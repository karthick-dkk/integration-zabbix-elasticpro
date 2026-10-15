#!/usr/bin/env python3
"""
ElasticPro mock agent — TEST VM ONLY. Answers Zabbix passive checks for the "Mock …" hosts with
made-up figures, so the ElasticPro widgets have five clusters' worth of data to show.

Each mock host's agent interface points at 127.0.0.1:<its own port>; this listens on every port
named in values.json and answers that host's keys. A value is {"base": n, "wobble": n, "grow": n}:
base ± a slow sine wobble, plus `grow` per day (storage that fills, so "Full in" has a trend).
A port listed with "down": true is not opened, so that host reads as down.

Speaks both the Zabbix 7.0 JSON passive-check request and the older plain-key one.
"""
import asyncio, json, math, struct, sys, time

CONF = json.load(open(sys.argv[1] if len(sys.argv) > 1 else '/opt/elasticpro-mock/values.json'))
START = CONF.get('start', time.time())

def value(spec, port, key):
    if isinstance(spec, str):
        return spec
    t = time.time()
    phase = (port * 7 + sum(map(ord, key))) % 60
    v = spec.get('base', 0) + spec.get('wobble', 0) * math.sin((t / 600.0) + phase)
    v += spec.get('grow', 0) * (t - START) / 86400.0
    lo, hi = spec.get('min'), spec.get('max')
    if lo is not None: v = max(lo, v)
    if hi is not None: v = min(hi, v)
    return str(int(round(v))) if spec.get('int') else f"{v:.4f}"

def frame(payload: bytes) -> bytes:
    return b'ZBXD\x01' + struct.pack('<II', len(payload), 0) + payload

async def handle(reader, writer, port, items):
    try:
        head = await asyncio.wait_for(reader.readexactly(13), 5)
        size = struct.unpack('<I', head[5:9])[0]
        body = (await asyncio.wait_for(reader.readexactly(size), 5)).decode('utf-8', 'replace')
        if body.lstrip().startswith('{'):
            req = json.loads(body)
            out = []
            for d in req.get('data', []):
                k = d.get('key', '')
                out.append({'value': value(items[k], port, k)} if k in items else {'error': 'Unsupported item key.'})
            writer.write(frame(json.dumps({'version': '7.0.0', 'variant': 2, 'data': out}).encode()))
        else:
            k = body.strip()
            writer.write(frame((value(items[k], port, k) if k in items else 'ZBX_NOTSUPPORTED\x00Unsupported item key.').encode()))
        await writer.drain()
    except Exception:
        pass
    finally:
        writer.close()

async def main():
    servers = []
    for port, host in CONF['hosts'].items():
        if host.get('down'):
            continue
        items = host['items']
        servers.append(await asyncio.start_server(lambda r, w, p=int(port), it=items: handle(r, w, p, it), '127.0.0.1', int(port)))
    print(f"mock agent: {len(servers)} hosts listening", flush=True)
    await asyncio.gather(*(s.serve_forever() for s in servers))

asyncio.run(main())
