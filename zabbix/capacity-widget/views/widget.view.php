<?php declare(strict_types = 0);
/**
 * Client capacity widget view.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

if ($data['error'] !== null) {
	$view->addItem((new CTableInfo())->setNoDataMessage($data['error']));
}
else {
	$toolbar = (new CDiv([
		(new CSpan(_n('%1$s client', '%1$s clients', count($data['rows']))))->addClass('ep-cap-count ep-pt-count')->setAttribute('data-ep-count', '')
			->setAttribute('data-template', _('%n clients')),
		(new CLabel([(new CSpan('⌕'))->setAttribute('aria-hidden', 'true'),
			(new CTag('input', false))->setAttribute('type', 'search')->setAttribute('data-ep-search', '')
				->setAttribute('placeholder', _('Client or URL'))->setAttribute('aria-label', _('Search clients'))
		]))->addClass('ep-pt-search'),
		new CLabel(_('Type')),
		// The types the site has, from ElasticPro -> Settings. Hard-coded here before, which is
		// how CI came to be missing from a filter whose list has always had it.
		(new CTag('select', true, array_merge([(new CTag('option', true, _('All')))->setAttribute('value', '')],
			array_map(fn($t) => (new CTag('option', true, $t))->setAttribute('value', $t), $data['meta']['types'])
		)))->setAttribute('data-ep-type-filter', '')->setAttribute('aria-label', _('Client type')),
		// Table settings, the report's Columns and its exports, in the panel menu.
		(new CDiv([
			(new CSimpleButton('⋮'))->addClass('ep-pbtn')->setAttribute('data-ep-menu', '')->setAttribute('aria-haspopup', 'true')
				->setAttribute('aria-expanded', 'false')->setAttribute('title', _('Table settings, columns and export')),
			(new CDiv([
				(new CSimpleButton(_('Table settings').'…'))->setAttribute('data-ep-settings', '')->setAttribute('aria-expanded', 'false'),
				(new CSimpleButton(_('Columns').'…'))->setAttribute('data-ep-columns', ''),
				(new CSimpleButton(_('Export CSV')))->setAttribute('data-ep-export', 'csv'),
				(new CSimpleButton(_('Export Excel')))->setAttribute('data-ep-export', 'xlsx')
			]))->addClass('ep-menu-pop')->setAttribute('hidden', 'hidden')
		]))->addClass('ep-menu')
	]))->addClass('ep-cap-toolbar ep-pt');

	$header = [];
	foreach ($data['columns'] as $i => $column) {
		$header[] = (new CColHeader($column['label']))->addClass($i === 0 ? 'ep-cap-first' : null);
	}

	$table = (new CTableInfo())
		->addClass('ep-cap-table')
		->setHeader($header)
		->setNoDataMessage(_('No client master hosts. Link "ElasticPro client master" to a host and set its macros.'));

	foreach ($data['rows'] as $row) {
		$cols = [];
		foreach ($row['cells'] as $i => $cell) {
			$col = (new CCol(\Modules\EpCapacity\Lib\Bars::cell($cell['text'], $cell['bar'] ?? null, $data['bars'] ?? 'under', $cell['class'])))
				->addClass($cell['class'] !== '' ? $cell['class'] : null)
				->addClass(($cell['bar'] ?? null) !== null && ($data['bars'] ?? 'under') !== 'none' ? 'ep-barcell' : null);
			if ($i === 0) {
				$col->addClass('ep-cap-first');
			}
			if ($cell['hint'] !== '') {
				$col->setAttribute('title', $cell['hint']);
			}
			$cols[] = $col;
		}
		$table->addRow((new CRow($cols))->setAttribute('data-type', $row['type']));
	}

	// With nothing to list, say so where it can be seen. Centred across two dozen columns, the table's
	// own empty row puts the message far off to the right of a widget this wide.
	$content = $data['rows']
		? (new CDiv($table))->addClass('ep-cap-scroll ep-pscroll')
		: (new CDiv(_('No client master hosts yet. Create a host with the "ElasticPro client master" template and set its macros — clients appear here, and export is enabled.')))->addClass('ep-empty');

	$view
		->addItem((new CDiv([$toolbar, $content]))->addClass('ep-panel')->setAttribute('data-ep-panel', 'capacity'))
		->setVar('export', $data['export'])
		->setVar('ep_meta', $data['meta']);
}

$view->show();
