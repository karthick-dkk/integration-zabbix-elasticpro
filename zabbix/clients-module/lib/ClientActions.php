<?php declare(strict_types = 0);

namespace Modules\EpClients\Lib;

use CCsrfTokenHelper;
use CUrl;

/**
 * What can be done to one client, drawn the same way wherever it is offered.
 *
 * The list used to carry these in every row; they now sit in the header of the client's own page.
 * One definition, so the buttons, their confirmations and the forms they post are the ones the
 * list always had, and the page and its tests read them from one place.
 *
 * Every action that changes something is a one-button POST form with its own token. The two
 * buttons that open a dialog (Maintenance…, Delete for good…) carry data attributes read by
 * views/js/ep.clients.dialogs.js; dialogs() draws the dialogs themselves.
 */
class ClientActions {

	public static function e($v): string {
		return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
	}

	public static function url(string $action, array $args = []): string {
		$u = (new CUrl('zabbix.php'))->setArgument('action', $action);
		foreach ($args as $k => $v) {
			$u->setArgument($k, $v);
		}
		return $u->getUrl();
	}

	public static function csrf(string $action): string {
		return '<input type="hidden" name="'.CSRF_TOKEN_NAME.'" value="'.self::e(CCsrfTokenHelper::get($action)).'">';
	}

	/** A one-button form posting to an action. */
	public static function post(string $action, string $label, array $vars, string $confirm = '', string $class = 'ep-link'): string {
		$out = '<form method="post" action="'.self::e(self::url($action)).'" class="ep-inline">'.self::csrf($action);
		foreach ($vars as $k => $v) {
			$out .= '<input type="hidden" name="'.self::e($k).'" value="'.self::e($v).'">';
		}
		return $out.'<button type="submit" class="'.$class.'"'.($confirm !== '' ? ' onclick="return confirm('.self::e(json_encode($confirm)).');"' : '').'>'.self::e($label).'</button></form>';
	}

	/**
	 * The status a client is in, as a badge: [class, label].
	 *
	 * "Enabled" rather than the list's "Active" because this sits beside Disable / Enable and says
	 * what pressing it would undo.
	 */
	public static function badge(string $status, ?array $maintenance): array {
		if ($status === 'decommissioned') {
			return ['ep-st-decom', _('Decommissioned')];
		}
		if ($status === 'disabled') {
			return ['ep-st-off', _('Disabled')];
		}
		return $maintenance !== null ? ['ep-st-maint', _('In maintenance')] : ['ep-st-on', _('Enabled')];
	}

	/**
	 * The buttons for a client whose master host exists, in the order the list showed them.
	 *
	 * @param array $c  name, status, maintenance (or null), removed, changed, masterid
	 * @param string $button  the class of each button
	 * @return string[]  HTML, one per action
	 */
	public static function forClient(array $c, string $button = 'ep-link'): array {
		$name = $c['name'];
		$v = ['client' => $name];
		$acts = [];
		if ($c['status'] === 'active') {
			if ($c['removed']) {
				$acts[] = self::post('ep.clients.recreate', _n('Re-create %1$s', 'Re-create %1$s', count($c['removed'])), $v + ['op' => 'recreate'], '', $button);
				$acts[] = self::post('ep.clients.recreate', _('Accept removal'), $v + ['op' => 'accept'],
					_s('Accept that these hosts are gone from "%1$s"? %2$s', $name, implode(', ', array_column($c['removed'], 'name'))), $button);
			}
			if ($c['changed'] ?? []) {
				$acts[] = self::post('ep.clients.recreate', _('Put back'), $v + ['op' => 'putback'],
					_s('Rename and regroup the hosts of "%1$s" changed outside this page, as the page last saw them?', $name), $button);
			}
			$acts[] = '<button type="button" class="'.$button.'" data-open-maint="'.self::e($name).'">'.self::e($c['maintenance'] !== null ? _('Extend…') : _('Maintenance…')).'</button>';
			if ($c['maintenance'] !== null) {
				$acts[] = self::post('ep.clients.status', _('End maintenance'), $v + ['op' => 'end_maintenance'], '', $button);
			}
			$acts[] = self::post('ep.clients.status', _('Disable'), $v + ['op' => 'disable'],
				_s('Disable "%1$s"? Every host of it is set to Not monitored: no data and no problems until it is enabled. History is kept. A backup is taken first.', $name), $button);
			$acts[] = self::dashboard($c, $button);
			$acts[] = '<a class="'.$button.'" href="'.self::e(self::url('ep.clients.edit', ['clone' => $name])).'" title="'.self::e(_('A new client with the same roles, capacity and settings')).'">'.self::e(_('Clone')).'</a>';
		}
		elseif ($c['status'] === 'disabled') {
			$acts[] = self::post('ep.clients.status', _('Enable'), $v + ['op' => 'enable'], '', $button);
			$acts[] = self::post('ep.clients.status', _('Decommission'), $v + ['op' => 'decommission'],
				_s('Decommission "%1$s"? It leaves the main list and the reports; its hosts stay, not monitored, with their history. You can restore it later. A backup is taken first.', $name), $button.' ep-danger');
		}
		else {
			$acts[] = self::post('ep.clients.status', _('Restore'), $v + ['op' => 'restore'], '', $button);
			$acts[] = '<button type="button" class="'.$button.' ep-danger" data-open-delete="'.self::e($name).'">'.self::e(_('Delete for good…')).'</button>';
		}
		return $acts;
	}

	/** Where a Zabbix Admin may look: the dashboard, and nothing that changes anything. */
	public static function dashboard(array $c, string $button = 'ep-link'): string {
		return '<a class="'.$button.'" href="'.self::e(self::url('host.dashboard.view', ['hostid' => $c['masterid']])).'">'.self::e(_('Dashboard')).'</a>';
	}

	/** A client whose master host is gone has no page to open, so its two ways out stay on the list. */
	public static function forMissing(string $name, string $button = 'ep-link'): array {
		$v = ['client' => $name];
		return [
			self::post('ep.clients.recreate', _('Re-create'), $v + ['op' => 'recreate'],
				_s('Re-create "%1$s" from what the page last recorded? Its hosts come back with new history. A backup is taken first.', $name), $button),
			self::post('ep.clients.recreate', _('Forget'), $v + ['op' => 'accept'],
				_s('Forget "%1$s"? It leaves the list; nothing in Zabbix changes.', $name), $button)
		];
	}

	/** The maintenance dialog (one client, or the ticked ones on the list) and the delete-for-good dialog. */
	public static function dialogs(): string {
		$e = fn($v) => self::e($v);
		$h = '<dialog id="ep-maint" class="ep-dialog"><form method="post" action="'.$e(self::url('ep.clients.status')).'">'.self::csrf('ep.clients.status')
			.'<input type="hidden" name="op" value="maintenance"><div id="ep-maint-who"></div>'
			.'<h3 id="ep-maint-title">'.$e(_('Maintenance')).'</h3>'
			.'<div class="ep-drow"><label>'.$e(_('Starts')).'</label><div class="ep-seg"><input type="radio" name="when" id="m-now" value="now" checked><label for="m-now">'.$e(_('Now')).'</label>'
			.'<input type="radio" name="when" id="m-at" value="at"><label for="m-at">'.$e(_('At')).'</label></div><input type="datetime-local" name="start" id="m-start" hidden></div>'
			.'<div class="ep-drow"><label>'.$e(_('Lasts')).'</label><div class="ep-seg">';
		foreach (['1' => '1 h', '4' => '4 h', '8' => '8 h', '24' => '24 h'] as $hours => $l) {
			$h .= '<input type="radio" name="hours" id="m-h'.$hours.'" value="'.$hours.'"'.($hours === '4' ? ' checked' : '').'><label for="m-h'.$hours.'">'.$l.'</label>';
		}
		$h .= '</div></div>'
			.'<div class="ep-drow"><label>'.$e(_('Data')).'</label><div class="ep-seg"><input type="radio" name="collect" id="m-c1" value="1" checked><label for="m-c1">'.$e(_('Keep collecting')).'</label>'
			.'<input type="radio" name="collect" id="m-c0" value="0"><label for="m-c0">'.$e(_('Stop collecting')).'</label></div></div>'
			.'<div class="ep-drow"><label for="m-reason">'.$e(_('Reason')).'</label><input type="text" name="reason" id="m-reason" placeholder="'.$e(_('e.g. ES rolling upgrade')).'"></div>'
			.'<p class="ep-sub">'.$e(_('Creates a Zabbix maintenance on the client\'s host group: every host, and any added during it. Problems stay visible, marked as in maintenance. It ends by itself.')).'</p>'
			.'<div class="ep-dfoot"><button type="button" class="ep-btn-alt" data-close>'.$e(_('Cancel')).'</button><button type="submit">'.$e(_('Start maintenance')).'</button></div></form></dialog>';

		// Delete for good: the name typed to confirm.
		return $h.'<dialog id="ep-delete" class="ep-dialog"><form method="post" action="'.$e(self::url('ep.clients.remove')).'">'.self::csrf('ep.clients.remove')
			.'<input type="hidden" name="client" id="d-client"><h3>'.$e(_('Delete for good')).'</h3>'
			.'<p>'.$e(_('The hosts this page made for this client are deleted, with their history. Hosts made by hand and the host group stay. A backup of the settings is taken first; the history cannot come back.')).'</p>'
			.'<div class="ep-drow"><label for="d-confirm">'.$e(_('Type the client\'s name')).'</label><input type="text" name="confirm" id="d-confirm" autocomplete="off"></div>'
			.'<div class="ep-dfoot"><button type="button" class="ep-btn-alt" data-close>'.$e(_('Cancel')).'</button><button type="submit" id="d-go" class="ep-danger-btn" disabled>'.$e(_('Delete for good')).'</button></div></form></dialog>';
	}
}
