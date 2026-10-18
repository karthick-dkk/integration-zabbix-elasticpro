<?php declare(strict_types = 0);

namespace EpShared\Actions;

foreach (['Store', 'Settings', 'Roles', 'ClientData', 'Columns', 'ColumnMath', 'ColumnReader', 'ColumnSettings'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}
require_once __DIR__.'/../includes/Catalogue.php';

use CController,
	CControllerResponseData;
use EpShared\{Columns, ColumnReader, ColumnSettings, Roles, Settings};
use EpShared\Includes\Catalogue;

/**
 * The column editor: the dialog Add column and Edit open over the widget form, as Top hosts
 * opens its column editor. Opened, it draws the column; Update sends it back here, where
 * Columns::clean — the rule the widget's Columns field applies again on Apply, and the widget
 * leniently when it draws — either returns the column as it will be stored or says what is
 * wrong, every message naming the column. "Check" asks how many client servers have the item key.
 *
 * The widget's own Includes\Catalogue says which built-in columns there are.
 *
 * Shared: ../../sync-assets.mjs copies this into each widget that offers the column editor, under its namespace.
 */
class ColumnEdit extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		// The values themselves are checked by Columns::clean.
		$fields = ['roles' => 'array', 'thresholds' => 'array', 'scope' => 'in client,row', 'trend_days' => 'string',
			'edit' => 'in 1', 'update' => 'in 1', 'coverage' => 'in 1', 'templateid' => 'string'];
		foreach (array_keys(Columns::DEFAULT) as $k) {
			if (!isset($fields[$k])) {
				$fields[$k] = 'string';
			}
		}
		$ret = $this->validateInput($fields);
		if (!$ret) {
			$this->setResponse((new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => array_column(get_and_clear_messages(), 'message')]
			], JSON_THROW_ON_ERROR)]))->disableView());
		}
		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$roles = Roles::load();
		$model = ColumnSettings::loadModel(Catalogue::REPORT);
		$scope = $this->getInput('scope', 'client') === 'row' ? 'row' : 'client';
		$catalogue = Catalogue::forScope(Catalogue::columns($roles, $model['split']), $scope);
		$roleIds = array_column(Roles::allRoles($roles), 'id');
		$input = $this->getInputAll();

		if ($this->hasInput('coverage')) {
			$this->json(ColumnReader::coverage(trim((string) ($input['key'] ?? '')), ($input['pattern'] ?? '0') === '1', (array) ($input['roles'] ?? []),
				ColumnReader::clientServers($roles, Settings::all())));
			return;
		}

		if ($this->hasInput('update')) {
			$edit = $this->hasInput('edit');
			$clean = array_intersect_key($input, Columns::DEFAULT);
			[$column, $errors] = Columns::clean($clean, $catalogue, $roleIds, $scope);
			if ($errors) {
				$this->json(['error' => ['title' => _('Cannot update column'),
					'messages' => Columns::named($errors, is_scalar($input['name'] ?? null) ? (string) $input['name'] : '', 0,
						is_scalar($input['group'] ?? null) ? (string) $input['group'] : '')]]);
				return;
			}
			$this->json($column + ['scope' => $scope] + ($edit ? ['edit' => 1] : []));
			return;
		}

		$column = array_intersect_key($input, Columns::DEFAULT) + Columns::DEFAULT;
		$column['roles'] = array_values(array_filter((array) $column['roles'], 'is_scalar'));
		$column['thresholds'] = array_values(array_filter((array) $column['thresholds'], 'is_array'));
		$cfg = Settings::all();
		$servers = $scope === 'client' ? ColumnReader::clientServers($roles, $cfg) : [];
		$this->setResponse(new CControllerResponseData([
			'action' => $this->getAction(),
			'edit' => $this->hasInput('edit'),
			'scope' => $scope,
			'column' => $column,
			'catalogue' => $catalogue,
			'roles' => array_map(fn($r) => ['id' => $r['id'], 'label' => $r['label'], 'family' => $r['familyLabel']], Roles::allRoles($roles)),
			'usage' => Settings::usage($cfg),
			'palette' => Settings::palette($cfg),
			'decimals' => ['%' => Settings::decimals('%', $cfg), '' => Settings::decimals('', $cfg), 'B' => Settings::decimals('B', $cfg)],
			'trend_days' => (int) ($input['trend_days'] ?? 7) ?: 7,
			'keys' => $scope === 'client' ? ColumnReader::keySuggestions($servers) : [],
			'coverage' => (int) $column['source'] === Columns::SOURCE_ITEM && $column['key'] !== ''
				? ColumnReader::coverage((string) $column['key'], (string) $column['pattern'] === '1', $column['roles'], $servers) : null,
			'user' => ['debug_mode' => $this->getDebugMode()]
		]));
	}

	private function json(array $out): void {
		$this->setResponse((new CControllerResponseData(['main_block' => json_encode($out, JSON_THROW_ON_ERROR)]))->disableView());
	}
}
