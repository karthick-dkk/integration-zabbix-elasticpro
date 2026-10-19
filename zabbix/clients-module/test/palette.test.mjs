/**
 * ElasticPro -> Settings, the Colours card (views/js/ep.clients.settings.js) in a DOM: a box that
 * is not six hex digits is marked as it is typed, the swatch and the box stay together, what reads
 * on a colour is worked out again as it changes, and Reset fills in the shipped colours. Needs jsdom.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const HERE = path.resolve(import.meta.dirname, '..');
function jsdom() {
  for (const base of [path.resolve(HERE, '..'), path.resolve(HERE, '../../..')]) {
    try { return createRequire(path.join(base, 'package.json'))('jsdom'); } catch { /* next */ }
  }
  return null;
}
const DEFAULTS = { light: { ok: '#3e9b3a', warn: '#e5860b', link: '#1f62e0' }, dark: { ok: '#73bf69', warn: '#ff9830', link: '#6e9fff' },
  series: ['#5794f2', '#73bf69'] };
const row = (theme, key, v) => `<span class="ep-pal-c"><input type="color" value="${v}" data-ep-swatch="palette[${theme}][${key}]">`
  + `<input type="text" name="palette[${theme}][${key}]" value="${v}" data-ep-hex></span><div data-ep-check="${theme}:${key}"><span class="ep-pal-th">x</span> ?</div>`;
const HTML = `<div id="ep-palette" data-defaults='${JSON.stringify(DEFAULTS)}' data-min="4.5" data-dark-text="#111217"
  data-backgrounds='{"light":"#ffffff","dark":"#181b1f"}'>
  <button data-ep-pal-reset>Reset to defaults</button>
  ${row('light', 'ok', '#000000')}${row('light', 'warn', '#e5860b')}${row('light', 'link', '#1f62e0')}${row('dark', 'ok', '#73bf69')}${row('dark', 'warn', '#ff9830')}${row('dark', 'link', '#6e9fff')}
  <input type="color" name="palette[series][0]" value="#ffffff"><input type="color" name="palette[series][1]" value="#ffffff">
  <div data-ep-preview></div></div>`;

function page(lib) {
  const dom = new lib.JSDOM(`<body>${HTML}</body>`, { runScripts: 'outside-only' });
  const w = dom.window;
  w.CSS = { escape: (s) => String(s).replace(/[[\]"]/g, '\\$&') };
  w.eval(fs.readFileSync(path.join(HERE, 'views/js/ep.clients.settings.js'), 'utf8'));
  const card = w.document.getElementById('ep-palette');
  const type = (name, v) => { const el = card.querySelector(`[name="${name}"]`); el.value = v; el.dispatchEvent(new w.Event('input', { bubbles: true })); return el; };
  return { w, card, type };
}

test('a colour that is not six hex digits is marked as it is typed; a good one moves the swatch', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { card, type } = page(lib);
  for (const bad of ['red', '#fff', '#12345g', '#1234567']) {
    assert.ok(type('palette[light][ok]', bad).classList.contains('ep-pal-wrong'), bad);
  }
  const box = type('palette[light][ok]', '1F62E0');
  assert.ok(!box.classList.contains('ep-pal-wrong'));
  assert.equal(card.querySelector('[data-ep-swatch="palette[light][ok]"]').value, '#1f62e0');
});

test('what reads on a colour is worked out again: white, or flagged with dark text below 4.5:1', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { card, type } = page(lib);
  type('palette[light][ok]', '#1f62e0');
  const ok = card.querySelector('[data-ep-check="light:ok"]');
  assert.match(ok.textContent, /white 5\.\d:1 ✓/);
  assert.equal(ok.className, 'ep-pal-ok');
  type('palette[light][ok]', '#e5860b');
  assert.match(ok.textContent, /dark text \d+\.\d:1 ⚠/);
  assert.equal(ok.className, 'ep-pal-bad');
  type('palette[dark][link]', '#20242a');
  assert.match(card.querySelector('[data-ep-check="dark:link"]').textContent, /on the page 1\.\d:1 ⚠/, 'a link is judged against the page');
});

test('the swatch writes the box; Reset fills in the shipped colours for Save to keep', (t) => {
  const lib = jsdom(); if (!lib) { t.skip('jsdom not found — npm i jsdom'); return; }
  const { w, card } = page(lib);
  const sw = card.querySelector('[data-ep-swatch="palette[dark][ok]"]');
  sw.value = '#abcdef';
  sw.dispatchEvent(new w.Event('input', { bubbles: true }));
  assert.equal(card.querySelector('[name="palette[dark][ok]"]').value, '#abcdef');
  card.querySelector('[data-ep-pal-reset]').click();
  assert.equal(card.querySelector('[name="palette[light][ok]"]').value, '#3e9b3a');
  assert.equal(card.querySelector('[name="palette[dark][ok]"]').value, '#73bf69');
  assert.equal(card.querySelector('[name="palette[series][0]"]').value, '#5794f2');
  assert.equal(card.querySelector('[data-ep-preview]').style.getPropertyValue('--pv-ok'), '#3e9b3a', 'the preview follows');
});
