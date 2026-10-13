<?php
/**
 * The Volume report controller against a fake Zabbix, scoped to what stopped being a literal:
 * both template names now come from the Roles page's mapping, the client type default and the
 * decimal places from ElasticPro -> Settings — while the colours deliberately still come from
 * the plan template's own macros, because its triggers are what alarm on them.
 *   docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php test/view.test.php
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
 */
namespace {
	define('ITEM_STATE_NORMAL', 0); define('USER_TYPE_SUPER_ADMIN', 3);
	define('ITEM_VALUE_TYPE_FLOAT', 0); define('ITEM_VALUE_TYPE_UINT64', 3);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	// Guarded because PHP's gettext extension already defines _(), and a Zabbix frontend host
	// has gettext loaded — Zabbix uses it for translations. Without the guard every suite
	// died with "Cannot redeclare _()" on exactly the machine the modules run on. gettext's
	// _() returns its argument when no domain is bound, which is what the stub did anyway.
	if (!function_exists('_')) { function _($s) { return $s; } }
	if (!function_exists('_s')) { function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace('%'.($i + 1).'$s', (string) $v, $s); } return $s; } }
	function getSubGroups($ids) { return $ids; }
	function zbx_date2age($t) { return '5m'; }
	// Records the decimals it was asked for: this stand-in does not pad, so the places a figure
	// was drawn to cannot be read back out of its text.
	$GLOBALS['ep_decimals'] = [];
	function convertUnits(array $o) {
		$GLOBALS['ep_decimals'][$o['units']] = $o['decimals'] ?? null;
		return round($o['value'], $o['decimals'] ?? 2).($o['units'] !== '' ? ' '.ltrim($o['units'], '!') : '');
	}

	class CControllerDashboardWidgetView {
		public $fields_values = ['groupids' => []];
		public $widget;
		public $response;
		public function __construct() { $this->widget = new class { public function getDefaultName() { return 'Volume report'; } }; }
		public function getInput($k, $d = null) { return $d; }
		public function getDebugMode() { return 0; }
		public function getUserType() { return USER_TYPE_SUPER_ADMIN; }
		public function setResponse($r) { $this->response = $r; }
	}
	class CControllerResponseData { public $data; public function __construct($d) { $this->data = $d; } }
	class CCsrfTokenHelper { public static function get($a) { return 'token:'.$a; } }

	/**
	 * The fake Zabbix: a client plan template with three columns and the two threshold macros,
	 * two cluster hosts — karthi-ES-Cluster in client group "karthi" (whose master host says DI)
	 * and acme-ES-Cluster in "acme" (whose master host says nothing).
	 */
	class FakeApi {
		public static $plan = true;
		public static $master = true;
		/** A second plan template, as a site that mapped the slot and kept the shipped one has. */
		public static $two_plans = false;
		/** The templateids the controller asked for items and hosts by. */
		public static $used_ids = [];
		/** Every template name the controller asked Zabbix for, in order. */
		public static $asked_for = [];
		private $what;
		public function __construct($what) { $this->what = $what; }
		public function get(array $o) {
			switch ($this->what) {
				case 'Template':
					self::$asked_for[] = $o['filter']['host'];
					// The client master lookup asks for no macros; the plan lookup does.
					if (!isset($o['selectMacros'])) {
						return self::$master ? [['templateid' => 7]] : [];
					}
					if (!self::$plan) {
						return [];
					}
					// The mapped template is asked for first; Zabbix answers in its own order, so
					// the shipped one is deliberately returned FIRST here.
					$mapped = ['templateid' => 9, 'host' => self::$two_plans ? 'ACME plan' : 'ElasticPro client plan', 'macros' => [
						['macro' => '{$ELASTICPRO.PLAN.LIVE_USED.WARN}', 'value' => '85'],
						['macro' => '{$ELASTICPRO.PLAN.LIVE_DAYS.MIN}', 'value' => '14']
					]];
					$shipped = ['templateid' => 8, 'host' => 'ElasticPro client plan', 'macros' => [
						['macro' => '{$ELASTICPRO.PLAN.LIVE_USED.WARN}', 'value' => '99'],
						['macro' => '{$ELASTICPRO.PLAN.LIVE_DAYS.MIN}', 'value' => '1']
					]];
					return self::$two_plans ? [$shipped, $mapped] : [$mapped];
				case 'Item':
					// The template's items are the columns; a host's items are the figures.
					if (isset($o['templateids'])) {
						self::$used_ids = $o['templateids'];
						$tag = fn($plan, $col) => [['tag' => 'plan', 'value' => $plan], ['tag' => 'column', 'value' => (string) $col]];
						return [
							['key_' => 'plan[live.used.pct]', 'name' => 'Client plan: Live used %', 'units' => '%', 'value_type' => ITEM_VALUE_TYPE_FLOAT,
								'tags' => $tag('Live', 2)],
							['key_' => 'plan[live.days]', 'name' => 'Client plan: Live days', 'units' => 'days', 'value_type' => ITEM_VALUE_TYPE_FLOAT,
								'tags' => $tag('Live', 1)],
							['key_' => 'plan[note]', 'name' => 'Plan note', 'units' => '', 'value_type' => 4, 'tags' => $tag('Live', 3)],
							// No column tag: not a column at all.
							['key_' => 'elasticpro.plan.epoch', 'name' => 'Client plan: measured at', 'units' => '', 'value_type' => ITEM_VALUE_TYPE_UINT64,
								'tags' => [['tag' => 'plan', 'value' => 'Live']]]
						];
					}
					$all = [
						[301, 'plan[live.used.pct]', '90.5'], [301, 'plan[live.days]', '9'], [301, 'plan[note]', 'hot tier'],
						[301, 'elasticpro.plan.epoch', '1700000000'], [301, 'elasticpro.plan.reachable', '1'],
						[302, 'elasticpro.plan.reachable', '0']
					];
					$out = [];
					foreach ($all as [$h, $k, $v]) {
						if (in_array($h, $o['hostids']) && in_array($k, $o['filter']['key_'], true)) {
							$out[] = ['hostid' => $h, 'key_' => $k, 'lastvalue' => $v, 'lastclock' => 1, 'state' => 0];
						}
					}
					return $out;
				case 'Host':
					// The client-type lookup asks only for ids, off the master template.
					if (($o['output'] ?? null) === []) {
						return [101 => [], 102 => []];
					}
					$g = fn(...$n) => array_map(fn($x) => ['name' => $x], $n);
					return [
						301 => ['hostid' => 301, 'name' => 'karthi-ES-Cluster', 'hostgroups' => $g('karthi', 'Elasticsearch clusters'), 'tags' => []],
						302 => ['hostid' => 302, 'name' => 'acme-ES-Cluster', 'hostgroups' => $g('acme', 'Elasticsearch clusters'), 'tags' => []]
					];
				case 'UserMacro':
					// The master hosts' macros, for the client types.
					if (in_array(101, $o['hostids'], true)) {
						return [
							['hostid' => 101, 'macro' => '{$GRP.CLIENT}', 'value' => 'karthi'],
							['hostid' => 101, 'macro' => '{$EP.CLIENT.TYPE}', 'value' => 'DI'],
							['hostid' => 102, 'macro' => '{$GRP.CLIENT}', 'value' => 'acme']
						];
					}
					return [];
			}
		}
	}
	class API {
		public static function __callStatic($name, $args) { return new FakeApi($name); }
	}
}

namespace Modules\EpVolume\Test {
	require __DIR__.'/../actions/WidgetView.php';
	use Modules\EpVolume\Actions\WidgetView;
	use Modules\EpVolume\Lib\{Roles, Settings};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-vol-test-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);

	function run(array $fields = []): array {
		Settings::forget();
		\FakeApi::$asked_for = [];
		\FakeApi::$used_ids = [];
		$v = new WidgetView();
		$v->fields_values = $fields + ['groupids' => []];
		(new \ReflectionMethod($v, 'doAction'))->invoke($v);
		return $v->response->data;
	}
	function col(array $d, string $id): ?int {
		foreach ($d['columns'] as $i => $c) { if ($c['id'] === $id) { return $i; } }
		return null;
	}
	function cell(array $d, string $cluster, string $id): array {
		foreach ($d['rows'] as $row) {
			if ($row['cluster'] === $cluster) { return $row['cells'][col($d, $id)]; }
		}
		return [];
	}

	/* ---------------- it draws at all ---------------- */
	$d = run();
	check('no error', $d['error'] === null, $d['error']);
	check('clusters are sorted by name', array_column($d['rows'], 'cluster') === ['acme-ES-Cluster', 'karthi-ES-Cluster']);
	check('only the items tagged with a place are columns', array_column($d['columns'], 'id')
		=== ['plan[live.days]', 'plan[live.used.pct]', 'plan[note]'], array_column($d['columns'], 'id'));
	check('the item-name prefix is stripped from the label', $d['columns'][0]['label'] === 'Live days');
	check('an item not named that way keeps its whole name', $d['columns'][2]['label'] === 'Plan note');
	check('a figure nobody measured is "—"', cell($d, 'acme-ES-Cluster', 'plan[live.days]')['text'] === '—');
	check('an unreachable cluster says so', $d['rows'][0]['unreachable'] === true && $d['rows'][1]['unreachable'] === false);

	/* ---------------- the colours stay the template's ---------------- */
	// 90.5 % is over the template's warning of 85, and 9 days is under its minimum of 14.
	check('over the template\'s warning is yellow', cell($d, 'karthi-ES-Cluster', 'plan[live.used.pct]')['class'] === 'ep-warn');
	check('under the template\'s minimum days is red', cell($d, 'karthi-ES-Cluster', 'plan[live.days]')['class'] === 'ep-high');
	// The site's own thresholds govern the other two reports; here the template's triggers do.
	Settings::save(['usage' => ['warn' => 99, 'high' => 100]]);
	$d = run();
	check('and the site\'s thresholds do not move them, because the triggers use the template\'s',
		cell($d, 'karthi-ES-Cluster', 'plan[live.used.pct]')['class'] === 'ep-warn');
	Settings::reset();

	/* ---------------- decimals ---------------- */
	$GLOBALS['ep_decimals'] = [];
	run();
	check('percentages take the site\'s places and days stay whole',
		($GLOBALS['ep_decimals']['%'] ?? null) === 1 && ($GLOBALS['ep_decimals']['days'] ?? null) === 0, $GLOBALS['ep_decimals']);
	Settings::save(['display' => ['decimals_percent' => 3]]);
	$GLOBALS['ep_decimals'] = [];
	run();
	check('and follow the site when it changes its mind', ($GLOBALS['ep_decimals']['%'] ?? null) === 3, $GLOBALS['ep_decimals']);
	$GLOBALS['ep_decimals'] = [];
	run(['decimals' => 0]);
	check('a widget given its own places uses them everywhere',
		($GLOBALS['ep_decimals']['%'] ?? null) === 0 && ($GLOBALS['ep_decimals']['days'] ?? null) === 0, $GLOBALS['ep_decimals']);
	$GLOBALS['ep_decimals'] = [];
	run(['decimals' => Settings::INHERIT_CHOICE]);
	check('and the inherit value is never used as a number of places', ($GLOBALS['ep_decimals']['%'] ?? null) === 3);
	Settings::reset();

	/* ---------------- client types ---------------- */
	$d = run();
	check('a cluster takes its client\'s type', $d['rows'][1]['type'] === 'DI');
	check('a client that says nothing is the site default', $d['rows'][0]['type'] === 'On-Prem');
	check('the Type filter is offered the site\'s list, CI included', $d['meta']['types'] === ['CI', 'DI', 'On-Prem']);
	Settings::save(['types' => ['all' => ['DI', 'Managed'], 'default' => 'Managed']]);
	$d = run();
	check('and the default follows the site', $d['rows'][0]['type'] === 'Managed');
	check('as does the filter', $d['meta']['types'] === ['DI', 'Managed']);
	Settings::reset();

	/* ---------------- both templates come from the mapping ---------------- */
	$d = run();
	check('the plan template is asked for by every name the mapping answers to',
		\FakeApi::$asked_for[0] === Roles::templateNamesFor('plan'), \FakeApi::$asked_for);
	check('and so is the client master, which used to be a second literal here',
		in_array(Roles::templateNamesFor('master'), \FakeApi::$asked_for, true), \FakeApi::$asked_for);
	Roles::saveTemplateNames(['plan' => 'ACME plan', 'master' => 'ACME master']);
	$d = run();
	// The mapped name leads and the shipped one is still answered to, so a site mid-rename —
	// some hosts moved, some not — keeps seeing all of its clusters.
	check('a renamed plan template leads, with the shipped name still recognised',
		\FakeApi::$asked_for[0] === ['ACME plan', 'ElasticPro client plan'], \FakeApi::$asked_for);
	check('a renamed client master too',
		in_array(['ACME master', 'ElasticPro client master'], \FakeApi::$asked_for, true), \FakeApi::$asked_for);
	check('and the report still draws, types and all', $d['error'] === null && $d['rows'][1]['type'] === 'DI');

	// A client master that is missing leaves every cluster on the site default rather than failing.
	\FakeApi::$master = false;
	$d = run();
	check('no client master: the report still draws, on the site default type',
		$d['error'] === null && array_column($d['rows'], 'type') === ['On-Prem', 'On-Prem']);
	\FakeApi::$master = true;

	\FakeApi::$plan = false;
	$d = run();
	check('no plan template: the widget says so', $d['error'] !== null);
	check('and names what this Zabbix is meant to have', strpos((string) $d['error'], 'ACME plan') !== false, $d['error']);
	Roles::saveTemplateNames([]);
	\FakeApi::$plan = true;

	foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
		if (is_file($dir.'/'.$f)) { unlink($dir.'/'.$f); }
	}
	rmdir($dir);
}

namespace Modules\EpVolume\Test {
	use Modules\EpVolume\Lib\{Roles, Settings};

	/* ---------------- two plan templates at once ---------------- */
	// A site that maps the plan slot and still has the shipped template installed has two. The
	// controller took $templates[0], so which one drove the columns, the thresholds and the list
	// of clusters depended on database order — and clusters linked to the other one vanished.
	// The site has mapped the slot to its own name and kept the shipped template installed.
	Roles::saveTemplateNames(['plan' => 'ACME plan', 'plan_also' => 'ElasticPro client plan']);
	\FakeApi::$two_plans = true;
	$d = run();
	check('both plan templates are read, not just whichever came back first',
		\FakeApi::$used_ids === [9, 8], \FakeApi::$used_ids);
	check('the report still draws its columns', array_column($d['columns'], 'id')
		=== ['plan[live.days]', 'plan[live.used.pct]', 'plan[note]']);
	// 90.5 % is over the mapped template's warning of 85 but under the shipped one's 99. The
	// mapped name was asked for first, so its own thresholds are the ones that must apply.
	check('the mapped template\'s thresholds win over a shipped template left behind',
		cell($d, 'karthi-ES-Cluster', 'plan[live.used.pct]')['class'] === 'ep-warn',
		cell($d, 'karthi-ES-Cluster', 'plan[live.used.pct]')['class']);
	Roles::saveTemplateNames([]);
	\FakeApi::$two_plans = false;
	$d = run();
	check('and one template alone still behaves as before', \FakeApi::$used_ids === [9] && $d['error'] === null);

	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
