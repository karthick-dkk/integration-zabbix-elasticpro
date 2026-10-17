<?php declare(strict_types = 0);
/**
 * The list and the client page, rendered for real against stand-ins for Zabbix's page classes.
 *
 *   php test/views.test.php            checks
 *   EP_VIEWS_OUT=/dir php test/views.test.php   also writes list.html, list-ro.html, edit.html, edit-ro.html, add.html,
 *                                                import.html, import-errors.html, import-done.html
 *
 * What it holds the pages to: the list's row is one pencil and no text links; the client page
 * carries the status and the list's old actions for a client that exists and none for a new one;
 * and the form posts every field it did before, to the same action, with a token.
 */
namespace {
	const CSRF_TOKEN_NAME = '_csrf_token';
	const ZBX_STYLE_BTN_ALT = 'btn-alt';
	if (!function_exists('_')) { function _($s) { return $s; } }
	if (!function_exists('_s')) {
		function _s($s, ...$a) { foreach ($a as $i => $v) { $s = str_replace(['%'.($i + 1).'$s', '%'.($i + 1).'$d'], (string) $v, $s); } return $s; }
	}
	if (!function_exists('_n')) { function _n($a, $b, $n) { return str_replace('%1$s', (string) $n, $n == 1 ? $a : $b); } }
	class CCsrfTokenHelper { public static function get($a) { return 'tok-'.$a; } }
	class CUrl {
		private $a = [];
		public function __construct($u) {}
		public function setArgument($k, $v) { $this->a[$k] = $v; return $this; }
		public function getUrl() { return 'zabbix.php?'.http_build_query($this->a); }
	}
	class CObject { public $html; public function __construct($h) { $this->html = $h; } public function toString() { return $this->html; } }
	class CTag {
		protected $tag; protected $at = []; protected $items = [];
		public function __construct($t, $p = true, $c = null) { $this->tag = $t; if ($c !== null) { $this->addItem($c); } }
		public function addItem($i) { if (is_array($i)) { foreach ($i as $x) { $this->addItem($x); } } else { $this->items[] = $i; } return $this; }
		public function setAttribute($k, $v) { $this->at[$k] = $v; return $this; }
		public function setId($v) { return $this->setAttribute('id', $v); }
		public function addClass($c) { $this->at['class'] = trim(($this->at['class'] ?? '').' '.$c); return $this; }
		public function toString() {
			$a = ''; foreach ($this->at as $k => $v) { $a .= ' '.$k.'="'.htmlspecialchars((string) $v).'"'; }
			$in = ''; foreach ($this->items as $i) { $in .= is_object($i) ? $i->toString() : htmlspecialchars((string) $i); }
			return "<{$this->tag}$a>$in</{$this->tag}>";
		}
	}
	class CList extends CTag { public function __construct() { parent::__construct('ul'); } }
	class CRedirectButton extends CTag { public function __construct($t, $u) { parent::__construct('button', true, $t); } }
	class CHtmlPage {
		private $i = [];
		public function setTitle($t) { return $this; }
		public function setControls($c) { return $this; }
		public function addItem($i) { $this->i[] = $i; return $this; }
		public function show() { foreach ($this->i as $i) { echo $i->toString(); } return $this; }
	}
	class CView {}
	// For the import page, which is built from Zabbix's classes rather than an HTML string.
	class CDiv extends CTag { public function __construct($c = null) { parent::__construct('div', true, $c); } }
	class CSpan extends CTag { public function __construct($c = null) { parent::__construct('span', true, $c); } }
	class CLink extends CTag { public function __construct($c = null, $u = null) { parent::__construct('a', true, $c); if ($u !== null) { $this->setAttribute('href', $u); } } }
	class CLabel extends CTag { public function __construct($c = null, $for = null) { parent::__construct('label', true, $c); if ($for !== null) { $this->setAttribute('for', $for); } } }
	class CSubmit extends CTag { public function __construct($n = 'submit', $c = '') { parent::__construct('button', true, $c); $this->setAttribute('type', 'submit')->setAttribute('name', $n); } }
	class CForm extends CTag {
		public function __construct($m = 'post', $a = null) { parent::__construct('form'); $this->setAttribute('method', $m)->setAttribute('action', $a); }
		public function addVar($n, $v) { return $this->addItem((new CTag('input', false))->setAttribute('type', 'hidden')->setAttribute('name', $n)->setAttribute('value', $v)); }
	}
	class CCheckBox extends CTag {
		public function __construct($n, $v = '1') { parent::__construct('input', false); $this->setAttribute('type', 'checkbox')->setAttribute('name', $n)->setAttribute('value', $v); }
		public function setChecked($on) { if ($on) { $this->setAttribute('checked', 'checked'); } return $this; }
	}
	class CTableInfo extends CTag {
		public function __construct() { parent::__construct('table'); }
		public function setHeader($h) { $tr = new CTag('tr'); foreach ($h as $c) { $tr->addItem(new CTag('th', true, $c)); } return $this->addItem($tr); }
		public function addRow($cells) { $tr = new CTag('tr'); foreach ($cells as $c) { $tr->addItem((new CTag('td'))->addItem($c)); } return $this->addItem($tr); }
	}
}
namespace Modules\EpClients\Test {
	foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'Forecast', 'ClientTypes', 'Bars', 'MasterTemplate', 'DevicesTemplate', 'JumpTemplate', 'ClusterTemplate', 'ClientSpec', 'SetupChecks', 'ClientActions'] as $lib) {
		require __DIR__.'/../lib/'.$lib.'.php';
	}
	$dir = sys_get_temp_dir().'/ep-views-'.getmypid();
	mkdir($dir);
	putenv('EP_DATA_DIR='.$dir);

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo 'FAIL: ', $what, $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}
	function render(string $view, array $data): string {
		ob_start();
		(function () use ($view, $data) { include __DIR__.'/../views/'.$view.'.php'; })();
		return (string) ob_get_clean();
	}
	/** The visible text of the page's body, without the inlined script and style. */
	function body(string $html): string {
		return (string) preg_replace('~<(script|style)\b.*?</\1>~s', '', $html);
	}
	$out = getenv('EP_VIEWS_OUT');
	$dump = function (string $name, string $html) use ($out) { if ($out) { file_put_contents($out.'/'.$name.'.html', $html); } };

	/* ---------------- the list ---------------- */
	$client = fn(string $name, string $status, array $x = []) => $x + ['name' => $name, 'masterid' => '1'.crc32($name) % 90, 'status' => $status, 'maintenance' => null,
		'type' => 'CI', 'es_url' => 'https://es.'.$name.'.example:9200', 'jump' => '', 'archive' => '', 'lead' => 'Asha Rao', 'dl' => 'soc-'.$name.'@example.com',
		'contract_end' => '', 'machines' => '3 ES · 1 Parser', 'unassigned' => 0, 'shared' => 0, 'removed' => [], 'changed' => [], 'checks' => [], 'problems' => [],
		'full_in' => null, 'over' => [], 'change' => null, 'search' => $name, 'master_missing' => false];
	$clients = [
		$client('acme', 'active'),
		$client('globex', 'active', ['maintenance' => ['since' => time() - 600, 'till' => time() + 3600, 'collect' => true, 'reason' => 'ES upgrade'],
			'changed' => [['name' => 'globex-ES-1', 'now' => 'old-name', 'why' => 'renamed']]]),
		$client('initech', 'disabled'),
		$client('umbrella', 'decommissioned'),
		$client('lost', 'active', ['masterid' => null, 'master_missing' => true]),
	];
	$listData = fn(bool $write) => ['clients' => $clients, 'can_write' => $write, 'store_ok' => true, 'store_dir' => $dir, 'template' => 'current',
		'candidates' => [], 'backups' => 3, 'forecast' => []];
	$list = render('ep.clients.list', $listData(true));
	$dump('list', $list);
	$lb = body($list);
	preg_match_all('~<tr data-tabs=.*?</tr>~s', $lb, $rows);
	check('the list has a row per client', count($rows[0]) === 5, count($rows[0]));
	$acts = fn(string $row) => preg_match('~<div class="ep-acts">(.*?)</div></td></tr>~s', $row, $m) ? $m[1] : '';
	foreach (['Edit', 'Maintenance…', 'Extend…', 'Disable', 'Enable', 'Dashboard', 'Clone', 'Put back', 'Decommission', 'Restore', 'Delete for good…', 'End maintenance'] as $text) {
		foreach ($rows[0] as $i => $row) {
			if ($i === 4) { continue; }
			check("row $i has no \"$text\" text action", !preg_match('~>'.preg_quote($text, '~').'</(a|button)>~u', $acts($row)));
		}
	}
	foreach ($rows[0] as $i => $row) {
		if ($i === 4) { continue; }
		$a = $acts($row);
		check("row $i has exactly one control, the edit pencil", substr_count($a, '<a ') + substr_count($a, '<button') + substr_count($a, '<form') === 1 && str_contains($a, 'class="ep-edit"'), $a);
		check("row $i's pencil opens that client's page and is labelled", str_contains($a, 'action=ep.clients.edit&amp;client='.$clients[$i]['name'])
			&& str_contains($a, 'aria-label="Edit '.$clients[$i]['name'].'"') && str_contains($a, 'title="Edit '.$clients[$i]['name'].'"'), $a);
	}
	check('a client whose master host is gone keeps Re-create and Forget on the list, as it has no page to open',
		str_contains($acts($rows[0][4]), '>Re-create</button>') && str_contains($acts($rows[0][4]), '>Forget</button>') && !str_contains($acts($rows[0][4]), 'ep-edit'));
	check('the list still offers the bulk bar and the Maintenance and Delete dialogs', str_contains($lb, 'id="ep-bulk"') && str_contains($lb, 'id="ep-maint"') && str_contains($lb, 'id="ep-delete"'));
	check('the list wires its dialogs through the shared script', str_contains($list, 'window.EpDialogs.init(root, ticked)') && str_contains($list, 'window.EpDialogs = '));
	$ro = render('ep.clients.list', $listData(false));
	$dump('list-ro', $ro);
	preg_match_all('~<tr data-tabs=.*?</tr>~s', body($ro), $rrows);
	check('read only: one labelled "View" pencil per row, no dialogs', str_contains($acts($rrows[0][0]), 'aria-label="View acme"') && !str_contains(body($ro), 'id="ep-maint"'));

	/* ---------------- the client page ---------------- */
	$spec = new \Modules\EpClients\Lib\ClientSpec(\Modules\EpClients\Lib\Roles::defaults());
	$form = $spec->defaults();
	$form = array_filter($form, fn($k) => $k[0] !== '_', ARRAY_FILTER_USE_KEY);
	$base = ['form' => $form, 'roles' => \Modules\EpClients\Lib\Roles::defaults(), 'mode' => 'edit', 'adopting' => false, 'existing' => [], 'host_names' => [], 'unassigned' => [],
		'shared' => [], 'deletes' => null, 'current' => [], 'merge' => [], 'has_cluster' => true, 'proxies' => ['dc-proxy'], 'status' => 'active', 'masterid' => '10084',
		'maintenance' => null, 'removed' => [], 'changed' => [], 'history' => [], 'proxy_groups' => [], 'errors' => [], 'cloned_from' => '', 'can_write' => true, 'done' => []];
	$filled = ['name' => 'acme', 'type' => 'CI', 'es_url' => 'https://es.acme.example:9200', 'es_user' => 'elastic', 'es_password_mode' => 'vault',
		'es_password_path' => 'secret/elasticpro/acme:password', 'lead_name' => 'Asha Rao', 'cluster_dl' => 'soc-acme@example.com', 'alert_dl' => '1', 'purchased_by' => 'storage',
		'purchased' => '20000', 'contract_end' => '2027-03-31', 'es_data_hot_cpu' => '32', 'es_data_hot_mem' => '128', 'es_data_hot_disk' => '4000', 'es_master_cpu' => '12',
		'servers' => json_encode([['ip' => '10.0.0.11', 'roles' => ['es_data_hot'], 'services' => [], 'notes' => 'rack 4'], ['ip' => '10.0.0.12', 'roles' => ['es_data_hot'], 'services' => [], 'notes' => ''],
			['ip' => '10.0.0.13', 'roles' => ['es_master'], 'services' => [], 'notes' => ''], ['ip' => '10.0.0.21', 'roles' => ['parser'], 'services' => ['nginx'], 'notes' => '']])];
	$edit = fn(array $x = [], array $f = []) => $x + ['form' => $f + $filled + $form] + $base;
	$page = render('ep.clients.edit', $edit());
	$dump('edit', $page);
	$pb = body($page);
	preg_match('~<div class="ep-head">.*?</div></div>~s', $pb, $head);
	$head = $head[0] ?? '';
	check('an existing client has a header with its status badge', str_contains($head, 'class="ep-st ep-st-on"') && str_contains($head, '>Enabled<') && str_contains($head, 'acme'), $head);
	foreach (['Maintenance…' => 'button', 'Disable' => 'button', 'Dashboard' => 'a', 'Clone' => 'a'] as $text => $tag) {
		check("and the list's old \"$text\" in it", (bool) preg_match('~<'.$tag.' [^>]*>'.preg_quote($text, '~').'</'.$tag.'>~u', $head), $head);
	}
	check('Disable posts to the status action with its token and confirmation',
		str_contains($head, 'action=ep.clients.status') && str_contains($head, 'value="tok-ep.clients.status"') && str_contains($head, 'name="op" value="disable"')
		&& str_contains($head, 'return confirm('), $head);
	check('Clone and Dashboard go where the list sent them', str_contains($head, 'action=ep.clients.edit&amp;clone=acme') && str_contains($head, 'action=host.dashboard.view&amp;hostid=10084'));
	check('Maintenance… opens the dialog the page carries', str_contains($head, 'data-open-maint="acme"') && str_contains($pb, 'id="ep-maint"') && str_contains($pb, 'id="ep-delete"'));
	check('the dialogs are not inside the client form', !preg_match('~<form[^>]*id="ep-client-form".*id="ep-maint".*</form>\s*<dialog~s', $pb) && strpos($pb, 'id="ep-maint"') > strpos($pb, '</form>'));
	check('no action link on a page for an existing client says Edit', !str_contains($head, '>Edit<'));
	$maint = render('ep.clients.edit', $edit(['maintenance' => ['since' => time() - 60, 'till' => time() + 3600, 'collect' => true, 'reason' => 'ES upgrade']]));
	check('in maintenance: the badge says so, with when and why, and the button offers Extend and End', str_contains($maint, 'ep-st-maint') && str_contains($maint, 'In maintenance')
		&& str_contains($maint, 'ES upgrade') && str_contains($maint, '>Extend…<') && str_contains($maint, '>End maintenance<'));
	check('an existing client has "Remove only in ElasticPro…" in the header, and a dialog that names the three ways and what stays',
		str_contains($head, 'data-open-release="acme"') && str_contains($head, '>Remove only in ElasticPro…<') && str_contains($pb, 'id="ep-release"')
		&& str_contains($pb, 'action=ep.clients.release') && str_contains($pb, 'value="tok-ep.clients.release"') && str_contains($pb, 'name="confirm" id="r-confirm"')
		&& str_contains($pb, '<b>Decommission</b>') && str_contains($pb, '<b>Delete for good</b>') && str_contains($pb, '<b>Remove only in ElasticPro</b>')
		&& str_contains($pb, 'Stays in Zabbix') && str_contains($pb, 'id="r-go" class="ep-danger-btn" disabled'));
	check('it is offered for every status', str_contains(render('ep.clients.edit', $edit(['status' => 'disabled'])), 'data-open-release="acme"')
		&& str_contains(render('ep.clients.edit', $edit(['status' => 'decommissioned'])), 'data-open-release="acme"'));
	check('the list does not offer it', !str_contains($lb, 'data-open-release') && !str_contains($lb, 'id="ep-release"'));
	$off = render('ep.clients.edit', $edit(['status' => 'disabled']));
	check('disabled: the badge, and Enable and Decommission instead of Disable', str_contains($off, 'ep-st-off') && str_contains($off, '>Disabled<') && str_contains($off, 'name="op" value="enable"')
		&& str_contains($off, 'name="op" value="decommission"') && !str_contains(body($off), 'name="op" value="disable"'));
	$gone = render('ep.clients.edit', $edit(['status' => 'decommissioned']));
	check('decommissioned: the badge, Restore, and Delete for good…', str_contains($gone, 'ep-st-decom') && str_contains($gone, 'name="op" value="restore"') && str_contains($gone, 'data-open-delete="acme"'));
	$changed = render('ep.clients.edit', $edit(['changed' => [['name' => 'a', 'now' => 'b', 'why' => 'renamed']]]));
	check('changed outside: Put back is offered, with the list\'s confirmation', str_contains($changed, 'name="op" value="putback"') && str_contains($changed, 'changed outside this page, as the page last saw them'));
	$removed = render('ep.clients.edit', $edit(['removed' => [['name' => 'acme-ES-1', 'ip' => '10.0.0.1']]]));
	check('removed from Zabbix: Re-create and Accept removal are offered', str_contains($removed, 'name="op" value="recreate"') && str_contains($removed, 'name="op" value="accept"'));
	$view = render('ep.clients.edit', $edit(['can_write' => false]));
	$dump('edit-ro', $view);
	$vb = body($view);
	check('read only: the status and the dashboard, nothing that changes anything', str_contains($vb, 'ep-st-on') && str_contains($vb, 'host.dashboard.view')
		&& !str_contains($vb, 'name="op"') && !str_contains($vb, 'id="ep-maint"') && !str_contains($vb, 'data-open-maint')
		&& !str_contains($vb, 'data-open-release') && !str_contains($vb, 'id="ep-release"'));

	$add = render('ep.clients.edit', ['mode' => 'add', 'has_cluster' => false, 'status' => 'active', 'masterid' => null, 'form' => $form] + $base);
	$dump('add', $add);
	$ab = body($add);
	check('a new client has no status badge, no actions and no dialogs',
		!str_contains($ab, 'class="ep-head"') && !str_contains($ab, 'ep-st ') && !str_contains($ab, 'name="op"') && !str_contains($ab, 'data-open-maint')
		&& !str_contains($ab, 'id="ep-maint"') && !str_contains($ab, 'host.dashboard.view') && !str_contains($ab, 'data-open-release') && !str_contains($ab, 'id="ep-release"'));

	// The form: the same action, token and fields as before.
	foreach (['edit' => $pb, 'add' => $ab] as $which => $b) {
		check("$which: the form posts to the save action with its token", str_contains($b, '<form method="post" action="zabbix.php?action=ep.clients.save"')
			&& str_contains($b, 'name="_csrf_token" value="tok-ep.clients.save"'));
		preg_match('~<form[^>]*id="ep-client-form".*?</form>~s', $b, $f);
		$names = [];
		preg_match_all('~<(?:input|select|textarea)\b[^>]*\bname="([^"]+)"~', $f[0] ?? '', $m);
		$names = array_unique($m[1]);
		$want = ['mode', 'servers', 'name', 'type', 'purchased_by', 'purchased', 'purchased_devices', 'lead_name', 'cluster_dl', 'alert_dl', 'weekly_report', 'contract_end',
			'es_url', 'es_user', 'es_password_mode', 'es_password_path', 'es_password', 'monitored_by', 'jump_host', 'jump_port', 'jump_user', 'jump_key', 'jump_tls', 'jump_ca',
			'es_apikey_path', 'ulm_bucket', 'ulm_region', 'ulm_auth', 'ulm_role_arn', 'ulm_external_id', 'ulm_access_key_id', 'ulm_secret_path', 'ulm_raw_prefix',
			'ulm_enriched_prefix', 'agent_port', '_csrf_token'];
		foreach (\Modules\EpClients\Lib\Roles::allRoles(\Modules\EpClients\Lib\Roles::defaults()) as $r) {
			foreach (['servers', 'cpu', 'mem', 'disk'] as $s) { $want[] = $r['id'].'_'.$s; }
			for ($n = 2; $n <= \Modules\EpClients\Lib\Roles::DISK_SLOTS; $n++) { $want[] = $r['id'].'_disk'.$n; $want[] = $r['id'].'_disk'.$n.'_fs'; }
		}
		check("$which: every input it posted before is still there", array_diff($want, $names) === [], array_values(array_diff($want, $names)));
		check("$which: the form's own buttons are still Save/Add and Cancel", str_contains($b, $which === 'edit' ? '<button type="submit">Save</button>' : '<button type="submit">Add client</button>')
			&& str_contains($b, 'class="btn-alt ep-btn"'));
	}
	check('the hints are keyboard reachable and named by their input', substr_count($pb, 'aria-describedby="ep-tip-') >= 6 && substr_count($pb, 'role="tooltip"') === substr_count($pb, 'aria-describedby="ep-tip-')
		&& substr_count($pb, 'tabindex="0"') === substr_count($pb, 'role="tooltip"'));
	check('names and labels are never cut with an ellipsis', !str_contains(file_get_contents(__DIR__.'/../views/css/ep.clients.edit.css'), 'text-overflow')
		&& !str_contains(file_get_contents(__DIR__.'/../views/css/ep.clients.edit.css'), 'ellipsis'));

	/* ---------------- the import page ---------------- */
	$importData = fn(array $x = []) => $x + ['stage' => 'upload', 'file' => '', 'plan' => null, 'added' => [], 'backup' => null, 'pending' => [], 'error' => null,
		'store_ok' => true, 'store_dir' => $dir, 'sheets' => [], 'skipped' => [], 'client_count' => 6];
	$imp = render('ep.clients.import', $importData());
	$dump('import', $imp);
	$ib = body($imp);
	check('import: three cards — get the workbook, upload it, review and apply', str_contains($ib, '<h4>1 · Get the workbook</h4>') && str_contains($ib, '<h4>2 · Upload it</h4>')
		&& str_contains($ib, '<h4>3 · Review and apply</h4>') && substr_count($ib, 'class="ep-card"') === 3);
	check('import: Download template — clients.xlsx, from the workbook action', (bool) preg_match('~<a href="zabbix.php\?action=ep.clients.workbook" class="ep-dl ep-dl-main">.*Download template — clients.xlsx</a>~', $ib)
		&& str_contains($ib, 'A filled-in Sample sheet and a note on every column; dropdowns from this Zabbix'));
	check('import: Export current clients — clients.xlsx, with the count', (bool) preg_match('~<a href="zabbix.php\?action=ep.clients.workbook&amp;what=export" class="ep-dl">.*Export current clients — clients.xlsx</a>~', $ib)
		&& str_contains($ib, 'Your 6 clients and their servers, ready to edit and import back.'));
	check('import: one file input, .xlsx and the old .csv files, several allowed', preg_match_all('~<input type="file"~', $ib) === 1
		&& (bool) preg_match('~<input type="file" name="file\[\]" multiple="multiple" accept="\.xlsx,\.csv[^"]*" id="ep-file"~', $ib));
	check('import: the upload posts multipart to the import action with its token, and says Check file',
		(bool) preg_match('~<form method="post" action="zabbix.php\?action=ep.clients.import" enctype="multipart/form-data">~', $ib)
		&& str_contains($ib, 'name="_csrf_token" value="tok-ep.clients.import"') && str_contains($ib, '<button type="submit" name="check">Check file</button>'));
	check('import: the rules', str_contains($ib, 'One error and nothing is applied.') && str_contains($ib, 'a column you leave out means &quot;no change&quot;') === false
		&& str_contains($ib, 'A column you leave out means &quot;no change&quot;; an empty cell means &quot;clear&quot;.') && str_contains($ib, 'Clients missing from the file are not removed.'));
	check('import: the old CSV templates are still linked, small', str_contains($ib, 'href="zabbix.php?action=ep.clients.csv.template"') && str_contains($ib, 'action=ep.clients.csv.template&amp;kind=servers'));
	check('import: before a check, the review says what it will show and does not span the width', str_contains($ib, 'Check a file to see') && !str_contains($ib, 'ep-wide'));
	check('import: the view carries no colour; the sheet has light and dark tokens', !preg_match('/#[0-9a-f]{3,6}\b/i', preg_replace('~<style>.*</style>~s', '', file_get_contents(__DIR__.'/../views/ep.clients.import.php')))
		&& str_contains($imp, '.ep-imp { --ep-card:') && str_contains($imp, '[theme="dark-theme"] .ep-imp'));
	$errPlan = ['rows' => [], 'ignored' => [], 'errors' => ['Servers, row 14 (10.0.0.31): Role 2 is "ES Hot" — not one of the roles. Pick it from the dropdown, or leave it empty.']];
	$err = render('ep.clients.import', $importData(['stage' => 'errors', 'file' => 'clients.xlsx', 'plan' => $errPlan, 'skipped' => ['Sample'],
		'sheets' => [['name' => 'Clients', 'rows' => 7, 'new' => 1, 'changed' => 2, 'errors' => 0], ['name' => 'Servers', 'rows' => 52, 'new' => 4, 'changed' => 1, 'errors' => 1]]]));
	$dump('import-errors', $err);
	$eb = body($err);
	check('import: after a check, a table per sheet — rows, new, changed, errors', str_contains($eb, '<th>Sheet</th><th>Rows</th><th>New</th><th>Changed</th><th>Errors</th>')
		&& str_contains($eb, '<tr><td>Servers</td><td>52</td><td class="ep-n-new">4</td><td>1</td><td class="ep-n-err">1</td></tr>')
		&& str_contains($eb, '<tr><td>Clients</td><td>7</td><td class="ep-n-new">1</td><td>2</td><td>0</td></tr>'), $eb);
	check('import: the errors, by sheet, row and column, nothing applied, and the review spans the width', str_contains($eb, 'role="alert"') && str_contains($eb, 'Nothing was applied.')
		&& str_contains($eb, 'Servers, row 14 (10.0.0.31): Role 2 is &quot;ES Hot&quot; — not one of the roles.') && str_contains($eb, 'ep-card ep-wide') && str_contains($eb, 'Not read: Sample.'));
	$pending = [['name' => 'acme', 'status' => 'update', 'type' => 'DI', 'warnings' => [], 'diff' => ['fields' => [['field' => 'Type', 'old' => 'CI', 'new' => 'DI']],
		'hosts' => [['change' => 'add', 'ip' => '10.0.0.9', 'roles' => 'Parser']]]],
		['name' => 'globex', 'status' => 'update', 'type' => 'On-Prem', 'warnings' => [], 'diff' => ['fields' => [], 'hosts' => [['change' => 'delete', 'ip' => '10.0.0.5', 'host' => 'globex-Parser-1', 'roles' => 'Parser']]]]];
	$done = render('ep.clients.import', $importData(['stage' => 'done', 'file' => 'clients.xlsx', 'plan' => ['rows' => $pending, 'errors' => [], 'ignored' => []], 'pending' => $pending,
		'token' => 'abc123', 'backup' => 'b1', 'added' => [['name' => 'bajaj', 'done' => ['Created bajaj.'], 'problems' => []]],
		'sheets' => [['name' => 'Clients', 'rows' => 3, 'new' => 1, 'changed' => 1, 'errors' => 0]]]));
	$dump('import-done', $done);
	$db = body($done);
	check('import: the apply flow is the one it was — same action, token, pending token, a tick per client', str_contains($db, '<form method="post" action="zabbix.php?action=ep.clients.import.apply">')
		&& str_contains($db, 'name="_csrf_token" value="tok-ep.clients.import.apply"') && str_contains($db, 'name="token" value="abc123"')
		&& str_contains($db, '<input type="checkbox" name="apply[acme]" value="1" checked="checked" id="apply_acme">')
		&& str_contains($db, '<input type="checkbox" name="apply[globex]" value="1" id="apply_globex">') && str_contains($db, '<button type="submit" name="apply-selected">Apply ticked changes</button>'), $db);
	check('import: new clients added straight away, read back, after a backup', str_contains($db, 'Backup taken.') && str_contains($db, '1 new client added: bajaj.') && str_contains($db, 'Read back — matches the file.'));
	check('import: a deleted host is named and not ticked for you', str_contains($db, '− globex-Parser-1 (10.0.0.5) — deleted with its history'));

	foreach (array_diff(scandir($dir), ['.', '..']) as $fn) { is_dir($dir.'/'.$fn) ? null : unlink($dir.'/'.$fn); }
	@rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
