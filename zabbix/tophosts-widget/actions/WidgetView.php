<?php declare(strict_types = 0);

namespace Modules\EpTophosts\Actions;

foreach (['Store', 'Settings', 'Roles', 'ClientData'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}
require_once __DIR__.'/../includes/WidgetForm.php';

use CControllerDashboardWidgetView,
	CControllerResponseData;
use Modules\EpTophosts\Includes\WidgetForm;
use Modules\EpTophosts\Lib\{ClientData, Roles, Settings};

/**
 * Top hosts: the busiest client servers, by CPU, memory or fullest disk. The clients and their
 * servers are ClientData's — the same reading Client resources expands into — and each server's
 * colour is decided with its own client's thresholds, so a host is the same colour here as in
 * Client resources. Every server goes to the page with all three figures, so the ranking can be
 * switched on the widget without asking Zabbix again.
 */
class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$cfg = Settings::all();
		$rank = WidgetForm::RANKS[(int) ($this->fields_values['rank'] ?? 0)] ?? 'cpu';
		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'rank' => $rank,
			'count' => max(1, min(100, (int) ($this->fields_values['count'] ?? 10))),
			'scope' => '',
			'servers' => [],
			'error' => null,
			'user' => ['debug_mode' => $this->getDebugMode()]
		];
		[, $clients, $error] = ClientData::clients($cfg, $this->fields_values['groupids'] ?? []);
		if ($error !== null) {
			$data['error'] = $error;
			$this->setResponse(new CControllerResponseData($data));
			return;
		}

		// The client and the client type the settings name, if any.
		$want_client = trim((string) ($this->fields_values['client'] ?? ''));
		$want_type = null;
		$type_key = (int) ($this->fields_values['client_type'] ?? 0);
		if ($type_key !== 0) {
			foreach (Settings::types($cfg)['all'] as $t) {
				if (WidgetForm::typeKey($t) === $type_key) {
					$want_type = $t;
				}
			}
		}
		$clients = array_filter($clients, fn($c) => ($want_client === '' || strcasecmp($c['name'], $want_client) === 0)
			&& ($want_type === null || $c['type'] === $want_type));
		$data['scope'] = implode(' · ', array_filter([$want_client, $want_type]));
		if ($want_client !== '' && !$clients) {
			$data['error'] = _s('No client named "%1$s" here. Check the name in ElasticPro → Clients, or empty the Client setting.', $want_client);
			$this->setResponse(new CControllerResponseData($data));
			return;
		}

		$byName = array_column($clients, null, 'name');
		foreach (ClientData::servers(Roles::load(), $clients) as $client => $list) {
			$c = $byName[$client] ?? null;
			if ($c === null) {
				continue;
			}
			['warn' => $warn, 'high' => $high] = Settings::usageFor($c['macros'], $this->fields_values['usage_warn'] ?? Settings::INHERIT,
				$this->fields_values['usage_high'] ?? Settings::INHERIT, $cfg);
			foreach ($list as $s) {
				$s = ClientData::classify($s, $warn, $high);
				$data['servers'][] = ['host' => $s['host'], 'hostid' => $s['hostid'], 'client' => $client, 'type' => $c['type'], 'role' => $s['role'],
					'status' => $s['status'], 'cpu' => $s['cpu_used'], 'mem' => $s['mem_used'], 'disk' => $s['disk_used'],
					'classes' => $s['classes'], 'url' => (new \CUrl('zabbix.php'))->setArgument('action', 'latest.view')->setArgument('hostids', [$s['hostid']])->getUrl()];
			}
		}
		$this->setResponse(new CControllerResponseData($data));
	}
}
