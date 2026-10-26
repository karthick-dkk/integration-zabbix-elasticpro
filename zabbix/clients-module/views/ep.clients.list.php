<?php declare(strict_types = 0);
/**
 * Cluster Management (board List-after.dc.html, approved 2026-10-23): search, filters All /
 * Missing / Not adopted / Old templates, a Status choice, and a Columns popover (hide, reorder,
 * rename — one shared setting, ListColumns); the table: Client with its cluster host, one column
 * per role with its server count (hover: IP · server name; hidden when no listed client has one),
 * template generation, password (own / default / not set), last seen, and the Missing chip that
 * opens the client's Missing panel. No Problems and no Disk full columns. The summary line —
 * clients, with missing pieces, hosts not adopted → Discover — is a footer under the table.
 * Tick clients for bulk Maintenance, Disable or Enable. Follows Zabbix's light or dark theme.
 *
 * @var CView $this
 * @var array $data
 */

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$url = fn(string $action, array $args = []) => array_reduce(array_keys($args), fn($u, $k) => $u->setArgument($k, $args[$k]),
	(new CUrl('zabbix.php'))->setArgument('action', $action))->getUrl();
$csrf = fn(string $action) => '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.$e(CCsrfTokenHelper::get($action)).'">';
$post = fn(string $action, string $label, array $vars, string $confirm = '', string $class = 'ep-link') => \Modules\EpClients\Lib\ClientActions::post($action, $label, $vars, $confirm, $class);

$now = time();
$tab = function(array $c): array {
	if ($c['status'] === 'decommissioned') {
		return ['decom'];
	}
	if ($c['status'] === 'disabled') {
		return ['disabled'];
	}
	return $c['maintenance'] !== null ? ['live', 'maint'] : ['live'];
};
$counts = ['live' => 0, 'maint' => 0, 'disabled' => 0, 'decom' => 0];
foreach ($data['clients'] as $c) {
	foreach ($tab($c) as $t) {
		$counts[$t]++;
	}
}
$sevNames = [5 => ['D', 'disaster'], 4 => ['H', 'high'], 3 => ['A', 'average'], 2 => ['W', 'warning'], 1 => ['I', 'info']];

$ro = !($data['can_write'] ?? true);
// An ElasticPro operator adds and edits clients; Discover, Import / export, Roles and the master
// template stay an admin's, so their buttons are not offered to someone they would only deny.
$admin = !$ro && (bool) ($data['can_remove'] ?? true);
$html = [];
$html[] = '<div class="ep-l'.($ro ? ' ep-ro' : '').'">';
if ($ro) {
	// Who may edit, and why this person may not — EpRole::noteLines(), the one wording.
	$lines = \Modules\EpClients\Lib\EpRole::noteLines($data['write_note'] ?? null);
	$lines[0] .= ' '._('You see the clients whose hosts you may read.');
	foreach ($lines as $line) {
		$html[] = '<div class="ep-note">'.$e($line).'</div>';
	}
}
if (!$data['store_ok']) {
	$html[] = '<div class="ep-note ep-bad">'.$e(_s('Backups cannot be kept: %1$s is missing or not writable, so every change is refused until it is there. See the Clients module README.', $data['store_dir'])).'</div>';
}
if ($data['template'] !== 'current') {
	$html[] = '<div class="ep-note ep-warn">'.$e($data['template'] === 'missing' ? _('The master template is not in Zabbix yet. Every client\'s master host is linked to it: its capacity figures, alerts and dashboard come from there. Write it once, before adding clients; nothing is written until you press the button.')
		: _('The templates were written for other roles or an older version. Write them again so every client follows the current ones.')).' '
		.($admin ? $post('ep.clients.template.install', _('Write master template'), [], '', 'ep-btn-alt') : '').'</div>';
}

$missingN = count(array_filter($data['clients'], fn($c) => !empty($c['missing'])));
$oldN = count(array_filter($data['clients'], fn($c) => !empty($c['beside'])));
$cols = $data['columns'] ?? [];
$html[] = '<div class="ep-tools">'
	.'<label class="ep-search"><span class="ep-sub">'.$e(_('Search')).'</span><input type="search" id="ep-q" placeholder="'.$e(_('Find a client, host name or IP')).'"></label>'
	.'<div class="ep-seg" role="group" aria-label="'.$e(_('Filter')).'">'
	.'<button type="button" data-filter="all" aria-pressed="true">'.$e(_('All')).'<span class="ep-c">'.count($data['clients']).'</span></button>'
	.'<button type="button" data-filter="missing" aria-pressed="false">'.$e(_('Missing')).'<span class="ep-c">'.$missingN.'</span></button>'
	.'<a href="'.$e($url('ep.clients.discover')).'" title="'.$e(_('Hosts not yet adopted are on Discover, not here.')).'">'.$e(_('Not adopted'))
		.'<span class="ep-c">'.($data['not_adopted'] === null ? '?' : (int) $data['not_adopted']).'</span></a>'
	.'<button type="button" data-filter="old" aria-pressed="false">'.$e(_('Old templates')).'<span class="ep-c">'.$oldN.'</span></button>'
	.'</div>'
	.'<label class="ep-status"><span class="ep-sub">'.$e(_('Status')).'</span><select id="ep-status">'
	.'<option value="live">'.$e(_s('In service (%1$s)', $counts['live'])).'</option>'
	.'<option value="maint">'.$e(_s('Maintenance (%1$s)', $counts['maint'])).'</option>'
	.'<option value="disabled">'.$e(_s('Disabled (%1$s)', $counts['disabled'])).'</option>'
	.'<option value="decom">'.$e(_s('Decommissioned (%1$s)', $counts['decom'])).'</option></select></label>'
	.'<button type="button" class="ep-btn-alt ep-cols-btn" aria-expanded="false" aria-controls="ep-cols"><svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="2" y="2.5" width="3" height="11"></rect><rect x="6.5" y="2.5" width="3" height="11"></rect><rect x="11" y="2.5" width="3" height="11"></rect></svg> '
	.$e(_('Columns')).'</button>'
	.'</div>';

// The Columns popover: drag to reorder, untick to hide, type to rename; one shared setting (ListColumns).
if (!$ro) {
	$items = '';
	$items .= '<li class="ep-fixed"><span class="ep-grip" aria-hidden="true"></span><input type="checkbox" checked disabled aria-label="'.$e(_('Show Client (always)')).'"><input type="text" value="'.$e(_('Client')).'" disabled aria-label="'.$e(_('Client')).'"></li>';
	foreach ($cols as $col) {
		$isRole = strpos($col['key'], 'family:') === 0 || strpos($col['key'], 'role:') === 0;
		$empty = $isRole && \Modules\EpClients\Lib\ListColumns::count($col['key'], $data['clients']) === 0;
		$on = \Modules\EpClients\Lib\ListColumns::shows($col, $data['clients']);
		$items .= '<li draggable="true" data-key="'.$e($col['key']).'" data-auto="'.($col['show'] === null ? '1' : '0').'"'.($on ? '' : ' class="ep-off"').'>'
			.'<span class="ep-grip" aria-hidden="true">⋮⋮</span>'
			.'<input type="checkbox"'.($on ? ' checked' : '').' aria-label="'.$e(_s('Show %1$s', $col['label'])).'">'
			.'<input type="text" value="'.$e($col['label']).'" maxlength="48" aria-label="'.$e(_s('Rename %1$s', $col['label'])).'">'
			.($empty && !$on ? '<span class="ep-why">'.$e(_s('hidden: no listed client has a %1$s server', $col['label'])).'</span>' : '')
			.'</li>';
	}
	$html[] = '<form method="post" action="'.$e($url('ep.clients.columns.save')).'" class="ep-pop" id="ep-cols" role="dialog" aria-label="'.$e(_('Columns')).'" hidden>'.$csrf('ep.clients.columns.save')
		.'<div class="ep-ph"><span>'.$e(_('Columns')).'</span><button type="button" class="ep-x" data-cols-close aria-label="'.$e(_('Close')).'">×</button></div>'
		.'<div class="ep-sub">'.$e(_('Drag to reorder, untick to hide, type to rename. One shared setting for this page.')).'</div>'
		.'<ul>'.$items.'</ul><input type="hidden" name="columns" value="">'
		.'<div class="ep-pf"><span class="ep-sub">'.$e(_('Ticking an empty role shows it anyway.')).'</span>'
		.'<button type="submit" name="reset" value="1" class="ep-link">'.$e(_('Reset')).'</button> <button type="submit" name="save" value="1">'.$e(_('Save')).'</button></div></form>';
}

// Bulk: the ticked clients, posted with the chosen action.
$html[] = $ro ? '' : '<form method="post" action="'.$e($url('ep.clients.status')).'" id="ep-bulk" class="ep-bulk" hidden>'.$csrf('ep.clients.status')
	.'<span id="ep-bulk-n"></span>'
	.'<button type="button" class="ep-btn-alt" data-open-maint="">'.$e(_('Maintenance…')).'</button>'
	.'<button type="submit" name="op" value="disable" class="ep-btn-alt" onclick="return confirm('.$e(json_encode(_('Disable the ticked clients? Every host of each is set to Not monitored; a backup is taken first.'))).');">'.$e(_('Disable')).'</button>'
	.'<button type="submit" name="op" value="enable" class="ep-btn-alt">'.$e(_('Enable')).'</button>'
	.'<div id="ep-bulk-ticks"></div></form>';

// The columns as set (ListColumns): the Client column first and always; a role column left on
// "auto" only when a listed client has a server in it.
$shown = array_values(array_filter($cols, fn($col) => $col['key'] !== 'cluster_host' && \Modules\EpClients\Lib\ListColumns::shows($col, $data['clients'])));
$underClient = (bool) array_filter($cols, fn($col) => $col['key'] === 'cluster_host' && \Modules\EpClients\Lib\ListColumns::shows($col, $data['clients']));
$isCount = fn(string $k) => strpos($k, 'family:') === 0 || strpos($k, 'role:') === 0;
$head = '<th class="ep-tick">'.($ro ? '' : '<input type="checkbox" id="ep-all" aria-label="'.$e(_('Tick all shown')).'">').'</th><th>'.$e(_('Client')).'</th>';
foreach ($shown as $col) {
	$head .= '<th'.($isCount($col['key']) ? ' class="ep-r"' : '').' data-col="'.$e($col['key']).'">'.$e($col['label']).'</th>';
}
$head .= '<th class="ep-act">'.$e(_('Edit')).'</th>';
$html[] = '<div class="ep-table-wrap"><table class="ep-t"><thead><tr>'.$head.'</tr></thead><tbody>';
if (!$data['clients']) {
	$html[] = '<tr><td colspan="'.(count($shown) + 3).'" class="ep-empty">'.$e(_('No clients yet. Discover the clusters Zabbix already has, add one, or import a workbook.')).'</td></tr>';
}
$ago = function (?int $t) use ($now, $e): string {
	if ($t === null) {
		return '<span class="ep-sub">'.$e(_('unknown: no cluster host')).'</span>';
	}
	if ($t <= 0) {
		return '<span class="ep-sub">'.$e(_('never')).'</span>';
	}
	$d = max(0, $now - $t);
	$text = $d < 3600 ? _n('%1$s min ago', '%1$s min ago', max(1, (int) round($d / 60))) : ($d < 86400 ? _n('%1$s hour ago', '%1$s hours ago', (int) round($d / 3600))
		: _n('%1$s day ago', '%1$s days ago', (int) round($d / 86400)));
	return '<span class="'.($d > 900 ? 'ep-warn-t' : 'ep-sub').'">'.$e($text).'</span>';
};
foreach ($data['clients'] as $c) {
	$name = $c['name'];
	$status = '';
	if ($c['master_missing']) {
		$status = '<span class="ep-pill ep-s-bad">'.$e(_('Master host removed')).'</span>';
	}
	elseif ($c['status'] === 'decommissioned') {
		$status = '<span class="ep-pill ep-s-decom">'.$e(_('Decommissioned')).'</span>';
	}
	elseif ($c['status'] === 'disabled') {
		$status = '<span class="ep-pill ep-s-off">'.$e(_('Disabled')).'</span>';
	}
	elseif ($c['maintenance'] !== null) {
		$status = '<span class="ep-pill ep-s-maint" title="'.$e($c['maintenance']['reason']).'">'.$e(_s('Maintenance until %1$s', date('d M H:i', $c['maintenance']['till']))).'</span>';
	}
	$clientCell = '<div class="ep-name">'.($c['masterid'] !== null ? '<a href="'.$e($url('ep.clients.edit', ['client' => $name])).'">'.$e($name).'</a>' : $e($name)).'</div>'
		.($underClient ? ($c['cluster_host'] !== null ? '<div class="ep-mono">'.$e($c['cluster_host']).'</div>' : '<div class="ep-sub">'.$e(_('no cluster host')).'</div>') : '')
		.$status;
	$cells = '';
	foreach ($shown as $col) {
		$k = $col['key'];
		if ($isCount($k)) {
			$cell = \Modules\EpClients\Lib\ListColumns::cell($k, $c);
			if ($cell['n'] > 0) {
				$v = '<span class="ep-cnt" title="'.$e(implode("\n", $cell['hosts'])).'">'.$cell['n'].'</span>'.($cell['shared'] ? ' <span class="ep-sub">'.$e(_('shared')).'</span>' : '');
			}
			elseif ($cell['requested'] > 0) {
				$v = '<span class="ep-miss" title="'.$e(_('The plan asks for it; no server holds it.')).'">'.$e(_s('0 of %1$s', $cell['requested'])).'</span>';
			}
			else {
				$v = '<span class="ep-sub">—</span>';
			}
			$cells .= '<td class="ep-r">'.$v.'</td>';
		}
		elseif ($k === 'templates') {
			$cells .= '<td>'.($c['cluster_host'] === null ? '<span class="ep-sub">—</span>' : '<span class="ep-sub">'.$e(_('current')).'</span>')
				.($c['beside'] ? ' <span class="ep-old" title="'.$e(implode(', ', $c['beside'])).'">'.$e(_n('+ %1$s older beside', '+ %1$s older beside', count($c['beside']))).'</span>' : '').'</td>';
		}
		elseif ($k === 'password') {
			$p = $c['password'];
			$cells .= '<td>'.($p === 'not set' ? '<span class="ep-bad-t">'.$e(_('not set')).'</span>'
				: ($p === 'hidden' ? '<span class="ep-warn-t" title="'.$e(_('A linked template defines the password macro and hides the default.')).'">'.$e(_('hidden by template')).'</span>'
				: '<span class="ep-sub">'.$e($p === 'own' ? _('own') : _('default')).'</span>')).'</td>';
		}
		elseif ($k === 'last_seen') {
			$cells .= '<td>'.$ago($c['last_seen']).'</td>';
		}
		elseif ($k === 'missing') {
			$n = count($c['missing']);
			$tip = implode("\n", array_map(fn($m) => $m['text'], $c['missing']));
			$cells .= '<td>'.($n && $c['masterid'] !== null ? '<a class="ep-bad-chip" title="'.$e($tip).'" href="'.$e($url('ep.clients.edit', ['client' => $name])).'#ep-missing">'.$e(_s('Missing %1$s', $n)).'</a>'
				: ($n ? '<span class="ep-bad-chip" title="'.$e($tip).'">'.$e(_s('Missing %1$s', $n)).'</span>' : '')).'</td>';
		}
	}
	$acts = $c['master_missing'] ? implode('', \Modules\EpClients\Lib\ClientActions::forMissing($name))
		: '<a class="ep-edit" href="'.$e($url('ep.clients.edit', ['client' => $name])).'" aria-label="'.$e($ro ? _s('View %1$s', $name) : _s('Edit %1$s', $name)).'">'.$e($ro ? _('View') : _('Edit')).'</a>';
	$html[] = '<tr data-tabs="'.$e(implode(' ', $tab($c))).'" data-search="'.$e($c['search']).'" data-missing="'.($c['missing'] ? '1' : '0').'" data-old="'.($c['beside'] ? '1' : '0').'"'
		.(in_array('live', $tab($c), true) ? '' : ' hidden').'>'
		.'<td class="ep-tick">'.($c['master_missing'] || $ro ? '' : '<input type="checkbox" class="ep-sel" value="'.$e($name).'" aria-label="'.$e(_s('Select %1$s', $name)).'">').'</td>'
		.'<td>'.$clientCell.'</td>'.$cells.'<td class="ep-act"><div class="ep-acts">'.$acts.'</div></td></tr>';
}
$html[] = '</tbody></table></div>';

// The footer (moved under the table, 2026-10-23): the summary line, what is shown, the key.
$hiddenRoles = array_values(array_map(fn($col) => $col['label'], array_filter($cols, fn($col) => $isCount($col['key']) && $col['show'] === null
	&& !\Modules\EpClients\Lib\ListColumns::shows($col, $data['clients']))));
$html[] = '<div class="ep-foot">'
	.'<div class="ep-sumline"><b>'.$e(_n('%1$s client', '%1$s clients', count($data['clients']))).'</b><span class="ep-sub" aria-hidden="true">·</span>'
	.'<button type="button" class="ep-link" data-filter="missing">'.$e(_n('%1$s with missing pieces', '%1$s with missing pieces', $missingN)).'</button><span class="ep-sub" aria-hidden="true">·</span>'
	.'<a href="'.$e($url('ep.clients.discover')).'">'.($data['not_adopted'] === null ? $e(_('hosts not adopted: see')) : '<b>'.$e(_n('%1$s host not adopted', '%1$s hosts not adopted', (int) $data['not_adopted'])).'</b>')
	.' → '.$e(_('Discover')).'</a></div>'
	.'<div class="ep-sub"><span id="ep-showing" data-of="'.count($data['clients']).'"></span> '.$e(_('Hosts not yet adopted are on Discover, not here.'))
	.($hiddenRoles ? ' <b>'.$e(_n('%1$s role column hidden:', '%1$s role columns hidden:', count($hiddenRoles))).'</b> '.$e(implode(', ', $hiddenRoles)).'. '
		.$e(_('No listed client has a server in them; each appears by itself once one does.')) : '').'</div>'
	.'<div class="ep-key ep-sub"><b>'.$e(_('Key')).'</b> <span><span class="ep-cnt">5</span> '.$e(_('servers in that role; hover for IP · server name')).'</span> '
	.'<span><span class="ep-miss">0 of 1</span> '.$e(_('plan asks for it, no server holds it')).'</span> '
	.'<span><span class="ep-old">+ 1 older beside</span> '.$e(_('older template generation still linked (history kept)')).'</span> '
	.'<span><span class="ep-bad-chip">Missing 2</span> '.$e(_('opens the Missing panel')).'</span></div>'
	.'</div>';

if (!$ro) {
	$html[] = \Modules\EpClients\Lib\ClientActions::dialogs();
}
$html[] = '</div>';

// Backups stays with Super admins (BackupList), so an ElasticPro admin who is a Zabbix Admin edits
// here without a Backups button that would only answer "Access denied" — as the menu does.
$controls = new CList();
if ($admin) {
	$controls->addItem(new CRedirectButton(_('Discover clusters'), $url('ep.clients.discover')))
		->addItem(new CRedirectButton(_('Import / export'), $url('ep.clients.import')));
	if (($data['backups'] ?? null) !== null) {
		$controls->addItem(new CRedirectButton(_s('Backups (%1$s)', $data['backups']), $url('ep.clients.backups')));
	}
	$controls->addItem(new CRedirectButton(_('Roles'), $url('ep.clients.roles')));
}
if (!$ro) {
	$controls->addItem(new CRedirectButton(_('Add client'), $url('ep.clients.edit')));
}
(new CHtmlPage())->setTitle(_('Cluster Management'))->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls')))
	->addItem(new CObject(implode("\n", $html)))->show();
?>
<script><?php readfile(__DIR__.'/js/ep.clients.dialogs.js'); ?></script>
<script><?php readfile(__DIR__.'/js/ep.clients.list.js'); ?></script>
<style><?php readfile(__DIR__.'/css/ep.clients.dialogs.css'); ?></style>
<style>
	.ep-l { --ep-card: #fff; --ep-line: #e3e6ea; --ep-muted: #6b7380; --ep-accent: #0a66d6; --ep-chip: #eef1f5; --ep-soft: #f6f8fa;
		--ep-good: #1f7a36; --ep-bad: #c0352c; --ep-warn: #9a6400; display: grid; gap: 12px; font-size: 13px; }
	html[color-scheme="dark"] .ep-l, [theme="dark-theme"] .ep-l, [theme="hc-dark"] .ep-l { --ep-card: #25282c; --ep-line: #383c42; --ep-muted: #9aa1ab; --ep-accent: #4d9bff; --ep-chip: #31353b;
		--ep-soft: #2c3035; --ep-good: #5cc97a; --ep-bad: #ff7a70; --ep-warn: #f0b04a; }
	.ep-l .ep-note { border: 1px solid var(--ep-line); background: var(--ep-card); border-radius: 10px; padding: 10px 14px; }
	.ep-l .ep-bad { border-color: color-mix(in srgb, var(--ep-bad) 50%, transparent); } .ep-l .ep-warn { border-color: color-mix(in srgb, var(--ep-warn) 50%, transparent); }
	.ep-l .ep-tools { display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap; }
	.ep-l #ep-q { flex: 1; min-width: 260px; max-width: 420px; border-radius: 7px; padding: 6px 10px; height: auto; }
	.ep-l .ep-tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--ep-line); }
	.ep-l .ep-tabs button { background: none; border: 0; border-bottom: 2px solid transparent; color: var(--ep-muted); font: inherit; font-weight: 600; padding: 7px 12px; cursor: pointer; height: auto; }
	.ep-l .ep-tabs button[aria-selected=true] { color: inherit; border-bottom-color: var(--ep-accent); }
	.ep-l .ep-tabs span { background: var(--ep-chip); border-radius: 999px; padding: 0 7px; margin-left: 3px; font-weight: normal; font-variant-numeric: tabular-nums; }
	.ep-l .ep-bulk { display: flex; gap: 10px; align-items: center; background: var(--ep-card); border: 1px solid var(--ep-accent); border-radius: 10px; padding: 8px 12px; }
	.ep-l .ep-bulk[hidden] { display: none; }
	.ep-l .ep-table-wrap { overflow-x: auto; background: var(--ep-card); border: 1px solid var(--ep-line); border-radius: 10px; }
	.ep-l .ep-t { width: 100%; border-collapse: collapse; min-width: 1100px; }
	.ep-l .ep-t th { text-align: left; font-weight: normal; color: var(--ep-muted); font-size: 12px; padding: 9px 12px; border-bottom: 1px solid var(--ep-line); }
	.ep-l .ep-t td { padding: 10px 12px; border-top: 1px solid var(--ep-line); vertical-align: top; }
	.ep-l .ep-t tr[hidden] { display: none; }
	.ep-l .ep-tick { width: 26px; }
	.ep-l .ep-name { font-weight: 600; font-size: 13.5px; } .ep-l .ep-name a { color: inherit; }
	.ep-l .ep-sub { color: var(--ep-muted); font-size: 12px; }
	.ep-l .ep-flag { font-size: 12px; margin-top: 2px; } .ep-l .ep-bad-t { color: var(--ep-bad); } .ep-l .ep-warn-t { color: var(--ep-warn); } .ep-l .ep-strong { font-weight: 600; }
	.ep-l .ep-pill { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 600; padding: 1px 8px; border-radius: 999px; white-space: nowrap; }
	.ep-l .ep-pill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
	.ep-l .ep-s-on { color: var(--ep-good); background: color-mix(in srgb, var(--ep-good) 14%, transparent); }
	.ep-l .ep-s-maint { color: var(--ep-warn); background: color-mix(in srgb, var(--ep-warn) 16%, transparent); }
	.ep-l .ep-s-off, .ep-l .ep-s-decom { color: var(--ep-muted); background: var(--ep-chip); }
	.ep-l .ep-s-decom::before { background: transparent; border: 1.5px solid currentColor; box-sizing: border-box; }
	.ep-l .ep-s-bad { color: var(--ep-bad); background: color-mix(in srgb, var(--ep-bad) 14%, transparent); }
	.ep-l .ep-chk { font-weight: 700; font-size: 12px; border-radius: 5px; padding: 1px 7px; font-variant-numeric: tabular-nums; cursor: help; }
	.ep-l .ep-chk-ok { color: var(--ep-good); background: color-mix(in srgb, var(--ep-good) 14%, transparent); }
	.ep-l .ep-chk-bad { color: var(--ep-bad); background: color-mix(in srgb, var(--ep-bad) 14%, transparent); }
	.ep-l .ep-chk-wait { color: var(--ep-warn); background: color-mix(in srgb, var(--ep-warn) 16%, transparent); }
	.ep-l .ep-sevs { display: inline-flex; gap: 4px; text-decoration: none; }
	.ep-l .ep-sev { font-size: 11px; font-weight: 700; border-radius: 4px; padding: 0 6px; color: #fff; font-variant-numeric: tabular-nums; }
	.ep-l .ep-sev-disaster { background: #c0352c; } .ep-l .ep-sev-high { background: #e45959; } .ep-l .ep-sev-average { background: #f28a2e; }
	.ep-l .ep-sev-warning { background: #d9a400; } .ep-l .ep-sev-info { background: #6f8fc9; }
	.ep-l .ep-acts { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
	.ep-l .ep-inline { display: inline; }
	.ep-l .ep-edit { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 6px; color: var(--ep-accent); border: 1px solid transparent; text-decoration: none; }
	.ep-l .ep-edit:hover, .ep-l .ep-edit:focus-visible { background: var(--ep-chip); border-color: var(--ep-line); outline: none; }
	.ep-l td.ep-act { white-space: nowrap; width: 1%; }
	.ep-l td.ep-act .ep-acts { flex-wrap: nowrap; }
	.ep-l .ep-link { background: none; border: 0; color: var(--ep-accent); font: inherit; padding: 0; cursor: pointer; height: auto; line-height: 1.5; text-decoration: none; }
	.ep-l .ep-danger { color: var(--ep-bad); }
	.ep-l .ep-btn-alt { background: var(--ep-chip); color: inherit; border: 0; border-radius: 6px; padding: 4px 11px; cursor: pointer; font: inherit; height: auto; }
	.ep-l .ep-empty { color: var(--ep-muted); padding: 16px; }
	.ep-l .ep-search { display: inline-flex; flex-direction: column; gap: 2px; flex: 1; min-width: 240px; max-width: 420px; margin: 0; padding: 0; float: none; width: auto; }
	.ep-l .ep-search #ep-q { max-width: none; }
	.ep-l .ep-seg { display: inline-flex; border: 1px solid var(--ep-line); border-radius: 6px; overflow: hidden; }
	.ep-l .ep-seg > * { background: var(--ep-card); border: 0; border-right: 1px solid var(--ep-line); padding: 6px 12px; font: inherit; color: inherit; cursor: pointer; height: auto; text-decoration: none; display: inline-flex; gap: 6px; align-items: center; }
	.ep-l .ep-seg > *:last-child { border-right: 0; }
	.ep-l .ep-seg [aria-pressed=true] { background: var(--ep-chip); font-weight: 600; }
	.ep-l .ep-c { background: var(--ep-chip); border-radius: 999px; padding: 0 7px; font-size: 11.5px; font-variant-numeric: tabular-nums; }
	.ep-l .ep-status { display: inline-flex; gap: 6px; align-items: center; margin: 0 0 0 auto; padding: 0; float: none; width: auto; }
	.ep-l .ep-cols-btn { display: inline-flex; gap: 6px; align-items: center; }
	.ep-l .ep-pop { position: relative; z-index: 5; justify-self: end; width: 380px; max-width: 100%; background: var(--ep-card); border: 1px solid var(--ep-line); border-radius: 8px; padding: 10px 12px; box-shadow: 0 6px 18px rgba(0,0,0,.18); display: grid; gap: 8px; }
	.ep-l .ep-pop[hidden] { display: none; }
	.ep-l .ep-ph { display: flex; justify-content: space-between; align-items: center; font-weight: 600; }
	.ep-l .ep-x { background: none; border: 0; font-size: 18px; cursor: pointer; color: inherit; height: auto; }
	.ep-l .ep-pop ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; }
	.ep-l .ep-pop li { display: grid; grid-template-columns: 16px 18px minmax(0, 1fr); gap: 6px; align-items: center; padding: 3px 4px; border-radius: 4px; }
	.ep-l .ep-pop li.ep-off input[type=text] { opacity: .6; }
	.ep-l .ep-pop li.ep-drag { background: var(--ep-chip); }
	.ep-l .ep-pop .ep-grip { cursor: grab; color: var(--ep-muted); }
	.ep-l .ep-pop .ep-why { grid-column: 3; font-size: 11px; color: var(--ep-muted); }
	.ep-l .ep-pf { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
	.ep-l .ep-r { text-align: right; }
	.ep-l .ep-mono { font-family: Menlo, Consolas, monospace; font-size: 12px; color: var(--ep-muted); }
	.ep-l .ep-cnt { display: inline-block; min-width: 22px; text-align: center; font-weight: 600; font-variant-numeric: tabular-nums; border-radius: 4px; background: var(--ep-chip); padding: 0 6px; cursor: help; }
	.ep-l .ep-miss { color: var(--ep-warn); font-size: 12px; white-space: nowrap; }
	.ep-l .ep-old { display: inline-block; padding: 1px 6px; border-radius: 3px; background: color-mix(in srgb, var(--ep-warn) 16%, transparent); color: var(--ep-warn); font-size: 11.5px; white-space: nowrap; }
	.ep-l .ep-bad-chip { display: inline-block; padding: 2px 8px; border-radius: 3px; background: color-mix(in srgb, var(--ep-bad) 14%, transparent); color: var(--ep-bad); font-size: 12px; font-weight: 700; text-decoration: none; white-space: nowrap; }
	.ep-l .ep-edit { width: auto; padding: 0 8px; }
	.ep-l .ep-foot { display: grid; gap: 6px; }
	.ep-l .ep-sumline { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 13.5px; }
	.ep-l .ep-key { display: flex; flex-wrap: wrap; gap: 6px 16px; align-items: center; }
</style>
