/**
 * SEO OS – közös építőelemek: API, útválasztás, formázás, apró komponensek.
 */
import { html, render, useState, useEffect, useRef, useMemo, useCallback, createContext, useContext } from './vendor/preact-htm.js';

export { html, render, useState, useEffect, useRef, useMemo, useCallback, createContext, useContext };

export const CFG = window.HPV_SEO;
export const AppContext = createContext(null);
export const useApp = () => useContext(AppContext);

/* ── API ─────────────────────────────────────────── */

async function handle(res) {
	const text = await res.text().catch(() => '');
	let data = null;
	try { data = text ? JSON.parse(text) : null; } catch (e) { data = { message: text.slice(0, 200) }; }
	if (!res.ok) {
		if (data && (data.code === 'rest_cookie_invalid_nonce' || res.status === 401 && data.code)) {
			window.location.reload();
		}
		const err = new Error((data && data.message) || 'Hiba történt. Próbáld újra.');
		err.problems = (data && data.problems) || [];
		err.status = res.status;
		throw err;
	}
	return data;
}

export async function api(path, opts = {}) {
	const res = await fetch(CFG.rest + path, {
		method: opts.method || 'GET',
		credentials: 'same-origin',
		headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
		body: opts.body !== undefined ? JSON.stringify(opts.body) : undefined,
	});
	return handle(res);
}

/** Fájlfeltöltés: a nyers fájl megy a törzsben, a név fejlécben. */
export async function upload(path, file) {
	const res = await fetch(CFG.rest + path, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': file.type || 'application/octet-stream',
			'X-Filename': encodeURIComponent(file.name),
			'X-WP-Nonce': CFG.nonce,
		},
		body: file,
	});
	return handle(res);
}

/** Letöltés (PDF, DOCX, XLSX): a böngésző menti el. */
export async function download(path, fallbackName = 'export') {
	const res = await fetch(CFG.rest + path, { credentials: 'same-origin', headers: { 'X-WP-Nonce': CFG.nonce } });
	if (!res.ok) {
		await handle(res);
		return;
	}
	const blob = await res.blob();
	const cd = res.headers.get('Content-Disposition') || '';
	const m = cd.match(/filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i);
	const name = m ? decodeURIComponent(m[1] || m[2]) : fallbackName;
	const url = URL.createObjectURL(blob);
	const a = document.createElement('a');
	a.href = url;
	a.download = name;
	document.body.appendChild(a);
	a.click();
	a.remove();
	setTimeout(() => URL.revokeObjectURL(url), 2000);
}

/** Adat betöltése: [data, loading, error, reload, setData] */
export function useLoad(path, deps = []) {
	const [state, setState] = useState({ data: null, loading: true, error: null });
	const [tick, setTick] = useState(0);
	useEffect(() => {
		if (!path) { setState({ data: null, loading: false, error: null }); return undefined; }
		let alive = true;
		setState((s) => ({ ...s, loading: true, error: null }));
		api(path).then((data) => alive && setState({ data, loading: false, error: null }))
			.catch((error) => alive && setState({ data: null, loading: false, error }));
		return () => { alive = false; };
	}, [path, tick, ...deps]);
	const reload = useCallback(() => setTick((t) => t + 1), []);
	const setData = useCallback((fn) => setState((s) => ({ ...s, data: typeof fn === 'function' ? fn(s.data) : fn })), []);
	return [state.data, state.loading, state.error, reload, setData];
}

/** Háttérfeladat követése: addig kérdezi, amíg kész vagy hibás. */
export function useJob(onDone) {
	const [job, setJob] = useState(null);
	useEffect(() => {
		if (!job || job.status === 'done' || job.status === 'failed') return undefined;
		const t = setTimeout(async () => {
			try {
				const j = await api('/jobs/' + job.id);
				setJob(j);
				if (j.status === 'done') { onDone && onDone(j); }
				if (j.status === 'failed') { toast(j.error || 'A feladat nem sikerült.', 'error'); }
			} catch (e) { toast(e.message, 'error'); setJob(null); }
		}, 1200);
		return () => clearTimeout(t);
	}, [job]);
	return [job, setJob];
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
	if (value === null || value === undefined || value === '') delete params[key];
	else params[key] = value;
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

/* ── Formázás ────────────────────────────────────── */

export const fmt = {
	num(v) { return v === null || v === undefined || v === '' ? '–' : Number(v).toLocaleString('hu-HU'); },
	date(v) { return v ? new Date(v).toLocaleDateString('hu-HU', { year: 'numeric', month: 'short', day: 'numeric' }) : '–'; },
	datetime(v) { return v ? new Date(v).toLocaleString('hu-HU', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '–'; },
	month(v) { return v ? new Date(v).toLocaleDateString('hu-HU', { year: 'numeric', month: 'long' }) : '–'; },
	pct(v) { return v === null || v === undefined ? '–' : Math.round(v * 100) + '%'; },
	ago(v) {
		if (!v) return '';
		const s = (Date.now() - new Date(v).getTime()) / 1000;
		if (s < 60) return 'most';
		if (s < 3600) return Math.floor(s / 60) + ' perce';
		if (s < 86400) return Math.floor(s / 3600) + ' órája';
		return Math.floor(s / 86400) + ' napja';
	},
};

export const can = (me, cap) => !!me && (me.caps || []).includes(cap);

/* ── Ikonok (inline SVG, 1.6px vonal) ─────────────── */

const ICONS = {
	home: 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
	folder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
	check: 'M5 12l5 5L20 7',
	tasks: 'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01',
	settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
	plus: 'M12 5v14M5 12h14',
	x: 'M6 6l12 12M18 6L6 18',
	search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
	chevron: 'M9 6l6 6-6 6',
	down: 'M6 9l6 6 6-6',
	upload: 'M12 16V4M6 10l6-6 6 6M4 20h16',
	download: 'M12 4v12M6 10l6 6 6-6M4 20h16',
	doc: 'M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9zM14 3v6h6M8 13h8M8 17h6',
	spark: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8zM19 17l.8 2.2L22 20l-2.2.8L19 23l-.8-2.2L16 20l2.2-.8z',
	key: 'M15 7a4 4 0 1 1-3.5 6l-7.5 7H2v-2l1-1h2v-2h2l3.5-3.5A4 4 0 0 1 15 7z',
	sitemap: 'M9 3h6v5H9zM3 16h6v5H3zM15 16h6v5h-6zM12 8v4M6 16v-2h12v2',
	calendar: 'M4 6h16v15H4zM4 10h16M8 3v4M16 3v4',
	layout: 'M3 4h18v16H3zM3 9h18M9 9v11',
	bug: 'M8 9h8v7a4 4 0 0 1-8 0zM9 5l1.5 2M15 5l-1.5 2M4 13h4M16 13h4M5 19l3-2M19 19l-3-2M5 7l3 2M19 7l-3 2',
	link: 'M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1',
	ext: 'M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
	logout: 'M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l5-5-5-5M15 12H3',
	menu: 'M4 6h16M4 12h16M4 18h16',
	refresh: 'M20 11a8 8 0 1 0-2.3 5.7M20 5v6h-6',
	trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13',
	edit: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
	lock: 'M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4',
	eye: 'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
	flag: 'M5 21V4M5 4h11l-2 4 2 4H5',
	chat: 'M4 5h16v11H8l-4 4z',
	bell: 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 20a2 2 0 0 0 4 0',
	crawl: 'M4 12a8 8 0 0 1 14-5.3M20 12a8 8 0 0 1-14 5.3M18 3v4h-4M6 21v-4h4',
};

export function Icon({ name, size = 16 }) {
	return html`<svg class="icon" width=${size} height=${size} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d=${ICONS[name] || ICONS.doc} /></svg>`;
}

/* ── Visszajelzések ──────────────────────────────── */

let pushToast = null;
export function toast(message, kind = 'ok') { if (pushToast) pushToast({ message, kind, id: Math.random() }); }

export function Toasts() {
	const [items, setItems] = useState([]);
	useEffect(() => {
		pushToast = (t) => {
			setItems((list) => [...list, t]);
			setTimeout(() => setItems((list) => list.filter((x) => x.id !== t.id)), t.kind === 'error' ? 6000 : 3200);
		};
		return () => { pushToast = null; };
	}, []);
	return html`<div class="toasts" role="status">${items.map((t) => html`<div key=${t.id} class=${'toast toast--' + t.kind}>${t.message}</div>`)}</div>`;
}

export function errorText(e) {
	if (!e) return '';
	return e.problems && e.problems.length ? e.message + ' ' + e.problems.join(' · ') : e.message;
}

export function Spinner({ label }) {
	return html`<div class="spinner"><span class="spinner__dot"></span>${label ? html`<span>${label}</span>` : ''}</div>`;
}

export function Empty({ icon = 'folder', title, children, action }) {
	return html`<div class="empty"><div class="empty__icon"><${Icon} name=${icon} size="22" /></div><h3>${title}</h3>${children ? html`<p>${children}</p>` : ''}${action || ''}</div>`;
}

export function ErrorBox({ error, onRetry }) {
	if (!error) return null;
	return html`<div class="alert alert--error"><span>${errorText(error)}</span>${onRetry ? html`<button class="btn btn--ghost btn--sm" onClick=${onRetry}>Újra</button>` : ''}</div>`;
}

/* ── Címkék, lépésjelző ──────────────────────────── */

export function StatusPill({ status, meta }) {
	const label = (meta && meta.statuses[status]) || status;
	return html`<span class=${'pill pill--' + status}>${label}</span>`;
}

export function Pill({ kind = '', children, title }) {
	return html`<span class=${'pill ' + (kind ? 'pill--' + kind : '')} title=${title || ''}>${children}</span>`;
}

export function Stepper({ status, meta, compact }) {
	const order = meta.status_order;
	const idx = order.indexOf(status);
	return html`
		<ol class=${'stepper' + (compact ? ' stepper--compact' : '')} aria-label="Projekt státusza">
			${order.map((s, i) => html`
				<li key=${s} class=${'stepper__step' + (i < idx ? ' is-done' : '') + (i === idx ? ' is-current' : '')} title=${meta.statuses[s]}>
					<span class="stepper__dot">${i < idx ? html`<${Icon} name="check" size="11" />` : i + 1}</span>
					${compact ? '' : html`<span class="stepper__label">${meta.statuses[s]}</span>`}
				</li>`)}
		</ol>`;
}

export function Progress({ value }) {
	return html`<div class="progress"><span style=${'width:' + Math.round((value || 0) * 100) + '%'}></span></div>`;
}

export function Avatar({ name, size = 26 }) {
	const initials = (name || '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
	return html`<span class="avatar" style=${`width:${size}px;height:${size}px;font-size:${Math.round(size * 0.4)}px`} title=${name}>${initials}</span>`;
}

/* ── Panelek ─────────────────────────────────────── */

export function Modal({ title, onClose, children, footer, wide }) {
	useEffect(() => {
		const on = (e) => { if (e.key === 'Escape') onClose(); };
		window.addEventListener('keydown', on);
		return () => window.removeEventListener('keydown', on);
	}, []);
	return html`
		<div class="overlay" onMouseDown=${(e) => { if (e.target === e.currentTarget) onClose(); }}>
			<div class=${'modal' + (wide ? ' modal--wide' : '')} role="dialog" aria-modal="true" aria-label=${title}>
				<header class="modal__head"><h2>${title}</h2><button class="icon-btn" onClick=${onClose} aria-label="Bezárás"><${Icon} name="x" /></button></header>
				<div class="modal__body">${children}</div>
				${footer ? html`<footer class="modal__foot">${footer}</footer>` : ''}
			</div>
		</div>`;
}

export function Drawer({ title, onClose, children, footer, subtitle }) {
	useEffect(() => {
		const on = (e) => { if (e.key === 'Escape') onClose(); };
		window.addEventListener('keydown', on);
		return () => window.removeEventListener('keydown', on);
	}, []);
	return html`
		<div class="overlay overlay--drawer" onMouseDown=${(e) => { if (e.target === e.currentTarget) onClose(); }}>
			<aside class="drawer" role="dialog" aria-modal="true" aria-label=${title}>
				<header class="drawer__head"><div><h2>${title}</h2>${subtitle ? html`<p class="muted">${subtitle}</p>` : ''}</div><button class="icon-btn" onClick=${onClose} aria-label="Bezárás"><${Icon} name="x" /></button></header>
				<div class="drawer__body">${children}</div>
				${footer ? html`<footer class="drawer__foot">${footer}</footer>` : ''}
			</aside>
		</div>`;
}

export function Confirm({ text, onYes, onClose, danger, yes = 'Igen' }) {
	return html`<${Modal} title="Megerősítés" onClose=${onClose} footer=${html`
		<button class="btn btn--ghost" onClick=${onClose}>Mégse</button>
		<button class=${'btn ' + (danger ? 'btn--danger' : '')} onClick=${() => { onYes(); onClose(); }}>${yes}</button>`}>
		<p>${text}</p>
	</${Modal}>`;
}

/* ── Űrlapelemek ─────────────────────────────────── */

export function Field({ label, hint, children, wide }) {
	return html`<label class=${'field' + (wide ? ' field--wide' : '')}><span class="field__label">${label}</span>${children}${hint ? html`<span class="field__hint">${hint}</span>` : ''}</label>`;
}

export function Input({ value, onInput, ...rest }) {
	return html`<input class="input" value=${value ?? ''} onInput=${(e) => onInput(e.target.value)} ...${rest} />`;
}

export function Textarea({ value, onInput, rows = 3, ...rest }) {
	return html`<textarea class="input" rows=${rows} value=${value ?? ''} onInput=${(e) => onInput(e.target.value)} ...${rest}></textarea>`;
}

export function Select({ value, onChange, options, placeholder, ...rest }) {
	const entries = Array.isArray(options) ? options : Object.entries(options).map(([k, v]) => [k, v]);
	return html`<select class="input" value=${value ?? ''} onChange=${(e) => onChange(e.target.value)} ...${rest}>
		${placeholder !== undefined ? html`<option value="">${placeholder}</option>` : ''}
		${entries.map(([k, v]) => html`<option key=${k} value=${k}>${v}</option>`)}
	</select>`;
}

/** Címke-lista (vesszővel vagy Enterrel bővíthető). */
export function Tags({ value = [], onChange, placeholder }) {
	const [draft, setDraft] = useState('');
	const add = (raw) => {
		const parts = raw.split(/[,\n]/).map((s) => s.trim()).filter(Boolean);
		if (!parts.length) return;
		const lower = value.map((v) => v.toLowerCase());
		onChange([...value, ...parts.filter((p) => !lower.includes(p.toLowerCase()))]);
		setDraft('');
	};
	return html`<div class="tags">
		${value.map((t, i) => html`<span key=${t} class="tag">${t}<button type="button" aria-label="Törlés" onClick=${() => onChange(value.filter((_, j) => j !== i))}>×</button></span>`)}
		<input class="tags__input" value=${draft} placeholder=${placeholder || 'Írd be, majd Enter'}
			onInput=${(e) => { const v = e.target.value; if (v.includes(',')) add(v); else setDraft(v); }}
			onKeyDown=${(e) => { if (e.key === 'Enter') { e.preventDefault(); add(draft); } if (e.key === 'Backspace' && !draft && value.length) onChange(value.slice(0, -1)); }}
			onBlur=${() => add(draft)} />
	</div>`;
}

export function Checkbox({ checked, onChange, label }) {
	return html`<label class="check"><input type="checkbox" checked=${!!checked} onChange=${(e) => onChange(e.target.checked)} /><span>${label}</span></label>`;
}

/** Soron belüli szerkesztés: kattintásra mező, Enter/elhagyás ment. */
export function InlineEdit({ value, onSave, placeholder = '–', multiline, disabled }) {
	const [editing, setEditing] = useState(false);
	const [draft, setDraft] = useState(value || '');
	useEffect(() => setDraft(value || ''), [value]);
	if (disabled) return html`<span class=${value ? '' : 'muted'}>${value || placeholder}</span>`;
	if (!editing) {
		return html`<button type="button" class=${'inline-edit' + (value ? '' : ' muted')} onClick=${() => setEditing(true)}>${value || placeholder}</button>`;
	}
	const done = () => { setEditing(false); if ((draft || '') !== (value || '')) onSave(draft); };
	const props = {
		class: 'input input--inline', value: draft, autoFocus: true,
		onInput: (e) => setDraft(e.target.value), onBlur: done,
		onKeyDown: (e) => { if (e.key === 'Escape') { setDraft(value || ''); setEditing(false); } if (e.key === 'Enter' && !multiline) done(); },
	};
	return multiline ? html`<textarea rows="3" ...${props}></textarea>` : html`<input ...${props} />`;
}

/** Fül-sor. tabs: [[kulcs, címke, ikon?, darab?]] */
export function Tabs({ tabs, active, onChange }) {
	return html`<nav class="tabs" role="tablist">${tabs.map(([key, label, icon, count]) => html`
		<button key=${key} role="tab" aria-selected=${active === key} class=${'tab' + (active === key ? ' is-active' : '')} onClick=${() => onChange(key)}>
			${icon ? html`<${Icon} name=${icon} size="15" />` : ''}<span>${label}</span>${count !== undefined && count !== null ? html`<span class="tab__count">${count}</span>` : ''}
		</button>`)}</nav>`;
}

/** Egyszerű rendezhető tábla. columns: [{key, label, render?, sort?, class?}] */
export function DataTable({ columns, rows, rowKey = 'id', onRowClick, empty = 'Nincs adat.', initialSort, selectable, selected, onSelect }) {
	const [sort, setSort] = useState(initialSort || null);
	const sorted = useMemo(() => {
		if (!sort) return rows;
		const col = columns.find((c) => c.key === sort.key);
		const get = col && col.sortValue ? col.sortValue : (r) => r[sort.key];
		return [...rows].sort((a, b) => {
			const x = get(a); const y = get(b);
			if (x === y) return 0;
			if (x === null || x === undefined || x === '') return 1;
			if (y === null || y === undefined || y === '') return -1;
			return (x > y ? 1 : -1) * (sort.dir === 'desc' ? -1 : 1);
		});
	}, [rows, sort]);
	const allSelected = selectable && rows.length && rows.every((r) => selected.has(r[rowKey]));
	if (!rows.length) return html`<div class="table-empty muted">${empty}</div>`;
	return html`<div class="table-wrap"><table class="table">
		<thead><tr>
			${selectable ? html`<th class="col-check"><input type="checkbox" checked=${allSelected} onChange=${(e) => onSelect(e.target.checked ? new Set(rows.map((r) => r[rowKey])) : new Set())} aria-label="Összes kijelölése" /></th>` : ''}
			${columns.map((c) => html`<th key=${c.key} class=${(c.class || '') + (c.sort !== false ? ' is-sortable' : '')}
				onClick=${() => c.sort !== false && setSort((s) => (s && s.key === c.key ? { key: c.key, dir: s.dir === 'asc' ? 'desc' : 'asc' } : { key: c.key, dir: c.num ? 'desc' : 'asc' }))}>
				${c.label}${sort && sort.key === c.key ? (sort.dir === 'asc' ? ' ↑' : ' ↓') : ''}</th>`)}
		</tr></thead>
		<tbody>${sorted.map((r) => html`<tr key=${r[rowKey]} class=${onRowClick ? 'is-clickable' : ''} onClick=${onRowClick ? (e) => { if (!e.target.closest('button,a,input,select,textarea')) onRowClick(r); } : undefined}>
			${selectable ? html`<td class="col-check"><input type="checkbox" checked=${selected.has(r[rowKey])} onChange=${(e) => { const n = new Set(selected); if (e.target.checked) n.add(r[rowKey]); else n.delete(r[rowKey]); onSelect(n); }} /></td>` : ''}
			${columns.map((c) => html`<td key=${c.key} class=${c.class || ''}>${c.render ? c.render(r) : (r[c.key] ?? '–')}</td>`)}
		</tr>`)}</tbody>
	</table></div>`;
}

/** Fájlválasztó ejtőzóna. */
export function DropZone({ onFiles, accept, label = 'Húzd ide a fájlt, vagy kattints a tallózáshoz', busy }) {
	const input = useRef(null);
	const [over, setOver] = useState(false);
	return html`<div class=${'dropzone' + (over ? ' is-over' : '') + (busy ? ' is-busy' : '')}
		onClick=${() => !busy && input.current.click()}
		onDragOver=${(e) => { e.preventDefault(); setOver(true); }}
		onDragLeave=${() => setOver(false)}
		onDrop=${(e) => { e.preventDefault(); setOver(false); if (!busy) onFiles([...e.dataTransfer.files]); }}>
		<${Icon} name="upload" size="22" />
		<span>${busy ? 'Feltöltés…' : label}</span>
		<input ref=${input} type="file" multiple accept=${accept} hidden onChange=${(e) => { onFiles([...e.target.files]); e.target.value = ''; }} />
	</div>`;
}

/** AI-javaslat: elfogadás / elvetés. */
export function Suggestion({ label, value, reason, onAccept, onReject }) {
	return html`<div class="suggestion">
		<${Icon} name="spark" size="14" />
		<div class="suggestion__body"><span class="suggestion__label">${label}:</span> <strong>${value}</strong>${reason ? html`<span class="suggestion__reason">${reason}</span>` : ''}</div>
		${onAccept ? html`<button class="btn btn--sm" onClick=${onAccept}>Elfogad</button>` : ''}
		${onReject ? html`<button class="btn btn--sm btn--ghost" onClick=${onReject}>Elvet</button>` : ''}
	</div>`;
}

export function Stat({ label, value, hint }) {
	return html`<div class="stat"><span class="stat__label">${label}</span><span class="stat__value">${value}</span>${hint ? html`<span class="stat__hint">${hint}</span>` : ''}</div>`;
}

export const ROLE_COLORS = { seo: 'seo', writer: 'writer', developer: 'dev', designer: 'design' };
