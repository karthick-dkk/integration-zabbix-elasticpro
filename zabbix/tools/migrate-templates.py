#!/usr/bin/env python3
"""Move hosts from older templates to the ElasticPro templates without losing a stored value.

    ZABBIX_URL=https://zabbix.example ZABBIX_TOKEN=... python3 migrate-templates.py --map my-map.json
    ... --map my-map.json --commit --journal migrate-journal.json
    ... --rollback migrate-journal.json [--commit]

DRY RUN UNLESS --commit. On its own it reads, plans and prints what it would do, and calls no
method that writes. The map file says which old templates become which ElasticPro templates and
how old item keys and macro names map to new ones: copy migrate-map.example.json and fill it in
(docs/MIGRATION.md walks through it).

WHY THIS ORDER. A value in Zabbix belongs to an item id, not to a key or a template. Three
behaviours of Zabbix 7.0 make a lossless move possible, and each was checked on 7.0.31:

  * "Unlink" (host.massremove with templateids) keeps the template's items on the host as
    plain host items, with their ids and history. "Unlink and clear" deletes them. This
    script can only do the first: templateids_clear / templates_clear are refused before any
    request is built, and no delete method is reachable at all.
  * A host item's key can be changed (item.update key_) once it is no longer inherited; the
    id, and so the history and trends, stay.
  * Linking a template to a host that already has an item with the same key ADOPTS that item:
    same id, now inherited from the template, history kept. A discovery rule with the same key
    is adopted the same way: same rule id, the rule and its prototypes templated again, the
    items it discovered untouched.

So, per host (mode "replace"): copy the macros the kept items need to host level, unlink the
old template WITHOUT clear, rename each host item whose old key maps to a key of the new
template, link the new templates (they adopt the renamed items), then verify that every item
that existed before still exists and has at least as many stored values as before.

A template that keeps its NAME but whose items carry an older key prefix (mode "in-place") is
upgraded on the template itself: item.update key_ on a template item renames it on every host
at once, same ids; a following import of the current export (--import) then matches the items
by key and adopts the new uuids instead of creating second, empty items beside them.

WHAT IS REFUSED. A rename onto a key whose value type differs: Zabbix adopts it anyway and
converts the item, and the values stored under the old type stop being visible (checked: 1
value before, 0 after). A host on which linking would fail (the key already comes from a third
template) or would silently convert a host item. A discovery rule in both templates whose
prototype has another value type under the same key: the link would adopt the rule and convert
the discovered items. Each is reported with its reason and the host is left exactly as it was.

WHAT IS NEVER DONE. Nothing is deleted: no item, trigger, host, group, macro or value. Old
triggers stay as host triggers; --retire-old-triggers DISABLES (never deletes) the ones that
duplicate a new template trigger on the same items. Secret macro values cannot be read through
the API by anyone, so a secret the new setup needs under another name or at host level is
listed for a person to set; it is never guessed or blanked.

ROLLBACK. --commit writes a journal (--journal, default migrate-journal-<time>.json). --rollback
JOURNAL re-enables what was disabled, unlinks the new templates (without clear), renames the
keys back and re-links the old templates, which adopt the items again. Items a new template
created that did not exist before stay behind as empty host items, listed by name: nothing is
deleted, here either.

Needs: an API token (or user) allowed to read and update the hosts and templates involved —
see docs/MIGRATION.md. Python 3.8+, standard library only.
"""
import argparse, json, os, re, ssl, sys, time, urllib.error, urllib.request

VT_NAMES = {"FLOAT": "0", "CHAR": "1", "LOG": "2", "UNSIGNED": "3", "TEXT": "4", "BINARY": "5"}
VT_LABEL = {v: k for k, v in VT_NAMES.items()}
MACRO_TYPE = {"0": "text", "1": "secret", "2": "vault"}

READ = {"apiinfo.version", "user.login", "host.get", "template.get", "item.get", "itemprototype.get",
        "discoveryrule.get", "trigger.get", "triggerprototype.get", "graph.get", "graphprototype.get", "valuemap.get",
        "templatedashboard.get", "usermacro.get", "history.get", "hostgroup.get"}
# Every write this script can make, with the only parameter names it may carry. Anything else —
# a delete, a clear, a host.update that REPLACES a list — cannot be expressed.
WRITE_SHAPES = {
    "usermacro.create": {"hostid", "macro", "value", "type", "description"},
    "host.massremove": {"hostids", "templateids"},
    "host.massadd": {"hosts", "templates"},
    "item.update": {"itemid", "key_", "uuid"},
    "itemprototype.update": {"itemid", "key_", "uuid"},
    "discoveryrule.update": {"itemid", "key_", "uuid"},
    "trigger.update": {"triggerid", "status", "uuid"},
    # uuid only: a template.update carrying "templates", "groups" or "macros" REPLACES that list.
    "template.update": {"templateid", "uuid"},
    "triggerprototype.update": {"triggerid", "uuid"},
    "graph.update": {"graphid", "uuid"},
    "graphprototype.update": {"graphid", "uuid"},
    "valuemap.update": {"valuemapid", "uuid"},
    "templatedashboard.update": {"dashboardid", "uuid"},
    "configuration.import": {"format", "source", "rules"},
}
FORBIDDEN_KEYS = {"templateids_clear", "templates_clear", "deleteMissing"}
MACRO_FIELDS = ["key_", "url", "username", "password", "params", "delay", "timeout", "posts", "headers",
                "query_fields", "ssl_cert_file", "ssl_key_file", "ssl_key_password", "http_proxy",
                "status_codes", "jmx_endpoint", "publickey", "privatekey", "lifetime"]
IMPORT_RULES = {k: {"createMissing": True, "updateExisting": True} for k in
                ("template_groups", "templates", "items", "discoveryRules", "triggers", "graphs",
                 "httptests", "templateDashboards", "valueMaps")}


class Refused(Exception):
    pass


# ------------------------------------------------------------------------------------- api

class Api:
    def __init__(self, url, token=None, user=None, password=None, insecure=False, commit=False):
        self.url = url.rstrip("/")
        if self.url.endswith("/api_jsonrpc.php"):
            self.url = self.url[: -len("/api_jsonrpc.php")]
        self.ctx = ssl._create_unverified_context() if insecure else None
        self.commit = commit
        self.auth = None
        self.writes = []
        self.version = self.call("apiinfo.version", {})
        self.auth = token or self.call("user.login", {"username": user, "password": password})

    def call(self, method, params):
        if method in WRITE_SHAPES:
            self._check_shape(method, params)
            if not self.commit:
                raise Refused(f"{method} in a dry run (this is a bug: the plan must not write)")
            self.writes.append(method)
        elif method not in READ:
            raise Refused(f"{method} is not a method this script may call")
        h = {"Content-Type": "application/json-rpc"}
        if self.auth and method not in ("apiinfo.version", "user.login"):
            h["Authorization"] = "Bearer " + self.auth
        body = json.dumps({"jsonrpc": "2.0", "method": method, "params": params, "id": 1}).encode()
        try:
            r = json.load(urllib.request.urlopen(urllib.request.Request(self.url + "/api_jsonrpc.php", body, h),
                                                 context=self.ctx, timeout=120))
        except (urllib.error.URLError, OSError, ValueError) as e:
            raise SystemExit(f"{method}: cannot talk to {self.url}: {e}")
        if "error" in r:
            raise RuntimeError(f"{method}: {r['error'].get('data') or r['error'].get('message')}")
        return r["result"]

    @staticmethod
    def _check_shape(method, params):
        def walk(x):
            if isinstance(x, dict):
                for k, v in x.items():
                    if k in FORBIDDEN_KEYS:
                        raise Refused(f"{method} carrying {k}: refused, it deletes items or their history")
                    walk(v)
            elif isinstance(x, list):
                for v in x:
                    walk(v)
        walk(params)
        extra = set(params) - WRITE_SHAPES[method]
        if extra:
            raise Refused(f"{method} with {sorted(extra)}: refused, not a shape this script writes")


# ------------------------------------------------------------------------------------- map

def load_map(path):
    with open(path, encoding="utf-8") as fh:
        m = json.load(fh)
    gens = m.get("generations") or []
    if not gens:
        raise SystemExit(f"{path}: no generations")
    for g in gens:
        g.setdefault("mode", "replace")
        if g["mode"] not in ("replace", "in-place"):
            raise SystemExit(f"{path}: generation {g.get('name')!r}: mode must be replace or in-place")
        for k in ("key_prefixes", "macro_prefixes"):
            g[k] = [tuple(x) for x in g.get(k, [])]
        g.setdefault("keys", {})
        g.setdefault("macros", {})
        placeholders = re.findall(r"<[A-Z][A-Za-z0-9 _-]*>", json.dumps(g))
        if placeholders:
            raise SystemExit(f"{path}: generation {g.get('name')!r} still has placeholders {placeholders[:3]}: "
                             "fill them in, or delete the generation")
        g["_dir"] = os.path.dirname(os.path.abspath(path))
    return gens


def map_key(key, gen):
    if key in gen["keys"]:
        return gen["keys"][key]
    for old, new in gen["key_prefixes"]:
        if key.startswith(old):
            return new + key[len(old):]
    return key


def map_macro(macro, gen):
    if macro in gen["macros"]:
        return gen["macros"][macro]
    for old, new in gen["macro_prefixes"]:
        if macro.startswith(old):
            return new + macro[len(old):]
    return macro


def raw_expression(expression, functions, key_of, host):
    """A trigger expression with its macros as written.

    trigger.get's expandExpression resolves user macros to their VALUES ("{$X.STALE}" comes
    back as "30m"), so it can neither show which macros a trigger uses nor be compared with an
    export. The stored form is "{functionid}=1" with each function's parameters apart
    ("$,{$X.STALE}", "$" standing for the item); this puts them back together:
    nodata(/host/key,{$X.STALE})=1."""
    out = expression or ""
    for f in functions or []:
        params = f.get("parameter", "")
        rest = params[1:] if params.startswith("$") else ("," + params if params else "")
        call = f"{f['function']}(/{host}/{key_of.get(f['itemid'], '?')}{rest})"
        out = out.replace("{" + f["functionid"] + "}", call)
    return out


def macro_refs(text):
    return {re.sub(r":.*\}$", "}", m) for m in re.findall(r"\{\$[A-Z0-9_.]+(?::[^}]*)?\}", text or "")}


# ------------------------------------------------------------------------- export reading

def export_objects(path):
    """Every uuid-carrying object of a Zabbix 7.0 export (YAML or JSON) as
    {section, uuid, key, value_type, name, expression, template}. YAML is read with PyYAML when
    it is installed, otherwise by a scanner for the regular shape Zabbix writes (every object
    starts with '- uuid:')."""
    with open(path, encoding="utf-8") as fh:
        text = fh.read()
    if path.endswith(".json"):
        return _objects_from_doc(json.loads(text))
    try:
        import yaml  # optional
        return _objects_from_doc(yaml.safe_load(text))
    except ImportError:
        return _objects_from_yaml_scan(text)


def export_keys(path):
    """{'items': {key: vt}, 'prototypes': {key: vt}, 'lld': {key}} of an export."""
    return _keys_of(export_objects(path))


def _keys_of(objs):
    out = {"items": {}, "prototypes": {}, "lld": set()}
    for o in objs:
        if o["section"] == "items":
            out["items"][o["key"]] = VT_NAMES.get(o.get("value_type") or "UNSIGNED", "3")
        elif o["section"] == "item_prototypes":
            out["prototypes"][o["key"]] = VT_NAMES.get(o.get("value_type") or "UNSIGNED", "3")
        elif o["section"] == "discovery_rules":
            out["lld"].add(o["key"])
    return out


def _keys_from_doc(doc):
    return _keys_of(_objects_from_doc(doc))


def _keys_from_yaml_scan(text):
    return _keys_of(_objects_from_yaml_scan(text))


def _objects_from_doc(doc):
    out = []

    def add(section, o):
        out.append({"section": section, "uuid": o.get("uuid"), "key": o.get("key"), "value_type": o.get("value_type"),
                    "name": o.get("name"), "expression": o.get("expression"), "template": o.get("template")})

    for t in doc["zabbix_export"].get("templates", []):
        add("templates", t)
        for i in t.get("items", []):
            add("items", i)
            for tr in i.get("triggers", []):
                add("triggers", tr)
        for d in t.get("discovery_rules", []):
            add("discovery_rules", d)
            for p in d.get("item_prototypes", []):
                add("item_prototypes", p)
                for tr in p.get("trigger_prototypes", []):
                    add("trigger_prototypes", tr)
            for tr in d.get("trigger_prototypes", []):
                add("trigger_prototypes", tr)
            for g in d.get("graph_prototypes", []):
                add("graph_prototypes", g)
        for v in t.get("valuemaps", []):
            add("valuemaps", v)
        for b in t.get("dashboards", []):
            add("dashboards", b)
    for tr in doc["zabbix_export"].get("triggers", []):
        add("triggers", tr)
    for g in doc["zabbix_export"].get("graphs", []):
        add("graphs", g)
    return out


def _unquote(v):
    v = v.strip()
    if len(v) >= 2 and v[0] == v[-1] and v[0] in "'\"":
        q, v = v[0], v[1:-1]
        if q == "'":
            v = v.replace("''", "'")  # YAML's escape for a quote inside single quotes
    return v


def _objects_from_yaml_scan(text):
    out, headers, obj = [], {}, None
    fields = ("key", "value_type", "name", "expression", "template")

    def close():
        if obj:
            out.append({"section": obj["section"], **{f: obj.get(f) for f in ("uuid",) + fields}})

    for line in text.splitlines():
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        ind = len(line) - len(line.lstrip())
        m = re.match(r"^(\s*)- uuid: (\S+)", line)
        if m:
            close()
            d = len(m.group(1))
            obj = {"indent": d, "section": headers.get(d - 2), "uuid": _unquote(m.group(2))}
            continue
        m = re.match(r"^(\s*)([a-z_]+):\s*$", line)
        if m:
            headers = {k: v for k, v in headers.items() if k < ind}
            headers[ind] = m.group(2)
        if obj is not None:
            if ind <= obj["indent"]:
                close()
                obj = None
            elif ind == obj["indent"] + 2:
                m = re.match(r"^\s*(" + "|".join(fields) + r"):\s*(.+)$", line)
                if m:
                    obj[m.group(1)] = _unquote(m.group(2))
    close()
    return out


# ------------------------------------------------------------------------------- snapshot

def history_count(api, item, since=None):
    if item["value_type"] not in ("0", "1", "2", "3", "4"):
        return None
    p = {"history": int(item["value_type"]), "itemids": [item["itemid"]], "countOutput": True}
    if since:
        p["time_from"] = since
    return int(api.call("history.get", p))


def snapshot_host(api, hostid, since=None, count=True):
    h = api.call("host.get", {"hostids": [hostid], "output": ["hostid", "host", "name"],
                              "selectParentTemplates": ["templateid", "host"],
                              "selectMacros": ["hostmacroid", "macro", "value", "type"],
                              "selectHostGroups": ["name"]})[0]
    items = api.call("item.get", {"hostids": [hostid], "output": ["itemid", "key_", "name", "templateid", "value_type",
                                                                    "flags", "type", "status"] + MACRO_FIELDS})
    rules = api.call("discoveryrule.get", {"hostids": [hostid], "output": ["itemid", "key_", "templateid"] + MACRO_FIELDS})
    protos = api.call("itemprototype.get", {"hostids": [hostid], "output": ["itemid", "key_", "templateid", "value_type"] + MACRO_FIELDS,
                                            "selectDiscoveryRule": ["itemid"]}) if rules else []
    triggers = api.call("trigger.get", {"hostids": [hostid], "output": ["triggerid", "description", "templateid", "status",
                                                                         "expression", "recovery_expression"],
                                         "selectItems": ["itemid"], "selectFunctions": "extend"})
    # Where each inherited object comes from: its templateid is the parent object on the
    # directly linked template, whose hostid is that template.
    origin = {}
    parent_items = [i["templateid"] for i in items + rules + protos if i["templateid"] != "0"]
    if parent_items:
        for meth in ("item.get", "discoveryrule.get", "itemprototype.get"):
            for p in api.call(meth, {"itemids": parent_items, "output": ["itemid", "hostid"], "templated": True}):
                origin[p["itemid"]] = p["hostid"]
    parent_trigs = [t["templateid"] for t in triggers if t["templateid"] != "0"]
    torigin = {}
    if parent_trigs:
        for p in api.call("trigger.get", {"triggerids": parent_trigs, "output": ["triggerid"], "selectHosts": ["hostid"],
                                          "templated": True}):
            torigin[p["triggerid"]] = p["hosts"][0]["hostid"] if p["hosts"] else None
    for o in items + rules + protos:
        o["origin"] = origin.get(o["templateid"]) if o["templateid"] != "0" else None
    key_of = {i["itemid"]: i["key_"] for i in items}
    for t in triggers:
        t["origin"] = torigin.get(t["templateid"]) if t["templateid"] != "0" else None
        t["itemids"] = sorted(i["itemid"] for i in t.get("items", []))
        t["expression"] = raw_expression(t["expression"], t.get("functions"), key_of, h["host"])
        t["recovery_expression"] = raw_expression(t["recovery_expression"], t.get("functions"), key_of, h["host"])
    counts = {}
    if count:
        for i in items:
            counts[i["itemid"]] = history_count(api, i, since)
    return {"host": h, "items": items, "rules": rules, "prototypes": protos, "triggers": triggers, "counts": counts}


def targets(api, names):
    """The new templates, by technical name: ids, item keys with value types, LLD keys, macros."""
    found = api.call("template.get", {"filter": {"host": names}, "output": ["templateid", "host"],
                                      "selectMacros": ["macro", "value", "type"]})
    missing = sorted(set(names) - {t["host"] for t in found})
    ids = [t["templateid"] for t in found]
    keys, lld, protos, macros = {}, set(), {}, {}
    if ids:
        for i in api.call("item.get", {"templateids": ids, "output": ["key_", "value_type"]}):
            keys[i["key_"]] = i["value_type"]
        for r in api.call("discoveryrule.get", {"templateids": ids, "output": ["key_"]}):
            lld.add(r["key_"])
        for p in api.call("itemprototype.get", {"templateids": ids, "output": ["key_", "value_type"]}):
            protos[p["key_"]] = p["value_type"]
        for t in found:
            for m in t["macros"]:
                macros[m["macro"]] = (m.get("value"), m["type"])
    return {"templates": found, "missing": missing, "keys": keys, "lld": lld, "protos": protos, "macros": macros}


# --------------------------------------------------------------------------- planning (pure)

def plan_replace(snap, gen, old_tpl, target, unlink=True):
    """What to do on one host. Pure: everything it needs is in its arguments.

    old_tpl: {templateid: {"host": name, "macros": {macro: (value|None, type)}}} for the old
             templates linked to this host.
    target:  targets() for the generation's new templates.
    """
    host = snap["host"]
    linked = {t["templateid"] for t in host["parentTemplates"]}
    target_ids = {t["templateid"] for t in target["templates"]}
    old_ids = set(old_tpl) & linked
    plan = {"host": host["host"], "hostid": host["hostid"], "refused": [], "items": [], "rules": [], "macros": [], "manual": [],
            "unlink": sorted(old_ids), "link": sorted(target_ids - linked), "triggers": [], "notes": []}
    if target["missing"]:
        plan["refused"].append("new template(s) not in Zabbix — import them first: " + ", ".join(target["missing"]))
    if not old_ids:
        plan["refused"].append("none of the old templates is linked directly to this host")
    host_keys = {i["key_"]: i for i in snap["items"]}
    taken = {}
    for it in snap["items"]:
        k, vt = it["key_"], it["value_type"]
        row = {"itemid": it["itemid"], "name": it.get("name", ""), "key": k, "new_key": k,
               "value_type": VT_LABEL.get(vt, vt), "count": snap["counts"].get(it["itemid"])}
        if it["flags"] == "4":
            row.update(action="KEEP", why="discovered: stays with its discovery rule")
        elif it["origin"] in old_ids:
            nk = map_key(k, gen)
            row["new_key"] = nk
            if nk not in target["keys"]:
                row.update(action="KEEP", why="no equivalent key in the new templates", new_key=k)
            elif target["keys"][nk] != vt and nk == k:
                # Not KEEP: kept under the same key, the unlinked item is a host item with the new
                # template's key, and the link adopts it and converts it anyway — the stored values
                # would stop being visible. Only a renamed key can be kept apart.
                plan["refused"].append(f"item {k} is {VT_LABEL.get(vt)} and the new template's is "
                                       f"{VT_LABEL.get(target['keys'][nk])} under the same key: the link would adopt "
                                       "and convert it, hiding its values")
                row.update(action="REFUSE", why="value type differs under the same key")
            elif target["keys"][nk] != vt:
                row.update(action="KEEP", new_key=k, why=f"new key {nk} is {VT_LABEL.get(target['keys'][nk])}, this is "
                           f"{VT_LABEL.get(vt)}: adopting it would hide the stored values")
            elif nk != k and nk in host_keys:
                row.update(action="KEEP", new_key=k, why=f"the host already has an item {nk}")
            elif nk in taken:
                row.update(action="KEEP", new_key=k, why=f"{taken[nk]} already maps to {nk}")
            else:
                taken[nk] = k
                row.update(action="ADOPT" if nk == k else "RENAME",
                           why="same key: the link adopts it" if nk == k else "renamed, then adopted by the link")
        elif it["origin"] in target_ids:
            row.update(action="CURRENT", why="already from the new template")
        elif it["templateid"] == "0":
            if k in target["keys"]:
                if target["keys"][k] != vt:
                    plan["refused"].append(f"host item {k} is {VT_LABEL.get(vt)} and the new template's is "
                                           f"{VT_LABEL.get(target['keys'][k])}: linking would hide its values")
                    row.update(action="REFUSE", why="value type differs")
                else:
                    row.update(action="ADOPT", why="host's own item with the new key: the link adopts it")
            else:
                row.update(action="UNTOUCHED", why="host's own item")
        else:
            if k in target["keys"]:
                plan["refused"].append(f"item {k} comes from another linked template: the link would fail")
                row.update(action="REFUSE", why="key comes from a third template")
            else:
                row.update(action="UNTOUCHED", why="from another template")
        plan["items"].append(row)
    # Discovery rules of the old template. Checked on 7.0.31: the link adopts a rule with the
    # same key as it adopts an item — same rule id, the rule and its prototypes templated again,
    # the discovered items (flags 4) left as they are — unless a prototype changes its value type
    # under the same key, which would convert what was discovered; that host is refused.
    protos = target.get("protos", {})
    for r in snap["rules"]:
        if r["origin"] not in old_ids:
            continue
        row = {"itemid": r["itemid"], "key": r["key_"]}
        if r["key_"] not in target["lld"]:
            row.update(action="KEEP", why="no equivalent rule in the new templates: stays a host rule with its items")
        else:
            bad = [p for p in snap["prototypes"] if (p.get("discoveryRule") or {}).get("itemid") == r["itemid"]
                   and p["key_"] in protos and protos[p["key_"]] != p.get("value_type")]
            if bad:
                plan["refused"].append(f"discovery rule {r['key_']} is in both templates and its prototype {bad[0]['key_']} is "
                                       f"{VT_LABEL.get(bad[0].get('value_type'))} here, {VT_LABEL.get(protos[bad[0]['key_']])} in the new "
                                       "template: the link would adopt the rule and convert the items it discovered")
                row.update(action="REFUSE", why="prototype value type differs under the same key")
            else:
                row.update(action="ADOPT", why="same key: the link adopts the rule, its prototypes and the items it discovered")
        plan["rules"].append(row)
    renames = [x for x in plan["items"] if x["action"] == "RENAME"]
    inherited_adopt = [x for x in plan["items"] if x["action"] == "ADOPT"
                       and next((i for i in snap["items"] if i["itemid"] == x["itemid"]), {}).get("origin") in old_ids]
    rule_adopt = [x for x in plan["rules"] if x["action"] == "ADOPT"]
    if not unlink:
        plan["unlink"] = []
        if renames or inherited_adopt or rule_adopt:
            plan["refused"].append("keys must move from the old template to the new one, which needs the old "
                                   "template unlinked (unlink_old is false for this generation)")

    # Macros. (a) Preserve what kept objects resolve today: a macro they use that only the old
    # template defines would vanish with the unlink. (b) Carry host-level settings to their new
    # names. Never a value made up, never a secret guessed.
    # Without an unlink the old template still answers for its macros — unless a new template
    # defines the same macro differently: two linked templates with one macro resolve by template
    # order, so the link could change what the kept items see. Those are pinned at host level too.
    if old_ids:
        old_macros = {}
        for t in old_tpl.values():
            for m, v in t["macros"].items():
                old_macros.setdefault(m, v)
        kept_ids = {x["itemid"] for x in plan["items"] if x["action"] == "KEEP"}
        texts = []
        for i in snap["items"]:
            if i["itemid"] in kept_ids:
                texts += [str(i.get(f) or "") for f in MACRO_FIELDS]
        for o in snap["rules"] + snap["prototypes"]:
            if o["origin"] in old_ids:
                texts += [str(o.get(f) or "") for f in MACRO_FIELDS]
        for t in snap["triggers"]:
            if t["origin"] in old_ids:
                texts += [t.get("expression", ""), t.get("recovery_expression", "")]
        used = set().union(*[macro_refs(x) for x in texts]) if texts else set()
        on_host = {m["macro"]: m for m in host["macros"]}
        for m in sorted(used):
            if m in on_host or m not in old_macros:
                continue
            val, typ = old_macros[m]
            if m in target["macros"] and target["macros"][m] == (val, typ) and typ != "1":
                continue
            if not plan["unlink"] and m not in target["macros"]:
                continue  # the old template stays linked and nothing new competes for the name
            if typ == "1":
                plan["manual"].append(f"{m}: secret on the old template, used by kept items — set it on the host "
                                      "by hand (secret values cannot be read through the API)")
            else:
                plan["macros"].append({"macro": m, "value": val, "type": typ, "why":
                                       "kept items use it; it came from the old template" if plan["unlink"] else
                                       "kept items use it and a new template defines it differently"})
    on_host = {m["macro"]: m for m in host["macros"]}
    planned = {m["macro"] for m in plan["macros"]}
    for m, hm in sorted(on_host.items()):
        nm = map_macro(m, gen)
        if nm == m or nm in on_host or nm in planned:
            continue
        if hm["type"] == "1":
            plan["manual"].append(f"{nm}: copy of the host's secret {m} — set it by hand")
        else:
            plan["macros"].append({"macro": nm, "value": hm.get("value", ""), "type": hm["type"], "why": f"renamed from {m}"})
    for m, (val, typ) in sorted(target["macros"].items()):
        if typ == "1" and m not in on_host and m not in planned:
            plan["manual"].append(f"{m}: secret the new template ships empty — set it on the host if this host needs it")

    # Triggers that will duplicate: an old trigger on items that all move to the new template.
    moving = {x["itemid"] for x in plan["items"] if x["action"] in ("RENAME", "ADOPT")}
    for t in snap["triggers"]:
        if t["origin"] in old_ids and t["itemids"] and set(t["itemids"]) <= moving:
            plan["triggers"].append({"triggerid": t["triggerid"], "description": t["description"],
                                     "itemids": t["itemids"], "status": t["status"]})
    return plan


def plan_in_place(tpl, titems, tprotos, trules, target, gen, host_items):
    """Key renames on one template that keeps its name. host_items: [(host, key)] on every host
    linked to it, for the collision check."""
    rows, refused = [], []
    host_keys = {}
    for h, k in host_items:
        host_keys.setdefault(k, set()).add(h)
    have = {i["key_"] for i in titems} | {p["key_"] for p in tprotos} | {r["key_"] for r in trules}
    for kind, objs, tkeys in (("item", titems, target["items"]), ("prototype", tprotos, target["prototypes"]),
                              ("lld", trules, {k: None for k in target["lld"]})):
        for o in objs:
            k = o["key_"]
            nk = map_key(k, gen)
            row = {"kind": kind, "itemid": o["itemid"], "key": k, "new_key": nk}
            if nk == k:
                row.update(action="CURRENT" if k in tkeys else "KEEP",
                           why="already the current key" if k in tkeys else "not in the current export: left as it is")
            elif nk not in tkeys:
                row.update(action="KEEP", new_key=k, why=f"{nk} is not in the current export")
            elif o.get("templateid", "0") != "0":
                row.update(action="KEEP", new_key=k, why="inherited from another template: rename it there")
            elif kind != "lld" and tkeys[nk] != o["value_type"]:
                row.update(action="KEEP", new_key=k, why=f"value type differs from the export's {VT_LABEL.get(tkeys[nk])}")
            elif nk in have:
                row.update(action="KEEP", new_key=k, why=f"the template already has {nk} (an earlier import made it): "
                           "the two series stay separate")
            elif kind == "item" and nk in host_keys:
                row.update(action="KEEP", new_key=k, why="hosts already have an item " + nk + ": "
                           + ", ".join(sorted(host_keys[nk])[:5]))
            else:
                row.update(action="RENAME", why="renamed on the template; every linked host follows, same item ids")
            rows.append(row)
    macros, manual = [], []
    tm = {m["macro"]: m for m in tpl["macros"]}
    for m, v in sorted(tm.items()):
        nm = map_macro(m, gen)
        if nm != m and nm not in tm:
            if v["type"] == "1":
                manual.append(f"{nm}: copy of the template's secret {m} — set it by hand")
            else:
                macros.append({"hostid": tpl["templateid"], "macro": nm, "value": v.get("value", ""), "type": v["type"],
                               "why": f"renamed from {m} on the template"})
    return {"template": tpl["host"], "templateid": tpl["templateid"], "rows": rows, "macros": macros,
            "manual": manual, "refused": refused}


UUID_METHOD = {"templates": ("template.update", "templateid"), "items": ("item.update", "itemid"),
               "discovery_rules": ("discoveryrule.update", "itemid"), "item_prototypes": ("itemprototype.update", "itemid"),
               "triggers": ("trigger.update", "triggerid"), "trigger_prototypes": ("triggerprototype.update", "triggerid"),
               "graphs": ("graph.update", "graphid"), "graph_prototypes": ("graphprototype.update", "graphid"),
               "valuemaps": ("valuemap.update", "valuemapid"), "dashboards": ("templatedashboard.update", "dashboardid")}


def zabbix_objects(api, tid):
    """A template's own objects with their uuids, in export_objects()' shape plus their id."""
    out = []
    t = api.call("template.get", {"templateids": [tid], "output": ["templateid", "host", "uuid"]})[0]
    out.append({"section": "templates", "id": t["templateid"], "uuid": t["uuid"], "template": t["host"]})
    key_of = {}
    for meth in ("item.get", "itemprototype.get"):
        for i in api.call(meth, {"templateids": [tid], "output": ["itemid", "key_"]}):
            key_of[i["itemid"]] = i["key_"]
    for sec, meth, idk, extra in (("items", "item.get", "itemid", ["key_"]), ("discovery_rules", "discoveryrule.get", "itemid", ["key_"]),
                                  ("item_prototypes", "itemprototype.get", "itemid", ["key_"]),
                                  ("triggers", "trigger.get", "triggerid", ["description", "expression"]),
                                  ("trigger_prototypes", "triggerprototype.get", "triggerid", ["description", "expression"]),
                                  ("graphs", "graph.get", "graphid", ["name"]), ("graph_prototypes", "graphprototype.get", "graphid", ["name"])):
        p = {"templateids": [tid], "output": [idk, "uuid"] + extra, "inherited": False}
        if "expression" in extra:
            p["selectFunctions"] = "extend"
        for o in api.call(meth, p):
            expr = raw_expression(o.get("expression"), o.get("functions"), key_of, t["host"]) if "expression" in extra else None
            out.append({"section": sec, "id": o[idk], "uuid": o.get("uuid"), "key": o.get("key_"),
                        "name": o.get("description") or o.get("name"), "expression": expr})
    for v in api.call("valuemap.get", {"hostids": [tid], "output": ["valuemapid", "name", "uuid"]}):
        out.append({"section": "valuemaps", "id": v["valuemapid"], "uuid": v["uuid"], "name": v["name"]})
    for b in api.call("templatedashboard.get", {"templateids": [tid], "output": ["dashboardid", "name", "uuid"]}):
        out.append({"section": "dashboards", "id": b["dashboardid"], "uuid": b["uuid"], "name": b["name"]})
    return out


def _norm_text(text, gen):
    """A trigger name or expression with keys and macros in their new spelling and no spaces."""
    text = re.sub(r"(/[^/()]+/)([A-Za-z0-9_.\-]+(?:\[[^\]]*\])?)", lambda m: m.group(1) + map_key(m.group(2), gen), text or "")
    text = re.sub(r"\{\$[A-Z0-9_.]+\}", lambda m: map_macro(m.group(0), gen), text)
    return re.sub(r"\s+", "", text)


def plan_uuids(zobjs, eobjs, gen, renames):
    """The uuid updates that make an import of the export UPDATE these objects in place.

    Zabbix's import matches objects by uuid. A template of an older generation has other uuids,
    so the import tries to create every trigger, graph and value map a second time and stops at
    the first ("Trigger ... already exists"). Giving the existing objects the export's uuids
    first — matched by key, by name and expression in their new spelling, or by name — turns the
    import into the update it should be. renames: {id: new key} planned for this template."""
    def ident(o, side):
        sec = o["section"]
        if sec == "templates":
            return ("t",)
        if sec in ("items", "discovery_rules", "item_prototypes"):
            k = renames.get(o.get("id"), o.get("key")) if side == "z" else o.get("key")
            return (k,)
        if sec in ("triggers", "trigger_prototypes"):
            return (_norm_text(o.get("name"), gen), _norm_text(o.get("expression"), gen))
        return (o.get("name"),)

    by = {}
    for e in eobjs:
        if e["section"] in UUID_METHOD and e.get("uuid"):
            by.setdefault((e["section"],) + ident(e, "e"), []).append(e)
    used = {z["uuid"] for z in zobjs}
    out = []
    for z in zobjs:
        if z["section"] not in UUID_METHOD:
            continue
        cand = by.get((z["section"],) + ident(z, "z"), [])
        if len(cand) != 1 or cand[0]["uuid"] == z["uuid"]:
            continue
        if cand[0]["uuid"] in used:
            continue  # someone already has it; the import will match that one
        meth, idk = UUID_METHOD[z["section"]]
        out.append({"method": meth, "idfield": idk, "id": z["id"], "uuid": cand[0]["uuid"], "section": z["section"],
                    "what": z.get("key") or z.get("name") or z.get("template")})
        used.add(cand[0]["uuid"])
    return out


# ---------------------------------------------------------------------------------- output

def print_plan(p, verbose):
    print(f"\n== host {p['host']}")
    for r in p["refused"]:
        print(f"   REFUSED: {r}")
    counts = {}
    for x in p["items"]:
        counts[x["action"]] = counts.get(x["action"], 0) + 1
    print("   items: " + ", ".join(f"{k} {v}" for k, v in sorted(counts.items())))
    for x in p["items"]:
        if x["action"] in ("RENAME", "ADOPT", "REFUSE") or (verbose and x["action"] == "KEEP"):
            arrow = f" -> {x['new_key']}" if x["new_key"] != x["key"] else ""
            print(f"   {x['action']:7} {x['key']}{arrow}  [{x['value_type']}, {x['count']} values]  {x['why']}")
    keep = [x for x in p["items"] if x["action"] == "KEEP"]
    if keep and not verbose:
        print(f"   KEEP    {len(keep)} item(s) stay on the host as host items with their history (--verbose lists them)")
    for x in p.get("rules", []):
        print(f"   {x['action']:7} discovery rule {x['key']}  {x['why']}")
    for m in p["macros"]:
        print(f"   MACRO   {m['macro']} ({MACRO_TYPE[m['type']]}) at host level — {m['why']}")
    for m in p["manual"]:
        print(f"   BY HAND {m}")
    for t in p["triggers"]:
        print(f"   DUPLICATE-CANDIDATE trigger {t['description']!r} (old; the new template likely has its own)")
    if p["unlink"]:
        print(f"   unlink (without clear): {', '.join(p.get('unlink_names', p['unlink']))}")
    if p["link"]:
        print(f"   link: {', '.join(p.get('link_names', p['link']))}")


def print_in_place(p, verbose):
    print(f"\n== template {p['template']} (in place)")
    counts = {}
    for r in p["rows"]:
        counts[r["action"]] = counts.get(r["action"], 0) + 1
    print("   objects: " + ", ".join(f"{k} {v}" for k, v in sorted(counts.items())))
    for r in p["rows"]:
        if r["action"] == "RENAME" or (verbose and r["action"] == "KEEP"):
            print(f"   {r['action']:7} {r['kind']} {r['key']} -> {r['new_key']}  {r['why']}")
    for m in p["macros"]:
        print(f"   MACRO   {m['macro']} on the template — {m['why']}")
    for m in p["manual"]:
        print(f"   BY HAND {m}")
    if p.get("uuids") is not None:
        secs = {}
        for x in p["uuids"]:
            secs[x["section"]] = secs.get(x["section"], 0) + 1
        print(f"   UUID    {len(p['uuids'])} object(s) take the export's uuid so --import updates them in place"
              + (": " + ", ".join(f"{k} {v}" for k, v in sorted(secs.items())) if secs else ""))


# ----------------------------------------------------------------------------------- commit

def verify(api, before, hostid, renamed, rules=None):
    after = snapshot_host(api, hostid, count=False)
    ids = {i["itemid"]: i for i in after["items"]}
    lost = [i["key_"] for i in before["items"] if i["itemid"] not in ids]
    # An adopted discovery rule: same id, inherited again. Its discovered items are among the
    # host's items, so a lost one shows up in lost_items like any other.
    rule_ids = {r["itemid"]: r for r in after["rules"]}
    rules_not_adopted = [k for rid, k in (rules or {}).items() if rid not in rule_ids or rule_ids[rid]["templateid"] == "0"]
    fewer, rows = [], []
    for i in before["items"]:
        a = ids.get(i["itemid"])
        if not a:
            continue
        n_after = history_count(api, a)
        n_before = before["counts"].get(i["itemid"])
        if n_before is not None and n_after is not None and n_after < n_before:
            fewer.append(f"{a['key_']}: {n_before} -> {n_after}")
        rows.append({"itemid": i["itemid"], "key_before": i["key_"], "key_after": a["key_"],
                     "inherited_after": a["templateid"] != "0", "before": n_before, "after": n_after})
    not_adopted = [k for iid, (k, nk) in renamed.items() if iid in ids and ids[iid]["templateid"] == "0"]
    return {"lost_items": lost, "fewer_values": fewer, "not_adopted": not_adopted, "rules_not_adopted": rules_not_adopted, "rows": rows,
            "new_items": [i["key_"] for i in after["items"] if i["itemid"] not in {b["itemid"] for b in before["items"]}]}


def commit_replace(api, p, snap, gen, retire, journal):
    j = {"kind": "replace", "hostid": p["hostid"], "host": p["host"], "macros": [], "unlinked": [], "renamed": [],
         "linked": [], "adopted_rules": [], "disabled_triggers": [], "done": False}
    journal["steps"].append(j)
    for m in p["macros"]:
        r = api.call("usermacro.create", {"hostid": p["hostid"], "macro": m["macro"], "value": m["value"] or "",
                                          "type": int(m["type"]), "description": "copied by migrate-templates.py"})
        j["macros"].append({"hostmacroid": r["hostmacroids"][0], "macro": m["macro"]})
    if p["unlink"]:
        api.call("host.massremove", {"hostids": [p["hostid"]], "templateids": p["unlink"]})
        j["unlinked"] = p["unlink"]
    renamed = {}
    for x in p["items"]:
        if x["action"] == "RENAME":
            api.call("item.update", {"itemid": x["itemid"], "key_": x["new_key"]})
            j["renamed"].append({"itemid": x["itemid"], "old": x["key"], "new": x["new_key"]})
            renamed[x["itemid"]] = (x["key"], x["new_key"])
    for x in p["items"]:
        if x["action"] == "ADOPT":
            renamed[x["itemid"]] = (x["key"], x["key"])
    if p["link"]:
        api.call("host.massadd", {"hosts": [{"hostid": p["hostid"]}], "templates": [{"templateid": t} for t in p["link"]]})
        j["linked"] = p["link"]
    adopted_rules = {x["itemid"]: x["key"] for x in p.get("rules", []) if x["action"] == "ADOPT"}
    j["adopted_rules"] = sorted(adopted_rules.values())
    res = verify(api, snap, p["hostid"], renamed, adopted_rules)
    if retire:
        after = snapshot_host(api, p["hostid"], count=False)
        target_ids = set(p["link"]) | {t for t in (t["templateid"] for t in snap["host"]["parentTemplates"]) if t not in p["unlink"]}
        new_sets = {tuple(t["itemids"]) for t in after["triggers"] if t["origin"] in target_ids}
        for t in after["triggers"]:
            if t["templateid"] == "0" and t["triggerid"] in {d["triggerid"] for d in p["triggers"]} \
                    and tuple(t["itemids"]) in new_sets and t["status"] == "0":
                api.call("trigger.update", {"triggerid": t["triggerid"], "status": 1})
                j["disabled_triggers"].append({"triggerid": t["triggerid"], "description": t["description"]})
    j["done"] = True
    j["verify"] = {k: v for k, v in res.items() if k != "rows"}
    return res


def commit_in_place(api, p, import_file, journal):
    j = {"kind": "in-place", "templateid": p["templateid"], "template": p["template"], "macros": [], "renamed": [],
         "imported": None, "done": False}
    journal["steps"].append(j)
    for m in p["macros"]:
        r = api.call("usermacro.create", {"hostid": m["hostid"], "macro": m["macro"], "value": m["value"] or "",
                                          "type": int(m["type"]), "description": "copied by migrate-templates.py"})
        j["macros"].append({"hostmacroid": r["hostmacroids"][0], "macro": m["macro"]})
    meth = {"item": "item.update", "prototype": "itemprototype.update", "lld": "discoveryrule.update"}
    for r in p["rows"]:
        if r["action"] == "RENAME":
            api.call(meth[r["kind"]], {"itemid": r["itemid"], "key_": r["new_key"]})
            j["renamed"].append({"kind": r["kind"], "itemid": r["itemid"], "old": r["key"], "new": r["new_key"]})
    if import_file:
        for x in p.get("uuids", []):
            api.call(x["method"], {x["idfield"]: x["id"], "uuid": x["uuid"]})
        j["uuids"] = len(p.get("uuids", []))
        fmt = "json" if import_file.endswith(".json") else "yaml"
        api.call("configuration.import", {"format": fmt, "source": open(import_file, encoding="utf-8").read(),
                                          "rules": IMPORT_RULES})
        j["imported"] = import_file
    j["done"] = True


def rollback(api, journal):
    meth = {"item": "item.update", "prototype": "itemprototype.update", "lld": "discoveryrule.update"}
    for j in reversed(journal["steps"]):
        if j["kind"] == "replace":
            print(f"rollback host {j['host']}")
            for t in j.get("disabled_triggers", []):
                api.call("trigger.update", {"triggerid": t["triggerid"], "status": 0})
            if j["linked"]:
                api.call("host.massremove", {"hostids": [j["hostid"]], "templateids": j["linked"]})
            for r in reversed(j["renamed"]):
                api.call("item.update", {"itemid": r["itemid"], "key_": r["old"]})
            if j["unlinked"]:
                api.call("host.massadd", {"hosts": [{"hostid": j["hostid"]}], "templates": [{"templateid": t} for t in j["unlinked"]]})
        else:
            print(f"rollback template {j['template']} (keys back; an import that was done is not undone)")
            for r in reversed(j["renamed"]):
                api.call(meth[r["kind"]], {"itemid": r["itemid"], "key_": r["old"]})
        for m in j.get("macros", []):
            print(f"   macro {m['macro']} (hostmacroid {m['hostmacroid']}) left in place — remove it by hand if unwanted")


# ------------------------------------------------------------------------------------- main

def main(argv=None):
    ap = argparse.ArgumentParser(description="Move hosts to the ElasticPro templates, keeping every stored value.")
    ap.add_argument("--map", help="the mapping file (copy migrate-map.example.json)")
    ap.add_argument("--generation", action="append", default=[], help="only these generations (by name)")
    ap.add_argument("--host", action="append", default=[], help="only these hosts (technical name; replace mode)")
    ap.add_argument("--commit", action="store_true", help="make the changes (default: plan only)")
    ap.add_argument("--import", dest="do_import", action="store_true",
                    help="in-place mode: import the current export after the renames")
    ap.add_argument("--retire-old-triggers", action="store_true",
                    help="disable (never delete) old triggers that duplicate a new template trigger")
    ap.add_argument("--count-since-days", type=int, default=0, help="count values of the last N days only (faster)")
    ap.add_argument("--journal", help="where --commit writes its journal")
    ap.add_argument("--rollback", metavar="JOURNAL", help="undo a committed run from its journal")
    ap.add_argument("--json", help="also write the plan and results here")
    ap.add_argument("--verbose", action="store_true")
    ap.add_argument("--insecure", action="store_true", help="skip TLS verification for ZABBIX_URL")
    a = ap.parse_args(argv)

    url = os.environ.get("ZABBIX_URL")
    if not url:
        raise SystemExit("set ZABBIX_URL and ZABBIX_TOKEN (or ZABBIX_USER and ZABBIX_PASSWORD)")
    if not os.environ.get("ZABBIX_TOKEN") and not (os.environ.get("ZABBIX_USER") and os.environ.get("ZABBIX_PASSWORD")):
        raise SystemExit("set ZABBIX_TOKEN, or both ZABBIX_USER and ZABBIX_PASSWORD")
    api = Api(url, os.environ.get("ZABBIX_TOKEN"), os.environ.get("ZABBIX_USER"), os.environ.get("ZABBIX_PASSWORD"),
              a.insecure, commit=a.commit)
    print(f"Zabbix {api.version} at {api.url} — {'COMMIT' if a.commit else 'dry run: nothing will be changed'}")
    if not str(api.version).startswith("7.0."):
        print("note: written and tested for Zabbix 7.0")

    if a.rollback:
        journal = json.load(open(a.rollback))
        if not a.commit:
            for j in journal["steps"]:
                print(f"would roll back {j['kind']} {j.get('host') or j.get('template')}: "
                      f"{len(j.get('renamed', []))} key(s) back, unlink {j.get('linked', [])}, relink {j.get('unlinked', [])}")
            print("dry run — add --commit to roll back")
            return 0
        rollback(api, journal)
        return 0
    if not a.map:
        raise SystemExit("--map is required (copy migrate-map.example.json)")

    since = int(time.time()) - a.count_since_days * 86400 if a.count_since_days else None
    gens = [g for g in load_map(a.map) if not a.generation or g["name"] in a.generation]
    out = {"zabbix": api.version, "commit": a.commit, "generations": []}
    journal = {"started": time.strftime("%Y-%m-%dT%H:%M:%S"), "zabbix": api.url, "steps": []}
    refused_total = 0
    try:
        for g in gens:
            print(f"\n######## generation {g['name']} ({g['mode']})")
            gout = {"name": g["name"], "mode": g["mode"], "plans": []}
            out["generations"].append(gout)
            if g["mode"] == "in-place":
                for name, rel in g.get("templates", {}).items():
                    tpl = api.call("template.get", {"filter": {"host": name}, "output": ["templateid", "host"],
                                                    "selectMacros": ["macro", "value", "type"]})
                    if not tpl:
                        print(f"\n== template {name}: not in this Zabbix — nothing to do")
                        continue
                    tpl = tpl[0]
                    path = rel if os.path.isabs(rel) else os.path.normpath(os.path.join(g["_dir"], rel))
                    target = export_keys(path)
                    titems = api.call("item.get", {"templateids": [tpl["templateid"]], "output": ["itemid", "key_", "value_type", "templateid"]})
                    tprotos = api.call("itemprototype.get", {"templateids": [tpl["templateid"]], "output": ["itemid", "key_", "value_type", "templateid"]})
                    trules = api.call("discoveryrule.get", {"templateids": [tpl["templateid"]], "output": ["itemid", "key_", "templateid"]})
                    hosts = api.call("host.get", {"templateids": [tpl["templateid"]], "output": ["hostid", "host"]})
                    host_items = []
                    for h in hosts:
                        for i in api.call("item.get", {"hostids": [h["hostid"]], "output": ["key_"]}):
                            host_items.append((h["host"], i["key_"]))
                    p = plan_in_place(tpl, titems, tprotos, trules, target, g, host_items)
                    p["hosts"] = [h["host"] for h in hosts]
                    if a.do_import:
                        renames = {r["itemid"]: r["new_key"] for r in p["rows"] if r["action"] == "RENAME"}
                        p["uuids"] = plan_uuids(zabbix_objects(api, tpl["templateid"]), export_objects(path), g, renames)
                    print_in_place(p, a.verbose)
                    print(f"   hosts following this template: {len(hosts)}")
                    befores = {h["hostid"]: snapshot_host(api, h["hostid"], since) for h in hosts} if a.commit else {}
                    if a.commit:
                        commit_in_place(api, p, path if a.do_import else None, journal)
                        for hid, b in befores.items():
                            res = verify(api, b, hid, {})
                            p.setdefault("verify", {})[b["host"]["host"]] = {k: v for k, v in res.items() if k != "rows"}
                            ok = not res["lost_items"] and not res["fewer_values"]
                            print(f"   verify {b['host']['host']}: {'ok' if ok else 'PROBLEM'} — lost {len(res['lost_items'])}, "
                                  f"fewer values {len(res['fewer_values'])}, new items {len(res['new_items'])}")
                    gout["plans"].append(p)
                continue
            # replace
            olds = api.call("template.get", {"filter": {"host": g["old_templates"]}, "output": ["templateid", "host"],
                                             "selectMacros": ["macro", "value", "type"]})
            if not olds:
                print("none of the old templates is in this Zabbix — nothing to do")
                continue
            old_tpl = {t["templateid"]: {"host": t["host"], "macros": {m["macro"]: (m.get("value"), m["type"]) for m in t["macros"]}}
                       for t in olds}
            target = targets(api, g["new_templates"])
            names = {t["templateid"]: t["host"] for t in olds + target["templates"]}
            hosts = api.call("host.get", {"templateids": list(old_tpl), "output": ["hostid", "host"]})
            if a.host:
                hosts = [h for h in hosts if h["host"] in a.host]
            print(f"hosts linked to {', '.join(t['host'] for t in olds)}: {len(hosts)}")
            for h in hosts:
                snap = snapshot_host(api, h["hostid"], since)
                p = plan_replace(snap, g, old_tpl, target, unlink=g.get("unlink_old", True))
                p["unlink_names"] = [names.get(t, t) for t in p["unlink"]]
                p["link_names"] = [names.get(t, t) for t in p["link"]]
                p["groups"] = [x["name"] for x in snap["host"]["hostgroups"]]
                print_plan(p, a.verbose)
                gout["plans"].append(p)
                if p["refused"]:
                    refused_total += 1
                    continue
                if a.commit:
                    res = commit_replace(api, p, snap, g, a.retire_old_triggers, journal)
                    p["result"] = res
                    ok = not res["lost_items"] and not res["fewer_values"] and not res["not_adopted"] and not res["rules_not_adopted"]
                    print(f"   verify: {'ok' if ok else 'PROBLEM'} — items lost {len(res['lost_items'])}, items with fewer "
                          f"values {len(res['fewer_values'])}, renamed but not adopted {len(res['not_adopted'])}, "
                          f"discovery rules not adopted {len(res['rules_not_adopted'])}, "
                          f"new (empty) items from the new templates {len(res['new_items'])}")
                    for x in res["fewer_values"] + res["not_adopted"] + res["rules_not_adopted"] + res["lost_items"]:
                        print(f"     ! {x}")
                    print(f"   {'item':48} {'values before':>13} {'after':>8}")
                    for r in res["rows"]:
                        if r["key_before"] != r["key_after"] or a.verbose:
                            print(f"   {r['key_before'][:22] + ' -> ' + r['key_after'][:22]:48} {str(r['before']):>13} {str(r['after']):>8}")
    finally:
        if a.commit and journal["steps"]:
            path = a.journal or f"migrate-journal-{time.strftime('%Y%m%d-%H%M%S')}.json"
            with open(path, "w") as fh:
                json.dump(journal, fh, indent=1)
            print(f"\njournal: {path}  (roll back with --rollback {path} --commit)")
    if a.json:
        with open(a.json, "w") as fh:
            json.dump(out, fh, indent=1, default=list)
    if not a.commit:
        print("\ndry run — nothing was changed. Add --commit to make these changes.")
    if refused_total:
        print(f"{refused_total} host(s) refused and left as they are; see REFUSED above.")
    return 1 if refused_total else 0


if __name__ == "__main__":
    sys.exit(main())
