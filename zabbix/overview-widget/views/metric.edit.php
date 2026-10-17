<?php declare(strict_types = 0);
/**
 * The metric editor dialog (MetricEdit): one metric's settings, as Top hosts edits a column.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpOverview\Includes\Metrics;

$m = $data['metric'];
$form = (new CForm())
	->setName('ep_overview_metric')
	->addStyle('display: none;')
	->addVar('action', $data['action'])
	->addVar('update', 1)
	->addVar('uid', $m['uid']);
if ($data['edit']) {
	$form->addVar('edit', 1);
}
$form->addItem((new CSubmitButton())->addClass(ZBX_STYLE_FORM_SUBMIT_HIDDEN));

$grid = (new CFormGrid())->addClass('ep-ov-metric-form');
$hint = fn(string $text) => (new CSpan($text))->addClass('ep-ov-hint');

// Name.
$grid->addItem([
	(new CLabel(_('Name'), 'ep_ov_metric_name'))->setAsteriskMark(),
	new CFormField((new CTextBox('name', $m['name'], false, Metrics::MAX_NAME))
		->setId('ep_ov_metric_name')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)->setAriaRequired()->setAttribute('autofocus', 'autofocus'))
]);

// Source: a built-in figure, or any item key on the client's servers.
$grid->addItem([
	new CLabel(_('Source'), 'source'),
	new CFormField((new CRadioButtonList('source', (int) $m['source']))
		->addValue(_('Built-in metric'), Metrics::SOURCE_BUILTIN)
		->addValue(_('Zabbix item'), Metrics::SOURCE_ITEM)
		->setModern())
]);

$master = [];
$perServer = [];
foreach ($data['catalogue'] as $id => $b) {
	if ($b['on'] === Metrics::ON_MASTER) {
		$master[$id] = $b['label'];
	}
	else {
		$perServer[$id] = $b['label'];
	}
}
$builtin = (new CSelect('builtin'))
	->setId('ep_ov_metric_builtin')
	->setFocusableElementId('ep_ov_metric_builtin_btn')
	->setValue($m['builtin'] !== '' ? $m['builtin'] : array_key_first($master))
	->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	->addOptionGroup((new CSelectOptionGroup(_('Client master host — one value per client')))->addOptions(CSelect::createOptionsFromArray($master)))
	->addOptionGroup((new CSelectOptionGroup(_('Each server — combined per client')))->addOptions(CSelect::createOptionsFromArray($perServer)));
$grid->addItem([
	(new CLabel(_('Built-in metric'), 'ep_ov_metric_builtin_btn'))->addClass('js-builtin'),
	(new CFormField([$builtin, ' ', $hint('')->setId('ep_ov_metric_builtin_key')]))->addClass('js-builtin')
]);

// Item key, with the keys the client servers have offered as suggestions.
$coverage = $data['coverage'];
$grid->addItem([
	(new CLabel(_('Item key'), 'ep_ov_metric_key'))->setAsteriskMark()->addClass('js-item'),
	(new CFormField([
		(new CTextBox('key', $m['key'], false, Metrics::MAX_KEY))->setId('ep_ov_metric_key')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('list', 'ep_ov_metric_keys')->setAttribute('autocomplete', 'off')
			->setAttribute('placeholder', _('type to pick from the keys on client servers')),
		(new CTag('datalist', true, array_map(fn($k) => (new CTag('option', true, ''))->setAttribute('value', $k), $data['keys'])))->setId('ep_ov_metric_keys'),
		' ',
		(new CButtonLink(_('Check')))->setId('ep_ov_metric_check'),
		(new CDiv((new CCheckBox('pattern'))->setChecked((int) $m['pattern'] === 1)->setLabel(_('Key is a pattern (* wildcard)'))->setId('ep_ov_metric_pattern'))),
		(new CDiv($coverage === null || $coverage['found'] === null ? ''
			: _s('Found on %1$s of %2$s client servers. Clients without it show "not collected", never 0.', $coverage['found'], $coverage['total'])))
			->setId('ep_ov_metric_coverage')->addClass('ep-ov-hint')
	]))->addClass('js-item')
]);
// Servers: roles from Cluster Management; none ticked is every server.
$roleBoxes = [(new CCheckBox('roles_all'))->setId('ep_ov_metric_roles_all')->setLabel(_('All'))->setChecked(!$m['roles'])];
foreach ($data['roles'] as $r) {
	$roleBoxes[] = (new CCheckBox('roles[]', $r['id']))->setId('ep_ov_metric_role_'.$r['id'])->setLabel($r['label'])
		->setChecked(in_array($r['id'], $m['roles'], true))->addClass('js-role');
}
$roleBoxes[] = $hint(_('roles from Cluster Management'));
$grid->addItem([
	(new CLabel(_('Servers')))->addClass('js-servers'),
	(new CFormField((new CDiv($roleBoxes))->addClass('ep-ov-inline')))->addClass('js-servers')
]);
$grid->addItem([
	(new CLabel(_('Combine servers'), 'ep_ov_metric_combine_btn'))->addClass('js-servers'),
	(new CFormField([
		(new CSelect('combine'))->setId('ep_ov_metric_combine')->setFocusableElementId('ep_ov_metric_combine_btn')->setValue((int) $m['combine'])
			->addOptions(CSelect::createOptionsFromArray($data['combine'])),
		' ', $hint(_('one value per client'))
	]))->addClass('js-servers')
]);

// Units, decimals, short numbers.
$grid->addItem([
	new CLabel(_('Units'), 'ep_ov_metric_units'),
	new CFormField((new CDiv([
		(new CTextBox('units', $m['units'], false, Metrics::MAX_UNITS))->setId('ep_ov_metric_units')->setWidth(ZBX_TEXTAREA_TINY_WIDTH),
		new CLabel(_('Decimals'), 'ep_ov_metric_decimals'),
		(new CTextBox('decimals', $m['decimals'], false, 2))->setId('ep_ov_metric_decimals')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
			->setAttribute('placeholder', _('Settings')),
		(new CCheckBox('short'))->setChecked((int) $m['short'] === 1)->setLabel(_('Short numbers (18.4k)'))->setId('ep_ov_metric_short')
	]))->addClass('ep-ov-inline'))
]);

// Display.
$grid->addItem([
	new CLabel(_('Display'), 'display'),
	new CFormField((new CDiv([
		(new CRadioButtonList('display', (int) $m['display']))
			->addValue(_('As is'), Metrics::DISPLAY_AS_IS)
			->addValue(_('Bar'), Metrics::DISPLAY_BAR)
			->addValue(_('Indicators'), Metrics::DISPLAY_INDICATORS)
			->addValue(_('Sparkline'), Metrics::DISPLAY_SPARKLINE)
			->setModern(),
		(new CLabel(_('Bar min'), 'ep_ov_metric_min'))->addClass('js-bar'),
		(new CTextBox('min', $m['min']))->setId('ep_ov_metric_min')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)->setAttribute('placeholder', '0')->addClass('js-bar'),
		(new CLabel(_('max'), 'ep_ov_metric_max'))->addClass('js-bar'),
		(new CTextBox('max', $m['max']))->setId('ep_ov_metric_max')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)->setAttribute('placeholder', _('auto'))->addClass('js-bar')
	]))->addClass('ep-ov-inline'))
]);

// Trend columns.
$grid->addItem([
	new CLabel(_('Trend columns')),
	new CFormField((new CDiv([
		(new CCheckBox('trend_avg'))->setChecked((int) $m['trend_avg'] === 1)->setLabel(_('Avg'))->setId('ep_ov_metric_trend_avg'),
		(new CCheckBox('trend_min'))->setChecked((int) $m['trend_min'] === 1)->setLabel(_('Min'))->setId('ep_ov_metric_trend_min'),
		(new CCheckBox('trend_max'))->setChecked((int) $m['trend_max'] === 1)->setLabel(_('Max'))->setId('ep_ov_metric_trend_max'),
		(new CRadioButtonList('window_own', (int) $m['window'] > 0 ? 1 : 0))
			->addValue(_s('Widget window (%1$s days)', $data['trend_days']), 0)
			->addValue(_('Own:'), 1)
			->setModern()
			->setId('ep_ov_metric_window_own'),
		(new CTextBox('window', (int) $m['window'] > 0 ? (string) $m['window'] : '30', false, 3))->setId('ep_ov_metric_window')
			->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH),
		new CSpan(_('days'))
	]))->addClass('ep-ov-inline'))
]);

// Worse when.
$grid->addItem([
	new CLabel(_('Worse when'), 'worse'),
	new CFormField((new CDiv([
		(new CRadioButtonList('worse', (int) $m['worse']))
			->addValue(_('Higher'), Metrics::WORSE_HIGHER)
			->addValue(_('Lower'), Metrics::WORSE_LOWER)
			->setModern(),
		$hint(_('lower: a value falling to a threshold is the alarm, as for ingest'))
	]))->addClass('ep-ov-inline'))
]);

// Thresholds: ElasticPro Settings for a built-in usage figure, or the metric's own list.
$thTable = (new CTable())
	->setId('ep_ov_metric_thresholds')
	->addClass(ZBX_STYLE_TABLE_FORMS)
	->setHeader([_('Colour'), _('Level'), (new CColHeader(new CSpan(_('Value'))))->setId('ep_ov_metric_th_head'), _('Action')])
	->setFooter(new CRow((new CCol((new CButtonLink(_('Add threshold')))->addClass('element-table-add')))->setColSpan(4)));
$thTemplate = (new CTemplateTag('ep-ov-threshold-row'))->addItem((new CRow([
	(new CColor('thresholds[#{rowNum}][color]', '#{color}'))->appendColorPickerJs(false),
	(new CSelect('thresholds[#{rowNum}][level]'))->setValue('#{level}')->addOptions(CSelect::createOptionsFromArray([
		Metrics::LEVEL_WARN => _('Warn'), Metrics::LEVEL_HIGH => _('High')
	])),
	(new CTextBox('thresholds[#{rowNum}][value]', '#{value}', false))->setWidth(ZBX_TEXTAREA_TINY_WIDTH)->setAriaRequired(),
	(new CButton('thresholds[#{rowNum}][remove]', _('Remove')))->addClass(ZBX_STYLE_BTN_LINK)->addClass('element-table-remove')
]))->addClass('form_row'));
$grid->addItem([
	new CLabel(_('Thresholds')),
	new CFormField([
		(new CDiv((new CCheckBox('use_settings'))->setChecked((int) $m['use_settings'] === 1)->setId('ep_ov_metric_use_settings')
			->setLabel(_('Use ElasticPro Settings (only for built-in usage metrics)'))))->addClass('ep-ov-inline'),
		(new CDiv([
			new CLabel(_('Warn'), 'ep_ov_metric_warn'),
			(new CTextBox('warn', $m['warn'], false, 6))->setId('ep_ov_metric_warn')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
				->setAttribute('placeholder', _s('Settings: %1$s', Metrics::num((float) $data['usage']['warn']))),
			new CLabel(_('High'), 'ep_ov_metric_high'),
			(new CTextBox('high', $m['high'], false, 6))->setId('ep_ov_metric_high')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
				->setAttribute('placeholder', _s('Settings: %1$s', Metrics::num((float) $data['usage']['high']))),
			$hint(_('%, empty follows ElasticPro → Settings; a client\'s own thresholds on its master host still win'))
		]))->addClass('ep-ov-inline')->setId('ep_ov_metric_settings_values'),
		(new CDiv([$thTable, $thTemplate]))->setId('ep_ov_metric_own')->addClass('table-forms-separator')->addStyle('min-width: '.ZBX_TEXTAREA_STANDARD_WIDTH.'px')
	])
]);

// No data, stale.
$grid->addItem([
	new CLabel(_('No data'), 'nodata'),
	new CFormField((new CDiv([
		(new CRadioButtonList('nodata', (int) $m['nodata']))
			->addValue(_('Show "not collected"'), Metrics::NODATA_SHOW)
			->addValue(_('Hide the row for that client'), Metrics::NODATA_HIDE)
			->setModern(),
		new CLabel(_('Stale after'), 'ep_ov_metric_stale'),
		(new CTextBox('stale', $m['stale'], false, 5))->setId('ep_ov_metric_stale')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
			->setAttribute('placeholder', (string) $data['stale_after']),
		new CSpan(_('min'))
	]))->addClass('ep-ov-inline'))
]);

// Click opens.
$grid->addItem([
	new CLabel(_('Click opens'), 'ep_ov_metric_click_btn'),
	new CFormField((new CSelect('click'))->setId('ep_ov_metric_click')->setFocusableElementId('ep_ov_metric_click_btn')->setValue((int) $m['click'])
		->addOptions(CSelect::createOptionsFromArray([
			Metrics::CLICK_GRAPH => _('Graph of this item for the client'),
			Metrics::CLICK_LATEST => _('Latest data, filtered to the client'),
			Metrics::CLICK_NONE => _('Nothing')
		])))
]);

$catalogue = array_map(fn($b) => ['label' => $b['label'], 'on' => $b['on'], 'key' => $b['key'], 'units' => $b['units'], 'settings' => $b['settings']], $data['catalogue']);
$form
	->addItem($grid)
	->addItem((new CScriptTag('ep_overview_metric_form.init('.json_encode([
		'form_name' => $form->getName(),
		'thresholds' => array_map(fn($t) => ['level' => (string) $t['level'], 'color' => (string) $t['color'], 'value' => (string) $t['value']], $m['thresholds']),
		'catalogue' => $catalogue,
		'decimals' => $data['decimals'],
		'coverage_url' => (new CUrl('zabbix.php'))->setArgument('action', $data['action'])->setArgument('coverage', 1)->getUrl()
	], JSON_THROW_ON_ERROR).');'))->setOnDocumentReady());

$output = [
	'header' => $data['edit'] ? _s('Metric — %1$s', $m['name']) : _('New metric'),
	'script_inline' => $this->readJsFile('metric.edit.js.php', null, ''),
	'body' => $form->toString(),
	'buttons' => [[
		'title' => $data['edit'] ? _('Update') : _('Add'),
		'keepOpen' => true,
		'isSubmit' => true,
		'action' => '$(document.forms.ep_overview_metric).trigger("process.form", [overlay])'
	]]
];
if ($data['user']['debug_mode'] == GROUP_DEBUG_MODE_ENABLED) {
	CProfiler::getInstance()->stop();
	$output['debug'] = CProfiler::getInstance()->make()->toString();
}
echo json_encode($output, JSON_THROW_ON_ERROR);
