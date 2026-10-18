<?php declare(strict_types = 0);
/**
 * Client resources settings, on three tabs: Data, Columns and View.
 *
 * The parts every column widget has — who is listed, the columns list and the server rows' columns
 * (each edited in the column editor, ColumnEdit), Bars and Warn / High, the table, toolbar and Needs
 * attention — are the shared ColumnForm's, with its behaviour (columns.form.js.php); what is Client
 * resources' alone, the view (table, pie chart, or both for viewers to switch) and the donuts, is
 * drawn here. While the widget follows the report defaults both lists are shown as they are and
 * cannot be changed here — choosing "This widget" starts the widget's own lists from them. An empty
 * Warn or High follows ElasticPro -> Settings, and the form says so with the value that applies.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpResources\Includes\{Catalogue, ColumnForm, WidgetForm};
use Modules\EpResources\Lib\{Columns, ColumnSettings, Roles, Settings};

$fields = $data['fields'];
$cfg = Settings::all();
$roles = Roles::load();
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
$f = new ColumnForm($form, $fields);

/* ------------------------------------------ Data ------------------------------------------ */

$dataGrid = new CFormGrid();
$f->who($dataGrid, $cfg);
$f->trendRow($dataGrid);

/* ----------------------------------------- Columns ----------------------------------------- */

$columnsGrid = new CFormGrid();
$f->columnsFrom($columnsGrid, $own);
$f->columnsList($columnsGrid, $columns, $catalogue, $own, $trendDays, $usage, null,
	_s('Drag rows to reorder; columns in a group stay together. New roles from Cluster Management appear here on their own, unticked. Up to %1$s columns.', Columns::MAX));
$columnsSet = new CFormGrid();
$f->serverRows($columnsSet, $serverColumns, $own);
$f->barsAndThresholds($columnsSet, $usage);
$columnsTab = [$columnsGrid, new CFormFieldset(_('Server rows and thresholds'), $columnsSet)];

/* ------------------------------------------ View ------------------------------------------ */

$viewField = $f->view(new CWidgetFieldRadioButtonListView($fields['view']));
$switch = $f->view(new CWidgetFieldCheckBoxView($fields['view_switch']));
$viewGrid = new CFormGrid();
$f->row($viewGrid, $viewField->getLabel(), (new CDiv([$viewField->getView(), $switch->getView(),
	$f->hint(_('a Table / Pie chart switch on the widget\'s toolbar'))]))->addClass('ep-ef-inline'));

// Pie chart: Client resources' own.
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
$centre = $f->view(new CWidgetFieldSelectView($fields['pie_centre']));
$short = $f->view(new CWidgetFieldRadioButtonListView($fields['pie_short']));
$perRow = $f->view(new CWidgetFieldSelectView($fields['pie_per_row']));
$size = $f->view(new CWidgetFieldSelectView($fields['pie_size']));
$chips = $f->view(new CWidgetFieldCheckBoxView($fields['pie_chips']));
$pieGrid = new CFormGrid();
$f->row($pieGrid, new CLabel(_('Donuts')), [(new CVar('pie_shown[]', ''))->removeId(), $donuts,
	$f->hint(_('Drag to reorder. Any figure with used, allocated and requested can be a donut.'))]);
$f->row($pieGrid, $centre->getLabel(), $centre->getView());
$f->row($pieGrid, new CLabel(_('Legend rows')), $f->boxes('pie_legend', ['used' => _('Used'), 'free' => _('Free'), 'short' => _('Short'),
	'allocated' => _('Allocated'), 'requested' => _('Requested'), 'pct' => _('with %')], $fields['pie_legend']->getValue()));
$f->row($pieGrid, $short->getLabel(), $short->getView());
$f->row($pieGrid, new CLabel(_('Layout')), (new CDiv([new CSpan(_('donuts per row')), $perRow->getView(), new CSpan(_('size')), $size->getView(),
	$chips->getView()]))->addClass('ep-ef-inline'));

$viewTab = (new CDiv([
	$viewGrid,
	(new CFormFieldset(_('Pie chart'), $pieGrid))->addClass('ep-res-pie-only'),
	(new CFormFieldset(_('Table'), $f->tableGrid(ColumnForm::sortOptions($columns, _('Client')))))->addClass('ep-res-table-only'),
	// No "Table / Pie chart" item here: Viewers may switch on the widget puts that switch on the toolbar.
	new CFormFieldset(_('Toolbar on the widget'), $f->toolbarGrid(['search' => _('Search'), 'type' => _('Type filter'), 'attention' => _('Needs attention'),
		'compact' => _('Compact'), 'expand' => _('Expand all'), 'columns' => _('Columns dialog'),
		'export' => _('Export CSV / Excel')])),
	new CFormFieldset(_('Needs attention means'), $f->attentionGrid(['warn' => _('Over Warn'), 'high' => _('Over High'), 'down' => _('Server down'),
		'short' => _('Short (allocated below requested)'), 'nodata' => _('No data'), 'disk30' => _('Disk full within 30 d')]))
]))->addClass('ep-ef-layout');

$tabs = $f->tabs('ep_res_tabs', [
	'ep_res_tab_data' => [_('Data'), $dataGrid],
	'ep_res_tab_columns' => [_('Columns'), (new CDiv($columnsTab))->addClass('ep-ef-layout')],
	'ep_res_tab_view' => [_('View'), $viewTab]
]);

$form
	->addItem($tabs)
	->addJavaScript($tabs->makeJavascript())
	->includeJsFile('columns.form.js.php')
	->includeJsFile('widget.edit.js.php')
	->addJavaScript('widget_ep_resources_form.init('.json_encode([
		'action' => 'widget.ep_resources.column.edit',
		'templateid' => $data['templateid'],
		'trend_days' => $trendDays,
		'own' => $own,
		'max' => Columns::MAX
	], JSON_THROW_ON_ERROR).');')
	->show();
