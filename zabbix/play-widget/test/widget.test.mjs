/**
 * The "ElasticPro Play" widget (installed as `ep_play`): its wiring, what its HTML may and may not
 * carry, and its script in a DOM — the frame signed in through the ElasticPro module, kept across
 * refreshes, emptied out of view, and signed in again only when its own frame asks. The DOM tests
 * need jsdom and skip, saying so, without it.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const HERE = path.resolve(import.meta.dirname, '..');
const ROOT = path.resolve(HERE, '..');
const read = (f) => fs.readFileSync(path.join(HERE, f), 'utf8');
function jsdom() {
  for (const base of [ROOT, path.resolve(ROOT, '../..')]) {
    try { return createRequire(path.join(base, 'package.json'))('jsdom'); } catch { /* next */ }
  }
  return null;
}
/** Every file the widget ships (not its tests). */
const shipped = () => {
  const out = [];
  const walk = (d) => {
    for (const e of fs.readdirSync(path.join(HERE, d), { withFileTypes: true })) {
      const rel = path.join(d, e.name);
      if (e.isDirectory()) { if (rel !== 'test') walk(rel); }
      else out.push(rel);
    }
  };
  walk('.');
  return out;
};

test('the manifest names the widget, its controller and its script, and does not refresh Play away', () => {
  const m = JSON.parse(read('manifest.json'));
  assert.equal(m.id, 'ep_play');
  assert.equal(m.type, 'widget');
  assert.equal(m.name, 'ElasticPro Play');
  assert.equal(m.namespace, 'EpPlay');
  assert.match(m.version, /^\d+\.\d+\.\d+$/); // the one shared version; tools/test/version.test.mjs holds the modules to it
  assert.equal(m.widget.js_class, 'WidgetEpPlay');
  assert.equal(m.widget.refresh_rate, 0, 'a wall display that runs for hours is not reloaded by the dashboard');
  assert.deepEqual(m.actions, { 'widget.ep_play.view': { class: 'WidgetView' } });
  assert.deepEqual(m.assets, { js: ['class.widget.js'], css: ['widget.css'] });
  for (const f of ['Widget.php', 'actions/WidgetView.php', 'views/widget.view.php', 'assets/js/class.widget.js', 'assets/css/widget.css', 'README.md']) {
    assert.ok(fs.existsSync(path.join(HERE, f)), f);
  }
});

test('ElasticPro only, in every file the widget ships', () => {
  for (const f of shipped()) assert.ok(!/elasticvue/i.test(read(f)), f);
});

test('no fields: Play\'s URL takes no slide list, and no display link is offered', () => {
  // Play (ElasticPro ui/js/pages/play.js) reads its slides from its own settings; its address
  // honours only `display=<secret>`, which this widget will not store or send (README, WidgetView).
  assert.ok(!fs.existsSync(path.join(HERE, 'includes/WidgetForm.php')), 'no form of its own');
  assert.ok(!fs.existsSync(path.join(HERE, 'views/widget.edit.php')), 'no settings of its own');
  for (const f of shipped().filter((x) => /\.(php|js)$/.test(x))) {
    assert.ok(!/display=/.test(read(f)), `${f} builds no display-link address`);
  }
});

test('the HTML carries no address, code or secret: the frame starts empty and the script asks the ElasticPro module', () => {
  const view = read('views/widget.view.php');
  assert.doesNotMatch(view, /setAttribute\('src'/, 'the frame has no src in the HTML');
  assert.match(view, /->setVar\('ep_play', \$data\['play'\]\)/);
  assert.match(view, /setAttribute\('referrerpolicy', 'no-referrer'\)/, 'as the ElasticPro page frames it');
  const php = read('actions/WidgetView.php');
  assert.match(php, /FRAME_ACTION = 'elasticpro\.play\.frame'/);
  assert.match(php, /PAGE_ACTION = 'elasticpro\.play'/);
  assert.match(php, /EMBED_MODULE = 'elasticpro'/);
  assert.match(php, /CCsrfTokenHelper::get\(self::FRAME_ACTION\)/, 'the POST carries this user\'s CSRF token for that action');
  assert.doesNotMatch(php, /SsoClient|mintCode|sso_code|DBselect/, 'the sign-in is the ElasticPro module\'s alone');
  assert.match(php, /->setArgument\('fullscreen', '1'\)/, 'full screen without Zabbix\'s profile-saving `kiosk`');
  assert.doesNotMatch(php, /setArgument\('kiosk'/);
  // Zabbix loads only the modules that are enabled AND allowed for the user's role: name both.
  const msg = php.slice(php.indexOf('function unavailableMessage('));
  assert.match(msg, /not enabled in this Zabbix/);
  assert.match(msg, /your Zabbix user role does not allow it/);
  assert.match(php, /if \(CWebUser::isGuest\(\)\) \{/, 'the guest user is told, and nothing is asked');
  // The actions the widget names are the ElasticPro module's own.
  const embed = JSON.parse(fs.readFileSync(path.join(ROOT, 'embed-module/manifest.json'), 'utf8'));
  assert.equal(embed.id, 'elasticpro');
  assert.ok(embed.actions['elasticpro.play'], 'elasticpro.play');
  assert.deepEqual(embed.actions['elasticpro.play.frame'], { class: 'PlayFrame', layout: 'layout.json' });
});

/* ------------------------------------------ the script, in a DOM ------------------------------------------ */

const ORIGIN = 'https://ep.example.com:8443';
const URL1 = `${ORIGIN}/?sso_code=c1&embed=1&zbx_theme=dark-theme#/play`;
const BODY = '<div class="ep-play"><iframe class="ep-play-frame" title="ElasticPro Play" referrerpolicy="no-referrer"></iframe>'
  + '<div class="ep-play-msg" hidden="hidden"></div><div class="ep-play-tools"><button class="ep-play-full" type="button">Full screen</button><a class="ep-play-newtab" href="zabbix.php?action=elasticpro.play&amp;fullscreen=1">Open in new tab</a></div></div>';
const PLAY = { frame_url: 'zabbix.php?action=elasticpro.play.frame', csrf_name: '_csrf_token', csrf: 'tok', full_url: 'zabbix.php?action=elasticpro.play&fullscreen=1' };

/** A dashboard with one Play widget; `answers` are what the ElasticPro module says, in turn. */
function dashboard(lib, answers) {
  const dom = new lib.JSDOM('<body><div id="w"></div></body>', { runScripts: 'outside-only' });
  const w = dom.window;
  const asked = [];
  w.fetch = async (url, init) => {
    asked.push({ url, init });
    const next = answers.length > 1 ? answers.shift() : answers[0];
    return { json: async () => { if (next instanceof Error) throw next; return next; } };
  };
  // Zabbix's widget, as far as this one leans on it: setContents puts the body in.
  w.eval('class CWidget { constructor() { this._body = document.getElementById("w"); } setContents(r) { this._body.innerHTML = r.body ?? ""; } }'
    + read('assets/js/class.widget.js') + ';window.W = WidgetEpPlay;');
  const widget = new w.W();
  const settle = () => new Promise((r) => setTimeout(r, 0));
  const frame = () => w.document.querySelector('.ep-play-frame');
  const msg = () => w.document.querySelector('.ep-play-msg');
  const reauth = (opts = {}) => w.dispatchEvent(new w.MessageEvent('message', {
    data: { type: 'elasticpro:reauth' }, origin: ORIGIN, source: frame().contentWindow, ...opts }));
  return { w, widget, asked, settle, frame, msg, reauth };
}

test('it asks the ElasticPro module for the address (POST, CSRF token) and loads it; a refresh keeps Play running (DOM)', async (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const d = dashboard(lib, [{ url: URL1, origin: ORIGIN }]);
  d.widget.setContents({ body: BODY, ep_play: PLAY });
  await d.settle();
  assert.equal(d.asked.length, 1);
  assert.equal(d.asked[0].url, PLAY.frame_url);
  assert.equal(d.asked[0].init.method, 'POST');
  assert.equal(d.asked[0].init.body, '_csrf_token=tok');
  assert.equal(d.frame().src, URL1);
  assert.equal(d.msg().hidden, true);
  assert.equal(d.widget.hasPadding(), false, 'Play fills the widget');

  const before = d.frame();
  d.widget.setContents({ body: BODY, ep_play: PLAY });
  await d.settle();
  assert.equal(d.frame(), before, 'the same frame: Play is not reloaded by a refresh');
  assert.equal(d.asked.length, 1, 'and no new sign-in');
});

test('out of view the frame empties; back in view it signs in afresh (DOM)', async (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const d = dashboard(lib, [{ url: URL1, origin: ORIGIN }, { url: URL1.replace('c1', 'c2'), origin: ORIGIN }]);
  d.widget.setContents({ body: BODY, ep_play: PLAY });
  await d.settle();
  d.widget.onDeactivate();
  assert.equal(d.frame().src, 'about:blank', 'Play stops polling while its dashboard page is away');
  d.widget.onActivate();
  await d.settle();
  assert.equal(d.asked.length, 2);
  assert.equal(d.frame().src, URL1.replace('c1', 'c2'), 'a new code: the first one was used');
  d.widget.onActivate();
  await d.settle();
  assert.equal(d.asked.length, 2, 'already showing: no extra sign-in');
});

test('"sign me in again" counts only from its own frame and ElasticPro\'s origin, three times a minute, then EP-703 (DOM)', async (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const d = dashboard(lib, [{ url: URL1, origin: ORIGIN }]);
  d.widget.setContents({ body: BODY, ep_play: PLAY });
  await d.settle();
  d.reauth({ origin: 'https://evil.example' });
  d.reauth({ source: d.w });
  d.reauth({ data: { type: 'something-else' } });
  await d.settle();
  assert.equal(d.asked.length, 1, 'another origin, another window or another message: ignored');
  for (let i = 0; i < 3; i++) { d.reauth(); await d.settle(); }
  assert.equal(d.asked.length, 4, 'three sign-ins again');
  d.reauth();
  await d.settle();
  assert.equal(d.asked.length, 4, 'the fourth in a minute is not asked');
  assert.equal(d.msg().hidden, false);
  assert.match(d.msg().textContent, /could not keep you signed in\. \(EP-703\)/);
  assert.match(d.msg().textContent, /Administration → ElasticPro/);
  assert.equal(d.frame().src, 'about:blank');
});

test('a refusal is shown as text with its code; a page that is not JSON says what to check (DOM)', async (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  let d = dashboard(lib, [{ error: { code: 'EP-702', message: 'ElasticPro could not sign you in.', reason: '<img src=x onerror=alert(1)> EP-410 disabled' } }]);
  d.widget.setContents({ body: BODY, ep_play: PLAY });
  await d.settle();
  assert.equal(d.msg().hidden, false);
  assert.equal(d.msg().querySelector('b').textContent, 'ElasticPro could not sign you in. (EP-702)');
  assert.match(d.msg().textContent, /<img src=x onerror=alert\(1\)> EP-410 disabled/, 'the reason is text');
  assert.equal(d.msg().querySelector('img'), null, 'never markup');
  assert.ok(!d.frame().getAttribute('src') || d.frame().src === 'about:blank');

  d = dashboard(lib, [new SyntaxError('Unexpected token <')]);
  d.widget.setContents({ body: BODY, ep_play: PLAY });
  await d.settle();
  assert.match(d.msg().textContent, /did not answer with ElasticPro’s address/);
  assert.match(d.msg().textContent, /user role/);
});

test('the controller\'s own message (module not enabled) is drawn as it came, and nothing is asked (DOM)', async (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const d = dashboard(lib, [{ url: URL1, origin: ORIGIN }]);
  d.widget.setContents({ body: '<div class="ep-play-msg ep-play-alone">not enabled</div>' });
  await d.settle();
  assert.equal(d.asked.length, 0);
  assert.equal(d.frame(), null);
});

test('frameOf, isReauth and retry, without a DOM', async () => {
  const { default: vm } = await import('node:vm');
  const ctx = vm.createContext({});
  vm.runInContext(`class CWidget {}\n${read('assets/js/class.widget.js')}\n;globalThis.W = WidgetEpPlay;`, ctx);
  const W = ctx.W;
  assert.equal(W.frameOf({ url: 'javascript:alert(1)', origin: ORIGIN }).url, null, 'only an http(s) address is loaded');
  assert.equal(W.frameOf({ url: URL1, origin: ORIGIN }).url, URL1);
  assert.equal(W.frameOf({ error: { code: 'EP-701', message: 'not connected' } }).error.title, 'not connected (EP-701)');
  assert.equal(W.frameOf({ error: { code: 'nope', message: 'x' } }).error.title, 'x', 'a code is shown only in its own shape');
  const win = {};
  assert.equal(W.isReauth({ source: win, origin: ORIGIN, data: { type: 'elasticpro:reauth' } }, win, ORIGIN), true);
  assert.equal(W.isReauth({ source: win, origin: ORIGIN, data: { type: 'elasticpro:reauth' } }, win, ''), false, 'no origin known yet: nothing counts');
  let r = W.retry([], 1000);
  r = W.retry(r.times, 2000); r = W.retry(r.times, 3000);
  assert.equal(r.ok, true);
  assert.equal(W.retry(r.times, 4000).ok, false);
  assert.equal(W.retry(r.times, 61500).ok, true, 'a minute later, the first one has aged out');
});

test('the widget\'s Full screen is a button on its own frame; the new-tab link stays; the frame allows full screen', () => {
  const view = read('views/widget.view.php');
  assert.match(view, /new CButton\('ep-play-full', _\('Full screen'\)\)/);
  assert.match(view, /new CLink\(_\('Open in new tab'\), \$data\['play'\]\['full_url'\]\)/);
  assert.match(view, /setAttribute\('allow', 'clipboard-write; fullscreen'\)/);
  assert.match(view, /setAttribute\('allowfullscreen', 'allowfullscreen'\)/);
  assert.doesNotMatch(view, /onclick/i);
  assert.doesNotMatch(read('assets/js/class.widget.js'), /innerHTML/);
});

test('Full screen: requestFullscreen on the widget\'s frame, prefix fallback, refusal is quiet; Esc keeps the lifecycle (DOM)', async (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const d = dashboard(lib, [{ url: URL1, origin: ORIGIN }, { url: URL1.replace('c1', 'c2'), origin: ORIGIN }]);
  d.widget.setContents({ body: BODY, ep_play: PLAY });
  await d.settle();
  const doc = d.w.document;
  const btn = () => doc.querySelector('.ep-play-full');
  const calls = [];

  d.frame().requestFullscreen = function () { calls.push(this); return Promise.resolve(); };
  btn().click();
  assert.deepEqual(calls, [d.frame()], 'called on the widget\'s own frame');
  assert.equal(d.asked.length, 1, 'no new sign-in, no reload');
  assert.equal(d.frame().src, URL1);

  // The browser says the frame is full screen, then that it is not (Esc): nothing is reloaded.
  Object.defineProperty(doc, 'fullscreenElement', { configurable: true, get: () => d.frame() });
  doc.dispatchEvent(new d.w.Event('fullscreenchange'));
  assert.equal(d.widget._ep.full, true);
  Object.defineProperty(doc, 'fullscreenElement', { configurable: true, get: () => null });
  doc.dispatchEvent(new d.w.Event('fullscreenchange'));
  assert.equal(d.widget._ep.full, false);
  assert.equal(d.asked.length, 1);
  assert.equal(d.frame().src, URL1, 'back at widget size, still Play');

  // Page switched away while full screen: leave it, blank, and sign in afresh on return.
  let exited = 0;
  doc.exitFullscreen = () => { exited++; return Promise.resolve(); };
  Object.defineProperty(doc, 'fullscreenElement', { configurable: true, get: () => d.frame() });
  d.widget.onDeactivate();
  assert.equal(exited, 1);
  assert.equal(d.frame().src, 'about:blank');
  Object.defineProperty(doc, 'fullscreenElement', { configurable: true, get: () => null });
  d.widget.onActivate();
  await d.settle();
  assert.equal(d.asked.length, 2);
  assert.equal(d.frame().src, URL1.replace('c1', 'c2'));

  // Prefixed, refused, unsupported.
  const w2 = [];
  delete d.frame().requestFullscreen;
  d.frame().webkitRequestFullscreen = function () { w2.push(this); };
  btn().click();
  assert.deepEqual(w2, [d.frame()]);
  d.frame().webkitRequestFullscreen = () => { throw new Error('no'); };
  assert.doesNotThrow(() => btn().click());
  d.frame().requestFullscreen = () => Promise.reject(new Error('denied'));
  btn().click();
  await d.settle();
  delete d.frame().requestFullscreen; delete d.frame().webkitRequestFullscreen;
  assert.doesNotThrow(() => btn().click());
});
