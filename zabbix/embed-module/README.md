# ElasticPro embed module

The fifth frontend module: the **ElasticPro** page inside Zabbix that embeds the dashboard
itself and signs the person in through the pairing (one-time code, then SSO). Copied from the
ElasticPro repository at v0.3.10 (`deploy/zabbix/module/elasticpro`, installed there by
`deploy/zabbix/zabbix-modules-install.sh`); edited there.

Install: copy this folder into Zabbix's `modules/` as `elasticpro`, enable it under
*Administration → General → Modules*, then pair from ElasticPro's Config → Zabbix page.
`config.php.example` shows the settings; the live `config.php` is written by the pairing
and is ignored by git.
