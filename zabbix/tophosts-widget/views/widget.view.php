<?php declare(strict_types = 0);
/**
 * Top hosts view: the panel, its switch between the metrics a viewer may rank by, and a pane for
 * each of them drawn on the page — a list with bars, a table or a bar chart — of which class.widget.js
 * shows the one chosen, so switching costs no trip to Zabbix.
 *
 * Everything drawn here was decided by the controller (WidgetView, ColumnReport, ColumnCell): the
 * ranking, the text, the level, the colours (strict #RRGGBB, into custom properties only) and the
 * links (Zabbix pages only, built by ColumnMath::url). This file turns those into tags; Zabbix
 * escapes every string.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\EpTophosts\Includes\{ColumnTable, WidgetForm};
use Modules\EpTophosts\Lib\TableOptions;

$view = new CWidgetView($data);
$look = ['toolbar_pos' => TableOptions::TOOLBAR_HOVER, 'density' => TableOptions::DENSITY_COMPACT, 'sticky_header' => 1, 'freeze_first' => 0, 'striped' => 0,
	'row_height' => null, 'group_headers' => 0];
$panel = fn(array $items) => ColumnTable::panel($items, 'tophosts', $look, (string) $data['palette'], ['list', 'table', 'bars'][$data['view']] ?? 'list');

if ($data['error'] !== null) {
	$view->addItem($panel([(new CDiv($data['error']))->addClass('ep-empty')]))->show();
	return;
}

$switch = count($data['metrics']) > 1
	? (new CDiv(array_map(
		fn($m) => (new CSimpleButton($m['label']))->addClass('ep-pbtn')->setAttribute('data-ep-rank', $m['id'])->setAttribute('aria-pressed', $m['id'] === $data['rank'] ? 'true' : 'false'),
		$data['metrics']
	)))->addClass('ep-seg')->setAttribute('role', 'group')->setAttribute('aria-label', _('Rank by'))
	: null;

/** A level, as the class the list and the chart colour by; a column's own colour goes in as custom properties. */
$tone = fn(array $cell) => $cell['level'] !== '' ? 'lv-'.$cell['level'] : null;

$panes = [];
foreach ($data['panes'] as $id => $pane) {
	if (!$pane['rows']) {
		$none = (new CDiv(_s('No server reports %1$s yet.', mb_strtolower($pane['label']))))->addClass('ep-empty');
		$panes[] = (new CDiv($none))->setAttribute('data-ep-pane', $id)->addClass('ep-top-pane')->setAttribute('hidden', $id === $data['rank'] ? null : 'hidden');
		continue;
	}
	switch ($data['view']) {
		case WidgetForm::VIEW_TABLE:
			$body = ColumnTable::table(['columns' => $data['columns'], 'rows' => $pane['rows'], 'totals' => null], $look);
			$body = (new CDiv($body))->addClass('ep-res-scroll');
			break;

		case WidgetForm::VIEW_BARS:
			$rows = [];
			foreach ($pane['rows'] as $r) {
				$v = $r['value'];
				$rows[] = (new CDiv([
					(new CLink($r['host'], $r['url']))->addClass('hn')->setAttribute('title', $r['client_role']),
					(new CDiv((new CTag('i', true, ''))->setAttribute('style', 'width:'.max(0, min(100, (float) ($r['share'] ?? 0))).'%')))->addClass('ep-tc-bar'),
					(new CSpan($v['text']))->addClass('hv')
				]))->addClass('ep-tc-row')->addClass($tone($v))->addClass($v['style'] !== '' ? 'ep-lvc' : null)->setAttribute('style', $v['style'] !== '' ? $v['style'] : null);
			}
			$body = (new CDiv($rows))->addClass('ep-tchart');
			break;

		default:
			$items = [];
			foreach ($pane['rows'] as $r) {
				$v = $r['value'];
				$extra = [];
				foreach ($r['extras'] as $x) {
					$extra[] = (new CSpan([$x['name'].': ', $x['cell']['text']]))->addClass('hx');
				}
				$items[] = (new CTag('li', true, [
					(new CSpan((string) $r['rank']))->addClass('rk'),
					(new CLink($r['host'], $r['url']))->addClass('hn')->setAttribute('title', _s('Latest data for %1$s', $r['host'])),
					(new CSpan($v['text']))->addClass('hv'),
					(new CSpan($r['client_role']))->addClass('hc'),
					$extra ? (new CSpan($extra))->addClass('hxs') : null,
					(new CSpan((new CTag('i', true, ''))->setAttribute('style', 'width:'.max(0, min(100, (float) ($r['share'] ?? 0))).'%')))->addClass('hb')
				]))->addClass($tone($v))->addClass($v['style'] !== '' ? 'ep-lvc' : null)->setAttribute('style', $v['style'] !== '' ? $v['style'] : null);
			}
			$body = (new CTag('ol', true, $items))->addClass('ep-top10');
	}
	$panes[] = (new CDiv($body))->setAttribute('data-ep-pane', $id)->addClass('ep-top-pane')->setAttribute('hidden', $id === $data['rank'] ? null : 'hidden');
}

$view
	->addItem($panel([
		(new CDiv([(new CSpan($data['scope'] !== '' ? $data['scope'] : _('Every client')))->addClass('ep-pt-count'), $switch]))->addClass('ep-pt'),
		(new CDiv([$panes, ColumnTable::dropped($data['dropped'])]))->addClass('ep-pscroll')
	]))
	->show();
