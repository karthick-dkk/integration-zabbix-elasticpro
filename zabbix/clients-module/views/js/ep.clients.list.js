/*
 * Cluster Management's list: search, the filters (All / Missing / Old templates; Not adopted is a
 * link to Discover), the Status choice, the "Showing N of M" line, bulk ticks, and the Columns
 * popover (drag to reorder, untick to hide, type to rename; saved as one shared setting by
 * ep.clients.columns.save). shown() and columnsFrom() are pure and exported for
 * test/list-js.test.mjs.
 */
(function () {
	const EpList = {
		/** Whether a row shows: a search looks across every status; otherwise the status, then the filter. */
		shown(row, s) {
			const term = (s.term || '').trim().toLowerCase();
			if (term !== '') return row.search.includes(term);
			if (!row.tabs.includes(s.status || 'live')) return false;
			if (s.filter === 'missing') return row.missing;
			if (s.filter === 'old') return row.old;
			return true;
		},

		/** The popover's list as the setting posted: [{key, show (true|false|null = as the data says), label}], in order. */
		columnsFrom(items) {
			return items.map((i) => ({ key: i.key, label: i.label, show: i.auto && i.checked === i.autoOn ? null : i.checked }));
		},

		init(root) {
			const rows = [...root.querySelectorAll('tbody tr[data-tabs]')];
			const st = { term: '', status: 'live', filter: 'all' };
			const q = root.querySelector('#ep-q');
			const status = root.querySelector('#ep-status');
			const showing = root.querySelector('#ep-showing');
			const data = (r) => ({ search: r.dataset.search || '', tabs: (r.dataset.tabs || '').split(' '), missing: r.dataset.missing === '1', old: r.dataset.old === '1' });
			function show() {
				let n = 0;
				rows.forEach((r) => { r.hidden = !EpList.shown(data(r), st); if (!r.hidden) n += 1; });
				if (showing) showing.textContent = 'Showing ' + n + ' of ' + showing.dataset.of + ' clients.';
				ticks();
			}
			if (q) q.addEventListener('input', () => { st.term = q.value; show(); });
			if (status) status.addEventListener('change', () => { st.status = status.value; show(); });
			root.querySelectorAll('[data-filter]').forEach((b) => b.addEventListener('click', () => {
				st.filter = b.dataset.filter;
				root.querySelectorAll('.ep-seg [data-filter]').forEach((x) => x.setAttribute('aria-pressed', String(x.dataset.filter === st.filter)));
				show();
			}));

			// Bulk: the ticked, shown clients go into the bulk form as clients[<name>]=1.
			const bulk = root.querySelector('#ep-bulk');
			const ticked = () => rows.filter((r) => !r.hidden).map((r) => r.querySelector('.ep-sel')).filter((c) => c && c.checked).map((c) => c.value);
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
			const all = root.querySelector('#ep-all');
			if (all) all.addEventListener('change', (e) => {
				rows.filter((r) => !r.hidden).forEach((r) => { const c = r.querySelector('.ep-sel'); if (c) c.checked = e.target.checked; });
				ticks();
			});

			// Columns: open, drag to reorder, and on Save the order, switches and names as one field.
			const pop = root.querySelector('#ep-cols');
			const btn = root.querySelector('.ep-cols-btn');
			if (pop && btn) {
				const toggle = (open) => { pop.hidden = !open; btn.setAttribute('aria-expanded', String(open)); };
				btn.addEventListener('click', () => toggle(pop.hidden));
				pop.querySelector('[data-cols-close]').addEventListener('click', () => toggle(false));
				let dragged = null;
				pop.querySelectorAll('li[draggable]').forEach((li) => {
					li.dataset.autoOn = li.querySelector('input[type=checkbox]').checked ? '1' : '0';
					li.addEventListener('dragstart', () => { dragged = li; li.classList.add('ep-drag'); });
					li.addEventListener('dragend', () => { li.classList.remove('ep-drag'); dragged = null; });
					li.addEventListener('dragover', (e) => { e.preventDefault(); if (dragged && dragged !== li) li.parentNode.insertBefore(dragged, li); });
				});
				pop.addEventListener('submit', () => {
					const items = [...pop.querySelectorAll('li[draggable]')].map((li) => ({
						key: li.dataset.key, label: li.querySelector('input[type=text]').value, auto: li.dataset.auto === '1',
						checked: li.querySelector('input[type=checkbox]').checked, autoOn: li.dataset.autoOn === '1' }));
					pop.querySelector('input[name=columns]').value = JSON.stringify(EpList.columnsFrom(items));
				});
			}
			if (window.EpDialogs) window.EpDialogs.init(root, ticked);
			show();
		}
	};
	if (typeof module !== 'undefined' && module.exports) { module.exports = EpList; return; }
	const root = document.querySelector('.ep-l');
	if (root) EpList.init(root);
})();
