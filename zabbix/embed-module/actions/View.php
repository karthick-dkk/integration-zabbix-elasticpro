<?php declare(strict_types = 1);

namespace Modules\ElasticPro\Actions;

require_once __DIR__.'/../lib/SignIn.php';

use API;
use CController;
use CControllerResponseData;
use CControllerResponseRedirect;
use CUrl;
use Modules\ElasticPro\Lib\SignIn;
use Modules\ElasticPro\Module;

/**
 * One controller for every ElasticPro page. The action name says which page:
 * `elasticpro.indices` opens the app at `#/indices`.
 *
 * Each page load mints its own code (lib/SignIn.php, the one sign-in every ElasticPro page
 * and the Play widget share). Codes are single-use and cheap, and a page that carried a
 * long-lived session in its HTML would be a session anyone with the page source could reuse.
 *
 * `elasticpro.play` opens Play, ElasticPro's NOC wall display (`#/play`). With `fullscreen=1`
 * (the Play widget's "Open full screen") this one response is drawn without Zabbix's sidebar
 * and header, with an "Exit full screen" link back to plain `elasticpro.play`. Not `kiosk=1`:
 * Zabbix saves that parameter into the person's profile on every request (web.layout.mode),
 * which would put every other page they open into kiosk mode too.
 */
class View extends CController {

	protected function init(): void {
		// GET only, and nothing here changes state in Zabbix. The one side effect — an
		// account created or refreshed in ElasticPro — is that app's to guard, and it
		// does, with the signature.
		$this->disableCsrfValidation();
	}

	/**
	 * The troubleshoot action takes what a Zabbix problem says about itself, filled in by the
	 * "Troubleshoot in ElasticPro" script. All of it is passed to the app as text, which
	 * treats it as a hint — the cluster is still one this user can see, or none. The Play
	 * page takes `fullscreen`.
	 */
	protected function checkInput(): bool {
		return $this->validateInput([
			'zbx_host' => 'string',
			'client' => 'string',
			'rule' => 'string',
			'problem' => 'string',
			'fullscreen' => 'in 0,1',
		]);
	}

	/** Play asked for full screen (this response only; nothing is saved). */
	private function fullscreen(): bool {
		return $this->page() === 'play' && (string) $this->getInput('fullscreen', '0') === '1';
	}

	/** The troubleshoot request, trimmed to what a URL should carry. */
	private function trouble(): array {
		if ($this->getAction() !== 'elasticpro.troubleshoot') {
			return [];
		}
		$out = [];
		foreach (['zbx_host', 'client', 'rule', 'problem'] as $k) {
			$v = trim((string) $this->getInput($k, ''));
			if ($v !== '') {
				$out[$k] = mb_substr($v, 0, 255);
			}
		}
		return $out;
	}

	private function page(): string {
		$page = substr($this->getAction(), strlen('elasticpro.'));
		return array_key_exists($page, Module::PAGES) || array_key_exists($page, Module::ADMIN_PAGES)
			? $page : 'overview';
	}

	protected function checkPermissions(): bool {
		// Guests have no ElasticPro role at all (SignIn::mayOpen: nor the guest user, whose
		// role is of the User type).
		if (!SignIn::mayOpen()) {
			return false;
		}
		if (array_key_exists($this->page(), Module::ADMIN_PAGES)) {
			return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
		}
		return true;
	}

	/**
	 * Alerts are Zabbix problems. The Alerts menu item opens Problems filtered to the host
	 * groups this module's hosts live in — clusters and the forwarder, parser and node
	 * roles — rather than a second list inside the app.
	 */
	private function problemsUrl(): CUrl {
		$groups = API::HostGroup()->get([
			'output' => ['groupid'],
			'filter' => ['name' => ['Elasticsearch clusters', 'Forwarders', 'Parsers', 'ESNodes', 'Engines']],
		]);
		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('filter_set', '1');
		if ($groups) {
			$url->setArgument('groupids', array_column($groups, 'groupid'));
		}
		return $url;
	}

	protected function doAction(): void {
		if ($this->page() === 'alerts') {
			$this->setResponse(new CControllerResponseRedirect($this->problemsUrl()));
			return;
		}
		$page = $this->page();
		$sign = SignIn::frame($page, $this->trouble());

		$labels = Module::PAGES + Module::ADMIN_PAGES;
		$response = new CControllerResponseData([
			'configured' => $sign['configured'],
			'frame_url' => $sign['url'],
			'origin' => SignIn::origin($sign['public_url']),
			// The reason in full for a Super admin; ElasticPro's own codes only for anyone else.
			'error' => SignIn::reason($sign['error'], $this->getUserType() == USER_TYPE_SUPER_ADMIN),
			'fullscreen' => $this->fullscreen(),
			// The Play page gets a browser "Full screen" button (views/elasticpro.embed.php).
			'play' => $page === 'play',
		]);
		$response->setTitle('ElasticPro — '.($this->trouble() ? 'Troubleshoot' : $labels[$page]));
		$this->setResponse($response);
	}
}
