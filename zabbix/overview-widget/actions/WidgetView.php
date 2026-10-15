<?php declare(strict_types = 0);

namespace Modules\EpOverview\Actions;

foreach (['Store', 'Settings', 'Roles', 'ClientData'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}
require_once __DIR__.'/../includes/WidgetForm.php';

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData;
use Modules\EpOverview\Includes\WidgetForm;
use Modules\EpOverview\Lib\{ClientData, Roles, Settings};

/**
 * Client overview: per client, devices, ES storage, every family's servers, CPU and memory, and
 * its servers' status and use. The clients and their servers are ClientData's — the reading
 * Client resources and Top hosts use — and every colour class is decided here with the client's
 * thresholds, so a figure is the same colour in every ElasticPro report.
 */
class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$cfg = Settings::all();
		$roles = Roles::load();
		$f = $this->fields_values;
		$familyKey = (int) ($f['family'] ?? 0);
		$family = 'es';
		foreach ($roles['families'] as $fam) {
			if ($familyKey !== 0 && WidgetForm::key($fam['id']) === $familyKey) {
				$family = $fam['id'];
			}
		}
		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'error' => null,
			'overview' => null,
			'user' => ['debug_mode' => $this->getDebugMode()]
		];
		[$masters, $clients, $error] = ClientData::clients($cfg, $f['groupids'] ?? []);
		if ($error !== null) {
			$data['error'] = $error;
			$this->setResponse(new CControllerResponseData($data));
			return;
		}
		$want_client = trim((string) ($f['client'] ?? ''));
		$want_type = null;
		if ((int) ($f['client_type'] ?? 0) !== 0) {
			foreach (Settings::types($cfg)['all'] as $t) {
				if (WidgetForm::key($t) === (int) $f['client_type']) {
					$want_type = $t;
				}
			}
		}
		$clients = array_filter($clients, fn($c) => ($want_client === '' || strcasecmp($c['name'], $want_client) === 0)
			&& ($want_type === null || $c['type'] === $want_type));
		if ($want_client !== '' && !$clients) {
			$data['error'] = _s('No client named "%1$s" here. Check the name in ElasticPro → Clients, or empty the Client setting.', $want_client);
			$this->setResponse(new CControllerResponseData($data));
			return;
		}

		// The master hosts' figures the panels read: storage, devices, every family's servers, CPU, memory.
		$keys = ['ep.es.storage.purchased', 'ep.es.storage.allocated', 'ep.es.storage.used', 'ep.es.storage.usage', 'ep.devices.purchased', 'ep.devices.seen'];
		foreach ($roles['families'] as $fam) {
			foreach (['servers', 'cpu', 'mem'] as $res) {
				foreach (['requested', 'allocated', 'usage'] as $kind) {
					$keys[] = 'ep.'.$fam['id'].'.'.$res.'.'.$kind;
				}
			}
		}
		$values = [];
		if ($clients) {
			foreach (API::Item()->get(['output' => ['hostid', 'key_', 'lastvalue', 'lastclock', 'state'], 'hostids' => array_keys($clients),
					'filter' => ['key_' => $keys]]) as $it) {
				$values[$it['hostid']][$it['key_']] = ClientData::known($it) ? (float) $it['lastvalue'] : null;
			}
		}
		$servers = ClientData::servers($roles, $clients);
		$out = [];
		foreach ($clients as $hostid => $client) {
			['warn' => $warn, 'high' => $high] = Settings::usageFor($client['macros'], $f['usage_warn'] ?? Settings::INHERIT,
				$f['usage_high'] ?? Settings::INHERIT, $cfg);
			$srv = array_map(fn($s) => ClientData::classify($s, $warn, $high), $servers[$client['name']] ?? []);
			$out[] = $this->client($roles, $client, $values[$hostid] ?? [], $srv, $warn, $high);
		}
		usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
		$data['overview'] = [
			'families' => array_map(fn($fam) => ['id' => $fam['id'], 'label' => $fam['label']], $roles['families']),
			'clients' => $out,
			'settings' => [
				'client' => $want_client !== '' ? (array_values($clients)[0]['name'] ?? '') : '',
				'family' => $family,
				'panels' => ['summary' => (int) ($f['show_summary'] ?? 1) === 1, 'used' => (int) ($f['show_used'] ?? 1) === 1,
					'roles' => (int) ($f['show_roles'] ?? 1) === 1],
				'types' => Settings::types($cfg)['all']
			]
		];
		$this->setResponse(new CControllerResponseData($data));
	}

	/** One client: raw figures with the colour class each was given. */
	private function client(array $roles, array $client, array $v, array $srv, float $warn, float $high): array {
		$req = fn($k) => isset($v[$k]) && $v[$k] !== null && $v[$k] > 0 ? $v[$k] : null;
		$num = fn($k) => $v[$k] ?? null;
		$cls = fn($x) => ClientData::usageClass($x, $warn, $high);
		$fam = [];
		foreach ($roles['families'] as $f) {
			$k = fn($res, $kind) => 'ep.'.$f['id'].'.'.$res.'.'.$kind;
			$fam[$f['id']] = [
				'servers' => ['requested' => $req($k('servers', 'requested')), 'allocated' => $num($k('servers', 'allocated'))],
				'cpu' => ['requested' => $req($k('cpu', 'requested')), 'allocated' => $num($k('cpu', 'allocated')), 'usage' => $num($k('cpu', 'usage')),
					'class' => $cls($num($k('cpu', 'usage')))],
				'mem' => ['requested' => $req($k('mem', 'requested')), 'allocated' => $num($k('mem', 'allocated')), 'usage' => $num($k('mem', 'usage')),
					'class' => $cls($num($k('mem', 'usage')))]
			];
		}
		return [
			'name' => $client['name'], 'type' => $client['type'], 'badge' => $client['badge'], 'warn' => $warn, 'high' => $high,
			'devices' => ['purchased' => $req('ep.devices.purchased'), 'seen' => $num('ep.devices.seen')],
			'storage' => ['purchased' => $req('ep.es.storage.purchased'), 'allocated' => $num('ep.es.storage.allocated'),
				'used' => $num('ep.es.storage.used'), 'usage' => $num('ep.es.storage.usage'), 'class' => $cls($num('ep.es.storage.usage'))],
			'families' => $fam,
			'servers' => array_map(fn($s) => ['host' => $s['host'], 'hostid' => $s['hostid'], 'roles' => array_values(array_filter(array_map('trim', explode('+', $s['role'])))),
				'status' => $s['status'], 'cpu' => $s['cpu_used'], 'mem' => $s['mem_used'], 'classes' => $s['classes']], $srv)
		];
	}
}
