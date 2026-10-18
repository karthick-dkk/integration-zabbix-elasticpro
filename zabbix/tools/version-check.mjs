#!/usr/bin/env node
/**
 * Fails when a module changed but the version did not move.
 *
 *   node tools/version-check.mjs          (npm run version-check)
 *
 * Finds the newest `v*` tag reachable from HEAD. If the manifests still carry that tag's version
 * and any file the modules ship, under zabbix/<module>/ or zabbix/shared/, differs from the tag,
 * the change would reach a Zabbix under a version that already means something else: it fails
 * and names the files. Bump with `npm run version -- x.y.z`.
 *
 * "Ships" follows the release archive: tests, READMEs, *.py and *.mjs tool scripts, .gitignore
 * and macOS litter are not part of a module on a Zabbix, so changing only those needs no bump.
 *
 * Passes, and says why, when it has nothing to compare with: no git, not a checkout, no `v*` tag
 * yet. A shallow clone (no tag history) fails, because passing there would be a silent pass; CI
 * checks out with full history.
 */
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { ZABBIX, moduleDirs, currentVersion, compare, isSemver } from './version.mjs';

/** Mirrors what tools/zabbix-modules-tarball.sh in ElasticPro leaves out of a module folder. */
export function isShipped(rel) {
  const parts = rel.split('/');
  const base = parts[parts.length - 1];
  if (parts.slice(0, -1).some((p) => p === 'test' || p === 'tests' || p === 'node_modules')) return false;
  if (/\.(py|mjs)$/.test(base) || base === 'README.md' || base === '.gitignore' || base.startsWith('._') || base === '.DS_Store') return false;
  if (base === 'config.php' || (base.startsWith('config.php.') && base !== 'config.php.example')) return false;
  return true;
}

const git = (cwd, ...args) => execFileSync('git', args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();

/** { ok, message } — never throws for the cases the header lists. */
export function check(root = ZABBIX) {
  try { git(root, 'rev-parse', '--show-toplevel'); } catch { return { ok: true, message: 'skipped: git is not available here or this is not a checkout' }; }
  if (git(root, 'rev-parse', '--is-shallow-repository') === 'true') {
    return { ok: false, message: 'this is a shallow clone, so the tags cannot be compared; fetch the full history (CI: fetch-depth: 0)' };
  }
  const tags = git(root, 'tag', '--merged', 'HEAD', '--list', 'v*', '--sort=-v:refname').split('\n').filter((t) => /^v\d+\.\d+\.\d+$/.test(t));
  if (!tags.length) return { ok: true, message: 'no v* tag yet, so there is nothing to compare with; passes' };
  const tag = tags[0];
  const tagged = tag.slice(1);
  const version = currentVersion(root);
  if (isSemver(version) && compare(version, tagged) < 0) return { ok: false, message: `the manifests say ${version}, lower than the latest tag ${tag}` };
  if (version !== tagged) return { ok: true, message: `${version} is ahead of ${tag}; passes` };
  const mods = new Set([...moduleDirs(root), 'shared']);
  const changed = git(root, 'diff', '--name-only', '--relative', tag, '--', '.').split('\n').filter(Boolean)
    .filter((f) => mods.has(f.split('/')[0]) && isShipped(f));
  const untracked = git(root, 'ls-files', '--others', '--exclude-standard', '--', '.').split('\n').filter(Boolean)
    .filter((f) => mods.has(f.split('/')[0]) && isShipped(f));
  const all = [...new Set([...changed, ...untracked])];
  if (!all.length) return { ok: true, message: `nothing the modules ship has changed since ${tag}; ${version} stands` };
  return {
    ok: false,
    message: `${all.length} file(s) changed since ${tag} but the version is still ${version}:\n  ${all.slice(0, 20).join('\n  ')}${all.length > 20 ? `\n  … and ${all.length - 20} more` : ''}\nBump it: npm run version -- ${version.replace(/\d+$/, (n) => Number(n) + 1)}`,
  };
}

if (process.argv[1] && import.meta.url === pathToFileURL(fs.realpathSync(process.argv[1])).href) {
  const r = check();
  (r.ok ? console.log : console.error)(r.ok ? `ok: ${r.message}` : `x ${r.message}`);
  process.exitCode = r.ok ? 0 : 1;
}
