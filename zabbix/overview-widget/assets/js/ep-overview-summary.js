/*
 * Client overview, Summary view — one summary card per client (servers up / down, devices, the
 * chosen family's CPU and memory, ES storage), a usage chart, "Used of allocated" and "Servers by
 * role" with a block per client; one client's tiles and gauges when a client is picked. Every
 * figure and every colour class comes from the controller (WidgetView::summary), decided with the
 * same thresholds as the other reports; this only lays them out.
 *
 * A widget saved before the Summary view had settings of its own (settings.legacy, or no
 * settings.cards at all) is drawn exactly as the 2.0 widget drew it: a summary panel, then one
 * row of Used of allocated and Servers by role. Otherwise the panels are laid out in the widget's
 * order and widths, the cards, Used of allocated and Servers by role follow their settings, and
 * the usage chart is drawn from data.chart.
 *
 * The global is EpOverviewSummary (the Cards view is EpOverview) and the viewer's choices are kept
 * under their own name on the widget. Every name is text, never markup; every colour that came
 * from the server is checked to be #RRGGBB before it is used. The chart's series colours are the
 * palette's (--ep-s1 … --ep-s8, set on the panel from ElasticPro → Settings), never listed here.
 */
window.EpOverviewSummary = {
	/** How many chart series colours the palette has (ElasticPro → Settings → Colours: --ep-s1 … --ep-s8). */
	SERIES: 8,

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
	/** A colour from the server, only when it is strictly #RRGGBB. */
	hex(c, fallback = '') {
		return typeof c === 'string' && /^#[0-9A-Fa-f]{6}$/.test(c) ? c : fallback;
	},
	/** The colour of the line of the client at place i among the widget's clients: the palette's series colour, by the viewer's theme. */
	seriesColor(i) {
		const n = Number.isInteger(i) && i >= 0 ? i : 0;
		return `var(--ep-s${(n % this.SERIES) + 1})`;
	},

	init(widget, body, data) {
		const root = body.querySelector('.ep-panel[data-ep-panel="overview"]');
		const pane = root && root.querySelector('.ep-ov');
		if (!root || !pane || !data) return;
		const set = data.settings || {};
		// What the viewer chose survives the refresh; the widget's Client setting is where it starts.
		const st = widget._epOvSummary || (widget._epOvSummary = { client: set.client || '', q: '', type: '' });
		// The chart's own choices: a metric and a period the viewer pressed, and the client isolated.
		const ch = st.chart || (st.chart = { metric: '', period: 0, iso: '', conf: null });
		if (data.chart) {
			// A chart reconfigured in the dashboard starts from the new setting, not from an old press.
			const conf = JSON.stringify(data.chart.configured || null);
			if (ch.conf !== conf) { ch.conf = conf; ch.metric = ''; ch.period = 0; }
		}
		const q = root.querySelector('[data-ep-search]'), sel = root.querySelector('[data-ep-type-filter]');
		if (q) q.value = st.q;
		if (sel && [...sel.options].some((o) => o.value === st.type)) sel.value = st.type;
		const redraw = (focus) => {
			st.q = q ? q.value : ''; st.type = sel ? sel.value : '';
			root.classList.toggle('ep-pt-pinned', st.q !== '' || st.type !== '');
			this.draw(root, pane, data, st);
			if (focus) {
				const again = [...pane.querySelectorAll('[data-ep-ov-iso]')].find((b) => b.dataset.epOvIso === focus);
				if (again && again.focus) again.focus();
			}
		};
		// The chart's metric and period are asked of the server: the widget refreshes with them.
		const refresh = () => { if (typeof widget._startUpdating === 'function') widget._startUpdating(); };
		if (q) q.addEventListener('input', () => redraw());
		if (sel) sel.addEventListener('change', () => redraw());
		pane.addEventListener('change', (e) => {
			if (e.target.matches('[data-ep-ov-client]')) { st.client = e.target.value; redraw(); }
			else if (e.target.matches('[data-ep-ov-metric]')) { ch.metric = e.target.value; refresh(); }
		});
		pane.addEventListener('click', (e) => {
			const period = e.target.closest('[data-ep-ov-period]');
			if (period) {
				const s = Number(period.dataset.epOvPeriod);
				if (Number.isInteger(s) && s > 0) { ch.period = s; refresh(); }
				return;
			}
			const iso = e.target.closest('[data-ep-ov-iso]');
			if (iso) {
				// Click a client to show only it; click it again for all.
				ch.iso = ch.iso === iso.dataset.epOvIso ? '' : iso.dataset.epOvIso;
				redraw(iso.tagName === 'BUTTON' ? iso.dataset.epOvIso : '');
				return;
			}
			const card = e.target.closest('[data-ep-ov-pick]');
			if (card) { st.client = card.dataset.epOvPick; redraw(); }
		});
		redraw();
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
		const set = data.settings || {};
		const fam = data.families.find((f) => f.id === set.family) || data.families[0] || { id: 'es', label: 'ES' };
		if (set.legacy || !set.cards) {
			this.drawLegacy(pane, data, st, list, picker, set, fam);
			return;
		}
		const widthOf = (w) => ['full', 'half', 'third', 'quarter'].includes(w) ? w : 'full';
		const cards = set.cards;
		const columns = [2, 3, 4].includes(cards.columns) ? ` style="grid-template-columns:repeat(${cards.columns},minmax(0,1fr))"` : '';
		const panels = (set.layout || []).map(({ id, width }) => {
			const cls = ` ep-ov-w-${widthOf(width)}`;
			switch (id) {
				case 'summary':
					return st.client ? this.tiles(list[0], fam, cls)
						: `<section class="ep-ov-panel${cls}"><h4>Summary by client <small>select a client to open its own view</small></h4><div class="ep-ov-cards"${columns}>${list.map((c) => this.card(c, fam, cards)).join('')}</div></section>`;
				case 'chart': return data.chart ? this.chartPanel(data.chart, set, st, list, cls) : '';
				case 'used': return this.usedOfAllocated(list, data.families, set.used, cls);
				case 'roles': return this.roles(list, set.roles, cls);
				default: return '';
			}
		}).join('');
		pane.innerHTML = picker + (panels ? `<div class="ep-ov-lay">${panels}</div>` : '<div class="ep-empty">Every panel is turned off in this widget\'s settings.</div>');
	},

	/** A widget saved before the Summary view had settings of its own: drawn as it always was. */
	drawLegacy(pane, data, st, list, picker, set, fam) {
		const panels = set.panels || { summary: true, used: true, roles: true };
		const summary = !panels.summary ? '' : st.client ? this.tiles(list[0], fam) : `<section class="ep-ov-panel ep-ov-wide"><h4>Summary by client <small>select a client to open its own view</small></h4><div class="ep-ov-cards">${list.map((c) => this.card(c, fam)).join('')}</div></section>`;
		const row = (panels.used ? this.usedOfAllocated(list, data.families) : '') + (panels.roles ? this.roles(list) : '');
		pane.innerHTML = picker + summary + (row ? `<div class="ep-ov-row">${row}</div>` : '')
			+ (!summary && !row ? '<div class="ep-empty">Every panel is turned off in this widget\'s settings.</div>' : '');
	},

	servers(c) {
		const up = c.servers.filter((s) => s.status === 'up').length;
		const down = c.servers.filter((s) => s.status === 'down').length;
		return { up, down, total: c.servers.length };
	},

	/** The status line of a card: the counts the widget asks for, or OK when there is nothing to say. */
	statusLine(c, items) {
		const names = { down: 'down', high: 'high', warn: 'warn', short: 'short', nodata: 'no data' };
		const counts = c.status || {};
		const said = items.filter((k) => names[k] && Number(counts[k]) > 0);
		const level = said.some((k) => k === 'down' || k === 'high') ? 'high' : said.some((k) => k === 'warn' || k === 'short') ? 'warn' : said.length ? 'muted' : 'ok';
		const text = said.length ? said.map((k) => `${Number(counts[k])} ${names[k]}`).join(' · ') : 'OK';
		return `<span class="ep-ov-st ep-ov-st-${level}">${this.esc(text)}</span>`;
	},

	card(c, fam, cards) {
		const e = this.esc.bind(this);
		const { up, down, total } = this.servers(c);
		const es = c.families[fam.id] || null;
		const opt = cards || {};
		const tiles = opt.tiles || ['up', 'down', 'devices'];
		const bars = opt.bars || ['cpu', 'mem', 'storage'];
		const status = opt.status || [];
		const bar = (label, v, cls) => `<div class="ep-ov-m"><span>${e(label)}</span><i><b style="width:${v === null || v === undefined ? 0 : Math.max(0, Math.min(100, v))}%;background:${this.color(cls)}"></b></i><em style="color:${v === null || v === undefined ? 'var(--ep-faint)' : this.color(cls)}">${v === null || v === undefined ? '—' : this.num(v) + '%'}</em></div>`;
		const tile = {
			up: `<span><b style="color:var(--ep-ok)">${up}<small> / ${total}</small></b><small>servers up</small></span>`,
			down: `<span><b style="color:${down ? 'var(--ep-high)' : 'var(--ep-ok)'}">${down}</b><small>down</small></span>`,
			devices: `<span><b style="color:var(--ep-blue)">${c.devices.purchased ?? '—'}</b><small>devices</small></span>`,
			problems: `<span><b style="color:${c.problems ? 'var(--ep-high)' : c.problems === 0 ? 'var(--ep-ok)' : 'var(--ep-faint)'}">${c.problems === null || c.problems === undefined ? '—' : Number(c.problems)}</b><small>open problems</small></span>`
		};
		const shown = tiles.filter((k) => tile[k]);
		return `<button type="button" class="ep-ov-card" data-ep-ov-pick="${e(c.name)}" title="Open ${e(c.name)}">`
			+ `<span class="ep-ov-card-h"><b>${e(c.name)}</b><span class="ep-pill">${e(c.type)}</span>${c.badge ? `<span class="ep-ov-badge">${e(c.badge)}</span>` : ''}${status.length ? this.statusLine(c, status) : ''}</span>`
			+ (shown.length ? `<span class="ep-ov-trio"${shown.length === 3 ? '' : ` style="grid-template-columns:repeat(${shown.length},1fr)"`}>${shown.map((k) => tile[k]).join('')}</span>` : '')
			+ (es && bars.includes('cpu') ? bar(`${fam.label} CPU`, es.cpu.usage, es.cpu.class) : '')
			+ (es && bars.includes('mem') ? bar(`${fam.label} memory`, es.mem.usage, es.mem.class) : '')
			+ (bars.includes('storage') ? bar('ES storage', c.storage.usage, c.storage.class) : '') + `</button>`;
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

	tiles(c, fam, cls = '') {
		const e = this.esc.bind(this);
		const { up, down, total } = this.servers(c);
		const es = c.families[fam.id] || { cpu: {}, mem: {} };
		const downHosts = c.servers.filter((s) => s.status === 'down').map((s) => s.host).join(', ');
		const tile = (h, body, extra = '') => `<section class="ep-ov-panel ep-ov-tile${extra}"><h4>${e(h)}</h4>${body}</section>`;
		return `<div class="ep-ov-tiles${cls}">`
			+ tile('Devices', `<div class="ep-ov-stat"><b style="color:var(--ep-blue)">${c.devices.purchased ?? '—'}</b><small>purchased · ${c.devices.seen === null || c.devices.seen === undefined ? 'seen not measured' : c.devices.seen + ' seen'}</small></div>`)
			+ tile('Servers up', `<div class="ep-ov-stat"><b style="color:var(--ep-ok)">${up}<small> / ${total}</small></b><small>${total - up - down} with no data</small></div>`)
			+ tile('Servers down', `<div class="ep-ov-stat"><b>${down}</b><small>${down ? e(downHosts) : 'none'}</small></div>`, down ? ' ep-ov-bad' : '')
			+ tile(`${fam.label} CPU`, this.gauge(es.cpu.usage, es.cpu.class, c.warn, c.high, es.cpu.allocated === null || es.cpu.allocated === undefined ? '' : `of ${this.num(es.cpu.allocated, 0)} cores`))
			+ tile(`${fam.label} memory`, this.gauge(es.mem.usage, es.mem.class, c.warn, c.high, es.mem.allocated === null || es.mem.allocated === undefined ? '' : `of ${this.bytes(es.mem.allocated)}`))
			+ tile('ES storage', this.gauge(c.storage.usage, c.storage.class, c.warn, c.high, c.storage.used === null ? 'not reported' : `${this.bytes(c.storage.used)} of ${this.bytes(c.storage.allocated)}`))
			+ `</div>`;
	},

	/**
	 * Used of allocated: a block per resource, a row per client. Without settings (a widget saved
	 * before them): ES storage, then each family's CPU and memory, as it always was. With them:
	 * the groups ticked, CPU then memory then storage then devices, rows by name or by value, and
	 * a segmented or a solid bar.
	 */
	usedOfAllocated(list, families, ua, cls = '') {
		const e = this.esc.bind(this);
		const set = ua || null;
		const segmented = (v, warn, high) => {
			const on = v === null || v === undefined ? 0 : Math.round(Math.min(100, v) / 4);
			let cells = '';
			for (let i = 0; i < 25; i++) {
				const at = (i + 1) * 4;
				const c = at > high ? 'var(--ep-high)' : at > warn ? 'var(--ep-warn)' : 'var(--ep-ok)';
				cells += `<i${i < on ? ` style="background:${c}"` : ''}></i>`;
			}
			return `<span class="ep-ov-lcd">${cells}</span>`;
		};
		const solid = (v, cl) => `<span class="ep-ov-solid"><b style="width:${v === null || v === undefined ? 0 : Math.max(0, Math.min(100, v))}%;background:${this.color(cl)}"></b></span>`;
		const known = (v) => v !== null && v !== undefined;
		const row = (label, v, cl, c) => `<div class="ep-ov-bg"><span>${e(label)}</span>${set && set.bar === 'solid' ? solid(v, cl) : segmented(v, c.warn, c.high)}<em style="color:${known(v) ? this.color(cl) : 'var(--ep-faint)'}">${known(v) ? this.num(v) + '%' : '—'}</em></div>`;
		const rowsOf = (rows) => {
			const ordered = set && set.sort === 'value'
				? [...rows].sort((a, b) => (known(b.v) - known(a.v)) || ((b.v ?? 0) - (a.v ?? 0)) || a.c.name.localeCompare(b.c.name, undefined, { numeric: true }))
				: rows;
			return ordered.map((r) => row(r.c.name, r.v, r.cl, r.c)).join('');
		};
		const famBlocks = (res, lab) => families.flatMap((f) => {
			const rows = list.filter((c) => c.families[f.id] && known(c.families[f.id][res].allocated))
				.map((c) => ({ c, v: c.families[f.id][res].usage, cl: c.families[f.id][res].class }));
			return rows.length ? [`<h5>${e(f.label)} ${lab}</h5>` + rowsOf(rows)] : [];
		});
		const storage = () => [`<h5>ES storage</h5>` + rowsOf(list.map((c) => ({ c, v: c.storage.usage, cl: c.storage.class })))];
		const blocks = [];
		if (!set) {
			blocks.push(...storage());
			for (const f of families) {
				for (const [res, lab] of [['cpu', 'CPU'], ['mem', 'memory']]) {
					const rows = list.filter((c) => c.families[f.id] && known(c.families[f.id][res].allocated));
					if (!rows.length) continue;
					blocks.push(`<h5>${e(f.label)} ${lab}</h5>` + rows.map((c) => row(c.name, c.families[f.id][res].usage, c.families[f.id][res].class, c)).join(''));
				}
			}
		}
		else {
			const groups = set.groups || [];
			if (groups.includes('cpu')) blocks.push(...famBlocks('cpu', 'CPU'));
			if (groups.includes('mem')) blocks.push(...famBlocks('mem', 'memory'));
			if (groups.includes('storage')) blocks.push(...storage());
			if (groups.includes('devices')) blocks.push(`<h5>Devices seen of purchased</h5>` + rowsOf(list.map((c) => ({ c, v: c.devices.usage, cl: c.devices.class || '' }))));
		}
		return `<section class="ep-ov-panel${cls}"><h4>Used of allocated</h4>${blocks.join('') || '<div class="ep-empty">No group is turned on.</div>'}</section>`;
	},

	/** Servers by role: a donut (as it always was), a stacked bar or pills, with a count and a percent as asked. */
	roles(list, sr, cls = '') {
		const e = this.esc.bind(this);
		const pal = ['#73bf69', '#fade2a', '#5794f2', '#b877d9', '#ff9830', '#8ab8ff', '#ffb357', '#ca95e5'];
		const all = [...new Set(list.flatMap((c) => c.servers.flatMap((s) => s.roles)))].sort();
		const colorOf = (r) => pal[all.indexOf(r) % pal.length];
		const opt = sr || { style: 'donut', count: true, percent: true };
		const legend = (items, total) => `<ul${sr ? ' class="ep-ov-lg"' : ''}>${items.map(([r, v]) => `<li><i style="background:${colorOf(r)}"></i><span>${e(r)}</span>`
			+ (opt.count ? `<b>${v}</b>` : '') + (opt.percent ? `<em>${Math.round(v / total * 100)}%</em>` : '') + `</li>`).join('')}</ul>`;
		const one = (c) => {
			const counts = {};
			c.servers.forEach((s) => s.roles.forEach((r) => { counts[r] = (counts[r] || 0) + 1; }));
			const items = Object.entries(counts).sort((a, b) => b[1] - a[1]);
			const total = items.reduce((n, [, v]) => n + v, 0);
			if (!total) return `<div class="ep-ov-pie"><h5>${e(c.name)}</h5><div class="ep-empty">No servers</div></div>`;
			if (opt.style === 'bar') {
				return `<div class="ep-ov-pie"><h5>${e(c.name)} <small>${c.servers.length} servers</small></h5><div class="ep-ov-rbar" role="img" aria-label="Servers by role, ${e(c.name)}">`
					+ items.map(([r, v]) => `<span style="flex:${v};background:${colorOf(r)}" title="${e(r)}: ${v}"></span>`).join('') + `</div>${legend(items, total)}</div>`;
			}
			if (opt.style === 'pills') {
				return `<div class="ep-ov-pie"><h5>${e(c.name)} <small>${c.servers.length} servers</small></h5><div class="ep-ov-rpills">`
					+ items.map(([r, v]) => `<span class="ep-ov-rp"><i style="background:${colorOf(r)}"></i>${e(r)}${opt.count ? ` <b>${v}</b>` : ''}${opt.percent ? ` <em>${Math.round(v / total * 100)}%</em>` : ''}</span>`).join('') + `</div></div>`;
			}
			const C = 2 * Math.PI * 30;
			let off = 0;
			const arcs = items.map(([r, v]) => {
				const s = `<circle cx="42" cy="42" r="30" fill="none" stroke="${colorOf(r)}" stroke-width="16" stroke-dasharray="${(v / total * C).toFixed(2)} ${C.toFixed(2)}" stroke-dashoffset="${(-off / total * C).toFixed(2)}" transform="rotate(-90 42 42)"></circle>`;
				off += v;
				return s;
			}).join('');
			return `<div class="ep-ov-pie"><h5>${e(c.name)}</h5><div class="ep-ov-pie-b"><svg viewBox="0 0 84 84" role="img" aria-label="Servers by role, ${e(c.name)}">${arcs}<text x="42" y="47" text-anchor="middle" font-size="15" font-weight="500" fill="var(--ep-strong)">${c.servers.length}</text></svg>`
				+ legend(items, total) + `</div></div>`;
		};
		return `<section class="ep-ov-panel${cls}"><h4>Servers by role</h4><div class="ep-ov-pies">${list.map(one).join('')}</div></section>`;
	},

	/* ------------------------------------ usage chart ------------------------------------ */

	/** A value on the chart's axis, in the metric's units. */
	axis(v, units) {
		if (units === '%') return `${this.num(v, 0)}%`;
		if (units === 'B' || units === 'Bps') return this.bytes(v) + (units === 'Bps' ? '/s' : '');
		const a = Math.abs(v);
		const [d, s] = a >= 1e9 ? [1e9, 'G'] : a >= 1e6 ? [1e6, 'M'] : a >= 1e3 ? [1e3, 'k'] : [1, ''];
		const text = this.num(v / d, d === 1 ? 2 : 1) + s;
		return units ? (units[0] === '/' ? text + units : `${text} ${units}`) : text;
	},
	/** A duration in seconds as the chart's time axis words it: 90m, 6h, 7d. */
	span(s) {
		if (s % 86400 === 0) return `${s / 86400}d`;
		if (s >= 7200) return `${Math.round(s / 360) / 10}h`;
		return `${Math.round(s / 60)}m`;
	},
	/** The top of a value axis: a round number a little above the data. */
	niceMax(max) {
		if (!(max > 0)) return 1;
		const p = 10 ** Math.floor(Math.log10(max * 1.05));
		const f = max * 1.05 / p;
		return p * (f <= 1 ? 1 : f <= 2 ? 2 : f <= 4 ? 4 : f <= 5 ? 5 : f <= 8 ? 8 : 10);
	},
	/** The pieces of a line between its gaps: a gap is a stretch much longer than the points' usual step. */
	pieces(points) {
		if (points.length < 2) return [points];
		const steps = points.slice(1).map((p, i) => p[0] - points[i][0]).sort((a, b) => a - b);
		const med = steps[Math.floor(steps.length / 2)] || 0;
		const out = [[points[0]]];
		for (let i = 1; i < points.length; i++) {
			if (med > 0 && points[i][0] - points[i - 1][0] > med * 3) out.push([]);
			out[out.length - 1].push(points[i]);
		}
		return out;
	},
	/** Each client's cumulative line for a stacked area: at every time any client has a point, the clients with a point there add up. */
	stack(rows) {
		const clocks = [...new Set(rows.flatMap((r) => r.points.map((p) => p[0])))].sort((a, b) => a - b);
		const at = rows.map((r) => new Map(r.points));
		const sum = clocks.map(() => 0);
		return rows.map((r, k) => {
			const lo = sum.slice();
			clocks.forEach((t, i) => { if (at[k].has(t)) sum[i] += at[k].get(t); });
			return { row: r, clocks, lo, hi: sum.slice(), has: clocks.map((t) => at[k].has(t)) };
		});
	},

	/** The chart itself, as SVG. `rows` are the clients drawn: {name, i, points}. */
	chartSvg(chart, set, rows) {
		const cs = set.chart || {};
		const W = 620, H = Math.max(120, Number(cs.height) || 230), L = 48, R = 8, T = 8, B = 22;
		const pw = W - L - R, ph = H - T - B;
		const t0 = chart.period.from, t1 = chart.period.till;
		const x = (t) => L + (t1 > t0 ? (Math.min(t1, Math.max(t0, t)) - t0) / (t1 - t0) : 0) * pw;
		const stacked = cs.style === 'stacked';
		const stacks = stacked ? this.stack(rows) : null;
		const values = stacked ? stacks.flatMap((s) => s.hi) : rows.flatMap((r) => r.points.map((p) => p[1]));
		const lines = [['warn', chart.lines && chart.lines.warn], ['high', chart.lines && chart.lines.high]].filter(([, v]) => typeof v === 'number');
		let lo = 0, hi;
		if (chart.percent && !stacked) {
			// Stacked percentages add up past 100, so a stacked chart always scales to its data.
			hi = 100;
		}
		else {
			const top = values.length ? Math.max(...values) : 1;
			const bottom = values.length ? Math.min(...values) : 0;
			// A line far above the data would flatten it; it is left off then, not squeezed in.
			const near = lines.filter(([, v]) => v <= Math.max(top, 0) * 3).map(([, v]) => v);
			hi = this.niceMax(Math.max(top, ...near, 0));
			if (bottom < 0) lo = -this.niceMax(-bottom);
		}
		if (!(hi > lo)) hi = lo + 1;
		const y = (v) => T + ph - (Math.min(hi, Math.max(lo, v)) - lo) / (hi - lo) * ph;
		const f = (n) => n.toFixed(1);
		const e = this.esc.bind(this);
		let grid = '', labels = '';
		for (let i = 0; i <= 4; i++) {
			const v = lo + (hi - lo) * i / 4, yy = y(v);
			grid += `<line x1="${L}" y1="${f(yy)}" x2="${W - R}" y2="${f(yy)}" style="stroke:var(--ep-line-2)"></line>`;
			labels += `<text x="${L - 5}" y="${f(yy + 3.5)}" text-anchor="end">${e(this.axis(v, chart.metric.units))}</text>`;
		}
		const seconds = chart.period.seconds;
		const ticks = [[L, 'start', `−${this.span(seconds)}`], [L + pw / 2, 'middle', `−${this.span(Math.round(seconds / 2))}`], [W - R, 'end', 'now']];
		const xaxis = ticks.map(([tx, anchor, label]) => `<text x="${f(tx)}" y="${H - 6}" text-anchor="${anchor}" font-size="10">${e(label)}</text>`).join('');
		const guide = lines.filter(([, v]) => v >= lo && v <= hi).map(([k, v]) => `<line x1="${L}" y1="${f(y(v))}" x2="${W - R}" y2="${f(y(v))}" stroke-dasharray="5 4" style="stroke:var(--ep-${k})"><title>${k === 'warn' ? 'Warn' : 'High'} ${e(this.axis(v, chart.metric.units))}</title></line>`).join('');
		let series = '';
		if (stacked) {
			series = stacks.map((s) => {
				const c = this.seriesColor(s.row.i), iso = ` data-ep-ov-iso="${e(s.row.name)}"`;
				const idx = s.clocks.map((t, i) => i);
				const top = idx.map((i) => `${f(x(s.clocks[i]))},${f(y(s.hi[i]))}`);
				const base = idx.slice().reverse().map((i) => `${f(x(s.clocks[i]))},${f(y(s.lo[i]))}`);
				return s.row.points.length ? `<polygon points="${top.concat(base).join(' ')}" fill="${c}" fill-opacity=".55" stroke="${c}" stroke-width="1"${iso} style="cursor:pointer"><title>${e(s.row.name)}</title></polygon>` : '';
			}).join('');
		}
		else {
			series = rows.map((r) => {
				const c = this.seriesColor(r.i), iso = ` data-ep-ov-iso="${e(r.name)}"`;
				return this.pieces(r.points).map((seg) => {
					if (!seg.length) return '';
					if (seg.length === 1) return `<circle cx="${f(x(seg[0][0]))}" cy="${f(y(seg[0][1]))}" r="2.5" fill="${c}"${iso} style="cursor:pointer"><title>${e(r.name)}</title></circle>`;
					const pts = seg.map((p) => `${f(x(p[0]))},${f(y(p[1]))}`).join(' ');
					const area = cs.style === 'area' ? `<polygon points="${f(x(seg[0][0]))},${f(y(Math.max(lo, 0)))} ${pts} ${f(x(seg[seg.length - 1][0]))},${f(y(Math.max(lo, 0)))}" fill="${c}" fill-opacity=".14" stroke="none"${iso} style="cursor:pointer"></polygon>` : '';
					return `${area}<polyline points="${pts}" fill="none" stroke="${c}" stroke-width="2" stroke-linejoin="round"${iso} style="cursor:pointer"><title>${e(r.name)}</title></polyline>`;
				}).join('');
			}).join('');
		}
		const label = `${chart.metric.label} for each client, last ${this.span(seconds)}`;
		return `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${e(label)}" class="ep-ov-chart-svg">`
			+ `<g font-size="11" style="fill:var(--ep-muted)">${labels}${xaxis}</g>${grid}${guide}${series}</svg>`;
	},

	/** The usage chart's panel: its title, the period buttons and metric switch, the chart, and the legend table. */
	chartPanel(chart, set, st, list, cls = '') {
		const e = this.esc.bind(this);
		const cs = set.chart || {};
		const names = new Set(list.map((c) => c.name));
		const shown = chart.clients.filter((c) => names.has(c.name));
		const ch = st.chart || {};
		const iso = cs.isolate !== false && ch.iso && shown.some((c) => c.name === ch.iso) ? ch.iso : '';
		const drawn = shown.filter((c) => c.points.length && (!iso || c.name === iso));
		const quick = (chart.quick || []).map((q) => `<button type="button" data-ep-ov-period="${Number(q.seconds)}" aria-pressed="${chart.period.key === q.key ? 'true' : 'false'}">${e(q.key)}</button>`).join('');
		const metrics = chart.metrics || [];
		const select = metrics.length > 1
			? `<select data-ep-ov-metric aria-label="Chart metric">${metrics.map((m) => `<option value="${e(m.id)}"${m.id === chart.metric.id ? ' selected' : ''}>${e(m.label)}</option>`).join('')}</select>` : '';
		const cols = cs.legend || ['mean', 'min', 'max', 'last'];
		const head = { mean: 'Mean', min: 'Min', max: 'Max', last: 'Last' };
		const cell = (v) => {
			if (!v) return '<td>—</td>';
			const col = cs.color === false ? '' : this.hex(v.color, v.level === 'high' ? 'var(--ep-high)' : v.level === 'warn' ? 'var(--ep-warn)' : '');
			return `<td${col ? ` style="color:${col}"` : ''}>${e(v.text)}</td>`;
		};
		const rows = shown.map((c) => {
			const pressed = iso === c.name;
			const name = `<span class="ep-ov-sw" style="background:${this.seriesColor(c.i)}"></span>${e(c.name)}`;
			const who = cs.isolate === false ? `<span>${name}</span>`
				: `<button type="button" class="ep-ov-iso" data-ep-ov-iso="${e(c.name)}" aria-pressed="${pressed ? 'true' : 'false'}" title="${pressed ? 'Show every client' : 'Show only ' + e(c.name)}">${name}</button>`;
			return `<tr${iso && !pressed ? ' class="ep-ov-dim"' : ''}><th scope="row">${who}</th>`
				+ (c.legend ? cols.map((k) => cell(c.legend[k])).join('') : `<td colspan="${cols.length}" class="ep-ov-nodata">no data</td>`) + `</tr>`;
		}).join('');
		const lines = chart.lines || {};
		const dashed = [typeof lines.warn === 'number' ? `Warn ${this.axis(lines.warn, chart.metric.units)}` : '', typeof lines.high === 'number' ? `High ${this.axis(lines.high, chart.metric.units)}` : ''].filter(Boolean);
		const note = [dashed.length ? `Dashed lines: ${dashed.join(' and ')}.` : '',
			cs.isolate === false ? '' : 'Click a client to show only it; click again for all.',
			chart.total > chart.clients.length ? `Showing ${chart.clients.length} of ${chart.total} clients.` : '',
			`Points: ${cs.point || 'average'} per ${chart.period.source === 'history' ? 'step, from Zabbix history' : 'hour, from Zabbix trends'}.`].filter(Boolean).join(' ');
		const body = shown.length
			? (drawn.length ? this.chartSvg(chart, set, drawn) : '<div class="ep-empty">No data in this period.</div>')
				+ `<table class="ep-ov-legend"><thead><tr><th scope="col">Client</th>${cols.map((k) => `<th scope="col">${head[k]}</th>`).join('')}</tr></thead><tbody>${rows}</tbody></table>`
			: '<div class="ep-empty">No client to draw.</div>';
		return `<section class="ep-ov-panel ep-ov-chart${cls}"><div class="ep-ov-chart-h"><h4>${e(chart.metric.label)} by client</h4><span class="ep-ov-fill"></span>`
			+ (quick ? `<div class="ep-ov-seg" role="group" aria-label="Chart period">${quick}</div>` : '') + select + `</div>${body}<small class="ep-ov-note">${e(note)}</small></section>`;
	}
};
