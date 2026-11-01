<?php
/**
 * TypeSafe Jev judging Discover's guesses from names (lib/Jev.php), against the fake Zabbix
 * (test/fake-zabbix.php) and a stubbed HTTP layer — the real TypeSafe API is never called:
 *   php test/jev.test.php
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
 *
 * What it holds the feature to:
 *  - off by default, and off / no key / a failed, slow or invalid answer: the name rules exactly
 *    as before, and a note that says which ran and why — never the key;
 *  - on: one request per scan, to the documented endpoint with the key as a Bearer token, TLS
 *    verified; the Choice options only the clusters the scan knows plus "none of these"; no IP
 *    address, no macro value in what is sent;
 *  - sure answers used (a client, "none", not Kafka, a cluster group); unsure ones shown to check,
 *    never ticked, and adoptable only when a person ticks them;
 *  - the key: stored sealed, never in the status the page gets, refused when it cannot be sealed;
 *  - the pure parts: thresholds, answer validation, option chunks of at most 255.
 */
namespace {
	require __DIR__.'/fake-zabbix.php';
}
namespace Modules\EpClients\Test {
	foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'Forecast', 'ClientTypes', 'MasterTemplate', 'DevicesTemplate', 'JumpTemplate', 'ClusterTemplate',
			'ClientSpec', 'Csv', 'Zip', 'Xlsx', 'Workbook', 'Backups', 'Reconciler', 'DefaultCredential', 'Lifecycle', 'Registry', 'History', 'SetupChecks', 'AlertRouting',
			'ClientState', 'Importer', 'TemplateInstaller', 'TemplateMap', 'WriteCheck', 'EpRole', 'TemplateMove', 'Missing', 'ClusterSecret', 'Jev', 'Discovery'] as $lib) {
		require __DIR__.'/../lib/'.$lib.'.php';
	}
	use Modules\EpClients\Lib\{ClientSpec, Discovery, Jev, Reconciler, Roles, Store};

	$failed = 0; $passed = 0; $scene = '';
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed, $scene;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: ", $scene !== '' ? $scene.' — ' : '', $what, $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-jev-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);

	$KEY = 'ts_live_SECRETKEY_0123456789abcdef';
	$roles = Roles::defaults();
	$spec = new ClientSpec($roles);
	$words = Discovery::nameWords($roles);
	$rg = Roles::groups($roles);
	$disc = fn() => new Discovery($spec, new Reconciler($spec));
	$byName = fn(array $scan) => array_column($scan['proposals'], null, 'name');
	$hostIn = fn(array $p, string $host) => array_values(array_filter($p['hosts'], fn($h) => $h['host'] === $host))[0] ?? null;
	$esMacros = fn(string $host) => ['{$ELASTICSEARCH.SCHEME}' => 'https', '{$ELASTICSEARCH.HOST}' => $host, '{$ELASTICSEARCH.PORT}' => '9200', '{$ELASTICSEARCH.USERNAME}' => 'elastic'];
	$noBackup = function () {};
	$noNote = function (string $n) {};
	// A reversible stand-in for the ElasticPro module's seal(): the test only needs "not the plain key".
	$sealer = ['seal' => fn(string $p) => 'v1:'.base64_encode(strrev($p)), 'open' => fn(string $s) => str_starts_with($s, 'v1:') ? strrev((string) base64_decode(substr($s, 3))) : ''];

	/* ---------------- the stubbed HTTP layer ---------------- */
	$calls = [];
	/** Answers by what a question is about: the server's visible or host name, or the host group. */
	$script = [];
	$mode = 'answer';
	$answerOf = function (array $q) use (&$script): array {
		$about = $q['instructions']['server']['visible name'] ?? $q['instructions']['server']['host name'] ?? $q['instructions']['host group'] ?? '';
		if ($q['type'] === 'noul') {
			$kind = isset($q['instructions']['host group']) ? 'group' : 'kafka';
			return ['type' => 'noul', 'noul' => $script[$kind][$about] ?? 0.5];
		}
		$want = $script['client'][$about] ?? [Jev::NONE => 0.5];
		$probs = [];
		foreach (array_keys($q['criteria']) as $o) {
			$probs[(string) $o] = $want[(string) $o] ?? 0.0;
		}
		arsort($probs);
		return ['type' => 'choice', 'choice' => (string) array_key_first($probs), 'confidence' => 0.9, 'probabilities' => $probs];
	};
	Jev::$http = function (string $url, array $headers, string $body, int $timeout) use (&$calls, &$mode, $answerOf, $KEY): array {
		$req = json_decode($body, true);
		$calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'req' => $req, 'timeout' => $timeout];
		if ($mode === 'timeout') {
			return ['status' => 0, 'body' => '', 'error' => 'Operation timed out after 10001 milliseconds with 0 bytes received'];
		}
		if ($mode === '401') {
			return ['status' => 401, 'body' => json_encode(['detail' => 'Invalid API key '.$KEY]), 'error' => ''];
		}
		$answers = [];
		foreach ($req['questions'] as $qid => $q) {
			$answers[$qid] = $answerOf($q);
		}
		if ($mode === 'invalid') {
			$first = array_key_first(array_filter($req['questions'], fn($q) => $q['type'] === 'choice'));
			$answers[$first] = ['type' => 'choice', 'choice' => 'made-up-client', 'confidence' => 1.0, 'probabilities' => ['made-up-client' => 1.0]];
		}
		if ($mode === 'missing') {
			array_pop($answers);
		}
		return ['status' => 200, 'body' => json_encode(['model' => 'jev-1.14.0', 'answers' => $answers, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]), 'error' => ''];
	};

	$fixture = function () use ($esMacros): void {
		\Zbx::reset();
		foreach (array_merge(Reconciler::clusterTemplates(), [Reconciler::agentTemplate()]) as $t) { \Zbx::addTemplate($t); }
		\Zbx::addHost(['host' => 'globex-prod', 'groups' => ['Elasticsearch clusters'], 'templates' => [Roles::clusterTemplate()], 'macros' => $esMacros('es.globex')]);
		\Zbx::addHost(['host' => 'initech', 'groups' => ['Elasticsearch clusters'], 'templates' => [Roles::clusterTemplate()], 'macros' => $esMacros('es.initech')]);
		// No group, macro, tag or prefix says whose: only its visible name does.
		\Zbx::addHost(['host' => '10.5.0.1', 'name' => 'gx-parser-b', 'ip' => '10.5.0.1', 'groups' => ['Parser', 'Parsers']]);
		// The prefix says globex-prod; Jev (in the scene that answers) leans to initech, unsure.
		\Zbx::addHost(['host' => 'globex-prod-Parser-8', 'ip' => '10.5.0.2', 'groups' => ['Parser', 'Parsers']]);
		// Nothing points anywhere.
		\Zbx::addHost(['host' => 'mystery-1', 'ip' => '10.5.0.3', 'groups' => ['Engine', 'Engines']]);
		// The name match says Kafka (its group); Jev surely says not.
		\Zbx::addHost(['host' => 'logship-01', 'ip' => '10.5.0.4', 'groups' => ['Kafka brokers']]);
		// The name match says Kafka; Jev is unsure: the match stands.
		\Zbx::addHost(['host' => 'kafka-07', 'ip' => '10.5.0.5', 'groups' => ['Kafka brokers']]);
		// Cluster template, but no "elasticsearch" in its group's name.
		\Zbx::addHost(['host' => 'es-prod-cl', 'groups' => ['ES Prod Clusters'], 'templates' => [Roles::clusterTemplate()], 'macros' => $esMacros('es.prodcl')]);
		\Zbx::addHost(['host' => 'other-stray', 'groups' => ['Linux servers'], 'templates' => [Roles::clusterTemplate()]]);
	};
	$sure = ['client' => ['gx-parser-b' => ['globex-prod' => 0.97, 'initech' => 0.02, Jev::NONE => 0.01],
			'globex-prod-Parser-8' => ['initech' => 0.6, 'globex-prod' => 0.35, Jev::NONE => 0.05],
			'mystery-1' => [Jev::NONE => 0.95, 'globex-prod' => 0.03, 'initech' => 0.02],
			'logship-01' => [Jev::NONE => 0.9], 'kafka-07' => [Jev::NONE => 0.9]],
		'kafka' => ['logship-01' => 0.04, 'kafka-07' => 0.55, 'gx-parser-b' => 0.02, 'mystery-1' => 0.03],
		'group' => ['ES Prod Clusters' => 0.96, 'Linux servers' => 0.03]];

	/* ======================================================================================
	 *  PURE PARTS
	 * ====================================================================================== */
	$scene = 'thresholds';
	check('0.97 over 0.02: auto', Jev::decide(['a' => 0.97, 'b' => 0.02, 'c' => 0.01])['decision'] === 'auto');
	check('0.84: review (under the top bar)', Jev::decide(['a' => 0.84, 'b' => 0.16])['decision'] === 'review');
	check('a close second: review', Jev::decide(['a' => 0.86, 'b' => 0.4])['decision'] === 'review' && Jev::decide(['a' => 0.86, 'b' => 0.14])['decision'] === 'auto');
	check('the pick is the top option', Jev::decide(['a' => 0.1, 'b' => 0.9])['pick'] === 'b');
	check('constants as designed', Jev::AUTO_TOP === 0.85 && Jev::AUTO_MARGIN === 0.5 && Jev::TIMEOUT === 10 && Jev::MAX_OPTIONS === 255 && Jev::URL === 'https://api.typesafe.ai/v1/systemone');

	$scene = 'answers checked against their question';
	$cq = ['type' => 'choice', 'criteria' => ['acme' => null, Jev::NONE => 'x']];
	check('a valid choice is read', (Jev::readAnswer($cq, ['type' => 'choice', 'choice' => 'acme', 'probabilities' => ['acme' => 0.9, Jev::NONE => 0.1]])['pick'] ?? null) === 'acme');
	check('an option that was not offered is refused', Jev::readAnswer($cq, ['type' => 'choice', 'choice' => 'evil', 'probabilities' => ['evil' => 1.0]]) === null);
	check('a probability out of range is refused', Jev::readAnswer($cq, ['type' => 'choice', 'choice' => 'acme', 'probabilities' => ['acme' => 1.5]]) === null);
	check('the wrong type is refused', Jev::readAnswer($cq, ['type' => 'noul', 'noul' => 0.9]) === null);
	check('a noul is read, yes and sure', (Jev::readAnswer(['type' => 'noul'], ['type' => 'noul', 'noul' => 0.95]) ?? [])['yes'] === true
		&& Jev::readAnswer(['type' => 'noul'], ['type' => 'noul', 'noul' => 0.95])['decision'] === 'auto');
	check('a noul at 0.6 is not sure', Jev::readAnswer(['type' => 'noul'], ['type' => 'noul', 'noul' => 0.6])['decision'] === 'review');
	check('a noul that is not a number is refused', Jev::readAnswer(['type' => 'noul'], ['type' => 'noul', 'noul' => 'yes']) === null);

	$scene = 'names that may be sent';
	check('an IPv4 host name is never sent', Jev::safeName('10.0.0.1') === '(an IP address, not sent)' && Jev::safeName('fe80::1') === '(an IP address, not sent)');
	check('a name is', Jev::safeName('acme-Parser-1') === 'acme-Parser-1');
	$f = Jev::facts(['host' => '10.1.1.1', 'name' => 'srv-a', 'groups' => ['G'], 'templates' => ['T'], 'ip' => '10.1.1.1', 'grp_client' => 'secret-ish', 'macros' => ['{$X}']]);
	check('facts: names, groups and templates only', array_keys($f) === ['host name', 'visible name', 'host groups', 'templates'] && $f['host name'] === '(an IP address, not sent)', $f);

	$scene = 'option chunks of at most 255';
	$many = [];
	for ($i = 0; $i < 300; $i++) {
		$many[] = ['hostid' => (string) (1000 + $i), 'host' => 'cl'.$i, 'name' => 'cl'.$i, 'kind' => 'cluster', 'groups' => ['Elasticsearch clusters'], 'already' => null, 'grp_client' => '', 'tag_client' => '',
			'roles' => [], 'ip' => null, 'shared_ip' => []];
	}
	$many[] = ['hostid' => '9', 'host' => 'lone', 'name' => 'lone', 'kind' => 'server', 'groups' => ['Engine', 'Engines'], 'templates' => [], 'already' => null, 'grp_client' => '', 'tag_client' => '',
		'roles' => ['engine'], 'role_source' => 'group', 'ip' => '10.0.0.9', 'shared_ip' => []];
	$r = Jev::requests($many, $rg, $words);
	$qs = array_merge([], ...array_map(fn($b) => $b['questions'], $r['bodies']));
	$choices = array_filter($qs, fn($q) => $q['type'] === 'choice');
	check('300 clusters: two Choice questions for the one host', count($choices) === 2 && $r['error'] === null, [count($choices), $r['error']]);
	check('each with at most 255 options, "none of these" in each', !array_filter($choices, fn($q) => count($q['criteria']) > 255 || !array_key_exists(Jev::NONE, $q['criteria'])));
	check('together every cluster once', count(array_unique(array_merge(...array_map(fn($q) => array_diff(array_keys($q['criteria']), [Jev::NONE]), array_values($choices))))) === 300);
	$c = Jev::combine(['a' => ['kind' => 'client', 'ref' => '9', 'options' => ['cl1' => '1001']], 'b' => ['kind' => 'client', 'ref' => '9', 'options' => ['cl299' => '1299']]],
		['a' => ['pick' => 'cl1', 'p' => 0.95, 'margin' => 0.9, 'decision' => 'auto'], 'b' => ['pick' => Jev::NONE, 'p' => 0.97, 'margin' => 0.95, 'decision' => 'auto']]);
	check('one chunk surely names a client, the other surely none: decided', $c['client']['9']['pick'] === '1001' && $c['client']['9']['decision'] === 'auto', $c);
	$c = Jev::combine(['a' => ['kind' => 'client', 'ref' => '9', 'options' => ['cl1' => '1001']], 'b' => ['kind' => 'client', 'ref' => '9', 'options' => ['cl299' => '1299']]],
		['a' => ['pick' => 'cl1', 'p' => 0.95, 'margin' => 0.9, 'decision' => 'auto'], 'b' => ['pick' => 'cl299', 'p' => 0.9, 'margin' => 0.85, 'decision' => 'auto']]);
	check('two chunks name two clients: to check', $c['client']['9']['decision'] === 'review' && $c['client']['9']['pick'] === '1001', $c);

	/* ======================================================================================
	 *  OFF BY DEFAULT: THE RULES, UNCHANGED
	 * ====================================================================================== */
	$scene = 'off by default';
	Jev::$sealer = $sealer;
	$fixture();
	$scan = $disc()->scan();
	$ps = $byName($scan);
	check('no request at all', $calls === [], count($calls));
	check('the note: names matched by rule, Jev off', $scan['judge']['used'] === false && $scan['judge']['note'] === 'Names matched by rule; Jev off.', $scan['judge']);
	check('the prefix rule attaches globex-prod-Parser-8 to globex-prod, ticked as before', ($hostIn($ps['globex-prod'], 'globex-prod-Parser-8')['source'] ?? null) === 'prefix'
		&& empty($hostIn($ps['globex-prod'], 'globex-prod-Parser-8')['check']));
	check('the Kafka name match places logship-01 under Forwarder, to confirm', in_array('logship-01', array_column($scan['unattached'], 'host'), true)
		&& (array_column($scan['unattached'], null, 'host')['logship-01']['role_source'] ?? null) === 'kafka');
	check('a cluster template outside an "elasticsearch" group stays a note, not a proposal', !isset($ps['es-prod-cl']) && (bool) preg_grep('/es-prod-cl/', $scan['notes']));
	$offScan = $scan;

	$scene = 'on, but no key';
	Store::write(Jev::FILE, ['enabled' => true]);
	$scan = $disc()->scan();
	check('no request without a key', $calls === []);
	check('the note says Jev is unavailable, and why', !$scan['judge']['used'] && str_contains($scan['judge']['note'], 'Names matched by rule; Jev unavailable: no API key is set'), $scan['judge']);
	check('the proposals are the rules\' own', array_keys($byName($scan)) === array_keys($byName($offScan)));

	/* ======================================================================================
	 *  THE KEY
	 * ====================================================================================== */
	$scene = 'the key';
	$threw = null;
	try { Jev::save(true, 'set', "two words", 'alice'); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
	check('a key with a space is refused', $threw !== null && !str_contains($threw, 'two words'), $threw);
	$done = Jev::save(true, 'set', '  '.$KEY.'  ', 'alice');
	check('stored, and said', in_array('API key stored, sealed.', $done, true), $done);
	$raw = (string) file_get_contents(Store::path(Jev::FILE));
	check('the file holds no plain key', !str_contains($raw, $KEY) && str_contains($raw, '"key"'));
	$st = Jev::status();
	check('the status says set, by whom — and never the key', $st['enabled'] && $st['key_set'] && $st['key_readable'] && $st['set_by'] === 'alice' && !str_contains(json_encode($st), $KEY), $st);
	Jev::save(true, 'keep', 'ignored-because-keep', 'bob');
	check('keep leaves the key and who set it', Jev::status()['set_by'] === 'alice');
	$saved = Jev::$sealer;
	Jev::$sealer = null;
	$threw = null;
	try { Jev::save(true, 'set', 'another-key', 'alice'); } catch (\RuntimeException $e) { $threw = $e->getMessage(); }
	check('without the ElasticPro module a key is not stored (it could not be sealed)', $threw !== null && str_contains($threw, 'not installed'), $threw);
	$scan = $disc()->scan();
	check('…and a scan says Jev is unavailable, and why, without calling', $calls === [] && str_contains($scan['judge']['note'], 'ElasticPro module'), $scan['judge']);
	Jev::$sealer = $saved;

	/* ======================================================================================
	 *  ON: SURE ANSWERS USED, UNSURE ONES TO CHECK
	 * ====================================================================================== */
	$scene = 'on, answered';
	$script = $sure;
	$fixture();
	$scan = $disc()->scan();
	$ps = $byName($scan);
	check('one request for the whole scan', count($calls) === 1, count($calls));
	$call = $calls[0];
	check('to the documented endpoint, the key as a Bearer token', $call['url'] === 'https://api.typesafe.ai/v1/systemone' && in_array('Authorization: Bearer '.$KEY, $call['headers'], true));
	check('within the 10 seconds', $call['timeout'] >= 1 && $call['timeout'] <= 10, $call['timeout']);
	check('model, state and questions as the API takes them', $call['req']['model'] === 'jev-latest' && isset($call['req']['state'], $call['req']['questions']));
	check('no IP address in what is sent', !preg_match('/\b10\.5\.0\.\d\b/', $call['body']), $call['body']);
	check('no macro value in what is sent', !str_contains($call['body'], 'es.globex') && !str_contains($call['body'], 'elastic"'));
	$qs = $call['req']['questions'];
	$known = ['globex-prod', 'initech', 'es-prod-cl', 'other-stray', Jev::NONE];
	$opts = array_unique(array_merge(...array_map(fn($q) => array_map('strval', array_keys($q['criteria'])), array_values(array_filter($qs, fn($q) => $q['type'] === 'choice')))));
	check('the options are only the clusters the scan knows (and hosts a group answer could make one) plus "none of these"', !array_diff($opts, $known) && in_array(Jev::NONE, $opts, true), $opts);
	check('no role host is ever an option', !array_intersect($opts, ['gx-parser-b', 'globex-prod-Parser-8', 'mystery-1', 'logship-01', 'kafka-07']));
	$asked = array_map(fn($q) => $q['instructions']['server']['visible name'] ?? $q['instructions']['server']['host name'] ?? null, array_filter($qs, fn($q) => $q['type'] === 'choice'));
	sort($asked);
	check('a client is asked only for the hosts the exact rules leave open', $asked === ['globex-prod-Parser-8', 'gx-parser-b', 'kafka-07', 'logship-01', 'mystery-1'], $asked);
	$groupsAsked = array_values(array_filter(array_map(fn($q) => $q['instructions']['host group'] ?? null, $qs)));
	sort($groupsAsked);
	check('host groups asked: those of hosts with a cluster template outside every "elasticsearch" group', $groupsAsked === ['ES Prod Clusters', 'Linux servers'], $groupsAsked);
	check('the note says Jev judged the names, and with which model', $scan['judge']['used'] && str_starts_with($scan['judge']['note'], 'Names judged by Jev (jev-1.14.0)'), $scan['judge']);

	$g = $ps['globex-prod'];
	$gx = $hostIn($g, '10.5.0.1');
	check('a sure answer attaches the host Jev named, said as Jev\'s', $gx !== null && $gx['source'] === 'jev' && str_contains($gx['source_detail'], 'judged by Jev: globex-prod (97 %)'), $gx);
	check('…and it is ticked like any attached host', $g['choices']['hosts'][$gx['hostid']] === true);
	$i = $ps['initech'];
	$p8 = $hostIn($i, 'globex-prod-Parser-8');
	check('an unsure answer: shown where Jev leaned, flagged check', $p8 !== null && $p8['check'] === 'jev' && $p8['source'] === 'jev' && str_contains($p8['source_detail'], 'not sure'), $p8);
	check('…never ticked', $i['choices']['hosts'][$p8['hostid']] === false);
	check('…and Missing says to check it', in_array('jev_check', array_column($i['missing'], 'key'), true));
	check('it is not where the prefix would have put it', $hostIn($g, 'globex-prod-Parser-8') === null);
	$un = array_column($scan['unattached'], null, 'host');
	check('a sure "none of these": left unattached, saying Jev judged so', isset($un['mystery-1']) && str_contains($un['mystery-1']['source_detail'], 'Jev judged it belongs to none of the clusters (95 %)'), $un['mystery-1'] ?? null);
	check('a sure "not Kafka" overrides the name match: no Forwarder suggested', isset($un['logship-01']) && $un['logship-01']['role_source'] === null && $un['logship-01']['kafka'] === false
		&& ($un['logship-01']['kafka_by'] ?? null) === 'jev', $un['logship-01'] ?? null);
	check('an unsure Kafka answer leaves the name match', isset($un['kafka-07']) && $un['kafka-07']['role_source'] === 'kafka' && !isset($un['kafka-07']['kafka_by']), $un['kafka-07'] ?? null);
	check('a group Jev surely judged a cluster group makes its host a cluster, said', isset($ps['es-prod-cl']) && ($hostIn($ps['es-prod-cl'], 'es-prod-cl')['cluster_by'] ?? null) === 'jev'
		&& (bool) preg_grep('/Jev judged host group "ES Prod Clusters"/', $hostIn($ps['es-prod-cl'], 'es-prod-cl')['gaps']), array_keys($ps));
	check('…and the page is told which groups those are', $scan['judge']['cluster_groups'] === ['ES Prod Clusters']);
	check('a group surely judged not: its host stays a note', !isset($ps['other-stray']) && (bool) preg_grep('/other-stray/', $scan['notes']));
	check('the key is nowhere in the scan', !str_contains(json_encode($scan), $KEY));
	check('the scan wrote nothing to Zabbix', !array_filter(\Zbx::$wrote, fn($w) => !str_ends_with((string) $w, '.get')));

	$scene = 'adopting with Jev\'s answers';
	$ids = array_column($i['hosts'], 'hostid', 'host');
	$base = ['sel' => [$i['id'] => '1'], 'host' => [$i['id'] => [$ids['initech'] => '1']], 'es_url' => [$i['id'] => 'https://es.initech:9200']];
	$plan = $disc()->plan($i, Discovery::choicesFrom($i, $base, $roles));
	check('as posted with the check box left empty: the host is not adopted', !isset($plan['servers'][$p8['hostid']]) && $plan['adopting'] === 1, [$plan['adopting'], $plan['blockers']]);
	$ticked = $base;
	$ticked['host'][$i['id']][$p8['hostid']] = '1';
	$plan = $disc()->plan($i, Discovery::choicesFrom($i, $ticked, $roles));
	check('ticked by a person: adopted with initech', isset($plan['servers'][$p8['hostid']]) && $plan['adopting'] === 2, [$plan['adopting'], $plan['blockers'], $plan['skipped']]);

	/* ======================================================================================
	 *  FAILURES: THE RULES, AND A NOTE WITHOUT THE KEY
	 * ====================================================================================== */
	foreach (['invalid' => 'its answer to question', 'missing' => 'missing or not valid', 'timeout' => 'no answer within 10 seconds', '401' => 'the API key was refused (401)'] as $m => $said) {
		$scene = 'failure: '.$m;
		$mode = (string) $m;
		$calls = [];
		$fixture();
		$scan = $disc()->scan();
		$ps = $byName($scan);
		check('one attempt', count($calls) === 1, count($calls));
		check('the note: names matched by rule, Jev unavailable, and why', !$scan['judge']['used'] && str_starts_with($scan['judge']['note'], 'Names matched by rule; Jev unavailable: ')
			&& str_contains($scan['judge']['note'], $said), $scan['judge']['note']);
		check('the prefix rule stands, nothing flagged', ($hostIn($ps['globex-prod'], 'globex-prod-Parser-8')['source'] ?? null) === 'prefix'
			&& !array_filter(array_merge(...array_map(fn($p) => $p['hosts'], $scan['proposals'])), fn($h) => isset($h['check'])));
		check('the same proposals as with Jev off', array_keys($ps) === array_keys($byName($offScan)));
		check('the key is nowhere in the scan', !str_contains(json_encode($scan), $KEY));
	}
	$mode = 'answer';

	$scene = 'clear';
	$done = Jev::save(false, 'clear', '', 'alice');
	check('cleared and off, said', in_array('API key cleared.', $done, true) && !Jev::status()['key_set'] && !Jev::status()['enabled'], $done);
	check('the file no longer holds a key', !str_contains((string) file_get_contents(Store::path(Jev::FILE)), '"key"'));

	/* ---------------- the page's half: what the views are given ---------------- */
	$scene = 'views';
	$sv = (string) file_get_contents(__DIR__.'/../views/ep.clients.settings.php');
	check('settings: its own form and keep-marker, never a key value', str_contains($sv, 'ep.clients.jev.save') && str_contains($sv, "'jev_key_action'")
		&& !preg_match("~'jev_key'\)->setAttribute\('value', \\$~", $sv));
	check('settings: says plainly what is sent and what never is', str_contains($sv, 'host names and visible names') && str_contains($sv, 'host group and template names')
		&& str_contains($sv, 'candidate clients') && str_contains($sv, 'No IP addresses, no item values') && str_contains($sv, 'no log content'));
	$edit = (string) file_get_contents(__DIR__.'/../actions/SettingsEdit.php');
	check('settings: the page gets Jev::status(), which has no key in it', str_contains($edit, "'jev' => Jev::status()") && !preg_match('/Jev::key\(/', $edit));
	$save = (string) file_get_contents(__DIR__.'/../actions/JevSave.php');
	check('the save is a Super admin\'s', str_contains($save, 'USER_TYPE_SUPER_ADMIN'));
	check('the action is in the manifest', (json_decode((string) file_get_contents(__DIR__.'/../manifest.json'), true)['actions']['ep.clients.jev.save']['class'] ?? null) === 'JevSave');
	$lib = (string) file_get_contents(__DIR__.'/../lib/Jev.php');
	check('TLS verified, https only, both timeouts set, no redirects', str_contains($lib, 'CURLOPT_SSL_VERIFYPEER => true') && str_contains($lib, 'CURLOPT_SSL_VERIFYHOST => 2')
		&& str_contains($lib, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS') && str_contains($lib, 'CURLOPT_TIMEOUT => $timeout') && str_contains($lib, 'CURLOPT_FOLLOWLOCATION => false'));
	check('nothing is logged', !preg_match('/\berror_log\s*\(|\bsyslog\s*\(/', $lib));

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
