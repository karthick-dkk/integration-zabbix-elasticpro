<?php declare(strict_types = 0);

namespace Modules\EpCapacity\Lib;

use CDiv;
use CSpan;
use CTag;

/**
 * A figure with its percentage drawn beside it, for the widget tables.
 *
 * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.
 *
 * One definition for every widget: the percentage handed in here is the same $usage the cell's
 * colour was chosen from, so the bar and the colour can never tell different stories. The
 * thresholds are not repeated — the caller has already turned them into the cell's class, and
 * the bar simply takes its colour from that class in CSS.
 *
 * A null percentage draws no bar at all. That is the point of it being nullable: Elasticsearch
 * cannot always report a figure, and a bar of zero length reads as nought per cent, which is a
 * measurement nobody made.
 */
class Bars {

	/**
	 * The styles the widget's Display setting offers, in the order it offers them. 'none' draws
	 * the value and nothing else.
	 *
	 * The POSITION is what a widget stores. Zabbix validates a CWidgetFieldSelect as an integer
	 * — a field offering these as strings is refused outright with "an integer is expected", and
	 * the widget then renders nothing at all — so the setting holds the index and styleOf()
	 * turns it back into the name the drawing code uses. Keeping both in this one list is what
	 * stops the stored number and the name drifting apart; append to it, never reorder it.
	 */
	public const STYLES = ['under', 'cell', 'segments', 'none'];

	/** Bars under the value: the most legible down a column, every bar from the same left edge. */
	public const DEFAULT_STYLE = 'under';

	/** What a widget stores when its Display setting has never been touched. */
	public const DEFAULT_VALUE = 0;

	/**
	 * The style name for what a widget stored. An unknown value — a setting saved by a later
	 * release, or a hand-edited dashboard — falls back to the default rather than drawing
	 * nothing, because the figures matter more than the decoration.
	 */
	public static function styleOf($stored): string {
		if (is_string($stored) && in_array($stored, self::STYLES, true)) {
			return $stored;
		}
		return is_numeric($stored) && isset(self::STYLES[(int) $stored])
			? self::STYLES[(int) $stored]
			: self::DEFAULT_STYLE;
	}

	/** value => label for the widget form's Display select. */
	public static function options(): array {
		return [
			0 => _('Bar under the value'),
			1 => _('Bar in the cell'),
			2 => _('Segments'),
			3 => _('Numbers only')
		];
	}

	/**
	 * Not a style: what a widget's Display setting holds while it follows the site's own, set on
	 * ElasticPro -> Settings. A widget that has never been touched holds this, because Zabbix
	 * does not store a field whose value is its default — so following the site costs nothing and
	 * is the state a freshly placed widget is really in.
	 *
	 * A number well clear of the styles rather than another entry in STYLES, because that list is
	 * append-only by position and a reader that forgets to resolve this must still draw
	 * something: styleOf() does not recognise it and falls back to the default style, so the
	 * worst a missed resolution costs is the shipped bar instead of the chosen one.
	 */
	public const INHERIT = 100;

	public static function isInherit($stored): bool {
		return is_numeric($stored) && (int) $stored === self::INHERIT;
	}

	/** value => label for a widget form's Display select, with following the site offered first. */
	public static function optionsWithInherit(): array {
		return [self::INHERIT => _('From ElasticPro settings')] + self::options();
	}

	/** How many lit-or-unlit blocks the segmented style draws, unless a column asks for another number. */
	public const SEGMENTS = 12;

	/**
	 * $content as the cell should render it: untouched when there is no percentage to draw or
	 * the widget is set to numbers only, else wrapped with the bar in the chosen style.
	 *
	 * @param mixed       $content  the cell's text, or the nodes making it up
	 * @param float|null  $pct      0-100, or null when the figure was not reported
	 * @param string      $style    one of STYLES
	 * @param int         $segments how many blocks the segmented style draws (a column's own choice)
	 */
	public static function cell($content, ?float $pct, string $style, string $class = '', int $segments = self::SEGMENTS) {
		if ($pct === null || $style === 'none' || !in_array($style, self::STYLES, true)) {
			return $content;
		}
		$pct = max(0.0, min(100.0, $pct));
		$width = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');

		if ($style === 'segments') {
			$segments = max(2, min(50, $segments));
			$lit = (int) floor($pct / (100 / $segments) + 1e-9);
			$segs = [];
			for ($i = 0; $i < $segments; $i++) {
				$segs[] = (new CTag('i', true, ''))->addClass($i < $lit ? 'ep-seg-on' : null);
			}
			return (new CDiv([(new CSpan($segs))->addClass('ep-bar-segs'), (new CSpan($content))->addClass('ep-bar-val')]))
				->addClass('ep-bar-wrap ep-bar-segmented');
		}

		// The track is a child rather than a background on the cell, so a cell that also carries
		// ep-short or ep-unset keeps its own styling and the bar sits inside it.
		$track = (new CDiv((new CDiv(''))->addClass('ep-bar-fill')->setAttribute('style', 'width:'.$width.'%')))
			->addClass('ep-bar-track');

		return $style === 'cell'
			? (new CDiv([$track, (new CSpan($content))->addClass('ep-bar-val')]))->addClass('ep-bar-wrap ep-bar-incell')
			: (new CDiv([(new CDiv((new CSpan($content))->addClass('ep-bar-val')))->addClass('ep-bar-line'), $track]))
				->addClass('ep-bar-wrap ep-bar-under');
	}
}
