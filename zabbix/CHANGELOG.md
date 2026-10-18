# Changelog

What changed in the ElasticPro Zabbix modules, newest first. Every module carries the same
version (`manifest.json` in each folder under `zabbix/`), so one line here covers the whole set.
How a version is chosen and released is in [README.md](README.md#versions-and-releases).

## 2.1.1

Full customization for the ElasticPro widgets, starting with Client resources: one column engine,
one palette.

- **The column engine** (`shared/php/Columns.php`, `ColumnMath.php`, `ColumnReader.php`, copied into
  every module). A column has a group and a name; a source — a built-in column, any Zabbix item key
  on the client's servers (a pattern needs 3 plain characters before its first `*`, and reads at
  most 50 items a server and 500 a column) or a figure calculated from two items (x of y with its %,
  minus, plus, divided by); the servers it reads and how they combine (each server's worst item
  first); its value now or its average, minimum, maximum or change over N days (one trend.get;
  history in one-minute buckets under two hours); units, decimals, size unit, short numbers; what an
  unknown says and when a value is stale; where a click goes (graph, Latest data, client dashboard,
  nothing — Zabbix pages only); how it shows (value, bar under / in cell / segments / gauge,
  coloured cell or text, indicators, sparkline, badge) and its thresholds — ElasticPro Settings' or
  its own levels and colours, higher or lower is worse. One rule checks a column in the editor, on
  Apply and, leniently, when a widget draws; every error names the column. Colours are `#RRGGBB`
  only. The Client overview's metrics now use the same rules and arithmetic (its behaviour and tests
  unchanged).
- **Colours in ElasticPro → Settings.** One palette — OK, Warn, High, Unknown, Short, Free, Down,
  Stale, Link for the Blue and the Dark theme, and eight chart series colours — that Client
  resources, Client capacity, Client overview and Top hosts draw with, chosen by the viewer's Zabbix
  theme. Text on each colour is checked: below 4.5:1 it is flagged and gets dark text. Reset to
  defaults; Super admins only, saved with the other settings. The shipped colours are the panel's
  own. One visible change: Client resources and Client capacity in the Blue theme had painted OK,
  Warn and High with the Dark theme's colours (the per-browser Table settings wrote them over the
  sheet); they now use the Blue theme's, as Client overview and Top hosts always did. A viewer who
  chose colours in Client capacity's Table settings keeps them.
- **Client resources: Data, Columns, View.** Clients, client types and a trend window. Columns per
  widget or from the report defaults (*Columns from: Report defaults | This widget*, *Reset to
  report defaults*); the column list with drag to reorder, Add column, Add group and Add avg / min /
  max for a column; new roles from Cluster Management arrive unticked; the server rows' columns
  (Role … Uptime) each in the same editor. A **Pie chart** view — per client, donuts of used, free and
  short (hatched, solid or hidden) for any figure with used, allocated and requested, a centre of
  your choice, a legend with percentages and status chips; allocated not set says so and draws no
  ring — and viewers may switch between it and the table. Table options: server rows on click / all
  / none, density and row height, group headers, sticky header, frozen first column, striped rows, a
  totals row, default sort and top N. Toolbar items and position, and what **Needs attention** means
  (over Warn, over High, server down, short, no data, disk full within 30 days) with its default.
- **Report defaults, migrated.** The Columns dialog's settings are kept as the full column model.
  A Columns file written by an earlier release is read onto it unchanged — names, hidden columns,
  order, families shown as roles — and is only rewritten when a Super admin saves. A Super admin can
  make a widget's own columns the report defaults from the dialog.
- **A widget saved before this release looks as it did**: it follows the report defaults, a compact
  table, the toolbar on hover. Warn / High left empty mean ElasticPro Settings and the boxes say
  so — the 0 an earlier release stored for an empty box is read as empty. The look is the widget's
  setting now: colours, row height and server columns are no longer kept per browser; the browser
  keeps only the search, open clients, Needs attention, Compact, the view switched to and dragged
  column widths. CSV and Excel export follow the widget's visible columns.
- **Client overview: a usage chart in the Summary view, and more Summary settings.** One line per
  client over time for CPU or memory of the family the Summary shows, ES storage used, or any
  metric of the widget's Data and metrics list — combined per client exactly as the Cards combine
  a metric, with dashed Warn and High lines, a legend table (Mean, Min, Max, Last) coloured over
  Warn and High, quick 1h / 6h / 24h / 7d buttons and a metric switch on the panel (when the
  widget allows them), and click a client to show only it. Short periods are read from Zabbix
  history, long ones from trends, in one call per refresh. A client with no data in the period
  says "no data" and has no line, never zeros. The lines are drawn in the palette's eight chart
  series colours (ElasticPro → Settings → Colours).
  A period too long to read for a client's items at once says "window not read" for that client,
  not "no data". The Client overview's metrics follow the column rule for roles: a metric that
  names a role Cluster Management no longer has is refused on Apply, and on the dashboard it is
  left out and named above the cards instead of quietly reading every server.
  The Layout tab, with View set to Summary, now sets the Summary view's panels (on or off, drag
  order, full / half / third / quarter width: Summary by client, Usage chart, Used of allocated,
  Servers by role), its cards (family, tiles, bars, status line, columns), the chart (metric,
  period, top N clients, line / area / stacked area, point average / max / min, height, Y axis,
  legend), Used of allocated (groups, row order, segmented or solid bar) and Servers by role
  (donut, bar or pills; count and percent). Warn and High keep their place; empty still follows
  ElasticPro → Settings.
  A Summary widget saved before this release is drawn exactly as it was, byte for byte: no
  usage chart, no status line, Used of allocated beside Servers by role. Turn the chart on in
  the widget's settings; a widget being added starts with the chart on.

## 2.1.0

The first numbered release. It is everything on `main` up to this point; from here every change
that reaches a Zabbix bumps the version.

- **Templates and the embed module.** The ElasticPro menu module (opens ElasticPro inside Zabbix,
  signed in as the Zabbix user) and the four templates ElasticPro feeds (automation, alerts,
  client plan, log delay), copied from ElasticPro 0.3.10 so a Zabbix can be set up from this
  project alone.
- **Client capacity and Client resources.** A dark table panel with search, sort and resizable
  columns, and a settings dialog.
- **Overview panels and Top hosts.** The Client overview widget, a Top hosts widget ranking the
  busiest client servers by CPU or memory, every disk coloured, panels that follow the Zabbix
  theme, and mock clients for trying them out.
- **No single-node role.** A single node is a server holding ES, Parser, Forwarder and Engine
  rather than a role of its own.
- **Compact Settings and Cluster Management.** Both pages are shorter, the client list has an
  edit icon and the client page a status header, and **Remove only in ElasticPro** is the way to
  take a client out of ElasticPro without touching its hosts in Zabbix.
- **Excel import.** Clients and their servers can be imported from one Excel workbook (Clients,
  Servers and Sample sheets, with dropdowns and notes); no zip extension is needed on the Zabbix
  server. CSV import still works.
- **Client overview 2.1.** Full customization: host groups, clients and client types; a trend
  window; a list of metrics (built-in ElasticPro figures or any item key on the client's servers)
  with their own thresholds, display and trend columns; ordering, top N and filters; sections,
  columns, density, used of allocated with a forecast, servers by role, problems and warnings, and
  where a click goes. The Summary view of the earlier overview is kept, and widgets saved before
  2.1 draw as they did.
