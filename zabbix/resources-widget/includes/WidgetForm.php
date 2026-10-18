<?php declare(strict_types = 0);

namespace Modules\EpResources\Includes;

use Modules\EpResources\Lib\{Bars, ColumnSettings, Roles, Settings};
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{CWidgetFieldCheckBox, CWidgetFieldIntegerBox, CWidgetFieldMultiSelectGroup, CWidgetFieldRadioButtonList,
	CWidgetFieldSelect, CWidgetFieldTextBox};

/**
 * Client resources' settings, on three tabs.
 *
 * Data: which clients (host groups, clients by name, client types) and the trend window that a
 * column's average, minimum, maximum and change are taken over.
 *
 * Columns: where the columns come from — the report defaults a Super admin sets in the widget's
 * Columns dialog, or this widget's own list — and that list: the client row's columns and the
 * server row's, each edited in the column editor; the bar style and the Warn / High a column
 * left alone follows (empty: ElasticPro -> Settings).
 *
 * View: Table or Pie chart, and whether viewers may switch; the Pie chart's donuts, centre,
 * legend, short, layout and chips; the Table's server rows, density, header, rows, totals and
 * default sort; the toolbar's items and where it shows; and what "Needs attention" means.
 *
 * A widget saved before 2.1.1 has none of these fields but groupids, bars and the two thresholds,
 * and every default here is how the widget looked then: it follows the report defaults (which a
 * version-1 Columns file is read into unchanged), a compact table with group headers, a sticky
 * header and a frozen first column, servers on click, the toolbar on hover with search, type,
 * expand all, Columns and export. A Warn or High stored as 0 — Zabbix's integer box saving an
 * empty box — is read as empty: ElasticPro -> Settings, which is what it always meant.
 */
class WidgetForm extends CWidgetForm {

	/** The Display setting stores the position in Bars::STYLES; Zabbix wants an integer here. */
	public const BARS_DEFAULT = Bars::INHERIT;

	public const FROM_DEFAULTS = 0;
	public const FROM_WIDGET = 1;

	public const VIEW_TABLE = 0;
	public const VIEW_PIE = 1;

	public const CENTRE_PCT_ALLOCATED = 0;
	public const CENTRE_PCT_REQUESTED = 1;
	public const CENTRE_USED = 2;
	public const CENTRE_FREE = 3;

	public const SHORT_HATCHED = 0;
	public const SHORT_SOLID = 1;
	public const SHORT_HIDDEN = 2;

	public const SIZE_SMALL = 0;
	public const SIZE_MEDIUM = 1;
	public const SIZE_LARGE = 2;

	public const ROWS_CLICK = 0;
	public const ROWS_ALL = 1;
	public const ROWS_NONE = 2;

	public const DENSITY_COMFORTABLE = 0;
	public const DENSITY_COMPACT = 1;

	public const TOTALS_SUM = 0;
	public const TOTALS_AVG = 1;

	public const SORT_ASC = 0;
	public const SORT_DESC = 1;

	public const TOOLBAR_ALWAYS = 0;
	public const TOOLBAR_HOVER = 1;
	public const TOOLBAR_HIDDEN = 2;

	public const ATTENTION_ALL = 0;
	public const ATTENTION_ONLY = 1;

	public const LEGEND = ['used', 'free', 'short', 'allocated', 'requested', 'pct'];
	public const TOOLBAR = ['search', 'type', 'attention', 'compact', 'view', 'expand', 'columns', 'export'];
	/** The toolbar a widget saved before 2.1.1 had. */
	public const TOOLBAR_DEFAULT = ['search', 'type', 'expand', 'columns', 'export'];
	public const ATTENTION = ['warn', 'high', 'down', 'short', 'nodata', 'disk30'];
	public const ATTENTION_DEFAULT = ['warn', 'high', 'down', 'short'];

	protected function normalizeValues(array $values): array {
		$values = parent::normalizeValues($values);

		$model = ColumnSettings::loadModel(Catalogue::REPORT);

		return self::upgrade($values, Roles::load(), $model);
	}

	/**
	 * The settings as the fields expect them, from whatever came in: a saved widget (old or new),
	 * the form, or the dashboard. Pure, for the tests.
	 *
	 * @param array $model  the report defaults, as ColumnSettings::loadModel() reads them
	 */
	public static function upgrade(array $values, array $roles, array $model): array {
		// The lists reordered by dragging: the form posts each row's old position in its new order.
		foreach (['columns', 'server_columns'] as $list) {
			if (isset($values['sortorder'][$list]) && isset($values[$list]) && is_array($values[$list])) {
				$sorted = [];
				foreach ((array) $values['sortorder'][$list] as $index) {
					if (isset($values[$list][$index])) {
						$sorted[] = $values[$list][$index];
					}
				}
				$values[$list] = $sorted;
			}
		}
		unset($values['sortorder']);

		// A threshold of 0 was the integer box's empty box: ElasticPro -> Settings.
		foreach (['usage_warn', 'usage_high'] as $k) {
			if (array_key_exists($k, $values) && is_numeric($values[$k]) && (float) $values[$k] <= 0) {
				$values[$k] = '';
			}
		}

		// Donuts: the form posts the order of every measure and the ones ticked.
		if (array_key_exists('pie_shown', $values)) {
			$values['pie_donuts'] = array_values(array_filter((array) $values['pie_shown'], fn($v) => is_string($v) && $v !== '')) ?: '';
			unset($values['pie_shown']);
		}

		// This widget's own columns: a widget switched to them starts from the report defaults, and
		// a column the catalogue has gained since (a role added in Cluster Management) is offered
		// unticked.
		if ((int) ($values['columns_from'] ?? self::FROM_DEFAULTS) === self::FROM_WIDGET) {
			$catalogue = Catalogue::columns($roles, $model['split']);
			$own = array_values(array_filter((array) ($values['columns'] ?? []), 'is_array'));
			if (!$own) {
				$own = ColumnSettings::resolve($model['columns'], Catalogue::forScope($catalogue, 'client'), $model['legacy'], $model['new_hidden']);
			}
			else {
				$own = ColumnSettings::resolve($own, Catalogue::forScope($catalogue, 'client'), null, true);
			}
			$values['columns'] = $own;
			$rows = array_values(array_filter((array) ($values['server_columns'] ?? []), 'is_array'));
			$values['server_columns'] = $rows
				? ColumnSettings::resolve($rows, Catalogue::forScope($catalogue, 'row'), null, true)
				: ($model['server_columns'] ?? Catalogue::serverDefaults());
		}
		else {
			// Following the report defaults: nothing of the widget's own is kept.
			unset($values['columns'], $values['server_columns']);
		}

		return $values;
	}

	/**
	 * The fields check their own ranges and the column rule; this checks what must agree, when
	 * the form is applied. A saved widget that disagrees still draws.
	 */
	public function validate(bool $strict = false): array {
		$errors = parent::validate($strict);

		if ($strict && !$errors) {
			$v = $this->getFieldsValues();
			$set = fn($k) => isset($v[$k]) && $v[$k] !== '' && is_numeric($v[$k]);
			if ($set('usage_warn') && $set('usage_high') && (float) $v['usage_warn'] > (float) $v['usage_high']) {
				$errors[] = _s('Invalid parameter "%1$s": %2$s.', _('Warn at, %'),
					_s('must not be above High at, %% (%1$s > %2$s)', $v['usage_warn'], $v['usage_high']));
			}
		}

		return $errors;
	}

	public function addFields(): self {
		$roles = Roles::load();
		$cfg = Settings::all();
		$model = ColumnSettings::loadModel(Catalogue::REPORT);
		$catalogue = Catalogue::columns($roles, $model['split']);
		$role_ids = array_column(Roles::allRoles($roles), 'id');
		$measures = array_keys(Catalogue::measures($roles, $model['split']));

		return $this
			// Data.
			->addField(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
			->addField(new CWidgetFieldStrings('clients', _('Clients'), 500))
			->addField((new CWidgetFieldStrings('client_types', _('Client types'), 8, 32))->setAllowed(Settings::types($cfg)['all']))
			->addField((new CWidgetFieldIntegerBox('trend_days', _('Trend window'), 1, 365))->setDefault(7))
			// Columns.
			->addField((new CWidgetFieldRadioButtonList('columns_from', _('Columns from'), [
				self::FROM_DEFAULTS => _('Report defaults'),
				self::FROM_WIDGET => _('This widget')
			]))->setDefault(self::FROM_DEFAULTS))
			->addField(new CWidgetFieldColumns('columns', _('Columns'), Catalogue::forScope($catalogue, 'client'), $role_ids, 'client'))
			->addField(new CWidgetFieldColumns('server_columns', _('Server rows'), Catalogue::forScope($catalogue, 'row'), $role_ids, 'row'))
			->addField((new CWidgetFieldSelect('bars', _('Bar style'), Bars::optionsWithInherit()))->setDefault(self::BARS_DEFAULT))
			->addField(new CWidgetFieldOptionalNumber('usage_warn', _('Warn at, %'), 1, 100))
			->addField(new CWidgetFieldOptionalNumber('usage_high', _('High at, %'), 1, 100))
			// View.
			->addField((new CWidgetFieldRadioButtonList('view', _('View'), [
				self::VIEW_TABLE => _('Table'),
				self::VIEW_PIE => _('Pie chart')
			]))->setDefault(self::VIEW_TABLE))
			->addField((new CWidgetFieldCheckBox('view_switch', null, _('Viewers may switch on the widget')))->setDefault(0))
			->addField((new CWidgetFieldStrings('pie_order', null, 400, 80))->setAllowed($measures))
			->addField((new CWidgetFieldStrings('pie_donuts', _('Donuts'), 400, 80))->setAllowed($measures)->setDefault(Catalogue::DEFAULT_DONUTS))
			->addField((new CWidgetFieldSelect('pie_centre', _('Centre shows'), [
				self::CENTRE_PCT_ALLOCATED => _('Used of allocated, %'),
				self::CENTRE_PCT_REQUESTED => _('Used of requested, %'),
				self::CENTRE_USED => _('Used value'),
				self::CENTRE_FREE => _('Free value')
			]))->setDefault(self::CENTRE_PCT_ALLOCATED))
			->addField((new CWidgetFieldStrings('pie_legend', _('Legend rows'), 6, 16))->setAllowed(self::LEGEND)->setDefault(self::LEGEND))
			->addField((new CWidgetFieldRadioButtonList('pie_short', _('Short shown as'), [
				self::SHORT_HATCHED => _('Hatched'),
				self::SHORT_SOLID => _('Solid'),
				self::SHORT_HIDDEN => _('Hidden')
			]))->setDefault(self::SHORT_HATCHED))
			->addField((new CWidgetFieldSelect('pie_per_row', _('Donuts per row'), [0 => _('Auto'), 2 => '2', 3 => '3', 4 => '4', 6 => '6']))->setDefault(0))
			->addField((new CWidgetFieldSelect('pie_size', _('Donut size'), [
				self::SIZE_MEDIUM => _('Medium'),
				self::SIZE_SMALL => _('Small'),
				self::SIZE_LARGE => _('Large')
			]))->setDefault(self::SIZE_MEDIUM))
			->addField((new CWidgetFieldCheckBox('pie_chips', null, _('Status chips')))->setDefault(1))
			->addField((new CWidgetFieldRadioButtonList('server_rows', _('Server rows'), [
				self::ROWS_CLICK => _('Expand on click'),
				self::ROWS_ALL => _('All expanded'),
				self::ROWS_NONE => _('None')
			]))->setDefault(self::ROWS_CLICK))
			->addField((new CWidgetFieldRadioButtonList('density', _('Density'), [
				self::DENSITY_COMFORTABLE => _('Comfortable'),
				self::DENSITY_COMPACT => _('Compact')
			]))->setDefault(self::DENSITY_COMPACT))
			->addField(new CWidgetFieldOptionalNumber('row_height', _('Row height'), 16, 80))
			->addField((new CWidgetFieldCheckBox('group_headers', null, _('Group headers')))->setDefault(1))
			->addField((new CWidgetFieldCheckBox('sticky_header', null, _('Sticky header')))->setDefault(1))
			->addField((new CWidgetFieldCheckBox('freeze_first', null, _('Freeze first column')))->setDefault(1))
			->addField((new CWidgetFieldCheckBox('striped', null, _('Striped')))->setDefault(0))
			->addField((new CWidgetFieldCheckBox('totals', null, _('Totals row')))->setDefault(0))
			->addField((new CWidgetFieldSelect('totals_fn', _('Totals'), [self::TOTALS_SUM => _('Sum'), self::TOTALS_AVG => _('Average')]))
				->setDefault(self::TOTALS_SUM))
			->addField((new CWidgetFieldTextBox('sort_by', _('Sort')))->setDefault('client'))
			->addField((new CWidgetFieldSelect('sort_dir', _('Sort direction'), [self::SORT_ASC => _('Ascending'), self::SORT_DESC => _('Descending')]))
				->setDefault(self::SORT_ASC))
			->addField(new CWidgetFieldOptionalNumber('top_n', _('Top'), 1, 1000))
			->addField((new CWidgetFieldStrings('toolbar_items', _('Show'), 8, 16))->setAllowed(self::TOOLBAR)->setDefault(self::TOOLBAR_DEFAULT))
			->addField((new CWidgetFieldRadioButtonList('toolbar_pos', _('Position'), [
				self::TOOLBAR_ALWAYS => _('Always shown'),
				self::TOOLBAR_HOVER => _('On hover'),
				self::TOOLBAR_HIDDEN => _('Hidden')
			]))->setDefault(self::TOOLBAR_HOVER))
			->addField((new CWidgetFieldStrings('attention', _('Any of'), 6, 16))->setAllowed(self::ATTENTION)->setDefault(self::ATTENTION_DEFAULT))
			->addField((new CWidgetFieldRadioButtonList('attention_default', _('Default filter'), [
				self::ATTENTION_ALL => _('All clients'),
				self::ATTENTION_ONLY => _('Needs attention only')
			]))->setDefault(self::ATTENTION_ALL));
	}
}
