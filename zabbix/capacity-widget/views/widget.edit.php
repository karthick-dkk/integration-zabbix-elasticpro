<?php declare(strict_types = 0);
/**
 * Client capacity settings, on three tabs: Data, Columns and View.
 *
 * Columns are listed here and edited in their own dialog (ColumnEdit); each row carries the column
 * as hidden fields, and the form posts them in the order the rows were dragged into. While the
 * widget follows the report defaults the list is shown as it is and cannot be changed here —
 * choosing "This widget" starts the widget's own list from them. An empty Warn or High follows
 * ElasticPro -> Settings, and the form says so with the value that applies.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpCapacity\Includes\{Catalogue, ColumnForm, WidgetForm};
use Modules\EpCapacity\Lib\{ColumnSettings, Columns, Settings};

$fields = $data['fields'];
$cfg = Settings::all();
$model = ColumnSettings::loadModel(Catalogue::REPORT);
$catalogue = $fields['columns']->getCatalogue();
$own = (int) $fields['columns_from']->getValue() === WidgetForm::FROM_WIDGET;
$columns = $own ? $fields['columns']->getValue() : ColumnSettings::resolve($model['columns'], $catalogue, $model['legacy'], $model['new_hidden']);
$trendDays = (int) $fields['trend_days']->getValue();

$form = new CWidgetFormView($data);
$f = new ColumnForm($form, $fields);

/* ------------------------------------------ Data ------------------------------------------ */

$dataGrid = new CFormGrid();
$f->who($dataGrid, $cfg);
$f->trendRow($dataGrid);

/* ----------------------------------------- Columns ----------------------------------------- */

$columnsGrid = new CFormGrid();
$f->columnsFrom($columnsGrid, $own);
$f->columnsList($columnsGrid, $columns, $catalogue, $own, $trendDays, Settings::usage($cfg),
	null, _s('Drag rows to reorder; columns in a group stay together. Up to %1$s columns.', Columns::MAX));
$settingsGrid = new CFormGrid();
$f->barsAndThresholds($settingsGrid, Settings::usage($cfg));
$columnsTab = [$columnsGrid, new CFormFieldset(_('Bars and thresholds'), $settingsGrid)];

/* ------------------------------------------ View ------------------------------------------ */

$viewTab = (new CDiv([
	new CFormFieldset(_('Table'), $f->tableGrid(ColumnForm::sortOptions($columns, _('Client')))),
	new CFormFieldset(_('Toolbar on the widget'), $f->toolbarGrid(['search' => _('Search'), 'type' => _('Type filter'), 'attention' => _('Needs attention'),
		'compact' => _('Compact'), 'columns' => _('Columns dialog'), 'export' => _('Export CSV / Excel')])),
	new CFormFieldset(_('Needs attention means'), $f->attentionGrid(['warn' => _('Over Warn'), 'high' => _('Over High'),
		'short' => _('Short (allocated below requested)'), 'disk30' => _('Storage full within 30 d')]))
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
	->addJavaScript('widget_ep_capacity_form.init('.json_encode([
		'action' => 'widget.ep_capacity.column.edit',
		'templateid' => $data['templateid'],
		'trend_days' => $trendDays,
		'own' => $own,
		'max' => Columns::MAX
	], JSON_THROW_ON_ERROR).');')
	->show();
