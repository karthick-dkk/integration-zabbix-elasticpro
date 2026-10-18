/*
 * Client resources widget. The table and the donuts are drawn by the server
 * (views/widget.view.php); the controls — Type filter, expanding a client, Columns, export —
 * are ep-columns.js and ep-export.js, shared with the other ElasticPro reports, and search, sort,
 * Needs attention, Compact and the Table / Pie chart switch are ep-panel.js.
 *
 * How the report looks — colours, row height, server columns — is the widget's own setting (and
 * ElasticPro -> Settings' palette), so the panel is started with {prefs: false}: nothing of the
 * look is read from this browser.
 */
class WidgetEpResources extends CWidget {

	setContents(response) {
		super.setContents(response);
		const meta = response.ep_meta || null;
		window.EpColumns.init(this, this._body, meta);
		window.EpExport.bind(this._body, response.export || null, 'client-resources', 'Clients', () => window.EpColumns.typeOf(this._body));
		// Search, sorting, widths, the ⋮ menu and the view switches; after EpColumns, so the search,
		// the Type filter and Needs attention decide together which clients show.
		window.EpPanel.init(this, this._body, 'resources', {prefs: false, view: meta ? meta.view : null});
	}
}
