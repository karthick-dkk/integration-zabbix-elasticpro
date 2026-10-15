<?php declare(strict_types = 0);
/**
 * Client overview view: the panel, a toolbar (count, search, Type) that shows on hover, and the
 * pane ep-overview.js draws from `ep_overview`.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

if ($data['error'] !== null) {
	$view->addItem((new CDiv((new CDiv($data['error']))->addClass('ep-empty')))->addClass('ep-panel')->setAttribute('data-ep-panel', 'overview'))->show();
	return;
}
$n = count($data['overview']['clients']);
$toolbar = (new CDiv([
	(new CSpan(_n('%1$s client', '%1$s clients', $n)))->addClass('ep-pt-count'),
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
	->addItem((new CDiv([$toolbar, (new CDiv(''))->addClass('ep-ov ep-pscroll')]))->addClass('ep-panel')->setAttribute('data-ep-panel', 'overview'))
	->setVar('ep_overview', $data['overview'])
	->show();
