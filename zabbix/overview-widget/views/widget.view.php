<?php declare(strict_types = 0);
/**
 * Client overview view: the panel, a toolbar (count, search, Type) that shows on hover, and the
 * cards ep-overview.js draws from `ep_overview`.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

if ($data['error'] !== null) {
	$view->addItem((new CDiv((new CDiv($data['error']))->addClass('ep-empty')))->addClass('ep-panel')->setAttribute('data-ep-panel', 'overview')
		// ElasticPro -> Settings' palette, for the theme this user chose.
		->setAttribute('style', \Modules\EpOverview\Lib\Settings::paletteStyle()))->show();
	return;
}
$n = count($data['overview']['clients']);
$total = (int) $data['overview']['total'];
$toolbar = (new CDiv([
	(new CSpan($n === $total ? _n('%1$s client', '%1$s clients', $n) : _s('%1$s of %2$s clients', $n, $total)))->addClass('ep-pt-count'),
	(new CLabel([(new CSpan('⌕'))->setAttribute('aria-hidden', 'true'),
		(new CTag('input', false))->setAttribute('type', 'search')->setAttribute('data-ep-search', '')
			->setAttribute('placeholder', _('Client or host'))->setAttribute('aria-label', _('Search clients or hosts'))
	]))->addClass('ep-pt-search'),
	new CLabel(_('Type')),
	(new CTag('select', true, array_merge([(new CTag('option', true, _('All')))->setAttribute('value', '')],
		array_map(fn($t) => (new CTag('option', true, $t))->setAttribute('value', $t), $data['overview']['settings']['types'])
	)))->setAttribute('data-ep-type-filter', '')->setAttribute('aria-label', _('Client type'))
]))->addClass('ep-pt');

$view
	->addItem((new CDiv([$toolbar, (new CDiv(''))->addClass('ep-ov ep-pscroll')->addClass(($data['overview']['view'] ?? 'cards') === 'summary' ? 'ep-ov-sv' : 'ep-ov-cv')]))->addClass('ep-panel')->setAttribute('data-ep-panel', 'overview')
		// ElasticPro -> Settings' palette, for the theme this user chose.
		->setAttribute('style', \Modules\EpOverview\Lib\Settings::paletteStyle()))
	->setVar('ep_overview', $data['overview'])
	->show();
