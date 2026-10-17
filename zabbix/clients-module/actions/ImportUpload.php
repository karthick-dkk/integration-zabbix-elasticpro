<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use CControllerResponseData;
use Exception;
use Modules\EpClients\Lib\{Csv, Store, Workbook};

/**
 * Import clients.xlsx — or, as before, clients.csv, servers.csv, or both. Every row is checked
 * first; one error and nothing is applied. With a clean file: a backup, then new clients added
 * straight away (unless something overlaps), then the changes to existing clients shown for a
 * tick. Each client added is read back and compared.
 *
 * The workbook is read by Workbook::parse into exactly what Csv::parse / Csv::parseServers give,
 * so from there on — Importer, the backup, the apply — a workbook and the CSV files are one path.
 */
class ImportUpload extends Base {

	/** The largest file taken: a client list is a few hundred KB at most. */
	public const MAX_UPLOAD = 5 * 1024 * 1024;

	protected function init(): void {
		// The upload is a multipart POST; the token travels in the form and is checked here.
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput([CSRF_TOKEN_NAME => 'string']);
	}

	protected function doAction(): void {
		$data = ['stage' => 'upload', 'file' => '', 'plan' => null, 'added' => [], 'backup' => null, 'pending' => [], 'error' => null,
			'store_ok' => Store::writable(), 'store_dir' => Store::dir(), 'sheets' => [], 'skipped' => [], 'client_count' => null];

		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
			if (!\CCsrfTokenHelper::check((string) $this->getInput(CSRF_TOKEN_NAME, ''), 'ep.clients.import')) {
				$data['error'] = _('The form expired. Choose the file again.');
			}
			else {
				$data = $this->receive($data);
			}
		}
		try {
			$data['client_count'] = count($this->state()->clients());
		}
		catch (Exception $e) {
			$data['client_count'] = null;
		}
		$response = new CControllerResponseData($data);
		$response->setTitle(_('Import clients'));
		$this->setResponse($response);
	}

	/** The uploaded files: file[] (the page's one input, several allowed), and csv / servers as before. */
	private static function uploads(): array {
		$out = [];
		$f = $_FILES['file'] ?? null;
		if ($f !== null) {
			foreach ((array) $f['name'] as $i => $name) {
				$out[] = ['name' => (string) $name, 'tmp' => (string) ((array) $f['tmp_name'])[$i], 'error' => (int) ((array) $f['error'])[$i],
					'size' => (int) ((array) $f['size'])[$i], 'as' => null];
			}
		}
		foreach (['csv' => 'clients', 'servers' => 'servers'] as $k => $as) {
			if (isset($_FILES[$k]) && !is_array($_FILES[$k]['name'])) {
				$out[] = ['name' => (string) $_FILES[$k]['name'], 'tmp' => (string) $_FILES[$k]['tmp_name'], 'error' => (int) $_FILES[$k]['error'],
					'size' => (int) $_FILES[$k]['size'], 'as' => $as];
			}
		}
		return array_values(array_filter($out, fn($u) => $u['error'] !== UPLOAD_ERR_NO_FILE && $u['name'] !== ''));
	}

	private function receive(array $data): array {
		$got = [];
		foreach (self::uploads() as $u) {
			$name = basename($u['name']);
			if ($u['error'] === UPLOAD_ERR_INI_SIZE || $u['error'] === UPLOAD_ERR_FORM_SIZE || $u['size'] > self::MAX_UPLOAD) {
				$data['error'] = _s('%1$s is larger than %2$s MB; a client list is far smaller. Is it the right file?', $name, self::MAX_UPLOAD / 1024 / 1024);
				return $data;
			}
			if ($u['error'] !== UPLOAD_ERR_OK || $u['size'] === 0 || !is_uploaded_file($u['tmp'])) {
				continue;
			}
			$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			if (in_array($ext, ['xls', 'ods', 'numbers'], true)) {
				$data['error'] = _s('%1$s is not an .xlsx workbook. In Excel (or LibreOffice) use Save As → Excel Workbook (.xlsx), and choose that file.', $name);
				return $data;
			}
			$bytes = (string) file_get_contents($u['tmp']);
			$xlsx = in_array($ext, ['xlsx', 'xlsm'], true) || strncmp($bytes, "PK\x03\x04", 4) === 0;
			$got[] = ['name' => $name, 'bytes' => $bytes, 'kind' => $xlsx ? 'xlsx' : ($u['as'] ?? self::csvKind($name, $bytes))];
		}
		if (!$got) {
			$data['error'] = _('No file was received. Choose clients.xlsx (or the old clients.csv / servers.csv) and press Check file.');
			return $data;
		}
		$kinds = array_count_values(array_column($got, 'kind'));
		if (isset($kinds['xlsx']) && count($got) > 1) {
			$data['error'] = _('Choose one workbook, or the CSV files — not both, and not two workbooks.');
			return $data;
		}
		if (($kinds['clients'] ?? 0) > 1 || ($kinds['servers'] ?? 0) > 1) {
			$data['error'] = _('Two clients.csv or two servers.csv files were chosen. Choose one of each at most.');
			return $data;
		}
		$data['file'] = implode(' + ', array_column($got, 'name'));
		$roles = $this->roles();
		if (isset($kinds['xlsx'])) {
			$wb = Workbook::parse($got[0]['bytes'], $roles, $this->workbookLists());
			$data['skipped'] = $wb['skipped'];
			return $this->process($wb['clients'], $wb['servers'], $data, $wb['errors'], $wb['counts'], true);
		}
		$byKind = array_column($got, 'bytes', 'kind');
		$clients = isset($byKind['clients']) ? Csv::parse($byKind['clients'], $roles) : null;
		$servers = isset($byKind['servers']) ? Csv::parseServers($byKind['servers'], $roles) : null;
		$counts = [];
		if ($clients !== null) {
			$counts['clients.csv'] = count($clients['rows']);
		}
		if ($servers !== null) {
			$counts['servers.csv'] = array_sum(array_map('count', $servers['clients']));
		}
		return $this->process($clients, $servers, $data, [], $counts, false);
	}

	/** A CSV is servers.csv when it says so in its name, or when its header has ip and roles and no type. */
	private static function csvKind(string $name, string $bytes): string {
		if (stripos($name, 'server') !== false) {
			return 'servers';
		}
		if (stripos($name, 'client') !== false) {
			return 'clients';
		}
		$first = strtolower((string) strtok(preg_replace('/^\xEF\xBB\xBF/', '', $bytes), "\r\n"));
		$cells = array_map('trim', preg_split('/[;,]/', $first));
		return in_array('ip', $cells, true) && in_array('roles', $cells, true) && !in_array('type', $cells, true) ? 'servers' : 'clients';
	}

	/**
	 * Per sheet (or file): rows, new, changed and errors — the review's table. Servers count as new
	 * when added, changed when their roles, details or presence change.
	 */
	private static function sheets(array $plan, array $counts, bool $xlsx, ?array $clients, ?array $servers): array {
		[$cName, $sName] = $xlsx ? [Workbook::CLIENTS, Workbook::SERVERS] : ['clients.csv', 'servers.csv'];
		$serverWords = [$sName.', row', ($servers['file'] ?? 'servers.csv').':', $servers['file'] ?? 'servers.csv', 'An IP belongs to one client only', ' is listed for both '];
		$out = [];
		foreach ([$cName, $sName] as $name) {
			if (array_key_exists($name, $counts)) {
				$out[$name] = ['name' => $name, 'rows' => $counts[$name], 'new' => 0, 'changed' => 0, 'errors' => 0];
			}
		}
		$inClients = array_flip(array_map(fn($r) => strtolower(trim((string) ($r['client'] ?? ''))), $clients['rows'] ?? []));
		foreach ($plan['rows'] as $r) {
			$fromClients = isset($inClients[strtolower($r['name'])]);
			if (isset($out[$cName]) && $fromClients) {
				$out[$cName]['new'] += $r['status'] === 'new' ? 1 : 0;
				$out[$cName]['changed'] += $r['status'] === 'update' && !empty($r['diff']['fields']) ? 1 : 0;
			}
			if (isset($out[$sName])) {
				if ($r['status'] === 'new') {
					$out[$sName]['new'] += count((array) json_decode((string) ($r['form']['servers'] ?? '[]'), true));
				}
				foreach ($r['diff']['hosts'] ?? [] as $h) {
					$out[$sName][$h['change'] === 'add' ? 'new' : 'changed'] += $h['change'] === 'leave' ? 0 : 1;
				}
			}
		}
		foreach ($plan['errors'] as $e) {
			$isServer = false;
			foreach ($serverWords as $w) {
				$isServer = $isServer || str_contains($e, $w);
			}
			$key = $isServer && isset($out[$sName]) ? $sName : (isset($out[$cName]) ? $cName : $sName);
			if (isset($out[$key])) {
				$out[$key]['errors']++;
			}
		}
		return array_values($out);
	}

	/**
	 * A check that names a client says where it is on the Clients sheet: "Acme: Cluster DL …" becomes
	 * "Clients, row 5 (Acme): Cluster DL …". One about its servers keeps its own words.
	 */
	private static function locate(array $errors, ?array $clients): array {
		$lines = [];
		foreach ($clients['rows'] ?? [] as $r) {
			$lines[(string) ($r['client'] ?? '')] = $r['_line'];
		}
		return array_map(function ($e) use ($lines) {
			foreach ($lines as $name => $line) {
				if ($name !== '' && strpos($e, $name.': ') === 0 && strpos($e, $name.': '._('the Servers sheet').': ') !== 0) {
					return _s('%1$s, row %2$s (%3$s): %4$s', Workbook::CLIENTS, $line, $name, substr($e, strlen($name) + 2));
				}
			}
			return $e;
		}, $errors);
	}

	private function process(?array $clients, ?array $servers, array $data, array $fileErrors, array $counts, bool $xlsx): array {
		$importer = $this->importer();
		$plan = $fileErrors
			? ['rows' => [], 'errors' => array_merge($fileErrors, $clients['errors'] ?? [], $servers['errors'] ?? []), 'ignored' => []]
			: $importer->analyze($clients, $servers);
		if ($xlsx) {
			$plan['errors'] = self::locate($plan['errors'], $clients);
		}
		$data['plan'] = $plan;
		$data['sheets'] = self::sheets($plan, $counts, $xlsx, $clients, $servers);
		if ($plan['errors']) {
			$data['stage'] = 'errors';
			return $data;
		}
		if (!Store::writable()) {
			$data['stage'] = 'errors';
			$data['plan']['errors'][] = _s('Backups cannot be kept: %1$s is missing or not writable. Nothing was changed.', Store::dir());
			return $data;
		}
		// A backup before what is added now; what waits for a tick gets its own when it is applied.
		// Checking a file changes nothing, so it does not use up one of the three.
		$work = array_filter($plan['rows'], fn($r) => $r['status'] === 'new' && !$r['warnings']);
		if ($work) {
			// Backups::take() refuses outright, by throwing, when it finds no clients while the
			// backups already kept hold some: the clients are found by a template name and a host
			// tag that both changed when the product was renamed, so reading zero of them is far
			// likelier to be a lookup that missed than a site whose clients have all gone. It also
			// throws when the data folder cannot be written. Caught here because it was not — this
			// was the one caller of backup() with no try/catch around it, so the refusal left the
			// controller as an uncaught exception and Zabbix answered with a fatal-error page, which
			// is the one place the operator could not read the worded reason for it. Either way
			// there is no backup, so the import stops here with the reason in the plan's errors and
			// nothing is applied, exactly as the not-writable branch above does.
			try {
				$data['backup'] = $this->backup('Import '.$data['file']);
			}
			catch (Exception $e) {
				$data['stage'] = 'errors';
				$data['plan']['errors'][] = $e->getMessage();
				return $data;
			}
		}

		$spec = $this->spec();
		foreach ($plan['rows'] as $row) {
			if ($row['status'] !== 'new' || $row['warnings']) {
				continue;
			}
			$rec = $this->rec();
			['client' => $client] = $spec->fromForm($row['form']);
			try {
				$rec->apply($client);
				$this->noteChange($client['name'], 'imported');
				$data['added'][] = ['name' => $client['name'], 'done' => $rec->done(), 'problems' => $importer->verify($client)];
			}
			catch (Exception $e) {
				$data['added'][] = ['name' => $client['name'], 'done' => $rec->done(), 'problems' => [$e->getMessage()]];
			}
		}

		// What waits for a tick: changes, and new clients that overlap something.
		$pending = array_values(array_filter($plan['rows'], fn($r) => $r['status'] === 'update' || ($r['status'] === 'new' && $r['warnings'])));
		if ($pending) {
			$token = bin2hex(random_bytes(8));
			Store::write('pending-import.json', ['token' => $token, 'file' => $data['file'], 'at' => time(), 'rows' => $pending]);
			$data['token'] = $token;
		}
		$data['pending'] = $pending;
		$data['stage'] = 'done';
		return $data;
	}
}
