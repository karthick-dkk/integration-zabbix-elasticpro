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
 * where the JSON is {"zabbix_user", "zabbix_user_type", "ts", "nonce"} (nonce: 32 hex characters,
 * fresh for every question). The core checks the signature, the clock (±60 s) and that the nonce
 * is new, and answers {role, pinned, source, disabled, locked, nonce, ts} for <user>@zabbix, or
 * role null when it has never seen that person sign in — then the Zabbix mapping applies (a
 * Zabbix Admin is an operator there), which is never an admin. Nothing is created by asking.
 *
 * The answer is believed only when ElasticPro signed it: the core sends
 *   X-ElasticPro-Signature: <hex HMAC-SHA256 of ts + "\n" + nonce + "\n" + the exact body bytes>
 * with this question's ts and nonce, under the same secret, and the body echoes both. A missing or
 * wrong signature, an echo of another question, or a nonce this session has already consumed is
 * no answer at all. The signature is what authenticates the answer: somebody on the path can
 * block it (which leaves only Super admins editing) but cannot forge or replay it. On top of that,
 * an "admin" is acted on only over https with certificate verification, a loopback core, or plain
 * http to a host named in the ElasticPro module's `trusted_plain_hosts` — exact host names only,
 * default ['ep-core'], the core's name on the documented compose network. https stays the
 * recommendation; any other plain-http host is refused with UNTRUSTED.
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
	/** What the page says when ElasticPro answered "admin" over a connection elevation does not trust. */
	public const UNTRUSTED = 'ElasticPro\'s answer cannot be trusted over this connection; only Super admins can edit until it can.';

	/** @var callable(): array  {core_url, secret, verify_tls, trusted_plain_hosts} or {error} */
	private $settings;
	/** @var callable(string $url, array $headers, bool $verify_tls): array{status: int, data: ?array, error: string} */
	private $transport;
	/** @var callable(string $secret, string $body): string */
	private $sign;
	/** @var callable(): int */
	private $clock;
	/** @var callable(): string a fresh nonce for each question */
	private $nonce;
	/** @var callable(): ?array */
	private $load;
	/** @var callable(array): void */
	private $save;
	/** @var callable(string): void  the server log (error_log), where the detailed reasons go */
	private $log;

	/** @var array answers already decided in this request, user|type => answer */
	private $memo = [];

	private static $current = null;

	/**
	 * Every dependency can be replaced, so the tests can answer for the core, the clock and the
	 * session. Keys: settings, transport, sign, clock, nonce, load, save, log.
	 */
	public function __construct(array $deps = []) {
		$this->settings = $deps['settings'] ?? [self::class, 'embedSettings'];
		$this->transport = $deps['transport'] ?? [self::class, 'curlGet'];
		$this->sign = $deps['sign'] ?? [SsoClient::class, 'sign'];
		$this->clock = $deps['clock'] ?? 'time';
		$this->nonce = $deps['nonce'] ?? fn() => bin2hex(random_bytes(16));
		$this->load = $deps['load'] ?? [self::class, 'sessionLoad'];
		$this->save = $deps['save'] ?? [self::class, 'sessionSave'];
		$this->log = $deps['log'] ?? fn(string $line) => error_log($line);
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

	/** How many consumed nonces a session remembers; each is checked once, so a few suffice. */
	public const NONCES_KEPT = 64;

	/** The reasons a lookup is not acted on, as the page shows them. */
	public const NOT_SIGNED = 'the answer was not signed by ElasticPro';
	public const REPLAYED = 'replayed answer';
	public const INSECURE = 'core_url is plain HTTP / TLS verification is off; elevation needs https with verification, a loopback core, or a host listed in trusted_plain_hosts';
	/** Plain-http hosts trusted when the ElasticPro module does not say (older module, or nothing stored). */
	public const DEFAULT_PLAIN_HOSTS = ['ep-core'];

	/** The exact bytes that are signed and sent. */
	public static function claim(string $user, int $type, int $ts, string $nonce): string {
		return (string) json_encode(['zabbix_user' => $user, 'zabbix_user_type' => $type, 'ts' => $ts, 'nonce' => $nonce],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * The request for one question: where it goes, what is signed, and the header carrying the
	 * signature. The signature is over the JSON itself, not over the URL that carries it.
	 *
	 * @return array{url: string, json: string, headers: string[]}
	 */
	public static function request(string $core_url, string $secret, string $user, int $type, int $ts, string $nonce, callable $sign): array {
		$json = self::claim($user, $type, $ts, $nonce);
		return [
			'url' => rtrim($core_url, '/').self::PATH.'?claim='.rawurlencode($json),
			'json' => $json,
			'headers' => ['Accept: application/json', 'X-Zabbix-Module-Signature: '.$sign($secret, $json)]
		];
	}

	/** What ElasticPro's answer to the question ($ts, $nonce) must be signed with: over ts, nonce and the exact body. */
	public static function answerSignature(string $secret, int $ts, string $nonce, string $raw, callable $sign): string {
		return $sign($secret, $ts."\n".$nonce."\n".$raw);
	}

	/**
	 * Whether a signed "admin" from this core may be acted on: https with certificate verification;
	 * a core on this machine (127.0.0.0/8, ::1, localhost), where nothing travels a network; or plain
	 * http to a host named exactly in $plain_hosts (the internal compose network's core). Only host
	 * names match there — a core_url naming an IP never does, and a wildcard in the list matches
	 * only itself, which no URL has — and only the http scheme: https without verification is not
	 * rescued by being listed.
	 *
	 * @param string[] $plain_hosts
	 */
	public static function transportTrusted(string $core_url, bool $verify_tls, array $plain_hosts = []): bool {
		$scheme = strtolower((string) parse_url($core_url, PHP_URL_SCHEME));
		$host = strtolower(trim((string) parse_url($core_url, PHP_URL_HOST), '[]'));
		if ($host === 'localhost' || $host === '::1' || preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host)) {
			return true;
		}
		if ($scheme === 'https') {
			return $verify_tls;
		}
		// The list was cleaned when it was stored (the ElasticPro module's Settings::plainHosts(), the
		// one definition of what may be listed); here it is only matched, exactly, and never against an
		// address — a core_url naming an IP is not on the internal network by name.
		if ($scheme !== 'http' || $host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return false;
		}
		return in_array($host, array_map(fn($h) => strtolower(trim((string) $h)), $plain_hosts), true);
	}

	/**
	 * What ElasticPro says about this Zabbix user, from the session when the answer is young
	 * enough (any age when $fresh is false), otherwise asked now and kept.
	 *
	 * @return array{asked: bool, role: ?string, pinned: bool, source: ?string, disabled: bool, locked: bool,
	 *               trusted: bool, error: ?string, at: int}
	 */
	public function answer(string $user, int $type, bool $fresh = true): array {
		$key = $user."\n".$type;
		if (isset($this->memo[$key])) {
			return $this->memo[$key];
		}
		$now = (int) ($this->clock)();
		$kept = ($this->load)();
		$kept = is_array($kept) ? $kept : [];
		if (($kept['user'] ?? null) === $user && (int) ($kept['type'] ?? -1) === $type
				&& is_array($kept['answer'] ?? null) && (!$fresh || $now - (int) ($kept['answer']['at'] ?? 0) < self::TTL)) {
			return $this->memo[$key] = $kept['answer'];
		}
		// The consumed nonces outlive a change of user: they are this session's, not one answer's.
		$nonces = is_array($kept['nonces'] ?? null) ? $kept['nonces'] : [];
		$answer = $this->ask($user, $type, $now, $nonces);
		// The detailed reason (core_url, the curl error, the core's own message) goes to the server
		// log; the page shows a person who is not a Super admin only the fixed line (decide()).
		if (($answer['error'] ?? null) !== null) {
			($this->log)('ElasticPro Cluster Management: the role lookup for "'.$user.'" was not acted on: '.$answer['error']);
		}
		($this->save)(['user' => $user, 'type' => $type, 'answer' => $answer,
			'nonces' => array_slice($nonces, -self::NONCES_KEPT)]);
		return $this->memo[$key] = $answer;
	}

	/**
	 * Ask the core now. Every failure is an answer with asked false and the reason; an answer
	 * over a connection elevation may not trust is asked true, trusted false.
	 *
	 * @param string[] $nonces  the nonces this session has consumed; the one used here is added
	 */
	private function ask(string $user, int $type, int $now, array &$nonces): array {
		$out = ['asked' => false, 'role' => null, 'pinned' => false, 'source' => null, 'disabled' => false,
			'locked' => false, 'trusted' => false, 'error' => null, 'at' => $now];
		$s = ($this->settings)();
		if (isset($s['error'])) {
			$out['error'] = (string) $s['error'];
			return $out;
		}
		$secret = (string) $s['secret'];
		$nonce = (string) ($this->nonce)();
		$req = self::request((string) $s['core_url'], $secret, $user, $type, $now, $nonce, $this->sign);
		$r = ($this->transport)($req['url'], $req['headers'], (bool) ($s['verify_tls'] ?? true));
		if ((int) ($r['status'] ?? 0) === 0) {
			$out['error'] = 'ElasticPro did not answer at '.rtrim((string) $s['core_url'], '/').': '.((string) ($r['error'] ?? '') ?: 'no answer');
			return $out;
		}
		$raw = (string) ($r['raw'] ?? '');
		$data = json_decode($raw, true);
		$data = is_array($data) ? $data : null;
		if ((int) $r['status'] !== 200 || $data === null || ($data['ok'] ?? false) !== true) {
			$out['error'] = $data !== null && isset($data['message'])
				? 'ElasticPro refused the question (HTTP '.(int) $r['status'].'): '.(string) $data['message']
				: 'ElasticPro answered HTTP '.(int) $r['status'];
			return $out;
		}
		$got = strtolower(trim((string) ($r['signature'] ?? '')));
		if ($got === '' || !hash_equals(self::answerSignature($secret, $now, $nonce, $raw, $this->sign), $got)) {
			$out['error'] = self::NOT_SIGNED;
			return $out;
		}
		if (($data['nonce'] ?? null) !== $nonce || ($data['ts'] ?? null) !== $now || in_array($nonce, $nonces, true)) {
			$out['error'] = self::REPLAYED;
			return $out;
		}
		$nonces[] = $nonce;
		$role = $data['role'] ?? null;
		$out['asked'] = true;
		$out['role'] = is_string($role) && in_array($role, ['admin', 'operator', 'user', 'guest'], true) ? $role : null;
		$out['pinned'] = ($data['pinned'] ?? false) === true;
		$out['source'] = is_string($data['source'] ?? null) ? $data['source'] : null;
		$out['disabled'] = ($data['disabled'] ?? false) === true;
		$out['locked'] = ($data['locked'] ?? false) === true;
		$out['trusted'] = self::transportTrusted((string) $s['core_url'], (bool) ($s['verify_tls'] ?? true),
			(array) ($s['trusted_plain_hosts'] ?? self::DEFAULT_PLAIN_HOSTS));
		if (!$out['trusted']) {
			$out['error'] = self::INSECURE;
		}
		return $out;
	}

	/**
	 * The decision, in one place: the actions' canWrite(), checkPermissions(), the sidebar and the
	 * read-only notes all come from here.
	 *
	 * @param array|null $answer  answer() for this user; ignored for a Super admin.
	 * @return array{write: bool, why: string, role: ?string, note: ?string, hint: ?string, detail: ?string}
	 *   why: super | elasticpro_admin | elasticpro_role | untrusted | unasked | no_type
	 *
	 * When ElasticPro could not be asked, or its answer cannot be trusted, the person (never a
	 * Super admin: they are not asked) sees only the fixed UNASKED / UNTRUSTED line and no hint:
	 * the reason names core_url, a curl error or the core's own message, which is the server's
	 * business, not theirs. The reason stays in `detail` and in the server log (answer()).
	 */
	public static function decide(int $type, ?array $answer): array {
		if ($type == USER_TYPE_SUPER_ADMIN) {
			return ['write' => true, 'why' => 'super', 'role' => null, 'note' => null, 'hint' => null, 'detail' => null];
		}
		if (!in_array($type, [USER_TYPE_ZABBIX_ADMIN, USER_TYPE_ZABBIX_USER], true)) {
			return ['write' => false, 'why' => 'no_type', 'role' => null,
				'note' => _('Editing needs an ElasticPro admin role (or Zabbix Super admin).'), 'hint' => null, 'detail' => null];
		}
		if ($answer === null || !($answer['asked'] ?? false)) {
			return ['write' => false, 'why' => 'unasked', 'role' => null, 'note' => _(self::UNASKED), 'hint' => null,
				'detail' => $answer !== null && ($answer['error'] ?? null) !== null ? (string) $answer['error'] : null];
		}
		$role = $answer['role'];
		$mapped = $role === null;
		$role = $role ?? self::zabbixRole($type);
		$blocked = ($answer['disabled'] ?? false) || ($answer['locked'] ?? false);
		if ($role === 'admin' && !$blocked && !$mapped) {
			if (!($answer['trusted'] ?? false)) {
				// An admin over a connection that could have been altered on the way is not acted on.
				return ['write' => false, 'why' => 'untrusted', 'role' => 'admin', 'note' => _(self::UNTRUSTED), 'hint' => null,
					'detail' => (string) ($answer['error'] ?? self::INSECURE)];
			}
			return ['write' => true, 'why' => 'elasticpro_admin', 'role' => 'admin', 'note' => null, 'hint' => null, 'detail' => null];
		}
		$said = $mapped
			? _s('Your ElasticPro role: %1$s (from your Zabbix user type — ElasticPro has not seen you sign in yet).', (string) $role)
			: ($blocked
				? _s('Your ElasticPro role: %1$s, but the account is %2$s in ElasticPro.', (string) $role,
					($answer['disabled'] ?? false) ? _('blocked') : _('locked'))
				: _s('Your ElasticPro role: %1$s.', (string) $role));
		return ['write' => false, 'why' => 'elasticpro_role', 'role' => $role,
			'note' => _('Editing needs an ElasticPro admin role (or Zabbix Super admin).').' '.$said, 'hint' => null, 'detail' => null];
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
		return ['core_url' => (string) $s['core_url'], 'secret' => (string) $s['secret'], 'verify_tls' => (bool) $s['verify_tls'],
			// An ElasticPro module from before this setting has no key: the documented compose default.
			'trusted_plain_hosts' => array_key_exists('trusted_plain_hosts', $s) ? (array) $s['trusted_plain_hosts'] : self::DEFAULT_PLAIN_HOSTS];
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

	/**
	 * A signed GET. Short timeouts: a page waits on this. Hands back the exact body bytes and the
	 * X-ElasticPro-Signature header, which the answer is checked against.
	 *
	 * @return array{status: int, raw: string, signature: string, error: string}
	 */
	public static function curlGet(string $url, array $headers, bool $verify_tls): array {
		if (!function_exists('curl_init') || ($ch = curl_init($url)) === false) {
			return ['status' => 0, 'raw' => '', 'signature' => '', 'error' => 'curl is not available'];
		}
		$signature = '';
		curl_setopt_array($ch, [
			CURLOPT_HTTPGET => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT => 3,
			CURLOPT_SSL_VERIFYPEER => $verify_tls,
			CURLOPT_SSL_VERIFYHOST => $verify_tls ? 2 : 0,
			CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$signature): int {
				if (stripos($line, 'X-ElasticPro-Signature:') === 0) {
					$signature = trim(substr($line, strlen('X-ElasticPro-Signature:')));
				}
				return strlen($line);
			}
		]);
		$raw = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		if ($raw === false) {
			return ['status' => 0, 'raw' => '', 'signature' => '', 'error' => $error !== '' ? $error : 'no answer'];
		}
		return ['status' => $status, 'raw' => (string) $raw, 'signature' => $signature, 'error' => ''];
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
