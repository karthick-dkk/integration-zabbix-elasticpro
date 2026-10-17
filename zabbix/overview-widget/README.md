# Client overview widget (Zabbix 7.0 module)

Every client at a glance, in one of two views (Layout and production → **View**):

- **Cards** (new widgets): open problems by severity, the metrics the widget lists
(now, and avg / min / max over a trend window), used of allocated with a forecast, servers by
role and what is wrong with the client's data.
- **Summary**: the overview as releases before 2.1 drew it — a summary card per client (servers
  up and down, devices, the chosen family's CPU and memory, ES storage), Used of allocated and
  Servers by role, or one client's tiles and gauges when exactly one client is picked. Its own
  settings: *Cards show* (family), its three panels, Warn and High (empty: ElasticPro → Settings).
  It never lists decommissioned clients; *Hide disabled…* decides the disabled ones.

Copy into Zabbix's `modules/` as `ep_overview`;
`master/import.py` enables it and makes the *ElasticPro — Client overview* dashboard.

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
