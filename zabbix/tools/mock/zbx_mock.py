#!/usr/bin/env python3
"""
Five mock ElasticPro clients on a TEST Zabbix — named "Mock …" unless --names says otherwise, and
every host also in the group "ElasticPro mock data", so they are easy to tell apart and to remove (--remove).

Each client is built the way a real one is: a master host on the client master template with its
request macros, server hosts in the client's group and their role groups, and a cluster host,
whose items the master template's calculated items read. The server and cluster items are
Zabbix agent checks answered by mock_agent.py on 127.0.0.1:<port>; values.json says what.

  ZABBIX_URL=… ZABBIX_USER=Admin ZABBIX_PASSWORD=… python3 zbx_mock.py values.json [--names "A,B,C,D,E"] [--remove]
"""
import json, os, sys, time, urllib.request

URL = os.environ['ZABBIX_URL'].rstrip('/') + '/api_jsonrpc.php'
GB = 1024 ** 3
MOCK_GROUP = 'ElasticPro mock data'

def call(method, params, auth=None):
    body = {'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}
    req = urllib.request.Request(URL, json.dumps(body).encode(), {'Content-Type': 'application/json-rpc', **({'Authorization': 'Bearer ' + auth} if auth else {})})
    r = json.load(urllib.request.urlopen(req, timeout=60))
    if 'error' in r:
        raise SystemExit(f"{method}: {r['error'].get('data') or r['error']}")
    return r['result']

TOKEN = call('user.login', {'username': os.environ['ZABBIX_USER'], 'password': os.environ['ZABBIX_PASSWORD']})
api = lambda m, p: call(m, p, TOKEN)

def group(name):
    g = api('hostgroup.get', {'filter': {'name': [name]}, 'output': ['groupid']})
    return g[0]['groupid'] if g else api('hostgroup.create', {'name': name})['groupids'][0]

if '--remove' in sys.argv:
    gid = api('hostgroup.get', {'filter': {'name': [MOCK_GROUP]}, 'output': ['groupid']})
    hosts = api('host.get', {'groupids': gid[0]['groupid'], 'output': ['hostid']}) if gid else []
    if hosts:
        api('host.delete', [h['hostid'] for h in hosts])
    print(f"removed {len(hosts)} mock hosts")
    sys.exit(0)

# The client master template: whatever the existing master host is on.
masters = api('host.get', {'filter': {'host': ['Local-Master']}, 'selectParentTemplates': ['templateid', 'name'], 'output': ['hostid']})
tpl = [t for t in masters[0]['parentTemplates'] if 'master' in t['name'].lower()][0] if masters else None
if not tpl:
    tpl = api('template.get', {'search': {'host': 'client master'}, 'output': ['templateid', 'name']})[0]
print('master template:', tpl['name'])

# role id -> (group, family macro? no: role macros are per role)
ROLE_GROUP = {'es_data': 'ES Data', 'es_data_hot': 'ES Data Hot', 'es_data_warm': 'ES Data Warm', 'es_coord': 'ES Coordination',
              'es_master': 'ES Master', 'parser': 'Parser', 's3_parser': 'S3 Parser', 'forwarder': 'Forwarders',
              'engine': 'Engine', 'ueba': 'UEBA', 'aiml': 'AIML', 'single_node': 'Single node'}
FAMILY_GROUP = {'es': 'ESNodes', 'parser': 'Parsers', 'fwd': 'Forwarders', 'engine': 'Engines', 'single': 'Single node'}
ROLE_FAMILY = {'es_data': 'es', 'es_data_hot': 'es', 'es_data_warm': 'es', 'es_coord': 'es', 'es_master': 'es', 'parser': 'parser',
               's3_parser': 'parser', 'forwarder': 'fwd', 'engine': 'engine', 'ueba': 'engine', 'aiml': 'engine', 'single_node': 'single'}

# The five clients. Names from --names "A,B,C,D,E" (on a test system people like to see their own);
# the profiles below stay in this order. Every client has ES, Parser, Forwarder and Engine servers.
# servers: (roles, count, cores, mem GB, cpu %, mem %, disks {mount: (size GB, used %)}, down count).
# A server with several roles is one machine in each role's group: ('es_data', 'engine', 'parser',
# 'forwarder') is a single node, ES, Engine, Parser and Forwarder on one box.
PROFILES = [
    {'type': 'DI', 'purchased_gb': 4000, 'devices': 400, 'storage': (4000, 2300, 6), 'seen': 388, 'delay': (0, 400, 0, 95, 340),
     'servers': [(('es_data_hot',), 3, 16, 64, 46, 62, {'/': (100, 22), '/data': (1300, 58)}, 0), (('es_master',), 1, 8, 32, 12, 55, {'/': (100, 18)}, 0),
                 (('es_coord',), 1, 8, 32, 18, 48, {'/': (100, 20)}, 0), (('parser',), 2, 8, 32, 52, 58, {'/': (200, 31)}, 0),
                 (('forwarder',), 1, 4, 16, 33, 41, {'/': (500, 47)}, 0), (('engine',), 1, 8, 32, 61, 66, {'/': (100, 29)}, 0)]},
    {'type': 'On-Prem', 'purchased_gb': 3000, 'devices': 300, 'storage': (3000, 2760, 12), 'seen': 287, 'delay': (7, 300, 2, 410, 5400),
     'servers': [(('es_data',), 2, 16, 64, 71, 78, {'/': (100, 41), '/data': (1500, 92)}, 1), (('es_master',), 1, 4, 16, 14, 61, {'/': (50, 22)}, 0),
                 (('parser',), 1, 8, 32, 64, 70, {'/': (200, 38)}, 0), (('forwarder',), 1, 4, 16, 28, 44, {'/': (500, 81)}, 0),
                 (('engine',), 1, 8, 32, 49, 57, {'/': (100, 31)}, 0)]},
    {'type': 'CI', 'purchased_gb': 8000, 'devices': 1200, 'storage': (7000, 4100, 25), 'seen': 1104, 'delay': (3, 1200, 0, 160, 1900),
     'servers': [(('es_data_hot',), 2, 16, 64, 84, 74, {'/': (100, 35), '/data': (2000, 64)}, 0), (('es_data_warm',), 2, 8, 64, 38, 69, {'/': (100, 28), '/data': (1500, 51)}, 0),
                 (('es_master',), 3, 4, 16, 11, 52, {'/': (50, 21)}, 0), (('es_coord',), 2, 8, 32, 27, 47, {'/': (100, 19)}, 0),
                 (('parser',), 2, 8, 32, 88, 81, {'/': (200, 44)}, 0), (('s3_parser',), 1, 8, 32, 42, 50, {'/': (200, 36)}, 0),
                 (('forwarder',), 2, 4, 16, 36, 40, {'/': (500, 52)}, 0), (('engine',), 1, 8, 32, 58, 63, {'/': (100, 33)}, 0),
                 (('ueba',), 1, 8, 32, 47, 59, {'/': (100, 27)}, 0), (('aiml',), 1, 16, 64, 76, 71, {'/': (200, 48)}, 0)],
     'short': {'es_data_hot': 4, 'parser': 3}},
    {'type': 'DI', 'purchased_gb': 500, 'devices': 50, 'storage': (500, 210, 2), 'seen': 50, 'delay': (0, 50, 0, 60, 180),
     'servers': [(('es_data', 'engine', 'parser', 'forwarder'), 1, 16, 64, 39, 57, {'/': (100, 26), '/data': (500, 42)}, 0)]},
    {'type': 'On-Prem', 'purchased_gb': 10000, 'devices': 900, 'storage': (9000, 6100, 40), 'seen': 860, 'delay': (12, 900, 4, 720, 9600),
     'servers': [(('es_data',), 4, 16, 128, 66, 91, {'/': (100, 33), '/data': (2250, 67)}, 0), (('es_master',), 3, 4, 16, 9, 58, {'/': (50, 24)}, 0),
                 (('parser',), 3, 8, 32, 74, 86, {'/': (200, 41)}, 1), (('forwarder',), 2, 4, 16, 31, 46, {'/': (500, 61)}, 0),
                 (('engine',), 1, 8, 32, 44, 60, {'/': (100, 26)}, 0), (('aiml',), 1, 16, 64, 69, 72, {'/': (200, 39)}, 0)]},
]
NAMES = ['Mock Alpha', 'Mock Bravo', 'Mock Charlie', 'Mock Delta', 'Mock Echo']
if '--names' in sys.argv:
    NAMES = [n.strip() for n in sys.argv[sys.argv.index('--names') + 1].split(',') if n.strip()][:5]
CLIENTS = []
for n, prof in zip(NAMES, PROFILES):
    slug = ''.join(ch for ch in n.lower() if ch.isalnum())
    c = {'name': n, 'url': f'https://es.{slug}.local:9200', **prof}
    # Requested: what each role has, unless the profile asks for more (to show a shortfall).
    req = {}
    for roles, count, cores, mem_gb, *_ in prof['servers']:
        for rid in roles:
            have = req.get(rid, (0, 0, 0))
            req[rid] = (have[0] + count, have[1] + count * cores, have[2] + count * mem_gb)
    for rid, want in prof.get('short', {}).items():
        cnt, cpu, mem = req[rid]
        req[rid] = (want, cpu * want // cnt, mem * want // cnt)
    c['requested'] = req
    CLIENTS.append(c)

values = {'start': time.time(), 'hosts': {}}
port = 10061
ag = lambda key, name, units='', tags=(), vt=0: {'name': name, 'key_': key, 'type': 0, 'value_type': vt, 'delay': '1m', 'units': units,
                                                  'interfaceid': None, 'tags': [{'tag': t, 'value': v} for t, v in tags]}
clients_gid, mock_gid = group('ElasticPro clients'), group(MOCK_GROUP)

def upsert_host(host, groups, interface_port=None, templates=(), macros=(), items=()):
    old = api('host.get', {'filter': {'host': [host]}, 'output': ['hostid']})
    if old:
        api('host.delete', [old[0]['hostid']])
    p = {'host': host, 'groups': [{'groupid': g} for g in groups], 'tags': [{'tag': 'elasticpro', 'value': 'mock'}],
         'templates': [{'templateid': t} for t in templates], 'macros': [{'macro': m, 'value': str(v)} for m, v in macros]}
    if interface_port:
        p['interfaces'] = [{'type': 1, 'main': 1, 'useip': 1, 'ip': '127.0.0.1', 'dns': '', 'port': str(interface_port)}]
    hid = api('host.create', p)['hostids'][0]
    if items:
        ifid = api('hostinterface.get', {'hostids': hid, 'output': ['interfaceid']})[0]['interfaceid']
        for it in items:
            it['interfaceid'] = ifid
            it['hostid'] = hid
        api('item.create', list(items))
    return hid

for c in CLIENTS:
    n = c['name']
    cg = group(n)
    # The master host and its request macros.
    macros = [('{$GRP.CLIENT}', n), ('{$EP.CLIENT.TYPE}', c['type']), ('{$ES.URL}', c['url']),
              ('{$ES.VOLUME.CUS.PURCHASED}', c['purchased_gb']), ('{$EP.DEVICES.PURCHASED}', c['devices'])]
    for rid, (cnt, cpu, mem) in c['requested'].items():
        R = rid.upper()
        macros += [(f'{{$EP.{R}.SERVER.COUNT.REQUESTED}}', cnt), (f'{{$EP.{R}.CPU.REQUESTED}}', cpu), (f'{{$EP.{R}.MEMORY.REQUESTED}}', mem),
                   (f'{{$EP.{R}.ROOTDISK.REQUESTED}}', 100)]
    data_roles = {r for s in c['servers'] if '/data' in s[6] for r in s[0] if r.startswith('es_')}
    for rid in data_roles:
        macros.append((f'{{$EP.{rid.upper()}.DISK2.FS}}', '/data'))
    upsert_host(f'{n}-Master', [clients_gid, cg, mock_gid], templates=[tpl['templateid']], macros=macros)

    # The cluster host: storage, devices seen, log delay.
    alloc_gb, used_gb, grow_gb = c['storage']
    late, measured, critical, median, worst = c['delay']
    citems = [ag('agent.ping', 'Agent ping', vt=3), ag('es.nodes.fs.total_in_bytes', 'ES file stores total', 'B', vt=3),
              ag('es.nodes.fs.used_in_bytes', 'ES file stores used', 'B', vt=3), ag('ep.es.devices.seen', 'Devices seen', vt=3)]
    citems += [ag(f'elasticpro.delay[{k}]', f'Log delay {k}', 's' if k in ('median', 'worst') else '', vt=0) for k in ('late', 'devices', 'critical', 'median', 'worst')]
    values['hosts'][port] = {'host': f'{n}-ES-Cluster', 'items': {
        'agent.ping': '1', 'es.nodes.fs.total_in_bytes': {'base': alloc_gb * GB, 'int': True},
        'es.nodes.fs.used_in_bytes': {'base': used_gb * GB, 'wobble': 0.002 * used_gb * GB, 'grow': grow_gb * GB, 'max': alloc_gb * GB, 'int': True},
        'ep.es.devices.seen': {'base': c['seen'], 'int': True},
        'elasticpro.delay[late]': {'base': late, 'wobble': 1 if late else 0, 'min': 0, 'int': True},
        'elasticpro.delay[devices]': {'base': measured, 'int': True}, 'elasticpro.delay[critical]': {'base': critical, 'int': True},
        'elasticpro.delay[median]': {'base': median, 'wobble': median * 0.1}, 'elasticpro.delay[worst]': {'base': worst, 'wobble': worst * 0.05}}}
    upsert_host(f'{n}-ES-Cluster', [cg, mock_gid], interface_port=port, items=citems)
    port += 1

    # The servers.
    for roles, count, cores, mem_gb, cpu, memu, disks, down in c['servers']:
        gs = [cg, mock_gid]
        for rid in roles:
            gs += [group(ROLE_GROUP[rid]), group(FAMILY_GROUP[ROLE_FAMILY[rid]])]
        groups = list(dict.fromkeys(gs))
        label = 'Single-Node' if len(roles) > 1 else ROLE_GROUP[roles[0]].replace(' ', '-')
        for i in range(1, count + 1):
            host = f"{n}-{label}-{i}"
            items = [ag('agent.ping', 'Agent ping', vt=3), ag('system.cpu.num', 'CPU cores', vt=3), ag('system.cpu.util', 'CPU utilization', '%'),
                     ag('vm.memory.size[total]', 'Memory total', 'B', vt=3), ag('vm.memory.utilization', 'Memory utilization', '%')]
            vals = {'agent.ping': '1', 'system.cpu.num': str(cores), 'system.cpu.util': {'base': cpu, 'wobble': 6, 'min': 0, 'max': 100},
                    'vm.memory.size[total]': str(mem_gb * GB), 'vm.memory.utilization': {'base': memu, 'wobble': 3, 'min': 0, 'max': 100}}
            for m, (size, pused) in disks.items():
                tags = [('filesystem', m)]
                items += [ag(f'vfs.fs.dependent.size[{m},total]', f'{m}: total', 'B', tags, vt=3), ag(f'vfs.fs.dependent.size[{m},used]', f'{m}: used', 'B', tags, vt=3),
                          ag(f'vfs.fs.dependent.size[{m},pused]', f'{m}: used %', '%', tags)]
                vals[f'vfs.fs.dependent.size[{m},total]'] = str(size * GB)
                vals[f'vfs.fs.dependent.size[{m},used]'] = {'base': size * GB * pused / 100, 'wobble': size * GB * 0.002, 'int': True}
                vals[f'vfs.fs.dependent.size[{m},pused]'] = {'base': pused, 'wobble': 0.2}
            values['hosts'][port] = {'host': host, 'items': vals, **({'down': True} if i <= down else {})}
            upsert_host(host, groups, interface_port=port, items=items)
            port += 1
    print(f"{n}: master, cluster and {sum(s[1] for s in c['servers'])} servers")

json.dump(values, open(sys.argv[1], 'w'), indent=1)
print(f"values for {len(values['hosts'])} mock agent ports written to {sys.argv[1]}")
