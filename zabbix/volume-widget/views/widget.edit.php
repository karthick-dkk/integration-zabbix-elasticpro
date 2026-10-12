<?php declare(strict_types = 0);
/**
 * Decimals left at "From ElasticPro settings" follows the site; the thresholds this report
 * colours by are the plan template's own and are not offered here — see WidgetForm.
 *
 * @var CView $this
 * @var array $data
 */

(new CWidgetFormView($data))
	->addField(new CWidgetFieldMultiSelectGroupView($data['fields']['groupids']))
	->addField(new CWidgetFieldSelectView($data['fields']['decimals']))
	->show();
