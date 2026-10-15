<?php declare(strict_types = 0);
/**
 * Client resources widget view: sections over their columns, a row per client that expands into
 * its servers.
 *
 * @var CView $this
 * @var array $data
 */

$view = new CWidgetView($data);

if ($data['error'] !== null) {
	$view->addItem((new CDiv($data['error']))->addClass('ep-empty'));
	$view->show();
	return;
}

$toolbar = (new CDiv([
	(new CSpan(_n('%1$s client', '%1$s clients', count($data['rows']))))->addClass('ep-res-count ep-pt-count')->setAttribute('data-ep-count', '')
		->setAttribute('data-template', _('%n clients')),
	(new CLabel([(new CSpan('⌕'))->setAttribute('aria-hidden', 'true'),
		(new CTag('input', false))->setAttribute('type', 'search')->setAttribute('data-ep-search', '')
			->setAttribute('placeholder', _('Client, host or IP'))->setAttribute('aria-label', _('Search clients, hosts or IPs'))
	]))->addClass('ep-pt-search'),
	new CLabel(_('Type')),
	// The types the site has, from ElasticPro -> Settings. Hard-coded here before, which is how
	// CI came to be missing from a filter whose list has always had it.
	(new CTag('select', true, array_merge([(new CTag('option', true, _('All')))->setAttribute('value', '')],
		array_map(fn($t) => (new CTag('option', true, $t))->setAttribute('value', $t), $data['meta']['types'])
	)))->setAttribute('data-ep-type-filter', '')->setAttribute('aria-label', _('Client type')),
	(new CDiv([
		(new CSimpleButton(_('Table')))->addClass('ep-pbtn')->setAttribute('data-ep-view', 'table')->setAttribute('aria-pressed', 'true'),
		(new CSimpleButton(_('Overview')))->addClass('ep-pbtn')->setAttribute('data-ep-view', 'overview')->setAttribute('aria-pressed', 'false')
	]))->addClass('ep-seg')->setAttribute('role', 'group')->setAttribute('aria-label', _('View')),
	(new CSimpleButton(_('Expand all')))->addClass(ZBX_STYLE_BTN_ALT)->setAttribute('data-ep-expand-all', ''),
	(new CSimpleButton('⚙ '._('Table settings')))->addClass('ep-pbtn')->setAttribute('data-ep-settings', '')->setAttribute('aria-expanded', 'false'),
	// The report's Columns and its exports, in the panel menu.
	(new CDiv([
		(new CSimpleButton('⋮'))->addClass('ep-pbtn')->setAttribute('data-ep-menu', '')->setAttribute('aria-haspopup', 'true')
			->setAttribute('aria-expanded', 'false')->setAttribute('title', _('Columns and export')),
		(new CDiv([
			(new CSimpleButton(_('Columns').'…'))->setAttribute('data-ep-columns', ''),
			(new CSimpleButton(_('Export CSV')))->setAttribute('data-ep-export', 'csv'),
			(new CSimpleButton(_('Export servers CSV')))->setAttribute('data-ep-export', 'csv-servers'),
			(new CSimpleButton(_('Export Excel')))->setAttribute('data-ep-export', 'xlsx')
		]))->addClass('ep-menu-pop')->setAttribute('hidden', 'hidden')
	]))->addClass('ep-menu')
]))->addClass('ep-res-toolbar ep-pt');

if (!$data['rows']) {
	$view->addItem((new CDiv([$toolbar,
			(new CDiv(_('No client master hosts yet. Add clients in ElasticPro → Clients — they appear here, and export is enabled.')))->addClass('ep-empty')
		]))->addClass('ep-panel')->setAttribute('data-ep-panel', 'resources'))
		->setVar('export', $data['export'])->setVar('ep_meta', $data['meta'])->show();
	return;
}

// Sections: a run of columns with one group is one section.
$groups = [];
foreach ($data['columns'] as $i => $c) {
	if ($groups && $groups[count($groups) - 1]['name'] === $c['group']) {
		$groups[count($groups) - 1]['span']++;
	}
	else {
		$groups[] = ['name' => $c['group'], 'span' => 1, 'first' => $i];
	}
}
$starts = array_column($groups, 'first');

$group_row = (new CTag('tr', true))->addItem((new CTag('th', true, ''))->addClass('ep-res-toggle'));
foreach ($groups as $g) {
	$group_row->addItem((new CTag('th', true, $g['name'] === 'Client' ? '' : $g['name']))->setAttribute('colspan', $g['span'])
		->addClass('ep-res-group')->addClass('ep-res-start'));
}
$label_row = (new CTag('tr', true))->addItem((new CTag('th', true, ''))->addClass('ep-res-toggle'));
foreach ($data['columns'] as $i => $c) {
	$label_row->addItem((new CTag('th', true, $c['label']))->addClass($i === 0 ? 'ep-res-first' : null)
		->addClass(in_array($i, $starts, true) ? 'ep-res-start' : null));
}

$pct = fn($v) => $v === null ? '—' : round($v, 1).' %';
$body = new CTag('tbody', true);
foreach ($data['rows'] as $n => $row) {
	$id = 'c'.$n;
	$tr = (new CTag('tr', true))->setAttribute('data-type', $row['type'])->setAttribute('data-row', $id);
	$tr->addItem((new CTag('td', true, $row['servers']
		? (new CTag('button', true, '▸'))->setAttribute('type', 'button')->addClass('ep-expand')->setAttribute('data-ep-expand', $id)
			->setAttribute('aria-expanded', 'false')->setAttribute('aria-label', _s('Servers of %1$s', $row['client']))
		: ''))->addClass('ep-res-toggle'));
	foreach ($row['cells'] as $i => $cell) {
		$content = $cell['sub'] !== '' ? [$cell['text'], ' ', (new CSpan($cell['sub']))->addClass('ep-res-sub')] : $cell['text'];
		$content = \Modules\EpResources\Lib\Bars::cell($content, $cell['bar'] ?? null, $data['bars'] ?? 'under', $cell['class']);
		$td = (new CTag('td', true, $i === 0 ? new CTag('b', true, $content) : $content))
			->addClass($cell['class'] !== '' ? $cell['class'] : null)
			->addClass(($cell['bar'] ?? null) !== null && ($data['bars'] ?? 'under') !== 'none' ? 'ep-barcell' : null)
			->addClass($i === 0 ? 'ep-res-first' : null)
			->addClass(in_array($i, $starts, true) ? 'ep-res-start' : null);
		if ($cell['hint'] !== '') {
			$td->setAttribute('title', $cell['hint']);
		}
		$tr->addItem($td);
	}
	$body->addItem($tr);

	if ($row['servers']) {
		// One column per mount the client's servers have (/, /data …), in the order first met.
		$mounts = [];
		foreach ($row['servers'] as $s) {
			foreach ($s['disks'] ?? [] as $d) {
				if (!in_array($d['mount'], $mounts, true)) {
					$mounts[] = $d['mount'];
				}
			}
		}
		$head = new CTag('tr', true);
		// data-sc names each column, so a viewer can hide one in Table settings.
		foreach (['role' => _('Role'), 'host' => _('Host'), 'ip' => _('IP'), 'status' => _('Status'), 'cores' => _('Cores'), 'cpu' => _('CPU used'),
				'mem' => _('Memory'), 'memu' => _('Memory used')] as $sc => $h) {
			$head->addItem((new CTag('th', true, $h))->setAttribute('data-sc', $sc));
		}
		foreach ($mounts as $m) {
			$head->addItem((new CTag('th', true, _s('Disk %1$s', $m)))->setAttribute('data-sc', 'disks'));
		}

		$sb = new CTag('tbody', true);
		foreach ($row['servers'] as $s) {
			// Each role its own chip; a server with several reads "ES Data · Parser · Engine".
			$roleCell = [];
			foreach (array_filter(array_map('trim', explode('+', $s['role']))) as $r) {
				$roleCell[] = (new CSpan($r))->addClass('ep-role-chip');
				$roleCell[] = ' ';
			}
			foreach ($s['services'] ?? [] as $svc) {
				$roleCell[] = (new CSpan($svc))->addClass('ep-svc');
				$roleCell[] = ' ';
			}
			if (!empty($s['single'])) {
				$roleCell[] = (new CSpan(_('single node')))->addClass('ep-mark');
			}
			elseif (!empty($s['shared'])) {
				$roleCell[] = (new CSpan(_('shared')))->addClass('ep-mark');
			}
			// Each mount in its own column, coloured by its own figure. A server without that disk: empty.
			$byMount = array_column($s['disks'] ?? [], null, 'mount');
			$diskCells = [];
			foreach ($mounts as $m) {
				$d = $byMount[$m] ?? null;
				$diskCells[] = (new CTag('td', true, $d === null ? '' : (new CSpan([
					(new CSpan((new CTag('i', true, ''))->setAttribute('style', 'width:'.($d['used'] === null ? 0 : max(0, min(100, round($d['used'], 1)))).'%')))->addClass('ep-disk-bar'),
					$d['total'] === null ? '—' : convertUnits(['value' => $d['total'], 'units' => 'B']),
					$d['used'] === null ? '' : [' · ', new CTag('b', true, round($d['used']).' %')]
				]))->addClass('ep-disk-line')->addClass(($d['class'] ?? '') !== '' ? $d['class'] : null)))->setAttribute('data-sc', 'disks');
			}
			$bar = fn($v, $cls) => \Modules\EpResources\Lib\Bars::cell($pct($v), $v, $data['bars'] ?? 'under', $cls);
			$scell = fn($sc, $content, $cls = '') => (new CTag('td', true, $content))->setAttribute('data-sc', $sc)
				->addClass($cls !== '' ? $cls : null);
			$barcell = fn($sc, $v, $cls) => $scell($sc, $bar($v, $cls), $cls)
				->addClass($v !== null && ($data['bars'] ?? 'under') !== 'none' ? 'ep-barcell' : null);
			$sb->addItem((new CTag('tr', true))
				->addItem($scell('role', $roleCell))
				->addItem($scell('host', new CLink($s['host'],
					(new CUrl('zabbix.php'))->setArgument('action', 'latest.view')->setArgument('hostids', [$s['hostid']])->getUrl())))
				->addItem($scell('ip', $s['ip'] !== '' ? $s['ip'] : '—'))
				->addItem($scell('status', new CSpan($s['status'] === 'up' ? _('up') : ($s['status'] === 'down' ? _('down') : _('no data'))), 'ep-st-'.$s['status']))
				->addItem($scell('cores', $s['cpu_cores'] === null ? '—' : (string) (int) $s['cpu_cores']))
				->addItem($barcell('cpu', $s['cpu_used'], $s['classes']['cpu']))
				->addItem($scell('mem', $s['mem_total'] === null ? '—' : convertUnits(['value' => $s['mem_total'], 'units' => 'B'])))
				->addItem($barcell('memu', $s['mem_used'], $s['classes']['mem']))
				->addItem($diskCells));
		}
		$srv = (new CTag('table', true))->addClass('ep-servers')->addItem((new CTag('thead', true))->addItem($head))->addItem($sb);
		// Across the whole row, the expand column included: no empty cell pushing the servers right.
		$body->addItem((new CTag('tr', true))->addClass('ep-sub')->setAttribute('data-parent', $id)->setAttribute('hidden', 'hidden')
			->addItem((new CTag('td', true, (new CDiv($srv))->addClass('ep-servers-wrap')))->setAttribute('colspan', count($data['columns']) + 1)));
	}
}

$table = (new CTag('table', true))
	->addClass(ZBX_STYLE_LIST_TABLE)
	->addClass('ep-res-table')
	->addItem((new CTag('thead', true))->addItem($group_row)->addItem($label_row))
	->addItem($body);

$view
	->addItem((new CDiv([$toolbar, (new CDiv($table))->addClass('ep-res-scroll ep-pscroll'),
		// The overview panels, drawn by ep-overview.js from ep_overview when Overview is chosen.
		(new CDiv(''))->addClass('ep-ov ep-pscroll')->setAttribute('hidden', 'hidden')]))
		->addClass('ep-panel')->setAttribute('data-ep-panel', 'resources'))
	->setVar('ep_overview', $data['overview'])
	->setVar('export', $data['export'])
	->setVar('ep_meta', $data['meta'])
	->show();
