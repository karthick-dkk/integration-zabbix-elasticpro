<?php
/**
 * The shared column engine on its own, as shared/php has it (namespace EpShared): the one column
 * rule (Columns::clean) and what it refuses — bad keys and patterns, colours that are not
 * #RRGGBB, thresholds out of order, numbers that are not finite — calculated sources, value
 * windows, combining servers, unknown never drawn as zero; the palette (cleaning, contrast, the
 * theme it picks, defaults that are today's colours); the report defaults' two shapes and the
 * migration between them; and the donut arithmetic.
 *   docker run --rm -v "$PWD":/m -w /m php:8.3-cli php shared/test/engine.test.php
 */
namespace {
	define('ITEM_STATE_NORMAL', 0);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	if (!function_exists('_')) { function _($s) { return $s; } }
	if (!function_exists('_s')) { function _s($s, ...$a) { return vsprintf($s, $a); } }
	if (!function_exists('_n')) { function _n($one, $many, $n, ...$a) { return vsprintf($n == 1 ? $one : $many, array_merge([$n], $a)); } }
}
namespace EpShared\Test {
	foreach (['Store', 'Settings', 'ClientData', 'Forecast', 'Columns', 'ColumnMath', 'ColumnSettings'] as $f) {
		require __DIR__.'/../php/'.$f.'.php';
	}
	use EpShared\{ColumnMath, Columns, ColumnSettings, Settings};

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '', PHP_EOL;
	}
	$dir = sys_get_temp_dir().'/ep-engine-test-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);

	// A small catalogue, shaped as a widget's Includes\Catalogue makes it.
	$cat = [
		'client.name' => ['label' => 'Cluster Name', 'group' => '', 'on' => Columns::ON_CLIENT, 'field' => 'name', 'text' => true, 'units' => '', 'auto' => true],
		'storage.used' => ['label' => 'Used', 'group' => 'ES storage', 'on' => Columns::ON_MASTER, 'key' => 'ep.es.storage.used', 'units' => 'B', 'auto' => true],
		'storage.allocated' => ['label' => 'Allocated', 'group' => 'ES storage', 'on' => Columns::ON_MASTER, 'key' => 'ep.es.storage.allocated', 'units' => 'B', 'auto' => true],
		'storage.usage' => ['label' => 'Used %', 'group' => 'ES storage', 'on' => Columns::ON_MASTER, 'key' => 'ep.es.storage.usage', 'units' => '%', 'settings' => true,
			'auto' => true, 'defaults' => ['show' => Columns::SHOW_BAR]],
		'devices.seen' => ['label' => 'Seen', 'group' => 'Devices', 'on' => Columns::ON_MASTER, 'key' => 'ep.devices.seen', 'units' => '', 'pct' => 'ep.devices.usage', 'auto' => true],
		'f.es.cpu.usage' => ['label' => 'Used', 'group' => 'ES CPU (cores)', 'on' => Columns::ON_MASTER, 'key' => 'ep.es.cpu.usage', 'units' => '%', 'settings' => true, 'auto' => true],
		'r.es_hot.cpu.usage' => ['label' => 'Used', 'group' => 'ES Hot CPU (cores)', 'on' => Columns::ON_MASTER, 'key' => 'ep.role.cpu.usage[es_hot]', 'units' => '%',
			'settings' => true, 'auto' => false],
		'srv.cpu' => ['label' => 'CPU (each server)', 'group' => '', 'on' => Columns::ON_SERVERS, 'key' => 'system.cpu.util', 'units' => '%', 'settings' => true],
		'row.host' => ['label' => 'Host', 'group' => '', 'on' => Columns::ON_ROW, 'field' => 'host', 'text' => true, 'units' => '', 'auto' => true]
	];
	$roles = ['es_hot', 'parser'];
	$clean = fn(array $in, string $scope = 'client') => Columns::clean($in + ['name' => 'x', 'uid' => 'abc'], $cat, $roles, $scope);
	$errs = fn(array $in, string $scope = 'client') => $clean($in, $scope)[1];
	$has = fn(array $errors, string $needle) => (bool) array_filter($errors, fn($e) => strpos($e, $needle) !== false);

	/* ---------------- the column rule ---------------- */
	[$c, $e] = $clean(['source' => 1, 'builtin' => 'storage.usage']);
	check('a built-in column passes, with its units from the catalogue', $e === [] && $c['units'] === '%' && $c['uid'] === 'abc', $e);
	check('…and every property of the model is there', array_keys($c) === array_keys(Columns::DEFAULT));
	[$c] = Columns::clean(['name' => 'x', 'source' => 1, 'builtin' => 'storage.used'], $cat, $roles);
	check('a column with no id is given one', preg_match('/^[a-z0-9]{10}$/', $c['uid']) === 1, $c['uid']);
	check('a name is needed', $has($errs(['name' => ' ', 'source' => 1, 'builtin' => 'storage.used']), 'Name cannot be empty'));
	check('a name has a length', $has($errs(['name' => str_repeat('n', 65), 'source' => 1, 'builtin' => 'storage.used']), 'longer than 64'));
	check('a name has no control characters', $has($errs(['name' => "a\x07b", 'source' => 1, 'builtin' => 'storage.used']), 'control characters'));
	check('an unknown built-in is refused, by name', $has($errs(['source' => 1, 'builtin' => 'nope']), 'There is no built-in column "nope"'));
	check('a server-row column is not a client column', $has($errs(['source' => 1, 'builtin' => 'row.host']), 'There is no built-in column'));
	check('…nor a client column a server row\'s', $has($errs(['source' => 1, 'builtin' => 'storage.used'], 'row'), 'There is no built-in column'));
	check('a server row takes only built-in columns', $has($errs(['source' => 2, 'key' => 'a.b'], 'row'), 'not one of the choices'));
	check('a choice that is not one is named', $has($errs(['source' => 1, 'builtin' => 'storage.used', 'show' => 99]), '"show" is not one of the choices'));

	// Item keys and patterns.
	check('an empty key is refused', $has($errs(['source' => 2, 'key' => '']), 'Item key cannot be empty'));
	check('a key with a space is not a key', $has($errs(['source' => 2, 'key' => 'bad key']), 'is not an item key'));
	check('a * without "pattern" is refused', $has($errs(['source' => 2, 'key' => 'es.jvm[*]']), 'tick "Key is a pattern"'));
	check('a pattern needs three plain characters first', $has($errs(['source' => 2, 'key' => 'ab*', 'pattern' => 1]), 'at least 3 plain characters'));
	check('…and a * at all', $has($errs(['source' => 2, 'key' => 'abc', 'pattern' => 1]), 'A pattern needs a *'));
	check('a key may be at most 255 characters', $has($errs(['source' => 2, 'key' => str_repeat('a', 256)]), 'longer than 255'));
	[$c, $e] = $clean(['source' => 2, 'key' => 'es.jvm[*]', 'pattern' => 1, 'roles' => ['es_hot', 'nope', 'es_hot']]);
	check('a good pattern passes; roles it does not know are dropped, repeats too', $e === [] && $c['roles'] === ['es_hot'], [$e, $c['roles']]);
	check('the pattern caps are 50 a server and 500 a column', Columns::PATTERN_PER_HOST === 50 && Columns::PATTERN_PER_METRIC === 500 && Columns::PATTERN_PREFIX === 3);
	check('a pattern matches by its * and nothing else', Columns::keyMatches('es.jvm[heap]', 'es.jvm[*]', true) && !Columns::keyMatches('xes.jvm[heap]', 'es.jvm[*]', true)
		&& !Columns::keyMatches('es.jvm[heap]', 'es.jvm[*]', false));
	[$c] = $clean(['source' => 1, 'builtin' => 'storage.used', 'roles' => ['es_hot']]);
	check('a master-host figure has no servers to pick', $c['roles'] === []);

	// Calculated from two items.
	[$c, $e] = $clean(['source' => 3, 'calc_a' => 'storage.used', 'calc_op' => Columns::OP_OF, 'calc_b' => 'storage.allocated']);
	check('x of y passes, and is judged as a percentage (Settings allowed)', $e === [] && $c['use_settings'] === 1, $e);
	check('an operand is needed', $has($errs(['source' => 3, 'calc_a' => '', 'calc_b' => 'storage.allocated']), 'First item: choose'));
	check('an operand must be a number the catalogue has', $has($errs(['source' => 3, 'calc_a' => 'client.name', 'calc_b' => 'storage.allocated']),
		'no built-in number "client.name"'));
	check('a key operand is checked as a key', $has($errs(['source' => 3, 'calc_a' => 'key:bad key', 'calc_b' => 'storage.allocated']), 'Second item') === false
		&& $has($errs(['source' => 3, 'calc_a' => 'key:bad key', 'calc_b' => 'storage.allocated']), 'First item: "bad key" is not an item key'));
	[$c, $e] = $clean(['source' => 3, 'calc_a' => 'key:es.indexing.total', 'calc_op' => Columns::OP_MINUS, 'calc_b' => 'srv.cpu', 'roles' => ['parser']]);
	check('an operand on the servers keeps the roles; minus is not a percentage', $e === [] && $c['roles'] === ['parser'] && $c['use_settings'] === 0, $e);
	check('an operation that is not one is refused', $has($errs(['source' => 3, 'calc_a' => 'storage.used', 'calc_op' => 9, 'calc_b' => 'storage.allocated']), '"calc_op"'));

	// Colours: #RRGGBB and nothing else.
	foreach (['red', '#fff', '#12345', '#1234567', '##123456', 'rgb(1,2,3)', '12345g', '"><x'] as $bad) {
		check("the colour \"$bad\" is refused", $has($errs(['source' => 1, 'builtin' => 'storage.usage', 'warn_color' => $bad]), 'Warn colour must be a colour like'), $bad);
	}
	[$c] = $clean(['source' => 1, 'builtin' => 'storage.usage', 'warn_color' => '12ab3f', 'high_color' => '#CC0000', 'track' => ' #aabbcc ']);
	check('a colour is stored #RRGGBB, upper case, with or without the #', $c['warn_color'] === '#12AB3F' && $c['high_color'] === '#CC0000' && $c['track'] === '#AABBCC');
	check('a fixed bar colour needs a colour', $has($errs(['source' => 1, 'builtin' => 'storage.usage', 'show' => 2, 'bar_color' => 2]), 'A fixed bar colour needs a colour'));
	check('Columns::color lets nothing else into a style attribute', Columns::color('red;}') === null && Columns::color('#a1b2c3') === '#A1B2C3' && Columns::color('') === null);

	// Thresholds: order, direction, numbers that are numbers.
	$own = fn(int $worse, array $rows) => $errs(['source' => 2, 'key' => 'k', 'use_settings' => 0, 'worse' => $worse, 'thresholds' => $rows]);
	check('higher is worse: Warn above High is refused', $has($own(1, [['level' => 1, 'value' => '90'], ['level' => 2, 'value' => '80']]), 'Warn (90) must be below High (80)'));
	check('…and in order passes', $own(1, [['level' => 1, 'value' => '80'], ['level' => 2, 'value' => '90']]) === []);
	check('lower is worse: Warn below High is refused', $has($own(2, [['level' => 1, 'value' => '1000'], ['level' => 2, 'value' => '5000']]), 'when lower is worse'));
	check('…and in order passes', $own(2, [['level' => 1, 'value' => '5000'], ['level' => 2, 'value' => '1000']]) === []);
	foreach (['1e999', 'INF', 'NaN', '1e13', '', 'abc'] as $bad) {
		check("the threshold \"$bad\" is not a number to compare with", $has($own(1, [['level' => 1, 'value' => $bad]]), 'needs a number between -1e12 and 1e12'), $bad);
	}
	check('a repeated value is refused', $has($own(1, [['level' => 1, 'value' => '5'], ['level' => 2, 'value' => '5.0']]), 'repeats the value 5'));
	check('a level is Warn or High', $has($own(1, [['level' => 3, 'value' => '5']]), 'the level is Warn or High'));
	check('at most ten thresholds', $has($own(1, array_map(fn($i) => ['level' => 2, 'value' => (string) $i], range(1, 11))), 'At most 10 thresholds'));
	[$c] = $clean(['source' => 2, 'key' => 'k', 'use_settings' => 0, 'thresholds' => [['level' => 2, 'value' => '9', 'color' => 'aa0000'], ['level' => 1, 'value' => '3']]]);
	check('thresholds are kept in order, colours with their #', array_column($c['thresholds'], 'value') === ['3', '9'] && $c['thresholds'][1]['color'] === '#AA0000');
	// Settings' thresholds: only for a percentage, Warn not above High, above 0 and at most 100.
	[$c] = $clean(['source' => 1, 'builtin' => 'storage.used', 'use_settings' => 1]);
	check('a size in bytes cannot use Settings\' percentage thresholds', $c['use_settings'] === 0);
	[$c] = $clean(['source' => 1, 'builtin' => 'devices.seen', 'use_settings' => 1]);
	check('a figure with its share beside it can', $c['use_settings'] === 1);
	[$c] = $clean(['source' => 2, 'key' => 'k', 'units' => '%', 'use_settings' => 1]);
	check('an item in % can', $c['use_settings'] === 1);
	check('Settings Warn above High is refused', $has($errs(['source' => 1, 'builtin' => 'storage.usage', 'warn' => '95', 'high' => '90']), 'Warn must not be above High'));
	check('a Warn of 0 is not a threshold', $has($errs(['source' => 1, 'builtin' => 'storage.usage', 'warn' => '0']), 'above 0 and at most 100'));
	check('nor one of 101', $has($errs(['source' => 1, 'builtin' => 'storage.usage', 'high' => '101']), 'above 0 and at most 100'));
	check('nor one that is not finite', $has($errs(['source' => 1, 'builtin' => 'storage.usage', 'high' => '1e999']), 'above 0 and at most 100'));
	[$c] = $clean(['source' => 1, 'builtin' => 'storage.usage', 'worse' => 2, 'use_settings' => 1]);
	check('Settings\' thresholds are always "higher is worse"', $c['worse'] === Columns::WORSE_HIGHER);

	// Value windows, display, cell.
	check('a text column shows its value now', $has($errs(['source' => 1, 'builtin' => 'client.name', 'value' => 2]), 'A text column shows its value now'));
	check('a server row shows its value now', $has($errs(['source' => 1, 'builtin' => 'row.host', 'value' => 3], 'row'), 'A server row column shows its value now'));
	check('a window is 1 to 365 days', $has($errs(['source' => 1, 'builtin' => 'storage.used', 'value' => 2, 'window' => '366']), '1 to 365'));
	[$c] = $clean(['source' => 1, 'builtin' => 'storage.used', 'value' => Columns::VALUE_CHANGE, 'window' => '']);
	check('an empty window is the widget\'s', $c['window'] === 0 && $c['value'] === Columns::VALUE_CHANGE);
	check('a text column is a value or a badge', $has($errs(['source' => 1, 'builtin' => 'client.name', 'show' => Columns::SHOW_BAR]), 'shown as a value or a badge'));
	check('decimals are 0 to 10', $has($errs(['source' => 1, 'builtin' => 'storage.used', 'decimals' => '11']), 'Decimals must be'));
	check('stale after is 1 to 10080 minutes', $has($errs(['source' => 1, 'builtin' => 'storage.used', 'stale' => '0']), 'Stale after'));
	check('segments are 2 to 50', $has($errs(['source' => 1, 'builtin' => 'storage.usage', 'segments' => '1']), 'Segments must be 2 to 50'));
	check('a width is auto or 30 to 800 px', $has($errs(['source' => 1, 'builtin' => 'storage.used', 'width' => '20']), 'Width must be auto'));
	[$c] = $clean(['source' => 1, 'builtin' => 'storage.used', 'width' => 'AUTO', 'min' => '0', 'max' => '1e3']);
	check('"auto" is no width; a range is kept as plain numbers', $c['width'] === '' && $c['min'] === '0' && $c['max'] === '1000');
	check('a bar range runs low to high', $has($errs(['source' => 1, 'builtin' => 'storage.used', 'min' => '5', 'max' => '5']), 'from a lower number to a higher one'));
	check('a bar range number must be finite', $has($errs(['source' => 1, 'builtin' => 'storage.used', 'max' => '1e999']), 'Bar range to must be a number'));

	// Every error names the column.
	check('an error names the column by group and name', Columns::named(['Boom.'], 'Used', 2, 'ES storage') === ['Column "ES storage · Used": Boom.']);
	check('…or by its place, or as a new one', Columns::named(['x'], '', 3)[0] === 'Column "#3": x' && Columns::named(['x'], '')[0] === 'Column "New column": x');
	[$list, $e] = Columns::cleanList([['name' => 'ok', 'source' => 1, 'builtin' => 'storage.used'], ['name' => 'Bad', 'group' => 'G', 'source' => 2, 'key' => 'x y']], $cat, $roles, true);
	check('strict: every error, named', count($e) === 1 && strpos($e[0], 'Column "G · Bad"') === 0, $e);
	[$list, $e, $dropped] = Columns::cleanList([['name' => 'ok', 'source' => 1, 'builtin' => 'storage.used'], ['name' => 'Bad', 'source' => 2, 'key' => 'x y']], $cat, $roles, false);
	check('lenient (a saved widget drawn): the bad one left out, and said', count($list) === 1 && $e === [] && count($dropped) === 1 && strpos($dropped[0], 'Column "Bad"') === 0);
	[$list] = Columns::cleanList([['uid' => 'same', 'name' => 'a', 'source' => 1, 'builtin' => 'storage.used'], ['uid' => 'same', 'name' => 'b', 'source' => 1, 'builtin' => 'storage.used']], $cat, $roles, false);
	check('a repeated id is given a fresh one', $list[0]['uid'] === 'same' && $list[1]['uid'] !== 'same');
	[, $e] = Columns::cleanList(array_fill(0, Columns::MAX + 1, ['name' => 'a', 'source' => 1, 'builtin' => 'storage.used']), $cat, $roles, true);
	check('too many columns is refused on Apply', $has($e, 'at most 120 columns'), $e);
	$g = Columns::groupTogether([['uid' => 'a', 'group' => 'X'], ['uid' => 'b', 'group' => ''], ['uid' => 'c', 'group' => 'X'], ['uid' => 'd', 'group' => 'Y']]);
	check('a group\'s columns stay together, where its first one is', array_column($g, 'uid') === ['a', 'c', 'b', 'd']);

	/* ---------------- the arithmetic ---------------- */
	check('combine: sum, average, highest, lowest', ColumnMath::combine([1, 2, 6], Columns::COMBINE_SUM) === 9.0 && ColumnMath::combine([1, 2, 6], Columns::COMBINE_AVG) === 3.0
		&& ColumnMath::combine([1, 2, 6], Columns::COMBINE_MAX) === 6.0 && ColumnMath::combine([1, 2, 6], Columns::COMBINE_MIN) === 1.0);
	check('combine: only known values; nothing known is null, not 0', ColumnMath::combine([null, 4], Columns::COMBINE_SUM) === 4.0
		&& ColumnMath::combine([], Columns::COMBINE_SUM) === null && ColumnMath::combine([null, null], Columns::COMBINE_AVG) === null);
	check('combine: count over Warn', ColumnMath::combine([10, 85, 95], Columns::COMBINE_COUNT, fn($v) => $v >= 80) === 2.0);
	check('per server first: its worst item', ColumnMath::perHost([[1, 70.0], [1, 20.0], [2, null]], false) === [1 => 70.0, 2 => null]);
	$lv = ColumnMath::level(85.0, ['use_settings' => 1, 'warn_color' => '#123456'], ['warn' => 80, 'high' => 90]);
	check('Settings levels, with a column\'s own Warn colour', $lv === ['level' => 'warn', 'color' => '#123456'], $lv);
	check('…and the palette\'s when it has none', ColumnMath::level(95.0, ['use_settings' => 1], ['warn' => 80, 'high' => 90]) === ['level' => 'high', 'color' => null]);
	check('own thresholds: colour from the threshold crossed', ColumnMath::level(9.0, ['use_settings' => 0, 'worse' => 1,
		'thresholds' => [['level' => 1, 'value' => '3', 'color' => ''], ['level' => 2, 'value' => '8', 'color' => '#AA0000']]], [])['color'] === '#AA0000');
	check('an unknown value has no level', ColumnMath::level(null, ['use_settings' => 1], ['warn' => 80, 'high' => 90])['level'] === '');
	$t = ColumnMath::trend(['a' => [100 => [10, 5, 20], 200 => [30, 25, 40]], 'b' => [100 => [1, 0, 2], 200 => [3, 2, 50]]], Columns::COMBINE_SUM, 0, 1000);
	check('a window: average, minimum, maximum of the combined hours', ColumnMath::windowValue($t, Columns::VALUE_AVG) === 22.0
		&& ColumnMath::windowValue($t, Columns::VALUE_MIN) === 11.0 && ColumnMath::windowValue($t, Columns::VALUE_MAX) === 33.0, $t);
	check('change: the last combined hour less the first', ColumnMath::windowValue($t, Columns::VALUE_CHANGE) === 22.0);
	check('change needs two points; no trend is no figure, not 0', ColumnMath::windowValue(['series' => [5 => 1.0], 'avg' => 1.0, 'min' => 1.0, 'max' => 1.0], Columns::VALUE_CHANGE) === null
		&& ColumnMath::windowValue(null, Columns::VALUE_AVG) === null);
	check('history in one-minute buckets, combined across servers', ColumnMath::trend(['a' => ColumnMath::bucket([[60, 10], [125, 20]]),
		'b' => ColumnMath::bucket([[75, 1], [170, 2]])], Columns::COMBINE_SUM, 0, 1000)['series'] === [60 => 11.0, 120 => 22.0]);
	check('a size in the unit the column fixes', ColumnMath::formatColumn(1.5 * 1099511627776, 'B', 2, false, Columns::SIZE_TB) === '1.5 TB'
		&& ColumnMath::formatColumn(1.5 * 1099511627776, 'B', 0, false, Columns::SIZE_GB) === '1536 GB'
		&& ColumnMath::formatColumn(1048576.0, 'B', 0, false, Columns::SIZE_MB) === '1 MB');
	check('auto picks the unit; short numbers; a change has its sign', ColumnMath::formatColumn(2147483648.0, 'B', 2, false, Columns::SIZE_AUTO) === '2 GB'
		&& ColumnMath::formatColumn(18400.0, '/s', 1, true, 0) === '18.4k/s' && ColumnMath::formatColumn(3.0, '%', 0, false, 0, true) === '+3%'
		&& ColumnMath::formatColumn(null, '%', 0, false, 0) === '—');
	check('a bar: a percentage runs 0 to 100', ColumnMath::barShare(62.0, '%', '', '') === 62.0 && ColumnMath::barShare(140.0, '%', '', '') === 100.0);
	check('…a range of the column\'s own', ColumnMath::barShare(15.0, '', '10', '20') === 50.0);
	check('…no top: the highest seen; unknown draws no bar', ColumnMath::barShare(5.0, '', '', '', 10.0) === 50.0 && ColumnMath::barShare(null, '%', '', '') === null
		&& ColumnMath::barShare(5.0, '', '', '') === null);
	check('links go only to Zabbix pages, encoded', ColumnMath::clickUrl(Columns::CLICK_GRAPH, ['7'], ['1']) === 'history.php?action=showgraph&itemids%5B%5D=7'
		&& strpos(ColumnMath::clickUrl(Columns::CLICK_GRAPH, ['7', '8'], ['1']), 'history.php?action=batchgraph') === 0
		&& strpos(ColumnMath::clickUrl(Columns::CLICK_GRAPH, range(1, 31), ['1']), 'zabbix.php?action=latest.view') === 0
		&& ColumnMath::clickUrl(Columns::CLICK_DASHBOARD, [], [], '101') === 'zabbix.php?action=host.dashboard.view&hostid=101'
		&& ColumnMath::clickUrl(Columns::CLICK_NONE, ['7'], ['1']) === null);

	/* ---------------- donuts ---------------- */
	$p = ColumnMath::pie(2870.0, 3500.0, 4000.0);
	check('used, free and short around the ring', $p['known'] && $p['used'] === 2870.0 && $p['free'] === 630.0 && $p['short'] === 500.0
		&& abs($p['segments']['used'] - 71.75) < 1e-9 && abs($p['segments']['free'] - 15.75) < 1e-9 && abs($p['segments']['short'] - 12.5) < 1e-9, $p);
	check('the shares make the whole ring', abs(array_sum($p['segments']) - 100) < 1e-9);
	check('used of allocated and of requested', round($p['pct_allocated']) === 82.0 && abs($p['pct_requested'] - 71.75) < 1e-9);
	$p = ColumnMath::pie(1310.0, null, 2000.0);
	check('allocated not set is no donut, and says why', !$p['known'] && $p['why'] === 'allocated' && $p['segments'] === [] && $p['pct_allocated'] === null);
	check('allocated 0 is not set either', ColumnMath::pie(10.0, 0.0, 20.0)['why'] === 'allocated');
	check('used not measured is no donut', ColumnMath::pie(null, 100.0, 100.0)['why'] === 'used');
	$p = ColumnMath::pie(50.0, 100.0, null);
	check('requested not entered: no short, nothing to be a share of', $p['short'] === 0.0 && $p['requested'] === null && $p['pct_requested'] === null);
	$p = ColumnMath::pie(120.0, 100.0, 100.0);
	check('used past allocated: no free, the over-use counted', $p['free'] === 0.0 && $p['over'] === 20.0 && $p['segments']['used'] === 100.0);
	$p = ColumnMath::pie(50.0, 100.0, 200.0, false);
	check('short hidden is out of the ring but still known', $p['segments']['short'] === 0.0 && $p['short'] === 100.0 && $p['segments']['used'] === 50.0);

	/* ---------------- the palette ---------------- */
	check('with nothing saved, the palette is the shipped one', Settings::clean([]) === Settings::DEFAULTS && isset(Settings::DEFAULTS['palette']));
	// Today's colours: the panel sheet's two theme blocks.
	$css = file_get_contents(__DIR__.'/../ep-panel.css');
	$block = function (string $start) use ($css) { $at = strpos($css, $start); return substr($css, $at, strpos($css, '}', $at) - $at); };
	$var = function (string $block, string $name) { preg_match('/--ep-'.$name.':\s*(#[0-9a-f]{6})/i', $block, $m); return strtolower($m[1] ?? ''); };
	$light = $block(".ep-panel {\n\t--ep-ok");
	$dark = $block('[theme="dark-theme"] .ep-panel');
	$map = ['ok' => 'ok', 'warn' => 'warn', 'high' => 'high', 'unknown' => 'faint', 'short' => 'short', 'free' => 'track', 'down' => 'down', 'stale' => 'stale', 'link' => 'link'];
	foreach ($map as $k => $v) {
		check("the shipped $k (Blue) is the colour the panel uses today", Settings::DEFAULTS['palette']['light'][$k] === $var($light, $v), [$k, $var($light, $v)]);
		check("the shipped $k (Dark) is the colour the panel uses today", Settings::DEFAULTS['palette']['dark'][$k] === $var($dark, $v), [$k, $var($dark, $v)]);
	}
	foreach (Settings::DEFAULTS['palette']['series'] as $i => $c) {
		check('series '.($i + 1).' is today\'s', $var($light, 's'.($i + 1)) === $c && $var($dark, 's'.($i + 1)) === $c);
	}
	check('the overview\'s donut-by-role colours are the series', strpos(file_get_contents(__DIR__.'/../../overview-widget/assets/js/ep-overview.js'),
		"['".implode("', '", Settings::DEFAULTS['palette']['series'])."']") !== false);
	$p = Settings::cleanPalette(['light' => ['ok' => ' #ABCDEF ', 'warn' => 'red', 'high' => '#fff', 'unknown' => '12345g', 'short' => '#123456;}', 'link' => '1f62e1'],
		'dark' => ['ok' => null], 'series' => ['#000000', 'blue']]);
	check('a colour is six hex digits, kept lower case with its #', $p['light']['ok'] === '#abcdef' && $p['light']['link'] === '#1f62e1');
	check('anything else is the shipped colour for that place', $p['light']['warn'] === Settings::DEFAULTS['palette']['light']['warn']
		&& $p['light']['high'] === Settings::DEFAULTS['palette']['light']['high'] && $p['light']['unknown'] === Settings::DEFAULTS['palette']['light']['unknown']
		&& $p['light']['short'] === Settings::DEFAULTS['palette']['light']['short']);
	check('one theme\'s colour never fills in for the other\'s', $p['dark']['ok'] === Settings::DEFAULTS['palette']['dark']['ok']);
	check('series: the good kept, the bad shipped, eight of them', $p['series'][0] === '#000000' && $p['series'][1] === Settings::DEFAULTS['palette']['series'][1]
		&& count($p['series']) === 8);
	$e = Settings::paletteErrors(['light' => ['ok' => '#00ff00', 'warn' => 'red', 'high' => ''], 'series' => ['#000000', 'nope']], ['warn' => 'Warn']);
	check('the page is told which colours were not kept, and only those', count($e) === 2 && strpos($e[0], 'Warn, Blue theme: "red"') === 0
		&& strpos($e[1], 'Chart series 2') === 0, $e);
	check('contrast: black on white is 21:1, a colour on itself 1:1', abs(Settings::contrast('#000000', '#ffffff') - 21) < 0.01 && abs(Settings::contrast('#777777', '#777777') - 1) < 1e-9);
	$t = Settings::textOn('#1f62e0');
	check('a dark blue carries white text, unflagged', $t['color'] === '#ffffff' && !$t['flagged'] && $t['ratio'] >= 4.5, $t);
	$t = Settings::textOn('#e5860b');
	check('an orange is flagged below 4.5:1 and gets dark text', $t['flagged'] && $t['color'] === Settings::DARK_TEXT && $t['white'] < 4.5 && $t['ratio'] >= 4.5, $t);
	$t = Settings::textOn('#e0314a');
	check('a mid red is flagged, and keeps white when dark text reads worse', $t['flagged'] && $t['color'] === '#ffffff', $t);
	check('the theme: dark under Dark and high-contrast dark, light otherwise', Settings::themeOf('dark-theme') === 'dark' && Settings::themeOf('hc-dark') === 'dark'
		&& Settings::themeOf('blue-theme') === 'light' && Settings::themeOf('hc-light') === 'light' && Settings::themeOf(null) === 'light');
	$vars = Settings::paletteVars(Settings::DEFAULTS['palette'], 'dark');
	check('the dark theme gets the dark colours as custom properties', strpos($vars, '--ep-ok:'.Settings::DEFAULTS['palette']['dark']['ok']) !== false
		&& strpos($vars, '--ep-faint:'.Settings::DEFAULTS['palette']['dark']['unknown']) !== false && strpos($vars, '--ep-s8:') !== false
		&& strpos($vars, '--ep-warn-fg:') !== false, $vars);
	check('…the light theme the light ones', strpos(Settings::paletteVars(Settings::DEFAULTS['palette'], 'light'), '--ep-ok:'.Settings::DEFAULTS['palette']['light']['ok']) !== false);
	$vars = Settings::paletteVars(['light' => ['ok' => 'red;background:url(x)']], 'light');
	check('nothing but a colour reaches the style attribute', strpos($vars, 'url(') === false && strpos($vars, 'red') === false);
	check('without Zabbix, the light colours', Settings::paletteStyle(Settings::DEFAULTS) === Settings::paletteVars(Settings::DEFAULTS['palette'], 'light'));

	/* ---------------- report defaults: the two shapes ---------------- */
	$all = [];
	foreach (array_filter($cat, fn($e) => !empty($e['auto']) && $e['on'] !== Columns::ON_ROW) as $id => $e) {
		$all[] = ['id' => $id, 'label' => $e['label']];
	}
	$clientCat = array_filter($cat, fn($e) => $e['on'] !== Columns::ON_ROW);
	check('no file: the catalogue\'s own columns, shown, in its order',
		array_column(ColumnSettings::resolve(null, $clientCat), 'builtin') === array_column($all, 'id')
		&& !array_filter(array_column(ColumnSettings::resolve(null, $clientCat), 'hidden')));
	check('a built-in column keeps the same id every time', ColumnSettings::resolve(null, $clientCat)[1]['uid'] === ColumnSettings::builtinUid('storage.used')
		&& preg_match('/^[a-z0-9]{1,16}$/', ColumnSettings::builtinUid('storage.used')) === 1);
	check('…and the look the catalogue gives it', ColumnSettings::resolve(null, $clientCat)[3]['show'] === Columns::SHOW_BAR);
	// A version-1 file, as every release before this one wrote it.
	$v1 = ['labels' => ['client.name' => 'Client', 'storage.used' => 'Used now'], 'hidden' => ['devices.seen'],
		'order' => ['storage.used', 'client.name', 'storage.allocated', 'storage.usage', 'devices.seen'], 'split' => ['es']];
	file_put_contents($dir.'/columns-resources.json', json_encode($v1));
	$model = ColumnSettings::loadModel('resources');
	check('a version-1 file is read, not rewritten', $model['columns'] === null && $model['legacy']['labels'] === $v1['labels'] && $model['split'] === ['es']
		&& json_decode(file_get_contents($dir.'/columns-resources.json'), true) === $v1);
	$migrated = ColumnSettings::resolve($model['columns'], $clientCat, $model['legacy'], $model['new_hidden']);
	$old = ColumnSettings::apply($all, ColumnSettings::load('resources'), true);
	check('migrated: the order version 1 drew', array_column($migrated, 'builtin') === array_column($old, 'id'), array_column($migrated, 'builtin'));
	check('migrated: the names', array_column($migrated, 'name') === array_column($old, 'label'));
	check('migrated: the hidden columns', array_map(fn($c) => (bool) $c['hidden'], $migrated) === array_column($old, 'hidden'));
	check('migrated: a column version 1 never saw is shown, as version 1 showed it', $migrated[array_search('f.es.cpu.usage', array_column($migrated, 'builtin'))]['hidden'] === 0);
	check('migrated columns pass the column rule', Columns::cleanList($migrated, $clientCat, $roles, true)[1] === []);

	// Version 2.
	$saved = ColumnSettings::saveModel('resources', array_slice($migrated, 0, 3), null, ['es']);
	$model = ColumnSettings::loadModel('resources');
	check('version 2 reads back what was saved', $model['columns'] === array_values(array_slice($migrated, 0, 3)) && $model['new_hidden'] === true && $model['legacy'] === null);
	$resolved = ColumnSettings::resolve($model['columns'], $clientCat, null, $model['new_hidden']);
	$added = array_values(array_filter($resolved, fn($c) => !in_array($c['builtin'], array_column(array_slice($migrated, 0, 3), 'builtin'), true)));
	check('a role added since arrives unticked, after the column it follows', $added && !array_filter($added, fn($c) => !$c['hidden'])
		&& array_search('storage.usage', array_column($resolved, 'builtin')) === array_search('storage.allocated', array_column($resolved, 'builtin')) + 1,
		array_column($resolved, 'builtin'));
	check('a column that is not auto is never added on its own', !in_array('r.es_hot.cpu.usage', array_column($resolved, 'builtin'), true)
		&& !in_array('srv.cpu', array_column($resolved, 'builtin'), true));
	check('a version-1 reader of a version-2 file gets its split and no stale names', ColumnSettings::load('resources') === ['labels' => [], 'hidden' => [], 'order' => [], 'split' => ['es']]);
	// The Columns dialog on version 2: names, shown, order by uid.
	$uids = array_column($resolved, 'uid');
	$d = ColumnSettings::applyDialog($resolved, ['labels' => [$uids[1] => '  Renamed  ', $uids[0] => ''], 'hidden' => [$uids[0]], 'order' => [$uids[2], $uids[0]]], $clientCat);
	check('dialog: order first, the rest after', array_column($d, 'uid')[0] === $uids[2] && array_column($d, 'uid')[1] === $uids[0]);
	check('dialog: a name trimmed; an empty one back to the catalogue\'s', $d[array_search($uids[1], array_column($d, 'uid'))]['name'] === 'Renamed'
		&& $d[1]['name'] === $clientCat[$d[1]['builtin']]['label']);
	check('dialog: shown and hidden', $d[1]['hidden'] === 1 && $d[0]['hidden'] === 0);
	$digits = [array_merge($resolved[0], ['uid' => '1234567890']), $resolved[1]];
	$d = ColumnSettings::applyDialog($digits, ['labels' => [], 'hidden' => [], 'order' => [$resolved[1]['uid'], '1234567890']], $clientCat);
	check('dialog: a uid of digits alone is one column, not two', array_column($d, 'uid') === [$resolved[1]['uid'], '1234567890'], array_column($d, 'uid'));
	// Families as their roles: the columns swap.
	$before = $clientCat;
	$after = $clientCat;
	$after['f.es.cpu.usage']['auto'] = false;
	$after['r.es_hot.cpu.usage']['auto'] = true;
	$swapped = ColumnSettings::resplit(ColumnSettings::resolve(null, $before), $before, $after);
	$ids = array_column($swapped, 'builtin');
	check('split: the family\'s columns go, the roles\' arrive shown', !in_array('f.es.cpu.usage', $ids, true) && in_array('r.es_hot.cpu.usage', $ids, true)
		&& $swapped[array_search('r.es_hot.cpu.usage', $ids)]['hidden'] === 0, $ids);
	ColumnSettings::reset('resources');
	check('reset: no file, the catalogue\'s own columns again', ColumnSettings::loadModel('resources')['columns'] === null
		&& ColumnSettings::loadModel('resources')['legacy'] === null);

	foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
		unlink($dir.'/'.$f);
	}
	rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
