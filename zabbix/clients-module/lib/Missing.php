<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

/**
 * The "Missing" panel: everything about a cluster that could not be determined or is not set up,
 * said once, in one list — on a Discover proposal before it is adopted, and on the client's page
 * afterwards. Nothing here guesses: an item is either a fact read from Zabbix (or this module's
 * own record of who handed over a password) or it is not listed.
 *
 * Each item is ['key' => a stable id, 'text' => what is missing, 'fix' => what to do]. The keys
 * are what the tests and the page's styling use; the text is what a person reads.
 *
 * Pure: the callers read Zabbix and pass what they found.
 */
final class Missing {

	/** The cluster host macros a cluster cannot be reached without. */
	public const CLUSTER_MACROS = ['{$ELASTICSEARCH.HOST}', '{$ELASTICSEARCH.PORT}', '{$ELASTICSEARCH.SCHEME}'];

	/** The jump host macros the SSH template needs. */
	public const JUMP_MACROS = ['{$WJ.HOST}', '{$WJ.USER}', '{$WJ.KEY}'];

	private static function item(string $key, string $text, string $fix): array {
		return ['key' => $key, 'text' => $text, 'fix' => $fix];
	}

	/**
	 * For a Discover proposal: the cluster row and its attached hosts as Discovery described
	 * them, whether a host group of the client's name exists, and the plan for the default choices.
	 */
	public static function forProposal(array $p, bool $groupExists, array $plan): array {
		$cluster = $p['hosts'][0] ?? null;
		if ($cluster === null || $cluster['kind'] !== 'cluster') {
			return [];
		}
		$out = [];
		if (!$groupExists) {
			$out[] = self::item('client_group', _s('No client group: there is no host group "%1$s".', $p['name']),
				_('Adopting creates it and puts the cluster host and its role hosts in it.'));
		}
		elseif (!in_array($p['name'], $cluster['groups'], true)) {
			$out[] = self::item('client_group', _s('The cluster host is not in host group "%1$s".', $p['name']), _('Adopting puts it there.'));
		}
		$servers = array_slice($p['hosts'], 1);
		// Both proxy choices start at the Zabbix server: a host monitored by a proxy today is said,
		// with what adopting would do to it unless that proxy is chosen.
		foreach ($p['hosts'] as $h) {
			if (($h['monitored_by'] ?? '') !== '' && ($h['already'] ?? null) === null && ($h['jump'] ?? false) === false) {
				$which = $h['kind'] === 'cluster' ? _('ES hosts monitored by') : _('Linux hosts monitored by');
				$out[] = self::item('proxy', _s('%1$s is monitored by %2$s; adoption would move it to the Zabbix server unless you choose that proxy.', $h['host'], $h['proxy_label']),
					_s('Choose it under "%1$s" in the summary.', $which));
			}
		}
		if (!$servers) {
			$out[] = self::item('role_hosts', _('No role hosts found: no host in a role group, with a role template or an ep-role tag points at this cluster.'),
				_('Add its servers on the client\'s page after adopting, or put them in the client\'s host group and rescan.'));
		}
		foreach ($servers as $s) {
			if (!$s['roles']) {
				$out[] = self::item('role', _s('Role unknown for "%1$s".', $s['host']), _('Pick its role in the table, or it is not adopted.'));
			}
			if (($s['role_source'] ?? null) === 'kafka') {
				$out[] = self::item('kafka', _s('"%1$s" looks like a Kafka server: Kafka is not a role.', $s['host']), _('Confirm Forwarder or Parser in its role dropdown, and tick it.'));
			}
			if (($s['server_name'] ?? '') === '') {
				$out[] = self::item('server_name', _s('Server name unknown for %1$s (host name %2$s).', $s['ip'] ?? $s['host'], $s['host']),
					_('Its visible name is left as it is. Give the server name in Import (Servers sheet) or on the client\'s page.'));
			}
			if ($s['ip'] === null) {
				$out[] = self::item('ip', _s('"%1$s" has no agent interface with an IP.', $s['host']), _('Give it one in Data collection → Hosts; servers are kept by IP.'));
			}
			elseif ($s['shared_ip']) {
				$out[] = self::item('ip', _s('"%1$s" shares %2$s with %3$s.', $s['host'], $s['ip'], implode(', ', $s['shared_ip'])),
					_('The module keeps one host per IP: adopt one of them.'));
			}
		}
		$out = array_merge($out, self::cluster($cluster['es'] ?? [], (bool) ($cluster['jump'] ?? false), $cluster['macros'] ?? [],
			array_merge(($cluster['generation'] ?? null) === 'legacy' ? array_values(array_intersect($cluster['templates'], Reconciler::esClusterTemplates())) : [],
				$cluster['es_templates'] ?? []), $cluster['proxy_label'] ?? '', null));
		foreach ($plan['moves'] ?? [] as $m) {
			if (!empty($m['refused']) && preg_grep('/not in Zabbix/', $m['refused'])) {
				$out[] = self::item('templates', _('Templates you cannot see, or not imported: ').implode('; ', preg_grep('/not in Zabbix/', $m['refused'])),
					_('Import them, or ask for read permission on their template group.'));
				break;
			}
		}
		return self::unique($out);
	}

	/**
	 * For a client's page: Reconciler::current(), the cluster host's macros (name => value; secret
	 * values are never read), templates the client needs that the user cannot see or Zabbix does
	 * not have, and ClusterSecret::of() — who handed the password over, when.
	 */
	public static function forClient(array $now, array $clusterMacros, array $missingTemplates, ?array $secret, bool $jump, array $credential = []): array {
		$out = [];
		if ($now['groupid'] === null) {
			$out[] = self::item('client_group', _('No client group in Zabbix.'), _('Saving the client creates it.'));
		}
		$cluster = $now['cluster'] ?? null;
		if ($cluster === null) {
			$out[] = self::item('cluster', _('No cluster host: no host in the client\'s group carries the Elasticsearch cluster template.'),
				_('Give the client its ES URL and save, or put the cluster host in the client\'s group.'));
		}
		else {
			$types = array_column($cluster['macros'] ?? [], 'type', 'macro');
			$es = [
				'url' => ClientState::urlFrom($clusterMacros),
				'password' => !isset($types[Reconciler::ES_PASSWORD_MACRO]) ? 'none'
					: ([ZBX_MACRO_TYPE_SECRET => 'secret', ZBX_MACRO_TYPE_VAULT => 'vault'][(int) $types[Reconciler::ES_PASSWORD_MACRO]] ?? 'text'),
				'source' => $credential['source'] ?? null,
				'hidden_by' => $credential['hidden_by'] ?? []
			];
			$templates = array_column($cluster['parentTemplates'] ?? [], 'host');
			$current = array_merge([Roles::clusterTemplate(), Roles::templateName('jump')]);
			$old = array_values(array_diff(array_intersect($templates, Reconciler::clusterHostTemplates()), $current));
			$out = array_merge($out, self::cluster($es, $jump, array_keys($clusterMacros + $types), $old, '', $secret, $clusterMacros, $credential['default_record'] ?? null));
			$own = $jump ? Reconciler::jumpClusterTemplates() : Reconciler::clusterTemplates();
			$notLinked = array_values(array_diff($own, $templates));
			if ($templates && $notLinked) {
				$out[] = self::item('linked', _s('Not linked to the cluster host: %1$s.', implode(', ', $notLinked)), _('Saving the client links them.'));
			}
		}
		if (!$now['machines']) {
			$out[] = self::item('role_hosts', _('No role hosts: the client has no server in Zabbix.'), _('Add its servers in the Servers card.'));
		}
		foreach ($now['machines'] ?? [] as $h) {
			if (($h['_confirm'] ?? '') === 'kafka') {
				$out[] = self::item('role_confirm', _s('Role to confirm: %1$s · %2$s is a Kafka server, placed under Forwarder.', $h['_ip'] ?? $h['host'], ($h['_server_name'] ?? '') !== '' ? $h['_server_name'] : $h['name']),
					_('Confirm Forwarder or Parser in Servers & roles; until then it counts as a Forwarder.'));
			}
			if (($h['_roles'] ?? []) && ($h['_server_name'] ?? '') === '') {
				$out[] = self::item('server_name', _s('Server name unknown for %1$s (host name %2$s).', $h['_ip'] ?? $h['host'], $h['host']),
					_('Give it in the Servers card or the Servers sheet of Import; it becomes the visible name. The host name stays.'));
			}
		}
		foreach ($now['unassigned'] ?? [] as $h) {
			$out[] = self::item('role', _s('"%1$s" is in the client\'s group but has no role.', $h['name']), _('Give its IP a role in the Servers card.'));
		}
		if ($missingTemplates) {
			$out[] = self::item('templates', _s('Templates you cannot see, or not imported: %1$s.', implode(', ', $missingTemplates)),
				_('Import them, or ask for read permission on their template group.'));
		}
		return self::unique($out);
	}

	/**
	 * What the cluster host itself lacks: macros, a password ElasticPro can use, the jump host's
	 * settings, and an older template generation still linked.
	 *
	 * @param array      $es      ['url', 'password' => none|secret|vault|text (the host's own), 'source' => override|template|default|none, 'hidden_by']
	 * @param string[]   $macros  macro names the host carries
	 * @param string[]   $old     older-generation cluster templates still linked
	 * @param array|null $secret  ClusterSecret::of(), or null when not known here (Discover)
	 * @param array      $values  macro => value, for telling an empty macro from a missing one
	 */
	private static function cluster(array $es, bool $jump, array $macros, array $old, string $proxyLabel, ?array $secret, array $values = [], ?array $defaultRecord = null): array {
		$out = [];
		$defaultRecord = $defaultRecord ?? ($es['default_record'] ?? null);
		$unset = array_values(array_filter(self::CLUSTER_MACROS, fn($m) => !in_array($m, $macros, true) || (array_key_exists($m, $values) && trim((string) $values[$m]) === '')));
		if ($unset) {
			$out[] = self::item('macros', _s('Macros not set on the cluster host: %1$s.', implode(', ', $unset)),
				($es['url'] ?? '') === '' ? _('Type the ES URL; saving writes them.') : _('Saving the client writes them from its ES URL and user.'));
		}
		// Where the credential comes from (DefaultCredential::sourceOf): the default, this cluster's
		// own, a template hiding the default, or nowhere.
		$source = $es['source'] ?? (($es['password'] ?? 'none') === 'none' ? 'none' : 'override');
		if ($source === 'none') {
			$out[] = self::item('credential', _('No credential: neither the default Elasticsearch credential nor this cluster\'s own is set.'),
				_('Set the default on ElasticPro → Settings, or this cluster\'s own with "Change password" on the client\'s page.'));
		}
		elseif ($source === 'template') {
			$out[] = self::item('credential_hidden', _s('A linked template defines {$ELASTICSEARCH.PASSWORD} and hides the default credential: %1$s.', implode(', ', $es['hidden_by'] ?? [])),
				_('Give this cluster its own password, or remove that macro from the template.'));
		}
		elseif ($source === 'default') {
			if ($defaultRecord !== null && empty($defaultRecord['core'])) {
				$out[] = self::item('password_core', _('ElasticPro has not been given the default password, so it cannot poll the clusters that use it.'),
					_('On ElasticPro → Settings, press "Change password" in Default Elasticsearch credential and type it once.'));
			}
		}
		else {
			switch ($es['password'] ?? 'none') {
				case 'secret':
					if ($secret === null || empty($secret['core'])) {
						$out[] = self::item('password_core', _('The password is a Zabbix secret, which nothing can read back — ElasticPro has not been given it, so it cannot poll this cluster.'),
							_('Press "Change password" on the client\'s page and type it once: it goes to Zabbix and to ElasticPro.'));
					}
					break;
				case 'text':
					$out[] = self::item('password_plain', _('The password is a plain text macro that anyone who can read the host can read.'),
						_('Press "Change password" to keep it as a Zabbix secret, or choose a Vault path.'));
					break;
			}
		}
		if ($jump) {
			$gone = array_values(array_filter(self::JUMP_MACROS, fn($m) => !in_array($m, $macros, true)));
			if ($gone) {
				$out[] = self::item('jump', _s('Jump host settings not set: %1$s.', implode(', ', $gone)), _('Fill in the jump host on the client\'s page.'));
			}
		}
		elseif ($proxyLabel === '' || $proxyLabel === _('Zabbix server')) {
			// Asked by the Zabbix server directly, no proxy and no jump host: fine if it is reachable
			// so; if it is reachable only through a Windows jump host, nothing here can know which.
			$out[] = self::item('jump', _('Jump host unknown: monitored by the Zabbix server for now.'),
				_('If this cluster is reachable only through a Windows jump host, say which (Connection › Monitored by).'));
		}
		if ($old) {
			$out[] = self::item('old_generation', _s('An older template generation is still linked: %1$s.', implode(', ', $old)),
				_('It stays beside the ElasticPro templates, items and history as they are. tools/migrate-templates.py moves it when you choose (docs/MIGRATION.md).'));
		}
		return $out;
	}

	private static function unique(array $items): array {
		$seen = [];
		return array_values(array_filter($items, function ($i) use (&$seen) {
			$k = $i['key']."\0".$i['text'];
			if (isset($seen[$k])) {
				return false;
			}
			return $seen[$k] = true;
		}));
	}
}
