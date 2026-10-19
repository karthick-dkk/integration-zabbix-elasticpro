/*
 * Client overview. The controller sends every client's figures with their colour classes and the
 * widget's settings; the view the settings choose lays them out — ep-overview.js the cards,
 * ep-overview-summary.js the Summary view. The search and the Type filter survive the refresh.
 */
class WidgetEpOverview extends CWidget {

	/**
	 * The Summary view's usage chart can be switched by the viewer (its metric, its period): the
	 * choice goes to the controller with the next refresh, as two fields the form validates and
	 * never saves, and the controller honours it only where the widget allows it.
	 */
	getUpdateRequestData() {
		const data = super.getUpdateRequestData();
		const pick = this._epOvSummary && this._epOvSummary.chart;

		if (pick && (pick.metric || pick.period)) {
			data.fields = {...(data.fields || {}), chart_view_metric: pick.metric || '', chart_view_period: pick.period || 0};
		}

		return data;
	}

	/**
	 * A refresh replaces the widget's whole body, which put every scrolled pane back at the top — the
	 * viewer reading the usage chart halfway down was thrown up to the cards every few seconds. The
	 * scroll of the pane and of every inner scroller is taken before and put back after, by its place
	 * among its kind; the viewer's other choices already survive the refresh.
	 */
	setContents(response) {
		const scrolled = WidgetEpOverview.scrollOf(this._body);
		super.setContents(response);
		const data = response.ep_overview || null;
		(data !== null && data.view === 'summary' ? window.EpOverviewSummary : window.EpOverview).init(this, this._body, data);
		WidgetEpOverview.restoreScroll(this._body, scrolled);
	}

	/** What scrolls in the widget: the pane and the scrollers inside it, the Cards' and the Summary's. */
	static get SCROLLERS() {
		return ['.ep-ov', '.ep-ov-tablewrap', '.ep-ov-mwrap'];
	}

	/** Each scroller's position, by selector and its place among those it matches; only the ones scrolled. */
	static scrollOf(body) {
		const out = [];
		if (!body || typeof body.querySelectorAll !== 'function') return out;
		for (const sel of WidgetEpOverview.SCROLLERS) {
			body.querySelectorAll(sel).forEach((el, i) => {
				if (el.scrollTop || el.scrollLeft) out.push({ sel, i, top: el.scrollTop, left: el.scrollLeft });
			});
		}
		return out;
	}

	/** The positions put back on the new body's scrollers. */
	static restoreScroll(body, scrolled) {
		if (!body || !scrolled.length) return;
		const put = () => {
			let short = false;
			for (const { sel, i, top, left } of scrolled) {
				const el = body.querySelectorAll(sel)[i];
				if (!el) continue;
				el.scrollTop = top;
				el.scrollLeft = left;
				short = short || el.scrollTop < top || el.scrollLeft < left;
			}
			return short;
		};
		// Once now; once more after the next layout if the new content was not tall enough yet.
		if (put() && typeof requestAnimationFrame === 'function') requestAnimationFrame(put);
	}
}
