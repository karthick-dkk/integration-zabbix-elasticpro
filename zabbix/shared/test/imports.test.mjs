/**
 * Every class a PHP file names is one it imports, defines, or shares a namespace with.
 *
 * Zabbix autoloads a module's classes by namespace: `Settings::all()` inside
 * Modules\EpClients\Actions means Modules\EpClients\Actions\Settings, and when that does not
 * exist the page is a 500. The PHP suites run against a fake Zabbix whose loader is more
 * forgiving, so this is checked the way the real one resolves names — which is how
 * Cluster Management shipped as a fatal error with 1,106 checks green.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(import.meta.dirname, '../..');
const MODULES = ['clients-module', 'capacity-widget', 'resources-widget', 'volume-widget', 'tophosts-widget', 'overview-widget'];

const phpFiles = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => {
  const p = path.join(dir, e.name);
  if (e.isDirectory()) return (e.name === 'node_modules' || e.name === 'test') ? [] : phpFiles(p);
  return e.name.endsWith('.php') ? [p] : [];
});

/** The class names a file brings into scope with `use`, grouped or not, aliased or not. */
function imported(src) {
  const out = new Set();
  for (const m of src.matchAll(/^use\s+([^;]+);/gm)) {
    const body = m[1].trim();
    const group = body.match(/^([\w\\]+)\\\{([^}]+)\}$/);
    const names = group ? group[2].split(',') : body.split(',');
    for (const n of names) out.add(n.trim().split(/\s+as\s+/).pop().split('\\').pop());
  }
  return out;
}

/** Classes defined per namespace across a module, so same-namespace references resolve. */
function definedByNamespace(files) {
  const byNs = new Map();
  for (const f of files) {
    const src = fs.readFileSync(f, 'utf8');
    const ns = src.match(/^namespace\s+([\w\\]+);/m)?.[1] ?? '';
    for (const m of src.matchAll(/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/gm)) {
      if (!byNs.has(ns)) byNs.set(ns, new Set());
      byNs.get(ns).add(m[1]);
    }
  }
  return byNs;
}

// Zabbix's own (global namespace) classes and PHP's: anything imported with a bare `use X;`
// is fine, and a few are used without one in views, which Zabbix includes in the global scope.
const GLOBAL_OK = /^(C[A-Z]\w*|API|Exception|Throwable|DateTime\w*|Zip\w*|Spl\w*|Array\w*|Json\w*|Runtime\w*|Invalid\w*|ZBX_\w*|self|static|parent)$/;

for (const mod of MODULES) {
  test(`${mod}: every class reference resolves the way Zabbix's autoloader resolves it`, () => {
    const files = phpFiles(path.join(ROOT, mod));
    const defined = definedByNamespace(files);
    const problems = [];
    for (const f of files) {
      const src = fs.readFileSync(f, 'utf8')
        .replace(/\/\*[\s\S]*?\*\//g, '')          // block comments
        .replace(/^\s*\/\/.*$/gm, '')               // line comments
        .replace(/'(?:[^'\\]|\\.)*'/g, "''")        // string literals
        .replace(/"(?:[^"\\]|\\.)*"/g, '""');
      const ns = src.match(/^namespace\s+([\w\\]+);/m)?.[1] ?? '';
      const uses = imported(src);
      const own = defined.get(ns) ?? new Set();
      for (const m of src.matchAll(/(?<![\w\\$>:])([A-Z]\w+)::/g)) {
        const name = m[1];
        if (uses.has(name) || own.has(name) || GLOBAL_OK.test(name)) continue;
        problems.push(`${path.relative(ROOT, f)}: ${name}:: is neither imported nor in namespace ${ns || '(global)'}`);
      }
    }
    assert.deepEqual([...new Set(problems)], [], problems.join('\n'));
  });
}
