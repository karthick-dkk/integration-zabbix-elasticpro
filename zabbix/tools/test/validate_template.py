#!/usr/bin/env python3
"""
validate_template.py - static checks for Zabbix template exports (YAML or JSON).

Catches the mistakes that make a template import cleanly but fail at runtime:
  * broken references (dependent master_item, calculated formulas, trigger
    expressions, graphs, dashboard widgets, trigger dependencies, value maps)
  * duplicate / malformed UUIDs
  * plaintext credentials, hard-coded IPs, secret-looking macros not SECRET_TEXT
  * LLD macros used outside discovery prototypes
  * user macros used but not defined (warning: may be global/host-level)
  * HTTP agent URLs with the query string in front of the path

Usage:
  validate_template.py TEMPLATE.yaml [--json] [--strict]
  validate_template.py --new-uuid [N]          # print N fresh template UUIDs

Exit code: 0 = no errors, 1 = errors found (or warnings with --strict), 2 = bad input.
Read-only: never modifies the template, safe to re-run.
"""
import argparse
import json
import re
import sys
import uuid

try:
    import yaml
except ImportError:  # pragma: no cover
    yaml = None

KNOWN_VERSIONS = {"6.0", "6.2", "6.4", "7.0", "7.2", "7.4", "8.0"}
UUID_RE = re.compile(r"^[0-9a-f]{32}$")
USER_MACRO_RE = re.compile(r"\{\$([A-Z0-9_.]+)(?::[^}]*)?\}")
LLD_MACRO_RE = re.compile(r"\{#[A-Z0-9_.]+\}")
IPV4_RE = re.compile(r"(?<![\d.])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?![\d.])")
IP_ALLOW = {"127.0.0.1", "0.0.0.0", "255.255.255.255"}
SECRET_NAME_RE = re.compile(r"PASS|PASSWORD|PASSWD|SECRET|TOKEN|APIKEY|API_KEY|PRIVATE", re.I)

CRED_PATTERNS = [
    ("user:password@ in URL", re.compile(r"[A-Za-z0-9_.\-]+:[^\s@{}/'\"]{2,}@[A-Za-z0-9\[]")),
    ("curl -u/--user with literal password", re.compile(r"(?:-u|--user)\s+['\"]?[^\s'\"{}]+:[^\s'\"{}$]+")),
    ("literal password= / password:", re.compile(r"(?i)\b(?:password|passwd|pwd)\s*[=:]\s*['\"]?(?!\{\$)[^\s'\"{},]{3,}")),
    ("Authorization: Basic with literal base64", re.compile(r"(?i)authorization:\s*basic\s+[A-Za-z0-9+/]{8,}={0,2}")),
    ("Bearer token literal", re.compile(r"(?i)\bbearer\s+(?!\{\$)[A-Za-z0-9._\-]{20,}")),
]


class Report:
    def __init__(self):
        self.errors, self.warnings, self.info = [], [], []

    def err(self, where, msg):
        self.errors.append({"where": where, "msg": msg})

    def warn(self, where, msg):
        self.warnings.append({"where": where, "msg": msg})

    def note(self, where, msg):
        self.info.append({"where": where, "msg": msg})


# ---------------------------------------------------------------- expression parsing
def extract_item_refs(expr):
    """Return [(host, key)] for every function reference func(/host/key ...) in expr.

    host may be '' (calculated item, same host) or '*' (foreach).  Item keys may
    contain brackets with quoted, comma-separated params, e.g. key["a,b",c].
    Filters like /*/key?[group="x"] are stripped.
    """
    refs = []
    if not isinstance(expr, str):
        return refs
    i, n = 0, len(expr)
    while i < n:
        m = re.compile(r"\(\s*/").search(expr, i)
        if not m:
            break
        j = m.end()  # just after first '/'
        slash = expr.find("/", j)
        if slash == -1:
            break
        host = expr[j:slash]
        if "(" in host or ")" in host or "\n" in host:
            i = j
            continue
        k = slash + 1
        start = k
        while k < n and (expr[k].isalnum() or expr[k] in "_.-{}#$:"):
            # stop at user-macro boundaries only inside brackets; keys rarely hold macros outside
            k += 1
        if k < n and expr[k] == "[":
            depth, inq = 0, False
            while k < n:
                c = expr[k]
                if inq:
                    if c == "\\" and k + 1 < n:
                        k += 2
                        continue
                    if c == '"':
                        inq = False
                elif c == '"':
                    inq = True
                elif c == "[":
                    depth += 1
                elif c == "]":
                    depth -= 1
                    if depth == 0:
                        k += 1
                        break
                k += 1
        key = expr[start:k]
        if key:
            refs.append((host.strip(), key))
        i = k
    return refs


# ---------------------------------------------------------------- traversal helpers
def walk_strings(node, path="$"):
    if isinstance(node, dict):
        for k, v in node.items():
            yield from walk_strings(v, f"{path}.{k}")
    elif isinstance(node, list):
        for idx, v in enumerate(node):
            yield from walk_strings(v, f"{path}[{idx}]")
    elif isinstance(node, str):
        yield path, node


def collect_uuids(node, path="$", out=None):
    if out is None:
        out = []
    if isinstance(node, dict):
        if "uuid" in node:
            out.append((path, node["uuid"]))
        for k, v in node.items():
            collect_uuids(v, f"{path}.{k}", out)
    elif isinstance(node, list):
        for idx, v in enumerate(node):
            collect_uuids(v, f"{path}[{idx}]", out)
    return out


def label(obj, fallback="?"):
    if isinstance(obj, dict):
        return obj.get("name") or obj.get("key") or obj.get("template") or fallback
    return fallback


# ---------------------------------------------------------------- main checks
def check_template(tpl, all_template_names, rep, tidx):
    tname = tpl.get("template", f"templates[{tidx}]")
    where_t = f"template '{tname}'"

    items = tpl.get("items") or []
    rules = tpl.get("discovery_rules") or []
    item_keys = {it.get("key") for it in items if isinstance(it, dict)}
    proto_keys = set()
    for r in rules:
        for ip in r.get("item_prototypes") or []:
            proto_keys.add(ip.get("key"))
    valuemaps = {vm.get("name") for vm in tpl.get("valuemaps") or []}
    defined_macros = {}
    for m in tpl.get("macros") or []:
        mname = m.get("macro", "")
        mm = USER_MACRO_RE.fullmatch(mname)
        if not mm:
            rep.err(where_t, f"malformed macro name {mname!r}")
            continue
        defined_macros[mm.group(1)] = m
        if SECRET_NAME_RE.search(mm.group(1)) and m.get("type") not in ("SECRET_TEXT", "VAULT"):
            rep.warn(where_t, f"macro {mname} looks secret but type is {m.get('type','TEXT')} (use SECRET_TEXT or VAULT)")
        if m.get("type") == "SECRET_TEXT" and m.get("value"):
            rep.warn(where_t, f"SECRET_TEXT macro {mname} carries a value in the export; ship it empty and set it on the host")

    # duplicate keys
    seen = set()
    for it in items:
        k = it.get("key")
        if k in seen:
            rep.err(where_t, f"duplicate item key {k!r}")
        seen.add(k)
    seen = set()
    for r in rules:
        k = r.get("key")
        if k in seen or k in item_keys:
            rep.err(where_t, f"discovery rule key {k!r} duplicates another item/rule key")
        seen.add(k)

    def check_ref(where, host, key, allow_proto=False):
        if host == "*" or "?[" in key:
            return  # foreach/wildcard - cannot resolve statically
        if host and host != tname:
            if host in all_template_names:
                rep.warn(where, f"references another template in this file: /{host}/{key}")
            else:
                rep.warn(where, f"references external host/template /{host}/{key} (cannot verify)")
            return
        pool = item_keys | (proto_keys if allow_proto else set())
        if key not in pool:
            hint = ""
            if allow_proto is False and key in proto_keys:
                hint = " (it is an item PROTOTYPE key; only usable from prototypes)"
            rep.err(where, f"reference to missing item key {key!r}{hint}")

    def check_item(it, where, is_proto):
        t = it.get("type", "ZABBIX_PASSIVE")
        key = it.get("key", "")
        if t == "DEPENDENT":
            mk = (it.get("master_item") or {}).get("key")
            if not mk:
                rep.err(where, "DEPENDENT item has no master_item.key")
            else:
                check_ref(where, "", mk, allow_proto=is_proto)
                if mk == key:
                    rep.err(where, "item is its own master")
        if t == "CALCULATED":
            refs = extract_item_refs(it.get("params", ""))
            if not refs:
                rep.warn(where, "CALCULATED item formula has no item references")
            for h, k in refs:
                check_ref(where, h, k, allow_proto=is_proto)
        if t == "HTTP_AGENT":
            url = it.get("url", "")
            m = re.match(r"^[a-z]+://[^/?#]+(\?[^/]*)/", url)
            if m:
                rep.err(where, f"HTTP agent URL has query string before path: {url!r}")
        if t in ("SSH", "TELNET", "HTTP_AGENT", "DB_MONITOR", "ODBC", "JMX"):
            pw = it.get("password")
            if pw and not USER_MACRO_RE.search(str(pw)):
                rep.err(where, "literal password field (use a SECRET_TEXT macro)")
        if "system.run[" in key:
            rep.note(where, "uses system.run: agent needs AllowKey=system.run[*] (or a narrower AllowKey/AllowKeyRegexp on 8.0)")
        vm = (it.get("valuemap") or {}).get("name")
        if vm and vm not in valuemaps:
            rep.err(where, f"value map {vm!r} not defined in template")
        if not is_proto and LLD_MACRO_RE.search(json.dumps(it)):
            rep.err(where, "LLD macro {#...} used in a non-prototype item")
        for trg in it.get("triggers" if not is_proto else "trigger_prototypes") or []:
            check_trigger(trg, f"{where} > trigger '{label(trg)}'", is_proto)

    trigger_index = []  # (name, expression)

    def check_trigger(trg, where, is_proto):
        for fld in ("expression", "recovery_expression"):
            for h, k in extract_item_refs(trg.get(fld, "")):
                check_ref(where, h, k, allow_proto=is_proto)
        if not is_proto:
            trigger_index.append((trg.get("name"), trg.get("expression")))
            if LLD_MACRO_RE.search(trg.get("expression", "")):
                rep.err(where, "LLD macro in non-prototype trigger expression")
        deps.append((where, trg.get("dependencies") or [], is_proto))

    deps = []

    for it in items:
        check_item(it, f"{where_t} > item '{label(it)}'", False)
    for r in rules:
        rwhere = f"{where_t} > LLD '{label(r)}'"
        if r.get("type") == "DEPENDENT":
            mk = (r.get("master_item") or {}).get("key")
            if not mk or mk not in item_keys:
                rep.err(rwhere, f"dependent LLD master_item {mk!r} not found among items")
        for ip in r.get("item_prototypes") or []:
            check_item(ip, f"{rwhere} > prototype '{label(ip)}'", True)
            if not LLD_MACRO_RE.search(ip.get("key", "")):
                rep.err(f"{rwhere} > prototype '{label(ip)}'", "item prototype key has no {#LLD} macro -> duplicates on 2nd discovered entity")
        for tp in r.get("trigger_prototypes") or []:
            check_trigger(tp, f"{rwhere} > trigger prototype '{label(tp)}'", True)
        for gp in r.get("graph_prototypes") or []:
            for gi in gp.get("graph_items") or []:
                itm = gi.get("item") or {}
                check_ref(f"{rwhere} > graph prototype '{label(gp)}'", "" if itm.get("host") == tname else itm.get("host", ""), itm.get("key", ""), True)

    # top-level graphs live outside the template block in exports; handled by caller
    return item_keys, proto_keys, trigger_index, deps, defined_macros


def validate(doc):
    rep = Report()
    if not isinstance(doc, dict) or "zabbix_export" not in doc:
        rep.err("$", "not a Zabbix export (missing zabbix_export root)")
        return rep
    zx = doc["zabbix_export"]
    ver = str(zx.get("version", ""))
    if not ver:
        rep.err("$", "zabbix_export.version missing")
    elif ver not in KNOWN_VERSIONS:
        rep.warn("$", f"unrecognised export version {ver!r}")
    if ver.startswith("8"):
        rep.note("$", "8.0 export: an 8.0 file will not import into 7.0; keep a 7.0 build if both are targeted")

    # UUIDs
    seen = {}
    for p, u in collect_uuids(zx):
        if isinstance(u, int):
            rep.err(p, f"uuid {u} was parsed as a number - quote it, or generate a real one with --new-uuid")
        elif not isinstance(u, str) or not UUID_RE.match(u):
            rep.err(p, f"malformed uuid {u!r} (need 32 lowercase hex chars, no dashes)")
        elif u in seen:
            rep.err(p, f"duplicate uuid {u} (also at {seen[u]})")
        else:
            seen[u] = p

    templates = zx.get("templates") or []
    names = {t.get("template") for t in templates}
    all_items, all_protos, all_triggers, all_deps, macros_defined = {}, {}, [], [], {}
    for idx, t in enumerate(templates):
        ik, pk, ti, dp, dm = check_template(t, names, rep, idx)
        all_items[t.get("template")] = ik
        all_protos[t.get("template")] = pk
        all_triggers += ti
        all_deps += dp
        macros_defined.update(dm)
        # dashboards
        for d in t.get("dashboards") or []:
            for pg in d.get("pages") or []:
                for w in pg.get("widgets") or []:
                    for f in w.get("fields") or []:
                        if f.get("type") == "ITEM" and isinstance(f.get("value"), dict):
                            v = f["value"]
                            if v.get("host") == t.get("template") and v.get("key") not in ik:
                                rep.err(f"template '{t.get('template')}' > dashboard '{d.get('name')}'",
                                        f"widget references missing item {v.get('key')!r}")
                        if f.get("type") == "ITEM_PROTOTYPE" and isinstance(f.get("value"), dict):
                            v = f["value"]
                            if v.get("host") == t.get("template") and v.get("key") not in pk:
                                rep.err(f"template '{t.get('template')}' > dashboard '{d.get('name')}'",
                                        f"widget references missing item prototype {v.get('key')!r}")

    # top-level triggers (multi-item)
    for trg in zx.get("triggers") or []:
        where = f"trigger '{label(trg)}'"
        for fld in ("expression", "recovery_expression"):
            for h, k in extract_item_refs(trg.get(fld, "")):
                if h in all_items:
                    if k not in all_items[h]:
                        rep.err(where, f"reference to missing item /{h}/{k}")
                elif h not in ("", "*"):
                    rep.warn(where, f"references external host/template /{h}/{k}")
        all_triggers.append((trg.get("name"), trg.get("expression")))
        all_deps.append((where, trg.get("dependencies") or [], False))

    # top-level graphs
    for g in zx.get("graphs") or []:
        for gi in g.get("graph_items") or []:
            itm = gi.get("item") or {}
            h, k = itm.get("host"), itm.get("key")
            if h in all_items and k not in all_items[h]:
                rep.err(f"graph '{label(g)}'", f"graph item /{h}/{k} not found")

    # trigger dependencies
    trig_set = {(n, e) for n, e in all_triggers}
    for where, dl, is_proto in all_deps:
        for d in dl:
            if is_proto:
                continue  # may depend on prototypes or regular triggers; skip
            if (d.get("name"), d.get("expression")) not in trig_set:
                rep.err(where, f"dependency on unknown trigger {d.get('name')!r} (name+expression must match exactly)")

    # string scans
    used_macros = set()
    for p, s in walk_strings(zx):
        for m in USER_MACRO_RE.finditer(s):
            used_macros.add(m.group(1))
        if p.endswith(".uuid"):
            continue
        for lbl, rx in CRED_PATTERNS:
            mt = rx.search(s)
            if mt:
                rep.err(p, f"possible plaintext credential ({lbl}): ...{mt.group(0)[:40]}...")
        for ip in IPV4_RE.findall(s):
            if ip not in IP_ALLOW:
                rep.warn(p, f"hard-coded IP {ip} (prefer a {{$...HOST}} macro or {{HOST.CONN}})")
                break
    for m in sorted(used_macros - set(macros_defined)):
        rep.warn("macros", f"{{${m}}} used but not defined on any template in this file (ok if global/host-level)")
    for m in sorted(set(macros_defined) - used_macros):
        # defined macros always appear in walk_strings via 'macro' field; check usage elsewhere
        pass
    usage_count = {}
    for p, s in walk_strings(zx):
        if p.endswith(".macro"):
            continue
        for m in USER_MACRO_RE.finditer(s):
            usage_count[m.group(1)] = usage_count.get(m.group(1), 0) + 1
    for m in sorted(set(macros_defined) - set(usage_count)):
        rep.note("macros", f"{{${m}}} defined but never used")

    # summary info
    n_items = sum(len(v) for v in all_items.values())
    n_protos = sum(len(v) for v in all_protos.values())
    rep.note("$", f"version {ver}; templates {len(templates)}; items {n_items}; item prototypes {n_protos}; "
                  f"triggers {len(all_triggers)}; uuids {len(seen)}")
    return rep


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("file", nargs="?")
    ap.add_argument("--json", action="store_true", help="machine-readable output")
    ap.add_argument("--strict", action="store_true", help="treat warnings as failures")
    ap.add_argument("--new-uuid", nargs="?", const=1, type=int, metavar="N", help="print N new UUIDs and exit")
    a = ap.parse_args()

    if a.new_uuid:
        for _ in range(a.new_uuid):
            print(uuid.uuid4().hex)
        return 0
    if not a.file:
        ap.error("template file required")
    try:
        with open(a.file, encoding="utf-8") as fh:
            text = fh.read()
        if a.file.endswith(".json"):
            doc = json.loads(text)
        else:
            if yaml is None:
                print("PyYAML missing: pip install pyyaml", file=sys.stderr)
                return 2
            doc = yaml.safe_load(text)
    except Exception as e:  # noqa
        print(f"cannot parse {a.file}: {e}", file=sys.stderr)
        return 2

    rep = validate(doc)
    if a.json:
        print(json.dumps({"errors": rep.errors, "warnings": rep.warnings, "info": rep.info}, indent=2))
    else:
        for title, lst in (("ERROR", rep.errors), ("WARN", rep.warnings), ("INFO", rep.info)):
            for e in lst:
                print(f"[{title}] {e['where']}: {e['msg']}")
        print(f"\n{len(rep.errors)} error(s), {len(rep.warnings)} warning(s)")
    fail = bool(rep.errors) or (a.strict and bool(rep.warnings))
    return 1 if fail else 0


if __name__ == "__main__":
    sys.exit(main())
