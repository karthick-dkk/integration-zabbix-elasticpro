<?php declare(strict_types = 0);

namespace Modules\EpCapacity\Includes;

use Modules\EpCapacity\Lib\Columns;

/**
 * What Client capacity offers as built-in columns: the master template's items columns.json lists,
 * in its order — the client (name, ES URL) and then, per family, requested · allocated · usage —
 * each as the column model's built-in column. Those are the report's own columns ("auto"): the ones
 * a list nobody has saved shows, and the ones Client capacity has always drawn.
 *
 * The ids are the ones the report has always used (the item key, or client.name / client.url), which
 * is what lets a version-1 Columns file — names, hidden columns and order keyed by them — be read
 * onto the column model unchanged.
 *
 * columns.json says how each is drawn: kind requested (a request; 0 is "not set"), allocated (with
 * the request it may fall short of — vs), usage (a usage percentage, the kind ElasticPro -> Settings'
 * thresholds are for), used (with the usage item its share comes from — usageKey), forecast (the
 * time until storage is full), delay. A widget on the shared column model has a class of this name:
 * the shared column editor (actions/ColumnEdit.php) and Columns dialog (actions/ColumnsSave.php)
 * find it by it.
 */
class Catalogue {

	public const REPORT = 'capacity';

	/** How the report's own columns look, as they always have: no link, an unknown is "—". */
	private const PLAIN = ['click' => Columns::CLICK_NONE, 'nodata' => Columns::NODATA_DASH];

	/** A figure with a share behind it: drawn as the widget's bar (Display), coloured at Settings' thresholds. */
	private const BAR = ['click' => Columns::CLICK_NONE, 'nodata' => Columns::NODATA_DASH, 'show' => Columns::SHOW_BAR, 'use_settings' => 1];

	/** The id a column of columns.json has in a Columns file. */
	public static function idOf(array $c): string {
		return $c['key'] ?? ('client.'.($c['source'] === 'host' ? 'name' : 'url'));
	}

	/** columns.json, as written. */
	public static function spec(): array {
		$spec = json_decode((string) file_get_contents(__DIR__.'/../columns.json'), true);

		return is_array($spec['columns'] ?? null) ? $spec['columns'] : [];
	}

	/**
	 * The report's columns, in its order. The signature is the one every widget's Catalogue has; the
	 * roles and the families shown as roles are Client resources' and play no part here.
	 */
	public static function columns(array $roles = [], array $split = []): array {
		$master = _('Write the master template (ElasticPro → Clients → Write master template) and check the client\'s cluster host is monitored.');
		$out = [];
		foreach (self::spec() as $c) {
			$id = self::idOf($c);
			$source = $c['source'] ?? '';
			if ($source === 'host') {
				$out[$id] = ['label' => $c['label'], 'group' => '', 'on' => Columns::ON_CLIENT, 'field' => 'shown', 'export_field' => 'name', 'text' => true,
					'units' => '', 'auto' => true, 'defaults' => self::PLAIN];
				continue;
			}
			if ($source === 'macro') {
				$out[$id] = ['label' => $c['label'], 'group' => '', 'on' => Columns::ON_CLIENT, 'macro' => $c['macro'], 'text' => true, 'units' => '',
					'auto' => true, 'defaults' => self::PLAIN];
				continue;
			}
			$kind = $c['kind'] ?? '';
			$e = ['label' => $c['label'], 'group' => '', 'on' => Columns::ON_MASTER, 'key' => $c['key'], 'units' => $kind === 'forecast' ? 's' : (string) ($c['units'] ?? ''),
				'auto' => true, 'fix' => $master, 'defaults' => self::PLAIN];
			switch ($kind) {
				case 'requested':
					$e['requested'] = true;
					$e['fix'] = _('Set what the client requested in Cluster Management.');
					break;
				case 'allocated':
					$e['vs'] = $c['vs'];
					$e['vs_label'] = strpos($c['vs'], 'purchased') !== false ? _('purchased') : _('requested');
					break;
				case 'usage':
					$e['settings'] = true;
					$e['defaults'] = self::BAR;
					break;
				case 'used':
					if (isset($c['usageKey'])) {
						$e['pct'] = $c['usageKey'];
						$e['defaults'] = self::BAR;
					}
					break;
				case 'forecast':
					$e['forecast'] = true;
					$e['defaults'] = ['show' => Columns::SHOW_TEXT] + self::PLAIN;
					$e['fix'] = _('Monitor the client\'s cluster host with Elasticsearch Cluster by HTTP EP; the forecast needs 7 days of storage history.');
					break;
				case 'delay':
					$e['fix'] = _('Link the ElasticPro log delay template and let ElasticPro measure the delay.');
					break;
			}
			$out[$id] = $e;
		}

		return $out;
	}

	/** The catalogue a column of this scope may read: Client capacity has no server rows. */
	public static function forScope(array $catalogue, string $scope): array {
		return $scope === 'row' ? [] : $catalogue;
	}
}
