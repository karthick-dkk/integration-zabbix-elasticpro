# ElasticPro — Zabbix modules

The Zabbix frontend side of ElasticPro: the Cluster Management page, the client master template it
writes, the report widgets, the embed module, and a copy of the four templates ElasticPro itself
feeds (automation, alerts, client plan, log delay). Those templates and the embed module are
generated and edited with the core, in `deploy/zabbix/` of the ElasticPro repository
(https://github.com/karthick-dkk/elasticpro); the copies here are stamped with the version they
came from, so a Zabbix can be set up from this project alone.

| Folder | What | Import |
|---|---|---|
| `clients-module/` | **Cluster Management** page (and **Roles**, **Settings**, **Backups**, under an *ElasticPro* section it makes itself): add, edit clients (DI / On-Prem) and their hosts; status (active, maintenance through Zabbix maintenance, disabled, decommissioned), setup checks, Test connection, hosts removed in Zabbix shown with Re-create; CSV bulk import with confirmation, three backups with restore, configurable roles; writes the master template and the Windows jump host (SSH) template | copy into `modules/` as `ep_clients`, data folder (see its README), then `master/import.py` |
| `master/` | **ElasticPro client master** — one host per client (made by the Clients page): requested / allocated / used per role and family, shortfall alerts, one dashboard per client. The template is written by the Clients page from its roles | `master/import.py` (set-up), then *Write master template* |
| `capacity-widget/` | **Client capacity** dashboard widget — every client in one table, coloured, with CSV / Excel export | copy into Zabbix's `modules/`, then `master/import.py` enables it and builds the dashboard |
| `resources-widget/` | **Client resources** dashboard widget — Requested / Allocated / Used per family or role, plus ES storage and log delay; expand a client into its servers; Type filter, Columns dialog, CSV / Excel export | copy into `modules/` as `ep_resources`, then `master/import.py` |
| `volume-widget/` | **Volume report** dashboard widget — the client plan per cluster, laid out as in ElasticPro (sections over columns), with CSV / Excel export. Columns come from the ElasticPro client plan template itself | copy into Zabbix's `modules/` as `ep_volume`, then `volume-widget/import.py` |
| `overview-widget/` | **Client overview** dashboard widget — one card per client: problems by severity, the metrics you choose (built-in ElasticPro figures or any item key on the client's servers, with trends and thresholds), used of allocated with a forecast, contract badge, servers by role and warnings; sections, order, density and click-through set on the widget | copy into `modules/` as `ep_overview`, then `master/import.py` |
| `shared/` | the widgets' export, Columns dialog and styles, and the PHP they share with the Clients page (data folder, settings, roles, column settings); `node sync-assets.mjs` copies them into each module | — |
| `embed-module/` | **ElasticPro** page — embeds the dashboard inside Zabbix and signs the person in through the pairing (one-time code, then SSO) | copy into `modules/` as `elasticpro`, enable, pair from ElasticPro's Config → Zabbix |
| `templates/` | the four templates ElasticPro feeds — automation, alerts, client plan, log delay — as shipped with the core version named in `templates/README.md` | *Data collection → Templates → Import*, or `zbx_phase34.py` from the ElasticPro repository |

**Settings.** The thresholds, decimals, size unit, forecast windows and client types the page and
all three widgets read live on **ElasticPro → Settings**, in `settings.json` in the data folder. A
dashboard widget can override its own thresholds and bar style; a client's master host overrides
the thresholds for itself. The order is always the host, then the widget, then that page, then what
ElasticPro ships — so a site that never opens it sees what it saw before. Changing a threshold does
not move the alerts until the master template is rewritten; the page says so and links to it.

Order on a fresh Zabbix: the cluster and Linux templates, then the ElasticPro repository's templates
(`deploy/zabbix/setup/zbx_phase34.py`: client plan, alerts, log delay), then
`master/import.py`, then *Write master template* on the Clients page.

The cluster and Linux templates are expected as **Elasticsearch Cluster by HTTP EP** and
**Linux by Zabbix agent -EP**. A Zabbix that has them under other names (its own, or the ones
an earlier release shipped) is pointed at them on *Roles → Template mapping*, which writes
`templates.json` in the data folder. All ten templates this suite names can be mapped there, and
the mapping governs everything that looks one up: the Clients page, all three report widgets, and
`master/import.py` (which reads the same file, or `EP_<SLOT>_TEMPLATE` when it runs from another
machine). The mapping table's **Used by** column says which of them each name reaches.

Tests (from this folder): `npm test`. `npm install` once beforehand gets `jsdom`, without which
the Columns dialog's own tests skip; everything else runs without it. The PHP suites run through a
`php:8.4-cli-alpine` container when there is no local `php`, and say so when there is neither.

## Versions and releases

**One version for the whole set.** Every module — `clients-module`, the five widgets and
`embed-module` — carries the same `x.y.z` in its `manifest.json`, and `CHANGELOG.md` has a
`## x.y.z` section saying what changed. The version is the modules' own: it is not ElasticPro's
version (for example, ElasticPro 0.3.20 ships modules 2.1.0). Zabbix shows it under *Administration → General
→ Modules*, and **ElasticPro → Settings** shows it with the build (see below).

**Any change to what a module ships bumps the version, one patch at a time** (2.1.0 → 2.1.1). Tests,
READMEs and tool scripts (`*.py`, `*.mjs`) are not shipped and need no bump. Two checks enforce it:

- `npm test` holds the manifests to one `x.y.z` and the CHANGELOG to a written section for it.
- `npm run version-check` compares with the latest `v*` tag: if a shipped file under a module or
  `shared/` changed since the tag and the version is still the tag's, it fails and names the files.
  With no tag yet it passes and says so. CI runs both on every pull request and push to `main`.

**To bump** (in the pull request that makes the change):

```bash
cd zabbix
npm run version -- 2.1.1          # sets every manifest, adds "## 2.1.1" to CHANGELOG.md with a placeholder
$EDITOR CHANGELOG.md              # replace the placeholder with what changed, for a person who runs Zabbix
npm run version -- --check        # fails while the placeholder is there, or the manifests disagree
```

`npm run version` with no argument prints the current version. A jump (2.1.0 → 2.3.0, or 3.0.0)
is refused unless `--allow-jump` is added; a version that is not higher is always refused.

**To release** (after the pull request is merged):

1. On `main`, at the commit that carries the version: `git tag v2.1.1 && git push origin v2.1.1`.
2. The `ci` workflow runs the tests, checks that the tag equals the manifests' version, and creates a
   GitHub release titled *ElasticPro Zabbix modules 2.1.1* whose notes are that version's CHANGELOG
   section. Nothing is built or attached: the release is a version and its notes.
3. ElasticPro ships the modules from a pinned commit, `deploy/zabbix/MODULES_REF` in its repository.
   Move it, in a pull request there, to the tagged commit and set its `# modules x.y.z` line to the
   same version; ElasticPro's build fails if the commit's manifests do not carry that version.

**Which build is installed.** ElasticPro's `tools/zabbix-modules-tarball.sh` writes a `BUILD` file
into every module folder of the archive it makes: `key=value` lines for `modules_version`,
`modules_commit`, `modules_commit_short` and `elasticpro_version`. **ElasticPro → Settings** shows
"Zabbix modules 2.1.0 · build 723f101 · from ElasticPro 0.3.20" from it, and "Zabbix modules 2.1.0 ·
build not recorded" for a module copied straight from a checkout, which has no `BUILD`. `BUILD` is
never committed to this repository.
