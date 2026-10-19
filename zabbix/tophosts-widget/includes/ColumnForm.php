<?php declare(strict_types = 0);

namespace Modules\EpTophosts\Includes;

use CButton, CButtonLink, CCheckBox, CCol, CDiv, CFormField, CFormFieldset, CFormGrid, CLabel, CList, CRow, CSelect, CSpan, CTable, CTabView, CTextBox, CVar,
	CWidgetFormView;
use CWidgetFieldCheckBoxView, CWidgetFieldIntegerBoxView, CWidgetFieldMultiSelectGroupView, CWidgetFieldRadioButtonListView, CWidgetFieldSelectView;
use Modules\EpTophosts\Lib\{ClientData, Columns, Settings};

/**
 * The parts of a column widget's settings form that are the same whichever widget it is — Client
 * resources, Client capacity, Volume and Top hosts: who is listed (host groups, clients, client
 * types, the trend window), the columns list with Add column / Add group / Add avg-min-max, the
 * server rows' columns (Client resources, the one widget whose clients open into server rows), the
 * bar style and Warn / High a column left alone follows, and the View tab's table, toolbar and
 * Needs attention. What is a widget's alone (Client resources' donuts, Top hosts' ranking) it draws
 * beside these.
 *
 * The behaviour is columns.form.js.php (EpColumnsForm); the element ids here are the ones it looks for.
 *
 * Copied from ../../shared/php/includes by ../../sync-assets.mjs — edit it there.
 */
class ColumnForm {

	private CWidgetFormView $form;
	private array $fields;

	public function __construct(CWidgetFormView $form, array $fields) {
		$this->form = $form;
		$this->fields = $fields;
	}

	public function hint(string $text): CSpan {
		return (new CSpan($text))->addClass('ep-ef-hint');
	}

	/** One line of a form grid: its label and its field. */
	public function row(CFormGrid $grid, $label, $field, ?string $class = null): void {
		$grid->addItem([
			$label instanceof \CTag ? $label->addClass($class) : $label,
			($field instanceof CFormField ? $field : new CFormField($field))->addClass($class)
		]);
	}

	/** Registers a field's view with the form, so its templates and script travel with it. */
	public function view($field_view) {
		return $this->form->registerField($field_view);
	}

	/** A list of checkboxes posting name[] — with an empty first entry, so none ticked still says so. */
	public function boxes(string $name, array $options, array $checked, string $class = ''): CDiv {
		$out = [(new CVar($name.'[]', ''))->removeId()];
		foreach ($options as $value => $label) {
			$out[] = (new CSpan((new CCheckBox($name.'[]', (string) $value))
				->setId(zbx_formatDomId($name.'_'.md5((string) $value)))
				->setLabel($label)
				->setChecked(in_array((string) $value, array_map('strval', $checked), true))))->addClass('ep-ef-box');
		}

		return (new CDiv($out))->addClass('ep-ef-inline')->addClass($class);
	}

	/* ------------------------------------------ Data ------------------------------------------ */

	/**
	 * Who is listed: host groups (when the widget has them), the clients — the ones ElasticPro knows;
	 * a name saved earlier that is no longer there stays ticked, marked — and the client types.
	 *
	 * @param array $cfg  Settings::all()
	 */
	public function who(CFormGrid $grid, array $cfg, ?string $clientsLabel = null): void {
		if (isset($this->fields['groupids'])) {
			$groups = $this->view(new CWidgetFieldMultiSelectGroupView($this->fields['groupids']));
			$this->row($grid, $groups->getLabel(), $groups->getView());
		}
		$known = [];
		try {
			[, $clients] = ClientData::clients($cfg, []);
			foreach ($clients as $c) {
				$known[$c['name']] = $c['name'].($c['badge'] !== '' ? ' ('.$c['badge'].')' : '');
			}
			uksort($known, 'strnatcasecmp');
		}
		catch (\Throwable $e) {
			$known = [];
		}
		$picked = $this->fields['clients']->getValue();
		foreach ($picked as $name) {
			if (!array_key_exists($name, $known)) {
				$known[$name] = _s('%1$s (not found)', $name);
			}
		}
		$this->row($grid, new CLabel($clientsLabel ?? _('Clients'), 'ep_cf_clients_filter'), [
			(new CDiv([
				(new CTextBox('ep_cf_clients_filter', '', false))->setId('ep_cf_clients_filter')->setWidth(ZBX_TEXTAREA_SMALL_WIDTH)
					->setAttribute('placeholder', _('type to filter'))->setAttribute('data-no-trim', '1')->removeAttribute('name'),
				$this->hint($picked ? _n('%1$s client picked', '%1$s clients picked', count($picked)) : _s('All clients · tick to pick from %1$s', count($known)))
					->setId('ep_cf_clients_note')
			]))->addClass('ep-ef-inline'),
			$this->boxes('clients', $known, $picked, 'ep-ef-clients')
		]);
		$types = Settings::types($cfg)['all'];
		$this->row($grid, new CLabel(_('Client types')), [
			$this->boxes('client_types', array_combine($types, $types), $this->fields['client_types']->getValue()),
			$this->hint(_('none ticked: every type'))
		]);
	}

	/** The trend window: what a column's average, minimum, maximum and change are taken over. */
	public function trendRow(CFormGrid $grid, ?string $what = null): void {
		$trend = $this->view(new CWidgetFieldIntegerBoxView($this->fields['trend_days']));
		$this->row($grid, $trend->getLabel(), (new CDiv([new CSpan(_('Last')), $trend->getView(), new CSpan(_('days')),
			$this->hint($what ?? _('a column\'s average, minimum, maximum and change are taken over this, from Zabbix trends, unless it sets its own'))
		]))->addClass('ep-ef-inline'));
	}

	/* ----------------------------------------- Columns ----------------------------------------- */

	/** Columns from: the report defaults or this widget, and Reset to report defaults. */
	public function columnsFrom(CFormGrid $grid, bool $own): void {
		$from = $this->view(new CWidgetFieldRadioButtonListView($this->fields['columns_from']));
		$this->row($grid, $from->getLabel(), [
			(new CDiv([$from->getView(),
				(new CButtonLink(_('Reset to report defaults')))->setId('ep_cf_reset')->setEnabled($own)
			]))->addClass('ep-ef-inline'),
			(new CDiv(_('Report defaults are set by a Super admin in the widget\'s Columns dialog and used by every widget that follows them.')))
				->addClass('ep-ef-hint ep-ef-hint-block')
		]);
	}

	/**
	 * The columns list. While the widget follows the report defaults it is shown as it is and cannot
	 * be changed here; its own, each row carries its column as hidden fields and the form posts them
	 * in the order the rows were dragged into.
	 *
	 * @param array         $columns    the columns in force
	 * @param array         $catalogue  the built-in columns
	 * @param callable|null $note       fn(array $column): ?string — a threshold that is not the column's own (Volume's plan macros)
	 */
	public function columnsList(CFormGrid $grid, array $columns, array $catalogue, bool $own, int $trendDays, array $usage, ?callable $note = null, ?string $help = null): void {
		$sourceText = function (array $c) use ($catalogue) {
			switch ((int) $c['source']) {
				case Columns::SOURCE_ITEM:
					return $c['key'].((int) $c['pattern'] === 1 ? ' '._('(pattern)') : '');
				case Columns::SOURCE_CALC:
					$name = fn(string $op) => strpos($op, Columns::KEY_OPERAND) === 0 ? substr($op, strlen(Columns::KEY_OPERAND)) : ($catalogue[$op]['key'] ?? $op);

					return $name($c['calc_a']).' '.(Columns::opLabels()[(int) $c['calc_op']] ?? '').' '.$name($c['calc_b']);
			}
			$b = $catalogue[$c['builtin']] ?? null;
			if ($b === null) {
				return $c['builtin'];
			}

			return $b['key'] ?? (isset($b['macro']) ? _('client macro').' '.$b['macro'] : _('client').' '.($b['field'] ?? ''));
		};
		$valueText = function (array $c) use ($trendDays) {
			if ((int) $c['value'] === Columns::VALUE_NOW) {
				return _('Now');
			}
			$days = $c['window'] > 0 ? (int) $c['window'] : $trendDays;

			return _s('%1$s over %2$s d', Columns::valueLabels()[(int) $c['value']] ?? '', $days);
		};
		$thresholdText = function (array $c) use ($catalogue, $usage, $note) {
			$b = (int) $c['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$c['builtin']] ?? null) : null;
			if ($b !== null && !empty($b['text'])) {
				return '—';
			}
			if ($note !== null && ($text = $note($c)) !== null) {
				return [$text, (new CSpan(_('plan template')))->addClass('ep-ef-tag')];
			}
			if ((int) $c['use_settings'] === 1) {
				$inherited = $c['warn'] === '' || $c['high'] === '';

				return [Columns::num($c['warn'] === '' ? (float) $usage['warn'] : (float) $c['warn']).' / '
					.Columns::num($c['high'] === '' ? (float) $usage['high'] : (float) $c['high']),
					$inherited ? (new CSpan(_('Settings')))->addClass('ep-ef-tag') : null];
			}
			if ($c['thresholds']) {
				return [implode(' · ', array_map(fn($t) => ((int) $t['level'] === Columns::LEVEL_HIGH ? 'H ' : 'W ').$t['value'], $c['thresholds'])),
					(int) $c['worse'] === Columns::WORSE_LOWER ? (new CSpan(_('lower is worse')))->addClass('ep-ef-tag') : null];
			}

			return (new CSpan('—'))->addClass('ep-ef-hint');
		};

		$list = (new CTable())
			->setId('ep-cf-columns')
			->addClass('ep-ef-metrics')
			->addClass($own ? null : 'ep-res-readonly')
			->setHeader(['', _('Show'), _('Group'), _('Name'), _('Source'), _('Value'), _('Display'), _('Warn / High'), _('Actions')]);
		foreach (array_values($columns) as $i => $c) {
			$c += Columns::DEFAULT;
			$list->addRow((new CRow([
				(new CCol($own ? (new CDiv())->addClass(ZBX_STYLE_DRAG_ICON) : ''))->addClass(ZBX_STYLE_TD_DRAG_ICON),
				(new CCheckBox('ep_show_'.$i))->setChecked((int) $c['hidden'] === 0)->removeAttribute('name')->setId('ep_cf_show_'.$i)
					->addClass('js-show')->setEnabled($own)->setAttribute('aria-label', _s('Show %1$s', $c['name'])),
				$c['group'] === '' ? (new CSpan('—'))->addClass('ep-ef-hint') : $c['group'],
				(new CDiv([$c['name'], (int) $c['source'] === Columns::SOURCE_ITEM ? (new CSpan(_('custom item')))->addClass('ep-ef-tag')
					: ((int) $c['source'] === Columns::SOURCE_CALC ? (new CSpan(_('calculated')))->addClass('ep-ef-tag') : null)]))->addClass('text'),
				(new CDiv($sourceText($c)))->addClass('ep-ef-mono')->setTitle($sourceText($c)),
				$valueText($c),
				$this->displayText($c),
				$thresholdText($c),
				$own ? (new CList([
					(new CButton('edit', _('Edit')))->addClass(ZBX_STYLE_BTN_LINK)->removeId(),
					(new CButton('remove', _('Remove')))->addClass(ZBX_STYLE_BTN_LINK)->removeId(),
					$this->hidden('columns', $i, $c)
				]))->addClass(ZBX_STYLE_HOR_LIST) : ''
			]))->addClass((int) $c['hidden'] === 1 ? 'ep-res-off' : null)->setAttribute('data-uid', $c['uid']));
		}
		$count = count($columns);
		if ($own) {
			$numbers = [];
			foreach (array_values($columns) as $i => $c) {
				$b = (int) $c['source'] === Columns::SOURCE_BUILTIN ? ($catalogue[$c['builtin']] ?? null) : null;
				if ($b === null || empty($b['text'])) {
					$numbers[(string) $i] = trim(($c['group'] !== '' ? $c['group'].' · ' : '').$c['name']);
				}
			}
			$list->addRow((new CCol([
				(new CButton('add', _('Add column')))->addClass(ZBX_STYLE_BTN_LINK)->setEnabled($count < Columns::MAX),
				' ',
				(new CButton('add_group', _('Add group')))->addClass(ZBX_STYLE_BTN_LINK)->setEnabled($count < Columns::MAX),
				' ',
				(new CButton('add_window', _('Add avg / min / max for a column')))->addClass(ZBX_STYLE_BTN_LINK)->setEnabled($count < Columns::MAX && $numbers),
				(new CDiv([
					(new CSelect('ep_cf_window_of'))->setId('ep_cf_window_of')->setFocusableElementId('ep_cf_window_of_btn')
						->addOptions(CSelect::createOptionsFromArray($numbers ?: ['' => '—']))->setAttribute('aria-label', _('Column')),
					(new CCheckBox('ep_cf_window_avg'))->setId('ep_cf_window_avg')->setLabel(_('Avg'))->setChecked(true)->removeAttribute('name'),
					(new CCheckBox('ep_cf_window_min'))->setId('ep_cf_window_min')->setLabel(_('Min'))->removeAttribute('name'),
					(new CCheckBox('ep_cf_window_max'))->setId('ep_cf_window_max')->setLabel(_('Max'))->setChecked(true)->removeAttribute('name'),
					(new CButton('add_window_go', _('Add')))->addClass(ZBX_STYLE_BTN_ALT)->removeId(),
					$this->hint(_s('over the widget\'s %1$s days, in the same group, right after it', $trendDays))
				]))->addClass('ep-ef-inline ep-res-window')->setId('ep_cf_window')->addStyle('display: none'),
				(new CDiv($help ?? _s('Drag rows to reorder; columns in a group stay together. Up to %1$s columns.', Columns::MAX)))
					->addClass('ep-ef-hint ep-ef-hint-block')
			]))->setColSpan(9));
		}
		else {
			$list->addRow((new CCol((new CDiv(_('These are the report defaults. Choose This widget to change them for this widget alone.')))->addClass('ep-ef-hint')))->setColSpan(9));
		}
		$this->row($grid, new CLabel(_('Columns')), (new CFormField($list))->addClass(ZBX_STYLE_TABLE_FORMS_SEPARATOR));
	}

	/**
	 * The server rows' columns (Role … Uptime) of a widget whose clients open into their servers:
	 * each on or off, dragged into order, each opening the same column editor (scope "row"). Like
	 * the columns list, shown as it is while the widget follows the report defaults.
	 *
	 * @param array $serverColumns  the server rows' columns in force
	 */
	public function serverRows(CFormGrid $grid, array $serverColumns, bool $own): void {
		$list = (new CTable())->setId('ep-cf-server-columns')->addClass('ep-ef-sortlist')->addClass($own ? null : 'ep-res-readonly');
		foreach (array_values($serverColumns) as $i => $c) {
			$c += Columns::DEFAULT;
			$list->addRow((new CRow([
				(new CCol($own ? (new CDiv())->addClass(ZBX_STYLE_DRAG_ICON) : ''))->addClass(ZBX_STYLE_TD_DRAG_ICON),
				(new CCheckBox('ep_srv_'.$i))->setChecked((int) $c['hidden'] === 0)->removeAttribute('name')->setId('ep_cf_srv_'.$i)->addClass('js-show')
					->setLabel($c['name'])->setEnabled($own),
				$this->hint($this->displayText($c)),
				$own ? [(new CButton('edit', _('Edit')))->addClass(ZBX_STYLE_BTN_LINK)->removeId(), $this->hidden('server_columns', $i, $c)] : ''
			]))->setAttribute('data-uid', $c['uid']));
		}
		$this->row($grid, new CLabel(_('Server rows')), [$list,
			$this->hint(_('When a client is expanded. Drag to reorder. Each opens the same column editor (bars, colours, thresholds).'))]);
	}

	/** How a list says a column shows: value, bar and its style, short numbers. */
	private function displayText(array $c): string {
		$parts = [Columns::showLabels()[(int) $c['show']] ?? ''];
		if (in_array((int) $c['show'], [Columns::SHOW_BAR, Columns::SHOW_INDICATORS], true)) {
			$parts[] = [Columns::BAR_WIDGET => _('widget style'), Columns::BAR_UNDER => _('under value'), Columns::BAR_CELL => _('in cell'),
				Columns::BAR_SEGMENTS => _s('segments (%1$s)', $c['segments']), Columns::BAR_GAUGE => _('gauge')][(int) $c['bar_style']] ?? '';
		}
		if ((int) $c['short'] === 1) {
			$parts[] = _('short numbers');
		}

		return implode(' · ', array_filter($parts));
	}

	/** A column's settings as hidden fields of the form, under $name[$i]. */
	private function hidden(string $name, int $i, array $c): array {
		$out = [(new CVar('sortorder['.$name.'][]', $i))->removeId()];
		foreach ($c as $k => $v) {
			if ($k === 'roles') {
				foreach (array_values($v) as $j => $r) {
					$out[] = (new CVar($name."[$i][roles][$j]", $r))->removeId();
				}
			}
			elseif ($k === 'thresholds') {
				foreach (array_values($v) as $j => $t) {
					foreach (['level', 'color', 'value'] as $tk) {
						$out[] = (new CVar($name."[$i][thresholds][$j][$tk]", (string) $t[$tk]))->removeId();
					}
				}
			}
			elseif (array_key_exists($k, Columns::DEFAULT)) {
				$out[] = (new CVar($name."[$i][$k]", (string) $v))->removeId();
			}
		}

		return $out;
	}

	/**
	 * The bar style, and the Warn and High a column using ElasticPro Settings' thresholds follows when
	 * it has none of its own. Empty is ElasticPro -> Settings, and the boxes say what that is today.
	 */
	public function barsAndThresholds(CFormGrid $grid, array $usage, bool $thresholds = true): void {
		$bars = $this->view(new CWidgetFieldSelectView($this->fields['bars']));
		$this->row($grid, $bars->getLabel(), [$bars->getView(), ' ', $this->hint(_('for every bar left at "Widget default"'))]);
		if (!$thresholds) {
			return;
		}
		$this->row($grid, new CLabel(_('Warn / High')), (new CDiv([
			new CSpan(_('Warn at, %')),
			(new CTextBox('usage_warn', $this->fields['usage_warn']->getValue(), false, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
				->setAttribute('placeholder', _s('Settings: %1$s', Columns::num((float) $usage['warn']))),
			new CSpan(_('High at, %')),
			(new CTextBox('usage_high', $this->fields['usage_high']->getValue(), false, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
				->setAttribute('placeholder', _s('Settings: %1$s', Columns::num((float) $usage['high']))),
			$this->hint(_('empty: ElasticPro → Settings, for every column using Settings; a client\'s own thresholds on its master host still win'))
		]))->addClass('ep-ef-inline'));
	}

	/* ------------------------------------------ View ------------------------------------------ */

	/**
	 * The table's options — density and row height, header, rows, totals, sort and top N — as a grid;
	 * first, when the widget has server rows, when they show (on click, all, none).
	 *
	 * @param array $sortOptions  value => label of what the table can be sorted by
	 */
	public function tableGrid(array $sortOptions, ?string $rowsLabel = null): CFormGrid {
		$f = $this->fields;
		$density = $this->view(new CWidgetFieldRadioButtonListView($f['density']));
		$groupHeaders = $this->view(new CWidgetFieldCheckBoxView($f['group_headers']));
		$sticky = $this->view(new CWidgetFieldCheckBoxView($f['sticky_header']));
		$freeze = $this->view(new CWidgetFieldCheckBoxView($f['freeze_first']));
		$striped = $this->view(new CWidgetFieldCheckBoxView($f['striped']));
		$totals = $this->view(new CWidgetFieldCheckBoxView($f['totals']));
		$totalsFn = $this->view(new CWidgetFieldSelectView($f['totals_fn']));
		$sortDir = $this->view(new CWidgetFieldSelectView($f['sort_dir']));
		$sortBy = $f['sort_by']->getValue();
		$grid = new CFormGrid();
		if (isset($f['server_rows'])) {
			$serverRows = $this->view(new CWidgetFieldRadioButtonListView($f['server_rows']));
			$this->row($grid, $serverRows->getLabel(), $serverRows->getView());
		}
		$this->row($grid, $density->getLabel(), (new CDiv([$density->getView(), new CSpan(_('row height')),
			(new CTextBox('row_height', $f['row_height']->getValue(), false, 3))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)
				->setAttribute('placeholder', _('auto'))->setAttribute('aria-label', _('Row height')),
			new CSpan(_('px'))]))->addClass('ep-ef-inline'));
		$this->row($grid, new CLabel(_('Header')), (new CDiv([$groupHeaders->getView(), $sticky->getView(), $freeze->getView()]))->addClass('ep-ef-inline'));
		$this->row($grid, new CLabel($rowsLabel ?? _('Rows')), (new CDiv([$striped->getView(), $totals->getView(), $totalsFn->getView()]))->addClass('ep-ef-inline'));
		$this->row($grid, new CLabel(_('Sort'), 'ep_cf_sort_by_btn'), (new CDiv([
			(new CSelect('sort_by'))->setId('ep_cf_sort_by')->setFocusableElementId('ep_cf_sort_by_btn')
				->setValue(array_key_exists($sortBy, $sortOptions) ? $sortBy : array_key_first($sortOptions))->addOptions(CSelect::createOptionsFromArray($sortOptions)),
			$sortDir->getView(),
			new CSpan(_('top')),
			(new CTextBox('top_n', $f['top_n']->getValue(), false, 4))->setWidth(ZBX_TEXTAREA_NUMERIC_STANDARD_WIDTH)->setAttribute('placeholder', _('all'))
				->setAttribute('aria-label', _('Top N'))
		]))->addClass('ep-ef-inline'));

		return $grid;
	}

	/** What the toolbar can show (value => label), and where. */
	public function toolbarGrid(array $items): CFormGrid {
		$pos = $this->view(new CWidgetFieldRadioButtonListView($this->fields['toolbar_pos']));
		$grid = new CFormGrid();
		$this->row($grid, new CLabel(_('Show')), $this->boxes('toolbar_items', $items, $this->fields['toolbar_items']->getValue()));
		$this->row($grid, $pos->getLabel(), $pos->getView());

		return $grid;
	}

	/** What "Needs attention" means (value => label) and whether the widget starts on it. */
	public function attentionGrid(array $criteria): CFormGrid {
		$default = $this->view(new CWidgetFieldRadioButtonListView($this->fields['attention_default']));
		$grid = new CFormGrid();
		$this->row($grid, new CLabel(_('Any of')), $this->boxes('attention', $criteria, $this->fields['attention']->getValue()));
		$this->row($grid, $default->getLabel(), $default->getView());

		return $grid;
	}

	/**
	 * What a table can be sorted by: the name column, then every column that shows.
	 *
	 * @param string $first  the label of the first column's own sort ('client')
	 */
	public static function sortOptions(array $columns, string $first): array {
		$out = ['client' => $first];
		foreach ($columns as $c) {
			if ((int) ($c['hidden'] ?? 0) === 0) {
				$out[$c['uid']] = trim(($c['group'] !== '' ? $c['group'].' · ' : '').$c['name']);
			}
		}

		return $out;
	}

	/** The tabs of the form, started on their own element (makeJavascript() initialises the tabs on the id CTabView was built with). */
	public function tabs(string $id, array $tabs): CTabView {
		$view = new CTabView(['id' => $id]);
		foreach ($tabs as $tab => [$title, $content]) {
			$view->addTab($tab, $title, $content);
		}

		return $view->addClass('ep-ef-tabs')->setSelected(0);
	}
}
