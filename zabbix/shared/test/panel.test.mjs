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

test('the panel files are copied to the three table reports, loaded after the shared scripts and before the widget', async () => {
  const { PANEL_JS, PANEL_CSS, PANEL_WIDGETS, SHARED } = await import(pathToFileURL(path.join(ROOT, 'sync-assets.mjs')).href);
  assert.deepEqual(PANEL_WIDGETS.sort(), ['capacity-widget', 'resources-widget', 'volume-widget']);
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
    // The panel, its toolbar and its table are ColumnTable's, the same for each.
    const table = fs.readFileSync(path.join(ROOT, w, 'includes/ColumnTable.php'), 'utf8');
    for (const hook of ["'ep-panel'", 'data-ep-search', 'data-ep-menu', 'ep-menu-pop', 'data-ep-columns', 'data-ep-type-filter']) assert.ok(table.includes(hook), `${w}: ${hook}`);
    assert.ok(view.includes('ColumnTable::panel(') && view.includes('ColumnTable::toolbar('), `${w}: drawn with ColumnTable`);
    assert.ok(!view.includes('data-ep-settings') && !table.includes('data-ep-settings'), `${w}: no per-browser Table settings`);
    const controller = fs.readFileSync(path.join(ROOT, w, 'actions/WidgetView.php'), 'utf8');
    assert.ok(view.includes('paletteStyle(') || (controller.includes('Settings::paletteStyle(') && view.includes("$data['palette']")),
      `${w}: the panel carries ElasticPro Settings' palette`);
  }
  // None of them keeps anything of the look in the browser: it is the widget's own setting since 2.1.1.
  for (const w of PANEL_WIDGETS) {
    assert.match(fs.readFileSync(path.join(ROOT, w, 'assets/js/class.widget.js'), 'utf8'), /EpPanel\.init\(this, this\._body, '[a-z]+', \{view:/, `${w}: the panel is started with the widget's view settings`);
  }
});

test('the panel follows the Zabbix theme: light by default, dark under Dark and high-contrast dark', () => {
  const css = fs.readFileSync(path.join(HERE, 'ep-panel.css'), 'utf8');
  assert.match(css, /\[theme="dark-theme"\] \.ep-panel, \[theme="hc-dark"\] \.ep-panel \{/);
  const block = (sel) => css.slice(css.indexOf(sel), css.indexOf('}', css.indexOf(sel)));
  const light = block('.ep-panel {\n\t--ep-ok'), dark = block('[theme="dark-theme"] .ep-panel');
  const names = (b) => [...b.matchAll(/(--ep-[a-z0-9-]+):/g)].map((m) => m[1]).sort();
  assert.deepEqual(names(dark), names(light), 'every colour has a value in both themes');
  const rules = css.slice(css.indexOf('/* ---- toolbar ---- */')).replace(/\/\*[\s\S]*?\*\//g, '');
  assert.deepEqual(rules.match(/#[0-9a-f]{6}\b/gi) || [], [], 'no colour outside the two theme blocks');
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
  assert.equal(v('+3 %'), 3, 'a change carries its sign');
  assert.equal(v('18.4k/s'), 18400, 'short numbers');
  assert.ok(v('2 months') > v('45 days'), 'months are longer than days');
  assert.equal(v('under a day'), 0);
  const ds = w.document.createElement('td'); ds.textContent = 'allocated not set'; ds.dataset.sort = '';
  assert.equal(w.EpPanel.sortValue(ds), null, 'the server\'s figure wins: unknown');
  ds.dataset.sort = '82.5'; assert.equal(w.EpPanel.sortValue(ds), 82.5);
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

test('the browser holds only dragged column widths: what comes back from storage is checked, and nothing of the look is read', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  // Colours, row height and hidden server columns were kept here before the widgets took them as settings: ignored now.
  const old = JSON.stringify({ colors: { ok: '"><img src=x onerror=alert(1)>', warn: '#123456' }, density: 'comfortable', hiddenSrv: ['ip', 'nope'],
    widths: { a: [10, 'x'], b: [100, 120] } });
  const { w, body } = setup(lib, { 'ep-panel:resources': old });
  const root = body.querySelector('.ep-panel');
  assert.equal(root.style.getPropertyValue('--ep-ok'), '', 'no colour of the viewer\'s');
  assert.ok(!root.className.includes('ep-hide-') && !root.classList.contains('ep-comfortable'), 'no row height or hidden column of the viewer\'s');
  assert.equal(JSON.stringify(w.EpPanel.prefs('resources')), '{"widths":{"b":[100,120]}}', 'only widths that are numbers survive');
  assert.ok(!body.querySelector('.ep-set') && !w.EpPanel.settings, 'there is no Table settings drawer');
  w.EpPanel.savePrefs('resources', { widths: { k: [90] }, colors: { ok: '#000000' } });
  assert.deepEqual(JSON.parse(w.localStorage.getItem('ep-panel:resources')), { widths: { k: [90] } }, 'and only widths are written back');
});

test('the toolbar hides until hover, and stays while a filter is in force or a menu is open', (t) => {
  const css = fs.readFileSync(path.join(HERE, 'ep-panel.css'), 'utf8');
  assert.match(css, /\.ep-panel \.ep-pt \{ max-height: 0; opacity: 0;/);
  assert.match(css, /\.ep-panel:hover \.ep-pt, \.ep-panel:focus-within \.ep-pt, \.ep-panel\.ep-pt-pinned \.ep-pt, \.ep-panel\.ep-pt-open \.ep-pt/);
  assert.match(css, /@media \(hover: none\)/, 'touch screens always see it');
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body } = setup(lib);
  const root = body.querySelector('.ep-panel'), q = body.querySelector('[data-ep-search]');
  assert.ok(!root.classList.contains('ep-pt-pinned'));
  q.value = 'beta'; q.dispatchEvent(new w.Event('input'));
  assert.ok(root.classList.contains('ep-pt-pinned'), 'a search in force keeps it showing');
  q.value = ''; q.dispatchEvent(new w.Event('input'));
  assert.ok(!root.classList.contains('ep-pt-pinned'));
  body.querySelector('[data-ep-menu]').click();
  assert.ok(root.classList.contains('ep-pt-open'), 'an open menu keeps it showing');
  body.querySelector('.ep-res-first').click();
  assert.ok(!root.classList.contains('ep-pt-open'), 'and a click elsewhere lets it go');
});

test('the menu opens and closes', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body } = setup(lib);
  const pop = body.querySelector('.ep-menu-pop');
  body.querySelector('[data-ep-menu]').click();
  assert.equal(pop.hidden, false);
  body.querySelector('.ep-res-first').click();
  assert.equal(pop.hidden, true, 'a click elsewhere closes it');
});

/* ---- 2.1.1: Needs attention, Compact, Table / Pie chart, and the palette ---- */

const RES = `<div id="w"><div class="ep-panel" data-ep-panel="resources" data-ep-view="table" style="--ep-ok:#3e9b3a;--ep-warn:#e5860b;--ep-high:#e0314a">
  <div class="ep-pt"><span data-ep-count data-template="%n clients">2 clients</span>
    <label><input type="search" data-ep-search></label>
    <select data-ep-type-filter><option value="">All</option><option value="DI">DI</option></select>
    <button data-ep-attention aria-pressed="false">Needs attention <span>1</span></button>
    <button data-ep-compact aria-pressed="true">Compact</button>
    <div class="ep-seg"><button data-ep-view-to="table" aria-pressed="true">Table</button><button data-ep-view-to="pie" aria-pressed="false">Pie chart</button></div>
    <button data-ep-expand-all class="ep-table-only">Expand all</button>
  </div>
  <div class="ep-pscroll ep-view-table"><table class="ep-res-table">
    <thead><tr><th class="ep-res-toggle"></th><th class="ep-res-first">Client</th><th>Used</th></tr></thead>
    <tbody>
      <tr data-type="On-Prem" data-row="c0" data-attention="0"><td class="ep-res-toggle"><button data-ep-expand="c0" aria-expanded="false">▸</button></td><td class="ep-res-first">acme</td><td>30 %</td></tr>
      <tr class="ep-sub" data-parent="c0" hidden><td colspan="3"><table class="ep-servers"><tr><td>acme-es-1 10.0.0.9</td></tr></table></td></tr>
      <tr data-type="DI" data-row="c1" data-attention="1"><td class="ep-res-toggle"><button data-ep-expand="c1" aria-expanded="false">▸</button></td><td class="ep-res-first">beta</td><td>95 %</td></tr>
      <tr class="ep-sub" data-parent="c1" hidden><td colspan="3"><table class="ep-servers"><tr><td>beta-data-1 10.40.1.21</td></tr></table></td></tr>
    </tbody><tfoot><tr class="ep-totals" data-ep-totals><td></td><td>Total (all clients)</td><td>62 %</td></tr></tfoot></table></div>
  <div class="ep-pie ep-pscroll ep-view-pie" hidden>
    <article class="ep-pie-card" data-type="On-Prem" data-card="c0" data-attention="0" data-search="acme acme-es-1 10.0.0.9">acme <button data-ep-goto="c0">Servers (1)</button></article>
    <article class="ep-pie-card" data-type="DI" data-card="c1" data-attention="1" data-search="beta beta-data-1 10.40.1.21">beta <button data-ep-goto="c1">Servers (1)</button></article>
  </div></div></div>`;

function resources(lib, view = { view: 0, switch: true, attention: false, compact: true }, widget = {}) {
  const dom = new lib.JSDOM(`<body>${RES}</body>`, { runScripts: 'outside-only', url: 'https://zabbix.example/zabbix.php' });
  const w = dom.window;
  w.CSS = { escape: (s) => String(s).replace(/"/g, '\\"') };
  w.eval(fs.readFileSync(path.join(HERE, 'ep-columns.js'), 'utf8'));
  w.eval(fs.readFileSync(path.join(HERE, 'ep-panel.js'), 'utf8'));
  const body = w.document.getElementById('w');
  w.EpColumns.init(widget, body, null);
  w.EpPanel.init(widget, body, 'resources', { view });
  return { w, body, widget, root: body.querySelector('.ep-panel') };
}
const shownCards = (body) => [...body.querySelectorAll('.ep-pie-card')].filter((c) => !c.hidden).map((c) => c.dataset.card);

test('Needs attention filters the table and the cards together, and keeps the toolbar showing', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body, root } = resources(lib);
  const att = body.querySelector('[data-ep-attention]');
  att.click();
  assert.deepEqual(visible(body), ['beta']);
  assert.deepEqual(shownCards(body), ['c1']);
  assert.equal(att.getAttribute('aria-pressed'), 'true');
  assert.ok(root.classList.contains('ep-pt-pinned'), 'a filter in force keeps the toolbar');
  assert.equal(body.querySelector('[data-ep-count]').textContent, '1 clients');
  const q = body.querySelector('[data-ep-search]');
  q.value = '10.0.0.9'; q.dispatchEvent(new w.Event('input'));
  assert.deepEqual(shownCards(body), [], 'search and attention decide together');
  att.click();
  assert.deepEqual(shownCards(body), ['c0'], 'a host\'s IP finds its client\'s card');
});

test('the widget\'s default filter starts Needs attention on, and a viewer\'s choice outlives the redraw', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const first = resources(lib, { view: 0, switch: true, attention: true, compact: true });
  assert.deepEqual(visible(first.body), ['beta'], 'Needs attention only, by the widget\'s setting');
  first.body.querySelector('[data-ep-attention]').click();
  const again = resources(lib, { view: 0, switch: true, attention: true, compact: true }, first.widget);
  assert.deepEqual(visible(again.body), ['acme', 'beta'], 'the viewer turned it off; a refresh does not turn it back on');
});

test('Table / Pie chart switches the view, the count follows, and a card\'s Servers opens its client in the table', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { body, root, widget } = resources(lib);
  body.querySelector('[data-ep-view-to="pie"]').click();
  assert.equal(body.querySelector('.ep-view-pie').hidden, false);
  assert.equal(body.querySelector('.ep-view-table').hidden, true);
  assert.equal(root.dataset.epView, 'pie');
  assert.equal(body.querySelector('[data-ep-expand-all]').hidden, true, 'Expand all is for the table');
  assert.equal(body.querySelector('[data-ep-view-to="pie"]').getAttribute('aria-pressed'), 'true');
  body.querySelector('[data-ep-goto="c1"]').click();
  assert.equal(root.dataset.epView, 'table');
  assert.equal(body.querySelector('[data-ep-expand="c1"]').getAttribute('aria-expanded'), 'true');
  assert.equal(body.querySelector('tr[data-parent="c1"]').hidden, false);
  assert.equal(widget._epPanel.view, 'table', 'the switch is this viewer\'s, held on the widget');
});

test('Compact is one viewer\'s switch, and nothing of the look is read from or written to the browser', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body, root } = resources(lib);
  assert.ok(!root.classList.contains('ep-comfortable'));
  body.querySelector('[data-ep-compact]').click();
  assert.ok(root.classList.contains('ep-comfortable'));
  assert.equal(root.style.getPropertyValue('--ep-ok'), '#3e9b3a', 'the palette on the panel is left alone');
  assert.equal(w.localStorage.getItem('ep-panel:resources'), null, 'nothing kept in the browser');
});

test('the totals row, which is over every client, steps aside while a filter is on', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, body } = resources(lib);
  const totals = body.querySelector('[data-ep-totals]');
  assert.equal(totals.hidden, false);
  body.querySelector('[data-ep-attention]').click();
  assert.equal(totals.hidden, true, 'Needs attention');
  body.querySelector('[data-ep-attention]').click();
  const q = body.querySelector('[data-ep-search]');
  q.value = 'beta'; q.dispatchEvent(new w.Event('input'));
  assert.equal(totals.hidden, true, 'a search');
  q.value = ''; q.dispatchEvent(new w.Event('input'));
  assert.equal(totals.hidden, false, 'back when the filter is cleared');
  const sel = body.querySelector('[data-ep-type-filter]');
  sel.value = 'DI'; sel.dispatchEvent(new w.Event('change'));
  assert.equal(totals.hidden, true, 'a Type filter');
});
