/**
 * The widgets' export (ep-export.js) and spreadsheet writer (ep-xlsx.js), run as the
 * browser runs them — plain scripts on a window — and every widget's copy of them.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { pathToFileURL } from 'node:url';

const HERE = path.resolve(import.meta.dirname, '..');
const ROOT = path.resolve(HERE, '..');
const ctx = { window: {}, TextEncoder, Uint8Array, Uint32Array, DataView, console };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(HERE, 'ep-xlsx.js'), 'utf8'), ctx);
vm.runInContext(fs.readFileSync(path.join(HERE, 'ep-export.js'), 'utf8'), ctx);
const X = ctx.window.EpExport;
const plain = (v) => JSON.parse(JSON.stringify(v));   // built inside the sandbox

test('CSV: quoted where needed, unknown left empty, never a formula', () => {
  const csv = X.csv(['Cluster Name', 'ES URL', 'ES Used % (%)'], [['acme, inc', '=HYPERLINK("x")', 81.5], ['lab', 'http://es', null]]);
  assert.equal(csv, 'Cluster Name,ES URL,ES Used % (%)\r\n"acme, inc","\'=HYPERLINK(""x"")",81.5\r\nlab,http://es,\r\n');
});

test('Excel: numbers stay numbers, unknowns stay empty, text that looks like a formula is text', () => {
  const rows = X.xlsxRows(['A', 'B'], [[1.5, null], ['+cmd', 'ok']]);
  assert.deepEqual(plain(rows), [['A', 'B'], [1.5, null], ["'+cmd", 'ok']]);
  const bytes = ctx.window.EpXlsx.workbook([{ name: 'Sheet', rows }]);
  assert.equal(bytes[0], 0x50); assert.equal(bytes[1], 0x4b, 'a ZIP, as an .xlsx is');
  assert.match(Buffer.from(bytes).toString('latin1'), /<c r="A2"><v>1\.5<\/v><\/c><c r="B2"\/>/, 'a number, then an empty cell — not a zero');
});

test('the file is named for what it is and when it was taken', () => {
  assert.equal(X.filename('volume-report', 'xlsx', new Date(2026, 8, 26, 9, 5)), 'volume-report-2026-09-26_0905.xlsx');
});

test('every widget carries the shared scripts exactly', async () => {
  const { SHARED, WIDGETS } = await import(pathToFileURL(path.join(ROOT, 'sync-assets.mjs')).href);
  for (const w of WIDGETS) {
    for (const f of SHARED) {
      assert.equal(fs.readFileSync(path.join(ROOT, w, 'assets/js', f), 'utf8'), fs.readFileSync(path.join(HERE, f), 'utf8'),
        `${w}/assets/js/${f} differs from shared/ — run: node sync-assets.mjs`);
    }
    const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, w, 'manifest.json'), 'utf8'));
    assert.deepEqual(manifest.assets.js.slice(0, SHARED.length), SHARED, `${w}: the shared scripts must load before the widget's own`);
  }
});

test('the spreadsheet writer is still ElasticPro’s ui/js/lib/xlsx.js', (t) => {
  // The original lives in the ElasticPro repository, a separate checkout since the modules moved
  // out of it: ELASTICPRO_REPO names it. Without it this one check is skipped, and says so.
  const repo = process.env.ELASTICPRO_REPO || path.resolve(ROOT, '../..');
  const original = path.join(repo, 'ui/js/lib/xlsx.js');
  if (!fs.existsSync(original)) { t.skip('ui/js/lib/xlsx.js not found — set ELASTICPRO_REPO to an ElasticPro checkout'); return; }
  const strip = (s) => s.replace(/^export (function|const)/gm, '$1').trim();
  assert.ok(fs.readFileSync(path.join(HERE, 'ep-xlsx.js'), 'utf8').includes(strip(fs.readFileSync(original, 'utf8'))),
    'copy drifted: re-copy ui/js/lib/xlsx.js into shared/ep-xlsx.js');
});

test('and nobody has edited it here', () => {
  // The check above can only run inside the ElasticPro repository, so in a standalone checkout
  // — which is how these modules are usually worked on — the spreadsheet writer was the one
  // file that could be changed with no test noticing at all. This pins it where it is.
  //
  // When ui/js/lib/xlsx.js genuinely moves on: re-copy it, run the test above from the
  // ElasticPro repository to prove the copy is right, then put the new digest here.
  const KNOWN = '5b352cb36a017e5c5cebe577e4608875d12848742ac0c2aa4b5317b52605e6d6';
  const got = crypto.createHash('sha256').update(fs.readFileSync(path.join(HERE, 'ep-xlsx.js'))).digest('hex');
  assert.equal(got, KNOWN, 'shared/ep-xlsx.js changed — it is a copy of ElasticPro’s ui/js/lib/xlsx.js, not a file to edit here');
});

test('the modules use only Zabbix constants confirmed to exist in Zabbix 7.0', () => {
  // PHP's syntax check cannot see an undefined constant; Zabbix finds it at run time, as a
  // widget stuck loading. ZBX_STYLE_NOTHING_TO_SHOW was one: it does not exist in 7.0.
  // Confirmed against include/defines.inc.php of Zabbix 7.0.31. Add one here only after checking it there.
  const CONFIRMED = new Set(['API_OUTPUT_COUNT', 'INTERFACE_PRIMARY', 'INTERFACE_TYPE_AGENT', 'INTERFACE_USE_DNS', 'INTERFACE_USE_IP',
    'ITEM_STATE_NORMAL', 'ITEM_VALUE_TYPE_FLOAT', 'ITEM_VALUE_TYPE_UINT64', 'USER_TYPE_SUPER_ADMIN', 'USER_TYPE_ZABBIX_ADMIN', 'USER_TYPE_ZABBIX_USER', 'TAG_OPERATOR_EQUAL', 'ZBX_REPORT_PERIOD_WEEK', 'ZBX_REPORT_CYCLE_WEEKLY', 'ZBX_REPORT_STATUS_ENABLED', 'ZBX_WIDGET_FIELD_TYPE_GROUP', 'GROUP_GUI_ACCESS_DISABLED', 'PERM_READ', 'MEDIA_STATUS_ACTIVE', 'MEDIA_TYPE_EMAIL', 'MEDIA_TYPE_STATUS_ACTIVE', 'EVENT_SOURCE_TRIGGERS', 'ACTION_STATUS_ENABLED', 'ACTION_PAUSE_SUPPRESSED_TRUE', 'CONDITION_EVAL_TYPE_AND_OR', 'ZBX_CONDITION_TYPE_HOST_GROUP', 'CONDITION_OPERATOR_EQUAL', 'OPERATION_TYPE_MESSAGE', 'OPERATION_TYPE_RECOVERY_MESSAGE', 'ZBX_MACRO_TYPE_SECRET',
    'ZBX_MACRO_TYPE_VAULT', 'ZBX_STYLE_BTN_ALT', 'ZBX_STYLE_LIST_TABLE', 'ZBX_TEXTAREA_SMALL_WIDTH', 'ZBX_TEXTAREA_STANDARD_WIDTH',
    'ZBX_TEXTAREA_TINY_WIDTH', 'HOST_INVENTORY_AUTOMATIC', 'HOST_INVENTORY_DISABLED', 'ZBX_MONITORED_BY_SERVER',
    'ZBX_MONITORED_BY_PROXY', 'ZBX_MONITORED_BY_PROXY_GROUP', 'ZBX_SIDEBAR_VIEW_MODE_COMPACT', 'ZBX_TM_TASK_CHECK_NOW',
    'ITEM_STATE_NOTSUPPORTED', 'HOST_STATUS_MONITORED', 'HOST_STATUS_NOT_MONITORED', 'MAINTENANCE_TYPE_NORMAL', 'MAINTENANCE_TYPE_NODATA',
    'TIMEPERIOD_TYPE_ONETIME', 'TRIGGER_VALUE_TRUE', 'TRIGGER_SEVERITY_HIGH',
    // The column editor and the widget fields (2.1.1), checked in the same file of 7.0.31.
    'ZBX_WIDGET_FIELD_TYPE_STR', 'ZBX_WIDGET_FIELD_TYPE_INT32', 'ZBX_STYLE_BTN_LINK', 'ZBX_STYLE_DRAG_ICON', 'ZBX_STYLE_TD_DRAG_ICON',
    'ZBX_STYLE_FORM_SUBMIT_HIDDEN', 'ZBX_STYLE_HOR_LIST', 'ZBX_STYLE_TABLE_FORMS', 'ZBX_STYLE_TABLE_FORMS_SEPARATOR', 'ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH']);
  const used = new Set();
  const scan = (dir) => {
    for (const f of fs.readdirSync(dir, {withFileTypes: true})) {
      const p = path.join(dir, f.name);
      if (f.isDirectory()) { if (!['test', 'assets'].includes(f.name)) scan(p); continue; }
      if (!f.name.endsWith('.php')) continue;
      for (const m of fs.readFileSync(p, 'utf8').matchAll(/\b(ZBX_[A-Z_]+|ITEM_[A-Z0-9_]+|USER_TYPE_[A-Z_]+|INTERFACE_[A-Z_]+|API_OUTPUT_[A-Z_]+|HOST_INVENTORY_[A-Z_]+|HOST_STATUS_[A-Z_]+|MAINTENANCE_[A-Z_]+|TIMEPERIOD_[A-Z_]+|TRIGGER_[A-Z_]+)\b/g)) {
        used.add(`${path.relative(ROOT, p)}: ${m[1]}`);
      }
    }
  };
  for (const w of ['capacity-widget', 'volume-widget', 'resources-widget', 'clients-module']) scan(path.join(ROOT, w));
  const unknown = [...used].filter((u) => !CONFIRMED.has(u.split(': ')[1]));
  assert.deepEqual(unknown, []);
});

test('every module has the shared styles, PHP and the Columns action, as sync-assets.mjs writes them', async () => {
  const { WIDGETS, SHARED_CSS, SHARED_PHP, SHARED_ACTIONS, SHARED_INCLUDES, SHARED_VIEWS, PHP_MODULES, phpFor } = await import(pathToFileURL(path.join(ROOT, 'sync-assets.mjs')).href);
  // The widget fields and the column editor's views, under each widget's namespace.
  for (const [map, from, to] of [[SHARED_INCLUDES, 'php/includes', 'includes'], [SHARED_VIEWS, 'php/views', 'views']]) {
    for (const [f, mods] of Object.entries(map)) {
      for (const m of mods) {
        const copy = fs.readFileSync(path.join(ROOT, m, to, f), 'utf8');
        assert.equal(copy, phpFor(fs.readFileSync(path.join(HERE, from, f), 'utf8'), PHP_MODULES[m]), `${m}/${to}/${f} — run: node sync-assets.mjs`);
        assert.doesNotMatch(copy, /EpShared/, `${m}/${to}/${f} still names EpShared`);
      }
    }
  }
  for (const w of WIDGETS) {
    for (const f of SHARED_CSS) {
      assert.equal(fs.readFileSync(path.join(ROOT, w, 'assets/css', f), 'utf8'), fs.readFileSync(path.join(HERE, f), 'utf8'), `${w}/assets/css/${f} — run: node sync-assets.mjs`);
    }
  }
  for (const [m, ns] of Object.entries(PHP_MODULES)) {
    for (const f of SHARED_PHP) {
      assert.equal(fs.readFileSync(path.join(ROOT, m, 'lib', f), 'utf8'), phpFor(fs.readFileSync(path.join(HERE, 'php', f), 'utf8'), ns), `${m}/lib/${f} — run: node sync-assets.mjs`);
    }
  }
  for (const [f, mods] of Object.entries(SHARED_ACTIONS)) {
    for (const m of mods) {
      const copy = fs.readFileSync(path.join(ROOT, m, 'actions', f), 'utf8');
      assert.equal(copy, phpFor(fs.readFileSync(path.join(HERE, 'php/actions', f), 'utf8'), PHP_MODULES[m]), `${m}/actions/${f} — run: node sync-assets.mjs`);
      assert.match(copy, new RegExp(`namespace Modules\\\\${PHP_MODULES[m]}\\\\Actions;`));
      assert.doesNotMatch(copy, /EpShared/);
      const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, m, 'manifest.json'), 'utf8'));
      const cls = f.replace(/\.php$/, '');
      assert.ok(Object.values(manifest.actions).some((a) => a.class === cls), `${m}: manifest lacks the ${cls} action`);
      if (cls !== 'ColumnsSave') continue;
      const [name, action] = Object.entries(manifest.actions).find(([, a]) => a.class === 'ColumnsSave') || [];
      assert.ok(action, `${m}: manifest lacks the ColumnsSave action`);
      assert.equal(action.layout, 'layout.json');
      // The controller hands the dialog a token for exactly that action; Zabbix checks it by name.
      assert.ok(fs.readFileSync(path.join(ROOT, m, 'actions/WidgetView.php'), 'utf8').includes(`CCsrfTokenHelper::get('${name}')`), `${m}: token for ${name}`);
    }
  }
});

test('each widget may write only its own report', () => {
  // The action accepted any of the three report names from the request, so the volume widget's
  // endpoint could rewrite the capacity report's settings — three ways in to each file.
  const cs = fs.readFileSync(path.join(HERE, 'php/actions/ColumnsSave.php'), 'utf8');
  assert.match(cs, /'required\|in '\.\$this->own\(\)/, 'the report comes from the action, not the request');
  assert.match(cs, /explode\('\.', \$this->getAction\(\)\)/);
  assert.ok(!/in resources,volume,capacity/.test(cs), 'no list of all three in the input rules');
  for (const [dir, id, report] of [['capacity-widget', 'ep_capacity', 'capacity'],
      ['resources-widget', 'ep_resources', 'resources'], ['volume-widget', 'ep_volume', 'volume']]) {
    assert.ok(cs.includes(`'${id}' => '${report}'`), `${id} maps to ${report}`);
    const m = JSON.parse(fs.readFileSync(path.join(ROOT, dir, 'manifest.json'), 'utf8'));
    assert.ok(m.actions[`widget.${id}.columns`], `${dir}: the Columns action is named for the widget`);
    // The widget id in the action name is what own() reads, so the two must be the same word.
    assert.equal(m.id, id, `${dir}: manifest id`);
  }
});

test('the widgets tell the Columns dialog when the data folder cannot be written to', async () => {
  // It used to find out by failing on Save; the Cluster Management page has always said so.
  const { WIDGETS } = await import(pathToFileURL(path.join(ROOT, 'sync-assets.mjs')).href);
  for (const w of WIDGETS) {
    assert.match(fs.readFileSync(path.join(ROOT, w, 'actions/WidgetView.php'), 'utf8'), /'storeOk' => Store::writable\(\)/, w);
  }
  assert.match(fs.readFileSync(path.join(HERE, 'ep-columns.js'), 'utf8'), /meta\.storeOk === false/);
});
