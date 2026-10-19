/** The shared column engine and the palette (engine.test.php), run with PHP. */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const ROOT = path.resolve(import.meta.dirname, '../..');
// Docker could not be reached at all, as opposed to the tests having failed.
const noDocker = (r) => r.status !== 0 && /docker|daemon/i.test(`${r.stderr || ''}${r.stdout || ''}`)
  && !/passed, \d+ failed|FAIL:/.test(`${r.stdout || ''}`);

test('the column engine, the palette and the report defaults (PHP)', (t) => {
  const local = spawnSync('php', ['shared/test/engine.test.php'], { cwd: ROOT, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${ROOT}:/m:ro`, '-w', '/m', 'php:8.4-cli-alpine', 'php', 'shared/test/engine.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || noDocker(r)) { t.skip('no php and no docker here — run shared/test/engine.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
