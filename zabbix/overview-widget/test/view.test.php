<?php
/**
 * The Client overview against a fake Zabbix, and its rules on their own: the metric settings and
 * what is refused, how a widget saved before 2.1 is read, unknown never drawn as zero, the
 * threshold directions, the combine functions and trends, the order / top N / only-show filters,
 * problem counting, the forecast and the contract badge.
 *   docker run --rm -v "$PWD":/m -w /m php:8.3-cli php test/view.test.php
 */
namespace Zabbix\Widgets {
	class CWidgetForm {
		protected array $values = [];
		public function __construct(array $values = [], $templateid = null) { $this->values = $this->normalizeValues($values); }
		protected function normalizeValues(array $values): array { return $values; }
	}
	/** Just enough of Zabbix's base field for the module's own fields. */
	abstract class CWidgetField {
		public const FOREIGN_REFERENCE_KEY = '_reference';
		public const REFERENCE_DASHBOARD = 'DASHBOARD';
		protected string $name;
		protected ?string $label;
		protected $value = null;
		protected $default = null;
		protected ?int $save_type = null;
		public function __construct(string $name, ?string $label = null) { $this->name = $name; $this->label = $label; }
		public function getName(): string { return $this->name; }
		public function getValue() { return $this->value ?? $this->default; }
		public function setValue($value): self { $this->value = $value; return $this; }
		public function getDefault() { return $this->default; }
		public function setDefault($value): self { $this->default = $value; return $this; }
		protected function setSaveType($t): self { $this->save_type = $t; return $this; }
		public function getErrorLabel(): string { return $this->label ?? $this->name; }
		public static function createTypedReference(string $reference, string $type = ''): string { return $type !== '' ? $reference.'.'.$type : $reference; }
	}
}
namespace {
	define('ITEM_STATE_NORMAL', 0); define('INTERFACE_TYPE_AGENT', 1); define('INTERFACE_PRIMARY', 1);
	define('ZBX_WIDGET_FIELD_TYPE_INT32', 0); define('ZBX_WIDGET_FIELD_TYPE_STR', 1);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	if (!function_exists('_')) { function _($s) { return $s; } }
	// As Zabbix's own: vsprintf, so a stray % in a message fails here as it would there.
	if (!function_exists('_s')) { function _s($s, ...$a) { return vsprintf($s, $a); } }
	if (!function_exists('_n')) { function _n($one, $many, $n, ...$a) { return vsprintf($n == 1 ? $one : $many, array_merge([$n], $a)); } }
	function getSubGroups($ids) { return $ids; }
	class CWidgetsData { public const DATA_TYPE_TIME_PERIOD = '_timeperiod'; }

	class CControllerDashboardWidgetView {
		public $fields_values = ['groupids' => []];
		public $widget;
		public $response;
		public function __construct() { $this->widget = new class { public function getDefaultName() { return 'Client overview'; } }; }
		public function getInput($k, $d = null) { return $d; }
		public function getDebugMode() { return 0; }
		public function setResponse($r) { $this->response = $r; }
	}
	class CControllerResponseData { public $data; public function __construct($d) { $this->data = $d; } }

	/**
	 * Three clients and a decommissioned one: karthi (DI) with four servers, one with its agent
	 * unreachable and one that has sent nothing for 20 minutes;
	 * acme (On-Prem) in maintenance, thresholds lowered to 30 / 40 on its master host; dis,
	 * disabled; gone, decommissioned.
	 */
	class FakeApi {
		public static array $calls = [];
		public static array $options = [];
		private $what;
		public function __construct($what) { $this->what = $what; }

		public static function items(): array {
			$now = time();
			$rows = [
				// [hostid, key, value, age in seconds]
				[201, 'system.cpu.util', 85, 60], [201, 'vm.memory.utilization', 40, 60], [201, 'vfs.fs.dependent.size[/,pused]', 99, 60],
				[201, 'es.ingest.rate', 18400, 60], [201, 'agent.ping', 1, 60], [205, 'agent.ping', 1, 1200],
				[202, 'system.cpu.util', 20, 3600], [202, 'vfs.fs.dependent.size[/,pused]', 91, 3600],
				[204, 'system.cpu.util', 95, 60], [204, 'es.ingest.rate', 1600, 60], [204, 'es.jvm[heap]', 70, 60], [204, 'es.jvm[old]', 20, 60],
				[301, 'system.cpu.util', 35, 60], [301, 'vm.memory.utilization', 10, 60], [301, 'es.ingest.rate', 900, 1800],
				[101, 'ep.es.cpu.usage', 95, 60], [101, 'ep.es.cpu.allocated', 16, 60], [101, 'ep.parser.mem.usage', 82, 60],
				[101, 'ep.es.mem.usage', 50, 60],
				[101, 'ep.es.storage.usage', 61, 60], [101, 'ep.es.storage.used', 600 * 1073741824, 60], [101, 'ep.es.storage.allocated', 1000 * 1073741824, 60],
				[101, 'ep.devices.seen', 40, 60],
				[102, 'ep.es.cpu.usage', 35, 60], [102, 'ep.es.storage.used', 300 * 1073741824, 60],
				[104, 'ep.es.cpu.usage', 5, 60]
			];
			for ($n = 0; $n < 60; $n++) {
				$rows[] = [201, 'es.many['.$n.']', $n, 60];
			}
			$out = [];
			foreach ($rows as $i => [$h, $k, $v, $age]) {
				$out[] = ['itemid' => (string) ($h * 1000 + $i), 'hostid' => (string) $h, 'key_' => $k, 'name' => $k, 'lastvalue' => (string) $v,
					'lastclock' => (string) ($now - $age), 'state' => '0', 'value_type' => '0', 'units' => ''];
			}
			return $out;
		}

		public function get(array $o) {
			self::$calls[] = $this->what;
			self::$options[] = [$this->what, $o];
			$if = fn($ip, $a) => [['ip' => $ip, 'type' => 1, 'main' => 1, 'available' => $a]];
			$g = fn(...$n) => array_map(fn($x) => ['name' => $x], $n);
			$now = time();
			switch ($this->what) {
				case 'Template':
					return [['templateid' => 9]];
				case 'Host':
					if (isset($o['templateids'])) {
						return [101 => ['hostid' => 101, 'name' => 'karthi-Master', 'maintenance_status' => 0],
							102 => ['hostid' => 102, 'name' => 'acme-Master', 'maintenance_status' => 1],
							103 => ['hostid' => 103, 'name' => 'gone-Master', 'maintenance_status' => 0],
							104 => ['hostid' => 104, 'name' => 'dis-Master', 'maintenance_status' => 0]];
					}
					return [
						201 => ['hostid' => 201, 'name' => 'karthi-ES-Data-Hot-1', 'hostgroups' => $g('karthi', 'ES Data Hot'), 'interfaces' => $if('10.0.0.1', 1), 'tags' => []],
						202 => ['hostid' => 202, 'name' => 'karthi-Parser-1', 'hostgroups' => $g('karthi', 'Parser'), 'interfaces' => $if('10.0.0.2', 2), 'tags' => []],
						204 => ['hostid' => 204, 'name' => 'karthi-Forwarder-1', 'hostgroups' => $g('karthi', 'Forwarders'), 'interfaces' => $if('10.0.0.4', 1), 'tags' => []],
						205 => ['hostid' => 205, 'name' => 'karthi-Engine-1', 'hostgroups' => $g('karthi', 'Engine'), 'interfaces' => $if('10.0.0.5', 1), 'tags' => []],
						301 => ['hostid' => 301, 'name' => 'acme-ES-Data-Hot-1', 'hostgroups' => $g('acme', 'ES Data Hot'), 'interfaces' => $if('10.1.0.1', 1), 'tags' => []]
					];
				case 'HostGroup':
					return array_values(array_filter([['groupid' => 1, 'name' => 'karthi'], ['groupid' => 2, 'name' => 'acme'], ['groupid' => 3, 'name' => 'gone'],
						['groupid' => 4, 'name' => 'dis']], fn($x) => in_array($x['name'], $o['filter']['name'], true)));
				case 'UserMacro':
					return [
						['hostid' => 101, 'macro' => '{$GRP.CLIENT}', 'value' => 'karthi'], ['hostid' => 101, 'macro' => '{$EP.CLIENT.TYPE}', 'value' => 'DI'],
						['hostid' => 101, 'macro' => '{$EP.CONTRACT.END}', 'value' => date('Y-m-d', $now + 20 * 86400)],
						['hostid' => 102, 'macro' => '{$GRP.CLIENT}', 'value' => 'acme'], ['hostid' => 102, 'macro' => '{$EP.CLIENT.TYPE}', 'value' => 'On-Prem'],
						['hostid' => 102, 'macro' => '{$EP.USAGE.WARN}', 'value' => '30'], ['hostid' => 102, 'macro' => '{$EP.USAGE.HIGH}', 'value' => '40'],
						['hostid' => 103, 'macro' => '{$GRP.CLIENT}', 'value' => 'gone'], ['hostid' => 103, 'macro' => '{$EP.CLIENT.STATUS}', 'value' => 'decommissioned'],
						['hostid' => 104, 'macro' => '{$GRP.CLIENT}', 'value' => 'dis'], ['hostid' => 104, 'macro' => '{$EP.CLIENT.STATUS}', 'value' => 'disabled']
					];
				case 'Item':
					$out = [];
					foreach (self::items() as $it) {
						if (!in_array((int) $it['hostid'], array_map('intval', $o['hostids']), true)) {
							continue;
						}
						if (isset($o['filter']['key_']) && !in_array($it['key_'], (array) $o['filter']['key_'], true)) {
							continue;
						}
						if (isset($o['search']['key_'])) {
							$hit = false;
							foreach ((array) $o['search']['key_'] as $p) {
								// Zabbix's wildcard search is SQL LIKE: * is anything, [ ] are plain characters.
								$hit = $hit || preg_match('/^'.str_replace('\\*', '.*', preg_quote($p, '/')).'$/', $it['key_']);
							}
							if (!$hit) {
								continue;
							}
						}
						$out[] = $it;
					}
					return isset($o['limit']) ? array_slice($out, 0, $o['limit']) : $out;
				case 'Trend':
					// Seven days of hourly trend for the items asked, rising slowly: ES storage usage
					// from 54 % to 61 %, every other item flat at its value with ±5 around it.
					$byId = array_column(self::items(), null, 'itemid');
					$out = [];
					foreach ($o['itemids'] as $id) {
						$it = $byId[$id];
						for ($h = 7 * 24; $h >= 1; $h--) {
							$clock = intdiv($now, 3600) * 3600 - $h * 3600;
							if ($clock < $o['time_from'] || $clock > $o['time_till']) {
								continue;
							}
							$v = $it['key_'] === 'ep.es.storage.usage' ? 61 - $h / 24 : (float) $it['lastvalue'];
							$out[] = ['itemid' => $id, 'clock' => (string) $clock, 'value_avg' => (string) $v, 'value_min' => (string) ($v - 5), 'value_max' => (string) ($v + 5)];
						}
					}
					return $out;
				case 'History':
					return [];
				case 'Problem':
					$all = [
						['eventid' => 1, 'objectid' => 11, 'severity' => '5', 'acknowledged' => '0', 'suppressed' => '0'],
						['eventid' => 2, 'objectid' => 12, 'severity' => '4', 'acknowledged' => '1', 'suppressed' => '0'],
						['eventid' => 3, 'objectid' => 13, 'severity' => '3', 'acknowledged' => '0', 'suppressed' => '1'],
						['eventid' => 4, 'objectid' => 14, 'severity' => '2', 'acknowledged' => '0', 'suppressed' => '0'],
						['eventid' => 5, 'objectid' => 15, 'severity' => '1', 'acknowledged' => '0', 'suppressed' => '0']
					];
					return array_values(array_filter($all, fn($p) => in_array((int) $p['severity'], $o['severities'], true)
						&& (!isset($o['acknowledged']) || (int) $p['acknowledged'] === (int) $o['acknowledged'])
						&& (!isset($o['suppressed']) || (int) $p['suppressed'] === (int) $o['suppressed'])));
				case 'Trigger':
					$map = [11 => [201, 202], 12 => [101], 13 => [201], 14 => [301], 15 => [201]];
					$out = [];
					foreach ($o['triggerids'] as $t) {
						$out[$t] = ['triggerid' => $t, 'hosts' => array_map(fn($h) => ['hostid' => (string) $h], $map[$t] ?? [])];
					}
					return $out;
			}
			return [];
		}
	}
	class API {
		public static function __callStatic($name, $args) { return new FakeApi($name); }
	}
}
namespace Modules\EpOverview\Test {
	require __DIR__.'/../actions/WidgetView.php';
	use Modules\EpOverview\Actions\WidgetView;
	use Modules\EpOverview\Includes\{CWidgetFieldMetrics, CWidgetFieldStrings, Metrics, Overview, WidgetForm};
	use Modules\EpOverview\Lib\{Roles, Settings};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-ov-test-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);

	/** The fields as the widget's form hands them over: upgraded, metrics cleaned, lists cleaned. */
	function values(array $raw): array {
		Settings::forget();
		$roles = Roles::load();
		$v = WidgetForm::upgrade($raw + ['groupids' => []], $roles, Settings::all());
		[$v['metrics']] = CWidgetFieldMetrics::cleanList($v['metrics'] ?? [], Metrics::catalogue($roles), array_column(Roles::allRoles($roles), 'id'), false);
		foreach (['clients', 'client_types', 'section_order', 'sections', 'role_order', 'roles_hidden', 'severities'] as $k) {
			if (array_key_exists($k, $v)) {
				$v[$k] = CWidgetFieldStrings::clean($v[$k]);
			}
		}
		return $v;
	}
	function run(array $fields = []): array {
		\FakeApi::$calls = [];
		\FakeApi::$options = [];
		$v = new WidgetView();
		$v->fields_values = values($fields);
		$m = new \ReflectionMethod($v, 'doAction');
		$m->invoke($v);
		return $v->response->data;
	}
	$byName = fn($d) => array_column($d['overview']['clients'], null, 'name');
	$metricOf = fn($client, $uid) => array_column($client['metrics'], null, 'uid')[$uid] ?? null;
	$roles = Roles::load();
	$cat = Metrics::catalogue($roles);
	$roleIds = array_column(Roles::allRoles($roles), 'id');
	$metric = fn(array $m) => Metrics::clean($m + ['name' => 'x', 'uid' => 'abc'], $cat, $roleIds);

	/* ---------------------------------- config validation ---------------------------------- */

	[$m, $e] = $metric(['source' => 2, 'key' => 'es.ingest.rate', 'roles' => ['es_data_hot', 'nope'], 'combine' => 1, 'units' => '/s',
		'use_settings' => 1, 'thresholds' => [['level' => 2, 'color' => '#ff0000', 'value' => '1000'], ['level' => 1, 'color' => '', 'value' => '5000']], 'worse' => 2]);
	check('a custom item metric is accepted, unknown roles dropped, thresholds sorted, colour without #', $e === [] && $m['roles'] === ['es_data_hot']
		&& $m['use_settings'] === 0 && $m['thresholds'][0]['value'] === '1000' && $m['thresholds'][0]['color'] === 'FF0000' && $m['worse'] === 2, [$m, $e]);
	foreach (['bad key!' => false, 'es.jvm[*]' => false, str_repeat('a', 256) => false, 'a b' => false] as $key => $pattern) {
		[, $e] = $metric(['source' => 2, 'key' => $key, 'pattern' => $pattern ? 1 : 0]);
		check('a bad item key is refused: '.substr($key, 0, 20), $e !== [], $e);
	}
	[, $e] = $metric(['source' => 2, 'key' => 'es.jvm[*]', 'pattern' => 1]);
	check('a * key is fine as a pattern', $e === [], $e);
	[, $e] = $metric(['source' => 2, 'key' => '*', 'pattern' => 1]);
	check('a pattern of only * is refused', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => "es.x\x01"]);
	check('control characters in a key are refused', $e !== []);
	[, $e] = $metric(['source' => 1, 'builtin' => 'nope']);
	check('an unknown built-in metric is refused', $e !== []);
	[, $e] = $metric(['name' => '', 'source' => 1, 'builtin' => 'storage.usage']);
	check('a metric needs a name', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'thresholds' => [['level' => 1, 'value' => 'abc']]]);
	check('a threshold must be a number', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'thresholds' => [['level' => 1, 'value' => '5', 'color' => 'red']]]);
	check('a threshold colour must be hex', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'thresholds' => [['level' => 1, 'value' => '5'], ['level' => 2, 'value' => '5.0']]]);
	check('two thresholds at one value are refused', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'thresholds' => array_fill(0, 11, ['level' => 1, 'value' => '1'])]);
	check('more than ten thresholds are refused', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'thresholds' => [['level' => 3, 'value' => '5']]]);
	check('a threshold level is Warn or High', $e !== []);
	[, $e] = $metric(['source' => 1, 'builtin' => 'storage.usage', 'use_settings' => 1, 'warn' => '95', 'high' => '90']);
	check('Warn above High is refused', $e !== []);
	[, $e] = $metric(['source' => 1, 'builtin' => 'storage.usage', 'use_settings' => 1, 'warn' => '0']);
	check('a Warn of 0 is refused: empty means Settings', $e !== []);
	[$m, $e] = $metric(['source' => 1, 'builtin' => 'storage.usage', 'use_settings' => 1, 'warn' => '', 'high' => '', 'units' => 'XX', 'worse' => 2]);
	check('a built-in takes its own units, Settings thresholds are "higher is worse", empty is Settings',
		$e === [] && $m['units'] === '%' && $m['worse'] === 1 && $m['warn'] === '' && $m['high'] === '', [$m, $e]);
	[$m] = $metric(['source' => 1, 'builtin' => 'delay.median', 'use_settings' => 1]);
	check('Settings thresholds only for a usage percentage', $m['use_settings'] === 0);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'combine' => 9]);
	check('an unknown combine function is refused', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'decimals' => '11']);
	check('decimals beyond 10 are refused', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'stale' => '0']);
	check('stale after 0 minutes is refused', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'min' => '10', 'max' => '5']);
	check('bar min must be below max', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'units' => '<b>']);
	check('units with markup are refused', $e !== []);

	$many = array_fill(0, Metrics::MAX + 1, ['name' => 'cpu', 'source' => 1, 'builtin' => 'srv.cpu']);
	[$list, $e] = CWidgetFieldMetrics::cleanList($many, $cat, $roleIds, true);
	check('more than twenty metrics: an error when applied', $e !== [] && strpos($e[0], 'at most 20') !== false, $e);
	[$list, $e] = CWidgetFieldMetrics::cleanList($many, $cat, $roleIds, false);
	check('…and the first twenty when a saved widget is drawn, each with its own id', $e === [] && count($list) === 20
		&& count(array_unique(array_column($list, 'uid'))) === 20);
	[$list, $e] = CWidgetFieldMetrics::cleanList([['name' => 'ok', 'source' => 1, 'builtin' => 'srv.cpu'], ['name' => 'bad', 'source' => 2, 'key' => 'x y']], $cat, $roleIds, true);
	check('a failing metric is named in the error', $e !== [] && strpos($e[0], 'Metric "bad"') === 0, $e);
	[$list] = CWidgetFieldMetrics::cleanList([['name' => 'ok', 'source' => 1, 'builtin' => 'srv.cpu'], ['name' => 'bad', 'source' => 2, 'key' => 'x y']], $cat, $roleIds, false);
	check('…and left out, not fatal, on the dashboard', array_column($list, 'name') === ['ok']);

	$field = (new \Modules\EpOverview\Includes\CWidgetFieldOptionalNumber('top_n', 'Show top', 1, 1000))->setValue('');
	check('an empty optional number stays empty, not 0', $field->validate(true) === [] && $field->getValue() === '');
	$field->setValue('0');
	check('an optional number out of range is an error when applied', $field->validate(true) !== []);
	$field->setValue('0');
	check('…and dropped on the dashboard', $field->validate(false) === [] && $field->getValue() === '');

	$api = [];
	(new CWidgetFieldStrings('sections'))->setDefault(Overview::SECTIONS_ON)->setValue('')->toApi($api);
	check('an empty list that differs from its default is stored as one empty string', $api === [['type' => 1, 'name' => 'sections.0', 'value' => '']], $api);
	check('…which reads back as empty', CWidgetFieldStrings::clean(['']) === []);
	$api = [];
	(new CWidgetFieldMetrics('metrics', 'Metrics', $cat, $roleIds))->setValue([Metrics::defaults($roles)[0]])->toApi($api);
	$names = array_column($api, 'name');
	check('a metric is stored as metrics.N.key, defaults left out', in_array('metrics.0.builtin', $names, true) && in_array('metrics.0.uid', $names, true)
		&& !in_array('metrics.0.display', $names, true), $names);

	/* ---------------------------------- back-compat ---------------------------------- */

	$up = WidgetForm::upgrade(['client' => 'karthi', 'client_type' => WidgetForm::key('DI'), 'show_summary' => '0', 'show_roles' => '0',
		'family' => (string) WidgetForm::key('parser'), 'usage_warn' => '70', 'usage_high' => '0'], $roles, Settings::all());
	check('old Client becomes the client list, old Client type the type list', $up['clients'] === ['karthi'] && $up['client_types'] === ['DI'], $up);
	check('old panels off become sections off: Summary → metrics, Servers by role → roles', $up['sections'] === ['header', 'problems', 'used', 'warnings'], $up['sections'] ?? null);
	check('old family and thresholds become the default metrics: Parser CPU and memory at Warn 70, High from Settings',
		array_column($up['metrics'], 'builtin') === ['storage.usage', 'fam.parser.cpu', 'fam.parser.mem']
		&& $up['metrics'][1]['warn'] === '70' && $up['metrics'][1]['high'] === '' && $up['metrics'][1]['use_settings'] === 1, $up['metrics']);
	check('old fields are not carried on', !array_intersect(WidgetForm::LEGACY, array_keys($up)));
	$up = WidgetForm::upgrade(['usage_warn' => '0', 'usage_high' => '0'], $roles, Settings::all());
	check('old thresholds of 0 mean Settings: empty, never 0', $up['metrics'][0]['warn'] === '' && $up['metrics'][0]['high'] === ''
		&& !array_key_exists('sections', $up) && !array_key_exists('clients', $up));
	$up = WidgetForm::upgrade(['config_version' => '2', 'client' => 'old'], $roles, Settings::all());
	check('a 2.1 widget whose metrics were all removed keeps none, and old fields are ignored', !array_key_exists('metrics', $up) && !array_key_exists('clients', $up), $up);
	$up = WidgetForm::upgrade(['config_version' => 2, 'metrics' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']],
		'sortorder' => ['metrics' => ['2', '0', '1']]], $roles, Settings::all());
	check('dragged metrics come in their new order', array_column($up['metrics'], 'name') === ['c', 'a', 'b'] && !isset($up['sortorder']));
	$up = WidgetForm::upgrade(['config_version' => 2, 'role_order' => ['', 'es_master', 'parser', 'engine'], 'roles_shown' => ['', 'parser']], $roles, Settings::all());
	check('roles not ticked are kept as hidden, so a new role shows on its own', $up['roles_hidden'] === ['es_master', 'engine'], $up);
	$up = WidgetForm::upgrade(['config_version' => 2, 'trend_source' => '0'], $roles, Settings::all());
	check('trend window "dashboard": the dashboard time period is referred to', ($up['time_period']['_reference'] ?? '') === 'DASHBOARD._timeperiod', $up);
	$up = WidgetForm::upgrade(['config_version' => 2, 'trend_source' => '1', 'time_period' => ['_reference' => 'DASHBOARD._timeperiod']], $roles, Settings::all());
	check('…and not while it is "Last N days", so the dashboard shows no time selector for it', !array_key_exists('time_period', $up));

	// A widget saved before 2.1 draws, unedited, as it did: the Summary view.
	$d = run();
	$ov = $d['overview'];
	check('a 2.0 widget (host groups only) opens on the Summary view', ($ov['view'] ?? '') === 'summary', $ov['view'] ?? $d['error']);
	check('…every client but the decommissioned one, disabled ones with their badge, by name', array_column($ov['clients'], 'name') === ['acme', 'dis', 'karthi'],
		array_column($ov['clients'], 'name'));
	$k = $byName($d)['karthi'];
	check('ES CPU with its class at the default thresholds: 95 % high', $k['families']['es']['cpu']['usage'] === 95.0 && $k['families']['es']['cpu']['class'] === 'ep-high'
		&& $k['families']['es']['cpu']['allocated'] === 16.0, $k['families']['es']['cpu']);
	check('every family is there, not only ES', $k['families']['parser']['mem']['usage'] === 82.0 && $k['families']['parser']['mem']['class'] === 'ep-warn');
	check('storage in bytes, with its class', $k['storage']['used'] === 600.0 * 1073741824 && $k['storage']['class'] === '');
	check('servers with their status and CPU and memory classes', count($k['servers']) === 4 && in_array('down', array_column($k['servers'], 'status'), true)
		&& isset($k['servers'][0]['classes']['cpu']));
	check('acme at its own thresholds (30 / 40): 35 % ES CPU is warn', $byName($d)['acme']['families']['es']['cpu']['class'] === 'ep-warn');
	check('by default every panel, cards for ES, no client chosen', $ov['settings']['panels'] === ['summary' => true, 'used' => true, 'roles' => true]
		&& $ov['settings']['family'] === 'es' && $ov['settings']['client'] === '');
	$d = run(['show_used' => 0, 'show_roles' => 0, 'family' => WidgetForm::key('parser'), 'usage_warn' => '96', 'usage_high' => '98']);
	check('its panels, family and thresholds carry on', $d['overview']['settings']['panels'] === ['summary' => true, 'used' => false, 'roles' => false]
		&& $d['overview']['settings']['family'] === 'parser' && $byName($d)['karthi']['families']['es']['cpu']['class'] === '', $d['overview']['settings']);
	$d = run(['client' => 'KARTHI']);
	check('old Client: that client alone, opened on its own view', array_column($d['overview']['clients'], 'name') === ['karthi'] && $d['overview']['settings']['client'] === 'karthi');
	$d = run(['client' => 'nobody']);
	check('a client that is not there is said', strpos((string) $d['error'], 'No client named "nobody"') !== false, $d['error']);
	$d = run(['client_type' => WidgetForm::key('On-Prem')]);
	check('old Client type: only that type (dis has none set, so it is the default type, On-Prem)', array_column($d['overview']['clients'], 'name') === ['acme', 'dis']);
	$up = WidgetForm::upgrade([], $roles, Settings::all());
	check('a widget being added (no fields at all) starts on Cards', !array_key_exists('view', $up) && count($up['metrics']) === 3);
	$up = WidgetForm::upgrade(['groupids' => ['5']], $roles, Settings::all());
	check('a saved 2.0 widget starts on Summary and lists disabled clients as it did', $up['view'] === WidgetForm::VIEW_SUMMARY && $up['hide_inactive'] === 0);
	// Chosen Cards, the 2.0 widget's cards' figures are the default metrics.
	$d = run(['client' => 'KARTHI', 'usage_warn' => '0', 'usage_high' => '0', 'view' => 0]);
	$k = $byName($d)['karthi'];
	check('Cards for a 2.0 widget: ES storage, ES CPU and ES memory, at the Settings thresholds', array_column($k['metrics'], 'uid') === ['storage', 'cpu', 'mem']
		&& $metricOf($k, 'cpu')['now']['text'] === '95%' && $metricOf($k, 'cpu')['now']['level'] === 'high', $k['metrics']);
	foreach ($roles['families'] as $fam) {
		$up = WidgetForm::upgrade(['family' => (string) WidgetForm::key($fam['id'])], $roles, Settings::all());
		$keys = array_map(fn($m) => $cat[$m['builtin']]['key'], $up['metrics']);
		$labels = array_column($up['metrics'], 'name');
		check("default metrics for the {$fam['label']} family read its own items", $keys === ['ep.es.storage.usage', 'ep.'.$fam['id'].'.cpu.usage', 'ep.'.$fam['id'].'.mem.usage']
			&& $labels === ['ES storage used', $fam['label'].' CPU', $fam['label'].' memory'], [$keys, $labels]);
	}

	/* ---------------------------------- the overview ---------------------------------- */

	$cards = ['view' => 0];
	$d = run($cards);
	$ov = $d['overview'];
	check('every active client by name; disabled and decommissioned hidden by default', array_column($ov['clients'], 'name') === ['acme', 'karthi'], array_column($ov['clients'], 'name'));
	$d = run($cards + ['hide_inactive' => 0]);
	check('…and shown when asked, with their badge', array_column($d['overview']['clients'], 'name') === ['acme', 'dis', 'gone', 'karthi']
		&& $byName($d)['gone']['badge'] === 'decommissioned' && $byName($d)['dis']['status'] === 'disabled', array_column($d['overview']['clients'], 'badge', 'name'));
	$d = run($cards + ['clients' => ['acme', 'Karthi'], 'client_types' => ['DI']]);
	check('Clients and Client types together', array_column($d['overview']['clients'], 'name') === ['karthi']);

	$custom = ['config_version' => 2, 'metrics' => [
		['uid' => 'ingest', 'name' => 'Ingest', 'source' => 2, 'key' => 'es.ingest.rate', 'combine' => Metrics::COMBINE_SUM, 'units' => '/s', 'decimals' => '1',
			'use_settings' => 0, 'worse' => Metrics::WORSE_LOWER, 'thresholds' => [['level' => 1, 'value' => '5000', 'color' => ''], ['level' => 2, 'value' => '1000', 'color' => '']]],
		['uid' => 'cpu', 'name' => 'CPU', 'source' => 1, 'builtin' => 'srv.cpu', 'combine' => Metrics::COMBINE_AVG],
		['uid' => 'cpumax', 'name' => 'CPU max', 'source' => 1, 'builtin' => 'srv.cpu', 'combine' => Metrics::COMBINE_MAX, 'trend_avg' => 0],
		['uid' => 'hot', 'name' => 'Hot CPU', 'source' => 1, 'builtin' => 'srv.cpu', 'roles' => ['es_data_hot'], 'combine' => Metrics::COMBINE_COUNT, 'use_settings' => 1],
		['uid' => 'jvm', 'name' => 'JVM', 'source' => 2, 'key' => 'es.jvm[*]', 'pattern' => 1, 'combine' => Metrics::COMBINE_MAX, 'display' => Metrics::DISPLAY_SPARKLINE],
		['uid' => 'gone', 'name' => 'Not here', 'source' => 2, 'key' => 'no.such.key'],
		['uid' => 'hidden', 'name' => 'Hidden', 'source' => 2, 'key' => 'no.such.key', 'nodata' => Metrics::NODATA_HIDE],
		['uid' => 'delay', 'name' => 'Log delay', 'source' => 1, 'builtin' => 'delay.median']
	]];
	$d = run($custom);
	$k = $byName($d)['karthi'];
	$a = $byName($d)['acme'];
	$ingest = $metricOf($k, 'ingest');
	check('Sum: 18400 + 1600 per second, shortened', $ingest['now']['text'] === '20k/s' && $ingest['now']['known'], $ingest['now']);
	check('…from two of four servers, which the card says', $ingest['partial'] === [2, 4], $ingest['partial']);
	check('lower is worse: 900/s at Warn 5000 / High 1000 is high', $metricOf($a, 'ingest')['now']['level'] === 'high', $metricOf($a, 'ingest')['now']);
	check('…and 20k/s is fine', $ingest['now']['level'] === '');
	check('Average of the three servers\' CPU (85, 20, 95)', $metricOf($k, 'cpu')['now']['text'] === '66.7%', $metricOf($k, 'cpu')['now']);
	check('Highest server', $metricOf($k, 'cpumax')['now']['text'] === '95%' && !array_key_exists('avg', $metricOf($k, 'cpumax')));
	check('Count over Warn: hot servers at or past Warn (karthi\'s one at 85 % of 80)', $metricOf($k, 'hot')['now']['text'] === '1', $metricOf($k, 'hot')['now']);
	check('…at acme\'s own thresholds from its master host (35 % of 30 / 40)', $metricOf($a, 'hot')['now']['text'] === '1');
	check('a pattern key reads every matching item (es.jvm[heap], es.jvm[old]): the highest', $metricOf($k, 'jvm')['now']['text'] === '70', $metricOf($k, 'jvm')['now']);
	check('…with a sparkline from its trend', count($metricOf($k, 'jvm')['spark'] ?? []) > 1);
	$gone = $metricOf($k, 'gone');
	check('unknown is not zero: no item is "not collected" with no value, never 0', $gone['now']['known'] === false && $gone['now']['text'] === '—'
		&& strpos((string) $gone['why'], 'add the item no.such.key') !== false, $gone);
	check('…its trend cells are empty, not 0', ($gone['avg']['text'] ?? null) === '' && ($gone['max']['text'] ?? null) === '');
	check('…and the warnings name the fix', (bool) array_filter($k['warnings'], fn($w) => strpos($w['text'], 'Not here is not collected — add the item') === 0));
	check('a metric set to hide when there is no data has no row', $metricOf($k, 'hidden') === null);
	check('a built-in with no item names its template', strpos((string) $metricOf($k, 'delay')['why'], 'log delay template') !== false, $metricOf($k, 'delay')['why']);
	check('trend: CPU over seven days, avg of the servers\' hourly averages', ($metricOf($k, 'cpu')['avg']['text'] ?? '') === '66.7%', $metricOf($k, 'cpu')['avg'] ?? null);
	check('trend: Highest takes the highest peak (max of maxes: 95 + 5)', ($metricOf($k, 'cpumax')['max']['text'] ?? '') === '100%', $metricOf($k, 'cpumax')['max'] ?? null);
	check('a value older than its stale time is flagged (acme ingest, 30 min old)', $metricOf($a, 'ingest')['now']['stale'] === true
		&& (bool) array_filter($a['warnings'], fn($w) => strpos($w['text'], 'Ingest: no new value for 30 min') === 0));
	check('one item.get for the keys, one for the pattern, one trend.get, one problem.get, one trigger.get — not per client',
		count(array_filter(\FakeApi::$calls, fn($c) => $c === 'Item')) === 2 && count(array_filter(\FakeApi::$calls, fn($c) => $c === 'Trend')) === 1
		&& count(array_filter(\FakeApi::$calls, fn($c) => $c === 'Problem')) === 1 && count(array_filter(\FakeApi::$calls, fn($c) => $c === 'Trigger')) === 1,
		array_count_values(\FakeApi::$calls));

	// Servers: down when the agent is unreachable, or when nothing new came for N minutes.
	$roleRow = array_column($k['roles'], null, 'id');
	check('servers by role, down counted (Parser-1: agent unavailable)', $roleRow['parser']['n'] === 1 && $roleRow['parser']['down'] === 1
		&& $roleRow['es_data_hot']['down'] === 0, $k['roles']);
	check('…and said', (bool) array_filter($k['warnings'], fn($w) => $w['text'] === 'karthi-Parser-1: Zabbix agent unreachable'));
	check('down after N minutes with no data: Engine-1 last spoke 20 min ago', $roleRow['engine']['down'] === 1
		&& (bool) array_filter($k['warnings'], fn($w) => $w['text'] === 'karthi-Engine-1: no data for 20 min (down after 5 min)'), $k['warnings']);
	$d2 = run($custom + ['down_after' => 30]);
	check('…and not when Down after is longer than that', array_column($byName($d2)['karthi']['roles'], 'down', 'id')['engine'] === 0);
	$d2 = run(['config_version' => 2, 'metrics' => [], 'roles_hidden' => ['parser'], 'role_order' => ['forwarder', 'es_data_hot']]);
	check('roles in the widget\'s order, hidden ones left out, a role it does not list yet at the end', array_column($byName($d2)['karthi']['roles'], 'id') === ['forwarder', 'es_data_hot', 'engine'], $byName($d2)['karthi']['roles']);
	check('acme is in maintenance, and the card says so', (bool) array_filter($a['warnings'], fn($w) => strpos($w['text'], 'In maintenance') === 0));

	// Problems: default Disaster..Warning, unacknowledged and unsuppressed only.
	$sev = fn($c) => array_column($c['problems'], 'n', 'severity');
	check('problems by severity: the default count leaves acknowledged and suppressed out', $sev($k) === [5 => 1, 4 => 0, 3 => 0, 2 => 0] && $sev($a) === [5 => 0, 4 => 0, 3 => 0, 2 => 1], [$sev($k), $sev($a)]);
	check('a problem on two of a client\'s hosts counts once', $k['problems_total'] === 1);
	$d2 = run($cards + ['problems_ack' => 1, 'problems_suppressed' => 1, 'severities' => ['5', '4', '3', '2', '1']]);
	check('include acknowledged and suppressed, and Information', $sev($byName($d2)['karthi']) === [5 => 1, 4 => 1, 3 => 1, 2 => 0, 1 => 1], $sev($byName($d2)['karthi']));
	check('the severity link opens Problems for the client\'s hosts and that severity', strpos($k['problems'][0]['url'], 'action=problem.view') !== false
		&& strpos($k['problems'][0]['url'], 'hostids%5B%5D=101') !== false && strpos($k['problems'][0]['url'], 'severities%5B5%5D=5') !== false
		&& strpos($k['problems'][0]['url'], 'acknowledgement_status=1') !== false, $k['problems'][0]['url']);
	check('the worst open severity colours the card', $k['worst'] === 5 && $a['worst'] === 2);

	// Used of allocated, forecast and contract.
	$u = $k['used'];
	check('used of allocated: 600 GB of 1000 GB, 61 %', $u['used'] === '600 GB' && $u['allocated'] === '1000 GB' && $u['pct'] === 61.0, $u);
	check('the forecast from seven days of growth (1 % a day, 39 % left): about a month', $u['forecast'] !== null && $u['forecast']['days'] > 35
		&& $u['forecast']['days'] < 43, $u['forecast']);
	$u = $a['used'];
	check('allocated not set: what is used, no share, no forecast, and the setting that fixes it', $u['used'] === '300 GB' && $u['allocated'] === null
		&& $u['pct'] === null && $u['forecast'] === null && strpos((string) $u['why'], 'allocated is not set') !== false, $u);
	check('contract end from the master host: 20 days left, inside Warn 60, outside High 14', $k['contract']['days'] === 20 && $k['contract']['level'] === 'warn', $k['contract']);
	check('no contract date is null, not a guess', $a['contract'] === null);

	// Order, top N, only show.
	$d2 = run($custom + ['order_by' => 'm:cpu:now', 'order_dir' => Overview::ORDER_DESC]);
	check('order by a metric, highest first', array_column($d2['overview']['clients'], 'name') === ['karthi', 'acme']);
	$d2 = run($custom + ['order_by' => 'name', 'order_dir' => Overview::ORDER_DESC, 'top_n' => '1']);
	check('top 1, by name Z to A', array_column($d2['overview']['clients'], 'name') === ['karthi'] && $d2['overview']['total'] === 2);
	$d2 = run(['config_version' => 2, 'metrics' => [['uid' => 'c', 'name' => 'ES CPU', 'source' => 1, 'builtin' => 'fam.es.cpu', 'use_settings' => 1]],
		'only_show' => Overview::ONLY_OVER]);
	check('only clients over a threshold (karthi 95 %, acme 35 % of its 30 / 40)', array_column($d2['overview']['clients'], 'name') === ['acme', 'karthi']);
	$d2 = run(['config_version' => 2, 'metrics' => [['uid' => 'c', 'name' => 'ES CPU', 'source' => 1, 'builtin' => 'fam.es.cpu', 'use_settings' => 1, 'warn' => '50', 'high' => '96']],
		'only_show' => Overview::ONLY_OVER, 'clients' => ['karthi']]);
	check('a metric\'s own Warn applies where the host has none (95 % at 50 / 96 is warn)', $byName($d2)['karthi']['metrics'][0]['now']['level'] === 'warn');
	$d2 = run($cards + ['only_show' => Overview::ONLY_PROBLEMS, 'severities' => ['5']]);
	check('only clients with open problems', array_column($d2['overview']['clients'], 'name') === ['karthi']);
	$d2 = run($cards + ['order_by' => 'm:nosuch:now']);
	check('ordering by a metric that has gone falls back to the name', array_column($d2['overview']['clients'], 'name') === ['acme', 'karthi']);
	check('settings for the cards: sections in order, the summary bar off by default', $ov['settings']['sections'] === Overview::SECTIONS_ON
		&& $ov['settings']['window'] === '7d');
	$d2 = run($cards + ['section_order' => ['warnings', 'metrics', 'summary'], 'sections' => ['summary', 'metrics', 'warnings']]);
	check('sections on and in the order chosen', $d2['overview']['settings']['sections'] === ['warnings', 'metrics', 'summary'], $d2['overview']['settings']['sections']);

	/* ---------------------------------- the arithmetic ---------------------------------- */

	check('combine: sum, average, highest, lowest', Overview::combine([1, 2, 6], Metrics::COMBINE_SUM) === 9.0 && Overview::combine([1, 2, 6], Metrics::COMBINE_AVG) === 3.0
		&& Overview::combine([1, 2, 6], Metrics::COMBINE_MAX) === 6.0 && Overview::combine([1, 2, 6], Metrics::COMBINE_MIN) === 1.0);
	check('combine: count over Warn', Overview::combine([10, 85, 95], Metrics::COMBINE_COUNT, fn($v) => $v >= 80) === 2.0);
	check('combine: only known values, and nothing known is null, not 0', Overview::combine([null, 4], Metrics::COMBINE_SUM) === 4.0
		&& Overview::combine([], Metrics::COMBINE_SUM) === null && Overview::combine([null], Metrics::COMBINE_COUNT, fn($v) => true) === null);
	$lower = ['use_settings' => 0, 'worse' => Metrics::WORSE_LOWER, 'thresholds' => [['level' => 1, 'value' => '5000', 'color' => ''], ['level' => 2, 'value' => '1000', 'color' => 'AA0000']]];
	check('lower is worse: at or below the threshold', Overview::level(1000.0, $lower, [])['level'] === 'high' && Overview::level(1000.0, $lower, [])['color'] === '#AA0000'
		&& Overview::level(3000.0, $lower, [])['level'] === 'warn' && Overview::level(6000.0, $lower, [])['level'] === '' && Overview::level(null, $lower, [])['level'] === '');
	$higher = ['use_settings' => 0, 'worse' => Metrics::WORSE_HIGHER, 'thresholds' => [['level' => 1, 'value' => '60', 'color' => ''], ['level' => 2, 'value' => '300', 'color' => '']]];
	check('higher is worse: at or above', Overview::level(60.0, $higher, [])['level'] === 'warn' && Overview::level(301.0, $higher, [])['level'] === 'high'
		&& Overview::level(59.0, $higher, [])['level'] === '');
	check('Settings thresholds through ClientData::usageClass', Overview::level(85.0, ['use_settings' => 1], ['warn' => 80, 'high' => 90])['level'] === 'warn'
		&& Overview::level(90.0, ['use_settings' => 1], ['warn' => 80, 'high' => 90])['level'] === 'high');
	$t = Overview::trend(['a' => [100 => [10, 5, 20], 200 => [30, 25, 40]], 'b' => [100 => [1, 0, 2], 200 => [3, 2, 50]]], Metrics::COMBINE_SUM, 0, 1000);
	check('trend Sum: hour by hour sums of averages (11, 33): avg 22, min 11, max 33', $t['avg'] === 22.0 && $t['min'] === 11.0 && $t['max'] === 33.0, $t);
	$t = Overview::trend(['a' => [100 => [10, 5, 20], 200 => [30, 25, 40]], 'b' => [100 => [1, 0, 2], 200 => [3, 2, 50]]], Metrics::COMBINE_MAX, 0, 1000);
	check('trend Highest: max of maxes (50), highest trough (5)', $t['max'] === 50.0 && $t['min'] === 5.0, $t);
	$t = Overview::trend(['a' => [100 => [10, 5, 20]], 'b' => [100 => [1, 0, 2]]], Metrics::COMBINE_MIN, 0, 1000);
	check('trend Lowest: min of mins (0)', $t['min'] === 0.0 && $t['avg'] === 1.0, $t);
	check('trend outside the window is none, not zero', Overview::trend(['a' => [100 => [10, 5, 20]]], Metrics::COMBINE_SUM, 500, 1000) === null);

	$rows = [['name' => 'b', 'sort' => ['x' => 5], 'over' => true, 'problems_total' => 0], ['name' => 'a', 'sort' => ['x' => null], 'over' => false, 'problems_total' => 2],
		['name' => 'c', 'sort' => ['x' => 9], 'over' => true, 'problems_total' => 1]];
	check('arrange: highest first, unknown last', array_column(Overview::arrange($rows, 'x', Overview::ORDER_DESC, 0, null), 'name') === ['c', 'b', 'a']);
	check('arrange: lowest first, unknown still last', array_column(Overview::arrange($rows, 'x', Overview::ORDER_ASC, 0, null), 'name') === ['b', 'c', 'a']);
	check('arrange: top N', array_column(Overview::arrange($rows, 'name', Overview::ORDER_ASC, 0, 2), 'name') === ['a', 'b']);
	check('arrange: over a threshold', array_column(Overview::arrange($rows, 'name', Overview::ORDER_ASC, Overview::ONLY_OVER, null), 'name') === ['b', 'c']);
	check('arrange: with problems', array_column(Overview::arrange($rows, 'name', Overview::ORDER_ASC, Overview::ONLY_PROBLEMS, null), 'name') === ['a', 'c']);

	$p = [['objectid' => 1, 'severity' => 5, 'acknowledged' => 0, 'suppressed' => 0], ['objectid' => 2, 'severity' => 4, 'acknowledged' => 1, 'suppressed' => 0],
		['objectid' => 3, 'severity' => 3, 'acknowledged' => 0, 'suppressed' => 1], ['objectid' => 4, 'severity' => 1, 'acknowledged' => 0, 'suppressed' => 0]];
	$th = [1 => [10, 11], 2 => [10], 3 => [20], 4 => [10]];
	$hc = [10 => 'A', 11 => 'A', 20 => 'B'];
	check('countProblems: severities chosen, unacknowledged, unsuppressed, once per client',
		Overview::countProblems($p, $th, $hc, [5, 4, 3, 2], false, false) === ['A' => [5 => 1]]);
	check('countProblems: with acknowledged and suppressed', Overview::countProblems($p, $th, $hc, [5, 4, 3, 2], true, true) === ['A' => [5 => 1, 4 => 1], 'B' => [3 => 1]]);
	check('countProblems: Information when chosen', Overview::countProblems($p, $th, $hc, [1], false, false) === ['A' => [1 => 1]]);

	$now = time();
	$series = [];
	for ($h = 48; $h >= 0; $h--) {
		$series[$now - $h * 3600] = 50 + (48 - $h) * 0.5;
	}
	$f = Overview::forecast($series, 74.0, Settings::forecast(Settings::all()));
	check('forecast: 0.5 % an hour with 26 % left is about two days', $f !== null && $f['days'] > 2.0 && $f['days'] < 2.3 && $f['level'] === 'bad', $f);
	check('forecast: allocated unset (no share) is no forecast', Overview::forecast($series, null, []) === null);
	check('forecast: too little history is no forecast', Overview::forecast([$now => 50, $now - 60 => 49], 50.0, []) === null);
	check('forecast: flat is "not growing"', Overview::forecast([$now - 86400 => 50, $now => 50], 50.0, [])['level'] === 'flat');
	check('contract: past is high, inside High high, inside Warn warn, beyond ok', Overview::contract(date('Y-m-d', $now - 86400), $now, 60, 14)['level'] === 'high'
		&& Overview::contract(date('Y-m-d', $now + 10 * 86400), $now, 60, 14)['level'] === 'high'
		&& Overview::contract(date('Y-m-d', $now + 30 * 86400), $now, 60, 14)['level'] === 'warn'
		&& Overview::contract(date('Y-m-d', $now + 90 * 86400), $now, 60, 14)['level'] === 'ok' && Overview::contract(null, $now, 60, 14) === null);
	check('format: bytes, percent, short numbers, nothing', Overview::format(1.5 * 1099511627776, 'B', 2, true) === '1.5 TB'
		&& Overview::format(91.0, '%', 1, true) === '91%' && Overview::format(18400.0, '/s', 1, true) === '18.4k/s'
		&& Overview::format(42.0, 's', 0, false) === '42 s' && Overview::format(null, '%', 1, true) === '—');
	check('urls are encoded, arrays as name[]', Overview::url('zabbix.php', ['action' => 'ep.clients.edit', 'client' => 'a&b "c"']) === 'zabbix.php?action=ep.clients.edit&client=a%26b%20%22c%22');
	check('a client name is never a macro: ClientData::contractEnd reads only a real date', \Modules\EpOverview\Lib\ClientData::contractEnd(['{$EP.CONTRACT.END}' => '2026-02-30']) === null
		&& \Modules\EpOverview\Lib\ClientData::contractEnd(['{$EP.CONTRACT.END}' => '2026-12-31']) === '2026-12-31');


	/* ------------------------------- review fixes (2.1 QA) ------------------------------- */

	foreach (['*.*' => false, 'a*' => false, 'ab*' => false, '*abc' => false, 'abc*' => true, 'es.jvm[*]' => true, 'es.jvm[heap]' => false] as $key => $ok) {
		[, $e] = $metric(['source' => 2, 'key' => $key, 'pattern' => 1]);
		check("a pattern needs three plain characters before its first *: $key", ($e === []) === $ok, $e);
	}
	$many = ['config_version' => 2, 'metrics' => [['uid' => 'many', 'name' => 'Many', 'source' => 2, 'key' => 'es.many[*]', 'pattern' => 1,
		'combine' => Metrics::COMBINE_MAX, 'use_settings' => 0]], 'clients' => ['karthi']];
	$d = run($many);
	$row = $metricOf($byName($d)['karthi'], 'many');
	$call = array_values(array_filter(\FakeApi::$options, fn($c) => $c[0] === 'Item' && isset($c[1]['search'])))[0][1] ?? [];
	check('a pattern search is anchored at the start and limited', ($call['startSearch'] ?? false) === true && ($call['limit'] ?? 0) > 0
		&& $call['limit'] <= 10000, $call);
	check('a pattern matching 60 items on one server reads 50 and says it is capped', !empty($row['capped'])
		&& $row['now']['text'] === '49' && (bool) array_filter($byName($d)['karthi']['warnings'], fn($w) => strpos($w['text'], 'Many: the key pattern matches more items') === 0), $row);
	check('…and the graph link, which would hold 50 items, opens Latest data instead', strpos((string) $row['url'], 'action=latest.view') !== false, $row['url']);

	check('history in one-minute buckets, the last value of each', Overview::bucket([[100, 1], [130, 2], [170, 5]], 60) === [60 => [1.0, 1.0, 1.0], 120 => [5.0, 5.0, 5.0]]);
	$t = Overview::trend(['a' => Overview::bucket([[60, 10], [125, 20]]), 'b' => Overview::bucket([[75, 1], [170, 2]])], Metrics::COMBINE_SUM, 0, 1000);
	check('…so servers polled seconds apart are summed, not taken one at a time', $t['avg'] === 16.5 && $t['min'] === 11.0 && $t['max'] === 22.0, $t);

	check('one value per server: its worst item', Overview::perHost([[1, 70.0], [1, 20.0], [2, null], [3, 5.0]], false) === [1 => 70.0, 2 => null, 3 => 5.0]
		&& Overview::perHost([[1, 70.0], [1, 20.0]], true) === [1 => 20.0]);
	$hs = Overview::hostSeries([1 => ['a' => [100 => [70, 60, 80]], 'b' => [100 => [20, 10, 30]]]], false);
	check('…and in trends, the worst item\'s hour', $hs === [1 => [100 => [70, 60, 80]]], $hs);
	$perHost = ['config_version' => 2, 'clients' => ['karthi'], 'metrics' => [
		['uid' => 'javg', 'name' => 'JVM avg', 'source' => 2, 'key' => 'es.jvm[*]', 'pattern' => 1, 'combine' => Metrics::COMBINE_AVG, 'use_settings' => 0],
		['uid' => 'jcnt', 'name' => 'JVM count', 'source' => 2, 'key' => 'es.jvm[*]', 'pattern' => 1, 'combine' => Metrics::COMBINE_COUNT, 'use_settings' => 0,
			'thresholds' => [['level' => 1, 'value' => '10', 'color' => '']]]]];
	$k = $byName(run($perHost))['karthi'];
	check('Average over servers, not items: Forwarder-1\'s worst JVM item (70), not the mean of its two (45)', $metricOf($k, 'javg')['now']['text'] === '70', $metricOf($k, 'javg')['now']);
	check('Count over Warn counts servers, not items (1, not 2)', $metricOf($k, 'jcnt')['now']['text'] === '1', $metricOf($k, 'jcnt')['now']);
	check('…and is coloured by its worst server, never by holding the count against the server threshold', $metricOf($k, 'jcnt')['now']['level'] === 'warn'
		&& ($metricOf($k, 'jcnt')['max']['level'] ?? null) === '', $metricOf($k, 'jcnt'));

	foreach (['min' => '1e999', 'max' => '2e12'] as $f => $v) {
		[, $e] = $metric(['source' => 2, 'key' => 'k', $f => $v]);
		check("a bar $f of $v is refused, readably", $e !== [] && strpos($e[0], '1e12') !== false, $e);
	}
	[, $e] = $metric(['source' => 1, 'builtin' => 'storage.usage', 'use_settings' => 1, 'warn' => '1e999']);
	check('a Warn of 1e999 is refused', $e !== []);
	[, $e] = $metric(['source' => 2, 'key' => 'k', 'thresholds' => [['level' => 1, 'value' => '1e999']]]);
	check('a threshold of 1e999 (INF) is refused before it can vanish on the dashboard', $e !== [] && strpos($e[0], '1e12') !== false, $e);
	[$m, $e] = $metric(['source' => 2, 'key' => 'k', 'thresholds' => [['level' => 1, 'value' => '-1e12']]]);
	check('…while -1e12 is a number', $e === [], $e);


	/* -------------------------------- browser QA fixes -------------------------------- */

	$own = fn($worse, $w, $h) => $metric(['name' => 'Ingest', 'source' => 2, 'key' => 'k', 'worse' => $worse, 'use_settings' => 0,
		'thresholds' => [['level' => 1, 'value' => $w], ['level' => 2, 'value' => $h]]]);
	[, $e] = $own(Metrics::WORSE_HIGHER, '90', '80');
	check('higher is worse: Warn 90 above High 80 is refused', $e === ['Warn (90) must be below High (80) when higher is worse.'], $e);
	[, $e] = $own(Metrics::WORSE_HIGHER, '80', '90');
	check('…Warn 80 below High 90 is fine', $e === [], $e);
	[, $e] = $own(Metrics::WORSE_LOWER, '1000', '5000');
	check('lower is worse: Warn 1000 below High 5000 is refused', $e === ['Warn (1000) must be above High (5000) when lower is worse.'], $e);
	[, $e] = $own(Metrics::WORSE_LOWER, '5000', '1000');
	check('…Warn 5000 above High 1000 is fine', $e === [], $e);
	[, $e] = CWidgetFieldMetrics::cleanList([['name' => 'Ingest', 'source' => 2, 'key' => 'k', 'use_settings' => 0,
		'thresholds' => [['level' => 1, 'value' => '90'], ['level' => 2, 'value' => '80']]]], $cat, $roleIds, true);
	check('…and refused on Apply, naming the metric', $e === ['Metric "Ingest": Warn (90) must be below High (80) when higher is worse.'], $e);
	[, $e] = $metric(['name' => 'ES CPU', 'source' => 1, 'builtin' => 'fam.es.cpu', 'use_settings' => 1, 'warn' => '95', 'high' => '90']);
	check('every editor error names its metric', Metrics::named($e, 'ES CPU') === ['Metric "ES CPU": Warn must not be above High.'], Metrics::named($e, 'ES CPU'));
	check('…a metric with no name yet is "New metric", or its place in the list', Metrics::named(['Item key cannot be empty.'], '')[0] === 'Metric "New metric": Item key cannot be empty.'
		&& Metrics::named(['x'], '', 3)[0] === 'Metric "#3": x');


	check('Summary view: Warn at 90 above High at 50 is refused on Apply, readably',
		WidgetForm::pairErrors(['summary_warn' => '90', 'summary_high' => '50']) === ['Invalid parameter "Summary view: Warn at, %": must not be above High at, % (90 > 50).'],
		WidgetForm::pairErrors(['summary_warn' => '90', 'summary_high' => '50']));
	check('…Warn 50 / High 90, or either empty (Settings), is fine', WidgetForm::pairErrors(['summary_warn' => '50', 'summary_high' => '90']) === []
		&& WidgetForm::pairErrors(['summary_warn' => '90', 'summary_high' => '']) === []);
	check('the day counts pair up the same way: High within must not exceed Warn within', count(WidgetForm::pairErrors(['contract_warn' => 14, 'contract_high' => 60,
		'forecast_warn' => '7', 'forecast_high' => '30'])) === 2 && WidgetForm::pairErrors(['contract_warn' => 60, 'contract_high' => 14]) === []);

	foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
		unlink($dir.'/'.$f);
	}
	rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
