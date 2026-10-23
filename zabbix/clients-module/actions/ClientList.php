<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use API;
use CControllerResponseData;
use Exception;
use Modules\EpClients\Lib\{ClientSpec, ClientState, ClusterSecret, DefaultCredential, Discovery, Forecast, History, JumpTemplate, ListColumns, MasterTemplate, Lifecycle, Missing, Reconciler, Registry, Roles, Settings, SetupChecks, Store, TemplateInstaller};

/**
 * The clients, by status: each with its type, ES URL, contacts, servers per family, setup checks,
 * open problems, days until its storage fills, hosts removed from Zabbix, and last change. A
 * client whose master host was deleted in Zabbix stays listed from the register, with a way back.
 * Above: whether the templates are current and backups can be kept.
 */
class ClientList extends Base {

	protected const READ_ONLY_OK = true;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$roles = $this->roles();
		// Asked once. It was asked once per client inside the loop below — which on an unwritable
		// folder meant one log line per client per page load, and a mkdir attempt from a page
		// that only shows things.
		$writable = Store::writable();
		$cfg = Settings::all();
		$byId = Roles::byId($roles);
		$rec = $this->rec();
		$state = $this->state($rec);
		$live = $state->clients();
		$registry = Registry::all();
		$changes = Store::read('changes.json', []);
		$maint = Lifecycle::maintenances(array_keys($live));

		$nows = [];
		$macros = [];
		foreach ($live as $name => $c) {
			$nows[$name] = $rec->current($name);
			$macros[$name] = $c['macros'];
			// First sight of a client the register does not know yet: remember it as it is.
			//
			// A GET that writes, which is worth being plain about. It is kept because the record
			// is how this page notices hosts deleted in Zabbix behind its back, and a client made
			// outside the page — or before the register existed — would otherwise never get a
			// first snapshot, so deletions under it would never be noticed. What gets written is
			// a reading of what Zabbix says right now: nothing from the request reaches it, which
			// is why no token is asked for and why a forged load could only write what an honest
			// one would.
			//
			// Wrapped because it must never cost the page. Store::write() throws when the folder
			// has gone away between the check above and the write — a mount lost mid-request —
			// and this loop runs for every client, so one failure used to take the whole Cluster
			// Management page down with a fatal rather than showing the clients it had.
			if (!isset($registry[$name]) && $writable && $this->superOnly()) {
				try {
					$this->register($name);
				}
				catch (Exception $e) {
					error_log('ep_clients: could not record client '.$name.' on first sight: '.$e->getMessage());
				}
			}
		}
		$registry = Registry::all();
		$exists = $this->existingHosts();
		$checks = SetupChecks::forClients($nows, $macros);

		// Open problems, by severity; when each client's storage fills; how much of what it bought it uses.
		$problems = [];
		$figures = [];
		$masterIds = array_filter(array_map(fn($c) => $c['masterid'], $live));
		foreach ($masterIds ? API::Item()->get(['output' => ['hostid', 'key_', 'lastvalue', 'lastclock', 'state'], 'hostids' => array_values($masterIds),
				'filter' => ['key_' => array_merge([Forecast::KEY], array_values(MasterTemplate::OVER))]]) : [] as $it) {
			$figures[$it['hostid']][$it['key_']] = $it['state'] == ITEM_STATE_NORMAL && (int) $it['lastclock'] > 0 ? (float) $it['lastvalue'] : null;
		}
		// No Problems column any more (board List-after): open problems are Zabbix's own page.

		// Last seen: when the cluster host last answered its Elasticsearch check, over HTTP or
		// through the jump host. One item.get for every cluster host.
		$clusterIds = array_values(array_filter(array_map(fn($n) => $n['cluster']['hostid'] ?? null, $nows)));
		$seen = [];
		foreach ($clusterIds ? (API::Item()->get(['output' => ['hostid', 'lastclock'], 'hostids' => $clusterIds,
				'filter' => ['key_' => [SetupChecks::ES_HEALTH_KEY, JumpTemplate::FAST]]]) ?: []) : [] as $it) {
			$seen[(string) $it['hostid']] = max($seen[(string) $it['hostid']] ?? 0, (int) $it['lastclock']);
		}
		// Where each cluster's credential comes from, and what Missing would list.
		$default = DefaultCredential::current();
		$defaultRecord = ClusterSecret::of(DefaultCredential::KEY);
		$tm = DefaultCredential::templateMacros(array_merge([], ...array_map(fn($n) => array_column($n['cluster']['parentTemplates'] ?? [], 'templateid'), array_values($nows))));
		// A cluster template under a name no setting knows, found by its health check (one lookup).
		$signature = Discovery::signatureTemplates($clusterIds)['templates'];

		$clients = [];
		foreach ($live as $name => $c) {
			$now = $nows[$name];
			$m = $c['macros'];
			$counts = [];
			foreach ($roles['families'] as $f) {
				$hosts = [];
				foreach ($now['machines'] as $h) {
					foreach ($h['_roles'] as $rid) {
						if (isset($byId[$rid]) && in_array($f['id'], Roles::familiesOf($byId[$rid]), true)) {
							$hosts[$h['hostid']] = true;
						}
					}
				}
				if ($hosts) {
					$counts[] = count($hosts).' '.$f['label'];
				}
			}
			$search = [$name, $m['{$ES.URL}'] ?? ''];
			foreach (array_merge(array_filter([$now['master'], $now['cluster'], $now['ulm']]), array_values($now['machines'])) as $h) {
				$search[] = $h['name'];
				if (!empty($h['_ip'])) {
					$search[] = $h['_ip'];
				}
			}
			$status = Lifecycle::statusOf($m);
			$bucket = $m['{$ULM.S3.BUCKET}'] ?? '';
			// The register knows hosts beyond what anyone but a Super admin may see in Zabbix: theirs alone.
			$diff = $this->superOnly() ? Registry::compare($name, $now, $exists) : ['removed' => [], 'changed' => []];
			$fullIn = $figures[$c['masterid']][Forecast::KEY] ?? null;
			$over = [];
			foreach (MasterTemplate::OVER as $basis => $key) {
				$pct = $figures[$c['masterid']][$key] ?? null;
				if ($pct !== null && $pct > 100) {
					$over[$basis] = $pct;
				}
			}
			// Per role: the servers that hold it (IP · server name), and what the plan asks for.
			$perRole = [];
			foreach (Roles::allRoles($roles) as $r) {
				$held = array_values(array_filter($now['machines'], fn($h) => in_array($r['id'], $h['_roles'], true)));
				$perRole[$r['id']] = ['family' => $r['family'], 'n' => count($held), 'requested' => (int) ((float) (Roles::macroValue($m, $r['id'], 'SERVER.COUNT.REQUESTED') ?? 0)),
					'hosts' => array_map(fn($h) => ($h['_ip'] ?? $h['host']).' · '.(($h['_server_name'] ?? '') !== '' ? $h['_server_name'] : _('server name unknown'))
						.(count($h['_roles']) > 1 ? ' ('._('shared').')' : ''), $held),
					'shared' => count(array_filter($held, fn($h) => count($h['_roles']) > 1))];
			}
			$cl = $now['cluster'];
			$beside = $cl !== null ? array_merge(...array_values(Reconciler::besideTemplates(array_column($cl['parentTemplates'] ?? [], 'host'), $signature[(string) $cl['hostid']] ?? null))) : [];
			$source = $cl !== null ? DefaultCredential::sourceOf($cl, $tm, $default['password']) : 'none';
			$missing = [];
			try {
				['client' => $parsed, 'errors' => $bad] = $this->spec()->fromForm(ClientState::plain($state->formFor($name)));
				$missing = Missing::forClient($now, $cl !== null ? $rec->macros((string) $cl['hostid']) : [], $bad ? [] : $rec->missingTemplates($parsed),
					$cl !== null ? ClusterSecret::of((string) $cl['host']) : null, ($m['{$EP.MONITORED.BY}'] ?? '') === 'jump',
					['source' => $source, 'default_record' => $defaultRecord], $cl !== null ? ($signature[(string) $cl['hostid']] ?? null) : null);
			}
			catch (Exception $e) {
				$missing = [['key' => 'read', 'text' => _s('Could not be read: %1$s', $e->getMessage()), 'fix' => '']];
			}
			$clients[] = [
				'cluster_host' => $cl['host'] ?? null,
				'roles' => $perRole,
				'beside' => $beside,
				'password' => ['override' => 'own', 'default' => 'default', 'template' => 'hidden', 'none' => 'not set'][$source],
				'last_seen' => $cl !== null ? ($seen[(string) $cl['hostid']] ?? 0) : null,
				'missing' => $missing,
				'name' => $name,
				'masterid' => $c['masterid'],
				'status' => $status,
				'maintenance' => $maint[$name] ?? null,
				'type' => ($m['{$EP.CLIENT.TYPE}'] ?? '') !== '' ? $m['{$EP.CLIENT.TYPE}'] : Settings::defaultType($cfg),
				'es_url' => $m['{$ES.URL}'] ?? '',
				'jump' => ($m['{$EP.MONITORED.BY}'] ?? '') === 'jump' ? ($m['{$EP.JUMP.HOST}'] ?? '') : '',
				'archive' => $bucket !== '' && $bucket !== ClientSpec::UNSET_BUCKET ? $bucket : '',
				'lead' => $m['{$EP.LEAD}'] ?? '',
				'dl' => $m['{$EP.DL}'] ?? '',
				'contract_end' => $m['{$EP.CONTRACT.END}'] ?? '',
				'machines' => $counts ? implode(' · ', $counts) : '—',
				'unassigned' => count($now['unassigned']),
				'shared' => count($now['shared']),
				'removed' => array_values($diff['removed']),
				'changed' => array_values($diff['changed']),
				'checks' => $checks[$name] ?? [],
				'problems' => $problems[$name] ?? [],
				'full_in' => $fullIn,
				'over' => $over,
				'change' => $changes[$name] ?? null,
				'search' => strtolower(implode(' ', $search)),
				'master_missing' => false
			];
		}
		// Recorded but its master host gone from Zabbix: still listed, with a way back.
		foreach ($this->superOnly() ? $registry : [] as $name => $r) {
			if (isset($live[$name])) {
				continue;
			}
			$f = $r['form'] ?? [];
			$clients[] = ['name' => $name, 'masterid' => null, 'status' => 'active', 'maintenance' => null,
				'type' => ($f['type'] ?? '') !== '' ? $f['type'] : Settings::defaultType($cfg),
				'es_url' => $f['es_url'] ?? '', 'jump' => '', 'archive' => '', 'lead' => $f['lead_name'] ?? '', 'dl' => $f['cluster_dl'] ?? '',
				'contract_end' => $f['contract_end'] ?? '', 'machines' => '—', 'unassigned' => 0, 'shared' => 0, 'removed' => array_values(array_diff_key($r['hosts'] ?? [], $exists)), 'changed' => array_values(array_intersect_key($r['hosts'] ?? [], $exists)),
				'checks' => [], 'problems' => [], 'full_in' => null, 'over' => [], 'change' => $changes[$name] ?? null, 'search' => strtolower($name), 'master_missing' => true,
				'cluster_host' => null, 'roles' => [], 'beside' => [], 'password' => 'not set', 'last_seen' => null,
				'missing' => [['key' => 'master', 'text' => _('The master host was removed from Zabbix.'), 'fix' => _('Re-create it, or forget the client.')]]];
		}
		usort($clients, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

		$response = new CControllerResponseData([
			'clients' => $clients,
			// Not adopted yet: from this session's Discover scan when there is one (a scan here
			// would walk Zabbix on every list load); null when none was made.
			'not_adopted' => ($scan = $this->cachedScan()) !== null
				? count(array_filter($scan['scan']['proposals'], fn($p) => $p['status'] !== 'client')) : null,
			'columns' => ListColumns::load($roles),
			'roles_config' => $roles,
			'template' => TemplateInstaller::status($roles),
			'store_ok' => $writable,
			'forecast' => Settings::forecast($cfg),
			'can_write' => $this->canWrite(),
			'write_note' => $this->writeNote(),
			'store_dir' => Store::dir(),
			'backups' => count(Store::listing('backups'))
		]);
		$response->setTitle(_('ElasticPro — Cluster Management'));
		$this->setResponse($response);
	}
}
