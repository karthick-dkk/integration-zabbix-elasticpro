<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use API;
use Exception;

/**
 * One Elasticsearch credential for every cluster, with a per-cluster override.
 *
 * The default lives in Zabbix as the GLOBAL macros {$ELASTICSEARCH.USERNAME} (text) and
 * {$ELASTICSEARCH.PASSWORD} (Secret text). A cluster host inherits them unless it defines its own
 * macro — its override ("This cluster only" on the client's page). Zabbix resolves host, then
 * template, then global, so a template linked to the host that defines either macro hides the
 * default too; the ElasticPro cluster template no longer does (ClusterTemplate, version 2), and a
 * template that does is named on the client's page instead of being silently in the way.
 *
 * The password follows the module's rules: typed once, never shown — the page says when and by
 * whom it was set (ClusterSecret::of('*')) — replaced only by "Change", untouched by every other
 * edit. Like a cluster's own, it is handed to the ElasticPro core too, as cluster "*".
 *
 * Writing a global macro is a Zabbix Super admin's right (usermacro.createglobal/updateglobal):
 * anyone else is told so before anything is written.
 */
final class DefaultCredential {

	public const USER_MACRO = '{$ELASTICSEARCH.USERNAME}';
	public const PASSWORD_MACRO = '{$ELASTICSEARCH.PASSWORD}';
	/** What the core and this module's record call the default. */
	public const KEY = '*';

	/** The global macros as they stand: the user's value, whether a password is kept, their ids. Never a secret value. */
	public static function current(): array {
		$out = ['user' => null, 'user_id' => null, 'password' => false, 'password_type' => null, 'password_id' => null];
		foreach (API::UserMacro()->get(['output' => ['globalmacroid', 'macro', 'value', 'type'], 'globalmacro' => true,
				'filter' => ['macro' => [self::USER_MACRO, self::PASSWORD_MACRO]]]) ?: [] as $m) {
			if ($m['macro'] === self::USER_MACRO) {
				$out['user'] = (string) ($m['value'] ?? '');
				$out['user_id'] = (string) $m['globalmacroid'];
			}
			else {
				$out['password'] = (int) $m['type'] === ZBX_MACRO_TYPE_SECRET || (string) ($m['value'] ?? '') !== '';
				$out['password_type'] = (int) $m['type'];
				$out['password_id'] = (string) $m['globalmacroid'];
			}
		}
		return $out;
	}

	/**
	 * Where a cluster host's credential comes from: 'override' (its own macro), 'template' (a
	 * linked template defines it, hiding the default), 'default' (the global one), or 'none'.
	 * Pure: the host as read with selectMacros and selectParentTemplates, the macros its linked
	 * templates define (templateid => [macro names]), and whether a default password exists.
	 */
	public static function sourceOf(array $host, array $templateMacros, bool $defaultSet): string {
		if (in_array(self::PASSWORD_MACRO, array_column($host['macros'] ?? [], 'macro'), true)) {
			return 'override';
		}
		foreach ($host['parentTemplates'] ?? [] as $t) {
			if (in_array(self::PASSWORD_MACRO, $templateMacros[(string) $t['templateid']] ?? [], true)) {
				return 'template';
			}
		}
		return $defaultSet ? 'default' : 'none';
	}

	/** Macro names each of these templates defines, for sourceOf(). */
	public static function templateMacros(array $templateids): array {
		$out = [];
		if ($templateids) {
			foreach (API::Template()->get(['output' => ['templateid'], 'templateids' => array_values(array_unique($templateids)),
					'selectMacros' => ['macro']]) ?: [] as $t) {
				$out[(string) $t['templateid']] = array_column($t['macros'] ?? [], 'macro');
			}
		}
		return $out;
	}

	/**
	 * "Used by N of M clusters · K override": every cluster host (a host carrying a cluster
	 * template, any generation), by where its credential comes from.
	 */
	public static function usage(): array {
		$tpl = API::Template()->get(['output' => ['templateid'], 'filter' => ['host' => Reconciler::clusterHostTemplates()]]) ?: [];
		$hosts = $tpl ? (API::Host()->get(['output' => ['hostid', 'host'], 'templateids' => array_column($tpl, 'templateid'),
			'selectMacros' => ['macro', 'type'], 'selectParentTemplates' => ['templateid', 'host']]) ?: []) : [];
		$tm = self::templateMacros(array_merge([], ...array_map(fn($h) => array_column($h['parentTemplates'] ?? [], 'templateid'), $hosts)));
		$set = self::current()['password'];
		$out = ['clusters' => count($hosts), 'default' => 0, 'override' => 0, 'template' => 0, 'none' => 0, 'hidden_by' => [], 'override_names' => []];
		foreach ($hosts as $h) {
			$src = self::sourceOf($h, $tm, $set);
			$out[$src]++;
			if ($src === 'template') {
				$out['hidden_by'][] = $h['host'];
			}
			if ($src === 'override') {
				$out['override_names'][] = $h['host'];
			}
		}
		sort($out['override_names'], SORT_NATURAL | SORT_FLAG_CASE);
		return $out;
	}

	/**
	 * Set the default. $password '' keeps the one Zabbix has (the keep-marker said so); a user
	 * of '' removes nothing — the user macro is written only when given.
	 *
	 * @return string[] what was done, for the page
	 */
	public static function save(string $user, string $password, string $by, array $deps = []): array {
		$now = self::current();
		$done = [];
		$user = trim($user);
		if ($user !== '' && $user !== $now['user']) {
			$r = $now['user_id'] === null
				? API::UserMacro()->createGlobal(['macro' => self::USER_MACRO, 'value' => $user, 'type' => ZBX_MACRO_TYPE_TEXT,
					'description' => 'Default Elasticsearch user for every cluster host. Set by ElasticPro Cluster Management → Settings.'])
				: API::UserMacro()->updateGlobal(['globalmacroid' => $now['user_id'], 'value' => $user]);
			self::check($r, _('write the default Elasticsearch user'));
			$done[] = _s('Default Elasticsearch user set to "%1$s".', $user);
		}
		if ($password !== '') {
			if (strlen($password) > ClusterSecret::MAX_PASSWORD) {
				throw new Exception(_('That password is longer than any Elasticsearch password; it was not saved.'));
			}
			$r = $now['password_id'] === null
				? API::UserMacro()->createGlobal(['macro' => self::PASSWORD_MACRO, 'value' => $password, 'type' => ZBX_MACRO_TYPE_SECRET,
					'description' => 'Default Elasticsearch password for every cluster host. Set by ElasticPro Cluster Management → Settings; never shown.'])
				: API::UserMacro()->updateGlobal(['globalmacroid' => $now['password_id'], 'value' => $password, 'type' => ZBX_MACRO_TYPE_SECRET]);
			self::check($r, _('write the default Elasticsearch password'));
			$core = ClusterSecret::send(self::KEY, $user !== '' ? $user : (string) ($now['user'] ?? 'elastic'), $password, $by, $deps);
			ClusterSecret::record(self::KEY, self::KEY, $by, true, $core, (int) (($deps['clock'] ?? 'time')()));
			$done[] = $core['ok'] ? _('Default Elasticsearch password kept as a Zabbix secret and handed to ElasticPro, which keeps it encrypted.')
				: _s('Default Elasticsearch password kept as a Zabbix secret. ElasticPro was not given it: %1$s', (string) $core['error']);
		}
		return $done;
	}

	private static function check($result, string $what): void {
		if ($result === false) {
			$said = function_exists('get_and_clear_messages') ? array_column(get_and_clear_messages(), 'message') : [];
			throw new Exception(_s('Zabbix would not %1$s: %2$s', $what, implode(' ', $said) ?: _('no reason given')));
		}
	}
}
