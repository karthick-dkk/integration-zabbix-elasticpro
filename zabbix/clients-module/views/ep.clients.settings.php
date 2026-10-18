<?php declare(strict_types = 0);
/**
 * ElasticPro settings: the site's thresholds, how figures are drawn, when a filling disk is
 * urgent, and which client types exist.
 *
 * One page of cards in a grid (views/css/ep.clients.settings.css), a setting to a row: its label,
 * its input, and what ElasticPro ships beside it. The explanation of a setting is a hint that
 * opens on hover or focus, not a paragraph under every input; the facts that apply to a whole card
 * sit in its foot. The form underneath is unchanged — same field names, same POST, same token —
 * so the save and its cleaning (Settings::clean) do not know the page was redrawn.
 *
 * No colour in this file: the sheet carries the tokens, light by default and dark under Zabbix's
 * dark themes. Inputs, selects and buttons stay Zabbix's own.
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

// Everything on the page is inside .ep-set, which the sheet scopes its colours and sizes to.
$top = [];
$top[] = (new CDiv(_('These apply to Cluster Management and to the Client capacity, Client resources and Volume report widgets. A dashboard widget can override its own thresholds and bar style.')))
	->addClass('ep-intro');
if (!$data['store_ok']) {
	$top[] = (new CDiv(_s('The data folder %1$s is missing or not writable, so nothing here can be saved. See the Clients module README.', $data['store_dir'])))
		->addClass('msg-bad')->addClass('ep-box');
}
if ($ro) {
	$top[] = (new CDiv(_('You can read these; only a Super admin changes them.')))->addClass('ep-intro');
}

// The templates carry their own copy of the two usage thresholds, and it is those copies the
// triggers compare against — so a setting that has not reached them means the reports and the
// alerts disagree, and only the reports moved. Said at the top of the page where the change was
// made, rather than discovered later from an alert that never arrived.
//
// Its own form, above the settings form and not inside it: writing the templates changes things,
// so it must be a POST with a token, and HTML has no nested forms. A GET link here would have
// been reachable from any page that could make this browser fetch a URL.
$in_template = $data['template'];
if ($in_template !== null && (($in_template['warn'] !== null && abs($in_template['warn'] - (float) $s['usage']['warn']) > 0.001)
		|| ($in_template['high'] !== null && abs($in_template['high'] - (float) $s['usage']['high']) > 0.001))) {
	$drift = [_s('The alerts still fire at %1$s %% and %2$s %%. Those thresholds live on the templates this page writes, and their triggers are what actually alarm — the reports already follow the figures below. Write the master template to bring the two into step.',
		$in_template['warn'] ?? '—', $in_template['high'] ?? '—')];
	if (!$ro) {
		$drift[] = (new CForm('post', $url('ep.clients.template.install')))
			->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('ep.clients.template.install'))
			->addItem((new CSubmit('go', _('Write master template')))->addClass(ZBX_STYLE_BTN_ALT))
			->addClass('ep-drift-form');
	}
	$top[] = (new CDiv($drift))->addClass('msg-warning')->addClass('ep-box');
}

$form = (new CForm('post', $url('ep.clients.settings.save')))
	->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('ep.clients.settings.save'))->setId('ep-settings-form');

/** A hint: a small (i) that opens its text on hover or focus. $id is what the input's aria-describedby names. */
$tip = fn(string $id, string $text) => (new CSpan([
	(new CSpan('ⓘ'))->addClass('ep-i')->setAttribute('tabindex', '0')->setAttribute('role', 'img')->setAttribute('aria-label', _('Details')),
	(new CSpan($text))->addClass('ep-tip')->setId($id)->setAttribute('role', 'tooltip')
]))->addClass('ep-tipw');
/** What ElasticPro ships, small and muted beside the input. */
$shipped = fn($value) => (new CSpan([_('default').' ', (new CTag('b', true, (string) $value))]))->addClass('ep-def');

/** A short box for a number, sized to its content, with the shipped value beside it. */
$number = function (string $label, string $name, $value, $default, string $note = '') use ($ro, $tip, $shipped) {
	$input = (new CTextBox($name, (string) $value, $ro))->setId('s-'.$name);
	$cells = [new CLabel($label, 's-'.$name)];
	if ($note !== '') {
		$input->setAttribute('aria-describedby', 'ep-tip-'.$name);
	}
	$cells[] = (new CSpan([$input, $shipped($default)]))->addClass('ep-ctl');
	$cells[] = $note !== '' ? $tip('ep-tip-'.$name, $note) : new CSpan('');
	return (new CDiv($cells))->addClass('ep-row');
};
/** A select; its shipped value goes beside it when short, into the hint when not. */
$choice = function (string $label, string $name, array $options, $value, $default, string $note = '') use ($ro, $tip, $shipped) {
	$select = (new CSelect($name))->setId('s-'.$name)->setValue($value)
		->addOptions(CSelect::createOptionsFromArray($options))->setReadonly($ro);
	$default_text = (string) ($options[$default] ?? $default);
	$inline = mb_strlen($default_text) <= 8;
	if (!$inline) {
		$note = trim($note.' '._s('ElasticPro ships %1$s.', $default_text));
	}
	if ($note !== '') {
		$select->setAttribute('aria-describedby', 'ep-tip-'.$name);
	}
	$cells = [new CLabel($label, 's-'.$name),
		(new CSpan($inline ? [$select, $shipped($default_text)] : [$select]))->addClass('ep-ctl'),
		$note !== '' ? $tip('ep-tip-'.$name, $note) : new CSpan('')];
	return (new CDiv($cells))->addClass('ep-row');
};
/** A card: titled, one line of purpose at most, its rows, and the facts that apply to all of them in the foot. */
$card = function (string $title, string $purpose, array $rows, $foot = null) {
	$head = (new CDiv([new CTag('h4', true, $title), new CTag('p', true, $purpose)]))->addClass('ep-card-head');
	$card = (new CDiv([$head, (new CDiv($rows))->addClass('ep-card-body')]))->addClass('ep-card');
	if ($foot !== null) {
		$card->addItem((new CDiv($foot))->addClass('ep-card-foot'));
	}
	return $card;
};

$cards = [];

/* ---------------- usage ---------------- */
$cards[] = $card(_('Usage colouring'), _('When a figure turns yellow, then red.'), [
	$number(_('Warn at, %'), 'warn', $s['usage']['warn'], $d['usage']['warn'],
		_('Usage at or above this is yellow.')),
	$number(_('High at, %'), 'high', $s['usage']['high'], $d['usage']['high'],
		_('Usage at or above this is red; at or above the warning, yellow.'))
], _s('A client\'s master host overrides both for itself with %1$s and %2$s.',
	\Modules\EpClients\Lib\Settings::WARN_MACRO, \Modules\EpClients\Lib\Settings::HIGH_MACRO));

/* ---------------- display ---------------- */
$cards[] = $card(_('Display'), _('How figures are drawn and exported.'), [
	$choice(_('Bars'), 'bars', $data['bars'], $s['display']['bars'], $d['display']['bars'],
		_('How a figure with a percentage behind it is drawn. A dashboard widget may choose its own.')),
	$number(_('Decimals — sizes'), 'decimals_bytes', $s['display']['decimals_bytes'], $d['display']['decimals_bytes']),
	$number(_('Decimals — percentages'), 'decimals_percent', $s['display']['decimals_percent'], $d['display']['decimals_percent']),
	$number(_('Decimals — plain numbers'), 'decimals_plain', $s['display']['decimals_plain'], $d['display']['decimals_plain'],
		_('Servers, CPU cores and the like.')),
	$choice(_('Size unit'), 'size_unit', array_combine($data['units'], $data['units']), $s['export']['size_unit'], $d['export']['size_unit'],
		_('What a size is divided by in the CSV and Excel export, and what its column header says. Binary multiples, so the export agrees with the figure in the table.'))
]);

/* ---------------- forecast ---------------- */
$cards[] = $card(_('When storage fills'), _('How soon a filling disk is urgent.'), [
	$number(_('Red under, days'), 'bad_days', $s['forecast']['bad_days'], $d['forecast']['bad_days']),
	$number(_('Yellow under, days'), 'warn_days', $s['forecast']['warn_days'], $d['forecast']['warn_days'],
		_('Neither may pass the one outside it; a figure that would is brought back.')),
	$number(_('"Not growing" over, days'), 'flat_days', $s['forecast']['flat_days'], $d['forecast']['flat_days'],
		_('Longer than this, or falling, reads as not growing at all.')),
	$number(_('Say "under a day" below, days'), 'day_text_days', $s['forecast']['day_text_days'], $d['forecast']['day_text_days']),
	$number(_('Count in months over, days'), 'month_text_days', $s['forecast']['month_text_days'], $d['forecast']['month_text_days']),
	$number(_('Days to a month'), 'month_days', $s['forecast']['month_days'], $d['forecast']['month_days'])
], _s('From each master host\'s %1$s, which Zabbix measures over the last 7 days.',
	\Modules\EpClients\Lib\Forecast::KEY));

/* ---------------- types ---------------- */
$types = (new CTextArea('types', implode("\n", $s['types']['all'])))->setId('s-types')
	->setRows(4)->setReadonly($ro)->setAttribute('aria-describedby', 'ep-tip-types');
$cards[] = $card(_('Client types'), _('The types a client can be given.'), [
	(new CDiv([new CLabel(_('One per line'), 's-types'),
		$tip('ep-tip-types', _('The list the client form offers and the reports filter by. At most eight, and no two that differ only in case. Removing one does not change any client that already has it.')),
		(new CSpan([$types]))->addClass('ep-ctl')]))->addClass('ep-row')->addClass('ep-row-wide'),
	$choice(_('Default'), 'type_default', array_combine($s['types']['all'], $s['types']['all']), $s['types']['default'], $d['types']['default'],
		_('What a client with no type of its own counts as.'))
]);

/* ---------------- refresh ---------------- */
$refresh = [];
foreach ([_('Client capacity') => 'capacity', _('Client resources') => 'resources', _('Volume report') => 'volume'] as $label => $report) {
	$refresh[] = (new CDiv([new CSpan($label), (new CSpan($s['refresh'][$report].' s'))->addClass('ep-ctl')->addClass('ep-val'), new CSpan('')]))
		->addClass('ep-row');
}
$cards[] = $card(_('Refresh'), _('What a newly placed widget starts at.'), $refresh,
	(new CTag('details', true, [
		new CTag('summary', true, _('Read-only here')),
		new CDiv(_('Zabbix keeps a placed widget\'s refresh in each user\'s own profile — change it on the dashboard, in the widget\'s own menu. These are what a newly placed widget starts at.'))
	])));

$form->addItem((new CDiv($cards))->addClass('ep-grid'));

if (!$ro) {
	$form->addItem((new CDiv([new CSubmit('save', _('Save')),
		(new CSubmit('reset', _('Reset to shipped')))->addClass(ZBX_STYLE_BTN_ALT)
			->onClick('return confirm('.json_encode(_('Put every setting here back to the one ElasticPro ships?')).');')]))->addClass('ep-bar'));
}

// Which build of the modules this is, read from the manifest and the BUILD file the release archive
// carries (lib/Build.php). A plain string in a CDiv, so Zabbix escapes it.
$build = (new CDiv(\Modules\EpClients\Lib\Build::line()))->addClass('ep-build');

$page->addItem((new CDiv(array_merge($top, [$form, $build])))->addClass('ep-set'))->show();
?>
<style><?php readfile(__DIR__.'/css/ep.hint.css'); readfile(__DIR__.'/css/ep.clients.settings.css'); ?></style>
