<?php declare(strict_types = 0);

namespace Modules\EpCapacity\Includes;

use Modules\EpCapacity\Lib\TableOptions;
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{CWidgetFieldCheckBox, CWidgetFieldRadioButtonList, CWidgetFieldSelect, CWidgetFieldTextBox};

/**
 * The fields of the View tab's table, the same for every widget on the column model: density and
 * row height, group headers, sticky header, frozen first column, striped rows, a totals row, the
 * default sort and top N, the toolbar's items and position, and what Needs attention means.
 * TableOptions reads them back.
 *
 * @see TableOptions
 *
 * Copied from ../../shared/php/includes by ../../sync-assets.mjs — edit it there.
 */
class TableFields {

	/**
	 * @param array $o  toolbar (the items this widget offers), toolbar_default, attention (the criteria it offers),
	 *                  attention_default, and optionally all (the label of the unfiltered choice), density (default), group_headers, sticky_header, freeze_first, sort_by, toolbar_pos
	 */
	public static function add(CWidgetForm $form, array $o): void {
		$flag = fn(string $name, string $label, int $default) => (new CWidgetFieldCheckBox($name, null, $label))->setDefault($default);

		$form
			->addField((new CWidgetFieldRadioButtonList('density', _('Density'), [
				TableOptions::DENSITY_COMFORTABLE => _('Comfortable'),
				TableOptions::DENSITY_COMPACT => _('Compact')
			]))->setDefault($o['density'] ?? TableOptions::DENSITY_COMPACT))
			->addField(new CWidgetFieldOptionalNumber('row_height', _('Row height'), 16, 80))
			->addField($flag('group_headers', _('Group headers'), $o['group_headers'] ?? 1))
			->addField($flag('sticky_header', _('Sticky header'), $o['sticky_header'] ?? 1))
			->addField($flag('freeze_first', _('Freeze first column'), $o['freeze_first'] ?? 1))
			->addField($flag('striped', _('Striped'), 0))
			->addField($flag('totals', _('Totals row'), 0))
			->addField((new CWidgetFieldSelect('totals_fn', _('Totals'), [TableOptions::TOTALS_SUM => _('Sum'), TableOptions::TOTALS_AVG => _('Average')]))
				->setDefault(TableOptions::TOTALS_SUM))
			->addField((new CWidgetFieldTextBox('sort_by', _('Sort')))->setDefault($o['sort_by'] ?? 'client'))
			->addField((new CWidgetFieldSelect('sort_dir', _('Sort direction'), [TableOptions::SORT_ASC => _('Ascending'), TableOptions::SORT_DESC => _('Descending')]))
				->setDefault(TableOptions::SORT_ASC))
			->addField(new CWidgetFieldOptionalNumber('top_n', _('Top'), 1, 1000))
			->addField((new CWidgetFieldStrings('toolbar_items', _('Show'), 8, 16))->setAllowed($o['toolbar'])->setDefault($o['toolbar_default']))
			->addField((new CWidgetFieldRadioButtonList('toolbar_pos', _('Position'), [
				TableOptions::TOOLBAR_ALWAYS => _('Always shown'),
				TableOptions::TOOLBAR_HOVER => _('On hover'),
				TableOptions::TOOLBAR_HIDDEN => _('Hidden')
			]))->setDefault($o['toolbar_pos'] ?? TableOptions::TOOLBAR_HOVER))
			->addField((new CWidgetFieldStrings('attention', _('Any of'), 6, 16))->setAllowed($o['attention'])->setDefault($o['attention_default']))
			->addField((new CWidgetFieldRadioButtonList('attention_default', _('Default filter'), [
				TableOptions::ATTENTION_ALL => $o['all'] ?? _('All clients'),
				TableOptions::ATTENTION_ONLY => _('Needs attention only')
			]))->setDefault(TableOptions::ATTENTION_ALL));
	}
}
