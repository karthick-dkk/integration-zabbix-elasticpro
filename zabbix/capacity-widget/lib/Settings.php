<?php declare(strict_types = 0);

namespace Modules\EpCapacity\Lib;

/**
 * The site's own numbers: where usage turns yellow and red, how figures are drawn and rounded,
 * when a filling disk is called urgent, and which client types exist. One setting for everyone,
 * changed by Super admins on ElasticPro -> Settings, read by the Cluster Management page and all
 * three report widgets.
 *
 * Kept in the data folder as settings.json. A dashboard widget may override its own thresholds
 * and bar style, and a client's master host overrides the thresholds for itself with
 * {$EP.USAGE.WARN} / {$EP.USAGE.HIGH} — so the order everywhere is
 *
 *   the host's macros  ->  this widget's own field  ->  this file  ->  the shipped default
 *
 * and usageFor() is the one place that knows it.
 *
 * Nothing here is in Store::OWN_FILES, and settings.json must never be added to it. That list
 * decides which of the two data folders is the live one, and a folder that holds only this file
 * is a folder nobody has configured yet: letting it win would hand Roles::load() the shipped
 * defaults and the next template write would delete every per-role item with its history. The
 * reasoning in full is in Store, above that constant.
 *
 * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.
 */
class Settings {

	public const FILE = 'settings.json';

	/** The three report widgets, as the keys refresh() and the Columns dialog name them. */
	public const REPORTS = ['capacity', 'resources', 'volume'];

	/**
	 * What a size column is divided by for the export, under the names people use for them.
	 * Binary multiples on purpose: the table itself is drawn by Zabbix's convertUnits(), which is
	 * 1024-based, so a decimal (1000-based) option here would make the export disagree with the
	 * figure beside it.
	 */
	public const SIZE_UNITS = ['MB' => 1048576, 'GB' => 1073741824, 'TB' => 1099511627776];

	/** Written into every file so a later release can tell what it is reading. */
	public const VERSION = 1;

	/**
	 * What ElasticPro ships. Every one of these was a literal in PHP or JSON before this file
	 * existed, so a site that never opens the Settings page sees exactly what it saw then.
	 *
	 * `types` repeats ClientTypes::ALL and ::DEFAULT as literals rather than naming them: the
	 * widgets' ColumnsSave loads this class without ClientTypes, and a reference would be fatal
	 * there. A test holds the two in step.
	 *
	 * `refresh` is the default of record, not a setting that can be applied. Zabbix reads a
	 * widget's refresh_rate from its manifest once, when the module loads, and a placed widget's
	 * interval lives in each user's own profile from then on — changed in the widget's menu on
	 * the dashboard, and reachable from nowhere in PHP. It is here so the manifests have one
	 * place to agree with, and the Settings page shows it read-only.
	 */
	public const DEFAULTS = [
		'usage' => ['warn' => 80.0, 'high' => 90.0],
		'display' => ['bars' => 0, 'decimals_bytes' => 2, 'decimals_percent' => 1, 'decimals_plain' => 0],
		'export' => ['size_unit' => 'GB'],
		'refresh' => ['capacity' => 60, 'resources' => 60, 'volume' => 300],
		'forecast' => ['bad_days' => 7, 'warn_days' => 30, 'flat_days' => 3650,
			'day_text_days' => 1, 'month_text_days' => 60, 'month_days' => 30],
		'types' => ['all' => ['CI', 'DI', 'On-Prem'], 'default' => 'On-Prem'],
		// The colours every ElasticPro widget draws with: the panel's own, light and dark, as
		// shared/ep-panel.css has always had them, so a site that never opens the Colours card
		// sees what it saw before. The series are the Client overview's donut-by-role colours.
		'palette' => [
			'light' => ['ok' => '#3e9b3a', 'warn' => '#e5860b', 'high' => '#e0314a', 'unknown' => '#9aa0aa', 'short' => '#e0314a',
				'free' => '#e3e6ea', 'down' => '#e0314a', 'stale' => '#e5860b', 'link' => '#1f62e0'],
			'dark' => ['ok' => '#73bf69', 'warn' => '#ff9830', 'high' => '#f2495c', 'unknown' => '#6b6f7c', 'short' => '#f2495c',
				'free' => '#22252b', 'down' => '#f2495c', 'stale' => '#ff9830', 'link' => '#6e9fff'],
			'series' => ['#5794f2', '#73bf69', '#b877d9', '#fade2a', '#ff9830', '#8ab8ff', '#ca95e5', '#96d98d']
		]
	];

	/**
	 * The palette's colours, in the order the Colours card lists them, each with what it is used
	 * for. The CSS custom property each becomes is --ep-<key> (unknown is --ep-faint and free is
	 * --ep-track, the names the panel sheet already gave them).
	 */
	public const PALETTE_KEYS = ['ok', 'warn', 'high', 'unknown', 'short', 'free', 'down', 'stale', 'link'];
	public const PALETTE_SERIES = 8;
	private const PALETTE_VARS = ['ok' => 'ok', 'warn' => 'warn', 'high' => 'high', 'unknown' => 'faint', 'short' => 'short', 'free' => 'track',
		'down' => 'down', 'stale' => 'stale', 'link' => 'link'];

	/** The page a theme's colours are judged against: Zabbix's panel background, light and dark. */
	public const PALETTE_BACKGROUND = ['light' => '#ffffff', 'dark' => '#181b1f'];

	/** Text on a coloured cell or badge: white where it reads, this where it does not. */
	public const DARK_TEXT = '#111217';

	/** WCAG AA for normal text. A colour whose white text falls below it is flagged and gets dark text. */
	public const MIN_CONTRAST = 4.5;

	/** The master host macros that override the thresholds for one client. */
	public const WARN_MACRO = '{$EP.USAGE.WARN}';
	public const HIGH_MACRO = '{$EP.USAGE.HIGH}';

	/** What a widget field holds when it has been left alone. Not a threshold: 0 colours nothing. */
	public const INHERIT = 0;

	/**
	 * The same, for a setting chosen from a list rather than typed — where 0 is a real answer, as
	 * it is for a number of decimal places. A value well clear of the choices, so one that slips
	 * through unresolved falls back rather than being drawn as a choice. Bars::INHERIT is the same
	 * number for the same reason, named there because Bars is loaded in places this class is not.
	 */
	public const INHERIT_CHOICE = 100;

	/** Read once per request: a 30-column report asks for these on every row. */
	private static $loaded = null;

	/**
	 * The settings in force. A missing file means the defaults, and a file somebody edited by
	 * hand is cleaned on the way in rather than refused — a report that will not draw is worse
	 * than a report drawn with a number put back in range.
	 */
	public static function all(): array {
		if (self::$loaded === null) {
			self::$loaded = self::clean((array) Store::read(self::FILE, []));
		}
		return self::$loaded;
	}

	/** Settings as they came from the page, cleaned, then saved. Returns what was stored. */
	public static function save(array $in): array {
		$clean = self::clean($in);
		Store::write(self::FILE, ['version' => self::VERSION] + $clean);
		self::$loaded = $clean;
		return $clean;
	}

	/**
	 * Back to what ElasticPro ships. Answers whether the file is actually gone: the page said
	 * "back to the ones ElasticPro ships" either way, so a folder that had stopped being
	 * writable left the old settings in force under a success banner.
	 */
	public static function reset(): bool {
		$gone = Store::delete(self::FILE);
		self::$loaded = null;
		return $gone;
	}

	/**
	 * Every rule in one pure function, so the page, the file and the tests cannot disagree.
	 *
	 * A value that is absent or of the wrong kind falls back to the shipped one. A number that is
	 * out of range is brought to the nearest end of it rather than replaced, because a figure
	 * somebody typed says what they wanted even when it cannot be had: 120 % means "as high as it
	 * goes", and answering 80 would look like the save had been ignored. A pair that contradicts
	 * itself is ordered rather than refused.
	 */
	public static function clean(array $in): array {
		$d = self::DEFAULTS;
		$num = fn($v, float $lo, float $hi, float $fallback): float => is_numeric($v) ? max($lo, min($hi, (float) $v)) : $fallback;
		$int = fn($v, int $lo, int $hi, int $fallback): int => is_numeric($v) ? max($lo, min($hi, (int) $v)) : $fallback;

		// Usage. An inverted pair would paint every cell red and read as a fault in the product,
		// so warn comes down to high rather than high going up to warn.
		// High has a floor of 1: at 0 every figure is "at or above", so every cell goes red and
		// — since the templates take their macros from here — the jump host's triggers alarm on
		// every cluster at once. Warn may be 0, which simply means "always at least yellow".
		$usage_in = (array) ($in['usage'] ?? []);
		$high = $num($usage_in['high'] ?? null, 1.0, 100.0, $d['usage']['high']);
		$warn = min($num($usage_in['warn'] ?? null, 0.0, 100.0, $d['usage']['warn']), $high);

		// Display. bars is a position in Bars::STYLES, named here as a range so this class needs
		// no reference to Bars — see the note on DEFAULTS.
		$display_in = (array) ($in['display'] ?? []);
		$bars = $display_in['bars'] ?? null;
		$display = [
			'bars' => is_numeric($bars) && (int) $bars >= 0 && (int) $bars <= 3 ? (int) $bars : $d['display']['bars'],
			'decimals_bytes' => $int($display_in['decimals_bytes'] ?? null, 0, 4, $d['display']['decimals_bytes']),
			'decimals_percent' => $int($display_in['decimals_percent'] ?? null, 0, 4, $d['display']['decimals_percent']),
			'decimals_plain' => $int($display_in['decimals_plain'] ?? null, 0, 4, $d['display']['decimals_plain'])
		];

		$export_in = (array) ($in['export'] ?? []);
		$unit = strtoupper(trim((string) ($export_in['size_unit'] ?? '')));
		$export = ['size_unit' => isset(self::SIZE_UNITS[$unit]) ? $unit : $d['export']['size_unit']];

		$refresh_in = (array) ($in['refresh'] ?? []);
		$refresh = [];
		foreach (self::REPORTS as $report) {
			$refresh[$report] = $int($refresh_in[$report] ?? null, 10, 3600, $d['refresh'][$report]);
		}

		// Forecast, widest window first: each bound is then held at or below the one outside it,
		// so "red under 40 days, yellow under 30" cannot leave a band that never shows.
		$f_in = (array) ($in['forecast'] ?? []);
		$flat = $int($f_in['flat_days'] ?? null, 1, 36500, $d['forecast']['flat_days']);
		$warn_days = min($int($f_in['warn_days'] ?? null, 1, 36500, $d['forecast']['warn_days']), $flat);
		$bad_days = min($int($f_in['bad_days'] ?? null, 1, 36500, $d['forecast']['bad_days']), $warn_days);
		$day_text = $int($f_in['day_text_days'] ?? null, 1, 30, $d['forecast']['day_text_days']);
		$forecast = [
			'bad_days' => $bad_days,
			'warn_days' => $warn_days,
			'flat_days' => $flat,
			'day_text_days' => $day_text,
			'month_text_days' => max($int($f_in['month_text_days'] ?? null, 1, 36500, $d['forecast']['month_text_days']), $day_text),
			'month_days' => $int($f_in['month_days'] ?? null, 1, 366, $d['forecast']['month_days'])
		];

		// Types. Spelt as the operator spelt them, but no two that differ only in case, because
		// a client's {$EP.CLIENT.TYPE} is matched against this list as it is written.
		$types_in = (array) ($in['types'] ?? []);
		$all = [];
		$seen = [];
		foreach ((array) ($types_in['all'] ?? []) as $t) {
			if (!is_string($t) && !is_numeric($t)) {
				continue;
			}
			$t = mb_substr(trim((string) $t), 0, 32);
			$k = mb_strtolower($t);
			if ($t !== '' && !isset($seen[$k]) && count($all) < 8) {
				$seen[$k] = true;
				$all[] = $t;
			}
		}
		if (!$all) {
			$all = $d['types']['all'];
		}
		// A default that is not on the list — removed, or never given — goes to the shipped one
		// while the list still has it, and only then to whatever is first.
		$default = trim((string) ($types_in['default'] ?? ''));
		if (!in_array($default, $all, true)) {
			$default = in_array($d['types']['default'], $all, true) ? $d['types']['default'] : $all[0];
		}
		$types = ['all' => $all, 'default' => $default];

		return ['usage' => ['warn' => $warn, 'high' => $high], 'display' => $display, 'export' => $export,
			'refresh' => $refresh, 'forecast' => $forecast, 'types' => $types, 'palette' => self::cleanPalette((array) ($in['palette'] ?? []))];
	}

	/**
	 * The palette, cleaned: every colour #rrggbb (six hex digits, nothing else — it goes into a
	 * style attribute), anything else back to the shipped colour for that place. One theme's
	 * colour never fills in for the other's.
	 */
	public static function cleanPalette(array $in): array {
		$d = self::DEFAULTS['palette'];
		$out = [];
		foreach (['light', 'dark'] as $theme) {
			$t = (array) ($in[$theme] ?? []);
			foreach (self::PALETTE_KEYS as $k) {
				$out[$theme][$k] = self::hex($t[$k] ?? null) ?? $d[$theme][$k];
			}
		}
		$series = array_values((array) ($in['series'] ?? []));
		for ($i = 0; $i < self::PALETTE_SERIES; $i++) {
			$out['series'][$i] = self::hex($series[$i] ?? null) ?? $d['series'][$i];
		}
		return $out;
	}

	/**
	 * What in a palette as typed will not be kept, one line per colour, for the page to say beside
	 * "Settings saved". Empty when every colour is a colour.
	 */
	public static function paletteErrors(array $in, array $labels = []): array {
		$errors = [];
		foreach (['light' => _('Blue theme'), 'dark' => _('Dark theme')] as $theme => $themeLabel) {
			foreach (self::PALETTE_KEYS as $k) {
				$v = $in[$theme][$k] ?? null;
				if ($v !== null && trim((string) $v) !== '' && self::hex($v) === null) {
					$errors[] = _s('%1$s, %2$s: "%3$s" is not a colour like #1f62e0 — the shipped colour is kept.', $labels[$k] ?? $k, $themeLabel,
						mb_substr((string) $v, 0, 32));
				}
			}
		}
		foreach (array_values((array) ($in['series'] ?? [])) as $i => $v) {
			if ($v !== null && trim((string) $v) !== '' && self::hex($v) === null) {
				$errors[] = _s('Chart series %1$s: "%2$s" is not a colour like #1f62e0 — the shipped colour is kept.', $i + 1, mb_substr((string) $v, 0, 32));
			}
		}
		return $errors;
	}

	/** #rrggbb, lower case, or null for anything that is not six hex digits (with or without #). */
	public static function hex($v): ?string {
		if (!is_string($v)) {
			return null;
		}
		$v = trim($v);
		return preg_match('/^#?([0-9a-fA-F]{6})$/', $v, $m) ? '#'.strtolower($m[1]) : null;
	}

	/** The palette in force. */
	public static function palette(?array $s = null): array {
		return ($s ?? self::all())['palette'];
	}

	/** WCAG relative luminance of #rrggbb. */
	public static function luminance(string $hex): float {
		$hex = ltrim($hex, '#');
		$lin = function (int $c): float {
			$c /= 255;
			return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
		};
		return 0.2126 * $lin(hexdec(substr($hex, 0, 2))) + 0.7152 * $lin(hexdec(substr($hex, 2, 2))) + 0.0722 * $lin(hexdec(substr($hex, 4, 2)));
	}

	/** WCAG contrast ratio of two #rrggbb colours, 1 to 21. */
	public static function contrast(string $a, string $b): float {
		[$x, $y] = [self::luminance($a), self::luminance($b)];
		return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
	}

	/**
	 * The text a colour carries when it fills a cell or a badge: white when white reads at
	 * MIN_CONTRAST or better. Below that the colour is flagged — the Colours card says so beside it
	 * — and carries dark text instead, unless dark text would read even worse than the white it
	 * replaces (a mid red, say), in which case white stays: the flag is the warning, not a swap
	 * that makes it worse.
	 *
	 * @return array{color: string, ratio: float, flagged: bool, white: float}
	 */
	public static function textOn(string $hex): array {
		$white = self::contrast($hex, '#ffffff');
		if ($white >= self::MIN_CONTRAST) {
			return ['color' => '#ffffff', 'ratio' => round($white, 1), 'flagged' => false, 'white' => round($white, 1)];
		}
		$dark = self::contrast($hex, self::DARK_TEXT);
		return ['color' => $dark >= $white ? self::DARK_TEXT : '#ffffff', 'ratio' => round(max($dark, $white), 1), 'flagged' => true,
			'white' => round($white, 1)];
	}

	/** 'dark' under Zabbix's Dark and high-contrast dark themes, 'light' under every other. */
	public static function themeOf(?string $theme): string {
		return in_array($theme, ['dark-theme', 'hc-dark'], true) ? 'dark' : 'light';
	}

	/**
	 * The palette for one theme as CSS custom properties, for a widget's panel to carry in its
	 * style attribute: --ep-ok, --ep-ok-fg (the text that reads on it), … --ep-s1 … --ep-s8. Every
	 * value has been through hex(), so nothing else can reach the attribute.
	 */
	public static function paletteVars(array $palette, string $theme): string {
		$p = self::cleanPalette($palette);
		$set = $p[$theme === 'dark' ? 'dark' : 'light'];
		$out = [];
		foreach (self::PALETTE_VARS as $k => $var) {
			$out[] = '--ep-'.$var.':'.$set[$k];
			if ($k !== 'link' && $k !== 'free') {
				$out[] = '--ep-'.$k.'-fg:'.self::textOn($set[$k])['color'];
			}
		}
		foreach ($p['series'] as $i => $c) {
			$out[] = '--ep-s'.($i + 1).':'.$c;
		}
		return implode(';', $out);
	}

	/**
	 * The palette for the theme the user viewing the page chose, as paletteVars() writes it. Read
	 * from Zabbix when it is there; the light colours otherwise (a test, a script).
	 */
	public static function paletteStyle(?array $s = null): string {
		$theme = null;
		if (function_exists('getUserTheme') && class_exists('CWebUser', false) && isset(\CWebUser::$data)) {
			$theme = getUserTheme(\CWebUser::$data);
		}
		return self::paletteVars(self::palette($s), self::themeOf($theme));
	}

	/**
	 * The thresholds in force for one client, in the order this class exists to hold: the host's
	 * own macros first, then the widget's field, then the site setting. An override of 0 is a
	 * field left alone — 0 is not a threshold anyone wants, which is what lets an empty box in
	 * the widget form mean "follow the setting" without a second control beside it.
	 *
	 * @param array $macros  the master host's macros, as the controllers already collect them
	 */
	public static function usageFor(array $macros, $warn_override = self::INHERIT, $high_override = self::INHERIT, ?array $s = null): array {
		$g = self::usage($s);
		$pick = function ($macro, $override, float $fallback) use ($macros): float {
			if (is_numeric($macros[$macro] ?? null)) {
				return max(0.0, min(100.0, (float) $macros[$macro]));
			}
			return is_numeric($override) && (float) $override > 0 ? max(0.0, min(100.0, (float) $override)) : $fallback;
		};
		$high = $pick(self::HIGH_MACRO, $high_override, $g['high']);
		return ['warn' => min($pick(self::WARN_MACRO, $warn_override, $g['warn']), $high), 'high' => $high];
	}

	/** The site's thresholds, with no host and no widget in the question. */
	public static function usage(?array $s = null): array {
		return ($s ?? self::all())['usage'];
	}

	/** The site's bar style, as a position to hand to Bars::styleOf(). */
	public static function bars(?array $s = null): int {
		return ($s ?? self::all())['display']['bars'];
	}

	/** How many decimals a figure in these units is drawn with. */
	public static function decimals(string $units, ?array $s = null): int {
		$display = ($s ?? self::all())['display'];
		if ($units === '') {
			return $display['decimals_plain'];
		}
		return $units === '%' ? $display['decimals_percent'] : $display['decimals_bytes'];
	}

	/** What a size is divided by for the export. */
	public static function sizeDivisor(?array $s = null): int {
		return self::SIZE_UNITS[self::sizeLabel($s)];
	}

	/** What that division is called, for the export's column headers. */
	public static function sizeLabel(?array $s = null): string {
		return ($s ?? self::all())['export']['size_unit'];
	}

	/** The refresh a freshly placed widget starts at — see the note on DEFAULTS. */
	public static function refresh(string $report, ?array $s = null): int {
		return ($s ?? self::all())['refresh'][$report] ?? self::DEFAULTS['refresh'][$report] ?? 60;
	}

	/** The windows Forecast::fullIn() measures against. */
	public static function forecast(?array $s = null): array {
		return ($s ?? self::all())['forecast'];
	}

	/** The client types this site has, and which one a client with no type counts as. */
	public static function types(?array $s = null): array {
		return ($s ?? self::all())['types'];
	}

	public static function defaultType(?array $s = null): string {
		return self::types($s)['default'];
	}

	/** Only the tests need this: all() is memoised, and a test writes the file between reads. */
	public static function forget(): void {
		self::$loaded = null;
	}
}
