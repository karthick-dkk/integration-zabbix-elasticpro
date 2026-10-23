<?php
/**
 * Discover existing hosts, against the fake Zabbix (test/fake-zabbix.php):
 *   php test/discover.test.php
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
 *
 * What it holds the feature to:
 *  - the cluster rule (a host group containing "elasticsearch"), the host name as the client's,
 *    and every hint that attaches role hosts — group, macro, tag, prefix — plus ambiguity,
 *    unattached hosts and clusters outside such a group;
 *  - all three template generations: the ElasticPro one, the pre-rename one, and a name no
 *    setting knows (found by its Elasticsearch health item);
 *  - hosts already a client's shown and never ticked, the old page's karthick-DC-style client
 *    included, and nothing at all written for it;
 *  - the write list (every template linked named), the apply's writes, the WriteCheck refusal;
 *  - TemplateMove: the shared case table with migrate-templates.py, its constants, and a real
 *    move that keeps every item id;
 *  - the password: the keep-marker and the signed hand-over to the core.
 */
namespace {
	require __DIR__.'/fake-zabbix.php';
}
namespace Modules\EpClients\Test {
	foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'Forecast', 'ClientTypes', 'MasterTemplate', 'DevicesTemplate', 'JumpTemplate', 'ClusterTemplate',
			'ClientSpec', 'Csv', 'Zip', 'Xlsx', 'Workbook', 'Backups', 'Reconciler', 'DefaultCredential', 'Lifecycle', 'Registry', 'History', 'SetupChecks', 'AlertRouting',
			'ClientState', 'Importer', 'TemplateInstaller', 'TemplateMap', 'WriteCheck', 'EpRole', 'TemplateMove', 'Missing', 'ClusterSecret', 'Discovery'] as $lib) {
		require __DIR__.'/../lib/'.$lib.'.php';
	}
	use Modules\EpClients\Lib\{ClientSpec, ClientState, ClusterSecret, ClusterTemplate, DefaultCredential, Discovery, EpRole, JumpTemplate, MasterTemplate, Missing, Reconciler, Roles, SetupChecks, Store,
		TemplateMove, WriteCheck};

	$failed = 0; $passed = 0; $scene = '';
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed, $scene;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: ", $scene !== '' ? $scene.' — ' : '', $what, $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-disc-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);

	$roles = Roles::defaults();
	$spec = new ClientSpec($roles);
	$OLDGEN = 'Elasticsearch Cluster by HTTP OLDGEN';   // a generation no setting names: made up for the test
	$OLD_AGENT = 'Linux by Zabbix agent';

	$allTemplates = array_values(array_unique(array_merge([MasterTemplate::NAME], Reconciler::clusterTemplates(),
		Reconciler::jumpClusterTemplates(), [Reconciler::ULM_TEMPLATE, JumpTemplate::ULM_NAME, Reconciler::agentTemplate()])));
	$begin = function (string $name) use ($allTemplates, $OLDGEN, $OLD_AGENT): void {
		global $scene;
		$scene = $name;
		\Zbx::reset();
		foreach ($allTemplates as $t) { \Zbx::addTemplate($t); }
		// The agent templates of both generations carry the same keys, as the real ones do.
		foreach ([Reconciler::agentTemplate(), $OLD_AGENT] as $t) {
			$tid = \Zbx::addTemplate($t);
			\Zbx::addItem($tid, 'agent.ping', '3');
			\Zbx::addItem($tid, 'system.cpu.load[all,avg1]', '0');
		}
		\Zbx::addItem(\Zbx::addTemplate($OLD_AGENT), 'system.old.only', '3', ['url' => '{$OLD.URL}']);
		\Zbx::addMacro(\Zbx::addTemplate($OLD_AGENT), '{$OLD.URL}', 'http://x', 0);
		// Older Elasticsearch templates carry the health item; the ElasticPro cluster template none.
		\Zbx::addItem(\Zbx::addTemplate(Reconciler::LEGACY_CLUSTER_TEMPLATE), SetupChecks::ES_HEALTH_KEY, '4');
		\Zbx::addItem(\Zbx::addTemplate($OLDGEN), SetupChecks::ES_HEALTH_KEY, '4');
		\Zbx::addItem(\Zbx::addTemplate($OLDGEN), 'es.cluster.status', '3');
	};
	$rec = fn() => new Reconciler($spec);
	$disc = fn(?Reconciler $r = null) => new Discovery($spec, $r ?? $rec());
	$byName = fn(array $scan) => array_column($scan['proposals'], null, 'name');
	$hostIn = fn(array $p, string $host) => array_values(array_filter($p['hosts'], fn($h) => $h['host'] === $host))[0] ?? null;
	$esMacros = fn(string $host) => ['{$ELASTICSEARCH.SCHEME}' => 'https', '{$ELASTICSEARCH.HOST}' => $host, '{$ELASTICSEARCH.PORT}' => '9200', '{$ELASTICSEARCH.USERNAME}' => 'elastic'];
	$words = Discovery::nameWords($roles);
	$noBackup = function () {};
	$noNote = function (string $n) {};

	/* ======================================================================================
	 *  PURE PARTS
	 * ====================================================================================== */
	$scene = 'names';
	check('<client>-<Role>-<n> gives the client', Discovery::clientFromName('acme-ES-Data-Hot-2', $words) === 'acme');
	check('a client whose name holds a role word keeps it', Discovery::clientFromName('Data-Corp-ES-Data-1', $words) === 'Data-Corp');
	check('a dashed client name is kept whole', Discovery::clientFromName('ztest-disc-Forwarder-1', $words) === 'ztest-disc');
	check('<client>-ES-Cluster gives the client', Discovery::clientFromName('Local-ES-Cluster', $words) === 'Local');
	check('a name that does not follow the pattern gives nothing', Discovery::clientFromName('web01', $words) === null
		&& Discovery::clientFromName('acme-db-1', $words) === null);
	check('the cluster group rule ignores case', Discovery::isClusterGroup('Prod ElasticSearch nodes') && Discovery::isClusterGroup('Elasticsearch clusters')
		&& !Discovery::isClusterGroup('Linux servers'));

	$scene = 'TemplateMove constants are migrate-templates.py\'s';
	$py = (string) file_get_contents(__DIR__.'/../../tools/migrate-templates.py');
	$pyList = function (string $name) use ($py): array {
		preg_match('/^'.$name.'\s*=\s*[\[\{]([^\]\}]*)[\]\}]/ms', $py, $m);
		preg_match_all('/"([^"]+)"/', $m[1] ?? '', $x);
		return $x[1];
	};
	check('MACRO_FIELDS', $pyList('MACRO_FIELDS') === TemplateMove::MACRO_FIELDS, $pyList('MACRO_FIELDS'));
	$fk = $pyList('FORBIDDEN_KEYS');
	sort($fk);
	$mine = TemplateMove::FORBIDDEN_KEYS;
	sort($mine);
	check('FORBIDDEN_KEYS', $fk === $mine, $fk);

	$scene = 'the shared replace cases (tools/test/fixtures/replace-cases.json)';
	$cases = json_decode((string) file_get_contents(__DIR__.'/../../tools/test/fixtures/replace-cases.json'), true)['cases'];
	check('the table is there and not empty', count($cases) >= 8);
	foreach ($cases as $case) {
		$p = TemplateMove::plan($case['snap'], $case['old_tpl'], $case['target'], $case['unlink']);
		$e = $case['expect'];
		$acts = array_column($p['items'], 'action', 'key');
		check($case['name'].': actions', $acts === $e['actions'], $acts);
		check($case['name'].': refusals', count($p['refused']) === $e['refused'], $p['refused']);
		check($case['name'].': unlink and link', $p['unlink'] === $e['unlink'] && $p['link'] === $e['link'], [$p['unlink'], $p['link']]);
		check($case['name'].': macros copied', array_column($p['macros'], 'macro') === $e['macros'], $p['macros']);
		check($case['name'].': left for a person', count($p['manual']) === $e['manual'], $p['manual']);
	}
	$threw = false;
	try { TemplateMove::guard(['hostids' => ['1'], 'templateids_clear' => ['2']]); } catch (\LogicException $x) { $threw = true; }
	check('the guard refuses templateids_clear before any request', $threw);

	$scene = 'proposals (pure)';
	$row = fn(string $host, string $kind, array $over = []) => $over + ['hostid' => (string) crc32($host), 'host' => $host, 'name' => $host, 'kind' => $kind,
		'groups' => [], 'already' => null, 'grp_client' => '', 'tag_client' => '', 'roles' => [], 'ip' => '10.0.0.1', 'shared_ip' => []];
	$rg = Roles::groups($roles);
	$rows = [
		$row('acme', 'cluster', ['groups' => ['Elasticsearch clusters', 'acme-team']]),
		$row('globex-ES-Cluster', 'cluster', ['groups' => ['Elasticsearch clusters'], 'grp_client' => 'globex']),
		$row('initech', 'cluster', ['groups' => ['elasticsearch prod'], 'tag_client' => 'initech']),
		$row('s-group', 'server', ['groups' => ['acme-team', 'ES Data', 'ESNodes']]),
		$row('s-macro', 'server', ['groups' => ['Parser', 'Parsers'], 'grp_client' => 'globex']),
		$row('s-tag', 'server', ['groups' => ['Forwarders'], 'tag_client' => 'initech']),
		$row('globex-Engine-1', 'server', ['groups' => ['Engine', 'Engines']]),
		$row('acme-ES-Master-3', 'server', ['groups' => ['ES Master', 'ESNodes']]),
		$row('lonely', 'server', ['groups' => ['Engine', 'Engines']]),
		$row('stray-es', 'stray', ['groups' => ['Linux servers']]),
	];
	$out = Discovery::proposals($rows, $words, $rg);
	$ps = array_column($out['proposals'], null, 'name');
	check('one proposal per cluster, named after its host', array_keys($ps) === ['acme', 'globex-ES-Cluster', 'initech'], array_keys($ps));
	$src = function (string $c) use ($ps) { return array_column(array_slice($ps[$c]['hosts'], 1), 'source', 'host'); };
	check('a host group only that cluster is in attaches by "group"', ($src('acme')['s-group'] ?? null) === 'group', $src('acme'));
	check('a server named after the cluster attaches by "prefix"', ($src('acme')['acme-ES-Master-3'] ?? null) === 'prefix', $src('acme'));
	check('{$GRP.CLIENT} naming the cluster\'s client attaches by "macro"', ($src('globex-ES-Cluster')['s-macro'] ?? null) === 'macro', $src('globex-ES-Cluster'));
	check('<client>-<Role>-<n> of a <client>-ES-Cluster host attaches by "prefix"', ($src('globex-ES-Cluster')['globex-Engine-1'] ?? null) === 'prefix');
	check('a client tag attaches by "tag"', ($src('initech')['s-tag'] ?? null) === 'tag', $src('initech'));
	check('a server nothing points at is unattached, with why', array_column($out['unattached'], 'host') === ['lonely']
		&& strpos($out['unattached'][0]['source_detail'], 'points at a cluster') !== false, $out['unattached']);
	check('a host with an Elasticsearch template outside such a group is not a cluster', array_column($out['stray'], 'host') === ['stray-es']);
	$amb = Discovery::proposals([$row('a1', 'cluster', ['groups' => ['Elasticsearch clusters'], 'grp_client' => 'x']),
		$row('a2', 'cluster', ['groups' => ['Elasticsearch clusters'], 'grp_client' => 'x']), $row('s', 'server', ['groups' => ['Engine'], 'grp_client' => 'x'])], $words, $rg);
	check('a hint pointing at two clusters attaches to neither, and says so', count($amb['unattached']) === 1
		&& strpos($amb['unattached'][0]['source_detail'], 'more than one cluster') !== false, $amb['unattached']);
	$shared = Discovery::proposals([$row('b1', 'cluster', ['groups' => ['Elasticsearch clusters', 'Team']]), $row('b2', 'cluster', ['groups' => ['Elasticsearch clusters', 'Team']]),
		$row('s', 'server', ['groups' => ['Engine', 'Team']])], $words, $rg);
	check('a host group two clusters share is no hint', count($shared['unattached']) === 1);

	/* ======================================================================================
	 *  THE SCAN, AGAINST THE FAKE ZABBIX
	 * ====================================================================================== */

	$fixture = function () use ($esMacros, $OLDGEN, $OLD_AGENT): void {
		// An existing client the module made: master, cluster, a server — "already a client".
		\Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'master']], 'macros' => ['{$GRP.CLIENT}' => 'acme']]);
		\Zbx::addHost(['host' => 'acme-ES-Cluster', 'groups' => ['acme', Reconciler::CLUSTER_GROUP], 'templates' => Reconciler::clusterTemplates(),
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'cluster']], 'macros' => $esMacros('es.acme')]);
		\Zbx::addHost(['host' => 'acme-ES-Data-1', 'ip' => '10.1.0.1', 'groups' => ['acme', 'ES Data', 'ESNodes'], 'templates' => [Reconciler::agentTemplate()],
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'server']]]);
		// The old page's client, karthick-DC style: every name of the generation before the rename,
		// {$EVP.…} macros on its master, and one IP holding four roles.
		\Zbx::addHost(['host' => 'karthick-DC-Master', 'groups' => ['karthick-DC', Reconciler::LEGACY_MASTERS_GROUP], 'templates' => [MasterTemplate::LEGACY_NAME],
			'tags' => [Reconciler::LEGACY_MANAGED, ['tag' => Reconciler::LEGACY_TAG_PREFIX.'client', 'value' => 'karthick-DC'], ['tag' => Reconciler::LEGACY_TAG_PREFIX.'kind', 'value' => 'master']],
			'macros' => ['{$GRP.CLIENT}' => 'karthick-DC', '{$EVP.CLIENT.TYPE}' => 'On-Prem']]);
		\Zbx::addHost(['host' => 'karthick-DC-ES-Cluster', 'groups' => ['karthick-DC', Reconciler::CLUSTER_GROUP], 'templates' => [Reconciler::LEGACY_CLUSTER_TEMPLATE],
			'tags' => [Reconciler::LEGACY_MANAGED, ['tag' => Reconciler::LEGACY_TAG_PREFIX.'kind', 'value' => 'cluster']], 'macros' => $esMacros('es.kdc')]);
		\Zbx::addHost(['host' => 'karthick-DC-ES-Data-Parser-Forwarder-Engine-1', 'ip' => '10.2.0.1',
			'groups' => ['karthick-DC', 'ES Data', 'ESNodes', 'Parser', 'Parsers', 'Forwarders', 'Engine', 'Engines'], 'templates' => [Reconciler::agentTemplate()],
			'tags' => [Reconciler::LEGACY_MANAGED, ['tag' => Reconciler::LEGACY_TAG_PREFIX.'kind', 'value' => 'server']]]);
		// A cluster made by hand, on a generation no setting knows, with a server in its own team group
		// on the old agent template, a server tagged to it, and one whose role cannot be told.
		\Zbx::addHost(['host' => 'globex-prod', 'ip' => '10.3.0.9', 'groups' => ['Prod Elasticsearch', 'globex'], 'templates' => [$OLDGEN],
			'macros' => $esMacros('es.globex') + ['{$ELASTICSEARCH.PASSWORD}' => ['hunter2', 1]]]);
		\Zbx::addHost(['host' => 'gx-data-a', 'ip' => '10.3.0.1', 'groups' => ['globex', 'ES Data Hot', 'ESNodes'], 'templates' => [$OLD_AGENT]]);
		\Zbx::addHost(['host' => 'gx-fwd', 'ip' => '10.3.0.2', 'groups' => ['Forwarders'], 'tags' => [['tag' => 'client', 'value' => 'globex-prod']]]);
		\Zbx::addHost(['host' => 'globex-prod-ES-Data-7', 'ip' => '10.3.0.3', 'groups' => ['ESNodes']]);
		// The pre-rename generation, by hand, named <client>-ES-Cluster, with a {$GRP.CLIENT} server.
		\Zbx::addHost(['host' => 'initech-ES-Cluster', 'groups' => ['Elasticsearch clusters'], 'templates' => [Reconciler::LEGACY_CLUSTER_TEMPLATE],
			'macros' => $esMacros('es.initech')]);
		\Zbx::addHost(['host' => 'in-parser', 'ip' => '10.4.0.1', 'groups' => ['Parser', 'Parsers'], 'macros' => ['{$GRP.CLIENT}' => 'initech']]);
		// Not proposed: the Elasticsearch template, outside a host group named so.
		\Zbx::addHost(['host' => 'stray-es', 'groups' => ['Linux servers'], 'templates' => [Roles::clusterTemplate()]]);
		// A role host nothing points at, and two that share an IP.
		\Zbx::addHost(['host' => 'lonely', 'ip' => '10.9.0.1', 'groups' => ['Engine', 'Engines']]);
	};

	$begin('scan');
	$fixture();
	$before = count(\Zbx::$wrote);
	$scan = $disc()->scan();
	check('the scan writes nothing', count(\Zbx::$wrote) === $before, \Zbx::$wrote);
	$ps = $byName($scan);
	check('every cluster is proposed, by its client or host name', array_keys($ps) === ['acme', 'globex-prod', 'initech-ES-Cluster', 'karthick-DC'], array_keys($ps));
	check('acme is already a client: listed, never ticked', $ps['acme']['status'] === 'client' && !$ps['acme']['choices']['selected']
		&& !array_filter($ps['acme']['choices']['hosts']));
	check('the old page\'s karthick-DC is recognised as a client and never ticked', $ps['karthick-DC']['status'] === 'client' && !$ps['karthick-DC']['choices']['selected']);
	check('its four-role server is with it', ($hostIn($ps['karthick-DC'], 'karthick-DC-ES-Data-Parser-Forwarder-Engine-1')['already'] ?? null) === 'karthick-DC');
	$g = $ps['globex-prod'];
	check('a cluster made by hand is a new client, ticked', $g['status'] === 'new' && $g['choices']['selected'], [$g['status'], $g['plan']['blockers']]);
	$gc = $hostIn($g, 'globex-prod');
	check('a template name no setting knows is found by its health item, and named', $gc['generation'] === 'unrecognised' && $gc['es_templates'] === [$OLDGEN]);
	check('the pre-rename generation is told as such', $hostIn($ps['initech-ES-Cluster'], 'initech-ES-Cluster')['generation'] === 'legacy');
	check('the server in the cluster\'s own group, by "group"', ($hostIn($g, 'gx-data-a')['source'] ?? null) === 'group');
	check('the server tagged client=globex-prod, by "tag"', ($hostIn($g, 'gx-fwd')['source'] ?? null) === 'tag');
	check('<cluster>-<Role>-<n>, by "prefix"', ($hostIn($g, 'globex-prod-ES-Data-7')['source'] ?? null) === 'prefix');
	check('{$GRP.CLIENT} naming the <client> of <client>-ES-Cluster, by "macro"', ($hostIn($ps['initech-ES-Cluster'], 'in-parser')['source'] ?? null) === 'macro');
	check('a role from its role group, said', $hostIn($g, 'gx-data-a')['roles'] === ['es_data_hot'] && $hostIn($g, 'gx-data-a')['role_source'] === 'group');
	$unk = $hostIn($g, 'globex-prod-ES-Data-7');
	check('in ESNodes only: role unknown, said, not guessed, not ticked', $unk['roles'] === [] && !$g['choices']['hosts'][$unk['hostid']]
		&& (bool) preg_grep('/Role unknown: in ES/', $unk['gaps']), $unk['gaps']);
	check('a single-role family group gives its role', $hostIn($g, 'gx-fwd')['roles'] === ['forwarder']);
	check('the old agent template, found by the keys it shares, is to be moved', ($g['plan']['moves'][$hostIn($g, 'gx-data-a')['hostid']]['mode'] ?? null) === 'move',
		$g['plan']['moves'][$hostIn($g, 'gx-data-a')['hostid']] ?? null);
	check('the unattached role host is listed with why', array_column($scan['unattached'], 'host') === ['lonely'], array_column($scan['unattached'], 'source_detail', 'host'));
	check('the stray Elasticsearch host is named in a note', (bool) preg_grep('/stray-es/', $scan['notes']), $scan['notes']);
	$keys = array_column($g['missing'], 'key');
	check('Missing: no client group', in_array('client_group', $keys, true), $keys);
	check('Missing: a secret ElasticPro has not been given', in_array('password_core', $keys, true), $keys);
	check('Missing: jump host unknown (asked by the Zabbix server directly)', in_array('jump', $keys, true));
	check('Missing: the unknown generation still linked', in_array('old_generation', $keys, true));
	check('Missing: the role that cannot be told', in_array('role', $keys, true));
	check('karthick-DC lists what it lacks too: an old generation', in_array('old_generation', array_column($ps['karthick-DC']['missing'], 'key'), true));
	check('a secret macro value is never in the scan', strpos(json_encode($scan), 'hunter2') === false);

	$scene = 'the write list';
	$w = $g['plan']['writes'];
	$ops = array_column($w, 0);
	check('a backup first', ($ops[0] ?? '') === 'backup');
	check('the client group is created', (bool) preg_grep('/Create host group "globex-prod"/', array_column($w, 1)));
	$link = implode(' ', array_column(array_filter($w, fn($x) => $x[0] === 'link'), 1));
	foreach (Reconciler::clusterTemplates() as $t) {
		check("the write list names \"$t\"", strpos($link, '"'.$t.'"') !== false, $link);
	}
	check('…linked directly, in one step', strpos($link, 'directly and in one step') !== false);
	check('the unknown generation stays linked beside', strpos($link, 'Stays linked beside') !== false && strpos($link, $OLDGEN) !== false, $link);
	check('the master host is made', in_array('master', $ops, true));
	check('a cluster with its own password: the user macro is written on it', (bool) preg_grep('/as the cluster host.*\{\$ELASTICSEARCH\.SCHEME\/HOST\/PORT\/USERNAME\}/', array_column($w, 1)));
	check('the server move is listed: unlink without clear, link, adopt', (bool) preg_grep('/without clear.*adopted/', array_column($w, 1)), array_column($w, 1));
	check('nothing is renamed: no rename in the list, and it says so', !in_array('rename', $ops, true) && (bool) preg_grep('/no host name is changed/', array_column($w, 1)));
	check('each server line names its IP, host name and server name', (bool) preg_grep('/host name "gx-data-a" \(unchanged\), server name unknown.*IP 10\.3\.0\.1, role ES Data Hot/', array_column($w, 1)), array_column($w, 1));
	check('the server with no IP-sharing, two roles found, is in the plan', count($g['plan']['servers']) === 2, array_keys($g['plan']['servers']));

	/* ======================================================================================
	 *  ADOPTING
	 * ====================================================================================== */
	$begin('adopt globex-prod');
	$fixture();
	$scan = $disc()->scan();
	$g = $byName($scan)['globex-prod'];
	$ids = array_column($g['hosts'], 'hostid', 'host');
	$itemsBefore = array_keys(array_filter(\Zbx::$items, fn($i) => in_array($i['hostid'], [$ids['globex-prod'], $ids['gx-data-a']], true)));
	$c = Discovery::choicesFrom($g, ['sel' => [$g['id'] => '1'], 'host' => [$g['id'] => [$ids['globex-prod'] => '1', $ids['gx-data-a'] => '1', $ids['gx-fwd'] => '1']],
		'move' => [$g['id'] => [$ids['gx-data-a'] => '1']], 'es_url' => [$g['id'] => 'https://es.globex:9200']], $roles);
	$backups = 0;
	$r = $rec();
	$res = $disc($r)->apply($g, $c, null, function () use (&$backups) { $backups++; }, $noNote);
	check('adopted without an error', $res['error'] === null, $res);
	check('one backup, before the writes', $backups === 1);
	$cl = \Zbx::byHost('globex-prod');
	$have = array_column($cl['parentTemplates'], 'host');
	check('the cluster host keeps its name', $cl !== null && \Zbx::byHost('globex-prod-ES-Cluster') === null);
	foreach (Reconciler::clusterTemplates() as $t) {
		check("the cluster host carries \"$t\" directly", in_array($t, $have, true), $have);
	}
	check('the unknown generation is still linked beside', in_array($OLDGEN, $have, true));
	check('the cluster host is in its client group and the clusters group', in_array('globex-prod', array_column($cl['hostgroups'], 'name'), true)
		&& in_array(Reconciler::CLUSTER_GROUP, array_column($cl['hostgroups'], 'name'), true));
	check('…tagged as the client\'s cluster, keeping its own name', in_array('ep-client=globex-prod', \Zbx::tagsOf($cl['hostid']), true)
		&& in_array('ep-kind=cluster', \Zbx::tagsOf($cl['hostid']), true) && in_array('ep-name=own', \Zbx::tagsOf($cl['hostid']), true));
	check('a host made by hand is not marked managed (so this page never deletes it)', !Reconciler::isManaged($cl) && !Reconciler::isManaged(\Zbx::byHost('gx-data-a')));
	$m = \Zbx::byHost('globex-prod-Master');
	check('the master host is made, managed, in both groups', $m !== null && Reconciler::isManaged($m)
		&& !array_diff(['globex-prod', Reconciler::MASTERS_GROUP], array_column($m['hostgroups'], 'name')));
	$s = \Zbx::byHost('gx-data-a');
	check('the server keeps its name, gets its role groups and the client group', $s !== null
		&& !array_diff(['globex-prod', 'ES Data Hot', 'ESNodes'], array_column($s['hostgroups'], 'name')));
	check('the server moved: old agent template unlinked, the ElasticPro one linked', !in_array($OLD_AGENT, array_column($s['parentTemplates'], 'host'), true)
		&& in_array(Reconciler::agentTemplate(), array_column($s['parentTemplates'], 'host'), true));
	$itemsAfter = array_keys(array_filter(\Zbx::$items, fn($i) => in_array($i['hostid'], [$ids['globex-prod'], $ids['gx-data-a']], true)));
	check('every item id the two hosts had is still there (history kept)', !array_diff($itemsBefore, $itemsAfter), array_diff($itemsBefore, $itemsAfter));
	$adopted = array_filter(\Zbx::$items, fn($i) => $i['hostid'] === $ids['gx-data-a'] && $i['key_'] === 'agent.ping');
	$tplItem = array_values(array_filter(\Zbx::$items, fn($i) => $i['hostid'] === (string) \Zbx::addTemplate(Reconciler::agentTemplate()) && $i['key_'] === 'agent.ping'))[0];
	check('the same key was adopted by the new template, same id', count($adopted) === 1 && array_values($adopted)[0]['templateid'] === $tplItem['itemid']);
	check('a macro the kept item uses was copied to the host', (\Zbx::macrosOf($ids['gx-data-a'])['{$OLD.URL}'] ?? null) === 'http://x');
	check('the server carries {$GRP.CLIENT}', (\Zbx::macrosOf($ids['gx-data-a'])['{$GRP.CLIENT}'] ?? null) === 'globex-prod');
	check('no host was deleted', !in_array('Host.delete', \Zbx::$wrote, true));
	check('nothing was unlinked with clear', !array_filter(\Zbx::calls('Host.massRemove'), fn($p) => isset($p['templateids_clear'])));
	check('the secret password on the cluster host was left alone', \Zbx::macrosOf($ids['globex-prod'])['{$ELASTICSEARCH.PASSWORD}'] === 'hunter2');
	check('the role-unknown host was not touched', array_column(\Zbx::byHost('globex-prod-ES-Data-7')['hostgroups'], 'name') === ['ESNodes']);
	check('karthick-DC was not written', \Zbx::tagsOf(\Zbx::byHost('karthick-DC-ES-Cluster')['hostid']) === [Reconciler::LEGACY_TAG_PREFIX.'kind=cluster', Reconciler::LEGACY_MANAGED['tag'].'='.Reconciler::LEGACY_MANAGED['value']]);
	$again = $byName($disc()->scan());
	check('rescanned: globex-prod is a client now', $again['globex-prod']['status'] === 'client' || $again['globex-prod']['status'] === 'existing', $again['globex-prod']['status']);

	$begin('host name = IP, visible name = server name');
	$fixture();
	\Zbx::addHost(['host' => '10.4.0.7', 'name' => 'in-parser-07', 'ip' => '10.4.0.7', 'groups' => ['Parser', 'Parsers'], 'macros' => ['{$GRP.CLIENT}' => 'initech']]);
	\Zbx::addHost(['host' => 'in-kafka-1', 'ip' => '10.4.0.8', 'groups' => ['Kafka brokers'], 'macros' => ['{$GRP.CLIENT}' => 'initech']]);
	\Zbx::addHost(['host' => 'in-kafka-1x', 'ip' => '10.4.0.9', 'groups' => ['ESNodes'], 'templates' => ['Apache Kafka by JMX'], 'macros' => ['{$GRP.CLIENT}' => 'initech']]);
	$scan = $disc()->scan();
	$i = $byName($scan)['initech-ES-Cluster'];
	$named = $hostIn($i, '10.4.0.7');
	check('a role host named by IP with a visible name: the visible name is its server name', $named !== null && $named['server_name'] === 'in-parser-07');
	check('a host name is never taken for a server name', $hostIn($i, 'in-parser')['server_name'] === '' && (bool) preg_grep('/Server name unknown/', $hostIn($i, 'in-parser')['gaps']));
	check('Missing lists the server name it does not know', in_array('server_name', array_column($i['missing'], 'key'), true));
	$k = $hostIn($i, 'in-kafka-1');
	check('kafka is not a role: placed under Forwarder, flagged "confirm", never silently decided', $k !== null && $k['roles'] === ['forwarder'] && $k['role_source'] === 'kafka'
		&& $i['choices']['confirm'][$k['hostid']] === 'kafka' && (bool) preg_grep('/confirm Forwarder or Parser/', $k['gaps']), $k);
	check('…and Missing asks to confirm it', in_array('kafka', array_column($i['missing'], 'key'), true));
	$k2 = $hostIn($i, 'in-kafka-1x');
	check('a Kafka host whose role group says ES keeps that role: nothing is suggested over a fact', $k2['roles'] === [] || $k2['role_source'] !== 'kafka');
	$ids = array_column($i['hosts'], 'hostid', 'host');
	$c = Discovery::choicesFrom($i, ['sel' => [$i['id'] => '1'], 'host' => [$i['id'] => [$ids['initech-ES-Cluster'] => '1', $ids['in-parser'] => '1', $ids['10.4.0.7'] => '1',
		$ids['in-kafka-1'] => '1', $ids['in-kafka-1x'] => '1']], 'role' => [$i['id'] => [$ids['in-kafka-1'] => ['parser'], $ids['in-kafka-1x'] => ['kafka:forwarder']]],
		'es_url' => [$i['id'] => 'https://es.initech:9200']], $roles);
	$res = $disc()->apply($i, $c, null, $noBackup, $noNote);
	check('adopted', $res['error'] === null, $res);
	check('no host name changed: in-parser, 10.4.0.7, in-kafka-1 are all still there', \Zbx::byHost('in-parser') !== null && \Zbx::byHost('10.4.0.7') !== null && \Zbx::byHost('in-kafka-1') !== null);
	check('visible names left as they were', \Zbx::byHost('in-parser')['name'] === 'in-parser' && \Zbx::byHost('10.4.0.7')['name'] === 'in-parser-07');
	check('the confirmed Kafka host is a Parser, with nothing left to confirm', in_array('Parser', array_column(\Zbx::byHost('in-kafka-1')['hostgroups'], 'name'), true)
		&& !in_array('ep-confirm=kafka', \Zbx::tagsOf(\Zbx::byHost('in-kafka-1')['hostid']), true));
	check('the one left as suggested is a Forwarder tagged ep-confirm=kafka', in_array('Forwarders', array_column(\Zbx::byHost('in-kafka-1x')['hostgroups'], 'name'), true)
		&& in_array('ep-confirm=kafka', \Zbx::tagsOf(\Zbx::byHost('in-kafka-1x')['hostid']), true));
	$rr = $rec();
	$nowI = $rr->current('initech-ES-Cluster');
	check('…and the client\'s page lists "Role to confirm"', in_array('role_confirm', array_column(Missing::forClient($nowI, [], [], null, false, ['source' => 'default']), 'key'), true));
	$fI = (new ClientState($spec, $rr))->formFor('initech-ES-Cluster');
	check('the client form carries the flag, so a save keeps it until a role is picked', str_contains($fI['servers'], '"confirm":"kafka"'));
	check('no host.update ever carried a host name', !array_filter(\Zbx::calls('Host.update'), fn($p) => array_key_exists('host', $p)));
	$roleIds = array_column(Roles::allRoles($roles), 'id');
	check('the roles: ES, Parser, Forwarder, Engine, AIML, Trino (ULM), SOAR — and no Kafka', !array_diff(['es_data', 'parser', 'forwarder', 'engine', 'aiml', 'trino', 'soar'], $roleIds)
		&& !in_array('kafka', $roleIds, true));

	$begin('karthick-DC is never adopted, even ticked');
	$fixture();
	$scan = $disc()->scan();
	$k = $byName($scan)['karthick-DC'];
	$c = Discovery::choicesFrom($k, ['sel' => [$k['id'] => '1'], 'host' => [$k['id'] => array_fill_keys(array_column($k['hosts'], 'hostid'), '1')]], $roles);
	$wrote = count(\Zbx::$wrote);
	$res = $disc()->apply($k, $c, null, function () { throw new \Exception('no backup may be taken for this'); }, $noNote);
	check('refused, before any write and before the backup', $res['error'] !== null && count(\Zbx::$wrote) === $wrote, [$res['error'], array_slice(\Zbx::$wrote, $wrote)]);

	$begin('WriteCheck: a user whose rights do not cover it');
	$fixture();
	\Zbx::groupId('globex-prod');   // the group exists, but read-only to them
	\Zbx::$readOnlyGroups = ['globex-prod'];
	$refusals = function (array $needs): array {
		$r = WriteCheck::refusals($needs, WriteCheck::rights($needs), false);
		return $r ? [WriteCheck::message($r)] : [];
	};
	$scan = $disc()->scan($refusals);
	$g = $byName($scan)['globex-prod'];
	check('the refusal is shown on the proposal, which is not ticked', !$g['choices']['selected'] && (bool) preg_grep('/your Zabbix rights do not cover/', $g['plan']['blockers']), $g['plan']['blockers']);
	$wrote = count(\Zbx::$wrote);
	$res = $disc()->apply($g, Discovery::choicesFrom($g, ['sel' => [$g['id'] => '1'], 'host' => [$g['id'] => [$hostIn($g, 'globex-prod')['hostid'] => '1']],
		'es_url' => [$g['id'] => 'https://es.globex:9200']], $roles), $refusals, $noBackup, $noNote);
	check('ticked anyway: skipped with the reason, nothing written', $res['error'] !== null && strpos($res['error'], 'Nothing was changed') === 0
		&& count(\Zbx::$wrote) === $wrote, $res['error']);

	$begin('a template move the plan refuses');
	$fixture();
	// The old agent template's agent.ping as text: the link would convert it.
	foreach (\Zbx::$items as $id => $it) {
		if ($it['key_'] === 'agent.ping' && $it['hostid'] === (string) \Zbx::addTemplate($OLD_AGENT)) { \Zbx::$items[$id]['value_type'] = '4'; }
		if ($it['key_'] === 'agent.ping' && $it['hostid'] === \Zbx::byHost('gx-data-a')['hostid']) { \Zbx::$items[$id]['value_type'] = '4'; }
	}
	$g = $byName($disc()->scan())['globex-prod'];
	check('the host is left out, naming the script', (bool) preg_grep('/cannot be moved.*migrate-templates\.py/', $g['plan']['skipped']), $g['plan']['skipped']);
	check('the rest of the proposal still stands', isset($g['plan']['cluster']) && $g['plan']['cluster'] !== null);

	/* ======================================================================================
	 *  THE PASSWORD
	 * ====================================================================================== */
	$scene = 'keep-marker';
	check('a password counts only with es_password_action=set', ClusterSecret::fromPost('set', 'pw') === 'pw' && ClusterSecret::fromPost('keep', 'pw') === ''
		&& ClusterSecret::fromPost('', 'autofilled') === '');
	check('a new client\'s form keeps its password as a Zabbix secret', $spec->newForm()['es_password_mode'] === 'zabbix');

	$scene = 'hand-over to the core';
	$secret = str_repeat('k', 40);
	$sign = fn(string $k, string $b) => hash_hmac('sha256', $b, trim($k));
	$sent = null;
	$core = function (string $url, string $body, array $headers, bool $tls) use (&$sent, $secret, $sign) {
		$sent = ['url' => $url, 'body' => $body, 'headers' => $headers];
		$q = json_decode($body, true);
		$raw = json_encode(['ok' => true, 'cluster' => $q['cluster'], 'ts' => $q['ts'], 'nonce' => $q['nonce']]);
		return ['status' => 200, 'raw' => $raw, 'signature' => $sign($secret, $q['ts']."\n".$q['nonce']."\n".$raw), 'error' => ''];
	};
	$deps = ['settings' => fn() => ['core_url' => 'http://ep-core:8765', 'secret' => $secret, 'verify_tls' => true, 'trusted_plain_hosts' => ['ep-core']],
		'transport' => $core, 'sign' => $sign, 'clock' => fn() => 1790000000, 'nonce' => fn() => str_repeat('a', 32)];
	$r = ClusterSecret::send('globex-prod', 'elastic', 'p@ss', 'alice', $deps);
	check('sent and answered', $r['ok'] === true, $r);
	check('to the cluster-secret path', $sent['url'] === 'http://ep-core:8765/sso/zabbix/cluster-secret');
	$hdr = preg_grep('/^X-Zabbix-Module-Signature: /', $sent['headers']);
	check('signed over the exact body', $hdr && substr(array_values($hdr)[0], 27) === $sign($secret, $sent['body']));
	check('the body is {cluster, user, password, set_by, ts, nonce}', array_keys(json_decode($sent['body'], true)) === ['cluster', 'user', 'password', 'set_by', 'ts', 'nonce']);
	$forged = ['transport' => fn($u, $b, $h, $t) => ['status' => 200, 'raw' => '{"ok":true}', 'signature' => 'bad', 'error' => '']] + $deps;
	check('an unsigned answer is not taken as success', ClusterSecret::send('globex-prod', 'elastic', 'p@ss', 'alice', $forged)['ok'] === false);
	$sent = null;
	$plain = ['settings' => fn() => ['core_url' => 'http://10.0.0.5:8765', 'secret' => $secret, 'verify_tls' => true, 'trusted_plain_hosts' => ['ep-core']]] + $deps;
	$r = ClusterSecret::send('globex-prod', 'elastic', 'p@ss', 'alice', $plain);
	check('over plain HTTP to an address, the password is not sent at all', $r['ok'] === false && $sent === null, $r);
	check('the error never carries the password', strpos(json_encode($r), 'p@ss') === false);
	ClusterSecret::record('globex-prod', 'globex-prod', 'alice', true, ['ok' => true], 1790000000);
	$kept = ClusterSecret::of('globex-prod');
	check('what is kept is who and when, never the password', $kept['by'] === 'alice' && $kept['core'] === true && strpos(json_encode(Store::read(ClusterSecret::FILE)), 'p@ss') === false);

	$begin('Missing on a client\'s page');
	\Zbx::addHost(['host' => 'm-ES-Cluster', 'groups' => ['m', Reconciler::CLUSTER_GROUP], 'templates' => [Roles::clusterTemplate(), Reconciler::LEGACY_CLUSTER_TEMPLATE],
		'macros' => ['{$ELASTICSEARCH.HOST}' => 'es.m', '{$ELASTICSEARCH.PASSWORD}' => ['x', 1]]]);
	$r = $rec();
	$now = $r->current('m');
	$miss = Missing::forClient($now, $r->macros($now['cluster']['hostid']), [], null, false);
	$keys = array_column($miss, 'key');
	check('macros not set, password ElasticPro lacks, old generation, templates not linked, no role hosts',
		!array_diff(['macros', 'password_core', 'old_generation', 'linked', 'role_hosts'], $keys), $keys);
	$miss = Missing::forClient($now, $r->macros($now['cluster']['hostid']), [], ['core' => true, 'at' => 1, 'by' => 'alice'], false);
	check('once handed over, the password is not missing', !in_array('password_core', array_column($miss, 'key'), true));

	// A cluster template under a name no setting knows, beside the current one: one definition
	// (Reconciler::besideTemplates) for the list, the client page and Missing — not "none".
	\Zbx::addHost(['host' => 'u-ES-Cluster', 'groups' => ['u', Reconciler::CLUSTER_GROUP], 'templates' => [Roles::clusterTemplate(), $OLDGEN],
		'macros' => ['{$ELASTICSEARCH.HOST}' => 'es.u']]);
	$r = $rec();
	$now = $r->current('u');
	$hid = (string) $now['cluster']['hostid'];
	$sig = Discovery::signatureTemplates([$hid]);
	check('signatureTemplates finds the template that gives the health check, by its item', ($sig['templates'][$hid] ?? null) === $OLDGEN && isset($sig['hosts'][$hid]), $sig);
	check('signatureTemplates of no hosts asks nothing', Discovery::signatureTemplates([]) === ['hosts' => [], 'templates' => []]);
	$names = array_column($now['cluster']['parentTemplates'], 'host');
	check('besideTemplates: the unknown one is beside, apart from known older generations',
		Reconciler::besideTemplates($names, $OLDGEN) === ['known' => [], 'unknown' => [$OLDGEN]], Reconciler::besideTemplates($names, $OLDGEN));
	check('besideTemplates: a known older generation is known, and never counted twice',
		Reconciler::besideTemplates([Roles::clusterTemplate(), Reconciler::LEGACY_CLUSTER_TEMPLATE], Reconciler::LEGACY_CLUSTER_TEMPLATE)
			=== ['known' => [Reconciler::LEGACY_CLUSTER_TEMPLATE], 'unknown' => []]);
	check('besideTemplates: the current template is never beside itself, nor a signature not linked',
		Reconciler::besideTemplates([Roles::clusterTemplate()], Roles::clusterTemplate()) === ['known' => [], 'unknown' => []]
		&& Reconciler::besideTemplates([Roles::clusterTemplate()], $OLDGEN) === ['known' => [], 'unknown' => []]);
	$miss = Missing::forClient($now, $r->macros($hid), [], null, false, [], $sig['templates'][$hid]);
	$u = array_values(array_filter($miss, fn($m) => $m['key'] === 'unknown_template'));
	check('Missing names the unknown template still linked, and says it stays', count($u) === 1 && str_contains($u[0]['text'], $OLDGEN)
		&& str_contains($u[0]['fix'], 'stays linked') && !in_array('old_generation', array_column($miss, 'key'), true), $miss);
	$hidden = fn(array $by) => array_values(array_filter(Missing::forClient($now, $r->macros($hid), [], null, false, ['source' => 'template', 'hidden_by' => $by]),
		fn($m) => $m['key'] === 'credential_hidden'))[0] ?? null;
	check('hidden by an older ElasticPro cluster template: the fix is "Write master template"', str_contains($hidden([ClusterTemplate::name()])['fix'] ?? '', 'Write master template'));
	check('hidden by someone else\'s template: theirs to change, or this cluster\'s own password', !str_contains($hidden(['Some stock ES template'])['fix'] ?? 'Write master template', 'Write master template'));
	check('without the lookup, Missing says nothing it does not know', !in_array('unknown_template', array_column(Missing::forClient($now, $r->macros($hid), [], null, false), 'key'), true));

	/* ======================================================================================
	 *  TWO PROXIES: THE CLUSTER HOST'S, AND ONE FOR ALL THE LINUX ROLE HOSTS
	 * ====================================================================================== */
	$begin('proxies');
	$p1 = \Zbx::addProxy('proxy-dc1');
	$p2 = \Zbx::addProxy('proxy-dc2');
	\Zbx::addHost(['host' => 'px-es', 'groups' => ['Elasticsearch clusters', 'px'], 'templates' => [Roles::clusterTemplate()], 'macros' => $esMacros('es.px'),
		'monitored_by' => ZBX_MONITORED_BY_PROXY, 'proxyid' => $p1]);
	\Zbx::addHost(['host' => '10.6.0.1', 'name' => 'px-data-1', 'ip' => '10.6.0.1', 'groups' => ['px', 'ES Data', 'ESNodes'], 'monitored_by' => ZBX_MONITORED_BY_PROXY, 'proxyid' => $p2]);
	\Zbx::addHost(['host' => '10.6.0.2', 'name' => 'px-parser-1', 'ip' => '10.6.0.2', 'groups' => ['px', 'Parser', 'Parsers']]);
	$px = $byName($disc()->scan())['px-es'];
	check('both proxy choices start at the Zabbix server', ($px['plan']['form']['monitored_by'] ?? null) === '' && ($px['plan']['form']['servers_monitored_by'] ?? null) === 'server');
	$pm = array_column(array_filter($px['missing'], fn($m) => $m['key'] === 'proxy'), 'text');
	check('a host monitored by a proxy today is said under Missing, with what adopting would do', (bool) preg_grep('/^px-es is monitored by proxy proxy-dc1; adoption would move it to the Zabbix server unless you choose that proxy/', $pm)
		&& (bool) preg_grep('/^10\.6\.0\.1 is monitored by proxy proxy-dc2/', $pm) && !preg_grep('/10\.6\.0\.2/', $pm), $pm);
	check('…and nothing is refused for it', !preg_grep('/Proxy not chosen/', $px['plan']['blockers']));
	check('a cluster on the default credential: no user macro promised on it', (bool) preg_grep('/as the cluster host.*\{\$ELASTICSEARCH\.SCHEME\/HOST\/PORT\} \(user and password from the default credential\)/', array_column($px['plan']['writes'], 1)),
		array_column($px['plan']['writes'], 1));
	check('the default write list says which hosts would move to the Zabbix server', (bool) preg_grep('/Set proxy on "px-es": proxy proxy-dc1 → the Zabbix server/', array_column($px['plan']['writes'], 1)));
	$c = Discovery::choicesFrom($px, ['sel' => [$px['id'] => '1'], 'host' => [$px['id'] => array_fill_keys(array_column($px['hosts'], 'hostid'), '1')],
		'es_url' => [$px['id'] => 'https://es.px:9200'], 'proxy_es' => 'proxy:proxy-dc1', 'proxy_linux' => 'proxy:proxy-dc2'], $roles);
	$plan = $disc()->plan($px, $c);
	check('chosen: the write list says "Set proxy on" only the host that moves', (bool) preg_grep('/Set proxy on "10\.6\.0\.2": Zabbix server → proxy:proxy-dc2/', array_column($plan['writes'], 1))
		&& !preg_grep('/Set proxy on "px-es"/', array_column($plan['writes'], 1)), array_column($plan['writes'], 1));
	$res = $disc()->apply($px, $c, null, $noBackup, $noNote);
	check('adopted', $res['error'] === null, $res);
	check('the Linux hosts are on proxy-dc2, the cluster host stays on proxy-dc1', \Zbx::byHost('10.6.0.2')['proxyid'] === $p2 && \Zbx::byHost('10.6.0.1')['proxyid'] === $p2
		&& \Zbx::byHost('px-es')['proxyid'] === $p1);
	$m = \Zbx::macrosOf(\Zbx::byHost('px-es-Master')['hostid']);
	check('both choices are kept on the master host', ($m['{$EP.MONITORED.BY}'] ?? null) === 'proxy:proxy-dc1' && ($m['{$EP.SERVERS.MONITORED.BY}'] ?? null) === 'proxy:proxy-dc2', $m);

	/* ======================================================================================
	 *  THE DEFAULT CREDENTIAL
	 * ====================================================================================== */
	$begin('the default credential');
	$scene = 'the default credential';
	$tplMacros = array_column(ClusterTemplate::export()['zabbix_export']['templates'][0]['macros'], 'macro');
	check('the ElasticPro cluster template no longer defines the credential macros (they would hide the default)',
		!in_array('{$ELASTICSEARCH.PASSWORD}', $tplMacros, true) && !in_array('{$ELASTICSEARCH.USERNAME}', $tplMacros, true) && ClusterTemplate::VERSION === '2');
	$okCore = ['settings' => fn() => ['core_url' => 'http://ep-core:8765', 'secret' => str_repeat('k', 40), 'verify_tls' => true, 'trusted_plain_hosts' => ['ep-core']],
		'transport' => function ($u, $b, $h, $t) { $q = json_decode($b, true); $raw = json_encode(['ok' => true, 'cluster' => $q['cluster'], 'ts' => $q['ts'], 'nonce' => $q['nonce']]);
			return ['status' => 200, 'raw' => $raw, 'signature' => hash_hmac('sha256', $q['ts']."\n".$q['nonce']."\n".$raw, str_repeat('k', 40)), 'error' => '']; },
		'sign' => fn($k, $b) => hash_hmac('sha256', $b, trim($k)), 'clock' => fn() => 1790000000, 'nonce' => fn() => str_repeat('b', 32)];
	check('none at first', DefaultCredential::current()['password'] === false);
	$lines = DefaultCredential::save('svc-elastic', 'def@ult', 'alice', $okCore);
	$g = array_column(\Zbx::$globals, null, 'macro');
	check('saved as global macros: the user as text, the password as Secret text', ($g['{$ELASTICSEARCH.USERNAME}']['value'] ?? '') === 'svc-elastic'
		&& (int) ($g['{$ELASTICSEARCH.PASSWORD}']['type'] ?? 0) === ZBX_MACRO_TYPE_SECRET, $g);
	check('handed to the core as cluster "*", recorded without the password', (bool) preg_grep('/handed to ElasticPro/', $lines)
		&& ClusterSecret::of('*')['core'] === true && strpos(json_encode(Store::read(ClusterSecret::FILE)), 'def@ult') === false);
	check('read back: set, user known, never the password', DefaultCredential::current()['password'] === true && DefaultCredential::current()['user'] === 'svc-elastic'
		&& strpos(json_encode(DefaultCredential::current()), 'def@ult') === false);
	$calls = count(\Zbx::calls('UserMacro.updateGlobal'));
	DefaultCredential::save('svc-elastic', ClusterSecret::fromPost('keep', 'autofill'), 'bob', $okCore);
	check('the keep-marker: a save that only names the user never touches the password', count(\Zbx::calls('UserMacro.updateGlobal')) === $calls
		&& ClusterSecret::of('*')['by'] === 'alice');
	\Zbx::addHost(['host' => 'c-default', 'groups' => ['Elasticsearch clusters'], 'templates' => [Roles::clusterTemplate()], 'macros' => $esMacros('es.d')]);
	\Zbx::addHost(['host' => 'c-own', 'groups' => ['Elasticsearch clusters'], 'templates' => [Roles::clusterTemplate()], 'macros' => $esMacros('es.o') + ['{$ELASTICSEARCH.PASSWORD}' => ['x', 1]]]);
	$stock = \Zbx::addTemplate('Some stock ES template');
	\Zbx::addMacro($stock, '{$ELASTICSEARCH.PASSWORD}', '', 0);
	\Zbx::addHost(['host' => 'c-hidden', 'groups' => ['Elasticsearch clusters'], 'templates' => [Roles::clusterTemplate(), 'Some stock ES template'], 'macros' => $esMacros('es.h')]);
	$u = DefaultCredential::usage();
	check('"Used by N of M clusters · K override", and the one a template hides', $u['clusters'] === 3 && $u['default'] === 1 && $u['override'] === 1 && $u['template'] === 1
		&& $u['hidden_by'] === ['c-hidden'], $u);
	$scan = $disc()->scan();
	$byN = $byName($scan);
	check('a cluster with no password of its own is adopted on the default: nothing written on the host', ($byN['c-default']['plan']['form']['es_password_mode'] ?? null) === 'default'
		&& !in_array('credential', array_column($byN['c-default']['missing'], 'key'), true), $byN['c-default']['plan']['form']['es_password_mode'] ?? null);
	check('a cluster with its own secret keeps it as its own', ($byN['c-own']['plan']['form']['es_password_mode'] ?? null) === 'zabbix');
	check('a template hiding the default is named in Missing', in_array('credential_hidden', array_column($byN['c-hidden']['missing'], 'key'), true));
	$cd = $byN['c-default'];
	$res = $disc()->apply($cd, $cd['choices'], null, $noBackup, $noNote);
	check('adopted on the default', $res['error'] === null, $res);
	$mm = \Zbx::macrosOf(\Zbx::byHost('c-default')['hostid']);
	check('…the host defines neither credential macro, so it inherits both', !isset($mm['{$ELASTICSEARCH.PASSWORD}']) && !isset($mm['{$ELASTICSEARCH.USERNAME}']), $mm);
	// Switching a cluster with its own password to the default takes its own off.
	$own = $byN['c-own'];
	$res = $disc()->apply($own, $own['choices'], null, $noBackup, $noNote);
	$r = $rec();
	$form = ClientState::plain((new ClientState($spec, $r))->formFor('c-own'));
	['client' => $toDefault] = $spec->fromForm(array_merge($form, ['es_password_mode' => 'default']));
	$rr = $rec();
	$rr->apply($toDefault);
	$mo = \Zbx::macrosOf(\Zbx::byHost('c-own')['hostid']);
	check('switched to the default: its own password and user removed, and said', !isset($mo['{$ELASTICSEARCH.PASSWORD}']) && !isset($mo['{$ELASTICSEARCH.USERNAME}'])
		&& (bool) preg_grep('/now uses the default Elasticsearch credential/', $rr->done()), [$mo, $rr->done()]);

	$begin('no default, a cluster of its own: the switch is refused');
	\Zbx::addHost(['host' => 'c-own2', 'groups' => ['Elasticsearch clusters'], 'templates' => [Roles::clusterTemplate()], 'macros' => $esMacros('es.o2') + ['{$ELASTICSEARCH.PASSWORD}' => ['x', 1]]]);
	$o2 = $byName($disc()->scan())['c-own2'];
	$disc()->apply($o2, $o2['choices'], null, $noBackup, $noNote);
	$form = ClientState::plain((new ClientState($spec, $rec()))->formFor('c-own2'));
	['client' => $toDefault] = $spec->fromForm(array_merge($form, ['es_password_mode' => 'default']));
	$r = $rec();
	check('refused before anything is written', (bool) preg_grep('/default Elasticsearch credential is not set/', $r->problems($toDefault, $r->current('c-own2'))));
	$miss = $byName($disc()->scan())['c-own2'];
	$rr = $rec();
	$now = $rr->current('c-own2');
	$m = Missing::forClient($now, $rr->macros($now['cluster']['hostid']), [], null, false, ['source' => 'none']);
	check('Missing: "no credential: set the default or this cluster\'s"', in_array('credential', array_column($m, 'key'), true));


	/* ======================================================================================
	 *  A CLUSTER HOST WITH NO ES URL ON IT: THE URL TYPED ON THE PAGE
	 * ====================================================================================== */
	$begin('typed URL for a cluster host without macros');
	\Zbx::addHost(['host' => 'nm-ES-Cluster', 'groups' => ['Elasticsearch clusters'], 'templates' => [Roles::clusterTemplate()]]);
	$nm = $byName($disc()->scan())['nm-ES-Cluster'];
	$nmId = $hostIn($nm, 'nm-ES-Cluster')['hostid'];
	check('the scan refuses it and does not tick it', !$nm['choices']['selected'] && $nm['plan']['blockers'] !== [] && $nm['choices']['hosts'][$hostIn($nm, 'nm-ES-Cluster')['hostid']] === false, [$nm['plan']['skipped'], $nm['plan']['blockers']]);
	// What the page posts: the hidden host input now carries the real host id's "1" for this row.
	$post = fn(string $url) => ['sel' => [$nm['id'] => '1'], 'host' => [$nm['id'] => [$nmId => '1']], 'es_url' => [$nm['id'] => $url]];
	$bad = $disc()->plan($nm, Discovery::choicesFrom($nm, $post('not a url'), $roles));
	check('a bad URL stays refused, with the reason', $bad['cluster'] === null && $bad['blockers'] !== [] && (bool) preg_grep('/type it as scheme:\/\/host:port/', $bad['skipped']), [$bad['blockers'], $bad['skipped']]);
	$wrote = count(\Zbx::$wrote);
	$res = $disc()->apply($nm, Discovery::choicesFrom($nm, $post('not a url'), $roles), null, $noBackup, $noNote);
	check('…and adopting it writes nothing', $res['error'] !== null && count(\Zbx::$wrote) === $wrote, $res['error']);
	$c = Discovery::choicesFrom($nm, $post('https://es.nm.example:9200'), $roles);
	check('the posted host id makes the cluster host chosen', $c['hosts'][$nmId] === true);
	$plan = $disc()->plan($nm, $c);
	check('a valid URL: no blocker, the cluster host is adopted', $plan['blockers'] === [] && $plan['cluster']['hostid'] === $nmId && $plan['adopting'] === 1, [$plan['blockers'], $plan['skipped']]);
	$line = array_values(array_filter($plan['writes'], fn($w) => str_starts_with($w[1], 'Set connection macros on "nm-ES-Cluster" from the typed URL')));
	check('the write list says it sets the connection macros from the typed URL', count($line) === 1 && str_contains($line[0][1], 'https://es.nm.example:9200'), $plan['writes']);
	$res = $disc()->apply($nm, $c, null, $noBackup, $noNote);
	check('adopt succeeds', $res['error'] === null, $res);
	$mm = \Zbx::macrosOf($nmId);
	check('the host now carries SCHEME, HOST and PORT from the typed URL', ($mm['{$ELASTICSEARCH.SCHEME}'] ?? null) === 'https' && ($mm['{$ELASTICSEARCH.HOST}'] ?? null) === 'es.nm.example'
		&& ($mm['{$ELASTICSEARCH.PORT}'] ?? null) === '9200', $mm);
	$hostRow = \Zbx::byHost('nm-ES-Cluster');
	check('it is in the client group, with the cluster template linked', in_array('nm-ES-Cluster', array_column($hostRow['hostgroups'], 'name'), true)
		&& in_array(Roles::clusterTemplate(), array_column($hostRow['parentTemplates'], 'host'), true), $hostRow['hostgroups']);
	check('a macro-bearing host does not get the line (the URL was read, not typed)', (function () use ($disc, $byName, $esMacros, $begin, $hostIn, $roles) {
		$begin('control');
		\Zbx::addHost(['host' => 'ct-ES-Cluster', 'groups' => ['Elasticsearch clusters'], 'templates' => [Roles::clusterTemplate()], 'macros' => $esMacros('es.ct')]);
		$p = $byName($disc()->scan())['ct-ES-Cluster'];
		return !preg_grep('/from the typed URL/', array_column($p['plan']['writes'], 1)) && $p['choices']['selected'];
	})());

	/* ---------------- tidy up ---------------- */
	$rm = function (string $d) use (&$rm) {
		foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $f) { is_dir("$d/$f") ? $rm("$d/$f") : unlink("$d/$f"); }
		rmdir($d);
	};
	$rm($dir);
	$scene = '';
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
