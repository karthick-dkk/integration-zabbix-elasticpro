# ElasticPro Play widget (Zabbix 7.0 module)

**ElasticPro Play** — ElasticPro's NOC wall display (ElasticPro 0.3.47+, `#/play`) — in a dashboard
widget: the same page as **ElasticPro → Play** in the Zabbix menu, in a frame sized to the widget,
signed in as the Zabbix user looking at the dashboard. Fleet wall, Alerts, Cluster spotlight, Log
delay, Backups and Volume turn as they do on a TV; ← → and Space work once the widget has focus.

Copy into Zabbix's `modules/` as `ep_play` and enable it under *Administration → General → Modules*.
It needs the **ElasticPro** module (`embed-module/`, installed as `elasticpro`) enabled and paired:
the widget signs in through it and nothing else.

## Adding the widget

1. Open a dashboard, **Edit dashboard → Add widget**, type **ElasticPro Play**.
2. Size it: Play lays itself out to the frame (text scales with it), so a wide widget — half the
   dashboard or more — reads best. The default is 36 × 10.
3. Leave **Refresh interval** at *No refresh* (the default). A refresh would not reload Play anyway:
   the widget keeps the running frame while nothing has changed.
4. **Save changes.**

There are no other settings. Which slides show, how long each stays and the zoom are Play's own
(ElasticPro *Config › Play*, and the − / + in Play's top bar); Play's address takes no slide list, so
the widget offers none.

**Open full screen** (bottom right, shown when the pointer is over the widget) opens
**ElasticPro → Play** in Zabbix's kiosk mode in a new tab — no sidebar, no header; Zabbix's own
*Normal view* button leaves it. The person's saved layout mode is not changed.

## How it signs in

The widget's HTML carries no address and no code. Its script asks the ElasticPro module
(`zabbix.php?action=elasticpro.play.frame`, a POST with the user's Zabbix CSRF token) for a fresh
address each time the frame loads — on the first view, when the dashboard page comes back into view,
and when Play's session ends and it asks to be signed in again — and the module mints a one-time
code for that Zabbix user exactly as for its own pages (`embed-module/lib/SignIn.php`). So:

- **Who sees what** is ElasticPro's decision, as on the ElasticPro pages: the user's groups and role
  decide their clusters. Two people looking at one dashboard each see their own Play.
- **Who may see it**: whoever may open the ElasticPro pages. A Zabbix role that denies the
  `elasticpro` module denies the widget's sign-in too, and the widget says so.
- A dashboard page out of view empties its frame (Play stops polling); back in view it signs in
  afresh. "Sign in again" from Play is acted on at most three times a minute, then **EP-703**.

What the widget can show instead of Play: *not enabled* (the ElasticPro module is not enabled),
**EP-701** (not paired), **EP-702** (ElasticPro refused the sign-in, its own reason follows),
**EP-703** (lost the session repeatedly — almost always the two sides no longer share a pairing
secret; re-pair under *Administration → ElasticPro*).

Framing: Play loads from ElasticPro's public address inside the Zabbix page, exactly as the
ElasticPro pages do, so it works wherever they work: ElasticPro's `frame-ancestors` names the paired
Zabbix origin. Nothing in Zabbix's or ElasticPro's headers changes for it.

## Not offered: display links

A Play *display link* (`#/play?display=<secret>`, made in ElasticPro *Config › Play*) is meant for a
TV that signs in as nobody. The widget does not take one: a widget field is stored in the dashboard,
where `dashboard.get` returns it to everyone who can read that dashboard, and the frame's address
would put the secret in every viewer's browser. A TV should open the display link itself.

Tests: `node --test play-widget/test/*.test.mjs` (part of `npm test`).
