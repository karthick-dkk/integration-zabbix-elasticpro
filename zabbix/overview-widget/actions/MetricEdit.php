<?php declare(strict_types = 0);

namespace Modules\EpOverview\Actions;

foreach (['Store', 'Settings', 'Roles', 'ClientData'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}
require_once __DIR__.'/../includes/Metrics.php';

use API,
	CController,
	CControllerResponseData;
use Modules\EpOverview\Includes\Metrics;
use Modules\EpOverview\Lib\{ClientData, Roles, Settings};

/**
 * The metric editor: the dialog Add metric and Edit open over the widget form, as Top hosts
 * opens its column editor. Opened, it draws the metric; Update sends it back here, where
 * Metrics::clean — the rule the widget's Metrics field applies again on Apply — either returns
 * the metric as it will be stored or says what is wrong. "Check" asks how many client servers
 * have the item key.
 */
class MetricEdit extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		// The values themselves are checked by Metrics::clean.
		$fields = [
			'uid' => 'string', 'name' => 'string', 'source' => 'string', 'builtin' => 'string', 'key' => 'string', 'pattern' => 'string',
			'roles' => 'array', 'combine' => 'string', 'units' => 'string', 'decimals' => 'string', 'short' => 'string',
			'display' => 'string', 'min' => 'string', 'max' => 'string', 'trend_avg' => 'string', 'trend_min' => 'string', 'trend_max' => 'string',
			'window' => 'string', 'worse' => 'string', 'use_settings' => 'string', 'warn' => 'string', 'high' => 'string',
			'thresholds' => 'array', 'nodata' => 'string', 'stale' => 'string', 'click' => 'string',
			'trend_days' => 'string', 'stale_after' => 'string',
			'edit' => 'in 1', 'update' => 'in 1', 'coverage' => 'in 1', 'templateid' => 'string'
		];
		$ret = $this->validateInput($fields);
		if (!$ret) {
			$this->setResponse((new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => array_column(get_and_clear_messages(), 'message')]
			], JSON_THROW_ON_ERROR)]))->disableView());
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$roles = Roles::load();
		$catalogue = Metrics::catalogue($roles);
		$roleIds = array_column(Roles::allRoles($roles), 'id');
		$input = $this->getInputAll();

		if ($this->hasInput('coverage')) {
			$this->json($this->coverage(trim((string) ($input['key'] ?? '')), ($input['pattern'] ?? '0') === '1', (array) ($input['roles'] ?? []), $roles));
			return;
		}

		if ($this->hasInput('update')) {
			$edit = $this->hasInput('edit');
			unset($input['update'], $input['edit'], $input['templateid'], $input['trend_days'], $input['stale_after']);
			[$metric, $errors] = Metrics::clean($input, $catalogue, $roleIds);
			if ($errors) {
				$this->json(['error' => ['title' => _('Cannot update metric'),
					'messages' => Metrics::named($errors, is_scalar($input['name'] ?? null) ? (string) $input['name'] : '')]]);
				return;
			}
			$this->json($metric + ($edit ? ['edit' => 1] : []));
			return;
		}

		$metric = array_intersect_key($input, Metrics::DEFAULT) + Metrics::DEFAULT;
		$metric['roles'] = array_values(array_filter((array) $metric['roles'], 'is_scalar'));
		$metric['thresholds'] = array_values(array_filter((array) $metric['thresholds'], 'is_array'));
		$cfg = Settings::all();
		[$keys, $servers] = $this->keys($roles);
		$this->setResponse(new CControllerResponseData([
			'action' => $this->getAction(),
			'edit' => $this->hasInput('edit'),
			'metric' => $metric,
			'catalogue' => $catalogue,
			'roles' => array_map(fn($r) => ['id' => $r['id'], 'label' => $r['label'], 'family' => $r['familyLabel']], Roles::allRoles($roles)),
			'combine' => Metrics::combineLabels(),
			'usage' => Settings::usage($cfg),
			'decimals' => ['%' => Settings::decimals('%', $cfg), '' => Settings::decimals('', $cfg), 'B' => Settings::decimals('B', $cfg)],
			'trend_days' => (int) ($input['trend_days'] ?? 7) ?: 7,
			'stale_after' => (int) ($input['stale_after'] ?? 10) ?: 10,
			'keys' => $keys,
			'coverage' => (int) $metric['source'] === Metrics::SOURCE_ITEM && $metric['key'] !== ''
				? $this->coverage((string) $metric['key'], (string) $metric['pattern'] === '1', $metric['roles'], $roles, $servers) : null,
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	private function json(array $out): void {
		$this->setResponse((new CControllerResponseData(['main_block' => json_encode($out, JSON_THROW_ON_ERROR)]))->disableView());
	}

	/** Every client server this user can see, with its roles. */
	private function servers(array $roles): array {
		[, $clients] = ClientData::clients(Settings::all(), []);
		$out = [];
		foreach (ClientData::servers($roles, $clients, false) as $list) {
			foreach ($list as $s) {
				$out[$s['hostid']] = $s['roles'];
			}
		}
		return $out;
	}

	/** Numeric item keys on the client servers, for the key box to suggest. */
	private function keys(array $roles): array {
		$servers = $this->servers($roles);
		if (!$servers) {
			return [[], $servers];
		}
		$keys = array_unique(array_column(API::Item()->get(['output' => ['key_'], 'hostids' => array_keys($servers),
			'filter' => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]], 'limit' => 20000]) ?: [], 'key_'));
		sort($keys, SORT_NATURAL);
		return [array_slice($keys, 0, 2000), $servers];
	}

	/** How many client servers (in the chosen roles) have an item with this key: "found on 41 of 52". */
	private function coverage(string $key, bool $pattern, array $want, array $roles, ?array $servers = null): array {
		if (Metrics::keyError($key, $pattern) !== null) {
			return ['found' => null, 'total' => null];
		}
		$servers = array_filter($servers ?? $this->servers($roles), fn($held) => !$want || array_intersect($want, $held));
		if (!$servers) {
			return ['found' => 0, 'total' => 0];
		}
		$options = ['output' => ['hostid', 'key_'], 'hostids' => array_keys($servers)];
		// A pattern starts with a literal prefix (Metrics::keyError): anchored, and limited.
		$options += $pattern
			? ['search' => ['key_' => $key], 'searchWildcardsEnabled' => true, 'startSearch' => true,
				'limit' => min(10000, count($servers) * Metrics::PATTERN_PER_HOST)]
			: ['filter' => ['key_' => $key]];
		$hosts = [];
		foreach (API::Item()->get($options) ?: [] as $it) {
			if (Metrics::keyMatches($it['key_'], $key, $pattern)) {
				$hosts[$it['hostid']] = true;
			}
		}
		return ['found' => count($hosts), 'total' => count($servers)];
	}
}
