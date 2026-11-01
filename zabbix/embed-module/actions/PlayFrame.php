<?php declare(strict_types = 1);

namespace Modules\ElasticPro\Actions;

require_once __DIR__.'/../lib/SignIn.php';

use CController;
use CControllerResponseData;
use CWebUser;
use Modules\ElasticPro\Lib\SignIn;

/**
 * The address of Play (`#/play`) for the "ElasticPro Play" dashboard widget, signed in as the
 * Zabbix user who is looking at the dashboard — the same sign-in as the ElasticPro pages
 * (lib/SignIn.php), asked for each time the widget loads its frame, so the one-time code in it
 * is never older than the frame.
 *
 * POST with Zabbix's CSRF token for this action (the widget's controller hands it to the
 * widget's script). Answers JSON:
 *   {"url": "<ElasticPro>/?sso_code=…&embed=1&zbx_theme=…#/play", "origin": "<ElasticPro origin>"}
 *   {"error": {"code": "EP-701" | "EP-702", "message": "…", "reason": "…"}}
 * The codes are the ElasticPro page's (views/elasticpro.embed.php), worded the same way.
 *
 * Who may ask: whoever may open the ElasticPro pages (View) — any Zabbix user, as there.
 * No new permission: a Zabbix role that denies this module denies this action with it.
 */
class PlayFrame extends CController {

	protected function checkInput(): bool {
		return $this->validateInput([]);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	/** What the widget gets back, from what the sign-in answered. Pure, for the tests. */
	public static function answer(array $sign, bool $super_admin): array {
		if (!$sign['configured']) {
			return ['error' => [
				'code' => 'EP-701',
				'message' => $super_admin
					? 'ElasticPro is not connected yet. Pair it under Administration → ElasticPro.'
					: 'ElasticPro is not connected yet. A Zabbix Super admin pairs it under Administration → ElasticPro.',
				'reason' => ''
			]];
		}
		if ($sign['url'] === null) {
			return ['error' => [
				'code' => 'EP-702',
				'message' => 'ElasticPro could not sign you in.',
				'reason' => (string) $sign['error']
			]];
		}
		return ['url' => $sign['url'], 'origin' => SignIn::origin((string) $sign['public_url'])];
	}

	protected function doAction(): void {
		$answer = self::answer(SignIn::frame('play'), CWebUser::getType() == USER_TYPE_SUPER_ADMIN);
		$this->setResponse((new CControllerResponseData([
			'main_block' => json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
		]))->disableView());
	}
}
