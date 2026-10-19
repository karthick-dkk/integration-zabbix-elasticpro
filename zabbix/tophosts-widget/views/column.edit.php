<?php declare(strict_types = 0);
/**
 * The column editor dialog (ColumnEdit): one column's settings, in two parts — Data (group,
 * name, source, servers, value, units, no data, click) and Display and colours (show as, bar
 * style, colours and range, cell, thresholds) — with a preview of the look on sample figures.
 *
 * Copied from ../../shared/php/views by ../../sync-assets.mjs — edit it there.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpTophosts\Lib\{Columns, Settings};

$c = $data['column'];
$row = $data['scope'] === 'row';
$form = (new CForm())
	->setName('ep_column_edit')
	->addStyle('display: none;')
	->addVar('action', $data['action'])
	->addVar('update', 1)
	->addVar('scope', $data['scope'])
	->addVar('uid', $c['uid'])
	->addVar('hidden', (string) (int) $c['hidden']);
if ($data['edit']) {
	$form->addVar('edit', 1);
}
$form->addItem((new CSubmitButton())->addClass(ZBX_STYLE_FORM_SUBMIT_HIDDEN));

$hint = fn(string $text) => (new CSpan($text))->addClass('ep-ce-hint');
$inline = fn(array $items) => (new CDiv($items))->addClass('ep-ce-inline');
$color = fn(string $name, string $value, string $label) => (new CDiv((new CColor($name, ltrim($value, '#')))->appendColorPickerJs(false)))
	->addClass('ep-ce-color')->setAttribute('title', $label);

/* ------------------------------------------ Data ------------------------------------------ */

$dataGrid = new CFormGrid();
$dataGrid->addItem([
	new CLabel(_('Group'), 'ep_ce_group'),
	new CFormField((new CTextBox('group', $c['group'], false, Columns::MAX_GROUP))->setId('ep_ce_group')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		->setAttribute('placeholder', _('none: a column of its own')))
]);
$dataGrid->addItem([
	// Required for an item or a calculated column; a built-in left unnamed takes its label (the script keeps
	// the mark and the placeholder with the source chosen).
	(new CLabel(_('Name'), 'ep_ce_name'))->setAsteriskMark((int) $c['source'] !== Columns::SOURCE_BUILTIN)->setId('ep_ce_name_label'),
	new CFormField((new CTextBox('name', $c['name'], false, Columns::MAX_NAME))->setId('ep_ce_name')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		->setAriaRequired((int) $c['source'] !== Columns::SOURCE_BUILTIN)->setAttribute('autofocus', 'autofocus'))
]);

// Source: a built-in column, any item key on the client's servers, or two of those calculated.
$sources = (new CRadioButtonList('source', (int) $c['source']))->addValue(_('Built-in column'), Columns::SOURCE_BUILTIN)->setModern();
if (!$row) {
	$sources->addValue(_('Zabbix item key'), Columns::SOURCE_ITEM)->addValue(_('Calculated from two items'), Columns::SOURCE_CALC);
}
$dataGrid->addItem([new CLabel(_('Source'), 'source'), new CFormField($sources)]);

$groups = [];
foreach ($data['catalogue'] as $id => $b) {
	$where = [Columns::ON_CLIENT => _('Client'), Columns::ON_MASTER => _('Client master host — one value per client'),
		Columns::ON_SERVERS => _('Each server — combined per client'), Columns::ON_ROW => _('Server row')][$b['on']] ?? '';
	$groups[$where][$id] = trim(($b['group'] !== '' ? $b['group'].' · ' : '').$b['label']);
}
$builtin = (new CSelect('builtin'))->setId('ep_ce_builtin')->setFocusableElementId('ep_ce_builtin_btn')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
	->setValue($c['builtin'] !== '' && isset($data['catalogue'][$c['builtin']]) ? $c['builtin'] : (string) array_key_first($data['catalogue']));
foreach ($groups as $label => $options) {
	$builtin->addOptionGroup((new CSelectOptionGroup($label))->addOptions(CSelect::createOptionsFromArray($options)));
}
$dataGrid->addItem([
	(new CLabel(_('Built-in column'), 'ep_ce_builtin_btn'))->addClass('js-builtin'),
	(new CFormField([$builtin, ' ', $hint('')->setId('ep_ce_builtin_key')]))->addClass('js-builtin')
]);

// A server row reads its own figure: no item key, no calculation, no servers to pick.
if (!$row) {
$coverage = $data['coverage'];
$dataGrid->addItem([
	(new CLabel(_('Item key'), 'ep_ce_key'))->setAsteriskMark()->addClass('js-item'),
	(new CFormField([
		(new CTextBox('key', $c['key'], false, Columns::MAX_KEY))->setId('ep_ce_key')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
			->setAttribute('list', 'ep_ce_keys')->setAttribute('autocomplete', 'off')->setAttribute('placeholder', _s('type to pick from the keys on %1$s', $data['host_words']['hosts'] ?? _('client servers'))),
		(new CTag('datalist', true, array_map(fn($k) => (new CTag('option', true, ''))->setAttribute('value', $k), $data['keys'])))->setId('ep_ce_keys'),
		' ',
		(new CButtonLink(_('Check')))->setId('ep_ce_check'),
		(new CDiv((new CCheckBox('pattern'))->setChecked((int) $c['pattern'] === 1)->setLabel(_('Key is a pattern (* wildcard)'))->setId('ep_ce_pattern'))),
		(new CDiv((string) ($data['coverage_text'] ?? '')))
			->setId('ep_ce_coverage')->addClass('ep-ce-hint'),
		$hint(_s('A pattern needs %1$s plain characters before its first *; it reads at most %2$s items a server and %3$s a column.',
			Columns::PATTERN_PREFIX, Columns::PATTERN_PER_HOST, Columns::PATTERN_PER_METRIC))->addClass('ep-ce-block')
	]))->addClass('js-item')
]);

// Calculated: two operands, each a built-in number or an item key on the servers.
$numbers = [];
foreach ($data['catalogue'] as $id => $b) {
	if (empty($b['text']) && in_array($b['on'], [Columns::ON_MASTER, Columns::ON_SERVERS], true)) {
		$numbers[$id] = trim(($b['group'] !== '' ? $b['group'].' · ' : '').$b['label']);
	}
}
$numbers['__key'] = _('Zabbix item key…');
$operand = function (string $name, string $value, string $label) use ($numbers) {
	$isKey = strpos($value, Columns::KEY_OPERAND) === 0;
	return [
		(new CSelect($name.'_pick'))->setId('ep_ce_'.$name.'_pick')->setFocusableElementId('ep_ce_'.$name.'_btn')->setAttribute('aria-label', $label)
			->setValue($isKey ? '__key' : (isset($numbers[$value]) ? $value : (string) array_key_first($numbers)))
			->addOptions(CSelect::createOptionsFromArray($numbers)),
		(new CTextBox($name.'_key', $isKey ? substr($value, strlen(Columns::KEY_OPERAND)) : '', false, Columns::MAX_KEY))->setId('ep_ce_'.$name.'_key')
			->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)->setAttribute('list', 'ep_ce_keys')->setAttribute('placeholder', _('item key'))->addClass('js-opkey')
	];
};
$dataGrid->addItem([
	(new CLabel(_('Calculate')))->addClass('js-calc'),
	(new CFormField($inline(array_merge(
		$operand('calc_a', $c['calc_a'], _('First item')),
		[(new CSelect('calc_op'))->setId('ep_ce_calc_op')->setFocusableElementId('ep_ce_calc_op_btn')->setValue((int) $c['calc_op'])
			->setAttribute('aria-label', _('Operation'))->addOptions(CSelect::createOptionsFromArray(Columns::opLabels()))],
		$operand('calc_b', $c['calc_b'], _('Second item'))
	))))->addClass('js-calc')
]);

// Servers: roles from Cluster Management (none ticked: every server), and how they combine. A row
// that is one host (Volume, Top hosts) has no servers to pick.
if (empty($data['single'])) {
$roleBoxes = [(new CCheckBox('roles_all'))->setId('ep_ce_roles_all')->setLabel(_('All'))->setChecked(!$c['roles'])];
foreach ($data['roles'] as $r) {
	$roleBoxes[] = (new CCheckBox('roles[]', $r['id']))->setId('ep_ce_role_'.$r['id'])->setLabel($r['label'])
		->setChecked(in_array($r['id'], $c['roles'], true))->addClass('js-role');
}
$dataGrid->addItem([
	(new CLabel(_('Servers')))->addClass('js-servers'),
	(new CFormField([$inline($roleBoxes), $inline([
		new CLabel(_('Combine'), 'ep_ce_combine_btn'),
		(new CSelect('combine'))->setId('ep_ce_combine')->setFocusableElementId('ep_ce_combine_btn')->setValue((int) $c['combine'])
			->addOptions(CSelect::createOptionsFromArray(Columns::combineLabels())),
		$hint(_('one value per client: each server\'s worst item first, then the servers'))
	])]))->addClass('js-servers')
]);
}
}

$dataGrid->addItem([
	(new CLabel(_('Value'), 'ep_ce_value_btn'))->addClass('js-number'),
	(new CFormField($inline([
		(new CSelect('value'))->setId('ep_ce_value')->setFocusableElementId('ep_ce_value_btn')->setValue((int) $c['value'])
			->addOptions(CSelect::createOptionsFromArray(Columns::valueLabels())),
		(new CSpan(_('over')))->addClass('js-window'),
		(new CTextBox('window', (int) $c['window'] > 0 ? (string) $c['window'] : '', false, 3))->setId('ep_ce_window')
			->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)->setAttribute('placeholder', (string) $data['trend_days'])->addClass('js-window'),
		(new CSpan(_s('days (empty: the widget\'s window, %1$s)', $data['trend_days'])))->addClass('js-window')
	])))->addClass('js-number')
]);

$dataGrid->addItem([
	(new CLabel(_('Units'), 'ep_ce_units'))->addClass('js-number'),
	(new CFormField($inline([
		(new CTextBox('units', $c['units'], false, Columns::MAX_UNITS))->setId('ep_ce_units')->setWidth(ZBX_TEXTAREA_TINY_WIDTH)
			->setAttribute('placeholder', _('units')),
		(new CSelect('size_unit'))->setId('ep_ce_size_unit')->setFocusableElementId('ep_ce_size_unit_btn')->setValue((int) $c['size_unit'])
			->setAttribute('aria-label', _('Size unit'))->addOptions(CSelect::createOptionsFromArray(Columns::sizeLabels())),
		new CLabel(_('Decimals'), 'ep_ce_decimals'),
		(new CTextBox('decimals', $c['decimals'], false, 2))->setId('ep_ce_decimals')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
			->setAttribute('placeholder', _('Settings')),
		(new CCheckBox('short'))->setChecked((int) $c['short'] === 1)->setLabel(_('Short numbers (18.4k)'))->setId('ep_ce_short')
	])))->addClass('js-number')
]);

$dataGrid->addItem([
	new CLabel(_('No data'), 'ep_ce_nodata_btn'),
	new CFormField($inline([
		(new CSelect('nodata'))->setId('ep_ce_nodata')->setFocusableElementId('ep_ce_nodata_btn')->setValue((int) $c['nodata'])
			->addOptions(CSelect::createOptionsFromArray([Columns::NODATA_WHY => _('Say why (e.g. allocated not set)'), Columns::NODATA_DASH => '—'])),
		(new CSpan(_('stale after')))->addClass('js-number'),
		(new CTextBox('stale', $c['stale'], false, 5))->setId('ep_ce_stale')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
			->setAttribute('placeholder', _('never'))->addClass('js-number'),
		(new CSpan(_('min')))->addClass('js-number')
	]))
]);

$dataGrid->addItem([
	new CLabel(_('Click opens'), 'ep_ce_click_btn'),
	new CFormField((new CSelect('click'))->setId('ep_ce_click')->setFocusableElementId('ep_ce_click_btn')->setValue((int) $c['click'])
		->addOptions(CSelect::createOptionsFromArray(Columns::clickLabels())))
]);

/* ---------------------------------- Display and colours ---------------------------------- */

$showGrid = new CFormGrid();
$show = (new CRadioButtonList('show', (int) $c['show']))->setModern();
foreach (Columns::showLabels() as $v => $label) {
	$show->addValue($label, $v);
}
$showGrid->addItem([new CLabel(_('Show as'), 'show'), new CFormField($show)]);

$showGrid->addItem([
	(new CLabel(_('Bar style'), 'bar_style'))->addClass('js-bar'),
	(new CFormField($inline([
		(new CRadioButtonList('bar_style', (int) $c['bar_style']))
			->addValue(_('Widget default'), Columns::BAR_WIDGET)
			->addValue(_('Under value'), Columns::BAR_UNDER)
			->addValue(_('In cell'), Columns::BAR_CELL)
			->addValue(_('Segments'), Columns::BAR_SEGMENTS)
			->addValue(_('Gauge'), Columns::BAR_GAUGE)
			->setModern(),
		(new CTextBox('segments', (string) $c['segments'], false, 2))->setId('ep_ce_segments')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
			->setAttribute('aria-label', _('Segment count'))
	])))->addClass('js-bar')
]);
$showGrid->addItem([
	(new CLabel(_('Bar colours'), 'bar_color'))->addClass('js-bar'),
	(new CFormField($inline([
		(new CRadioButtonList('bar_color', (int) $c['bar_color']))
			->addValue(_('By level (palette)'), Columns::BARCOLOR_LEVEL)
			->addValue(_('Fixed'), Columns::BARCOLOR_FIXED)
			->setModern(),
		$color('bar_fixed', $c['bar_fixed'], _('Fixed bar colour')),
		new CSpan(_('track')),
		$color('track', $c['track'], _('Track colour'))
	])))->addClass('js-bar')
]);
$showGrid->addItem([
	(new CLabel(_('Bar range'), 'ep_ce_min'))->addClass('js-bar'),
	(new CFormField($inline([
		(new CTextBox('min', $c['min']))->setId('ep_ce_min')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)->setAttribute('placeholder', '0')
			->setAttribute('aria-label', _('Bar minimum')),
		new CSpan(_('to')),
		(new CTextBox('max', $c['max']))->setId('ep_ce_max')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)->setAttribute('placeholder', _('auto'))
			->setAttribute('aria-label', _('Bar maximum')),
		(new CCheckBox('show_value'))->setChecked((int) $c['show_value'] === 1)->setLabel(_('Show value'))->setId('ep_ce_show_value'),
		(new CCheckBox('show_pct'))->setChecked((int) $c['show_pct'] === 1)->setLabel(_('Show %'))->setId('ep_ce_show_pct')
	])))->addClass('js-bar')
]);
$showGrid->addItem([
	new CLabel(_('Cell'), 'ep_ce_align_btn'),
	new CFormField($inline([
		(new CSelect('align'))->setId('ep_ce_align')->setFocusableElementId('ep_ce_align_btn')->setValue((int) $c['align'])
			->addOptions(CSelect::createOptionsFromArray([Columns::ALIGN_AUTO => _('Align as the report does'), Columns::ALIGN_LEFT => _('Align left'),
				Columns::ALIGN_CENTRE => _('Centre'), Columns::ALIGN_RIGHT => _('Right')])),
		new CSpan(_('width')),
		(new CTextBox('width', $c['width'] === '' ? '' : (string) $c['width'], false, 4))->setId('ep_ce_width')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
			->setAttribute('placeholder', _('auto')),
		(new CCheckBox('wrap'))->setChecked((int) $c['wrap'] === 1)->setLabel(_('Wrap text'))->setId('ep_ce_wrap')
	]))
]);

// Thresholds: ElasticPro Settings for a percentage, or the column's own levels.
$thTable = (new CTable())
	->setId('ep_ce_thresholds')
	->addClass(ZBX_STYLE_TABLE_FORMS)
	->setHeader([_('Colour'), _('Level'), (new CColHeader(new CSpan(_('Value'))))->setId('ep_ce_th_head'), _('Action')])
	->setFooter(new CRow((new CCol((new CButtonLink(_('Add threshold')))->addClass('element-table-add')))->setColSpan(4)));
$thTemplate = (new CTemplateTag('ep-ce-threshold-row'))->addItem((new CRow([
	(new CColor('thresholds[#{rowNum}][color]', '#{color}'))->appendColorPickerJs(false),
	(new CSelect('thresholds[#{rowNum}][level]'))->setValue('#{level}')->addOptions(CSelect::createOptionsFromArray([
		Columns::LEVEL_WARN => _('Warn'), Columns::LEVEL_HIGH => _('High')
	])),
	(new CTextBox('thresholds[#{rowNum}][value]', '#{value}', false))->setWidth(ZBX_TEXTAREA_TINY_WIDTH)->setAriaRequired(),
	(new CButton('thresholds[#{rowNum}][remove]', _('Remove')))->addClass(ZBX_STYLE_BTN_LINK)->addClass('element-table-remove')
]))->addClass('form_row'));
$showGrid->addItem([
	(new CLabel(_('Thresholds')))->addClass('js-number'),
	(new CFormField([
		$inline([
			(new CRadioButtonList('worse', (int) $c['worse']))
				->addValue(_('Higher is worse'), Columns::WORSE_HIGHER)
				->addValue(_('Lower is worse'), Columns::WORSE_LOWER)
				->setModern(),
			(new CCheckBox('use_settings'))->setChecked((int) $c['use_settings'] === 1)->setId('ep_ce_use_settings')
				->setLabel(_s('Use ElasticPro Settings (%1$s / %2$s)', Columns::num((float) $data['usage']['warn']), Columns::num((float) $data['usage']['high'])))
		]),
		(new CDiv([
			$inline([$color('warn_color', $c['warn_color'], _('Warn colour')), new CLabel(_('Warn'), 'ep_ce_warn'),
				(new CTextBox('warn', $c['warn'], false, 6))->setId('ep_ce_warn')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
					->setAttribute('placeholder', _('Settings'))]),
			$inline([$color('high_color', $c['high_color'], _('High colour')), new CLabel(_('High'), 'ep_ce_high'),
				(new CTextBox('high', $c['high'], false, 6))->setId('ep_ce_high')->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
					->setAttribute('placeholder', _('Settings'))]),
			$hint(_('%, empty follows the widget, then ElasticPro → Settings; a client\'s own thresholds on its master host still win.'))
		]))->setId('ep_ce_settings_values'),
		(new CDiv([$thTable, $thTemplate]))->setId('ep_ce_own')->addClass('table-forms-separator')->addStyle('min-width: '.ZBX_TEXTAREA_STANDARD_WIDTH.'px'),
		$hint(_('Colours left as they are follow the palette in ElasticPro → Settings. A column with a share (x of y, used of allocated, %) is judged on the share.'))
			->addClass('ep-ce-block')
	]))->addClass('js-number')
]);

/* ------------------------------------------ Preview ------------------------------------------ */

$preview = (new CDiv([
	(new CSpan(_('Preview, on sample figures')))->addClass('ep-ce-preview-title'),
	(new CDiv(''))->setId('ep_ce_preview')
]))->addClass('ep-ce-preview ep-panel')->setAttribute('style', Settings::paletteStyle());

$catalogue = array_map(fn($b) => ['label' => $b['label'], 'group' => $b['group'], 'on' => $b['on'], 'key' => $b['key'] ?? '', 'units' => $b['units'] ?? '',
	'settings' => !empty($b['settings']), 'pct' => !empty($b['pct']), 'text' => !empty($b['text'])], $data['catalogue']);
$form
	->addItem((new CDiv([
		(new CFormFieldset(_('Data'), $dataGrid))->addClass('ep-ce-set'),
		(new CFormFieldset(_('Display and colours'), $showGrid))->addClass('ep-ce-set'),
		$preview
	]))->addClass('ep-ce'))
	->addItem((new CScriptTag('ep_column_form.init('.json_encode([
		'form_name' => $form->getName(),
		'thresholds' => array_map(fn($t) => ['level' => (string) $t['level'], 'color' => ltrim((string) $t['color'], '#'), 'value' => (string) $t['value']],
			$c['thresholds']),
		'catalogue' => $catalogue,
		'decimals' => $data['decimals'],
		'usage' => $data['usage'],
		'row' => $row,
		'coverage_url' => (new CUrl('zabbix.php'))->setArgument('action', $data['action'])->setArgument('coverage', 1)->getUrl()
	], JSON_THROW_ON_ERROR).');'))->setOnDocumentReady());

$title = trim(($c['group'] !== '' ? $c['group'].' · ' : '').$c['name']);
$output = [
	'header' => $data['edit'] ? _s('Column — %1$s', $title) : ($row ? _('New server column') : _('New column')),
	'script_inline' => $this->readJsFile('column.edit.js.php', null, ''),
	'body' => $form->toString(),
	'buttons' => [[
		'title' => $data['edit'] ? _('Update') : _('Add'),
		'keepOpen' => true,
		'isSubmit' => true,
		'action' => '$(document.forms.ep_column_edit).trigger("process.form", [overlay])'
	]]
];
if ($data['user']['debug_mode'] == GROUP_DEBUG_MODE_ENABLED) {
	CProfiler::getInstance()->stop();
	$output['debug'] = CProfiler::getInstance()->make()->toString();
}
echo json_encode($output, JSON_THROW_ON_ERROR);
