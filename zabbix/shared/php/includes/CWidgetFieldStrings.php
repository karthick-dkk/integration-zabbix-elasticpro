<?php declare(strict_types = 0);

namespace EpShared\Includes;

use Zabbix\Widgets\CWidgetField;

/**
 * A list of short strings: client names, client types, section and role ids, severities. Kept
 * as text so a choice stays with its name when lists are reordered, and filtered rather than
 * refused when a name has since gone (a client removed, a role renamed) — a saved widget keeps
 * drawing instead of turning into an error.
 *
 * An empty list that differs from a non-empty default is stored as one empty string, because
 * Zabbix stores an array as one row per element and nothing at all would read back as the
 * default.
 *
 * Shared: ../../../sync-assets.mjs copies this into the widgets that use it, under their namespace.
 */
class CWidgetFieldStrings extends CWidgetField {

	public const DEFAULT_VIEW = null;

	private int $max_count;
	private int $max_chars;
	private ?array $allowed = null;

	public function __construct(string $name, ?string $label = null, int $max_count = 200, int $max_chars = 255) {
		parent::__construct($name, $label);

		$this->max_count = $max_count;
		$this->max_chars = $max_chars;

		$this
			->setDefault([])
			->setSaveType(ZBX_WIDGET_FIELD_TYPE_STR);
	}

	/** Only these values are kept; null keeps any. */
	public function setAllowed(?array $allowed): self {
		$this->allowed = $allowed === null ? null : array_values(array_map('strval', $allowed));

		return $this;
	}

	public function setValue($value): self {
		$this->value = self::clean($value, $this->max_count, $this->max_chars, $this->allowed);

		return $this;
	}

	public function setDefault($value): self {
		$this->default = array_values(array_map('strval', (array) $value));

		return $this;
	}

	public function validate(bool $strict = false): array {
		$this->setValue($this->getValue());

		return [];
	}

	public function toApi(array &$widget_fields = []): void {
		$value = array_values($this->getValue());

		if ($value === array_values($this->getDefault())) {
			return;
		}

		foreach ($value ?: [''] as $index => $each) {
			$widget_fields[] = ['type' => ZBX_WIDGET_FIELD_TYPE_STR, 'name' => $this->name.'.'.$index, 'value' => $each];
		}
	}

	/** The list as stored: strings, trimmed, no empties, no repeats, within the limits. */
	public static function clean($value, int $max_count = 200, int $max_chars = 255, ?array $allowed = null): array {
		$out = [];

		foreach ((array) ($value === '' || $value === null ? [] : $value) as $v) {
			if (!is_scalar($v)) {
				continue;
			}

			$v = trim((string) $v);

			if ($v === '' || mb_strlen($v) > $max_chars || in_array($v, $out, true)
					|| ($allowed !== null && !in_array($v, $allowed, true))) {
				continue;
			}

			$out[] = $v;

			if (count($out) >= $max_count) {
				break;
			}
		}

		return $out;
	}
}
