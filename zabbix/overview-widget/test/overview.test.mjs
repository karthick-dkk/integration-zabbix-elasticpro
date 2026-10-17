/** The Client overview cards (ep-overview.js) in a DOM. Needs jsdom; skipped, saying so, otherwise. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const HERE = path.resolve(import.meta.dirname, '..');
const ROOT = path.resolve(HERE, '..');
function jsdom() {
  for (const base of [ROOT, path.resolve(ROOT, '../..')]) {
    try { return createRequire(path.join(base, 'package.json'))('jsdom'); } catch { /* next */ }
  }
  return null;
}

const SEV = { 5: { name: 'Disaster', color: '#E45959' }, 4: { name: 'High', color: '#E97659' }, 3: { name: 'Average', color: '#FFA059' },
  2: { name: 'Warning', color: '#FFC859' }, 1: { name: 'Information', color: '#7499FF' }, 0: { name: 'Not classified', color: '#97AAB3' } };
const metric = (uid, name, now, extra = {}) => ({ uid, name, custom: false, display: 1, window: null, partial: null, why: null, url: `history.php?action=showgraph&itemids%5B%5D=${uid.length}`,
  now: { text: now, level: '', color: null, known: now !== '—', stale: false, age: 60 }, avg: { text: now, level: '' }, min: { text: now, level: '' }, max: { text: now, level: '' }, ...extra });
const client = (name, type, o = {}) => ({
  name, type, badge: '', status: 'active', maintenance: false, worst: null,
  link: 'zabbix.php?action=host.dashboard.view&hostid=101', dashboard: 'zabbix.php?action=host.dashboard.view&hostid=101',
  problems_url: 'zabbix.php?action=problem.view&filter_set=1&hostids%5B%5D=101',
  contract: null,
  problems: [5, 4, 3, 2].map((s) => ({ severity: s, n: 0, url: `zabbix.php?action=problem.view&severities%5B${s}%5D=${s}` })),
  metrics: [metric('storage', 'ES storage used', '61%')],
  used: { label: 'ES storage used of allocated', used: '600 GB', allocated: '1000 GB', pct: 61, pct_text: '61%', level: '', known: true,
    forecast: { text: '39 days', level: 'ok', days: 39, date: null }, why: null },
  roles: [{ id: 'es_data_hot', label: 'ES Data Hot', n: 2, down: 0, hosts: [{ name: `${name}-hot-1`, down: false }, { name: `${name}-hot-2`, down: false }] }],
  servers: { total: 2, down: 0 }, warnings: [], over: false, problems_total: 0, ...o
});
const data = (settings = {}, clients = null) => ({
  clients: clients || [
    client('acme', 'On-Prem', {
      worst: 5, contract: { date: '2027-03-31', days: 165, level: 'ok' },
      problems: [{ severity: 5, n: 1, url: 'zabbix.php?action=problem.view&severities%5B5%5D=5' }, { severity: 4, n: 0, url: 'zabbix.php?x' },
        { severity: 3, n: 0, url: 'zabbix.php?x' }, { severity: 2, n: 2, url: 'zabbix.php?x' }],
      metrics: [metric('storage', 'ES storage used', '92%', { now: { text: '92%', level: 'high', color: null, known: true, stale: false, age: 60 } }),
        metric('ingest', 'Ingest', '18.4k/s', { custom: true, partial: [2, 3], now: { text: '18.4k/s', level: 'warn', color: '#123456', known: true, stale: true, age: 900 } }),
        metric('gone', 'Not here', '—', { why: 'add the item no.such.key to these servers', avg: { text: '', level: '' }, min: { text: '', level: '' }, max: { text: '', level: '' } })],
      roles: [{ id: 'es_data_hot', label: 'ES Data Hot', n: 3, down: 1, hosts: [{ name: 'acme-hot-1', down: false }, { name: 'acme-hot-2', down: false }, { name: 'acme-hot-3', down: true }] }],
      servers: { total: 3, down: 1 },
      warnings: [{ level: 'warn', text: 'acme-hot-3: Zabbix agent unreachable' }, { level: 'info', text: 'Not here is not collected — add the item' }]
    }),
    client('beta<b>', 'DI', { contract: { date: '2026-10-31', days: 14, level: 'high' },
      used: { label: 'ES storage used of allocated', used: '300 GB', allocated: null, pct: null, pct_text: null, level: '', known: true, forecast: null,
        why: 'No forecast: allocated is not set — monitor the client\'s cluster host.' } })
  ],
  total: 2,
  summary: { clients: 2, servers: 5, down: 1, over: 1, problems: [{ severity: 5, n: 1 }, { severity: 4, n: 0 }, { severity: 3, n: 0 }, { severity: 2, n: 2 }] },
  metrics: [{ uid: 'storage', name: 'ES storage used' }, { uid: 'ingest', name: 'Ingest' }, { uid: 'gone', name: 'Not here' }],
  settings: { sections: ['header', 'problems', 'metrics', 'used', 'roles', 'warnings'], columns: 0, card_width: 340, density: 0, window: '7d',
    severities: SEV, hide_zero: true, link_severity: true, new_tab: false, used_show: 0, used_style: 0, contract_badge: true, forecast_days: 7,
    roles_style: 0, roles_servers: 0, down_red: true, types: ['DI', 'On-Prem'], ...settings }
});
const HTML = `<div id="w"><div class="ep-panel" data-ep-panel="overview"><div class="ep-pt">
  <input data-ep-search><select data-ep-type-filter><option value="">All</option><option>DI</option><option>On-Prem</option></select></div>
  <div class="ep-ov"></div></div></div>`;

function setup(lib, d = data()) {
  const dom = new lib.JSDOM(`<body>${HTML}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  w.eval(fs.readFileSync(path.join(HERE, 'assets/js/ep-overview.js'), 'utf8'));
  const widget = {}, body = w.document.getElementById('w');
  w.EpOverview.init(widget, body, d);
  return { w, body, widget };
}
const card = (body, name) => body.querySelector(`.ep-ov-card[data-ep-client="${name}"]`);

test('one card per client with the default sections in order, and no summary bar', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib);
  assert.equal(body.querySelectorAll('.ep-ov-card').length, 2);
  assert.ok(!body.querySelector('.ep-ov-summary'), 'the summary bar is off by default');
  const kids = [...card(body, 'acme').children].map((el) => el.className.split(' ')[0]);
  assert.deepEqual(kids, ['ep-ov-head', 'ep-ov-probs', 'ep-ov-metrics', 'ep-ov-used', 'ep-ov-roles', 'ep-ov-warn']);
  assert.match(body.querySelector('.ep-ov-grid').getAttribute('style'), /minmax\(min\(100%, 340px\), 1fr\)/, 'Auto columns at the card width');
});

test('the sections the widget turns on, in its order; columns; the summary bar', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib, data({ sections: ['summary', 'warnings', 'metrics'], columns: 3 }));
  assert.ok(body.querySelector('.ep-ov-summary'));
  assert.match(body.querySelector('.ep-ov-summary').textContent, /1 down/);
  const kids = [...card(body, 'acme').children].map((el) => el.className.split(' ')[0]);
  assert.deepEqual(kids, ['ep-ov-head', 'ep-ov-warn', 'ep-ov-metrics'], 'the name stays; the rest as ordered');
  assert.ok(!card(body, 'acme').querySelector('.ep-pill'), 'card header off: no type, no contract badge');
  assert.match(body.querySelector('.ep-ov-grid').getAttribute('style'), /repeat\(3, minmax\(0, 1fr\)\)/);
});

test('header: worst severity dot, type, contract badge with its level, or "No contract date"', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib);
  const a = card(body, 'acme');
  assert.match(a.querySelector('.ep-ov-dot').getAttribute('style'), /#E45959/);
  assert.match(a.querySelector('.ep-ov-contract').textContent, /^Contract to /);
  assert.ok(!/·/.test(a.querySelector('.ep-ov-contract').textContent), 'far off: no day count');
  const b = card(body, 'beta<b>');
  assert.ok(b.querySelector('.ep-ov-contract').classList.contains('ep-ov-lv-high'));
  assert.match(b.querySelector('.ep-ov-contract').textContent, /· 14 d$/);
  const { body: b2 } = setup(lib, data({}, [client('x', 'DI')]));
  assert.equal(b2.querySelector('.ep-ov-contract').textContent, 'No contract date');
});

test('problems: zero severities hidden by default, each a link to Problems; shown at 0 when asked', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  let { body } = setup(lib);
  const chips = [...card(body, 'acme').querySelectorAll('.ep-ov-probs .ep-ov-chip')];
  assert.deepEqual(chips.map((c) => c.textContent), ['Disaster 1', 'Warning 2']);
  assert.equal(chips[0].tagName, 'A');
  assert.match(chips[0].getAttribute('href'), /^zabbix\.php\?action=problem\.view/);
  assert.match(card(body, 'beta<b>').querySelector('.ep-ov-probs').textContent, /No open problems/);
  ({ body } = setup(lib, data({ hide_zero: false, link_severity: false, new_tab: true })));
  const all = [...card(body, 'acme').querySelectorAll('.ep-ov-probs .ep-ov-chip')];
  assert.equal(all.length, 4);
  assert.ok(all.every((c) => c.tagName === 'SPAN'), 'severity link off');
  assert.equal(card(body, 'acme').querySelector('.ep-ov-name').getAttribute('target'), '_blank', 'new tab');
});

test('metrics: level colour, custom tag, stale and partial marks, "not collected" — never 0', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib);
  const rows = [...card(body, 'acme').querySelectorAll('.ep-ov-metrics tbody tr')];
  assert.equal(rows.length, 3);
  assert.deepEqual([...card(body, 'acme').querySelectorAll('.ep-ov-metrics thead th')].map((th) => th.textContent), ['Metric', 'Now', '7d avg', 'min', 'max']);
  assert.ok(rows[0].querySelector('.ep-ov-val.ep-ov-lv-high'));
  assert.match(rows[1].querySelector('th').textContent, /custom/);
  assert.match(rows[1].querySelector('.ep-ov-val').getAttribute('style'), /#123456/, 'the threshold\'s own colour');
  assert.ok(rows[1].querySelector('.ep-ov-stale'));
  assert.match(rows[1].querySelector('.ep-ov-part').textContent, /2\/3/);
  assert.match(rows[1].querySelector('[title]').getAttribute('title'), /no new value for 15 min · from 2 of 3 servers/);
  const nc = rows[2].querySelector('.ep-ov-nc');
  assert.equal(nc.textContent, 'not collected');
  assert.match(nc.getAttribute('title'), /add the item no\.such\.key/);
  assert.ok(![...rows[2].querySelectorAll('td')].some((td) => /^0/.test(td.textContent.trim())), 'no 0 anywhere for it');
});

test('metric displays: bar, indicators and sparkline', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const m = [metric('a', 'A', '40%', { display: 2, bar: 40 }), metric('b', 'B', '70%', { display: 3, bar: 70 }), metric('c', 'C', '5', { display: 4, spark: [1, 3, 2, 5] })];
  const { body } = setup(lib, data({}, [client('x', 'DI', { metrics: m })]));
  const rows = body.querySelectorAll('.ep-ov-metrics tbody tr');
  assert.match(rows[0].querySelector('.ep-ov-barcell b').getAttribute('style'), /width:40%/);
  assert.equal(rows[1].querySelectorAll('.ep-ov-lcd i[style]').length, 7);
  assert.equal(rows[2].querySelector('svg polyline').getAttribute('points').split(' ').length, 4);
});

test('used of allocated: x of y with a bar and the forecast; allocated not set says so and names the fix', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  let { body } = setup(lib);
  const u = card(body, 'acme').querySelector('.ep-ov-used');
  assert.match(u.textContent, /600 GB of 1000 GB/);
  assert.match(u.querySelector('.ep-ov-ubar b').getAttribute('style'), /width:61%/);
  assert.match(u.querySelector('.ep-ov-fc').textContent, /Full in about 39 days at the 7-day growth rate/);
  const b = card(body, 'beta<b>').querySelector('.ep-ov-used');
  assert.match(b.textContent, /300 GB used · allocated not set/);
  assert.match(b.querySelector('.ep-ov-fc').textContent, /No forecast: allocated is not set/);
  ({ body } = setup(lib, data({ used_show: 2, used_style: 1 })));
  assert.match(card(body, 'acme').querySelector('.ep-ov-used').textContent, /600 GB of 1000 GB · 61%/);
  assert.ok(card(body, 'acme').querySelector('.ep-ov-gauge'));
});

test('servers by role: pills with down in red, names on hover, every server, stacked bar, donut', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  let { body } = setup(lib);
  const pills = [...card(body, 'acme').querySelectorAll('.ep-ov-rpill')];
  assert.deepEqual(pills.map((p) => p.textContent), ['ES Data Hot 2', 'ES Data Hot 1 down']);
  assert.ok(pills[1].classList.contains('ep-ov-rdown'));
  ({ body } = setup(lib, data({ roles_servers: 1, down_red: false })));
  const hover = [...card(body, 'acme').querySelectorAll('.ep-ov-rpill')];
  assert.equal(hover[1].getAttribute('title'), 'acme-hot-3');
  assert.ok(!hover[1].classList.contains('ep-ov-rdown'), 'down in red off');
  ({ body } = setup(lib, data({ roles_servers: 2 })));
  assert.equal(card(body, 'acme').querySelectorAll('.ep-ov-rpill').length, 3);
  ({ body } = setup(lib, data({ roles_style: 1 })));
  assert.ok(card(body, 'acme').querySelector('.ep-ov-stack'));
  ({ body } = setup(lib, data({ roles_style: 2 })));
  assert.ok(card(body, 'acme').querySelector('.ep-ov-donut'));
});

test('density Table: one row per client, the metrics as columns', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib, data({ density: 2 }));
  assert.equal(body.querySelectorAll('.ep-ov-table tbody tr').length, 2);
  assert.ok(!body.querySelector('.ep-ov-card'));
  assert.deepEqual([...body.querySelectorAll('.ep-ov-table thead th')].map((th) => th.textContent),
    ['Client', 'Type', 'Problems', 'ES storage used', 'Ingest', 'Not here', 'Used of allocated', 'Servers', 'Warnings']);
  assert.match(body.querySelector('.ep-ov-table tbody tr').textContent, /not collected/);
});

test('the Type filter and the search choose the clients, and keep the toolbar showing', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body, widget } = setup(lib);
  const sel = body.querySelector('[data-ep-type-filter]');
  sel.value = 'DI'; sel.dispatchEvent(new w.Event('change'));
  assert.deepEqual([...body.querySelectorAll('.ep-ov-card')].map((c) => c.dataset.epClient), ['beta<b>']);
  assert.ok(body.querySelector('.ep-panel').classList.contains('ep-pt-pinned'));
  sel.value = ''; sel.dispatchEvent(new w.Event('change'));
  const q = body.querySelector('[data-ep-search]');
  q.value = 'acme-hot-3'; q.dispatchEvent(new w.Event('input'));
  assert.deepEqual([...body.querySelectorAll('.ep-ov-card')].map((c) => c.dataset.epClient), ['acme'], 'a server name finds its client');
  assert.equal(widget._epOv.q, 'acme-hot-3', 'kept for the next refresh');
  q.value = 'nobody'; q.dispatchEvent(new w.Event('input'));
  assert.match(body.querySelector('.ep-ov').textContent, /No client matches the search/);
});

test('nothing to show says why', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  let { body } = setup(lib, { ...data(), clients: [], total: 3 });
  assert.match(body.querySelector('.ep-ov').textContent, /Only show clients/);
  ({ body } = setup(lib, { ...data(), clients: [], total: 0 }));
  assert.match(body.querySelector('.ep-ov').textContent, /No client matches the widget's settings/);
});

test('names are text, never markup, and only Zabbix pages are links', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const evil = client('x"><img src=x onerror=alert(1)>', 'DI', { link: 'javascript:alert(1)',
    warnings: [{ level: 'warn', text: '<script>alert(1)</script>' }],
    metrics: [metric('m', '<i>m</i>', '<b>1</b>', { url: 'https://evil.example/' })] });
  const { body } = setup(lib, data({}, [evil, client('beta<b>', 'DI')]));
  assert.ok(!body.querySelector('img, script, .ep-ov-metrics i'), 'no element made from a name');
  assert.match(body.querySelector('.ep-ov').innerHTML, /beta&lt;b&gt;/);
  assert.ok(![...body.querySelectorAll('a')].some((a) => /^(javascript|https?):/.test(a.getAttribute('href'))), 'no foreign link');
});
