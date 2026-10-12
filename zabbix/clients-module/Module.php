<?php declare(strict_types = 0);

namespace Modules\EpClients;

use APP;
use CController;
use CMenuItem;
use CViewHelper;
use CWebUser;
use Zabbix\Core\CModule as CoreModule;

/**
 * Puts ElasticPro in the sidebar: Cluster Management, Roles, Settings and Backups.
 *
 * The section is joined when another ElasticPro module has already made one, and made here when
 * none has, so this module needs nothing but itself to install. Before, it only looked for a
 * section belonging to another module and fell back to Data collection — which put Cluster
 * Management among Zabbix's own pages on every install without that other module. Each entry is
 * added only when the section has not got it already, so whichever module boots first there is
 * one section and one of each entry.
 *
 * Added before each page is drawn rather than at start-up: the section may belong to another
 * module, which may start after this one.
 *
 * Zabbix marks the current page's menu entry (and keeps its section open) before modules'
 * onBeforeAction runs, so an entry added here would never be marked: the ElasticPro section
 * closed on every one of its own pages. The selection is run again once the entries are in.
 */
class Module extends CoreModule {

	private $added = false;

	/**
	 * The section's entries: label, the action it opens, the other actions that draw a page under
	 * it, and whether it is for Super admins alone.
	 *
	 * The aliases keep the right entry marked and the section open while one of those pages is on
	 * screen; no action belongs to two entries. Roles and Backups change things and their actions
	 * refuse anyone else, so an Admin is not offered a link that would only deny them — Settings
	 * reads as the reports' legend and is shown to Admins as the client list is.
	 *
	 * A function rather than a constant so each label is a literal inside _(), where the
	 * translations can be collected from.
	 */
	private static function entries(): array {
		return [
			[_('Cluster Management'), 'ep.clients.list', ['ep.clients.edit', 'ep.clients.save', 'ep.clients.import'], false],
			[_('Roles'), 'ep.clients.roles', ['ep.clients.role.save', 'ep.clients.template.install'], true],
			[_('Settings'), 'ep.clients.settings', ['ep.clients.settings.save'], false],
			[_('Backups'), 'ep.clients.backups', ['ep.clients.backup.restore', 'ep.clients.backup.download'], true]
		];
	}

	public function onBeforeAction(CController $action): void {
		// Admins see the pages that only show; the rest stay Super admin.
		$type = CWebUser::getType();
		if ($this->added || !in_array($type, [USER_TYPE_SUPER_ADMIN, USER_TYPE_ZABBIX_ADMIN])) {
			return;
		}
		$this->added = true;
		$menu = APP::Component()->get('menu.main');

		$home = $menu->find(_('ElasticPro'));
		if ($home === null) {
			// insertAfter puts it at the end when Monitoring is not there to go after, which is
			// a sidebar nobody has seen but still a section rather than nothing.
			$home = new CMenuItem(_('ElasticPro'));
			$menu->insertAfter(_('Monitoring'), $home);
		}
		// getSubMenu() makes the submenu when the section has not got one, and marks the section
		// as having one — so a section this module just made renders as a section.
		$sub = $home->getSubMenu();
		foreach (self::entries() as [$label, $action_name, $aliases, $super_only]) {
			if (($super_only && $type != USER_TYPE_SUPER_ADMIN) || $sub->find($label) !== null) {
				continue;
			}
			$sub->add((new CMenuItem($label))->setAction($action_name)->setAliases($aliases));
		}
		$menu->setSelectedByAction($action->getAction(), $_REQUEST,
			CViewHelper::loadSidebarMode() != ZBX_SIDEBAR_VIEW_MODE_COMPACT);
	}
}
