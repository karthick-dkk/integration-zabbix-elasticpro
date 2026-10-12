<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use API;
use CControllerResponseData;
use Modules\EpClients\Lib\{Bars, Roles, Settings, Store};

/**
 * ElasticPro -> Settings: the site's own numbers, read by this page and all three report widgets.
 *
 * Open to Zabbix Admins read-only, like the client list, because the thresholds explain what the
 * colours on the reports mean and reading them changes nothing.
 */
class SettingsEdit extends Base {

	protected const READ_ONLY_OK = true;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function doAction(): void {
		$settings = Settings::all();
		$response = new CControllerResponseData([
			'settings' => $settings,
			'defaults' => Settings::DEFAULTS,
			'bars' => Bars::options(),
			'units' => array_keys(Settings::SIZE_UNITS),
			'template' => $this->templateUsage(),
			'template_name' => Roles::templateName('master'),
			'can_write' => $this->canWrite(),
			'store_ok' => Store::writable(),
			'store_dir' => Store::dir()
		]);
		$response->setTitle(_('ElasticPro settings'));
		$this->setResponse($response);
	}

	/**
	 * The thresholds the installed master template is carrying, so the page can say when the
	 * alerts have fallen behind the setting.
	 *
	 * Read from Zabbix rather than remembered here on purpose: the template is what the triggers
	 * actually compare against, and a note driven by a record of our own would be wrong in both
	 * directions — silent after the template was changed by hand, and nagging after a rewrite
	 * this module did not make. null when the template is not installed yet, in which case there
	 * is nothing to have fallen behind.
	 */
	private function templateUsage(): ?array {
		$found = API::Template()->get([
			'output' => ['templateid'],
			'filter' => ['host' => Roles::templateNamesFor('master')],
			'selectMacros' => ['macro', 'value']
		]) ?: [];
		if (!$found) {
			return null;
		}
		$macros = array_column($found[0]['macros'] ?? [], 'value', 'macro');
		$of = fn(string $macro) => is_numeric($macros[$macro] ?? null) ? (float) $macros[$macro] : null;
		return ['warn' => $of(Settings::WARN_MACRO), 'high' => $of(Settings::HIGH_MACRO)];
	}
}
