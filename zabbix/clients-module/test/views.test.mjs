/**
 * Runs test/views.test.php — the list and the client page, rendered — with PHP: local php, or a
 * php:8.4-cli container, and skips, saying so, when neither is available.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const HERE = path.resolve(import.meta.dirname, '..');
const noDocker = (r) => r.status !== 0 && /docker|daemon/i.test(`${r.stderr || ''}${r.stdout || ''}`)
  && !/passed, \d+ failed|FAIL:/.test(`${r.stdout || ''}`);

test('the list and the client page, rendered (PHP)', (t) => {
  const local = spawnSync('php', ['test/views.test.php'], { cwd: HERE, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${HERE}:/m:ro`, '-w', '/m', 'php:8.4-cli-alpine', 'php', 'test/views.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || noDocker(r)) { t.skip('no php and no docker here — run test/views.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
