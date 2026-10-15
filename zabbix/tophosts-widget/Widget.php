<?php declare(strict_types = 0);

namespace Modules\EpTophosts;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

	public function getDefaultName(): string {
		return _('Top hosts');
	}
}
