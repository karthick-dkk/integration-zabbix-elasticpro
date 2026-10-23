<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use Exception;
use Modules\EpClients\Lib\{History, Registry, Store, WriteCheck};

/**
 * Remove a client from ElasticPro only: its master host leaves the master template and loses the
 * macros this module wrote on it (Reconciler::release), and the module's own records of it go.
 * No host is disabled, deleted or moved and monitoring carries on; for whoever may edit, like Delete
 * for good, and only with the client's name typed to confirm.
 *
 * Deliberately not noteChange(): that re-reads the client and syncs its alert routing, which
 * would delete the client's trigger action, DL user group, scheduled report and dashboard — all
 * Zabbix objects this action promises to leave where they are. The audit entry and the list's
 * "last change" are written here instead, and the backup taken first is the way back.
 */
class ClientRelease extends Base {

	protected function checkInput(): bool {
		return $this->validateInput(['client' => 'required|string', 'confirm' => 'string']);
	}

	protected function doAction(): void {
		$client = trim((string) $this->getInput('client'));
		$rec = $this->rec();
		try {
			if (trim((string) $this->getInput('confirm', '')) !== $client) {
				throw new Exception(_s('Type the client\'s name, %1$s, to remove it from ElasticPro.', $client));
			}
			// Only the master host is written (unlinked, macros removed); no alert routing is synced.
			$now = $rec->current($client);
			$this->refuseUnlessAllowed(WriteCheck::forHosts($client, $now['master'] !== null
				? [(string) $now['master']['hostid'] => (string) $now['master']['name']] : [], null, [], null));
			$backup = $this->backup('Remove '.$client.' from ElasticPro');
			$rec->release($client);
			// The module's own records of the client. History is kept: it is the audit trail.
			Registry::forget($client);
			$log = Store::read('changes.json', []);
			unset($log[$client]);
			Store::write('changes.json', $log);
			History::add($client, 'removed from ElasticPro only', $this->user(), $backup, 'hosts untouched in Zabbix');
			$this->toList(_s('%1$s removed from ElasticPro — its hosts are untouched in Zabbix', $client), $rec->done());
		}
		catch (Exception $e) {
			$this->toList(_s('Client "%1$s" was not removed from ElasticPro', $client), $rec->done(), true, $e->getMessage());
		}
	}
}
