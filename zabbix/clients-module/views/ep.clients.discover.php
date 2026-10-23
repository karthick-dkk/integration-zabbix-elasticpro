<?php declare(strict_types = 0);
/**
 * Discover clusters (board Discover.dc.html, approved 2026-10-23): the rule, one table row per
 * Elasticsearch cluster Zabbix holds — tick, editable cluster name, host, host groups, templates
 * found with their generation, role hosts as "IP · server name", Ready or Missing N with reasons —
 * "show hosts that are already a client" off by default, and one summary box with the two proxy
 * choices and exactly what adopting the ticked rows writes. Nothing is written until "Adopt selected".
 *
 * Each row's own write list, the ES URL when it is not set, and an optional password are in the
 * row's details. Colours are tokens on .ep-disc (views/css/ep.clients.discover.css), light and dark.
 *
 * @var CView $this
 * @var array $data
 */

$url = fn(string $action, array $args = []) => array_reduce(array_keys($args), fn($u, $k) => $u->setArgument($k, $args[$k]),
	(new CUrl('zabbix.php'))->setArgument('action', $action))->getUrl();
$ul = function (array $items, string $class = '') {
	$l = (new CList())->addClass($class);
	foreach ($items as $i) {
		$l->addItem($i);
	}
	return $l;
};
$chip = fn(string $text, string $kind = '') => (new CSpan($text))->addClass('ep-chip')->addClass($kind !== '' ? 'ep-chip-'.$kind : '');
$roleLabel = [];
foreach ($data['roles']['families'] ?? [] as $f) {
	foreach ($f['roles'] as $r) {
		$roleLabel[$r['id']] = $r['label'];
	}
}
/** A Missing item's few words, for the Status column. The full sentence is in the row's details. */
$short = ['client_group' => _('no client group'), 'role_hosts' => _('no role hosts'), 'credential' => _('no credential'), 'password' => _('no password'),
	'password_core' => _('password not given to ElasticPro'), 'password_plain' => _('plain-text password'), 'credential_hidden' => _('default credential hidden by a template'),
	'jump' => _('jump host unknown'), 'old_generation' => _('old generation linked'), 'macros' => _('ES URL macros not set'), 'server_name' => _('server name unknown'),
	'proxy' => _('proxy not chosen'), 'role' => _('role unknown'), 'ip' => _('IP to sort out'), 'templates' => _('templates not visible'), 'linked' => _('templates not linked')];
$genChip = function (array $cluster) use ($chip) {
	switch ($cluster['generation']) {
		case 'current': return $chip(_('ElasticPro template'));
		case 'legacy': return $chip(_('older generation · kept beside'), 'old');
		case 'unrecognised': return $chip(_('name not known here · kept beside'), 'old');
		case 'none': return $chip(_('no cluster template yet'), 'old');
		default: return $chip(_('generation unknown'), 'old');
	}
};
$keep = \Modules\EpClients\Lib\Discovery::KEEP;
$proxyOptions = function (string $name) use ($data, $keep) {
	$sel = (new CTag('select', true))->setAttribute('name', $name)->setAttribute('data-ep-proxy', $name);
	$opts = ['server' => _('Zabbix server')];
	foreach ($data['scan']['proxies'] ?? [] as $p) {
		$opts['proxy:'.$p] = $p;
	}
	foreach ($data['scan']['proxy_groups'] ?? [] as $g) {
		$opts['group:'.$g] = _s('proxy group %1$s', $g);
	}
	foreach ($opts as $v => $label) {
		$sel->addItem((new CTag('option', true, $label))->setAttribute('value', (string) $v));
	}
	return $sel;
};

$page = (new CHtmlPage())->setTitle(_('Discover clusters'))
	->setControls((new CTag('nav', true, (new CList())
		->addItem(new CRedirectButton(_('Rescan'), $url('ep.clients.discover', ['rescan' => '1'])))
		->addItem(new CRedirectButton(_('Back to the list'), $url('ep.clients.list')))))->setAttribute('aria-label', _('Content controls')));

$top = [];
if (!$data['store_ok']) {
	$top[] = (new CDiv(_s('Backups cannot be kept: %1$s is missing or not writable. Adopting is refused until it is there.', $data['store_dir'])))->addClass('msg-bad')->addClass('ep-box');
}
if ($data['error'] !== null) {
	$top[] = (new CDiv($data['error']))->addClass('msg-bad')->addClass('ep-box')->setAttribute('role', 'alert');
}

/* ---------------- what adopting did ---------------- */
foreach ($data['results'] ?? [] as $r) {
	$body = [new CTag('b', true, $r['error'] === null ? _s('%1$s: adopted.', $r['name']) : _s('%1$s: not adopted.', $r['name']))];
	if ($r['error'] !== null) {
		$body[] = ' '.$r['error'];
	}
	if ($r['done']) {
		$body[] = $ul($r['done'], 'ep-plain');
	}
	if ($r['skipped']) {
		$body[] = new CTag('div', true, _('Left out:'));
		$body[] = $ul($r['skipped'], 'ep-plain');
	}
	if ($r['problems']) {
		$body[] = new CTag('div', true, _('Read back from Zabbix, these differ: ').implode(' ', $r['problems']));
	}
	$top[] = (new CDiv($body))->addClass($r['error'] === null ? 'msg-good' : 'msg-bad')->addClass('ep-box');
}

$scan = $data['scan'];
$main = [];
if ($scan !== null) {
	$open = array_values(array_filter($scan['proposals'], fn($p) => $p['status'] !== 'client'));
	$clients = array_values(array_filter($scan['proposals'], fn($p) => $p['status'] === 'client'));
	$groupsMatched = [];
	foreach ($scan['proposals'] as $p) {
		foreach ($p['hosts'][0]['groups'] ?? [] as $g) {
			if (\Modules\EpClients\Lib\Discovery::isClusterGroup((string) $g)) {
				$groupsMatched[$g] = true;
			}
		}
	}
	/* ---- the rule ---- */
	$main[] = (new CDiv([
		(new CDiv([new CTag('b', true, _('Rule:')), ' ', _('a host in a host group whose name matches'), ' ', (new CSpan('elasticsearch'))->addClass('ep-mono-chip'), ' ',
			_('(any case, anywhere in the name) is a cluster. Its host name becomes the cluster name.')]))->addClass('ep-rule-text'),
		(new CDiv(_s('Scanned %1$s · %2$s matching groups · %3$s cluster hosts · %4$s already clients · %5$s not adopted',
			date('Y-m-d H:i', (int) $scan['at']), count($groupsMatched), count($scan['proposals']), count($clients), count($open))))->addClass('ep-muted'),
		(new CDiv(_('Roles looked for: ES, Parser, Forwarder, Engine, AIML, Trino (ULM), SOAR. A server is shown as IP · server name: the Zabbix host name is the IP, the visible name is the server name. A Kafka server is placed under Forwarder and flagged "kafka: confirm"; Kafka is not a role.')))
			->addClass('ep-muted')->addClass('ep-rule-roles')
	]))->addClass('ep-rule');
	foreach ($scan['notes'] as $n) {
		$main[] = (new CDiv($n))->addClass('msg-warning')->addClass('ep-box');
	}
	if (!$scan['proposals']) {
		$main[] = (new CDiv(_('No host is in a host group whose name contains "elasticsearch", so there is nothing to propose.')))->addClass('ep-box')->addClass('ep-muted');
	}
	$main[] = (new CDiv([
		new CLabel([(new CTag('input', false))->setAttribute('type', 'checkbox')->setId('ep-show-clients')->setAttribute('data-ep-show-clients', '1'), ' ',
			_s('Show hosts that are already a client (%1$s)', count($clients))], 'ep-show-clients'),
		(new CSpan(_('Hidden by default: they are on the list already and adopting them again changes nothing.')))->addClass('ep-muted')
	]))->addClass('ep-show');

	$form = (new CForm('post', $url('ep.clients.discover.apply')))
		->addVar(CSRF_TOKEN_NAME, CCsrfTokenHelper::get('ep.clients.discover.apply'))
		->addVar('token', $data['token'])
		->setAttribute('autocomplete', 'off')->setId('ep-disc-form')
		->setAttribute('data-ep-text', json_encode(['head' => _('Adopting %n selected will:'), 'adopt' => _('Adopt selected (%n)'),
			'tpl' => count(\Modules\EpClients\Lib\Reconciler::clusterTemplates()),
			'line' => _('link %tpl templates to %links{host|hosts} · create %groups{group|groups} · adopt %hosts{host|hosts} · set proxy on %proxy{host|hosts} · delete nothing · old templates kept beside')]));

	$t = (new CTag('table', true))->addClass('ep-lt');
	$t->addItem(new CTag('thead', true, new CTag('tr', true, [
		new CTag('th', true, (new CTag('input', false))->setAttribute('type', 'checkbox')->setAttribute('data-ep-all', '1')->setAttribute('aria-label', _('Select all'))),
		new CTag('th', true, [_('Cluster name'), ' ', (new CSpan(_('(editable)')))->addClass('ep-muted')]), new CTag('th', true, _('Host')),
		new CTag('th', true, _('Host groups')), new CTag('th', true, _('Templates found')),
		new CTag('th', true, [_('Role hosts'), ' ', (new CSpan(_('(IP · server name)')))->addClass('ep-muted')]), new CTag('th', true, _('Status'))])));
	$tb = new CTag('tbody', true);
	foreach (array_merge($open, $clients) as $p) {
		$pid = $p['id'];
		$c = $p['choices'];
		$plan = $p['plan'];
		$cluster = $p['hosts'][0];
		$already = $p['status'] === 'client';
		$servers = array_slice($p['hosts'], 1);
		$missing = array_values(array_filter($p['missing'], fn($m) => $m['key'] !== 'kafka' && $m['key'] !== 'role_confirm'));
		$confirms = count(array_filter($servers, fn($h) => ($h['role_source'] ?? null) === 'kafka'));

		$box = (new CCheckBox('sel['.$pid.']', '1'))->setChecked(!empty($c['selected']))->setId('sel_'.$pid)->setAttribute('data-ep-row', $pid)
			->setAttribute('aria-label', _s('Adopt %1$s', $p['name']));
		if ($already || $plan['blockers']) {
			$box->setAttribute('disabled', 'disabled');
		}
		$name = (new CTag('input', false))->setAttribute('type', 'text')->setAttribute('name', 'name['.$pid.']')->setAttribute('value', (string) ($c['name'] ?? $p['name']))
			->setAttribute('aria-label', _s('Cluster name for %1$s', $p['name']))->addClass('ep-nm');
		if ($already) {
			$name->setAttribute('disabled', 'disabled');
		}
		$groupCell = [(new CDiv(array_map(fn($g) => $chip((string) $g), $cluster['groups'])))->addClass('ep-chips')];
		$hasGroup = !in_array('client_group', array_column($p['missing'], 'key'), true);
		$groupCell[] = (new CDiv($already ? _s('client %1$s', $p['name']) : ($hasGroup ? _s('client group found: %1$s', $c['name'] ?? $p['name'])
			: _s('no client group: will create %1$s', $c['name'] ?? $p['name']))))->addClass('ep-muted');
		$tpl = array_values(array_unique(array_merge(array_values(array_intersect($cluster['templates'], \Modules\EpClients\Lib\Reconciler::clusterHostTemplates())), $cluster['es_templates'] ?? [])));
		$tplCell = [new CDiv($tpl ? implode(', ', $tpl) : _('none')), new CDiv($genChip($cluster))];

		// Role hosts: a chip per role, IP · server name, and a dropdown only where a person must decide.
		$roleLines = [];
		foreach ($servers as $h) {
			$id = $h['hostid'];
			$line = [];
			$needs = !$h['roles'] || ($h['role_source'] ?? null) === 'kafka';
			if ($needs && !$already) {
				$sel = (new CTag('select', true))->setAttribute('name', 'role['.$pid.']['.$id.'][]')->setAttribute('aria-label', _s('Role of %1$s', $h['ip'] ?? $h['host']));
				if (($h['role_source'] ?? null) === 'kafka') {
					$sel->addItem((new CTag('option', true, _('Forwarder — kafka, to confirm')))->setAttribute('value', 'kafka:forwarder')->setAttribute('selected', 'selected'));
					foreach (['forwarder', 'parser'] as $rid) {
						if (isset($roleLabel[$rid])) {
							$sel->addItem((new CTag('option', true, $roleLabel[$rid]))->setAttribute('value', $rid));
						}
					}
				}
				else {
					$sel->addItem((new CTag('option', true, _('— role unknown: pick one —')))->setAttribute('value', ''));
					foreach ($roleLabel as $rid => $label) {
						$sel->addItem((new CTag('option', true, $label))->setAttribute('value', $rid));
					}
				}
				$line[] = $sel;
			}
			else {
				foreach ($h['roles'] as $rid) {
					$line[] = $chip($roleLabel[$rid] ?? $rid);
				}
			}
			$line[] = ' ';
			$line[] = (new CSpan($h['ip'] ?? _('no IP')))->addClass('ep-mono');
			$line[] = ' · ';
			$line[] = ($h['server_name'] ?? '') !== '' ? $h['server_name'] : (new CSpan(_('server name unknown')))->addClass('ep-muted');
			if (($h['role_source'] ?? null) === 'kafka') {
				$line[] = ' ';
				$line[] = $chip(_('kafka: confirm Forwarder or Parser'), 'old');
			}
			$line[] = ' ';
			$line[] = (new CSpan('· '.($h['proxy_label'] ?? _('Zabbix server'))))->addClass('ep-muted');
			if (($h['already'] ?? null) !== null && !$already) {
				$line[] = ' ';
				$line[] = $chip(_s('already a client: %1$s', $h['already']));
			}
			if (!$already) {
				$line[] = (new CTag('input', false))->setAttribute('type', 'hidden')->setAttribute('name', 'host['.$pid.']['.$id.']')
					->setAttribute('value', !empty($c['hosts'][$id]) || $needs ? '1' : '0');
				$line[] = (new CTag('input', false))->setAttribute('type', 'hidden')->setAttribute('name', 'move['.$pid.']['.$id.']')->setAttribute('value', '1');
			}
			$roleLines[] = (new CDiv($line))->addClass('ep-rh');
		}
		if (!$already) {
			$roleLines[] = (new CTag('input', false))->setAttribute('type', 'hidden')->setAttribute('name', 'host['.$pid.']['.$cluster['hostid'].']')
				// A cluster host with no ES URL on it is offered too: the plan refuses it until a valid URL is typed, and
				// then it is the real host id that goes with the form (it used to stay 0, so a typed URL adopted nothing).
				->setAttribute('value', !empty($c['hosts'][$cluster['hostid']]) || (($cluster['already'] ?? null) === null && ($cluster['es']['url'] ?? '') === '') ? '1' : '0');
			$roleLines[] = (new CTag('input', false))->setAttribute('type', 'hidden')->setAttribute('name', 'move['.$pid.']['.$cluster['hostid'].']')->setAttribute('value', '1');
		}
		$sources = array_count_values(array_filter(array_map(fn($h) => $h['source_detail'] ?? null, $servers)));
		$roleCell = $servers ? [(new CDiv($roleLines))->addClass('ep-rhs'),
			(new CDiv(implode('; ', array_map(fn($d, $n) => _n('%1$s host: ', '%1$s hosts: ', $n).$d, array_keys($sources), $sources))))->addClass('ep-muted')]
			: array_merge([(new CSpan(_('none found')))->addClass('ep-muted')], $roleLines);

		// Status: Ready, Missing N (what), or refused until something is fixed.
		if ($already) {
			$status = [$chip(_('already a client'))];
		}
		elseif ($plan['blockers']) {
			$status = [(new CSpan(_('Refused until fixed')))->addClass('ep-bad'), $ul($plan['blockers'], 'ep-plain ep-small')];
		}
		elseif ($missing) {
			$status = [(new CSpan(_s('Missing %1$s', count($missing))))->addClass('ep-bad'),
				(new CDiv(implode(' · ', array_values(array_unique(array_map(fn($m) => $short[$m['key']] ?? $m['key'], $missing))))))->addClass('ep-muted')];
		}
		else {
			$status = [(new CSpan(['● ', _('Ready')]))->addClass('ep-ok')];
		}
		if ($confirms && !$already) {
			$status[] = (new CDiv(_n('%1$s role to confirm (kafka)', '%1$s roles to confirm (kafka)', $confirms)))->addClass('ep-muted');
		}
		if (!$already && ($cluster['es']['url'] ?? '') === '') {
			$status[] = new CLabel([_('ES URL'), ' ', (new CTag('input', false))->setAttribute('type', 'text')->setAttribute('name', 'es_url['.$pid.']')
				->setAttribute('value', (string) $c['es_url'])->setAttribute('placeholder', 'https://es.example:9200')->addClass('ep-nm')], null);
		}
		elseif (!$already) {
			$status[] = (new CTag('input', false))->setAttribute('type', 'hidden')->setAttribute('name', 'es_url['.$pid.']')->setAttribute('value', (string) $c['es_url']);
		}

		// The row's details: everything it would write, what was left out, Missing in full, a password.
		$details = [];
		if (!$already) {
			$parts = [];
			if ($plan['writes'] && !$plan['blockers']) {
				$parts[] = new CTag('b', true, _('Adopting this one would:'));
				$parts[] = $ul(array_map(fn($w) => $w[1], $plan['writes']), 'ep-writes');
			}
			if ($plan['skipped']) {
				$parts[] = new CTag('b', true, _('Left out:'));
				$parts[] = $ul(array_values($plan['skipped']), 'ep-plain');
			}
			if ($p['missing']) {
				$parts[] = new CTag('b', true, _('Missing:'));
				$parts[] = $ul(array_map(fn($x) => [$x['text'], ' ', (new CSpan($x['fix']))->addClass('ep-muted')], $p['missing']), 'ep-plain');
			}
			if (in_array($cluster['es']['password'] ?? 'none', ['none', 'secret', 'text'], true)) {
				$parts[] = new CLabel([_('This cluster only: Elasticsearch password (optional)'), ' ', (new CTag('input', false))->setAttribute('type', 'password')
					->setAttribute('name', 'pw['.$pid.']')->setAttribute('value', '')->setAttribute('autocomplete', 'new-password')], null);
				$parts[] = (new CSpan(_('Empty: the default credential is used. Typed: kept as a Zabbix secret on the cluster host and handed to ElasticPro; never shown again.')))->addClass('ep-muted');
			}
			$details = [(new CTag('details', true, [new CTag('summary', true, _('Details')), (new CDiv($parts))->addClass('ep-det')]))];
		}
		$hostCell = [(new CDiv($cluster['host']))->addClass('ep-mono'), (new CDiv(_s('monitored by %1$s', $cluster['proxy_label'] ?? _('Zabbix server'))))->addClass('ep-muted')];
		$tr = new CTag('tr', true, [new CTag('td', true, $box), new CTag('td', true, $name), new CTag('td', true, $hostCell),
			new CTag('td', true, $groupCell), new CTag('td', true, $tplCell), new CTag('td', true, $roleCell), new CTag('td', true, array_merge($status, $details))]);
		$tr->setAttribute('data-proposal', $pid);
		if ($already) {
			$tr->addClass('ep-already')->setAttribute('hidden', 'hidden');
		}
		else {
			// What the summary adds up for this row when it is ticked (views/js/ep.clients.discover.js).
			$tr->setAttribute('data-ep-sum', json_encode([
				'cluster' => $plan['cluster'] !== null ? ['host' => $cluster['host'], 'proxy' => $cluster['monitored_by'] ?? ''] : null,
				'groups' => array_values(array_map(fn($w) => preg_replace('/^.*"(.+)".*$/', '$1', $w[1]), array_filter($plan['writes'], fn($w) => $w[0] === 'group'))),
				'hosts' => (int) $plan['adopting'],
				'servers' => array_values(array_map(fn($s) => ['host' => $s['row']['host'], 'proxy' => $s['row']['monitored_by'] ?? ''], $plan['servers'])),
				'es_proxy' => $plan['form']['monitored_by'] ?? '', 'linux_proxy' => $plan['form']['servers_monitored_by'] ?? ''
			]));
		}
		$tb->addItem($tr);
	}
	$t->addItem($tb);
	$form->addItem((new CDiv($t))->addClass('ep-table-wrap'));

	/* ---- the summary: two proxies, what the ticked rows write, one confirm ---- */
	if ($open) {
		$ticked = array_values(array_filter($open, fn($p) => !empty($p['choices']['selected'])));
		$clusterLinks = 0;
		$hosts = 0;
		$created = [];
		foreach ($ticked as $p) {
			foreach ($p['plan']['writes'] as [$op, $text]) {
				if ($op === 'group') {
					$created[] = preg_replace('/^.*"(.+)".*$/', '$1', $text);
				}
			}
			$hosts += (int) $p['plan']['adopting'];
			$clusterLinks += $p['plan']['cluster'] !== null ? 1 : 0;
		}
		$proxyMoves = count(array_merge([], ...array_map(fn($p) => array_values(array_filter($p['plan']['writes'], fn($w) => $w[0] === 'monitor')), $ticked)));
		$five = \Modules\EpClients\Lib\Reconciler::clusterTemplates();
		$form->addItem((new CDiv([
			(new CDiv([
				(new CDiv([new CLabel([_('ES hosts monitored by'), ' ', $proxyOptions('proxy_es')], null),
					new CLabel([_('Linux hosts monitored by'), ' ', $proxyOptions('proxy_linux')], null)]))->addClass('ep-proxies'),
				(new CDiv(new CTag('b', true, _s('Adopting %1$s selected will:', count($ticked)))))->addClass('ep-sum-h')->setAttribute('data-ep-sum-head', '1'),
				(new CDiv(_s('link %1$s templates to %2$s · create %3$s · adopt %4$s · set proxy on %5$s · delete nothing · old templates kept beside',
					count($five), _n('%1$s host', '%1$s hosts', $clusterLinks), _n('%1$s group', '%1$s groups', count(array_unique($created))),
					_n('%1$s host', '%1$s hosts', $hosts), _n('%1$s host', '%1$s hosts', $proxyMoves))))->addClass('ep-sum-line')->setAttribute('data-ep-sum-line', '1'),
				$ul([
					[new CTag('b', true, _s('%1$s templates, linked directly to each cluster host:', count($five))), ' ', implode(', ', $five), '. ', _('Not nested in one template.')],
					_('Proxy: each cluster host by "ES hosts monitored by", every Linux role host by "Linux hosts monitored by" — both the Zabbix server unless you choose a proxy. A host monitored otherwise today is listed under Missing.'),
					$created ? _s('Groups created: %1$s.', implode(', ', array_unique($created))) : _('No group to create.'),
					_('Templates already linked stay linked beside, with their history. Nothing is unlinked, renamed or deleted.'),
					_('Each client\'s own master host is made as today. A backup is taken first.')
				], 'ep-wl')
			]))->addClass('ep-sum-main'),
			(new CDiv([
				(new CTag('button', true, _s('Adopt selected (%1$s)', count($ticked))))->setAttribute('type', 'submit')->setAttribute('name', 'do')->setAttribute('value', 'adopt')
					->setAttribute('data-ep-adopt', '1')->addClass('ep-adopt'),
				(new CTag('button', true, _('Preview writes')))->setAttribute('type', 'submit')->setAttribute('name', 'do')->setAttribute('value', 'preview')->addClass('btn-alt'),
				(new CSpan(_('One confirm. Missing pieces are listed on each client afterwards.')))->addClass('ep-muted')
			]))->addClass('ep-sum-side')
		]))->addClass('ep-summary'));
	}
	$main[] = $form;
	if ($scan['unattached']) {
		$main[] = new CTag('h4', true, _('Role hosts no cluster could be found for'));
		$main[] = $ul(array_map(fn($h) => [(new CSpan($h['ip'] ?? $h['host']))->addClass('ep-mono'), ' · ', ($h['server_name'] ?? '') !== '' ? $h['server_name'] : $h['host'], ' — ', $h['source_detail']],
			$scan['unattached']), 'ep-plain');
	}
}

$page->addItem((new CDiv(array_merge($top, $main)))->addClass('ep-disc'))->show();
?>
<style><?php readfile(__DIR__.'/css/ep.clients.discover.css'); ?></style>
<script><?php readfile(__DIR__.'/js/ep.clients.discover.js'); ?></script>
