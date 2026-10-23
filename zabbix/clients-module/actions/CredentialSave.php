<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use Exception;
use Modules\EpClients\Lib\{ClusterSecret, DefaultCredential};

/**
 * Saves the default Elasticsearch credential from ElasticPro -> Settings: the global macros
 * {$ELASTICSEARCH.USERNAME} and {$ELASTICSEARCH.PASSWORD} (DefaultCredential), and the password to
 * the ElasticPro core as cluster "*". Its own form and action, so saving the other settings never
 * posts an empty password over it. The keep-marker (es_password_action=set) decides whether the
 * password field counts at all.
 *
 * Global macros are a Zabbix Super admin's to write: anyone else is told so and nothing changes.
 * A backup is not taken — no client changes; Zabbix keeps the macro's history in its audit log.
 */
class CredentialSave extends Base {

	protected function checkInput(): bool {
		return $this->validateInput(['es_user' => 'string', 'es_password' => 'string', 'es_password_action' => 'string', 'save_credential' => 'string']);
	}

	protected function doAction(): void {
		try {
			if ($this->getUserType() != USER_TYPE_SUPER_ADMIN) {
				throw new Exception(_('Nothing was changed: the default credential is kept in global macros, which only a Zabbix Super admin may write.'));
			}
			$done = DefaultCredential::save((string) $this->getInput('es_user', ''),
				ClusterSecret::fromPost((string) $this->getInput('es_password_action', ''), (string) $this->getInput('es_password', '')), $this->user());
			CMessageHelper::setSuccessTitle($done ? _('Default Elasticsearch credential saved') : _('Default Elasticsearch credential: nothing to change'));
			foreach ($done as $line) {
				CMessageHelper::addSuccess($line);
			}
		}
		catch (Exception $e) {
			CMessageHelper::setErrorTitle(_('Default Elasticsearch credential not changed'));
			CMessageHelper::addError($e->getMessage());
		}
		$this->setResponse(new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'ep.clients.settings')));
	}
}
