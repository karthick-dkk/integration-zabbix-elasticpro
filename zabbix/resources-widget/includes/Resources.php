<?php declare(strict_types = 0);

namespace Modules\EpResources\Includes;

use Modules\EpResources\Lib\{ColumnCell, ColumnMath, Columns};

/**
 * What Client resources makes of a figure once WidgetView has read it. The cell a column draws,
 * whether a client needs attention, the totals row and the order of the clients are the shared
 * column model's (ColumnCell, the same for every widget on it); the methods of that name here
 * hand straight to it. What is this widget's own is the donuts of the Pie chart and the chips
 * over them.
 *
 * Pure — every figure comes in as an argument — so the tests can hold all of it without a
 * Zabbix. Unknown is not zero anywhere here: a figure nobody measured is null all the way through
 * and says so in the cell, and a donut whose allocated is not set is not drawn as a guess.
 */
class Resources {

	public const BAR_STYLES = ColumnCell::BAR_STYLES;

	public static function subject(?float $value, ?float $pct, array $column, ?array $entry): ?float {
		return ColumnCell::subject($value, $pct, $column, $entry);
	}

	public static function usage(array $macros, array $column, $widgetWarn, $widgetHigh, array $cfg): array {
		return ColumnCell::usage($macros, $column, $widgetWarn, $widgetHigh, $cfg);
	}

	public static function text(?float $v, string $units, array $column, array $cfg, bool $signed = false): string {
		return ColumnCell::text($v, $units, $column, $cfg, $signed);
	}

	public static function pctText(?float $pct, array $cfg): string {
		return ColumnCell::pctText($pct, $cfg);
	}

	public static function cell(array $column, ?array $entry, array $f, array $cfg): array {
		return ColumnCell::cell($column, $entry, $f, $cfg);
	}

	public static function layout(array $column): array {
		return ColumnCell::layout($column);
	}

	public static function forecast(?float $seconds, array $cfg): array {
		return ColumnCell::forecast($seconds, $cfg);
	}

	public static function attention(array $flags, array $criteria): bool {
		return ColumnCell::attention($flags, $criteria);
	}

	public static function chips(array $flags): array {
		return ColumnCell::chips($flags);
	}

	public static function total(array $values, string $units, bool $average): ?float {
		return ColumnCell::total($values, $units, $average);
	}

	public static function arrange(array $rows, string $by, bool $desc, ?int $top): array {
		return ColumnCell::arrange($rows, $by, $desc, $top);
	}

	public static function exportHeader(array $column, string $units, array $cfg): string {
		return ColumnCell::exportHeader($column, $units, $cfg);
	}

	public static function exportValue($value, string $units, array $cfg) {
		return ColumnCell::exportValue($value, $units, $cfg);
	}

	/**
	 * One donut: used, free and short around a centre that says what the widget chose, a legend
	 * of the figures with their shares, and a level from the share of allocated used — or, with
	 * allocated not set (or used not measured), a donut that says so and draws no ring.
	 *
	 * @param array $m  measure: label, units; $v: used, allocated, requested (?float each)
	 */
	public static function donut(array $m, array $v, array $opts, array $usage, array $cfg): array {
		$withShort = (int) ($opts['short'] ?? WidgetForm::SHORT_HATCHED) !== WidgetForm::SHORT_HIDDEN;
		$pie = ColumnMath::pie($v['used'] ?? null, $v['allocated'] ?? null, $v['requested'] ?? null, $withShort);
		$units = (string) $m['units'];
		$col = Columns::DEFAULT;
		$fmt = fn(?float $x) => $x === null ? '—' : ($units === 'cores' ? self::text($x, '', $col, $cfg).' '._('cores') : self::text($x, $units, $col, $cfg));
		$pct = fn(?float $x) => $x === null ? '' : round($x).' %';
		$legendOn = array_flip($opts['legend'] ?? WidgetForm::LEGEND);
		$withPct = isset($legendOn['pct']);
		$out = ['label' => $m['label'], 'known' => $pie['known'], 'level' => 'ok', 'centre' => '—', 'sub' => '', 'segments' => $pie['segments'],
			'legend' => [], 'aria' => ''];
		if (!$pie['known']) {
			$out['level'] = 'unknown';
			$out['sub'] = $pie['why'] === 'allocated' ? _('allocated not set') : _('used not measured');
			$out['aria'] = $m['label'].': '.$out['sub'];
			if (isset($legendOn['used'])) {
				$out['legend'][] = ['key' => 'used', 'label' => _('Used'), 'value' => $fmt($pie['used']), 'pct' => ''];
			}
			if (isset($legendOn['allocated'])) {
				$out['legend'][] = ['key' => 'allocated', 'label' => _('Allocated'), 'value' => $pie['allocated'] === null ? _('not set') : $fmt($pie['allocated']), 'pct' => ''];
			}
			if (isset($legendOn['requested'])) {
				$out['legend'][] = ['key' => 'requested', 'label' => _('Requested'), 'value' => $pie['requested'] === null ? _('not set') : $fmt($pie['requested']), 'pct' => ''];
			}
			return $out;
		}
		$level = ColumnMath::level($pie['pct_allocated'], ['use_settings' => 1], $usage)['level'];
		$out['level'] = $level === '' ? 'ok' : $level;
		switch ((int) ($opts['centre'] ?? WidgetForm::CENTRE_PCT_ALLOCATED)) {
			case WidgetForm::CENTRE_PCT_REQUESTED:
				$out['centre'] = $pie['pct_requested'] === null ? '—' : round($pie['pct_requested']).'%';
				$out['sub'] = $pie['pct_requested'] === null ? _('requested not set') : _('used of requested');
				break;
			case WidgetForm::CENTRE_USED:
				$out['centre'] = $fmt($pie['used']);
				$out['sub'] = _('used');
				break;
			case WidgetForm::CENTRE_FREE:
				$out['centre'] = $fmt($pie['free']);
				$out['sub'] = _('free');
				break;
			default:
				$out['centre'] = round($pie['pct_allocated']).'%';
				$out['sub'] = _('used of allocated');
		}
		$out['aria'] = _s('%1$s: %2$s percent of allocated used', $m['label'], round($pie['pct_allocated']));
		$rows = [
			'used' => [_('Used'), $pie['used'], $pie['segments']['used']],
			'free' => [_('Free'), $pie['free'], $pie['segments']['free']],
			'short' => [_('Short'), $pie['short'], $pie['segments']['short']],
			'allocated' => [_('Allocated'), $pie['allocated'], null],
			'requested' => [_('Requested'), $pie['requested'], null]
		];
		foreach ($rows as $k => [$label, $x, $share]) {
			if (!isset($legendOn[$k]) || ($k === 'short' && (!$withShort || !$pie['short']))) {
				continue;
			}
			$out['legend'][] = ['key' => $k, 'label' => $label, 'value' => $k === 'requested' && $x === null ? _('not set') : $fmt($x),
				'pct' => $withPct && $share !== null ? $pct($share) : ''];
		}
		return $out;
	}
}
