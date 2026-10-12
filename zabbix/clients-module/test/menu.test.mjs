/**
 * Runs test/menu.test.php — the ElasticPro section is made, joined and never doubled — with PHP: local php, or a
 * php:8.4-cli container, and skips, saying so, when neither is available.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const HERE = path.resolve(import.meta.dirname, '..');

test('the sidebar (PHP)', (t) => {
  const local = spawnSync('php', ['test/menu.test.php'], { cwd: HERE, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${HERE}:/m:ro`, '-w', '/m', 'php:8.4-cli-alpine', 'php', 'test/menu.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || /Cannot connect to the Docker daemon/.test(r.stderr || '')) { t.skip('no php and no docker here — run test/menu.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
