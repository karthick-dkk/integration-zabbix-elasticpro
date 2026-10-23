/**
 * The Discover page's summary (views/js/ep.clients.discover.js): what the ticked rows add up to
 * under the two proxy choices, and that the view loads the file.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const HERE = path.resolve(import.meta.dirname, '..');
const D = createRequire(import.meta.url)(path.join(HERE, 'views/js/ep.clients.discover.js'));

const rows = [
  { cluster: { host: 'acme', proxy: 'proxy:dc1' }, groups: ['acme'], hosts: 3, es_proxy: 'proxy:dc1', linux_proxy: 'proxy:dc2',
    servers: [{ host: '10.0.0.1', proxy: 'proxy:dc2' }, { host: '10.0.0.2', proxy: '' }] },
  { cluster: { host: 'globex', proxy: '' }, groups: ['globex', 'Parsers'], hosts: 1, es_proxy: '', linux_proxy: '', servers: [] },
];

test('as each host has today: only the host that disagrees with its client\'s choice moves', () => {
  assert.deepEqual(D.summarize(rows, D.KEEP, D.KEEP), { count: 2, links: 2, groups: 3, hosts: 4, proxy: 1 });
});

test('a chosen proxy moves every host not on it already', () => {
  assert.deepEqual(D.summarize(rows, 'proxy:dc1', 'server'), { count: 2, links: 2, groups: 3, hosts: 4, proxy: 2 });
  assert.equal(D.summarize(rows, 'server', 'proxy:dc2').proxy, 2);
});

test('the Linux hosts follow the cluster host when their own choice is empty, except through a jump host', () => {
  const r = [{ cluster: { host: 'x', proxy: 'proxy:a' }, groups: [], hosts: 2, es_proxy: 'proxy:a', linux_proxy: '', servers: [{ host: 's', proxy: 'proxy:a' }] }];
  assert.equal(D.summarize(r, D.KEEP, D.KEEP).proxy, 0);
  const j = [{ cluster: { host: 'x', proxy: '' }, groups: [], hosts: 2, es_proxy: 'jump', linux_proxy: '', servers: [{ host: 's', proxy: '' }] }];
  assert.equal(D.summarize(j, D.KEEP, D.KEEP).proxy, 0);
});

test('nothing ticked adds up to nothing', () => {
  assert.deepEqual(D.summarize([], D.KEEP, D.KEEP), { count: 0, links: 0, groups: 0, hosts: 0, proxy: 0 });
});

test('the view reads this file and gives it the figures it adds up', () => {
  const view = fs.readFileSync(path.join(HERE, 'views/ep.clients.discover.php'), 'utf8');
  assert.match(view, /readfile\(__DIR__\.'\/js\/ep\.clients\.discover\.js'\)/);
  assert.match(view, /'data-ep-sum'/);
  assert.match(view, /'data-ep-text'/);
});

test('the summary line says "1 host", "2 hosts": the figure and the word agree', () => {
  const t = 'link %tpl templates to %links{host|hosts} · create %groups{group|groups} · set proxy on %proxy{host|hosts}';
  assert.equal(D.fill(t, { tpl: 5, links: 1, groups: 2, proxy: 0 }), 'link 5 templates to 1 host · create 2 groups · set proxy on 0 hosts');
  assert.equal(D.fill('Adopt selected (%n)', { n: 3 }), 'Adopt selected (3)');
  assert.equal(D.fill('%x{a|b} stays', { n: 1 }), '%x{a|b} stays', 'an unknown figure is left as it is');
});
