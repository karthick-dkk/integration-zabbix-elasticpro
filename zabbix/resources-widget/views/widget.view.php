<?php declare(strict_types = 0);
/**
 * Client resources widget view: a table of clients — sections over their columns, a row per
 * client that expands into its servers — or a card per client with donuts of used, free and
 * short; both when viewers may switch.
 *
 * Everything drawn here was decided by the controller (WidgetView, Resources): the text, the
 * level, the colours (strict #RRGGBB, into custom properties only), the links (Zabbix pages only,
 * built by ColumnMath::url). This file turns those into tags; Zabbix escapes every string.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpResources\Includes\WidgetForm;
use Modules\EpResources\Lib\Bars;

$view = new CWidgetView($data);
$opt = $data['view'];
$palette = (string) ($data['palette'] ?? '');

$panel = fn(array $items) => (new CDiv($items))->addClass('ep-panel')->setAttribute('data-ep-panel', 'resources')
	->setAttribute('style', $palette !== '' ? $palette : null)
	->addClass($opt['toolbar_pos'] === WidgetForm::TOOLBAR_ALWAYS ? 'ep-pt-always' : null)
	->addClass($opt['density'] === WidgetForm::DENSITY_COMFORTABLE ? 'ep-comfortable' : null)
	->addClass(!$opt['sticky_header'] ? 'ep-nosticky' : null)
	->addClass(!$opt['freeze_first'] ? 'ep-nofreeze' : null)
	->addClass($opt['striped'] ? 'ep-striped' : null)
	->addClass($opt['row_height'] !== null ? 'ep-rowh' : null)
	->setAttribute('data-ep-view', $opt['view'] === WidgetForm::VIEW_PIE ? 'pie' : 'table');

if ($data['error'] !== null) {
	$view->addItem($panel([(new CDiv($data['error']))->addClass('ep-empty')]))->show();
	return;
}

$tools = array_flip($opt['toolbar']);
$attentionCount = count(array_filter($data['rows'], fn($r) => $r['attention']));
$bothViews = $opt['switch'];
$showTable = $bothViews || $opt['view'] === WidgetForm::VIEW_TABLE;
$showPie = $bothViews || $opt['view'] === WidgetForm::VIEW_PIE;

/* ---------------------------------------- toolbar ---------------------------------------- */

$toolbar = null;
if ($opt['toolbar_pos'] !== WidgetForm::TOOLBAR_HIDDEN) {
	$bar = [(new CSpan(_n('%1$s client', '%1$s clients', count($data['rows']))))->addClass('ep-res-count ep-pt-count')->setAttribute('data-ep-count', '')
		->setAttribute('data-template', _('%n clients'))];
	if (isset($tools['search'])) {
		$bar[] = (new CLabel([(new CSpan('⌕'))->setAttribute('aria-hidden', 'true'),
			(new CTag('input', false))->setAttribute('type', 'search')->setAttribute('data-ep-search', '')
				->setAttribute('placeholder', _('Client, host or IP'))->setAttribute('aria-label', _('Search clients, hosts or IPs'))
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
			->setAttribute('aria-pressed', $opt['density'] === WidgetForm::DENSITY_COMPACT ? 'true' : 'false');
	}
	if (isset($tools['view']) && $bothViews) {
		$bar[] = (new CDiv([
			(new CSimpleButton(_('Table')))->addClass('ep-pbtn')->setAttribute('data-ep-view-to', 'table')
				->setAttribute('aria-pressed', $opt['view'] === WidgetForm::VIEW_TABLE ? 'true' : 'false'),
			(new CSimpleButton(_('Pie chart')))->addClass('ep-pbtn')->setAttribute('data-ep-view-to', 'pie')
				->setAttribute('aria-pressed', $opt['view'] === WidgetForm::VIEW_PIE ? 'true' : 'false')
		]))->addClass('ep-seg')->setAttribute('role', 'group')->setAttribute('aria-label', _('View'));
	}
	if (isset($tools['expand']) && $showTable && $opt['server_rows'] !== WidgetForm::ROWS_NONE) {
		$bar[] = (new CSimpleButton($opt['server_rows'] === WidgetForm::ROWS_ALL ? _('Collapse all') : _('Expand all')))->addClass(ZBX_STYLE_BTN_ALT)
			->setAttribute('data-ep-expand-all', '')->setAttribute('data-open', $opt['server_rows'] === WidgetForm::ROWS_ALL ? '1' : '0')
			->addClass('ep-table-only');
	}
	$menu = [];
	if (isset($tools['columns'])) {
		$menu[] = (new CSimpleButton(_('Columns').'…'))->setAttribute('data-ep-columns', '');
	}
	if (isset($tools['export'])) {
		$menu[] = (new CSimpleButton(_('Export CSV')))->setAttribute('data-ep-export', 'csv');
		$menu[] = (new CSimpleButton(_('Export servers CSV')))->setAttribute('data-ep-export', 'csv-servers');
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
	$toolbar = (new CDiv($bar))->addClass('ep-res-toolbar ep-pt');
}

if (!$data['rows']) {
	$view->addItem($panel([$toolbar,
			(new CDiv(_('No client master hosts yet. Add clients in ElasticPro → Clients — they appear here, and export is enabled.')))->addClass('ep-empty')
		]))
		->setVar('export', $data['export'])->setVar('ep_meta', $data['meta'])->show();
	return;
}

/* -------------------------------------- one cell -------------------------------------- */

/** The content of a cell as Resources::cell described it: its text and sub-text, its bar, badge, indicators or sparkline, its link. */
$content = function (array $c) {
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
};
$td = function (array $c, string $tag = 'td') use ($content) {
	$el = (new CTag($tag, true, $content($c)))
		->addClass($c['class'] !== '' ? $c['class'] : null)
		->addClass(($c['layout']['class'] ?? '') !== '' ? $c['layout']['class'] : null);
	$style = trim(implode(';', array_filter([$c['style'] ?? '', $c['layout']['style'] ?? ''])), ';');
	if ($style !== '') {
		$el->setAttribute('style', $style);
	}
	if ($c['hint'] !== '') {
		$el->setAttribute('title', $c['hint']);
	}
	// What a header click sorts by: the figure itself, not its text ("+3 %", "18.4k", "2 months",
	// a reason). Empty: unknown, last either way. Text columns sort by their text.
	if (array_key_exists('sort', $c) && !is_string($c['sort'])) {
		$el->setAttribute('data-sort', $c['sort'] === null ? '' : (string) round((float) $c['sort'], 6));
	}
	return $el;
};

$items = [$toolbar];

/* ---------------------------------------- table ---------------------------------------- */

if ($showTable) {
	$columns = $data['columns'];
	// Sections: a run of columns with one group is one section.
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
	$expandable = $opt['server_rows'] !== WidgetForm::ROWS_NONE;

	$thead = new CTag('thead', true);
	if ($opt['group_headers'] && array_filter(array_column($columns, 'group'), fn($g) => $g !== '')) {
		$group_row = (new CTag('tr', true))->addItem($expandable ? (new CTag('th', true, ''))->addClass('ep-res-toggle') : null);
		foreach ($groups as $g) {
			$group_row->addItem((new CTag('th', true, $g['name']))->setAttribute('colspan', $g['span'])
				->addClass('ep-res-group')->addClass('ep-res-start'));
		}
		$thead->addItem($group_row);
	}
	$label_row = (new CTag('tr', true))->addItem($expandable ? (new CTag('th', true, ''))->addClass('ep-res-toggle') : null);
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
		$id = $row['id'];
		$open = $opt['server_rows'] === WidgetForm::ROWS_ALL;
		$tr = (new CTag('tr', true))->setAttribute('data-type', $row['type'])->setAttribute('data-row', $id)
			->setAttribute('data-attention', $row['attention'] ? '1' : '0');
		if ($expandable) {
			$tr->addItem((new CTag('td', true, $row['servers']
				? (new CTag('button', true, $open ? '▾' : '▸'))->setAttribute('type', 'button')->addClass('ep-expand')->setAttribute('data-ep-expand', $id)
					->setAttribute('aria-expanded', $open ? 'true' : 'false')->setAttribute('aria-label', _s('Servers of %1$s', $row['client']))
				: ''))->addClass('ep-res-toggle'));
		}
		foreach ($row['cells'] as $i => $cell) {
			$el = $td($cell)->addClass($i === 0 ? 'ep-res-first' : null)->addClass(in_array($i, $starts, true) ? 'ep-res-start' : null);
			if ($i === 0 && $cell['url'] === null) {
				// The client's name, as the report has always set it: bold.
				$el = (new CTag('td', true, new CTag('b', true, $content($cell))))->addClass('ep-res-first')
					->setAttribute('data-sort', array_key_exists('sort', $cell) && !is_string($cell['sort'])
						? ($cell['sort'] === null ? '' : (string) round((float) $cell['sort'], 6)) : null)
					->addClass($cell['class'] !== '' ? $cell['class'] : null)->addClass(in_array(0, $starts, true) ? 'ep-res-start' : null);
				if ($cell['hint'] !== '') {
					$el->setAttribute('title', $cell['hint']);
				}
			}
			$tr->addItem($el);
		}
		$body->addItem($tr);

		if ($expandable && $row['servers']) {
			// One column per mount the client's servers have (/, /data …), in the order first met.
			$mounts = [];
			foreach ($row['servers'] as $s) {
				foreach ($s['mounts'] as $m) {
					if (!in_array($m, $mounts, true)) {
						$mounts[] = $m;
					}
				}
			}
			$head = new CTag('tr', true);
			foreach ($data['server_head'] as $h) {
				if ($h['disks']) {
					foreach ($mounts as $m) {
						$head->addItem((new CTag('th', true, _s('Disk %1$s', $m)))->setAttribute('data-sc', 'disks'));
					}
					continue;
				}
				$th = (new CTag('th', true, $h['name']))->setAttribute('data-sc', $h['sc'])
					->addClass($h['layout']['class'] !== '' ? $h['layout']['class'] : null);
				if ($h['layout']['style'] !== '') {
					$th->setAttribute('style', $h['layout']['style']);
				}
				$head->addItem($th);
			}
			$sb = new CTag('tbody', true);
			foreach ($row['servers'] as $s) {
				$str = new CTag('tr', true);
				foreach ($s['cells'] as $sc) {
					switch ($sc['kind']) {
						case 'disks':
							foreach ($mounts as $m) {
								$d = $sc['disks'][$m] ?? null;
								$str->addItem((new CTag('td', true, $d === null ? '' : (new CSpan([
									$d['mount'] !== null ? [(new CSpan($d['mount']))->addClass('ep-mount'), ' '] : null,
									$d['used_on'] && $d['bar'] !== null ? (new CSpan((new CTag('i', true, ''))->setAttribute('style', 'width:'.($d['bar'] ?? 0).'%')))->addClass('ep-disk-bar') : null,
									$d['size'],
									$d['used_on'] && $d['used'] !== null ? [$d['size'] !== null ? ' · ' : '', new CTag('b', true, $d['used'].' %')] : null
								]))->addClass('ep-disk-line')->addClass($d['class'] !== '' ? $d['class'] : null)))->setAttribute('data-sc', 'disks'));
							}
							break;
						case 'role':
							// Each role its own chip; a server with several reads "ES Data · Parser · Engine".
							$roleCell = [];
							foreach ($sc['roles'] as $r) {
								$roleCell[] = (new CSpan($r))->addClass('ep-role-chip');
								$roleCell[] = ' ';
							}
							foreach ($sc['services'] as $svc) {
								$roleCell[] = (new CSpan($svc))->addClass('ep-svc');
								$roleCell[] = ' ';
							}
							if ($sc['single']) {
								$roleCell[] = (new CSpan(_('single node')))->addClass('ep-mark');
							}
							elseif ($sc['shared']) {
								$roleCell[] = (new CSpan(_('shared')))->addClass('ep-mark');
							}
							$str->addItem((new CTag('td', true, $roleCell))->setAttribute('data-sc', 'role'));
							break;
						case 'host':
							$str->addItem((new CTag('td', true, $sc['url'] !== null ? new CLink($sc['text'], $sc['url']) : $sc['text']))->setAttribute('data-sc', 'host'));
							break;
						case 'status':
							$str->addItem((new CTag('td', true, new CSpan($sc['status'] === 'up' ? _('up') : ($sc['status'] === 'down' ? _('down') : _('no data')))))
								->setAttribute('data-sc', 'status')->addClass('ep-st-'.$sc['status']));
							break;
						default:
							$str->addItem($td($sc['cell'] + ['layout' => $sc['layout']])->setAttribute('data-sc', $sc['sc']));
					}
				}
				$sb->addItem($str);
			}
			$srv = (new CTag('table', true))->addClass('ep-servers')->addItem((new CTag('thead', true))->addItem($head))->addItem($sb);
			// Across the whole row, the expand column included: no empty cell pushing the servers right.
			$sub = (new CTag('tr', true))->addClass('ep-sub')->setAttribute('data-parent', $id)
				->addItem((new CTag('td', true, (new CDiv($srv))->addClass('ep-servers-wrap')))->setAttribute('colspan', count($columns) + 1));
			if (!$open) {
				$sub->setAttribute('hidden', 'hidden');
			}
			$body->addItem($sub);
		}
	}

	$table = (new CTag('table', true))->addClass(ZBX_STYLE_LIST_TABLE)->addClass('ep-res-table')->addItem($thead)->addItem($body);
	if ($data['totals'] !== null) {
		$tf = (new CTag('tr', true))->addClass('ep-totals')->setAttribute('data-ep-totals', '')
			->setAttribute('title', _('Over every client the widget lists; hidden while a search, Type or Needs attention filter is on.'))->addItem($expandable ? (new CTag('td', true, ''))->addClass('ep-res-toggle') : null);
		foreach ($data['totals'] as $i => $t) {
			$tf->addItem((new CTag('td', true, $t))->addClass($i === 0 ? 'ep-res-first' : null)->addClass(in_array($i, $starts, true) ? 'ep-res-start' : null));
		}
		$table->addItem((new CTag('tfoot', true))->addItem($tf));
	}
	$scroll = (new CDiv($table))->addClass('ep-res-scroll ep-pscroll ep-view-table');
	if ($opt['row_height'] !== null) {
		$scroll->setAttribute('style', '--ep-row-h:'.(int) $opt['row_height'].'px');
	}
	if ($bothViews && $opt['view'] !== WidgetForm::VIEW_TABLE) {
		$scroll->setAttribute('hidden', 'hidden');
	}
	$items[] = $scroll;
}

/* -------------------------------------- pie chart -------------------------------------- */

if ($showPie) {
	$sizes = [WidgetForm::SIZE_SMALL => 'ep-pie-s', WidgetForm::SIZE_MEDIUM => 'ep-pie-m', WidgetForm::SIZE_LARGE => 'ep-pie-l'];
	$ring = fn($cls, $dash, $offset, $extra = null) => (new CTag('circle', true, ''))->addClass($cls)->setAttribute('cx', '21')->setAttribute('cy', '21')
		->setAttribute('r', '15.9155')->setAttribute('fill', 'none')->setAttribute('stroke-width', '5')
		->setAttribute('stroke-dasharray', $dash)->setAttribute('stroke-dashoffset', $offset)->setAttribute('style', $extra);
	$hatch = $opt['hatch'];
	// The hatching for Short: one pattern for the whole widget, named so two widgets never share one.
	$pattern = (new CTag('pattern', true, [
		(new CTag('rect', true, ''))->addClass('ep-hatch-bg')->setAttribute('width', '2')->setAttribute('height', '2'),
		(new CTag('rect', true, ''))->addClass('ep-hatch-fg')->setAttribute('width', '1')->setAttribute('height', '2')
	]))->setAttribute('id', $hatch)->setAttribute('patternUnits', 'userSpaceOnUse')->setAttribute('width', '2')->setAttribute('height', '2')
		->setAttribute('patternTransform', 'rotate(45)');
	$cards = [(new CTag('svg', true, new CTag('defs', true, $pattern)))->addClass('ep-defs')->setAttribute('width', '0')->setAttribute('height', '0')
		->setAttribute('aria-hidden', 'true')];
	foreach ($data['rows'] as $row) {
		$head = [
			(new CLink($row['client'], $row['dashboard']))->addClass('ep-pie-name'),
			(new CSpan($row['type']))->addClass('ep-pill')->addClass($row['type'] === 'DI' ? 'ep-di' : 'ep-op'),
			$row['badge'] !== '' ? (new CSpan($row['badge']))->addClass('ep-mark') : null,
			(new CSpan(''))->addClass('ep-grow')
		];
		if ($opt['chips']) {
			foreach ($row['chips'] as $chip) {
				$head[] = (new CSpan($chip['text']))->addClass('ep-chip ep-chip-'.$chip['level']);
			}
		}
		$n = count($row['servers']);
		$head[] = $bothViews && $opt['server_rows'] !== WidgetForm::ROWS_NONE && $n > 0
			? (new CSimpleButton(_s('Servers (%1$s)', $n)))->addClass('ep-pbtn ep-goto')->setAttribute('data-ep-goto', $row['id'])
			: (new CSpan(_s('Servers (%1$s)', $n)))->addClass('ep-muted');
		$donuts = [];
		foreach ($row['donuts'] as $d) {
			$circles = [$ring('ep-d-track', '100 0', '0')];
			if ($d['known']) {
				$u = round($d['segments']['used'], 2);
				$fr = round($d['segments']['free'], 2);
				$sh = round($d['segments']['short'], 2);
				$circles[] = $ring('ep-d-used ep-d-'.$d['level'], $u.' '.round(100 - $u, 2), '25');
				if ($sh > 0 && $opt['short'] !== WidgetForm::SHORT_HIDDEN) {
					$circles[] = $ring('ep-d-short'.($opt['short'] === WidgetForm::SHORT_SOLID ? ' ep-d-solid' : ''), $sh.' '.round(100 - $sh, 2),
						(string) round(25 - $u - $fr, 2), $opt['short'] === WidgetForm::SHORT_HATCHED ? 'stroke:url(#'.$hatch.')' : null);
				}
			}
			$circles[] = (new CTag('text', true, $d['centre']))->addClass('ep-d-pct ep-d-t-'.$d['level'])->setAttribute('x', '21')->setAttribute('y', '21')
				->setAttribute('text-anchor', 'middle');
			$circles[] = (new CTag('text', true, $d['sub']))->addClass('ep-d-sub')->setAttribute('x', '21')->setAttribute('y', '27')->setAttribute('text-anchor', 'middle');
			$legend = new CTag('tbody', true);
			foreach ($d['legend'] as $l) {
				$legend->addItem((new CTag('tr', true))
					->addItem((new CTag('th', true, [(new CSpan(''))->addClass('ep-sw ep-sw-'.$l['key'])->addClass($l['key'] === 'used' ? 'ep-d-'.$d['level'] : null),
						$l['label']]))->setAttribute('scope', 'row'))
					->addItem(new CTag('td', true, $l['value']))
					->addItem((new CTag('td', true, $l['pct']))->addClass('ep-lg-pct')));
			}
			$donuts[] = (new CDiv([
				(new CTag('svg', true, $circles))->addClass('ep-donut')->setAttribute('viewBox', '0 0 42 42')->setAttribute('role', 'img')
					->setAttribute('aria-label', $d['aria']),
				(new CDiv($d['label']))->addClass('ep-d-title'),
				(new CTag('table', true, $legend))->addClass('ep-legend')
			]))->addClass('ep-d')->setAttribute('data-measure', $d['id']);
		}
		$cards[] = (new CTag('article', true, [
			(new CDiv($head))->addClass('ep-pie-head'),
			$donuts ? (new CDiv($donuts))->addClass('ep-pie-grid') : (new CDiv(_('No donuts chosen — pick them in the widget\'s View settings.')))->addClass('ep-muted')
		]))->addClass('ep-pie-card')->setAttribute('data-type', $row['type'])->setAttribute('data-card', $row['id'])
			->setAttribute('data-attention', $row['attention'] ? '1' : '0')->setAttribute('data-search', $row['client'].' '.$row['search']);
	}
	$pie = (new CDiv($cards))->addClass('ep-pie ep-pscroll ep-view-pie')->addClass($sizes[$opt['size']] ?? 'ep-pie-m')
		->setAttribute('style', $opt['per_row'] > 0 ? '--ep-per-row:'.(int) $opt['per_row'] : null);
	if ($bothViews && $opt['view'] !== WidgetForm::VIEW_PIE) {
		$pie->setAttribute('hidden', 'hidden');
	}
	$items[] = $pie;
}

if ($data['dropped']) {
	// A column a saved widget had that no longer passes the column rule: left out, and named.
	$items[] = (new CDiv(_n('%1$s column is not drawn', '%1$s columns are not drawn', count($data['dropped']))))->addClass('ep-dropped')
		->setAttribute('title', implode("\n", $data['dropped']));
}

$view
	->addItem($panel($items))
	->setVar('export', $data['export'])
	->setVar('ep_meta', $data['meta'])
	->show();
