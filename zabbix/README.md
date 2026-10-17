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
