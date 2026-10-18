#!/usr/bin/env node
/**
 * One version for every ElasticPro Zabbix module: x.y.z, in each zabbix/<module>/manifest.json,
 * and a matching `## x.y.z` section in zabbix/CHANGELOG.md.
 *
 *   node tools/version.mjs                  print the version the manifests carry
 *   node tools/version.mjs 2.1.1            set it everywhere; adds a `## 2.1.1` heading to the CHANGELOG
 *   node tools/version.mjs 2.3.0 --allow-jump   a version that is not the next patch (2.1.0 -> 2.1.1)
 *   node tools/version.mjs --check          fail unless the manifests agree on one x.y.z and the
 *                                           CHANGELOG has a section for it with no placeholder left
 *   node tools/version.mjs --notes          print the CHANGELOG section of the current version
 *                                           (the body of the GitHub release)
 *
 * Also: `npm run version -- 2.1.1`.
 *
 * Bumps go one patch at a time. 2.1.0 -> 2.1.1 is allowed; 2.1.0 -> 2.3.0 or 3.0.0 is refused
 * unless --allow-jump is given, and a version that is not higher than today's is always refused.
 *
 * A module is any folder next to this tools/ folder that has a manifest.json. Manifests are
 * edited in place, one line, so their own indentation is untouched.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

export const ZABBIX = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
export const CHANGELOG = 'CHANGELOG.md';
/** What a freshly bumped CHANGELOG section holds until someone writes it; --check fails while it is there. */
export const PLACEHOLDER = 'TODO: describe what changed in this release.';

export const isSemver = (v) => typeof v === 'string' && /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/.test(v);
const parts = (v) => v.split('.').map(Number);

/** -1, 0 or 1 */
export function compare(a, b) {
  const [x, y] = [parts(a), parts(b)];
  for (let i = 0; i < 3; i++) if (x[i] !== y[i]) return x[i] < y[i] ? -1 : 1;
  return 0;
}

/** Is `to` the next patch after `from`: same major and minor, patch one higher. */
export function isNextPatch(from, to) {
  const [f, t] = [parts(from), parts(to)];
  return f[0] === t[0] && f[1] === t[1] && t[2] === f[2] + 1;
}

/** Every folder under `root` that holds a manifest.json, sorted. */
export function moduleDirs(root = ZABBIX) {
  return fs.readdirSync(root, { withFileTypes: true })
    .filter((e) => e.isDirectory() && e.name !== 'node_modules' && fs.existsSync(path.join(root, e.name, 'manifest.json')))
    .map((e) => e.name).sort();
}

/** { module: version-string-or-undefined } */
export function readVersions(root = ZABBIX) {
  const out = {};
  for (const d of moduleDirs(root)) out[d] = JSON.parse(fs.readFileSync(path.join(root, d, 'manifest.json'), 'utf8')).version;
  return out;
}

/** The text of the CHANGELOG section for `version` (heading excluded), or null when there is none. */
export function section(changelog, version) {
  const lines = changelog.split('\n');
  const at = lines.findIndex((l) => l.trim() === `## ${version}`);
  if (at === -1) return null;
  let end = lines.findIndex((l, i) => i > at && /^## /.test(l));
  if (end === -1) end = lines.length;
  return lines.slice(at + 1, end).join('\n').trim();
}

/**
 * The problems with the state on disk, as sentences; an empty list means it is releasable.
 * Pure over what it is given, so the tests can hand it broken states.
 */
export function problems(versions, changelog) {
  const out = [];
  const names = Object.keys(versions);
  if (!names.length) return ['no module manifests found'];
  const distinct = [...new Set(Object.values(versions))];
  for (const n of names) {
    if (!isSemver(versions[n])) out.push(`${n}/manifest.json: version ${JSON.stringify(versions[n])} is not x.y.z`);
  }
  if (distinct.length > 1) {
    out.push(`the manifests disagree: ${names.map((n) => `${n} ${versions[n]}`).join(', ')}`);
  }
  const version = distinct.length === 1 ? distinct[0] : null;
  if (version && isSemver(version)) {
    const body = changelog === null ? null : section(changelog, version);
    if (changelog === null) out.push(`${CHANGELOG} is missing`);
    else if (body === null) out.push(`${CHANGELOG} has no "## ${version}" section`);
    else if (body === '') out.push(`${CHANGELOG}: the "## ${version}" section is empty`);
  }
  if (changelog && changelog.includes(PLACEHOLDER)) out.push(`${CHANGELOG} still holds the placeholder "${PLACEHOLDER}" — write what changed`);
  return out;
}

export function readChangelog(root = ZABBIX) {
  const f = path.join(root, CHANGELOG);
  return fs.existsSync(f) ? fs.readFileSync(f, 'utf8') : null;
}

/** The CHANGELOG with a `## version` section and the placeholder added above the newest one. */
export function addSection(changelog, version) {
  const block = `## ${version}\n\n${PLACEHOLDER}\n\n`;
  const at = changelog.search(/^## /m);
  if (at === -1) return changelog.replace(/\n*$/, '\n\n') + block.replace(/\n+$/, '\n');
  return changelog.slice(0, at) + block + changelog.slice(at);
}

/** Why `from` -> `to` is refused, or null when it is fine. */
export function refusal(from, to, allowJump) {
  if (!isSemver(to)) return `${JSON.stringify(to)} is not x.y.z`;
  if (!isSemver(from)) return null; // the manifests are broken; setting them right is what this is for
  if (compare(to, from) <= 0) return `${to} is not higher than the current ${from}`;
  if (!allowJump && !isNextPatch(from, to)) {
    const [f] = [parts(from)];
    return `${from} -> ${to} is a jump; bumps go one patch at a time (next is ${f[0]}.${f[1]}.${f[2] + 1}). Pass --allow-jump if you mean it`;
  }
  return null;
}

/** Writes `version` into every manifest, keeping each file's formatting. Returns the modules changed. */
export function setVersion(version, root = ZABBIX) {
  const done = [];
  for (const d of moduleDirs(root)) {
    const f = path.join(root, d, 'manifest.json');
    const before = fs.readFileSync(f, 'utf8');
    const re = /^([ \t]*"version"[ \t]*:[ \t]*")[^"]*(")/m;
    if (!re.test(before)) throw new Error(`${d}/manifest.json has no "version" line to set`);
    const after = before.replace(re, `$1${version}$2`);
    if (JSON.parse(after).version !== version) throw new Error(`${d}/manifest.json: the edit did not give version ${version}`);
    if (after !== before) fs.writeFileSync(f, after);
    done.push(d);
  }
  return done;
}

/** The single shared version, or throws saying what is wrong. */
export function currentVersion(root = ZABBIX) {
  const versions = readVersions(root);
  const distinct = [...new Set(Object.values(versions))];
  if (distinct.length !== 1) throw new Error(`the manifests disagree: ${Object.entries(versions).map(([n, v]) => `${n} ${v}`).join(', ')}`);
  return distinct[0];
}

function main(argv) {
  const args = argv.slice(2);
  const flags = new Set(args.filter((a) => a.startsWith('--')));
  const known = new Set(['--check', '--notes', '--allow-jump']);
  for (const f of flags) if (!known.has(f)) { console.error(`unknown option ${f}`); return 2; }
  const positional = args.filter((a) => !a.startsWith('--'));

  if (flags.has('--check')) {
    const bad = problems(readVersions(), readChangelog());
    if (bad.length) { for (const b of bad) console.error(`x ${b}`); return 1; }
    console.log(`ok: every module is ${currentVersion()} and ${CHANGELOG} says what changed`);
    return 0;
  }
  if (flags.has('--notes')) {
    const v = currentVersion();
    const body = section(readChangelog() ?? '', v);
    if (!body) { console.error(`x ${CHANGELOG} has no text under "## ${v}"`); return 1; }
    console.log(body);
    return 0;
  }
  if (!positional.length) { console.log(currentVersion()); return 0; }
  if (positional.length > 1) { console.error('one version at a time'); return 2; }

  const to = positional[0].replace(/^v/, '');
  let from;
  try { from = currentVersion(); } catch { from = null; }
  const why = refusal(from, to, flags.has('--allow-jump'));
  if (why) { console.error(`x ${why}`); return 1; }
  const changelog = readChangelog();
  if (changelog === null) { console.error(`x ${CHANGELOG} is missing`); return 1; }
  const had = section(changelog, to) !== null;
  const modules = setVersion(to);
  if (!had) fs.writeFileSync(path.join(ZABBIX, CHANGELOG), addSection(changelog, to));
  console.log(`${from ?? 'mixed'} -> ${to} in ${modules.length} manifests (${modules.join(', ')})`);
  console.log(had ? `${CHANGELOG} already has a "## ${to}" section` : `${CHANGELOG}: added "## ${to}" — replace the placeholder with what changed, then run: npm run version -- --check`);
  return 0;
}

if (process.argv[1] && import.meta.url === pathToFileURL(fs.realpathSync(process.argv[1])).href) process.exitCode = main(process.argv);
