<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use API;
use CControllerResponseData;
use Modules\EpClients\Lib\{Roles, Store, TemplateInstaller, TemplateMap};

/** Families and their roles, with how many machines each has across every client. */
class RoleList extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['edit' => 'string', 'family' => 'string']);
	}

	protected function doAction(): void {
		$roles = $this->roles();
		// One call for every group. It was one call per group, which on a site with five
		// families and a dozen roles meant seventeen round trips to draw one table.
		$counts = array_fill_keys(Roles::groups($roles), 0);
		foreach (API::HostGroup()->get(['output' => ['name'], 'filter' => ['name' => array_keys($counts)],
				'selectHosts' => API_OUTPUT_COUNT]) ?: [] as $g) {
			$counts[$g['name']] = (int) $g['hosts'];
		}
		$response = new CControllerResponseData([
			'roles' => $roles,
			'counts' => $counts,
			'edit' => (string) $this->getInput('edit', ''),
			'family' => (string) $this->getInput('family', ''),
			'template' => TemplateInstaller::status($roles),
			'templates' => Roles::templateNames(),
			'aliases' => Roles::templateAliases(),
			'map' => TemplateMap::rows(),
			'choices' => TemplateMap::choices(),
			'consumers' => Roles::TEMPLATE_CONSUMERS,
			'store_ok' => Store::writable(),
			'store_dir' => Store::dir()
		]);
		$response->setTitle(_('Client roles'));
		$this->setResponse($response);
	}
}
