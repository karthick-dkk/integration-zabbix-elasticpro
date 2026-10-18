<?php
/**
 * The Client resources controller against a fake Zabbix API: columns from the roles, the Columns
 * settings (rename, hide, split a family into roles) and their migration onto the column model,
 * colours, "not set", unknown as "—", the servers each client expands into, and the export; then
 * the column model in the widget — its own columns (item keys, calculated, windows, colours),
 * the Pie chart's donuts, Needs attention, sort and top N, totals — and how a widget saved before
 * 2.1.1 is read.
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
	define('ITEM_STATE_NORMAL', 0); define('USER_TYPE_SUPER_ADMIN', 3); define('INTERFACE_TYPE_AGENT', 1); define('INTERFACE_PRIMARY', 1);
	define('ZBX_WIDGET_FIELD_TYPE_INT32', 0); define('ZBX_WIDGET_FIELD_TYPE_STR', 1);
	if (!function_exists('_n')) { function _n($one, $many, $n, ...$a) { return str_replace('%1$s', (string) $n, $n == 1 ? $one : $many); } }
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	// Guarded because PHP's gettext extension already defines _(), and a Zabbix frontend host
	// has gettext loaded — Zabbix uses it for translations. Without the guard every suite
	// died with "Cannot redeclare _()" on exactly the machine the modules run on. gettext's
	// _() returns its argument when no domain is bound, which is what the stub did anyway.
	if (!function_exists('_')) { function _($s) { return $s; } }
	if (!function_exists('_s')) { function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace('%'.($i + 1).'$s', (string) $v, $s); } return $s; } }
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
		public function __construct() { $this->widget = new class { public function getDefaultName() { return 'Client resources'; } }; }
		public function getInput($k, $d = null) { return $d; }
		public function getDebugMode() { return 0; }
		public function getUserType() { return USER_TYPE_SUPER_ADMIN; }
		public function setResponse($r) { $this->response = $r; }
	}
	class CControllerResponseData { public $data; public function __construct($d) { $this->data = $d; } }
	/** Just enough of Zabbix's controller for the Columns action: its input, its action name, its response. */
	class CController {
		public array $input = [];
		public $response;
		public function __construct(array $input = []) { $this->input = $input; }
		protected function validateInput(array $rules): bool { return true; }
		protected function getInput($k, $d = null) { return $this->input[$k] ?? $d; }
		protected function hasInput($k): bool { return array_key_exists($k, $this->input); }
		protected function getAction(): string { return 'widget.ep_resources.columns'; }
		protected function getUserType(): int { return USER_TYPE_SUPER_ADMIN; }
		protected function setResponse($r): void { $this->response = $r; }
	}
	class CCsrfTokenHelper { public static function get($a) { return 'token:'.$a; } }
	/**
	 * Just enough of Zabbix's HTML classes for Bars to build its markup: a tag with classes,
	 * attributes and nested children. Only what Bars uses — this is not a second renderer.
	 */
	class CTag {
		protected $tag; protected $items = []; protected $classes = []; protected $attrs = [];
		public function __construct($tag = 'div', $ignored = true, $items = null) {
			$this->tag = $tag;
			if ($items !== null) { $this->items = is_array($items) ? $items : [$items]; }
		}
		public function addClass($c) { if ($c !== null && $c !== '') { $this->classes[] = $c; } return $this; }
		public function setAttribute($k, $v) { $this->attrs[$k] = $v; return $this; }
		public function toString() {
			$a = '';
			foreach ($this->classes as $c) { $a .= ''; }
			if ($this->classes) { $a .= ' class="'.implode(' ', $this->classes).'"'; }
			foreach ($this->attrs as $k => $v) { $a .= ' '.$k.'="'.$v.'"'; }
			$inner = '';
			foreach ($this->items as $i) { $inner .= is_object($i) ? $i->toString() : (string) $i; }
			return '<'.$this->tag.$a.'>'.$inner.'</'.$this->tag.'>';
		}
		public function __toString() { return $this->toString(); }
	}
	class CDiv extends CTag { public function __construct($items = null) { parent::__construct('div', true, $items); } }
	class CSpan extends CTag { public function __construct($items = null) { parent::__construct('span', true, $items); } }

	/** The fake Zabbix: two client master hosts, karthi (DI) with two machines and acme (On-Prem) with none measured. */
	class FakeApi {
		public static $template = true;
		/** What the controller asked Zabbix to find the client master template by. */
		public static $asked_for = null;
		/** Extra macros on host 101, for the cases about where a threshold comes from. */
		public static $extra_macros = [];
		/** What item.get, trend.get and history.get were asked, for the cases about batching. */
		public static $asked = [];
		public static $asked_trend = [];
		public static $asked_history = [];
		/** itemid => [clock => [avg, min, max]]: the trends the window columns read. */
		public static $trends = [];
		public static $extra_items = [];
		/** A third client, "example": a master host and nothing else — no servers, no figures (QA 2.1.1). */
		public static $silent_client = false;
		/** "host|key" => a last value in place of the one below (the export's places). */
		public static $override = [];
		public static function items(): array {
			$all = [
				[101, 'ep.es.cpu.requested', 16, ''], [101, 'ep.es.cpu.allocated', 8, ''], [101, 'ep.es.cpu.usage', 95, '%'],
				[101, 'ep.parser.cpu.requested', 0, ''], [101, 'ep.role.cpu.requested[es_data_hot]', 8, ''],
				[101, 'ep.es.storage.used', 2 * 1073741824, 'B'],
				[102, 'ep.es.storage.used', 300 * 1073741824, 'B'], [102, 'ep.es.storage.allocated', 1000 * 1073741824, 'B'],
				[102, 'ep.es.storage.purchased', 1200 * 1073741824, 'B'], [102, 'ep.es.mem.allocated', 64 * 1073741824, 'B'],
				[102, 'ep.es.mem.requested', 64 * 1073741824, 'B'], [102, 'ep.es.mem.usage', 50, '%'],
				[201, 'system.cpu.num', 4, ''], [201, 'system.cpu.util', 85, '%'], [201, 'vfs.fs.dependent.size[/data,pused]', 50, '%'],
				[201, 'vfs.fs.dependent.size[/,pused]', 99, '%'], [202, 'vfs.fs.dependent.size[/,pused]', 91, '%'],
				[202, 'vm.memory.size[total]', 8 * 1073741824, 'B'], [201, 'system.uptime', 86400, 'uptime'],
				[201, 'es.ingest.rate', 18400, '/s'], [202, 'es.ingest.rate', 1600, '/s'], [201, 'es.jvm[heap]', 70, '%'], [201, 'es.jvm[old]', 20, '%']
			];
			// "example" as it is on the test Zabbix: a master host whose items all say 0 (requests nobody entered,
			// no servers in any role, nothing purchased) — and nothing else.
			if (self::$silent_client) {
				$all = array_merge($all, [[103, 'ep.es.servers.allocated', 0, ''], [103, 'ep.es.cpu.requested', 0, ''], [103, 'ep.es.cpu.allocated', 0, ''],
					[103, 'ep.es.storage.purchased', 0, 'B'], [103, 'ep.devices.purchased', 0, '']]);
			}
			$all = array_merge($all, self::$extra_items);
			$out = [];
			foreach ($all as $i => [$h, $k, $v, $u]) {
				$v = self::$override[$h.'|'.$k] ?? $v;
				$out[] = ['itemid' => (string) ($h * 1000 + $i), 'hostid' => $h, 'key_' => $k, 'lastvalue' => (string) $v, 'lastclock' => time() - 60,
					'units' => $u, 'state' => 0, 'value_type' => 0, 'name' => $k];
			}
			return $out;
		}
		public static function itemid(int $host, string $key): string {
			foreach (self::items() as $it) {
				if ($it['hostid'] === $host && $it['key_'] === $key) {
					return $it['itemid'];
				}
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
					if (isset($o['templateids'])) {
						return [101 => ['hostid' => 101, 'name' => 'karthi-Master'], 102 => ['hostid' => 102, 'name' => 'acme-Master']]
							+ (self::$silent_client ? [103 => ['hostid' => 103, 'name' => 'example-Master']] : []);
					}
					$if = fn($ip, $a) => [['ip' => $ip, 'type' => 1, 'main' => 1, 'available' => $a]];
					$g = fn(...$n) => array_map(fn($x) => ['name' => $x], $n);
					return [
						201 => ['hostid' => 201, 'name' => 'karthi-ES-Data-Hot-1', 'hostgroups' => $g('karthi', 'ESNodes', 'ES Data Hot'), 'interfaces' => $if('10.0.0.1', 1)],
						205 => ['hostid' => 205, 'name' => 'karthi-Engine-AIML-1', 'hostgroups' => $g('karthi', 'Engines', 'Engine', 'AIML'), 'interfaces' => $if('10.0.0.5', 1),
							'tags' => [['tag' => 'ep-service', 'value' => 'engine'], ['tag' => 'ep-service', 'value' => 'kafka']]],
						202 => ['hostid' => 202, 'name' => 'karthi-Parser-1', 'hostgroups' => $g('karthi', 'Parsers', 'Parser'), 'interfaces' => $if('10.0.0.2', 2)],
						203 => ['hostid' => 203, 'name' => 'karthi-ULM', 'hostgroups' => $g('karthi', 'Log archive'), 'interfaces' => []],
						204 => ['hostid' => 204, 'name' => 'karthi-Forwarder-1', 'hostgroups' => $g('karthi', 'Forwarders'), 'interfaces' => $if('10.0.0.4', 0)]
					];
				case 'HostGroup':
					return array_values(array_filter([['groupid' => 1, 'name' => 'karthi'], ['groupid' => 2, 'name' => 'acme'], ['groupid' => 3, 'name' => 'example']],
						fn($g) => in_array($g['name'], $o['filter']['name'], true)));
				case 'UserMacro':
					$base = [
						['hostid' => 101, 'macro' => '{$GRP.CLIENT}', 'value' => 'karthi'],
						['hostid' => 101, 'macro' => '{$EP.CLIENT.TYPE}', 'value' => 'DI'],
						['hostid' => 101, 'macro' => '{$ES.URL}', 'value' => 'https://es.karthi:9200'],
						['hostid' => 101, 'macro' => '{$EP.ES_DATA_HOT.DISK2.FS}', 'value' => '/data'],
						['hostid' => 102, 'macro' => '{$GRP.CLIENT}', 'value' => 'acme']
					];
					if (self::$silent_client) {
						$base[] = ['hostid' => 103, 'macro' => '{$GRP.CLIENT}', 'value' => 'example'];
					}
					return array_merge($base, array_map(fn($m) => ['hostid' => 101, 'macro' => $m[0], 'value' => $m[1]], self::$extra_macros));
				case 'Item':
					$out = [];
					foreach (self::items() as $it) {
						if (!in_array($it['hostid'], $o['hostids'])) {
							continue;
						}
						if (isset($o['filter']['key_']) && in_array($it['key_'], (array) $o['filter']['key_'], true)) {
							$out[] = $it;
						}
						elseif (isset($o['search']['key_'])) {
							foreach ((array) $o['search']['key_'] as $p) {
								if (strpos($it['key_'], rtrim(explode('*', $p)[0])) === 0) {
									$out[] = $it;
									break;
								}
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
namespace Modules\EpResources\Test {
	// Bars owns the default Display style the view action reads; it is a shared file, copied
	// into this widget's lib by sync-assets.mjs.
	require __DIR__.'/../lib/Bars.php';
	require __DIR__.'/../actions/WidgetView.php';
	use Modules\EpResources\Actions\WidgetView;
	use Modules\EpResources\Includes\{Catalogue, Resources, WidgetForm};
	use Modules\EpResources\Lib\{Bars, ColumnCell, ColumnMath, Columns, ColumnSettings, Roles, Settings};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-res-test-'.getmypid();
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
	/** The class on one client's cell, for the colour cases below. */
	function cls(array $d, string $client, string $id): string {
		foreach ($d['rows'] as $row) {
			if ($row['client'] === $client) { return trim(str_replace('ep-barcell', '', $row['cells'][col($d, $id)]['class'])); }
		}
		return '?';
	}
	function col(array $d, string $id): ?int {
		foreach ($d['columns'] as $i => $c) { if ($c['builtin'] === $id || $c['uid'] === $id) return $i; }
		return null;
	}

	$d = run();
	check('no error', $d['error'] === null, $d['error']);
	check('clients sorted by name, acme first', array_column($d['rows'], 'client') === ['acme', 'karthi']);
	check('types from the master hosts; unset is On-Prem', array_column($d['rows'], 'type') === ['On-Prem', 'DI']);
	check('a family column per resource and kind', col($d, 'f.es.cpu.requested') !== null && col($d, 'f.engine.servers.allocated') !== null);
	check('roles are not their own columns until split', col($d, 'r.es_data_hot.cpu.requested') === null);
	check('Engine is a family of its own', in_array('Engine CPU (cores)', array_column($d['columns'], 'group'), true));
	[$acme, $karthi] = $d['rows'];
	$cell = fn($row, $id) => $row['cells'][col($d, $id)];
	check('allocated below requested is marked', strpos($cell($karthi, 'f.es.cpu.allocated')['class'], 'ep-short') !== false, $cell($karthi, 'f.es.cpu.allocated'));
	check('usage 95 % is red', cls($d, 'karthi', 'f.es.cpu.usage') === 'ep-high', $cell($karthi, 'f.es.cpu.usage'));
	check('a request of 0 is "not set"', $cell($karthi, 'f.parser.cpu.requested')['text'] === 'not set');
	check('unknown is "—", not 0', $cell($acme, 'f.es.cpu.usage')['text'] === '—');
	$e = $d['export'];
	check('export: unknown is an empty cell', $e['rows'][0][col($d, 'f.es.cpu.usage')] === null);
	check('export: bytes as GB, header says so', $e['rows'][1][col($d, 'storage.used')] === 2.0 && $e['headers'][col($d, 'storage.used')] === 'ES storage Used (GB)', $e['headers'][col($d, 'storage.used')]);
	check('export: types beside the rows', $e['types'] === ['On-Prem', 'DI']);
	// The export's places, as 2.1.0 wrote them: a size in the export unit to two, a percentage to one,
	// any other number to two — never the places the table is drawn with.
	// A share that cannot be worked out because what it is a share of is not set says so (QA 2.1.1: it said "no value yet").
	\FakeApi::$extra_items = [[101, 'ep.es.storage.usage', '', '%'], [102, 'ep.es.storage.usage', 30, '%']];
	// The column with No data = "Say why", as the person had it.
	$whyCols = array_map(fn($c) => $c['builtin'] === 'storage.usage' ? ['nodata' => Columns::NODATA_WHY] + $c : $c,
		WidgetForm::upgrade(['columns_from' => '1'], Roles::load(), ColumnSettings::loadModel('resources'))['columns']);
	$d = run(['columns_from' => '1', 'columns' => $whyCols]);
	$used = fn($row) => $row['cells'][col($d, 'storage.usage')];
	[$acmeRow, $karthiRow] = $d['rows'];
	check('Used % with allocated not set, No data "Say why": "ES storage allocated not set", the reason, not "no value yet"', col($d, 'storage.usage') !== null
		&& $used($karthiRow)['text'] === 'ES storage allocated not set' && $used($karthiRow)['value'] === null, $used($karthiRow));
	$d = run();
	check('…and with No data "—" the cell is a dash and the reason is its tooltip', strpos((string) $d['rows'][1]['cells'][col($d, 'storage.usage')]['hint'], 'ES storage allocated not set') === 0);
	check('…and one that has its figure shows it', $used($acmeRow)['value'] === 30.0, $used($acmeRow));
	\FakeApi::$extra_items = [];
	$d = run();
	check('…while a share not collected at all still says "not collected"', col($d, 'storage.usage') === null
		|| strpos((string) $d['rows'][1]['cells'][col($d, 'storage.usage')]['text'], 'allocated not set') === false);
	check('shareOf: as the master template computes the shares', Columns::shareOf('ep.es.storage.usage') === 'ep.es.storage.allocated'
		&& Columns::shareOf('ep.devices.usage') === 'ep.devices.purchased' && Columns::shareOf('ep.es.storage.purchased.usage') === 'ep.es.storage.purchased'
		&& Columns::shareOf('ep.parser.disk.usage') === 'ep.parser.disk.allocated' && Columns::shareOf('ep.role.disk.usage[es_data_hot]') === 'ep.role.disk.allocated[es_data_hot]'
		&& Columns::shareOf('ep.es.cpu.usage') === null && Columns::shareOf('ep.es.mem.usage') === null);
	\FakeApi::$override = ['101|ep.es.storage.used' => 2.3456 * 1073741824, '101|ep.es.cpu.usage' => 95.456, '101|ep.es.cpu.requested' => 16.456];
	$places = function (array $d) {
		$at = array_search('karthi', array_column($d['rows'], 'client'), true);
		$r = $d['export']['rows'][$at];
		return [$r[col($d, 'storage.used')], $r[col($d, 'f.es.cpu.usage')], $r[col($d, 'f.es.cpu.requested')]];
	};
	check('export places: 2.3456 GB is 2.35, 95.456 % is 95.5, 16.456 cores is 16.46 — as before 2.1.1', $places(run()) === [2.35, 95.5, 16.46], $places(run()));
	Settings::save(['display' => ['decimals_bytes' => 0, 'decimals_percent' => 3, 'decimals_plain' => 0]]);
	check('…and the site\'s Decimals do not move them', $places(run()) === [2.35, 95.5, 16.46], $places(run()));
	Settings::reset();
	\FakeApi::$override = [];

	$srv = array_column($karthi['servers'], 'raw');
	$multi = array_values(array_filter($srv, fn($x) => $x['host'] === 'karthi-Engine-AIML-1'))[0] ?? null;
	check('a server with two roles is one row naming both, marked shared, with its extra services', $multi !== null && $multi['role'] === 'Engine + AIML'
		&& $multi['shared'] && $multi['services'] === ['kafka'], $multi);
	$srv = array_values(array_filter($srv, fn($x) => $x['host'] !== 'karthi-Engine-AIML-1'));
	check('karthi expands into its machines with a role (ULM has none)', array_column($srv, 'host') === ['karthi-ES-Data-Hot-1', 'karthi-Forwarder-1', 'karthi-Parser-1'], array_column($srv, 'host'));
	check('the ES data node shows / and its data disk, each measured', array_column($srv[0]['disks'], 'mount') === ['/', '/data']
		&& $srv[0]['disks'][0]['used'] === 99.0 && $srv[0]['disks'][1]['used'] === 50.0, $srv[0]['disks']);
	check('there is no one fullest-disk figure per server', !array_key_exists('disk_used', $srv[0]));
	check('other roles have / only', $srv[2]['mount'] === '/' && $srv[2]['disks'][0]['used'] === 91.0);
	check('status from the agent interface', array_column($srv, 'status') === ['up', 'unknown', 'down']);
	check('devices: purchased and seen columns', col($d, 'devices.purchased') !== null && col($d, 'devices.seen') !== null);
	check('server colours: 85 % CPU yellow, 91 % disk red', $srv[0]['classes']['cpu'] === 'ep-warn' && $srv[2]['disks'][0]['class'] === 'ep-high');
	check('unmeasured server figures stay unknown', $srv[1]['cpu_used'] === null && $srv[1]['mem_total'] === null);
	check('every disk is coloured by its own figure: / at 99 % red, /data at 50 % plain',
		$srv[0]['disks'][0]['class'] === 'ep-high' && $srv[0]['disks'][1]['class'] === '', $srv[0]['disks']);

	check('acme has no servers', $acme['servers'] === []);
	check('servers export in the table\'s client order', array_column($e['servers']['rows'], 4) === ['karthi-ES-Data-Hot-1', 'karthi-Engine-AIML-1', 'karthi-Forwarder-1', 'karthi-Parser-1']);
	check('servers export: one row per machine, GB', count($e['servers']['rows']) === 4 && $e['servers']['rows'][3][9] === 8.0 && $e['servers']['types'] === ['DI', 'DI', 'DI', 'DI']);
	check('the dialog gets a token for its own action', $d['meta']['token'] === 'token:widget.ep_resources.columns' && $d['meta']['canEdit'] === true);

	// A Columns file as every release before 2.1.1 wrote it — split ES into its roles, hide the URL,
	// rename a column, move Type first, the whole order keyed by the report's own column ids — is
	// read onto the column model unchanged, and not rewritten.
	$order = array_column($d['columns'], 'builtin');
	[$order[0], $order[1]] = [$order[1], $order[0]];
	ColumnSettings::save('resources', ['labels' => ['client.name' => 'Client'], 'hidden' => ['client.url'], 'order' => $order, 'split' => ['es']], null);
	$v1 = file_get_contents(getenv('EP_DATA_DIR').'/columns-resources.json');
	$d = run();
	check('split: ES roles are sections', col($d, 'r.es_data_hot.cpu.requested') !== null && col($d, 'f.es.cpu.requested') === null);
	check('split: the role figure is the role\'s item', $d['rows'][1]['cells'][col($d, 'r.es_data_hot.cpu.requested')]['text'] === '8');
	check('split: Parser stays one family', col($d, 'f.parser.cpu.requested') !== null);
	check('hidden: not in the table nor the export', col($d, 'client.url') === null && !in_array('ES URL', $d['export']['headers'], true));
	check('hidden: still offered in the dialog', in_array('ES URL', array_column($d['meta']['columns'], 'label'), true)
		&& array_column($d['meta']['columns'], 'hidden', 'label')['ES URL'] === true);
	check('renamed and reordered', $d['columns'][0]['builtin'] === 'client.type' && $d['columns'][1]['name'] === 'Client' && $d['export']['headers'][1] === 'Client',
		array_slice(array_column($d['columns'], 'builtin'), 0, 4));
	check('the dialog offers the renamed column under its new name, with the report\'s own beside it',
		in_array(['label' => 'Client', 'default' => 'Cluster Name'], array_map(fn($c) => ['label' => $c['label'], 'default' => $c['default']], $d['meta']['columns']), true));
	check('the version-1 file is read, not rewritten', file_get_contents(getenv('EP_DATA_DIR').'/columns-resources.json') === $v1);
	check('the dialog knows ES is split', array_column($d['meta']['families'], 'split', 'id')['es'] === true);

	// A family added on the Roles page is in the report, with no change here.
	$roles = Roles::load();
	$roles['families'][] = ['id' => 'soar', 'label' => 'SOAR', 'group' => 'SOAR', 'macroPrefix' => 'SOAR', 'roles' => [['id' => 'soar_node', 'label' => 'SOAR', 'short' => 'SOAR', 'group' => 'SOAR nodes']]];
	check('the new family is valid', Roles::validate($roles) === [], Roles::validate($roles));
	Roles::save($roles);
	$d = run();
	check('a new family appears as its own columns', col($d, 'f.soar.cpu.requested') !== null && in_array('SOAR memory', array_column($d['columns'], 'group'), true));

	// This widget's copy of the shared Roles: the same neutral defaults, the same override file.
	check('every template slot defaults to the name ElasticPro ships',
		Roles::templateNames() === array_map(fn($slot) => $slot[0], Roles::TEMPLATE_SLOTS), Roles::templateNames());
	Roles::saveTemplateNames(['agent' => 'Linux by Zabbix agent -ACME']);
	check('a name set in the settings reaches the widget too', Roles::defaultTemplate() === 'Linux by Zabbix agent -ACME');
	Roles::saveTemplateNames([]);

	// The bars: the same figure the colour came from, and nothing at all when there is no figure.
	check('no bar is drawn for a value Elasticsearch did not report',
		Bars::cell('—', null, 'under') === '—');
	check('numbers only draws the value and nothing else', Bars::cell('62 %', 62.0, 'none') === '62 %');
	check('an unknown style is treated as numbers only, never as a guess',
		Bars::cell('62 %', 62.0, 'nonsense') === '62 %');
	$under = Bars::cell('62 %', 62.0, 'under')->toString();
	check('the bar under the value is drawn to the percentage given',
		str_contains($under, 'ep-bar-under') && str_contains($under, 'width:62%'), $under);
	check('and the value is still there to read', str_contains($under, '62 %'));
	$inCell = Bars::cell('91 %', 91.0, 'cell')->toString();
	check('the in-cell bar is the same figure', str_contains($inCell, 'ep-bar-incell') && str_contains($inCell, 'width:91%'));
	$segs = Bars::cell('50 %', 50.0, 'segments')->toString();
	check('segments light half the blocks at half', substr_count($segs, 'ep-seg-on') === 6, substr_count($segs, 'ep-seg-on'));
	check('segments light none at nought and all at a hundred',
		substr_count(Bars::cell('0 %', 0.0, 'segments')->toString(), 'ep-seg-on') === 0
		&& substr_count(Bars::cell('100 %', 100.0, 'segments')->toString(), 'ep-seg-on') === 12);
	check('a figure over a hundred per cent fills the bar and no more',
		str_contains(Bars::cell('140 %', 140.0, 'under')->toString(), 'width:100%'));
	check('a negative figure draws an empty bar, not a backwards one',
		str_contains(Bars::cell('-5 %', -5.0, 'under')->toString(), 'width:0%'));
	check('the default style is one the widget offers', in_array(Bars::DEFAULT_STYLE, Bars::STYLES, true));

	// Zabbix validates a CWidgetFieldSelect as an integer: a field offering the styles as
	// strings is refused with "an integer is expected" and the widget then renders nothing at
	// all. The setting therefore stores the position in STYLES, and styleOf() turns it back.
	check('what the widget stores is an integer position', Bars::options() === [0 => 'Bar under the value',
		1 => 'Bar in the cell', 2 => 'Segments', 3 => 'Numbers only'], Bars::options());
	check('every option maps to a style', array_keys(Bars::options()) === array_keys(Bars::STYLES));
	check('the stored integer becomes the style', Bars::styleOf(0) === 'under' && Bars::styleOf(1) === 'cell'
		&& Bars::styleOf(2) === 'segments' && Bars::styleOf(3) === 'none');
	check('the default value is the default style', Bars::styleOf(Bars::DEFAULT_VALUE) === Bars::DEFAULT_STYLE);
	check('a value from some later release falls back to the default rather than drawing nothing',
		Bars::styleOf(99) === Bars::DEFAULT_STYLE && Bars::styleOf(null) === Bars::DEFAULT_STYLE
		&& Bars::styleOf('') === Bars::DEFAULT_STYLE);
	check('a style name is still accepted, so a stored name is never mistaken for position 0',
		Bars::styleOf('segments') === 'segments');

	/* ---------------- the site's settings reach this report ---------------- */
	// From here on the columns are the plain ones: the cases above left a family split and
	// columns renamed and hidden, which would move every index these cases read by name.
	ColumnSettings::reset('resources');
	// karthi's ES CPU is 95 % used. Which colour that is must follow the one order: the client's
	// own macros, then this widget's fields, then ElasticPro -> Settings.
	Settings::reset();
	$d = run();
	check('with nothing set, 95 % is red at the shipped thresholds', cls($d, 'karthi', 'f.es.cpu.usage') === 'ep-high');

	Settings::save(['usage' => ['warn' => 96, 'high' => 97]]);
	$d = run();
	check('raising the site thresholds takes the colour off it', cls($d, 'karthi', 'f.es.cpu.usage') === '');
	$d = run(['usage_warn' => 90, 'usage_high' => 94]);
	check('this widget\'s own thresholds beat the site\'s', cls($d, 'karthi', 'f.es.cpu.usage') === 'ep-high');
	\FakeApi::$extra_macros = [['{$EP.USAGE.WARN}', '10'], ['{$EP.USAGE.HIGH}', '99']];
	$d = run(['usage_warn' => 90, 'usage_high' => 94]);
	check('and the client\'s own macros beat both', cls($d, 'karthi', 'f.es.cpu.usage') === 'ep-warn');
	check('a client without those macros still follows the widget', cls($d, 'acme', 'f.es.cpu.usage') === '');
	\FakeApi::$extra_macros = [];
	Settings::reset();

	// Display: the widget follows the site until it is given a style of its own; a column left at
	// "Widget default" follows the widget.
	$barOf = function (array $d) { foreach ($d['rows'] as $r) { if ($r['client'] === 'karthi') { return $r['cells'][col($d, 'f.es.cpu.usage')]['bar']['style'] ?? 'none'; } } };
	$d = run();
	check('a widget nobody has configured follows the site\'s bar style', $barOf($d) === Bars::DEFAULT_STYLE, $barOf($d));
	Settings::save(['display' => ['bars' => 2]]);
	check('and moves when the site does', $barOf(run()) === 'segments');
	check('but not when the widget was given one', $barOf(run(['bars' => 1])) === 'cell');
	check('the inherit value is never drawn as a style', $barOf(run(['bars' => Bars::INHERIT])) === 'segments');
	check('numbers only draws no bar, and still colours the figure', $barOf(run(['bars' => 3])) === 'none' && cls(run(['bars' => 3]), 'karthi', 'f.es.cpu.usage') === 'ep-high');
	Settings::reset();

	// Decimals.
	$GLOBALS['ep_decimals'] = [];
	run();
	check('figures are drawn to the places the site shipped',
		($GLOBALS['ep_decimals']['%'] ?? null) === 1 && ($GLOBALS['ep_decimals']['B'] ?? null) === 2 && ($GLOBALS['ep_decimals'][''] ?? null) === 0,
		$GLOBALS['ep_decimals']);
	Settings::save(['display' => ['decimals_percent' => 3, 'decimals_bytes' => 0]]);
	$GLOBALS['ep_decimals'] = [];
	run();
	check('and follow the site when it changes its mind',
		($GLOBALS['ep_decimals']['%'] ?? null) === 3 && ($GLOBALS['ep_decimals']['B'] ?? null) === 0,
		$GLOBALS['ep_decimals']);
	Settings::reset();

	// The export's size unit, and the header that must agree with it.
	$d = run();
	$i = col($d, 'storage.used');
	// Rows are sorted by client name, so acme is 0 and karthi — the one with figures — is 1.
	check('sizes export in GB by default', $d['export']['rows'][1][$i] === 2.0, $d['export']['rows'][1][$i]);
	check('and the header says so', strpos($d['export']['headers'][$i], '(GB)') !== false, $d['export']['headers'][$i]);
	Settings::save(['export' => ['size_unit' => 'MB']]);
	$d = run();
	check('choosing MB changes the figure', $d['export']['rows'][1][$i] === 2048.0, $d['export']['rows'][1][$i]);
	check('and the header with it, so the two never disagree', strpos($d['export']['headers'][$i], '(MB)') !== false, $d['export']['headers'][$i]);
	check('the servers sheet follows the same unit',
		in_array('Memory (MB)', $d['export']['servers']['headers'], true) && in_array('Disk (MB)', $d['export']['servers']['headers'], true),
		$d['export']['servers']['headers']);
	Settings::reset();

	// Client types: the list the filter offers, and what a client with no type counts as.
	$d = run();
	check('the Type filter is offered the site\'s own list, CI included', $d['meta']['types'] === ['CI', 'DI', 'On-Prem'], $d['meta']['types']);
	check('a client with no type of its own is the site default', $d['rows'][array_search('acme', array_column($d['rows'], 'client'))]['type'] === 'On-Prem');
	Settings::save(['types' => ['all' => ['CI', 'DI', 'Managed'], 'default' => 'Managed']]);
	$d = run();
	check('a type added to the site reaches the filter', $d['meta']['types'] === ['CI', 'DI', 'Managed']);
	check('and the default reaches the clients that have none', $d['rows'][array_search('acme', array_column($d['rows'], 'client'))]['type'] === 'Managed');
	Settings::reset();

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
	check('no template: says where to write it', strpos((string) $d['error'], 'ElasticPro → Clients') !== false);
	check('and names the template this Zabbix is meant to have', strpos((string) $d['error'], 'ACME client master') !== false, $d['error']);
	Roles::saveTemplateNames([]);
	\FakeApi::$template = true;

	/* ---------------- a widget saved before 2.1.1 ---------------- */
	ColumnSettings::reset('resources');
	$roles = Roles::load();
	$model = ColumnSettings::loadModel('resources');
	$old = WidgetForm::upgrade(['groupids' => ['1'], 'bars' => '2', 'usage_warn' => '0', 'usage_high' => '0'], $roles, $model);
	check('an old widget: a Warn and High of 0 are empty — ElasticPro Settings', $old['usage_warn'] === '' && $old['usage_high'] === '');
	check('…it follows the report defaults, with nothing of its own', !isset($old['columns']) && !isset($old['server_columns']) && $old['bars'] === '2');
	check('…a threshold someone typed is kept', WidgetForm::upgrade(['usage_warn' => '70'], $roles, $model)['usage_warn'] === '70');
	$d = run(['bars' => '2', 'usage_warn' => '', 'usage_high' => '']);
	check('…and draws the report as it always did: the same columns, in the same order', array_column($d['columns'], 'builtin')
		=== array_keys(array_filter(Catalogue::forScope(Catalogue::columns($roles, []), 'client'), fn($e) => !empty($e['auto']))), array_column($d['columns'], 'builtin'));
	check('…a compact table, group headers, a sticky header and a frozen first column, servers on click',
		$d['view']['view'] === WidgetForm::VIEW_TABLE && $d['view']['density'] === WidgetForm::DENSITY_COMPACT && $d['view']['group_headers']
		&& $d['view']['sticky_header'] && $d['view']['freeze_first'] && $d['view']['server_rows'] === WidgetForm::ROWS_CLICK && !$d['view']['totals']);
	check('…the toolbar on hover, with search, type, expand all, Columns and export',
		$d['view']['toolbar'] === ['search', 'type', 'expand', 'columns', 'export'] && $d['view']['toolbar_pos'] === WidgetForm::TOOLBAR_HOVER);
	check('…the server rows as they were: Disk mount and Uptime off', array_column(array_filter(Catalogue::serverDefaults(), fn($c) => $c['hidden']), 'builtin')
		=== ['row.disk_mount', 'row.uptime']);
	check('…and its colours are the palette\'s, not anything kept in a browser', strpos($d['palette'], '--ep-ok:') === 0);

	// This widget's own columns start from the report defaults; new roles arrive unticked.
	$own = WidgetForm::upgrade(['columns_from' => '1'], $roles, $model);
	check('switched to This widget: the report defaults copied in', array_column($own['columns'], 'builtin') === array_column($d['columns'], 'builtin')
		&& count($own['server_columns']) === count(Catalogue::serverColumns()));
	$short = array_slice($own['columns'], 0, 4);
	$own = WidgetForm::upgrade(['columns_from' => '1', 'columns' => $short], $roles, $model);
	check('a column the widget never had (a new role) is offered, unticked', count($own['columns']) === count($d['columns'])
		&& !array_filter(array_slice($own['columns'], 4), fn($c) => !$c['hidden']));
	$own = WidgetForm::upgrade(['columns_from' => '1', 'columns' => $short, 'sortorder' => ['columns' => ['2', '0', '1', '3']]], $roles, $model);
	check('the order the rows were dragged into is the order kept', array_column(array_slice($own['columns'], 0, 4), 'uid') === array_column([$short[2], $short[0], $short[1], $short[3]], 'uid'));
	$back = WidgetForm::upgrade(['columns_from' => '0', 'columns' => $short], $roles, $model);
	check('Reset to report defaults keeps nothing of the widget\'s own', !isset($back['columns']));
	check('donuts: what the form ticked, in its order', WidgetForm::upgrade(['pie_shown' => ['', 'storage', 'f.es.mem']], $roles, $model)['pie_donuts'] === ['storage', 'f.es.mem']
		&& WidgetForm::upgrade(['pie_shown' => ['']], $roles, $model)['pie_donuts'] === '');

	/* ---------------- the widget's own columns ---------------- */
	$col = fn(array $c) => $c + ['uid' => substr(md5(json_encode($c)), 0, 8), 'source' => Columns::SOURCE_BUILTIN];
	$mine = [
		$col(['name' => 'Client', 'builtin' => 'client.name', 'click' => Columns::CLICK_DASHBOARD]),
		$col(['group' => 'ES storage', 'name' => 'Used of allocated', 'source' => Columns::SOURCE_CALC, 'calc_a' => 'storage.used', 'calc_op' => Columns::OP_OF,
			'calc_b' => 'storage.allocated', 'show' => Columns::SHOW_BAR, 'bar_style' => Columns::BAR_SEGMENTS, 'segments' => 10, 'nodata' => Columns::NODATA_WHY]),
		$col(['group' => 'Ingest', 'name' => 'Docs/s', 'source' => Columns::SOURCE_ITEM, 'key' => 'es.ingest.rate', 'combine' => Columns::COMBINE_SUM, 'short' => 1,
			'use_settings' => 0, 'worse' => Columns::WORSE_LOWER, 'thresholds' => [['level' => 1, 'value' => '30000', 'color' => ''], ['level' => 2, 'value' => '10000', 'color' => '#AA0000']],
			'show' => Columns::SHOW_CELL]),
		$col(['group' => 'Ingest', 'name' => 'Hot docs/s', 'source' => Columns::SOURCE_ITEM, 'key' => 'es.ingest.rate', 'roles' => ['es_data_hot'], 'combine' => Columns::COMBINE_SUM]),
		$col(['group' => 'JVM', 'name' => 'Worst', 'source' => Columns::SOURCE_ITEM, 'key' => 'es.jvm[*]', 'pattern' => 1, 'combine' => Columns::COMBINE_MAX, 'units' => '%',
			'show' => Columns::SHOW_BADGE, 'warn_color' => '#123456']),
		$col(['group' => 'ES CPU', 'name' => '7d avg', 'builtin' => 'f.es.cpu.usage', 'value' => Columns::VALUE_AVG, 'show' => Columns::SHOW_TEXT]),
		$col(['group' => 'ES CPU', 'name' => '7d change', 'builtin' => 'f.es.cpu.usage', 'value' => Columns::VALUE_CHANGE]),
		$col(['group' => 'ES CPU', 'name' => 'Trend', 'builtin' => 'f.es.cpu.usage', 'show' => Columns::SHOW_SPARKLINE]),
		$col(['group' => 'Gone', 'name' => 'Old role', 'builtin' => 'r.nope.cpu.usage'])
	];
	$cpu = \FakeApi::itemid(101, 'ep.es.cpu.usage');
	$now = time();
	\FakeApi::$trends = [$cpu => [$now - 3 * 3600 => [80, 70, 90], $now - 2 * 3600 => [70, 60, 75], $now - 3600 => [96, 90, 99]]];
	\FakeApi::$asked = []; \FakeApi::$asked_trend = []; \FakeApi::$asked_history = [];
	$d = run(['columns_from' => '1', 'columns' => $mine, 'server_columns' => Catalogue::serverDefaults(), 'trend_days' => '7']);
	check('own columns: no error', $d['error'] === null, $d['error']);
	$byName = function (array $d, string $client, string $name) {
		foreach ($d['rows'] as $r) {
			if ($r['client'] === $client) {
				foreach ($d['columns'] as $i => $c) { if ($c['name'] === $name) return $r['cells'][$i]; }
			}
		}
		return null;
	};
	check('a column that no longer passes is left out and named', count($d['dropped']) === 1 && strpos($d['dropped'][0], 'Column "Gone · Old role"') === 0, $d['dropped']);
	$c = $byName($d, 'acme', 'Used of allocated');
	check('x of y: the share, the two figures, judged on the share', strpos($c['text'], '300 B') === false && strpos($c['text'], ' of ') !== false
		&& strpos($c['text'], '30 %') !== false && $c['bar']['share'] === 30.0 && $c['bar']['style'] === 'segments' && $c['bar']['segments'] === 10, $c);
	$c = $byName($d, 'karthi', 'Used of allocated');
	check('allocated not set: unknown, and the cell says why — never a guessed share', $c['value'] === null && $c['bar'] === null
		&& $c['text'] === 'ES storage allocated not set' && $c['class'] === 'ep-nodata', $c);
	$c = $byName($d, 'karthi', 'Docs/s');
	check('an item key, summed over the client\'s servers, short numbers', $c['value'] === 20000.0 && $c['text'] === '20k/s', $c);
	check('lower is worse: under 30k is Warn, coloured cell', $c['level'] === 'warn' && strpos($c['class'], 'ep-fill-warn') !== false, $c);
	check('only the chosen roles\' servers', $byName($d, 'karthi', 'Hot docs/s')['value'] === 18400.0);
	check('a client with no servers: unknown with the reason, not 0', $byName($d, 'acme', 'Docs/s')['value'] === null
		&& $byName($d, 'acme', 'Docs/s')['text'] === 'no servers with a role', $byName($d, 'acme', 'Docs/s'));
	$c = $byName($d, 'karthi', 'Worst');
	check('a pattern, worst item per server, highest server; a badge', $c['value'] === 70.0 && $c['badge'] && strpos($c['class'], 'ep-badge-ok') !== false, $c);
	$c = $byName($d, 'karthi', '7d avg');
	check('the average over the window, from trends, at Settings\' thresholds', $c['value'] === 82.0 && $c['level'] === 'warn'
		&& $c['class'] === 'ep-warn', $c);
	check('the change over the window, signed', $byName($d, 'karthi', '7d change')['value'] === 16.0 && strpos($byName($d, 'karthi', '7d change')['text'], '+') === 0,
		$byName($d, 'karthi', '7d change'));
	check('a sparkline of the window', $byName($d, 'karthi', 'Trend')['spark'] === [80.0, 70.0, 96.0], $byName($d, 'karthi', 'Trend')['spark']);
	check('no trend yet is said, not drawn as 0', $byName($d, 'acme', '7d avg')['value'] === null);
	check('the client name links to the client\'s dashboard, a Zabbix page', $byName($d, 'karthi', 'Client')['url'] === 'zabbix.php?action=host.dashboard.view&hostid=101');
	check('batched: one trend.get for every window, no history for a 7-day window', count(\FakeApi::$asked_trend) === 1 && \FakeApi::$asked_history === []);
	check('batched: one item.get for the exact keys and one for the pattern, besides the servers\'',
		count(array_filter(\FakeApi::$asked, fn($o) => isset($o['search']))) === 1 && count(\FakeApi::$asked) <= 3, count(\FakeApi::$asked));
	$e = $d['export'];
	$i = array_search('Used of allocated', array_column($d['columns'], 'name'));
	check('export: the share of x of y, in %, unknown empty', $e['headers'][$i] === 'ES storage Used of allocated (%)' && $e['rows'][0][$i] === 30.0 && $e['rows'][1][$i] === null,
		[$e['headers'][$i], array_column($e['rows'], $i)]);
	check('export: only the columns the widget shows', count($e['headers']) === count($d['columns']));

	// Colours: a threshold's own colour, a column's Warn colour, fixed bars — strict #RRGGBB, into custom properties only.
	$mine[2]['thresholds'][0]['value'] = '25000';
	$d = run(['columns_from' => '1', 'columns' => array_merge($mine, [$col(['group' => 'ES CPU', 'name' => 'Fixed', 'builtin' => 'f.es.cpu.usage', 'show' => Columns::SHOW_BAR,
		'bar_color' => Columns::BARCOLOR_FIXED, 'bar_fixed' => '#00AA00', 'track' => '#EEEEEE']), $col(['group' => 'JVM', 'name' => 'Old', 'source' => Columns::SOURCE_ITEM,
		'key' => 'es.jvm[old]', 'units' => '%', 'use_settings' => 1, 'warn' => '10', 'show' => Columns::SHOW_TEXT, 'warn_color' => '#123456'])]), 'trend_days' => '7']);
	$c = $byName($d, 'karthi', 'Fixed');
	check('a fixed bar colour and track', strpos($c['style'], '--ep-bar:#00AA00') !== false && strpos($c['style'], '--ep-track:#EEEEEE') !== false
		&& strpos($c['class'], 'ep-barfixed') !== false, $c);
	$c = $byName($d, 'karthi', 'Old');
	check('a column\'s own Warn colour, with text that reads on it', $c['level'] === 'warn' && strpos($c['style'], '--ep-lv:#123456') !== false
		&& strpos($c['style'], '--ep-lv-fg:#ffffff') !== false, $c);
	$c = $byName($d, 'karthi', 'Docs/s');
	check('a threshold\'s own colour (High under 10k is not crossed; Warn under 25k is)', $c['level'] === 'warn' && strpos($c['style'], '--ep-lv') === false, $c);

	// Count over Warn is a number of servers: no %, no bar of a share.
	$d = run(['columns_from' => '1', 'columns' => [$col(['name' => 'Client', 'builtin' => 'client.name']),
		$col(['group' => 'Hot', 'name' => 'Busy servers', 'builtin' => 'srv.cpu', 'combine' => Columns::COMBINE_COUNT, 'show' => Columns::SHOW_BAR])]]);
	$c = $byName($d, 'karthi', 'Busy servers');
	check('count over Warn: a count, never a percentage (its bar runs to the highest count seen)', $c['value'] === 1.0 && $c['text'] === '1'
		&& $c['sub'] === '' && $c['bar']['share'] === 100.0 && $c['level'] === 'warn', $c);
	// A forecast that is not growing exports nothing, not its raw seconds.
	\FakeApi::$extra_items = [[101, 'ep.es.storage.full_in', -1, 's']];
	$d = run(['columns_from' => '1', 'columns' => [$col(['name' => 'Client', 'builtin' => 'client.name']), $col(['group' => 'ES storage', 'name' => 'Full in', 'builtin' => 'storage.full_in'])]]);
	\FakeApi::$extra_items = [];
	check('not growing: said, exported empty, never -1', $byName($d, 'karthi', 'Full in')['text'] === 'not growing' && $d['export']['rows'][1][1] === null,
		[$byName($d, 'karthi', 'Full in')['text'], $d['export']['rows'][1]]);

	/* ---------------- sort, top N, totals ---------------- */
	$plain = ['columns_from' => '1', 'columns' => [$col(['name' => 'Client', 'builtin' => 'client.name']), $col(['group' => 'ES storage', 'name' => 'Used', 'builtin' => 'storage.used'])]];
	$used = $plain['columns'][1]['uid'];
	$d = run($plain + ['sort_by' => $used, 'sort_dir' => WidgetForm::SORT_DESC]);
	check('sorted by a column, highest first', array_column($d['rows'], 'client') === ['acme', 'karthi'], array_column($d['rows'], 'client'));
	$d = run($plain + ['sort_by' => $used, 'sort_dir' => WidgetForm::SORT_ASC, 'top_n' => '1']);
	check('…lowest first, top 1', array_column($d['rows'], 'client') === ['karthi']);
	$d = run($plain + ['sort_by' => 'gone-column']);
	check('a sort by a column no longer there sorts by client', array_column($d['rows'], 'client') === ['acme', 'karthi']);
	$d = run($plain + ['totals' => '1']);
	// The stand-in convertUnits writes bytes as bytes: 2 GiB + 300 GiB.
	check('totals: the sum of the known figures', $d['totals'] === ['Total (all clients)', (302 * 1073741824).' B'], $d['totals']);
	check('totals: average when asked', run($plain + ['totals' => '1', 'totals_fn' => WidgetForm::TOTALS_AVG])['totals'] === ['Average (all clients)', (151 * 1073741824).' B']);
	check('totals over the top N say so', run($plain + ['totals' => '1', 'top_n' => '1'])['totals'][0] === 'Total (top 1)');
	check('totals: unknown adds nothing, and nothing known is no total', Resources::total([null, 4.0], '', false) === 4.0 && Resources::total([null], '', false) === null
		&& Resources::total([50.0, 70.0], '%', false) === 60.0 && Resources::total([2.0, 4.0], 'B', true) === 3.0);

	/* ---------------- Pie chart, chips, Needs attention ---------------- */
	$d = run(['view' => '1', 'view_switch' => '1', 'pie_donuts' => ['storage', 'f.es.mem', 'f.es.cpu'], 'pie_centre' => WidgetForm::CENTRE_PCT_ALLOCATED,
		'attention' => ['high', 'down'], 'attention_default' => WidgetForm::ATTENTION_ONLY, 'toolbar_items' => ['search', 'attention', 'view']]);
	$acme = $d['rows'][0]; $karthi = $d['rows'][1];
	check('the donuts chosen, in the order chosen', array_column($acme['donuts'], 'id') === ['storage', 'f.es.cpu', 'f.es.mem'], array_column($acme['donuts'], 'id'));
	$st = $acme['donuts'][0];
	check('ES storage: used 300 of 1000 GB, 200 short of 1200 requested', $st['known'] && $st['centre'] === '30%' && $st['sub'] === 'used of allocated'
		&& array_column($st['legend'], 'key') === ['used', 'free', 'short', 'allocated', 'requested'], $st);
	check('…the ring\'s shares', abs($st['segments']['used'] - 25) < 1e-9 && abs($st['segments']['free'] - 58.3333333) < 1e-6 && abs($st['segments']['short'] - 16.6666667) < 1e-6);
	check('…the legend with its percentages', $st['legend'][0]['pct'] === '25 %' && $st['legend'][3]['pct'] === '');
	$mem = $acme['donuts'][2];
	check('memory measured as a share: used is that share of allocated', $mem['known'] && $mem['centre'] === '50%' && $mem['legend'][0]['value'] === '34359738368 B', $mem['legend']);
	check('no short when allocated meets requested', !in_array('short', array_column($mem['legend'], 'key'), true));
	$kst = $karthi['donuts'][0];
	check('allocated not set: no ring, the reason in the centre', !$kst['known'] && $kst['sub'] === 'allocated not set' && $kst['segments'] === []
		&& $kst['legend'][1]['value'] === 'not set' && $kst['level'] === 'unknown', $kst);
	check('CPU with nothing measured: allocated not set too', $acme['donuts'][1]['known'] === false);
	check('chips: a server down, a high, a short, a server sending no data', array_column($karthi['chips'], 'text') === ['1 down', '1 high', '1 short', '1 no data'],
		$karthi['chips']);
	check('chips: nothing wrong is OK', Resources::chips([]) === [['text' => 'OK', 'level' => 'ok']]);
	// A client of which nothing reports — no servers, no figure — is no data, not OK (QA 2.1.1: "No data" matched none).
	\FakeApi::$silent_client = true;
	$ds = run(['attention' => ['nodata'], 'attention_default' => WidgetForm::ATTENTION_ONLY]);
	$byName = array_column($ds['rows'], null, 'client');
	check('a client with no servers whose master host says only 0: Needs attention "No data" matches it, its chip says no data', isset($byName['example'])
		&& in_array(0.0, array_column($byName['example']['cells'], 'value'), true)
		&& $byName['example']['attention'] && $byName['example']['flags']['silent'] && array_column($byName['example']['chips'], 'text') === ['no data'],
		$byName['example'] ?? array_keys($byName));
	check('…not acme, which has no servers but reports figures, nor karthi\'s count of servers sending nothing changed', !$byName['acme']['attention']
		&& !$byName['acme']['flags']['silent'] && $byName['karthi']['attention'] && $byName['karthi']['flags']['nodata'] === 1);
	\FakeApi::$silent_client = false;
	check('silent: no figure but zeros and no server sending; a figure other than 0 or a server up or down is not', ColumnCell::silent([['value' => null], ['value' => 'x']], [])
		&& ColumnCell::silent([], [['status' => 'unknown']]) && ColumnCell::silent([['value' => 0.0], ['value' => 0]], []) && !ColumnCell::silent([['value' => 0.5]], [])
		&& !ColumnCell::silent([['value' => 0.0]], [['status' => 'up']]) && !ColumnCell::silent([], [['status' => 'down']]));
	check('Needs attention (High, server down): karthi, not acme — whose only fault is a short', $karthi['attention'] && !$acme['attention'] && $acme['flags']['short'] === 1,
		[$acme['flags'], $karthi['flags']]);
	check('…by what the widget says it means', !Resources::attention(['warn' => 2], ['high']) && Resources::attention(['warn' => 2], ['warn'])
		&& Resources::attention(['full_days' => 12.0], ['disk30']) && !Resources::attention(['full_days' => 45.0], ['disk30']) && Resources::attention(['nodata' => 1], ['nodata']));
	check('the View settings reach the widget', $d['view']['view'] === WidgetForm::VIEW_PIE && $d['view']['switch'] && $d['view']['attention_default']
		&& $d['meta']['view'] === ['view' => WidgetForm::VIEW_PIE, 'switch' => true, 'attention' => true, 'compact' => true]);
	check('Short hidden: out of the ring and the legend', !in_array('short', array_column(run(['pie_short' => WidgetForm::SHORT_HIDDEN])['rows'][0]['donuts'][0]['legend'], 'key'), true));
	$d = run(['pie_centre' => WidgetForm::CENTRE_PCT_REQUESTED, 'pie_legend' => ['used', 'requested']]);
	check('centre: used of requested; legend rows chosen, no %', $d['rows'][0]['donuts'][0]['centre'] === '25%' && $d['rows'][0]['donuts'][0]['sub'] === 'used of requested'
		&& array_column($d['rows'][0]['donuts'][0]['legend'], 'key') === ['used', 'requested'] && $d['rows'][0]['donuts'][0]['legend'][0]['pct'] === '');
	check('centre: free value', run(['pie_centre' => WidgetForm::CENTRE_FREE])['rows'][0]['donuts'][0]['centre'] === (700 * 1073741824).' B');
	check('centre: used value', run(['pie_centre' => WidgetForm::CENTRE_USED])['rows'][0]['donuts'][0]['centre'] === (300 * 1073741824).' B');

	/* ---------------- the Columns dialog on the report defaults (ColumnsSave) ---------------- */
	require_once __DIR__.'/../actions/ColumnsSave.php';
	$save = function (array $input): array {
		$a = new \Modules\EpResources\Actions\ColumnsSave($input + ['report' => 'resources']);
		(new \ReflectionMethod($a, 'doAction'))->invoke($a);
		return json_decode($a->response->data['main_block'], true);
	};
	ColumnSettings::reset('resources');
	$d = run();
	$meta = $d['meta']['columns'];
	$byLabel = fn(string $l) => array_values(array_filter($meta, fn($c) => $c['label'] === $l))[0]['id'];
	$order = array_column($meta, 'id');
	[$order[0], $order[1]] = [$order[1], $order[0]];
	$r = $save(['settings' => json_encode(['labels' => [$byLabel('Cluster Name') => 'Client'], 'hidden' => [$byLabel('ES URL')], 'order' => $order, 'split' => ['es']])]);
	check('the dialog saves the report defaults', $r === ['ok' => true], $r);
	$file = json_decode(file_get_contents(getenv('EP_DATA_DIR').'/columns-resources.json'), true);
	check('…in the version-2 shape', $file['version'] === 2 && $file['split'] === ['es'] && $file['new_hidden'] === 1);
	$d = run();
	check('…renamed, hidden and moved, by the columns\' own ids', $d['columns'][0]['builtin'] === 'client.type' && $d['columns'][1]['name'] === 'Client'
		&& col($d, 'client.url') === null);
	check('…ES shown as its roles: the roles\' columns arrive shown, the family\'s go', col($d, 'r.es_data_hot.cpu.requested') !== null
		&& col($d, 'f.es.cpu.requested') === null && col($d, 'f.parser.cpu.requested') !== null);
	$r = $save(['settings' => json_encode(['labels' => [], 'hidden' => [], 'order' => array_column(run()['meta']['columns'], 'id'), 'split' => []])]);
	$d = run();
	check('…and back as one family again', $r === ['ok' => true] && col($d, 'f.es.cpu.requested') !== null && col($d, 'r.es_data_hot.cpu.requested') === null);

	$good = ['columns' => [['uid' => 'c1', 'name' => 'Client', 'source' => 1, 'builtin' => 'client.name'],
		['uid' => 'c2', 'group' => 'Ingest', 'name' => 'Docs/s', 'source' => 2, 'key' => 'es.ingest.rate', 'combine' => 1]], 'server_columns' => Catalogue::serverDefaults()];
	$r = $save(['columns' => json_encode($good)]);
	$d = run();
	check('a widget\'s own columns made the report defaults', $r === ['ok' => true] && array_column($d['columns'], 'name') === array_merge(['Client', 'Docs/s'],
		array_column(array_filter($d['columns'], fn($c) => !in_array($c['name'], ['Client', 'Docs/s'], true)), 'name')), array_column($d['columns'], 'name'));
	$before = file_get_contents(getenv('EP_DATA_DIR').'/columns-resources.json');
	$bad = $good;
	$bad['columns'][1]['key'] = 'bad key';
	$bad['columns'][1]['warn_color'] = 'red';
	$r = $save(['columns' => json_encode($bad)]);
	check('…checked by the column rule first: refused, every error naming the column, nothing written', $r['ok'] === false
		&& strpos($r['error'], 'Column "Ingest · Docs/s": "bad key" is not an item key') !== false && strpos($r['error'], 'Warn colour must be a colour like') !== false
		&& file_get_contents(getenv('EP_DATA_DIR').'/columns-resources.json') === $before, $r);
	check('Reset: back to the report\'s own columns', $save(['reset' => '1']) === ['ok' => true] && !is_file(getenv('EP_DATA_DIR').'/columns-resources.json'));

	foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
		unlink($dir.'/'.$f);
	}
	rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
