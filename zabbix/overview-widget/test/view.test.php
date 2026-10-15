<?php
/**
 * The Client overview controller against a fake Zabbix: each client's figures and colour classes
 * at its own thresholds, the panels and family the settings choose, Client and Client type.
 *   docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php test/view.test.php
 */
namespace Zabbix\Widgets {
	class CWidgetForm {}
}
namespace {
	define('ITEM_STATE_NORMAL', 0); define('INTERFACE_TYPE_AGENT', 1); define('INTERFACE_PRIMARY', 1);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	if (!function_exists('_')) { function _($s) { return $s; } }
	if (!function_exists('_s')) { function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace('%'.($i + 1).'$s', (string) $v, $s); } return $s; } }
	function getSubGroups($ids) { return $ids; }

	class CControllerDashboardWidgetView {
		public $fields_values = ['groupids' => []];
		public $widget;
		public $response;
		public function __construct() { $this->widget = new class { public function getDefaultName() { return 'Top hosts'; } }; }
		public function getInput($k, $d = null) { return $d; }
		public function getDebugMode() { return 0; }
		public function setResponse($r) { $this->response = $r; }
	}
	class CControllerResponseData { public $data; public function __construct($d) { $this->data = $d; } }
	class CUrl {
		private $args = [];
		public function __construct($p) {}
		public function setArgument($k, $v) { $this->args[$k] = $v; return $this; }
		public function getUrl() { return 'zabbix.php?'.http_build_query($this->args); }
	}

	/** Two clients: karthi (DI) with three measured servers, acme (On-Prem) with one, and acme's thresholds lowered. */
	class FakeApi {
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
						return [101 => ['hostid' => 101, 'name' => 'karthi-Master'], 102 => ['hostid' => 102, 'name' => 'acme-Master'],
							103 => ['hostid' => 103, 'name' => 'gone-Master']];
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
					$all = [
						[201, 'system.cpu.util', 85], [201, 'vm.memory.utilization', 40], [201, 'vfs.fs.dependent.size[/,pused]', 99],
						[202, 'system.cpu.util', 20], [202, 'vfs.fs.dependent.size[/,pused]', 91],
						[204, 'system.cpu.util', 95],
						[301, 'system.cpu.util', 35], [301, 'vm.memory.utilization', 10],
						[101, 'ep.es.cpu.usage', 95], [101, 'ep.es.cpu.allocated', 16], [101, 'ep.es.cpu.requested', 32],
						[101, 'ep.parser.mem.usage', 82], [101, 'ep.es.storage.usage', 61], [101, 'ep.es.storage.used', 600 * 1073741824],
						[101, 'ep.devices.purchased', 0], [101, 'ep.devices.seen', 40],
						[102, 'ep.es.cpu.usage', 35]
					];
					$out = [];
					foreach ($all as [$h, $k, $v]) {
						if (in_array($h, $o['hostids']) && in_array($k, $o['filter']['key_'], true)) {
							$out[] = ['hostid' => $h, 'key_' => $k, 'lastvalue' => (string) $v, 'lastclock' => 1, 'state' => 0];
						}
					}
					return $out;
			}
		}
	}
	class API {
		public static function __callStatic($name, $args) { return new FakeApi($name); }
	}
}
namespace Modules\EpOverview\Test {
	require __DIR__.'/../actions/WidgetView.php';
	use Modules\EpOverview\Actions\WidgetView;
	use Modules\EpOverview\Includes\WidgetForm;
	use Modules\EpOverview\Lib\Settings;

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-ov-test-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);

	function run(array $fields = []): array {
		Settings::forget();
		$v = new WidgetView();
		$v->fields_values = $fields + ['groupids' => []];
		$m = new \ReflectionMethod($v, 'doAction');
		$m->invoke($v);
		return $v->response->data;
	}
	$byName = fn($d) => array_column($d['overview']['clients'], null, 'name');

	$d = run();
	$ov = $d['overview'];
	check('every client but the decommissioned one, by name', array_column($ov['clients'], 'name') === ['acme', 'karthi'], array_column($ov['clients'], 'name'));
	$k = $byName($d)['karthi'];
	check('ES CPU with its class at the default thresholds: 95 % high', $k['families']['es']['cpu']['usage'] === 95.0 && $k['families']['es']['cpu']['class'] === 'ep-high'
		&& $k['families']['es']['cpu']['requested'] === 32.0 && $k['families']['es']['cpu']['allocated'] === 16.0, $k['families']['es']['cpu']);
	check('every family is there, not only ES', $k['families']['parser']['mem']['usage'] === 82.0 && $k['families']['parser']['mem']['class'] === 'ep-warn');
	check('storage in bytes, with its class', $k['storage']['used'] === 600.0 * 1073741824 && $k['storage']['class'] === '');
	check('a request of 0 is not set, never zero', $k['devices']['purchased'] === null && $k['devices']['seen'] === 40.0);
	check('servers with their status and CPU and memory classes', count($k['servers']) === 3 && in_array('down', array_column($k['servers'], 'status'), true)
		&& isset($k['servers'][0]['classes']['cpu']) && !isset($k['servers'][0]['classes']['disk']));
	$a = $byName($d)['acme'];
	check('acme at its own thresholds (30 / 40): 35 % ES CPU is warn', $a['families']['es']['cpu']['class'] === 'ep-warn', $a['families']['es']['cpu']);
	check('by default every panel, cards for ES, no client chosen', $ov['settings']['panels'] === ['summary' => true, 'used' => true, 'roles' => true]
		&& $ov['settings']['family'] === 'es' && $ov['settings']['client'] === '');

	$d = run(['show_used' => 0, 'show_roles' => 0, 'family' => WidgetForm::key('parser')]);
	check('the panels and the family the settings choose', $d['overview']['settings']['panels'] === ['summary' => true, 'used' => false, 'roles' => false]
		&& $d['overview']['settings']['family'] === 'parser');

	$d = run(['client' => 'KARTHI']);
	check('Client: that client alone, opened on its own view', array_column($d['overview']['clients'], 'name') === ['karthi'] && $d['overview']['settings']['client'] === 'karthi');
	$d = run(['client' => 'nobody']);
	check('a client that is not there is said', strpos((string) $d['error'], 'No client named "nobody"') !== false);
	$d = run(['client_type' => WidgetForm::key('On-Prem')]);
	check('Client type: only that type', array_column($d['overview']['clients'], 'name') === ['acme']);

	foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
		unlink($dir.'/'.$f);
	}
	rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
