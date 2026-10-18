<?php declare(strict_types = 0);

namespace Modules\EpResources\Actions;

foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'ClientData', 'Bars', 'Forecast', 'Columns', 'ColumnMath', 'ColumnReader'] as $lib) {
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
use Modules\EpResources\Lib\{Bars, ClientData, ColumnMath, ColumnReader, Columns, ColumnSettings, Forecast, Roles, Settings, Store};

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
	private ColumnReader $reader;

	protected function doAction(): void {
		$this->cfg = Settings::all();
		$this->roles = Roles::load();
		$this->f = $this->fields_values;
		$this->now = time();
		$this->reader = new ColumnReader();
		$f = $this->f;

		$model = ColumnSettings::loadModel(Catalogue::REPORT);
		$catalogue = Catalogue::columns($this->roles, $model['split']);
		$clientCat = Catalogue::forScope($catalogue, 'client');
		$rowCat = Catalogue::forScope($catalogue, 'row');
		$roleIds = array_column(Roles::allRoles($this->roles), 'id');

		// The columns: this widget's own, or the report defaults (a version-1 file read into them).
		$own = (int) ($f['columns_from'] ?? WidgetForm::FROM_DEFAULTS) === WidgetForm::FROM_WIDGET;
		$defaults = ColumnSettings::resolve($model['columns'], $clientCat, $model['legacy'], $model['new_hidden']);
		$defaultRows = $model['server_columns'] !== null ? ColumnSettings::resolve($model['server_columns'], $rowCat, null, true) : Catalogue::serverDefaults();
		$list = $own && !empty($f['columns']) ? ColumnSettings::resolve($f['columns'], $clientCat, null, true) : $defaults;
		$rowList = $own && !empty($f['server_columns']) ? ColumnSettings::resolve($f['server_columns'], $rowCat, null, true) : $defaultRows;
		// The rule again, leniently: what no longer passes is left out, and the widget says which.
		[$columns, , $dropped] = Columns::cleanList($list, $clientCat, $roleIds, false);
		[$rowColumns, , $droppedRows] = Columns::cleanList($rowList, $rowCat, $roleIds, false, 'row');
		// A widget's own list was already cleaned by its field when Zabbix read the widget; what that
		// left out is named there.
		if ($own && isset($this->form)) {
			foreach (['columns' => 'dropped', 'server_columns' => 'droppedRows'] as $name => $var) {
				$field = $this->form->getFields()[$name] ?? null;
				if ($field !== null && method_exists($field, 'getDropped')) {
					$$var = array_merge($field->getDropped(), $$var);
				}
			}
		}
		$visible = array_values(array_filter($columns, fn($c) => !$c['hidden']));
		$visibleRows = array_values(array_filter($rowColumns, fn($c) => !$c['hidden']));
		[$cleanDefaults] = Columns::cleanList($defaults, $clientCat, $roleIds, false);
		$measures = Catalogue::measures($this->roles, $model['split']);
		$view = $this->viewOptions($measures);

		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'columns' => array_map(fn($c) => $this->head($c, $clientCat), $visible),
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
				'columns' => array_map(fn($c) => ['id' => $c['uid'], 'label' => $c['name'],
					'default' => (int) $c['source'] === Columns::SOURCE_BUILTIN ? (string) ($clientCat[$c['builtin']]['label'] ?? $c['name']) : $c['name'],
					'hidden' => (bool) $c['hidden'], 'section' => $c['group']], $cleanDefaults),
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
		$exact = [];
		$patterns = [];
		$plan = [];
		$donutKeys = [];
		foreach ($view['donuts'] as $id) {
			foreach (['used', 'used_pct', 'allocated', 'requested'] as $k) {
				if (($measures[$id][$k] ?? null) !== null) {
					$donutKeys[] = $measures[$id][$k];
				}
			}
		}
		$donutKeys[] = Forecast::KEY;
		foreach ($clients as $mid => $c) {
			$list = $servers[$c['name']] ?? [];
			foreach ($visible as $col) {
				$plan[$mid][$col['uid']] = $this->targets($col, $clientCat, (string) $mid, $list);
				foreach ($plan[$mid][$col['uid']] as $target) {
					foreach ($target['want'] as [$hostid, $key, $pattern]) {
						if ($pattern) {
							$patterns[$key][$hostid] = true;
						}
						else {
							$exact[$key][$hostid] = true;
						}
					}
				}
			}
			foreach ($donutKeys as $key) {
				$exact[$key][$mid] = true;
			}
		}
		$items = $this->reader->items($exact, $patterns);

		// Trends: every item a column takes a window of, or draws a sparkline from.
		$trendDays = max(1, (int) ($f['trend_days'] ?? 7));
		$wantSeries = [];
		foreach ($clients as $mid => $c) {
			foreach ($visible as $col) {
				if ((int) $col['value'] === Columns::VALUE_NOW && (int) $col['show'] !== Columns::SHOW_SPARKLINE) {
					continue;
				}
				$from = $this->now - ($col['window'] > 0 ? $col['window'] : $trendDays) * 86400;
				foreach ($plan[$mid][$col['uid']] as $target) {
					foreach ($this->reader->matched($target['want'], $items) as $it) {
						if (!ColumnReader::numeric($it)) {
							continue;
						}
						$wantSeries[$it['itemid']] = ['from' => min($from, $wantSeries[$it['itemid']]['from'] ?? PHP_INT_MAX),
							'till' => $this->now, 'value_type' => (int) $it['value_type']];
					}
				}
			}
		}
		$series = $wantSeries ? $this->reader->series($wantSeries) : [];

		// Every figure first, then the cells: a bar with no top of its own runs to the highest of its column.
		$found = [];
		$seenMax = [];
		$units = [];
		foreach ($clients as $mid => $c) {
			foreach ($visible as $col) {
				$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($clientCat[$col['builtin']] ?? null) : null;
				$fig = $this->figure($col, $entry, (string) $mid, $c, $plan[$mid][$col['uid']], $items, $series, $trendDays, $clientCat);
				$found[$mid][$col['uid']] = $fig;
				if (isset($fig['value']) && is_float($fig['value'])) {
					$seenMax[$col['uid']] = max($seenMax[$col['uid']] ?? $fig['value'], $fig['value']);
				}
				if (($fig['units'] ?? '') !== '' && !isset($units[$col['uid']])) {
					$units[$col['uid']] = $fig['units'];
				}
			}
		}

		$widgetBars = Bars::styleOf(Bars::isInherit($f['bars'] ?? Bars::INHERIT) ? Settings::bars($this->cfg) : $f['bars']);
		$warnW = $f['usage_warn'] ?? '';
		$highW = $f['usage_high'] ?? '';
		$data['server_head'] = $this->serverHead($visibleRows, $rowCat);
		$rows = [];
		foreach ($clients as $mid => $c) {
			$cells = [];
			$flags = ['warn' => 0, 'high' => 0, 'short' => 0, 'nodata' => 0, 'down' => 0, 'full_days' => null];
			$sort = [];
			$export = [];
			foreach ($visible as $col) {
				$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($clientCat[$col['builtin']] ?? null) : null;
				$fig = $found[$mid][$col['uid']] + ['seen_max' => $seenMax[$col['uid']] ?? null, 'widget_bars' => $widgetBars,
					'usage' => Resources::usage($c['macros'], $col, $warnW, $highW, $this->cfg)];
				$cell = Resources::cell($col, $entry, $fig, $this->cfg) + ['layout' => Resources::layout($col), 'first' => !$cells];
				if ($cell['level'] === 'warn' || $cell['level'] === 'high') {
					$flags[$cell['level']]++;
				}
				if ($cell['short']) {
					$flags['short']++;
				}
				// A figure that says what it sorts and exports by (a forecast: its days, null when not
				// growing) is taken at its word, null included.
				$sort[$col['uid']] = array_key_exists('sort', $fig) ? $fig['sort'] : $cell['value'];
				$cell['sort'] = $sort[$col['uid']];
				$export[] = Resources::exportValue(array_key_exists('export', $fig) ? $fig['export'] : $cell['value'],
					(string) ($fig['export_units'] ?? $fig['units'] ?? ''), $this->cfg);
				$cells[] = $cell;
			}
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
			$data['totals'] = [];
			foreach ($visible as $i => $col) {
				$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($clientCat[$col['builtin']] ?? null) : null;
				$u = (string) ($units[$col['uid']] ?? $col['units']);
				$text = !empty($entry['text']) || !empty($entry['forecast']);
				$values = array_map(fn($r) => $r['sort'][$col['uid']] ?? null, $rows);
				$isPct = (int) $col['source'] === Columns::SOURCE_CALC && (int) $col['calc_op'] === Columns::OP_OF;
				$t = $text ? null : Resources::total($values, $isPct ? '%' : $u, $view['totals_avg']);
				$data['totals'][] = $i === 0 ? ($view['totals_avg'] ? _('Average') : _('Total')) : ($t === null ? '' : ($isPct ? Resources::pctText($t, $this->cfg) : Resources::text($t, $u, $col, $this->cfg)));
			}
		}

		// The export: the columns the widget shows, in its order; the servers on their own sheet.
		$data['export'] = [
			'headers' => array_map(fn($col) => Resources::exportHeader($col, $this->exportUnits($col, $clientCat, $units), $this->cfg), $visible),
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
		return [
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
			'density' => (int) ($f['density'] ?? WidgetForm::DENSITY_COMPACT),
			'row_height' => ($f['row_height'] ?? '') === '' ? null : (int) $f['row_height'],
			'group_headers' => (int) ($f['group_headers'] ?? 1) === 1,
			'sticky_header' => (int) ($f['sticky_header'] ?? 1) === 1,
			'freeze_first' => (int) ($f['freeze_first'] ?? 1) === 1,
			'striped' => (int) ($f['striped'] ?? 0) === 1,
			'totals' => (int) ($f['totals'] ?? 0) === 1,
			'totals_avg' => (int) ($f['totals_fn'] ?? WidgetForm::TOTALS_SUM) === WidgetForm::TOTALS_AVG,
			'sort_by' => (string) ($f['sort_by'] ?? 'client'),
			'sort_desc' => (int) ($f['sort_dir'] ?? WidgetForm::SORT_ASC) === WidgetForm::SORT_DESC,
			'top_n' => ($f['top_n'] ?? '') === '' ? null : (int) $f['top_n'],
			'toolbar' => $f['toolbar_items'] ?? WidgetForm::TOOLBAR_DEFAULT,
			'toolbar_pos' => (int) ($f['toolbar_pos'] ?? WidgetForm::TOOLBAR_HOVER),
			'attention' => $f['attention'] ?? WidgetForm::ATTENTION_DEFAULT,
			'attention_default' => (int) ($f['attention_default'] ?? WidgetForm::ATTENTION_ALL) === WidgetForm::ATTENTION_ONLY,
			'hatch' => 'ep-hatch-'.bin2hex(random_bytes(4))
		];
	}

	/** A column's header: its group, its name, how its cells sit, and whether it is a number. */
	private function head(array $col, array $catalogue): array {
		$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$col['builtin']] ?? null) : null;
		return ['uid' => $col['uid'], 'builtin' => (int) $col['source'] === Columns::SOURCE_BUILTIN ? $col['builtin'] : '', 'group' => $col['group'],
			'name' => $col['name'], 'layout' => Resources::layout($col), 'custom' => (int) $col['source'] !== Columns::SOURCE_BUILTIN];
	}

	/** The units a column's export is in: what Zabbix said its items are in, else what the catalogue says. */
	private function exportUnits(array $col, array $catalogue, array $units): string {
		if ((int) $col['source'] === Columns::SOURCE_CALC && (int) $col['calc_op'] === Columns::OP_OF) {
			return '%';
		}
		$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$col['builtin']] ?? null) : null;
		if (!empty($entry['forecast'])) {
			return 'days';
		}
		return (string) ($units[$col['uid']] ?? ($entry['units'] ?? $col['units']));
	}

	/**
	 * Where one column is read for one client: for each of its figures (the value; a calculated
	 * column's two operands; a built-in's share and request) [hostid, key, is pattern] per host,
	 * and whether they are on the master host or the servers.
	 */
	private function targets(array $col, array $catalogue, string $mid, array $servers): array {
		$picked = array_values(array_filter($servers, fn($s) => !$col['roles'] || array_intersect($col['roles'], $s['roles'])));
		$operand = function (string $spec) use ($catalogue, $mid, $picked): array {
			if (strpos($spec, Columns::KEY_OPERAND) === 0) {
				$key = substr($spec, strlen(Columns::KEY_OPERAND));
				return ['on' => Columns::ON_SERVERS, 'servers' => count($picked), 'want' => array_map(fn($s) => [$s['hostid'], $key, false], $picked),
					'label' => $key];
			}
			$b = $catalogue[$spec] ?? null;
			if ($b === null || !isset($b['key'])) {
				return ['on' => Columns::ON_MASTER, 'servers' => 0, 'want' => [], 'label' => $spec];
			}
			if ($b['on'] === Columns::ON_MASTER) {
				return ['on' => Columns::ON_MASTER, 'servers' => 1, 'want' => [[$mid, $b['key'], false]], 'label' => self::operandLabel($b)];
			}
			$want = [];
			foreach ($picked as $s) {
				if (!empty($b['disk'])) {
					foreach ($s['disks'] as $d) {
						$want[] = [$s['hostid'], 'vfs.fs.dependent.size['.$d['mount'].',pused]', false];
					}
				}
				else {
					$want[] = [$s['hostid'], $b['key'], false];
				}
			}
			return ['on' => Columns::ON_SERVERS, 'servers' => count($picked), 'want' => $want, 'label' => self::operandLabel($b)];
		};
		switch ((int) $col['source']) {
			case Columns::SOURCE_ITEM:
				return ['a' => ['on' => Columns::ON_SERVERS, 'servers' => count($picked),
					'want' => array_map(fn($s) => [$s['hostid'], $col['key'], (bool) $col['pattern']], $picked), 'label' => $col['key']]];
			case Columns::SOURCE_CALC:
				return ['a' => $operand($col['calc_a']), 'b' => $operand($col['calc_b'])];
		}
		$b = $catalogue[$col['builtin']] ?? null;
		if ($b === null || !isset($b['key'])) {
			return [];
		}
		$out = ['a' => $operand($col['builtin'])];
		foreach (['pct', 'vs'] as $k) {
			if (isset($b[$k])) {
				$out[$k] = ['on' => Columns::ON_MASTER, 'servers' => 1, 'want' => [[$mid, $b[$k], false]], 'label' => $k];
			}
		}
		return $out;
	}

	/** How a reason names a built-in figure: "ES storage allocated", "devices seen". */
	private static function operandLabel(array $b): string {
		$label = mb_strtolower(mb_substr((string) $b['label'], 0, 1)).mb_substr((string) $b['label'], 1);
		return trim(($b['group'] ?? '') !== '' ? $b['group'].' '.$label : $label);
	}

	/**
	 * One operand's figure for one client: one value per server first — its worst item — then the
	 * servers combined; or the master host's item. Now, or over the window from the trend.
	 *
	 * @return array{now: ?float, value: ?float, trend: ?array, itemids: array, hostids: array, units: string, why: ?string, newest: int}
	 */
	private function evaluate(array $target, array $col, array $items, array $series, int $trendDays, array $usage): array {
		$found = $this->reader->matched($target['want'], $items, $capped);
		$numeric = array_values(array_filter($found, fn($it) => ColumnReader::numeric($it)));
		$master = $target['on'] === Columns::ON_MASTER;
		$lower = (int) $col['worse'] === Columns::WORSE_LOWER;
		$fn = $master ? Columns::COMBINE_MAX : (int) $col['combine'];
		$over = fn(float $v) => ColumnMath::level($v, $col, $usage)['level'] !== '';
		$hostValues = ColumnMath::perHost(array_map(fn($it) => [$it['hostid'], ColumnReader::value($it)], $numeric), $lower);
		$now = ColumnMath::combine(array_values($hostValues), $fn, $over);
		$from = $this->now - ($col['window'] > 0 ? $col['window'] : $trendDays) * 86400;
		$byHost = [];
		foreach ($numeric as $it) {
			if (isset($series[$it['itemid']])) {
				$byHost[$it['hostid']][$it['itemid']] = $series[$it['itemid']];
			}
		}
		$trend = $byHost ? ColumnMath::trend(ColumnMath::hostSeries($byHost, $lower), $fn, $from, $this->now, $over) : null;
		$value = (int) $col['value'] === Columns::VALUE_NOW ? $now : ColumnMath::windowValue($trend, (int) $col['value']);
		$why = null;
		if ($value === null) {
			if (!$master && $target['servers'] === 0) {
				$why = $col['roles'] ? _('no servers in these roles') : _('no servers with a role');
			}
			elseif ($found && !$numeric) {
				$why = _('not a number');
			}
			elseif ($now !== null && $trend === null) {
				$why = _('no trend yet');
			}
			elseif ($found) {
				$why = _('no value yet');
			}
			else {
				$why = _('not collected');
			}
		}
		$count = !$master && (int) $col['combine'] === Columns::COMBINE_COUNT;
		return [
			'now' => $now, 'value' => $value, 'trend' => $trend, 'capped' => (bool) $capped,
			'itemids' => array_column($numeric, 'itemid'), 'hostids' => array_values(array_unique(array_column($found, 'hostid'))),
			'units' => $count ? '' : (string) ($numeric[0]['units'] ?? ''), 'why' => $why,
			'newest' => $numeric ? max(array_map(fn($it) => (int) $it['lastclock'], $numeric)) : 0,
			'host_levels' => $count ? array_map(fn($v) => ColumnMath::level($v, $col, $usage)['level'], array_filter($hostValues, fn($v) => $v !== null)) : null
		];
	}

	/** One column's figures for one client, for Resources::cell to draw. */
	private function figure(array $col, ?array $entry, string $mid, array $client, array $plan, array $items, array $series, int $trendDays,
			array $catalogue): array {
		$usage = Resources::usage($client['macros'], $col, $this->f['usage_warn'] ?? '', $this->f['usage_high'] ?? '', $this->cfg);
		$source = (int) $col['source'];

		if ($source === Columns::SOURCE_BUILTIN && $entry !== null && $entry['on'] === Columns::ON_CLIENT) {
			if (isset($entry['field'])) {
				return ['text' => (string) ($client[$entry['field']] ?? ''),
					'url' => (int) $col['click'] === Columns::CLICK_DASHBOARD ? ColumnMath::clickUrl(Columns::CLICK_DASHBOARD, [], [], $mid) : null];
			}
			return ['text' => (string) ($client['macros'][$entry['macro']] ?? '')];
		}
		if (!isset($plan['a'])) {
			return ['value' => null, 'why' => _('not collected')];
		}

		$a = $this->evaluate($plan['a'], $col, $items, $series, $trendDays, $usage);
		// Count over Warn is a number of servers: no units, never a percentage, whatever it counts.
		$count = $a['host_levels'] !== null && $source !== Columns::SOURCE_CALC;
		$fig = ['value' => $a['value'], 'units' => $count ? '' : ($a['units'] !== '' ? $a['units'] : (string) ($entry['units'] ?? $col['units'])), 'why' => $a['why']];
		if ($source !== Columns::SOURCE_BUILTIN && $col['units'] !== '' && !$count) {
			$fig['units'] = $col['units'];
		}
		$itemids = $a['itemids'];
		$hostids = $a['hostids'];

		if ($source === Columns::SOURCE_CALC) {
			$b = $this->evaluate($plan['b'], $col, $items, $series, $trendDays, $usage);
			$itemids = array_merge($itemids, $b['itemids']);
			$hostids = array_values(array_unique(array_merge($hostids, $b['hostids'])));
			$x = $a['value'];
			$y = $b['value'];
			$fig['why'] = $x === null ? _s('%1$s %2$s', $plan['a']['label'], $a['why']) : ($y === null ? _s('%1$s not set', $plan['b']['label']) : null);
			switch ((int) $col['calc_op']) {
				case Columns::OP_OF:
					if ($x !== null && $y !== null && $y > 0) {
						$fig['pct'] = $x / $y * 100;
						$fig['of'] = Resources::text($y, $b['units'] !== '' ? $b['units'] : $fig['units'], $col, $this->cfg);
						$fig['export'] = $fig['pct'];
						$fig['export_units'] = '%';
						$fig['sort'] = $fig['pct'];
					}
					else {
						if ($x !== null && $y !== null) {
							$fig['why'] = _s('%1$s not set', $plan['b']['label']);
						}
						$fig['value'] = null;
					}
					break;
				case Columns::OP_MINUS:
					$fig['value'] = $x !== null && $y !== null ? $x - $y : null;
					break;
				case Columns::OP_PLUS:
					$fig['value'] = $x !== null && $y !== null ? $x + $y : null;
					break;
				case Columns::OP_DIV:
					$fig['value'] = $x !== null && $y !== null && $y != 0 ? $x / $y : null;
					if ($x !== null && $y !== null && $y == 0) {
						$fig['why'] = _s('%1$s is 0', $plan['b']['label']);
					}
					break;
			}
			if ((int) $col['calc_op'] === Columns::OP_DIV && $col['units'] === '') {
				$fig['units'] = '';
			}
		}
		elseif ($source === Columns::SOURCE_BUILTIN && $entry !== null) {
			if (!empty($entry['forecast'])) {
				$fc = Resources::forecast($a['now'], $this->cfg);
				if ($fc['text'] === null) {
					return ['value' => null, 'why' => $a['why'] ?? _('not measured yet'), 'units' => 's'];
				}
				return ['value' => $a['now'], 'display' => $fc['text'], 'level' => $fc['level'], 'units' => 's', 'export' => $fc['days'],
					'export_units' => 'days', 'sort' => $fc['days'] ?? 1e9, 'url' => null];
			}
			if (!empty($entry['requested']) && (int) $col['value'] === Columns::VALUE_NOW && $a['value'] !== null && $a['value'] == 0) {
				return ['value' => null, 'unset' => true, 'units' => $fig['units']];
			}
			if (!empty($entry['settings']) && !$count) {
				$fig['pct'] = $a['value'];
			}
			if (isset($plan['pct'])) {
				$p = $this->evaluate($plan['pct'], $col, $items, $series, $trendDays, $usage);
				$fig['pct'] = $p['value'];
			}
			if (isset($plan['vs']) && $a['value'] !== null) {
				$req = ColumnReader::value($items[$mid][$entry['vs']] ?? null);
				if ($req !== null && $req > 0 && $a['value'] < $req) {
					$fig['short'] = _s('Below %1$s: %2$s', $entry['vs_label'] ?? _('requested'),
						Resources::text($req, (string) ($items[$mid][$entry['vs']]['units'] ?? $fig['units']), $col, $this->cfg));
				}
			}
			if ($a['value'] === null && isset($entry['fix'])) {
				$fig['fix'] = $entry['fix'];
			}
		}

		// Count over Warn: the level of the worst server, never the count against the thresholds it counts with.
		if ($a['host_levels'] !== null && $source !== Columns::SOURCE_CALC) {
			$fig['level'] = in_array('high', $a['host_levels'], true) ? 'high' : (in_array('warn', $a['host_levels'], true) ? 'warn' : '');
		}
		if ($fig['value'] !== null && $col['stale'] !== '' && $a['newest'] > 0 && $this->now - $a['newest'] > (int) $col['stale'] * 60) {
			$fig['stale'] = _s('No new value for %1$s min (stale after %2$s min)', (int) floor(($this->now - $a['newest']) / 60), $col['stale']);
		}
		if ($a['capped']) {
			$fig['capped'] = _s('The key pattern matches more items than are read (%1$s per server, %2$s in all) — make it narrower.',
				Columns::PATTERN_PER_HOST, Columns::PATTERN_PER_METRIC);
		}
		if ((int) $col['show'] === Columns::SHOW_SPARKLINE && $a['trend'] !== null) {
			$fig['series'] = array_values(array_filter($a['trend']['series'], fn($v) => $v !== null));
		}
		$fig['url'] = ColumnMath::clickUrl((int) $col['click'], $itemids, $hostids, $mid);
		if (!array_key_exists('sort', $fig)) {
			$fig['sort'] = $fig['value'];
		}
		return $fig;
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
