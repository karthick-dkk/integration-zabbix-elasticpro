<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use API;
use Exception;

/**
 * Reads uploaded files against Zabbix: what is new, what would change and how, what is
 * unchanged, what is wrong — and what overlaps something else.
 *
 * clients.csv and servers.csv may come together or alone. Errors stop everything: nothing is
 * applied until every row is right. Overlaps are warnings — an IP another host already has, an
 * ES URL two clients share — and a new client with one waits for a tick instead of being added
 * straight away.
 */
class Importer {

	/** @var ClientSpec */
	private $spec;
	/** @var ClientState */
	private $state;
	/** @var array */
	private $roles;

	public function __construct(ClientSpec $spec, ClientState $state) {
		$this->spec = $spec;
		$this->state = $state;
		$this->roles = $spec->roles();
	}

	/**
	 * The plan for parsed files (Csv::parse / Csv::parseServers, or the workbook's sheets through
	 * Workbook::parse, which hands over the same structures; either may be null). Their 'file'
	 * names the source in messages.
	 */
	public function analyze(?array $clients, ?array $servers): array {
		$plan = ['rows' => [], 'errors' => [], 'ignored' => []];
		foreach ([$clients, $servers] as $p) {
			if ($p !== null) {
				$plan['errors'] = array_merge($plan['errors'], $p['errors']);
				$plan['ignored'] = array_merge($plan['ignored'], $p['ignored'] ?? []);
			}
		}
		if ($plan['errors']) {
			return $plan;
		}
		$existing = $this->state->clients();
		$rows = [];
		$lines = [];
		foreach ($clients['rows'] ?? [] as $row) {
			$name = trim((string) ($row['client'] ?? ''));
			$key = strtolower($name);
			if ($name !== '' && isset($rows[$key])) {
				$plan['errors'][] = _s('Client "%1$s" is in %4$s twice (lines %2$s and %3$s).', $name, $lines[$key], $row['_line'], $clients['file'] ?? 'clients.csv');
				continue;
			}
			$rows[$key] = ['name' => $name, 'row' => $row, 'servers' => null];
			$lines[$key] = $row['_line'];
		}
		foreach ($servers['clients'] ?? [] as $name => $list) {
			$key = strtolower($name);
			if (!isset($rows[$key]) && !isset($existing[$name])) {
				$plan['errors'][] = _s('%2$s lists servers for "%1$s", which is not a client yet. Add it to %3$s too.', $name, $servers['file'] ?? 'servers.csv',
					$clients['file'] ?? 'clients.csv');
				continue;
			}
			$rows[$key] = ($rows[$key] ?? ['name' => $name, 'row' => null]) + ['servers' => null];
			$rows[$key]['servers'] = $list;
		}

		$ipOwner = [];
		$nameOwner = [];
		foreach ($rows as $r) {
			$name = $r['name'];
			$exists = $name !== '' && isset($existing[$name]);
			if ($exists && Lifecycle::statusOf($existing[$name]['macros']) === 'decommissioned') {
				$plan['errors'][] = _s('%1$s is decommissioned: restore it in Cluster Management before importing changes to it.', $name);
				continue;
			}
			$current = $exists ? $this->state->formFor($name) : null;
			$before = $current !== null ? ClientState::plain($current) : null;
			// The host now at each IP: a Host name equal to a made-up one changes nothing, and an empty
			// one keeps a name of the host's own — the export fills in every name.
			$hostAt = [];
			foreach ($current['_now']['machines'] ?? [] as $h) {
				if ($h['_ip'] !== null) {
					$hostAt[$h['_ip']] = $h;
				}
			}
			foreach ($r['servers'] ?? [] as $k => $sv) {
				$h = $hostAt[trim((string) ($sv['ip'] ?? ''))] ?? null;
				$given = trim((string) ($sv['host'] ?? ''));
				if ($h !== null && $given === '' && !empty($h['_own_name'])) {
					$r['servers'][$k]['host'] = $h['host'];
				}
				elseif ($h !== null && $given === $h['host'] && empty($h['_own_name'])) {
					$r['servers'][$k]['host'] = '';
				}
			}
			$form = $before ?? array_merge($this->spec->defaults(), ['name' => $name]);
			if ($r['row'] !== null) {
				$form = Csv::toForm($r['row'], $form, $this->roles);
			}
			$errors = [];
			if ($r['servers'] !== null) {
				['servers' => $parsed, 'errors' => $serverErrors] = $this->spec->parseServers(json_encode(array_map(
					fn($s) => array_diff_key($s, ['_line' => 1]), $r['servers'])));
				$errors = array_merge($errors, array_map(fn($e) => ($servers['file'] ?? 'servers.csv').': '.$e, $serverErrors));
				$form['servers'] = ClientSpec::encodeServers($parsed);
			}
			['client' => $client, 'errors' => $formErrors] = $this->spec->fromForm($form);
			$errors = array_merge($errors, $formErrors);
			foreach ($client['servers'] as $ip => $_) {
				if (isset($ipOwner[$ip]) && $ipOwner[$ip] !== $name) {
					$errors[] = _s('%1$s is listed for both %2$s and %3$s.', $ip, $ipOwner[$ip], $name);
				}
				$ipOwner[$ip] = $name;
			}
			if (!$errors) {
				$errors = array_merge($errors, $this->hostNameClashes($name, $client, $hostAt, $nameOwner), $this->templatesMissing($name, $client));
			}
			$form['servers'] = $client['servers_json'];
			$diff = ($exists && !$errors) ? $this->diff($before, $client) : null;
			$plan['rows'][] = [
				'line' => $r['row']['_line'] ?? ($r['servers'][0]['_line'] ?? 0),
				'name' => $name,
				'status' => $errors ? 'error' : (!$exists ? 'new' : ($diff['fields'] || $diff['hosts'] ? 'update' : 'same')),
				'errors' => $errors,
				'warnings' => [],
				'diff' => $diff,
				'form' => $form,
				'type' => $client['fields']['type'] ?? ''
			];
		}

		foreach ($plan['rows'] as $row) {
			if ($row['errors']) {
				$plan['errors'][] = _s('%1$s: %2$s', $row['name'] !== '' ? $row['name'] : _s('line %1$s', $row['line']), implode(' ', $row['errors']));
			}
		}
		if (!$plan['errors']) {
			$this->overlaps($plan, $existing);
		}
		if ($plan['errors']) {
			foreach ($plan['rows'] as &$row) {
				if ($row['status'] !== 'error' && preg_grep('/^'.preg_quote($row['name'], '/').': .*one client only/', $plan['errors'])) {
					$row['status'] = 'error';
				}
			}
			unset($row);
		}
		return $plan;
	}

	/**
	 * Host names of the operator's own that another host in Zabbix already has, or that the file
	 * gives twice. Zabbix keeps host names (and visible names) unique; it would refuse in the middle
	 * of a save, so it is said here, at Check time.
	 */
	private function hostNameClashes(string $client, array $c, array $hostAt, array &$nameOwner): array {
		$want = [];
		foreach ($c['servers'] as $ip => $s) {
			if (($s['host'] ?? '') !== '' && ($hostAt[$ip]['host'] ?? null) !== $s['host']) {
				$want[$s['host']] = $ip;
			}
		}
		$out = [];
		foreach ($c['servers'] as $ip => $s) {
			if (($s['host'] ?? '') === '') {
				continue;
			}
			$key = strtolower($s['host']);
			if (isset($nameOwner[$key]) && $nameOwner[$key] !== $client) {
				$out[] = _s('host name "%1$s" (%2$s) is also given to a server of %3$s. Host names are unique in Zabbix.', $s['host'], $ip, $nameOwner[$key]);
			}
			$nameOwner[$key] = $client;
		}
		if (!$want) {
			return $out;
		}
		$found = [];
		foreach ([['host' => array_keys($want)], ['name' => array_keys($want)]] as $filter) {
			foreach (API::Host()->get(['output' => ['hostid', 'host', 'name'], 'filter' => $filter]) ?: [] as $h) {
				$found[$h['hostid']] = $h;
			}
		}
		foreach ($found as $h) {
			foreach ([$h['host'], $h['name']] as $taken) {
				$ip = $want[$taken] ?? null;
				if ($ip !== null && ($hostAt[$ip]['hostid'] ?? null) !== $h['hostid']) {
					$out[] = _s('host name "%1$s" for %2$s is already taken in Zabbix (by host "%3$s"). Host names are unique: give another, or leave it empty.', $taken, $ip, $h['host']);
					unset($want[$taken]);
				}
			}
		}
		return $out;
	}

	/** The templates this client would need and Zabbix does not have — so the operator learns at Check time, not at Apply. */
	private function templatesMissing(string $client, array $c): array {
		$missing = (new Reconciler($this->spec))->missingTemplates($c);
		if (!$missing) {
			return [];
		}
		// "DI needs …" when it is the log archive a DI client must have; "needs …" otherwise.
		$who = in_array(Reconciler::ulmTemplate(), $missing, true) && ($c['fields']['type'] ?? '') === 'DI' ? _('DI').' ' : '';
		$names = '"'.implode('", "', $missing).'"';
		$text = count($missing) === 1 ? _s('%1$sneeds the template %2$s, which is not in this Zabbix.', $who, $names)
			: _s('%1$sneeds the templates %2$s, which are not in this Zabbix.', $who, $names);
		return [trim($text.' '.implode(' ', Reconciler::missingTemplatesHints($missing)))];
	}

	/** What changes for an existing client: settings old → new, servers added, changed and removed. */
	public function diff(array $before, array $client): array {
		['client' => $old] = $this->spec->fromForm($before);
		$labels = $this->labels();
		$fields = [];
		foreach ($client['fields'] as $k => $v) {
			if ((string) ($old['fields'][$k] ?? '') !== (string) $v) {
				$fields[] = ['field' => $labels[$k] ?? $k, 'old' => $old['fields'][$k] ?? '', 'new' => $v];
			}
		}
		$now = $this->state->formFor($client['name'])['_now'];
		$byIp = [];
		foreach ($now['machines'] as $h) {
			if ($h['_ip'] !== null) {
				$byIp[$h['_ip']] = $h;
			}
		}
		$hosts = [];
		foreach ($client['servers'] as $ip => $s) {
			$was = $old['servers'][$ip] ?? null;
			if ($was === null) {
				$hosts[] = ['change' => isset($byIp[$ip]) ? 'role' : 'add', 'roles' => $this->roleLabels($s['roles']), 'ip' => $ip, 'host' => $byIp[$ip]['name'] ?? null,
					'as' => $s['host'] ?? ''];
			}
			elseif ($was['roles'] !== $s['roles']) {
				$hosts[] = ['change' => 'role', 'roles' => $this->roleLabels($s['roles']), 'was' => $this->roleLabels($was['roles']), 'ip' => $ip, 'host' => $byIp[$ip]['name'] ?? null];
			}
			elseif (($was['host'] ?? '') !== ($s['host'] ?? '') && ($s['host'] ?? '') !== '') {
				$hosts[] = ['change' => 'rename', 'roles' => $this->roleLabels($s['roles']), 'ip' => $ip, 'host' => $byIp[$ip]['name'] ?? null, 'as' => $s['host']];
			}
			elseif ($was['services'] !== $s['services'] || $was['notes'] !== $s['notes'] || array_diff_assoc($s['attrs'], $was['attrs'])) {
				$hosts[] = ['change' => 'detail', 'roles' => $this->roleLabels($s['roles']), 'ip' => $ip, 'host' => $byIp[$ip]['name'] ?? null];
			}
		}
		foreach ($old['servers'] as $ip => $s) {
			if (!isset($client['servers'][$ip]) && isset($byIp[$ip])) {
				$hosts[] = ['change' => Reconciler::isManaged($byIp[$ip]) ? 'delete' : 'leave', 'roles' => $this->roleLabels($s['roles']), 'ip' => $ip, 'host' => $byIp[$ip]['name']];
			}
		}
		return ['fields' => $fields, 'hosts' => $hosts];
	}

	private function roleLabels(array $ids): string {
		$byId = Roles::byId($this->roles);
		return implode(' + ', array_map(fn($id) => $byId[$id]['label'] ?? $id, $ids));
	}

	/** IPs another host already has, and ES URLs two clients share — in Zabbix or in the files. */
	private function overlaps(array &$plan, array $existing): void {
		$ips = [];
		foreach ($plan['rows'] as $row) {
			foreach ((array) json_decode($row['form']['servers'], true) as $s) {
				$ips[$s['ip']] = $row['name'];
			}
		}
		if ($ips) {
			$ifs = API::HostInterface()->get(['output' => ['hostid', 'ip'], 'filter' => ['ip' => array_keys($ips)]]);
			$hostids = array_unique(array_column($ifs, 'hostid'));
			$hosts = $hostids ? API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => $hostids,
				'selectHostGroups' => ['name'], 'preservekeys' => true]) : [];
			foreach ($ifs as $if) {
				$h = $hosts[$if['hostid']] ?? null;
				if ($h === null) {
					continue;
				}
				$owner = $ips[$if['ip']];
				$groups = array_column($h['hostgroups'], 'name');
				if (in_array($owner, $groups, true)) {
					continue;
				}
				$other = array_values(array_intersect($groups, array_keys($existing)));
				if ($other) {
					// An IP is one client's only: this stops the file, like any other error.
					$plan['errors'][] = _s('%1$s: %2$s is already "%3$s", a server of client %4$s. An IP belongs to one client only.', $owner, $if['ip'], $h['name'], $other[0]);
					continue;
				}
				$this->warn($plan, $owner, _s('%1$s is already "%2$s" in Zabbix, belonging to no client: it will be taken on, with its history.', $if['ip'], $h['name']));
			}
		}

		$urls = [];
		$inFile = array_column($plan['rows'], 'name');
		foreach ($existing as $name => $c) {
			if (!in_array($name, $inFile, true)) {
				$urls[$name] = $c['macros']['{$ES.URL}'] ?? '';
			}
		}
		foreach ($plan['rows'] as $row) {
			$urls[$row['name']] = $row['form']['es_url'] ?? '';
		}
		$seenUrl = [];
		foreach ($urls as $name => $url) {
			$key = rtrim(strtolower($url), '/');
			if ($key === '') {
				continue;
			}
			if (isset($seenUrl[$key])) {
				foreach ([$name, $seenUrl[$key]] as $who) {
					$this->warn($plan, $who, _s('%1$s and %2$s have the same ES URL (%3$s).', $seenUrl[$key], $name, $url));
				}
			}
			$seenUrl[$key] = $name;
		}
	}

	private function warn(array &$plan, string $name, string $text): void {
		foreach ($plan['rows'] as &$row) {
			if ($row['name'] === $name && !in_array($text, $row['warnings'], true)) {
				$row['warnings'][] = $text;
			}
		}
	}

	/**
	 * Apply planned rows, one client at a time: ['applied' => [['name', 'done', 'problems']], 'failed' =>
	 * [['name', 'error', 'done', 'exists']]]. A client whose apply threw is never counted as applied:
	 * `done` says what was written before the refusal and `exists` what of it is in Zabbix now.
	 * $newRec makes a Reconciler per client; $applied($name) is called for each client applied.
	 */
	public function applyRows(array $rows, callable $newRec, callable $applied): array {
		$out = ['applied' => [], 'failed' => []];
		foreach ($rows as $row) {
			$rec = $newRec();
			['client' => $client] = $this->spec->fromForm($row['form']);
			try {
				$rec->apply($client);
			}
			catch (Exception $e) {
				$out['failed'][] = ['name' => $client['name'], 'error' => $e->getMessage(), 'done' => $rec->done(), 'exists' => $this->existsNow($client['name'])];
				continue;
			}
			$problems = [];
			try {
				$applied($client['name']);
			}
			catch (Exception $e) {
				$problems[] = $e->getMessage();
			}
			$out['applied'][] = ['name' => $client['name'], 'done' => $rec->done(), 'problems' => array_merge($problems, $this->verify($client))];
		}
		return $out;
	}

	/** The hosts a client has in Zabbix now, by name: what a failed apply left behind. */
	public function existsNow(string $client): array {
		try {
			$now = (new Reconciler($this->spec))->current($client);
		}
		catch (Exception $e) {
			return [];
		}
		$out = [];
		foreach (array_merge(array_filter([$now['master'], $now['cluster'], $now['ulm']]), array_values($now['machines'])) as $h) {
			$out[] = $h['name'] ?? $h['host'];
		}
		return $out;
	}

	/** After applying: the client read back from Zabbix, compared with what was asked. Empty when they match. */
	public function verify(array $client): array {
		$form = ClientState::plain($this->state->formFor($client['name']));
		['client' => $now] = $this->spec->fromForm($form);
		$labels = $this->labels();
		$out = [];
		foreach ($client['fields'] as $k => $v) {
			if ((string) ($now['fields'][$k] ?? '') !== (string) $v) {
				$out[] = _s('%1$s: asked "%2$s", Zabbix has "%3$s".', $labels[$k] ?? $k, $v, $now['fields'][$k] ?? '');
			}
		}
		foreach ($client['servers'] as $ip => $s) {
			$have = $now['servers'][$ip] ?? null;
			if ($have === null) {
				$out[] = _s('%1$s: asked for, not in Zabbix.', $ip);
			}
			elseif ($have['roles'] !== $s['roles']) {
				$out[] = _s('%1$s: asked %2$s, Zabbix has %3$s.', $ip, $this->roleLabels($s['roles']), $this->roleLabels($have['roles']));
			}
			elseif (($s['host'] ?? '') !== '' && ($have['host'] ?? '') !== $s['host']) {
				$out[] = _s('%1$s: asked for the host name "%2$s", Zabbix has "%3$s".', $ip, $s['host'], $have['host'] ?? '');
			}
			// Only the attributes asked for: other inv: tags are Host Inventory's.
			elseif ($have['services'] !== $s['services'] || array_diff_assoc(array_filter($s['attrs'], 'strlen'), $have['attrs'])) {
				$out[] = _s('%1$s: services or attributes differ from what was asked.', $ip);
			}
			elseif ($have['notes'] !== $s['notes']) {
				$out[] = _s('%1$s: notes were not kept (the host keeps no inventory).', $ip);
			}
		}
		foreach ($now['servers'] as $ip => $_) {
			if (!isset($client['servers'][$ip])) {
				$out[] = _s('%1$s: in Zabbix, not asked for.', $ip);
			}
		}
		return $out;
	}

	/** Field => words, for changes and checks. */
	public function labels(): array {
		$out = ['type' => _('Type'), 'es_url' => _('ES URL'), 'es_user' => _('ES user'), 'es_password_mode' => _('ES password kept in'),
			'es_password_path' => _('ES password Vault path'), 'monitored_by' => _('Monitored by'), 'ulm_bucket' => _('S3 bucket'),
			'ulm_region' => _('S3 region'), 'ulm_auth' => _('S3 access'), 'ulm_role_arn' => _('Role ARN'), 'ulm_external_id' => _('External ID'),
			'ulm_access_key_id' => _('Access key ID'), 'ulm_secret_path' => _('S3 secret Vault path'), 'ulm_raw_prefix' => _('Raw folder'),
			'ulm_enriched_prefix' => _('Enriched folder'), 'agent_port' => _('Agent port'), 'purchased_by' => _('Purchased by'),
			'purchased' => _('Purchased storage (GB)'), 'purchased_devices' => _('Purchased devices')];
		foreach (Roles::allRoles($this->roles) as $r) {
			$out[$r['id'].'_servers'] = $r['label'].' '._('servers requested');
			$out[$r['id'].'_cpu'] = $r['label'].' '._('CPU requested');
			$out[$r['id'].'_mem'] = $r['label'].' '._('memory requested (GB)');
			$out[$r['id'].'_disk'] = $r['label'].' '._('disk / requested (GB)');
			for ($n = 2; $n <= Roles::DISK_SLOTS; $n++) {
				$out[$r['id'].'_disk'.$n.'_fs'] = _s('%1$s disk %2$s mount', $r['label'], $n);
				$out[$r['id'].'_disk'.$n] = _s('%1$s disk %2$s requested (GB)', $r['label'], $n);
			}
		}
		return $out;
	}
}
