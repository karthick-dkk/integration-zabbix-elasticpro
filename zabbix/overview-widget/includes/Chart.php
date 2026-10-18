<?php declare(strict_types = 0);

namespace Modules\EpOverview\Includes;

use Modules\EpOverview\Lib\ColumnMath;

/**
 * The Summary view's usage chart, apart from Zabbix so the tests can hold it: which metric and
 * period it draws, how each client's servers become one line (Overview's own combine, the one the
 * Cards use), the Mean / Min / Max / Last of a line, which clients are drawn and in what order,
 * and where the Warn and High lines go. WidgetView reads Zabbix and hands the figures here.
 *
 * Unknown is not zero: a client with no point in the period has no stats at all (null), never
 * zeros, and is drawn with no line and "no data" in the legend.
 */
class Chart {

	/** CPU and memory of the family the Summary shows, and ES storage used (a percentage). */
	public const BUILTIN = ['cpu', 'mem', 'storage'];

	/** The quick period buttons, in seconds. */
	public const QUICK = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];

	/** Periods up to this long are read from history, longer ones from (hourly) trends. */
	public const HISTORY_UNDER = 21600;

	/** History is combined into at most this many points across the period. */
	public const POINTS = 90;

	public const DEFAULT_SECONDS = 21600;
	public const MIN_SECONDS = 3600;
	public const MAX_SECONDS = 31536000;

	public const PERIOD_DASHBOARD = 0;
	public const PERIOD_LAST = 1;

	public const UNIT_HOURS = 0;
	public const UNIT_DAYS = 1;

	public const CLIENTS_ALL = 0;
	public const CLIENTS_TOP = 1;

	/** What "top N" and the legend sort rank by. */
	public const BY_MAX = 0;
	public const BY_MEAN = 1;
	public const BY_LAST = 2;

	public const SORT_MEAN = 0;
	public const SORT_MAX = 1;
	public const SORT_LAST = 2;
	public const SORT_NAME = 3;

	public const STYLE_LINE = 0;
	public const STYLE_AREA = 1;
	public const STYLE_STACKED = 2;

	/** Which of an hour's (or a bucket's) average, maximum and minimum each point draws. */
	public const POINT_AVG = 0;
	public const POINT_MAX = 1;
	public const POINT_MIN = 2;

	public const HEIGHT_S = 0;
	public const HEIGHT_M = 1;
	public const HEIGHT_L = 2;

	public const Y_PERCENT = 0;
	public const Y_AUTO = 1;

	/** The legend's columns, in the order they are drawn. */
	public const LEGEND = ['mean', 'min', 'max', 'last'];

	/** Whether $id names a chart metric: one of BUILTIN or a metric of the widget's list (m:<uid>). */
	public static function isMetricId(string $id): bool {
		return preg_match('/^(cpu|mem|storage|m:[a-z0-9]{1,16})\z/', $id) === 1;
	}

	/** Every id the chart may draw, for a widget whose Metrics list is $metrics. */
	public static function metricIds(array $metrics): array {
		$out = self::BUILTIN;
		foreach ($metrics as $m) {
			if (is_array($m) && isset($m['uid']) && preg_match('/^[a-z0-9]{1,16}\z/', (string) $m['uid'])) {
				$out[] = 'm:'.$m['uid'];
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * The metric to draw: the viewer's choice when the widget lets viewers switch and the choice
	 * is one it offers, else the configured one when it still exists, else CPU.
	 */
	public static function pickMetric(string $configured, string $viewer, bool $switch, array $allowed): string {
		if ($switch && $viewer !== '' && in_array($viewer, $allowed, true)) {
			return $viewer;
		}
		return in_array($configured, $allowed, true) ? $configured : 'cpu';
	}

	/**
	 * The period drawn: [from, till, seconds, key]. A viewer's quick button wins when the widget
	 * shows them; then the dashboard's time period if the widget follows it; then the last N
	 * hours or days. Never shorter than an hour or longer than a year. `key` is the quick button
	 * the period equals (1h, 6h, 24h, 7d) or "custom".
	 *
	 * @param array $f       the widget's fields
	 * @param int   $viewer  the quick period the viewer pressed, in seconds (0: none)
	 */
	public static function window(array $f, int $viewer, int $now): array {
		$key = fn(int $s) => array_search($s, self::QUICK, true) ?: 'custom';
		if ((int) ($f['chart_quick'] ?? 1) === 1 && in_array($viewer, self::QUICK, true)) {
			return [$now - $viewer, $now, $viewer, $key($viewer)];
		}
		$dash = $f['time_period'] ?? null;
		if ((int) ($f['chart_period_source'] ?? self::PERIOD_LAST) === self::PERIOD_DASHBOARD
				&& isset($dash['from_ts'], $dash['to_ts']) && (int) $dash['to_ts'] > (int) $dash['from_ts']) {
			$till = min((int) $dash['to_ts'], $now);
			$from = max((int) $dash['from_ts'], $till - self::MAX_SECONDS);
			if ($till - $from >= 60) {
				return [$from, $till, $till - $from, 'custom'];
			}
		}
		$n = max(1, (int) ($f['chart_period_n'] ?? 6));
		$seconds = $n * ((int) ($f['chart_period_unit'] ?? self::UNIT_HOURS) === self::UNIT_DAYS ? 86400 : 3600);
		$seconds = max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds));
		return [$now - $seconds, $now, $seconds, $key($seconds)];
	}

	/** Short periods come from history, long ones from hourly trends. */
	public static function usesHistory(int $seconds): bool {
		return $seconds <= self::HISTORY_UNDER;
	}

	/** The width of one history point, in whole minutes: the period over POINTS, never under a minute. */
	public static function bucketSeconds(int $seconds): int {
		return max(60, (int) (ceil($seconds / self::POINTS / 60) * 60));
	}

	/** Which column of a [avg, min, max] point a setting draws. */
	public static function pointColumn(int $point): int {
		return $point === self::POINT_MAX ? 2 : ($point === self::POINT_MIN ? 1 : 0);
	}

	/**
	 * One client's line: its servers' items combined as the Cards combine a metric — each host's
	 * items made one series (the worst item stands for the host), then the hosts combined with the
	 * metric's function, point by point (the shared column engine's ColumnMath::hostSeries
	 * and ColumnMath::trend — the ones Overview's cards use).
	 *
	 * @param array $byHost  hostid => [itemid => [clock => [avg, min, max]]]
	 *
	 * @return array  clock => value; empty: the client has no data in the period
	 */
	public static function line(array $byHost, bool $lower, int $fn, int $from, int $till, ?callable $over, int $point): array {
		$trend = ColumnMath::trend(ColumnMath::hostSeries($byHost, $lower), $fn, $from, $till, $over, self::pointColumn($point));
		return $trend === null ? [] : array_filter($trend['series'], fn($v) => $v !== null);
	}

	/**
	 * A line as the points sent to the browser: [clock, value], at most $max of them. A long
	 * period (a year of hourly trends) is cut into $max stretches, each drawn at its mean time
	 * with the stretch's mean, maximum or minimum as the chart's point asks — so a peak a chart
	 * of maxima is drawn for is not averaged away. The stats are taken from the whole line, not from these.
	 *
	 * @return array  list of [int clock, float value]
	 */
	public static function thin(array $line, int $point, int $max = 240): array {
		ksort($line);
		$points = [];
		foreach ($line as $clock => $v) {
			$points[] = [(int) $clock, (float) $v];
		}
		$n = count($points);
		if ($n <= $max) {
			return array_map(fn($p) => [$p[0], round($p[1], 4)], $points);
		}
		$out = [];
		for ($i = 0; $i < $max; $i++) {
			$a = (int) floor($i * $n / $max);
			$b = max($a + 1, (int) floor(($i + 1) * $n / $max));
			$slice = array_slice($points, $a, $b - $a);
			$values = array_column($slice, 1);
			$v = $point === self::POINT_MAX ? max($values) : ($point === self::POINT_MIN ? min($values) : array_sum($values) / count($values));
			$out[] = [(int) round(array_sum(array_column($slice, 0)) / count($slice)), round($v, 4)];
		}
		return $out;
	}

	/**
	 * Mean, Min, Max and Last of a line, from the points drawn. null: no point, so no stats —
	 * never a mean of nothing as 0.
	 *
	 * @return array{mean: float, min: float, max: float, last: float}|null
	 */
	public static function stats(array $line): ?array {
		$line = array_filter($line, fn($v) => $v !== null);
		if (!$line) {
			return null;
		}
		ksort($line);
		$values = array_values(array_map('floatval', $line));
		return ['mean' => array_sum($values) / count($values), 'min' => min($values), 'max' => max($values), 'last' => end($values)];
	}

	/** The stats key a ranking names. */
	public static function rankKey(int $by): string {
		return [self::BY_MAX => 'max', self::BY_MEAN => 'mean', self::BY_LAST => 'last'][$by] ?? 'max';
	}

	/**
	 * Clients highest first by a stat, a client with no data last and ties by name.
	 *
	 * @param array $rows  each ['name' => string, 'stats' => ?array]
	 */
	public static function rank(array $rows, string $key): array {
		usort($rows, function ($a, $b) use ($key) {
			$x = $a['stats'][$key] ?? null;
			$y = $b['stats'][$key] ?? null;
			if ($x === null xor $y === null) {
				return $x === null ? 1 : -1;
			}
			if ($x !== null && $x != $y) {
				return $y <=> $x;
			}
			return strnatcasecmp($a['name'], $b['name']);
		});
		return $rows;
	}

	/**
	 * The clients drawn: all of them, or the top N by max, mean or last. A client with no data
	 * ranks after every client that has some, so it is shown (with "no data") only while there is
	 * room, never ahead of one with a line. The rows keep the order they came in.
	 */
	public static function select(array $rows, int $mode, int $n, int $by): array {
		if ($mode !== self::CLIENTS_TOP || $n < 1 || $n >= count($rows)) {
			return $rows;
		}
		$keep = array_column(array_slice(self::rank($rows, self::rankKey($by)), 0, $n), 'name');
		return array_values(array_filter($rows, fn($r) => in_array($r['name'], $keep, true)));
	}

	/** The legend's order: by a stat, highest first and no data last, or by client name. */
	public static function legendOrder(array $rows, int $sort): array {
		if ($sort === self::SORT_NAME) {
			usort($rows, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
			return $rows;
		}
		return self::rank($rows, [self::SORT_MEAN => 'mean', self::SORT_MAX => 'max', self::SORT_LAST => 'last'][$sort] ?? 'mean');
	}

	/**
	 * Where the dashed Warn and High lines go, in the metric's own units: a usage percentage at
	 * the Warn and High in force for the widget (its fields, else ElasticPro → Settings); any
	 * other metric at its own Warn and High thresholds (the first one crossed in its direction),
	 * and no line for a level it does not have.
	 *
	 * @param array $usage  Settings::usageFor([], …) for the widget
	 *
	 * @return array{warn: ?float, high: ?float}
	 */
	public static function lines(array $metric, array $usage, bool $on): array {
		$none = ['warn' => null, 'high' => null];
		if (!$on) {
			return $none;
		}
		if (!empty($metric['use_settings'])) {
			return ['warn' => (float) $usage['warn'], 'high' => (float) $usage['high']];
		}
		$lower = (int) ($metric['worse'] ?? Metrics::WORSE_HIGHER) === Metrics::WORSE_LOWER;
		$out = $none;
		foreach ([Metrics::LEVEL_WARN => 'warn', Metrics::LEVEL_HIGH => 'high'] as $level => $name) {
			$values = [];
			foreach ($metric['thresholds'] ?? [] as $t) {
				if ((int) $t['level'] === $level) {
					$values[] = (float) $t['value'];
				}
			}
			if ($values) {
				$out[$name] = $lower ? max($values) : min($values);
			}
		}
		return $out;
	}
}
