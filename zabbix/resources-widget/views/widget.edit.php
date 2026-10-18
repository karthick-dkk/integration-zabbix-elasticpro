<?php declare(strict_types = 0);
/**
 * Client resources settings, on three tabs: Data, Columns and View.
 *
 * Columns are listed here and edited in their own dialog (ColumnEdit), as Top hosts edits its
 * columns; each row carries the column as hidden fields, and the form posts them in the order the
 * rows were dragged into. While the widget follows the report defaults the list is shown as it is
 * and cannot be changed here — choosing "This widget" starts the widget's own list from them.
 * An empty Warn or High follows ElasticPro -> Settings, and the form says so with the value that
 * applies.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpResources\Includes\{Catalogue, WidgetForm};
use Modules\EpResources\Lib\{ClientData, Columns, ColumnSettings, Roles, Settings};

$fields = $data['fields'];
$cfg = Settings::all();
$roles = Roles::load();
$allRoles = Roles::byId($roles);
$usage = Settings::usage($cfg);
$model = ColumnSettings::loadModel(Catalogue::REPORT);
$catalogue = $fields['columns']->getCatalogue();
$rowCatalogue = $fields['server_columns']->getCatalogue();
$own = (int) $fields['columns_from']->getValue() === WidgetForm::FROM_WIDGET;
$columns = $own ? $fields['columns']->getValue()
	: ColumnSettings::resolve($model['columns'], $catalogue, $model['legacy'], $model['new_hidden']);
$serverColumns = $own ? $fields['server_columns']->getValue()
	: ($model['server_columns'] !== null ? ColumnSettings::resolve($model['server_columns'], $rowCatalogue, null, true) : Catalogue::serverDefaults());
$trendDays = (int) $fields['trend_days']->getValue();

$form = new CWidgetFormView($data);
$hint = fn(string $text) => (new CSpan($text))->addClass('ep-ef-hint');
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
			->setChecked(in_array((string) $value, array_map('strval', $checked), true))))->addClass('ep-ef-box');
	}
	return (new CDiv($out))->addClass('ep-ef-inline')->addClass($class);
};

/* ------------------------------------------ Data ------------------------------------------ */

$dataGrid = new CFormGrid();
$groups = $view(new CWidgetFieldMultiSelectGroupView($fields['groupids']));
$row($dataGrid, $groups->getLabel(), $groups->getView());

// Clients: the ones ClientData knows; a name saved earlier that is no longer there stays ticked, marked.
$known = [];
try {
	[, $clients] = ClientData::clients($cfg, []);
	foreach ($clients as $c) {
		$known[$c['name']] = $c['name'].($c['badge'] !== '' ? ' ('.$c['badge'].')' : '');
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
$row($dataGrid, new CLabel(_('Clients'), 'ep_res_clients_filter'), [
	(new CDiv([
		(new CTextBox('ep_res_clients_filter', '', false))->setId('ep_res_clients_filter')->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
			->setAttribute('placeholder', _('type to filter'))->setAttribute('data-no-trim', '1')->removeAttribute('name'),
		$hint($picked ? _n('%1$s client picked', '%1$s clients picked', count($picked)) : _s('All clients · tick to pick from %1$s', count($known)))
			->setId('ep_res_clients_note')
	]))->addClass('ep-ef-inline'),
	$boxes('clients', $known, $picked, 'ep-ef-clients')
]);
$types = Settings::types($cfg)['all'];
$row($dataGrid, new CLabel(_('Client types')), [
	$boxes('client_types', array_combine($types, $types), $fields['client_types']->getValue()),
	$hint(_('none ticked: every type'))
]);
$trend = $view(new CWidgetFieldIntegerBoxView($fields['trend_days']));
$row($dataGrid, $trend->getLabel(), (new CDiv([new CSpan(_('Last')), $trend->getView(), new CSpan(_('days')),
	$hint(_('a column\'s average, minimum, maximum and change are taken over this, from Zabbix trends, unless it sets its own'))
]))->addClass('ep-ef-inline'));

/* ----------------------------------------- Columns ----------------------------------------- */

/** How the list says where a column reads from. */
$sourceText = function (array $c, array $cat) {
	switch ((int) $c['source']) {
		case Columns::SOURCE_ITEM:
			return $c['key'].((int) $c['pattern'] === 1 ? ' '._('(pattern)') : '');
		case Columns::SOURCE_CALC:
			$name = fn(string $op) => strpos($op, Columns::KEY_OPERAND) === 0 ? substr($op, strlen(Columns::KEY_OPERAND)) : ($cat[$op]['key'] ?? $op);
			return $name($c['calc_a']).' '.(Columns::opLabels()[(int) $c['calc_op']] ?? '').' '.$name($c['calc_b']);
	}
	$b = $cat[$c['builtin']] ?? null;
	if ($b === null) {
		return $c['builtin'];
	}
	return $b['key'] ?? (isset($b['macro']) ? _('client macro').' '.$b['macro'] : _('client').' '.($b['field'] ?? ''));
};
$valueText = function (array $c) use ($trendDays) {
	if ((int) $c['value'] === Columns::VALUE_NOW) {
		return _('Now');
	}
	$days = $c['window'] > 0 ? (int) $c['window'] : $trendDays;
	return _s('%1$s over %2$s d', Columns::valueLabels()[(int) $c['value']] ?? '', $days);
};
$displayText = function (array $c) {
	$parts = [Columns::showLabels()[(int) $c['show']] ?? ''];
	if (in_array((int) $c['show'], [Columns::SHOW_BAR, Columns::SHOW_INDICATORS], true)) {
		$parts[] = [Columns::BAR_WIDGET => _('widget style'), Columns::BAR_UNDER => _('under value'), Columns::BAR_CELL => _('in cell'),
			Columns::BAR_SEGMENTS => _s('segments (%1$s)', $c['segments']), Columns::BAR_GAUGE => _('gauge')][(int) $c['bar_style']] ?? '';
	}
	if ((int) $c['short'] === 1) {
		$parts[] = _('short numbers');
	}
	return implode(' · ', array_filter($parts));
};
$thresholdText = function (array $c, array $cat) use ($usage) {
	$b = (int) $c['source'] === Columns::SOURCE_BUILTIN ? ($cat[$c['builtin']] ?? null) : null;
	if ($b !== null && !empty($b['text'])) {
		return '—';
	}
	if ((int) $c['use_settings'] === 1) {
		$inherited = $c['warn'] === '' || $c['high'] === '';
		return [Columns::num($c['warn'] === '' ? (float) $usage['warn'] : (float) $c['warn']).' / '
			.Columns::num($c['high'] === '' ? (float) $usage['high'] : (float) $c['high']),
			$inherited ? (new CSpan(_('Settings')))->addClass('ep-ef-tag') : null];
	}
	if ($c['thresholds']) {
		return [implode(' · ', array_map(fn($t) => ((int) $t['level'] === Columns::LEVEL_HIGH ? 'H ' : 'W ').$t['value'], $c['thresholds'])),
			(int) $c['worse'] === Columns::WORSE_LOWER ? (new CSpan(_('lower is worse')))->addClass('ep-ef-tag') : null];
	}
	return (new CSpan('—'))->addClass('ep-ef-hint');
};
/** A column's settings as hidden fields of the form, under $name[$i]. */
$hidden = function (string $name, int $i, array $c) {
	$out = [(new CVar('sortorder['.$name.'][]', $i))->removeId()];
	foreach ($c as $k => $v) {
		if ($k === 'roles') {
			foreach (array_values($v) as $j => $r) {
				$out[] = (new CVar($name."[$i][roles][$j]", $r))->removeId();
			}
		}
		elseif ($k === 'thresholds') {
			foreach (array_values($v) as $j => $t) {
				foreach (['level', 'color', 'value'] as $tk) {
					$out[] = (new CVar($name."[$i][thresholds][$j][$tk]", (string) $t[$tk]))->removeId();
				}
			}
		}
		elseif (array_key_exists($k, Columns::DEFAULT)) {
			$out[] = (new CVar($name."[$i][$k]", (string) $v))->removeId();
		}
	}
	return $out;
};

$fromField = $view(new CWidgetFieldRadioButtonListView($fields['columns_from']));
$columnsGrid = new CFormGrid();
$row($columnsGrid, $fromField->getLabel(), [
	(new CDiv([$fromField->getView(),
		(new CButtonLink(_('Reset to report defaults')))->setId('ep_res_reset')->setEnabled($own)
	]))->addClass('ep-ef-inline'),
	(new CDiv(_('Report defaults are set by a Super admin in the widget\'s Columns dialog and used by every widget that follows them.')))
		->addClass('ep-ef-hint ep-ef-hint-block')
]);

$list = (new CTable())
	->setId('ep-res-columns')
	->addClass('ep-ef-metrics')
	->addClass($own ? null : 'ep-res-readonly')
	->setHeader(['', _('Show'), _('Group'), _('Name'), _('Source'), _('Value'), _('Display'), _('Warn / High'), _('Actions')]);
foreach (array_values($columns) as $i => $c) {
	$c += Columns::DEFAULT;
	$list->addRow((new CRow([
		(new CCol($own ? (new CDiv())->addClass(ZBX_STYLE_DRAG_ICON) : ''))->addClass(ZBX_STYLE_TD_DRAG_ICON),
		(new CCheckBox('ep_show_'.$i))->setChecked((int) $c['hidden'] === 0)->removeAttribute('name')->setId('ep_res_show_'.$i)
			->addClass('js-show')->setEnabled($own)->setAttribute('aria-label', _s('Show %1$s', $c['name'])),
		$c['group'] === '' ? (new CSpan('—'))->addClass('ep-ef-hint') : $c['group'],
		(new CDiv([$c['name'], (int) $c['source'] === Columns::SOURCE_ITEM ? (new CSpan(_('custom item')))->addClass('ep-ef-tag')
			: ((int) $c['source'] === Columns::SOURCE_CALC ? (new CSpan(_('calculated')))->addClass('ep-ef-tag') : null)]))->addClass('text'),
		(new CDiv($sourceText($c, $catalogue)))->addClass('ep-ef-mono')->setTitle($sourceText($c, $catalogue)),
		$valueText($c),
		$displayText($c),
		$thresholdText($c, $catalogue),
		$own ? (new CList([
			(new CButton('edit', _('Edit')))->addClass(ZBX_STYLE_BTN_LINK)->removeId(),
			(new CButton('remove', _('Remove')))->addClass(ZBX_STYLE_BTN_LINK)->removeId(),
			$hidden('columns', $i, $c)
		]))->addClass(ZBX_STYLE_HOR_LIST) : ''
	]))->addClass((int) $c['hidden'] === 1 ? 'ep-res-off' : null)->setAttribute('data-uid', $c['uid']));
}
$count = count($columns);
if ($own) {
	$numbers = [];
	foreach (array_values($columns) as $i => $c) {
		$b = (int) $c['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$c['builtin']] ?? null) : null;
		if ($b === null || empty($b['text'])) {
			$numbers[(string) $i] = trim(($c['group'] !== '' ? $c['group'].' · ' : '').$c['name']);
		}
	}
	$list->addRow((new CCol([
		(new CButton('add', _('Add column')))->addClass(ZBX_STYLE_BTN_LINK)->setEnabled($count < Columns::MAX),
		' ',
		(new CButton('add_group', _('Add group')))->addClass(ZBX_STYLE_BTN_LINK)->setEnabled($count < Columns::MAX),
		' ',
		(new CButton('add_window', _('Add avg / min / max for a column')))->addClass(ZBX_STYLE_BTN_LINK)->setEnabled($count < Columns::MAX && $numbers),
		(new CDiv([
			(new CSelect('ep_res_window_of'))->setId('ep_res_window_of')->setFocusableElementId('ep_res_window_of_btn')
				->addOptions(CSelect::createOptionsFromArray($numbers ?: ['' => '—']))->setAttribute('aria-label', _('Column')),
			(new CCheckBox('ep_res_window_avg'))->setId('ep_res_window_avg')->setLabel(_('Avg'))->setChecked(true)->removeAttribute('name'),
			(new CCheckBox('ep_res_window_min'))->setId('ep_res_window_min')->setLabel(_('Min'))->removeAttribute('name'),
			(new CCheckBox('ep_res_window_max'))->setId('ep_res_window_max')->setLabel(_('Max'))->setChecked(true)->removeAttribute('name'),
			(new CButton('add_window_go', _('Add')))->addClass(ZBX_STYLE_BTN_ALT)->removeId(),
			$hint(_s('over the widget\'s %1$s days, in the same group, right after it', $trendDays))
		]))->addClass('ep-ef-inline ep-res-window')->setId('ep_res_window')->addStyle('display: none'),
		(new CDiv(_s('Drag rows to reorder; columns in a group stay together. New roles from Cluster Management appear here on their own, unticked. Up to %1$s columns.', Columns::MAX)))
			->addClass('ep-ef-hint ep-ef-hint-block')
	]))->setColSpan(9));
}
else {
	$list->addRow((new CCol((new CDiv(_('These are the report defaults. Choose This widget to change them for this widget alone.')))->addClass('ep-ef-hint')))->setColSpan(9));
}
$row($columnsGrid, new CLabel(_('Columns')), (new CFormField($list))->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR));

// Server rows: each column on or off, in order, each opening the same editor.
$serverList = (new CTable())->setId('ep-res-server-columns')->addClass('ep-ef-sortlist')->addClass($own ? null : 'ep-res-readonly');
foreach (array_values($serverColumns) as $i => $c) {
	$c += Columns::DEFAULT;
	$serverList->addRow((new CRow([
		(new CCol($own ? (new CDiv())->addClass(ZBX_STYLE_DRAG_ICON) : ''))->addClass(ZBX_STYLE_TD_DRAG_ICON),
		(new CCheckBox('ep_srv_'.$i))->setChecked((int) $c['hidden'] === 0)->removeAttribute('name')->setId('ep_res_srv_'.$i)->addClass('js-show')
			->setLabel($c['name'])->setEnabled($own),
		$hint($displayText($c)),
		$own ? [(new CButton('edit', _('Edit')))->addClass(ZBX_STYLE_BTN_LINK)->removeId(), $hidden('server_columns', $i, $c)] : ''
	]))->setAttribute('data-uid', $c['uid']));
}
$bars = $view(new CWidgetFieldSelectView($fields['bars']));
$warn = $fields['usage_warn']->getValue();
$high = $fields['usage_high']->getValue();
$columnsSet = new CFormGrid();
$row($columnsSet, new CLabel(_('Server rows')), [$serverList,
	$hint(_('When a client is expanded. Drag to reorder. Each opens the same column editor (bars, colours, thresholds).'))]);
$row($columnsSet, $bars->getLabel(), [$bars->getView(), ' ', $hint(_('for every bar left at "Widget default"'))]);
// Empty is ElasticPro -> Settings, and the boxes say what that is today — never a 0.
$row($columnsSet, new CLabel(_('Warn / High')), (new CDiv([
	new CSpan(_('Warn at, %')),
	(new CTextBox('usage_warn', $warn, false, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
		->setAttribute('placeholder', _s('Settings: %1$s', Columns::num((float) $usage['warn']))),
	new CSpan(_('High at, %')),
	(new CTextBox('usage_high', $high, false, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
		->setAttribute('placeholder', _s('Settings: %1$s', Columns::num((float) $usage['high']))),
	$hint(_('empty: ElasticPro → Settings, for every column using Settings; a client\'s own thresholds on its master host still win'))
]))->addClass('ep-ef-inline'));
$columnsTab = [$columnsGrid, (new CFormFieldset(_('Server rows and thresholds'), $columnsSet))];

/* ------------------------------------------ View ------------------------------------------ */

$viewField = $view(new CWidgetFieldRadioButtonListView($fields['view']));
$switch = $view(new CWidgetFieldCheckBoxView($fields['view_switch']));
$viewGrid = new CFormGrid();
$row($viewGrid, $viewField->getLabel(), (new CDiv([$viewField->getView(), $switch->getView()]))->addClass('ep-ef-inline'));

// Pie chart.
$measures = Catalogue::measures($roles, $model['split']);
$order = array_values(array_intersect($fields['pie_order']->getValue(), array_keys($measures)));
$order = array_merge($order, array_values(array_diff(array_keys($measures), $order)));
$donutsOn = $fields['pie_donuts']->getValue();
$donuts = (new CTable())->setId('ep-res-donuts')->addClass('ep-ef-sortlist');
foreach ($order as $id) {
	$donuts->addRow([
		(new CCol((new CDiv())->addClass(ZBX_STYLE_DRAG_ICON)))->addClass(ZBX_STYLE_TD_DRAG_ICON),
		[(new CVar('pie_order[]', $id))->removeId(),
			(new CCheckBox('pie_shown[]', $id))->setId(zbx_formatDomId('ep_res_donut_'.$id))->setLabel($measures[$id]['label'])->setChecked(in_array($id, $donutsOn, true))]
	]);
}
$centre = $view(new CWidgetFieldSelectView($fields['pie_centre']));
$short = $view(new CWidgetFieldRadioButtonListView($fields['pie_short']));
$perRow = $view(new CWidgetFieldSelectView($fields['pie_per_row']));
$size = $view(new CWidgetFieldSelectView($fields['pie_size']));
$chips = $view(new CWidgetFieldCheckBoxView($fields['pie_chips']));
$pieGrid = new CFormGrid();
$row($pieGrid, new CLabel(_('Donuts')), [(new CVar('pie_shown[]', ''))->removeId(), $donuts,
	$hint(_('Drag to reorder. Any figure with used, allocated and requested can be a donut.'))]);
$row($pieGrid, $centre->getLabel(), $centre->getView());
$row($pieGrid, new CLabel(_('Legend rows')), $boxes('pie_legend', ['used' => _('Used'), 'free' => _('Free'), 'short' => _('Short'),
	'allocated' => _('Allocated'), 'requested' => _('Requested'), 'pct' => _('with %')], $fields['pie_legend']->getValue()));
$row($pieGrid, $short->getLabel(), $short->getView());
$row($pieGrid, new CLabel(_('Layout')), (new CDiv([new CSpan(_('donuts per row')), $perRow->getView(), new CSpan(_('size')), $size->getView(),
	$chips->getView()]))->addClass('ep-ef-inline'));

// Table.
$serverRows = $view(new CWidgetFieldRadioButtonListView($fields['server_rows']));
$density = $view(new CWidgetFieldRadioButtonListView($fields['density']));
$groupHeaders = $view(new CWidgetFieldCheckBoxView($fields['group_headers']));
$sticky = $view(new CWidgetFieldCheckBoxView($fields['sticky_header']));
$freeze = $view(new CWidgetFieldCheckBoxView($fields['freeze_first']));
$striped = $view(new CWidgetFieldCheckBoxView($fields['striped']));
$totals = $view(new CWidgetFieldCheckBoxView($fields['totals']));
$totalsFn = $view(new CWidgetFieldSelectView($fields['totals_fn']));
$sortDir = $view(new CWidgetFieldSelectView($fields['sort_dir']));
$sortOptions = ['client' => _('Client')];
foreach ($columns as $c) {
	if ((int) ($c['hidden'] ?? 0) === 0) {
		$sortOptions[$c['uid']] = trim(($c['group'] !== '' ? $c['group'].' · ' : '').$c['name']);
	}
}
$sortBy = $fields['sort_by']->getValue();
$tableGrid = new CFormGrid();
$row($tableGrid, $serverRows->getLabel(), $serverRows->getView());
$row($tableGrid, $density->getLabel(), (new CDiv([$density->getView(), new CSpan(_('row height')),
	(new CTextBox('row_height', $fields['row_height']->getValue(), false, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
		->setAttribute('placeholder', _('auto'))->setAttribute('aria-label', _('Row height')),
	new CSpan(_('px'))]))->addClass('ep-ef-inline'));
$row($tableGrid, new CLabel(_('Header')), (new CDiv([$groupHeaders->getView(), $sticky->getView(), $freeze->getView()]))->addClass('ep-ef-inline'));
$row($tableGrid, new CLabel(_('Rows')), (new CDiv([$striped->getView(), $totals->getView(), $totalsFn->getView()]))->addClass('ep-ef-inline'));
$row($tableGrid, new CLabel(_('Sort'), 'ep_res_sort_by_btn'), (new CDiv([
	(new CSelect('sort_by'))->setId('ep_res_sort_by')->setFocusableElementId('ep_res_sort_by_btn')
		->setValue(array_key_exists($sortBy, $sortOptions) ? $sortBy : 'client')->addOptions(CSelect::createOptionsFromArray($sortOptions)),
	$sortDir->getView(),
	new CSpan(_('top')),
	(new CTextBox('top_n', $fields['top_n']->getValue(), false, 4))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)->setAttribute('placeholder', _('all'))
		->setAttribute('aria-label', _('Top N'))
]))->addClass('ep-ef-inline'));

// Toolbar.
$toolbarPos = $view(new CWidgetFieldRadioButtonListView($fields['toolbar_pos']));
$toolbarGrid = new CFormGrid();
$row($toolbarGrid, new CLabel(_('Show')), $boxes('toolbar_items', ['search' => _('Search'), 'type' => _('Type filter'), 'attention' => _('Needs attention'),
	'compact' => _('Compact'), 'view' => _('Table / Pie chart'), 'expand' => _('Expand all'), 'columns' => _('Columns dialog'),
	'export' => _('Export CSV / Excel')], $fields['toolbar_items']->getValue()));
$row($toolbarGrid, $toolbarPos->getLabel(), $toolbarPos->getView());

// Needs attention.
$attDefault = $view(new CWidgetFieldRadioButtonListView($fields['attention_default']));
$attGrid = new CFormGrid();
$row($attGrid, new CLabel(_('Any of')), $boxes('attention', ['warn' => _('Over Warn'), 'high' => _('Over High'), 'down' => _('Server down'),
	'short' => _('Short (allocated below requested)'), 'nodata' => _('No data'), 'disk30' => _('Disk full within 30 d')], $fields['attention']->getValue()));
$row($attGrid, $attDefault->getLabel(), $attDefault->getView());

$viewTab = (new CDiv([
	$viewGrid,
	(new CFormFieldset(_('Pie chart'), $pieGrid))->addClass('ep-res-pie-only'),
	(new CFormFieldset(_('Table'), $tableGrid))->addClass('ep-res-table-only'),
	new CFormFieldset(_('Toolbar on the widget'), $toolbarGrid),
	new CFormFieldset(_('Needs attention means'), $attGrid)
]))->addClass('ep-ef-layout');

// The id goes to the constructor: makeJavascript() initialises jQuery UI tabs on the id CTabView
// was built with (default "tabs"), and setId() alone changes only the element.
$tabs = (new CTabView(['id' => 'ep_res_tabs']))
	->addTab('ep_res_tab_data', _('Data'), $dataGrid)
	->addTab('ep_res_tab_columns', _('Columns'), (new CDiv($columnsTab))->addClass('ep-ef-layout'))
	->addTab('ep_res_tab_view', _('View'), $viewTab)
	->addClass('ep-ef-tabs')
	->setSelected(0);

$form
	->addItem($tabs)
	->addJavaScript($tabs->makeJavascript())
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_ep_resources_form.init('.json_encode([
		'templateid' => $data['templateid'],
		'trend_days' => $trendDays,
		'own' => $own,
		'max' => Columns::MAX
	], JSON_THROW_ON_ERROR).');')
	->show();
