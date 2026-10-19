<?php declare(strict_types = 0);
/**
 * Volume report widget view: sections over their columns, as the Volume report page lays the
 * client plan out — a row per cluster, the plan's sections (Volume, Live storage, Backup storage)
 * over their columns, the cluster fixed on the left.
 *
 * Everything drawn here was decided by the controller (WidgetView, ColumnReport, ColumnCell): the
 * text, the level, the colours (strict #RRGGBB, into custom properties only), the links (Zabbix
 * pages only). ColumnTable turns those into tags; Zabbix escapes every string.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpVolume\Includes\ColumnTable;

$view = new CWidgetView($data);
$opt = $data['view'];
$panel = fn(array $items) => ColumnTable::panel($items, 'volume', $opt, (string) $data['palette']);

if ($data['error'] !== null) {
	$view->addItem($panel([(new CDiv($data['error']))->addClass('ep-empty')]))->show();
	return;
}

$toolbar = ColumnTable::toolbar($data, $opt, [
	'count' => ['text' => _n('%1$s cluster', '%1$s clusters', count($data['rows'])), 'template' => _('%n clusters')],
	'search' => ['placeholder' => _('Cluster'), 'label' => _('Search clusters')],
	'pie' => null,
	'expand' => null
]);

// With nothing to list, say so where it can be seen: centred across this many columns, an empty
// table row puts the message far off to the right.
$items = $data['rows']
	? [$toolbar, ColumnTable::scroll(ColumnTable::table($data, $opt), $opt), ColumnTable::dropped($data['dropped'])]
	: [$toolbar, (new CDiv(_('No cluster hosts with the "ElasticPro client plan" template yet — clusters appear here once ElasticPro sends their plan, and export is enabled.')))
		->addClass('ep-empty')];

$view
	->addItem($panel($items))
	->setVar('export', $data['export'])
	->setVar('ep_meta', $data['meta'])
	->show();
