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

function setup(lib, d = data()) {
  const dom = new lib.JSDOM(`<body>${HTML}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  w.eval(fs.readFileSync(path.join(HERE, 'assets/js/ep-overview-summary.js'), 'utf8'));
  const widget = {}, body = w.document.getElementById('w');
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
