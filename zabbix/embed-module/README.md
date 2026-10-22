# ElasticPro embed module

The fifth frontend module: the **ElasticPro** page inside Zabbix that embeds the dashboard
itself and signs the person in through the pairing (one-time code, then SSO). Copied from the
ElasticPro repository at v0.3.10 (`deploy/zabbix/module/elasticpro`, installed there by
`deploy/zabbix/zabbix-modules-install.sh`); edited there.

Install: copy this folder into Zabbix's `modules/` as `elasticpro`, enable it under
*Administration → General → Modules*, then pair from ElasticPro's Config → Zabbix page.
`config.php.example` shows the settings; the live `config.php` is written by the pairing
and is ignored by git.

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
