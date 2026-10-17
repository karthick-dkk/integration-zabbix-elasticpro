/*
 * Client overview. The controller sends every client's figures with their colour classes and the
 * widget's settings; the view the settings choose lays them out — ep-overview.js the cards,
 * ep-overview-summary.js the Summary view. The search and the Type filter survive the refresh.
 */
class WidgetEpOverview extends CWidget {

	setContents(response) {
		super.setContents(response);
		const data = response.ep_overview || null;
		(data !== null && data.view === 'summary' ? window.EpOverviewSummary : window.EpOverview).init(this, this._body, data);
	}
}
