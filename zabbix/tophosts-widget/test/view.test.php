<?php
/**
 * The Top hosts controller against a fake Zabbix: the servers ClientData reads, each coloured at
 * its own client's thresholds, the Client and Client type settings, and what it says when the
 * named client is not there.
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
						[301, 'system.cpu.util', 35], [301, 'vm.memory.utilization', 10]
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
namespace Modules\EpTophosts\Test {
	require __DIR__.'/../actions/WidgetView.php';
	use Modules\EpTophosts\Actions\WidgetView;
	use Modules\EpTophosts\Includes\WidgetForm;
	use Modules\EpTophosts\Lib\Settings;

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
		$v = new WidgetView();
		$v->fields_values = $fields + ['groupids' => []];
		$m = new \ReflectionMethod($v, 'doAction');
		$m->invoke($v);
		return $v->response->data;
	}
	$byHost = fn($d) => array_column($d['servers'], null, 'host');

	$d = run();
	check('every client\'s servers, decommissioned clients left out', count($d['servers']) === 4
		&& !in_array('gone', array_column($d['servers'], 'client'), true), array_column($d['servers'], 'host'));
	$h = $byHost($d);
	check('all three figures go to the page, so the switch needs no reload', $h['karthi-ES-Data-Hot-1']['cpu'] === 85.0
		&& $h['karthi-ES-Data-Hot-1']['mem'] === 40.0 && $h['karthi-ES-Data-Hot-1']['disk'] === 99.0);
	check('not measured stays unknown, never zero', $h['karthi-Forwarder-1']['mem'] === null && $h['karthi-Forwarder-1']['disk'] === null);
	check('colours at the client\'s thresholds: karthi at the defaults, 85 % CPU is warn', $h['karthi-ES-Data-Hot-1']['classes']['cpu'] === 'ep-warn');
	check('acme\'s own thresholds (30 / 40): 35 % CPU is warn there', $h['acme-ES-Data-Hot-1']['classes']['cpu'] === 'ep-warn', $h['acme-ES-Data-Hot-1']['classes']);
	check('each host links to its Latest data', strpos($h['karthi-Parser-1']['url'], 'latest.view') !== false && strpos($h['karthi-Parser-1']['url'], '202') !== false);
	check('rank and count from the settings, CPU and 10 by default', $d['rank'] === 'cpu' && $d['count'] === 10 && $d['scope'] === '');

	$d = run(['rank' => 2, 'count' => 3]);
	check('Rank by: fullest disk, three hosts', $d['rank'] === 'disk' && $d['count'] === 3);

	$d = run(['client' => 'ACME']);
	check('Client: one client, matched without regard to case', array_unique(array_column($d['servers'], 'client')) === ['acme'] && $d['scope'] === 'ACME');
	$d = run(['client' => 'nobody']);
	check('a client that is not there is said, not shown as an empty list', strpos((string) $d['error'], 'No client named "nobody"') !== false);

	$d = run(['client_type' => WidgetForm::typeKey('DI')]);
	check('Client type: only that type\'s clients', array_unique(array_column($d['servers'], 'client')) === ['karthi'] && $d['scope'] === 'DI');
	check('the stored type number comes from the name, so reordering the site\'s types keeps it',
		WidgetForm::typeKey('DI') === WidgetForm::typeKey('DI') && WidgetForm::typeKey('DI') !== WidgetForm::typeKey('On-Prem') && WidgetForm::typeKey('DI') > 0);

	/* ---------------- a single node: ES, Parser, Forwarder and Engine on one box ---------------- */
	$r = fn($fam, $extra = []) => ['id' => $fam, 'family' => $fam] + $extra;
	check('ES, Parser, Forwarder and Engine on one machine is a single node',
		\Modules\EpTophosts\Lib\ClientData::isSingleNode([$r('es'), $r('parser'), $r('fwd'), $r('engine')]));
	check('three of the four is not', !\Modules\EpTophosts\Lib\ClientData::isSingleNode([$r('es'), $r('parser'), $r('engine')]));
	check('the all-in-one role still is', \Modules\EpTophosts\Lib\ClientData::isSingleNode([$r('single', ['exclusive' => true])]));

	foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
		unlink($dir.'/'.$f);
	}
	rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
