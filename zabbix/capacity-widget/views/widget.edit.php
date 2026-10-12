<?php declare(strict_types = 0);
/**
 * A threshold of 0 and the Display setting left alone both follow ElasticPro -> Settings; a
 * figure typed here is for this dashboard alone. Zabbix has no empty state for a whole-number
 * field — a box left blank casts to 0 — so 0 is what "follow the site" looks like, and the help
 * beside each label says so rather than leaving a 0 that reads as "colour everything".
 *
 * @var CView $this
 * @var array $data
 */

$inherit = _('0: follow ElasticPro → Settings, where this is set for every report at once. A figure here applies to this widget alone.');

(new CWidgetFormView($data))
	->addField(new CWidgetFieldMultiSelectGroupView($data['fields']['groupids']))
	->addField(new CWidgetFieldSelectView($data['fields']['bars']))
	->addField((new CWidgetFieldIntegerBoxView($data['fields']['usage_warn']))->setFieldHint(makeHelpIcon($inherit)))
	->addField((new CWidgetFieldIntegerBoxView($data['fields']['usage_high']))->setFieldHint(makeHelpIcon($inherit)))
	->show();
