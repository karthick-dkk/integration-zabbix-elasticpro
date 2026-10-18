<?php declare(strict_types = 0);
/**
 * Client overview settings, on two tabs: Data and metrics, Layout and production.
 *
 * Metrics are listed here and edited in their own dialog (MetricEdit), as Top hosts edits its
 * columns; each row carries the metric as hidden fields, and the form posts them in the order
 * the rows were dragged into. An empty Warn or High follows ElasticPro -> Settings, and the list
 * says so with the value that applies.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpOverview\Includes\{Chart, Metrics, Overview, WidgetForm};
use Modules\EpOverview\Lib\{ClientData, Roles, Settings};

$fields = $data['fields'];
$cfg = Settings::all();
$roles = Roles::load();
$allRoles = Roles::byId($roles);
$catalogue = $fields['metrics']->getCatalogue();
$usage = Settings::usage($cfg);

$form = new CWidgetFormView($data);
$hint = fn(string $text) => (new CSpan($text))->addClass('ep-ov-hint');
$row = fn(CFormGrid $grid, $label, $field, ?string $class = null) => $grid->addItem([
	$label instanceof CTag ? $label->addClass($class) : $label,
	($field instanceof CFormField ? $field : new CFormField($field))->addClass($class)
]);
$view = fn($field_view) => $form->registerField($field_view);

/** A list of checkboxes posting name[] — with an empty first entry, so none ticked still says so. */
$boxes = function (string $name, array $options, array $checked, string $class = '') {
	$out = [(new CVar($name.'[]', ''))->removeId()];
	foreach ($options as $value => $label) {
		$out[] = (new CSpan((new CCheckBox($name.'[]', (string) $value))
			->setId(zbx_formatDomId($name.'_'.md5((string) $value)))
			->setLabel($label)
			->setChecked(in_array((string) $value, array_map('strval', $checked), true))))->addClass('ep-ov-box');
	}
	return (new CDiv($out))->addClass('ep-ov-inline')->addClass($class);
};

/* ----------------------------------- Data and metrics ----------------------------------- */

$dataGrid = new CFormGrid();
$groups = $view(new CWidgetFieldMultiSelectGroupView($fields['groupids']));
$row($dataGrid, $groups->getLabel(), $groups->getView());

// Clients: the ones ClientData knows; a name saved earlier that is no longer there stays ticked, marked.
$known = [];
try {
	[, $clients] = ClientData::clients($cfg, [], true);
	foreach ($clients as $c) {
		$known[$c['name']] = $c['name'].($c['status'] !== 'active' ? ' ('.$c['badge'].')' : '');
	}
	uksort($known, 'strnatcasecmp');
}
catch (Throwable $e) {
	$known = [];
}
$picked = $fields['clients']->getValue();
foreach ($picked as $name) {
	if (!array_key_exists($name, $known)) {
		$known[$name] = _s('%1$s (not found)', $name);
	}
}
$hideInactive = $view(new CWidgetFieldCheckBoxView($fields['hide_inactive']));
$row($dataGrid, new CLabel(_('Clients'), 'ep_ov_clients_filter'), [
	(new CDiv([
		(new CTextBox('ep_ov_clients_filter', '', false))->setId('ep_ov_clients_filter')->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			->setAttribute('placeholder', _('type to filter'))->setAttribute('data-no-trim', '1')->removeAttribute('name'),
		$hint($picked ? _n('%1$s client picked', '%1$s clients picked', count($picked))
			: _s('All clients · tick to pick from %1$s', count($known)))->setId('ep_ov_clients_note')
	]))->addClass('ep-ov-inline'),
	$boxes('clients', $known, $picked, 'ep-ov-clients'),
	$hideInactive->getView()
]);

$types = Settings::types($cfg)['all'];
$row($dataGrid, new CLabel(_('Client types')), [
	$boxes('client_types', array_combine($types, $types), $fields['client_types']->getValue()),
	$hint(_('none ticked: every type'))
]);

$trendSource = $view(new CWidgetFieldRadioButtonListView($fields['trend_source']));
$trendDays = $view(new CWidgetFieldIntegerBoxView($fields['trend_days']));
$row($dataGrid, new CLabel(_('Trend window')), (new CDiv([
	$trendSource->getView(), $trendDays->getView(), new CSpan(_('days')),
	$hint(_('avg, min and max for every metric use this window, from Zabbix trends'))
]))->addClass('ep-ov-inline'), 'ep-ov-cards-only');

// The metrics list.
$metricsTable = (new CTable())
	->setId('ep-ov-metrics')
	->addClass('ep-ov-metrics')
	->setHeader(['', _('Name'), _('Item'), _('Servers'), _('Value'), _('Trend'), _('Warn / High'), _('Action')]);
foreach ($fields['metrics']->getValue() as $i => $m) {
	$b = (int) $m['source'] === Metrics::SOURCE_BUILTIN ? ($catalogue[$m['builtin']] ?? null) : null;
	$master = $b !== null && $b['on'] === Metrics::ON_MASTER;
	$hidden = [(new CVar('sortorder[metrics][]', $i))->removeId()];
	foreach ($m as $k => $v) {
		if ($k === 'roles') {
			foreach (array_values($v) as $j => $r) {
				$hidden[] = (new CVar("metrics[$i][roles][$j]", $r))->removeId();
			}
		}
		elseif ($k === 'thresholds') {
			foreach (array_values($v) as $j => $t) {
				foreach (['level', 'color', 'value'] as $tk) {
					$hidden[] = (new CVar("metrics[$i][thresholds][$j][$tk]", (string) $t[$tk]))->removeId();
				}
			}
		}
		else {
			$hidden[] = (new CVar("metrics[$i][$k]", (string) $v))->removeId();
		}
	}
	$item = $b !== null ? $b['key'] : $m['key'].((int) $m['pattern'] === 1 ? ' '._('(pattern)') : '');
	$servers = $master ? _('Client master') : ($m['roles']
		? implode(', ', array_map(fn($r) => $allRoles[$r]['label'] ?? $r, $m['roles'])) : _('All'));
	$trend = implode(' · ', array_keys(array_filter(['avg' => $m['trend_avg'], 'min' => $m['trend_min'], 'max' => $m['trend_max']])));
	$trend = $trend === '' ? '—' : $trend.($m['window'] > 0 ? ' ('._s('%1$s d', $m['window']).')' : '');
	if ((int) $m['use_settings'] === 1) {
		$inherited = $m['warn'] === '' || $m['high'] === '';
		$th = [Metrics::num($m['warn'] === '' ? (float) $usage['warn'] : (float) $m['warn']).' / '
			.Metrics::num($m['high'] === '' ? (float) $usage['high'] : (float) $m['high']),
			$inherited ? (new CSpan(_('Settings')))->addClass('ep-ov-tag') : null];
	}
	elseif ($m['thresholds']) {
		$th = [implode(' · ', array_map(fn($t) => ((int) $t['level'] === Metrics::LEVEL_HIGH ? 'H ' : 'W ').$t['value'], $m['thresholds'])),
			(int) $m['worse'] === Metrics::WORSE_LOWER ? (new CSpan(_('lower is worse')))->addClass('ep-ov-tag') : null];
	}
	else {
		$th = (new CSpan(_('none')))->addClass('ep-ov-hint');
	}
	$metricsTable->addRow([
		(new CCol((new CDiv())->addClass(ZBX_STYLE_DRAG_ICON)))->addClass(ZBX_STYLE_TD_DRAG_ICON),
		(new CDiv([$m['name'], (int) $m['source'] === Metrics::SOURCE_ITEM ? (new CSpan(_('custom')))->addClass('ep-ov-tag') : null]))->addClass('text'),
		(new CDiv($item))->addClass('ep-ov-mono')->setTitle($item),
		$servers,
		$master ? _('Client value') : Metrics::combineSummary((int) $m['combine']),
		$trend,
		$th,
		(new CList([
			(new CButton('edit', _('Edit')))->addClass(ZBX_STYLE_BTN_LINK)->removeId(),
			(new CButton('remove', _('Remove')))->addClass(ZBX_STYLE_BTN_LINK)->removeId(),
			$hidden
		]))->addClass(ZBX_STYLE_HOR_LIST)
	]);
}
$count = count($fields['metrics']->getValue());
$metricsTable->addRow((new CCol([
	(new CButton('add', _('Add metric')))->addClass(ZBX_STYLE_BTN_LINK)->setEnabled($count < Metrics::MAX),
	' ',
	$hint(_s('Built-in metrics or any Zabbix item key, up to %1$s. Drag rows to reorder. A blank Warn or High uses ElasticPro Settings.', Metrics::MAX))
]))->setColSpan(8));
// Both views read the list: the Cards draw it, and the Summary view's usage chart offers it in its metric menu
// (after CPU, memory and ES storage), coloured at each metric's Warn / High — so it stays where it is edited.
$row($dataGrid, new CLabel(_('Metrics')), (new CFormField([$metricsTable,
	(new CDiv(_('Summary view: the usage chart offers these metrics in its metric menu, after CPU, memory and ES storage, and colours its legend at each one\'s Warn / High.')))
		->addClass('ep-ov-hint ep-ov-hint-block ep-ov-summary-only')
]))->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR));

// Order clients by: any metric now or its trend max, problems, days until full, contract end, name.
$windowLabel = (int) $fields['trend_source']->getValue() === WidgetForm::TREND_DAYS ? $fields['trend_days']->getValue().'d' : _('period');
$orders = ['name' => _('Client name'), 'problems' => _('Open problems (worst severity)'), 'full' => _('Days until full (used of allocated)'),
	'contract' => _('Contract end date')];
foreach ($fields['metrics']->getValue() as $m) {
	$orders['m:'.$m['uid'].':now'] = _s('%1$s — now', $m['name']);
	$orders['m:'.$m['uid'].':max'] = _s('%1$s — %2$s max', $m['name'], $m['window'] > 0 ? $m['window'].'d' : $windowLabel);
}
$orderBy = $fields['order_by']->getValue();
$orderDir = $view(new CWidgetFieldSelectView($fields['order_dir']));
$row($dataGrid, new CLabel(_('Order clients by'), 'ep_ov_order_by_btn'), (new CDiv([
	(new CSelect('order_by'))->setId('ep_ov_order_by')->setFocusableElementId('ep_ov_order_by_btn')
		->setValue(array_key_exists($orderBy, $orders) ? $orderBy : 'name')->addOptions(CSelect::createOptionsFromArray($orders)),
	$orderDir->getView(),
	new CSpan(_('Show top')),
	(new CTextBox('top_n', $fields['top_n']->getValue(), false, 4))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)->setAttribute('placeholder', _('all')),
	$hint(_('clients (empty shows all)'))
]))->addClass('ep-ov-inline'), 'ep-ov-cards-only');

$only = $view(new CWidgetFieldRadioButtonListView($fields['only_show']));
$row($dataGrid, $only->getLabel(), $only->getView(), 'ep-ov-cards-only');

/* ------------------------------- Layout and production -------------------------------- */

// Sections: on, off and order.
$sectionNames = [
	'summary' => [_('Summary bar'), _('totals across clients, off by default')],
	'header' => [_('Card header'), _('type and contract badge')],
	'problems' => [_('Problems by severity'), _('links to Problems')],
	'metrics' => [_('Metrics'), _('now and trend columns')],
	'used' => [_('Used of allocated'), _('with forecast')],
	'roles' => [_('Servers by role'), _('down in red')],
	'warnings' => [_('Warnings'), _('stale, unreachable, not collected')]
];
$order = array_values(array_unique(array_merge(array_intersect($fields['section_order']->getValue(), Overview::SECTIONS), Overview::SECTIONS)));
$on = $fields['sections']->getValue();
$sections = (new CTable())->setId('ep-ov-sections')->addClass('ep-ov-sortlist');
foreach ($order as $id) {
	$sections->addRow([
		(new CCol((new CDiv())->addClass(ZBX_STYLE_DRAG_ICON)))->addClass(ZBX_STYLE_TD_DRAG_ICON),
		[(new CVar('section_order[]', $id))->removeId(),
			(new CCheckBox('sections[]', $id))->setId('ep_ov_section_'.$id)->setLabel($sectionNames[$id][0])->setChecked(in_array($id, $on, true))],
		$hint($sectionNames[$id][1])
	]);
}
$columns = $view(new CWidgetFieldRadioButtonListView($fields['columns']));
$cardWidth = $view(new CWidgetFieldIntegerBoxView($fields['card_width']));
$density = $view(new CWidgetFieldRadioButtonListView($fields['density']));
$sectionsGrid = new CFormGrid();
$row($sectionsGrid, new CLabel(_('Sections')), [(new CVar('sections[]', ''))->removeId(), $sections, $hint(_('Drag to reorder.'))]);
$row($sectionsGrid, $columns->getLabel(), $columns->getView());
$row($sectionsGrid, $cardWidth->getLabel(), (new CDiv([new CSpan(_('at least')), $cardWidth->getView(),
	$hint(_('px (Auto fits as many as the widget holds)'))]))->addClass('ep-ov-inline'));
$row($sectionsGrid, $density->getLabel(), $density->getView());

// Used of allocated and forecast.
$measures = ['storage' => _('ES storage (cluster disk)')];
foreach ($roles['families'] as $fam) {
	$measures['fam.'.$fam['id'].'.disk'] = _s('%1$s disk (role mounts)', $fam['label']);
	$measures['fam.'.$fam['id'].'.mem'] = _s('%1$s memory', $fam['label']);
}
$measures['devices'] = _('Devices seen of purchased');
$measure = $fields['used_measure']->getValue();
$usedShow = $view(new CWidgetFieldRadioButtonListView($fields['used_show']));
$usedStyle = $view(new CWidgetFieldSelectView($fields['used_style']));
$fcDays = $view(new CWidgetFieldIntegerBoxView($fields['forecast_days']));
$windows = Settings::forecast($cfg);
$badge = $view(new CWidgetFieldCheckBoxView($fields['contract_badge']));
$cWarn = $view(new CWidgetFieldIntegerBoxView($fields['contract_warn']));
$cHigh = $view(new CWidgetFieldIntegerBoxView($fields['contract_high']));
$usedGrid = new CFormGrid();
$row($usedGrid, new CLabel(_('Measure'), 'ep_ov_used_measure_btn'), (new CSelect('used_measure'))->setId('ep_ov_used_measure')
	->setFocusableElementId('ep_ov_used_measure_btn')->setValue(array_key_exists($measure, $measures) ? $measure : 'storage')
	->addOptions(CSelect::createOptionsFromArray($measures)));
$row($usedGrid, $usedShow->getLabel(), (new CDiv([$usedShow->getView(), $usedStyle->getView()]))->addClass('ep-ov-inline'));
$row($usedGrid, $fcDays->getLabel(), (new CDiv([
	$fcDays->getView(), new CSpan(_('days of growth · Warn at')),
	(new CTextBox('forecast_warn', $fields['forecast_warn']->getValue(), false, 5))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
		->setAttribute('placeholder', (string) $windows['warn_days']),
	new CSpan(_('High at')),
	(new CTextBox('forecast_high', $fields['forecast_high']->getValue(), false, 5))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
		->setAttribute('placeholder', (string) $windows['bad_days']),
	new CSpan(_('days left')),
	$hint(_('empty: ElasticPro → Settings'))
]))->addClass('ep-ov-inline'));
$row($usedGrid, new CLabel(_('Contract end')), (new CDiv([
	$badge->getView(), new CSpan(_('Warn within')), $cWarn->getView(), new CSpan(_('High within')), $cHigh->getView(), new CSpan(_('days')),
	$hint(_('from the client\'s contract end date in Cluster Management'))
]))->addClass('ep-ov-inline'));

// Servers by role.
$rolesStyle = $view(new CWidgetFieldRadioButtonListView($fields['roles_style']));
$rolesServers = $view(new CWidgetFieldRadioButtonListView($fields['roles_servers']));
$downRed = $view(new CWidgetFieldCheckBoxView($fields['down_red']));
$downAfter = $view(new CWidgetFieldIntegerBoxView($fields['down_after']));
$roleOrder = array_values(array_intersect($fields['role_order']->getValue(), array_keys($allRoles)));
$roleOrder = array_merge($roleOrder, array_values(array_diff(array_keys($allRoles), $roleOrder)));
$hiddenRoles = $fields['roles_hidden']->getValue();
$roleList = (new CTable())->setId('ep-ov-roles')->addClass('ep-ov-sortlist');
foreach ($roleOrder as $id) {
	$roleList->addRow([
		(new CCol((new CDiv())->addClass(ZBX_STYLE_DRAG_ICON)))->addClass(ZBX_STYLE_TD_DRAG_ICON),
		[(new CVar('role_order[]', $id))->removeId(),
			(new CCheckBox('roles_shown[]', $id))->setId('ep_ov_role_'.$id)->setLabel($allRoles[$id]['label'])->setChecked(!in_array($id, $hiddenRoles, true))],
		$hint($allRoles[$id]['familyLabel'])
	]);
}
$rolesGrid = new CFormGrid();
$row($rolesGrid, $rolesStyle->getLabel(), $rolesStyle->getView());
$row($rolesGrid, new CLabel(_('Roles shown')), [(new CVar('roles_shown[]', ''))->removeId(), $roleList,
	$hint(_('Drag to reorder. Roles come from Cluster Management; a new role appears here on its own.'))]);
$row($rolesGrid, $rolesServers->getLabel(), $rolesServers->getView());
$row($rolesGrid, new CLabel(_('Down servers')), (new CDiv([
	$downRed->getView(), new CSpan(_('down = Zabbix agent unavailable or no data for')), $downAfter->getView(), new CSpan(_('min'))
]))->addClass('ep-ov-inline'));

// Problems and warnings.
$sevNames = [];
foreach (Overview::SEVERITIES as $s) {
	$sevNames[$s] = class_exists('CSeverityHelper') ? CSeverityHelper::getName($s) : (string) $s;
}
$ack = $view(new CWidgetFieldCheckBoxView($fields['problems_ack']));
$supp = $view(new CWidgetFieldCheckBoxView($fields['problems_suppressed']));
$zero = $view(new CWidgetFieldCheckBoxView($fields['hide_zero']));
$stale = $view(new CWidgetFieldIntegerBoxView($fields['stale_after']));
$wAgent = $view(new CWidgetFieldCheckBoxView($fields['warn_agent']));
$wNodata = $view(new CWidgetFieldCheckBoxView($fields['warn_nodata']));
$wMaint = $view(new CWidgetFieldCheckBoxView($fields['warn_maintenance']));
$problemsGrid = new CFormGrid();
$row($problemsGrid, new CLabel(_('Severities')), $boxes('severities', $sevNames, $fields['severities']->getValue()));
$row($problemsGrid, new CLabel(_('Count')), (new CDiv([$ack->getView(), $supp->getView(), $zero->getView()]))->addClass('ep-ov-inline'));
$row($problemsGrid, $stale->getLabel(), (new CDiv([new CSpan(_('flag a metric with no new value for')), $stale->getView(),
	new CSpan(_('min (a metric can override)'))]))->addClass('ep-ov-inline'));
$row($problemsGrid, new CLabel(_('Also warn')), (new CDiv([$wAgent->getView(), $wNodata->getView(), $wMaint->getView()]))->addClass('ep-ov-inline'));

// Click-through.
$linkClient = $view(new CWidgetFieldSelectView($fields['link_client']));
$linkSeverity = $view(new CWidgetFieldSelectView($fields['link_severity']));
$linkTarget = $view(new CWidgetFieldRadioButtonListView($fields['link_target']));
$clickGrid = new CFormGrid();
$row($clickGrid, $linkClient->getLabel(), $linkClient->getView());
$row($clickGrid, $linkSeverity->getLabel(), $linkSeverity->getView());
$row($clickGrid, $linkTarget->getLabel(), $linkTarget->getView());

// View: the new cards, or the Summary view the widget drew before 2.1, with its own settings.
$viewField = $view(new CWidgetFieldRadioButtonListView($fields['view']));
$families = array_combine(array_column($roles['families'], 'id'), array_column($roles['families'], 'label'));
$summaryFamily = $fields['summary_family']->getValue();
$summaryWarn = $fields['summary_warn']->getValue();
$summaryHigh = $fields['summary_high']->getValue();
$viewGrid = new CFormGrid();
$row($viewGrid, $viewField->getLabel(), [$viewField->getView(),
	(new CDiv(_('Cards: the metrics and sections set here. Summary: the overview as it was before 2.1.')))->addClass('ep-ov-hint')->addClass('ep-ov-hint-block')
]);

// Summary view, panels: on, off, order and the width each takes of a row.
$panelNames = [
	'summary' => _('Summary by client'),
	'chart' => _('Usage chart'),
	'used' => _('Used of allocated'),
	'roles' => _('Servers by role')
];
$panelsOn = $fields['summary_panels']->getValue();
$panelOrder = array_values(array_unique(array_merge(array_intersect($fields['summary_order']->getValue(), WidgetForm::PANELS), WidgetForm::PANELS)));
$panelList = (new CTable())->setId('ep-ov-panels')->addClass('ep-ov-sortlist');
foreach ($panelOrder as $id) {
	$panelList->addRow([
		(new CCol((new CDiv())->addClass(ZBX_STYLE_DRAG_ICON)))->addClass(ZBX_STYLE_TD_DRAG_ICON),
		[(new CVar('summary_order[]', $id))->removeId(),
			(new CCheckBox('summary_panels[]', $id))->setId('ep_ov_panel_'.$id)->setLabel($panelNames[$id])->setChecked(in_array($id, $panelsOn, true))],
		$view(new CWidgetFieldSelectView($fields['summary_w_'.$id]))->getView()
	]);
}
$panelsGrid = new CFormGrid();
$row($panelsGrid, new CLabel(_('Panels')), [(new CVar('summary_panels[]', ''))->removeId(), $panelList,
	$hint(_('Drag to reorder. Width sets how much of a row each panel takes; a narrow widget stacks them.'))]);

// Summary cards.
$cardsGrid = new CFormGrid();
$row($cardsGrid, new CLabel(_('Cards show'), 'ep_ov_summary_family_btn'), [
	(new CSelect('summary_family'))->setId('ep_ov_summary_family')->setFocusableElementId('ep_ov_summary_family_btn')
		->setValue(array_key_exists($summaryFamily, $families) ? $summaryFamily : 'es')->addOptions(CSelect::createOptionsFromArray($families)),
	' ', $hint(_('the family whose CPU and memory the summary cards, the usage chart and the gauges show'))
]);
$row($cardsGrid, new CLabel(_('Tiles')), $boxes('summary_tiles', ['up' => _('Servers up'), 'down' => _('Down'), 'devices' => _('Devices'),
	'problems' => _('Open problems')], $fields['summary_tiles']->getValue()));
$row($cardsGrid, new CLabel(_('Bars')), $boxes('summary_bars', ['cpu' => _('CPU'), 'mem' => _('Memory'), 'storage' => _('Storage')],
	$fields['summary_bars']->getValue()));
$row($cardsGrid, new CLabel(_('Status line')), [
	$boxes('summary_status', ['down' => _('Down'), 'high' => _('High'), 'warn' => _('Warn'), 'short' => _('Short'), 'nodata' => _('No data')],
		$fields['summary_status']->getValue()),
	$hint(_('servers down, servers high or warn on any figure, resources allocated short of what was requested, servers with no data'))
]);
$summaryColumns = $view(new CWidgetFieldRadioButtonListView($fields['summary_columns']));
$row($cardsGrid, $summaryColumns->getLabel(), $summaryColumns->getView());

// Usage chart.
$chartMetrics = ['cpu' => _('CPU (the family above)'), 'mem' => _('Memory (the family above)'), 'storage' => _('ES storage used')];
foreach ($fields['metrics']->getValue() as $m) {
	$chartMetrics['m:'.$m['uid']] = _s('Metric: %1$s', $m['name']);
}
$chartMetric = (string) $fields['chart_metric']->getValue();
$chartSwitch = $view(new CWidgetFieldCheckBoxView($fields['chart_switch']));
$periodSource = $view(new CWidgetFieldRadioButtonListView($fields['chart_period_source']));
$periodN = $view(new CWidgetFieldIntegerBoxView($fields['chart_period_n']));
$periodUnit = $view(new CWidgetFieldSelectView($fields['chart_period_unit']));
$chartQuick = $view(new CWidgetFieldCheckBoxView($fields['chart_quick']));
$chartClients = $view(new CWidgetFieldRadioButtonListView($fields['chart_clients']));
$chartTopN = $view(new CWidgetFieldIntegerBoxView($fields['chart_top_n']));
$chartTopBy = $view(new CWidgetFieldSelectView($fields['chart_top_by']));
$chartIsolate = $view(new CWidgetFieldCheckBoxView($fields['chart_isolate']));
$chartStyle = $view(new CWidgetFieldRadioButtonListView($fields['chart_style']));
$chartPoint = $view(new CWidgetFieldSelectView($fields['chart_point']));
$chartHeight = $view(new CWidgetFieldSelectView($fields['chart_height']));
$chartYaxis = $view(new CWidgetFieldRadioButtonListView($fields['chart_yaxis']));
$chartLines = $view(new CWidgetFieldCheckBoxView($fields['chart_lines']));
$chartSort = $view(new CWidgetFieldSelectView($fields['chart_sort']));
$chartColor = $view(new CWidgetFieldCheckBoxView($fields['chart_color']));
$chartGrid = new CFormGrid();
$row($chartGrid, new CLabel(_('Metric'), 'ep_ov_chart_metric_btn'), (new CDiv([
	(new CSelect('chart_metric'))->setId('ep_ov_chart_metric')->setFocusableElementId('ep_ov_chart_metric_btn')
		->setValue(array_key_exists($chartMetric, $chartMetrics) ? $chartMetric : 'cpu')->addOptions(CSelect::createOptionsFromArray($chartMetrics)),
	$chartSwitch->getView()
]))->addClass('ep-ov-inline'));
$row($chartGrid, new CLabel(_('Period')), (new CDiv([$periodSource->getView(), $periodN->getView(), $periodUnit->getView(), $chartQuick->getView()]))
	->addClass('ep-ov-inline'));
$row($chartGrid, $chartClients->getLabel(), (new CDiv([$chartClients->getView(), $chartTopN->getView(), new CSpan(_('by')), $chartTopBy->getView(),
	$chartIsolate->getView()]))->addClass('ep-ov-inline'));
$row($chartGrid, $chartStyle->getLabel(), (new CDiv([$chartStyle->getView(), new CSpan(_('Each point')), $chartPoint->getView(), new CSpan(_('Height')),
	$chartHeight->getView()]))->addClass('ep-ov-inline'));
$row($chartGrid, $chartYaxis->getLabel(), (new CDiv([$chartYaxis->getView(), $chartLines->getView()]))->addClass('ep-ov-inline'));
$row($chartGrid, new CLabel(_('Legend')), (new CDiv([
	$boxes('chart_legend', ['mean' => _('Mean'), 'min' => _('Min'), 'max' => _('Max'), 'last' => _('Last')], $fields['chart_legend']->getValue()),
	new CSpan(_('sorted by')), $chartSort->getView(), $chartColor->getView()
]))->addClass('ep-ov-inline'));

// Used of allocated.
$uaSort = $view(new CWidgetFieldSelectView($fields['ua_sort']));
$uaBar = $view(new CWidgetFieldRadioButtonListView($fields['ua_bar']));
$uaGrid = new CFormGrid();
$row($uaGrid, new CLabel(_('Groups')), $boxes('ua_groups', ['cpu' => _('CPU'), 'mem' => _('Memory'), 'storage' => _('Storage'), 'devices' => _('Devices')],
	$fields['ua_groups']->getValue()));
$row($uaGrid, $uaSort->getLabel(), $uaSort->getView());
$row($uaGrid, $uaBar->getLabel(), $uaBar->getView());

// Servers by role, and the thresholds.
$srStyle = $view(new CWidgetFieldRadioButtonListView($fields['sr_style']));
$srCount = $view(new CWidgetFieldCheckBoxView($fields['sr_count']));
$srPercent = $view(new CWidgetFieldCheckBoxView($fields['sr_percent']));
$srGrid = new CFormGrid();
$row($srGrid, $srStyle->getLabel(), $srStyle->getView());
$row($srGrid, new CLabel(_('Legend')), (new CDiv([$srCount->getView(), $srPercent->getView()]))->addClass('ep-ov-inline'));
$row($srGrid, new CLabel(_('Thresholds')), (new CDiv([
	new CSpan(_('Warn at, %')),
	(new CTextBox('summary_warn', $summaryWarn, false, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
		->setAttribute('placeholder', Metrics::num((float) $usage['warn'])),
	new CSpan(_('High at, %')),
	(new CTextBox('summary_high', $summaryHigh, false, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
		->setAttribute('placeholder', Metrics::num((float) $usage['high'])),
	$hint(_('empty: ElasticPro → Settings; a client\'s own thresholds on its master host still win'))
]))->addClass('ep-ov-inline'));

$layout = [
	$viewGrid,
	(new CFormFieldset(_('Summary view: panels — on, off, order and width'), $panelsGrid))->addClass('ep-ov-summary-only'),
	(new CFormFieldset(_('Summary cards'), $cardsGrid))->addClass('ep-ov-summary-only'),
	(new CFormFieldset(_('Usage chart'), $chartGrid))->addClass('ep-ov-summary-only'),
	(new CFormFieldset(_('Used of allocated'), $uaGrid))->addClass('ep-ov-summary-only'),
	(new CFormFieldset(_('Servers by role and thresholds'), $srGrid))->addClass('ep-ov-summary-only'),
	(new CFormFieldset(_('Sections — on, off and order'), $sectionsGrid))->addClass('ep-ov-cards-only'),
	(new CFormFieldset(_('Used of allocated and forecast'), $usedGrid))->addClass('ep-ov-cards-only'),
	(new CFormFieldset(_('Servers by role'), $rolesGrid))->addClass('ep-ov-cards-only'),
	(new CFormFieldset(_('Problems and warnings'), $problemsGrid))->addClass('ep-ov-cards-only'),
	(new CFormFieldset(_('Click-through'), $clickGrid))->addClass('ep-ov-cards-only')
];

// The id goes to the constructor: makeJavascript() initialises jQuery UI tabs on the id CTabView
// was built with (default "tabs"), and setId() alone changes only the element — which left the
// tabs as two bare links with no script behind them.
$tabs = (new CTabView(['id' => 'ep_ov_tabs']))
	->addTab('ep_ov_tab_data', _('Data and metrics'), $dataGrid)
	->addTab('ep_ov_tab_layout', _('Layout and production'), (new CDiv($layout))->addClass('ep-ov-layout'))
	->addClass('ep-ov-tabs')
	->setSelected(0);

$form
	->addVar('config_version', (string) WidgetForm::CONFIG_VERSION)
	->addVar('summary_version', (string) WidgetForm::SUMMARY_VERSION)
	->addItem($tabs)
	->addJavaScript($tabs->makeJavascript())
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_ep_overview_form.init('.json_encode([
		'templateid' => $data['templateid'],
		'trend_days' => (int) $fields['trend_days']->getValue(),
		'stale_after' => (int) $fields['stale_after']->getValue(),
		'max' => Metrics::MAX
	], JSON_THROW_ON_ERROR).');')
	->show();
