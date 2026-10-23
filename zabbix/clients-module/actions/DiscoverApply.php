<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use CControllerResponseData;
use Exception;
use Modules\EpClients\Lib\{Discovery, Store};

/**
 * The Discover page's form. do=preview draws the page again with the choices made (names fixed,
 * roles picked, hosts ticked) and their write lists — nothing is written. do=adopt carries out the
 * ticked proposals, each through Discovery::apply(): one backup first, then per client its
 * template moves, its cluster host into its group and Reconciler::apply(); a client that
 * something stops is skipped with the reason and the others go on. Afterwards the scan is run
 * again, so the page shows Zabbix as it now is.
 *
 * Only proposals and hosts from this session's scan can be named: the posted ids are matched
 * against it, and every proposal is planned again against Zabbix as it is at that moment.
 */
class DiscoverApply extends Base {

	protected function checkInput(): bool {
		return $this->validateInput(['token' => 'required|string', 'do' => 'required|in preview,adopt', 'sel' => 'array', 'host' => 'array',
			'role' => 'array', 'move' => 'array', 'rename' => 'array', 'es_url' => 'array', 'pw' => 'array']);
	}

	protected function doAction(): void {
		$data = ['scan' => null, 'token' => '', 'error' => null, 'results' => null, 'store_ok' => Store::writable(), 'store_dir' => Store::dir(),
			'can_write' => $this->canWrite(), 'roles' => $this->rolesFor()];
		$cached = $this->cachedScan();
		if ($cached === null || !hash_equals((string) $cached['token'], (string) $this->getInput('token'))) {
			$data['error'] = _('This scan is no longer current (another tab rescanned, or you signed in again). Nothing was changed — look at the proposals again.');
			$this->respond($data, $cached);
			return;
		}
		$in = [];
		foreach (['sel', 'host', 'role', 'move', 'rename', 'es_url', 'pw'] as $k) {
			$in[$k] = (array) $this->getInput($k, []);
		}
		$roles = $this->rolesFor();
		$refusals = fn(array $needs) => $this->writeRefusals($needs);
		$scan = $cached['scan'];

		if ($this->getInput('do') === 'preview') {
			$disc = $this->discovery();
			foreach ($scan['proposals'] as &$p) {
				if ($p['status'] === 'client') {
					continue;
				}
				$p['choices'] = Discovery::choicesFrom($p, $in, $roles);
				try {
					$p['plan'] = Discovery::compact($disc->plan($p, $p['choices'], $refusals));
				}
				catch (Exception $e) {
					$p['plan'] = Discovery::emptyPlan();
					$p['plan']['blockers'][] = $e->getMessage();
				}
				$p['choices']['password'] = '';
			}
			unset($p);
			$data['scan'] = $scan;
			$data['token'] = $cached['token'];
			$data['previewed'] = true;
			$this->respond($data, null);
			return;
		}

		// Adopt: the ticked proposals, against a fresh scan, each matched by id.
		$results = [];
		$backedUp = false;
		$fresh = $this->discovery()->scan($refusals);
		$byId = array_column($fresh['proposals'], null, 'id');
		foreach ($scan['proposals'] as $old) {
			if ((string) ($in['sel'][$old['id']] ?? '') !== '1') {
				continue;
			}
			$p = $byId[$old['id']] ?? null;
			if ($p === null) {
				$results[] = ['name' => $old['name'], 'done' => [], 'skipped' => [], 'problems' => [],
					'error' => _('Nothing was changed: this cluster is no longer proposed — rescan.')];
				continue;
			}
			$c = Discovery::choicesFrom($p, $in, $roles);
			$rec = $this->rec();
			$results[] = $this->discovery($rec)->apply($p, $c, $refusals,
				function () use (&$backedUp) {
					if (!$backedUp) {
						$this->backup('Discover existing hosts — adopt');
						$backedUp = true;
					}
				},
				fn(string $name) => $this->noteChange($name, 'discovered'),
				fn(array $client) => $this->importer()->verify($client),
				$this->user());
		}
		if (!$results) {
			$data['error'] = _('Nothing is ticked, so nothing was changed.');
		}
		$data['results'] = $results;
		$this->respond($data, $this->cacheScan($this->discovery()->scan($refusals)));
	}

	private function respond(array $data, ?array $cached): void {
		if ($cached !== null) {
			$data['scan'] = $cached['scan'];
			$data['token'] = $cached['token'];
		}
		$response = new CControllerResponseData($data);
		$response->setTitle(_('Discover existing hosts'));
		$this->setResponse($response);
	}
}
