/**
 * Tartalom és jóváhagyások: naptár és lista, szerkesztő, kiküldés az ügyfélnek jóváhagyásra.
 * Az ügyfél a portál „Jóváhagyás” menüjében dönt; a döntésről e-mail jön.
 */
import { html, useState, useEffect, useRef, api, upload, useApp, navigate, setParam, Icon, Modal, Spinner, Empty, toast, timeAgo, MONTHS_LONG, todayISO } from '../ui.js';
import { RichText } from './richtext.js';

export const STATUS = { draft: 'Piszkozat', pending: 'Jóváhagyásra vár', changes: 'Javítást kért', approved: 'Jóváhagyva', published: 'Megjelent', cancelled: 'Elvetve' };
const TABS = ['all', 'draft', 'pending', 'changes', 'approved', 'published'];
const DAYS = ['H', 'K', 'Sze', 'Cs', 'P', 'Szo', 'V'];
const monthLabel = (m) => { const [y, mm] = m.split('-'); return y + '. ' + MONTHS_LONG[Number(mm) - 1]; };
const shiftMonth = (m, d) => { const [y, mm] = m.split('-').map(Number); const t = new Date(Date.UTC(y, mm - 1 + d, 1)); return t.toISOString().slice(0, 7); };
const pill = (s) => html`<span class=${'pill pill--ap-' + s}>${STATUS[s] || s}</span>`;

function NewItemModal({ clients, types, clientId, date, onClose }) {
	const [f, setF] = useState({ client_id: clientId || '', type: 'social', title: '', channel: '', publish_date: date || '' });
	const [busy, setBusy] = useState(false);
	const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/content', { method: 'POST', body: f }).then((a) => { onClose(); navigate('/content/' + a.id); }).catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};
	return html`
		<${Modal} title="Új anyag jóváhagyásra" onClose=${onClose}>
			<form class="form" onSubmit=${submit}>
				<div class="row">
					<label class="field"><span>Ügyfél</span><select required value=${f.client_id} onChange=${set('client_id')}><option value="">Válassz…</option>${clients.map((c) => html`<option value=${c.id}>${c.name}</option>`)}</select></label>
					<label class="field"><span>Típus</span><select value=${f.type} onChange=${set('type')}>${types.map((t) => html`<option value=${t.key}>${t.label}</option>`)}</select></label>
				</div>
				<label class="field"><span>Cím (az ügyfél nyelvén)</span><input required value=${f.title} onInput=${set('title')} placeholder="pl. Októberi akció — Facebook poszt" /></label>
				<div class="row">
					<label class="field"><span>Csatorna</span><input value=${f.channel} onInput=${set('channel')} placeholder="Facebook, Instagram, Cégprofil…" /></label>
					<label class="field"><span>Tervezett megjelenés</span><input type="date" value=${f.publish_date} onInput=${set('publish_date')} /></label>
				</div>
				<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>Tovább</button></footer>
			</form>
		</${Modal}>`;
}

function Calendar({ month, items, onNew }) {
	const [y, m] = month.split('-').map(Number);
	const first = new Date(Date.UTC(y, m - 1, 1));
	const offset = (first.getUTCDay() + 6) % 7;
	const days = new Date(Date.UTC(y, m, 0)).getUTCDate();
	const cells = [];
	for (let i = 0; i < offset; i++) cells.push(null);
	for (let d = 1; d <= days; d++) cells.push(`${month}-${String(d).padStart(2, '0')}`);
	const today = todayISO();
	return html`
		<div class="cal">
			${DAYS.map((d) => html`<div class="cal__head">${d}</div>`)}
			${cells.map((iso, i) => iso ? html`
				<div key=${iso} class=${'cal__day' + (iso === today ? ' is-today' : '')}>
					<button class="cal__num" title="Új anyag erre a napra" onClick=${() => onNew(iso)}>${Number(iso.slice(8))}</button>
					${items.filter((a) => a.publish_date === iso).map((a) => html`
						<a key=${a.id} class=${'cal__item cal__item--' + a.status} href=${'#/content/' + a.id} title=${a.client + ' · ' + STATUS[a.status]}>
							<strong>${a.client}</strong> ${a.title}
						</a>`)}
				</div>` : html`<div key=${'e' + i} class="cal__day cal__day--empty"></div>`)}
		</div>`;
}

export function Content({ params }) {
	const { boot } = useApp();
	const [data, setData] = useState(null);
	const [creating, setCreating] = useState(null);
	const month = /^\d{4}-\d{2}$/.test(params.month || '') ? params.month : todayISO().slice(0, 7);
	const view = params.view === 'list' ? 'list' : 'calendar';
	const status = params.status || 'all';
	const client = params.client || '';

	useEffect(() => {
		setData(null);
		const q = new URLSearchParams({ ...(client ? { client_id: client } : {}), ...(status !== 'all' ? { status } : {}), ...(view === 'calendar' ? { month } : {}) });
		api('/content?' + q).then(setData).catch((e) => toast(e.message, 'error'));
	}, [month, view, status, client]);

	const undated = data ? data.items.filter((a) => !a.publish_date) : [];
	return html`
		<div class="page page--wide">
			<header class="page__head">
				<div><h1>Tartalom és jóváhagyás</h1><p class="muted">Posztok, cikkek, hirdetések és dokumentumok. Az ügyfél a portálon jóváhagyja, vagy javítást kér.</p></div>
				<button class="btn" onClick=${() => setCreating({ date: '' })}><${Icon} name="plus" /> Új anyag</button>
			</header>
			<div class="toolbar">
				<div class="seg"><button class=${view === 'calendar' ? 'is-active' : ''} onClick=${() => setParam('view', '')}>Naptár</button><button class=${view === 'list' ? 'is-active' : ''} onClick=${() => setParam('view', 'list')}>Lista</button></div>
				${view === 'calendar' ? html`<div class="month-nav"><button class="icon-btn" onClick=${() => setParam('month', shiftMonth(month, -1))} aria-label="Előző hónap"><${Icon} name="chevron" size="16" /></button><strong>${monthLabel(month)}</strong><button class="icon-btn" onClick=${() => setParam('month', shiftMonth(month, 1))} aria-label="Következő hónap"><${Icon} name="chevron" size="16" /></button></div>` : null}
				<select class="meta-select" value=${client} onChange=${(e) => setParam('client', e.target.value)}><option value="">Minden ügyfél</option>${boot.clients.map((c) => html`<option value=${c.id}>${c.name}</option>`)}</select>
				<div class="seg seg--scroll">${TABS.map((k) => html`<button key=${k} class=${status === k ? 'is-active' : ''} onClick=${() => setParam('status', k === 'all' ? '' : k)}>${k === 'all' ? 'Mind' : STATUS[k]}${data && k !== 'all' && data.counts[k] ? html` <em class="seg-count">${data.counts[k]}</em>` : ''}</button>`)}</div>
			</div>
			${!data ? html`<${Spinner} />` : view === 'calendar' ? html`
				<${Calendar} month=${month} items=${data.items} onNew=${(date) => setCreating({ date })} />
				${undated.length ? html`<section class="card undated"><h3>Dátum nélkül</h3>${undated.map((a) => html`<a key=${a.id} class="undated__item" href=${'#/content/' + a.id}>${pill(a.status)} <strong>${a.client}</strong> ${a.title}</a>`)}</section>` : null}` : data.items.length ? html`
				<div class="card table-card">
					<table class="table">
						<thead><tr><th>Anyag</th><th>Ügyfél</th><th>Típus</th><th>Megjelenés</th><th>Státusz</th><th class="right">Hozzászólás</th></tr></thead>
						<tbody>${data.items.map((a) => html`
							<tr key=${a.id} class="row-link" onClick=${() => navigate('/content/' + a.id)}>
								<td><a class="link" href=${'#/content/' + a.id}><strong>${a.title}</strong></a>${a.source === 'seo-os' ? html` <span class="pill">SEO OS</span>` : null}</td>
								<td>${a.client}</td>
								<td class="muted">${(data.types.find((t) => t.key === a.type) || {}).label}${a.channel ? ' · ' + a.channel : ''}</td>
								<td class="muted">${a.publish_date || '—'}</td>
								<td>${pill(a.status)}</td>
								<td class="right muted">${a.comments || ''}</td>
							</tr>`)}</tbody>
					</table>
				</div>` : html`<${Empty} icon="proposal" title="Nincs ilyen anyag" />`}
			${creating ? html`<${NewItemModal} clients=${boot.clients} types=${data ? data.types : []} clientId=${client} date=${creating.date} onClose=${() => setCreating(null)} />` : null}
		</div>`;
}

/* ── Szerkesztő ──────────────────────────────────── */

function FilePicker({ clientId, selected, onChange }) {
	const [files, setFiles] = useState(null);
	const [busy, setBusy] = useState(false);
	const input = useRef(null);
	const load = () => api('/files?client_id=' + clientId).then((d) => setFiles(d.files)).catch(() => setFiles([]));
	useEffect(load, [clientId]);
	const add = async (list) => {
		setBusy(true);
		const ids = [];
		for (const file of Array.from(list)) {
			const fd = new FormData();
			fd.append('file', file); fd.append('client_id', clientId); fd.append('visible', '1'); fd.append('notify', '0');
			try { ids.push((await upload('/files', fd)).id); } catch (e) { toast(file.name + ': ' + e.message, 'error'); }
		}
		setBusy(false);
		await load();
		onChange([...selected, ...ids]);
	};
	if (!files) return html`<${Spinner} />`;
	const chosen = files.filter((f) => selected.includes(f.id));
	return html`
		<div class="file-pick">
			${chosen.map((f) => html`<span key=${f.id} class="file-chip">${/^image\//.test(f.mime) ? html`<img src=${f.url} alt="" />` : html`<${Icon} name="doc" size="14" />`}${f.name}<button type="button" aria-label="Eltávolítás" onClick=${() => onChange(selected.filter((x) => x !== f.id))}>×</button></span>`)}
			<button type="button" class="btn btn--ghost btn--small" disabled=${busy} onClick=${() => input.current.click()}><${Icon} name="up" size="14" /> ${busy ? 'Feltöltés…' : 'Kép vagy fájl'}</button>
			${files.length > chosen.length ? html`<select class="meta-select" value="" onChange=${(e) => e.target.value && onChange([...selected, Number(e.target.value)])}><option value="">A meglévő fájlokból…</option>${files.filter((f) => !selected.includes(f.id)).map((f) => html`<option value=${f.id}>${f.name}</option>`)}</select>` : null}
			<input ref=${input} type="file" multiple hidden onChange=${(e) => add(e.target.files)} />
		</div>`;
}

export function ContentPage({ id }) {
	const [a, setA] = useState(null);
	const [f, setF] = useState(null);
	const [dirty, setDirty] = useState(false);
	const [busy, setBusy] = useState(false);
	const [types, setTypes] = useState([]);
	const [projects, setProjects] = useState([]);
	const [comment, setComment] = useState('');

	const take = (d) => {
		setA(d);
		setF({ title: d.title, type: d.type, channel: d.channel, publish_date: d.publish_date || '', due_date: d.due_date || '', link: d.link, body: d.body, project_id: d.project_id || '', file_ids: d.files.map((x) => x.id) });
		setDirty(false);
	};
	useEffect(() => {
		api('/content/' + id).then((d) => { take(d); api('/pm/projects?client_id=' + d.client_id).then(setProjects).catch(() => {}); }).catch((e) => toast(e.message, 'error'));
		api('/content?status=draft').then((d) => setTypes(d.types)).catch(() => {});
	}, [id]);
	if (!a || !f) return html`<${Spinner} />`;

	const locked = ['approved', 'published'].includes(a.status);
	const edit = (patch) => { setF({ ...f, ...patch }); setDirty(true); };
	const run = (fn, msg) => { setBusy(true); return fn().then((d) => { if (d) take(d); if (msg) toast(msg); }).catch((e) => toast(e.message, 'error')).finally(() => setBusy(false)); };
	const save = () => run(() => api('/content/' + a.id, { method: 'POST', body: { ...f, file_ids: f.file_ids.join(',') } }), 'Mentve.');
	const act = (action, msg, body = {}) => run(async () => {
		if (dirty && !locked) await api('/content/' + a.id, { method: 'POST', body: { ...f, file_ids: f.file_ids.join(',') } });
		return api('/content/' + a.id + '/' + action, { method: 'POST', body });
	}, msg);
	const remove = () => { if (window.confirm('Törlöd az anyagot?')) api('/content/' + a.id, { method: 'DELETE' }).then(() => { toast('Törölve.'); navigate('/content'); }).catch((e) => toast(e.message, 'error')); };
	const send = () => { if (window.confirm('Kiküldöd jóváhagyásra? Az ügyfél e-mailt kap a portál linkjével.')) act('send', 'Kiküldve jóváhagyásra.'); };

	return html`
		<div class="page page--wide">
			<header class="page__head">
				<div>
					<a class="crumb" href="#/content">Tartalom</a>
					<h1 class="content-title">${a.title} ${pill(a.status)}</h1>
					<p class="muted">${a.client}${a.round > 1 ? ' · ' + a.round + '. kör' : ''}${a.source === 'seo-os' ? ' · SEO OS-ből' : ''}</p>
				</div>
				<div class="head-actions">
					<a class="btn btn--ghost" href=${a.portal_url} target="_blank" rel="noopener"><${Icon} name="eye" /> Portál</a>
					${!locked ? html`<button class="btn btn--ghost" disabled=${busy || !dirty} onClick=${save}>Mentés</button>` : null}
					${['draft', 'changes'].includes(a.status) ? html`<button class="btn" disabled=${busy} onClick=${send}><${Icon} name="send" /> ${a.status === 'changes' ? 'Javított változat küldése' : 'Küldés jóváhagyásra'}</button>` : null}
					${a.status === 'approved' ? html`<button class="btn" disabled=${busy} onClick=${() => act('published', 'Megjelentnek jelölve.')}>Megjelent</button>` : null}
				</div>
			</header>
			${a.status === 'changes' && a.decision_note ? html`<div class="alert alert--warn"><strong>Az ügyfél javítást kér:</strong> ${a.decision_note}</div>` : null}
			<div class="inv-grid">
				<section class="card content-edit">
					<label class="field"><span>Cím</span><input value=${f.title} disabled=${locked} onInput=${(e) => edit({ title: e.target.value })} /></label>
					<div class="row row--3">
						<label class="field"><span>Típus</span><select value=${f.type} disabled=${locked} onChange=${(e) => edit({ type: e.target.value })}>${types.map((t) => html`<option value=${t.key}>${t.label}</option>`)}</select></label>
						<label class="field"><span>Csatorna</span><input value=${f.channel} disabled=${locked} onInput=${(e) => edit({ channel: e.target.value })} /></label>
						<label class="field"><span>Megjelenés</span><input type="date" value=${f.publish_date} onInput=${(e) => edit({ publish_date: e.target.value })} /></label>
					</div>
					<div class="field"><span>Szöveg (az ügyfél nyelvén)</span>
						${locked ? html`<div class="doc" dangerouslySetInnerHTML=${{ __html: a.body }}></div>` : html`<${RichText} value=${f.body} onChange=${(body) => edit({ body })} placeholder="A poszt vagy cikk szövege…" />`}
					</div>
					<div class="field"><span>Képek és fájlok</span>${locked ? html`<div class="file-pick">${a.files.map((x) => html`<a class="file-chip" href=${x.url} target="_blank" rel="noopener">${x.name}</a>`)}</div>` : html`<${FilePicker} clientId=${a.client_id} selected=${f.file_ids} onChange=${(file_ids) => edit({ file_ids })} />`}</div>
					<div class="row">
						<label class="field"><span>Link (előnézet, Google Doc, Figma)</span><input type="url" value=${f.link} disabled=${locked} onInput=${(e) => edit({ link: e.target.value })} placeholder="https://…" /></label>
						<label class="field"><span>Projekt</span><select value=${f.project_id} onChange=${(e) => edit({ project_id: e.target.value })}><option value="">—</option>${projects.map((p) => html`<option value=${p.id}>${p.name}</option>`)}</select></label>
					</div>
					<footer class="inv-foot">
						<span>
							${['draft', 'cancelled'].includes(a.status) ? html`<button class="link danger" onClick=${remove}>Törlés</button>` : null}
							${['pending', 'changes'].includes(a.status) ? html`<button class="link danger" onClick=${() => window.confirm('Elveted az anyagot? Az ügyfél nem látja tovább.') && act('cancel', 'Elvetve.')}>Elvetés</button>` : null}
							${['approved', 'published', 'cancelled'].includes(a.status) ? html`<button class="link" onClick=${() => window.confirm('Visszaállítod piszkozatra? Új jóváhagyási kör kell.') && act('draft', 'Piszkozat.')}>Új kör (vissza piszkozatba)</button>` : null}
						</span>
						${dirty ? html`<span class="muted">Nem mentett változás</span>` : null}
					</footer>
				</section>
				<aside class="inv-side">
					<section class="card">
						<h3>Előzmények és hozzászólások</h3>
						<ol class="thread">${a.thread.map((c) => html`
							<li key=${c.id} class=${'thread__' + c.kind + (c.client ? ' thread--client' : '')}>
								<strong>${c.author}</strong> <span class="muted small">${timeAgo(c.at * 1000)}</span>
								<p>${{ sent: 'Jóváhagyásra küldve.', approved: 'Jóváhagyta.', changes: 'Javítást kért.' }[c.kind] || ''} ${c.body !== '—' ? c.body : ''}</p>
							</li>`)}</ol>
						${!a.thread.length ? html`<p class="hint">Még nincs előzmény.</p>` : null}
						<div class="inline-form"><input value=${comment} onInput=${(e) => setComment(e.target.value)} placeholder="Hozzászólás (az ügyfél is látja)…" /><button class="btn btn--small" disabled=${!comment.trim() || busy} onClick=${() => act('comment', 'Elküldve.', { body: comment }).then(() => setComment(''))}>Küldés</button></div>
					</section>
				</aside>
			</div>
		</div>`;
}
