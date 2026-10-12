<?php declare(strict_types = 0);

namespace Modules\EpResources\Actions;

require_once __DIR__.'/../lib/Store.php';
require_once __DIR__.'/../lib/ColumnSettings.php';

use CController;
use CControllerResponseData;
use Modules\EpResources\Lib\ColumnSettings;

/**
 * Save or reset a report's column settings (the Columns dialog). Super admins only; one setting
 * for everyone. Answers JSON: {ok: true} or {ok: false, error}.
 *
 * Shared: ../../sync-assets.mjs copies this into each widget module, under its namespace.
 */
class ColumnsSave extends CController {

	/**
	 * Which report this copy of the action may write, worked out from the action it was reached
	 * by — widget.ep_capacity.columns is the capacity widget's and nothing else's.
	 *
	 * It used to accept any of the three from the request, which meant the volume widget's
	 * endpoint could rewrite the capacity report's settings: three ways in to each file instead
	 * of one, and a Super admin could do it by accident with a stale dialog open. Deriving it
	 * from the action leaves each widget able to write only its own.
	 */
	private const OWN = ['ep_capacity' => 'capacity', 'ep_resources' => 'resources', 'ep_volume' => 'volume'];

	private function own(): string {
		return self::OWN[explode('.', $this->getAction())[1] ?? ''] ?? '';
	}

	protected function checkInput(): bool {
		return $this->validateInput(['report' => 'required|in '.$this->own(), 'settings' => 'string', 'reset' => 'in 1']);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction(): void {
		try {
			$report = (string) $this->getInput('report');
			if ($this->hasInput('reset')) {
				ColumnSettings::reset($report);
			}
			else {
				$in = json_decode((string) $this->getInput('settings', '{}'), true);
				ColumnSettings::save($report, is_array($in) ? $in : [], null);
			}
			$out = ['ok' => true];
		}
		catch (\Throwable $e) {
			$out = ['ok' => false, 'error' => $e->getMessage()];
		}
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($out)]));
	}
}
