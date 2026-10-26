<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use CMessageHelper;
use Exception;
use Modules\EpClients\Lib\{Lifecycle, WriteCheck};

/**
 * Change clients' status — one, or several ticked on the list: disable, enable, decommission,
 * restore, start or end maintenance. One backup first for all of them; each client is changed on
 * its own, so one refusal does not stop the others.
 */
class ClientStatus extends Base {

	/** An operator's page: add, edit, disable, enable, maintenance (EpRole). */
	protected const NEEDS = \Modules\EpClients\Lib\EpRole::NEED_OPERATOR;

	private const OPS = ['disable', 'enable', 'decommission', 'restore', 'maintenance', 'end_maintenance'];

	protected function checkInput(): bool {
		return $this->validateInput([
			'op' => 'required|in '.implode(',', self::OPS),
			'client' => 'string', 'clients' => 'array',
			'start' => 'string', 'hours' => 'string', 'collect' => 'in 0,1', 'reason' => 'string'
		]);
	}

	protected function doAction(): void {
		$op = $this->getInput('op');
		$clients = array_values(array_filter(array_map('strval', array_merge(
			$this->hasInput('client') ? [$this->getInput('client')] : [],
			array_keys(array_filter((array) $this->getInput('clients', []), fn($v) => (string) $v === '1'))))));
		if (!$clients) {
			$this->toList(_('Nothing changed'), [], true, _('Tick at least one client.'));
			return;
		}
		$words = ['disable' => _('Disable'), 'enable' => _('Enable'), 'decommission' => _('Decommission'), 'restore' => _('Restore'),
			'maintenance' => _('Maintenance'), 'end_maintenance' => _('End maintenance')];
		try {
			// Decommissioning and restoring are an admin's (EpRole): an operator disables, enables
			// and sets maintenance.
			if (in_array($op, ['decommission', 'restore'], true) && !$this->canRemove()) {
				throw new Exception(_('Decommissioning or restoring a client needs an ElasticPro admin role (or Zabbix Super admin).'));
			}
			// Every client asked about first, so a refusal changes none of them.
			$needs = WriteCheck::none();
			if ($this->getUserType() != USER_TYPE_SUPER_ADMIN) {
				foreach ($clients as $client) {
					$needs = WriteCheck::merge($needs, $this->needsForStatus($client, $op));
				}
			}
			$this->refuseUnlessAllowed($needs);
			$this->backup($words[$op].' '.implode(', ', $clients));
		}
		catch (Exception $e) {
			$this->toList(_('Nothing changed'), [], true, $e->getMessage());
			return;
		}
		$lines = [];
		$failed = false;
		foreach ($clients as $client) {
			$life = $this->lifecycle();
			try {
				switch ($op) {
					case 'disable': $life->disable($client); break;
					case 'enable': $life->enable($client); break;
					case 'decommission': $life->decommission($client); break;
					case 'restore': $life->restore($client); break;
					case 'end_maintenance': $life->endMaintenance($client); break;
					case 'maintenance':
						$start = trim((string) $this->getInput('start', ''));
						$since = $start === '' || $start === 'now' ? time() : strtotime($start);
						if ($since === false) {
							throw new Exception(_s('"%1$s" is not a start time.', $start));
						}
						$hours = (float) $this->getInput('hours', '4');
						$life->startMaintenance($client, $since, (int) round($hours * 3600), $this->getInput('collect', '1') === '1',
							trim((string) $this->getInput('reason', '')));
						break;
				}
				$how = ['disable' => 'disabled', 'enable' => 'enabled', 'decommission' => 'decommissioned', 'restore' => 'restored to disabled',
					'maintenance' => 'maintenance', 'end_maintenance' => 'maintenance ended'][$op];
				$this->noteChange($client, $how);
				$lines[] = $client.': '.implode(' ', $life->done());
			}
			catch (Exception $e) {
				$failed = true;
				$lines[] = $client.': '.$e->getMessage();
			}
		}
		// Zabbix queues its own "Updated status of host …" for each host; the lines above say it once per client.
		$errors = array_filter(CMessageHelper::getMessages(), fn($m) => ($m['type'] ?? '') === 'error');
		CMessageHelper::clear();
		array_map([CMessageHelper::class, 'addMessage'], array_values($errors));
		$this->toList($failed ? _s('%1$s: done with problems', $words[$op]) : _s('%1$s: done', $words[$op]), $lines, $failed);
	}

	/** The status each change leaves behind; maintenance leaves it as it is. */
	private const AFTER = ['disable' => 'disabled', 'enable' => 'active', 'decommission' => 'decommissioned', 'restore' => 'disabled'];

	/**
	 * What one status change needs of the user's Zabbix rights: write on the hosts it changes, on
	 * the client's host group for a maintenance, and the alert routing noteChange() syncs after it
	 * on the master host's status as the change leaves it.
	 */
	private function needsForStatus(string $client, string $op): array {
		$rec = $this->rec();
		$now = $rec->current($client);
		if ($now['master'] === null) {
			return WriteCheck::none();   // Lifecycle refuses it on its own, changing nothing
		}
		$before = $rec->macros((string) $now['master']['hostid']);
		$after = isset(self::AFTER[$op]) ? array_merge($before, [Lifecycle::MACRO => self::AFTER[$op]]) : $before;
		$needs = WriteCheck::forHosts($client, (new Lifecycle($rec))->touched($now), $now['groupid'], $before, $after);
		if (in_array($op, ['maintenance', 'end_maintenance'], true) && $now['groupid'] !== null) {
			$needs['groups'] = [$client => true];
		}
		return $needs;
	}
}
