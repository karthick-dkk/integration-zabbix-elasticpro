<?php declare(strict_types = 0);

namespace EpShared\Includes;

use EpShared\{Columns, ColumnSettings};
use Zabbix\Widgets\CWidgetField;

/**
 * A widget's columns: an ordered list, each one a set of settings (Columns::DEFAULT). Stored the
 * way Zabbix stores Top hosts' columns — columns.0.name, columns.0.thresholds.1.value … — which
 * Zabbix reads back into the same nested list. Only what differs from the default is stored — for a
 * built-in column, from how the catalogue draws it — so a fifty-column table copied from the
 * report defaults is a hundred short rows (each column's source and built-in id), not seven hundred.
 *
 * Every column goes through Columns::clean, the rule the column editor applies. When the form is
 * applied (strict) a column that breaks it is an error naming the column; when a saved widget is
 * drawn, a column that no longer passes (a built-in column whose family was removed) is left out
 * and named in getDropped(), rather than turning the whole widget into an error.
 *
 * Shared: ../../../sync-assets.mjs copies this into the widgets that use it, under their namespace.
 */
class CWidgetFieldColumns extends CWidgetField {

	public const DEFAULT_VIEW = null;

	private array $catalogue;
	private array $role_ids;
	private string $scope;
	private array $dropped = [];

	/**
	 * @param string $scope  'client' (a client row's columns) or 'row' (a server row's)
	 */
	public function __construct(string $name, ?string $label, array $catalogue, array $role_ids, string $scope = 'client') {
		parent::__construct($name, $label);

		$this->catalogue = $catalogue;
		$this->role_ids = $role_ids;
		$this->scope = $scope;

		$this
			->setDefault([])
			->setSaveType(ZBX_WIDGET_FIELD_TYPE_STR);
	}

	public function getCatalogue(): array {
		return $this->catalogue;
	}

	public function getScope(): string {
		return $this->scope;
	}

	/** Columns a saved widget had that no longer pass, each named with why. */
	public function getDropped(): array {
		return $this->dropped;
	}

	public function setValue($value): self {
		// In the order of their positions (columns.0, columns.1 …) whatever order they arrived in.
		$value = (array) $value;
		if (!array_is_list($value)) {
			ksort($value, SORT_NUMERIC);
		}
		$this->value = array_map(fn(array $c) => $this->withBaseline($c), array_values(array_filter($value, 'is_array')));

		return $this;
	}

	/**
	 * What a column is when nothing about it was stored: a built-in column as the catalogue draws
	 * it (its id, name, group, units and look), anything else the model's default.
	 */
	private function baseline(array $c): array {
		$id = is_scalar($c['builtin'] ?? null) ? (string) $c['builtin'] : '';
		if ((string) ($c['source'] ?? Columns::SOURCE_BUILTIN) === (string) Columns::SOURCE_BUILTIN && isset($this->catalogue[$id])) {
			return ColumnSettings::builtinColumn($id, $this->catalogue[$id]);
		}
		return Columns::DEFAULT;
	}

	/** A column read back from storage: what was not stored is its baseline's. */
	private function withBaseline(array $c): array {
		$base = $this->baseline($c);
		foreach (['roles', 'thresholds'] as $list) {
			if (!array_key_exists($list, $c)) {
				$c[$list] = $base[$list];
			}
		}
		return $c + $base;
	}

	public function validate(bool $strict = false): array {
		[$clean, $errors, $dropped] = Columns::cleanList($this->getValue(), $this->catalogue, $this->role_ids, $strict, $this->scope);

		if (!$errors) {
			$this->value = Columns::groupTogether($clean);
			$this->dropped = $dropped;
		}

		return $errors;
	}

	public function toApi(array &$widget_fields = []): void {
		foreach (array_values($this->getValue()) as $i => $c) {
			$prefix = $this->name.'.'.$i.'.';
			$base = $this->baseline($c);
			$builtin = $base !== Columns::DEFAULT;

			foreach ($base as $key => $default) {
				if (!array_key_exists($key, $c)) {
					continue;
				}

				if (($key === 'roles' || $key === 'thresholds') && array_values((array) $c[$key]) == array_values((array) $default) && $default) {
					continue;
				}

				if ($key === 'roles') {
					foreach (array_values((array) $c['roles']) as $j => $role) {
						$widget_fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => $prefix.'roles.'.$j, 'value' => (string) $role];
					}
					continue;
				}

				if ($key === 'thresholds') {
					foreach (array_values((array) $c['thresholds']) as $j => $t) {
						$widget_fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_INT32, 'name' => $prefix.'thresholds.'.$j.'.level', 'value' => (int) $t['level']];
						$widget_fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => $prefix.'thresholds.'.$j.'.color', 'value' => (string) $t['color']];
						$widget_fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => $prefix.'thresholds.'.$j.'.value', 'value' => (string) $t['value']];
					}
					continue;
				}

				// What is still the baseline need not be stored; it reads back the same. The source always
				// is, and a built-in column's id — or, for any other, its uid and name — so a stored column
				// is never a bare index.
				$always = $builtin ? ['source', 'builtin'] : ['uid', 'name', 'source'];
				if (!in_array($key, $always, true) && $c[$key] === $default) {
					continue;
				}

				$int = in_array($key, Columns::INT_FIELDS, true);
				$widget_fields[] = [
					'type' => $int ? ZBX_WIDGET_FIELD_TYPE_INT32 : ZBX_WIDGET_FIELD_TYPE_STR,
					'name' => $prefix.$key,
					'value' => $int ? (int) $c[$key] : (string) $c[$key]
				];
			}
		}
	}
}
