<?php declare(strict_types = 0);

namespace Modules\EpCapacity\Actions;

foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'ClientData', 'Bars', 'Forecast', 'Columns', 'ColumnMath', 'ColumnReader', 'ColumnCell', 'ColumnReport',
		'TableOptions'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}
foreach (['CWidgetFieldStrings', 'CWidgetFieldOptionalNumber', 'CWidgetFieldColumns', 'TableFields', 'Catalogue', 'WidgetForm'] as $inc) {
	require_once __DIR__.'/../includes/'.$inc.'.php';
}

use CControllerDashboardWidgetView,
	CControllerResponseData,
	CCsrfTokenHelper;
use Modules\EpCapacity\Includes\{Catalogue, WidgetForm};
use Modules\EpCapacity\Lib\{Bars, ClientData, ColumnCell, ColumnReader, ColumnReport, ColumnSettings, Forecast, Roles, Settings, Store, TableOptions};

/**
 * The capacity table: one row per client master host, the columns the widget (or the report
 * defaults) lists — the master template's family-level items columns.json names, which the Clients
 * page writes (tests check the keys), any Zabbix item key on the client's servers, or figures
 * calculated from two items, each now or over a window.
 *
 * Every figure of the report's own columns is the master host's own item, so this reads, computes
 * nothing that a trigger or the host's dashboard does not also show, and needs no configuration
 * beyond which clients to list. A figure Zabbix does not have — an item unsupported, or never
 * measured — is shown as "—" and exported as an empty cell, never as 0; a request of 0 is one
 * nobody entered ("not set"). The figures, their cells and the export are the shared column
 * model's (ColumnReport), the same as Client resources draws with.
 *
 * Zabbix is asked a fixed number of times per refresh, never once per client, always as the user
 * viewing the widget: the master hosts and their macros (ClientData), one item.get for every key
 * the columns read (a second only when a column's key is a pattern), and one trend.get per window
 * a column asks of.
 */
class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$cfg = Settings::all();
		$roles = Roles::load();
		$f = $this->fields_values;
		$now = time();

		$model = ColumnSettings::loadModel(Catalogue::REPORT);
		$catalogue = Catalogue::columns($roles, $model['split']);
		$roleIds = array_column(Roles::allRoles($roles), 'id');
		$cols = ColumnReport::resolve($f, $model, $catalogue, $roleIds, $this->form ?? null);
		$visible = $cols['visible'];
		$view = TableOptions::read($f, ['toolbar' => WidgetForm::TOOLBAR_DEFAULT, 'attention' => WidgetForm::ATTENTION_DEFAULT]);
		$report = new ColumnReport($cfg, $now, $f['usage_warn'] ?? '', $f['usage_high'] ?? '');

		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'columns' => array_map(fn($c) => $report->head($c, $catalogue), $visible),
			'rows' => [],
			'totals' => null,
			'view' => $view,
			'dropped' => $cols['dropped'],
			'palette' => Settings::paletteStyle($cfg),
			'export' => null,
			'error' => null,
			'meta' => [
				'title' => 'Client capacity',
				'report' => Catalogue::REPORT,
				'action' => 'widget.ep_capacity.columns',
				'token' => CCsrfTokenHelper::get('widget.ep_capacity.columns'),
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

		[, $clients, $error] = ClientData::clients($cfg, $f['groupids'] ?? []);
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

		// A disabled client says so beside its name; the export has the name alone.
		foreach ($clients as $mid => $c) {
			$clients[$mid]['shown'] = $c['name'].($c['status'] === 'disabled' ? ' ('._('disabled').')' : '');
		}

		// The servers only when a column reads them: the report's own columns are the master hosts' items.
		$servers = ColumnReport::readsServers($visible, $catalogue) ? ClientData::servers($roles, $clients, false) : [];
		$readRows = [];
		foreach ($clients as $mid => $c) {
			$readRows[$mid] = ['client' => $c, 'servers' => $servers[$c['name']] ?? []];
		}
		$report->read($readRows, $visible, $catalogue, (int) ($f['trend_days'] ?? 7), [Forecast::KEY]);
		$items = $report->items();

		$widgetBars = Bars::styleOf(Bars::isInherit($f['bars'] ?? Bars::INHERIT) ? Settings::bars($cfg) : $f['bars']);
		$rows = [];
		foreach ($clients as $mid => $c) {
			['cells' => $cells, 'flags' => $flags, 'sort' => $sort, 'export' => $export] = $report->cells((string) $mid, $c, $visible, $catalogue, $widgetBars);
			// Storage full within 30 days: the forecast item of the master host, when it is part of Needs attention.
			$flags += ['nodata' => 0, 'down' => 0, 'full_days' => ColumnCell::forecast(ColumnReader::value($items[$mid][Forecast::KEY] ?? null), $cfg)['days']];
			$rows[] = [
				'client' => $c['name'], 'type' => $c['type'], 'hostid' => (string) $mid, 'cells' => $cells,
				'attention' => ColumnCell::attention($flags, $view['attention']), 'flags' => $flags,
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
		$data['export'] = ['headers' => $report->exportHeaders($visible, $catalogue), 'rows' => array_column($rows, 'export'), 'types' => array_column($rows, 'type')];
		foreach ($data['rows'] as &$row) {
			unset($row['export'], $row['sort']);
		}
		unset($row);
		$this->setResponse(new CControllerResponseData($data));
	}
}
