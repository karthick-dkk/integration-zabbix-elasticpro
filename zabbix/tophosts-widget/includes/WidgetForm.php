<?php declare(strict_types = 0);

namespace Modules\EpTophosts\Includes;

use Modules\EpTophosts\Lib\Settings;
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{CWidgetFieldIntegerBox, CWidgetFieldMultiSelectGroup, CWidgetFieldSelect, CWidgetFieldTextBox};

/**
 * Which servers are ranked — host groups of the client master hosts, one client by name, one
 * client type — by what, how many, and for this dashboard alone where usage turns yellow and red.
 * Left alone, every client is ranked and the thresholds follow ElasticPro -> Settings, as in the
 * other ElasticPro reports.
 */
class WidgetForm extends CWidgetForm {

	/** What the Rank by setting stores, by position: Zabbix wants an integer there. */
	public const RANKS = ['cpu', 'mem'];

	public const USAGE_DEFAULT = Settings::INHERIT;

	/**
	 * The number a client type is stored as. Zabbix stores a select as an integer, and a position
	 * in the site's list would point at another type the day the list is reordered; a number
	 * taken from the name stays with the name.
	 */
	public static function typeKey(string $type): int {
		return (crc32($type) & 0x7fffffff) ?: 1;
	}

	public function addFields(): self {
		$types = Settings::types(Settings::all())['all'];
		return $this
			->addField(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
			->addField(new CWidgetFieldTextBox('client', _('Client')))
			->addField((new CWidgetFieldSelect('client_type', _('Client type'), [0 => _('All')] + array_combine(array_map([self::class, 'typeKey'], $types), $types)))->setDefault(0))
			->addField((new CWidgetFieldSelect('rank', _('Rank by'), [0 => _('CPU'), 1 => _('Memory')]))->setDefault(0))
			->addField((new CWidgetFieldIntegerBox('count', _('Hosts'), 1, 100))->setDefault(10))
			->addField((new CWidgetFieldIntegerBox('usage_warn', _('Warn at, %'), 0, 100))->setDefault(self::USAGE_DEFAULT))
			->addField((new CWidgetFieldIntegerBox('usage_high', _('High at, %'), 0, 100))->setDefault(self::USAGE_DEFAULT));
	}
}
