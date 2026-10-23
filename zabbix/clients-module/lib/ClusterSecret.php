<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use Modules\ElasticPro\Lib\SsoClient;

/**
 * The Elasticsearch password, handed to ElasticPro as well as kept in Zabbix.
 *
 * Zabbix keeps the password as the Secret macro {$ELASTICSEARCH.PASSWORD} on the cluster host —
 * which nothing, the ElasticPro core included, can read back through the API. So when an admin
 * types it here, the module also hands it to the core, server to server, over the channel the
 * pairing already set up: `POST {core_url}/sso/zabbix/cluster-secret`, the JSON body
 * `{cluster, user, password, set_by, ts, nonce}` signed with HMAC-SHA256 under the pairing secret
 * in X-Zabbix-Module-Signature, exactly as the role lookup is. The core checks the signature, the
 * clock and the nonce, keeps the password encrypted in its data directory, and answers signed back
 * over the request's ts and nonce. `cluster` is the cluster host's technical name — what the
 * core's Zabbix sync knows the cluster by.
 *
 * The password is sent only over a transport EpRole::transportTrusted() accepts (https with
 * verification, a core on this machine, or the internal network's named core). Over anything
 * else it is not sent at all, and the page says so: a password is not worth a cleartext hop.
 *
 * What this module keeps is the fact, never the password: cluster-secrets.json in the data
 * folder, cluster host name => when, by whom, whether Zabbix and the core took it. That is what
 * the client's page shows instead of the password ("set on <date> by <user>").
 */
final class ClusterSecret {

	public const PATH = '/sso/zabbix/cluster-secret';
	public const FILE = 'cluster-secrets.json';

	/** The longest password accepted — far beyond any real one, so a runaway post is refused. */
	public const MAX_PASSWORD = 1024;

	/**
	 * The keep-marker: the edit form posts es_password_action=set only when a password field was
	 * shown and meant (a new cluster, or "Change password" pressed). Anything else keeps the
	 * password exactly as it is, whatever the field holds — a browser's autofill in a hidden
	 * field must not replace a password, and editing a contact must not ask for one.
	 */
	public const ACTION_SET = 'set';

	/** The password from a posted form, honouring the keep-marker: '' means "leave it as it is". */
	public static function fromPost(string $action, string $password): string {
		return $action === self::ACTION_SET ? $password : '';
	}

	/** Who set the password for this cluster host and when, or null when this page never did. */
	public static function of(string $clusterHost): ?array {
		$all = Store::read(self::FILE, []);
		return is_array($all[$clusterHost] ?? null) ? $all[$clusterHost] : null;
	}

	/** Remember that a password was set — never the password. */
	public static function record(string $clusterHost, string $client, string $by, bool $zabbix, array $core, int $at): void {
		$all = Store::read(self::FILE, []);
		$all[$clusterHost] = ['client' => $client, 'at' => $at, 'by' => $by, 'zabbix' => $zabbix,
			'core' => (bool) ($core['ok'] ?? false), 'core_error' => $core['error'] ?? null];
		Store::write(self::FILE, $all);
	}

	/**
	 * After a save: a password typed for a Zabbix secret goes to the ElasticPro core too, so it
	 * can poll the cluster; what happened is recorded — never the password — and said. The one
	 * hand-over every save path uses (the client page, Discover existing hosts).
	 *
	 * @return string[] one line for the page
	 */
	public static function handOver(Reconciler $rec, array $client, string $by, array $deps = []): array {
		if ($client['es'] === null || $client['fields']['es_password_mode'] !== 'zabbix' || $client['es_password'] === '') {
			return [];
		}
		$cluster = $rec->current($client['name'])['cluster'] ?? null;
		if ($cluster === null) {
			return [_('The password was not handed to ElasticPro: the cluster host was not found after saving.')];
		}
		$user = $client['fields']['es_user'] !== '' ? $client['fields']['es_user'] : 'elastic';
		$core = self::send((string) $cluster['host'], $user, $client['es_password'], $by, $deps);
		self::record((string) $cluster['host'], $client['name'], $by, true, $core, (int) (($deps['clock'] ?? 'time')()));
		return [$core['ok'] ? _('Password kept as a Zabbix secret and handed to ElasticPro, which keeps it encrypted.')
			: _s('Password kept as a Zabbix secret. ElasticPro was not given it: %1$s', (string) $core['error'])];
	}

	/** The exact bytes signed and sent. */
	public static function body(string $cluster, string $user, string $password, string $by, int $ts, string $nonce): string {
		return (string) json_encode(['cluster' => $cluster, 'user' => $user, 'password' => $password, 'set_by' => $by, 'ts' => $ts, 'nonce' => $nonce],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Hand the password to the core. Every dependency can be replaced for the tests: settings,
	 * transport, sign, clock, nonce.
	 *
	 * @return array{ok: bool, error: ?string}  never the password, in either
	 */
	public static function send(string $cluster, string $user, string $password, string $by, array $deps = []): array {
		if ($cluster === '' || $password === '' || strlen($password) > self::MAX_PASSWORD) {
			return ['ok' => false, 'error' => _('no cluster host or no password to hand over')];
		}
		$s = ($deps['settings'] ?? [EpRole::class, 'embedSettings'])();
		if (isset($s['error'])) {
			return ['ok' => false, 'error' => (string) $s['error']];
		}
		$core = (string) $s['core_url'];
		if (!EpRole::transportTrusted($core, (bool) ($s['verify_tls'] ?? true), (array) ($s['trusted_plain_hosts'] ?? EpRole::DEFAULT_PLAIN_HOSTS))) {
			return ['ok' => false, 'error' => _('not sent: the connection to ElasticPro is not one a password may travel over (plain HTTP or TLS verification off). Use https, or the internal network\'s core.')];
		}
		$sign = $deps['sign'] ?? [SsoClient::class, 'sign'];
		$ts = (int) (($deps['clock'] ?? 'time')());
		$nonce = (string) (($deps['nonce'] ?? fn() => bin2hex(random_bytes(16)))());
		$secret = (string) $s['secret'];
		$body = self::body($cluster, $user, $password, $by, $ts, $nonce);
		$r = ($deps['transport'] ?? [self::class, 'curlPost'])(rtrim($core, '/').self::PATH, $body,
			['Content-Type: application/json', 'Accept: application/json', 'X-Zabbix-Module-Signature: '.$sign($secret, $body)], (bool) ($s['verify_tls'] ?? true));
		if ((int) ($r['status'] ?? 0) === 0) {
			return ['ok' => false, 'error' => _s('ElasticPro did not answer at %1$s: %2$s', rtrim($core, '/'), (string) ($r['error'] ?? '') ?: _('no answer'))];
		}
		$raw = (string) ($r['raw'] ?? '');
		$data = json_decode($raw, true);
		if ((int) $r['status'] !== 200 || !is_array($data) || ($data['ok'] ?? false) !== true) {
			return ['ok' => false, 'error' => is_array($data) && isset($data['message'])
				? _s('ElasticPro refused it (HTTP %1$s): %2$s', (int) $r['status'], (string) $data['message'])
				: _s('ElasticPro answered HTTP %1$s', (int) $r['status'])];
		}
		$got = strtolower(trim((string) ($r['signature'] ?? '')));
		if ($got === '' || !hash_equals(EpRole::answerSignature($secret, $ts, $nonce, $raw, $sign), $got)
				|| ($data['nonce'] ?? null) !== $nonce || ($data['ts'] ?? null) !== $ts || ($data['cluster'] ?? null) !== $cluster) {
			return ['ok' => false, 'error' => _('the answer was not signed by ElasticPro for this request')];
		}
		return ['ok' => true, 'error' => null];
	}

	/**
	 * A signed POST. Short timeouts: the save waits on it. Hands back the exact body and the
	 * X-ElasticPro-Signature header.
	 *
	 * @return array{status: int, raw: string, signature: string, error: string}
	 */
	public static function curlPost(string $url, string $body, array $headers, bool $verify_tls): array {
		if (!function_exists('curl_init') || ($ch = curl_init($url)) === false) {
			return ['status' => 0, 'raw' => '', 'signature' => '', 'error' => 'curl is not available'];
		}
		$signature = '';
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 2,
			CURLOPT_TIMEOUT => 5,
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
}
