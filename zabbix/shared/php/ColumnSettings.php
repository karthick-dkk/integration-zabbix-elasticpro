<?php declare(strict_types = 0);

namespace EpShared;

/**
 * A report's column settings: names changed, columns hidden, order moved — one setting for
 * everyone, changed by Super admins.
 *
 * Kept per report in the data folder (columns-<report>.json), in one of two shapes.
 *
 * Version 1, which Client capacity and Volume still use, and every report wrote before 2.1.1:
 *   { "labels": {id: "name"}, "hidden": [id, …], "order": [id, …], "split": [familyId, …] }
 * A column the report has that the settings do not mention keeps its place and name, so a new
 * role appears in the report without anyone touching its settings.
 *
 * Version 2, the report defaults of a widget whose columns are the shared column model
 * (Columns.php — Client resources): every column in full, in order, for the client rows and the
 * server rows, and the families shown as their roles.
 *   { "version": 2, "columns": [column, …], "server_columns": [column, …], "split": [familyId, …],
 *     "new_hidden": 1 }
 * A version-1 file is read into this shape without being rewritten — names, hidden columns, order
 * and split all kept — so nothing is lost until a Super admin saves, and nothing at all if nobody
 * does. "new_hidden" says how a built-in column the list has never seen (a role added in Cluster
 * Management) arrives: unticked in a list saved from the column editor, shown in a list carried
 * over from version 1, which is how version 1 always treated it.
 *
 * Shared: ../../sync-assets.mjs copies this into each module, under that module's namespace.
 */
class ColumnSettings {

	public static function file(string $report): string {
		return 'columns-'.preg_replace('/[^a-z0-9_-]/', '', $report).'.json';
	}

	public static function load(string $report): array {
		$s = Store::read(self::file($report), []);
		if ((int) ($s['version'] ?? 1) >= 2) {
			// A report on the column model; a version-1 reader gets its split and nothing else.
			return ['labels' => [], 'hidden' => [], 'order' => [],
				'split' => array_values(array_filter((array) ($s['split'] ?? []), 'is_string'))];
		}
		return [
			'labels' => is_array($s['labels'] ?? null) ? $s['labels'] : [],
			'hidden' => array_values(array_filter((array) ($s['hidden'] ?? []), 'is_string')),
			'order' => array_values(array_filter((array) ($s['order'] ?? []), 'is_string')),
			'split' => array_values(array_filter((array) ($s['split'] ?? []), 'is_string'))
		];
	}

	/**
	 * Settings as they came from the dialog, cleaned, then saved. With `$known_ids`, ids the
	 * report does not have are dropped; without, any id shaped like a column id is kept.
	 */
	public static function save(string $report, array $in, ?array $known_ids): array {
		$ok = fn($id) => is_string($id) && ($known_ids === null ? preg_match('/^[a-z0-9_.:\[\]\-]{1,120}$/i', $id) === 1 : in_array($id, $known_ids, true));
		$known = [];
		foreach (array_merge(array_keys((array) ($in['labels'] ?? [])), (array) ($in['hidden'] ?? []), (array) ($in['order'] ?? [])) as $id) {
			if ($ok($id)) {
				$known[$id] = true;
			}
		}
		$labels = [];
		foreach ((array) ($in['labels'] ?? []) as $id => $label) {
			$label = trim((string) $label);
			if (isset($known[$id]) && $label !== '') {
				$labels[$id] = mb_substr($label, 0, 80);
			}
		}
		$clean = [
			'labels' => $labels,
			'hidden' => array_values(array_filter((array) ($in['hidden'] ?? []), fn($id) => isset($known[$id]))),
			'order' => array_values(array_unique(array_filter((array) ($in['order'] ?? []), fn($id) => isset($known[$id])))),
			'split' => array_values(array_filter((array) ($in['split'] ?? []), fn($id) => is_string($id) && preg_match('/^[a-z][a-z0-9_]*$/', $id)))
		];
		Store::write(self::file($report), $clean);
		return $clean;
	}

	public static function reset(string $report): void {
		Store::delete(self::file($report));
	}

	/**
	 * The report's columns with the settings applied: in order, renamed, hidden ones gone.
	 * Each column needs an `id` and a `label`; its own label is kept as `default`.
	 */
	public static function apply(array $columns, array $settings, bool $keep_hidden = false): array {
		$byId = [];
		foreach ($columns as $i => $c) {
			$c['default'] = $c['label'];
			if (isset($settings['labels'][$c['id']])) {
				$c['label'] = $settings['labels'][$c['id']];
			}
			$c['hidden'] = in_array($c['id'], $settings['hidden'], true);
			$c['_pos'] = $i;
			$byId[$c['id']] = $c;
		}
		// Saved order first. A column the settings never saw — a role added later — goes right
		// after the column it followed in the report, so it lands beside its own section.
		$seq = [];
		foreach ($settings['order'] as $id) {
			if (isset($byId[$id]) && !in_array($id, $seq, true)) {
				$seq[] = $id;
			}
		}
		$original = array_keys($byId);
		foreach ($original as $i => $id) {
			if (in_array($id, $seq, true)) {
				continue;
			}
			$at = 0;
			for ($j = $i - 1; $j >= 0; $j--) {
				$k = array_search($original[$j], $seq, true);
				if ($k !== false) {
					$at = $k + 1;
					break;
				}
			}
			array_splice($seq, $at, 0, [$id]);
		}
		$ordered = [];
		foreach ($seq as $id) {
			$ordered[$id] = $byId[$id];
		}
		$byId = $ordered;
		$out = [];
		foreach ($byId as $c) {
			unset($c['_pos']);
			if ($keep_hidden || !$c['hidden']) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/* --------------------------- version 2: the column model --------------------------- */

	public const MODEL_VERSION = 2;

	/**
	 * A report's defaults as stored, in the version-2 shape. columns / server_columns null:
	 * never set, so the report's own defaults apply. A version-1 file comes back under 'legacy',
	 * for resolve() to read its names, hidden columns and order onto the defaults.
	 */
	public static function loadModel(string $report): array {
		$s = Store::read(self::file($report), []);
		if (!is_array($s)) {
			$s = [];
		}
		if ((int) ($s['version'] ?? 1) >= self::MODEL_VERSION) {
			return [
				'version' => self::MODEL_VERSION,
				'columns' => is_array($s['columns'] ?? null) ? array_values(array_filter($s['columns'], 'is_array')) : null,
				'server_columns' => is_array($s['server_columns'] ?? null) ? array_values(array_filter($s['server_columns'], 'is_array')) : null,
				'split' => self::cleanSplit($s['split'] ?? []),
				'new_hidden' => (bool) ($s['new_hidden'] ?? true),
				'legacy' => null
			];
		}
		$legacy = $s ? self::load($report) : null;
		return ['version' => self::MODEL_VERSION, 'columns' => null, 'server_columns' => null, 'split' => $legacy['split'] ?? [],
			'new_hidden' => false, 'legacy' => $legacy];
	}

	/**
	 * Saves a report's defaults in the version-2 shape. The columns are expected clean
	 * (Columns::cleanList, strict): this writes what it is given.
	 */
	public static function saveModel(string $report, array $columns, ?array $serverColumns, array $split): array {
		$clean = ['version' => self::MODEL_VERSION, 'columns' => array_values($columns),
			'server_columns' => $serverColumns === null ? null : array_values($serverColumns),
			'split' => self::cleanSplit($split), 'new_hidden' => 1];
		Store::write(self::file($report), $clean);
		return $clean;
	}

	private static function cleanSplit($split): array {
		return array_values(array_unique(array_filter((array) $split, fn($id) => is_string($id) && preg_match('/^[a-z][a-z0-9_]*$/', $id))));
	}

	/** The id a built-in column has in a list nobody has saved: the same every time, so a sort or a dialog can name it. */
	public static function builtinUid(string $id): string {
		return 'b'.substr(md5($id), 0, 9);
	}

	/** A built-in column as a column of the model: its catalogue name, group and look. */
	public static function builtinColumn(string $id, array $entry): array {
		return array_replace(Columns::DEFAULT, (array) ($entry['defaults'] ?? []), [
			'uid' => self::builtinUid($id), 'source' => Columns::SOURCE_BUILTIN, 'builtin' => $id,
			'group' => (string) ($entry['group'] ?? ''), 'name' => (string) ($entry['label'] ?? $id),
			'units' => (string) ($entry['units'] ?? '')
		]);
	}

	/**
	 * The columns in force, from a list (or none) and the catalogue.
	 *
	 * No list: the catalogue's own columns (those marked auto), in its order — with a version-1
	 * file's names, hidden columns and order applied exactly as version 1 applied them.
	 *
	 * A list: as it is, and every auto column it does not mention added after the column it
	 * follows in the catalogue — unticked when $newHidden, shown otherwise — so a role added in
	 * Cluster Management is offered without anyone touching the widget.
	 *
	 * @param array      $catalogue  id => entry (label, group, units, auto, defaults …), in report order
	 * @param array|null $legacy     a version-1 file, as load() reads it
	 */
	public static function resolve(?array $list, array $catalogue, ?array $legacy = null, bool $newHidden = true): array {
		$auto = array_filter($catalogue, fn($e) => !empty($e['auto']));
		if ($list === null) {
			if ($legacy !== null) {
				$all = [];
				foreach ($auto as $id => $e) {
					$all[] = ['id' => $id, 'label' => (string) ($e['label'] ?? $id)];
				}
				$out = [];
				foreach (self::apply($all, $legacy, true) as $c) {
					$col = self::builtinColumn($c['id'], $auto[$c['id']]);
					$col['name'] = $c['label'];
					$col['hidden'] = $c['hidden'] ? 1 : 0;
					$out[] = $col;
				}
				return $out;
			}
			$out = [];
			foreach ($auto as $id => $e) {
				$out[] = self::builtinColumn($id, $e);
			}
			return $out;
		}
		// A built-in column is in the list when the list has it as it is — now, not its average or
		// maximum over a window, which are columns of their own.
		$plain = fn(array $c) => (int) ($c['source'] ?? 0) === Columns::SOURCE_BUILTIN && ($c['builtin'] ?? '') !== ''
			&& (int) ($c['value'] ?? Columns::VALUE_NOW) === Columns::VALUE_NOW;
		$present = [];
		foreach ($list as $c) {
			if ($plain($c)) {
				$present[$c['builtin']] = true;
			}
		}
		$ids = array_keys($catalogue);
		$out = array_values($list);
		foreach ($ids as $i => $id) {
			if (!isset($auto[$id]) || isset($present[$id])) {
				continue;
			}
			// After the nearest column before it in the catalogue that the list has.
			$at = 0;
			for ($j = $i - 1; $j >= 0; $j--) {
				$found = null;
				foreach ($out as $k => $c) {
					if (($c['builtin'] ?? '') === $ids[$j] && $plain($c)) {
						$found = $k;
					}
				}
				if ($found !== null) {
					$at = $found + 1;
					break;
				}
			}
			$col = self::builtinColumn($id, $auto[$id]);
			$col['hidden'] = $newHidden ? 1 : 0;
			array_splice($out, $at, 0, [$col]);
			$present[$id] = true;
		}
		return $out;
	}

	/**
	 * What the Columns dialog saved (names, shown or hidden, order, split — keyed by column uid),
	 * put onto a list of columns. Columns the dialog did not mention keep their place after the
	 * ones it did; a name left empty or equal to the column's catalogue name goes back to that.
	 */
	public static function applyDialog(array $columns, array $in, array $catalogue): array {
		$labels = (array) ($in['labels'] ?? []);
		$hidden = array_flip(array_filter((array) ($in['hidden'] ?? []), 'is_string'));
		$order = array_values(array_filter((array) ($in['order'] ?? []), 'is_string'));
		$byUid = [];
		foreach ($columns as $c) {
			$byUid[$c['uid']] = $c;
		}
		$seq = [];
		foreach ($order as $uid) {
			if (isset($byUid[$uid]) && !in_array($uid, $seq, true)) {
				$seq[] = $uid;
			}
		}
		foreach (array_keys($byUid) as $uid) {
			if (!in_array($uid, $seq, true)) {
				$seq[] = $uid;
			}
		}
		$out = [];
		foreach ($seq as $uid) {
			$c = $byUid[$uid];
			$default = (int) $c['source'] === Columns::SOURCE_BUILTIN ? (string) ($catalogue[$c['builtin']]['label'] ?? $c['name']) : $c['name'];
			if (array_key_exists($uid, $labels) && is_scalar($labels[$uid])) {
				$name = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]/', '', (string) $labels[$uid])), 0, Columns::MAX_NAME);
				$c['name'] = $name !== '' ? $name : $default;
			}
			elseif (array_key_exists('order', $in)) {
				// The dialog sends a name only when it differs from the column's own.
				$c['name'] = $default;
			}
			$c['hidden'] = isset($hidden[$uid]) ? 1 : 0;
			$out[] = $c;
		}
		return $out;
	}

	/**
	 * A list after the report's families were shown as their roles (or back as one): the built-in
	 * columns that are no longer the report's own go, and the ones that now are arrive shown, where
	 * the catalogue puts them — as version 1 always swapped them.
	 */
	public static function resplit(array $list, array $before, array $after): array {
		$auto = fn(array $cat) => array_keys(array_filter($cat, fn($e) => !empty($e['auto'])));
		$gone = array_diff($auto($before), $auto($after));
		// Only the columns as the report draws them go; an average or maximum someone added stays.
		$list = array_values(array_filter($list, fn($c) => !((int) ($c['source'] ?? 0) === Columns::SOURCE_BUILTIN
			&& in_array($c['builtin'] ?? '', $gone, true) && (int) ($c['value'] ?? Columns::VALUE_NOW) === Columns::VALUE_NOW)));
		return self::resolve($list, $after, null, false);
	}
}
