<?php
/**
 * The Client capacity controller against a fake Zabbix API. This widget's figures used to be
 * governed by three literals in columns.json and one in the controller; now they come from
 * ElasticPro -> Settings and from the Roles page's template mapping, so the order they are read
 * in — the client's own macros, then this widget's fields, then the site — is what is checked
 * here, along with the things a lost colour would otherwise hide.
 *   docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php test/view.test.php
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
 */
namespace {
	define('ITEM_STATE_NORMAL', 0); define('USER_TYPE_SUPER_ADMIN', 3);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	function _($s) { return $s; }
	function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace('%'.($i + 1).'$s', (string) $v, $s); } return $s; }
	function _n($a, $b, $n) { return str_replace('%1$s', (string) $n, $n == 1 ? $a : $b); }
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
	class CCsrfTokenHelper { public static function get($a) { return 'token:'.$a; } }

	/** Just enough of Zabbix's HTML classes for Bars to build its markup. */
	class CTag {
		public $tag; public $items; public $classes = []; public $attrs = [];
		public function __construct($tag, $paired = true, $items = null) { $this->tag = $tag; $this->items = $items; }
		public function addClass($c) { $this->classes[] = $c; return $this; }
		public function setAttribute($k, $v) { $this->attrs[$k] = $v; return $this; }
		public function addItem($i) { $this->items = $i; return $this; }
	}
	class CDiv extends CTag { public function __construct($items = null) { parent::__construct('div', true, $items); } }
	class CSpan extends CTag { public function __construct($items = null) { parent::__construct('span', true, $items); } }

	/**
	 * The fake Zabbix: two client master hosts. karthi (DI) is measured — ES storage 95 % full,
	 * filling in 10 days, a purchase of 0 nobody entered, an allocation under what was purchased,
	 * and one item unsupported. acme has a name and nothing else.
	 */
	class FakeApi {
		public static $template = true;
		/** What the controller asked Zabbix to find the client master template by. */
		public static $asked_for = null;
		/** Extra macros on host 101, for the cases about where a threshold comes from. */
		public static $extra_macros = [];
		private $what;
		public function __construct($what) { $this->what = $what; }
		public function get(array $o) {
			switch ($this->what) {
				case 'Template':
					self::$asked_for = $o['filter']['host'];
					return self::$template ? [['templateid' => 9]] : [];
				case 'Host':
					return [
						101 => ['hostid' => 101, 'host' => 'karthi-Master', 'name' => 'karthi-Master'],
						102 => ['hostid' => 102, 'host' => 'acme-Master', 'name' => 'acme-Master'],
						103 => ['hostid' => 103, 'host' => 'gone-Master', 'name' => 'gone-Master']
					];
				case 'UserMacro':
					$base = [
						['hostid' => 101, 'macro' => '{$GRP.CLIENT}', 'value' => 'karthi'],
						['hostid' => 101, 'macro' => '{$EP.CLIENT.TYPE}', 'value' => 'DI'],
						['hostid' => 101, 'macro' => '{$ES.URL}', 'value' => 'https://es.karthi:9200'],
						['hostid' => 102, 'macro' => '{$GRP.CLIENT}', 'value' => 'acme'],
						// Decommissioned clients leave the report altogether.
						['hostid' => 103, 'macro' => '{$GRP.CLIENT}', 'value' => 'gone'],
						['hostid' => 103, 'macro' => '{$EP.CLIENT.STATUS}', 'value' => 'decommissioned']
					];
					return array_merge($base, array_map(fn($m) => ['hostid' => 101, 'macro' => $m[0], 'value' => $m[1]], self::$extra_macros));
				case 'Item':
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
						[101, 'ep.es.cpu.requested', 16, '', 1]
					];
					$out = [];
					foreach ($all as [$h, $k, $v, $u, $state]) {
						if (in_array($h, $o['hostids']) && in_array($k, $o['filter']['key_'], true)) {
							$out[] = ['hostid' => $h, 'key_' => $k, 'lastvalue' => (string) $v, 'lastclock' => 1, 'units' => $u, 'state' => $state];
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

namespace Modules\EpCapacity\Test {
	// Bars owns the Display styles the view action reads; a shared file, copied into this
	// widget's lib by sync-assets.mjs, and loaded on its own because the action does not.
	require __DIR__.'/../lib/Bars.php';
	require __DIR__.'/../actions/WidgetView.php';
	use Modules\EpCapacity\Actions\WidgetView;
	use Modules\EpCapacity\Lib\{Bars, ColumnSettings, Forecast, Roles, Settings};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-cap-test-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);

	function run(array $fields = []): array {
		Settings::forget();
		$v = new WidgetView();
		$v->fields_values = $fields + ['groupids' => []];
		(new \ReflectionMethod($v, 'doAction'))->invoke($v);
		return $v->response->data;
	}
	function col(array $d, string $id): ?int {
		foreach ($d['columns'] as $i => $c) { if ($c['id'] === $id) { return $i; } }
		return null;
	}
	/** One client's cell for a column, by the column's id. */
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

	/* ---------------- it draws at all ---------------- */
	$d = run();
	check('no error', $d['error'] === null, $d['error']);
	check('clients are sorted by name', array_column($d['rows'], 'client') === ['acme', 'karthi']);
	check('a decommissioned client leaves the report', !in_array('gone', array_column($d['rows'], 'client'), true));
	check('the type comes from the master host', cell($d, 'karthi', 'client.name')['text'] === 'karthi'
		&& $d['rows'][array_search('karthi', array_column($d['rows'], 'client'), true)]['type'] === 'DI');
	check('a client with no type of its own is the site default',
		$d['rows'][array_search('acme', array_column($d['rows'], 'client'), true)]['type'] === 'On-Prem');
	check('a macro column shows the macro', cell($d, 'karthi', 'client.url')['text'] === 'https://es.karthi:9200');
	check('unknown is "—", never 0', cell($d, 'acme', 'ep.es.storage.used')['text'] === '—'
		&& exported($d, 'acme', 'ep.es.storage.used') === null);
	check('an unsupported item is unknown too', cell($d, 'karthi', 'ep.es.cpu.requested')['text'] === '—');
	check('a purchase of 0 is one nobody entered', cell($d, 'karthi', 'ep.devices.purchased')['text'] === 'not set'
		&& exported($d, 'karthi', 'ep.devices.purchased') === null);
	check('allocated under purchased is marked', strpos(cell($d, 'karthi', 'ep.es.storage.allocated')['class'], 'ep-short') !== false);
	check('the bar is the figure the colour came from', cell($d, 'karthi', 'ep.es.storage.usage')['bar'] === 95.0);
	check('and there is no bar for something unreported', cell($d, 'acme', 'ep.es.storage.usage')['bar'] === null);

	/* ---------------- columns.json no longer holds settings ---------------- */
	$spec = json_decode(file_get_contents(__DIR__.'/../columns.json'), true);
	check('columns.json is the column catalogue and nothing else', array_keys($spec) === ['columns'], array_keys($spec));
	check('it still lists every column', count($spec['columns']) === 31);

	/* ---------------- where a threshold comes from ---------------- */
	// karthi's ES storage is 95 % used, which is red at the shipped thresholds.
	Settings::reset();
	check('95 % is red at the shipped thresholds', cell(run(), 'karthi', 'ep.es.storage.usage')['class'] === 'ep-high');
	Settings::save(['usage' => ['warn' => 96, 'high' => 97]]);
	check('raising the site thresholds takes the colour off it', cell(run(), 'karthi', 'ep.es.storage.usage')['class'] === '');
	check('this widget\'s own thresholds beat the site\'s',
		cell(run(['usage_warn' => 90, 'usage_high' => 94]), 'karthi', 'ep.es.storage.usage')['class'] === 'ep-high');
	\FakeApi::$extra_macros = [['{$EP.USAGE.WARN}', '10'], ['{$EP.USAGE.HIGH}', '99']];
	check('and the client\'s own macros beat both',
		cell(run(['usage_warn' => 90, 'usage_high' => 94]), 'karthi', 'ep.es.storage.usage')['class'] === 'ep-warn');
	\FakeApi::$extra_macros = [];
	Settings::reset();

	/* ---------------- display, decimals, size unit ---------------- */
	check('a widget nobody has configured follows the site\'s bar style', run()['bars'] === Bars::DEFAULT_STYLE);
	Settings::save(['display' => ['bars' => 2]]);
	check('and moves when the site does', run()['bars'] === 'segments');
	check('but not when the widget was given one', run(['bars' => 1])['bars'] === 'cell');
	check('the inherit value is never drawn as a style', run(['bars' => Bars::INHERIT])['bars'] === 'segments');
	Settings::reset();

	$GLOBALS['ep_decimals'] = [];
	run();
	check('figures are drawn to the places the site shipped',
		($GLOBALS['ep_decimals']['%'] ?? null) === 1 && ($GLOBALS['ep_decimals']['B'] ?? null) === 2 && ($GLOBALS['ep_decimals'][''] ?? null) === 0,
		$GLOBALS['ep_decimals']);
	Settings::save(['display' => ['decimals_percent' => 3, 'decimals_bytes' => 0]]);
	$GLOBALS['ep_decimals'] = [];
	run();
	check('and follow the site when it changes its mind',
		($GLOBALS['ep_decimals']['%'] ?? null) === 3 && ($GLOBALS['ep_decimals']['B'] ?? null) === 0, $GLOBALS['ep_decimals']);
	Settings::reset();

	$d = run();
	$i = col($d, 'ep.es.storage.used');
	check('sizes export divided by GB', exported($d, 'karthi', 'ep.es.storage.used') === 2.0);
	check('and the header says which unit that was', strpos($d['export']['headers'][$i], '(GB)') !== false, $d['export']['headers'][$i]);
	Settings::save(['export' => ['size_unit' => 'TB']]);
	$d = run();
	check('choosing TB changes the figure', exported($d, 'karthi', 'ep.es.storage.used') === 0.0,
		exported($d, 'karthi', 'ep.es.storage.used'));
	check('and the header with it, so the two never disagree', strpos($d['export']['headers'][$i], '(TB)') !== false, $d['export']['headers'][$i]);
	Settings::reset();

	/* ---------------- when storage fills ---------------- */
	$d = run();
	check('ten days to full is a warning at the shipped windows', cell($d, 'karthi', 'ep.es.storage.full_in')['class'] === 'ep-warn');
	check('and the export gives the days, not the words', exported($d, 'karthi', 'ep.es.storage.full_in') === 10.0);
	check('the days column says so in its header', strpos($d['export']['headers'][col($d, 'ep.es.storage.full_in')], '(days)') !== false);
	Settings::save(['forecast' => ['bad_days' => 14, 'warn_days' => 30]]);
	check('a site that calls two weeks urgent gets red', cell(run(), 'karthi', 'ep.es.storage.full_in')['class'] === 'ep-high');
	Settings::save(['forecast' => ['warn_days' => 5, 'bad_days' => 2]]);
	check('and one that calls ten days fine gets no colour', cell(run(), 'karthi', 'ep.es.storage.full_in')['class'] === '');
	Settings::save(['forecast' => ['flat_days' => 5]]);
	check('past the "not growing" mark it reads as not growing',
		cell(run(), 'karthi', 'ep.es.storage.full_in')['text'] === 'not growing');
	check('the windows this widget uses are the ones Forecast knows',
		array_keys(Settings::forecast()) === array_keys(Forecast::WINDOWS));
	Settings::reset();

	/* ---------------- client types ---------------- */
	$d = run();
	check('the Type filter is offered the site\'s own list, CI included', $d['meta']['types'] === ['CI', 'DI', 'On-Prem'], $d['meta']['types']);
	Settings::save(['types' => ['all' => ['CI', 'DI', 'Managed'], 'default' => 'Managed']]);
	$d = run();
	check('a type added to the site reaches the filter', $d['meta']['types'] === ['CI', 'DI', 'Managed']);
	check('and its default reaches the clients that have none',
		$d['rows'][array_search('acme', array_column($d['rows'], 'client'), true)]['type'] === 'Managed');
	Settings::reset();

	/* ---------------- the Columns dialog still governs the columns ---------------- */
	ColumnSettings::save('capacity', ['labels' => ['ep.es.storage.used' => 'Used now'], 'hidden' => ['ep.devices.seen'],
		'order' => [], 'split' => []], null);
	$d = run();
	check('a renamed column is renamed', $d['columns'][col($d, 'ep.es.storage.used')]['label'] === 'Used now');
	check('a hidden column is gone from the table', col($d, 'ep.devices.seen') === null);
	check('and the dialog is still told about it', in_array('ep.devices.seen', array_column($d['meta']['columns'], 'id'), true));
	ColumnSettings::reset('capacity');

	/* ---------------- the template is whatever this Zabbix calls it ---------------- */
	$d = run();
	check('the master template is looked for by every name the mapping answers to',
		\FakeApi::$asked_for === Roles::templateNamesFor('master'), \FakeApi::$asked_for);
	check('which is the shipped name until the mapping says otherwise', \FakeApi::$asked_for === ['ElasticPro client master']);
	Roles::saveTemplateNames(['master' => 'ACME client master', 'master_also' => 'ElasticPro client master']);
	$d = run();
	check('a renamed master template is the one asked for, and the old name still recognised',
		\FakeApi::$asked_for === ['ACME client master', 'ElasticPro client master'], \FakeApi::$asked_for);
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
