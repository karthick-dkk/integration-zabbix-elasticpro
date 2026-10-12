/** The volume widget's wiring: it keeps no column list, so what is left to check is the frame. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const HERE = path.resolve(import.meta.dirname, '..');
const php = (f) => fs.readFileSync(path.join(HERE, f), 'utf8');

test('the columns come from the client plan template, not from a list here', () => {
  const view = php('actions/WidgetView.php');
  // The template is whatever the Roles page's mapping says this Zabbix calls it, and both
  // templates this widget reads go through it — the client master lookup was a second literal.
  assert.match(view, /Roles::templateNamesFor\('plan'\)/);
  assert.match(view, /Roles::templateNamesFor\('master'\)/);
  assert.ok(!view.includes("'ElasticPro client plan'"), 'the plan template name comes from the mapping');
  assert.ok(!view.includes("'ElasticPro client master'"), 'and so does the client master name');
  assert.match(view, /array_key_exists\('column', \$tags\)/, 'columns are the items tagged with their place');
  assert.ok(!fs.existsSync(path.join(HERE, 'columns.json')), 'no column list of its own');
});

test('its thresholds stay the template\'s own, and only decimals may be overridden', () => {
  const form = php('includes/WidgetForm.php');
  // A dashboard allowed its own thresholds could show green for something already alarming.
  assert.ok(!form.includes('usage_warn') && !form.includes('usage_high'), 'no threshold override here');
  assert.match(form, /CWidgetFieldSelect\('decimals'/);
  assert.match(form, /Settings::INHERIT_CHOICE/);
  const view = php('actions/WidgetView.php');
  assert.match(view, /\$this->fields_values\['decimals'\]/);
  assert.match(view, /Settings::decimals\(/);
  assert.match(view, /Settings::defaultType\(/);
});

test('it colours only what the template’s triggers alarm on, at their thresholds', () => {
  const view = php('actions/WidgetView.php');
  assert.match(view, /\{\$ELASTICPRO\.PLAN\.LIVE_USED\.WARN\}/);
  assert.match(view, /\{\$ELASTICPRO\.PLAN\.LIVE_DAYS\.MIN\}/);
});

test('the manifest names the widget and its controller', () => {
  const m = JSON.parse(php('manifest.json'));
  assert.equal(m.id, 'ep_volume');
  assert.equal(m.widget.js_class, 'WidgetEpVolume');
  assert.ok(php('assets/js/class.widget.js').includes('class WidgetEpVolume extends CWidget'));
  assert.ok(m.actions['widget.ep_volume.view']);
});

test('the Columns dialog and the Type filter: settings applied, client type from the master hosts', () => {
  const view = php('actions/WidgetView.php');
  assert.match(view, /ColumnSettings::load\('volume'\)/);
  assert.match(view, /'id' => \$item\['key_'\]/, 'a column is known by its item key');
  assert.match(view, /\{\$EP\.CLIENT\.TYPE\}/);
  const tpl = php('views/widget.view.php');
  for (const hook of ['data-ep-type-filter', 'data-ep-columns', "setVar('ep_meta'", "'data-type'"]) assert.ok(tpl.includes(hook), hook);
  assert.ok(php('assets/js/class.widget.js').includes('EpColumns.init'));
});
