<?php declare(strict_types = 0);

namespace Modules\EpVolume\Actions;

foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'ClientData', 'Bars', 'Forecast', 'Columns', 'ColumnMath', 'ColumnReader', 'ColumnCell', 'ColumnReport',
		'TableOptions'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}
foreach (['CWidgetFieldStrings', 'CWidgetFieldOptionalNumber', 'CWidgetFieldColumns', 'TableFields', 'Catalogue', 'WidgetForm'] as $inc) {
	require_once __DIR__.'/../includes/'.$inc.'.php';
}

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CCsrfTokenHelper;
use Modules\EpVolume\Includes\{Catalogue, WidgetForm};
use Modules\EpVolume\Lib\{Bars, ClientData, ColumnCell, ColumnReader, ColumnReport, ColumnSettings, Columns, Roles, Settings, Store, TableOptions};

/**
 * The Volume report's client plan: one row per cluster host.
 *
 * The columns are the "ElasticPro client plan" template's items — label, section (tag `plan`),
 * place (tag `column`) and units — which ElasticPro generates from the same list the Volume report
 * page draws (Catalogue), and any other item of the cluster host a column asks for. So this keeps
 * no column list of its own: a column added to the report reaches the widget with the template. The
 * Columns dialog renames, hides and reorders them within their section (one setting for everyone),
 * a widget may have its own, and a cluster's client type — DI or On-Prem — comes from its client's
 * master host.
 *
 * Coloured where the template's triggers alarm, at the thresholds they use — Live used above
 * {$ELASTICPRO.PLAN.LIVE_USED.WARN}, Live days under {$ELASTICPRO.PLAN.LIVE_DAYS.MIN}, the host's
 * own macros first — unless the widget says each column has its own. A figure ElasticPro could not
 * measure is not sent to Zabbix at all; here it is "—" and an empty cell in the export, never 0.
 * The figures, their cells, totals and export are the shared column model's (ColumnReport).
 */
class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$cfg = Settings::all();
		$roles = Roles::load();
		$f = $this->fields_values;
		$now = time();

		$model = ColumnSettings::loadModel(Catalogue::REPORT);
		$catalogue = Catalogue::columns($roles, $model['split']);
		$plan = Catalogue::plan();
		$cols = ColumnReport::resolve($f, $model, $catalogue, [], $this->form ?? null);
		// How many places a figure is drawn to: the widget's, else the site's — whole for days and bare counts.
		$visible = array_map(fn($c) => self::withDecimals($c, $f, $cfg), $cols['visible']);
		$view = TableOptions::read($f, WidgetForm::TABLE);
		$report = new ColumnReport($cfg, $now, '', '', (int) ($f['thresholds_from'] ?? WidgetForm::THRESHOLDS_PLAN) === WidgetForm::THRESHOLDS_PLAN
			? fn($col, $entry, $value, $client) => self::planLevel($col, $entry, $value, $client) : null, true);

		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'columns' => array_map(fn($c) => $report->head($c, $catalogue), $visible),
			'rows' => [],
			'totals' => null,
			'view' => $view,
			'dropped' => $cols['dropped'],
			'palette' => Settings::paletteStyle($cfg),
			'export' => null,
			'error' => $plan['error'],
			'meta' => [
				'title' => 'Volume report',
				'report' => Catalogue::REPORT,
				'action' => 'widget.ep_volume.columns',
				'token' => CCsrfTokenHelper::get('widget.ep_volume.columns'),
				'canEdit' => $this->getUserType() == USER_TYPE_SUPER_ADMIN,
				// The Columns dialog edits the report defaults: names, shown, order.
				'columns' => ColumnReport::dialogColumns($cols['defaults'], $catalogue),
				// This widget's own list, so a Super admin can make it the report defaults.
				'own' => $cols['own'] ? ['columns' => $cols['columns'], 'server_columns' => []] : null,
				'types' => Settings::types($cfg)['all'],
				'view' => ['view' => 0, 'switch' => false, 'attention' => $view['attention_default'], 'compact' => $view['density'] === TableOptions::DENSITY_COMPACT],
				// So the Columns dialog can say why Save will fail, instead of failing.
				'storeOk' => Store::writable()
			],
			'user' => ['debug_mode' => $this->getDebugMode()]
		];
		if ($plan['error'] !== null) {
			$this->setResponse(new CControllerResponseData($data));
			return;
		}

		$options = [
			'output' => ['hostid', 'name'],
			'selectHostGroups' => ['name'],
			'selectTags' => ['tag', 'value'],
			'templateids' => $plan['templateids'],
			'preservekeys' => true
		];
		if ($f['groupids'] ?? []) {
			$options['groupids'] = getSubGroups($f['groupids']);
		}
		$hosts = API::Host()->get($options) ?: [];

		// The thresholds the template's triggers use, per host where a host overrides them.
		$macros = [];
		if ($hosts) {
			foreach (API::UserMacro()->get(['output' => ['hostid', 'macro', 'value'], 'hostids' => array_keys($hosts)]) ?: [] as $m) {
				$macros[$m['hostid']][$m['macro']] = (string) ($m['value'] ?? '');
			}
		}

		// A cluster's client, and the client's type, from the client master hosts.
		[, $clients] = ClientData::clients($cfg, []);
		$byClient = [];
		foreach ($clients as $c) {
			$byClient[$c['name']] = $c['type'];
		}
		$want = array_map('mb_strtolower', $f['clients'] ?? []);
		$types = $f['client_types'] ?? [];

		$rowsIn = [];
		foreach ($hosts as $hostid => $host) {
			// Decommissioned clients leave the report.
			if (in_array(['tag' => 'ep-status', 'value' => 'decommissioned'], array_map(fn($t) => ['tag' => $t['tag'], 'value' => $t['value']], $host['tags'] ?? []), true)) {
				continue;
			}
			$type = Settings::defaultType($cfg);
			$owner = null;
			foreach ($host['hostgroups'] as $g) {
				if (isset($byClient[$g['name']])) {
					$type = $byClient[$g['name']];
					$owner = $g['name'];
				}
			}
			if (($want && !in_array(mb_strtolower((string) $owner), $want, true)) || ($types && !in_array($type, $types, true))) {
				continue;
			}
			$rowsIn[$hostid] = [
				'client' => ['name' => $host['name'], 'type' => $type, 'macros' => $macros[$hostid] ?? [], 'plan' => [
					'warn' => Catalogue::macro($macros[$hostid] ?? [], Catalogue::MACRO_WARN, Catalogue::macro($plan['defaults'], Catalogue::MACRO_WARN, Catalogue::FALLBACK_WARN)),
					'days' => Catalogue::macro($macros[$hostid] ?? [], Catalogue::MACRO_DAYS, Catalogue::macro($plan['defaults'], Catalogue::MACRO_DAYS, Catalogue::FALLBACK_DAYS))
				]],
				// A row is one host: its custom items are the cluster host's own.
				'servers' => [['hostid' => (string) $hostid, 'roles' => [], 'disks' => []]]
			];
		}
		if ($want && !$rowsIn && $hosts && !array_intersect($want, array_map('mb_strtolower', array_keys($byClient)))) {
			$data['error'] = count($want) === 1
				? _s('No client named "%1$s" here. Check the name in ElasticPro → Clients, or change the Clients setting.', $f['clients'][0])
				: _('None of the clients this widget names is here. Check the names in ElasticPro → Clients, or change the Clients setting.');
			$this->setResponse(new CControllerResponseData($data));
			return;
		}

		$report->read($rowsIn, $visible, $catalogue, (int) ($f['trend_days'] ?? 7), [Catalogue::KEY_EPOCH, Catalogue::KEY_REACHABLE]);
		$items = $report->items();

		$widgetBars = Bars::styleOf(Bars::isInherit($f['bars'] ?? Bars::INHERIT) ? Settings::bars($cfg) : $f['bars']);
		$rows = [];
		foreach ($rowsIn as $hostid => $in) {
			$c = $in['client'];
			['cells' => $cells, 'flags' => $flags, 'sort' => $sort, 'export' => $export] = $report->cells((string) $hostid, $c, $visible, $catalogue, $widgetBars);
			$epoch = ColumnReader::value($items[$hostid][Catalogue::KEY_EPOCH] ?? null);
			$reach = ColumnReader::value($items[$hostid][Catalogue::KEY_REACHABLE] ?? null);
			$unreachable = $reach !== null && $reach == 0;
			$hint = $epoch !== null ? _s('Updated %1$s ago', zbx_date2age((int) $epoch)) : _('Never updated');
			$rows[] = [
				'client' => $c['name'], 'type' => $c['type'], 'hostid' => (string) $hostid, 'cells' => $cells,
				'attention' => ColumnCell::attention($flags, $view['attention']), 'flags' => $flags,
				'row_class' => $unreachable ? 'ep-unreachable' : null,
				'first_title' => $unreachable ? _('Cluster unreachable — figures are the last measured').' · '.$hint : $hint,
				'sort' => ['client' => $c['name']] + $sort, 'export' => $export
			];
		}

		$sortBy = in_array($view['sort_by'], array_column($visible, 'uid'), true) ? $view['sort_by'] : 'client';
		$rows = ColumnCell::arrange($rows, $sortBy, $view['sort_desc'], $view['top_n']);
		foreach ($rows as $i => &$row) {
			$row['id'] = 'c'.$i;
		}
		unset($row);
		$data['rows'] = $rows;
		if ($view['totals'] && $rows) {
			$data['totals'] = $report->totals($rows, $visible, $catalogue, $view['totals_avg'], $view['top_n']);
		}

		// The export: the columns the widget shows, in its order.
		$data['export'] = ['headers' => $report->exportHeaders($visible, $catalogue, false), 'rows' => array_column($rows, 'export'), 'types' => array_column($rows, 'type')];
		foreach ($data['rows'] as &$row) {
			unset($row['export'], $row['sort']);
		}
		unset($row);
		$this->setResponse(new CControllerResponseData($data));
	}

	/**
	 * A column with the places it is drawn to: its own, else the widget's, else the site's — whole
	 * for days and bare counts, a measured figure otherwise, as the report has always drawn them.
	 */
	public static function withDecimals(array $col, array $f, array $cfg): array {
		if ($col['decimals'] !== '') {
			return $col;
		}
		$own = $f['decimals'] ?? Settings::INHERIT_CHOICE;
		if (is_numeric($own) && (int) $own !== Settings::INHERIT_CHOICE) {
			$col['decimals'] = (string) (int) $own;
		}
		elseif ((int) $col['source'] === Columns::SOURCE_BUILTIN) {
			$bare = in_array(ltrim((string) $col['units'], '!'), ['days', ''], true);
			$col['decimals'] = (string) Settings::decimals($bare ? '' : '%', $cfg);
		}

		return $col;
	}

	/**
	 * What the template's triggers alarm on, at the thresholds they use: the use of live storage
	 * above Warn is yellow, the days of live storage left under the minimum is red. Only those two
	 * columns; every other column is judged by its own thresholds.
	 */
	public static function planLevel(array $col, ?array $entry, float $value, array $client): ?string {
		if ($entry === null || !isset($entry['key'])) {
			return null;
		}
		switch (Catalogue::kind((string) $entry['key'])) {
			case 'used':
				return $value > $client['plan']['warn'] ? 'warn' : '';
			case 'days':
				return $value < $client['plan']['days'] ? 'high' : '';
		}

		return null;
	}
}
