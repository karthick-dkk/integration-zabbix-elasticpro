/**
 * The Columns tab's behaviour in a widget's settings form (shared/php/views/columns.form.js.php,
 * EpColumnsForm — Client capacity, Volume, Top hosts), in a DOM: Add and Edit open the widget's own
 * column editor, what the editor returns becomes the form's hidden fields in the order they were
 * dragged to, Remove, Add avg / min / max, Reset to report defaults and the client filter. Needs
 * jsdom; skipped, saying so, otherwise.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const ROOT = path.resolve(import.meta.dirname, '../..');

function jsdom() {
  for (const base of [ROOT, path.resolve(ROOT, '../..')]) {
    try { return createRequire(path.join(base, 'package.json'))('jsdom'); } catch { /* next */ }
  }
  return null;
}

/** The view file as the script it renders: the PHP tags replaced with what they print. */
function script() {
  let src = fs.readFileSync(path.join(ROOT, 'shared/php/views/columns.form.js.php'), 'utf8');
  src = src.slice(src.indexOf('?>') + 2);
  const C = { VALUE_AVG: 2, VALUE_MIN: 3, VALUE_MAX: 4 };
  src = src.replace(/<\?= Columns::(\w+) \?>/g, (_, k) => { assert.ok(k in C, k); return String(C[k]); });
  src = src.replace(/<\?= ZBX_STYLE_DRAG_ICON \?>/g, 'drag-icon');
  src = src.replace(/<\?= json_encode\(_\('((?:[^'\\]|\\.)*)'\)\) \?>/g, (_, s) => JSON.stringify(s.replace(/\\'/g, "'")));
  assert.ok(!src.includes('<?'), 'every PHP tag replaced');
  return src;
}

const FORM = `<form id="widget-dialogue-form">
  <label><input type="radio" name="columns_from" value="0"><input type="radio" name="columns_from" value="1" checked></label>
  <button id="ep_cf_reset" type="button">Reset</button>
  <input id="ep_cf_clients_filter"><span id="ep_cf_clients_note"></span>
  <div class="ep-ef-clients"><span class="ep-ef-box"><input type="checkbox" name="clients[]" value="acme">acme</span><span class="ep-ef-box"><input type="checkbox" name="clients[]" value="beta">beta</span></div>
  <input name="trend_days" value="14">
  <input type="checkbox" name="totals"><select name="totals_fn"></select>
  <table id="ep-cf-columns"><tbody>
    <tr data-uid="u0"><td><input type="checkbox" class="js-show" checked><button type="button" name="edit">Edit</button><button type="button" name="remove">Remove</button>
      <input type="hidden" name="sortorder[columns][]" value="0"><input type="hidden" name="columns[0][uid]" value="u0"><input type="hidden" name="columns[0][name]" value="Used"><input type="hidden" name="columns[0][hidden]" value="0"></td></tr>
    <tr><td><button type="button" name="add">Add</button><button type="button" name="add_group">Group</button><button type="button" name="add_window">Window</button>
      <div id="ep_cf_window" style="display:none"><select id="ep_cf_window_of"><option value="0">Used</option></select>
        <input type="checkbox" id="ep_cf_window_avg" checked><input type="checkbox" id="ep_cf_window_min"><input type="checkbox" id="ep_cf_window_max" checked>
        <button type="button" name="add_window_go">Add</button></div></td></tr>
  </tbody></table>
</form>`;

function setup(lib) {
  const dom = new lib.JSDOM(`<body>${FORM}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  const calls = { popups: [], reloads: 0, sortables: 0 };
  w.CSortable = class { constructor() { calls.sortables++; } };
  w.jQuery = () => ({ hide() {} });
  w.ZABBIX = { Dashboard: { reloadWidgetProperties: () => { calls.reloads++; } } };
  w.getFormFields = (form) => {
    // Zabbix's own: names with [] nesting into objects.
    const out = {};
    for (const el of form.elements) {
      if (!el.name || ((el.type === 'radio' || el.type === 'checkbox') && !el.checked)) continue;
      const path_ = el.name.replace(/\]/g, '').split('[');
      let o = out;
      path_.forEach((k, i) => {
        if (i === path_.length - 1) { if (k === '') { o.push ? o.push(el.value) : null; } else o[k] = el.value; }
        else { const next = path_[i + 1] === '' ? [] : {}; o = o[k] = o[k] || next; }
      });
    }
    return out;
  };
  w.PopUp = (action, params) => {
    const dialogue = w.document.createElement('div');
    calls.popups.push({ action, params, dialogue });
    return { $dialogue: [dialogue] };
  };
  w.eval(script());
  const form = new w.EpColumnsForm();
  form.init({ action: 'widget.ep_x.column.edit', templateid: undefined, trend_days: 7, own: true, max: 400 });
  return { w, form, calls, doc: w.document };
}
const names = (doc) => [...doc.querySelectorAll('[name]')].map((e) => `${e.name}=${e.value}`);
const submit = (w, popup, detail) => popup.dialogue.dispatchEvent(new w.CustomEvent('dialogue.submit', { detail }));

test('Add opens this widget\'s column editor with the form\'s trend window; what it returns joins the form in the next free place', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, calls, doc } = setup(lib);
  assert.equal(calls.sortables, 1, 'the rows can be dragged');
  doc.querySelector('button[name="add"]').click();
  assert.equal(calls.popups[0].action, 'widget.ep_x.column.edit');
  assert.equal(calls.popups[0].params.trend_days, '14', 'the window typed in the form, not the one it was opened with');
  assert.equal(calls.popups[0].params.scope, 'client');
  submit(w, calls.popups[0], { uid: 'n1', name: 'Docs/s', key: 'es.docs', roles: ['a'], thresholds: [{ level: '1', color: '', value: '5' }], edit: 0, scope: 'client' });
  const n = names(doc);
  assert.ok(n.includes('sortorder[columns][]=1') && n.includes('columns[1][name]=Docs/s') && n.includes('columns[1][roles][0]=a')
    && n.includes('columns[1][thresholds][0][value]=5'), n.join('\n'));
  assert.ok(!n.some((x) => x.startsWith('columns[1][edit]') || x.startsWith('columns[1][scope]')), 'the editor\'s own flags stay out');
  assert.equal(calls.reloads, 1, 'and the form is drawn again');
  doc.querySelector('button[name="add_group"]').click();
  assert.equal(calls.popups[1].params.group, 'New group');
});

test('Edit replaces a column\'s settings and keeps its place; Remove takes the row out', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, calls, doc } = setup(lib);
  doc.querySelector('button[name="edit"]').click();
  assert.equal(calls.popups[0].params.edit, 1);
  assert.equal(calls.popups[0].params.name, 'Used', 'the editor opens on the column as it is');
  submit(w, calls.popups[0], { uid: 'u0', name: 'Used now', hidden: '0', edit: 1 });
  const n = names(doc);
  assert.ok(n.includes('columns[0][name]=Used now') && !n.includes('columns[0][name]=Used'), n.join('\n'));
  assert.equal(n.filter((x) => x === 'sortorder[columns][]=0').length, 1, 'its place in the order kept, not repeated');
  doc.querySelector('button[name="remove"]').click();
  assert.equal(doc.querySelector('tr[data-uid="u0"]'), null);
  assert.equal(calls.reloads, 2);
});

test('Hiding a column in the list sets it hidden in the form', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, doc } = setup(lib);
  const box = doc.querySelector('.js-show');
  box.checked = false;
  box.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.equal(doc.querySelector('[name="columns[0][hidden]"]').value, '1');
  assert.ok(doc.querySelector('tr[data-uid="u0"]').classList.contains('ep-res-off'));
});

test('Add avg / min / max: copies of the column over the widget\'s window, right after it, named for it', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { calls, doc } = setup(lib);
  doc.querySelector('button[name="add_window"]').click();
  assert.equal(doc.getElementById('ep_cf_window').style.display, '');
  doc.querySelector('button[name="add_window_go"]').click();
  const n = names(doc);
  assert.ok(n.includes('columns[1][name]=7d avg') && n.includes('columns[1][value]=2') && n.includes('columns[2][name]=7d max') && n.includes('columns[2][value]=4'), n.join('\n'));
  assert.ok(!n.some((x) => x.startsWith('columns[3]')), 'min was not ticked');
  assert.ok(n.includes('columns[1][uid]='), 'a new uid is made by the editor\'s rule, not copied');
  const order = [...doc.querySelectorAll('[name="sortorder[columns][]"]')].map((e) => e.value);
  assert.deepEqual(order, ['0', '1', '2'], 'right after the column');
  assert.equal(calls.reloads, 1);
});

test('Reset to report defaults drops the widget\'s own columns; the client filter and its count work', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, calls, doc } = setup(lib);
  const note = doc.getElementById('ep_cf_clients_note');
  assert.match(note.textContent, /All clients/);
  const acme = doc.querySelector('[name="clients[]"][value="acme"]');
  acme.checked = true;
  acme.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.match(note.textContent, /1 client picked/);
  const filter = doc.getElementById('ep_cf_clients_filter');
  filter.value = 'bet';
  filter.dispatchEvent(new w.Event('input'));
  const boxes = [...doc.querySelectorAll('.ep-ef-box')];
  assert.deepEqual(boxes.map((b) => b.style.display), ['none', ''], 'the filter narrows the list');
  const enter = new w.KeyboardEvent('keydown', { key: 'Enter', cancelable: true });
  filter.dispatchEvent(enter);
  assert.ok(enter.defaultPrevented, 'Enter in the filter does not apply the whole form');
  doc.getElementById('ep_cf_reset').click();
  assert.equal(doc.querySelector('[name="columns_from"][value="0"]').checked, true);
  assert.equal(doc.querySelectorAll('[name^="columns["]').length, 0, 'nothing of its own is kept');
  assert.equal(doc.querySelectorAll('[name^="sortorder["]').length, 0);
  assert.equal(calls.reloads, 1);
  // Choosing the report defaults by the radio does the same.
  doc.querySelector('[name="columns_from"][value="1"]').checked = true;
  const radio = doc.querySelector('[name="columns_from"][value="0"]');
  radio.checked = true;
  radio.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.equal(calls.reloads, 2);
});

test('the totals function is only for a totals row', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, doc } = setup(lib);
  const fn = doc.querySelector('[name="totals_fn"]');
  assert.equal(fn.disabled, true);
  const totals = doc.querySelector('[name="totals"]');
  totals.checked = true;
  totals.dispatchEvent(new w.Event('change', { bubbles: true }));
  assert.equal(fn.disabled, false);
});
