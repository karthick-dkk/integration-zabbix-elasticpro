<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

/**
 * The names ElasticPro's templates have carried, newest first. An install keeps the templates it
 * was set up with until somebody imports newer ones, so a Zabbix may still run the older
 * generation: the test Zabbix (7.0.31) has "ElasticPro client plan" with espro.plan.epoch,
 * espro.plan.reachable, espro.plan[...] and {$ESPRO.PLAN.LIVE_USED.WARN} — where the current
 * templates say elasticpro.* and {$ELASTICPRO.*}. A report that read only the newest name showed
 * every such cluster as never updated and its thresholds as the fallbacks.
 *
 * So a key or a macro is asked for in every generation, and the newest one present wins. One
 * definition: every reader of a template key or macro that has changed names goes through here.
 *
 * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.
 */
class KeyGenerations {

	/** Item key prefixes, newest first. */
	public const ITEM = ['elasticpro.', 'espro.'];

	/** User macro prefixes, newest first. */
	public const MACRO = ['{$ELASTICPRO.', '{$ESPRO.'];

	/** Every spelling of an item key, newest first: elasticpro.plan.epoch, espro.plan.epoch. A key of no generation is itself. */
	public static function keys(string $key): array {
		return self::spellings($key, self::ITEM);
	}

	/** Every spelling of a user macro, newest first: {$ELASTICPRO.PLAN.STALE}, {$ESPRO.PLAN.STALE}. */
	public static function macros(string $macro): array {
		return self::spellings($macro, self::MACRO);
	}

	/**
	 * What $byName has for the newest spelling present — an item by key, a macro's value by name —
	 * or null when it has none of them.
	 *
	 * @param array $byName  key or macro => anything
	 */
	public static function first(array $byName, string $name) {
		$all = strpos($name, '{$') === 0 ? self::macros($name) : self::keys($name);
		foreach ($all as $n) {
			if (array_key_exists($n, $byName) && $byName[$n] !== null) {
				return $byName[$n];
			}
		}

		return null;
	}

	private static function spellings(string $name, array $prefixes): array {
		foreach ($prefixes as $p) {
			if (strpos($name, $p) === 0) {
				$rest = substr($name, strlen($p));

				return array_map(fn($q) => $q.$rest, $prefixes);
			}
		}

		return [$name];
	}
}
