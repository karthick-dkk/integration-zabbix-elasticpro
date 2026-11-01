/**
 * Play inside Zabbix (2.1.15), the ElasticPro module's half: the menu entry, the Play page with its
 * kiosk mode, the action that answers the Play widget, and the one sign-in they share. The PHP
 * behaviour is test/play.test.php (local php, or a php:8.4-cli container; skips, saying so, when
 * neither is available).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const MOD = path.resolve(import.meta.dirname, '..');
const read = (f) => fs.readFileSync(path.join(MOD, f), 'utf8');
const noDocker = (r) => r.status !== 0 && /docker|daemon/i.test(`${r.stderr || ''}${r.stdout || ''}`)
  && !/passed, \d+ failed|FAIL:/.test(`${r.stdout || ''}`);

test('the manifest has the Play page and the widget\'s frame action', () => {
  const m = JSON.parse(read('manifest.json'));
  assert.deepEqual(m.actions['elasticpro.play'], { class: 'View', view: 'elasticpro.embed', layout: 'layout.htmlpage' });
  assert.deepEqual(m.actions['elasticpro.play.frame'], { class: 'PlayFrame', layout: 'layout.json' });
  assert.ok(fs.existsSync(path.join(MOD, 'actions/PlayFrame.php')));
});

test('ElasticPro → Play is a menu entry of its own, after the REST console', () => {
  const php = read('Module.php');
  assert.match(php, /'play'\s*=>\s*'Play',/);
  assert.match(php, /public const MENU = \['overview', 'indices', 'snapshots', 'console', 'play'\];/);
});

test('one sign-in: the pages and the widget\'s action both go through lib/SignIn.php', () => {
  const view = read('actions/View.php');
  const frame = read('actions/PlayFrame.php');
  assert.match(view, /SignIn::frame\(\$page, \$this->trouble\(\)\)/);
  assert.match(frame, /SignIn::frame\('play'\)/);
  for (const [name, src] of [['View.php', view], ['PlayFrame.php', frame]]) {
    assert.doesNotMatch(src, /mintCode|DBselect|new SsoClient/, `${name} does not sign in on its own`);
  }
  const lib = read('lib/SignIn.php');
  for (const s of ['function groups(', 'function roleName(', 'function theme(', 'function frame(', 'function origin(', '->mintCode(']) {
    assert.ok(lib.includes(s), s);
  }
  // The page's "sign me in again" origin is SignIn's, not parsed a second way in the view.
  const page = read('views/elasticpro.embed.php');
  assert.match(page, /\$origin = \$data\['origin'\];/);
  assert.doesNotMatch(page, /parse_url/);
});

test('the Play page: kiosk only for Play, only for this page, and only where Zabbix can', () => {
  const view = read('actions/View.php');
  assert.match(view, /'kiosk' => 'in 0,1'/);
  assert.match(view, /\$this->page\(\) === 'play' && \(string\) \$this->getInput\('kiosk', '0'\) === '1'/);
  const page = read('views/elasticpro.embed.php');
  assert.match(page, /if \(\$data\['kiosk'\] && method_exists\(\$this, 'setLayoutMode'\)\)/);
  assert.match(page, /\$this->setLayoutMode\(ZBX_LAYOUT_KIOSKMODE\)/);
  assert.doesNotMatch(page, /saveLayoutMode|web\.layout\.mode/, 'the person\'s saved layout mode is not touched');
  assert.match(page, /addJsFile\('layout\.mode\.js'\)/, 'Zabbix\'s own Normal view button works');
});

test('the widget\'s action: POST with Zabbix\'s CSRF check left on, the page\'s codes, JSON only', () => {
  const frame = read('actions/PlayFrame.php');
  assert.doesNotMatch(frame, /disableCsrfValidation/, 'it mints a sign-in: Zabbix checks the CSRF token');
  assert.match(frame, /getUserType\(\) >= USER_TYPE_ZABBIX_USER/, 'who may open the ElasticPro pages, nobody else');
  assert.match(frame, /->disableView\(\)/);
  // Its codes are the page's, worded as the page words them.
  const page = read('views/elasticpro.embed.php');
  for (const code of ['EP-701', 'EP-702']) {
    assert.ok(frame.includes(`'${code}'`), code);
    assert.ok(page.includes(`(${code})`), code);
  }
  assert.ok(page.includes("_('ElasticPro could not sign you in.')") && frame.includes("'ElasticPro could not sign you in.'"));
  assert.doesNotMatch(frame, /secret|public_url' =>/, 'only the address and its origin go back');
});

test('ElasticPro only', () => {
  for (const f of ['Module.php', 'manifest.json', 'actions/View.php', 'actions/PlayFrame.php', 'lib/SignIn.php', 'views/elasticpro.embed.php', 'README.md']) {
    assert.ok(!/elasticvue/i.test(read(f)), f);
  }
});

test('Play inside Zabbix (PHP)', (t) => {
  const local = spawnSync('php', ['test/play.test.php'], { cwd: MOD, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${MOD}:/m:ro`, '-w', '/m', 'php:8.4-cli-alpine', 'php', 'test/play.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || noDocker(r)) { t.skip('no php and no docker here — run test/play.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
