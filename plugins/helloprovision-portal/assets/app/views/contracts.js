/**
 * Szerződések: lista, új szerződés (mintából, AI-val), szerkesztő, AI módosítás, kiküldés aláírásra.
 */
import { html, useState, useEffect, api, useApp, navigate, Icon, Modal, Spinner, Empty, toast, InlineText, timeAgo } from '../ui.js';
import { RichText } from './richtext.js';

export const CONTRACT_STATUS = { draft: 'Piszkozat', sent: 'Aláírásra vár', signed: 'Aláírva', void: 'Visszavonva' };
const LANG = { en: 'Angol', hu: 'Magyar' };

const when = (ts) => (ts ? new Date(ts * 1000).toLocaleDateString('hu-HU', { month: 'short', day: 'numeric' }) : '—');

/** Hosszú AI munka közben: türelmi üzenet. */
export function AiWait({ label }) {
	return html`<div class="ai-wait"><${Spinner} /><div><strong>${label}</strong><p class="muted">Az AI dolgozik a saját mintátokból. Ez általában 20–60 másodperc.</p></div></div>`;
}

/* ── Új szerződés ────────────────────────────────── */

export function NewContractModal({ onClose, clientId, proposalId }) {
	const { boot } = useApp();
	const [f, setF] = useState({ client_id: clientId ? String(clientId) : '', template_id: '', project_id: '', proposal_id: proposalId ? String(proposalId) : '', language: '', instructions: '', ai: true });
	const [ctx, setCtx] = useState(null);
	const [templates, setTemplates] = useState([]);
	const [aiOn, setAiOn] = useState(false);
	const [busy, setBusy] = useState(false);
	const set = (k) => (e) => setF({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

	useEffect(() => { api('/docs/templates?type=contract').then((r) => { setTemplates(r.templates); setAiOn(r.ai); setF((x) => ({ ...x, ai: r.ai })); }).catch(() => {}); }, []);
	useEffect(() => {
		if (!f.client_id) { setCtx(null); return; }
		api('/docs/context?client_id=' + f.client_id).then((c) => {
			setCtx(c);
			setF((x) => ({ ...x, language: c.language, template_id: x.template_id || String((templates.find((t) => t.language === c.language) || {}).id || '') }));
		}).catch(() => setCtx(null));
	}, [f.client_id, templates.length]);

	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/docs/contracts', { method: 'POST', body: { ...f, ai: f.ai ? 1 : 0 } })
			.then((c) => { onClose(); navigate('/contracts/' + c.id); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};

	return html`
		<${Modal} title="Új szerződés" onClose=${busy ? () => {} : onClose} wide>
			${busy && f.ai ? html`<${AiWait} label="A szerződés készül…" />` : html`
				<form class="form" onSubmit=${submit}>
					<div class="row">
						<label class="field"><span>Ügyfél</span>
							<select required value=${f.client_id} onChange=${set('client_id')}><option value="">— válassz —</option>${boot.clients.map((c) => html`<option value=${c.id}>${c.name}${c.country === 'HU' ? ' (HU)' : ''}</option>`)}</select>
						</label>
						<label class="field"><span>Minta</span>
							<select value=${f.template_id} onChange=${set('template_id')}><option value="">— minta nélkül —</option>${templates.map((t) => html`<option value=${t.id}>${t.name} (${LANG[t.language]})</option>`)}</select>
						</label>
						<label class="field"><span>Nyelv</span>
							<select value=${f.language} onChange=${set('language')}><option value="en">Angol</option><option value="hu">Magyar</option></select>
						</label>
					</div>
					${ctx ? html`
						<div class="row">
							<label class="field"><span>Projekt (nem kötelező)</span>
								<select value=${f.project_id} onChange=${set('project_id')}><option value="">—</option>${ctx.projects.map((p) => html`<option value=${p.id}>${p.name}</option>`)}</select>
							</label>
							<label class="field"><span>Elfogadott ajánlatból</span>
								<select value=${f.proposal_id} onChange=${set('proposal_id')}><option value="">— az aktív szolgáltatásokból —</option>${ctx.proposals.filter((p) => p.status === 'accepted').map((p) => html`<option value=${p.id}>${p.number} ${p.title}</option>`)}</select>
							</label>
						</div>` : null}
					${!templates.length ? html`<p class="hint">Még nincs szerződésminta. A <a href="#/templates?type=contract">Minták</a> oldalon másold be a saját szerződésedet, és az AI abból dolgozik.</p>` : null}
					<label class="toggle"><input type="checkbox" checked=${f.ai} disabled=${!aiOn} onChange=${set('ai')} /> <span>Az AI szabja az ügyfélre (név, cím, szolgáltatások, díjak)${aiOn ? '' : ' — nincs AI kulcs beállítva'}</span></label>
					${f.ai ? html`<label class="field"><span>Utasítás az AI-nak (nem kötelező)</span><textarea rows="3" value=${f.instructions} onInput=${set('instructions')} placeholder="pl. 12 hónapos határozott idő, 50% előleg, a tárhely nem része"></textarea></label>` : null}
					<p class="hint">Ami hiányzik, azt az AI <mark>[[TODO: …]]</mark> jelöléssel hagyja; ilyen résszel a szerződés nem küldhető ki.</p>
					<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>${f.ai ? html`<${Icon} name="sparkle" /> Szerződés készítése` : 'Létrehozás'}</button></footer>
				</form>`}
		</${Modal}>`;
}

/* ── Lista ───────────────────────────────────────── */

export function Contracts({ params }) {
	const [list, setList] = useState(null);
	const [modal, setModal] = useState(false);
	const [status, setStatus] = useState('open');

	useEffect(() => { api('/docs/contracts' + (params.client ? '?client_id=' + params.client : '')).then(setList).catch((e) => toast(e.message, 'error')); }, [params.client]);

	const shown = (list || []).filter((c) => status === 'all' || (status === 'open' ? ['draft', 'sent'].includes(c.status) : c.status === status));
	return html`
		<div class="page">
			<header class="page__head">
				<div><h1>Szerződések</h1><p class="muted">A saját mintáitokból, az ügyfélre szabva, elektronikus aláírással.</p></div>
				<div class="actions">
					<a class="btn btn--ghost" href="#/templates?type=contract"><${Icon} name="template" /> Minták</a>
					<button class="btn" onClick=${() => setModal(true)}><${Icon} name="plus" /> Új szerződés</button>
				</div>
			</header>
			<div class="toolbar"><div class="seg">${[['open', 'Folyamatban'], ['signed', 'Aláírva'], ['all', 'Mind']].map(([k, l]) => html`<button key=${k} class=${status === k ? 'is-active' : ''} onClick=${() => setStatus(k)}>${l}</button>`)}</div></div>
			${!list ? html`<${Spinner} />` : shown.length ? html`
				<div class="card table-card">
					<table class="table table--click">
						<thead><tr><th>Szerződés</th><th>Ügyfél</th><th>Státusz</th><th>Nyelv</th><th class="right">Módosítva</th></tr></thead>
						<tbody>${shown.map((c) => html`
							<tr key=${c.id} onClick=${() => navigate('/contracts/' + c.id)}>
								<td><strong>${c.title}</strong>${c.todos ? html` <span class="tag-todo">${c.todos} kitöltetlen</span>` : null}</td>
								<td>${c.client ? c.client.name : '—'}</td>
								<td><span class=${'pill pill--doc-' + c.status}>${CONTRACT_STATUS[c.status] || c.status}</span></td>
								<td>${LANG[c.language] || c.language}</td>
								<td class="right muted">${timeAgo(c.updated_at * 1000)}</td>
							</tr>`)}</tbody>
					</table>
				</div>` : html`<${Empty} icon="doc" title="Nincs ilyen szerződés">Új szerződés a mintádból, egy kattintással.</${Empty}>`}
			${modal ? html`<${NewContractModal} onClose=${() => setModal(false)} clientId=${params.client} />` : null}
		</div>`;
}

/* ── Szerkesztő ──────────────────────────────────── */

export function ContractPage({ id }) {
	const [c, setC] = useState(null);
	const [saved, setSaved] = useState('');
	const [revise, setRevise] = useState('');
	const [busy, setBusy] = useState('');

	useEffect(() => { api('/docs/contracts/' + id).then(setC).catch((e) => toast(e.message, 'error')); }, [id]);
	if (!c) return html`<${Spinner} />`;

	const editable = c.status === 'draft' || c.status === 'sent';
	const save = (body) => {
		setSaved('Mentés…');
		return api('/docs/contracts/' + id, { method: 'POST', body }).then((x) => { setC(x); setSaved('Mentve'); }).catch((e) => { toast(e.message, 'error'); setSaved(''); });
	};
	const send = () => {
		if (!window.confirm(c.status === 'sent' ? 'Újra elküldöd az ügyfélnek?' : 'Elküldöd aláírásra? Az ügyfél e-mailt kap, és a portálon írhatja alá.')) return;
		setBusy('send');
		api('/docs/contracts/' + id + '/send', { method: 'POST' }).then((x) => { setC(x); toast('Kiküldve aláírásra.'); }).catch((e) => toast(e.message, 'error')).finally(() => setBusy(''));
	};
	const doRevise = () => {
		if (!revise.trim()) return;
		setBusy('revise');
		api('/docs/contracts/' + id + '/revise', { method: 'POST', body: { instructions: revise } })
			.then((x) => { setC(x); setRevise(''); toast('Az AI módosította a szerződést.'); })
			.catch((e) => toast(e.message, 'error')).finally(() => setBusy(''));
	};
	const remove = () => {
		if (!window.confirm(c.status === 'draft' ? 'Törlöd a piszkozatot?' : 'Visszavonod a szerződést? (A nyoma megmarad.)')) return;
		api('/docs/contracts/' + id, { method: 'DELETE' }).then((r) => { if (r.deleted) navigate('/contracts'); else setC({ ...c, status: 'void' }); }).catch((e) => toast(e.message, 'error'));
	};

	return html`
		<div class="page doc-page">
			<a class="crumb" href="#/contracts">Szerződések</a>
			<header class="page__head">
				<div class="doc-head">
					<h1>${editable ? html`<${InlineText} value=${c.title} onSave=${(v) => v.trim() && save({ title: v })} className="inline-title" />` : c.title}</h1>
					<p class="muted">
						${c.client ? html`<a class="chip chip--client" href=${'#/contracts?client=' + c.client.id}>${c.client.name}</a>` : null}
						<span class=${'pill pill--doc-' + c.status}>${CONTRACT_STATUS[c.status]}</span>
						${' '}${LANG[c.language]}${c.sent_at ? ' · kiküldve ' + when(c.sent_at) : ''}${saved ? html` · <span class="saved">${saved}</span>` : ''}
					</p>
				</div>
				<div class="actions">
					<a class="btn btn--ghost" href=${c.preview} target="_blank" rel="noopener"><${Icon} name="eye" /> Ahogy az ügyfél látja</a>
					${editable ? html`<button class="btn" onClick=${send} disabled=${busy === 'send'}><${Icon} name="send" /> ${c.status === 'sent' ? 'Újraküldés' : 'Kiküldés aláírásra'}</button>` : null}
				</div>
			</header>

			${c.todos && editable ? html`<div class="notice-bar"><span><strong>${c.todos} kitöltetlen rész</strong> ([[TODO]]): pótold, mielőtt kiküldöd.</span></div>` : null}
			${c.status === 'signed' && c.signer ? html`
				<section class="card signed-card">
					<h2><${Icon} name="check" /> Aláírva</h2>
					<p>${c.signer.name} (${c.signer.email}) · ${new Date(c.signed_at * 1000).toLocaleString('hu-HU')} · IP ${c.signer.ip}</p>
					<p class="muted small">Dokumentum lenyomat (SHA-256): <code>${c.signer.hash}</code></p>
				</section>` : null}

			<div class="doc-layout">
				<section class="card doc-editor">
					<${RichText} value=${c.body} readOnly=${!editable} onChange=${(body) => save({ body })} placeholder="A szerződés szövege…" />
				</section>
				${editable ? html`
					<aside class="doc-side">
						<section class="card">
							<h2 class="side-title"><${Icon} name="sparkle" /> Módosítás AI-val</h2>
							${busy === 'revise' ? html`<${AiWait} label="Az AI módosítja…" />` : html`
								<textarea class="ai-input" rows="5" value=${revise} onInput=${(e) => setRevise(e.target.value)} placeholder="pl. A fizetési határidő legyen 30 nap. Tegyél bele egy titoktartási pontot."></textarea>
								<button class="btn btn--small" onClick=${doRevise} disabled=${!revise.trim()}>Módosítás</button>
								<p class="hint">Az AI csak azt változtatja, amit kérsz; a többi szöveg marad.</p>`}
						</section>
						<section class="card">
							<h2 class="side-title">Nyelv</h2>
							<select class="meta-select" value=${c.language} onChange=${(e) => save({ language: e.target.value })}><option value="en">Angol</option><option value="hu">Magyar</option></select>
						</section>
						<button class="link danger" onClick=${remove}>${c.status === 'draft' ? 'Piszkozat törlése' : 'Visszavonás'}</button>
					</aside>` : null}
			</div>
		</div>`;
}
