<?php declare(strict_types = 0);

namespace Modules\EpTophosts\Actions;

foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'ClientData', 'Bars', 'Forecast', 'Columns', 'ColumnMath', 'ColumnReader', 'ColumnCell', 'ColumnReport'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}
foreach (['CWidgetFieldStrings', 'CWidgetFieldOptionalNumber', 'CWidgetFieldColumns', 'Catalogue', 'WidgetForm'] as $inc) {
	require_once __DIR__.'/../includes/'.$inc.'.php';
}

use CControllerDashboardWidgetView,
	CControllerResponseData;
use Modules\EpTophosts\Includes\{Catalogue, WidgetForm};
use Modules\EpTophosts\Lib\{Bars, ClientData, ColumnCell, ColumnMath, ColumnReport, ColumnSettings, Columns, Roles, Settings};

/**
 * Top hosts: the busiest client servers by the figure the widget ranks by — CPU, memory, disk used,
 * JVM heap or any Zabbix item, now or its average or maximum over the window — drawn as a list with
 * bars, a table or a bar chart. The clients and their servers are ClientData's (the same reading
 * Client resources expands into), the figures are the column model's (ColumnReport: one item.get
 * for every key on every server, one trend.get per window), and each server is judged at its own
 * client's thresholds, so a host is the same colour here as in Client resources.
 *
 * Every metric the widget offers is ranked and drawn on the page (a pane each), so switching on
 * the widget costs no trip to Zabbix. A server with no figure for the metric is left out of its
 * ranking — unknown is not zero.
 */
class WidgetView extends CControllerDashboardWidgetView {

	protected function doAction(): void {
		$cfg = Settings::all();
		$roles = Roles::load();
		$f = $this->fields_values;
		$now = time();

		$catalogue = Catalogue::columns($roles);
		$columns = $this->columns($f, $catalogue);
		[$list, $dropped] = $columns;
		$visible = array_values(array_filter($list, fn($c) => !$c['hidden']));
		$report = new ColumnReport($cfg, $now, $f['usage_warn'] ?? '', $f['usage_high'] ?? '', null, true);

		// What is ranked: the metric the widget is set to, then the others a viewer may switch to.
		$main = Catalogue::RANKS[(int) ($f['rank'] ?? 0)] ?? 'cpu';
		$offered = array_values(array_unique(array_merge([$main], array_intersect(WidgetForm::SWITCH, $f['switch_metrics'] ?? WidgetForm::SWITCH_DEFAULT))));
		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'view' => (int) ($f['view'] ?? WidgetForm::VIEW_LIST),
			'rank' => $main,
			'metrics' => [],
			'panes' => [],
			'columns' => array_map(fn($c) => $report->head($c, $catalogue), $visible),
			'count' => max(1, min(100, (int) ($f['count'] ?? 10))),
			'scope' => '',
			'dropped' => $dropped,
			'palette' => Settings::paletteStyle($cfg),
			'error' => null,
			'user' => ['debug_mode' => $this->getDebugMode()]
		];
		foreach ($offered as $id) {
			$data['metrics'][] = ['id' => $id, 'label' => Catalogue::metric($id, $f)['label']];
		}

		[, $clients, $error] = ClientData::clients($cfg, $f['groupids'] ?? []);
		if ($error !== null) {
			$data['error'] = $error;
			$this->setResponse(new CControllerResponseData($data));
			return;
		}
		$want = array_map('mb_strtolower', $f['clients'] ?? []);
		$types = $f['client_types'] ?? [];
		$roleIds = $f['roles'] ?? [];
		$data['scope'] = implode(' · ', array_filter([implode(', ', $f['clients'] ?? []), implode(', ', $types),
			implode(', ', array_map(fn($id) => Roles::byId($roles)[$id]['label'] ?? $id, $roleIds))]));
		if ($want && !array_filter($clients, fn($c) => in_array(mb_strtolower($c['name']), $want, true))) {
			$data['error'] = count($want) === 1
				? _s('No client named "%1$s" here. Check the name in ElasticPro → Clients, or change the Clients setting.', $f['clients'][0])
				: _('None of the clients this widget names is here. Check the names in ElasticPro → Clients, or change the Clients setting.');
			$this->setResponse(new CControllerResponseData($data));
			return;
		}
		$masters = [];
		foreach ($clients as $mid => $c) {
			if ((!$want || in_array(mb_strtolower($c['name']), $want, true)) && (!$types || in_array($c['type'], $types, true))) {
				$masters[$c['name']] = ['mid' => (string) $mid] + $c;
			}
		}

		// Every server of the clients listed, in the roles asked for — the figures are read below, once.
		$rowsIn = [];
		foreach (ClientData::servers($roles, $masters, false) as $client => $list2) {
			$c = $masters[$client] ?? null;
			foreach ($list2 as $s) {
				if ($c === null || ($roleIds && !array_intersect($roleIds, $s['roles']))) {
					continue;
				}
				$rowsIn[$s['hostid']] = [
					'client' => ['name' => $s['host'], 'host' => $s['host'], 'hostid' => $s['hostid'], 'client' => $client, 'type' => $c['type'], 'role' => $s['role'],
						'client_role' => $client.' · '.$s['role'], 'ip' => $s['ip'], 'status' => [ 'up' => _('up'), 'down' => _('down'), 'unknown' => _('no data')][$s['status']] ?? '',
						'rank' => '', 'value' => '', 'macros' => $c['macros'], 'mid' => $c['mid']],
					'servers' => [$s]
				];
			}
		}

		// The Value column's look, and the figure each metric makes of it.
		$valueCol = null;
		foreach ($list as $c) {
			if ($c['builtin'] === 'top.value' && (int) $c['source'] === Columns::SOURCE_BUILTIN) {
				$valueCol = $c;
			}
		}
		$valueCol ??= ColumnSettings::builtinColumn('top.value', $catalogue['top.value']);
		$special = ['top.rank', 'top.host', 'top.value'];
		$extras = array_values(array_filter($visible, fn($c) => !in_array($c['builtin'], $special, true) || (int) $c['source'] !== Columns::SOURCE_BUILTIN));
		$metricCols = [];
		foreach ($offered as $id) {
			$metricCols[$id] = array_replace($valueCol, Catalogue::metric($id, $f)['column'], ['uid' => 'm.'.$id, 'name' => Catalogue::metric($id, $f)['label'], 'hidden' => 0]);
		}
		$all = array_merge(array_values($metricCols), $extras);

		$report->read($rowsIn, $all, $catalogue, (int) ($f['trend_days'] ?? 7));
		// An item's figure is judged at Settings' thresholds only when it is a percentage; otherwise at the Value column's own.
		$units = $report->units();
		foreach ($metricCols as $id => $mc) {
			if (($id === 'item' || $id === 'jvm') && ($units[$mc['uid']] ?? '') !== '%') {
				$metricCols[$id]['use_settings'] = 0;
			}
		}
		$all = array_merge(array_values($metricCols), $extras);

		$widgetBars = Bars::styleOf(Bars::isInherit($f['bars'] ?? Bars::INHERIT) ? Settings::bars($cfg) : $f['bars']);
		$found = [];
		foreach ($rowsIn as $hostid => $in) {
			$found[$hostid] = $report->cells((string) $hostid, $in['client'], $all, $catalogue, $widgetBars);
		}

		foreach ($offered as $id) {
			$uid = $metricCols[$id]['uid'];
			$ranked = [];
			foreach ($found as $hostid => $res) {
				$v = $res['sort'][$uid] ?? null;
				if ($v !== null) {
					$ranked[] = ['hostid' => $hostid, 'value' => (float) $v];
				}
			}
			usort($ranked, fn($a, $b) => $b['value'] <=> $a['value'] ?: strnatcasecmp($rowsIn[$a['hostid']]['client']['host'], $rowsIn[$b['hostid']]['client']['host']));
			$ranked = array_slice($ranked, 0, $data['count']);
			$top = $ranked ? max(array_column($ranked, 'value')) : null;
			$pane = ['id' => $id, 'label' => Catalogue::metric($id, $f)['label'], 'rows' => []];
			foreach ($ranked as $i => $r) {
				$in = $rowsIn[$r['hostid']];
				$client = $in['client'];
				$res = $found[$r['hostid']];
				$byUid = [];
				foreach ($all as $k => $col) {
					$byUid[$col['uid']] = $res['cells'][$k];
				}
				$valueCell = $byUid[$uid];
				$cells = [];
				foreach ($visible as $col) {
					$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$col['builtin']] ?? null) : null;
					$layout = ['layout' => ColumnCell::layout($col)];
					if ($entry !== null && $col['builtin'] === 'top.rank') {
						$cells[] = ColumnCell::cell($col, $entry, ['text' => (string) ($i + 1)], $cfg) + $layout;
					}
					elseif ($entry !== null && $col['builtin'] === 'top.host') {
						$cells[] = ColumnCell::cell($col, $entry, ['text' => $client['host'],
							'url' => ColumnMath::clickUrl((int) $col['click'], [], [$client['hostid']], $client['mid'])], $cfg) + $layout;
					}
					elseif ($entry !== null && $col['builtin'] === 'top.value') {
						$cells[] = $valueCell + $layout;
					}
					else {
						$cells[] = $byUid[$col['uid']];
					}
				}
				$extraCells = [];
				foreach ($extras as $col) {
					if ($col['builtin'] !== 'top.clientrole' || (int) $col['source'] !== Columns::SOURCE_BUILTIN) {
						$extraCells[] = ['name' => $col['name'], 'cell' => $byUid[$col['uid']]];
					}
				}
				$pane['rows'][] = [
					'id' => $id.'.'.$i, 'type' => $client['type'], 'attention' => false, 'host' => $client['host'], 'hostid' => $client['hostid'],
					'url' => ColumnMath::clickUrl(Columns::CLICK_LATEST, [], [$client['hostid']]), 'client_role' => $client['client_role'],
					'rank' => $i + 1, 'value' => $valueCell, 'extras' => $extraCells, 'cells' => $cells,
					'share' => ColumnMath::barShare($r['value'], (string) ($units[$uid] ?? '%'), '', '', $top)
				];
			}
			$data['panes'][$id] = $pane;
		}
		$this->setResponse(new CControllerResponseData($data));
	}

	/**
	 * The columns in force: this widget's own, through the column rule leniently — what no longer
	 * passes is left out and named.
	 *
	 * @return array{0: array, 1: string[]}  [columns, dropped]
	 */
	private function columns(array $f, array $catalogue): array {
		$own = array_values(array_filter((array) ($f['columns'] ?? []), 'is_array'));
		$list = ColumnSettings::resolve($own ?: Catalogue::defaultColumns($catalogue), $catalogue, null, true);
		[$columns, , $dropped] = Columns::cleanList($list, $catalogue, [], false);
		if (isset($this->form)) {
			$field = $this->form->getFields()['columns'] ?? null;
			if ($field !== null && method_exists($field, 'getDropped')) {
				$dropped = array_merge($field->getDropped(), $dropped);
			}
		}

		return [$columns, $dropped];
	}
}
