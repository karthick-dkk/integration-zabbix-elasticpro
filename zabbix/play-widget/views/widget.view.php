<?php declare(strict_types = 0);
/**
 * ElasticPro Play: a frame the widget's script points at Play once the ElasticPro module has
 * signed this user in (class.widget.js), a line for what went wrong, and "Open full screen".
 * The frame starts empty; no address, code or secret is written into this HTML.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

if ($data['error'] !== null) {
	$view->addItem((new CDiv($data['error']))->addClass('ep-play-msg')->addClass('ep-play-alone'))->show();
	return;
}

$view
	->addItem((new CDiv([
		(new CTag('iframe', true))
			->addClass('ep-play-frame')
			->setAttribute('title', _('ElasticPro Play'))
			->setAttribute('referrerpolicy', 'no-referrer')
			->setAttribute('allow', 'clipboard-write'),
		(new CDiv())->addClass('ep-play-msg')->setAttribute('hidden', 'hidden'),
		(new CLink(_('Open full screen'), $data['play']['full_url']))
			->addClass('ep-play-full')
			->setTarget('_blank')
			->setAttribute('rel', 'noopener')
			->setAttribute('title', _('ElasticPro → Play without Zabbix\'s sidebar and header, in a new tab'))
	]))->addClass('ep-play'))
	->setVar('ep_play', $data['play'])
	->show();
