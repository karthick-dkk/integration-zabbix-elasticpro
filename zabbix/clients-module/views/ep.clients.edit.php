<?php declare(strict_types = 0);
/**
 * Add or edit a client: a header with the client's status and its actions (an existing client
 * only — ClientActions, what the list rows used to carry), then cards in a grid — Client,
 * Elasticsearch, Log archive, Servers, More — and the requested capacity across the full width.
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
	$html[] = '<div class="ep-notes"><div class="ep-note">'.$e(_('Read only: only Super admins change clients.')).'</div></div>';
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
	$html[] = '<div class="ep-note ep-good">'.$e(_('This client has hosts already. They are kept — history, passwords — renamed to the client\'s pattern, and only what is missing is added.')).'</div>';
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
$html[] = '<div class="ep-grid">';

/* ---- Client ---- */
$html[] = '<div class="ep-card"><div class="ep-card-title">'.$e(_('Client')).'</div><div class="ep-group">';
$html[] = $row(_('Name'), $text('name', 'example', $editing ? 'readonly' : 'required'), 'name',
	$editing ? '' : $e(_('Names its host group and hosts: example-Master, example-ES-Data-Hot-1 …')));
// The types the site has, and its default, from ElasticPro -> Settings. A client already
// carrying a type since taken off that list keeps it: ClientSpec::types() still accepts it, and
// $seg marks whichever value is current.
$epTypes = \Modules\EpClients\Lib\Settings::types();
$epTypeNow = $field('type') !== '' ? $field('type') : $epTypes['default'];
$epTypeList = in_array($epTypeNow, $epTypes['all'], true) ? $epTypes['all'] : array_merge($epTypes['all'], [$epTypeNow]);
$html[] = $row(_('Type'), $seg('type', array_combine($epTypeList, $epTypeList), $epTypeNow)
	.' <span class="ep-inline-hint" id="ep-type-hint"></span>');
$html[] = $row(_('Purchased'), '<div class="ep-inline">'.$seg('purchased_by', ['storage' => _('Storage'), 'devices' => _('Devices')], $field('purchased_by') ?: 'storage')
	.'<input type="text" inputmode="decimal" class="ep-num" id="purchased" name="purchased" value="'.$e($num('purchased')).'" placeholder="0">'
	.'<input type="text" inputmode="numeric" class="ep-num" id="purchased_devices" name="purchased_devices" value="'.$e($num('purchased_devices')).'" placeholder="0">'
	.'<span class="ep-inline-hint" id="ep-purchased-unit">GB</span></div>');
$html[] = $row(_('SOC / Manager lead'), $text('lead_name', _('Name')), 'lead_name');
$html[] = $row(_('Cluster DL'), $text('cluster_dl', 'soc-example@company.com'), 'cluster_dl', $e(_('The client\'s mailing list.')));
$html[] = $row(_('Alerts'), '<label class="ep-check"><input type="checkbox" name="alert_dl" value="1"'.($field('alert_dl') === '1' ? ' checked' : '').'> '
	.$e(_('Email this client\'s problems (warning and above) to the cluster DL')).'</label>', 'alert_dl',
	$e(_('Only this client\'s problems; none during a maintenance. Zabbix needs an active Email media type.')));
$html[] = $row(_('Weekly report'), '<label class="ep-check"><input type="checkbox" name="weekly_report" value="1"'.($field('weekly_report') === '1' ? ' checked' : '').'> '
	.$e(_('Mail a PDF of the client\'s resources, capacity and volume to the cluster DL every Monday')).'</label>', 'weekly_report',
	$e(_('A Zabbix scheduled report of the dashboard "ElasticPro: <client>", for the week before.')));
$html[] = $row(_('Contract ends'), '<input type="date" id="contract_end" name="contract_end" value="'.$e($field('contract_end')).'" class="ep-date">', 'contract_end',
	$e(_('Cluster Management flags a contract ending within 30 days.')));
$html[] = '</div></div>';

/* ---- Elasticsearch ---- */
$monitor = '<select id="monitored_by" name="monitored_by"><option value="">'.$e(_('Zabbix server')).'</option>'
	.'<option value="jump"'.($field('monitored_by') === 'jump' ? ' selected' : '').'>'.$e(_('Windows jump host (SSH), no proxy')).'</option>';
foreach ($data['proxies'] as $p) {
	$monitor .= '<option value="'.$e('proxy:'.$p).'"'.($field('monitored_by') === 'proxy:'.$p ? ' selected' : '').'>'.$e(_s('Proxy: %1$s', $p)).'</option>';
}
foreach ($data['proxy_groups'] as $g) {
	$monitor .= '<option value="'.$e('group:'.$g).'"'.($field('monitored_by') === 'group:'.$g ? ' selected' : '').'>'.$e(_s('Proxy group: %1$s', $g)).'</option>';
}
if ($field('monitored_by') !== '' && $field('monitored_by') !== 'jump' && !in_array($field('monitored_by'), array_merge(array_map(fn($p) => 'proxy:'.$p, $data['proxies']), array_map(fn($g) => 'group:'.$g, $data['proxy_groups'])), true)) {
	$monitor .= '<option value="'.$e($field('monitored_by')).'" selected>'.$e($field('monitored_by').' — '._('not in Zabbix')).'</option>';
}
$monitor .= '</select>';
$html[] = '<div class="ep-card"><div class="ep-card-title">'.$e(_('Elasticsearch')).'</div><div class="ep-group">';
$html[] = $row(_('URL'), $text('es_url', 'https://es.example.local:9200'), 'es_url');
$html[] = $row(_('User'), $text('es_user', 'elastic'), 'es_user');
$html[] = $row(_('Password'), $seg('es_password_mode', ['vault' => 'Vault', 'zabbix' => _('Zabbix secret')], $field('es_password_mode') ?: 'vault')
	.'<input type="text" id="es_password_path" name="es_password_path" value="'.$e($field('es_password_path')).'" placeholder="secret/elasticpro/example:password">'
	.'<input type="password" id="es_password" name="es_password" value="" placeholder="'.$e($data['has_cluster'] ? _('Unchanged — type to replace') : _('Password')).'" autocomplete="new-password">'
	.'<div class="ep-hint" id="ep-pw-hint"></div>');
$html[] = $row(_('Monitored by'), $monitor, 'monitored_by', $e(_('A proxy or proxy group monitors every host of the client. Through a Windows jump host, the Zabbix server signs in over SSH and asks Elasticsearch with curl.exe; no proxy is needed.')));
$html[] = '<div id="ep-jump" hidden>';
$html[] = $row(_('Jump host'), '<div class="ep-inline">'.$text('jump_host', 'jump-windows.example.internal').'<span class="ep-inline-hint">:</span>'
	.'<input type="text" id="jump_port" name="jump_port" value="'.$e($field('jump_port')).'" placeholder="22" class="ep-num" aria-label="'.$e(_('SSH port')).'"></div>', 'jump_host',
	$e(_('Address and SSH port, as the Zabbix server reaches it. Windows 10 1803+ or Server 2019+ with OpenSSH and curl.exe.')));
$html[] = $row(_('SSH user'), $text('jump_user', 'elasticpro'), 'jump_user', $e(_('Signs in with the Zabbix server\'s key; add its public key to this user\'s authorized_keys.')));
$html[] = $row(_('SSH key file'), $text('jump_key', 'id_ed25519'), 'jump_key', $e(_('In the Zabbix server\'s key folder, with its .pub beside it.')));
$tlsOpt = ['verify' => _('Check it (Windows trust store)'), 'ca' => _('Check it against a CA file on the jump host'), 'none' => _('Do not check (self-signed, not recommended)')];
$tls = '<select id="jump_tls" name="jump_tls">';
foreach ($tlsOpt as $v => $label) {
	$tls .= '<option value="'.$v.'"'.(($field('jump_tls') ?: 'verify') === $v ? ' selected' : '').'>'.$e($label).'</option>';
}
$html[] = $row(_('ES certificate'), $tls.'</select>', 'jump_tls',
	$e(_('The API key goes to whatever answers at the ES URL; checking the certificate makes sure that is the cluster.')));
$html[] = '<div id="ep-jump-ca"'.($field('jump_tls') === 'ca' ? '' : ' hidden').'>'.$row(_('CA file'), $text('jump_ca', 'C:\\certs\\es-ca.pem'), 'jump_ca',
	$e(_('Path on the jump host to the CA that signed the cluster\'s certificate (PEM).'))).'</div>';
$html[] = $row(_('ES API key'), $text('es_apikey_path', 'secret/elasticpro/example:apikey'), 'es_apikey_path',
	$e(_('Vault path:key of a read-only API key (monitor, read_ilm, read_slm; read on the device index). The ES URL above is the address as the jump host sees it. The log archive check cannot run through a jump host yet.')));
$html[] = '</div>';
if ($data['mode'] === 'edit' && !$ro) {
	$html[] = '<div class="ep-row"><label>'.$e(_('Test connection')).'</label><div class="ep-ctl"><button type="button" class="btn-alt" id="ep-test" data-csrf="'.$e(CCsrfTokenHelper::get('ep.clients.test')).'">'.$e(_('Test now')).'</button>'
		.'<div id="ep-test-out" class="ep-test"></div></div></div>';
}
$html[] = '</div></div>';

$html[] = '<div class="ep-stack">';

/* ---- Log archive ---- */
$html[] = '<div class="ep-card" id="ep-archive"><div class="ep-card-title">'.$e(_('Log archive (S3)')).'</div>';
$html[] = '<button type="button" class="ep-link ep-open" id="ep-archive-open">'.$e(_('+ Add log archive')).'</button>';
$html[] = '<div class="ep-group" id="ep-archive-body" hidden>';
$html[] = $row(_('Bucket'), $text('ulm_bucket', 'example-archive'), 'ulm_bucket');
$html[] = $row(_('Region'), $text('ulm_region', 'ap-south-1'), 'ulm_region');
$html[] = $row(_('Access'), $seg('ulm_auth', ['role_base' => _('Zabbix server role'), 'access_key' => _('Access key')], $field('ulm_auth') ?: 'role_base'));
$html[] = '<div id="ep-archive-more" hidden>';
$html[] = $row(_('Role ARN'), $text('ulm_role_arn', 'arn:aws:iam::111122223333:role/example-archive'), 'ulm_role_arn', $e(_('Optional, per bucket: assumed from the access above.')));
$html[] = $row(_('External ID'), $text('ulm_external_id'), 'ulm_external_id');
$html[] = $row(_('Access key ID'), $text('ulm_access_key_id', _('access key only')), 'ulm_access_key_id');
$html[] = $row(_('Secret (Vault)'), $text('ulm_secret_path', 'secret/elasticpro/example-s3:secret_access_key'), 'ulm_secret_path');
$html[] = $row(_('Folders'), '<div class="ep-inline">'.$text('ulm_raw_prefix', 'rawlog', 'aria-label="'.$e(_('Raw folder')).'"')
	.$text('ulm_enriched_prefix', 'enrichedlog', 'aria-label="'.$e(_('Enriched folder')).'"').'</div>', '', $e(_('Raw and enriched copies, in the same bucket.')));
$html[] = '</div>';
$html[] = '<button type="button" class="ep-more" data-ep-disclose="ep-archive-more" data-more="'.$e(_('Role ARN, keys, folders…')).'" data-less="'.$e(_('Fewer options')).'" aria-expanded="false">'
	.$e(_('Role ARN, keys, folders…')).'</button>';
$html[] = '</div></div>';

/* ---- Servers ---- */
$html[] = '<div class="ep-card"><div class="ep-card-title">'.$e(_('Servers')).' <span class="ep-count" id="ep-server-count"></span></div><div class="ep-group">';
$html[] = '<div id="ep-server-list"></div>';
$html[] = '<div class="ep-sheet" id="ep-sheet" hidden>'
	.'<div class="ep-sheet-row ep-ip-row"><div class="ep-ip-wrap"><input type="text" id="ep-s-ip" class="ep-mono" placeholder="10.0.0.21 or a host name" aria-label="'.$e(_('Server IP')).'" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="ep-s-suggest">'
	.'<div id="ep-s-suggest" class="ep-suggest" role="listbox" hidden></div></div>'
	.'<span class="ep-hint">'.$e(_('Type an IP or a host name to pick a host already in Zabbix, or a new IP to create one. Paste several IPs to give them all the same roles.')).'</span></div>'
	.'<div id="ep-chips"></div>'
	.'<div class="ep-sheet-row"><input type="text" id="ep-s-svc" placeholder="nginx, kafka" aria-label="'.$e(_('Other services')).'">'
	.'<span class="ep-hint">'.$e(_('Other services, optional. Every role is a service already.')).'</span></div>'
	.'<div class="ep-sheet-row"><input type="text" id="ep-s-notes" placeholder="'.$e(_('Notes, optional — rack, owner, anything to remember')).'" aria-label="'.$e(_('Notes')).'"></div>'
	.'<div class="ep-sheet-foot"><span id="ep-s-msg" class="ep-hint"></span>'
	.'<button type="button" class="btn-alt" id="ep-s-cancel">'.$e(_('Cancel')).'</button>'
	.'<button type="button" id="ep-s-add" disabled>'.$e(_('Add server')).'</button></div></div>';
$html[] = '<div class="ep-group-foot"><button type="button" class="ep-link" id="ep-add-server">'.$e(_('+ Add server')).'</button></div>';
$html[] = '</div></div>';

/* ---- More ---- */
$html[] = '<div class="ep-card"><div class="ep-card-title">'.$e(_('More')).'</div><div class="ep-group">';
$html[] = $row(_('Agent port'), $text('agent_port', '10050', 'class="ep-num"'), 'agent_port');
$html[] = '</div></div>';
$html[] = '</div></div>';

/* ---- Requested capacity ---- */
$html[] = '<div class="ep-card ep-wide"><div class="ep-card-title">'.$e(_('Requested capacity')).' <span class="ep-count">'.$e(_('empty = not set')).'</span></div><div class="ep-group">';
$html[] = '<div class="ep-table-wrap"><table class="ep-req"><thead><tr><th>'.$e(_('Role')).'</th><th>'.$e(_('Servers')).'</th><th>'.$e(_('CPU cores')).'</th>'
	.'<th>'.$e(_('Memory GB')).'</th><th class="ep-disks-h">'.$e(_('Disks, GB')).'</th></tr></thead><tbody>';
foreach ($data['roles']['families'] as $fam) {
	foreach ($fam['roles'] as $r) {
		$in = fn(string $suffix, string $ph = '') => '<input type="text" inputmode="decimal" data-ep-num name="'.$e($r['id'].'_'.$suffix).'" value="'
			.$e($num($r['id'].'_'.$suffix)).'" placeholder="'.$e($ph).'" aria-label="'.$e($r['label'].' '.$suffix).'">';
		// / always; up to four more disks, each a mount and a size, shown once one is added.
		$disks = '<div class="ep-disk"><span class="ep-mono ep-mount">/</span>'.$in('disk', '—').'</div>';
		for ($n = 2; $n <= \Modules\EpClients\Lib\Roles::DISK_SLOTS; $n++) {
			$fs = $r['id'].'_disk'.$n.'_fs';
			$disks .= '<div class="ep-disk" data-ep-slot><input type="text" class="ep-mono ep-mount-in" name="'.$e($fs).'" value="'.$e($field($fs)).'" placeholder="/data" aria-label="'
				.$e(_s('%1$s disk %2$s mount', $r['label'], $n)).'">'.$in('disk'.$n, '—')
				.'<button type="button" class="ep-icon" data-ep-disk-remove aria-label="'.$e(_s('Remove %1$s disk %2$s', $r['label'], $n)).'">×</button></div>';
		}
		$disks .= '<button type="button" class="ep-link ep-add-disk" data-ep-disk-add>'.$e(_('+ disk')).'</button>';
		$html[] = '<tr data-ep-role="'.$e($r['id']).'"><td><span class="ep-req-role">'.$e($r['label']).'</span> <span class="ep-built"></span></td>'
			.'<td>'.$in('servers', '—').'</td><td>'.$in('cpu', '—').'</td><td>'.$in('mem', '—').'</td><td class="ep-disks">'.$disks.'</td></tr>';
	}
}
$html[] = '</tbody></table></div>';
$html[] = '<div class="ep-group-foot"><span class="ep-hint" id="ep-req-empty">'.$e(_('Rows appear for the roles your servers have.')).'</span> <select id="ep-request-role" aria-label="'.$e(_('Request another role')).'"></select></div>';
$html[] = '</div></div>';

if ($data['existing']) {
	$items = '';
	foreach ($data['existing'] as $h) {
		$items .= '<li><span>'.$e($h['name']).'</span><span class="ep-mono">'.$e($h['ip'] ?? '').'</span><span>'.$e($h['role']).'</span><span class="ep-hint">'
			.$e($h['managed'] ? _('made here') : _('made by hand — kept')).'</span></li>';
	}
	$html[] = '<details class="ep-hosts"><summary>'.$e(_n('%1$s host in Zabbix now', '%1$s hosts in Zabbix now', count($data['existing']))).'</summary><ul>'.$items.'</ul></details>';
}

if ($data['history']) {
	$items = '';
	foreach ($data['history'] as $h) {
		$items .= '<li><span class="ep-mono">'.$e(date('d M Y H:i', $h['at'])).'</span><span>'.$e($h['what']).'</span><span class="ep-hint">'.$e($h['by'])
			.($h['backup'] ? ' · '._s('backup %1$s', $h['backup']) : '').'</span></li>';
	}
	$html[] = '<details class="ep-hosts"><summary>'.$e(_n('Activity: %1$s change', 'Activity: %1$s changes', count($data['history']))).'</summary><ul class="ep-history">'.$items.'</ul></details>';
}

$html[] = '<div class="ep-foot"><span class="ep-hint">'.$e(_('A backup of every client is taken before saving.')).'</span>'
	.'<a class="btn-alt ep-btn" href="'.$e($url('ep.clients.list')).'">'.$e(_('Cancel')).'</a>'
	.($ro ? '' : '<button type="submit">'.$e($editing ? _('Save') : _('Add client')).'</button>').'</div>';
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
	'client' => $field('name'),
	'testUrl' => $url('ep.clients.test'),
	'current' => $data['current'],
	'unassigned' => array_values(array_map(function ($u) use ($data) {
		// A role to start from: the first of the family the host is in (an ES node → ES Data).
		$fam = array_values(array_filter($data['roles']['families'], fn($f) => in_array($f['id'], $u['families'], true)))[0] ?? null;
		return ['name' => $u['name'], 'ip' => $u['ip'], 'suggest' => $fam ? [$fam['roles'][0]['id']] : []];
	}, array_filter($data['unassigned'], fn($u) => $u['ip'] !== null))),
	'text' => [
		'diHint' => _('Log archive required'), 'opHint' => _('Log archive optional'), 'devices' => _('devices'),
		'pwVault' => _('A Vault path:key. Empty: a new cluster host reads secret/elasticpro/<client>:password.'),
		'pwZabbix' => _('Kept as a secret macro on the cluster host; never shown again.'),
		'noServers' => _('No servers yet.'), 'oneServer' => _('1 server'), 'nServers' => _('%n servers'),
		'shared' => _('shared'), 'single' => _('single node'), 'allInOne' => _('all-in-one'), 'edit' => _('Edit'), 'remove' => _('Remove'),
		'badIp' => _('"%s" is not an IPv4 address.'), 'dupIp' => _('%s is already a server. Edit it to change its roles.'),
		'pickRoles' => _('Pick one or more roles.'), 'named' => _('Will be named'), 'manyNamed' => _('%n servers, the first named'),
		'add' => _('Add server'), 'addMany' => _('Add %n servers'), 'update' => _('Update server'),
		'built' => _('%n built'), 'requestRole' => _('+ Request another role'),
		'willDelete' => _('will be deleted on save — its history too'), 'undo' => _('Undo'), 'noRole' => _('no role yet — left as it is'),
		'giveRole' => _('Give a role'),
		'takenBy' => _('%s is already a server of client %c. An IP belongs to one client only.'),
		'takeOn' => _('In Zabbix as "%h" — it is taken on, with its history, and named'),
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
		// Monitored by: the jump host fields only for a jump host.
		const sel = document.getElementById('monitored_by'), box = document.getElementById('ep-jump');
		const paint = () => { box.hidden = sel.value !== 'jump'; };
		sel.addEventListener('change', paint); paint();
		const tls = document.getElementById('jump_tls'), ca = document.getElementById('ep-jump-ca');
		const paintTls = () => { ca.hidden = tls.value !== 'ca'; };
		tls.addEventListener('change', paintTls); paintTls();
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
