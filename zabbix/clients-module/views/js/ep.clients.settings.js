/*
 * ElasticPro -> Settings, the Colours card: each colour's swatch and its hex box kept together,
 * a box that is not six hex digits marked as it is typed, what reads on each colour worked out
 * again as it changes (white, or dark text below 4.5:1 — Settings::textOn() is the rule, and the
 * one Save applies; this only shows it early), the preview, and Reset to defaults, which fills in
 * the shipped colours for Save to keep.
 */
(() => {
	const card = document.getElementById('ep-palette');
	if (!card) return;
	const min = Number(card.dataset.min) || 4.5;
	const dark = card.dataset.darkText || '#111217';
	const backgrounds = JSON.parse(card.dataset.backgrounds || '{}');
	const defaults = JSON.parse(card.dataset.defaults || '{}');
	const hex = (v) => (/^#?([0-9a-f]{6})$/i.exec(String(v).trim()) || [])[1];
	const lum = (h) => {
		const c = [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16) / 255).map((x) => (x <= 0.03928 ? x / 12.92 : ((x + 0.055) / 1.055) ** 2.4));
		return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
	};
	const contrast = (a, b) => { const [x, y] = [lum(a), lum(b)]; return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
	const fmt = (r) => (Math.floor(r * 10) / 10).toFixed(1);
	const value = (theme, key) => { const el = card.querySelector(`[name="palette[${theme}][${key}]"]`); return el ? hex(el.value) : undefined; };

	const check = (theme, key) => {
		const out = card.querySelector(`[data-ep-check="${theme}:${key}"]`);
		const h = value(theme, key);
		if (!out || !h) return;
		let text, bad;
		if (key === 'link') {
			const r = contrast(h, hex(backgrounds[theme] || '#ffffff'));
			text = `on the page ${fmt(r)}:1`;
			bad = r < min;
		}
		else {
			const white = contrast(h, 'ffffff');
			const d = contrast(h, hex(dark));
			bad = white < min;
			const useDark = bad && d >= white;
			text = `${useDark ? 'dark text' : 'white'} ${fmt(useDark ? d : white)}:1`;
		}
		out.className = bad ? 'ep-pal-bad' : 'ep-pal-ok';
		out.textContent = '';
		const label = document.createElement('span');
		label.className = 'ep-pal-th';
		label.textContent = theme === 'light' ? 'Blue' : 'Dark';
		out.append(label, ` ${text} ${bad ? '⚠' : '✓'}`);
	};

	const preview = () => {
		const box = card.querySelector('[data-ep-preview]');
		if (!box) return;
		const theme = /dark/.test(document.documentElement.getAttribute('theme') || '') ? 'dark' : 'light';
		for (const key of ['ok', 'warn', 'high', 'unknown', 'free']) {
			const h = value(theme, key);
			if (!h) continue;
			box.style.setProperty(`--pv-${key}`, `#${h}`);
			const white = contrast(h, 'ffffff');
			box.style.setProperty(`--pv-${key}-fg`, white >= min || contrast(h, hex(dark)) < white ? '#ffffff' : dark);
		}
	};

	card.addEventListener('input', (e) => {
		const t = e.target;
		if (t.matches('[data-ep-hex]')) {
			const h = hex(t.value);
			t.classList.toggle('ep-pal-wrong', !h);
			t.setAttribute('aria-invalid', h ? 'false' : 'true');
			const sw = card.querySelector(`[data-ep-swatch="${CSS.escape(t.name)}"]`);
			if (h && sw) sw.value = `#${h.toLowerCase()}`;
		}
		else if (t.matches('[data-ep-swatch]')) {
			const box = card.querySelector(`[name="${CSS.escape(t.dataset.epSwatch)}"]`);
			if (box) { box.value = t.value; box.classList.remove('ep-pal-wrong'); }
		}
		const m = /^palette\[(light|dark)\]\[(\w+)\]$/.exec(t.name || t.dataset.epSwatch || '');
		if (m) check(m[1], m[2]);
		preview();
	});

	const reset = card.querySelector('[data-ep-pal-reset]');
	if (reset) {
		reset.addEventListener('click', () => {
			for (const theme of ['light', 'dark']) {
				for (const [key, c] of Object.entries(defaults[theme] || {})) {
					const box = card.querySelector(`[name="palette[${theme}][${key}]"]`);
					const sw = card.querySelector(`[data-ep-swatch="palette[${theme}][${key}]"]`);
					if (box) { box.value = c; box.classList.remove('ep-pal-wrong'); }
					if (sw) sw.value = c;
					check(theme, key);
				}
			}
			(defaults.series || []).forEach((c, i) => {
				const el = card.querySelector(`[name="palette[series][${i}]"]`);
				if (el) el.value = c;
			});
			preview();
		});
	}
	preview();
})();
