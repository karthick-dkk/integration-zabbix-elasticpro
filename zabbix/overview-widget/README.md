# Client overview widget (Zabbix 7.0 module)

Every client at a glance, in one of two views (Layout and production → **View**):

- **Cards** (new widgets): open problems by severity, the metrics the widget lists
(now, and avg / min / max over a trend window), used of allocated with a forecast, servers by
role and what is wrong with the client's data.
- **Summary**: the overview as releases before 2.1 drew it — a summary card per client (servers
  up and down, devices, the chosen family's CPU and memory, ES storage), a usage chart, Used of
  allocated and Servers by role, or one client's tiles and gauges when exactly one client is
  picked. Its settings are under *Summary view* (below). It never lists decommissioned clients;
  *Hide disabled…* decides the disabled ones.

Copy into Zabbix's `modules/` as `ep_overview`;
`master/import.py` enables it and makes the *ElasticPro — Client overview* dashboard.

## Summary view

Layout and production → **View: Summary** shows these settings:

- **Panels**: Summary by client, Usage chart, Used of allocated and Servers by role — on or off,
  dragged into order, each full width, half, a third or a quarter of a row (a narrow widget
  stacks them).
- **Summary cards**: *Cards show* (the family whose CPU and memory the cards, the chart and the
  gauges use), tiles (servers up, down, devices, open problems), bars (CPU, memory, storage), a
  status line (servers down, high or warn on any figure; resources allocated *short* of what was
  requested; servers with *no data*), and columns (auto, 2, 3, 4).
- **Usage chart**: one line per client over a period, for CPU or memory of that family, ES storage
  used (the percentage), or any metric of the Data and metrics list — its servers combined exactly
  as the Cards combine it (same function, same worst-item-per-server rule, same hourly trends).
  The period is the dashboard's time period or the last N hours or days (up to a year); the
  quick buttons 1h · 6h · 24h · 7d and the metric switch on the panel are the widget's to offer and
  are asked of the server, which honours only a choice the widget allows. Periods up to six hours
  are read from Zabbix history (at most ninety points), longer ones from hourly trends; either
  way one call for every client per refresh. Clients: all, or the top N by max, mean or last.
  Each point is the average, maximum or minimum. Style: line, area or stacked area (clients
  without a point at a time add nothing to the stack there). Height small, medium or large. The Y
  axis is 0–100 for a percentage (or always automatic; a stacked area is always automatic, as
  stacked percentages add up past 100). Dashed Warn and High lines are the
  widget's thresholds (its Warn / High, else ElasticPro → Settings) for a usage percentage, or
  the metric's own Warn and High otherwise; a client with its own thresholds on its master host
  is coloured by those in the legend. The legend gives Mean, Min, Max and Last of the line drawn,
  coloured over Warn and High, sorted by mean, max, last or name. Click a client (or its line)
  to show only it; click again for all. **A client with no data in the period says "no data"
  and has no line** — never a line of zeros.
- **Used of allocated**: groups (CPU, memory, storage, devices), rows by client name or by value,
  a segmented or a solid bar; a figure nobody measured is a dash.
- **Servers by role**: donut, bar or pills; count and percent in the legend.
- **Warn at / High at**: empty follows ElasticPro → Settings; Warn above High is refused.

The chart's line colours are the palette's eight chart series colours (ElasticPro → Settings →
Colours, by the viewer's Zabbix theme), by the client's place among the widget's clients.

A Summary widget saved before these settings existed (by 2.0 or 2.1.0) keeps its exact look: no
usage chart, no status line, and Used of allocated beside Servers by role in one row. Opening
its settings shows that as the choices, and applying saves them; turn the usage chart on there.
A widget being added starts with the chart on.

## Data and metrics

- **Host groups** of the client master hosts; **Clients** picked by name (none ticked: every
  client); **Hide disabled and decommissioned clients** (on by default); **Client types**
  (none ticked: every type).
- **Trend window**: the dashboard's time period, or the last N days (7). avg, min and max come
  from Zabbix trends; a window under two hours is read from history instead.
- **Metrics** (up to 20, dragged into order, each edited in its own dialog like Top hosts'
  columns): a **built-in** figure — the client master host's `ep.*` items (ES storage, devices,
  log delay, every family's CPU, memory, disk and servers) or each server's CPU, memory and role
  mounts — or **any Zabbix item key** on the client's servers, filtered by role. A key pattern
  needs at least three plain characters before its first `*`; it is searched anchored at the
  start, with a limit, and reads at most 50 items per server and 500 per metric — beyond that
  the card says the pattern is capped. A graph link over more than 30 items opens Latest data. Servers are combined into one value per client: sum, average, highest, lowest, or count
  over Warn. Units, decimals (empty: ElasticPro → Settings), short numbers; shown as is, as a bar,
  as indicators or as a sparkline; trend columns over the widget's window or the metric's own;
  worse when higher or lower; **thresholds** — a usage percentage may follow ElasticPro →
  Settings (an empty Warn or High is Settings, a client's `{$EP.USAGE.WARN}` / `{$EP.USAGE.HIGH}`
  still win), anything else has its own list (Warn / High, colour, value); no data shows "not
  collected" (with what would collect it) or hides the row; stale after N minutes; a click opens
  the item's graph or Latest data.
- **Order clients by** any metric (now, or its trend max), open problems (worst severity first),
  days until full, contract end or name, either direction; **Show top** N (empty: all); **Only
  show** all clients, those over a threshold, or those with open problems. A client whose sort
  figure is unknown goes last either way.

Each server counts once: when a metric reads several items on one server (srv.disk's mounts, a
pattern), its worst item stands for it — the highest when higher is worse, the lowest when lower
is — before servers are combined, now and in trends. **Count over Warn** counts servers, and is
coloured by its worst server (warn or high), never by holding the count against the per-server
thresholds; its trend cells are not coloured.

How servers combine over time: hour by hour with the metric's function, into one series per
client (windows under two hours: from history, each item's last value per minute). Sum, average and count combine the hourly averages (min and max are the lowest and
highest combined hours); highest takes the highest peak of any server for max (max of maxes),
lowest the lowest trough for min (min of mins). avg is the mean of the combined hours.

## Layout and production

- **Sections**, on or off and in any order: summary bar (off by default), card header (type,
  status, contract badge), problems by severity, metrics, used of allocated, servers by role,
  warnings. **Columns** auto (at least N px wide) or 1–4; **density** comfortable, compact, or a
  table with one row per client.
- **Used of allocated**: ES storage (default), a family's disk or memory, or devices seen of
  purchased; shown as "x of y", a percentage or both, with a bar, a gauge or neither. The forecast
  fits a line to the share over the last N days of trends (7) and reads it through the same bands
  as Client capacity (warn / high at N days left, empty: ElasticPro → Settings). Allocated not set:
  the card says so, names what sets it, and makes no forecast.
- **Contract end** badge from the client's `{$EP.CONTRACT.END}` (Cluster Management → Contract
  ends), warn and high within N days; "No contract date" when none is set.
- **Servers by role**: pills, a stacked bar or a donut; roles shown and their order (a role added
  in Cluster Management appears on its own); a count per role, names on hover, or every server;
  down servers in red — down is the Zabbix agent unavailable, or no data for N minutes.
- **Problems**: which severities (Disaster to Warning by default), acknowledged and suppressed
  counted or not, severities at 0 hidden; **warnings** for stale data, unreachable agents,
  metrics not collected and clients in maintenance.
- **Click-through**: the client name opens its dashboard, the ElasticPro page or its Cluster
  Management page; a severity count opens Problems for the client's hosts at that severity; in
  the same tab or a new one.

Zabbix is asked a fixed number of times per refresh, never once per client: the master hosts and
their macros, the servers, one `item.get` for every key (and one more when a metric's key is a
pattern), one `trend.get`, one `problem.get` with the `trigger.get` that says which host each
problem is on.

A widget saved before 2.1 (Client, Client type, Summary / Used / Servers by role, Cards show,
Warn at, High at) draws unchanged without an edit, on the **Summary** view with its family,
panels and thresholds, its client and type as the lists. Its cards' figures also become the
default metrics, should it be switched to Cards. A widget being added starts on Cards. (A 2.0
widget left entirely at its defaults, with no host groups either, stored no fields and so cannot
be told from a new one: it opens on Cards.)

Tests (from `zabbix/`): `npm test`; the controller alone: `php test/view.test.php`.
