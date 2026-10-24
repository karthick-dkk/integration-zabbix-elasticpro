<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use API;
use Exception;

/**
 * Moves one host onto the ElasticPro templates without losing a stored value — the in-module half
 * of tools/migrate-templates.py, for the hosts Discover existing hosts adopts.
 *
 * Zabbix keeps history per item id, so the move is the script's replace mode, in its order:
 * copy the macros the remaining items need to host level → unlink the old template WITHOUT clear
 * (its items stay on the host, same ids, all their history) → link the new templates, which adopt
 * every host item whose key they carry → check every item id that existed still exists.
 *
 * What is shared with the script and what is not (docs/MIGRATION.md says the same):
 *  - plan() is a port of plan_replace() for a generation without key or macro renames: the same
 *    ADOPT / KEEP / CURRENT / UNTOUCHED / REFUSE decisions, the same refusals, the same macro
 *    rules. Both are run against one table of cases, tools/test/fixtures/replace-cases.json
 *    (tools/test/migrate_plan_test.py and test/discover.test.php), so the two cannot drift
 *    without a test going red. MACRO_FIELDS and FORBIDDEN_KEYS are copies of the script's;
 *    test/discover.test.php reads the script and fails if they differ.
 *  - Not ported: key and macro renames (prefix maps), in-place template upgrades, --import,
 *    --retire-old-triggers, the journal and its rollback, and value counts in the verify (the
 *    item ids are checked; Zabbix stores values by id). A host that needs any of those is
 *    refused here with a reason that names the script.
 *
 * Which templates are "old" is not a list of names. A directly linked template that gives the
 * host an item or discovery key the new templates also carry is old: linking the new template
 * beside it would fail (Zabbix allows one item per key) or duplicate it. A host with no such
 * template gets the new templates linked beside what it has, and nothing is unlinked — the
 * script's unlink_old: false. That is always the case for the Elasticsearch cluster template
 * this module writes, which carries no items at all (ClusterTemplate).
 *
 * Never: templateids_clear, a delete, an item key rename. The write calls are exactly
 * usermacro.create, host.massremove with templateids, and host.massadd with templates.
 */
final class TemplateMove {

	/** Item fields a macro can appear in — copied from migrate-templates.py's MACRO_FIELDS. */
	public const MACRO_FIELDS = ['key_', 'url', 'username', 'password', 'params', 'delay', 'timeout', 'posts', 'headers',
		'query_fields', 'ssl_cert_file', 'ssl_key_file', 'ssl_key_password', 'http_proxy',
		'status_codes', 'jmx_endpoint', 'publickey', 'privatekey', 'lifetime'];

	/** Parameters no call here may carry — copied from migrate-templates.py's FORBIDDEN_KEYS. */
	public const FORBIDDEN_KEYS = ['templateids_clear', 'templates_clear', 'deleteMissing'];

	/** Zabbix value types, as the script labels them. */
	public const VT_LABEL = ['0' => 'FLOAT', '1' => 'CHAR', '2' => 'LOG', '3' => 'UNSIGNED', '4' => 'TEXT', '5' => 'BINARY'];

	/** How a macro copied here is described on the host, so a person can tell where it came from. */
	public const MACRO_NOTE = 'copied by Cluster Management (Discover existing hosts)';

	/* ------------------------------------ planning (pure) ------------------------------------ */

	/** Macro names used in a text, context stripped: {$A:"x"} is {$A}. As the script's macro_refs(). */
	public static function macroRefs(string $text): array {
		preg_match_all('/\{\$[A-Z0-9_.]+(?::[^}]*)?\}/', $text, $m);
		return array_values(array_unique(array_map(fn($x) => preg_replace('/:.*\}$/', '}', $x), $m[0])));
	}

	/**
	 * What to do on one host. Pure — a port of plan_replace() with no key or macro renames.
	 *
	 * @param array $snap    snapshot(): host {hostid, host, parentTemplates, macros}, items, rules,
	 *                       prototypes, triggers — each inherited object with its origin template id
	 * @param array $oldTpl  templateid => ['host' => name, 'macros' => [macro => [value|null, type]]]
	 * @param array $target  targets(): templates [{templateid, host}], missing [names],
	 *                       keys [key => value type], lld [key, …], macros [macro => [value, type]]
	 * @param bool  $unlink  whether the old templates are unlinked (the script's unlink_old)
	 */
	public static function plan(array $snap, array $oldTpl, array $target, bool $unlink = true): array {
		$host = $snap['host'];
		$linked = array_map('strval', array_column($host['parentTemplates'] ?? [], 'templateid'));
		$targetIds = array_map('strval', array_column($target['templates'], 'templateid'));
		$oldIds = array_values(array_intersect(array_map('strval', array_keys($oldTpl)), $linked));
		sort($oldIds);
		$link = array_values(array_diff($targetIds, $linked));
		sort($link);
		$plan = ['host' => $host['host'], 'hostid' => (string) $host['hostid'], 'refused' => [], 'items' => [], 'rules' => [], 'macros' => [],
			'manual' => [], 'unlink' => $oldIds, 'link' => $link, 'triggers' => [], 'notes' => []];
		if ($target['missing']) {
			$plan['refused'][] = 'new template(s) not in Zabbix — import them first: '.implode(', ', $target['missing']);
		}
		if (!$oldIds) {
			$plan['refused'][] = 'none of the old templates is linked directly to this host';
		}
		$keys = $target['keys'];
		$vt = fn($v) => self::VT_LABEL[(string) $v] ?? (string) $v;
		foreach ($snap['items'] as $it) {
			$k = (string) $it['key_'];
			$t = (string) $it['value_type'];
			$origin = isset($it['origin']) && $it['origin'] !== null ? (string) $it['origin'] : null;
			$row = ['itemid' => (string) $it['itemid'], 'key' => $k, 'value_type' => $vt($t)];
			if ((string) ($it['flags'] ?? '0') === '4') {
				$row += ['action' => 'KEEP', 'why' => 'discovered: stays with its discovery rule'];
			}
			elseif ($origin !== null && in_array($origin, $oldIds, true)) {
				if (!array_key_exists($k, $keys)) {
					$row += ['action' => 'KEEP', 'why' => 'no equivalent key in the new templates'];
				}
				elseif ((string) $keys[$k] !== $t) {
					// Same key, another value type: after the unlink it is a host item with the new
					// template's key, and the link would adopt it and convert it — the values stored
					// under the old type would stop being visible. Refused, as the script refuses it.
					$plan['refused'][] = "item $k is ".$vt($t).' and the new template\'s is '.$vt($keys[$k])
						.' under the same key: the link would adopt and convert it, hiding its values';
					$row += ['action' => 'REFUSE', 'why' => 'value type differs under the same key'];
				}
				else {
					$row += ['action' => 'ADOPT', 'why' => 'same key: the link adopts it'];
				}
			}
			elseif ($origin !== null && in_array($origin, $targetIds, true)) {
				$row += ['action' => 'CURRENT', 'why' => 'already from the new template'];
			}
			elseif ((string) ($it['templateid'] ?? '0') === '0') {
				if (array_key_exists($k, $keys)) {
					if ((string) $keys[$k] !== $t) {
						$plan['refused'][] = "host item $k is ".$vt($t).' and the new template\'s is '.$vt($keys[$k]).': linking would hide its values';
						$row += ['action' => 'REFUSE', 'why' => 'value type differs'];
					}
					else {
						$row += ['action' => 'ADOPT', 'why' => 'host\'s own item with the new key: the link adopts it'];
					}
				}
				else {
					$row += ['action' => 'UNTOUCHED', 'why' => 'host\'s own item'];
				}
			}
			else {
				if (array_key_exists($k, $keys)) {
					$plan['refused'][] = "item $k comes from another linked template: the link would fail";
					$row += ['action' => 'REFUSE', 'why' => 'key comes from a third template'];
				}
				else {
					$row += ['action' => 'UNTOUCHED', 'why' => 'from another template'];
				}
			}
			$plan['items'][] = $row;
		}
		// Discovery rules of the old template, as the script plans them. Checked on 7.0.31: the link
		// adopts a rule with the same key as it adopts an item — same rule id, the rule and its
		// prototypes templated again, the discovered items left as they are — unless a prototype
		// changes its value type under the same key, which would convert what was discovered.
		$lld = array_flip(array_map('strval', $target['lld']));
		$protos = $target['protos'] ?? [];
		foreach ($snap['rules'] ?? [] as $r) {
			if (!in_array((string) ($r['origin'] ?? ''), $oldIds, true)) {
				continue;
			}
			$row = ['itemid' => (string) $r['itemid'], 'key' => (string) $r['key_']];
			if (!isset($lld[(string) $r['key_']])) {
				$row += ['action' => 'KEEP', 'why' => 'no equivalent rule in the new templates: stays a host rule with its items'];
			}
			else {
				$bad = array_values(array_filter($snap['prototypes'] ?? [], fn($p) => (string) ($p['discoveryRule']['itemid'] ?? '') === (string) $r['itemid']
					&& array_key_exists((string) $p['key_'], $protos) && (string) $protos[(string) $p['key_']] !== (string) ($p['value_type'] ?? '')));
				if ($bad) {
					$plan['refused'][] = 'discovery rule '.$r['key_'].' is in both templates and its prototype '.$bad[0]['key_'].' is '.$vt($bad[0]['value_type'] ?? '')
						.' here, '.$vt($protos[(string) $bad[0]['key_']]).' in the new template: the link would adopt the rule and convert the items it discovered';
					$row += ['action' => 'REFUSE', 'why' => 'prototype value type differs under the same key'];
				}
				else {
					$row += ['action' => 'ADOPT', 'why' => 'same key: the link adopts the rule, its prototypes and the items it discovered'];
				}
			}
			$plan['rules'][] = $row;
		}
		$origins = array_column(array_map(fn($i) => ['id' => (string) $i['itemid'], 'o' => $i['origin'] ?? null], $snap['items']), 'o', 'id');
		$inheritedAdopt = array_filter($plan['items'], fn($x) => $x['action'] === 'ADOPT'
			&& in_array((string) ($origins[$x['itemid']] ?? ''), $oldIds, true));
		$ruleAdopt = array_filter($plan['rules'], fn($x) => $x['action'] === 'ADOPT');
		if (!$unlink) {
			$plan['unlink'] = [];
			if ($inheritedAdopt || $ruleAdopt) {
				$plan['refused'][] = 'keys must move from the old template to the new one, which needs the old template unlinked (unlink_old is false for this generation)';
			}
		}

		// Macros: what kept objects resolve today must survive the unlink; never a value made up,
		// never a secret guessed. As the script: without an unlink the old template still answers,
		// unless a new template defines the same macro differently.
		$onHost = array_column($host['macros'] ?? [], null, 'macro');
		if ($oldIds) {
			$oldMacros = [];
			foreach ($oldTpl as $t) {
				foreach ($t['macros'] as $m => $v) {
					$oldMacros[$m] = $oldMacros[$m] ?? $v;
				}
			}
			$kept = array_flip(array_column(array_filter($plan['items'], fn($x) => $x['action'] === 'KEEP'), 'itemid'));
			$texts = [];
			foreach ($snap['items'] as $i) {
				if (isset($kept[(string) $i['itemid']])) {
					foreach (self::MACRO_FIELDS as $f) {
						$texts[] = (string) ($i[$f] ?? '');
					}
				}
			}
			foreach (array_merge($snap['rules'] ?? [], $snap['prototypes'] ?? []) as $o) {
				if (in_array((string) ($o['origin'] ?? ''), $oldIds, true)) {
					foreach (self::MACRO_FIELDS as $f) {
						$texts[] = (string) ($o[$f] ?? '');
					}
				}
			}
			foreach ($snap['triggers'] ?? [] as $t) {
				if (in_array((string) ($t['origin'] ?? ''), $oldIds, true)) {
					$texts[] = (string) ($t['expression'] ?? '');
					$texts[] = (string) ($t['recovery_expression'] ?? '');
				}
			}
			$used = [];
			foreach ($texts as $x) {
				foreach (self::macroRefs($x) as $m) {
					$used[$m] = true;
				}
			}
			$used = array_keys($used);
			sort($used);
			foreach ($used as $m) {
				if (isset($onHost[$m]) || !array_key_exists($m, $oldMacros)) {
					continue;
				}
				[$val, $typ] = $oldMacros[$m];
				$typ = (string) $typ;
				$tm = $target['macros'][$m] ?? null;
				if ($tm !== null && (string) $tm[0] === (string) $val && (string) $tm[1] === $typ && $typ !== '1') {
					continue;
				}
				if (!$plan['unlink'] && $tm === null) {
					continue;
				}
				if ($typ === '1') {
					$plan['manual'][] = "$m: secret on the old template, used by kept items — set it on the host by hand (secret values cannot be read through the API)";
				}
				else {
					$plan['macros'][] = ['macro' => $m, 'value' => $val, 'type' => $typ, 'why' => $plan['unlink']
						? 'kept items use it; it came from the old template' : 'kept items use it and a new template defines it differently'];
				}
			}
		}
		$planned = array_flip(array_column($plan['macros'], 'macro'));
		$targetMacros = $target['macros'];
		ksort($targetMacros);
		foreach ($targetMacros as $m => [$val, $typ]) {
			if ((string) $typ === '1' && !isset($onHost[$m]) && !isset($planned[$m])) {
				$plan['manual'][] = "$m: secret the new template ships empty — set it on the host if this host needs it";
			}
		}
		$moving = array_flip(array_column(array_filter($plan['items'], fn($x) => $x['action'] === 'ADOPT'), 'itemid'));
		foreach ($snap['triggers'] ?? [] as $t) {
			$ids = array_map('strval', $t['itemids'] ?? []);
			if (in_array((string) ($t['origin'] ?? ''), $oldIds, true) && $ids && !array_diff($ids, array_keys($moving))) {
				$plan['triggers'][] = ['triggerid' => (string) $t['triggerid'], 'description' => (string) $t['description']];
			}
		}
		return $plan;
	}

	/**
	 * The move this host needs to carry the new templates, decided from its own items: the old
	 * templates are the directly linked ones (not among the new) that give it a key the new ones
	 * carry. None: 'beside' — link, unlink nothing. Some: plan() with the unlink.
	 *
	 * @return array plan() plus 'mode' => beside | move, and for beside an empty item list
	 */
	public static function decide(array $snap, array $templateNames, array $target): array {
		$targetIds = array_map('strval', array_column($target['templates'], 'templateid'));
		$keys = $target['keys'];
		$lld = array_flip(array_map('strval', $target['lld']));
		$old = [];
		foreach ($snap['items'] as $it) {
			$o = $it['origin'] ?? null;
			if ($o !== null && !in_array((string) $o, $targetIds, true) && array_key_exists((string) $it['key_'], $keys)) {
				$old[(string) $o] = true;
			}
		}
		foreach ($snap['rules'] ?? [] as $r) {
			$o = $r['origin'] ?? null;
			if ($o !== null && !in_array((string) $o, $targetIds, true) && isset($lld[(string) $r['key_']])) {
				$old[(string) $o] = true;
			}
		}
		if (!$old) {
			// Beside: nothing moves. What can still stop the link is a host item of the host's own
			// with a new key and another value type — the link would convert it.
			$plan = self::plan($snap, [], $target, false);
			$plan['refused'] = array_values(array_filter($plan['refused'], fn($r) => strpos($r, 'none of the old templates') !== 0));
			$plan['mode'] = 'beside';
			return $plan;
		}
		$oldTpl = [];
		foreach (array_keys($old) as $id) {
			$oldTpl[$id] = ['host' => $templateNames[$id] ?? $id, 'macros' => $snap['template_macros'][$id] ?? []];
		}
		$plan = self::plan($snap, $oldTpl, $target, true);
		$plan['mode'] = 'move';
		return $plan;
	}

	/** One line for the proposal's write list. Pure. */
	public static function summary(array $plan, array $templateNames): string {
		$name = fn($id) => '"'.($templateNames[$id] ?? $id).'"';
		$count = fn(string $a) => count(array_filter($plan['items'], fn($x) => $x['action'] === $a));
		if ($plan['refused']) {
			return _s('cannot move: %1$s — use tools/migrate-templates.py (docs/MIGRATION.md)', implode('; ', $plan['refused']));
		}
		if ($plan['mode'] === 'beside') {
			return $plan['link'] ? _s('link %1$s beside what it has; nothing is unlinked', implode(', ', array_map($name, $plan['link'])))
				: _('already carries the ElasticPro templates');
		}
		$out = _s('unlink %1$s without clear (its items stay, with their history), then link %2$s: %3$s items adopted, %4$s kept as host items',
			implode(', ', array_map($name, $plan['unlink'])), implode(', ', array_map($name, $plan['link'])) ?: _('nothing new'),
			$count('ADOPT'), $count('KEEP'));
		$rules = array_column(array_filter($plan['rules'] ?? [], fn($x) => $x['action'] === 'ADOPT'), 'key');
		if ($rules) {
			$out .= '; '._s('discovery rules adopted with their items: %1$s', implode(', ', $rules));
		}
		if ($plan['macros']) {
			$out .= '; '._s('macros copied to the host: %1$s', implode(', ', array_column($plan['macros'], 'macro')));
		}
		if ($plan['manual']) {
			$out .= '; '._s('by hand afterwards: %1$s', implode(' ', $plan['manual']));
		}
		return $out;
	}

	/* ------------------------------------ reading ------------------------------------ */

	/**
	 * One host as plan() needs it, read through the API as the signed-in user. Secret macro values
	 * are never returned by Zabbix; their names and types are.
	 */
	public static function snapshot(string $hostid): array {
		$h = API::Host()->get(['output' => ['hostid', 'host', 'name'], 'hostids' => [$hostid],
			'selectParentTemplates' => ['templateid', 'host'], 'selectMacros' => ['hostmacroid', 'macro', 'value', 'type']]);
		if (!$h) {
			throw new Exception(_s('Host %1$s is not visible to you any more.', $hostid));
		}
		$items = API::Item()->get(['output' => array_values(array_unique(array_merge(['itemid', 'key_', 'name', 'templateid', 'value_type', 'flags'], self::MACRO_FIELDS))),
			'hostids' => [$hostid]]) ?: [];
		$rules = API::DiscoveryRule()->get(['output' => array_values(array_unique(array_merge(['itemid', 'key_', 'templateid'], self::MACRO_FIELDS))),
			'hostids' => [$hostid]]) ?: [];
		$protos = $rules ? (API::ItemPrototype()->get(['output' => array_values(array_unique(array_merge(['itemid', 'key_', 'templateid', 'value_type'], self::MACRO_FIELDS))),
			'hostids' => [$hostid], 'selectDiscoveryRule' => ['itemid']]) ?: []) : [];
		$triggers = API::Trigger()->get(['output' => ['triggerid', 'description', 'templateid', 'status', 'expression', 'recovery_expression'],
			'hostids' => [$hostid], 'selectItems' => ['itemid']]) ?: [];
		// Where each inherited object comes from: its templateid is the object on the directly
		// linked template, whose hostid is that template.
		$origin = [];
		$parents = array_values(array_filter(array_map(fn($o) => (string) $o['templateid'], array_merge($items, $rules, $protos)), fn($x) => $x !== '0'));
		if ($parents) {
			foreach ([API::Item(), API::DiscoveryRule(), API::ItemPrototype()] as $res) {
				foreach ($res->get(['output' => ['itemid', 'hostid'], 'itemids' => $parents, 'templated' => true]) ?: [] as $p) {
					$origin[(string) $p['itemid']] = (string) $p['hostid'];
				}
			}
		}
		$torigin = [];
		$tparents = array_values(array_filter(array_map(fn($t) => (string) $t['templateid'], $triggers), fn($x) => $x !== '0'));
		if ($tparents) {
			foreach (API::Trigger()->get(['output' => ['triggerid'], 'triggerids' => $tparents, 'selectHosts' => ['hostid'], 'templated' => true]) ?: [] as $p) {
				$torigin[(string) $p['triggerid']] = (string) ($p['hosts'][0]['hostid'] ?? '');
			}
		}
		$withOrigin = function (array $list) use ($origin): array {
			return array_map(fn($o) => $o + ['origin' => (string) $o['templateid'] !== '0' ? ($origin[(string) $o['templateid']] ?? null) : null], $list);
		};
		$triggers = array_map(fn($t) => $t + ['origin' => (string) $t['templateid'] !== '0' ? ($torigin[(string) $t['templateid']] ?? null) : null,
			'itemids' => array_column($t['items'] ?? [], 'itemid')], $triggers);
		$linked = array_column($h[0]['parentTemplates'] ?? [], 'templateid');
		$tmacros = [];
		if ($linked) {
			foreach (API::Template()->get(['output' => ['templateid'], 'templateids' => $linked, 'selectMacros' => ['macro', 'value', 'type']]) ?: [] as $t) {
				foreach ($t['macros'] ?? [] as $m) {
					$tmacros[(string) $t['templateid']][$m['macro']] = [(string) $m['type'] === '1' ? null : ($m['value'] ?? ''), (string) $m['type']];
				}
			}
		}
		return ['host' => $h[0], 'items' => $withOrigin($items), 'rules' => $withOrigin($rules), 'prototypes' => $withOrigin($protos),
			'triggers' => $triggers, 'template_macros' => $tmacros];
	}

	/** The new templates by name: ids, item keys with value types, discovery keys, macros. As the script's targets(). */
	public static function targets(array $names): array {
		$names = array_values(array_unique(array_filter($names, fn($n) => $n !== '')));
		$found = $names ? (API::Template()->get(['output' => ['templateid', 'host'], 'filter' => ['host' => $names],
			'selectMacros' => ['macro', 'value', 'type']]) ?: []) : [];
		$missing = array_values(array_diff($names, array_column($found, 'host')));
		sort($missing);
		$ids = array_column($found, 'templateid');
		$keys = [];
		$lld = [];
		$protos = [];
		$macros = [];
		if ($ids) {
			foreach (API::Item()->get(['output' => ['key_', 'value_type'], 'templateids' => $ids]) ?: [] as $i) {
				$keys[$i['key_']] = (string) $i['value_type'];
			}
			foreach (API::DiscoveryRule()->get(['output' => ['key_'], 'templateids' => $ids]) ?: [] as $r) {
				$lld[] = (string) $r['key_'];
			}
			foreach (API::ItemPrototype()->get(['output' => ['key_', 'value_type'], 'templateids' => $ids]) ?: [] as $p) {
				$protos[$p['key_']] = (string) $p['value_type'];
			}
			foreach ($found as $t) {
				foreach ($t['macros'] ?? [] as $m) {
					$macros[$m['macro']] = [(string) $m['type'] === '1' ? null : ($m['value'] ?? ''), (string) $m['type']];
				}
			}
		}
		return ['templates' => array_map(fn($t) => ['templateid' => (string) $t['templateid'], 'host' => $t['host']], $found),
			'missing' => $missing, 'keys' => $keys, 'lld' => array_values(array_unique($lld)), 'protos' => $protos, 'macros' => $macros];
	}

	/* ------------------------------------ writing ------------------------------------ */

	/**
	 * Carry out a 'move' plan on one host and check it. Throws, naming the step, at the first
	 * refusal or when an item that existed is gone afterwards. A 'beside' plan writes nothing
	 * here: Reconciler::apply() links the new templates as it links every adopted host's.
	 *
	 * @return string[] what was done, one sentence per step
	 */
	public static function execute(array $plan, array $snap, array $templateNames): array {
		if ($plan['refused']) {
			throw new Exception(_s('"%1$s" was not moved: %2$s', $plan['host'], implode('; ', $plan['refused'])));
		}
		if ($plan['mode'] !== 'move') {
			return [];
		}
		$done = [];
		$hostid = $plan['hostid'];
		foreach ($plan['macros'] as $m) {
			self::call(API::UserMacro()->create(['hostid' => $hostid, 'macro' => $m['macro'], 'value' => (string) ($m['value'] ?? ''),
				'type' => (int) $m['type'], 'description' => self::MACRO_NOTE]), _s('copy macro %1$s to "%2$s"', $m['macro'], $plan['host']));
			$done[] = _s('Copied %1$s to "%2$s" (kept items use it).', $m['macro'], $plan['host']);
		}
		if ($plan['unlink']) {
			$params = ['hostids' => [$hostid], 'templateids' => array_values($plan['unlink'])];
			self::guard($params);
			self::call(API::Host()->massRemove($params), _s('unlink the old templates from "%1$s"', $plan['host']));
			$done[] = _s('Unlinked %1$s from "%2$s" without clear: its items stay, with their history.',
				implode(', ', array_map(fn($id) => '"'.($templateNames[$id] ?? $id).'"', $plan['unlink'])), $plan['host']);
		}
		if ($plan['link']) {
			$params = ['hosts' => [['hostid' => $hostid]], 'templates' => array_map(fn($id) => ['templateid' => $id], array_values($plan['link']))];
			self::guard($params);
			self::call(API::Host()->massAdd($params), _s('link the new templates to "%1$s"', $plan['host']));
			$done[] = _s('Linked %1$s to "%2$s".', implode(', ', array_map(fn($id) => '"'.($templateNames[$id] ?? $id).'"', $plan['link'])), $plan['host']);
		}
		$v = self::verify($plan, $snap);
		if ($v['lost'] || $v['not_adopted'] || $v['rules_not_adopted']) {
			throw new Exception(_s('"%1$s" was moved but does not check out: %2$s item(s) gone (%3$s), %4$s not adopted (%5$s), %6$s discovery rule(s) not adopted (%7$s). Nothing was deleted by this page; see docs/MIGRATION.md → Rollback.',
				$plan['host'], count($v['lost']), implode(', ', $v['lost']), count($v['not_adopted']), implode(', ', $v['not_adopted']),
				count($v['rules_not_adopted']), implode(', ', $v['rules_not_adopted'])));
		}
		$done[] = _s('"%1$s" checked: every item it had is still there (%2$s), %3$s now from the new templates.',
			$plan['host'], $v['before'], $v['adopted']);
		return $done;
	}

	/** Every item id the host had still exists; every ADOPT row — item or discovery rule — is now inherited. */
	public static function verify(array $plan, array $snap): array {
		$after = array_column(API::Item()->get(['output' => ['itemid', 'key_', 'templateid'], 'hostids' => [$plan['hostid']]]) ?: [], null, 'itemid');
		$rules = array_column(API::DiscoveryRule()->get(['output' => ['itemid', 'key_', 'templateid'], 'hostids' => [$plan['hostid']]]) ?: [], null, 'itemid');
		$rulesNotAdopted = [];
		foreach ($plan['rules'] ?? [] as $x) {
			if ($x['action'] === 'ADOPT' && (!isset($rules[$x['itemid']]) || (string) $rules[$x['itemid']]['templateid'] === '0')) {
				$rulesNotAdopted[] = $x['key'];
			}
		}
		$lost = [];
		foreach ($snap['items'] as $i) {
			if (!isset($after[(string) $i['itemid']])) {
				$lost[] = $i['key_'];
			}
		}
		$notAdopted = [];
		$adopted = 0;
		foreach ($plan['items'] as $x) {
			if ($x['action'] !== 'ADOPT' || !isset($after[$x['itemid']])) {
				continue;
			}
			if ((string) $after[$x['itemid']]['templateid'] === '0') {
				$notAdopted[] = $x['key'];
			}
			else {
				$adopted++;
			}
		}
		return ['lost' => $lost, 'not_adopted' => $notAdopted, 'rules_not_adopted' => $rulesNotAdopted, 'before' => count($snap['items']), 'adopted' => $adopted];
	}

	/** Refuse, before a request is built, any parameter that would clear or delete. */
	public static function guard(array $params): void {
		$walk = function ($v) use (&$walk): void {
			if (!is_array($v)) {
				return;
			}
			foreach ($v as $k => $x) {
				if (is_string($k) && in_array($k, self::FORBIDDEN_KEYS, true)) {
					throw new \LogicException("refused: $k would delete items and their history");
				}
				$walk($x);
			}
		};
		$walk($params);
	}

	private static function call($result, string $what) {
		if ($result === false) {
			$said = function_exists('get_and_clear_messages') ? array_column(get_and_clear_messages(), 'message') : [];
			throw new Exception(_s('Zabbix would not %1$s: %2$s', $what, implode(' ', $said) ?: _('no reason given')));
		}
		return $result;
	}
}
