/** The capacity widget's column catalogue, and its wiring onto the shared column engine. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const HERE = path.resolve(import.meta.dirname, '..');
const read = (f) => fs.readFileSync(path.join(HERE, f), 'utf8');

test('every column the widget draws is one the master template defines', () => {
  const cols = JSON.parse(read('columns.json'));
  // The catalogue and nothing else: the template name now comes from the Roles page's mapping
  // and the thresholds from ElasticPro -> Settings, so neither may creep back in here as a
  // fourth place to look.
  assert.deepEqual(Object.keys(cols), ['columns']);
  assert.equal(cols.columns.length, 31);
  // When storage fills is read the Cluster Management way (shared Forecast), not as a raw figure.
  assert.deepEqual(cols.columns.filter((c) => c.kind === 'forecast').map((c) => c.key), ['ep.es.storage.full_in']);
  // Requested and purchased columns are marked, so 0 can be shown as "not set".
  assert.deepEqual(cols.columns.filter((c) => c.kind === 'requested').map((c) => c.label),
    ['Cust.Purchased Storage', 'Cust.Purchased Devices', 'ES CPU Requested', 'ES Mem Requested', 'Parser CPU Requested', 'Parser Mem Requested',
     'Forwarder Mem Requested', 'Forwarder CPU Requested', 'Forwarder Requested / Storage']);
});

test('its family keys are the master template\'s: families es, parser and fwd exist in the default roles', () => {
  const cols = JSON.parse(read('columns.json')).columns;
  const roles = fs.readFileSync(path.join(HERE, '..', 'shared/php/Roles.php'), 'utf8');
  for (const c of cols.filter((c) => c.key && !c.key.startsWith('ep.delay') && !c.key.startsWith('ep.es.storage') && !c.key.startsWith('ep.devices'))) {
    const m = c.key.match(/^ep\.([a-z_]+)\.(servers|cpu|mem|disk)\.(requested|allocated|usage|used)$/);
    assert.ok(m, c.key);
    assert.match(roles, new RegExp(`'id' => '${m[1]}'`), `family ${m[1]} is not a default family`);
  }
});

test('the manifest names the widget, its controller, the Columns dialog and the column editor', () => {
  const m = JSON.parse(read('manifest.json'));
  assert.equal(m.id, 'ep_capacity');
  assert.equal(m.widget.js_class, 'WidgetEpCapacity');
  assert.ok(m.actions['widget.ep_capacity.view']);
  assert.equal(m.actions['widget.ep_capacity.columns'].class, 'ColumnsSave');
  assert.deepEqual(m.actions['widget.ep_capacity.column.edit'], { class: 'ColumnEdit', view: 'column.edit', layout: 'layout.json' });
  assert.deepEqual(m.assets.css, ['ep-widget.css', 'ep-table.css', 'ep-form.css', 'ep-panel.css']);
  assert.deepEqual(m.assets.js, ['ep-xlsx.js', 'ep-export.js', 'ep-columns.js', 'ep-panel.js', 'class.widget.js']);
  for (const f of m.assets.css) assert.ok(fs.existsSync(path.join(HERE, 'assets/css', f)), f);
  const js = read('assets/js/class.widget.js');
  assert.ok(js.includes('class WidgetEpCapacity extends CWidget') && js.includes('EpColumns.init') && js.includes('EpExport.bind'));
});

test('the form has the three tabs of the design and every setting on them, and still reads the old fields', () => {
  const form = read('includes/WidgetForm.php') + read('includes/TableFields.php');
  for (const field of ['groupids', 'clients', 'client_types', 'trend_days', 'columns_from', 'columns', 'bars', 'usage_warn', 'usage_high', 'density', 'row_height',
    'group_headers', 'sticky_header', 'freeze_first', 'striped', 'totals', 'totals_fn', 'sort_by', 'sort_dir', 'top_n', 'toolbar_items', 'toolbar_pos', 'attention',
    'attention_default']) {
    assert.ok(form.includes(`'${field}'`), field);
  }
  // Warn and High: empty means Settings, never a 0 in the box.
  assert.ok(!/CWidgetFieldIntegerBox\('(usage_warn|usage_high)'/.test(form), 'no integer box for thresholds');
  assert.match(form, /CWidgetFieldOptionalNumber\('usage_warn'/);
  assert.ok(!form.includes('server_columns'), 'no server rows: it is a flat table');
  const edit = read('views/widget.edit.php');
  for (const tab of ["_('Data')", "_('Columns')", "_('View')"]) assert.ok(edit.includes(tab), tab);
  assert.match(edit, /f->tabs\('ep_cf_tabs'/, 'the tabs are started on their own element');
  const shared = read('includes/ColumnForm.php');
  for (const copy of ['Report defaults are set by a Super admin', 'Reset to report defaults', 'Add column', 'Add group', 'Add avg / min / max for a column',
    "_s('Settings: %1\$s'"]) {
    assert.ok(shared.includes(copy), copy);
  }
  assert.ok(edit.includes('Needs attention means') && edit.includes('Toolbar on the widget'));
  assert.ok(edit.includes("'widget.ep_capacity.column.edit'"), 'Edit and Add open the column editor');
  assert.ok(read('views/widget.edit.js.php').includes('new window.EpColumnsForm()'));
});

test('one definition: the figures, cells, totals and export are the shared column engine\'s', () => {
  const ctl = read('actions/WidgetView.php');
  for (const call of ['ColumnReport::resolve(', 'new ColumnReport(', '->read(', '->cells(', '->totals(', '->exportHeaders(', 'TableOptions::read(', 'ColumnCell::arrange(',
    'ColumnCell::attention(', 'Settings::paletteStyle(', 'ClientData::clients(']) {
    assert.ok(ctl.includes(call), call);
  }
  assert.ok(!/ColumnMath::|convertUnits\(|Settings::usageFor\(/.test(ctl), 'no arithmetic or formatting of its own in the controller');
  // The numbers and the name come from one place each, and no literal is left beside them.
  assert.ok(!ctl.includes('1073741824'), 'the GB divisor is a setting now, not a literal');
  assert.ok(!ctl.includes("'ElasticPro client master'"), 'the template name comes from the mapping');
  assert.ok(fs.readFileSync(path.join(HERE, 'lib/ClientData.php'), 'utf8').includes('Roles::templateNamesFor(\'master\')'));
  // The catalogue is columns.json, under the ids a Columns file has always used.
  const cat = read('includes/Catalogue.php');
  assert.match(cat, /columns\.json/);
  assert.match(cat, /'client\.'\.\(\$c\['source'\] === 'host' \? 'name' : 'url'\)/);
  assert.ok(read('actions/ColumnEdit.php').includes('Columns::clean('), 'the editor applies the same rule');
});

test('the view draws with ColumnTable, and keeps no Table settings of the viewer\'s in the browser', () => {
  const view = read('views/widget.view.php');
  for (const hook of ['ColumnTable::panel(', 'ColumnTable::toolbar(', 'ColumnTable::table(', "setVar('ep_meta'", "setVar('export'", "'capacity'"]) assert.ok(view.includes(hook), hook);
  assert.ok(!read('includes/ColumnTable.php').includes('data-ep-settings'));
  assert.match(read('assets/js/class.widget.js'), /EpPanel\.init\(this, this\._body, 'capacity', \{view:/);
  assert.ok(!/new CUrl\(/.test(view), 'no address built in the view');
});
