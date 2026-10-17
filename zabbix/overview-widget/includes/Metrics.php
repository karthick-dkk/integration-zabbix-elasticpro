<?php declare(strict_types = 0);

namespace Modules\EpOverview\Includes;

/**
 * The metrics a Client overview card lists: what each one may be, what it is when left alone,
 * and the one function that decides whether a metric as typed is acceptable — used by the
 * widget's Metrics field when the form is applied, by the metric editor when Update is
 * pressed, and when a saved widget is drawn. Three readers, one rule.
 *
 * A metric reads either a built-in figure (a client master host item the reports already use,
 * or a figure every client server has) or any Zabbix item key on the client's servers. Pure: no
 * Zabbix API here, so the tests can hold every rule.
 */
class Metrics {

	public const MAX = 20;
	public const MAX_THRESHOLDS = 10;
	public const MAX_NAME = 64;
	public const MAX_KEY = 255;
	public const MAX_UNITS = 16;

	/** Largest magnitude a typed number may have: beyond it, it is a typo (or 1e999), not a threshold. */
	public const MAX_NUMBER = 1e12;

	/** A literal start a key pattern needs before its first *, so it cannot match every item there is. */
	public const PATTERN_PREFIX = 3;

	/** How many items a pattern may bring in, per host and per metric for one client, before it is capped. */
	public const PATTERN_PER_HOST = 50;
	public const PATTERN_PER_METRIC = 500;

	public const SOURCE_BUILTIN = 1;
	public const SOURCE_ITEM = 2;

	public const COMBINE_SUM = 1;
	public const COMBINE_AVG = 2;
	public const COMBINE_MAX = 3;
	public const COMBINE_MIN = 4;
	public const COMBINE_COUNT = 5;

	public const DISPLAY_AS_IS = 1;
	public const DISPLAY_BAR = 2;
	public const DISPLAY_INDICATORS = 3;
	public const DISPLAY_SPARKLINE = 4;

	public const WORSE_HIGHER = 1;
	public const WORSE_LOWER = 2;

	public const LEVEL_WARN = 1;
	public const LEVEL_HIGH = 2;

	public const NODATA_SHOW = 1;
	public const NODATA_HIDE = 2;

	public const CLICK_GRAPH = 1;
	public const CLICK_LATEST = 2;
	public const CLICK_NONE = 3;

	/** Where a built-in figure lives: one item on the client master host, or one per server. */
	public const ON_MASTER = 'master';
	public const ON_SERVERS = 'servers';

	/** A metric as the editor opens it. Every saved metric is this with its own values on top. */
	public const DEFAULT = [
		'uid' => '', 'name' => '', 'source' => self::SOURCE_BUILTIN, 'builtin' => '', 'key' => '', 'pattern' => 0,
		'roles' => [], 'combine' => self::COMBINE_AVG, 'units' => '', 'decimals' => '', 'short' => 1,
		'display' => self::DISPLAY_AS_IS, 'min' => '', 'max' => '',
		'trend_avg' => 1, 'trend_min' => 1, 'trend_max' => 1, 'window' => 0,
		'worse' => self::WORSE_HIGHER, 'use_settings' => 1, 'warn' => '', 'high' => '', 'thresholds' => [],
		'nodata' => self::NODATA_SHOW, 'stale' => '', 'click' => self::CLICK_GRAPH
	];

	/** What each property is stored as in Zabbix, so an integer stays an integer. */
	public const INT_FIELDS = ['source', 'pattern', 'combine', 'short', 'display', 'trend_avg', 'trend_min', 'trend_max',
		'window', 'worse', 'use_settings', 'nodata', 'click'];

	public static function combineLabels(): array {
		return [
			self::COMBINE_SUM => _('Sum'),
			self::COMBINE_AVG => _('Average'),
			self::COMBINE_MAX => _('Highest'),
			self::COMBINE_MIN => _('Lowest'),
			self::COMBINE_COUNT => _('Count over Warn')
		];
	}

	/** How the metrics list says what a combine function does. */
	public static function combineSummary(int $combine): string {
		return [
			self::COMBINE_SUM => _('Sum of servers'),
			self::COMBINE_AVG => _('Average of servers'),
			self::COMBINE_MAX => _('Highest server'),
			self::COMBINE_MIN => _('Lowest server'),
			self::COMBINE_COUNT => _('Servers over Warn')
		][$combine] ?? '';
	}

	/**
	 * Every built-in figure, by id. Master-host ones are the ep.* items of the ElasticPro client
	 * master template — the figures Client resources and Client capacity read — with one set per
	 * family from roles.json; server ones are the agent items ClientData reads for each server.
	 *
	 * `settings` marks a usage percentage, the only kind ElasticPro -> Settings' thresholds are
	 * written for. `fix` is what to do when a client does not have it.
	 */
	public static function catalogue(array $roles): array {
		$master = _('Write the master template (ElasticPro → Clients → Write master template) and check the client\'s cluster host is monitored.');
		$out = [
			'storage.usage' => ['label' => _('ES storage used'), 'on' => self::ON_MASTER, 'key' => 'ep.es.storage.usage', 'units' => '%', 'settings' => true, 'fix' => $master],
			'storage.used' => ['label' => _('ES storage used (size)'), 'on' => self::ON_MASTER, 'key' => 'ep.es.storage.used', 'units' => 'B', 'settings' => false, 'fix' => $master],
			'storage.allocated' => ['label' => _('ES storage allocated'), 'on' => self::ON_MASTER, 'key' => 'ep.es.storage.allocated', 'units' => 'B', 'settings' => false, 'fix' => $master],
			'devices.seen' => ['label' => _('Devices seen'), 'on' => self::ON_MASTER, 'key' => 'ep.devices.seen', 'units' => '', 'settings' => false,
				'fix' => _('Link the ElasticPro cluster devices template to the client\'s cluster host.')],
			'devices.usage' => ['label' => _('Devices seen of purchased'), 'on' => self::ON_MASTER, 'key' => 'ep.devices.usage', 'units' => '%', 'settings' => true,
				'fix' => _('Set Devices purchased for the client in Cluster Management.')],
			'delay.median' => ['label' => _('Log delay, median'), 'on' => self::ON_MASTER, 'key' => 'ep.delay.median', 'units' => 's', 'settings' => false,
				'fix' => _('Link the ElasticPro log delay template and let ElasticPro measure the delay.')],
			'delay.worst' => ['label' => _('Log delay, worst'), 'on' => self::ON_MASTER, 'key' => 'ep.delay.worst', 'units' => 's', 'settings' => false,
				'fix' => _('Link the ElasticPro log delay template and let ElasticPro measure the delay.')],
			'delay.late' => ['label' => _('Devices late'), 'on' => self::ON_MASTER, 'key' => 'ep.delay.late', 'units' => '', 'settings' => false,
				'fix' => _('Link the ElasticPro log delay template and let ElasticPro measure the delay.')]
		];
		foreach ($roles['families'] ?? [] as $f) {
			$id = (string) $f['id'];
			$L = (string) $f['label'];
			$out['fam.'.$id.'.cpu'] = ['label' => _s('%1$s CPU', $L), 'on' => self::ON_MASTER, 'key' => 'ep.'.$id.'.cpu.usage', 'units' => '%', 'settings' => true, 'fix' => $master];
			$out['fam.'.$id.'.mem'] = ['label' => _s('%1$s memory', $L), 'on' => self::ON_MASTER, 'key' => 'ep.'.$id.'.mem.usage', 'units' => '%', 'settings' => true, 'fix' => $master];
			$out['fam.'.$id.'.disk'] = ['label' => _s('%1$s disk', $L), 'on' => self::ON_MASTER, 'key' => 'ep.'.$id.'.disk.usage', 'units' => '%', 'settings' => true,
				'fix' => _s('Set the %1$s disk mounts in Cluster Management, then write the master template.', $L)];
			$out['fam.'.$id.'.servers'] = ['label' => _s('%1$s servers', $L), 'on' => self::ON_MASTER, 'key' => 'ep.'.$id.'.servers.allocated', 'units' => '', 'settings' => false, 'fix' => $master];
		}
		$agent = _('Link Linux by Zabbix agent -EP (or the template mapped to it on Roles → Template mapping) to the client\'s servers.');
		$out['srv.cpu'] = ['label' => _('CPU (each server)'), 'on' => self::ON_SERVERS, 'key' => 'system.cpu.util', 'units' => '%', 'settings' => true, 'fix' => $agent];
		$out['srv.mem'] = ['label' => _('Memory (each server)'), 'on' => self::ON_SERVERS, 'key' => 'vm.memory.utilization', 'units' => '%', 'settings' => true, 'fix' => $agent];
		// Each server's role mounts, as ClientData names them: / and the role's extra disks.
		$out['srv.disk'] = ['label' => _('Disk used (each server\'s mounts)'), 'on' => self::ON_SERVERS, 'key' => 'vfs.fs.dependent.size[{#MOUNT},pused]', 'units' => '%', 'settings' => true,
			'fix' => _('Set the role\'s disk mounts in Cluster Management and link the Linux agent template to the servers.')];
		return $out;
	}

	/** The disk item ClientData reads for a mount; srv.disk reads one per mount of each server. */
	public static function diskKey(string $mount): string {
		return 'vfs.fs.dependent.size['.$mount.',pused]';
	}

	/**
	 * The metrics a widget saved before metrics existed shows: what its cards showed then —
	 * ES storage used, and CPU and memory of the family its "Cards show" setting chose — at the
	 * thresholds it had (blank: ElasticPro -> Settings).
	 */
	public static function defaults(array $roles, string $family = 'es', $warn = '', $high = ''): array {
		$cat = self::catalogue($roles);
		if (!isset($cat['fam.'.$family.'.cpu'])) {
			$family = isset($cat['fam.es.cpu']) ? 'es' : (string) ($roles['families'][0]['id'] ?? 'es');
		}
		$warn = is_numeric($warn) && (float) $warn > 0 ? self::num((float) $warn) : '';
		$high = is_numeric($high) && (float) $high > 0 ? self::num((float) $high) : '';
		$out = [['uid' => 'storage', 'builtin' => 'storage.usage']];
		if (isset($cat['fam.'.$family.'.cpu'])) {
			$out[] = ['uid' => 'cpu', 'builtin' => 'fam.'.$family.'.cpu'];
			$out[] = ['uid' => 'mem', 'builtin' => 'fam.'.$family.'.mem'];
		}
		return array_map(fn($m) => ['name' => $cat[$m['builtin']]['label'], 'units' => '%', 'warn' => $warn, 'high' => $high] + $m + self::DEFAULT, $out);
	}

	/** A fresh id for a metric, so the sort order can name it whatever position it moves to. */
	public static function newUid(): string {
		return substr(bin2hex(random_bytes(6)), 0, 10);
	}

	/**
	 * A metric as it came in (form, editor or a saved widget: strings or numbers), made into the
	 * stored shape — or the reasons it cannot be.
	 *
	 * @param array $roleIds  the role ids Cluster Management has; others are dropped from Servers
	 *
	 * @return array{0: array, 1: string[]}  [clean metric, errors]
	 */
	public static function clean(array $in, array $catalogue, array $roleIds): array {
		$e = [];
		$m = self::DEFAULT;
		$str = fn($k) => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';
		$int = function ($k, array $allowed) use ($in, &$e): int {
			$v = $in[$k] ?? self::DEFAULT[$k];
			if (!is_scalar($v) || !preg_match('/^-?\d+$/', trim((string) $v)) || !in_array((int) $v, $allowed, true)) {
				$e[] = _s('"%1$s" is not one of the choices.', $k);
				return self::DEFAULT[$k];
			}
			return (int) $v;
		};
		$flag = fn($k) => $int($k, [0, 1]);

		$uid = $str('uid');
		$m['uid'] = preg_match('/^[a-z0-9]{1,16}$/', $uid) ? $uid : self::newUid();

		$m['name'] = $str('name');
		if ($m['name'] === '') {
			$e[] = _('Name cannot be empty.');
		}
		elseif (mb_strlen($m['name']) > self::MAX_NAME) {
			$e[] = _s('Name is longer than %1$s characters.', self::MAX_NAME);
		}
		elseif (preg_match('/[\x00-\x1f\x7f]/', $m['name'])) {
			$e[] = _('Name contains control characters.');
		}

		$m['source'] = $int('source', [self::SOURCE_BUILTIN, self::SOURCE_ITEM]);
		$builtin = null;
		if ($m['source'] === self::SOURCE_BUILTIN) {
			$m['builtin'] = $str('builtin');
			$builtin = $catalogue[$m['builtin']] ?? null;
			if ($builtin === null) {
				$e[] = $m['builtin'] === '' ? _('Choose a built-in metric.') : _s('There is no built-in metric "%1$s".', $m['builtin']);
			}
			else {
				// A built-in figure's units are what its item reports; nothing to type.
				$m['units'] = $builtin['units'];
			}
		}
		else {
			$m['pattern'] = $flag('pattern');
			$m['key'] = $str('key');
			$keyError = self::keyError($m['key'], (bool) $m['pattern']);
			if ($keyError !== null) {
				$e[] = $keyError;
			}
		}

		// Servers: only meaningful for a figure read off the servers; a master-host figure is one per client.
		$onServers = $m['source'] === self::SOURCE_ITEM || ($builtin !== null && $builtin['on'] === self::ON_SERVERS);
		$roles = [];
		foreach ((array) ($in['roles'] ?? []) as $r) {
			$r = is_scalar($r) ? trim((string) $r) : '';
			if ($r !== '' && in_array($r, $roleIds, true) && !in_array($r, $roles, true)) {
				$roles[] = $r;
			}
		}
		$m['roles'] = $onServers ? $roles : [];
		$m['combine'] = $int('combine', array_keys(self::combineLabels()));

		if ($m['source'] === self::SOURCE_ITEM) {
			$m['units'] = $str('units');
			if (mb_strlen($m['units']) > self::MAX_UNITS || preg_match('/[\x00-\x1f\x7f<>]/', $m['units'])) {
				$e[] = _s('Units must be at most %1$s characters, without < or >.', self::MAX_UNITS);
				$m['units'] = '';
			}
		}
		$dec = $str('decimals');
		if ($dec !== '' && (!preg_match('/^\d+$/', $dec) || (int) $dec > 10)) {
			$e[] = _('Decimals must be empty (ElasticPro → Settings) or 0 to 10.');
			$dec = '';
		}
		$m['decimals'] = $dec === '' ? '' : (string) (int) $dec;
		$m['short'] = $flag('short');
		$m['display'] = $int('display', [self::DISPLAY_AS_IS, self::DISPLAY_BAR, self::DISPLAY_INDICATORS, self::DISPLAY_SPARKLINE]);
		foreach (['min', 'max'] as $k) {
			$v = $str($k);
			if ($v !== '' && !self::isNumber($v)) {
				$e[] = _s('Bar %1$s must be a number between -1e12 and 1e12, or empty.', $k);
				$v = '';
			}
			$m[$k] = $v === '' ? '' : self::num((float) $v);
		}
		if ($m['min'] !== '' && $m['max'] !== '' && (float) $m['min'] >= (float) $m['max']) {
			$e[] = _('Bar min must be below bar max.');
		}
		foreach (['trend_avg', 'trend_min', 'trend_max'] as $k) {
			$m[$k] = $flag($k);
		}
		$win = $str('window');
		if ($win === '') {
			$win = '0';
		}
		if (!preg_match('/^\d+$/', $win) || (int) $win > 365) {
			$e[] = _('Own trend window must be 1 to 365 days.');
			$win = '0';
		}
		$m['window'] = (int) $win;
		$m['worse'] = $int('worse', [self::WORSE_HIGHER, self::WORSE_LOWER]);

		// Thresholds: ElasticPro -> Settings for a usage percentage, or the metric's own.
		$m['use_settings'] = $builtin !== null && $builtin['settings'] ? $flag('use_settings') : 0;
		if ($m['use_settings']) {
			// Settings' thresholds say "usage this high is bad"; a lower-is-worse reading of them would be another rule.
			$m['worse'] = self::WORSE_HIGHER;
			foreach (['warn', 'high'] as $k) {
				$v = $str($k);
				if ($v !== '' && (!self::isNumber($v) || (float) $v <= 0 || (float) $v > 100)) {
					$e[] = _s('%1$s must be empty (ElasticPro → Settings) or above 0 and at most 100.', $k === 'warn' ? _('Warn') : _('High'));
					$v = '';
				}
				$m[$k] = $v === '' ? '' : self::num((float) $v);
			}
			if ($m['warn'] !== '' && $m['high'] !== '' && (float) $m['warn'] > (float) $m['high']) {
				$e[] = _('Warn must not be above High.');
			}
		}
		else {
			$rows = array_values(array_filter((array) ($in['thresholds'] ?? []), 'is_array'));
			if (count($rows) > self::MAX_THRESHOLDS) {
				$e[] = _s('At most %1$s thresholds.', self::MAX_THRESHOLDS);
				$rows = array_slice($rows, 0, self::MAX_THRESHOLDS);
			}
			$seen = [];
			foreach ($rows as $i => $t) {
				$v = is_scalar($t['value'] ?? null) ? trim((string) $t['value']) : '';
				$level = is_scalar($t['level'] ?? null) ? (int) $t['level'] : 0;
				$color = strtoupper(ltrim(is_scalar($t['color'] ?? null) ? trim((string) $t['color']) : '', '#'));
				if ($v === '' || !self::isNumber($v)) {
					$e[] = _s('Threshold %1$s needs a number between -1e12 and 1e12.', $i + 1);
					continue;
				}
				if (!in_array($level, [self::LEVEL_WARN, self::LEVEL_HIGH], true)) {
					$e[] = _s('Threshold %1$s: the level is Warn or High.', $i + 1);
					continue;
				}
				if ($color !== '' && !preg_match('/^[0-9A-F]{6}$/', $color)) {
					$e[] = _s('Threshold %1$s: the colour must be six hex digits.', $i + 1);
					continue;
				}
				$v = self::num((float) $v);
				if (isset($seen[$v])) {
					$e[] = _s('Threshold %1$s repeats the value %2$s.', $i + 1, $v);
					continue;
				}
				$seen[$v] = true;
				$m['thresholds'][] = ['level' => $level, 'color' => $color, 'value' => $v];
			}
			usort($m['thresholds'], fn($a, $b) => (float) $a['value'] <=> (float) $b['value']);
		}

		$m['nodata'] = $int('nodata', [self::NODATA_SHOW, self::NODATA_HIDE]);
		$stale = $str('stale');
		if ($stale !== '' && (!preg_match('/^\d+$/', $stale) || (int) $stale < 1 || (int) $stale > 10080)) {
			$e[] = _('Stale after must be empty (the widget\'s setting) or 1 to 10080 minutes.');
			$stale = '';
		}
		$m['stale'] = $stale === '' ? '' : (string) (int) $stale;
		$m['click'] = $int('click', [self::CLICK_GRAPH, self::CLICK_LATEST, self::CLICK_NONE]);

		return [$m, array_values(array_unique($e))];
	}

	/**
	 * Why an item key cannot be read, or null. A Zabbix key: a name of letters, digits, _ - .
	 * and an optional [parameters]; * may stand for anything only when the metric says the key
	 * is a pattern.
	 */
	public static function keyError(string $key, bool $pattern): ?string {
		if ($key === '') {
			return _('Item key cannot be empty.');
		}
		if (strlen($key) > self::MAX_KEY) {
			return _s('Item key is longer than %1$s characters.', self::MAX_KEY);
		}
		if (preg_match('/[\x00-\x1f\x7f]/', $key)) {
			return _('Item key contains control characters.');
		}
		if (!$pattern && strpos($key, '*') !== false) {
			return _('Item key has a * — tick "Key is a pattern" to use it as a wildcard.');
		}
		$name = $pattern ? '[0-9A-Za-z_.\-*]+' : '[0-9A-Za-z_.\-]+';
		if (!preg_match('/^'.$name.'(\[.*\])?$/s', $key)) {
			return _s('"%1$s" is not an item key: letters, digits, _ - . then optional [parameters].', $key);
		}
		if ($pattern) {
			$star = strpos($key, '*');
			if ($star === false) {
				return _('A pattern needs a * — or untick "Key is a pattern".');
			}
			if ($star < self::PATTERN_PREFIX) {
				return _s('A pattern must start with at least %1$s plain characters before its first *, so it cannot match every item.',
					self::PATTERN_PREFIX);
			}
		}
		return null;
	}

	/** A finite number of sane size: 1e999 is INF, and INF is not a threshold. */
	public static function isNumber($v): bool {
		return is_numeric($v) && is_finite((float) $v) && abs((float) $v) <= self::MAX_NUMBER;
	}

	/** Whether a key matches a metric's key: equal, or the pattern's * standing for anything. */
	public static function keyMatches(string $key, string $want, bool $pattern): bool {
		if (!$pattern) {
			return $key === $want;
		}
		$re = '/^'.str_replace('\*', '.*', preg_quote($want, '/')).'$/s';
		return (bool) preg_match($re, $key);
	}

	/** A number as text with no float noise: 80 not 80.0, 0.1 not 0.10000000000000001. */
	public static function num(float $v): string {
		$s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
		return $s === '-0' ? '0' : $s;
	}
}
