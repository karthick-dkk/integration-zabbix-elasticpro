/**
 * The two set-up scripts and the numbers the manifests must agree with. Nothing here talks to a
 * Zabbix: these are the properties that were wrong in a way no test would have noticed — a
 * re-run that exited 1, a dashboard built with no group filter, a template list that had two of
 * its ten slots, and a refresh rate drifting from the settings default.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '../..');
const read = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8');
const SCRIPTS = ['master/import.py', 'volume-widget/import.py'];

/** The body of a PHP `const NAME = [...]`, so a key is read from that block and no other. */
function phpConst(php, name) {
  const at = php.indexOf(`const ${name} = [`);
  assert.notEqual(at, -1, `${name} is not in Roles.php`);
  return php.slice(at, php.indexOf('];', at));
}
const phpConstKeys = (php, name) => [...phpConst(php, name).matchAll(/^\s*'([a-z_]+)' =>/gm)].map((m) => m[1]);

test('a re-run of either script is a success, not a failure', () => {
  for (const f of SCRIPTS) {
    const py = read(f);
    // sys.exit("message") writes to stderr and exits 1. For the ordinary "already there"
    // case that makes every repeat run look broken to whatever is calling it.
    for (const m of py.matchAll(/sys\.exit\((.*)\)/g)) {
      assert.ok(!/kept as it is|already/.test(m[1]), `${f}: an idempotent no-op must print, not sys.exit: ${m[0]}`);
    }
    assert.match(py, /print\("dashboard kept as it is:"|print\("dashboard", did/, `${f}: says what it did`);
  }
});

test('the volume script makes the host group it filters on, like the master one', () => {
  const py = read('volume-widget/import.py');
  // Without this it built the dashboard with an empty `fields`, which is no filter at all —
  // every cluster host on the system — and then kept that dashboard for good.
  assert.match(py, /hostgroup\.create/);
  assert.match(py, /GROUP = "Elasticsearch clusters"/);
  const at = py.indexOf('hostgroup.create');
  assert.ok(at < py.indexOf('dashboard.create'), 'the group is made before the dashboard that filters on it');
});

test('both scripts say what went wrong instead of raising a traceback', () => {
  for (const f of SCRIPTS) {
    const py = read(f);
    assert.match(py, /except urllib\.error\.HTTPError/, `${f}: an HTTP error is explained`);
    assert.match(py, /except urllib\.error\.URLError/, `${f}: an unreachable host is explained`);
    assert.match(py, /import .*urllib\.error/, `${f}: imports what it catches`);
    // os.environ["ZABBIX_USER"] on a missing variable is a KeyError traceback.
    assert.match(py, /set ZABBIX_TOKEN, or both ZABBIX_USER and ZABBIX_PASSWORD/, `${f}: asks for credentials in words`);
    const guard = py.indexOf('set ZABBIX_TOKEN, or both');
    assert.ok(guard !== -1 && guard < py.indexOf('os.environ["ZABBIX_USER"]'), `${f}: and asks before it subscripts`);
    assert.match(py, /apiinfo\.version/, `${f}: checks the Zabbix version before it creates anything`);
    assert.match(py, /authed=False/, `${f}: apiinfo.version refuses an authorised request`);
  }
});

test('the set-up script knows every template slot the Roles page can map', () => {
  const roles = read('shared/php/Roles.php');
  const slots = phpConstKeys(roles, 'TEMPLATE_SLOTS');
  const py = read('master/import.py');
  const block = py.slice(py.indexOf('TEMPLATE_DEFAULTS = {'), py.indexOf('}', py.indexOf('TEMPLATE_DEFAULTS = {')));
  const pySlots = [...block.matchAll(/"([a-z_]+)":/g)].map((m) => m[1]);
  // Two of ten before: a site that mapped "ElasticPro alerts" was told by this script to import
  // a template it had deliberately renamed.
  assert.deepEqual(pySlots, slots, 'master/import.py must name the same slots as Roles::TEMPLATE_SLOTS');
  // And each shipped name must be the one PHP ships, or the two disagree about what to look for.
  // Read from the TEMPLATE_SLOTS block alone: the same keys appear in TEMPLATE_USED_BY.
  const slotBody = phpConst(roles, 'TEMPLATE_SLOTS');
  let checked = 0;
  for (const slot of slots) {
    const shipped = slotBody.match(new RegExp(`'${slot}' => \\['([^']+)'`));
    if (shipped) {
      assert.ok(block.includes(`"${shipped[1]}"`), `${slot}: import.py must ship "${shipped[1]}"`);
      checked++;
    }
  }
  // cluster and agent name their shipped value through a constant, so eight of ten are literals.
  assert.equal(checked, 8, 'the shipped names actually got compared');
});

test('both scripts read templates.json from the folders the PHP looks in, in that order', () => {
  for (const f of SCRIPTS) {
    const py = read(f);
    assert.match(py, /\/var\/lib\/elasticpro-zabbix/, `${f}: the current folder`);
    // Without the legacy folder, a renamed install's script and its PHP disagree about which
    // templates the site uses, and the script demands names that are present under others.
    assert.match(py, /\/var\/lib\/elasticvue-zabbix/, `${f}: and the folder of the old name`);
    assert.ok(py.indexOf('elasticpro-zabbix') < py.indexOf('elasticvue-zabbix'), `${f}: current first`);
    assert.match(py, /EP_DATA_DIR/, `${f}: which EP_DATA_DIR overrides`);
  }
});

test('each widget manifest starts at the refresh its settings default names', () => {
  // Zabbix reads refresh_rate from the manifest once, when the module loads, and a placed
  // widget's interval then lives in each user's own profile. The settings file cannot change it,
  // so the only thing worth guarding is that the two numbers do not drift apart.
  const settings = read('shared/php/Settings.php');
  const refresh = settings.slice(settings.indexOf("'refresh' => ["), settings.indexOf(']', settings.indexOf("'refresh' => [")));
  const want = Object.fromEntries([...refresh.matchAll(/'([a-z]+)' => (\d+)/g)].map((m) => [m[1], Number(m[2])]));
  assert.deepEqual(Object.keys(want), ['capacity', 'resources', 'volume']);
  for (const [report, dir] of [['capacity', 'capacity-widget'], ['resources', 'resources-widget'], ['volume', 'volume-widget']]) {
    assert.equal(JSON.parse(read(`${dir}/manifest.json`)).widget.refresh_rate, want[report], `${dir}: refresh_rate`);
  }
});

test('the modules agree on their version and author', () => {
  const dirs = ['clients-module', 'capacity-widget', 'resources-widget', 'volume-widget', 'tophosts-widget', 'overview-widget'];
  const seen = dirs.map((d) => {
    const m = JSON.parse(read(`${d}/manifest.json`));
    return {dir: d, version: m.version, author: m.author, manifest_version: m.manifest_version};
  });
  for (const m of seen) {
    assert.equal(m.version, seen[0].version, `${m.dir}: version drifted from ${seen[0].dir}`);
    assert.equal(m.author, seen[0].author, `${m.dir}: author drifted`);
    // The real Zabbix-side gate, and what makes these loadable at all.
    assert.equal(m.manifest_version, 2.0, `${m.dir}: manifest_version`);
  }
});

test('every template says which generation of itself it writes', () => {
  // Three of these are what TemplateInstaller compares against a macro on the installed
  // template to decide it is stale, so they are deliberately independent numbers — bumping one
  // must not mark the others outdated. What is worth holding is that each vendor line is built
  // from its own VERSION and not written out by hand, which is how '7.0-3' came to sit in
  // MasterTemplate where no search for VERSION would find it.
  for (const f of ['ClusterTemplate', 'DevicesTemplate', 'JumpTemplate', 'MasterTemplate']) {
    const php = read(`clients-module/lib/${f}.php`);
    assert.match(php, /public const VERSION = '\d+';/, `${f}: declares its generation`);
    for (const m of php.matchAll(/'vendor' => \[([^\]]+)\]/g)) {
      assert.match(m[1], /'version' => '7\.0-'\.self::VERSION/, `${f}: the vendor line is built from VERSION`);
    }
  }
  // And the installer must have a staleness check for each of the three fixed-content ones.
  const installer = read('clients-module/lib/TemplateInstaller.php');
  for (const f of ['DevicesTemplate', 'JumpTemplate', 'ClusterTemplate']) {
    assert.ok(installer.includes(`${f}::VERSION`), `${f}: the installer notices when it is stale`);
  }
  // The master template's contents follow the roles, so its staleness is the roles fingerprint.
  assert.match(installer, /Roles::hash/);
});
