/**
 * The embed module (installed as `elasticpro`): what the person sees when the ElasticPro page
 * cannot open, and the names it shows. ElasticPro takes this folder from here at the commit it
 * pins (deploy/zabbix/MODULES_REF), so this is the only copy — these are the properties its old
 * copy in the ElasticPro repository carried and this one did not.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const MOD = path.resolve(import.meta.dirname, '..');
const read = (f) => fs.readFileSync(path.join(MOD, f), 'utf8');
const VIEW = read('views/elasticpro.embed.php');

/** The source between two markers, so a code is checked in the branch it belongs to. */
function between(src, from, to) {
  const a = src.indexOf(from);
  assert.notEqual(a, -1, `"${from}" is not in the view`);
  const b = src.indexOf(to, a + from.length);
  assert.notEqual(b, -1, `"${to}" does not follow "${from}"`);
  return src.slice(a, b);
}

test('not paired: both the Super admin and everyone else see EP-701', () => {
  const branch = between(VIEW, "if (!$data['configured'])", "elseif ($data['frame_url'] === null)");
  // One message for a Super admin (with the link to pair), one for everyone else.
  assert.equal([...branch.matchAll(/\(EP-701\)/g)].length, 2, branch);
});

test('sign-in refused: EP-702, followed by the core\'s own reason', () => {
  const branch = between(VIEW, "elseif ($data['frame_url'] === null)", 'else');
  assert.match(branch, /could not sign you in\.'\)\.' \(EP-702\)'/);
  assert.match(branch, /\$data\['error'\]/, 'the core\'s reason (with its own code) still follows');
});

test('lost the session three times: EP-703, and the core reasons by their codes', () => {
  const msg = between(VIEW, 'could not keep you signed in', '</div>');
  assert.match(msg, /^could not keep you signed in\. \(EP-703\)/);
  for (const [code, kind] of [['EP-306', 'not_configured'], ['EP-510', 'bad_signature'], ['EP-511', 'stale']]) {
    assert.ok(msg.includes(`<code>${code} ${kind}</code>`), `${code} ${kind} is named as the server log names it`);
  }
});

test('the header lists every code the view shows, and only those', () => {
  const header = VIEW.slice(0, VIEW.indexOf('*/'));
  const listed = [...header.matchAll(/^\s*\*\s+(EP-7\d\d)\s/gm)].map((m) => m[1]);
  const shown = [...new Set([...VIEW.slice(header.length).matchAll(/EP-7\d\d/g)].map((m) => m[0]))];
  assert.deepEqual(listed, ['EP-701', 'EP-702', 'EP-703']);
  assert.deepEqual(shown.sort(), listed);
});

test('the menu names the Snapshots page "Snapshots", as ElasticPro does', () => {
  const php = read('Module.php');
  assert.match(php, /'snapshots'\s*=>\s*'Snapshots',/);
  assert.doesNotMatch(php, /Snapshots & SLM/);
});

test('sync-role.json: the one API identity, read methods only', () => {
  const spec = JSON.parse(read('sync-role.json'));
  for (const k of ['roleName', 'roleType', 'userName', 'noFrontendGroup', 'scriptTokenName', 'pairedTokenPrefix', 'methods']) {
    assert.ok(k in spec, `${k} is missing`);
  }
  assert.ok(spec.methods.length > 0);
  for (const m of spec.methods) assert.match(m, /^[a-z]+\.get$/, `${m} is not a read method`);
  assert.equal(new Set(spec.methods).size, spec.methods.length);
});
