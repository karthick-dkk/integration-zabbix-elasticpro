<?php declare(strict_types = 0);

namespace Modules\EpTophosts\Lib;

/**
 * What a figure becomes once a widget has read it, the same for every widget on the column model:
 * the cell a column draws (its text, the reason when there is none, its level and colour, its
 * bar, badge, indicators or sparkline, where it links), how a column's cells sit, whether a row
 * needs attention and the chips that say why, the totals row, the order rows come in, and how a
 * figure and its header are written in the export.
 *
 * Pure — every figure comes in as an argument — so the tests can hold all of it without a
 * Zabbix. Unknown is not zero anywhere here: a figure nobody measured is null all the way
 * through and says so in the cell.
 *
 * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.
 */
class ColumnCell {

	/** The bar styles a column may ask for, as Bars names them; 'gauge' is drawn here. */
	public const BAR_STYLES = [Columns::BAR_UNDER => 'under', Columns::BAR_CELL => 'cell', Columns::BAR_SEGMENTS => 'segments', Columns::BAR_GAUGE => 'gauge'];

	/**
	 * The level a column's figure is judged on: its share when it has one (x of y, a figure with
	 * its share of allocated beside it, anything in %), its value otherwise. Thresholds of 80 / 90
	 * on "Used of allocated" are about the 82 %, not the 2.87 TB.
	 */
	public static function subject(?float $value, ?float $pct, array $column, ?array $entry): ?float {
		if ($pct !== null) {
			return $pct;
		}
		// A share that is not known is not the value instead: thresholds of 80 on a size in bytes
		// would colour every disk red.
		if (((int) $column['source'] === Columns::SOURCE_CALC && (int) $column['calc_op'] === Columns::OP_OF) || !empty($entry['pct'])) {
			return null;
		}
		return $value;
	}

	/** The thresholds for one client and column: the host's macros, then the column's own, then the widget's, then Settings. */
	public static function usage(array $macros, array $column, $widgetWarn, $widgetHigh, array $cfg): array {
		$pick = fn($own, $widget) => $own !== '' && $own !== null ? $own : ($widget !== '' && $widget !== null ? $widget : Settings::INHERIT);
		return Settings::usageFor($macros, $pick($column['warn'] ?? '', $widgetWarn), $pick($column['high'] ?? '', $widgetHigh), $cfg);
	}

	/**
	 * A figure written as a column asks: Zabbix's own convertUnits() when the column leaves sizes
	 * to it (so a built-in column reads exactly as the report always drew it), otherwise in the
	 * size unit it fixes, shortened when asked. A change carries its sign.
	 */
	public static function text(?float $v, string $units, array $column, array $cfg, bool $signed = false): string {
		if ($v === null) {
			return '—';
		}
		$decimals = $column['decimals'] === '' ? Settings::decimals($units === 'uptime' ? '' : $units, $cfg) : (int) $column['decimals'];
		if ((int) $column['size_unit'] === Columns::SIZE_AUTO && !$column['short'] && function_exists('convertUnits')) {
			$text = convertUnits(['value' => $v, 'units' => $units, 'decimals' => $decimals]);
			return $signed && $v > 0 ? '+'.$text : (string) $text;
		}
		return ColumnMath::formatColumn($v, $units === 'uptime' ? 's' : $units, $decimals, (bool) $column['short'], (int) $column['size_unit'], $signed);
	}

	/** Percent written with the site's decimals: "82 %" (or "82%" when Zabbix is not there to say otherwise). */
	public static function pctText(?float $pct, array $cfg): string {
		if ($pct === null) {
			return '—';
		}
		return function_exists('convertUnits')
			? (string) convertUnits(['value' => $pct, 'units' => '%', 'decimals' => Settings::decimals('%', $cfg)])
			: ColumnMath::format($pct, '%', Settings::decimals('%', $cfg), false);
	}

	/**
	 * One cell, ready to draw.
	 *
	 * @param array $f  what WidgetView found: value, pct, text (a text column's), why (why there
	 *                  is no figure), unset (a request nobody entered), short (allocated below
	 *                  requested: the hint), units, usage (thresholds), level (a level decided
	 *                  elsewhere, as a forecast's), series, url, seen_max (the highest of this
	 *                  column across the clients, for a bar with no top), widget_bars (the
	 *                  widget's Display style)
	 *
	 * @return array{text: string, sub: string, class: string, style: string, hint: string, bar: ?array, badge: bool,
	 *               spark: ?array, ind: ?array, url: ?string, value, level: string, unknown: bool, short: bool}
	 */
	public static function cell(array $column, ?array $entry, array $f, array $cfg): array {
		$cell = ['text' => '—', 'sub' => '', 'class' => '', 'style' => '', 'hint' => '', 'bar' => null, 'badge' => false, 'badge_class' => 'ep-badge', 'spark' => null,
			'ind' => null, 'url' => $f['url'] ?? null, 'value' => null, 'level' => '', 'unknown' => false, 'short' => false];
		$show = (int) $column['show'];
		$styles = [];

		// A client's own text: its name, its type, a macro.
		if (array_key_exists('text', $f)) {
			$text = (string) $f['text'];
			$cell['text'] = $text !== '' ? $text : '—';
			$cell['value'] = $text;
			if ($show === Columns::SHOW_BADGE && $text !== '') {
				$cell['badge'] = true;
				if (($entry['field'] ?? '') === 'type') {
					// The type pills the report has always drawn: DI one colour, everything else the other.
					// The pill is the text's, not the cell's: a table cell drawn inline-block falls out
					// of its row.
					$cell['class'] = $text === 'DI' ? 'ep-di' : 'ep-op';
					$cell['badge_class'] = 'ep-pill';
				}
			}
			return $cell;
		}

		$v = $f['value'] ?? null;
		$pct = $f['pct'] ?? null;
		$units = (string) ($f['units'] ?? $column['units']);
		$cell['value'] = $v;

		// A request nobody entered is not a request of nought.
		if (!empty($f['unset'])) {
			$cell['text'] = _('not set');
			$cell['class'] = 'ep-unset';
			$cell['value'] = null;
			return $cell;
		}

		if ($v === null && !(isset($f['level']) && $f['level'] !== '')) {
			// Unknown: "—", or the reason when the column asks for it; the whole story in the tooltip.
			$cell['unknown'] = true;
			$why = trim((string) ($f['why'] ?? ''));
			if ((int) $column['nodata'] === Columns::NODATA_WHY && $why !== '') {
				$cell['text'] = $why;
				$cell['class'] = 'ep-nodata';
			}
			$fix = trim((string) ($f['fix'] ?? ''));
			$cell['hint'] = trim($why.($fix !== '' ? ' — '.$fix : '').(isset($f['capped']) ? ' '.$f['capped'] : ''));
			return $cell;
		}

		$signed = (int) $column['value'] === Columns::VALUE_CHANGE;
		$valueText = isset($f['display']) ? (string) $f['display'] : self::text($v, $units, $column, $cfg, $signed);
		$pctText = self::pctText($pct, $cfg);
		if ($pct !== null && !empty($f['of'])) {
			// x of y: "2.87 TB of 3.5 TB · 82 %".
			$parts = [];
			if ($column['show_value']) {
				$parts[] = $valueText.' '._('of').' '.$f['of'];
			}
			if ($column['show_pct'] || !$parts) {
				$parts[] = $pctText;
			}
			$cell['text'] = implode(' · ', $parts);
		}
		elseif ($pct !== null && !empty($entry['pct'])) {
			// A figure with its share beside it, as Used and Seen have always been drawn.
			$cell['text'] = $column['show_value'] || !$column['show_pct'] ? $valueText : $pctText;
			if ($column['show_value'] && $column['show_pct']) {
				$cell['sub'] = '('.round($pct).' %)';
			}
		}
		else {
			$cell['text'] = $valueText;
		}

		// The level, and the colour a threshold or the column asks for.
		$level = $f['level'] ?? null;
		$color = null;
		if ($level === null) {
			$subject = self::subject($v, $pct, $column, $entry);
			$lv = ColumnMath::level($subject, $column, $f['usage'] ?? ['warn' => 80, 'high' => 90]);
			$level = $lv['level'];
			$color = $lv['color'];
		}
		$cell['level'] = $level;
		$levelClass = $level === '' ? '' : 'ep-'.$level;

		if (!empty($f['short'])) {
			$cell['short'] = true;
			$cell['hint'] = (string) $f['short'];
		}
		$hints = array_filter([$cell['hint'], (string) ($f['stale'] ?? ''), (string) ($f['capped'] ?? '')]);
		$cell['hint'] = implode(' ', $hints);

		$share = $pct !== null ? max(0.0, min(100.0, $pct))
			: ColumnMath::barShare($v, $units, $column['min'], $column['max'], $f['seen_max'] ?? null);
		if ($column['min'] !== '' || $column['max'] !== '') {
			$share = ColumnMath::barShare($pct ?? $v, $pct !== null ? '%' : $units, $column['min'], $column['max'], $f['seen_max'] ?? null);
		}

		$classes = [];
		switch ($show) {
			case Columns::SHOW_BAR:
				$classes[] = $levelClass;
				$style = (int) $column['bar_style'] === Columns::BAR_WIDGET
					? (string) ($f['widget_bars'] ?? Bars::DEFAULT_STYLE)
					: self::BAR_STYLES[(int) $column['bar_style']];
				if ($share !== null && $style !== 'none') {
					$cell['bar'] = ['share' => $share, 'style' => $style, 'segments' => (int) $column['segments']];
					$classes[] = 'ep-barcell';
				}
				break;
			case Columns::SHOW_TEXT:
				$classes[] = $levelClass;
				break;
			case Columns::SHOW_CELL:
				if ($level !== '') {
					$classes[] = 'ep-fill ep-fill-'.$level;
				}
				break;
			case Columns::SHOW_BADGE:
				$cell['badge'] = true;
				$classes[] = $level !== '' ? 'ep-badge-'.$level : 'ep-badge-ok';
				break;
			case Columns::SHOW_INDICATORS:
				$classes[] = $levelClass;
				if ($share !== null) {
					$cell['ind'] = ['lit' => (int) round($share / 10), 'of' => 10];
					$classes[] = 'ep-barcell';
				}
				break;
			case Columns::SHOW_SPARKLINE:
				$classes[] = $levelClass;
				$points = $f['series'] ?? [];
				$cell['spark'] = count($points) >= 2 ? ColumnMath::downsample($points, 40) : null;
				break;
		}
		if ($cell['short']) {
			$classes[] = 'ep-short';
		}
		if (!empty($f['stale'])) {
			$classes[] = 'ep-stale';
		}

		// Colours a column or a threshold chose: strictly #RRGGBB (Columns::color), never anything else.
		if ($color !== null && $level !== '') {
			$styles[] = '--ep-lv:'.$color;
			$styles[] = '--ep-lv-fg:'.Settings::textOn(strtolower($color))['color'];
			$classes[] = 'ep-lvc';
		}
		if ((int) $column['bar_color'] === Columns::BARCOLOR_FIXED && ($fixed = Columns::color($column['bar_fixed'])) !== null) {
			$styles[] = '--ep-bar:'.$fixed;
			$classes[] = 'ep-barfixed';
		}
		elseif ($color !== null && $level !== '') {
			$styles[] = '--ep-bar:'.$color;
			$classes[] = 'ep-barfixed';
		}
		if (($track = Columns::color($column['track'])) !== null) {
			$styles[] = '--ep-track:'.$track;
		}
		$cell['class'] = trim(implode(' ', array_filter($classes)));
		$cell['style'] = implode(';', $styles);
		return $cell;
	}

	/** Alignment, width and wrapping a column asks for, as classes and a style for its cells. */
	public static function layout(array $column): array {
		$classes = [];
		$align = [Columns::ALIGN_LEFT => 'ep-al-l', Columns::ALIGN_CENTRE => 'ep-al-c', Columns::ALIGN_RIGHT => 'ep-al-r'][(int) $column['align']] ?? null;
		if ($align !== null) {
			$classes[] = $align;
		}
		if ($column['wrap']) {
			$classes[] = 'ep-wrap';
		}
		return ['class' => implode(' ', $classes), 'style' => $column['width'] !== '' ? 'width:'.(int) $column['width'].'px;min-width:'.(int) $column['width'].'px' : ''];
	}

	/** A forecast in seconds as its column says it: "23 days", "not growing", and a level from the site's bands. */
	public static function forecast(?float $seconds, array $cfg): array {
		$f = Forecast::fullIn($seconds, Settings::forecast($cfg));
		if ($f === null) {
			return ['text' => null, 'level' => '', 'days' => null];
		}
		return ['text' => $f['text'], 'level' => $f['level'] === 'bad' ? 'high' : ($f['level'] === 'warn' ? 'warn' : ''), 'days' => $f['days']];
	}

	/**
	 * Whether a client needs attention, by what the widget says that means: a cell over Warn or
	 * over High, a server down, allocated short of requested, a server sending no data, ES storage
	 * full within 30 days.
	 *
	 * @param array $flags  warn, high, short (cells), down, nodata (servers), silent (nothing of the client reports:
	 *                      no figure, and no server sending), full_days (?float)
	 */
	/**
	 * Whether nothing of a client reports: no server of it sends anything (it has none, or every one is
	 * "no data"), and not one of its cells has a figure other than 0. Such a client is "no data", never OK.
	 *
	 * Zeros do not count: a client with no servers still has a master host, whose items say 0 — the
	 * requests nobody entered, the servers counted in each role (none) — as "example" on the test Zabbix
	 * does (77 items, every one 0). Those are the absence of anything reporting, not a report.
	 *
	 * @param array $cells    the client's cells (ColumnCell::cell)
	 * @param array $servers  its servers, each with status up / down / unknown
	 */
	public static function silent(array $cells, array $servers): bool {
		foreach ($cells as $cell) {
			$v = $cell['value'] ?? null;
			if ((is_int($v) || is_float($v)) && $v != 0) {
				return false;
			}
		}
		foreach ($servers as $s) {
			if (($s['status'] ?? 'unknown') !== 'unknown') {
				return false;
			}
		}

		return true;
	}

	public static function attention(array $flags, array $criteria): bool {
		foreach ($criteria as $c) {
			switch ($c) {
				case 'warn':
					if (($flags['warn'] ?? 0) > 0 || ($flags['high'] ?? 0) > 0) {
						return true;
					}
					break;
				case 'nodata':
					// A server sending nothing — or a client of which nothing reports at all (no servers, no figure).
					if (($flags['nodata'] ?? 0) > 0 || !empty($flags['silent'])) {
						return true;
					}
					break;
				case 'high':
				case 'down':
				case 'short':
					if (($flags[$c] ?? 0) > 0) {
						return true;
					}
					break;
				case 'disk30':
					if (($flags['full_days'] ?? null) !== null && $flags['full_days'] <= 30) {
						return true;
					}
					break;
			}
		}
		return false;
	}

	/** The status chips over a client's donuts: "1 down", "2 high", "1 warn", "3 short", "1 no data" — or "OK". */
	public static function chips(array $flags): array {
		$out = [];
		foreach ([['down', 'high', _('%1$s down')], ['high', 'high', _('%1$s high')], ['warn', 'warn', _('%1$s warn')],
				['short', 'warn', _('%1$s short')], ['nodata', 'warn', _('%1$s no data')]] as [$k, $level, $text]) {
			if (($flags[$k] ?? 0) > 0) {
				$out[] = ['text' => _s($text, $flags[$k]), 'level' => $level];
			}
		}
		// Nothing of the client reports: no data, not OK — and no count, since no server is behind it.
		if (!empty($flags['silent']) && ($flags['nodata'] ?? 0) === 0) {
			$out[] = ['text' => _('no data'), 'level' => 'warn'];
		}
		return $out ?: [['text' => _('OK'), 'level' => 'ok']];
	}

	/**
	 * The totals row: per numeric column, the sum (or average) of the clients' figures — the
	 * known ones only, and nothing when none is known. A percentage is always averaged: a sum of
	 * shares is not a share of anything.
	 */
	public static function total(array $values, string $units, bool $average): ?float {
		$known = array_values(array_filter($values, fn($v) => $v !== null && is_numeric($v)));
		if (!$known) {
			return null;
		}
		return $average || $units === '%' ? array_sum($known) / count($known) : (float) array_sum($known);
	}

	/**
	 * The clients in the widget's order: by a column's figure (unknown last either way, never
	 * first as a 0 would be; ties by name) or by name, then the top N.
	 *
	 * @param array $rows  each with 'client' and 'sort' => uid => value
	 */
	public static function arrange(array $rows, string $by, bool $desc, ?int $top): array {
		usort($rows, function ($a, $b) use ($by, $desc) {
			if ($by !== 'client') {
				$x = $a['sort'][$by] ?? null;
				$y = $b['sort'][$by] ?? null;
				if ($x === null xor $y === null) {
					return $x === null ? 1 : -1;
				}
				if ($x !== null && $x != $y) {
					$c = is_string($x) || is_string($y) ? strnatcasecmp((string) $x, (string) $y) : ($x <=> $y);
					return $desc ? -$c : $c;
				}
				return strnatcasecmp($a['client'], $b['client']);
			}
			$c = strnatcasecmp($a['client'], $b['client']);
			return $desc ? -$c : $c;
		});
		return $top !== null && $top > 0 ? array_slice($rows, 0, $top) : $rows;
	}

	/** A column's header in the export, its size unit said as the export divides by it. */
	public static function exportHeader(array $column, string $units, array $cfg, bool $withGroup = true): string {
		$head = ($withGroup && $column['group'] !== '' ? $column['group'].' ' : '').$column['name'];
		if ($units === 'B' && (int) $column['value'] !== Columns::VALUE_CHANGE) {
			return $head.' ('.Settings::sizeLabel($cfg).')';
		}
		// A unit Zabbix is told not to scale ("!GB") is said as it is.
		return $head.($units === '%' ? ' (%)' : ($units === 'days' ? ' (days)' : ($units !== '' && $units[0] === '!' ? ' ('.substr($units, 1).')' : '')));
	}

	/**
	 * The places an export writes a figure to: a percentage ('%') and every other number ('', sizes
	 * after they are divided into the site's export unit). These are the places Client resources and
	 * Client capacity have always exported with; a widget whose export always had others (Volume:
	 * two places for everything) says so with ColumnReport::exportPlaces(). An export's places are
	 * the widget's own and never follow the Decimals a table is drawn with, so changing how a figure
	 * looks never changes a spreadsheet built on it.
	 */
	public const EXPORT_PLACES = ['%' => 1, '' => 2];

	/**
	 * A figure as the export writes it: sizes in the site's export unit, to the places $places says
	 * (EXPORT_PLACES unless the widget has its own), unknown empty.
	 */
	public static function exportValue($value, string $units, array $cfg, array $places = self::EXPORT_PLACES) {
		if ($value === null || !is_numeric($value)) {
			return is_string($value) && $value !== '' ? $value : null;
		}
		$places += self::EXPORT_PLACES;

		return $units === 'B' ? round($value / Settings::sizeDivisor($cfg), (int) $places[''])
			: round((float) $value, (int) $places[$units === '%' ? '%' : '']);
	}
}
