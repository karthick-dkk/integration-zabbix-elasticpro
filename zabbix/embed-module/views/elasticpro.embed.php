<?php declare(strict_types = 1);
/**
 * @var CView $this
 * @var array $data  ['configured', 'frame_url', 'origin', 'error', 'fullscreen']
 *
 * Each failure here carries an error code from the module's range, 7xx (the core uses
 * 1xx-5xx, the ElasticPro page 6xx; docs/ERROR-CODES.md in the ElasticPro repository lists them all):
 *   EP-701  not paired with ElasticPro yet
 *   EP-702  ElasticPro refused to sign this Zabbix user in (its own code follows the reason)
 *   EP-703  signed in, then lost the session three times in a minute
 */

if (!$data['configured']) {
	$body = (new CDiv(CWebUser::getType() == USER_TYPE_SUPER_ADMIN
		? [
			_('ElasticPro is not connected yet. Pair it under '),
			new CLink(_('Administration → ElasticPro'),
				(new CUrl('zabbix.php'))->setArgument('action', 'elasticpro.link.edit')
			),
			_(' (or, managed on the server, copy modules/elasticpro/config.php.example to config.php).'),
			' (EP-701)'
		]
		: _('ElasticPro is not connected yet. A Zabbix Super admin pairs it under Administration → ElasticPro.').' (EP-701)'
	))->addClass('ep-error');
}
elseif ($data['frame_url'] === null) {
	$body = (new CDiv([
		new CTag('b', true, _('ElasticPro could not sign you in.').' (EP-702)'),
		BR(),
		// The reason in full for a Super admin; for other users only ElasticPro's own codes in it
		// (SignIn::reason): it can name the core's internal address.
		$data['error'] !== '' ? $data['error'] : null,
		CWebUser::getType() == USER_TYPE_SUPER_ADMIN ? null
			: [$data['error'] !== '' ? BR() : null, _('A Zabbix Super admin sees the reason on this page.')]
	]))->addClass('ep-error');
}
else {
	$body = (new CTag('iframe', true))
		->setAttribute('src', $data['frame_url'])
		->setAttribute('title', 'ElasticPro')
		->setAttribute('referrerpolicy', 'no-referrer')
		->setAttribute('allow', 'clipboard-write')
		->addClass('ep-frame');
}

// Play's "Open full screen" (fullscreen=1): this one response is drawn without Zabbix's sidebar
// and header — the view's layout mode, set for this response only (CView::setLayoutMode writes
// nothing) — with our own "Exit full screen" link back to plain elasticpro.play. Zabbix's
// `kiosk` URL parameter and its layout.mode.js are deliberately not used: Zabbix saves `kiosk`
// into the person's profile (web.layout.mode) on every request, so every page they opened
// afterwards would be in kiosk mode too. A Zabbix without CView::setLayoutMode() draws the page
// in the normal layout.
$fullscreen = $data['fullscreen'] && method_exists($this, 'setLayoutMode');
if ($fullscreen) {
	$this->enableLayoutModes();
	$this->setLayoutMode(ZBX_LAYOUT_KIOSKMODE);
}

// No setTitle(): that draws a heading over the frame, and the page is the frame. The
// browser tab still gets the name, from the response (see View::doAction). The page itself stays
// in the normal mode, so Zabbix adds none of its own kiosk controls.
(new CHtmlPage())
	->addItem((new CDiv([
		$body,
		$fullscreen
			? (new CLink(_('Exit full screen'), (new CUrl('zabbix.php'))->setArgument('action', 'elasticpro.play')))
				->addClass('ep-exit-full')
			: null
	]))->addClass('ep-wrap')->addClass($fullscreen ? 'ep-full' : null))
	->show();
$origin = $data['origin'];
?>
<script>
	/* The app in the frame asks for this when its session ends — the core restarted, or it
	   sat idle. Loading this page again mints a fresh one-time code for the same Zabbix user,
	   so the person is signed straight back in instead of meeting a login form.
	   Only the ElasticPro origin is listened to, and at most three times a minute: a
	   sign-in that keeps failing must end in its error, not in a page that reloads forever. */
	(function () {
		var origin = <?= json_encode($origin) ?>;
		window.addEventListener('message', function (e) {
			if (!origin || e.origin !== origin || !e.data) return;
			if (e.data.type !== 'elasticpro:reauth') return;
			var key = 'elasticpro.reauth', now = Date.now(), recent = [];
			try { recent = JSON.parse(sessionStorage.getItem(key) || '[]').filter(function (t) { return now - t < 60000; }); } catch (_) {}
			if (recent.length >= 3) {
				var box = document.querySelector('.ep-wrap');
				/* Name the likely cause. The old wording said only that it had retried
				   three times and to read the server log, which is true and useless: the
				   one condition that actually produces this loop is the two sides no
				   longer sharing a pairing secret — reinstall either half and the module
				   still believes it is paired, because its secret lives in the Zabbix
				   database while the core keeps its own in its data directory. That is a
				   permanent state no amount of reloading fixes, so say what to do. */
				if (box) box.innerHTML = '<div class="ep-error"><b>ElasticPro could not keep you signed in. (EP-703)</b><br>'
					+ 'It asked Zabbix to sign you in again three times in a minute, so it stopped retrying.<br><br>'
					+ 'This almost always means Zabbix and ElasticPro no longer share a pairing secret — '
					+ 'which happens when either side is reinstalled or its data reset, because Zabbix keeps '
					+ 'the secret in its database and ElasticPro keeps its half in its data directory. '
					+ 'Reloading will not fix that: a Zabbix Super admin has to re-pair the two under '
					+ '<b>Administration \u2192 ElasticPro</b>.<br><br>'
					+ 'The ElasticPro server log gives the exact reason on <code>/sso/zabbix</code> — '
					+ '<code>EP-306 not_configured</code> (ElasticPro has no secret), <code>EP-510 bad_signature</code> '
					+ '(the two secrets differ) or <code>EP-511 stale</code> (the clocks disagree).</div>';
				return;
			}
			recent.push(now);
			try { sessionStorage.setItem(key, JSON.stringify(recent)); } catch (_) {}
			location.reload();
		});
	})();
</script>
<style>
	/* Full height, no Zabbix footer band under a short frame: the wrapper flex-fills the
	   main column and the frame fills the wrapper. Scoped to these pages only. */
	.wrapper > footer[role="contentinfo"] { display: none !important; }
	.wrapper main { padding: 0 !important; }
	.ep-wrap { display: flex; flex: 1 1 auto; min-height: calc(100vh - 60px); }
	.ep-frame { flex: 1 1 auto; width: 100%; min-height: calc(100vh - 60px); border: 0; }
	.ep-wrap.ep-full, .ep-full .ep-frame { min-height: 100vh; }
	.ep-wrap.ep-full { position: relative; }
	.ep-exit-full { position: absolute; top: 6px; right: 6px; z-index: 2; padding: 3px 10px; border-radius: 3px;
	                font-size: 12px; background: rgba(0, 0, 0, .55); color: #fff !important; text-decoration: none;
	                opacity: .35; transition: opacity .15s; }
	.ep-exit-full:hover, .ep-exit-full:focus { opacity: 1; }
	.ep-error { margin: 40px auto; max-width: 640px; padding: 14px 16px; border-radius: 4px;
	             border: 1px solid #e45959; background: #fef6f6; color: #7a2020; line-height: 1.6; }
</style>
