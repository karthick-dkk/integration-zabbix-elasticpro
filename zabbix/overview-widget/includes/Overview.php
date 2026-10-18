<?php declare(strict_types = 0);

namespace Modules\EpOverview\Includes;

use Modules\EpOverview\Lib\{ColumnMath, Forecast};

/**
 * The arithmetic of the Client overview, apart from Zabbix so the tests can hold it: how a
 * metric's servers become one value per client, its level against its thresholds, its trend
 * over a window, the forecast, the contract badge, the problem counts, and which clients show
 * in what order. WidgetView gathers the figures from Zabbix and hands them here.
 *
 * The arithmetic a card shares with every ElasticPro column — combining servers, levels, trends,
 * buckets, sparklines, how a figure is written and the addresses a click may open — is
 * ColumnMath's (../lib/ColumnMath.php, shared by every widget); the functions of that name here
 * hand straight to it, so the cards and the Client resources table cannot drift apart.
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

	/** One value per client from its servers' values: the known ones only (ColumnMath::combine). */
	public static function combine(array $values, int $fn, ?callable $over = null): ?float {
		return ColumnMath::combine($values, $fn, $over);
	}

	/** A value's level for a metric and the colour its threshold asks for (ColumnMath::level). */
	public static function level(?float $v, array $metric, array $usage): array {
		return ColumnMath::level($v, $metric, $usage);
	}

	/** A metric's trend over a window, servers combined hour by hour (ColumnMath::trend). */
	public static function trend(array $itemSeries, int $fn, int $from, int $till, ?callable $over = null): ?array {
		return ColumnMath::trend($itemSeries, $fn, $from, $till, $over);
	}

	/** One value per host from its items: the worst stands for the host (ColumnMath::perHost). */
	public static function perHost(array $values, bool $lower): array {
		return ColumnMath::perHost($values, $lower);
	}

	/** The same for trends (ColumnMath::hostSeries). */
	public static function hostSeries(array $byHost, bool $lower): array {
		return ColumnMath::hostSeries($byHost, $lower);
	}

	/** History values in buckets of $size seconds (ColumnMath::bucket). */
	public static function bucket(array $rows, int $size = 60): array {
		return ColumnMath::bucket($rows, $size);
	}

	/** At most $n points of a series, for a sparkline (ColumnMath::downsample). */
	public static function downsample(array $series, int $n = 48): array {
		return ColumnMath::downsample($series, $n);
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

	/** A figure as the card shows it (ColumnMath::format). */
	public static function format(?float $v, string $units, int $decimals, bool $short): string {
		return ColumnMath::format($v, $units, $decimals, $short);
	}

	/** A Zabbix frontend address, everything encoded (ColumnMath::url). The card only links to addresses made here. */
	public static function url(string $page, array $args): string {
		return ColumnMath::url($page, $args);
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
