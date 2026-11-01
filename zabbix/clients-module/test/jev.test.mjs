/**
 * Runs test/jev.test.php — TypeSafe Jev judging Discover's guesses from names, against a stubbed HTTP layer (the real API is never called) — with PHP: local php, or a
 * php:8.4-cli container, and skips, saying so, when neither is available.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const HERE = path.resolve(import.meta.dirname, '..');

/**
 * Docker could not be reached at all, as opposed to the tests having failed. Matched loosely on
 * purpose: this used to look for one exact sentence, so a stopped Docker Desktop — which words it
 * differently ("failed to connect to the docker API at unix://…") — turned every PHP suite into a
 * red failure that had nothing to do with the code.
 */
const noDocker = (r) => r.status !== 0 && /docker|daemon/i.test(`${r.stderr || ''}${r.stdout || ''}`)
  && !/passed, \d+ failed|FAIL:/.test(`${r.stdout || ''}`);

test('Discover judged by TypeSafe Jev, against a fake Zabbix and a stubbed API (PHP)', (t) => {
  const local = spawnSync('php', ['test/jev.test.php'], { cwd: HERE, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${HERE}:/m:ro`, '-w', '/m', 'php:8.4-cli-alpine', 'php', 'test/jev.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || noDocker(r)) { t.skip('no php and no docker here — run test/jev.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
