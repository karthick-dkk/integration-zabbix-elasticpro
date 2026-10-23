/*
 * Discover clusters: select all, "show hosts that are already a client", and the summary box,
 * kept in step with what is ticked and with the two proxy choices. Nothing is written from here:
 * the form posts to ep.clients.discover.apply, which plans every row again against Zabbix.
 *
 * summarize() is pure and exported for test/discover-js.test.mjs; the page reads each row's
 * figures from its data-ep-sum (drawn by ep.clients.discover.php from the row's plan).
 */
(function () {
	const EpDiscover = {
		KEEP: '__keep__',

		/** A proxy choice as the value a host would end up with ('' is the Zabbix server). */
		target(choice, today) {
			if (choice === EpDiscover.KEEP) return today;
			if (choice === undefined || choice === null) return '';
			return choice === 'server' ? '' : choice;
		},

		/**
		 * What the ticked rows would write: how many clients, cluster hosts linked, groups created,
		 * hosts adopted, and hosts whose proxy changes under these two choices.
		 */
		summarize(rows, esChoice, linuxChoice) {
			const groups = new Set();
			let links = 0, hosts = 0, proxy = 0;
			for (const r of rows) {
				(r.groups || []).forEach((g) => groups.add(g));
				hosts += r.hosts || 0;
				const es = EpDiscover.target(esChoice, r.es_proxy || '');
				if (r.cluster) {
					links += 1;
					if (es !== 'jump' && (r.cluster.proxy || '') !== es) proxy += 1;
				}
				// The Linux hosts: their own choice, else the client's (empty = as the cluster host).
				let lx = r.linux_proxy || '';
				lx = lx === 'server' ? '' : (lx === '' ? (es === 'jump' ? '' : es) : lx);
				lx = EpDiscover.target(linuxChoice, lx);
				for (const s of r.servers || []) {
					if ((s.proxy || '') !== lx) proxy += 1;
				}
			}
			return { count: rows.length, links, groups: groups.size, hosts, proxy };
		},

		/**
		 * Put the figures into a line: "%k" is the figure, "%k{one|many}" the figure and the word
		 * that agrees with it ("1 host", "3 hosts").
		 */
		fill(t, v) {
			const out = (t || '').replace(/%(\w+)\{([^|}]*)\|([^}]*)\}/g, (all, k, one, many) =>
				(k in v ? `${v[k]} ${Number(v[k]) === 1 ? one : many}` : all));
			return Object.keys(v).reduce((s, k) => s.split('%' + k).join(String(v[k])), out);
		},

		init(root) {
			const form = root.querySelector('#ep-disc-form');
			if (!form) return;
			const text = JSON.parse(form.dataset.epText || '{}');
			const rowBoxes = () => [...root.querySelectorAll('input[data-ep-row]')].filter((b) => !b.disabled);
			const ticked = () => rowBoxes().filter((b) => b.checked)
				.map((b) => b.closest('tr')).filter((tr) => tr && tr.dataset.epSum).map((tr) => JSON.parse(tr.dataset.epSum));
			const choice = (n) => { const s = root.querySelector(`select[name="${n}"]`); return s ? s.value : EpDiscover.KEEP; };
			const fill = EpDiscover.fill;
			function update() {
				const sum = EpDiscover.summarize(ticked(), choice('proxy_es'), choice('proxy_linux'));
				const head = root.querySelector('[data-ep-sum-head]');
				const line = root.querySelector('[data-ep-sum-line]');
				const btn = root.querySelector('[data-ep-adopt]');
				if (head && text.head) head.textContent = fill(text.head, { n: sum.count });
				if (line && text.line) line.textContent = fill(text.line, { tpl: text.tpl, links: sum.links, groups: sum.groups, hosts: sum.hosts, proxy: sum.proxy });
				if (btn && text.adopt) { btn.textContent = fill(text.adopt, { n: sum.count }); btn.disabled = sum.count === 0; }
			}
			const all = root.querySelector('input[data-ep-all]');
			if (all) all.addEventListener('change', () => { rowBoxes().forEach((b) => { b.checked = all.checked; }); update(); });
			rowBoxes().forEach((b) => b.addEventListener('change', update));
			root.querySelectorAll('select[data-ep-proxy]').forEach((s) => s.addEventListener('change', update));
			const show = root.querySelector('input[data-ep-show-clients]');
			if (show) show.addEventListener('change', () => root.querySelectorAll('tr.ep-already').forEach((tr) => { tr.hidden = !show.checked; }));
			update();
		}
	};
	if (typeof module !== 'undefined' && module.exports) { module.exports = EpDiscover; return; }
	const root = document.querySelector('.ep-disc');
	if (root) EpDiscover.init(root);
})();
