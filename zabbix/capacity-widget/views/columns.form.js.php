<?php declare(strict_types = 0);
/**
 * The behaviour of the Columns tab of an ElasticPro column widget's form (Client capacity, Volume,
 * Top hosts): the columns list (add, add a group, add avg / min / max for a column, edit and
 * remove through the column editor, show or hide, drag to reorder), the client filter, Columns
 * from and Reset to report defaults. A widget's own edit script starts it:
 *
 *   window.widget_ep_x_form = new window.EpColumnsForm();
 *   widget_ep_x_form.init({action: 'widget.ep_x.column.edit', templateid, trend_days, own, max});
 *
 * and adds what is its own (a setting that shows or hides another) beside it.
 *
 * Copied from ../../shared/php/views by ../../sync-assets.mjs — edit it there.
 *
 * @var CView $this
 */

use Modules\EpCapacity\Lib\Columns;

?>

window.EpColumnsForm = class {

	init({action, templateid, trend_days, own, max}) {
		this._action_name = action;
		this._form = document.getElementById('widget-dialogue-form');
		this._templateid = templateid;
		this._trend_days = trend_days;
		this._own = own;
		this._max = max;
		this._columns = document.getElementById('ep-cf-columns');

		if (own && this._columns !== null) {
			new CSortable(this._columns.querySelector('tbody'), {selector_handle: 'div.<?= ZBX_STYLE_DRAG_ICON ?>', freeze_end: 1});
			this._columns.addEventListener('click', (e) => this._action(e, 'columns'));
			this._columns.addEventListener('change', (e) => {
				if (e.target.matches('.js-show')) {
					const hidden = e.target.closest('tr').querySelector('[name^="columns["][name$="[hidden]"]');
					if (hidden !== null) {
						hidden.value = e.target.checked ? '0' : '1';
					}
					e.target.closest('tr').classList.toggle('ep-res-off', !e.target.checked);
				}
			});
		}

		const filter = document.getElementById('ep_cf_clients_filter');
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
		const reset = document.getElementById('ep_cf_reset');
		if (reset !== null) {
			reset.addEventListener('click', () => {
				const from = this._form.querySelector('[name="columns_from"][value="0"]');
				if (from !== null) {
					from.checked = true;
				}
				this._dropOwn();
				ZABBIX.Dashboard.reloadWidgetProperties();
			});
		}

		this._form.addEventListener('change', (e) => {
			// This widget's own list starts from the report defaults: the form is drawn again with them.
			if (e.target.name === 'columns_from') {
				if (e.target.value === '0') {
					this._dropOwn();
				}
				ZABBIX.Dashboard.reloadWidgetProperties();
				return;
			}
			if (['clients[]', 'totals'].includes(e.target.name)) {
				this._update();
			}
		});
		this._update();
	}

	_dropOwn() {
		this._form.querySelectorAll('[name^="columns["], [name^="sortorder["]').forEach((n) => n.remove());
	}

	_update() {
		const note = document.getElementById('ep_cf_clients_note');
		if (note !== null) {
			const boxes = this._form.querySelectorAll('.ep-ef-clients input[type="checkbox"]');
			const picked = [...boxes].filter((box) => box.checked).length;
			note.textContent = picked > 0
				? (picked === 1 ? <?= json_encode(_('%1$s client picked')) ?> : <?= json_encode(_('%1$s clients picked')) ?>).replace('%1$s', picked)
				: <?= json_encode(_('All clients · tick to pick from %1$s')) ?>.replace('%1$s', boxes.length);
		}
		const totals = this._form.querySelector('[name="totals"]');
		const fn = this._form.querySelector('[name="totals_fn"]');
		if (totals !== null && fn !== null) {
			fn.disabled = !totals.checked;
		}
	}

	_common() {
		const fields = getFormFields(this._form);
		return {templateid: this._templateid ?? undefined, trend_days: fields.trend_days ?? this._trend_days, scope: 'client'};
	}

	_nextIndex(list) {
		const used = [...this._form.querySelectorAll(`[name="sortorder[${list}][]"]`)].map((el) => Number(el.value));
		return used.length ? Math.max(...used) + 1 : 0;
	}

	_open(params, list) {
		const popup = PopUp(this._action_name, params, {
			dialogueid: 'ep-cf-column-edit', dialogue_class: 'modal-popup-generic modal-popup-large'
		}).$dialogue[0];
		popup.addEventListener('dialogue.submit', (ev) => this._store(ev.detail, list));
		popup.addEventListener('dialogue.close', () => jQuery('#color_picker').hide());
	}

	_action(e, list) {
		const button = e.target.closest('button[name]');
		if (button === null) {
			return;
		}
		const fields = getFormFields(this._form);
		switch (button.getAttribute('name')) {
			case 'add':
				this._index = this._nextIndex(list);
				this._open(this._common(), list);
				break;
			case 'add_group':
				this._index = this._nextIndex(list);
				this._open({...this._common(), group: <?= json_encode(_('New group')) ?>}, list);
				break;
			case 'add_window': {
				const box = document.getElementById('ep_cf_window');
				box.style.display = box.style.display === 'none' ? '' : 'none';
				break;
			}
			case 'add_window_go':
				this._addWindows(fields);
				break;
			case 'edit': {
				this._index = button.closest('tr').querySelector(`[name="sortorder[${list}][]"]`).value;
				this._open({...(fields[list] || {})[this._index], ...this._common(), edit: 1}, list);
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
		const select = document.getElementById('ep_cf_window_of');
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
			const box = document.getElementById('ep_cf_window_' + k);
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
			const prefix = `${list}[${this._index}][`;
			[...this._form.querySelectorAll('[name]')].filter((node) => node.name.startsWith(prefix)).forEach((node) => node.remove());
			this._fields(column, list, this._index);
		}
		else {
			this._write(column, list);
		}
		ZABBIX.Dashboard.reloadWidgetProperties();
	}
};
