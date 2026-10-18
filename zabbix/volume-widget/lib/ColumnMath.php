<?php declare(strict_types = 0);

namespace Modules\EpVolume\Lib;

/**
 * The arithmetic behind a column, apart from Zabbix so the tests can hold it: how a column's
 * servers become one value per client, its level against its thresholds, its value over a window
 * (average, minimum, maximum, change), how a figure is written, the pieces of a used / free /
 * short donut, and the only addresses a cell may link to.
 *
 * The Client overview's cards and the Client resources table both draw with these; the overview's
 * Overview class hands its own calls straight here, so the two can never disagree about what
 * "the average of the servers" or "Warn" means.
 *
 * Unknown is not zero anywhere in this class: a value nobody measured is null all the way
 * through, never 0, and every function that combines figures combines only the known ones.
 *
 * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.
 */
class ColumnMath {

	/**
	 * One value per client from its servers' values: the known ones only. No known value is
	 * null, not 0 — a sum of nothing is not a measurement of nothing.
	 *
	 * @param float[]       $values
	 * @param callable|null $over  for Count over Warn: whether a value is at or past Warn
	 */
	public static function combine(array $values, int $fn, ?callable $over = null): ?float {
		$values = array_values(array_filter($values, fn($v) => $v !== null));
		if (!$values) {
			return null;
		}
		switch ($fn) {
			case Columns::COMBINE_SUM:
				return (float) array_sum($values);
			case Columns::COMBINE_MAX:
				return (float) max($values);
			case Columns::COMBINE_MIN:
				return (float) min($values);
			case Columns::COMBINE_COUNT:
				return (float) count(array_filter($values, fn($v) => $over !== null && $over((float) $v)));
			default:
				return array_sum($values) / count($values);
		}
	}

	/**
	 * A value's level: '' (fine), 'warn' or 'high', and the colour asked for (null: the level's
	 * own colour from the palette).
	 *
	 * With ElasticPro -> Settings the level is ClientData::usageClass at the client's thresholds
	 * ($usage, from Settings::usageFor) — the colour every other ElasticPro report gives the same
	 * figure — and a column may still give each level its own colour. Otherwise the column's own
	 * thresholds decide, in its direction: worse when higher, a value at or above a threshold has
	 * crossed it; worse when lower, at or below. Of the thresholds crossed, the higher level wins,
	 * then the furthest one.
	 */
	public static function level(?float $v, array $column, array $usage): array {
		if ($v === null) {
			return ['level' => '', 'color' => null];
		}
		if (!empty($column['use_settings'])) {
			$cls = ClientData::usageClass($v, (float) $usage['warn'], (float) $usage['high']);
			$level = $cls === 'ep-high' ? 'high' : ($cls === 'ep-warn' ? 'warn' : '');
			$color = $level === '' ? null : Columns::color($column[$level.'_color'] ?? '');
			return ['level' => $level, 'color' => $color];
		}
		$lower = (int) ($column['worse'] ?? Columns::WORSE_HIGHER) === Columns::WORSE_LOWER;
		$best = null;
		foreach ($column['thresholds'] ?? [] as $t) {
			$at = (float) $t['value'];
			if ($lower ? $v > $at : $v < $at) {
				continue;
			}
			if ($best === null || (int) $t['level'] > (int) $best['level']
					|| ((int) $t['level'] === (int) $best['level'] && ($lower ? $at < (float) $best['value'] : $at > (float) $best['value']))) {
				$best = $t;
			}
		}
		if ($best === null) {
			return ['level' => '', 'color' => null];
		}
		return ['level' => (int) $best['level'] === Columns::LEVEL_HIGH ? 'high' : 'warn',
			'color' => Columns::color($best['color'] ?? '')];
	}

	/**
	 * A column's trend over a window, from Zabbix trends (or history points shaped like them):
	 * per item, clock => [avg, min, max].
	 *
	 * Servers are combined hour by hour with the column's function into one series per client:
	 *  - Sum, Average, Count over Warn: the hour's averages are combined; min and max are the
	 *    lowest and highest of those combined hours (a sum of peaks that never met would overstate).
	 *  - Highest: the hour's value is the highest average; max is the highest peak (value_max) of
	 *    any server, min the highest trough — max of maxes.
	 *  - Lowest: the mirror — min is the lowest trough of any server (min of mins).
	 * avg is the mean of the combined hours. An hour in which only some servers reported is
	 * combined from those that did; an item with no trend in the window adds nothing.
	 *
	 * `series` is each combined hour's value, from the servers' hourly averages (column 0 of a
	 * point) — or, for the Client overview's usage chart, from their minima or maxima ($point 1
	 * or 2), combined the same way; avg, min and max then describe that series.
	 *
	 * @return array{avg: ?float, min: ?float, max: ?float, series: array}|null  null: no trend in the window
	 */
	public static function trend(array $itemSeries, int $fn, int $from, int $till, ?callable $over = null, int $point = 0): ?array {
		$byClock = [];
		foreach ($itemSeries as $points) {
			foreach ($points as $clock => $p) {
				if ($clock >= $from && $clock <= $till) {
					$byClock[$clock][] = $p;
				}
			}
		}
		if (!$byClock) {
			return null;
		}
		ksort($byClock);
		$series = [];
		$lo = [];
		$hi = [];
		foreach ($byClock as $clock => $ps) {
			$avgs = array_column($ps, $point);
			$v = self::combine($avgs, $fn, $over);
			$series[$clock] = $v;
			if ($fn === Columns::COMBINE_MAX) {
				$hi[] = max(array_column($ps, 2));
				$lo[] = max(array_column($ps, 1));
			}
			elseif ($fn === Columns::COMBINE_MIN) {
				$hi[] = min(array_column($ps, 2));
				$lo[] = min(array_column($ps, 1));
			}
			else {
				$hi[] = $v;
				$lo[] = $v;
			}
		}
		return ['avg' => array_sum($series) / count($series), 'min' => (float) min($lo), 'max' => (float) max($hi), 'series' => $series];
	}

	/**
	 * The figure a column's Value asks for from its trend: the average, minimum or maximum over
	 * the window, or the change across it (the last combined hour less the first). null when
	 * there is no trend, or — for a change — fewer than two points to measure it between.
	 */
	public static function windowValue(?array $trend, int $value): ?float {
		if ($trend === null) {
			return null;
		}
		switch ($value) {
			case Columns::VALUE_AVG:
				return $trend['avg'];
			case Columns::VALUE_MIN:
				return $trend['min'];
			case Columns::VALUE_MAX:
				return $trend['max'];
			case Columns::VALUE_CHANGE:
				$known = array_values(array_filter($trend['series'] ?? [], fn($v) => $v !== null));
				return count($known) >= 2 ? (float) end($known) - (float) $known[0] : null;
		}
		return null;
	}

	/**
	 * One value per host from its items: a host is one server, however many items a column reads
	 * on it (a disk per mount, a pattern matching several keys). The worst item stands for the
	 * host — the highest when higher is worse, the lowest when lower is. Unknown items add
	 * nothing; a host with none known is null.
	 *
	 * @param array $values  list of [hostid, ?float]
	 *
	 * @return array  hostid => ?float
	 */
	public static function perHost(array $values, bool $lower): array {
		$out = [];
		foreach ($values as [$hostid, $v]) {
			if (!array_key_exists($hostid, $out)) {
				$out[$hostid] = null;
			}
			if ($v !== null && ($out[$hostid] === null || ($lower ? $v < $out[$hostid] : $v > $out[$hostid]))) {
				$out[$hostid] = (float) $v;
			}
		}
		return $out;
	}

	/**
	 * The same for trends: each host's items made into one series, clock by clock taking the point
	 * of its worst item (by the hour's average), before servers are combined.
	 *
	 * @param array $byHost  hostid => [itemid => [clock => [avg, min, max]]]
	 *
	 * @return array  hostid => [clock => [avg, min, max]]
	 */
	public static function hostSeries(array $byHost, bool $lower): array {
		$out = [];
		foreach ($byHost as $hostid => $items) {
			foreach ($items as $points) {
				foreach ($points as $clock => $p) {
					$cur = $out[$hostid][$clock] ?? null;
					if ($cur === null || ($lower ? $p[0] < $cur[0] : $p[0] > $cur[0])) {
						$out[$hostid][$clock] = $p;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * History values in buckets of $size seconds, the last value of each item in each bucket, so
	 * that servers polled a few seconds apart meet in the same bucket and combine — combined by
	 * exact clock, a sum would be one server's figure.
	 *
	 * @param array $rows  [clock, value] in any order
	 *
	 * @return array  bucket clock => [v, v, v]
	 */
	public static function bucket(array $rows, int $size = 60): array {
		usort($rows, fn($a, $b) => $a[0] <=> $b[0]);
		$out = [];
		foreach ($rows as [$clock, $v]) {
			$v = (float) $v;
			$out[intdiv((int) $clock, $size) * $size] = [$v, $v, $v];
		}
		return $out;
	}

	/**
	 * History values in buckets of $size seconds like bucket(), but keeping what happened in each
	 * bucket — its mean, lowest and highest value — as a trend hour does, for a chart whose point
	 * may be the average, the maximum or the minimum.
	 *
	 * @param array $rows  [clock, value] in any order
	 *
	 * @return array  bucket clock => [avg, min, max]
	 */
	public static function bucketStats(array $rows, int $size = 60): array {
		$groups = [];
		foreach ($rows as [$clock, $v]) {
			$groups[intdiv((int) $clock, $size) * $size][] = (float) $v;
		}
		ksort($groups);
		return array_map(fn($vs) => [array_sum($vs) / count($vs), min($vs), max($vs)], $groups);
	}

	/** At most $n points of a series, each the mean of its stretch — enough for a sparkline. */
	public static function downsample(array $series, int $n = 48): array {
		$values = array_values(array_filter($series, fn($v) => $v !== null));
		$count = count($values);
		if ($count <= $n) {
			return array_map(fn($v) => round((float) $v, 4), $values);
		}
		$out = [];
		for ($i = 0; $i < $n; $i++) {
			$a = (int) floor($i * $count / $n);
			$b = max($a + 1, (int) floor(($i + 1) * $count / $n));
			$slice = array_slice($values, $a, $b - $a);
			$out[] = round(array_sum($slice) / count($slice), 4);
		}
		return $out;
	}

	/**
	 * A figure as a card or a cell shows it. Bytes in binary units, as Zabbix draws them; a
	 * percentage with %; a count shortened to k, M, G when asked. null is "—" — the caller says
	 * why beside it.
	 */
	public static function format(?float $v, string $units, int $decimals, bool $short): string {
		if ($v === null) {
			return '—';
		}
		$num = function (float $x) use ($decimals): string {
			$s = number_format($x, $decimals, '.', abs($x) >= 10000 ? ',' : '');
			return strpos($s, '.') !== false ? rtrim(rtrim($s, '0'), '.') : $s;
		};
		if ($units === 'B' || $units === 'Bps') {
			$u = ['', 'K', 'M', 'G', 'T', 'P'];
			$i = 0;
			while (abs($v) >= 1024 && $i < count($u) - 1) {
				$v /= 1024;
				$i++;
			}
			return $num($v).' '.$u[$i].$units;
		}
		$suffix = '';
		if ($short && $units !== '%' && abs($v) >= 1000) {
			foreach (['T' => 1e12, 'G' => 1e9, 'M' => 1e6, 'k' => 1e3] as $s => $div) {
				if (abs($v) >= $div) {
					$v /= $div;
					$suffix = $s;
					break;
				}
			}
		}
		$text = $num($v).$suffix;
		if ($units === '') {
			return $text;
		}
		return $units === '%' || $units[0] === '/' ? $text.$units : $text.' '.$units;
	}

	/** What each fixed size unit divides by: binary, as Zabbix draws sizes. */
	public const SIZE_DIVISORS = [Columns::SIZE_MB => [1048576, 'MB'], Columns::SIZE_GB => [1073741824, 'GB'], Columns::SIZE_TB => [1099511627776, 'TB']];

	/**
	 * A column's figure written its way: a size in the unit it asks for (auto: whatever reads
	 * best, as format() does), anything else as format() writes it. A change carries its sign.
	 */
	public static function formatColumn(?float $v, string $units, int $decimals, bool $short, int $sizeUnit, bool $signed = false): string {
		if ($v === null) {
			return '—';
		}
		if (($units === 'B' || $units === 'Bps') && isset(self::SIZE_DIVISORS[$sizeUnit])) {
			[$div, $label] = self::SIZE_DIVISORS[$sizeUnit];
			$text = self::format($v / $div, '', $decimals, false).' '.$label.($units === 'Bps' ? 'ps' : '');
		}
		else {
			$text = self::format($v, $units, $decimals, $short);
		}
		return $signed && $v > 0 ? '+'.$text : $text;
	}

	/**
	 * A share of a bar's range, 0 to 100, or null when there is nothing to draw against. A
	 * percentage runs 0 to 100 unless the column says otherwise; anything else needs a top, given
	 * or taken from the highest value seen.
	 */
	public static function barShare(?float $v, string $units, $min, $max, ?float $seenMax = null): ?float {
		if ($v === null) {
			return null;
		}
		$lo = $min === '' || $min === null ? 0.0 : (float) $min;
		$hi = $max !== '' && $max !== null ? (float) $max : ($units === '%' ? 100.0 : ($seenMax !== null ? max($seenMax, $v) : null));
		if ($hi === null || $hi <= $lo) {
			return null;
		}
		return round(max(0.0, min(1.0, ($v - $lo) / ($hi - $lo))) * 100, 1);
	}

	/**
	 * A used / allocated / requested donut. Its ring is used, free (allocated not used) and short
	 * (requested not allocated), each as a share of the three together; the figures behind it are
	 * returned with their percentages for the legend.
	 *
	 * Allocated unknown, or not above 0, is not a donut: there is nothing to be a share of, and a
	 * ring drawn from requested instead would be a guess. The caller says "allocated not set".
	 * Used unknown likewise says "not measured". A requested figure nobody entered is simply left
	 * out: no short, and "used of requested" has nothing to be a share of.
	 *
	 * @return array{known: bool, why: ?string, used: ?float, free: ?float, short: ?float, over: ?float, allocated: ?float,
	 *               requested: ?float, segments: array, pct_allocated: ?float, pct_requested: ?float}
	 */
	public static function pie(?float $used, ?float $allocated, ?float $requested, bool $withShort = true): array {
		$requested = $requested !== null && $requested > 0 ? $requested : null;
		$out = ['known' => false, 'why' => null, 'used' => $used, 'free' => null, 'short' => null, 'over' => null,
			'allocated' => $allocated !== null && $allocated > 0 ? $allocated : null, 'requested' => $requested, 'segments' => [],
			'pct_allocated' => null, 'pct_requested' => $used !== null && $requested !== null ? $used / $requested * 100 : null];
		if ($out['allocated'] === null) {
			$out['why'] = 'allocated';
			return $out;
		}
		if ($used === null) {
			$out['why'] = 'used';
			return $out;
		}
		$allocated = $out['allocated'];
		$used = max(0.0, $used);
		$free = max($allocated - $used, 0.0);
		$short = $requested !== null ? max($requested - $allocated, 0.0) : 0.0;
		$total = $used + $free + ($withShort ? $short : 0.0);
		$out['known'] = true;
		$out['used'] = $used;
		$out['free'] = $free;
		$out['short'] = $short;
		$out['over'] = max($used - $allocated, 0.0);
		$out['pct_allocated'] = $used / $allocated * 100;
		$share = fn(float $x) => $total > 0 ? $x / $total * 100 : 0.0;
		$out['segments'] = ['used' => $share($used), 'free' => $share($free), 'short' => $withShort ? $share($short) : 0.0];
		return $out;
	}

	/**
	 * A Zabbix frontend address: the page and its arguments, arrays as name[]=, everything
	 * encoded. A cell only ever links to addresses made here, and only to Zabbix's own pages.
	 */
	public static function url(string $page, array $args): string {
		$parts = [];
		foreach ($args as $k => $v) {
			if (!is_array($v)) {
				$parts[] = rawurlencode((string) $k).'='.rawurlencode((string) $v);
				continue;
			}
			$list = array_is_list($v);
			foreach ($v as $i => $one) {
				$parts[] = rawurlencode((string) $k).'%5B'.($list ? '' : rawurlencode((string) $i)).'%5D='.rawurlencode((string) $one);
			}
		}
		return $page.($parts ? '?'.implode('&', $parts) : '');
	}

	/** A graph link holds at most this many items; more open Latest data instead. */
	public const GRAPH_ITEMS = 30;

	/**
	 * Where a click on a column's cell goes: a graph of its items, Latest data for its hosts, the
	 * client's dashboard, or nowhere. Only Zabbix pages, only through url().
	 */
	public static function clickUrl(int $click, array $itemids, array $hostids, ?string $masterid = null): ?string {
		if ($click === Columns::CLICK_GRAPH && count($itemids) > self::GRAPH_ITEMS) {
			$click = Columns::CLICK_LATEST;
		}
		if ($click === Columns::CLICK_GRAPH && $itemids) {
			return count($itemids) === 1
				? self::url('history.php', ['action' => 'showgraph', 'itemids' => array_values($itemids)])
				: self::url('history.php', ['action' => 'batchgraph', 'graphtype' => 0, 'itemids' => array_values($itemids)]);
		}
		if ($click === Columns::CLICK_LATEST && $hostids) {
			return self::url('zabbix.php', ['action' => 'latest.view', 'filter_set' => 1, 'hostids' => array_values($hostids)]);
		}
		if ($click === Columns::CLICK_DASHBOARD && $masterid !== null) {
			return self::url('zabbix.php', ['action' => 'host.dashboard.view', 'hostid' => $masterid]);
		}
		return null;
	}
}
