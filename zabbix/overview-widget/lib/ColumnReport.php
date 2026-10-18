<?php declare(strict_types = 0);

namespace Modules\EpOverview\Lib;

/**
 * A table of rows read through the column model, the same for every widget that draws one:
 * Client resources, Client capacity, Volume (a row a client or a cluster) and Top hosts (a row a
 * server). A widget hands it its rows — each with the host its master figures are on and the
 * servers its server figures are on — and the columns that show; it reads Zabbix a fixed number of
 * times however many rows and columns there are (one item.get for every exact key on every host, one
 * more when a column's key is a pattern, one trend.get for every window or sparkline — ColumnReader),
 * works out each column's figure (ColumnMath), and turns the figures into cells, totals and export
 * (ColumnCell). Always as the user viewing the widget.
 *
 * The widget keeps what is its own: which rows there are, what its built-in columns are (its
 * Catalogue), and — through $levels — any level it decides itself (Volume's plan thresholds).
 *
 * Unknown is not zero anywhere here.
 *
 * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.
 */
class ColumnReport {

	/** Columns from: the value of the widget's "This widget" choice. */
	public const FROM_WIDGET = 1;

	public ColumnReader $reader;

	private array $cfg;
	private int $now;
	private $warnW;
	private $highW;
	/** @var callable|null  fn(array $column, ?array $entry, ?float $value, array $client): ?string — 'warn', 'high', '' or null (the engine decides) */
	private $levels;
	/** A row is one host (Volume, Top hosts): there are no servers to combine, so a window's maximum is the host's own peak. */
	private bool $single;
	private array $items = [];
	private array $series = [];
	private array $found = [];
	private array $seenMax = [];
	private array $units = [];
	private int $trendDays = 7;

	/**
	 * @param mixed $warnW  the widget's own Warn at ('' : ElasticPro Settings)
	 * @param mixed $highW  and High at
	 */
	public function __construct(array $cfg, int $now, $warnW = '', $highW = '', ?callable $levels = null, bool $single = false) {
		$this->single = $single;
		$this->cfg = $cfg;
		$this->now = $now;
		$this->warnW = $warnW;
		$this->highW = $highW;
		$this->levels = $levels;
		$this->reader = new ColumnReader();
	}

	/**
	 * The columns in force for a widget: its own list or the report defaults (a version-1 file read
	 * onto them), each through the column rule leniently — what no longer passes is left out and
	 * named.
	 *
	 * @param array       $f          the widget's field values
	 * @param array       $model      ColumnSettings::loadModel
	 * @param array       $catalogue  the built-in columns of the client scope
	 * @param object|null $form       the widget's form, for what its Columns field dropped
	 *
	 * @return array{own: bool, columns: array, visible: array, dropped: string[], defaults: array}
	 */
	public static function resolve(array $f, array $model, array $catalogue, array $roleIds, $form = null, bool $newHidden = true): array {
		$own = (int) ($f['columns_from'] ?? 0) === self::FROM_WIDGET;
		$defaults = ColumnSettings::resolve($model['columns'], $catalogue, $model['legacy'], $model['new_hidden']);
		$list = $own && !empty($f['columns']) ? ColumnSettings::resolve($f['columns'], $catalogue, null, $newHidden) : $defaults;
		[$columns, , $dropped] = Columns::cleanList($list, $catalogue, $roleIds, false);
		if ($own && $form !== null) {
			$field = $form->getFields()['columns'] ?? null;
			if ($field !== null && method_exists($field, 'getDropped')) {
				$dropped = array_merge($field->getDropped(), $dropped);
			}
		}
		[$cleanDefaults] = Columns::cleanList($defaults, $catalogue, $roleIds, false);

		return ['own' => $own, 'columns' => $columns, 'visible' => array_values(array_filter($columns, fn($c) => !$c['hidden'])),
			'dropped' => $dropped, 'defaults' => $cleanDefaults];
	}

	/**
	 * A widget's settings as the fields expect them, from whatever came in (a saved widget, old or
	 * new; the form; the dashboard). A Warn or High saved as 0 — Zabbix's integer box saving an empty
	 * box — is empty: ElasticPro -> Settings, which is what it always meant. The rows the form
	 * reordered by dragging come back in their new order. A widget on its own columns starts from the
	 * report defaults, and a built-in column the catalogue has gained since is offered unticked; one
	 * that follows the report defaults keeps nothing of its own.
	 *
	 * @param array $model      ColumnSettings::loadModel
	 * @param array $catalogue  the built-in columns of the client scope
	 * @param bool  $newHidden  whether a built-in column the list has never seen arrives unticked
	 */
	public static function upgrade(array $values, array $catalogue, array $model, bool $newHidden = true): array {
		if (isset($values['sortorder']['columns']) && isset($values['columns']) && is_array($values['columns'])) {
			$sorted = [];
			foreach ((array) $values['sortorder']['columns'] as $index) {
				if (isset($values['columns'][$index])) {
					$sorted[] = $values['columns'][$index];
				}
			}
			$values['columns'] = $sorted;
		}
		unset($values['sortorder']);

		foreach (['usage_warn', 'usage_high'] as $k) {
			if (array_key_exists($k, $values) && is_numeric($values[$k]) && (float) $values[$k] <= 0) {
				$values[$k] = '';
			}
		}

		if ((int) ($values['columns_from'] ?? 0) === self::FROM_WIDGET) {
			$own = array_values(array_filter((array) ($values['columns'] ?? []), 'is_array'));
			$values['columns'] = $own
				? ColumnSettings::resolve($own, $catalogue, null, $newHidden)
				: ColumnSettings::resolve($model['columns'], $catalogue, $model['legacy'], $model['new_hidden']);
		}
		else {
			unset($values['columns']);
		}

		return $values;
	}

	/**
	 * Whether any of these columns reads figures off a client's servers — an item key, a calculation
	 * with one, a built-in column of the servers — so a widget with no servers of its own knows when
	 * it has to ask for them.
	 */
	public static function readsServers(array $visible, array $catalogue): bool {
		foreach ($visible as $col) {
			switch ((int) $col['source']) {
				case Columns::SOURCE_ITEM:
					return true;
				case Columns::SOURCE_CALC:
					foreach (['calc_a', 'calc_b'] as $k) {
						$op = (string) $col[$k];
						if (strpos($op, Columns::KEY_OPERAND) === 0 || ($catalogue[$op]['on'] ?? '') === Columns::ON_SERVERS) {
							return true;
						}
					}
					break;
				default:
					if (($catalogue[$col['builtin']]['on'] ?? '') === Columns::ON_SERVERS) {
						return true;
					}
			}
		}

		return false;
	}

	/** What the Columns dialog lists: the report defaults, each with the name the report itself gives it. */
	public static function dialogColumns(array $defaults, array $catalogue): array {
		return array_map(fn($c) => ['id' => $c['uid'], 'label' => $c['name'],
			'default' => (int) $c['source'] === Columns::SOURCE_BUILTIN ? (string) ($catalogue[$c['builtin']]['label'] ?? $c['name']) : $c['name'],
			'hidden' => (bool) $c['hidden'], 'section' => $c['group']], $defaults);
	}

	/**
	 * Reads Zabbix for these rows and works out every visible column's figure for each.
	 *
	 * @param array $rows        id => ['client' => the row as the widget describes it (name, type, macros, and the text a
	 *                           built-in column of the row reads), 'servers' => the servers its server figures are on
	 *                           (hostid, roles, disks)]; the id is the host the row's master figures are on
	 * @param array $masterKeys  further keys to read on every row's master host (a donut's, the forecast's)
	 */
	public function read(array $rows, array $visible, array $catalogue, int $trendDays, array $masterKeys = []): void {
		$this->trendDays = max(1, $trendDays);
		$exact = [];
		$patterns = [];
		$plan = [];
		foreach ($rows as $mid => $row) {
			foreach ($visible as $col) {
				$plan[$mid][$col['uid']] = $this->targets($col, $catalogue, (string) $mid, $row['servers']);
				foreach ($plan[$mid][$col['uid']] as $target) {
					foreach ($target['want'] as [$hostid, $key, $pattern]) {
						if ($pattern) {
							$patterns[$key][$hostid] = true;
						}
						else {
							$exact[$key][$hostid] = true;
						}
					}
				}
			}
			foreach ($masterKeys as $key) {
				$exact[$key][$mid] = true;
			}
		}
		$this->items = $this->reader->items($exact, $patterns);

		// Trends: every item a column takes a window of, or draws a sparkline from.
		$wantSeries = [];
		foreach ($rows as $mid => $row) {
			foreach ($visible as $col) {
				if ((int) $col['value'] === Columns::VALUE_NOW && (int) $col['show'] !== Columns::SHOW_SPARKLINE) {
					continue;
				}
				$from = $this->now - ($col['window'] > 0 ? $col['window'] : $this->trendDays) * 86400;
				foreach ($plan[$mid][$col['uid']] as $target) {
					foreach ($this->reader->matched($target['want'], $this->items) as $it) {
						if (!ColumnReader::numeric($it)) {
							continue;
						}
						$wantSeries[$it['itemid']] = ['from' => min($from, $wantSeries[$it['itemid']]['from'] ?? PHP_INT_MAX),
							'till' => $this->now, 'value_type' => (int) $it['value_type']];
					}
				}
			}
		}
		$this->series = $wantSeries ? $this->reader->series($wantSeries) : [];

		// Every figure first, then the cells: a bar with no top of its own runs to the highest of its column.
		$this->found = [];
		$this->seenMax = [];
		$this->units = [];
		foreach ($rows as $mid => $row) {
			foreach ($visible as $col) {
				$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$col['builtin']] ?? null) : null;
				$fig = $this->figure($col, $entry, (string) $mid, $row['client'], $plan[$mid][$col['uid']], $this->items, $this->series, $this->trendDays, $catalogue);
				$this->found[$mid][$col['uid']] = $fig;
				if (isset($fig['value']) && is_float($fig['value'])) {
					$this->seenMax[$col['uid']] = max($this->seenMax[$col['uid']] ?? $fig['value'], $fig['value']);
				}
				if (($fig['units'] ?? '') !== '' && !isset($this->units[$col['uid']])) {
					$this->units[$col['uid']] = $fig['units'];
				}
			}
		}
	}

	/** Every item read, hostid => key => item (a widget draws donuts and forecasts from the master's). */
	public function items(): array {
		return $this->items;
	}

	/** The units each column's figures turned out to be in, uid => units. */
	public function units(): array {
		return $this->units;
	}

	/**
	 * One row's cells, ready to draw, and what they say about it.
	 *
	 * @return array{cells: array, flags: array, sort: array, export: array}  flags: warn, high, short counts
	 */
	public function cells(string $id, array $client, array $visible, array $catalogue, string $widgetBars): array {
		$cells = [];
		$flags = ['warn' => 0, 'high' => 0, 'short' => 0];
		$sort = [];
		$export = [];
		foreach ($visible as $col) {
			$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$col['builtin']] ?? null) : null;
			$fig = ($this->found[$id][$col['uid']] ?? ['value' => null, 'why' => _('not collected')])
				+ ['seen_max' => $this->seenMax[$col['uid']] ?? null, 'widget_bars' => $widgetBars,
					'usage' => ColumnCell::usage($client['macros'], $col, $this->warnW, $this->highW, $this->cfg)];
			$cell = ColumnCell::cell($col, $entry, $fig, $this->cfg) + ['layout' => ColumnCell::layout($col), 'first' => !$cells];
			if ($cell['level'] === 'warn' || $cell['level'] === 'high') {
				$flags[$cell['level']]++;
			}
			if ($cell['short']) {
				$flags['short']++;
			}
			// A figure that says what it sorts and exports by (a forecast: its days, null when not
			// growing) is taken at its word, null included.
			$sort[$col['uid']] = array_key_exists('sort', $fig) ? $fig['sort'] : $cell['value'];
			$cell['sort'] = $sort[$col['uid']];
			$export[] = ColumnCell::exportValue(array_key_exists('export', $fig) ? $fig['export'] : $cell['value'],
				(string) ($fig['export_units'] ?? $fig['units'] ?? ''), $this->cfg);
			$cells[] = $cell;
		}

		return ['cells' => $cells, 'flags' => $flags, 'sort' => $sort, 'export' => $export];
	}

	/**
	 * The totals row: the first cell says Total or Average, the others the sum (or average) of the
	 * rows' known figures; nothing for a text column or a column with nothing known.
	 *
	 * @param array $rows  each with 'sort' => uid => value
	 */
	public function totals(array $rows, array $visible, array $catalogue, bool $average, ?int $topN = null): array {
		$out = [];
		foreach ($visible as $i => $col) {
			$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$col['builtin']] ?? null) : null;
			$u = (string) ($this->units[$col['uid']] ?? $col['units']);
			$text = !empty($entry['text']) || !empty($entry['forecast']);
			$values = array_map(fn($r) => $r['sort'][$col['uid']] ?? null, $rows);
			$isPct = (int) $col['source'] === Columns::SOURCE_CALC && (int) $col['calc_op'] === Columns::OP_OF;
			$t = $text ? null : ColumnCell::total($values, $isPct ? '%' : $u, $average);
			$out[] = $i === 0 ? self::totalsLabel($average, $topN)
				: ($t === null ? '' : ($isPct ? ColumnCell::pctText($t, $this->cfg) : ColumnCell::text($t, $u, $col, $this->cfg)));
		}

		return $out;
	}

	/**
	 * What the totals row is over, said in its first cell: every row the widget lists, or its top N.
	 * A viewer's search, Type filter or Needs attention hides the row (ep-panel.js), since these
	 * figures are not the visible rows'.
	 */
	public static function totalsLabel(bool $average, ?int $topN): string {
		if ($topN !== null) {
			return $average ? _s('Average (top %1$s)', $topN) : _s('Total (top %1$s)', $topN);
		}

		return $average ? _('Average (all clients)') : _('Total (all clients)');
	}

	/**
	 * The export's headers for the visible columns: its group and name — or, with $groupFirst off,
	 * its name alone, the group only where two columns share a name (Volume's have always been named
	 * by their label).
	 */
	public function exportHeaders(array $visible, array $catalogue, bool $groupFirst = true): array {
		$names = array_count_values(array_column($visible, 'name'));

		return array_map(fn($col) => ColumnCell::exportHeader($col, $this->exportUnits($col, $catalogue, $this->units), $this->cfg,
			$groupFirst || $names[$col['name']] > 1), $visible);
	}

	/** A column's header: its group, its name, how its cells sit, and whether it is a number. */
	public function head(array $col, array $catalogue): array {
		$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$col['builtin']] ?? null) : null;
		return ['uid' => $col['uid'], 'builtin' => (int) $col['source'] === Columns::SOURCE_BUILTIN ? $col['builtin'] : '', 'group' => $col['group'],
			'name' => $col['name'], 'layout' => ColumnCell::layout($col), 'custom' => (int) $col['source'] !== Columns::SOURCE_BUILTIN];
	}

	/** The units a column's export is in: what Zabbix said its items are in, else what the catalogue says. */
	public function exportUnits(array $col, array $catalogue, array $units): string {
		if ((int) $col['source'] === Columns::SOURCE_CALC && (int) $col['calc_op'] === Columns::OP_OF) {
			return '%';
		}
		$entry = (int) $col['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$col['builtin']] ?? null) : null;
		if (!empty($entry['forecast'])) {
			return 'days';
		}
		return (string) ($units[$col['uid']] ?? ($entry['units'] ?? $col['units']));
	}

	/**
	 * Where one column is read for one client: for each of its figures (the value; a calculated
	 * column's two operands; a built-in's share and request) [hostid, key, is pattern] per host,
	 * and whether they are on the master host or the servers.
	 */
	private function targets(array $col, array $catalogue, string $mid, array $servers): array {
		$picked = array_values(array_filter($servers, fn($s) => !$col['roles'] || array_intersect($col['roles'], $s['roles'])));
		$operand = function (string $spec) use ($catalogue, $mid, $picked): array {
			if (strpos($spec, Columns::KEY_OPERAND) === 0) {
				$key = substr($spec, strlen(Columns::KEY_OPERAND));
				return ['on' => Columns::ON_SERVERS, 'servers' => count($picked), 'want' => array_map(fn($s) => [$s['hostid'], $key, false], $picked),
					'label' => $key];
			}
			$b = $catalogue[$spec] ?? null;
			if ($b === null || !isset($b['key'])) {
				return ['on' => Columns::ON_MASTER, 'servers' => 0, 'want' => [], 'label' => $spec];
			}
			if ($b['on'] === Columns::ON_MASTER) {
				return ['on' => Columns::ON_MASTER, 'servers' => 1, 'want' => [[$mid, $b['key'], false]], 'label' => self::operandLabel($b)];
			}
			$want = [];
			foreach ($picked as $s) {
				if (!empty($b['disk'])) {
					foreach ($s['disks'] as $d) {
						$want[] = [$s['hostid'], 'vfs.fs.dependent.size['.$d['mount'].',pused]', false];
					}
				}
				else {
					$want[] = [$s['hostid'], $b['key'], false];
				}
			}
			return ['on' => Columns::ON_SERVERS, 'servers' => count($picked), 'want' => $want, 'label' => self::operandLabel($b)];
		};
		switch ((int) $col['source']) {
			case Columns::SOURCE_ITEM:
				return ['a' => ['on' => Columns::ON_SERVERS, 'servers' => count($picked),
					'want' => array_map(fn($s) => [$s['hostid'], $col['key'], (bool) $col['pattern']], $picked), 'label' => $col['key']]];
			case Columns::SOURCE_CALC:
				return ['a' => $operand($col['calc_a']), 'b' => $operand($col['calc_b'])];
		}
		$b = $catalogue[$col['builtin']] ?? null;
		if ($b === null || !isset($b['key'])) {
			return [];
		}
		$out = ['a' => $operand($col['builtin'])];
		foreach (['pct', 'vs'] as $k) {
			if (isset($b[$k])) {
				$out[$k] = ['on' => Columns::ON_MASTER, 'servers' => 1, 'want' => [[$mid, $b[$k], false]], 'label' => $k];
			}
		}
		return $out;
	}

	/** How a reason names a built-in figure: "ES storage allocated", "devices seen". */
	private static function operandLabel(array $b): string {
		$label = mb_strtolower(mb_substr((string) $b['label'], 0, 1)).mb_substr((string) $b['label'], 1);
		return trim(($b['group'] ?? '') !== '' ? $b['group'].' '.$label : $label);
	}

	/**
	 * One operand's figure for one client: one value per server first — its worst item — then the
	 * servers combined; or the master host's item. Now, or over the window from the trend.
	 *
	 * @return array{now: ?float, value: ?float, trend: ?array, itemids: array, hostids: array, units: string, why: ?string, newest: int}
	 */
	private function evaluate(array $target, array $col, array $items, array $series, int $trendDays, array $usage): array {
		$found = $this->reader->matched($target['want'], $items, $capped);
		$numeric = array_values(array_filter($found, fn($it) => ColumnReader::numeric($it)));
		$master = $target['on'] === Columns::ON_MASTER;
		$lower = (int) $col['worse'] === Columns::WORSE_LOWER;
		$fn = $master || $this->single ? Columns::COMBINE_MAX : (int) $col['combine'];
		$over = fn(float $v) => ColumnMath::level($v, $col, $usage)['level'] !== '';
		$hostValues = ColumnMath::perHost(array_map(fn($it) => [$it['hostid'], ColumnReader::value($it)], $numeric), $lower);
		$now = ColumnMath::combine(array_values($hostValues), $fn, $over);
		$from = $this->now - ($col['window'] > 0 ? $col['window'] : $trendDays) * 86400;
		$byHost = [];
		foreach ($numeric as $it) {
			if (isset($series[$it['itemid']])) {
				$byHost[$it['hostid']][$it['itemid']] = $series[$it['itemid']];
			}
		}
		$trend = $byHost ? ColumnMath::trend(ColumnMath::hostSeries($byHost, $lower), $fn, $from, $this->now, $over) : null;
		$value = (int) $col['value'] === Columns::VALUE_NOW ? $now : ColumnMath::windowValue($trend, (int) $col['value']);
		$why = null;
		if ($value === null) {
			if (!$master && $target['servers'] === 0) {
				$why = $col['roles'] ? _('no servers in these roles') : _('no servers with a role');
			}
			elseif ($found && !$numeric) {
				$why = _('not a number');
			}
			elseif ($now !== null && $trend === null && array_intersect_key(array_flip(array_column($numeric, 'itemid')), $this->reader->seriesCapped)) {
				$why = _('window not read');
			}
			elseif ($now !== null && $trend === null) {
				$why = _('no trend yet');
			}
			elseif ($found) {
				$why = _('no value yet');
			}
			else {
				$why = _('not collected');
			}
		}
		$count = !$master && (int) $col['combine'] === Columns::COMBINE_COUNT;
		return [
			'now' => $now, 'value' => $value, 'trend' => $trend, 'capped' => (bool) $capped,
			'trend_capped' => (bool) array_intersect_key(array_flip(array_column($numeric, 'itemid')), $this->reader->seriesCapped),
			'itemids' => array_column($numeric, 'itemid'), 'hostids' => array_values(array_unique(array_column($found, 'hostid'))),
			'units' => $count ? '' : (string) ($numeric[0]['units'] ?? ''), 'why' => $why,
			'newest' => $numeric ? max(array_map(fn($it) => (int) $it['lastclock'], $numeric)) : 0,
			'host_levels' => $count ? array_map(fn($v) => ColumnMath::level($v, $col, $usage)['level'], array_filter($hostValues, fn($v) => $v !== null)) : null
		];
	}

	/** One column's figures for one client, for ColumnCell::cell to draw. */
	private function figure(array $col, ?array $entry, string $mid, array $client, array $plan, array $items, array $series, int $trendDays,
			array $catalogue): array {
		$usage = ColumnCell::usage($client['macros'], $col, $this->warnW, $this->highW, $this->cfg);
		$source = (int) $col['source'];

		if ($source === Columns::SOURCE_BUILTIN && $entry !== null && $entry['on'] === Columns::ON_CLIENT) {
			if (isset($entry['field'])) {
				// export_field: the plain text the export has where the cell adds something (a client's "(disabled)").
				return (isset($entry['export_field']) ? ['export' => (string) ($client[$entry['export_field']] ?? '')] : [])
					+ ['text' => (string) ($client[$entry['field']] ?? ''),
					'url' => (int) $col['click'] === Columns::CLICK_DASHBOARD ? ColumnMath::clickUrl(Columns::CLICK_DASHBOARD, [], [], $mid) : null];
			}
			return ['text' => (string) ($client['macros'][$entry['macro']] ?? '')];
		}
		if (!isset($plan['a'])) {
			return ['value' => null, 'why' => _('not collected')];
		}
		// A text item on the master host (a date, a name): its last value, as it is.
		if ($source === Columns::SOURCE_BUILTIN && $entry !== null && $entry['on'] === Columns::ON_MASTER && !empty($entry['text']) && isset($entry['key'])) {
			$it = $items[$mid][$entry['key']] ?? null;
			$known = $it !== null && ClientData::known($it);

			return ['text' => $known ? (string) $it['lastvalue'] : '', 'url' => null];
		}

		$a = $this->evaluate($plan['a'], $col, $items, $series, $trendDays, $usage);
		// Count over Warn is a number of servers: no units, never a percentage, whatever it counts.
		$count = $a['host_levels'] !== null && $source !== Columns::SOURCE_CALC;
		$fig = ['value' => $a['value'], 'units' => $count ? '' : ($a['units'] !== '' ? $a['units'] : (string) ($entry['units'] ?? $col['units'])), 'why' => $a['why']];
		if ($source !== Columns::SOURCE_BUILTIN && $col['units'] !== '' && !$count) {
			$fig['units'] = $col['units'];
		}
		$itemids = $a['itemids'];
		$hostids = $a['hostids'];

		if ($source === Columns::SOURCE_CALC) {
			$b = $this->evaluate($plan['b'], $col, $items, $series, $trendDays, $usage);
			$itemids = array_merge($itemids, $b['itemids']);
			$hostids = array_values(array_unique(array_merge($hostids, $b['hostids'])));
			$x = $a['value'];
			$y = $b['value'];
			$fig['why'] = $x === null ? _s('%1$s %2$s', $plan['a']['label'], $a['why']) : ($y === null ? _s('%1$s not set', $plan['b']['label']) : null);
			switch ((int) $col['calc_op']) {
				case Columns::OP_OF:
					if ($x !== null && $y !== null && $y > 0) {
						$fig['pct'] = $x / $y * 100;
						$fig['of'] = ColumnCell::text($y, $b['units'] !== '' ? $b['units'] : $fig['units'], $col, $this->cfg);
						$fig['export'] = $fig['pct'];
						$fig['export_units'] = '%';
						$fig['sort'] = $fig['pct'];
					}
					else {
						if ($x !== null && $y !== null) {
							$fig['why'] = _s('%1$s not set', $plan['b']['label']);
						}
						$fig['value'] = null;
					}
					break;
				case Columns::OP_MINUS:
					$fig['value'] = $x !== null && $y !== null ? $x - $y : null;
					break;
				case Columns::OP_PLUS:
					$fig['value'] = $x !== null && $y !== null ? $x + $y : null;
					break;
				case Columns::OP_DIV:
					$fig['value'] = $x !== null && $y !== null && $y != 0 ? $x / $y : null;
					if ($x !== null && $y !== null && $y == 0) {
						$fig['why'] = _s('%1$s is 0', $plan['b']['label']);
					}
					break;
			}
			if ((int) $col['calc_op'] === Columns::OP_DIV && $col['units'] === '') {
				$fig['units'] = '';
			}
		}
		elseif ($source === Columns::SOURCE_BUILTIN && $entry !== null) {
			if (!empty($entry['forecast'])) {
				$fc = ColumnCell::forecast($a['now'], $this->cfg);
				if ($fc['text'] === null) {
					return ['value' => null, 'why' => $a['why'] ?? _('not measured yet'), 'units' => 's'];
				}
				return ['value' => $a['now'], 'display' => $fc['text'], 'level' => $fc['level'], 'units' => 's', 'export' => $fc['days'],
					'export_units' => 'days', 'sort' => $fc['days'] ?? 1e9, 'url' => null];
			}
			if (!empty($entry['requested']) && (int) $col['value'] === Columns::VALUE_NOW && $a['value'] !== null && $a['value'] == 0) {
				return ['value' => null, 'unset' => true, 'units' => $fig['units']];
			}
			if (!empty($entry['settings']) && !$count) {
				$fig['pct'] = $a['value'];
			}
			if (isset($plan['pct'])) {
				$p = $this->evaluate($plan['pct'], $col, $items, $series, $trendDays, $usage);
				$fig['pct'] = $p['value'];
			}
			if (isset($plan['vs']) && $a['value'] !== null) {
				$req = ColumnReader::value($items[$mid][$entry['vs']] ?? null);
				if ($req !== null && $req > 0 && $a['value'] < $req) {
					$fig['short'] = _s('Below %1$s: %2$s', $entry['vs_label'] ?? _('requested'),
						ColumnCell::text($req, (string) ($items[$mid][$entry['vs']]['units'] ?? $fig['units']), $col, $this->cfg));
				}
			}
			if ($a['value'] === null && isset($entry['fix'])) {
				$fig['fix'] = $entry['fix'];
			}
		}

		// Count over Warn: the level of the worst server, never the count against the thresholds it counts with.
		if ($a['host_levels'] !== null && $source !== Columns::SOURCE_CALC) {
			$fig['level'] = in_array('high', $a['host_levels'], true) ? 'high' : (in_array('warn', $a['host_levels'], true) ? 'warn' : '');
		}
		if ($fig['value'] !== null && $col['stale'] !== '' && $a['newest'] > 0 && $this->now - $a['newest'] > (int) $col['stale'] * 60) {
			$fig['stale'] = _s('No new value for %1$s min (stale after %2$s min)', (int) floor(($this->now - $a['newest']) / 60), $col['stale']);
		}
		if ($a['capped']) {
			$fig['capped'] = _s('The key pattern matches more items than are read (%1$s per server, %2$s in all) — make it narrower.',
				Columns::PATTERN_PER_HOST, Columns::PATTERN_PER_METRIC);
		}
		if ($a['trend_capped']) {
			$fig['capped'] = trim(($fig['capped'] ?? '').' '._s('The widget asks for more trend than one refresh reads (%1$s item-days): this window was not read — shorten it, or narrow the column.',
				ColumnReader::MAX_ITEM_DAYS));
		}
		if ((int) $col['show'] === Columns::SHOW_SPARKLINE && $a['trend'] !== null) {
			$fig['series'] = array_values(array_filter($a['trend']['series'], fn($v) => $v !== null));
		}
		// A level the widget decides itself (Volume's plan thresholds), where the figure has none of its own.
		if ($this->levels !== null && !array_key_exists('level', $fig) && $fig['value'] !== null) {
			$decided = ($this->levels)($col, $entry, (float) $fig['value'], $client);
			if ($decided !== null) {
				$fig['level'] = $decided;
			}
		}
		$fig['url'] = ColumnMath::clickUrl((int) $col['click'], $itemids, $hostids, $mid);
		if (!array_key_exists('sort', $fig)) {
			$fig['sort'] = $fig['value'];
		}
		return $fig;
	}
}
