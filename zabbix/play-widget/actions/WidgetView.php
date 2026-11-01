<?php declare(strict_types = 0);

namespace Modules\EpPlay\Actions;

use APP,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CCsrfTokenHelper,
	CUrl,
	CWebUser;

/**
 * ElasticPro Play in a dashboard widget: a frame sized to the widget, showing the same page as
 * ElasticPro → Play.
 *
 * Nothing about the sign-in is decided here. The frame's address — ElasticPro with a one-time
 * code for the Zabbix user looking at the dashboard — comes from the ElasticPro module's
 * `elasticpro.play.frame` action (embed-module/actions/PlayFrame.php, through its lib/SignIn.php,
 * the same sign-in as its pages), which the widget's script asks each time it loads the frame.
 * So this answer carries no code, nothing that outlives the page, and nothing a person who can
 * only view the dashboard could not get by opening ElasticPro → Play themselves; and who may see
 * Play is exactly who may open the ElasticPro pages.
 *
 * Play's own URL takes no slide list, so the widget has no fields of its own: the slides, their
 * timing and the zoom are Play's settings (ElasticPro Config › Play). A display link is not
 * offered either: its secret would be stored in the dashboard (readable through dashboard.get by
 * everyone who can see the dashboard) and sent to every viewer's browser.
 */
class WidgetView extends CControllerDashboardWidgetView {

	/** The ElasticPro module's id, as installed (embed-module, `modules/elasticpro`). */
	public const EMBED_MODULE = 'elasticpro';

	/** The ElasticPro module's action that answers the frame's address. */
	public const FRAME_ACTION = 'elasticpro.play.frame';

	/** The ElasticPro module's Play page. */
	public const PAGE_ACTION = 'elasticpro.play';

	protected function doAction(): void {
		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'error' => null,
			'play' => null,
			'user' => ['debug_mode' => $this->getDebugMode()]
		];
		if (CWebUser::isGuest()) {
			// The guest user has no ElasticPro role; the ElasticPro module refuses it too.
			$data['error'] = _('ElasticPro Play is not available to the guest user. Sign in to Zabbix to see it.');
		}
		elseif (!self::embedAvailable()) {
			$data['error'] = self::unavailableMessage();
		}
		else {
			$data['play'] = self::play(CCsrfTokenHelper::get(self::FRAME_ACTION));
		}
		$this->setResponse(new CControllerResponseData($data));
	}

	/**
	 * Can this user reach the ElasticPro module? Zabbix loads only the modules that are enabled
	 * AND that this user's role may access, so "no" has two causes this cannot tell apart.
	 */
	protected static function embedAvailable(): bool {
		return APP::ModuleManager()->getModule(self::EMBED_MODULE) !== null;
	}

	/** Why the widget cannot open Play when the module is not loaded for this user: both causes. */
	public static function unavailableMessage(): string {
		return _('ElasticPro Play opens through the ElasticPro module (installed as "elasticpro"), which is not available to you: either it is not enabled in this Zabbix (a Zabbix Super admin enables it under Administration → General → Modules, then pairs it under Administration → ElasticPro), or your Zabbix user role does not allow it (Users → User roles → Access to modules).');
	}

	/**
	 * What the widget's script needs: where to ask for the frame's address (with the CSRF token
	 * Zabbix checks on that POST, which is this user's own) and the full-screen page.
	 */
	public static function play(string $csrf_token): array {
		return [
			'frame_url' => (new CUrl('zabbix.php'))->setArgument('action', self::FRAME_ACTION)->getUrl(),
			'csrf_name' => CSRF_TOKEN_NAME,
			'csrf' => $csrf_token,
			'full_url' => (new CUrl('zabbix.php'))
				->setArgument('action', self::PAGE_ACTION)
				// Not `kiosk`: Zabbix would save that into the person's profile (web.layout.mode).
				->setArgument('fullscreen', '1')
				->getUrl()
		];
	}
}
