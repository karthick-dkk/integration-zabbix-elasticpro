<?php declare(strict_types = 0);

namespace Modules\EpTophosts\Lib;

/**
 * The column model every customisable ElasticPro widget shares, and the one rule that decides
 * whether a column as typed is acceptable.
 *
 * A column has a group and a name; a source (a built-in column the widget offers, any Zabbix item
 * key on the client's servers, or a figure calculated from two of those); the servers it reads
 * and how they combine into one value per client; which value (now, or the average, minimum,
 * maximum or change over a window); how it is written (units, decimals, size unit, short
 * numbers, what an unknown says); where a click goes; how it is shown (a value, a bar in one of
 * four styles, a coloured cell or text, indicators, a sparkline or a badge) and coloured
 * (thresholds — ElasticPro -> Settings' or its own — and colours that otherwise follow the
 * palette).
 *
 * clean() is used by the column editor when Update is pressed, by the widget's Columns field when
 * the form is applied (strict: every error, each naming its column) and when a saved widget is
 * drawn (lenient: a column that no longer passes is left out, and the widget says so). Three
 * readers, one rule. Pure — no Zabbix API — so the tests can hold all of it.
 *
 * A catalogue entry (what a widget offers as built-in columns) has at least: label, group, on
 * (ON_MASTER, ON_SERVERS, ON_CLIENT or ON_ROW), units, text (true: not a number) and settings
 * (true: a usage percentage, the only kind ElasticPro -> Settings' thresholds are written for).
 * Optionally now (true: the figure is only ever the value now — the widget decides any window
 * itself, as Top hosts' ranked value does).
 *
 * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.
 */
class Columns {

	/**
	 * Columns a widget may have. Generous on purpose: every family shown as its roles brings
	 * eleven columns a role, and a widget's own list keeps the report's columns it hides, so a
	 * site with a dozen roles split is well past a hundred before anyone adds one.
	 */
	public const MAX = 400;
	public const MAX_SERVER = 24;
	public const MAX_THRESHOLDS = 10;
	public const MAX_NAME = 64;
	public const MAX_GROUP = 64;
	public const MAX_KEY = 255;
	public const MAX_UNITS = 16;
	public const MAX_WINDOW = 365;
	public const MAX_STALE = 10080;

	/** Largest magnitude a typed number may have: beyond it, it is a typo (or 1e999), not a threshold. */
	public const MAX_NUMBER = 1e12;

	/** A literal start a key pattern needs before its first *, so it cannot match every item there is. */
	public const PATTERN_PREFIX = 3;

	/** How many items a pattern may bring in, per host and per column for one client, before it is capped. */
	public const PATTERN_PER_HOST = 50;
	public const PATTERN_PER_METRIC = 500;

	public const SOURCE_BUILTIN = 1;
	public const SOURCE_ITEM = 2;
	public const SOURCE_CALC = 3;

	public const COMBINE_SUM = 1;
	public const COMBINE_AVG = 2;
	public const COMBINE_MAX = 3;
	public const COMBINE_MIN = 4;
	public const COMBINE_COUNT = 5;

	public const VALUE_NOW = 1;
	public const VALUE_AVG = 2;
	public const VALUE_MIN = 3;
	public const VALUE_MAX = 4;
	public const VALUE_CHANGE = 5;

	/** Calculated: a of b (with its percentage), a minus b, a plus b, a divided by b. */
	public const OP_OF = 1;
	public const OP_MINUS = 2;
	public const OP_PLUS = 3;
	public const OP_DIV = 4;

	public const SIZE_AUTO = 0;
	public const SIZE_GB = 1;
	public const SIZE_TB = 2;
	public const SIZE_MB = 3;

	public const NODATA_WHY = 1;
	public const NODATA_DASH = 2;

	public const CLICK_GRAPH = 1;
	public const CLICK_LATEST = 2;
	public const CLICK_NONE = 3;
	public const CLICK_DASHBOARD = 4;

	/** Numbered so the first four mean what the Client overview's Display has always meant. */
	public const SHOW_VALUE = 1;
	public const SHOW_BAR = 2;
	public const SHOW_INDICATORS = 3;
	public const SHOW_SPARKLINE = 4;
	public const SHOW_CELL = 5;
	public const SHOW_TEXT = 6;
	public const SHOW_BADGE = 7;

	/** 0: whatever the widget's own Display says (which may follow ElasticPro -> Settings). */
	public const BAR_WIDGET = 0;
	public const BAR_UNDER = 1;
	public const BAR_CELL = 2;
	public const BAR_SEGMENTS = 3;
	public const BAR_GAUGE = 4;

	public const BARCOLOR_LEVEL = 1;
	public const BARCOLOR_FIXED = 2;

	public const ALIGN_AUTO = 0;
	public const ALIGN_LEFT = 1;
	public const ALIGN_CENTRE = 2;
	public const ALIGN_RIGHT = 3;

	public const WORSE_HIGHER = 1;
	public const WORSE_LOWER = 2;

	public const LEVEL_WARN = 1;
	public const LEVEL_HIGH = 2;

	/** Where a built-in column's figure lives. */
	public const ON_MASTER = 'master';
	public const ON_SERVERS = 'servers';
	public const ON_CLIENT = 'client';
	public const ON_ROW = 'row';

	/** The prefix that makes a calculated column's operand an item key rather than a built-in column. */
	public const KEY_OPERAND = 'key:';

	/** A column as the editor opens it. Every saved column is this with its own values on top. */
	public const DEFAULT = [
		'uid' => '', 'group' => '', 'name' => '', 'hidden' => 0,
		'source' => self::SOURCE_BUILTIN, 'builtin' => '', 'key' => '', 'pattern' => 0,
		'calc_a' => '', 'calc_op' => self::OP_OF, 'calc_b' => '',
		'roles' => [], 'combine' => self::COMBINE_AVG,
		'value' => self::VALUE_NOW, 'window' => 0,
		'units' => '', 'decimals' => '', 'size_unit' => self::SIZE_AUTO, 'short' => 0,
		'nodata' => self::NODATA_WHY, 'stale' => '', 'click' => self::CLICK_GRAPH,
		'show' => self::SHOW_VALUE, 'bar_style' => self::BAR_WIDGET, 'segments' => 12,
		'bar_color' => self::BARCOLOR_LEVEL, 'bar_fixed' => '', 'track' => '',
		'min' => '', 'max' => '', 'show_value' => 1, 'show_pct' => 1,
		'align' => self::ALIGN_AUTO, 'width' => '', 'wrap' => 0,
		'worse' => self::WORSE_HIGHER, 'use_settings' => 1, 'warn' => '', 'high' => '',
		'warn_color' => '', 'high_color' => '', 'thresholds' => []
	];

	/** What each property is stored as in Zabbix, so an integer stays an integer. */
	public const INT_FIELDS = ['hidden', 'source', 'pattern', 'calc_op', 'combine', 'value', 'window', 'size_unit', 'short', 'nodata', 'click',
		'show', 'bar_style', 'segments', 'bar_color', 'show_value', 'show_pct', 'align', 'wrap', 'worse', 'use_settings'];

	public static function combineLabels(): array {
		return [
			self::COMBINE_SUM => _('Sum'),
			self::COMBINE_AVG => _('Average'),
			self::COMBINE_MAX => _('Highest'),
			self::COMBINE_MIN => _('Lowest'),
			self::COMBINE_COUNT => _('Count over Warn')
		];
	}

	public static function valueLabels(): array {
		return [
			self::VALUE_NOW => _('Now (last value)'),
			self::VALUE_AVG => _('Average'),
			self::VALUE_MIN => _('Minimum'),
			self::VALUE_MAX => _('Maximum'),
			self::VALUE_CHANGE => _('Change')
		];
	}

	/**
	 * The figure a share is a share of, as the client master template computes it
	 * (MasterTemplate: 100*last(//used)/last(//allocated)): ES storage usage of ES storage allocated,
	 * storage used of purchased of purchased, devices seen of purchased of purchased, a family's or a
	 * role's disk usage of its disk allocated. Null for anything else — CPU and memory usage are
	 * averages over the servers, not shares of what was allocated. Lets a share nobody can work out
	 * say which figure is missing ("ES storage allocated not set"), not "no value yet".
	 */
	public static function shareOf(string $key): ?string {
		$fixed = ['ep.es.storage.usage' => 'ep.es.storage.allocated', 'ep.es.storage.purchased.usage' => 'ep.es.storage.purchased',
			'ep.devices.usage' => 'ep.devices.purchased'];
		if (isset($fixed[$key])) {
			return $fixed[$key];
		}
		if (preg_match('/^ep\.role\.disk\.usage\[([^\]]+)\]$/', $key, $m)) {
			return 'ep.role.disk.allocated['.$m[1].']';
		}
		if (preg_match('/^ep\.([a-z0-9_]+)\.disk\.usage$/', $key, $m) && $m[1] !== 'role') {
			return 'ep.'.$m[1].'.disk.allocated';
		}

		return null;
	}

	public static function opLabels(): array {
		return [
			self::OP_OF => _('of (x of y, %)'),
			self::OP_MINUS => _('minus'),
			self::OP_PLUS => _('plus'),
			self::OP_DIV => _('divided by')
		];
	}

	public static function showLabels(): array {
		return [
			self::SHOW_VALUE => _('Value'),
			self::SHOW_BAR => _('Bar'),
			self::SHOW_CELL => _('Coloured cell'),
			self::SHOW_TEXT => _('Coloured text'),
			self::SHOW_INDICATORS => _('Indicators'),
			self::SHOW_SPARKLINE => _('Sparkline'),
			self::SHOW_BADGE => _('Badge')
		];
	}

	public static function clickLabels(): array {
		return [
			self::CLICK_GRAPH => _('Graph of the item'),
			self::CLICK_LATEST => _('Latest data'),
			self::CLICK_DASHBOARD => _('Client dashboard'),
			self::CLICK_NONE => _('Nothing')
		];
	}

	public static function sizeLabels(): array {
		return [self::SIZE_AUTO => _('Auto (GB / TB)'), self::SIZE_MB => 'MB', self::SIZE_GB => 'GB', self::SIZE_TB => 'TB'];
	}

	/** A fresh id for a column, so the sort order and the list can name it whatever position it moves to. */
	public static function newUid(): string {
		return substr(bin2hex(random_bytes(6)), 0, 10);
	}

	/**
	 * A column as it came in (form, editor or a saved widget: strings or numbers), made into the
	 * stored shape — or the reasons it cannot be.
	 *
	 * @param array  $catalogue  the built-in columns the widget offers, by id
	 * @param array  $roleIds    the role ids Cluster Management has; others are dropped from Servers
	 * @param string $scope      'client' for a client row's columns, 'row' for a server row's
	 *
	 * @return array{0: array, 1: string[]}  [clean column, errors]
	 */
	public static function clean(array $in, array $catalogue, array $roleIds, string $scope = 'client'): array {
		$e = [];
		$c = self::DEFAULT;
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
		$text = function (string $k, int $max, string $label, bool $required) use ($str, &$e): string {
			$v = $str($k);
			if ($v === '' && $required) {
				$e[] = _s('%1$s cannot be empty.', $label);
			}
			elseif (mb_strlen($v) > $max) {
				$e[] = _s('%1$s is longer than %2$s characters.', $label, $max);
				$v = mb_substr($v, 0, $max);
			}
			elseif (preg_match('/[\x00-\x1f\x7f]/', $v)) {
				$e[] = _s('%1$s contains control characters.', $label);
				$v = preg_replace('/[\x00-\x1f\x7f]/', '', $v);
			}
			return $v;
		};

		$uid = $str('uid');
		$c['uid'] = preg_match('/^[a-z0-9]{1,16}$/', $uid) ? $uid : self::newUid();
		$c['group'] = $text('group', self::MAX_GROUP, _('Group'), false);
		// Required for an item or a calculated column; a built-in left unnamed is named by its label (below).
		$c['name'] = $text('name', self::MAX_NAME, _('Name'), false);
		$c['hidden'] = $flag('hidden');

		$row = $scope === 'row';
		$c['source'] = $int('source', $row ? [self::SOURCE_BUILTIN] : [self::SOURCE_BUILTIN, self::SOURCE_ITEM, self::SOURCE_CALC]);
		$builtin = null;
		$nowOnly = false;
		$onServers = false;
		$numeric = true;
		$percent = false;
		if ($c['source'] === self::SOURCE_BUILTIN) {
			$c['builtin'] = $str('builtin');
			$builtin = $catalogue[$c['builtin']] ?? null;
			if ($builtin !== null && (($builtin['on'] === self::ON_ROW) !== $row)) {
				$builtin = null;
			}
			if ($builtin === null) {
				$e[] = $c['builtin'] === '' ? _('Choose a built-in column.') : _s('There is no built-in column "%1$s".', $c['builtin']);
			}
			else {
				if ($c['name'] === '') {
					$c['name'] = (string) $builtin['label'];
				}
				$c['units'] = (string) ($builtin['units'] ?? '');
				$onServers = $builtin['on'] === self::ON_SERVERS;
				$numeric = empty($builtin['text']);
				$nowOnly = !empty($builtin['now']);
				$percent = !empty($builtin['settings']) || !empty($builtin['pct']);
			}
		}
		elseif ($c['name'] === '') {
			$e[] = _s('%1$s cannot be empty.', _('Name'));
		}
		if ($c['source'] === self::SOURCE_ITEM) {
			$c['pattern'] = $flag('pattern');
			$c['key'] = $str('key');
			$keyError = self::keyError($c['key'], (bool) $c['pattern']);
			if ($keyError !== null) {
				$e[] = $keyError;
			}
			$onServers = true;
		}
		elseif ($c['source'] === self::SOURCE_CALC) {
			$c['calc_op'] = $int('calc_op', array_keys(self::opLabels()));
			foreach (['calc_a' => _('First item'), 'calc_b' => _('Second item')] as $k => $label) {
				$c[$k] = $str($k);
				[$err, $servers] = self::operandError($c[$k], $label, $catalogue);
				if ($err !== null) {
					$e[] = $err;
				}
				$onServers = $onServers || $servers;
			}
			$percent = $c['calc_op'] === self::OP_OF;
		}

		// Servers: only meaningful for a figure read off the servers; a master-host figure is one per client.
		$roles = [];
		$unknown = [];
		foreach ((array) ($in['roles'] ?? []) as $r) {
			$r = is_scalar($r) ? trim((string) $r) : '';
			if ($r === '' || in_array($r, $roles, true)) {
				continue;
			}
			if (in_array($r, $roleIds, true)) {
				$roles[] = $r;
			}
			else {
				$unknown[] = $r;
			}
		}
		// A role that is gone is not "every server": a column scoped to it would quietly read them all.
		if ($onServers && $unknown) {
			$e[] = _s('Servers: the role "%1$s" is not in Cluster Management any more — choose its servers again.', implode('", "', $unknown));
		}
		$c['roles'] = $onServers ? $roles : [];
		$c['combine'] = $int('combine', array_keys(self::combineLabels()));

		$c['value'] = $int('value', array_keys(self::valueLabels()));
		if ((!$numeric || $row || $nowOnly) && $c['value'] !== self::VALUE_NOW) {
			$e[] = $row ? _('A server row column shows its value now.') : ($nowOnly
				? _('This column is the widget\'s own figure; it shows its value now.')
				: _('A text column shows its value now; average, minimum, maximum and change are for numbers.'));
			$c['value'] = self::VALUE_NOW;
		}
		$win = $str('window');
		if ($win === '') {
			$win = '0';
		}
		if (!preg_match('/^\d+$/', $win) || (int) $win > self::MAX_WINDOW) {
			$e[] = _s('Days must be empty (the widget\'s trend window) or 1 to %1$s.', self::MAX_WINDOW);
			$win = '0';
		}
		$c['window'] = (int) $win;

		if ($c['source'] !== self::SOURCE_BUILTIN) {
			$c['units'] = $str('units');
			if (mb_strlen($c['units']) > self::MAX_UNITS || preg_match('/[\x00-\x1f\x7f<>]/', $c['units'])) {
				$e[] = _s('Units must be at most %1$s characters, without < or >.', self::MAX_UNITS);
				$c['units'] = '';
			}
			if ($c['source'] === self::SOURCE_CALC && $c['calc_op'] === self::OP_OF) {
				$percent = true;
			}
			elseif ($c['units'] === '%') {
				$percent = true;
			}
		}
		$dec = $str('decimals');
		if ($dec !== '' && (!preg_match('/^\d+$/', $dec) || (int) $dec > 10)) {
			$e[] = _('Decimals must be empty (ElasticPro → Settings) or 0 to 10.');
			$dec = '';
		}
		$c['decimals'] = $dec === '' ? '' : (string) (int) $dec;
		$c['size_unit'] = $int('size_unit', array_keys(self::sizeLabels()));
		$c['short'] = $flag('short');
		$c['nodata'] = $int('nodata', [self::NODATA_WHY, self::NODATA_DASH]);
		$stale = $str('stale');
		if ($stale !== '' && (!preg_match('/^\d+$/', $stale) || (int) $stale < 1 || (int) $stale > self::MAX_STALE)) {
			$e[] = _s('Stale after must be empty or 1 to %1$s minutes.', self::MAX_STALE);
			$stale = '';
		}
		$c['stale'] = $stale === '' ? '' : (string) (int) $stale;
		$c['click'] = $int('click', array_keys(self::clickLabels()));

		// Display.
		$c['show'] = $int('show', array_keys(self::showLabels()));
		if (!$numeric && !in_array($c['show'], [self::SHOW_VALUE, self::SHOW_BADGE], true)) {
			$e[] = _('A text column is shown as a value or a badge.');
			$c['show'] = self::SHOW_VALUE;
		}
		$c['bar_style'] = $int('bar_style', [self::BAR_WIDGET, self::BAR_UNDER, self::BAR_CELL, self::BAR_SEGMENTS, self::BAR_GAUGE]);
		$seg = $str('segments');
		if ($seg === '') {
			$seg = (string) self::DEFAULT['segments'];
		}
		if (!preg_match('/^\d+$/', $seg) || (int) $seg < 2 || (int) $seg > 50) {
			$e[] = _('Segments must be 2 to 50.');
			$seg = (string) self::DEFAULT['segments'];
		}
		$c['segments'] = (int) $seg;
		$c['bar_color'] = $int('bar_color', [self::BARCOLOR_LEVEL, self::BARCOLOR_FIXED]);
		foreach (['bar_fixed' => _('Fixed bar colour'), 'track' => _('Track colour'), 'warn_color' => _('Warn colour'), 'high_color' => _('High colour')] as $k => $label) {
			[$c[$k], $err] = self::colorOrError($str($k), $label);
			if ($err !== null) {
				$e[] = $err;
			}
		}
		if ($c['bar_color'] === self::BARCOLOR_FIXED && $c['bar_fixed'] === '') {
			$e[] = _('A fixed bar colour needs a colour.');
		}
		foreach (['min' => _('Bar range from'), 'max' => _('Bar range to')] as $k => $label) {
			$v = $str($k);
			if ($v !== '' && !self::isNumber($v)) {
				$e[] = _s('%1$s must be a number between -1e12 and 1e12, or empty.', $label);
				$v = '';
			}
			$c[$k] = $v === '' ? '' : self::num((float) $v);
		}
		if ($c['min'] !== '' && $c['max'] !== '' && (float) $c['min'] >= (float) $c['max']) {
			$e[] = _('The bar range must go from a lower number to a higher one.');
		}
		$c['show_value'] = $flag('show_value');
		$c['show_pct'] = $flag('show_pct');
		$c['align'] = $int('align', [self::ALIGN_AUTO, self::ALIGN_LEFT, self::ALIGN_CENTRE, self::ALIGN_RIGHT]);
		$width = strtolower($str('width'));
		if ($width === 'auto') {
			$width = '';
		}
		if ($width !== '' && (!preg_match('/^\d+$/', $width) || (int) $width < 30 || (int) $width > 800)) {
			$e[] = _('Width must be auto or 30 to 800 pixels.');
			$width = '';
		}
		$c['width'] = $width;
		$c['wrap'] = $flag('wrap');

		// Thresholds: ElasticPro -> Settings for a usage percentage, or the column's own.
		$c['worse'] = $int('worse', [self::WORSE_HIGHER, self::WORSE_LOWER]);
		$c['use_settings'] = $numeric && $percent ? $flag('use_settings') : 0;
		$th = self::cleanThresholds($in, (bool) $c['use_settings'], $c['worse'], true);
		$e = array_merge($e, $th['errors']);
		foreach (['worse', 'warn', 'high', 'thresholds'] as $k) {
			$c[$k] = $th[$k];
		}
		if (!$numeric) {
			$c['thresholds'] = [];
		}

		return [$c, array_values(array_unique($e))];
	}

	/**
	 * Thresholds, the part of a column (and of a Client overview metric) that says when a value is
	 * Warn and when High.
	 *
	 * With ElasticPro -> Settings: Warn and High may be left empty (the Settings figure applies)
	 * or typed, above 0 and at most 100, Warn not above High, and the direction is always
	 * "higher is worse" — Settings' thresholds say "usage this high is bad". Otherwise a list of
	 * levels, each a number, Warn or High and an optional colour, in the column's direction.
	 *
	 * @param bool $hash  colours as #RRGGBB (columns) or RRGGBB (the Client overview's metrics)
	 *
	 * @return array{worse: int, warn: string, high: string, thresholds: array, errors: string[]}
	 */
	public static function cleanThresholds(array $in, bool $useSettings, int $worse, bool $hash): array {
		$str = fn($k) => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';
		$out = ['worse' => $worse, 'warn' => '', 'high' => '', 'thresholds' => [], 'errors' => []];
		if ($useSettings) {
			$out['worse'] = self::WORSE_HIGHER;
			foreach (['warn', 'high'] as $k) {
				$v = $str($k);
				if ($v !== '' && (!self::isNumber($v) || (float) $v <= 0 || (float) $v > 100)) {
					$out['errors'][] = _s('%1$s must be empty (ElasticPro → Settings) or above 0 and at most 100.', $k === 'warn' ? _('Warn') : _('High'));
					$v = '';
				}
				$out[$k] = $v === '' ? '' : self::num((float) $v);
			}
			if ($out['warn'] !== '' && $out['high'] !== '' && (float) $out['warn'] > (float) $out['high']) {
				$out['errors'][] = _('Warn must not be above High.');
			}
			return $out;
		}
		$rows = array_values(array_filter((array) ($in['thresholds'] ?? []), 'is_array'));
		if (count($rows) > self::MAX_THRESHOLDS) {
			$out['errors'][] = _s('At most %1$s thresholds.', self::MAX_THRESHOLDS);
			$rows = array_slice($rows, 0, self::MAX_THRESHOLDS);
		}
		$seen = [];
		foreach ($rows as $i => $t) {
			$v = is_scalar($t['value'] ?? null) ? trim((string) $t['value']) : '';
			$level = is_scalar($t['level'] ?? null) ? (int) $t['level'] : 0;
			$color = strtoupper(ltrim(is_scalar($t['color'] ?? null) ? trim((string) $t['color']) : '', '#'));
			if ($v === '' || !self::isNumber($v)) {
				$out['errors'][] = _s('Threshold %1$s needs a number between -1e12 and 1e12.', $i + 1);
				continue;
			}
			if (!in_array($level, [self::LEVEL_WARN, self::LEVEL_HIGH], true)) {
				$out['errors'][] = _s('Threshold %1$s: the level is Warn or High.', $i + 1);
				continue;
			}
			if ($color !== '' && !preg_match('/^[0-9A-F]{6}$/', $color)) {
				$out['errors'][] = _s('Threshold %1$s: the colour must be six hex digits.', $i + 1);
				continue;
			}
			$v = self::num((float) $v);
			if (isset($seen[$v])) {
				$out['errors'][] = _s('Threshold %1$s repeats the value %2$s.', $i + 1, $v);
				continue;
			}
			$seen[$v] = true;
			$out['thresholds'][] = ['level' => $level, 'color' => $color !== '' && $hash ? '#'.$color : $color, 'value' => $v];
		}
		usort($out['thresholds'], fn($a, $b) => (float) $a['value'] <=> (float) $b['value']);
		$out['errors'] = array_merge($out['errors'], self::orderErrors($out['thresholds'], $worse));
		return $out;
	}

	/**
	 * Warn and High on the right sides of each other: worse when higher, every Warn below every
	 * High; worse when lower, every Warn above every High. Otherwise Warn would never show, or
	 * High would colour values that are better than Warn.
	 */
	public static function orderErrors(array $thresholds, int $worse): array {
		$warn = array_map(fn($t) => (float) $t['value'], array_filter($thresholds, fn($t) => (int) $t['level'] === self::LEVEL_WARN));
		$high = array_map(fn($t) => (float) $t['value'], array_filter($thresholds, fn($t) => (int) $t['level'] === self::LEVEL_HIGH));
		if (!$warn || !$high) {
			return [];
		}
		if ($worse === self::WORSE_LOWER) {
			return min($warn) > max($high) ? [] : [_s('Warn (%1$s) must be above High (%2$s) when lower is worse.', self::num(min($warn)), self::num(max($high)))];
		}
		return max($warn) < min($high) ? [] : [_s('Warn (%1$s) must be below High (%2$s) when higher is worse.', self::num(max($warn)), self::num(min($high)))];
	}

	/**
	 * A colour as stored: '' (follow the palette) or #RRGGBB, upper case. Six hex digits only —
	 * no names, no short form, no rgba() — because the value goes into a style attribute.
	 *
	 * @return array{0: string, 1: ?string}  [colour, error]
	 */
	public static function colorOrError(string $v, string $label): array {
		$v = trim($v);
		if ($v === '') {
			return ['', null];
		}
		$hex = strtoupper(ltrim($v, '#'));
		if (!preg_match('/^[0-9A-F]{6}$/', $hex) || substr_count($v, '#') > 1) {
			return ['', _s('%1$s must be a colour like #1F62E0.', $label)];
		}
		return ['#'.$hex, null];
	}

	/** A colour that is safe to put in a style attribute, or null. */
	public static function color($v): ?string {
		[$c, $err] = self::colorOrError(is_scalar($v) ? (string) $v : '', '');
		return $err === null && $c !== '' ? $c : null;
	}

	/**
	 * What is wrong with a calculated column's operand, and whether it is read off the servers.
	 * An operand is a numeric built-in column, or "key:" and an item key on the client's servers.
	 *
	 * @return array{0: ?string, 1: bool}
	 */
	public static function operandError(string $operand, string $label, array $catalogue): array {
		if ($operand === '') {
			return [_s('%1$s: choose a built-in column or type key: and an item key.', $label), false];
		}
		if (strpos($operand, self::KEY_OPERAND) === 0) {
			$err = self::keyError(substr($operand, strlen(self::KEY_OPERAND)), false);
			return [$err === null ? null : $label.': '.$err, true];
		}
		$b = $catalogue[$operand] ?? null;
		if ($b === null || !empty($b['text']) || !in_array($b['on'], [self::ON_MASTER, self::ON_SERVERS], true)) {
			return [_s('%1$s: there is no built-in number "%2$s".', $label, $operand), false];
		}
		return [null, $b['on'] === self::ON_SERVERS];
	}

	/**
	 * A list of columns as stored, and what is wrong with it. Not strict: what fails is dropped
	 * (and named in $dropped) and the rest kept. Strict: every failure is reported, each naming
	 * its column. A uid repeated is given a fresh one.
	 *
	 * @return array{0: array, 1: string[], 2: string[]}  [columns, errors, dropped]
	 */
	public static function cleanList(array $columns, array $catalogue, array $roleIds, bool $strict, string $scope = 'client'): array {
		$errors = [];
		$dropped = [];
		$out = [];
		$uids = [];
		$max = $scope === 'row' ? self::MAX_SERVER : self::MAX;
		$columns = array_values(array_filter($columns, 'is_array'));
		if (count($columns) > $max) {
			if ($strict) {
				$errors[] = _s('Invalid parameter "%1$s": %2$s.', _('Columns'), _s('at most %1$s columns', $max));
			}
			// Never silently: every column past the cap is named among the dropped.
			foreach (array_slice($columns, $max) as $i => $col) {
				$dropped[] = self::named([_s('more than %1$s columns — left out.', $max)], is_scalar($col['name'] ?? null) ? (string) $col['name'] : '',
					$max + $i + 1, is_scalar($col['group'] ?? null) ? (string) $col['group'] : '')[0];
			}
			$columns = array_slice($columns, 0, $max);
		}
		foreach ($columns as $i => $col) {
			[$clean, $e] = self::clean($col, $catalogue, $roleIds, $scope);
			if ($e) {
				$named = self::named($e, is_scalar($col['name'] ?? null) ? (string) $col['name'] : '', $i + 1, (string) ($col['group'] ?? ''));
				if ($strict) {
					$errors = array_merge($errors, $named);
				}
				else {
					$dropped[] = $named[0];
				}
				continue;
			}
			while (in_array($clean['uid'], $uids, true)) {
				$clean['uid'] = self::newUid();
			}
			$uids[] = $clean['uid'];
			$out[] = $clean;
		}
		return [$out, $errors, $dropped];
	}

	/**
	 * A column's errors as the editor and the form show them: each one naming the column — its
	 * group and name — so a message is never just "Threshold 1 needs a number." with forty
	 * columns to look through.
	 */
	public static function named(array $errors, string $name, int $position = 0, string $group = ''): array {
		$label = trim($name) !== '' ? trim($name) : ($position > 0 ? '#'.$position : _('New column'));
		if (trim($group) !== '' && trim($name) !== '') {
			$label = trim($group).' · '.$label;
		}
		return array_map(fn($one) => _s('Column "%1$s": %2$s', $label, $one), $errors);
	}

	/**
	 * Columns of one group kept together: a group's columns move to where its first column is, in
	 * the order they were in. Dragging a column out of its group and back is then harmless, and
	 * the group header is never split in two.
	 */
	public static function groupTogether(array $columns): array {
		$order = [];
		$byGroup = [];
		foreach ($columns as $c) {
			$g = (string) ($c['group'] ?? '');
			$key = $g === '' ? "\0".($c['uid'] ?? count($order)) : $g;
			if (!isset($byGroup[$key])) {
				$order[] = $key;
			}
			$byGroup[$key][] = $c;
		}
		$out = [];
		foreach ($order as $key) {
			foreach ($byGroup[$key] as $c) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * Why an item key cannot be read, or null. A Zabbix key: a name of letters, digits, _ - .
	 * and an optional [parameters]; * may stand for anything only when the column says the key
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

	/** Whether a key matches a column's key: equal, or the pattern's * standing for anything. */
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
