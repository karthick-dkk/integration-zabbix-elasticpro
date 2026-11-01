<?php declare(strict_types = 0);
/**
 * ElasticPro Play: a frame the widget's script points at Play once the ElasticPro module has
 * signed this user in (class.widget.js), a line for what went wrong, and "Full screen" / "Open in new tab".
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
			->setAttribute('allow', 'clipboard-write; fullscreen')
			->setAttribute('allowfullscreen', 'allowfullscreen'),
		(new CDiv())->addClass('ep-play-msg')->setAttribute('hidden', 'hidden'),
		(new CDiv([
			// A button, not a link: the browser allows full screen only from a click, and the
			// widget's script (class.widget.js) asks for it on this widget's own frame.
			(new CButton('ep-play-full', _('Full screen')))
				->addClass(ZBX_STYLE_BTN_ALT)
				->addClass('ep-play-full')
				->setAttribute('title', _('ElasticPro Play fills the whole screen; Esc returns to the dashboard')),
			(new CLink(_('Open in new tab'), $data['play']['full_url']))
				->addClass('ep-play-newtab')
				->setTarget('_blank')
				->setAttribute('rel', 'noopener')
				->setAttribute('title', _('ElasticPro → Play without Zabbix\'s sidebar and header, in a new tab'))
		]))->addClass('ep-play-tools')
	]))->addClass('ep-play'))
	->setVar('ep_play', $data['play'])
	->show();
