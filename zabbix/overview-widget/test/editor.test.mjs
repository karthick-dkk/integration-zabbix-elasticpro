/**
 * The metric editor's name and units handling (views/metric.edit.js.php) in a DOM: what the
 * editor filled in from a built-in goes when the source changes, what was typed stays. The PHP in
 * the file only prints constants and strings; they are put in here as PHP would. Needs jsdom.
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
const CONST = { SOURCE_BUILTIN: 1, SOURCE_ITEM: 2, ON_SERVERS: 'servers', DISPLAY_BAR: 2, DISPLAY_INDICATORS: 3, LEVEL_WARN: 1, LEVEL_HIGH: 2,
  WORSE_HIGHER: 1, WORSE_LOWER: 2 };
function script() {
  let src = fs.readFileSync(path.join(HERE, 'views/metric.edit.js.php'), 'utf8');
  src = src.slice(src.indexOf('?>') + 2);
  src = src.replace(/<\?= Metrics::(\w+) \?>/g, (_, k) => String(CONST[k]));
  src = src.replace(/<\?= json_encode\(_\('((?:[^'\\]|\\.)*)'\)\) \?>/g, (_, s) => JSON.stringify(s.replace(/\\'/g, "'")));
  assert.ok(!src.includes('<?'), 'every PHP tag replaced');
  return src;
}
const FORM = `<form name="f">
  <input type="radio" name="source" value="1" checked><input type="radio" name="source" value="2">
  <input name="name" value=""><input name="units" value="">
</form>`;

function editor(lib) {
  const dom = new lib.JSDOM(`<body>${FORM}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  w.eval(script());
  const ed = w.ep_overview_metric_form;
  ed._form = w.document.forms.f;
  ed._catalogue = { 'storage.usage': { label: 'ES storage used', on: 'master', key: 'ep.es.storage.usage', units: '%', settings: true } };
  ed._val = function (n) { const c = this._form.querySelector(`[name="${n}"]:checked`); return c ? c.value : (this._form.querySelector(`[name="${n}"]`) || {}).value || ''; };
  ed._builtin = () => ed._catalogue['storage.usage'];
  ed._source = '1';
  for (const n of ['name', 'units']) ed._form.querySelector(`[name="${n}"]`).addEventListener('input', (e) => delete e.target.dataset.epAuto);
  // form.name is the form's own name, so the fields are looked up, not read as properties.
  const el = (n) => ed._form.querySelector(`[name="${n}"]:not([type="radio"])`);
  const f = { get name() { return el('name'); }, get units() { return el('units'); }, querySelector: (q) => ed._form.querySelector(q) };
  const pick = (v) => { f.querySelector(`[name="source"][value="${v}"]`).checked = true; ed._sourceChanged(); if (v === '1') { f.units.value = '%'; f.units.dataset.epAuto = '1'; } };
  const type = (n, v) => { el(n).value = v; el(n).dispatchEvent(new w.Event('input')); };
  // What _update does for a built-in: its units, marked as filled in.
  f.units.value = '%'; f.units.dataset.epAuto = '1';
  return { ed, f, pick, type };
}

test('switching to a Zabbix item clears what a built-in filled in', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { f, pick } = editor(lib);
  f.name.value = 'ES storage used';
  pick('2');
  assert.equal(f.name.value, '', 'a built-in\'s label is not a name');
  assert.equal(f.units.value, '', 'nor its units');
});

test('typed units survive Zabbix item → Built-in → Zabbix item, as a typed name does', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { f, pick, type } = editor(lib);
  pick('2');
  type('name', 'Ingest');
  type('units', '/s');
  pick('1');
  assert.equal(f.units.value, '%', 'the built-in shows its own units');
  pick('2');
  assert.equal(f.name.value, 'Ingest');
  assert.equal(f.units.value, '/s');
});
