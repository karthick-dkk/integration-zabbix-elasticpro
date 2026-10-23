<?php declare(strict_types = 0);
/**
 * Writes the templates the Clients page generates — cluster, devices, jump host, log archive via
 * jump host, and the client master for the default roles — as Zabbix 7.0 JSON exports, so they
 * can be validated and live-tested like the four shipped YAML templates:
 *
 *   php tools/test/gen_templates.php OUT_DIR
 *   python3 tools/test/validate_template.py OUT_DIR/master.json
 *   python3 tools/test/template_live_test.py --extra OUT_DIR/master.json ...
 *
 * No Zabbix and no frontend: the generators are plain PHP. With no roles.json / templates.json
 * in reach (EP_DATA_DIR unset or empty), the shipped defaults are used.
 */
spl_autoload_register(function ($class) {
	$parts = explode('\\', $class);
	$file = __DIR__.'/../../clients-module/lib/'.end($parts).'.php';
	if (is_file($file)) {
		require $file;
	}
});

use Modules\EpClients\Lib\{ClusterTemplate, DevicesTemplate, JumpTemplate, MasterTemplate, Roles};

$out = $argv[1] ?? null;
if (!$out) {
	fwrite(STDERR, "usage: php gen_templates.php OUT_DIR\n");
	exit(2);
}
if (!getenv('EP_DATA_DIR')) {
	putenv('EP_DATA_DIR='.sys_get_temp_dir().'/ep-gen-templates-none');
}
@mkdir($out, 0777, true);
$write = function (string $name, array $export) use ($out) {
	file_put_contents("$out/$name.json", json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	echo "$out/$name.json\n";
};
$write('cluster', ClusterTemplate::export());
$write('devices', DevicesTemplate::export());
$write('jump', JumpTemplate::export());
$write('jump_ulm', JumpTemplate::ulmExport());
$write('master', (new MasterTemplate(Roles::defaults()))->export());
