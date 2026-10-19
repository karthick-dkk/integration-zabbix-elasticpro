<?php declare(strict_types = 0);

namespace Modules\EpVolume\Includes;

use Modules\EpVolume\Lib\{Bars, ColumnReport, ColumnSettings, Roles, Settings, TableOptions};
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{CWidgetFieldIntegerBox, CWidgetFieldMultiSelectGroup, CWidgetFieldRadioButtonList, CWidgetFieldSelect};

/**
 * Volume's settings, on three tabs.
 *
 * Data: which clusters (host groups, clients by name, client types) and the trend window that a
 * column's average, minimum, maximum and change are taken over.
 *
 * Columns: where the columns come from — the report defaults a Super admin sets in the widget's
 * Columns dialog, or this widget's own list — and that list (the plan template's items, and any
 * other item), each column edited in the column editor; where the colours come from — the plan
 * template's macros (which its triggers compare against) or each column's own thresholds; the bar
 * style and how many places a figure is drawn to.
 *
 * View: the table — group headers by plan, totals, sort, search — the toolbar and what "Needs
 * attention" means.
 *
 * A widget saved before 2.1.1 has none of these fields but groupids and decimals, and every default
 * here is how the widget looked then: it follows the report defaults (which a version-1 Columns
 * file is read into unchanged), coloured at the plan template's macros, a table with the section
 * header, the cluster fixed on the left, the toolbar always shown with the Type filter, Columns and
 * the exports.
 */
class WidgetForm extends CWidgetForm {

	/** A select has no empty state, so a value clear of the choices means "follow the site". */
	public const DECIMALS_DEFAULT = Settings::INHERIT_CHOICE;

	public const BARS_DEFAULT = Bars::INHERIT;

	public const FROM_DEFAULTS = 0;
	public const FROM_WIDGET = 1;

	/** Where the colours come from. */
	public const THRESHOLDS_PLAN = 0;
	public const THRESHOLDS_COLUMN = 1;

	public const TOOLBAR = ['search', 'type', 'attention', 'compact', 'columns', 'export'];
	/** The toolbar a widget saved before 2.1.1 had: the Type filter, Columns and the exports. */
	public const TOOLBAR_DEFAULT = ['type', 'columns', 'export'];
	public const ATTENTION = ['warn', 'high'];
	public const ATTENTION_DEFAULT = ['warn', 'high'];

	/** How the table was drawn before 2.1.1, for TableOptions::read and TableFields::add. */
	public const TABLE = ['density' => TableOptions::DENSITY_COMFORTABLE, 'group_headers' => 1, 'sticky_header' => 0, 'freeze_first' => 1,
		'toolbar' => self::TOOLBAR, 'toolbar_default' => self::TOOLBAR_DEFAULT, 'toolbar_pos' => TableOptions::TOOLBAR_ALWAYS,
		'attention' => self::ATTENTION, 'attention_default' => self::ATTENTION_DEFAULT];

	protected function normalizeValues(array $values): array {
		$values = parent::normalizeValues($values);
		$model = ColumnSettings::loadModel(Catalogue::REPORT);

		return self::upgrade($values, Catalogue::columns(Roles::load(), $model['split']), $model);
	}

	/**
	 * The settings as the fields expect them, from whatever came in. Pure, for the tests. A column
	 * the template has gained since is offered in a widget's own list, unticked: a column taken out
	 * of the list must not come back shown.
	 *
	 * @see ColumnReport::upgrade()
	 */
	public static function upgrade(array $values, array $catalogue, array $model): array {
		return ColumnReport::upgrade($values, $catalogue, $model);
	}

	public function addFields(): self {
		$roles = Roles::load();
		$cfg = Settings::all();
		$model = ColumnSettings::loadModel(Catalogue::REPORT);
		$catalogue = Catalogue::columns($roles, $model['split']);

		$this
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
			->addField(new CWidgetFieldColumns('columns', _('Columns'), Catalogue::forScope($catalogue, 'client'), [], 'client'))
			->addField((new CWidgetFieldRadioButtonList('thresholds_from', _('Thresholds'), [
				self::THRESHOLDS_PLAN => _('From the plan template\'s macros'),
				self::THRESHOLDS_COLUMN => _('Per column')
			]))->setDefault(self::THRESHOLDS_PLAN))
			->addField((new CWidgetFieldSelect('bars', _('Bar style'), Bars::optionsWithInherit()))->setDefault(self::BARS_DEFAULT))
			->addField((new CWidgetFieldSelect('decimals', _('Decimals'), [
				Settings::INHERIT_CHOICE => _('From ElasticPro settings'), 0 => '0', 1 => '1', 2 => '2', 3 => '3'
			]))->setDefault(self::DECIMALS_DEFAULT));

		// View.
		TableFields::add($this, self::TABLE + ['all' => _('All clusters')]);

		return $this;
	}
}
