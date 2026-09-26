/**
 * Ügyfél-adatlap a CRM appban: adatok, portál-hozzáférés, jegyzetek és idővonal, kapcsolódó munka és pénzügy.
 * Szolgáltatás-katalógus (számlázási joggal).
 */
import { html, useState, useEffect, api, useApp, navigate, Icon, Modal, Spinner, Empty, toast, timeAgo, money } from '../ui.js';
import { SalesPanel } from './sales.js';

const GROUPS = { main: 'Alapadatok', billing: 'Számlázás', sources: 'Riport adatforrások' };
const STATUS = { lead: 'Érdeklődő', active: 'Aktív', paused: 'Szünetel', former: 'Korábbi' };
const INV = { draft: 'Piszkozat', sent: 'Kiküldve', overdue: 'Lejárt', paid: 'Fizetve', void: 'Érvénytelen' };
const BILLING = { monthly: 'havi', quarterly: 'negyedéves', yearly: 'éves', one_time: 'egyszeri' };

export function NewClientModal({ onClose }) {
	const [f, setF] = useState({ name: '', country: 'US', email: '', contact_name: '', status: 'active' });
	const [busy, setBusy] = useState(false);
	const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/clients', { method: 'POST', body: f })
			.then((c) => { window.location.hash = '#/clients/' + c.id; window.location.reload(); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};
	return html`
		<${Modal} title="Új ügyfél" onClose=${onClose}>
			<form class="form" onSubmit=${submit}>
				<label class="field"><span>Cégnév</span><input required value=${f.name} onInput=${set('name')} autoFocus /></label>
				<div class="row">
					<label class="field"><span>Ország</span><select value=${f.country} onChange=${set('country')}><option value="US">USA</option><option value="HU">Magyarország</option></select></label>
					<label class="field"><span>Státusz</span><select value=${f.status} onChange=${set('status')}>${Object.entries(STATUS).map(([k, l]) => html`<option value=${k}>${l}</option>`)}</select></label>
				</div>
				<div class="row">
					<label class="field"><span>Kapcsolattartó</span><input value=${f.contact_name} onInput=${set('contact_name')} /></label>
					<label class="field"><span>E-mail</span><input type="email" value=${f.email} onInput=${set('email')} /></label>
				</div>
				<p class="hint">Az ország dönti el a pénznemet, a számlázót és a portál nyelvét.</p>
				<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>Létrehozás</button></footer>
			</form>
		</${Modal}>`;
}

function Field({ meta, value, onChange }) {
	const common = { value: value ?? '', onInput: (e) => onChange(e.target.value) };
	let input;
	if (meta.options) input = html`<select value=${value} onChange=${(e) => onChange(e.target.value)}>${meta.options.map((o) => html`<option value=${o.key}>${o.label}</option>`)}</select>`;
	else if (meta.type === 'textarea') input = html`<textarea rows="3" ...${common}></textarea>`;
	else input = html`<input type=${meta.type === 'email' ? 'email' : meta.type === 'url' ? 'url' : meta.type === 'money' ? 'number' : 'text'} step=${meta.type === 'money' ? '0.01' : undefined} required=${meta.required} ...${common} />`;
	return html`<label class=${'field' + (meta.type === 'textarea' ? ' field--wide' : '')}><span>${meta.label}</span>${input}${meta.help ? html`<small class="muted">${meta.help}</small>` : null}</label>`;
}

export function ClientPage({ id }) {
	const { boot } = useApp();
	const caps = boot.me.caps || {};
	const [c, setC] = useState(null);
	const [f, setF] = useState(null);
	const [dirty, setDirty] = useState(false);
	const [note, setNote] = useState('');
	const [invite, setInvite] = useState({ name: '', email: '' });
	const [busy, setBusy] = useState(false);

	const take = (d) => { setC((old) => ({ ...(old || {}), ...d })); const v = {}; (d.fields || (c && c.fields) || []).forEach((m) => { v[m.key] = d[m.key]; }); setF(v); setDirty(false); };
	useEffect(() => { api('/clients/' + id).then(take).catch((e) => toast(e.message, 'error')); }, [id]);
	if (!c || !f) return html`<${Spinner} />`;

	const run = (p, msg) => { setBusy(true); return p.then((d) => { take(d); if (msg) toast(msg); }).catch((e) => toast(e.message, 'error')).finally(() => setBusy(false)); };
	const save = () => run(api('/clients/' + id, { method: 'POST', body: f }), 'Mentve.');
	const addNote = () => run(api('/clients/' + id + '/notes', { method: 'POST', body: { body: note } }), 'Jegyzet mentve.').then(() => setNote(''));
	const sendInvite = (e) => { e.preventDefault(); run(api('/clients/' + id + '/invite', { method: 'POST', body: invite }), 'Meghívó elküldve.').then(() => setInvite({ name: '', email: '' })); };
	const revoke = (u) => window.confirm(`Visszavonod ${u.name} portál-hozzáférését?`) && run(api('/clients/' + id + '/users/' + u.id, { method: 'DELETE' }), 'Visszavonva.');
	const review = () => window.confirm('Küldjünk Google értékeléskérést az ügyfélnek? (90 napon belül egyszer)') && api('/clients/' + id + '/review-request', { method: 'POST', body: {} }).then((r) => toast(r.status === 'sent' ? 'Értékeléskérés elküldve.' : 'Nem ment ki: ' + r.status)).catch((e) => toast(e.message, 'error'));
	const groups = Object.keys(GROUPS).map((g) => [g, c.fields.filter((m) => m.group === g)]).filter(([, l]) => l.length);

	return html`
		<div class="page page--wide">
			<header class="page__head">
				<div>
					<a class="crumb" href="#/clients">Ügyfelek</a>
					<h1 class="content-title">${c.name} <span class=${'pill pill--' + c.status}>${STATUS[c.status] || c.status}</span></h1>
					<p class="muted">${c.country === 'HU' ? 'Magyarország · HUF · magyar portál' : 'USA · USD · angol portál'}${c.contact_name ? ' · ' + c.contact_name : ''}</p>
				</div>
				<div class="head-actions">
					<a class="btn btn--ghost" href=${'#/chat?client=' + id}><${Icon} name="chat" /> Chat</a>
					<a class="btn btn--ghost" href=${c.portal_url} target="_blank" rel="noopener"><${Icon} name="eye" /> Portál</a>
					<button class="btn" disabled=${busy || !dirty} onClick=${save}>Mentés</button>
				</div>
			</header>
			<div class="client-grid">
				<div class="client-main">
					${groups.map(([g, list]) => html`
						<section class="card client-form" key=${g}>
							<h3>${GROUPS[g]}</h3>
							<div class="client-fields">${list.map((m) => html`<${Field} key=${m.key} meta=${m} value=${f[m.key]} onChange=${(v) => { setF({ ...f, [m.key]: v }); setDirty(true); }} />`)}</div>
						</section>`)}
					<section class="card">
						<h3>Jegyzetek és idővonal</h3>
						<div class="inline-form"><input value=${note} onInput=${(e) => setNote(e.target.value)} placeholder="Belső jegyzet (az ügyfél nem látja)…" onKeyDown=${(e) => e.key === 'Enter' && note.trim() && addNote()} /><button class="btn btn--small" disabled=${!note.trim() || busy} onClick=${addNote}>Mentés</button></div>
						<ol class="timeline-list">${c.activity.map((a) => html`
							<li key=${a.id} class=${'tl--' + a.type}>
								<span class="muted small">${timeAgo(a.at * 1000)}${a.author ? ' · ' + a.author : ''}${a.visible ? ' · az ügyfél is látja' : ''}</span>
								<p>${a.body}</p>
							</li>`)}</ol>
					</section>
				</div>
				<aside class="client-side">
					${c.sales ? html`<${SalesPanel} client=${c} onChange=${() => api('/clients/' + id).then(take)} />` : null}
					<section class="card">
						<h3>Portál-hozzáférés</h3>
						${c.users.length ? html`<ul class="people">${c.users.map((u) => html`<li key=${u.id}><span><strong>${u.name}</strong><small class="muted">${u.email}${u.last_login ? ' · belépett ' + timeAgo(u.last_login * 1000) : ' · még nem lépett be'}</small></span><button class="icon-btn" title="Visszavonás" aria-label="Visszavonás" onClick=${() => revoke(u)}><${Icon} name="x" size="15" /></button></li>`)}</ul>` : html`<p class="hint">Még nincs portál-felhasználó.</p>`}
						<form class="invite-form" onSubmit=${sendInvite}>
							<input required placeholder="Név" value=${invite.name} onInput=${(e) => setInvite({ ...invite, name: e.target.value })} />
							<input required type="email" placeholder="E-mail" value=${invite.email} onInput=${(e) => setInvite({ ...invite, email: e.target.value })} />
							<button class="btn btn--small" disabled=${busy}>Meghívás</button>
						</form>
						<p class="hint">A meghívott e-mailt kap a jelszó beállításához, az ügyfél nyelvén.</p>
					</section>
					${caps.invoices ? html`
						<section class="card">
							<h3>Pénzügy</h3>
							<dl class="kv"><dt>Kintlévőség</dt><dd>${c.outstanding}</dd><dt>Havi díjak</dt><dd>${c.mrr}</dd></dl>
							${c.invoices.length ? html`<ul class="mini-list">${c.invoices.map((i) => html`<li key=${i.id}><a class="link" href=${'#/invoices/' + i.id}>${i.number || 'piszkozat'}</a> <span class="muted">${i.total_label}</span> <span class=${'pill pill--inv-' + i.status}>${INV[i.status] || i.status}</span></li>`)}</ul>` : null}
							${c.subscriptions.length ? html`<ul class="mini-list">${c.subscriptions.map((s) => html`<li key=${s.id}>${s.name} <span class="muted">${s.price_label} ${BILLING[s.billing] || ''}${s.status !== 'active' ? ' · ' + s.status : ''}</span></li>`)}</ul>` : null}
							<p><a class="link" href=${'#/invoices?client=' + id}>Összes számla</a> · <a class="link" href="#/subscriptions">Előfizetések</a></p>
						</section>` : null}
					<section class="card">
						<h3>Munka</h3>
						${c.projects.length ? html`<ul class="mini-list">${c.projects.map((p) => html`<li key=${p.id}><a class="link" href=${'#/projects/' + p.id}>${p.name}</a> <span class="muted">${p.progress}%</span></li>`)}</ul>` : html`<p class="hint">Nincs projekt.</p>`}
						<p class="links-row">
							<a class="link" href=${'#/files?client=' + id}>Fájlok (${c.counts.files})</a>
							<a class="link" href=${'#/content?client=' + id + '&view=list'}>Tartalom${c.counts.approvals ? ' (' + c.counts.approvals + ' vár)' : ''}</a>
							<a class="link" href="#/reports">Riportok (${c.counts.reports})</a>
							<a class="link" href=${'#/calls?client=' + id}>Hívások</a>
						</p>
						${c.contracts && c.contracts.length ? html`<ul class="mini-list">${c.contracts.map((k) => html`<li key=${k.id}><a class="link" href=${'#/contracts/' + k.id}>${k.title}</a> <span class="muted">${k.status}</span></li>`)}</ul>` : null}
						${c.country === 'US' ? html`<p><button class="link" onClick=${review}>Google értékelés kérése</button></p>` : null}
					</section>
				</aside>
			</div>
		</div>`;
}

export function Services() {
	const [list, setList] = useState(null);
	const [edit, setEdit] = useState(null);
	const load = () => api('/billing/services').then(setList).catch((e) => toast(e.message, 'error'));
	useEffect(load, []);
	const save = (e) => {
		e.preventDefault();
		api('/billing/services' + (edit.id ? '/' + edit.id : ''), { method: 'POST', body: { ...edit, active: edit.active ? 1 : 0 } }).then(() => { toast('Mentve.'); setEdit(null); load(); }).catch((err) => toast(err.message, 'error'));
	};
	if (!list) return html`<${Spinner} />`;
	return html`
		<div class="page">
			<header class="page__head">
				<div><h1>Szolgáltatás-katalógus</h1><p class="muted">Ebből választasz új előfizetéshez; az ajánlat-AI is ismeri. Az ár USD-ben értendő, magyar ügyfélnél az előfizetésen írd át.</p></div>
				<button class="btn" onClick=${() => setEdit({ name: '', description: '', price: '', billing: 'monthly', active: true })}><${Icon} name="plus" /> Új szolgáltatás</button>
			</header>
			${list.length ? html`
				<div class="card table-card"><table class="table">
					<thead><tr><th>Szolgáltatás</th><th class="right">Ár</th><th>Számlázás</th><th class="right">Aktív előfizetés</th><th>Választható</th></tr></thead>
					<tbody>${list.map((s) => html`<tr key=${s.id} class=${'row-link' + (s.active ? '' : ' is-muted')} onClick=${() => setEdit(s)}><td><strong>${s.name}</strong>${s.description ? html`<br /><small class="muted">${s.description}</small>` : null}</td><td class="right">${money(Math.round(s.price * 100), 'USD')}</td><td>${BILLING[s.billing]}</td><td class="right">${s.in_use || '—'}</td><td>${s.active ? 'igen' : 'nem'}</td></tr>`)}</tbody>
				</table></div>` : html`<${Empty} icon="tag" title="Még üres a katalógus" />`}
			${edit ? html`
				<${Modal} title=${edit.id ? 'Szolgáltatás' : 'Új szolgáltatás'} onClose=${() => setEdit(null)}>
					<form class="form" onSubmit=${save}>
						<label class="field"><span>Név (az ügyfél is látja)</span><input required value=${edit.name} onInput=${(e) => setEdit({ ...edit, name: e.target.value })} /></label>
						<label class="field"><span>Mit tartalmaz</span><textarea rows="3" value=${edit.description} onInput=${(e) => setEdit({ ...edit, description: e.target.value })}></textarea></label>
						<div class="row">
							<label class="field"><span>Alapár (USD)</span><input type="number" step="0.01" min="0" value=${edit.price} onInput=${(e) => setEdit({ ...edit, price: e.target.value })} /></label>
							<label class="field"><span>Számlázás</span><select value=${edit.billing} onChange=${(e) => setEdit({ ...edit, billing: e.target.value })}>${Object.entries(BILLING).map(([k, l]) => html`<option value=${k}>${l}</option>`)}</select></label>
						</div>
						<label class="toggle"><input type="checkbox" checked=${edit.active} onChange=${(e) => setEdit({ ...edit, active: e.target.checked })} /> <span>Választható új előfizetéshez</span></label>
						<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${() => setEdit(null)}>Mégse</button><button class="btn">Mentés</button></footer>
					</form>
				</${Modal}>` : null}
		</div>`;
}
