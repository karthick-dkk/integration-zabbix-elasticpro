# Client resources widget (Zabbix 7.0 module)

Requested · Allocated · Used for every client, in sections like the Volume report widget — one
row per **client master host**: Client (name, type, ES URL) · Log delay · ES storage · then per
family (ES, Parser, Forwarder, Engine, and any family added on the Clients page's Roles): servers,
CPU (cores), memory, disk.

- **Expand** a client (▸, or *Expand all*) for its servers: roles (a server with several reads
  "ES Data + Parser + Engine", marked shared or single node), extra services, host, IP, agent
  status, CPU cores and use, memory and use, one column per disk mount (Disk /, Disk /data …) with its size and use, each coloured by its own figure.
- **Type** filter: DI / On-Prem — the table, the count and the export follow it.
- **Columns** (Super admins, ⋮ → Columns…): the report defaults — rename, show / hide, reorder;
  show a family's roles as their own sections (ES Data Hot, ES Master …). A widget may have its own
  columns instead (below). The export follows the columns the widget shows.
- **Used**: CPU and memory as the average % across the hosts; disks as the size with its share
  of allocated. Yellow at 80 %, red at 90 % (a client's `{$EP.USAGE.WARN}` / `{$EP.USAGE.HIGH}`).
- **Allocated below requested** (ES storage: below purchased): dashed red outline, the request in
  the tooltip. A request nobody entered reads "not set"; a figure not measured "—".
- **Export CSV / Export servers CSV / Export Excel** (sheets *Clients* and *Servers*): numbers as
  numbers, bytes as GB, unknowns as empty cells.

The columns are built from the roles each time, so a role added on the Clients page appears here
with no change to this widget.


## Columns, per widget or report defaults (2.1.1)

Every column is the shared column model (`../shared/php/Columns.php`, the same engine the Client
overview's metrics use). Edit widget → **Columns**:

- **Columns from: Report defaults | This widget.** Report defaults are set by a Super admin in the
  widget's ⋮ → **Columns…** dialog (names, shown, order, families shown as roles) — or with **Use
  this widget's columns** there — and every widget that follows them shows them. Choosing *This
  widget* starts the widget's own list from them; **Reset to report defaults** goes back.
- Each column: group, name; a built-in column, any **Zabbix item key** on the client's servers (a
  pattern needs 3 plain characters before its first `*`; at most 50 items a server, 500 a column)
  or **calculated from two items** (x of y with %, minus, plus, divided by); servers by role and how
  they combine; **now, or average, minimum, maximum or change over N days** (trends; history in
  one-minute buckets under two hours); units, decimals, size unit, short numbers; what an unknown
  says (the reason, or —) and when a value is stale; where a click goes; shown as a value, bar
  (under, in cell, segments, gauge), coloured cell or text, indicators, sparkline or badge; bar
  colours by level or fixed, track, range, value and %; align, width, wrap; thresholds — ElasticPro
  Settings' (empty Warn / High: the widget's, then Settings) or its own levels and colours.
- **Add avg / min / max for a column** adds copies of a column over the trend window beside it.
- New roles from Cluster Management appear in a widget's own list on their own, unticked.
- **Server rows** (Role, Host, IP, Status, CPU cores, CPU used, Memory, Memory used, Disk mount,
  Disk size, Disk used, Uptime) each open the same editor.

One rule checks a column in the editor, on Apply and (leniently) when the widget draws; a saved
column that no longer passes is left out and named under the table. Colours are `#RRGGBB` only and
otherwise follow the palette (**ElasticPro → Settings → Colours**, per Zabbix theme).

A Columns file written before 2.1.1 is read onto the model unchanged — names, hidden columns,
order, families as roles — and only rewritten when a Super admin saves.

## View

**Table** or **Pie chart**, and whether viewers may switch on the widget. The Pie chart draws a
card per client with the donuts chosen (any figure with used, allocated and requested; ES storage,
CPU and memory by default): used, free and short (hatched, solid or hidden) around a centre of used
of allocated or requested, or the used or free value; a legend with percentages; status chips. A
donut whose allocated is not set says so and draws no ring. The Table: server rows on click, all or
none; density and row height; group headers, sticky header, frozen first column; striped rows; a
totals row (sum or average); default sort and top N. The toolbar's items and whether it is always
shown, on hover or hidden; what **Needs attention** means (over Warn, over High, a server down,
short, a server sending no data, ES storage full within 30 days) and whether the widget starts on it.

## Panel view

The report draws as a table panel (`shared/ep-panel.css`, `shared/ep-panel.js`), shared with the
other client report:

- **Search** finds a client by any text in its row or its servers (host, IP), together with the
  **Type** filter and **Needs attention** — in the table and the pie chart alike.
- **Sort** by any column: click its header; again to reverse. A figure nobody measured or entered
  sorts last either way.
- **Resize** a column by dragging its header's right edge; double-click to fit.
- **⋮** holds **Columns…** (Super admins) and the exports.

How the report looks — colours, row height, which server columns show — is the widget's own
setting. The browser keeps only one viewer's conveniences: the search, the open clients, Needs
attention, Compact and the view switched to (until the page is reloaded), and dragged column widths.

## Install

Copy this folder to Zabbix's modules directory as `modules/ep_resources`, then run
`../master/import.py`: it enables the module and creates **ElasticPro — Client resources**.
Needs the data folder described in `../clients-module/README.md` (roles, column settings).
Tests: `php test/view.test.php` — the controller against a fake Zabbix API.
`assets/js/ep-*.js`, `assets/css/ep-*.css`, `lib/`, `actions/Column*.php`, `includes/{CWidgetField*,ColumnForm,ColumnTable,TableFields}.php`,
`views/column*.php` and `views/columns.form.js.php` are copies of `../shared/` (`node ../sync-assets.mjs`): the settings form's
Data, Columns (with the server rows) and View parts are the same `ColumnForm` and `EpColumnsForm` the other column widgets use;
`views/widget.edit.php` and `views/widget.edit.js.php` add only what is this widget's own — the view switch and the donuts.

## Settings

**Bar style** and the two thresholds (**Warn at**, **High at**) start at *From ElasticPro settings*
/ empty — the site-wide values on *ElasticPro → Settings*, which the boxes show as their placeholder.
A figure typed into the widget applies to that dashboard alone, a column may set its own, and a
client's master host overrides them for itself with `{$EP.USAGE.WARN}` / `{$EP.USAGE.HIGH}`: the
host wins, then the column, then the widget, then the site. A threshold of 0 saved by an earlier
release is read as empty.

Decimals, the export's size unit and the client types the **Type** filter offers come from the same
page. The client master template is found by whatever this Zabbix calls it — *Roles → Template
mapping* decides.

The same note as the capacity widget applies on upgrade: a *Display* left at the old default now
follows the site, whose default is the same style.
