#!/usr/bin/env node
/**
 * Copies shared/ into every module — Zabbix loads a module's files from its own folder, so each
 * carries a copy. Edit shared/, then run this; the tests fail while any copy differs.
 *
 *   node sync-assets.mjs
 *
 * JavaScript goes to <widget>/assets/js/. PHP goes to <module>/lib/, with its namespace
 * rewritten to the module's own (EpShared → Modules\<Namespace>\Lib), so two modules never
 * declare the same class. Widget fields (shared/php/includes) go to <widget>/includes/ under
 * Modules\<Namespace>\Includes, and the column editor's action and views to the widgets that
 * offer it.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const SHARED = ['ep-xlsx.js', 'ep-export.js', 'ep-columns.js'];
export const SHARED_CSS = ['ep-widget.css'];
export const WIDGETS = ['capacity-widget', 'volume-widget', 'resources-widget'];
/** The panel look and its controls: only the two client reports use them, so the Volume report is untouched. */
export const PANEL_JS = ['ep-panel.js'];
export const PANEL_CSS = ['ep-panel.css'];
export const PANEL_WIDGETS = ['capacity-widget', 'resources-widget'];
/** The panel look alone, without the table controls: Top hosts is a list, not a table. */
export const PANEL_CSS_ONLY = ['tophosts-widget', 'overview-widget'];
export const SHARED_PHP = ['Store.php', 'Settings.php', 'Roles.php', 'ColumnSettings.php', 'Forecast.php', 'ClientTypes.php', 'Bars.php', 'ClientData.php',
  'Columns.php', 'ColumnMath.php', 'ColumnReader.php'];
/** The widgets that have their columns in the shared column model (Columns.php) and so its editor and fields. */
export const COLUMN_WIDGETS = ['resources-widget'];
export const SHARED_ACTIONS = { 'ColumnsSave.php': ['resources-widget', 'capacity-widget', 'volume-widget'], 'ColumnEdit.php': COLUMN_WIDGETS };
export const SHARED_INCLUDES = {
  'CWidgetFieldStrings.php': ['overview-widget', ...COLUMN_WIDGETS],
  'CWidgetFieldOptionalNumber.php': ['overview-widget', ...COLUMN_WIDGETS],
  'CWidgetFieldColumns.php': COLUMN_WIDGETS
};
export const SHARED_VIEWS = { 'column.edit.php': COLUMN_WIDGETS, 'column.edit.js.php': COLUMN_WIDGETS };
export const PHP_MODULES = { 'clients-module': 'EpClients', 'resources-widget': 'EpResources', 'capacity-widget': 'EpCapacity', 'volume-widget': 'EpVolume', 'tophosts-widget': 'EpTophosts', 'overview-widget': 'EpOverview' };

/** The shared PHP file as it sits in a module. */
export function phpFor(source, namespace) {
  return source.replace(/^namespace EpShared\\Actions;$/m, `namespace Modules\\${namespace}\\Actions;`)
    .replace(/^namespace EpShared\\Includes;$/m, `namespace Modules\\${namespace}\\Includes;`)
    .replace(/^use EpShared\\Includes\\/gm, `use Modules\\${namespace}\\Includes\\`)
    .replace(/^use EpShared\\/gm, `use Modules\\${namespace}\\Lib\\`)
    .replace(/^namespace EpShared;$/m, `namespace Modules\\${namespace}\\Lib;`)
    .replace(' * Shared: ../../sync-assets.mjs copies this into each module, under that module\'s namespace.',
      ' * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.')
    .replace(' * Shared: ../../../sync-assets.mjs copies this into the widgets that use it, under their namespace.',
      ' * Copied from ../../shared/php/includes by ../../sync-assets.mjs — edit it there.')
    .replace(' * Shared: ../../../sync-assets.mjs copies this into the widgets that offer the column editor.',
      ' * Copied from ../../shared/php/views by ../../sync-assets.mjs — edit it there.');
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  for (const w of WIDGETS) {
    if (!fs.existsSync(path.join(HERE, w))) continue;
    for (const f of SHARED) {
      if (fs.existsSync(path.join(HERE, 'shared', f))) fs.copyFileSync(path.join(HERE, 'shared', f), path.join(HERE, w, 'assets', 'js', f));
    }
    for (const f of SHARED_CSS) fs.copyFileSync(path.join(HERE, 'shared', f), path.join(HERE, w, 'assets', 'css', f));
  }
  for (const w of PANEL_WIDGETS) {
    for (const f of PANEL_JS) fs.copyFileSync(path.join(HERE, 'shared', f), path.join(HERE, w, 'assets', 'js', f));
    for (const f of PANEL_CSS) fs.copyFileSync(path.join(HERE, 'shared', f), path.join(HERE, w, 'assets', 'css', f));
  }
  for (const w of PANEL_CSS_ONLY) {
    for (const f of PANEL_CSS) fs.copyFileSync(path.join(HERE, 'shared', f), path.join(HERE, w, 'assets', 'css', f));
  }
  for (const [dir, ns] of Object.entries(PHP_MODULES)) {
    if (!fs.existsSync(path.join(HERE, dir))) continue;
    fs.mkdirSync(path.join(HERE, dir, 'lib'), { recursive: true });
    for (const f of SHARED_PHP) {
      fs.writeFileSync(path.join(HERE, dir, 'lib', f), phpFor(fs.readFileSync(path.join(HERE, 'shared', 'php', f), 'utf8'), ns));
    }
    console.log(`${dir}: ${SHARED_PHP.join(', ')}`);
  }
  const copy = (map, from, to) => {
    for (const [f, dirs] of Object.entries(map)) {
      for (const dir of dirs) {
        if (!fs.existsSync(path.join(HERE, dir))) continue;
        fs.mkdirSync(path.join(HERE, dir, to), { recursive: true });
        fs.writeFileSync(path.join(HERE, dir, to, f), phpFor(fs.readFileSync(path.join(HERE, 'shared', 'php', from, f), 'utf8'), PHP_MODULES[dir]));
      }
    }
  };
  copy(SHARED_ACTIONS, 'actions', 'actions');
  copy(SHARED_INCLUDES, 'includes', 'includes');
  copy(SHARED_VIEWS, 'views', 'views');
}
