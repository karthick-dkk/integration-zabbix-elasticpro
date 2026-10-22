<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use APP;
use CSessionHelper;
use Modules\ElasticPro\Lib\Settings as EmbedSettings;
use Modules\ElasticPro\Lib\SsoClient;

/**
 * Who may edit Cluster Management: a Zabbix Super admin, or someone ElasticPro calls an admin.
 *
 * ElasticPro's role is the authority (decision cf6faa2e, 2026-10-22). A Zabbix Admin whose
 * ElasticPro account an ElasticPro administrator made an admin edits here; a Zabbix Admin whom
 * ElasticPro calls an operator reads. Zabbix still decides what that person may write in Zabbix —
 * the pre-check (WriteCheck) refuses a save their Zabbix rights do not cover, before anything
 * changes.
 *
 * The question goes to the ElasticPro core server to server, the way the ElasticPro module's
 * sign-in does, under the same pairing secret:
 *
 *   GET {core_url}/sso/zabbix/role?claim=<JSON, URL-encoded>
 *   X-Zabbix-Module-Signature: <hex HMAC-SHA256 of the exact JSON bytes>
 *
 * where the JSON is {"zabbix_user", "zabbix_user_type", "ts"}. The core checks the signature and
 * the clock (±60 s) and answers {role, pinned, source, disabled, locked} for <user>@zabbix, or
 * role null when it has never seen that person sign in — then the Zabbix mapping applies (a
 * Zabbix Admin is an operator there), which is never an admin. Nothing is created by asking.
 *
 * The pairing and the signing are the ElasticPro module's (modules/elasticpro, id "elasticpro"):
 * its Settings::resolve() and SsoClient::sign(), loaded from that module's folder, so there is one
 * implementation of the signed call. When that module is not enabled, not paired, or the core does
 * not answer, nobody but a Super admin edits, and the page says so rather than going quiet.
 *
 * Answers are kept in the Zabbix session for TTL seconds per user. A page of this module asks
 * again once the answer is older than that; the sidebar on every other Zabbix page asks only when
 * there is no answer at all, and otherwise uses the last one, so ordinary browsing costs one
 * request per session — the page itself always decides on a fresh answer.
 */
final class EpRole {

	public const TTL = 60;
	public const SESSION_KEY = 'ep_clients_ep_role';
	public const EMBED_MODULE_ID = 'elasticpro';
	public const PATH = '/sso/zabbix/role';

	/** What the page says when the core could not be asked. */
	public const UNASKED = 'ElasticPro could not be asked who may edit; only Super admins can edit until it answers.';

	/** @var callable(): array  {core_url, secret, verify_tls} or {error} */
	private $settings;
	/** @var callable(string $url, array $headers, bool $verify_tls): array{status: int, data: ?array, error: string} */
	private $transport;
	/** @var callable(string $secret, string $body): string */
	private $sign;
	/** @var callable(): int */
	private $clock;
	/** @var callable(): ?array */
	private $load;
	/** @var callable(array): void */
	private $save;

	/** @var array answers already decided in this request, user|type => answer */
	private $memo = [];

	private static $current = null;

	/**
	 * Every dependency can be replaced, so the tests can answer for the core, the clock and the
	 * session. Keys: settings, transport, sign, clock, load, save.
	 */
	public function __construct(array $deps = []) {
		$this->settings = $deps['settings'] ?? [self::class, 'embedSettings'];
		$this->transport = $deps['transport'] ?? [self::class, 'curlGet'];
		$this->sign = $deps['sign'] ?? [SsoClient::class, 'sign'];
		$this->clock = $deps['clock'] ?? 'time';
		$this->load = $deps['load'] ?? [self::class, 'sessionLoad'];
		$this->save = $deps['save'] ?? [self::class, 'sessionSave'];
	}

	/** The one this request uses. */
	public static function current(): self {
		return self::$current ?? (self::$current = new self());
	}

	/** Replace it — the tests do; null goes back to the real one. */
	public static function setCurrent(?self $role): void {
		self::$current = $role;
	}

	/** The ElasticPro role Zabbix's mapping gives a user type: what a first sign-in would make. */
	public static function zabbixRole(int $type): ?string {
		return [1 => 'user', 2 => 'operator', 3 => 'admin'][$type] ?? null;
	}

	/** The exact bytes that are signed and sent. */
	public static function claim(string $user, int $type, int $ts): string {
		return (string) json_encode(['zabbix_user' => $user, 'zabbix_user_type' => $type, 'ts' => $ts],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * The request for one question: where it goes, what is signed, and the header carrying the
	 * signature. The signature is over the JSON itself, not over the URL that carries it.
	 *
	 * @return array{url: string, json: string, headers: string[]}
	 */
	public static function request(string $core_url, string $secret, string $user, int $type, int $ts, callable $sign): array {
		$json = self::claim($user, $type, $ts);
		return [
			'url' => rtrim($core_url, '/').self::PATH.'?claim='.rawurlencode($json),
			'json' => $json,
			'headers' => ['Accept: application/json', 'X-Zabbix-Module-Signature: '.$sign($secret, $json)]
		];
	}

	/**
	 * What ElasticPro says about this Zabbix user, from the session when the answer is young
	 * enough (any age when $fresh is false), otherwise asked now and kept.
	 *
	 * @return array{asked: bool, role: ?string, pinned: bool, source: ?string, disabled: bool, locked: bool, error: ?string, at: int}
	 */
	public function answer(string $user, int $type, bool $fresh = true): array {
		$key = $user."\n".$type;
		if (isset($this->memo[$key])) {
			return $this->memo[$key];
		}
		$now = (int) ($this->clock)();
		$kept = ($this->load)();
		if (is_array($kept) && ($kept['user'] ?? null) === $user && (int) ($kept['type'] ?? -1) === $type
				&& is_array($kept['answer'] ?? null) && (!$fresh || $now - (int) ($kept['answer']['at'] ?? 0) < self::TTL)) {
			return $this->memo[$key] = $kept['answer'];
		}
		$answer = $this->ask($user, $type, $now);
		($this->save)(['user' => $user, 'type' => $type, 'answer' => $answer]);
		return $this->memo[$key] = $answer;
	}

	/** Ask the core now. Every failure is an answer with asked false and the reason. */
	private function ask(string $user, int $type, int $now): array {
		$out = ['asked' => false, 'role' => null, 'pinned' => false, 'source' => null, 'disabled' => false,
			'locked' => false, 'error' => null, 'at' => $now];
		$s = ($this->settings)();
		if (isset($s['error'])) {
			$out['error'] = (string) $s['error'];
			return $out;
		}
		$req = self::request((string) $s['core_url'], (string) $s['secret'], $user, $type, $now, $this->sign);
		$r = ($this->transport)($req['url'], $req['headers'], (bool) ($s['verify_tls'] ?? true));
		$data = is_array($r['data'] ?? null) ? $r['data'] : null;
		if ((int) ($r['status'] ?? 0) === 0) {
			$out['error'] = 'ElasticPro did not answer at '.rtrim((string) $s['core_url'], '/').': '.((string) ($r['error'] ?? '') ?: 'no answer');
			return $out;
		}
		if ((int) $r['status'] !== 200 || $data === null || ($data['ok'] ?? false) !== true) {
			$out['error'] = $data !== null && isset($data['message'])
				? 'ElasticPro refused the question (HTTP '.(int) $r['status'].'): '.(string) $data['message']
				: 'ElasticPro answered HTTP '.(int) $r['status'];
			return $out;
		}
		$role = $data['role'] ?? null;
		$out['asked'] = true;
		$out['role'] = is_string($role) && in_array($role, ['admin', 'operator', 'user', 'guest'], true) ? $role : null;
		$out['pinned'] = ($data['pinned'] ?? false) === true;
		$out['source'] = is_string($data['source'] ?? null) ? $data['source'] : null;
		$out['disabled'] = ($data['disabled'] ?? false) === true;
		$out['locked'] = ($data['locked'] ?? false) === true;
		return $out;
	}

	/**
	 * The decision, in one place: the actions' canWrite(), checkPermissions(), the sidebar and the
	 * read-only notes all come from here.
	 *
	 * @param array|null $answer  answer() for this user; ignored for a Super admin.
	 * @return array{write: bool, why: string, role: ?string, note: ?string, hint: ?string}
	 *   why: super | elasticpro_admin | elasticpro_role | unasked | no_type
	 */
	public static function decide(int $type, ?array $answer): array {
		if ($type == USER_TYPE_SUPER_ADMIN) {
			return ['write' => true, 'why' => 'super', 'role' => null, 'note' => null, 'hint' => null];
		}
		if (!in_array($type, [USER_TYPE_ZABBIX_ADMIN, USER_TYPE_ZABBIX_USER], true)) {
			return ['write' => false, 'why' => 'no_type', 'role' => null,
				'note' => _('Editing needs an ElasticPro admin role (or Zabbix Super admin).'), 'hint' => null];
		}
		if ($answer === null || !($answer['asked'] ?? false)) {
			return ['write' => false, 'why' => 'unasked', 'role' => null, 'note' => _(self::UNASKED),
				'hint' => $answer !== null && ($answer['error'] ?? null) !== null ? (string) $answer['error'] : null];
		}
		$role = $answer['role'];
		$mapped = $role === null;
		$role = $role ?? self::zabbixRole($type);
		$blocked = ($answer['disabled'] ?? false) || ($answer['locked'] ?? false);
		if ($role === 'admin' && !$blocked) {
			return ['write' => true, 'why' => 'elasticpro_admin', 'role' => 'admin', 'note' => null, 'hint' => null];
		}
		$said = $mapped
			? _s('Your ElasticPro role: %1$s (from your Zabbix user type — ElasticPro has not seen you sign in yet).', (string) $role)
			: ($blocked
				? _s('Your ElasticPro role: %1$s, but the account is %2$s in ElasticPro.', (string) $role,
					($answer['disabled'] ?? false) ? _('blocked') : _('locked'))
				: _s('Your ElasticPro role: %1$s.', (string) $role));
		return ['write' => false, 'why' => 'elasticpro_role', 'role' => $role,
			'note' => _('Editing needs an ElasticPro admin role (or Zabbix Super admin).').' '.$said, 'hint' => null];
	}

	/** decide() for this user, asking (or reading the session) only when the type needs it. */
	public function decision(string $user, int $type, bool $fresh = true): array {
		$ask = in_array($type, [USER_TYPE_ZABBIX_ADMIN, USER_TYPE_ZABBIX_USER], true);
		return self::decide($type, $ask ? $this->answer($user, $type, $fresh) : null);
	}

	/**
	 * What a read-only page says, from Base::writeNote(): the sentence (who may edit, and this
	 * person's ElasticPro role, or that ElasticPro could not be asked), then the reason it could
	 * not be asked when there is one. The views all draw it from here.
	 */
	public static function noteLines(?array $write_note): array {
		[$note, $hint] = ($write_note ?? []) + [null, null];
		$out = [$note ?? _('Read only. Editing needs an ElasticPro admin role (or Zabbix Super admin).')];
		if ($hint !== null && $hint !== '') {
			$out[] = _s('Why: %1$s.', rtrim((string) $hint, '.'));
		}
		return $out;
	}

	/* ---------------------------- the real dependencies ---------------------------- */

	/** The ElasticPro module's pairing, or why there is none. Never the secret in an error. */
	public static function embedSettings(): array {
		if (!self::loadEmbed()) {
			return ['error' => _s('the ElasticPro module (%1$s) is not enabled in Zabbix, so there is no pairing to ask with', self::EMBED_MODULE_ID)];
		}
		$s = EmbedSettings::resolve();
		if (trim((string) $s['core_url']) === '' || strlen(trim((string) $s['secret'])) < 32) {
			return ['error' => !empty($s['secret_unreadable'])
				? _('the stored pairing secret no longer opens — pair again from ElasticPro')
				: _('Zabbix is not paired with ElasticPro (Administration → ElasticPro)')];
		}
		return ['core_url' => (string) $s['core_url'], 'secret' => (string) $s['secret'], 'verify_tls' => (bool) $s['verify_tls']];
	}

	/**
	 * Make the ElasticPro module's Settings and SsoClient available: Zabbix's autoloader has them
	 * when that module is enabled; otherwise they are required from its folder, found through the
	 * module manager (getDir() on Zabbix 7.0, the root plus getRelativePath() on 8.0).
	 */
	private static function loadEmbed(): bool {
		if (class_exists(SsoClient::class) && class_exists(EmbedSettings::class)) {
			return true;
		}
		if (!class_exists('APP', false)) {
			return false;
		}
		$module = APP::ModuleManager()->getModule(self::EMBED_MODULE_ID);
		if ($module === null) {
			return false;
		}
		$dir = method_exists($module, 'getDir') ? $module->getDir()
			: (method_exists($module, 'getRelativePath') ? APP::getRootDir().'/'.$module->getRelativePath() : null);
		if ($dir === null || !is_file($dir.'/lib/SsoClient.php') || !is_file($dir.'/lib/Settings.php')) {
			return false;
		}
		require_once $dir.'/lib/Settings.php';
		require_once $dir.'/lib/SsoClient.php';
		return class_exists(SsoClient::class, false) && class_exists(EmbedSettings::class, false);
	}

	/** A signed GET. Short timeouts: a page waits on this. */
	public static function curlGet(string $url, array $headers, bool $verify_tls): array {
		if (!function_exists('curl_init') || ($ch = curl_init($url)) === false) {
			return ['status' => 0, 'data' => null, 'error' => 'curl is not available'];
		}
		curl_setopt_array($ch, [
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT => 3,
			CURLOPT_SSL_VERIFYPEER => $verify_tls,
			CURLOPT_SSL_VERIFYHOST => $verify_tls ? 2 : 0
		]);
		$raw = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		if ($raw === false) {
			return ['status' => 0, 'data' => null, 'error' => $error !== '' ? $error : 'no answer'];
		}
		$data = json_decode((string) $raw, true);
		return ['status' => $status, 'data' => is_array($data) ? $data : null, 'error' => ''];
	}

	public static function sessionLoad(): ?array {
		if (class_exists('CSessionHelper')) {
			$v = CSessionHelper::get(self::SESSION_KEY);
			return is_array($v) ? $v : null;
		}
		return isset($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY]) ? $_SESSION[self::SESSION_KEY] : null;
	}

	public static function sessionSave(array $kept): void {
		if (class_exists('CSessionHelper')) {
			CSessionHelper::set(self::SESSION_KEY, $kept);
			return;
		}
		$_SESSION[self::SESSION_KEY] = $kept;
	}
}
