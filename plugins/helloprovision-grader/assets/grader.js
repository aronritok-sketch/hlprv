/**
 * HelloProVision Website Grader — frontend.
 * Folyamat: URL → /scan (azonnali eredmény) → /speed (Google PageSpeed, háttérben) → /unlock (e-mail → teljes riport).
 */
(function () {
	'use strict';

	var root = document.querySelector('[data-hpv-grader]');
	var config = window.HPV_GRADER || {};
	if (!root || !config.api) {
		return;
	}

	var el = {
		scan: root.querySelector('[data-hpv-scan]'),
		progress: root.querySelector('[data-hpv-progress]'),
		progressSite: root.querySelector('[data-hpv-progress-site]'),
		results: root.querySelector('[data-hpv-results]'),
		gate: root.querySelector('[data-hpv-gate]'),
		unlock: root.querySelector('[data-hpv-unlock]'),
		cta: root.querySelector('[data-hpv-cta]'),
		ctaNote: root.querySelector('[data-hpv-cta-note]')
	};

	var state = { report: null, token: '', email: '', speedRunning: false };
	var stepTimer = null;

	var STATUS = {
		pass: { icon: '✓', label: 'Passed' },
		warn: { icon: '!', label: 'Needs attention' },
		fail: { icon: '✕', label: 'Failed' }
	};

	/* ── Segédfüggvények ─────────────────────────────── */

	function esc(value) {
		return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function api(path, body) {
		return fetch(config.api.replace(/\/$/, '') + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body)
		}).then(function (res) {
			return res.json().catch(function () {
				return {};
			}).then(function (data) {
				if (!res.ok) {
					throw new Error(data && data.message ? data.message : 'Something went wrong. Please try again.');
				}
				return data;
			});
		});
	}

	function showError(form, message) {
		var box = form.querySelector('.hpv-g-error');
		box.textContent = message || '';
		box.hidden = !message;
	}

	function setBusy(form, busy) {
		var button = form.querySelector('button[type="submit"]');
		button.disabled = busy;
		button.classList.toggle('is-busy', busy);
		form.setAttribute('aria-busy', busy ? 'true' : 'false');
	}

	function tone(score) {
		if (score == null) {
			return 'pending';
		}
		return score >= 75 ? 'good' : score >= 55 ? 'ok' : 'bad';
	}

	function ring(score, size) {
		var r = 44;
		var c = 2 * Math.PI * r;
		var value = score == null ? 0 : Math.max(0, Math.min(100, score));
		return '<svg class="hpv-g-ring hpv-g-ring--' + size + ' is-' + tone(score) + '" viewBox="0 0 100 100" aria-hidden="true">' +
			'<circle class="hpv-g-ring__track" cx="50" cy="50" r="' + r + '"/>' +
			'<circle class="hpv-g-ring__bar" cx="50" cy="50" r="' + r + '" stroke-dasharray="' + c.toFixed(2) + '" stroke-dashoffset="' + (c * (1 - value / 100)).toFixed(2) + '"/>' +
			'</svg>';
	}

	/* ── Folyamatjelző ───────────────────────────────── */

	function startProgress(url) {
		var steps = el.progress.querySelectorAll('li');
		var i = 0;
		el.progressSite.textContent = 'Auditing ' + url.replace(/^https?:\/\//, '').replace(/\/$/, '');
		steps.forEach(function (li) {
			li.className = '';
		});
		steps[0].className = 'is-active';
		el.progress.hidden = false;
		clearInterval(stepTimer);
		stepTimer = setInterval(function () {
			// Az utolsó lépés (sebesség) a /speed hívásnál indul — addig a 4. lépésen várunk.
			if (i < steps.length - 2) {
				steps[i].className = 'is-done';
				i += 1;
				steps[i].className = 'is-active';
			}
		}, 1400);
	}

	function stopProgress() {
		clearInterval(stepTimer);
		el.progress.hidden = true;
	}

	/* ── Megjelenítés ────────────────────────────────── */

	function checkRow(check, unlocked) {
		var s = STATUS[check.status];
		var fix = '';
		if (check.status !== 'pass') {
			if (check.fix) {
				fix = '<div class="hpv-g-fix"><strong>How to fix:</strong> ' + esc(check.fix) + '</div>';
			} else if (check.locked && !unlocked) {
				fix = '<button type="button" class="hpv-g-fix hpv-g-fix--locked" data-hpv-to-gate>' +
					'<span class="hpv-g-fix__blur" aria-hidden="true">Step-by-step fix with the exact setting, code or content change to make on your site.</span>' +
					'<span class="hpv-g-fix__lock">Unlock the fix with your email</span></button>';
			}
		}
		return '<li class="hpv-g-check is-' + check.status + (check.top ? ' is-top' : '') + '">' +
			'<span class="hpv-g-check__icon" role="img" aria-label="' + s.label + '">' + s.icon + '</span>' +
			'<div class="hpv-g-check__body">' +
			'<div class="hpv-g-check__title">' + esc(check.title) + (check.top ? ' <span class="hpv-g-badge">Top priority</span>' : '') + '</div>' +
			'<div class="hpv-g-check__detail">' + esc(check.detail) + '</div>' + fix +
			'</div></li>';
	}

	function render(report) {
		var unlocked = !!report.unlocked;
		var cats = report.categories;
		var keys = Object.keys(config.categories || cats);
		var issues = report.checks.filter(function (c) {
			return c.status !== 'pass';
		}).length;

		var html = '<div class="hpv-g-summary">' +
			'<div class="hpv-g-overall">' + ring(report.overall, 'lg') +
			'<div class="hpv-g-overall__num"><div><span>' + esc(report.overall) + '</span><small>/100</small></div></div></div>' +
			'<div class="hpv-g-summary__text">' +
			'<p class="hpv-g-eyebrow">Your website score</p>' +
			'<h2 class="hpv-g-summary__grade is-' + tone(report.overall) + '">' + esc(report.grade) + '</h2>' +
			'<p class="hpv-g-summary__site">' + esc(report.url) + '</p>' +
			'<p class="hpv-g-summary__meta">' + issues + (issues === 1 ? ' issue' : ' issues') + ' found' +
			(report.categories.speed.pending ? ' · speed test still running' : '') + '</p>' +
			'<button type="button" class="hpv-g-link" data-hpv-again>Audit another website</button>' +
			'</div></div>';

		html += '<div class="hpv-g-tiles">';
		keys.forEach(function (key) {
			var cat = cats[key];
			var n = cat.counts.warn + cat.counts.fail;
			html += '<a class="hpv-g-tile" href="#hpv-g-cat-' + key + '">' +
				'<div class="hpv-g-tile__ring">' + ring(cat.score, 'sm') +
				'<span class="hpv-g-tile__num">' + (cat.pending ? '<span class="hpv-g-spinner" aria-hidden="true"></span>' : (cat.score == null ? '–' : esc(cat.score))) + '</span></div>' +
				'<div class="hpv-g-tile__label">' + esc(cat.label) + '</div>' +
				'<div class="hpv-g-tile__meta">' + (cat.pending ? 'Measuring with Google…' : (n ? n + (n === 1 ? ' issue' : ' issues') : 'All good')) + '</div>' +
				'</a>';
		});
		html += '</div><div data-hpv-slot></div>';

		keys.forEach(function (key) {
			var cat = cats[key];
			var checks = report.checks.filter(function (c) {
				return c.category === key;
			});
			// Előbb a hibák, aztán a figyelmeztetések, végül a rendben lévők.
			var order = { fail: 0, warn: 1, pass: 2 };
			checks.sort(function (a, b) {
				return order[a.status] - order[b.status] || b.weight - a.weight;
			});
			html += '<section class="hpv-g-cat" id="hpv-g-cat-' + key + '">' +
				'<div class="hpv-g-cat__head"><h3>' + esc(cat.label) + '</h3>' +
				'<span class="hpv-g-cat__score is-' + tone(cat.score) + '">' + (cat.pending ? 'Measuring…' : (cat.score == null ? '–' : cat.score + '/100')) + '</span></div>';
			if (cat.pending) {
				html += '<p class="hpv-g-cat__note"><span class="hpv-g-spinner" aria-hidden="true"></span> Google PageSpeed Insights is testing your site on a simulated phone. This takes 20–60 seconds.</p>';
			} else if (key === 'speed' && report.psi_status === 'failed') {
				html += '<p class="hpv-g-cat__note">Google\'s speed test could not be completed right now, so only the server response time is scored. Try again in a few minutes.</p>';
			}
			html += '<ul class="hpv-g-checks">' + checks.map(function (c) {
				return checkRow(c, unlocked);
			}).join('') + '</ul></section>';
		});

		el.results.innerHTML = html;
		el.results.hidden = false;

		// A feloldó űrlap / CTA a csempék alá kerül (a szerver oldali markupot mozgatjuk, hogy a téma gombjai működjenek).
		var slot = el.results.querySelector('[data-hpv-slot]');
		if (unlocked) {
			el.gate.hidden = true;
			el.cta.hidden = false;
			slot.appendChild(el.cta);
		} else {
			el.cta.hidden = true;
			el.gate.hidden = false;
			slot.appendChild(el.gate);
		}
	}

	/* ── Folyamat ─────────────────────────────────────── */

	function runSpeed() {
		if (state.speedRunning || !state.report || !state.report.categories.speed.pending) {
			return;
		}
		state.speedRunning = true;
		var id = state.report.id;
		api('/speed', { id: id, token: state.token }).then(function (report) {
			if (!state.report || state.report.id !== id) {
				return;
			}
			state.speedRunning = false;
			// Ha közben feloldották, a tokennel még egyszer lekérjük, hogy a javaslatok is látszódjanak.
			if (state.token && !report.unlocked) {
				return api('/speed', { id: id, token: state.token }).then(function (full) {
					state.report = full;
					render(full);
				});
			}
			state.report = report;
			render(report);
		}).catch(function () {
			state.speedRunning = false;
		});
	}

	el.scan.addEventListener('submit', function (event) {
		event.preventDefault();
		var input = el.scan.querySelector('input[name="url"]');
		var url = input.value.trim();
		showError(el.scan, '');
		if (!/^(https?:\/\/)?[^\s\/]+\.[a-z]{2,}(\/\S*)?$/i.test(url)) {
			showError(el.scan, 'Enter your website address, like yourbusiness.com.');
			input.focus();
			return;
		}

		setBusy(el.scan, true);
		el.results.hidden = true;
		el.gate.hidden = true;
		el.cta.hidden = true;
		state = { report: null, token: '', email: '', speedRunning: false };
		startProgress(url);

		api('/scan', { url: url }).then(function (report) {
			stopProgress();
			state.report = report;
			render(report);
			el.results.focus({ preventScroll: true });
			el.results.scrollIntoView({ behavior: 'smooth', block: 'start' });
			runSpeed();
		}).catch(function (error) {
			stopProgress();
			showError(el.scan, error.message);
		}).then(function () {
			setBusy(el.scan, false);
		});
	});

	el.unlock.addEventListener('submit', function (event) {
		event.preventDefault();
		var form = el.unlock;
		var field = function (name) {
			return form.elements.namedItem(name);
		};
		var data = {
			id: state.report && state.report.id,
			name: field('name').value.trim(),
			email: field('email').value.trim(),
			business: field('business').value.trim(),
			website: field('website').value,
			consent: field('consent').checked
		};
		showError(form, '');
		if (!data.name || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)) {
			showError(form, 'Please enter your first name and a valid email address.');
			return;
		}
		if (!data.consent) {
			showError(form, 'Please accept the Privacy Policy to receive your report.');
			return;
		}

		setBusy(form, true);
		api('/unlock', data).then(function (report) {
			state.token = report.token;
			state.email = data.email;
			state.report = report;
			el.ctaNote.textContent = 'A copy of the full report is on its way to ' + data.email + '.';
			el.ctaNote.hidden = false;
			render(report);
			el.cta.scrollIntoView({ behavior: 'smooth', block: 'center' });
			// Ha a sebességmérés még fut, a válasza a tokennel frissíti a riportot (runSpeed).
			if (report.categories.speed.pending && !state.speedRunning) {
				runSpeed();
			}
		}).catch(function (error) {
			showError(form, error.message);
		}).then(function () {
			setBusy(form, false);
		});
	});

	root.addEventListener('click', function (event) {
		if (event.target.closest('[data-hpv-to-gate]')) {
			el.gate.scrollIntoView({ behavior: 'smooth', block: 'center' });
			var first = el.unlock.querySelector('input[name="name"]');
			setTimeout(function () {
				first.focus({ preventScroll: true });
			}, 400);
		}
		if (event.target.closest('[data-hpv-again]')) {
			var input = el.scan.querySelector('input[name="url"]');
			input.value = '';
			root.scrollIntoView({ behavior: 'smooth', block: 'start' });
			setTimeout(function () {
				input.focus({ preventScroll: true });
			}, 400);
		}
	});

	// Partner oldalakról érkező link: /website-grader/?site=example.com — kitölti a mezőt.
	var site = new URLSearchParams(window.location.search).get('site');
	if (site) {
		el.scan.querySelector('input[name="url"]').value = site.slice(0, 200);
	}
})();
