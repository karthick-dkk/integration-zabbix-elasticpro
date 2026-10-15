/** The Top hosts widget's wiring and its ranking, and its controller (test/view.test.php) run with PHP. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';

const HERE = path.resolve(import.meta.dirname, '..');
const ROOT = path.resolve(HERE, '..');
const noDocker = (r) => r.status !== 0 && /docker|daemon/i.test(`${r.stderr || ''}${r.stdout || ''}`)
  && !/passed, \d+ failed|FAIL:/.test(`${r.stdout || ''}`);
function jsdom() {
  for (const base of [ROOT, path.resolve(ROOT, '../..')]) {
    try { return createRequire(path.join(base, 'package.json'))('jsdom'); } catch { /* next */ }
  }
  return null;
}

test('the manifest names the widget and its controller, and loads the panel look', () => {
  const m = JSON.parse(fs.readFileSync(path.join(HERE, 'manifest.json'), 'utf8'));
  assert.equal(m.id, 'ep_tophosts');
  assert.equal(m.widget.js_class, 'WidgetEpTophosts');
  assert.ok(m.actions['widget.ep_tophosts.view']);
  assert.deepEqual(m.assets.css, ['ep-panel.css', 'widget.css']);
  assert.equal(fs.readFileSync(path.join(HERE, 'assets/css/ep-panel.css'), 'utf8'), fs.readFileSync(path.join(ROOT, 'shared/ep-panel.css'), 'utf8'), 'node sync-assets.mjs');
  const php = fs.readFileSync(path.join(HERE, 'actions/WidgetView.php'), 'utf8');
  assert.match(php, /ClientData::clients\(/, 'the clients come from the shared reading');
  assert.match(php, /ClientData::servers\(/);
  assert.match(php, /ClientData::classify\(/, 'colours from the shared rule');
  assert.match(php, /Settings::usageFor\(/, 'at each client\'s thresholds');
  assert.ok(fs.readFileSync(path.join(ROOT, 'master/import.py'), 'utf8').includes('"ep_tophosts"'), 'import.py makes its dashboard');
});

test('the ranking: highest first, at most count, unmeasured left out, names escaped', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const dom = new lib.JSDOM('<body><ol></ol></body>', { runScripts: 'outside-only' });
  const w = dom.window;
  w.CWidget = class {};
  w.eval(fs.readFileSync(path.join(HERE, 'assets/js/class.widget.js'), 'utf8'));
  const s = (host, cpu, mem, disk, cls = '') => ({ host, client: 'c<1>', role: 'ES', url: 'zabbix.php?x=1&y="2"', cpu, mem, disk, classes: { cpu: cls, mem: '', disk: '' } });
  const data = { count: 2, servers: [s('a', 10, null, 5), s('b<img>', 90, 50, null, 'ep-high'), s('c', 50, 20, 70), s('d', null, 60, 80)] };
  assert.deepEqual(w.EpTopHosts.ranked(data.servers, 'cpu', 2).map((x) => x.host), ['b<img>', 'c']);
  assert.deepEqual(w.EpTopHosts.ranked(data.servers, 'mem', 5).map((x) => x.host), ['d', 'b<img>', 'c'], 'no memory figure: not ranked as zero');
  const ol = w.document.querySelector('ol');
  w.EpTopHosts.draw(ol, data, 'cpu');
  assert.equal(ol.querySelectorAll('li').length, 2);
  assert.equal(ol.querySelector('img'), null, 'a host name is text, never markup');
  assert.equal(ol.querySelector('.hn').textContent, 'b<img>');
  assert.equal(ol.querySelector('a').getAttribute('href'), 'zabbix.php?x=1&y="2"', 'the link survives escaping intact');
  assert.match(ol.querySelector('.hv').getAttribute('style'), /--ep-high/, 'the colour is the class the controller chose');
  w.EpTopHosts.draw(ol, { count: 3, servers: [s('x', null, null, null)] }, 'mem');
  assert.match(ol.textContent, /No server reports memory use yet/);
});

test('WidgetView against a fake Zabbix (PHP)', (t) => {
  const local = spawnSync('php', ['test/view.test.php'], { cwd: HERE, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${HERE}:/m:ro`, '-w', '/m', 'php:8.4-cli-alpine', 'php', 'test/view.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || noDocker(r)) { t.skip('no php and no docker here — run test/view.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
