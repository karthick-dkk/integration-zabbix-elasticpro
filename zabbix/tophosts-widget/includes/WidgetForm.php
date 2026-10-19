<?php declare(strict_types = 0);

namespace Modules\EpTophosts\Includes;

use Modules\EpTophosts\Lib\{Bars, ColumnSettings, Columns, Roles, Settings};
use Zabbix\Widgets\CWidgetForm;
use Zabbix\Widgets\Fields\{CWidgetFieldCheckBox, CWidgetFieldIntegerBox, CWidgetFieldMultiSelectGroup, CWidgetFieldRadioButtonList, CWidgetFieldSelect,
	CWidgetFieldTextBox};

/**
 * Top hosts' settings: which servers are ranked — host groups of the client master hosts, clients,
 * client types and roles — by what (CPU, memory, disk used, JVM heap or any Zabbix item; now, or
 * its average or maximum over a window), how many, which of the metrics viewers may switch between
 * on the widget, the columns each row shows (Rank, Host, Value, Client · Role, and any column of the
 * model: more figures of the server, each now or over a window), how the ranking is drawn (a list
 * with bars, a table, a bar chart), and for this dashboard alone where usage turns yellow and red.
 * Left alone, every client is ranked and the thresholds follow ElasticPro -> Settings.
 *
 * A widget saved before 2.1.1 had Client (a name), Client type, Rank by (CPU or memory), Hosts and
 * the two thresholds. They are read onto the new settings: the client is the one client picked, the
 * type the one type, Rank by is the same field, the thresholds of 0 are empty. It still ranks the same
 * servers by the same figure, with the CPU / Memory switch, as a list with bars.
 */
class WidgetForm extends CWidgetForm {

	public const BARS_DEFAULT = Bars::INHERIT;

	public const VIEW_LIST = 0;
	public const VIEW_TABLE = 1;
	public const VIEW_BARS = 2;

	public const RANK_VALUE_NOW = 0;
	public const RANK_VALUE_AVG = 1;
	public const RANK_VALUE_MAX = 2;

	/** The metrics a viewer may be offered a switch to. An item is the widget's own choice and has none. */
	public const SWITCH = ['cpu', 'mem', 'disk', 'jvm'];
	/** What a widget saved before 2.1.1 offered: the CPU / Memory switch. */
	public const SWITCH_DEFAULT = ['cpu', 'mem'];

	/** Kept for the tests and for reading widgets that stored it: the number a client type was stored as. */
	public static function typeKey(string $type): int {
		return (crc32($type) & 0x7fffffff) ?: 1;
	}

	protected function normalizeValues(array $values): array {
		$values = parent::normalizeValues($values);

		return self::upgrade($values, Catalogue::columns(), Settings::types(Settings::all())['all']);
	}

	/**
	 * The settings as the fields expect them, from whatever came in: a saved widget (old or new), the
	 * form, or the dashboard. Pure, for the tests.
	 *
	 * @param string[] $types  the client types the site has
	 */
	public static function upgrade(array $values, array $catalogue, array $types): array {
		// Before 2.1.1: one client by name, one client type by number.
		if (empty($values['clients']) && is_scalar($values['client'] ?? null) && trim((string) $values['client']) !== '') {
			$values['clients'] = [trim((string) $values['client'])];
		}
		if (empty($values['client_types']) && (int) ($values['client_type'] ?? 0) !== 0) {
			foreach ($types as $t) {
				if (self::typeKey($t) === (int) $values['client_type']) {
					$values['client_types'] = [$t];
				}
			}
		}
		unset($values['client'], $values['client_type']);

		// A threshold of 0 was the integer box's empty box: ElasticPro -> Settings.
		foreach (['usage_warn', 'usage_high'] as $k) {
			if (array_key_exists($k, $values) && is_numeric($values[$k]) && (float) $values[$k] <= 0) {
				$values[$k] = '';
			}
		}

		// The columns, in the order the rows were dragged into; a widget that never set them draws the four it always did,
		// and a built-in column it has never seen is offered unticked.
		if (isset($values['sortorder']['columns']) && isset($values['columns']) && is_array($values['columns'])) {
			$sorted = [];
			foreach ((array) $values['sortorder']['columns'] as $index) {
				if (isset($values['columns'][$index])) {
					$sorted[] = $values['columns'][$index];
				}
			}
			$values['columns'] = $sorted;
		}
		unset($values['sortorder']);
		$own = array_values(array_filter((array) ($values['columns'] ?? []), 'is_array'));
		$values['columns'] = ColumnSettings::resolve($own ?: Catalogue::defaultColumns($catalogue), $catalogue, null, true);

		return $values;
	}

	/** What must agree, when the form is applied. A saved widget that disagrees still draws. */
	public function validate(bool $strict = false): array {
		$errors = parent::validate($strict);

		if ($strict && !$errors) {
			$v = $this->getFieldsValues();
			$set = fn($k) => isset($v[$k]) && $v[$k] !== '' && is_numeric($v[$k]);
			if ($set('usage_warn') && $set('usage_high') && (float) $v['usage_warn'] > (float) $v['usage_high']) {
				$errors[] = _s('Invalid parameter "%1$s": %2$s.', _('Warn at, %'),
					_s('must not be above High at, %% (%1$s > %2$s)', $v['usage_warn'], $v['usage_high']));
			}
			if ((Catalogue::RANKS[(int) ($v['rank'] ?? 0)] ?? 'cpu') === 'item') {
				$error = Columns::keyError((string) ($v['rank_key'] ?? ''), (bool) ($v['rank_pattern'] ?? 0));
				if ($error !== null) {
					$errors[] = _s('Invalid parameter "%1$s": %2$s.', _('Item key'), $error);
				}
			}
		}

		return $errors;
	}

	public function addFields(): self {
		$roles = Roles::load();
		$cfg = Settings::all();
		$catalogue = Catalogue::columns($roles);

		return $this
			// Who is ranked.
			->addField(new CWidgetFieldMultiSelectGroup('groupids', _('Host groups')))
			->addField(new CWidgetFieldStrings('clients', _('Clients'), 500))
			->addField((new CWidgetFieldStrings('client_types', _('Client types'), 8, 32))->setAllowed(Settings::types($cfg)['all']))
			->addField((new CWidgetFieldStrings('roles', _('Roles'), 100, 64))->setAllowed(array_column(Roles::allRoles($roles), 'id')))
			// By what.
			->addField((new CWidgetFieldSelect('rank', _('Rank by'), [0 => _('CPU'), 1 => _('Memory'), 2 => _('Disk used'), 3 => _('JVM heap'), 4 => _('Any Zabbix item…')]))
				->setDefault(0))
			->addField(new CWidgetFieldTextBox('rank_key', _('Item key')))
			->addField((new CWidgetFieldCheckBox('rank_pattern', null, _('Key is a pattern (* wildcard)')))->setDefault(0))
			->addField((new CWidgetFieldSelect('rank_value', _('Rank value'), [
				self::RANK_VALUE_NOW => _('Now'), self::RANK_VALUE_AVG => _('Average over the window'), self::RANK_VALUE_MAX => _('Maximum over the window')
			]))->setDefault(self::RANK_VALUE_NOW))
			->addField((new CWidgetFieldIntegerBox('trend_days', _('Trend window'), 1, 365))->setDefault(7))
			->addField((new CWidgetFieldIntegerBox('count', _('Hosts'), 1, 100))->setDefault(10))
			->addField((new CWidgetFieldStrings('switch_metrics', _('Switch on widget'), 4, 8))->setAllowed(self::SWITCH)->setDefault(self::SWITCH_DEFAULT))
			// What a row shows, and how.
			->addField(new CWidgetFieldColumns('columns', _('Columns'), Catalogue::forScope($catalogue, 'client'), [], 'client'))
			->addField((new CWidgetFieldRadioButtonList('view', _('View'), [
				self::VIEW_LIST => _('List with bars'),
				self::VIEW_TABLE => _('Table'),
				self::VIEW_BARS => _('Bar chart')
			]))->setDefault(self::VIEW_LIST))
			->addField((new CWidgetFieldSelect('bars', _('Bar style'), Bars::optionsWithInherit()))->setDefault(self::BARS_DEFAULT))
			->addField(new CWidgetFieldOptionalNumber('usage_warn', _('Warn at, %'), 1, 100))
			->addField(new CWidgetFieldOptionalNumber('usage_high', _('High at, %'), 1, 100));
	}
}
