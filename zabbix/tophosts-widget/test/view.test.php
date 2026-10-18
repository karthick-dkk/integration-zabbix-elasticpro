<?php
/**
 * The Top hosts controller against a fake Zabbix: the servers ClientData reads, each ranked by the
 * figure the widget is set to (CPU, memory, disk used, JVM heap or any item; now or over the window)
 * and judged at its own client's thresholds, a pane for every metric a viewer may switch to, the
 * columns of a row through the column model, how a widget saved before 2.1.1 (Client, Client type,
 * Rank by, Hosts, Warn / High) is read onto the new settings, and what it says when the named client
 * is not there.
 *   docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php test/view.test.php
 */
namespace Zabbix\Widgets {
	class CWidgetForm {
		protected array $values = [];
		public function __construct(array $values = [], $templateid = null) { $this->values = $this->normalizeValues($values); }
		protected function normalizeValues(array $values): array { return $values; }
	}
	/** Just enough of Zabbix's base field for the module's own fields. */
	abstract class CWidgetField {
		protected string $name;
		protected ?string $label;
		protected $value = null;
		protected $default = null;
		public function __construct(string $name, ?string $label = null) { $this->name = $name; $this->label = $label; }
		public function getValue() { return $this->value ?? $this->default; }
		public function setValue($value): self { $this->value = $value; return $this; }
		public function getDefault() { return $this->default; }
		public function setDefault($value): self { $this->default = $value; return $this; }
		protected function setSaveType($t): self { return $this; }
		public function getErrorLabel(): string { return $this->label ?? $this->name; }
	}
}
namespace {
	define('ITEM_STATE_NORMAL', 0); define('INTERFACE_TYPE_AGENT', 1); define('INTERFACE_PRIMARY', 1); define('USER_TYPE_SUPER_ADMIN', 3);
	define('ZBX_WIDGET_FIELD_TYPE_INT32', 0); define('ZBX_WIDGET_FIELD_TYPE_STR', 1);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	if (!function_exists('_')) { function _($s) { return $s; } }
	if (!function_exists('_s')) { function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace('%'.($i + 1).'$s', (string) $v, $s); } return $s; } }
	if (!function_exists('_n')) { function _n($a, $b, $n) { return str_replace('%1$s', (string) $n, $n == 1 ? $a : $b); } }
	function getSubGroups($ids) { return $ids; }
	function convertUnits(array $o) { return round($o['value'], $o['decimals'] ?? 2).($o['units'] !== '' ? ' '.$o['units'] : ''); }

	class CControllerDashboardWidgetView {
		public $fields_values = ['groupids' => []];
		public $widget;
		public $response;
		public function __construct() { $this->widget = new class { public function getDefaultName() { return 'Top hosts'; } }; }
		public function getInput($k, $d = null) { return $d; }
		public function getDebugMode() { return 0; }
		public function getUserType() { return USER_TYPE_SUPER_ADMIN; }
		public function setResponse($r) { $this->response = $r; }
	}
	class CControllerResponseData { public $data; public function __construct($d) { $this->data = $d; } public function disableView(): self { return $this; } }
	class CController {
		public array $input = [];
		public $response;
		public function __construct(array $input = []) { $this->input = $input; }
		protected function validateInput(array $rules): bool { return true; }
		protected function getInput($k, $d = null) { return $this->input[$k] ?? $d; }
		protected function hasInput($k): bool { return array_key_exists($k, $this->input); }
		protected function getInputAll(): array { return $this->input; }
		protected function getAction(): string { return 'widget.ep_tophosts.column.edit'; }
		protected function getUserType(): int { return USER_TYPE_SUPER_ADMIN; }
		protected function getDebugMode(): int { return 0; }
		protected function disableCsrfValidation(): void {}
		protected function setResponse($r): void { $this->response = $r; }
	}

	/** Two clients: karthi (DI) with three servers, acme (On-Prem) with one and its thresholds lowered; gone is decommissioned. */
	class FakeApi {
		public static $asked = [];
		public static $asked_trend = [];
		public static $trends = [];
		public static function values(): array {
			return [
				[201, 'system.cpu.util', 85, '%'], [201, 'vm.memory.utilization', 40, '%'], [201, 'vfs.fs.dependent.size[/,pused]', 99, '%'],
				[201, 'es.node.jvm.mem.heap_used_percent[n1]', 60, '%'], [201, 'es.node.jvm.mem.heap_used_percent[n2]', 70, '%'], [201, 'es.ingest.rate', 18400, '/s'],
				[201, 'system.uptime', 86400, 'uptime'],
				[202, 'system.cpu.util', 20, '%'], [202, 'vfs.fs.dependent.size[/,pused]', 91, '%'], [202, 'es.ingest.rate', 1600, '/s'],
				[204, 'system.cpu.util', 95, '%'],
				[301, 'system.cpu.util', 35, '%'], [301, 'vm.memory.utilization', 10, '%'], [301, 'vfs.fs.dependent.size[/,pused]', 50, '%']
			];
		}
		public static function itemid(int $host, string $key): string {
			foreach (self::values() as $i => [$h, $k]) { if ($h === $host && $k === $key) { return (string) ($h * 1000 + $i); } }
			return '';
		}
		private $what;
		public function __construct($what) { $this->what = $what; }
		public function get(array $o) {
			$if = fn($ip, $a) => [['ip' => $ip, 'type' => 1, 'main' => 1, 'available' => $a]];
			$g = fn(...$n) => array_map(fn($x) => ['name' => $x], $n);
			switch ($this->what) {
				case 'Template':
					return [['templateid' => 9]];
				case 'Host':
					if (isset($o['templateids'])) {
						return [101 => ['hostid' => 101, 'name' => 'karthi-Master', 'maintenance_status' => 0], 102 => ['hostid' => 102, 'name' => 'acme-Master', 'maintenance_status' => 0],
							103 => ['hostid' => 103, 'name' => 'gone-Master', 'maintenance_status' => 0]];
					}
					return [
						201 => ['hostid' => 201, 'name' => 'karthi-ES-Data-Hot-1', 'hostgroups' => $g('karthi', 'ES Data Hot'), 'interfaces' => $if('10.0.0.1', 1)],
						202 => ['hostid' => 202, 'name' => 'karthi-Parser-1', 'hostgroups' => $g('karthi', 'Parser'), 'interfaces' => $if('10.0.0.2', 2)],
						204 => ['hostid' => 204, 'name' => 'karthi-Forwarder-1', 'hostgroups' => $g('karthi', 'Forwarders'), 'interfaces' => $if('10.0.0.4', 1)],
						301 => ['hostid' => 301, 'name' => 'acme-ES-Data-Hot-1', 'hostgroups' => $g('acme', 'ES Data Hot'), 'interfaces' => $if('10.1.0.1', 1)]
					];
				case 'HostGroup':
					return array_values(array_filter([['groupid' => 1, 'name' => 'karthi'], ['groupid' => 2, 'name' => 'acme'], ['groupid' => 3, 'name' => 'gone']],
						fn($x) => in_array($x['name'], $o['filter']['name'], true)));
				case 'UserMacro':
					return [
						['hostid' => 101, 'macro' => '{$GRP.CLIENT}', 'value' => 'karthi'], ['hostid' => 101, 'macro' => '{$EP.CLIENT.TYPE}', 'value' => 'DI'],
						['hostid' => 102, 'macro' => '{$GRP.CLIENT}', 'value' => 'acme'], ['hostid' => 102, 'macro' => '{$EP.CLIENT.TYPE}', 'value' => 'On-Prem'],
						['hostid' => 102, 'macro' => '{$EP.USAGE.WARN}', 'value' => '30'], ['hostid' => 102, 'macro' => '{$EP.USAGE.HIGH}', 'value' => '40'],
						['hostid' => 103, 'macro' => '{$GRP.CLIENT}', 'value' => 'gone'], ['hostid' => 103, 'macro' => '{$EP.CLIENT.STATUS}', 'value' => 'decommissioned']
					];
				case 'Item':
					$out = [];
					foreach (self::values() as $i => [$h, $k, $v, $u]) {
						if (!in_array($h, $o['hostids'])) { continue; }
						$hit = isset($o['filter']['key_']) && in_array($k, (array) $o['filter']['key_'], true);
						foreach ((array) ($o['search']['key_'] ?? []) as $p) { $hit = $hit || strpos($k, rtrim(explode('*', $p)[0])) === 0; }
						if ($hit) {
							$out[] = ['itemid' => (string) ($h * 1000 + $i), 'hostid' => $h, 'key_' => $k, 'lastvalue' => (string) $v, 'lastclock' => time() - 30, 'state' => 0,
								'units' => $u, 'value_type' => 0, 'name' => $k];
						}
					}
					self::$asked[] = $o;
					return $out;
				case 'Trend':
					self::$asked_trend[] = $o;
					$out = [];
					foreach (self::$trends as $itemid => $points) {
						if (in_array((string) $itemid, array_map('strval', $o['itemids']), true)) {
							foreach ($points as $clock => [$avg, $min, $max]) {
								$out[] = ['itemid' => (string) $itemid, 'clock' => (string) $clock, 'value_avg' => $avg, 'value_min' => $min, 'value_max' => $max];
							}
						}
					}
					return $out;
				case 'History':
					return [];
			}
		}
	}
	class API {
		public static function __callStatic($name, $args) { return new FakeApi($name); }
	}
}
namespace Modules\EpTophosts\Test {
	require __DIR__.'/../lib/Bars.php';
	require __DIR__.'/../actions/WidgetView.php';
	use Modules\EpTophosts\Actions\WidgetView;
	use Modules\EpTophosts\Includes\{Catalogue, WidgetForm};
	use Modules\EpTophosts\Lib\{ColumnSettings, Columns, Roles, Settings};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-top-test-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);

	function run(array $fields = []): array {
		Settings::forget();
		\FakeApi::$asked = [];
		\FakeApi::$asked_trend = [];
		$v = new WidgetView();
		$v->fields_values = WidgetForm::upgrade($fields, Catalogue::columns(), Settings::types(Settings::all())['all']) + ['groupids' => []];
		(new \ReflectionMethod($v, 'doAction'))->invoke($v);
		return $v->response->data;
	}
	$hosts = fn(array $d, string $pane) => array_column($d['panes'][$pane]['rows'], 'host');
	$catalogue = Catalogue::columns();
	$base = Catalogue::defaultColumns($catalogue);
	$GB = 1073741824;

	/* ---------------- a widget nobody has configured, and one saved before 2.1.1 ---------------- */
	$d = run();
	check('no error', $d['error'] === null, $d['error']);
	check('ranked by CPU, with the CPU / Memory switch it always had', $d['rank'] === 'cpu' && array_column($d['metrics'], 'id') === ['cpu', 'mem'] && $d['count'] === 10);
	check('highest first: every client\'s servers, decommissioned clients left out', $hosts($d, 'cpu') === ['karthi-Forwarder-1', 'karthi-ES-Data-Hot-1', 'acme-ES-Data-Hot-1', 'karthi-Parser-1'],
		$hosts($d, 'cpu'));
	check('memory: not measured is left out, never ranked as zero', $hosts($d, 'mem') === ['karthi-ES-Data-Hot-1', 'acme-ES-Data-Hot-1']);
	check('a pane for every metric on offer — switching needs no reload', array_keys($d['panes']) === ['cpu', 'mem']);
	check('a list with bars', $d['view'] === WidgetForm::VIEW_LIST && $d['scope'] === '');
	$r = $d['panes']['cpu']['rows'];
	check('rank, value, client · role, the share the bar is drawn to', $r[0]['rank'] === 1 && $r[0]['value']['text'] === '95 %' && $r[0]['client_role'] === 'karthi · Forwarder'
		&& $r[0]['share'] === 95.0, $r[0]);
	check('each host links to its Latest data', strpos($r[3]['url'], 'latest.view') !== false && strpos($r[3]['url'], '202') !== false && strpos($r[3]['url'], 'zabbix.php') === 0);
	check('colours at the client\'s thresholds: 95 % red, 85 % yellow at the defaults', $r[0]['value']['level'] === 'high' && $r[1]['value']['level'] === 'warn');
	check('acme\'s own thresholds (30 / 40): 35 % CPU is warn there', $r[2]['value']['level'] === 'warn', $r[2]['value']);
	check('a server that reports nothing is not coloured', $r[3]['value']['level'] === '');
	check('the four columns it always had: Rank, Host, Value, Client · Role', array_column($d['columns'], 'builtin') === ['top.rank', 'top.host', 'top.value', 'top.clientrole']);
	check('…and a table row has a cell for each, in order', array_column($r[0]['cells'], 'text') === ['1', 'karthi-Forwarder-1', '95 %', 'karthi · Forwarder'], array_column($r[0]['cells'], 'text'));

	// The settings it stored then.
	$old = WidgetForm::upgrade(['groupids' => ['1'], 'client' => 'ACME', 'client_type' => WidgetForm::typeKey('DI'), 'rank' => 1, 'count' => 3, 'usage_warn' => 0, 'usage_high' => '0'],
		$catalogue, ['CI', 'DI', 'On-Prem']);
	check('Client (a name) is the one client picked, Client type the one type, the thresholds of 0 are empty', $old['clients'] === ['ACME'] && $old['client_types'] === ['DI']
		&& $old['usage_warn'] === '' && $old['usage_high'] === '' && !isset($old['client']) && !isset($old['client_type']) && $old['rank'] === 1 && $old['count'] === 3);
	check('a client picked in the new settings is not overwritten by an old one', WidgetForm::upgrade(['clients' => ['x'], 'client' => 'y'], $catalogue, [])['clients'] === ['x']);
	check('a threshold someone typed is kept', WidgetForm::upgrade(['usage_warn' => '70'], $catalogue, [])['usage_warn'] === '70');
	$d = run(['rank' => 1, 'count' => 3]);
	check('Rank by: memory, three hosts', $d['rank'] === 'mem' && $d['count'] === 3 && $hosts($d, 'mem') === ['karthi-ES-Data-Hot-1', 'acme-ES-Data-Hot-1']);
	$d = run(['rank' => 1, 'count' => 1]);
	check('…at most count', count($d['panes']['mem']['rows']) === 1 && count($d['panes']['cpu']['rows']) === 1);
	$d = run(['client' => 'ACME']);
	check('Client: one client, matched without regard to case', array_unique(array_column($d['panes']['cpu']['rows'], 'host')) === ['acme-ES-Data-Hot-1'] && $d['scope'] === 'ACME', $d['scope']);
	$d = run(['client' => 'nobody']);
	check('a client that is not there is said, not shown as an empty list', strpos((string) $d['error'], 'No client named "nobody"') !== false);
	$d = run(['client_type' => WidgetForm::typeKey('DI')]);
	check('Client type: only that type\'s clients', count($d['panes']['cpu']['rows']) === 3 && $d['scope'] === 'DI');
	check('the stored type number comes from the name, so reordering the site\'s types keeps it', WidgetForm::typeKey('DI') !== WidgetForm::typeKey('On-Prem') && WidgetForm::typeKey('DI') > 0);

	/* ---------------- the new settings ---------------- */
	$d = run(['clients' => ['karthi', 'acme']]);
	check('several clients', count($d['panes']['cpu']['rows']) === 4 && $d['scope'] === 'karthi, acme');
	check('a named client that is not there among several is simply not listed', count(run(['clients' => ['karthi', 'nobody']])['panes']['cpu']['rows']) === 3);
	$d = run(['roles' => ['es_data_hot']]);
	check('Roles: only servers holding them', $hosts($d, 'cpu') === ['karthi-ES-Data-Hot-1', 'acme-ES-Data-Hot-1'] && $d['scope'] === 'ES Data Hot', [$hosts($d, 'cpu'), $d['scope']]);
	$d = run(['clients' => ['karthi'], 'client_types' => ['DI'], 'roles' => ['parser']]);
	check('…together', $hosts($d, 'cpu') === ['karthi-Parser-1']);

	$d = run(['rank' => 2]);
	check('Rank by disk used: the fullest mount of each server', $d['rank'] === 'disk' && $hosts($d, 'disk') === ['karthi-ES-Data-Hot-1', 'karthi-Parser-1', 'acme-ES-Data-Hot-1']
		&& $d['panes']['disk']['rows'][0]['value']['text'] === '99 %', $hosts($d, 'disk'));
	check('…the switch offers it with CPU and Memory', array_column($d['metrics'], 'id') === ['disk', 'cpu', 'mem']);
	$d = run(['rank' => 3]);
	check('Rank by JVM heap: the server\'s worst node item', $hosts($d, 'jvm') === ['karthi-ES-Data-Hot-1'] && $d['panes']['jvm']['rows'][0]['value']['text'] === '70 %');
	$d = run(['rank' => 4, 'rank_key' => 'es.ingest.rate']);
	check('Rank by any item: by its key, in its own units', $hosts($d, 'item') === ['karthi-ES-Data-Hot-1', 'karthi-Parser-1'] && $d['panes']['item']['rows'][0]['value']['text'] === '18400 /s'
		&& $d['metrics'][0]['label'] === 'es.ingest.rate', $d['panes']['item']['rows'][0]['value']['text']);
	check('…not judged at the usage thresholds: it is not a percentage', $d['panes']['item']['rows'][0]['value']['level'] === '');
	$value = array_values(array_filter($base, fn($c) => $c['builtin'] === 'top.value'))[0];
	$value['use_settings'] = 0;
	$value['thresholds'] = [['level' => Columns::LEVEL_WARN, 'color' => '', 'value' => '1000'], ['level' => Columns::LEVEL_HIGH, 'color' => '', 'value' => '5000']];
	$d = run(['rank' => 4, 'rank_key' => 'es.ingest.rate', 'columns' => array_map(fn($c) => $c['builtin'] === 'top.value' ? $value : $c, $base)]);
	check('…unless the Value column has thresholds of its own', $d['panes']['item']['rows'][0]['value']['level'] === 'high' && $d['panes']['item']['rows'][1]['value']['level'] === 'warn',
		[$d['panes']['item']['rows'][0]['value']['level'], $d['panes']['item']['rows'][1]['value']['level']]);
	$d = run(['rank' => 4, 'rank_key' => 'es.node.jvm.mem.heap_used_percent[*]', 'rank_pattern' => 1]);
	check('…a pattern reads every item that matches', $d['panes']['item']['rows'][0]['value']['value'] === 70.0);
	$d = run(['switch_metrics' => ['']]);
	check('Switch on widget: none ticked is no switch', array_column($d['metrics'], 'id') === ['cpu']);
	$d = run(['switch_metrics' => ['disk', 'jvm']]);
	check('…and the ones ticked are the ones offered, the main metric first', array_column($d['metrics'], 'id') === ['cpu', 'disk', 'jvm']);

	// Rank value: the average or the maximum over the window.
	$cpu = \FakeApi::itemid(204, 'system.cpu.util');
	$cpu2 = \FakeApi::itemid(201, 'system.cpu.util');
	$now = time();
	\FakeApi::$trends = [$cpu => [$now - 3 * 3600 => [40, 30, 50], $now - 2 * 3600 => [50, 40, 60]], $cpu2 => [$now - 3 * 3600 => [70, 60, 99], $now - 2 * 3600 => [90, 80, 95]]];
	$d = run(['rank_value' => WidgetForm::RANK_VALUE_AVG, 'trend_days' => 7]);
	check('7d average: ranked by the average of the window, from trends', $hosts($d, 'cpu') === ['karthi-ES-Data-Hot-1', 'karthi-Forwarder-1'] && $d['panes']['cpu']['rows'][0]['value']['value'] === 80.0
		&& $d['metrics'][0]['label'] === 'CPU · average over 7 d', [$hosts($d, 'cpu'), $d['metrics'][0]['label']]);
	check('…a server with no trend yet is not ranked (the average is of the window, not of now)', count($d['panes']['cpu']['rows']) === 2);
	$d = run(['rank_value' => WidgetForm::RANK_VALUE_MAX, 'trend_days' => 7]);
	check('7d max: the highest of the window', $d['panes']['cpu']['rows'][0]['value']['value'] === 99.0 && $d['metrics'][0]['label'] === 'CPU · maximum over 7 d');
	check('batched: one trend.get for the window, one item.get for the exact keys of every server', count(\FakeApi::$asked_trend) === 1
		&& count(array_filter(\FakeApi::$asked, fn($o) => !isset($o['search']))) === 1, [count(\FakeApi::$asked_trend), count(\FakeApi::$asked)]);
	\FakeApi::$trends = [];

	/* ---------------- the columns of a row ---------------- */
	$col = fn(array $c) => $c + ['uid' => substr(md5(json_encode($c)), 0, 8), 'source' => Columns::SOURCE_BUILTIN];
	$mine = array_merge($base, [
		$col(['name' => 'Ingest', 'source' => Columns::SOURCE_ITEM, 'key' => 'es.ingest.rate', 'short' => 1]),
		$col(['name' => 'Uptime', 'builtin' => 'srv.uptime']),
		$col(['name' => '7d CPU max', 'builtin' => 'srv.cpu', 'value' => Columns::VALUE_MAX]),
		$col(['name' => 'IP', 'builtin' => 'top.ip']),
		$col(['name' => 'Old', 'builtin' => 'srv.nope'])
	]);
	$cpu = \FakeApi::itemid(201, 'system.cpu.util');
	\FakeApi::$trends = [$cpu => [$now - 3 * 3600 => [70, 60, 99], $now - 2 * 3600 => [90, 80, 95]]];
	$d = run(['columns' => $mine]);
	check('a column that no longer passes is left out and named', count($d['dropped']) === 1 && strpos($d['dropped'][0], 'Column "Old"') === 0, $d['dropped']);
	check('extra columns: the four, then the server\'s figures', array_column($d['columns'], 'name') === ['Rank', 'Host', 'Value', 'Client · Role', 'Ingest', 'Uptime', '7d CPU max', 'IP'],
		array_column($d['columns'], 'name'));
	$row = array_values(array_filter($d['panes']['cpu']['rows'], fn($r) => $r['host'] === 'karthi-ES-Data-Hot-1'))[0];
	$cells = array_column($row['cells'], null, null);
	check('any item of the server, short numbers', $row['cells'][4]['text'] === '18.4k/s' || $row['cells'][4]['text'] === '18400 /s' || strpos($row['cells'][4]['text'], '18') === 0, $row['cells'][4]['text']);
	check('a column that is a window of a figure: the maximum over it, the host\'s own peak', $row['cells'][6]['value'] === 99.0, $row['cells'][6]);
	check('a text column of the row', $row['cells'][7]['text'] === '10.0.0.1');
	check('a server without the item: unknown with the reason, not 0', array_values(array_filter($d['panes']['cpu']['rows'], fn($r) => $r['host'] === 'karthi-Forwarder-1'))[0]['cells'][4]['value'] === null);
	check('the list view lists the extras beside the row', count($row['extras']) === 4 && $row['extras'][0]['name'] === 'Ingest');
	$hidden = $mine;
	$hidden[2]['hidden'] = 1;
	check('Value hidden: the ranking and the list still have it', count(run(['columns' => $hidden])['columns']) === 7 && run(['columns' => $hidden])['panes']['cpu']['rows'][0]['value']['text'] === '95 %');
	[, $errs] = Columns::clean(['name' => 'Value', 'source' => 1, 'builtin' => 'top.value', 'value' => 2], $catalogue, []);
	check('Value is only ever the value now: the widget decides the window', $errs !== [], $errs);
	[$c] = Columns::clean(['name' => 'x', 'source' => 2, 'key' => 'es.ingest.rate', 'roles' => ['parser'], 'combine' => 3], $catalogue, []);
	check('a row is one server: no roles are kept', $c['roles'] === []);
	\FakeApi::$trends = [];

	/* ---------------- how it is drawn ---------------- */
	check('Table', run(['view' => WidgetForm::VIEW_TABLE])['view'] === WidgetForm::VIEW_TABLE);
	check('Bar chart', run(['view' => WidgetForm::VIEW_BARS])['view'] === WidgetForm::VIEW_BARS);
	check('the palette is the user\'s theme\'s', strpos(run()['palette'], '--ep-ok:') === 0);

	/* ---------------- the clients' templates ---------------- */
	check('a widget with its own columns saved by an earlier version gets the others offered unticked',
		count(WidgetForm::upgrade(['columns' => [$base[0]]], $catalogue, [])['columns']) === count($catalogue)
		&& count(array_filter(WidgetForm::upgrade(['columns' => [$base[0]]], $catalogue, [])['columns'], fn($c) => !$c['hidden'])) === 1);
	check('the order the rows were dragged into is the order kept', array_column(WidgetForm::upgrade(['columns' => [$base[0], $base[1], $base[2]], 'sortorder' => ['columns' => ['2', '0', '1']]],
		$catalogue, [])['columns'], 'builtin')[0] === 'top.value');

	/* ---------------- the column editor: a row is one server ---------------- */
	require_once __DIR__.'/../actions/ColumnEdit.php';
	$edit = function (array $input): array {
		$a = new \Modules\EpTophosts\Actions\ColumnEdit($input);
		(new \ReflectionMethod($a, 'doAction'))->invoke($a);
		return json_decode($a->response->data['main_block'], true);
	};
	$r = $edit(['update' => 1, 'scope' => 'client', 'name' => 'Peak CPU', 'source' => '1', 'builtin' => 'srv.cpu', 'value' => '4', 'window' => '7', 'roles' => ['parser']]);
	check('Update: a column of the server\'s figures, a window of it, no roles', ($r['builtin'] ?? '') === 'srv.cpu' && $r['value'] === 4 && $r['roles'] === [] && !isset($r['error']), $r);
	$r = $edit(['update' => 1, 'scope' => 'client', 'name' => 'Value', 'source' => '1', 'builtin' => 'top.value', 'value' => '2']);
	check('…Value only ever the value now', isset($r['error']) && strpos(implode(' ', $r['error']['messages']), 'it shows its value now') !== false, $r);
	check('Check counts the client servers that have the key', $edit(['coverage' => 1, 'key' => 'es.ingest.rate', 'pattern' => '0', 'roles' => []]) === ['found' => 2, 'total' => 4]);

	// A single node: ES, Parser, Forwarder and Engine on one box (the shared reading).
	$r = fn($fam, $extra = []) => ['id' => $fam, 'family' => $fam] + $extra;
	check('ES, Parser, Forwarder and Engine on one machine is a single node', \Modules\EpTophosts\Lib\ClientData::isSingleNode([$r('es'), $r('parser'), $r('fwd'), $r('engine')]));
	check('three of the four is not', !\Modules\EpTophosts\Lib\ClientData::isSingleNode([$r('es'), $r('parser'), $r('engine')]));

	foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
		if (is_file($dir.'/'.$f)) { unlink($dir.'/'.$f); }
	}
	rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
