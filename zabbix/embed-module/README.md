# ElasticPro embed module

The **ElasticPro** page inside Zabbix (installed as `elasticpro`): it embeds the dashboard
itself and signs the person in through the pairing (one-time code, then SSO). This folder is the
only copy. It started as a copy of ElasticPro's `deploy/zabbix/module/elasticpro` (ElasticPro
v0.3.10); ElasticPro has since dropped that folder and ships this one, taken at the commit pinned
in its `deploy/zabbix/MODULES_REF`, in `elasticpro-zabbix-modules-<ver>.tar.gz` with the other six.
Change it here.

Install: `zabbix-modules-install.sh` from an ElasticPro release puts it in place with the rest; by
hand, copy this folder into Zabbix's `modules/` as `elasticpro`, enable it under
*Administration → General → Modules*, then pair from ElasticPro's Config → Zabbix page.
`config.php.example` shows the settings; the live `config.php` is written by hand on the server and
is ignored by git (the pairing stores its settings in the Zabbix database instead).

`sync-role.json` is the one definition of ElasticPro's Zabbix API identity — role, user, token
names and the API allow-list (read methods, plus `history.push`; see below). The pairing
(`lib/Pairing.php`) reads it, and so does ElasticPro's `deploy/zabbix/setup/zbx_api_user.py`, from
the installed module.

**Error codes** (the module's range is 7xx; ElasticPro's `docs/ERROR-CODES.md` lists every range):

| Code | When |
|---|---|
| EP-701 | not paired with ElasticPro yet |
| EP-702 | ElasticPro refused to sign this Zabbix user in; the core's own code follows the reason |
| EP-703 | signed in, then lost the session three times in a minute (usually the two sides' pairing secrets differ) |

Tests: `node --test embed-module/test/*.test.mjs` (part of `npm test`); the PHP suite
`test/play.test.php` runs with local `php` or a `php:8.4-cli-alpine` container and is skipped,
saying so, where there is neither.

## Play (2.1.15)

**ElasticPro → Play** (action `elasticpro.play`) opens ElasticPro's NOC wall display (`#/play`,
ElasticPro 0.3.47+) in the page, like every other ElasticPro entry: same one-time code, same
frame, same "sign me in again" handling, same error codes. It is offered to everyone who sees the
ElasticPro menu; what they see in it is what ElasticPro gives their groups and role. With
`&fullscreen=1` that one response is drawn without Zabbix's sidebar and header, with an **Exit full
screen** link back to the normal page. Not Zabbix's `kiosk=1`: Zabbix saves that parameter into the
person's profile (`web.layout.mode`) on every request, so every page they opened afterwards would be
in kiosk mode too; `fullscreen=1` saves nothing. A Zabbix whose views cannot set the layout mode per
response draws it in the normal layout instead.

Since 2.1.17 the Play page also has a **Full screen** button (top right, beside **Exit full screen**
when that shows): its click asks the browser for full screen on the ElasticPro frame, Esc returns.
Every ElasticPro frame carries `allow="clipboard-write; fullscreen"` and `allowfullscreen`, so
ElasticPro's own button in Play works in it too. The button saves nothing in Zabbix.

Who may open any ElasticPro page, Play and the widget's action included (`SignIn::mayOpen`): a Zabbix
User or above, never the guest user — Zabbix gives guest a role of the User type, so with guest
access to the frontend a guest-visible page would otherwise sign `guest@zabbix` in. The guest user is
not offered the ElasticPro menu. When ElasticPro refuses a sign-in (EP-702), a Super admin sees the
reason in full; everyone else sees only ElasticPro's own codes in it (`SignIn::reason`), since the
reason can name the core's internal address.

The **ElasticPro Play** dashboard widget (`../play-widget/`, installed as `ep_play`) shows the same
page in a dashboard. It does not sign in on its own: its script asks this module's
`elasticpro.play.frame` action — a POST that Zabbix checks against the user's CSRF token, refused to
anyone below a Zabbix User, as the pages are — for a fresh frame address each time the frame loads.
The answer is JSON: `{"url", "origin"}`, or `{"error": {"code": "EP-701" | "EP-702", "message",
"reason"}}`, worded as this page words them.

Both go through `lib/SignIn.php`, the one sign-in: what Zabbix vouches for (user type, groups and
role, read from the database), the code, and the frame address. Nothing in Zabbix's or ElasticPro's
headers changes for Play: the frame is ElasticPro's public address inside a Zabbix page, which
ElasticPro's `frame-ancestors` (the paired Zabbix origin) already allows.

## The sync identity and `history.push`

Pairing creates (or reuses) the role **ElasticPro sync** and the user `elasticpro-sync`, with the
API methods listed in `sync-role.json`, the one definition the pairing and the setup script both
read. Besides the read methods the cluster sync needs, the list includes **`history.push`**, so the
ElasticPro core can push its own request figures to the template *ElasticPro request gate by Zabbix
trapper* (ElasticPro 0.3.24+, `ZABBIX_METRICS_HOST`).

What that implies: the role is of the Super admin type, so it sees every host. With `history.push`,
ElasticPro's paired token can write a value into **any trapper item** in this Zabbix, not only the
metrics host's. It still cannot change any configuration. A site that does not want that can
remove `history.push` from the role and give the core a separate, push-only token instead
(`ELASTICPRO_ZABBIX_METRICS_TOKEN_FILE`; ElasticPro's `deploy/README.md`, "The request figures in
Zabbix").

**After upgrading the module, re-pair once** (ElasticPro: Config → Zabbix → Pair with Zabbix). An
existing role whose method list differs from `sync-role.json` is updated during pairing, so this
adds `history.push` to an install paired before. Or edit the role by hand: *Users → User roles →
ElasticPro sync → API methods*, add `history.push`. Until one of the two is done, the core's push is
refused with `No permissions to call "history.push"` and the Request Centre's Zabbix line says so.

## Trusted plain-HTTP hosts (`trusted_plain_hosts`)

Cluster Management (the clients module) asks the ElasticPro core, over this module's pairing, what
role the signed-in Zabbix user has there, and lets an ElasticPro admin edit. What authenticates that
answer is the core's signature: an HMAC under the pairing secret over the question's ts and nonce
and the exact body. Someone on the path can block the answer (then only Super admins edit) but
cannot forge or replay it.

Which connections are acceptable on top of that: https with TLS verification (the recommendation),
a loopback core, or plain http to a host named in `trusted_plain_hosts`. The default is `['ep-core']`,
the core's service name on the documented compose network (`core_url` `http://ep-core:8765`). Set it
in `config.php` (it then shows read-only on the settings page) or under Administration → ElasticPro →
Edit settings. Exact host names only: the page refuses IP addresses and wildcards, and a core_url that
names an IP never matches. An empty list trusts no plain-http host. For any other plain-http core the
page says "ElasticPro's answer cannot be trusted over this connection" and only Super admins edit.
