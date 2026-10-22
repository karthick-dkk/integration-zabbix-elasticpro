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
names and the read-only API allow-list. The pairing (`lib/Pairing.php`) reads it, and so does
ElasticPro's `deploy/zabbix/setup/zbx_api_user.py`, from the installed module.

**Error codes** (the module's range is 7xx; ElasticPro's `docs/ERROR-CODES.md` lists every range):

| Code | When |
|---|---|
| EP-701 | not paired with ElasticPro yet |
| EP-702 | ElasticPro refused to sign this Zabbix user in; the core's own code follows the reason |
| EP-703 | signed in, then lost the session three times in a minute (usually the two sides' pairing secrets differ) |

Tests: `node --test embed-module/test/*.test.mjs` (part of `npm test`).
