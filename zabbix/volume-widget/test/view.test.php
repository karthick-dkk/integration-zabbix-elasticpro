<?php
/**
 * The Volume controller against a fake Zabbix API: the columns the client plan template's items
 * make, coloured at the template's macros (what its triggers alarm on) or at each column's own
 * thresholds, decimals, the client types, both templates coming from the Roles page's mapping —
 * and the column model in the widget: the Columns file written by every release before 2.1.1 read
 * onto it, a widget saved before 2.1.1 looking as it did, its own columns (any other item of the
 * cluster host, windows, calculations), group headers by plan, totals, sort and top N.
 *   docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php test/view.test.php
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
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
	define('ITEM_STATE_NORMAL', 0); define('USER_TYPE_SUPER_ADMIN', 3); define('INTERFACE_TYPE_AGENT', 1); define('INTERFACE_PRIMARY', 1);
	define('ITEM_VALUE_TYPE_FLOAT', 0); define('ITEM_VALUE_TYPE_UINT64', 3);
	define('ZBX_WIDGET_FIELD_TYPE_INT32', 0); define('ZBX_WIDGET_FIELD_TYPE_STR', 1);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	// Guarded because PHP's gettext extension already defines _(), and a Zabbix frontend host
	// has gettext loaded — Zabbix uses it for translations. Without the guard every suite
	// died with "Cannot redeclare _()" on exactly the machine the modules run on.
	if (!function_exists('_')) { function _($s) { return $s; } }
	if (!function_exists('_s')) { function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace('%'.($i + 1).'$s', (string) $v, $s); } return $s; } }
	if (!function_exists('_n')) { function _n($a, $b, $n) { return str_replace('%1$s', (string) $n, $n == 1 ? $a : $b); } }
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
	class CControllerResponseData { public $data; public function __construct($d) { $this->data = $d; } public function disableView(): self { return $this; } }
	class CController {
		public array $input = [];
		public $response;
		public function __construct(array $input = []) { $this->input = $input; }
		protected function validateInput(array $rules): bool { return true; }
		protected function getInput($k, $d = null) { return $this->input[$k] ?? $d; }
		protected function hasInput($k): bool { return array_key_exists($k, $this->input); }
		protected function getAction(): string { return 'widget.ep_volume.columns'; }
		protected function getUserType(): int { return USER_TYPE_SUPER_ADMIN; }
		protected function setResponse($r): void { $this->response = $r; }
		protected function getInputAll(): array { return $this->input; }
		protected function getDebugMode(): int { return 0; }
		protected function disableCsrfValidation(): void {}
	}
	class CCsrfTokenHelper { public static function get($a) { return 'token:'.$a; } }
	class CTag {
		protected $tag; protected $items = []; protected $classes = []; protected $attrs = [];
		public function __construct($tag = 'div', $ignored = true, $items = null) {
			$this->tag = $tag;
			if ($items !== null) { $this->items = is_array($items) ? $items : [$items]; }
		}
		public function addClass($c) { if ($c !== null && $c !== '') { $this->classes[] = $c; } return $this; }
		public function setAttribute($k, $v) { $this->attrs[$k] = $v; return $this; }
		public function toString() {
			$a = $this->classes ? ' class="'.implode(' ', $this->classes).'"' : '';
			foreach ($this->attrs as $k => $v) { $a .= ' '.$k.'="'.$v.'"'; }
			$inner = '';
			foreach ($this->items as $i) { $inner .= is_object($i) ? $i->toString() : (string) $i; }
			return '<'.$this->tag.$a.'>'.$inner.'</'.$this->tag.'>';
		}
		public function __toString() { return $this->toString(); }
	}
	class CDiv extends CTag { public function __construct($items = null) { parent::__construct('div', true, $items); } }
	class CSpan extends CTag { public function __construct($items = null) { parent::__construct('span', true, $items); } }

	/**
	 * The fake Zabbix: a client plan template with three columns and the two threshold macros,
	 * two cluster hosts — karthi-ES-Cluster in client group "karthi" (whose master host says DI)
	 * and acme-ES-Cluster in "acme" (whose master host says nothing, and whose cluster cannot be
	 * reached).
	 */
	class FakeApi {
		public static $plan = true;
		public static $master = true;
		/** A second plan template, as a site that mapped the slot and kept the shipped one has. */
		public static $two_plans = false;
		/** A column the template gains. */
		public static $extra_column = false;
		/** The templateids the controller asked for items and hosts by. */
		public static $used_ids = [];
		/** Every template name the controller asked Zabbix for, in order. */
		public static $asked_for = [];
		public static $extra_macros = [];
		public static $asked = [];
		public static $asked_trend = [];
		/** itemid => [clock => [avg, min, max]] */
		public static $trends = [];
		/** "host|key" => a last value in place of the one below (the export's places). */
		public static $override = [];
		public static function values(): array {
			return array_map(fn($r) => array_key_exists($r[0].'|'.$r[1], self::$override) ? [$r[0], $r[1], self::$override[$r[0].'|'.$r[1]]] : $r, [
				[301, 'plan[live.used.pct]', '90.5'], [301, 'plan[live.days]', '9'], [301, 'plan[note]', 'hot tier'], [301, 'plan[backup.bytes]', '3'],
				[301, 'elasticpro.plan.epoch', '1700000000'], [301, 'elasticpro.plan.reachable', '1'], [301, 'es.docs.rate', '1500'],
				[302, 'elasticpro.plan.reachable', '0'], [302, 'plan[live.used.pct]', '85'], [302, 'plan[live.days]', '14']
			]);
		}
		public static function itemid(int $host, string $key): string {
			foreach (self::values() as $i => [$h, $k]) { if ($h === $host && $k === $key) { return (string) ($h * 1000 + $i); } }
			return '';
		}
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
						$items = [
							['key_' => 'plan[live.used.pct]', 'name' => 'Client plan: Live used %', 'units' => '%', 'value_type' => ITEM_VALUE_TYPE_FLOAT, 'tags' => $tag('Live', 2)],
							['key_' => 'plan[live.days]', 'name' => 'Client plan: Live days', 'units' => 'days', 'value_type' => ITEM_VALUE_TYPE_FLOAT, 'tags' => $tag('Live', 1)],
							['key_' => 'plan[note]', 'name' => 'Plan note', 'units' => '', 'value_type' => 4, 'tags' => $tag('Live', 3)],
							// No column tag: not a column at all.
							['key_' => 'elasticpro.plan.epoch', 'name' => 'Client plan: measured at', 'units' => '', 'value_type' => ITEM_VALUE_TYPE_UINT64,
								'tags' => [['tag' => 'plan', 'value' => 'Live']]]
						];
						if (self::$extra_column) {
							$items[] = ['key_' => 'plan[backup.bytes]', 'name' => 'Client plan: Backup size', 'units' => '!GB', 'value_type' => ITEM_VALUE_TYPE_FLOAT, 'tags' => $tag('Backup', 4)];
						}
						return $items;
					}
					$out = [];
					foreach (self::values() as $i => [$h, $k, $v]) {
						if (!in_array($h, $o['hostids'])) { continue; }
						$hit = isset($o['filter']['key_']) && in_array($k, (array) $o['filter']['key_'], true);
						foreach ((array) ($o['search']['key_'] ?? []) as $p) { $hit = $hit || strpos($k, rtrim(explode('*', $p)[0])) === 0; }
						if ($hit) {
							$out[] = ['itemid' => (string) ($h * 1000 + $i), 'hostid' => $h, 'key_' => $k, 'lastvalue' => $v, 'lastclock' => time() - 60, 'state' => 0, 'units' => $k === 'plan[live.used.pct]' ? '%' : ($k === 'plan[live.days]' ? 'days' : ''),
								'value_type' => $k === 'plan[note]' ? 4 : 0, 'name' => $k];
						}
					}
					self::$asked[] = $o;
					return $out;
				case 'Host':
					// The client masters (by the master template), then the cluster hosts (by the plan templates).
					if (($o['templateids'] ?? null) === [7]) {
						return [101 => ['hostid' => 101, 'name' => 'karthi-Master', 'maintenance_status' => 0], 102 => ['hostid' => 102, 'name' => 'acme-Master', 'maintenance_status' => 0]];
					}
					$g = fn(...$n) => array_map(fn($x) => ['name' => $x], $n);
					return [
						301 => ['hostid' => 301, 'name' => 'karthi-ES-Cluster', 'hostgroups' => $g('karthi', 'Elasticsearch clusters'), 'tags' => []],
						302 => ['hostid' => 302, 'name' => 'acme-ES-Cluster', 'hostgroups' => $g('acme', 'Elasticsearch clusters'), 'tags' => []]
					];
				case 'UserMacro':
					if (in_array(101, $o['hostids'], true)) {
						return [
							['hostid' => 101, 'macro' => '{$GRP.CLIENT}', 'value' => 'karthi'],
							['hostid' => 101, 'macro' => '{$EP.CLIENT.TYPE}', 'value' => 'DI'],
							['hostid' => 102, 'macro' => '{$GRP.CLIENT}', 'value' => 'acme']
						];
					}
					return array_map(fn($m) => ['hostid' => 301, 'macro' => $m[0], 'value' => $m[1]], self::$extra_macros);
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

namespace Modules\EpVolume\Test {
	require __DIR__.'/../lib/Bars.php';
	require __DIR__.'/../actions/WidgetView.php';
	use Modules\EpVolume\Actions\WidgetView;
	use Modules\EpVolume\Includes\{Catalogue, WidgetForm};
	use Modules\EpVolume\Lib\{ColumnSettings, Columns, Roles, Settings, TableOptions};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-vol-test-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);
	$file = fn() => $dir.'/columns-volume.json';

	function run(array $fields = []): array {
		Settings::forget();
		Catalogue::forget();
		\FakeApi::$asked_for = [];
		\FakeApi::$used_ids = [];
		\FakeApi::$asked = [];
		\FakeApi::$asked_trend = [];
		$v = new WidgetView();
		$v->fields_values = $fields + ['groupids' => []];
		(new \ReflectionMethod($v, 'doAction'))->invoke($v);
		return $v->response->data;
	}
	function col(array $d, string $id): ?int {
		foreach ($d['columns'] as $i => $c) { if ($c['builtin'] === $id || $c['uid'] === $id) { return $i; } }
		return null;
	}
	function cell(array $d, string $cluster, string $id): array {
		foreach ($d['rows'] as $row) {
			if ($row['client'] === $cluster) { return $row['cells'][col($d, $id)]; }
		}
		return [];
	}
	$K = 'karthi-ES-Cluster'; $A = 'acme-ES-Cluster';
	$USED = 'plan[live.used.pct]'; $DAYS = 'plan[live.days]'; $NOTE = 'plan[note]';

	/* ---------------- it draws at all ---------------- */
	$d = run();
	check('no error', $d['error'] === null, $d['error']);
	check('clusters are sorted by name', array_column($d['rows'], 'client') === [$A, $K]);
	check('the cluster first, then only the items tagged with a place, in their order', array_column($d['columns'], 'builtin') === ['client.name', $DAYS, $USED, $NOTE],
		array_column($d['columns'], 'builtin'));
	check('the item-name prefix is stripped from the label', $d['columns'][1]['name'] === 'Live days' && $d['columns'][3]['name'] === 'Plan note');
	check('the sections are the plan tags: group headers by plan', array_column($d['columns'], 'group') === ['', 'Live', 'Live', 'Live']);
	check('a figure nobody measured is "—", never 0', cell($d, $A, $NOTE)['text'] === '—' && $d['export']['rows'][0][3] === null);
	check('a text item is shown as it is, and exported as it is', cell($d, $K, $NOTE)['text'] === 'hot tier' && $d['export']['rows'][1][3] === 'hot tier');
	check('numbers are exported as numbers, with the item\'s units in the header', $d['export']['rows'][1][2] === 90.5 && $d['export']['headers'][2] === 'Live used % (%)'
		&& $d['export']['headers'][1] === 'Live days (days)', $d['export']['headers']);
	check('an unreachable cluster is dimmed and says so; a reachable one says when it was updated', $d['rows'][0]['row_class'] === 'ep-unreachable'
		&& strpos($d['rows'][0]['first_title'], 'Cluster unreachable') === 0 && $d['rows'][1]['row_class'] === null && $d['rows'][1]['first_title'] === 'Updated 5m ago'
		&& strpos($d['rows'][0]['first_title'], 'Never updated') !== false, [$d['rows'][0]['first_title'], $d['rows'][1]['first_title']]);

	/* ---------------- the colours: the plan template's macros ---------------- */
	// 90.5 % is over the template's warning of 85, and 9 days is under its minimum of 14.
	$lv = fn(array $c) => $c['level'];
	check('over the template\'s warning is yellow', $lv(cell($d, $K, $USED)) === 'warn' && strpos(cell($d, $K, $USED)['class'], 'ep-fill-warn') !== false, cell($d, $K, $USED));
	check('under the template\'s minimum days is red', $lv(cell($d, $K, $DAYS)) === 'high' && strpos(cell($d, $K, $DAYS)['class'], 'ep-fill-high') !== false);
	check('exactly at the threshold the triggers do not fire: 85 % is not over 85, 14 days is not under 14', $lv(cell($d, $A, $USED)) === '' && $lv(cell($d, $A, $DAYS)) === '');
	check('a column that is not one of those two is not coloured', $lv(cell($d, $K, $NOTE)) === '');
	// The site's own thresholds govern the other reports; here the template's triggers do.
	Settings::save(['usage' => ['warn' => 99, 'high' => 100]]);
	check('the site\'s thresholds do not move them, because the triggers use the template\'s', $lv(cell(run(), $K, $USED)) === 'warn');
	Settings::reset();
	\FakeApi::$extra_macros = [['{$ELASTICPRO.PLAN.LIVE_USED.WARN}', '95']];
	check('a cluster host\'s own macro beats the template\'s', $lv(cell(run(), $K, $USED)) === '');
	\FakeApi::$extra_macros = [];
	// Per column: each column\'s own thresholds, seeded from the template\'s macros.
	$d = run(['thresholds_from' => '1']);
	check('per column: the columns start at the template\'s numbers (at or above Warn, at or below the minimum)', $lv(cell($d, $K, $USED)) === 'warn'
		&& $lv(cell($d, $K, $DAYS)) === 'high' && $lv(cell($d, $A, $USED)) === 'warn' && $lv(cell($d, $A, $DAYS)) === 'high', [cell($d, $A, $USED)['level'], cell($d, $A, $DAYS)['level']]);
	$cols = array_slice(ColumnSettings::resolve(null, Catalogue::columns()), 0, 4);
	$usedCol = array_values(array_filter($cols, fn($c) => $c['builtin'] === $USED))[0];
	$usedCol['thresholds'] = [['level' => 1, 'color' => '', 'value' => '95'], ['level' => 2, 'color' => '#AA0000', 'value' => '99']];
	$d = run(['thresholds_from' => '1', 'columns_from' => '1', 'columns' => array_map(fn($c) => $c['builtin'] === $USED ? $usedCol : $c, $cols)]);
	check('per column: a column\'s own thresholds decide, and the plan\'s macros no longer do', $lv(cell($d, $K, $USED)) === '', cell($d, $K, $USED));
	$usedCol['thresholds'][0]['value'] = '90';
	$d = run(['thresholds_from' => '1', 'columns_from' => '1', 'columns' => array_map(fn($c) => $c['builtin'] === $USED ? $usedCol : $c, $cols)]);
	check('…90 is crossed by 90.5', $lv(cell($d, $K, $USED)) === 'warn');

	/* ---------------- decimals ---------------- */
	$GLOBALS['ep_decimals'] = [];
	run();
	check('percentages take the site\'s places and days stay whole', ($GLOBALS['ep_decimals']['%'] ?? null) === 1 && ($GLOBALS['ep_decimals']['days'] ?? null) === 0, $GLOBALS['ep_decimals']);
	Settings::save(['display' => ['decimals_percent' => 3]]);
	$GLOBALS['ep_decimals'] = [];
	run();
	check('and follow the site when it changes its mind', ($GLOBALS['ep_decimals']['%'] ?? null) === 3, $GLOBALS['ep_decimals']);
	$GLOBALS['ep_decimals'] = [];
	run(['decimals' => 0]);
	check('a widget given its own places uses them everywhere', ($GLOBALS['ep_decimals']['%'] ?? null) === 0 && ($GLOBALS['ep_decimals']['days'] ?? null) === 0, $GLOBALS['ep_decimals']);
	$GLOBALS['ep_decimals'] = [];
	run(['decimals' => Settings::INHERIT_CHOICE]);
	check('and the inherit value is never used as a number of places', ($GLOBALS['ep_decimals']['%'] ?? null) === 3);
	Settings::reset();

	/* ---------------- the export's places: two, as Volume has always exported ---------------- */
	// 2.1.0 wrote round($raw, 2) for every number, whatever Decimals said; the shared engine's own
	// export writes a percentage to one place, so Volume says its own (WidgetView::EXPORT_PLACES).
	\FakeApi::$override = ['301|plan[live.used.pct]' => '90.456', '301|plan[live.days]' => '9.876'];
	$exported = function (array $d) use ($K, $USED, $DAYS) {
		$at = array_search($K, array_column($d['rows'], 'client'), true);
		return [$d['export']['rows'][$at][col($d, $USED)], $d['export']['rows'][$at][col($d, $DAYS)]];
	};
	$d = run();
	check('export: a percentage to two places, as before 2.1.1 — 90.456 is 90.46, not 90.5', $exported($d)[0] === 90.46, $exported($d));
	check('export: days to two places too — 9.876 is 9.88', $exported($d)[1] === 9.88, $exported($d));
	check('…while the table draws them to the site\'s places', ($GLOBALS['ep_decimals']['%'] ?? null) === 1);
	Settings::save(['display' => ['decimals_percent' => 3, 'decimals_plain' => 0]]);
	check('the site\'s Decimals do not move the export', $exported(run()) === [90.46, 9.88], $exported(run()));
	check('nor does the widget\'s own Decimals', $exported(run(['decimals' => 0])) === [90.46, 9.88] && $exported(run(['decimals' => 3])) === [90.46, 9.88]);
	Settings::reset();
	\FakeApi::$override = [];

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
	check('client types only', array_column(run(['client_types' => ['DI']])['rows'], 'client') === [$K]);
	check('named clients only (a cluster\'s client is its host group)', array_column(run(['clients' => ['KARTHI']])['rows'], 'client') === [$K]);
	check('a client that is not there is said', strpos((string) run(['clients' => ['nobody']])['error'], 'No client named "nobody"') === 0);

	/* ---------------- both templates come from the mapping ---------------- */
	$d = run();
	check('the plan template is asked for by every name the mapping answers to', \FakeApi::$asked_for[0] === Roles::templateNamesFor('plan'), \FakeApi::$asked_for);
	check('and so is the client master, which used to be a second literal here', in_array(Roles::templateNamesFor('master'), \FakeApi::$asked_for, true), \FakeApi::$asked_for);
	Roles::saveTemplateNames(['plan' => 'ACME plan', 'master' => 'ACME master']);
	$d = run();
	check('a renamed plan template leads, with the shipped name still recognised', \FakeApi::$asked_for[0] === ['ACME plan', 'ElasticPro client plan'], \FakeApi::$asked_for);
	check('a renamed client master too', in_array(['ACME master', 'ElasticPro client master'], \FakeApi::$asked_for, true), \FakeApi::$asked_for);
	check('and the report still draws, types and all', $d['error'] === null && $d['rows'][1]['type'] === 'DI');
	\FakeApi::$master = false;
	$d = run();
	check('no client master: the report still draws, on the site default type', $d['error'] === null && array_column($d['rows'], 'type') === ['On-Prem', 'On-Prem']);
	\FakeApi::$master = true;
	\FakeApi::$plan = false;
	$d = run();
	check('no plan template: the widget says so', $d['error'] !== null);
	check('and names what this Zabbix is meant to have', strpos((string) $d['error'], 'ACME plan') !== false, $d['error']);
	Roles::saveTemplateNames([]);
	\FakeApi::$plan = true;

	// A site that maps the plan slot and still has the shipped template installed has two.
	Roles::saveTemplateNames(['plan' => 'ACME plan', 'plan_also' => 'ElasticPro client plan']);
	\FakeApi::$two_plans = true;
	$d = run();
	check('both plan templates are read, not just whichever came back first', \FakeApi::$used_ids === [9, 8], \FakeApi::$used_ids);
	check('the report still draws its columns', array_column($d['columns'], 'builtin') === ['client.name', $DAYS, $USED, $NOTE]);
	check('the mapped template\'s thresholds win over a shipped template left behind (90.5 is over 85, under 99)', $lv(cell($d, $K, $USED)) === 'warn');
	Roles::saveTemplateNames([]);
	\FakeApi::$two_plans = false;
	$d = run();
	check('and one template alone still behaves as before', \FakeApi::$used_ids === [9] && $d['error'] === null);

	/* ---------------- the Columns file every release before 2.1.1 wrote ---------------- */
	$order = [$NOTE, $DAYS, $USED];
	ColumnSettings::save('volume', ['labels' => [$USED => 'Used live'], 'hidden' => [$DAYS], 'order' => $order, 'split' => []], null);
	$v1 = file_get_contents($file());
	$d = run();
	check('a renamed column is renamed, in the table and the export', $d['columns'][col($d, $USED)]['name'] === 'Used live' && $d['export']['headers'][col($d, $USED)] === 'Used live (%)');
	check('a hidden column is gone from the table and the export', col($d, $DAYS) === null && count($d['export']['headers']) === 3);
	check('moved: the note first among the template\'s columns (the cluster, which the file never knew, stays first)', array_column($d['columns'], 'builtin') === ['client.name', $NOTE, $USED],
		array_column($d['columns'], 'builtin'));
	check('the version-1 file is read, not rewritten', file_get_contents($file()) === $v1);
	\FakeApi::$extra_column = true;
	$d = run();
	check('a column the template gains is shown in the report defaults, in its section — the widget shows it, as it always did', col($d, 'plan[backup.bytes]') !== null
		&& $d['columns'][col($d, 'plan[backup.bytes]')]['group'] === 'Backup', array_column($d['columns'], 'builtin'));
	check('its units are the template\'s, written without the "!" and exported in them', cell($d, $K, 'plan[backup.bytes]')['text'] === '3 GB'
		&& in_array('Backup size (GB)', $d['export']['headers'], true), [cell($d, $K, 'plan[backup.bytes]')['text'], $d['export']['headers']]);
	\FakeApi::$extra_column = false;

	require_once __DIR__.'/../actions/ColumnsSave.php';
	$save = function (array $input): array {
		$a = new \Modules\EpVolume\Actions\ColumnsSave($input + ['report' => 'volume']);
		(new \ReflectionMethod($a, 'doAction'))->invoke($a);
		return json_decode($a->response->data['main_block'], true);
	};
	$meta = $d['meta']['columns'];
	$r = $save(['settings' => json_encode(['labels' => [], 'hidden' => [$meta[0]['id']], 'order' => array_column($meta, 'id'), 'split' => []])]);
	$saved = json_decode(file_get_contents($file()), true);
	check('the dialog saves the report defaults in the version-2 shape, a gained column still arriving shown', $r === ['ok' => true] && $saved['version'] === 2 && $saved['new_hidden'] === 0, $r);
	\FakeApi::$extra_column = true;
	$d = run();
	check('…hidden by the column\'s own id; the column gained since is shown', col($d, 'client.name') === null && col($d, 'plan[backup.bytes]') !== null);
	\FakeApi::$extra_column = false;
	check('Reset: back to the template\'s own columns', $save(['reset' => '1']) === ['ok' => true] && !is_file($file()) && count(run()['columns']) === 4);

	/* ---------------- a widget saved before 2.1.1 ---------------- */
	$catalogue = Catalogue::columns();
	$model = ColumnSettings::loadModel('volume');
	$old = WidgetForm::upgrade(['groupids' => ['1'], 'decimals' => '1'], $catalogue, $model);
	check('an old widget follows the report defaults, with nothing of its own, and keeps its decimals', !isset($old['columns']) && $old['decimals'] === '1');
	$d = run(['decimals' => '1']);
	check('…the same columns in the same order, coloured at the template\'s macros', array_column($d['columns'], 'builtin') === ['client.name', $DAYS, $USED, $NOTE]
		&& $lv(cell($d, $K, $USED)) === 'warn');
	check('…the table as it was: comfortable, the section header, the cluster fixed on the left, no sticky header, no totals', $d['view']['density'] === TableOptions::DENSITY_COMFORTABLE
		&& $d['view']['group_headers'] && $d['view']['freeze_first'] && !$d['view']['sticky_header'] && !$d['view']['totals'] && !$d['view']['striped']);
	check('…the toolbar always shown, with the Type filter, Columns and the exports', $d['view']['toolbar'] === ['type', 'columns', 'export'] && $d['view']['toolbar_pos'] === TableOptions::TOOLBAR_ALWAYS);
	check('…sorted by cluster name', $d['view']['sort_by'] === 'client' && !$d['view']['sort_desc'] && $d['view']['top_n'] === null);
	check('…its colours the palette\'s', strpos($d['palette'], '--ep-ok:') === 0);
	$own = WidgetForm::upgrade(['columns_from' => '1', 'columns' => array_slice(ColumnSettings::resolve(null, $catalogue), 0, 2)], $catalogue, $model);
	check('a widget\'s own list is offered the template\'s other columns, unticked — a column taken out must not come back shown', count($own['columns']) === 4
		&& count(array_filter($own['columns'], fn($c) => $c['hidden'])) === 2);

	/* ---------------- the widget's own columns: any other item, windows, calculations ---------------- */
	$col = fn(array $c) => $c + ['uid' => substr(md5(json_encode($c)), 0, 8), 'source' => Columns::SOURCE_BUILTIN];
	$epoch = \FakeApi::itemid(301, $USED);
	$now = time();
	\FakeApi::$trends = [$epoch => [$now - 3 * 3600 => [80, 70, 90], $now - 2 * 3600 => [70, 60, 75], $now - 3600 => [96, 90, 99]]];
	$mine = [
		$col(['name' => 'Cluster', 'builtin' => 'client.name']),
		$col(['group' => 'Live', 'name' => 'Used', 'builtin' => $USED, 'show' => Columns::SHOW_BAR, 'use_settings' => 0]),
		$col(['group' => 'Live', 'name' => '7d avg', 'builtin' => $USED, 'value' => Columns::VALUE_AVG]),
		$col(['group' => 'Live', 'name' => '7d max', 'builtin' => $USED, 'value' => Columns::VALUE_MAX]),
		$col(['group' => 'Docs', 'name' => 'Docs/s', 'source' => Columns::SOURCE_ITEM, 'key' => 'es.docs.rate', 'short' => 1, 'decimals' => '1']),
		$col(['group' => 'Docs', 'name' => 'Docs per day', 'source' => Columns::SOURCE_CALC, 'calc_a' => 'key:es.docs.rate', 'calc_op' => Columns::OP_MINUS, 'calc_b' => $DAYS]),
		$col(['group' => 'Docs', 'name' => 'Updated', 'source' => Columns::SOURCE_ITEM, 'key' => 'elasticpro.plan.*', 'pattern' => 1])
	];
	$d = run(['columns_from' => '1', 'columns' => $mine, 'trend_days' => '7']);
	check('own columns: no error, nothing dropped', $d['error'] === null && $d['dropped'] === [], [$d['error'], $d['dropped']]);
	$byName = function (array $d, string $client, string $name) {
		foreach ($d['rows'] as $r) { if ($r['client'] === $client) { foreach ($d['columns'] as $i => $c) { if ($c['name'] === $name) return $r['cells'][$i]; } } }
		return null;
	};
	check('any other item of the cluster host, short numbers', $byName($d, $K, 'Docs/s')['value'] === 1500.0 && $byName($d, $K, 'Docs/s')['text'] === '1.5k', $byName($d, $K, 'Docs/s'));
	check('…none on a cluster without it: unknown, with the reason, not 0', $byName($d, $A, 'Docs/s')['value'] === null && $byName($d, $A, 'Docs/s')['text'] === 'not collected');
	check('a calculation of two items of the host', $byName($d, $K, 'Docs per day')['value'] === 1491.0, $byName($d, $K, 'Docs per day'));
	check('a pattern reads every item that matches, the worst', $byName($d, $K, 'Updated')['value'] === 1700000000.0);
	check('the average over the window, from trends', $byName($d, $K, '7d avg')['value'] === 82.0, $byName($d, $K, '7d avg'));
	check('the maximum over the window', $byName($d, $K, '7d max')['value'] === 99.0);
	check('batched: one trend.get, one item.get for every key on every cluster', count(\FakeApi::$asked_trend) === 1 && count(array_filter(\FakeApi::$asked, fn($o) => !isset($o['search']))) === 1,
		[count(\FakeApi::$asked_trend), count(\FakeApi::$asked)]);

	/* ---------------- validation, table options ---------------- */
	[, $errs] = Columns::clean(['name' => 'Ingest', 'source' => 2, 'key' => 'bad key'], $catalogue, []);
	check('an item key that is not one is refused, naming the column', $errs && strpos(Columns::named($errs, 'Ingest')[0], 'Column "Ingest":') === 0, $errs);
	[$c] = Columns::clean(['name' => 'x', 'source' => 2, 'key' => 'es.docs.rate', 'roles' => ['es_data_hot']], $catalogue, []);
	check('a cluster has no roles: none are kept', $c['roles'] === []);
	$plain = ['columns_from' => '1', 'columns' => [$mine[0], $col(['group' => 'Live', 'name' => 'Days', 'builtin' => $DAYS])]];
	$days = $plain['columns'][1]['uid'];
	$d = run($plain + ['sort_by' => $days, 'sort_dir' => TableOptions::SORT_DESC]);
	check('sorted by a column, highest first (an unmeasured cluster last)', array_column($d['rows'], 'client') === [$A, $K] || array_column($d['rows'], 'client') === [$K, $A], array_column($d['rows'], 'client'));
	$d = run($plain + ['sort_by' => $days, 'sort_dir' => TableOptions::SORT_ASC, 'top_n' => '1']);
	check('…lowest first, top 1: karthi\'s 9 days before acme\'s 14', array_column($d['rows'], 'client') === [$K]);
	$d = run($plain + ['totals' => '1']);
	check('totals: the sum of the known figures (9 + 14 days), said to be over all', $d['totals'] === ['Total (all clients)', '23 days'], $d['totals']);
	$d = run($plain + ['totals' => '1', 'totals_fn' => TableOptions::TOTALS_AVG]);
	check('totals: average when asked', $d['totals'] === ['Average (all clients)', '12 days'], $d['totals']);
	check('totals: none unless asked', run($plain)['totals'] === null);
	$d = run(['density' => '1', 'row_height' => '30', 'sticky_header' => '1', 'striped' => '1', 'toolbar_items' => ['search'], 'toolbar_pos' => '1', 'group_headers' => '0']);
	check('the View settings reach the widget', $d['view']['density'] === 1 && $d['view']['row_height'] === 30 && $d['view']['sticky_header'] && !$d['view']['group_headers']
		&& $d['view']['striped'] && $d['view']['toolbar'] === ['search'] && $d['view']['toolbar_pos'] === TableOptions::TOOLBAR_HOVER, $d['view']);
	$d = run(['attention' => ['warn']]);
	$by = array_column($d['rows'], null, 'client');
	check('Needs attention (over Warn): karthi is over the plan\'s warning, acme is not', $by[$K]['attention'] && !$by[$A]['attention'], [$by[$K]['flags'], $by[$A]['flags']]);

	/* ---------------- the column editor: a row is one host ---------------- */
	require_once __DIR__.'/../actions/ColumnEdit.php';
	$edit = function (array $input): array {
		$a = new \Modules\EpVolume\Actions\ColumnEdit($input);
		(new \ReflectionMethod($a, 'doAction'))->invoke($a);
		return json_decode($a->response->data['main_block'], true);
	};
	Catalogue::forget();
	check('Check counts the cluster hosts that have the key — not the client servers', $edit(['coverage' => 1, 'key' => 'plan[live.days]', 'pattern' => '0', 'roles' => []]) === ['found' => 2, 'total' => 2]);
	$r = $edit(['update' => 1, 'scope' => 'client', 'name' => 'Docs/s', 'source' => '2', 'key' => 'es.docs.rate', 'roles' => ['es_data_hot'], 'combine' => '3']);
	check('Update returns the column by the one rule, with no roles (a cluster has none)', ($r['key'] ?? '') === 'es.docs.rate' && $r['roles'] === [] && !isset($r['error']), $r);
	$r = $edit(['update' => 1, 'scope' => 'client', 'name' => 'Bad', 'source' => '2', 'key' => 'bad key']);
	check('…and refuses a bad key, naming the column', strpos($r['error']['messages'][0] ?? '', 'Column "Bad": "bad key" is not an item key') === 0, $r);

	foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
		if (is_file($dir.'/'.$f)) { unlink($dir.'/'.$f); }
	}
	rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
