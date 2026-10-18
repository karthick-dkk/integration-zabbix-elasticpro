# Changelog

What changed in the ElasticPro Zabbix modules, newest first. Every module carries the same
version (`manifest.json` in each folder under `zabbix/`), so one line here covers the whole set.
How a version is chosen and released is in [README.md](README.md#versions-and-releases).

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
