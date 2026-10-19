<?php declare(strict_types = 0);

namespace Modules\EpCapacity\Actions;

foreach (['Store', 'Settings', 'Roles', 'Columns', 'ColumnSettings'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}

use CController;
use CControllerResponseData;
use Modules\EpCapacity\Lib\{Columns, ColumnSettings, Roles};

/**
 * Save or reset a report's column settings (the Columns dialog). Super admins only; one setting
 * for everyone. Answers JSON: {ok: true} or {ok: false, error}.
 *
 * A report whose columns are the shared column model (its widget has an Includes\Catalogue —
 * Client resources) keeps them as report defaults in the version-2 shape: the dialog's names,
 * shown columns, order and families-as-roles are put onto the defaults, and "Use this widget's
 * columns" replaces them with a widget's own list, checked by the column rule (Columns::clean,
 * strictly — every error named) before anything is written.
 *
 * Shared: ../../sync-assets.mjs copies this into each widget module, under its namespace.
 */
class ColumnsSave extends CController {

	/**
	 * Which report this copy of the action may write, worked out from the action it was reached
	 * by — widget.ep_capacity.columns is the capacity widget's and nothing else's.
	 *
	 * It used to accept any of the three from the request, which meant the volume widget's
	 * endpoint could rewrite the capacity report's settings: three ways in to each file instead
	 * of one, and a Super admin could do it by accident with a stale dialog open. Deriving it
	 * from the action leaves each widget able to write only its own.
	 */
	private const OWN = ['ep_capacity' => 'capacity', 'ep_resources' => 'resources', 'ep_volume' => 'volume'];

	private function own(): string {
		return self::OWN[explode('.', $this->getAction())[1] ?? ''] ?? '';
	}

	protected function checkInput(): bool {
		return $this->validateInput(['report' => 'required|in '.$this->own(), 'settings' => 'string', 'columns' => 'string', 'reset' => 'in 1']);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
	}

	/** The widget's catalogue of built-in columns, when its columns are the shared column model. */
	private function catalogue(): ?string {
		$cls = substr(static::class, 0, (int) strrpos(static::class, '\\Actions\\')).'\\Includes\\Catalogue';
		$file = __DIR__.'/../includes/Catalogue.php';
		if (!class_exists($cls, false) && is_file($file)) {
			require_once $file;
		}
		return class_exists($cls) ? $cls : null;
	}

	protected function doAction(): void {
		try {
			$report = (string) $this->getInput('report');
			$catalogue = $this->catalogue();
			if ($this->hasInput('reset')) {
				ColumnSettings::reset($report);
			}
			elseif ($catalogue !== null) {
				$this->saveModel($report, $catalogue);
			}
			elseif ($this->hasInput('columns')) {
				throw new \RuntimeException(_('This report keeps names, shown columns and order only.'));
			}
			else {
				$in = json_decode((string) $this->getInput('settings', '{}'), true);
				ColumnSettings::save($report, is_array($in) ? $in : [], null);
			}
			$out = ['ok' => true];
		}
		catch (\Throwable $e) {
			$out = ['ok' => false, 'error' => $e->getMessage()];
		}
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($out)]));
	}

	/** The report defaults of a column-model report: from the dialog, or from a widget's own list. */
	private function saveModel(string $report, string $catalogue): void {
		$roles = Roles::load();
		$roleIds = array_column(Roles::allRoles($roles), 'id');
		$model = ColumnSettings::loadModel($report);
		if ($this->hasInput('columns')) {
			$in = json_decode((string) $this->getInput('columns'), true);
			$in = is_array($in) ? $in : [];
			$all = $catalogue::columns($roles, $model['split']);
			[$columns, $errors] = Columns::cleanList((array) ($in['columns'] ?? []), $catalogue::forScope($all, 'client'), $roleIds, true);
			[$rows, $rowErrors] = Columns::cleanList((array) ($in['server_columns'] ?? []), $catalogue::forScope($all, 'row'), $roleIds, true, 'row');
			if ($errors || $rowErrors || !$columns) {
				throw new \RuntimeException(implode(' ', array_merge($errors, $rowErrors)) ?: _('There are no columns to save.'));
			}
			ColumnSettings::saveModel($report, Columns::groupTogether($columns), $rows ?: null, $model['split'], $this->newHidden($catalogue));
			return;
		}
		$in = json_decode((string) $this->getInput('settings', '{}'), true);
		$in = is_array($in) ? $in : [];
		$before = $catalogue::forScope($catalogue::columns($roles, $model['split']), 'client');
		$list = ColumnSettings::resolve($model['columns'], $before, $model['legacy'], $model['new_hidden']);
		$list = ColumnSettings::applyDialog($list, $in, $before);
		$split = array_key_exists('split', $in) ? (array) $in['split'] : $model['split'];
		// Families shown as their roles, or back as one: their columns swap, the new ones shown.
		$after = $catalogue::forScope($catalogue::columns($roles, $split), 'client');
		$list = ColumnSettings::resplit($list, $before, $after);
		// The bound every widget is held to on Apply: report defaults past it would be defaults no
		// widget could take.
		if (count($list) > Columns::MAX) {
			throw new \RuntimeException(_s('The report would have %1$s columns; at most %2$s. Show fewer families as their roles.', count($list), Columns::MAX));
		}
		ColumnSettings::saveModel($report, $list, $model['server_columns'], $split, $this->newHidden($catalogue));
	}

	/** Whether a built-in column the list has never seen arrives unticked: it does, unless the widget's Catalogue says it arrives shown. */
	private function newHidden(string $catalogue): bool {
		return !method_exists($catalogue, 'newHidden') || $catalogue::newHidden();
	}
}
