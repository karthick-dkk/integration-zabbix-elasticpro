<?php declare(strict_types = 0);

namespace EpShared\Includes;

use CDiv, CLabel, CLink, CSimpleButton, CSpan, CTag;
use EpShared\{Bars, TableOptions};

/**
 * How a widget on the column model turns what its controller decided into tags: a cell's content
 * (its text and sub-text, its bar, badge, indicators or sparkline, its link), the cell, the table
 * with its sections over its columns, rows, totals and (for Client resources) a row that expands,
 * the toolbar, and the panel around them.
 *
 * Everything drawn was decided by the controller (ColumnReport, ColumnCell): the text, the level,
 * the colours (strict #RRGGBB, into custom properties only) and the links (Zabbix pages only, built
 * by ColumnMath::url). This only turns those into tags, and Zabbix escapes every string.
 *
 * Shared: ../../../sync-assets.mjs copies this into the widgets that use it, under their namespace.
 */
class ColumnTable {

	/** The content of a cell as ColumnCell::cell described it. */
	public static function content(array $c) {
		$text = $c['sub'] !== '' ? [$c['text'], ' ', (new CSpan($c['sub']))->addClass('ep-res-sub')] : $c['text'];
		if ($c['badge']) {
			$text = (new CSpan($text))->addClass($c['badge_class'] ?? 'ep-badge');
		}
		if ($c['bar'] !== null) {
			if ($c['bar']['style'] === 'gauge') {
				$text = (new CDiv([
					(new CSpan(''))->addClass('ep-gauge')->setAttribute('style', '--p:'.round($c['bar']['share'], 1)),
					(new CSpan($text))->addClass('ep-bar-val')
				]))->addClass('ep-bar-wrap ep-bar-gauge');
			}
			else {
				$text = Bars::cell($text, (float) $c['bar']['share'], $c['bar']['style'], $c['class'], (int) $c['bar']['segments']);
			}
		}
		elseif ($c['ind'] !== null) {
			$dots = [];
			for ($i = 0; $i < $c['ind']['of']; $i++) {
				$dots[] = (new CTag('i', true, ''))->addClass($i < $c['ind']['lit'] ? 'on' : null);
			}
			$text = (new CDiv([(new CSpan($dots))->addClass('ep-ind'), (new CSpan($text))->addClass('ep-bar-val')]))->addClass('ep-bar-wrap ep-bar-ind');
		}
		elseif ($c['spark'] !== null) {
			$pts = $c['spark'];
			$lo = min($pts);
			$hi = max($pts);
			$n = count($pts);
			$xy = [];
			foreach ($pts as $i => $v) {
				$xy[] = round($i * 78 / max(1, $n - 1) + 1, 1).','.round(18 - ($hi > $lo ? ($v - $lo) / ($hi - $lo) * 16 : 8), 1);
			}
			$text = (new CDiv([
				(new CTag('svg', true, (new CTag('polyline', true, ''))->setAttribute('points', implode(' ', $xy))->setAttribute('fill', 'none')))
					->addClass('ep-spark')->setAttribute('viewBox', '0 0 80 20')->setAttribute('width', '80')->setAttribute('height', '20')
					->setAttribute('aria-hidden', 'true'),
				(new CSpan($text))->addClass('ep-bar-val')
			]))->addClass('ep-bar-wrap ep-bar-spark');
		}
		if (($c['url'] ?? null) !== null) {
			$text = (new CLink($text, $c['url']))->addClass('ep-cell-link');
		}

		return $text;
	}

	/** What a header click sorts a cell by: its figure, not its text; empty is unknown; a text column sorts by its text. */
	public static function sortAttribute(array $c): ?string {
		if (!array_key_exists('sort', $c) || is_string($c['sort'])) {
			return null;
		}

		return $c['sort'] === null ? '' : (string) round((float) $c['sort'], 6);
	}

	public static function td(array $c, string $tag = 'td'): CTag {
		$el = (new CTag($tag, true, self::content($c)))
			->addClass($c['class'] !== '' ? $c['class'] : null)
			->addClass(($c['layout']['class'] ?? '') !== '' ? $c['layout']['class'] : null);
		$style = trim(implode(';', array_filter([$c['style'] ?? '', $c['layout']['style'] ?? ''])), ';');
		if ($style !== '') {
			$el->setAttribute('style', $style);
		}
		if ($c['hint'] !== '') {
			$el->setAttribute('title', $c['hint']);
		}
		$el->setAttribute('data-sort', self::sortAttribute($c));

		return $el;
	}

	/** The panel around a widget's contents: the palette, and the look the View tab chose. */
	public static function panel(array $items, string $kind, array $opt, string $palette, string $view = 'table'): CDiv {
		return (new CDiv($items))->addClass('ep-panel')->setAttribute('data-ep-panel', $kind)
			->setAttribute('style', $palette !== '' ? $palette : null)
			->addClass($opt['toolbar_pos'] === TableOptions::TOOLBAR_ALWAYS ? 'ep-pt-always' : null)
			->addClass($opt['density'] === TableOptions::DENSITY_COMFORTABLE ? 'ep-comfortable' : null)
			->addClass(!$opt['sticky_header'] ? 'ep-nosticky' : null)
			->addClass(!$opt['freeze_first'] ? 'ep-nofreeze' : null)
			->addClass($opt['striped'] ? 'ep-striped' : null)
			->addClass($opt['row_height'] !== null ? 'ep-rowh' : null)
			->setAttribute('data-ep-view', $view);
	}

	/**
	 * The toolbar, with the items the widget's View tab shows.
	 *
	 * @param array $o  count (text, template: "%n clients"), search (placeholder, label), pie (null, or
	 *                  ['both' => viewers may switch, 'pie' => it starts as the Pie chart]), expand (null, or
	 *                  ['open' => all start open]), servers_csv (an export of the servers too)
	 */
	public static function toolbar(array $data, array $opt, array $o): ?CDiv {
		if ($opt['toolbar_pos'] === TableOptions::TOOLBAR_HIDDEN) {
			return null;
		}
		$tools = array_flip($opt['toolbar']);
		$attentionCount = count(array_filter($data['rows'], fn($r) => $r['attention'] ?? false));
		$bar = [(new CSpan($o['count']['text']))->addClass('ep-res-count ep-pt-count')->setAttribute('data-ep-count', '')
			->setAttribute('data-template', $o['count']['template'])];
		if (isset($tools['search'])) {
			$bar[] = (new CLabel([(new CSpan('⌕'))->setAttribute('aria-hidden', 'true'),
				(new CTag('input', false))->setAttribute('type', 'search')->setAttribute('data-ep-search', '')
					->setAttribute('placeholder', $o['search']['placeholder'])->setAttribute('aria-label', $o['search']['label'])
			]))->addClass('ep-pt-search');
		}
		if (isset($tools['type'])) {
			$bar[] = new CLabel(_('Type'));
			// The types the site has, from ElasticPro -> Settings.
			$bar[] = (new CTag('select', true, array_merge([(new CTag('option', true, _('All')))->setAttribute('value', '')],
				array_map(fn($t) => (new CTag('option', true, $t))->setAttribute('value', $t), $data['meta']['types'])
			)))->setAttribute('data-ep-type-filter', '')->setAttribute('aria-label', _('Client type'));
		}
		if (isset($tools['attention'])) {
			$bar[] = (new CSimpleButton([_('Needs attention').' ', (new CSpan((string) $attentionCount))->addClass('ep-att-n')]))
				->addClass('ep-pbtn ep-att')->setAttribute('data-ep-attention', '')->setAttribute('aria-pressed', $opt['attention_default'] ? 'true' : 'false');
		}
		if (isset($tools['compact'])) {
			$bar[] = (new CSimpleButton(_('Compact')))->addClass('ep-pbtn')->setAttribute('data-ep-compact', '')
				->setAttribute('aria-pressed', $opt['density'] === TableOptions::DENSITY_COMPACT ? 'true' : 'false');
		}
		// "Viewers may switch on the widget" is the setting: the switch is on the toolbar whenever it is on
		// (2.1.1 at first also wanted a separate toolbar item ticked, off by default, so ticking the setting did nothing).
		if ($o['pie'] !== null && $o['pie']['both']) {
			$bar[] = (new CDiv([
				(new CSimpleButton(_('Table')))->addClass('ep-pbtn')->setAttribute('data-ep-view-to', 'table')
					->setAttribute('aria-pressed', !$o['pie']['pie'] ? 'true' : 'false'),
				(new CSimpleButton(_('Pie chart')))->addClass('ep-pbtn')->setAttribute('data-ep-view-to', 'pie')
					->setAttribute('aria-pressed', $o['pie']['pie'] ? 'true' : 'false')
			]))->addClass('ep-seg')->setAttribute('role', 'group')->setAttribute('aria-label', _('View'));
		}
		if (isset($tools['expand']) && $o['expand'] !== null) {
			$bar[] = (new CSimpleButton($o['expand']['open'] ? _('Collapse all') : _('Expand all')))->addClass(ZBX_STYLE_BTN_ALT)
				->setAttribute('data-ep-expand-all', '')->setAttribute('data-open', $o['expand']['open'] ? '1' : '0')
				->addClass('ep-table-only');
		}
		$menu = [];
		if (isset($tools['columns'])) {
			$menu[] = (new CSimpleButton(_('Columns').'…'))->setAttribute('data-ep-columns', '');
		}
		if (isset($tools['export'])) {
			$menu[] = (new CSimpleButton(_('Export CSV')))->setAttribute('data-ep-export', 'csv');
			if (!empty($o['servers_csv'])) {
				$menu[] = (new CSimpleButton(_('Export servers CSV')))->setAttribute('data-ep-export', 'csv-servers');
			}
			$menu[] = (new CSimpleButton(_('Export Excel')))->setAttribute('data-ep-export', 'xlsx');
		}
		if ($menu) {
			// The report's Columns and its exports, in the panel menu.
			$bar[] = (new CDiv([
				(new CSimpleButton('⋮'))->addClass('ep-pbtn')->setAttribute('data-ep-menu', '')->setAttribute('aria-haspopup', 'true')
					->setAttribute('aria-expanded', 'false')->setAttribute('title', _('Columns and export')),
				(new CDiv($menu))->addClass('ep-menu-pop')->setAttribute('hidden', 'hidden')
			]))->addClass('ep-menu');
		}

		return (new CDiv($bar))->addClass('ep-res-toolbar ep-pt');
	}

	/**
	 * The table: sections (a run of columns with one group) over their columns, a row a client with
	 * its cells, the totals row. A widget whose rows open (Client resources) says so with $rows:
	 * toggle(row) is the content of the cell that opens it, sub(row, columns) the row that opens.
	 *
	 * @param array      $data  columns (uid, group, name, layout), rows (id, type, attention, cells, row_class?, first_title?), totals
	 * @param array|null $rows  ['toggle' => callable, 'sub' => callable, 'open' => bool], or null for a table whose rows do not open
	 */
	public static function table(array $data, array $opt, ?array $rows = null): CTag {
		$columns = $data['columns'];
		$groups = [];
		foreach ($columns as $i => $c) {
			if ($groups && $groups[count($groups) - 1]['name'] === $c['group']) {
				$groups[count($groups) - 1]['span']++;
			}
			else {
				$groups[] = ['name' => $c['group'], 'span' => 1, 'first' => $i];
			}
		}
		$starts = array_column($groups, 'first');
		$expandable = $rows !== null;
		$toggleHead = fn() => $expandable ? (new CTag('th', true, ''))->addClass('ep-res-toggle') : null;

		$thead = new CTag('thead', true);
		if ($opt['group_headers'] && array_filter(array_column($columns, 'group'), fn($g) => $g !== '')) {
			$group_row = (new CTag('tr', true))->addItem($toggleHead());
			foreach ($groups as $g) {
				$group_row->addItem((new CTag('th', true, $g['name']))->setAttribute('colspan', $g['span'])
					->addClass('ep-res-group')->addClass('ep-res-start'));
			}
			$thead->addItem($group_row);
		}
		$label_row = (new CTag('tr', true))->addItem($toggleHead());
		foreach ($columns as $i => $c) {
			$th = (new CTag('th', true, $c['name']))->addClass($i === 0 ? 'ep-res-first' : null)
				->addClass(in_array($i, $starts, true) ? 'ep-res-start' : null)
				->addClass($c['layout']['class'] !== '' ? $c['layout']['class'] : null)
				->setAttribute('data-ep-col', $c['uid']);
			if ($c['layout']['style'] !== '') {
				$th->setAttribute('style', $c['layout']['style']);
			}
			$label_row->addItem($th);
		}
		$thead->addItem($label_row);

		$body = new CTag('tbody', true);
		foreach ($data['rows'] as $row) {
			$tr = (new CTag('tr', true))->setAttribute('data-type', $row['type'])->setAttribute('data-row', $row['id'])
				->setAttribute('data-attention', $row['attention'] ? '1' : '0')->addClass($row['row_class'] ?? null);
			if ($expandable) {
				$tr->addItem((new CTag('td', true, ($rows['toggle'])($row)))->addClass('ep-res-toggle'));
			}
			foreach ($row['cells'] as $i => $cell) {
				$el = self::td($cell)->addClass($i === 0 ? 'ep-res-first' : null)->addClass(in_array($i, $starts, true) ? 'ep-res-start' : null);
				if ($i === 0 && $cell['url'] === null) {
					// The client's name, as the report has always set it: bold.
					$el = (new CTag('td', true, new CTag('b', true, self::content($cell))))->addClass('ep-res-first')
						->setAttribute('data-sort', self::sortAttribute($cell))
						->addClass($cell['class'] !== '' ? $cell['class'] : null)->addClass(in_array(0, $starts, true) ? 'ep-res-start' : null);
					$title = trim($cell['hint'].' '.($row['first_title'] ?? ''));
					if ($title !== '') {
						$el->setAttribute('title', $title);
					}
				}
				elseif ($i === 0 && ($row['first_title'] ?? '') !== '') {
					$el->setAttribute('title', trim($cell['hint'].' '.$row['first_title']));
				}
				$tr->addItem($el);
			}
			$body->addItem($tr);
			if ($expandable && ($sub = ($rows['sub'])($row, count($columns) + 1)) !== null) {
				$body->addItem($sub);
			}
		}

		$table = (new CTag('table', true))->addClass(ZBX_STYLE_LIST_TABLE)->addClass('ep-res-table')->addItem($thead)->addItem($body);
		if ($data['totals'] !== null) {
			$tf = (new CTag('tr', true))->addClass('ep-totals')->setAttribute('data-ep-totals', '')
				->setAttribute('title', _('Over every row the widget lists; hidden while a search, Type or Needs attention filter is on.'))->addItem($expandable ? (new CTag('td', true, ''))->addClass('ep-res-toggle') : null);
			foreach ($data['totals'] as $i => $t) {
				$tf->addItem((new CTag('td', true, $t))->addClass($i === 0 ? 'ep-res-first' : null)->addClass(in_array($i, $starts, true) ? 'ep-res-start' : null));
			}
			$table->addItem((new CTag('tfoot', true))->addItem($tf));
		}

		return $table;
	}

	/** The scrolling box around the table, with the row height the View tab chose. */
	public static function scroll(CTag $table, array $opt, bool $hidden = false): CDiv {
		$scroll = (new CDiv($table))->addClass('ep-res-scroll ep-pscroll ep-view-table');
		if ($opt['row_height'] !== null) {
			$scroll->setAttribute('style', '--ep-row-h:'.(int) $opt['row_height'].'px');
		}
		if ($hidden) {
			$scroll->setAttribute('hidden', 'hidden');
		}

		return $scroll;
	}

	/** What a saved widget had that no longer passes the column rule: said under the table. */
	public static function dropped(array $dropped): ?CDiv {
		if (!$dropped) {
			return null;
		}

		return (new CDiv(_n('%1$s column is not drawn', '%1$s columns are not drawn', count($dropped))))->addClass('ep-dropped')
			->setAttribute('title', implode("\n", $dropped));
	}
}
