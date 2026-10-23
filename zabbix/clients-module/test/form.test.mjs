/**
 * The client form's server labels and the role-name table (views/js/ep.clients.edit.js) against the same table the PHP
 * naming is held to (names.json) — so what the page shows is what Zabbix gets.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const HERE = path.resolve(import.meta.dirname, '..');
const Form = createRequire(import.meta.url)(path.join(HERE, 'views/js/ep.clients.edit.js'));
const R = (id, short) => ({ id, short, label: id });
const families = [
  { id: 'es', label: 'ES', roles: [R('es_data', 'ES-Data'), R('es_data_hot', 'ES-Data-Hot'), R('es_data_warm', 'ES-Data-Warm'), R('es_coord', 'ES-Coord'), R('es_master', 'ES-Master')] },
  { id: 'parser', label: 'Parser', roles: [R('parser', 'Parser'), R('s3_parser', 'S3-Parser')] },
  { id: 'fwd', label: 'Forwarder', roles: [R('forwarder', 'Forwarder')] },
  { id: 'engine', label: 'Engine', roles: [R('engine', 'Engine'), R('ueba', 'UEBA'), R('aiml', 'AIML')] },
  { id: 'single', label: 'Single node', roles: [R('single_node', 'Single-Node')] },
];

test('the preview names servers as the PHP does, case by case', () => {
  const table = JSON.parse(fs.readFileSync(path.join(HERE, 'test/names.json'), 'utf8')).cases;
  for (const c of table) assert.equal(Form.serverBase(families, c.roles), c.base, c.roles.join('+'));
});

test('host name = IP, server name = visible name: nothing is made up, nothing renamed', () => {
  const servers = [
    { ip: '10.0.0.1', roles: ['es_data_hot'] },                          // new, no server name
    { ip: '10.0.0.2', roles: ['es_data_hot'], host: 'es-hot-02' },       // new, named
    { ip: '10.0.0.3', roles: ['engine'] },                               // existing, renamed by an earlier release
    { ip: '10.0.0.4', roles: ['parser'] },                               // existing, IP host name, a visible name
  ];
  const tech = { '10.0.0.3': 'karthi-Engine-1', '10.0.0.4': '10.0.0.4' };
  const visible = { '10.0.0.3': 'karthi-Engine-1', '10.0.0.4': 'parser-a' };
  assert.deepEqual(Form.labels(servers, tech, visible), [
    { host: '10.0.0.1', name: '' },
    { host: '10.0.0.2', name: 'es-hot-02' },
    { host: 'karthi-Engine-1', name: '' },
    { host: '10.0.0.4', name: 'parser-a' },
  ]);
  assert.equal(Form.names, undefined, 'the <client>-<Role>-<n> namer is gone');
});

test('the view reads this file, and the file follows the roles the page is given', () => {
  const view = fs.readFileSync(path.join(HERE, 'views/ep.clients.edit.php'), 'utf8');
  assert.match(view, /readfile\(__DIR__\.'\/js\/ep\.clients\.edit\.js'\)/);
  assert.match(view, /name="servers" id="servers"/);
});
