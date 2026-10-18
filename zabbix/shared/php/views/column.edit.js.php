<?php declare(strict_types = 0);
/**
 * The column editor's behaviour: which rows show for the source and the look chosen, the
 * thresholds table, "Check" for the item key, the preview, and Update — which posts the column
 * to ColumnEdit and hands what comes back (the column as it will be stored, or the errors, each
 * naming the column) to the widget form.
 *
 * Shared: ../../../sync-assets.mjs copies this into the widgets that offer the column editor.
 *
 * @var CView $this
 */

use EpShared\Columns;

?>

window.ep_column_form = new class {

	init({form_name, thresholds, catalogue, decimals, usage, row, coverage_url}) {
		this._form = document.forms[form_name];
		this._$form = jQuery(this._form);
		this._catalogue = catalogue;
		this._decimals = decimals;
		this._usage = usage;
		this._row = row;
		this._coverage_url = coverage_url;
		this._$thresholds = this._$form.find('#ep_ce_thresholds');

		this._$thresholds.dynamicRows({
			rows: thresholds,
			template: '#ep-ce-threshold-row',
			allow_empty: true,
			dataCallback: (r) => {
				if (!('level' in r)) {
					r.level = this._$thresholds[0].querySelectorAll('tr.form_row').length === 0
						? '<?= Columns::LEVEL_WARN ?>'
						: '<?= Columns::LEVEL_HIGH ?>';
				}
				if (!('color' in r)) {
					r.color = '';
				}
				if (!('value' in r)) {
					r.value = '';
				}
			}
		});
		// Zabbix 7.0's CColor is a div.color-picker holding a hidden input: the colour picker adds the
		// swatch button beside it, and its dialog (pick, or type a hex) opens in this popup's body, as
		// the core widgets' forms do. Empty is "the palette's colour" (use default).
		const colorpickers = () => {
			this._form.querySelectorAll('.<?= ZBX_STYLE_COLOR_PICKER ?> input, .input-color-picker input').forEach((input) => {
				if (!input.dataset.epInit) {
					input.dataset.epInit = '1';
					const body = jQuery(input).closest('.overlay-dialogue-body');
					jQuery(input).colorpicker({
						appendTo: body.length ? body : 'body',
						use_default: true,
						// The picker sets the value with jQuery; the preview listens for input.
						onUpdate: function () { this.dispatchEvent(new Event('input', {bubbles: true})); }
					});
				}
			});
		};
		colorpickers();
		this._$thresholds.on('afteradd.dynamicRows', () => colorpickers());

		this._form.addEventListener('change', (e) => {
			if (e.target.matches('input[type="text"]')) {
				e.target.value = e.target.value.trim();
			}
			if (e.target.id === 'ep_ce_roles_all' && e.target.checked) {
				this._form.querySelectorAll('.js-role').forEach((box) => box.checked = false);
			}
			if (e.target.matches('.js-role') && e.target.checked) {
				this._form.querySelector('#ep_ce_roles_all').checked = false;
			}
			if (e.target.name === 'builtin') {
				this._prefill();
			}
			if (e.target.id === 'ep_ce_use_settings') {
				delete e.target.dataset.epWas;
			}
			this._update();
		}, {capture: true});
		this._form.addEventListener('input', () => this._preview());
		const builtin = this._form.querySelector('[name="builtin"]');
		if (builtin !== null) {
			builtin.addEventListener('change', () => { this._prefill(); this._update(); });
		}
		for (const name of ['calc_a_pick', 'calc_b_pick', 'calc_op', 'value', 'combine', 'size_unit', 'nodata', 'align']) {
			const el = this._form.querySelector(`[name="${name}"]`);
			if (el !== null) {
				el.addEventListener('change', () => this._update());
			}
		}
		const check = this._form.querySelector('#ep_ce_check');
		if (check !== null) {
			check.addEventListener('click', () => this._check());
		}

		this._$form.on('process.form', (e, overlay) => this._submit(overlay));

		// What the user types is theirs; what the editor filled in from a built-in is not.
		for (const name of ['name', 'group']) {
			this._form.querySelector(`[name="${name}"]`).addEventListener('input', (e) => delete e.target.dataset.epAuto);
		}
		if (this._form.querySelector('[name="name"]').value === '') {
			this._prefill();
		}
		this._update();
		this._form.style.display = '';
		this._form.querySelector('[name="name"]').focus();
	}

	_val(name) {
		const checked = this._form.querySelector(`[name="${name}"]:checked`);
		if (checked) {
			return checked.value;
		}
		const el = this._form.querySelector(`[name="${name}"]`);
		return el ? el.value : '';
	}

	_checked(name) {
		const box = this._form.querySelector(`[name="${name}"]`);
		return box !== null && box.checked && !box.disabled;
	}

	_builtin() {
		return this._val('source') === '<?= Columns::SOURCE_BUILTIN ?>' ? (this._catalogue[this._val('builtin')] || null) : null;
	}

	/** A calculated column's operand as it is stored: a built-in id, or key: and an item key. */
	_operand(name) {
		const pick = this._val(name + '_pick');
		return pick === '__key' ? '<?= Columns::KEY_OPERAND ?>' + this._val(name + '_key').trim() : pick;
	}

	/**
	 * The name (and the group, while empty) follow the built-in chosen while they are still a
	 * built-in's own: choosing another must not leave "ES CPU" reading ES storage.
	 */
	_prefill() {
		const b = this._builtin();
		if (b === null) {
			return;
		}
		const name = this._form.querySelector('[name="name"]');
		const group = this._form.querySelector('[name="group"]');
		const isLabel = Object.values(this._catalogue).some((c) => c.label === name.value);
		if (name.value === '' || name.dataset.epAuto === '1' || isLabel) {
			name.value = b.label;
			name.dataset.epAuto = '1';
		}
		if (group.value === '' || group.dataset.epAuto === '1') {
			group.value = b.group;
			group.dataset.epAuto = '1';
		}
	}

	/** Whether the column's level is judged on a percentage: the only kind Settings' thresholds are for. */
	_percent() {
		const source = this._val('source');
		if (source === '<?= Columns::SOURCE_BUILTIN ?>') {
			const b = this._builtin();
			return b !== null && (b.settings || b.pct);
		}
		if (source === '<?= Columns::SOURCE_CALC ?>') {
			return this._val('calc_op') === '<?= Columns::OP_OF ?>' || this._val('units') === '%';
		}
		return this._val('units') === '%';
	}

	_update() {
		const source = this._val('source');
		const b = this._builtin();
		const text = b !== null && b.text;
		const operandOnServers = (name) => {
			const op = this._operand(name);
			return op.startsWith('<?= Columns::KEY_OPERAND ?>') || (this._catalogue[op] && this._catalogue[op].on === '<?= Columns::ON_SERVERS ?>');
		};
		const onServers = !this._row && (source === '<?= Columns::SOURCE_ITEM ?>'
			|| (source === '<?= Columns::SOURCE_CALC ?>' && (operandOnServers('calc_a') || operandOnServers('calc_b')))
			|| (b !== null && b.on === '<?= Columns::ON_SERVERS ?>'));
		const show = (selector, on) => this._form.querySelectorAll(selector).forEach((el) => el.style.display = on ? '' : 'none');

		show('.js-builtin', source === '<?= Columns::SOURCE_BUILTIN ?>');
		show('.js-item', source === '<?= Columns::SOURCE_ITEM ?>');
		show('.js-calc', source === '<?= Columns::SOURCE_CALC ?>');
		show('.js-servers', onServers);
		show('.js-number', !text);
		const showAs = this._val('show');
		show('.js-bar', !text && ['<?= Columns::SHOW_BAR ?>', '<?= Columns::SHOW_INDICATORS ?>'].includes(showAs));
		for (const name of ['calc_a', 'calc_b']) {
			const key = this._form.querySelector(`[name="${name}_key"]`);
			if (key !== null) {
				key.style.display = this._val(name + '_pick') === '__key' ? '' : 'none';
			}
		}

		// The value over a window: a server row and a text column show theirs now.
		const valueRow = this._form.querySelector('[name="value"]');
		if (valueRow !== null) {
			valueRow.closest('.form-field').style.display = this._row || text ? 'none' : '';
			valueRow.closest('.form-field').previousElementSibling.style.display = this._row || text ? 'none' : '';
		}
		show('.js-window', this._val('value') !== '<?= Columns::VALUE_NOW ?>');

		const units = this._form.querySelector('[name="units"]');
		units.readOnly = b !== null;
		if (b !== null) {
			units.value = b.units;
		}
		const dec = this._decimals[units.value === '%' ? '%' : (units.value === '' ? '' : 'B')];
		this._form.querySelector('[name="decimals"]').placeholder = <?= json_encode(_('Settings')) ?> + (dec !== undefined ? `: ${dec}` : '');
		const keyHint = this._form.querySelector('#ep_ce_builtin_key');
		if (keyHint !== null) {
			keyHint.textContent = b !== null ? (b.key || '') : '';
		}

		const segments = this._form.querySelector('[name="segments"]');
		if (segments !== null) {
			segments.style.display = this._val('bar_style') === '<?= Columns::BAR_SEGMENTS ?>' ? '' : 'none';
		}
		const fixed = this._form.querySelector('[name="bar_fixed"]');
		if (fixed !== null) {
			fixed.closest('.ep-ce-color').style.opacity = this._val('bar_color') === '<?= Columns::BARCOLOR_FIXED ?>' ? '' : '.4';
		}

		const allowed = !text && this._percent();
		const us = this._form.querySelector('#ep_ce_use_settings');
		us.disabled = !allowed;
		// Unticked because the figure is not a percentage, ticked again when it is: what was chosen stays chosen.
		if (!allowed) {
			if (us.checked) {
				us.dataset.epWas = '1';
			}
			us.checked = false;
		}
		else if (us.dataset.epWas === '1') {
			us.checked = true;
			delete us.dataset.epWas;
		}
		const useSettings = allowed && us.checked;
		this._form.querySelector('#ep_ce_settings_values').style.display = useSettings ? '' : 'none';
		this._form.querySelector('#ep_ce_own').style.display = useSettings ? 'none' : '';
		// Settings' thresholds are "this high is bad": Higher is worse is theirs while they are used.
		this._form.querySelectorAll('[name="worse"]').forEach((r) => r.disabled = useSettings);
		const lower = this._val('worse') === '<?= Columns::WORSE_LOWER ?>' && !useSettings;
		this._form.querySelector('#ep_ce_th_head span').textContent = lower ? <?= json_encode(_('At or below')) ?> : <?= json_encode(_('At or above')) ?>;
		this._preview();
	}

	/** The look on three sample figures and an unknown one, in the palette's colours. */
	_preview() {
		const out = this._form.querySelector('#ep_ce_preview');
		if (out === null) {
			return;
		}
		const showAs = this._val('show');
		const style = this._val('bar_style');
		const n = Math.max(2, Math.min(50, parseInt(this._val('segments'), 10) || 12));
		const warn = parseFloat(this._val('warn')) || Number(this._usage.warn);
		const high = parseFloat(this._val('high')) || Number(this._usage.high);
		const level = (p) => p >= high ? 'high' : (p >= warn ? 'warn' : '');
		out.textContent = '';
		for (const [label, p] of [['A', 56], ['B', 84], ['C', 93], ['D', null]]) {
			const line = document.createElement('div');
			line.className = 'ep-ce-pv';
			const name = document.createElement('span');
			name.textContent = <?= json_encode(_('Client')) ?> + ' ' + label;
			const cell = document.createElement('span');
			const lv = p === null ? '' : level(p);
			cell.className = 'ep-ce-pv-cell' + (lv ? ' ep-' + lv : '');
			if (p === null) {
				cell.textContent = this._val('nodata') === '<?= Columns::NODATA_WHY ?>' ? <?= json_encode(_('allocated not set')) ?> : '—';
				cell.classList.add('ep-nodata');
			}
			else if (showAs === '<?= Columns::SHOW_BAR ?>' || showAs === '<?= Columns::SHOW_INDICATORS ?>') {
				const bar = document.createElement('span');
				if (showAs === '<?= Columns::SHOW_INDICATORS ?>' || style === '<?= Columns::BAR_SEGMENTS ?>') {
					bar.className = 'ep-bar-segs';
					const count = showAs === '<?= Columns::SHOW_INDICATORS ?>' ? 10 : n;
					for (let i = 0; i < count; i++) {
						const seg = document.createElement('i');
						if (i < Math.round(p / 100 * count)) {
							seg.className = 'ep-seg-on';
						}
						bar.appendChild(seg);
					}
				}
				else if (style === '<?= Columns::BAR_GAUGE ?>') {
					bar.className = 'ep-gauge';
					bar.style.setProperty('--p', String(p));
				}
				else {
					bar.className = 'ep-bar-track ep-ce-pv-track';
					const fill = document.createElement('span');
					fill.className = 'ep-bar-fill';
					fill.style.width = p + '%';
					bar.appendChild(fill);
				}
				cell.classList.add('ep-barcell');
				cell.append(bar, ' ', `${p} %`);
			}
			else if (showAs === '<?= Columns::SHOW_CELL ?>') {
				cell.textContent = `${p} %`;
				if (lv) {
					cell.classList.add('ep-fill', 'ep-fill-' + lv);
				}
			}
			else if (showAs === '<?= Columns::SHOW_BADGE ?>') {
				const badge = document.createElement('span');
				badge.className = 'ep-badge ' + (lv ? 'ep-badge-' + lv : 'ep-badge-ok');
				badge.textContent = `${p} %`;
				cell.appendChild(badge);
			}
			else {
				cell.textContent = `${p} %`;
				if (showAs === '<?= Columns::SHOW_VALUE ?>') {
					cell.className = 'ep-ce-pv-cell';
				}
			}
			line.append(name, cell);
			out.appendChild(line);
		}
	}

	_check() {
		const out = this._form.querySelector('#ep_ce_coverage');
		const body = new URLSearchParams();
		body.append('key', this._val('key'));
		body.append('pattern', this._form.querySelector('#ep_ce_pattern').checked ? '1' : '0');
		this._form.querySelectorAll('.js-role:checked').forEach((box) => body.append('roles[]', box.value));
		out.textContent = <?= json_encode(_('Checking…')) ?>;
		fetch(this._coverage_url, {method: 'POST', body})
			.then((r) => r.json())
			.then((r) => {
				out.textContent = r.found === null
					? <?= json_encode(_('Not an item key yet.')) ?>
					: <?= json_encode(_('Found on %1$s of %2$s client servers. Clients without it show "not collected", never 0.')) ?>
						.replace('%1$s', r.found).replace('%2$s', r.total);
			})
			.catch(() => out.textContent = <?= json_encode(_('Could not check.')) ?>);
	}

	/** What the editor sends: every box said, ticked or not, so an unticked one is 0 and not the default. */
	_fields() {
		const fields = getFormFields(this._form);
		for (const name of ['pattern', 'short', 'show_value', 'show_pct', 'wrap', 'use_settings']) {
			fields[name] = this._checked(name) ? '1' : '0';
		}
		fields.calc_a = this._operand('calc_a');
		fields.calc_b = this._operand('calc_b');
		for (const name of ['calc_a_pick', 'calc_b_pick', 'calc_a_key', 'calc_b_key', 'roles_all']) {
			delete fields[name];
		}
		// A built-in left unnamed is called what it is.
		const b = this._builtin();
		if ((fields.name || '') === '' && b !== null) {
			fields.name = b.label;
		}
		if (!('worse' in fields)) {
			fields.worse = '<?= Columns::WORSE_HIGHER ?>';
		}
		if (!('roles' in fields)) {
			fields.roles = [];
		}
		return fields;
	}

	_submit(overlay) {
		const curl = new Curl(this._form.getAttribute('action'));
		fetch(curl.getUrl(), {
			method: 'POST',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: urlEncodeData(this._fields())
		})
			.then((response) => response.json())
			.then((response) => {
				if ('error' in response) {
					throw {error: response.error};
				}
				overlayDialogueDestroy(overlay.dialogueid);
				overlay.$dialogue[0].dispatchEvent(new CustomEvent('dialogue.submit', {detail: response}));
			})
			.catch((exception) => {
				for (const el of this._form.parentNode.children) {
					if (el.matches('.msg-good, .msg-bad, .msg-warning')) {
						el.parentNode.removeChild(el);
					}
				}
				const error = typeof exception === 'object' && 'error' in exception ? exception.error : null;
				const box = makeMessageBox('bad', error ? error.messages : [<?= json_encode(_('Unexpected server error.')) ?>],
					error ? error.title : null)[0];
				this._form.parentNode.insertBefore(box, this._form);
			})
			.finally(() => overlay.unsetLoading());
	}
};
