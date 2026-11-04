<?php declare(strict_types = 1);

namespace Modules\ElasticPro;

use APP;
use CMenu;
use CMenuItem;
use CWebUser;
use Zabbix\Core\CModule as CoreModule;

/**
 * Adds "ElasticPro" to the Zabbix main menu, right after Monitoring.
 *
 * The menu offers the pages people open from Zabbix directly: Clusters, Indices, Snapshots,
 * REST console, Play and, for Super admins, Config (Cluster Management, the Clients module, joins
 * them). Every other page — Alerts, Nodes & shards, Live logs, Reports, Automation,
 * Accounts — is reached inside ElasticPro, from its own tabs, as when it runs on its own;
 * each still has its action, so a link to it (a Zabbix problem's troubleshoot link) works.
 * Config and Accounts are Super admin pages: the core refuses them to anybody else
 * regardless; this only avoids offering a page whose every request fails.
 *
 * The menu API (`menu.main`, `insertAfter`, `CMenuItem`) is the one Zabbix 7.0 ships and
 * ships. If the item does not appear on another Zabbix version, this
 * file is the one place to adjust.
 */
class Module extends CoreModule {

	/** Every page id in ElasticPro => its label. Each has an action. */
	public const PAGES = [
		'overview'   => 'Clusters',
		'alerts'     => 'Alerts',
		'indices'    => 'Indices',
		'shards'     => 'Nodes & shards',
		'logs'       => 'Live logs & log delay',
		'snapshots'  => 'Snapshots',
		// The page ElasticPro 0.3.53 renamed Reports. The action stays elasticpro.volume (it opens
		// #/volume, which ElasticPro forwards to #/reports), so existing links keep working.
		'volume'     => 'Reports',
		'console'    => 'REST console',
		'automation' => 'Automation',
		'play'       => 'Play',
	];

	/**
	 * The pages in the Zabbix menu, in menu order. The rest are tabs inside the app. Play is the
	 * NOC wall display (ElasticPro 0.3.47+, `#/play`); the "ElasticPro Play" dashboard widget
	 * (play-widget, installed as `ep_play`) opens the same page through PlayFrame.
	 */
	public const MENU = ['overview', 'indices', 'snapshots', 'console', 'play'];

	/** Pages only a Super admin is offered. */
	public const ADMIN_PAGES = [
		'accounts' => 'Accounts',
		'settings' => 'Config',
	];

	public function init(): void {
		// The guest user has no ElasticPro role (Lib\SignIn::mayOpen): offer nothing it is refused.
		require_once __DIR__.'/lib/SignIn.php';
		if (!Lib\SignIn::mayOpen()) {
			return;
		}
		$items = [];
		// The other pages' actions stay aliases of Clusters, so the menu marks where you are.
		$others = array_map(fn($id) => 'elasticpro.'.$id, array_diff(array_keys(self::PAGES), self::MENU));
		foreach (self::MENU as $id) {
			$entry = (new CMenuItem(_(self::PAGES[$id])))->setAction('elasticpro.'.$id);
			if ($id === 'overview') {
				$entry->setAliases(array_merge($others, ['elasticpro.accounts', 'elasticpro.troubleshoot']));
			}
			$items[] = $entry;
		}
		if (CWebUser::getType() == USER_TYPE_SUPER_ADMIN) {
			$items[] = (new CMenuItem(_(self::ADMIN_PAGES['settings'])))->setAction('elasticpro.settings');
		}

		$item = (new CMenuItem(_('ElasticPro')))
			->setId('elasticpro')
			->setSubMenu(new CMenu($items));
		// The reports glyph — Zabbix's icon font has nothing Elasticsearch-shaped, and a
		// constant that is missing on some build must not take the whole menu down.
		if (defined('ZBX_ICON_REPORTS')) {
			$item->setIcon(constant('ZBX_ICON_REPORTS'));
		}

		$menu = APP::Component()->get('menu.main');
		$menu->insertAfter(_('Monitoring'), $item);

		// Administration → ElasticPro: pairing and connection settings (LinkEdit). The
		// Administration menu is a Super admin's only; the check is here too so the entry
		// never depends on that.
		if (CWebUser::getType() == USER_TYPE_SUPER_ADMIN) {
			$admin = $menu->find(_('Administration'));
			if ($admin !== null && $admin->hasSubMenu()) {
				$admin->getSubMenu()->add(
					(new CMenuItem(_('ElasticPro')))
						->setAction('elasticpro.link.edit')
						->setAliases(['elasticpro.link.update'])
				);
			}
		}
	}
}
