<?php declare(strict_types = 0);

namespace Modules\EpCapacity\Includes;

use Modules\EpCapacity\Lib\{Bars, ColumnReport, ColumnSettings, Roles, Settings};
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{CWidgetFieldIntegerBox, CWidgetFieldMultiSelectGroup, CWidgetFieldRadioButtonList, CWidgetFieldSelect};

/**
 * Client capacity's settings, on three tabs.
 *
 * Data: which clients (host groups, clients by name, client types) and the trend window that a
 * column's average, minimum, maximum and change are taken over.
 *
 * Columns: where the columns come from — the report defaults a Super admin sets in the widget's
 * Columns dialog, or this widget's own list — and that list, each column edited in the column editor;
 * the bar style and the Warn / High a column left alone follows (empty: ElasticPro -> Settings).
 *
 * View: the table — density and row height, group headers, sticky header, frozen first column,
 * striped rows, a totals row, the default sort and top N — the toolbar's items and where it shows,
 * and what "Needs attention" means.
 *
 * A widget saved before 2.1.1 has none of these fields but groupids, bars and the two thresholds,
 * and every default here is how the widget looked then: it follows the report defaults (which a
 * version-1 Columns file is read into unchanged), a compact table, the toolbar on hover with
 * search, type, Columns and export. A Warn or High stored as 0 — Zabbix's integer box saving an
 * empty box — is read as empty: ElasticPro -> Settings, which is what it always meant. What it kept
 * per browser (colours, row height) is the widget's own setting now.
 */
class WidgetForm extends CWidgetForm {

	/** The Display setting stores the position in Bars::STYLES; Zabbix wants an integer here. */
	public const BARS_DEFAULT = Bars::INHERIT;

	public const FROM_DEFAULTS = 0;
	public const FROM_WIDGET = 1;

	public const TOOLBAR = ['search', 'type', 'attention', 'compact', 'columns', 'export'];
	/** The toolbar a widget saved before 2.1.1 had. */
	public const TOOLBAR_DEFAULT = ['search', 'type', 'columns', 'export'];
	public const ATTENTION = ['warn', 'high', 'short', 'disk30'];
	public const ATTENTION_DEFAULT = ['warn', 'high', 'short'];

	protected function normalizeValues(array $values): array {
		$values = parent::normalizeValues($values);
		$model = ColumnSettings::loadModel(Catalogue::REPORT);

		return self::upgrade($values, Catalogue::columns(Roles::load(), $model['split']), $model);
	}

	/**
	 * The settings as the fields expect them, from whatever came in. Pure, for the tests.
	 *
	 * @see ColumnReport::upgrade()
	 */
	public static function upgrade(array $values, array $catalogue, array $model): array {
		return ColumnReport::upgrade($values, $catalogue, $model);
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
			->addField(new CWidgetFieldColumns('columns', _('Columns'), Catalogue::forScope($catalogue, 'client'), $role_ids, 'client'))
			->addField((new CWidgetFieldSelect('bars', _('Bar style'), Bars::optionsWithInherit()))->setDefault(self::BARS_DEFAULT))
			->addField(new CWidgetFieldOptionalNumber('usage_warn', _('Warn at, %'), 1, 100))
			->addField(new CWidgetFieldOptionalNumber('usage_high', _('High at, %'), 1, 100));

		// View.
		TableFields::add($this, ['toolbar' => self::TOOLBAR, 'toolbar_default' => self::TOOLBAR_DEFAULT, 'attention' => self::ATTENTION,
			'attention_default' => self::ATTENTION_DEFAULT]);

		return $this;
	}
}
