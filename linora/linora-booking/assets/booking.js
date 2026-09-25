/**
 * Linora időpontfoglaló konfigurátor.
 *
 * Nem → testtáj → terület → alkalmak → időpont → összegzés (+ újabb terület) → adatok → WooCommerce pénztár.
 * Every block instance on the page (homepage section, header modal) shares one basket,
 * kept in sessionStorage until the order is placed.
 */
(function () {
	'use strict';

	var DATA = window.lnrBookingData;
	if (!DATA || !DATA.catalog) return;

	var STORAGE_KEY = 'lnrBooking';
	var MONTHS = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
	var WEEKDAYS = ['vasárnap', 'hétfő', 'kedd', 'szerda', 'csütörtök', 'péntek', 'szombat'];
	var WEEKDAYS_SHORT = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
	var STEPS = [
		{ key: 'area', label: 'Terület' },
		{ key: 'option', label: 'Alkalmak' },
		{ key: 'date', label: 'Időpont' },
		{ key: 'summary', label: 'Összegzés' },
		{ key: 'details', label: 'Adatok' }
	];
	var STEP_OF = { gender: 0, group: 0, area: 0, option: 1, date: 2, summary: 3, details: 4 };

	/* ---------- helpers ---------- */

	function esc(value) {
		return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function price(value) {
		return String(Math.round(value)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft';
	}

	function minutes(seconds) {
		var total = Math.round(seconds / 60);
		var hours = Math.floor(total / 60);
		var rest = total % 60;
		if (!hours) return rest + ' perc';
		return hours + ' óra' + (rest ? ' ' + rest + ' perc' : '');
	}

	function optionLabel(n) {
		return n > 1 ? n + ' alkalmas bérlet' : '1 alkalom';
	}

	function pad(n) {
		return (n < 10 ? '0' : '') + n;
	}

	// Dates are wall-clock times of the salon; they are compared as such, never converted.
	function stamp(date, time) {
		var d = date.split('-'), t = (time || '00:00').split(':');
		return Date.UTC(+d[0], d[1] - 1, +d[2], +t[0], +t[1]);
	}

	function parseDate(date) {
		var d = date.split('-');
		return new Date(Date.UTC(+d[0], d[1] - 1, +d[2]));
	}

	function dateLabel(date, withWeekday) {
		var d = parseDate(date);
		return d.getUTCFullYear() + '. ' + MONTHS[d.getUTCMonth()] + ' ' + d.getUTCDate() + '.' +
			(withWeekday ? ', ' + WEEKDAYS[d.getUTCDay()] : '');
	}

	function monthKey(date) {
		return date.slice(0, 7);
	}

	function shiftMonth(month, delta) {
		var p = month.split('-');
		var d = new Date(Date.UTC(+p[0], p[1] - 1 + delta, 1));
		return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1);
	}

	function uid() {
		return Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
	}

	/* ---------- catalog ---------- */

	var catalog = DATA.catalog;

	function findGender(key) {
		for (var i = 0; i < catalog.genders.length; i++) {
			if (catalog.genders[i].key === key) return catalog.genders[i];
		}
		return null;
	}

	function findGroup(gender, key) {
		if (!gender) return null;
		for (var i = 0; i < gender.groups.length; i++) {
			if (gender.groups[i].key === key) return gender.groups[i];
		}
		return null;
	}

	function lookup(serviceId, packageId) {
		for (var g = 0; g < catalog.genders.length; g++) {
			var gender = catalog.genders[g];
			for (var r = 0; r < gender.groups.length; r++) {
				var group = gender.groups[r];
				for (var i = 0; i < group.items.length; i++) {
					var item = group.items[i];
					if (item.id !== serviceId) continue;
					for (var o = 0; o < item.options.length; o++) {
						if ((item.options[o].packageId || null) === (packageId || null)) {
							return { gender: gender, group: group, item: item, option: item.options[o] };
						}
					}
				}
			}
		}
		return null;
	}

	/* ---------- basket (shared by all instances) ---------- */

	var store = {
		items: [],
		customer: { lastName: '', firstName: '', email: '', phone: '' },
		listeners: [],

		load: function () {
			try {
				var saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || 'null');
				if (saved) {
					this.items = (saved.items || []).filter(function (item) {
						return lookup(item.serviceId, item.packageId) && stamp(item.start.slice(0, 10), item.start.slice(11)) > stamp(DATA.now.slice(0, 10), DATA.now.slice(11));
					});
					for (var key in this.customer) {
						if (saved.customer && typeof saved.customer[key] === 'string') this.customer[key] = saved.customer[key];
					}
				}
			} catch (e) { /* storage unavailable: the basket just lives in memory */ }
		},

		save: function () {
			try {
				sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ items: this.items, customer: this.customer }));
			} catch (e) { /* ignore */ }
		},

		changed: function (source) {
			this.save();
			this.listeners.forEach(function (listener) {
				if (listener !== source) listener.onStoreChange();
			});
		},

		sorted: function () {
			return this.items.slice().sort(function (a, b) {
				return a.start < b.start ? -1 : a.start > b.start ? 1 : 0;
			});
		},

		get: function (id) {
			for (var i = 0; i < this.items.length; i++) {
				if (this.items[i].uid === id) return this.items[i];
			}
			return null;
		},

		conflicts: function (date, time, duration, ignoreUid) {
			var begin = stamp(date, time), end = begin + duration * 1000;
			return this.items.some(function (item) {
				if (item.uid === ignoreUid) return false;
				var found = lookup(item.serviceId, item.packageId);
				var itemBegin = stamp(item.start.slice(0, 10), item.start.slice(11));
				var itemEnd = itemBegin + (found ? found.item.duration : 0) * 1000;
				return begin < itemEnd && itemBegin < end;
			});
		}
	};

	/* ---------- free times ---------- */

	var slotCache = {};

	function loadSlots(serviceId, month) {
		var key = serviceId + '|' + month;
		if (!slotCache[key]) {
			var url = DATA.ajaxUrl + '?action=lnr_booking_slots&service=' + serviceId + '&month=' + month;
			slotCache[key] = fetch(url, { credentials: 'same-origin' })
				.then(function (response) { return response.json(); })
				.then(function (json) {
					if (!json || !json.success) throw new Error(json && json.data && json.data.message);
					return json.data.slots || {};
				})
				.catch(function (error) {
					delete slotCache[key];
					throw error;
				});
		}
		return slotCache[key];
	}

	/* ---------- one configurator ---------- */

	function Configurator(root) {
		this.root = root;
		this.presetGender = root.getAttribute('data-gender') || '';
		this.state = {};
		this.touched = false;
		this.reset(store.items.length ? 'summary' : null);
		this.bind();
		this.render(false);
	}

	Configurator.prototype.reset = function (step) {
		var gender = this.presetGender && findGender(this.presetGender) ? this.presetGender : '';
		if (!gender && catalog.genders.length === 1) gender = catalog.genders[0].key;

		this.state = {
			step: step || (gender ? 'group' : 'gender'),
			gender: gender,
			group: '',
			serviceId: 0,
			packageId: null,
			editing: null,
			month: '',
			date: '',
			time: '',
			providerId: 0,
			slots: null,
			slotsError: '',
			autoAdvance: 0,
			error: '',
			errorUid: '',
			busy: false
		};
	};

	Configurator.prototype.onStoreChange = function () {
		// An instance nobody has used yet (e.g. the closed header modal) follows the basket.
		if (!this.touched || (!store.items.length && (this.state.step === 'summary' || this.state.step === 'details'))) {
			this.reset(store.items.length ? 'summary' : null);
			this.render(false);
			return;
		}
		if (this.state.step === 'summary' || this.state.step === 'date') this.render(false);
	};

	Configurator.prototype.go = function (step, patch) {
		for (var key in patch || {}) this.state[key] = patch[key];
		this.state.step = step;
		if (step !== 'summary' && step !== 'details') {
			this.state.error = '';
			this.state.errorUid = '';
		}
		if (step === 'date') this.openCalendar();
		this.render(true);
	};

	Configurator.prototype.bind = function () {
		var self = this;

		this.root.addEventListener('click', function (event) {
			var target = event.target.closest('[data-action]');
			if (!target || !self.root.contains(target) || target.disabled) return;
			event.preventDefault();
			self.touched = true;
			self.action(target.getAttribute('data-action'), target.getAttribute('data-value'));
		});

		this.root.addEventListener('input', function (event) {
			var name = event.target.getAttribute('data-field');
			if (name) {
				store.customer[name] = event.target.value;
				store.save();
			}
		});

		this.root.addEventListener('submit', function (event) {
			event.preventDefault();
			self.touched = true;
			self.submit();
		});
	};

	Configurator.prototype.action = function (action, value) {
		var s = this.state;

		switch (action) {
			case 'back':
				this.back();
				break;
			case 'gender':
				this.go('group', { gender: value, group: '' });
				break;
			case 'group':
				this.go('area', { group: value });
				break;
			case 'area':
				this.go('option', { serviceId: +value, packageId: null });
				break;
			case 'option':
				this.go('date', { packageId: value ? +value : null, date: '', time: '' });
				break;
			case 'month':
				s.month = shiftMonth(s.month, +value);
				s.date = '';
				s.time = '';
				s.autoAdvance = 0;
				this.fetchMonth();
				break;
			case 'day':
				s.date = value;
				s.time = '';
				this.render(false);
				break;
			case 'time':
				var parts = value.split('|');
				s.time = parts[0];
				s.providerId = +parts[1];
				this.render(false);
				break;
			case 'confirm-time':
				this.confirmTime();
				break;
			case 'edit':
				var item = store.get(value);
				var found = item && lookup(item.serviceId, item.packageId);
				if (!found) return;
				this.go('date', {
					editing: item.uid,
					gender: found.gender.key,
					group: found.group.key,
					serviceId: item.serviceId,
					packageId: item.packageId,
					date: item.start.slice(0, 10),
					time: item.start.slice(11),
					providerId: item.providerId
				});
				break;
			case 'remove':
				store.items = store.items.filter(function (i) { return i.uid !== value; });
				if (s.errorUid === value) {
					s.error = '';
					s.errorUid = '';
				}
				store.changed(this);
				if (!store.items.length) this.reset();
				this.render(true);
				break;
			case 'add-more':
				var last = store.sorted()[store.items.length - 1];
				var lastFound = last && lookup(last.serviceId, last.packageId);
				this.reset();
				if (lastFound) this.state.gender = lastFound.gender.key;
				this.go(this.state.gender ? 'group' : 'gender');
				break;
			case 'details':
				this.go('details');
				break;
		}
	};

	Configurator.prototype.back = function () {
		var s = this.state;
		switch (s.step) {
			case 'group': this.go('gender'); break;
			case 'area': this.go('group'); break;
			case 'option': this.go('area'); break;
			case 'date':
				if (s.editing) this.go('summary', { editing: null });
				else this.go('option');
				break;
			case 'details': this.go('summary'); break;
			default:
				// From the first steps "back" returns to the basket when there is one.
				if (store.items.length) this.go('summary');
		}
	};

	Configurator.prototype.current = function () {
		return lookup(this.state.serviceId, this.state.packageId);
	};

	Configurator.prototype.openCalendar = function () {
		var s = this.state;
		var last = store.sorted()[store.items.length - 1];
		var start = s.date || (last ? last.start.slice(0, 10) : DATA.today);
		if (start < DATA.today) start = DATA.today;
		s.month = monthKey(start);
		s.autoAdvance = s.date ? 0 : 6;
		this.fetchMonth();
	};

	Configurator.prototype.fetchMonth = function () {
		var self = this, s = this.state;
		var serviceId = s.serviceId, month = s.month;

		s.slots = null;
		s.slotsError = '';
		this.render(false);

		loadSlots(serviceId, month).then(function (slots) {
			if (s.serviceId !== serviceId || s.month !== month || s.step !== 'date') return;
			s.slots = slots;
			// Nothing free this month: look ahead a few months on first opening.
			if (s.autoAdvance > 0 && !self.availableDays().length) {
				s.autoAdvance--;
				s.month = shiftMonth(s.month, 1);
				self.fetchMonth();
				return;
			}
			s.autoAdvance = 0;
			if (!s.date) {
				var days = self.availableDays();
				if (days.length) s.date = days[0];
			}
			self.render(false);
		}, function (error) {
			if (s.serviceId !== serviceId || s.month !== month) return;
			s.slots = {};
			s.slotsError = (error && error.message) || 'Az időpontokat most nem sikerült betölteni.';
			self.render(false);
		});
	};

	Configurator.prototype.timesOf = function (date) {
		var s = this.state, found = this.current(), times = [];
		var day = s.slots && s.slots[date];
		if (!day || !found) return times;
		Object.keys(day).sort().forEach(function (time) {
			if (!store.conflicts(date, time, found.item.duration, s.editing)) {
				times.push({ time: time, providerId: day[time] });
			}
		});
		return times;
	};

	Configurator.prototype.availableDays = function () {
		var self = this;
		return Object.keys(this.state.slots || {}).sort().filter(function (date) {
			return monthKey(date) === self.state.month && self.timesOf(date).length > 0;
		});
	};

	Configurator.prototype.confirmTime = function () {
		var s = this.state;
		if (!s.date || !s.time || !s.providerId) return;

		var start = s.date + ' ' + s.time;
		var existing = s.editing && store.get(s.editing);
		if (existing) {
			existing.start = start;
			existing.providerId = s.providerId;
		} else {
			store.items.push({
				uid: uid(),
				serviceId: s.serviceId,
				packageId: s.packageId,
				start: start,
				providerId: s.providerId
			});
		}
		if (s.errorUid === s.editing) {
			s.error = '';
			s.errorUid = '';
		}
		store.changed(this);
		this.go('summary', { editing: null });
	};

	Configurator.prototype.submit = function () {
		var self = this, s = this.state;
		if (s.busy) return;

		var form = this.root.querySelector('form');
		if (form && !form.reportValidity()) return;

		s.busy = true;
		s.error = '';
		this.render(false);

		var body = new FormData();
		body.append('action', 'lnr_booking_checkout');
		body.append('payload', JSON.stringify({
			customer: store.customer,
			items: store.items.map(function (item) {
				return {
					uid: item.uid,
					serviceId: item.serviceId,
					packageId: item.packageId,
					start: item.start,
					providerId: item.providerId
				};
			})
		}));

		fetch(DATA.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (response) { return response.json(); })
			.then(function (json) {
				if (json && json.success && json.data.redirect) {
					window.location.href = json.data.redirect;
					return;
				}
				var data = (json && json.data) || {};
				s.busy = false;
				s.error = data.message || 'A foglalást most nem sikerült elküldeni. Kérjük, próbálja újra.';
				s.errorUid = data.uid || '';
				// A problem with one of the items is fixed in the basket.
				if (s.errorUid && store.get(s.errorUid)) {
					slotCache = {};
					s.step = 'summary';
				}
				self.render(true);
			})
			.catch(function () {
				s.busy = false;
				s.error = 'A foglalást most nem sikerült elküldeni. Kérjük, ellenőrizze az internetkapcsolatot, és próbálja újra.';
				self.render(true);
			});
	};

	/* ---------- rendering ---------- */

	Configurator.prototype.render = function (moved) {
		var s = this.state;
		var html = '<div class="lnr-booking__inner">' + this.renderSteps() + '<div class="lnr-booking__body">';

		switch (s.step) {
			case 'gender': html += this.renderGender(); break;
			case 'group': html += this.renderGroup(); break;
			case 'area': html += this.renderArea(); break;
			case 'option': html += this.renderOption(); break;
			case 'date': html += this.renderDate(); break;
			case 'summary': html += this.renderSummary(); break;
			case 'details': html += this.renderDetails(); break;
		}

		html += '</div></div>';
		this.root.innerHTML = html;

		if (moved) {
			var title = this.root.querySelector('.lnr-booking__title');
			if (title) title.focus({ preventScroll: true });
			var top = this.root.getBoundingClientRect().top;
			if (top < 0) this.root.scrollIntoView({ block: 'start', behavior: 'smooth' });
		}
	};

	Configurator.prototype.renderSteps = function () {
		var current = STEP_OF[this.state.step];
		return '<ol class="lnr-booking__steps" aria-label="Foglalás lépései">' + STEPS.map(function (step, index) {
			var cls = index < current ? ' is-done' : index === current ? ' is-current' : '';
			return '<li class="lnr-booking__step' + cls + '"' + (index === current ? ' aria-current="step"' : '') + '>' +
				'<span class="lnr-booking__step-dot">' + (index + 1) + '</span>' +
				'<span class="lnr-booking__step-label">' + esc(step.label) + '</span></li>';
		}).join('') + '</ol>';
	};

	Configurator.prototype.head = function (title, text, canGoBack) {
		return '<div class="lnr-booking__head">' +
			(canGoBack ? '<button type="button" class="lnr-booking__back" data-action="back">‹ Vissza</button>' : '') +
			'<h4 class="lnr-booking__title" tabindex="-1">' + esc(title) + '</h4>' +
			(text ? '<p class="lnr-booking__lead">' + text + '</p>' : '') +
			'</div>';
	};

	Configurator.prototype.renderGender = function () {
		return this.head('Kinek szól a kezelés?', 'Diódalézeres szőrtelenítés – válassza ki a kezelés típusát.', store.items.length > 0) +
			'<div class="lnr-booking__choices lnr-booking__choices--2">' +
			catalog.genders.map(function (gender) {
				return '<button type="button" class="lnr-booking__choice lnr-booking__choice--big" data-action="gender" data-value="' + esc(gender.key) + '">' +
					'<span class="lnr-booking__choice-title">' + esc(gender.label) + ' kezelések</span>' +
					'<span class="lnr-booking__choice-meta">' + gender.groups.reduce(function (sum, group) { return sum + group.items.length; }, 0) + ' kezelési terület</span>' +
					'</button>';
			}).join('') + '</div>';
	};

	Configurator.prototype.renderGroup = function () {
		var gender = findGender(this.state.gender);
		if (!gender) return this.renderGender();
		var canGoBack = catalog.genders.length > 1 || store.items.length > 0;

		return this.head('Melyik testtájat kezeljük?', esc(gender.label) + ' diódalézeres szőrtelenítés', canGoBack) +
			'<div class="lnr-booking__choices lnr-booking__choices--2">' +
			gender.groups.map(function (group) {
				var names = group.items.slice(0, 3).map(function (item) { return item.name; }).join(', ');
				var from = Math.min.apply(null, group.items.map(function (item) { return item.options[0].price; }));
				return '<button type="button" class="lnr-booking__choice" data-action="group" data-value="' + esc(group.key) + '">' +
					'<span class="lnr-booking__choice-title">' + esc(group.label) + '</span>' +
					'<span class="lnr-booking__choice-meta">' + esc(names) + (group.items.length > 3 ? '…' : '') + '</span>' +
					'<span class="lnr-booking__choice-price">' + price(from) + '-tól</span>' +
					'</button>';
			}).join('') + '</div>';
	};

	Configurator.prototype.renderArea = function () {
		var gender = findGender(this.state.gender);
		var group = findGroup(gender, this.state.group);
		if (!group) return this.renderGroup();

		return this.head(group.label, esc(gender.label) + ' kezelések · válassza ki a területet', true) +
			'<ul class="lnr-booking__list">' +
			group.items.map(function (item) {
				return '<li><button type="button" class="lnr-booking__row" data-action="area" data-value="' + item.id + '">' +
					'<span class="lnr-booking__row-name">' + esc(item.name) + '</span>' +
					'<span class="lnr-booking__row-meta">' + minutes(item.duration) + '</span>' +
					'<span class="lnr-booking__row-price">' + price(item.options[0].price) + '</span>' +
					'</button></li>';
			}).join('') + '</ul>';
	};

	Configurator.prototype.renderOption = function () {
		var found = lookup(this.state.serviceId, null);
		if (!found) return this.renderArea();
		var base = found.item.options[0].price;

		return this.head(found.item.name, esc(found.gender.label) + ' · ' + esc(found.group.label) + ' · hány alkalmat szeretne?', true) +
			'<div class="lnr-booking__choices">' +
			found.item.options.map(function (option) {
				var perVisit = option.price / option.n;
				var saving = Math.round((1 - option.price / (base * option.n)) * 100);
				return '<button type="button" class="lnr-booking__choice lnr-booking__choice--option" data-action="option" data-value="' + (option.packageId || '') + '">' +
					'<span class="lnr-booking__choice-title">' + optionLabel(option.n) + '</span>' +
					(saving > 0 ? '<span class="lnr-booking__badge">' + saving + '% kedvezmény</span>' : '') +
					'<span class="lnr-booking__choice-price">' + price(option.price) + '</span>' +
					'<span class="lnr-booking__choice-meta">' + (option.n > 1 ? price(perVisit) + ' / alkalom' : 'egyszeri kezelés') + '</span>' +
					'</button>';
			}).join('') + '</div>' +
			'<p class="lnr-booking__note">A tartós eredményhez általában több kezelés szükséges. Bérletnél most az első alkalom időpontját foglalja le, a további alkalmakat a kezelések során egyeztetjük.</p>';
	};

	Configurator.prototype.renderDate = function () {
		var s = this.state, found = this.current();
		if (!found) return this.renderOption();

		var html = this.head('Mikor jönne?', esc(found.item.name) + ' · ' + optionLabel(found.option.n) + ' · ' + minutes(found.item.duration) +
			(found.option.n > 1 ? '<br>Az első alkalom időpontja' : ''), true);

		var p = s.month.split('-');
		var canPrev = s.month > monthKey(DATA.today);
		html += '<div class="lnr-booking__calendar">' +
			'<div class="lnr-booking__month">' +
			'<button type="button" class="lnr-booking__nav" data-action="month" data-value="-1" aria-label="Előző hónap"' + (canPrev ? '' : ' disabled') + '>‹</button>' +
			'<span>' + p[0] + '. ' + MONTHS[p[1] - 1] + '</span>' +
			'<button type="button" class="lnr-booking__nav" data-action="month" data-value="1" aria-label="Következő hónap">›</button>' +
			'</div>';

		if (!s.slots) {
			return html + '<div class="lnr-booking__loading" role="status">Szabad időpontok betöltése…</div></div>';
		}

		var available = {};
		this.availableDays().forEach(function (date) { available[date] = true; });

		html += '<div class="lnr-booking__grid" role="grid">' + WEEKDAYS_SHORT.map(function (day) {
			return '<span class="lnr-booking__weekday">' + day + '</span>';
		}).join('');

		var first = parseDate(s.month + '-01');
		var offset = (first.getUTCDay() + 6) % 7;
		var days = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();
		for (var i = 0; i < offset; i++) html += '<span></span>';
		for (var d = 1; d <= days; d++) {
			var date = s.month + '-' + pad(d);
			var cls = 'lnr-booking__day' + (date === s.date ? ' is-selected' : '') + (date === DATA.today ? ' is-today' : '');
			html += available[date]
				? '<button type="button" class="' + cls + '" data-action="day" data-value="' + date + '" aria-label="' + esc(dateLabel(date, true)) + '"' + (date === s.date ? ' aria-pressed="true"' : '') + '>' + d + '</button>'
				: '<span class="' + cls + ' is-off">' + d + '</span>';
		}
		html += '</div>';

		if (s.slotsError) {
			html += '<p class="lnr-booking__error" role="alert">' + esc(s.slotsError) + '</p>';
		} else if (!Object.keys(available).length) {
			html += '<p class="lnr-booking__empty">Ebben a hónapban nincs szabad időpont. Nézze meg a következő hónapot.</p>';
		}
		html += '</div>';

		if (s.date && available[s.date]) {
			html += '<div class="lnr-booking__times"><p class="lnr-booking__times-title">' + esc(dateLabel(s.date, true)) + '</p><div class="lnr-booking__time-list">' +
				this.timesOf(s.date).map(function (slot) {
					var selected = slot.time === s.time;
					return '<button type="button" class="lnr-booking__time' + (selected ? ' is-selected' : '') + '" data-action="time" data-value="' + slot.time + '|' + slot.providerId + '"' + (selected ? ' aria-pressed="true"' : '') + '>' + slot.time + '</button>';
				}).join('') + '</div></div>';
		}

		html += '<div class="lnr-booking__actions">' +
			'<button type="button" class="iu-button iu-button-default lnr-booking__primary" data-action="confirm-time"' + (s.date && s.time ? '' : ' disabled') + '><span class="iu-button-text"><span>' +
			(s.editing ? 'Időpont mentése' : 'Tovább az összegzéshez') + '</span></span></button></div>';

		return html;
	};

	Configurator.prototype.renderSummary = function () {
		var s = this.state, total = 0, hasPass = false;
		var items = store.sorted();

		var html = this.head('Az Ön kezelései', 'Ellenőrizze a kiválasztott kezeléseket és időpontokat.', false);

		if (s.error) html += '<p class="lnr-booking__error" role="alert">' + esc(s.error) + '</p>';

		html += '<ul class="lnr-booking__basket">' + items.map(function (item) {
			var found = lookup(item.serviceId, item.packageId);
			if (!found) return '';
			total += found.option.price;
			hasPass = hasPass || found.option.n > 1;
			return '<li class="lnr-booking__item' + (item.uid === s.errorUid ? ' has-error' : '') + '">' +
				'<div class="lnr-booking__item-main">' +
				'<span class="lnr-booking__item-name">' + esc(found.item.name) + '</span>' +
				'<span class="lnr-booking__item-meta">' + esc(found.gender.label) + ' · ' + esc(found.group.label) + ' · ' + optionLabel(found.option.n) + '</span>' +
				'<span class="lnr-booking__item-date">' + esc(dateLabel(item.start.slice(0, 10), true)) + ' · <span class="lnr-booking__nowrap">' + item.start.slice(11) + '</span></span>' +
				'</div>' +
				'<span class="lnr-booking__item-price">' + price(found.option.price) + '</span>' +
				'<div class="lnr-booking__item-actions">' +
				'<button type="button" class="lnr-booking__link" data-action="edit" data-value="' + esc(item.uid) + '">Időpont módosítása</button>' +
				'<button type="button" class="lnr-booking__link lnr-booking__link--danger" data-action="remove" data-value="' + esc(item.uid) + '">Törlés</button>' +
				'</div></li>';
		}).join('') + '</ul>';

		html += '<div class="lnr-booking__total"><span>Összesen</span><strong>' + price(total) + '</strong></div>';
		if (hasPass) {
			html += '<p class="lnr-booking__note">Bérletnél a további alkalmak időpontját a kezelések során egyeztetjük.</p>';
		}

		html += '<div class="lnr-booking__actions lnr-booking__actions--split">' +
			'<button type="button" class="iu-button iu-button-invert lnr-booking__secondary" data-action="add-more"><span class="iu-button-text"><span>+ Újabb terület</span></span></button>' +
			'<button type="button" class="iu-button iu-button-default lnr-booking__primary" data-action="details"><span class="iu-button-text"><span>Tovább</span></span></button>' +
			'</div>';

		return html;
	};

	Configurator.prototype.renderDetails = function () {
		var s = this.state, c = store.customer;
		var id = this.root.id || (this.root.id = 'lnr-booking-' + uid());

		function field(name, label, type, autocomplete) {
			return '<p class="lnr-booking__field">' +
				'<label for="' + id + '-' + name + '">' + label + '</label>' +
				'<input id="' + id + '-' + name + '" type="' + type + '" data-field="' + name + '" value="' + esc(c[name]) + '" autocomplete="' + autocomplete + '" required' +
				(name === 'phone' ? ' pattern="\\+?[0-9 \\(\\)\\/\\-]{7,20}" inputmode="tel"' : '') + '>' +
				'</p>';
		}

		return this.head('Az Ön adatai', 'Ezekre az adatokra küldjük a visszaigazolást. A számlázási adatokat és a fizetés módját a következő lépésben adhatja meg.', true) +
			(s.error ? '<p class="lnr-booking__error" role="alert">' + esc(s.error) + '</p>' : '') +
			'<form class="lnr-booking__form" novalidate>' +
			'<div class="lnr-booking__field-row">' +
			field('lastName', 'Vezetéknév', 'text', 'family-name') +
			field('firstName', 'Keresztnév', 'text', 'given-name') +
			'</div>' +
			field('email', 'E-mail cím', 'email', 'email') +
			field('phone', 'Telefonszám', 'tel', 'tel') +
			'<div class="lnr-booking__actions">' +
			'<button type="submit" class="iu-button iu-button-default lnr-booking__primary"' + (s.busy ? ' disabled' : '') + '><span class="iu-button-text"><span>' +
			(s.busy ? 'Egy pillanat…' : 'Tovább a fizetéshez') + '</span></span></button>' +
			'</div></form>';
	};

	/* ---------- start ---------- */

	function init() {
		store.load();
		var roots = document.querySelectorAll('[data-lnr-booking]');
		for (var i = 0; i < roots.length; i++) {
			if (roots[i].lnrBooking) continue;
			roots[i].lnrBooking = new Configurator(roots[i]);
			store.listeners.push(roots[i].lnrBooking);
		}
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
