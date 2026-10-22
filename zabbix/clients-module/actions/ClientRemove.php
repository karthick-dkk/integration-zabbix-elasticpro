<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use Exception;
use Modules\EpClients\Lib\{Reconciler, WriteCheck};

/**
 * Delete a decommissioned client for good: the hosts this page made for it, with their history,
 * after a backup. Only a decommissioned client, and only when its name is typed to confirm. Hosts
 * made by hand and the host group stay.
 */
class ClientRemove extends Base {

	protected function checkInput(): bool {
		return $this->validateInput(['client' => 'required|string', 'confirm' => 'string']);
	}

	protected function doAction(): void {
		$client = trim((string) $this->getInput('client'));
		$rec = $this->rec();
		try {
			if ($this->lifecycle()->status($client) !== 'decommissioned') {
				throw new Exception(_('Only a decommissioned client can be deleted for good: disable it, then decommission it.'));
			}
			if (trim((string) $this->getInput('confirm', '')) !== $client) {
				throw new Exception(_s('Type the client\'s name, %1$s, to delete it for good.', $client));
			}
			// The hosts remove() deletes. noteChange() syncs no alert routing for a client that is gone.
			$now = $rec->current($client);
			$deleted = array_filter(WriteCheck::hostsOf($now), fn($id) => self::managed($now, (string) $id), ARRAY_FILTER_USE_KEY);
			$this->refuseUnlessAllowed(WriteCheck::forHosts($client, $deleted, null, [], null));
			$this->backup('Delete '.$client);
			$rec->remove($client);
			$this->noteChange($client, 'deleted for good');
			$this->toList(_s('Client "%1$s" deleted for good', $client), $rec->done());
		}
		catch (Exception $e) {
			$this->toList(_s('Client "%1$s" was not deleted', $client), $rec->done(), true, $e->getMessage());
		}
	}

	/** Whether remove() deletes this host: one this page made (Reconciler::isManaged). */
	private static function managed(array $now, string $hostid): bool {
		foreach (array_merge(array_filter([$now['master'], $now['cluster'], $now['ulm']]), array_values($now['machines'])) as $h) {
			if ((string) $h['hostid'] === $hostid) {
				return Reconciler::isManaged($h);
			}
		}
		return false;
	}
}
