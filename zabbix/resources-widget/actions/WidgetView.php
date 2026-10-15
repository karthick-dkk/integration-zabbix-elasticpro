<?php declare(strict_types = 0);

namespace Modules\EpResources\Actions;

foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'ClientData'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CCsrfTokenHelper;
use Modules\EpResources\Lib\{Bars, ClientData, ColumnSettings, Roles, Settings, Store};

/**
 * Client resources: one row per client master host; per family (or per role, where the Columns
 * dialog splits a family) servers, CPU, memory and disk — requested, allocated, used. Each client
 * expands into its servers: role, host, IP, whether it answers, and its CPU, memory and disk.
 *
 * The columns come from the roles (the Clients page's Roles), so a role added there appears here
 * without a change to this widget. A figure Zabbix does not have is "—" and an empty cell in the
 * export; a request of 0 is one nobody entered, shown as "not set".
 */
class WidgetView extends CControllerDashboardWidgetView {

	private const RES = ['servers' => 'servers', 'cpu' => 'CPU (cores)', 'mem' => 'memory', 'disk' => 'disk'];

	/** The site's settings, read once: cell() asks for decimals on every figure drawn. */
	private array $cfg = [];

	protected function doAction(): void {
		$roles = Roles::load();
		$this->cfg = Settings::all();
		$settings = ColumnSettings::load('resources');
		$all = $this->columns($roles, $settings['split']);
		$columns = ColumnSettings::apply($all, $settings);

		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'columns' => $columns,
			'bars' => Bars::styleOf(Bars::isInherit($this->fields_values['bars'] ?? Bars::INHERIT)
				? Settings::bars($this->cfg)
				: $this->fields_values['bars']),
			'rows' => [],
			'export' => null,
			'error' => null,
			'meta' => [
				'title' => 'Client resources',
				'report' => 'resources',
				'action' => 'widget.ep_resources.columns',
				'token' => CCsrfTokenHelper::get('widget.ep_resources.columns'),
				'canEdit' => $this->getUserType() == USER_TYPE_SUPER_ADMIN,
				'columns' => array_map(fn($c) => ['id' => $c['id'], 'label' => $c['label'], 'default' => $c['default'], 'hidden' => $c['hidden'],
					'section' => $c['group']], ColumnSettings::apply($all, $settings, true)),
				'families' => array_map(fn($f) => ['id' => $f['id'], 'label' => $f['label'], 'split' => in_array($f['id'], $settings['split'], true)],
					$roles['families']),
				'types' => Settings::types($this->cfg)['all'],
				// So the Columns dialog can say why Save will fail, instead of failing.
				'storeOk' => Store::writable()
			],
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		// The clients, and below their servers, read by the one shared reading Top hosts uses too.
		[$masters, $clients, $error] = ClientData::clients($this->cfg, $this->fields_values['groupids'] ?: []);
		if ($error !== null) {
			$data['error'] = $error;
			$this->setResponse(new CControllerResponseData($data));
			return;
		}

		$keys = [];
		foreach ($all as $c) {
			foreach (['key', 'vs', 'usageKey'] as $k) {
				if (isset($c[$k])) {
					$keys[$c[$k]] = true;
				}
			}
		}
		$values = [];
		$units = [];
		if ($masters) {
			foreach (API::Item()->get(['output' => ['hostid', 'key_', 'lastvalue', 'lastclock', 'units', 'state'],
					'hostids' => array_keys($masters), 'filter' => ['key_' => array_keys($keys)]]) as $it) {
				$units[$it['key_']] = $it['units'];
				$values[$it['hostid']][$it['key_']] = ClientData::known($it) ? (float) $it['lastvalue'] : null;
			}
		}

		$servers = ClientData::servers($roles, $clients);

		$headers = array_map(fn($c) => ($c['group'] === 'Client' ? '' : $c['group'].' ').$c['label'].$this->suffix(isset($c['key']) ? ($units[$c['key']] ?? '') : ''), $columns);
		$exportRows = [];
		$exportTypes = [];
		$serverRows = [];
		foreach ($masters as $hostid => $master) {
			$client = $clients[$hostid];
			$m = $client['macros'];
			['warn' => $warn, 'high' => $high] = Settings::usageFor($m, $this->fields_values['usage_warn'] ?? Settings::INHERIT,
				$this->fields_values['usage_high'] ?? Settings::INHERIT, $this->cfg);
			$cells = [];
			$export = [];
			foreach ($columns as $c) {
				[$cell, $value] = $this->cell($c, $client, $values[$hostid] ?? [], $units, $warn, $high);
				$cells[] = $cell;
				$export[] = $value;
			}
			$srv = $servers[$client['name']] ?? [];
			foreach ($srv as &$s) {
				$s = ClientData::classify($s, $warn, $high);
				$div = Settings::sizeDivisor($this->cfg);
				$serverRows[$client['name']][] = [$client['name'], $client['type'], $s['role'], implode(', ', $s['services'] ?? []), $s['host'], $s['ip'], $s['status'],
					$s['cpu_cores'], self::r1($s['cpu_used']), $s['mem_total'] !== null ? round($s['mem_total'] / $div, 2) : null, self::r1($s['mem_used']),
					$s['mount'], $s['disk_total'] !== null ? round($s['disk_total'] / $div, 2) : null];
			}
			unset($s);
			$data['rows'][] = ['client' => $client['name'], 'type' => $client['type'], 'cells' => $cells, 'servers' => $srv];
			$exportRows[] = $export;
			$exportTypes[] = $client['type'];
		}
		$order = array_keys($data['rows']);
		usort($order, fn($a, $b) => strnatcasecmp($data['rows'][$a]['client'], $data['rows'][$b]['client']));
		$data['rows'] = array_map(fn($i) => $data['rows'][$i], $order);
		$servers_out = [];
		foreach ($data['rows'] as $row) {
			$servers_out = array_merge($servers_out, $serverRows[$row['client']] ?? []);
		}
		$data['export'] = [
			'headers' => $headers,
			'rows' => array_map(fn($i) => $exportRows[$i], $order),
			'types' => array_map(fn($i) => $exportTypes[$i], $order),
			'servers' => ['headers' => ['Client', 'Type', 'Roles', 'Services', 'Host', 'IP', 'Status', 'CPU cores', 'CPU used (%)',
				'Memory ('.Settings::sizeLabel($this->cfg).')', 'Memory used (%)', 'Disks', 'Disk ('.Settings::sizeLabel($this->cfg).')'],
				'rows' => $servers_out, 'types' => array_column($servers_out, 1)]
		];
		$this->setResponse(new CControllerResponseData($data));
	}

	/** Every column the report can show, before the settings: families, or roles where split. */
	private function columns(array $roles, array $split): array {
		$out = [
			['id' => 'client.name', 'group' => 'Client', 'label' => 'Cluster Name', 'source' => 'client'],
			['id' => 'client.type', 'group' => 'Client', 'label' => 'Type', 'source' => 'type'],
			['id' => 'client.url', 'group' => 'Client', 'label' => 'ES URL', 'source' => 'macro', 'macro' => '{$ES.URL}'],
			['id' => 'delay.late', 'group' => 'Log delay', 'label' => 'Devices late', 'key' => 'ep.delay.late'],
			['id' => 'storage.purchased', 'group' => 'ES storage', 'label' => 'Purchased', 'key' => 'ep.es.storage.purchased', 'kind' => 'requested'],
			['id' => 'storage.allocated', 'group' => 'ES storage', 'label' => 'Allocated', 'key' => 'ep.es.storage.allocated', 'vs' => 'ep.es.storage.purchased'],
			['id' => 'storage.used', 'group' => 'ES storage', 'label' => 'Used', 'key' => 'ep.es.storage.used'],
			['id' => 'storage.usage', 'group' => 'ES storage', 'label' => 'Used %', 'key' => 'ep.es.storage.usage', 'usage' => true],
			['id' => 'devices.purchased', 'group' => 'Devices', 'label' => 'Purchased', 'key' => 'ep.devices.purchased', 'kind' => 'requested'],
			['id' => 'devices.seen', 'group' => 'Devices', 'label' => 'Seen', 'key' => 'ep.devices.seen', 'usageKey' => 'ep.devices.usage']
		];
		foreach ($roles['families'] as $f) {
			$units = in_array($f['id'], $split, true)
				? array_map(fn($r) => ['id' => 'r.'.$r['id'], 'label' => $r['label'], 'key' => fn($res, $kind) => 'ep.role.'.$res.'.'.$kind.'['.$r['id'].']'], $f['roles'])
				: [['id' => 'f.'.$f['id'], 'label' => $f['label'], 'key' => fn($res, $kind) => 'ep.'.$f['id'].'.'.$res.'.'.$kind]];
			foreach ($units as $u) {
				foreach ($f['order'] ?? Roles::RESOURCES as $res) {
					$g = $u['label'].' '.self::RES[$res];
					$k = $u['key'];
					$out[] = ['id' => $u['id'].'.'.$res.'.requested', 'group' => $g, 'label' => 'Requested', 'key' => $k($res, 'requested'), 'kind' => 'requested'];
					$out[] = ['id' => $u['id'].'.'.$res.'.allocated', 'group' => $g, 'label' => 'Allocated', 'key' => $k($res, 'allocated'), 'vs' => $k($res, 'requested')];
					if ($res === 'cpu' || $res === 'mem') {
						$out[] = ['id' => $u['id'].'.'.$res.'.usage', 'group' => $g, 'label' => 'Used', 'key' => $k($res, 'usage'), 'usage' => true];
					}
					if ($res === 'disk') {
						$out[] = ['id' => $u['id'].'.'.$res.'.used', 'group' => $g, 'label' => 'Used', 'key' => $k($res, 'used'), 'usageKey' => $k($res, 'usage')];
					}
				}
			}
		}
		return $out;
	}

	/** One cell: [what the table shows, what the export gets]. */
	private function cell(array $c, array $client, array $values, array $units, float $warn, float $high): array {
		$cell = ['text' => '—', 'sub' => '', 'class' => '', 'hint' => '', 'bar' => null];
		switch ($c['source'] ?? 'item') {
			case 'client':
				return [['text' => $client['name'], 'sub' => $client['badge']] + $cell, $client['name']];
			case 'type':
				return [['text' => $client['type'], 'class' => $client['type'] === 'DI' ? 'ep-pill ep-di' : 'ep-pill ep-op'] + $cell, $client['type']];
			case 'macro':
				$v = $client['macros'][$c['macro']] ?? '';
				return [['text' => $v !== '' ? $v : '—'] + $cell, $v];
		}
		$value = $values[$c['key']] ?? null;
		$unit = $units[$c['key']] ?? '';
		if (($c['kind'] ?? '') === 'requested' && $value !== null && $value == 0) {
			return [['text' => _('not set'), 'class' => 'ep-unset'] + $cell, null];
		}
		if ($value !== null) {
			$cell['text'] = convertUnits(['value' => $value, 'units' => $unit, 'decimals' => Settings::decimals($unit, $this->cfg)]);
		}
		$usage = !empty($c['usage']) ? $value : (isset($c['usageKey']) ? ($values[$c['usageKey']] ?? null) : null);
		if ($usage !== null) {
			$cell['class'] = self::usageClass($usage, $warn, $high);
			// Same figure as the colour. Null stays null: no bar for something not reported.
			$cell['bar'] = max(0.0, min(100.0, (float) $usage));
			if (isset($c['usageKey']) && $value !== null) {
				$cell['sub'] = '('.round($usage).' %)';
			}
		}
		if (isset($c['vs'])) {
			$req = $values[$c['vs']] ?? null;
			if ($value !== null && $req !== null && $req > 0 && $value < $req) {
				$cell['class'] = trim($cell['class'].' ep-short');
				$cell['hint'] = _s('Below %1$s: %2$s', $c['vs'] === 'ep.es.storage.purchased' ? _('purchased') : _('requested'),
					convertUnits(['value' => $req, 'units' => $units[$c['vs']] ?? $unit,
						'decimals' => Settings::decimals($units[$c['vs']] ?? $unit, $this->cfg)]));
			}
		}
		return [$cell, $this->exportValue($value, $unit)];
	}

	public static function usageClass(?float $v, float $warn, float $high): string {
		return ClientData::usageClass($v, $warn, $high);
	}

	private static function r1(?float $v): ?float {
		return $v === null ? null : round($v, 1);
	}

	/** What a size column's header says it was divided by — the two must never disagree. */
	private function suffix(string $units): string {
		return $units === 'B' ? ' ('.Settings::sizeLabel($this->cfg).')' : ($units === '%' ? ' (%)' : '');
	}

	private function exportValue(?float $value, string $units) {
		if ($value === null) {
			return null;
		}
		return $units === 'B' ? round($value / Settings::sizeDivisor($this->cfg), 2) : round($value, $units === '%' ? 1 : 2);
	}
}
