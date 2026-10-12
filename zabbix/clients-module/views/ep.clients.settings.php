<?php declare(strict_types = 0);
/**
 * ElasticPro settings: the site's thresholds, how figures are drawn, when a filling disk is
 * urgent, and which client types exist.
 *
 * Built from Zabbix's own form objects, like the Roles page, and with no colour of its own: the
 * emphasis here is msg-warning and the hints are opacity, both of which the dark theme already
 * knows. A page of fifteen labelled inputs has no reason to carry a palette.
 *
 * @var CView $this
 * @var array $data
 */

$url = fn(string $action) => (new CUrl('zabbix.php'))->setArgument('action', $action)->getUrl();
$ro = !$data['can_write'];
$s = $data['settings'];
$d = $data['defaults'];

$page = (new CHtmlPage())->setTitle(_('ElasticPro settings'))
	->setControls((new CTag('nav', true, (new CList())->addItem(new CRedirectButton(_('Back to clients'), $url('ep.clients.list'))))));
$page->addItem((new CDiv(_('These apply to Cluster Management and to the Client capacity, Client resources and Volume report widgets. A dashboard widget can override its own thresholds and bar style; a client\'s master host can override the thresholds for itself.')))
	->addClass('ep-soft'));
if (!$data['store_ok']) {
	$page->addItem((new CDiv(_s('The data folder %1$s is missing or not writable, so nothing here can be saved. See the Clients module README.', $data['store_dir'])))
		->addClass('msg-bad')->addClass('ep-box'));
}
if ($ro) {
	$page->addItem((new CDiv(_('You can read these; only a Super admin changes them.')))->addClass('ep-soft'));
}

$form = (new CForm('post', $url('ep.clients.settings.save')))
	->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('ep.clients.settings.save'))->setId('ep-settings-form');
$grid = new CFormGrid();

$head = fn(string $text) => $grid->addItem([new CTag('h4', true, $text), new CFormField('')]);
$hint = fn(string $text) => (new CDiv($text))->addClass('ep-hint');
/** A short box for a number, with what ElasticPro ships said underneath rather than guessed at. */
$number = function (string $label, string $name, $value, $shipped, string $note = '') use ($grid, $ro, $hint) {
	$items = [(new CTextBox($name, (string) $value, $ro))->setId('s-'.$name)->setWidth(ZBX_TEXTAREA_TINY_WIDTH),
		$hint($note !== '' ? $note.' '._s('ElasticPro ships %1$s.', $shipped) : _s('ElasticPro ships %1$s.', $shipped))];
	return $grid->addItem([new CLabel($label, 's-'.$name), new CFormField($items)]);
};
$choice = function (string $label, string $name, array $options, $value, string $note = '') use ($grid, $ro, $hint) {
	$select = (new CSelect($name))->setId('s-'.$name)->setValue($value)
		->addOptions(CSelect::createOptionsFromArray($options))->setReadonly($ro);
	return $grid->addItem([new CLabel($label, 's-'.$name),
		new CFormField($note !== '' ? [$select, $hint($note)] : [$select])]);
};

/* ---------------- usage ---------------- */
$head(_('Usage colouring'));
$number(_('Warn at, %'), 'warn', $s['usage']['warn'], $d['usage']['warn']);
$number(_('High at, %'), 'high', $s['usage']['high'], $d['usage']['high'],
	_('Usage at or above this is red; at or above the warning, yellow.'));
$grid->addItem([new CLabel(''), new CFormField($hint(_s('A client\'s master host overrides both for itself with %1$s and %2$s.',
	\Modules\EpClients\Lib\Settings::WARN_MACRO, \Modules\EpClients\Lib\Settings::HIGH_MACRO)))]);
// The triggers compare against the template's own copy of these two, so a changed setting that
// has not reached the template means the reports and the alerts disagree. Said here, where the
// change was made, rather than discovered later from an alert that did not arrive.
$in_template = $data['template'];
if ($in_template !== null && (($in_template['warn'] !== null && abs($in_template['warn'] - (float) $s['usage']['warn']) > 0.001)
		|| ($in_template['high'] !== null && abs($in_template['high'] - (float) $s['usage']['high']) > 0.001))) {
	$grid->addItem([new CLabel(''), new CFormField((new CDiv([
		_s('The alerts still fire at %1$s %% and %2$s %%: those thresholds live on the template "%3$s", and its triggers are what actually alarm. Write the master template to bring them into step.',
			$in_template['warn'] ?? '—', $in_template['high'] ?? '—', $data['template_name']),
		$ro ? null : [' ', new CLink(_('Write master template'), $url('ep.clients.template.install'))]
	]))->addClass('msg-warning')->addClass('ep-box'))]);
}

/* ---------------- display ---------------- */
$head(_('Display'));
$choice(_('Bars'), 'bars', $data['bars'], $s['display']['bars'],
	_('How a figure with a percentage behind it is drawn. A dashboard widget may choose its own.'));
$number(_('Decimals — sizes'), 'decimals_bytes', $s['display']['decimals_bytes'], $d['display']['decimals_bytes']);
$number(_('Decimals — percentages'), 'decimals_percent', $s['display']['decimals_percent'], $d['display']['decimals_percent']);
$number(_('Decimals — plain numbers'), 'decimals_plain', $s['display']['decimals_plain'], $d['display']['decimals_plain'],
	_('Servers, CPU cores and the like.'));
$choice(_('Size unit'), 'size_unit', array_combine($data['units'], $data['units']), $s['export']['size_unit'],
	_('What a size is divided by in the CSV and Excel export, and what its column header says. Binary multiples, so the export agrees with the figure in the table.'));

/* ---------------- forecast ---------------- */
$head(_('When storage fills'));
$grid->addItem([new CLabel(''), new CFormField($hint(_s('From each master host\'s %1$s, which Zabbix measures over the last 7 days.',
	\Modules\EpClients\Lib\Forecast::KEY)))]);
$number(_('Red under, days'), 'bad_days', $s['forecast']['bad_days'], $d['forecast']['bad_days']);
$number(_('Yellow under, days'), 'warn_days', $s['forecast']['warn_days'], $d['forecast']['warn_days'],
	_('Neither may pass the one outside it; a figure that would is brought back.'));
$number(_('"Not growing" over, days'), 'flat_days', $s['forecast']['flat_days'], $d['forecast']['flat_days'],
	_('Longer than this, or falling, reads as not growing at all.'));
$number(_('Say "under a day" below, days'), 'day_text_days', $s['forecast']['day_text_days'], $d['forecast']['day_text_days']);
$number(_('Count in months over, days'), 'month_text_days', $s['forecast']['month_text_days'], $d['forecast']['month_text_days']);
$number(_('Days to a month'), 'month_days', $s['forecast']['month_days'], $d['forecast']['month_days']);

/* ---------------- types ---------------- */
$head(_('Client types'));
$grid->addItem([new CLabel(_('One per line'), 's-types'),
	new CFormField([(new CTextArea('types', implode("\n", $s['types']['all'])))->setId('s-types')
		->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)->setRows(5)->setReadonly($ro),
		$hint(_('The list the client form offers and the reports filter by. At most eight, and no two that differ only in case. Removing one does not change any client that already has it.'))])]);
$choice(_('Default'), 'type_default', array_combine($s['types']['all'], $s['types']['all']), $s['types']['default'],
	_('What a client with no type of its own counts as.'));

/* ---------------- refresh ---------------- */
$head(_('Refresh'));
$refresh = (new CTableInfo())->setHeader([_('Widget'), _('Seconds')]);
foreach ([_('Client capacity') => 'capacity', _('Client resources') => 'resources', _('Volume report') => 'volume'] as $label => $report) {
	$refresh->addRow([$label, $s['refresh'][$report]]);
}
$grid->addItem([new CLabel(''), new CFormField([$refresh,
	$hint(_('Read-only here. Zabbix keeps a placed widget\'s refresh in each user\'s own profile — change it on the dashboard, in the widget\'s own menu. These are what a newly placed widget starts at.'))])]);

if (!$ro) {
	$grid->addItem([new CLabel(''), new CFormField([new CSubmit('save', _('Save')), ' ',
		(new CSubmit('reset', _('Reset to shipped')))->addClass(ZBX_STYLE_BTN_ALT)
			->onClick('return confirm('.json_encode(_('Put every setting here back to the one ElasticPro ships?')).');')])]);
}
$page->addItem($form->addItem($grid))->show();
?>
<style>
	.ep-soft { opacity: .85; margin: 0 0 8px; max-width: 110ch; }
	.ep-box { margin: 10px 0; padding: 10px 12px; max-width: 110ch; }
	.ep-hint { opacity: .75; margin-top: 3px; max-width: 90ch; }
	#ep-settings-form h4 { margin: 18px 0 2px; }
	#ep-settings-form .list-table { margin-top: 2px; max-width: 320px; }
</style>
