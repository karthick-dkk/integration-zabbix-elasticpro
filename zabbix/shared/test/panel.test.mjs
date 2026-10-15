/**
 * ep-panel.js and ep-panel.css: the panel look and controls of Client resources and Client
 * capacity. The copies and the load order, then the controls in a DOM: search and the Type
 * filter deciding together, sorting that keeps a client's servers with it, what survives a
 * redraw, and what is read back from storage. Needs jsdom; skipped, saying so, otherwise.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const HERE = path.resolve(import.meta.dirname, '..');
const ROOT = path.resolve(HERE, '..');

function jsdom() {
  for (const base of [ROOT, path.resolve(ROOT, '../..')]) {
    try { return createRequire(path.join(base, 'package.json'))('jsdom'); } catch { /* next */ }
  }
  return null;
}

test('the panel files are copied to the two client reports, loaded after the shared scripts and before the widget', async () => {
  const { PANEL_JS, PANEL_CSS, PANEL_WIDGETS, SHARED } = await import(pathToFileURL(path.join(ROOT, 'sync-assets.mjs')).href);
  assert.deepEqual(PANEL_WIDGETS.sort(), ['capacity-widget', 'resources-widget']);
  for (const w of PANEL_WIDGETS) {
    for (const f of PANEL_JS) assert.equal(fs.readFileSync(path.join(ROOT, w, 'assets/js', f), 'utf8'), fs.readFileSync(path.join(HERE, f), 'utf8'), `${w}/${f} differs: node sync-assets.mjs`);
    for (const f of PANEL_CSS) assert.equal(fs.readFileSync(path.join(ROOT, w, 'assets/css', f), 'utf8'), fs.readFileSync(path.join(HERE, f), 'utf8'), `${w}/${f} differs: node sync-assets.mjs`);
    const m = JSON.parse(fs.readFileSync(path.join(ROOT, w, 'manifest.json'), 'utf8'));
    assert.deepEqual(m.assets.js.slice(0, SHARED.length + PANEL_JS.length), [...SHARED, ...PANEL_JS], `${w}: shared, then the panel`);
    assert.equal(m.assets.js[m.assets.js.length - 1], 'class.widget.js', `${w}: the widget's own class last`);
    assert.equal(m.assets.css[m.assets.css.length - 1], 'ep-panel.css', `${w}: the panel sheet loads last, so it decides the look`);
    const js = fs.readFileSync(path.join(ROOT, w, 'assets/js/class.widget.js'), 'utf8');
    assert.ok(js.indexOf('EpPanel.init') > js.indexOf('EpColumns.init'), `${w}: the panel starts after EpColumns`);
    const view = fs.readFileSync(path.join(ROOT, w, 'views/widget.view.php'), 'utf8');
    for (const hook of ["'ep-panel'", 'data-ep-search', 'data-ep-settings', 'data-ep-menu', 'ep-menu-pop', 'data-ep-columns', 'data-ep-type-filter']) assert.ok(view.includes(hook), `${w}: ${hook}`);
  }
  // The Volume report keeps its own look.
  const vol = JSON.parse(fs.readFileSync(path.join(ROOT, 'volume-widget/manifest.json'), 'utf8'));
  assert.ok(!vol.assets.js.includes('ep-panel.js') && !vol.assets.css.includes('ep-panel.css'));
});

test('the panel colours a status by the class WidgetView chose, never by a figure of its own', () => {
  const css = fs.readFileSync(path.join(HERE, 'ep-panel.css'), 'utf8');
  for (const cls of ['td.ep-warn', 'td.ep-high', 'td.ep-short', 'td.ep-unset']) assert.ok(css.includes(cls), cls);
  assert.ok(!/\b(80|90)\s*%/.test(css.replace(/\/\*[\s\S]*?\*\//g, '')), 'no threshold numbers in the sheet');
  const js = fs.readFileSync(path.join(HERE, 'ep-panel.js'), 'utf8');
  assert.ok(!/usage_warn|usage_high|>=\s*(80|90)\b/.test(js), 'no thresholds in the script');
});

const HTML = `<div id="w"><div class="ep-panel" data-ep-panel="resources">
  <div class="ep-pt"><span data-ep-count data-template="%n clients">3 clients</span>
    <label><input type="search" data-ep-search></label>
    <select data-ep-type-filter><option value="">All</option><option value="DI">DI</option><option value="On-Prem">On-Prem</option></select>
    <button data-ep-expand-all>Expand all</button>
    <button data-ep-settings>Table settings</button>
    <div class="ep-menu"><button data-ep-menu>⋮</button><div class="ep-menu-pop" hidden><button data-ep-columns>Columns</button><button data-ep-export="csv">CSV</button></div></div>
  </div>
  <div class="ep-pscroll"><table class="ep-res-table">
    <thead><tr><th class="ep-res-toggle"></th><th colspan="3">x</th></tr>
      <tr><th class="ep-res-toggle"></th><th class="ep-res-first">Client</th><th>Used</th><th>Storage</th></tr></thead>
    <tbody>
      <tr data-type="On-Prem" data-row="c0"><td class="ep-res-toggle"><button data-ep-expand="c0" aria-expanded="false">▸</button></td><td class="ep-res-first">acme</td><td>32.8 %</td><td>3.5 TB</td></tr>
      <tr class="ep-sub" data-parent="c0" hidden><td></td><td colspan="3"><table class="ep-servers"><tr><td>acme-es-1 10.0.0.9</td></tr></table></td></tr>
      <tr data-type="DI" data-row="c1"><td class="ep-res-toggle"><button data-ep-expand="c1" aria-expanded="false">▸</button></td><td class="ep-res-first">beta</td><td>84.2 %</td><td>500 GB</td></tr>
      <tr class="ep-sub" data-parent="c1" hidden><td></td><td colspan="3"><table class="ep-servers"><tr><td>beta-data-1 10.40.1.21</td></tr></table></td></tr>
      <tr data-type="DI" data-row="c2"><td class="ep-res-toggle"></td><td class="ep-res-first">gamma</td><td>—</td><td>not set</td></tr>
    </tbody></table></div></div></div>`;

function setup(lib, storage = {}) {
  const dom = new lib.JSDOM(`<body>${HTML}</body>`, { runScripts: 'outside-only', url: 'https://zabbix.example/zabbix.php' });
  const w = dom.window;
  for (const [k, v] of Object.entries(storage)) w.localStorage.setItem(k, v);
  w.CSS = { escape: (s) => String(s).replace(/"/g, '\\"') };  // jsdom has no CSS.escape; as in columns.test.mjs
  w.eval(fs.readFileSync(path.join(HERE, 'ep-columns.js'), 'utf8'));
  w.eval(fs.readFileSync(path.join(HERE, 'ep-panel.js'), 'utf8'));
  const widget = {};
  const body = w.document.getElementById('w');
  w.EpColumns.init(widget, body, null);
  w.EpPanel.init(widget, body, 'resources');
  return { w, body, widget };
}
const visible = (body) => [...body.querySelectorAll('tbody tr[data-type]')].filter((r) => !r.hidden).map((r) => r.cells[1].textContent);

test('a value sorts by its number and size unit; unknown sorts last', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w } = setup(lib);
  const v = (text) => { const td = w.document.createElement('td'); td.textContent = text; return w.EpPanel.sortValue(td); };
  assert.equal(v('32.8 %'), 32.8);
  assert.ok(v('3.5 TB') > v('900 GB'));
  assert.equal(v('12 days'), 12);
  assert.equal(v('4 ▼'), 4, 'the shortfall marker is not part of the number');
  for (const unknown of ['—', 'not set', 'not growing', '', 'no data']) assert.equal(v(unknown), null, unknown);
  assert.equal(v('acme'), 'acme');
});

test('search and the Type filter decide together, servers included, and the count follows', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body } = setup(lib);
  const q = body.querySelector('[data-ep-search]'), sel = body.querySelector('[data-ep-type-filter]');
  q.value = '10.40.1.21'; q.dispatchEvent(new w.Event('input'));
  assert.deepEqual(visible(body), ['beta'], 'an IP finds the client its server belongs to');
  q.value = ''; q.dispatchEvent(new w.Event('input'));
  sel.value = 'DI'; sel.dispatchEvent(new w.Event('change'));
  assert.deepEqual(visible(body), ['beta', 'gamma']);
  q.value = 'gam'; q.dispatchEvent(new w.Event('input'));
  assert.deepEqual(visible(body), ['gamma']);
  assert.equal(body.querySelector('[data-ep-count]').textContent, '1 clients');
});

test('sorting moves a client with its servers, says which way, and keeps unknown last both ways', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib);
  const th = body.querySelectorAll('thead tr')[1].cells[2];
  th.click();
  const order = () => [...body.querySelectorAll('.ep-res-table > tbody > tr')].map((r) => r.dataset.row || ('sub:' + r.dataset.parent));
  assert.deepEqual(order(), ['c1', 'sub:c1', 'c0', 'sub:c0', 'c2'], 'highest first, each server row right under its client');
  assert.equal(th.getAttribute('aria-sort'), 'descending');
  th.click();
  assert.deepEqual(order(), ['c0', 'sub:c0', 'c1', 'sub:c1', 'c2'], 'unknown stays last');
  assert.equal(th.getAttribute('aria-sort'), 'ascending');
  assert.equal(body.querySelectorAll('thead tr')[1].cells[0].getAttribute('aria-sort'), null, 'the expand column does not sort');
});

test('the search, the sort and the open clients survive the widget redrawing', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body, widget } = setup(lib);
  body.querySelector('[data-ep-expand="c1"]').click();
  const q = body.querySelector('[data-ep-search]');
  q.value = 'beta'; q.dispatchEvent(new w.Event('input'));
  body.querySelectorAll('thead tr')[1].cells[3].click();
  return new Promise((done) => setTimeout(() => {
    // Zabbix replaces the contents; the widget object stays.
    body.innerHTML = HTML.replace(/^<div id="w">|<\/div>$/g, '');
    w.EpColumns.init(widget, body, null);
    w.EpPanel.init(widget, body, 'resources');
    assert.equal(body.querySelector('[data-ep-search]').value, 'beta');
    assert.deepEqual(visible(body), ['beta']);
    assert.equal(body.querySelector('[data-ep-expand="c1"]').getAttribute('aria-expanded'), 'true');
    assert.equal(body.querySelector('tr[data-parent="c1"]').hidden, false);
    assert.equal(body.querySelectorAll('thead tr')[1].cells[3].getAttribute('aria-sort'), 'descending');
    done();
  }, 5));
});

test('what comes back from storage is checked before it is used', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const bad = JSON.stringify({ colors: { ok: '"><img src=x onerror=alert(1)>', warn: '#123456' }, density: 'huge', disks: 'x',
    hiddenSrv: ['ip', 'nope'], widths: { a: [10, 'x'], b: [100, 120] } });
  const { body } = setup(lib, { 'ep-panel:resources': bad });
  const root = body.querySelector('.ep-panel');
  assert.equal(root.style.getPropertyValue('--ep-ok'), '#73bf69', 'a colour that is not hex falls back');
  assert.equal(root.style.getPropertyValue('--ep-warn'), '#123456');
  assert.ok(root.classList.contains('ep-hide-ip') && !root.className.includes('nope'));
  assert.ok(!root.classList.contains('ep-comfortable'));
  body.querySelector('[data-ep-settings]').click();
  assert.ok(!body.querySelector('.ep-set').innerHTML.includes('onerror'), 'nothing from storage reaches the markup unchecked');
});

test('the menu opens and closes, and Table settings opens its drawer', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib);
  const pop = body.querySelector('.ep-menu-pop');
  body.querySelector('[data-ep-menu]').click();
  assert.equal(pop.hidden, false);
  body.querySelector('.ep-res-first').click();
  assert.equal(pop.hidden, true, 'a click elsewhere closes it');
  body.querySelector('[data-ep-settings]').click();
  assert.equal(body.querySelector('.ep-set').hidden, false);
  assert.ok(body.querySelector('.ep-set input[data-sc="ip"]'), 'a report with servers offers its server columns');
});
