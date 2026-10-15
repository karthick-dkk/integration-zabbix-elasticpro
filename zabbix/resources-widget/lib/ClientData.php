<?php declare(strict_types = 0);

namespace Modules\EpResources\Lib;

use API;

/**
 * The clients and their servers, read one way for every report that lists them: Client resources,
 * and Top hosts, which ranks the same servers. Two readings would drift — a decommissioned client
 * dropped from one and not the other, a server's fullest disk taken one way here and another there —
 * so both ask this.
 *
 * Copied from ../../shared/php by ../../sync-assets.mjs — edit it there.
 */
class ClientData {

	/**
	 * The client master hosts this user can see, and the clients they describe. Decommissioned
	 * clients are left out; disabled ones stay, with a badge. Empty $groupids: every master host.
	 *
	 * @return array{0: array, 1: array, 2: ?string}  [masters by hostid, clients by hostid, error]
	 */
	public static function clients(array $cfg, array $groupids): array {
		$names = Roles::templateNamesFor('master');
		$templates = API::Template()->get(['output' => ['templateid'], 'filter' => ['host' => $names]]);
		if (!$templates) {
			return [[], [], _s('Template "%1$s" is not in Zabbix yet — write it from ElasticPro → Clients.', $names[0])];
		}
		// Every template the mapping answers to counts: a site that renamed it and has hosts on
		// both the old name and the new one sees all of its clients, not whichever sorted first.
		$options = ['output' => ['hostid', 'name', 'maintenance_status'], 'templateids' => array_column($templates, 'templateid'), 'preservekeys' => true];
		if ($groupids) {
			$options['groupids'] = getSubGroups($groupids);
		}
		$masters = API::Host()->get($options);
		$macros = [];
		if ($masters) {
			foreach (API::UserMacro()->get(['output' => ['hostid', 'macro', 'value'], 'hostids' => array_keys($masters)]) ?: [] as $m) {
				$macros[$m['hostid']][$m['macro']] = (string) ($m['value'] ?? '');
			}
		}
		$clients = [];
		foreach ($masters as $hostid => $master) {
			$m = $macros[$hostid] ?? [];
			// Decommissioned clients leave the report.
			if (($m['{$EP.CLIENT.STATUS}'] ?? 'active') === 'decommissioned') {
				unset($masters[$hostid]);
				continue;
			}
			$clients[$hostid] = [
				'name' => ($m['{$GRP.CLIENT}'] ?? '') !== '' ? $m['{$GRP.CLIENT}'] : $master['name'],
				'type' => ($m['{$EP.CLIENT.TYPE}'] ?? '') !== '' ? $m['{$EP.CLIENT.TYPE}'] : Settings::defaultType($cfg),
				'badge' => ($m['{$EP.CLIENT.STATUS}'] ?? 'active') === 'disabled' ? _('disabled') : ((int) ($master['maintenance_status'] ?? 0) === 1 ? _('maintenance') : ''),
				'macros' => $m
			];
		}
		return [$masters, $clients, null];
	}

	/** Every client's machines, each with its role, IP, status and CPU, memory and disk. */
	public static function servers(array $roles, array $clients): array {
		if (!$clients) {
			return [];
		}
		$names = array_column($clients, 'name');
		$groups = array_column(API::HostGroup()->get(['output' => ['groupid', 'name'], 'filter' => ['name' => $names]]), 'groupid', 'name');
		if (!$groups) {
			return [];
		}
		$hosts = API::Host()->get(['output' => ['hostid', 'name'], 'groupids' => array_values($groups), 'selectHostGroups' => ['name'],
			'selectInterfaces' => ['ip', 'type', 'main', 'available'], 'selectTags' => ['tag', 'value'], 'preservekeys' => true]);
		// Each role's disks: / and its extra mounts, from the client's master host.
		$mounts = [];
		foreach ($clients as $c) {
			foreach (Roles::allRoles($roles) as $r) {
				$mounts[$c['name']][$r['id']] = Roles::diskMounts($c['macros'], $r['id']);
			}
		}
		$out = [];
		$want = [];
		foreach ($hosts as $hostid => $h) {
			$gn = array_column($h['hostgroups'], 'name');
			$client = array_values(array_intersect($names, $gn))[0] ?? null;
			if ($client === null) {
				continue;
			}
			// The same reading of roles as the Clients page: every role whose group the host is in.
			$held = Roles::rolesOfGroups($roles, $gn);
			if (!$held) {
				continue;
			}
			$ids = array_column($held, 'id');
			$services = [];
			foreach ($h['tags'] ?? [] as $t) {
				if ($t['tag'] === 'ep-service' && !in_array($t['value'], $ids, true)) {
					$services[] = $t['value'];
				}
			}
			$ip = null;
			$avail = 0;
			foreach ($h['interfaces'] as $if) {
				if ($if['type'] == INTERFACE_TYPE_AGENT && $if['main'] == INTERFACE_PRIMARY) {
					$ip = $if['ip'];
					$avail = (int) $if['available'];
				}
			}
			$mine = [];
			foreach ($ids as $rid) {
				foreach ($mounts[$client][$rid] as $m) {
					if (!in_array($m, $mine, true)) {
						$mine[] = $m;
					}
				}
			}
			$out[$client][$hostid] = ['role' => implode(' + ', array_column($held, 'label')), 'roles' => $ids, 'services' => $services,
				'shared' => count($held) > 1 && !self::isSingleNode($held), 'single' => self::isSingleNode($held), 'host' => $h['name'], 'hostid' => $hostid, 'ip' => $ip ?? '',
				'status' => $avail === 1 ? 'up' : ($avail === 2 ? 'down' : 'unknown'), 'mount' => implode(', ', $mine),
				'disks' => array_map(fn($m) => ['mount' => $m, 'total' => null, 'used' => null], $mine),
				'cpu_cores' => null, 'cpu_used' => null, 'mem_total' => null, 'mem_used' => null, 'disk_total' => null, 'disk_used' => null];
			$want[$hostid] = ['system.cpu.num' => 'cpu_cores', 'system.cpu.util' => 'cpu_used', 'vm.memory.size[total]' => 'mem_total',
				'vm.memory.utilization' => 'mem_used'];
			foreach ($mine as $i => $m) {
				$want[$hostid]['vfs.fs.dependent.size['.$m.',total]'] = 'disk.'.$i.'.total';
				$want[$hostid]['vfs.fs.dependent.size['.$m.',pused]'] = 'disk.'.$i.'.used';
			}
		}
		if ($want) {
			$keys = array_unique(array_merge(...array_map('array_keys', array_values($want))));
			foreach (API::Item()->get(['output' => ['hostid', 'key_', 'lastvalue', 'lastclock', 'state'], 'hostids' => array_keys($want),
					'filter' => ['key_' => $keys]]) as $it) {
				$field = $want[$it['hostid']][$it['key_']] ?? null;
				if ($field === null || !self::known($it)) {
					continue;
				}
				foreach ($out as $client => $list) {
					if (!isset($list[$it['hostid']])) {
						continue;
					}
					if (preg_match('/^disk\.(\d+)\.(total|used)$/', $field, $m)) {
						$out[$client][$it['hostid']]['disks'][(int) $m[1]][$m[2]] = (float) $it['lastvalue'];
					}
					else {
						$out[$client][$it['hostid']][$field] = (float) $it['lastvalue'];
					}
				}
			}
		}
		// A server's disk: the sum of the sizes known, and its fullest disk — each only when measured.
		foreach ($out as $client => $list) {
			foreach ($list as $hid => $s) {
				$totals = array_filter(array_column($s['disks'], 'total'), fn($v) => $v !== null);
				$used = array_filter(array_column($s['disks'], 'used'), fn($v) => $v !== null);
				$out[$client][$hid]['disk_total'] = $totals ? array_sum($totals) : null;
				$out[$client][$hid]['disk_used'] = $used ? max($used) : null;
			}
		}
		foreach ($out as $client => $list) {
			$list = array_values($list);
			usort($list, fn($a, $b) => [$a['role'], $a['host']] <=> [$b['role'], $b['host']]);
			$out[$client] = $list;
		}
		return $out;
	}

	/**
	 * A server's colour classes, each from its own figure and the client's thresholds: CPU,
	 * memory, the fullest disk — and every disk on its own, so a second disk past its threshold
	 * is coloured too and not only the fullest one.
	 */
	public static function classify(array $s, float $warn, float $high): array {
		$s['classes'] = [
			'cpu' => self::usageClass($s['cpu_used'], $warn, $high),
			'mem' => self::usageClass($s['mem_used'], $warn, $high),
			'disk' => self::usageClass($s['disk_used'], $warn, $high)
		];
		foreach ($s['disks'] as $i => $d) {
			$s['disks'][$i]['class'] = self::usageClass($d['used'], $warn, $high);
		}
		return $s;
	}

	/** The families a single node stands for: ES, Parser, Forwarder and Engine on one machine. */
	public const SINGLE_NODE_FAMILIES = ['es', 'parser', 'fwd', 'engine'];

	/**
	 * A server is a single node when it carries the all-in-one role, or when its roles cover ES,
	 * Parser, Forwarder and Engine together — one box doing all four, whatever roles name it.
	 */
	public static function isSingleNode(array $held): bool {
		if (array_filter($held, fn($r) => !empty($r['exclusive']))) {
			return true;
		}
		$families = array_unique(array_merge(...array_map(fn($r) => Roles::familiesOf($r), $held ?: [['family' => '']])));
		return !array_diff(self::SINGLE_NODE_FAMILIES, $families);
	}

	public static function known(array $it): bool {
		return $it['state'] == ITEM_STATE_NORMAL && $it['lastclock'] != 0 && $it['lastvalue'] !== '';
	}

	public static function usageClass(?float $v, float $warn, float $high): string {
		return $v === null ? '' : ($v >= $high ? 'ep-high' : ($v >= $warn ? 'ep-warn' : ''));
	}
}
