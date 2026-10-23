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

Tests: `node --test embed-module/test/*.test.mjs` (part of `npm test`).

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
