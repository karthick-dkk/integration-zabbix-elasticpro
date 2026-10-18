# Top hosts widget (Zabbix 7.0 module)

The busiest client servers, ranked by the figure you choose, in each client's colours.

- **Who**: host groups of the client master hosts, **clients** (pick from the list), **client types**
  and **roles**. Empty means all. The clients and their servers are the ones Client resources expands
  into (`../shared/php/ClientData.php`).
- **Rank by**: CPU, memory, disk used (the server's fullest mount), JVM heap, or **any Zabbix item**
  (a key, or a pattern with `*`; the server's worst matching item ranks it). **Rank value**: now, or the
  average or maximum over the trend window (Zabbix trends; one-minute history under two hours). **Hosts**
  is how many are listed. A server with no figure for the metric is left out of its ranking — never
  ranked as 0.
- **Switch on widget**: the metrics a viewer may switch between without opening the form, besides the
  one ranked by. Every one is ranked and drawn on the page, so switching costs no trip to Zabbix.
- **Columns**: Rank, Host, Value, Client · Role, and more: Client, Role, IP, Status, CPU, memory, disk,
  uptime, or any Zabbix item of the server — now or its average, minimum or maximum over the window —
  each with its own look and thresholds (the shared column editor). **Value** is the figure ranked
  by; its window is the widget's, so it is only ever the value now as a column.
- **View**: a list with bars (as before), a table, or a bar chart.
- **Warn at / High at**: empty is *ElasticPro → Settings*; a client's own `{$EP.USAGE.WARN}` /
  `{$EP.USAGE.HIGH}` on its master host win. They judge percentages; an item that is not a percentage
  is judged by the Value column's own thresholds, if it has any.

A widget saved before 2.1.1 looks as it did: **Client** (a name) is the one client picked, **Client
type** the one type, **Rank by** keeps CPU and memory (the same field), the thresholds of 0 are empty;
it is a list with bars with the CPU / Memory switch and the four columns it drew.

**JVM heap** is reported per Elasticsearch node, and the templates the clients use keep it on the
client's **cluster host**, not on the servers: *Elasticsearch Cluster by HTTP* (and the SISA copy of it)
and ElasticPro's *Elasticsearch via SSH jump host* discover the nodes there and make
`es.node.jvm.mem.heap_used_percent[<node name>]` for each. (Checked on the test Zabbix 7.0.31: the item
is on `Local-ES-Cluster` only, keyed `[ep-es-1]`; no server host carries it.) So Top hosts
(`includes/JvmNodes.php`):

- reads a server that carries `es.node.jvm.mem.heap_used_percent[*]` itself there, as before;
- reads any other server at its node on the client's cluster host — the node whose name is the server's
  Zabbix host name or visible name (regardless of case), or the first label of either when only one
  server has it (`es-hot-1` for `es-hot-1.example.com`);
- ranks a node that names no server as itself (`<node>`, *<client> · ES node*, linking to the cluster
  host's Latest data), so a site whose host names are not its node names still sees its heap. With
  roles picked such a node's role is not known: it is left out, and the pane says how many were;
- says, when no node reports JVM heap at all, where it is read and which template to link, instead of
  an empty list.

Now or over the window alike: the node's item and its trend are read through the column engine (a row
may say where one column is read — `ColumnReport`'s `at`), one item.get for the node items of every
client, only when JVM heap is ranked or offered on the switch.

## Install

Copy this folder to Zabbix's modules directory as `modules/ep_tophosts`, then run `../master/import.py`
(it enables the module and creates the dashboard). `assets/css/ep-*.css`, `lib/`, `actions/ColumnEdit.php`,
`includes/{CWidgetField*,ColumnForm,ColumnTable,TableFields}.php` and `views/column*.php` are copies of
`../shared/` (`node ../sync-assets.mjs`). Tests: `php test/view.test.php`.
