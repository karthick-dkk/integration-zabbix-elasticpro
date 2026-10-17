/*
 * Client overview widget: one card per client — header, problems by severity, the metrics the
 * widget lists with their trend, used of allocated with its forecast, servers by role and
 * warnings — in the order and density the widget's settings choose, drawn from `ep_overview`
 * (WidgetView).
 *
 * Every figure arrives formatted, with its level, from the controller, which decided them with
 * the same thresholds as every other ElasticPro report; this only lays them out. A figure
 * nobody measured arrives as unknown and is drawn as "not collected" with the reason, never 0.
 * Every name is text, never markup; every link is one the controller made.
 */
window.EpOverview = {
	esc(s) {
		return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	},
	/** Only the Zabbix pages the controller links to: anything else is not a link. */
	href(url) {
		return typeof url === 'string' && /^(zabbix|history)\.php\?[^"'<>\s]*$/.test(url) ? url : null;
	},
	link(url, body, set, attrs = '') {
		const h = this.href(url);
		if (!h) return `<span${attrs}>${body}</span>`;
		return `<a href="${this.esc(h)}"${set && set.new_tab ? ' target="_blank" rel="noopener"' : ''}${attrs}>${body}</a>`;
	},
	levelColor(level, color) {
		if (color && /^#[0-9A-Fa-f]{6}$/.test(color)) return color;
		return level === 'high' ? 'var(--ep-high)' : level === 'warn' ? 'var(--ep-warn)' : 'var(--ep-ok)';
	},
	date(iso, withYear) {
		const d = new Date(`${iso}T00:00:00`);
		if (Number.isNaN(d.getTime())) return iso;
		const sameYear = d.getFullYear() === new Date().getFullYear();
		return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', ...(withYear || !sameYear ? { year: 'numeric' } : {}) });
	},

	init(widget, body, data) {
		const root = body.querySelector('.ep-panel[data-ep-panel="overview"]');
		const pane = root && root.querySelector('.ep-ov');
		if (!root || !pane || !data) return;
		// What the viewer typed in the toolbar survives the refresh.
		const st = widget._epOv || (widget._epOv = { q: '', type: '' });
		const q = root.querySelector('[data-ep-search]'), sel = root.querySelector('[data-ep-type-filter]');
		if (q) q.value = st.q;
		if (sel && [...sel.options].some((o) => o.value === st.type)) sel.value = st.type;
		const redraw = () => {
			st.q = q ? q.value : ''; st.type = sel ? sel.value : '';
			root.classList.toggle('ep-pt-pinned', st.q !== '' || st.type !== '');
			this.draw(pane, data, st);
		};
		if (q) q.addEventListener('input', redraw);
		if (sel) sel.addEventListener('change', redraw);
		redraw();
	},

	visible(data, st) {
		const q = (st.q || '').trim().toLowerCase();
		return data.clients.filter((c) => (!st.type || c.type === st.type)
			&& (!q || `${c.name} ${c.roles.flatMap((r) => r.hosts.map((h) => h.name)).join(' ')}`.toLowerCase().includes(q)));
	},

	draw(pane, data, st) {
		const set = data.settings || {};
		const list = this.visible(data, st);
		const sections = set.sections || [];
		let html = sections.includes('summary') ? this.summary(data, set) : '';
		if (!data.clients.length) {
			pane.innerHTML = html + `<div class="ep-empty">${data.total ? 'No client passes the widget\'s "Only show clients" setting.' : 'No client matches the widget\'s settings.'}</div>`;
			return;
		}
		if (!list.length) {
			pane.innerHTML = html + '<div class="ep-empty">No client matches the search or the type.</div>';
			return;
		}
		if (set.density === 2) {
			html += this.table(list, data, set);
		}
		else {
			const cols = set.columns > 0 ? `repeat(${set.columns}, minmax(0, 1fr))` : `repeat(auto-fill, minmax(min(100%, ${Math.max(200, set.card_width || 340)}px), 1fr))`;
			html += `<div class="ep-ov-grid${set.density === 1 ? ' ep-ov-compact' : ''}" style="grid-template-columns:${cols}">`
				+ list.map((c) => this.card(c, data, set)).join('') + '</div>';
		}
		pane.innerHTML = html;
	},

	summary(data, set) {
		const s = data.summary || {};
		const e = this.esc.bind(this);
		const sev = (s.problems || []).filter((p) => !set.hide_zero || p.n > 0)
			.map((p) => `<span class="ep-ov-chip"><i style="background:${p.n ? e(set.severities[p.severity].color) : 'var(--ep-track)'}"></i>${e(set.severities[p.severity].name)} ${p.n}</span>`).join('');
		return `<div class="ep-ov-summary"><span><b>${s.clients ?? 0}</b> clients</span><span><b>${s.servers ?? 0}</b> servers</span>`
			+ `<span class="${s.down ? 'ep-ov-bad-text' : ''}"><b>${s.down ?? 0}</b> down</span><span><b>${s.over ?? 0}</b> over a threshold</span>`
			+ `<span class="ep-ov-sev">${sev || '<span class="ep-ov-muted">No open problems</span>'}</span></div>`;
	},

	card(c, data, set) {
		const sections = (set.sections || []).filter((s) => s !== 'summary');
		const parts = sections.map((s) => {
			switch (s) {
				case 'problems': return this.problems(c, set);
				case 'metrics': return this.metrics(c, set);
				case 'used': return this.used(c, set);
				case 'roles': return this.roles(c, set);
				case 'warnings': return this.warnings(c);
				default: return '';
			}
		}).join('');
		return `<article class="ep-ov-card" data-ep-client="${this.esc(c.name)}">${this.header(c, set, sections.includes('header'))}${parts}</article>`;
	},

	header(c, set, full) {
		const e = this.esc.bind(this);
		const dot = c.worst !== null && c.worst !== undefined && set.severities[c.worst] ? set.severities[c.worst].color : 'var(--ep-ok)';
		let out = `<div class="ep-ov-head"><span class="ep-ov-dot" style="background:${e(dot)}"></span>`
			+ this.link(c.link, e(c.name), set, ' class="ep-ov-name"');
		if (full) {
			out += `<span class="ep-pill">${e(c.type)}</span>`;
			if (c.badge) out += `<span class="ep-ov-badge ep-ov-st-${e(c.status)}">${e(c.badge)}</span>`;
			out += '<span class="ep-ov-fill"></span>';
			if (set.contract_badge) out += this.contract(c.contract);
		}
		return out + '</div>';
	},

	contract(k) {
		if (!k) return '<span class="ep-ov-contract ep-ov-lv-none" title="Set the contract end date in Cluster Management">No contract date</span>';
		const text = k.days < 0 ? `Contract ended ${this.date(k.date)}`
			: k.level === 'ok' ? `Contract to ${this.date(k.date)}` : `Contract to ${this.date(k.date)} · ${k.days} d`;
		return `<span class="ep-ov-contract ep-ov-lv-${this.esc(k.level)}">${this.esc(text)}</span>`;
	},

	problems(c, set) {
		const e = this.esc.bind(this);
		const chips = c.problems.filter((p) => !set.hide_zero || p.n > 0).map((p) => {
			const s = set.severities[p.severity] || { name: String(p.severity), color: '#97AAB3' };
			const body = `<i style="background:${p.n ? e(s.color) : 'var(--ep-track)'}"></i>${e(s.name)} ${p.n}`;
			const attrs = ` class="ep-ov-chip${p.n ? '' : ' ep-ov-zero'}" aria-label="${e(s.name)} problems: ${p.n}"`;
			return set.link_severity ? this.link(p.url, body, set, attrs) : `<span${attrs}>${body}</span>`;
		}).join('');
		return `<div class="ep-ov-probs">${chips || '<span class="ep-ov-muted">No open problems</span>'}<span class="ep-ov-fill"></span>`
			+ this.link(c.problems_url, 'Problems', set) + this.link(c.dashboard, 'Dashboard', set) + '</div>';
	},

	metrics(c, set) {
		const e = this.esc.bind(this);
		if (!c.metrics.length) return '';
		const has = (k) => c.metrics.some((m) => k in m);
		const cols = ['avg', 'min', 'max'].filter(has);
		const head = `<tr><th scope="col">Metric</th><th scope="col">Now</th>${cols.map((k) => `<th scope="col">${k === 'avg' ? `${e(set.window)} avg` : k}</th>`).join('')}</tr>`;
		const rows = c.metrics.map((m) => {
			const name = this.link(m.url, e(m.name), set) + (m.custom ? '<span class="ep-ov-tag">custom</span>' : '')
				+ (m.window ? `<span class="ep-ov-tag" title="Trend over its own ${e(m.window)}">${e(m.window)}</span>` : '');
			const trend = cols.map((k) => {
				const t = m[k];
				if (!t) return '<td></td>';
				return `<td class="${t.level ? `ep-ov-tx-${e(t.level)}` : ''}">${e(t.text)}</td>`;
			}).join('');
			return `<tr><th scope="row">${name}</th><td>${this.now(m)}</td>${trend}</tr>`;
		}).join('');
		return `<table class="ep-ov-metrics"><thead>${head}</thead><tbody>${rows}</tbody></table>`;
	},

	/** The Now cell in the metric's display: as is, a bar, indicators or a sparkline. */
	now(m) {
		const e = this.esc.bind(this);
		const n = m.now;
		if (!n.known) {
			return `<span class="ep-ov-nc" title="${e(m.why || '')}">not collected</span>`;
		}
		const title = [n.stale && n.age !== null ? `no new value for ${Math.floor(n.age / 60)} min` : '',
			m.partial ? `from ${m.partial[0]} of ${m.partial[1]} servers` : ''].filter(Boolean).join(' · ');
		const tip = title ? ` title="${e(title)}"` : '';
		const flag = (n.stale ? '<sup class="ep-ov-stale" aria-label="stale">stale</sup>' : '')
			+ (m.partial ? `<sup class="ep-ov-part">${m.partial[0]}/${m.partial[1]}</sup>` : '');
		const color = this.levelColor(n.level, n.color);
		const value = n.level || n.color
			? `<span class="ep-ov-val ep-ov-lv-${e(n.level || 'custom')}"${n.color ? ` style="background:${e(n.color)}"` : ''}>${e(n.text)}</span>`
			: `<span class="ep-ov-val">${e(n.text)}</span>`;
		if (m.display === 2 && m.bar !== undefined && m.bar !== null) {
			return `<span class="ep-ov-barcell"${tip}><i><b style="width:${Number(m.bar)}%;background:${color}"></b></i>${value}${flag}</span>`;
		}
		if (m.display === 3 && m.bar !== undefined && m.bar !== null) {
			const on = Math.round(Number(m.bar) / 10);
			let cells = '';
			for (let i = 0; i < 10; i++) cells += `<i${i < on ? ` style="background:${color}"` : ''}></i>`;
			return `<span class="ep-ov-barcell"${tip}><span class="ep-ov-lcd">${cells}</span>${value}${flag}</span>`;
		}
		if (m.display === 4) {
			return `<span class="ep-ov-barcell"${tip}>${this.spark(m.spark || [], color)}${value}${flag}</span>`;
		}
		return `<span${tip}>${value}${flag}</span>`;
	},

	spark(points, color) {
		if (points.length < 2) return '<svg class="ep-ov-spark" viewBox="0 0 60 16" aria-hidden="true"></svg>';
		const lo = Math.min(...points), hi = Math.max(...points), span = hi - lo || 1;
		const xy = points.map((v, i) => `${(i / (points.length - 1) * 60).toFixed(1)},${(15 - (v - lo) / span * 14).toFixed(1)}`).join(' ');
		return `<svg class="ep-ov-spark" viewBox="0 0 60 16" role="img" aria-label="trend"><polyline points="${xy}" fill="none" stroke="${color}" stroke-width="1.4"></polyline></svg>`;
	},

	used(c, set) {
		const e = this.esc.bind(this);
		const u = c.used;
		if (!u) return '';
		let figure;
		if (!u.known) {
			figure = `<span class="ep-ov-nc">not collected</span>`;
		}
		else if (u.allocated === null) {
			figure = `${e(u.used)} used · allocated not set`;
		}
		else {
			const of = `${e(u.used)} of ${e(u.allocated)}`;
			figure = set.used_show === 1 ? e(u.pct_text) : set.used_show === 2 ? `${of} · ${e(u.pct_text)}` : of;
		}
		const color = u.pct === null ? 'var(--ep-track)' : this.levelColor(u.level === '' ? 'ok' : u.level);
		let bar = '';
		if (set.used_style === 0) {
			bar = `<div class="ep-ov-ubar"><b style="width:${u.pct === null ? 0 : Math.max(0, Math.min(100, u.pct))}%;background:${u.level === '' ? 'var(--ep-blue)' : color}"></b></div>`;
		}
		else if (set.used_style === 1) {
			bar = this.gauge(u.pct, u.level === '' ? 'var(--ep-blue)' : color);
		}
		let fc;
		if (u.forecast) {
			const f = u.forecast;
			const lv = f.level === 'bad' ? 'high' : f.level === 'warn' ? 'warn' : 'ok';
			const when = f.level === 'flat' ? 'Not growing' : `Full in about ${f.text}${f.date ? ` (${this.date(new Date(f.date * 1000).toISOString().slice(0, 10))})` : ''} at the ${set.forecast_days}-day growth rate`;
			fc = `<div class="ep-ov-fc ep-ov-tx-${lv}">${e(when)}</div>`;
		}
		else {
			fc = `<div class="ep-ov-fc ep-ov-muted">${e(u.why || '')}</div>`;
		}
		return `<div class="ep-ov-used"><div class="ep-ov-urow"><span class="ep-ov-muted">${e(u.label)}</span><span class="ep-ov-ufig">${figure}</span></div>${bar}${fc}</div>`;
	},

	gauge(pct, color) {
		const known = pct !== null && pct !== undefined;
		const f = known ? Math.max(0, Math.min(1, pct / 100)) : 0;
		const pt = (x, r) => { const a = Math.PI * (1 - x); return [30 + r * Math.cos(a), 30 - r * Math.sin(a)]; };
		const arc = (a, b, r) => { const [x0, y0] = pt(a, r), [x1, y1] = pt(b, r); return `M${x0.toFixed(2)} ${y0.toFixed(2)} A${r} ${r} 0 0 1 ${x1.toFixed(2)} ${y1.toFixed(2)}`; };
		return `<svg class="ep-ov-gauge" viewBox="0 0 60 34" role="img" aria-label="${known ? Math.round(pct) + ' %' : 'not reported'}">`
			+ `<path d="${arc(0, 1, 24)}" style="stroke:var(--ep-track)" stroke-width="8" fill="none"></path>`
			+ (f > 0 ? `<path d="${arc(0, f, 24)}" stroke="${color}" stroke-width="8" fill="none"></path>` : '') + '</svg>';
	},

	roles(c, set) {
		const e = this.esc.bind(this);
		const roles = c.roles || [];
		if (!roles.length) return `<div class="ep-ov-roles"><span class="ep-ov-muted">Servers</span><span class="ep-ov-muted">none with a role</span></div>`;
		const red = set.down_red;
		const pal = ['#5794f2', '#73bf69', '#b877d9', '#fade2a', '#ff9830', '#8ab8ff', '#ca95e5', '#96d98d'];
		if (set.roles_style === 1 || set.roles_style === 2) {
			const total = roles.reduce((n, r) => n + r.n, 0);
			const legend = roles.map((r, i) => `<li><i style="background:${pal[i % pal.length]}"></i>${e(r.label)} <b>${r.n}</b>${r.down ? ` <em class="${red ? 'ep-ov-bad-text' : ''}">${r.down} down</em>` : ''}</li>`).join('');
			let graphic;
			if (set.roles_style === 1) {
				graphic = '<div class="ep-ov-stack">' + roles.map((r, i) => `<span style="flex:${r.n};background:${pal[i % pal.length]}" title="${e(r.label)}: ${r.n}">`
					+ (r.down ? `<b style="width:${r.down / r.n * 100}%;background:${red ? 'var(--ep-high)' : 'var(--ep-faint)'}"></b>` : '') + '</span>').join('') + '</div>';
			}
			else {
				const C = 2 * Math.PI * 14;
				let off = 0;
				graphic = '<svg class="ep-ov-donut" viewBox="0 0 40 40" role="img" aria-label="Servers by role">' + roles.map((r, i) => {
					const s = `<circle cx="20" cy="20" r="14" fill="none" stroke="${pal[i % pal.length]}" stroke-width="8" stroke-dasharray="${(r.n / total * C).toFixed(2)} ${C.toFixed(2)}" stroke-dashoffset="${(-off / total * C).toFixed(2)}" transform="rotate(-90 20 20)"></circle>`;
					off += r.n;
					return s;
				}).join('') + `<text x="20" y="24" text-anchor="middle" font-size="10" fill="var(--ep-strong)">${c.servers.total}</text></svg>`;
			}
			return `<div class="ep-ov-roles ep-ov-roles-g">${graphic}<ul>${legend}</ul></div>`;
		}
		const names = (r, down) => r.hosts.filter((h) => h.down === down).map((h) => h.name).join(', ');
		const pills = roles.map((r) => {
			if (set.roles_servers === 2) {
				return r.hosts.map((h) => `<span class="ep-ov-rpill${h.down && red ? ' ep-ov-rdown' : ''}" title="${e(r.label)}${h.down ? ' — down' : ''}">${e(h.name)}</span>`).join('');
			}
			const hover = set.roles_servers === 1;
			const up = r.n - r.down;
			let out = up > 0 || !r.down ? `<span class="ep-ov-rpill"${hover ? ` title="${e(names(r, false))}"` : ''}>${e(r.label)} ${up}</span>` : '';
			if (r.down) out += `<span class="ep-ov-rpill${red ? ' ep-ov-rdown' : ''}"${hover ? ` title="${e(names(r, true))}"` : ''}>${e(r.label)} ${r.down} down</span>`;
			return out;
		}).join('');
		return `<div class="ep-ov-roles"><span class="ep-ov-muted">Servers</span>${pills}</div>`;
	},

	warnings(c) {
		if (!c.warnings || !c.warnings.length) return '';
		return '<ul class="ep-ov-warn">' + c.warnings.map((w) => `<li class="ep-ov-w-${this.esc(w.level)}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l9 16H3z"></path><path d="M12 10v4M12 17v.5"></path></svg><span>${this.esc(w.text)}</span></li>`).join('') + '</ul>';
	},

	/** Density "Table": one row per client. */
	table(list, data, set) {
		const e = this.esc.bind(this);
		const metrics = data.metrics || [];
		const sections = set.sections || [];
		const head = '<tr><th scope="col">Client</th>' + (sections.includes('header') ? '<th scope="col">Type</th>' : '')
			+ (sections.includes('problems') ? '<th scope="col">Problems</th>' : '')
			+ (sections.includes('metrics') ? metrics.map((m) => `<th scope="col">${e(m.name)}</th>`).join('') : '')
			+ (sections.includes('used') ? '<th scope="col">Used of allocated</th>' : '')
			+ (sections.includes('roles') ? '<th scope="col">Servers</th>' : '')
			+ (sections.includes('warnings') ? '<th scope="col">Warnings</th>' : '') + '</tr>';
		const rows = list.map((c) => {
			const dot = c.worst !== null && c.worst !== undefined && set.severities[c.worst] ? set.severities[c.worst].color : 'var(--ep-ok)';
			let r = `<tr><th scope="row"><span class="ep-ov-dot" style="background:${e(dot)}"></span>${this.link(c.link, e(c.name), set)}</th>`;
			if (sections.includes('header')) r += `<td>${e(c.type)}${c.badge ? ` <span class="ep-ov-badge">${e(c.badge)}</span>` : ''}</td>`;
			if (sections.includes('problems')) {
				r += '<td>' + c.problems.filter((p) => p.n > 0).map((p) => {
					const s = set.severities[p.severity];
					const body = `<i style="background:${e(s.color)}"></i>${p.n}`;
					return set.link_severity ? this.link(p.url, body, set, ` class="ep-ov-chip" title="${e(s.name)}"`) : `<span class="ep-ov-chip" title="${e(s.name)}">${body}</span>`;
				}).join('') + '</td>';
			}
			if (sections.includes('metrics')) {
				r += metrics.map((m) => {
					const row = c.metrics.find((x) => x.uid === m.uid);
					return `<td>${row ? this.now(row) : ''}</td>`;
				}).join('');
			}
			if (sections.includes('used')) {
				const u = c.used;
				r += `<td>${!u || !u.known ? '<span class="ep-ov-nc">not collected</span>' : u.pct_text === null ? `${e(u.used)} · allocated not set`
					: `<span class="ep-ov-tx-${e(u.level || 'ok')}">${e(u.pct_text)}</span>`}</td>`;
			}
			if (sections.includes('roles')) {
				r += `<td>${c.servers.total - c.servers.down} / ${c.servers.total}${c.servers.down ? ` <span class="${set.down_red ? 'ep-ov-bad-text' : ''}">${c.servers.down} down</span>` : ''}</td>`;
			}
			if (sections.includes('warnings')) {
				r += `<td>${c.warnings.length ? `<span title="${e(c.warnings.map((w) => w.text).join('\n'))}">${c.warnings.length}</span>` : ''}</td>`;
			}
			return r + '</tr>';
		}).join('');
		return `<div class="ep-ov-tablewrap"><table class="ep-ov-table"><thead>${head}</thead><tbody>${rows}</tbody></table></div>`;
	}
};
