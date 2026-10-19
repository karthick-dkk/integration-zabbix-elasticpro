<?php declare(strict_types = 0);
/**
 * Volume settings, on three tabs: Data, Columns and View.
 *
 * Columns are the plan template's items, listed here and edited in their own dialog (ColumnEdit);
 * each row carries the column as hidden fields, and the form posts them in the order the rows were
 * dragged into. While the widget follows the report defaults the list is shown as it is and cannot
 * be changed here — choosing "This widget" starts the widget's own list from them. The colours are
 * the plan template's macros (what its triggers compare against) unless each column has its own.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpVolume\Includes\{Catalogue, ColumnForm, WidgetForm};
use Modules\EpVolume\Lib\{ColumnSettings, Columns, Settings};

$fields = $data['fields'];
$cfg = Settings::all();
$model = ColumnSettings::loadModel(Catalogue::REPORT);
$catalogue = $fields['columns']->getCatalogue();
$own = (int) $fields['columns_from']->getValue() === WidgetForm::FROM_WIDGET;
$columns = $own ? $fields['columns']->getValue() : ColumnSettings::resolve($model['columns'], $catalogue, $model['legacy'], $model['new_hidden']);
$trendDays = (int) $fields['trend_days']->getValue();
$plan = Catalogue::plan();
$macros = (int) $fields['thresholds_from']->getValue() === WidgetForm::THRESHOLDS_PLAN;

$form = new CWidgetFormView($data);
$f = new ColumnForm($form, $fields);

/* ------------------------------------------ Data ------------------------------------------ */

$dataGrid = new CFormGrid();
$f->who($dataGrid, $cfg);
$f->trendRow($dataGrid);

/* ----------------------------------------- Columns ----------------------------------------- */

$columnsGrid = new CFormGrid();
$f->columnsFrom($columnsGrid, $own);
// In the plan's macros mode the two columns its triggers alarm on say where their colour comes from.
$warn = Catalogue::macro($plan['defaults'], Catalogue::MACRO_WARN, Catalogue::FALLBACK_WARN);
$days = Catalogue::macro($plan['defaults'], Catalogue::MACRO_DAYS, Catalogue::FALLBACK_DAYS);
$note = $macros ? function (array $c) use ($catalogue, $warn, $days) {
	$key = (int) $c['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$c['builtin']]['key'] ?? '') : '';
	switch ($key !== '' ? Catalogue::kind($key) : '') {
		case 'used':
			return _s('above %1$s', Columns::num($warn));
		case 'days':
			return _s('under %1$s', Columns::num($days));
	}

	return null;
} : null;
$f->columnsList($columnsGrid, $columns, $catalogue, $own, $trendDays, Settings::usage($cfg), $note,
	_s('Drag rows to reorder; columns in a section stay together. The plan template\'s items are all here; a column added to the template appears on its own. Any other item of the cluster host can be added. Up to %1$s columns.', Columns::MAX));

$thresholds = $form->registerField(new CWidgetFieldRadioButtonListView($fields['thresholds_from']));
$decimals = $form->registerField(new CWidgetFieldSelectView($fields['decimals']));
$settingsGrid = new CFormGrid();
$f->row($settingsGrid, $thresholds->getLabel(), [$thresholds->getView(),
	$f->hint(_s('the plan template\'s macros: Live used above %1$s, Live days under %2$s — what its triggers alarm on, the host\'s own macros first', Columns::num($warn), Columns::num($days)))]);
$f->barsAndThresholds($settingsGrid, Settings::usage($cfg), false);
$f->row($settingsGrid, $decimals->getLabel(), [$decimals->getView(), $f->hint(_('for every figure that sets none of its own'))]);
$columnsTab = [$columnsGrid, new CFormFieldset(_('Colours and figures'), $settingsGrid)];

/* ------------------------------------------ View ------------------------------------------ */

$viewTab = (new CDiv([
	new CFormFieldset(_('Table'), $f->tableGrid(ColumnForm::sortOptions($columns, _('Cluster')), _('Rows'))),
	new CFormFieldset(_('Toolbar on the widget'), $f->toolbarGrid(['search' => _('Search'), 'type' => _('Type filter'), 'attention' => _('Needs attention'),
		'compact' => _('Compact'), 'columns' => _('Columns dialog'), 'export' => _('Export CSV / Excel')])),
	new CFormFieldset(_('Needs attention means'), $f->attentionGrid(['warn' => _('Over Warn'), 'high' => _('Over High')]))
]))->addClass('ep-ef-layout');

$tabs = $f->tabs('ep_cf_tabs', [
	'ep_cf_tab_data' => [_('Data'), $dataGrid],
	'ep_cf_tab_columns' => [_('Columns'), (new CDiv($columnsTab))->addClass('ep-ef-layout')],
	'ep_cf_tab_view' => [_('View'), $viewTab]
]);

$form
	->addItem($tabs)
	->addJavaScript($tabs->makeJavascript())
	->includeJsFile('columns.form.js.php')
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_ep_volume_form.init('.json_encode([
		'action' => 'widget.ep_volume.column.edit',
		'templateid' => $data['templateid'],
		'trend_days' => $trendDays,
		'own' => $own,
		'max' => Columns::MAX
	], JSON_THROW_ON_ERROR).');')
	->show();
