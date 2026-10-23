<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use CControllerResponseData;
use Exception;
use Modules\EpClients\Lib\Store;

/**
 * Discover existing hosts: the clusters Zabbix already holds, each proposed as a client with its
 * role hosts, what adopting it would write, and what is missing. Reads only — nothing changes
 * until "Adopt selected" on the page posts to ep.clients.discover.apply.
 *
 * The scan is kept for this session (Base::cachedScan) and re-run only on "Rescan", so opening
 * the page twice does not walk Zabbix twice. Who may open it: whoever may edit (Base).
 */
class DiscoverList extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['rescan' => 'in 1']);
	}

	protected function doAction(): void {
		$data = ['scan' => null, 'token' => '', 'error' => null, 'results' => null, 'store_ok' => Store::writable(), 'store_dir' => Store::dir(),
			'can_write' => $this->canWrite()];
		try {
			$cached = $this->getInput('rescan', '') === '1' ? null : $this->cachedScan();
			if ($cached === null) {
				$cached = $this->cacheScan($this->discovery()->scan(fn(array $needs) => $this->writeRefusals($needs)));
			}
			$data['scan'] = $cached['scan'];
			$data['token'] = $cached['token'];
		}
		catch (Exception $e) {
			$data['error'] = $e->getMessage();
		}
		$data['roles'] = $this->rolesFor();
		$response = new CControllerResponseData($data);
		$response->setTitle(_('Discover existing hosts'));
		$this->setResponse($response);
	}
}
