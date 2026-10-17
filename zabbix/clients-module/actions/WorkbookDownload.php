<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

use CControllerResponseData;
use Modules\EpClients\Lib\Workbook;

/**
 * clients.xlsx: the template (Clients and Servers empty, and the Sample), or with what=export every
 * client and its servers as they are now — edit in Excel and import back. The dropdowns are read
 * from this Zabbix as it is now: client types, roles, proxies and proxy groups.
 */
class WorkbookDownload extends Base {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['what' => 'in template,export']);
	}

	protected function doAction(): void {
		$export = $this->getInput('what', 'template') === 'export';
		[$forms, $names] = $export ? $this->exportForms() : [[], []];
		$response = new CControllerResponseData(['main_block' => Workbook::build($this->roles(), $this->workbookLists(), $forms, $names),
			'mime_type' => Workbook::MIME]);
		$response->setFileName($export ? 'clients-'.date('Y-m-d').'.xlsx' : 'clients.xlsx');
		$this->setResponse($response);
	}
}
