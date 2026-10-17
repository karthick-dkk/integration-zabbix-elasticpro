<?php declare(strict_types = 0);

namespace Modules\EpOverview\Actions;

foreach (['Store', 'Settings', 'Roles', 'ClientData', 'Forecast'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}
foreach (['CWidgetFieldStrings', 'CWidgetFieldOptionalNumber', 'CWidgetFieldMetrics', 'Metrics', 'Overview', 'WidgetForm'] as $inc) {
	require_once __DIR__.'/../includes/'.$inc.'.php';
}

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CSeverityHelper;
use Modules\EpOverview\Includes\{Metrics, Overview, WidgetForm};
use Modules\EpOverview\Lib\{ClientData, Forecast, Roles, Settings};

/**
 * Client overview: one card per client with the metrics the widget lists, its open problems,
 * used of allocated with a forecast, its servers by role and what is wrong with its data.
 *
 * The clients and their servers are ClientData's — the reading Client resources and Top hosts
 * use. Every threshold that follows ElasticPro -> Settings goes through Settings::usageFor and
 * ClientData::usageClass, so a figure is the same colour here as in every other ElasticPro
 * report; the arithmetic is Overview's, the forecast bands Forecast's.
 *
 * Zabbix is asked a fixed number of times per refresh, never once per client: the master hosts
 * and their macros, the servers, one item.get for every key (a second only when a metric's key
 * is a pattern), one trend.get (history.get instead when the window is under two hours), one
 * problem.get and the trigger.get that says which hosts each problem is on.
 */
class WidgetView extends CControllerDashboardWidgetView {

	/** Items whose newest value says a server is alive, beside whatever the metrics read. */
	private const HEARTBEAT = ['agent.ping', 'system.cpu.util'];

	/** Under this, a window is read from history: trends are hourly. */
	private const HISTORY_UNDER = 7200;

	private const SEVERITY_DEFAULTS = [
		0 => ['Not classified', '97AAB3'], 1 => ['Information', '7499FF'], 2 => ['Warning', 'FFC859'],
		3 => ['Average', 'FFA059'], 4 => ['High', 'E97659'], 5 => ['Disaster', 'E45959']
	];

	private array $cfg;
	private array $roles;
	private array $f;
	private int $now;

	protected function doAction(): void {
		$this->cfg = Settings::all();
		$this->roles = Roles::load();
		$this->f = $this->fields_values;
		$this->now = time();
		$data = [
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'error' => null,
			'overview' => null,
			'user' => ['debug_mode' => $this->getDebugMode()]
		];
		try {
			$data['overview'] = $this->overview();
		}
		catch (OverviewError $e) {
			$data['error'] = $e->getMessage();
		}
		$this->setResponse(new CControllerResponseData($data));
	}

	private function overview(): array {
		$f = $this->f;
		$hideInactive = (int) ($f['hide_inactive'] ?? 1) === 1;
		[$masters, $clients, $error] = ClientData::clients($this->cfg, $f['groupids'] ?? [], !$hideInactive);
		if ($error !== null) {
			throw new OverviewError($error);
		}
		if ($hideInactive) {
			$clients = array_filter($clients, fn($c) => ($c['status'] ?? 'active') === 'active');
		}
		$want = array_map('mb_strtolower', $f['clients'] ?? []);
		$types = $f['client_types'] ?? [];
		$picked = array_filter($clients, fn($c) => (!$want || in_array(mb_strtolower($c['name']), $want, true))
			&& (!$types || in_array($c['type'], $types, true)));
		if ($want && !array_filter($clients, fn($c) => in_array(mb_strtolower($c['name']), $want, true))) {
			throw new OverviewError(count($want) === 1
				? _s('No client named "%1$s" here. Check the name in ElasticPro → Clients, or change the Clients setting.', $f['clients'][0])
				: _('None of the clients this widget names is here. Check the names in ElasticPro → Clients, or change the Clients setting.'));
		}
		$clients = $picked;

		$metrics = $f['metrics'] ?? [];
		$catalogue = Metrics::catalogue($this->roles);
		$servers = ClientData::servers($this->roles, $clients, false);
		$measure = $this->measure((string) ($f['used_measure'] ?? 'storage'));

		// What to read: per client, per metric, the hosts and keys.
		$plan = [];
		$exact = [];
		$patterns = [];
		foreach ($clients as $mid => $c) {
			$list = $servers[$c['name']] ?? [];
			foreach ($metrics as $m) {
				$plan[$mid][$m['uid']] = $this->targets($m, $catalogue, $mid, $list);
				foreach ($plan[$mid][$m['uid']]['want'] as [$hostid, $key, $pattern]) {
					if ($pattern) {
						$patterns[$key][$hostid] = true;
					}
					else {
						$exact[$key][$hostid] = true;
					}
				}
			}
			foreach (array_filter([$measure['used'], $measure['allocated'], $measure['pct'], $measure['full_in']]) as $key) {
				$exact[$key][$mid] = true;
			}
			foreach ($list as $s) {
				foreach (self::HEARTBEAT as $key) {
					$exact[$key][$s['hostid']] = true;
				}
			}
		}
		$items = $this->items($exact, $patterns);

		// Trends: every item a metric shows a trend or a sparkline for, and each client's
		// percentage the forecast is drawn from.
		[$wFrom, $wTill, $wLabel] = $this->window();
		$fcDays = (int) ($f['forecast_days'] ?? 7);
		$want = [];
		foreach ($clients as $mid => $c) {
			foreach ($metrics as $m) {
				if (!$m['trend_avg'] && !$m['trend_min'] && !$m['trend_max'] && (int) $m['display'] !== Metrics::DISPLAY_SPARKLINE) {
					continue;
				}
				[$from, $till] = $m['window'] > 0 ? [$this->now - $m['window'] * 86400, $this->now] : [$wFrom, $wTill];
				foreach ($this->matched($plan[$mid][$m['uid']], $items) as $it) {
					$want[$it['itemid']] = ['from' => min($from, $want[$it['itemid']]['from'] ?? PHP_INT_MAX),
						'till' => max($till, $want[$it['itemid']]['till'] ?? 0), 'value_type' => (int) $it['value_type']];
				}
			}
			if ($measure['pct'] !== null && isset($items[$mid][$measure['pct']])) {
				$it = $items[$mid][$measure['pct']];
				$want[$it['itemid']] = ['from' => min($this->now - $fcDays * 86400, $want[$it['itemid']]['from'] ?? PHP_INT_MAX),
					'till' => $this->now, 'value_type' => (int) $it['value_type']];
			}
		}
		$series = $this->series($want);

		// Problems on every host of every client shown: master and servers.
		$hostClient = [];
		foreach ($clients as $mid => $c) {
			$hostClient[$mid] = $mid;
			foreach ($servers[$c['name']] ?? [] as $s) {
				$hostClient[$s['hostid']] = $mid;
			}
		}
		$severities = array_map('intval', $f['severities'] ?? Overview::SEVERITIES_ON);
		$ack = (int) ($f['problems_ack'] ?? 0) === 1;
		$suppressed = (int) ($f['problems_suppressed'] ?? 0) === 1;
		$problems = $this->problems(array_keys($hostClient), $hostClient, $severities, $ack, $suppressed);

		$rows = [];
		foreach ($clients as $mid => $c) {
			$rows[] = $this->client($mid, $c, $servers[$c['name']] ?? [], $metrics, $catalogue, $plan[$mid] ?? [], $items, $series,
				[$wFrom, $wTill], $measure, $problems[$mid] ?? [], $severities, $ack, $suppressed);
		}
		$orderBy = $this->orderKey((string) ($f['order_by'] ?? 'name'), $metrics);
		$top = ($f['top_n'] ?? '') === '' ? null : (int) $f['top_n'];
		$total = count($rows);
		$rows = Overview::arrange($rows, $orderBy, (int) ($f['order_dir'] ?? Overview::ORDER_ASC), (int) ($f['only_show'] ?? Overview::ONLY_ALL), $top);
		foreach ($rows as &$r) {
			unset($r['sort']);
		}
		unset($r);

		return [
			'clients' => $rows,
			'total' => $total,
			'summary' => $this->summary($rows, $severities),
			'metrics' => array_map(fn($m) => ['uid' => $m['uid'], 'name' => $m['name']], $metrics),
			'settings' => $this->settings($wLabel, $measure, $severities)
		];
	}

	/**
	 * Where one metric is read for one client: [hostid, key, is pattern] for each host, and how
	 * many servers it should come from.
	 */
	private function targets(array $m, array $catalogue, string $mid, array $servers): array {
		$out = ['want' => [], 'on' => Metrics::ON_SERVERS, 'servers' => 0];
		if ((int) $m['source'] === Metrics::SOURCE_BUILTIN) {
			$b = $catalogue[$m['builtin']] ?? null;
			if ($b === null) {
				return $out;
			}
			if ($b['on'] === Metrics::ON_MASTER) {
				return ['want' => [[$mid, $b['key'], false]], 'on' => Metrics::ON_MASTER, 'servers' => 1];
			}
		}
		$picked = array_values(array_filter($servers, fn($s) => !$m['roles'] || array_intersect($m['roles'], $s['roles'])));
		$out['servers'] = count($picked);
		foreach ($picked as $s) {
			if ((int) $m['source'] === Metrics::SOURCE_ITEM) {
				$out['want'][] = [$s['hostid'], $m['key'], (bool) $m['pattern']];
			}
			elseif ($m['builtin'] === 'srv.disk') {
				foreach ($s['disks'] as $d) {
					$out['want'][] = [$s['hostid'], Metrics::diskKey($d['mount']), false];
				}
			}
			else {
				$out['want'][] = [$s['hostid'], $catalogue[$m['builtin']]['key'], false];
			}
		}
		return $out;
	}

	/** The items one metric reads for one client, as found. */
	private function matched(array $target, array $items): array {
		$out = [];
		foreach ($target['want'] as [$hostid, $key, $pattern]) {
			if (!$pattern) {
				if (isset($items[$hostid][$key])) {
					$out[$items[$hostid][$key]['itemid']] = $items[$hostid][$key];
				}
				continue;
			}
			foreach ($items[$hostid] ?? [] as $k => $it) {
				if (Metrics::keyMatches((string) $k, $key, true)) {
					$out[$it['itemid']] = $it;
				}
			}
		}
		return array_values($out);
	}

	/** One item.get for every exact key on every host, one more for the patterns. Numeric items only. */
	private function items(array $exact, array $patterns): array {
		$out = [];
		$output = ['itemid', 'hostid', 'key_', 'name', 'lastvalue', 'lastclock', 'state', 'value_type', 'units'];
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
			$index(API::Item()->get(['output' => $output, 'hostids' => $hostids, 'filter' => ['key_' => array_keys($exact)]]), $exact);
		}
		if ($patterns) {
			$hostids = array_keys(array_replace(...array_values($patterns)));
			$rows = API::Item()->get(['output' => $output, 'hostids' => $hostids, 'search' => ['key_' => array_keys($patterns)],
				'searchByAny' => true, 'searchWildcardsEnabled' => true]);
			foreach ($rows ?: [] as $it) {
				foreach ($patterns as $p => $hosts) {
					if (isset($hosts[$it['hostid']]) && Metrics::keyMatches($it['key_'], $p, true)) {
						$out[$it['hostid']][$it['key_']] = $it;
					}
				}
			}
		}
		return $out;
	}

	/** The widget's trend window: [from, till, header label]. */
	private function window(): array {
		$f = $this->f;
		if ((int) ($f['trend_source'] ?? WidgetForm::TREND_DAYS) === WidgetForm::TREND_DASHBOARD
				&& isset($f['time_period']['from_ts'], $f['time_period']['to_ts']) && (int) $f['time_period']['to_ts'] > (int) $f['time_period']['from_ts']) {
			$from = (int) $f['time_period']['from_ts'];
			$till = min((int) $f['time_period']['to_ts'], $this->now);
			$span = $till - $from;
			$label = $span % 86400 < 60 && $span >= 86340 ? round($span / 86400).'d'
				: ($span % 3600 < 60 && $span >= 3540 ? round($span / 3600).'h' : _('period'));
			return [$from, $till, $label];
		}
		$days = (int) ($f['trend_days'] ?? 7);
		return [$this->now - $days * 86400, $this->now, $days.'d'];
	}

	/**
	 * Every item's points in its window: clock => [avg, min, max]. One trend.get for all of
	 * them; items whose window is under two hours come from history instead (trends are hourly),
	 * one history.get per value type.
	 */
	private function series(array $want): array {
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
			foreach ($rows ?: [] as $r) {
				$v = (float) $r['value'];
				$out[$r['itemid']][(int) $r['clock']] = [$v, $v, $v];
			}
		}
		return $out;
	}

	/** Open problems per client and severity: one problem.get, one trigger.get for their hosts. */
	private function problems(array $hostids, array $hostClient, array $severities, bool $ack, bool $suppressed): array {
		if (!$hostids || !$severities) {
			return [];
		}
		$options = ['output' => ['eventid', 'objectid', 'severity', 'acknowledged', 'suppressed'], 'hostids' => $hostids,
			'source' => 0, 'object' => 0, 'severities' => $severities, 'symptom' => false];
		if (!$ack) {
			$options['acknowledged'] = false;
		}
		if (!$suppressed) {
			$options['suppressed'] = false;
		}
		$problems = API::Problem()->get($options) ?: [];
		if (!$problems) {
			return [];
		}
		$triggerHosts = [];
		foreach (API::Trigger()->get(['output' => ['triggerid'], 'triggerids' => array_values(array_unique(array_column($problems, 'objectid'))),
				'selectHosts' => ['hostid'], 'preservekeys' => true]) ?: [] as $tid => $t) {
			$triggerHosts[$t['triggerid'] ?? $tid] = array_column($t['hosts'] ?? [], 'hostid');
		}
		return Overview::countProblems($problems, $triggerHosts, $hostClient, $severities, $ack, $suppressed);
	}

	/**
	 * What used of allocated measures. ES storage by default; a family's disk or memory, or
	 * devices seen of purchased, when chosen. Each names the items its x of y and its share come
	 * from, and what to set when allocated is missing.
	 */
	private function measure(string $id): array {
		if ($id === 'devices') {
			return ['id' => 'devices', 'label' => _('Devices seen of purchased'), 'used' => 'ep.devices.seen', 'allocated' => 'ep.devices.purchased',
				'pct' => 'ep.devices.usage', 'units' => '', 'full_in' => null, 'from_pct' => false,
				'fix' => _('set Devices purchased for the client in Cluster Management')];
		}
		if (preg_match('/^fam\.([a-z0-9_]+)\.(disk|mem)$/', $id, $m)) {
			foreach ($this->roles['families'] as $fam) {
				if ($fam['id'] === $m[1]) {
					return $m[2] === 'disk'
						? ['id' => $id, 'label' => _s('%1$s disk used of allocated', $fam['label']), 'used' => 'ep.'.$fam['id'].'.disk.used',
							'allocated' => 'ep.'.$fam['id'].'.disk.allocated', 'pct' => 'ep.'.$fam['id'].'.disk.usage', 'units' => 'B', 'full_in' => null,
							'from_pct' => false, 'fix' => _s('set the %1$s disk mounts in Cluster Management and write the master template', $fam['label'])]
						: ['id' => $id, 'label' => _s('%1$s memory used of allocated', $fam['label']), 'used' => null,
							'allocated' => 'ep.'.$fam['id'].'.mem.allocated', 'pct' => 'ep.'.$fam['id'].'.mem.usage', 'units' => 'B', 'full_in' => null,
							'from_pct' => true, 'fix' => _s('link the Linux agent template to the %1$s servers so their memory is measured', $fam['label'])];
				}
			}
		}
		return ['id' => 'storage', 'label' => _('ES storage used of allocated'), 'used' => 'ep.es.storage.used', 'allocated' => 'ep.es.storage.allocated',
			'pct' => 'ep.es.storage.usage', 'units' => 'B', 'full_in' => 'ep.es.storage.full_in', 'from_pct' => false,
			'fix' => _('monitor the client\'s cluster host with Elasticsearch Cluster by HTTP EP so ES storage allocated is measured')];
	}

	/** A known item's value, or null: the one test of "known" every ElasticPro report uses. */
	private static function value(?array $it): ?float {
		return $it !== null && ClientData::known($it) ? (float) $it['lastvalue'] : null;
	}

	private function client(string $mid, array $c, array $servers, array $metrics, array $catalogue, array $plan, array $items, array $series,
			array $window, array $measure, array $problems, array $severities, bool $ack, bool $suppressed): array {
		$f = $this->f;
		$staleWidget = (int) ($f['stale_after'] ?? 10);
		$downAfter = (int) ($f['down_after'] ?? 5) * 60;
		$warnNodata = (int) ($f['warn_nodata'] ?? 1) === 1;
		$warnings = [];
		$sort = [];
		$over = false;
		$names = array_column($servers, 'host', 'hostid');

		// Metrics.
		$rows = [];
		foreach ($metrics as $m) {
			$target = $plan[$m['uid']] ?? ['want' => [], 'on' => Metrics::ON_SERVERS, 'servers' => 0];
			$found = $this->matched($target, $items);
			$usage = Settings::usageFor($c['macros'], $m['warn'] === '' ? Settings::INHERIT : $m['warn'],
				$m['high'] === '' ? Settings::INHERIT : $m['high'], $this->cfg);
			$levelOf = fn(?float $v) => Overview::level($v, $m, $usage);
			$overWarn = fn(float $v) => $levelOf($v)['level'] !== '';
			$master = $target['on'] === Metrics::ON_MASTER;
			$fn = $master ? Metrics::COMBINE_MAX : (int) $m['combine'];
			$numeric = array_filter($found, fn($it) => in_array((int) $it['value_type'], [0, 3], true));
			$values = array_map([self::class, 'value'], $numeric);
			$now = Overview::combine($values, $fn, $overWarn);
			$fromServers = count(array_unique(array_map(fn($it) => $it['hostid'], array_filter($numeric, fn($it) => self::value($it) !== null))));
			$newest = $numeric ? max(array_map(fn($it) => (int) $it['lastclock'], $numeric)) : 0;
			$staleMin = $m['stale'] === '' ? $staleWidget : (int) $m['stale'];
			$stale = $now !== null && $newest > 0 && $this->now - $newest > $staleMin * 60;
			$decimals = $m['decimals'] === '' ? Settings::decimals($m['units'], $this->cfg) : (int) $m['decimals'];
			$units = (int) $m['combine'] === Metrics::COMBINE_COUNT && !$master ? '' : $m['units'];
			$fmt = fn(?float $v) => Overview::format($v, $units, (int) $m['combine'] === Metrics::COMBINE_COUNT && !$master ? 0 : $decimals, (bool) $m['short']);

			$why = null;
			if ($now === null) {
				$why = $this->notCollected($m, $catalogue, $target, $found, $numeric);
				if ($warnNodata) {
					$warnings[] = ['level' => 'info', 'text' => _s('%1$s is not collected — %2$s', $m['name'], $why)];
				}
				if ((int) $m['nodata'] === Metrics::NODATA_HIDE) {
					$sort['m:'.$m['uid'].':now'] = null;
					$sort['m:'.$m['uid'].':max'] = null;
					continue;
				}
			}
			$lv = $levelOf($now);
			if ($lv['level'] !== '') {
				$over = true;
			}

			// The trend: the metric's own window, or the widget's.
			[$from, $till] = $m['window'] > 0 ? [$this->now - $m['window'] * 86400, $this->now] : $window;
			$trend = Overview::trend(array_intersect_key($series, array_flip(array_column($numeric, 'itemid'))), $fn, $from, $till, $overWarn);
			$sort['m:'.$m['uid'].':now'] = $now;
			$sort['m:'.$m['uid'].':max'] = $trend['max'] ?? null;

			$row = [
				'uid' => $m['uid'], 'name' => $m['name'], 'custom' => (int) $m['source'] === Metrics::SOURCE_ITEM,
				'display' => (int) $m['display'],
				'now' => ['text' => $fmt($now), 'level' => $lv['level'], 'color' => $lv['color'], 'known' => $now !== null, 'stale' => $stale,
					'age' => $newest > 0 ? $this->now - $newest : null],
				'why' => $why,
				'window' => $m['window'] > 0 ? $m['window'].'d' : null,
				'partial' => !$master && $now !== null && $fromServers < $target['servers'] ? [$fromServers, $target['servers']] : null
			];
			foreach (['avg', 'min', 'max'] as $k) {
				if ($m['trend_'.$k]) {
					$v = $trend[$k] ?? null;
					$row[$k] = ['text' => $trend === null ? ($now === null ? '' : _('no trend')) : $fmt($v), 'level' => $levelOf($v)['level']];
				}
			}
			if ((int) $m['display'] === Metrics::DISPLAY_SPARKLINE && $trend !== null) {
				$row['spark'] = Overview::downsample($trend['series']);
			}
			if (in_array((int) $m['display'], [Metrics::DISPLAY_BAR, Metrics::DISPLAY_INDICATORS], true) && $now !== null) {
				$min = $m['min'] === '' ? 0.0 : (float) $m['min'];
				$max = $m['max'] !== '' ? (float) $m['max'] : ($units === '%' ? 100.0 : max($now, $trend['max'] ?? $now));
				$row['bar'] = $max > $min ? round(max(0.0, min(1.0, ($now - $min) / ($max - $min))) * 100, 1) : null;
			}
			if ($stale) {
				$warnings[] = ['level' => 'warn', 'text' => _s('%1$s: no new value for %2$s min (stale after %3$s min)', $m['name'],
					(int) floor(($this->now - $newest) / 60), $staleMin)];
			}
			if ($row['partial'] !== null && $warnNodata) {
				$warnings[] = ['level' => 'info', 'text' => _s('%1$s is from %2$s of %3$s servers — the others do not report it', $m['name'],
					$row['partial'][0], $row['partial'][1])];
			}
			$row['url'] = $this->metricUrl((int) $m['click'], array_column($numeric, 'itemid'), array_unique(array_column($found, 'hostid')));
			$rows[] = $row;
		}

		// Servers: up, down (agent unavailable, or nothing new for N minutes), by role.
		$down = [];
		$lastSeen = [];
		foreach ($servers as $s) {
			if (!empty($items[$s['hostid']])) {
				$lastSeen[$s['hostid']] = max(array_map(fn($it) => (int) $it['lastclock'], $items[$s['hostid']]));
			}
		}
		foreach ($servers as $s) {
			if ($s['status'] === 'down') {
				$down[$s['hostid']] = 'agent';
			}
			elseif (isset($lastSeen[$s['hostid']]) && $lastSeen[$s['hostid']] > 0 && $this->now - $lastSeen[$s['hostid']] > $downAfter) {
				$down[$s['hostid']] = 'nodata';
			}
		}
		if ((int) ($f['warn_agent'] ?? 1) === 1) {
			$agent = array_keys(array_filter($down, fn($why) => $why === 'agent'));
			if (count($agent) > 3) {
				$warnings[] = ['level' => 'warn', 'text' => _s('Zabbix agent unreachable on %1$s of %2$s servers', count($agent), count($servers))];
			}
			else {
				foreach ($agent as $hostid) {
					$warnings[] = ['level' => 'warn', 'text' => _s('%1$s: Zabbix agent unreachable', $names[$hostid])];
				}
			}
		}
		foreach (array_keys(array_filter($down, fn($why) => $why === 'nodata')) as $hostid) {
			$warnings[] = ['level' => 'warn', 'text' => _s('%1$s: no data for %2$s min (down after %3$s min)', $names[$hostid],
				(int) floor(($this->now - $lastSeen[$hostid]) / 60), (int) ($f['down_after'] ?? 5))];
		}
		if (!$servers) {
			$warnings[] = ['level' => 'info', 'text' => _('No servers found in the client\'s host group with a role from Cluster Management.')];
		}
		if (!empty($c['maintenance']) && (int) ($f['warn_maintenance'] ?? 1) === 1) {
			$warnings[] = ['level' => 'info', 'text' => _('In maintenance: its problems may be suppressed.')];
		}

		// Problems.
		$sev = [];
		$total = 0;
		$worst = null;
		foreach (Overview::SEVERITIES as $s) {
			if (!in_array($s, $severities, true)) {
				continue;
			}
			$n = (int) ($problems[$s] ?? 0);
			$total += $n;
			if ($n > 0 && $worst === null) {
				$worst = $s;
			}
			$sev[] = ['severity' => $s, 'n' => $n];
		}
		$hostids = array_merge([$mid], array_column($servers, 'hostid'));
		$sort['problems'] = $total > 0 ? $worst * 1000000 + $total : 0;

		// Used of allocated, and when it fills.
		$used = $this->used($mid, $c, $items, $series, $measure);
		$sort['full'] = $used['forecast'] === null ? null : ($used['forecast']['days'] ?? 1e9);
		$contract = Overview::contract(ClientData::contractEnd($c['macros']), $this->now, (int) ($f['contract_warn'] ?? 60), (int) ($f['contract_high'] ?? 14));
		$sort['contract'] = $contract['days'] ?? null;

		return [
			'name' => $c['name'], 'type' => $c['type'], 'badge' => $c['badge'], 'status' => $c['status'] ?? 'active',
			'maintenance' => !empty($c['maintenance']),
			'link' => $this->clientUrl($mid, $c['name']),
			'dashboard' => Overview::url('zabbix.php', ['action' => 'host.dashboard.view', 'hostid' => $mid]),
			'problems_url' => Overview::problemsUrl($hostids, $severities, $ack, $suppressed),
			'worst' => $worst,
			'contract' => $contract,
			'problems' => array_map(fn($p) => $p + ['url' => Overview::problemsUrl($hostids, [$p['severity']], $ack, $suppressed)], $sev),
			'metrics' => $rows,
			'used' => $used,
			'roles' => $this->roles($servers, $down),
			'servers' => ['total' => count($servers), 'down' => count($down)],
			'warnings' => $warnings,
			'over' => $over,
			'problems_total' => $total,
			'sort' => $sort
		];
	}

	/** Why a metric has no value for a client, and what would give it one. */
	private function notCollected(array $m, array $catalogue, array $target, array $found, array $numeric): string {
		if ($target['on'] === Metrics::ON_SERVERS && $target['servers'] === 0) {
			return $m['roles'] ? _('the client has no servers in the chosen roles') : _('the client has no servers with a role');
		}
		if ($found && !$numeric) {
			return _('the item is not numeric');
		}
		if ($found) {
			return _('no value yet (or the item is unsupported) — check it in Latest data');
		}
		if ((int) $m['source'] === Metrics::SOURCE_BUILTIN) {
			return lcfirst(rtrim($catalogue[$m['builtin']]['fix'] ?? '', '.'));
		}
		return _s('add the item %1$s to these servers (for example, link a template that has it)', $m['key']);
	}

	/** Used of allocated for one client: x of y, the share, its colour, and the forecast or why there is none. */
	private function used(string $mid, array $c, array $items, array $series, array $measure): array {
		$f = $this->f;
		$pct = self::value($items[$mid][$measure['pct']] ?? null);
		$allocated = self::value($items[$mid][$measure['allocated']] ?? null);
		$used = $measure['from_pct']
			? ($pct !== null && $allocated !== null ? $pct / 100 * $allocated : null)
			: self::value($items[$mid][$measure['used']] ?? null);
		if ($allocated !== null && $allocated <= 0) {
			$allocated = null;
		}
		if ($pct === null && $used !== null && $allocated !== null) {
			$pct = $used / $allocated * 100;
		}
		if ($allocated === null) {
			$pct = null;
		}
		$usage = Settings::usageFor($c['macros'], Settings::INHERIT, Settings::INHERIT, $this->cfg);
		$decimals = Settings::decimals($measure['units'], $this->cfg);
		$fmt = fn(?float $v) => Overview::format($v, $measure['units'], $decimals, true);
		$windows = Settings::forecast($this->cfg);
		if (($f['forecast_warn'] ?? '') !== '') {
			$windows['warn_days'] = (int) $f['forecast_warn'];
		}
		if (($f['forecast_high'] ?? '') !== '') {
			$windows['bad_days'] = min((int) $f['forecast_high'], $windows['warn_days']);
		}
		$forecast = null;
		$why = null;
		if ($allocated === null) {
			$why = _s('No forecast: allocated is not set — %1$s.', $measure['fix']);
		}
		else {
			$itemid = $items[$mid][$measure['pct']]['itemid'] ?? null;
			$days = (int) ($f['forecast_days'] ?? 7);
			$s = [];
			foreach ($itemid !== null ? ($series[$itemid] ?? []) : [] as $clock => $p) {
				if ($clock >= $this->now - $days * 86400) {
					$s[$clock] = $p[0];
				}
			}
			$forecast = Overview::forecast($s, $pct, $windows);
			// The master template's own reading over seven days, when the trend is too short to fit a line.
			if ($forecast === null && $measure['full_in'] !== null && $days === 7) {
				$fi = self::value($items[$mid][$measure['full_in']] ?? null);
				if ($fi !== null) {
					$forecast = Forecast::fullIn($fi, $windows) + ['date' => $fi >= 0 ? $this->now + (int) $fi : null];
				}
			}
			if ($forecast === null) {
				$why = _s('No forecast yet: needs at least six hours of trend in the last %1$s days.', $days);
			}
		}
		return [
			'label' => $measure['label'],
			'used' => $fmt($used), 'allocated' => $allocated === null ? null : $fmt($allocated),
			'pct' => $pct === null ? null : round($pct, 1),
			'pct_text' => $pct === null ? null : Overview::format($pct, '%', Settings::decimals('%', $this->cfg), false),
			'level' => $pct === null ? '' : ['ep-high' => 'high', 'ep-warn' => 'warn', '' => ''][ClientData::usageClass($pct, $usage['warn'], $usage['high'])],
			'known' => $used !== null,
			'forecast' => $forecast === null ? null : ['text' => $forecast['text'], 'level' => $forecast['level'], 'days' => $forecast['days'],
				'date' => $forecast['date'] ?? null],
			'why' => $why
		];
	}

	/** Servers by role, in the widget's role order, without the roles it hides; new roles at the end. */
	private function roles(array $servers, array $down): array {
		$out = [];
		foreach ($this->roleOrder() as $r) {
			$held = array_values(array_filter($servers, fn($s) => in_array($r['id'], $s['roles'], true)));
			if (!$held) {
				continue;
			}
			$out[] = [
				'id' => $r['id'], 'label' => $r['label'], 'n' => count($held),
				'down' => count(array_filter($held, fn($s) => isset($down[$s['hostid']]))),
				'hosts' => array_map(fn($s) => ['name' => $s['host'], 'down' => isset($down[$s['hostid']])], $held)
			];
		}
		return $out;
	}

	/** Every role in the order the widget keeps, roles it does not know yet appended; hidden ones left out. */
	private function roleOrder(): array {
		$all = Roles::byId($this->roles);
		$order = array_values(array_intersect($this->f['role_order'] ?? [], array_keys($all)));
		$order = array_merge($order, array_values(array_diff(array_keys($all), $order)));
		$hidden = $this->f['roles_hidden'] ?? [];
		return array_map(fn($id) => $all[$id], array_values(array_diff($order, $hidden)));
	}

	private function metricUrl(int $click, array $itemids, array $hostids): ?string {
		if ($click === Metrics::CLICK_GRAPH && $itemids) {
			return count($itemids) === 1
				? Overview::url('history.php', ['action' => 'showgraph', 'itemids' => array_values($itemids)])
				: Overview::url('history.php', ['action' => 'batchgraph', 'graphtype' => 0, 'itemids' => array_values($itemids)]);
		}
		if ($click === Metrics::CLICK_LATEST && $hostids) {
			return Overview::url('zabbix.php', ['action' => 'latest.view', 'filter_set' => 1, 'hostids' => array_values($hostids)]);
		}
		return null;
	}

	private function clientUrl(string $mid, string $name): string {
		switch ((int) ($this->f['link_client'] ?? WidgetForm::LINK_DASHBOARD)) {
			case WidgetForm::LINK_ELASTICPRO:
				return Overview::url('zabbix.php', ['action' => 'elasticpro.overview']);
			case WidgetForm::LINK_CLUSTER_MANAGEMENT:
				return Overview::url('zabbix.php', ['action' => 'ep.clients.edit', 'client' => $name]);
			default:
				return Overview::url('zabbix.php', ['action' => 'host.dashboard.view', 'hostid' => $mid]);
		}
	}

	/** The sort key the widget names, or the client name when it names a metric that has gone. */
	private function orderKey(string $key, array $metrics): string {
		if (in_array($key, ['name', 'problems', 'full', 'contract'], true)) {
			return $key;
		}
		if (preg_match('/^m:([a-z0-9]{1,16}):(now|max)$/', $key, $m) && in_array($m[1], array_column($metrics, 'uid'), true)) {
			return $key;
		}
		return 'name';
	}

	/** Totals across the clients shown, for the summary bar. */
	private function summary(array $rows, array $severities): array {
		$sev = array_fill_keys($severities, 0);
		foreach ($rows as $r) {
			foreach ($r['problems'] as $p) {
				$sev[$p['severity']] += $p['n'];
			}
		}
		return [
			'clients' => count($rows),
			'servers' => array_sum(array_map(fn($r) => $r['servers']['total'], $rows)),
			'down' => array_sum(array_map(fn($r) => $r['servers']['down'], $rows)),
			'over' => count(array_filter($rows, fn($r) => $r['over'])),
			'problems' => array_map(fn($s) => ['severity' => $s, 'n' => $sev[$s]], array_values(array_filter(Overview::SEVERITIES, fn($s) => isset($sev[$s]))))
		];
	}

	private function settings(string $windowLabel, array $measure, array $severities): array {
		$f = $this->f;
		$order = array_values(array_unique(array_merge(array_intersect($f['section_order'] ?? Overview::SECTIONS, Overview::SECTIONS), Overview::SECTIONS)));
		$on = $f['sections'] ?? Overview::SECTIONS_ON;
		$names = [];
		foreach (Overview::SEVERITIES as $s) {
			$names[$s] = [
				'name' => class_exists('CSeverityHelper') ? CSeverityHelper::getName($s) : self::SEVERITY_DEFAULTS[$s][0],
				'color' => '#'.ltrim((string) (class_exists('CSeverityHelper') ? CSeverityHelper::getColor($s) : self::SEVERITY_DEFAULTS[$s][1]), '#')
			];
		}
		return [
			'sections' => array_values(array_filter($order, fn($s) => in_array($s, $on, true))),
			'columns' => (int) ($f['columns'] ?? 0),
			'card_width' => (int) ($f['card_width'] ?? 340),
			'density' => (int) ($f['density'] ?? WidgetForm::DENSITY_COMFORTABLE),
			'window' => $windowLabel,
			'severities' => $names,
			'hide_zero' => (int) ($f['hide_zero'] ?? 1) === 1,
			'link_severity' => (int) ($f['link_severity'] ?? WidgetForm::LINK_SEVERITY_PROBLEMS) === WidgetForm::LINK_SEVERITY_PROBLEMS,
			'new_tab' => (int) ($f['link_target'] ?? 0) === 1,
			'used_show' => (int) ($f['used_show'] ?? WidgetForm::USED_SHOW_OF),
			'used_style' => (int) ($f['used_style'] ?? WidgetForm::USED_BAR),
			'contract_badge' => (int) ($f['contract_badge'] ?? 1) === 1,
			'forecast_days' => (int) ($f['forecast_days'] ?? 7),
			'roles_style' => (int) ($f['roles_style'] ?? WidgetForm::ROLES_PILLS),
			'roles_servers' => (int) ($f['roles_servers'] ?? WidgetForm::SERVERS_COUNT),
			'down_red' => (int) ($f['down_red'] ?? 1) === 1,
			'types' => Settings::types($this->cfg)['all']
		];
	}
}

/** A reason the overview cannot be drawn at all, said in the widget instead of the cards. */
class OverviewError extends \RuntimeException {}
