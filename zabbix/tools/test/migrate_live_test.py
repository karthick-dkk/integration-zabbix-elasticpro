#!/usr/bin/env python3
"""Live proof that migrate-templates.py keeps every stored value, against a real Zabbix 7.0.

    ZABBIX_URL=http://zabbix.example/zabbix ZABBIX_TOKEN=... python3 migrate_live_test.py [--keep]

Builds its own fixtures — every object named ztest-mig-* — and runs the real command line:

  replace   an "old" template (same key, a key that maps by prefix, a FLOAT item the new template
            has as UNSIGNED, an HTTP item using a macro only the old template defines, a trigger,
            a discovery rule of its own and one the new template also has, with a prototype) and
            a "new" one; a host on the old one with values pushed into it and a discovered item.
            Dry run (nothing may change), --commit --retire-old-triggers, a second dry run (nothing
            left to do), then --rollback --commit.
  in-place  a copy of the client plan template whose keys and macros carry an older prefix, with
            values; --commit --import of the current export onto it.

After each step it checks that every item id that existed still exists and holds at least as
many values as before, that renamed items were adopted, that the value-type mismatch was kept
apart, that the shared discovery rule was adopted (same rule id, templated again, its discovered
item still there with its values), and that nothing was deleted. Removes everything it made unless --keep (or --cleanup to
remove leftovers only). Never point it at production: it creates hosts and pushes values.
"""
import json, os, re, subprocess, sys, tempfile, time, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
TOOL = os.path.join(HERE, "..", "migrate-templates.py")
PLAN = os.path.join(HERE, "..", "..", "templates", "template-elasticpro-client-plan.yaml")
P = "ztest-mig-"
URL = os.environ.get("ZABBIX_URL", "").rstrip("/")
TOKEN = os.environ.get("ZABBIX_TOKEN", "")
if not URL or not TOKEN:
    sys.exit("set ZABBIX_URL and ZABBIX_TOKEN")
RESULTS = []


def api(method, params):
    body = json.dumps({"jsonrpc": "2.0", "method": method, "params": params, "id": 1}).encode()
    r = json.load(urllib.request.urlopen(urllib.request.Request(URL + "/api_jsonrpc.php", body, {
        "Content-Type": "application/json-rpc", "Authorization": "Bearer " + TOKEN}), timeout=120))
    if "error" in r:
        raise RuntimeError(f"{method}: {r['error'].get('data')}")
    return r["result"]


def check(name, ok, detail=""):
    RESULTS.append((name, bool(ok), str(detail)))
    print(f"  [{'ok  ' if ok else 'FAIL'}] {name}" + (f": {detail}" if detail else ""))


def u(seed):
    import hashlib
    h = hashlib.sha256((P + seed).encode()).hexdigest()
    return h[0:12] + "4" + h[13:16] + "89ab"[int(h[16], 16) % 4] + h[17:32]


R = {k: {"createMissing": True, "updateExisting": True} for k in
     ("template_groups", "templates", "items", "discoveryRules", "triggers", "graphs", "valueMaps")}


def imp(yaml):
    api("configuration.import", {"format": "yaml", "source": yaml, "rules": R})


def tid(name):
    t = api("template.get", {"filter": {"host": name}, "output": ["templateid"]})
    return t[0]["templateid"] if t else None


def run(*args):
    env = dict(os.environ)
    r = subprocess.run([sys.executable, TOOL, *args], capture_output=True, text=True, env=env, timeout=600)
    return r.returncode, r.stdout + r.stderr


def items(hostid):
    return {i["itemid"]: i for i in api("item.get", {"hostids": hostid, "output": ["itemid", "key_", "templateid", "value_type", "flags"]})}


def count(i):
    return int(api("history.get", {"history": int(i["value_type"]), "itemids": [i["itemid"]], "countOutput": True}))


def counts(hostid):
    return {iid: count(i) for iid, i in items(hostid).items() if i["value_type"] in "01234"}


def cleanup():
    for h in api("host.get", {"search": {"host": P}, "startSearch": True, "output": ["hostid"]}):
        api("host.delete", [h["hostid"]])
    for t in api("template.get", {"search": {"host": P}, "startSearch": True, "output": ["templateid"]}):
        api("template.delete", [t["templateid"]])
    for g in api("hostgroup.get", {"search": {"name": P}, "startSearch": True, "output": ["groupid"]}):
        api("hostgroup.delete", [g["groupid"]])


GROUPS = """  template_groups:
    - uuid: a4d66365396d4f7fb44b205013911a63
      name: Templates/Applications
"""
OLD_T = f"""zabbix_export:
  version: '7.0'
{GROUPS}  templates:
    - uuid: {u('old')}
      template: '{P}OLD'
      name: '{P}OLD'
      groups:
        - name: Templates/Applications
      macros:
        - macro: '{{$ZTEST.URL}}'
          value: 'http://127.0.0.1:9/ztest'
        - macro: '{{$ZTEST.SECRET}}'
          type: SECRET_TEXT
          value: ''
      items:
        - uuid: {u('o1')}
          name: 'same key'
          type: TRAP
          key: ztest.same
        - uuid: {u('o2')}
          name: 'maps by prefix'
          type: TRAP
          key: ztestold.a
        - uuid: {u('o3')}
          name: 'float here, unsigned there'
          type: TRAP
          key: ztestold.f
          value_type: FLOAT
        - uuid: {u('o4')}
          name: 'kept, uses a macro of the old template'
          type: HTTP_AGENT
          key: ztestold.http
          delay: 1h
          value_type: TEXT
          url: '{{$ZTEST.URL}}'
          authtype: BASIC
          username: zabbix
          password: '{{$ZTEST.SECRET}}'
          triggers:
            - uuid: {u('o4t')}
              expression: 'length(last(/{P}OLD/ztestold.http))>100'
              name: 'ztest kept trigger'
        - uuid: {u('o5')}
          name: 'kept trapper'
          type: TRAP
          key: ztestold.kept
      discovery_rules:
        - uuid: {u('o6')}
          name: 'old lld'
          type: TRAP
          key: ztestold.lld
        - uuid: {u('o7')}
          name: 'shared lld'
          type: TRAP
          key: ztest.lld
          item_prototypes:
            - uuid: {u('o7p')}
              name: 'discovered {{#X}}'
              type: TRAP
              key: 'ztest.lld.item[{{#X}}]'
  triggers:
    - uuid: {u('o1t')}
      expression: 'last(/{P}OLD/ztest.same)>100'
      name: 'ztest same key high (old)'
"""
NEW_T = f"""zabbix_export:
  version: '7.0'
{GROUPS}  templates:
    - uuid: {u('new')}
      template: '{P}NEW'
      name: '{P}NEW'
      groups:
        - name: Templates/Applications
      items:
        - uuid: {u('n1')}
          name: 'same key'
          type: TRAP
          key: ztest.same
        - uuid: {u('n2')}
          name: 'a'
          type: TRAP
          key: ztestnew.a
        - uuid: {u('n3')}
          name: 'f'
          type: TRAP
          key: ztestnew.f
      discovery_rules:
        - uuid: {u('n7')}
          name: 'shared lld'
          type: TRAP
          key: ztest.lld
          item_prototypes:
            - uuid: {u('n7p')}
              name: 'discovered {{#X}}'
              type: TRAP
              key: 'ztest.lld.item[{{#X}}]'
  triggers:
    - uuid: {u('n1t')}
      expression: 'last(/{P}NEW/ztest.same)>100'
      name: 'ztest same key high (new)'
"""


def replace_scenario(tmp):
    print("\n== replace: old template -> new template on one host")
    imp(OLD_T)
    imp(NEW_T)
    gid = api("hostgroup.create", {"name": P + "group"})["groupids"][0]
    host = api("host.create", {"host": P + "host-1", "groups": [{"groupid": gid}], "templates": [{"templateid": tid(P + "OLD")}]})["hostids"][0]
    time.sleep(20)
    # One discovered item under the shared rule, so the move has a flags=4 item to keep.
    rule = {r["key_"]: r for r in api("discoveryrule.get", {"hostids": host, "output": ["itemid", "key_", "templateid"]})}["ztest.lld"]
    api("history.push", [{"itemid": rule["itemid"], "value": json.dumps([{"{#X}": "a"}])}])
    for _ in range(12):
        time.sleep(5)
        if any(i["key_"] == "ztest.lld.item[a]" for i in items(host).values()):
            break
    its = items(host)
    disc = next((i for i in its.values() if i["key_"] == "ztest.lld.item[a]"), None)
    check("fixture: the shared rule discovered ztest.lld.item[a]", disc is not None and disc["flags"] == "4")
    vals = [{"itemid": i["itemid"], "value": str(n)} for i in its.values()
            if i["key_"] in ("ztest.same", "ztestold.a", "ztestold.f", "ztestold.kept", "ztest.lld.item[a]")
            for n in range(5)]
    for v in vals:
        api("history.push", [v])
        time.sleep(1.1)  # one value per second per item: Zabbix keeps one value per item per clock+ns
    time.sleep(5)
    before, ids_before = counts(host), set(its)
    check("fixture: values stored before the migration", sum(before.values()) >= 20, before)
    m = {"generations": [{"name": "ztest-replace", "mode": "replace", "old_templates": [P + "OLD"], "new_templates": [P + "NEW"],
                          "unlink_old": True, "key_prefixes": [["ztestold.", "ztestnew."]]}]}
    path = os.path.join(tmp, "map.json")
    json.dump(m, open(path, "w"))
    journal = os.path.join(tmp, "journal.json")

    rc, out = run("--map", path)
    linked = [t["host"] for t in api("host.get", {"hostids": host, "selectParentTemplates": ["host"], "output": ["hostid"]})[0]["parentTemplates"]]
    check("dry run changes nothing", linked == [P + "OLD"] and {i["key_"] for i in items(host).values()} == {i["key_"] for i in its.values()}, linked)
    check("dry run plans RENAME ztestold.a -> ztestnew.a", "RENAME  ztestold.a -> ztestnew.a" in out)
    check("dry run plans ADOPT ztest.same", "ADOPT   ztest.same" in out)
    check("dry run keeps the FLOAT item apart", "hide the stored values" in out or "KEEP" in out)
    check("dry run copies {$ZTEST.URL} to the host", "MACRO   {$ZTEST.URL}" in out)
    check("dry run lists {$ZTEST.SECRET} for a person", "BY HAND {$ZTEST.SECRET}" in out)
    check("dry run plans ADOPT of the shared discovery rule", "ADOPT   discovery rule ztest.lld" in out)
    check("dry run keeps the old template's own rule", "KEEP    discovery rule ztestold.lld" in out)

    rc, out = run("--map", path, "--commit", "--retire-old-triggers", "--journal", journal)
    check("commit exit 0", rc == 0, out[-600:] if rc else "")
    after = items(host)
    by = {i["key_"]: i for i in after.values()}
    linked = [t["host"] for t in api("host.get", {"hostids": host, "selectParentTemplates": ["host"], "output": ["hostid"]})[0]["parentTemplates"]]
    check("old template unlinked, new linked", linked == [P + "NEW"], linked)
    check("no item lost (every old id still there)", ids_before <= set(after), sorted(ids_before - set(after)))
    ac = counts(host)
    fewer = {iid: (before[iid], ac.get(iid)) for iid in before if (ac.get(iid) or 0) < before[iid]}
    check("no item has fewer values than before", not fewer, fewer)
    same, a = by.get("ztest.same"), by.get("ztestnew.a")
    check("ztest.same adopted by the new template, same id", same and same["templateid"] != "0" and same["itemid"] in ids_before)
    check("ztestold.a renamed to ztestnew.a and adopted, same id", a and a["templateid"] != "0" and a["itemid"] in ids_before)
    check("its 5 values came with it", a and ac.get(a["itemid"]) == 5, a and ac.get(a["itemid"]))
    f_old, f_new = by.get("ztestold.f"), by.get("ztestnew.f")
    check("FLOAT item kept as a host item with its values", f_old and f_old["templateid"] == "0" and ac.get(f_old["itemid"]) == 5)
    check("new template's ztestnew.f created beside it (empty)", f_new and f_new["itemid"] not in ids_before)
    check("kept HTTP item still on the host", "ztestold.http" in by)
    rules = {r["key_"]: r for r in api("discoveryrule.get", {"hostids": host, "output": ["itemid", "key_", "templateid"]})}
    check("shared discovery rule adopted by the new template, same rule id", rules.get("ztest.lld", {}).get("itemid") == rule["itemid"]
          and rules["ztest.lld"]["templateid"] != "0", rules.get("ztest.lld"))
    check("the old template's own rule stays as a host rule", rules.get("ztestold.lld", {}).get("templateid") == "0", rules.get("ztestold.lld"))
    d_after = by.get("ztest.lld.item[a]")
    check("discovered item still there, same id, flags 4, every value it had", disc and d_after and d_after["itemid"] == disc["itemid"]
          and d_after["flags"] == "4" and ac.get(d_after["itemid"]) == before.get(disc["itemid"]), d_after and (before.get(disc["itemid"]), ac.get(d_after["itemid"])))
    protos = api("itemprototype.get", {"hostids": host, "output": ["key_", "templateid"]})
    check("the prototype on the host is the new template's (one, templated)", len(protos) == 1 and protos[0]["templateid"] != "0", protos)
    check("verify counted no discovery rule as not adopted", "discovery rules not adopted 0" in out)
    hm = {x["macro"]: x for x in api("usermacro.get", {"hostids": host, "output": ["macro", "value"]})}
    check("{$ZTEST.URL} copied to the host with the old value", hm.get("{$ZTEST.URL}", {}).get("value") == "http://127.0.0.1:9/ztest")
    check("secret not copied", "{$ZTEST.SECRET}" not in hm)
    trig = {t["description"]: t for t in api("trigger.get", {"hostids": host, "output": ["description", "status", "templateid"]})}
    check("old duplicate trigger disabled, not deleted", trig.get("ztest same key high (old)", {}).get("status") == "1")
    check("old trigger on a kept item left enabled", trig.get("ztest kept trigger", {}).get("status") == "0")
    check("journal written", os.path.exists(journal))

    rc, out = run("--map", path)
    check("second dry run: nothing left to do (idempotent)", "hosts linked to" in out and ": 0" in out.split("hosts linked to")[1].split("\n")[0], out.split("\n")[2:4])

    rc, out = run("--rollback", journal, "--commit")
    rb = items(host)
    rby = {i["key_"]: i for i in rb.values()}
    linked = [t["host"] for t in api("host.get", {"hostids": host, "selectParentTemplates": ["host"], "output": ["hostid"]})[0]["parentTemplates"]]
    check("rollback: old template linked again", linked == [P + "OLD"], linked)
    check("rollback: key back and re-adopted by the old template", rby.get("ztestold.a", {}).get("templateid", "0") != "0"
          and rby["ztestold.a"]["itemid"] == a["itemid"])
    rc2 = counts(host)
    fewer = {iid: (before[iid], rc2.get(iid)) for iid in before if (rc2.get(iid) or 0) < before[iid]}
    check("rollback: still no item with fewer values", not fewer, fewer)
    check("rollback: nothing deleted (old ids all present)", ids_before <= set(rb))
    rules = {r["key_"]: r for r in api("discoveryrule.get", {"hostids": host, "output": ["itemid", "key_", "templateid"]})}
    check("rollback: the shared rule re-adopted by the old template, same id", rules.get("ztest.lld", {}).get("itemid") == rule["itemid"]
          and rules["ztest.lld"]["templateid"] != "0", rules.get("ztest.lld"))


def in_place_scenario(tmp):
    print("\n== in-place: current template name, older keys")
    src = open(PLAN, encoding="utf-8").read()
    name = "ElasticPro client plan"
    old = src.replace(name, P + "PLAN").replace("elasticpro.plan", "ztestold.plan").replace("{$ELASTICPRO.", "{$ZTESTOLD.")
    old = re.sub(r"(uuid: )([0-9a-f]{32})", lambda m: m.group(1) + u("old" + m.group(2)), old)
    cur = src.replace(name, P + "PLAN")
    cur = re.sub(r"(uuid: )([0-9a-f]{32})", lambda m: m.group(1) + u("cur" + m.group(2)), cur)
    target = os.path.join(tmp, "current-plan.yaml")
    open(target, "w").write(cur)
    imp(old)
    gid = api("hostgroup.get", {"filter": {"name": P + "group"}, "output": ["groupid"]})
    gid = gid[0]["groupid"] if gid else api("hostgroup.create", {"name": P + "group"})["groupids"][0]
    host = api("host.create", {"host": P + "host-2", "groups": [{"groupid": gid}], "templates": [{"templateid": tid(P + "PLAN")}]})["hostids"][0]
    time.sleep(20)
    its = items(host)
    for i in its.values():
        v = "1" if i["value_type"] in ("0", "3") else "ztest"
        api("history.push", [{"itemid": i["itemid"], "value": v}])
    time.sleep(5)
    before, ids_before = counts(host), set(its)
    check("fixture: 18 items with a value each", len(its) == 18 and sum(before.values()) == 18, sum(before.values()))
    m = {"generations": [{"name": "ztest-in-place", "mode": "in-place", "templates": {P + "PLAN": target},
                          "key_prefixes": [["ztestold.", "elasticpro."]], "macro_prefixes": [["{$ZTESTOLD.", "{$ELASTICPRO."]]}]}
    path = os.path.join(tmp, "map2.json")
    json.dump(m, open(path, "w"))
    rc, out = run("--map", path)
    check("dry run plans 18 renames", out.count("RENAME  item") == 18, out.count("RENAME  item"))
    rc, out = run("--map", path, "--commit", "--import", "--journal", os.path.join(tmp, "journal2.json"))
    check("commit + import exit 0", rc == 0, out[-600:] if rc else "")
    after = items(host)
    check("same 18 item ids, no twins", set(after) == ids_before, len(after))
    check("every key is the current one", all(i["key_"].startswith("elasticpro.") for i in after.values()))
    ac = counts(host)
    check("every item kept its value", all(ac.get(iid, 0) >= n for iid, n in before.items()), {k: (before[k], ac.get(k)) for k in before if ac.get(k, 0) < before[k]})
    tm = {x["macro"] for x in api("template.get", {"filter": {"host": P + "PLAN"}, "selectMacros": ["macro"], "output": ["templateid"]})[0]["macros"]}
    check("macros carried to the current names on the template", {"{$ELASTICPRO.PLAN.STALE}", "{$ELASTICPRO.PLAN.LIVE_USED.WARN}"} <= tm, sorted(tm))
    titems = api("item.get", {"templateids": tid(P + "PLAN"), "output": ["key_"]})
    check("template has 18 items after the import (no empty duplicates)", len(titems) == 18, len(titems))
    trigs = api("trigger.get", {"templateids": tid(P + "PLAN"), "output": ["description"]})
    want = src.count("expression:")
    check(f"template has {want} triggers after the import (none duplicated, macros in parameters matched)",
          len(trigs) == want, sorted(t["description"] for t in trigs))
    graphs = api("graph.get", {"templateids": tid(P + "PLAN"), "output": ["name"]})
    check("graphs not duplicated", len(graphs) == 3, len(graphs))


def main():
    keep = "--keep" in sys.argv
    if "--cleanup" in sys.argv:
        cleanup()
        return 0
    cleanup()
    with tempfile.TemporaryDirectory() as tmp:
        try:
            replace_scenario(tmp)
            in_place_scenario(tmp)
        finally:
            if not keep:
                cleanup()
    bad = [r for r in RESULTS if not r[1]]
    print(f"\n{len(RESULTS)} checks: {len(RESULTS) - len(bad)} ok, {len(bad)} failed")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
