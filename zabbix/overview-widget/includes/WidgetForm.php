<?php declare(strict_types = 0);

namespace Modules\EpOverview\Includes;

use CWidgetsData;
use Modules\EpOverview\Lib\{Roles, Settings};
use Zabbix\Widgets\{CWidgetField, CWidgetForm};
use Zabbix\Widgets\Fields\{CWidgetFieldCheckBox, CWidgetFieldIntegerBox, CWidgetFieldMultiSelectGroup, CWidgetFieldRadioButtonList,
	CWidgetFieldSelect, CWidgetFieldTextBox, CWidgetFieldTimePeriod};

/**
 * The Client overview's settings, on two tabs.
 *
 * Data and metrics: which clients (host groups, clients by name, client types, whether disabled
 * and decommissioned ones show), the trend window, the metrics each card lists, the order the
 * clients come in and which of them show.
 *
 * Layout and production: the card's sections and their order, columns and density, used of
 * allocated with its forecast, the contract badge, servers by role, problems and warnings, and
 * where a click goes.
 *
 * A widget saved before these settings existed (groupids, client, client_type, show_*, family,
 * usage_warn, usage_high) is read into them by normalizeValues() without being edited; the
 * first Apply stores it in the new shape. CONFIG_VERSION tells the two apart, so a widget whose
 * metrics were all removed on purpose is not handed the default set again.
 */
class WidgetForm extends CWidgetForm {

	public const CONFIG_VERSION = 2;

	public const TREND_DASHBOARD = 0;
	public const TREND_DAYS = 1;

	public const DENSITY_COMFORTABLE = 0;
	public const DENSITY_COMPACT = 1;
	public const DENSITY_TABLE = 2;

	public const USED_SHOW_OF = 0;
	public const USED_SHOW_PCT = 1;
	public const USED_SHOW_BOTH = 2;

	public const USED_BAR = 0;
	public const USED_GAUGE = 1;
	public const USED_NONE = 2;

	public const ROLES_PILLS = 0;
	public const ROLES_STACKED = 1;
	public const ROLES_DONUT = 2;

	public const SERVERS_COUNT = 0;
	public const SERVERS_HOVER = 1;
	public const SERVERS_LIST = 2;

	public const LINK_DASHBOARD = 0;
	public const LINK_ELASTICPRO = 1;
	public const LINK_CLUSTER_MANAGEMENT = 2;

	public const LINK_SEVERITY_PROBLEMS = 0;
	public const LINK_SEVERITY_NONE = 1;

	/** Fields this widget had before 2.1; read once, never stored again. */
	public const LEGACY = ['client', 'client_type', 'show_summary', 'show_used', 'show_roles', 'family', 'usage_warn', 'usage_high'];

	/**
	 * The number a name (a client type, a family id) was stored as before 2.1: Zabbix stores a
	 * select as an integer, and a number taken from the name stays with the name. Kept to read
	 * those widgets.
	 */
	public static function key(string $name): int {
		return (crc32($name) & 0x7fffffff) ?: 1;
	}

	protected function normalizeValues(array $values): array {
		$values = parent::normalizeValues($values);

		return self::upgrade($values, Roles::load(), Settings::all());
	}

	/**
	 * The settings as the fields expect them, from whatever came in: a saved widget (old or new),
	 * the form, or the dashboard. Pure, for the tests.
	 */
	public static function upgrade(array $values, array $roles, array $cfg): array {
		// The metrics list reordered by dragging: the form posts each row's old position in its new order.
		if (isset($values['sortorder']['metrics']) && isset($values['metrics']) && is_array($values['metrics'])) {
			$sorted = [];
			foreach ((array) $values['sortorder']['metrics'] as $index) {
				if (isset($values['metrics'][$index])) {
					$sorted[] = $values['metrics'][$index];
				}
			}
			$values['metrics'] = $sorted;
		}
		unset($values['sortorder']);

		// Roles shown: the form posts the order of every role and the ones ticked; what is kept is
		// the ones not ticked, so a role added later in Cluster Management shows without an edit.
		if (array_key_exists('roles_shown', $values)) {
			$order = CWidgetFieldStrings::clean($values['role_order'] ?? []);
			$shown = CWidgetFieldStrings::clean($values['roles_shown']);
			$values['roles_hidden'] = array_values(array_diff($order, $shown)) ?: '';
			unset($values['roles_shown']);
		}

		// The dashboard's time period is referred to only while the trend window follows it, so a
		// dashboard shows its time selector for this widget only when it means something here.
		if ((int) ($values['trend_source'] ?? self::TREND_DAYS) === self::TREND_DASHBOARD) {
			if (!array_key_exists('time_period', $values)) {
				$values['time_period'] = [CWidgetField::FOREIGN_REFERENCE_KEY => self::dashboardPeriod()];
			}
		}
		else {
			unset($values['time_period']);
		}

		if ((int) ($values['config_version'] ?? 0) >= self::CONFIG_VERSION) {
			return array_diff_key($values, array_flip(self::LEGACY));
		}

		// Before 2.1. One client by name, one client type by number.
		$client = trim((string) ($values['client'] ?? ''));
		if ($client !== '' && !array_key_exists('clients', $values)) {
			$values['clients'] = [$client];
		}
		$typeKey = (int) ($values['client_type'] ?? 0);
		if ($typeKey !== 0 && !array_key_exists('client_types', $values)) {
			foreach (Settings::types($cfg)['all'] as $t) {
				if (self::key($t) === $typeKey) {
					$values['client_types'] = [$t];
				}
			}
		}
		// Its three panels: the cards' bars are the metrics now, the other two kept their names.
		if (!array_key_exists('sections', $values)) {
			$off = [];
			foreach (['show_summary' => 'metrics', 'show_used' => 'used', 'show_roles' => 'roles'] as $old => $section) {
				if (array_key_exists($old, $values) && (int) $values[$old] === 0) {
					$off[] = $section;
				}
			}
			if ($off) {
				$values['sections'] = array_values(array_diff(Overview::SECTIONS_ON, $off)) ?: '';
			}
		}
		// What its cards showed, at its thresholds (0, or nothing: ElasticPro -> Settings).
		$metrics = array_filter((array) ($values['metrics'] ?? []), 'is_array');
		if (!$metrics) {
			$family = 'es';
			$familyKey = (int) ($values['family'] ?? 0);
			foreach ($roles['families'] ?? [] as $f) {
				if ($familyKey !== 0 && self::key((string) $f['id']) === $familyKey) {
					$family = (string) $f['id'];
				}
			}
			$values['metrics'] = Metrics::defaults($roles, $family, $values['usage_warn'] ?? '', $values['usage_high'] ?? '');
		}

		return array_diff_key($values, array_flip(self::LEGACY));
	}

	/** The typed reference to the dashboard's time period, as Zabbix's own widgets name it. */
	public static function dashboardPeriod(): string {
		return CWidgetField::createTypedReference(CWidgetField::REFERENCE_DASHBOARD, CWidgetsData::DATA_TYPE_TIME_PERIOD);
	}

	public function addFields(): self {
		$roles = Roles::load();
		$cfg = Settings::all();
		$role_ids = array_column(Roles::allRoles($roles), 'id');

		return $this
			->addField((new CWidgetFieldIntegerBox('config_version', null, 0, 99))->setDefault(0))
			// Data and metrics.
			->addField(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
			->addField(new CWidgetFieldStrings('clients', _('Clients'), 500))
			->addField((new CWidgetFieldCheckBox('hide_inactive', null, _('Hide disabled and decommissioned clients')))->setDefault(1))
			->addField((new CWidgetFieldStrings('client_types', _('Client types'), 8, 32))->setAllowed(Settings::types($cfg)['all']))
			->addField((new CWidgetFieldRadioButtonList('trend_source', _('Trend window'), [
				self::TREND_DASHBOARD => _('Dashboard time period'),
				self::TREND_DAYS => _('Last')
			]))->setDefault(self::TREND_DAYS))
			->addField((new CWidgetFieldIntegerBox('trend_days', _('Days'), 1, 365))->setDefault(7))
			->addField(
				(new CWidgetFieldTimePeriod('time_period', _('Time period')))
					->setDefault(['from' => 'now-7d', 'to' => 'now'])
					->setInType(CWidgetsData::DATA_TYPE_TIME_PERIOD)
					->acceptDashboard()
			)
			->addField(new CWidgetFieldMetrics('metrics', _('Metrics'), Metrics::catalogue($roles), $role_ids))
			->addField((new CWidgetFieldTextBox('order_by', _('Order clients by')))->setDefault('name'))
			->addField((new CWidgetFieldSelect('order_dir', _('Direction'), [
				Overview::ORDER_DESC => _('Highest first'),
				Overview::ORDER_ASC => _('Lowest first')
			]))->setDefault(Overview::ORDER_ASC))
			->addField(new CWidgetFieldOptionalNumber('top_n', _('Show top'), 1, 1000))
			->addField((new CWidgetFieldRadioButtonList('only_show', _('Only show clients'), [
				Overview::ONLY_ALL => _('All'),
				Overview::ONLY_OVER => _('Over a Warn or High threshold'),
				Overview::ONLY_PROBLEMS => _('With open problems')
			]))->setDefault(Overview::ONLY_ALL))
			// Layout and production: sections.
			->addField((new CWidgetFieldStrings('section_order', null, 20, 16))->setAllowed(Overview::SECTIONS)->setDefault(Overview::SECTIONS))
			->addField((new CWidgetFieldStrings('sections', null, 20, 16))->setAllowed(Overview::SECTIONS)->setDefault(Overview::SECTIONS_ON))
			->addField((new CWidgetFieldRadioButtonList('columns', _('Columns'), [0 => _('Auto'), 1 => '1', 2 => '2', 3 => '3', 4 => '4']))->setDefault(0))
			->addField((new CWidgetFieldIntegerBox('card_width', _('Card width'), 200, 1200))->setDefault(340))
			->addField((new CWidgetFieldRadioButtonList('density', _('Density'), [
				self::DENSITY_COMFORTABLE => _('Comfortable'),
				self::DENSITY_COMPACT => _('Compact'),
				self::DENSITY_TABLE => _('Table (one row per client)')
			]))->setDefault(self::DENSITY_COMFORTABLE))
			// Used of allocated and forecast.
			->addField((new CWidgetFieldTextBox('used_measure', _('Measure')))->setDefault('storage'))
			->addField((new CWidgetFieldRadioButtonList('used_show', _('Show as'), [
				self::USED_SHOW_OF => _('Used of allocated'),
				self::USED_SHOW_PCT => _('Percent'),
				self::USED_SHOW_BOTH => _('Both')
			]))->setDefault(self::USED_SHOW_OF))
			->addField((new CWidgetFieldSelect('used_style', _('Bar style'), [
				self::USED_BAR => _('Bar'),
				self::USED_GAUGE => _('Gauge'),
				self::USED_NONE => _('No bar')
			]))->setDefault(self::USED_BAR))
			->addField((new CWidgetFieldIntegerBox('forecast_days', _('Forecast from'), 1, 90))->setDefault(7))
			->addField(new CWidgetFieldOptionalNumber('forecast_warn', _('Forecast warn at'), 1, 36500))
			->addField(new CWidgetFieldOptionalNumber('forecast_high', _('Forecast high at'), 1, 36500))
			->addField((new CWidgetFieldCheckBox('contract_badge', null, _('Badge')))->setDefault(1))
			->addField((new CWidgetFieldIntegerBox('contract_warn', _('Contract warn within'), 0, 3650))->setDefault(60))
			->addField((new CWidgetFieldIntegerBox('contract_high', _('Contract high within'), 0, 3650))->setDefault(14))
			// Servers by role.
			->addField((new CWidgetFieldRadioButtonList('roles_style', _('Style'), [
				self::ROLES_PILLS => _('Pills'),
				self::ROLES_STACKED => _('Stacked bar'),
				self::ROLES_DONUT => _('Donut')
			]))->setDefault(self::ROLES_PILLS))
			->addField((new CWidgetFieldStrings('role_order', null, 200, 64)))
			->addField((new CWidgetFieldStrings('roles_hidden', null, 200, 64)))
			->addField((new CWidgetFieldRadioButtonList('roles_servers', _('Servers'), [
				self::SERVERS_COUNT => _('Count per role'),
				self::SERVERS_HOVER => _('Count, names on hover'),
				self::SERVERS_LIST => _('List every server')
			]))->setDefault(self::SERVERS_COUNT))
			->addField((new CWidgetFieldCheckBox('down_red', null, _('Show in red')))->setDefault(1))
			->addField((new CWidgetFieldIntegerBox('down_after', _('Down after'), 1, 1440))->setDefault(5))
			// Problems and warnings.
			->addField((new CWidgetFieldStrings('severities', _('Severities'), 6, 1))->setAllowed(array_map('strval', Overview::SEVERITIES))
				->setDefault(Overview::SEVERITIES_ON))
			->addField(new CWidgetFieldCheckBox('problems_ack', null, _('Include acknowledged')))
			->addField(new CWidgetFieldCheckBox('problems_suppressed', null, _('Include suppressed')))
			->addField((new CWidgetFieldCheckBox('hide_zero', null, _('Hide severities at 0')))->setDefault(1))
			->addField((new CWidgetFieldIntegerBox('stale_after', _('Stale data'), 1, 10080))->setDefault(10))
			->addField((new CWidgetFieldCheckBox('warn_agent', null, _('Agent unreachable')))->setDefault(1))
			->addField((new CWidgetFieldCheckBox('warn_nodata', null, _('Metric not collected')))->setDefault(1))
			->addField((new CWidgetFieldCheckBox('warn_maintenance', null, _('Client in maintenance')))->setDefault(1))
			// Click-through.
			->addField((new CWidgetFieldSelect('link_client', _('Client name'), [
				self::LINK_DASHBOARD => _('Client dashboard (master host)'),
				self::LINK_ELASTICPRO => _('ElasticPro cluster page'),
				self::LINK_CLUSTER_MANAGEMENT => _('Cluster Management, this client')
			]))->setDefault(self::LINK_DASHBOARD))
			->addField((new CWidgetFieldSelect('link_severity', _('Severity count'), [
				self::LINK_SEVERITY_PROBLEMS => _('Problems, filtered to client and severity'),
				self::LINK_SEVERITY_NONE => _('Nothing')
			]))->setDefault(self::LINK_SEVERITY_PROBLEMS))
			->addField((new CWidgetFieldRadioButtonList('link_target', _('Open in'), [0 => _('Same tab'), 1 => _('New tab')]))->setDefault(0));
	}
}
