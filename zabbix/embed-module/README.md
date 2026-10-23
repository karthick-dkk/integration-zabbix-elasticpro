# ElasticPro embed module

The fifth frontend module: the **ElasticPro** page inside Zabbix that embeds the dashboard
itself and signs the person in through the pairing (one-time code, then SSO). Copied from the
ElasticPro repository at v0.3.10 (`deploy/zabbix/module/elasticpro`, installed there by
`deploy/zabbix/zabbix-modules-install.sh`); edited there.

Install: copy this folder into Zabbix's `modules/` as `elasticpro`, enable it under
*Administration → General → Modules*, then pair from ElasticPro's Config → Zabbix page.
`config.php.example` shows the settings; the live `config.php` is written by the pairing
and is ignored by git.

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
