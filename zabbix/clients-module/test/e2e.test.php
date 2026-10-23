<?php
/**
 * End-to-end scenarios for the Clients module, against a fake Zabbix API: connections, host and
 * client names, duplicates, disabled hosts, import/export, and data left behind by the rename.
 *   docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php test/e2e.test.php
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
 *
 * test/spec.test.php covers the pure parts — the form's rules, the macros, the templates. This
 * file covers what only shows once Zabbix is in the picture: what Reconciler, Lifecycle,
 * ClientState, Backups and Importer do to an install that looks like the live one. The live one
 * is seven clients and some forty hosts that still carry the pre-rename names, which is why most
 * fixtures here are of that generation.
 *
 * The fake is \Zbx below. It is a store of hosts, templates, groups and macros plus the slice of
 * host.get that this module actually asks for, and it is deliberately strict in two ways:
 *   - a resource or method the module asks for and the fake does not know throws, rather than
 *     answering an empty array that would read as "there is nothing there";
 *   - a field is returned only when the call asked for it with the matching select*, because
 *     Reconciler::kindWithoutTemplates() keys off parentTemplates being absent and a fake that
 *     always answered it would hide the Zabbix-Admin case this file is partly about.
 * Nothing here talks to a real Zabbix, so none of it can touch item history.
 */
namespace {
	require __DIR__.'/fake-zabbix.php';
}
namespace Modules\EpClients\Test {
	foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'Forecast', 'ClientTypes', 'MasterTemplate', 'DevicesTemplate', 'JumpTemplate', 'ClusterTemplate', 'ClientSpec', 'Csv', 'Zip', 'Xlsx', 'Workbook', 'Backups', 'Reconciler', 'DefaultCredential', 'Lifecycle', 'Registry', 'History', 'SetupChecks', 'AlertRouting', 'ClientState', 'Importer', 'TemplateInstaller', 'TemplateMap', 'WriteCheck'] as $lib) {
		require __DIR__.'/../lib/'.$lib.'.php';
	}
	use Modules\EpClients\Lib\{AlertRouting, Backups, ClientSpec, ClientState, Csv, Settings, Importer, Workbook, Xlsx, JumpTemplate, Lifecycle, MasterTemplate, Reconciler, Roles, SetupChecks, Store, TemplateInstaller, TemplateMap, ClusterTemplate, DevicesTemplate, WriteCheck};

	$failed = 0; $passed = 0; $scene = '';
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed, $scene;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: ", $scene !== '' ? $scene.' — ' : '', $what, $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-e2e-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);

	$roles = Roles::defaults();
	$spec = new ClientSpec($roles);
	$byId = Roles::byId($roles);

	/**
	 * Zabbix's limit on a host's technical and visible name, and on a template name. 128 is not
	 * invented here: Roles::templateNames() already refuses a template name longer than it.
	 */
	$ZBX_NAME_MAX = 128;
	/** Zabbix's limit on a username, which is what AlertRouting::userName() has to stay inside. */
	$ZBX_USERNAME_MAX = 100;

	/**
	 * Every template a save needs present — taken from the module's own definitions rather than
	 * written out here, so renaming a template cannot leave this fixture quietly wrong.
	 */
	$allTemplates = array_values(array_unique(array_merge([MasterTemplate::NAME], Reconciler::clusterTemplates(),
		Reconciler::jumpClusterTemplates(), [Reconciler::ULM_TEMPLATE, JumpTemplate::ULM_NAME, Reconciler::agentTemplate()])));

	$begin = function (string $name) use ($allTemplates): void {
		global $scene;
		$scene = $name;
		\Zbx::reset();
		foreach ($allTemplates as $t) { \Zbx::addTemplate($t); }
	};
	/** A Reconciler per scenario: it caches group ids, so one is never shared across fixtures. */
	$rec = fn() => new Reconciler($spec);
	$state = fn($r) => new ClientState($spec, $r);

	/** A form for client $name. Everything a save needs, nothing a scenario does not care about. */
	$form = function (string $name, array $over = []) use ($spec): array {
		return array_merge($spec->defaults(), [
			'name' => $name, 'type' => 'On-Prem', 'es_url' => 'https://es.'.strtolower($name).'.example:9243',
			'es_password_mode' => 'vault', 'es_password_path' => 'secret/elasticpro/'.strtolower($name).':password',
			'ulm_bucket' => '', 'ulm_region' => '', 'servers' => '[]',
			'es_data_hot_cpu' => '32', 'es_data_hot_mem' => '128', 'es_master_cpu' => '8'
		], $over);
	};
	$client = function (array $f) use ($spec): array {
		['client' => $c, 'errors' => $e] = $spec->fromForm($f);
		if ($e) { check('the fixture form is valid', false, $e); }
		return $c;
	};

	/* ======================================================================================
	 *  ZABBIX CONNECTION
	 * ====================================================================================== */

	/*
	 * The API answers every read and refuses every write: a token with read-only permissions, or
	 * a user whose role lost its write access since the page was opened. The page must not report
	 * a half-success, and must say which call Zabbix refused and what Zabbix said about it.
	 */
	$begin('read-only token');
	$gid = \Zbx::groupId('acme');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP],
		'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'master']],
		'macros' => ['{$GRP.CLIENT}' => 'acme', '{$EP.CLIENT.TYPE}' => 'On-Prem']]);
	\Zbx::addHost(['host' => 'acme-ES-Cluster', 'groups' => ['acme', Reconciler::CLUSTER_GROUP],
		'templates' => [Roles::clusterTemplate()], 'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'cluster']]]);
	$r = $rec();
	$now = $r->current('acme');
	check('reads work: the master and cluster hosts are found', $now['master']['hostid'] === $masterid && $now['cluster'] !== null);
	check('reads work: the client is listed', array_keys($state($r)->clients()) === ['acme']);
	$c = $client($form('acme'));
	check('a read-only token is not something problems() can see — it asks Zabbix nothing it would refuse', $r->problems($c, $now) === [], $r->problems($c, $now));
	\Zbx::$readOnly = true;
	$said = '';
	try { $rec()->apply($c); } catch (\Exception $e) { $said = $e->getMessage(); }
	check('the save stops at the first refused write', $said !== '');
	check('and names the call and quotes what Zabbix said', strpos($said, 'Zabbix would not') === 0 && strpos($said, 'No permissions to call') !== false, $said);
	check('the refusal is not reported as "no reason given"', strpos($said, 'no reason given') === false, $said);

	/*
	 * A Zabbix Admin may read hosts but not templates, so template.get answers an empty list and
	 * every host shows no parentTemplates. That is the same answer a Zabbix with no templates
	 * imported would give, and the two must not be confused: reading it as "there are no clients"
	 * is what showed an operator an empty Cluster Management page, and Backups::take() walks that
	 * same list.
	 */
	$begin('a Zabbix Admin sees hosts but no templates');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP],
		'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'master']],
		'macros' => ['{$GRP.CLIENT}' => 'acme', '{$EP.CLIENT.TYPE}' => 'DI']]);
	\Zbx::addHost(['host' => 'acme-ES-Cluster', 'groups' => ['acme', Reconciler::CLUSTER_GROUP],
		'templates' => [Roles::clusterTemplate()], 'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'cluster']]]);
	\Zbx::addHost(['host' => 'acme-ULM', 'groups' => ['acme', Reconciler::ULM_GROUP],
		'templates' => [Reconciler::ULM_TEMPLATE], 'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'archive']]]);
	\Zbx::addHost(['host' => 'acme-ES-Data-Hot-1', 'ip' => '10.20.0.11', 'groups' => ['acme', 'ES Data Hot', 'ESNodes'],
		'templates' => [Reconciler::agentTemplate()],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'server'],
			['tag' => 'ep-role', 'value' => 'es_data_hot']]]);
	\Zbx::$hideTemplates = true;
	$r = $rec();
	check('the module still answers when template.get is empty to it', Reconciler::masterHosts(['output' => ['hostid']]) !== []);
	check('the master host is still found, by its kind tag', count(Reconciler::masterHosts(['output' => ['hostid', 'host']])) === 1);
	$clients = $state($r)->clients();
	check('the client list is not empty — "no templates" is not "no clients"', array_keys($clients) === ['acme'], array_keys($clients));
	$now = $r->current('acme');
	check('master, cluster and archive hosts are told apart by their kind tags', ($now['master']['hostid'] ?? '') === $masterid
		&& ($now['cluster']['host'] ?? '') === 'acme-ES-Cluster' && ($now['ulm']['host'] ?? '') === 'acme-ULM');
	check('the server is recognised by its role group even with no template in sight', count($now['machines']) === 1
		&& array_values($now['machines'])[0]['_roles'] === ['es_data_hot']);
	check('kindWithoutTemplates answers only when there are no templates to go on',
		Reconciler::kindWithoutTemplates(['tags' => [['tag' => 'ep-kind', 'value' => 'master']]]) === 'master'
		&& Reconciler::kindWithoutTemplates(['tags' => [['tag' => 'ep-kind', 'value' => 'master']], 'parentTemplates' => [['host' => 'x']]]) === null);
	check('the guard that keeps one client out of another still has the client groups', $r->clientGroups() === ['acme'], $r->clientGroups());
	$c = $client($form('acme', ['type' => 'DI', 'ulm_bucket' => 'acme-archive', 'ulm_region' => 'ap-south-1']));
	$problems = $r->problems($c, $now);
	check('a save is refused, naming the templates it cannot see', (bool) preg_grep('/Nothing was saved\. These templates are not in Zabbix/', $problems), $problems);
	check('and the master template is among them, so the message is about reading, not about one missing import',
		(bool) preg_grep('/'.preg_quote(MasterTemplate::NAME, '/').'/', $problems));
	$said = '';
	try { $rec()->apply($c); } catch (\Exception $e) { $said = $e->getMessage(); }
	check('nothing is written: the refusal comes before the first write', \Zbx::$wrote === [] && $said !== '', \Zbx::$wrote);
	// The live install's hosts carry the old spelling of the kind tag, so the fallback has to
	// answer to it too — this is the case the fallback was written for.
	\Zbx::$hosts[$masterid]['tags'] = [Reconciler::LEGACY_MANAGED, ['tag' => 'evp-kind', 'value' => 'master'], ['tag' => 'evp-client', 'value' => 'acme']];
	check('the fallback reads the pre-rename kind tag as well', count(Reconciler::masterHosts(['output' => ['hostid', 'host']])) === 1);
	check('and the client is still listed', array_keys($state($rec())->clients()) === ['acme']);

	/*
	 * Zabbix refuses a write half way through a save. apply() has no transaction, so the client is
	 * left part-written; the point of problems() is that the refusals it can foresee never get
	 * that far. Both halves are asserted: what a refusal costs, and that the foreseeable one is
	 * caught before the first write.
	 */
	$begin('Zabbix refuses a write mid-apply');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP],
		'templates' => [MasterTemplate::NAME], 'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']],
		'macros' => ['{$GRP.CLIENT}' => 'acme']]);
	$c = $client($form('acme'));
	$r = $rec();
	check('nothing foreseeable is wrong with this save', $r->problems($c, $r->current('acme')) === [], $r->problems($c, $r->current('acme')));
	\Zbx::$deny['Host.create'] = 'Host "acme-ES-Cluster" already exists.';
	$said = '';
	try { $rec()->apply($c); } catch (\Exception $e) { $said = $e->getMessage(); }
	check('the save throws with what Zabbix said', strpos($said, 'create host') !== false && strpos($said, 'already exists') !== false, $said);
	check('the cluster host was not created', \Zbx::byHost('acme-ES-Cluster') === null);
	check('but the master host had already been written: it carries the client and status tags now',
		in_array('ep-client=acme', \Zbx::tagsOf($masterid), true) && in_array('ep-status=active', \Zbx::tagsOf($masterid), true), \Zbx::tagsOf($masterid));
	check('and its macros had already been written over', array_key_exists('{$EP.CLIENT.TYPE}', \Zbx::macrosOf($masterid)));
	check('so the client is left half-written — which is what problems() exists to prevent', \Zbx::$wrote !== []);
	// The same collision, this time visible to Zabbix before anything is written: a host already
	// has the name apply() would ask for. problems() has to refuse, and refuse before writing.
	$begin('a host already has the name the save would create');
	\Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP],
		'templates' => [MasterTemplate::NAME], 'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']],
		'macros' => ['{$GRP.CLIENT}' => 'acme']]);
	\Zbx::addHost(['host' => 'acme-ES-Cluster', 'groups' => ['Discovered hosts'], 'templates' => []]);
	$r = $rec();
	$problems = $r->problems($c, $r->current('acme'));
	check('problems() names the host and says this page did not recognise it', (bool) preg_grep('/A host called "acme-ES-Cluster" already exists/', $problems), $problems);
	check('and tells the operator the tag that would fix it', (bool) preg_grep('/ep-kind: cluster/', $problems), $problems);
	$said = '';
	try { $rec()->apply($c); } catch (\Exception $e) { $said = $e->getMessage(); }
	check('the save is refused before the first write', \Zbx::$wrote === [] && $said !== '', \Zbx::$wrote);

	/* ======================================================================================
	 *  HOSTNAMES
	 * ====================================================================================== */

	/*
	 * Role hosts: the host name is the IP, the visible name is the server name (decided
	 * 2026-10-23). A host taken on keeps the host name it has — an FQDN, an IP, or the
	 * <client>-<Role>-<n> an earlier release gave it — and only its visible name follows the
	 * server name, when one is known. Nothing is ever renamed to a pattern, or back from one.
	 */
	/** A master host of today's generation, with nothing for a save to change on it. */
	$addMaster = function (string $cl): string {
		return \Zbx::addHost(['host' => $cl.'-Master', 'groups' => [$cl, Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => $cl], ['tag' => 'ep-kind', 'value' => 'master'],
				['tag' => 'ep-status', 'value' => 'active']], 'macros' => ['{$GRP.CLIENT}' => $cl]]);
	};
	/** One of this page's server hosts, as it looks after a save. */
	$addServer = function (string $cl, array $f): string {
		return \Zbx::addHost($f + ['groups' => [$cl, 'ES Data Hot', 'ESNodes'], 'templates' => [Reconciler::agentTemplate()],
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => $cl], ['tag' => 'ep-kind', 'value' => 'server'],
				['tag' => 'ep-role', 'value' => 'es_data_hot'], ['tag' => 'ep-service', 'value' => 'es_data_hot'],
				['tag' => 'ep-status', 'value' => 'active']]]);
	};
	/** Every rename a save asked Zabbix for: a host.update that carries a technical name. */
	$renames = fn() => array_values(array_filter(\Zbx::calls('Host.update'), fn($p) => array_key_exists('host', $p)));

	$begin('a role host taken on keeps its host name');
	$addMaster('acme');
	$fqdn = $addServer('acme', ['host' => 'vm-prod-17.acme.internal', 'name' => 'ES hot node (rack 4)', 'ip' => '10.20.0.11']);
	$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.20.0.11', 'roles' => ['es_data_hot']]])]));
	$rr = $rec();
	$rr->apply($c);
	check('no server name given: host name and visible name stay as they are',
		\Zbx::$hosts[$fqdn]['host'] === 'vm-prod-17.acme.internal' && \Zbx::$hosts[$fqdn]['name'] === 'ES hot node (rack 4)',
		[\Zbx::$hosts[$fqdn]['host'], \Zbx::$hosts[$fqdn]['name']]);
	check('and no host name was ever asked for', $renames() === [], $renames());
	check('read back, its visible name is its server name', str_contains($state($rec())->formFor('acme')['servers'], '"host":"ES hot node (rack 4)"'));
	$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.20.0.11', 'roles' => ['es_data_hot'], 'host' => 'es-hot-17']])]));
	$rr = $rec();
	$rr->apply($c);
	check('a server name given becomes the visible name; the host name stays', \Zbx::$hosts[$fqdn]['host'] === 'vm-prod-17.acme.internal'
		&& \Zbx::$hosts[$fqdn]['name'] === 'es-hot-17' && $renames() === []);
	check('it is the same host: its interface and its id are untouched, so its history stays',
		\Zbx::$hosts[$fqdn]['interfaces'][0]['ip'] === '10.20.0.11' && \Zbx::$hosts[$fqdn]['hostid'] === $fqdn);
	check('and the save says what it changed', (bool) preg_grep('/Visible name of vm-prod-17\.acme\.internal set to "es-hot-17"/', $rr->done()), $rr->done());

	$begin('an earlier release\'s <client>-<Role>-<n> is left alone');
	$addMaster('acme');
	$same = $addServer('acme', ['host' => 'acme-ES-Data-Hot-1', 'ip' => '10.20.0.11']);
	$halfway = $addServer('acme', ['host' => 'vm-prod-19.acme.internal', 'name' => 'acme-ES-Data-Hot-2', 'ip' => '10.20.0.12']);
	$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.20.0.11', 'roles' => ['es_data_hot']], ['ip' => '10.20.0.12', 'roles' => ['es_data_hot']]])]));
	$rec()->apply($c);
	check('nothing is renamed, back or forward', $renames() === [], $renames());
	check('both keep the names they had', \Zbx::$hosts[$same]['host'] === 'acme-ES-Data-Hot-1' && \Zbx::$hosts[$same]['name'] === 'acme-ES-Data-Hot-1'
		&& \Zbx::$hosts[$halfway]['host'] === 'vm-prod-19.acme.internal' && \Zbx::$hosts[$halfway]['name'] === 'acme-ES-Data-Hot-2');
	$now = $rec()->current('acme');
	check('a host name is never taken for a server name', $now['machines'][$same]['_server_name'] === '' && $now['machines'][$halfway]['_server_name'] === 'acme-ES-Data-Hot-2');

	$begin('a new role host: host name = IP, visible name = server name');
	$addMaster('acme');
	$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.20.0.13', 'roles' => ['es_data_hot'], 'host' => 'es-hot-13'],
		['ip' => '10.20.0.14', 'roles' => ['parser']]])]));
	$rr = $rec();
	$rr->apply($c);
	check('with a server name', (\Zbx::byHost('10.20.0.13')['name'] ?? null) === 'es-hot-13');
	check('without one: the IP for both, and the save says no server name is known', (\Zbx::byHost('10.20.0.14')['name'] ?? null) === '10.20.0.14'
		&& (bool) preg_grep('/Created host 10\.20\.0\.14 \(no server name yet\)/', $rr->done()), $rr->done());
	check('no host is named <client>-<Role>-<n>', !array_filter(array_column(\Zbx::$hosts, 'host'), fn($h) => preg_match('/^acme-(ES|Parser)/', $h)));
	$taken = \Zbx::addHost(['host' => '10.20.0.15', 'groups' => ['Elsewhere']]);
	$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.20.0.13', 'roles' => ['es_data_hot'], 'host' => 'es-hot-13'],
		['ip' => '10.20.0.14', 'roles' => ['parser']], ['ip' => '10.20.0.15', 'roles' => ['parser']]])]));
	$r = $rec();
	check('an IP that is already another host\'s host name is refused before anything is written',
		(bool) preg_grep('/A host whose host name is 10\.20\.0\.15 already exists/', $r->problems($c, $r->current('acme'))), $r->problems($c, $r->current('acme')));

	/*
	 * Two clients each have a server whose hostname is the same string — a template-built VM name
	 * repeated per environment. They are different machines on different IPs, and both must be
	 * kept; the only thing that must not happen is one client taking the other's host on.
	 */
	$begin('two clients, one hostname');
	foreach (['acme', 'beta'] as $i => $cl) {
		\Zbx::addHost(['host' => $cl.'-Master', 'groups' => [$cl, Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => $cl], ['tag' => 'ep-kind', 'value' => 'master'],
				['tag' => 'ep-status', 'value' => 'active']], 'macros' => ['{$GRP.CLIENT}' => $cl]]);
		\Zbx::addHost(['host' => $cl.'-Parser-1', 'name' => 'siem-parser-01', 'ip' => '10.'.(30 + $i).'.0.5',
			'groups' => [$cl, 'Parser', 'Parsers'], 'templates' => [Reconciler::agentTemplate()],
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => $cl], ['tag' => 'ep-kind', 'value' => 'server'],
				['tag' => 'ep-role', 'value' => 'parser']]]);
	}
	$r = $rec();
	check('each client sees its own server only', count($r->current('acme')['machines']) === 1 && count($rec()->current('beta')['machines']) === 1);
	check('both client groups are known, so neither host looks unowned', $r->clientGroups() === ['acme', 'beta'], $r->clientGroups());
	$taken = $rec()->outsiders('acme', ['10.31.0.5'])['taken'];
	check('beta\'s server is refused to acme by IP, and the message names the owner',
		isset($taken['10.31.0.5']) && $taken['10.31.0.5']['client'] === 'beta' && $taken['10.31.0.5']['name'] === 'siem-parser-01', $taken);
	$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.30.0.5', 'roles' => ['parser']], ['ip' => '10.31.0.5', 'roles' => ['parser']]])]));
	$r = $rec();
	$problems = $r->problems($c, $r->current('acme'));
	check('and a save that lists it is refused, saying an IP belongs to one client only',
		(bool) preg_grep('/10\.31\.0\.5 is already "siem-parser-01", a server of client beta/', $problems), $problems);

	/*
	 * Names at and over the limits. The form caps a client name at 48 characters; the names built
	 * from it have to stay inside Zabbix's 128.
	 */
	$begin('names at the length limits');
	$n48 = str_repeat('a', 48);
	check('a 48-character client name is accepted', $spec->fromForm($form($n48, ['es_url' => '']))['errors'] === []);
	check('49 is refused, and the message says what is allowed',
		(bool) preg_grep('/may hold only/', $spec->fromForm($form(str_repeat('a', 49), ['es_url' => '']))['errors']));
	check('a client name of one character is accepted', $spec->fromForm($form('a', ['es_url' => '']))['errors'] === []);
	// A role host's host name is its IP and its visible name its server name (at most 128), so no
	// host name is built from the client and its roles any more: nothing about a server's name
	// grows with the roles it holds.
	check('the longest server name accepted is 128, as Zabbix\'s visible name', ClientSpec::isServerName(str_repeat('s', 128)) && !ClientSpec::isServerName(str_repeat('s', 129)));
	check('the master, cluster and archive host names of a 48-character client fit', strlen(ClientSpec::clusterName($n48)) <= $ZBX_NAME_MAX
		&& strlen(ClientSpec::masterName($n48)) <= $ZBX_NAME_MAX && strlen(ClientSpec::ulmName($n48)) <= $ZBX_NAME_MAX);
	$tooLong = false;
	try { Roles::saveTemplateNames(['agent' => str_repeat('x', 129)]); } catch (\InvalidArgumentException $e) { $tooLong = true; }
	check('a template name over Zabbix 128 characters is refused rather than silently cut, and nothing is saved',
		$tooLong && Roles::templateNames()['agent'] === Roles::DEFAULT_TEMPLATE && !is_file($dir.'/templates.json'));

	/*
	 * Case and whitespace. A client name is trimmed before it is checked, because an operator
	 * pasting from a ticket brings the spaces with them; but case is never folded, because the
	 * host names are built from the name and Zabbix tells "ACME-Master" from "acme-Master".
	 */
	$begin('case and whitespace in names');
	/** The form's own verdict on a client name, with nothing else in the form to complain about. */
	$nameOf = fn(string $name) => $spec->fromForm($form($name, ['es_url' => '', 'es_password_path' => '']))['client']['name'];
	$err = fn(string $name) => $spec->fromForm($form($name, ['es_url' => '', 'es_password_path' => '']))['errors'];
	check('a name is trimmed before it is checked', $nameOf('  acme  ') === 'acme' && $err('  acme  ') === []);
	check('an inner space is still refused', (bool) preg_grep('/may hold only/', $err('acme eu')));
	check('a tab is refused too', (bool) preg_grep('/may hold only/', $err("acme\teu")));
	check('case is kept, not folded', $nameOf('ACME') === 'ACME' && $err('ACME') === []);
	check('a server name of another case does not count as ours',
		ClientSpec::slotOf('ACME-ES-Data-Hot-1', 'acme', 'ES-Data-Hot') === null
		&& ClientSpec::slotOf('acme-ES-Data-Hot-1', 'acme', 'ES-Data-Hot') === 1);
	check('nor does one whose base differs only in case', ClientSpec::slotOf('acme-es-data-hot-1', 'acme', 'ES-Data-Hot') === null);
	check('a slot number must be a positive integer', ClientSpec::slotOf('acme-Parser-0', 'acme', 'Parser') === null
		&& ClientSpec::slotOf('acme-Parser-01', 'acme', 'Parser') === 1 && ClientSpec::slotOf('acme-Parser-1x', 'acme', 'Parser') === null);

	/*
	 * A host whose technical name follows this page's pattern but which this page did not make:
	 * an operator built it by hand, or it is left over from a client that was removed. It must
	 * never be deleted, and its number must not be handed out again — host.create would be
	 * refused in the middle of a save.
	 */
	$begin('a host that looks like ours and is not');
	\Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'master'],
			['tag' => 'ep-status', 'value' => 'active']], 'macros' => ['{$GRP.CLIENT}' => 'acme']]);
	// In the client's group and in a family group, so current() sees it; no role group, no
	// managed-by marker: it is nobody's server.
	$lookalike = \Zbx::addHost(['host' => 'acme-Parser-3', 'ip' => '10.40.0.3', 'groups' => ['acme', 'Parsers'],
		'templates' => [Reconciler::agentTemplate()], 'tags' => []]);
	$r = $rec();
	$now = $r->current('acme');
	check('it is read as a host with no role, not as a server', count($now['unassigned']) === 1
		&& $now['machines'][$lookalike]['_roles'] === []);
	check('isManaged says no', !Reconciler::isManaged($now['machines'][$lookalike]));
	$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.40.0.9', 'roles' => ['parser']]])]));
	check('a save that does not list its IP never plans to delete it', $r->plannedDeletes($c, $now) === [], $r->plannedDeletes($c, $now));
	$rr = $rec();
	$rr->apply($c);
	check('it is still there afterwards, under its own name', isset(\Zbx::$hosts[$lookalike]) && \Zbx::$hosts[$lookalike]['host'] === 'acme-Parser-3');
	check('and the save says why it was left alone', (bool) preg_grep('/has no role and is left as it is/', $rr->done()), $rr->done());
	check('the new server\'s host name is its IP', \Zbx::byHost('10.40.0.9') !== null
		&& \Zbx::byHost('10.40.0.9')['interfaces'][0]['ip'] === '10.40.0.9');

	/* ======================================================================================
	 *  CLIENT NAMES
	 * ====================================================================================== */

	$begin('what a client may be called');
	$err = fn(string $name) => $spec->fromForm($form($name, ['es_url' => '', 'es_password_path' => '']))['errors'];
	check('letters, digits, hyphen and underscore are allowed', $err('acme-eu_2') === [], $err('acme-eu_2'));
	check('a dot is refused: the name is a host-name part', (bool) preg_grep('/may hold only/', $err('acme.eu')));
	check('so is a slash, a colon and a brace', $err('acme/eu') !== [] && $err('acme:eu') !== [] && $err('acme{eu}') !== []);
	check('it may not start with a hyphen or an underscore', $err('-acme') !== [] && $err('_acme') !== []);
	check('unicode is refused', $err('acmé') !== [] && $err('アクメ') !== []);
	check('an empty name says why it is required', (bool) preg_grep('/its host group and every host are named after it/', $err('')));
	check('a name of only spaces is read as empty, not as a bad name', (bool) preg_grep('/is required/', $err('   ')));

	/*
	 * One client's name being the start of another's. Every match this page makes on a name has to
	 * be exact: "acme" must not claim "acme-eu"'s hosts, and the slot logic must not read
	 * "acme-eu-Parser-1" as acme's parser number 1 — renaming it would take it away from acme-eu.
	 */
	$begin('a client name that is a prefix of another');
	check('the client hosts never collide', ClientSpec::masterName('acme') !== ClientSpec::masterName('acme-eu')
		&& ClientSpec::clusterName('acme') !== ClientSpec::clusterName('acme-eu')
		&& ClientSpec::ulmName('acme') !== ClientSpec::ulmName('acme-eu'));
	check('slotOf cannot cross-match the two ways round',
		ClientSpec::slotOf('acme-eu-Parser-1', 'acme', 'Parser') === null
		&& ClientSpec::slotOf('acme-eu-Parser-1', 'acme-eu', 'Parser') === 1
		&& ClientSpec::slotOf('acme-Parser-1', 'acme-eu', 'Parser') === null);
	check('nor through the base: "Parser" is not "Parser-S3-Parser"',
		ClientSpec::slotOf('acme-Parser-S3-Parser-1', 'acme', 'Parser') === null);
	foreach (['acme', 'acme-eu'] as $i => $cl) {
		\Zbx::addHost(['host' => $cl.'-Master', 'groups' => [$cl, Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => $cl], ['tag' => 'ep-kind', 'value' => 'master'],
				['tag' => 'ep-status', 'value' => 'active']], 'macros' => ['{$GRP.CLIENT}' => $cl]]);
		\Zbx::addHost(['host' => $cl.'-Parser-1', 'ip' => '10.5'.$i.'.0.1', 'groups' => [$cl, 'Parser', 'Parsers'],
			'templates' => [Reconciler::agentTemplate()],
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => $cl], ['tag' => 'ep-kind', 'value' => 'server'],
				['tag' => 'ep-role', 'value' => 'parser'], ['tag' => 'ep-service', 'value' => 'parser'],
				['tag' => 'ep-status', 'value' => 'active']]]);
	}
	$clients = $state($rec())->clients();
	check('both are listed, each under its own name', array_keys($clients) === ['acme', 'acme-eu'], array_keys($clients));
	check('acme\'s group holds only acme\'s hosts', count($rec()->current('acme')['machines']) === 1
		&& count($rec()->current('acme-eu')['machines']) === 1);
	$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.50.0.1', 'roles' => ['parser']]])]));
	$r = $rec();
	check('saving acme plans to delete nothing of acme-eu\'s', $r->plannedDeletes($c, $r->current('acme')) === []);
	$rr = $rec();
	$rr->apply($c);
	check('and acme-eu\'s parser keeps its name', \Zbx::byHost('acme-eu-Parser-1') !== null);
	check('the DL user names do not collide either', AlertRouting::userName('acme') !== AlertRouting::userName('acme-eu'));

	$begin('the DL account name');
	check('it is ep-dl- and the client name', AlertRouting::userName('acme') === 'ep-dl-acme');
	check('the pre-rename spelling is still recognised, and is asked for second',
		AlertRouting::userNames('acme') === ['ep-dl-acme', 'evp-dl-acme']);
	check('characters a username may not hold become one hyphen', AlertRouting::userName('acmé') === 'ep-dl-acm-'
		&& AlertRouting::userName('a  b') === 'ep-dl-a-b');
	check('dots, hyphens and underscores are kept', AlertRouting::userName('a.c-m_e') === 'ep-dl-a.c-m_e');
	$long = AlertRouting::userName(str_repeat('c', 200));
	check('the name is capped at 90 characters after the prefix', strlen($long) === 96 && strlen($long) <= $ZBX_USERNAME_MAX, strlen($long));
	check('a client name the form would accept is nowhere near the cap', strlen(AlertRouting::userName(str_repeat('a', 48))) === 54);
	check('two long names that differ only past the cap would collide, so the cap must stay above what the form allows',
		AlertRouting::userName(str_repeat('c', 95).'x') === AlertRouting::userName(str_repeat('c', 95).'y'));

	/* ======================================================================================
	 *  DUPLICATES
	 * ====================================================================================== */

	/*
	 * Several hosts on one IP: the usual cause is a host built by hand beside one this page made,
	 * or a VM rebuilt under a new name. A save must refuse until the operator says which way, and
	 * a merge must name every host it would delete before it deletes one.
	 */
	$begin('two hosts, one IP');
	\Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'master'],
			['tag' => 'ep-status', 'value' => 'active']], 'macros' => ['{$GRP.CLIENT}' => 'acme']]);
	$keep = \Zbx::addHost(['host' => 'acme-Parser-1', 'ip' => '10.60.0.7', 'groups' => ['acme', 'Parser', 'Parsers'],
		'templates' => [Reconciler::agentTemplate()],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'server'],
			['tag' => 'ep-role', 'value' => 'parser'], ['tag' => 'ep-service', 'value' => 'parser'], ['tag' => 'ep-status', 'value' => 'active']]]);
	$twin = \Zbx::addHost(['host' => 'acme-Parser-2', 'ip' => '10.60.0.7', 'groups' => ['acme', 'Parser', 'Parsers'],
		'templates' => [Reconciler::agentTemplate()],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'server'],
			['tag' => 'ep-role', 'value' => 'parser'], ['tag' => 'ep-service', 'value' => 'parser'], ['tag' => 'ep-status', 'value' => 'active']]]);
	$r = $rec();
	$now = $r->current('acme');
	check('the shared IP is reported, oldest host first', array_keys($now['shared']) === ['10.60.0.7']
		&& array_column($now['shared']['10.60.0.7'], 'hostid') === [$keep, $twin]);
	$servers = json_encode([['ip' => '10.60.0.7', 'roles' => ['parser']]]);
	$c = $client($form('acme', ['es_url' => '', 'servers' => $servers]));
	$problems = $r->problems($c, $now);
	check('without the merge tick the save is refused, naming both hosts',
		(bool) preg_grep('/2 hosts share 10\.60\.0\.7 \("acme-Parser-1", "acme-Parser-2"\)/', $problems), $problems);
	check('and nothing is planned for deletion while it is refused', $r->plannedDeletes($c, $now) === [], $r->plannedDeletes($c, $now));
	$merged = $client($form('acme', ['es_url' => '', 'servers' => $servers, 'merge' => '10.60.0.7']));
	check('with the tick the save is allowed', $rec()->problems($merged, $rec()->current('acme')) === []);
	check('and names the host it will delete: all but the first', $rec()->plannedDeletes($merged, $rec()->current('acme')) === [$twin => 'acme-Parser-2']);
	$rr = $rec();
	$rr->apply($merged);
	check('after the merge the oldest host is the one left, with its history', isset(\Zbx::$hosts[$keep]) && !isset(\Zbx::$hosts[$twin]));
	// A duplicate an operator built by hand is not this page's to delete, tick or no tick.
	$begin('a hand-made host shares the IP');
	\Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']], 'macros' => ['{$GRP.CLIENT}' => 'acme']]);
	\Zbx::addHost(['host' => 'acme-Parser-1', 'ip' => '10.60.0.7', 'groups' => ['acme', 'Parser', 'Parsers'],
		'templates' => [Reconciler::agentTemplate()], 'tags' => [Reconciler::MANAGED, ['tag' => 'ep-role', 'value' => 'parser']]]);
	\Zbx::addHost(['host' => 'parser-by-hand', 'ip' => '10.60.0.7', 'groups' => ['acme', 'Parser', 'Parsers'],
		'templates' => [Reconciler::agentTemplate()], 'tags' => []]);
	$r = $rec();
	$problems = $r->problems($merged, $r->current('acme'));
	check('the merge is refused, and says to remove it by hand first',
		(bool) preg_grep('/"parser-by-hand" shares 10\.60\.0\.7 but was made by hand/', $problems), $problems);
	check('it is never in the planned deletions', $rec()->plannedDeletes($merged, $rec()->current('acme')) === [],
		$rec()->plannedDeletes($merged, $rec()->current('acme')));

	/*
	 * A visible name a host in another client already carries. Zabbix refuses a collision on
	 * either name, so nameClashes() asks about both — a clash found only on the visible name is
	 * still a save that would fail half way through.
	 */
	$begin('a duplicate visible name across clients');
	\Zbx::addHost(['host' => 'beta-Master', 'groups' => ['beta', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']], 'macros' => ['{$GRP.CLIENT}' => 'beta']]);
	// A beta host whose visible name is the name acme's master host would be created under.
	\Zbx::addHost(['host' => 'beta-old-box', 'name' => 'acme-Master', 'groups' => ['beta'], 'templates' => []]);
	$c = $client($form('acme', ['es_url' => '']));
	$r = $rec();
	$problems = $r->problems($c, $r->current('acme'));
	check('the clash is found by the visible name', (bool) preg_grep('/A host called "beta-old-box" already exists/', $problems), $problems);
	check('and it is named as the master host of acme', (bool) preg_grep('/the master host of client "acme"/', $problems), $problems);
	$said = '';
	try { $rec()->apply($c); } catch (\Exception $e) { $said = $e->getMessage(); }
	check('so nothing is written', \Zbx::$wrote === [] && $said !== '', \Zbx::$wrote);

	/*
	 * The twin case: one logical object carrying both generations of a name at once, which is what
	 * a migration stopped half way leaves behind. Nothing may be counted twice, and today's value
	 * wins wherever the two disagree.
	 */
	$begin('one object under both the old and the new name');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP, Reconciler::LEGACY_MASTERS_GROUP],
		'templates' => [MasterTemplate::NAME, MasterTemplate::LEGACY_NAME],
		'tags' => [Reconciler::MANAGED, Reconciler::LEGACY_MANAGED, ['tag' => 'ep-kind', 'value' => 'master'],
			['tag' => 'evp-kind', 'value' => 'master'], ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'evp-client', 'value' => 'acme']],
		'macros' => ['{$GRP.CLIENT}' => 'acme', '{$EP.CLIENT.TYPE}' => 'DI', '{$EVP.CLIENT.TYPE}' => 'On-Prem']]);
	check('the master host is listed once, not twice', count(Reconciler::masterHosts(['output' => ['hostid']])) === 1);
	check('the client is listed once', array_keys($state($rec())->clients()) === ['acme']);
	$r = $rec();
	$now = $r->current('acme');
	check('current() picks one master host', $now['master']['hostid'] === $masterid);
	check('the two spellings of a tag are read as one value, not two',
		Reconciler::tagValues($now['master'], 'ep-client') === ['acme']);
	check('the masters\' group is not read as a client of its own', $r->clientGroups() === ['acme'], $r->clientGroups());
	check('where both generations of a macro disagree, today\'s wins',
		ClientSpec::canonicalMacros(\Zbx::macrosOf($masterid))['{$EP.CLIENT.TYPE}'] === 'DI'
		&& ClientSpec::macroOf(\Zbx::macrosOf($masterid), '{$EP.CLIENT.TYPE}') === 'DI');
	check('and AlertRouting reads it the same way — the two must keep agreeing',
		AlertRouting::canonical(\Zbx::macrosOf($masterid))['{$EP.CLIENT.TYPE}'] === 'DI');
	check('the form opens on today\'s value', $state($rec())->formFor('acme')['type'] === 'DI');
	// Both spellings of the Elasticsearch template name, where a site pointed the Roles page at
	// the old one: it is one name, and must be filtered on once.

	/* ======================================================================================
	 *  DISABLED HOSTS
	 * ====================================================================================== */

	/*
	 * A host an operator switched off. It is still offered when a server is added — leaving it out
	 * would have the sheet say "not in Zabbix, it will be created" and then adopt that very host —
	 * and adoption must not switch it on behind their back.
	 */
	$begin('adopting a host that is Not monitored');
	\Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master'], ['tag' => 'ep-status', 'value' => 'active']],
		'macros' => ['{$GRP.CLIENT}' => 'acme']]);
	$off = \Zbx::addHost(['host' => 'spare-vm-04', 'ip' => '10.70.0.4', 'status' => HOST_STATUS_NOT_MONITORED,
		'groups' => ['Discovered hosts'], 'templates' => [Reconciler::agentTemplate()], 'tags' => []]);
	$free = $rec()->outsiders('acme', ['10.70.0.4'])['free'];
	check('a Not monitored host is still offered as free to take on', isset($free['10.70.0.4'][$off]));
	check('and its status comes back, so the sheet can show it',
		(int) \Zbx::hostGet(['hostids' => [$off]])[0]['status'] === HOST_STATUS_NOT_MONITORED);
	$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.70.0.4', 'roles' => ['parser']]])]));
	$rr = $rec();
	$rr->apply($c);
	check('it was taken on, its host name untouched, and the save says its history stays',
		\Zbx::$hosts[$off]['host'] === 'spare-vm-04' && (bool) preg_grep('/Took on the existing host/', $rr->done()), $rr->done());
	check('adoption did not switch it on', (int) \Zbx::$hosts[$off]['status'] === HOST_STATUS_NOT_MONITORED);
	check('no update of it asked for a status at all',
		array_filter(\Zbx::calls('Host.update'), fn($p) => (string) $p['hostid'] === $off && array_key_exists('status', $p)) === []);
	$checks = SetupChecks::forClients(['acme' => $rec()->current('acme')], ['acme' => \Zbx::macrosOf(\Zbx::byHost('acme-Master')['hostid'])]);
	$labels = array_column($checks['acme'], 0);
	check('and the client list says so rather than blaming the agent',
		in_array('Servers are monitored', $labels, true), $labels);
	$monitored = array_values(array_filter($checks['acme'], fn($ck) => $ck[0] === 'Servers are monitored'));
	check('the check fails and names the host', $monitored !== [] && $monitored[0][1] === false
		&& strpos($monitored[0][2], 'spare-vm-04') !== false, $monitored);
	check('and it is not asked of a client that is disabled, where every host being off is the point',
		!in_array('Servers are monitored', array_column(SetupChecks::forClients(['acme' => $rec()->current('acme')],
			['acme' => [Lifecycle::MACRO => 'disabled']])['acme'], 0), true));

	/*
	 * A client disabled before the rename. Its status is under the pre-rename macro name, and if
	 * that is not read the client shows as active: Enable would refuse it, Disable would try to
	 * disable it again, and AlertRouting would keep alerting on a client that was switched off.
	 */
	$begin('a client disabled before the rename');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'status' => HOST_STATUS_NOT_MONITORED,
		'groups' => ['acme', Reconciler::LEGACY_MASTERS_GROUP], 'templates' => [MasterTemplate::LEGACY_NAME],
		'tags' => [Reconciler::LEGACY_MANAGED, ['tag' => 'evp-kind', 'value' => 'master'], ['tag' => 'evp-status', 'value' => 'disabled']],
		'macros' => ['{$GRP.CLIENT}' => 'acme', '{$EVP.CLIENT.STATUS}' => 'disabled']]);
	$onBefore = \Zbx::addHost(['host' => 'acme-Parser-1', 'ip' => '10.80.0.1', 'status' => HOST_STATUS_NOT_MONITORED,
		'groups' => ['acme', 'Parser', 'Parsers'], 'templates' => [Reconciler::agentTemplate()],
		'tags' => [Reconciler::LEGACY_MANAGED, ['tag' => 'evp-role', 'value' => 'parser'], ['tag' => 'evp-status', 'value' => 'disabled']]]);
	// The one an operator had deliberately left off before the client was disabled, marked under
	// the old spelling. Enable() must leave exactly this one off.
	$offBefore = \Zbx::addHost(['host' => 'acme-Parser-2', 'ip' => '10.80.0.2', 'status' => HOST_STATUS_NOT_MONITORED,
		'groups' => ['acme', 'Parser', 'Parsers'], 'templates' => [Reconciler::agentTemplate()],
		'tags' => [Reconciler::LEGACY_MANAGED, ['tag' => 'evp-role', 'value' => 'parser'], ['tag' => 'evp-status', 'value' => 'disabled'],
			['tag' => 'evp-was-off', 'value' => '1']]]);
	check('the legacy macro is read as a status', Lifecycle::statusOf(\Zbx::macrosOf($masterid)) === 'disabled');
	check('an empty macro of today does not mask it',
		Lifecycle::statusOf(['{$EP.CLIENT.STATUS}' => '', '{$EVP.CLIENT.STATUS}' => 'decommissioned']) === 'decommissioned');
	check('nor does an unrecognised one',
		Lifecycle::statusOf(['{$EP.CLIENT.STATUS}' => 'paused', '{$EVP.CLIENT.STATUS}' => 'disabled']) === 'disabled');
	check('where both are recognised, today\'s wins',
		Lifecycle::statusOf(['{$EP.CLIENT.STATUS}' => 'active', '{$EVP.CLIENT.STATUS}' => 'disabled']) === 'active');
	$life = new Lifecycle($rec());
	check('the page reads it as disabled', $life->status('acme') === 'disabled');
	$life->enable('acme');
	check('enabling switches on the host that was on before', (int) \Zbx::$hosts[$onBefore]['status'] === HOST_STATUS_MONITORED);
	check('and leaves off the one an operator had left off, marked under the old name',
		(int) \Zbx::$hosts[$offBefore]['status'] === HOST_STATUS_NOT_MONITORED);
	check('the status is written under today\'s macro name', \Zbx::macrosOf($masterid)['{$EP.CLIENT.STATUS}'] === 'active');
	check('and the stale one is left where it is, because deleting a macro cannot be undone',
		\Zbx::macrosOf($masterid)['{$EVP.CLIENT.STATUS}'] === 'disabled');
	check('every host carries today\'s status tag and neither spelling twice',
		\Zbx::tagsOf($onBefore) === ['ep-status=active', 'evp-role=parser', 'managed-by=elasticvue-clients'], \Zbx::tagsOf($onBefore));
	// The marker is spent once it has been read: the next disable records the state as it is then,
	// and a leftover marker under either spelling would keep this host off for good.
	check('the was-off marker is taken off once it has been honoured, under the old spelling too',
		!preg_grep('/was-off/', \Zbx::tagsOf($offBefore)), \Zbx::tagsOf($offBefore));
	check('and the host it belonged to still reads as active, not as something half-enabled',
		in_array('ep-status=active', \Zbx::tagsOf($offBefore), true), \Zbx::tagsOf($offBefore));

	/*
	 * Disable then enable, within one generation: the record of which hosts were already off is
	 * what makes the pair safe to repeat.
	 */
	$begin('disable then enable leaves the already-off hosts off');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']], 'macros' => ['{$GRP.CLIENT}' => 'acme']]);
	$on = \Zbx::addHost(['host' => 'acme-Parser-1', 'ip' => '10.90.0.1', 'groups' => ['acme', 'Parser', 'Parsers'],
		'templates' => [Reconciler::agentTemplate()], 'tags' => [Reconciler::MANAGED, ['tag' => 'ep-role', 'value' => 'parser']]]);
	$wasOff = \Zbx::addHost(['host' => 'acme-Parser-2', 'ip' => '10.90.0.2', 'status' => HOST_STATUS_NOT_MONITORED,
		'groups' => ['acme', 'Parser', 'Parsers'], 'templates' => [Reconciler::agentTemplate()],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-role', 'value' => 'parser']]]);
	$life = new Lifecycle($rec());
	$life->disable('acme');
	check('every host is Not monitored', (int) \Zbx::$hosts[$on]['status'] === HOST_STATUS_NOT_MONITORED
		&& (int) \Zbx::$hosts[$masterid]['status'] === HOST_STATUS_NOT_MONITORED);
	check('the one that was already off is marked as such', in_array('ep-was-off=1', \Zbx::tagsOf($wasOff), true), \Zbx::tagsOf($wasOff));
	check('the one that was on is not', !in_array('ep-was-off=1', \Zbx::tagsOf($on), true), \Zbx::tagsOf($on));
	check('the count reported is of hosts actually switched off', (bool) preg_grep('/2 hosts set to Not monitored/', $life->done()), $life->done());
	$life2 = new Lifecycle($rec());
	$life2->enable('acme');
	check('enable puts back only what it switched off', (int) \Zbx::$hosts[$on]['status'] === HOST_STATUS_MONITORED
		&& (int) \Zbx::$hosts[$wasOff]['status'] === HOST_STATUS_NOT_MONITORED);
	check('decommission and restore move between two disabled states and keep the marker',
		(function () use ($rec, $wasOff) {
			$l = new Lifecycle($rec());
			$l->disable('acme');
			(new Lifecycle($rec()))->decommission('acme');
			$kept = in_array('ep-was-off=1', \Zbx::tagsOf($wasOff), true) && (new Lifecycle($rec()))->status('acme') === 'decommissioned';
			(new Lifecycle($rec()))->restore('acme');
			return $kept && (new Lifecycle($rec()))->status('acme') === 'disabled' && in_array('ep-was-off=1', \Zbx::tagsOf($wasOff), true);
		})());
	check('a save of a disabled client does not strip the was-off marker',
		(function () use ($rec, $spec, $client, $form, $wasOff) {
			$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.90.0.1', 'roles' => ['parser']], ['ip' => '10.90.0.2', 'roles' => ['parser']]])]));
			$rr = $rec();
			$rr->apply($c);
			return in_array('ep-was-off=1', \Zbx::tagsOf($wasOff), true) && in_array('ep-status=disabled', \Zbx::tagsOf($wasOff), true);
		})(), \Zbx::tagsOf($wasOff));

	/* ======================================================================================
	 *  IMPORT / EXPORT
	 * ====================================================================================== */

	/*
	 * Export then import has to be a round trip, or an operator who exports to edit one column
	 * silently changes every other one.
	 */
	$begin('CSV round trip');
	$servers = [['ip' => '10.100.0.11', 'roles' => ['es_data_hot'], 'services' => ['nginx'], 'notes' => 'rack 4', 'attrs' => ['rack' => 'R4']],
		['ip' => '10.100.0.12', 'roles' => ['parser', 's3_parser'], 'services' => [], 'notes' => '', 'attrs' => []]];
	$original = $form('acme', ['type' => 'DI', 'ulm_bucket' => 'acme-archive', 'ulm_region' => 'ap-south-1',
		'lead_name' => 'Asha Rao', 'cluster_dl' => 'soc-acme@corp.example', 'contract_end' => '2027-03-31',
		'servers' => $spec->canonServers($servers), 'purchased_by' => 'storage', 'purchased' => '40960']);
	$before = $client($original);
	$csv = Csv::export($roles, [$original]);
	$parsed = Csv::parse($csv, $roles);
	check('the export parses with no errors and no unknown columns', $parsed['errors'] === [] && $parsed['ignored'] === [], [$parsed['errors'], $parsed['ignored']]);
	check('one row, and its client column is the name', count($parsed['rows']) === 1 && $parsed['rows'][0]['client'] === 'acme');
	$backForm = Csv::toForm($parsed['rows'][0], $spec->defaults(), $roles);
	// array_merge, not +: $backForm already carries a 'servers' key from the defaults, and + keeps
	// the left-hand value, so the servers would silently stay empty and the comparison below
	// would pass while comparing the wrong thing.
	$after = $client(array_merge($backForm, ['servers' => $original['servers']]));
	check('every settings field survives the round trip', $before['fields'] === $after['fields'],
		array_diff_assoc($before['fields'], $after['fields']));
	$scsv = Csv::exportServers($roles, [$original]);
	$sparsed = Csv::parseServers($scsv, $roles);
	check('the servers export parses too', $sparsed['errors'] === [], $sparsed['errors']);
	['servers' => $backServers, 'errors' => $se] = $spec->parseServers(json_encode(array_map(
		fn($s) => array_diff_key($s, ['_line' => 1]), $sparsed['clients']['acme'])));
	check('and the servers come back identical, roles, services, notes and attributes',
		$se === [] && ClientSpec::encodeServers($backServers) === $original['servers'], [$se, ClientSpec::encodeServers($backServers)]);
	check('a role written by its label imports as the same role',
		Csv::parseServers("client,ip,roles\nacme,10.1.1.1,ES Data Hot\n", $roles)['clients']['acme'][0]['roles'] === ['es_data_hot']);
	check('a cell a spreadsheet would run as a formula is written as text and read back whole',
		strpos(Csv::export($roles, [$form('acme', ['lead_name' => '=cmd|calc'])]), "'=cmd|calc") !== false
		&& Csv::parse(Csv::export($roles, [$form('acme', ['lead_name' => '=cmd|calc'])]), $roles)['rows'][0]['soc_lead'] === '=cmd|calc');

	/*
	 * An import whose server list is shorter than what Zabbix holds. Deleting a host deletes its
	 * history, so the import may not do it quietly: with no servers.csv at all the servers are not
	 * touched, and with a short servers.csv every host that would go is named in the plan for the
	 * operator to tick.
	 */
	$begin('an import with a short server list');
	\Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']],
		'macros' => ['{$GRP.CLIENT}' => 'acme', '{$EP.CLIENT.TYPE}' => 'On-Prem', '{$ES.URL}' => 'https://es.acme.example:9243']]);
	foreach ([1 => '10.110.0.1', 2 => '10.110.0.2', 3 => '10.110.0.3'] as $n => $ip) {
		\Zbx::addHost(['host' => 'acme-Parser-'.$n, 'ip' => $ip, 'groups' => ['acme', 'Parser', 'Parsers'],
			'templates' => [Reconciler::agentTemplate()],
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'server'],
				['tag' => 'ep-role', 'value' => 'parser'], ['tag' => 'ep-service', 'value' => 'parser'], ['tag' => 'ep-status', 'value' => 'active']]]);
	}
	$r = $rec();
	$st = $state($r);
	$importer = new Importer($spec, $st);
	check('Zabbix holds three servers', count(json_decode($st->formFor('acme')['servers'], true)) === 3);
	// clients.csv alone: it carries no server column at all, so the servers must be left as they are.
	$onlyClients = Csv::parse("client,type\nacme,On-Prem\n", $roles);
	$plan = $importer->analyze($onlyClients, null);
	check('a clients.csv with no servers.csv is read without error', $plan['errors'] === [], $plan['errors']);
	check('and keeps all three servers — nothing is removed', count(json_decode($plan['rows'][0]['form']['servers'], true)) === 3,
		$plan['rows'][0]['form']['servers']);
	check('so there is nothing to delete in its plan',
		array_filter($plan['rows'][0]['diff']['hosts'] ?? [], fn($h) => $h['change'] === 'delete') === []);
	// A short servers.csv does change the list, and every host that would go has to be named.
	$short = Csv::parseServers("client,ip,roles\nacme,10.110.0.1,parser\n", $roles);
	$plan2 = (new Importer($spec, $state($rec())))->analyze(Csv::parse("client,type\nacme,On-Prem\n", $roles), $short);
	check('a short servers.csv is read without error', $plan2['errors'] === [], $plan2['errors']);
	check('it is an update, not something applied straight away', $plan2['rows'][0]['status'] === 'update');
	$deletes = array_values(array_filter($plan2['rows'][0]['diff']['hosts'], fn($h) => $h['change'] === 'delete'));
	check('and both hosts that would go are named, by host name and IP', count($deletes) === 2
		&& array_column($deletes, 'host') === ['acme-Parser-2', 'acme-Parser-3'], $deletes);
	check('the import itself changed nothing — checking a file is a read', \Zbx::$wrote === [], \Zbx::$wrote);
	check('a servers.csv for a client that is not one yet is an error, not a new client',
		(bool) preg_grep('/which is not a client yet/', (new Importer($spec, $state($rec())))->analyze(null, Csv::parseServers("client,ip,roles\nnobody,10.1.1.1,parser\n", $roles))['errors']));
	check('the same client twice in clients.csv is an error, whatever the case',
		(bool) preg_grep('/is in clients\.csv twice/', (new Importer($spec, $state($rec())))->analyze(Csv::parse("client,type\nacme,On-Prem\nACME,DI\n", $roles), null)['errors']));

	/*
	 * The same import from clients.xlsx. The workbook is only another way to write the two CSV
	 * files: Workbook::parse hands the Importer what Csv::parse / Csv::parseServers hand it, so a new
	 * client and its servers go through the same analyze and the same apply, and the Sample sheet is
	 * never read.
	 */
	$begin('an xlsx import: a new client and its servers');
	$lists = ['types' => Settings::types()['all'], 'proxies' => [], 'proxy_groups' => []];
	$book = Xlsx::build([
		['name' => 'Clients', 'rows' => [1 => [1 => 'Client *', 2 => 'Type ▾', 3 => 'ES URL *', 4 => 'Password in ▾', 5 => 'Vault path', 6 => 'Contract ends', 7 => 'ES Data Hot · CPU cores', 8 => 'ES Data Hot · Other disks'],
			2 => [1 => 'bajaj', 2 => 'On-Prem', 3 => 'https://es.bajaj.example:9243', 4 => 'Vault', 5 => 'secret/elasticpro/bajaj:password', 6 => ['v' => '2027-03-31', 'style' => 'date'], 7 => 16, 8 => '/data=4000']]],
		['name' => 'Servers', 'rows' => [1 => [1 => 'Client ▾ *', 2 => 'IP *', 3 => 'Host name', 4 => 'Role 1 ▾ *', 5 => 'Role 2 ▾', 6 => 'Rack'],
			2 => [1 => 'bajaj', 2 => '10.120.0.11', 4 => 'ES Data Hot', 6 => 'R4'], 3 => [1 => 'bajaj', 2 => '10.120.0.12', 4 => 'ES Master', 5 => 'ES Coordination']]],
		['name' => 'Sample', 'rows' => [1 => [1 => 'Client *'], 2 => [1 => 'acme-from-the-sample']]]
	]);
	$wb = Workbook::parse($book, $roles, $lists);
	check('the workbook reads with no error, the Sample skipped', $wb['errors'] === [] && $wb['skipped'] === ['Sample'], $wb['errors']);
	$xplan = (new Importer($spec, $state($rec())))->analyze($wb['clients'], $wb['servers']);
	check('the Importer plans one new client and no error', $xplan['errors'] === [] && count($xplan['rows']) === 1 && $xplan['rows'][0]['status'] === 'new'
		&& $xplan['rows'][0]['name'] === 'bajaj', [$xplan['errors'], array_column($xplan['rows'], 'status')]);
	$csvPlan = (new Importer($spec, $state($rec())))->analyze(
		Csv::parse("client,type,es_url,es_password_in,es_password_vault,contract_end,es_data_hot_cpu_cores,es_data_hot_disk2_mount,es_data_hot_disk2_gb,es_data_hot_disk3_mount,es_data_hot_disk3_gb,es_data_hot_disk4_mount,es_data_hot_disk4_gb,es_data_hot_disk5_mount,es_data_hot_disk5_gb\n"
			."bajaj,On-Prem,https://es.bajaj.example:9243,vault,secret/elasticpro/bajaj:password,2027-03-31,16,/data,4000,,,,,,\n", $roles),
		Csv::parseServers("client,ip,name,roles,rack\nbajaj,10.120.0.11,,es_data_hot,R4\nbajaj,10.120.0.12,,es_master;es_coord,\n", $roles));
	check('and it is the very plan the same clients.csv and servers.csv give', $csvPlan['errors'] === [] && $csvPlan['rows'][0]['form'] === $xplan['rows'][0]['form'],
		array_diff_assoc($xplan['rows'][0]['form'], $csvPlan['rows'][0]['form'] ?? []));
	\Zbx::$wrote = [];
	$xr = $rec();
	['client' => $xc] = $spec->fromForm($xplan['rows'][0]['form']);
	$xr->apply($xc);
	$now = $state($rec())->formFor('bajaj');
	$ips = array_column((array) json_decode($now['servers'], true), 'roles', 'ip');
	check('applied as ImportUpload applies it: the client and both servers are in Zabbix, roles as on the sheet', $ips === ['10.120.0.11' => ['es_data_hot'], '10.120.0.12' => ['es_coord', 'es_master']]
		|| $ips === ['10.120.0.11' => ['es_data_hot'], '10.120.0.12' => ['es_master', 'es_coord']], $ips);
	check('the settings from the Clients sheet are on the client, read back', $now['contract_end'] === '2027-03-31' && $now['es_data_hot_cpu'] === '16'
		&& $now['es_data_hot_disk2_fs'] === '/data' && $now['es_data_hot_disk2'] === '4000', [$now['contract_end'], $now['es_data_hot_cpu'], $now['es_data_hot_disk2_fs']]);
	check('and the Importer\'s read-back finds nothing different', (new Importer($spec, $state($rec())))->verify($xc) === [], (new Importer($spec, $state($rec())))->verify($xc));
	check('nothing from the Sample sheet was made', !isset($state($rec())->clients()['acme-from-the-sample']));
	$bad = Workbook::parse(Xlsx::build([['name' => 'Servers', 'rows' => [1 => [1 => 'Client ▾ *', 2 => 'IP *', 3 => 'Role 1 ▾ *', 4 => 'Role 2 ▾'],
		14 => [1 => 'bajaj', 2 => '10.120.0.31', 3 => 'ES Master', 4 => 'ES Hot']]]]), $roles, $lists);
	check('a role not on the Roles page stops the file, saying where and how to fix it',
		in_array('Servers, row 14 (10.120.0.31): Role 2 is "ES Hot" — not one of the roles. Pick it from the dropdown, or leave it empty.', $bad['errors'], true), $bad['errors']);

	/*
	 * A client Zabbix refuses is never reported as added. Before: "Backup taken. 1 new client added:
	 * QA-Excel." and then "Read back — differs: Nothing was saved. These templates are not in
	 * Zabbix …", with nothing created. Now Check says so first, and an apply that is refused anyway
	 * comes back as failed, with what it wrote and what is in Zabbix now.
	 */
	$begin('a client whose template is missing: told at Check, and never counted as added');
	foreach (\Zbx::$templates as $id => $t) {
		if ($t['host'] === Reconciler::ulmTemplate()) { unset(\Zbx::$templates[$id]); }
	}
	$diBook = Xlsx::build([['name' => 'Clients', 'rows' => [1 => [1 => 'Client *', 2 => 'Type ▾', 3 => 'ES URL *', 4 => 'Vault path', 5 => 'S3 bucket', 6 => 'S3 region'],
		2 => [1 => 'QA-Excel', 2 => 'DI', 3 => 'https://es.qa.example:9243', 4 => 'secret/elasticpro/qa:password', 5 => 'qa-archive', 6 => 'ap-south-1']]]]);
	$dw = Workbook::parse($diBook, $roles, $lists);
	$dplan = (new Importer($spec, $state($rec())))->analyze($dw['clients'], $dw['servers']);
	check('Check names the missing template, before anything is applied',
		(bool) preg_grep('/^QA-Excel: DI needs the template "'.preg_quote(Reconciler::ulmTemplate(), '/').'", which is not in this Zabbix\./', $dplan['errors'])
		&& $dplan['rows'][0]['status'] === 'error' && \Zbx::$wrote === [], $dplan['errors']);
	// Applied regardless (a template removed between Check and Apply): reported as failed, not added.
	$res = (new Importer($spec, $state($rec())))->applyRows([['form' => array_merge(Csv::toForm($dw['clients']['rows'][0], $spec->defaults(), $roles), ['servers' => '[]'])]], $rec, fn($n) => null);
	check('the apply result has 0 applied and 1 failed, with Zabbix\'s reason, and nothing in Zabbix', $res['applied'] === [] && count($res['failed']) === 1
		&& $res['failed'][0]['name'] === 'QA-Excel' && str_contains($res['failed'][0]['error'], 'These templates are not in Zabbix') && $res['failed'][0]['exists'] === []
		&& !isset($state($rec())->clients()['QA-Excel']), $res);
	// A refusal half-way: the master host is made, then Zabbix refuses the cluster host.
	$begin('a client refused half-way says what exists now');
	\Zbx::$deny['UserMacro.create'] = 'No permissions to referred object or it does not exist!';
	$res = (new Importer($spec, $state($rec())))->applyRows([['form' => $form('halfway', ['servers' => $spec->canonServers([['ip' => '10.130.0.1', 'roles' => ['parser']]])])]], $rec, fn($n) => null);
	check('partial failure: not applied; what was written before it stopped, and what is in Zabbix now', $res['applied'] === [] && count($res['failed']) === 1
		&& str_contains($res['failed'][0]['error'], 'No permissions to referred object') && $res['failed'][0]['done'] !== []
		&& $res['failed'][0]['exists'] === array_values(array_map(fn($h) => $h['name'], array_filter(\Zbx::$hosts, fn($h) => in_array('halfway', array_column($h['hostgroups'], 'name'), true)))), $res['failed']);

	/*
	 * Host name: empty — named from the client and roles, as before; given — kept as the host's own,
	 * marked ep-name=own, and no later save takes it away. Checked at Check time: Zabbix's rule for
	 * a host name, and unique in Zabbix.
	 */
	$begin('Server name: the visible name of a role host whose host name is its IP');
	$hn = fn(array $servers) => Workbook::parse(Xlsx::build([
		['name' => 'Clients', 'rows' => [1 => [1 => 'Client *', 2 => 'Type ▾', 3 => 'ES URL *', 4 => 'Vault path'], 2 => [1 => 'named', 2 => 'On-Prem', 3 => 'https://es.named.example:9243', 4 => 'secret/elasticpro/named:password']]],
		['name' => 'Servers', 'rows' => [1 => [1 => 'Client ▾ *', 2 => 'IP *', 3 => 'Host name', 4 => 'Role 1 ▾ *']] + $servers]]), $roles, $lists);
	$hw = $hn([2 => [1 => 'named', 2 => '10.140.0.1', 3 => 'named-edge-01', 4 => 'Forwarder'], 3 => [1 => 'named', 2 => '10.140.0.2', 4 => 'Parser']]);
	$hplan = (new Importer($spec, $state($rec())))->analyze($hw['clients'], $hw['servers']);
	check('a valid, free server name passes Check', $hplan['errors'] === [] && $hplan['rows'][0]['status'] === 'new', $hplan['errors']);
	$res = (new Importer($spec, $state($rec())))->applyRows($hplan['rows'], $rec, fn($n) => null);
	$edge = \Zbx::byHost('10.140.0.1');
	check('host name = IP, visible name = the server name given; without one, the IP', $res['failed'] === [] && $res['applied'][0]['problems'] === []
		&& $edge !== null && $edge['name'] === 'named-edge-01' && (\Zbx::byHost('10.140.0.2')['name'] ?? null) === '10.140.0.2', [$res, array_column(\Zbx::$hosts, 'name', 'host')]);
	$nform = $state($rec())->formFor('named');
	check('read back, the named server carries its server name; the other none', str_contains($nform['servers'], '"host":"named-edge-01"') && substr_count($nform['servers'], '"host"') === 1, $nform['servers']);
	// A later save that changes its roles keeps the name.
	$again = array_merge(ClientState::plain($nform), ['servers' => $spec->canonServers([['ip' => '10.140.0.1', 'roles' => ['forwarder', 'engine']], ['ip' => '10.140.0.2', 'roles' => ['parser']]])]);
	['client' => $ac] = $spec->fromForm($again);
	$rec()->apply($ac);
	check('a later save (from the client page) changes the roles and keeps both names', (\Zbx::byHost('10.140.0.1')['name'] ?? null) === 'named-edge-01'
		&& \Zbx::byHost('10.140.0.1')['hostid'] === $edge['hostid'], array_column(\Zbx::$hosts, 'name', 'host'));
	// The export carries every host's name; importing it back changes nothing.
	[$forms0, $names0] = [[ClientState::plain($state($rec())->formFor('named'))], []];
	foreach ($state($rec())->formFor('named')['_now']['machines'] as $h) { $names0['named'][$h['_ip']] = $h['name']; }
	$round = Workbook::parse(Workbook::build($roles, $lists, $forms0, $names0), $roles, $lists);
	$rplan = (new Importer($spec, $state($rec())))->analyze($round['clients'], $round['servers']);
	check('the export, imported back: the same client, nothing to change', $rplan['errors'] === [] && $rplan['rows'][0]['status'] === 'same', [$rplan['errors'], $rplan['rows'][0]['diff'] ?? null]);
	// Renaming an existing server: shown for a tick, then applied, history kept (same host).
	$rw = $hn([2 => [1 => 'named', 2 => '10.140.0.1', 3 => 'named-edge-01', 4 => 'Forwarder'], 3 => [1 => 'named', 2 => '10.140.0.2', 3 => 'named-parser-a', 4 => 'Parser']]);
	$rp = (new Importer($spec, $state($rec())))->analyze($rw['clients'], $rw['servers']);
	$renames = array_values(array_filter($rp['rows'][0]['diff']['hosts'] ?? [], fn($h) => $h['change'] === 'rename'));
	check('a new server name for an existing server is a change to tick', $rp['errors'] === [] && $rp['rows'][0]['status'] === 'update'
		&& count($renames) === 1 && $renames[0]['host'] === '10.140.0.2' && $renames[0]['as'] === 'named-parser-a', [$rp['errors'], $rp['rows'][0]['diff'] ?? null]);
	$parserId = \Zbx::byHost('10.140.0.2')['hostid'];
	$res = (new Importer($spec, $state($rec())))->applyRows($rp['rows'], $rec, fn($n) => null);
	check('applied: the same host, its host name still the IP, its visible name the server name', $res['failed'] === []
		&& (\Zbx::byHost('10.140.0.2')['hostid'] ?? null) === $parserId && \Zbx::$hosts[$parserId]['name'] === 'named-parser-a', $res);
	// Refused at Check: a name Zabbix would not take, one another host has, one given twice.
	\Zbx::addHost(['host' => 'taken-host', 'groups' => ['Elsewhere']]);
	foreach ([[3 => str_repeat('x', 129), 'want' => '/server name "x{129}" is longer than 128 characters/'],
			[3 => 'taken-host', 'want' => '/server name "taken-host" for 10\.140\.0\.9 is already taken in Zabbix \(by host "taken-host"\)/'],
			[3 => 'named-edge-01', 'want' => '/server name "named-edge-01" is already given to 10\.140\.0\.1\. Server names are unique\./']] as $case) {
		$bw = $hn([2 => [1 => 'named', 2 => '10.140.0.1', 4 => 'Forwarder'], 3 => [1 => 'named', 2 => '10.140.0.2', 4 => 'Parser'], 4 => [1 => 'named', 2 => '10.140.0.9', 3 => $case[3], 4 => 'Engine']]);
		$bp = (new Importer($spec, $state($rec())))->analyze($bw['clients'], $bw['servers']);
		check('Check refuses server name "'.$case[3].'"', (bool) preg_grep($case['want'], $bp['errors']), $bp['errors']);
	}
	$tw = $hn([2 => [1 => 'named', 2 => '10.140.0.1', 4 => 'Forwarder'], 3 => [1 => 'named', 2 => '10.140.0.2', 4 => 'Parser'], 4 => [1 => 'named', 2 => '10.140.0.8', 3 => 'twice', 4 => 'Engine'], 5 => [1 => 'named', 2 => '10.140.0.9', 3 => 'Twice', 4 => 'UEBA']]);
	$tp = (new Importer($spec, $state($rec())))->analyze($tw['clients'], $tw['servers']);
	check('Check refuses the same server name twice in the file', (bool) preg_grep('/server name "Twice" is already given to 10\.140\.0\.8/', $tp['errors']), $tp['errors']);

	/*
	 * A backup of an empty client list. Only three backups are kept, so a backup of nothing is not
	 * harmless: three of them push every real copy out. An empty list is far likelier to be a
	 * lookup that missed — the clients are found by a template name and a tag that both changed in
	 * the rename — than a site whose clients have all gone.
	 */
	$begin('a backup taken while the client list is empty');
	foreach (glob($dir.'/backups/*.json') ?: [] as $f) { unlink($f); }
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']],
		'macros' => ['{$GRP.CLIENT}' => 'acme', '{$EP.CLIENT.TYPE}' => 'DI', '{$EP.LEAD}' => 'Asha Rao']]);
	$good = Backups::take('a real save', 'tester', $state($rec()), $roles);
	check('a backup of a client that is there holds it', count(Backups::get($good)['clients']) === 1
		&& Backups::get($good)['clients']['acme']['lead_name'] === 'Asha Rao');
	// Now the lookup misses: no master template, no kind tag — exactly what reading one generation
	// of the names did on the live install.
	\Zbx::$hosts[$masterid]['parentTemplates'] = [];
	\Zbx::$hosts[$masterid]['tags'] = [];
	check('the list really does come back empty', $state($rec())->clients() === []);
	$code = 0; $why = '';
	try { Backups::take('a save that must not happen', 'tester', $state($rec()), $roles); }
	catch (\RuntimeException $e) { $code = $e->getCode(); $why = $e->getMessage(); }
	check('the backup is refused with its own code, so a caller can tell this apart from an unwritable folder',
		$code === Backups::REFUSED_EMPTY);
	check('and says nothing was changed and what to check', strpos($why, 'Nothing has been changed and no backup was taken') !== false
		&& strpos($why, 'Open Cluster Management and check that it lists its clients') !== false, $why);
	check('the backup that holds the client is still there', count(Backups::list()) === 1 && Backups::list()[0]['clients'] === 1);
	// Three refusals in a row must not wear the real copies down.
	for ($i = 0; $i < 3; $i++) {
		try { Backups::take('again', 'tester', $state($rec()), $roles); } catch (\RuntimeException $e) { }
	}
	check('three refused saves leave the real copy untouched', count(Backups::list()) === 1 && Backups::list()[0]['clients'] === 1, Backups::list());
	// The restore is the one caller allowed to snapshot an empty present, because that snapshot is
	// the only way back from a restore that turns out to be the wrong one.
	$empty = Backups::take('before restoring', 'tester', $state($rec()), $roles, true);
	check('a restore may take its snapshot even so', $empty !== '' && count(Backups::get($empty)['clients']) === 0);
	check('and that snapshot does not push out the copy that holds clients',
		count(array_filter(Backups::list(), fn($b) => $b['clients'] > 0)) === 1, Backups::list());

	/*
	 * A backup taken by a Zabbix Admin whom ElasticPro calls an admin. Backups::take() reads the
	 * clients through the Zabbix API as that person (ClientState, not a register of its own), so
	 * it holds only the clients they can see: a partial backup. It must say so, never push a full
	 * Super admin backup out, and a restore of it must leave every other client alone.
	 */
	$begin('a backup taken by someone who sees only some clients is partial');
	foreach (glob($dir.'/backups/*.json') ?: [] as $f) { unlink($f); }
	foreach (['north', 'south'] as $c) {
		\Zbx::addHost(['host' => $c.'-Master', 'groups' => [$c, Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
			'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']],
			'macros' => ['{$GRP.CLIENT}' => $c, '{$EP.CLIENT.TYPE}' => 'DI', '{$EP.LEAD}' => 'Lead '.$c]]);
	}
	$full = Backups::take('a Super admin save', 'Admin', $state($rec()), $roles);
	check('a Super admin\'s backup holds both clients and is full',
		array_keys(Backups::get($full)['clients']) === ['north', 'south'] && !Backups::isPartial(Backups::get($full)));
	// A Zabbix Admin with no permission on south's host group (nor the masters group).
	\Zbx::$hiddenGroups = ['south', Reconciler::MASTERS_GROUP];
	check('the Admin sees north only', array_keys($state($rec())->clients()) === ['north'], array_keys($state($rec())->clients()));
	$part = Backups::take('an ElasticPro admin\'s save', 'ep-qa-admin', $state($rec()), $roles, false, false);
	$pb = Backups::get($part);
	check('their backup does not contain the client they cannot see — it is a view, not the full register',
		array_keys($pb['clients']) === ['north'], array_keys($pb['clients']));
	check('so it is recorded as partial, with its scope and the total known from the full backup',
		Backups::isPartial($pb) && $pb['scope'] === ['north'] && $pb['scope_of'] === 2, [$pb['scope'] ?? null, $pb['scope_of'] ?? null]);
	check('the Backups list labels it "partial: 1 of 2 clients"',
		(array_values(array_filter(Backups::list(), fn($b) => $b['id'] === $part))[0]['scope_label'] ?? '') === 'partial: 1 of 2 clients', Backups::list());
	check('a restore of it leaves the client outside its scope alone', Backups::outsideScope($pb, ['north', 'south']) === ['south']);
	check('a restore of a full backup leaves nobody out', Backups::outsideScope(Backups::get($full), ['north', 'south']) === []);
	for ($i = 0; $i < 4; $i++) {
		Backups::take('another admin save '.$i, 'ep-qa-admin', $state($rec()), $roles, false, false);
	}
	$listed = Backups::list();
	check('five partial backups never push the full Super admin backup out',
		count(array_filter($listed, fn($b) => $b['id'] === $full)) === 1, $listed);
	check('partial backups rotate among themselves: three kept',
		count(array_filter($listed, fn($b) => $b['partial'])) === Backups::KEEP, $listed);
	\Zbx::$hiddenGroups = [];
	for ($i = 0; $i < 3; $i++) {
		Backups::take('a Super admin save '.$i, 'Admin', $state($rec()), $roles);
	}
	$listed = Backups::list();
	check('and full ones among themselves: three full and three partial',
		count(array_filter($listed, fn($b) => !$b['partial'])) === Backups::KEEP && count(array_filter($listed, fn($b) => $b['partial'])) === Backups::KEEP, $listed);
	foreach (glob($dir.'/backups/*.json') ?: [] as $f) { unlink($f); }
	\Zbx::$hiddenGroups = ['south', Reconciler::MASTERS_GROUP];
	$lone = Backups::get(Backups::take('an admin save, no full backup yet', 'ep-qa-admin', $state($rec()), $roles, false, false));
	check('with no full backup to count from, the total is unknown, not a guess',
		$lone['scope_of'] === null && Backups::scopeLabel($lone) === 'partial: 1 clients of an unknown total', $lone['scope_of'] ?? 'missing');
	\Zbx::$hiddenGroups = [];
	$base_src = file_get_contents(__DIR__.'/../actions/Base.php');
	check('Base::backup() takes a full backup only for a Super admin',
		str_contains($base_src, "Backups::take(\$before, \$this->user(), \$this->state(), \$this->roles(), false, \$this->superOnly())"));
	$restore_src = file_get_contents(__DIR__.'/../actions/BackupRestore.php');
	check('BackupRestore neither removes nor overwrites a client outside a partial backup\'s scope',
		str_contains($restore_src, '$outside = Backups::outsideScope($b, array_keys($live));')
		&& str_contains($restore_src, "array_diff(array_keys(\$live), array_keys(\$b['clients']), \$outside)")
		&& substr_count($restore_src, 'if (in_array((string) $name, $outside, true)) {') === 2);
	foreach (glob($dir.'/backups/*.json') ?: [] as $f) { unlink($f); }

	/*
	 * Restoring a backup from before the rename: schema 1, one disk per role, IPs held per role
	 * rather than per server. It has to come back in today's terms or the restore writes a client
	 * with no servers.
	 */
	$begin('restoring a pre-rename backup');
	foreach (glob($dir.'/backups/*.json') ?: [] as $f) { unlink($f); }
	$oldId = gmdate('Ymd-His').'-abcd';
	Store::write(Backups::DIR.'/'.$oldId.'.json', ['id' => $oldId, 'schema' => 1, 'taken' => time(), 'by' => 'old version',
		'before' => 'a save on 2.7.1', 'roles' => $roles, 'machines' => 3, 'clients' => ['acme' => [
			'name' => 'acme', 'type' => 'DI', 'es_url' => 'https://es.acme.example:9243', 'lead_name' => 'Asha Rao',
			'ulm_bucket' => 'acme-archive', 'ulm_region' => 'ap-south-1',
			'ips_es_data_hot' => "10.120.0.11 10.120.0.12", 'ips_parser' => '10.120.0.21',
			'es_data_hot_disk_fs' => '/data', 'es_data_hot_disk' => '4096', 'ulm_tags' => 'dropped', 'es_jumphost' => 'dropped']]]);
	$old = Backups::get($oldId);
	check('it is read, and brought up to today\'s schema', $old !== null && (int) $old['schema'] === Backups::SCHEMA);
	$f = $old['clients']['acme'];
	check('the per-role IP lists become servers', count(json_decode($f['servers'], true)) === 3, $f['servers']);
	check('each server carries the role its list named', json_decode($f['servers'], true)[0]['roles'] === ['es_data_hot']
		&& json_decode($f['servers'], true)[2]['roles'] === ['parser'], $f['servers']);
	check('a role\'s one disk on a mount that is not / becomes its second disk, and / is not claimed',
		$f['es_data_hot_disk2_fs'] === '/data' && $f['es_data_hot_disk2'] === '4096' && $f['es_data_hot_disk'] === '0');
	check('fields the form no longer has are dropped', !array_key_exists('ulm_tags', $f) && !array_key_exists('es_jumphost', $f)
		&& !array_key_exists('ips_parser', $f));
	check('the settings it did keep are still there', $f['type'] === 'DI' && $f['lead_name'] === 'Asha Rao' && $f['ulm_bucket'] === 'acme-archive');
	check('and every field of today\'s form has a value, so a restore cannot write a blank it never meant',
		array_diff(array_keys($spec->defaults()), array_keys($f)) === [], array_diff(array_keys($spec->defaults()), array_keys($f)));
	check('the restored form is valid as it stands', $spec->fromForm($f)['errors'] === [], $spec->fromForm($f)['errors']);
	check('an id that is not an id is refused rather than read as a path', Backups::get('../../etc/passwd') === null
		&& Backups::get('20260101-000000-zzzz') === null);

	/* ======================================================================================
	 *  STALE DATA
	 * ====================================================================================== */

	/*
	 * The live install: a master host whose every setting is under a {$EVP.…} macro. The form has
	 * to read the real values, and the save has to be refused until the migration has run —
	 * saving would write the shipped defaults over requested CPU, memory and disk per role, the
	 * client type, the jump-host fields, the lead and the cluster DL, record the destroyed version
	 * as the backup, and have the alert routing conclude the client wants no alerts and delete its
	 * action, DL group, DL account and weekly report.
	 */
	$begin('a master host still on the pre-rename macros');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::LEGACY_MASTERS_GROUP],
		'templates' => [MasterTemplate::LEGACY_NAME],
		'tags' => [Reconciler::LEGACY_MANAGED, ['tag' => 'evp-client', 'value' => 'acme'], ['tag' => 'evp-kind', 'value' => 'master']],
		'macros' => ['{$GRP.CLIENT}' => 'acme', '{$EVP.CLIENT.TYPE}' => 'DI', '{$EVP.LEAD}' => 'Asha Rao',
			'{$EVP.DL}' => 'soc-acme@corp.example', '{$EVP.CONTRACT.END}' => '2027-03-31',
			'{$EVP.ES_DATA_HOT.CPU.REQUESTED}' => '64', '{$EVP.ES_DATA_HOT.MEMORY.REQUESTED}' => '256',
			'{$EVP.ES_DATA_HOT.ROOTDISK.REQUESTED}' => '8192', '{$EVP.ES_MASTER.CPU.REQUESTED}' => '16',
			'{$EVP.MONITORED.BY}' => 'proxy:dc-proxy', '{$ES.URL}' => 'https://es.acme.example:9243',
			'{$ULM.S3.BUCKET}' => 'acme-archive', '{$ULM.S3.REGION}' => 'ap-south-1']]);
	\Zbx::addHost(['host' => 'acme-ES-Cluster', 'groups' => ['acme', Reconciler::CLUSTER_GROUP],
		'templates' => [Reconciler::LEGACY_CLUSTER_TEMPLATE], 'tags' => [Reconciler::LEGACY_MANAGED, ['tag' => 'evp-kind', 'value' => 'cluster']]]);
	\Zbx::addHost(['host' => 'acme-ULM', 'groups' => ['acme', Reconciler::ULM_GROUP],
		'templates' => [Reconciler::LEGACY_ULM_TEMPLATE], 'tags' => [Reconciler::LEGACY_MANAGED, ['tag' => 'evp-kind', 'value' => 'archive']]]);
	\Zbx::addProxy('dc-proxy', time());
	$r = $rec();
	$now = $r->current('acme');
	check('the client, its cluster host and its archive host are all recognised',
		$now['master']['hostid'] === $masterid && $now['cluster'] !== null && $now['ulm'] !== null);
	$f = $state($rec())->formFor('acme');
	$defaults = $spec->defaults();
	check('the form shows the real client type, not the shipped default', $f['type'] === 'DI' && $defaults['type'] !== 'DI');
	check('the real requested CPU, memory and disk', $f['es_data_hot_cpu'] === '64' && $f['es_data_hot_mem'] === '256'
		&& $f['es_data_hot_disk'] === '8192' && $f['es_master_cpu'] === '16',
		[$f['es_data_hot_cpu'], $f['es_data_hot_mem'], $f['es_data_hot_disk'], $f['es_master_cpu']]);
	check('the real contacts and contract end', $f['lead_name'] === 'Asha Rao' && $f['cluster_dl'] === 'soc-acme@corp.example'
		&& $f['contract_end'] === '2027-03-31');
	check('the real proxy', $f['monitored_by'] === 'proxy:dc-proxy');
	check('macros that never carried the product name are read as they always were',
		$f['es_url'] === 'https://es.acme.example:9243' && $f['ulm_bucket'] === 'acme-archive');
	check('not one field of the form fell back to its default where the host says otherwise',
		$f['es_data_hot_cpu'] !== $defaults['es_data_hot_cpu'] && $f['lead_name'] !== $defaults['lead_name']);
	// And the refusal: the form reading correctly is not enough, because a field whose fallback
	// was missed is invisible. legacyMacrosIn() is a blanket scan of the prefix for that reason.
	$legacy = ClientSpec::legacyMacrosIn(\Zbx::macrosOf($masterid));
	check('every pre-rename macro on the host is named, sorted, and only those', count($legacy) === 9
		&& $legacy[0] === '{$EVP.CLIENT.TYPE}' && in_array('{$EVP.MONITORED.BY}', $legacy, true)
		&& !in_array('{$ES.URL}', $legacy, true) && !in_array('{$ULM.S3.BUCKET}', $legacy, true), $legacy);
	check('a host of today\'s generation carries none', ClientSpec::legacyMacrosIn(['{$EP.LEAD}' => 'x', '{$ES.URL}' => 'y']) === []);
	$c = $client(array_merge($f, ['es_password_mode' => 'vault']));
	$problems = $rec()->problems($c, $now);
	check('the save is refused, and names the migration to run',
		(bool) preg_grep('/still stores its settings under the previous names.*python3 setup\/zbx_rename\.py --apply/', $problems), $problems);
	check('and names some of the macros that stopped it', (bool) preg_grep('/\{\$EVP\.CLIENT\.TYPE\}/', $problems), $problems);
	check('with a count for the rest, rather than all nine', (bool) preg_grep('/and 5 more/', $problems), $problems);
	$said = '';
	try { $rec()->apply($c); } catch (\Exception $e) { $said = $e->getMessage(); }
	check('nothing is written: not one macro, not one tag', \Zbx::$wrote === [] && $said !== '', \Zbx::$wrote);
	check('the host still carries its real values afterwards', \Zbx::macrosOf($masterid)['{$EVP.ES_DATA_HOT.CPU.REQUESTED}'] === '64');

	/*
	 * A host carrying both generations of the same tag with different values — a migration run
	 * half way, or a host touched by both releases. Both values are readable, and one save brings
	 * the host onto today's spelling without inventing a value.
	 */
	$begin('both generations of a tag, with different values');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']], 'macros' => ['{$GRP.CLIENT}' => 'acme']]);
	// In the family group but in no role group, and showing no templates, so the roles can only
	// come from its ep-role tags — which is where the two generations disagree.
	$srv = \Zbx::addHost(['host' => 'acme-Parser-1', 'ip' => '10.130.0.1', 'groups' => ['acme', 'Parsers'],
		'templates' => [],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-role', 'value' => 'parser'], ['tag' => 'evp-role', 'value' => 's3_parser'],
			['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'evp-client', 'value' => 'acme-old'],
			['tag' => 'inv:rack', 'value' => 'R4']]]);
	$now = $rec()->current('acme');
	$host = $now['machines'][$srv];
	check('both values of the role tag are readable, today\'s first',
		Reconciler::tagValues($host, 'ep-role') === ['parser', 's3_parser'], Reconciler::tagValues($host, 'ep-role'));
	check('both values of the client tag too — a disagreement is visible, not hidden',
		Reconciler::tagValues($host, 'ep-client') === ['acme', 'acme-old'], Reconciler::tagValues($host, 'ep-client'));
	check('with no role group to go on, the tags give the roles', $host['_roles'] === ['parser', 's3_parser'], $host['_roles']);
	check('an inv: tag is not one of ours and is read as an attribute', $host['_attrs'] === ['rack' => 'R4']);
	$c = $client($form('acme', ['es_url' => '', 'servers' => json_encode([['ip' => '10.130.0.1', 'roles' => ['parser'], 'attrs' => ['rack' => 'R5']]])]));
	$rec()->apply($c);
	$after = \Zbx::tagsOf($srv);
	check('after a save the host carries one spelling of each of our tags', !preg_grep('/^evp-/', $after), $after);
	check('today\'s values are what is left', in_array('ep-client=acme', $after, true) && in_array('ep-role=parser', $after, true)
		&& !in_array('ep-role=s3_parser', $after, true), $after);
	check('and the managed-by marker is brought forward, not doubled',
		count(preg_grep('/^managed-by=/', $after)) === 1 && in_array('managed-by=elasticpro-clients', $after, true), $after);
	check('the attribute given replaces its inv: tag; it is not left there twice',
		in_array('inv:rack=R5', $after, true) && !in_array('inv:rack=R4', $after, true), $after);

	/*
	 * A cluster host still linked to the pre-rename jump host template. Both generations carry the
	 * same item keys, so Zabbix refuses to link both to one host, and it refuses in the middle of
	 * the save. This page will not unlink it on the operator's behalf: which generation of items
	 * the host keeps afterwards is a decision about item history, and item history does not come
	 * back.
	 */
	$begin('a cluster host still on the pre-rename jump template');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']], 'macros' => ['{$GRP.CLIENT}' => 'acme']]);
	$clusterid = \Zbx::addHost(['host' => 'acme-ES-Cluster', 'groups' => ['acme', Reconciler::CLUSTER_GROUP],
		'templates' => [JumpTemplate::LEGACY_NAME], 'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'cluster']],
		'macros' => [Reconciler::ES_PASSWORD_MACRO => ['kept', ZBX_MACRO_TYPE_SECRET]]]);
	$r = $rec();
	$now = $r->current('acme');
	check('the cluster host is recognised by the pre-rename jump template', ($now['cluster']['hostid'] ?? '') === $clusterid);
	// The form says "reached directly", because the macros a jump-host client keeps are the ones
	// the rename moved: on a host like this throughJump() can only return the shipped default.
	// The guard has to read the TEMPLATE on the host, which is readable whatever the macros say.
	$direct = $client($form('acme', ['monitored_by' => '']));
	check('the form reads as reached directly, which is the shipped default', !$spec->throughJump($direct));
	$problems = $r->problems($direct, $now);
	check('the clash is still refused', (bool) preg_grep('/is still linked to "'.preg_quote(JumpTemplate::LEGACY_NAME, '/').'"/', $problems), $problems);
	check('and the message says Unlink, never Unlink and clear, and why',
		(bool) preg_grep('/"Unlink", never "Unlink and clear", which deletes those items and every value they hold/', $problems), $problems);
	check('it names the host to go to and the template that takes over',
		(bool) preg_grep('/'.preg_quote(JumpTemplate::NAME, '/').'/', $problems) && (bool) preg_grep('/acme-ES-Cluster/', $problems), $problems);
	$said = '';
	try { $rec()->apply($direct); } catch (\Exception $e) { $said = $e->getMessage(); }
	check('nothing is written, so the host keeps both its items and its history', \Zbx::$wrote === [] && $said !== '', \Zbx::$wrote);
	check('the legacy template is still linked: this page does not unlink it for them',
		in_array(JumpTemplate::LEGACY_NAME, array_column(\Zbx::$hosts[$clusterid]['parentTemplates'], 'host'), true));
	// A client that really is asked through a jump host hits the same guard, for the same reason.
	$viaJump = $client($form('acme', ['monitored_by' => 'jump', 'jump_host' => 'jump-win.acme.internal', 'jump_user' => 'jump-user',
		'es_apikey_path' => 'secret/elasticpro/acme:apikey']));
	check('and so does a client the form does say is reached through one', $spec->throughJump($viaJump)
		&& (bool) preg_grep('/is still linked to/', $rec()->problems($viaJump, $rec()->current('acme'))));
	// Without the legacy template there is nothing to refuse: the guard is about the host, and it
	// must stay quiet when the host is clean.
	\Zbx::hostMassRemove(['hostids' => [$clusterid], 'templateids' => array_column(\Zbx::$hosts[$clusterid]['parentTemplates'], 'templateid')]);
	\Zbx::hostMassAdd(['hosts' => [['hostid' => $clusterid]], 'templates' => [['templateid' => \Zbx::addTemplate(JumpTemplate::NAME)]]]);
	check('a cluster host on today\'s jump template is not refused',
		!preg_grep('/is still linked to/', $rec()->problems($viaJump, $rec()->current('acme'))),
		$rec()->problems($viaJump, $rec()->current('acme')));
	// TemplateInstaller spells out the procedure, and says whether the leftover template is one
	// this page wrote — an operator must not be told to unlink a template a site imported itself.
	$begin('the procedure for a leftover template');
	// The uuid seed is the key TemplateInstaller::LEGACY_TEMPLATES holds the master template
	// under, which is how it tells a template it wrote itself from one a site imported.
	$tid = \Zbx::addTemplate(MasterTemplate::LEGACY_NAME, MasterTemplate::legacyUuid('template'));
	\Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme'], 'templates' => [MasterTemplate::LEGACY_NAME, MasterTemplate::NAME]]);
	$collisions = TemplateInstaller::legacyCollision();
	check('the leftover template is found, with the hosts still on it', count($collisions) === 1
		&& $collisions[0]['template'] === MasterTemplate::LEGACY_NAME && count($collisions[0]['hosts']) === 1, $collisions);
	check('and it says the host is on today\'s template as well', $collisions[0]['hosts'][0]['also_current'] === true);
	check('and that this page wrote it, so it is safe to say unlink', $collisions[0]['written_here'] === true);

	/* ---------------- the template mapping, against what Zabbix really has ---------------- */
	// The mapping's job is to make a naming problem visible on the page instead of letting it
	// fail silently later, so what matters is what each row reports about this Zabbix.
	$scene = 'template mapping';
	Roles::saveTemplateNames([]);
	$rows = TemplateMap::rows();
	check('every slot gets a row', array_keys($rows) === array_keys(Roles::TEMPLATE_SLOTS), array_keys($rows));
	check('an unmapped row shows the shipped name and is not marked mapped',
		$rows['master']['name'] === Roles::TEMPLATE_SLOTS['master'][0] && $rows['master']['mapped'] === false);
	// Who each template is for, which the page shows beside the name — the consts themselves are
	// held in step with TEMPLATE_SLOTS in spec.test.php.
	check('every row carries who the name reaches and who reads its items',
		array_reduce(array_keys($rows), fn($ok, $s) => $ok && isset($rows[$s]['used_by']) && isset($rows[$s]['read_by']), true));
	check('the client master row names the three report widgets',
		$rows['master']['used_by'] === Roles::TEMPLATE_USED_BY['master']
			&& array_intersect(['capacity', 'resources', 'volume'], $rows['master']['used_by']) === ['capacity', 'resources', 'volume'],
		$rows['master']['used_by']);
	check('and the log delay row says its items are read whatever it is called',
		$rows['delay']['used_by'] === ['import'] && $rows['delay']['read_by'] === ['capacity', 'resources']);

	// A template this page wrote: recognised as ours by uuid, not by its name.
	\Zbx::addTemplate('ACME master', MasterTemplate::uuid('template'));
	Roles::saveTemplateNames(['master' => 'ACME master']);
	$row = TemplateMap::rows()['master'];
	check('a mapped name that exists and carries our uuid is reported as ours',
		$row['found'] === true && $row['ours'] === true && $row['problem'] === '', $row);

	// The same name owned by a template this page did not write is the one mapping that cannot
	// work: configuration.import matches by uuid and refuses a name that belongs to another
	// object, so the page has to say so before the operator leaves it.
	\Zbx::addTemplate('Someone elses template', 'ffffffffffffffffffffffffffffffff');
	Roles::saveTemplateNames(['master' => 'Someone elses template']);
	$row = TemplateMap::rows()['master'];
	check('a written slot mapped onto a foreign template is refused in words',
		$row['found'] === true && $row['ours'] === false && str_contains($row['problem'], 'already exists'), $row);
	check('and the sentence names the template, so it can be found in Zabbix',
		str_contains($row['problem'], 'Someone elses template'), $row['problem']);

	// A slot this page only looks for: a name nothing answers to finds nothing, which is the
	// exact failure an operator cannot see from the form alone.
	Roles::saveTemplateNames(['plan' => 'No such plan template']);
	$row = TemplateMap::rows()['plan'];
	check('a look-for slot mapped to a name Zabbix has not got says so',
		$row['found'] === false && str_contains($row['problem'], 'No template on this Zabbix is called'), $row);
	check('and names the name that found nothing', str_contains($row['problem'], 'No such plan template'), $row['problem']);

	// The same absence on a slot this page writes is the normal state before install, so it is
	// reported as pending and not as a fault — a column that cried wolf would stop being read.
	Roles::saveTemplateNames(['devices' => 'Devices template not installed yet']);
	$row = TemplateMap::rows()['devices'];
	check('a written slot Zabbix has not got yet is not called a problem',
		$row['found'] === false && $row['problem'] === '', $row);

	// The extra names, which are recognised and never written.
	Roles::saveTemplateNames(['cluster' => 'ACME cluster', 'cluster_also' => "An older cluster name\nAnother missing one"]);
	\Zbx::addTemplate('An older cluster name');
	$row = TemplateMap::rows()['cluster'];
	check('an extra name that exists is listed without complaint',
		count($row['aliases']) === 2 && $row['aliases'][0]['found'] === true, $row['aliases']);
	check('an extra name nothing answers to is flagged', $row['aliases'][1]['found'] === false);
	check('the extra names are recognised but never written',
		in_array('An older cluster name', Reconciler::esClusterTemplates(), true) && ClusterTemplate::name() === 'ACME cluster');

	// Regression: ISSUE-001 — an extra name equal to the mapped name was hidden from the form.
	// Found by /qa on 2026-10-08. The form posts back what it shows, so the next Save dropped
	// the hidden name from the store, and moving the slot away then lost it for good.
	// Report: .gstack/qa-reports/qa-report-192-168-64-13-2026-10-08.md
	Roles::saveTemplateNames(['cluster' => 'ACME cluster', 'cluster_also' => 'ACME cluster']);
	$row = TemplateMap::rows()['cluster'];
	check('an extra name equal to the mapped name is still shown, so a Save cannot drop it',
		array_column($row['aliases'], 'name') === ['ACME cluster'], $row['aliases']);
	check('and the store still holds it', Roles::templateAliases()['cluster'] === ['ACME cluster']);
	check('recognition lists it once, not twice',
		count(array_keys(Reconciler::esClusterTemplates(), 'ACME cluster', true)) === 1,
		Reconciler::esClusterTemplates());
	// What the form round-trip does: post back exactly the names the page displayed.
	Roles::saveTemplateNames(['cluster' => 'ACME cluster',
		'cluster_also' => implode("\n", array_column(TemplateMap::rows()['cluster']['aliases'], 'name'))]);
	check('a Save that changes nothing keeps the extra name',
		Roles::templateAliases()['cluster'] === ['ACME cluster'], Roles::templateAliases()['cluster']);
	// And moving the slot away must leave the name still recognised.
	Roles::saveTemplateNames(['cluster_also' => 'ACME cluster']);
	check('moving the slot away leaves the extra name recognised',
		in_array('ACME cluster', Reconciler::esClusterTemplates(), true));
	Roles::saveTemplateNames([]);

	$choices = TemplateMap::choices();
	check('the picker offers the template names this Zabbix has, sorted',
		in_array('ACME cluster', $choices['names'], true) === false || $choices['names'] === array_values($choices['names']));
	check('and says whether it listed them all', is_bool($choices['complete']));
	Roles::saveTemplateNames([]);


	/*
	 * Remove only in ElasticPro: the master host leaves the master template (a plain unlink) and
	 * loses the module's own macros, and nothing else in Zabbix is touched.
	 */
	$begin('a client released from ElasticPro');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME, 'Some other template'],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']],
		'macros' => ['{$GRP.CLIENT}' => 'acme', '{$EP.CLIENT.TYPE}' => 'DI', '{$EP.LEAD}' => 'Asha Rao', '{$EP.CLIENT.STATUS}' => 'active',
			'{$EVP.DL}' => 'old@example.com', '{$ES.URL}' => 'https://es:9200', '{$ULM.S3.BUCKET}' => 'b', '{$ES.VOLUME.CUS.PURCHASED}' => '500',
			'{$EP.FAMILY.ES.DISK1.FS}' => '/', '{$EP.USAGE.WARN}' => '70', '{$MY.OWN}' => 'keep me', '{$SNMP_COMMUNITY}' => 'public']]);
	$clusterid = \Zbx::addHost(['host' => 'acme-Cluster', 'groups' => ['acme', Reconciler::CLUSTER_GROUP], 'templates' => [Roles::clusterTemplate()],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'cluster']], 'macros' => ['{$GRP.CLIENT}' => 'acme', '{$ELASTICSEARCH.HOST}' => 'es']]);
	$serverid = \Zbx::addHost(['host' => 'acme-Parser-1', 'ip' => '10.7.0.1', 'groups' => ['acme', 'Parser', 'Parsers'], 'templates' => [Reconciler::agentTemplate()],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-role', 'value' => 'parser']]]);
	check('it is listed before', isset($state($rec())->clients()['acme']));
	$before = ['cluster' => json_encode(\Zbx::$hosts[$clusterid]), 'server' => json_encode(\Zbx::$hosts[$serverid]), 'macros' => [$clusterid => \Zbx::macrosOf($clusterid)]];
	$otherTpl = array_values(array_filter(\Zbx::$hosts[$masterid]['parentTemplates'], fn($t) => $t['host'] === 'Some other template'))[0]['templateid'];
	$masterTpl = array_values(array_filter(\Zbx::$hosts[$masterid]['parentTemplates'], fn($t) => $t['host'] === MasterTemplate::NAME))[0]['templateid'];
	\Zbx::$calls = []; \Zbx::$wrote = [];
	$r = $rec();
	[$unlinked, $removed] = $r->release('acme');
	check('the master template is unlinked, by name', $unlinked === [MasterTemplate::NAME], $unlinked);
	$mr = \Zbx::calls('Host.massRemove');
	check('with one plain unlink of exactly the master template', count($mr) === 1 && $mr[0]['templateids'] === [$masterTpl] && $mr[0]['hostids'] === [$masterid], $mr);
	check('never templates_clear: the master host keeps the items it got', !isset($mr[0]['templateids_clear']) && !isset($mr[0]['templates_clear']));
	check('another template of the master host stays linked', array_column(\Zbx::$hosts[$masterid]['parentTemplates'], 'templateid') === [$otherTpl]);
	$left = \Zbx::macrosOf($masterid);
	check('every ElasticPro macro is gone, under either spelling, and {$GRP.CLIENT}, {$ES.*}, {$ULM.*}',
		array_keys($left) === ['{$MY.OWN}', '{$SNMP_COMMUNITY}'], array_keys($left));
	check('a macro that is not the module\'s own is untouched', $left['{$MY.OWN}'] === 'keep me' && $left['{$SNMP_COMMUNITY}'] === 'public');
	$md = \Zbx::calls('UserMacro.delete');
	check('one delete, for exactly those macros', count($md) === 1 && count($md[0]) === 10 && count($removed) === 10, [count($md), $removed]);
	check('nothing is written but the unlink and the macro delete', array_values(array_unique(\Zbx::$wrote)) === ['Host.massRemove', 'UserMacro.delete'], \Zbx::$wrote);
	check('no host is updated, disabled or deleted', !\Zbx::calls('Host.update') && !\Zbx::calls('Host.delete') && (int) \Zbx::$hosts[$masterid]['status'] === HOST_STATUS_MONITORED);
	check('the cluster and server hosts are exactly as they were, with their template and macros',
		json_encode(\Zbx::$hosts[$clusterid]) === $before['cluster'] && json_encode(\Zbx::$hosts[$serverid]) === $before['server']
		&& \Zbx::macrosOf($clusterid) === $before['macros'][$clusterid]);
	check('the master host is still in its groups and keeps its tags', count(\Zbx::$hosts[$masterid]['hostgroups']) === 2 && count(\Zbx::$hosts[$masterid]['tags']) === 2);
	check('the client is no longer listed', !isset($state($rec())->clients()['acme']));
	check('it says what it did', (bool) preg_grep('/no longer linked to/', $r->done()), $r->done());
	\Zbx::$wrote = [];
	$rec()->release('acme');
	check('releasing it again changes nothing', \Zbx::$wrote === [], \Zbx::$wrote);
	$begin('a refused unlink leaves the macros alone');
	$m2 = \Zbx::addHost(['host' => 'beta-Master', 'groups' => ['beta', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'master']], 'macros' => ['{$GRP.CLIENT}' => 'beta', '{$EP.LEAD}' => 'x']]);
	\Zbx::$deny['Host.massRemove'] = 'Cannot unlink.';
	$refused = '';
	try { $rec()->release('beta'); } catch (\Exception $e) { $refused = $e->getMessage(); }
	check('the refusal is reported with Zabbix\'s reason', str_contains($refused, 'Cannot unlink.'), $refused);
	check('and the macros are still there, so the client is not left listed and empty', count(\Zbx::macrosOf($m2)) === 2 && !\Zbx::calls('UserMacro.delete'));
	$written = array_keys($spec->masterMacros($spec->fromForm($form('acme'))['client']));
	check('the macro names released are the ones the master macros write',
		count($written) > 20 && array_diff($written, $spec->masterMacroNames()) === [], array_diff($written, $spec->masterMacroNames()));

	/* ======================================================================================
	 *  WHO MAY EDIT: THE PRE-CHECK BEFORE A SAVE BY SOMEONE WHO IS NOT A SUPER ADMIN
	 * ====================================================================================== */

	/*
	 * An ElasticPro admin who is a Zabbix Admin may edit, but writes as themself: a save their
	 * Zabbix rights do not cover is refused before anything is written, naming the step.
	 */
	$hasHostgroups = function () {
		foreach (\Zbx::$hosts as $h) { foreach ($h['hostgroups'] as $g) { yield $g['name']; } }
	};
	$begin('the groups a save needs are the groups apply() puts hosts in');
	$c = $client($form('acme', ['ulm_bucket' => 'acme-archive', 'ulm_region' => 'ap-south-1',
		'servers' => json_encode([['ip' => '10.20.0.11', 'roles' => ['es_data_hot']], ['ip' => '10.20.0.12', 'roles' => ['es_master']]])]));
	$r = $rec();
	$listed = $r->groupsFor($c);
	$r->apply($c);
	$used = array_values(array_unique(iterator_to_array($hasHostgroups(), false)));
	sort($listed); sort($used);
	check('groupsFor() names exactly the host groups apply() used — one definition for the pre-check and the save', $listed === $used, ['groupsFor' => $listed, 'apply' => $used]);

	$begin('a new client for a Zabbix Admin: its host group would have to be created');
	$c = $client($form('newco'));
	$r = $rec();
	$now = $r->current('newco');
	\Zbx::groupId(Reconciler::MASTERS_GROUP); \Zbx::groupId(Reconciler::CLUSTER_GROUP);
	$needs = WriteCheck::forSave($r, $spec, $c, $now);
	$refused = WriteCheck::refusals($needs, WriteCheck::rights($needs), false);
	check('refused, naming the step a Super admin alone may take', (bool) preg_grep('/^Creating the host group "newco" for a new client needs a Zabbix Super admin/', $refused), $refused);
	check('only that step: the groups that exist and are writable are not mentioned', count($refused) === 1, $refused);
	check('nothing was written to find that out', \Zbx::$wrote === [], \Zbx::$wrote);
	check('a Super admin is never refused, and so never asked about', WriteCheck::refusals($needs, [], true) === []);
	check('the message says nothing was changed', strpos(WriteCheck::message($refused), 'Nothing was changed') === 0);

	$begin('an existing client in a host group the Zabbix Admin may only read');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'master']],
		'macros' => ['{$GRP.CLIENT}' => 'acme', '{$EP.CLIENT.TYPE}' => 'On-Prem']]);
	\Zbx::addHost(['host' => 'acme-ES-Cluster', 'groups' => ['acme', Reconciler::CLUSTER_GROUP],
		'templates' => [Roles::clusterTemplate()], 'tags' => [Reconciler::MANAGED, ['tag' => 'ep-kind', 'value' => 'cluster']]]);
	\Zbx::$readOnlyGroups = ['acme', Reconciler::MASTERS_GROUP];
	$r = $rec();
	$c = $client($form('acme'));
	$needs = WriteCheck::forSave($r, $spec, $c, $r->current('acme'));
	$refused = WriteCheck::refusals($needs, WriteCheck::rights($needs), false);
	check('the read-only group is named', (bool) preg_grep('/^Putting hosts in host group "acme" needs write permission/', $refused), $refused);
	check('and the host in no writable group', (bool) preg_grep('/^Changing the host "acme-Master" needs write permission/', $refused), $refused);
	check('the cluster host, also in a writable group, is not', !preg_grep('/acme-ES-Cluster/', $refused), $refused);
	\Zbx::$readOnlyGroups = [];
	$needs = WriteCheck::forSave($r, $spec, $c, $r->current('acme'));
	check('with write on every group, nothing is refused', WriteCheck::refusals($needs, WriteCheck::rights($needs), false) === [],
		WriteCheck::refusals($needs, WriteCheck::rights($needs), false));
	check('still nothing written', \Zbx::$wrote === [], \Zbx::$wrote);

	$begin('a client with DL alert routing on');
	$masterid = \Zbx::addHost(['host' => 'acme-Master', 'groups' => ['acme', Reconciler::MASTERS_GROUP], 'templates' => [MasterTemplate::NAME],
		'tags' => [Reconciler::MANAGED, ['tag' => 'ep-client', 'value' => 'acme'], ['tag' => 'ep-kind', 'value' => 'master']],
		'macros' => ['{$GRP.CLIENT}' => 'acme', '{$EP.ALERT.DL}' => '1', '{$EP.DL}' => 'dl@acme.example']]);
	$r = $rec();
	$c = $client($form('acme', ['alert_dl' => '1', 'cluster_dl' => 'dl@acme.example', 'es_url' => '']));
	$needs = WriteCheck::forSave($r, $spec, $c, $r->current('acme'));
	$refused = WriteCheck::refusals($needs, WriteCheck::rights($needs), false);
	check('refused: keeping the routing writes a Zabbix user group, a Super admin\'s step', (bool) preg_grep('/^Keeping the cluster DL alert routing or weekly report of "acme" in step/', $refused), $refused);
	check('routing on before is enough, even when the save turns it off', AlertRouting::needsAccounts('acme', true, ['{$EP.ALERT.DL}' => '1', '{$EP.DL}' => 'x@y'], []));
	check('routing off before and after: no account is touched', !AlertRouting::needsAccounts('acme', true, [], ['{$EP.ALERT.DL}' => '0', '{$EP.DL}' => 'x@y']));
	check('the weekly report counts too', AlertRouting::needsAccounts('acme', true, [], ['{$EP.REPORT.WEEKLY}' => '1', '{$EP.DL}' => 'x@y']));
	check('no host group afterwards: sync() leaves everything alone, so nothing is needed', !AlertRouting::needsAccounts('acme', false, ['{$EP.ALERT.DL}' => '1', '{$EP.DL}' => 'x@y'], ['{$EP.ALERT.DL}' => '1', '{$EP.DL}' => 'x@y']));
	check('a decommissioned client wants none, so turning it off from decommissioned needs nothing',
		!AlertRouting::needsAccounts('acme', true, ['{$EP.ALERT.DL}' => '1', '{$EP.DL}' => 'x@y', '{$EP.CLIENT.STATUS}' => 'decommissioned'],
			['{$EP.ALERT.DL}' => '1', '{$EP.DL}' => 'x@y', '{$EP.CLIENT.STATUS}' => 'decommissioned']));

	$begin('templates a Zabbix Admin cannot see');
	\Zbx::$hideTemplates = true;
	$r = $rec();
	$c = $client($form('acme'));
	$needs = WriteCheck::forSave($r, $spec, $c, $r->current('acme'));
	$refused = WriteCheck::refusals($needs, WriteCheck::rights($needs), false);
	check('named as a read permission, not only as "import it"', (bool) preg_grep('/^These templates are not visible to your Zabbix user: .*'.preg_quote(MasterTemplate::NAME, '/').'/', $refused), $refused);

	$begin('the master template install');
	$groups = TemplateInstaller::templateGroups(Roles::defaults());
	check('the template groups install() writes into are read from the exports', $groups === ['Templates/Applications'], $groups);

	/* ---------------- tidy up ---------------- */
	foreach (glob($dir.'/backups/*.json') ?: [] as $f) { unlink($f); }
	if (is_dir($dir.'/backups')) { rmdir($dir.'/backups'); }
	foreach (array_diff(scandir($dir), ['.', '..']) as $fn) { unlink($dir.'/'.$fn); }
	rmdir($dir);

	$scene = '';
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
