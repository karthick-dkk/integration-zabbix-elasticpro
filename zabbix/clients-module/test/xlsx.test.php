<?php
/**
 * clients.xlsx: the ZIP reader and writer, the XLSX reader and writer, and the workbook the Import
 * page offers — built, read back, and read as another tool (openpyxl) wrote it.
 *   docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php test/xlsx.test.php
 *   EP_FIXTURE_OUT=test/fixtures … also writes template.xlsx and export.xlsx there, for
 *   test/fixtures/make_fixture.py, which writes the openpyxl-*.xlsx files read below.
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
 *
 * php:8.4-cli-alpine has no zip extension, and the first check says so: none of this needs it.
 */
namespace {
	define('ZBX_MACRO_TYPE_TEXT', 0); define('ZBX_MACRO_TYPE_SECRET', 1); define('ZBX_MACRO_TYPE_VAULT', 2);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	if (!function_exists('_')) { function _($s) { return $s; } }
	if (!function_exists('_s')) { function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace('%'.($i + 1).'$s', (string) $v, $s); } return $s; } }
}
namespace Modules\EpClients\Test {
	foreach (['Store', 'Settings', 'Roles', 'ClientTypes', 'MasterTemplate', 'DevicesTemplate', 'JumpTemplate', 'ClusterTemplate', 'ClientSpec', 'Csv', 'Zip', 'Xlsx', 'Workbook'] as $lib) {
		require __DIR__.'/../lib/'.$lib.'.php';
	}
	use Modules\EpClients\Lib\{ClientSpec, Csv, Roles, Workbook, Xlsx, Zip};
	use RuntimeException;

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo 'FAIL: ', $what, $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '', PHP_EOL;
	}
	/** The message of what $fn throws, or '' when it does not. */
	function refusal(callable $fn): string {
		try { $fn(); } catch (RuntimeException $e) { return $e->getMessage(); }
		return '';
	}
	$dir = sys_get_temp_dir().'/ep-xlsx-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);
	$fixtures = __DIR__.'/fixtures';

	// php:8.4-cli-alpine has no zip extension, so a run there proves nothing below needs it; a PHP
	// that has it is told so, and the code is checked for never calling it.
	if (extension_loaded('zip')) {
		echo 'note: this PHP has the zip extension; run in php:8.4-cli-alpine to prove it is not needed', PHP_EOL;
	}
	foreach (['Zip', 'Xlsx', 'Workbook'] as $lib) {
		check("lib/$lib.php never uses the zip extension", !preg_match('/ZipArchive|\bzip_(open|read|entry)/', file_get_contents(__DIR__.'/../lib/'.$lib.'.php')));
	}

	/* ---------------- ZIP ---------------- */
	$entries = ['a.txt' => 'hello', 'empty.bin' => '', 'dir/b.xml' => str_repeat('<row r="1"/>', 5000), 'bin' => random_bytes(3000), 'ü.txt' => 'utf-8 name'];
	$zip = Zip::build($entries);
	check('zip round trip: every entry comes back byte for byte', Zip::read($zip) === $entries);
	// Stored entries, and deflated ones with bit 3 set and the sizes after the data — how Excel,
	// LibreOffice and openpyxl may write it. Only the central directory has the sizes then.
	$stored = function (array $files) {
		$out = ''; $cd = '';
		foreach ($files as $name => $data) {
			$crc = crc32($data); $off = strlen($out);
			$out .= "PK\x03\x04".pack('vvvvvVVVvv', 20, 0, 0, 0, 0x5000, $crc, strlen($data), strlen($data), strlen($name), 0).$name.$data;
			$cd .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0x5000, $crc, strlen($data), strlen($data), strlen($name), 0, 0, 0, 0, 0, $off).$name;
		}
		return $out.$cd."PK\x05\x06".pack('vvvvVVv', 0, 0, count($files), count($files), strlen($cd), strlen($out), 0);
	};
	// Rebuild properly: the extra field goes after the name.
	$sd = (function () {
		$files = ['x.xml' => '<a/>', 'y.xml' => 'yy'];
		$out = ''; $cd = '';
		foreach ($files as $name => $data) {
			$crc = crc32($data); $off = strlen($out);
			$packed = gzdeflate($data);
			$out .= "PK\x03\x04".pack('vvvvvVVVvv', 20, 0x0008, 8, 0, 0x5000, 0, 0, 0, strlen($name), 4).$name."\x01\x00\x00\x00".$packed
				."PK\x07\x08".pack('VVV', $crc, strlen($packed), strlen($data));
			$cd .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0008, 8, 0, 0x5000, $crc, strlen($packed), strlen($data), strlen($name), 0, 0, 0, 0, 0, $off).$name;
		}
		return $out.$cd."PK\x05\x06".pack('vvvvVVv', 0, 0, 2, 2, strlen($cd), strlen($out), 0);
	})();
	check('deflated entries with bit 3, zero sizes locally and a local extra field are read', Zip::read($sd) === ['x.xml' => '<a/>', 'y.xml' => 'yy'], refusal(fn() => Zip::read($sd)));
	$plain = $stored(['s.txt' => 'stored']);
	check('a stored entry reads', Zip::read($plain) === ['s.txt' => 'stored'], refusal(fn() => Zip::read($plain)));
	check('a file that is not a ZIP is refused in words', str_contains(refusal(fn() => Zip::read('client,ip\nacme,1.2.3.4')), 'not a ZIP'));
	check('a truncated ZIP is refused', refusal(fn() => Zip::read(substr($zip, 0, 200))) !== '');
	foreach (['../evil.xml', '/etc/passwd', 'a\\..\\b', 'C:/x', 'xl/../../x'] as $bad) {
		$z = Zip::build([$bad => 'x']);
		check("zip-slip: \"$bad\" is refused", str_contains(refusal(fn() => Zip::read($z)), 'climbs out'), refusal(fn() => Zip::read($z)));
	}
	check('a name with .. inside a word is fine', Zip::read(Zip::build(['a..b/c.xml' => 'x'])) === ['a..b/c.xml' => 'x']);
	$bomb = Zip::build(['xl/worksheets/sheet1.xml' => str_repeat("\0", 3 * 1024 * 1024)]);
	check('a bomb is refused before it is unpacked: the declared total is over the cap', strlen($bomb) < 20000
		&& str_contains(refusal(fn() => Zip::read($bomb, 1024 * 1024)), 'more than 1 MB'), [strlen($bomb), refusal(fn() => Zip::read($bomb, 1024 * 1024))]);
	check('the shipped cap is 50 MB and 2000 entries', Zip::MAX_BYTES === 50 * 1024 * 1024 && Zip::MAX_ENTRIES === 2000);
	// A directory that understates an entry's size: inflating stops at the declared size.
	$liar = Zip::build(['a.xml' => str_repeat('A', 100000)]);
	$cdAt = strpos($liar, "PK\x01\x02");
	$liar = substr_replace($liar, pack('V', 10), $cdAt + 24, 4);
	check('an entry that unpacks to more than it says is refused', str_contains(refusal(fn() => Zip::read($liar)), 'more than it says'), refusal(fn() => Zip::read($liar)));
	check('too many entries are refused', str_contains(refusal(fn() => Zip::read(Zip::build(['a' => '1', 'b' => '2', 'c' => '3']), Zip::MAX_BYTES, 2)), 'at most 2'));
	$enc = $zip;
	$enc = substr_replace($enc, pack('v', 0x0001), strpos($enc, "PK\x01\x02") + 8, 2);
	check('an encrypted workbook is refused, saying so', str_contains(refusal(fn() => Zip::read($enc)), 'password-protected'));
	$z64 = substr_replace($zip, pack('v', 0xFFFF), strrpos($zip, "PK\x05\x06") + 10, 2);
	check('ZIP64 is refused, saying so', str_contains(refusal(fn() => Zip::read($z64)), 'ZIP64'), refusal(fn() => Zip::read($z64)));
	$crcBad = Zip::build(['a.txt' => 'hello']);
	$crcBad = substr_replace($crcBad, pack('V', 12345), strpos($crcBad, "PK\x01\x02") + 16, 4);
	check('a check sum that does not match is refused', str_contains(refusal(fn() => Zip::read($crcBad)), 'check sum'));

	/* ---------------- XLSX ---------------- */
	$book = Xlsx::build([
		['name' => 'One', 'rows' => [1 => [1 => 'Name', 2 => 'When', 3 => 'N', 4 => 'Flag'], 2 => [1 => 'x', 2 => ['v' => '2027-03-31', 'style' => 'date'], 3 => 12.5, 4 => true],
			4 => [3 => 7, 1 => '=HYPERLINK("http://evil")', 5 => '  spaced  '], 5 => [2 => '']], 'freeze' => 'A2'],
		['name' => 'Two', 'rows' => [1 => [27 => 'AA']]]
	]);
	$read = Xlsx::read($book);
	check('xlsx round trip: sheets in order', array_keys($read) === ['One', 'Two']);
	check('text, dates, numbers and booleans come back', $read['One'][2] === [1 => 'x', 2 => '2027-03-31', 3 => '12.5', 4 => 'TRUE'], $read['One'][2]);
	check('gaps in rows and columns are kept; empty cells and rows left out', $read['One'][4] === [1 => '=HYPERLINK("http://evil")', 3 => '7', 5 => 'spaced']
		&& !isset($read['One'][3]) && !isset($read['One'][5]), $read['One']);
	check('column AA', $read['Two'][1] === [27 => 'AA']);
	$files = Zip::read($book);
	check('nothing is ever written as a formula', !preg_match('/<f[ >]/', implode('', $files)));
	check('a cell that starts like a formula is a string with quotePrefix', preg_match('/<c r="A4" s="5" t="s">/', $files['xl/worksheets/sheet1.xml']) === 1
		&& str_contains($files['xl/styles.xml'], 'quotePrefix="1"'));
	check('column letters', Xlsx::col(1) === 'A' && Xlsx::col(26) === 'Z' && Xlsx::col(27) === 'AA' && Xlsx::col(703) === 'AAA' && Xlsx::colNumber('AAA') === 703);
	check('date serials both ways', Xlsx::serial('2027-03-31') === 46477.0 && Xlsx::fromSerial(46477) === '2027-03-31' && Xlsx::fromSerial(46477, true) === '2031-04-01');
	check('number text: 20000.0 → 20000, float noise trimmed', Xlsx::numberText('20000.0') === '20000' && Xlsx::numberText('12.300000000000001') === '12.3'
		&& Xlsx::numberText('1E-3') === '0.001');
	check('date formats are told from number formats', Xlsx::isDateFormat(14, null) && Xlsx::isDateFormat(164, 'dd/mm/yyyy') && Xlsx::isDateFormat(165, '[$-409]d-mmm-yy;@')
		&& !Xlsx::isDateFormat(164, '0.00') && !Xlsx::isDateFormat(164, '#,##0 "days"') && !Xlsx::isDateFormat(0, 'General'));

	// XXE: a DOCTYPE with an entity — internal or external — is refused before parsing.
	$evil = $files;
	$evil['xl/sharedStrings.xml'] = '<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY x SYSTEM "file:///etc/passwd">]><sst xmlns="'.Xlsx::NS_MAIN.'"><si><t>&x;</t></si></sst>';
	$msg = refusal(fn() => Xlsx::read(Zip::build($evil)));
	check('XXE: an external entity in sharedStrings is refused', str_contains($msg, 'DOCTYPE') && !str_contains($msg, 'root:'), $msg);
	$evil = $files;
	$evil['xl/worksheets/sheet1.xml'] = '<?xml version="1.0"?><!DOCTYPE w [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;">]><worksheet xmlns="'.Xlsx::NS_MAIN.'"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&b;</t></is></c></row></sheetData></worksheet>';
	check('XXE: an entity expansion in a sheet is refused', str_contains(refusal(fn() => Xlsx::read(Zip::build($evil))), 'DOCTYPE'));
	$evil = $files;
	$evil['xl/worksheets/sheet1.xml'] = '<worksheet xmlns="'.Xlsx::NS_MAIN.'"><sheetData><row r="1"><c r="A1"';
	check('broken XML is refused in words, with no warning', str_contains(refusal(fn() => Xlsx::read(Zip::build($evil))), 'not valid XML'));
	$evil = $files;
	unset($evil['xl/workbook.xml']);
	check('a ZIP with no workbook is refused', str_contains(refusal(fn() => Xlsx::read(Zip::build($evil))), 'not an Excel workbook'));
	// Inline strings, rich text, cells without references, a phonetic run — as other writers do.
	$odd = $files;
	$odd['xl/worksheets/sheet2.xml'] = '<worksheet xmlns="'.Xlsx::NS_MAIN.'"><sheetData><row><c t="inlineStr"><is><r><t>Ri</t></r><r><t>ch</t></r><rPh><t>x</t></rPh></is></c><c><v>3</v></c><c t="e"><v>#N/A</v></c><c t="str"><f>A1</f><v>Rich</v></c><c t="d"><v>2026-12-31T00:00:00Z</v></c></row></sheetData></worksheet>';
	check('inline rich text, implicit references, errors, formula results and ISO dates', Xlsx::read(Zip::build($odd))['Two'] === [1 => [1 => 'Rich', 2 => '3', 4 => 'Rich', 5 => '2026-12-31']],
		Xlsx::read(Zip::build($odd))['Two']);

	/* ---------------- the workbook ---------------- */
	$roles = Roles::defaults();
	$spec = new ClientSpec($roles);
	$lists = ['types' => ['CI', 'DI', 'On-Prem'], 'proxies' => ['proxy-mumbai-01', 'proxy-dc2'], 'proxy_groups' => ['dc-group']];
	$template = Workbook::build($roles, $lists);
	$t = Xlsx::read($template);
	check('the template has Clients, Servers and Sample', array_keys($t) === ['Clients', 'Servers', 'Sample']);
	$clientHeaders = array_values(array_filter($t['Clients'][1], fn($h) => strpos($h, Workbook::CHOICES) !== 0));
	check('Clients headers, in the designed order', array_slice($clientHeaders, 0, 31) === ['Client *', 'Type ▾', 'Purchased by ▾', 'Purchased', 'SOC / Manager lead', 'Cluster DL',
		'Alert DL ▾', 'Weekly report ▾', 'Contract ends', 'ES URL *', 'ES user', 'Password in ▾', 'Vault path', 'API key Vault path', 'Monitored by ▾', 'Jump host', 'Jump port',
		'Jump user', 'Jump key', 'Jump TLS ▾', 'Jump CA', 'S3 bucket', 'S3 region', 'S3 access ▾', 'S3 role ARN', 'S3 external ID', 'S3 access key ID', 'S3 secret Vault path',
		'Raw folder', 'Enriched folder', 'Agent port'], array_slice($clientHeaders, 0, 31));
	check('then five capacity columns per role', count($clientHeaders) === 31 + 5 * count(Roles::allRoles($roles))
		&& array_slice($clientHeaders, 31, 5) === ['ES Data · Servers', 'ES Data · CPU cores', 'ES Data · Memory GB', 'ES Data · Disk / GB', 'ES Data · Other disks'], array_slice($clientHeaders, 31, 5));
	check('Servers headers', $t['Servers'][1] === [1 => 'Client ▾ *', 2 => 'IP *', 3 => 'Host name', 4 => 'Role 1 ▾ *', 5 => 'Role 2 ▾', 6 => 'Role 3 ▾', 7 => 'Role 4 ▾', 8 => 'Services', 9 => 'Notes'], $t['Servers'][1]);
	check('the template\'s Clients and Servers have no data rows (only the hidden choices)', count($t['Servers']) === 1);
	check('Sample banner', str_starts_with($t['Sample'][1][1], 'SAMPLE — not imported. Copy what you need to the Clients and Servers sheets.'));
	check('Sample clients and servers', $t['Sample'][5][1] === 'Acme' && $t['Sample'][6][1] === 'Globex' && $t['Sample'][7][1] === 'Initech'
		&& $t['Sample'][5][4] === '20000' && $t['Sample'][6][8] === 'proxy-mumbai-01' && $t['Sample'][7][11] === '/data=2000; /logs=500'
		&& $t['Sample'][13] === [1 => 'Acme', 2 => '10.10.0.21', 3 => 'acme-edge-01', 4 => 'Forwarder', 8 => 'nginx; kafka', 10 => 'R7']
		&& $t['Sample'][14] === [1 => 'Globex', 2 => '10.20.0.5', 4 => 'ES Data', 5 => 'Parser', 6 => 'Forwarder', 7 => 'Engine', 9 => 'single node'], $t['Sample']);
	$tf = Zip::read($template);
	$s1 = $tf['xl/worksheets/sheet1.xml'];
	check('every Clients and Servers header has a note', substr_count($tf['xl/comments1.xml'], '<comment ') === count($clientHeaders)
		&& substr_count($tf['xl/comments2.xml'], '<comment ') === 9, [substr_count($tf['xl/comments1.xml'], '<comment '), count($clientHeaders)]);
	check('the notes are wired so they show on hover: VML per sheet, hidden until hovered, legacyDrawing, content types',
		str_contains($s1, '<legacyDrawing r:id="rId2"/>') && substr_count($tf['xl/drawings/vmlDrawing1.vml'], 'ObjectType="Note"') === count($clientHeaders)
		&& str_contains($tf['xl/drawings/vmlDrawing1.vml'], 'visibility:hidden') && !str_contains($tf['xl/drawings/vmlDrawing1.vml'], '<x:Visible')
		&& str_contains($tf['[Content_Types].xml'], 'Extension="vml"') && str_contains($tf['[Content_Types].xml'], '/xl/comments1.xml')
		&& str_contains($tf['xl/worksheets/_rels/sheet1.xml.rels'], 'vmlDrawing1.vml'));
	check('Type note says what it takes, its choices and an example', (bool) preg_match('/Type ▾.*Settings → Client types.*Choices: CI, DI, On-Prem\..*Example: DI/s', $tf['xl/comments1.xml']));
	check('frozen header row (and the Client column) on Clients; header row on Servers',
		str_contains($s1, '<pane xSplit="1" ySplit="1" topLeftCell="B2" activePane="bottomRight" state="frozen"/>')
		&& str_contains($tf['xl/worksheets/sheet2.xml'], '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'));
	check('Type is an inline list with a stop and a clear message', str_contains($s1, 'errorStyle="stop"') && (bool) preg_match('/error="Type: pick CI, DI or On-Prem\."[^>]*sqref="B2:B2000"><formula1>&quot;CI,DI,On-Prem&quot;<\/formula1>/', $s1));
	$monCol = Xlsx::col(array_search('Choices: Monitored by', $t['Clients'][1], true));
	check('Monitored by reads a hidden column of the server, proxies and groups', (bool) preg_match('/sqref="O2:O2000"><formula1>Clients!\$'.$monCol.'\$2:\$'.$monCol.'\$6<\/formula1>/', $s1)
		&& array_column(array_slice($t['Clients'], 1, 5), array_search('Choices: Monitored by', $t['Clients'][1], true)) === ['Zabbix server', 'Jump host', 'proxy-mumbai-01', 'proxy-dc2', 'dc-group (proxy group)']
		&& str_contains($s1, '<col min="'.Xlsx::colNumber($monCol).'" max="'.Xlsx::colNumber($monCol).'" width="18" customWidth="1" hidden="1"/>'), $monCol);
	check('the capacity columns are one collapsed outline group, its +/- over Agent port', substr_count($s1, 'outlineLevel="1"') === 5 * count(Roles::allRoles($roles))
		&& str_contains($s1, '<col min="31" max="31" width="11" customWidth="1" collapsed="1"/>') && str_contains($s1, 'outlineLevelCol="1"') && str_contains($s1, 'summaryRight="0"'));
	check('Contract ends cells get the date format', str_contains($tf['xl/styles.xml'], 'formatCode="yyyy\-mm\-dd"'));
	$s2 = $tf['xl/worksheets/sheet2.xml'];
	check('Servers Client reads the Clients sheet, as a warning (a client already in Zabbix needs no Clients row)',
		(bool) preg_match('/errorStyle="warning"[^>]*sqref="A2:A2000"><formula1>Clients!\$A\$2:\$A\$2000<\/formula1>/', $s2));
	check('Role 1–4 are role lists', substr_count($s2, '<formula1>&quot;ES Data,ES Data Hot,ES Data Warm,ES Coordination,ES Master,Parser,S3 Parser,Forwarder,Engine,UEBA,AIML,Trino (ULM),SOAR&quot;</formula1>') === 4);
	$p = Workbook::parse($template, $roles, $lists);
	check('the untouched template imports as nothing, with no error, and the Sample is skipped', $p['errors'] === [] && $p['clients']['rows'] === [] && $p['servers']['clients'] === []
		&& $p['skipped'] === ['Sample'], $p);
	// Many proxies: the choices outgrow Excel's 255 characters and still work.
	$many = Workbook::build($roles, ['proxies' => array_map(fn($i) => 'proxy-number-'.$i, range(1, 40))] + $lists);
	check('forty proxies still make one dropdown, from its hidden column', (bool) preg_match('/<formula1>Clients!\$[A-Z]+\$2:\$[A-Z]+\$44<\/formula1>/', Zip::read($many)['xl/worksheets/sheet1.xml']));
	$longRoles = $roles;
	foreach ($longRoles['families'][0]['roles'] as &$r) { $r['label'] .= ' with a much longer name, for testing the limit'; }
	unset($r);
	check('a long role list moves to a hidden column too', (bool) preg_match('/Choices: Role/', implode('|', Xlsx::read(Workbook::build($longRoles, $lists))['Clients'][1]))
		&& substr_count(Zip::read(Workbook::build($longRoles, $lists))['xl/worksheets/sheet2.xml'], '<formula1>Clients!$') === 5);

	/* ---------------- export, and back ---------------- */
	$form = fn(string $name, array $over) => array_merge($spec->defaults(), ['name' => $name, 'servers' => '[]'], $over);
	$acme = $form('acme', ['type' => 'DI', 'purchased_by' => 'storage', 'purchased' => '40960', 'lead_name' => "=cmd|' /C calc'!A0", 'cluster_dl' => 'soc-acme@example.com',
		'alert_dl' => '1', 'weekly_report' => '1', 'contract_end' => '2027-03-31', 'es_url' => 'https://es.acme.example:9200', 'es_user' => 'elastic',
		'es_password_mode' => 'vault', 'es_password_path' => 'secret/elasticpro/acme:password', 'monitored_by' => 'proxy:proxy-dc2',
		'ulm_bucket' => 'acme-archive', 'ulm_region' => 'ap-south-1', 'ulm_auth' => 'access_key', 'ulm_access_key_id' => 'AKIAX', 'ulm_secret_path' => 'secret/a:s3',
		'es_data_hot_servers' => '2', 'es_data_hot_cpu' => '16', 'es_data_hot_mem' => '64', 'es_data_hot_disk' => '200',
		'es_data_hot_disk2_fs' => '/data', 'es_data_hot_disk2' => '4000', 'es_data_hot_disk3_fs' => '/logs', 'es_data_hot_disk3' => '500',
		'servers' => $spec->canonServers([['ip' => '10.100.0.11', 'roles' => ['es_data_hot'], 'services' => ['nginx'], 'notes' => 'rack 4', 'attrs' => ['rack' => 'R4', 'owner_team' => 'blue']],
			['ip' => '10.100.0.12', 'roles' => ['es_master', 'es_coord'], 'services' => [], 'notes' => '', 'attrs' => []],
			['ip' => '10.100.0.13', 'roles' => ['es_data', 'parser', 'forwarder', 'engine', 'ueba'], 'services' => ['a', 'b'], 'notes' => '', 'attrs' => []]])]);
	$wayne = $form('wayne', ['type' => 'On-Prem', 'purchased_by' => 'devices', 'purchased_devices' => '300', 'es_url' => 'https://es.wayne.example:9200',
		'es_password_mode' => 'zabbix', 'monitored_by' => 'jump', 'jump_host' => 'jump.wayne.example', 'jump_user' => 'zbx', 'jump_tls' => 'ca', 'jump_ca' => 'C:\\certs\\ca.pem',
		'es_apikey_path' => 'secret/wayne:apikey', 'agent_port' => '10051']);
	$canon = function (array $f) use ($spec) { ['client' => $c, 'errors' => $e] = $spec->fromForm($f); return [$c['fields'] ?? null, $c['servers'] ?? null, $e]; };
	[$acmeFields, , $acmeErr] = $canon($acme);
	[$wayneFields, , $wayneErr] = $canon($wayne);
	check('the export fixtures are valid clients', $acmeErr === [] && $wayneErr === [], [$acmeErr, $wayneErr]);
	$forms = [$acme, $wayne];
	$export = Workbook::build($roles, $lists, $forms, ['acme' => ['10.100.0.11' => 'acme-ES-Data-Hot-1']]);
	if (getenv('EP_FIXTURE_OUT')) {
		file_put_contents(getenv('EP_FIXTURE_OUT').'/template.xlsx', $template);
		file_put_contents(getenv('EP_FIXTURE_OUT').'/export.xlsx', $export);
	}
	$e = Xlsx::read($export);
	$col = array_flip($e['Clients'][1]);
	check('export: one row per client, shown as the dropdowns show them', $e['Clients'][2][1] === 'acme' && $e['Clients'][2][$col['Type ▾']] === 'DI'
		&& $e['Clients'][2][$col['Purchased']] === '40960' && $e['Clients'][2][$col['Alert DL ▾']] === 'Yes' && $e['Clients'][2][$col['Contract ends']] === '2027-03-31'
		&& $e['Clients'][2][$col['Monitored by ▾']] === 'proxy-dc2' && $e['Clients'][2][$col['S3 access ▾']] === 'Access key'
		&& $e['Clients'][2][$col['ES Data Hot · Other disks']] === '/data=4000; /logs=500' && $e['Clients'][2][$col['ES Data Hot · Servers']] === '2'
		&& $e['Clients'][3][$col['Purchased']] === '300' && $e['Clients'][3][$col['Monitored by ▾']] === 'Jump host' && $e['Clients'][3][$col['Jump TLS ▾']] === 'Yes'
		&& $e['Clients'][3][$col['Password in ▾']] === 'Zabbix secret', $e['Clients'][2]);
	check('export: not-set capacity is an empty cell, not 0', !isset($e['Clients'][2][$col['AIML · Servers']]));
	check('export: the servers, roles by name in Role 1–4, the host name, attributes as columns', $e['Servers'][1] === [1 => 'Client ▾ *', 2 => 'IP *', 3 => 'Host name', 4 => 'Role 1 ▾ *',
		5 => 'Role 2 ▾', 6 => 'Role 3 ▾', 7 => 'Role 4 ▾', 8 => 'Services', 9 => 'Notes', 10 => 'Owner team', 11 => 'Rack']
		&& $e['Servers'][2] === [1 => 'acme', 2 => '10.100.0.11', 3 => 'acme-ES-Data-Hot-1', 4 => 'ES Data Hot', 8 => 'nginx', 9 => 'rack 4', 10 => 'blue', 11 => 'R4']
		&& $e['Servers'][3] === [1 => 'acme', 2 => '10.100.0.12', 4 => 'ES Coordination', 5 => 'ES Master'] || $e['Servers'][3] === [1 => 'acme', 2 => '10.100.0.12', 4 => 'ES Master', 5 => 'ES Coordination'], $e['Servers']);
	$ef = Zip::read($export);
	check('formula injection: a lead of =cmd… is a string cell, never a formula', !preg_match('/<f[ >]/', implode('', $ef))
		&& $e['Clients'][2][$col['SOC / Manager lead']] === "=cmd|' /C calc'!A0" && (bool) preg_match('/<c r="E2" s="5" t="s">/', $ef['xl/worksheets/sheet1.xml']));
	$back = Workbook::parse($export, $roles, $lists);
	check('export reads back with no errors and nothing ignored', $back['errors'] === [] && $back['clients']['errors'] === [] && $back['servers']['errors'] === []
		&& $back['clients']['ignored'] === [], [$back['errors'], $back['clients']['errors'] ?? null, $back['clients']['ignored'] ?? null]);
	check('counts per sheet', $back['counts'] === ['Clients' => 2, 'Servers' => 3], $back['counts']);
	// The same clients through clients.csv: the workbook must hand the Importer what the CSV hands it.
	$viaCsv = Csv::parse(Csv::export($roles, $forms), $roles);
	foreach ([0, 1] as $i) {
		$fromXlsx = Csv::toForm($back['clients']['rows'][$i], $spec->defaults(), $roles);
		$fromCsv = Csv::toForm($viaCsv['rows'][$i], $spec->defaults(), $roles);
		[$fx, , $ex] = $canon(array_merge($fromXlsx, ['servers' => '[]']));
		[$fc, , ] = $canon(array_merge($fromCsv, ['servers' => '[]']));
		check("client $i: every setting is the same as through clients.csv, and as it was", $ex === [] && $fx === $fc && $fx === [$acmeFields, $wayneFields][$i],
			[$ex, array_diff_assoc((array) $fx, (array) $fc), array_diff_assoc((array) $fx, [$acmeFields, $wayneFields][$i])]);
	}
	$sv = Csv::parseServers(Csv::exportServers($roles, $forms), $roles);
	$strip = fn($list) => array_map(fn($s) => array_diff_key($s, ['_line' => 1]), $list);
	$csvNamed = Csv::parseServers(Csv::exportServers($roles, $forms, ['acme' => ['10.100.0.11' => 'acme-ES-Data-Hot-1']]), $roles);
	check('servers: the same as through servers.csv, Host name read as the host name', $strip($back['servers']['clients']['acme']) == $strip($csvNamed['clients']['acme'])
		&& $back['servers']['clients']['acme'][0]['host'] === 'acme-ES-Data-Hot-1' && $back['servers']['clients']['acme'][1]['host'] === '',
		[$strip($back['servers']['clients']['acme']), $strip($csvNamed['clients']['acme'])]);
	// The Importer drops a Host name that is the host's current made-up name (test/e2e.test.php); here, by hand.
	$unnamed = array_map(fn($s) => array_merge($s, ['host' => '']), $strip($back['servers']['clients']['acme']));
	['servers' => $again, 'errors' => $se] = $spec->parseServers(json_encode($unnamed));
	check('and the same servers as before — five roles on one server survive the four slots', $se === [] && ClientSpec::encodeServers($again) === $forms[0]['servers'],
		[$se, ClientSpec::encodeServers($again), $forms[0]['servers']]);

	/* ---------------- what the import checks ---------------- */
	$sheet = fn(array $clients, ?array $servers = null, array $extra = []) => Xlsx::build(array_merge(
		[['name' => 'Clients', 'rows' => $clients]], $servers === null ? [] : [['name' => 'Servers', 'rows' => $servers]], $extra));
	$p = Workbook::parse($sheet([1 => [1 => 'Client *', 2 => 'Type ▾', 3 => 'Monitored by ▾'], 2 => [1 => 'Acme', 2 => 'XYZ', 3 => 'proxy-nowhere'], 3 => [1 => 'Beta', 2 => 'di', 3 => 'proxy-dc2']]), $roles, $lists);
	check('a Type not in the list is an error naming sheet, row, client and the choices', in_array('Clients, row 2 (Acme): Type is "XYZ" — pick CI, DI or On-Prem.', $p['errors'], true), $p['errors']);
	check('a proxy this Zabbix does not have is an error', (bool) preg_grep('/^Clients, row 2 \(Acme\): Monitored by is "proxy-nowhere" — pick Zabbix server, Jump host, proxy-mumbai-01, proxy-dc2 or dc-group \(proxy group\)\. Proxies are read/', $p['errors']), $p['errors']);
	check('a known proxy is proxy:<name>; a type in any case is the type', $p['clients']['rows'][1]['monitored_by'] === 'proxy:proxy-dc2' && $p['clients']['rows'][1]['type'] === 'DI', $p['clients']['rows'][1]);
	$p = Workbook::parse($sheet([1 => [1 => 'Client', 2 => 'Monitored by', 3 => 'Jump TLS', 4 => 'Jump CA', 5 => 'Alert DL', 6 => 'ES Data Hot · Other disks', 7 => 'Purchased', 8 => 'Purchased by'],
		2 => [1 => 'A', 2 => 'dc-group (proxy group)', 3 => 'Yes', 4 => 'C:\\ca.pem', 5 => 'TRUE', 6 => '/data=4000GB;/logs = 500', 7 => '300', 8 => 'devices'],
		3 => [1 => 'B', 2 => 'Jump host', 3 => 'no', 5 => 'no', 6 => '']]), $roles, $lists);
	$r0 = $p['clients']['rows'][0]; $r1 = $p['clients']['rows'][1];
	check('proxy group, Jump TLS Yes with a CA = ca, TRUE = yes, disks, Purchased', $p['errors'] === [] && $r0['monitored_by'] === 'group:dc-group' && $r0['jump_tls'] === 'ca'
		&& $r0['alert_dl'] === '1' && $r0['es_data_hot_disk2_mount'] === '/data' && $r0['es_data_hot_disk2_gb'] === '4000' && $r0['es_data_hot_disk3_mount'] === '/logs'
		&& $r0['es_data_hot_disk3_gb'] === '500' && $r0['es_data_hot_disk4_mount'] === '' && $r0['purchased_storage_gb'] === '300' && $r0['purchased_devices'] === '300', [$p['errors'], $r0]);
	check('Jump host = jump; No = none; an empty Other disks clears all four', $r1['monitored_by'] === 'jump' && $r1['jump_tls'] === 'none' && $r1['alert_dl'] === '0'
		&& $r1['es_data_hot_disk2_mount'] === '' && $r1['es_data_hot_disk5_gb'] === '', $r1);
	$devices = Csv::toForm($r0, $spec->defaults(), $roles);
	check('Purchased goes to the measure Purchased by names', $spec->fromForm(array_merge($devices, ['name' => 'A']))['client']['fields']['purchased_devices'] === '300'
		&& $spec->fromForm(array_merge($devices, ['name' => 'A']))['client']['fields']['purchased'] === '0');
	$p = Workbook::parse($sheet([1 => [1 => 'Client', 2 => 'ES Data Hot · Other disks'], 2 => [1 => 'A', 2 => '/a=1; /b=2; /c=3; /d=4; /e=5'], 3 => [1 => 'B', 2 => '/data:4000']]), $roles, $lists);
	check('more than four other disks, and a disk not written mount=GB, are errors that say how', in_array('Clients, row 2 (A): ES Data Hot · Other disks lists 5 disks; at most 4 besides /.', $p['errors'], true)
		&& in_array('Clients, row 3 (B): ES Data Hot · Other disks: "/data:4000" is not mount=GB. Write it like /data=4000; /logs=500.', $p['errors'], true), $p['errors']);
	// A column left out is no change; an empty cell clears.
	$p = Workbook::parse($sheet([1 => [1 => 'Client *', 2 => 'S3 role ARN'], 2 => [1 => 'acme']]), $roles, $lists);
	$over = Csv::toForm($p['clients']['rows'][0], $acme, $roles);
	check('a column left out is no change; an empty cell clears', $p['clients']['columns'] === ['client', 's3_role_arn'] && $over['lead_name'] === $acme['lead_name']
		&& $over['ulm_role_arn'] === '' && $over['es_data_hot_cpu'] === '16', $p['clients']);
	$p = Workbook::parse($sheet([1 => [1 => 'Client *', 2 => 'Favourite colour'], 2 => [1 => 'acme', 2 => 'blue']]), $roles, $lists);
	check('an unknown Clients header is reported as ignored', $p['clients']['ignored'] === ['Favourite colour (Clients)'], $p['clients']['ignored']);
	$p = Workbook::parse($sheet([1 => [1 => 'Client *']], [1 => [1 => 'Client ▾ *', 2 => 'IP *', 3 => 'Role 1 ▾ *', 4 => 'Role 2 ▾', 5 => 'Role 3 ▾', 6 => 'Rack'],
		14 => [1 => 'acme', 2 => '10.0.0.31', 3 => 'ES Master', 4 => 'ES Hot'], 15 => [1 => 'acme', 2 => '10.0.0.32', 3 => 'parser', 4 => 'Parser'],
		16 => [1 => 'acme', 2 => '10.0.0.33'], 17 => [1 => 'acme', 3 => 'Parser'], 18 => [1 => 'acme', 2 => '10.0.0.34', 3 => 'ES-Coord', 5 => 'Engine', 6 => 'R9']]), $roles, $lists);
	check('a role that is not one of the roles: sheet, row, IP, column, and how to fix', in_array('Servers, row 14 (10.0.0.31): Role 2 is "ES Hot" — not one of the roles. Pick it from the dropdown, or leave it empty.', $p['errors'], true), $p['errors']);
	check('the same role twice, no role, no IP: each an error with its row', in_array('Servers, row 15 (10.0.0.32): Role 2 repeats "Parser", already given in this row.', $p['errors'], true)
		&& in_array('Servers, row 16 (10.0.0.33): Role 1 is empty — pick the server\'s role.', $p['errors'], true)
		&& in_array('Servers, row 17: IP is empty — every server needs its IPv4 address.', $p['errors'], true), $p['errors']);
	$last = end($p['servers']['clients']['acme']);
	check('roles by id, label or short name; a gap between slots is fine; other columns are attributes', $last['roles'] === ['es_coord', 'engine'] && $last['attrs'] === ['rack' => 'R9'] && $last['_line'] === 18, $last);
	$p = Workbook::parse($sheet([1 => [1 => 'Client']], null, [['name' => 'SAMPLE', 'rows' => [1 => [1 => 'Client'], 2 => [1 => 'Acme']]], ['name' => 'Notes', 'rows' => [1 => [1 => 'x']]]]), $roles, $lists);
	check('the Sample sheet (any case) and any other sheet are never read', $p['skipped'] === ['SAMPLE', 'Notes'] && $p['clients']['rows'] === [] && $p['servers'] === null, $p);
	check('a workbook with neither sheet says so', str_contains(implode(' ', Workbook::parse(Xlsx::build([['name' => 'Sheet1', 'rows' => [1 => [1 => 'x']]]]), $roles, $lists)['errors']), 'no Clients or Servers sheet'));
	check('a CSV handed to the workbook reader is refused in words', str_contains(implode(' ', Workbook::parse("client,type\nacme,DI\n", $roles, $lists)['errors']), 'not a ZIP'));
	$p = Workbook::parse($sheet([1 => [1 => 'Client', 2 => 'client']]), $roles, $lists);
	check('the same column twice is an error', (bool) preg_grep('/Column "client" appears 2 times/', $p['clients']['errors']), $p['clients']['errors']);
	$p = Workbook::parse($sheet([1 => [1 => 'client', 2 => 'type', 3 => 'es_data_hot_cpu_cores', 4 => 's3_access'], 2 => [1 => 'acme', 2 => 'On-Prem', 3 => '8', 4 => 'key']]), $roles, $lists);
	check('clients.csv\'s own column names are read too', $p['errors'] === [] && $p['clients']['rows'][0]['es_data_hot_cpu_cores'] === '8' && $p['clients']['rows'][0]['s3_access'] === 'key', $p);

	/* ---------------- workbooks another tool wrote (test/fixtures/make_fixture.py) ---------------- */
	$filled = $fixtures.'/openpyxl-filled.xlsx';
	if (!is_file($filled)) {
		check('the openpyxl fixtures are there (run make_fixture.py)', false);
	}
	else {
		$p = Workbook::parse(file_get_contents($filled), $roles, $lists);
		check('openpyxl-filled: no errors', $p['errors'] === [] && $p['clients']['errors'] === [] && $p['servers']['errors'] === [], [$p['errors'], $p['clients']['errors'] ?? null, $p['servers']['errors'] ?? null]);
		$b = $p['clients']['rows'][0]; $w = $p['clients']['rows'][1];
		check('openpyxl-filled: Bajaj as typed in Excel', $b['client'] === 'Bajaj' && $b['type'] === 'DI' && $b['purchased_by'] === 'storage' && $b['purchased_storage_gb'] === '20000'
			&& $b['alert_dl'] === '1' && $b['weekly_report'] === '1' && $b['contract_end'] === '2027-03-31' && $b['es_password_in'] === 'vault' && $b['monitored_by'] === ''
			&& $b['s3_access'] === 'role' && $b['es_data_hot_servers'] === '2' && $b['es_data_hot_cpu_cores'] === '16' && $b['es_data_hot_disk2_mount'] === '/data'
			&& $b['es_data_hot_disk3_gb'] === '500' && $b['_line'] === 2, $b);
		check('openpyxl-filled: Wayne', $w['client'] === 'Wayne' && $w['purchased_by'] === 'devices' && $w['purchased_devices'] === '300' && $w['es_password_in'] === 'zabbix'
			&& $w['monitored_by'] === 'proxy:proxy-mumbai-01' && $w['agent_port'] === '10051' && $w['weekly_report'] === '0', $w);
		foreach ([$b, $w] as $row) {
			[, , $err] = $canon(Csv::toForm($row, $spec->defaults(), $roles));
			check('openpyxl-filled: '.$row['client'].' is a valid client', $err === [], $err);
		}
		$sv = $p['servers']['clients'];
		check('openpyxl-filled: the servers, with Rack as an attribute', array_keys($sv) === ['Bajaj', 'Wayne'] && $sv['Bajaj'][1]['roles'] === ['es_master', 'es_coord']
			&& $sv['Bajaj'][0]['attrs'] === ['rack' => 'R4'] && $sv['Wayne'][0]['roles'] === ['es_data', 'parser', 'forwarder', 'engine'] && $sv['Wayne'][0]['services'] === ['nginx', 'kafka']
			&& $sv['Wayne'][0]['notes'] === 'single node', $sv);
		$m = Workbook::parse(file_get_contents($fixtures.'/openpyxl-mixed.xlsx'), $roles, $lists);
		$a = $m['clients']['rows'][0] ?? []; $c = $m['clients']['rows'][1] ?? [];
		check('openpyxl-mixed: no errors; the Notes sheet skipped; the unknown column ignored', $m['errors'] === [] && $m['skipped'] === ['Notes']
			&& $m['clients']['ignored'] === ['Favourite colour (Clients)'], $m);
		check('openpyxl-mixed: a date cell (dd/mm/yyyy format) is Y-m-d; 1500.0 is 1500; TRUE is yes; "on prem" is On-Prem; the empty row is skipped',
			count($m['clients']['rows']) === 2 && $a['contract_end'] === '2026-12-31' && $a['purchased_storage_gb'] === '1500' && $a['alert_dl'] === '1' && $a['type'] === 'On-Prem'
			&& $a['agent_port'] === '10050' && $a['es_url'] === '' && $c['_line'] === 4 && $c['contract_end'] === '2027-01-15' && $c['purchased_storage_gb'] === '12.5'
			&& $c['alert_dl'] === '0' && $c['purchased_by'] === '', [$a, $c]);
		check('openpyxl-mixed: a lower-case servers sheet, role ids and labels, an extra attribute', $m['servers']['clients']['Mixed'][0]['roles'] === ['es_data', 'parser']
			&& $m['servers']['clients']['Mixed'][0]['attrs'] === ['owner_team' => 'team-a'] && $m['servers']['clients']['Mixed'][1]['services'] === ['nginx']
			&& $m['servers']['clients']['Mixed'][1]['attrs'] === ['owner_team' => ''], $m['servers']);
	}

	/* ---------------- our export, opened and saved again by LibreOffice ---------------- */
	// soffice --headless --convert-to xlsx export.xlsx (see test/fixtures/make_fixture.py): LibreOffice
	// kept every note and dropdown, which it would not have had it not read them; and our reader reads
	// what LibreOffice writes.
	$lo = $fixtures.'/libreoffice-export.xlsx';
	if (is_file($lo)) {
		$lf = Zip::read(file_get_contents($lo));
		$notes = 0; $dvs = 0;
		foreach ($lf as $k => $v) {
			$notes += str_contains($k, 'comments') ? substr_count($v, '<comment ') : 0;
			$dvs += str_contains($k, 'worksheets/sheet') ? substr_count($v, '<dataValidation ') : 0;
		}
		// The fixture was written before the roles of version 3 (Trino, SOAR): its Clients sheet has
		// the headers of the roles it was made with, not today's.
		$fixtureRoles = $roles;
		$fixtureRoles['families'] = array_values(array_filter($roles['families'], fn($f) => !in_array($f['id'], Roles::ADDED_IN[3], true)));
		$fixtureHeaders = array_values(array_filter(Xlsx::read(Workbook::build($fixtureRoles, $lists))['Clients'][1], fn($h) => strpos($h, Workbook::CHOICES) !== 0));
		check('LibreOffice kept every note: Clients, Servers (with its two attribute columns) and Sample', $notes === count($fixtureHeaders) + 11 + 6, [$notes, count($fixtureHeaders) + 11 + 6]);
		check('and all 13 dropdowns', $dvs === 13, $dvs);
		$p = Workbook::parse(file_get_contents($lo), $roles, $lists);
		check('the LibreOffice-saved export reads back with no errors, the same clients and servers', $p['errors'] === [] && $p['counts'] === ['Clients' => 2, 'Servers' => 3]
			&& $p['clients']['rows'][0]['contract_end'] === '2027-03-31' && $p['clients']['rows'][1]['jump_tls'] === 'ca' && $p['clients']['rows'][0]['soc_lead'] === "=cmd|' /C calc'!A0"
			&& $p['servers']['clients']['acme'][2]['roles'] === ['es_data', 'parser', 'forwarder', 'engine', 'ueba'], $p['errors']);
	}
	else {
		check('the LibreOffice fixture is there', false);
	}

	foreach (array_diff(scandir($dir), ['.', '..']) as $fn) { is_dir($dir.'/'.$fn) ? null : unlink($dir.'/'.$fn); }
	@rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
