# Mock clients — for a test Zabbix only

Five made-up ElasticPro clients (Mock Alpha … Mock Echo) so the report widgets have something to
show on a test system: a healthy one, one with storage at 92 % and a server down, one short of the
servers and cores it requested, a single node, and one with memory high and a parser down.

Each is built the way a real client is: a master host on the client master template with its
request macros, a cluster host, and server hosts in the client's group and its role groups. The
cluster and server items are Zabbix agent checks answered by `mock_agent.py` on
`127.0.0.1:10061…`, with values that wander a little and storage that grows slowly, so "Full in"
has a trend. Every mock host is also in the group **ElasticPro mock data**.

```bash
# create (or re-create) them, and write the agent's values
ZABBIX_URL=http://zabbix/zabbix ZABBIX_USER=Admin ZABBIX_PASSWORD=… python3 zbx_mock.py values.json
# run the mock agent where the Zabbix server can reach 127.0.0.1 (the server's own machine)
python3 mock_agent.py values.json
# remove every mock host
ZABBIX_URL=… ZABBIX_USER=Admin ZABBIX_PASSWORD=… python3 zbx_mock.py values.json --remove
```

Never run this against a production Zabbix.
