<?php declare(strict_types = 0);
/**
 * Cluster Management: every client by status — In service (active and in maintenance),
 * Maintenance, Disabled, Decommissioned — with setup checks, open problems, days until storage is
 * full and hosts removed from Zabbix. One pencil per row opens the client's own page, which holds
 * what each status allows (Maintenance, Disable, Clone … — ClientActions). Tick clients for bulk
 * Maintenance, Disable or Enable. Follows Zabbix's light or dark theme.
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
		.($ro ? '' : $post('ep.clients.template.install', _('Write master template'), [], '', 'ep-btn-alt')).'</div>';
}

$html[] = '<div class="ep-tools">'
	.'<input type="search" id="ep-q" placeholder="'.$e(_('Find a client, host name or IP')).'" aria-label="'.$e(_('Search')).'">'
	.'<div class="ep-tabs" role="tablist">'
	.'<button type="button" role="tab" data-tab="live" aria-selected="true">'.$e(_('In service')).' <span>'.$counts['live'].'</span></button>'
	.'<button type="button" role="tab" data-tab="maint" aria-selected="false">'.$e(_('Maintenance')).' <span>'.$counts['maint'].'</span></button>'
	.'<button type="button" role="tab" data-tab="disabled" aria-selected="false">'.$e(_('Disabled')).' <span>'.$counts['disabled'].'</span></button>'
	.'<button type="button" role="tab" data-tab="decom" aria-selected="false">'.$e(_('Decommissioned')).' <span>'.$counts['decom'].'</span></button>'
	.'</div></div>';

// Bulk: the ticked clients, posted with the chosen action.
$html[] = $ro ? '' : '<form method="post" action="'.$e($url('ep.clients.status')).'" id="ep-bulk" class="ep-bulk" hidden>'.$csrf('ep.clients.status')
	.'<span id="ep-bulk-n"></span>'
	.'<button type="button" class="ep-btn-alt" data-open-maint="">'.$e(_('Maintenance…')).'</button>'
	.'<button type="submit" name="op" value="disable" class="ep-btn-alt" onclick="return confirm('.$e(json_encode(_('Disable the ticked clients? Every host of each is set to Not monitored; a backup is taken first.'))).');">'.$e(_('Disable')).'</button>'
	.'<button type="submit" name="op" value="enable" class="ep-btn-alt">'.$e(_('Enable')).'</button>'
	.'<div id="ep-bulk-ticks"></div></form>';

$html[] = '<div class="ep-table-wrap"><table class="ep-t"><thead><tr><th class="ep-tick">'.($ro ? '' : '<input type="checkbox" id="ep-all" aria-label="'.$e(_('Tick all shown')).'">').'</th>'
	.'<th>'.$e(_('Client')).'</th><th>'.$e(_('Status')).'</th><th>'.$e(_('Setup')).'</th><th>'.$e(_('Servers')).'</th><th>'.$e(_('Problems')).'</th>'
	.'<th>'.$e(_('Disk full in')).'</th><th>'.$e(_('Last change')).'</th><th>'.$e(_('Actions')).'</th></tr></thead><tbody>';
if (!$data['clients']) {
	$html[] = '<tr><td colspan="9" class="ep-empty">'.$e(_('No clients yet. Add one, import a CSV, or set up an existing cluster below.')).'</td></tr>';
}
foreach ($data['clients'] as $c) {
	$name = $c['name'];
	$sub = [$c['type']];
	if ($c['jump'] !== '') {
		$sub[] = _s('via jump host %1$s', $c['jump']);
	}
	if ($c['es_url'] !== '') {
		$sub[] = $c['es_url'];
	}
	$contacts = array_filter([$c['lead'], $c['dl']]);
	$contract = '';
	if ($c['contract_end'] !== '' && ($t = strtotime($c['contract_end'])) !== false) {
		$days = (int) floor(($t - $now) / 86400);
		$contract = $days < 0 ? '<div class="ep-flag ep-bad-t">'.$e(_s('contract ended %1$s', $c['contract_end'])).'</div>'
			: ($days <= 30 ? '<div class="ep-flag ep-warn-t">'.$e(_n('contract ends in %1$s day', 'contract ends in %1$s days', $days)).'</div>' : '');
	}
	// Using more than it bought (storage or devices), from the master host's figures.
	foreach ($c['over'] ?? [] as $basis => $pct) {
		$contract .= '<div class="ep-flag ep-bad-t">'.$e(_s($basis === 'devices' ? 'devices over purchase: %1$s%% of what was bought' : 'storage over purchase: %1$s%% of what was bought', (string) round($pct))).'</div>';
	}
	$clientCell = '<div class="ep-name">'.($c['masterid'] !== null ? '<a href="'.$e($url('ep.clients.edit', ['client' => $name])).'">'.$e($name).'</a>' : $e($name)).'</div>'
		.'<div class="ep-sub">'.$e(implode(' · ', $sub)).'</div>'
		.($contacts ? '<div class="ep-sub">'.$e(implode(' · ', $contacts)).'</div>' : '').$contract;

	// A proxy that stopped answering explains every host of the client looking broken at once.
	$down = $c['status'] === 'active' ? \Modules\EpClients\Lib\SetupChecks::proxyDown($c['checks']) : null;
	$proxyNote = $down !== null ? '<div class="ep-flag ep-bad-t" title="'.$e($down).'">'.$e(_('proxy down: its hosts cannot report')).'</div>' : '';

	// Status, with what it means right now.
	if ($c['master_missing']) {
		$statusCell = '<span class="ep-pill ep-s-bad">'.$e(_('Master host removed')).'</span><div class="ep-sub">'.$e(_('Re-create it, or forget the client')).'</div>';
	}
	elseif ($c['status'] === 'decommissioned') {
		$statusCell = '<span class="ep-pill ep-s-decom">'.$e(_('Decommissioned')).'</span><div class="ep-sub">'.$e(_('hosts kept, not monitored')).'</div>';
	}
	elseif ($c['status'] === 'disabled') {
		$statusCell = '<span class="ep-pill ep-s-off">'.$e(_('Disabled')).'</span><div class="ep-sub">'.$e(_('not monitored')).'</div>';
	}
	elseif ($c['maintenance'] !== null) {
		$m = $c['maintenance'];
		$statusCell = '<span class="ep-pill ep-s-maint">'.$e(_('Maintenance')).'</span><div class="ep-sub">'
			.$e(($m['since'] > $now ? _s('from %1$s ', date('d M H:i', $m['since'])) : '')._s('until %1$s', date(date('Ymd', $m['till']) === date('Ymd') ? 'H:i' : 'd M H:i', $m['till']))
			.' · '.($m['collect'] ? _('data collected') : _('no data'))).'</div>'.($m['reason'] !== '' ? '<div class="ep-sub">'.$e($m['reason']).'</div>' : '');
	}
	else {
		$statusCell = '<span class="ep-pill ep-s-on">'.$e(_('Active')).'</span>';
	}
	$statusCell .= $proxyNote;
	if ($c['removed'] && !$c['master_missing']) {
		$statusCell .= '<div class="ep-flag ep-bad-t" title="'.$e(implode(', ', array_column($c['removed'], 'name'))).'">'
			.$e(_n('%1$s host removed from Zabbix', '%1$s hosts removed from Zabbix', count($c['removed']))).'</div>';
	}
	if (($c['changed'] ?? []) && !$c['master_missing']) {
		$statusCell .= '<div class="ep-flag ep-warn-t" title="'.$e(implode(', ', array_map(fn($h) => $h['name'].' → '.$h['now'], $c['changed']))).'">'
			.$e(_n('%1$s host changed outside', '%1$s hosts changed outside', count($c['changed']))).'</div>';
	}
	if ($c['shared']) {
		$statusCell .= '<div class="ep-flag ep-warn-t">'.$e(_n('%1$s IP on several hosts', '%1$s IPs on several hosts', $c['shared'])).'</div>';
	}

	// Setup checks: n of m working; what fails, in the tooltip and under it.
	$setup = '<span class="ep-sub">—</span>';
	if ($c['checks'] && $c['status'] !== 'decommissioned') {
		$ok = count(array_filter($c['checks'], fn($x) => $x[1] === true));
		$bad = array_values(array_filter($c['checks'], fn($x) => $x[1] === false));
		$wait = array_values(array_filter($c['checks'], fn($x) => $x[1] === null));
		$tip = implode("\n", array_map(fn($x) => ($x[1] === true ? '✓ ' : ($x[1] === false ? '✗ ' : '… ')).$x[0].($x[2] !== '' ? ' — '.$x[2] : ''), $c['checks']));
		$class = $bad ? 'ep-chk-bad' : ($wait ? 'ep-chk-wait' : 'ep-chk-ok');
		$setup = '<span class="ep-chk '.$class.'" title="'.$e($tip).'">'.$ok.'/'.count($c['checks']).'</span>'
			.($bad ? '<div class="ep-flag ep-bad-t">'.$e($bad[0][0].($bad[0][2] !== '' ? ': '.mb_substr($bad[0][2], 0, 60) : '')).'</div>' : '');
	}

	$sev = '';
	foreach ($sevNames as $p => [$l, $cls]) {
		if (!empty($c['problems'][$p])) {
			$sev .= '<span class="ep-sev ep-sev-'.$cls.'" title="'.$e(ucfirst($cls)).'">'.$c['problems'][$p].'</span>';
		}
	}
	// Open problems, linking to Zabbix Problems filtered to the client's hosts (their ep-client tag).
	$problems = $c['status'] !== 'active' ? '<span class="ep-sub">—</span>'
		: ($sev === '' ? '<span class="ep-sub">'.$e(_('none')).'</span>'
		: '<a class="ep-sevs" title="'.$e(_('Open Problems')).'" href="zabbix.php?action=problem.view&amp;filter_set=1&amp;tags%5B0%5D%5Btag%5D=ep-client&amp;tags%5B0%5D%5Boperator%5D=1&amp;tags%5B0%5D%5Bvalue%5D='.rawurlencode($name).'">'.$sev.'</a>');

	// The site's own windows, the same ones the Client capacity widget reads — otherwise one
	// figure is yellow here and red there.
	$fc = \Modules\EpClients\Lib\Forecast::fullIn($c['full_in'], $data['forecast'] ?? []);
	$full = $fc === null ? '<span class="ep-sub">—</span>'
		: ($fc['level'] === 'flat' ? '<span class="ep-sub">'.$e($fc['text']).'</span>'
		: '<span class="'.['bad' => 'ep-bad-t ep-strong', 'warn' => 'ep-warn-t ep-strong', 'ok' => ''][$fc['level']].'">'.$e($fc['text']).'</span>');
	$change = $c['change'] ? date('d M H:i', $c['change']['at']).' · '.$c['change']['how'].' · '.$c['change']['by'] : '—';

	// One pencil opens the client's page, where everything each status allows is offered
	// (ClientActions::forClient). A client whose master host is gone has no page to open, so its
	// two ways out stay here. Read only: the same pencil, to look.
	$acts = [];
	if ($c['master_missing']) {
		$acts = \Modules\EpClients\Lib\ClientActions::forMissing($name);
	}
	else {
		$label = $ro ? _s('View %1$s', $name) : _s('Edit %1$s', $name);
		$acts[] = '<a class="ep-edit" href="'.$e($url('ep.clients.edit', ['client' => $name])).'" title="'.$e($label).'" aria-label="'.$e($label).'">'
			.($ro ? '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.4" d="M1 8s2.5-4.5 7-4.5S15 8 15 8s-2.5 4.5-7 4.5S1 8 1 8z"/><circle cx="8" cy="8" r="2" fill="none" stroke="currentColor" stroke-width="1.4"/></svg>'
				: '<svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" d="M11 2.5l2.5 2.5L5.5 13 2 14l1-3.5z"/></svg>')
			.'</a>';
	}
	$html[] = '<tr data-tabs="'.$e(implode(' ', $tab($c))).'" data-search="'.$e($c['search']).'"'.(in_array('live', $tab($c), true) ? '' : ' hidden').'>'
		.'<td class="ep-tick">'.($c['master_missing'] || $ro ? '' : '<input type="checkbox" class="ep-sel" value="'.$e($name).'" aria-label="'.$e(_s('Tick %1$s', $name)).'">').'</td>'
		.'<td>'.$clientCell.'</td><td>'.$statusCell.'</td><td>'.$setup.'</td><td>'.$e($c['machines'])
		.($c['unassigned'] ? '<div class="ep-flag ep-warn-t">'.$e(_n('%1$s without a role', '%1$s without a role', $c['unassigned'])).'</div>' : '').'</td>'
		.'<td>'.$problems.'</td><td>'.$full.'</td><td class="ep-sub">'.$e($change).'</td><td class="ep-act"><div class="ep-acts">'.implode('', $acts).'</div></td></tr>';
}
$html[] = '</tbody></table></div>';

if (!$ro) {
	$html[] = \Modules\EpClients\Lib\ClientActions::dialogs();
}

if ($data['candidates'] && !$ro) {
	$html[] = '<h4 class="ep-h">'.$e(_('Elasticsearch clusters that are not a client yet')).'</h4><div class="ep-sub">'
		.$e(_('Setting one up keeps it and every host in its group — history, passwords — renames them to the client\'s pattern and adds what is missing.')).'</div><ul class="ep-cands">';
	foreach ($data['candidates'] as $c) {
		$html[] = '<li><b>'.$e($c['host']).'</b> <span class="ep-sub">'.$e($c['es_url']).'</span> <a class="ep-link" href="'.$e($url('ep.clients.edit', ['client' => $c['name']])).'">'.$e(_('Set up as client')).'</a></li>';
	}
	$html[] = '</ul>';
}
$html[] = '</div>';

$controls = $ro ? new CList() : (new CList())
	->addItem(new CRedirectButton(_('Import / export'), $url('ep.clients.import')))
	->addItem(new CRedirectButton(_s('Backups (%1$s)', $data['backups']), $url('ep.clients.backups')))
	->addItem(new CRedirectButton(_('Roles'), $url('ep.clients.roles')))
	->addItem(new CRedirectButton(_('Add client'), $url('ep.clients.edit')));
(new CHtmlPage())->setTitle(_('Cluster Management'))->setControls((new CTag('nav', true, $controls))->setAttribute('aria-label', _('Content controls')))
	->addItem(new CObject(implode("\n", $html)))->show();
?>
<script><?php readfile(__DIR__.'/js/ep.clients.dialogs.js'); ?></script>
<script>
	(function () {
		const root = document.querySelector('.ep-l');
		if (!root) return;
		const rows = [...root.querySelectorAll('tbody tr[data-tabs]')];
		let tab = 'live';
		const q = root.querySelector('#ep-q');
		function show() {
			const term = q.value.trim().toLowerCase();
			rows.forEach((r) => {
				const inTab = r.dataset.tabs.split(' ').includes(tab);
				// A search looks across every status, so a host or IP is found wherever its client is.
				r.hidden = term !== '' ? !r.dataset.search.includes(term) : !inTab;
			});
			ticks();
		}
		root.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => {
			tab = b.dataset.tab;
			root.querySelectorAll('[data-tab]').forEach((x) => x.setAttribute('aria-selected', String(x === b)));
			show();
		}));
		q.addEventListener('input', show);

		// Bulk: the ticked, shown clients go into the bulk form as clients[<name>]=1.
		const bulk = root.querySelector('#ep-bulk');
		function ticked() { return rows.filter((r) => !r.hidden).map((r) => r.querySelector('.ep-sel')).filter((c) => c && c.checked).map((c) => c.value); }
		function ticks() {
			if (!bulk) return;
			const names = ticked();
			bulk.hidden = !names.length;
			root.querySelector('#ep-bulk-n').textContent = names.length + (names.length === 1 ? ' client ticked' : ' clients ticked');
			const box = root.querySelector('#ep-bulk-ticks');
			box.textContent = '';
			names.forEach((n) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = 'clients[' + n + ']'; i.value = '1'; box.appendChild(i); });
		}
		root.querySelectorAll('.ep-sel').forEach((c) => c.addEventListener('change', ticks));
		root.querySelector('#ep-all')?.addEventListener('change', (e) => {
			rows.filter((r) => !r.hidden).forEach((r) => { const c = r.querySelector('.ep-sel'); if (c) c.checked = e.target.checked; });
			ticks();
		});

		window.EpDialogs.init(root, ticked);
	})();
</script>
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
	.ep-l .ep-h { margin: 12px 0 2px; } .ep-l .ep-cands { list-style: none; padding: 0; margin: 6px 0; display: grid; gap: 4px; }
</style>
