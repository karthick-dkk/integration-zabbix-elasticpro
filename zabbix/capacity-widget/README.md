# Client capacity widget (Zabbix 7.0 module)

A dashboard widget: one row per **client master host**, the columns

Cluster Name · ES URL · Cust.Purchased Storage · Logdelay Devices Count · ES Allocated / Used
Storage · ES Used % · ES Full In · ES CPU and Mem Requested / Allocated / Usage · Parser CPU and Mem
Requested / Allocated / Usage · Forwarder Mem and CPU Requested / Allocated / Usage · Forwarder
Requested / Allocated / Curr.Used Storage

- **ES Full In**: when ES storage fills at the last week's growth, read exactly as Cluster Management
  reads it (`shared/php/Forecast.php`): red under 7 days, yellow under 30, *not growing* when it
  never fills — all three settable on *ElasticPro → Settings*. The export gives days.
- **Usage** cells: yellow at 80 %, red at 90 % by default. Set for every report on *ElasticPro →
  Settings*, for this dashboard alone in the widget's own **Warn at** / **High at**, and for one
  client by its master host's `{$EP.USAGE.WARN}` / `{$EP.USAGE.HIGH}` — in that order, the host
  first. Forwarder used storage is coloured by its share of allocated.
- **Allocated below requested** (or ES storage below purchased): dashed red outline and ▼,
  the request in the tooltip. Unset requests read "not set".
- **Export CSV / Export Excel**: numbers as numbers, bytes divided by the size unit the settings
  name (GB by default, with the header saying which), unknowns as empty cells.

The columns come from `columns.json` (family-level keys of the master template). **Columns**
(Super admins) renames, hides and reorders them; the **Type** filter offers the client types the
settings list; the export follows both. The master template is found by whatever this Zabbix calls
it — *Roles → Template mapping* decides, for this widget as for the Clients page.


## Data, Columns, View (2.1.1)

Client capacity is on the same column engine as Client resources (`../shared/php/Columns.php`,
`ColumnReport.php`, `ColumnCell.php`), with the same three tabs. A flat table — one row a client, no
server rows.

- **Data**: host groups, clients (by name), client types, and the trend window a column's average,
  minimum, maximum and change are taken over.
- **Columns**: *Report defaults | This widget* and *Reset to report defaults*. The catalogue is
  `columns.json` — every column keeps the id it has always had (its item key; `client.name` and
  `client.url`), which is what lets the Columns file written by every earlier release be read onto
  the new model unchanged: names, hidden columns, order. Beside them, any **Zabbix item key** on the
  client's servers, a figure **calculated from two items**, **now or the average, minimum, maximum or
  change over N days**, shown as a value, bar, coloured cell or text, indicators, sparkline or badge,
  with thresholds that are ElasticPro Settings' or the column's own. Add group, Add avg / min / max.
  The server rows' columns of Client resources do not apply here.
- **View**: density and row height, group headers, a sticky header, a frozen first column, striped
  rows, a totals row (sum or average, labelled for what it is over, hidden while a filter is on),
  the default sort and top N, the toolbar's items and where it shows, and what **Needs attention**
  means (over Warn, over High, allocated short of requested, ES storage full within 30 days).

**Warn at** and **High at**: empty is *ElasticPro → Settings* (the boxes show it as their
placeholder); a figure typed applies to this widget alone, a column may set its own, and a client's
master host overrides all of them for itself with `{$EP.USAGE.WARN}` / `{$EP.USAGE.HIGH}`. A 0 stored by
an earlier release is read as empty. **Bar style** starts at *From ElasticPro settings*: Zabbix stores
nothing for a field left at its default, so a widget nobody has configured genuinely follows the site.

A widget saved before 2.1.1 looks as it did: it follows the report defaults, a compact table with a
sticky header and a frozen first column, the toolbar on hover with search, type, Columns and export.
What it kept per browser (the colours of ⚙ Table settings, row height) is the widget's own setting
now — colours follow the palette on *ElasticPro → Settings*; a viewer who had chosen colours sees the
palette. The browser keeps only what one viewer does: the search, Needs attention, Compact and dragged
column widths.

## Panel view

The report draws as a table panel (`shared/ep-panel.css`, `shared/ep-panel.js`), shared with the
other client reports:

- **Search** finds a client by any text in its row, together with the **Type** filter and **Needs
  attention**.
- **Sort** by any column: click its header; again to reverse. A figure nobody measured or entered
  sorts last either way.
- **Resize** a column by dragging its header's right edge; double-click to fit.
- **⋮** holds **Columns…** (Super admins) and the exports.

## Install

1. Copy this folder to Zabbix's modules directory as `modules/ep_capacity` (Docker: mount it
   at `/usr/share/zabbix/modules/ep_capacity`).
2. Run `../master/import.py` — it registers and enables the module and creates the dashboard
   **ElasticPro — Client capacity** (group filter: ElasticPro clients). Or add the widget to
   any dashboard by hand: *Client capacity*.

`assets/js/ep-*.js`, `assets/css/ep-*.css`, `lib/`, `actions/Column*.php`, `includes/{CWidgetField*,ColumnForm,ColumnTable,TableFields}.php` and `views/column*.php` are copies from `../shared/` (`node ../sync-assets.mjs`).
Tests: `php test/view.test.php` — the controller against a fake Zabbix API.
