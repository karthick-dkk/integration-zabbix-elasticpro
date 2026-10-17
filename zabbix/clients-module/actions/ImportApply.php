<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use Exception;
use Modules\EpClients\Lib\Store;

/** Apply the ticked changes of an import, after a backup of how things are now. */
class ImportApply extends Base {

	protected function checkInput(): bool {
		return $this->validateInput(['token' => 'required|string', 'apply' => 'array']);
	}

	protected function doAction(): void {
		$pending = Store::read('pending-import.json');
		if (!$pending || !hash_equals((string) $pending['token'], (string) $this->getInput('token'))) {
			$this->toList(_('Nothing to apply'), [], true, _('This import is no longer pending — it was applied, cancelled, or another import replaced it.'));
			return;
		}
		Store::delete('pending-import.json');
		$chosen = array_filter((array) $this->getInput('apply', []), fn($v) => (string) $v === '1');
		$lines = [];
		if (array_intersect_key($chosen, array_flip(array_column($pending['rows'], 'name')))) {
			try {
				$this->backup('Import '.($pending['file'] ?? '').' — confirmed changes');
			}
			catch (Exception $e) {
				$this->toList(_('Nothing applied'), [], true, $e->getMessage());
				return;
			}
		}
		$chosenRows = [];
		foreach ($pending['rows'] as $row) {
			if (!isset($chosen[$row['name']])) {
				$lines[] = _s('%1$s left as it was.', $row['name']);
				continue;
			}
			$chosenRows[] = $row;
		}
		$importer = $this->importer();
		$result = $importer->applyRows($chosenRows, fn() => $this->rec(), fn($name) => $this->noteChange($name, 'imported'));
		foreach ($result['applied'] as $a) {
			$lines[] = _s('%1$s: %2$s', $a['name'], implode(' ', $a['done']) ?: _('saved.'));
			$lines[] = $a['problems'] ? _s('%1$s read back — differs: %2$s', $a['name'], implode(' ', $a['problems']))
				: _s('%1$s read back — matches the file.', $a['name']);
		}
		// A client Zabbix refused is said to be not changed — with what was written before the refusal.
		$errors = [];
		foreach ($result['failed'] as $f) {
			$errors[] = _s('%1$s was not changed: %2$s', $f['name'], $f['error'])
				.($f['done'] ? ' '._s('Written before it stopped: %1$s', implode(' ', $f['done'])) : '');
		}
		$title = !$result['failed'] ? _('Import applied')
			: ($result['applied'] ? _s('Import applied to %1$s of %2$s clients', count($result['applied']), count($chosenRows)) : _('Nothing applied'));
		$this->toList($title, $lines, (bool) $result['failed'], $errors ? implode(" ", $errors) : null);
	}
}
