<?php declare(strict_types = 0);
/**
 * Client resources' form script: the shared Columns behaviour (columns.form.js.php, included before
 * this — the columns list, the server rows' columns, the client filter, Columns from and Reset to
 * report defaults), started as this widget's own, and what is its alone: the donuts dragged into
 * order, and the View tab showing the settings of the view chosen.
 *
 * @var CView $this
 */

use Modules\EpResources\Includes\WidgetForm;
?>

window.widget_ep_resources_form = new class extends window.EpColumnsForm {

	init(params) {
		super.init(params);
		const donuts = document.querySelector('#ep-res-donuts tbody');
		if (donuts !== null && donuts.children.length > 1) {
			new CSortable(donuts, {selector_handle: 'div.<?= ZBX_STYLE_DRAG_ICON ?>'});
		}
		this._form.addEventListener('change', (e) => {
			if (['view', 'view_switch'].includes(e.target.name)) {
				this._update();
			}
		});
	}

	/** The shared notes, then each view's own settings showing while that view can be seen. */
	_update() {
		super._update();
		const view = this._form.querySelector('[name="view"]:checked');
		const both = this._form.querySelector('[name="view_switch"]');
		const pie = view !== null && view.value === '<?= WidgetForm::VIEW_PIE ?>';
		const switchable = both !== null && both.checked;
		this._form.querySelectorAll('.ep-res-pie-only').forEach((el) => el.style.display = pie || switchable ? '' : 'none');
		this._form.querySelectorAll('.ep-res-table-only').forEach((el) => el.style.display = !pie || switchable ? '' : 'none');
	}
};
