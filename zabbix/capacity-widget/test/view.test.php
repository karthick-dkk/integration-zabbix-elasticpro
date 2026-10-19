<?php
/**
 * The Client capacity controller against a fake Zabbix API: the columns columns.json lists as the
 * column model's built-in columns, where each threshold comes from, the forecast, the export, the
 * template mapping — and the column model in the widget: the Columns file written by every release
 * before 2.1.1 read onto it, a widget saved before 2.1.1 looking as it did, its own columns (item
 * keys, calculated, windows, colours), the table options, Needs attention and the validation of a
 * column.
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
	define('ZBX_WIDGET_FIELD_TYPE_INT32', 0); define('ZBX_WIDGET_FIELD_TYPE_STR', 1);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	// Guarded because PHP's gettext extension already defines _(), and a Zabbix frontend host
	// has gettext loaded — Zabbix uses it for translations. Without the guard every suite
	// died with "Cannot redeclare _()" on exactly the machine the modules run on.
	if (!function_exists('_')) { function _($s) { return $s; } }
	if (!function_exists('_s')) { function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace('%'.($i + 1).'$s', (string) $v, $s); } return $s; } }
	if (!function_exists('_n')) { function _n($a, $b, $n) { return str_replace('%1$s', (string) $n, $n == 1 ? $a : $b); } }
	function getSubGroups($ids) { return $ids; }
	// Records the decimals it was asked for: this stand-in does not pad, so the number of places
	// a figure was drawn to cannot be read back out of its text.
	$GLOBALS['ep_decimals'] = [];
	function convertUnits(array $o) {
		$GLOBALS['ep_decimals'][$o['units']] = $o['decimals'] ?? null;
		return round($o['value'], $o['decimals'] ?? 2).($o['units'] !== '' ? ' '.$o['units'] : '');
	}

	class CControllerDashboardWidgetView {
		public $fields_values = ['groupids' => []];
		public $widget;
		public $response;
		public function __construct() { $this->widget = new class { public function getDefaultName() { return 'Client capacity'; } }; }
		public function getInput($k, $d = null) { return $d; }
		public function getDebugMode() { return 0; }
		public function getUserType() { return USER_TYPE_SUPER_ADMIN; }
		public function setResponse($r) { $this->response = $r; }
	}
	class CControllerResponseData { public $data; public function __construct($d) { $this->data = $d; } }
	/** Just enough of Zabbix's controller for the Columns action. */
	class CController {
		public array $input = [];
		public $response;
		public function __construct(array $input = []) { $this->input = $input; }
		protected function validateInput(array $rules): bool { return true; }
		protected function getInput($k, $d = null) { return $this->input[$k] ?? $d; }
		protected function hasInput($k): bool { return array_key_exists($k, $this->input); }
		protected function getAction(): string { return 'widget.ep_capacity.columns'; }
		protected function getUserType(): int { return USER_TYPE_SUPER_ADMIN; }
		protected function setResponse($r): void { $this->response = $r; }
	}
	class CCsrfTokenHelper { public static function get($a) { return 'token:'.$a; } }

	/** Just enough of Zabbix's HTML classes for Bars to build its markup. */
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
	 * The fake Zabbix: three client master hosts. karthi (DI) is measured — ES storage 95 % full,
	 * filling in 10 days, a purchase of 0 nobody entered, an allocation under what was purchased,
	 * and one item unsupported — and has one machine. acme has a name and nothing else; gone is
	 * decommissioned.
	 */
	class FakeApi {
		public static $template = true;
		/** What the controller asked Zabbix to find the client master template by. */
		public static $asked_for = null;
		/** Extra macros on host 101, for the cases about where a threshold comes from. */
		public static $extra_macros = [];
		public static $extra_items = [];
		/** What item.get, trend.get and history.get were asked, for the cases about batching. */
		public static $asked = [];
		public static $asked_trend = [];
		public static $asked_history = [];
		public static $asked_hosts = 0;
		/** itemid => [clock => [avg, min, max]]: the trends the window columns read. */
		public static $trends = [];
		/** "host|key" => a last value in place of the one below (the export's places). */
		public static $override = [];
		public static function items(): array {
			$GB = 1073741824;
			$all = [
				[101, 'ep.es.storage.purchased', 10 * $GB, 'B', 0],
				[101, 'ep.es.storage.allocated', 4 * $GB, 'B', 0],
				[101, 'ep.es.storage.used', 2 * $GB, 'B', 0],
				[101, 'ep.es.storage.usage', 95, '%', 0],
				[101, 'ep.es.storage.full_in', 10 * 86400, '', 0],
				[101, 'ep.devices.purchased', 0, '', 0],
				[101, 'ep.devices.seen', 40, '', 0],
				[101, 'ep.devices.usage', 80, '%', 0],
				// Unsupported: unknown, which is not zero.
				[101, 'ep.es.cpu.requested', 16, '', 1],
				[102, 'ep.es.storage.used', 7 * $GB, 'B', 0],
				[201, 'es.ingest.rate', 18400, '/s', 0],
				[201, 'system.cpu.util', 85, '%', 0]
			];
			$out = [];
			foreach (array_merge($all, self::$extra_items) as $i => [$h, $k, $v, $u, $state]) {
				$v = self::$override[$h.'|'.$k] ?? $v;
				$out[] = ['itemid' => (string) ($h * 1000 + $i), 'hostid' => $h, 'key_' => $k, 'lastvalue' => (string) $v, 'lastclock' => time() - 60, 'units' => $u,
					'state' => $state, 'value_type' => 0, 'name' => $k];
			}
			return $out;
		}
		public static function itemid(int $host, string $key): string {
			foreach (self::items() as $it) {
				if ($it['hostid'] === $host && $it['key_'] === $key) { return $it['itemid']; }
			}
			return '';
		}
		private $what;
		public function __construct($what) { $this->what = $what; }
		public function get(array $o) {
			switch ($this->what) {
				case 'Template':
					self::$asked_for = $o['filter']['host'];
					return self::$template ? [['templateid' => 9]] : [];
				case 'Host':
					self::$asked_hosts++;
					if (isset($o['templateids'])) {
						return [
							101 => ['hostid' => 101, 'host' => 'karthi-Master', 'name' => 'karthi-Master', 'maintenance_status' => 0],
							102 => ['hostid' => 102, 'host' => 'acme-Master', 'name' => 'acme-Master', 'maintenance_status' => 0],
							103 => ['hostid' => 103, 'host' => 'gone-Master', 'name' => 'gone-Master', 'maintenance_status' => 0]
						];
					}
					$g = fn(...$n) => array_map(fn($x) => ['name' => $x], $n);
					return [201 => ['hostid' => 201, 'name' => 'karthi-ES-Data-Hot-1', 'hostgroups' => $g('karthi', 'ESNodes', 'ES Data Hot'),
						'interfaces' => [['ip' => '10.0.0.1', 'type' => 1, 'main' => 1, 'available' => 1]]]];
				case 'HostGroup':
					return array_values(array_filter([['groupid' => 1, 'name' => 'karthi']], fn($g) => in_array($g['name'], $o['filter']['name'], true)));
				case 'UserMacro':
					$base = [
						['hostid' => 101, 'macro' => '{$GRP.CLIENT}', 'value' => 'karthi'],
						['hostid' => 101, 'macro' => '{$EP.CLIENT.TYPE}', 'value' => 'DI'],
						['hostid' => 101, 'macro' => '{$ES.URL}', 'value' => 'https://es.karthi:9200'],
						['hostid' => 102, 'macro' => '{$GRP.CLIENT}', 'value' => 'acme'],
						['hostid' => 102, 'macro' => '{$EP.CLIENT.STATUS}', 'value' => 'disabled'],
						// Decommissioned clients leave the report altogether.
						['hostid' => 103, 'macro' => '{$GRP.CLIENT}', 'value' => 'gone'],
						['hostid' => 103, 'macro' => '{$EP.CLIENT.STATUS}', 'value' => 'decommissioned']
					];
					return array_merge($base, array_map(fn($m) => ['hostid' => 101, 'macro' => $m[0], 'value' => $m[1]], self::$extra_macros));
				case 'Item':
					$out = [];
					foreach (self::items() as $it) {
						if (!in_array($it['hostid'], $o['hostids'])) { continue; }
						if (isset($o['filter']['key_']) && in_array($it['key_'], (array) $o['filter']['key_'], true)) { $out[] = $it; }
						elseif (isset($o['search']['key_'])) {
							foreach ((array) $o['search']['key_'] as $p) {
								if (strpos($it['key_'], rtrim(explode('*', $p)[0])) === 0) { $out[] = $it; break; }
							}
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
					self::$asked_history[] = $o;
					return [];
			}
		}
	}
	class API {
		public static function __callStatic($name, $args) { return new FakeApi($name); }
	}
}

namespace Modules\EpCapacity\Test {
	// Bars owns the Display styles the view action reads; a shared file, copied into this
	// widget's lib by sync-assets.mjs, and loaded on its own because the action does not.
	require __DIR__.'/../lib/Bars.php';
	require __DIR__.'/../actions/WidgetView.php';
	use Modules\EpCapacity\Actions\WidgetView;
	use Modules\EpCapacity\Includes\{Catalogue, WidgetForm};
	use Modules\EpCapacity\Lib\{Bars, ColumnCell, ColumnMath, ColumnSettings, Columns, Forecast, Roles, Settings, TableOptions};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-cap-test-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);
	$file = fn() => $dir.'/columns-capacity.json';

	function run(array $fields = []): array {
		Settings::forget();
		$v = new WidgetView();
		$v->fields_values = $fields + ['groupids' => []];
		(new \ReflectionMethod($v, 'doAction'))->invoke($v);
		return $v->response->data;
	}
	/** The position of a column, by its built-in id or its uid. */
	function col(array $d, string $id): ?int {
		foreach ($d['columns'] as $i => $c) { if ($c['builtin'] === $id || $c['uid'] === $id) { return $i; } }
		return null;
	}
	/** One client's cell for a column. */
	function cell(array $d, string $client, string $id): array {
		foreach ($d['rows'] as $row) {
			if ($row['client'] === $client) { return $row['cells'][col($d, $id)]; }
		}
		return [];
	}
	function exported(array $d, string $client, string $id) {
		$at = array_search($client, array_column($d['rows'], 'client'), true);
		return $d['export']['rows'][$at][col($d, $id)];
	}
	$cls = fn(array $c) => trim(str_replace('ep-barcell', '', $c['class']));

	/* ---------------- it draws at all ---------------- */
	$d = run();
	check('no error', $d['error'] === null, $d['error']);
	check('clients are sorted by name', array_column($d['rows'], 'client') === ['acme', 'karthi']);
	check('a decommissioned client leaves the report', !in_array('gone', array_column($d['rows'], 'client'), true));
	check('the type comes from the master host', array_column($d['rows'], 'type') === ['On-Prem', 'DI']);
	check('a macro column shows the macro', cell($d, 'karthi', 'client.url')['text'] === 'https://es.karthi:9200');
	check('a disabled client says so beside its name, the export has the name alone', cell($d, 'acme', 'client.name')['text'] === 'acme (disabled)'
		&& exported($d, 'acme', 'client.name') === 'acme' && cell($d, 'karthi', 'client.name')['text'] === 'karthi');
	check('unknown is "—", never 0', cell($d, 'acme', 'ep.es.storage.allocated')['text'] === '—' && exported($d, 'acme', 'ep.es.storage.allocated') === null);
	check('an unsupported item is unknown too', cell($d, 'karthi', 'ep.es.cpu.requested')['text'] === '—');
	check('a purchase of 0 is one nobody entered', cell($d, 'karthi', 'ep.devices.purchased')['text'] === 'not set' && exported($d, 'karthi', 'ep.devices.purchased') === null);
	check('allocated under purchased is marked, with the purchase in the tooltip', strpos(cell($d, 'karthi', 'ep.es.storage.allocated')['class'], 'ep-short') !== false
		&& strpos(cell($d, 'karthi', 'ep.es.storage.allocated')['hint'], 'Below purchased') !== false, cell($d, 'karthi', 'ep.es.storage.allocated'));
	check('the bar is the figure the colour came from', cell($d, 'karthi', 'ep.es.storage.usage')['bar']['share'] === 95.0);
	check('and there is no bar for something unreported', cell($d, 'acme', 'ep.es.storage.usage')['bar'] === null);
	check('Seen shows its share beside it, as it always has', cell($d, 'karthi', 'ep.devices.seen')['text'] === '40' && cell($d, 'karthi', 'ep.devices.seen')['sub'] === '(80 %)');

	/* ---------------- columns.json is the catalogue ---------------- */
	$spec = json_decode(file_get_contents(__DIR__.'/../columns.json'), true);
	check('columns.json is the column catalogue and nothing else', array_keys($spec) === ['columns'], array_keys($spec));
	$catalogue = Catalogue::columns([], []);
	check('every column of it is a built-in column, under the id the report has always used (its key)', count($spec['columns']) === 31 && count($catalogue) === 31
		&& array_keys($catalogue) === array_map([Catalogue::class, 'idOf'], $spec['columns']), array_keys($catalogue));
	check('…all of them the report\'s own', count(array_filter($catalogue, fn($e) => !empty($e['auto']))) === 31);
	check('a version-1 id for the client is client.name and client.url', isset($catalogue['client.name'], $catalogue['client.url']));
	check('no server rows', Catalogue::forScope($catalogue, 'row') === [] && count(Catalogue::forScope($catalogue, 'client')) === 31);

	/* ---------------- where a threshold comes from ---------------- */
	// karthi's ES storage is 95 % used, which is red at the shipped thresholds.
	Settings::reset();
	check('95 % is red at the shipped thresholds', $cls(cell(run(), 'karthi', 'ep.es.storage.usage')) === 'ep-high');
	Settings::save(['usage' => ['warn' => 96, 'high' => 97]]);
	check('raising the site thresholds takes the colour off it', $cls(cell(run(), 'karthi', 'ep.es.storage.usage')) === '');
	check('this widget\'s own thresholds beat the site\'s', $cls(cell(run(['usage_warn' => 90, 'usage_high' => 94]), 'karthi', 'ep.es.storage.usage')) === 'ep-high');
	\FakeApi::$extra_macros = [['{$EP.USAGE.WARN}', '10'], ['{$EP.USAGE.HIGH}', '99']];
	check('and the client\'s own macros beat both', $cls(cell(run(['usage_warn' => 90, 'usage_high' => 94]), 'karthi', 'ep.es.storage.usage')) === 'ep-warn');
	\FakeApi::$extra_macros = [];
	Settings::reset();

	/* ---------------- display, decimals, size unit ---------------- */
	$bar = fn(array $d) => cell($d, 'karthi', 'ep.es.storage.usage')['bar']['style'] ?? 'none';
	check('a widget nobody has configured follows the site\'s bar style', $bar(run()) === Bars::DEFAULT_STYLE);
	Settings::save(['display' => ['bars' => 2]]);
	check('and moves when the site does', $bar(run()) === 'segments');
	check('but not when the widget was given one', $bar(run(['bars' => 1])) === 'cell');
	check('the inherit value is never drawn as a style', $bar(run(['bars' => Bars::INHERIT])) === 'segments');
	check('numbers only draws no bar, and still colours the figure', $bar(run(['bars' => 3])) === 'none' && $cls(cell(run(['bars' => 3]), 'karthi', 'ep.es.storage.usage')) === 'ep-high');
	Settings::reset();

	$GLOBALS['ep_decimals'] = [];
	run();
	check('figures are drawn to the places the site shipped',
		($GLOBALS['ep_decimals']['%'] ?? null) === 1 && ($GLOBALS['ep_decimals']['B'] ?? null) === 2 && ($GLOBALS['ep_decimals'][''] ?? null) === 0, $GLOBALS['ep_decimals']);
	Settings::save(['display' => ['decimals_percent' => 3, 'decimals_bytes' => 0]]);
	$GLOBALS['ep_decimals'] = [];
	run();
	check('and follow the site when it changes its mind', ($GLOBALS['ep_decimals']['%'] ?? null) === 3 && ($GLOBALS['ep_decimals']['B'] ?? null) === 0, $GLOBALS['ep_decimals']);
	Settings::reset();

	$d = run();
	$i = col($d, 'ep.es.storage.used');
	check('sizes export divided by GB', exported($d, 'karthi', 'ep.es.storage.used') === 2.0);
	check('and the header says which unit that was', strpos($d['export']['headers'][$i], '(GB)') !== false, $d['export']['headers'][$i]);
	Settings::save(['export' => ['size_unit' => 'TB']]);
	$d = run();
	check('choosing TB changes the figure', exported($d, 'karthi', 'ep.es.storage.used') === 0.0, exported($d, 'karthi', 'ep.es.storage.used'));
	check('and the header with it, so the two never disagree', strpos($d['export']['headers'][$i], '(TB)') !== false, $d['export']['headers'][$i]);
	Settings::reset();

	/* ---------------- when storage fills ---------------- */
	$d = run();
	check('ten days to full is a warning at the shipped windows', $cls(cell($d, 'karthi', 'ep.es.storage.full_in')) === 'ep-warn', cell($d, 'karthi', 'ep.es.storage.full_in'));
	check('and the export gives the days, not the words', exported($d, 'karthi', 'ep.es.storage.full_in') === 10.0);
	check('the days column says so in its header', strpos($d['export']['headers'][col($d, 'ep.es.storage.full_in')], '(days)') !== false);

	// The export's places, as 2.1.0 wrote them: a size in the export unit to two, a percentage to one,
	// any other number to two — never the places the table is drawn with.
	$GB = 1073741824;
	\FakeApi::$override = ['101|ep.es.storage.used' => 2.3456 * $GB, '101|ep.es.storage.usage' => 95.456, '101|ep.devices.seen' => 40.456];
	$places = fn(array $d) => [exported($d, 'karthi', 'ep.es.storage.used'), exported($d, 'karthi', 'ep.es.storage.usage'), exported($d, 'karthi', 'ep.devices.seen')];
	$d = run();
	check('export places: 2.3456 GB is 2.35, 95.456 % is 95.5, 40.456 is 40.46 — as before 2.1.1', $places($d) === [2.35, 95.5, 40.46], $places($d));
	Settings::save(['display' => ['decimals_bytes' => 0, 'decimals_percent' => 3, 'decimals_plain' => 0]]);
	check('…and the site\'s Decimals do not move them', $places(run()) === [2.35, 95.5, 40.46], $places(run()));
	Settings::reset();
	\FakeApi::$override = [];
	Settings::save(['forecast' => ['bad_days' => 14, 'warn_days' => 30]]);
	check('a site that calls two weeks urgent gets red', $cls(cell(run(), 'karthi', 'ep.es.storage.full_in')) === 'ep-high');
	Settings::save(['forecast' => ['warn_days' => 5, 'bad_days' => 2]]);
	check('and one that calls ten days fine gets no colour', $cls(cell(run(), 'karthi', 'ep.es.storage.full_in')) === '');
	Settings::save(['forecast' => ['flat_days' => 5]]);
	check('past the "not growing" mark it reads as not growing', cell(run(), 'karthi', 'ep.es.storage.full_in')['text'] === 'not growing');
	Settings::reset();

	/* ---------------- client types ---------------- */
	$d = run();
	check('the Type filter is offered the site\'s own list, CI included', $d['meta']['types'] === ['CI', 'DI', 'On-Prem'], $d['meta']['types']);
	Settings::save(['types' => ['all' => ['CI', 'DI', 'Managed'], 'default' => 'Managed']]);
	$d = run();
	check('a type added to the site reaches the filter', $d['meta']['types'] === ['CI', 'DI', 'Managed']);
	check('and its default reaches the clients that have none', $d['rows'][array_search('acme', array_column($d['rows'], 'client'), true)]['type'] === 'Managed');
	Settings::reset();

	/* ---------------- the Columns file every release before 2.1.1 wrote ---------------- */
	$plain = array_column(run()['columns'], 'builtin');
	$order = $plain;
	[$order[0], $order[1]] = [$order[1], $order[0]];
	ColumnSettings::save('capacity', ['labels' => ['ep.es.storage.used' => 'Used now'], 'hidden' => ['ep.devices.seen'], 'order' => $order, 'split' => []], null);
	$v1 = file_get_contents($file());
	$d = run();
	check('a renamed column is renamed, in the table and the export', $d['columns'][col($d, 'ep.es.storage.used')]['name'] === 'Used now'
		&& $d['export']['headers'][col($d, 'ep.es.storage.used')] === 'Used now (GB)');
	check('a hidden column is gone from the table and the export', col($d, 'ep.devices.seen') === null && count($d['export']['headers']) === 30);
	check('moved: the ES URL first, then the cluster name', $d['columns'][0]['builtin'] === 'client.url' && $d['columns'][1]['builtin'] === 'client.name', array_slice(array_column($d['columns'], 'builtin'), 0, 3));
	check('the dialog is told of it, and of the hidden one', in_array('ep.devices.seen', array_column($d['meta']['columns'], 'label') ? array_map(fn($c) => $c['id'] === ColumnSettings::builtinUid('ep.devices.seen') ? 'ep.devices.seen' : '', $d['meta']['columns']) : [], true)
		&& array_column($d['meta']['columns'], 'default', 'label')['Used now'] === 'ES Used Storage', $d['meta']['columns'][0]);
	check('the version-1 file is read, not rewritten', file_get_contents($file()) === $v1);
	// A column the file never mentioned arrives shown, beside the one it followed, as version 1 always treated it.
	check('a column added since is shown, as before', col($d, 'ep.es.storage.full_in') !== null);

	// The Columns dialog (ColumnsSave) writes the report defaults in the version-2 shape.
	require_once __DIR__.'/../actions/ColumnsSave.php';
	$save = function (array $input): array {
		$a = new \Modules\EpCapacity\Actions\ColumnsSave($input + ['report' => 'capacity']);
		(new \ReflectionMethod($a, 'doAction'))->invoke($a);
		return json_decode($a->response->data['main_block'], true);
	};
	$meta = $d['meta']['columns'];
	$r = $save(['settings' => json_encode(['labels' => [$meta[0]['id'] => 'Cluster URL'], 'hidden' => [$meta[2]['id']], 'order' => array_column($meta, 'id'), 'split' => []])]);
	$saved = json_decode(file_get_contents($file()), true);
	check('the dialog saves the report defaults in the version-2 shape', $r === ['ok' => true] && $saved['version'] === 2 && count($saved['columns']) === 31, $r);
	$d = run();
	check('…renamed and hidden by the columns\' own ids', $d['columns'][0]['name'] === 'Cluster URL' && count($d['columns']) === 30);
	check('…nothing else changes: the same figures', exported($d, 'karthi', 'ep.es.storage.used') === 2.0);
	check('Reset: back to the report\'s own columns', $save(['reset' => '1']) === ['ok' => true] && !is_file($file()) && count(run()['columns']) === 31);

	/* ---------------- a widget saved before 2.1.1 ---------------- */
	$model = ColumnSettings::loadModel('capacity');
	$old = WidgetForm::upgrade(['groupids' => ['1'], 'bars' => '2', 'usage_warn' => '0', 'usage_high' => '0'], $catalogue, $model);
	check('an old widget: a Warn and High of 0 are empty — ElasticPro Settings', $old['usage_warn'] === '' && $old['usage_high'] === '');
	check('…it follows the report defaults, with nothing of its own, and keeps its bar style', !isset($old['columns']) && $old['bars'] === '2');
	check('…a threshold someone typed is kept', WidgetForm::upgrade(['usage_warn' => '70'], $catalogue, $model)['usage_warn'] === '70');
	$d = run(['bars' => '2', 'usage_warn' => '', 'usage_high' => '']);
	check('…and draws the report as it always did: the same 31 columns in the same order, under the same names',
		array_column($d['columns'], 'builtin') === array_map([Catalogue::class, 'idOf'], $spec['columns'])
		&& array_column($d['columns'], 'name') === array_column($spec['columns'], 'label'));
	check('…a compact table, a sticky header and a frozen first column, no totals', $d['view']['density'] === TableOptions::DENSITY_COMPACT && $d['view']['sticky_header']
		&& $d['view']['freeze_first'] && !$d['view']['totals'] && !$d['view']['striped'] && $d['view']['row_height'] === null);
	check('…the toolbar on hover, with search, type, Columns and export', $d['view']['toolbar'] === ['search', 'type', 'columns', 'export']
		&& $d['view']['toolbar_pos'] === TableOptions::TOOLBAR_HOVER);
	check('…sorted by client name', $d['view']['sort_by'] === 'client' && !$d['view']['sort_desc'] && $d['view']['top_n'] === null);
	check('…its colours the palette\'s, not anything kept in a browser', strpos($d['palette'], '--ep-ok:') === 0);
	check('…the first column is the cluster name, bold and fixed on the left', $d['columns'][0]['builtin'] === 'client.name' && $d['columns'][0]['custom'] === false);

	// This widget's own columns start from the report defaults; new ones arrive unticked.
	$own = WidgetForm::upgrade(['columns_from' => '1'], $catalogue, $model);
	check('switched to This widget: the report defaults copied in', array_column($own['columns'], 'builtin') === array_column($d['columns'], 'builtin'));
	$short = array_slice($own['columns'], 0, 4);
	$own = WidgetForm::upgrade(['columns_from' => '1', 'columns' => $short], $catalogue, $model);
	check('a column the widget never had is offered, unticked', count($own['columns']) === 31 && !array_filter(array_slice($own['columns'], 4), fn($c) => !$c['hidden']));
	$own = WidgetForm::upgrade(['columns_from' => '1', 'columns' => $short, 'sortorder' => ['columns' => ['2', '0', '1', '3']]], $catalogue, $model);
	check('the order the rows were dragged into is the order kept', array_column(array_slice($own['columns'], 0, 4), 'uid') === array_column([$short[2], $short[0], $short[1], $short[3]], 'uid'));
	check('Reset to report defaults keeps nothing of the widget\'s own', !isset(WidgetForm::upgrade(['columns_from' => '0', 'columns' => $short], $catalogue, $model)['columns']));

	/* ---------------- the widget's own columns ---------------- */
	$col = fn(array $c) => $c + ['uid' => substr(md5(json_encode($c)), 0, 8), 'source' => Columns::SOURCE_BUILTIN];
	$mine = [
		$col(['name' => 'Client', 'builtin' => 'client.name', 'click' => Columns::CLICK_DASHBOARD]),
		$col(['group' => 'ES storage', 'name' => 'Used of purchased', 'source' => Columns::SOURCE_CALC, 'calc_a' => 'ep.es.storage.used', 'calc_op' => Columns::OP_OF,
			'calc_b' => 'ep.es.storage.purchased', 'show' => Columns::SHOW_BAR, 'bar_style' => Columns::BAR_SEGMENTS, 'segments' => 10, 'nodata' => Columns::NODATA_WHY]),
		$col(['group' => 'Ingest', 'name' => 'Docs/s', 'source' => Columns::SOURCE_ITEM, 'key' => 'es.ingest.rate', 'combine' => Columns::COMBINE_SUM, 'short' => 1,
			'use_settings' => 0, 'worse' => Columns::WORSE_LOWER, 'thresholds' => [['level' => 1, 'value' => '30000', 'color' => ''], ['level' => 2, 'value' => '10000', 'color' => '#AA0000']],
			'show' => Columns::SHOW_CELL]),
		$col(['group' => 'ES storage', 'name' => '7d avg', 'builtin' => 'ep.es.storage.usage', 'value' => Columns::VALUE_AVG, 'show' => Columns::SHOW_TEXT]),
		$col(['group' => 'ES storage', 'name' => '7d max', 'builtin' => 'ep.es.storage.usage', 'value' => Columns::VALUE_MAX]),
		$col(['group' => 'ES storage', 'name' => 'Trend', 'builtin' => 'ep.es.storage.usage', 'show' => Columns::SHOW_SPARKLINE]),
		$col(['group' => 'Gone', 'name' => 'Old', 'builtin' => 'ep.nope.cpu.usage'])
	];
	$usage = \FakeApi::itemid(101, 'ep.es.storage.usage');
	$now = time();
	\FakeApi::$trends = [$usage => [$now - 3 * 3600 => [80, 70, 90], $now - 2 * 3600 => [70, 60, 75], $now - 3600 => [96, 90, 99]]];
	\FakeApi::$asked = []; \FakeApi::$asked_trend = []; \FakeApi::$asked_history = []; \FakeApi::$asked_hosts = 0;
	$d = run(['columns_from' => '1', 'columns' => $mine, 'trend_days' => '7']);
	check('own columns: no error', $d['error'] === null, $d['error']);
	check('a column that no longer passes is left out and named', count($d['dropped']) === 1 && strpos($d['dropped'][0], 'Column "Gone · Old"') === 0, $d['dropped']);
	$byName = function (array $d, string $client, string $name) {
		foreach ($d['rows'] as $r) {
			if ($r['client'] === $client) { foreach ($d['columns'] as $i => $c) { if ($c['name'] === $name) return $r['cells'][$i]; } }
		}
		return null;
	};
	$c = $byName($d, 'acme', 'Used of purchased');
	check('x of y: the figure, the purchase, the share — judged on the share', $c['value'] === null || strpos($c['text'], ' of ') !== false, $c);
	$c = $byName($d, 'karthi', 'Used of purchased');
	check('…2 of 10 GB is 20 %, a segmented bar', strpos($c['text'], '20 %') !== false && $c['bar']['share'] === 20.0 && $c['bar']['style'] === 'segments', $c);
	$c = $byName($d, 'karthi', 'Docs/s');
	check('an item key on the client\'s servers, summed, short numbers', $c['value'] === 18400.0 && $c['text'] === '18.4k/s', $c);
	check('lower is worse: under 30k is Warn, coloured cell', $c['level'] === 'warn' && strpos($c['class'], 'ep-fill-warn') !== false, $c);
	check('a client with no servers: unknown with the reason, not 0', $byName($d, 'acme', 'Docs/s')['value'] === null && $byName($d, 'acme', 'Docs/s')['text'] === 'no servers with a role');
	$c = $byName($d, 'karthi', '7d avg');
	check('the average over the window, from trends, at Settings\' thresholds', $c['value'] === 82.0 && $c['level'] === 'warn', $c);
	check('the maximum over the window', $byName($d, 'karthi', '7d max')['value'] === 99.0);
	check('a sparkline of the window', $byName($d, 'karthi', 'Trend')['spark'] === [80.0, 70.0, 96.0]);
	check('no trend yet is said, not drawn as 0', $byName($d, 'acme', '7d avg')['value'] === null);
	check('the client name links to the client\'s dashboard, a Zabbix page', $byName($d, 'karthi', 'Client')['url'] === 'zabbix.php?action=host.dashboard.view&hostid=101');
	check('batched: one trend.get for the window, no history for a 7-day window', count(\FakeApi::$asked_trend) === 1 && \FakeApi::$asked_history === []);
	check('batched: one item.get for every key on every host — not one a client', count(\FakeApi::$asked) === 1, count(\FakeApi::$asked));
	$e = $d['export'];
	check('export: only the columns the widget shows, the share in %, unknown empty', count($e['headers']) === count($d['columns']) && $e['headers'][1] === 'ES storage Used of purchased (%)'
		&& $e['rows'][1][1] === 20.0 && $e['rows'][0][1] === null, [$e['headers'], $e['rows']]);
	// The report's own columns need no servers: they are only asked for when a column reads them.
	\FakeApi::$asked_hosts = 0;
	run(['columns_from' => '1', 'columns' => [$mine[0], $mine[3]]]);
	check('the servers are only asked for when a column reads them (the master hosts: one host.get)', \FakeApi::$asked_hosts === 1, \FakeApi::$asked_hosts);

	/* ---------------- validation: the rule the editor applies ---------------- */
	[$ok, $errs] = Columns::clean(['name' => 'Ingest', 'source' => 2, 'key' => 'bad key'], Catalogue::columns(), []);
	check('an item key that is not one is refused, naming the column', $errs && strpos(Columns::named($errs, 'Ingest')[0], 'Column "Ingest":') === 0, $errs);
	[$ok, $errs] = Columns::clean(['name' => 'x', 'source' => 1, 'builtin' => 'ep.nope'], Catalogue::columns(), []);
	check('a built-in column Client capacity does not have is refused', $errs !== [], $errs);
	[$ok, $errs] = Columns::clean(['name' => 'x', 'source' => 1, 'builtin' => 'ep.es.storage.usage', 'warn_color' => 'red'], Catalogue::columns(), []);
	check('a colour that is not #RRGGBB is refused', $errs !== [] && strpos(implode(' ', $errs), 'colour like') !== false, $errs);
	[$ok, $errs] = Columns::clean(['name' => 'x', 'source' => 1, 'builtin' => 'client.name', 'value' => 2], Catalogue::columns(), []);
	check('a text column shows its value now', $errs !== [], $errs);

	/* ---------------- the table options, sort, top N, totals ---------------- */
	$plainCols = ['columns_from' => '1', 'columns' => [$col(['name' => 'Client', 'builtin' => 'client.name']), $col(['group' => 'ES storage', 'name' => 'Used', 'builtin' => 'ep.es.storage.used'])]];
	$used = $plainCols['columns'][1]['uid'];
	$d = run($plainCols + ['sort_by' => $used, 'sort_dir' => TableOptions::SORT_DESC]);
	check('sorted by a column, highest first', array_column($d['rows'], 'client') === ['acme', 'karthi'], array_column($d['rows'], 'client'));
	$d = run($plainCols + ['sort_by' => $used, 'sort_dir' => TableOptions::SORT_ASC, 'top_n' => '1']);
	check('…lowest first, top 1', array_column($d['rows'], 'client') === ['karthi']);
	check('a sort by a column no longer there sorts by client', array_column(run($plainCols + ['sort_by' => 'gone'])['rows'], 'client') === ['acme', 'karthi']);
	$d = run($plainCols + ['totals' => '1']);
	check('totals: the sum of the known figures, said to be over all clients', $d['totals'] === ['Total (all clients)', (9 * 1073741824).' B'], $d['totals']);
	$d = run($plainCols + ['totals' => '1', 'totals_fn' => TableOptions::TOTALS_AVG, 'top_n' => '2']);
	check('totals: average when asked, over the top N', $d['totals'] === ['Average (top 2)', (4.5 * 1073741824).' B'], $d['totals']);
	check('totals: none unless asked', run($plainCols)['totals'] === null);
	$d = run(['density' => '0', 'row_height' => '30', 'group_headers' => '0', 'sticky_header' => '0', 'freeze_first' => '0', 'striped' => '1', 'toolbar_items' => ['search'], 'toolbar_pos' => '2']);
	check('the View settings reach the widget', $d['view']['density'] === 0 && $d['view']['row_height'] === 30 && !$d['view']['group_headers'] && !$d['view']['sticky_header']
		&& !$d['view']['freeze_first'] && $d['view']['striped'] && $d['view']['toolbar'] === ['search'] && $d['view']['toolbar_pos'] === TableOptions::TOOLBAR_HIDDEN, $d['view']);

	/* ---------------- Needs attention ---------------- */
	$d = run(['attention' => ['high'], 'attention_default' => '1']);
	$by = array_column($d['rows'], null, 'client');
	check('Needs attention (over High): karthi\'s storage is at 95 %, acme has nothing wrong', $by['karthi']['attention'] && !$by['acme']['attention'], [$by['karthi']['flags'], $by['acme']['flags']]);
	check('…it starts on when the widget says so', $d['meta']['view']['attention'] === true);
	check('…short: karthi\'s allocation is below what was purchased', $by['karthi']['flags']['short'] === 1 && run(['attention' => ['short']])['rows'][1]['attention']);
	check('…by what the widget says it means: nothing ticked, nothing needs it', !array_filter(run(['attention' => ['']])['rows'], fn($r) => $r['attention']));
	check('…storage full within 30 days (karthi: 10)', $by['karthi']['flags']['full_days'] === 10.0 && run(['attention' => ['disk30']])['rows'][1]['attention']
		&& !run(['attention' => ['disk30']])['rows'][0]['attention']);

	/* ---------------- the clients listed ---------------- */
	check('named clients only', array_column(run(['clients' => ['KARTHI']])['rows'], 'client') === ['karthi']);
	check('a name that is not there is said', strpos((string) run(['clients' => ['nobody']])['error'], 'No client named "nobody"') === 0);
	check('client types only', array_column(run(['client_types' => ['DI']])['rows'], 'client') === ['karthi']);

	/* ---------------- the template is whatever this Zabbix calls it ---------------- */
	$d = run();
	check('the master template is looked for by every name the mapping answers to', \FakeApi::$asked_for === Roles::templateNamesFor('master'), \FakeApi::$asked_for);
	check('which is the shipped name until the mapping says otherwise', \FakeApi::$asked_for === ['ElasticPro client master']);
	Roles::saveTemplateNames(['master' => 'ACME client master', 'master_also' => 'ElasticPro client master']);
	$d = run();
	check('a renamed master template is the one asked for, and the old name still recognised', \FakeApi::$asked_for === ['ACME client master', 'ElasticPro client master'], \FakeApi::$asked_for);
	check('and the report still draws', $d['error'] === null && count($d['rows']) === 2);
	\FakeApi::$template = false;
	$d = run();
	check('no template: the widget says so rather than drawing an empty table', $d['error'] !== null);
	check('and names the template this Zabbix is meant to have', strpos((string) $d['error'], 'ACME client master') !== false, $d['error']);
	Roles::saveTemplateNames([]);
	\FakeApi::$template = true;

	foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
		if (is_file($dir.'/'.$f)) { unlink($dir.'/'.$f); }
	}
	rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
