/*
 * Top hosts. The controller sends a pane for each metric a viewer may rank by — already ranked,
 * already drawn (a list with bars, a table or a bar chart) — so the switch only shows the one
 * chosen: no trip to Zabbix, nothing built from text on this side. The choice survives the refresh.
 */
class WidgetEpTophosts extends CWidget {

	setContents(response) {
		super.setContents(response);
		const panes = [...this._body.querySelectorAll('[data-ep-pane]')];
		if (panes.length === 0) {
			return;
		}
		const buttons = [...this._body.querySelectorAll('[data-ep-rank]')];
		const first = (buttons.find((b) => b.getAttribute('aria-pressed') === 'true') || {dataset: {}}).dataset.epRank || panes[0].dataset.epPane;
		const show = (id) => {
			this._epRank = id;
			for (const b of buttons) {
				b.setAttribute('aria-pressed', String(b.dataset.epRank === id));
			}
			for (const p of panes) {
				p.hidden = p.dataset.epPane !== id;
			}
		};
		for (const b of buttons) {
			b.addEventListener('click', () => show(b.dataset.epRank));
		}
		show(panes.some((p) => p.dataset.epPane === this._epRank) ? this._epRank : first);
	}
}
