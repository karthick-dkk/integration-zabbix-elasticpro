<?php declare(strict_types = 0);

namespace Modules\EpOverview\Includes;

use Modules\EpOverview\Lib\{Roles, Settings};
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{CWidgetFieldCheckBox, CWidgetFieldIntegerBox, CWidgetFieldMultiSelectGroup, CWidgetFieldSelect, CWidgetFieldTextBox};

/**
 * Which clients the overview shows (host groups, one client by name, one client type), which of
 * its panels, the family its cards and gauges show CPU and memory for, and — for this dashboard
 * alone — where usage turns yellow and red. Left alone: every client, every panel, ES, and the
 * thresholds of ElasticPro -> Settings.
 */
class WidgetForm extends CWidgetForm {

	public const USAGE_DEFAULT = Settings::INHERIT;

	/**
	 * The number a name (a client type, a family id) is stored as. Zabbix stores a select as an
	 * integer, and a position in a list would point at another entry the day the list changes; a
	 * number taken from the name stays with the name.
	 */
	public static function key(string $name): int {
		return (crc32($name) & 0x7fffffff) ?: 1;
	}

	public function addFields(): self {
		$types = Settings::types(Settings::all())['all'];
		$families = Roles::load()['families'];
		return $this
			->addField(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
			->addField(new CWidgetFieldTextBox('client', _('Client')))
			->addField((new CWidgetFieldSelect('client_type', _('Client type'), [0 => _('All')] + array_combine(array_map([self::class, 'key'], $types), $types)))->setDefault(0))
			->addField((new CWidgetFieldCheckBox('show_summary', _('Summary')))->setDefault(1))
			->addField((new CWidgetFieldCheckBox('show_used', _('Used of allocated')))->setDefault(1))
			->addField((new CWidgetFieldCheckBox('show_roles', _('Servers by role')))->setDefault(1))
			->addField((new CWidgetFieldSelect('family', _('Cards show'), array_combine(
				array_map(fn($f) => self::key($f['id']), $families), array_column($families, 'label'))))->setDefault(self::key('es')))
			->addField((new CWidgetFieldIntegerBox('usage_warn', _('Warn at, %'), 0, 100))->setDefault(self::USAGE_DEFAULT))
			->addField((new CWidgetFieldIntegerBox('usage_high', _('High at, %'), 0, 100))->setDefault(self::USAGE_DEFAULT));
	}
}
