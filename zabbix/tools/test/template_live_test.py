#!/usr/bin/env python3
"""Live test of the ElasticPro templates against a real Zabbix 7.0 — import, macros, payloads.

    ZABBIX_URL=http://zabbix.example/zabbix ZABBIX_TOKEN=... python3 template_live_test.py
    ZABBIX_URL=... ZABBIX_USER=Admin ZABBIX_PASSWORD=... python3 template_live_test.py --fast
    python3 template_live_test.py --dry-run          # no Zabbix needed: what would be done

What it does, per template file (every one in ../../templates, plus any --extra export, such as
the master, devices, jump and cluster templates the Clients page writes):

  1. imports a COPY of the template, named <prefix><name> with uuids derived from the
     originals, so it never touches the real template or its hosts on the Zabbix it runs
     against; checks configuration.importcompare reports no change afterwards and imports it
     a second time (idempotence);
  2. creates a test host <prefix>host-<n> in host group <prefix>templates, linked to that copy;
     its agent interface is 192.0.2.1 (TEST-NET-1), so agent and system.run checks of a copied
     template go nowhere instead of to a real agent;
  3. lists where every template macro is consumed (item URL, credentials, parameters, trigger
     expressions) and sets each one at host level to a test value;
  4. feeds payloads — history.push for trapper items, a mock HTTP endpoint for the automation
     template's HTTP item — and checks every dependent item gets a value and every trigger
     evaluates to the expected state, including the edge cases: empty secret, wrong URL,
     thresholds exactly at the boundary, stale data;
  5. prints a matrix (template x check x result) and, with --json FILE, writes it as JSON;
  6. removes everything it created, unless --keep. --cleanup removes leftovers of an earlier
     --keep run and does nothing else.

Only objects whose names start with the prefix ($EP_TEST_PREFIX, default "ztest-") are ever
created, changed or deleted. Never point this at a production Zabbix all the same: it creates
hosts, and a trapper host is a place anyone who can reach port 10051 can push to.

The automation template polls an HTTP document. To test it, run mock_scrape_server.py where the
Zabbix SERVER can reach it and set
    EP_TEST_MOCK_URL=http://<that host>:8000   EP_TEST_MOCK_USER=...   EP_TEST_MOCK_PASSWORD=...
Without EP_TEST_MOCK_URL those checks are reported SKIP, not passed.

--fast skips the checks that must wait out a staleness window (nodata / fuzzytime), which take
two to three minutes each. Exit status: 0 when nothing FAILed, 1 otherwise, 2 for a usage or
connection error.
"""
import argparse, hashlib, json, os, re, sys, time, urllib.error, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
TEMPLATES_DIR = os.path.normpath(os.path.join(HERE, "..", "..", "templates"))


def default_files():
    """Every template in ../../templates — a template added there is tested without editing this."""
    names = sorted(f for f in os.listdir(TEMPLATES_DIR) if f.endswith((".yaml", ".yml", ".json")))
    return sorted(names, key=lambda f: (f != "template-elasticpro.yaml", f))

ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
ap.add_argument("--dry-run", action="store_true", help="print the plan; no Zabbix calls at all")
ap.add_argument("--fast", action="store_true", help="skip the slow staleness-window checks")
ap.add_argument("--keep", action="store_true", help="leave the test hosts and template copies")
ap.add_argument("--cleanup", action="store_true", help="only remove leftovers of a --keep run")
ap.add_argument("--extra", action="append", default=[], help="another export to test (yaml or json)")
ap.add_argument("--only", action="append", default=[], help="test only templates whose name contains this")
ap.add_argument("--json", help="also write the matrix here")
ap.add_argument("--insecure", action="store_true", help="skip TLS verification for ZABBIX_URL")
args = ap.parse_args()

PREFIX = os.environ.get("EP_TEST_PREFIX", "ztest-")
if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_-]*-", PREFIX):
    sys.exit("EP_TEST_PREFIX must be letters/digits ending in '-', e.g. ztest-")
GROUP = PREFIX + "templates"
MOCK = os.environ.get("EP_TEST_MOCK_URL", "").rstrip("/")
MOCK_USER = os.environ.get("EP_TEST_MOCK_USER", "zabbix")
MOCK_PASSWORD = os.environ.get("EP_TEST_MOCK_PASSWORD", "")

ROWS = []


def row(template, check, expected, actual, status):
    ROWS.append({"template": template, "check": check, "expected": str(expected),
                 "actual": str(actual), "result": status})
    mark = {"PASS": "ok  ", "FAIL": "FAIL", "SKIP": "skip", "INFO": "info"}[status]
    print(f"  [{mark}] {check}: {actual}" + ("" if status != "FAIL" else f"   (expected {expected})"))


def check(template, name, expected, actual, ok=None):
    ok = (actual == expected) if ok is None else ok
    row(template, name, expected, actual, "PASS" if ok else "FAIL")
    return ok


# ------------------------------------------------------------------------------------ files

def load(path):
    text = open(path, encoding="utf-8").read()
    fmt = "json" if path.endswith(".json") else "yaml"
    if fmt == "json":
        names = [t["template"] for t in json.loads(text)["zabbix_export"].get("templates", [])]
    else:
        # The export format is regular enough for this: "    - uuid: …\n      template: 'Name'".
        names = [m.group(2) for m in re.finditer(r"^\s+template: (['\"]?)(.+?)\1\s*$", text, re.M)]
    return text, fmt, names


def uuid_like(seed):
    """A UUIDv4-shaped id derived from seed (Zabbix validates the v4 shape)."""
    h = hashlib.sha256(seed.encode()).hexdigest()
    return h[0:12] + "4" + h[13:16] + "89ab"[int(h[16], 16) % 4] + h[17:32]


def copy_of(text, names):
    """The export renamed to <prefix><name> with every uuid replaced, so importing it can only
    ever create or update the copy. Longest name first, in case one name contains another."""
    for n in sorted(names, key=len, reverse=True):
        text = text.replace(n, PREFIX + n)
    return re.sub(r"(uuid['\"]?\s*:\s*['\"]?)([0-9a-f]{32})",
                  lambda m: m.group(1) + uuid_like(PREFIX + m.group(2)), text)


# -------------------------------------------------------------------------------------- api

class Api:
    def __init__(self):
        self.url = os.environ.get("ZABBIX_URL", "").rstrip("/")
        if not self.url:
            sys.exit("set ZABBIX_URL (and ZABBIX_TOKEN, or ZABBIX_USER + ZABBIX_PASSWORD)")
        if self.url.endswith("/api_jsonrpc.php"):
            self.url = self.url[: -len("/api_jsonrpc.php")]
        self.ctx = None
        if args.insecure:
            import ssl
            self.ctx = ssl._create_unverified_context()
        self.auth = os.environ.get("ZABBIX_TOKEN")
        self.version = self.raw("apiinfo.version", {}, authed=False)
        if not self.auth:
            if not (os.environ.get("ZABBIX_USER") and os.environ.get("ZABBIX_PASSWORD")):
                sys.exit("set ZABBIX_TOKEN, or both ZABBIX_USER and ZABBIX_PASSWORD")
            self.auth = self.raw("user.login", {"username": os.environ["ZABBIX_USER"],
                                                "password": os.environ["ZABBIX_PASSWORD"]}, authed=False)

    def raw(self, method, params, authed=True):
        h = {"Content-Type": "application/json-rpc"}
        if authed:
            h["Authorization"] = "Bearer " + self.auth
        body = json.dumps({"jsonrpc": "2.0", "method": method, "params": params, "id": 1}).encode()
        try:
            r = json.load(urllib.request.urlopen(urllib.request.Request(self.url + "/api_jsonrpc.php", body, h),
                                                 context=self.ctx, timeout=120))
        except (urllib.error.URLError, OSError, ValueError) as e:
            print(f"{method}: cannot talk to {self.url}: {e}", file=sys.stderr)
            sys.exit(2)
        if "error" in r:
            raise ApiError(method, r["error"].get("data") or r["error"].get("message"))
        return r["result"]

    def __call__(self, method, **params):
        return self.raw(method, params)


class ApiError(Exception):
    def __init__(self, method, msg):
        super().__init__(f"{method}: {msg}")
        self.msg = msg


RULES = {
    "template_groups": {"createMissing": True, "updateExisting": True},
    "templates": {"createMissing": True, "updateExisting": True},
    "items": {"createMissing": True, "updateExisting": True},
    "discoveryRules": {"createMissing": True, "updateExisting": True},
    "triggers": {"createMissing": True, "updateExisting": True},
    "graphs": {"createMissing": True, "updateExisting": True},
    "httptests": {"createMissing": True, "updateExisting": True},
    "templateDashboards": {"createMissing": True, "updateExisting": True},
    "valueMaps": {"createMissing": True, "updateExisting": True},
}
VT = {"0": "FLOAT", "1": "CHAR", "2": "LOG", "3": "UNSIGNED", "4": "TEXT"}
MACRO_FIELDS = ["key_", "url", "username", "password", "params", "delay", "timeout", "posts",
                "headers", "query_fields", "ssl_cert_file", "ssl_key_file", "ssl_key_password",
                "http_proxy", "status_codes", "jmx_endpoint", "publickey", "privatekey"]


def wait(fn, timeout=120, every=3):
    """fn() until it returns something truthy or timeout seconds pass; the last result either way."""
    end = time.time() + timeout
    while True:
        r = fn()
        if r or time.time() > end:
            return r
        time.sleep(every)


def export_of(api, tplid):
    return api("configuration.export", format="json", options={"templates": [tplid]})


def _norm(v):
    return v.replace("\r\n", "\n") if isinstance(v, str) else v


def _same(before, after):
    """True when after only ADDS keys to before (the source spelling out a default value that an
    export omits) and every value before has is unchanged, line endings aside."""
    if isinstance(before, dict) and isinstance(after, dict):
        return all(k in after and _same(v, after[k]) for k, v in before.items())
    if isinstance(before, list) and isinstance(after, list):
        return len(before) == len(after) and all(_same(x, y) for x, y in zip(before, after))
    return _norm(before) == _norm(after)


def real_changes(diff, path=""):
    """What configuration.importcompare reports that is a real change, as short strings."""
    out = []
    for kind, groups in (diff or {}).items():
        if not isinstance(groups, dict):
            continue
        for verb in ("added", "removed"):
            for o in groups.get(verb, []):
                o = o.get("after") or o.get("before") or o
                out.append(f"{path}{kind} {verb}: {o.get('key') or o.get('name') or o.get('expression') or o.get('uuid')}")
        for o in groups.get("updated", []):
            b, a = o.get("before", {}), o.get("after", {})
            if not _same(b, a):
                out.append(f"{path}{kind} updated: {a.get('key') or a.get('name') or a.get('uuid')}")
            nested = {k: v for k, v in o.items() if k not in ("before", "after")}
            out += real_changes(nested, f"{path}{kind}/")
    return out


# ------------------------------------------------------------------------------- the tests

def items_of(api, hostid):
    out = {}
    for it in api("item.get", hostids=hostid, output=["itemid", "key_", "type", "value_type", "state", "error",
                                                       "lastvalue", "lastclock", "status", "flags"]):
        out[it["key_"]] = it
    return out


def triggers_of(api, hostid):
    return api("trigger.get", hostids=hostid, expandExpression=True, expandDescription=True,
               output=["triggerid", "description", "expression", "value", "state", "error", "priority", "status"])


def find_trigger(trigs, key_part, contains=None, priority=None):
    for t in trigs:
        if key_part in t["expression"] and (contains is None or contains in t["description"]) \
                and (priority is None or t["priority"] == str(priority)):
            return t
    return None


def set_macro(api, hostid, macro, value, mtype=0):
    cur = api("usermacro.get", hostids=hostid, filter={"macro": macro}, output=["hostmacroid"])
    if cur:
        api("usermacro.update", hostmacroid=cur[0]["hostmacroid"], value=value, type=mtype)
    else:
        api("usermacro.create", hostid=hostid, macro=macro, value=value, type=mtype)


def push(api, values):
    """history.push, retried while the server's configuration cache has not seen the item yet."""
    def attempt():
        r = api.raw("history.push", values)
        bad = [x for x in r.get("data", []) if x.get("error")]
        return (not bad, bad)
    ok, bad = attempt()
    end = time.time() + 90
    while not ok and time.time() < end:
        time.sleep(5)
        ok, bad = attempt()
    return ok, bad


def check_now(api, itemid):
    api("task.create", type=6, request={"itemid": itemid})


def macro_usage(api, tplid):
    """macro -> the objects that consume it (not names/descriptions: those only display it)."""
    use = {}

    def scan(kind, objs, fields):
        for o in objs:
            for f in fields:
                v = o.get(f)
                if isinstance(v, list):
                    v = json.dumps(v)
                for m in re.findall(r"\{\$[A-Z0-9_.]+(?::[^}]*)?\}", v or ""):
                    use.setdefault(re.sub(r":.*\}$", "}", m), set()).add(f"{kind}:{f}")

    scan("item", api("item.get", templateids=tplid, output=MACRO_FIELDS + ["formula"]), MACRO_FIELDS + ["params"])
    scan("lld", api("discoveryrule.get", templateids=tplid, output=MACRO_FIELDS + ["lifetime"]), MACRO_FIELDS + ["lifetime"])
    scan("prototype", api("itemprototype.get", templateids=tplid, output=MACRO_FIELDS), MACRO_FIELDS)
    # 7.0 keeps function parameters (where a macro period sits) apart from the expression, which
    # reads "{12345}=1"; and expandExpression resolves macros to their values, so it hides them.
    # The raw expression (constants such as {$X.WARN}) plus every function's parameters it is.
    for kind, meth in (("trigger", "trigger.get"), ("trigger-prototype", "triggerprototype.get")):
        objs = api(meth, templateids=tplid, output=["expression", "recovery_expression"],
                   selectFunctions=["parameter"])
        for o in objs:
            o["parameters"] = " ".join(f.get("parameter", "") for f in o.get("functions", []))
        scan(kind, objs, ["expression", "recovery_expression", "parameters"])
    # Preprocessing parameters consume macros too (e.g. a JSONPath or a threshold).
    for kind, meth in (("item", "item.get"), ("prototype", "itemprototype.get")):
        for o in api(meth, templateids=tplid, output=["itemid"], selectPreprocessing=["params"]):
            for p in o.get("preprocessing", []):
                for m in re.findall(r"\{\$[A-Z0-9_.]+\}", p.get("params", "")):
                    use.setdefault(m, set()).add(f"{kind}:preprocessing")
    return use


def test_value(macro, mtype, default):
    """A value different from the default, of the same kind, for macros no scenario sets itself."""
    if mtype == "1":
        return "ztest-secret"
    if re.fullmatch(r"\d+[smhdw]", default or ""):
        return str(int(default[:-1]) + 1) + default[-1]
    if re.fullmatch(r"\d+(\.\d+)?", default or ""):
        return str(float(default) + 1).rstrip("0").rstrip(".")
    if mtype == "2":
        return "ztest/path:key"
    return "ztest-value"


def generic(api, tname, host, tplid, items, trigs):
    """Every template: macros consumed, every item supported or explained, triggers evaluate."""
    unsupported = {k: it["error"] for k, it in items.items() if it["state"] == "1"}
    row(tname, "items on host", "", f"{len(items)} items, {len(unsupported)} unsupported", "INFO")
    for k, e in list(unsupported.items())[:15]:
        row(tname, f"unsupported {k}", "", e[:160], "INFO")


def scenario_trap(api, tname, host, items, overrides=None):
    """Push one value into every trapper item; every one of them must then have a value."""
    overrides = overrides or {}
    was = {k: it["lastclock"] for k, it in items.items()}
    values = []
    for k, it in items.items():
        if it["type"] != "2":
            continue
        v = overrides.get(k)
        if v is None:
            v = {"0": 1.5, "3": 1, "1": "ztest", "4": "ztest", "2": "ztest"}[it["value_type"]]
        # No clock: the server stamps the value on receipt. This machine's clock may differ from
        # the Zabbix server's, and nodata() and fuzzytime() judge by the server's.
        values.append({"itemid": it["itemid"], "value": v})
    if not values:
        return
    ok, bad = push(api, values)
    check(tname, "history.push accepted for every trapper item", 0, len(bad), ok)
    stale = lambda: [k for k, it in items_of(api, host).items() if it["type"] == "2" and it["lastclock"] == was.get(k)]
    wait(lambda: not stale() or None, 60)
    missing = stale()
    check(tname, "every trapper item has the pushed value", [], missing)


def state_of(api, host, key_part, contains=None, priority=None):
    t = find_trigger(triggers_of(api, host), key_part, contains, priority)
    return None if not t else ("UNKNOWN" if t["state"] == "1" else ("PROBLEM" if t["value"] == "1" else "OK"))


def expect_trigger(api, tname, host, label, key_part, want, contains=None, priority=None, timeout=60):
    got = wait(lambda: (lambda s: s if s == want else None)(state_of(api, host, key_part, contains, priority)), timeout)
    got = got or state_of(api, host, key_part, contains, priority)
    return check(tname, label, want, got)


def push_one(api, items, key, value):
    ok, bad = push(api, [{"itemid": items[key]["itemid"], "value": value}])
    if not ok:
        raise ApiError("history.push", bad)
    time.sleep(2)


# ---- per-template scenarios, keyed by the ORIGINAL template name

def s_alerts(api, tname, host, tplid, items):
    over = {k: 0 for k in items if k.startswith("elasticpro.alert[")}
    over.update({k: "" for k in items if k.startswith("elasticpro.alert.detail[")})
    over["elasticpro.alert[disk]"] = 2
    over["elasticpro.alert.detail[disk]"] = "critical: Disk usage above threshold — ztest 96%"
    over["elasticpro.alert[ilm]"] = 1
    over["elasticpro.alert.detail[ilm]"] = "warning: Indices in an ILM error step — ztest"
    scenario_trap(api, tname, host, items, over)
    expect_trigger(api, tname, host, "disk critical -> High problem", "alert.detail[disk]", "PROBLEM", priority=4)
    expect_trigger(api, tname, host, "disk critical does not also raise the Warning", "alert.detail[disk]", "OK", priority=2)
    expect_trigger(api, tname, host, "ilm warning -> Warning problem", "alert.detail[ilm]", "PROBLEM", priority=2)
    expect_trigger(api, tname, host, "ilm warning does not raise the High", "alert.detail[ilm]", "OK", priority=4)
    trigs = triggers_of(api, host)
    others = [t["description"] for t in trigs if "[disk]" not in t["expression"] and "[ilm]" not in t["expression"]
              and (t["value"] == "1" or t["state"] == "1")]
    check(tname, "every other alert trigger evaluated and clear", [], others)
    names = [p["name"] for p in api("problem.get", hostids=host, output=["name"])]
    check(tname, "problem name carries the detail ({ITEM.LASTVALUE1} in event_name)", True,
          any("ztest 96%" in n for n in names), None)
    push_one(api, items, "elasticpro.alert.detail[disk]", "")
    expect_trigger(api, tname, host, "empty detail resolves the problem", "alert.detail[disk]", "OK", priority=4)
    push_one(api, items, "elasticpro.alert.detail[disk]", "warning: Disk usage above threshold — ztest 86%")
    expect_trigger(api, tname, host, "critical -> warning moves the problem to Warning", "alert.detail[disk]", "PROBLEM", priority=2)


def s_plan(api, tname, host, tplid, items):
    over = {"elasticpro.plan.epoch": 1, "elasticpro.plan.reachable": 1,
            "elasticpro.plan[live.used.pct]": 50, "elasticpro.plan[live.days]": 100,
            "elasticpro.plan[live.total]": 1000, "elasticpro.plan[live.required.30d]": 300,
            "elasticpro.plan[backup.total]": 5000, "elasticpro.plan[backup.required.365d]": 4000}
    scenario_trap(api, tname, host, items, over)
    trigs = triggers_of(api, host)
    bad = [t["description"] for t in trigs if t["value"] == "1" or t["state"] == "1"]
    check(tname, "healthy plan: every trigger evaluated and clear", [], bad)
    # The threshold macros, set at host level to values the defaults would disagree with: a
    # trigger that still followed the template default would fire (or stay quiet) wrongly.
    set_macro(api, host, "{$ELASTICPRO.PLAN.LIVE_USED.WARN}", "90")
    set_macro(api, host, "{$ELASTICPRO.PLAN.LIVE_DAYS.MIN}", "10")
    time.sleep(15)  # configuration cache
    push_one(api, items, "elasticpro.plan[live.used.pct]", 89)
    expect_trigger(api, tname, host, "{$...LIVE_USED.WARN}=90 consumed: 89% (over the default 85) is clear",
                   "live.used.pct", "OK")
    push_one(api, items, "elasticpro.plan[live.used.pct]", 90)
    expect_trigger(api, tname, host, "boundary: exactly 90% is clear (strict >)", "live.used.pct", "OK")
    push_one(api, items, "elasticpro.plan[live.used.pct]", 90.5)
    expect_trigger(api, tname, host, "90.5% -> problem", "live.used.pct", "PROBLEM")
    t = find_trigger(triggers_of(api, host), "live.used.pct")
    check(tname, "trigger name shows the host macro value", True, bool(t and "over 90%" in t["description"]), None)
    push_one(api, items, "elasticpro.plan[live.days]", 12)
    expect_trigger(api, tname, host, "{$...LIVE_DAYS.MIN}=10 consumed: 12 days (under the default 14) is clear",
                   "live.days", "OK")
    push_one(api, items, "elasticpro.plan[live.days]", 10)
    expect_trigger(api, tname, host, "boundary: exactly 10 days is clear (strict <)", "live.days", "OK")
    push_one(api, items, "elasticpro.plan[live.days]", 9.5)
    expect_trigger(api, tname, host, "9.5 days -> problem", "live.days", "PROBLEM")
    push_one(api, items, "elasticpro.plan.reachable", 0)
    expect_trigger(api, tname, host, "reachable=0 -> unreachable problem", "plan.reachable", "PROBLEM")
    push_one(api, items, "elasticpro.plan[live.total]", 200)
    expect_trigger(api, tname, host, "live total below 30-day need -> problem", "live.required.30d", "PROBLEM")
    push_one(api, items, "elasticpro.plan[live.total]", 300)
    expect_trigger(api, tname, host, "boundary: live total equal to the need is clear", "live.required.30d", "OK")
    if args.fast:
        row(tname, "{$ELASTICPRO.PLAN.STALE} nodata", "PROBLEM", "skipped (--fast)", "SKIP")
        return
    set_macro(api, host, "{$ELASTICPRO.PLAN.STALE}", "60s")
    # Wait until the server's configuration cache has the new period: the trigger name carries
    # the macro, so it shows when the host value is in force.
    named = wait(lambda: (lambda t: t if t and "for 60s" in t["description"] else None)(
        find_trigger(triggers_of(api, host), "plan.epoch")), 90)
    check(tname, "{$...PLAN.STALE}=60s in force (trigger name)", True, bool(named), None)
    push_one(api, items, "elasticpro.plan.epoch", 2)
    expect_trigger(api, tname, host, "{$...PLAN.STALE}=60s, fresh push -> clear", "plan.epoch", "OK", timeout=20)
    expect_trigger(api, tname, host, "{$...PLAN.STALE}=60s consumed: no push for 60s -> problem", "plan.epoch",
                   "PROBLEM", timeout=240)


def s_delay(api, tname, host, tplid, items):
    over = {"elasticpro.delay[devices]": 40, "elasticpro.delay[late]": 3, "elasticpro.delay[delayed]": 2,
            "elasticpro.delay[critical]": 1, "elasticpro.delay[clock_ahead]": 0, "elasticpro.delay[median]": 12.5,
            "elasticpro.delay[worst]": 5400, "elasticpro.delay[worst_device]": "fw-01"}
    scenario_trap(api, tname, host, items, over)
    its = items_of(api, host)
    check(tname, "units: worst delay stored in seconds (5400)", "5400", its["elasticpro.delay[worst]"]["lastvalue"])


def s_automation(api, tname, host, tplid, items):
    master = items["elasticpro.state"]["itemid"]
    if not MOCK:
        row(tname, "HTTP payload, credentials, URL, staleness", "", "EP_TEST_MOCK_URL not set", "SKIP")
        return
    good = MOCK + "/automation.json"

    def poll(url=None, pw=None, settle=15):
        if url is not None:
            set_macro(api, host, "{$ELASTICPRO.URL}", url)
        if pw is not None:
            set_macro(api, host, "{$ELASTICPRO.PASSWORD}", pw, 1)
        time.sleep(settle)  # configuration cache, so the check uses the new macro values
        was = items_of(api, host)["elasticpro.state"]
        check_now(api, master)
        # A new value moves lastclock; a failed check moves only state/error — and an item that was
        # already failing the same way moves nothing, which is why this can only time out to "same".
        return wait(lambda: (lambda it: it if it["lastclock"] != was["lastclock"]
                             or (it["state"], it["error"]) != (was["state"], was["error"]) else None)(
            items_of(api, host)["elasticpro.state"]), 45) or items_of(api, host)["elasticpro.state"]

    set_macro(api, host, "{$ELASTICPRO.USER}", MOCK_USER)
    set_macro(api, host, "{$ELASTICPRO.STALE}", "2m")
    st = poll(good, MOCK_PASSWORD)
    check(tname, "{$ELASTICPRO.URL}+USER+PASSWORD consumed: document fetched", "0", st["state"])
    its = items_of(api, host)
    want = {"elasticpro.ok": "0", "elasticpro.proposed": "3", "elasticpro.held": "1", "elasticpro.freed": "5368709120",
            "elasticpro.errors": "1", "elasticpro.unreachable": "1"}
    for k, v in want.items():
        check(tname, f"dependent {k}", v, its.get(k, {}).get("lastvalue"))
    ep = int(its.get("elasticpro.epoch", {}).get("lastvalue") or 0)
    got_at = int(its.get("elasticpro.epoch", {}).get("lastclock") or 0)  # the server's clock, not ours
    check(tname, "dependent elasticpro.epoch is the document time", True, abs(ep - got_at) < 120, None)
    check(tname, "dependent elasticpro.problems holds the problem list", True,
          "ECONNREFUSED" in (its.get("elasticpro.problems", {}).get("lastvalue") or ""), None)
    # LLD runs on that value; the discovered items fill on the next one.
    disc = wait(lambda: (lambda its: its if "elasticpro.cluster.reachable[cluster-b]" in its
                         and "elasticpro.rule.detail[cluster-a,readonly]" in its else None)(items_of(api, host)), 90)
    check(tname, "LLD discovered both clusters and both rules", True, bool(disc), None)
    time.sleep(15)
    poll()
    its = items_of(api, host)
    for k, v in {"elasticpro.cluster.reachable[cluster-a]": "1", "elasticpro.cluster.reachable[cluster-b]": "0",
                 "elasticpro.cluster.health[cluster-b]": "red", "elasticpro.cluster.proposed[cluster-a]": "3",
                 "elasticpro.cluster.held[cluster-a]": "1", "elasticpro.rule.proposed[cluster-a,retention]": "3",
                 "elasticpro.rule.held[cluster-a,retention]": "1",
                 "elasticpro.rule.detail[cluster-a,readonly]": "rule threw: bad pattern"}.items():
        check(tname, f"discovered {k}", v, its.get(k, {}).get("lastvalue"))
    nodisc = [k for k, it in its.items() if it["flags"] == "4" and it["state"] == "1"]
    check(tname, "no discovered item unsupported", [], nodisc)
    expect_trigger(api, tname, host, "unreachable total > 0 -> problem", "elasticpro.unreachable)", "PROBLEM")
    expect_trigger(api, tname, host, "rule errors > 0 -> problem", "elasticpro.errors)", "PROBLEM")
    expect_trigger(api, tname, host, "held back > 0 -> problem", "elasticpro.held)", "PROBLEM")
    expect_trigger(api, tname, host, "Cluster B unreachable (prototype) -> problem", "reachable[cluster-b]", "PROBLEM")
    expect_trigger(api, tname, host, "Cluster B red (prototype) -> problem", "health[cluster-b]", "PROBLEM")
    expect_trigger(api, tname, host, "Cluster A reachable (prototype) -> clear", "reachable[cluster-a]", "OK")
    expect_trigger(api, tname, host, "fresh document -> scrape-stopped trigger clear", "elasticpro.epoch", "OK")

    st = poll(pw="")
    check(tname, "empty {$ELASTICPRO.PASSWORD} -> master unsupported, 401 named", True,
          st["state"] == "1" and "401" in st["error"], None)
    st = poll(pw=MOCK_PASSWORD)
    check(tname, "password restored -> master collects again", "0", st["state"])
    st = poll(MOCK + "/missing.json")
    check(tname, "wrong {$ELASTICPRO.URL} (404) -> master unsupported, 404 named", True,
          st["state"] == "1" and "404" in st["error"], None)
    st = poll("http://ztest-no-such-host.invalid/automation.json")
    check(tname, "unresolvable {$ELASTICPRO.URL} -> master unsupported", "1", st["state"])
    st = poll(good + "?age=600")
    expect_trigger(api, tname, host, "{$ELASTICPRO.STALE}=2m consumed: 10-minute-old document -> problem",
                   "elasticpro.epoch", "PROBLEM")
    st = poll(good + "?age=60")
    expect_trigger(api, tname, host, "1-minute-old document (inside 2m) -> clear", "elasticpro.epoch", "OK")
    if args.fast:
        row(tname, "endpoint down: scrape-stopped fires with no new values", "PROBLEM", "skipped (--fast)", "SKIP")
        return
    st = poll("http://ztest-no-such-host.invalid/automation.json")
    expect_trigger(api, tname, host, "endpoint down: scrape-stopped fires with no new values (within 2m + slack)",
                   "elasticpro.epoch", "PROBLEM", timeout=200)


def s_cluster(api, tname, host, tplid, items):
    # The vault decision: the ES password arrives as a Vault reference. A host-level VAULT macro
    # must be accepted over the template's TEXT macro and come back readable as its path.
    try:
        set_macro(api, host, "{$ELASTICSEARCH.PASSWORD}", "secret/elasticpro/ztest:password", 2)
        got = api("usermacro.get", hostids=host, filter={"macro": "{$ELASTICSEARCH.PASSWORD}"}, output=["value", "type"])
        check(tname, "{$ELASTICSEARCH.PASSWORD} as a host VAULT macro: path readable by the API", True,
              bool(got) and got[0]["type"] == "2" and got[0].get("value") == "secret/elasticpro/ztest:password", None)
    except ApiError as e:
        row(tname, "{$ELASTICSEARCH.PASSWORD} as a host VAULT macro", "accepted", e.msg, "FAIL")
    for mtype, label in ((1, "SECRET_TEXT"),):
        set_macro(api, host, "{$ZTEST.SECRET.PROBE}", "x", mtype)
        got = api("usermacro.get", hostids=host, filter={"macro": "{$ZTEST.SECRET.PROBE}"}, output=["value", "type"])
        row(tname, f"a {label} macro's value through the API", "unreadable",
            "unreadable" if got and "value" not in got[0] else "READABLE", "PASS" if got and "value" not in got[0] else "FAIL")


def s_master(api, tname, host, tplid, items):
    # The "requested" items are calculated from one macro each: set one, it must arrive.
    req = [k for k, it in items.items() if it["type"] == "15" and ".requested" in k]
    if not req:
        row(tname, "requested items", "present", "none found", "FAIL")
        return
    key = next((k for k in req if k.startswith("ep.role.cpu.requested[")), req[0])
    formula = api("item.get", itemids=items[key]["itemid"], output=["params"])[0]["params"]
    macro = (re.findall(r"\{\$[A-Z0-9_.]+\}", formula) or [None])[0]
    if not macro:
        row(tname, f"{key} formula names a macro", "a macro", formula, "FAIL")
        return
    set_macro(api, host, macro, "12")
    time.sleep(15)
    check_now(api, items[key]["itemid"])
    got = wait(lambda: (lambda v: v if v in ("12", "12.0", "12.0000") else None)(
        items_of(api, host)[key]["lastvalue"]), 90) or items_of(api, host)[key]["lastvalue"]
    check(tname, f"{macro}=12 consumed by {key}", True, str(got).startswith("12"), None)


def s_gate(api, tname, host, tplid, items):
    """The request-gate template: one TEXT trapper master that ElasticPro's core fills by
    history.push once a minute (zbx_push.rs). Fed here with a payload of the core's shape —
    including the fields the core leaves OUT when it cannot measure them (a wait p95 with no
    grants, a memory limit that does not exist), which must be discarded, not stored as 0."""
    raw = items["elasticpro.gate.raw"]["itemid"]
    set_macro(api, host, "{$ELASTICPRO.GATE.WINDOW}", "2m")
    time.sleep(15)

    def cls(t, a, b, c):
        return {"total": t, "interactive": a, "prompted": b, "background": c}

    def payload(queued, refused, p95i, paused, inflight_pused):
        return {"schema": 1, "version": "ztest-1", "clock": 0, "window_s": 60,
                "inflight": cls(5, 2, 1, 2), "queued": dict(cls(queued, queued, 0, 0), oldest_wait_ms=2500),
                "granted": cls(40, 20, 5, 15), "wait_p95_ms": {"interactive": p95i},
                "refused": {"overloaded": refused, "queue_timeout": 0, "total": refused}, "too_large": 0,
                "shared": 3, "cached": 7, "requests_per_min": 44,
                "limits": {"global": 16, "queue_max": 64, "queue_wait_ms": 30000},
                "policy": {"mode": "hybrid", "paused_all": paused, "paused_clusters": 1},
                "memory": {"cache_used_bytes": 1, "cache_budget_bytes": 10, "inflight_used_bytes": 95,
                           "inflight_budget_bytes": 100, "read_cache_used_bytes": 1, "read_cache_budget_bytes": 4,
                           "cache_pused": 10.0, "inflight_pused": inflight_pused},
                "clusters": 3}

    for _ in range(3):  # three pushes, every threshold crossed
        ok, bad = push(api, [{"itemid": raw, "value": json.dumps(payload(25, 30, 20000, 1, 95.0))}])
        check(tname, "history.push of a core-shaped payload accepted", True, ok, None)
        time.sleep(15)
    its = items_of(api, host)
    absent = {"elasticpro.gate.memory[limit]", "elasticpro.gate.wait.p95[prompted]", "elasticpro.gate.wait.p95[background]"}
    deps = {k: it for k, it in its.items() if k != "elasticpro.gate.raw"}
    check(tname, "no dependent item unsupported", [], sorted(k for k, it in deps.items() if it["state"] == "1"))
    check(tname, "every dependent got a value, except the fields the core omits",
          [], sorted(k for k, it in deps.items() if it["lastclock"] == "0" and k not in absent))
    check(tname, "omitted fields discarded, not stored as 0 (unknown is not zero)",
          [], sorted(k for k in absent if deps.get(k, {}).get("lastclock") not in ("0", None)))
    for k, v in {"elasticpro.gate.policy.mode": "2", "elasticpro.gate.paused": "1", "elasticpro.gate.queued.oldest": "2.5",
                 "elasticpro.gate.wait.p95[interactive]": "20", "elasticpro.gate.version": "ztest-1",
                 "elasticpro.gate.queued[total]": "25"}.items():
        check(tname, f"dependent {k} (units/JS/valuemap)", v, deps.get(k, {}).get("lastvalue"))
    expect_trigger(api, tname, host, "{$...GATE.WINDOW}=2m + QUEUED.CRIT: queueing critical -> problem", "queued[total]", "PROBLEM", priority=3)
    expect_trigger(api, tname, host, "queueing warning held back by its critical (dependency)", "queued[total]", "OK", priority=2)
    expect_trigger(api, tname, host, "wait p95 critical -> problem", "wait.p95[interactive]", "PROBLEM", priority=3)
    expect_trigger(api, tname, host, "wait p95 warning held back by its critical", "wait.p95[interactive]", "OK", priority=2)
    expect_trigger(api, tname, host, "refused over REFUSED.CRIT in the window -> problem", "refused[total]", "PROBLEM", priority=3)
    expect_trigger(api, tname, host, "in-flight memory over INFLIGHT.PUSED.WARN -> problem", "inflight.pused", "PROBLEM")
    expect_trigger(api, tname, host, "figures arriving -> nodata trigger clear", "elasticpro.gate.raw", "OK")
    if args.fast:
        row(tname, "{$ELASTICPRO.GATE.NODATA} nodata", "PROBLEM", "skipped (--fast)", "SKIP")
        return
    set_macro(api, host, "{$ELASTICPRO.GATE.NODATA}", "60s")
    time.sleep(30)
    push(api, [{"itemid": raw, "value": json.dumps(payload(0, 0, 100, 0, 10.0))}])
    expect_trigger(api, tname, host, "{$...GATE.NODATA}=60s consumed: no push for 60s -> problem",
                   "elasticpro.gate.raw", "PROBLEM", timeout=240)


SCENARIOS = {"ElasticPro request gate by Zabbix trapper": s_gate, "ElasticPro alerts": s_alerts, "ElasticPro client plan": s_plan, "ElasticPro log delay": s_delay,
             "ElasticPro automation": s_automation, "Elasticsearch Cluster by HTTP EP": s_cluster,
             "ElasticPro client master": s_master}


# ------------------------------------------------------------------------------------ main

def cleanup(api):
    hosts = api("host.get", search={"host": PREFIX}, startSearch=True, output=["hostid", "host"])
    hosts = [h for h in hosts if h["host"].startswith(PREFIX)]
    if hosts:
        api.raw("host.delete", [h["hostid"] for h in hosts])
    tpls = [t for t in api("template.get", search={"host": PREFIX}, startSearch=True, output=["templateid", "host"])
            if t["host"].startswith(PREFIX)]
    if tpls:
        api.raw("template.delete", [t["templateid"] for t in tpls])
    groups = [g for g in api("hostgroup.get", filter={"name": GROUP}, output=["groupid"])]
    if groups:
        api.raw("hostgroup.delete", [g["groupid"] for g in groups])
    print(f"cleanup: removed {len(hosts)} host(s), {len(tpls)} template copy(ies), {len(groups)} group(s) named {PREFIX}*")


def main():
    files = [os.path.join(TEMPLATES_DIR, f) for f in default_files()] + args.extra
    plan = []
    for f in files:
        text, fmt, names = load(f)
        if args.only and not any(o in n for o in args.only for n in names):
            continue
        plan.append((f, text, fmt, names))
    if args.dry_run:
        print(f"dry run — would test {len(plan)} export(s) as copies prefixed {PREFIX!r}, in host group {GROUP!r}:")
        for f, text, fmt, names in plan:
            print(f"  {os.path.basename(f)} ({fmt}): " + ", ".join(PREFIX + n for n in names)
                  + ("" if names[0] in SCENARIOS else "  [generic checks only]"))
        print("HTTP checks: " + (f"mock at {MOCK}" if MOCK else "SKIP (EP_TEST_MOCK_URL not set)"))
        return 0
    api = Api()
    print(f"Zabbix {api.version} at {api.url}")
    if not str(api.version).startswith("7.0."):
        print("note: these templates are written for 7.0")
    if args.cleanup:
        cleanup(api)
        return 0
    gid = (api("hostgroup.get", filter={"name": GROUP}, output=["groupid"]) or [{}])[0].get("groupid") \
        or api("hostgroup.create", name=GROUP)["groupids"][0]
    try:
        for n, (f, text, fmt, names) in enumerate(plan, 1):
            src = copy_of(text, names)
            tname = names[0]
            print(f"\n== {tname}  ({os.path.basename(f)})")
            try:
                api("configuration.import", format=fmt, source=src, rules=RULES)
                row(tname, "import (copy)", "ok", "ok", "PASS")
            except ApiError as e:
                row(tname, "import (copy)", "ok", e.msg, "FAIL")
                continue
            tpl = api("template.get", filter={"host": PREFIX + tname}, output=["templateid"])
            tplid = tpl[0]["templateid"]
            # importcompare is informative only: it compares the source with an export, and the two
            # spell the same template differently (defaults omitted, CRLF, triggers nested under
            # their item). The proof of idempotence is the export comparison below.
            real = real_changes(api("configuration.importcompare", format=fmt, source=src, rules=RULES))
            row(tname, "importcompare after import (informative)", "", "; ".join(real[:4]) or "no change", "INFO")
            before = export_of(api, tplid)
            try:
                api("configuration.import", format=fmt, source=src, rules=RULES)
                row(tname, "second import", "ok", "ok", "PASS")
            except ApiError as e:
                row(tname, "second import", "ok", e.msg, "FAIL")
            check(tname, "export identical before and after the second import (idempotent)", True,
                  before == export_of(api, tplid), None)
            hostname = f"{PREFIX}host-{n}"
            old = api("host.get", filter={"host": hostname}, output=["hostid"])
            if old:
                api.raw("host.delete", [old[0]["hostid"]])
            host = api("host.create", host=hostname, groups=[{"groupid": gid}],
                       interfaces=[{"type": 1, "main": 1, "useip": 1, "ip": "192.0.2.1", "dns": "", "port": "10050"}],
                       templates=[{"templateid": tplid}])["hostids"][0]
            row(tname, "linked to a test host", "ok", hostname, "PASS")
            use = macro_usage(api, tplid)
            # template.get selectMacros, not usermacro.get templateids: on 7.0.31 the latter returned
            # none of the master template's 192 macros and only some of the others'.
            tmacros = api("template.get", templateids=tplid, output=["templateid"],
                          selectMacros=["macro", "value", "type"])[0]["macros"]
            for m in tmacros:
                where = sorted(use.get(m["macro"], []))
                row(tname, f"macro {m['macro']} consumed by", "", ", ".join(where) or "nothing on this template",
                    "INFO")
                if m["type"] == "1":
                    check(tname, f"secret {m['macro']} ships empty", "", m.get("value", ""))
            undefined = sorted(set(use) - {m["macro"] for m in tmacros})
            if undefined:
                row(tname, "macros used but defined elsewhere (host/global/other template)", "", ", ".join(undefined), "INFO")
            # Every macro to a test value, then the scenario sets the ones it tests on purpose.
            if names[0] not in SCENARIOS:
                for m in tmacros:
                    set_macro(api, host, m["macro"], test_value(m["macro"], m["type"], m.get("value")), int(m["type"]))
                hm = {x["macro"] for x in api("usermacro.get", hostids=host, output=["macro"])}
                check(tname, "every template macro overridable at host level", [], sorted({m["macro"] for m in tmacros} - hm))
            time.sleep(12)
            items = items_of(api, host)
            trigs = triggers_of(api, host)
            row(tname, "objects on the host", "", f"{len(items)} items, {len(trigs)} triggers", "INFO")
            try:
                if names[0] in SCENARIOS:
                    SCENARIOS[names[0]](api, tname, host, tplid, items)
                else:
                    scenario_trap(api, tname, host, items)
                generic(api, tname, host, tplid, items_of(api, host), triggers_of(api, host))
            except ApiError as e:
                row(tname, "scenario", "ran", e.msg, "FAIL")
    finally:
        if not args.keep:
            cleanup(api)
    return 0


rc = main()
fails = [r for r in ROWS if r["result"] == "FAIL"]
if ROWS:
    print(f"\n{len(ROWS)} rows: {sum(r['result'] == 'PASS' for r in ROWS)} pass, {len(fails)} fail, "
          f"{sum(r['result'] == 'SKIP' for r in ROWS)} skip, {sum(r['result'] == 'INFO' for r in ROWS)} info")
if args.json:
    with open(args.json, "w") as fh:
        json.dump(ROWS, fh, indent=1)
sys.exit(rc or (1 if fails else 0))
