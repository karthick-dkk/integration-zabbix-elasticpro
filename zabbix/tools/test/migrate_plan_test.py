#!/usr/bin/env python3
"""Unit tests for migrate-templates.py's planning, its write guard and its export reader.

No Zabbix needed: the snapshots below have the shape snapshot_host() records from the API
(item.get / discoveryrule.get / trigger.get output plus the resolved origin template). The
behaviour the plans rely on — unlink without clear keeps items, a renamed host item is adopted
by the link, a value-type change hides stored values — was checked on Zabbix 7.0.31 and is
re-checked live by migrate_live_test.py.

    python3 -m unittest zabbix/tools/test/migrate_plan_test.py
"""
import glob, importlib.util, os, unittest

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location("migrate", os.path.join(HERE, "..", "migrate-templates.py"))
mig = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mig)

OLD, NEW, OTHER = "100", "200", "300"


def read(path):
    with open(path, encoding="utf-8") as fh:
        return fh.read()


def item(iid, key, origin=None, vt="3", flags="0", **kw):
    d = {"itemid": iid, "key_": key, "name": key, "templateid": "9" + iid if origin else "0", "value_type": vt,
         "flags": flags, "type": "2", "status": "0", "origin": origin}
    d.update(kw)
    return d


def snap(items, rules=(), protos=(), triggers=(), host_macros=(), linked=(OLD,)):
    return {"host": {"hostid": "1", "host": "ztest-h", "parentTemplates": [{"templateid": t, "host": t} for t in linked],
                     "macros": list(host_macros), "hostgroups": []},
            "items": list(items), "rules": list(rules), "prototypes": list(protos), "triggers": list(triggers),
            "counts": {i["itemid"]: 5 for i in items}}


GEN = {"name": "t", "mode": "replace", "keys": {"old.exact": "new.exact"}, "key_prefixes": [("oldp.", "newp.")],
       "macros": {}, "macro_prefixes": [("{$OLDM.", "{$NEWM.")]}
TARGET = {"templates": [{"templateid": NEW, "host": "New"}], "missing": [],
          "keys": {"same.key": "3", "newp.a": "3", "newp.f": "3", "new.exact": "0", "newp.taken": "3", "own.key": "3",
                   "own.float": "3", "third.key": "3"},
          "lld": {"new.lld"}, "macros": {"{$NEWM.PASS}": ("", "1"), "{$SHARED}": ("x", "0")}}
OLD_TPL = {OLD: {"host": "Old", "macros": {"{$URL}": ("http://old", "0"), "{$SECRET}": (None, "1"),
                                          "{$SHARED}": ("x", "0"), "{$UNUSED}": ("u", "0")}}}


def by_key(plan):
    return {r["key"]: r for r in plan["items"]}


class ReplacePlan(unittest.TestCase):
    def test_actions(self):
        s = snap([item("1", "same.key", OLD), item("2", "oldp.a", OLD), item("3", "oldp.f", OLD, vt="0"),
                  item("4", "old.exact", OLD, vt="0"), item("5", "kept.key", OLD, url="{$URL}/x"),
                  item("6", "oldp.taken", OLD), item("7", "newp.taken"), item("8", "disc[1]", flags="4"),
                  item("9", "mine.key")])
        p = mig.plan_replace(s, GEN, OLD_TPL, TARGET)
        k = by_key(p)
        self.assertEqual(k["same.key"]["action"], "ADOPT")
        self.assertEqual((k["oldp.a"]["action"], k["oldp.a"]["new_key"]), ("RENAME", "newp.a"))
        self.assertEqual(k["oldp.f"]["action"], "KEEP")
        self.assertIn("hide the stored values", k["oldp.f"]["why"])
        self.assertEqual((k["old.exact"]["action"], k["old.exact"]["new_key"]), ("RENAME", "new.exact"))
        self.assertEqual(k["kept.key"]["action"], "KEEP")
        self.assertEqual(k["oldp.taken"]["action"], "KEEP")          # host already has newp.taken
        self.assertEqual(k["newp.taken"]["action"], "ADOPT")         # the host's own item, same key
        self.assertEqual(k["disc[1]"]["action"], "KEEP")
        self.assertEqual(k["mine.key"]["action"], "UNTOUCHED")
        self.assertEqual(p["refused"], [])
        self.assertEqual(p["unlink"], [OLD])
        self.assertEqual(p["link"], [NEW])

    def test_macros_preserved_for_kept_items_secret_by_hand(self):
        s = snap([item("5", "kept.key", OLD, url="{$URL}/x", password="{$SECRET}", username="{$SHARED}")],
                 host_macros=[{"macro": "{$OLDM.PASS}", "type": "1"}, {"macro": "{$OLDM.HOST}", "value": "h", "type": "0"}])
        p = mig.plan_replace(s, GEN, OLD_TPL, TARGET)
        copied = {m["macro"]: m for m in p["macros"]}
        self.assertEqual(copied["{$URL}"]["value"], "http://old")    # only the old template had it
        self.assertNotIn("{$SHARED}", copied)                         # the new template resolves it the same
        self.assertNotIn("{$UNUSED}", copied)                         # nothing kept uses it
        self.assertEqual(copied["{$NEWM.HOST}"]["value"], "h")        # host setting carried to its new name
        manual = " ".join(p["manual"])
        self.assertIn("{$SECRET}", manual)
        self.assertIn("{$NEWM.PASS}", manual)                         # a secret is never copied, only listed

    def test_refusals(self):
        cases = {
            "value type": snap([item("1", "own.float", vt="0")]),
            "third template": snap([item("1", "third.key", OTHER)], linked=(OLD, OTHER)),
            "discovery rule": snap([], rules=[{"itemid": "50", "key_": "new.lld", "templateid": "950", "origin": OLD}]),
            "not linked": snap([], linked=()),
        }
        for why, s in cases.items():
            with self.subTest(why):
                self.assertTrue(mig.plan_replace(s, GEN, OLD_TPL, TARGET)["refused"])
        missing = dict(TARGET, missing=["New"])
        self.assertTrue(mig.plan_replace(snap([]), GEN, OLD_TPL, missing)["refused"])

    def test_no_unlink_means_no_moves(self):
        s = snap([item("2", "oldp.a", OLD)])
        p = mig.plan_replace(s, GEN, OLD_TPL, TARGET, unlink=False)
        self.assertTrue(any("unlinked" in r for r in p["refused"]))
        s = snap([item("5", "kept.key", OLD)])
        p = mig.plan_replace(s, GEN, OLD_TPL, TARGET, unlink=False)
        self.assertEqual((p["refused"], p["unlink"], p["link"]), ([], [], [NEW]))   # purely additive

    def test_duplicate_trigger_candidates(self):
        s = snap([item("1", "same.key", OLD), item("5", "kept.key", OLD)],
                 triggers=[{"triggerid": "t1", "description": "moves", "templateid": "9t1", "status": "0", "origin": OLD,
                            "itemids": ["1"], "expression": "", "recovery_expression": ""},
                           {"triggerid": "t2", "description": "stays", "templateid": "9t2", "status": "0", "origin": OLD,
                            "itemids": ["5"], "expression": "", "recovery_expression": ""}])
        p = mig.plan_replace(s, GEN, OLD_TPL, TARGET)
        self.assertEqual([t["triggerid"] for t in p["triggers"]], ["t1"])

    def test_two_old_keys_onto_one_new(self):
        g = dict(GEN, keys={"a.one": "newp.a", "a.two": "newp.a"})
        p = mig.plan_replace(snap([item("1", "a.one", OLD), item("2", "a.two", OLD)]), g, OLD_TPL, TARGET)
        self.assertEqual(sorted(r["action"] for r in p["items"]), ["KEEP", "RENAME"])


class InPlacePlan(unittest.TestCase):
    def test_actions(self):
        tpl = {"templateid": "10", "host": "ElasticPro client plan",
               "macros": [{"macro": "{$OLDM.STALE}", "value": "30m", "type": "0"}, {"macro": "{$OLDM.PW}", "type": "1"}]}
        titems = [{"itemid": "1", "key_": "oldp.a", "value_type": "3", "templateid": "0"},
                  {"itemid": "2", "key_": "oldp.f", "value_type": "0", "templateid": "0"},
                  {"itemid": "3", "key_": "newp.taken", "value_type": "3", "templateid": "0"},
                  {"itemid": "4", "key_": "oldp.taken", "value_type": "3", "templateid": "0"},
                  {"itemid": "5", "key_": "oldp.hostdup", "value_type": "3", "templateid": "0"},
                  {"itemid": "6", "key_": "oldp.nowhere", "value_type": "3", "templateid": "0"}]
        target = {"items": {"newp.a": "3", "newp.f": "3", "newp.taken": "3", "newp.hostdup": "3"}, "prototypes": {}, "lld": set()}
        p = mig.plan_in_place(tpl, titems, [], [], target, dict(GEN, mode="in-place"), [("ztest-h", "newp.hostdup")])
        k = {r["key"]: r for r in p["rows"]}
        self.assertEqual(k["oldp.a"]["action"], "RENAME")
        self.assertEqual(k["oldp.f"]["action"], "KEEP")
        self.assertEqual(k["newp.taken"]["action"], "CURRENT")
        self.assertIn("series stay separate", k["oldp.taken"]["why"])
        self.assertIn("ztest-h", k["oldp.hostdup"]["why"])
        self.assertEqual(k["oldp.nowhere"]["action"], "KEEP")
        self.assertEqual([m["macro"] for m in p["macros"]], ["{$NEWM.STALE}"])
        self.assertIn("{$NEWM.PW}", " ".join(p["manual"]))


class Guard(unittest.TestCase):
    def setUp(self):
        self.api = mig.Api.__new__(mig.Api)
        self.api.commit, self.api.writes, self.api.auth, self.api.url, self.api.ctx = False, [], "x", "http://127.0.0.1:9", None

    def test_dry_run_cannot_write(self):
        with self.assertRaises(mig.Refused):
            self.api.call("item.update", {"itemid": "1", "key_": "x"})

    def test_clear_is_refused_even_on_commit(self):
        self.api.commit = True
        for params in ({"hostids": ["1"], "templateids_clear": ["2"]}, {"hostids": ["1"], "templates_clear": [{"templateid": "2"}]}):
            with self.assertRaises(mig.Refused):
                self.api.call("host.massremove", params)
        with self.assertRaises(mig.Refused):
            self.api.call("configuration.import", {"format": "yaml", "source": "", "rules": {"items": {"deleteMissing": True}}})

    def test_unknown_methods_and_shapes(self):
        self.api.commit = True
        for m in ("item.delete", "host.delete", "history.clear", "host.update", "template.delete"):
            with self.assertRaises(mig.Refused):
                self.api.call(m, {})
        with self.assertRaises(mig.Refused):
            self.api.call("item.update", {"itemid": "1", "key_": "x", "status": 1})


class Exports(unittest.TestCase):
    def test_scanner_matches_the_templates(self):
        files = sorted(glob.glob(os.path.join(HERE, "..", "..", "templates", "*.yaml")))
        self.assertEqual(len(files), 4)
        for f in files:
            text = read(f)
            k = mig._keys_from_yaml_scan(text)
            n_keys = text.count("\n          key: ") + text.count("\n              key: ")
            self.assertEqual(len(k["items"]) + len(k["prototypes"]) + len(k["lld"]), n_keys, f)
        plan = mig._keys_from_yaml_scan(read([f for f in files if "client-plan" in f][0]))
        self.assertEqual(plan["items"]["elasticpro.plan[es.host]"], "1")
        self.assertEqual(plan["items"]["elasticpro.plan.epoch"], "3")
        auto = mig._keys_from_yaml_scan(read([f for f in files if f.endswith("template-elasticpro.yaml")][0]))
        self.assertIn("elasticpro.discovery.rules", auto["lld"])
        self.assertEqual(auto["prototypes"]["elasticpro.cluster.health[{#CLUSTER.ID}]"], "1")

    def test_scanner_agrees_with_pyyaml(self):
        try:
            import yaml
        except ImportError:
            self.skipTest("PyYAML not installed")
        for f in glob.glob(os.path.join(HERE, "..", "..", "templates", "*.yaml")):
            text = read(f)
            a, b = mig._keys_from_yaml_scan(text), mig._keys_from_doc(yaml.safe_load(text))
            self.assertEqual(a, b, f)

    def test_maps(self):
        self.assertEqual(mig.map_key("oldp.x[1]", GEN), "newp.x[1]")
        self.assertEqual(mig.map_key("old.exact", GEN), "new.exact")
        self.assertEqual(mig.map_key("other", GEN), "other")
        self.assertEqual(mig.map_macro("{$OLDM.A}", GEN), "{$NEWM.A}")
        self.assertEqual(mig.macro_refs("{$A} {$B:\"ctx\"} x"), {"{$A}", "{$B}"})

    def test_example_map_refuses_until_filled(self):
        with self.assertRaises(SystemExit) as e:
            mig.load_map(os.path.join(HERE, "..", "migrate-map.example.json"))
        self.assertIn("placeholder", str(e.exception))


if __name__ == "__main__":
    unittest.main()
