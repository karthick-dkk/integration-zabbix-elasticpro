/** The Overview view of Client resources (ep-overview.js) in a DOM. Needs jsdom; skipped, saying so, otherwise. */
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
const client = (name, type, servers, es, storage) => ({ name, type, badge: '', warn: 80, high: 90, devices: { purchased: 120, seen: 100 },
  storage: { purchased: null, allocated: 100 * 2 ** 30, used: storage === null ? null : storage * 2 ** 30, usage: storage, class: storage >= 90 ? 'ep-high' : '' },
  families: { es }, servers });
const srv = (host, roles, status) => ({ host, hostid: 1, roles, status, cpu: 10, mem: 10, disk: 10, classes: { cpu: '', mem: '', disk: '' } });
const DATA = { families: [{ id: 'es', label: 'ES' }], clients: [
  client('acme', 'On-Prem', [srv('acme-1', ['ES Data'], 'up'), srv('acme-2', ['ES Data', 'Parser'], 'down')], fam(95, 8, 'ep-high'), 92),
  client('beta<b>', 'DI', [srv('beta-1', ['Engine'], 'up')], fam(20, 4), null)
] };
const HTML = `<div id="w"><div class="ep-panel" data-ep-panel="resources"><div class="ep-pt">
  <input data-ep-search><select data-ep-type-filter><option value="">All</option><option>DI</option><option>On-Prem</option></select>
  <button data-ep-view="table" aria-pressed="true">Table</button><button data-ep-view="overview" aria-pressed="false">Overview</button>
  <button data-ep-expand-all>Expand all</button></div>
  <div class="ep-res-scroll"><table></table></div><div class="ep-ov" hidden></div></div></div>`;

function setup(lib) {
  const dom = new lib.JSDOM(`<body>${HTML}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  w.eval(fs.readFileSync(path.join(HERE, 'assets/js/ep-overview.js'), 'utf8'));
  const widget = {}, body = w.document.getElementById('w');
  w.EpOverview.init(widget, body, DATA);
  return { w, body, widget };
}

test('Overview swaps the table for the panels, and back', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib);
  const pane = body.querySelector('.ep-ov'), table = body.querySelector('.ep-res-scroll');
  assert.ok(pane.hidden && !table.hidden, 'the table first');
  body.querySelector('[data-ep-view="overview"]').click();
  assert.ok(!pane.hidden && table.hidden && body.querySelector('[data-ep-expand-all]').hidden);
  assert.equal(body.querySelectorAll('.ep-ov-card').length, 2, 'all clients: a summary card each');
  assert.equal(body.querySelectorAll('.ep-ov-pie').length, 2, 'and a role pie each');
  body.querySelector('[data-ep-view="table"]').click();
  assert.ok(pane.hidden && !table.hidden);
});

test('one client: its tiles and gauges, coloured by the classes it was sent', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body, widget } = setup(lib);
  body.querySelector('[data-ep-view="overview"]').click();
  body.querySelector('.ep-ov-card[data-ep-ov-pick="acme"]').click();
  assert.equal(widget._epOv.client, 'acme');
  const tiles = body.querySelectorAll('.ep-ov-tile');
  assert.equal(tiles.length, 6);
  assert.ok(body.querySelector('.ep-ov-bad'), 'a server down turns its tile red');
  assert.match(body.querySelector('.ep-ov-bad').textContent, /acme-2/);
  assert.match(body.querySelector('.ep-ov-tile:nth-child(4) svg').innerHTML, /--ep-high/, 'ES CPU 95 % in the class it came with');
  const sel = body.querySelector('[data-ep-ov-client]');
  sel.value = ''; sel.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.equal(body.querySelectorAll('.ep-ov-card').length, 2, 'back to every client');
});

test('the Type filter and the search choose the clients; a figure not reported reads as such', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body } = setup(lib);
  body.querySelector('[data-ep-view="overview"]').click();
  const sel = body.querySelector('[data-ep-type-filter]');
  sel.value = 'DI'; sel.dispatchEvent(new w.Event('change'));
  assert.equal(body.querySelectorAll('.ep-ov-card').length, 1);
  assert.match(body.querySelector('.ep-ov-card').textContent, /—/, 'storage not reported: a dash, not 0 %');
  sel.value = ''; sel.dispatchEvent(new w.Event('change'));
  const q = body.querySelector('[data-ep-search]');
  q.value = 'acme-2'; q.dispatchEvent(new w.Event('input'));
  assert.deepEqual([...body.querySelectorAll('.ep-ov-card')].map((c) => c.dataset.epOvPick), ['acme'], 'a server name finds its client');
});

test('names are text, never markup', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib);
  body.querySelector('[data-ep-view="overview"]').click();
  assert.ok(!body.querySelector('.ep-ov b b'), 'no element made from a client name');
  assert.match(body.querySelector('.ep-ov').innerHTML, /beta&lt;b&gt;/);
});

test('the view hands the overview its data and the switch', () => {
  const v = fs.readFileSync(path.join(HERE, 'views/widget.view.php'), 'utf8');
  for (const hook of ["setVar('ep_overview'", "'data-ep-view', 'overview'", "'ep-ov ep-pscroll'"]) assert.ok(v.includes(hook), hook);
  const js = fs.readFileSync(path.join(HERE, 'assets/js/class.widget.js'), 'utf8');
  assert.ok(js.includes('EpOverview.init'));
});
