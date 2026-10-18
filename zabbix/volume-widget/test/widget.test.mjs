/** The volume widget's wiring: it keeps no column list, so what is left to check is the frame. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const HERE = path.resolve(import.meta.dirname, '..');
const php = (f) => fs.readFileSync(path.join(HERE, f), 'utf8');

test('the columns come from the client plan template, not from a list here', () => {
  const cat = php('includes/Catalogue.php');
  // The template is whatever the Roles page's mapping says this Zabbix calls it, and both
  // templates this widget reads go through it — the client master lookup was a second literal.
  assert.match(cat, /Roles::templateNamesFor\('plan'\)/);
  assert.match(php('lib/ClientData.php'), /Roles::templateNamesFor\('master'\)/, 'the client master, for each cluster\'s type');
  const view = php('actions/WidgetView.php');
  assert.ok(!view.includes("'ElasticPro client plan'") && !cat.includes("'ElasticPro client plan'"), 'the plan template name comes from the mapping');
  assert.ok(!view.includes("'ElasticPro client master'"), 'and so does the client master name');
  assert.match(cat, /array_key_exists\('column', \$tags\)/, 'columns are the items tagged with their place');
  assert.ok(!fs.existsSync(path.join(HERE, 'columns.json')), 'no column list of its own');
  assert.ok(cat.includes("'Client plan: '"), 'the item-name prefix is stripped from the label');
});

test('its colours are the template\'s own macros unless the widget says each column has its own', () => {
  const cat = php('includes/Catalogue.php');
  assert.match(cat, /\{\$ELASTICPRO\.PLAN\.LIVE_USED\.WARN\}/);
  assert.match(cat, /\{\$ELASTICPRO\.PLAN\.LIVE_DAYS\.MIN\}/);
  const form = php('includes/WidgetForm.php');
  assert.match(form, /CWidgetFieldRadioButtonList\('thresholds_from'/);
  assert.ok(!form.includes('usage_warn') && !form.includes('usage_high'), 'a Warn / High of the widget\'s would be a third place to look');
  const view = php('actions/WidgetView.php');
  assert.match(view, /THRESHOLDS_PLAN/);
  assert.match(view, /function planLevel\(/);
  // The comparisons the template's triggers make: above Warn, under the minimum — not at.
  assert.match(view, /\$value > \$client\['plan'\]\['warn'\]/);
  assert.match(view, /\$value < \$client\['plan'\]\['days'\]/);
});

test('decimals may be overridden, and follow the site otherwise', () => {
  const form = php('includes/WidgetForm.php');
  assert.match(form, /CWidgetFieldSelect\('decimals'/);
  assert.match(form, /Settings::INHERIT_CHOICE/);
  const view = php('actions/WidgetView.php');
  assert.match(view, /\$f\['decimals'\]/);
  assert.match(view, /Settings::decimals\(/);
  assert.match(view, /Settings::defaultType\(/);
});

test('the manifest names the widget, its controller, the Columns dialog, the column editor and the panel', () => {
  const m = JSON.parse(php('manifest.json'));
  assert.equal(m.id, 'ep_volume');
  assert.equal(m.widget.js_class, 'WidgetEpVolume');
  const js = php('assets/js/class.widget.js');
  assert.ok(js.includes('class WidgetEpVolume extends CWidget') && js.includes('EpColumns.init') && js.includes('EpExport.bind'));
  assert.match(js, /EpPanel\.init\(this, this\._body, 'volume', \{view:/, 'search and sort, from the shared panel');
  assert.ok(m.actions['widget.ep_volume.view']);
  assert.equal(m.actions['widget.ep_volume.columns'].class, 'ColumnsSave');
  assert.deepEqual(m.actions['widget.ep_volume.column.edit'], { class: 'ColumnEdit', view: 'column.edit', layout: 'layout.json' });
  assert.deepEqual(m.assets.css, ['ep-widget.css', 'ep-table.css', 'ep-form.css', 'ep-panel.css']);
  assert.deepEqual(m.assets.js, ['ep-xlsx.js', 'ep-export.js', 'ep-columns.js', 'ep-panel.js', 'class.widget.js']);
});

test('the form has the three tabs and a column is one of the plan\'s items or any other', () => {
  const form = php('includes/WidgetForm.php') + php('includes/TableFields.php');
  for (const field of ['groupids', 'clients', 'client_types', 'trend_days', 'columns_from', 'columns', 'thresholds_from', 'bars', 'decimals', 'density', 'group_headers',
    'totals', 'sort_by', 'top_n', 'toolbar_items', 'toolbar_pos', 'attention']) {
    assert.ok(form.includes(`'${field}'`), field);
  }
  const edit = php('views/widget.edit.php');
  for (const tab of ["_('Data')", "_('Columns')", "_('View')"]) assert.ok(edit.includes(tab), tab);
  assert.ok(edit.includes("'widget.ep_volume.column.edit'"));
  const cat = php('includes/Catalogue.php');
  assert.match(cat, /public static function single\(\): bool \{\s*return true;/, 'a row is one host: no roles, no combine');
  assert.match(cat, /public static function editorHosts\(/, 'the editor looks at the cluster hosts');
  assert.match(cat, /public static function newHidden\(\): bool \{\s*return false;/, 'a column the template gains arrives shown');
});

test('the Columns dialog and the Type filter: settings applied, client type from the master hosts', () => {
  const view = php('actions/WidgetView.php');
  assert.match(view, /ColumnReport::resolve\(/);
  assert.match(view, /ClientData::clients\(/, 'a cluster\'s type from its client\'s master host');
  const tpl = php('views/widget.view.php');
  for (const hook of ['ColumnTable::panel(', 'ColumnTable::toolbar(', 'ColumnTable::table(', "setVar('ep_meta'", "setVar('export'"]) assert.ok(tpl.includes(hook), hook);
  const table = php('includes/ColumnTable.php');
  for (const hook of ['data-ep-type-filter', 'data-ep-columns', "'data-type'"]) assert.ok(table.includes(hook), hook);
});
