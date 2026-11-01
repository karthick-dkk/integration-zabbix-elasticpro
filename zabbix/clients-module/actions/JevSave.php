<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use Exception;
use Modules\EpClients\Lib\Jev;

/**
 * Saves "Judge Discover proposals with TypeSafe Jev" from ElasticPro -> Settings: on or off, and the
 * API key (Jev). Its own form, so saving the other settings never posts an empty key over it, and
 * the key field counts only with jev_key_action=set — the same keep-marker as the default
 * credential's password; jev_key_action=clear forgets the key.
 *
 * A Zabbix Super admin's: turning it on sends host, host group and template names to TypeSafe, a
 * decision for whoever owns the Zabbix. The key is never echoed, logged or put in a message.
 */
class JevSave extends Base {

	protected function checkInput(): bool {
		return $this->validateInput(['jev_enabled' => 'in 1', 'jev_key' => 'string', 'jev_key_action' => 'in keep,set,clear', 'save_jev' => 'string']);
	}

	protected function doAction(): void {
		try {
			if ($this->getUserType() != USER_TYPE_SUPER_ADMIN) {
				throw new Exception(_('Nothing was changed: whether Discover sends names to TypeSafe is a Zabbix Super admin\'s decision.'));
			}
			$done = Jev::save($this->getInput('jev_enabled', '') === '1', (string) $this->getInput('jev_key_action', 'keep'),
				(string) $this->getInput('jev_key', ''), $this->user());
			CMessageHelper::setSuccessTitle($done ? _('TypeSafe Jev setting saved') : _('TypeSafe Jev: nothing to change'));
			foreach ($done as $line) {
				CMessageHelper::addSuccess($line);
			}
		}
		catch (Exception $e) {
			CMessageHelper::setErrorTitle(_('TypeSafe Jev setting not changed'));
			CMessageHelper::addError($e->getMessage());
		}
		$this->setResponse(new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'ep.clients.settings')));
	}
}
