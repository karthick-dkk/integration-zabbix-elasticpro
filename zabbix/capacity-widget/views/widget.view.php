<?php declare(strict_types = 0);
/**
 * Client capacity widget view: a table of clients, one row each, over the columns the widget (or
 * the report defaults) lists.
 *
 * Everything drawn here was decided by the controller (WidgetView, ColumnReport, ColumnCell): the
 * text, the level, the colours (strict #RRGGBB, into custom properties only), the links (Zabbix
 * pages only, built by ColumnMath::url). ColumnTable turns those into tags; Zabbix escapes every
 * string.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpCapacity\Includes\ColumnTable;

$view = new CWidgetView($data);
$opt = $data['view'];
$panel = fn(array $items) => ColumnTable::panel($items, 'capacity', $opt, (string) $data['palette']);

if ($data['error'] !== null) {
	$view->addItem($panel([(new CDiv($data['error']))->addClass('ep-empty')]))->show();
	return;
}

$toolbar = ColumnTable::toolbar($data, $opt, [
	'count' => ['text' => _n('%1$s client', '%1$s clients', count($data['rows'])), 'template' => _('%n clients')],
	'search' => ['placeholder' => _('Client or URL'), 'label' => _('Search clients')],
	'pie' => null,
	'expand' => null
]);

if (!$data['rows']) {
	// With nothing to list, say so where it can be seen. Centred across two dozen columns, the table's
	// own empty row puts the message far off to the right of a widget this wide.
	$items = [$toolbar, (new CDiv(_('No client master hosts yet. Create a host with the "ElasticPro client master" template and set its macros — clients appear here, and export is enabled.')))
		->addClass('ep-empty')];
}
else {
	$items = [$toolbar, ColumnTable::scroll(ColumnTable::table($data, $opt), $opt), ColumnTable::dropped($data['dropped'])];
}

$view
	->addItem($panel($items))
	->setVar('export', $data['export'])
	->setVar('ep_meta', $data['meta'])
	->show();
