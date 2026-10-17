<?php declare(strict_types = 0);
/**
 * The metric editor's behaviour: which rows show for the source chosen, the thresholds table,
 * "Check" for the item key, and Update — which posts the metric to MetricEdit and hands what
 * comes back (the metric as it will be stored) to the widget form.
 *
 * @var CView $this
 */

use Modules\EpOverview\Includes\Metrics;

?>

window.ep_overview_metric_form = new class {

	init({form_name, thresholds, catalogue, decimals, coverage_url}) {
		this._form = document.forms[form_name];
		this._$form = jQuery(this._form);
		this._catalogue = catalogue;
		this._decimals = decimals;
		this._coverage_url = coverage_url;
		this._$thresholds = this._$form.find('#ep_ov_metric_thresholds');

		this._$thresholds.dynamicRows({
			rows: thresholds,
			template: '#ep-ov-threshold-row',
			allow_empty: true,
			dataCallback: (row) => {
				if (!('level' in row)) {
					row.level = this._$thresholds[0].querySelectorAll('tr.form_row').length === 0
						? '<?= Metrics::LEVEL_WARN ?>'
						: '<?= Metrics::LEVEL_HIGH ?>';
				}
				if (!('color' in row)) {
					row.color = '';
				}
				if (!('value' in row)) {
					row.value = '';
				}
			}
		});
		const colorpickers = () => {
			this._$thresholds.find('tr.form_row input[name$="[color]"]').each((i, input) => {
				if (!input.dataset.epInit) {
					input.dataset.epInit = '1';
					jQuery(input).colorpicker({appendTo: jQuery(input).closest('.input-color-picker')});
				}
			});
		};
		colorpickers();
		this._$thresholds.on('afteradd.dynamicRows', () => colorpickers());

		this._form.addEventListener('change', (e) => {
			if (e.target.matches('input[type="text"]')) {
				e.target.value = e.target.value.trim();
			}
			if (e.target.id === 'ep_ov_metric_roles_all' && e.target.checked) {
				this._form.querySelectorAll('.js-role').forEach((box) => box.checked = false);
			}
			if (e.target.matches('.js-role') && e.target.checked) {
				this._form.querySelector('#ep_ov_metric_roles_all').checked = false;
			}
			if (e.target.name === 'builtin') {
				this._prefill();
			}
			if (e.target.name === 'source') {
				this._sourceChanged();
			}
			this._update();
		}, {capture: true});
		this._form.querySelector('[name="builtin"]').addEventListener('change', () => { this._prefill(); this._update(); });
		this._form.querySelector('#ep_ov_metric_check').addEventListener('click', () => this._check());

		this._$form.on('process.form', (e, overlay) => this._submit(overlay));

		// What the user types is theirs; what the editor filled in from a built-in is not.
		for (const name of ['name', 'units']) {
			this._form.querySelector(`[name="${name}"]`).addEventListener('input', (e) => delete e.target.dataset.epAuto);
		}
		this._source = this._val('source');
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

	_builtin() {
		return this._catalogue[this._val('builtin')] || null;
	}

	/**
	 * The name follows the built-in figure chosen while it is still a built-in's own label (or
	 * empty): choosing another figure must not leave "Engine CPU" reading ES storage.
	 */
	_prefill() {
		const name = this._form.querySelector('[name="name"]');
		const b = this._builtin();
		const isLabel = Object.values(this._catalogue).some((c) => c.label === name.value);
		if (b && (name.value === '' || name.dataset.epAuto === '1' || isLabel)) {
			name.value = b.label;
			name.dataset.epAuto = '1';
		}
	}

	/**
	 * Switching to a Zabbix item clears the name and units a built-in filled in, and brings back
	 * the units typed for the item before; switching to a built-in fills both from it again and
	 * keeps the typed units aside.
	 */
	_sourceChanged() {
		const source = this._val('source');
		if (source === this._source) {
			return;
		}
		this._source = source;
		const name = this._form.querySelector('[name="name"]');
		const units = this._form.querySelector('[name="units"]');
		if (source === '<?= Metrics::SOURCE_ITEM ?>') {
			const isLabel = Object.values(this._catalogue).some((c) => c.label === name.value);
			if (name.dataset.epAuto === '1' || isLabel) {
				name.value = '';
				delete name.dataset.epAuto;
			}
			// Back to an item: the units typed for it come back; a built-in's never stay.
			units.value = this._typedUnits ?? (units.dataset.epAuto === '1' ? '' : units.value);
			delete units.dataset.epAuto;
		}
		else {
			// Leaving the item: keep what was typed for it, as a typed name is kept.
			if (units.dataset.epAuto !== '1') {
				this._typedUnits = units.value;
			}
			this._prefill();
		}
	}

	_update() {
		const builtin = this._val('source') === '<?= Metrics::SOURCE_BUILTIN ?>';
		const b = builtin ? this._builtin() : null;
		const onServers = !builtin || (b !== null && b.on === '<?= Metrics::ON_SERVERS ?>');
		const settingsAllowed = b !== null && b.settings;
		const useSettings = settingsAllowed && this._form.querySelector('#ep_ov_metric_use_settings').checked;
		const show = (selector, on) => this._form.querySelectorAll(selector).forEach((el) => el.style.display = on ? '' : 'none');

		show('.js-builtin', builtin);
		show('.js-item', !builtin);
		show('.js-servers', onServers);
		show('.js-bar', ['<?= Metrics::DISPLAY_BAR ?>', '<?= Metrics::DISPLAY_INDICATORS ?>'].includes(this._val('display')));

		const units = this._form.querySelector('[name="units"]');
		units.readOnly = builtin;
		if (b !== null) {
			units.value = b.units;
			units.dataset.epAuto = '1';
		}
		const dec = this._decimals[units.value === '%' ? '%' : (units.value === '' ? '' : 'B')];
		this._form.querySelector('[name="decimals"]').placeholder = <?= json_encode(_('Settings')) ?> + (dec !== undefined ? `: ${dec}` : '');
		this._form.querySelector('#ep_ov_metric_builtin_key').textContent = b !== null ? b.key : '';

		const us = this._form.querySelector('#ep_ov_metric_use_settings');
		us.disabled = !settingsAllowed;
		if (!settingsAllowed) {
			us.checked = false;
		}
		this._form.querySelector('#ep_ov_metric_settings_values').style.display = useSettings ? '' : 'none';
		this._form.querySelector('#ep_ov_metric_own').style.display = useSettings ? 'none' : '';
		// Settings' thresholds are "this high is bad": Worse when is theirs while they are used.
		this._form.querySelectorAll('[name="worse"]').forEach((r) => r.disabled = useSettings);
		const lower = this._val('worse') === '<?= Metrics::WORSE_LOWER ?>' && !useSettings;
		this._form.querySelector('#ep_ov_metric_th_head span').textContent = lower
			? <?= json_encode(_('At or below')) ?> : <?= json_encode(_('At or above')) ?>;

		this._form.querySelector('[name="window"]').disabled = this._val('window_own') !== '1';
	}

	_check() {
		const out = this._form.querySelector('#ep_ov_metric_coverage');
		const body = new URLSearchParams();
		body.append('key', this._val('key'));
		body.append('pattern', this._form.querySelector('#ep_ov_metric_pattern').checked ? '1' : '0');
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
		for (const name of ['pattern', 'short', 'trend_avg', 'trend_min', 'trend_max', 'use_settings']) {
			const box = this._form.querySelector(`[name="${name}"]`);
			fields[name] = box && box.checked && !box.disabled ? '1' : '0';
		}
		if (this._val('window_own') !== '1') {
			fields.window = '0';
		}
		// A built-in left unnamed is called what it is.
		const b = this._val('source') === '<?= Metrics::SOURCE_BUILTIN ?>' ? this._builtin() : null;
		if ((fields.name || '') === '' && b !== null) {
			fields.name = b.label;
		}
		if (!('worse' in fields)) {
			fields.worse = '<?= Metrics::WORSE_HIGHER ?>';
		}
		delete fields.window_own;
		delete fields.roles_all;
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
