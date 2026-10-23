<?php declare(strict_types = 0);
/**
 * The list and the client page, rendered for real against stand-ins for Zabbix's page classes.
 *
 *   php test/views.test.php            checks
 *   EP_VIEWS_OUT=/dir php test/views.test.php   also writes list.html, list-ro.html, edit.html, edit-ro.html, add.html,
 *                                                import.html, import-errors.html, import-done.html
 *
 * What it holds the pages to: the list's row has one Edit link and the board's columns; the client page
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
		public function setTitle($v) { return $this->setAttribute('title', $v); }
		public function onClick($js) { return $this->setAttribute('onclick', $js); }
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
		private $i = []; private $controls = null;
		public function setTitle($t) { return $this; }
		public function setControls($c) { $this->controls = $c; return $this; }
		public function addItem($i) { $this->i[] = $i; return $this; }
		public function show() { if ($this->controls) { echo $this->controls->toString(); } foreach ($this->i as $i) { echo $i->toString(); } return $this; }
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
		public function setNoDataMessage($m) { return $this; }
		public function setHeader($h) { $tr = new CTag('tr'); foreach ($h as $c) { $tr->addItem(new CTag('th', true, $c)); } return $this->addItem($tr); }
		public function addRow($cells) { $tr = new CTag('tr'); foreach ($cells as $c) { $tr->addItem((new CTag('td'))->addItem($c)); } return $this->addItem($tr); }
	}
}
namespace Modules\EpClients\Test {
	foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'Forecast', 'ClientTypes', 'Bars', 'MasterTemplate', 'DevicesTemplate', 'JumpTemplate', 'ClusterTemplate', 'ClientSpec', 'SetupChecks', 'ClientActions', 'EpRole', 'Reconciler', 'Discovery', 'ListColumns'] as $lib) {
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

	/* ---------------- backups: a partial one says so ---------------- */
	$bk = render('ep.clients.backups', ['store_ok' => true, 'store_dir' => '/var/lib/elasticpro-zabbix', 'backups' => [
		['id' => '20261023-010000-aaaa', 'taken' => 1792717200, 'by' => 'Admin', 'before' => 'Save acme', 'clients' => 7, 'machines' => 30, 'partial' => false, 'scope_label' => null],
		['id' => '20261023-020000-bbbb', 'taken' => 1792720800, 'by' => 'ep-qa-admin', 'before' => 'Save north', 'clients' => 3, 'machines' => 9, 'partial' => true, 'scope_label' => 'partial: 3 of 7 clients']]]);
	check('backups: a partial backup is labelled "partial: N of M clients", a full one is not', substr_count(body($bk), 'partial: 3 of 7 clients') >= 1
		&& substr_count(body($bk), 'class="ep-partial"') === 1, body($bk));
	check('backups: restoring a partial one says every other client is left alone', str_contains($bk, 'Every other client is left alone')
		&& str_contains($bk, 'Clients added since are removed'));

	/* ---------------- the list ---------------- */
	$client = fn(string $name, string $status, array $x = []) => $x + ['name' => $name, 'masterid' => '1'.crc32($name) % 90, 'status' => $status, 'maintenance' => null,
		'type' => 'CI', 'es_url' => 'https://es.'.$name.'.example:9200', 'jump' => '', 'archive' => '', 'lead' => 'Asha Rao', 'dl' => 'soc-'.$name.'@example.com',
		'contract_end' => '', 'machines' => '3 ES · 1 Parser', 'unassigned' => 0, 'shared' => 0, 'removed' => [], 'changed' => [], 'checks' => [], 'problems' => [],
		'full_in' => null, 'over' => [], 'change' => null, 'search' => $name, 'master_missing' => false,
		'cluster_host' => $name.'-ES-Cluster', 'beside' => [], 'password' => 'default', 'last_seen' => time() - 120, 'missing' => [],
		'roles' => ['es_data_hot' => ['family' => 'es', 'n' => 3, 'requested' => 3, 'hosts' => ['10.0.0.1 · es-1', '10.0.0.2 · es-2', '10.0.0.3 · es-3'], 'shared' => 0],
			'parser' => ['family' => 'parser', 'n' => 0, 'requested' => 1, 'hosts' => [], 'shared' => 0]]];
	$clients = [
		$client('acme', 'active', ['missing' => [['key' => 'password_core', 'text' => 'ElasticPro has not been given it', 'fix' => 'x']], 'beside' => ['Old ES template']]),
		$client('globex', 'active', ['maintenance' => ['since' => time() - 600, 'till' => time() + 3600, 'collect' => true, 'reason' => 'ES upgrade'],
			'changed' => [['name' => 'globex-ES-1', 'now' => 'old-name', 'why' => 'renamed']]]),
		$client('initech', 'disabled'),
		$client('umbrella', 'decommissioned'),
		$client('lost', 'active', ['masterid' => null, 'master_missing' => true]),
	];
	$listData = fn(bool $write) => ['clients' => $clients, 'can_write' => $write, 'store_ok' => true, 'store_dir' => $dir, 'template' => 'current',
		'not_adopted' => 3, 'columns' => \Modules\EpClients\Lib\ListColumns::defaults(\Modules\EpClients\Lib\Roles::defaults()), 'backups' => 3, 'forecast' => []];
	$list = render('ep.clients.list', $listData(true));
	$dump('list', $list);
	$lb = body($list);
	preg_match_all('~<tr data-tabs=.*?</tr>~s', $lb, $rows);
	check('the list has a row per client', count($rows[0]) === 5, count($rows[0]));
	$acts = fn(string $row) => preg_match('~<div class="ep-acts">(.*?)</div></td></tr>~s', $row, $m) ? $m[1] : '';
	foreach (['Maintenance…', 'Extend…', 'Disable', 'Enable', 'Dashboard', 'Clone', 'Put back', 'Decommission', 'Restore', 'Delete for good…', 'End maintenance'] as $text) {
		foreach ($rows[0] as $i => $row) {
			if ($i === 4) { continue; }
			check("row $i has no \"$text\" action: those are on the client's page", !preg_match('~>'.preg_quote($text, '~').'</(a|button)>~u', $acts($row)));
		}
	}
	foreach ($rows[0] as $i => $row) {
		if ($i === 4) { continue; }
		$a = $acts($row);
		check("row $i has exactly one control, Edit, opening that client's page", substr_count($a, '<a ') + substr_count($a, '<button') + substr_count($a, '<form') === 1
			&& str_contains($a, 'class="ep-edit"') && str_contains($a, 'action=ep.clients.edit&amp;client='.$clients[$i]['name']) && str_contains($a, '>Edit</a>'), $a);
	}
	check('the board\'s columns: Client, a column per role family that has servers, Templates, Password, Last seen, Missing; no Problems, no Disk full in',
		str_contains($lb, 'data-col="family:es">ES<') && !str_contains($lb, 'data-col="family:engine"') && str_contains($lb, '>Templates<') && str_contains($lb, '>Password<')
		&& str_contains($lb, '>Last seen<') && str_contains($lb, '>Missing<') && !str_contains($lb, '>Problems<') && !str_contains($lb, '>Disk full in<'));
	check('a family the plan asks for and no server holds: "0 of 1"', str_contains($lb, 'data-col="family:parser"') && str_contains($lb, '0 of 1'));
	check('the role count lists IP · server name on hover', str_contains($lb, 'title="10.0.0.1 · es-1'));
	check('the cluster host under the client, the older template beside, the Missing chip', str_contains($lb, 'acme-ES-Cluster') && str_contains($lb, '+ 1 older beside')
		&& str_contains($lb, '>Missing 1</a>'));
	check('the filters at the top, the summary line in the footer under the table', str_contains($lb, 'data-filter="missing"') && str_contains($lb, 'id="ep-status"')
		&& strpos($lb, 'class="ep-sumline"') > strpos($lb, '</tbody></table></div>') && str_contains($lb, '3 hosts not adopted') && str_contains($lb, '1 with missing pieces'));
	check('empty role columns are hidden and named in the footer', str_contains($lb, 'role columns hidden:') && str_contains($lb, 'Trino (ULM)'));
	check('the Columns popover posts to its action with a token', str_contains($lb, 'action="zabbix.php?action=ep.clients.columns.save"') && str_contains($lb, 'value="tok-ep.clients.columns.save"'));
	check('a client whose master host is gone keeps Re-create and Forget on the list, as it has no page to open',
		str_contains($acts($rows[0][4]), '>Re-create</button>') && str_contains($acts($rows[0][4]), '>Forget</button>') && !str_contains($acts($rows[0][4]), 'ep-edit'));
	check('the list still offers the bulk bar and the Maintenance and Delete dialogs', str_contains($lb, 'id="ep-bulk"') && str_contains($lb, 'id="ep-maint"') && str_contains($lb, 'id="ep-delete"'));
	check('the list wires its dialogs through the shared script', str_contains($list, 'window.EpDialogs.init(root, ticked)') && str_contains($list, 'window.EpDialogs = '));
	$ro = render('ep.clients.list', $listData(false));
	$dump('list-ro', $ro);
	preg_match_all('~<tr data-tabs=.*?</tr>~s', body($ro), $rrows);
	check('read only: one "View" link per row, no dialogs, no Columns popover', str_contains($acts($rrows[0][0]), 'aria-label="View acme"') && !str_contains(body($ro), 'id="ep-maint"')
		&& !str_contains(body($ro), 'id="ep-cols"'));
	$ro = render('ep.clients.list', ['write_note' => ['Editing needs an ElasticPro admin role (or Zabbix Super admin). Your ElasticPro role: operator.', null]] + $listData(false));
	check('read only: the list says who may edit and this person\'s ElasticPro role',
		str_contains(body($ro), '<div class="ep-note">Editing needs an ElasticPro admin role (or Zabbix Super admin). Your ElasticPro role: operator. You see the clients whose hosts you may read.</div>'), body($ro));
	$ro = render('ep.clients.list', ['write_note' => ['ElasticPro could not be asked who may edit; only Super admins can edit until it answers.', 'ElasticPro did not answer at http://ep-core:8765: timeout']] + $listData(false));
	check('ElasticPro not answering: said, with why', str_contains(body($ro), 'ElasticPro could not be asked who may edit; only Super admins can edit until it answers.')
		&& str_contains(body($ro), '<div class="ep-note">Why: ElasticPro did not answer at http://ep-core:8765: timeout.</div>'), body($ro));
	check('no note when the page is writable', !str_contains(body($list), 'Editing needs'));
	// The header buttons. Backups is a Super admin's only (BackupList), so the list offers it only
	// when it was given a count — ClientList gives null to everyone else, an ElasticPro admin who is
	// a Zabbix Admin included, who would otherwise press it into "Access denied".
	$nav = fn(string $html) => preg_match('~<nav\b.*?</nav>~s', $html, $m) ? $m[0] : '';
	check('a Super admin\'s list offers Import / export, Backups (count), Roles and Add client',
		str_contains($nav($list), '<button>Import / export</button>') && str_contains($nav($list), '<button>Backups (3)</button>')
		&& str_contains($nav($list), '<button>Roles</button>') && str_contains($nav($list), '<button>Add client</button>'), $nav($list));
	$nb = render('ep.clients.list', ['backups' => null] + $listData(true));
	check('an editor who is not a Super admin gets the same buttons but no Backups',
		!str_contains($nav($nb), 'Backups') && str_contains($nav($nb), '<button>Import / export</button>')
		&& str_contains($nav($nb), '<button>Roles</button>') && str_contains($nav($nb), '<button>Add client</button>'), $nav($nb));
	check('an editor, Super admin or not, is offered Discover clusters first', str_contains($nav($list), '<button>Discover clusters</button>')
		&& str_contains($nav($nb), '<button>Discover clusters</button>') && strpos($nav($nb), 'Discover clusters') < strpos($nav($nb), 'Import / export'));
	check('a read-only list has no header buttons at all', !str_contains($nav(render('ep.clients.list', ['backups' => null] + $listData(false))), '<button'));

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
	$view = render('ep.clients.edit', $edit(['can_write' => false, 'write_note' => ['Editing needs an ElasticPro admin role (or Zabbix Super admin). Your ElasticPro role: operator.', null]]));
	check('read only: the client page says who may edit and this person\'s ElasticPro role',
		str_contains(body($view), '<div class="ep-notes"><div class="ep-note">Editing needs an ElasticPro admin role (or Zabbix Super admin). Your ElasticPro role: operator.</div></div>'));
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

	/* ---------------- Discover clusters (the approved board) ---------------- */
	$hostRow = fn(array $x) => $x + ['hostid' => '1', 'host' => 'h', 'name' => 'h', 'kind' => 'server', 'ip' => '10.0.0.1', 'proxy_label' => 'Zabbix server', 'monitored_by' => '',
		'already' => null, 'roles' => [], 'role_source' => null, 'server_name' => '', 'gaps' => [], 'source' => 'group', 'source_detail' => 'host group "x"', 'generation' => null,
		'es' => null, 'groups' => [], 'templates' => [], 'es_templates' => []];
	$planOf = fn(array $x) => $x + ['blockers' => [], 'skipped' => [], 'adopting' => 0, 'moves' => [], 'writes' => [], 'cluster' => null, 'servers' => [], 'form' => []];
	$scanData = ['at' => 1790000000, 'notes' => ['Not proposed — stray-es.'], 'proxies' => ['proxy-dc1', 'proxy-dc2'], 'proxy_groups' => [],
		'unattached' => [$hostRow(['host' => 'lonely', 'source_detail' => 'nothing points at a cluster'])],
		'proposals' => [
			['id' => 'p1', 'name' => 'globex-prod', 'status' => 'new', 'source' => 'cluster', 'cluster' => 'globex-prod',
				'hosts' => [
					$hostRow(['hostid' => '10', 'host' => 'globex-prod', 'kind' => 'cluster', 'generation' => 'unrecognised', 'groups' => ['Prod Elasticsearch'],
						'templates' => ['Old ES template'], 'es_templates' => ['Old ES template'], 'es' => ['password' => 'secret', 'url' => 'https://es.g:9200']]),
					$hostRow(['hostid' => '11', 'host' => '10.3.0.1', 'ip' => '10.3.0.1', 'name' => 'gx-data-a', 'server_name' => 'gx-data-a', 'roles' => ['es_data_hot'], 'role_source' => 'group']),
					$hostRow(['hostid' => '12', 'host' => 'kfk-1', 'ip' => '10.3.0.2', 'roles' => ['forwarder'], 'role_source' => 'kafka', 'gaps' => ['kafka: confirm Forwarder or Parser']])],
				'choices' => ['selected' => true, 'name' => 'globex-prod', 'hosts' => ['10' => true, '11' => true, '12' => true], 'roles' => ['11' => ['es_data_hot'], '12' => ['forwarder']],
					'move' => ['11' => true], 'es_url' => 'https://es.g:9200', 'password' => ''],
				'missing' => [['key' => 'client_group', 'text' => 'No client group', 'fix' => 'Adopting creates it'], ['key' => 'password_core', 'text' => 'ElasticPro has not been given it', 'fix' => 'Change password']],
				'plan' => $planOf(['adopting' => 3, 'cluster' => ['host' => 'globex-prod'], 'form' => ['monitored_by' => '', 'servers_monitored_by' => 'server'],
					'servers' => ['11' => ['row' => ['host' => '10.3.0.1', 'monitored_by' => '']]],
					'writes' => [['backup', 'Take a backup'], ['group', 'Create host group "globex-prod".'], ['link', 'Link to "globex-prod", directly and in one step (host.massadd): "Elasticsearch Cluster by HTTP EP".']]])],
			['id' => 'p3', 'name' => 'blocked-es', 'status' => 'new', 'source' => 'cluster', 'cluster' => 'blocked-es',
				'hosts' => [$hostRow(['hostid' => '30', 'host' => 'blocked-es', 'kind' => 'cluster', 'generation' => 'none', 'es' => ['password' => 'none', 'url' => '']])],
				'choices' => ['selected' => false, 'name' => 'blocked-es', 'hosts' => [], 'roles' => [], 'move' => [], 'es_url' => '', 'password' => ''],
				'missing' => [], 'plan' => $planOf(['blockers' => ['No host is ticked that can be adopted.']])],
			['id' => 'p2', 'name' => 'acme', 'status' => 'client', 'source' => 'cluster', 'cluster' => 'acme-ES-Cluster',
				'hosts' => [$hostRow(['hostid' => '20', 'host' => 'acme-ES-Cluster', 'kind' => 'cluster', 'already' => 'acme', 'generation' => 'current'])],
				'choices' => ['selected' => false, 'name' => 'acme', 'hosts' => [], 'roles' => [], 'move' => [], 'es_url' => '', 'password' => ''], 'missing' => [], 'plan' => $planOf([])]
		]];
	$disc = render('ep.clients.discover', ['scan' => $scanData, 'token' => 'tk', 'error' => null, 'results' => null, 'store_ok' => true, 'store_dir' => $dir, 'can_write' => true,
		'roles' => \Modules\EpClients\Lib\Roles::defaults()]);
	$dump('discover', $disc);
	$db = body($disc);
	check('discover: the form posts to the apply action with its token and the scan token', str_contains($db, 'action="zabbix.php?action=ep.clients.discover.apply"')
		&& str_contains($db, 'value="tok-ep.clients.discover.apply"') && str_contains($db, 'name="token" value="tk"'));
	check('discover: the rule box, and one row per cluster', str_contains($db, 'is a cluster. Its host name becomes the cluster name') && substr_count($db, 'data-proposal=') === 3);
	check('discover: the new cluster ticked, its name editable', str_contains($db, 'name="sel[p1]" value="1" checked="checked" id="sel_p1"') && str_contains($db, 'name="name[p1]" value="globex-prod"'));
	check('discover: a refused row cannot be ticked, and says why', (bool) preg_match('~name="sel\[p3\]"[^>]*disabled~', $db) && str_contains($db, 'Refused until fixed'));
	check('discover: a refused cluster host with no ES URL carries its real host id, so a typed URL can be adopted; one already a client carries none',
		str_contains($db, 'name="host[p3][30]" value="1"') && !str_contains($db, 'name="host[p2][20]"'));
	check('discover: already a client hidden by default behind its switch', (bool) preg_match('~<tr[^>]*class="ep-already"[^>]*hidden="hidden"~', $db) && str_contains($db, 'Show hosts that are already a client (1)'));
	check('discover: host groups, "no client group: will create", templates with their generation', str_contains($db, 'Prod Elasticsearch') && str_contains($db, 'no client group: will create globex-prod')
		&& str_contains($db, 'Old ES template') && str_contains($db, 'name not known here · kept beside'));
	check('discover: role hosts as IP · server name, the kafka host with its dropdown and flag', str_contains($db, '10.3.0.1</span> · gx-data-a') && str_contains($db, 'name="role[p1][12][]"')
		&& str_contains($db, 'value="kafka:forwarder" selected="selected"') && str_contains($db, 'kafka: confirm Forwarder or Parser'));
	check('discover: Missing N with its reasons, and the kafka role to confirm', str_contains($db, 'Missing 2') && str_contains($db, 'no client group · password not given to ElasticPro')
		&& str_contains($db, '1 role to confirm (kafka)'));
	check('discover: the summary: two proxy choices, what it writes, the five templates by name, one confirm', str_contains($db, 'name="proxy_es"') && str_contains($db, 'name="proxy_linux"')
		&& str_contains($db, 'Adopting 1 selected will:') && str_contains($db, 'delete nothing') && str_contains($db, 'linked directly to each cluster host')
		&& str_contains($db, 'Adopt selected (1)') && str_contains($db, 'name="do" value="adopt"'));
	check('discover: an optional password field in the details, always empty', str_contains($db, 'name="pw[p1]" value=""'));
	check('discover: the unattached role host and the note are shown', str_contains($db, 'lonely') && str_contains($db, 'stray-es'));
	$settingsView = (string) file_get_contents(__DIR__.'/../views/ep.clients.settings.php');
	check('settings: the default credential has its own form, a keep-marker, and never a password value',
		str_contains($settingsView, "ep.clients.credential.save") && str_contains($settingsView, "'es_password_action'") && !preg_match("~'es_password'\)->setAttribute\('value', \$~", $settingsView));

	/* ---------------- the password: never shown, a keep-marker, "Change password" ---------------- */
	preg_match('~<input type="password" id="es_password"[^>]*>~', $ab, $pwi);
	check('a new client shows the password field, marked "set"', isset($pwi[0]) && !str_contains($pwi[0], 'hidden') && str_contains($ab, 'name="es_password_action" value="set"'), $pwi);
	$kept = render('ep.clients.edit', $edit(['password' => ['stored' => true, 'record' => ['at' => 1790000000, 'by' => 'alice', 'core' => true, 'core_error' => null]]],
		['es_password_mode' => 'zabbix']));
	$kb = body($kept);
	preg_match('~<input type="password" id="es_password"[^>]*>~', $kb, $pwi);
	check('a kept password: no field until "Change", and the marker says keep', str_contains($kb, 'id="ep-pw-new" hidden')
		&& str_contains($kb, 'name="es_password_action" value="keep"') && str_contains($kb, 'id="ep-pw-change"') && str_contains($kb, 'id="ep-pw-keep"'), $pwi);
	check('…it says who set it and when, and nothing about ElasticPro lacking it', (bool) preg_match('~Set \d{4}-\d{2}-\d{2} by alice~', $kb) && !str_contains($kb, 'ElasticPro was not given it'));
	check('…and never a value in the password field', !preg_match('~id="es_password"[^>]*value="[^"]+~', $kb));
	$legacyPw = body(render('ep.clients.edit', $edit(['password' => ['stored' => true, 'record' => null]], ['es_password_mode' => 'zabbix'])));
	check('a password kept from before this page recorded it says so, and that ElasticPro lacks it', str_contains($legacyPw, 'Set outside this page; ElasticPro was not given it'));
	$roPw = body(render('ep.clients.edit', $edit(['can_write' => false, 'password' => ['stored' => true, 'record' => null]], ['es_password_mode' => 'zabbix'])));
	check('read only: no "Change password"', !str_contains($roPw, 'id="ep-pw-change"'));
	$miss = body(render('ep.clients.edit', $edit(['missing' => [['key' => 'password_core', 'text' => 'ElasticPro has not been given it', 'fix' => 'Press Change password']]])));
	check('the Missing panel lists each item with what to do', str_contains($miss, 'ep-missing') && str_contains($miss, 'data-missing="password_core"')
		&& str_contains($miss, 'Press Change password'));

	/* ---------------- the client page (board Client-page) and the compact form (board Edit-after) ---------------- */
	$cp = body(render('ep.clients.edit', $edit(['missing' => [
			['key' => 'role_confirm', 'text' => 'Role to confirm: 10.0.0.9 · kfk is a Kafka server', 'fix' => 'Confirm Forwarder or Parser'],
			['key' => 'old_generation', 'text' => 'An older template generation is still linked', 'fix' => 'It stays beside']],
		'by_role' => ['es_data_hot' => [['ip' => '10.0.0.11', 'name' => 'es-hot-1', 'host' => '10.0.0.11', 'confirm' => ''], ['ip' => '10.0.0.12', 'name' => '', 'host' => '10.0.0.12', 'confirm' => '']],
			'forwarder' => [['ip' => '10.0.0.9', 'name' => 'kfk', 'host' => '10.0.0.9', 'confirm' => 'kafka']]],
		'cluster_info' => ['host' => 'acme-ES-Cluster', 'hostid' => '501', 'templates' => ['Elasticsearch Cluster by HTTP EP', 'Old ES template', 'Odd ES template', 'Linux extra'],
			'beside' => ['known' => ['Old ES template'], 'unknown' => ['Odd ES template']]], 'master_host' => 'acme-Master',
		'last_seen' => 0, 'full_in' => null, 'forecast' => [],
		'default_credential' => ['set' => true, 'user' => 'svc', 'record' => ['at' => 1790000000, 'by' => 'alice']], 'proxy_groups' => []],
		['servers_monitored_by' => 'server', 'retention_days' => '', 'backup_gb' => '250'])));
	$dump('client-page', $cp);
	check('client page: the five tabs, Overview first and open', str_contains($cp, 'data-tab="overview" aria-selected="true"') && str_contains($cp, 'data-tab="servers"')
		&& str_contains($cp, 'data-tab="templates"') && str_contains($cp, 'data-tab="connection"') && str_contains($cp, 'data-tab="backups"')
		&& str_contains($cp, 'data-panel="overview">') && str_contains($cp, 'data-panel="form" hidden'));
	check('client page: the Missing panel at the top of Overview, each line with its Fix', strpos($cp, 'id="ep-missing"') < strpos($cp, 'Servers by role')
		&& str_contains($cp, 'Missing: 2 things could not be determined or set') && str_contains($cp, 'data-goto="servers">Fix: Servers &amp; roles')
		&& str_contains($cp, 'data-goto="templates">Fix: Templates'));
	check('client page: Beside names the older generation and the template whose name is not known here, not "none"',
		(bool) preg_match('~ep-k">Beside</div><div><span class="ep-flag">Old ES template</span>.*Odd ES template</span> <span class="ep-hint">name not known here~', $cp));
	check('client page: the Templates tab says what each linked template is', str_contains($cp, 'Old ES template</td><td class="ep-hint">older generation, kept beside')
		&& str_contains($cp, 'Odd ES template</td><td class="ep-hint">gives the Elasticsearch items under a name this module does not know')
		&& str_contains($cp, 'Linux extra</td><td class="ep-hint">another template, left as it is'));
	check('client page: servers by role as IP · server name, unknown said, kafka flagged, empty roles "none"', str_contains($cp, '10.0.0.11</span> · es-hot-1')
		&& str_contains($cp, '10.0.0.12</span> · <span class="ep-hint">server name unknown') && str_contains($cp, 'kafka: confirm Forwarder or Parser')
		&& (bool) preg_match('~ep-k">Parser</div><div><span class="ep-hint">none~', $cp));
	check('client page: last seen never, disk full unknown, retention not set, backup 250 GB', str_contains($cp, 'Last seen</div><div><span class="ep-hint">never')
		&& str_contains($cp, 'unknown: not measured yet') && str_contains($cp, 'Retention</div><div><span class="ep-hint">not set') && str_contains($cp, '250 GB'));
	check('client page: the Templates tab names each template and what it is; the ones not linked yet', str_contains($cp, '<td>Linux extra</td><td class="ep-hint">another template, left as it is')
		&& str_contains($cp, 'not linked yet: saving links it'));
	check('form: two packed columns (Client, Capacity | Connection, Log archive), Servers & roles under them, and the action bar',
		substr_count($cp, 'class="ep-col"') === 2 && str_contains($cp, 'class="ep-cols"')
		&& strpos($cp, 'id="ep-card-client"') < strpos($cp, 'id="ep-card-capacity"') && strpos($cp, 'id="ep-card-capacity"') < strpos($cp, 'class="ep-col"', strpos($cp, 'id="ep-card-capacity"'))
		&& strpos($cp, 'id="ep-card-connection"') < strpos($cp, 'id="ep-archive"') && strpos($cp, 'id="ep-archive"') < strpos($cp, 'id="ep-card-servers"')
		&& str_contains($cp, 'id="ep-dirty"'));
	check('form: compact hooks — label and (i) share a cell, no hint column, "No servers yet" beside + Add server', str_contains($cp, '<div class="ep-lab"><label')
		&& str_contains($cp, 'class="ep-noservers">No servers yet</span><button type="button" class="ep-lnk" id="ep-add-server"')
		&& preg_match_all('~<div class="ep-row"><div class="ep-lab">~', $cp) >= 8);
	check('form: "This DL receives" alert e-mails and the weekly report; notes', str_contains($cp, 'This DL receives') && str_contains($cp, 'name="alert_dl"')
		&& str_contains($cp, 'name="weekly_report"') && str_contains($cp, 'name="client_notes"'));
	check('form: Credential Default / This cluster only, the default named with who set it', str_contains($cp, 'Default (svc, set ') && str_contains($cp, 'This cluster only')
		&& str_contains($cp, 'name="es_password_mode"') && str_contains($cp, 'Change default in Settings'));
	check('form: Route Direct / Jump host and the cluster host\'s Zabbix proxy, default the Zabbix server', str_contains($cp, 'name="ep_route"') && str_contains($cp, 'id="ep_es_proxy"')
		&& str_contains($cp, 'Zabbix server (default)') && str_contains($cp, 'name="monitored_by"'));
	check('form: Linux hosts monitored by, its own choice, set to the Zabbix server', (bool) preg_match('~<select aria-describedby="ep-tip-srv" id="servers_monitored_by" name="servers_monitored_by">.*?<option value="server" selected>Zabbix server \(default\)~s', $cp));
	check('form: Retention and Backup capacity, empty shown as "not set"', str_contains($cp, 'name="retention_days" value="" placeholder="not set"') && str_contains($cp, 'name="backup_gb" value="250"'));
	check('form: the contract end the compact form does not show is still posted, so a save never blanks it', str_contains($cp, 'type="hidden" name="contract_end" value="2027-03-31"'));
	$addP = body(render('ep.clients.edit', ['mode' => 'add', 'has_cluster' => false, 'status' => 'active', 'masterid' => null, 'form' => $spec->newForm() + $form] + $base));
	check('a new client: no tabs, the form shown at once', !str_contains($addP, 'role="tablist"') && str_contains($addP, 'data-panel="form">'));

	// The form: the same action, token and fields as before.
	foreach (['edit' => $pb, 'add' => $ab] as $which => $b) {
		check("$which: the form posts to the save action with its token", str_contains($b, '<form method="post" action="zabbix.php?action=ep.clients.save"')
			&& str_contains($b, 'name="_csrf_token" value="tok-ep.clients.save"'));
		preg_match('~<form[^>]*id="ep-client-form".*?</form>~s', $b, $f);
		$names = [];
		preg_match_all('~<(?:input|select|textarea)\b[^>]*\bname="([^"]+)"~', $f[0] ?? '', $m);
		$names = array_unique($m[1]);
		$want = ['mode', 'servers', 'name', 'type', 'purchased_by', 'purchased', 'purchased_devices', 'lead_name', 'cluster_dl', 'alert_dl', 'weekly_report', 'contract_end',
			'client_notes', 'retention_days', 'backup_gb', 'servers_monitored_by', 'es_password_action',
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
		'store_ok' => true, 'store_dir' => $dir, 'sheets' => [], 'skipped' => [], 'client_count' => 6, 'failed' => []];
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

	// A new client Zabbix refused: never counted as added, shown as an error with what exists now.
	$refused = render('ep.clients.import', $importData(['stage' => 'done', 'file' => 'clients.xlsx', 'plan' => ['rows' => [], 'errors' => [], 'ignored' => []],
		'backup' => 'b1', 'added' => [], 'failed' => [['name' => 'QA-Excel', 'error' => 'Nothing was saved. These templates are not in Zabbix: ElasticPro log archive S3.', 'done' => [], 'exists' => []]],
		'sheets' => [['name' => 'Clients', 'rows' => 1, 'new' => 0, 'changed' => 0, 'errors' => 1]]]));
	$dump('import-refused', $refused);
	$rb = body($refused);
	check('import: a refused new client is not in the added count and is an error', !str_contains($rb, 'new client added') && !str_contains($rb, 'msg-good')
		&& str_contains($rb, 'class="msg-bad ep-box" role="alert"') && str_contains($rb, '<b>QA-Excel was not added: </b>Nothing was saved. These templates are not in Zabbix')
		&& str_contains($rb, 'Nothing of QA-Excel is in Zabbix.') && str_contains($rb, 'Backup taken.'), $rb);
	$partial = body(render('ep.clients.import', $importData(['stage' => 'done', 'file' => 'clients.xlsx', 'plan' => ['rows' => [], 'errors' => [], 'ignored' => []],
		'added' => [['name' => 'ok-1', 'done' => ['Created "ok-1-Master".'], 'problems' => []]],
		'failed' => [['name' => 'half', 'error' => 'No permissions.', 'done' => ['Created host group "half".', 'Created "half-Master".'], 'exists' => ['half-Master']]]])));
	check('import: some added, one refused half-way — counted apart, and what exists now is named', str_contains($partial, '1 new client added: ok-1.')
		&& str_contains($partial, '<b>half was not added: </b>No permissions. Written before it stopped: Created host group &quot;half&quot;. Created &quot;half-Master&quot;. In Zabbix now: half-Master.'), $partial);

	foreach (array_diff(scandir($dir), ['.', '..']) as $fn) { is_dir($dir.'/'.$fn) ? null : unlink($dir.'/'.$fn); }
	@rmdir($dir);
	echo "$passed passed, $failed failed", PHP_EOL;
	exit($failed ? 1 : 0);
}
