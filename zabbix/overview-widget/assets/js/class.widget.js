/*
 * Client overview. The controller sends every client's figures with their colour classes and the
 * widget's settings; ep-overview.js lays out the panels the settings ask for. The chosen client,
 * the search and the Type filter survive the refresh.
 */
class WidgetEpOverview extends CWidget {

	setContents(response) {
		super.setContents(response);
		window.EpOverview.init(this, this._body, response.ep_overview || null);
	}
}
