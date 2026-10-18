/**
 * The module version: one x.y.z in every manifest, and a CHANGELOG section for it that someone
 * wrote. The first two tests hold the real repository to that; the rest hold the tools
 * (tools/version.mjs, tools/version-check.mjs) to what they promise, on throwaway copies.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';
import {
  ZABBIX, PLACEHOLDER, isSemver, isNextPatch, refusal, readVersions, readChangelog, moduleDirs,
  problems, section, addSection, setVersion, currentVersion,
} from '../version.mjs';
import { check, isShipped } from '../version-check.mjs';

const tmp = () => fs.mkdtempSync(path.join(os.tmpdir(), 'ep-version-'));

test('every module manifest carries the same x.y.z', () => {
  const versions = readVersions();
  assert.deepEqual(moduleDirs().sort(),
    ['capacity-widget', 'clients-module', 'embed-module', 'overview-widget', 'resources-widget', 'tophosts-widget', 'volume-widget'],
    'a module was added or removed: say so here, and in the tarball tool and installer of ElasticPro');
  for (const [dir, v] of Object.entries(versions)) assert.ok(isSemver(v), `${dir}/manifest.json: version ${JSON.stringify(v)} is not x.y.z`);
  assert.equal(new Set(Object.values(versions)).size, 1, `the manifests disagree: ${JSON.stringify(versions)}`);
});

test('the CHANGELOG has a written section for that version', () => {
  const version = currentVersion();
  const log = readChangelog();
  assert.ok(log, 'CHANGELOG.md is missing');
  const body = section(log, version);
  assert.ok(body, `CHANGELOG.md has no "## ${version}" section, or it is empty — run: npm run version -- ${version}`);
  assert.ok(!log.includes(PLACEHOLDER), `CHANGELOG.md still holds the placeholder "${PLACEHOLDER}"`);
  assert.deepEqual(problems(readVersions(), log), []);
});

test('the CHANGELOG names the product ElasticPro and no other', () => {
  assert.ok(!/elasticvue/i.test(readChangelog()));
});

test('only the next patch is accepted without --allow-jump', () => {
  assert.equal(refusal('2.1.0', '2.1.1', false), null);
  assert.equal(refusal('2.1.9', '2.1.10', false), null);
  assert.match(refusal('2.1.0', '2.3.0', false), /jump/);
  assert.match(refusal('2.1.0', '3.0.0', false), /jump/);
  assert.match(refusal('2.1.0', '2.2.0', false), /jump/);
  assert.match(refusal('2.1.0', '2.1.2', false), /jump/);
  assert.equal(refusal('2.1.0', '2.3.0', true), null);
  assert.match(refusal('2.1.0', '2.1.0', true), /not higher/);
  assert.match(refusal('2.1.1', '2.1.0', true), /not higher/);
  for (const bad of ['2.1', '2.1.0.1', 'v2.1.1', '2.1.x', '', '02.1.1', '2.1.1-rc1']) assert.match(refusal('2.1.0', bad, true), /not x\.y\.z/, bad);
  assert.ok(isNextPatch('2.1.0', '2.1.1') && !isNextPatch('2.1.0', '2.2.1'));
});

test('--check problems: placeholder, disagreement, malformed, missing section', () => {
  const ok = { a: '2.1.0', b: '2.1.0' };
  const log = '# Changelog\n\n## 2.1.0\n\nSomething real.\n';
  assert.deepEqual(problems(ok, log), []);
  assert.match(problems(ok, `${log}\n${PLACEHOLDER}`).join(), /placeholder/);
  assert.match(problems({ a: '2.1.0', b: '2.1.1' }, log).join(), /disagree/);
  assert.match(problems({ a: '2.1', b: '2.1' }, log).join(), /not x\.y\.z/);
  assert.match(problems(ok, '# Changelog\n\n## 2.0.9\n\nOld.\n').join(), /no "## 2\.1\.0" section/);
  assert.match(problems(ok, '# Changelog\n\n## 2.1.0\n\n## 2.0.9\n\nOld.\n').join(), /empty/);
  assert.match(problems(ok, null).join(), /missing/);
  assert.match(problems({}, log).join(), /no module manifests/);
});

test('a section is found by its exact heading and stops at the next', () => {
  const log = '# C\n\n## 2.1.10\n\nten\n\n## 2.1.1\n\none\n\n## 2.1.0\n\nzero\n';
  assert.equal(section(log, '2.1.1'), 'one');
  assert.equal(section(log, '2.1.10'), 'ten');
  assert.equal(section(log, '2.1.0'), 'zero');
  assert.equal(section(log, '2.1'), null);
});

test('addSection puts the new heading and placeholder above the newest, intro untouched', () => {
  const out = addSection('# Changelog\n\nintro\n\n## 2.1.0\n\nreal\n', '2.1.1');
  assert.equal(out, `# Changelog\n\nintro\n\n## 2.1.1\n\n${PLACEHOLDER}\n\n## 2.1.0\n\nreal\n`);
  assert.match(addSection('# Changelog\n', '2.1.1'), new RegExp(`## 2\\.1\\.1\\n\\n${PLACEHOLDER}\\n$`));
});

test('the bump tool, end to end on a copy: sets every manifest, keeps formatting, adds the heading, then --check demands text', () => {
  const root = tmp();
  fs.mkdirSync(path.join(root, 'tools'));
  for (const f of ['version.mjs']) fs.copyFileSync(path.join(ZABBIX, 'tools', f), path.join(root, 'tools', f));
  const manifests = { 'one-module': '{\n\t"id": "one",\n\t"version": "2.1.0"\n}\n', 'two-widget': '{\n    "id": "two",\n    "version": "2.1.0",\n    "name": "Two"\n}\n' };
  for (const [d, text] of Object.entries(manifests)) { fs.mkdirSync(path.join(root, d)); fs.writeFileSync(path.join(root, d, 'manifest.json'), text); }
  fs.writeFileSync(path.join(root, 'CHANGELOG.md'), '# Changelog\n\n## 2.1.0\n\nFirst.\n');
  const run = (...a) => spawnSync(process.execPath, [path.join(root, 'tools', 'version.mjs'), ...a], { encoding: 'utf8' });

  assert.equal(run('--check').status, 0, run('--check').stderr);
  const jump = run('2.2.0');
  assert.equal(jump.status, 1);
  assert.match(jump.stderr, /jump/);
  assert.equal(fs.readFileSync(path.join(root, 'one-module/manifest.json'), 'utf8'), manifests['one-module'], 'a refused bump touches nothing');

  const bump = run('2.1.1');
  assert.equal(bump.status, 0, bump.stderr);
  assert.equal(fs.readFileSync(path.join(root, 'one-module/manifest.json'), 'utf8'), manifests['one-module'].replace('2.1.0', '2.1.1'));
  assert.equal(fs.readFileSync(path.join(root, 'two-widget/manifest.json'), 'utf8'), manifests['two-widget'].replace('2.1.0', '2.1.1'));
  assert.match(fs.readFileSync(path.join(root, 'CHANGELOG.md'), 'utf8'), /## 2\.1\.1\n\nTODO[^\n]*\n\n## 2\.1\.0/);
  const placeholder = run('--check');
  assert.equal(placeholder.status, 1);
  assert.match(placeholder.stderr, /placeholder/);

  fs.writeFileSync(path.join(root, 'CHANGELOG.md'), fs.readFileSync(path.join(root, 'CHANGELOG.md'), 'utf8').replace(PLACEHOLDER, 'Fixed the thing.'));
  assert.equal(run('--check').status, 0);
  assert.equal(run('--notes').stdout.trim(), 'Fixed the thing.');
  assert.equal(run().stdout.trim(), '2.1.1');

  setVersion('2.1.1', root); // idempotent
  fs.writeFileSync(path.join(root, 'two-widget/manifest.json'), manifests['two-widget']);
  assert.match(run('--check').stderr, /disagree/);
  assert.equal(run('--allow-jump', '4.0.0').status, 0, 'a jump with --allow-jump, from mixed manifests, is allowed');
  assert.equal(run('--check').status, 1, 'no section text yet for 4.0.0');
});

test('isShipped follows what the release archive carries', () => {
  for (const f of ['clients-module/lib/Build.php', 'clients-module/actions/SettingsEdit.php', 'overview-widget/assets/js/class.widget.js', 'shared/php/Store.php', 'clients-module/config.php.example', 'clients-module/manifest.json']) assert.ok(isShipped(f), f);
  for (const f of ['clients-module/test/build.test.php', 'shared/test/export.test.mjs', 'clients-module/README.md', 'master/import.py', 'x/._junk', 'x/config.php', 'x/config.php.local', 'sync-assets.mjs']) assert.ok(!isShipped(f), f);
});

// ---- tools/version-check.mjs, on a throwaway git repository shaped like this one ----
function repo() {
  const top = tmp();
  const z = path.join(top, 'zabbix');
  for (const d of ['clients-module', 'shared']) fs.mkdirSync(path.join(z, d), { recursive: true });
  fs.writeFileSync(path.join(z, 'clients-module/manifest.json'), '{ "version": "2.1.0" }\n');
  fs.mkdirSync(path.join(z, 'clients-module/test'));
  fs.writeFileSync(path.join(z, 'clients-module/Module.php'), '<?php\n');
  fs.writeFileSync(path.join(z, 'shared/a.js'), '1\n');
  fs.writeFileSync(path.join(z, 'README.md'), 'readme\n');
  const git = (...a) => execFileSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@example.com', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...a], { cwd: top, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  git('init', '-q');
  git('add', '.'); git('commit', '-qm', 'one');
  return { top, z, git };
}

test('version-check: no tag yet passes and says so', () => {
  const { z } = repo();
  const r = check(z);
  assert.ok(r.ok);
  assert.match(r.message, /no v\* tag yet/);
});

test('version-check: a shipped change under the tag\'s version fails; a bump, or a test/README change, does not', () => {
  const { z, git } = repo();
  git('tag', 'v2.1.0');
  assert.ok(check(z).ok, 'nothing changed since the tag');

  fs.writeFileSync(path.join(z, 'clients-module/test/x.test.mjs'), 'x\n');
  fs.writeFileSync(path.join(z, 'README.md'), 'changed\n');
  git('add', '.'); git('commit', '-qm', 'tests and docs');
  assert.ok(check(z).ok, 'tests and READMEs are not shipped');

  fs.writeFileSync(path.join(z, 'shared/a.js'), '2\n');
  git('add', '.'); git('commit', '-qm', 'shared');
  const bad = check(z);
  assert.equal(bad.ok, false);
  assert.match(bad.message, /shared\/a\.js/);
  assert.match(bad.message, /npm run version -- 2\.1\.1/);

  fs.writeFileSync(path.join(z, 'clients-module/manifest.json'), '{ "version": "2.1.1" }\n');
  const bumped = check(z);
  assert.ok(bumped.ok, bumped.message);
  assert.match(bumped.message, /ahead of v2\.1\.0/);
});

test('version-check: an untracked new module file counts; a version below the tag fails; the newest tag wins', () => {
  const { z, git } = repo();
  git('tag', 'v2.1.0');
  fs.writeFileSync(path.join(z, 'clients-module/New.php'), '<?php\n');
  assert.equal(check(z).ok, false);
  fs.rmSync(path.join(z, 'clients-module/New.php'));
  fs.writeFileSync(path.join(z, 'clients-module/manifest.json'), '{ "version": "2.0.9" }\n');
  assert.match(check(z).message, /lower than the latest tag v2\.1\.0/);
  fs.writeFileSync(path.join(z, 'clients-module/manifest.json'), '{ "version": "2.1.0" }\n');
  git('tag', 'v2.0.10');
  git('tag', 'v2.1.0-rc1');
  assert.ok(check(z).ok, 'v2.1.0 sorts above v2.0.10 and the rc is not a release tag');
});

test('version-check: a shallow clone fails rather than passing blind', () => {
  const { top, git, z } = repo();
  git('tag', 'v2.1.0');
  const clone = tmp();
  execFileSync('git', ['clone', '-q', '--depth', '1', `file://${top}`, clone + '/c'], { stdio: 'ignore' });
  const r = check(path.join(clone, 'c', 'zabbix'));
  assert.equal(r.ok, false);
  assert.match(r.message, /shallow/);
  assert.ok(z);
});
