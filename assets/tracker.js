/* DN Burst Funnel Stats tracker: sends pageview and engagement beacons. */
(function () {
	'use strict';

	var cfg = window.dnbfsPage;

	if (!cfg || !cfg.endpoint || navigator.webdriver) {
		return;
	}

	var VISITOR = 'dnbfs_vid';
	var SESSION = 'dnbfs_sid';
	var META = 'dnbfs_sm';
	var HEX = /^[a-f0-9]{32}$/;

	function decode(value) {
		try {
			return decodeURIComponent(value);
		} catch (e) {
			return value;
		}
	}

	function getCookie(name) {
		var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return match ? decode(match[1]) : '';
	}

	function setCookie(name, value, seconds) {
		var cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + seconds + '; SameSite=Lax';

		if (location.protocol === 'https:') {
			cookie += '; Secure';
		}

		document.cookie = cookie;
	}

	function uid() {
		var bytes = new Uint8Array(16);
		var out = '';

		(window.crypto || window.msCrypto).getRandomValues(bytes);

		for (var i = 0; i < bytes.length; i++) {
			out += ('0' + bytes[i].toString(16)).slice(-2);
		}

		return out;
	}

	function siteDay() {
		return new Date(Date.now() + (cfg.tz || 0) * 60000).toISOString().slice(0, 10);
	}

	function param(name) {
		var match = location.search.match(new RegExp('[?&]' + name + '=([^&]*)'));
		return match ? decode(match[1].replace(/\+/g, ' ')) : '';
	}

	var vid = getCookie(VISITOR);
	if (!HEX.test(vid)) {
		vid = uid();
	}
	setCookie(VISITOR, vid, (cfg.cookieDays || 365) * 86400);

	var timeout = (cfg.timeout || 30) * 60;
	var sid = getCookie(SESSION);
	var rawMeta = getCookie(META);
	var cut = rawMeta.indexOf('~');
	// Split on the first '~' only: campaigns may contain '~'.
	var meta = cut === -1 ? [rawMeta, ''] : [rawMeta.slice(0, cut), rawMeta.slice(cut + 1)];
	var day = siteDay();
	var campaign = param('utm_campaign');

	if (!HEX.test(sid) || meta[0] !== day || (campaign && campaign !== (meta[1] || ''))) {
		sid = uid();
		meta = [day, campaign || (meta[0] === day ? meta[1] || '' : '')];
	}

	function touch() {
		setCookie(SESSION, sid, timeout);
		setCookie(META, meta[0] + '~' + (meta[1] || ''), timeout);
	}

	touch();

	var pvid = 0;
	var engaged = 0;
	var visibleSince = null;
	var started = false;

	function send(body, wantResponse) {
		var json = JSON.stringify(body);

		if (wantResponse && window.fetch) {
			return fetch(cfg.endpoint, {
				method: 'POST',
				body: json,
				keepalive: true,
				credentials: 'same-origin',
				headers: { 'Content-Type': 'text/plain' }
			}).then(function (response) {
				return response.status === 200 ? response.json() : null;
			}).catch(function () {
				return null;
			});
		}

		if (navigator.sendBeacon) {
			navigator.sendBeacon(cfg.endpoint, new Blob([json], { type: 'text/plain' }));
		} else if (window.fetch) {
			fetch(cfg.endpoint, { method: 'POST', body: json, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' } });
		}

		return null;
	}

	function engagedSeconds() {
		var total = engaged;

		if (visibleSince !== null) {
			total += (Date.now() - visibleSince) / 1000;
		}

		return Math.min(1800, Math.round(total));
	}

	function ping() {
		if (!pvid) {
			return;
		}

		touch();
		send({ t: 'ping', vid: vid, sid: sid, pvid: pvid, engaged: engagedSeconds() }, false);
	}

	function pauseClock() {
		if (visibleSince !== null) {
			engaged += (Date.now() - visibleSince) / 1000;
			visibleSince = null;
		}
	}

	function start() {
		visibleSince = Date.now();

		var request = send({
			t: 'pv',
			vid: vid,
			sid: sid,
			path: location.pathname,
			query: location.search,
			ref: document.referrer,
			ptype: cfg.type || 'other',
			pid: cfg.id || 0,
			sw: (window.screen && screen.width) || 0
		}, true);

		if (request) {
			request.then(function (data) {
				if (data && data.pvid) {
					pvid = data.pvid;
				}
			});
		}

		setInterval(function () {
			if (document.visibilityState === 'visible') {
				ping();
			}
		}, 60000);
	}

	function onVisibilityChange() {
		if (document.visibilityState === 'visible') {
			if (!started) {
				started = true;
				start();
			} else if (visibleSince === null) {
				visibleSince = Date.now();
			}
		} else if (visibleSince !== null) {
			pauseClock();
			ping();
		}
	}

	document.addEventListener('visibilitychange', onVisibilityChange);
	window.addEventListener('pagehide', function () {
		// The visibilitychange->hidden ping already went out if the clock is paused.
		if (visibleSince !== null) {
			pauseClock();
			ping();
		}
	});

	onVisibilityChange();
})();
