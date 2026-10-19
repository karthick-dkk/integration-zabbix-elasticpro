# Volume report widget (Zabbix 7.0 module)

The Volume report's **client plan**, one row per cluster host, laid out as ElasticPro lays
it out: Cluster · Volume · Live storage · Backup storage, each section over its columns, every
value centred.

- **Columns** are the "ElasticPro client plan" template's items — label, section (tag
  `plan`) and place (tag `column`) — which ElasticPro generates from the list the Volume
  report page draws. The widget keeps no column list: add a column to the report, regenerate
  and import the template, and the widget shows it (in the report defaults, and offered unticked in
  a widget's own list).
- **Colours** where the template's triggers alarm, at their thresholds: Live used above
  `{$ELASTICPRO.PLAN.LIVE_USED.WARN}` (85 %) yellow, Live days under `{$ELASTICPRO.PLAN.LIVE_DAYS.MIN}`
  (14 days) red — the cluster host's own macro first, then the template's. An unreachable cluster's
  row is dimmed; the cluster cell says when it was updated.
- **Export CSV / Export Excel**: one row per cluster, numbers in the item's units (GB, %, days) to
  two places — whatever **Decimals** says, which is only how the table draws them — unknowns as
  empty cells, never 0.

## Data, Columns, View (2.1.1)

Volume is on the same column engine as Client resources (`../shared/php/Columns.php`,
`ColumnReport.php`, `ColumnCell.php`), with the same three tabs. A row is one cluster host.

- **Data**: host groups, clients (a cluster's client is its host group), client types, and the trend
  window a column's average, minimum, maximum and change are taken over.
- **Columns**: *Report defaults | This widget*. The catalogue is the plan template's items, under
  the ids the report has always used (the item key), so the Columns file written by every earlier
  release is read onto the new model unchanged. Each column: rename, hide, reorder, display (value,
  bar, coloured cell or text, indicators, sparkline, badge), colours, **avg / min / max copies over the
  window**, and any other item of the cluster host (a key, a pattern, two items calculated). A row is
  one host, so there are no servers to pick and nothing to combine.
- **Thresholds**: *From the plan template's macros* (the default — what the template's triggers compare
  against, so the cell and the alarm agree; Live used strictly above Warn, Live days strictly under
  the minimum) or *Per column*, where each column has its own thresholds (seeded from the macros).
- **Decimals**: *From ElasticPro settings* (a measured figure to the places the site says; days and
  bare counts whole) or 0–3, for every figure that sets none of its own.
- **View**: group headers by plan, a totals row, sort and top N, search, the toolbar (Type filter,
  Columns dialog, export) and where it shows, density, a sticky header, a frozen first column.

A widget saved before 2.1.1 looks as it did: the report defaults, the section header, the cluster
fixed on the left, comfortable rows, the toolbar always shown with the Type filter, Columns and the
exports, coloured at the macros. What is new on it is search and sorting (the shared panel).

## Install

1. Copy this folder to Zabbix's modules directory as `modules/ep_volume` (Docker: mount it at
   `/usr/share/zabbix/modules/ep_volume`).
2. `ZABBIX_URL=… ZABBIX_TOKEN=… python3 import.py` — enables the module and creates the dashboard
   **ElasticPro — Volume report** (group: Elasticsearch clusters). Or add *Volume report* to any
   dashboard by hand.

`assets/js/ep-*.js`, `assets/css/ep-*.css`, `lib/`, `actions/Column*.php`, `includes/{CWidgetField*,ColumnForm,ColumnTable,TableFields}.php` and `views/column*.php` are copies of `../shared/` (`node ../sync-assets.mjs`).
Tests: `php test/view.test.php` — the controller against a fake Zabbix API.

**Columns** (Super admins) renames, hides and reorders the template's columns as the report
defaults; the **Type** filter shows clusters of DI or On-Prem clients (from each client's master
host). The export follows both. Needs the data folder described in `../clients-module/README.md`.

## Settings

Both templates it reads — the client plan and the client master, for each cluster's type — are found
by whatever this Zabbix calls them: *Roles → Template mapping* decides. Where its figures turn
yellow and red stays, by default, the client plan template's own macros, because that template's
triggers compare against them — choosing *Per column* is a decision to let the dashboard disagree.

`import.py` is idempotent: a second run prints what it kept and exits 0. It creates the
*Elasticsearch clusters* host group if the dashboard it builds would otherwise have no filter.
