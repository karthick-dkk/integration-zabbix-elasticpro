<?php declare(strict_types = 0);
/**
 * The Client resources form's behaviour: the columns lists (add, add a group, add avg / min / max
 * for a column, edit and remove through the column editor, show or hide, drag to reorder), the
 * donuts list, the client filter, Columns from and Reset to report defaults, and the View tab
 * showing the settings of the view chosen.
 *
 * @var CView $this
 */

use Modules\EpResources\Includes\WidgetForm;
use Modules\EpResources\Lib\Columns;

?>

window.widget_ep_resources_form = new class {

	init({templateid, trend_days, own, max}) {
		this._form = document.getElementById('widget-dialogue-form');
		this._templateid = templateid;
		this._trend_days = trend_days;
		this._own = own;
		this._max = max;
		this._columns = document.getElementById('ep-res-columns');
		this._servers = document.getElementById('ep-res-server-columns');

		if (own) {
			new CSortable(this._columns.querySelector('tbody'), {selector_handle: 'div.<?= ZBX_STYLE_DRAG_ICON ?>', freeze_end: 1});
			const srv = this._servers.querySelector('tbody');
			if (srv !== null && srv.children.length > 1) {
				new CSortable(srv, {selector_handle: 'div.<?= ZBX_STYLE_DRAG_ICON ?>'});
			}
			this._columns.addEventListener('click', (e) => this._action(e, 'columns', 'client'));
			this._servers.addEventListener('click', (e) => this._action(e, 'server_columns', 'row'));
			for (const [table, list] of [[this._columns, 'columns'], [this._servers, 'server_columns']]) {
				table.addEventListener('change', (e) => {
					if (e.target.matches('.js-show')) {
						const hidden = e.target.closest('tr').querySelector(`[name^="${list}["][name$="[hidden]"]`);
						if (hidden !== null) {
							hidden.value = e.target.checked ? '0' : '1';
						}
						e.target.closest('tr').classList.toggle('ep-res-off', !e.target.checked);
					}
				});
			}
		}
		const donuts = document.querySelector('#ep-res-donuts tbody');
		if (donuts !== null && donuts.children.length > 1) {
			new CSortable(donuts, {selector_handle: 'div.<?= ZBX_STYLE_DRAG_ICON ?>'});
		}

		const filter = document.getElementById('ep_res_clients_filter');
		if (filter !== null) {
			filter.addEventListener('input', () => {
				const q = filter.value.trim().toLowerCase();
				this._form.querySelectorAll('.ep-ef-clients .ep-ef-box').forEach((box) => {
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

		// Reset to report defaults: the widget follows them again and keeps nothing of its own.
		const reset = document.getElementById('ep_res_reset');
		if (reset !== null) {
			reset.addEventListener('click', () => {
				const from = this._form.querySelector('[name="columns_from"][value="<?= WidgetForm::FROM_DEFAULTS ?>"]');
				if (from !== null) {
					from.checked = true;
				}
				this._form.querySelectorAll('[name^="columns["], [name^="server_columns["], [name^="sortorder["]').forEach((n) => n.remove());
				ZABBIX.Dashboard.reloadWidgetProperties();
			});
		}

		this._form.addEventListener('change', (e) => {
			// This widget's own list starts from the report defaults: the form is drawn again with them.
			if (e.target.name === 'columns_from') {
				if (e.target.value === '<?= WidgetForm::FROM_DEFAULTS ?>') {
					this._form.querySelectorAll('[name^="columns["], [name^="server_columns["], [name^="sortorder["]').forEach((n) => n.remove());
				}
				ZABBIX.Dashboard.reloadWidgetProperties();
				return;
			}
			if (['view', 'view_switch', 'clients[]', 'totals'].includes(e.target.name)) {
				this._update();
			}
		});
		this._update();
	}

	_update() {
		const note = document.getElementById('ep_res_clients_note');
		if (note !== null) {
			const boxes = this._form.querySelectorAll('.ep-ef-clients input[type="checkbox"]');
			const picked = [...boxes].filter((box) => box.checked).length;
			note.textContent = picked > 0
				? (picked === 1 ? <?= json_encode(_('%1$s client picked')) ?> : <?= json_encode(_('%1$s clients picked')) ?>).replace('%1$s', picked)
				: <?= json_encode(_('All clients · tick to pick from %1$s')) ?>.replace('%1$s', boxes.length);
		}
		// Each view's own settings show while that view can be seen.
		const view = this._form.querySelector('[name="view"]:checked');
		const both = this._form.querySelector('[name="view_switch"]');
		const pie = view !== null && view.value === '<?= WidgetForm::VIEW_PIE ?>';
		const switchable = both !== null && both.checked;
		this._form.querySelectorAll('.ep-res-pie-only').forEach((el) => el.style.display = pie || switchable ? '' : 'none');
		this._form.querySelectorAll('.ep-res-table-only').forEach((el) => el.style.display = !pie || switchable ? '' : 'none');
		const totals = this._form.querySelector('[name="totals"]');
		const fn = this._form.querySelector('[name="totals_fn"]');
		if (totals !== null && fn !== null) {
			fn.disabled = !totals.checked;
		}
	}

	_common(scope) {
		const fields = getFormFields(this._form);
		return {templateid: this._templateid ?? undefined, trend_days: fields.trend_days ?? this._trend_days, scope};
	}

	_nextIndex(list) {
		const used = [...this._form.querySelectorAll(`[name="sortorder[${list}][]"]`)].map((el) => Number(el.value));
		return used.length ? Math.max(...used) + 1 : 0;
	}

	_open(params, list) {
		const popup = PopUp('widget.ep_resources.column.edit', params, {
			dialogueid: 'ep-res-column-edit', dialogue_class: 'modal-popup-generic modal-popup-large'
		}).$dialogue[0];
		popup.addEventListener('dialogue.submit', (ev) => this._store(ev.detail, list));
		popup.addEventListener('dialogue.close', () => jQuery('#color_picker').hide());
	}

	_action(e, list, scope) {
		const button = e.target.closest('button[name]');
		if (button === null) {
			return;
		}
		const fields = getFormFields(this._form);
		switch (button.getAttribute('name')) {
			case 'add':
				this._index = this._nextIndex(list);
				this._after = null;
				this._open(this._common(scope), list);
				break;
			case 'add_group':
				this._index = this._nextIndex(list);
				this._after = null;
				this._open({...this._common(scope), group: <?= json_encode(_('New group')) ?>}, list);
				break;
			case 'add_window': {
				const box = document.getElementById('ep_res_window');
				box.style.display = box.style.display === 'none' ? '' : 'none';
				break;
			}
			case 'add_window_go':
				this._addWindows(fields);
				break;
			case 'edit': {
				this._index = button.closest('tr').querySelector(`[name="sortorder[${list}][]"]`).value;
				this._after = null;
				this._open({...(fields[list] || {})[this._index], ...this._common(scope), edit: 1}, list);
				break;
			}
			case 'remove':
				button.closest('tr').remove();
				ZABBIX.Dashboard.reloadWidgetProperties();
				break;
		}
	}

	/** Average, minimum and maximum of a column over the widget's window: copies of it, right after it, in its group. */
	_addWindows(fields) {
		const select = document.getElementById('ep_res_window_of');
		const at = select !== null ? select.value : '';
		const base = (fields.columns || {})[at];
		if (base === undefined) {
			return;
		}
		const anchor = [...this._form.querySelectorAll('[name="sortorder[columns][]"]')].find((el) => el.value === String(at));
		const values = [['avg', '<?= Columns::VALUE_AVG ?>', <?= json_encode(_('avg')) ?>], ['min', '<?= Columns::VALUE_MIN ?>', <?= json_encode(_('min')) ?>],
			['max', '<?= Columns::VALUE_MAX ?>', <?= json_encode(_('max')) ?>]];
		let after = anchor;
		for (const [k, value, label] of values) {
			const box = document.getElementById('ep_res_window_' + k);
			if (box === null || !box.checked) {
				continue;
			}
			const copy = {...base, uid: '', value, window: '0', hidden: '0', name: `${this._trend_days}d ${label}`};
			this._index = this._nextIndex('columns');
			after = this._write(copy, 'columns', after);
		}
		ZABBIX.Dashboard.reloadWidgetProperties();
	}

	_input(name, value, after = null) {
		const input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		if (after !== null) {
			after.after(input);
		}
		else {
			this._form.appendChild(input);
		}
		return input;
	}

	/** A column's settings as hidden fields of the widget form, under $list[$index]. */
	_fields(column, list, index) {
		for (const [key, value] of Object.entries(column)) {
			if (key === 'roles') {
				(Array.isArray(value) ? value : Object.values(value || {})).forEach((role, j) => this._input(`${list}[${index}][roles][${j}]`, role));
			}
			else if (key === 'thresholds') {
				(Array.isArray(value) ? value : Object.values(value || {})).forEach((t, j) => {
					for (const tk of ['level', 'color', 'value']) {
						this._input(`${list}[${index}][thresholds][${j}][${tk}]`, t[tk]);
					}
				});
			}
			else if (!['edit', 'scope'].includes(key)) {
				this._input(`${list}[${index}][${key}]`, value);
			}
		}
	}

	/** A new column: its place in the order after $after (or at the end), then its settings. */
	_write(column, list, after = null) {
		const order = this._input(`sortorder[${list}][]`, this._index, after);
		this._fields(column, list, this._index);
		return order;
	}

	/** The column the editor returned, as hidden fields of the widget form; then the form is drawn again. */
	_store(column, list) {
		if (column.edit) {
			// Edited: its settings replaced, its place in the order kept.
			this._form.querySelectorAll(`[name^="${list}[${this._index}]["]`).forEach((node) => node.remove());
			this._fields(column, list, this._index);
		}
		else {
			this._write(column, list);
		}
		ZABBIX.Dashboard.reloadWidgetProperties();
	}
};
