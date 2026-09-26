/**
 * Közös építőelemek: API, formázás, apró komponensek.
 */
import { html, render, useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } from './vendor/preact-htm.js';

export { html, render, useState, useEffect, useRef, useMemo, useCallback, createContext, useContext };

export const CFG = window.HPV_APP;
export const AppContext = createContext(null);
export const useApp = () => useContext(AppContext);

/* ── API ─────────────────────────────────────────── */

export async function api(path, opts = {}) {
	const res = await fetch(CFG.rest + path, {
		method: opts.method || 'GET',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
		body: opts.body ? JSON.stringify(opts.body) : undefined,
	});
	// A WordPress a null választ üres törzsként küldi (pl. leállított stopper): ilyenkor null.
	const text = await res.text().catch(() => '');
	let data = null;
	try { data = text ? JSON.parse(text) : null; } catch (e) { data = {}; }
	if (!res.ok) {
		// Lejárt munkamenet (pl. egész nap nyitva hagyott fül): újratöltés, ami a belépéshez visz.
		if (data && (data.code === 'rest_cookie_invalid_nonce' || res.status === 401)) {
			window.location.reload();
		}
		throw new Error((data && data.message) || 'Hiba történt. Próbáld újra.');
	}
	return data;
}

/* ── Útválasztás (#/utvonal?param=ertek) ─────────── */

export function parseHash() {
	const raw = window.location.hash.replace(/^#/, '') || '/';
	const [path, query = ''] = raw.split('?');
	return { path, params: Object.fromEntries(new URLSearchParams(query)) };
}

export function navigate(path, params = {}) {
	const q = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')).toString();
	window.location.hash = path + (q ? '?' + q : '');
}

export function setParam(key, value) {
	const { path, params } = parseHash();
	if (value === null || value === undefined || value === '') {
		delete params[key];
	} else {
		params[key] = value;
	}
	navigate(path, params);
}

export function useRoute() {
	const [route, setRoute] = useState(parseHash());
	useEffect(() => {
		const on = () => setRoute(parseHash());
		window.addEventListener('hashchange', on);
		return () => window.removeEventListener('hashchange', on);
	}, []);
	return route;
}

/* ── Dátum, idő, pénz ────────────────────────────── */

const pad = (n) => String(n).padStart(2, '0');

export function todayISO(offsetDays = 0) {
	const d = new Date();
	d.setDate(d.getDate() + offsetDays);
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function parseISO(iso) {
	if (!iso) return null;
	const [y, m, d] = iso.split('-').map(Number);
	return new Date(y, m - 1, d);
}

export function addDays(iso, days) {
	const d = parseISO(iso);
	d.setDate(d.getDate() + days);
	return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function daysBetween(a, b) {
	return Math.round((parseISO(b) - parseISO(a)) / 86400000);
}

const MONTHS = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
export const MONTHS_LONG = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];

export function shortDate(iso) {
	if (!iso) return '';
	const d = parseISO(iso);
	const diff = daysBetween(todayISO(), iso);
	if (diff === 0) return 'Ma';
	if (diff === 1) return 'Holnap';
	if (diff === -1) return 'Tegnap';
	return `${MONTHS[d.getMonth()]} ${d.getDate()}.`;
}

export function timeAgo(isoDateTime) {
	const d = new Date(isoDateTime);
	const s = Math.round((Date.now() - d) / 1000);
	if (s < 60) return 'most';
	if (s < 3600) return `${Math.floor(s / 60)} perce`;
	if (s < 86400) return `${Math.floor(s / 3600)} órája`;
	if (s < 7 * 86400) return `${Math.floor(s / 86400)} napja`;
	return `${d.getFullYear()}. ${MONTHS[d.getMonth()]} ${d.getDate()}.`;
}

export function minutesLabel(min) {
	if (!min) return '0p';
	const h = Math.floor(min / 60);
	const m = min % 60;
	return h ? `${h}ó${m ? ' ' + m + 'p' : ''}` : `${m}p`;
}

export function clock(seconds) {
	const h = Math.floor(seconds / 3600);
	const m = Math.floor((seconds % 3600) / 60);
	const s = seconds % 60;
	return (h ? h + ':' + pad(m) : m) + ':' + pad(s);
}

export function money(cents, currency = 'USD') {
	const v = (cents || 0) / 100;
	if (currency === 'HUF') return Math.round(v).toLocaleString('hu-HU').replace(/\s/g, '\u00a0') + '\u00a0Ft';
	const sym = { USD: '$', EUR: '€' }[currency] || currency + ' ';
	const whole = Math.round(Math.abs(v) * 100) % 100 === 0;
	return (v < 0 ? '-' : '') + sym + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2 });
}

export const isOverdue = (t) => t.due_date && t.status !== 'done' && t.due_date < todayISO();

/* ── Komponensek ─────────────────────────────────── */

const ICONS = {
	home: 'M3 11l9-7 9 7M5 10v10h5v-6h4v6h5V10',
	check: 'M4 12l5 5L20 6',
	folder: 'M3 6h6l2 2h10v11H3z',
	chat: 'M4 5h16v11H8l-4 4z',
	users: 'M9 11a4 4 0 100-8 4 4 0 000 8zM2 21c1-4 4-6 7-6s6 2 7 6M16 3a4 4 0 010 8M22 21c-.5-3-2-5-4.5-5.7',
	receipt: 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6',
	doc: 'M6 3h9l3 3v15H6zM9 10h6M9 14h6M9 18h3',
	tag: 'M3 12V4h8l10 10-8 8z M7.5 7.5h.01',
	cog: 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z',
	search: 'M11 18a7 7 0 100-14 7 7 0 000 14zM21 21l-5-5',
	plus: 'M12 5v14M5 12h14',
	x: 'M6 6l12 12M18 6L6 18',
	play: 'M7 4l13 8-13 8z',
	stop: 'M6 6h12v12H6z',
	clock: 'M12 21a9 9 0 100-18 9 9 0 000 18zM12 7v5l3 3',
	comment: 'M4 5h16v11H8l-4 4z',
	list: 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
	board: 'M3 4h5v16H3zM10 4h5v10h-5zM17 4h4v13h-4z',
	gantt: 'M3 5h8M7 10h10M5 15h7M10 20h11',
	flag: 'M5 21V4h11l-2 4 2 4H5',
	eyeOff: 'M3 3l18 18M10.6 5.1A10 10 0 0112 5c5 0 9 4.5 10 7a13 13 0 01-3.2 4.3M6.2 6.2A13 13 0 002 12c1 2.5 5 7 10 7a9.8 9.8 0 004.8-1.2M9.9 9.9a3 3 0 004.2 4.2',
	link: 'M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1',
	trash: 'M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3',
	out: 'M14 4h6v16h-6M10 16l4-4-4-4M14 12H4',
	ext: 'M14 4h6v6M20 4l-9 9M18 14v6H4V6h6',
	chevron: 'M9 6l6 6-6 6',
	sub: 'M6 4v10a4 4 0 004 4h8M14 14l4 4-4 4',
	block: 'M12 21a9 9 0 100-18 9 9 0 000 18zM5.6 5.6l12.8 12.8',
	video: 'M3 7h12v10H3zM15 10l6-3v10l-6-3',
	proposal: 'M5 3h10l4 4v14H5zM14 3v5h5M9 13l2 2 4-4',
	template: 'M4 4h16v5H4zM4 13h7v7H4zM15 13h5M15 17h5M15 20h3',
	sparkle: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z',
	send: 'M4 12l16-8-6 16-2-6z',
	copy: 'M8 8h12v12H8zM4 4h12v4M4 4v12h4',
	up: 'M6 14l6-6 6 6',
	down: 'M6 10l6 6 6-6',
	eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 100-6 3 3 0 000 6z',
};

export function Icon({ name, size = 18 }) {
	return html`<svg class="i" viewBox="0 0 24 24" width=${size} height=${size} fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d=${ICONS[name] || ''} /></svg>`;
}

const AVATAR_COLORS = ['#b8ff34', '#d29dff', '#7dd3fc', '#fdba74', '#fca5a5', '#86efac', '#fde047', '#a5b4fc'];

export function Avatar({ user, size = 26 }) {
	if (!user) {
		return html`<span class="av av--empty" style=${{ width: size + 'px', height: size + 'px' }} title="Nincs felelős"><${Icon} name="users" size=${size * 0.55} /></span>`;
	}
	const bg = AVATAR_COLORS[user.id % AVATAR_COLORS.length];
	return html`<span class="av" style=${{ width: size + 'px', height: size + 'px', background: bg, fontSize: size * 0.4 + 'px' }} title=${user.name}>${user.initials}</span>`;
}

export function StatusPill({ status, label }) {
	return html`<span class=${'pill pill--' + status}>${label}</span>`;
}

const PRIORITY_COLORS = { low: '#9b9b93', normal: '', high: '#f59e0b', urgent: '#ef4444' };

export function PriorityFlag({ priority }) {
	if (!priority || priority === 'normal') return null;
	return html`<span class="flag" style=${{ color: PRIORITY_COLORS[priority] }} title=${'Prioritás: ' + priority}><${Icon} name="flag" size="14" /></span>`;
}

export function Modal({ title, onClose, children, wide }) {
	useEffect(() => {
		const onKey = (e) => e.key === 'Escape' && onClose();
		document.addEventListener('keydown', onKey);
		return () => document.removeEventListener('keydown', onKey);
	}, [onClose]);
	return html`
		<div class="modal" onMouseDown=${(e) => e.target === e.currentTarget && onClose()}>
			<div class=${'modal__box' + (wide ? ' modal__box--wide' : '')} role="dialog" aria-modal="true" aria-label=${title}>
				<header class="modal__head"><h2>${title}</h2><button class="icon-btn" onClick=${onClose} aria-label="Bezárás"><${Icon} name="x" /></button></header>
				${children}
			</div>
		</div>`;
}

/* ── Értesítés (toast) ───────────────────────────── */

let toastListener = null;
export function toast(message, type = 'ok') {
	if (toastListener) toastListener({ message, type, id: Date.now() + Math.random() });
}

export function Toasts() {
	const [items, setItems] = useState([]);
	useEffect(() => {
		toastListener = (t) => {
			setItems((list) => [...list, t]);
			setTimeout(() => setItems((list) => list.filter((x) => x.id !== t.id)), 3500);
		};
		return () => { toastListener = null; };
	}, []);
	return html`<div class="toasts" aria-live="polite">${items.map((t) => html`<div class=${'toast toast--' + t.type} key=${t.id}>${t.message}</div>`)}</div>`;
}

export function Empty({ icon = 'check', title, children }) {
	return html`<div class="empty"><span class="empty__icon"><${Icon} name=${icon} size="22" /></span><strong>${title}</strong>${children ? html`<p>${children}</p>` : null}</div>`;
}

export function Spinner() {
	return html`<div class="loading"><span class="spinner"></span></div>`;
}

/**
 * Kijelölés vagy szerkesztés utáni automatikus mentés: blur vagy Enter.
 */
export function InlineText({ value, onSave, placeholder, multiline, className }) {
	const [v, setV] = useState(value || '');
	useEffect(() => setV(value || ''), [value]);
	const commit = () => {
		if ((v || '') !== (value || '')) onSave(v);
	};
	if (multiline) {
		return html`<textarea class=${className} value=${v} placeholder=${placeholder} onInput=${(e) => setV(e.target.value)} onBlur=${commit} rows="4" />`;
	}
	return html`<input class=${className} value=${v} placeholder=${placeholder} onInput=${(e) => setV(e.target.value)} onBlur=${commit} onKeyDown=${(e) => { if (e.key === 'Enter') e.target.blur(); if (e.key === 'Escape') { setV(value || ''); } }} />`;
}
