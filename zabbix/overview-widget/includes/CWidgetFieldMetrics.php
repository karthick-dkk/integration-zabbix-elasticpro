<?php declare(strict_types = 0);

namespace Modules\EpOverview\Includes;

use Zabbix\Widgets\CWidgetField;

/**
 * The card's metrics: an ordered list, each one a set of settings (Metrics::DEFAULT). Stored the
 * way Zabbix stores Top hosts' columns — metrics.0.name, metrics.0.thresholds.1.value … — which
 * Zabbix reads back into the same nested list.
 *
 * Every metric goes through Metrics::clean, the same rule the metric editor applies. When the
 * form is applied (strict) a metric that breaks it is an error naming the metric; when a saved
 * widget is drawn, a metric that no longer passes (a built-in figure whose family was removed)
 * is left out rather than turning the whole widget into an error.
 */
class CWidgetFieldMetrics extends CWidgetField {

	public const DEFAULT_VIEW = null;

	private array $catalogue;
	private array $role_ids;

	/** Metrics left out when the widget last drew, each named with why: never silently. */
	private array $dropped = [];

	public function __construct(string $name, ?string $label, array $catalogue, array $role_ids) {
		parent::__construct($name, $label);

		$this->catalogue = $catalogue;
		$this->role_ids = $role_ids;

		$this
			->setDefault([])
			->setSaveType(ZBX_WIDGET_FIELD_TYPE_STR);
	}

	public function getCatalogue(): array {
		return $this->catalogue;
	}

	public function setValue($value): self {
		$this->value = array_values(array_filter((array) $value, 'is_array'));

		return $this;
	}

	/** The metrics left out of the list as it was read, one line each naming the metric and why. */
	public function getDropped(): array {
		return $this->dropped;
	}

	public function validate(bool $strict = false): array {
		[$clean, $errors, $dropped] = self::cleanList($this->getValue(), $this->catalogue, $this->role_ids, $strict);

		if (!$errors) {
			$this->value = $clean;
		}
		$this->dropped = $dropped;

		return $errors;
	}

	/**
	 * The list as stored, and what is wrong with it. Not strict: what fails is dropped and the
	 * rest kept. Strict: every failure is reported.
	 *
	 * @return array{0: array, 1: string[], 2: string[]}  [metrics, errors, dropped] — what is left out is named in $dropped
	 */
	public static function cleanList(array $metrics, array $catalogue, array $role_ids, bool $strict): array {
		$errors = [];
		$dropped = [];
		$out = [];
		$uids = [];

		if (count($metrics) > Metrics::MAX) {
			if ($strict) {
				$errors[] = _s('Invalid parameter "%1$s": %2$s.', _('Metrics'), _s('at most %1$s metrics', Metrics::MAX));
			}
			foreach (array_slice(array_values($metrics), Metrics::MAX) as $i => $m) {
				$dropped[] = Metrics::named([_s('more than %1$s metrics — left out.', Metrics::MAX)], is_scalar($m['name'] ?? null) ? (string) $m['name'] : '',
					Metrics::MAX + $i + 1)[0];
			}
			$metrics = array_slice($metrics, 0, Metrics::MAX);
		}

		foreach (array_values($metrics) as $i => $m) {
			[$clean, $e] = Metrics::clean((array) $m, $catalogue, $role_ids);

			if ($e) {
				$named = Metrics::named($e, is_scalar($m['name'] ?? null) ? (string) $m['name'] : '', $i + 1);
				if ($strict) {
					$errors = array_merge($errors, $named);
				}
				else {
					$dropped[] = $named[0];
				}
				continue;
			}

			while (in_array($clean['uid'], $uids, true)) {
				$clean['uid'] = Metrics::newUid();
			}

			$uids[] = $clean['uid'];
			$out[] = $clean;
		}

		return [$out, $errors, $dropped];
	}

	public function toApi(array &$widget_fields = []): void {
		foreach (array_values($this->getValue()) as $i => $m) {
			$prefix = $this->name.'.'.$i.'.';

			foreach (Metrics::DEFAULT as $key => $default) {
				if (!array_key_exists($key, $m)) {
					continue;
				}

				if ($key === 'roles') {
					foreach (array_values($m['roles']) as $j => $role) {
						$widget_fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => $prefix.'roles.'.$j, 'value' => $role];
					}
					continue;
				}

				if ($key === 'thresholds') {
					foreach (array_values($m['thresholds']) as $j => $t) {
						$widget_fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_INT32, 'name' => $prefix.'thresholds.'.$j.'.level', 'value' => (int) $t['level']];
						$widget_fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => $prefix.'thresholds.'.$j.'.color', 'value' => (string) $t['color']];
						$widget_fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => $prefix.'thresholds.'.$j.'.value', 'value' => (string) $t['value']];
					}
					continue;
				}

				// What is still the default need not be stored; it reads back the same. Name, uid and
				// source always are, so a stored metric is never a bare index.
				if (!in_array($key, ['uid', 'name', 'source'], true) && $m[$key] === $default) {
					continue;
				}

				$int = in_array($key, Metrics::INT_FIELDS, true);
				$widget_fields[] = [
					'type' => $int ? ZBX_WIDGET_FIELD_TYPE_INT32 : ZBX_WIDGET_FIELD_TYPE_STR,
					'name' => $prefix.$key,
					'value' => $int ? (int) $m[$key] : (string) $m[$key]
				];
			}
		}
	}
}
