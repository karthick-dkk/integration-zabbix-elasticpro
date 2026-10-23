# Moving hosts to the ElasticPro templates without losing history

`tools/migrate-templates.py` links the ElasticPro templates to hosts that run older
Elasticsearch, Linux or earlier ElasticPro templates, and keeps every value those hosts have
stored. It is a dry run unless you add `--commit`, and it never deletes anything.

## How history survives

A stored value belongs to an **item id**, not to a key or a template. Three things Zabbix 7.0
does make a lossless move possible (each was checked on Zabbix 7.0.31, and
`tools/test/migrate_live_test.py` checks them again on yours):

1. **Unlink** keeps the template's items on the host as plain host items, same ids, all their
   history. **Unlink and clear** deletes them — the script cannot do that: `templates_clear`,
   `templateids_clear`, `deleteMissing` and every delete method are refused before a request is
   built.
2. A host item that is no longer inherited can have its **key renamed**; the id, the history and
   the trends stay.
3. **Linking** a template to a host that already has an item with the same key **adopts** that
   item: same id, now inherited from the template, history kept.

So the order per host is: copy the macros the remaining items need to host level → unlink the
old template (without clear) → rename the keys that map onto keys of the new template → link
the new templates (they adopt the renamed items) → verify that every item that existed still
exists and holds at least as many values as before.

A template that already has the **current name** but older item keys (an install from before a
key-prefix change) is upgraded **in place**: the keys are renamed on the template itself, which
renames them on every linked host at once, same ids. `--import` then gives the template's
objects the uuids of the current export and imports it, so the import updates the template
instead of failing with "Trigger … already exists" or creating empty second items.

## What it will not do

| Situation | What happens |
|---|---|
| The new key has a different value type (e.g. FLOAT → UNSIGNED) | The item is **kept apart** under its old key. Zabbix would adopt it and convert it, and the values stored under the old type would stop being visible (checked on 7.0.31: 1 value before, 0 after). The new template's item starts empty beside it. |
| The key already comes from a third template on the host | The host is **refused** — the link would fail. |
| A host item of your own has the new key but another value type | The host is **refused** — the link would convert it. |
| A discovery rule key is in both the old and the new template | The host is **refused**. |
| An old item has no equivalent in the new templates | It stays on the host as a host item, still collecting, with its history (the macros it uses are copied to the host first). |
| A secret macro is needed at host level or under a new name | Listed as **BY HAND**. Nobody can read a secret's value through the API, so it is never copied, guessed or blanked. |
| Old triggers | Kept. Old triggers on items that moved are listed as duplicate candidates; `--retire-old-triggers` **disables** (never deletes) the ones a new template trigger duplicates on the same items. |
| An old template linked through another template | Not touched (the plan says "not linked directly"). Unlink it from the parent template first. |

Refused hosts are left exactly as they were and the run exits 1 so a script notices.

## Before you start

- **Zabbix 7.0.** Written and tested for 7.0 LTS.
- **The ElasticPro templates are imported** — the four in `templates/` and the ones the Clients
  page writes (Clients → *Write master template*). A plan that needs a template Zabbix does not
  have says "import them first".
- **A backup.** Export the old templates and the hosts (Data collection → Templates / Hosts →
  Export) and take a database backup. Nothing here deletes, but a backup is what makes a bad
  morning short.
- **An API token** (Users → API tokens) for a user that can change the hosts and templates
  involved. In practice a **Super admin**, or an **Admin** whose user groups have *Read-write* on
  the host groups of the hosts **and** the template groups of the old and new templates. If the
  user role limits API methods, allow:
  `host.get host.massadd host.massremove template.get template.update item.get item.update
  itemprototype.get itemprototype.update discoveryrule.get discoveryrule.update trigger.get
  trigger.update triggerprototype.get triggerprototype.update graph.get graph.update
  graphprototype.get graphprototype.update valuemap.get valuemap.update templatedashboard.get
  templatedashboard.update usermacro.get usermacro.create history.get hostgroup.get` and, for
  `--import`, `configuration.import`. A dry run needs only the `.get` methods.
- **Python 3.8+.** Standard library only.

```bash
export ZABBIX_URL=https://zabbix.example.com      # the frontend, with its scheme
export ZABBIX_TOKEN=...                           # or ZABBIX_USER + ZABBIX_PASSWORD
cd zabbix/tools
```

## 1. Write the map

```bash
cp migrate-map.example.json migrate-map.json      # migrate-map.json is git-ignored
```

Replace every `<PLACEHOLDER>` with the names on your Zabbix and delete the generations you do
not have; the script refuses to run while a placeholder is left. Each generation is one of:

- **`in-place`** — `templates`: template name → the current export for it (path relative to
  the map). `key_prefixes` / `macro_prefixes`: `[old, new]` pairs.
- **`replace`** — `old_templates` → `new_templates`. `unlink_old: true` unlinks the old template
  (without clear); `false` links the new ones beside it and moves nothing, which is right when
  no key of the old template exists in the new ones. `keys` / `macros` map exact names,
  `key_prefixes` / `macro_prefixes` map a leading prefix.

The old names are on *Data collection → Templates* (and on each host's *Templates* tab). If an
older ElasticPro install is involved, the ElasticPro repository's
`deploy/zabbix/setup/zbx_rename.py` lists the prefixes earlier releases used.

## 2. Dry run

```bash
python3 migrate-templates.py --map migrate-map.json            # add --verbose for every item
```

Per host it prints what each item becomes, the macros it would create, what is left for you,
and the templates it would unlink and link:

```
== host cluster-01
   items: ADOPT 21, CURRENT 76, KEEP 41, RENAME 1
   RENAME  oldprefix.cluster.status -> es.cluster.status  [UNSIGNED, 20160 values]  renamed, then adopted by the link
   ADOPT   es.cluster.number_of_nodes  [UNSIGNED, 20160 values]  same key: the link adopts it
   KEEP    41 item(s) stay on the host as host items with their history
   MACRO   {$ES.INDEX.PATTERN} (text) at host level — kept items use it; it came from the old template
   BY HAND {$ELASTICSEARCH.PASSWORD}: secret on the old template, used by kept items — set it on the host by hand
   unlink (without clear): Old Elasticsearch template
   link: ElasticPro Elasticsearch via SSH jump host
```

| Action | Meaning |
|---|---|
| `ADOPT` | Same key in the new template: the link takes the item over, history and all. |
| `RENAME` | Old key mapped to a new one: renamed after the unlink, then adopted. |
| `KEEP` | Stays a host item with its history (reason given). |
| `CURRENT` | Already from a new template. |
| `UNTOUCHED` | Belongs to neither — left alone. |
| `REFUSE` | This host cannot be moved safely; see the REFUSED line. |

`--count-since-days N` counts only the last N days of values, which is faster on big hosts.

## 3. One host first

```bash
python3 migrate-templates.py --map migrate-map.json --host cluster-01 --commit --journal journal-cluster-01.json
```

Look at the `verify:` line it prints: items lost, items with fewer values, renamed items not
adopted — all three must be 0 — and the before/after value counts of every renamed item. Then
in the frontend: *Monitoring → Latest data* for the host shows the renamed items with their
old values; a graph of one of them runs straight through the moment of the migration.

Then set what the run listed as **BY HAND** (secrets) on the host.

## 4. The rest

```bash
python3 migrate-templates.py --map migrate-map.json --commit --retire-old-triggers --journal journal-all.json
```

Run it again afterwards: a dry run that finds nothing to do ("hosts linked to …: 0", or only
`CURRENT` objects) is the proof it is finished. Re-running is always safe.

For an in-place generation add `--import` so the template becomes the current export after its
keys are renamed:

```bash
python3 migrate-templates.py --map migrate-map.json --generation previous-key-prefix --commit --import
```

An import makes the template's own macro list match the export: macros that exist only under
an old name on the **template** are replaced by the export's. The script copies their values to
the new names first; macros set on hosts are never touched.

## 5. By hand, afterwards

- Secrets the run listed as **BY HAND**.
- Dashboards **of the old template** disappear from the host with the unlink (they belong to
  the template; no data is involved). Host and global dashboards are untouched.
- Actions, maintenance and services that name old-template triggers by name still match the old
  host triggers, which stay; point them at the new ones when you retire the old.
- Old host items that are no longer wanted can be disabled — or deleted, which deletes their
  history; that decision is yours and this script never makes it.
- Clocks: trapper values carry the sender's clock. If the ElasticPro scraper's machine and the
  Zabbix server disagree (the test VM was 23 minutes behind), the `nodata()` staleness triggers
  fire or stay quiet at the wrong time. Run NTP on both.

## Rollback

```bash
python3 migrate-templates.py --rollback journal-cluster-01.json            # what it would do
python3 migrate-templates.py --rollback journal-cluster-01.json --commit
```

It re-enables the triggers it disabled, unlinks the new templates (without clear), renames the
keys back and re-links the old templates, which adopt the items again — same ids, history kept.
Items a new template created that did not exist before stay behind as empty host items, and the
macros it created stay on the host; both are listed, and removing them is your call. An import
done with `--import` is not undone (re-import the old template export if you need it back).

Without a journal: link the old template again by hand (*Link new templates*); items whose key
was renamed need the key changed back first, which the journal records per item.

## Testing it on a test Zabbix

```bash
ZABBIX_URL=... ZABBIX_TOKEN=... python3 test/migrate_live_test.py      # ztest-mig-* fixtures, removed afterwards
python3 -m unittest test/migrate_plan_test.py                          # no Zabbix needed
```

The live test builds an old and a new template, pushes values into a host, and runs the real
command line through dry run, commit, a second dry run and rollback, then an in-place upgrade
with `--import`, checking item ids and value counts at every step. It passed 35/35 on Zabbix
7.0.31.
