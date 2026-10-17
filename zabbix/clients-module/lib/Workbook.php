<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use RuntimeException;

/**
 * clients.xlsx: the clients and their servers as one Excel workbook — the Import page's file.
 *
 *   Clients  one row per client: Csv::BASE's columns under their workbook headers, then five
 *            requested-capacity columns per role (Csv::PER_ROLE), grouped and collapsed
 *   Servers  one row per server: Client, IP, Host name, Role 1–4, Services, Notes, then any other
 *            column as an attribute
 *   Sample   filled-in examples; never imported (nor is any sheet but Clients and Servers)
 *
 * Every header carries a note; every column with a fixed set of values is a dropdown, and the
 * import checks those values again (a pasted value gets past Excel's check).
 *
 * The workbook is only another way to write clients.csv and servers.csv: it is built from
 * Csv::rows() / Csv::serverLines() and read into Csv::parseRows() / Csv::parseServerRows(), so the
 * Importer sees exactly what the CSV files give it. The columns, their fields and their headers
 * are Csv's one table; this file adds only how a cell shows a value and reads it back.
 */
class Workbook {

	public const CLIENTS = 'Clients';
	public const SERVERS = 'Servers';
	public const SAMPLE = 'Sample';
	/** Dropdowns and their checks cover rows 2 to this. */
	public const LAST_ROW = 2000;
	public const ROLE_SLOTS = 4;
	/** Header of a hidden column holding a dropdown's choices. Such columns are never read as data. */
	public const CHOICES = 'Choices: ';
	public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	/* ------------------------------------------------------------------ the columns */

	/**
	 * The Clients sheet's columns, in order: ['label', 'header', 'entry' (Csv::BASE's, or a
	 * per-role one), 'covers' (clients.csv columns it fills), 'role' (null for the base columns),
	 * 'part' (the PER_ROLE key, or 'disks')].
	 */
	public static function clientColumns(array $roles): array {
		$out = [];
		foreach (Csv::BASE as $col => $e) {
			if ($e['label'] === null) {
				continue;
			}
			$covers = ($e['kind'] ?? '') === 'purchased' ? [$col, 'purchased_devices'] : [$col];
			$out[] = ['label' => $e['label'], 'header' => self::header($e), 'entry' => $e, 'covers' => $covers, 'role' => null, 'part' => null];
		}
		foreach (Roles::allRoles($roles) as $r) {
			$disks = [];
			foreach (Csv::PER_ROLE as $col => $e) {
				if ($e['label'] === null) {
					$disks[] = $r['id'].'_'.$col;
					continue;
				}
				$label = $r['label'].' · '.$e['label'];
				$out[] = ['label' => $label, 'header' => $label, 'entry' => ['kind' => 'number'] + $e, 'covers' => [$r['id'].'_'.$col], 'role' => $r, 'part' => $col];
			}
			$label = $r['label'].' · '.Csv::OTHER_DISKS;
			$out[] = ['label' => $label, 'header' => $label, 'entry' => ['kind' => 'disks', 'label' => Csv::OTHER_DISKS], 'covers' => $disks, 'role' => $r, 'part' => 'disks'];
		}
		return $out;
	}

	/** A header as shown: its label, " ▾" for a dropdown, " *" when it is needed. */
	private static function header(array $e): string {
		return $e['label'].(isset($e['choices']) ? ' ▾' : '').(!empty($e['need']) ? ' *' : '');
	}

	/** The Servers sheet's own columns: header => servers.csv column ('role1' … 'role4' are Role 1–4). */
	public static function serverColumns(): array {
		$out = ['Client ▾ *' => 'client', 'IP *' => 'ip', 'Host name' => 'name'];
		for ($n = 1; $n <= self::ROLE_SLOTS; $n++) {
			$out['Role '.$n.' ▾'.($n === 1 ? ' *' : '')] = 'role'.$n;
		}
		return $out + ['Services' => 'services', 'Notes' => 'notes'];
	}

	/** A header as the reader matches it: without " *" and " ▾", spaces collapsed, lower case. */
	public static function key(string $header): string {
		$h = preg_replace('/(\s*[*▾])+\s*$/u', '', trim($header));
		return mb_strtolower((string) preg_replace('/\s+/u', ' ', (string) $h));
	}

	/**
	 * The choices of a dropdown column: shown text => clients.csv text. $lists comes from this
	 * Zabbix: 'types' (Settings → Client types), 'proxies', 'proxy_groups'.
	 */
	public static function choices(array $entry, array $lists): array {
		$c = $entry['choices'] ?? null;
		if (is_array($c)) {
			return $c;
		}
		if ($c === 'types') {
			$types = $lists['types'] ?? ClientTypes::ALL;
			return array_combine($types, $types);
		}
		if ($c === 'monitored') {
			$out = [_('Zabbix server') => '', _('Jump host') => 'jump'];
			foreach ($lists['proxies'] ?? [] as $p) {
				$out[(string) $p] = 'proxy:'.$p;
			}
			foreach ($lists['proxy_groups'] ?? [] as $g) {
				$out[_s('%1$s (proxy group)', $g)] = 'group:'.$g;
			}
			return $out;
		}
		return [];
	}

	/** "pick A, B or C" — or, for a long list, "pick one from its dropdown". */
	private static function pick(array $shown): string {
		$shown = array_values(array_map('strval', $shown));
		if (count($shown) > 6) {
			return _('pick one from its dropdown');
		}
		$last = array_pop($shown);
		return $shown ? _s('pick %1$s or %2$s', implode(', ', $shown), $last) : _s('pick %1$s', $last);
	}

	/* ------------------------------------------------------------------ building */

	/**
	 * The workbook. With no forms: the template — Clients and Servers empty, and the Sample. With
	 * forms (ClientState::plain each): those clients and their servers, ready to edit and import back.
	 * $hostNames: client => ip => the host's name in Zabbix, shown in Host name.
	 */
	public static function build(array $roles, array $lists, array $forms = [], array $hostNames = []): string {
		return Xlsx::build([self::clientsSheet($roles, $lists, $forms), self::serversSheet($roles, $lists, $forms, $hostNames), self::sampleSheet($roles, $lists)]);
	}

	/** Where each dropdown's choices go: inline when short, else a hidden column on the Clients sheet. */
	private static function listFormula(array $shown, string $name, bool $forceColumn, array &$hidden, int $firstHidden): string {
		$inline = '"'.implode(',', $shown).'"';
		$clean = !array_filter($shown, fn($s) => strpos((string) $s, ',') !== false || strpos((string) $s, '"') !== false);
		if (!$forceColumn && $clean && strlen($inline) <= 250 && $shown) {
			return $inline;
		}
		if (!isset($hidden[$name])) {
			$hidden[$name] = ['col' => $firstHidden + count($hidden), 'values' => $shown];
		}
		$letter = Xlsx::col($hidden[$name]['col']);
		return self::CLIENTS.'!$'.$letter.'$2:$'.$letter.'$'.(1 + max(1, count($shown)));
	}

	/** The hidden choice columns are worked out once, so the Servers sheet can point at the Clients sheet's. */
	private static function hiddenLists(array $roles, array $lists): array {
		$cols = self::clientColumns($roles);
		$first = count($cols) + 1;
		$hidden = [];
		$formulas = [];
		foreach ($cols as $c) {
			if (isset($c['entry']['choices'])) {
				$formulas[$c['label']] = self::listFormula(array_keys(self::choices($c['entry'], $lists)), $c['label'],
					$c['entry']['choices'] === 'monitored', $hidden, $first);
			}
		}
		$formulas['_roles'] = self::listFormula(array_column(Roles::allRoles($roles), 'label'), _('Role'), false, $hidden, $first);
		return [$formulas, $hidden];
	}

	private static function clientsSheet(array $roles, array $lists, array $forms): array {
		$cols = self::clientColumns($roles);
		[$formulas, $hidden] = self::hiddenLists($roles, $lists);
		$sheet = ['name' => self::CLIENTS, 'rows' => [], 'cols' => [], 'freeze' => 'B2', 'validations' => [], 'notes' => [],
			'summaryLeft' => true, 'heights' => [1 => 32], 'active' => true];
		$lastBase = 0;
		foreach ($cols as $i => $c) {
			$n = $i + 1;
			$e = $c['entry'];
			$sheet['rows'][1][$n] = ['v' => $c['header'], 'style' => $c['role'] ? 'header2' : 'header'];
			if ($c['role'] === null) {
				$lastBase = $n;
				$sheet['cols'][$n] = ['width' => $e['width'] ?? 14];
			}
			else {
				$sheet['cols'][$n] = ['width' => $c['part'] === 'disks' ? 22 : 13, 'outline' => 1, 'hidden' => true];
			}
			$sheet['notes'][Xlsx::col($n).'1'] = self::note($c, $lists);
			if (isset($e['choices'])) {
				$shown = array_keys(self::choices($e, $lists));
				$sheet['validations'][] = self::validation(Xlsx::col($n), $c['label'], $formulas[$c['label']], $shown);
			}
		}
		// The capacity group's +/- sits over the last base column, and starts collapsed.
		$sheet['cols'][$lastBase]['collapsed'] = true;
		foreach ($hidden as $name => $h) {
			$sheet['rows'][1][$h['col']] = ['v' => self::CHOICES.$name, 'style' => 'header'];
			foreach ($h['values'] as $k => $v) {
				$sheet['rows'][2 + $k][$h['col']] = (string) $v;
			}
			$sheet['cols'][$h['col']] = ['width' => 18, 'hidden' => true];
		}

		$r = 2;
		foreach (Csv::rows($roles, $forms) as $row) {
			foreach ($cols as $i => $c) {
				$v = self::show($c, $row, $lists);
				if ($v !== null && $v !== '') {
					$sheet['rows'][$r][$i + 1] = $v;
				}
			}
			$r++;
		}
		return $sheet;
	}

	private static function validation(string $letter, string $label, string $formula, array $shown, string $style = 'stop', ?string $error = null): array {
		return ['sqref' => $letter.'2:'.$letter.self::LAST_ROW, 'list' => $formula, 'style' => $style,
			'errorTitle' => mb_substr($label, 0, 32), 'error' => $error ?? _s('%1$s: %2$s.', $label, self::pick($shown)),
			'promptTitle' => mb_substr($label, 0, 32), 'prompt' => count($shown) <= 8 ? implode(' · ', $shown) : _('Pick from the list.')];
	}

	/** A header's note: what it takes, its choices, an example. */
	private static function note(array $c, array $lists): array {
		$e = $c['entry'];
		if ($c['role'] !== null) {
			$role = $c['role']['label'];
			$what = [
				'servers' => _s('How many %1$s servers the client asked for. A whole number; empty: not set.', $role),
				'cpu_cores' => _s('CPU cores asked for, for %1$s. A number; empty: not set.', $role),
				'memory_gb' => _s('Memory in GB asked for, for %1$s. A number; empty: not set.', $role),
				'disk_gb' => _s('Disk / in GB asked for, for %1$s. A number; empty: not set.', $role),
				'disks' => _s('%1$s\'s other disks, besides /: mount=GB, separated by ;. Up to %2$s. Empty: none.', $role, Roles::DISK_SLOTS - 1)
			][$c['part']];
			$example = ['servers' => '2', 'cpu_cores' => '16', 'memory_gb' => '64', 'disk_gb' => '200', 'disks' => '/data=4000; /logs=500'][$c['part']];
			return ['title' => $c['header'], 'text' => $what."\n"._s('Example: %1$s', $example)."\n"._('Requested capacity: open the group with + above the headers.')];
		}
		$text = $e['note'];
		if (isset($e['choices'])) {
			$shown = array_keys(self::choices($e, $lists));
			$text .= "\n".(count($shown) <= 12 ? _s('Choices: %1$s.', implode(', ', $shown)) : _('Choices: in the dropdown.'));
		}
		if (($e['example'] ?? '') !== '') {
			$text .= "\n"._s('Example: %1$s', $e['example']);
		}
		if (!empty($e['need'])) {
			$text .= "\n"._('Required.');
		}
		return ['title' => $c['header'], 'text' => $text];
	}

	/** One clients.csv row's value as the workbook shows it in this column. */
	private static function show(array $c, array $row, array $lists) {
		$e = $c['entry'];
		$kind = $e['kind'] ?? null;
		$col = $c['covers'][0];
		if ($kind === 'disks') {
			$parts = [];
			for ($k = 0; $k + 1 < count($c['covers']); $k += 2) {
				$mount = trim((string) ($row[$c['covers'][$k]] ?? ''));
				if ($mount !== '') {
					$parts[] = $mount.'='.self::number((string) ($row[$c['covers'][$k + 1]] ?? ''), '0');
				}
			}
			return implode('; ', $parts);
		}
		if ($kind === 'purchased') {
			$v = ($row['purchased_by'] ?? '') === 'devices' ? ($row['purchased_devices'] ?? '') : ($row[$col] ?? '');
			return self::number((string) $v);
		}
		$v = (string) ($row[$col] ?? '');
		if ($kind === 'number') {
			return self::number($v);
		}
		if ($kind === 'date') {
			return $v === '' ? null : (Xlsx::serial($v) !== null ? ['v' => $v, 'style' => 'date'] : $v);
		}
		if ($kind === 'tls') {
			return $v === '' ? '' : ($v === 'none' ? 'No' : 'Yes');
		}
		if (isset($e['choices'])) {
			if ($v === '' && $e['choices'] !== 'monitored') {
				return '';
			}
			$shown = array_search($v, self::choices($e, $lists), true);
			if ($shown === false && $e['choices'] === 'monitored') {
				// A proxy this Zabbix no longer has: shown by name, and the import says what is wrong.
				return preg_replace('/^(proxy|group):/', '', $v);
			}
			return $shown === false ? $v : (string) $shown;
		}
		return $v;
	}

	/** A number for a number cell: int or float; empty for not set ("" or, for $zero, "0"). */
	private static function number(string $v, string $zero = '') {
		$v = trim($v);
		if ($v === '' || ($zero === '' && is_numeric($v) && (float) $v == 0)) {
			return $zero === '' ? null : $zero;
		}
		if (!is_numeric($v)) {
			return $v;
		}
		return preg_match('/^-?\d+$/', $v) ? (int) $v : (float) $v;
	}

	private static function serversSheet(array $roles, array $lists, array $forms, array $hostNames): array {
		[$formulas] = self::hiddenLists($roles, $lists);
		$sheet = ['name' => self::SERVERS, 'rows' => [], 'cols' => [], 'freeze' => 'A2', 'validations' => [], 'notes' => [], 'heights' => [1 => 20]];
		['attrs' => $attrs, 'lines' => $lines] = Csv::serverLines($forms, $hostNames);
		$labels = array_column(Roles::allRoles($roles), 'label', 'id');
		$roleNames = array_values($labels);
		$notes = [
			'client' => _('The client this server belongs to: a name on the Clients sheet, or a client already in Zabbix. A client listed here gets exactly the servers listed for it; a client not listed keeps its servers.')."\n"._('Example: Acme')."\n"._('Required.'),
			'ip' => _('The server\'s IPv4 address. Once per client: give one server all its roles. An IP belongs to one client only.')."\n"._('Example: 10.10.0.11')."\n"._('Required.'),
			'name' => _('The host\'s name in Zabbix, filled in on export so you can tell the rows apart. Not read on import: ElasticPro names new hosts from the client and the roles (Acme-ES-Data-Hot-1).'),
			'role1' => _('The server\'s role, from the Roles page. More roles on the same server go in Role 2 to Role 4: a single node is ES Data, Parser, Forwarder and Engine.')."\n"._s('Choices: %1$s.', implode(', ', $roleNames))."\n"._('Required.'),
			'services' => _('Services on the server, separated by ;.')."\n"._('Example: nginx; kafka'),
			'notes' => _('Free text about the server.')
		];
		$n = 0;
		foreach (self::serverColumns() as $header => $field) {
			$n++;
			$sheet['rows'][1][$n] = ['v' => $header, 'style' => 'header'];
			$sheet['cols'][$n] = ['width' => ['client' => 16, 'ip' => 15, 'name' => 28, 'services' => 18, 'notes' => 24][$field] ?? 17];
			$sheet['notes'][Xlsx::col($n).'1'] = ['title' => $header, 'text' => $notes[$field]
				?? _('Another role on the same server, from the Roles page. Empty: none.')."\n"._('Example: ES Coordination')];
			if ($field === 'client') {
				// A warning, not a stop: a client already in Zabbix may get servers without a Clients row.
				$sheet['validations'][] = ['prompt' => _('A client on the Clients sheet, or one already in Zabbix.')]
					+ self::validation('A', _('Client'), self::CLIENTS.'!$A$2:$A$'.self::LAST_ROW, [], 'warning',
					_('Client: not a name on the Clients sheet. Fine if it is already a client in Zabbix; otherwise add it to the Clients sheet.'));
			}
			elseif (strpos($field, 'role') === 0) {
				$sheet['validations'][] = self::validation(Xlsx::col($n), 'Role '.substr($field, 4), $formulas['_roles'], $roleNames, 'stop',
					_s('%1$s: pick one of the roles on the Roles page, or leave it empty.', 'Role '.substr($field, 4)));
			}
		}
		$fixed = $n;
		foreach ($attrs as $k) {
			$n++;
			$header = self::attrHeader($k);
			$sheet['rows'][1][$n] = ['v' => $header, 'style' => 'header'];
			$sheet['cols'][$n] = ['width' => 14];
			$sheet['notes'][Xlsx::col($n).'1'] = ['title' => $header, 'text' => _('An attribute of the server, kept with it in Zabbix. Any column not listed above becomes one; an empty cell clears it.')];
		}
		$r = 2;
		foreach ($lines as $s) {
			$cells = [1 => $s['client'], 2 => $s['ip'], 3 => $s['name']];
			$ids = $s['roles'];
			for ($k = 0; $k < self::ROLE_SLOTS; $k++) {
				// More roles than slots (rare): the rest share the last slot, ;-separated, and read back the same.
				$slot = $k < self::ROLE_SLOTS - 1 ? array_slice($ids, $k, 1) : array_slice($ids, $k);
				$cells[4 + $k] = implode('; ', array_map(fn($id) => $labels[$id] ?? $id, $slot));
			}
			$cells[4 + self::ROLE_SLOTS] = implode('; ', $s['services']);
			$cells[5 + self::ROLE_SLOTS] = $s['notes'];
			foreach ($attrs as $i => $k) {
				$cells[$fixed + 1 + $i] = (string) ($s['attrs'][$k] ?? '');
			}
			$sheet['rows'][$r++] = array_filter($cells, fn($v) => $v !== '');
		}
		return $sheet;
	}

	/** An attribute name as a header: rack → Rack, owner_team → Owner team (Csv::attrKey reads it back). */
	public static function attrHeader(string $key): string {
		return ucfirst(str_replace('_', ' ', $key));
	}

	private static function sampleSheet(array $roles, array $lists): array {
		$byId = Roles::byId($roles);
		$all = Roles::allRoles($roles);
		$label = fn(string $id) => $byId[$id]['label'] ?? $id;
		$hot = isset($byId['es_data_hot']) ? $byId['es_data_hot'] : ($all[0] ?? ['id' => 'es_data_hot', 'label' => 'ES Data Hot']);
		$cols = [];
		foreach (self::clientColumns($roles) as $c) {
			$cols[$c['role'] === null ? $c['covers'][0] : $c['role']['id'].':'.$c['part']] = $c;
		}
		$pickCols = ['client', 'type', 'purchased_by', 'purchased_storage_gb', 'es_url', 'es_password_in', 'es_password_vault', 'monitored_by', 'weekly_report',
			$hot['id'].':servers', $hot['id'].':disks'];
		$proxy = ($lists['proxies'] ?? [])[0] ?? _('Zabbix server');
		$types = $lists['types'] ?? ClientTypes::ALL;
		$type = fn(string $t) => in_array($t, $types, true) ? $t : ($types[0] ?? $t);
		$clients = [
			['Acme', $type('DI'), 'Storage', 20000, 'https://es.acme.example:9200', 'Vault', 'secret/elasticpro/acme:password', _('Zabbix server'), 'Yes', 2, '/data=4000'],
			['Globex', $type('On-Prem'), 'Devices', 400, 'https://es.globex.example:9200', 'Zabbix secret', '', $proxy, 'No', null, ''],
			['Initech', $type('CI'), 'Storage', 5000, 'https://es.initech.example:9200', 'Vault', '', _('Zabbix server'), 'Yes', 3, '/data=2000; /logs=500']
		];
		$sheet = ['name' => self::SAMPLE, 'rows' => [], 'cols' => [], 'merges' => ['A1:K1'], 'notes' => [], 'heights' => [1 => 24, 4 => 32, 10 => 20]];
		$sheet['rows'][1][1] = ['v' => _('SAMPLE — not imported. Copy what you need to the Clients and Servers sheets.'), 'style' => 'banner'];
		for ($k = 2; $k <= 11; $k++) {
			$sheet['rows'][1][$k] = ['v' => null, 'style' => 'banner'];
		}
		$sheet['rows'][3][1] = ['v' => _('Sample clients (as on the Clients sheet)'), 'style' => 'title'];
		$n = 0;
		foreach ($pickCols as $key) {
			$n++;
			$c = $cols[$key] ?? null;
			$sheet['rows'][4][$n] = ['v' => $c['header'] ?? $key, 'style' => ($c['role'] ?? null) ? 'header2' : 'header'];
			$sheet['cols'][$n] = ['width' => [1 => 14, 5 => 30, 7 => 31, 8 => 18, 10 => 16, 11 => 22][$n] ?? 13];
		}
		foreach ($clients as $i => $row) {
			foreach ($row as $k => $v) {
				if ($v !== null && $v !== '') {
					$sheet['rows'][5 + $i][$k + 1] = $v;
				}
			}
		}
		$sheet['notes']['D4'] = ['title' => _('Purchased'), 'text' => _('GB when Purchased by is Storage (Acme: 20000 GB), a count when it is Devices (Globex: 400 devices).')];
		$sheet['notes']['G4'] = ['title' => _('Vault path'), 'text' => _('Empty with Password in = Vault: the default path (Initech). With Zabbix secret there is no path: set the password on the client\'s page (Globex).')];
		$sheet['notes']['K4'] = ['title' => $cols[$hot['id'].':disks']['header'] ?? Csv::OTHER_DISKS,
			'text' => _s('Extra mounts besides /, as mount=GB separated by ;. Up to %1$s. Empty: none. Each role has its own Other disks column, in the collapsed Requested capacity group on the Clients sheet.', Roles::DISK_SLOTS - 1)];

		$sheet['rows'][9][1] = ['v' => _('Sample servers (as on the Servers sheet)'), 'style' => 'title'];
		$headers = array_merge(array_keys(self::serverColumns()), ['Rack']);
		foreach ($headers as $k => $h) {
			$sheet['rows'][10][$k + 1] = ['v' => $h, 'style' => 'header'];
		}
		$servers = [
			['Acme', '10.10.0.11', '', $label('es_data_hot'), '', '', '', '', '', 'R4'],
			['Acme', '10.10.0.12', '', $label('es_master'), $label('es_coord'), '', '', '', '', 'R4'],
			['Acme', '10.10.0.21', 'acme-edge-01', $label('forwarder'), '', '', '', 'nginx; kafka', '', 'R7'],
			['Globex', '10.20.0.5', '', $label('es_data'), $label('parser'), $label('forwarder'), $label('engine'), '', _('single node'), '']
		];
		foreach ($servers as $i => $row) {
			$sheet['rows'][11 + $i] = array_filter(array_combine(range(1, count($row)), $row), fn($v) => $v !== '');
		}
		$sheet['notes']['C10'] = ['title' => _('Host name'), 'text' => _('Leave it empty: ElasticPro names each host from the client and its roles (Acme-ES-Data-Hot-1). On export it shows the name the host has in Zabbix; it is not read back.')];
		$sheet['notes']['D10'] = ['title' => _('Role 1 – Role 4'), 'text' => _('One role per column, from the Roles page. Acme\'s 10.10.0.12 is a master that also coordinates; Globex\'s 10.20.0.5 is a single node: ES Data, Parser, Forwarder and Engine on one machine.')];
		$sheet['notes']['J10'] = ['title' => _('Rack'), 'text' => _('Not one of the Servers sheet\'s own columns, so it becomes an attribute of the server. Add any column you like.')];
		return $sheet;
	}

	/* ------------------------------------------------------------------ reading */

	/**
	 * An uploaded workbook, as the CSV path gives it: ['clients' => Csv::parseRows() or null,
	 * 'servers' => Csv::parseServerRows() or null, 'errors' => what is wrong with the file itself,
	 * 'counts' => [sheet => data rows], 'skipped' => sheet names not read].
	 * Every check that names a cell says which sheet, row and column, and how to put it right.
	 */
	public static function parse(string $bytes, array $roles, array $lists): array {
		$out = ['clients' => null, 'servers' => null, 'errors' => [], 'counts' => [], 'skipped' => []];
		try {
			$sheets = Xlsx::read($bytes);
		}
		catch (RuntimeException $e) {
			$out['errors'][] = $e->getMessage();
			return $out;
		}
		$found = [];
		foreach ($sheets as $name => $rows) {
			$k = mb_strtolower(trim((string) $name));
			if ($k === mb_strtolower(self::CLIENTS) && !isset($found['clients'])) {
				$found['clients'] = $rows;
			}
			elseif ($k === mb_strtolower(self::SERVERS) && !isset($found['servers'])) {
				$found['servers'] = $rows;
			}
			else {
				$out['skipped'][] = (string) $name;
			}
		}
		if (!$found) {
			$out['errors'][] = _('The workbook has no Clients or Servers sheet. Download the template and fill in its Clients and Servers sheets — the Sample sheet is never imported.');
			return $out;
		}
		if (isset($found['clients'])) {
			[$out['clients'], $errors, $out['counts'][self::CLIENTS]] = self::readClients($found['clients'], $roles, $lists);
			$out['errors'] = array_merge($out['errors'], $errors);
		}
		if (isset($found['servers'])) {
			[$out['servers'], $errors, $out['counts'][self::SERVERS]] = self::readServers($found['servers'], $roles);
			$out['errors'] = array_merge($out['errors'], $errors);
		}
		return $out;
	}

	/** Where a cell is, for a message: "Clients, row 5 (Acme)". */
	private static function at(string $sheet, int $row, string $who): string {
		return $who !== '' ? _s('%1$s, row %2$s (%3$s)', $sheet, $row, $who) : _s('%1$s, row %2$s', $sheet, $row);
	}

	private static function readClients(array $rows, array $roles, array $lists): array {
		$errors = [];
		$header = $rows[1] ?? [];
		unset($rows[1]);
		$byKey = [];
		$cols = self::clientColumns($roles);
		foreach ($cols as $c) {
			$byKey[self::key($c['label'])] = $c;
		}
		foreach ($cols as $c) {
			foreach ($c['covers'] as $col) {
				// clients.csv's own column names are read too, one cell to one column — unless the
				// name is also a header (Client, Type), which is then read as the header.
				$byKey[$col] = $byKey[$col] ?? ['csv' => $col] + $c;
			}
		}
		$map = [];
		$ignored = [];
		$skip = [];
		foreach ($header as $n => $h) {
			if (strpos($h, self::CHOICES) === 0) {
				$skip[$n] = true;
				continue;
			}
			$k = self::key($h);
			if (isset($byKey[$k])) {
				$map[$n] = $byKey[$k];
			}
			elseif (isset($byKey[$h])) {
				$map[$n] = $byKey[$h];
			}
			else {
				$ignored[] = $h;
			}
		}
		$csvHeader = [];
		foreach ($map as $c) {
			foreach (isset($c['csv']) ? [$c['csv']] : $c['covers'] as $col) {
				$csvHeader[] = $col;
			}
		}
		// The same column twice is caught by Csv::parseRows, by its clients.csv name.
		$tlsCol = array_search('jump_ca', array_map(fn($c) => $c['covers'][0], $map), true);
		$out = [];
		$count = 0;
		foreach ($rows as $r => $cells) {
			$cells = array_diff_key($cells, $skip);
			if (implode('', $cells) === '') {
				continue;
			}
			$count++;
			$who = '';
			foreach ($map as $n => $c) {
				if ($c['covers'][0] === 'client') {
					$who = (string) ($cells[$n] ?? '');
				}
			}
			$row = ['_line' => $r];
			foreach ($map as $n => $c) {
				$v = trim((string) ($cells[$n] ?? ''));
				if (isset($c['csv'])) {
					$row[$c['csv']] = $v;
					continue;
				}
				$ca = $tlsCol !== false ? trim((string) ($cells[$tlsCol] ?? '')) : '';
				[$values, $error] = self::read($c, $v, $lists, $ca);
				if ($error !== null) {
					$errors[] = self::at(self::CLIENTS, $r, $who).': '.$error;
				}
				$row = array_merge($row, $values);
			}
			$out[] = $row;
		}
		if (!$map && $count) {
			$errors[] = _('Clients: the first row must be the column headers, as in the template. Download the template for them.');
			return [null, $errors, $count];
		}
		$parsed = Csv::parseRows($csvHeader, $out, $roles, _('the Clients sheet'));
		$parsed['ignored'] = array_merge($parsed['ignored'], array_map(fn($h) => _s('%1$s (Clients)', $h), $ignored));
		return [$parsed, $errors, $count];
	}

	/**
	 * One cell of the Clients sheet as clients.csv column(s) => text, and what is wrong with it.
	 * $ca is the row's Jump CA, which turns Jump TLS = Yes into "ca".
	 */
	public static function read(array $c, string $v, array $lists, string $ca = ''): array {
		$e = $c['entry'];
		$kind = $e['kind'] ?? null;
		$col = $c['covers'][0];
		if ($kind === 'disks') {
			return self::readDisks($c, $v);
		}
		if ($kind === 'purchased') {
			// Both measures get the figure: the client form zeroes the one Purchased by does not name.
			return [[$col => $v, 'purchased_devices' => $v], null];
		}
		if ($kind === 'date') {
			if (is_numeric($v) && (float) $v >= 1 && (float) $v < 2958466) {
				$v = substr(Xlsx::fromSerial((float) $v), 0, 10);
			}
			return [[$col => $v], null];
		}
		if (!isset($e['choices'])) {
			return [[$col => $v], null];
		}
		if ($v === '') {
			return [[$col => ''], null];
		}
		$choices = self::choices($e, $lists);
		$lower = mb_strtolower($v);
		$found = null;
		foreach ($choices as $shown => $raw) {
			if (mb_strtolower((string) $shown) === $lower || ($raw !== '' && mb_strtolower((string) $raw) === $lower)) {
				$found = $raw;
				break;
			}
		}
		if ($found === null) {
			if ($choices === ['Yes' => '1', 'No' => '0'] || $kind === 'tls') {
				$yes = in_array($lower, ['yes', 'y', 'true', '1', 'on'], true);
				$no = in_array($lower, ['no', 'n', 'false', '0', 'off'], true);
				if ($yes || $no) {
					$found = $kind === 'tls' ? ($yes ? 'verify' : 'none') : ($yes ? '1' : '0');
				}
				elseif ($kind === 'tls' && in_array($lower, ['verify', 'ca', 'none'], true)) {
					$found = $lower;
				}
			}
			elseif ($e['choices'] === 'types') {
				$t = ClientTypes::normalize($v);
				if (in_array($t, array_unique(array_merge($lists['types'] ?? [], ClientTypes::ALL)), true)) {
					$found = $t;
				}
			}
			elseif ($e['choices'] === 'monitored' && in_array($lower, ['server', 'jump'], true)) {
				$found = $lower === 'jump' ? 'jump' : '';
			}
		}
		if ($found === null) {
			return [[$col => $v], _s('%1$s is "%2$s" — %3$s.', $e['label'], $v, self::pick(array_keys($choices)))
				.($e['choices'] === 'monitored' ? ' '._('Proxies are read from this Zabbix; add the proxy in Zabbix first.') : '')];
		}
		if ($kind === 'tls' && $found === 'verify' && $ca !== '') {
			$found = 'ca';
		}
		return [[$col => $found], null];
	}

	/** "Other disks": mount=GB; mount=GB → disk2 … disk5, mount and size. Empty clears all four. */
	private static function readDisks(array $c, string $v): array {
		$out = array_fill_keys($c['covers'], '');
		if ($v === '') {
			return [$out, null];
		}
		$items = preg_split('/\s*;\s*/', trim($v, " \t;"), -1, PREG_SPLIT_NO_EMPTY);
		$slots = intdiv(count($c['covers']), 2);
		if (count($items) > $slots) {
			return [$out, _s('%1$s lists %2$s disks; at most %3$s besides /.', $c['label'], count($items), $slots)];
		}
		foreach ($items as $k => $item) {
			if (!preg_match('#^(\S+)\s*=\s*(\d+(?:\.\d+)?)\s*(?:GB)?$#i', $item, $m)) {
				return [$out, _s('%1$s: "%2$s" is not mount=GB. Write it like /data=4000; /logs=500.', $c['label'], $item)];
			}
			$out[$c['covers'][2 * $k]] = $m[1];
			$out[$c['covers'][2 * $k + 1]] = $m[2];
		}
		return [$out, null];
	}

	private static function readServers(array $rows, array $roles): array {
		$errors = [];
		$header = $rows[1] ?? [];
		unset($rows[1]);
		$own = [];
		foreach (self::serverColumns() as $h => $field) {
			$own[self::key($h)] = $field;
		}
		// servers.csv's own names are read too.
		foreach (Csv::SERVER_COLUMNS as $col) {
			$own[$col] = $own[$col] ?? $col;
		}
		$own['host name'] = 'name';
		$map = [];
		foreach ($header as $n => $h) {
			$k = self::key($h);
			$map[$n] = $own[$k] ?? ['attr' => Csv::attrKey($h)];
		}
		$csvHeader = [];
		foreach ($map as $f) {
			$col = is_array($f) ? $f['attr'] : (preg_match('/^role\d$/', $f) ? 'roles' : $f);
			if (!in_array($col, $csvHeader, true) || !in_array($col, ['roles'], true)) {
				$csvHeader[] = $col;
			}
		}
		$hasRoles = in_array('roles', $csvHeader, true);
		$names = Csv::roleNames($roles);
		$out = [];
		$count = 0;
		foreach ($rows as $r => $cells) {
			if (implode('', $cells) === '') {
				continue;
			}
			$count++;
			$row = ['_line' => $r];
			$ids = [];
			$ip = '';
			$bad = false;
			foreach ($map as $n => $f) {
				$v = trim((string) ($cells[$n] ?? ''));
				if ($f === 'ip') {
					$ip = $v;
				}
			}
			foreach ($map as $n => $f) {
				$v = trim((string) ($cells[$n] ?? ''));
				if (is_array($f)) {
					$row[$f['attr']] = $v;
				}
				elseif ($f === 'roles' || preg_match('/^role(\d)$/', $f)) {
					$column = $f === 'roles' ? 'Roles' : 'Role '.substr($f, 4);
					foreach (preg_split('/\s*[;|+]\s*/', $v, -1, PREG_SPLIT_NO_EMPTY) as $name) {
						$id = $names[mb_strtolower($name)] ?? null;
						if ($id === null) {
							$bad = true;
							$errors[] = self::at(self::SERVERS, $r, $ip).': '._s('%1$s is "%2$s" — not one of the roles. Pick it from the dropdown, or leave it empty.', $column, $name);
						}
						elseif (in_array($id, $ids, true)) {
							$bad = true;
							$errors[] = self::at(self::SERVERS, $r, $ip).': '._s('%1$s repeats "%2$s", already given in this row.', $column, $name);
						}
						else {
							$ids[] = $id;
						}
					}
				}
				else {
					$row[$f] = $v;
				}
			}
			if ($ip === '') {
				$errors[] = self::at(self::SERVERS, $r, '').': '._('IP is empty — every server needs its IPv4 address.');
			}
			if ($hasRoles && !$ids && !$bad) {
				$errors[] = self::at(self::SERVERS, $r, $ip).': '._('Role 1 is empty — pick the server\'s role.');
			}
			$row['roles'] = implode(';', $ids);
			$out[] = $row;
		}
		$where = fn($line) => _s('%1$s, row %2$s', self::SERVERS, $line);
		$parsed = Csv::parseServerRows($csvHeader, $out, $roles, $where, _('the Servers sheet'));
		return [$parsed, $errors, $count];
	}
}
