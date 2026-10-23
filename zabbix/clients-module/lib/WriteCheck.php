<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use API;

/**
 * Before a save by someone who is not a Zabbix Super admin: would Zabbix let them do every step
 * of it? Asked first, so a save their Zabbix rights do not cover is refused before anything has
 * changed — not half-written and then stopped by Zabbix at the first refusal.
 *
 * An ElasticPro admin may edit Cluster Management (EpRole), but the writes still go to Zabbix as
 * that person, and Zabbix keeps some steps for Super admins whatever their groups say:
 *  - creating a host group (hostgroup.create) or a template group (templategroup.create);
 *  - the cluster DL alert routing and weekly report: their user group and DL account
 *    (usergroup.create/update/delete, user.create/update/delete of another user).
 * Everything else needs write permission in Zabbix: on every host group a save puts hosts in, on
 * every host it changes, and on the template group the master template is imported into. A
 * template it links must be visible (read) to the user.
 *
 * What a save needs is a plain array, so the decision is pure and tested on its own:
 *   groups           host group name => true (the client's own group is flagged in client_group)
 *   client_group     the client's own host group name, for the wording, or null
 *   hosts            hostid => name, existing hosts the save changes or deletes
 *   routing          client names whose alert routing or weekly report a save would write
 *   template_groups  template group names the master template import writes into
 *   templates_hidden template names a save links that this user cannot see
 * and what the user may do:
 *   groups_seen, groups_editable, hosts_editable (ids), template_groups_seen,
 *   template_groups_editable
 */
final class WriteCheck {

	/** A needs array with nothing in it. */
	public static function none(): array {
		return ['groups' => [], 'client_group' => null, 'hosts' => [], 'routing' => [], 'template_groups' => [], 'templates_hidden' => []];
	}

	/** Two needs arrays as one (a restore or an import needs everything its clients need). */
	public static function merge(array $a, array $b): array {
		return [
			'groups' => ($a['groups'] ?? []) + ($b['groups'] ?? []),
			'client_group' => $a['client_group'] ?? $b['client_group'] ?? null,
			'hosts' => ($a['hosts'] ?? []) + ($b['hosts'] ?? []),
			'routing' => array_values(array_unique(array_merge($a['routing'] ?? [], $b['routing'] ?? []))),
			'template_groups' => ($a['template_groups'] ?? []) + ($b['template_groups'] ?? []),
			'templates_hidden' => array_values(array_unique(array_merge($a['templates_hidden'] ?? [], $b['templates_hidden'] ?? [])))
		];
	}

	/**
	 * Each step the user may not take, as a sentence naming it. Empty: go ahead. A Super admin
	 * has every right, so nothing is refused.
	 */
	public static function refusals(array $needs, array $rights, bool $super): array {
		if ($super) {
			return [];
		}
		$out = [];
		$seen = array_flip($rights['groups_seen'] ?? []);
		$editable = array_flip($rights['groups_editable'] ?? []);
		foreach (array_keys($needs['groups'] ?? []) as $name) {
			$name = (string) $name;
			if (!isset($seen[$name])) {
				// gstack-shortcut(dec-cf6faa2e-29c4-451b-a5f0-9db125b96d1f): refused, upgrade when the core has its own Zabbix write identity
				$out[] = $name === ($needs['client_group'] ?? null)
					? _s('Creating the host group "%1$s" for a new client needs a Zabbix Super admin. (Your Zabbix user cannot see a group of that name: it does not exist yet, or your user groups give no permission on it.)', $name)
					: _s('Creating the host group "%1$s" needs a Zabbix Super admin. (Your Zabbix user cannot see a group of that name: it does not exist yet, or your user groups give no permission on it.)', $name);
			}
			elseif (!isset($editable[$name])) {
				$out[] = _s('Putting hosts in host group "%1$s" needs write permission on it in Zabbix; your user groups give read only.', $name);
			}
		}
		$hosts_ok = array_flip(array_map('strval', $rights['hosts_editable'] ?? []));
		foreach ($needs['hosts'] ?? [] as $hostid => $name) {
			if (!isset($hosts_ok[(string) $hostid])) {
				$out[] = _s('Changing the host "%1$s" needs write permission on it in Zabbix; your user groups do not give it.', (string) $name);
			}
		}
		foreach ($needs['routing'] ?? [] as $client) {
			// gstack-shortcut(dec-cf6faa2e-29c4-451b-a5f0-9db125b96d1f): refused, upgrade when the core has its own Zabbix write identity
			$out[] = _s('Keeping the cluster DL alert routing or weekly report of "%1$s" in step writes its Zabbix user group and DL account, which needs a Zabbix Super admin. Ask one to make this change, or to turn those off for the client first.', (string) $client);
		}
		$tseen = array_flip($rights['template_groups_seen'] ?? []);
		$tedit = array_flip($rights['template_groups_editable'] ?? []);
		foreach (array_keys($needs['template_groups'] ?? []) as $name) {
			$name = (string) $name;
			if (!isset($tseen[$name])) {
				// gstack-shortcut(dec-cf6faa2e-29c4-451b-a5f0-9db125b96d1f): refused, upgrade when the core has its own Zabbix write identity
				$out[] = _s('Creating the template group "%1$s" for the master template needs a Zabbix Super admin. (Your Zabbix user cannot see a group of that name.)', $name);
			}
			elseif (!isset($tedit[$name])) {
				$out[] = _s('Writing the master template needs write permission on template group "%1$s" in Zabbix; your user groups give read only.', $name);
			}
		}
		if ($needs['templates_hidden'] ?? []) {
			$out[] = _s('These templates are not visible to your Zabbix user: %1$s. Linking them needs read permission on their template group — or they are not imported yet.',
				implode(', ', array_map(fn($t) => '"'.$t.'"', $needs['templates_hidden'])));
		}
		return $out;
	}

	/** The refusal as one message, saying first that nothing changed. */
	public static function message(array $refusals): string {
		return _('Nothing was changed: your Zabbix rights do not cover this save.').' '.implode(' ', $refusals);
	}

	/** What the signed-in user may do in Zabbix, for exactly the objects a save needs. */
	public static function rights(array $needs): array {
		$out = ['groups_seen' => [], 'groups_editable' => [], 'hosts_editable' => [], 'template_groups_seen' => [], 'template_groups_editable' => []];
		$groups = array_map('strval', array_keys($needs['groups'] ?? []));
		if ($groups) {
			$out['groups_seen'] = array_column(API::HostGroup()->get(['output' => ['name'], 'filter' => ['name' => $groups]]) ?: [], 'name');
			$out['groups_editable'] = array_column(API::HostGroup()->get(['output' => ['name'], 'filter' => ['name' => $groups], 'editable' => true]) ?: [], 'name');
		}
		$hostids = array_map('strval', array_keys($needs['hosts'] ?? []));
		if ($hostids) {
			$out['hosts_editable'] = array_column(API::Host()->get(['output' => ['hostid'], 'hostids' => $hostids, 'editable' => true]) ?: [], 'hostid');
		}
		$tgroups = array_map('strval', array_keys($needs['template_groups'] ?? []));
		if ($tgroups) {
			$out['template_groups_seen'] = array_column(API::TemplateGroup()->get(['output' => ['name'], 'filter' => ['name' => $tgroups]]) ?: [], 'name');
			$out['template_groups_editable'] = array_column(API::TemplateGroup()->get(['output' => ['name'], 'filter' => ['name' => $tgroups], 'editable' => true]) ?: [], 'name');
		}
		return $out;
	}

	/**
	 * What saving this client needs: the host groups Reconciler::apply() would put hosts in (and
	 * create when missing), the client's hosts it would change, templates it would link that this
	 * user cannot see, and whether alert routing would be written — Base::noteChange() syncs it
	 * after every save with the master host's macros as the save leaves them.
	 */
	public static function forSave(Reconciler $rec, ClientSpec $spec, array $client, array $now): array {
		$needs = self::none();
		$needs['client_group'] = $client['name'];
		$needs['groups'] = array_fill_keys($rec->groupsFor($client), true);
		$needs['hosts'] = self::hostsOf($now);
		$needs['templates_hidden'] = $rec->missingTemplates($client);
		$before = ($now['master'] ?? null) !== null ? $rec->macros((string) $now['master']['hostid']) : [];
		$after = array_merge($before, array_map(fn($v) => (string) $v[0], $spec->masterMacros($client)));
		// After a save the client's host group exists (apply() makes it), so sync() is not skipped for want of one.
		if (AlertRouting::needsAccounts($client['name'], true, $before, $after)) {
			$needs['routing'] = [$client['name']];
		}
		return $needs;
	}

	/**
	 * What a change that leaves the hosts' groups alone needs — a status change, a removal, a put
	 * back: write on these hosts, and the alert routing when noteChange() would sync it.
	 *
	 * @param array|null $after  the master host's macros afterwards; null when the change does not
	 *                           call noteChange() with the client still there (removal, release).
	 */
	public static function forHosts(string $client, array $hosts, ?string $groupid, array $before, ?array $after): array {
		$needs = self::none();
		$needs['hosts'] = $hosts;
		if ($after !== null && AlertRouting::needsAccounts($client, $groupid !== null, $before, $after)) {
			$needs['routing'] = [$client];
		}
		return $needs;
	}

	/** Every host of a client as Reconciler::current() found it, hostid => name. */
	public static function hostsOf(array $now): array {
		$out = [];
		foreach (array_merge(array_filter([$now['master'] ?? null, $now['cluster'] ?? null, $now['ulm'] ?? null]), array_values($now['machines'] ?? [])) as $h) {
			$out[(string) $h['hostid']] = (string) ($h['name'] ?? $h['host'] ?? $h['hostid']);
		}
		return $out;
	}
}
