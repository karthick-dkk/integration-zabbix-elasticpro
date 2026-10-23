/*
 * The client form's behaviour: the server list and its add/edit sheet (IP = host name, server name =
 * visible name), which requested-capacity rows show, and the segmented choices. The page itself is drawn
 * by ep.clients.edit.php; this only brings it to life and, on submit, writes the servers as JSON
 * into the form's `servers` field for ClientSpec to check.
 *
 * serverBase() must name servers exactly as Roles::serverBase() does in PHP; test/form.test.mjs
 * and test/spec.test.php hold them to the same table.
 */
(function () {
	const EpClientForm = {
		/** Name part for these roles: each role's own, in role order, a family word written once. */
		serverBase(families, ids) {
			const parts = [];
			for (const f of families) {
				for (const r of f.roles) {
					if (!ids.includes(r.id)) continue;
					let words = r.short.split('-');
					if (parts.length && words.length > 1 && parts.includes(words[0])) words = words.slice(1);
					parts.push(...words);
				}
			}
			return parts.join('-');
		},

		/**
		 * What each server is called, in order: its host name is its IP (an existing host keeps the
		 * host name it has, whatever it is), and its server name — the visible name — is the one
		 * given, else the one it has, else none. Nothing is made up from the client and its roles.
		 */
		labels(servers, techNames, visibleNames) {
			return servers.map((s) => {
				const tech = (techNames && techNames[s.ip]) || s.ip;
				const visible = visibleNames && visibleNames[s.ip];
				const name = s.host || (visible && visible !== tech && visible !== s.ip ? visible : '');
				return { host: tech, name };
			});
		},

		init(root, data) {
			const $ = (sel) => root.querySelector(sel);
			const $$ = (sel) => [...root.querySelectorAll(sel)];
			const families = data.roles.families;
			const byId = {};
			families.forEach((f) => f.roles.forEach((r) => { byId[r.id] = Object.assign({ family: f.label }, r); }));
			const order = Object.keys(byId);
			let servers = (data.servers || []).map((s) => ({ ip: s.ip, roles: s.roles.slice(), services: (s.services || []).slice(), notes: s.notes || '', attrs: s.attrs || {}, host: s.host || '', confirm: s.confirm || '' }));
			// Servers in Zabbix now that were removed here: shown until saved, and can be put back.
			const removed = (data.current || []).filter((c) => !servers.some((s) => s.ip === c.ip))
				.map((c) => ({ ip: c.ip, roles: c.roles.slice(), services: (c.services || []).slice(), notes: c.notes || '', attrs: c.attrs || {} }));
			const inZabbix = (ip) => (data.current || []).some((c) => c.ip === ip);
			let editing = -1;
			const picked = new Set();
			const client = () => ($('#name').value.trim() || 'client');
			const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };

			/* ---- segmented choices: radio inputs styled as one control ---- */
			const segValue = (name) => (root.querySelector(`input[name="${name}"]:checked`) || {}).value;
			const onSeg = (name, fn) => { $$(`input[name="${name}"]`).forEach((i) => i.addEventListener('change', fn)); fn(); };
			onSeg('type', () => {
				const type = segValue('type');
				$('#ep-type-hint').textContent = type === 'DI' ? data.text.diHint : data.text.opHint;
				$('#ep-archive').classList.toggle('ep-required', type === 'DI');
				// DI needs the archive, CI shows it; On-Prem keeps it folded unless a bucket is set.
				$('#ep-archive-note').textContent = type === 'On-Prem' ? data.text.notOnPrem : '';
				if (type !== 'On-Prem') openArchive(true);
			});
			onSeg('purchased_by', () => {
				const dev = segValue('purchased_by') === 'devices';
				$('#purchased').hidden = dev;
				$('#purchased_devices').hidden = !dev;
				$('#ep-purchased-unit').textContent = dev ? data.text.devices : 'GB';
			});

			/* ---- credential: Default, or This cluster only (Zabbix + ElasticPro, or Vault) ---- */
			const credMode = () => (segValue('ep_cred') === 'default' ? 'default' : ($('#ep_kept') ? $('#ep_kept').value : 'zabbix'));
			function credential() {
				const mode = credMode();
				$('#es_password_mode').value = mode;
				$('#ep-cred-default').hidden = mode !== 'default';
				$('#ep-cred-own').hidden = mode === 'default';
				$('#ep-pw-zabbix').hidden = mode !== 'zabbix';
				$('#es_password_path').hidden = mode !== 'vault';
				$('#ep-pw-hint').textContent = mode === 'default' ? data.text.pwDefault : (mode === 'vault' ? data.text.pwVault : data.text.pwZabbix);
			}
			$$('input[name="ep_cred"]').forEach((i) => i.addEventListener('change', credential));
			if ($('#ep_kept')) $('#ep_kept').addEventListener('change', credential);
			credential();
			// "Change": the field, and the keep-marker turned to "set" — only then does a typed password
			// count. "Keep current" takes both back.
			const pwChange = $('#ep-pw-change');
			if (pwChange) {
				pwChange.addEventListener('click', () => {
					$('#ep-pw-new').hidden = false;
					$('#es_password_action').value = 'set';
					$('#ep-pw-set').hidden = true;
					$('#es_password').focus();
				});
			}
			const pwKeep = $('#ep-pw-keep');
			if (pwKeep) {
				pwKeep.addEventListener('click', () => {
					$('#es_password').value = '';
					$('#ep-pw-new').hidden = true;
					$('#es_password_action').value = 'keep';
					$('#ep-pw-set').hidden = false;
				});
			}

			/* ---- route and proxy: Direct through a Zabbix proxy (or the server), or a jump host ---- */
			function route() {
				const jump = segValue('ep_route') === 'jump';
				$('#ep-jump-addr').hidden = !jump;
				$('#ep-jump').hidden = !jump;
				$('#ep-proxy-row').hidden = jump;
				$('#monitored_by').value = jump ? 'jump' : $('#ep_es_proxy').value;
			}
			$$('input[name="ep_route"]').forEach((i) => i.addEventListener('change', route));
			$('#ep_es_proxy').addEventListener('change', route);
			route();
			const tls = $('#jump_tls');
			if (tls) { const paint = () => { $('#ep-jump-ca').hidden = tls.value !== 'ca'; }; tls.addEventListener('change', paint); paint(); }

			/* ---- notes: one line, "more" opens a bigger box ---- */
			const more = $('[data-ep-notes-more]');
			if (more) more.addEventListener('click', () => {
				const input = $('#client_notes');
				const area = document.createElement('textarea');
				area.id = input.id; area.name = input.name; area.value = input.value; area.maxLength = 2000; area.rows = 4;
				input.replaceWith(area); more.hidden = true; area.focus();
			});

			/* ---- tabs (an existing client): Overview, the form, Templates, Backups ---- */
			const panels = $$('.ep-panel');
			function tab(name) {
				const panel = name === 'servers' || name === 'connection' ? 'form' : name;
				panels.forEach((p) => { p.hidden = p.dataset.panel !== panel; });
				$$('[role="tab"]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.tab === name)));
				const card = name === 'servers' ? $('#ep-card-servers') : (name === 'connection' ? $('#ep-card-connection') : null);
				if (card && card.scrollIntoView) card.scrollIntoView({ block: 'start' });
			}
			$$('[role="tab"]').forEach((b) => b.addEventListener('click', () => tab(b.dataset.tab)));
			$$('[data-goto]').forEach((b) => b.addEventListener('click', () => tab(b.dataset.goto)));
			// A save that came back with something to fix opens on the form, not on Overview.
			if (data.openForm && panels.length) tab('connection');

			/* ---- unsaved changes ---- */
			const dirty = $('#ep-dirty');
			const mark = () => { if (dirty) { dirty.textContent = data.text.unsaved; dirty.classList.add('ep-dirty'); } };
			root.addEventListener('input', mark);
			root.addEventListener('change', mark);
			function openArchive(open) {
				$('#ep-archive-body').hidden = !open;
				$('#ep-archive-open-row').hidden = open;
			}
			$('#ep-archive-open').addEventListener('click', () => openArchive(true));
			if ($('#ulm_bucket').value.trim() !== '') openArchive(true);
			$$('[data-ep-disclose]').forEach((b) => b.addEventListener('click', () => {
				const t = $('#' + b.dataset.epDisclose);
				t.hidden = !t.hidden;
				b.setAttribute('aria-expanded', String(!t.hidden));
				b.textContent = t.hidden ? b.dataset.more : b.dataset.less;
			}));

			/* ---- the server list ---- */
			const list = $('#ep-server-list');
			const ipNum = (ip) => ip.split('.').reduce((a, b) => a * 256 + Number(b), 0);
			function draw() {
				// Sorted as the page saves them, so the names previewed are the names given.
				servers.sort((a, b) => ipNum(a.ip) - ipNum(b.ip));
				const names = EpClientForm.labels(servers, data.techNames || {}, data.names || {});
				list.textContent = '';
				if (!servers.length) list.appendChild(el('div', 'ep-empty', data.text.noServers));
				servers.forEach((s, i) => {
					const row = el('div', 'ep-server');
					row.appendChild(el('span', 'ep-ip', s.ip));
					const nm = el('div', 'ep-server-name', names[i].name || data.text.noServerName);
					if (!names[i].name) nm.classList.add('ep-unknown');
					if (names[i].host !== s.ip) nm.appendChild(el('span', 'ep-rename', ' · ' + data.text.hostName.replace('%h', names[i].host)));
					row.appendChild(nm);
					const mid = el('div', 'ep-server-main');
					const pills = el('div', 'ep-pills');
					s.roles.forEach((r) => pills.appendChild(el('span', 'ep-pill', (byId[r] || { label: r }).label)));
					s.services.forEach((v) => pills.appendChild(el('span', 'ep-pill ep-pill-svc', v)));
					if (s.roles.length > 1) pills.appendChild(el('span', 'ep-pill ep-pill-mark', data.text.shared));
					if (s.roles.some((r) => byId[r] && byId[r].exclusive)) pills.appendChild(el('span', 'ep-pill ep-pill-mark', data.text.single));
					// A Kafka server Discover placed under Forwarder: said, until someone confirms its role.
					if (s.confirm === 'kafka') {
						pills.appendChild(el('span', 'ep-flag', data.text.kafka));
						const ok = el('button', 'ep-lnk', data.text.confirm); ok.type = 'button';
						ok.addEventListener('click', () => openSheet(i));
						pills.appendChild(ok);
					}
					mid.appendChild(pills);
					if (s.notes) mid.appendChild(el('div', 'ep-server-notes', s.notes));
					row.appendChild(mid);
					const act = el('div', 'ep-server-actions');
					const edit = el('button', 'ep-link', data.text.edit); edit.type = 'button';
					edit.addEventListener('click', () => openSheet(i));
					const del = el('button', 'ep-icon', '×'); del.type = 'button';
					del.setAttribute('aria-label', data.text.remove + ' ' + s.ip);
					del.addEventListener('click', () => {
						const [gone] = servers.splice(i, 1);
						if (inZabbix(gone.ip)) removed.push(gone);
						draw();
					});
					act.append(edit, del);
					row.appendChild(act);
					list.appendChild(row);
				});
				// Removed, still in Zabbix until saved.
				removed.forEach((s, i) => {
					const row = el('div', 'ep-server ep-removed');
					row.appendChild(el('span', 'ep-ip', s.ip));
					const mid = el('div', 'ep-server-main');
					mid.appendChild(el('div', 'ep-server-name', (data.names && data.names[s.ip]) || s.ip));
					row.appendChild(mid);
					const note = el('div', 'ep-server-actions');
					note.appendChild(el('span', 'ep-del-note', data.text.willDelete));
					const undo = el('button', 'ep-link', data.text.undo); undo.type = 'button';
					undo.addEventListener('click', () => { servers.push(removed.splice(i, 1)[0]); draw(); });
					note.appendChild(undo);
					row.appendChild(note);
					list.appendChild(row);
				});
				// Hosts with no role: listed, never touched, until given a role.
				(data.unassigned || []).filter((u) => !servers.some((s) => s.ip === u.ip)).forEach((u) => {
					const row = el('div', 'ep-server ep-norole');
					row.appendChild(el('span', 'ep-ip', u.ip));
					const mid = el('div', 'ep-server-main');
					mid.appendChild(el('div', 'ep-server-name', u.name));
					mid.appendChild(el('div', 'ep-server-notes', data.text.noRole));
					row.appendChild(mid);
					const act = el('div', 'ep-server-actions');
					const give = el('button', 'ep-link', data.text.giveRole); give.type = 'button';
					give.addEventListener('click', () => openSheet(-1, u));
					act.appendChild(give);
					row.appendChild(act);
					list.appendChild(row);
				});
				if (list.children.length > 1 && list.firstChild.classList.contains('ep-empty')) list.removeChild(list.firstChild);
				$('#ep-server-count').textContent = servers.length === 1 ? data.text.oneServer : data.text.nServers.replace('%n', servers.length);
				requested();
			}

			/* ---- the add / edit sheet ---- */
			const sheet = $('#ep-sheet');
			function chips() {
				const box = $('#ep-chips');
				box.textContent = '';
				families.forEach((f) => {
					const row = el('div', 'ep-chip-row');
					row.appendChild(el('span', 'ep-chip-fam', f.label));
					const wrap = el('div', 'ep-chip-wrap');
					f.roles.forEach((r) => {
						const c = el('button', 'ep-chip', r.label + (r.exclusive ? ' · ' + data.text.allInOne : ''));
						c.type = 'button';
						c.setAttribute('aria-pressed', picked.has(r.id) ? 'true' : 'false');
						c.addEventListener('click', () => {
							if (picked.has(r.id)) picked.delete(r.id);
							else if (r.exclusive) { picked.clear(); picked.add(r.id); }
							else { order.filter((id) => byId[id].exclusive).forEach((id) => picked.delete(id)); picked.add(r.id); }
							chips(); preview();
						});
						wrap.appendChild(c);
					});
					row.appendChild(wrap);
					box.appendChild(row);
				});
			}
			const ipsIn = () => $('#ep-s-ip').value.split(/[\s,;]+/).filter(Boolean);

			/* ---- hosts already in Zabbix: search as you type, pick one to take it on ---- */
			const known = {};   // ip → {name, client}, from what the search answered
			const box = $('#ep-s-suggest');
			let timer = null;
			let asked = '';
			const lastToken = () => { const v = $('#ep-s-ip').value; const m = /([^\s,;]*)$/.exec(v); return m ? m[1] : ''; };
			function closeSuggest() { box.hidden = true; $('#ep-s-ip').setAttribute('aria-expanded', 'false'); }
			function search(q) {
				asked = q;
				fetch('zabbix.php?action=ep.clients.hosts&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
					.then((r) => r.json()).then((d) => {
						if (asked !== q) return;
						(d.hosts || []).forEach((h) => { known[h.ip] = { name: h.name, client: h.client }; });
						box.textContent = '';
						const hosts = (d.hosts || []).filter((h) => !servers.some((s, i) => s.ip === h.ip && i !== editing));
						if (!hosts.length) {
							box.appendChild(el('div', 'ep-none', validIp(q) ? data.text.noMatch : '—'));
						}
						hosts.slice(0, 10).forEach((h) => {
							const other = h.client && h.client !== client();
							const b = el('button', ''); b.type = 'button'; b.setAttribute('role', 'option');
							b.append(el('span', 'ep-ip', h.ip), el('span', '', h.name), el('span', 'ep-owner', other ? data.text.serverOf.replace('%c', h.client) : data.text.inZabbix));
							if (other) b.disabled = true;
							b.addEventListener('click', () => {
								const v = $('#ep-s-ip').value;
								$('#ep-s-ip').value = v.slice(0, v.length - lastToken().length) + h.ip;
								closeSuggest(); preview(); $('#ep-s-ip').focus();
							});
							box.appendChild(b);
						});
						box.hidden = false;
						$('#ep-s-ip').setAttribute('aria-expanded', 'true');
						preview();
					}).catch(() => closeSuggest());
			}
			$('#ep-s-ip').addEventListener('input', () => {
				clearTimeout(timer);
				const q = lastToken();
				if (q.length < 2) { closeSuggest(); return; }
				timer = setTimeout(() => search(q), 250);
			});
			$('#ep-s-ip').addEventListener('keydown', (e) => {
				if (e.key === 'Escape') closeSuggest();
				if (e.key === 'ArrowDown' && !box.hidden) { const f = box.querySelector('button:not([disabled])'); if (f) { e.preventDefault(); f.focus(); } }
			});
			document.addEventListener('click', (e) => { if (!e.target.closest('.ep-ip-wrap')) closeSuggest(); });
			const validIp = (ip) => /^(25[0-5]|2[0-4]\d|1?\d?\d)(\.(25[0-5]|2[0-4]\d|1?\d?\d)){3}$/.test(ip);
			function preview() {
				const ids = order.filter((id) => picked.has(id));
				const ips = ipsIn();
				const bad = ips.filter((ip) => !validIp(ip));
				const dup = ips.filter((ip) => servers.some((s, i) => s.ip === ip && i !== editing));
				const taken = ips.filter((ip) => known[ip] && known[ip].client && known[ip].client !== client());
				const msg = $('#ep-s-msg');
				const add = $('#ep-s-add');
				add.disabled = !ids.length || !ips.length || bad.length > 0 || dup.length > 0 || taken.length > 0;
				if (bad.length) { msg.textContent = data.text.badIp.replace('%s', bad[0]); return; }
				if (dup.length) { msg.textContent = data.text.dupIp.replace('%s', dup[0]); return; }
				if (taken.length) { msg.textContent = data.text.takenBy.replace('%s', taken[0]).replace('%c', known[taken[0]].client); return; }
				if (!ids.length) { msg.textContent = data.text.pickRoles; return; }
				const given = $('#ep-s-name').value.trim();
				const [first] = EpClientForm.labels([{ ip: ips[0], host: given }], data.techNames || {}, data.names || {});
				const existing = ips.length === 1 && known[ips[0]] && !(data.names && data.names[ips[0]]) ? known[ips[0]] : null;
				const said = ips.length > 1 ? data.text.manyNamed.replace('%n', ips.length)
					: data.text.named.replace('%ip', existing ? existing.name : first.host).replace('%name', first.name || data.text.noServerName);
				msg.textContent = existing ? data.text.takeOn.replace('%h', existing.name) + ' ' + said : said;
				add.textContent = editing >= 0 ? data.text.update : (ips.length > 1 ? data.text.addMany.replace('%n', ips.length) : data.text.add);
			}
			function openSheet(i, prefill) {
				editing = i;
				picked.clear();
				const s = i >= 0 ? servers[i] : null;
				$('#ep-s-ip').value = s ? s.ip : (prefill ? prefill.ip : '');
				if (prefill) prefill.suggest.forEach((r) => picked.add(r));
				$('#ep-s-svc').value = s ? s.services.join(', ') : '';
				$('#ep-s-notes').value = s ? s.notes : '';
				$('#ep-s-name').value = s ? (s.host || '') : '';
				if (s) s.roles.forEach((r) => picked.add(r));
				chips(); preview();
				sheet.hidden = false;
				$('#ep-add-server').hidden = true;
				$('#ep-s-ip').focus();
			}
			function closeSheet() { closeSuggest(); sheet.hidden = true; $('#ep-add-server').hidden = false; editing = -1; }
			$('#ep-add-server').addEventListener('click', () => openSheet(-1));
			$('#ep-s-cancel').addEventListener('click', closeSheet);
			$('#ep-s-ip').addEventListener('input', preview);
			$('#ep-s-name').addEventListener('input', preview);
			$('#ep-s-ip').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); if (!$('#ep-s-add').disabled) $('#ep-s-add').click(); } });
			$('#ep-s-add').addEventListener('click', () => {
				const ids = order.filter((id) => picked.has(id));
				const services = $('#ep-s-svc').value.split(/[,;]+/).map((x) => x.trim()).filter(Boolean);
				const notes = $('#ep-s-notes').value.trim();
				// A server name names one server: several IPs pasted at once get none.
				const host = ipsIn().length === 1 ? $('#ep-s-name').value.trim() : '';
				if (editing >= 0) {
					// The roles are a person's choice now: nothing is left to confirm.
					Object.assign(servers[editing], { ip: ipsIn()[0], roles: ids, services, notes, host, confirm: '' });
				}
				else {
					ipsIn().forEach((ip) => servers.push({ ip, roles: ids, services, notes, attrs: {}, host }));
				}
				closeSheet(); draw();
			});
			$('#name').addEventListener('input', () => { draw(); if (!sheet.hidden) preview(); });

			/* ---- requested capacity: only roles in use, or with a figure, or asked for ---- */
			const revealed = new Set();
			function requested() {
				const used = new Set(servers.flatMap((s) => s.roles));
				const hidden = [];
				$$('tr[data-ep-role]').forEach((tr) => {
					const id = tr.dataset.epRole;
					const has = [...tr.querySelectorAll('input[data-ep-num]')].some((i) => i.value.trim() !== '' && Number(i.value) !== 0)
						|| [...tr.querySelectorAll('input.ep-mount-in')].some((i) => i.value.trim() !== '');
					const n = servers.filter((s) => s.roles.includes(id)).length;
					tr.querySelector('.ep-built').textContent = n ? data.text.built.replace('%n', n) : '';
					const show = used.has(id) || has || revealed.has(id);
					tr.hidden = !show;
					if (!show) hidden.push(id);
				});
				const sel = $('#ep-request-role');
				sel.textContent = '';
				sel.appendChild(new Option(data.text.requestRole, ''));
				hidden.forEach((id) => sel.appendChild(new Option(byId[id].family + ' · ' + byId[id].label, id)));
				sel.hidden = !hidden.length;
				$('#ep-req-empty').hidden = hidden.length < order.length;
			}
			$('#ep-request-role').addEventListener('change', (e) => { if (e.target.value) { revealed.add(e.target.value); requested(); } });
			$$('tr[data-ep-role] input[data-ep-num]').forEach((i) => i.addEventListener('change', requested));

			/* ---- disks: / always; extra disks shown once added, at most five in all ---- */
			const slotEmpty = (slot) => [...slot.querySelectorAll('input')].every((i) => i.value.trim() === '' || i.value.trim() === '0');
			function disks(tr) {
				const slots = [...tr.querySelectorAll('[data-ep-slot]')];
				const add = tr.querySelector('[data-ep-disk-add]');
				add.hidden = slots.every((s) => !s.hidden);
			}
			$$('tr[data-ep-role]').forEach((tr) => {
				tr.querySelectorAll('[data-ep-slot]').forEach((slot) => { slot.hidden = slotEmpty(slot); });
				tr.querySelector('[data-ep-disk-add]').addEventListener('click', () => {
					const next = [...tr.querySelectorAll('[data-ep-slot]')].find((s) => s.hidden);
					if (next) { next.hidden = false; next.querySelector('input').focus(); }
					disks(tr);
				});
				tr.querySelectorAll('[data-ep-disk-remove]').forEach((b) => b.addEventListener('click', () => {
					const slot = b.closest('[data-ep-slot]');
					slot.querySelectorAll('input').forEach((i) => { i.value = ''; });
					slot.hidden = true;
					disks(tr);
				}));
				disks(tr);
			});

			/* ---- submit: the servers into the form ---- */
			root.addEventListener('submit', () => {
				// Removed servers are simply not sent; the save lists what it would delete and asks first.
				$('#servers').value = JSON.stringify(servers);
			});
			draw();
		}
	};
	if (typeof module !== 'undefined' && module.exports) module.exports = EpClientForm;
	else window.EpClientForm = EpClientForm;
})();
