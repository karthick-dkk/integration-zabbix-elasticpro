<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use API;
use Exception;

/**
 * Discover existing hosts: find the Elasticsearch clusters and their role hosts Zabbix already
 * holds, propose each as a client, and — after an admin ticks and confirms — adopt them through
 * the same Reconciler path every save takes, so nobody has to make host groups by hand.
 *
 * A cluster is a host in a host group whose name contains "elasticsearch" (any case), and its
 * Zabbix host name is the cluster's — and so the client's — name. One proposal per cluster.
 *
 * Role hosts (in a role or family group, carrying a template only one role names, or tagged with
 * the module's ep-role) are attached to a cluster by hints, strongest first:
 *   1. group   — the host group of the client this page already counts the cluster in, or a host
 *                group that cluster alone is in;
 *   2. macro   — the host's {$GRP.CLIENT} names the cluster, its client, or its own {$GRP.CLIENT};
 *   3. tag     — a client: tag (or the module's ep-client, either generation) doing the same;
 *   4. prefix  — its name follows <client>-<Role>-<n> with the cluster's <client>;
 * The first hint that points at exactly one cluster wins; one that points at two is said, and the
 * host is left out. A role host no hint attaches is listed as unattached, with why.
 *
 * Nothing is guessed silently. A role comes from a role group, a template only that role names,
 * or the module's own ep-role tag, and the proposal says which; a host with none says "role
 * unknown" and is not ticked until a role is picked. Everything else the scan cannot tell — no
 * client group, no role hosts, no password, no jump host, an old template generation, macros not
 * set, templates the user cannot see — is in the proposal's Missing list (Missing).
 *
 * plan() turns a proposal and the admin's choices into the client form Reconciler::apply() takes,
 * the refusals Reconciler::problems() and WriteCheck would raise, and the write list shown before
 * anything happens — naming every template that will be linked. apply() carries one proposal
 * out: template moves first (TemplateMove), the cluster host into the client's group, then
 * Reconciler::apply(), which links the cluster template, client plan, alerts, log delay and
 * cluster devices directly to the cluster host in one host.massadd and makes <client>-Master.
 * Hosts are adopted, never recreated; nothing is deleted or unlinked with clear; no host is
 * renamed — a role host keeps its host name (the IP, or whatever it has) and its visible name is
 * its server name, which the scan reads but never invents; an older cluster template stays
 * linked beside.
 */
final class Discovery {

	/** A proxy choice that means "as the hosts are today". */
	public const KEEP = '__keep__';

	/** The hints that attach a role host to a cluster, strongest first. */
	public const SOURCES = ['group', 'macro', 'tag', 'prefix'];

	/** What a host group's name contains for its hosts to be clusters, compared without case. */
	public const CLUSTER_GROUP_MATCH = 'elasticsearch';

	/** Macros whose values the scan reads — none of them secret. Every other macro is read by name only. */
	private const VALUE_MACROS = ['{$GRP.CLIENT}', '{$ELASTICSEARCH.SCHEME}', '{$ELASTICSEARCH.HOST}', '{$ELASTICSEARCH.PORT}',
		'{$ELASTICSEARCH.USERNAME}', '{$ES.VOLUME.CUS.PURCHASED}', '{$WJ.HOST}', '{$WJ.PORT}', '{$WJ.USER}', '{$WJ.KEY}'];

	/** @var ClientSpec */
	private $spec;
	/** @var Reconciler */
	private $rec;
	/** @var array */
	private $roles;
	/** @var array templates.targets() by template set, for one request */
	private $targets = [];

	public function __construct(ClientSpec $spec, Reconciler $rec) {
		$this->spec = $spec;
		$this->rec = $rec;
		$this->roles = $spec->roles();
	}

	/* ------------------------------------ the scan (reads only) ------------------------------------ */

	/**
	 * Every proposal, with what each could not determine. Read-only: it asks Zabbix get methods
	 * only, as the signed-in user, so a user who cannot see a host does not see it proposed.
	 */
	public function scan(?callable $refusals = null): array {
		$ctx = $this->context();
		$rows = $this->rows($ctx);
		['proposals' => $proposals, 'unattached' => $unattached, 'stray' => $stray] = self::proposals($rows, self::nameWords($this->roles), Roles::groups($this->roles));
		$groups = $this->existingGroups(array_column($proposals, 'name'));
		foreach ($proposals as &$p) {
			$p['exists'] = isset($ctx['clients'][$p['name']]);
			$p['status'] = self::status($p);
			$p['choices'] = $this->defaults($p);
			$p['plan'] = $p['status'] === 'client' ? self::emptyPlan() : $this->plan($p, $p['choices'], $refusals);
			$p['missing'] = Missing::forProposal($p, isset($groups[$p['name']]), $p['plan']);
			// Ticked only when there is something to do and nothing stops it.
			$p['choices']['selected'] = $p['status'] === 'new' && !$p['plan']['blockers'] && $p['plan']['adopting'] > 0;
			$p['plan'] = self::compact($p['plan']);
		}
		unset($p);
		$notes = $ctx['notes'];
		if ($stray) {
			$notes[] = _s('Not proposed — they carry an Elasticsearch template but are in no host group whose name contains "%1$s": %2$s. Put a cluster host in such a group to have it proposed.',
				self::CLUSTER_GROUP_MATCH, implode(', ', array_column($stray, 'host')));
		}
		return ['at' => time(), 'proposals' => array_values($proposals), 'unattached' => $unattached, 'notes' => $notes,
			'proxies' => array_column(API::Proxy()->get(['output' => ['name'], 'sortfield' => 'name']) ?: [], 'name'),
			'proxy_groups' => array_column(API::ProxyGroup()->get(['output' => ['name'], 'sortfield' => 'name']) ?: [], 'name')];
	}

	/** The plan without what only the apply needs, for the session cache and the page. */
	public static function compact(array $plan): array {
		unset($plan['now']);
		if (is_array($plan['client'])) {
			$plan['client']['es_password'] = '';
		}
		foreach ($plan['moves'] as &$m) {
			$m['summary'] = TemplateMove::summary($m, $m['names'] ?? []);
			unset($m['items']);
		}
		return $plan;
	}

	/** Which of these host group names exist, as name => true. */
	private function existingGroups(array $names): array {
		$names = array_values(array_filter(array_map('strval', $names), fn($n) => $n !== ''));
		return $names ? array_fill_keys(array_column(API::HostGroup()->get(['output' => ['name'], 'filter' => ['name' => $names]]) ?: [], 'name'), true) : [];
	}

	/** Whether a host group's name makes its hosts clusters. */
	/**
	 * Hosts carrying the Elasticsearch health check every generation of the cluster template has
	 * (SetupChecks::ES_HEALTH_KEY, or the jump route's), and the template that gives it them —
	 * which finds a cluster template under a name no setting here knows without naming it.
	 * One lookup for Discover, the list, the client page and Missing.
	 *
	 * @param string[]|null $hostids  only these hosts; null for every host
	 * @return array{hosts: array<string, true>, templates: array<string, string>}  hostid => true; hostid => template name
	 */
	public static function signatureTemplates(?array $hostids = null): array {
		$out = ['hosts' => [], 'templates' => []];
		if ($hostids === []) {
			return $out;
		}
		$signature = [];
		foreach (API::Item()->get(['output' => ['itemid', 'hostid', 'templateid'], 'filter' => ['key_' => [SetupChecks::ES_HEALTH_KEY, JumpTemplate::FAST]],
				'templated' => false] + ($hostids !== null ? ['hostids' => array_values($hostids)] : [])) ?: [] as $it) {
			$out['hosts'][(string) $it['hostid']] = true;
			$signature[(string) $it['hostid']] = (string) $it['templateid'];
		}
		$parents = array_values(array_filter(array_unique(array_values($signature)), fn($x) => $x !== '0'));
		if ($parents) {
			$tplOf = array_column(API::Item()->get(['output' => ['itemid', 'hostid'], 'itemids' => $parents, 'templated' => true]) ?: [], 'hostid', 'itemid');
			$names = $tplOf ? array_column(API::Template()->get(['output' => ['templateid', 'host'], 'templateids' => array_values(array_unique($tplOf))]) ?: [], 'host', 'templateid') : [];
			foreach ($signature as $hostid => $parent) {
				if (isset($tplOf[$parent], $names[$tplOf[$parent]])) {
					$out['templates'][$hostid] = $names[$tplOf[$parent]];
				}
			}
		}
		return $out;
	}

	public static function isClusterGroup(string $name): bool {
		return stripos($name, self::CLUSTER_GROUP_MATCH) !== false;
	}

	/** What the scan knows about Zabbix before it looks at a host: clients, their groups and hosts. */
	private function context(): array {
		$state = new ClientState($this->spec, $this->rec);
		$clients = $state->clients();
		$owned = [];
		$unroled = [];
		foreach (array_keys($clients) as $name) {
			$now = $this->rec->current((string) $name);
			foreach (array_filter([$now['master'], $now['cluster'], $now['ulm']]) as $h) {
				$owned[(string) $h['hostid']] = (string) $name;
			}
			foreach ($now['machines'] as $h) {
				if ($h['_roles']) {
					$owned[(string) $h['hostid']] = (string) $name;
				}
				else {
					$unroled[(string) $h['hostid']] = (string) $name;
				}
			}
		}
		$notes = [];
		$masters = array_map('strval', array_column(Reconciler::masterHosts(['output' => ['hostid']]), 'hostid'));
		if (!API::Template()->get(['output' => ['templateid'], 'limit' => 1])) {
			$notes[] = _('Your Zabbix user can see no templates, so hosts are recognised by their groups and tags alone: a cluster host is found only through its ep-kind tag, and no template generation can be told.');
		}
		return ['default' => DefaultCredential::current(), 'default_record' => ClusterSecret::of(DefaultCredential::KEY),
			'clients' => $clients, 'client_groups' => $this->rec->clientGroups(), 'owned' => $owned, 'unroled' => $unroled,
			'masters' => $masters, 'notes' => $notes];
	}

	/**
	 * Template name => role id, for templates exactly one role names and that are not the
	 * default every role falls back to. A template two roles share says nothing about which.
	 */
	public static function roleTemplateMap(array $roles): array {
		$count = [];
		foreach (Roles::allRoles($roles) as $r) {
			foreach (array_unique(array_filter(array_map('trim', (array) ($r['templates'] ?? [])))) as $t) {
				$count[$t][] = $r['id'];
			}
		}
		$out = [];
		foreach ($count as $t => $ids) {
			if (count($ids) === 1 && $t !== Roles::defaultTemplate()) {
				$out[$t] = $ids[0];
			}
		}
		return $out;
	}

	/** The words of role and family names, for telling <client>-<Role>-<n> apart. */
	public static function nameWords(array $roles): array {
		$w = ['Cluster', 'Master', 'ULM', 'Single', 'Node'];
		foreach ($roles['families'] as $f) {
			$w = array_merge($w, preg_split('/[\s\-]+/', (string) $f['label']), preg_split('/[\s\-]+/', (string) $f['group']));
			foreach ($f['roles'] as $r) {
				$w = array_merge($w, explode('-', (string) $r['short']), preg_split('/[\s\-]+/', (string) $r['label']), preg_split('/[\s\-]+/', (string) $r['group']));
			}
		}
		return array_values(array_unique(array_map('strtolower', array_filter($w, fn($x) => $x !== ''))));
	}

	/**
	 * The client a host name points to when it follows the module's pattern: <client>-ES-Cluster,
	 * <client>-Master, or <client>-<role words>-<n>. The shortest tail of role words wins, so a
	 * client whose own name holds a role word keeps it. Null when the name does not follow it.
	 */
	public static function clientFromName(string $host, array $words): ?string {
		if (preg_match('/^(.+)-ES-Cluster$/', $host, $m)) {
			return $m[1];
		}
		$parts = explode('-', $host);
		$n = count($parts);
		if ($n < 3 || !ctype_digit($parts[$n - 1])) {
			return null;
		}
		$set = array_flip($words);
		for ($i = 1; $i <= $n - 2; $i++) {
			$tail = array_slice($parts, $i, $n - 1 - $i);
			if ($tail && !array_filter($tail, fn($t) => !isset($set[strtolower($t)]))) {
				return implode('-', array_slice($parts, 0, $i));
			}
		}
		return null;
	}

	/** Every host worth proposing, described. */
	private function rows(array $ctx): array {
		$roleGroups = Roles::groups($this->roles);
		$roleTpl = self::roleTemplateMap($this->roles);
		$clusterNames = Reconciler::clusterHostTemplates();
		$tpl = API::Template()->get(['output' => ['templateid', 'host'], 'filter' => ['host' => array_values(array_unique(array_merge($clusterNames, array_keys($roleTpl))))]]) ?: [];
		$ids = [];
		if ($tpl) {
			foreach (API::Host()->get(['output' => ['hostid'], 'templateids' => array_column($tpl, 'templateid')]) ?: [] as $h) {
				$ids[(string) $h['hostid']] = true;
			}
		}
		// The clusters: every host in a host group whose name contains "elasticsearch".
		$esGroups = array_values(array_filter(API::HostGroup()->get(['output' => ['groupid', 'name'], 'search' => ['name' => self::CLUSTER_GROUP_MATCH]]) ?: [],
			fn($g) => self::isClusterGroup((string) $g['name'])));
		if ($esGroups) {
			foreach (API::Host()->get(['output' => ['hostid'], 'groupids' => array_column($esGroups, 'groupid')]) ?: [] as $h) {
				$ids[(string) $h['hostid']] = true;
			}
		}
		// Kafka servers: not a role, but a forwarder or a parser — proposed so the admin can say which.
		$kafkaGroups = API::HostGroup()->get(['output' => ['groupid'], 'search' => ['name' => 'kafka']]) ?: [];
		$kafkaTpl = API::Template()->get(['output' => ['templateid'], 'search' => ['host' => 'kafka']]) ?: [];
		foreach (array_merge($kafkaGroups ? (API::Host()->get(['output' => ['hostid'], 'groupids' => array_column($kafkaGroups, 'groupid')]) ?: []) : [],
				$kafkaTpl ? (API::Host()->get(['output' => ['hostid'], 'templateids' => array_column($kafkaTpl, 'templateid')]) ?: []) : []) as $h) {
			$ids[(string) $h['hostid']] = true;
		}
		$groups = API::HostGroup()->get(['output' => ['groupid', 'name'], 'filter' => ['name' => $roleGroups]]) ?: [];
		if ($groups) {
			foreach (API::Host()->get(['output' => ['hostid'], 'groupids' => array_column($groups, 'groupid')]) ?: [] as $h) {
				$ids[(string) $h['hostid']] = true;
			}
		}
		// A cluster host whose template has a name no setting here knows: found by the health check
		// every generation of the Elasticsearch template carries, and the template that gives it.
		['hosts' => $carriers, 'templates' => $fromTemplate] = self::signatureTemplates();
		$ids += $carriers;
		foreach ($ctx['masters'] as $id) {
			unset($ids[$id]);
		}
		if (!$ids) {
			return [];
		}
		$hosts = API::Host()->get(['output' => ['hostid', 'host', 'name', 'status', 'monitored_by', 'proxyid', 'proxy_groupid'],
			'hostids' => array_keys($ids), 'selectHostGroups' => ['groupid', 'name'], 'selectParentTemplates' => ['templateid', 'host'],
			'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'type', 'main'], 'selectTags' => ['tag', 'value'],
			'selectMacros' => ['macro', 'type'], 'preservekeys' => true]) ?: [];
		$values = [];
		foreach (API::UserMacro()->get(['output' => ['hostid', 'macro', 'value', 'type'], 'hostids' => array_keys($hosts),
				'filter' => ['macro' => array_merge(self::VALUE_MACROS, self::requestedMacros($this->roles), ['{$ELASTICSEARCH.PASSWORD}', '{$ES.APIKEY}'])]]) ?: [] as $m) {
			// A value is kept only for a text macro on the list above, or a Vault reference (a path,
			// not a secret). A password typed into a text macro is not read: its type is enough.
			$keep = ((int) $m['type'] === ZBX_MACRO_TYPE_TEXT && $m['macro'] !== '{$ELASTICSEARCH.PASSWORD}' && $m['macro'] !== '{$ES.APIKEY}')
				|| (int) $m['type'] === ZBX_MACRO_TYPE_VAULT;
			$values[(string) $m['hostid']][$m['macro']] = ['type' => (int) $m['type'], 'value' => $keep ? (string) ($m['value'] ?? '') : null];
		}
		// Which linked templates define the password macro and so hide the default credential.
		$ctx['tpl_macros'] = DefaultCredential::templateMacros(array_merge([], ...array_map(fn($h) => array_column($h['parentTemplates'] ?? [], 'templateid'), array_values($hosts))));
		$proxies = array_column(API::Proxy()->get(['output' => ['proxyid', 'name']]) ?: [], 'name', 'proxyid');
		$proxyGroups = array_column(API::ProxyGroup()->get(['output' => ['proxy_groupid', 'name']]) ?: [], 'name', 'proxy_groupid');
		// Who else has each IP, anywhere in Zabbix: the module keeps one host per IP.
		$ips = array_values(array_unique(array_filter(array_map([Reconciler::class, 'agentIp'], $hosts))));
		$byIp = [];
		if ($ips) {
			$ifs = API::HostInterface()->get(['output' => ['hostid', 'ip'], 'filter' => ['ip' => $ips, 'type' => INTERFACE_TYPE_AGENT]]) ?: [];
			$names = array_column(API::Host()->get(['output' => ['hostid', 'host'], 'hostids' => array_values(array_unique(array_column($ifs, 'hostid')))]) ?: [], 'host', 'hostid');
			foreach ($ifs as $if) {
				$byIp[$if['ip']][(string) $if['hostid']] = $names[(string) $if['hostid']] ?? $if['hostid'];
			}
		}
		$out = [];
		foreach ($hosts as $id => $h) {
			if (Reconciler::hasAnyTemplate($h, Reconciler::ulmTemplates()) || Reconciler::kindWithoutTemplates($h) === 'archive'
					|| Reconciler::hasAnyTemplate($h, Reconciler::masterTemplates())) {
				continue;
			}
			$out[] = $this->describe($h, $values[(string) $id] ?? [], $ctx, $fromTemplate[(string) $id] ?? null, $byIp, $proxies, $proxyGroups, $roleTpl);
		}
		usort($out, fn($a, $b) => strnatcasecmp($a['host'], $b['host']));
		return $out;
	}

	/** The cluster host's per-family requested-figure macros, which a save writes from the form. */
	public static function requestedMacros(array $roles): array {
		$out = [];
		foreach ($roles['families'] as $f) {
			foreach (['SERVER.COUNT', 'CPU', 'MEMORY', 'ROOTDISK'] as $w) {
				$out[] = '{$'.$f['macroPrefix'].'.'.$w.'.REQUESTED}';
			}
		}
		return $out;
	}

	/** One host as the proposal shows it: what it is, which client and roles it points to, and what is missing. */
	private function describe(array $h, array $vals, array $ctx, ?string $sigTemplate, array $byIp, array $proxies, array $proxyGroups, array $roleTpl): array {
		$id = (string) $h['hostid'];
		$groups = Reconciler::groupNames($h);
		$templates = array_column($h['parentTemplates'] ?? [], 'host');
		$v = fn(string $m) => $vals[$m]['value'] ?? null;
		$row = [
			'hostid' => $id, 'host' => (string) $h['host'], 'name' => (string) ($h['name'] ?? $h['host']),
			'ip' => Reconciler::agentIp($h), 'groups' => $groups, 'templates' => $templates,
			'tags' => array_map(fn($t) => $t['tag'].($t['value'] !== '' ? '='.$t['value'] : ''), $h['tags'] ?? []),
			'macros' => array_values(array_unique(array_column($h['macros'] ?? [], 'macro'))),
			'monitored_by' => '', 'proxy_label' => _('Zabbix server'),
			'grp_client' => (string) ($v('{$GRP.CLIENT}') ?? ''),
			'tag_client' => (string) (array_values(array_filter(array_map(fn($t) => $t['tag'] === 'client' ? $t['value'] : null, $h['tags'] ?? []),
				fn($x) => $x !== null && $x !== ''))[0] ?? (Reconciler::tagValues($h, 'ep-client')[0] ?? '')),
			'kind' => 'server', 'roles' => [], 'role_source' => null, 'families' => [], 'generation' => null, 'es_templates' => [],
			'missing_own' => [], 'old_agent' => [], 'es' => null,
			'already' => $ctx['owned'][$id] ?? null, 'unroled_in' => $ctx['unroled'][$id] ?? null,
			'in_client_groups' => array_values(array_intersect($groups, $ctx['client_groups'])),
			'shared_ip' => [], 'managed' => Reconciler::isManaged($h), 'gaps' => []
		];
		if ((int) ($h['monitored_by'] ?? 0) === ZBX_MONITORED_BY_PROXY && isset($proxies[(string) $h['proxyid']])) {
			$row['monitored_by'] = 'proxy:'.$proxies[(string) $h['proxyid']];
			$row['proxy_label'] = _s('proxy %1$s', $proxies[(string) $h['proxyid']]);
		}
		elseif ((int) ($h['monitored_by'] ?? 0) === ZBX_MONITORED_BY_PROXY_GROUP && isset($proxyGroups[(string) $h['proxy_groupid']])) {
			$row['monitored_by'] = 'group:'.$proxyGroups[(string) $h['proxy_groupid']];
			$row['proxy_label'] = _s('proxy group %1$s', $proxyGroups[(string) $h['proxy_groupid']]);
		}
		if ($row['ip'] !== null) {
			$row['shared_ip'] = array_values(array_diff_key($byIp[$row['ip']] ?? [], [$id => true]));
		}

		// A cluster host: by a cluster template of a generation the module knows, by its ep-kind tag
		// when no template is visible, or by the health check of a generation it does not know.
		$jumpNames = Roles::templateNamesFor('jump', [JumpTemplate::LEGACY_NAME]);
		$current = [Roles::clusterTemplate(), Roles::templateName('jump')];
		$looksLikeCluster = Reconciler::hasAnyTemplate($h, Reconciler::clusterHostTemplates()) || Reconciler::kindWithoutTemplates($h) === 'cluster' || $sigTemplate !== null;
		$inEsGroup = (bool) array_filter($groups, fn($g) => self::isClusterGroup((string) $g));
		if ($looksLikeCluster && !$inEsGroup) {
			// The rule is the host group, not the template: said, never proposed.
			$row['kind'] = 'stray';
			return $row;
		}
		if ($inEsGroup) {
			$row['kind'] = 'cluster';
			$known = array_values(array_intersect($templates, Reconciler::clusterHostTemplates()));
			$row['jump'] = (bool) array_intersect($templates, $jumpNames);
			if (array_intersect($templates, $current)) {
				$row['generation'] = 'current';
			}
			elseif ($known) {
				$row['generation'] = 'legacy';
				$row['gaps'][] = _s('On an older generation of the cluster template (%1$s). Adopting links the ElasticPro cluster template beside it; history kept.', implode(', ', $known));
			}
			elseif ($sigTemplate !== null) {
				$row['generation'] = 'unrecognised';
			}
			elseif (!$templates && Reconciler::kindWithoutTemplates($h) === 'cluster') {
				$row['generation'] = null;
				$row['gaps'][] = _('Template generation unknown: no template is visible to you.');
			}
			else {
				$row['generation'] = 'none';
				$row['gaps'][] = _('Carries no Elasticsearch cluster template yet. Adopting links the ElasticPro cluster template.');
			}
			if ($sigTemplate !== null && !in_array($sigTemplate, $known, true)) {
				$row['es_templates'][] = $sigTemplate;
				$row['gaps'][] = $row['generation'] === 'unrecognised'
					? _s('Carries "%1$s", which gives it the Elasticsearch items but is a template name this module does not know. Adopting links the ElasticPro cluster template beside it (its items and history stay); or name it on the Roles page under the cluster template\'s extra names.', $sigTemplate)
					: _s('Also carries "%1$s", which gives it the Elasticsearch items under a template name this module does not know. It stays linked, items and history as they are.', $sigTemplate);
			}
			$own = $row['jump'] ? Reconciler::jumpClusterTemplates() : Reconciler::ownClusterTemplates();
			if ($templates) {
				$row['missing_own'] = array_values(array_diff($own, $templates));
				if ($row['missing_own']) {
					$row['gaps'][] = _s('Not linked yet: %1$s.', implode(', ', $row['missing_own']));
				}
			}
			$m = [];
			foreach ($vals as $name => $x) {
				$m[$name] = $x['value'];
			}
			$pw = $vals['{$ELASTICSEARCH.PASSWORD}'] ?? null;
			$requested = [];
			foreach ($this->roles['families'] as $f) {
				foreach (['servers' => 'SERVER.COUNT', 'cpu' => 'CPU', 'mem' => 'MEMORY', 'disk' => 'ROOTDISK'] as $k => $w) {
					$x = $v('{$'.$f['macroPrefix'].'.'.$w.'.REQUESTED}');
					if ($x !== null && is_numeric($x) && (float) $x > 0) {
						$requested[$f['id']][$k] = $x;
					}
				}
			}
			$row['es'] = [
				'url' => ClientState::urlFrom(array_filter($m, fn($x) => $x !== null)),
				'user' => (string) ($v('{$ELASTICSEARCH.USERNAME}') ?? ''),
				'password' => $pw === null ? 'none' : ([ZBX_MACRO_TYPE_SECRET => 'secret', ZBX_MACRO_TYPE_VAULT => 'vault'][$pw['type']] ?? 'text'),
				'password_path' => $pw !== null && $pw['type'] === ZBX_MACRO_TYPE_VAULT ? (string) $pw['value'] : '',
				'purchased' => (string) ($v('{$ES.VOLUME.CUS.PURCHASED}') ?? ''),
				'requested' => $requested,
				'source' => DefaultCredential::sourceOf($h, $ctx['tpl_macros'] ?? [], (bool) ($ctx['default']['password'] ?? false)),
				'hidden_by' => array_values(array_map(fn($t) => $t['host'], array_filter($h['parentTemplates'] ?? [],
					fn($t) => in_array(DefaultCredential::PASSWORD_MACRO, $ctx['tpl_macros'][(string) $t['templateid']] ?? [], true)))),
				'default_record' => $ctx['default_record'] ?? null,
				'jump' => ['host' => (string) ($v('{$WJ.HOST}') ?? ''), 'port' => (string) ($v('{$WJ.PORT}') ?? ''), 'user' => (string) ($v('{$WJ.USER}') ?? ''),
					'key' => (string) ($v('{$WJ.KEY}') ?? ''),
					'apikey' => ($vals['{$ES.APIKEY}']['type'] ?? null) === ZBX_MACRO_TYPE_VAULT ? (string) $vals['{$ES.APIKEY}']['value'] : '']
			];
			if ($row['es']['url'] === '') {
				$row['gaps'][] = _('No ES URL on the host ({$ELASTICSEARCH.HOST} is not set on it): type the URL in the proposal, or it is not adopted as the cluster host.');
			}
			if ($row['es']['password'] === 'text') {
				$row['gaps'][] = _('Its Elasticsearch password is a plain text macro (not read here). It is left as it is; a Vault reference or a Zabbix secret would be safer.');
			}
			return $row;
		}

		// A server: its roles from role groups, else a template only one role names, else the
		// module's own ep-role tag. Never from its name.
		$found = Roles::rolesOfGroups($this->roles, $groups);
		if ($found) {
			$row['roles'] = array_column($found, 'id');
			$row['role_source'] = 'group';
		}
		else {
			$byTpl = array_values(array_unique(array_values(array_intersect_key($roleTpl, array_flip($templates)))));
			if ($byTpl) {
				$row['roles'] = $byTpl;
				$row['role_source'] = 'template';
			}
			else {
				$byId = Roles::byId($this->roles);
				$byTag = array_values(array_filter(Reconciler::tagValues($h, 'ep-role'), fn($r) => isset($byId[$r])));
				if ($byTag) {
					$row['roles'] = $byTag;
					$row['role_source'] = 'tag';
				}
			}
		}
		// Kafka is not a role: a Kafka server is a forwarder or a parser. Suggested as Forwarder and
		// said, never decided: the host is not ticked until the admin confirms.
		$row['kafka'] = (bool) preg_match('/kafka/i', implode(' ', array_merge([(string) $h['host'], (string) ($h['name'] ?? '')], $groups, $templates)));
		$inFamily = (bool) array_filter($this->roles['families'], fn($f) => in_array($f['group'], $groups, true));
		if (!$row['roles'] && !$inFamily && $row['kafka'] && isset(Roles::byId($this->roles)['forwarder'])) {
			$row['roles'] = ['forwarder'];
			$row['role_source'] = 'kafka';
			$row['gaps'][] = _('kafka: Kafka is not a role — confirm Forwarder or Parser (Forwarder is only suggested).');
		}
		if (!$row['roles']) {
			foreach ($this->roles['families'] as $f) {
				if (in_array($f['group'], $groups, true)) {
					$row['families'][] = $f['label'];
				}
			}
			$row['gaps'][] = $row['families']
				? _s('Role unknown: in %1$s, but in none of its role groups (%2$s). Pick a role.', implode(', ', array_map(fn($f) => $f, $row['families'])),
					implode(', ', array_merge(...array_map(fn($f) => array_column($f['roles'], 'group'),
						array_values(array_filter($this->roles['families'], fn($f) => in_array($f['group'], $groups, true)))))))
				: _('Role unknown: no role group, no role template, no ep-role tag. Pick a role.');
		}
		// Host name = IP, visible name = server name: the server name is read, never made up.
		$row['server_name'] = Reconciler::serverName($h);
		if ($row['server_name'] === '') {
			$row['gaps'][] = _s('Server name unknown: its visible name is %1$s. It is left as it is; give the server name in Import (Servers sheet) or on the client\'s page.',
				(string) ($h['name'] ?? $h['host']) === (string) $h['host'] ? _('its host name') : '"'.$h['name'].'"');
		}
		$agentNames = Roles::templateNamesFor('agent');
		$row['old_agent'] = array_values(array_diff(array_intersect($templates, $agentNames), [Roles::defaultTemplate()]));
		if ($row['old_agent']) {
			$row['gaps'][] = _s('On an older generation of the agent template (%1$s).', implode(', ', $row['old_agent']));
		}
		if ($row['ip'] === null) {
			$row['gaps'][] = _('No agent interface with an IP: the module keeps servers by IP, so it cannot be adopted as one.');
		}
		elseif ($row['shared_ip']) {
			$row['gaps'][] = _s('%1$s is also the IP of %2$s: the module keeps one host per IP.', $row['ip'], implode(', ', $row['shared_ip']));
		}
		if ($row['unroled_in'] !== null) {
			$row['gaps'][] = _s('In client %1$s\'s group but with no role there.', $row['unroled_in']);
		}
		return $row;
	}

	/* ------------------------------------ grouping (pure) ------------------------------------ */

	/** Host groups that say nothing about which cluster a host belongs to. */
	private static function genericGroup(string $g, array $roleGroups): bool {
		return self::isClusterGroup($g) || in_array($g, $roleGroups, true)
			|| in_array($g, [Reconciler::MASTERS_GROUP, Reconciler::LEGACY_MASTERS_GROUP, Reconciler::CLUSTER_GROUP, Reconciler::ULM_GROUP], true);
	}

	/**
	 * What identifies a cluster to its role hosts, per hint: its client's group and the host
	 * groups only it is in; its names for macro and tag hints; its <client> for the prefix. Pure.
	 */
	public static function identity(array $cluster, array $allClusters, array $roleGroups, array $words): array {
		$shared = [];
		foreach ($allClusters as $o) {
			if ($o['hostid'] !== $cluster['hostid']) {
				foreach ($o['groups'] as $g) {
					$shared[$g] = true;
				}
			}
		}
		$names = array_values(array_unique(array_filter([$cluster['host'], $cluster['already'] ?? null, $cluster['grp_client'] ?? '', $cluster['tag_client'] ?? '',
				self::clientFromName($cluster['host'], $words)],
			fn($x) => $x !== null && $x !== '')));
		$groups = array_values(array_filter($cluster['groups'], fn($g) => !self::genericGroup($g, $roleGroups) && !isset($shared[$g])));
		// <client>-<Role>-<n>: the cluster's name is the client's, so its servers may be named after
		// it — or after the <client> of a <client>-ES-Cluster host, or its existing client.
		$prefix = array_values(array_filter(array_unique(array_merge([$cluster['host']], array_filter([$cluster['already'] ?? null]), [self::clientFromName($cluster['host'], $words) ?? ''])),
			fn($x) => $x !== ''));
		return ['group' => $groups, 'macro' => $names, 'tag' => $names, 'prefix' => $prefix];
	}

	/** A role host's hints, per source: the values it offers. Pure. */
	public static function hints(array $row, array $roleGroups, array $words): array {
		return [
			'group' => array_values(array_filter($row['groups'], fn($g) => !self::genericGroup($g, $roleGroups))),
			'macro' => ($row['grp_client'] ?? '') !== '' ? [$row['grp_client']] : [],
			'tag' => ($row['tag_client'] ?? '') !== '' ? [$row['tag_client']] : [],
			'prefix' => array_values(array_filter([self::clientFromName($row['host'], $words) ?? ''], fn($x) => $x !== ''))
		];
	}

	/**
	 * Rows into one proposal per cluster host, role hosts attached by the first hint that points
	 * at exactly one cluster. Pure.
	 *
	 * @return array{proposals: array, unattached: array, stray: array}
	 */
	public static function proposals(array $rows, array $words, array $roleGroups = []): array {
		$clusters = array_values(array_filter($rows, fn($r) => $r['kind'] === 'cluster'));
		$stray = array_values(array_filter($rows, fn($r) => $r['kind'] === 'stray'));
		$servers = array_values(array_filter($rows, fn($r) => $r['kind'] === 'server'));
		$ids = [];
		foreach ($clusters as $c) {
			$ids[$c['hostid']] = self::identity($c, $clusters, $roleGroups, $words);
		}
		$byId = array_column($clusters, null, 'hostid');
		$attached = [];
		$unattached = [];
		foreach ($servers as $s) {
			// Already a client's host: with that client's cluster, whatever else it says.
			$owner = null;
			if (($s['already'] ?? null) !== null) {
				foreach ($clusters as $c) {
					if (($c['already'] ?? null) === $s['already']) {
						$owner = [$c['hostid'], 'group', _s('already a host of client %1$s', $s['already'])];
					}
				}
			}
			$why = null;
			if ($owner === null) {
				$h = self::hints($s, $roleGroups, $words);
				foreach (self::SOURCES as $src) {
					$hit = [];
					foreach ($ids as $cid => $id) {
						$common = array_values(array_intersect($h[$src], $id[$src]));
						if ($common) {
							$hit[$cid] = $common[0];
						}
					}
					if (count($hit) === 1) {
						$cid = array_key_first($hit);
						$label = ['group' => _s('host group "%1$s"', $hit[$cid]), 'macro' => _s('{$GRP.CLIENT} = %1$s', $hit[$cid]),
							'tag' => _s('client tag %1$s', $hit[$cid]), 'prefix' => _s('name starts %1$s-', $hit[$cid])][$src];
						$owner = [(string) $cid, $src, $label];
						break;
					}
					if (count($hit) > 1) {
						$why = _s('its %1$s points at more than one cluster (%2$s)', $src,
							implode(', ', array_map(fn($cid) => $byId[$cid]['host'], array_keys($hit))));
						break;
					}
				}
			}
			if ($owner === null) {
				$s['source'] = null;
				$s['source_detail'] = $why ?? _('no client group, no {$GRP.CLIENT}, no client tag and no name prefix points at a cluster');
				$unattached[] = $s;
				continue;
			}
			$s['source'] = $owner[1];
			$s['source_detail'] = $owner[2];
			$attached[$owner[0]][] = $s;
		}
		$out = [];
		foreach ($clusters as $c) {
			$c['source'] = 'cluster';
			$c['source_detail'] = _s('in host group %1$s', implode(', ', array_filter($c['groups'], fn($g) => self::isClusterGroup((string) $g))));
			$out[] = ['id' => substr(sha1('c:'.$c['hostid']), 0, 10), 'name' => (string) ($c['already'] ?? $c['host']), 'source' => 'cluster',
				'cluster' => $c['host'], 'hosts' => array_merge([$c], $attached[$c['hostid']] ?? [])];
		}
		usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
		return ['proposals' => $out, 'unattached' => $unattached, 'stray' => $stray];
	}

	/** new: no host is a client's yet; client: every host already is; existing: some are. */
	public static function status(array $p): string {
		$already = count(array_filter($p['hosts'], fn($h) => ($h['already'] ?? null) !== null));
		return $already === count($p['hosts']) ? 'client' : ($already ? 'existing' : 'new');
	}

	/* ------------------------------------ planning ------------------------------------ */

	/** A plan that does nothing — what a proposal whose hosts are all a client's already has. */
	public static function emptyPlan(): array {
		return ['client' => null, 'form' => null, 'cluster' => null, 'servers' => [], 'skipped' => [], 'moves' => [],
			'blockers' => [], 'writes' => [], 'adopting' => 0, 'now' => null, 'cluster_found' => false];
	}

	/**
	 * The choices a proposal starts with: every host that can be adopted as it is, ticked; a host
	 * already a client's, or whose role is unknown or only suggested (kafka), or that something
	 * stops, not.
	 * Moving a host onto the ElasticPro templates is ticked wherever it applies.
	 */
	public function defaults(array $p): array {
		$c = ['selected' => false, 'name' => $p['name'], 'hosts' => [], 'roles' => [], 'move' => [], 'es_url' => '', 'password' => '',
			'proxy_es' => 'server', 'proxy_linux' => 'server'];
		$cluster = false;
		foreach ($p['hosts'] as $h) {
			$id = $h['hostid'];
			$c['roles'][$id] = $h['roles'];
			$c['move'][$id] = true;
			$ok = ($h['already'] ?? null) === null;
			if ($h['kind'] === 'cluster') {
				$ok = $ok && !$cluster && ($h['es']['url'] ?? '') !== '';
				if ($ok) {
					$cluster = true;
					$c['es_url'] = $h['es']['url'];
				}
			}
			else {
				$ok = $ok && $h['roles'] && $h['ip'] !== null && !$h['shared_ip'];
				$c['confirm'][$id] = ($h['role_source'] ?? null) === 'kafka' ? 'kafka' : '';
			}
			$c['hosts'][$id] = $ok;
		}
		return $c;
	}

	/**
	 * What adopting this proposal with these choices would do: the client form, the refusals, and
	 * the write list. Reads only.
	 *
	 * @param callable|null $refusals  WriteCheck needs => refusal sentences (the action's writeRefusals)
	 */
	public function plan(array $p, array $c, ?callable $refusals = null): array {
		$out = self::emptyPlan();
		// The cluster's Zabbix host name is its name by default; the Discover page lets it be edited.
		$name = trim((string) ($c['name'] ?? $p['name']));
		if ($name === '') {
			$out['blockers'][] = _('Give the cluster a name.');
			return $out;
		}
		$state = new ClientState($this->spec, $this->rec);
		$exists = isset($state->clients()[$name]);
		$byId = Roles::byId($this->roles);
		$clusterRow = null;
		$ips = [];
		foreach ($p['hosts'] as $h) {
			$id = $h['hostid'];
			if (empty($c['hosts'][$id])) {
				continue;
			}
			if (($h['already'] ?? null) !== null) {
				$out['skipped'][$id] = _s('"%1$s" is already a host of client %2$s.', $h['host'], $h['already']);
				continue;
			}
			if ($h['kind'] === 'cluster') {
				if ($clusterRow !== null) {
					$out['skipped'][$id] = _s('"%1$s": a client has one cluster host, and "%2$s" is already chosen.', $h['host'], $clusterRow['host']);
					continue;
				}
				if (ClientSpec::esEndpoint(trim((string) ($c['es_url'] ?? ''))) === null) {
					$out['skipped'][$id] = _s('"%1$s": no ES URL — type it as scheme://host:port to adopt it as the cluster host.', $h['host']);
					continue;
				}
				$clusterRow = $h;
				continue;
			}
			$roles = array_values(array_filter(array_map('strval', (array) ($c['roles'][$id] ?? [])), fn($r) => isset($byId[$r])));
			if (!$roles) {
				$out['skipped'][$id] = _s('"%1$s": no role picked.', $h['host']);
				continue;
			}
			if ($h['ip'] === null) {
				$out['skipped'][$id] = _s('"%1$s": no agent interface with an IP.', $h['host']);
				continue;
			}
			if (isset($ips[$h['ip']])) {
				$out['skipped'][$id] = _s('"%1$s": %2$s is already chosen for "%3$s" — one host per IP.', $h['host'], $h['ip'], $ips[$h['ip']]);
				continue;
			}
			if ($h['shared_ip']) {
				$out['skipped'][$id] = _s('"%1$s": %2$s is also the IP of %3$s — one host per IP.', $h['host'], $h['ip'], implode(', ', $h['shared_ip']));
				continue;
			}
			$ips[$h['ip']] = $h['host'];
			$out['servers'][$id] = ['row' => $h, 'roles' => $roles];
		}

		// The form: an existing client's as it stands, with these servers added; a new one from the
		// shipped defaults and what the cluster host's macros say.
		if ($exists) {
			$form = ClientState::plain($state->formFor($name));
		}
		else {
			$form = $this->spec->newForm();
			$form['name'] = $name;
		}
		$monitor = null;
		if ($clusterRow !== null) {
			$es = $clusterRow['es'];
			$form['es_url'] = trim((string) $c['es_url']);
			if ($es['user'] !== '') {
				$form['es_user'] = $es['user'];
			}
			// The password: typed here (only offered when the host has none ElasticPro can use) goes
			// to a Zabbix secret and to the core; a Vault reference on the host is kept as it is; a
			// secret already there is left alone; none at all is left none — and said under Missing,
			// never made up. Vault with no path writes nothing on an existing host.
			$typed = (string) ($c['password'] ?? '');
			if ($typed !== '' && $es['password'] !== 'vault') {
				$form['es_password_mode'] = 'zabbix';
				$form['es_password'] = $typed;
			}
			elseif ($es['password'] === 'secret') {
				$form['es_password_mode'] = 'zabbix';
			}
			elseif ($es['password'] === 'vault' && $es['password_path'] !== '') {
				$form['es_password_mode'] = 'vault';
				$form['es_password_path'] = $es['password_path'];
			}
			elseif (!$exists) {
				// No password of its own: the default credential (inherited, nothing written on the host).
				$form['es_password_mode'] = 'default';
				$form['es_password_path'] = '';
			}
			if (!$exists && $es['purchased'] !== '' && is_numeric($es['purchased'])) {
				$form['purchased'] = $es['purchased'];
			}
			if (!$exists) {
				$form = $this->carryRequested($form, $es['requested'], array_column($out['servers'], 'roles'));
			}
			if (!empty($clusterRow['jump'])) {
				$form['monitored_by'] = 'jump';
				$form['jump_host'] = $es['jump']['host'];
				$form['jump_port'] = $es['jump']['port'];
				$form['jump_user'] = $es['jump']['user'];
				$form['jump_key'] = $es['jump']['key'];
				$form['es_apikey_path'] = $es['jump']['apikey'];
			}
			$monitor = $clusterRow['monitored_by'];
		}
		// Two proxy choices: the cluster host's, and ONE for all the role hosts. Both are the Zabbix
		// server unless the admin picks a proxy (decided 2026-10-23); a host monitored otherwise
		// today is said under Missing and in the write list, never moved without being shown.
		$pick = fn($v) => $v === null || $v === '' || $v === 'server' || $v === self::KEEP ? '' : (string) $v;
		if (empty($clusterRow['jump'])) {
			$form['monitored_by'] = $pick($c['proxy_es'] ?? 'server');
		}
		$linux = $pick($c['proxy_linux'] ?? 'server');
		$form['servers_monitored_by'] = $linux === '' ? 'server' : $linux;
		$servers = json_decode((string) ($form['servers'] ?? '[]'), true) ?: [];
		$have = array_flip(array_column($servers, 'ip'));
		foreach ($out['servers'] as $id => $s) {
			if (isset($have[$s['row']['ip']])) {
				$out['skipped'][$id] = _s('"%1$s": %2$s is already a server of client %3$s.', $s['row']['host'], $s['row']['ip'], $name);
				unset($out['servers'][$id]);
				continue;
			}
			$servers[] = ['ip' => $s['row']['ip'], 'roles' => $s['roles'], 'services' => [], 'notes' => '', 'attrs' => [],
				// The server name is the visible name when it is one; never the host name.
				'host' => (string) ($s['row']['server_name'] ?? '')] + (($c['confirm'][$id] ?? '') !== '' ? ['confirm' => $c['confirm'][$id]] : []);
		}
		$form['servers'] = json_encode($servers);
		['client' => $client, 'errors' => $errors] = $this->spec->fromForm($form);
		// Never the password: the plan is cached for the session and drawn on the page.
		$out['form'] = array_diff_key($form, ['es_password' => true]);
		if ($errors) {
			$out['blockers'] = array_merge($out['blockers'], $errors);
			return $out;
		}
		// The cluster host's name is the client's: it is never renamed, ticked or not.
		$client['keep_cluster_name'] = true;
		$out['client'] = $client;
		$out['cluster'] = $clusterRow;
		$out['adopting'] = count($out['servers']) + ($clusterRow !== null ? 1 : 0);
		if (!$out['adopting']) {
			$out['blockers'][] = _('No host is ticked that can be adopted.');
		}

		// Template moves: each adopted host onto the templates apply() links to it.
		foreach ($out['servers'] as $id => $s) {
			$out['moves'][$id] = $this->movePlan($s['row'], Roles::templatesOf($this->roles, $s['roles']));
		}
		if ($clusterRow !== null) {
			$out['moves'][$clusterRow['hostid']] = $this->movePlan($clusterRow, $this->spec->throughJump($client)
				? Reconciler::jumpClusterTemplates() : Reconciler::clusterTemplates());
		}
		foreach ($out['moves'] as $id => $m) {
			$h = (string) $id === (string) ($clusterRow['hostid'] ?? '') ? $clusterRow : $out['servers'][$id]['row'];
			if ($m['refused']) {
				$out['skipped'][$id] = _s('"%1$s" cannot be moved onto the ElasticPro templates: %2$s — use tools/migrate-templates.py (docs/MIGRATION.md).', $h['host'], implode('; ', $m['refused']));
			}
			elseif ($m['mode'] === 'move' && empty($c['move'][$id])) {
				$out['skipped'][$id] = _s('"%1$s" keeps its old template ("move" is not ticked), and adopting it links one whose items clash with it.', $h['host']);
			}
		}
		if (array_intersect_key($out['skipped'], $out['servers'] + ($clusterRow !== null ? [$clusterRow['hostid'] => 1] : []))) {
			// A host the move refused leaves the plan: re-plan without it, so the form and the write list agree.
			$c2 = $c;
			foreach (array_keys($out['skipped']) as $id) {
				$c2['hosts'][$id] = false;
			}
			$again = $this->plan($p, $c2, $refusals);
			$again['skipped'] = $out['skipped'] + $again['skipped'];
			return $again;
		}

		// What would stop the save, asked before anything is written: Reconciler's own pre-flight,
		// on the client as it would be with the cluster host already in its group.
		$now = $this->rec->current($name);
		$out['cluster_found'] = $now['cluster'] !== null;
		if ($clusterRow !== null && $now['cluster'] === null) {
			$now['cluster'] = $this->hostRecord($clusterRow['hostid']);
		}
		$out['now'] = $now;
		$out['blockers'] = array_merge($out['blockers'], $this->rec->problems($client, $now));
		$deletes = $this->rec->plannedDeletes($client, $now);
		if ($deletes) {
			$out['blockers'][] = _s('Adopting would delete %1$s: open the client\'s page for that — this page never deletes.', implode(', ', $deletes));
		}
		if ($refusals !== null) {
			$out['blockers'] = array_merge($out['blockers'], $refusals($this->needs($client, $now, $out)));
		}
		$out['blockers'] = array_values(array_unique($out['blockers']));
		$out['writes'] = $this->writes($client, $now, $out, $exists);
		return $out;
	}

	/**
	 * A new client's requested figures from the cluster host's macros. The cluster host holds them
	 * per family; the form keeps them per role. Each family's figure goes on one role of it — the
	 * first in role order that one of the adopted servers holds, else the family's first — so the
	 * family sum a save writes back is the figure the host has now, not a zero.
	 */
	private function carryRequested(array $form, array $requested, array $serverRoles): array {
		$held = array_merge([], ...$serverRoles);
		foreach ($this->roles['families'] as $f) {
			if (empty($requested[$f['id']])) {
				continue;
			}
			$ids = array_column($f['roles'], 'id');
			$pick = array_values(array_intersect($ids, $held))[0] ?? $ids[0];
			foreach ($requested[$f['id']] as $k => $x) {
				$form[$pick.'_'.$k] = $x;
			}
		}
		return $form;
	}

	/** The host as Reconciler::hostsIn() reads it, for the pre-flight. */
	private function hostRecord(string $hostid): ?array {
		return API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status', 'monitored_by', 'proxyid', 'proxy_groupid', 'inventory_mode'],
			'hostids' => [$hostid], 'selectHostGroups' => ['groupid', 'name'], 'selectParentTemplates' => ['templateid', 'host'],
			'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'type', 'main', 'available'], 'selectTags' => ['tag', 'value'],
			'selectMacros' => ['macro', 'type']
		])[0] ?? null;
	}

	/** One host's move onto these templates, decided from its items (TemplateMove::decide). */
	private function movePlan(array $row, array $names): array {
		$key = implode("\n", $names);
		$target = $this->targets[$key] ?? ($this->targets[$key] = TemplateMove::targets($names));
		if (!$row['templates']) {
			// No template at all: nothing can clash, nothing is unlinked.
			return ['mode' => 'beside', 'refused' => $target['missing'] ? ['new template(s) not in Zabbix — import them first: '.implode(', ', $target['missing'])] : [],
				'items' => [], 'macros' => [], 'manual' => [], 'unlink' => [], 'link' => array_column($target['templates'], 'templateid'),
				'host' => $row['host'], 'hostid' => $row['hostid'], 'triggers' => [], 'notes' => [], 'names' => array_column($target['templates'], 'host', 'templateid')];
		}
		$snap = TemplateMove::snapshot($row['hostid']);
		$names = array_column($target['templates'], 'host', 'templateid') + array_column($snap['host']['parentTemplates'] ?? [], 'host', 'templateid');
		$plan = TemplateMove::decide($snap, $names, $target);
		$plan['names'] = $names;
		return $plan;
	}

	/** What the save needs of the user's Zabbix rights: WriteCheck::forSave(), and every host it adopts. */
	public function needs(array $client, array $now, array $plan): array {
		$needs = WriteCheck::forSave($this->rec, $this->spec, $client, $now);
		foreach ($plan['servers'] as $id => $s) {
			$needs['hosts'][(string) $id] = $s['row']['name'];
		}
		if ($plan['cluster'] !== null) {
			$needs['hosts'][(string) $plan['cluster']['hostid']] = $plan['cluster']['name'];
		}
		return $needs;
	}

	/**
	 * The write list: one line per thing confirming would change, in the order it happens.
	 * Each line is [op, text]: backup, group, move, link, master, cluster, server, monitor, note.
	 */
	private function writes(array $client, array $now, array $plan, bool $exists): array {
		$w = [['backup', _('Take a backup of every client first (Backups keeps the newest three).')]];
		$name = $client['name'];
		$existing = array_column(API::HostGroup()->get(['output' => ['name'], 'filter' => ['name' => $this->rec->groupsFor($client)]]) ?: [], 'name');
		foreach ($this->rec->groupsFor($client) as $g) {
			if (!in_array($g, $existing, true)) {
				$w[] = ['group', _s('Create host group "%1$s".', $g)];
			}
		}
		foreach ($plan['moves'] as $id => $m) {
			if ($m['mode'] === 'move') {
				$host = (string) $id === (string) ($plan['cluster']['hostid'] ?? '') ? $plan['cluster']['host'] : $plan['servers'][$id]['row']['host'];
				$w[] = ['move', _s('Move "%1$s" onto the ElasticPro templates (history kept): %2$s.', $host, TemplateMove::summary($m, $m['names'] ?? []))];
			}
		}
		if ($now['master'] === null) {
			$w[] = ['master', _s('Create "%1$s" in "%2$s" and "%3$s", linked to "%4$s", with the client\'s settings (the shipped defaults; edit them on the client\'s page). It carries %5$s, like every host this page makes.',
				ClientSpec::masterName($name), Reconciler::MASTERS_GROUP, $name, MasterTemplate::name(), Reconciler::MANAGED['tag'].': '.Reconciler::MANAGED['value'])];
		}
		$c = $plan['cluster'];
		if ($c !== null) {
			$tpl = $this->spec->throughJump($client) ? Reconciler::jumpClusterTemplates() : Reconciler::clusterTemplates();
			$add = array_values(array_diff($tpl, $c['templates']));
			$have = array_values(array_intersect($tpl, $c['templates']));
			$q = fn(array $l) => implode(', ', array_map(fn($t) => '"'.$t.'"', $l));
			$w[] = ['link', $add
				? _s('Link to "%1$s", directly and in one step (host.massadd): %2$s.%3$s Items with a key the host already has are adopted, history kept; nothing is unlinked or cleared.',
					$c['host'], $q($add), $have ? ' '._s('Already linked: %1$s.', $q($have)) : '')
				: _s('"%1$s" already carries %2$s: no template to link.', $c['host'], $q($have))];
			$old = array_values(array_diff($c['templates'], $tpl));
			if ($old) {
				$w[] = ['link', _s('Stays linked beside them, items and history as they are: %1$s.', $q($old))];
			}
			$w[] = ['cluster', _s('Adopt "%1$s" as the cluster host: into "%2$s" and "%3$s"; tags ep-client, ep-kind=cluster, ep-status%4$s; macros {$GRP.CLIENT}, {$ELASTICSEARCH.SCHEME/HOST/PORT/USERNAME} and the requested figures.',
				$c['host'], $name, Reconciler::CLUSTER_GROUP, ', ep-name=own')];
		}
		$byId = Roles::byId($this->roles);
		foreach ($plan['servers'] as $id => $s) {
			$h = $s['row'];
			$groups = array_values(array_unique(array_merge([$name], array_map(fn($r) => $byId[$r]['group'], $s['roles']), Roles::familyGroupsOf($this->roles, $s['roles']))));
			$add = array_values(array_diff(Roles::templatesOf($this->roles, $s['roles']), $h['templates']));
			$w[] = ['server', _s('Adopt %1$s — host name %2$s (unchanged), server name %3$s, IP %4$s, role %5$s: into %6$s; %7$s; tags ep-client, ep-kind=server, ep-role, ep-service, ep-status; macro {$GRP.CLIENT}.',
				'"'.$h['name'].'"', '"'.$h['host'].'"', ($h['server_name'] ?? '') !== '' ? '"'.$h['server_name'].'"' : _('unknown (the visible name stays as it is)'),
				$h['ip'], implode(' + ', array_map(fn($r) => $byId[$r]['label'], $s['roles'])),
				implode(', ', array_map(fn($g) => '"'.$g.'"', $groups)),
				$add ? _s('link %1$s', implode(', ', array_map(fn($t) => '"'.$t.'"', $add))) : _('no template to link'))];
		}
		// Proxy: the cluster host by the cluster's choice, every role host by the Linux hosts' one.
		$es = (string) ($client['fields']['monitored_by'] ?? '');
		$lx = (string) ($client['fields']['servers_monitored_by'] ?? '');
		$lx = $lx === '' ? ($es === 'jump' ? '' : $es) : ($lx === 'server' ? '' : $lx);
		$say = fn(string $v) => $v === '' ? _('the Zabbix server') : $v;
		if ($c !== null && $es !== 'jump' && $c['monitored_by'] !== $es) {
			$w[] = ['monitor', _s('Set proxy on "%1$s": %2$s → %3$s.', $c['host'], $c['proxy_label'], $say($es))];
		}
		foreach (array_column($plan['servers'], 'row') as $h) {
			if ($h['monitored_by'] !== $lx) {
				$w[] = ['monitor', _s('Set proxy on "%1$s": %2$s → %3$s (one for all the Linux hosts).', $h['host'], $h['proxy_label'], $say($lx))];
			}
		}
		$w[] = ['note', _('Nothing is deleted, and no host name is changed: the cluster host\'s name is the client\'s, a role host keeps its host name and its visible name is its server name.')];
		return $w;
	}

	/* ------------------------------------ applying ------------------------------------ */

	/**
	 * Carry out one proposal. Re-plans against Zabbix as it is now; refuses before any write when
	 * something would stop it. Then: template moves, the cluster host into the client's group (and
	 * onto the ElasticPro cluster template, which marks it as one), and Reconciler::apply().
	 *
	 * @param callable $before    called once, before the first write (takes the backup; throws to stop)
	 * @param callable $after     called with the client name after it is applied (Base::noteChange)
	 * @return array ['name', 'done' => [...], 'skipped' => [...], 'error' => ?string, 'problems' => [...]]
	 */
	public function apply(array $p, array $c, ?callable $refusals, callable $before, callable $after, ?callable $verify = null, string $by = '?'): array {
		$name = trim((string) ($c['name'] ?? $p['name']));
		$res = ['name' => $name !== '' ? $name : $p['name'], 'done' => [], 'skipped' => [], 'error' => null, 'problems' => []];
		try {
			$plan = $this->plan($p, $c, $refusals);
		}
		catch (Exception $e) {
			$res['error'] = $e->getMessage();
			return $res;
		}
		$res['skipped'] = array_values($plan['skipped']);
		if ($plan['blockers'] || $plan['client'] === null) {
			$res['error'] = _('Nothing was changed: ').implode(' ', $plan['blockers']);
			return $res;
		}
		$client = $plan['client'];
		try {
			$before();
		}
		catch (Exception $e) {
			$res['error'] = _('Nothing was changed: ').$e->getMessage();
			return $res;
		}
		try {
			foreach ($plan['moves'] as $id => $m) {
				if ($m['mode'] === 'move') {
					$res['done'] = array_merge($res['done'], TemplateMove::execute($m, TemplateMove::snapshot((string) $id), $m['names'] ?? []));
				}
			}
			$cl = $plan['cluster'];
			if ($cl !== null && !$plan['cluster_found']) {
				// current() finds a client's cluster host in the client's group by its template, so the
				// host goes into the group first and — when it carries no cluster template of a known
				// generation — gets the ElasticPro one, which has no items and so clashes with nothing.
				$gid = $this->rec->groupId($name, true);
				$mark = $this->spec->throughJump($client) ? Roles::templateName('jump') : Roles::clusterTemplate();
				$params = ['hosts' => [['hostid' => $cl['hostid']]], 'groups' => [['groupid' => $gid]]];
				if (in_array($cl['generation'], ['unrecognised', 'none'], true)) {
					$t = API::Template()->get(['output' => ['templateid'], 'filter' => ['host' => [$mark]]]);
					if (!$t) {
						throw new Exception(_s('The template "%1$s" is not in Zabbix: write it on the Roles page first.', $mark));
					}
					$params['templates'] = [['templateid' => $t[0]['templateid']]];
				}
				TemplateMove::guard($params);
				if (API::Host()->massAdd($params) === false) {
					$said = array_column(get_and_clear_messages(), 'message');
					throw new Exception(_s('Zabbix would not put "%1$s" in "%2$s": %3$s', $cl['host'], $name, implode(' ', $said) ?: _('no reason given')));
				}
				$res['done'][] = _s('Put "%1$s" in host group "%2$s"%3$s.', $cl['host'], $name, isset($params['templates']) ? _s(' and linked "%1$s" beside its templates', $mark) : '');
			}
			$this->rec->apply($client);
			$res['done'] = array_merge($res['done'], $this->rec->done(), ClusterSecret::handOver($this->rec, $client, $by));
		}
		catch (Exception $e) {
			$res['done'] = array_values(array_unique(array_merge($res['done'], $this->rec->done())));
			$res['error'] = $e->getMessage();
			return $res;
		}
		try {
			$after($name);
		}
		catch (Exception $e) {
			$res['problems'][] = $e->getMessage();
		}
		if ($verify !== null) {
			$res['problems'] = array_merge($res['problems'], $verify($client));
		}
		return $res;
	}

	/** Choices as posted, for one proposal, made safe: only its own hosts, only known roles. Pure. */
	public static function choicesFrom(array $p, array $in, array $roles): array {
		$pid = $p['id'];
		$byId = Roles::byId($roles);
		$c = ['selected' => (string) ($in['sel'][$pid] ?? '') === '1', 'name' => trim((string) ($in['name'][$pid] ?? $p['name'])),
			'hosts' => [], 'roles' => [], 'move' => [],
			'es_url' => trim((string) ($in['es_url'][$pid] ?? '')),
			// Never cached, never echoed back into the page.
			'password' => (string) ($in['pw'][$pid] ?? ''),
			// The two proxy choices, one per page (they apply to every ticked proposal).
			'proxy_es' => (string) ($in['proxy_es'] ?? 'server'), 'proxy_linux' => (string) ($in['proxy_linux'] ?? 'server')];
		foreach ($p['hosts'] as $h) {
			$id = $h['hostid'];
			$c['hosts'][$id] = (string) ($in['host'][$pid][$id] ?? '') === '1';
			$c['move'][$id] = (string) ($in['move'][$pid][$id] ?? '') === '1';
			$picked = $in['role'][$pid][$id] ?? null;
			// "kafka:forwarder" is the suggestion left as it is: Forwarder, still to be confirmed.
			$c['confirm'][$id] = $picked === null ? (($h['role_source'] ?? null) === 'kafka' ? 'kafka' : '')
				: (in_array('kafka:forwarder', (array) $picked, true) ? 'kafka' : '');
			$picked = $picked === null ? null : array_map(fn($r) => $r === 'kafka:forwarder' ? 'forwarder' : $r, (array) $picked);
			$c['roles'][$id] = $picked === null ? $h['roles']
				: array_values(array_unique(array_filter(array_map('strval', (array) $picked), fn($r) => isset($byId[$r]))));
		}
		return $c;
	}
}
