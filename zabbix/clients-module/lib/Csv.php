<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

/**
 * Clients as two CSV files. Pure — tested on its own (test/spec.test.php).
 *
 *   clients.csv  one row per client: settings and requested capacity per role
 *   servers.csv  one row per server: client, ip, roles (es_data_hot;es_coord), services, notes,
 *                and any other column kept as an attribute of the server (rack, owner …)
 *
 * Either file may come alone. On import a clients.csv column left out means "no change" and an
 * empty cell "clear it". A client in servers.csv gets exactly the servers listed for it; a
 * client not in it keeps its servers.
 */
class Csv {

	/**
	 * Every client setting, once, for both files: clients.csv column => its form field, and how the
	 * workbook (Workbook.php) shows it — header, note, choices. In the workbook's column order.
	 *
	 *   field    the client form's field (ClientSpec) the column fills
	 *   label    the workbook header; null: not a column of its own there (purchased_devices is
	 *            folded into "Purchased", whichever Purchased by says)
	 *   need     the header is marked " *"
	 *   choices  a dropdown: shown => written to clients.csv, or the name of a list read from this
	 *            Zabbix at download time ('types', 'monitored')
	 *   kind     'number', 'date', 'purchased', 'tls' (Yes/No, and "ca" when Jump CA is given)
	 *   note     what the column takes, for the header's note; example: a value it takes
	 */
	public const BASE = [
		'client' => ['field' => 'name', 'label' => 'Client', 'need' => true, 'width' => 18, 'example' => 'Acme',
			'note' => 'The client\'s name: its host group and every host are named after it. Letters, digits, - and _, starting with a letter or digit.'],
		'type' => ['field' => 'type', 'label' => 'Type', 'choices' => 'types', 'width' => 11, 'example' => 'DI',
			'note' => 'The client\'s type, from Settings → Client types. DI needs the S3 bucket and region.'],
		'purchased_by' => ['field' => 'purchased_by', 'label' => 'Purchased by', 'choices' => ['Storage' => 'storage', 'Devices' => 'devices'], 'width' => 14,
			'example' => 'Storage', 'note' => 'What the client bought: Storage (then Purchased is in GB) or Devices (then Purchased is a count). Empty: Storage.'],
		'purchased_storage_gb' => ['field' => 'purchased', 'label' => 'Purchased', 'kind' => 'purchased', 'width' => 12, 'example' => '20000',
			'note' => 'How much was bought: GB when Purchased by is Storage, a number of devices when it is Devices. A whole number; empty: not set.'],
		'purchased_devices' => ['field' => 'purchased_devices', 'label' => null],
		'soc_lead' => ['field' => 'lead_name', 'label' => 'SOC / Manager lead', 'width' => 20, 'example' => 'Asha Rao',
			'note' => 'Who leads this client\'s monitoring. Free text.'],
		'cluster_dl' => ['field' => 'cluster_dl', 'label' => 'Cluster DL', 'width' => 26, 'example' => 'soc-acme@example.com',
			'note' => 'The client\'s distribution list: one email address. Needed for Alert DL and Weekly report.'],
		'alert_dl' => ['field' => 'alert_dl', 'label' => 'Alert DL', 'choices' => ['Yes' => '1', 'No' => '0'], 'width' => 10, 'example' => 'Yes',
			'note' => 'Yes: problems are emailed to the Cluster DL. Empty: No.'],
		'weekly_report' => ['field' => 'weekly_report', 'label' => 'Weekly report', 'choices' => ['Yes' => '1', 'No' => '0'], 'width' => 14, 'example' => 'Yes',
			'note' => 'Yes: the weekly report goes to the Cluster DL. Empty: No.'],
		'contract_end' => ['field' => 'contract_end', 'label' => 'Contract ends', 'kind' => 'date', 'width' => 14, 'example' => '2027-03-31',
			'note' => 'The day the contract ends: an Excel date, or text like 2027-03-31. Empty: not set.'],
		'es_url' => ['field' => 'es_url', 'label' => 'ES URL', 'need' => true, 'width' => 32, 'example' => 'https://es.acme.example:9200',
			'note' => 'The cluster\'s address, scheme://host:port. Through a jump host: the address as the jump host sees it. Needed for every DI client.'],
		'es_user' => ['field' => 'es_user', 'label' => 'ES user', 'width' => 14, 'example' => 'elastic',
			'note' => 'The Elasticsearch user Zabbix signs in as.'],
		'es_password_in' => ['field' => 'es_password_mode', 'label' => 'Password in', 'choices' => ['Vault' => 'vault', 'Zabbix secret' => 'zabbix'], 'width' => 14,
			'example' => 'Vault', 'note' => 'Where the ES password is kept: Vault (give the Vault path) or a Zabbix secret macro (set it on the client\'s page). Empty: Vault.'],
		'es_password_vault' => ['field' => 'es_password_path', 'label' => 'Vault path', 'width' => 32, 'example' => 'secret/elasticpro/acme:password',
			'note' => 'The ES password in Vault, as path:key. Empty: the default path.'],
		'es_apikey_vault' => ['field' => 'es_apikey_path', 'label' => 'API key Vault path', 'width' => 32, 'example' => 'secret/elasticpro/acme:apikey',
			'note' => 'Through a jump host only: the read-only Elasticsearch API key in Vault, as path:key.'],
		'monitored_by' => ['field' => 'monitored_by', 'label' => 'Monitored by', 'choices' => 'monitored', 'width' => 20, 'example' => 'Zabbix server',
			'note' => 'Who polls the cluster: the Zabbix server, one of this Zabbix\'s proxies or proxy groups, or Jump host (through SSH). Empty: Zabbix server.'],
		'jump_host' => ['field' => 'jump_host', 'label' => 'Jump host', 'width' => 18, 'example' => 'jump.acme.example',
			'note' => 'With Monitored by = Jump host: the jump host\'s name or IP, as the Zabbix server reaches it.'],
		'jump_port' => ['field' => 'jump_port', 'label' => 'Jump port', 'kind' => 'number', 'width' => 10, 'example' => '22',
			'note' => 'The jump host\'s SSH port. Empty: 22.'],
		'jump_user' => ['field' => 'jump_user', 'label' => 'Jump user', 'width' => 12, 'example' => 'zabbix',
			'note' => 'The SSH user Zabbix signs in to the jump host as.'],
		'jump_key' => ['field' => 'jump_key', 'label' => 'Jump key', 'width' => 12, 'example' => 'id_ed25519',
			'note' => 'The SSH key: a file name in the Zabbix server\'s key folder. Empty: id_ed25519.'],
		'jump_tls' => ['field' => 'jump_tls', 'label' => 'Jump TLS', 'choices' => ['Yes' => 'verify', 'No' => 'none'], 'kind' => 'tls', 'width' => 10, 'example' => 'Yes',
			'note' => 'Check the cluster\'s certificate on the jump host: Yes (by the trust store, or by Jump CA when it is given) or No. Empty: Yes.'],
		'jump_ca' => ['field' => 'jump_ca', 'label' => 'Jump CA', 'width' => 18, 'example' => 'C:\\certs\\es-ca.pem',
			'note' => 'With Jump TLS = Yes: a CA file on the jump host to check the certificate with. Empty: the trust store.'],
		's3_bucket' => ['field' => 'ulm_bucket', 'label' => 'S3 bucket', 'width' => 18, 'example' => 'acme-archive',
			'note' => 'The log archive\'s S3 bucket. Needed for DI.'],
		's3_region' => ['field' => 'ulm_region', 'label' => 'S3 region', 'width' => 12, 'example' => 'ap-south-1',
			'note' => 'The bucket\'s AWS region. Needed for DI.'],
		's3_access' => ['field' => 'ulm_auth', 'label' => 'S3 access', 'choices' => ['Zabbix server role' => 'role', 'Access key' => 'key'], 'width' => 18,
			'example' => 'Zabbix server role', 'note' => 'How the bucket is read: the Zabbix server\'s own role, or an access key (give its ID and secret path). Empty: the role.'],
		's3_role_arn' => ['field' => 'ulm_role_arn', 'label' => 'S3 role ARN', 'width' => 24, 'example' => 'arn:aws:iam::123456789012:role/archive-read',
			'note' => 'A role to assume for reading the bucket. Empty: none.'],
		's3_external_id' => ['field' => 'ulm_external_id', 'label' => 'S3 external ID', 'width' => 14, 'example' => '',
			'note' => 'The external ID the role asks for, if any.'],
		's3_access_key_id' => ['field' => 'ulm_access_key_id', 'label' => 'S3 access key ID', 'width' => 18, 'example' => 'AKIA…',
			'note' => 'With S3 access = Access key: the key\'s ID.'],
		's3_secret_vault' => ['field' => 'ulm_secret_path', 'label' => 'S3 secret Vault path', 'width' => 26, 'example' => 'secret/elasticpro/acme:s3',
			'note' => 'With S3 access = Access key: the secret key in Vault, as path:key.'],
		'raw_folder' => ['field' => 'ulm_raw_prefix', 'label' => 'Raw folder', 'width' => 14, 'example' => 'raw/',
			'note' => 'The folder in the bucket raw logs go to.'],
		'enriched_folder' => ['field' => 'ulm_enriched_prefix', 'label' => 'Enriched folder', 'width' => 14, 'example' => 'enriched/',
			'note' => 'The folder in the bucket enriched logs go to.'],
		'agent_port' => ['field' => 'agent_port', 'label' => 'Agent port', 'kind' => 'number', 'width' => 11, 'example' => '10050',
			'note' => 'The Zabbix agent port on the client\'s servers. Empty: 10050.']
	];

	/**
	 * Per role: clients.csv column suffix => [form field suffix, workbook header after "<Role> · "].
	 * disk_gb is /; disk2 … disk5 are extra disks, which the workbook holds in one "Other disks"
	 * column as mount=GB; mount=GB (label null: not a column of their own there).
	 */
	public const PER_ROLE = [
		'servers' => ['field' => 'servers', 'label' => 'Servers'],
		'cpu_cores' => ['field' => 'cpu', 'label' => 'CPU cores'],
		'memory_gb' => ['field' => 'mem', 'label' => 'Memory GB'],
		'disk_gb' => ['field' => 'disk', 'label' => 'Disk / GB'],
		'disk2_mount' => ['field' => 'disk2_fs', 'label' => null], 'disk2_gb' => ['field' => 'disk2', 'label' => null],
		'disk3_mount' => ['field' => 'disk3_fs', 'label' => null], 'disk3_gb' => ['field' => 'disk3', 'label' => null],
		'disk4_mount' => ['field' => 'disk4_fs', 'label' => null], 'disk4_gb' => ['field' => 'disk4', 'label' => null],
		'disk5_mount' => ['field' => 'disk5_fs', 'label' => null], 'disk5_gb' => ['field' => 'disk5', 'label' => null]
	];

	/** The workbook header for a role's extra disks (disk2 … disk5). */
	public const OTHER_DISKS = 'Other disks';

	/** servers.csv's own columns. Any other column is an attribute. */
	public const SERVER_COLUMNS = ['client', 'ip', 'name', 'roles', 'services', 'notes', 'kind'];

	/** Every clients.csv column, in file order: CSV name => form field. */
	public static function columns(array $roles): array {
		$out = array_map(fn($c) => $c['field'], self::BASE);
		foreach (Roles::allRoles($roles) as $r) {
			foreach (self::PER_ROLE as $col => $c) {
				$out[$r['id'].'_'.$col] = $r['id'].'_'.$c['field'];
			}
		}
		return $out;
	}

	/** One clients.csv row per form, as column => text: what both clients.csv and the workbook write. */
	public static function rows(array $roles, array $forms): array {
		$cols = self::columns($roles);
		$out = [];
		foreach ($forms as $form) {
			$row = [];
			foreach ($cols as $col => $field) {
				$v = (string) ($form[$field] ?? '');
				if ($field === 'ulm_auth') {
					$v = $v === 'access_key' ? 'key' : 'role';
				}
				elseif ($field === 'ulm_bucket' && $v === ClientSpec::UNSET_BUCKET) {
					$v = '';
				}
				$row[$col] = $v;
			}
			$out[] = $row;
		}
		return $out;
	}

	/** clients.csv for these forms. Header only when there are none — the empty template. */
	public static function export(array $roles, array $forms): string {
		$rows = [array_keys(self::columns($roles))];
		foreach (self::rows($roles, $forms) as $row) {
			$rows[] = array_values($row);
		}
		return self::write($rows);
	}

	/**
	 * Every server of these forms, for servers.csv and the workbook: ['attrs' => attribute names,
	 * sorted, 'lines' => [['client', 'ip', 'name', 'roles' (ids), 'services', 'notes', 'attrs'], …]].
	 * `name` is the host's name in Zabbix when given (client => ip => name); it is ignored on import.
	 */
	public static function serverLines(array $forms, array $hostNames = []): array {
		$attrs = [];
		$lines = [];
		foreach ($forms as $form) {
			foreach ((array) json_decode((string) ($form['servers'] ?? '[]'), true) as $s) {
				$lines[] = ['client' => (string) $form['name'], 'ip' => (string) $s['ip'], 'name' => (string) ($hostNames[$form['name']][$s['ip']] ?? ''),
					'roles' => array_values((array) $s['roles']), 'services' => array_values((array) ($s['services'] ?? [])),
					'notes' => (string) ($s['notes'] ?? ''), 'attrs' => (array) ($s['attrs'] ?? [])];
				foreach ((array) ($s['attrs'] ?? []) as $k => $_) {
					$attrs[$k] = true;
				}
			}
		}
		$attrs = array_map('strval', array_keys($attrs));
		sort($attrs);
		return ['attrs' => $attrs, 'lines' => $lines];
	}

	/** servers.csv for these forms: one row per server; attribute columns after the fixed ones. */
	public static function exportServers(array $roles, array $forms, array $hostNames = []): string {
		['attrs' => $attrs, 'lines' => $lines] = self::serverLines($forms, $hostNames);
		$rows = [array_merge(self::SERVER_COLUMNS, $attrs)];
		foreach ($lines as $s) {
			$row = [$s['client'], $s['ip'], $s['name'], implode(';', $s['roles']), implode(';', $s['services']), $s['notes'], 'server'];
			foreach ($attrs as $k) {
				$row[] = (string) ($s['attrs'][$k] ?? '');
			}
			$rows[] = $row;
		}
		return self::write($rows);
	}

	private static function write(array $rows): string {
		$fh = fopen('php://temp', 'r+');
		foreach ($rows as $row) {
			// A cell a spreadsheet would run as a formula is written as text.
			fputcsv($fh, array_map(fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) && !is_numeric($v) ? "'".$v : $v, $row), ',', '"', '');
		}
		rewind($fh);
		return "\xEF\xBB\xBF".stream_get_contents($fh);
	}

	/** A file as a header and rows. Excel's "CSV UTF-8", or ;-separated. */
	private static function read(string $text): array {
		$text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
		$first = strtok($text, "\r\n");
		$sep = $first !== false && substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
		$fh = fopen('php://temp', 'r+');
		fwrite($fh, $text);
		rewind($fh);
		$header = fgetcsv($fh, 0, $sep, '"', '');
		if (!$header || $header === [null]) {
			return [null, []];
		}
		$header = array_map(fn($h) => strtolower(trim((string) $h)), $header);
		$rows = [];
		$line = 1;
		while (($cells = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
			$line++;
			if ($cells === [null] || implode('', array_map('trim', $cells)) === '') {
				continue;
			}
			$row = ['_line' => $line];
			foreach ($header as $i => $h) {
				if ($h === '') {
					continue;
				}
				$v = trim((string) ($cells[$i] ?? ''));
				// Undo the formula guard export adds.
				$row[$h] = strpos($v, "'") === 0 && preg_match('/^\'[=+\-@]/', $v) ? substr($v, 1) : $v;
			}
			$rows[] = $row;
		}
		return [$header, $rows];
	}

	private static function duplicates(array $header): array {
		$out = [];
		foreach (array_count_values(array_filter($header)) as $h => $n) {
			if ($n > 1) {
				$out[] = _s('Column "%1$s" appears %2$s times.', $h, $n);
			}
		}
		return $out;
	}

	/**
	 * clients.csv as rows keyed by column. Returns ['columns', 'rows', 'errors', 'ignored'].
	 */
	public static function parse(string $text, array $roles): array {
		[$header, $rows] = self::read($text);
		if ($header === null) {
			return ['columns' => [], 'rows' => [], 'errors' => [_('clients.csv is empty.')], 'ignored' => []];
		}
		return self::parseRows($header, $rows, $roles);
	}

	/**
	 * Rows already split into cells — from clients.csv, or the workbook's Clients sheet put into
	 * clients.csv's columns (Workbook::parse). $header holds column names; each row is column =>
	 * text plus '_line', its line or row number. $file names the source in messages.
	 */
	public static function parseRows(array $header, array $rows, array $roles, string $file = 'clients.csv'): array {
		$out = ['columns' => [], 'rows' => [], 'errors' => [], 'ignored' => [], 'file' => $file];
		$known = self::columns($roles);
		// An older file's one disk mount per role (<role>_disk_mount) is still read.
		foreach (Roles::allRoles($roles) as $r) {
			$known[$r['id'].'_disk_mount'] = '_legacy';
		}
		$out['ignored'] = array_values(array_filter($header, fn($h) => $h !== '' && !array_key_exists($h, $known)));
		if (!in_array('client', $header, true)) {
			$out['errors'][] = $file === 'clients.csv' ? _('clients.csv has no "client" column. Download its template for the columns it expects.')
				: _s('%1$s has no "Client" column. Download the template for the columns it expects.', $file);
			return $out;
		}
		$out['errors'] = self::duplicates($header);
		$out['columns'] = array_values(array_filter($header, fn($h) => array_key_exists($h, $known)));
		foreach ($rows as $row) {
			$out['rows'][] = array_intersect_key($row, $known + ['_line' => true]);
		}
		return $out;
	}

	/**
	 * servers.csv, grouped by client: ['clients' => [name => [server, …]], 'errors', 'ignored'].
	 * Roles may be written by id (es_data_hot) or by name (ES Data Hot).
	 */
	public static function parseServers(string $text, array $roles): array {
		[$header, $rows] = self::read($text);
		if ($header === null) {
			return ['clients' => [], 'errors' => [_('servers.csv is empty.')], 'ignored' => []];
		}
		return self::parseServerRows($header, $rows, $roles);
	}

	/**
	 * Server rows already split into cells — from servers.csv, or the workbook's Servers sheet put
	 * into servers.csv's columns. $where names a row in messages (line number => words).
	 */
	public static function parseServerRows(array $header, array $rows, array $roles, ?callable $where = null, string $file = 'servers.csv'): array {
		$where = $where ?? fn($line) => _s('servers.csv line %1$s', $line);
		$out = ['clients' => [], 'errors' => [], 'ignored' => [], 'file' => $file];
		foreach (['client', 'ip', 'roles'] as $need) {
			if (!in_array($need, $header, true)) {
				$out['errors'][] = _s('%1$s has no "%2$s" column. Download its template for the columns it expects.', $file, $need);
			}
		}
		$out['errors'] = array_merge($out['errors'], self::duplicates($header));
		if ($out['errors']) {
			return $out;
		}
		$byLabel = self::roleNames($roles);
		$attrCols = array_values(array_filter($header, fn($h) => $h !== '' && !in_array($h, self::SERVER_COLUMNS, true)));
		foreach ($rows as $row) {
			$client = (string) ($row['client'] ?? '');
			if ($client === '') {
				$out['errors'][] = _s('%1$s: no client.', $where($row['_line']));
				continue;
			}
			$kind = strtolower((string) ($row['kind'] ?? ''));
			if ($kind !== '' && $kind !== 'server') {
				$out['errors'][] = _s('%1$s: kind "%2$s" is not supported yet; only server.', $where($row['_line']), $row['kind']);
				continue;
			}
			$ids = [];
			foreach (preg_split('/\s*[;|+]\s*/', (string) ($row['roles'] ?? '')) as $name) {
				if (trim($name) === '') {
					continue;
				}
				$ids[] = $byLabel[strtolower(trim($name))] ?? trim($name);
			}
			$attrs = [];
			foreach ($attrCols as $col) {
				$attrs[self::attrKey($col)] = (string) ($row[$col] ?? '');
			}
			$out['clients'][$client][] = ['ip' => (string) ($row['ip'] ?? ''), 'roles' => $ids,
				'services' => preg_split('/\s*[;,]\s*/', (string) ($row['services'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
				'notes' => (string) ($row['notes'] ?? ''), 'attrs' => $attrs, '_line' => $row['_line']];
		}
		return $out;
	}

	/** id, label and short name, lower case => role id. */
	public static function roleNames(array $roles): array {
		$out = [];
		foreach (Roles::allRoles($roles) as $r) {
			$out[strtolower($r['id'])] = $r['id'];
			$out[strtolower($r['label'])] = $r['id'];
			$out[strtolower($r['short'])] = $r['id'];
		}
		return $out;
	}

	/** A column header as the attribute it becomes: "Rack" → rack, "owner team" → owner_team. */
	public static function attrKey(string $header): string {
		return preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($header)));
	}

	/** A clients.csv row laid over a client's current form: columns in the file replace, columns not in it keep. */
	public static function toForm(array $row, array $base, array $roles): array {
		$form = $base;
		foreach (self::columns($roles) as $col => $field) {
			if (!array_key_exists($col, $row)) {
				continue;
			}
			$v = $row[$col];
			if ($field === 'ulm_auth') {
				$v = in_array(strtolower($v), ['', 'role', 'role_base', 'iam'], true) ? 'role_base'
					: (in_array(strtolower($v), ['key', 'access_key'], true) ? 'access_key' : $v);
			}
			elseif ($field === 'type') {
				$v = ClientTypes::normalize($v);
			}
			elseif (in_array($field, ['purchased_by', 'es_password_mode'], true)) {
				$v = strtolower($v);
			}
			$form[$field] = $v;
		}
		foreach (Roles::allRoles($roles) as $r) {
			if (array_key_exists($r['id'].'_disk_mount', $row)) {
				$form = ClientSpec::legacyDisk($form, $r['id'], $row[$r['id'].'_disk_mount']);
			}
		}
		return $form;
	}
}
