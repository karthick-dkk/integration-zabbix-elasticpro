<?php declare(strict_types = 0);
/**
 * Add or edit a client (boards Client-page.dc.html and Edit-after.dc.html, approved 2026-10-23):
 * a header with the client's status and its actions (an existing client only — ClientActions), the
 * tabs Overview / Servers & roles / Templates / Connection / Backups — Overview carries the Missing
 * panel, servers by role and the cluster, templates and contacts — and the compact form: Client |
 * Connection, Servers & roles, Capacity | Log archive, and the action bar.
 * Servers are added one at a time in a sheet, each with one or more roles; the page's behaviour
 * is views/js/ep.clients.edit.js and its look views/css/ep.clients.edit.css. Follows Zabbix's
 * light or dark theme.
 *
 * @var CView $this
 * @var array $data
 */

$f = $data['form'];
$editing = $data['mode'] === 'edit';
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$url = fn(string $action, array $args = []) => array_reduce(array_keys($args), fn($u, $k) => $u->setArgument($k, $args[$k]),
	(new CUrl('zabbix.php'))->setArgument('action', $action))->getUrl();
$field = fn(string $name) => (string) ($f[$name] ?? '');
$num = fn(string $name) => in_array($field($name), ['', '0'], true) ? '' : $field($name);

/**
 * A labelled row inside a card: label | input | a hint (i). The hint is the explanation that used
 * to sit under the input; the input names it with aria-describedby, so it is read out with it.
 */
$tips = 0;
$row = function(string $label, string $control, string $for = '', string $hint = '') use ($e, &$tips): string {
	$tip = '<span></span>';
	if ($hint !== '') {
		$id = 'ep-tip-'.(++$tips);
		$control = preg_replace('/<(input|select|textarea)\b/', '<$1 aria-describedby="'.$id.'"', $control, 1);
		$tip = '<span class="ep-tipw"><span class="ep-i" tabindex="0" role="img" aria-label="'.$e(_('Details')).'">ⓘ</span>'
			.'<span class="ep-tip" role="tooltip" id="'.$id.'">'.$hint.'</span></span>';
	}
	return '<div class="ep-row"><label'.($for !== '' ? ' for="'.$e($for).'"' : '').'>'.$e($label).'</label><div class="ep-ctl">'.$control.'</div>'.$tip.'</div>';
};
$text = fn(string $name, string $placeholder = '', string $extra = '') =>
	'<input type="text" id="'.$e($name).'" name="'.$e($name).'" value="'.$e($field($name)).'" placeholder="'.$e($placeholder).'" '.$extra.'>';
/** A segmented control: radio inputs, one shown pressed. */
$seg = function(string $name, array $options, string $value) use ($e): string {
	$out = '<div class="ep-seg" role="radiogroup">';
	foreach ($options as $v => $label) {
		$id = $name.'_'.preg_replace('/[^a-z0-9]/i', '', (string) $v);
		$out .= '<input type="radio" name="'.$e($name).'" id="'.$e($id).'" value="'.$e($v).'"'.((string) $v === $value ? ' checked' : '').'>'
			.'<label for="'.$e($id).'">'.$e($label).'</label>';
	}
	return $out.'</div>';
};

$ro = !($data['can_write'] ?? true);
$html = ['<div class="ep-edit">'];

/* ---- header: the status, and what can be done to this client ---- */
// An existing client only: a new one has no status and nothing to do to it yet. The actions are
// the list row's, from the one place that defines them; they are forms of their own, so the
// header sits above the client form rather than in it.
if ($editing) {
	$client = ['name' => $field('name'), 'status' => $data['status'], 'maintenance' => $data['maintenance'],
		'removed' => $data['removed'], 'changed' => $data['changed'] ?? [], 'masterid' => $data['masterid'] ?? null];
	[$badge_class, $badge_label] = \Modules\EpClients\Lib\ClientActions::badge($data['status'], $data['maintenance']);
	$sub = '';
	if ($data['maintenance'] !== null) {
		$sub = _s('until %1$s', date('d M H:i', $data['maintenance']['till']))
			.($data['maintenance']['reason'] !== '' ? ' — '.$data['maintenance']['reason'] : '');
	}
	$acts = $ro ? ($client['masterid'] !== null ? [\Modules\EpClients\Lib\ClientActions::dashboard($client, 'ep-abtn')] : [])
		: \Modules\EpClients\Lib\ClientActions::forClient($client, 'ep-abtn');
	$html[] = '<div class="ep-head"><div class="ep-head-id"><span class="ep-head-name">'.$e($field('name')).'</span>'
		.'<span class="ep-st '.$badge_class.'" id="ep-status">'.$e($badge_label).'</span>'
		.($sub !== '' ? '<span class="ep-head-sub">'.$e($sub).'</span>' : '').'</div>'
		.'<div class="ep-acts" role="group" aria-label="'.$e(_('Client actions')).'">'.implode('', $acts).'</div></div>';
}
if ($ro) {
	$html[] = '<div class="ep-notes">'.implode('', array_map(fn($line) => '<div class="ep-note">'.$e($line).'</div>',
		\Modules\EpClients\Lib\EpRole::noteLines($data['write_note'] ?? null))).'</div>';
}
$html[] = '<form method="post" action="'.$e($url('ep.clients.save')).'" id="ep-client-form" class="ep-f" autocomplete="off">'.($ro ? '<fieldset disabled class="ep-ro">' : '');
$html[] = '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.$e(CCsrfTokenHelper::get('ep.clients.save')).'">';
$html[] = '<input type="hidden" name="mode" value="'.$e($data['mode']).'">';
$html[] = '<input type="hidden" name="servers" id="servers" value="'.$e($field('servers')).'">';

$html[] = '<div class="ep-notes">';
/* ---- status ---- */
if ($data['status'] === 'disabled') {
	$html[] = '<div class="ep-note ep-warn"><b>'.$e(_('Disabled.')).'</b> '.$e(_('Every host of this client is Not monitored. Changes saved here apply, and new hosts start not monitored.').($ro ? '' : ' '._('Enable it with the button above.'))).'</div>';
}
elseif ($data['status'] === 'decommissioned') {
	$html[] = '<div class="ep-note ep-warn"><b>'.$e(_('Decommissioned.')).'</b> '.$e(_('Its hosts are kept, not monitored, with their history. Restore it to change it.').($ro ? '' : ' '._('Use the button above.'))).'</div>';
}
if ($data['removed']) {
	$html[] = '<div class="ep-note ep-bad"><b>'.$e(_('Removed from Zabbix:')).'</b> '.$e(implode(', ', array_map(fn($h) => $h['name'].($h['ip'] ? ' ('.$h['ip'].')' : ''), $data['removed'])))
		.'. '.$e(_('Deleted in Zabbix, not by this page. Re-create them or accept the removal with the buttons above.')).'</div>';
}
if ($data['changed'] ?? []) {
	$html[] = '<div class="ep-note ep-warn"><b>'.$e(_('Changed outside this page:')).'</b> '.$e(implode('; ', array_map(fn($h) => $h['why'] === 'moved'
		? _s('%1$s moved out of the client\'s group', $h['now']) : _s('%1$s renamed to %2$s', $h['name'], $h['now']), $data['changed'])))
		.'. '.$e(_('"Put back" above restores the name and group.')).'</div>';
}

/* ---- messages ---- */
if ($data['deletes']) {
	$html[] = '<div class="ep-note ep-bad ep-confirm"><b>'.$e(_n('Saving deletes %1$s host, with its history:', 'Saving deletes %1$s hosts, with their history:', count($data['deletes']['hosts']))).'</b><ul>'
		.implode('', array_map(fn($h) => '<li>'.$e($h).'</li>', $data['deletes']['hosts'])).'</ul>'
		.'<label><input type="checkbox" name="confirm_delete" value="'.$e($data['deletes']['sig']).'"> '.$e(_('Yes, delete these hosts. A backup of the settings is taken first; the history cannot come back.')).'</label>'
		.'<div class="ep-hint">'.$e(_('Not what you meant? Put the servers back below (Undo), then save.')).'</div></div>';
}
if ($data['errors']) {
	$html[] = '<div class="ep-note ep-bad"><b>'.$e(_('Nothing was saved.')).'</b><ul>'
		.implode('', array_map(fn($x) => '<li>'.$e($x).'</li>', $data['errors'])).'</ul></div>';
}
if ($data['done']) {
	$html[] = '<div class="ep-note ep-warn"><b>'.$e(_('Already done before Zabbix refused:')).'</b><ul>'
		.implode('', array_map(fn($x) => '<li>'.$e($x).'</li>', $data['done'])).'</ul></div>';
}
if (($data['cloned_from'] ?? '') !== '') {
	$html[] = '<div class="ep-note ep-good">'.$e(_s('Started from "%1$s": roles, requested capacity and settings are copied. Give the new client its name, ES URL, password, contacts and servers.', $data['cloned_from'])).'</div>';
}
if ($data['adopting']) {
	$html[] = '<div class="ep-note ep-good">'.$e(_('This client has hosts already. They are kept — history, passwords, host names — and only what is missing is added.')).'</div>';
}
if ($data['unassigned']) {
	$html[] = '<div class="ep-note ep-warn">'.$e(_n('%1$s host has no role yet. It is listed under Servers: give it a role to take it on; until then it is left exactly as it is.',
		'%1$s hosts have no role yet. They are listed under Servers: give each a role to take it on; until then they are left exactly as they are.', count($data['unassigned']))).'</div>';
}
if ($data['shared']) {
	$items = '';
	foreach ($data['shared'] as $ip => $hosts) {
		$items .= '<li><label><input type="checkbox" name="merge[]" value="'.$e($ip).'"'.(in_array($ip, $data['merge'], true) ? ' checked' : '').'> '.$e(_s('Merge the %1$s hosts on %2$s into one server:', count($hosts), $ip))
			.' <b>'.$e($hosts[0]).'</b> '.$e(_('is kept, with its history;')).' '.$e(implode(', ', array_slice($hosts, 1))).' '.$e(_('are deleted.')).'</label></li>';
	}
	$html[] = '<div class="ep-note ep-warn"><b>'.$e(_('Several hosts share one IP.')).'</b> '
		.$e(_('A server can hold several roles, so each IP should be one host. Tick to merge; a backup is taken first.')).'<ul class="ep-plain">'.$items.'</ul></div>';
}

$html[] = '</div>';
/* ==== Overview, and the tabs (board Client-page.dc.html) — an existing client only ==== */
$tabs = $editing;
$keyTab = ['role_confirm' => 'servers', 'role' => 'servers', 'role_hosts' => 'servers', 'server_name' => 'servers', 'ip' => 'servers',
	'credential' => 'connection', 'credential_hidden' => 'connection', 'password' => 'connection', 'password_core' => 'connection', 'password_plain' => 'connection',
	'jump' => 'connection', 'macros' => 'connection', 'proxy' => 'connection', 'cluster' => 'connection', 'client_group' => 'connection',
	'old_generation' => 'templates', 'linked' => 'templates', 'templates' => 'templates'];
$tabLabel = ['servers' => _('Servers & roles'), 'connection' => _('Connection'), 'templates' => _('Templates')];
if ($tabs) {
	$html[] = '<nav class="ep-tabs" role="tablist" aria-label="'.$e(_('Client sections')).'">'
		.'<button type="button" role="tab" data-tab="overview" aria-selected="true">'.$e(_('Overview')).'</button>'
		.'<button type="button" role="tab" data-tab="servers" aria-selected="false">'.$e(_('Servers & roles')).'</button>'
		.'<button type="button" role="tab" data-tab="templates" aria-selected="false">'.$e(_('Templates')).'</button>'
		.'<button type="button" role="tab" data-tab="connection" aria-selected="false">'.$e(_('Connection')).'</button>'
		.'<button type="button" role="tab" data-tab="backups" aria-selected="false">'.$e(_('Backups')).'</button></nav>';

	$ov = [];
	$miss = $data['missing'] ?? [];
	if ($miss) {
		$items = '';
		foreach ($miss as $m) {
			$t = $keyTab[$m['key']] ?? 'connection';
			$items .= '<li data-missing="'.$e($m['key']).'"><div><b>'.$e($m['text']).'</b> <span class="ep-hint">'.$e($m['fix']).'</span></div>'
				.'<button type="button" class="ep-fix" data-goto="'.$e($t).'">'.$e(_s('Fix: %1$s', $tabLabel[$t])).'</button></li>';
		}
		$ov[] = '<section class="ep-missing" id="ep-missing" aria-labelledby="ep-miss-h"><div class="ep-miss-head"><h2 id="ep-miss-h">'
			.$e(_n('Missing: %1$s thing could not be determined or set', 'Missing: %1$s things could not be determined or set', count($miss))).'</h2>'
			.'<span class="ep-hint">'.$e(_('Nothing was guessed. Each line says what to do.')).'</span></div><ul class="ep-ml">'.$items.'</ul></section>';
	}
	// Servers by role: IP · server name, every role, "none" said.
	$rolesHtml = '';
	foreach ($data['roles']['families'] as $fam) {
		foreach ($fam['roles'] as $r) {
			$list = $data['by_role'][$r['id']] ?? [];
			$req = (int) ((float) $field($r['id'].'_servers'));
			$cell = $list ? implode('', array_map(fn($h) => '<div><span class="ep-mono">'.$e($h['ip']).'</span> · '.($h['name'] !== '' ? $e($h['name']) : '<span class="ep-hint">'.$e(_('server name unknown')).'</span>')
				.($h['confirm'] === 'kafka' ? ' <span class="ep-flag">'.$e(_('kafka: confirm Forwarder or Parser')).'</span>' : '').'</div>', $list))
				: '<span class="ep-hint">'.$e($req > 0 ? _s('none · the plan asks for %1$s', $req) : _('none')).'</span>';
			if (!$list && $req === 0 && !in_array($r['id'], ['es_data', 'parser', 'forwarder', 'engine'], true)) {
				continue;
			}
			$rolesHtml .= '<div class="ep-k">'.$e($r['label']).'</div><div>'.$cell.'</div>';
		}
	}
	$px = fn(string $v) => $v === '' ? _('the Zabbix server') : (strpos($v, 'proxy:') === 0 ? substr($v, 6) : (strpos($v, 'group:') === 0 ? _s('proxy group %1$s', substr($v, 6)) : ($v === 'jump' ? _('the jump host') : $v)));
	$lx = $field('servers_monitored_by');
	$lxText = $lx === 'server' ? _('the Zabbix server') : ($lx === '' ? _s('%1$s (as the cluster host)', $px($field('monitored_by') === 'jump' ? '' : $field('monitored_by'))) : $px($lx));
	$ov[] = '<div class="ep-card"><div class="ep-ct"><span>'.$e(_('Servers by role')).'</span><span class="ep-hint">'
		.$e(_('IP · server name. The Zabbix host name is the IP; the visible name is the server name.')).' '
		.$e(_s('Linux hosts monitored by %1$s; the cluster host by %2$s.', $lxText, $px($field('monitored_by')))).'</span></div><div class="ep-kv">'.$rolesHtml.'</div></div>';
	$ci = $data['cluster_info'] ?? null;
	$five = \Modules\EpClients\Lib\Reconciler::clusterTemplates();
	$fc = $data['full_in'] !== null ? \Modules\EpClients\Lib\Forecast::fullIn($data['full_in'], $data['forecast'] ?? []) : null;
	$notSet = '<span class="ep-hint">'.$e(_('not set')).'</span>';
	$seenText = $ci === null ? '<span class="ep-hint">'.$e(_('unknown: no cluster host')).'</span>'
		: ($field('es_url') === '' ? '<span class="ep-hint">'.$e(_('unknown: needs ES URL')).'</span>'
		: ((int) $data['last_seen'] > 0 ? $e(date('Y-m-d H:i', (int) $data['last_seen'])) : '<span class="ep-hint">'.$e(_('never')).'</span>'));
	$ov[] = '<div class="ep-ov3">'
		.'<div class="ep-card"><div class="ep-ct">'.$e(_('Cluster')).'</div><div class="ep-kv">'
		.'<div class="ep-k">'.$e(_('Cluster host')).'</div><div class="ep-mono">'.($ci !== null ? $e($ci['host']) : $e(_('none'))).'</div>'
		.'<div class="ep-k">'.$e(_('ES URL')).'</div><div>'.($field('es_url') !== '' ? '<span class="ep-mono">'.$e($field('es_url')).'</span>' : $notSet).'</div>'
		.'<div class="ep-k">'.$e(_('Last seen')).'</div><div>'.$seenText.'</div>'
		.'<div class="ep-k">'.$e(_('Disk full in')).'</div><div>'.($fc === null ? '<span class="ep-hint">'.$e($field('es_url') === '' ? _('unknown: needs ES URL') : _('unknown: not measured yet')).'</span>' : $e($fc['text'])).'</div>'
		.'<div class="ep-k">'.$e(_('Retention')).'</div><div>'.($field('retention_days') !== '' ? $e(_n('%1$s day', '%1$s days', (int) $field('retention_days'))) : $notSet).'</div>'
		.'<div class="ep-k">'.$e(_('Backup')).'</div><div>'.($field('backup_gb') !== '' ? $e($field('backup_gb').' GB') : $notSet).'</div>'
		.'</div></div>'
		.'<div class="ep-card"><div class="ep-ct">'.$e(_('Templates')).'</div><div class="ep-kv">'
		.'<div class="ep-k">'.$e(_('Current')).'</div><div><div class="ep-chips">'.implode('', array_map(fn($t) => '<span class="ep-chip'.($ci !== null && !in_array($t, $ci['templates'], true) ? ' ep-chip-miss' : '').'">'.$e($t).'</span>', $five)).'</div>'
			.'<div class="ep-hint">'.$e(_('linked directly to the cluster host')).'</div></div>'
		.'<div class="ep-k">'.$e(_('Beside')).'</div><div>'.(($ci !== null && ($ci['beside']['known'] || $ci['beside']['unknown']))
			? implode(' ', array_map(fn($t) => '<span class="ep-flag">'.$e($t).'</span>', $ci['beside']['known']))
				.implode(' ', array_map(fn($t) => ' <span class="ep-flag" title="'.$e(_('Gives the Elasticsearch items under a template name this module does not know.')).'">'.$e($t).'</span> <span class="ep-hint">'.$e(_('name not known here')).'</span>', $ci['beside']['unknown']))
			: '<span class="ep-hint">'.$e(_('none')).'</span>').'</div>'
		.'<div class="ep-k">'.$e(_('Master host')).'</div><div class="ep-mono">'.$e($data['master_host'] ?? _('none')).'</div>'
		.'</div></div>'
		.'<div class="ep-card"><div class="ep-ct">'.$e(_('Contacts')).'</div><div class="ep-kv">'
		.'<div class="ep-k">'.$e(_('SOC / Manager lead')).'</div><div>'.($field('lead_name') !== '' ? $e($field('lead_name')) : $notSet).'</div>'
		.'<div class="ep-k">'.$e(_('Cluster DL')).'</div><div>'.($field('cluster_dl') !== '' ? $e($field('cluster_dl')) : $notSet).'</div>'
		.'<div class="ep-k">'.$e(_('Last change')).'</div><div>'.($data['history'] ? $e(date('Y-m-d H:i', $data['history'][0]['at']).' · '.$data['history'][0]['what'].' · '.$data['history'][0]['by']) : '<span class="ep-hint">'.$e(_('none recorded')).'</span>').'</div>'
		.'</div></div></div>';
	$acts = '';
	foreach ($data['history'] as $h) {
		$acts .= '<div><span class="ep-hint">'.$e(date('Y-m-d H:i', $h['at'])).'</span> · '.$e($h['by']).' '.$e($h['what']).'</div>';
	}
	$ov[] = '<div class="ep-card"><div class="ep-ct">'.$e(_('Activity')).'</div><div class="ep-activity">'.($acts !== '' ? $acts : '<span class="ep-hint">'.$e(_('No change recorded yet.')).'</span>').'</div></div>';
	$html[] = '<div class="ep-panel" data-panel="overview">'.implode('', $ov).'</div>';

	// Templates tab: what the cluster host carries, by name.
	$tt = '';
	foreach (($ci['templates'] ?? []) as $t) {
		$kind = in_array($t, $five, true) ? _('ElasticPro, linked directly')
			: (in_array($t, $ci['beside']['known'], true) ? _('older generation, kept beside with its history')
			: (in_array($t, $ci['beside']['unknown'], true) ? _('gives the Elasticsearch items under a name this module does not know; kept beside with its history')
			: _('another template, left as it is')));
		$tt .= '<tr><td>'.$e($t).'</td><td class="ep-hint">'.$e($kind).'</td></tr>';
	}
	foreach (array_diff($five, $ci['templates'] ?? []) as $t) {
		$tt .= '<tr><td>'.$e($t).'</td><td class="ep-bad-t">'.$e(_('not linked yet: saving links it')).'</td></tr>';
	}
	$html[] = '<div class="ep-panel" data-panel="templates" hidden><div class="ep-card"><div class="ep-ct">'.$e(_s('Templates on %1$s', $ci['host'] ?? _('the cluster host'))).'</div>'
		.($ci === null ? '<div class="ep-hint">'.$e(_('No cluster host yet.')).'</div>' : '<table class="ep-tb"><tbody>'.$tt.'</tbody></table>'
		.'<div class="ep-hint">'.$e(_('An older generation stays linked beside: unlinking it is tools/migrate-templates.py\'s job (docs/MIGRATION.md), never "unlink and clear".')).'</div>').'</div></div>';
	// Backups tab: this client's changes, each with the backup taken before it.
	$bk = '';
	foreach ($data['history'] as $h) {
		$bk .= '<tr><td class="ep-mono">'.$e(date('Y-m-d H:i', $h['at'])).'</td><td>'.$e($h['what']).'</td><td>'.$e($h['by']).'</td><td class="ep-mono">'.$e($h['backup'] ?? '—').'</td></tr>';
	}
	$html[] = '<div class="ep-panel" data-panel="backups" hidden><div class="ep-card"><div class="ep-ct">'.$e(_('Backups')).'</div>'
		.($bk !== '' ? '<table class="ep-tb"><thead><tr><th>'.$e(_('When')).'</th><th>'.$e(_('Change')).'</th><th>'.$e(_('By')).'</th><th>'.$e(_('Backup taken before it')).'</th></tr></thead><tbody>'.$bk.'</tbody></table>'
			: '<div class="ep-hint">'.$e(_('No change recorded yet.')).'</div>')
		.'<div class="ep-hint">'.$e(_('A backup of every client is taken before each change; Backups (Super admins) restores one.')).'</div></div></div>';
}

/* ==== The form (board Edit-after.dc.html): Client | Connection, Servers & roles, Capacity | Log archive ==== */
$html[] = '<div class="ep-panel" data-panel="form"'.($tabs ? ' hidden' : '').'>';
$html[] = '<div class="ep-two">';

/* ---- Client ---- */
$epTypes = \Modules\EpClients\Lib\Settings::types();
$epTypeNow = $field('type') !== '' ? $field('type') : $epTypes['default'];
$epTypeList = in_array($epTypeNow, $epTypes['all'], true) ? $epTypes['all'] : array_merge($epTypes['all'], [$epTypeNow]);
[$badgeClass, $badgeLabel] = $editing ? \Modules\EpClients\Lib\ClientActions::badge($data['status'], $data['maintenance']) : ['', ''];
$html[] = '<section class="ep-card" id="ep-card-client" aria-labelledby="h-client"><h2 class="ep-ttl" id="h-client">'.$e(_('Client')).'</h2>';
$html[] = $row(_('Name').' *', $text('name', 'example', $editing ? 'readonly' : 'required'), 'name',
	$e($editing ? _('Fixed after creation: it names every host and group of this client.') : _('Names its host group and its master host (example-Master).')));
$html[] = $row(_('Type').' *', $seg('type', array_combine($epTypeList, $epTypeList), $epTypeNow).' <span class="ep-inline-hint" id="ep-type-hint"></span>', '',
	$e(_('DI needs a log archive (S3) and a URL; CI and On-Prem do not.')));
if ($editing) {
	$html[] = $row(_('Status'), '<span class="ep-st '.$e($badgeClass).'">'.$e($badgeLabel).'</span> <span class="ep-hint">'.$e(_('changed with Maintenance or Disable at the top')).'</span>');
}
$html[] = $row(_('Lead'), $text('lead_name', _('SOC / Manager lead')), 'lead_name');
$html[] = $row(_('Cluster DL'), '<input type="email" id="cluster_dl" name="cluster_dl" value="'.$e($field('cluster_dl')).'" placeholder="soc-example@company.com">', 'cluster_dl');
$html[] = '<fieldset class="ep-row ep-fs"><legend>'.$e(_('This DL receives')).'</legend><label>'.$e(_('This DL receives')).'</label><div class="ep-ctl ep-inline">'
	.'<label class="ep-check"><input type="checkbox" name="alert_dl" value="1"'.($field('alert_dl') === '1' ? ' checked' : '').'> '.$e(_('Alert e-mails')).'</label>'
	.'<label class="ep-check"><input type="checkbox" name="weekly_report" value="1"'.($field('weekly_report') === '1' ? ' checked' : '').'> '.$e(_('Weekly PDF report')).'</label></div><span></span></fieldset>';
$html[] = $row(_('Notes'), '<div class="ep-inline ep-grow"><input type="text" id="client_notes" name="client_notes" value="'.$e($field('client_notes')).'" placeholder="'.$e(_('Notes')).'" maxlength="2000">'
	.'<button type="button" class="ep-lnk" data-ep-notes-more aria-label="'.$e(_('Expand notes')).'">'.$e(_('more')).'</button></div>', 'client_notes');
// Not on the compact form, kept as it is so a save never blanks it.
$html[] = '<input type="hidden" name="contract_end" value="'.$e($field('contract_end')).'">';
$html[] = '</section>';

/* ---- Connection ---- */
$pw = $data['password'] ?? ['stored' => false, 'record' => null];
$pwField = !$data['has_cluster'] || !$pw['stored'];
$pwRec = $pw['record'] ?? null;
$pwSaid = $pwRec !== null
	? _s('Set %1$s by %2$s', date('Y-m-d', (int) $pwRec['at']), (string) $pwRec['by']).(!empty($pwRec['core']) ? '' : ' · '._('ElasticPro was not given it'))
	: _('Set outside this page; ElasticPro was not given it');
$dc = $data['default_credential'] ?? ['set' => false, 'user' => null, 'record' => null];
$mode = $field('es_password_mode') ?: 'zabbix';
$defLabel = $dc['set'] ? _s('Default (%1$s, set %2$s)', ($dc['user'] ?? '') !== '' ? $dc['user'] : 'elastic', $dc['record'] !== null ? date('Y-m-d', (int) $dc['record']['at']) : _('outside this page'))
	: _('Default (not set)');
$route = $field('monitored_by') === 'jump' ? 'jump' : 'direct';
$proxyOpts = function (string $name, string $value, bool $follow = false) use ($e, $data): string {
	$o = '<select id="'.$e($name).'" name="'.$e($name).'">';
	if ($follow) {
		$o .= '<option value=""'.($value === '' ? ' selected' : '').'>'.$e(_('Same as the cluster host')).'</option>';
	}
	$server = $follow ? 'server' : '';
	$o .= '<option value="'.$server.'"'.($value === $server || (!$follow && $value === '') ? ' selected' : '').'>'.$e(_('Zabbix server (default)')).'</option>';
	foreach ($data['proxies'] as $p) {
		$o .= '<option value="'.$e('proxy:'.$p).'"'.($value === 'proxy:'.$p ? ' selected' : '').'>'.$e($p).'</option>';
	}
	foreach ($data['proxy_groups'] as $g) {
		$o .= '<option value="'.$e('group:'.$g).'"'.($value === 'group:'.$g ? ' selected' : '').'>'.$e(_s('Proxy group: %1$s', $g)).'</option>';
	}
	$known = array_merge(['', 'server', 'jump'], array_map(fn($p) => 'proxy:'.$p, $data['proxies']), array_map(fn($g) => 'group:'.$g, $data['proxy_groups']));
	if (!in_array($value, $known, true)) {
		$o .= '<option value="'.$e($value).'" selected>'.$e($value.' — '._('not in Zabbix')).'</option>';
	}
	return $o.'</select>';
};
$ci = $data['cluster_info'] ?? null;
$html[] = '<section class="ep-card" id="ep-card-connection" aria-labelledby="h-conn"><h2 class="ep-ttl" id="h-conn">'.$e(_('Connection')).'</h2>';
if ($editing) {
	$html[] = $row(_('Cluster host'), $ci !== null ? '<span class="ep-mono">'.$e($ci['host']).'</span> <a href="'.$e((new CUrl('zabbix.php'))->setArgument('action', 'host.edit')->setArgument('hostid', $ci['hostid'])->getUrl()).'">'.$e(_('open')).'</a>'
		: '<span class="ep-hint">'.$e(_('none yet: saving with a URL makes it')).'</span>');
}
$html[] = $row(_('URL').' *', '<input type="url" id="es_url" name="es_url" value="'.$e($field('es_url')).'" placeholder="https://es.example.local:9200">', 'es_url');
// Credential: Default, or This cluster only (kept in Zabbix + ElasticPro, or Vault). The mode
// field es_password_mode carries the choice; the password is never shown (ClusterSecret).
$html[] = $row(_('Credential'), '<div class="ep-seg" role="radiogroup" aria-label="'.$e(_('Credential')).'">'
	.'<input type="radio" name="ep_cred" id="ep_cred_default" value="default"'.($mode === 'default' ? ' checked' : '').'><label for="ep_cred_default">'.$e($defLabel).'</label>'
	.'<input type="radio" name="ep_cred" id="ep_cred_own" value="own"'.($mode !== 'default' ? ' checked' : '').'><label for="ep_cred_own">'.$e(_('This cluster only')).'</label></div>'
	.'<input type="hidden" name="es_password_mode" id="es_password_mode" value="'.$e($mode).'">');
$html[] = '<div id="ep-cred-default"'.($mode === 'default' ? '' : ' hidden').'>'.$row(_('Default'),
	$dc['set'] ? '<span class="ep-dot"></span> '.$e(_s('user %1$s', ($dc['user'] ?? '') !== '' ? $dc['user'] : 'elastic')).($dc['record'] !== null ? ' · '.$e(_s('set %1$s by %2$s', date('Y-m-d', (int) $dc['record']['at']), (string) $dc['record']['by'])) : '')
		.' <a href="'.$e($url('ep.clients.settings')).'">'.$e(_('Change default in Settings')).'</a>'
		: '<span class="ep-bad-t">'.$e(_('No default credential is set.')).'</span> <a href="'.$e($url('ep.clients.settings')).'">'.$e(_('Set it in Settings')).'</a>').'</div>';
$html[] = '<div id="ep-cred-own"'.($mode === 'default' ? ' hidden' : '').'>';
$html[] = $row(_('User').' *', $text('es_user', 'elastic'), 'es_user');
$html[] = $row(_('Kept in'), '<select id="ep_kept" aria-label="'.$e(_('Kept in')).'"><option value="zabbix"'.($mode === 'zabbix' ? ' selected' : '').'>'.$e(_('Zabbix + ElasticPro (default)')).'</option>'
	.'<option value="vault"'.($mode === 'vault' ? ' selected' : '').'>'.$e(_('Vault (path:key)')).'</option></select>', 'ep_kept',
	$e(_('Zabbix + ElasticPro: a secret macro on the cluster host, and handed to the ElasticPro core encrypted. Neither can show it back.')));
$html[] = $row(_('Password').' *', '<div id="ep-pw-zabbix"'.($mode === 'vault' ? ' hidden' : '').'>'
	.($pwField ? '' : '<div class="ep-inline" id="ep-pw-set"><span class="ep-dot"></span><span>'.$e($pwSaid).'</span>'.($ro ? '' : '<button type="button" class="ep-lnk" id="ep-pw-change">'.$e(_('Change')).'</button>').'</div>')
	.'<div class="ep-inline" id="ep-pw-new"'.($pwField ? '' : ' hidden').'><input type="password" id="es_password" name="es_password" value="" placeholder="'.$e(_('New password')).'" autocomplete="new-password" aria-label="'.$e(_('New password')).'">'
	.($pwField ? '' : '<button type="button" class="ep-lnk" id="ep-pw-keep">'.$e(_('Keep current')).'</button>').'</div>'
	.'<input type="hidden" id="es_password_action" name="es_password_action" value="'.($pwField ? 'set' : 'keep').'"></div>'
	.'<input type="text" id="es_password_path" name="es_password_path" value="'.$e($field('es_password_path')).'" placeholder="secret/elasticpro/example:password" aria-label="'.$e(_('Vault path and key')).'"'.($mode === 'vault' ? '' : ' hidden').'>');
$html[] = '</div>';
$html[] = '<div class="ep-hint ep-saveline" id="ep-pw-hint">'.$e($mode === 'default' ? _('Saving uses the default credential; the cluster host keeps no password of its own.')
	: ($mode === 'vault' ? _('Saving writes the Vault reference on the cluster host; the core reads it from Vault.') : _('A password typed here goes to a Zabbix secret on the cluster host and to ElasticPro, once; editing anything else never touches it.'))).'</div>';
$html[] = $row(_('Route'), '<div class="ep-seg" role="radiogroup" aria-label="'.$e(_('Route')).'">'
	.'<input type="radio" name="ep_route" id="ep_route_direct" value="direct"'.($route === 'direct' ? ' checked' : '').'><label for="ep_route_direct">'.$e(_('Direct')).'</label>'
	.'<input type="radio" name="ep_route" id="ep_route_jump" value="jump"'.($route === 'jump' ? ' checked' : '').'><label for="ep_route_jump">'.$e(_('Jump host')).'</label></div>'
	.'<span class="ep-inline" id="ep-jump-addr"'.($route === 'jump' ? '' : ' hidden').'>'.$text('jump_host', 'jump-host', 'aria-label="'.$e(_('Jump host')).'"').'<span class="ep-hint">:</span>'
	.'<input type="text" id="jump_port" name="jump_port" value="'.$e($field('jump_port')).'" placeholder="22" class="ep-num" aria-label="'.$e(_('SSH port')).'"></span>');
$html[] = '<div id="ep-jump"'.($route === 'jump' ? '' : ' hidden').'>';
$html[] = $row(_('SSH user'), $text('jump_user', 'elasticpro'), 'jump_user', $e(_('Signs in with the Zabbix server\'s key; add its public key to this user\'s authorized_keys.')));
$html[] = $row(_('SSH key file'), $text('jump_key', 'id_ed25519'), 'jump_key', $e(_('In the Zabbix server\'s key folder, with its .pub beside it.')));
$html[] = $row(_('ES API key'), $text('es_apikey_path', 'secret/elasticpro/example:apikey'), 'es_apikey_path',
	$e(_('Vault path:key of a read-only API key. Through a jump host the URL is the address as the jump host sees it.')));
$tlsOpt = ['verify' => _('Verify (Windows trust store)'), 'ca' => _('Verify against a CA file on the jump host'), 'none' => _('Do not verify (not recommended)')];
$tls = '<select id="jump_tls" name="jump_tls">';
foreach ($tlsOpt as $v => $label) {
	$tls .= '<option value="'.$v.'"'.(($field('jump_tls') ?: 'verify') === $v ? ' selected' : '').'>'.$e($label).'</option>';
}
$html[] = $row(_('Verify TLS'), $tls.'</select>', 'jump_tls', $e(_('The API key goes to whatever answers at the URL; verifying makes sure that is the cluster.')));
$html[] = '<div id="ep-jump-ca"'.($field('jump_tls') === 'ca' ? '' : ' hidden').'>'.$row(_('CA file'), $text('jump_ca', 'C:\\certs\\es-ca.pem'), 'jump_ca').'</div>';
$html[] = '</div>';
$html[] = '<div id="ep-proxy-row"'.($route === 'jump' ? ' hidden' : '').'>'.$row(_('Zabbix proxy'), $proxyOpts('ep_es_proxy', $route === 'jump' ? '' : $field('monitored_by')), 'ep_es_proxy',
	$e(_('Monitors the ES cluster host only. The Linux hosts have their own proxy under Servers & roles.'))).'</div>';
$html[] = '<input type="hidden" name="monitored_by" id="monitored_by" value="'.$e($field('monitored_by')).'">';
if ($editing && !$ro) {
	$html[] = $row(_('Test'), '<button type="button" class="ep-lnk" id="ep-test" data-csrf="'.$e(CCsrfTokenHelper::get('ep.clients.test')).'">'.$e(_('Test now')).'</button><div id="ep-test-out" class="ep-test"></div>');
}
$html[] = '</section>';
$html[] = '</div>';

/* ---- Servers & roles ---- */
$html[] = '<section class="ep-card" id="ep-card-servers" aria-labelledby="h-srv"><div class="ep-srv-head"><h2 class="ep-ttl" id="h-srv">'.$e(_('Servers & roles')).' <span class="ep-count" id="ep-server-count"></span></h2>'
	.'<span class="ep-grow"></span><label for="servers_monitored_by">'.$e(_('Linux hosts monitored by')).'</label>'
	.str_replace('<select ', '<select aria-describedby="ep-tip-srv" ', $proxyOpts('servers_monitored_by', $field('servers_monitored_by'), true))
	.'<span class="ep-tipw"><span class="ep-i" tabindex="0" role="img" aria-label="'.$e(_('Details')).'">?</span><span class="ep-tip" role="tooltip" id="ep-tip-srv">'.$e(_('Sets the proxy on every Linux host of this client at once. The ES cluster host has its own, under Connection. The Zabbix host name is the IP; the visible name is the server name. Roles: ES, Parser, Forwarder, Engine, AIML, Trino (ULM), SOAR.')).'</span></span></div>';
$html[] = '<div class="ep-srv-cols" aria-hidden="true"><span>'.$e(_('IP')).'</span><span>'.$e(_('Server name')).'</span><span>'.$e(_('Roles')).'</span><span>'.$e(_('Remove')).'</span></div>';
$html[] = '<div id="ep-server-list"></div>';
$html[] = '<div class="ep-sheet" id="ep-sheet" hidden>'
	.'<div class="ep-sheet-row ep-ip-row"><div class="ep-ip-wrap"><input type="text" id="ep-s-ip" class="ep-mono" placeholder="10.0.0.21 or a host name" aria-label="'.$e(_('Server IP')).'" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="ep-s-suggest">'
	.'<div id="ep-s-suggest" class="ep-suggest" role="listbox" hidden></div></div>'
	.'<span class="ep-hint">'.$e(_('Type an IP or a host name to pick a host already in Zabbix, or a new IP to create one. Paste several IPs to give them all the same roles.')).'</span></div>'
	.'<div id="ep-chips"></div>'
	.'<div class="ep-sheet-row"><input type="text" id="ep-s-name" placeholder="'.$e(_('Server name, e.g. es-hot-01')).'" aria-label="'.$e(_('Server name')).'" maxlength="128">'
	.'<span class="ep-hint">'.$e(_('The host\'s visible name in Zabbix. Its host name is the IP, and never changes.')).'</span></div>'
	.'<div class="ep-sheet-row"><input type="text" id="ep-s-svc" placeholder="nginx" aria-label="'.$e(_('Other services')).'">'
	.'<span class="ep-hint">'.$e(_('Other services, optional. Every role is a service already.')).'</span></div>'
	.'<div class="ep-sheet-row"><input type="text" id="ep-s-notes" placeholder="'.$e(_('Notes, optional — rack, owner, anything to remember')).'" aria-label="'.$e(_('Notes')).'"></div>'
	.'<div class="ep-sheet-foot"><span id="ep-s-msg" class="ep-hint"></span>'
	.'<button type="button" class="btn-alt" id="ep-s-cancel">'.$e(_('Cancel')).'</button>'
	.'<button type="button" id="ep-s-add" disabled>'.$e(_('Add server')).'</button></div></div>';
$html[] = '<div class="ep-group-foot"><button type="button" class="ep-lnk" id="ep-add-server">'.$e(_('+ Add server')).'</button>'
	.'<span class="ep-grow"></span><label for="agent_port" class="ep-hint">'.$e(_('Agent port')).'</label> '.$text('agent_port', '10050', 'class="ep-num"').'</div>';
$html[] = '</section>';

$html[] = '<div class="ep-two">';
/* ---- Capacity ---- */
$html[] = '<section class="ep-card" id="ep-card-capacity" aria-labelledby="h-cap"><h2 class="ep-ttl" id="h-cap">'.$e(_('Capacity')).'</h2>';
$html[] = $row(_('Purchased').' *', '<div class="ep-inline">'.$seg('purchased_by', ['storage' => _('Storage'), 'devices' => _('Devices')], $field('purchased_by') ?: 'storage')
	.'<input type="text" inputmode="decimal" class="ep-num" id="purchased" name="purchased" value="'.$e($num('purchased')).'" placeholder="0" aria-label="'.$e(_('Purchased storage')).'">'
	.'<input type="text" inputmode="numeric" class="ep-num" id="purchased_devices" name="purchased_devices" value="'.$e($num('purchased_devices')).'" placeholder="0" aria-label="'.$e(_('Purchased devices')).'">'
	.'<span class="ep-inline-hint" id="ep-purchased-unit">GB</span></div>');
$html[] = $row(_('Retention'), '<div class="ep-inline"><input type="text" inputmode="numeric" class="ep-num" id="retention_days" name="retention_days" value="'.$e($field('retention_days')).'" placeholder="'.$e(_('not set')).'"><span class="ep-hint">'.$e(_('days')).'</span></div>', 'retention_days');
$html[] = $row(_('Backup'), '<div class="ep-inline"><input type="text" inputmode="decimal" class="ep-num" id="backup_gb" name="backup_gb" value="'.$e($field('backup_gb')).'" placeholder="'.$e(_('not set')).'"><span class="ep-hint">GB</span></div>', 'backup_gb',
	$e(_('Empty means not set; reports say not set, never 0.')));
// Requested capacity per role: kept from the full form, folded under Capacity.
$req = '<div class="ep-table-wrap"><table class="ep-req"><thead><tr><th>'.$e(_('Role')).'</th><th>'.$e(_('Servers')).'</th><th>'.$e(_('CPU cores')).'</th>'
	.'<th>'.$e(_('Memory GB')).'</th><th class="ep-disks-h">'.$e(_('Disks, GB')).'</th></tr></thead><tbody>';
foreach ($data['roles']['families'] as $fam) {
	foreach ($fam['roles'] as $r) {
		$in = fn(string $suffix, string $ph = '') => '<input type="text" inputmode="decimal" data-ep-num name="'.$e($r['id'].'_'.$suffix).'" value="'
			.$e($num($r['id'].'_'.$suffix)).'" placeholder="'.$e($ph).'" aria-label="'.$e($r['label'].' '.$suffix).'">';
		$disks = '<div class="ep-disk"><span class="ep-mono ep-mount">/</span>'.$in('disk', '—').'</div>';
		for ($n = 2; $n <= \Modules\EpClients\Lib\Roles::DISK_SLOTS; $n++) {
			$fs = $r['id'].'_disk'.$n.'_fs';
			$disks .= '<div class="ep-disk" data-ep-slot><input type="text" class="ep-mono ep-mount-in" name="'.$e($fs).'" value="'.$e($field($fs)).'" placeholder="/data" aria-label="'
				.$e(_s('%1$s disk %2$s mount', $r['label'], $n)).'">'.$in('disk'.$n, '—')
				.'<button type="button" class="ep-icon" data-ep-disk-remove aria-label="'.$e(_s('Remove %1$s disk %2$s', $r['label'], $n)).'">×</button></div>';
		}
		$disks .= '<button type="button" class="ep-link ep-add-disk" data-ep-disk-add>'.$e(_('+ disk')).'</button>';
		$req .= '<tr data-ep-role="'.$e($r['id']).'"><td><span class="ep-req-role">'.$e($r['label']).'</span> <span class="ep-built"></span></td>'
			.'<td>'.$in('servers', '—').'</td><td>'.$in('cpu', '—').'</td><td>'.$in('mem', '—').'</td><td class="ep-disks">'.$disks.'</td></tr>';
	}
}
$req .= '</tbody></table></div><div class="ep-group-foot"><span class="ep-hint" id="ep-req-empty">'.$e(_('Rows appear for the roles your servers have.')).'</span> <select id="ep-request-role" aria-label="'.$e(_('Request another role')).'"></select></div>';
$html[] = '<details class="ep-reqd"><summary>'.$e(_('Requested per role (servers, CPU, memory, disks)')).'</summary>'.$req.'</details>';
$html[] = '</section>';

/* ---- Log archive ---- */
$html[] = '<section class="ep-card" id="ep-archive" aria-labelledby="h-arch"><h2 class="ep-ttl" id="h-arch">'.$e(_('Log archive (S3)')).'</h2>';
$html[] = '<div class="ep-inline" id="ep-archive-open-row"><span class="ep-hint" id="ep-archive-note">'.$e(_('Not needed for On-Prem.')).'</span><button type="button" class="ep-lnk ep-open" id="ep-archive-open">'.$e(_('Add one anyway')).'</button></div>';
$html[] = '<div class="ep-group" id="ep-archive-body" hidden>';
$html[] = $row(_('Bucket'), $text('ulm_bucket', 'example-archive'), 'ulm_bucket');
$html[] = $row(_('Region'), '<div class="ep-inline">'.$text('ulm_region', 'us-east-1').$seg('ulm_auth', ['role_base' => _('Server role'), 'access_key' => _('Access key')], $field('ulm_auth') ?: 'role_base').'</div>', 'ulm_region');
$html[] = '<div id="ep-archive-more" hidden>';
$html[] = $row(_('Role ARN'), $text('ulm_role_arn', 'arn:aws:iam::111122223333:role/example-archive'), 'ulm_role_arn');
$html[] = $row(_('External ID'), $text('ulm_external_id'), 'ulm_external_id');
$html[] = $row(_('Access key ID'), $text('ulm_access_key_id', _('access key only')), 'ulm_access_key_id');
$html[] = $row(_('Secret (Vault)'), $text('ulm_secret_path', 'secret/elasticpro/example-s3:secret_access_key'), 'ulm_secret_path');
$html[] = $row(_('Folders'), '<div class="ep-inline">'.$text('ulm_raw_prefix', 'rawlog', 'aria-label="'.$e(_('Raw folder')).'"')
	.$text('ulm_enriched_prefix', 'enrichedlog', 'aria-label="'.$e(_('Enriched folder')).'"').'</div>');
$html[] = '</div>';
$html[] = '<button type="button" class="ep-more" data-ep-disclose="ep-archive-more" data-more="'.$e(_('Role ARN, keys, folders…')).'" data-less="'.$e(_('Fewer options')).'" aria-expanded="false">'
	.$e(_('Role ARN, keys, folders…')).'</button>';
$html[] = '</div></section>';
$html[] = '</div>';

/* ---- the action bar ---- */
$html[] = '<div class="ep-foot"><span class="ep-hint" id="ep-dirty">'.$e(_('No changes · a backup is taken before saving')).'</span><span class="ep-grow"></span>'
	.'<a class="btn-alt ep-btn" href="'.$e($url('ep.clients.list')).'">'.$e(_('Cancel')).'</a>'
	.($ro ? '' : '<button type="submit">'.$e($editing ? _('Save') : _('Add client')).'</button>').'</div>';
$html[] = '</div>';
$html[] = ($ro ? '</fieldset>' : '').'</form>';
// The Maintenance and Delete dialogs the header's buttons open, outside the form: a dialog of its own, posting for itself.
if ($editing && !$ro) {
	$html[] = \Modules\EpClients\Lib\ClientActions::dialogs(true);
}
$html[] = '</div>';

$byIp = [];
foreach ((array) json_decode($field('servers'), true) as $s) {
	$byIp[] = $s;
}
$js = [
	'roles' => $data['roles'],
	'servers' => $byIp,
	'names' => (object) $data['host_names'],
	'techNames' => (object) ($data['tech_names'] ?? []),
	'client' => $field('name'),
	'openForm' => (bool) ($data['errors'] || $data['deletes'] || $data['done']),
	'testUrl' => $url('ep.clients.test'),
	'current' => $data['current'],
	'unassigned' => array_values(array_map(function ($u) use ($data) {
		// A role to start from: the first of the family the host is in (an ES node → ES Data).
		$fam = array_values(array_filter($data['roles']['families'], fn($f) => in_array($f['id'], $u['families'], true)))[0] ?? null;
		return ['name' => $u['name'], 'ip' => $u['ip'], 'suggest' => $fam ? [$fam['roles'][0]['id']] : []];
	}, array_filter($data['unassigned'], fn($u) => $u['ip'] !== null))),
	'text' => [
		'diHint' => _('Log archive required'), 'opHint' => _('Log archive optional'), 'devices' => _('devices'),
		'pwVault' => _('Saving writes the Vault reference on the cluster host; the core reads it from Vault. Empty: a new cluster host reads secret/elasticpro/<client>:password.'),
		'pwZabbix' => _('A password typed here goes to a Zabbix secret on the cluster host and to ElasticPro, once; editing anything else never touches it.'),
		'pwDefault' => _('Saving uses the default credential; the cluster host keeps no password of its own.'),
		'notOnPrem' => _('Not needed for On-Prem.'), 'unsaved' => _('Unsaved changes'),
		'kafka' => _('kafka: confirm Forwarder or Parser'), 'confirm' => _('Confirm'),
		'noServers' => _('No servers yet.'), 'oneServer' => _('1 server'), 'nServers' => _('%n servers'),
		'shared' => _('shared'), 'single' => _('single node'), 'allInOne' => _('all-in-one'), 'edit' => _('Edit'), 'remove' => _('Remove'),
		'badIp' => _('"%s" is not an IPv4 address.'), 'dupIp' => _('%s is already a server. Edit it to change its roles.'),
		'pickRoles' => _('Pick one or more roles.'), 'named' => _('Host name %ip, server name %name.'), 'manyNamed' => _('%n servers: each one\'s host name is its IP.'),
		'noServerName' => _('server name unknown'), 'hostName' => _('host name %h'),
		'add' => _('Add server'), 'addMany' => _('Add %n servers'), 'update' => _('Update server'),
		'built' => _('%n built'), 'requestRole' => _('+ Request another role'),
		'willDelete' => _('will be deleted on save — its history too'), 'undo' => _('Undo'), 'noRole' => _('no role yet — left as it is'),
		'giveRole' => _('Give a role'),
		'takenBy' => _('%s is already a server of client %c. An IP belongs to one client only.'),
		'takeOn' => _('In Zabbix as "%h" — it is taken on, with its history; its host name stays.'),
		'serverOf' => _('server of %c'), 'inZabbix' => _('in Zabbix'), 'noMatch' => _('Not in Zabbix — it will be created.'),
		'notMonitored' => _('not monitored')
	]
];

(new CHtmlPage())
	->setTitle($editing ? _s('Client %1$s', $field('name')) : _('Add client'))
	->addItem(new CObject(implode("\n", $html)))
	->show();
?>
<script><?php readfile(__DIR__.'/js/ep.clients.dialogs.js'); ?></script>
<script><?php readfile(__DIR__.'/js/ep.clients.edit.js'); ?></script>
<script>
	window.EpClientForm.init(document.getElementById('ep-client-form'), <?= json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>);
	window.EpDialogs.init(document.querySelector('.ep-edit'));
</script>
<script>
	(function () {
		// Test connection: ask Zabbix to run the checks now, then read what they found.
		const btn = document.getElementById('ep-test'), out = document.getElementById('ep-test-out');
		if (!btn) return;
		const client = document.getElementById('name').value;
		// POST: Zabbix checks a CSRF token only on POST, and "start" asks the server to run checks.
		const call = (op, extra) => fetch('zabbix.php?action=ep.clients.test', { method: 'POST', credentials: 'same-origin',
			body: new URLSearchParams(Object.assign({ op, client, '<?= CSRF_TOKEN_NAME ?>': btn.dataset.csrf }, extra || {})) }).then((r) => r.json());
		function draw(checks, pending) {
			out.textContent = '';
			if (!checks.length) { out.textContent = 'Nothing to test yet: save the client first.'; return; }
			checks.forEach((c) => {
				const d = document.createElement('div');
				d.className = 'ep-test-row ' + (c.done === false ? 'wait' : c.ok ? 'ok' : 'bad');
				d.textContent = (c.done === false ? '… ' : c.ok ? '✓ ' : '✗ ') + c.label + ' — ' + c.host + (c.said ? ': ' + c.said : '');
				out.appendChild(d);
			});
			if (pending) { const p = document.createElement('div'); p.className = 'ep-hint'; p.textContent = 'Waiting for Zabbix to run them…'; out.appendChild(p); }
		}
		btn.addEventListener('click', () => {
			btn.disabled = true;
			call('start').then((s) => {
				draw(s.checks.map((c) => Object.assign({ done: false }, c)), true);
				let tries = 0;
				const poll = () => call('read', { since: s.since }).then((r) => {
					const pending = r.checks.some((c) => !c.done) && ++tries < 15;
					draw(r.checks, pending);
					if (pending) setTimeout(poll, 2000); else btn.disabled = false;
				}).catch(() => { btn.disabled = false; });
				setTimeout(poll, 2500);
			}).catch(() => { out.textContent = 'The test could not start.'; btn.disabled = false; });
		});
	})();
</script>
<style><?php readfile(__DIR__.'/css/ep.hint.css'); readfile(__DIR__.'/css/ep.clients.dialogs.css'); readfile(__DIR__.'/css/ep.clients.edit.css'); ?></style>
