<?php declare(strict_types = 0);

namespace Modules\EpOverview\Includes;

use Modules\EpOverview\Lib\{ClientData, Forecast};

/**
 * The arithmetic of the Client overview, apart from Zabbix so the tests can hold it: how a
 * metric's servers become one value per client, its level against its thresholds, its trend
 * over a window, the forecast, the contract badge, the problem counts, and which clients show
 * in what order. WidgetView gathers the figures from Zabbix and hands them here.
 *
 * Unknown is not zero anywhere in this class: a value nobody measured is null all the way
 * through, never 0, and every function that combines figures combines only the known ones.
 */
class Overview {

	public const SECTIONS = ['summary', 'header', 'problems', 'metrics', 'used', 'roles', 'warnings'];

	/** On unless the widget turns them off. The summary bar is off until asked for. */
	public const SECTIONS_ON = ['header', 'problems', 'metrics', 'used', 'roles', 'warnings'];

	/** Zabbix's severities, highest first, as the cards list them. */
	public const SEVERITIES = [5, 4, 3, 2, 1, 0];
	public const SEVERITIES_ON = ['5', '4', '3', '2'];

	public const ORDER_DESC = 0;
	public const ORDER_ASC = 1;

	public const ONLY_ALL = 0;
	public const ONLY_OVER = 1;
	public const ONLY_PROBLEMS = 2;

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
			case Metrics::COMBINE_SUM:
				return (float) array_sum($values);
			case Metrics::COMBINE_MAX:
				return (float) max($values);
			case Metrics::COMBINE_MIN:
				return (float) min($values);
			case Metrics::COMBINE_COUNT:
				return (float) count(array_filter($values, fn($v) => $over !== null && $over((float) $v)));
			default:
				return array_sum($values) / count($values);
		}
	}

	/**
	 * A value's level for a metric: '' (fine), 'warn' or 'high', and the colour its threshold
	 * asks for (null: the level's own colour).
	 *
	 * With ElasticPro -> Settings the level is ClientData::usageClass at the client's thresholds
	 * ($usage, from Settings::usageFor) — the colour every other ElasticPro report gives the same
	 * figure. Otherwise the metric's own thresholds decide, in its direction: worse when higher,
	 * a value at or above a threshold has crossed it; worse when lower, at or below. Of the
	 * thresholds crossed, the higher level wins, then the furthest one.
	 */
	public static function level(?float $v, array $metric, array $usage): array {
		if ($v === null) {
			return ['level' => '', 'color' => null];
		}
		if (!empty($metric['use_settings'])) {
			$cls = ClientData::usageClass($v, (float) $usage['warn'], (float) $usage['high']);
			return ['level' => $cls === 'ep-high' ? 'high' : ($cls === 'ep-warn' ? 'warn' : ''), 'color' => null];
		}
		$lower = (int) ($metric['worse'] ?? Metrics::WORSE_HIGHER) === Metrics::WORSE_LOWER;
		$best = null;
		foreach ($metric['thresholds'] ?? [] as $t) {
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
		return ['level' => (int) $best['level'] === Metrics::LEVEL_HIGH ? 'high' : 'warn', 'color' => $best['color'] !== '' ? '#'.$best['color'] : null];
	}

	/**
	 * A metric's trend over a window, from Zabbix trends (or history points shaped like them):
	 * per item, clock => [avg, min, max].
	 *
	 * Servers are combined hour by hour with the metric's function into one series per client:
	 *  - Sum, Average, Count over Warn: the hour's averages are combined; min and max are the
	 *    lowest and highest of those combined hours (a sum of peaks that never met would overstate).
	 *  - Highest: the hour's value is the highest average; max is the highest peak (value_max) of
	 *    any server, min the highest trough — max of maxes.
	 *  - Lowest: the mirror — min is the lowest trough of any server (min of mins).
	 * avg is the mean of the combined hours. An hour in which only some servers reported is
	 * combined from those that did; an item with no trend in the window adds nothing.
	 *
	 * @return array{avg: ?float, min: ?float, max: ?float, series: array}|null  null: no trend in the window
	 */
	public static function trend(array $itemSeries, int $fn, int $from, int $till, ?callable $over = null): ?array {
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
			$avgs = array_column($ps, 0);
			$v = self::combine($avgs, $fn, $over);
			$series[$clock] = $v;
			if ($fn === Metrics::COMBINE_MAX) {
				$hi[] = max(array_column($ps, 2));
				$lo[] = max(array_column($ps, 1));
			}
			elseif ($fn === Metrics::COMBINE_MIN) {
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
	 * One value per host from its items: a host is one server, however many items a metric reads
	 * on it (srv.disk has a mount each, a pattern may match several keys). The worst item stands
	 * for the host — the highest when higher is worse, the lowest when lower is. Unknown items add
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

	/** At most $n points of a series, each the mean of its stretch — enough for a sparkline. */
	public static function downsample(array $series, int $n = 48): array {
		$values = array_values($series);
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
	 * When a used-of-allocated share reaches 100 %, from its growth over the forecast window:
	 * the least-squares slope of the percentage over time — the reading behind the master
	 * template's own ep.es.storage.full_in (timeleft over 7 days). The bands and wording are
	 * Forecast::fullIn's, at the windows this widget sets.
	 *
	 * null: no forecast — the share is unknown (allocated not set) or there are fewer than two
	 * points across at least six hours. The caller says which.
	 */
	public static function forecast(array $series, ?float $pctNow, array $windows): ?array {
		if ($pctNow === null) {
			return null;
		}
		$pts = [];
		foreach ($series as $clock => $v) {
			if ($v !== null) {
				$pts[] = [(float) $clock, (float) $v];
			}
		}
		if (count($pts) < 2 || end($pts)[0] - $pts[0][0] < 6 * 3600) {
			return null;
		}
		$n = count($pts);
		$mx = array_sum(array_column($pts, 0)) / $n;
		$my = array_sum(array_column($pts, 1)) / $n;
		$num = 0.0;
		$den = 0.0;
		foreach ($pts as [$x, $y]) {
			$num += ($x - $mx) * ($y - $my);
			$den += ($x - $mx) ** 2;
		}
		$slope = $den > 0 ? $num / $den : 0.0;
		$seconds = $slope > 0 ? max(0.0, (100.0 - $pctNow) / $slope) : -1.0;
		return Forecast::fullIn($seconds, $windows) + ['date' => $slope > 0 ? (int) (time() + $seconds) : null];
	}

	/**
	 * The contract badge: the end date, the days left (negative once it has passed) and its
	 * level — high within $highDays or past, warn within $warnDays. null: no date set.
	 */
	public static function contract(?string $date, int $now, int $warnDays, int $highDays): ?array {
		if ($date === null) {
			return null;
		}
		$end = \DateTime::createFromFormat('!Y-m-d', $date);
		$today = (new \DateTime('@'.$now))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
		if ($end === false) {
			return null;
		}
		$days = (int) $today->diff($end)->format('%r%a');
		return ['date' => $date, 'days' => $days, 'level' => $days < 0 || $days <= $highDays ? 'high' : ($days <= $warnDays ? 'warn' : 'ok')];
	}

	/**
	 * Open problems per client and severity. Each problem counts once for a client even when its
	 * trigger spans several of that client's hosts. The filters are applied here as well as in
	 * the API call, so what is counted is what the widget says it counts.
	 *
	 * @param array $problems      problem.get rows: eventid, objectid, severity, acknowledged, suppressed
	 * @param array $triggerHosts  triggerid => hostids
	 * @param array $hostClient    hostid => client key
	 * @param int[] $severities
	 *
	 * @return array  client key => [severity => count]
	 */
	public static function countProblems(array $problems, array $triggerHosts, array $hostClient, array $severities, bool $ack, bool $suppressed): array {
		$out = [];
		foreach ($problems as $p) {
			$sev = (int) $p['severity'];
			if (!in_array($sev, $severities, true) || (!$ack && (int) ($p['acknowledged'] ?? 0) === 1)
					|| (!$suppressed && (int) ($p['suppressed'] ?? 0) === 1)) {
				continue;
			}
			$clients = [];
			foreach ($triggerHosts[$p['objectid']] ?? [] as $hostid) {
				if (isset($hostClient[$hostid])) {
					$clients[$hostClient[$hostid]] = true;
				}
			}
			foreach (array_keys($clients) as $c) {
				$out[$c][$sev] = ($out[$c][$sev] ?? 0) + 1;
			}
		}
		return $out;
	}

	/**
	 * Which clients show and in what order. Each row carries `name`, `sort` (key => value or
	 * null), `over` (a metric past a threshold) and `problems_total` (open problems counted).
	 *
	 * Order: by the chosen key in the chosen direction; a client whose key is unknown goes last
	 * either way, never first as a 0 would. Ties go by name. Then the filter, then the top N.
	 */
	public static function arrange(array $rows, string $orderBy, int $dir, int $only, ?int $top): array {
		if ($only === self::ONLY_OVER) {
			$rows = array_filter($rows, fn($r) => !empty($r['over']));
		}
		elseif ($only === self::ONLY_PROBLEMS) {
			$rows = array_filter($rows, fn($r) => (int) ($r['problems_total'] ?? 0) > 0);
		}
		$rows = array_values($rows);
		usort($rows, function ($a, $b) use ($orderBy, $dir) {
			if ($orderBy !== 'name') {
				$x = $a['sort'][$orderBy] ?? null;
				$y = $b['sort'][$orderBy] ?? null;
				if ($x === null xor $y === null) {
					return $x === null ? 1 : -1;
				}
				if ($x !== null && $x != $y) {
					return $dir === self::ORDER_ASC ? $x <=> $y : $y <=> $x;
				}
				return strnatcasecmp($a['name'], $b['name']);
			}
			$c = strnatcasecmp($a['name'], $b['name']);
			return $dir === self::ORDER_ASC ? $c : -$c;
		});
		return $top !== null && $top > 0 ? array_slice($rows, 0, $top) : $rows;
	}

	/**
	 * A figure as the card shows it. Bytes in binary units, as Zabbix draws them; a percentage
	 * with %; a count shortened to k, M, G when asked. null is "—" — the card says why beside it.
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

	/**
	 * A Zabbix frontend address: the page and its arguments, arrays as name[]=, everything
	 * encoded. The card only ever links to addresses made here.
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

	/** Problems filtered to these hosts (and severities), on Zabbix 7.0's Problems page. */
	public static function problemsUrl(array $hostids, array $severities, bool $ack, bool $suppressed): string {
		// show=3: problems open now (TRIGGERS_OPTION_IN_PROBLEM). Severities keyed by themselves,
		// as the page's own filter checkboxes post them.
		$args = ['action' => 'problem.view', 'filter_set' => 1, 'show' => 3, 'hostids' => array_values(array_map('strval', $hostids))];
		if ($severities) {
			$args['severities'] = array_combine(array_map('strval', $severities), array_map('strval', $severities));
		}
		$args['acknowledgement_status'] = $ack ? 0 : 1;
		$args['show_suppressed'] = $suppressed ? 1 : 0;
		return self::url('zabbix.php', $args);
	}
}
