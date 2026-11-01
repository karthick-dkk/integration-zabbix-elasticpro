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
 * `elasticpro.play` opens Play, ElasticPro's NOC wall display (`#/play`). With `kiosk=1`
 * (the Play widget's "Open full screen") it opens in Zabbix's kiosk mode, for this page
 * only: no sidebar, no header, and Zabbix's own "Normal view" button to leave it. The
 * person's saved layout mode is not changed.
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
	 * page takes `kiosk`.
	 */
	protected function checkInput(): bool {
		return $this->validateInput([
			'zbx_host' => 'string',
			'client' => 'string',
			'rule' => 'string',
			'problem' => 'string',
			'kiosk' => 'in 0,1',
		]);
	}

	/** Play asked for full screen (kiosk mode, this page only). */
	private function kiosk(): bool {
		return $this->page() === 'play' && (string) $this->getInput('kiosk', '0') === '1';
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
		// Guests have no ElasticPro role at all.
		if ($this->getUserType() < USER_TYPE_ZABBIX_USER) {
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
			'error' => $sign['error'],
			'kiosk' => $this->kiosk(),
		]);
		$response->setTitle('ElasticPro — '.($this->trouble() ? 'Troubleshoot' : $labels[$page]));
		$this->setResponse($response);
	}
}
