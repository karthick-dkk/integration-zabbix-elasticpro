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

**Display** and the two thresholds start at *From ElasticPro settings*. Zabbix stores nothing for a
field left at its default, so a widget nobody has configured genuinely follows the site rather than
holding a copy of today's numbers. One consequence on upgrade: a widget where somebody had chosen
*Bar under the value* — the old default — is indistinguishable from one never touched, and now
follows the site. Since the site's default is also *bar under the value*, nothing changes until an
admin changes it there on purpose.

## Install

1. Copy this folder to Zabbix's modules directory as `modules/ep_capacity` (Docker: mount it
   at `/usr/share/zabbix/modules/ep_capacity`).
2. Run `../master/import.py` — it registers and enables the module and creates the dashboard
   **ElasticPro — Client capacity** (group filter: ElasticPro clients). Or add the widget to
   any dashboard by hand: *Client capacity*.

`assets/js/ep-*.js`, `assets/css/ep-widget.css`, `lib/` and `actions/ColumnsSave.php` are copies from `../shared/` (`node ../sync-assets.mjs`).
