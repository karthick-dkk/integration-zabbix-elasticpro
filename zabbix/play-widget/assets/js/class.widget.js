/*
 * ElasticPro Play in a dashboard widget.
 *
 * The controller sends where to ask for the frame's address (`ep_play`); this asks the ElasticPro
 * module (`elasticpro.play.frame`, POST with this user's CSRF token) and points the frame at the
 * answer: ElasticPro with a one-time code for this Zabbix user, opening `#/play`. A code is asked
 * for each time the frame loads — the first time, when the dashboard page comes back into view,
 * and when Play asks to be signed in again — never kept.
 *
 * Play is a wall display that runs for hours, so a refresh of the widget does not reload it: the
 * frame is kept while the controller's answer is the same. A dashboard page out of view empties
 * its frame (Play stops polling); back in view it signs in afresh.
 *
 * "Sign me in again" (`elasticpro:reauth`, sent by ElasticPro when its session ends) is acted on
 * only from this widget's own frame and ElasticPro's origin, at most three times a minute, as the
 * ElasticPro page does (views/elasticpro.embed.php); a fourth ends in EP-703.
 */
class WidgetEpPlay extends CWidget {

	/** Play fills the widget edge to edge. */
	hasPadding() {
		return false;
	}

	setContents(response) {
		const play = response.ep_play || null;
		const sig = JSON.stringify([response.body ?? '', play]);
		const ep = this._ep || null;

		// Same answer, frame still there: leave Play running.
		if (ep !== null && ep.sig === sig && ep.frame !== null && ep.frame.isConnected) {
			return;
		}

		this._epStop();
		super.setContents(response);

		if (play === null) {
			return;
		}

		this._ep = {
			sig,
			play,
			frame: this._body.querySelector('.ep-play-frame'),
			msg: this._body.querySelector('.ep-play-msg'),
			origin: '',
			retries: [],
			seq: 0,
			blank: false,
			full: false
		};
		this._epListener = this._epListener || ((e) => this._epMessage(e));
		window.addEventListener('message', this._epListener);

		// "Full screen": the browser's full screen of this widget's own frame — same session, no
		// reload. The listeners are on this body's nodes (rebuilt with the body), never inline.
		const full = this._body.querySelector('.ep-play-full');
		const frame = this._ep.frame;

		if (full !== null && frame !== null) {
			full.addEventListener('click', () => WidgetEpPlay.goFullscreen(frame));
		}

		this._epFullListener = this._epFullListener || (() => this._epFullChange());
		document.addEventListener('fullscreenchange', this._epFullListener);
		document.addEventListener('webkitfullscreenchange', this._epFullListener);
		this._epLoad();
	}

	onActivate() {
		if (this._ep && this._ep.blank) {
			this._ep.blank = false;
			this._epLoad();
		}
	}

	onDeactivate() {
		if (this._ep && this._ep.frame !== null) {
			// Page switched away while Play was full screen: leave it first, then blank as usual.
			WidgetEpPlay.leaveFullscreen(this._ep.frame);
			this._ep.seq++;
			this._ep.blank = true;
			this._ep.frame.src = 'about:blank';
		}
	}

	onDestroy() {
		this._epStop();
	}

	_epStop() {
		if (this._epListener) {
			window.removeEventListener('message', this._epListener);
		}
		if (this._epFullListener) {
			document.removeEventListener('fullscreenchange', this._epFullListener);
			document.removeEventListener('webkitfullscreenchange', this._epFullListener);
		}
		if (this._ep && this._ep.frame !== null) {
			this._ep.frame.src = 'about:blank';
		}
		this._ep = null;
	}

	/**
	 * The frame entered or left browser full screen. Nothing is reloaded either way: the frame
	 * keeps its session and its size returns to the widget's. Only the state is kept, so that
	 * leaving the dashboard page (onDeactivate) knows whether there is a full screen to leave.
	 */
	_epFullChange() {
		const ep = this._ep;

		if (ep && ep.frame !== null) {
			ep.full = (document.fullscreenElement || document.webkitFullscreenElement || null) === ep.frame;
		}
	}

	/** Ask the ElasticPro module for a signed-in address, and load it. */
	async _epLoad() {
		const ep = this._ep;

		if (!ep || ep.frame === null) {
			return;
		}

		const seq = ++ep.seq;
		let answer = null;

		try {
			const res = await fetch(ep.play.frame_url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body: new URLSearchParams({[ep.play.csrf_name]: ep.play.csrf}).toString()
			});
			answer = await res.json();
		}
		catch (_) {
			answer = null;
		}

		// Superseded meanwhile: another load, the page went out of view, or the widget was rebuilt.
		if (this._ep !== ep || ep.seq !== seq) {
			return;
		}

		const next = WidgetEpPlay.frameOf(answer);

		if (next.url !== null) {
			ep.origin = next.origin;
			this._epSay(null);
			ep.frame.src = next.url;
		}
		else {
			ep.frame.src = 'about:blank';
			this._epSay(next.error);
		}
	}

	_epMessage(e) {
		const ep = this._ep;

		if (!ep || ep.frame === null || !WidgetEpPlay.isReauth(e, ep.frame.contentWindow, ep.origin)) {
			return;
		}

		const retry = WidgetEpPlay.retry(ep.retries, Date.now());

		ep.retries = retry.times;

		if (!retry.ok) {
			ep.seq++;
			ep.frame.src = 'about:blank';
			this._epSay(WidgetEpPlay.LOST);
			return;
		}

		this._epLoad();
	}

	/** Show a message over the frame (as text, never as markup), or hide it with null. */
	_epSay(error) {
		const ep = this._ep;

		if (!ep || ep.msg === null) {
			return;
		}

		ep.msg.textContent = '';

		if (error === null) {
			ep.msg.hidden = true;
			return;
		}

		const title = document.createElement('b');

		title.textContent = error.title;
		ep.msg.appendChild(title);

		if (error.reason) {
			ep.msg.appendChild(document.createElement('br'));
			ep.msg.appendChild(document.createTextNode(error.reason));
		}

		ep.msg.hidden = false;
	}

	/** Ask the browser for full screen on `frame`. Called from a click; refused or unsupported does nothing. */
	static goFullscreen(frame) {
		const fn = frame.requestFullscreen || frame.webkitRequestFullscreen;

		if (!fn) {
			return;
		}

		try {
			const r = fn.call(frame);

			if (r && typeof r.catch === 'function') {
				r.catch(() => {});
			}
		}
		catch (_) {
			// Refused: Play stays in the widget.
		}
	}

	/** Leave full screen if `frame` is what is full screen. */
	static leaveFullscreen(frame) {
		const doc = frame.ownerDocument;

		if ((doc.fullscreenElement || doc.webkitFullscreenElement || null) !== frame) {
			return;
		}

		try {
			const r = (doc.exitFullscreen || doc.webkitExitFullscreen || (() => {})).call(doc);

			if (r && typeof r.catch === 'function') {
				r.catch(() => {});
			}
		}
		catch (_) {
			// Nothing to do.
		}
	}

	/** EP-703: the session ended four times in a minute; the likely cause is the pairing. */
	static get LOST() {
		return {
			title: 'ElasticPro could not keep you signed in. (EP-703)',
			reason: 'It asked Zabbix to sign you in again three times in a minute, so it stopped retrying. '
				+ 'This almost always means Zabbix and ElasticPro no longer share a pairing secret: '
				+ 'a Zabbix Super admin re-pairs them under Administration → ElasticPro.'
		};
	}

	/**
	 * The frame's address from the ElasticPro module's answer, or what to say instead.
	 *
	 * @returns {{url: ?string, origin: string, error: ?{title: string, reason: string}}}
	 */
	static frameOf(answer) {
		if (answer && typeof answer.url === 'string' && /^https?:\/\//i.test(answer.url)) {
			return {url: answer.url, origin: typeof answer.origin === 'string' ? answer.origin : '', error: null};
		}
		if (answer && answer.error && typeof answer.error === 'object') {
			const code = typeof answer.error.code === 'string' && /^EP-\d{3}$/.test(answer.error.code) ? ` (${answer.error.code})` : '';

			return {url: null, origin: '', error: {
				title: `${String(answer.error.message || 'ElasticPro could not be opened.')}${code}`,
				reason: String(answer.error.reason || '')
			}};
		}
		return {url: null, origin: '', error: {
			title: 'Zabbix did not answer with ElasticPro’s address.',
			reason: 'The ElasticPro module may not be allowed for your Zabbix user role, or your Zabbix session ended. '
				+ 'Open ElasticPro → Play from the menu to see why.'
		}};
	}

	/** A "sign me in again" from this widget's own frame, sent by ElasticPro's origin. */
	static isReauth(e, frameWindow, origin) {
		return !!e && !!frameWindow && e.source === frameWindow && !!origin && e.origin === origin
			&& !!e.data && e.data.type === 'elasticpro:reauth';
	}

	/** At most three sign-ins again a minute. */
	static retry(times, now) {
		const recent = (times || []).filter((t) => now - t < 60000);

		if (recent.length >= 3) {
			return {ok: false, times: recent};
		}

		return {ok: true, times: [...recent, now]};
	}
}
