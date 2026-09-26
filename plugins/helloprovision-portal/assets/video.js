/**
 * Videóhívás keret (Daily Prebuilt) a CRM-ben és a portálon.
 * window.HPVCallMount(elem, { url, token, owner, dailySrc, onLeft, onError }) → Promise<leállító függvény>
 * A daily-js (275 KB) csak az első hívásnál töltődik be. A leiratot a munkatárs (owner) belépésekor indítjuk.
 */
(function () {
	'use strict';

	var loading = null;

	function loadDaily(src) {
		if (window.Daily) {
			return Promise.resolve(window.Daily);
		}
		if (!loading) {
			loading = new Promise(function (resolve, reject) {
				var s = document.createElement('script');
				s.src = src;
				s.async = true;
				s.onload = function () { window.Daily ? resolve(window.Daily) : reject(new Error('Daily unavailable')); };
				s.onerror = function () { loading = null; reject(new Error('Could not load the video component.')); };
				document.head.appendChild(s);
			});
		}
		return loading;
	}

	window.HPVCallMount = function (el, opts) {
		return loadDaily(opts.dailySrc).then(function (Daily) {
			var frame = Daily.createFrame(el, {
				iframeStyle: { width: '100%', height: '100%', border: '0' },
				showLeaveButton: true,
				showFullscreenButton: true,
			});
			var done = false;
			var destroy = function () {
				if (done) return;
				done = true;
				try { frame.destroy(); } catch (e) { /* már lezárva */ }
			};

			if (opts.owner) {
				// A leirat indítása; ha egy másik munkatárs már elindította, a hibát figyelmen kívül hagyjuk.
				frame.on('joined-meeting', function () {
					try { frame.startTranscription(); } catch (e) { /* nincs jog vagy már fut */ }
				});
			}
			frame.on('left-meeting', function () {
				destroy();
				if (opts.onLeft) opts.onLeft();
			});
			frame.on('error', function (ev) {
				if (opts.onError) opts.onError((ev && ev.errorMsg) || 'Video error');
			});

			return frame.join({ url: opts.url, token: opts.token }).then(function () { return destroy; }, function (err) {
				destroy();
				throw err;
			});
		});
	};
})();
