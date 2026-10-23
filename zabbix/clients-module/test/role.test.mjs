/**
 * Runs test/role.test.php — who may edit (EpRole), the signed role lookup it sends ElasticPro, and
 * the pre-check (WriteCheck) — with PHP: local php, or a php:8.4-cli container, and skips, saying
 * so, when neither is available. It loads the ElasticPro module's SsoClient from ../embed-module,
 * so the container gets the whole zabbix/ folder.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const HERE = path.resolve(import.meta.dirname, '..');
const ROOT = path.resolve(HERE, '..');
const noDocker = (r) => r.status !== 0 && /docker|daemon/i.test(`${r.stderr || ''}${r.stdout || ''}`)
  && !/passed, \d+ failed|FAIL:/.test(`${r.stdout || ''}`);

test('who may edit, the role lookup and the pre-check (PHP)', (t) => {
  const local = spawnSync('php', ['test/role.test.php'], { cwd: HERE, encoding: 'utf8' });
  const r = local.error
    ? spawnSync('docker', ['run', '--rm', '-v', `${ROOT}:/z:ro`, '-w', '/z/clients-module', 'php:8.4-cli-alpine', 'php', 'test/role.test.php'], { encoding: 'utf8' })
    : local;
  if (r.error || noDocker(r)) { t.skip('no php and no docker here — run test/role.test.php where PHP is'); return; }
  assert.equal(r.status, 0, `${r.stdout}${r.stderr}`);
});
