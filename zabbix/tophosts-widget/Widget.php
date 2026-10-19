<?php declare(strict_types = 0);

namespace Modules\EpTophosts;

use Zabbix\Core\CWidget;

class Widget extends CWidget {

	public function getDefaultName(): string {
		// Not "Top hosts": Zabbix has a widget of that name, and the widget type list showed two.
		return _('ElasticPro top hosts');
	}
}
