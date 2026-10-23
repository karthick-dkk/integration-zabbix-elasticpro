<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use API;
use CControllerResponseData;
use Modules\EpClients\Lib\{ClusterSecret, DefaultCredential, Discovery, Forecast, History, JumpTemplate, Settings, SetupChecks, Lifecycle, Missing, Reconciler, Registry, Roles};

/** The form: empty for a new client, filled for one that exists or a cluster host being taken on. */
class ClientEdit extends Base {

	protected const READ_ONLY_OK = true;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['client' => 'string', 'clone' => 'string']);
	}

	protected function doAction(): void {
		$from = trim((string) $this->getInput('clone', ''));
		if ($from !== '') {
			$source = $this->state()->formFor($from);
			if (($source['_now']['master'] ?? null) === null) {
				$this->toList(_('Nothing to clone'), [], true, _s('Client "%1$s" was not found.', $from));
				return;
			}
			$this->setResponse(self::page($this, $this->spec()->cloneOf($source), 'add', [], false, [], $from));
			return;
		}
		$name = trim((string) $this->getInput('client', ''));
		$form = $name === '' ? $this->spec()->newForm(DefaultCredential::current()['password']) : $this->state()->formFor($name);
		$now = $form['_now'] ?? null;
		$this->setResponse(self::page($this, $form, $now === null || $now['master'] === null ? 'add' : 'edit', [],
			$now !== null && $now['master'] === null && ($now['cluster'] !== null || $now['machines'])));
	}

	public static function page(Base $c, array $form, string $mode, array $errors, bool $adopting = false, array $done = [], string $clonedFrom = ''): CControllerResponseData {
		$now = $form['_now'] ?? null;
		$roles = $c->rolesFor();
		$byId = Roles::byId($roles);
		$names = [];
		$tech = [];
		$existing = [];
		if ($now !== null) {
			foreach (array_merge(array_filter([$now['master'], $now['cluster'], $now['ulm']]), array_values($now['machines'])) as $h) {
				$existing[] = ['name' => $h['name'], 'host' => $h['host'], 'server_name' => $h['_server_name'] ?? '', 'managed' => Reconciler::isManaged($h), 'ip' => $h['_ip'] ?? null,
					'role' => implode(' + ', array_map(fn($rid) => $byId[$rid]['label'] ?? $rid, $h['_roles'] ?? []))];
				if (($h['_ip'] ?? null) !== null) {
					$names[$h['_ip']] = $h['name'];
					$tech[$h['_ip']] = $h['host'];
				}
			}
		}
		$status = 'active';
		$maintenance = null;
		$removed = [];
		$changed = [];
		if ($now !== null && $now['master'] !== null) {
			$status = Lifecycle::statusOf((new Reconciler($c->specFor()))->macros($now['master']['hostid']));
			$maintenance = Lifecycle::maintenances([$form['name']])[$form['name']] ?? null;
			// The register knows hosts beyond what anyone but a Super admin may see: theirs alone.
			$diff = $c->superOnly() ? Registry::compare($form['name'], $now, $c->existingHosts()) : ['removed' => [], 'changed' => []];
			$removed = array_values($diff['removed']);
			$changed = array_values($diff['changed']);
		}
		// The password is never shown: whether one is kept, and who set it when (ClusterSecret).
		$cluster = $now['cluster'] ?? null;
		$password = ['stored' => Reconciler::hasStoredPassword($cluster), 'record' => $cluster !== null ? ClusterSecret::of((string) $cluster['host']) : null];
		$default = DefaultCredential::current();
		// What the cluster host carries beside the current template (Reconciler::besideTemplates).
		$signature = $cluster !== null ? (Discovery::signatureTemplates([(string) $cluster['hostid']])['templates'][(string) $cluster['hostid']] ?? null) : null;
		$missing = [];
		if ($now !== null && $mode === 'edit') {
			$rec = new Reconciler($c->specFor());
			['client' => $parsed, 'errors' => $bad] = $c->specFor()->fromForm(array_filter($form, fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY));
			$credential = [];
			if ($cluster !== null) {
				$tm = DefaultCredential::templateMacros(array_column($cluster['parentTemplates'] ?? [], 'templateid'));
				$credential = ['source' => DefaultCredential::sourceOf($cluster, $tm, $default['password']), 'default_record' => ClusterSecret::of(DefaultCredential::KEY),
					'hidden_by' => array_values(array_map(fn($t) => $t['host'], array_filter($cluster['parentTemplates'] ?? [],
						fn($t) => in_array(DefaultCredential::PASSWORD_MACRO, $tm[(string) $t['templateid']] ?? [], true))))];
			}
			$missing = Missing::forClient($now, $cluster !== null ? $rec->macros((string) $cluster['hostid']) : [],
				$bad ? [] : $rec->missingTemplates($parsed), $password['record'], ($form['monitored_by'] ?? '') === 'jump', $credential, $signature);
		}
		// The Overview tab (board Client-page.dc.html): servers by role, the cluster and its
		// templates, when it last answered, when its storage fills.
		$byRole = [];
		foreach ($now['machines'] ?? [] as $h) {
			foreach ($h['_roles'] ?? [] as $rid) {
				$byRole[$rid][] = ['ip' => $h['_ip'] ?? '', 'name' => $h['_server_name'] ?? '', 'host' => $h['host'], 'confirm' => $h['_confirm'] ?? ''];
			}
		}
		$lastSeen = null;
		$fullIn = null;
		if ($cluster !== null) {
			$lastSeen = 0;
			foreach (API::Item()->get(['output' => ['lastclock'], 'hostids' => [$cluster['hostid']],
					'filter' => ['key_' => [SetupChecks::ES_HEALTH_KEY, JumpTemplate::FAST]]]) ?: [] as $it) {
				$lastSeen = max($lastSeen, (int) $it['lastclock']);
			}
		}
		if (($now['master'] ?? null) !== null) {
			foreach (API::Item()->get(['output' => ['lastvalue', 'lastclock', 'state'], 'hostids' => [$now['master']['hostid']],
					'filter' => ['key_' => [Forecast::KEY]]]) ?: [] as $it) {
				$fullIn = $it['state'] == ITEM_STATE_NORMAL && (int) $it['lastclock'] > 0 ? (float) $it['lastvalue'] : null;
			}
		}
		$proxies = array_column(API::Proxy()->get(['output' => ['name'], 'sortfield' => 'name']) ?: [], 'name');
		$groups = array_column(API::ProxyGroup()->get(['output' => ['name'], 'sortfield' => 'name']) ?: [], 'name');
		$response = new CControllerResponseData([
			'form' => array_filter($form, fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY),
			'roles' => $roles,
			'mode' => $mode,
			'adopting' => $adopting,
			'existing' => $existing,
			'host_names' => $names,
			'tech_names' => $tech,
			'unassigned' => $form['_unassigned'] ?? [],
			'shared' => $form['_shared'] ?? [],
			'deletes' => $form['_deletes'] ?? null,
			'current' => (array) json_decode((string) ($form['_current_servers'] ?? ($mode === 'edit' ? $form['servers'] : '[]')), true),
			'merge' => array_filter(explode(',', (string) ($form['merge'] ?? ''))),
			'has_cluster' => $now !== null && $now['cluster'] !== null,
			'password' => $password,
			'by_role' => $byRole,
			'cluster_info' => $cluster !== null ? ['host' => $cluster['host'], 'hostid' => $cluster['hostid'],
				'templates' => array_column($cluster['parentTemplates'] ?? [], 'host'),
				'beside' => Reconciler::besideTemplates(array_column($cluster['parentTemplates'] ?? [], 'host'), $signature)] : null,
			'master_host' => $now['master']['host'] ?? null,
			'last_seen' => $lastSeen,
			'full_in' => $fullIn,
			'forecast' => Settings::forecast(Settings::all()),
			'default_credential' => ['set' => $default['password'], 'user' => $default['user'], 'record' => ClusterSecret::of(DefaultCredential::KEY)],
			'missing' => $missing,
			'proxies' => $proxies,
			'status' => $status,
			'masterid' => $now['master']['hostid'] ?? null,
			'maintenance' => $maintenance,
			'removed' => $removed,
			'changed' => $changed,
			'history' => $mode === 'edit' ? History::of($form['name'], 30) : [],
			'proxy_groups' => $groups,
			'errors' => $errors,
			'cloned_from' => $clonedFrom,
			'can_write' => $c->canWrite(),
			'write_note' => $c->writeNote(),
			'done' => $done
		]);
		$response->setTitle($mode === 'add' ? _('Add client') : _s('Client %1$s', $form['name']));
		return $response;
	}
}
