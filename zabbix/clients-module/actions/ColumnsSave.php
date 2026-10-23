<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use Exception;
use Modules\EpClients\Lib\ListColumns;

/**
 * The list's Columns popover: order, switches and names, as one shared setting for the page
 * (ListColumns). For whoever may edit; Zabbix's CSRF check stays on. Nothing in Zabbix changes.
 */
class ColumnsSave extends Base {

	protected function checkInput(): bool {
		return $this->validateInput(['columns' => 'string', 'save' => 'string', 'reset' => 'string']);
	}

	protected function doAction(): void {
		try {
			if ($this->hasInput('reset')) {
				ListColumns::reset();
				CMessageHelper::setSuccessTitle(_('Columns are back to the ones ElasticPro ships'));
			}
			else {
				$posted = json_decode((string) $this->getInput('columns', '[]'), true);
				ListColumns::save($this->roles(), is_array($posted) ? $posted : []);
				CMessageHelper::setSuccessTitle(_('Columns saved'));
			}
		}
		catch (Exception $e) {
			CMessageHelper::setErrorTitle(_('Columns not saved'));
			CMessageHelper::addError($e->getMessage());
		}
		$this->setResponse(new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'ep.clients.list')));
	}
}
