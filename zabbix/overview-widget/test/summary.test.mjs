/**
 * The Summary view (ep-overview-summary.js) in a DOM — the 2.0 widget's own tests, kept as they were: the
 * Summary view is that widget restored. Needs jsdom; skipped, saying so, otherwise.
 */
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
const fam = (u, a, cls = '') => ({ servers: { requested: null, allocated: 3 }, cpu: { requested: 16, allocated: a, usage: u, class: cls }, mem: { requested: null, allocated: 64 * 2 ** 30, usage: 50, class: '' } });
const client = (name, type, servers, families, storage) => ({ name, type, badge: '', warn: 80, high: 90, devices: { purchased: 120, seen: 100 },
  storage: { purchased: null, allocated: 100 * 2 ** 30, used: storage === null ? null : storage * 2 ** 30, usage: storage, class: storage >= 90 ? 'ep-high' : '' },
  families, servers });
const srv = (host, roles, status) => ({ host, hostid: 1, roles, status, cpu: 10, mem: 10, classes: { cpu: '', mem: '' } });
const data = (settings = {}) => ({ families: [{ id: 'es', label: 'ES' }, { id: 'parser', label: 'Parser' }], clients: [
  client('acme', 'On-Prem', [srv('acme-1', ['ES Data'], 'up'), srv('acme-2', ['ES Data', 'Parser'], 'down')], { es: fam(95, 8, 'ep-high'), parser: fam(20, 4) }, 92),
  client('beta<b>', 'DI', [srv('beta-1', ['Engine'], 'up')], { es: fam(20, 4), parser: fam(85, 2, 'ep-warn') }, null)
], settings: { client: '', family: 'es', panels: { summary: true, used: true, roles: true }, types: ['DI', 'On-Prem'], ...settings } });
const HTML = `<div id="w"><div class="ep-panel" data-ep-panel="overview"><div class="ep-pt">
  <input data-ep-search><select data-ep-type-filter><option value="">All</option><option>DI</option><option>On-Prem</option></select></div>
  <div class="ep-ov ep-ov-sv"></div></div></div>`;

function setup(lib, d = data(), widget = {}) {
  const dom = new lib.JSDOM(`<body>${HTML}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  w.eval(fs.readFileSync(path.join(HERE, 'assets/js/ep-overview-summary.js'), 'utf8'));
  const body = w.document.getElementById('w');
  w.EpOverviewSummary.init(widget, body, d);
  return { w, body, widget };
}

test('every panel by default: a summary card and a role pie per client, and used of allocated', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib);
  assert.equal(body.querySelectorAll('.ep-ov-card').length, 2);
  assert.equal(body.querySelectorAll('.ep-ov-pie').length, 2);
  assert.ok([...body.querySelectorAll('.ep-ov-panel h4')].some((h) => /Used of allocated/.test(h.textContent)));
});

test('the settings choose the panels and the family the cards show', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  let { body } = setup(lib, data({ panels: { summary: true, used: false, roles: false } }));
  assert.equal(body.querySelectorAll('.ep-ov-pie').length, 0, 'Servers by role off');
  assert.ok(![...body.querySelectorAll('.ep-ov-panel h4')].some((h) => /Used of allocated/.test(h.textContent)), 'Used of allocated off');
  ({ body } = setup(lib, data({ family: 'parser' })));
  const card = body.querySelector('.ep-ov-card[data-ep-ov-pick="beta<b>"]');
  assert.match(card.textContent, /Parser CPU/, 'the cards follow Cards show');
  assert.match(card.innerHTML, /--ep-warn/, 'in the class the figure came with');
  ({ body } = setup(lib, data({ panels: { summary: false, used: false, roles: false } })));
  assert.match(body.querySelector('.ep-ov').textContent, /Every panel is turned off/);
});

test('a client named in the settings opens on its own tiles', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body, widget } = setup(lib, data({ client: 'acme' }));
  assert.equal(widget._epOvSummary.client, 'acme');
  assert.equal(body.querySelectorAll('.ep-ov-tile').length, 6);
  assert.ok(body.querySelector('.ep-ov-bad'), 'a server down turns its tile red');
  assert.match(body.querySelector('.ep-ov-tile:nth-child(4) svg').innerHTML, /--ep-high/, 'ES CPU 95 % in the class it came with');
  const sel = body.querySelector('[data-ep-ov-client]');
  sel.value = ''; sel.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.equal(body.querySelectorAll('.ep-ov-card').length, 2, 'back to every client');
});

test('the Type filter and the search choose the clients, and keep the toolbar showing', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body } = setup(lib);
  const sel = body.querySelector('[data-ep-type-filter]');
  sel.value = 'DI'; sel.dispatchEvent(new w.Event('change'));
  assert.equal(body.querySelectorAll('.ep-ov-card').length, 1);
  assert.match(body.querySelector('.ep-ov-card').textContent, /—/, 'storage not reported: a dash, not 0 %');
  assert.ok(body.querySelector('.ep-panel').classList.contains('ep-pt-pinned'));
  sel.value = ''; sel.dispatchEvent(new w.Event('change'));
  const q = body.querySelector('[data-ep-search]');
  q.value = 'acme-2'; q.dispatchEvent(new w.Event('input'));
  assert.deepEqual([...body.querySelectorAll('.ep-ov-card')].map((c) => c.dataset.epOvPick), ['acme'], 'a server name finds its client');
});

test('names are text, never markup', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib);
  assert.ok(!body.querySelector('.ep-ov b b'), 'no element made from a client name');
  assert.match(body.querySelector('.ep-ov').innerHTML, /beta&lt;b&gt;/);
});

test('the controller tells the widget which view to draw', () => {
  const cls = fs.readFileSync(path.join(HERE, 'assets/js/class.widget.js'), 'utf8');
  assert.match(cls, /data\.view === 'summary' \? window\.EpOverviewSummary : window\.EpOverview/);
});

/* ------------------------------ the Summary view's own settings (2.1.1) ------------------------------ */

const ISO = { legacy: false, panels: { summary: true, chart: true, used: true, roles: true },
  layout: [{ id: 'summary', width: 'full' }, { id: 'chart', width: 'half' }, { id: 'used', width: 'quarter' }, { id: 'roles', width: 'quarter' }],
  cards: { tiles: ['up', 'down', 'devices'], bars: ['cpu', 'mem', 'storage'], status: ['down', 'high', 'warn', 'short', 'nodata'], columns: 0 },
  used: { groups: ['cpu', 'mem', 'storage'], sort: 'name', bar: 'segmented' }, roles: { style: 'donut', count: true, percent: true },
  chart: { style: 'line', height: 230, legend: ['mean', 'min', 'max', 'last'], color: true, isolate: true, point: 'average' } };
const stat = (v, level = '', color = null) => ({ v, text: `${v}%`, level, color });
const legend = (mean, min, max, last, level = '') => ({ mean: stat(mean, level), min: stat(min), max: stat(max, level), last: stat(last, level) });
const line = (from, step, values) => values.map((v, i) => [from + i * step, v]);
const T0 = 1700000000, SPAN = 21600;
const chart = (over = {}) => ({ metric: { id: 'cpu', label: 'ES CPU', units: '%' }, metrics: [{ id: 'cpu', label: 'ES CPU' }, { id: 'mem', label: 'ES memory' }],
  configured: { metric: 'cpu', seconds: SPAN }, period: { key: '6h', seconds: SPAN, from: T0, till: T0 + SPAN, source: 'history' },
  quick: [{ key: '1h', seconds: 3600 }, { key: '6h', seconds: 21600 }, { key: '24h', seconds: 86400 }, { key: '7d', seconds: 604800 }],
  percent: true, lines: { warn: 80, high: 90 }, total: 2,
  clients: [{ name: 'acme', i: 0, legend: legend(80.1, 69.6, 89.2, 78.8, 'warn'), points: line(T0, 1800, [70, 75, 80, 85, 89, 80, 79]) },
    { name: 'beta<b>', i: 1, legend: null, points: [] }], ...over });
const newData = (settings = {}, chartOver = {}) => {
  const d = data({ ...ISO, ...settings });
  d.chart = chartOver === null ? null : chart(chartOver);
  d.clients.forEach((c) => { c.status = { down: 0, high: 0, warn: 0, short: 0, nodata: 0 }; c.problems = null; c.devices = { ...c.devices, usage: 83.3, class: '' }; });
  return d;
};
const html = (body) => body.querySelector('.ep-ov').innerHTML;
const panelOf = (body) => body.querySelector('.ep-ov-chart');
const legacyFixture = () => JSON.parse(fs.readFileSync(path.join(HERE, 'test/fixtures/summary-legacy-2.1.0.json'), 'utf8'));

test('a widget saved before the Summary view had settings draws byte for byte as the 2.1.0 widget did', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const want = legacyFixture();
  const variants = { default: {}, 'one client': { client: 'acme' }, 'parser family': { family: 'parser' }, 'summary only': { panels: { summary: true, used: false, roles: false } },
    'used and roles': { panels: { summary: false, used: true, roles: true } } };
  for (const [name, s] of Object.entries(variants)) assert.equal(html(setup(lib, data(s)).body), want[name], name);
});

test('…and so does one the controller marks legacy, whatever else it sends', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib, newData({ legacy: true, panels: { summary: true, chart: false, used: true, roles: true } }, null));
  assert.equal(html(body), legacyFixture().default);
  assert.ok(!body.querySelector('.ep-ov-lay') && !body.querySelector('.ep-ov-chart') && !body.querySelector('.ep-ov-st'), 'no layout grid, no chart, no status line');
});

test('panels are laid out in the order and widths the widget keeps, and a panel turned off is not drawn', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  let { body } = setup(lib, newData());
  const widthOf = (p) => [...p.classList].find((c) => c.startsWith('ep-ov-w-'));
  assert.deepEqual([...body.querySelectorAll('.ep-ov-lay > section')].map((p) => [p.querySelector('h4').firstChild.textContent.trim(), widthOf(p)]),
    [['Summary by client', 'ep-ov-w-full'], ['ES CPU by client', 'ep-ov-w-half'], ['Used of allocated', 'ep-ov-w-quarter'], ['Servers by role', 'ep-ov-w-quarter']]);
  ({ body } = setup(lib, newData({ layout: [{ id: 'roles', width: 'third' }, { id: 'summary', width: 'bogus' }] })));
  assert.deepEqual([...body.querySelectorAll('.ep-ov-lay > section')].map(widthOf), ['ep-ov-w-third', 'ep-ov-w-full'], 'an unknown width is full');
  ({ body } = setup(lib, newData({ layout: [] })));
  assert.match(body.querySelector('.ep-ov').textContent, /Every panel is turned off/);
  ({ body } = setup(lib, newData({}, null)));
  assert.equal(body.querySelectorAll('.ep-ov-chart').length, 0, 'no chart data, no chart panel');
});

test('summary cards follow the tiles, bars, status line and columns of the settings', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const d = newData({ cards: { ...ISO.cards, tiles: ['up', 'problems'], bars: ['storage'], status: ['down', 'warn', 'nodata'], columns: 3 } });
  d.clients[0].problems = 4; d.clients[0].status = { down: 1, high: 2, warn: 1, short: 0, nodata: 2 };
  const { body } = setup(lib, d);
  const card = body.querySelector('.ep-ov-card[data-ep-ov-pick="acme"]');
  assert.deepEqual([...card.querySelectorAll('.ep-ov-trio > span > small')].map((s) => s.textContent), ['servers up', 'open problems']);
  assert.match(card.querySelector('.ep-ov-trio').getAttribute('style'), /repeat\(2,1fr\)/);
  assert.equal(card.querySelectorAll('.ep-ov-m').length, 1, 'one bar: storage');
  assert.match(card.querySelector('.ep-ov-m').textContent, /ES storage/);
  assert.equal(card.querySelector('.ep-ov-st').textContent, '1 down · 1 warn · 2 no data', 'only the items the widget asks for, only when there is something to say');
  assert.ok(card.querySelector('.ep-ov-st-high'), 'a server down makes it high');
  assert.equal(card.querySelector('.ep-ov-trio > span:last-child b').textContent, '4');
  const other = body.querySelector('.ep-ov-card[data-ep-ov-pick="beta<b>"]');
  assert.equal(other.querySelector('.ep-ov-st').textContent, 'OK', 'nothing to say is OK, not a blank');
  assert.equal(other.querySelector('.ep-ov-trio > span:last-child b').textContent, '—', 'open problems not read is a dash, not 0');
  assert.match(body.querySelector('.ep-ov-cards').getAttribute('style'), /repeat\(3,minmax\(0,1fr\)\)/);
  const none = setup(lib, newData({ cards: { ...ISO.cards, tiles: [], bars: [], status: [] } })).body;
  assert.ok(!none.querySelector('.ep-ov-trio') && !none.querySelector('.ep-ov-m') && !none.querySelector('.ep-ov-st'), 'everything off is a header only');
});

test('Used of allocated: the groups ticked, rows by name or value, a segmented or a solid bar; unknown is a dash', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const usedPanel = (body) => [...body.querySelectorAll('.ep-ov-panel')].find((p) => /Used of allocated/.test(p.querySelector('h4').textContent));
  const used = (s, d = newData({ used: { ...ISO.used, ...s } })) => usedPanel(setup(lib, d).body);
  let p = used({});
  assert.deepEqual([...p.querySelectorAll('h5')].map((h) => h.textContent), ['ES CPU', 'Parser CPU', 'ES memory', 'Parser memory', 'ES storage']);
  assert.ok(p.querySelector('.ep-ov-lcd') && !p.querySelector('.ep-ov-solid'));
  p = used({ groups: ['devices', 'storage'], bar: 'solid' });
  assert.deepEqual([...p.querySelectorAll('h5')].map((h) => h.textContent), ['ES storage', 'Devices seen of purchased']);
  assert.ok(p.querySelector('.ep-ov-solid') && !p.querySelector('.ep-ov-lcd'));
  assert.match(p.textContent, /83\.3%/);
  const names = (el) => [...el.querySelectorAll('.ep-ov-bg')].map((r) => r.firstChild.textContent);
  assert.deepEqual(names(used({ groups: ['storage'], sort: 'name' })), ['acme', 'beta<b>']);
  const d = newData({ used: { ...ISO.used, groups: ['storage'], sort: 'value' } });
  d.clients[0].storage.usage = null; d.clients[1].storage.usage = 99; d.clients[1].storage.class = 'ep-high';
  p = usedPanel(setup(lib, d).body);
  assert.deepEqual(names(p), ['beta<b>', 'acme'], 'highest first, unknown last');
  assert.equal(p.querySelectorAll('.ep-ov-bg em')[1].textContent, '—');
  assert.match(used({ groups: [] }).textContent, /No group is turned on/);
});

test('Servers by role: donut, bar or pills, with the count and percent the widget asks for', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const roles = (s) => setup(lib, newData({ roles: { ...ISO.roles, ...s } })).body;
  let b = roles({});
  assert.equal(b.querySelectorAll('.ep-ov-pie svg').length, 2);
  assert.ok(b.querySelector('.ep-ov-pie li b') && b.querySelector('.ep-ov-pie li em'));
  b = roles({ style: 'bar', percent: false });
  assert.equal(b.querySelectorAll('.ep-ov-rbar').length, 2);
  assert.ok(!b.querySelector('.ep-ov-pie svg') && !b.querySelector('.ep-ov-pie li em') && b.querySelector('.ep-ov-pie li b'));
  b = roles({ style: 'pills', count: false });
  assert.ok(b.querySelector('.ep-ov-rp') && !b.querySelector('.ep-ov-rp b') && b.querySelector('.ep-ov-rp em'));
  assert.match(b.querySelector('.ep-ov-rpills').textContent, /ES Data/);
});

/* ------------------------------------------ the usage chart ------------------------------------------ */

test('chart: a line per client in the series colours in order, the legend table, and "no data" with no line', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body } = setup(lib, newData());
  const p = panelOf(body);
  assert.equal(p.querySelector('h4').textContent, 'ES CPU by client');
  assert.equal(p.querySelectorAll('svg polyline').length, 1, 'acme only: beta has no data');
  assert.equal(p.querySelector('svg polyline').getAttribute('stroke'), 'var(--ep-s1)', 'the palette\'s first series colour');
  assert.equal(w.EpOverviewSummary.SERIES, 8);
  const rows = [...p.querySelectorAll('.ep-ov-legend tbody tr')];
  assert.equal(rows.length, 2);
  assert.deepEqual([...rows[0].children].map((c) => c.textContent), ['acme', '80.1%', '69.6%', '89.2%', '78.8%']);
  assert.deepEqual([...p.querySelectorAll('.ep-ov-legend thead th')].map((c) => c.textContent), ['Client', 'Mean', 'Min', 'Max', 'Last']);
  assert.match(rows[0].children[1].getAttribute('style'), /--ep-warn/, 'a value over Warn is coloured');
  assert.equal(rows[0].children[2].getAttribute('style'), null, 'under Warn is not');
  assert.equal(rows[1].textContent, 'beta<b>no data', 'no data is said, in words, not as zeros');
  assert.equal(rows[1].querySelector('td').getAttribute('colspan'), '4');
  assert.match(p.querySelector('.ep-ov-note').textContent, /Dashed lines: Warn 80% and High 90%\./);
  assert.match(p.querySelector('.ep-ov-note').textContent, /from Zabbix history/);
  assert.equal(p.querySelectorAll('svg line[stroke-dasharray]').length, 2, 'Warn and High');
  assert.equal(p.querySelectorAll('.ep-ov-legend b').length, 0, 'a name is text, not an element');
  assert.equal(rows[1].querySelector('th').textContent, 'beta<b>');
});

test('chart: 0–100 for a percentage, a round automatic axis otherwise; Warn/High lines left off when the widget says so or they are out of range', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const ticks = (el) => [...el.querySelectorAll('svg g text')].map((x) => x.textContent);
  let p = panelOf(setup(lib, newData()).body);
  assert.deepEqual(ticks(p).slice(0, 5), ['0%', '25%', '50%', '75%', '100%']);
  assert.deepEqual(ticks(p).slice(5), ['−6h', '−3h', 'now']);
  p = panelOf(setup(lib, newData({}, { percent: false, metric: { id: 'm:i', label: 'Ingest', units: '/s' }, lines: { warn: 5000, high: 99999999 },
    clients: [{ name: 'acme', i: 0, legend: legend(1, 1, 1, 1), points: line(T0, 1800, [1200, 3400, 2100, 1800]) }] })).body);
  const labels = ticks(p).slice(0, 5);
  assert.equal(labels[0], '0/s');
  assert.equal(labels[4], '8k/s', 'a round top that holds the data (3400) and the Warn line (5000)');
  assert.equal(labels[2], '4k/s');
  assert.equal(p.querySelectorAll('svg line[stroke-dasharray]').length, 1, 'a High line far above the data would flatten it and is not drawn');
  p = panelOf(setup(lib, newData({}, { lines: { warn: null, high: null } })).body);
  assert.equal(p.querySelectorAll('svg line[stroke-dasharray]').length, 0);
  assert.ok(!/Dashed lines/.test(p.querySelector('.ep-ov-note').textContent));
});

test('chart: quick buttons and the metric switch ask for a refresh with the choice; the pressed one is marked', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  let refreshed = 0;
  const widget = { _startUpdating() { refreshed++; } };
  const { w, body } = setup(lib, newData(), widget);
  const buttons = [...panelOf(body).querySelectorAll('[data-ep-ov-period]')];
  assert.deepEqual(buttons.map((b) => [b.textContent, b.getAttribute('aria-pressed')]), [['1h', 'false'], ['6h', 'true'], ['24h', 'false'], ['7d', 'false']]);
  buttons[2].dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  assert.equal(widget._epOvSummary.chart.period, 86400);
  assert.equal(refreshed, 1);
  const sel = panelOf(body).querySelector('[data-ep-ov-metric]');
  assert.deepEqual([...sel.options].map((o) => o.value), ['cpu', 'mem']);
  sel.value = 'mem'; sel.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.equal(widget._epOvSummary.chart.metric, 'mem');
  assert.equal(refreshed, 2);
  const bare = panelOf(setup(lib, newData({}, { quick: [], metrics: [] })).body);
  assert.ok(!bare.querySelector('[data-ep-ov-period]') && !bare.querySelector('select'), 'without buttons or a switch the panel offers neither');
});

test('chart: the request carries the viewer\'s choice, until the widget\'s own setting changes', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const dom = new lib.JSDOM('<body></body>', { runScripts: 'outside-only' });
  dom.window.eval('class CWidget { getUpdateRequestData() { return { fields: { a: 1 } }; } setContents() {} }'
    + fs.readFileSync(path.join(HERE, 'assets/js/class.widget.js'), 'utf8') + ';window.W = WidgetEpOverview;');
  const widget = new dom.window.W();
  const fields = () => JSON.parse(JSON.stringify(widget.getUpdateRequestData().fields));
  assert.deepEqual(fields(), { a: 1 }, 'nothing chosen, nothing added');
  widget._epOvSummary = { chart: { metric: 'mem', period: 3600 } };
  assert.deepEqual(fields(), { a: 1, chart_view_metric: 'mem', chart_view_period: 3600 });
  widget._epOvSummary = { chart: { metric: '', period: 86400 } };
  assert.deepEqual(fields(), { a: 1, chart_view_metric: '', chart_view_period: 86400 });
  // A new draw with a reconfigured chart forgets what was pressed; the same configuration keeps it.
  const { w, body } = setup(lib, newData());
  const wd = { _epOvSummary: { client: '', q: '', type: '', chart: { metric: 'mem', period: 3600, iso: '', conf: JSON.stringify({ metric: 'cpu', seconds: 7200 }) } } };
  w.EpOverviewSummary.init(wd, body, newData());
  assert.equal(wd._epOvSummary.chart.metric, '');
  assert.equal(wd._epOvSummary.chart.period, 0);
  wd._epOvSummary.chart.metric = 'mem';
  w.EpOverviewSummary.init(wd, body, newData());
  assert.equal(wd._epOvSummary.chart.metric, 'mem', 'the same setting keeps the press');
});

test('chart: click a client to show only it, click again for all; off when the widget says so', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const two = { clients: [{ name: 'acme', i: 0, legend: legend(50, 40, 60, 55), points: line(T0, 1800, [40, 50, 60, 55]) },
    { name: 'beta<b>', i: 1, legend: legend(20, 10, 30, 25), points: line(T0, 1800, [10, 20, 30, 25]) }] };
  const { w, body } = setup(lib, newData({}, two));
  const strokes = () => [...panelOf(body).querySelectorAll('svg polyline')].map((l) => l.getAttribute('stroke'));
  assert.equal(strokes().length, 2);
  const who = (n) => [...panelOf(body).querySelectorAll('.ep-ov-iso')].find((b) => b.dataset.epOvIso === n);
  who('beta<b>').dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  assert.deepEqual(strokes(), ['var(--ep-s2)'], 'only beta, in beta\'s own colour');
  assert.equal(who('beta<b>').getAttribute('aria-pressed'), 'true');
  assert.ok(panelOf(body).querySelector('tr.ep-ov-dim'), 'the others are dimmed in the legend');
  assert.equal(panelOf(body).querySelectorAll('.ep-ov-legend tbody tr').length, 2, 'the legend still lists everyone');
  panelOf(body).querySelector('svg polyline').dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  assert.equal(strokes().length, 2, 'clicking the line again shows all');
  who('acme').dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  who('acme').dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  assert.equal(strokes().length, 2, 'click, click: all again');
  const off = setup(lib, newData({ chart: { ...ISO.chart, isolate: false } }, two)).body;
  assert.ok(!off.querySelector('.ep-ov-iso') && !/Click a client/.test(off.querySelector('.ep-ov-chart .ep-ov-note').textContent));
});

test('chart: legend columns, colouring and the order the controller gave', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const p = panelOf(setup(lib, newData({ chart: { ...ISO.chart, legend: ['max', 'last'], color: false } })).body);
  assert.deepEqual([...p.querySelectorAll('.ep-ov-legend thead th')].map((c) => c.textContent), ['Client', 'Max', 'Last']);
  assert.ok(![...p.querySelectorAll('.ep-ov-legend td')].some((td) => td.getAttribute('style')), 'no colours when the widget does not ask for them');
  assert.equal(p.querySelector('.ep-ov-nodata').getAttribute('colspan'), '2');
  const order = panelOf(setup(lib, newData({}, { clients: [{ name: 'beta<b>', i: 1, legend: legend(9, 9, 9, 9), points: line(T0, 1800, [9, 9]) },
    { name: 'acme', i: 0, legend: legend(1, 1, 1, 1), points: line(T0, 1800, [1, 1]) }] })).body);
  assert.deepEqual([...order.querySelectorAll('.ep-ov-legend tbody th')].map((th) => th.textContent), ['beta<b>', 'acme'], 'the controller sorted it');
});

test('chart: a colour from the server is used only when it is #RRGGBB; names, labels and units are text', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const evil = '<img src=x onerror=alert(1)>';
  const bad = { clients: [{ name: evil, i: 0, points: line(T0, 1800, [1, 2]), legend: { mean: { v: 1, text: evil, level: '', color: 'red;" onmouseover="x' }, min: { v: 1, text: '1', level: '', color: '#12AB9f' },
    max: { v: 1, text: '1', level: '', color: '#12AB9' }, last: { v: 1, text: '1', level: 'high', color: 'javascript:1' } } }],
  metric: { id: 'cpu', label: evil, units: evil }, metrics: [{ id: 'cpu', label: evil }, { id: 'x', label: 'x' }], percent: false };
  const d = newData({}, bad);
  d.clients[0].name = evil;
  const p = panelOf(setup(lib, d).body);
  assert.equal(p.querySelectorAll('img').length, 0, 'no element made from a name, a label or a figure');
  const cells = [...p.querySelectorAll('.ep-ov-legend tbody td')];
  assert.equal(cells[0].getAttribute('style'), null, 'a colour that is not #RRGGBB is not used');
  assert.equal(cells[1].getAttribute('style'), 'color:#12AB9f', 'one that is, is');
  assert.equal(cells[2].getAttribute('style'), null, 'five digits are not');
  assert.match(cells[3].getAttribute('style'), /--ep-high/, 'with no colour the level colours it');
  assert.ok(![...p.querySelectorAll('*')].some((el) => el.hasAttribute('onmouseover') || el.hasAttribute('onerror')), 'no attribute injected');
});

test('chart: a gap in a line breaks it; one point is a dot; area and stacked area are filled', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const gap = [[T0, 10], [T0 + 600, 12], [T0 + 1200, 11], [T0 + 1800, 13], [T0 + 15000, 40], [T0 + 15600, 42]];
  const clients = [{ name: 'acme', i: 0, legend: legend(1, 1, 1, 1), points: gap }, { name: 'beta<b>', i: 1, legend: legend(1, 1, 1, 1), points: [[T0 + 3000, 30]] }];
  let p = panelOf(setup(lib, newData({}, { clients })).body);
  assert.equal(p.querySelectorAll('svg polyline').length, 2, 'acme in two pieces, not one line across the gap');
  assert.equal(p.querySelectorAll('svg circle').length, 1, 'beta\'s only point is a dot');
  p = panelOf(setup(lib, newData({ chart: { ...ISO.chart, style: 'area' } }, { clients })).body);
  assert.equal(p.querySelectorAll('svg polygon').length, 2, 'a fill under each piece');
  assert.equal(p.querySelectorAll('svg polyline').length, 2);
  p = panelOf(setup(lib, newData({ chart: { ...ISO.chart, style: 'stacked' } }, { clients })).body);
  assert.equal(p.querySelectorAll('svg polygon').length, 2, 'one band per client');
  p = panelOf(setup(lib, newData({ chart: { ...ISO.chart, style: 'stacked' } }, { clients, lines: { warn: null, high: null } })).body);
  assert.equal([...p.querySelectorAll('svg g text')][4].textContent, '50%', 'stacked percentages are scaled to their sum, not held to 0–100');
  assert.equal(p.querySelectorAll('svg polyline').length, 0);
});

test('chart: nothing to draw says so, and the search and the type narrow the chart as the rest', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  let p = panelOf(setup(lib, newData({}, { clients: [{ name: 'acme', i: 0, legend: null, points: [] }] })).body);
  assert.match(p.textContent, /No data in this period/);
  assert.equal(p.querySelectorAll('svg').length, 0);
  p = panelOf(setup(lib, newData({}, { clients: [] })).body);
  assert.match(p.textContent, /No client to draw/);
  const { w, body } = setup(lib, newData());
  const sel = body.querySelector('[data-ep-type-filter]');
  sel.value = 'DI'; sel.dispatchEvent(new w.Event('change'));
  assert.equal(panelOf(body).querySelectorAll('.ep-ov-legend tbody tr').length, 1, 'only the DI client is in the legend');
  assert.equal(panelOf(body).querySelectorAll('svg polyline').length, 0);
  const many = panelOf(setup(lib, newData({}, { total: 9 })).body);
  assert.match(many.querySelector('.ep-ov-note').textContent, /Showing 2 of 9 clients/);
});

test('chart: clients cycle through the palette\'s eight series colours, and garbage is the first, never undefined', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const S = setup(lib).w.EpOverviewSummary;
  assert.equal(S.seriesColor(0), 'var(--ep-s1)');
  assert.equal(S.seriesColor(7), 'var(--ep-s8)');
  assert.equal(S.seriesColor(8), 'var(--ep-s1)');
  assert.equal(S.seriesColor(9), 'var(--ep-s2)');
  assert.equal(S.seriesColor(-1), 'var(--ep-s1)');
  assert.equal(S.seriesColor('x'), 'var(--ep-s1)');
  assert.ok(!/#[0-9a-f]{6}/i.test(fs.readFileSync(path.join(HERE, 'assets/js/ep-overview-summary.js'), 'utf8').split('\n').filter((l) => /seriesColor|SERIES/.test(l)).join('\n')), 'no colour list in the chart code');
});
