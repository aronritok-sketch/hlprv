/**
 * Ajánlatok: lista, új ajánlat (AI: minta + brief + hívások), szerkesztő (fejezetek, ütemterv, árak),
 * kiküldés, követés, elfogadás után szerződés / számla / előfizetés / projekt.
 */
import { html, useState, useEffect, useRef, api, useApp, navigate, Icon, Modal, Spinner, Empty, toast, InlineText, money, timeAgo } from '../ui.js';
import { RichText } from './richtext.js';
import { AiWait } from './contracts.js';

export const PROPOSAL_STATUS = { draft: 'Piszkozat', sent: 'Kiküldve', viewed: 'Megnyitotta', accepted: 'Elfogadva', declined: 'Elutasítva', expired: 'Lejárt', void: 'Visszavonva' };
const RECURRING = { one_time: 'Egyszeri', monthly: 'Havi', yearly: 'Éves' };

const totalsText = (t, cur) => [t.one_time ? money(t.one_time, cur) : '', t.monthly ? money(t.monthly, cur) + ' / hó' : '', t.yearly ? money(t.yearly, cur) + ' / év' : ''].filter(Boolean).join(' + ') || '—';
const lineCents = (i) => Math.round((parseFloat(i.qty) || 0) * Math.round((parseFloat(i.unit_price) || 0) * 100));

/* ── Új ajánlat ──────────────────────────────────── */

function NewProposalModal({ onClose, clientId }) {
	const { boot } = useApp();
	const [clients, setClients] = useState(boot.clients);
	const [f, setF] = useState({ client_id: clientId ? String(clientId) : '', template_id: '', language: '', brief: '', call_ids: [], ai: true });
	const [lead, setLead] = useState(null); // új érdeklődő űrlap
	const [ctx, setCtx] = useState(null);
	const [templates, setTemplates] = useState([]);
	const [aiOn, setAiOn] = useState(false);
	const [busy, setBusy] = useState(false);
	const set = (k) => (e) => setF({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

	useEffect(() => { api('/docs/templates?type=proposal').then((r) => { setTemplates(r.templates); setAiOn(r.ai); setF((x) => ({ ...x, ai: r.ai })); }).catch(() => {}); }, []);
	useEffect(() => {
		if (!f.client_id) { setCtx(null); return; }
		api('/docs/context?client_id=' + f.client_id).then((c) => {
			setCtx(c);
			setF((x) => ({ ...x, language: c.language, call_ids: c.calls.slice(0, 2).map((k) => k.id), template_id: x.template_id || String((templates.find((t) => t.language === c.language) || {}).id || '') }));
		}).catch(() => setCtx(null));
	}, [f.client_id, templates.length]);

	const saveLead = (e) => {
		e.preventDefault();
		api('/docs/leads', { method: 'POST', body: lead }).then((c) => {
			setClients([...clients, c]);
			boot.clients.push(c);
			setF({ ...f, client_id: String(c.id) });
			setLead(null);
			toast('Érdeklődő felvéve.');
		}).catch((err) => toast(err.message, 'error'));
	};
	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/docs/proposals', { method: 'POST', body: { ...f, ai: f.ai ? 1 : 0 } })
			.then((p) => { onClose(); navigate('/proposals/' + p.id); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};
	const toggleCall = (id) => setF({ ...f, call_ids: f.call_ids.includes(id) ? f.call_ids.filter((x) => x !== id) : [...f.call_ids, id] });

	if (lead) {
		const setL = (k) => (e) => setLead({ ...lead, [k]: e.target.value });
		return html`
			<${Modal} title="Új érdeklődő" onClose=${() => setLead(null)}>
				<form class="form" onSubmit=${saveLead}>
					<label class="field"><span>Cégnév</span><input required value=${lead.name} onInput=${setL('name')} autoFocus /></label>
					<div class="row">
						<label class="field"><span>Kapcsolattartó</span><input value=${lead.contact_name} onInput=${setL('contact_name')} /></label>
						<label class="field"><span>E-mail</span><input type="email" value=${lead.email} onInput=${setL('email')} /></label>
					</div>
					<div class="row">
						<label class="field"><span>Ország</span><select value=${lead.country} onChange=${setL('country')}><option value="US">USA</option><option value="HU">Magyarország</option></select></label>
						<label class="field"><span>Weboldal</span><input value=${lead.website} onInput=${setL('website')} placeholder="https://" /></label>
					</div>
					<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${() => setLead(null)}>Vissza</button><button class="btn">Felvétel</button></footer>
				</form>
			</${Modal}>`;
	}

	return html`
		<${Modal} title="Új ajánlat" onClose=${busy ? () => {} : onClose} wide>
			${busy && f.ai ? html`<${AiWait} label="Az ajánlat készül…" />` : html`
				<form class="form" onSubmit=${submit}>
					<div class="row">
						<label class="field"><span>Ügyfél vagy érdeklődő</span>
							<select required value=${f.client_id} onChange=${set('client_id')}><option value="">— válassz —</option>${clients.map((c) => html`<option value=${c.id}>${c.name}${c.status === 'lead' ? ' (érdeklődő)' : ''}${c.country === 'HU' ? ' · HU' : ''}</option>`)}</select>
						</label>
						<div class="field"><span> </span><button type="button" class="btn btn--ghost" onClick=${() => setLead({ name: '', contact_name: '', email: '', country: 'US', website: '' })}><${Icon} name="plus" /> Új érdeklődő</button></div>
					</div>
					<div class="row">
						<label class="field"><span>Minta</span>
							<select value=${f.template_id} onChange=${set('template_id')}><option value="">— minta nélkül —</option>${templates.map((t) => html`<option value=${t.id}>${t.name} (${t.language === 'hu' ? 'magyar' : 'angol'})</option>`)}</select>
						</label>
						<label class="field"><span>Nyelv</span><select value=${f.language} onChange=${set('language')}><option value="en">Angol</option><option value="hu">Magyar</option></select></label>
					</div>
					${f.ai ? html`
						<label class="field"><span>Brief: mit szeretne az ügyfél, mit ajánlunk, keret, határidő</span>
							<textarea rows="5" value=${f.brief} onInput=${set('brief')} placeholder="pl. Új, gyors weboldal 8 aloldallal, Naples és Fort Myers szolgáltatási oldalak, helyi SEO havidíjjal. Keret kb. $6k. Szezon előtt, március 1-ig kész."></textarea>
						</label>
						${ctx && ctx.calls.length ? html`
							<div class="field"><span>Hívás-összefoglalók felhasználása</span>
								<div class="check-list">${ctx.calls.map((k) => html`
									<label key=${k.id} class="check-item"><input type="checkbox" checked=${f.call_ids.includes(k.id)} onChange=${() => toggleCall(k.id)} /><span><strong>${k.title}</strong> <span class="muted small">${k.date}</span><br /><span class="muted small">${k.summary.slice(0, 160)}${k.summary.length > 160 ? '…' : ''}</span></span></label>`)}
								</div>
							</div>` : null}` : null}
					${!templates.length ? html`<p class="hint">Még nincs ajánlatminta. A <a href="#/templates?type=proposal">Minták</a> oldalon másold be a saját ajánlatodat, és az AI annak a szerkezetét és hangnemét követi.</p>` : null}
					<label class="toggle"><input type="checkbox" checked=${f.ai} disabled=${!aiOn} onChange=${set('ai')} /> <span>Az AI megírja (fejezetek, ütemterv, árak)${aiOn ? '' : ' — nincs AI kulcs beállítva'}</span></label>
					<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>${f.ai ? html`<${Icon} name="sparkle" /> Ajánlat készítése` : 'Létrehozás'}</button></footer>
				</form>`}
		</${Modal}>`;
}

/* ── Lista ───────────────────────────────────────── */

export function Proposals({ params }) {
	const [list, setList] = useState(null);
	const [modal, setModal] = useState(false);
	const [tab, setTab] = useState('open');

	useEffect(() => { api('/docs/proposals' + (params.client ? '?client_id=' + params.client : '')).then(setList).catch((e) => toast(e.message, 'error')); }, [params.client]);

	const groups = { open: ['draft', 'sent', 'viewed'], accepted: ['accepted'], closed: ['declined', 'expired', 'void'] };
	const shown = (list || []).filter((p) => tab === 'all' || groups[tab].includes(p.status));
	const won = (list || []).filter((p) => p.status === 'accepted').length;
	const decided = (list || []).filter((p) => ['accepted', 'declined', 'expired'].includes(p.status)).length;

	return html`
		<div class="page">
			<header class="page__head">
				<div><h1>Ajánlatok</h1><p class="muted">${list && decided ? 'Elfogadási arány: ' + Math.round((100 * won) / decided) + '% (' + won + '/' + decided + ')' : 'AI-val a saját mintáitokból, márkázott ajánlat oldallal és online elfogadással.'}</p></div>
				<div class="actions">
					<a class="btn btn--ghost" href="#/templates?type=proposal"><${Icon} name="template" /> Minták</a>
					<button class="btn" onClick=${() => setModal(true)}><${Icon} name="plus" /> Új ajánlat</button>
				</div>
			</header>
			<div class="toolbar"><div class="seg">${[['open', 'Nyitott'], ['accepted', 'Elfogadott'], ['closed', 'Lezárt'], ['all', 'Mind']].map(([k, l]) => html`<button key=${k} class=${tab === k ? 'is-active' : ''} onClick=${() => setTab(k)}>${l}</button>`)}</div></div>
			${!list ? html`<${Spinner} />` : shown.length ? html`
				<div class="card table-card">
					<table class="table table--click">
						<thead><tr><th>Ajánlat</th><th>Ügyfél</th><th>Státusz</th><th class="right">Érték</th><th class="right">Megnyitás</th></tr></thead>
						<tbody>${shown.map((p) => html`
							<tr key=${p.id} onClick=${() => navigate('/proposals/' + p.id)}>
								<td><strong>${p.title}</strong><div class="muted small">${p.number}</div></td>
								<td>${p.client ? p.client.name : '—'}${p.client && p.client.status === 'lead' ? html` <span class="tag-mini tag-mini--lead">érdeklődő</span>` : null}</td>
								<td><span class=${'pill pill--prop-' + p.status}>${PROPOSAL_STATUS[p.status] || p.status}</span></td>
								<td class="right">${totalsText(p.totals, p.currency)}</td>
								<td class="right muted">${p.view_count ? p.view_count + '× · ' + timeAgo(p.last_viewed_at * 1000) : '—'}</td>
							</tr>`)}</tbody>
					</table>
				</div>` : html`<${Empty} icon="proposal" title="Nincs ilyen ajánlat">Az AI egy perc alatt megírja a briefből és a hívás jegyzeteiből.</${Empty}>`}
			${modal ? html`<${NewProposalModal} onClose=${() => setModal(false)} clientId=${params.client} />` : null}
		</div>`;
}

/* ── Szerkesztő részei ───────────────────────────── */

function SectionsEditor({ sections, onChange }) {
	const upd = (i, patch) => onChange(sections.map((s, k) => (k === i ? { ...s, ...patch } : s)));
	const move = (i, d) => { const a = [...sections]; const [x] = a.splice(i, 1); a.splice(i + d, 0, x); onChange(a); };
	return html`
		<div class="sections">
			${sections.map((s, i) => html`
				<section key=${i + ':' + sections.length} class="card section-card">
					<div class="section-card__head">
						<span class="section-num">${String(i + 1).padStart(2, '0')}</span>
						<input class="section-title" value=${s.title} placeholder="Fejezet címe" onChange=${(e) => upd(i, { title: e.target.value })} />
						<button type="button" class="icon-btn" disabled=${i === 0} onClick=${() => move(i, -1)} aria-label="Fel"><${Icon} name="up" /></button>
						<button type="button" class="icon-btn" disabled=${i === sections.length - 1} onClick=${() => move(i, 1)} aria-label="Le"><${Icon} name="down" /></button>
						<button type="button" class="icon-btn" onClick=${() => window.confirm('Törlöd a fejezetet?') && onChange(sections.filter((_, k) => k !== i))} aria-label="Törlés"><${Icon} name="trash" /></button>
					</div>
					<${RichText} compact value=${s.html} onChange=${(h) => upd(i, { html: h })} placeholder="A fejezet szövege…" />
				</section>`)}
			<button type="button" class="add-row" onClick=${() => onChange([...sections, { title: '', html: '' }])}><${Icon} name="plus" /> Új fejezet</button>
		</div>`;
}

function TimelineEditor({ timeline, onChange }) {
	const upd = (i, patch) => onChange(timeline.map((s, k) => (k === i ? { ...s, ...patch } : s)));
	return html`
		<div class="grid-rows">
			${timeline.map((t, i) => html`
				<div key=${i + ':' + timeline.length} class="grid-row grid-row--timeline">
					<input value=${t.phase} placeholder="Szakasz" onChange=${(e) => upd(i, { phase: e.target.value })} />
					<input value=${t.duration} placeholder="Időtartam (pl. 2 hét)" onChange=${(e) => upd(i, { duration: e.target.value })} />
					<input value=${t.description} placeholder="Leírás" onChange=${(e) => upd(i, { description: e.target.value })} />
					<button type="button" class="icon-btn" onClick=${() => onChange(timeline.filter((_, k) => k !== i))} aria-label="Törlés"><${Icon} name="x" /></button>
				</div>`)}
			<button type="button" class="add-row" onClick=${() => onChange([...timeline, { phase: '', duration: '', description: '' }])}><${Icon} name="plus" /> Szakasz</button>
		</div>`;
}

function PricingEditor({ pricing, currency, onChange }) {
	const upd = (i, patch) => onChange(pricing.map((s, k) => (k === i ? { ...s, ...patch } : s)));
	const sum = (rec) => pricing.filter((p) => p.recurring === rec && !p.optional).reduce((a, p) => a + lineCents(p), 0);
	const opt = pricing.filter((p) => p.optional).reduce((a, p) => a + lineCents(p), 0);
	return html`
		<div class="grid-rows">
			<div class="grid-row grid-row--price grid-row--head"><span>Tétel és leírás</span><span>Menny.</span><span>Egységár (${currency})</span><span>Díj</span><span>Opció</span><span></span></div>
			${pricing.map((p, i) => html`
				<div key=${i + ':' + pricing.length} class=${'grid-row grid-row--price' + (p.optional ? ' is-optional' : '')}>
					<span class="stack">
						<input value=${p.name} placeholder="Tétel" onChange=${(e) => upd(i, { name: e.target.value })} />
						<input class=${'small' + (/\[\[TODO/.test(p.description) ? ' has-todo' : '')} value=${p.description} placeholder="Rövid leírás" onChange=${(e) => upd(i, { description: e.target.value })} />
					</span>
					<input type="number" step="0.5" min="0" value=${p.qty} onChange=${(e) => upd(i, { qty: e.target.value })} />
					<input type="number" step="0.01" min="0" value=${p.unit_price} onChange=${(e) => upd(i, { unit_price: e.target.value })} />
					<select value=${p.recurring} onChange=${(e) => upd(i, { recurring: e.target.value })}>${Object.entries(RECURRING).map(([k, l]) => html`<option value=${k}>${l}</option>`)}</select>
					<label class="opt" title="Választható: az ügyfél bejelölheti"><input type="checkbox" checked=${p.optional} onChange=${(e) => upd(i, { optional: e.target.checked })} /></label>
					<button type="button" class="icon-btn" onClick=${() => onChange(pricing.filter((_, k) => k !== i))} aria-label="Törlés"><${Icon} name="x" /></button>
				</div>`)}
			<button type="button" class="add-row" onClick=${() => onChange([...pricing, { name: '', description: '', qty: 1, unit_price: '0', recurring: 'one_time', optional: false }])}><${Icon} name="plus" /> Tétel</button>
			<div class="price-totals">
				${sum('one_time') ? html`<span>Egyszeri: <strong>${money(sum('one_time'), currency)}</strong></span>` : null}
				${sum('monthly') ? html`<span>Havi: <strong>${money(sum('monthly'), currency)}</strong></span>` : null}
				${sum('yearly') ? html`<span>Éves: <strong>${money(sum('yearly'), currency)}</strong></span>` : null}
				${opt ? html`<span class="muted">+ választható: ${money(opt, currency)}</span>` : null}
			</div>
		</div>`;
}

function SendModal({ p, onClose, onSent }) {
	const [to, setTo] = useState('');
	const [message, setMessage] = useState('');
	const [busy, setBusy] = useState(false);
	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/docs/proposals/' + p.id + '/send', { method: 'POST', body: { to, message } })
			.then((x) => { toast('Ajánlat elküldve.'); onSent(x); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};
	return html`
		<${Modal} title="Ajánlat küldése" onClose=${onClose}>
			<form class="form" onSubmit=${submit}>
				<label class="field"><span>Címzett (üresen: az ügyfél számlázási e-mailje)</span><input type="email" value=${to} onInput=${(e) => setTo(e.target.value)} placeholder="nev@ceg.com" /></label>
				<label class="field"><span>Személyes üzenet (nem kötelező)</span><textarea rows="4" value=${message} onInput=${(e) => setMessage(e.target.value)} placeholder=${p.language === 'hu' ? 'Kedves Anna! Ahogy megbeszéltük…' : 'Hi Mia, as discussed…'}></textarea></label>
				<p class="hint">Az e-mail ${p.language === 'hu' ? 'magyarul' : 'angolul'} megy ki, a márkázott ajánlat oldal linkjével. Bejelentkezés nem kell hozzá. Amikor megnyitja, értesítést kapsz.</p>
				<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}><${Icon} name="send" /> Küldés</button></footer>
			</form>
		</${Modal}>`;
}

function ConvertBar({ p, onDone }) {
	const { boot } = useApp();
	const caps = boot.me.caps || {};
	const [busy, setBusy] = useState('');
	const [deposit, setDeposit] = useState('50');
	const run = (what, extra = {}) => {
		setBusy(what);
		api('/docs/proposals/' + p.id + '/convert', { method: 'POST', body: { what, ...extra } })
			.then((r) => {
				onDone(r.proposal);
				if (r.target.type === 'contract') navigate('/contracts/' + r.target.id);
				else if (r.target.type === 'project') navigate('/projects/' + r.target.id);
				else if (r.target.url) window.open(r.target.url, '_blank', 'noopener');
				toast('Elkészült.');
			})
			.catch((e) => toast(e.message, 'error')).finally(() => setBusy(''));
	};
	const a = p.acceptance;
	return html`
		<section class="card accepted-card">
			<div class="accepted-card__head">
				<span class="accepted-icon"><${Icon} name="check" size="22" /></span>
				<div>
					<h2>Elfogadva — ${totalsText(a.totals, p.currency)}</h2>
					<p class="muted">${a.name} (${a.email}) · ${new Date(p.accepted_at * 1000).toLocaleString('hu-HU')} · IP ${a.ip}</p>
				</div>
			</div>
			${busy === 'contract' ? html`<${AiWait} label="A szerződés készül az elfogadott tételekből…" />` : html`
				<div class="convert-grid">
					${caps.contracts ? html`<button class="convert" disabled=${!!busy} onClick=${() => (p.contract_id ? navigate('/contracts/' + p.contract_id) : run('contract', { ai: 1 }))}><${Icon} name="doc" /><strong>${p.contract_id ? 'Szerződés megnyitása' : 'Szerződés AI-val'}</strong><span>a szerződésmintából, az elfogadott tételekkel</span></button>` : null}
					${caps.invoices ? html`<div class="convert">
						<${Icon} name="receipt" /><strong>${p.invoice_id ? 'Számla megnyitása' : 'Előleg / számla'}</strong>
						${p.invoice_id ? html`<a class="link" href=${p.invoice_url} target="_blank" rel="noopener">Számla piszkozat →</a>` : html`
							<span class="inline"><select value=${deposit} onChange=${(e) => setDeposit(e.target.value)}><option value="30">30% előleg</option><option value="50">50% előleg</option><option value="100">teljes összeg</option></select>
							<button class="btn btn--small" disabled=${!!busy} onClick=${() => run('invoice', { deposit })}>Piszkozat</button></span>`}
					</div>` : null}
					${caps.invoices && (a.totals.monthly || a.totals.yearly) ? html`<button class="convert" disabled=${!!busy} onClick=${() => run('subscriptions')}><${Icon} name="tag" /><strong>Havi díjak</strong><span>előfizetésként az ügyfélhez</span></button>` : null}
					<button class="convert" disabled=${!!busy} onClick=${() => (p.project_id ? navigate('/projects/' + p.project_id) : run('project'))}><${Icon} name="folder" /><strong>${p.project_id ? 'Projekt megnyitása' : 'Projekt'}</strong><span>az ütemterv lépéseivel</span></button>
				</div>`}
			<p class="muted small">Tartalom-lenyomat (SHA-256): <code>${a.hash}</code></p>
		</section>`;
}

/* ── Szerkesztő ──────────────────────────────────── */

export function ProposalPage({ id }) {
	const [p, setP] = useState(null);
	const [saved, setSaved] = useState('');
	const [revise, setRevise] = useState('');
	const [busy, setBusy] = useState('');
	const [sending, setSending] = useState(false);
	const timer = useRef(null);
	const pending = useRef({});

	useEffect(() => { api('/docs/proposals/' + id).then(setP).catch((e) => toast(e.message, 'error')); }, [id]);
	useEffect(() => () => clearTimeout(timer.current), []);
	if (!p) return html`<${Spinner} />`;

	const editable = ['draft', 'sent', 'viewed', 'expired'].includes(p.status);
	const flush = () => {
		const body = pending.current;
		pending.current = {};
		if (!Object.keys(body).length) return;
		setSaved('Mentés…');
		api('/docs/proposals/' + id, { method: 'POST', body }).then((x) => { setP((cur) => ({ ...x, ...pending.current })); setSaved('Mentve'); }).catch((e) => { toast(e.message, 'error'); setSaved(''); });
	};
	// Helyben frissít, és 1 mp szünet után ment (a gyors egymás utáni változtatások egy mentés).
	const change = (patch) => {
		setP((cur) => ({ ...cur, ...patch }));
		pending.current = { ...pending.current, ...patch };
		setSaved('…');
		clearTimeout(timer.current);
		timer.current = setTimeout(flush, 900);
	};
	const doRevise = () => {
		setBusy('revise');
		api('/docs/proposals/' + id + '/revise', { method: 'POST', body: { instructions: revise } })
			.then((x) => { setP(x); setRevise(''); toast('Az AI átírta az ajánlatot.'); })
			.catch((e) => toast(e.message, 'error')).finally(() => setBusy(''));
	};
	const copy = () => navigator.clipboard.writeText(p.url).then(() => toast('Link másolva.'));
	const remove = () => {
		if (!window.confirm(p.status === 'draft' ? 'Törlöd a piszkozatot?' : 'Visszavonod az ajánlatot? A link ezután nem fogadható el.')) return;
		api('/docs/proposals/' + id, { method: 'DELETE' }).then((r) => { if (r.deleted) navigate('/proposals'); else setP(r); }).catch((e) => toast(e.message, 'error'));
	};

	return html`
		<div class="page doc-page">
			<a class="crumb" href="#/proposals">Ajánlatok</a>
			<header class="page__head">
				<div class="doc-head">
					<h1>${editable ? html`<${InlineText} value=${p.title} onSave=${(v) => v.trim() && change({ title: v })} className="inline-title" />` : p.title}</h1>
					<p class="muted">
						${p.client ? html`<a class="chip chip--client" href=${'#/proposals?client=' + p.client.id}>${p.client.name}</a>` : null}
						<span class=${'pill pill--prop-' + p.status}>${PROPOSAL_STATUS[p.status]}</span>
						${' '}${p.number}${p.view_count ? ' · ' + p.view_count + '× megnyitotta, utoljára ' + timeAgo(p.last_viewed_at * 1000) : p.sent_at ? ' · még nem nyitotta meg' : ''}${saved ? html` · <span class="saved">${saved}</span>` : ''}
					</p>
				</div>
				<div class="actions">
					<a class="btn btn--ghost" href=${p.url} target="_blank" rel="noopener"><${Icon} name="eye" /> Előnézet</a>
					${p.status !== 'draft' ? html`<button class="btn btn--ghost" onClick=${copy}><${Icon} name="copy" /> Link</button>` : null}
					${['draft', 'sent', 'viewed'].includes(p.status) ? html`<button class="btn" onClick=${() => { flush(); setSending(true); }}><${Icon} name="send" /> ${p.status === 'draft' ? 'Küldés' : 'Újraküldés'}</button>` : null}
				</div>
			</header>

			${p.acceptance ? html`<${ConvertBar} p=${p} onDone=${setP} />` : null}
			${p.declined ? html`<div class="notice-bar"><span><strong>Elutasítva</strong> ${new Date(p.declined.at * 1000).toLocaleDateString('hu-HU')}${p.declined.reason ? ': „' + p.declined.reason + '”' : ''}</span></div>` : null}
			${p.todos && editable ? html`<div class="notice-bar"><span><strong>${p.todos} kitöltetlen rész</strong> ([[TODO]]): pótold, mielőtt kiküldöd.</span></div>` : null}

			<div class="doc-layout">
				<div class="doc-main">
					<section class="card">
						<label class="field"><span>Alcím (a címlapon)</span><input value=${p.tagline} disabled=${!editable} onChange=${(e) => change({ tagline: e.target.value })} placeholder="pl. Több ajánlatkérés Naples-ből, gyorsabb oldallal" /></label>
					</section>
					<h2 class="block-title">Fejezetek</h2>
					${editable ? html`<${SectionsEditor} sections=${p.sections} onChange=${(sections) => change({ sections })} />`
						: p.sections.map((s, i) => html`<section key=${i} class="card section-card"><h3>${String(i + 1).padStart(2, '0')} ${s.title}</h3><div class="doc" dangerouslySetInnerHTML=${{ __html: s.html }}></div></section>`)}
					<h2 class="block-title">Ütemterv</h2>
					<section class="card">${editable ? html`<${TimelineEditor} timeline=${p.timeline} onChange=${(timeline) => change({ timeline })} />` : html`<ol class="bullets">${p.timeline.map((t, i) => html`<li key=${i}><strong>${t.phase}</strong> ${t.duration} — ${t.description}</li>`)}</ol>`}</section>
					<h2 class="block-title">Árak</h2>
					<section class="card">${editable ? html`<${PricingEditor} pricing=${p.pricing} currency=${p.currency} onChange=${(pricing) => change({ pricing })} />`
						: html`<ul class="bullets">${(p.acceptance ? p.acceptance.items : p.pricing).map((i, k) => html`<li key=${k}>${i.name}: ${money(lineCents(i), p.currency)} (${RECURRING[i.recurring]})</li>`)}</ul>`}</section>
				</div>
				<aside class="doc-side">
					${editable ? html`
						<section class="card">
							<h2 class="side-title"><${Icon} name="sparkle" /> Módosítás AI-val</h2>
							${busy === 'revise' ? html`<${AiWait} label="Az AI átírja…" />` : html`
								<textarea class="ai-input" rows="5" value=${revise} onInput=${(e) => setRevise(e.target.value)} placeholder="pl. Legyen rövidebb és közvetlenebb. Tegyél bele egy Google Ads opciót havi $500-ért."></textarea>
								<button class="btn btn--small" onClick=${() => { flush(); doRevise(); }} disabled=${!revise.trim()}>Módosítás</button>`}
						</section>
						<section class="card side-fields">
							<label class="field"><span>Érvényes</span><input type="date" value=${p.valid_until} onChange=${(e) => change({ valid_until: e.target.value })} /></label>
							<label class="field"><span>Nyelv</span><select value=${p.language} onChange=${(e) => change({ language: e.target.value })}><option value="en">Angol</option><option value="hu">Magyar</option></select></label>
							<label class="field"><span>Belső megjegyzés</span><textarea rows="3" value=${p.notes} onChange=${(e) => change({ notes: e.target.value })}></textarea></label>
						</section>` : null}
					<section class="card side-stats">
						<div><span>Kiküldve</span><strong>${p.sent_at ? new Date(p.sent_at * 1000).toLocaleDateString('hu-HU') : '—'}</strong></div>
						<div><span>Első megnyitás</span><strong>${p.first_viewed_at ? timeAgo(p.first_viewed_at * 1000) : '—'}</strong></div>
						<div><span>Megnyitások</span><strong>${p.view_count}</strong></div>
					</section>
					${p.status !== 'accepted' && p.status !== 'void' ? html`<button class="link danger" onClick=${remove}>${p.status === 'draft' ? 'Piszkozat törlése' : 'Ajánlat visszavonása'}</button>` : null}
				</aside>
			</div>
			${sending ? html`<${SendModal} p=${p} onClose=${() => setSending(false)} onSent=${(x) => { setP(x); setSending(false); }} />` : null}
		</div>`;
}
