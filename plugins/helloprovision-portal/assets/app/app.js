/**
 * HelloProVision CRM — webalkalmazás (crm.helloprovision.com).
 * Keret: oldalsáv, felső sáv (keresés, futó stopper), útválasztás, feladat panel.
 */
import { html, render, useState, useEffect, useRef, useCallback, api, CFG, AppContext, useApp, useRoute, navigate, setParam, Icon, Avatar, Toasts, toast, clock, Spinner, Empty } from './ui.js';
import { Dashboard } from './views/dashboard.js';
import { MyTasks } from './views/mytasks.js';
import { Projects } from './views/projects.js';
import { ProjectPage } from './views/project.js';
import { TaskDrawer } from './views/task.js';
import { Calls, CallPage } from './views/calls.js';
import { Team } from './views/team.js';

/* ── Chat (a meglévő chat komponens beágyazva) ───── */

function ChatView({ channel, client }) {
	const ref = useRef(null);
	useEffect(() => {
		let destroy = null;
		let cancelled = false;
		(async () => {
			let id = Number(channel) || 0;
			// Ügyfélből indítva: az ügyfél csatornája (a szerver létrehozza, ha még nincs).
			if (client) {
				try { id = (await api('/app/client-channel?client=' + client)).id; } catch (e) { toast(e.message, 'error'); }
			}
			if (!cancelled && window.HPVChatMount) {
				destroy = window.HPVChatMount(ref.current, Object.assign({}, CFG.chat, { channel: id }));
			}
		})();
		return () => { cancelled = true; if (destroy) destroy(); };
	}, []);
	return html`<div class="page page--chat"><div class="hpv-chat hpv-chat--app" ref=${ref}></div></div>`;
}

/* ── Ügyfelek (gyors lista; a részletes szerkesztés még a klasszikus CRM-ben) ── */

function Clients() {
	const { boot } = useApp();
	const [q, setQ] = useState('');
	const list = boot.clients.filter((c) => c.name.toLowerCase().includes(q.toLowerCase()));
	const labels = { lead: 'Érdeklődő', active: 'Aktív', paused: 'Szünetel', former: 'Korábbi' };
	return html`
		<div class="page">
			<header class="page__head">
				<div><h1>Ügyfelek</h1><p class="muted">${boot.clients.length} ügyfél</p></div>
				<a class="btn" href=${CFG.adminUrl.replace('page=hpv-crm', 'page=hpv-crm&action=edit&entity=client')}><${Icon} name="plus" /> Új ügyfél</a>
			</header>
			<input class="search-input" placeholder="Keresés…" value=${q} onInput=${(e) => setQ(e.target.value)} />
			<div class="card table-card">
				<table class="table">
					<thead><tr><th>Ügyfél</th><th>Ország</th><th>Státusz</th><th></th></tr></thead>
					<tbody>
						${list.map((c) => html`
							<tr key=${c.id}>
								<td><strong>${c.name}</strong></td>
								<td>${c.country === 'HU' ? 'Magyarország' : 'USA'}</td>
								<td><span class=${'pill pill--' + c.status}>${labels[c.status] || c.status}</span></td>
								<td class="right">
									<a class="link" href=${'#/projects?client=' + c.id}>Projektek</a>
									<a class="link" href=${'#/chat?client=' + c.id}>Chat</a>
									<a class="link" href=${'#/calls?client=' + c.id}>Hívások</a>
									<a class="link" href=${CFG.adminUrl + '&client=' + c.id}>Adatlap${boot.me.caps && boot.me.caps.invoices ? ', számlák' : ''} <${Icon} name="ext" size="13" /></a>
								</td>
							</tr>`)}
					</tbody>
				</table>
			</div>
		</div>`;
}

/* ── Keresés (Ctrl+K) ────────────────────────────── */

function Palette({ onClose }) {
	const [q, setQ] = useState('');
	const [items, setItems] = useState([]);
	const [active, setActive] = useState(0);
	const input = useRef(null);
	useEffect(() => input.current.focus(), []);
	useEffect(() => {
		if (q.trim().length < 2) { setItems([]); return undefined; }
		const t = setTimeout(() => api('/pm/search?q=' + encodeURIComponent(q.trim())).then((r) => { setItems(r); setActive(0); }).catch(() => {}), 180);
		return () => clearTimeout(t);
	}, [q]);
	const go = (it) => {
		onClose();
		if (it.type === 'project') navigate('/projects/' + it.id);
		if (it.type === 'task') navigate('/projects/' + it.project_id, { task: it.id });
		if (it.type === 'client') navigate('/projects', { client: it.id });
	};
	const icon = { project: 'folder', task: 'check', client: 'users' };
	return html`
		<div class="modal modal--top" onMouseDown=${(e) => e.target === e.currentTarget && onClose()}>
			<div class="palette" role="dialog" aria-modal="true" aria-label="Keresés">
				<div class="palette__input"><${Icon} name="search" /><input ref=${input} placeholder="Projekt, feladat vagy ügyfél keresése…" value=${q}
					onInput=${(e) => setQ(e.target.value)}
					onKeyDown=${(e) => {
						if (e.key === 'Escape') onClose();
						if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(a + 1, items.length - 1)); }
						if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(a - 1, 0)); }
						if (e.key === 'Enter' && items[active]) go(items[active]);
					}} /><kbd>Esc</kbd></div>
				${items.length ? html`<ul class="palette__list">${items.map((it, i) => html`
					<li key=${it.type + it.id} class=${i === active ? 'is-active' : ''} onMouseEnter=${() => setActive(i)} onClick=${() => go(it)}>
						<${Icon} name=${icon[it.type]} /><span><strong>${it.title}</strong><small>${it.meta}</small></span>
					</li>`)}</ul>` : html`<p class="palette__hint">${q.trim().length < 2 ? 'Írj be legalább 2 betűt.' : 'Nincs találat.'}</p>`}
			</div>
		</div>`;
}

/* ── Futó stopper a felső sávban ─────────────────── */

function TimerPill() {
	const { timer, setTimer } = useApp();
	const [now, setNow] = useState(Math.floor(Date.now() / 1000));
	useEffect(() => {
		if (!timer) return undefined;
		const t = setInterval(() => setNow(Math.floor(Date.now() / 1000)), 1000);
		return () => clearInterval(t);
	}, [timer]);
	if (!timer || !timer.task_id) return null;
	const stop = (e) => {
		e.stopPropagation();
		api('/pm/tasks/' + timer.task_id + '/timer', { method: 'POST', body: { action: 'stop' } }).then(() => {
			setTimer(null);
			toast('Idő rögzítve.');
			window.dispatchEvent(new CustomEvent('hpv:changed'));
		});
	};
	return html`
		<button class="timer-pill" onClick=${() => navigate('/projects/' + timer.project_id, { task: timer.task_id })} title=${timer.project}>
			<span class="timer-pill__dot"></span><span class="timer-pill__task">${timer.task}</span>
			<strong>${clock(Math.max(0, now - timer.started_at))}</strong>
			<span class="timer-pill__stop" onClick=${stop} role="button" aria-label="Stopper leállítása"><${Icon} name="stop" size="14" /></span>
		</button>`;
}

/* ── Keret ───────────────────────────────────────── */

const NAV = [
	{ path: '/', label: 'Vezérlőpult', icon: 'home' },
	{ path: '/my', label: 'Saját feladataim', icon: 'check' },
	{ path: '/projects', label: 'Projektek', icon: 'folder' },
	{ path: '/chat', label: 'Chat', icon: 'chat', badge: 'unread' },
	{ path: '/calls', label: 'Hívások', icon: 'video' },
	{ path: '/clients', label: 'Ügyfelek', icon: 'users' },
];

function Sidebar({ path }) {
	const { boot, unread } = useApp();
	const caps = boot.me.caps || {};
	const legacy = [];
	if (caps.invoices) legacy.push({ href: CFG.adminUrl.replace('page=hpv-crm', 'page=hpv-crm-invoices'), label: 'Számlák', icon: 'receipt' });
	if (caps.contracts) legacy.push({ href: CFG.adminUrl.replace('page=hpv-crm', 'page=hpv-crm-contracts'), label: 'Szerződések', icon: 'doc' });
	if (caps.invoices) legacy.push({ href: CFG.adminUrl.replace('page=hpv-crm', 'page=hpv-crm-services'), label: 'Szolgáltatások', icon: 'tag' });
	if (boot.me.is_admin) legacy.push({ href: CFG.adminUrl.replace('page=hpv-crm', 'page=hpv-crm-settings'), label: 'Beállítások', icon: 'cog' });
	const isActive = (p) => (p === '/' ? path === '/' : path.startsWith(p));
	return html`
		<aside class="side">
			<a class="brand" href="#/">Hello<span>ProVision</span><small>CRM</small></a>
			<nav class="nav">
				${NAV.map((n) => html`
					<a key=${n.path} href=${'#' + n.path} class=${isActive(n.path) ? 'is-active' : ''}>
						<${Icon} name=${n.icon} /><span>${n.label}</span>
						${n.badge === 'unread' && unread ? html`<em class="count">${unread}</em>` : null}
					</a>`)}
			</nav>
			${legacy.length || boot.me.is_admin ? html`<p class="nav-label">Pénzügy és admin</p>` : null}
			<nav class="nav nav--secondary">
				${boot.me.is_admin ? html`<a href="#/team" class=${isActive('/team') ? 'is-active' : ''}><${Icon} name="users" /><span>Csapat és jogok</span></a>` : null}
				${legacy.map((n) => html`<a key=${n.label} href=${n.href}><${Icon} name=${n.icon} /><span>${n.label}</span><${Icon} name="ext" size="13" /></a>`)}
			</nav>
			<div class="side__user">
				<${Avatar} user=${boot.me} size="32" />
				<span><strong>${boot.me.name}</strong><small>HelloProVision</small></span>
				<a class="icon-btn" href=${boot.logoutUrl} title="Kijelentkezés" aria-label="Kijelentkezés"><${Icon} name="out" /></a>
			</div>
		</aside>`;
}

function App() {
	const route = useRoute();
	const [boot, setBoot] = useState(null);
	const [timer, setTimer] = useState(null);
	const [unread, setUnread] = useState(0);
	const [palette, setPalette] = useState(false);
	const [error, setError] = useState('');

	useEffect(() => {
		api('/pm/bootstrap').then((b) => {
			CFG.boot = b;
			setBoot(b);
			setTimer(b.timer);
			setUnread(b.unread);
		}).catch((e) => setError(e.message));
	}, []);

	// Olvasatlan üzenetek frissítése.
	useEffect(() => {
		const t = setInterval(() => {
			if (!document.hidden) api('/chat/unread').then((r) => setUnread(r.total)).catch(() => {});
		}, 30000);
		return () => clearInterval(t);
	}, []);

	useEffect(() => {
		const onKey = (e) => {
			if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
				e.preventDefault();
				setPalette((p) => !p);
			}
		};
		document.addEventListener('keydown', onKey);
		return () => document.removeEventListener('keydown', onKey);
	}, []);

	const refreshTimer = useCallback(() => api('/pm/timer').then(setTimer).catch(() => {}), []);

	if (error) return html`<div class="boot-error"><${Empty} icon="x" title="Nem sikerült betölteni a CRM-et">${error}</${Empty}></div>`;
	if (!boot) return html`<${Spinner} />`;

	const { path, params } = route;
	let page;
	let m;
	if (path === '/') page = html`<${Dashboard} />`;
	else if (path === '/my') page = html`<${MyTasks} />`;
	else if (path === '/projects') page = html`<${Projects} params=${params} />`;
	else if ((m = path.match(/^\/projects\/(\d+)$/))) page = html`<${ProjectPage} key=${m[1]} id=${Number(m[1])} params=${params} />`;
	else if (path.startsWith('/chat')) page = html`<${ChatView} key=${'chat' + (params.channel || '') + (params.client || '')} channel=${params.channel} client=${params.client} />`;
	else if (path === '/calls') page = html`<${Calls} params=${params} />`;
	else if ((m = path.match(/^\/calls\/(\d+)$/))) page = html`<${CallPage} key=${'call' + m[1]} id=${Number(m[1])} params=${params} />`;
	else if (path === '/clients') page = html`<${Clients} />`;
	else if (path === '/team') page = html`<${Team} />`;
	else page = html`<${Empty} title="Az oldal nem található" />`;

	return html`
		<${AppContext.Provider} value=${{ boot, timer, setTimer, refreshTimer, unread, setUnread }}>
			<div class="app">
				<${Sidebar} path=${path} />
				<div class="main">
					<header class="topbar">
						<button class="search-btn" onClick=${() => setPalette(true)}><${Icon} name="search" /><span>Keresés…</span><kbd>Ctrl K</kbd></button>
						<${TimerPill} />
					</header>
					<main class="content">${page}</main>
				</div>
				${params.task ? html`<${TaskDrawer} key=${params.task} id=${Number(params.task)} onClose=${() => setParam('task', null)} />` : null}
				${palette ? html`<${Palette} onClose=${() => setPalette(false)} />` : null}
				<${Toasts} />
			</div>
		</${AppContext.Provider}>`;
}

render(html`<${App} />`, document.getElementById('app'));
