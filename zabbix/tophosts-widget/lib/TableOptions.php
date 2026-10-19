<?php declare(strict_types = 0);

namespace Modules\EpTophosts\Lib;

/**
 * The table a column widget draws, as its View tab sets it: density and row height, group headers,
 * a sticky header, a frozen first column, striped rows, a totals row, the default sort and top N,
 * which items the toolbar shows and where, and what "Needs attention" means.
 *
 * One reading of those settings for every widget on the column model; each passes the defaults a
 * widget saved before it had these settings was drawn with, so a saved widget looks as it did.
 *
 * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.
 */
class TableOptions {

	public const DENSITY_COMFORTABLE = 0;
	public const DENSITY_COMPACT = 1;

	public const TOTALS_SUM = 0;
	public const TOTALS_AVG = 1;

	public const SORT_ASC = 0;
	public const SORT_DESC = 1;

	public const TOOLBAR_ALWAYS = 0;
	public const TOOLBAR_HOVER = 1;
	public const TOOLBAR_HIDDEN = 2;

	public const ATTENTION_ALL = 0;
	public const ATTENTION_ONLY = 1;

	/** What "Needs attention" can mean: a cell over Warn or High, a server down, short, a server sending nothing, storage full within 30 days. */
	public const ATTENTION = ['warn', 'high', 'down', 'short', 'nodata', 'disk30'];

	/**
	 * @param array $f  the widget's field values (any may be missing: a widget saved earlier)
	 * @param array $d  the widget's defaults: toolbar (or toolbar_default), attention (or attention_default), and optionally density, group_headers,
	 *                  sticky_header, freeze_first, sort_by, toolbar_pos
	 */
	public static function read(array $f, array $d): array {
		$flag = fn(string $k, int $default) => (int) ($f[$k] ?? $default) === 1;

		return [
			'density' => (int) ($f['density'] ?? $d['density'] ?? self::DENSITY_COMPACT),
			'row_height' => ($f['row_height'] ?? '') === '' ? null : (int) $f['row_height'],
			'group_headers' => $flag('group_headers', (int) ($d['group_headers'] ?? 1)),
			'sticky_header' => $flag('sticky_header', (int) ($d['sticky_header'] ?? 1)),
			'freeze_first' => $flag('freeze_first', (int) ($d['freeze_first'] ?? 1)),
			'striped' => $flag('striped', 0),
			'totals' => $flag('totals', 0),
			'totals_avg' => (int) ($f['totals_fn'] ?? self::TOTALS_SUM) === self::TOTALS_AVG,
			'sort_by' => (string) ($f['sort_by'] ?? $d['sort_by'] ?? 'client'),
			'sort_desc' => (int) ($f['sort_dir'] ?? self::SORT_ASC) === self::SORT_DESC,
			'top_n' => ($f['top_n'] ?? '') === '' ? null : (int) $f['top_n'],
			'toolbar' => $f['toolbar_items'] ?? $d['toolbar_default'] ?? $d['toolbar'],
			'toolbar_pos' => (int) ($f['toolbar_pos'] ?? $d['toolbar_pos'] ?? self::TOOLBAR_HOVER),
			'attention' => $f['attention'] ?? $d['attention_default'] ?? $d['attention'],
			'attention_default' => (int) ($f['attention_default'] ?? self::ATTENTION_ALL) === self::ATTENTION_ONLY
		];
	}
}
