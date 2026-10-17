<?php declare(strict_types = 0);
/**
 * Import clients: get the workbook (clients.xlsx); upload it; see what it would do, per sheet;
 * confirm the changes to existing clients.
 *
 * Three cards in a grid (views/css/ep.clients.import.css, light and dark). The review spans the
 * width once a file was checked. The apply form underneath is unchanged — same action, token,
 * ticks — so ImportApply does not know the page was redrawn. The old clients.csv / servers.csv
 * are still accepted by the same file input.
 *
 * @var CView $this
 * @var array $data
 */

$url = fn(string $action, array $args = []) => array_reduce(array_keys($args), fn($u, $k) => $u->setArgument($k, $args[$k]),
	(new CUrl('zabbix.php'))->setArgument('action', $action))->getUrl();
$list = function(array $items, string $class = '') {
	$l = (new CList())->addClass($class);
	foreach ($items as $i) {
		$l->addItem($i);
	}
	return $l;
};
$card = function (string $title, array $body, $foot = null, string $class = '') {
	$c = (new CDiv([(new CDiv(new CTag('h4', true, $title)))->addClass('ep-card-head'), (new CDiv($body))->addClass('ep-card-body')]))->addClass('ep-card');
	if ($class !== '') {
		$c->addClass($class);
	}
	if ($foot !== null) {
		$c->addItem((new CDiv($foot))->addClass('ep-card-foot'));
	}
	return $c;
};
$icon = (new CObject('<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"></path></svg>'));

$page = (new CHtmlPage())->setTitle($data['file'] !== '' ? _s('Import %1$s', $data['file']) : _('Import clients'))
	->setControls((new CTag('nav', true, (new CList())->addItem(new CRedirectButton(_('Back to clients'), $url('ep.clients.list'))))));

$top = [];
if (!$data['store_ok']) {
	$top[] = (new CDiv(_s('Backups cannot be kept: %1$s is missing or not writable. Imports are refused until it is there.', $data['store_dir'])))->addClass('msg-bad')->addClass('ep-box');
}
if ($data['error'] !== null) {
	$top[] = (new CDiv($data['error']))->addClass('msg-bad')->addClass('ep-box');
}

/* ---------------- 1 · Get the workbook ---------------- */
$count = $data['client_count'];
$get = $card(_('1 · Get the workbook'), [
	(new CDiv([
		(new CLink([$icon, _('Download template — clients.xlsx')], $url('ep.clients.workbook')))->addClass('ep-dl')->addClass('ep-dl-main'),
		(new CSpan(_('A filled-in Sample sheet and a note on every column; dropdowns from this Zabbix (client types, roles, proxies).')))->addClass('ep-muted')
	]))->addClass('ep-dl-row'),
	(new CDiv([
		(new CLink([$icon, _('Export current clients — clients.xlsx')], $url('ep.clients.workbook', ['what' => 'export'])))->addClass('ep-dl'),
		(new CSpan($count === null ? _('Your clients and their servers, ready to edit and import back.')
			: _n('Your %1$s client and its servers, ready to edit and import back.', 'Your %1$s clients and their servers, ready to edit and import back.', $count)))->addClass('ep-muted')
	]))->addClass('ep-dl-row')
], [_('One workbook: Clients (with requested capacity, a collapsed column group), Servers, and a Sample sheet the import never reads. Hover a header in Excel for what it takes.')]);

/* ---------------- 2 · Upload it ---------------- */
$upload = (new CForm('post', $url('ep.clients.import')))
	->setAttribute('enctype', 'multipart/form-data')
	->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('ep.clients.import'))
	->addItem((new CDiv([
		new CLabel(_('clients.xlsx'), 'ep-file'),
		(new CTag('input', false))->setAttribute('type', 'file')->setAttribute('name', 'file[]')->setAttribute('multiple', 'multiple')
			->setAttribute('accept', '.xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv')->setId('ep-file')
			->setAttribute('aria-describedby', 'ep-file-help'),
		(new CSpan(_('.xlsx, up to 5 MB. The old clients.csv and servers.csv are still accepted: choose one or both.')))->addClass('ep-muted')->setId('ep-file-help')
	]))->addClass('ep-drop'))
	->addItem(new CSubmit('check', _('Check file')));
$send = $card(_('2 · Upload it'), [
	$upload,
	$list([
		_('Every row is checked first. One error and nothing is applied.'),
		_('A column you leave out means "no change"; an empty cell means "clear".'),
		_('Clients missing from the file are not removed. A client on the Servers sheet gets exactly the servers listed for it.'),
		_('New clients are added straight away; changes to existing clients wait for your tick — each after a backup.')
	], 'ep-rules')
], [_('CSV templates, for scripts: '), new CLink(_('clients.csv'), $url('ep.clients.csv.template')), ' · ',
	new CLink(_('servers.csv'), $url('ep.clients.csv.template', ['kind' => 'servers']))]);

/* ---------------- 3 · Review and apply ---------------- */
$plan = $data['plan'];
$review = [];
if ($plan === null) {
	$review[] = (new CSpan(_('Check a file to see, per sheet, what it would add and change — and any error, by sheet, row and column.')))->addClass('ep-muted');
}
else {
	$rows = $plan['rows'];
	$table = (new CTag('table', true))->addClass('ep-sheets');
	$table->addItem(new CTag('thead', true, new CTag('tr', true, [new CTag('th', true, _('Sheet')), new CTag('th', true, _('Rows')),
		new CTag('th', true, _('New')), new CTag('th', true, _('Changed')), new CTag('th', true, _('Errors'))])));
	$body = new CTag('tbody', true);
	foreach ($data['sheets'] as $s) {
		$new = new CTag('td', true, (string) $s['new']);
		$errors = new CTag('td', true, (string) $s['errors']);
		if ($s['new']) {
			$new->addClass('ep-n-new');
		}
		if ($s['errors']) {
			$errors->addClass('ep-n-err');
		}
		$body->addItem(new CTag('tr', true, [new CTag('td', true, $s['name']), new CTag('td', true, (string) $s['rows']), $new,
			new CTag('td', true, (string) $s['changed']), $errors]));
	}
	$table->addItem($body);
	if ($data['sheets']) {
		$review[] = (new CDiv($table))->addClass('ep-table-wrap');
	}
	if ($data['skipped']) {
		$review[] = (new CSpan(_s('Not read: %1$s.', implode(', ', $data['skipped']))))->addClass('ep-muted');
	}
	if ($plan['ignored']) {
		$review[] = (new CDiv(_s('Columns not recognised and ignored: %1$s.', implode(', ', $plan['ignored']))))->addClass('msg-warning')->addClass('ep-box');
	}
	if ($data['stage'] === 'errors') {
		$review[] = (new CDiv([new CTag('b', true, _n('Nothing was applied. Correct this and check the file again:', 'Nothing was applied. Correct these and check the file again:', count($plan['errors']))),
			$list($plan['errors'], 'ep-errors')]))->addClass('msg-bad')->addClass('ep-box')->setAttribute('role', 'alert');
	}
	else {
		$summary = [];
		if ($data['backup']) {
			$summary[] = new CTag('b', true, _('Backup taken. '));
		}
		$summary[] = $data['added'] ? _n('%1$s new client added: ', '%1$s new clients added: ', count($data['added'])).implode(', ', array_column($data['added'], 'name')).'.'
			: _('No new clients to add straight away.');
		$review[] = (new CDiv($summary))->addClass('msg-good')->addClass('ep-box');
		foreach ($data['added'] as $a) {
			$review[] = (new CDiv([new CTag('b', true, $a['name'].': '), implode(' ', $a['done']), ' ',
				$a['problems'] ? (new CSpan(_('Read back — differs: ').implode(' ', $a['problems'])))->addClass('ep-bad-text') : _('Read back — matches the file.')]))
				->addClass('ep-muted')->addClass('ep-line');
		}
	}

	$warnings = [];
	foreach ($rows as $r) {
		foreach ($r['warnings'] as $w) {
			$warnings[] = $r['name'].': '.$w;
		}
	}
	if ($warnings) {
		$review[] = (new CDiv([new CTag('b', true, _('Overlaps — check these before confirming:')), $list(array_values(array_unique($warnings)))]))
			->addClass('msg-warning')->addClass('ep-box');
	}

	if ($data['stage'] === 'done' && $data['pending']) {
		$confirm = (new CForm('post', $url('ep.clients.import.apply')))
			->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('ep.clients.import.apply'))
			->addVar('token', $data['token']);
		$t = (new CTableInfo())->setHeader([_('Apply'), _('Client'), _('What changes'), _('Hosts')]);
		foreach ($data['pending'] as $r) {
			$changes = [];
			foreach ($r['diff']['fields'] ?? [] as $d) {
				$changes[] = [$d['field'], ' ', (new CSpan($d['old'] === '' ? '—' : $d['old']))->addClass('ep-old'), ' → ', (new CSpan($d['new'] === '' ? '—' : $d['new']))->addClass('ep-new-text')];
			}
			if ($r['status'] === 'new') {
				$changes[] = [new CTag('b', true, _('New client')), ' — ', _('held back because of the overlap above.')];
			}
			$hosts = [];
			$deletes = false;
			foreach ($r['diff']['hosts'] ?? [] as $h) {
				switch ($h['change']) {
					case 'add': $hosts[] = (new CSpan('+ '.$h['ip'].' · '.$h['roles']))->addClass('ep-new-text'); break;
					case 'role': $hosts[] = (new CSpan('↻ '.($h['host'] ?? $h['ip']).' · '.(isset($h['was']) ? $h['was'].' → ' : '').$h['roles']))->addClass('ep-muted'); break;
					case 'detail': $hosts[] = (new CSpan('· '.($h['host'] ?? $h['ip']).' — '._('services, notes or attributes')))->addClass('ep-muted'); break;
					case 'delete': $deletes = true; $hosts[] = (new CSpan('− '.$h['host'].' ('.$h['ip'].') — '._('deleted with its history')))->addClass('ep-bad-text'); break;
					default: $hosts[] = (new CSpan('· '.$h['host'].' — '._('made by hand, left as it is')))->addClass('ep-muted');
				}
			}
			// A change that deletes a host, or a new client held back by an overlap, is never ticked for
			// you. Keyed by client: Zabbix's list-table script treats "apply[]" boxes as one and would
			// untick them all together; it finds each box by the id "apply_<key>".
			$box = (new CCheckBox('apply['.$r['name'].']', '1'))->setChecked(!$deletes && $r['status'] !== 'new')->setId('apply_'.$r['name']);
			$t->addRow([$box, [new CTag('b', true, $r['name']), ' ', (new CSpan($r['type']))->addClass('ep-pill')],
				$changes ? $list($changes, 'ep-plain') : '—', $hosts ? $list($hosts, 'ep-plain') : '—']);
		}
		$confirm->addItem((new CDiv($t))->addClass('ep-table-wrap'))->addItem((new CDiv([
			(new CSpan(_('Unticked clients stay as they are. A change that deletes a host, and a new client with an overlap, are never ticked for you. A backup of every client is taken before applying.')))->addClass('ep-muted ep-grow'),
			new CRedirectButton(_('Cancel changes'), $url('ep.clients.list')), ' ',
			new CSubmit('apply-selected', _('Apply ticked changes'))
		]))->addClass('ep-actions'));
		$review[] = (new CTag('h4', true, _('Changes to existing clients — confirm each')))->addClass('ep-h');
		$review[] = $confirm;
	}
	elseif ($data['stage'] === 'done') {
		$review[] = (new CDiv([_('No changes to existing clients. '), new CLink(_('Back to clients'), $url('ep.clients.list'))]))->addClass('ep-muted');
	}
}
$check = $card(_('3 · Review and apply'), $review, null, $plan !== null ? 'ep-wide' : '');

$page->addItem((new CDiv(array_merge($top, [(new CDiv([$get, $send, $check]))->addClass('ep-grid')])))->addClass('ep-imp'))->show();
?>
<style><?php readfile(__DIR__.'/css/ep.clients.import.css'); ?></style>
