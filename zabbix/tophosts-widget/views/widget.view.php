<?php declare(strict_types = 0);
/**
 * Top hosts view: the panel, its CPU / Memory switch and an empty list that
 * class.widget.js fills from `ep_tophosts`, so switching the ranking costs no trip to Zabbix.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

$body = $data['error'] !== null
	? (new CDiv($data['error']))->addClass('ep-empty')
	: (new CTag('ol', true))->addClass('ep-top10')->setAttribute('data-ep-top', '');

$switch = (new CDiv(array_map(
	fn($k, $label) => (new CSimpleButton($label))->addClass('ep-pbtn')->setAttribute('data-ep-rank', $k)
		->setAttribute('aria-pressed', $k === $data['rank'] ? 'true' : 'false'),
	['cpu', 'mem'], [_('CPU'), _('Memory')]
)))->addClass('ep-seg')->setAttribute('role', 'group')->setAttribute('aria-label', _('Rank by'));

$view
	->addItem((new CDiv([
		(new CDiv([
			(new CSpan($data['scope'] !== '' ? $data['scope'] : _('Every client')))->addClass('ep-pt-count'),
			$data['error'] === null ? $switch : null
		]))->addClass('ep-pt'),
		(new CDiv($body))->addClass('ep-pscroll')
	]))->addClass('ep-panel')->setAttribute('data-ep-panel', 'tophosts'))
	->setVar('ep_tophosts', ['servers' => $data['servers'], 'rank' => $data['rank'], 'count' => $data['count']])
	->show();
