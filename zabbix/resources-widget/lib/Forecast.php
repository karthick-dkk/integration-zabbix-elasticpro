<?php declare(strict_types = 0);

namespace Modules\EpResources\Lib;

/**
 * When a client's ES storage fills, from the master host's `ep.es.storage.full_in` (Zabbix
 * timeleft() over 7 days: seconds, or -1 when usage is flat or falling). One reading of it for
 * Cluster Management, Client capacity and its export.
 *
 * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.
 */
class Forecast {

	public const KEY = 'ep.es.storage.full_in';

	/**
	 * The bands and the wording, as ElasticPro ships them. A site changes them on
	 * ElasticPro -> Settings, which hands the saved set to fullIn() below.
	 *
	 * The numbers live here rather than being read from Settings because this class is loaded on
	 * its own in places Settings is not — a static call into it would be fatal there — and
	 * because a default kept beside the code it governs cannot drift from it.
	 */
	public const WINDOWS = ['bad_days' => 7, 'warn_days' => 30, 'flat_days' => 3650,
		'day_text_days' => 1, 'month_text_days' => 60, 'month_days' => 30];

	/**
	 * null when not measured yet. Otherwise text ("23 days", "not growing"), level (bad, warn,
	 * flat when it never fills, else ok) and days (null when flat).
	 *
	 * @param array $windows  what the site made of WINDOWS; anything left out keeps its shipped value
	 */
	public static function fullIn(?float $seconds, array $windows = []): ?array {
		if ($seconds === null) {
			return null;
		}
		$w = $windows + self::WINDOWS;
		$d = $seconds / 86400;
		if ($d < 0 || $d > $w['flat_days']) {
			return ['text' => _('not growing'), 'level' => 'flat', 'days' => null];
		}
		$text = $d < $w['day_text_days'] ? _('under a day')
			: ($d < $w['month_text_days'] ? _n('%1$s day', '%1$s days', (int) floor($d))
				: _n('%1$s month', '%1$s months', (int) floor($d / $w['month_days'])));
		return ['text' => $text, 'level' => $d < $w['bad_days'] ? 'bad' : ($d < $w['warn_days'] ? 'warn' : 'ok'), 'days' => round($d, 1)];
	}
}
