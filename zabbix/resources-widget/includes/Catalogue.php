<?php declare(strict_types = 0);

namespace Modules\EpResources\Includes;

use Modules\EpResources\Lib\{Columns, Roles};

/**
 * What Client resources offers as built-in columns, in the order the report has always drawn
 * them: the client (name, type, ES URL), log delay, ES storage, devices, then per family — or per
 * role, where a family is shown as its roles — servers, CPU, memory and disk, each requested,
 * allocated and used. Those are "auto": the report's own columns, the ones a list nobody has
 * saved shows, and the ones a role added in Cluster Management brings with it.
 *
 * Built from the roles each time, so a family or role added on the Roles page is here with no
 * change to this file. The ids are the ones the report has always used (client.name,
 * f.es.cpu.requested, r.es_data_hot.disk.used …), which is what lets a version-1 Columns file —
 * names, hidden columns and order keyed by them — be read onto the column model unchanged.
 *
 * Also: further built-in figures a column may read (log delay median and worst, ES storage used %
 * and "full in", each family's disk used %, every server's CPU, memory and disk), the server row's
 * columns, and the used / allocated / requested measures a Pie chart donut can show.
 *
 * Each entry: label, group, on (Columns::ON_*), key, units, text (not a number), settings (a usage
 * percentage, the kind ElasticPro -> Settings' thresholds are for), pct (the item holding this
 * figure's share of allocated), vs (the request it may fall short of), requested (a request: 0 is
 * "not set"), field / macro (a client's own text), forecast, auto, fix (what gives a client the
 * figure) and defaults (how the column looks until somebody changes it — as the report has always
 * drawn it).
 *
 * A widget whose columns are the shared column model has a class of this name; the shared column
 * editor (actions/ColumnEdit.php) and Columns dialog (actions/ColumnsSave.php) find it by it.
 */
class Catalogue {

	public const REPORT = 'resources';

	private const RES = ['servers' => 'servers', 'cpu' => 'CPU (cores)', 'mem' => 'memory', 'disk' => 'disk'];

	/** How the report's own columns look, as they always have: no link, an unknown is "—". */
	private const PLAIN = ['click' => Columns::CLICK_NONE, 'nodata' => Columns::NODATA_DASH];

	/** A figure with a share behind it: drawn as the widget's bar (Display), coloured at Settings' thresholds. */
	private const BAR = ['click' => Columns::CLICK_NONE, 'nodata' => Columns::NODATA_DASH, 'show' => Columns::SHOW_BAR, 'use_settings' => 1];

	/** The report's columns, in its order, for this roles file and these families shown as roles. */
	public static function columns(array $roles, array $split = []): array {
		$master = _('Write the master template (ElasticPro → Clients → Write master template) and check the client\'s cluster host is monitored.');
		$delay = _('Link the ElasticPro log delay template and let ElasticPro measure the delay.');
		$devices = _('Link the ElasticPro cluster devices template to the client\'s cluster host.');
		$out = [
			'client.name' => ['label' => 'Cluster Name', 'group' => '', 'on' => Columns::ON_CLIENT, 'field' => 'name', 'text' => true, 'units' => '',
				'auto' => true, 'defaults' => self::PLAIN],
			'client.type' => ['label' => 'Type', 'group' => '', 'on' => Columns::ON_CLIENT, 'field' => 'type', 'text' => true, 'units' => '',
				'auto' => true, 'defaults' => ['show' => Columns::SHOW_BADGE] + self::PLAIN],
			'client.url' => ['label' => 'ES URL', 'group' => '', 'on' => Columns::ON_CLIENT, 'macro' => '{$ES.URL}', 'text' => true, 'units' => '',
				'auto' => true, 'defaults' => self::PLAIN],
			'delay.late' => ['label' => 'Devices late', 'group' => 'Log delay', 'on' => Columns::ON_MASTER, 'key' => 'ep.delay.late', 'units' => '',
				'auto' => true, 'fix' => $delay, 'defaults' => self::PLAIN],
			'delay.median' => ['label' => 'Median', 'group' => 'Log delay', 'on' => Columns::ON_MASTER, 'key' => 'ep.delay.median', 'units' => 's', 'fix' => $delay],
			'delay.worst' => ['label' => 'Worst', 'group' => 'Log delay', 'on' => Columns::ON_MASTER, 'key' => 'ep.delay.worst', 'units' => 's', 'fix' => $delay],
			'storage.purchased' => ['label' => 'Purchased', 'group' => 'ES storage', 'on' => Columns::ON_MASTER, 'key' => 'ep.es.storage.purchased', 'units' => 'B',
				'requested' => true, 'auto' => true, 'fix' => _('Set ES storage purchased for the client in Cluster Management.'), 'defaults' => self::PLAIN],
			'storage.allocated' => ['label' => 'Allocated', 'group' => 'ES storage', 'on' => Columns::ON_MASTER, 'key' => 'ep.es.storage.allocated', 'units' => 'B',
				'vs' => 'ep.es.storage.purchased', 'vs_label' => _('purchased'), 'auto' => true, 'fix' => $master, 'defaults' => self::PLAIN],
			'storage.used' => ['label' => 'Used', 'group' => 'ES storage', 'on' => Columns::ON_MASTER, 'key' => 'ep.es.storage.used', 'units' => 'B',
				'auto' => true, 'fix' => $master, 'defaults' => self::PLAIN],
			'storage.usage' => ['label' => 'Used %', 'group' => 'ES storage', 'on' => Columns::ON_MASTER, 'key' => 'ep.es.storage.usage', 'units' => '%',
				'settings' => true, 'auto' => true, 'fix' => _('allocated not set'), 'defaults' => self::BAR],
			'storage.full_in' => ['label' => 'Full in', 'group' => 'ES storage', 'on' => Columns::ON_MASTER, 'key' => 'ep.es.storage.full_in', 'units' => 's',
				'forecast' => true, 'fix' => _('Monitor the client\'s cluster host with Elasticsearch Cluster by HTTP EP; the forecast needs 7 days of storage history.')],
			'devices.purchased' => ['label' => 'Purchased', 'group' => 'Devices', 'on' => Columns::ON_MASTER, 'key' => 'ep.devices.purchased', 'units' => '',
				'requested' => true, 'auto' => true, 'fix' => _('Set Devices purchased for the client in Cluster Management.'), 'defaults' => self::PLAIN],
			'devices.seen' => ['label' => 'Seen', 'group' => 'Devices', 'on' => Columns::ON_MASTER, 'key' => 'ep.devices.seen', 'units' => '',
				'pct' => 'ep.devices.usage', 'auto' => true, 'fix' => $devices, 'defaults' => self::BAR],
			'devices.usage' => ['label' => 'Seen of purchased', 'group' => 'Devices', 'on' => Columns::ON_MASTER, 'key' => 'ep.devices.usage', 'units' => '%',
				'settings' => true, 'fix' => _('Set Devices purchased for the client in Cluster Management.')]
		];
		$split = array_flip($split);
		foreach ($roles['families'] ?? [] as $f) {
			$units = [['prefix' => 'f.'.$f['id'], 'label' => (string) $f['label'], 'auto' => !isset($split[$f['id']]),
				'key' => fn($res, $kind) => 'ep.'.$f['id'].'.'.$res.'.'.$kind]];
			foreach ($f['roles'] ?? [] as $r) {
				$units[] = ['prefix' => 'r.'.$r['id'], 'label' => (string) $r['label'], 'auto' => isset($split[$f['id']]),
					'key' => fn($res, $kind) => 'ep.role.'.$res.'.'.$kind.'['.$r['id'].']'];
			}
			foreach ($units as $u) {
				foreach ($f['order'] ?? Roles::RESOURCES as $res) {
					$g = $u['label'].' '.self::RES[$res];
					$k = $u['key'];
					$size = $res === 'mem' || $res === 'disk' ? 'B' : '';
					$fix = $res === 'disk' ? _s('Set the %1$s disk mounts in Cluster Management, then write the master template.', $u['label']) : $master;
					$out[$u['prefix'].'.'.$res.'.requested'] = ['label' => 'Requested', 'group' => $g, 'on' => Columns::ON_MASTER, 'key' => $k($res, 'requested'),
						'units' => $size, 'requested' => true, 'auto' => $u['auto'], 'fix' => _('Set what the client requested in Cluster Management.'),
						'defaults' => self::PLAIN];
					$out[$u['prefix'].'.'.$res.'.allocated'] = ['label' => 'Allocated', 'group' => $g, 'on' => Columns::ON_MASTER, 'key' => $k($res, 'allocated'),
						'units' => $size, 'vs' => $k($res, 'requested'), 'vs_label' => _('requested'), 'auto' => $u['auto'], 'fix' => $master, 'defaults' => self::PLAIN];
					if ($res === 'cpu' || $res === 'mem') {
						$out[$u['prefix'].'.'.$res.'.usage'] = ['label' => 'Used', 'group' => $g, 'on' => Columns::ON_MASTER, 'key' => $k($res, 'usage'),
							'units' => '%', 'settings' => true, 'auto' => $u['auto'], 'defaults' => self::BAR,
							'fix' => _('Link the Linux agent template to the servers so their CPU and memory are measured.')];
					}
					if ($res === 'disk') {
						$out[$u['prefix'].'.disk.used'] = ['label' => 'Used', 'group' => $g, 'on' => Columns::ON_MASTER, 'key' => $k('disk', 'used'),
							'units' => 'B', 'pct' => $k('disk', 'usage'), 'auto' => $u['auto'], 'fix' => $fix, 'defaults' => self::BAR];
						$out[$u['prefix'].'.disk.usage'] = ['label' => 'Used %', 'group' => $g, 'on' => Columns::ON_MASTER, 'key' => $k('disk', 'usage'),
							'units' => '%', 'settings' => true, 'fix' => $fix];
					}
				}
			}
		}
		$agent = _('Link Linux by Zabbix agent -EP (or the template mapped to it on Roles → Template mapping) to the client\'s servers.');
		$out['srv.cpu'] = ['label' => _('CPU (each server)'), 'group' => '', 'on' => Columns::ON_SERVERS, 'key' => 'system.cpu.util', 'units' => '%', 'settings' => true, 'fix' => $agent];
		$out['srv.mem'] = ['label' => _('Memory (each server)'), 'group' => '', 'on' => Columns::ON_SERVERS, 'key' => 'vm.memory.utilization', 'units' => '%', 'settings' => true, 'fix' => $agent];
		$out['srv.disk'] = ['label' => _('Disk used (each server\'s mounts)'), 'group' => '', 'on' => Columns::ON_SERVERS, 'key' => 'vfs.fs.dependent.size[{#MOUNT},pused]',
			'units' => '%', 'settings' => true, 'disk' => true, 'fix' => _('Set the role\'s disk mounts in Cluster Management and link the Linux agent template to the servers.')];
		return $out + self::serverColumns();
	}

	/**
	 * A server row's columns, when a client is expanded. Disk mount, size and used are drawn one
	 * column per mount the client's servers have (Disk /, Disk /data …), size and used side by
	 * side in one cell as the report always drew them; Disk mount adds the mount's name to it.
	 */
	public static function serverColumns(): array {
		$row = fn(string $label, string $field, array $extra = []) => $extra + ['label' => $label, 'group' => '', 'on' => Columns::ON_ROW, 'field' => $field,
			'units' => '', 'auto' => true, 'defaults' => self::PLAIN];
		return [
			'row.role' => $row(_('Role'), 'role', ['text' => true]),
			'row.host' => $row(_('Host'), 'host', ['text' => true, 'defaults' => ['click' => Columns::CLICK_LATEST, 'nodata' => Columns::NODATA_DASH]]),
			'row.ip' => $row(_('IP'), 'ip', ['text' => true]),
			'row.status' => $row(_('Status'), 'status', ['text' => true]),
			'row.cores' => $row(_('CPU cores'), 'cpu_cores', ['label_short' => _('Cores')]),
			'row.cpu' => $row(_('CPU used'), 'cpu_used', ['units' => '%', 'settings' => true, 'defaults' => self::BAR]),
			'row.mem_total' => $row(_('Memory'), 'mem_total', ['units' => 'B']),
			'row.mem' => $row(_('Memory used'), 'mem_used', ['units' => '%', 'settings' => true, 'defaults' => self::BAR]),
			'row.disk_mount' => $row(_('Disk mount'), 'mount', ['text' => true, 'hidden' => true]),
			'row.disk_size' => $row(_('Disk size'), 'disk_total', ['units' => 'B']),
			'row.disk_used' => $row(_('Disk used'), 'disk_used', ['units' => '%', 'settings' => true, 'defaults' => self::BAR]),
			'row.uptime' => $row(_('Uptime'), 'uptime', ['units' => 'uptime', 'hidden' => true])
		];
	}

	/** Server row columns, as the report draws them until somebody changes them: Disk mount and Uptime off. */
	public static function serverDefaults(): array {
		$out = [];
		foreach (self::serverColumns() as $id => $e) {
			$c = \Modules\EpResources\Lib\ColumnSettings::builtinColumn($id, $e);
			$c['hidden'] = !empty($e['hidden']) ? 1 : 0;
			$out[] = $c;
		}
		return $out;
	}

	/** The catalogue a column of this scope may read. */
	public static function forScope(array $catalogue, string $scope): array {
		return array_filter($catalogue, fn($e) => ($e['on'] === Columns::ON_ROW) === ($scope === 'row'));
	}

	/**
	 * Every figure a Pie chart donut can show: one with something used, something allocated and
	 * something requested. ES storage; each family's (or, shown as roles, each role's) CPU, memory
	 * and disk; devices (seen of purchased). CPU and memory are measured as a share, so their used
	 * is that share of allocated — the figure the Used column colours.
	 *
	 * @return array  id => [label, units, used, used_pct, allocated, requested, fix]
	 */
	public static function measures(array $roles, array $split = []): array {
		$out = [
			'storage' => ['label' => _('ES storage'), 'units' => 'B', 'used' => 'ep.es.storage.used', 'used_pct' => null,
				'allocated' => 'ep.es.storage.allocated', 'requested' => 'ep.es.storage.purchased'],
		];
		$split = array_flip($split);
		foreach ($roles['families'] ?? [] as $f) {
			$units = isset($split[$f['id']])
				? array_map(fn($r) => ['id' => 'r.'.$r['id'], 'label' => (string) $r['label'], 'key' => fn($res, $kind) => 'ep.role.'.$res.'.'.$kind.'['.$r['id'].']'], $f['roles'] ?? [])
				: [['id' => 'f.'.$f['id'], 'label' => (string) $f['label'], 'key' => fn($res, $kind) => 'ep.'.$f['id'].'.'.$res.'.'.$kind]];
			foreach ($units as $u) {
				$k = $u['key'];
				$out[$u['id'].'.cpu'] = ['label' => _s('%1$s CPU', $u['label']), 'units' => 'cores', 'used' => null, 'used_pct' => $k('cpu', 'usage'),
					'allocated' => $k('cpu', 'allocated'), 'requested' => $k('cpu', 'requested')];
				$out[$u['id'].'.mem'] = ['label' => _s('%1$s memory', $u['label']), 'units' => 'B', 'used' => null, 'used_pct' => $k('mem', 'usage'),
					'allocated' => $k('mem', 'allocated'), 'requested' => $k('mem', 'requested')];
				$out[$u['id'].'.disk'] = ['label' => _s('%1$s disk', $u['label']), 'units' => 'B', 'used' => $k('disk', 'used'), 'used_pct' => null,
					'allocated' => $k('disk', 'allocated'), 'requested' => $k('disk', 'requested')];
			}
		}
		$out['devices'] = ['label' => _('Devices'), 'units' => '', 'used' => 'ep.devices.seen', 'used_pct' => null,
			'allocated' => 'ep.devices.purchased', 'requested' => 'ep.devices.purchased'];
		return $out;
	}

	/** The donuts a Pie chart shows until somebody chooses: ES storage, ES CPU and ES memory. */
	public const DEFAULT_DONUTS = ['storage', 'f.es.cpu', 'f.es.mem'];
}
