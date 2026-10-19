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

use Modules\EpResources\Includes\{ColumnTable, WidgetForm};

$view = new CWidgetView($data);
$opt = $data['view'];
$palette = (string) ($data['palette'] ?? '');

$panel = fn(array $items) => ColumnTable::panel($items, 'resources', $opt, $palette, $opt['view'] === WidgetForm::VIEW_PIE ? 'pie' : 'table');

if ($data['error'] !== null) {
	$view->addItem($panel([(new CDiv($data['error']))->addClass('ep-empty')]))->show();
	return;
}

$attentionCount = count(array_filter($data['rows'], fn($r) => $r['attention']));
$bothViews = $opt['switch'];
$showTable = $bothViews || $opt['view'] === WidgetForm::VIEW_TABLE;
$showPie = $bothViews || $opt['view'] === WidgetForm::VIEW_PIE;

/* ---------------------------------------- toolbar ---------------------------------------- */

$toolbar = ColumnTable::toolbar($data, $opt, [
	'count' => ['text' => _n('%1$s client', '%1$s clients', count($data['rows'])), 'template' => _('%n clients')],
	'search' => ['placeholder' => _('Client, host or IP'), 'label' => _('Search clients, hosts or IPs')],
	'pie' => ['both' => $bothViews, 'pie' => $opt['view'] === WidgetForm::VIEW_PIE],
	'expand' => $showTable && $opt['server_rows'] !== WidgetForm::ROWS_NONE ? ['open' => $opt['server_rows'] === WidgetForm::ROWS_ALL] : null,
	'servers_csv' => true
]);

if (!$data['rows']) {
	$view->addItem($panel([$toolbar,
			(new CDiv(_('No client master hosts yet. Add clients in ElasticPro → Clients — they appear here, and export is enabled.')))->addClass('ep-empty')
		]))
		->setVar('export', $data['export'])->setVar('ep_meta', $data['meta'])->show();
	return;
}

$items = [$toolbar];

/* ---------------------------------------- table ---------------------------------------- */

if ($showTable) {
	$expandable = $opt['server_rows'] !== WidgetForm::ROWS_NONE;
	$open = $opt['server_rows'] === WidgetForm::ROWS_ALL;

	/** A client's servers, a row of their own that opens under it: one column per mount the servers have. */
	$serversOf = function (array $row, int $span) use ($data, $open) {
		$id = $row['id'];
		if (!$row['servers']) {
			return null;
		}
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
						$str->addItem(ColumnTable::td($sc['cell'] + ['layout' => $sc['layout']])->setAttribute('data-sc', $sc['sc']));
				}
			}
			$sb->addItem($str);
		}
		$srv = (new CTag('table', true))->addClass('ep-servers')->addItem((new CTag('thead', true))->addItem($head))->addItem($sb);
		// Across the whole row, the expand column included: no empty cell pushing the servers right.
		$sub = (new CTag('tr', true))->addClass('ep-sub')->setAttribute('data-parent', $id)
			->addItem((new CTag('td', true, (new CDiv($srv))->addClass('ep-servers-wrap')))->setAttribute('colspan', $span));
		if (!$open) {
			$sub->setAttribute('hidden', 'hidden');
		}
		return $sub;
	};
	$rows = $expandable ? [
		'toggle' => fn(array $row) => $row['servers']
			? (new CTag('button', true, $open ? '▾' : '▸'))->setAttribute('type', 'button')->addClass('ep-expand')->setAttribute('data-ep-expand', $row['id'])
				->setAttribute('aria-expanded', $open ? 'true' : 'false')->setAttribute('aria-label', _s('Servers of %1$s', $row['client']))
			: '',
		'sub' => $serversOf
	] : null;
	$items[] = ColumnTable::scroll(ColumnTable::table($data, $opt, $rows), $opt, $bothViews && $opt['view'] !== WidgetForm::VIEW_TABLE);
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

if (($dropped = ColumnTable::dropped($data['dropped'])) !== null) {
	$items[] = $dropped;
}

$view
	->addItem($panel($items))
	->setVar('export', $data['export'])
	->setVar('ep_meta', $data['meta'])
	->show();
