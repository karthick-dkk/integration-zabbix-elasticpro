<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use Modules\ElasticPro\Lib\Settings as EmbedSettings;

/**
 * TypeSafe Jev, asked to judge the parts of a Discover scan that are guesses from names: which
 * client a role host belongs to when no host group, {$GRP.CLIENT} or client tag says, whether a
 * host is a Kafka server, and whether a host group whose name does not contain "elasticsearch"
 * still holds Elasticsearch cluster hosts. Opt-in, off by default (ElasticPro -> Settings).
 *
 * What is sent, and nothing else: host names and visible names (a name that is an IP address is
 * replaced by "(an IP address, not sent)"), host group and template names, and the names of the
 * candidate clients the scan already knows (cluster host names, existing client names, a cluster
 * host's {$GRP.CLIENT}). No IP, no item value, no macro value of a role host, no log content.
 *
 * The exact rules stay in Discovery and run first: a host already a client's, a client host group,
 * {$GRP.CLIENT}, a client tag, role groups, a template only one role names. Jev is asked only about
 * what those leave open, and its answer is used as a proposal — never written until an admin
 * adopts it, as every Discover proposal is:
 *   - "auto" (top probability >= AUTO_TOP and at least AUTO_MARGIN above the next) is proposed as
 *     the rule's answer would be;
 *   - anything else is proposed to check (not ticked), or falls back to the name rule.
 *
 * Off, no key, or the call fails, times out (TIMEOUT for the whole scan) or answers anything that
 * is not what was asked: the name rules are used unchanged, and the Discover page says which ran
 * and why — never the key. One request per scan; split only when a request would pass the API's
 * limits (a Choice has at most MAX_OPTIONS options; 64k tokens a request, 32k a question).
 *
 * The API key is stored sealed (AES-256-GCM) with the ElasticPro module's own seal(), the one the
 * pairing secret is kept with, in jev.json in the data folder. It is never sent to the browser,
 * never logged, and never put in a message: a reason that would carry it has it replaced.
 */
final class Jev {

	public const FILE = 'jev.json';
	public const URL = 'https://api.typesafe.ai/v1/systemone';
	public const MODEL = 'jev-latest';

	/** Seconds for the whole judgement of one scan, every request in it. */
	public const TIMEOUT = 10;
	public const CONNECT_TIMEOUT = 5;

	/** An answer is acted on when its top probability is at least this… */
	public const AUTO_TOP = 0.85;
	/** …and at least this much above the next option's. */
	public const AUTO_MARGIN = 0.5;

	/** The API's limit on a Choice question's options (criteria maxProperties). */
	public const MAX_OPTIONS = 255;

	/** The option for "it belongs to none of the clients". */
	public const NONE = 'none of these';

	/**
	 * Sizes in bytes of JSON, at a conservative 3 bytes a token: a request within the 64k-token
	 * request context, and the state plus any one question within the 32k-token question context.
	 */
	public const MAX_REQUEST_BYTES = 150000;
	public const MAX_QUESTION_BYTES = 75000;

	/** More requests than this for one scan is not a judgement worth waiting for: the rules run. */
	public const MAX_REQUESTS = 4;

	/** The longest key accepted — far beyond a real one, so a runaway paste is refused. */
	public const MAX_KEY = 512;

	/**
	 * Test seam for the HTTP call: fn(string $url, array $headers, string $body, int $timeout): array{status: int, body: string, error: string}.
	 * Null in Zabbix: post() is used.
	 *
	 * @var callable|null
	 */
	public static $http = null;

	/**
	 * Test seam for the seal: ['seal' => fn(string): string, 'open' => fn(string): string]. Null in
	 * Zabbix: the ElasticPro module's Settings::seal()/open().
	 *
	 * @var array|null
	 */
	public static $sealer = null;

	/* ------------------------------------ the setting ------------------------------------ */

	/**
	 * What the Settings page may show: whether it is on, whether a key is set (never the key), who
	 * set it and when, and whether a key can be stored at all here.
	 */
	public static function status(): array {
		$f = self::file();
		$sealed = (string) ($f['key'] ?? '');
		$canSeal = self::sealer() !== null;
		return ['enabled' => !empty($f['enabled']), 'key_set' => $sealed !== '',
			'key_readable' => $sealed !== '' && $canSeal && self::open($sealed) !== '',
			'set_at' => isset($f['set_at']) ? (int) $f['set_at'] : null, 'set_by' => isset($f['set_by']) ? (string) $f['set_by'] : null,
			'can_seal' => $canSeal];
	}

	/**
	 * Save the setting. $action is 'set' (store $key), 'clear' (forget the key) or anything else
	 * (keep the key as it is). Returns what changed, for the page; throws, changing nothing, when
	 * the key cannot be stored sealed or is not a key.
	 */
	public static function save(bool $enabled, string $action, string $key, string $by): array {
		$f = self::file();
		$done = [];
		if ($action === 'set') {
			$key = trim($key);
			if ($key === '' || strlen($key) > self::MAX_KEY || preg_match('/[\s\x00-\x1f\x7f]/', $key)) {
				throw new \RuntimeException(_('That is not an API key: paste the key from the TypeSafe console, on one line.'));
			}
			if (self::sealer() === null) {
				throw new \RuntimeException(_s('The key was not stored: it is kept sealed with the ElasticPro module\'s key, and the ElasticPro module (%1$s) is not installed here.', EpRole::EMBED_MODULE_ID));
			}
			$f['key'] = self::seal($key);
			$f['set_at'] = time();
			$f['set_by'] = $by;
			$done[] = _('API key stored, sealed.');
		}
		elseif ($action === 'clear' && ($f['key'] ?? '') !== '') {
			unset($f['key'], $f['set_at'], $f['set_by']);
			$done[] = _('API key cleared.');
		}
		if ((bool) ($f['enabled'] ?? false) !== $enabled) {
			$done[] = $enabled ? _('Discover proposals are judged by Jev.') : _('Jev is off: Discover matches names by rule.');
		}
		$f['enabled'] = $enabled;
		Store::write(self::FILE, $f);
		return $done;
	}

	private static function file(): array {
		$f = Store::read(self::FILE, []);
		return is_array($f) ? $f : [];
	}

	/** The plain key, or '' when there is none or it does not open. Never leaves this class but in the Authorization header. */
	private static function key(): string {
		$sealed = (string) (self::file()['key'] ?? '');
		return $sealed !== '' && self::sealer() !== null ? self::open($sealed) : '';
	}

	private static function sealer(): ?array {
		if (self::$sealer !== null) {
			return self::$sealer;
		}
		if (!EpRole::embedAvailable()) {
			return null;
		}
		return ['seal' => [EmbedSettings::class, 'seal'], 'open' => [EmbedSettings::class, 'open']];
	}

	private static function seal(string $plain): string {
		return (string) (self::sealer()['seal'])($plain);
	}

	private static function open(string $sealed): string {
		try {
			return (string) (self::sealer()['open'])($sealed);
		}
		catch (\Throwable $e) {
			return '';
		}
	}

	/* ------------------------------------ the judgement ------------------------------------ */

	/** A judgement that judged nothing: the name rules run, and the note says why. */
	public static function off(string $why): array {
		return ['used' => false, 'note' => _s('Names matched by rule; Jev %1$s.', $why), 'model' => null, 'asked' => 0,
			'client' => [], 'kafka' => [], 'es_groups' => []];
	}

	/**
	 * Judge one scan's rows (Discovery's describe(), without a judgement). Never throws: any
	 * failure is a judgement that judged nothing, with the reason in its note.
	 *
	 * @return array{used: bool, note: string, model: ?string, asked: int,
	 *               client: array<string, array>, kafka: array<string, array>, es_groups: array<string, array>}
	 */
	public static function judge(array $rows, array $roleGroups, array $words): array {
		try {
			$st = self::status();
			if (!$st['enabled']) {
				return self::off(_('off'));
			}
			if (!$st['key_set']) {
				return self::off(_('unavailable: no API key is set (ElasticPro → Settings)'));
			}
			if (!$st['can_seal']) {
				return self::off(_s('unavailable: the API key is sealed with the ElasticPro module\'s key, and the ElasticPro module (%1$s) is not installed here', EpRole::EMBED_MODULE_ID));
			}
			$key = self::key();
			if ($key === '') {
				return self::off(_('unavailable: the stored API key no longer opens (Zabbix\'s session key changed?) — set it again in ElasticPro → Settings'));
			}
			$plan = self::requests($rows, $roleGroups, $words);
			if ($plan['error'] !== null) {
				return self::off(_s('unavailable: %1$s', $plan['error']));
			}
			if (!$plan['bodies']) {
				return ['used' => true, 'note' => _('Names judged by Jev: nothing was left for it — every host was placed by its host group, {$GRP.CLIENT} or client tag.'),
					'model' => null, 'asked' => 0, 'client' => [], 'kafka' => [], 'es_groups' => []];
			}
			$deadline = microtime(true) + self::TIMEOUT;
			$answers = [];
			$model = null;
			foreach ($plan['bodies'] as $i => $body) {
				$left = (int) ceil($deadline - microtime(true));
				if ($left < 1) {
					return self::off(_s('unavailable: no answer within %1$s seconds', self::TIMEOUT));
				}
				$res = self::call(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $key, $left);
				if ($res['error'] !== '' || $res['status'] !== 200) {
					return self::off(_s('unavailable: %1$s', self::scrub(self::failure($res), $key)));
				}
				$data = json_decode($res['body'], true);
				if (!is_array($data) || !is_array($data['answers'] ?? null)) {
					return self::off(_('unavailable: its answer was not the JSON that was asked for'));
				}
				$model = is_string($data['model'] ?? null) ? $data['model'] : $model;
				foreach ($body['questions'] as $qid => $q) {
					$a = $data['answers'][$qid] ?? null;
					$read = is_array($a) ? self::readAnswer($q, $a) : null;
					if ($read === null) {
						return self::off(_s('unavailable: its answer to question %1$s was missing or not valid', $qid));
					}
					$answers[$qid] = $read;
				}
			}
			$out = self::combine($plan['map'], $answers);
			$auto = count(array_filter(array_merge($out['client'], $out['kafka'], $out['es_groups']), fn($x) => $x['decision'] === 'auto'));
			$all = count($out['client']) + count($out['kafka']) + count($out['es_groups']);
			return $out + ['used' => true, 'model' => $model, 'asked' => $all,
				'note' => _s('Names judged by Jev%1$s: %2$s questions, %3$s decided, %4$s left to check or to the name rule.',
					$model !== null ? ' ('.$model.')' : '', $all, $auto, $all - $auto)];
		}
		catch (\Throwable $e) {
			return self::off(_('unavailable: the judgement could not be made'));
		}
	}

	/** A failed call in words: the HTTP status the API documents, or the transport's error. */
	private static function failure(array $res): string {
		if ($res['error'] !== '') {
			return preg_match('/timed? ?out/i', $res['error']) ? _s('no answer within %1$s seconds', self::TIMEOUT) : $res['error'];
		}
		$why = [400 => 'the request was refused (400)', 401 => 'the API key was refused (401)', 413 => 'the request was too large (413)',
			422 => 'the request was not understood (422)', 429 => 'rate limited (429)', 529 => 'TypeSafe is overloaded (529)'][$res['status']] ?? null;
		return $why ?? _s('HTTP %1$s', $res['status']);
	}

	/** Never the key in anything shown. */
	public static function scrub(string $text, string $key): string {
		return $key !== '' ? str_replace($key, '***', $text) : $text;
	}

	private static function call(string $body, string $key, int $timeout): array {
		$headers = ['Authorization: Bearer '.$key, 'Content-Type: application/json', 'Accept: application/json'];
		$res = self::$http !== null ? (self::$http)(self::URL, $headers, $body, $timeout) : self::post(self::URL, $headers, $body, $timeout);
		return ['status' => (int) ($res['status'] ?? 0), 'body' => (string) ($res['body'] ?? ''), 'error' => (string) ($res['error'] ?? '')];
	}

	/** A POST with TLS verified, no redirects followed, https only, and both timeouts set — as SsoClient's. */
	private static function post(string $url, array $headers, string $body, int $timeout): array {
		if (!function_exists('curl_init') || ($ch = curl_init($url)) === false) {
			return ['status' => 0, 'body' => '', 'error' => 'curl is not available'];
		}
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, $timeout),
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2
		]);
		$raw = curl_exec($ch);
		$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$error = curl_error($ch);
		// No curl_close(): it has done nothing since PHP 8.0 and is deprecated in 8.5.
		if ($raw === false) {
			return ['status' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'no answer'];
		}
		return ['status' => $status, 'body' => (string) $raw, 'error' => ''];
	}

	/* ------------------------------------ the questions (pure) ------------------------------------ */

	/** A name as it may be sent: an IP address never is. */
	public static function safeName(string $name): string {
		return filter_var(trim($name), FILTER_VALIDATE_IP) !== false ? _('(an IP address, not sent)') : $name;
	}

	/** A host as the questions describe it: names, host groups and templates — nothing else. */
	public static function facts(array $row): array {
		$host = (string) $row['host'];
		$name = (string) ($row['name'] ?? $host);
		$out = ['host name' => self::safeName($host)];
		if ($name !== '' && $name !== $host) {
			$out['visible name'] = self::safeName($name);
		}
		$out['host groups'] = array_values(array_map('strval', $row['groups'] ?? []));
		$out['templates'] = array_values(array_map('strval', $row['templates'] ?? []));
		return $out;
	}

	/**
	 * The requests for one scan, and what each question is about. Pure.
	 *
	 * Asked:
	 *  - for each role host the exact rules leave without a client (none of host group,
	 *    {$GRP.CLIENT} or client tag attaches it, and none points at two): a Choice of the clusters'
	 *    names plus NONE — speculatively including hosts that are not in an "elasticsearch" group,
	 *    in case a group question below makes them clusters;
	 *  - for each role host with no role from its groups, templates or ep-role tag: a Noul, Kafka?;
	 *  - for each host group of a host that carries an Elasticsearch template but is in no
	 *    "elasticsearch" group (role groups and this module's own groups left out): a Noul, does it
	 *    hold Elasticsearch cluster hosts?
	 *
	 * @return array{bodies: array, map: array<string, array>, error: ?string}
	 */
	public static function requests(array $rows, array $roleGroups, array $words): array {
		$map = [];
		$questions = [];
		// The candidates: every cluster, and every host only a judged group would make one.
		$candidates = array_values(array_filter($rows, fn($r) => in_array($r['kind'], ['cluster', 'stray'], true)));
		$options = self::options($candidates);
		$state = ['about' => 'Hosts in a Zabbix that monitors Elasticsearch. Each client is one Elasticsearch cluster, and the client\'s servers (Elasticsearch nodes, parsers, forwarders, engines and other role hosts) are usually named, grouped or templated after it.'];

		// Which client: the role hosts the exact rules leave open.
		$open = self::openHosts($rows, $roleGroups, $words);
		if ($open && $options) {
			$state['clients'] = [];
			foreach ($options as $name => $o) {
				$state['clients'][$name] = $o['about'];
			}
			$chunks = array_chunk(array_keys($options), self::MAX_OPTIONS - 1);
			foreach ($open as $n => $row) {
				foreach ($chunks as $k => $names) {
					$qid = 'c'.$n.(count($chunks) > 1 ? '_'.$k : '');
					$criteria = array_fill_keys($names, null);
					$criteria[self::NONE] = 'The server belongs to none of the clients listed in this question, or nothing about it says which.';
					$questions[$qid] = ['type' => 'choice', 'instructions' => [
						'question' => 'Which client in `clients` does this server belong to? Judge by its host name, visible name, host groups and templates against each client\'s names and host groups.',
						'server' => self::facts($row)], 'criteria' => $criteria];
					$map[$qid] = ['kind' => 'client', 'ref' => (string) $row['hostid'], 'options' => array_map(fn($x) => $options[$x]['hostid'], array_combine($names, $names))];
				}
			}
		}

		// Kafka: role hosts with no role from a fact.
		$n = 0;
		foreach ($rows as $row) {
			if ($row['kind'] !== 'server' || ($row['already'] ?? null) !== null || !in_array($row['role_source'] ?? null, [null, 'kafka'], true)) {
				continue;
			}
			$qid = 'k'.$n++;
			$questions[$qid] = ['type' => 'noul', 'instructions' => ['question' => 'Is this server a Kafka server (a Kafka broker, or a host that runs Kafka)?',
				'server' => self::facts($row)],
				'criteria' => ['true' => 'Its names, host groups or templates say it runs Kafka.', 'false' => 'Nothing about it says Kafka; it is another kind of server.']];
			$map[$qid] = ['kind' => 'kafka', 'ref' => (string) $row['hostid']];
		}

		// Host groups that might hold cluster hosts: those of hosts with an Elasticsearch template outside every "elasticsearch" group.
		$groups = [];
		foreach ($rows as $row) {
			if ($row['kind'] !== 'stray') {
				continue;
			}
			foreach ($row['groups'] as $g) {
				if (!Discovery::genericGroup((string) $g, $roleGroups)) {
					$groups[(string) $g][] = self::safeName((string) $row['host']);
				}
			}
		}
		$n = 0;
		foreach ($groups as $g => $hosts) {
			$qid = 'g'.$n++;
			$questions[$qid] = ['type' => 'noul', 'instructions' => [
				'question' => 'Is this Zabbix host group meant for Elasticsearch cluster hosts — hosts that each stand for one whole Elasticsearch cluster, monitored over its HTTP API?',
				'host group' => $g, 'hosts in it that carry an Elasticsearch template' => array_values(array_unique($hosts))],
				'criteria' => ['true' => 'The group\'s name says Elasticsearch (or ES, Elastic) clusters or nodes.', 'false' => 'The group is about something else (an OS, a site, a team, an application), or does not say.']];
			$map[$qid] = ['kind' => 'group', 'ref' => $g];
		}
		if (!$questions) {
			return ['bodies' => [], 'map' => [], 'error' => null];
		}
		return self::pack($state, $questions, $map);
	}

	/**
	 * One option per candidate cluster, named as its proposal would be (its client's name, else
	 * its host name), with what identifies it — names and host groups only.
	 *
	 * @return array<string, array{hostid: string, about: array}>
	 */
	private static function options(array $candidates): array {
		$out = [];
		foreach ($candidates as $c) {
			$name = (string) ($c['already'] ?? $c['host']);
			$base = self::safeName($name);
			$key = $base;
			for ($i = 2; isset($out[$key]) || strcasecmp($key, self::NONE) === 0; $i++) {
				$key = $base.' ('.$i.')';
			}
			$also = array_values(array_unique(array_filter(array_map(fn($x) => self::safeName((string) $x), [$c['host'], $c['grp_client'] ?? '', $c['tag_client'] ?? '']),
				fn($x) => $x !== '' && $x !== $key)));
			$out[$key] = ['hostid' => (string) $c['hostid'], 'about' => array_filter([
				'cluster host' => self::safeName((string) $c['host']), 'also named' => $also,
				'host groups' => array_values(array_map('strval', $c['groups'] ?? []))], fn($x) => $x !== [] && $x !== '')];
		}
		return $out;
	}

	/** The role hosts no exact rule attaches, and no exact rule points at two clusters for. Pure. */
	public static function openHosts(array $rows, array $roleGroups, array $words): array {
		$pre = Discovery::proposals($rows, $words, $roleGroups);
		$open = [];
		foreach ($pre['proposals'] as $p) {
			foreach (array_slice($p['hosts'], 1) as $h) {
				if (($h['source'] ?? null) === 'prefix') {
					$open[] = $h;
				}
			}
		}
		foreach ($pre['unattached'] as $h) {
			// Ambiguous by an exact hint, or already a client's: not Jev's to decide.
			if (empty($h['ambiguous']) && ($h['already'] ?? null) === null) {
				$open[] = $h;
			}
		}
		return $open;
	}

	/** Questions into as few bodies as the size limits allow. */
	private static function pack(array $state, array $questions, array $map): array {
		$stateBytes = strlen(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$bodies = [];
		$cur = [];
		$size = $stateBytes;
		foreach ($questions as $qid => $q) {
			$qb = strlen(json_encode([$qid => $q], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			if ($stateBytes + $qb > self::MAX_QUESTION_BYTES) {
				return ['bodies' => [], 'map' => [], 'error' => _('too many clusters to name in one question')];
			}
			if ($cur && $size + $qb > self::MAX_REQUEST_BYTES) {
				$bodies[] = $cur;
				$cur = [];
				$size = $stateBytes;
			}
			$cur[$qid] = $q;
			$size += $qb;
		}
		$bodies[] = $cur;
		if (count($bodies) > self::MAX_REQUESTS) {
			return ['bodies' => [], 'map' => [], 'error' => _s('too many hosts to judge in one scan (%1$s questions)', count($questions))];
		}
		return ['bodies' => array_map(fn($qs) => ['model' => self::MODEL, 'state' => $state, 'questions' => $qs], $bodies), 'map' => $map, 'error' => null];
	}

	/* ------------------------------------ the answers (pure) ------------------------------------ */

	/**
	 * Top option, its probability, its lead over the next, and whether that is enough to act on.
	 *
	 * @param array<string, float> $probabilities
	 */
	public static function decide(array $probabilities): array {
		arsort($probabilities);
		$p = array_values($probabilities);
		$top = (float) ($p[0] ?? 0);
		$margin = $top - (float) ($p[1] ?? 0);
		return ['pick' => (string) array_key_first($probabilities), 'p' => $top, 'margin' => $margin,
			'decision' => $top >= self::AUTO_TOP && $margin >= self::AUTO_MARGIN ? 'auto' : 'review'];
	}

	/** One answer checked against its question: its decision, or null when it is not a valid answer to it. */
	public static function readAnswer(array $q, array $a): ?array {
		$num = fn($x) => (is_int($x) || is_float($x)) && $x >= 0 && $x <= 1;
		if ($q['type'] === 'noul') {
			if (($a['type'] ?? null) !== 'noul' || !$num($a['noul'] ?? null)) {
				return null;
			}
			$d = self::decide(['yes' => (float) $a['noul'], 'no' => 1 - (float) $a['noul']]);
			return $d + ['yes' => $d['pick'] === 'yes'];
		}
		$probs = $a['probabilities'] ?? null;
		if (($a['type'] ?? null) !== 'choice' || !is_array($probs) || !$probs) {
			return null;
		}
		foreach ($probs as $k => $v) {
			if (!array_key_exists((string) $k, $q['criteria']) || !$num($v)) {
				return null;
			}
		}
		if (!is_string($a['choice'] ?? null) || !array_key_exists($a['choice'], $q['criteria'])) {
			return null;
		}
		$probs = array_combine(array_map('strval', array_keys($probs)), array_map('floatval', $probs));
		return self::decide($probs);
	}

	/**
	 * The answers, as Discovery reads them. Pure.
	 *  - client: hostid => [pick: cluster hostid or null for none, name, p, decision]
	 *  - kafka: hostid => [yes, p, decision]
	 *  - es_groups: group name => [yes, p, decision]
	 */
	public static function combine(array $map, array $answers): array {
		$out = ['client' => [], 'kafka' => [], 'es_groups' => []];
		$byHost = [];
		foreach ($map as $qid => $m) {
			$a = $answers[$qid] ?? null;
			if ($a === null) {
				continue;
			}
			if ($m['kind'] === 'kafka') {
				$out['kafka'][$m['ref']] = ['yes' => $a['yes'], 'p' => $a['p'], 'decision' => $a['decision']];
			}
			elseif ($m['kind'] === 'group') {
				$out['es_groups'][$m['ref']] = ['yes' => $a['yes'], 'p' => $a['p'], 'decision' => $a['decision']];
			}
			else {
				$byHost[$m['ref']][] = ['name' => $a['pick'], 'pick' => $a['pick'] === self::NONE ? null : ($m['options'][$a['pick']] ?? null),
					'p' => $a['p'], 'decision' => $a['decision']];
			}
		}
		// A host asked in several option chunks: decided only when one chunk names a client, surely,
		// and every other chunk surely says none of its own.
		foreach ($byHost as $host => $parts) {
			$named = array_values(array_filter($parts, fn($x) => $x['pick'] !== null));
			$sure = !array_filter($parts, fn($x) => $x['decision'] !== 'auto');
			if (count($named) === 1) {
				$out['client'][$host] = array_merge($named[0], ['decision' => $sure ? 'auto' : 'review']);
			}
			elseif (!$named) {
				$out['client'][$host] = ['name' => self::NONE, 'pick' => null, 'p' => min(array_column($parts, 'p')), 'decision' => $sure ? 'auto' : 'review'];
			}
			else {
				usort($named, fn($a, $b) => $b['p'] <=> $a['p']);
				$out['client'][$host] = array_merge($named[0], ['decision' => 'review']);
			}
		}
		return $out;
	}

	/** Group names Jev surely judged to hold Elasticsearch cluster hosts. */
	public static function clusterGroups(array $judged): array {
		return array_keys(array_filter($judged['es_groups'] ?? [], fn($x) => $x['yes'] && $x['decision'] === 'auto'));
	}

	/** A probability as a person reads it. */
	public static function percent(float $p): string {
		return (string) round($p * 100).' %';
	}
}
