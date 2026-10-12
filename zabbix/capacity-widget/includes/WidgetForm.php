<?php declare(strict_types = 0);

namespace Modules\EpCapacity\Includes;

use Modules\EpCapacity\Lib\{Bars, Settings};
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{CWidgetFieldIntegerBox, CWidgetFieldMultiSelectGroup, CWidgetFieldSelect};

/**
 * Which host groups to read master hosts from, how a figure with a percentage behind it is drawn,
 * and — for this dashboard alone — where usage turns yellow and red. Empty groups: every client
 * master host this user can see.
 *
 * Both overrides are left alone by default and follow ElasticPro -> Settings. Zabbix does not
 * store a field whose value is its default, so a widget nobody has configured carries nothing at
 * all and genuinely follows the site, rather than holding a copy of today's numbers that would
 * then stop following it.
 */
class WidgetForm extends CWidgetForm {

	/** The Display setting stores the position in Bars::STYLES; Zabbix wants an integer here. */
	public const BARS_DEFAULT = Bars::INHERIT;

	/** A threshold box left empty casts to 0, and 0 is not a threshold — so empty means inherit. */
	public const USAGE_DEFAULT = Settings::INHERIT;

	public function addFields(): self {
		return $this
			->addField(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
			->addField((new CWidgetFieldSelect('bars', _('Display'), Bars::optionsWithInherit()))->setDefault(self::BARS_DEFAULT))
			->addField((new CWidgetFieldIntegerBox('usage_warn', _('Warn at, %'), 0, 100))->setDefault(self::USAGE_DEFAULT))
			->addField((new CWidgetFieldIntegerBox('usage_high', _('High at, %'), 0, 100))->setDefault(self::USAGE_DEFAULT));
	}
}
