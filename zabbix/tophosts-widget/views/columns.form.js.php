<?php declare(strict_types = 0);
/**
 * The behaviour of the Columns tab of an ElasticPro column widget's form (Client resources, Client
 * capacity, Volume, Top hosts): the columns list (add, add a group, add avg / min / max for a
 * column, edit and remove through the column editor, show or hide, drag to reorder), the server
 * rows' columns where the widget has them (edit, show or hide, drag to reorder), the client
 * filter, Columns from and Reset to report defaults. A widget's own edit script starts it:
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

use Modules\EpTophosts\Lib\Columns;

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
		this._servers = document.getElementById('ep-cf-server-columns');

		if (own && this._columns !== null) {
			new CSortable(this._columns.querySelector('tbody'), {selector_handle: 'div.<?= ZBX_STYLE_DRAG_ICON ?>', freeze_end: 1});
			this._columns.addEventListener('click', (e) => this._action(e, 'columns', 'client'));
			this._showToggle(this._columns, 'columns');
		}
		// The server rows' columns (Client resources): no Add here, each one edited in the same editor as a row column.
		if (own && this._servers !== null) {
			const rows = this._servers.querySelector('tbody');
			if (rows !== null && rows.children.length > 1) {
				new CSortable(rows, {selector_handle: 'div.<?= ZBX_STYLE_DRAG_ICON ?>'});
			}
			this._servers.addEventListener('click', (e) => this._action(e, 'server_columns', 'row'));
			this._showToggle(this._servers, 'server_columns');
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
				this._reload();
			});
		}

		this._form.addEventListener('change', (e) => {
			// This widget's own list starts from the report defaults: the form is drawn again with them.
			if (e.target.name === 'columns_from') {
				if (e.target.value === '0') {
					this._dropOwn();
				}
				this._reload();
				return;
			}
			if (['clients[]', 'totals'].includes(e.target.name)) {
				this._update();
			}
		});
		this._update();
		this._keep();
	}

	/**
	 * A column added, edited or removed, Columns from changed, Reset: the form is drawn again with it.
	 * Where the person was — the tab and how far down — is noted first, so the new form opens there
	 * (Zabbix draws a reloaded form on its first tab, at the top).
	 */
	_reload() {
		const state = this._state();
		window.EpColumnsFormKeep = {tab: state.tab, scroll: state.scroll, action: this._action_name, at: Date.now()};
		ZABBIX.Dashboard.reloadWidgetProperties();
	}

	/** The tab and scroll of this dialogue, kept on its element, which outlives the form drawn in it. */
	_state() {
		const dialogue = this._form.closest('.overlay-dialogue');
		const tabs = this._form.querySelector('.ep-ef-tabs');
		const body = this._form.closest('.overlay-dialogue-body');
		const state = dialogue !== null ? (dialogue.epFormKeep || (dialogue.epFormKeep = {tab: 0, scroll: 0})) : {tab: 0, scroll: 0};
		if (tabs !== null) {
			try {
				const active = jQuery(tabs).tabs('option', 'active');
				if (Number.isInteger(active)) {
					state.tab = active;
				}
			}
			catch (e) {
				// Tabs not started: nothing to note.
			}
		}
		if (body !== null) {
			state.scroll = body.scrollTop;
		}

		return state;
	}

	/** Opens a form drawn again on the tab and at the scroll it had; follows both while it is open. */
	_keep() {
		const dialogue = this._form.closest('.overlay-dialogue');
		const tabs = this._form.querySelector('.ep-ef-tabs');
		const body = this._form.closest('.overlay-dialogue-body');
		if (dialogue === null) {
			return;
		}
		// The same dialogue redrawn keeps its note; a new one (a different element) may still be the redraw
		// this form asked for a moment ago.
		const stash = window.EpColumnsFormKeep || null;
		window.EpColumnsFormKeep = null;
		if (!dialogue.epFormKeep && stash !== null && stash.action === this._action_name && Date.now() - stash.at < 15000) {
			dialogue.epFormKeep = {tab: stash.tab, scroll: stash.scroll};
		}
		const state = dialogue.epFormKeep || (dialogue.epFormKeep = {tab: 0, scroll: 0});
		if (tabs !== null && state.tab > 0) {
			try {
				jQuery(tabs).tabs('option', 'active', state.tab);
			}
			catch (e) {
				// Tabs not started yet: the form opens on its first tab, as Zabbix draws it.
			}
		}
		if (body !== null && state.scroll > 0) {
			const want = state.scroll;
			body.scrollTop = want;
			// Once more after the new form is laid out, if it was not tall enough yet.
			if (body.scrollTop < want && typeof requestAnimationFrame === 'function') {
				requestAnimationFrame(() => { body.scrollTop = want; });
			}
		}
		if (tabs !== null) {
			jQuery(tabs).on('tabsactivate', () => {
				try {
					state.tab = jQuery(tabs).tabs('option', 'active');
				}
				catch (e) {
					// Nothing to note.
				}
				state.scroll = 0;
			});
		}
		if (body !== null && !body.epKeepBound) {
			body.epKeepBound = true;
			body.addEventListener('scroll', () => {
				const d = body.closest('.overlay-dialogue');
				if (d !== null && d.epFormKeep) {
					d.epFormKeep.scroll = body.scrollTop;
				}
			}, {passive: true});
		}
	}

	/** Show ticked or not in a list sets that column hidden or not in the form. */
	_showToggle(table, list) {
		table.addEventListener('change', (e) => {
			if (e.target.matches('.js-show')) {
				const hidden = [...e.target.closest('tr').querySelectorAll('[name]')].find((n) => n.name.startsWith(`${list}[`) && n.name.endsWith('[hidden]'));
				if (hidden !== undefined) {
					hidden.value = e.target.checked ? '0' : '1';
				}
				e.target.closest('tr').classList.toggle('ep-res-off', !e.target.checked);
			}
		});
	}

	/** The widget's own lists out of the form — matched on the name itself, as _store() does, not on a selector with "[" in it. */
	_dropOwn() {
		[...this._form.querySelectorAll('[name]')].filter((n) => /^(columns|server_columns|sortorder)\[/.test(n.name)).forEach((n) => n.remove());
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
		// The checkbox itself: Zabbix's checkbox field puts a hidden "totals" = 0 before it, which is never checked.
		const totals = this._form.querySelector('input[type="checkbox"][name="totals"]');
		const fn = this._form.querySelector('[name="totals_fn"]');
		if (totals !== null && fn !== null) {
			fn.disabled = !totals.checked;
		}
	}

	_common(scope = 'client') {
		const fields = getFormFields(this._form);
		return {templateid: this._templateid ?? undefined, trend_days: fields.trend_days ?? this._trend_days, scope};
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

	_action(e, list, scope = 'client') {
		const button = e.target.closest('button[name]');
		if (button === null) {
			return;
		}
		const fields = getFormFields(this._form);
		switch (button.getAttribute('name')) {
			case 'add':
				this._index = this._nextIndex(list);
				this._open(this._common(scope), list);
				break;
			case 'add_group':
				this._index = this._nextIndex(list);
				this._open({...this._common(scope), group: <?= json_encode(_('New group')) ?>}, list);
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
				this._open({...(fields[list] || {})[this._index], ...this._common(scope), edit: 1}, list);
				break;
			}
			case 'remove':
				button.closest('tr').remove();
				this._reload();
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
		// The window the form says now, not the one it opened with.
		const days = fields.trend_days || this._trend_days;
		for (const [k, value, label] of values) {
			const box = document.getElementById('ep_cf_window_' + k);
			if (box === null || !box.checked) {
				continue;
			}
			// In the column's group, "7d avg" reads as that group's; a column of no group (Client capacity's) names its
			// copies after itself, or the headers would be a row of bare "7d avg".
			const name = String(base.group || '') !== '' ? `${days}d ${label}` : `${base.name} · ${days}d ${label}`;
			const copy = {...base, uid: '', value, window: '0', hidden: '0', name};
			this._index = this._nextIndex('columns');
			after = this._write(copy, 'columns', after);
		}
		this._reload();
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
		this._reload();
	}
};
