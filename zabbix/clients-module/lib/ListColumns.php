<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

/**
 * The Cluster Management list's columns (board List-after.dc.html): which show, in what order,
 * under what name. One shared setting for the page, as the board says, kept in the data folder as
 * list-columns.json — names and switches only, never anything secret.
 *
 * The Client column is always first and always shown. A role column follows the roles; left
 * alone ('auto') it shows only when a listed client has a server in that role, and ticking it
 * shows it anyway. A role added on the Roles page appears by itself; a role removed there drops
 * out of the setting the next time it is read.
 */
final class ListColumns {

	public const FILE = 'list-columns.json';

	/** Every column the page can draw, in its default order, with its default name. */
	public static function defaults(array $roles): array {
		$out = [['key' => 'cluster_host', 'label' => _('Cluster host (under Client)'), 'show' => true]];
		// One column per family (ES, Parser, Forwarder, Engine, Trino (ULM), SOAR), and AIML on its
		// own besides Engine, as the board draws them.
		foreach ($roles['families'] as $f) {
			$out[] = ['key' => 'family:'.$f['id'], 'label' => $f['label'], 'show' => null];
			foreach ($f['roles'] as $r) {
				if (self::ownColumn($f, $r)) {
					$out[] = ['key' => 'role:'.$r['id'], 'label' => $r['label'], 'show' => null];
				}
			}
		}
		foreach ([['templates', _('Templates')], ['password', _('Password')], ['last_seen', _('Last seen')], ['missing', _('Missing')]] as [$k, $l]) {
			$out[] = ['key' => $k, 'label' => $l, 'show' => true];
		}
		return $out;
	}

	/**
	 * A role drawn in a column of its own besides its family's: AIML in Engine, as the board has it
	 * — counted apart because a client buys it apart. The family column still counts it too.
	 */
	public static function ownColumn(array $family, array $role): bool {
		return $role['id'] === 'aiml';
	}

	/** The setting as stored, merged onto the defaults: unknown keys dropped, new ones added at the end of their kind. */
	public static function load(array $roles): array {
		$defaults = self::defaults($roles);
		$byKey = array_column($defaults, null, 'key');
		$saved = Store::read(self::FILE, []);
		$out = [];
		foreach (is_array($saved) ? $saved : [] as $c) {
			$k = (string) ($c['key'] ?? '');
			if (!isset($byKey[$k]) || isset($out[$k])) {
				continue;
			}
			$label = trim((string) ($c['label'] ?? ''));
			$out[$k] = ['key' => $k, 'label' => $label !== '' && mb_strlen($label) <= 48 ? $label : $byKey[$k]['label'],
				'show' => array_key_exists('show', $c) && $c['show'] !== null ? (bool) $c['show'] : $byKey[$k]['show']];
		}
		foreach ($defaults as $d) {
			if (!isset($out[$d['key']])) {
				$out[$d['key']] = $d;
			}
		}
		return array_values($out);
	}

	/**
	 * Keep what the Columns popover posted: [{key, show (true|false|null for "as the data says"),
	 * label}], in order. Anything that is not a known column is dropped.
	 */
	public static function save(array $roles, array $posted): array {
		$known = array_column(self::defaults($roles), 'key');
		$clean = [];
		foreach ($posted as $c) {
			$k = (string) ($c['key'] ?? '');
			if (!in_array($k, $known, true) || isset($clean[$k])) {
				continue;
			}
			$show = $c['show'] ?? null;
			$clean[$k] = ['key' => $k, 'label' => mb_substr(trim((string) ($c['label'] ?? '')), 0, 48),
				'show' => $show === null || $show === '' ? null : in_array((string) $show, ['1', 'true'], true)];
		}
		Store::write(self::FILE, array_values($clean));
		return self::load($roles);
	}

	public static function reset(): void {
		Store::delete(self::FILE);
	}

	/**
	 * Whether a column shows, given the clients listed: a role or family column left 'auto' only
	 * when one of them has a server in it, or a plan that asks for one ("0 of 1").
	 */
	public static function shows(array $col, array $clients): bool {
		if ($col['show'] !== null) {
			return (bool) $col['show'];
		}
		return self::count($col['key'], $clients) > 0 || self::requested($col['key'], $clients) > 0;
	}

	/** Servers the listed clients' plans ask for in a family or role column. */
	public static function requested(string $key, array $clients): int {
		$n = 0;
		foreach ($clients as $c) {
			$n += self::cell($key, $c)['requested'];
		}
		return $n;
	}

	/** Servers in a family or role column, across these clients. */
	public static function count(string $key, array $clients): int {
		$n = 0;
		foreach ($clients as $c) {
			$n += self::cell($key, $c)['n'];
		}
		return $n;
	}

	/** One client's figure for a family or role column: servers, requested, who, how many shared. */
	public static function cell(string $key, array $client): array {
		$out = ['n' => 0, 'requested' => 0, 'hosts' => [], 'shared' => 0];
		[$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
		foreach ($client['roles'] ?? [] as $rid => $r) {
			if (($kind === 'role' && $rid === $id) || ($kind === 'family' && ($r['family'] ?? null) === $id)) {
				$out['n'] += $r['n'];
				$out['requested'] += $r['requested'];
				$out['hosts'] = array_merge($out['hosts'], $r['hosts']);
				$out['shared'] += $r['shared'];
			}
		}
		$out['hosts'] = array_values(array_unique($out['hosts']));
		$out['n'] = $kind === 'family' ? count($out['hosts']) : $out['n'];
		return $out;
	}
}
