<?php declare(strict_types = 0);

namespace Modules\EpVolume\Includes;

use Modules\EpVolume\Lib\Settings;
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{CWidgetFieldMultiSelectGroup, CWidgetFieldSelect};

/**
 * Which host groups to read cluster hosts from — empty: every host carrying the client plan
 * template this user can see — and how many places a figure is drawn to.
 *
 * Decimals is the only presentation setting this report owns, and it is deliberately the only
 * override offered here. Where its figures turn yellow and red comes from the plan template's own
 * macros, which the template's TRIGGERS compare against; a dashboard allowed to disagree with
 * them would show a green cell for a problem that had already alarmed.
 */
class WidgetForm extends CWidgetForm {

	/** A select has no empty state, so a value clear of the choices means "follow the site". */
	public const DECIMALS_DEFAULT = Settings::INHERIT_CHOICE;

	public function addFields(): self {
		return $this
			->addField(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
			->addField((new CWidgetFieldSelect('decimals', _('Decimals'), [
				Settings::INHERIT_CHOICE => _('From ElasticPro settings'), 0 => '0', 1 => '1', 2 => '2', 3 => '3'
			]))->setDefault(self::DECIMALS_DEFAULT));
	}
}
