/**
 * HelloProVision chat — a CRM-ben (magyar) és a portálon (angol) is ez fut.
 * Csatornalista + üzenetek; új üzenetek lekérdezése 4 mp-enként, a lista 15 mp-enként.
 */
(function () {
	'use strict';

	var cfg = window.HPV_CHAT;
	var root = document.getElementById('hpv-chat');
	if (!cfg || !root) {
		return;
	}

	var T = {
		hu: {
			channels: 'Csatornák', clients: 'Ügyfelek', groups: 'Csoportok', join: 'Csatlakozható csoportok',
			newChannel: '+ Új csoport', placeholder: 'Írj üzenetet… (Enter = küldés, Shift+Enter = új sor)',
			send: 'Küldés', empty: 'Még nincs üzenet. Írd meg az elsőt!', pick: 'Válassz csatornát a bal oldalon.',
			members: 'tag', older: 'Korábbi üzenetek', name: 'Név', description: 'Leírás (nem kötelező)',
			client: 'Ügyfél (ha ügyfél-csatorna)', none: '— belső csoport —', people: 'Tagok', create: 'Létrehozás',
			cancel: 'Mégse', joinBtn: 'Csatlakozás', leave: 'Kilépés', staff: 'munkatárs', archive: 'Archiválás',
			membersTitle: 'Tagok', add: 'Hozzáadás', remove: 'Eltávolítás', error: 'Hiba történt. Próbáld újra.',
			today: 'Ma', yesterday: 'Tegnap', clientTag: 'ügyfél', noChannels: 'Még nincs csatornád.', back: '←'
		},
		en: {
			channels: 'Conversations', clients: 'Conversations', groups: 'Groups', join: '',
			newChannel: '', placeholder: 'Write a message… (Enter to send, Shift+Enter for a new line)',
			send: 'Send', empty: 'No messages yet. Say hello to your team!', pick: 'Choose a conversation.',
			members: 'members', older: 'Load earlier messages', name: '', description: '', client: '', none: '',
			people: 'People', create: '', cancel: 'Close', joinBtn: '', leave: '', staff: 'HelloProVision', archive: '',
			membersTitle: 'People in this conversation', add: '', remove: '', error: 'Something went wrong. Please try again.',
			today: 'Today', yesterday: 'Yesterday', clientTag: '', noChannels: 'No conversations yet. Your team will start one soon.', back: '←'
		}
	}[cfg.lang === 'hu' ? 'hu' : 'en'];

	var state = { channels: [], joinable: [], current: null, messages: [], lastId: 0, firstId: 0, more: false, canPost: true, sending: false };
	var timers = {};

	/* ── Segédfüggvények ─────────────────────────────── */

	function esc(value) {
		return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// Linkek kattinthatóvá tétele (escapelés után, csak http/https).
	function linkify(text) {
		return esc(text).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]])/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>').replace(/\n/g, '<br>');
	}

	function api(path, opts) {
		opts = opts || {};
		return fetch(cfg.api + path, {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: opts.body ? JSON.stringify(opts.body) : undefined
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) {
					throw new Error(data && data.message ? data.message : T.error);
				}
				return data;
			});
		});
	}

	function timeLabel(iso) {
		var d = new Date(iso);
		var now = new Date();
		var y = new Date(now);
		y.setDate(now.getDate() - 1);
		var hm = d.toLocaleTimeString(cfg.lang === 'hu' ? 'hu-HU' : 'en-US', { hour: '2-digit', minute: '2-digit' });
		if (d.toDateString() === now.toDateString()) {
			return hm;
		}
		if (d.toDateString() === y.toDateString()) {
			return T.yesterday + ' ' + hm;
		}
		return d.toLocaleDateString(cfg.lang === 'hu' ? 'hu-HU' : 'en-US', { month: 'short', day: 'numeric' }) + ' ' + hm;
	}

	function dayLabel(iso) {
		var d = new Date(iso);
		var now = new Date();
		var y = new Date(now);
		y.setDate(now.getDate() - 1);
		if (d.toDateString() === now.toDateString()) {
			return T.today;
		}
		if (d.toDateString() === y.toDateString()) {
			return T.yesterday;
		}
		return d.toLocaleDateString(cfg.lang === 'hu' ? 'hu-HU' : 'en-US', { weekday: 'long', month: 'long', day: 'numeric' });
	}

	/* ── Váz ─────────────────────────────────────────── */

	root.innerHTML =
		'<aside class="hpv-chat__side">' +
			'<div class="hpv-chat__side-head"><strong>' + esc(T.channels) + '</strong>' +
				(cfg.isStaff ? '<button type="button" class="hpv-chat__new" data-new>' + esc(T.newChannel) + '</button>' : '') +
			'</div>' +
			'<div class="hpv-chat__list" data-list></div>' +
		'</aside>' +
		'<section class="hpv-chat__main" data-main>' +
			'<header class="hpv-chat__head" data-head></header>' +
			'<div class="hpv-chat__messages" data-messages aria-live="polite"><p class="hpv-chat__placeholder">' + esc(T.pick) + '</p></div>' +
			'<form class="hpv-chat__composer" data-composer hidden>' +
				'<textarea rows="1" placeholder="' + esc(T.placeholder) + '" aria-label="' + esc(T.placeholder) + '"></textarea>' +
				'<button type="submit" class="hpv-chat__send" aria-label="' + esc(T.send) + '">' +
					'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12l16-8-6 16-2-6z"/></svg>' +
				'</button>' +
			'</form>' +
		'</section>' +
		'<div class="hpv-chat__modal" data-modal hidden></div>';

	var el = {
		list: root.querySelector('[data-list]'),
		main: root.querySelector('[data-main]'),
		head: root.querySelector('[data-head]'),
		messages: root.querySelector('[data-messages]'),
		composer: root.querySelector('[data-composer]'),
		input: root.querySelector('[data-composer] textarea'),
		modal: root.querySelector('[data-modal]')
	};

	/* ── Csatornalista ───────────────────────────────── */

	function channelItem(c, joinable) {
		var initials = c.type === 'client' ? (c.client || c.name).slice(0, 2).toUpperCase() : '#';
		var preview = c.last ? esc(c.last.name.split(' ')[0]) + ': ' + esc(c.last.body) : '';
		return '<button type="button" class="hpv-chat__item' + (state.current && state.current.id === c.id ? ' is-active' : '') + (c.unread ? ' has-unread' : '') + '" data-channel="' + c.id + '"' + (joinable ? ' data-joinable' : '') + '>' +
			'<span class="hpv-chat__icon hpv-chat__icon--' + c.type + '">' + esc(initials) + '</span>' +
			'<span class="hpv-chat__item-text"><strong>' + esc(c.name) + '</strong><small>' + (joinable ? esc(T.joinBtn) : preview) + '</small></span>' +
			(c.unread ? '<em class="hpv-chat__badge">' + c.unread + '</em>' : '') +
		'</button>';
	}

	function renderList() {
		var clients = state.channels.filter(function (c) { return c.type === 'client'; });
		var groups = state.channels.filter(function (c) { return c.type !== 'client'; });
		var html = '';
		if (!state.channels.length) {
			html += '<p class="hpv-chat__hint">' + esc(T.noChannels) + '</p>';
		}
		if (cfg.isStaff) {
			if (groups.length) {
				html += '<h3>' + esc(T.groups) + '</h3>' + groups.map(function (c) { return channelItem(c); }).join('');
			}
			if (clients.length) {
				html += '<h3>' + esc(T.clients) + '</h3>' + clients.map(function (c) { return channelItem(c); }).join('');
			}
			if (state.joinable.length) {
				html += '<h3>' + esc(T.join) + '</h3>' + state.joinable.map(function (c) { return channelItem(c, true); }).join('');
			}
		} else {
			html += state.channels.map(function (c) { return channelItem(c); }).join('');
		}
		el.list.innerHTML = html;
	}

	function loadChannels() {
		return api('/channels').then(function (data) {
			state.channels = data.channels || [];
			state.joinable = data.joinable || [];
			if (state.current) {
				var fresh = state.channels.filter(function (c) { return c.id === state.current.id; })[0];
				if (fresh) {
					fresh.unread = 0;
					state.current = fresh;
				}
			}
			renderList();
			updateTitle();
		});
	}

	function updateTitle() {
		var total = state.channels.reduce(function (sum, c) { return sum + (state.current && c.id === state.current.id ? 0 : c.unread); }, 0);
		document.title = document.title.replace(/^\(\d+\) /, '');
		if (total) {
			document.title = '(' + total + ') ' + document.title;
		}
	}

	/* ── Üzenetek ────────────────────────────────────── */

	function messageHtml(m, prev) {
		var html = '';
		if (!prev || new Date(prev.time).toDateString() !== new Date(m.time).toDateString()) {
			html += '<div class="hpv-chat__day"><span>' + esc(dayLabel(m.time)) + '</span></div>';
		}
		var grouped = prev && prev.user_id === m.user_id && (new Date(m.time) - new Date(prev.time)) < 5 * 60 * 1000 && new Date(prev.time).toDateString() === new Date(m.time).toDateString();
		var tag = m.is_staff ? (cfg.isStaff ? '' : T.staff) : (cfg.isStaff ? (m.company || T.clientTag) : '');
		html += '<div class="hpv-chat__msg' + (m.mine ? ' is-mine' : '') + (grouped ? ' is-grouped' : '') + (m.is_staff ? ' is-staff' : ' is-client') + '" data-id="' + m.id + '">' +
			(grouped ? '<span class="hpv-chat__avatar is-spacer"></span>' : '<span class="hpv-chat__avatar">' + esc(m.initials) + '</span>') +
			'<div class="hpv-chat__bubble-wrap">' +
				(grouped ? '' : '<div class="hpv-chat__meta"><strong>' + esc(m.name) + '</strong>' + (tag ? '<span class="hpv-chat__tag">' + esc(tag) + '</span>' : '') + '<time>' + esc(timeLabel(m.time)) + '</time></div>') +
				'<div class="hpv-chat__bubble">' + linkify(m.body) + '</div>' +
			'</div>' +
		'</div>';
		return html;
	}

	function renderMessages(stickToBottom) {
		var nearBottom = el.messages.scrollHeight - el.messages.scrollTop - el.messages.clientHeight < 120;
		var html = state.more ? '<button type="button" class="hpv-chat__older" data-older>' + esc(T.older) + '</button>' : '';
		if (!state.messages.length) {
			html += '<p class="hpv-chat__placeholder">' + esc(T.empty) + '</p>';
		}
		state.messages.forEach(function (m, i) {
			html += messageHtml(m, state.messages[i - 1]);
		});
		el.messages.innerHTML = html;
		if (stickToBottom || nearBottom) {
			el.messages.scrollTop = el.messages.scrollHeight;
		}
	}

	function renderHead() {
		var c = state.current;
		if (!c) {
			el.head.innerHTML = '';
			return;
		}
		el.head.innerHTML =
			'<button type="button" class="hpv-chat__back" data-back aria-label="Back">' + esc(T.back) + '</button>' +
			'<span class="hpv-chat__icon hpv-chat__icon--' + c.type + '">' + esc(c.type === 'client' ? (c.client || c.name).slice(0, 2).toUpperCase() : '#') + '</span>' +
			'<div class="hpv-chat__head-text"><strong>' + esc(c.name) + '</strong><small>' + esc(c.description || (c.members + ' ' + T.members)) + '</small></div>' +
			'<button type="button" class="hpv-chat__head-btn" data-members>' + esc(T.people) + '</button>';
	}

	function openChannel(id) {
		var c = state.channels.filter(function (x) { return x.id === id; })[0];
		if (!c) {
			return;
		}
		state.current = c;
		state.messages = [];
		state.lastId = 0;
		c.unread = 0;
		root.classList.add('is-open');
		renderList();
		renderHead();
		el.messages.innerHTML = '<p class="hpv-chat__placeholder"><span class="hpv-chat__spinner"></span></p>';
		el.composer.hidden = true;

		api('/channels/' + id + '/messages').then(function (data) {
			if (!state.current || state.current.id !== id) {
				return;
			}
			state.messages = data.messages;
			state.more = data.more;
			state.canPost = data.can_post;
			state.lastId = state.messages.length ? state.messages[state.messages.length - 1].id : 0;
			state.firstId = state.messages.length ? state.messages[0].id : 0;
			renderMessages(true);
			el.composer.hidden = !state.canPost;
			if (state.canPost && window.matchMedia('(min-width: 800px)').matches) {
				el.input.focus();
			}
			updateTitle();
		}).catch(function (e) {
			el.messages.innerHTML = '<p class="hpv-chat__placeholder">' + esc(e.message) + '</p>';
		});

		try {
			var url = new URL(window.location.href);
			url.searchParams.set('channel', id);
			url.searchParams.delete('client');
			window.history.replaceState(null, '', url);
		} catch (e) { /* régi böngésző */ }
	}

	function poll() {
		if (!state.current || document.hidden) {
			return;
		}
		var id = state.current.id;
		api('/channels/' + id + '/messages?after=' + state.lastId).then(function (data) {
			if (!state.current || state.current.id !== id || !data.messages.length) {
				return;
			}
			var known = {};
			state.messages.forEach(function (m) { known[m.id] = true; });
			data.messages.forEach(function (m) {
				if (!known[m.id]) {
					state.messages.push(m);
				}
			});
			state.lastId = state.messages[state.messages.length - 1].id;
			renderMessages(false);
		}).catch(function () {});
	}

	function loadOlder() {
		var id = state.current.id;
		api('/channels/' + id + '/messages?before=' + state.firstId).then(function (data) {
			if (!state.current || state.current.id !== id) {
				return;
			}
			var height = el.messages.scrollHeight;
			state.messages = data.messages.concat(state.messages);
			state.more = data.more;
			state.firstId = state.messages.length ? state.messages[0].id : 0;
			renderMessages(false);
			el.messages.scrollTop = el.messages.scrollHeight - height;
		});
	}

	function send(body) {
		if (!state.current || state.sending) {
			return;
		}
		state.sending = true;
		var id = state.current.id;
		// A mező azonnal ürül; hiba esetén visszakapja a szöveget.
		el.input.value = '';
		autosize();
		api('/channels/' + id + '/messages', { method: 'POST', body: { body: body } }).then(function (m) {
			// A 4 mp-es frissítés megelőzhette a választ: ugyanaz az üzenet ne kerüljön be kétszer.
			var exists = state.messages.some(function (x) { return x.id === m.id; });
			if (state.current && state.current.id === id && !exists) {
				state.messages.push(m);
				state.lastId = Math.max(state.lastId, m.id);
			}
			renderMessages(true);
		}).catch(function (e) {
			if (!el.input.value) {
				el.input.value = body;
				autosize();
			}
			alert(e.message);
		}).then(function () {
			state.sending = false;
		});
	}

	function autosize() {
		el.input.style.height = 'auto';
		el.input.style.height = Math.min(el.input.scrollHeight, 180) + 'px';
	}

	/* ── Ablakok: tagok, új csoport ──────────────────── */

	function closeModal() {
		el.modal.hidden = true;
		el.modal.innerHTML = '';
	}

	function showMembers() {
		var c = state.current;
		api('/channels/' + c.id + '/members').then(function (members) {
			var html = '<div class="hpv-chat__dialog" role="dialog" aria-modal="true"><h3>' + esc(T.membersTitle) + '</h3><ul class="hpv-chat__people">';
			members.forEach(function (m) {
				html += '<li><span class="hpv-chat__avatar">' + esc(m.initials) + '</span><span>' + esc(m.name) + (m.is_staff ? '' : ' <small>' + esc(m.company) + '</small>') + '</span>' +
					(cfg.isStaff && m.id !== cfg.me.id ? '<button type="button" class="hpv-chat__link" data-remove-member="' + m.id + '">' + esc(T.remove) + '</button>' : '') + '</li>';
			});
			html += '</ul>';
			if (cfg.isStaff) {
				html += '<div class="hpv-chat__add"><select data-add-select><option value="">…</option></select><button type="button" class="hpv-chat__btn" data-add-member>' + esc(T.add) + '</button></div>' +
					'<div class="hpv-chat__dialog-actions"><button type="button" class="hpv-chat__link" data-leave>' + esc(T.leave) + '</button>' +
					'<button type="button" class="hpv-chat__link" data-archive>' + esc(T.archive) + '</button>' +
					'<button type="button" class="hpv-chat__btn" data-close>' + esc(T.cancel) + '</button></div>';
			} else {
				html += '<div class="hpv-chat__dialog-actions"><button type="button" class="hpv-chat__btn" data-close>' + esc(T.cancel) + '</button></div>';
			}
			html += '</div>';
			el.modal.innerHTML = html;
			el.modal.hidden = false;

			if (cfg.isStaff) {
				var inChannel = {};
				members.forEach(function (m) { inChannel[m.id] = true; });
				api('/users' + (c.client_id ? '?client_id=' + c.client_id : '')).then(function (users) {
					var select = el.modal.querySelector('[data-add-select]');
					users.filter(function (u) { return !inChannel[u.id]; }).forEach(function (u) {
						var o = document.createElement('option');
						o.value = u.id;
						o.textContent = u.name + (u.is_staff ? '' : ' (' + u.company + ')');
						select.appendChild(o);
					});
				});
			}
		});
	}

	function updateMembers(body) {
		return api('/channels/' + state.current.id + '/members', { method: 'POST', body: body }).then(function () {
			return loadChannels();
		});
	}

	function showNewChannel() {
		var clientOptions = (cfg.clients || []).map(function (c) {
			return '<option value="' + c.id + '">' + esc(c.name) + '</option>';
		}).join('');
		el.modal.innerHTML =
			'<form class="hpv-chat__dialog" data-new-form role="dialog" aria-modal="true">' +
				'<h3>' + esc(T.newChannel.replace('+ ', '')) + '</h3>' +
				'<label>' + esc(T.name) + '<input name="name" required maxlength="100"></label>' +
				'<label>' + esc(T.description) + '<input name="description" maxlength="200"></label>' +
				'<label>' + esc(T.client) + '<select name="client_id"><option value="">' + esc(T.none) + '</option>' + clientOptions + '</select></label>' +
				'<fieldset><legend>' + esc(T.people) + '</legend><div class="hpv-chat__checks" data-staff-list>…</div></fieldset>' +
				'<div class="hpv-chat__dialog-actions"><button type="button" class="hpv-chat__link" data-close>' + esc(T.cancel) + '</button><button class="hpv-chat__btn">' + esc(T.create) + '</button></div>' +
			'</form>';
		el.modal.hidden = false;
		el.modal.querySelector('input[name=name]').focus();
		api('/users').then(function (users) {
			el.modal.querySelector('[data-staff-list]').innerHTML = users.filter(function (u) { return u.id !== cfg.me.id; }).map(function (u) {
				return '<label><input type="checkbox" name="members" value="' + u.id + '"> ' + esc(u.name) + '</label>';
			}).join('') || '—';
		});
	}

	/* ── Események ───────────────────────────────────── */

	root.addEventListener('click', function (e) {
		var t = e.target;
		var item = t.closest('[data-channel]');
		if (item) {
			var id = parseInt(item.getAttribute('data-channel'), 10);
			if (item.hasAttribute('data-joinable')) {
				api('/channels/' + id + '/members', { method: 'POST', body: { join: 1 } }).then(loadChannels).then(function () { openChannel(id); });
			} else {
				openChannel(id);
			}
			return;
		}
		if (t.closest('[data-new]')) {
			showNewChannel();
		} else if (t.closest('[data-older]')) {
			loadOlder();
		} else if (t.closest('[data-members]')) {
			showMembers();
		} else if (t.closest('[data-back]')) {
			root.classList.remove('is-open');
		} else if (t.closest('[data-close]') || t === el.modal) {
			closeModal();
		} else if (t.closest('[data-add-member]')) {
			var uid = parseInt(el.modal.querySelector('[data-add-select]').value, 10);
			if (uid) {
				updateMembers({ add: [uid] }).then(showMembers);
			}
		} else if (t.closest('[data-remove-member]')) {
			updateMembers({ remove: [parseInt(t.closest('[data-remove-member]').getAttribute('data-remove-member'), 10)] }).then(showMembers);
		} else if (t.closest('[data-leave]')) {
			updateMembers({ leave: 1 }).then(function () {
				closeModal();
				state.current = null;
				root.classList.remove('is-open');
				renderHead();
				el.messages.innerHTML = '<p class="hpv-chat__placeholder">' + esc(T.pick) + '</p>';
				el.composer.hidden = true;
			});
		} else if (t.closest('[data-archive]')) {
			if (window.confirm(T.archive + '?')) {
				api('/channels/' + state.current.id, { method: 'POST', body: { archived: 1 } }).then(function () {
					closeModal();
					state.current = null;
					loadChannels();
					renderHead();
					el.messages.innerHTML = '<p class="hpv-chat__placeholder">' + esc(T.pick) + '</p>';
					el.composer.hidden = true;
				});
			}
		}
	});

	root.addEventListener('submit', function (e) {
		if (e.target.matches('[data-new-form]')) {
			e.preventDefault();
			var f = e.target;
			var members = Array.prototype.map.call(f.querySelectorAll('input[name=members]:checked'), function (i) { return parseInt(i.value, 10); });
			var field = function (n) { return f.elements.namedItem(n).value; };
			api('/channels', { method: 'POST', body: { name: field('name'), description: field('description'), client_id: field('client_id') || 0, members: members } }).then(function (c) {
				closeModal();
				return loadChannels().then(function () { openChannel(c.id); });
			}).catch(function (err) { alert(err.message); });
		}
	});

	el.composer.addEventListener('submit', function (e) {
		e.preventDefault();
		var body = el.input.value.trim();
		if (body) {
			send(body);
		}
	});

	el.input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
			e.preventDefault();
			el.composer.requestSubmit ? el.composer.requestSubmit() : el.composer.dispatchEvent(new Event('submit', { cancelable: true }));
		}
	});
	el.input.addEventListener('input', autosize);

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && !el.modal.hidden) {
			closeModal();
		}
	});

	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) {
			poll();
			loadChannels();
		}
	});

	/* ── Indulás ─────────────────────────────────────── */

	loadChannels().then(function () {
		var initial = cfg.channel || (state.channels.length === 1 || (!cfg.isStaff && state.channels.length) ? state.channels[0].id : 0);
		if (initial) {
			openChannel(initial);
		}
	}).catch(function (e) {
		el.list.innerHTML = '<p class="hpv-chat__hint">' + esc(e.message) + '</p>';
	});

	timers.poll = setInterval(poll, 4000);
	timers.list = setInterval(function () {
		if (!document.hidden) {
			loadChannels();
		}
	}, 15000);
})();
