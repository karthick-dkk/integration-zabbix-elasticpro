# Changelog

What changed in the ElasticPro Zabbix modules, newest first. Every module carries the same
version (`manifest.json` in each folder under `zabbix/`), so one line here covers the whole set.
How a version is chosen and released is in [README.md](README.md#versions-and-releases).

## 2.1.8

- **New template `ElasticPro cluster health`** (`templates/template-elasticpro-cluster-health.yaml`): the cluster's own alerts, ElasticPro-owned. Health RED/UNKNOWN/YELLOW, service down and response time, node count changes, initializing and unassigned shards, cluster restarted, resharding space, per-node JVM heap, query/indexing/fetch/flush latency, thread-pool rejections and node restarts (`es.nodes.discovery`, 46 item / 10 trigger / 5 graph prototypes), plus the daily `es.indices-check` with its `{$INDICES.MAX.SIZE}` / `{$INDICES.MIN.SIZE}` triggers — 39 items, 15 triggers, under the keys of Zabbix's stock "Elasticsearch Cluster by HTTP", so a cluster moved off an older copy of that template keeps every stored value. It declares the threshold macros only: connection macros stay with `Elasticsearch Cluster by HTTP EP`, credentials with the host or the default credential (a template defining the password macro would hide it). Validator: 0 errors; the five "used but not defined" warnings for those macros are expected.
- **Cluster Management links it** to every cluster host asked over HTTP (new slot `health` on the Roles page's template mapping, `Reconciler::OWN_CLUSTER_SLOTS`; not to jump-host clusters). `master/import.py` says how to import it when it is missing instead of refusing. Discover's write list and the client page say six templates where they said five.
- **`tools/migrate-templates.py` adopts a discovery rule** that is in both the old and the new template instead of refusing the host: same rule id, the rule and its prototypes inherited again, the items it discovered untouched (checked on Zabbix 7.0.31; the dry run prints `ADOPT   discovery rule <key>`, the verify line counts `discovery rules not adopted`). The host is still refused when a prototype has another value type under the same key. `TemplateMove` in Cluster Management follows the same rule (one shared case table). Run for real on a cluster host with the old Elasticsearch template: 210 items before and after, every id unchanged, 0 values lost; `docs/MIGRATION.md` has the map for it.

## 2.1.7

- **Cluster Management: the Add / Edit client form is compact.** Rows are 32 px (inputs 28 px, labels 12.5 px in a 120 px column) with 8 px between them, cards pad 12 px, the (i) hints sit beside their labels, and the cards pack into two columns that are no longer stretched to each other — Client then Capacity, Connection then Log archive — with Servers & roles full width under them (an empty list is one line, "No servers yet · + Add server"). Add client fits a 1440 x 900 screen without scrolling. Layout and spacing only: every field, behaviour and password rule is as before.

## 2.1.6

- **Discover: a typed ES URL now adopts a cluster host that has no URL macros.** The refused row's hidden host id stayed 0, so typing `https://host:9200` (even then "Preview writes") never enabled Adopt ("No host is ticked that can be adopted"). The row now carries the real host id, a valid typed URL lifts the refusal and ticks the row after the preview, the write list gains "Set connection macros on <host> from the typed URL", and adopting sets `{$ELASTICSEARCH.SCHEME/HOST/PORT}` from it before linking; an invalid URL stays refused with the reason.

## Unreleased

- **Discover existing hosts** (Cluster Management → Discover): finds the Elasticsearch clusters
  Zabbix holds (a host in a host group whose name contains "elasticsearch"; its host name is the
  cluster's) and their role hosts, proposes each as a client with what is Missing and exactly what
  adopting writes, and after "Adopt selected" makes the client through the same path every save
  takes — five templates linked directly to the cluster host, older generations kept beside, items
  adopted by key, nothing deleted, unlinked with clear or renamed. A role host on an old agent
  template is moved as `tools/migrate-templates.py` moves one (`lib/TemplateMove.php`, one case
  table shared with the script). See clients-module/README.md → Discover existing hosts.
- **Host name = IP, visible name = server name** for role hosts. A host taken on keeps its host
  name (never renamed, not even back from `<client>-<Role>-<n>`); only its visible name follows the
  server name. The client form, the workbook and the CSV call the column "server name". New role
  hosts are created with the IP as host name.
- **Roles**: Trino (ULM) and SOAR families added (roles version 3); Kafka is not a role — a Kafka
  server is a Forwarder or a Parser, flagged until confirmed.
- **The password** is typed once and never shown ("set on … by …", Change / Keep current); a keep-
  marker stops other edits and autofill from touching it; a password kept as a Zabbix secret is also
  handed to the ElasticPro core over the signed pairing channel (needs ElasticPro with
  `/sso/zabbix/cluster-secret`). A new client's password is the **default credential** (global
  macros, set on Settings → Default Elasticsearch credential) unless "This cluster only".
- **Two proxy choices**: the cluster host's, and one for all of a client's Linux role hosts; both
  default to the Zabbix server.
- **Cluster Management list, client page and form** redrawn to the approved boards: filters, role
  columns with counts, Columns popover (hide / reorder / rename), the summary line under the table;
  tabs with a Missing panel and servers by role; the compact form with Retention and Backup capacity
  (new, displayed only).
- The ElasticPro cluster template (version 2) no longer defines `{$ELASTICSEARCH.USERNAME}` or
  `{$ELASTICSEARCH.PASSWORD}`, which hid the global default: **Write master template** once.
  Until then the client's page names it under Missing and says so.
- A cluster template under a name the module does not know (found by its Elasticsearch health
  item) is named everywhere: Discover, the list's "+ N older beside", the client page's Beside and
  Templates tab, and Missing. It was reported by Discover only.
- `tools/migrate-templates.py`: an old item whose key the new template also has, with another value
  type, is now refused (the link would adopt and convert it) instead of reported as kept apart.

## 2.1.5

- Cluster Management: the **Backups (N)** button on the client list is shown to Zabbix Super admins only. Backups stay with Super admins whatever ElasticPro says, but a Zabbix Admin whom ElasticPro calls an admin was offered the button and pressed it into "Access denied" (the menu already hid it). The button and the backup count are no longer given to anyone else; Import / export, Roles and Add client are unchanged.

## 2.1.4

- ElasticPro automation template: `elasticpro.state` accepts pushed values (`allow_traps`), and its poll interval is the new `{$ELASTICPRO.POLL}` macro (default 5m; 0 = push-only host never polls). This matches ElasticPro 0.3.24's `elasticpro-scrape.mjs --format sender`, which now sends the whole scrape document to that item. No other objects changed; UUIDs kept.

## 2.1.3

Cluster Management edit rights follow ElasticPro's role, the ElasticPro page shows error codes, and
a new template receives ElasticPro's own request figures. Upgrade note: **re-pair once** after
installing (ElasticPro: Config → Zabbix → Pair with Zabbix) so the sync role picks up
`history.push`; the edit rights need the ElasticPro core release that serves `/sso/zabbix/role`
(0.3.24).

- **Snapshots, as in 2.1.2.** The page keeps the label "Snapshots" in the ElasticPro menu and page
  list, matching ElasticPro 0.3.22. Nothing changed here since 2.1.2; it is listed because 2.1.3 is
  the first build most installs will take with it.
- **New template: ElasticPro request gate by Zabbix trapper**
  (`templates/template-elasticpro-request-gate.yaml`). The ElasticPro core (0.3.24+) pushes what
  its Request Centre shows, once a minute, to one trapper item when `ZABBIX_METRICS_HOST` names the
  host: in flight and queued per class, the oldest wait, wait p95, grants, refusals by reason,
  answers over the response cap, memory in use. Aggregates only, no cluster name or address.
  Triggers: figures stopped arriving; requests queueing, refused, or people waiting too long
  (warning and critical pairs); a pause left on; in-flight memory high; answers over the cap; the
  version changed. Every threshold is a `{$ELASTICPRO.GATE.*}` macro. Setup is in
  `templates/README.md`.
- **The sync role may push history.** The role *ElasticPro sync* (`embed-module/sync-role.json`)
  now includes `history.push`, so ElasticPro's paired token can feed the template above without a
  second token. What that implies: the role is of the Super admin type, so that token can write a
  value into **any trapper item** in this Zabbix, not only the request gate's. It still cannot
  change any configuration. Re-pairing updates an existing role; or add `history.push` by hand
  under *Users → User roles → ElasticPro sync*. A site that does not want this removes
  `history.push` from the role and gives the core a push-only token instead
  (`ELASTICPRO_ZABBIX_METRICS_TOKEN_FILE`).
- **Error codes on the ElasticPro page.** Not paired yet shows EP-701; a sign-in ElasticPro refused
  shows EP-702 with the core's own code after the reason; losing the session three times in a
  minute shows EP-703, which names the server-log reasons by their codes (EP-306 `not_configured`,
  EP-510 `bad_signature`, EP-511 `stale`). ElasticPro's `docs/ERROR-CODES.md` lists them all.
- **One copy of the ElasticPro menu module.** `embed-module/` is the only source of the module
  Zabbix installs as `elasticpro`; ElasticPro ships it from here with the other six, so a normal
  install no longer shows "mixed" module versions in ElasticPro → Settings.
- **Cluster Management: ElasticPro's role decides who edits.** Zabbix still owns hosts and host
  groups; for edit rights on this page, ElasticPro's role is the authority:

  | Zabbix user type | ElasticPro says | Cluster Management |
  |---|---|---|
  | Super admin | (not asked) | edits |
  | Admin or User | `admin`, not blocked or locked, in a signed answer the page trusts (below) | edits, within their Zabbix rights; never Backups or Re-create |
  | Admin | `operator`, `user`, `guest`, blocked or locked | reads, and the page says why ("Your ElasticPro role: operator.") |
  | Admin | has never signed in to ElasticPro | reads |
  | Admin | could not be asked (ElasticPro module not paired, core not answering) | reads, and the page says "ElasticPro could not be asked who may edit; only Super admins can edit until it answers" |
  | User | anything but `admin` | nothing, as before |

  The question goes to the core over the ElasticPro module's pairing, signed like the sign-in, with
  a fresh nonce each time. Only a signed, fresh answer counts: an unsigned or wrongly signed answer,
  or a replayed one, is not acted on. The answer is kept 60 seconds in the Zabbix session. Roles
  appears in the sidebar for exactly those who may edit. When ElasticPro could not be asked, or its
  answer cannot be trusted, the person sees only the fixed line; the reason (core_url, the
  connection error, the core's message) goes to the web server's PHP error log, not to the page.
- **Trusted plain-HTTP hosts.** An `admin` answer is acted on over https with TLS verification, from
  a loopback core, or over plain http to a host named in the ElasticPro module's new
  `trusted_plain_hosts` setting: *Administration → ElasticPro → Edit settings*, field "Trusted
  plain-HTTP hosts", or `config.php`. Exact host names only; IP addresses and wildcards are refused.
  The default is `ep-core`, the core's name on the documented compose network. An empty list trusts
  no plain-http host. Any other plain-http core: nobody is elevated and the page says "ElasticPro's
  answer cannot be trusted over this connection". https stays the recommendation.
- **Backups stay Super admin only**, whatever ElasticPro's role: Backups (list, download, restore)
  and Re-create / Put back / Accept refuse everyone else and are hidden from their sidebar, and only
  a Super admin's pages compare with the register of hosts or record a client in it.
- **Partial backups.** A backup is read through Zabbix as the person saving, so an ElasticPro admin
  who is a Zabbix Admin backs up only the clients they can see. Such a backup is marked partial:
  it records the clients it covers, the Backups list says "partial: N of M clients", the newest
  three partial backups are kept apart from the three full ones (so Admins' saves never push a Super
  admin's backup out), and restoring it changes only the clients in it, never removing or
  overwriting any other.
- **Zabbix rights are checked before a change, not half way.** Someone who is not a Super admin
  gets a refusal naming the step before anything is written when the change needs a step Zabbix
  keeps for Super admins (creating a host group or template group; the cluster DL alert routing or
  weekly report) or write permission they lack on a host group, a host or the
  `Templates/Applications` template group.
- **Template fixes**, one per template (`templates/`; the templates are not modules, so they change
  nothing in the module folders):
  - *ElasticPro automation*: "the automation scrape has stopped running" now also fires when the
    document cannot be fetched at all (endpoint down, DNS, 401/404). It was `fuzzytime()` alone,
    which went UNKNOWN instead of raising anything once the HTTP item went unsupported; now
    `fuzzytime(...)=0 or nodata(...)=1` on the same item. UUID unchanged, so it keeps its events.
    Reproduced on Zabbix 7.0.31 and 7.0.32.
  - *ElasticPro log delay*: declares its template group (the only one of the four that did not), so
    it imports on a Zabbix whose Templates/Applications group is missing or renamed.
  - *ElasticPro request gate*: "version has changed" no longer stays UNKNOWN (the version is
    stored at every push, so `change()` has two values from the second push on); the "people wait
    for a request slot" triggers no longer stick in PROBLEM after the slow minutes end (a new item,
    granted interactive requests, arrives every minute and both triggers require it, so they
    recover when nobody is asking). Found on Zabbix 7.0.31.
  - The automation and log-delay fixes are made in these copies; the same two fixes still have to
    reach the ElasticPro repository, or its next copy brings the old versions back
    (`templates/README.md` says where).
- **Template tests** (`tools/test/`). `validate_template.py` checks a template statically
  (references, UUIDs, credentials, macros); CI now runs it on every template in `templates/` and on
  the templates Cluster Management generates (`gen_templates.php`). `template_live_test.py`
  imports `ztest-` copies into a test Zabbix (`ZABBIX_URL`, `ZABBIX_TOKEN`), proves a second import
  changes nothing, sets every macro on a test host and feeds payloads, including the edge cases
  (empty secret, wrong URL, thresholds at the boundary, stale data); `--dry-run` needs no Zabbix
  and runs in CI. It covers every template in `templates/`, the request gate one with its own
  scenario. Its macro scan also reads trigger function parameters, so a macro used only there
  (`nodata(...,{$ELASTICPRO.STALE})`) is no longer reported as unused.
- **Moving hosts onto the ElasticPro templates without losing history**: `tools/migrate-templates.py`
  and `docs/MIGRATION.md`. Dry run unless `--commit`; it never deletes anything (delete methods and
  "unlink and clear" are refused before a request is built). Per host it copies to host level the
  macros the kept items need, unlinks the old template without clearing, renames the keys that map
  onto the new template, links the new templates (which adopt the renamed items, same ids and
  history), and checks that every item still exists with at least as many values. A template with
  the current name but older keys is upgraded in place. A rename onto a key of another value type,
  or a link that would fail, is refused with the reason and the host left alone. Macros a newly
  linked template would redefine for kept items are pinned at host level. Trigger macros are read
  from the stored expression, not Zabbix's expanded one, so a macro used only in a trigger
  parameter is kept on unlink and `--import` aligns every trigger instead of duplicating the ones
  with a macro (3 of the client plan's 6 had been duplicated in testing). `--commit` writes a
  journal and `--rollback` undoes from it. The planner's tests run in CI
  (`migrate_plan_test.py`); `migrate_live_test.py` proves both modes against a test Zabbix.

## 2.1.2

The Snapshots page is labelled "Snapshots" in the ElasticPro menu and page list, matching ElasticPro 0.3.22. Label only: the page id, actions and widget types are unchanged.

## 2.1.1

Full customization for the ElasticPro widgets — Client resources, Client capacity, Volume, Top hosts
and the Client overview's Summary view: one column engine, one palette.

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
  ring — and viewers may switch between it and the table (*Viewers may switch on the widget* puts the Table / Pie chart switch on the toolbar). Table options: server rows on click / all
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
- **Client capacity, Volume and Top hosts on the same engine.** The three widgets join Client
  resources: Data, Columns and View tabs (Top hosts on one page, as designed), the same column
  editor, the same palette, the same table options — and one reading of a figure, a cell, a total and
  an export (`ColumnReport`, `ColumnCell`, `TableOptions`, `ColumnTable`), and one settings form
  (`ColumnForm` and its script `EpColumnsForm`, Client resources' server rows included), shared and
  copied into every module, so a fix to one is a fix to all four.
  - **Client capacity**: its 31 columns (`columns.json`) are the built-in columns under the ids they
    have always had; beside them any item key on the client's servers, two items calculated, now or
    average / minimum / maximum / change over a window, groups, avg / min / max copies. Report
    defaults, with the Columns file of every earlier release read onto them unchanged. Table options
    and **Needs attention** (over Warn, over High, short, storage full within 30 days). Its
    per-browser Table settings (colours, row height) are the widget's settings now, following the
    palette.
  - **Volume**: the plan template's items are the built-in columns (their `plan` tag the section),
    with the same column editor, copies over a window and any other item of the cluster host.
    *Thresholds*: from the plan template's macros (the default; the cell and the trigger agree) or
    per column. Decimals, group headers by plan, totals, **search and sort** (new, from the shared
    panel), sort and top N; the columns file of earlier releases read unchanged, a column the
    template gains arriving shown.
  - **Top hosts**: clients (pick several), client types and **roles**; rank by CPU, memory, disk used,
    JVM heap or any Zabbix item, now or its average or maximum over the window; how many; which
    metrics a viewer may switch between; columns (Rank, Host, Value, Client · Role and any column of
    the model); a list with bars, a table or a bar chart. A widget saved before keeps ranking the same
    servers the same way: its Client, Client type, Rank by, Hosts and thresholds are read onto the new
    settings. **JVM heap** is read where Elasticsearch reports it: per node on the client's cluster
    host (the templates the clients use keep it there, none on the servers). A server is read at the
    node of its name, a node that names no server is ranked as itself, and when no node reports it
    the pane says what to link — 2.1.0 read it on the servers and ranked nothing.
  - ElasticPro's Top hosts is **ElasticPro top hosts** in the widget type list: Zabbix 7.0 has a Top
    hosts widget of its own, and the list showed two. A Top hosts widget saved with an empty name
    now shows the new default name, "ElasticPro top hosts"; one given a name keeps it.
  - A widget saved before this release of each kind looks as it did; Warn / High left empty mean
    ElasticPro Settings. Export headers say their unit ("(days)", "(GB)" for a unit Zabbix is told not
    to scale).
    An export's figures keep the places each widget always wrote them to, whatever the Decimals the
    table is drawn with: Volume every number to two places, Client resources and Client capacity a
    percentage to one and everything else to two.
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
  A refresh keeps the widget scrolled where the viewer left it. The time axis reads in one unit
  ("−7d … −1d", never "−84h" beside "−7d"). *Always auto* scales the Y axis to the data, not to
  the Warn and High lines; a line above the range is named in the note instead of drawn. With View
  set to Summary the Data tab keeps the Metrics list the chart's metric menu offers.
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
