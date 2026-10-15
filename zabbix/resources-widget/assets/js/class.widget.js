/*
 * Client resources widget. The table is drawn by the server (views/widget.view.php); the
 * controls — Type filter, expanding a client, Columns, export — are ep-columns.js and
 * ep-export.js, shared with the other ElasticPro reports.
 */
class WidgetEpResources extends CWidget {

	setContents(response) {
		super.setContents(response);
		window.EpColumns.init(this, this._body, response.ep_meta || null);
		window.EpExport.bind(this._body, response.export || null, 'client-resources', 'Clients', () => window.EpColumns.typeOf(this._body));
		// Search, sorting, widths, the ⋮ menu and Table settings; after EpColumns, so the search
		// and the Type filter decide together which clients show.
		window.EpPanel.init(this, this._body, 'resources');
		// The Overview view: the summary, gauges, used-of-allocated and role panels.
		window.EpOverview.init(this, this._body, response.ep_overview || null);
	}
}
