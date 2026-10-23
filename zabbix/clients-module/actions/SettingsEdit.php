<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use API;
use CControllerResponseData;
use Modules\EpClients\Lib\{Bars, ClusterSecret, DefaultCredential, Roles, Settings, Store};

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
			'palette_labels' => self::paletteLabels(),
			'can_write' => $this->canWrite(),
			'write_note' => $this->writeNote(),
			'credential' => ['current' => DefaultCredential::current(), 'usage' => DefaultCredential::usage(),
				'record' => ClusterSecret::of(DefaultCredential::KEY), 'can_set' => $this->getUserType() == USER_TYPE_SUPER_ADMIN],
			'store_ok' => Store::writable(),
			'store_dir' => Store::dir()
		]);
		$response->setTitle(_('ElasticPro settings'));
		$this->setResponse($response);
	}

	/** What each palette colour is called and used for, in the order the Colours card lists them. */
	public static function paletteLabels(): array {
		return [
			'ok' => [_('OK'), _('bars and values under Warn')],
			'warn' => [_('Warn'), _('over the Warn threshold')],
			'high' => [_('High'), _('over the High threshold')],
			'unknown' => [_('Unknown'), _('not collected, no data, allocated not set')],
			'short' => [_('Short'), _('allocated below requested (hatched)')],
			'free' => [_('Free'), _('allocated but unused; bar track')],
			'down' => [_('Down'), _('servers down')],
			'stale' => [_('Stale'), _('value older than its stale time')],
			'link' => [_('Link'), _('client names and click-throughs')]
		];
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
