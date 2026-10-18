/** The Client overview widget's wiring, and its controller (test/view.test.php) run with PHP. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const HERE = path.resolve(import.meta.dirname, '..');
const ROOT = path.resolve(HERE, '..');
const read = (f) => fs.readFileSync(path.join(HERE, f), 'utf8');
const noDocker = (r) => r.status !== 0 && /docker|daemon/i.test(`${r.stderr || ''}${r.stdout || ''}`)
  && !/passed, \d+ failed|FAIL:/.test(`${r.stdout || ''}`);

test('the manifest names the widget, its controller and the metric editor, and loads the panel look', () => {
  const m = JSON.parse(read('manifest.json'));
  assert.equal(m.id, 'ep_overview');
  assert.match(m.version, /^\d+\.\d+\.\d+$/); // the one shared version; tools/test/version.test.mjs holds the modules to the same one
  assert.equal(m.widget.js_class, 'WidgetEpOverview');
  assert.ok(m.actions['widget.ep_overview.view']);
  assert.deepEqual(m.actions['widget.ep_overview.metric.edit'], { class: 'MetricEdit', view: 'metric.edit', layout: 'layout.json' });
  for (const f of ['actions/MetricEdit.php', 'views/metric.edit.php', 'views/metric.edit.js.php', 'views/widget.edit.js.php']) assert.ok(fs.existsSync(path.join(HERE, f)), f);
  assert.deepEqual(m.assets.js, ['ep-overview.js', 'ep-overview-summary.js', 'class.widget.js']);
  assert.deepEqual(m.assets.css, ['ep-panel.css', 'widget.css']);
  assert.equal(read('assets/css/ep-panel.css'), fs.readFileSync(path.join(ROOT, 'shared/ep-panel.css'), 'utf8'), 'node sync-assets.mjs');
  assert.match(m.description, /Data and metrics/);
  assert.match(m.description, /Layout and production/);
  assert.ok(!/elasticvue/i.test(JSON.stringify(m)), 'ElasticPro only');
});

test('one definition: clients, servers, thresholds, forecast and the metric rule each come from one place', () => {
  const php = read('actions/WidgetView.php');
  for (const call of ['ClientData::clients(', 'ClientData::servers(', 'ClientData::known(', 'ClientData::usageClass(', 'ClientData::contractEnd(',
    'Settings::usageFor(', 'Forecast::fullIn(', 'Overview::level(', 'Overview::combine(', 'Overview::trend(', 'Overview::arrange(', 'Overview::countProblems(']) {
    assert.ok(php.includes(call), call);
  }
  assert.ok(read('includes/Overview.php').includes('ClientData::usageClass('), 'Settings levels are ClientData\'s');
  assert.ok(read('includes/Overview.php').includes('Forecast::fullIn('), 'forecast bands are Forecast\'s');
  // The editor and the field apply the same rule.
  assert.ok(read('actions/MetricEdit.php').includes('Metrics::clean('));
  assert.ok(read('includes/CWidgetFieldMetrics.php').includes('Metrics::clean('));
  // Batched: the controller never calls the API inside the per-client function.
  const client = php.slice(php.indexOf('private function client('), php.indexOf('private function notCollected('));
  assert.ok(!/API::/.test(client), 'no API call per client');
});

test('the form has both tabs and every setting of the design, and still reads the old fields', () => {
  const form = read('includes/WidgetForm.php');
  for (const field of ['groupids', 'clients', 'hide_inactive', 'client_types', 'trend_source', 'trend_days', 'time_period', 'metrics', 'order_by', 'order_dir',
    'top_n', 'only_show', 'section_order', 'sections', 'columns', 'card_width', 'density', 'used_measure', 'used_show', 'used_style', 'forecast_days',
    'forecast_warn', 'forecast_high', 'contract_badge', 'contract_warn', 'contract_high', 'roles_style', 'role_order', 'roles_hidden', 'roles_servers',
    'down_red', 'down_after', 'severities', 'problems_ack', 'problems_suppressed', 'hide_zero', 'stale_after', 'warn_agent', 'warn_nodata',
    'warn_maintenance', 'link_client', 'link_severity', 'link_target', 'config_version', 'view', 'summary_family', 'summary_panels',
    'summary_warn', 'summary_high']) {
    assert.ok(form.includes(`'${field}'`), field);
  }
  for (const old of ['client', 'client_type', 'show_summary', 'show_used', 'show_roles', 'family', 'usage_warn', 'usage_high']) {
    assert.ok(form.includes(`'${old}'`), `old field ${old} is read`);
  }
  const edit = read('views/widget.edit.php');
  assert.match(edit, /_\('Data and metrics'\)/);
  assert.match(edit, /_\('Layout and production'\)/);
  assert.ok(edit.includes("addVar('config_version'"), 'a saved form is marked as the new shape');
  // jQuery UI tabs are started on the id CTabView was constructed with: setId() alone leaves bare links.
  assert.match(edit, /new CTabView\(\['id' => 'ep_ov_tabs'\]\)/);
  assert.ok(!/->setId\('ep_ov_tabs'\)/.test(edit), 'no setId on the tab view');
  assert.ok(edit.includes("_('Summary view')") && edit.includes('ep-ov-cards-only'), 'each view\'s settings are grouped');
  // Warn and High: empty means Settings, never a 0 in the box.
  assert.ok(!/CWidgetFieldIntegerBox\('(usage_warn|usage_high)'/.test(form), 'no integer box for thresholds');
  assert.ok(read('views/metric.edit.php').includes("_s('Settings: %1\$s'"), 'the editor shows the Settings value as the placeholder');
  assert.ok(fs.readFileSync(path.join(ROOT, 'master/import.py'), 'utf8').includes('"ep_overview"'), 'import.py makes its dashboard');
  const res = fs.readFileSync(path.join(ROOT, 'resources-widget/views/widget.view.php'), 'utf8');
  assert.ok(!res.includes('ep_overview') && !res.includes("'data-ep-view'"), 'Client resources has no overview of its own');
});

test('the Summary view is the 2.0 widget restored, with its own scoped styles', () => {
  const css = read('assets/css/widget.css');
  assert.ok(css.includes('.ep-panel .ep-ov-sv .ep-ov-trio'), 'old card rules, scoped to the Summary view');
  assert.ok(css.includes('.ep-panel .ep-ov-cv .ep-ov-card {'), 'new card rules, scoped to Cards');
  assert.ok(!/^\.ep-panel \.ep-ov-card /m.test(css), 'no card rule that reaches both views');
  const php = read('actions/WidgetView.php');
  assert.ok(php.includes('private function summary(array $clients)') && php.includes('ClientData::classify('), 'the 2.0 controller path, on ClientData');
});

test('metric names are never cut in the cards, and a narrow card keeps its content inside', () => {
  const css = read('assets/css/widget.css');
  assert.match(css, /container: ep-ov-card \/ inline-size/);
  assert.match(css, /@container ep-ov-card \(max-width: 440px\)[\s\S]*?thead \{ display: none; \}/);
  assert.match(css, /\.ep-ov-mwrap \{ max-width: 100%; overflow-x: auto; \}/);
  const rule = css.split('\n').find((l) => l.startsWith('.ep-panel .ep-ov-cv .ep-ov-metrics tbody th {'));
  assert.ok(rule && /white-space: normal/.test(rule) && /min-width: 16ch/.test(rule) && !/ellipsis/.test(rule), rule);
});

test('the edit dialog: one width on both tabs, a live Clients hint, and the editor clears what a built-in filled in', () => {
  assert.match(read('assets/css/widget.css'), /form\.dashboard-widget-ep_overview \{ width: 1000px;/);
  const js = read('views/widget.edit.js.php');
  assert.ok(js.includes("getElementById('ep_ov_clients_note')") && js.includes("e.target.name === 'clients[]'"));
  const ed = read('views/metric.edit.js.php');
  assert.ok(ed.includes('_sourceChanged()') && ed.includes("delete e.target.dataset.epAuto"));
  assert.ok(!/if \(this\._form\.querySelector\('\[name="name"\]'\)\.value === ''\) \{\s*this\._prefill\(\);/.test(ed), 'Add opens with an empty name');
  assert.ok(read('includes/WidgetForm.php').includes("_('Client dashboard')"));
  assert.ok(read('actions/MetricEdit.php').includes('Metrics::named('), 'the editor names the metric in its errors');
});

test('no field in the edit dialog is wider than the dialog', () => {
  const css = read('assets/css/widget.css');
  // The field column takes what is left and no more; hints wrap inside it.
  assert.match(css, /\.ep-ov-tabs \.form-grid \{ grid-template-columns: minmax\(15%, max-content\) minmax\(0, 1fr\); \}/);
  assert.match(css, /\.ep-ov-tabs \.form-field \{ min-width: 0; max-width: 100%;/);
  assert.match(css, /\.ep-ov-hint \{ white-space: normal;/);
  const edit = read('views/widget.edit.php');
  // Every hint is short enough to sit on a line or two, and none is put inside a no-wrap row unbroken.
  for (const m of edit.matchAll(/\$hint\(_\('((?:[^'\\]|\\.)*)'\)/g)) assert.ok(m[1].length <= 140, `hint too long: ${m[1]}`);
  const view = edit.match(/Cards: the metrics and sections set here\.[^']*/);
  assert.ok(view && view[0].length <= 100 && edit.includes("addClass('ep-ov-hint-block')"), 'the View hint is short and a block of its own');
});

test('links go to real Zabbix 7.0 pages', () => {
  const php = read('actions/WidgetView.php') + read('includes/Overview.php');
  for (const action of ["'problem.view'", "'host.dashboard.view'", "'latest.view'", "'ep.clients.edit'", "'elasticpro.overview'", "'showgraph'", "'batchgraph'"]) {
    assert.ok(php.includes(action), action);
  }
  const js = read('assets/js/ep-overview.js');
  assert.ok(js.includes('(zabbix|history)\\.php'), 'the cards only link to the pages the controller names');
});

test('WidgetView and the metric rules against a fake Zabbix (PHP)', (t) => {
  const local = spawnSync('php', ['test/view.test.php'], { cwd: HERE, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${HERE}:/m:ro`, '-w', '/m', 'php:8.4-cli-alpine', 'php', 'test/view.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || noDocker(r)) { t.skip('no php and no docker here — run test/view.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
