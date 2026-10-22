<?php declare(strict_types = 0);

namespace Modules\EpClients\Actions;

foreach (['Store', 'Settings', 'Roles', 'ColumnSettings', 'Forecast', 'ClientTypes', 'Bars', 'MasterTemplate', 'ClusterTemplate', 'DevicesTemplate', 'JumpTemplate', 'ClientSpec', 'Reconciler', 'Lifecycle', 'Registry', 'History', 'ClientState', 'Csv', 'Zip', 'Xlsx', 'Workbook', 'Backups', 'TemplateInstaller', 'Importer', 'AlertRouting', 'EpRole', 'WriteCheck'] as $lib) {
	require_once __DIR__.'/../lib/'.$lib.'.php';
}

use API;
use Exception;
use CController;
use CControllerResponseRedirect;
use CMessageHelper;
use CUrl;
use CWebUser;
use Modules\EpClients\Lib\{AlertRouting, Backups, ClientSpec, ClientState, EpRole, History, Importer, Lifecycle, Reconciler, Registry, Roles, Settings, Store, WriteCheck};

/**
 * What every Clients page shares: who may change things; roles, rules and Zabbix state; backups.
 *
 * Who may change things is EpRole's decision, in one place: a Zabbix Super admin, or a Zabbix
 * Admin or User whom ElasticPro calls an admin. Pages that only show (READ_ONLY_OK) stay open to
 * every Zabbix Admin, read-only. Before a change by anyone but a Super admin, refuseUnlessAllowed()
 * asks Zabbix whether their own rights cover every step of it (WriteCheck), and refuses before
 * anything changes when they do not.
 */
abstract class Base extends CController {

	private $roles_cache;
	/** @var string|null the backup taken before this request's change */
	private $lastBackup = null;
	/** @var array|null EpRole::decide() for this request */
	private $decision = null;

	/** Pages that only show (the list, a client's page) are open to Zabbix Admins too, read-only. */
	protected const READ_ONLY_OK = false;

	protected function checkPermissions(): bool {
		return $this->canWrite() || (static::READ_ONLY_OK && $this->getUserType() == USER_TYPE_ZABBIX_ADMIN);
	}

	/** Who may edit — EpRole::decide(), asked once per request with a fresh answer. */
	public function writeDecision(): array {
		return $this->decision ?? ($this->decision = EpRole::current()->decision($this->user(), (int) $this->getUserType()));
	}

	/** A Zabbix Super admin, or someone ElasticPro calls an admin. */
	public function canWrite(): bool {
		return $this->writeDecision()['write'];
	}

	/** Why this page is read-only, for the view: [sentence, reason or null], or [null, null] when it is not. */
	public function writeNote(): array {
		$d = $this->writeDecision();
		return [$d['write'] ? null : $d['note'], $d['write'] ? null : $d['hint']];
	}

	/**
	 * The refusal of a change this user's Zabbix rights do not cover, as one message in a list
	 * (empty: go ahead). A Super admin is never asked about, so their saves cost no extra calls.
	 */
	protected function writeRefusals(array $needs): array {
		if ($this->getUserType() == USER_TYPE_SUPER_ADMIN) {
			return [];
		}
		$refused = WriteCheck::refusals($needs, WriteCheck::rights($needs), false);
		return $refused ? [WriteCheck::message($refused)] : [];
	}

	/** writeRefusals(), thrown: call it before backup() and before anything is written. */
	protected function refuseUnlessAllowed(array $needs): void {
		$refused = $this->writeRefusals($needs);
		if ($refused) {
			throw new Exception($refused[0]);
		}
	}

	/** What saving this client needs of the user's Zabbix rights. */
	protected function needsForSave(array $client, ?Reconciler $rec = null, ?ClientSpec $spec = null): array {
		$rec = $rec ?? $this->rec();
		return WriteCheck::forSave($rec, $spec ?? $this->spec(), $client, $rec->current($client['name']));
	}

	/** What applying these import rows needs, all of them together: one refusal before any row is applied. */
	protected function needsForRows(array $rows): array {
		$needs = WriteCheck::none();
		if ($this->getUserType() == USER_TYPE_SUPER_ADMIN) {
			return $needs;
		}
		$spec = $this->spec();
		foreach ($rows as $row) {
			['client' => $client, 'errors' => $errors] = $spec->fromForm($row['form']);
			if (!$errors) {
				$needs = WriteCheck::merge($needs, $this->needsForSave($client));
			}
		}
		return $needs;
	}

	/** hostid => visible name, for the recorded hosts Zabbix still has. */
	public function existingHosts(): array {
		$ids = Registry::hostIds();
		return $ids ? array_column(API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => $ids]) ?: [], 'name', 'hostid') : [];
	}

	protected function roles(): array {
		return $this->roles_cache ?? ($this->roles_cache = Roles::load());
	}

	/** The roles, for a view. */
	public function rolesFor(): array {
		return $this->roles();
	}

	/** The form rules, for a view. */
	public function specFor(): ClientSpec {
		return $this->spec();
	}

	protected function spec(): ClientSpec {
		return new ClientSpec($this->roles());
	}

	protected function rec(): Reconciler {
		return new Reconciler($this->spec());
	}

	protected function state(?Reconciler $rec = null): ClientState {
		return new ClientState($this->spec(), $rec ?? $this->rec());
	}

	protected function importer(): Importer {
		return new Importer($this->spec(), $this->state());
	}

	/**
	 * What the workbook's dropdowns offer, read from this Zabbix now: client types (Settings), and
	 * the proxies and proxy groups a client can be monitored by.
	 */
	protected function workbookLists(): array {
		return ['types' => Settings::types()['all'],
			'proxies' => array_column(API::Proxy()->get(['output' => ['name'], 'sortfield' => 'name']) ?: [], 'name'),
			'proxy_groups' => array_column(API::ProxyGroup()->get(['output' => ['name'], 'sortfield' => 'name']) ?: [], 'name')];
	}

	/** Every client as it is now, for an export: [forms (ClientState::plain), client => ip => host name]. */
	protected function exportForms(): array {
		$state = $this->state();
		$forms = [];
		$names = [];
		foreach ($state->clients() as $name => $_) {
			$form = $state->formFor($name);
			foreach ($form['_now']['machines'] as $h) {
				if ($h['_ip'] !== null) {
					$names[$name][$h['_ip']] = $h['name'];
				}
			}
			$forms[] = ClientState::plain($form);
		}
		return [$forms, $names];
	}

	protected function user(): string {
		return (string) (CWebUser::$data['username'] ?? '?');
	}

	/** A copy of everything, before a change. Refuses the change when it cannot be taken. */
	protected function backup(string $before): string {
		return $this->lastBackup = Backups::take($before, $this->user(), $this->state(), $this->roles());
	}

	protected function lifecycle(): Lifecycle {
		return new Lifecycle($this->rec());
	}

	/** Remember the client as it is now in Zabbix, for noticing hosts deleted behind the page's back. */
	protected function register(string $client): void {
		$form = $this->state()->formFor($client);
		if ($form['_now']['master'] !== null) {
			Registry::record($client, ClientState::plain($form), $form['_now']);
		}
	}

	/** Who last changed a client, and how — shown on the list. */
	protected function noteChange(string $client, string $how): void {
		$log = Store::read('changes.json', []);
		$log[$client] = ['at' => time(), 'how' => $how, 'by' => $this->user()];
		Store::write('changes.json', $log);
		History::add($client, $how, $this->user(), $this->lastBackup);
		// The register follows every change: forgotten when the client is gone, refreshed otherwise.
		$gone = in_array($how, ['removed', 'removed by restore', 'deleted for good'], true);
		if ($gone) {
			Registry::forget($client);
		}
		else {
			$this->register($client);
		}
		// Problems by email to the cluster DL follow the client too: set up, changed or removed.
		try {
			$now = $gone ? null : $this->rec()->current($client);
			$this->routed = array_merge($this->routed, AlertRouting::sync($client, $now['groupid'] ?? null,
				($now['master'] ?? null) !== null ? $this->rec()->macros($now['master']['hostid']) : [], (string) (CWebUser::$data['userid'] ?? '')));
		}
		catch (Exception $e) {
			$this->routed[] = _s('Alert routing: %1$s', $e->getMessage());
		}
	}

	/** What the DL alert routing did on this request's changes, for the notice. */
	private array $routed = [];

	protected function toList(string $title, array $lines = [], bool $error = false, ?string $detail = null): void {
		$lines = array_merge($lines, array_values(array_unique($this->routed)));
		$error ? CMessageHelper::setErrorTitle($title) : CMessageHelper::setSuccessTitle($title);
		if ($detail !== null) {
			$error ? CMessageHelper::addError($detail) : CMessageHelper::addSuccess($detail);
		}
		foreach ($lines as $line) {
			CMessageHelper::addSuccess($line);
		}
		$this->setResponse(new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'ep.clients.list')));
	}
}
