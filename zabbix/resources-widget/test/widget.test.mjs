/** The resources widget's wiring, and its controller (test/view.test.php) run with PHP. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

const HERE = path.resolve(import.meta.dirname, '..');

/**
 * Docker could not be reached at all, as opposed to the tests having failed. Matched loosely on
 * purpose: this used to look for one exact sentence, so a stopped Docker Desktop — which words it
 * differently ("failed to connect to the docker API at unix://…") — turned every PHP suite into a
 * red failure that had nothing to do with the code.
 */
const noDocker = (r) => r.status !== 0 && /docker|daemon/i.test(`${r.stderr || ''}${r.stdout || ''}`)
  && !/passed, \d+ failed|FAIL:/.test(`${r.stdout || ''}`);

test('the manifest names the widget, its controller and the Columns action', () => {
  const m = JSON.parse(fs.readFileSync(path.join(HERE, 'manifest.json'), 'utf8'));
  assert.equal(m.id, 'ep_resources');
  assert.equal(m.widget.js_class, 'WidgetEpResources');
  const js = fs.readFileSync(path.join(HERE, 'assets/js/class.widget.js'), 'utf8');
  assert.ok(js.includes('class WidgetEpResources extends CWidget'));
  assert.ok(js.includes('EpColumns.init') && js.includes('EpExport.bind'));
  assert.ok(m.actions['widget.ep_resources.view']);
  assert.equal(m.actions['widget.ep_resources.columns'].class, 'ColumnsSave');
  assert.deepEqual(m.actions['widget.ep_resources.column.edit'], { class: 'ColumnEdit', view: 'column.edit', layout: 'layout.json' },
    'the column editor, as Top hosts has its own');
});

const read = (f) => fs.readFileSync(path.join(HERE, f), 'utf8');

test('the form has the three tabs of the design and every setting on them, and still reads the old fields', () => {
  // The table's options are the shared TableFields' (the same for every widget on the column model).
  const form = read('includes/WidgetForm.php') + read('includes/TableFields.php');
  assert.ok(read('includes/WidgetForm.php').includes('TableFields::add('), 'the table options come from TableFields');
  for (const field of ['groupids', 'clients', 'client_types', 'trend_days', 'columns_from', 'columns', 'server_columns', 'bars', 'usage_warn', 'usage_high',
    'view', 'view_switch', 'pie_order', 'pie_donuts', 'pie_centre', 'pie_legend', 'pie_short', 'pie_per_row', 'pie_size', 'pie_chips', 'server_rows',
    'density', 'row_height', 'group_headers', 'sticky_header', 'freeze_first', 'striped', 'totals', 'totals_fn', 'sort_by', 'sort_dir', 'top_n',
    'toolbar_items', 'toolbar_pos', 'attention', 'attention_default']) {
    assert.ok(form.includes(`'${field}'`), field);
  }
  // Warn and High: empty means Settings, never a 0 in the box.
  assert.ok(!/CWidgetFieldIntegerBox\('(usage_warn|usage_high)'/.test(form), 'no integer box for thresholds');
  assert.match(form, /CWidgetFieldOptionalNumber\('usage_warn'/);
  const edit = read('views/widget.edit.php');
  for (const tab of ["_('Data')", "_('Columns')", "_('View')"]) assert.ok(edit.includes(tab), tab);
  assert.match(edit, /f->tabs\('ep_res_tabs'/, 'the tabs are started on their own element');
  // One settings form: the parts every column widget has are the shared ColumnForm's, the same file Client capacity, Volume and Top hosts draw.
  const shared = read('includes/ColumnForm.php');
  for (const call of ['new ColumnForm(', '->who(', '->trendRow(', '->columnsFrom(', '->columnsList(', '->serverRows(', '->barsAndThresholds(',
    '->tableGrid(', '->toolbarGrid(', '->attentionGrid(', '->tabs(']) {
    assert.ok(edit.includes(call), `the edit view uses ColumnForm: ${call}`);
  }
  for (const own of ['$hidden = function', '$sourceText = function', '$thresholdText = function', 'ep-res-columns', 'ep-res-server-columns', 'ep_res_window']) {
    assert.ok(!edit.includes(own), `no copy of the shared form of its own: ${own}`);
  }
  for (const copy of ['Report defaults are set by a Super admin', 'Reset to report defaults', 'Add column', 'Add group', 'Add avg / min / max for a column',
    "_s('Settings: %1\$s'", 'Server rows', 'When a client is expanded']) {
    assert.ok(shared.includes(copy), copy);
  }
  for (const copy of ['New roles from Cluster Management appear here on their own, unticked',
    'Any figure with used, allocated and requested can be a donut', 'Needs attention means', 'Toolbar on the widget', 'Server rows and thresholds']) {
    assert.ok(edit.includes(copy), copy);
  }
  assert.ok(edit.includes("'widget.ep_resources.column.edit'"), 'Edit and Add open the column editor');
  assert.ok(edit.includes("includeJsFile('columns.form.js.php')"), 'the shared form behaviour is included before its own');
  const js = read('views/widget.edit.js.php');
  assert.ok(js.includes('extends window.EpColumnsForm'), 'the form script is the shared one, extended');
  for (const own of ['_addWindows', '_store(', '_fields(', 'ep_res_reset']) assert.ok(!js.includes(own), `no copy of the shared behaviour: ${own}`);
});

test('the view draws what the controller sends: table and pie chart, attention, the view switch, the palette', () => {
  // The table, its cells and its toolbar are ColumnTable's, shared with the other widgets on the column model.
  const v = read('views/widget.view.php') + read('includes/ColumnTable.php');
  for (const hook of ['data-ep-attention', 'data-ep-compact', 'data-ep-view-to', 'ep-pie-card', 'data-ep-goto', 'ep-donut', "\$data['palette']",
    'stroke-dasharray', 'data-attention', 'ep-totals', 'ep-dropped', 'Bars::cell(']) {
    assert.ok(v.includes(hook.replace(/\\/g, '')), hook);
  }
  assert.ok(!v.includes('data-ep-settings'), 'no per-browser Table settings');
  // Links only from the controller (ColumnMath::url): the view builds none of its own.
  assert.ok(!/new CUrl\(/.test(v), 'no address built in the view');
});

test('one definition: the column rule, the arithmetic and the reading are the shared engine\'s', () => {
  // The figures are ColumnReport's — the same for every widget on the column model — and the controller only describes its rows.
  const ctl = read('actions/WidgetView.php');
  const engine = read('lib/ColumnReport.php');
  for (const call of ['ColumnReport::resolve(', 'new ColumnReport(', '->read(', '->cells(', '->totals(', 'Settings::paletteStyle(', 'ClientData::clients(', 'ClientData::servers(']) {
    assert.ok(ctl.includes(call), call);
  }
  for (const call of ['Columns::cleanList(', 'ColumnMath::combine(', 'ColumnMath::trend(', 'ColumnMath::perHost(', 'ColumnMath::windowValue(', 'ColumnReader', 'ColumnSettings::resolve(']) {
    assert.ok(engine.includes(call), `the engine: ${call}`);
  }
  assert.ok(!/ColumnMath::(combine|trend|perHost|windowValue)\(/.test(ctl), 'no arithmetic of its own in the controller');
  assert.ok(read('includes/Resources.php').includes('ColumnMath::pie('), 'the donuts are ColumnMath::pie');
  // Batched: no API call inside the per-client, per-column functions.
  for (const fn of ['private function serverRow(', 'private function donut(']) {
    const at = ctl.indexOf(fn);
    const body = ctl.slice(at, ctl.indexOf('\n\t}\n', at));
    assert.ok(at > 0 && !/API::/.test(body), `no API call in ${fn}`);
  }
  const fig = engine.slice(engine.indexOf('private function figure('), engine.indexOf('public function head(') > engine.indexOf('private function figure(') ? engine.length : engine.length);
  assert.ok(!/API::/.test(fig.slice(0, fig.indexOf('\n\t}\n'))), 'no API call in figure()');
  assert.ok(read('actions/ColumnEdit.php').includes('Columns::clean('), 'the editor applies the same rule');
  assert.ok(read('includes/CWidgetFieldColumns.php').includes('Columns::cleanList('), 'and the field, on Apply');
});

function jsdom() {
  for (const base of [path.resolve(HERE, '..'), path.resolve(HERE, '../../..')]) {
    try { return createRequire(path.join(base, 'package.json'))('jsdom'); } catch { /* next */ }
  }
  return null;
}
const COLS = { SOURCE_BUILTIN: 1, SOURCE_ITEM: 2, SOURCE_CALC: 3, KEY_OPERAND: 'key:', ON_SERVERS: 'servers', SHOW_BAR: 2, SHOW_INDICATORS: 3, SHOW_VALUE: 1,
  SHOW_CELL: 5, SHOW_BADGE: 7, BAR_SEGMENTS: 3, BAR_GAUGE: 4, BARCOLOR_FIXED: 2, OP_OF: 1, VALUE_NOW: 1, LEVEL_WARN: 1, LEVEL_HIGH: 2, WORSE_HIGHER: 1,
  WORSE_LOWER: 2, NODATA_WHY: 1 };
function editorScript() {
  let src = read('views/column.edit.js.php');
  src = src.slice(src.indexOf('?>') + 2);
  src = src.replace(/<\?= Columns::(\w+) \?>/g, (_, k) => { assert.ok(k in COLS, k); return String(COLS[k]); });
  src = src.replace(/<\?= ZBX_STYLE_COLOR_PICKER \?>/g, 'color-picker');
  src = src.replace(/<\?= json_encode\(_\('((?:[^'\\]|\\.)*)'\)\) \?>/g, (_, s) => JSON.stringify(s.replace(/\\'/g, "'")));
  assert.ok(!src.includes('<?'), 'every PHP tag replaced');
  return src;
}
/** A colour field as Zabbix 7.0.31's CColor renders it (appendColorPickerJs(false)): a hidden input in div.color-picker. */
const color = (name) => `<div class="color-picker"><input type="hidden" id="${name}" name="${name}" value=""></div>`;
const EDITOR = `<div class="overlay-dialogue-body"><form name="ep_column_edit" style="display:none">
  <input name="group" value=""><input name="name" value="">
  <input type="radio" name="source" value="1" checked><input type="radio" name="source" value="2"><input type="radio" name="source" value="3">
  <div class="js-builtin"><select name="builtin"><option value="storage.used">x</option><option value="storage.usage">y</option></select><span id="ep_ce_builtin_key"></span></div>
  <div class="js-item"><input name="key"><input type="checkbox" name="pattern" id="ep_ce_pattern"><div id="ep_ce_coverage"></div></div>
  <div class="js-calc"><select name="calc_a_pick"><option value="storage.used">u</option><option value="__key">k</option></select><input name="calc_a_key">
    <select name="calc_op"><option value="1">of</option><option value="2">minus</option></select>
    <select name="calc_b_pick"><option value="storage.allocated">a</option></select><input name="calc_b_key"></div>
  <div class="js-servers"><input type="checkbox" id="ep_ce_roles_all" name="roles_all"><input type="checkbox" class="js-role" name="roles[]" value="es_hot"></div>
  <label>Value</label><div class="form-field"><select name="value"><option value="1">now</option><option value="2">avg</option></select><span class="js-window">over</span><input name="window" class="js-window"></div>
  <div class="js-number"><input name="units"><input name="decimals"><input type="checkbox" name="short"></div>
  <select name="nodata"><option value="1">why</option><option value="2">—</option></select>
  <input type="radio" name="show" value="1" checked><input type="radio" name="show" value="2"><input type="radio" name="show" value="5">
  <div class="js-bar"><input type="radio" name="bar_style" value="0" checked><input type="radio" name="bar_style" value="3"><input name="segments" value="12">
    <input type="radio" name="bar_color" value="1" checked><input type="radio" name="bar_color" value="2"><div class="ep-ce-color">${color('bar_fixed')}</div><div class="ep-ce-color">${color('track')}</div>
    <input type="checkbox" name="show_value" checked><input type="checkbox" name="show_pct" checked></div>
  <input type="checkbox" name="wrap">
  <input type="radio" name="worse" value="1" checked><input type="radio" name="worse" value="2">
  <input type="checkbox" id="ep_ce_use_settings" name="use_settings" checked>
  <div id="ep_ce_settings_values"><input name="warn"><input name="high"><div class="ep-ce-color">${color('warn_color')}</div><div class="ep-ce-color">${color('high_color')}</div></div>
  <div id="ep_ce_own"><table id="ep_ce_thresholds"><tr><th id="ep_ce_th_head"><span>At or above</span></th></tr></table></div>
  <div id="ep_ce_preview"></div>
</form></div>`;
function editor(lib) {
  const dom = new lib.JSDOM(`<body>${EDITOR}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  const pickers = [];
  w.jQuery = (x) => ({ find: () => ({ dynamicRows() {}, on() {}, 0: w.document.getElementById('ep_ce_thresholds') }), on() {},
    colorpicker(o) { pickers.push({ el: x, o }); },
    closest(sel) { const c = x && x.closest ? x.closest(sel) : null; return c ? Object.assign([c], { length: 1 }) : Object.assign([], { length: 0 }); } });
  w.getFormFields = (form) => {
    const out = {};
    for (const el of form.elements) {
      if (!el.name || ((el.type === 'radio' || el.type === 'checkbox') && !el.checked)) continue;
      if (el.name.endsWith('[]')) (out[el.name.slice(0, -2)] = out[el.name.slice(0, -2)] || []).push(el.value);
      else out[el.name] = el.value;
    }
    return out;
  };
  w.CSS = { escape: (s) => String(s) };
  w.eval(editorScript());
  const ed = w.ep_column_form;
  ed.init({ form_name: 'ep_column_edit', thresholds: [], decimals: { '%': 1, '': 0, B: 2 }, usage: { warn: 80, high: 90 }, row: false, coverage_url: 'x',
    catalogue: { 'storage.used': { label: 'Used', group: 'ES storage', on: 'master', key: 'ep.es.storage.used', units: 'B', settings: false, pct: false, text: false },
      'storage.usage': { label: 'Used %', group: 'ES storage', on: 'master', key: 'ep.es.storage.usage', units: '%', settings: true, pct: false, text: false },
      'storage.allocated': { label: 'Allocated', group: 'ES storage', on: 'master', key: 'ep.es.storage.allocated', units: 'B', settings: false, pct: false, text: false } } });
  const f = w.document.forms.ep_column_edit;
  const set = (sel, v) => { const el = f.querySelector(sel); if (el.type === 'radio' || el.type === 'checkbox') el.checked = v; else el.value = v;
    el.dispatchEvent(new w.Event('change', { bubbles: true })); };
  return { w, ed, f, set, pickers, shown: (sel) => f.querySelector(sel).style.display !== 'none' };
}

test('the column editor: rows follow the source, a built-in names the column, Settings only for a percentage (DOM)', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { f, set, shown } = editor(lib);
  assert.equal(f.style.display, '', 'the form shows once set up');
  assert.equal(f.querySelector('[name="name"]').value, 'Used', 'a built-in names an unnamed column');
  assert.equal(f.querySelector('[name="group"]').value, 'ES storage', '…and its group');
  assert.ok(shown('.js-builtin') && !shown('.js-item') && !shown('.js-calc') && !shown('.js-servers'));
  assert.equal(f.querySelector('#ep_ce_use_settings').disabled, true, 'bytes are not a percentage: no Settings thresholds');
  assert.equal(f.querySelector('#ep_ce_own').style.display, '', 'its own thresholds instead');
  set('[name="builtin"]', 'storage.usage');
  assert.equal(f.querySelector('[name="name"]').value, 'Used %', 'the name follows the built-in while it is the built-in\'s');
  assert.equal(f.querySelector('#ep_ce_use_settings').disabled, false);
  assert.equal(f.querySelector('#ep_ce_settings_values').style.display, '');
  set('[name="source"][value="2"]', true);
  assert.ok(shown('.js-item') && shown('.js-servers') && !shown('.js-builtin'), 'an item key is read off the servers');
  set('[name="source"][value="3"]', true);
  assert.ok(shown('.js-calc') && !shown('.js-servers'), 'two master-host figures: no servers to pick');
  assert.equal(f.querySelector('#ep_ce_use_settings').disabled, false, 'x of y is a percentage');
  set('[name="calc_a_pick"]', '__key');
  assert.equal(f.querySelector('[name="calc_a_key"]').style.display, '', 'an item key operand gets its box');
  assert.ok(shown('.js-servers'), '…and is read off the servers');
});

test('the column editor\'s colour boxes get Zabbix 7.0\'s colour picker — swatch, pick, type a hex — in the popup (DOM)', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, f, pickers } = editor(lib);
  // 2.1.1 as shipped looked for .input-color-picker, which 7.0's CColor never renders: no box had a picker.
  assert.deepEqual(pickers.map((p) => p.el.name), ['bar_fixed', 'track', 'warn_color', 'high_color'], 'every CColor box is given the picker');
  const body = w.document.querySelector('.overlay-dialogue-body');
  assert.ok(pickers.every((p) => p.o.appendTo[0] === body), 'its dialog opens in the popup\'s body, as the core widgets\' forms have it');
  assert.ok(pickers.every((p) => p.o.use_default === true), 'empty is the palette\'s colour');
  // A colour picked (set by jQuery, which fires no DOM event) still updates the preview.
  let previewed = 0;
  f.addEventListener('input', () => previewed++);
  const warn = f.querySelector('[name="warn_color"]');
  warn.value = 'FF0000';
  pickers[2].o.onUpdate.call(warn, 'FF0000');
  assert.equal(previewed, 1);
  // Opening the editor again, or a threshold row added, never gives a box a second picker.
  f.querySelectorAll('.color-picker input').forEach((i) => assert.equal(i.dataset.epInit, '1'));
});

test('the column editor sends every box, ticked or not, and the operands as stored (DOM)', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { ed, f, set } = editor(lib);
  set('[name="source"][value="3"]', true);
  set('[name="calc_a_pick"]', '__key');
  f.querySelector('[name="calc_a_key"]').value = ' es.indexing.total ';
  set('[name="short"]', false);
  set('[name="show_pct"]', false);
  const out = ed._fields();
  assert.equal(out.calc_a, 'key:es.indexing.total');
  assert.equal(out.calc_b, 'storage.allocated');
  assert.equal(out.short, '0');
  assert.equal(out.show_pct, '0');
  assert.equal(out.show_value, '1');
  assert.ok(!('calc_a_pick' in out) && !('roles_all' in out), 'the editor\'s own boxes stay out of the column');
  assert.ok(out.roles && out.roles.length === 0, 'no roles ticked is every server, said');
});

test('the column editor previews the look on sample figures, in the palette\'s colours (DOM)', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { f, set } = editor(lib);
  set('[name="show"][value="2"]', true);
  set('[name="bar_style"][value="3"]', true);
  const lines = f.querySelectorAll('#ep_ce_preview .ep-ce-pv');
  assert.equal(lines.length, 4);
  assert.equal(lines[1].querySelectorAll('.ep-bar-segs i').length, 12, 'segments, as many as set');
  assert.ok(lines[2].querySelector('.ep-ce-pv-cell').classList.contains('ep-high'), '93 % is High at 80 / 90');
  assert.ok(lines[1].querySelector('.ep-ce-pv-cell').classList.contains('ep-warn'));
  assert.match(lines[3].textContent, /allocated not set/, 'unknown says why');
  set('[name="nodata"]', '2');
  assert.match(f.querySelectorAll('#ep_ce_preview .ep-ce-pv')[3].textContent, /—/);
});

/** A form view script as the script it renders: the PHP tags replaced with what they print. */
function formScript(file, consts) {
  let src = read(file);
  src = src.slice(src.indexOf('?>') + 2);
  src = src.replace(/<\?= (\w+)::(\w+) \?>/g, (_, cls, k) => { assert.ok(`${cls}::${k}` in consts, `${cls}::${k}`); return String(consts[`${cls}::${k}`]); });
  src = src.replace(/<\?= ZBX_STYLE_DRAG_ICON \?>/g, 'drag-icon');
  src = src.replace(/<\?= json_encode\(_\('((?:[^'\\]|\\.)*)'\)\) \?>/g, (_, str) => JSON.stringify(str.replace(/\\'/g, "'")));
  assert.ok(!src.includes('<?'), `${file}: every PHP tag replaced`);
  return src;
}
const RES_FORM = `<form id="widget-dialogue-form">
  <input type="radio" name="columns_from" value="0"><input type="radio" name="columns_from" value="1" checked>
  <button id="ep_cf_reset" type="button">Reset</button><span id="ep_cf_clients_note"></span>
  <input name="trend_days" value="7">
  <input type="radio" name="view" value="0" checked><input type="radio" name="view" value="1"><input type="checkbox" name="view_switch">
  <input type="checkbox" name="totals"><select name="totals_fn"></select>
  <table id="ep-cf-columns"><tbody>
    <tr data-uid="u0"><td><input type="checkbox" class="js-show" checked><button type="button" name="edit">Edit</button>
      <input type="hidden" name="sortorder[columns][]" value="0"><input type="hidden" name="columns[0][uid]" value="u0"><input type="hidden" name="columns[0][hidden]" value="0"></td></tr>
    <tr><td><button type="button" name="add">Add</button></td></tr>
  </tbody></table>
  <table id="ep-cf-server-columns"><tbody>
    <tr data-uid="s0"><td><input type="checkbox" class="js-show" checked><button type="button" name="edit">Edit</button>
      <input type="hidden" name="sortorder[server_columns][]" value="0"><input type="hidden" name="server_columns[0][uid]" value="s0"><input type="hidden" name="server_columns[0][name]" value="Role"><input type="hidden" name="server_columns[0][hidden]" value="0"></td></tr>
    <tr data-uid="s1"><td><input type="checkbox" class="js-show" checked><button type="button" name="edit">Edit</button>
      <input type="hidden" name="sortorder[server_columns][]" value="1"><input type="hidden" name="server_columns[1][uid]" value="s1"><input type="hidden" name="server_columns[1][hidden]" value="0"></td></tr>
  </tbody></table>
  <table id="ep-res-donuts"><tbody><tr><td>a</td></tr><tr><td>b</td></tr></tbody></table>
  <fieldset class="ep-res-pie-only">pie</fieldset><fieldset class="ep-res-table-only">table</fieldset>
</form>`;

test('the settings form is the shared EpColumnsForm with Client resources\' own: server rows, donuts, the view\'s settings (DOM)', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const dom = new lib.JSDOM(`<body>${RES_FORM}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  const calls = { popups: [], sortables: 0, reloads: 0 };
  w.CSortable = class { constructor() { calls.sortables++; } };
  w.jQuery = () => ({ hide() {} });
  w.ZABBIX = { Dashboard: { reloadWidgetProperties: () => { calls.reloads++; } } };
  w.getFormFields = (form) => {
    const out = {};
    for (const el of form.elements) {
      if (!el.name || ((el.type === 'radio' || el.type === 'checkbox') && !el.checked)) continue;
      const keys = el.name.replace(/\]/g, '').split('[');
      let o = out;
      keys.forEach((k, i) => { if (i === keys.length - 1) { if (k !== '') o[k] = el.value; } else { o = o[k] = o[k] || {}; } });
    }
    return out;
  };
  w.PopUp = (action, params) => { const dialogue = w.document.createElement('div'); calls.popups.push({ action, params, dialogue }); return { $dialogue: [dialogue] }; };
  w.eval(formScript('views/columns.form.js.php', { 'Columns::VALUE_AVG': 2, 'Columns::VALUE_MIN': 3, 'Columns::VALUE_MAX': 4 }));
  w.eval(formScript('views/widget.edit.js.php', { 'WidgetForm::VIEW_PIE': 1 }));
  const doc = w.document;
  assert.ok(w.widget_ep_resources_form instanceof w.EpColumnsForm, 'the shared form, extended');
  w.widget_ep_resources_form.init({ action: 'widget.ep_resources.column.edit', templateid: undefined, trend_days: 7, own: true, max: 400 });
  assert.equal(calls.sortables, 3, 'columns, server rows and donuts can be dragged');
  const shown = (sel) => doc.querySelector(sel).style.display !== 'none';
  assert.ok(shown('.ep-res-table-only') && !shown('.ep-res-pie-only'), 'Table: the table\'s settings');
  const pie = doc.querySelector('[name="view"][value="1"]');
  pie.checked = true;
  pie.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.ok(!shown('.ep-res-table-only') && shown('.ep-res-pie-only'), 'Pie chart: the donuts\' settings');
  const both = doc.querySelector('[name="view_switch"]');
  both.checked = true;
  both.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.ok(shown('.ep-res-table-only') && shown('.ep-res-pie-only'), 'viewers may switch: both');
  const totals = doc.querySelector('[name="totals"]');
  assert.equal(doc.querySelector('[name="totals_fn"]').disabled, true, 'the shared notes still run');
  totals.checked = true;
  totals.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.equal(doc.querySelector('[name="totals_fn"]').disabled, false);
  doc.querySelector('#ep-cf-server-columns tr[data-uid="s0"] button[name="edit"]').click();
  assert.equal(calls.popups[0].action, 'widget.ep_resources.column.edit', 'Client resources\' own column editor');
  assert.equal(calls.popups[0].params.scope, 'row');
  assert.equal(calls.popups[0].params.name, 'Role');
  doc.querySelector('#ep-cf-columns button[name="add"]').click();
  assert.equal(calls.popups[1].params.scope, 'client');
});

test('the view offers what the controller sends: expand, type filter, columns, servers export', () => {
  const v = fs.readFileSync(path.join(HERE, 'views/widget.view.php'), 'utf8');
  const t = fs.readFileSync(path.join(HERE, 'includes/ColumnTable.php'), 'utf8');
  for (const hook of ['data-ep-expand', 'data-ep-expand-all', 'data-ep-type-filter', 'data-ep-columns', "'csv-servers'", 'data-parent', "setVar('ep_meta'"]) {
    assert.ok(v.includes(hook) || t.includes(hook), hook);
  }
});

test('WidgetView against a fake Zabbix (PHP)', (t) => {
  const local = spawnSync('php', ['test/view.test.php'], { cwd: HERE, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${HERE}:/m:ro`, '-w', '/m', 'php:8.4-cli-alpine', 'php', 'test/view.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || noDocker(r)) { t.skip('no php and no docker here — run test/view.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
