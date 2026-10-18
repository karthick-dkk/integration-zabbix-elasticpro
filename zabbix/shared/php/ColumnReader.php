<?php declare(strict_types = 0);

namespace EpShared;

use API;

/**
 * Reading what columns need from Zabbix, a fixed number of times per refresh and never once per
 * client: one item.get for every exact key on every host, one more when any column's key is a
 * pattern, one trend.get for every item a window is asked of (history.get instead, one per value
 * type, when the window is under two hours). Always as the user viewing the widget — this class
 * calls the API the controller was given, nothing else.
 *
 * The Client overview and Client resources both read through this, so a pattern is capped the
 * same way and history is bucketed the same way in both.
 *
 * Shared: ../../sync-assets.mjs copies this into each module, under that module's namespace.
 */
class ColumnReader {

	/** Under this, a window is read from history: trends are hourly. */
	public const HISTORY_UNDER = 7200;

	/** History is combined across servers in buckets of this many seconds. */
	public const HISTORY_BUCKET = 60;

	/** The most items one pattern item.get may return. */
	public const PATTERN_LIMIT = 10000;

	public const FIELDS = ['itemid', 'hostid', 'key_', 'name', 'lastvalue', 'lastclock', 'state', 'value_type', 'units'];

	/** Patterns whose item.get hit its limit: their figures may be short of items. */
	public array $patternCapped = [];

	/**
	 * One item.get for every exact key on every host, one more for the patterns.
	 *
	 * @param array $exact     key => [hostid => true]
	 * @param array $patterns  pattern => [hostid => true]
	 *
	 * @return array  hostid => key => item
	 */
	public function items(array $exact, array $patterns): array {
		$out = [];
		$index = function (array $rows, ?array $hosts) use (&$out) {
			foreach ($rows ?: [] as $it) {
				if ($hosts !== null && !isset($hosts[$it['key_']][$it['hostid']])) {
					continue;
				}
				$out[$it['hostid']][$it['key_']] = $it;
			}
		};
		if ($exact) {
			$hostids = array_keys(array_replace(...array_values($exact)));
			$index(API::Item()->get(['output' => self::FIELDS, 'hostids' => $hostids, 'filter' => ['key_' => array_keys($exact)]]) ?: [], $exact);
		}
		if ($patterns) {
			// Every pattern starts with a literal prefix (Columns::keyError), so the search is anchored
			// at the start; the limit keeps one careless pattern from pulling every item there is.
			$hostids = array_keys(array_replace(...array_values($patterns)));
			$limit = min(self::PATTERN_LIMIT, max(1, count($hostids)) * Columns::PATTERN_PER_HOST * count($patterns));
			$rows = API::Item()->get(['output' => self::FIELDS, 'hostids' => $hostids, 'search' => ['key_' => array_keys($patterns)],
				'searchByAny' => true, 'searchWildcardsEnabled' => true, 'startSearch' => true, 'limit' => $limit]) ?: [];
			if (count($rows) >= $limit) {
				$this->patternCapped = array_fill_keys(array_keys($patterns), true);
			}
			foreach ($rows as $it) {
				foreach ($patterns as $p => $hosts) {
					if (isset($hosts[$it['hostid']]) && Columns::keyMatches($it['key_'], $p, true)) {
						$out[$it['hostid']][$it['key_']] = $it;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * The items one column reads for one client, as found. A pattern brings in at most
	 * Columns::PATTERN_PER_HOST items per host and PATTERN_PER_METRIC in all; $capped says when
	 * it would have brought more (or the item.get itself hit its limit).
	 *
	 * @param array $want  list of [hostid, key, is pattern]
	 */
	public function matched(array $want, array $items, ?bool &$capped = null): array {
		$out = [];
		$capped = false;
		foreach ($want as [$hostid, $key, $pattern]) {
			if (!$pattern) {
				if (isset($items[$hostid][$key])) {
					$out[$items[$hostid][$key]['itemid']] = $items[$hostid][$key];
				}
				continue;
			}
			$n = 0;
			foreach ($items[$hostid] ?? [] as $k => $it) {
				if (!Columns::keyMatches((string) $k, $key, true)) {
					continue;
				}
				if ($n >= Columns::PATTERN_PER_HOST || count($out) >= Columns::PATTERN_PER_METRIC) {
					$capped = true;
					break;
				}
				$out[$it['itemid']] = $it;
				$n++;
			}
			if (isset($this->patternCapped[$key])) {
				$capped = true;
			}
		}
		return array_values($out);
	}

	/**
	 * Every item's points in its window: clock => [avg, min, max]. One trend.get for all of
	 * them; items whose window is under two hours come from history instead (trends are hourly),
	 * one history.get per value type, put in one-minute buckets (each item's last value in each)
	 * so servers polled seconds apart are combined, not counted one at a time.
	 *
	 * @param array $want  itemid => ['from' => int, 'till' => int, 'value_type' => int]
	 */
	public function series(array $want): array {
		$out = [];
		$trend = [];
		$history = [];
		foreach ($want as $itemid => $w) {
			if ($w['till'] - $w['from'] < self::HISTORY_UNDER) {
				$history[$w['value_type']][$itemid] = $w;
			}
			else {
				$trend[$itemid] = $w;
			}
		}
		if ($trend) {
			$rows = API::Trend()->get(['output' => ['itemid', 'clock', 'value_min', 'value_avg', 'value_max'], 'itemids' => array_keys($trend),
				'time_from' => min(array_column($trend, 'from')), 'time_till' => max(array_column($trend, 'till'))]);
			foreach ($rows ?: [] as $r) {
				$out[$r['itemid']][(int) $r['clock']] = [(float) $r['value_avg'], (float) $r['value_min'], (float) $r['value_max']];
			}
		}
		foreach ($history as $type => $ws) {
			$rows = API::History()->get(['output' => ['itemid', 'clock', 'value'], 'history' => $type, 'itemids' => array_keys($ws),
				'time_from' => min(array_column($ws, 'from')), 'time_till' => max(array_column($ws, 'till')), 'sortfield' => 'clock']);
			$byItem = [];
			foreach ($rows ?: [] as $r) {
				$byItem[$r['itemid']][] = [(int) $r['clock'], $r['value']];
			}
			foreach ($byItem as $itemid => $points) {
				$out[$itemid] = ColumnMath::bucket($points, self::HISTORY_BUCKET);
			}
		}
		return $out;
	}

	/** A known item's value, or null: the one test of "known" every ElasticPro report uses. */
	public static function value(?array $it): ?float {
		return $it !== null && ClientData::known($it) ? (float) $it['lastvalue'] : null;
	}

	/** Numeric item value types (float, unsigned): the only ones a column computes with. */
	public static function numeric(array $it): bool {
		return in_array((int) ($it['value_type'] ?? 0), [0, 3], true);
	}

	/* ------------------------------ the column editor's helpers ------------------------------ */

	/** Every client server this user can see, with its roles: hostid => [roleId, …]. */
	public static function clientServers(array $roles, array $cfg): array {
		[, $clients] = ClientData::clients($cfg, []);
		$out = [];
		foreach (ClientData::servers($roles, $clients, false) as $list) {
			foreach ($list as $s) {
				$out[$s['hostid']] = $s['roles'];
			}
		}
		return $out;
	}

	/** Numeric item keys on these servers, for the editor's key box to suggest. */
	public static function keySuggestions(array $servers): array {
		if (!$servers) {
			return [];
		}
		$keys = array_unique(array_column(API::Item()->get(['output' => ['key_'], 'hostids' => array_keys($servers),
			'filter' => ['value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]], 'limit' => 20000]) ?: [], 'key_'));
		sort($keys, SORT_NATURAL);
		return array_slice($keys, 0, 2000);
	}

	/**
	 * How many client servers (in the chosen roles) have an item with this key: "found on 41 of
	 * 52". A pattern is anchored at its literal start and limited, as when the widget reads it.
	 *
	 * @param array $servers  hostid => roles, as clientServers() returns them
	 */
	public static function coverage(string $key, bool $pattern, array $want, array $servers): array {
		if (Columns::keyError($key, $pattern) !== null) {
			return ['found' => null, 'total' => null];
		}
		$servers = array_filter($servers, fn($held) => !$want || array_intersect($want, $held));
		if (!$servers) {
			return ['found' => 0, 'total' => 0];
		}
		$options = ['output' => ['hostid', 'key_'], 'hostids' => array_keys($servers)];
		$options += $pattern
			? ['search' => ['key_' => $key], 'searchWildcardsEnabled' => true, 'startSearch' => true,
				'limit' => min(self::PATTERN_LIMIT, count($servers) * Columns::PATTERN_PER_HOST)]
			: ['filter' => ['key_' => $key]];
		$hosts = [];
		foreach (API::Item()->get($options) ?: [] as $it) {
			if (Columns::keyMatches($it['key_'], $key, $pattern)) {
				$hosts[$it['hostid']] = true;
			}
		}
		return ['found' => count($hosts), 'total' => count($servers)];
	}
}
