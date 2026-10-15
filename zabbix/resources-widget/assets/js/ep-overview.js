/*
 * Client resources: the Overview view — the panels of the approved Version 2 design, drawn from
 * `ep_overview` (WidgetView::overview). Every figure and every colour class comes from the
 * controller, which decided them with the same thresholds as the table; this only lays them out.
 *
 * All clients: one summary card per client, then "Used of allocated" and "Servers by role" with a
 * block per client. One client: its tiles (devices, servers up, servers down) and gauges (ES CPU,
 * ES memory, ES storage), then the same two panels for that client alone. The client picker, the
 * Type filter and the search all decide which clients are drawn.
 */
window.EpOverview = {
	esc(s) {
		return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	},
	num(v, d = 1) {
		return v === null || v === undefined ? '—' : (Math.round(v * 10 ** d) / 10 ** d).toLocaleString();
	},
	bytes(v) {
		if (v === null || v === undefined) return '—';
		const u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
		let i = 0, n = v;
		while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
		return `${(Math.round(n * 100) / 100).toLocaleString()} ${u[i]}`;
	},
	/** The CSS colour for a class the controller chose. */
	color(cls) {
		return cls === 'ep-high' ? 'var(--ep-high)' : cls === 'ep-warn' ? 'var(--ep-warn)' : 'var(--ep-ok)';
	},

	init(widget, body, data) {
		const root = body.querySelector('.ep-panel[data-ep-panel="resources"]');
		const pane = root && root.querySelector('.ep-ov');
		if (!root || !pane || !data) return;
		const st = widget._epOv || (widget._epOv = { view: 'table', client: '' });
		const table = root.querySelector('.ep-res-scroll');
		const only = root.querySelectorAll('[data-ep-expand-all]');
		const show = (view) => {
			st.view = view;
			for (const b of root.querySelectorAll('[data-ep-view]')) b.setAttribute('aria-pressed', String(b.dataset.epView === view));
			pane.hidden = view !== 'overview';
			if (table) table.hidden = view === 'overview';
			for (const b of only) b.hidden = view === 'overview';
			if (view === 'overview') this.draw(root, pane, data, st);
		};
		for (const b of root.querySelectorAll('[data-ep-view]')) b.addEventListener('click', () => show(b.dataset.epView));
		// The search and the Type filter decide the overview's clients too.
		const redraw = () => { if (st.view === 'overview') this.draw(root, pane, data, st); };
		const q = root.querySelector('[data-ep-search]');
		if (q) q.addEventListener('input', redraw);
		const sel = root.querySelector('[data-ep-type-filter]');
		if (sel) sel.addEventListener('change', redraw);
		pane.addEventListener('change', (e) => { if (e.target.matches('[data-ep-ov-client]')) { st.client = e.target.value; redraw(); } });
		pane.addEventListener('click', (e) => {
			const card = e.target.closest('[data-ep-ov-pick]');
			if (card) { st.client = card.dataset.epOvPick; redraw(); }
		});
		show(st.view);
	},

	visible(root, data, st) {
		const sel = root.querySelector('[data-ep-type-filter]');
		const type = sel ? sel.value : '';
		const q = ((root.querySelector('[data-ep-search]') || {}).value || '').trim().toLowerCase();
		return data.clients.filter((c) => (!type || c.type === type)
			&& (!q || `${c.name} ${c.servers.map((s) => s.host).join(' ')}`.toLowerCase().includes(q))
			&& (!st.client || c.name === st.client));
	},

	draw(root, pane, data, st) {
		if (st.client && !data.clients.some((c) => c.name === st.client)) st.client = '';
		const list = this.visible(root, data, st);
		const e = this.esc.bind(this);
		const picker = `<div class="ep-ov-bar"><label>${e('Client')} <select data-ep-ov-client aria-label="Client"><option value="">${e('All clients')}</option>`
			+ data.clients.map((c) => `<option${c.name === st.client ? ' selected' : ''}>${e(c.name)}</option>`).join('')
			+ `</select></label><span class="ep-ov-note">${st.client ? e(st.client) + ': every panel shows this client only' : 'All clients: every panel shows one block per client'}</span></div>`;
		if (!list.length) {
			pane.innerHTML = picker + `<div class="ep-empty">No client matches the search or the type.</div>`;
			return;
		}
		const summary = st.client ? this.tiles(list[0]) : `<section class="ep-ov-panel ep-ov-wide"><h4>Summary by client <small>select a client to open its own view</small></h4><div class="ep-ov-cards">${list.map((c) => this.card(c)).join('')}</div></section>`;
		pane.innerHTML = picker + summary
			+ `<div class="ep-ov-row">${this.usedOfAllocated(list, data.families)}${this.roles(list)}</div>`;
	},

	servers(c) {
		const up = c.servers.filter((s) => s.status === 'up').length;
		const down = c.servers.filter((s) => s.status === 'down').length;
		return { up, down, total: c.servers.length };
	},

	card(c) {
		const e = this.esc.bind(this);
		const { up, down, total } = this.servers(c);
		const es = c.families.es || null;
		const bar = (label, v, cls) => `<div class="ep-ov-m"><span>${e(label)}</span><i><b style="width:${v === null || v === undefined ? 0 : Math.max(0, Math.min(100, v))}%;background:${this.color(cls)}"></b></i><em style="color:${v === null || v === undefined ? 'var(--ep-faint)' : this.color(cls)}">${v === null || v === undefined ? '—' : this.num(v) + '%'}</em></div>`;
		return `<button type="button" class="ep-ov-card" data-ep-ov-pick="${e(c.name)}" title="Open ${e(c.name)}">`
			+ `<span class="ep-ov-card-h"><b>${e(c.name)}</b><span class="ep-pill">${e(c.type)}</span>${c.badge ? `<span class="ep-ov-badge">${e(c.badge)}</span>` : ''}</span>`
			+ `<span class="ep-ov-trio"><span><b style="color:var(--ep-ok)">${up}<small> / ${total}</small></b><small>servers up</small></span>`
			+ `<span><b style="color:${down ? 'var(--ep-high)' : 'var(--ep-ok)'}">${down}</b><small>down</small></span>`
			+ `<span><b style="color:var(--ep-blue)">${c.devices.purchased ?? '—'}</b><small>devices</small></span></span>`
			+ (es ? bar('ES CPU', es.cpu.usage, es.cpu.class) + bar('ES memory', es.mem.usage, es.mem.class) : '')
			+ bar('ES storage', c.storage.usage, c.storage.class) + `</button>`;
	},

	gauge(v, cls, warn, high, sub) {
		const cx = 90, cy = 92, Rv = 62, Rb = 76;
		const pt = (f, r) => { const a = Math.PI * (1 - f); return [cx + r * Math.cos(a), cy - r * Math.sin(a)]; };
		const arc = (f0, f1, r) => { const [x0, y0] = pt(f0, r), [x1, y1] = pt(f1, r); return `M${x0.toFixed(2)} ${y0.toFixed(2)} A${r} ${r} 0 0 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`; };
		const band = [[0, warn / 100, 'var(--ep-ok)'], [warn / 100, high / 100, 'var(--ep-warn)'], [high / 100, 1, 'var(--ep-high)']]
			.filter(([a, b]) => b > a).map(([a, b, c]) => `<path d="${arc(a + .004, b - .004, Rb)}" stroke="${c}" stroke-width="4" fill="none"></path>`).join('');
		const known = v !== null && v !== undefined;
		const f = known ? Math.max(0, Math.min(1, v / 100)) : 0;
		return `<svg viewBox="0 0 180 112" role="img" aria-label="${known ? this.num(v) + ' %' : 'not reported'}">${band}`
			+ `<path d="${arc(0, 1, Rv)}" style="stroke:var(--ep-track)" stroke-width="18" fill="none"></path>`
			+ (f > 0 ? `<path d="${arc(0, f, Rv)}" stroke="${this.color(cls)}" stroke-width="18" fill="none"></path>` : '')
			+ `<text x="${cx}" y="${cy - 8}" text-anchor="middle" font-size="24" font-weight="500" fill="${known ? this.color(cls) : 'var(--ep-muted)'}">${known ? this.num(v) + '%' : '—'}</text>`
			+ `<text x="${cx}" y="${cy + 14}" text-anchor="middle" font-size="10.5" fill="var(--ep-muted)">${this.esc(sub || '')}</text></svg>`;
	},

	tiles(c) {
		const e = this.esc.bind(this);
		const { up, down, total } = this.servers(c);
		const es = c.families.es || { cpu: {}, mem: {} };
		const downHosts = c.servers.filter((s) => s.status === 'down').map((s) => s.host).join(', ');
		const tile = (h, body, extra = '') => `<section class="ep-ov-panel ep-ov-tile${extra}"><h4>${e(h)}</h4>${body}</section>`;
		return `<div class="ep-ov-tiles">`
			+ tile('Devices', `<div class="ep-ov-stat"><b style="color:var(--ep-blue)">${c.devices.purchased ?? '—'}</b><small>purchased · ${c.devices.seen === null || c.devices.seen === undefined ? 'seen not measured' : c.devices.seen + ' seen'}</small></div>`)
			+ tile('Servers up', `<div class="ep-ov-stat"><b style="color:var(--ep-ok)">${up}<small> / ${total}</small></b><small>${total - up - down} with no data</small></div>`)
			+ tile('Servers down', `<div class="ep-ov-stat"><b>${down}</b><small>${down ? e(downHosts) : 'none'}</small></div>`, down ? ' ep-ov-bad' : '')
			+ tile('ES CPU', this.gauge(es.cpu.usage, es.cpu.class, c.warn, c.high, es.cpu.allocated === null || es.cpu.allocated === undefined ? '' : `of ${this.num(es.cpu.allocated, 0)} cores`))
			+ tile('ES memory', this.gauge(es.mem.usage, es.mem.class, c.warn, c.high, es.mem.allocated === null || es.mem.allocated === undefined ? '' : `of ${this.bytes(es.mem.allocated)}`))
			+ tile('ES storage', this.gauge(c.storage.usage, c.storage.class, c.warn, c.high, c.storage.used === null ? 'not reported' : `${this.bytes(c.storage.used)} of ${this.bytes(c.storage.allocated)}`))
			+ `</div>`;
	},

	usedOfAllocated(list, families) {
		const e = this.esc.bind(this);
		const lcd = (v, cls, warn, high) => {
			const on = v === null || v === undefined ? 0 : Math.round(Math.min(100, v) / 4);
			let cells = '';
			for (let i = 0; i < 25; i++) {
				const at = (i + 1) * 4;
				const c = at > high ? 'var(--ep-high)' : at > warn ? 'var(--ep-warn)' : 'var(--ep-ok)';
				cells += `<i${i < on ? ` style="background:${c}"` : ''}></i>`;
			}
			return `<span class="ep-ov-lcd">${cells}</span>`;
		};
		const row = (label, v, cls, c) => `<div class="ep-ov-bg"><span>${e(label)}</span>${lcd(v, cls, c.warn, c.high)}<em style="color:${v === null || v === undefined ? 'var(--ep-faint)' : this.color(cls)}">${v === null || v === undefined ? '—' : this.num(v) + '%'}</em></div>`;
		const blocks = [];
		blocks.push(`<h5>ES storage</h5>` + list.map((c) => row(c.name, c.storage.usage, c.storage.class, c)).join(''));
		for (const f of families) {
			for (const [res, lab] of [['cpu', 'CPU'], ['mem', 'memory']]) {
				const rows = list.filter((c) => c.families[f.id] && c.families[f.id][res].allocated !== null && c.families[f.id][res].allocated !== undefined);
				if (!rows.length) continue;
				blocks.push(`<h5>${e(f.label)} ${lab}</h5>` + rows.map((c) => row(c.name, c.families[f.id][res].usage, c.families[f.id][res].class, c)).join(''));
			}
		}
		return `<section class="ep-ov-panel"><h4>Used of allocated</h4>${blocks.join('')}</section>`;
	},

	roles(list) {
		const e = this.esc.bind(this);
		const pal = ['#73bf69', '#fade2a', '#5794f2', '#b877d9', '#ff9830', '#8ab8ff', '#ffb357', '#ca95e5'];
		const all = [...new Set(list.flatMap((c) => c.servers.flatMap((s) => s.roles)))].sort();
		const colorOf = (r) => pal[all.indexOf(r) % pal.length];
		const one = (c) => {
			const counts = {};
			c.servers.forEach((s) => s.roles.forEach((r) => { counts[r] = (counts[r] || 0) + 1; }));
			const items = Object.entries(counts).sort((a, b) => b[1] - a[1]);
			const total = items.reduce((n, [, v]) => n + v, 0);
			if (!total) return `<div class="ep-ov-pie"><h5>${e(c.name)}</h5><div class="ep-empty">No servers</div></div>`;
			const C = 2 * Math.PI * 30;
			let off = 0;
			const arcs = items.map(([r, v]) => {
				const s = `<circle cx="42" cy="42" r="30" fill="none" stroke="${colorOf(r)}" stroke-width="16" stroke-dasharray="${(v / total * C).toFixed(2)} ${C.toFixed(2)}" stroke-dashoffset="${(-off / total * C).toFixed(2)}" transform="rotate(-90 42 42)"></circle>`;
				off += v;
				return s;
			}).join('');
			return `<div class="ep-ov-pie"><h5>${e(c.name)}</h5><div class="ep-ov-pie-b"><svg viewBox="0 0 84 84" role="img" aria-label="Servers by role, ${e(c.name)}">${arcs}<text x="42" y="47" text-anchor="middle" font-size="15" font-weight="500" fill="var(--ep-strong)">${c.servers.length}</text></svg>`
				+ `<ul>${items.map(([r, v]) => `<li><i style="background:${colorOf(r)}"></i><span>${e(r)}</span><b>${v}</b><em>${Math.round(v / total * 100)}%</em></li>`).join('')}</ul></div></div>`;
		};
		return `<section class="ep-ov-panel"><h4>Servers by role</h4><div class="ep-ov-pies">${list.map(one).join('')}</div></section>`;
	}
};
