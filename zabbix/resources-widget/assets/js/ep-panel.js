/*
 * The panel controls for Client resources and Client capacity: search, sorting, column widths,
 * the ⋮ menu and Table settings. ep-columns.js keeps the Type filter, expanding and the Columns
 * dialog; this works alongside it and never changes a figure.
 *
 * What a viewer does here outlives the widget's refresh. Zabbix replaces the widget's contents
 * every minute, so the search, the sort and the open clients are held on the widget object and
 * put back after each redraw; colours, row height, widths and the server columns are kept per
 * browser (localStorage), because they are one person's preference, not a dashboard setting.
 *
 * Shared: sync-assets.mjs copies this into the widgets that use it. Edit it here.
 */
window.EpPanel = {
	DEFAULTS: { colors: { ok: '#73bf69', warn: '#ff9830', high: '#f2495c' }, density: 'compact', hiddenSrv: [], widths: {} },
	SERVER_COLUMNS: [['role', 'Role'], ['host', 'Host'], ['ip', 'IP'], ['status', 'Status'], ['cores', 'CPU cores'], ['cpu', 'CPU used'],
		['mem', 'Memory'], ['memu', 'Memory used'], ['disks', 'Disks']],

	prefs(kind) {
		const p = JSON.parse(JSON.stringify(this.DEFAULTS));
		try {
			const v = JSON.parse(localStorage.getItem('ep-panel:' + kind) || 'null');
			if (v && typeof v === 'object') Object.assign(p, v, { colors: { ...p.colors, ...(v.colors || {}) } });
		}
		catch (_) { /* a private window: defaults */ }
		// What comes back from storage is checked before it is drawn: colours must be hex, widths
		// numbers, the rest one of the offered values. Anything else falls back to the default.
		for (const k of ['ok', 'warn', 'high']) {
			if (!/^#[0-9a-f]{6}$/i.test(String(p.colors[k]))) p.colors[k] = this.DEFAULTS.colors[k];
		}
		if (!['compact', 'comfortable'].includes(p.density)) p.density = this.DEFAULTS.density;
		const known = new Set(this.SERVER_COLUMNS.map(([k]) => k));
		p.hiddenSrv = Array.isArray(p.hiddenSrv) ? p.hiddenSrv.filter((k) => known.has(k)) : [];
		const widths = {};
		for (const [key, w] of Object.entries(p.widths && typeof p.widths === 'object' ? p.widths : {})) {
			if (Array.isArray(w) && w.every((n) => Number.isFinite(n) && n > 0 && n < 5000)) widths[key] = w.map((n) => Math.round(n));
		}
		p.widths = widths;
		return p;
	},
	savePrefs(kind, p) {
		try { localStorage.setItem('ep-panel:' + kind, JSON.stringify(p)); } catch (_) { /* not kept, still applied */ }
	},

	init(widget, body, kind) {
		const root = body.querySelector('.ep-panel');
		if (!root) return;
		const st = widget._epPanel || (widget._epPanel = { q: '', type: '', sort: null, open: [] });
		const prefs = this.prefs(kind);
		const table = root.querySelector('table.ep-res-table, table.ep-cap-table');
		const ctx = { widget, body, root, table, kind, st, prefs };

		this.applyPrefs(ctx);

		// The Type filter and the search, back where the viewer left them.
		const sel = root.querySelector('[data-ep-type-filter]');
		if (sel && st.type && [...sel.options].some((o) => o.value === st.type)) sel.value = st.type;
		if (sel) sel.addEventListener('change', () => { st.type = sel.value; this.filter(ctx); });
		const q = root.querySelector('[data-ep-search]');
		if (q) {
			q.value = st.q;
			q.addEventListener('input', () => { st.q = q.value; this.filter(ctx); });
		}

		// Open clients, back open; and remembered as they are opened and closed.
		for (const id of st.open) {
			const t = root.querySelector(`[data-ep-expand="${CSS.escape(id)}"]`);
			if (t && window.EpColumns) window.EpColumns.toggle(body, id, t, true);
		}
		root.addEventListener('click', (e) => {
			if (e.target.closest('[data-ep-expand], [data-ep-expand-all]')) {
				setTimeout(() => {
					st.open = [...root.querySelectorAll('[data-ep-expand][aria-expanded="true"]')].map((t) => t.dataset.epExpand);
					this.filter(ctx);
				}, 0);
			}
		});

		if (table) {
			this.sortable(ctx);
			if (st.sort) this.sortBy(ctx, st.sort.i, st.sort.dir);
			this.resizable(ctx);
		}
		this.menu(ctx);
		this.settings(ctx);
		this.filter(ctx);
	},

	/* ---- search and type, together ---- */
	filter(ctx) {
		const { root, st } = ctx;
		const sel = root.querySelector('[data-ep-type-filter]');
		const type = sel ? sel.value : '';
		const q = st.q.trim().toLowerCase();
		let shown = 0;
		for (const row of root.querySelectorAll('tbody tr[data-type]')) {
			const subs = row.dataset.row ? [...root.querySelectorAll(`tr[data-parent="${CSS.escape(row.dataset.row)}"]`)] : [];
			const text = (row.textContent + ' ' + subs.map((s) => s.textContent).join(' ')).toLowerCase();
			const hide = (type !== '' && row.dataset.type !== type) || (q !== '' && !text.includes(q));
			row.hidden = hide;
			if (!hide) shown++;
			const open = row.querySelector('[data-ep-expand][aria-expanded="true"]');
			for (const s of subs) s.hidden = hide || !open;
		}
		const count = root.querySelector('[data-ep-count]');
		if (count && count.dataset.template) count.textContent = count.dataset.template.replace('%n', shown);
	},

	/* ---- sorting ---- */
	labelRow(table) {
		const rows = table.tHead ? table.tHead.rows : [];
		return rows.length ? rows[rows.length - 1] : null;
	},

	/** A cell's value for sorting: a number where the text is one (with its size unit), else the text. Unknown sorts last. */
	sortValue(td) {
		if (!td) return null;
		const t = td.textContent.replace(/▼|\s+/g, ' ').trim();
		if (t === '' || t === '—' || /^not (set|growing)$/i.test(t) || /^no data$/i.test(t)) return null;
		const m = t.match(/^(-?[\d.,]+)\s*(%|[KMGTP]i?B|B|days?|d)?/i);
		if (!m) return t.toLowerCase();
		let n = parseFloat(m[1].replace(/,/g, ''));
		const u = (m[2] || '').toUpperCase().replace('IB', 'B');
		const pow = { B: 0, KB: 1, MB: 2, GB: 3, TB: 4, PB: 5 }[u];
		if (pow !== undefined) n *= Math.pow(1024, pow);
		return n;
	},

	sortable(ctx) {
		const lab = this.labelRow(ctx.table);
		if (!lab) return;
		[...lab.cells].forEach((th, i) => {
			if (th.classList.contains('ep-res-toggle')) return;
			th.dataset.epSort = String(i);
			th.setAttribute('aria-sort', 'none');
			th.addEventListener('click', (e) => {
				if (e.target.closest('.ep-rz') || ctx.resizing) return;
				const st = ctx.st;
				const dir = st.sort && st.sort.i === i ? -st.sort.dir : (i === this.firstDataCol(lab) ? 1 : -1);
				st.sort = { i, dir };
				this.sortBy(ctx, i, dir);
			});
		});
	},
	firstDataCol(lab) {
		return [...lab.cells].findIndex((th) => !th.classList.contains('ep-res-toggle'));
	},

	sortBy(ctx, i, dir) {
		const { table, root } = ctx;
		const body = table.tBodies[0];
		if (!body) return;
		// A client row travels with its servers row.
		const groups = [...body.querySelectorAll(':scope > tr[data-type]')].map((row) => ({
			row, subs: row.dataset.row ? [...body.querySelectorAll(`:scope > tr[data-parent="${CSS.escape(row.dataset.row)}"]`)] : [],
			v: this.sortValue(row.cells[i])
		}));
		groups.sort((a, b) => {
			if (a.v === null && b.v === null) return 0;
			if (a.v === null) return 1;
			if (b.v === null) return -1;
			return (typeof a.v === 'string' || typeof b.v === 'string' ? String(a.v).localeCompare(String(b.v)) : a.v - b.v) * dir;
		});
		for (const g of groups) body.append(g.row, ...g.subs);
		const lab = this.labelRow(table);
		if (lab) [...lab.cells].forEach((th, j) => { if (th.dataset.epSort) th.setAttribute('aria-sort', j === i ? (dir > 0 ? 'ascending' : 'descending') : 'none'); });
		root.dispatchEvent(new CustomEvent('ep-sorted'));
	},

	/* ---- column widths ---- */
	widthKey(ctx) {
		const lab = this.labelRow(ctx.table);
		return lab ? [...lab.cells].map((c) => c.textContent.trim()).join('|') : '';
	},
	applyWidths(ctx) {
		const { table, prefs } = ctx;
		const lab = this.labelRow(table);
		if (!lab) return;
		const w = prefs.widths[this.widthKey(ctx)];
		let cg = table.querySelector(':scope > colgroup.ep-cg');
		if (!w || w.length !== lab.cells.length) {
			if (cg) cg.remove();
			table.classList.remove('ep-fixed');
			table.style.width = '';
			return;
		}
		if (!cg) {
			cg = document.createElement('colgroup');
			cg.className = 'ep-cg';
			table.insertBefore(cg, table.firstChild);
		}
		cg.innerHTML = w.map((px) => `<col style="width:${px}px">`).join('');
		table.classList.add('ep-fixed');
		table.style.width = w.reduce((a, b) => a + b, 0) + 'px';
	},
	resizable(ctx) {
		const { table, root, prefs, kind } = ctx;
		const lab = this.labelRow(table);
		if (!lab) return;
		this.applyWidths(ctx);
		const key = this.widthKey(ctx);
		const measured = () => [...lab.cells].map((c) => Math.round(c.getBoundingClientRect().width));
		[...lab.cells].forEach((th, i) => {
			if (getComputedStyle(th).position === 'static') th.style.position = 'relative';
			const h = document.createElement('span');
			h.className = 'ep-rz';
			h.title = 'Drag to resize · double-click to fit';
			th.appendChild(h);
			h.addEventListener('pointerdown', (e) => {
				e.preventDefault();
				e.stopPropagation();
				if (!prefs.widths[key]) { prefs.widths[key] = measured(); this.applyWidths(ctx); }
				const x0 = e.clientX, w0 = prefs.widths[key][i];
				ctx.resizing = true;
				root.classList.add('ep-resizing');
				h.classList.add('on');
				h.setPointerCapture(e.pointerId);
				const move = (ev) => { prefs.widths[key][i] = Math.max(40, Math.round(w0 + ev.clientX - x0)); this.applyWidths(ctx); };
				const up = () => {
					h.removeEventListener('pointermove', move);
					h.removeEventListener('pointerup', up);
					h.classList.remove('on');
					root.classList.remove('ep-resizing');
					this.savePrefs(kind, prefs);
					setTimeout(() => { ctx.resizing = false; }, 0);
				};
				h.addEventListener('pointermove', move);
				h.addEventListener('pointerup', up);
			});
			h.addEventListener('dblclick', (e) => {
				e.stopPropagation();
				const kept = prefs.widths[key] || measured();
				delete prefs.widths[key];
				this.applyWidths(ctx);
				kept[i] = measured()[i];
				prefs.widths[key] = kept;
				this.applyWidths(ctx);
				this.savePrefs(kind, prefs);
			});
		});
	},

	/* ---- the ⋮ menu ---- */
	menu(ctx) {
		const { root } = ctx;
		const btn = root.querySelector('[data-ep-menu]');
		const pop = root.querySelector('.ep-menu-pop');
		if (!btn || !pop) return;
		const close = () => { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
		btn.addEventListener('click', (e) => { e.stopPropagation(); pop.hidden = !pop.hidden; btn.setAttribute('aria-expanded', String(!pop.hidden)); });
		pop.addEventListener('click', () => close());
		root.addEventListener('click', (e) => { if (!e.target.closest('.ep-menu')) close(); });
		root.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
		// Columns is for Super admins; ep-columns.js hides the button for anyone else.
		const cols = pop.querySelector('[data-ep-columns]');
		if (cols && cols.hidden && ![...pop.querySelectorAll('button')].some((b) => !b.hidden)) btn.hidden = true;
	},

	/* ---- Table settings ---- */
	applyPrefs(ctx) {
		const { root, prefs } = ctx;
		root.style.setProperty('--ep-ok', prefs.colors.ok);
		root.style.setProperty('--ep-warn', prefs.colors.warn);
		root.style.setProperty('--ep-high', prefs.colors.high);
		root.classList.toggle('ep-comfortable', prefs.density === 'comfortable');
		for (const [k] of this.SERVER_COLUMNS) root.classList.toggle('ep-hide-' + k, prefs.hiddenSrv.includes(k));
	},

	settings(ctx) {
		const { root, prefs, kind } = ctx;
		const btn = root.querySelector('[data-ep-settings]');
		if (!btn) return;
		const hasServers = !!root.querySelector('.ep-servers, [data-ep-expand]');
		const box = document.createElement('div');
		box.className = 'ep-set';
		box.hidden = true;
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-label', 'Table settings');
		const radios = (name, opts, cur) => opts.map(([v, l]) => `<label><input type="radio" name="${name}" value="${v}"${v === cur ? ' checked' : ''}> ${l}</label>`).join('');
		const draw = () => {
			box.innerHTML = `<div class="ep-set-h"><span>Table settings</span><button type="button" class="ep-pbtn" data-x aria-label="Close">✕</button></div>`
				+ `<fieldset><legend>Colours</legend><div class="ep-set-row">`
				+ [['ok', 'Normal'], ['warn', 'Warn'], ['high', 'High']].map(([k, l]) => `<label><input type="color" data-color="${k}" value="${prefs.colors[k]}"> ${l}</label>`).join('')
				+ `</div></fieldset>`
				+ `<fieldset><legend>Row height</legend>${radios('ep-dens-' + kind, [['compact', 'Compact'], ['comfortable', 'Comfortable']], prefs.density)}</fieldset>`
				+ (hasServers
					? `<fieldset><legend>Server columns</legend>${this.SERVER_COLUMNS.map(([k, l]) => `<label><input type="checkbox" data-sc="${k}"${prefs.hiddenSrv.includes(k) ? '' : ' checked'}> ${l}</label>`).join('')}</fieldset>`
					: '')
				+ `<p>Columns: drag a header's right edge to resize, double-click it to fit. The report's own columns, names and order are in ⋮ → Columns.</p>`
				+ `<p>Thresholds and the bar style are the widget's Warn at, High at and Display settings, so everyone sees the same colours for the same figures. These here are yours alone, kept in this browser.</p>`
				+ `<button type="button" class="ep-pbtn" data-reset>Reset to defaults</button>`;
		};
		draw();
		root.appendChild(box);
		const open = (on) => { box.hidden = !on; btn.setAttribute('aria-expanded', String(on)); if (on) { draw(); const f = box.querySelector('input'); if (f) f.focus(); } };
		btn.addEventListener('click', (e) => { e.stopPropagation(); open(box.hidden); });
		box.addEventListener('click', (e) => {
			e.stopPropagation();
			if (e.target.closest('[data-x]')) open(false);
			if (e.target.closest('[data-reset]')) {
				Object.assign(prefs, JSON.parse(JSON.stringify(this.DEFAULTS)));
				this.savePrefs(kind, prefs);
				this.applyPrefs(ctx);
				if (ctx.table) this.applyWidths(ctx);
				draw();
			}
		});
		box.addEventListener('keydown', (e) => { if (e.key === 'Escape') open(false); });
		box.addEventListener('input', (e) => {
			const c = e.target.dataset.color;
			if (c) { prefs.colors[c] = e.target.value; this.applyPrefs(ctx); }
		});
		box.addEventListener('change', (e) => {
			const t = e.target;
			if (t.dataset.color) prefs.colors[t.dataset.color] = t.value;
			else if (t.name === 'ep-dens-' + kind) prefs.density = t.value;
			else if (t.dataset.sc) {
				const s = new Set(prefs.hiddenSrv);
				t.checked ? s.delete(t.dataset.sc) : s.add(t.dataset.sc);
				prefs.hiddenSrv = [...s];
			}
			this.applyPrefs(ctx);
			this.savePrefs(kind, prefs);
		});
	}
};
