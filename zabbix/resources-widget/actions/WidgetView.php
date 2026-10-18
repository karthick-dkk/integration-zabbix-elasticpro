<?php declare(strict_types = 0);

namespace Modules\EpResources\Actions;

foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'ClientData', 'Bars', 'Forecast', 'Columns', 'ColumnMath', 'ColumnReader', 'ColumnCell', 'ColumnReport', 'TableOptions'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}
foreach (['CWidgetFieldStrings', 'CWidgetFieldOptionalNumber', 'CWidgetFieldColumns', 'Catalogue', 'Resources', 'WidgetForm'] as $inc) {
	require_once __DIR__.'/../includes/'.$inc.'.php';
}

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CCsrfTokenHelper;
use Modules\EpResources\Includes\{Catalogue, Resources, WidgetForm};
use Modules\EpResources\Lib\{Bars, ClientData, ColumnMath, ColumnReader, ColumnReport, Columns, ColumnSettings, Forecast, Roles, Settings, Store, TableOptions};

/**
 * Client resources: one row per client master host, its columns as the widget (or the report
 * defaults) lists them — built-in figures, any Zabbix item key on the client's servers, figures
 * calculated from two items, each now or over a window — and below each client its servers.
 * Drawn as a table, or as donuts of used, free and short per client.
 *
 * The built-in columns come from the roles (Catalogue), so a role added on the Clients page
 * appears here without a change to this widget. A figure Zabbix does not have is unknown and says
 * so — "—", or the reason when the column asks for it — and is an empty cell in the export; a
 * request of 0 is one nobody entered, shown as "not set"; a donut whose allocated is not set is
 * not drawn.
 *
 * Zabbix is asked a fixed number of times per refresh, never once per client, always as the user
 * viewing the widget: the master hosts and their macros and the servers with their figures
 * (ClientData), one item.get for every key the columns and donuts read (a second only when a
 * column's key is a pattern), and one trend.get for every item a window or a sparkline is asked of
 * (history.get instead when the window is under two hours) — ColumnReader.
 */
class WidgetView extends CControllerDashboardWidgetView {

	private array $cfg = [];
	private array $roles = [];
	private array $f = [];
	private int $now = 0;

	protected function doAction(): void {
		$this->cfg = Settings::all();
		$this->roles = Roles::load();
		$this->f = $this->fields_values;
		$this->now = time();
		$f = $this->f;

		$model = ColumnSettings::loadModel(Catalogue::REPORT);
		$catalogue = Catalogue::columns($this->roles, $model['split']);
		$clientCat = Catalogue::forScope($catalogue, 'client');
		$rowCat = Catalogue::forScope($catalogue, 'row');
		$roleIds = array_column(Roles::allRoles($this->roles), 'id');

		// The columns: this widget's own, or the report defaults (a version-1 file read into them);
		// the rule again, leniently: what no longer passes is left out, and the widget says which. A
		// widget's own list was already cleaned by its field when Zabbix read the widget; what that
		// left out is named there too.
		$cols = ColumnReport::resolve($f, $model, $clientCat, $roleIds, $this->form ?? null);
		['own' => $own, 'columns' => $columns, 'visible' => $visible, 'dropped' => $dropped, 'defaults' => $cleanDefaults] = $cols;
		$defaultRows = $model['server_columns'] !== null ? ColumnSettings::resolve($model['server_columns'], $rowCat, null, true) : Catalogue::serverDefaults();
		$rowList = $own && !empty($f['server_columns']) ? ColumnSettings::resolve($f['server_columns'], $rowCat, null, true) : $defaultRows;
		[$rowColumns, , $droppedRows] = Columns::cleanList($rowList, $rowCat, $roleIds, false, 'row');
		if ($own && isset($this->form)) {
			$field = $this->form->getFields()['server_columns'] ?? null;
			if ($field !== null && method_exists($field, 'getDropped')) {
				$droppedRows = array_merge($field->getDropped(), $droppedRows);
			}
		}
		$visibleRows = array_values(array_filter($rowColumns, fn($c) => !$c['hidden']));
		$measures = Catalogue::measures($this->roles, $model['split']);
		$view = $this->viewOptions($measures);

		$report = new ColumnReport($this->cfg, $this->now, $f['usage_warn'] ?? '', $f['usage_high'] ?? '');
		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'columns' => array_map(fn($c) => $report->head($c, $clientCat), $visible),
			'server_head' => [],
			'rows' => [],
			'totals' => null,
			'view' => $view,
			'dropped' => array_merge($dropped, $droppedRows),
			'palette' => Settings::paletteStyle($this->cfg),
			'export' => null,
			'error' => null,
			'meta' => [
				'title' => 'Client resources',
				'report' => Catalogue::REPORT,
				'action' => 'widget.ep_resources.columns',
				'token' => CCsrfTokenHelper::get('widget.ep_resources.columns'),
				'canEdit' => $this->getUserType() == USER_TYPE_SUPER_ADMIN,
				// The Columns dialog edits the report defaults: names, shown, order, families as roles.
				'columns' => ColumnReport::dialogColumns($cleanDefaults, $clientCat),
				'families' => array_map(fn($fam) => ['id' => $fam['id'], 'label' => $fam['label'], 'split' => in_array($fam['id'], $model['split'], true)],
					$this->roles['families']),
				// This widget's own lists, so a Super admin can make them the report defaults.
				'own' => $own ? ['columns' => $columns, 'server_columns' => $rowColumns] : null,
				'types' => Settings::types($this->cfg)['all'],
				'view' => ['view' => $view['view'], 'switch' => $view['switch'], 'attention' => $view['attention_default'],
					'compact' => $view['density'] === WidgetForm::DENSITY_COMPACT],
				// So the Columns dialog can say why Save will fail, instead of failing.
				'storeOk' => Store::writable()
			],
			'user' => ['debug_mode' => $this->getDebugMode()]
		];

		[$masters, $clients, $error] = ClientData::clients($this->cfg, $f['groupids'] ?? []);
		if ($error !== null) {
			$data['error'] = $error;
			$this->setResponse(new CControllerResponseData($data));
			return;
		}
		$want = array_map('mb_strtolower', $f['clients'] ?? []);
		$types = $f['client_types'] ?? [];
		if ($want && !array_filter($clients, fn($c) => in_array(mb_strtolower($c['name']), $want, true))) {
			$data['error'] = count($want) === 1
				? _s('No client named "%1$s" here. Check the name in ElasticPro → Clients, or change the Clients setting.', $f['clients'][0])
				: _('None of the clients this widget names is here. Check the names in ElasticPro → Clients, or change the Clients setting.');
			$this->setResponse(new CControllerResponseData($data));
			return;
		}
		$clients = array_filter($clients, fn($c) => (!$want || in_array(mb_strtolower($c['name']), $want, true))
			&& (!$types || in_array($c['type'], $types, true)));

		$servers = ClientData::servers($this->roles, $clients);

		// What to read: per client, per column, the hosts and keys; the donuts' and the forecast's master items.
		$donutKeys = [];
		foreach ($view['donuts'] as $id) {
			foreach (['used', 'used_pct', 'allocated', 'requested'] as $k) {
				if (($measures[$id][$k] ?? null) !== null) {
					$donutKeys[] = $measures[$id][$k];
				}
			}
		}
		$donutKeys[] = Forecast::KEY;
		$readRows = [];
		foreach ($clients as $mid => $c) {
			$readRows[$mid] = ['client' => $c, 'servers' => $servers[$c['name']] ?? []];
		}
		$report->read($readRows, $visible, $clientCat, (int) ($f['trend_days'] ?? 7), $donutKeys);
		$items = $report->items();

		$widgetBars = Bars::styleOf(Bars::isInherit($f['bars'] ?? Bars::INHERIT) ? Settings::bars($this->cfg) : $f['bars']);
		$warnW = $f['usage_warn'] ?? '';
		$highW = $f['usage_high'] ?? '';
		$data['server_head'] = $this->serverHead($visibleRows, $rowCat);
		$rows = [];
		foreach ($clients as $mid => $c) {
			['cells' => $cells, 'flags' => $flags, 'sort' => $sort, 'export' => $export] = $report->cells((string) $mid, $c, $visible, $clientCat, $widgetBars);
			$flags += ['nodata' => 0, 'down' => 0, 'full_days' => null];
			// The forecast, when "disk full within 30 days" is part of Needs attention.
			$fi = Resources::forecast(ColumnReader::value($items[$mid][Forecast::KEY] ?? null), $this->cfg);
			$flags['full_days'] = $fi['days'];

			$srv = [];
			foreach ($servers[$c['name']] ?? [] as $s) {
				// A server down (agent unavailable), or sending nothing at all: the "no data" of the chips.
				if ($s['status'] === 'down') {
					$flags['down']++;
				}
				elseif ($s['status'] === 'unknown') {
					$flags['nodata']++;
				}
				$srv[] = $this->serverRow($s, $c, $visibleRows, $rowCat, $widgetBars, $warnW, $highW);
			}
			$donuts = [];
			foreach ($view['donuts'] as $id) {
				if (!isset($measures[$id])) {
					continue;
				}
				$donuts[] = $this->donut($measures[$id], $items[$mid] ?? [], $c, $view, $warnW, $highW) + ['id' => $id];
			}
			$rows[] = [
				'client' => $c['name'], 'type' => $c['type'], 'hostid' => (string) $mid, 'badge' => $c['badge'], 'cells' => $cells, 'servers' => $srv,
				'search' => implode(' ', array_merge(array_column($servers[$c['name']] ?? [], 'host'), array_column($servers[$c['name']] ?? [], 'ip'))),
				'attention' => Resources::attention($flags, $view['attention']), 'flags' => $flags, 'chips' => Resources::chips($flags),
				'donuts' => $donuts, 'sort' => ['client' => $c['name']] + $sort, 'export' => $export,
				'dashboard' => ColumnMath::url('zabbix.php', ['action' => 'host.dashboard.view', 'hostid' => (string) $mid])
			];
		}

		$sortBy = in_array($view['sort_by'], array_column($visible, 'uid'), true) ? $view['sort_by'] : 'client';
		$rows = Resources::arrange($rows, $sortBy, $view['sort_desc'], $view['top_n']);
		foreach ($rows as $i => &$row) {
			$row['id'] = 'c'.$i;
		}
		unset($row);
		$data['rows'] = $rows;

		if ($view['totals'] && $rows) {
			$data['totals'] = $report->totals($rows, $visible, $clientCat, $view['totals_avg'], $view['top_n']);
		}

		// The export: the columns the widget shows, in its order; the servers on their own sheet.
		$data['export'] = [
			'headers' => $report->exportHeaders($visible, $clientCat),
			'rows' => array_column($rows, 'export'),
			'types' => array_column($rows, 'type'),
			'servers' => $this->serverExport($rows, $servers, $clients, $visibleRows)
		];
		foreach ($data['rows'] as &$row) {
			unset($row['export'], $row['sort']);
		}
		unset($row);
		$this->setResponse(new CControllerResponseData($data));
	}

	/** The View tab's settings, each read with its default — a widget saved before 2.1.1 has none of them. */
	private function viewOptions(array $measures): array {
		$f = $this->f;
		$order = array_values(array_intersect($f['pie_order'] ?? [], array_keys($measures)));
		$order = array_merge($order, array_values(array_diff(array_keys($measures), $order)));
		$on = $f['pie_donuts'] ?? Catalogue::DEFAULT_DONUTS;
		return TableOptions::read($f, ['toolbar' => WidgetForm::TOOLBAR_DEFAULT, 'attention' => WidgetForm::ATTENTION_DEFAULT]) + [
			'view' => (int) ($f['view'] ?? WidgetForm::VIEW_TABLE),
			'switch' => (int) ($f['view_switch'] ?? 0) === 1,
			'donuts' => array_values(array_filter($order, fn($id) => in_array($id, $on, true))),
			'centre' => (int) ($f['pie_centre'] ?? WidgetForm::CENTRE_PCT_ALLOCATED),
			'legend' => $f['pie_legend'] ?? WidgetForm::LEGEND,
			'short' => (int) ($f['pie_short'] ?? WidgetForm::SHORT_HATCHED),
			'per_row' => (int) ($f['pie_per_row'] ?? 0),
			'size' => (int) ($f['pie_size'] ?? WidgetForm::SIZE_MEDIUM),
			'chips' => (int) ($f['pie_chips'] ?? 1) === 1,
			'server_rows' => (int) ($f['server_rows'] ?? WidgetForm::ROWS_CLICK),
			'hatch' => 'ep-hatch-'.bin2hex(random_bytes(4))
		];
	}

	/**
	 * The server table's header: one per visible column, the disk columns drawn as one column per
	 * mount where the first of them stands.
	 */
	private function serverHead(array $columns, array $catalogue): array {
		$out = [];
		$disk = false;
		foreach ($columns as $col) {
			if (in_array($col['builtin'], ['row.disk_mount', 'row.disk_size', 'row.disk_used'], true)) {
				if (!$disk) {
					$out[] = ['disks' => true, 'sc' => 'disks', 'name' => ''];
					$disk = true;
				}
				continue;
			}
			$out[] = ['disks' => false, 'sc' => substr($col['builtin'], 4), 'name' => $col['name'], 'layout' => Resources::layout($col)];
		}
		return $out;
	}

	/** One server's cells, in the order of the server columns; its disks one per mount, coloured by their own figure. */
	private function serverRow(array $s, array $client, array $columns, array $catalogue, string $widgetBars, $warnW, $highW): array {
		$cells = [];
		$byId = [];
		foreach ($columns as $col) {
			$byId[$col['builtin']] = $col;
		}
		$diskDone = false;
		foreach ($columns as $col) {
			$id = $col['builtin'];
			$entry = $catalogue[$id] ?? null;
			$usage = Resources::usage($client['macros'], $col, $warnW, $highW, $this->cfg);
			if (in_array($id, ['row.disk_mount', 'row.disk_size', 'row.disk_used'], true)) {
				if ($diskDone) {
					continue;
				}
				$diskDone = true;
				$used = $byId['row.disk_used'] ?? null;
				$disks = [];
				foreach ($s['disks'] ?? [] as $d) {
					$lv = $used !== null && $d['used'] !== null ? ColumnMath::level($d['used'], $used, Resources::usage($client['macros'], $used, $warnW, $highW, $this->cfg)) : ['level' => '', 'color' => null];
					$disks[$d['mount']] = [
						'mount' => isset($byId['row.disk_mount']) ? $d['mount'] : null,
						'size' => isset($byId['row.disk_size']) ? ($d['total'] === null ? '—' : Resources::text($d['total'], 'B', $byId['row.disk_size'], $this->cfg)) : null,
						'used' => $used !== null && $d['used'] !== null ? round($d['used']) : null,
						'used_on' => $used !== null,
						'bar' => $d['used'] === null ? null : max(0, min(100, round($d['used'], 1))),
						'class' => $lv['level'] === '' ? '' : 'ep-'.$lv['level']
					];
				}
				$cells[] = ['kind' => 'disks', 'disks' => $disks];
				continue;
			}
			$sc = substr($id, 4);
			switch ($id) {
				case 'row.role':
					$cells[] = ['kind' => 'role', 'sc' => $sc, 'roles' => array_values(array_filter(array_map('trim', explode('+', $s['role'])))),
						'services' => $s['services'] ?? [], 'single' => !empty($s['single']), 'shared' => !empty($s['shared']), 'layout' => Resources::layout($col)];
					break;
				case 'row.host':
					$cells[] = ['kind' => 'host', 'sc' => $sc, 'text' => $s['host'], 'layout' => Resources::layout($col),
						'url' => ColumnMath::clickUrl((int) $col['click'], [], [$s['hostid']], (string) $s['hostid'])];
					break;
				case 'row.status':
					$cells[] = ['kind' => 'status', 'sc' => $sc, 'status' => $s['status'], 'layout' => Resources::layout($col)];
					break;
				default:
					$raw = $s[$entry['field']] ?? null;
					$fig = !empty($entry['text']) ? ['text' => (string) ($raw ?? '')]
						: ['value' => $raw === null ? null : (float) $raw, 'units' => (string) $entry['units'], 'usage' => $usage, 'widget_bars' => $widgetBars,
							'why' => _('not collected')];
					if (!empty($entry['settings']) && $raw !== null) {
						$fig['pct'] = (float) $raw;
					}
					if ($id === 'row.cores' && $raw !== null) {
						$fig['display'] = (string) (int) $raw;
					}
					if ($id === 'row.ip' && ($raw ?? '') === '') {
						$fig['text'] = '—';
					}
					$cells[] = ['kind' => 'cell', 'sc' => $sc, 'cell' => Resources::cell($col, $entry, $fig, $this->cfg), 'layout' => Resources::layout($col)];
			}
		}
		// raw: the server as ClientData read it, its classes at the client's thresholds — for the export and the tests.
		$t = Resources::usage($client['macros'], Columns::DEFAULT, $warnW, $highW, $this->cfg);
		return ['host' => $s['host'], 'hostid' => $s['hostid'], 'cells' => $cells, 'mounts' => array_column($s['disks'] ?? [], 'mount'),
			'raw' => ClientData::classify($s, $t['warn'], $t['high'])];
	}

	/** One donut for one client, from the master host's items. */
	private function donut(array $m, array $items, array $client, array $view, $warnW, $highW): array {
		$val = fn(?string $key) => $key === null ? null : ColumnReader::value($items[$key] ?? null);
		$allocated = $val($m['allocated']);
		$used = $m['used'] !== null ? $val($m['used'])
			: (($pct = $val($m['used_pct'])) !== null && $allocated !== null ? $pct / 100 * $allocated : null);
		$usage = Resources::usage($client['macros'], Columns::DEFAULT, $warnW, $highW, $this->cfg);
		return Resources::donut($m, ['used' => $used, 'allocated' => $allocated, 'requested' => $val($m['requested'])],
			['centre' => $view['centre'], 'legend' => $view['legend'], 'short' => $view['short']], $usage, $this->cfg);
	}

	/**
	 * The servers sheet: the client, its type, then the server columns the widget shows — the
	 * mounts and their total size beside each other, as the sheet always had them.
	 */
	private function serverExport(array $rows, array $servers, array $clients, array $columns): array {
		$ids = array_column($columns, 'builtin');
		$on = fn($id) => in_array($id, $ids, true);
		$div = Settings::sizeDivisor($this->cfg);
		$size = Settings::sizeLabel($this->cfg);
		$r1 = fn(?float $v) => $v === null ? null : round($v, 1);
		$spec = [];
		foreach ($columns as $col) {
			switch ($col['builtin']) {
				case 'row.role':
					$spec[] = ['Roles', fn($s) => $s['role']];
					$spec[] = ['Services', fn($s) => implode(', ', $s['services'] ?? [])];
					break;
				case 'row.host':
					$spec[] = ['Host', fn($s) => $s['host']];
					break;
				case 'row.ip':
					$spec[] = ['IP', fn($s) => $s['ip']];
					break;
				case 'row.status':
					$spec[] = ['Status', fn($s) => $s['status']];
					break;
				case 'row.cores':
					$spec[] = ['CPU cores', fn($s) => $s['cpu_cores']];
					break;
				case 'row.cpu':
					$spec[] = ['CPU used (%)', fn($s) => $r1($s['cpu_used'])];
					break;
				case 'row.mem_total':
					$spec[] = ['Memory ('.$size.')', fn($s) => $s['mem_total'] !== null ? round($s['mem_total'] / $div, 2) : null];
					break;
				case 'row.mem':
					$spec[] = ['Memory used (%)', fn($s) => $r1($s['mem_used'])];
					break;
				case 'row.disk_mount':
				case 'row.disk_size':
					if (!in_array('Disks', array_column($spec, 0), true)) {
						$spec[] = ['Disks', fn($s) => $s['mount']];
						if ($on('row.disk_size')) {
							$spec[] = ['Disk ('.$size.')', fn($s) => $s['disk_total'] !== null ? round($s['disk_total'] / $div, 2) : null];
						}
					}
					break;
				case 'row.disk_used':
					$spec[] = ['Disk used (%)', fn($s) => implode(', ', array_map(fn($d) => $d['mount'].': '.($d['used'] === null ? '—' : round($d['used'], 1)),
						$s['disks'] ?? []))];
					break;
				case 'row.uptime':
					$spec[] = ['Uptime (s)', fn($s) => $s['uptime']];
					break;
			}
		}
		$byName = [];
		foreach ($clients as $c) {
			$byName[$c['name']] = $c;
		}
		$out = [];
		foreach ($rows as $row) {
			foreach ($servers[$row['client']] ?? [] as $s) {
				$line = [$row['client'], $row['type']];
				foreach ($spec as [, $get]) {
					$line[] = $get($s);
				}
				$out[] = $line;
			}
		}
		return ['headers' => array_merge(['Client', 'Type'], array_column($spec, 0)), 'rows' => $out, 'types' => array_column($out, 1)];
	}
}
