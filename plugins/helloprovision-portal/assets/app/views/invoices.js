/**
 * Számlák és előfizetések a CRM appban („Számlázás” jog kell).
 * USA: saját sorszám, e-mail az ügyfélnek, QuickBooks, Stripe. Magyarország: Számlázz.hu (sorszám, PDF, NAV), Teya link.
 */
import { html, useState, useEffect, api, useApp, navigate, setParam, CFG, Icon, Modal, Spinner, Empty, toast, money, todayISO } from '../ui.js';

const STATUS = { draft: 'Piszkozat', sent: 'Kiküldve', overdue: 'Lejárt', paid: 'Fizetve', void: 'Érvénytelen' };
const TABS = [['all', 'Összes'], ['draft', 'Piszkozat'], ['open', 'Nyitott'], ['overdue', 'Lejárt'], ['paid', 'Fizetve'], ['void', 'Érvénytelen']];
const BILLING = { monthly: 'havi', quarterly: 'negyedéves', yearly: 'éves', one_time: 'egyszeri' };
const SUB_STATUS = { active: 'Aktív', paused: 'Szünetel', cancelled: 'Lemondva' };
const PROVIDER = { manual: 'Átutalás / kézi', teya: 'Teya', stripe: 'Stripe' };

const huDate = (iso) => {
	if (!iso) return '—';
	const [y, m, d] = iso.slice(0, 10).split('-');
	return `${y}. ${m}. ${d}.`;
};
const pill = (s) => html`<span class=${'pill pill--inv-' + s}>${STATUS[s] || s}</span>`;
const cents = (v) => Math.round(parseFloat(String(v).replace(',', '.')) * 100) || 0;
// Több pénznem („$10.00 · 5 000 Ft”): soronként egy.
const multi = (s) => String(s).split(' · ').map((x, i) => html`<span key=${i} class="kpi-line">${x}</span>`);

/* ── Lista ───────────────────────────────────────── */

export function Invoices({ params }) {
	const { boot } = useApp();
	const [data, setData] = useState(null);
	const [q, setQ] = useState('');
	const [creating, setCreating] = useState(false);
	const status = params.status || 'all';

	useEffect(() => {
		setData(null);
		const qs = new URLSearchParams({ status, ...(params.client ? { client_id: params.client } : {}) });
		api('/billing/invoices?' + qs).then(setData).catch((e) => toast(e.message, 'error'));
	}, [status, params.client]);

	const list = data ? data.invoices.filter((i) => !q || (i.number + ' ' + i.client).toLowerCase().includes(q.toLowerCase())) : [];
	return html`
		<div class="page page--wide">
			<header class="page__head">
				<div><h1>Számlák</h1><p class="muted">USA: saját sorszám, QuickBooks, Stripe. Magyarország: Számlázz.hu, Teya.</p></div>
				<div class="head-actions">
					<a class="btn btn--ghost" href="#/subscriptions"><${Icon} name="clock" /> Előfizetések</a>
					<button class="btn" onClick=${() => setCreating(true)}><${Icon} name="plus" /> Új számla</button>
				</div>
			</header>
			${data ? html`
				<div class="kpis kpis--money">
					<a class="kpi" href="#/invoices?status=open"><span>Kintlévőség</span><strong>${multi(data.kpi.outstanding)}</strong><small>${data.counts.open} nyitott számla</small></a>
					<a class=${'kpi' + (data.counts.overdue ? ' is-alert' : '')} href="#/invoices?status=overdue"><span>Lejárt</span><strong>${multi(data.kpi.overdue)}</strong><small>${data.counts.overdue} számla</small></a>
					<div class="kpi"><span>Befolyt ebben a hónapban</span><strong>${multi(data.kpi.paid_month)}</strong></div>
					<a class="kpi" href="#/subscriptions"><span>Havi ismétlődő bevétel</span><strong>${multi(data.kpi.mrr)}</strong><small>előfizetésekből</small></a>
				</div>` : null}
			<div class="toolbar">
				<div class="seg seg--scroll">${TABS.map(([k, l]) => html`<button key=${k} class=${status === k ? 'is-active' : ''} onClick=${() => setParam('status', k === 'all' ? '' : k)}>${l}${data && data.counts[k] ? html` <em class="seg-count">${data.counts[k]}</em>` : ''}</button>`)}</div>
				<input class="search-input search-input--inline" placeholder="Számlaszám vagy ügyfél…" value=${q} onInput=${(e) => setQ(e.target.value)} />
			</div>
			${!data ? html`<${Spinner} />` : list.length ? html`
				<div class="card table-card">
					<table class="table">
						<thead><tr><th>Számla</th><th>Ügyfél</th><th class="col-opt">Kiállítva</th><th>Határidő</th><th class="right">Összeg</th><th class="right col-opt">Hátralék</th><th>Státusz</th></tr></thead>
						<tbody>${list.map((i) => html`
							<tr key=${i.id} class="row-link" onClick=${() => navigate('/invoices/' + i.id)}>
								<td><a class="link" href=${'#/invoices/' + i.id}><strong>${i.number || 'piszkozat'}</strong></a>${i.sync_status === 'error' ? html` <span class="pill pill--late" title="Szinkron hiba">!</span>` : null}</td>
								<td>${i.client} <span class="muted small">${i.country}</span></td>
								<td class="muted col-opt">${huDate(i.issue_date)}</td>
								<td class=${i.status === 'overdue' ? 'late' : 'muted'}>${huDate(i.due_date)}</td>
								<td class="right">${i.total_label}</td>
								<td class="right col-opt">${i.balance && i.status !== 'draft' && i.status !== 'void' ? money(i.balance, i.currency) : '—'}</td>
								<td>${pill(i.status)}</td>
							</tr>`)}</tbody>
					</table>
				</div>` : html`<${Empty} icon="receipt" title="Nincs ilyen számla" />`}
			${creating ? html`<${NewInvoiceModal} clients=${boot.clients} clientId=${params.client} onClose=${() => setCreating(false)} />` : null}
		</div>`;
}

function NewInvoiceModal({ clients, clientId, onClose }) {
	const [client, setClient] = useState(clientId || '');
	const [busy, setBusy] = useState(false);
	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/billing/invoices', { method: 'POST', body: { client_id: client } })
			.then((inv) => { onClose(); navigate('/invoices/' + inv.id); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};
	return html`
		<${Modal} title="Új számla" onClose=${onClose}>
			<form class="form" onSubmit=${submit}>
				<label class="field"><span>Ügyfél</span>
					<select required value=${client} onChange=${(e) => setClient(e.target.value)}>
						<option value="">Válassz…</option>
						${clients.map((c) => html`<option value=${c.id}>${c.name} (${c.country === 'HU' ? 'Magyarország' : 'USA'})</option>`)}
					</select>
				</label>
				<p class="hint">A pénznem, a számlázó és a fizetés az ügyfél országától függ.</p>
				<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>Tovább</button></footer>
			</form>
		</${Modal}>`;
}

/* ── Egy számla ──────────────────────────────────── */

function ItemsEditor({ items, currency, taxRate, onChange }) {
	const upd = (i, patch) => onChange(items.map((s, k) => (k === i ? { ...s, ...patch } : s)));
	const line = (it) => Math.round((parseFloat(it.quantity) || 0) * cents(it.unit_price));
	const sub = items.reduce((a, it) => a + line(it), 0);
	const tax = Math.round((sub * (taxRate || 0)) / 100);
	return html`
		<div class="grid-rows">
			<div class="grid-row grid-row--inv grid-row--head"><span>Megnevezés</span><span>Menny.</span><span>Egységár (${currency})</span><span class="right">Összeg</span><span></span></div>
			${items.map((it, i) => html`
				<div key=${i + ':' + items.length} class="grid-row grid-row--inv">
					<input value=${it.description} placeholder="Tétel megnevezése" onChange=${(e) => upd(i, { description: e.target.value })} />
					<input type="number" step="0.25" min="0" value=${it.quantity} onChange=${(e) => upd(i, { quantity: e.target.value })} />
					<input type="number" step="0.01" min="0" value=${it.unit_price} onChange=${(e) => upd(i, { unit_price: e.target.value })} />
					<span class="right inv-line">${money(line(it), currency)}</span>
					<button type="button" class="icon-btn" onClick=${() => onChange(items.filter((_, k) => k !== i))} aria-label="Tétel törlése"><${Icon} name="x" /></button>
				</div>`)}
			<button type="button" class="add-row" onClick=${() => onChange([...items, { description: '', quantity: '1', unit_price: '0' }])}><${Icon} name="plus" /> Tétel</button>
			<div class="inv-totals">
				<span>Nettó</span><strong>${money(sub, currency)}</strong>
				${taxRate ? html`<span>ÁFA / adó (${taxRate}%)</span><strong>${money(tax, currency)}</strong>` : null}
				<span>Összesen</span><strong class="inv-total">${money(sub + tax, currency)}</strong>
			</div>
		</div>`;
}

function PaymentModal({ inv, onClose, onDone }) {
	const [f, setF] = useState({ amount: (inv.balance / 100).toFixed(2), paid_on: todayISO(), provider: inv.country === 'HU' ? 'manual' : 'manual', note: '' });
	const [busy, setBusy] = useState(false);
	const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/billing/invoices/' + inv.id + '/payment', { method: 'POST', body: f })
			.then((d) => { toast(d.raw_status === 'paid' ? 'Fizetettnek jelölve.' : 'Részfizetés rögzítve.'); onDone(d); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};
	return html`
		<${Modal} title=${'Befizetés — ' + inv.number} onClose=${onClose}>
			<form class="form" onSubmit=${submit}>
				<div class="row">
					<label class="field"><span>Összeg (${inv.currency})</span><input type="number" step="0.01" min="0.01" required value=${f.amount} onInput=${set('amount')} /></label>
					<label class="field"><span>Dátum</span><input type="date" required value=${f.paid_on} onInput=${set('paid_on')} /></label>
				</div>
				<label class="field"><span>Mód</span><select value=${f.provider} onChange=${set('provider')}>${Object.entries(PROVIDER).map(([k, l]) => html`<option value=${k}>${l}</option>`)}</select></label>
				<label class="field"><span>Megjegyzés (nem kötelező)</span><input value=${f.note} onInput=${set('note')} placeholder="pl. banki közlemény" /></label>
				<p class="hint">${inv.country === 'HU' ? 'A befizetést a Számlázz.hu-ban is rögzítjük.' : inv.integrations.qbo ? 'A befizetés a QuickBooksba is átkerül.' : ''} Hátralék: ${money(inv.balance, inv.currency)}.</p>
				<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>Rögzítés</button></footer>
			</form>
		</${Modal}>`;
}

export function InvoicePage({ id }) {
	const { boot } = useApp();
	const [inv, setInv] = useState(null);
	const [form, setForm] = useState(null);
	const [dirty, setDirty] = useState(false);
	const [busy, setBusy] = useState('');
	const [paying, setPaying] = useState(false);
	const [settings, setSettings] = useState(null);
	const [link, setLink] = useState('');

	const take = (d) => {
		setInv(d);
		setForm({ client_id: d.client_id, issue_date: d.issue_date || '', due_date: d.due_date || '', vat_key: d.vat_key, notes: d.notes, items: d.items.map((i) => ({ description: i.description, quantity: i.quantity, unit_price: String(i.unit_price) })) });
		setLink(d.payment_url);
		setDirty(false);
	};
	useEffect(() => {
		api('/billing/invoices/' + id).then(take).catch((e) => toast(e.message, 'error'));
		api('/billing/invoices?status=draft').then((d) => setSettings(d.settings)).catch(() => {});
	}, [id]);

	if (!inv || !form) return html`<${Spinner} />`;
	const draft = inv.raw_status === 'draft';
	const hu = inv.country === 'HU';
	const edit = (patch) => { setForm({ ...form, ...patch }); setDirty(true); };
	const vatKey = form.vat_key || (settings && settings.hu_vat_key) || '27';
	const taxRate = hu ? (Number.isFinite(parseFloat(vatKey)) ? parseFloat(vatKey) : 0) : inv.tax_rate;

	const run = (key, fn) => { setBusy(key); return fn().then((d) => { if (d) take(d); }).catch((e) => toast(e.message, 'error')).finally(() => setBusy('')); };
	const save = () => run('save', () => api('/billing/invoices/' + inv.id, { method: 'POST', body: form }).then((d) => { toast('Mentve.'); return d; }));
	const send = () => {
		const msg = hu ? 'Kiállítod a számlát a Számlázz.hu-ban? Utána nem módosítható (csak sztornózható), és a Számlázz.hu e-mailben elküldi az ügyfélnek.' : 'Kiküldöd a számlát? Az ügyfél e-mailt kap a portál linkjével' + (inv.integrations.qbo ? ', és átkerül a QuickBooksba.' : '.');
		if (!window.confirm(msg)) return;
		run('send', async () => {
			if (dirty) await api('/billing/invoices/' + inv.id, { method: 'POST', body: form });
			const d = await api('/billing/invoices/' + inv.id + '/send', { method: 'POST' });
			toast(hu ? 'Kiállítva: ' + d.number : 'Kiküldve.');
			return d;
		});
	};
	const voidIt = () => {
		const msg = hu && inv.external_id ? 'Sztornózod? A Számlázz.hu sztornó számlát állít ki.' : 'Érvényteleníted a számlát?' + (inv.integrations.qbo && inv.external_id ? ' A QuickBooksban is érvénytelen lesz.' : '');
		if (window.confirm(msg)) run('void', () => api('/billing/invoices/' + inv.id + '/void', { method: 'POST' }).then((d) => { toast('Érvénytelenítve.'); return d; }));
	};
	const remove = () => {
		if (window.confirm('Törlöd a piszkozatot?')) api('/billing/invoices/' + inv.id, { method: 'DELETE' }).then(() => { toast('Törölve.'); navigate('/invoices'); }).catch((e) => toast(e.message, 'error'));
	};
	const saveLink = () => run('link', () => api('/billing/invoices/' + inv.id + '/link', { method: 'POST', body: { payment_url: link } }).then((d) => { toast('Fizetési link mentve.'); return d; }));

	return html`
		<div class="page page--wide inv-page">
			<header class="page__head">
				<div>
					<a class="crumb" href="#/invoices">Számlák</a>
					<h1>${inv.number || 'Új számla (piszkozat)'} ${pill(inv.status)}</h1>
					<p class="muted">${inv.client} · ${hu ? 'Magyarország · Számlázz.hu' : 'USA' + (inv.integrations.qbo ? ' · QuickBooks' : '')} · ${inv.currency}</p>
				</div>
				<div class="head-actions">
					${inv.pdf_url ? html`<a class="btn btn--ghost" href=${inv.pdf_url} target="_blank" rel="noopener"><${Icon} name="doc" /> PDF</a>` : null}
					<a class="btn btn--ghost" href=${inv.portal_url} target="_blank" rel="noopener"><${Icon} name="eye" /> Portál</a>
					${draft ? html`<button class="btn btn--ghost" disabled=${!!busy || !dirty} onClick=${save}>Mentés</button>` : null}
					${draft ? html`<button class="btn" disabled=${!!busy || !form.items.length} onClick=${send}><${Icon} name="send" /> ${hu ? 'Kiállítás' : 'Kiküldés'}</button>` : null}
					${inv.raw_status === 'sent' ? html`<button class="btn" onClick=${() => setPaying(true)}>Befizetés rögzítése</button>` : null}
				</div>
			</header>

			${inv.sync_status === 'error' ? html`
				<div class="alert alert--error">
					<strong>Szinkron hiba:</strong> ${inv.sync_error}
					<button class="btn btn--small" disabled=${!!busy} onClick=${() => run('sync', () => api('/billing/invoices/' + inv.id + '/sync', { method: 'POST' }))}>Újrapróbálás</button>
				</div>` : null}

			<div class="inv-grid">
				<section class="card">
					${draft ? html`
						<div class="row row--3">
							<label class="field"><span>Ügyfél</span>
								<select value=${form.client_id} onChange=${(e) => edit({ client_id: Number(e.target.value) })} disabled=${!!inv.number}>
									${boot.clients.filter((c) => (c.country === 'HU') === hu).map((c) => html`<option value=${c.id}>${c.name}</option>`)}
								</select>
							</label>
							<label class="field"><span>Kiállítás</span><input type="date" value=${form.issue_date} onInput=${(e) => edit({ issue_date: e.target.value })} /></label>
							<label class="field"><span>Fizetési határidő</span><input type="date" value=${form.due_date} onInput=${(e) => edit({ due_date: e.target.value })} /></label>
						</div>
						${hu && settings ? html`
							<label class="field field--inline"><span>ÁFA</span>
								<select value=${form.vat_key} onChange=${(e) => edit({ vat_key: e.target.value })}>
									${settings.vat_keys.map((v) => html`<option value=${v.key}>${v.key ? v.label : 'Alapérték (' + settings.hu_vat_key + ')'}</option>`)}
								</select>
							</label>` : null}
						<${ItemsEditor} items=${form.items} currency=${inv.currency} taxRate=${taxRate} onChange=${(items) => edit({ items })} />
						<label class="field"><span>Megjegyzés a számlán</span><textarea rows="2" value=${form.notes} onInput=${(e) => edit({ notes: e.target.value })}></textarea></label>
						<footer class="inv-foot">
							${!inv.external_id ? html`<button class="link danger" onClick=${remove}>Piszkozat törlése</button>` : null}
							${dirty ? html`<span class="muted">Nem mentett változás</span>` : null}
						</footer>` : html`
						<dl class="inv-meta">
							<dt>Kiállítva</dt><dd>${huDate(inv.issue_date)}</dd>
							<dt>Határidő</dt><dd class=${inv.status === 'overdue' ? 'late' : ''}>${huDate(inv.due_date)}</dd>
							${inv.sent_at ? html`<dt>Kiküldve</dt><dd>${huDate(inv.sent_at)}</dd>` : null}
							${inv.paid_at ? html`<dt>Fizetve</dt><dd>${huDate(inv.paid_at)}</dd>` : null}
						</dl>
						<table class="table inv-items">
							<thead><tr><th>Megnevezés</th><th class="right">Menny.</th><th class="right">Egységár</th><th class="right">Összeg</th></tr></thead>
							<tbody>${inv.items.map((i, k) => html`<tr key=${k}><td>${i.description}</td><td class="right">${i.quantity}</td><td class="right">${money(Math.round(i.unit_price * 100), inv.currency)}</td><td class="right">${money(i.amount, inv.currency)}</td></tr>`)}</tbody>
						</table>
						<div class="inv-totals">
							<span>Nettó</span><strong>${money(inv.subtotal, inv.currency)}</strong>
							${inv.tax ? html`<span>ÁFA / adó</span><strong>${money(inv.tax, inv.currency)}</strong>` : null}
							<span>Összesen</span><strong class="inv-total">${money(inv.total, inv.currency)}</strong>
							${inv.paid && inv.raw_status !== 'paid' ? html`<span>Befizetve</span><strong>−${money(inv.paid, inv.currency)}</strong><span>Hátralék</span><strong class="inv-total">${money(inv.balance, inv.currency)}</strong>` : null}
						</div>
						${inv.notes ? html`<p class="muted inv-notes">${inv.notes}</p>` : null}`}
				</section>

				<aside class="inv-side">
					${inv.payments.length ? html`
						<section class="card">
							<h3>Befizetések</h3>
							<ul class="inv-payments">${inv.payments.map((p) => html`<li key=${p.id}><strong>${money(p.amount, inv.currency)}</strong><span class="muted">${huDate(p.paid_on)} · ${PROVIDER[p.provider] || p.provider}${p.booked ? ' · könyvelve' : ''}</span>${p.note ? html`<small class="muted">${p.note}</small>` : null}</li>`)}</ul>
						</section>` : null}
					${hu && inv.raw_status === 'sent' ? html`
						<section class="card">
							<h3>Online fizetés (Teya)</h3>
							<p class="hint">Amíg a Teya bekötés nincs kész: hozz létre fizetési linket a Teya felületén, és másold ide. Az ügyfél a portálon a „Fizetés most” gombbal éri el.</p>
							<div class="inline-form"><input type="url" placeholder="https://…" value=${link} onInput=${(e) => setLink(e.target.value)} /><button class="btn btn--small" disabled=${!!busy || link === inv.payment_url} onClick=${saveLink}>Mentés</button></div>
						</section>` : null}
					${!hu && inv.raw_status === 'sent' ? html`<section class="card"><h3>Online fizetés</h3><p class="hint">${inv.integrations.stripe ? 'Az ügyfél a portálon bankkártyával fizethet (Stripe); a befizetés magától rögzül.' : 'A Stripe nincs bekötve: az ügyfél átutalással fizet, a befizetést itt rögzítsd.'}</p></section>` : null}
					${['sent', 'overdue', 'paid'].includes(inv.status) || (draft && inv.external_id) ? html`
						<section class="card">
							<h3>Érvénytelenítés</h3>
							<p class="hint">${hu ? 'Kiállított magyar számla nem módosítható: sztornó számla készül, utána új számlát állíthatsz ki.' : 'Érvénytelen lesz itt' + (inv.integrations.qbo ? ' és a QuickBooksban' : '') + '. Új számlát készíthetsz helyette.'}</p>
							<button class="btn btn--ghost btn--danger" disabled=${!!busy} onClick=${voidIt}>${hu ? 'Sztornó' : 'Érvénytelenítés'}</button>
						</section>` : null}
					<p class="hint"><a class="link" href=${inv.admin_url}>Megnyitás a klasszikus CRM-ben</a></p>
				</aside>
			</div>
			${paying ? html`<${PaymentModal} inv=${inv} onClose=${() => setPaying(false)} onDone=${(d) => { setPaying(false); take(d); }} />` : null}
		</div>`;
}

/* ── Előfizetések ────────────────────────────────── */

function NewSubscriptionModal({ clients, services, onClose, onSaved }) {
	const [f, setF] = useState({ client_id: '', service_id: '', name: '', price: '', billing: 'monthly', start_date: todayISO(), description: '' });
	const [busy, setBusy] = useState(false);
	const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
	const pickService = (e) => {
		const s = services.find((x) => String(x.id) === e.target.value);
		setF({ ...f, service_id: e.target.value, ...(s ? { name: s.name, price: String(s.price), billing: s.billing, description: s.description } : {}) });
	};
	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/billing/subscriptions', { method: 'POST', body: { ...f, next_invoice_date: f.start_date } })
			.then(() => { toast('Előfizetés felvéve.'); onSaved(); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};
	const client = clients.find((c) => String(c.id) === String(f.client_id));
	return html`
		<${Modal} title="Új előfizetés" onClose=${onClose}>
			<form class="form" onSubmit=${submit}>
				<label class="field"><span>Ügyfél</span><select required value=${f.client_id} onChange=${set('client_id')}><option value="">Válassz…</option>${clients.map((c) => html`<option value=${c.id}>${c.name}</option>`)}</select></label>
				${services.length ? html`<label class="field"><span>Szolgáltatás a katalógusból (nem kötelező)</span><select value=${f.service_id} onChange=${pickService}><option value="">Egyedi…</option>${services.map((s) => html`<option value=${s.id}>${s.name}</option>`)}</select></label>` : null}
				<label class="field"><span>Megnevezés (az ügyfél nyelvén, a számlán ez szerepel)</span><input required value=${f.name} onInput=${set('name')} /></label>
				<div class="row row--3">
					<label class="field"><span>Ár (${client && client.country === 'HU' ? 'HUF' : 'USD'}, nettó)</span><input type="number" step="0.01" min="0" required value=${f.price} onInput=${set('price')} /></label>
					<label class="field"><span>Számlázás</span><select value=${f.billing} onChange=${set('billing')}>${Object.entries(BILLING).map(([k, l]) => html`<option value=${k}>${l}</option>`)}</select></label>
					<label class="field"><span>Első számla</span><input type="date" required value=${f.start_date} onInput=${set('start_date')} /></label>
				</div>
				<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>Felvétel</button></footer>
			</form>
		</${Modal}>`;
}

export function Subscriptions() {
	const { boot } = useApp();
	const [data, setData] = useState(null);
	const [preview, setPreview] = useState(null);
	const [adding, setAdding] = useState(false);
	const [running, setRunning] = useState(false);

	const load = () => {
		api('/billing/subscriptions').then(setData).catch((e) => toast(e.message, 'error'));
		api('/billing/recurring').then(setPreview).catch(() => {});
	};
	useEffect(load, []);

	const update = (s, patch) => api('/billing/subscriptions/' + s.id, { method: 'POST', body: patch })
		.then((ns) => setData((d) => ({ ...d, subscriptions: d.subscriptions.map((x) => (x.id === ns.id ? ns : x)) })))
		.catch((e) => toast(e.message, 'error'));
	const runNow = () => {
		if (!window.confirm('Elkészítjük a ma esedékes számlákat' + (preview && preview.mode === 'send' ? ' és ki is küldjük őket?' : ' (piszkozatként)?'))) return;
		setRunning(true);
		api('/billing/recurring', { method: 'POST' })
			.then((r) => { toast(r.created.length ? r.created.length + ' számla készült.' : 'Ma nincs esedékes számla.'); load(); })
			.catch((e) => toast(e.message, 'error'))
			.finally(() => setRunning(false));
	};

	if (!data) return html`<${Spinner} />`;
	const mode = { draft: 'piszkozat készül, ti külditek ki', send: 'automatikusan kiküldjük', off: 'kikapcsolva' }[data.recurring.mode];
	return html`
		<div class="page page--wide">
			<header class="page__head">
				<div>
					<a class="crumb" href="#/invoices">Számlák</a>
					<h1>Előfizetések</h1>
					<p class="muted">Ismétlődő számlák: a „Következő számla” napján reggel ${mode}.${data.recurring.last_run ? ' Utolsó futás: ' + huDate(data.recurring.last_run.at) + ', ' + data.recurring.last_run.count + ' számla.' : ''}</p>
				</div>
				<div class="head-actions">
					${data.recurring.mode !== 'off' ? html`<button class="btn btn--ghost" disabled=${running} onClick=${runNow}>Esedékesek elkészítése most</button>` : null}
					<button class="btn" onClick=${() => setAdding(true)}><${Icon} name="plus" /> Új előfizetés</button>
				</div>
			</header>
			${preview && preview.upcoming.length ? html`
				<section class="card upcoming">
					<h3>Következő 30 nap</h3>
					<ul>${preview.upcoming.map((u, i) => html`<li key=${i}><span class="muted">${huDate(u.date)}</span> <strong>${u.client}</strong> · ${u.name}${u.period ? ' — ' + u.period : ''} <span class="right">${u.amount}</span></li>`)}</ul>
				</section>` : null}
			${data.subscriptions.length ? html`
				<div class="card table-card">
					<table class="table">
						<thead><tr><th>Ügyfél</th><th>Szolgáltatás</th><th class="right">Ár</th><th>Számlázás</th><th>Következő számla</th><th>Státusz</th></tr></thead>
						<tbody>${data.subscriptions.map((s) => html`
							<tr key=${s.id} class=${s.status !== 'active' ? 'is-muted' : ''}>
								<td>${s.client}</td>
								<td><strong>${s.name}</strong></td>
								<td class="right">${s.price_label}</td>
								<td>${BILLING[s.billing] || s.billing}</td>
								<td><input class="cell-date" type="date" value=${s.next_invoice_date || ''} onChange=${(e) => update(s, { next_invoice_date: e.target.value })} /></td>
								<td><select class="cell-select" value=${s.status} onChange=${(e) => update(s, { status: e.target.value })}>${Object.entries(SUB_STATUS).map(([k, l]) => html`<option value=${k}>${l}</option>`)}</select></td>
							</tr>`)}</tbody>
					</table>
				</div>` : html`<${Empty} icon="clock" title="Még nincs előfizetés">Havi karbantartás, SEO, tárhely: vedd fel itt, és a számla magától elkészül.</${Empty}>`}
			${adding ? html`<${NewSubscriptionModal} clients=${boot.clients} services=${data.services} onClose=${() => setAdding(false)} onSaved=${() => { setAdding(false); load(); }} />` : null}
		</div>`;
}
