<?php declare(strict_types = 0);
/**
 * Top hosts settings. Client: a client's name as Cluster Management shows it, empty for every
 * client. A threshold of 0 follows ElasticPro -> Settings, as in the other ElasticPro reports.
 *
 * @var CView $this
 * @var array $data
 */

$inherit = _('0: follow ElasticPro → Settings, where this is set for every report at once. A figure here applies to this widget alone.');

(new CWidgetFormView($data))
	->addField(new CWidgetFieldMultiSelectGroupView($data['fields']['groupids']))
	->addField((new CWidgetFieldTextBoxView($data['fields']['client']))->setFieldHint(makeHelpIcon(_('A client\'s name as ElasticPro → Clients shows it. Empty: every client.'))))
	->addField(new CWidgetFieldSelectView($data['fields']['client_type']))
	->addField(new CWidgetFieldSelectView($data['fields']['rank']))
	->addField(new CWidgetFieldIntegerBoxView($data['fields']['count']))
	->addField((new CWidgetFieldIntegerBoxView($data['fields']['usage_warn']))->setFieldHint(makeHelpIcon($inherit)))
	->addField((new CWidgetFieldIntegerBoxView($data['fields']['usage_high']))->setFieldHint(makeHelpIcon($inherit)))
	->show();
