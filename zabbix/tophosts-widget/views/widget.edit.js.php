<?php declare(strict_types = 0);
/**
 * Top hosts' form script: the shared Columns behaviour (columns.form.js.php, included before this),
 * started as this widget's own, and the item key row showing only when Rank by is an item.
 *
 * @var CView $this
 */

use Modules\EpTophosts\Includes\Catalogue;
?>

window.widget_ep_tophosts_form = new class extends window.EpColumnsForm {

	init(params) {
		super.init(params);
		const rank = this._form.querySelector('[name="rank"]');
		const sync = () => {
			const item = rank !== null && rank.value === '<?= array_search('item', Catalogue::RANKS, true) ?>';
			this._form.querySelectorAll('.ep-top-itemrow').forEach((el) => el.style.display = item ? '' : 'none');
		};
		this._form.addEventListener('change', (e) => {
			if (e.target.name === 'rank') {
				sync();
			}
		});
		sync();
	}
};
