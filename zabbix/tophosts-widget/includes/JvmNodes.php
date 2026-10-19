<?php declare(strict_types = 0);

namespace Modules\EpTophosts\Includes;

use API;

/**
 * Where Top hosts reads a server's JVM heap.
 *
 * Elasticsearch reports heap per node, and the templates the clients use keep it on the client's
 * cluster host, not on the servers: Zabbix's "Elasticsearch Cluster by HTTP" and ElasticPro's
 * "Elasticsearch via SSH jump host" both discover the nodes there and make
 * es.node.jvm.mem.heap_used_percent[<node name>] for each (checked on the test Zabbix, 7.0.31: the
 * item is on Local-ES-Cluster only, keyed [ep-es-1]; no server carries it). Reading the key on the
 * servers, as 2.1.0 did, ranked nothing.
 *
 * So: a server that carries the item itself (a site that put the node template on each node) is
 * read there, as before. Any other server is read on its client's cluster host, at the node whose
 * name is the server's — its Zabbix host name or visible name, regardless of case, or the first
 * label of either ("es-hot-1" for "es-hot-1.example.com"). A node that names no server is ranked
 * as itself — "<node>", "<client> · ES node" — so a site whose host names are not its node names
 * still sees its heap, said plainly instead of an empty list.
 *
 * Three calls to Zabbix, only when JVM heap is ranked or offered: the clients' host groups, their
 * hosts, and the node items on them.
 */
class JvmNodes {

	/** The node items, by key: Catalogue::JVM_KEY without its "*]". */
	public const PREFIX = 'es.node.jvm.mem.heap_used_percent[';

	/**
	 * The node items on every host of these clients, and those hosts.
	 *
	 * @param string[] $clients  the client names (each its own host group)
	 *
	 * @return array{hosts: array, items: array}  hosts: hostid => [host, name, client]; items: [itemid, hostid, key_]
	 */
	public static function find(array $clients): array {
		if (!$clients) {
			return ['hosts' => [], 'items' => []];
		}
		$groups = array_column(API::HostGroup()->get(['output' => ['groupid', 'name'], 'filter' => ['name' => array_values($clients)]]), 'name', 'groupid');
		if (!$groups) {
			return ['hosts' => [], 'items' => []];
		}
		$hosts = [];
		foreach (API::Host()->get(['output' => ['hostid', 'host', 'name'], 'groupids' => array_keys($groups), 'selectHostGroups' => ['groupid', 'name'],
				'preservekeys' => true]) ?: [] as $hostid => $h) {
			$client = array_values(array_intersect(array_column($h['hostgroups'] ?? [], 'name'), $groups))[0] ?? null;
			if ($client !== null) {
				$hosts[$hostid] = ['host' => (string) ($h['host'] ?? $h['name']), 'name' => (string) $h['name'], 'client' => $client];
			}
		}
		if (!$hosts) {
			return ['hosts' => [], 'items' => []];
		}
		$items = API::Item()->get(['output' => ['itemid', 'hostid', 'key_'], 'hostids' => array_keys($hosts), 'search' => ['key_' => self::PREFIX],
			'startSearch' => true]) ?: [];

		return ['hosts' => $hosts, 'items' => array_values(array_filter($items, fn($it) => self::node((string) $it['key_']) !== null))];
	}

	/** The node a key names: "ep-es-1" for es.node.jvm.mem.heap_used_percent[ep-es-1]; null for another key. */
	public static function node(string $key): ?string {
		if (strpos($key, self::PREFIX) !== 0 || substr($key, -1) !== ']') {
			return null;
		}
		$node = trim(substr($key, strlen(self::PREFIX), -1), " \t\"");

		return $node !== '' ? $node : null;
	}

	/**
	 * Where each row reads JVM heap, and the nodes that name no server. Pure, for the tests.
	 *
	 * @param array  $servers  hostid => ['client' => client name, 'host' => visible name] — the rows Top hosts ranks
	 * @param array  $hosts    JvmNodes::find()['hosts']
	 * @param array  $items    JvmNodes::find()['items']
	 * @param string $uid      the JVM column's uid
	 *
	 * @return array{at: array, nodes: array, reported: int}  at: row hostid => [uid => [[hostid, key], …]] for the servers read on a
	 *         cluster host; nodes: [cluster hostid, node, key, client] for a node that names no server; reported: how many items in all
	 */
	public static function place(array $servers, array $hosts, array $items, string $uid): array {
		$own = [];
		$byClient = [];
		foreach ($items as $it) {
			$hostid = (string) $it['hostid'];
			if (isset($servers[$hostid])) {
				// The server carries it: the column reads it there, as it always did.
				$own[$hostid] = true;
				continue;
			}
			$client = $hosts[$hostid]['client'] ?? null;
			$node = self::node((string) $it['key_']);
			if ($client !== null && $node !== null) {
				$byClient[$client][] = ['hostid' => $hostid, 'node' => $node, 'key' => (string) $it['key_']];
			}
		}

		$at = [];
		$nodes = [];
		foreach ($byClient as $client => $list) {
			$mine = array_filter($servers, fn($s) => $s['client'] === $client);
			foreach ($list as $n) {
				$match = self::match($n['node'], $mine, $hosts);
				if ($match === null) {
					$nodes[] = ['hostid' => $n['hostid'], 'node' => $n['node'], 'key' => $n['key'], 'client' => $client];
				}
				// A server that carries the item itself is read there; its node on the cluster host is the same figure.
				elseif (!isset($own[$match])) {
					$at[$match][$uid][] = [$n['hostid'], $n['key']];
				}
			}
		}

		return ['at' => $at, 'nodes' => $nodes, 'reported' => count($items)];
	}

	/**
	 * The server a node is: its host or visible name, regardless of case; else the one server whose
	 * first label is the node's first label. Null when none is, or when that is not one server.
	 */
	private static function match(string $node, array $servers, array $hosts): ?string {
		$full = mb_strtolower($node);
		$label = fn(string $s) => explode('.', mb_strtolower($s), 2)[0];
		$names = function ($id, array $s) use ($hosts) {
			return array_values(array_unique(array_filter([(string) ($hosts[(string) $id]['host'] ?? ''), (string) ($hosts[(string) $id]['name'] ?? ''),
				(string) ($s['host'] ?? '')], fn($n) => $n !== '')));
		};
		foreach ($servers as $id => $s) {
			if (in_array($full, array_map('mb_strtolower', $names($id, $s)), true)) {
				return (string) $id;
			}
		}
		$short = [];
		foreach ($servers as $id => $s) {
			if (in_array($label($node), array_map($label, $names($id, $s)), true)) {
				$short[] = (string) $id;
			}
		}

		return count($short) === 1 ? $short[0] : null;
	}
}
