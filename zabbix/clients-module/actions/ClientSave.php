<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use Exception;
use Modules\EpClients\Lib\{ClusterSecret, WriteCheck};

/**
 * Add or edit a client. A form with mistakes comes back with them and nothing saved; a backup is
 * taken before anything changes; after saving, the client is read back and compared. A save that
 * would delete hosts comes back listing them, and goes ahead only once that is confirmed. A password
 * typed for a Zabbix secret goes to Zabbix only — never into the form, a backup or a log. Someone
 * who is not a Zabbix Super admin is refused before anything changes when their Zabbix rights do
 * not cover the save (Base::writeRefusals).
 */
class ClientSave extends Base {

	/** An operator's page: add, edit, disable, enable, maintenance (EpRole). */
	protected const NEEDS = \Modules\EpClients\Lib\EpRole::NEED_OPERATOR;

	protected function checkInput(): bool {
		$fields = ['name' => 'required|string', 'mode' => 'required|in add,edit', 'es_password' => 'string', 'es_password_action' => 'string',
			'merge' => 'array', 'confirm_delete' => 'string'];
		foreach (array_keys($this->spec()->defaults()) as $field) {
			$fields[$field] = $fields[$field] ?? 'string';
		}
		return $this->validateInput($fields);
	}

	protected function doAction(): void {
		$spec = $this->spec();
		$input = [];
		foreach (array_keys($spec->defaults()) as $field) {
			$input[$field] = (string) $this->getInput($field, '');
		}
		$mode = $this->getInput('mode');
		// The keep-marker: a password counts only when the form says it showed the field for one.
		$secret = ['es_password' => ClusterSecret::fromPost((string) $this->getInput('es_password_action', ''), (string) $this->getInput('es_password', '')),
			'merge' => implode(',', array_map('strval', (array) $this->getInput('merge', [])))];
		['client' => $client, 'errors' => $errors] = $spec->fromForm($input + $secret);
		$rec = $this->rec();
		$now = null;
		if (!$errors) {
			$now = $rec->current($client['name']);
			if ($mode === 'add' && $now['master'] !== null) {
				$errors[] = _s('Client "%1$s" exists already — edit it from the list.', $client['name']);
			}
			else {
				// Before anything else is said about the form: for anyone but a Super admin, whether
				// their Zabbix rights cover the save at all. A template they cannot see would
				// otherwise read as "import it first".
				$errors = $this->writeRefusals(WriteCheck::forSave($rec, $spec, $client, $now));
				if (!$errors) {
					$errors = $rec->problems($client, $now);
				}
			}
		}
		$deletes = [];
		if (!$errors && $now !== null) {
			$deletes = $rec->plannedDeletes($client, $now);
			// Confirmed for exactly these hosts: a list that changed since asks again.
			$sig = sha1(implode(',', array_keys($deletes)));
			if ($deletes && (string) $this->getInput('confirm_delete', '') !== $sig) {
				$current = $this->state()->formFor($client['name']);
				$input['_now'] = $current['_now'] ?? null;
				$input['_shared'] = $current['_shared'] ?? [];
				$input['_unassigned'] = $current['_unassigned'] ?? [];
				$input['_deletes'] = ['hosts' => array_values($deletes), 'sig' => $sig];
				$input['_current_servers'] = $current['servers'] ?? '[]';
				$input['merge'] = $secret['merge'];
				$this->setResponse(ClientEdit::page($this, $input, $mode, [], false, []));
				return;
			}
		}
		if (!$errors) {
			try {
				$this->backup(($mode === 'add' ? 'Add ' : 'Edit ').$client['name']);
				$rec->apply($client);
				$this->noteChange($client['name'], $mode === 'add' ? 'added' : 'edited');
				$problems = $this->importer()->verify($client);
				$this->toList($mode === 'add' ? _s('Client "%1$s" added', $client['name']) : _s('Client "%1$s" saved', $client['name']),
					array_merge($rec->done(), $this->handOver($client),
						$problems ? [_('Read back from Zabbix, these differ: ').implode(' ', $problems)] : [_('Read back from Zabbix: everything matches.')]));
				return;
			}
			catch (Exception $e) {
				$errors[] = $e->getMessage();
			}
		}
		$current = $input['name'] !== '' ? $this->state()->formFor($input['name']) : [];
		$input['_now'] = $current['_now'] ?? null;
		$input['_shared'] = $current['_shared'] ?? [];
		$input['_unassigned'] = $current['_unassigned'] ?? [];
		$input['_current_servers'] = $current['servers'] ?? '[]';
		$this->setResponse(ClientEdit::page($this, $input, $mode, $errors, false, $rec->done()));
	}

	private function handOver(array $client): array {
		return ClusterSecret::handOver($this->rec(), $client, $this->user());
	}
}
