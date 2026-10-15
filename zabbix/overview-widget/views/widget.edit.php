<?php declare(strict_types = 0);
/**
 * Client overview settings. Client: a client's name as ElasticPro → Clients shows it, empty for
 * every client. A threshold of 0 follows ElasticPro → Settings, as in the other reports.
 *
 * @var CView $this
 * @var array $data
 */

$inherit = _('0: follow ElasticPro → Settings, where this is set for every report at once. A figure here applies to this widget alone.');

(new CWidgetFormView($data))
	->addField(new CWidgetFieldMultiSelectGroupView($data['fields']['groupids']))
	->addField((new CWidgetFieldTextBoxView($data['fields']['client']))->setFieldHint(makeHelpIcon(_('A client\'s name as ElasticPro → Clients shows it. Empty: every client, one card each; a name: that client\'s own tiles and gauges.'))))
	->addField(new CWidgetFieldSelectView($data['fields']['client_type']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['show_summary']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['show_used']))
	->addField(new CWidgetFieldCheckBoxView($data['fields']['show_roles']))
	->addField((new CWidgetFieldSelectView($data['fields']['family']))->setFieldHint(makeHelpIcon(_('The family whose CPU and memory the summary cards and the gauges show.'))))
	->addField((new CWidgetFieldIntegerBoxView($data['fields']['usage_warn']))->setFieldHint(makeHelpIcon($inherit)))
	->addField((new CWidgetFieldIntegerBoxView($data['fields']['usage_high']))->setFieldHint(makeHelpIcon($inherit)))
	->show();
