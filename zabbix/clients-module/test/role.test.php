<?php declare(strict_types = 0);
/**
 * Who may edit Cluster Management: EpRole's decision, the question it asks ElasticPro and how it
 * keeps the answer, and WriteCheck's refusals — against stand-ins for the core, the clock and the
 * session. Nothing here talks to a real ElasticPro or Zabbix.
 *   docker run --rm -v "$PWD/..":/z -w /z/clients-module php:8.4-cli-alpine php test/role.test.php
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
 *
 * The signing is the ElasticPro module's own SsoClient::sign(), loaded from ../embed-module, so
 * this holds the request to the scheme the core verifies (HMAC-SHA256 of the exact JSON bytes)
 * rather than to a copy of it.
 */
namespace {
	define('USER_TYPE_ZABBIX_USER', 1); define('USER_TYPE_ZABBIX_ADMIN', 2); define('USER_TYPE_SUPER_ADMIN', 3);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	if (!function_exists('_')) { function _($s) { return $s; } }
	if (!function_exists('_s')) {
		function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace(['%'.($i + 1).'$s', '%'.($i + 1).'$d'], (string) $v, $s); } return $s; }
	}
}
namespace Modules\EpClients\Test {
	$embed = __DIR__.'/../../embed-module/lib/SsoClient.php';
	require_once $embed;
	require_once __DIR__.'/../lib/EpRole.php';
	require_once __DIR__.'/../lib/WriteCheck.php';
	use Modules\ElasticPro\Lib\SsoClient;
	use Modules\EpClients\Lib\{EpRole, WriteCheck};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '', PHP_EOL;
	}

	const SECRET = '0123456789abcdef0123456789abcdef-test';
	const CORE = 'http://ep-core:8765/';

	/**
	 * An EpRole with a stand-in core answering $reply (status, data), a clock at $now[0] and a
	 * session in $session. Every request it sends is appended to $sent.
	 */
	function role(array $reply, array &$sent, array &$now, ?array &$session, ?array $settings = null): EpRole {
		return new EpRole([
			'settings' => fn() => $settings ?? ['core_url' => CORE, 'secret' => SECRET, 'verify_tls' => true],
			'transport' => function (string $url, array $headers, bool $tls) use ($reply, &$sent) {
				$sent[] = ['url' => $url, 'headers' => $headers, 'tls' => $tls];
				return $reply + ['status' => 200, 'data' => null, 'error' => ''];
			},
			'clock' => function () use (&$now) { return $now[0]; },
			'load' => function () use (&$session) { return $session; },
			'save' => function (array $kept) use (&$session) { $session = $kept; }
		]);
	}
	function ok(array $data): array {
		return ['status' => 200, 'data' => $data + ['ok' => true, 'account' => 'alice@zabbix', 'role' => null, 'pinned' => false,
			'source' => 'zabbix', 'disabled' => false, 'locked' => false]];
	}

	/* ------------------------------- the decision table ------------------------------- */

	$asked = fn(array $a) => $a + ['asked' => true, 'role' => null, 'pinned' => false, 'source' => 'zabbix', 'disabled' => false, 'locked' => false, 'error' => null, 'at' => 0];
	$d = EpRole::decide(USER_TYPE_SUPER_ADMIN, null);
	check('a Super admin edits, without asking ElasticPro', $d['write'] && $d['why'] === 'super');
	$d = EpRole::decide(USER_TYPE_ZABBIX_ADMIN, $asked(['role' => 'admin']));
	check('a Zabbix Admin whom ElasticPro calls an admin edits', $d['write'] && $d['why'] === 'elasticpro_admin' && $d['note'] === null);
	$d = EpRole::decide(USER_TYPE_ZABBIX_ADMIN, $asked(['role' => 'operator']));
	check('a Zabbix Admin whom ElasticPro calls an operator reads', !$d['write'] && $d['role'] === 'operator');
	check('and is told why, with their ElasticPro role', $d['note'] === 'Editing needs an ElasticPro admin role (or Zabbix Super admin). Your ElasticPro role: operator.', $d['note']);
	$d = EpRole::decide(USER_TYPE_ZABBIX_USER, $asked(['role' => 'admin']));
	check('a Zabbix User whom ElasticPro calls an admin edits too — ElasticPro decides', $d['write']);
	$d = EpRole::decide(USER_TYPE_ZABBIX_USER, $asked(['role' => 'user']));
	check('a Zabbix User whom ElasticPro calls a user reads', !$d['write'] && strpos((string) $d['note'], 'Your ElasticPro role: user.') !== false, $d);
	$d = EpRole::decide(USER_TYPE_ZABBIX_ADMIN, $asked(['role' => null]));
	check('never seen by ElasticPro: the Zabbix mapping, and a Zabbix Admin is an operator there', !$d['write'] && $d['role'] === 'operator'
		&& strpos((string) $d['note'], 'from your Zabbix user type') !== false, $d);
	$d = EpRole::decide(USER_TYPE_ZABBIX_ADMIN, $asked(['role' => 'admin', 'disabled' => true]));
	check('an admin blocked in ElasticPro does not edit, and is told so', !$d['write'] && strpos((string) $d['note'], 'blocked in ElasticPro') !== false, $d);
	$d = EpRole::decide(USER_TYPE_ZABBIX_ADMIN, $asked(['role' => 'admin', 'locked' => true]));
	check('an admin locked in ElasticPro does not edit, and is told so', !$d['write'] && strpos((string) $d['note'], 'locked in ElasticPro') !== false, $d);
	$d = EpRole::decide(USER_TYPE_ZABBIX_ADMIN, ['asked' => false, 'error' => 'ElasticPro did not answer at http://ep-core:8765: timeout']);
	check('ElasticPro not answering: Super admins only, said plainly', !$d['write'] && $d['why'] === 'unasked'
		&& $d['note'] === 'ElasticPro could not be asked who may edit; only Super admins can edit until it answers.', $d);
	check('with the reason beside it', $d['hint'] === 'ElasticPro did not answer at http://ep-core:8765: timeout', $d['hint']);
	$d = EpRole::decide(4, null);
	check('a guest never edits', !$d['write'] && $d['why'] === 'no_type');
	check('the read-only lines are the note, then the reason', EpRole::noteLines(['A.', 'b']) === ['A.', 'Why: b.']
		&& EpRole::noteLines([null, null]) === ['Read only. Editing needs an ElasticPro admin role (or Zabbix Super admin).']
		&& EpRole::noteLines(null) === EpRole::noteLines([null, null]));

	/* ---------------------------- the request, byte for byte ---------------------------- */

	$sent = []; $now = [1790000000]; $session = null;
	$r = role(ok(['role' => 'admin']), $sent, $now, $session);
	$a = $r->answer('alice', USER_TYPE_ZABBIX_ADMIN);
	check('one request', count($sent) === 1);
	$json = '{"zabbix_user":"alice","zabbix_user_type":2,"ts":1790000000}';
	check('to /sso/zabbix/role on the core, the claim in the query', $sent[0]['url'] === 'http://ep-core:8765/sso/zabbix/role?claim='.rawurlencode($json), $sent[0]['url']);
	parse_str((string) parse_url($sent[0]['url'], PHP_URL_QUERY), $q);
	check('the claim decodes to exactly the signed JSON', ($q['claim'] ?? null) === $json, $q);
	$sig = null;
	foreach ($sent[0]['headers'] as $h) {
		if (stripos($h, 'X-Zabbix-Module-Signature: ') === 0) { $sig = substr($h, strlen('X-Zabbix-Module-Signature: ')); }
	}
	check('signed: HMAC-SHA256 of the exact JSON bytes under the pairing secret', $sig === hash_hmac('sha256', $json, SECRET), $sig);
	check('with the ElasticPro module\'s own SsoClient::sign()', $sig === SsoClient::sign(SECRET, $json));
	check('the secret is in no header and not in the URL', strpos(implode("\n", $sent[0]['headers']).$sent[0]['url'], SECRET) === false);
	check('the answer is read', $a['asked'] && $a['role'] === 'admin' && $a['source'] === 'zabbix');
	check('a name with spaces and non-ASCII letters is carried as it is signed', EpRole::claim('Zoë Admin', 2, 5) === '{"zabbix_user":"Zoë Admin","zabbix_user_type":2,"ts":5}');
	check('TLS is verified as the pairing says', $sent[0]['tls'] === true);

	/* ------------------------------- the session cache ------------------------------- */

	$r = role(ok(['role' => 'admin']), $sent, $now, $session);   // a new request: no memo
	$sent = [];
	$now[0] += 59;
	$r->answer('alice', USER_TYPE_ZABBIX_ADMIN);
	check('59 s later the session answers, no request', $sent === []);
	$r = role(ok(['role' => 'operator']), $sent, $now, $session);
	$now[0] += 2;
	$a = $r->answer('alice', USER_TYPE_ZABBIX_ADMIN);
	check('61 s after it was asked, ElasticPro is asked again', count($sent) === 1 && $a['role'] === 'operator', [$sent, $a]);
	$r = role(ok(['role' => 'admin']), $sent, $now, $session);
	$now[0] += 3600; $sent = [];
	$a = $r->answer('alice', USER_TYPE_ZABBIX_ADMIN, false);
	check('not fresh (the sidebar on other Zabbix pages): any age will do', $sent === [] && $a['role'] === 'operator', $a);
	$a = $r->answer('alice', USER_TYPE_ZABBIX_ADMIN);
	check('the same request asks once even for a fresh answer after that — the first one decided', $sent === []);
	$r = role(ok(['role' => 'admin']), $sent, $now, $session);
	$a = $r->answer('bob', USER_TYPE_ZABBIX_ADMIN);
	check('another user is asked about, not given alice\'s answer', count($sent) === 1 && $a['role'] === 'admin');
	$r = role(ok(['role' => 'admin']), $sent, $now, $session);
	$sent = [];
	$r->answer('bob', USER_TYPE_ZABBIX_USER);
	check('the same user with another Zabbix type is asked again', count($sent) === 1);
	check('kept under one key, for one user', isset($session['user'], $session['type'], $session['answer']) && $session['user'] === 'bob');

	/* --------------------------------- when it fails --------------------------------- */

	$fails = [
		'unreachable' => [['status' => 0, 'data' => null, 'error' => 'Connection refused'], '/did not answer at http:\/\/ep-core:8765: Connection refused/'],
		'bad signature' => [['status' => 401, 'data' => ['ok' => false, 'kind' => 'bad_signature', 'message' => 'the signature does not match']], '/refused the question \(HTTP 401\): the signature does not match/'],
		'stale' => [['status' => 401, 'data' => ['ok' => false, 'kind' => 'stale', 'message' => 'too old']], '/HTTP 401/'],
		'not the hosted build' => [['status' => 404, 'data' => null], '/answered HTTP 404/'],
		'not configured' => [['status' => 503, 'data' => ['ok' => false, 'kind' => 'not_configured', 'message' => 'set ELASTICPRO_ZABBIX_SSO_SECRET']], '/HTTP 503/'],
		'rate limited' => [['status' => 429, 'data' => ['ok' => false, 'kind' => 'rate_limited', 'message' => 'wait a minute']], '/HTTP 429/']
	];
	foreach ($fails as $what => [$reply, $why]) {
		$sent = []; $session = null;
		$r = role($reply, $sent, $now, $session);
		$d = $r->decision('alice', USER_TYPE_ZABBIX_ADMIN);
		check($what.': nobody but a Super admin edits', !$d['write'] && $d['why'] === 'unasked', $d);
		check($what.': the reason is given', preg_match($why, (string) $d['hint']) === 1, $d['hint']);
		check($what.': and never the secret', strpos(json_encode($d), SECRET) === false);
	}
	$sent = []; $session = null;
	$r = role(ok([]), $sent, $now, $session, ['error' => 'Zabbix is not paired with ElasticPro (Administration → ElasticPro)']);
	$d = $r->decision('alice', USER_TYPE_ZABBIX_ADMIN);
	check('not paired: nothing is sent, Super admins only, and the page says why', $sent === [] && !$d['write']
		&& $d['hint'] === 'Zabbix is not paired with ElasticPro (Administration → ElasticPro)', $d);
	$sent = []; $session = null;
	$r = role(['status' => 200, 'data' => ['ok' => true, 'role' => 'root']], $sent, $now, $session);
	$d = $r->decision('alice', USER_TYPE_ZABBIX_ADMIN);
	check('a role ElasticPro does not have is not taken as admin', !$d['write'], $d);
	$sent = []; $session = null;
	$r = role(ok(['role' => 'admin']), $sent, $now, $session);
	$r->decision('root', USER_TYPE_SUPER_ADMIN);
	$r->decision('guest', 4);
	check('a Super admin and a guest are not asked about at all', $sent === [] && $session === null);

	/* ------------------------------ the pre-check, pure ------------------------------ */

	$needs = ['groups' => ['acme' => true, 'ElasticPro clients' => true, 'ES Data Hot' => true, 'Log archive' => true], 'client_group' => 'acme',
		'hosts' => ['11' => 'acme-Master', '12' => 'acme-ES-Cluster'], 'routing' => ['acme'],
		'template_groups' => ['Templates/Applications' => true, 'Templates/New' => true], 'templates_hidden' => ['T one']];
	$rights = ['groups_seen' => ['ElasticPro clients', 'ES Data Hot', 'Log archive'], 'groups_editable' => ['ElasticPro clients', 'Log archive'],
		'hosts_editable' => ['12'], 'template_groups_seen' => ['Templates/Applications'], 'template_groups_editable' => []];
	$out = WriteCheck::refusals($needs, $rights, false);
	$has = fn(string $re) => (bool) preg_grep($re, $out);
	check('a missing client group: creating it is a Super admin\'s, named', $has('/^Creating the host group "acme" for a new client needs a Zabbix Super admin\./'), $out);
	check('a read-only group: write permission, named', $has('/^Putting hosts in host group "ES Data Hot" needs write permission on it in Zabbix/'), $out);
	check('a host not writable: named', $has('/^Changing the host "acme-Master" needs write permission/') && !$has('/acme-ES-Cluster/'), $out);
	check('alert routing: a Super admin\'s, named', $has('/^Keeping the cluster DL alert routing or weekly report of "acme" in step .* needs a Zabbix Super admin/'), $out);
	check('a missing template group: a Super admin\'s, named', $has('/^Creating the template group "Templates\/New" for the master template needs a Zabbix Super admin/'), $out);
	check('a read-only template group: named', $has('/^Writing the master template needs write permission on template group "Templates\/Applications"/'), $out);
	check('hidden templates: named', $has('/^These templates are not visible to your Zabbix user: "T one"/'), $out);
	check('one sentence per step, no more', count($out) === 7, $out);
	check('a group that is not the client\'s is not called a new client\'s', WriteCheck::refusals(['groups' => ['X' => true]] + WriteCheck::none(), [], false)
		=== ['Creating the host group "X" needs a Zabbix Super admin. (Your Zabbix user cannot see a group of that name: it does not exist yet, or your user groups give no permission on it.)']);
	$all = ['groups_seen' => array_keys($needs['groups']), 'groups_editable' => array_keys($needs['groups']), 'hosts_editable' => [11, 12],
		'template_groups_seen' => array_keys($needs['template_groups']), 'template_groups_editable' => array_keys($needs['template_groups'])];
	check('every right there and no Super-admin step: nothing refused',
		WriteCheck::refusals(['routing' => [], 'templates_hidden' => []] + $needs, $all, false) === []);
	check('a Super admin: nothing refused whatever is needed', WriteCheck::refusals($needs, [], true) === []);
	$m = WriteCheck::merge(['groups' => ['a' => true], 'routing' => ['x'], 'hosts' => ['1' => 'h1']] + WriteCheck::none(),
		['groups' => ['b' => true], 'routing' => ['x', 'y'], 'hosts' => ['2' => 'h2'], 'templates_hidden' => ['t']] + WriteCheck::none());
	check('needs merge: groups, hosts, routing once each', array_keys($m['groups']) === ['a', 'b'] && $m['routing'] === ['x', 'y']
		&& array_keys($m['hosts']) === [1, 2] && $m['templates_hidden'] === ['t'], $m);
	check('nothing needed: no rights asked, nothing refused', WriteCheck::refusals(WriteCheck::none(), [], false) === []);

	/* ----------------------- one wording, one decision, everywhere ----------------------- */

	foreach (['ep.clients.list', 'ep.clients.edit', 'ep.clients.settings'] as $v) {
		$src = file_get_contents(__DIR__.'/../views/'.$v.'.php');
		check("$v draws its read-only note from EpRole::noteLines()", str_contains($src, 'EpRole::noteLines($data[\'write_note\'] ?? null)'));
		check("$v no longer says only Super admins change things", !preg_match('/only (a )?Super admins? change/', $src));
	}
	$base = file_get_contents(__DIR__.'/../actions/Base.php');
	check('Base::canWrite() is EpRole\'s decision', str_contains($base, 'EpRole::current()->decision(') && !preg_match('/function canWrite\(\): bool \{\s*return \$this->getUserType\(\) == USER_TYPE_SUPER_ADMIN;/', $base));
	$module = file_get_contents(__DIR__.'/../Module.php');
	check('the sidebar asks the same decision', str_contains($module, 'EpRole::current()->decision('));
	// Every action that writes to Zabbix asks the pre-check before its backup.
	foreach (['ClientSave' => 'writeRefusals(', 'ImportUpload' => 'writeRefusals(', 'ImportApply' => 'writeRefusals(', 'ClientRecreate' => 'refuseUnlessAllowed(',
			'ClientStatus' => 'refuseUnlessAllowed(', 'ClientRemove' => 'refuseUnlessAllowed(', 'ClientRelease' => 'refuseUnlessAllowed(',
			'BackupRestore' => 'writeRefusals(', 'RoleSave' => 'refuseUnlessAllowed(', 'TemplateInstall' => 'refuseUnlessAllowed('] as $a => $call) {
		$src = file_get_contents(__DIR__.'/../actions/'.$a.'.php');
		$at = strpos($src, $call);
		$backup = strpos($src, '$this->backup(');
		check("$a asks the pre-check".($backup !== false ? ' before its backup' : ''), $at !== false && ($backup === false || $at < $backup), [$at, $backup]);
	}
	$wc = file_get_contents(__DIR__.'/../lib/WriteCheck.php');
	check('each Super-admin-only refusal carries the decision marker', substr_count($wc, 'gstack-shortcut(dec-cf6faa2e-29c4-451b-a5f0-9db125b96d1f)') === 3);

	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
