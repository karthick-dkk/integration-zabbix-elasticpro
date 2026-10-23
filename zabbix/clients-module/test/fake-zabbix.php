<?php
/**
 * The fake Zabbix the PHP suites run against: its constants, translation stubs, the store of
 * hosts, templates, groups, macros, items and the slice of the API the module asks for. Shared by
 * e2e.test.php and discover.test.php; see e2e.test.php for why it is strict.
 */
/*
 * Zabbix's own constants, with Zabbix 7.0's values. The values matter: the module compares
 * against them (INTERFACE_TYPE_AGENT on an interface, HOST_STATUS_NOT_MONITORED on a host),
 * and a fake that invented its own numbers would agree with the module about nothing.
 */
foreach ([
	'ZBX_MACRO_TYPE_TEXT' => 0, 'ZBX_MACRO_TYPE_SECRET' => 1, 'ZBX_MACRO_TYPE_VAULT' => 2,
	'INTERFACE_TYPE_AGENT' => 1, 'INTERFACE_PRIMARY' => 1, 'INTERFACE_SECONDARY' => 0,
	'INTERFACE_USE_IP' => 1, 'INTERFACE_USE_DNS' => 0,
	'HOST_STATUS_MONITORED' => 0, 'HOST_STATUS_NOT_MONITORED' => 1,
	'HOST_INVENTORY_DISABLED' => -1, 'HOST_INVENTORY_MANUAL' => 0, 'HOST_INVENTORY_AUTOMATIC' => 1,
	'HOST_MAINTENANCE_STATUS_OFF' => 0,
	'TAG_OPERATOR_LIKE' => 0, 'TAG_OPERATOR_EQUAL' => 1,
	'TAG_EVAL_TYPE_AND_OR' => 0, 'TAG_EVAL_TYPE_OR' => 2,
	'ITEM_STATE_NORMAL' => 0, 'ITEM_STATE_NOTSUPPORTED' => 1,
	'ZBX_MONITORED_BY_SERVER' => 0, 'ZBX_MONITORED_BY_PROXY' => 1, 'ZBX_MONITORED_BY_PROXY_GROUP' => 2,
	'TRIGGER_VALUE_FALSE' => 0, 'TRIGGER_VALUE_TRUE' => 1,
	'USER_TYPE_ZABBIX_USER' => 1, 'USER_TYPE_ZABBIX_ADMIN' => 2, 'USER_TYPE_SUPER_ADMIN' => 3,
	'MEDIA_TYPE_EMAIL' => 0, 'MEDIA_TYPE_STATUS_ACTIVE' => 0, 'MEDIA_STATUS_ACTIVE' => 0,
	'GROUP_GUI_ACCESS_DISABLED' => 3, 'PERM_READ' => 2,
	'EVENT_SOURCE_TRIGGERS' => 0, 'ACTION_STATUS_ENABLED' => 0, 'ACTION_PAUSE_SUPPRESSED_TRUE' => 1,
	'CONDITION_EVAL_TYPE_AND_OR' => 0, 'ZBX_CONDITION_TYPE_HOST_GROUP' => 0, 'CONDITION_OPERATOR_EQUAL' => 0,
	'OPERATION_TYPE_MESSAGE' => 0, 'OPERATION_TYPE_RECOVERY_MESSAGE' => 11,
	'MAINTENANCE_TYPE_NORMAL' => 0, 'MAINTENANCE_TYPE_NODATA' => 1, 'TIMEPERIOD_TYPE_ONETIME' => 3,
	'ZBX_REPORT_PERIOD_WEEK' => 1, 'ZBX_REPORT_CYCLE_WEEKLY' => 1, 'ZBX_REPORT_STATUS_ENABLED' => 0,
	'ZBX_WIDGET_FIELD_TYPE_GROUP' => 2, 'ZBX_TM_TASK_CHECK_NOW' => 6
] as $k => $v) { define($k, $v); }
define('API_OUTPUT_COUNT', 'count');

set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
// Guarded because PHP's gettext extension already defines _(), and a Zabbix frontend host
// has gettext loaded — Zabbix uses it for translations. Without the guard every suite
// died with "Cannot redeclare _()" on exactly the machine the modules run on. gettext's
// _() returns its argument when no domain is bound, which is what the stub did anyway.
if (!function_exists('_')) { function _($s) { return $s; } }
/**
 * Both %N$s and %N$d, because some of the module's own messages use %1$d — the count of
 * pre-rename macros that stopped a save is one — and a stub that only knew %N$s would leave
 * the placeholder in the string, so a test asserting what the operator reads would fail for a
 * reason that has nothing to do with the module.
 */
if (!function_exists('_s')) {
	function _s($s, ...$a) {
		foreach ($a as $i => $v) {
			$s = str_replace(['%'.($i + 1).'$s', '%'.($i + 1).'$d'], (string) $v, $s);
		}
		return $s;
	}
}
if (!function_exists('_n')) { function _n($a, $b, $n) { return str_replace('%1$s', (string) $n, $n == 1 ? $a : $b); } }

/**
 * Zabbix's own channel for "why the last call failed". Reconciler::api() and
 * Lifecycle::api() read it after a call answered false, so the fake has to fill it: without
 * it every refusal would read "no reason given" and the tests below could not tell a
 * permission refusal from a constraint one.
 */
function get_and_clear_messages(): array {
	$out = array_map(fn($m) => ['message' => $m], \Zbx::$messages);
	\Zbx::$messages = [];
	return $out;
}

/** The fake Zabbix: its data, and the log of what was asked of it. */
class Zbx {
	/** @var array hostid => record */
	public static $hosts = [];
	/** @var array templateid => ['host' => name, 'uuid' => string] */
	public static $templates = [];
	/** @var array groupid => name */
	public static $groups = [];
	/** @var array hostmacroid => ['hostid', 'macro', 'value', 'type'] */
	public static $macros = [];
	/** @var array name => ['proxyid' => id, 'lastaccess' => int] */
	public static $proxies = [];
	/** @var array name => ['proxy_groupid' => id, 'state' => int] */
	public static $proxyGroups = [];
	/** @var array every call made: [resource.method, params] */
	public static $calls = [];
	/** @var array every call that changed something */
	public static $wrote = [];
	/** @var array "Host.create" => the reason Zabbix gives for refusing it */
	public static $deny = [];
	/** @var string[] what get_and_clear_messages() will hand back */
	public static $messages = [];
	/** @var bool a read-only token: every write is refused, every read works */
	public static $readOnly = false;
	/**
	 * @var bool what a Zabbix Admin sees: hosts, but no templates at all. template.get
	 * answers an empty list and host.get shows no parentTemplates — the ambiguity the module
	 * must not read as "this install has no templates".
	 */
	public static $hideTemplates = false;
	/** @var int */
	public static $nextId = 1;
	/**
	 * @var string[] host groups the signed-in user may read but not write: absent from
	 * hostgroup.get with editable, and their hosts absent from host.get with editable unless
	 * another of their groups is writable — a Zabbix Admin's view of a group given read only.
	 */
	public static $readOnlyGroups = [];
	/** @var string[] host groups the signed-in user has no permission on: absent from every hostgroup.get */
	public static $hiddenGroups = [];
	/**
	 * @var array itemid => ['itemid', 'hostid', 'key_', 'value_type', 'templateid', 'flags', …] — on a
	 * host or a template (hostid is the template's id). Linking a template gives the host its items
	 * the way Zabbix does: a host item with the same key is ADOPTED (same id, templateid set), a
	 * key another linked template already gives is REFUSED; unlinking keeps them as host items,
	 * unlinking with clear deletes them. Empty unless a test adds items, so the suites that never
	 * do see the empty answers they always saw.
	 */
	public static $items = [];
	/** @var array itemid => discovery rule, same shape as $items */
	public static $rules = [];
	/** @var array globalmacroid => ['globalmacroid', 'macro', 'value', 'type'] */
	public static $globals = [];

	public static function reset(): void {
		self::$hosts = []; self::$templates = []; self::$groups = []; self::$macros = [];
		self::$proxies = []; self::$proxyGroups = [];
		self::$calls = []; self::$wrote = []; self::$deny = []; self::$messages = [];
		self::$readOnly = false; self::$hideTemplates = false; self::$nextId = 1;
		self::$readOnlyGroups = []; self::$hiddenGroups = [];
		self::$items = []; self::$rules = []; self::$globals = [];
	}

	/** An item on a host or template. */
	public static function addItem(string $hostid, string $key, string $valueType = '3', array $extra = []): string {
		$id = self::id();
		self::$items[$id] = ['itemid' => $id, 'hostid' => $hostid, 'key_' => $key, 'name' => $key, 'value_type' => $valueType,
			'templateid' => '0', 'flags' => '0', 'type' => '2', 'status' => '0'] + $extra;
		return $id;
	}

	/** A discovery rule on a host or template. */
	public static function addRule(string $hostid, string $key): string {
		$id = self::id();
		self::$rules[$id] = ['itemid' => $id, 'hostid' => $hostid, 'key_' => $key, 'templateid' => '0', 'flags' => '1'];
		return $id;
	}

	/** What linking template $tid does to host $hid's items; false (and a message) when Zabbix would refuse. */
	public static function inherit(string $hid, string $tid): bool {
		$plan = [];
		foreach (['items', 'rules'] as $store) {
			foreach (self::$$store as $ti) {
				if ($ti['hostid'] !== $tid) { continue; }
				$mine = null;
				foreach (self::$$store as $hi) {
					if ($hi['hostid'] === $hid && $hi['key_'] === $ti['key_']) { $mine = $hi; }
				}
				if ($mine !== null && $mine['templateid'] !== '0' && (self::$$store[$mine['templateid']]['hostid'] ?? null) !== $tid) {
					self::$messages[] = 'Item with key "'.$ti['key_'].'" already exists on "'.self::$hosts[$hid]['host'].'", inherited from another template.';
					return false;
				}
				$plan[] = [$store, $ti, $mine];
			}
		}
		foreach ($plan as [$store, $ti, $mine]) {
			if ($mine !== null) {
				self::$$store[$mine['itemid']]['templateid'] = $ti['itemid'];
				self::$$store[$mine['itemid']]['value_type'] = $ti['value_type'] ?? ($mine['value_type'] ?? '3');
			}
			else {
				$id = self::id();
				self::$$store[$id] = ['itemid' => $id, 'hostid' => $hid, 'templateid' => $ti['itemid']] + $ti;
			}
		}
		return true;
	}

	/** Unlink: the template's items stay as host items; with $clear they are deleted. */
	public static function disinherit(string $hid, string $tid, bool $clear): void {
		foreach (['items', 'rules'] as $store) {
			foreach (self::$$store as $id => $hi) {
				if ($hi['hostid'] !== $hid || $hi['templateid'] === '0' || (self::$$store[$hi['templateid']]['hostid'] ?? null) !== $tid) { continue; }
				if ($clear) { unset(self::$$store[$id]); }
				else { self::$$store[$id]['templateid'] = '0'; }
			}
		}
	}

	/** item.get and discoveryrule.get over one store. */
	public static function itemGet(string $store, array $p): array {
		$out = [];
		$templateIds = array_map('strval', array_keys(self::$templates));
		foreach (self::$$store as $i) {
			if (isset($p['hostids']) && !in_array($i['hostid'], self::vals($p['hostids']), true)) { continue; }
			if (isset($p['templateids']) && !in_array($i['hostid'], self::vals($p['templateids']), true)) { continue; }
			if (isset($p['itemids']) && !in_array($i['itemid'], self::vals($p['itemids']), true)) { continue; }
			if (isset($p['filter']) && !self::matchFilter($i, (array) $p['filter'])) { continue; }
			if (array_key_exists('templated', $p) && $p['templated'] !== null && (bool) $p['templated'] !== in_array($i['hostid'], $templateIds, true)) { continue; }
			if (self::$hideTemplates && in_array($i['hostid'], $templateIds, true)) { continue; }
			$out[] = $i;
		}
		return $out;
	}

	private static function id(): string {
		return (string) self::$nextId++;
	}

	public static function groupId(string $name): string {
		foreach (self::$groups as $id => $have) {
			if ($have === $name) { return (string) $id; }
		}
		$id = self::id();
		self::$groups[$id] = $name;
		return $id;
	}

	/** A template Zabbix holds. A host linked to one implies it exists, so addHost calls this too. */
	public static function addTemplate(string $name, string $uuid = ''): string {
		foreach (self::$templates as $id => $t) {
			if ($t['host'] === $name) {
				if ($uuid !== '') { self::$templates[$id]['uuid'] = $uuid; }
				return (string) $id;
			}
		}
		$id = self::id();
		self::$templates[$id] = ['host' => $name, 'uuid' => $uuid];
		return $id;
	}

	/**
	 * A host. 'macros' is macro => value or macro => [value, type]; it goes into the one
	 * macro store, so host.get's selectMacros and usermacro.get can never disagree about what
	 * a host carries — the disagreement Reconciler::hasStoredPassword() would be caught by.
	 */
	public static function addHost(array $f): string {
		$id = self::id();
		$h = [
			'hostid' => $id,
			'host' => (string) $f['host'],
			'name' => (string) ($f['name'] ?? $f['host']),
			'status' => (string) ($f['status'] ?? HOST_STATUS_MONITORED),
			'monitored_by' => (string) ($f['monitored_by'] ?? ZBX_MONITORED_BY_SERVER),
			'proxyid' => (string) ($f['proxyid'] ?? 0),
			'proxy_groupid' => (string) ($f['proxy_groupid'] ?? 0),
			'inventory_mode' => (string) ($f['inventory_mode'] ?? HOST_INVENTORY_DISABLED),
			'maintenance_status' => (string) HOST_MAINTENANCE_STATUS_OFF,
			'active_available' => '0',
			'hostgroups' => [],
			'parentTemplates' => [],
			'interfaces' => [],
			'tags' => [],
			'inventory' => ['notes' => (string) ($f['notes'] ?? '')]
		];
		foreach ((array) ($f['groups'] ?? []) as $g) {
			$h['hostgroups'][] = ['groupid' => self::groupId($g), 'name' => $g];
		}
		foreach ((array) ($f['templates'] ?? []) as $t) {
			$h['parentTemplates'][] = ['templateid' => self::addTemplate($t), 'host' => $t];
		}
		$link = array_column($h['parentTemplates'], 'templateid');
		foreach ((array) ($f['tags'] ?? []) as $t) {
			$h['tags'][] = ['tag' => (string) $t['tag'], 'value' => (string) ($t['value'] ?? '')];
		}
		if (isset($f['ip'])) {
			$h['interfaces'][] = ['interfaceid' => self::id(), 'ip' => (string) $f['ip'], 'dns' => '',
				'type' => (string) INTERFACE_TYPE_AGENT, 'main' => (string) INTERFACE_PRIMARY,
				'available' => (string) ($f['available'] ?? 1)];
		}
		self::$hosts[$id] = $h;
		foreach ($link as $tid) { self::inherit($id, (string) $tid); }
		foreach ((array) ($f['macros'] ?? []) as $macro => $v) {
			self::addMacro($id, (string) $macro, is_array($v) ? (string) $v[0] : (string) $v, is_array($v) ? (int) $v[1] : 0);
		}
		return $id;
	}

	/** A Zabbix proxy, so a client monitored through one can be saved. */
	public static function addProxy(string $name, int $lastaccess = 0): string {
		$id = self::id();
		self::$proxies[$name] = ['proxyid' => $id, 'lastaccess' => $lastaccess];
		return $id;
	}

	public static function addMacro(string $hostid, string $macro, string $value, int $type = 0): string {
		$id = self::id();
		self::$macros[$id] = ['hostmacroid' => $id, 'hostid' => $hostid, 'macro' => $macro, 'value' => $value, 'type' => (string) $type];
		return $id;
	}

	/* ------------------------------ what the tests ask the fake ------------------------------ */

	/** A host by its technical name, or null. */
	public static function byHost(string $host): ?array {
		foreach (self::$hosts as $h) {
			if ($h['host'] === $host) { return $h; }
		}
		return null;
	}

	/** A host's macros as macro => value. */
	public static function macrosOf(string $hostid): array {
		$out = [];
		foreach (self::$macros as $m) {
			if ($m['hostid'] === $hostid) { $out[$m['macro']] = $m['value']; }
		}
		return $out;
	}

	/** A host's tags as "tag=value" strings, sorted — one comparable shape for the checks. */
	public static function tagsOf(string $hostid): array {
		$out = array_map(fn($t) => $t['tag'].'='.$t['value'], self::$hosts[$hostid]['tags'] ?? []);
		sort($out);
		return $out;
	}

	/** Every call of one kind, with its parameters. */
	public static function calls(string $key): array {
		return array_values(array_map(fn($c) => $c[1], array_filter(self::$calls, fn($c) => $c[0] === $key)));
	}

	/* ------------------------------ the API surface ------------------------------ */

	private static function vals($v): array {
		return array_map(fn($x) => (string) $x, is_array($v) ? array_values($v) : [$v]);
	}

	/** A host's templates as the caller would see them — none at all to a Zabbix Admin. */
	private static function shown(array $h): array {
		return self::$hideTemplates ? [] : $h['parentTemplates'];
	}

	private static function matchFilter(array $rec, array $filter): bool {
		foreach ($filter as $k => $v) {
			if (!in_array((string) ($rec[$k] ?? ''), self::vals($v), true)) { return false; }
		}
		return true;
	}

	private static function matchSearch(array $rec, array $search, bool $start): bool {
		foreach ($search as $k => $v) {
			$hay = strtolower((string) ($rec[$k] ?? ''));
			$needle = strtolower((string) $v);
			if ($start ? strpos($hay, $needle) !== 0 : strpos($hay, $needle) === false) { return false; }
		}
		return true;
	}

	/**
	 * host.get's tag filter. The default is "every tag given", which is what made the
	 * two-generation filter match nothing until Reconciler::kindFilter() started passing
	 * evaltype OR; both are implemented here so that mistake would fail a test again.
	 */
	private static function matchTags(array $rec, array $tags, int $evaltype): bool {
		$hits = 0;
		foreach ($tags as $want) {
			foreach ($rec['tags'] as $t) {
				if ($t['tag'] !== $want['tag']) { continue; }
				$v = (string) ($want['value'] ?? '');
				$eq = (int) ($want['operator'] ?? TAG_OPERATOR_LIKE) === TAG_OPERATOR_EQUAL;
				if ($eq ? $t['value'] === $v : ($v === '' || strpos($t['value'], $v) !== false)) { $hits++; break; }
			}
		}
		return $evaltype === TAG_EVAL_TYPE_OR ? $hits > 0 : $hits === count($tags);
	}

	public static function hostGet(array $p): array {
		$out = [];
		foreach (self::$hosts as $id => $h) {
			if (isset($p['hostids']) && !in_array((string) $id, self::vals($p['hostids']), true)) { continue; }
			if (isset($p['groupids']) && !array_intersect(self::vals($p['groupids']), array_column($h['hostgroups'], 'groupid'))) { continue; }
			if (isset($p['templateids']) && !array_intersect(self::vals($p['templateids']), array_column(self::shown($h), 'templateid'))) { continue; }
			if (isset($p['filter']) && !self::matchFilter($h, (array) $p['filter'])) { continue; }
			if (isset($p['search']) && !self::matchSearch($h, (array) $p['search'], !empty($p['startSearch']))) { continue; }
			if (isset($p['tags']) && !self::matchTags($h, (array) $p['tags'], (int) ($p['evaltype'] ?? TAG_EVAL_TYPE_AND_OR))) { continue; }
			if (!empty($p['editable']) && !array_diff(array_column($h['hostgroups'], 'name'), self::$readOnlyGroups, self::$hiddenGroups)) { continue; }
			// A host all of whose groups the user has no permission on: Zabbix does not show it at all.
			if (self::$hiddenGroups && !array_diff(array_column($h['hostgroups'], 'name'), self::$hiddenGroups)) { continue; }
			// Only what the call asked for: see the note on $hideTemplates.
			$rec = ['hostid' => $h['hostid'], 'host' => $h['host'], 'name' => $h['name'], 'status' => $h['status'],
				'monitored_by' => $h['monitored_by'], 'proxyid' => $h['proxyid'], 'proxy_groupid' => $h['proxy_groupid'],
				'inventory_mode' => $h['inventory_mode'], 'maintenance_status' => $h['maintenance_status'],
				'active_available' => $h['active_available']];
			if (isset($p['selectHostGroups'])) { $rec['hostgroups'] = $h['hostgroups']; }
			if (isset($p['selectParentTemplates'])) { $rec['parentTemplates'] = self::shown($h); }
			if (isset($p['selectInterfaces'])) { $rec['interfaces'] = $h['interfaces']; }
			if (isset($p['selectTags'])) { $rec['tags'] = $h['tags']; }
			if (isset($p['selectInventory'])) { $rec['inventory'] = $h['inventory']; }
			if (isset($p['selectMacros'])) {
				$rec['macros'] = [];
				foreach (self::$macros as $m) {
					if ($m['hostid'] === $h['hostid']) { $rec['macros'][] = ['macro' => $m['macro'], 'type' => $m['type']]; }
				}
			}
			$out[(string) $id] = $rec;
		}
		if (isset($p['sortfield'])) {
			uasort($out, fn($a, $b) => strcmp((string) ($a[$p['sortfield']] ?? ''), (string) ($b[$p['sortfield']] ?? '')));
		}
		if (isset($p['limit'])) { $out = array_slice($out, 0, (int) $p['limit'], true); }
		return empty($p['preservekeys']) ? array_values($out) : $out;
	}

	public static function hostCreate(array $p): array {
		$f = ['host' => $p['host'], 'name' => $p['name'] ?? $p['host'], 'status' => $p['status'] ?? HOST_STATUS_MONITORED,
			'monitored_by' => $p['monitored_by'] ?? ZBX_MONITORED_BY_SERVER, 'proxyid' => $p['proxyid'] ?? 0,
			'proxy_groupid' => $p['proxy_groupid'] ?? 0, 'inventory_mode' => $p['inventory_mode'] ?? HOST_INVENTORY_DISABLED,
			'tags' => $p['tags'] ?? [], 'notes' => $p['inventory']['notes'] ?? ''];
		$f['groups'] = array_map(fn($g) => self::$groups[(string) $g['groupid']], (array) ($p['groups'] ?? []));
		$f['templates'] = array_map(fn($t) => self::$templates[(string) $t['templateid']]['host'], (array) ($p['templates'] ?? []));
		foreach ((array) ($p['interfaces'] ?? []) as $if) {
			if ((string) ($if['ip'] ?? '') !== '') { $f['ip'] = $if['ip']; }
		}
		return ['hostids' => [self::addHost($f)]];
	}

	public static function hostUpdate(array $p): array {
		$id = (string) $p['hostid'];
		foreach (['host', 'name', 'status', 'monitored_by', 'proxyid', 'proxy_groupid', 'inventory_mode'] as $k) {
			if (array_key_exists($k, $p)) { self::$hosts[$id][$k] = (string) $p[$k]; }
		}
		if (array_key_exists('tags', $p)) {
			self::$hosts[$id]['tags'] = array_values(array_map(fn($t) => ['tag' => (string) $t['tag'], 'value' => (string) ($t['value'] ?? '')], (array) $p['tags']));
		}
		if (array_key_exists('inventory', $p)) {
			self::$hosts[$id]['inventory'] = (array) $p['inventory'] + self::$hosts[$id]['inventory'];
		}
		return ['hostids' => [$id]];
	}

	public static function hostMassAdd(array $p): array {
		$ids = array_map(fn($h) => (string) $h['hostid'], (array) ($p['hosts'] ?? []));
		foreach ($ids as $id) {
			foreach ((array) ($p['templates'] ?? []) as $t) {
				$tid = (string) $t['templateid'];
				if (!in_array($tid, array_column(self::$hosts[$id]['parentTemplates'], 'templateid'), true)) {
					if (!self::inherit($id, $tid)) {
						return false;
					}
					self::$hosts[$id]['parentTemplates'][] = ['templateid' => $tid, 'host' => self::$templates[$tid]['host']];
				}
			}
			foreach ((array) ($p['groups'] ?? []) as $g) {
				$gid = (string) $g['groupid'];
				if (!in_array($gid, array_column(self::$hosts[$id]['hostgroups'], 'groupid'), true)) {
					self::$hosts[$id]['hostgroups'][] = ['groupid' => $gid, 'name' => self::$groups[$gid]];
				}
			}
		}
		return ['hostids' => $ids];
	}

	public static function hostMassRemove(array $p): array {
		$ids = self::vals($p['hostids'] ?? []);
		$drop_t = array_merge(self::vals($p['templateids'] ?? []), self::vals($p['templateids_clear'] ?? []));
		$drop_g = self::vals($p['groupids'] ?? []);
		foreach ($ids as $id) {
			foreach (self::vals($p['templateids'] ?? []) as $tid) { self::disinherit($id, $tid, false); }
			foreach (self::vals($p['templateids_clear'] ?? []) as $tid) { self::disinherit($id, $tid, true); }
			self::$hosts[$id]['parentTemplates'] = array_values(array_filter(self::$hosts[$id]['parentTemplates'],
				fn($t) => !in_array($t['templateid'], $drop_t, true)));
			self::$hosts[$id]['hostgroups'] = array_values(array_filter(self::$hosts[$id]['hostgroups'],
				fn($g) => !in_array($g['groupid'], $drop_g, true)));
		}
		return ['hostids' => $ids];
	}

	public static function hostDelete(array $ids): array {
		foreach (self::vals($ids) as $id) {
			unset(self::$hosts[$id]);
			foreach (self::$macros as $mid => $m) {
				if ($m['hostid'] === $id) { unset(self::$macros[$mid]); }
			}
		}
		return ['hostids' => self::vals($ids)];
	}

	public static function groupGet(array $p): array {
		$out = [];
		foreach (self::$groups as $id => $name) {
			if (in_array($name, self::$hiddenGroups, true) || (!empty($p['editable']) && in_array($name, self::$readOnlyGroups, true))) { continue; }
			$rec = ['groupid' => (string) $id, 'name' => $name, 'hosts' => 0];
			if (isset($p['filter']) && !self::matchFilter($rec, (array) $p['filter'])) { continue; }
			if (isset($p['search']) && !self::matchSearch($rec, (array) $p['search'], !empty($p['startSearch']))) { continue; }
			if (isset($p['groupids']) && !in_array((string) $id, self::vals($p['groupids']), true)) { continue; }
			foreach (self::$hosts as $h) {
				if (in_array((string) $id, array_column($h['hostgroups'], 'groupid'), true)) { $rec['hosts']++; }
			}
			$out[] = $rec;
		}
		return $out;
	}

	public static function groupCreate(array $p): array {
		$names = isset($p['name']) ? [$p['name']] : array_column((array) $p, 'name');
		return ['groupids' => array_map(fn($n) => self::groupId((string) $n), $names)];
	}

	public static function interfaceGet(array $p): array {
		$out = [];
		foreach (self::$hosts as $h) {
			foreach ($h['interfaces'] as $if) {
				$rec = ['interfaceid' => $if['interfaceid'], 'hostid' => $h['hostid'], 'ip' => $if['ip'], 'type' => $if['type']];
				if (isset($p['filter']) && !self::matchFilter($rec, (array) $p['filter'])) { continue; }
				if (isset($p['search']) && !self::matchSearch($rec, (array) $p['search'], !empty($p['startSearch']))) { continue; }
				$out[] = $rec;
			}
		}
		return isset($p['limit']) ? array_slice($out, 0, (int) $p['limit']) : $out;
	}

	public static function templateGet(array $p): array {
		if (self::$hideTemplates) { return []; }
		$out = [];
		foreach (self::$templates as $id => $t) {
			$rec = ['templateid' => (string) $id, 'host' => $t['host'], 'uuid' => $t['uuid']];
			if (isset($p['filter']) && !self::matchFilter($rec, (array) $p['filter'])) { continue; }
			if (isset($p['templateids']) && !in_array((string) $id, self::vals($p['templateids']), true)) { continue; }
			if (isset($p['search']) && !self::matchSearch($rec, (array) $p['search'], !empty($p['startSearch']))) { continue; }
			if (isset($p['selectMacros'])) {
				$rec['macros'] = [];
				foreach (self::$macros as $m) {
					if ($m['hostid'] === (string) $id) {
						$rec['macros'][] = ['macro' => $m['macro'], 'type' => $m['type']] + ((int) $m['type'] === 1 ? [] : ['value' => $m['value']]);
					}
				}
			}
			// selectHosts, because the mapping table counts the hosts carrying each template
			// and a stub that always answered none would have passed a count that never works.
			if (isset($p['selectHosts'])) {
				$rec['hosts'] = [];
				foreach (self::$hosts as $hid => $h) {
					foreach ((array) ($h['parentTemplates'] ?? []) as $pt) {
						if ((string) ($pt['templateid'] ?? '') === (string) $id) { $rec['hosts'][] = ['hostid' => (string) $hid]; }
					}
				}
			}
			$out[] = $rec;
		}
		if (($p['sortfield'] ?? '') === 'host') { usort($out, fn($a, $b) => strcmp($a['host'], $b['host'])); }
		if (isset($p['limit'])) { $out = array_slice($out, 0, (int) $p['limit']); }
		return $out;
	}

	public static function macroGet(array $p): array {
		$out = [];
		if (!empty($p['globalmacro'])) {
			foreach (self::$globals as $m) {
				if (isset($p['filter']) && !self::matchFilter($m, (array) $p['filter'])) { continue; }
				$out[] = (int) $m['type'] === 1 ? array_diff_key($m, ['value' => true]) : $m;
			}
			return $out;
		}
		foreach (self::$macros as $m) {
			if (isset($p['hostids']) && !in_array($m['hostid'], self::vals($p['hostids']), true)) { continue; }
			if (isset($p['filter']) && !self::matchFilter($m, (array) $p['filter'])) { continue; }
			// As Zabbix: a Secret macro's value is never returned, to anyone.
			$out[] = (int) $m['type'] === 1 ? array_diff_key($m, ['value' => true]) : $m;
		}
		return $out;
	}

	public static function macroCreate(array $p): array {
		$list = isset($p['macro']) ? [$p] : array_values($p);
		$ids = [];
		foreach ($list as $m) {
			$ids[] = self::addMacro((string) $m['hostid'], (string) $m['macro'], (string) ($m['value'] ?? ''), (int) ($m['type'] ?? 0));
		}
		return ['hostmacroids' => $ids];
	}

	public static function macroUpdate(array $p): array {
		$list = isset($p['hostmacroid']) ? [$p] : array_values($p);
		foreach ($list as $m) {
			$id = (string) $m['hostmacroid'];
			foreach (['value', 'type'] as $k) {
				if (array_key_exists($k, $m)) { self::$macros[$id][$k] = (string) $m[$k]; }
			}
		}
		return ['hostmacroids' => array_map(fn($m) => (string) $m['hostmacroid'], $list)];
	}

	public static function macroDelete(array $ids): array {
		foreach (self::vals($ids) as $id) { unset(self::$macros[(string) $id]); }
		return ['hostmacroids' => array_values(self::vals($ids))];
	}

	public static function proxyGet(array $p): array {
		$out = [];
		foreach (self::$proxies as $name => $px) {
			$rec = ['proxyid' => (string) $px['proxyid'], 'name' => $name, 'lastaccess' => (string) $px['lastaccess']];
			if (isset($p['filter']) && !self::matchFilter($rec, (array) $p['filter'])) { continue; }
			$out[] = $rec;
		}
		return $out;
	}

	public static function proxyGroupGet(array $p): array {
		$out = [];
		foreach (self::$proxyGroups as $name => $pg) {
			$rec = ['proxy_groupid' => (string) $pg['proxy_groupid'], 'name' => $name, 'state' => (string) $pg['state']];
			if (isset($p['filter']) && !self::matchFilter($rec, (array) $p['filter'])) { continue; }
			$out[] = $rec;
		}
		return $out;
	}
}

/** One Zabbix API resource. Every call is logged; a write is refused when the test says so. */
class ZbxResource {
	/** @var string */
	private $res;

	public function __construct(string $res) {
		$this->res = $res;
	}

	public function __call(string $method, array $args) {
		$p = $args[0] ?? [];
		$key = $this->res.'.'.$method;
		\Zbx::$calls[] = [$key, $p];
		if ($method !== 'get') {
			if (\Zbx::$readOnly) {
				// The wording Zabbix itself uses, so a test can tell a permission refusal
				// from a constraint one by reading the message the operator would see.
				\Zbx::$messages[] = 'No permissions to call "'.lcfirst($this->res).'.'.$method.'".';
				return false;
			}
			if (isset(\Zbx::$deny[$key])) {
				\Zbx::$messages[] = \Zbx::$deny[$key];
				return false;
			}
			\Zbx::$wrote[] = $key;
		}
		switch ($key) {
			case 'Host.get': return \Zbx::hostGet((array) $p);
			case 'Host.create': return \Zbx::hostCreate((array) $p);
			case 'Host.update': return \Zbx::hostUpdate((array) $p);
			case 'Host.massAdd': return \Zbx::hostMassAdd((array) $p);
			case 'Host.massRemove': return \Zbx::hostMassRemove((array) $p);
			case 'Host.delete': return \Zbx::hostDelete((array) $p);
			case 'HostGroup.get': return \Zbx::groupGet((array) $p);
			case 'HostGroup.create': return \Zbx::groupCreate((array) $p);
			case 'HostInterface.get': return \Zbx::interfaceGet((array) $p);
			case 'Template.get': return \Zbx::templateGet((array) $p);
			case 'UserMacro.get': return \Zbx::macroGet((array) $p);
			case 'UserMacro.create': return \Zbx::macroCreate((array) $p);
			case 'UserMacro.update': return \Zbx::macroUpdate((array) $p);
			case 'UserMacro.delete': return \Zbx::macroDelete((array) $p);
			case 'UserMacro.createGlobal':
				$id = (string) \Zbx::$nextId++;
				\Zbx::$globals[$id] = ['globalmacroid' => $id, 'macro' => $p['macro'], 'value' => (string) $p['value'], 'type' => (string) ($p['type'] ?? 0)];
				return ['globalmacroids' => [$id]];
			case 'UserMacro.updateGlobal':
				foreach (['value', 'type'] as $k) { if (array_key_exists($k, $p)) { \Zbx::$globals[(string) $p['globalmacroid']][$k] = (string) $p[$k]; } }
				return ['globalmacroids' => [(string) $p['globalmacroid']]];
			case 'Proxy.get': return \Zbx::proxyGet((array) $p);
			case 'ProxyGroup.get': return \Zbx::proxyGroupGet((array) $p);
			// Asked by SetupChecks on an install with no history yet. Empty is the honest
			// answer here and the module has to read it as "not measured yet", not "broken".
			case 'Item.get': return \Zbx::itemGet('items', (array) $p);
			case 'DiscoveryRule.get': return \Zbx::itemGet('rules', (array) $p);
			case 'ItemPrototype.get': return [];
			case 'Trigger.get': return [];
			case 'Task.create': return ['taskids' => []];
		}
		// Never an empty array for something the fake does not model: that is how a fake
		// starts passing tests for code it is not exercising.
		throw new \RuntimeException('the fake Zabbix was asked '.$key.', which it does not implement');
	}
}

class API {
	public static function __callStatic(string $name, array $args) {
		return new \ZbxResource($name);
	}
}
