<?php declare(strict_types = 0);

namespace Modules\EpOverview\Includes;

use Modules\EpOverview\Lib\Columns;
use Zabbix\Widgets\CWidgetField;

/**
 * A number that may be left empty, and empty means something: "all clients", "ElasticPro
 * Settings". Zabbix's integer box turns an empty box into 0 and draws it as 0, which is how the
 * old Warn and High fields came to show 0 for "follow Settings". This keeps the empty box empty.
 *
 * Copied from ../../shared/php/includes by ../../sync-assets.mjs — edit it there.
 */
class CWidgetFieldOptionalNumber extends CWidgetField {

	public const DEFAULT_VIEW = null;

	private float $min;
	private float $max;
	private bool $integer;

	public function __construct(string $name, ?string $label, float $min, float $max, bool $integer = true) {
		parent::__construct($name, $label);

		$this->min = $min;
		$this->max = $max;
		$this->integer = $integer;

		$this
			->setDefault('')
			->setSaveType(ZBX_WIDGET_FIELD_TYPE_STR);
	}

	public function setValue($value): self {
		$this->value = is_scalar($value) ? trim((string) $value) : '';

		return $this;
	}

	public function validate(bool $strict = false): array {
		$v = $this->getValue();

		if ($v === '') {
			return [];
		}

		$ok = $this->integer ? preg_match('/^-?\d+$/', $v) : is_numeric($v);

		if ($ok && (float) $v >= $this->min && (float) $v <= $this->max) {
			$this->value = $this->integer ? (string) (int) $v : Columns::num((float) $v);

			return [];
		}

		if (!$strict) {
			// A saved value that no longer fits is dropped, not turned into an error on the dashboard.
			$this->value = '';

			return [];
		}

		return [_s('Invalid parameter "%1$s": %2$s.', $this->getErrorLabel(),
			_s('must be empty or %1$s from %2$s to %3$s', $this->integer ? _('a whole number') : _('a number'),
				Columns::num($this->min), Columns::num($this->max)
			)
		)];
	}

	public function getMin(): float {
		return $this->min;
	}

	public function getMax(): float {
		return $this->max;
	}
}
