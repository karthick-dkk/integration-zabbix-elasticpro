<?php declare(strict_types = 0);

namespace Modules\EpVolume\Includes;

use API;
use Modules\EpVolume\Lib\{Columns, KeyGenerations, Roles};

/**
 * What Volume offers as built-in columns: the "ElasticPro client plan" template's items — the ones
 * tagged `column` — with their label (the item's name without the template's own prefix), their
 * section (tag `plan`), their place (tag `column`) and their units, which ElasticPro generates from
 * the list the Volume report page draws. So this keeps no column list of its own: a column added to
 * the report reaches the widget with the template.
 *
 * The first column is the cluster. The ids are the ones the report has always used (the item key,
 * which is what a version-1 Columns file is keyed by).
 *
 * Every figure is an item of the cluster host itself, so every column is the host's own ("master")
 * figure; a column the user adds from any other item reads the same host. The thresholds the
 * template's triggers alarm on — Live used above {$ELASTICPRO.PLAN.LIVE_USED.WARN}, Live days under
 * {$ELASTICPRO.PLAN.LIVE_DAYS.MIN} — are the default of two columns, and their macros are read here.
 *
 * Read once per request (plan()): the form, the Columns dialog and the view all ask.
 */
class Catalogue {

	public const REPORT = 'volume';

	/**
	 * The prefix the plan template's ITEM names carry ("Client plan: Live used %"), written by
	 * ElasticPro's own deploy. A literal on purpose, and not derived from the template's name:
	 * renaming or re-mapping the template does not rename its items, so tying the two together
	 * would break the label stripping on exactly the sites the mapping exists for. An item whose
	 * name does not carry it keeps its full name, so the worst a different spelling costs is a
	 * longer column heading.
	 */
	public const LABEL_PREFIX = 'Client plan: ';

	/** The newest spelling; an older template's {$ESPRO.PLAN.…} is read too (KeyGenerations). */
	public const MACRO_WARN = '{$ELASTICPRO.PLAN.LIVE_USED.WARN}';
	public const MACRO_DAYS = '{$ELASTICPRO.PLAN.LIVE_DAYS.MIN}';
	/** What the template's triggers fall back to when neither the host nor the template says. */
	public const FALLBACK_WARN = 85;
	public const FALLBACK_DAYS = 14;

	public const KEY_USED = '[live.used.pct]';
	public const KEY_DAYS = '[live.days]';

	/** The newest spelling; an older template's espro.plan.epoch / .reachable is read too (KeyGenerations). */
	public const KEY_EPOCH = 'elasticpro.plan.epoch';
	public const KEY_REACHABLE = 'elasticpro.plan.reachable';

	/** Every key the report reads beside its columns: the epoch and the reachable flag, in every generation. */
	public static function freshnessKeys(): array {
		return array_merge(KeyGenerations::keys(self::KEY_EPOCH), KeyGenerations::keys(self::KEY_REACHABLE));
	}

	/** How the report's own columns look until somebody changes them: no link, an unknown is "—". */
	private const PLAIN = ['click' => Columns::CLICK_NONE, 'nodata' => Columns::NODATA_DASH];

	private static ?array $plan = null;

	public static function forget(): void {
		self::$plan = null;
	}

	/** A row is one host, so there are no servers to pick roles of. */
	public static function single(): bool {
		return true;
	}

	/** A column added to the template arrives shown, as it always did. */
	public static function newHidden(): bool {
		return false;
	}

	/**
	 * The plan template(s), their macros and their columns, in order.
	 *
	 * @return array{error: ?string, templateids: array, defaults: array, columns: array}
	 */
	public static function plan(): array {
		if (self::$plan !== null) {
			return self::$plan;
		}
		$names = Roles::templateNamesFor('plan');
		$templates = API::Template()->get([
			'output' => ['templateid', 'host'],
			'filter' => ['host' => $names],
			'selectMacros' => ['macro', 'value']
		]);
		if (!$templates) {
			return self::$plan = ['error' => _s('Template "%1$s" is not imported, or you cannot read it.', $names[0]), 'templateids' => [], 'defaults' => [], 'columns' => []];
		}
		// Every template the mapping answers to, not whichever one the database happened to return
		// first. A site that has mapped the plan slot and still has the shipped template installed has
		// two of them, and picking [0] made the columns, the thresholds and the list of clusters depend
		// on database order — with the clusters linked to the other one vanishing from the report.
		//
		// Put back into the order the names were asked for, which is the mapping's order: the name a
		// site chose first, then the spellings it merely still answers to.
		usort($templates, function ($a, $b) use ($names) {
			$at = array_search($a['host'], $names, true);
			$bt = array_search($b['host'], $names, true);

			return ($at === false ? PHP_INT_MAX : $at) <=> ($bt === false ? PHP_INT_MAX : $bt);
		});
		// The first template that defines a macro owns it, so the mapped template's own thresholds beat
		// a shipped template left behind.
		$defaults = [];
		foreach ($templates as $t) {
			$defaults += array_column($t['macros'] ?? [], 'value', 'macro');
		}
		$columns = [];
		foreach (API::Item()->get([
			'output' => ['key_', 'name', 'units', 'value_type'],
			'templateids' => array_column($templates, 'templateid'),
			'selectTags' => ['tag', 'value']
		]) ?: [] as $item) {
			$tags = array_column($item['tags'], 'value', 'tag');
			if (!array_key_exists('column', $tags)) {
				continue;
			}
			$columns[] = [
				'key' => $item['key_'],
				'label' => strpos($item['name'], self::LABEL_PREFIX) === 0 ? substr($item['name'], strlen(self::LABEL_PREFIX)) : $item['name'],
				'group' => $tags['plan'] ?? '',
				'order' => (int) $tags['column'],
				'units' => $item['units'],
				'numeric' => in_array($item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64])
			];
		}
		usort($columns, static fn($a, $b) => $a['order'] <=> $b['order']);

		return self::$plan = ['error' => null, 'templateids' => array_column($templates, 'templateid'), 'defaults' => $defaults, 'columns' => $columns];
	}

	/** Whether this column's key is the one the template alarms on for use of live storage, or for days left. */
	public static function kind(string $key): string {
		foreach ([self::KEY_USED => 'used', self::KEY_DAYS => 'days'] as $suffix => $kind) {
			if (substr($key, -strlen($suffix)) === $suffix) {
				return $kind;
			}
		}

		return '';
	}

	/**
	 * The report's columns: the cluster, then the template's. The signature is the one every widget's
	 * Catalogue has; the roles and the families shown as roles are Client resources' and play no part
	 * here.
	 */
	public static function columns(array $roles = [], array $split = []): array {
		$plan = self::plan();
		$out = ['client.name' => ['label' => _('Cluster'), 'group' => '', 'on' => Columns::ON_CLIENT, 'field' => 'name', 'text' => true, 'units' => '',
			'auto' => true, 'defaults' => self::PLAIN]];
		$warn = self::macro($plan['defaults'], self::MACRO_WARN, self::FALLBACK_WARN);
		$days = self::macro($plan['defaults'], self::MACRO_DAYS, self::FALLBACK_DAYS);
		foreach ($plan['columns'] as $c) {
			$units = (string) $c['units'];
			$e = ['label' => $c['label'], 'group' => (string) $c['group'], 'on' => Columns::ON_MASTER, 'key' => $c['key'], 'units' => $units, 'auto' => true,
				'fix' => _('ElasticPro sends the client plan for the cluster; a figure it could not measure is not sent.'), 'defaults' => self::PLAIN];
			if (!$c['numeric']) {
				$e['text'] = true;
			}
			switch (self::kind($c['key'])) {
				// What the template's triggers alarm on: the cell is coloured; the plan's macros decide, or the column's own thresholds.
				case 'used':
					$e['defaults'] += ['show' => Columns::SHOW_CELL, 'use_settings' => 0, 'worse' => Columns::WORSE_HIGHER,
						'thresholds' => [['level' => Columns::LEVEL_WARN, 'color' => '', 'value' => Columns::num($warn)]]];
					break;
				case 'days':
					$e['defaults'] += ['show' => Columns::SHOW_CELL, 'use_settings' => 0, 'worse' => Columns::WORSE_LOWER,
						'thresholds' => [['level' => Columns::LEVEL_HIGH, 'color' => '', 'value' => Columns::num($days)]]];
					break;
			}
			$out[$c['key']] = $e;
		}

		return $out;
	}

	/** The catalogue a column of this scope may read: Volume has no server rows. */
	public static function forScope(array $catalogue, string $scope): array {
		return $scope === 'row' ? [] : $catalogue;
	}

	/** The cluster hosts the column editor's key suggestions and Check look at: hostid => [] (no roles). */
	/** "Check" looks at cluster hosts, a row a cluster. */
	public static function hostWords(): array {
		return ['hosts' => _('cluster hosts'), 'rows' => _('Clusters')];
	}

	public static function editorHosts(array $roles, array $cfg): array {
		$plan = self::plan();
		if (!$plan['templateids']) {
			return [];
		}

		return array_map(fn() => [], API::Host()->get(['output' => ['hostid'], 'templateids' => $plan['templateids'], 'preservekeys' => true]) ?: []);
	}

	/** A plan macro's number: the host's own, else the template's, else what the triggers fall back to. */
	public static function macro(array $source, string $name, float $fallback): float {
		// The newest spelling the source has: {$ELASTICPRO.…}, else an older template's {$ESPRO.…}.
		$value = KeyGenerations::first($source, $name);

		return is_numeric($value) ? (float) $value : $fallback;
	}
}
