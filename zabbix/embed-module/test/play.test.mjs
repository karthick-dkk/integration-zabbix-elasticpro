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
import { createRequire } from 'node:module';

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

test('the Play page: full screen only for Play, for this response only, and only where Zabbix can', () => {
  const view = read('actions/View.php');
  assert.match(view, /'fullscreen' => 'in 0,1'/);
  assert.match(view, /\$this->page\(\) === 'play' && \(string\) \$this->getInput\('fullscreen', '0'\) === '1'/);
  const page = read('views/elasticpro.embed.php');
  assert.match(page, /\$fullscreen = \$data\['fullscreen'\] && method_exists\(\$this, 'setLayoutMode'\);/);
  assert.match(page, /\$this->setLayoutMode\(ZBX_LAYOUT_KIOSKMODE\)/, 'the view\'s layout mode, this response only');
  assert.doesNotMatch(page, /saveLayoutMode|CProfile|updateUserProfile|setWebLayoutMode/, 'nothing is saved, and the page draws no Zabbix kiosk controls');
  assert.doesNotMatch(page, /addJsFile\('layout\.mode\.js'\)/, 'Zabbix\'s layout switcher strips only `kiosk` and saves the profile');
  assert.match(page, /new CLink\(_\('Exit full screen'\), \(new CUrl\('zabbix\.php'\)\)->setArgument\('action', 'elasticpro\.play'\)\)/);
});

test('no code path sends Zabbix\'s `kiosk` URL parameter (Zabbix saves it into the person\'s profile)', () => {
  // ZBase::setLayoutModeByUrl() runs on every zabbix.php request: any `kiosk` in the URL is
  // written to web.layout.mode, and every page after is in kiosk mode.
  const ROOT = path.resolve(MOD, '..');
  const files = [];
  const walk = (d) => {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) { if (e.name !== 'test') walk(p); }
      else if (/\.(php|js|json)$/.test(e.name)) files.push(p);
    }
  };
  walk(MOD);
  walk(path.join(ROOT, 'play-widget'));
  assert.ok(files.length > 10);
  for (const f of files) {
    const src = fs.readFileSync(f, 'utf8');
    for (const re of [/setArgument\(\s*['"]kiosk['"]/, /[?&]kiosk=/, /['"]kiosk['"]\s*(=>|:)/, /addJsFile\(\s*['"]layout\.mode\.js/, /ZBX_LAYOUT_MODE\b/]) {
      assert.doesNotMatch(src, re, `${path.relative(ROOT, f)}: ${re}`);
    }
  }
});

test('the widget\'s action: POST with Zabbix\'s CSRF check left on, the page\'s codes, JSON only', () => {
  const frame = read('actions/PlayFrame.php');
  assert.doesNotMatch(frame, /disableCsrfValidation/, 'it mints a sign-in: Zabbix checks the CSRF token');
  assert.match(frame, /return SignIn::mayOpen\(\);/, 'who may open the ElasticPro pages, nobody else');
  assert.match(frame, /'reason' => SignIn::reason\(/, 'the reason in full only to a Super admin');
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

test('guests and refusal reasons: one rule each, used by the pages, the action and the menu', () => {
  const lib = read('lib/SignIn.php');
  assert.match(lib, />= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest\(\)/);
  assert.match(read('actions/View.php'), /if \(!SignIn::mayOpen\(\)\) \{\n\t\t\treturn false;/);
  assert.match(read('actions/View.php'), /'error' => SignIn::reason\(\$sign\['error'\], \$this->getUserType\(\) == USER_TYPE_SUPER_ADMIN\)/);
  assert.match(read('Module.php'), /if \(!Lib\\SignIn::mayOpen\(\)\) \{\n\t\t\treturn;/);
  assert.match(read('views/elasticpro.embed.php'), /A Zabbix Super admin sees the reason on this page\./);
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

/* ---------------------------------------- full screen (2.1.17) ---------------------------------------- */

function jsdomLib() {
  for (const base of [path.resolve(MOD, '..'), path.resolve(MOD, '../..')]) {
    try { return createRequire(path.join(base, 'package.json'))('jsdom'); } catch { /* next */ }
  }
  return null;
}

test('every ElasticPro frame the modules draw allows full screen and nothing else new', () => {
  const ROOT = path.resolve(MOD, '..');
  for (const f of ['embed-module/views/elasticpro.embed.php', 'play-widget/views/widget.view.php']) {
    const src = fs.readFileSync(path.join(ROOT, f), 'utf8');
    assert.match(src, /setAttribute\('allow', 'clipboard-write; fullscreen'\)/, f);
    assert.match(src, /setAttribute\('allowfullscreen', 'allowfullscreen'\)/, f);
    assert.doesNotMatch(src, /camera|microphone|geolocation|autoplay/, f);
  }
  // No other module draws a frame without it.
  const frames = [];
  const walk = (d) => {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) { if (!['test', 'node_modules'].includes(e.name)) walk(p); }
      else if (/\.(php|js)$/.test(e.name) && /CTag\('iframe'|createElement\('iframe'/.test(fs.readFileSync(p, 'utf8'))) frames.push(path.relative(ROOT, p));
    }
  };
  walk(ROOT);
  assert.deepEqual(frames.sort(), ['embed-module/views/elasticpro.embed.php', 'play-widget/views/widget.view.php']);
});

test('the Play page: a Full screen button for Play only, saving nothing; the exit link stays', () => {
  const page = read('views/elasticpro.embed.php');
  assert.match(page, /\$data\['play'\] && \$data\['configured'\] && \$data\['frame_url'\] !== null/);
  assert.match(page, /new CButton\('ep-full-btn', _\('Full screen'\)\)/);
  assert.match(page, /ZBX_STYLE_BTN_ALT/);
  assert.match(page, /_\('Exit full screen'\)/);
  assert.match(read('actions/View.php'), /'play' => \$page === 'play'/);
  assert.doesNotMatch(page, /onclick|setAttribute\('on/i, 'no inline handler');
  assert.doesNotMatch(page, /innerHTML[^;]*(frame_url|\$data)/);
});

function menuPage(lib, frameProps) {
  const page = read('views/elasticpro.embed.php');
  const m = page.match(/<script>\s*\/\* ep-fullscreen[\s\S]*?<\/script>/);
  assert.ok(m, 'the full-screen script block');
  const dom = new lib.JSDOM('<body><div class="ep-wrap"><iframe class="ep-frame"></iframe><button class="ep-full-btn" type="button">Full screen</button></div></body>', { runScripts: 'outside-only' });
  const frame = dom.window.document.querySelector('.ep-frame');
  Object.assign(frame, frameProps);
  dom.window.eval(m[0].replace(/^<script>|<\/script>$/g, ''));
  return { dom, btn: dom.window.document.querySelector('.ep-full-btn'), frame };
}

test('Full screen button: requestFullscreen on the frame, in the click; webkit fallback; refused is quiet (DOM)', async (t) => {
  const lib = jsdomLib(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  let calls = [];
  let d = menuPage(lib, { requestFullscreen() { calls.push(this); return Promise.resolve(); } });
  d.btn.click();
  assert.deepEqual(calls, [d.frame], 'called on the iframe element');

  calls = [];
  d = menuPage(lib, { webkitRequestFullscreen() { calls.push(this); } });
  d.btn.click();
  assert.deepEqual(calls, [d.frame], 'prefixed fallback');

  d = menuPage(lib, { requestFullscreen() { return Promise.reject(new Error('denied')); } });
  d.btn.click();
  await new Promise((r) => setTimeout(r, 0)); // an unhandled rejection would fail the run
  d = menuPage(lib, { requestFullscreen() { throw new Error('sync refusal'); } });
  assert.doesNotThrow(() => d.btn.click());
  d = menuPage(lib, {});
  assert.doesNotThrow(() => d.btn.click(), 'unsupported: nothing happens');
});
