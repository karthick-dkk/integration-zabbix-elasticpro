<?php
/**
 * The sidebar: the ElasticPro section is made when nothing has made it, joined when something
 * has, and never doubled — the part of Module.php no other test reaches, against stand-ins for
 * Zabbix's CMenu and CMenuItem that keep only what the decision depends on.
 *   docker run --rm -v "$PWD":/m -w /m php:8.4-cli-alpine php test/menu.test.php
 * Exit 0 when every check holds; each failure is printed. A PHP warning is a failure.
 */
namespace {
	define('USER_TYPE_ZABBIX_USER', 1); define('USER_TYPE_ZABBIX_ADMIN', 2); define('USER_TYPE_SUPER_ADMIN', 3);
	define('ZBX_SIDEBAR_VIEW_MODE_COMPACT', 1);
	set_error_handler(function ($no, $msg, $file, $line) { throw new \ErrorException($msg, 0, $no, $file, $line); });
	// Guarded because PHP's gettext extension already defines _(), and a Zabbix frontend host
	// has gettext loaded — Zabbix uses it for translations. Without the guard every suite
	// died with "Cannot redeclare _()" on exactly the machine the modules run on. gettext's
	// _() returns its argument when no domain is bound, which is what the stub did anyway.
	if (!function_exists('_')) { function _($s) { return $s; } }

	/** Enough of Zabbix's CMenu for the find-or-create decision: order matters, so it is a list. */
	class CMenu {
		public $items = [];
		public $selected_for = null;
		public function add(CMenuItem $item): self { $this->items[] = $item; return $this; }
		public function find(string $label): ?CMenuItem {
			foreach ($this->items as $i) { if ($i->label === $label) { return $i; } }
			return null;
		}
		/** Zabbix's own: after the label, or at the end when there is no such label. */
		public function insertAfter(string $label, CMenuItem $item): self {
			foreach ($this->items as $n => $i) {
				if ($i->label === $label) { array_splice($this->items, $n + 1, 0, [$item]); return $this; }
			}
			return $this->add($item);
		}
		public function setSelectedByAction(string $action, array $params, bool $expand = false): bool {
			$this->selected_for = $action;
			return true;
		}
		public function labels(): array { return array_map(fn($i) => $i->label, $this->items); }
	}

	class CMenuItem {
		public $label; public $action = null; public $aliases = []; public $sub = null;
		public function __construct(string $label) { $this->label = $label; }
		public function setAction(string $a): self { $this->action = $a; return $this; }
		public function setAliases(array $a): self { $this->aliases = $a; return $this; }
		public function getSubMenu(): CMenu { return $this->sub ?? ($this->sub = new CMenu()); }
		public function setSubMenu(CMenu $m): self { $this->sub = $m; return $this; }
		public function hasSubMenu(): bool { return $this->sub !== null; }
		public function getLabel(): string { return $this->label; }
	}

	class CController { public function getAction(): string { return 'ep.clients.list'; } }
	class CViewHelper { public static function loadSidebarMode() { return 0; } }
	class CWebUser { public static $type = USER_TYPE_SUPER_ADMIN; public static function getType() { return self::$type; } }
	class APP {
		public static $menu;
		public static function Component(): self { return new self(); }
		public function get(string $what) { return self::$menu; }
	}
}
namespace Zabbix\Core { abstract class CModule { public function __construct() {} } }

namespace Modules\EpClients\Test {
	require __DIR__.'/../Module.php';
	use Modules\EpClients\Module;

	$failed = 0; $passed = 0;
	function check(string $what, bool $ok, $detail = null): void {
		global $failed, $passed;
		if ($ok) { $passed++; return; }
		$failed++; echo "FAIL: $what", $detail !== null ? ' — '.json_encode($detail, JSON_UNESCAPED_SLASHES) : '', PHP_EOL;
	}

	/** A sidebar as Zabbix hands one over, optionally with a section some other module made. */
	function sidebar(array $extra = []): \CMenu {
		$menu = new \CMenu();
		foreach (['Dashboards', 'Monitoring', 'Services', 'Inventory', 'Reports', 'Data collection', 'Administration'] as $label) {
			$item = new \CMenuItem($label);
			if ($label !== 'Dashboards') { $item->getSubMenu()->add((new \CMenuItem($label.' thing'))->setAction('zbx.'.$label)); }
			$menu->add($item);
		}
		foreach ($extra as $item) { $menu->insertAfter('Monitoring', $item); }
		return $menu;
	}

	function run(\CMenu $menu, int $type = USER_TYPE_SUPER_ADMIN, int $times = 1): \CMenu {
		\CWebUser::$type = $type;
		\APP::$menu = $menu;
		$module = new Module();
		for ($i = 0; $i < $times; $i++) { $module->onBeforeAction(new \CController()); }
		return $menu;
	}

	/* ---------------- the section is made when nothing has made it ---------------- */
	$menu = run(sidebar());
	$home = $menu->find('ElasticPro');
	check('an ElasticPro section is made when no other module has made one', $home !== null);
	check('and it renders as a section, not a bare link', $home->hasSubMenu() && $home->action === null);
	check('it sits straight after Monitoring', array_slice($menu->labels(), 0, 3) === ['Dashboards', 'Monitoring', 'ElasticPro'], $menu->labels());
	check('Cluster Management is NOT left under Data collection',
		$menu->find('Data collection')->getSubMenu()->find('Cluster Management') === null);
	check('all four entries are there, in order',
		array_map(fn($i) => $i->label, $home->getSubMenu()->items) === ['Cluster Management', 'Roles', 'Settings', 'Backups'],
		array_map(fn($i) => $i->label, $home->getSubMenu()->items));
	check('each entry opens its own page', array_map(fn($i) => $i->action, $home->getSubMenu()->items)
		=== ['ep.clients.list', 'ep.clients.roles', 'ep.clients.settings', 'ep.clients.backups']);
	check('the selection is run again, so the section is open on its own pages', $menu->selected_for === 'ep.clients.list');

	// Every alias must belong to one entry only, or Zabbix would have two to mark.
	$seen = [];
	foreach ($home->getSubMenu()->items as $i) {
		foreach (array_merge([$i->action], $i->aliases) as $a) { $seen[$a] = ($seen[$a] ?? 0) + 1; }
	}
	check('no action is claimed by two entries', array_keys(array_filter($seen, fn($n) => $n > 1)) === [], $seen);
	// An alias that is not an action of this module would never match anything.
	$manifest = json_decode(file_get_contents(__DIR__.'/../manifest.json'), true)['actions'];
	foreach (array_keys($seen) as $a) {
		check("the entry action $a is one this module declares", isset($manifest[$a]), $a);
	}

	/* ---------------- the section is joined when another module made it ---------------- */
	$theirs = new \CMenuItem('ElasticPro');
	$theirs->getSubMenu()->add((new \CMenuItem('Overview'))->setAction('other.overview'));
	$menu = run(sidebar([$theirs]));
	check('there is one ElasticPro section, not two', count(array_filter($menu->labels(), fn($l) => $l === 'ElasticPro')) === 1, $menu->labels());
	check('the other module\'s own entry is left alone, and first',
		array_map(fn($i) => $i->label, $menu->find('ElasticPro')->getSubMenu()->items)
			=== ['Overview', 'Cluster Management', 'Roles', 'Settings', 'Backups']);

	/* ---------------- nothing is ever doubled ---------------- */
	$menu = run(sidebar(), USER_TYPE_SUPER_ADMIN, 3);
	check('three passes over the same sidebar add one of each entry',
		count($menu->find('ElasticPro')->getSubMenu()->items) === 4);
	// A second module instance is what a second request really is, and the guard is per instance.
	$menu = sidebar();
	run($menu); run($menu);
	check('a second module instance over a sidebar already done adds nothing twice',
		array_map(fn($i) => $i->label, $menu->find('ElasticPro')->getSubMenu()->items) === ['Cluster Management', 'Roles', 'Settings', 'Backups'],
		array_map(fn($i) => $i->label, $menu->find('ElasticPro')->getSubMenu()->items));
	check('and still one section', count(array_filter($menu->labels(), fn($l) => $l === 'ElasticPro')) === 1);

	/* ---------------- who sees what ---------------- */
	$menu = run(sidebar(), USER_TYPE_ZABBIX_ADMIN);
	check('an Admin is offered the two pages that only show',
		array_map(fn($i) => $i->label, $menu->find('ElasticPro')->getSubMenu()->items) === ['Cluster Management', 'Settings'],
		array_map(fn($i) => $i->label, $menu->find('ElasticPro')->getSubMenu()->items));
	$menu = run(sidebar(), USER_TYPE_ZABBIX_USER);
	check('a User is offered nothing, and no empty section is left behind', $menu->find('ElasticPro') === null);

	/* ---------------- a sidebar without Monitoring ---------------- */
	$bare = new \CMenu();
	$bare->add(new \CMenuItem('Dashboards'));
	run($bare);
	check('with no Monitoring to follow, the section still goes in — at the end', $bare->labels() === ['Dashboards', 'ElasticPro']);

	echo $passed, ' passed, ', $failed, ' failed', PHP_EOL;
	exit($failed ? 1 : 0);
}
