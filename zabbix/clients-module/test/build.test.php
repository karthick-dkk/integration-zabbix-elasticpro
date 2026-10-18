<?php
/**
 * The line on ElasticPro -> Settings that says which build of the modules this is.
 *   docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php test/build.test.php
 * Exit 0 when every check holds; each failure is printed.
 */
namespace Modules\EpClients\Test {
	require __DIR__.'/../lib/Build.php';
	use Modules\EpClients\Lib\Build;

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	$tmp = sys_get_temp_dir().'/ep-build-'.getmypid();
	mkdir($tmp);
	$module = function (string $name, ?string $manifest, ?string $build) use ($tmp): string {
		$d = $tmp.'/'.$name; mkdir($d);
		if ($manifest !== null) { file_put_contents($d.'/manifest.json', $manifest); }
		if ($build !== null) { file_put_contents($d.'/BUILD', $build); }
		return $d;
	};
	$full = "# written by tools/zabbix-modules-tarball.sh\nmodules_version=2.1.0\nmodules_commit=723f10156ed96353ba0fc8ee69a1aadc3496bda5\nmodules_commit_short=723f101\n";

	check('with a BUILD file: version and build',
		Build::line($module('full', '{"version": "2.1.0"}', $full)) === 'Zabbix modules 2.1.0 · build 723f101', Build::line($tmp.'/full'));
	check('without a BUILD file: the build is not recorded, nothing invented',
		Build::line($module('plain', '{"version": "2.1.0"}', null)) === 'Zabbix modules 2.1.0 · build not recorded', Build::line($tmp.'/plain'));
	check('the version is the manifest\'s, not BUILD\'s',
		Build::line($module('differs', '{"version": "2.1.1"}', $full)) === 'Zabbix modules 2.1.1 · build 723f101');
	check('a build from a modified checkout says so',
		Build::line($module('modified', '{"version": "2.1.0"}', $full."modules_tree=modified\n")) === 'Zabbix modules 2.1.0 · build 723f101 (modified)', Build::line($tmp.'/modified'));
	check('a clean tree adds nothing',
		Build::line($module('clean', '{"version": "2.1.0"}', $full."modules_tree=clean\n")) === 'Zabbix modules 2.1.0 · build 723f101');
	check('a commit marked unknown is not recorded',
		Build::line($module('unknown', '{"version": "2.1.0"}', "modules_commit=unknown\nmodules_commit_short=unknown\n")) === 'Zabbix modules 2.1.0 · build not recorded');
	check('the short commit is taken from the full one when only that is given',
		Build::line($module('longonly', '{"version": "2.1.0"}', "modules_commit=723f10156ed96353ba0fc8ee69a1aadc3496bda5\n")) === 'Zabbix modules 2.1.0 · build 723f101');
	check('a value with markup is refused, not echoed',
		!str_contains(Build::line($module('xss', '{"version": "2.1.0"}', "modules_commit_short=<script>alert(1)</script>\n")), '<')
		&& Build::line($tmp.'/xss') === 'Zabbix modules 2.1.0 · build not recorded', Build::line($tmp.'/xss'));
	check('a key the line does not use is ignored (an older archive also wrote elasticpro_version)',
		Build::line($module('legacy', '{"version": "2.1.0"}', $full."elasticpro_version=0.3.20\n")) === 'Zabbix modules 2.1.0 · build 723f101', Build::line($tmp.'/legacy'));
	check('a build that is not hex is refused',
		Build::line($module('nothex', '{"version": "2.1.0"}', "modules_commit_short=<b>x</b>\n")) === 'Zabbix modules 2.1.0 · build not recorded');
	check('a manifest that cannot be read says so',
		Build::line($module('nomanifest', null, $full)) === 'Zabbix modules (version not readable) · build 723f101', Build::line($tmp.'/nomanifest'));
	check('a version that is not x.y.z is not shown',
		str_contains(Build::line($module('badver', '{"version": "<i>2</i>"}', null)), 'version not readable'));
	check('comments, blank lines and CRLF in BUILD are fine', Build::parse("# c\r\n\r\na=1\r\nb = two words\r\nnoequals\r\n") === ['a' => '1', 'b' => 'two words']);

	// The real module folder: the line must be built from this folder's manifest.
	$real = json_decode(file_get_contents(__DIR__.'/../manifest.json'), true)['version'];
	check('the real module reports its own manifest version', str_starts_with(Build::line(), 'Zabbix modules '.$real));
	// And the Settings page must show it, through the page's own escaping (CDiv/CSpan), not echoed.
	$view = file_get_contents(__DIR__.'/../views/ep.clients.settings.php');
	check('the Settings view shows Build::line()', str_contains($view, 'Build::line()') && str_contains($view, "ep-build"));
	check('and does not echo it raw', !preg_match('/\becho\b[^;]*Build::/', $view));

	array_map('unlink', glob($tmp.'/*/*')); array_map('rmdir', glob($tmp.'/*')); rmdir($tmp);
	echo $passed, ' passed, ', $failed, ' failed', PHP_EOL;
	exit($failed ? 1 : 0);
}
