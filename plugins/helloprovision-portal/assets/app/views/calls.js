/**
 * Videóhívások: lista, új hívás, hívás oldal (csatlakozás, leirat, AI-összefoglaló, teendők → feladatok).
 */
import { html, useState, useEffect, useRef, api, useApp, navigate, setParam, Icon, Modal, Spinner, Empty, toast, CFG, InlineText } from '../ui.js';

const VIDEO = CFG.video || { enabled: false, ai: false };

export const CALL_STATUS = {
	live: 'Folyamatban',
	processing: 'Leirat készül',
	done: 'Kész',
	no_transcript: 'Nincs leirat',
	failed: 'Összefoglaló hiba',
};

const when = (ts) => (ts ? new Date(ts * 1000).toLocaleString('hu-HU', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');

function CallPill({ status }) {
	return html`<span class=${'pill pill--call-' + status}>${status === 'live' ? html`<i class="live-dot"></i>` : null}${CALL_STATUS[status] || status}</span>`;
}

function NotConfigured() {
	return html`
		<${Empty} icon="video" title="A videóhívás még nincs beállítva">
			A fejlesztőnek a <code>wp-config.php</code>-ba kell tennie a Daily API kulcsot (<code>HPV_DAILY_API_KEY</code>),
			az összefoglalóhoz az AI kulcsot (<code>HPV_AI_API_KEY</code>). Részletek a fejlesztői dokumentációban.
		</${Empty}>`;
}

/* ── Új hívás ────────────────────────────────────── */

export function NewCallModal({ onClose, clientId, projectId }) {
	const { boot } = useApp();
	const [f, setF] = useState({ client_id: clientId ? String(clientId) : '', project_id: projectId ? String(projectId) : '', title: '', record: false, notify: true });
	const [projects, setProjects] = useState([]);
	const [busy, setBusy] = useState(false);
	const set = (k) => (e) => setF({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

	useEffect(() => {
		if (!f.client_id) { setProjects([]); return; }
		api('/pm/projects?client_id=' + f.client_id).then(setProjects).catch(() => setProjects([]));
	}, [f.client_id]);

	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/video/calls', { method: 'POST', body: { ...f, record: f.record ? 1 : 0, notify: f.notify ? 1 : 0 } })
			.then((c) => { onClose(); navigate('/calls/' + c.id, { join: 1 }); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};

	return html`
		<${Modal} title="Új videóhívás" onClose=${onClose}>
			${!VIDEO.enabled ? html`<${NotConfigured} />` : html`
				<form class="form" onSubmit=${submit}>
					<div class="row">
						<label class="field"><span>Ügyfél</span>
							<select required value=${f.client_id} onChange=${(e) => setF({ ...f, client_id: e.target.value, project_id: '' })}>
								<option value="">— válassz —</option>${boot.clients.map((c) => html`<option value=${c.id}>${c.name}</option>`)}
							</select>
						</label>
						<label class="field"><span>Projekt (nem kötelező)</span>
							<select value=${f.project_id} onChange=${set('project_id')} disabled=${!projects.length}>
								<option value="">— nincs —</option>${projects.map((p) => html`<option value=${p.id}>${p.name}</option>`)}
							</select>
						</label>
					</div>
					<label class="field"><span>Téma</span><input value=${f.title} onInput=${set('title')} placeholder="pl. Honlap terv átnézése (üresen: ügyfél + dátum)" maxlength="120" /></label>
					<label class="toggle"><input type="checkbox" checked=${f.notify} onChange=${set('notify')} /> <span>Meghívó e-mail az ügyfél portál-felhasználóinak</span></label>
					<label class="toggle"><input type="checkbox" checked=${f.record} onChange=${set('record')} /> <span>Videófelvétel is (a leirat mindig készül)</span></label>
					<p class="hint">A hívás linkje az ügyfél chat-csatornájába is bekerül. Mindenki csatlakozás előtt hozzájárul a rögzítéshez és a leirathoz (Floridában minden fél beleegyezése kell); ezt a rendszer naplózza.</p>
					<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}><${Icon} name="video" /> Hívás indítása</button></footer>
				</form>`}
		</${Modal}>`;
}

/* ── Lista ───────────────────────────────────────── */

export function Calls({ params }) {
	const { boot } = useApp();
	const [data, setData] = useState(null);
	const [modal, setModal] = useState(false);
	const client = params.client || '';

	useEffect(() => {
		setData(null);
		api('/video/calls' + (client ? '?client_id=' + client : '')).then(setData).catch((e) => toast(e.message, 'error'));
	}, [client]);

	const calls = data ? data.calls : [];
	const live = calls.filter((c) => c.status === 'live');
	const past = calls.filter((c) => c.status !== 'live');
	const clientName = client ? (boot.clients.find((c) => String(c.id) === String(client)) || {}).name : '';

	return html`
		<div class="page">
			<header class="page__head">
				<div>
					<h1>${clientName ? 'Hívások: ' + clientName : 'Videóhívások'}</h1>
					<p class="muted">Leirat és AI-összefoglaló minden hívásról, a teendők egy kattintással feladatok.</p>
				</div>
				<div class="actions">
					${client ? html`<a class="btn btn--ghost" href="#/calls">Összes hívás</a>` : null}
					<button class="btn" onClick=${() => setModal(true)}><${Icon} name="video" /> Új hívás</button>
				</div>
			</header>
			${!data ? html`<${Spinner} />` : !data.enabled && !calls.length ? html`<${NotConfigured} />` : html`
				${live.length ? html`
					<div class="live-calls">${live.map((c) => html`
						<a key=${c.id} class="live-call" href=${'#/calls/' + c.id}>
							<${CallPill} status="live" />
							<strong>${c.title}</strong>
							<span class="muted">${c.client ? c.client.name : ''} · ${when(c.started_at)}</span>
							<span class="btn btn--small">Csatlakozás</span>
						</a>`)}</div>` : null}
				${past.length ? html`
					<div class="card table-card">
						<table class="table table--calls">
							<thead><tr><th>Téma</th><th>Ügyfél</th><th>Időpont</th><th class="right">Hossz</th><th>Státusz</th><th class="right">Teendők</th></tr></thead>
							<tbody>${past.map((c) => html`
								<tr key=${c.id} onClick=${() => navigate('/calls/' + c.id)}>
									<td><strong>${c.title}</strong>${c.shared ? html` <span class="tag-mini" title="Az ügyfél látja az összefoglalót">portál</span>` : null}${c.project ? html`<div class="muted small">${c.project.name}</div>` : null}</td>
									<td>${c.client ? c.client.name : '—'}</td>
									<td>${when(c.started_at)}</td>
									<td class="right">${c.minutes ? c.minutes + ' perc' : '—'}</td>
									<td><${CallPill} status=${c.status} /></td>
									<td class="right">${c.action_count || '—'}</td>
								</tr>`)}</tbody>
						</table>
					</div>` : !live.length ? html`<${Empty} icon="video" title="Még nem volt hívás">Indíts hívást innen, vagy egy projekt fejlécéből.</${Empty}>` : null}`}
			${modal ? html`<${NewCallModal} onClose=${() => setModal(false)} clientId=${client} />` : null}
		</div>`;
}

/* ── Hívás keret (teljes képernyő) ───────────────── */

function CallFrame({ call, onClose, onEnd }) {
	const ref = useRef(null);
	const [state, setState] = useState('loading');

	useEffect(() => {
		let destroy = null;
		let cancelled = false;
		api('/video/calls/' + call.id + '/join', { method: 'POST' })
			.then((j) => window.HPVCallMount(ref.current, { ...j, dailySrc: VIDEO.daily, onLeft: onClose, onError: (m) => toast(m, 'error') }))
			.then((d) => { if (cancelled) d(); else { destroy = d; setState('in'); } })
			.catch((e) => { toast(e.message, 'error'); onClose(); });
		return () => { cancelled = true; if (destroy) destroy(); };
	}, [call.id]);

	return html`
		<div class="call-frame" role="dialog" aria-label=${'Videóhívás: ' + call.title}>
			<header class="call-frame__bar">
				<span class="call-frame__title"><i class="live-dot"></i> ${call.title}<span class="muted">${call.client ? ' · ' + call.client.name : ''}</span></span>
				<span class="call-frame__rec"><${Icon} name="doc" size="14" /> Leirat${call.record ? ' + felvétel' : ''}</span>
				<button class="btn btn--small btn--ghost" onClick=${onClose}>Kilépés</button>
				<button class="btn btn--small btn--stop" onClick=${onEnd}>Hívás befejezése</button>
			</header>
			<div class="call-frame__body" ref=${ref}>${state === 'loading' ? html`<${Spinner} />` : null}</div>
		</div>`;
}

/* ── Hívás oldal ─────────────────────────────────── */

export function CallPage({ id, params }) {
	const [c, setC] = useState(null);
	const [inCall, setInCall] = useState(false);
	const [q, setQ] = useState('');
	const [projects, setProjects] = useState([]);
	const [busy, setBusy] = useState('');

	const load = () => api('/video/calls/' + id).then(setC).catch((e) => toast(e.message, 'error'));
	useEffect(() => { load(); }, [id]);

	// Élő és feldolgozás alatti hívásnál frissítés 10 mp-enként.
	useEffect(() => {
		if (!c || !['live', 'processing'].includes(c.status) || inCall) return undefined;
		const t = setInterval(() => { if (!document.hidden) load(); }, 10000);
		return () => clearInterval(t);
	}, [c && c.status, inCall]);

	// Létrehozás után azonnal csatlakozás.
	useEffect(() => {
		if (c && c.status === 'live' && params.join) { setParam('join', null); setInCall(true); }
	}, [c && c.id]);

	useEffect(() => {
		if (c && c.client && !c.project && c.ai && c.ai.action_items.length) api('/pm/projects?client_id=' + c.client.id).then(setProjects).catch(() => {});
	}, [c && c.id, c && !!c.project]);

	if (!c) return html`<${Spinner} />`;

	const update = (body) => api('/video/calls/' + id, { method: 'POST', body }).then(setC).catch((e) => toast(e.message, 'error'));
	const end = () => {
		if (!window.confirm('Befejezed a hívást mindenkinek? A leirat és az összefoglaló ezután készül el.')) return;
		setInCall(false);
		api('/video/calls/' + id + '/end', { method: 'POST' }).then((x) => { setC(x); toast('Hívás befejezve. Az összefoglaló pár perc múlva kész.'); }).catch((e) => toast(e.message, 'error'));
	};
	const reprocess = () => {
		setBusy('process');
		api('/video/calls/' + id + '/process', { method: 'POST' }).then(setC).catch((e) => toast(e.message, 'error')).finally(() => setBusy(''));
	};
	const toTask = (i) => {
		setBusy('task' + i);
		api('/video/calls/' + id + '/tasks', { method: 'POST', body: { index: i } })
			.then((r) => { setC(r.call); toast('Feladat létrehozva.'); window.dispatchEvent(new CustomEvent('hpv:changed')); })
			.catch((e) => toast(e.message, 'error')).finally(() => setBusy(''));
	};
	const recordings = () => {
		setBusy('rec');
		api('/video/calls/' + id + '/recordings').then((list) => {
			if (!list.length) toast('A felvétel még nem érhető el (a hívás után pár perc).', 'error');
			list.forEach((r) => window.open(r.url, '_blank', 'noopener'));
		}).catch((e) => toast(e.message, 'error')).finally(() => setBusy(''));
	};

	const ai = c.ai;
	const lines = (c.transcript || '').split('\n').filter((l) => l && (!q || l.toLowerCase().includes(q.toLowerCase())));

	return html`
		<div class="page call-page">
			<a class="crumb" href="#/calls">Hívások</a>
			<header class="page__head">
				<div>
					<h1 class="call-title"><${InlineText} value=${c.title} onSave=${(v) => v.trim() && update({ title: v })} className="inline-title" /></h1>
					<p class="muted">
						${c.client ? html`<a class="chip chip--client" href=${'#/calls?client=' + c.client.id}>${c.client.name}</a>` : null}
						${c.project ? html` <a class="chip" style=${{ '--pc': c.project.color }} href=${'#/projects/' + c.project.id}>${c.project.name}</a>` : null}
						${' '}${when(c.started_at)}${c.minutes ? ' · ' + c.minutes + ' perc' : ''}${c.started_by ? ' · indította: ' + c.started_by.name : ''}
					</p>
				</div>
				<div class="actions"><${CallPill} status=${c.status} /></div>
			</header>

			${c.status === 'live' ? html`
				<section class="card call-live">
					<div>
						<h2>A hívás folyamatban van</h2>
						<p class="muted">Belépéskor elindul a leirat${c.record ? ' és a videófelvétel' : ''}. Az ügyfél a portálon csatlakozik, belépés előtt hozzájárul a rögzítéshez.</p>
					</div>
					<div class="actions">
						<button class="btn" onClick=${() => setInCall(true)}><${Icon} name="video" /> Csatlakozás</button>
						<button class="btn btn--danger" onClick=${end}>Hívás befejezése</button>
					</div>
				</section>` : null}

			${c.status === 'processing' ? html`
				<section class="card call-wait">
					<${Spinner} />
					<div><h2>A leirat és az összefoglaló készül</h2><p class="muted">A hívás vége után általában 2–10 perc. Az oldal magától frissül, és e-mailt is kapsz.</p></div>
					<button class="btn btn--ghost btn--small" onClick=${reprocess} disabled=${busy === 'process'}>Ellenőrzés most</button>
				</section>` : null}

			${c.error && c.status !== 'processing' ? html`
				<div class="notice-bar">
					<span>${c.error}</span>
					${['failed', 'no_transcript'].includes(c.status) || (c.status === 'done' && !c.summary) ? html`<button class="btn btn--small btn--ghost" onClick=${reprocess} disabled=${busy === 'process'}>Újrafeldolgozás</button>` : null}
				</div>` : null}

			${ai ? html`
				<div class="grid-2">
					<section class="card">
						<header class="card__head"><h2>Összefoglaló</h2>
							<label class="toggle small"><input type="checkbox" checked=${c.shared} onChange=${(e) => update({ shared: e.target.checked })} /> <span>Az ügyfél látja a portálon</span></label>
						</header>
						<p class="summary">${ai.summary}</p>
						${ai.decisions.length ? html`<h3 class="h3">Döntések</h3><ul class="bullets">${ai.decisions.map((d, i) => html`<li key=${i}>${d}</li>`)}</ul>` : null}
						${c.shared ? html`<p class="hint">A portálon az összefoglaló, a döntések és az ügyfél teendői látszanak; a belső megjegyzések nem.</p>` : null}
					</section>
					<section class="card">
						<header class="card__head"><h2>Teendők</h2><span class="muted small">${ai.action_items.length}</span></header>
						${!c.project && ai.action_items.length ? html`
							<label class="field small"><span>Projekt a feladatokhoz</span>
								<select value="" onChange=${(e) => e.target.value && update({ project_id: Number(e.target.value) })}>
									<option value="">— válassz projektet —</option>${projects.map((p) => html`<option value=${p.id}>${p.name}</option>`)}
								</select>
							</label>` : null}
						${ai.action_items.length ? html`<ul class="actions-list">${ai.action_items.map((a, i) => html`
							<li key=${i}>
								<span class=${'owner owner--' + a.owner}>${a.owner === 'client' ? 'Ügyfél' : 'Mi'}</span>
								<span class="actions-list__title">${a.title}${a.due ? html`<span class="muted small"> · ${a.due}</span>` : null}</span>
								${a.task_id ? html`<a class="link small" href=${'#/projects/' + (c.project ? c.project.id : '') + '?task=' + a.task_id}><${Icon} name="check" size="14" /> Feladat</a>`
									: html`<button class="btn btn--small btn--ghost" disabled=${!c.project || busy === 'task' + i} onClick=${() => toTask(i)} title=${c.project ? '' : 'Előbb válassz projektet'}><${Icon} name="plus" size="14" /> Feladat</button>`}
							</li>`)}</ul>` : html`<p class="muted">Nem hangzott el konkrét teendő.</p>`}
					</section>
				</div>
				${ai.internal_notes.length ? html`
					<section class="card card--internal">
						<header class="card__head"><h2>Belső megjegyzések</h2><span class="muted small">az ügyfél nem látja</span></header>
						<ul class="bullets">${ai.internal_notes.map((n, i) => html`<li key=${i}>${n}</li>`)}</ul>
					</section>` : null}` : null}

			${c.transcript ? html`
				<section class="card">
					<header class="card__head"><h2>Leirat</h2>
						<div class="actions">
							<input class="search-input small" placeholder="Keresés a leiratban…" value=${q} onInput=${(e) => setQ(e.target.value)} />
							<button class="btn btn--small btn--ghost" onClick=${() => navigator.clipboard.writeText(c.transcript).then(() => toast('Leirat másolva.'))}>Másolás</button>
						</div>
					</header>
					<div class="transcript">${lines.length ? lines.map((l, i) => {
						const m = l.match(/^\[(\d+:\d{2})\]\s*(?:([^:]{1,80}):\s)?(.*)$/);
						return m ? html`<p key=${i}><time>${m[1]}</time>${m[2] ? html`<strong>${m[2]}</strong>` : null}<span>${m[3]}</span></p>` : html`<p key=${i}><span>${l}</span></p>`;
					}) : html`<p class="muted">Nincs találat.</p>`}</div>
				</section>` : null}

			<footer class="call-foot muted small">
				${c.record && c.status !== 'live' ? html`<button class="btn btn--small btn--ghost" onClick=${recordings} disabled=${busy === 'rec'}><${Icon} name="play" size="14" /> Felvétel letöltése</button>` : null}
				${c.consents.length ? html`<span>Hozzájárult a rögzítéshez: ${c.consents.map((x) => x.name + (x.role === 'client' ? ' (ügyfél)' : '')).join(', ')}</span>` : null}
			</footer>

			${inCall ? html`<${CallFrame} call=${c} onClose=${() => { setInCall(false); load(); }} onEnd=${end} />` : null}
		</div>`;
}
