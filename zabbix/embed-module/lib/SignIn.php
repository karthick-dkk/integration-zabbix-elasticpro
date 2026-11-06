<?php declare(strict_types = 1);

namespace Modules\ElasticPro\Lib;

require_once __DIR__.'/SsoClient.php';

use CWebUser;

/**
 * The one sign-in of the Zabbix user looking at the page: what Zabbix vouches for (user type,
 * groups, role), the one-time code ElasticPro trades for it, and the frame address that carries
 * it. Every page that opens ElasticPro goes through here: the ElasticPro pages (View), the Play
 * page among them, and the "ElasticPro Play" dashboard widget, which asks PlayFrame for a fresh
 * address each time it loads its frame.
 *
 * A code is minted per load. Codes are single-use and live sixty seconds, so an address that
 * leaks is worth nothing a minute later, and nothing long-lived is ever written into a page.
 */
final class SignIn {

	/**
	 * May this Zabbix user be signed in to ElasticPro at all? A Zabbix User or above, and never
	 * the guest user: Zabbix gives guest a role of the User type, and with guest access to the
	 * frontend a guest-visible page would otherwise sign `guest@zabbix` in. Guests have no
	 * ElasticPro role. The one rule for the pages (View), PlayFrame and the menu.
	 */
	public static function mayOpen(): bool {
		return (int) CWebUser::getType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
	}

	/**
	 * What a refused sign-in may say to this user. A Super admin gets SsoClient's reason as it
	 * is — it can name the core's internal address ("could not reach ElasticPro at
	 * http://ep-core:8765: …"), which is theirs to see. Anyone else gets only ElasticPro's own
	 * error codes found in it (EP-nnn), or nothing.
	 */
	public static function reason(string $error, bool $super_admin): string {
		if ($super_admin) {
			return $error;
		}
		preg_match_all('/\bEP-\d{3}\b/', $error, $m);
		return implode(' ', array_values(array_unique($m[0])));
	}

	/**
	 * The user groups this user is in, each as `['id' => usrgrpid, 'name' => name]`, sorted by
	 * name. What ElasticPro's role map decides on (ElasticPro 0.3.55: each ElasticPro role holds
	 * Zabbix user groups; the id survives a rename in Zabbix).
	 *
	 * Read from the database rather than through the API: `usergroup.get` answers
	 * differently by user type, and this list decides which clusters a Zabbix User sees and
	 * what they may do in ElasticPro, so it must not depend on who is asking.
	 */
	public static function userGroups(): array {
		$groups = [];
		$rows = DBselect(
			'SELECT g.usrgrpid,g.name FROM usrgrp g JOIN users_groups ug ON ug.usrgrpid=g.usrgrpid'.
			' WHERE ug.userid='.zbx_dbstr(CWebUser::$data['userid'])
		);
		while ($row = DBfetch($rows)) {
			$groups[] = ['id' => (string) $row['usrgrpid'], 'name' => (string) $row['name']];
		}
		usort($groups, fn(array $a, array $b) => strcmp($a['name'], $b['name']));
		return $groups;
	}

	/** The names alone, from the same list — the sign-in scope, and what an older core reads. */
	public static function groups(): array {
		return array_map(fn(array $g) => $g['name'], self::userGroups());
	}

	/**
	 * The name of this user's Zabbix role ("Admin role", or one the site made). Read from the
	 * database like the groups, for the same reason: it decides what the person becomes in
	 * ElasticPro (its role map), so it must not depend on who is asking.
	 */
	public static function roleName(): ?string {
		$roleid = CWebUser::$data['roleid'] ?? null;
		if ($roleid === null || (string) $roleid === '' || (string) $roleid === '0') {
			return null;
		}
		$row = DBfetch(DBselect('SELECT r.name FROM role r WHERE r.roleid='.zbx_dbstr($roleid)));
		$name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
		return $name === '' ? null : $name;
	}

	public static function theme(): string {
		return function_exists('getUserTheme')
			? (string) getUserTheme(CWebUser::$data)
			: (string) (CWebUser::$data['theme'] ?? '');
	}

	/**
	 * Sign this Zabbix user in to ElasticPro and say where the frame goes.
	 *
	 * @param string $page   ElasticPro's page id (`overview`, `play`, …): the frame opens `#/<page>`.
	 * @param array  $extra  The troubleshoot request, or empty.
	 *
	 * @return array{configured: bool, url: ?string, error: string, public_url: string}
	 *         `url` is null when the module is not paired (`configured` false) or ElasticPro
	 *         refused the sign-in (`error` says why, with the core's own code).
	 */
	public static function frame(string $page, array $extra = [], ?SsoClient $sso = null): array {
		$sso = $sso ?? new SsoClient();
		$out = ['configured' => $sso->isConfigured(), 'url' => null, 'error' => '', 'public_url' => $sso->publicUrl()];
		if (!$out['configured']) {
			return $out;
		}
		$groups = self::userGroups();
		$code = $sso->mintCode((string) CWebUser::$data['username'], (int) CWebUser::getType(),
			array_map(fn(array $g) => $g['name'], $groups), self::roleName(), (int) (CWebUser::$data['roleid'] ?? 0) ?: null, $groups);
		if ($code === null) {
			$out['error'] = $sso->lastError();
			return $out;
		}
		$out['url'] = $sso->frameUrl($code, $page, self::theme(), $extra);
		return $out;
	}

	/**
	 * The origin (scheme://host[:port]) of ElasticPro's public address: the only sender whose
	 * "sign me in again" message a page acts on. '' when the address has no host.
	 */
	public static function origin(string $public_url): string {
		if ($public_url === '') {
			return '';
		}
		$parts = parse_url($public_url);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			return '';
		}
		return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
	}
}
