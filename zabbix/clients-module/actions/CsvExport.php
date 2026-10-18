<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use CControllerResponseData;
use Modules\EpClients\Lib\Csv;

/**
 * Every client as it is now: clients.csv, or servers.csv (kind=servers) — edit and import back.
 * The Import page offers clients.xlsx (WorkbookDownload); this stays for scripts and older links.
 */
class CsvExport extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['kind' => 'in clients,servers']);
	}

	protected function doAction(): void {
		$servers = $this->getInput('kind', 'clients') === 'servers';
		[$forms, $names] = $this->exportForms();
		$csv = $servers ? Csv::exportServers($this->roles(), $forms, $names) : Csv::export($this->roles(), $forms);
		$response = new CControllerResponseData(['main_block' => $csv, 'mime_type' => 'text/csv']);
		$response->setFileName(($servers ? 'servers-' : 'clients-').date('Y-m-d').'.csv');
		$this->setResponse($response);
	}
}
