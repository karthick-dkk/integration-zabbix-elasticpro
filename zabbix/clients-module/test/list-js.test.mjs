/**
 * Cluster Management's list (views/js/ep.clients.list.js): which rows show under search, status
 * and filter, and what the Columns popover posts.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const HERE = path.resolve(import.meta.dirname, '..');
const L = createRequire(import.meta.url)(path.join(HERE, 'views/js/ep.clients.list.js'));
const row = (x) => Object.assign({ search: 'acme 10.0.0.1', tabs: ['live'], missing: false, old: false }, x);

test('the status decides, then the filter', () => {
  assert.equal(L.shown(row({}), { status: 'live', filter: 'all' }), true);
  assert.equal(L.shown(row({ tabs: ['disabled'] }), { status: 'live', filter: 'all' }), false);
  assert.equal(L.shown(row({ missing: true }), { status: 'live', filter: 'missing' }), true);
  assert.equal(L.shown(row({}), { status: 'live', filter: 'missing' }), false);
  assert.equal(L.shown(row({ old: true }), { status: 'live', filter: 'old' }), true);
});

test('a search looks across every status and filter', () => {
  assert.equal(L.shown(row({ tabs: ['decom'] }), { term: '10.0.0.1', status: 'live', filter: 'missing' }), true);
  assert.equal(L.shown(row({}), { term: 'globex', status: 'live', filter: 'all' }), false);
});

test('an untouched role column stays on "as the data says"; a changed one is kept as chosen', () => {
  const posted = L.columnsFrom([
    { key: 'family:es', label: 'ES', auto: true, checked: true, autoOn: true },
    { key: 'role:aiml', label: 'AIML', auto: true, checked: true, autoOn: false },
    { key: 'password', label: 'Password', auto: false, checked: false, autoOn: true },
  ]);
  assert.deepEqual(posted, [
    { key: 'family:es', label: 'ES', show: null },
    { key: 'role:aiml', label: 'AIML', show: true },
    { key: 'password', label: 'Password', show: false },
  ]);
});

test('the view reads this file, posts the popover to the columns action, and has the footer line', () => {
  const view = fs.readFileSync(path.join(HERE, 'views/ep.clients.list.php'), 'utf8');
  assert.match(view, /readfile\(__DIR__\.'\/js\/ep\.clients\.list\.js'\)/);
  assert.match(view, /ep\.clients\.columns\.save/);
  assert.ok(view.indexOf("ep-sumline") > view.indexOf("</tbody></table></div>"), 'the summary line is under the table');
});

test('an operator\'s page has Maintenance and no Delete dialog: Cancel still closes it', async (t) => {
  const { JSDOM } = await import('jsdom');
  const dom = new JSDOM(`<div class="ep-l"><dialog id="ep-maint"><form><input type="radio" name="when" id="m-at"><input id="m-start"><b id="ep-maint-title"></b><i id="ep-maint-who"></i>
    <button type="button" data-close>Cancel</button></form></dialog></div>`, { runScripts: 'outside-only' });
  dom.window.eval(fs.readFileSync(path.join(HERE, 'views/js/ep.clients.dialogs.js'), 'utf8'));
  let closed = 0;
  dom.window.document.querySelector('#ep-maint').close = () => { closed += 1; };
  dom.window.EpDialogs.init(dom.window.document.querySelector('.ep-l'), () => []);
  dom.window.document.querySelector('[data-close]').dispatchEvent(new dom.window.Event('click', { bubbles: true }));
  assert.equal(closed, 1);
});
