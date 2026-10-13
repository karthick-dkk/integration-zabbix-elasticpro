<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use Exception;
use Modules\EpClients\Lib\Settings;

/**
 * Saves ElasticPro -> Settings. Super admins only, and Zabbix's own CSRF check is left in place:
 * the page posts a plain form with its token, so there is nothing here to disable.
 *
 * Every field arrives as a string and is handed to Settings::clean() rather than being validated
 * here. That is the whole point of cleaning instead of refusing: a number out of range comes to
 * the nearest end and the operator sees what was stored, where a rejected form would lose the
 * other fourteen fields over one mistyped digit.
 */
class SettingsSave extends Base {

	protected function checkInput(): bool {
		return $this->validateInput([
			'warn' => 'string', 'high' => 'string', 'bars' => 'string',
			'decimals_bytes' => 'string', 'decimals_percent' => 'string', 'decimals_plain' => 'string',
			'size_unit' => 'string',
			'bad_days' => 'string', 'warn_days' => 'string', 'flat_days' => 'string',
			'day_text_days' => 'string', 'month_text_days' => 'string', 'month_days' => 'string',
			'types' => 'string', 'type_default' => 'string',
			// Which button was pressed. A submit carries its own caption as its value, so this
			// is a string and only its presence is read.
			'save' => 'string', 'reset' => 'string'
		]);
	}

	protected function doAction(): void {
		$in = fn(string $k) => trim((string) $this->getInput($k, ''));
		try {
			if ($this->hasInput('reset')) {
				if (Settings::reset()) {
					CMessageHelper::setSuccessTitle(_('Settings are back to the ones ElasticPro ships'));
				}
				else {
					CMessageHelper::setErrorTitle(_('Settings not changed'));
					CMessageHelper::addError(_s('%1$s could not be deleted. Check the data folder\'s permissions.',
						Settings::FILE));
				}
			}
			else {
				Settings::save([
					'usage' => ['warn' => $in('warn'), 'high' => $in('high')],
					'display' => ['bars' => $in('bars'), 'decimals_bytes' => $in('decimals_bytes'),
						'decimals_percent' => $in('decimals_percent'), 'decimals_plain' => $in('decimals_plain')],
					'export' => ['size_unit' => $in('size_unit')],
					// Shown on the page but not editable there — Zabbix keeps a placed widget's
					// refresh in each user's own profile, so whatever is stored rides along
					// rather than being reset by every save.
					'refresh' => Settings::all()['refresh'],
					'forecast' => ['bad_days' => $in('bad_days'), 'warn_days' => $in('warn_days'), 'flat_days' => $in('flat_days'),
						'day_text_days' => $in('day_text_days'), 'month_text_days' => $in('month_text_days'), 'month_days' => $in('month_days')],
					'types' => ['all' => preg_split('/\R/', $in('types')) ?: [], 'default' => $in('type_default')]
				]);
				CMessageHelper::setSuccessTitle(_('Settings saved'));
			}
		}
		catch (Exception $e) {
			CMessageHelper::setErrorTitle(_('Settings not changed'));
			CMessageHelper::addError($e->getMessage());
		}
		$this->setResponse(new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'ep.clients.settings')));
	}
}
