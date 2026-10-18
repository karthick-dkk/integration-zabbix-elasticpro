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

	setContents(response) {
		super.setContents(response);
		const data = response.ep_overview || null;
		(data !== null && data.view === 'summary' ? window.EpOverviewSummary : window.EpOverview).init(this, this._body, data);
	}
}
