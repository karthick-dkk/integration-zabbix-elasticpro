<?php
/**
 * Play inside Zabbix (2.1.15): the menu entry, the sign-in every ElasticPro frame shares
 * (lib/SignIn.php) and what PlayFrame answers the "ElasticPro Play" widget — against stand-ins
 * for the few Zabbix classes they touch.
 *   docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php test/play.test.php
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
 */
namespace {
	define('USER_TYPE_ZABBIX_USER', 1); define('USER_TYPE_ZABBIX_ADMIN', 2); define('USER_TYPE_SUPER_ADMIN', 3);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	// Guarded: a Zabbix frontend host has gettext loaded, which defines _() already.
	if (!function_exists('_')) { function _($s) { return $s; } }

	class CMenu {
		public $items = [];
		public function __construct(array $items = []) { $this->items = $items; }
		public function add(CMenuItem $item): self { $this->items[] = $item; return $this; }
		public function find(string $label): ?CMenuItem {
			foreach ($this->items as $i) { if ($i->label === $label) { return $i; } }
			return null;
		}
		public function insertAfter(string $label, CMenuItem $item): self {
			foreach ($this->items as $n => $i) {
				if ($i->label === $label) { array_splice($this->items, $n + 1, 0, [$item]); return $this; }
			}
			return $this->add($item);
		}
	}
	class CMenuItem {
		public $label; public $action = null; public $aliases = []; public $sub = null; public $id = null;
		public function __construct(string $label) { $this->label = $label; }
		public function setAction(string $a): self { $this->action = $a; return $this; }
		public function setAliases(array $a): self { $this->aliases = $a; return $this; }
		public function setId(string $id): self { $this->id = $id; return $this; }
		public function setIcon(string $i): self { return $this; }
		public function setSubMenu(CMenu $m): self { $this->sub = $m; return $this; }
		public function getSubMenu(): CMenu { return $this->sub ?? ($this->sub = new CMenu()); }
		public function hasSubMenu(): bool { return $this->sub !== null; }
	}
	class APP {
		public static $menu;
		public static function Component(): self { return new self(); }
		public function get(string $what) { return self::$menu; }
	}
	class CWebUser {
		public static $type = USER_TYPE_ZABBIX_USER;
		public static $guest = false;
		public static function isGuest() { return self::$guest; }
		public static $data = ['userid' => '7', 'username' => 'noc-alice', 'roleid' => '1', 'theme' => 'dark-theme'];
		public static function getType() { return self::$type; }
	}
	class CController {
		protected function getUserType() { return \CWebUser::$type; }
	}

	// The database, as SignIn reads the user's groups and role.
	function zbx_dbstr($v) { return "'".addslashes((string) $v)."'"; }
	function DBselect(string $sql) {
		return new \ArrayIterator(strpos($sql, 'FROM usrgrp') !== false
			? [['name' => 'NOC'], ['name' => 'Elasticsearch operators']]
			: [['name' => 'User role']]);
	}
	function DBfetch(\ArrayIterator $rows) {
		if (!$rows->valid()) { return false; }
		$row = $rows->current();
		$rows->next();
		return $row;
	}
}
namespace Zabbix\Core { abstract class CModule { public function __construct() {} } }

namespace Modules\ElasticPro\Test {
	require __DIR__.'/../Module.php';
	require __DIR__.'/../actions/PlayFrame.php';
	use Modules\ElasticPro\Module;
	use Modules\ElasticPro\Actions\PlayFrame;
	use Modules\ElasticPro\Lib\{SignIn, SsoClient};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '', PHP_EOL;
	}

	/** ElasticPro, as the sign-in hears it: the claim it was sent, and a code or a refusal. */
	class FakeCore extends SsoClient {
		public $claims = [];
		public $refuse = null;
		public function mintCode(string $username, int $user_type, array $groups, ?string $role = null, ?int $roleid = null): ?string {
			$this->claims[] = compact('username', 'user_type', 'groups', 'role', 'roleid');
			return $this->refuse === null ? 'one-time-code' : null;
		}
		public function lastError(): string { return (string) $this->refuse; }
	}
	$paired = ['core_url' => 'http://ep-core:8765', 'public_url' => 'https://ep.example.com:8443', 'secret' => str_repeat('s', 32), 'verify_tls' => true];

	/* ---------------- the menu ---------------- */
	foreach ([USER_TYPE_ZABBIX_USER, USER_TYPE_SUPER_ADMIN] as $type) {
		\CWebUser::$type = $type;
		$menu = new \CMenu();
		foreach (['Dashboards', 'Monitoring', 'Administration'] as $label) { $menu->add((new \CMenuItem($label))->setSubMenu(new \CMenu())); }
		\APP::$menu = $menu;
		(new Module())->init();
		$ep = $menu->find('ElasticPro');
		$entries = $ep === null ? [] : array_map(fn($i) => [$i->label, $i->action], $ep->getSubMenu()->items);
		$want = [['Clusters', 'elasticpro.overview'], ['Indices', 'elasticpro.indices'], ['Snapshots', 'elasticpro.snapshots'],
			['REST console', 'elasticpro.console'], ['Play', 'elasticpro.play']];
		if ($type == USER_TYPE_SUPER_ADMIN) { $want[] = ['Config', 'elasticpro.settings']; }
		check("user type $type: ElasticPro → Play, after the REST console", $entries === $want, $entries);
		$clusters = $ep->getSubMenu()->items[0];
		check("user type $type: Play is its own entry, not an alias of Clusters", !in_array('elasticpro.play', $clusters->aliases, true), $clusters->aliases);
	}
	// The guest user (guest frontend access) has a role of the User type, and no ElasticPro role.
	\CWebUser::$type = USER_TYPE_ZABBIX_USER;
	\CWebUser::$guest = true;
	$menu = new \CMenu();
	foreach (['Dashboards', 'Monitoring', 'Administration'] as $label) { $menu->add((new \CMenuItem($label))->setSubMenu(new \CMenu())); }
	\APP::$menu = $menu;
	(new Module())->init();
	check('the guest user is offered no ElasticPro menu', $menu->find('ElasticPro') === null);
	check('the guest user may not be signed in', SignIn::mayOpen() === false);
	\CWebUser::$guest = false;
	check('a signed-in Zabbix User may', SignIn::mayOpen() === true);

	/* ---------------- the one sign-in ---------------- */
	\CWebUser::$type = USER_TYPE_ZABBIX_USER;
	$core = new FakeCore($paired);
	$r = SignIn::frame('play', [], $core);
	check('paired: configured, with an address', $r['configured'] === true && is_string($r['url']), $r);
	check('the frame opens #/play, embedded, with the code and the Zabbix theme',
		$r['url'] === 'https://ep.example.com:8443/?sso_code=one-time-code&embed=1&zbx_theme=dark-theme#/play', $r['url']);
	check('the claim is the user, their type, groups (sorted) and role — as for every ElasticPro page',
		$core->claims === [['username' => 'noc-alice', 'user_type' => USER_TYPE_ZABBIX_USER,
			'groups' => ['Elasticsearch operators', 'NOC'], 'role' => 'User role', 'roleid' => 1]], $core->claims);
	check('a code is minted per frame, never kept', (SignIn::frame('play', [], $core)['url'] !== null) && count($core->claims) === 2);

	$core = new FakeCore($paired);
	$core->refuse = 'EP-410 the account is disabled';
	$r = SignIn::frame('play', [], $core);
	check('refused: no address, and the core\'s reason', $r['url'] === null && $r['error'] === 'EP-410 the account is disabled', $r);

	$unpaired = new FakeCore(['core_url' => '', 'public_url' => '', 'secret' => '', 'verify_tls' => true]);
	$r = SignIn::frame('play', [], $unpaired);
	check('not paired: not configured, and ElasticPro is not even asked', $r['configured'] === false && $r['url'] === null && $unpaired->claims === [], $r);

	foreach ([
		['https://ep.example.com:8443', 'https://ep.example.com:8443'],
		['https://ep.example.com/sub/path', 'https://ep.example.com'],
		['http://10.0.0.5:8080/', 'http://10.0.0.5:8080'],
		['', ''],
		['ep.example.com', ''],
	] as [$url, $want]) {
		check("origin of '$url'", SignIn::origin($url) === $want, SignIn::origin($url));
	}

	/* ---------------- what the widget is answered ---------------- */
	$a = PlayFrame::answer(['configured' => false, 'url' => null, 'error' => '', 'public_url' => ''], false);
	check('not paired: EP-701, a Super admin pairs it', ($a['error']['code'] ?? '') === 'EP-701'
		&& strpos($a['error']['message'], 'A Zabbix Super admin pairs it') !== false && !isset($a['url']), $a);
	$a = PlayFrame::answer(['configured' => false, 'url' => null, 'error' => '', 'public_url' => ''], true);
	check('not paired, to a Super admin: EP-701, pair it under Administration → ElasticPro',
		($a['error']['code'] ?? '') === 'EP-701' && strpos($a['error']['message'], 'Pair it under Administration → ElasticPro') !== false, $a);
	$a = PlayFrame::answer(['configured' => true, 'url' => null, 'error' => 'EP-410 the account is disabled', 'public_url' => 'https://ep.example.com'], true);
	check('refused, to a Super admin: EP-702, followed by the core\'s own reason in full', ($a['error']['code'] ?? '') === 'EP-702'
		&& $a['error']['message'] === 'ElasticPro could not sign you in.' && $a['error']['reason'] === 'EP-410 the account is disabled', $a);
	$a = PlayFrame::answer(['configured' => true, 'url' => null, 'error' => 'EP-410 the account is disabled', 'public_url' => 'https://ep.example.com'], false);
	check('refused, to anyone else: EP-702 and the core\'s code only', ($a['error']['code'] ?? '') === 'EP-702' && $a['error']['reason'] === 'EP-410', $a);
	$dead = 'could not reach ElasticPro at http://ep-core:8765: Connection refused';
	$a = PlayFrame::answer(['configured' => true, 'url' => null, 'error' => $dead, 'public_url' => 'https://ep.example.com'], false);
	check('the core\'s internal address never reaches anyone but a Super admin', $a['error']['reason'] === '' && strpos(json_encode($a), 'ep-core') === false, $a);
	check('a Super admin still sees it', PlayFrame::answer(['configured' => true, 'url' => null, 'error' => $dead, 'public_url' => ''], true)['error']['reason'] === $dead);
	check('reason(): codes only, once each', SignIn::reason('EP-511 stale (EP-511), then EP-306 at http://10.0.0.1', false) === 'EP-511 EP-306', SignIn::reason('EP-511 stale (EP-511), then EP-306 at http://10.0.0.1', false));
	$a = PlayFrame::answer(['configured' => true, 'url' => 'https://ep.example.com:8443/?sso_code=c&embed=1&zbx_theme=&#/play', 'error' => '', 'public_url' => 'https://ep.example.com:8443'], false);
	check('signed in: the address and the only origin whose messages count', $a === ['url' => 'https://ep.example.com:8443/?sso_code=c&embed=1&zbx_theme=&#/play', 'origin' => 'https://ep.example.com:8443'], $a);

	/* ---------------- who may ask ---------------- */
	$may = function (int $type): bool {
		\CWebUser::$type = $type;
		return (new \ReflectionMethod(PlayFrame::class, 'checkPermissions'))->invoke(new PlayFrame());
	};
	check('a Zabbix User may load Play, as they may open the ElasticPro pages', $may(USER_TYPE_ZABBIX_USER));
	check('so may an Admin and a Super admin', $may(USER_TYPE_ZABBIX_ADMIN) && $may(USER_TYPE_SUPER_ADMIN));
	check('nobody below a Zabbix User', !$may(0));
	\CWebUser::$guest = true;
	check('not the guest user, whose role is of the User type', !$may(USER_TYPE_ZABBIX_USER));
	\CWebUser::$guest = false;

	echo $passed, ' passed, ', $failed, ' failed', PHP_EOL;
	exit($failed ? 1 : 0);
}
