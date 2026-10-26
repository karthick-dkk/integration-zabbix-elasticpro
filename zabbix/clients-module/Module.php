<?php declare(strict_types = 0);

namespace Modules\EpClients;

use APP;
use CController;
use CMenuItem;
use CViewHelper;
use CWebUser;
use Modules\EpClients\Lib\EpRole;
use Zabbix\Core\CModule as CoreModule;

require_once __DIR__.'/lib/EpRole.php';

/**
 * Puts ElasticPro in the sidebar: Cluster Management, Discover, Roles, Settings and Backups.
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
	 * it, and who sees it: 'show' (every Zabbix Admin), 'edit' (those who may edit) or 'super'.
	 *
	 * The aliases keep the right entry marked and the section open while one of those pages is on
	 * screen; no action belongs to two entries. Roles changes things and its actions refuse anyone
	 * who may not edit, so it is offered to exactly those who may — a Super admin, or a Zabbix Admin
	 * or User whom ElasticPro calls an admin (EpRole, the same decision the pages make). Backups
	 * stays with Super admins whatever ElasticPro says (Base::superOnly): a backup holds every
	 * client and host group. Nobody is offered a link that would only deny them. Settings reads as
	 * the reports' legend and is shown to Admins as the client list is.
	 *
	 * A function rather than a constant so each label is a literal inside _(), where the
	 * translations can be collected from.
	 */
	private static function entries(): array {
		return [
			[_('Cluster Management'), 'ep.clients.list', ['ep.clients.edit', 'ep.clients.save', 'ep.clients.import', 'ep.clients.columns.save'], 'show'],
			[_('Discover'), 'ep.clients.discover', ['ep.clients.discover.apply'], 'edit'],
			[_('Roles'), 'ep.clients.roles', ['ep.clients.role.save', 'ep.clients.template.install'], 'edit'],
			[_('Settings'), 'ep.clients.settings', ['ep.clients.settings.save', 'ep.clients.credential.save'], 'show'],
			[_('Backups'), 'ep.clients.backups', ['ep.clients.backup.restore', 'ep.clients.backup.download'], 'super']
		];
	}

	public function onBeforeAction(CController $action): void {
		if ($this->added) {
			return;
		}
		$type = (int) CWebUser::getType();
		// Super admins see everything without asking. Zabbix Admins see the pages that only show,
		// and the rest when ElasticPro calls them an admin; a Zabbix User sees the section when
		// ElasticPro calls them an operator (they add and edit clients) or an admin.
		$may_edit = $type == USER_TYPE_SUPER_ADMIN;
		$may_show = $may_edit || $type == USER_TYPE_ZABBIX_ADMIN;
		if (!$may_edit && in_array($type, [USER_TYPE_ZABBIX_ADMIN, USER_TYPE_ZABBIX_USER], true)) {
			// On this module's own pages the answer is fresh, as the page itself decides; on every
			// other Zabbix page the last answer is used, and ElasticPro is asked only when there is
			// none yet — so browsing Zabbix costs one question per session, not one a minute.
			$own = strncmp($action->getAction(), 'ep.clients.', 11) === 0;
			$user = (string) (CWebUser::$data['username'] ?? '');
			$name = EpRole::zabbixRoleName();
			$may_edit = EpRole::current()->decision($user, $type, $own, EpRole::NEED_ADMIN, $name)['write'];
			$may_show = $may_show || $may_edit || EpRole::current()->decision($user, $type, $own, EpRole::NEED_OPERATOR, $name)['write'];
		}
		if (!$may_show) {
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
		foreach (self::entries() as [$label, $action_name, $aliases, $who]) {
			if (($who === 'edit' && !$may_edit) || ($who === 'super' && $type != USER_TYPE_SUPER_ADMIN) || $sub->find($label) !== null) {
				continue;
			}
			$sub->add((new CMenuItem($label))->setAction($action_name)->setAliases($aliases));
		}
		$menu->setSelectedByAction($action->getAction(), $_REQUEST,
			CViewHelper::loadSidebarMode() != ZBX_SIDEBAR_VIEW_MODE_COMPACT);
	}
}
