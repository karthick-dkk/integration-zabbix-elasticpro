/** The Top hosts widget's wiring, its panes in a DOM, and its controller (test/view.test.php) run with PHP. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

const HERE = path.resolve(import.meta.dirname, '..');
const ROOT = path.resolve(HERE, '..');
const read = (f) => fs.readFileSync(path.join(HERE, f), 'utf8');
const noDocker = (r) => r.status !== 0 && /docker|daemon/i.test(`${r.stderr || ''}${r.stdout || ''}`)
  && !/passed, \d+ failed|FAIL:/.test(`${r.stdout || ''}`);
function jsdom() {
  for (const base of [ROOT, path.resolve(ROOT, '../..')]) {
    try { return createRequire(path.join(base, 'package.json'))('jsdom'); } catch { /* next */ }
  }
  return null;
}

test('the manifest names the widget, its controller and the column editor, and loads the panel look and the shared table', () => {
  const m = JSON.parse(read('manifest.json'));
  assert.equal(m.id, 'ep_tophosts');
  assert.equal(m.widget.js_class, 'WidgetEpTophosts');
  assert.ok(m.actions['widget.ep_tophosts.view']);
  assert.deepEqual(m.actions['widget.ep_tophosts.column.edit'], { class: 'ColumnEdit', view: 'column.edit', layout: 'layout.json' });
  assert.ok(!m.actions['widget.ep_tophosts.columns'], 'its columns are its own: no report defaults');
  assert.deepEqual(m.assets.css, ['ep-table.css', 'ep-form.css', 'ep-panel.css', 'widget.css']);
  for (const f of ['ep-panel.css', 'ep-table.css', 'ep-form.css']) {
    assert.equal(read(`assets/css/${f}`), fs.readFileSync(path.join(ROOT, 'shared', f), 'utf8'), `${f} differs: node sync-assets.mjs`);
  }
  const php = read('actions/WidgetView.php');
  for (const call of ['ClientData::clients(', 'ClientData::servers(', 'new ColumnReport(', '->read(', '->cells(', 'ColumnCell::cell(']) {
    assert.ok(php.includes(call), call);
  }
  assert.ok(!/ColumnMath::(combine|trend|perHost|windowValue)\(/.test(php) && !php.includes('Settings::usageFor('), 'the figures and the thresholds are the engine\'s');
  assert.ok(fs.readFileSync(path.join(ROOT, 'master/import.py'), 'utf8').includes('"ep_tophosts"'), 'import.py makes its dashboard');
});

test('the form: who is ranked, by what, the columns, the view, Warn / High — and the fields of the old widget are read', () => {
  const form = read('includes/WidgetForm.php');
  for (const field of ['groupids', 'clients', 'client_types', 'roles', 'rank', 'rank_key', 'rank_pattern', 'rank_value', 'trend_days', 'count', 'switch_metrics', 'columns', 'view',
    'bars', 'usage_warn', 'usage_high']) {
    assert.ok(form.includes(`'${field}'`), field);
  }
  assert.ok(!/CWidgetFieldIntegerBox\('(usage_warn|usage_high)'/.test(form), 'no integer box for thresholds');
  // The old fields are mapped, not registered.
  assert.match(form, /\$values\['client'\]/);
  assert.match(form, /\$values\['client_type'\]/);
  assert.ok(!form.includes("CWidgetFieldTextBox('client'") && !form.includes("CWidgetFieldSelect('client_type'"));
  assert.match(form, /new CWidgetFieldSelect\('rank'/, 'Rank by keeps its name and its first two choices (0 CPU, 1 memory)');
  const edit = read('views/widget.edit.php');
  assert.ok(form.includes('Any Zabbix item') && form.includes('JVM heap') && form.includes('Disk used'));
  for (const copy of ['Roles', 'Switch on widget', 'Warn / High', "'widget.ep_tophosts.column.edit'", 'Value is the figure the widget ranks by']) {
    assert.ok(edit.includes(copy), copy);
  }
  assert.ok(read('views/widget.edit.js.php').includes('extends window.EpColumnsForm'));
});

test('the panes: the choice is shown, the others hidden, and it survives the refresh (DOM)', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const html = `<div id="b"><div class="ep-seg">
      <button data-ep-rank="cpu" aria-pressed="true">CPU</button><button data-ep-rank="mem" aria-pressed="false">Memory</button><button data-ep-rank="disk" aria-pressed="false">Disk</button></div>
    <div data-ep-pane="cpu">cpu list</div><div data-ep-pane="mem" hidden>mem list</div><div data-ep-pane="disk" hidden>disk list</div></div>`;
  const dom = new lib.JSDOM(`<body>${html}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  w.CWidget = class { setContents() {} };
  w.eval(read('assets/js/class.widget.js') + '\nwindow.W = WidgetEpTophosts;');
  const widget = new w.W();
  widget._body = w.document.getElementById('b');
  const shown = () => [...widget._body.querySelectorAll('[data-ep-pane]')].filter((p) => !p.hidden).map((p) => p.dataset.epPane);
  widget.setContents({});
  assert.deepEqual(shown(), ['cpu']);
  widget._body.querySelector('[data-ep-rank="disk"]').click();
  assert.deepEqual(shown(), ['disk']);
  assert.equal(widget._body.querySelector('[data-ep-rank="disk"]').getAttribute('aria-pressed'), 'true');
  assert.equal(widget._body.querySelector('[data-ep-rank="cpu"]').getAttribute('aria-pressed'), 'false');
  // Zabbix redraws the widget: the server's markup comes back with the first pane showing, and the viewer's choice is put back.
  widget._body.innerHTML = html;
  widget.setContents({});
  assert.deepEqual(shown(), ['disk'], 'the switch survives the refresh');
  // A metric the widget no longer offers falls back to the one the server shows.
  widget._epRank = 'jvm';
  widget._body.innerHTML = html;
  widget.setContents({});
  assert.deepEqual(shown(), ['cpu']);
  // Nothing to draw: no error.
  widget._body.innerHTML = '<div class="ep-empty">nothing</div>';
  widget.setContents({});
});

test('WidgetView against a fake Zabbix (PHP)', (t) => {
  const local = spawnSync('php', ['test/view.test.php'], { cwd: HERE, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${HERE}:/m:ro`, '-w', '/m', 'php:8.4-cli-alpine', 'php', 'test/view.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || noDocker(r)) { t.skip('no php and no docker here — run test/view.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
