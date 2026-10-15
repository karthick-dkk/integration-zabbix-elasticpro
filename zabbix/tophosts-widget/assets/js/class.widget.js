/*
 * Top hosts. The controller sends every server with its CPU and memory and the colour class of
 * each; this ranks them by the one chosen, takes the first `count`, and redraws when the
 * CPU / Memory switch is pressed. The switch survives the refresh.
 */
window.EpTopHosts = {
	esc(s) {
		return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	},
	color(cls) {
		return cls === 'ep-high' ? 'var(--ep-high)' : cls === 'ep-warn' ? 'var(--ep-warn)' : 'var(--ep-ok)';
	},
	/** The servers with a figure for `rank`, highest first, at most `count`. Not measured is left out, never ranked as zero. */
	ranked(servers, rank, count) {
		return servers.filter((s) => s[rank] !== null && s[rank] !== undefined).sort((a, b) => b[rank] - a[rank]).slice(0, count);
	},
	draw(list, data, rank) {
		const cls = { cpu: 'cpu', mem: 'mem' }[rank] || 'cpu';
		const top = this.ranked(data.servers, rank, data.count);
		list.innerHTML = top.length ? top.map((s, i) => {
			const c = this.color(s.classes[cls]), v = Math.round(s[rank] * 10) / 10;
			return `<li><span class="rk">${i + 1}</span><a class="hn" href="${this.esc(s.url)}" title="Latest data for ${this.esc(s.host)}">${this.esc(s.host)}</a>`
				+ `<span class="hv" style="color:${c}">${v}%</span><span class="hc">${this.esc(s.client)} · ${this.esc(s.role)}</span>`
				+ `<span class="hb"><i style="width:${Math.max(0, Math.min(100, v))}%;background:${c}"></i></span></li>`;
		}).join('') : `<li class="ep-empty">No server reports ${rank === 'mem' ? 'memory use' : 'CPU use'} yet.</li>`;
	}
};

class WidgetEpTophosts extends CWidget {

	setContents(response) {
		super.setContents(response);
		const data = response.ep_tophosts;
		const list = this._body.querySelector('[data-ep-top]');
		if (!data || !list) return;
		const rank = ['cpu', 'mem'].includes(this._epRank) ? this._epRank : data.rank;
		const show = (r) => {
			this._epRank = r;
			for (const b of this._body.querySelectorAll('[data-ep-rank]')) b.setAttribute('aria-pressed', String(b.dataset.epRank === r));
			window.EpTopHosts.draw(list, data, r);
		};
		for (const b of this._body.querySelectorAll('[data-ep-rank]')) b.addEventListener('click', () => show(b.dataset.epRank));
		show(rank);
	}
}
