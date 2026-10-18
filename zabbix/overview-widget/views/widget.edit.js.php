<?php declare(strict_types = 0);
/**
 * The Client overview form's behaviour: the metrics list (add, edit and remove through the
 * metric editor, drag to reorder), the sections and roles lists (drag to reorder), the client
 * filter, and the trend days box that only means something for "Last N days".
 *
 * @var CView $this
 */

use Modules\EpOverview\Includes\WidgetForm;

?>

window.widget_ep_overview_form = new class {

	init({templateid, trend_days, stale_after, max}) {
		this._form = document.getElementById('widget-dialogue-form');
		this._templateid = templateid;
		this._trend_days = trend_days;
		this._stale_after = stale_after;
		this._max = max;
		this._metrics = document.getElementById('ep-ov-metrics');

		new CSortable(this._metrics.querySelector('tbody'), {
			selector_handle: 'div.<?= ZBX_STYLE_DRAG_ICON ?>',
			freeze_end: 1
		});
		this._metrics.addEventListener('click', (e) => this._metricsAction(e));

		for (const id of ['ep-ov-sections', 'ep-ov-roles']) {
			const body = document.querySelector(`#${id} tbody`);
			if (body !== null && body.children.length > 1) {
				new CSortable(body, {selector_handle: 'div.<?= ZBX_STYLE_DRAG_ICON ?>'});
			}
		}

		const filter = document.getElementById('ep_ov_clients_filter');
		if (filter !== null) {
			filter.addEventListener('input', () => {
				const q = filter.value.trim().toLowerCase();
				this._form.querySelectorAll('.ep-ov-clients .ep-ov-box').forEach((box) => {
					box.style.display = q === '' || box.textContent.toLowerCase().includes(q) ? '' : 'none';
				});
			});
			// Enter in the filter must not apply the whole form.
			filter.addEventListener('keydown', (e) => {
				if (e.key === 'Enter') {
					e.preventDefault();
				}
			});
		}

		this._form.addEventListener('change', (e) => {
			if (e.target.name === 'trend_source' || e.target.name === 'view' || e.target.name === 'clients[]') {
				this._update();
			}
		});
		this._update();
	}

	_update() {
		// The Clients hint says what is picked, as it is ticked.
		const note = document.getElementById('ep_ov_clients_note');
		if (note !== null) {
			const boxes = this._form.querySelectorAll('.ep-ov-clients input[type="checkbox"]');
			const picked = [...boxes].filter((box) => box.checked).length;
			note.textContent = picked > 0
				? (picked === 1 ? <?= json_encode(_('%1$s client picked')) ?> : <?= json_encode(_('%1$s clients picked')) ?>).replace('%1$s', picked)
				: <?= json_encode(_('All clients · tick to pick from %1$s')) ?>.replace('%1$s', boxes.length);
		}

		// Each view's own settings show while that view is chosen.
		const view = this._form.querySelector('[name="view"]:checked');
		const summary = view !== null && view.value === '<?= WidgetForm::VIEW_SUMMARY ?>';
		this._form.querySelectorAll('.ep-ov-summary-only').forEach((el) => el.style.display = summary ? '' : 'none');
		this._form.querySelectorAll('.ep-ov-cards-only').forEach((el) => el.style.display = summary ? 'none' : '');
		const days = this._form.querySelector('[name="trend_days"]');
		const source = this._form.querySelector('[name="trend_source"]:checked');
		if (days !== null && source !== null) {
			days.disabled = source.value !== '<?= WidgetForm::TREND_DAYS ?>';
		}
	}

	_metricsAction(e) {
		const button = e.target.closest('button[name]');
		if (button === null) {
			return;
		}
		const fields = getFormFields(this._form);
		const common = {
			templateid: this._templateid ?? undefined,
			trend_days: fields.trend_days ?? this._trend_days,
			stale_after: fields.stale_after ?? this._stale_after
		};
		let popup;

		switch (button.getAttribute('name')) {
			case 'add': {
				const used = [...this._metrics.querySelectorAll('[name="sortorder[metrics][]"]')].map((el) => Number(el.value));
				this._index = used.length ? Math.max(...used) + 1 : 0;
				popup = PopUp('widget.ep_overview.metric.edit', common, {
					dialogueid: 'ep-ov-metric-edit', dialogue_class: 'modal-popup-generic'
				}).$dialogue[0];
				break;
			}
			case 'edit':
				this._index = button.closest('tr').querySelector('[name="sortorder[metrics][]"]').value;
				popup = PopUp('widget.ep_overview.metric.edit', {...fields.metrics[this._index], ...common, edit: 1}, {
					dialogueid: 'ep-ov-metric-edit', dialogue_class: 'modal-popup-generic'
				}).$dialogue[0];
				break;
			case 'remove':
				button.closest('tr').remove();
				ZABBIX.Dashboard.reloadWidgetProperties();
				return;
			default:
				return;
		}
		popup.addEventListener('dialogue.submit', (ev) => this._store(ev.detail));
		popup.addEventListener('dialogue.close', () => jQuery('#color_picker').hide());
	}

	/** The metric the editor returned, as hidden fields of the widget form; then the form is drawn again. */
	_store(metric) {
		const index = this._index;
		const add = (name, value) => {
			const input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			input.value = value;
			this._form.appendChild(input);
		};

		if (metric.edit) {
			this._form.querySelectorAll(`[name^="metrics[${index}]["]`).forEach((node) => node.remove());
			delete metric.edit;
		}
		else {
			add('sortorder[metrics][]', index);
		}

		for (const [key, value] of Object.entries(metric)) {
			if (key === 'roles') {
				value.forEach((role, j) => add(`metrics[${index}][roles][${j}]`, role));
			}
			else if (key === 'thresholds') {
				value.forEach((t, j) => {
					for (const tk of ['level', 'color', 'value']) {
						add(`metrics[${index}][thresholds][${j}][${tk}]`, t[tk]);
					}
				});
			}
			else {
				add(`metrics[${index}][${key}]`, value);
			}
		}

		ZABBIX.Dashboard.reloadWidgetProperties();
	}
};
