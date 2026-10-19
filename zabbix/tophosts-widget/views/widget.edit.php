<?php declare(strict_types = 0);
/**
 * Top hosts settings, on one page: who is ranked (clients, client types, roles), by what and how many,
 * which metrics a viewer may switch between on the widget, the columns each row shows, how the
 * ranking is drawn, and Warn / High.
 *
 * Columns are listed here and edited in their own dialog (ColumnEdit); each row carries the column as
 * hidden fields, and the form posts them in the order the rows were dragged into. An empty Warn or
 * High follows ElasticPro -> Settings, and the form says so with the value that applies.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpTophosts\Includes\{Catalogue, ColumnForm, WidgetForm};
use Modules\EpTophosts\Lib\{Columns, Roles, Settings};

$fields = $data['fields'];
$cfg = Settings::all();
$roles = Roles::load();
$catalogue = $fields['columns']->getCatalogue();
$columns = $fields['columns']->getValue();
$trendDays = (int) $fields['trend_days']->getValue();

$form = new CWidgetFormView($data);
$f = new ColumnForm($form, $fields);

$grid = new CFormGrid();

/* ----------------------------------------- who is ranked ----------------------------------------- */

$f->who($grid, $cfg);
$roleOptions = [];
foreach (Roles::allRoles($roles) as $r) {
	$roleOptions[$r['id']] = $r['label'];
}
foreach ($fields['roles']->getValue() as $id) {
	if (!isset($roleOptions[$id])) {
		$roleOptions[$id] = _s('%1$s (not found)', $id);
	}
}
$f->row($grid, new CLabel(_('Roles')), [$f->boxes('roles', $roleOptions, $fields['roles']->getValue()), $f->hint(_('none ticked: every role'))]);

/* --------------------------------------- by what, how many --------------------------------------- */

$rank = $form->registerField(new CWidgetFieldSelectView($fields['rank']));
$value = $form->registerField(new CWidgetFieldSelectView($fields['rank_value']));
$count = $form->registerField(new CWidgetFieldIntegerBoxView($fields['count']));
$trend = $form->registerField(new CWidgetFieldIntegerBoxView($fields['trend_days']));
$f->row($grid, $rank->getLabel(), (new CDiv([
	$rank->getView(), $value->getView(), new CSpan(_('over the last')), $trend->getView(), new CSpan(_('days')), new CSpan(_('show')), $count->getView(), new CSpan(_('hosts'))
]))->addClass('ep-ef-inline'));
$f->row($grid, new CLabel(_('Item key'), 'rank_key'), (new CDiv([
	(new CTextBox('rank_key', $fields['rank_key']->getValue(), false, Columns::MAX_KEY))->setId('rank_key')->setWidth(ZBX_TEXTAREA_STANDARD_WIDTH)
		->setAttribute('placeholder', _('e.g. es.indexing.index_total.rate')),
	(new CCheckBox('rank_pattern'))->setId('rank_pattern')->setLabel(_('Key is a pattern (* wildcard)'))->setChecked((int) $fields['rank_pattern']->getValue() === 1),
	$f->hint(_s('a pattern needs %1$s plain characters before its first *; a server\'s worst matching item ranks it', Columns::PATTERN_PREFIX))
]))->addClass('ep-ef-inline ep-top-itemrow'), 'ep-top-itemrow');
$f->row($grid, new CLabel(_('Switch on widget')), [
	$f->boxes('switch_metrics', ['cpu' => _('CPU'), 'mem' => _('Memory'), 'disk' => _('Disk used'), 'jvm' => _('JVM heap')], $fields['switch_metrics']->getValue()),
	$f->hint(_('the metrics a viewer may rank by without opening this form, besides the one above'))
]);

/* ------------------------------------------- columns ------------------------------------------- */

$f->columnsList($grid, $columns, $catalogue, true, $trendDays, Settings::usage($cfg), null,
	_s('Drag rows to reorder. Value is the figure the widget ranks by; any other column is a figure of the server — CPU, memory, disk, uptime or any Zabbix item, now or its average, minimum or maximum over the window — with its own look. Up to %1$s columns.', Columns::MAX));

/* --------------------------------------- how it is drawn --------------------------------------- */

$view = $form->registerField(new CWidgetFieldRadioButtonListView($fields['view']));
$f->row($grid, $view->getLabel(), $view->getView());
$bars = $form->registerField(new CWidgetFieldSelectView($fields['bars']));
$f->row($grid, $bars->getLabel(), [$bars->getView(), ' ', $f->hint(_('for every bar left at "Widget default"'))]);
$usage = Settings::usage($cfg);
$f->row($grid, new CLabel(_('Warn / High')), (new CDiv([
	new CSpan(_('Warn at, %')),
	(new CTextBox('usage_warn', $fields['usage_warn']->getValue(), false, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
		->setAttribute('placeholder', _s('Settings: %1$s', Columns::num((float) $usage['warn']))),
	new CSpan(_('High at, %')),
	(new CTextBox('usage_high', $fields['usage_high']->getValue(), false, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
		->setAttribute('placeholder', _s('Settings: %1$s', Columns::num((float) $usage['high']))),
	$f->hint(_('empty: ElasticPro → Settings; a client\'s own thresholds on its master host still win'))
]))->addClass('ep-ef-inline'));

$form
	->addItem((new CDiv($grid))->addClass('ep-ef-layout'))
	->includeJsFile('columns.form.js.php')
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_ep_tophosts_form.init('.json_encode([
		'action' => 'widget.ep_tophosts.column.edit',
		'templateid' => $data['templateid'],
		'trend_days' => $trendDays,
		'own' => true,
		'max' => Columns::MAX
	], JSON_THROW_ON_ERROR).');')
	->show();
