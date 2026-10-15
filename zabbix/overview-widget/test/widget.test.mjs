/** The Client overview widget's wiring, and its controller (test/view.test.php) run with PHP. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const HERE = path.resolve(import.meta.dirname, '..');
const ROOT = path.resolve(HERE, '..');
const noDocker = (r) => r.status !== 0 && /docker|daemon/i.test(`${r.stderr || ''}${r.stdout || ''}`)
  && !/passed, \d+ failed|FAIL:/.test(`${r.stdout || ''}`);

test('the manifest names the widget and its controller, and loads the panel look', () => {
  const m = JSON.parse(fs.readFileSync(path.join(HERE, 'manifest.json'), 'utf8'));
  assert.equal(m.id, 'ep_overview');
  assert.equal(m.widget.js_class, 'WidgetEpOverview');
  assert.ok(m.actions['widget.ep_overview.view']);
  assert.deepEqual(m.assets.js, ['ep-overview.js', 'class.widget.js']);
  assert.deepEqual(m.assets.css, ['ep-panel.css', 'widget.css']);
  assert.equal(fs.readFileSync(path.join(HERE, 'assets/css/ep-panel.css'), 'utf8'), fs.readFileSync(path.join(ROOT, 'shared/ep-panel.css'), 'utf8'), 'node sync-assets.mjs');
  const php = fs.readFileSync(path.join(HERE, 'actions/WidgetView.php'), 'utf8');
  for (const call of ['ClientData::clients(', 'ClientData::servers(', 'ClientData::classify(', 'Settings::usageFor(']) assert.ok(php.includes(call), call);
  const form = fs.readFileSync(path.join(HERE, 'includes/WidgetForm.php'), 'utf8');
  for (const field of ["'groupids'", "'client'", "'client_type'", "'show_summary'", "'show_used'", "'show_roles'", "'family'", "'usage_warn'", "'usage_high'"]) assert.ok(form.includes(field), field);
  assert.ok(fs.readFileSync(path.join(ROOT, 'master/import.py'), 'utf8').includes('"ep_overview"'), 'import.py makes its dashboard');
  // The overview left Client resources: it is this widget now.
  const res = fs.readFileSync(path.join(ROOT, 'resources-widget/views/widget.view.php'), 'utf8');
  assert.ok(!res.includes('ep_overview') && !res.includes("'data-ep-view'"), 'Client resources has no overview of its own');
});

test('WidgetView against a fake Zabbix (PHP)', (t) => {
  const local = spawnSync('php', ['test/view.test.php'], { cwd: HERE, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${HERE}:/m:ro`, '-w', '/m', 'php:8.4-cli-alpine', 'php', 'test/view.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || noDocker(r)) { t.skip('no php and no docker here — run test/view.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
