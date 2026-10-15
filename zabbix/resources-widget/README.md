# Client resources widget (Zabbix 7.0 module)

Requested · Allocated · Used for every client, in sections like the Volume report widget — one
row per **client master host**: Client (name, type, ES URL) · Log delay · ES storage · then per
family (ES, Parser, Forwarder, Engine, and any family added on the Clients page's Roles): servers,
CPU (cores), memory, disk.

- **Expand** a client (▸, or *Expand all*) for its servers: roles (a server with several reads
  "ES Data + Parser + Engine", marked shared or single node), extra services, host, IP, agent
  status, CPU cores and use, memory and use, one column per disk mount (Disk /, Disk /data …) with its size and use, each coloured by its own figure.
- **Type** filter: DI / On-Prem — the table, the count and the export follow it.
- **Columns** (Super admins): rename, show / hide, reorder; show a family's roles as their own
  sections (ES Data Hot, ES Master …). One setting for everyone; the export follows it.
- **Used**: CPU and memory as the average % across the hosts; disks as the size with its share
  of allocated. Yellow at 80 %, red at 90 % (a client's `{$EP.USAGE.WARN}` / `{$EP.USAGE.HIGH}`).
- **Allocated below requested** (ES storage: below purchased): dashed red outline, the request in
  the tooltip. A request nobody entered reads "not set"; a figure not measured "—".
- **Export CSV / Export servers CSV / Export Excel** (sheets *Clients* and *Servers*): numbers as
  numbers, bytes as GB, unknowns as empty cells.

The columns are built from the roles each time, so a role added on the Clients page appears here
with no change to this widget.


## Panel view

The report draws as a dark table panel (`shared/ep-panel.css`, `shared/ep-panel.js`), shared with
the other client report:

- **Search** finds a client by any text in its row or its servers (host, IP), together with the **Type** filter.
- **Sort** by any column: click its header; again to reverse. A figure nobody measured or entered
  sorts last either way.
- **Resize** a column by dragging its header's right edge; double-click to fit.
- **⋮** holds **Columns…** (Super admins) and the exports.
- **⚙ Table settings**: colours for normal, warn and high, row height and which server columns show. These are one
  viewer's preferences, kept in that browser. Thresholds and the bar style stay the widget's own
  **Warn at**, **High at** and **Display**, so everyone sees the same colour for the same figure.

The search, the sort and the open clients survive the widget's refresh.

## Install

Copy this folder to Zabbix's modules directory as `modules/ep_resources`, then run
`../master/import.py`: it enables the module and creates **ElasticPro — Client resources**.
Needs the data folder described in `../clients-module/README.md` (roles, column settings).
Tests: `php test/view.test.php` — the controller against a fake Zabbix API.

## Settings

**Display** and the two thresholds (**Warn at**, **High at**) start at *From ElasticPro settings*
— the site-wide values on *ElasticPro → Settings*. A figure typed into the widget applies to that
dashboard alone, and a client's master host overrides both for itself with `{$EP.USAGE.WARN}` /
`{$EP.USAGE.HIGH}`: the host wins, then the widget, then the site. A threshold of 0 is a field left
alone, not a threshold of nought.

Decimals, the export's size unit and the client types the **Type** filter offers come from the same
page. The client master template is found by whatever this Zabbix calls it — *Roles → Template
mapping* decides.

The same note as the capacity widget applies on upgrade: a *Display* left at the old default now
follows the site, whose default is the same style.
