<?php declare(strict_types = 0);

namespace Modules\EpTophosts\Includes;

use Modules\EpTophosts\Lib\{Columns, ColumnSettings};

/**
 * What Top hosts offers as built-in columns, and what it ranks by. A row is a server, so a
 * "client" here is the row itself — the text it reads is the server's own (its rank, host, client
 * and role, IP, status) — and the figures are the server's: CPU, memory, disk, uptime, or any
 * Zabbix item, now or over a window, through the column model.
 *
 * Rank, Host, Value and Client · Role are the four columns a widget saved before 2.1.1 drew. Value
 * is the figure the widget ranks by (CPU, memory, disk used, JVM heap or any item, now or its average
 * or maximum over the window) — the widget decides it, so it is only ever the value now as a
 * column; every other column is an ordinary column of the model, with its own value, thresholds
 * and look.
 *
 * A widget on the shared column model has a class of this name: the shared column editor
 * (actions/ColumnEdit.php) finds it by it.
 */
class Catalogue {

	public const REPORT = 'tophosts';

	/** What the Rank by setting stores, by position: Zabbix wants an integer there. */
	public const RANKS = ['cpu', 'mem', 'disk', 'jvm', 'item'];

	/** The per-node JVM heap item of the Elasticsearch template, read on the server itself. */
	public const JVM_KEY = 'es.node.jvm.mem.heap_used_percent[*]';

	private const PLAIN = ['click' => Columns::CLICK_NONE, 'nodata' => Columns::NODATA_DASH];

	/** A row is one host, so there are no servers to pick roles of. */
	public static function single(): bool {
		return true;
	}

	/** The columns a widget saved before 2.1.1 drew, in their order. */
	public const CORE = ['top.rank', 'top.host', 'top.value', 'top.clientrole'];

	/** The row's own columns first, then the server's figures. */
	public static function columns(array $roles = [], array $split = []): array {
		$agent = _('Link Linux by Zabbix agent -EP (or the template mapped to it on Roles → Template mapping) to the client\'s servers.');
		$row = fn(string $label, string $field, array $extra = []) => $extra + ['label' => $label, 'group' => '', 'on' => Columns::ON_CLIENT, 'field' => $field,
			'text' => true, 'units' => '', 'auto' => true, 'defaults' => self::PLAIN];

		return [
			'top.rank' => $row(_('Rank'), 'rank'),
			'top.host' => $row(_('Host'), 'host', ['defaults' => ['click' => Columns::CLICK_LATEST, 'nodata' => Columns::NODATA_DASH]]),
			'top.value' => ['label' => _('Value'), 'group' => '', 'on' => Columns::ON_CLIENT, 'field' => 'value', 'units' => '%', 'settings' => true, 'now' => true,
				'auto' => true, 'defaults' => ['show' => Columns::SHOW_BAR, 'use_settings' => 1] + self::PLAIN],
			'top.clientrole' => $row(_('Client · Role'), 'client_role'),
			'top.client' => $row(_('Client'), 'client'),
			'top.role' => $row(_('Role'), 'role'),
			'top.ip' => $row(_('IP'), 'ip'),
			'top.status' => $row(_('Status'), 'status', ['defaults' => ['show' => Columns::SHOW_BADGE] + self::PLAIN]),
			'srv.cpu' => ['label' => _('CPU used'), 'group' => '', 'on' => Columns::ON_SERVERS, 'key' => 'system.cpu.util', 'units' => '%', 'settings' => true,
				'auto' => true, 'fix' => $agent, 'defaults' => ['show' => Columns::SHOW_BAR, 'use_settings' => 1] + self::PLAIN],
			'srv.mem' => ['label' => _('Memory used'), 'group' => '', 'on' => Columns::ON_SERVERS, 'key' => 'vm.memory.utilization', 'units' => '%', 'settings' => true,
				'auto' => true, 'fix' => $agent, 'defaults' => ['show' => Columns::SHOW_BAR, 'use_settings' => 1] + self::PLAIN],
			'srv.disk' => ['label' => _('Disk used (fullest mount)'), 'group' => '', 'on' => Columns::ON_SERVERS, 'key' => 'vfs.fs.dependent.size[{#MOUNT},pused]', 'units' => '%',
				'settings' => true, 'disk' => true, 'auto' => true, 'fix' => _('Set the role\'s disk mounts in Cluster Management and link the Linux agent template to the servers.'),
				'defaults' => ['show' => Columns::SHOW_BAR, 'use_settings' => 1] + self::PLAIN],
			'srv.uptime' => ['label' => _('Uptime'), 'group' => '', 'on' => Columns::ON_SERVERS, 'key' => 'system.uptime', 'units' => 'uptime', 'auto' => true, 'fix' => $agent,
				'defaults' => self::PLAIN]
		];
	}

	public static function forScope(array $catalogue, string $scope): array {
		return $scope === 'row' ? [] : $catalogue;
	}

	/** A widget nobody has set the columns of: Rank, Host, Value and Client · Role. */
	public static function defaultColumns(array $catalogue): array {
		return array_map(fn($id) => ColumnSettings::builtinColumn($id, $catalogue[$id]), self::CORE);
	}

	/**
	 * What a metric is, as a column of the model (the part of it that says where the figure is read):
	 * its label, and its source, units and value (now, or the average or maximum over the window).
	 *
	 * @param string $id  one of RANKS
	 * @param array  $f   the widget's fields: rank_key, rank_pattern, rank_value
	 *
	 * @return array{label: string, column: array}
	 */
	public static function metric(string $id, array $f): array {
		$value = [Columns::VALUE_NOW, Columns::VALUE_AVG, Columns::VALUE_MAX][(int) ($f['rank_value'] ?? 0)] ?? Columns::VALUE_NOW;
		$window = max(1, (int) ($f['trend_days'] ?? 7));
		switch ($id) {
			case 'mem':
				$label = _('Memory');
				$column = ['source' => Columns::SOURCE_BUILTIN, 'builtin' => 'srv.mem', 'units' => '%'];
				break;
			case 'disk':
				$label = _('Disk used');
				$column = ['source' => Columns::SOURCE_BUILTIN, 'builtin' => 'srv.disk', 'units' => '%'];
				break;
			case 'jvm':
				$label = _('JVM heap');
				$column = ['source' => Columns::SOURCE_ITEM, 'key' => self::JVM_KEY, 'pattern' => 1, 'units' => '%'];
				break;
			case 'item':
				$label = (string) ($f['rank_key'] ?? '');
				$column = ['source' => Columns::SOURCE_ITEM, 'key' => $label, 'pattern' => (int) ($f['rank_pattern'] ?? 0), 'units' => ''];
				break;
			default:
				$label = _('CPU');
				$column = ['source' => Columns::SOURCE_BUILTIN, 'builtin' => 'srv.cpu', 'units' => '%'];
		}
		if ($value !== Columns::VALUE_NOW) {
			$label = _s('%1$s · %2$s over %3$s d', $label, $value === Columns::VALUE_AVG ? _('average') : _('maximum'), $window);
		}

		return ['label' => $label, 'column' => $column + ['value' => $value, 'window' => 0, 'combine' => Columns::COMBINE_MAX]];
	}
}
