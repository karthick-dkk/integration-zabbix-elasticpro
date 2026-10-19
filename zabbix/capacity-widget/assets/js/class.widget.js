/*
 * Client capacity widget. The table is drawn by the server (views/widget.view.php); the Type
 * filter and Columns dialog are ep-columns.js, the export ep-export.js — shared with the other
 * ElasticPro reports — and search, sort, Needs attention and Compact are ep-panel.js.
 *
 * How the report looks — colours, row height, header, totals — is the widget's own setting (and
 * ElasticPro -> Settings' palette), so nothing of the look
 * is read from this browser. The browser keeps only one viewer's conveniences: the search, Needs
 * attention and Compact (until the page is reloaded), and dragged column widths.
 */
class WidgetEpCapacity extends CWidget {

	setContents(response) {
		super.setContents(response);
		const meta = response.ep_meta || null;
		window.EpColumns.init(this, this._body, meta);
		window.EpExport.bind(this._body, response.export || null, 'client-capacity', 'Client capacity', () => window.EpColumns.typeOf(this._body));
		// Search, sorting, widths, the ⋮ menu; after EpColumns, so the search and the Type filter decide together which clients show.
		window.EpPanel.init(this, this._body, 'capacity', {view: meta ? meta.view : null});
	}
}
