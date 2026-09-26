/**
 * Havi riportok: lista, összerakás (Google, Meta, feladatok, AI), szerkesztés, kiküldés az ügyfélnek.
 * Ügyfelenkénti adatforrások: DataSourcesModal (az Ügyfelek oldalról).
 */
import { html, useState, useEffect, api, useApp, navigate, setParam, Icon, Modal, Spinner, Empty, toast, MONTHS_LONG, todayISO } from '../ui.js';
import { RichText } from './richtext.js';

const monthLabel = (m) => { const [y, mm] = String(m).split('-'); return y + '. ' + MONTHS_LONG[Number(mm) - 1]; };
const prevMonth = () => { const d = new Date(todayISO() + 'T00:00:00Z'); d.setUTCDate(1); d.setUTCMonth(d.getUTCMonth() - 1); return d.toISOString().slice(0, 7); };
const SOURCE = { 'Google Search': 'Search Console', Website: 'GA4', 'Google Ads': 'Google Ads', 'Meta Ads': 'Meta' };
const FORMATS = { int: 'darab', pct: '%', money: 'pénz', decimal: 'tizedes', position: 'helyezés' };

export function DataSourcesModal({ client, onClose }) {
	const [f, setF] = useState(null);
	const [acc, setAcc] = useState(null);
	const [status, setStatus] = useState(null);
	useEffect(() => {
		api('/connectors/client/' + client.id).then(setF).catch((e) => toast(e.message, 'error'));
		api('/connectors').then(setStatus).catch(() => {});
		api('/connectors/accounts').then(setAcc).catch(() => setAcc({ gsc: [], ga4: [], gads: [], meta: [], errors: [] }));
	}, []);
	const save = (e) => {
		e.preventDefault();
		api('/connectors/client/' + client.id, { method: 'POST', body: f }).then(() => { toast('Mentve.'); onClose(); }).catch((err) => toast(err.message, 'error'));
	};
	const field = (key, label, list, placeholder, ok) => html`
		<label class="field"><span>${label}${!ok ? html` <em class="muted small">(nincs bekötve)</em>` : ''}</span>
			${list && list.length ? html`<select value=${f[key]} onChange=${(e) => setF({ ...f, [key]: e.target.value })}><option value="">— nincs —</option>${list.map((x) => html`<option value=${x.id}>${x.label}</option>`)}${f[key] && !list.find((x) => x.id === f[key]) ? html`<option value=${f[key]}>${f[key]}</option>` : null}</select>`
				: html`<input value=${f[key]} placeholder=${placeholder} onInput=${(e) => setF({ ...f, [key]: e.target.value })} />`}
		</label>`;
	return html`
		<${Modal} title=${'Riport adatok — ' + client.name} onClose=${onClose}>
			${!f || !acc || !status ? html`<${Spinner} />` : html`
				<form class="form" onSubmit=${save}>
					<p class="hint">Honnan jöjjenek a havi riport számai. Ami üres, az nem kerül a riportba.</p>
					${field('gsc_property', 'Google Search Console', acc.gsc, 'sc-domain:pelda.hu', status.google.connected)}
					${field('ga4_property', 'Google Analytics 4 tulajdon', acc.ga4, '412345678', status.google.connected)}
					${field('gads_customer', 'Google Ads fiók', acc.gads, '123-456-7890', status.gads)}
					${field('meta_ad_account', 'Meta hirdetési fiók', acc.meta, 'act_1234567890', status.meta)}
					${acc.errors.length ? html`<p class="hint danger">${acc.errors.join(' · ')}</p>` : null}
					${!status.google.connected ? html`<p class="hint">A Google összekapcsolása: CRM → Beállítások → Riport adatforrások (adminisztrátor).</p>` : null}
					<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn">Mentés</button></footer>
				</form>`}
		</${Modal}>`;
}

function NewReportModal({ clients, onClose }) {
	const [f, setF] = useState({ client_id: '', period: prevMonth(), ai: true });
	const [busy, setBusy] = useState(false);
	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/reports', { method: 'POST', body: f }).then((r) => { onClose(); navigate('/reports/' + r.id); }).catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};
	return html`
		<${Modal} title="Havi riport készítése" onClose=${onClose}>
			<form class="form" onSubmit=${submit}>
				<div class="row">
					<label class="field"><span>Ügyfél</span><select required value=${f.client_id} onChange=${(e) => setF({ ...f, client_id: e.target.value })}><option value="">Válassz…</option>${clients.map((c) => html`<option value=${c.id}>${c.name}</option>`)}</select></label>
					<label class="field"><span>Hónap</span><input type="month" required value=${f.period} onInput=${(e) => setF({ ...f, period: e.target.value })} /></label>
				</div>
				<label class="toggle"><input type="checkbox" checked=${f.ai} onChange=${(e) => setF({ ...f, ai: e.target.checked })} /> <span>Összefoglaló AI-val (az ügyfél nyelvén, csak a valós számokból)</span></label>
				<p class="hint">A számok a bekötött forrásokból jönnek, az elvégzett munka a hónapban lezárt, ügyfél által látható feladatokból. ${busy ? 'Összerakás… (fél perc is lehet)' : ''}</p>
				<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>${busy ? 'Készül…' : 'Összerakás'}</button></footer>
			</form>
		</${Modal}>`;
}

export function Reports({ params }) {
	const { boot } = useApp();
	const [data, setData] = useState(null);
	const [creating, setCreating] = useState(false);
	const status = params.status || '';
	useEffect(() => { setData(null); api('/reports' + (status ? '?status=' + status : '')).then(setData).catch((e) => toast(e.message, 'error')); }, [status]);
	return html`
		<div class="page">
			<header class="page__head">
				<div><h1>Havi riportok</h1><p class="muted">Search Console, Analytics, Google Ads és Meta számok, az elvégzett munka és a következő hónap, az ügyfél nyelvén.</p></div>
				<button class="btn" onClick=${() => setCreating(true)}><${Icon} name="plus" /> Riport készítése</button>
			</header>
			<div class="toolbar"><div class="seg">${[['', 'Mind'], ['draft', 'Piszkozat'], ['sent', 'Kiküldve']].map(([k, l]) => html`<button key=${k} class=${status === k ? 'is-active' : ''} onClick=${() => setParam('status', k)}>${l}</button>`)}</div></div>
			${!data ? html`<${Spinner} />` : data.reports.length ? html`
				<div class="card table-card">
					<table class="table">
						<thead><tr><th>Ügyfél</th><th>Hónap</th><th>Adatforrások</th><th>Státusz</th></tr></thead>
						<tbody>${data.reports.map((r) => html`
							<tr key=${r.id} class="row-link" onClick=${() => navigate('/reports/' + r.id)}>
								<td><a class="link" href=${'#/reports/' + r.id}><strong>${r.client}</strong></a></td>
								<td>${monthLabel(r.period)}</td>
								<td>${r.sources.length ? r.sources.map((s) => html`<span class="chip chip--src">${SOURCE[s] || s}</span> `) : html`<span class="muted">kézi</span>`}</td>
								<td><span class=${'pill pill--rep-' + r.status}>${r.status === 'sent' ? 'Kiküldve' : 'Piszkozat'}</span></td>
							</tr>`)}</tbody>
					</table>
				</div>` : html`<${Empty} icon="receipt" title="Még nincs riport">A hónap elején a havidíjas ügyfeleknek magától elkészül a piszkozat (Beállítások). Most is készíthetsz egyet.</${Empty}>`}
			${creating ? html`<${NewReportModal} clients=${boot.clients} onClose=${() => setCreating(false)} />` : null}
		</div>`;
}

function ListEditor({ items, onChange, placeholder, withGroup }) {
	const upd = (i, patch) => onChange(items.map((x, k) => (k === i ? { ...x, ...patch } : x)));
	return html`
		<div class="grid-rows">
			${items.map((it, i) => html`
				<div key=${i + ':' + items.length} class=${'grid-row ' + (withGroup ? 'grid-row--list2' : 'grid-row--list1')}>
					<input value=${it.text} placeholder=${placeholder} onChange=${(e) => upd(i, { text: e.target.value })} />
					${withGroup ? html`<input class="small" value=${it.group} placeholder="Projekt / csoport" onChange=${(e) => upd(i, { group: e.target.value })} />` : null}
					<button type="button" class="icon-btn" aria-label="Törlés" onClick=${() => onChange(items.filter((_, k) => k !== i))}><${Icon} name="x" /></button>
				</div>`)}
			<button type="button" class="add-row" onClick=${() => onChange([...items, { text: '', group: '' }])}><${Icon} name="plus" /> Sor</button>
		</div>`;
}

function MetricsEditor({ metrics, onChange }) {
	const upd = (i, patch) => onChange(metrics.map((x, k) => (k === i ? { ...x, ...patch } : x)));
	const num = (v) => (v === '' || v === null || v === undefined ? null : Number(v));
	return html`
		<div class="grid-rows">
			<div class="grid-row grid-row--metric grid-row--head"><span>Csoport</span><span>Mutató</span><span>E hónap</span><span>Előző</span><span>Formátum</span><span></span></div>
			${metrics.map((m, i) => html`
				<div key=${i + ':' + metrics.length} class=${'grid-row grid-row--metric' + (m.manual ? '' : ' is-auto')}>
					<input value=${m.section} disabled=${!m.manual} onChange=${(e) => upd(i, { section: e.target.value })} />
					<input value=${m.label} disabled=${!m.manual} onChange=${(e) => upd(i, { label: e.target.value })} />
					<input type="number" step="any" value=${m.value ?? ''} onChange=${(e) => upd(i, { value: num(e.target.value) })} />
					<input type="number" step="any" value=${m.prev ?? ''} onChange=${(e) => upd(i, { prev: num(e.target.value) })} />
					<select value=${m.format || 'int'} disabled=${!m.manual} onChange=${(e) => upd(i, { format: e.target.value })}>${Object.entries(FORMATS).map(([k, l]) => html`<option value=${k}>${l}</option>`)}</select>
					<button type="button" class="icon-btn" aria-label="Törlés" onClick=${() => onChange(metrics.filter((_, k) => k !== i))}><${Icon} name="x" /></button>
				</div>`)}
			<button type="button" class="add-row" onClick=${() => onChange([...metrics, { section: 'Google Cégprofil', label: '', value: null, prev: null, format: 'int', better: 'up', manual: true }])}><${Icon} name="plus" /> Kézi mutató (pl. Cégprofil hívások, helyezés)</button>
			<p class="hint">A bekötött forrásokból jövő mutatók neve fix (a portálon az ügyfél nyelvén jelenik meg); az értékük javítható. A kézi mutatót a saját nyelvén írd.</p>
		</div>`;
}

export function ReportPage({ id }) {
	const [r, setR] = useState(null);
	const [f, setF] = useState(null);
	const [dirty, setDirty] = useState(false);
	const [busy, setBusy] = useState('');

	const take = (d) => {
		setR(d);
		setF({ title: d.title, summary: d.summary, highlights: d.highlights.map((h) => ({ text: h })), metrics: d.metrics, work: d.work, next: d.next });
		setDirty(false);
	};
	useEffect(() => { api('/reports/' + id).then(take).catch((e) => toast(e.message, 'error')); }, [id]);
	if (!r || !f) return html`<${Spinner} />`;

	const sent = r.status === 'sent';
	const edit = (patch) => { setF({ ...f, ...patch }); setDirty(true); };
	const body = () => ({ ...f, highlights: f.highlights.map((h) => h.text) });
	const run = (key, fn, msg) => { setBusy(key); return fn().then((d) => { if (d) take(d); if (msg) toast(msg); }).catch((e) => toast(e.message, 'error')).finally(() => setBusy('')); };
	const save = () => run('save', () => api('/reports/' + r.id, { method: 'POST', body: body() }), 'Mentve.');
	const rebuild = (ai) => {
		if (!window.confirm(ai ? 'Újra lekérjük a számokat, és az AI újraírja az összefoglalót és a kiemeléseket. A kézi mutatók megmaradnak. Mehet?' : 'Újra lekérjük a számokat és a feladatokat. Az összefoglaló marad. Mehet?')) return;
		run('rebuild', async () => { if (dirty) await api('/reports/' + r.id, { method: 'POST', body: body() }); return api('/reports/' + r.id + '/rebuild', { method: 'POST', body: { ai } }); }, 'Frissítve.');
	};
	const send = () => {
		if (!window.confirm('Kiküldöd a riportot? Az ügyfél e-mailt kap, és a portálon látja.')) return;
		run('send', async () => { if (dirty) await api('/reports/' + r.id, { method: 'POST', body: body() }); return api('/reports/' + r.id + '/send', { method: 'POST' }); }, 'Kiküldve.');
	};

	return html`
		<div class="page page--wide">
			<header class="page__head">
				<div>
					<a class="crumb" href="#/reports">Havi riportok</a>
					<h1 class="content-title">${r.client} — ${monthLabel(r.period)} <span class=${'pill pill--rep-' + r.status}>${sent ? 'Kiküldve' : 'Piszkozat'}</span></h1>
					<p class="muted">${r.lang === 'hu' ? 'Magyar' : 'Angol'} riport · ${r.sources.length ? r.sources.map((s) => SOURCE[s] || s).join(', ') : 'nincs bekötött adatforrás'}</p>
				</div>
				<div class="head-actions">
					<a class="btn btn--ghost" href=${r.portal_url} target="_blank" rel="noopener"><${Icon} name="eye" /> Portál</a>
					${!sent ? html`
						<button class="btn btn--ghost" disabled=${!!busy} onClick=${() => rebuild(false)}>Számok frissítése</button>
						${r.ai ? html`<button class="btn btn--ghost" disabled=${!!busy} onClick=${() => rebuild(true)}><${Icon} name="sparkle" /> ${busy === 'rebuild' ? 'Készül…' : 'Újraírás AI-val'}</button>` : null}
						<button class="btn btn--ghost" disabled=${!!busy || !dirty} onClick=${save}>Mentés</button>
						<button class="btn" disabled=${!!busy} onClick=${send}><${Icon} name="send" /> Kiküldés</button>` : html`
						<button class="btn btn--ghost" disabled=${!!busy} onClick=${() => window.confirm('Visszavonod javításra? Amíg újra ki nem küldöd, az ügyfél nem látja.') && run('unsend', () => api('/reports/' + r.id + '/unsend', { method: 'POST' }))}>Visszavonás javításra</button>`}
				</div>
			</header>
			${r.errors.length ? html`<div class="alert alert--warn"><strong>Nem minden adat jött meg:</strong> ${r.errors.join(' · ')}</div>` : null}
			<div class="report-edit">
				<section class="card">
					<h3>Kiemelések <span class="muted small">(a riport tetején és az e-mailben)</span></h3>
					${sent ? html`<ul>${r.highlights.map((h) => html`<li>${h}</li>`)}</ul>` : html`<${ListEditor} items=${f.highlights} placeholder="pl. 300 kattintás a Google-ből (+50%)" onChange=${(highlights) => edit({ highlights })} />`}
				</section>
				<section class="card">
					<h3>Összefoglaló</h3>
					${sent ? html`<div class="doc" dangerouslySetInnerHTML=${{ __html: r.summary }}></div>` : html`<${RichText} value=${f.summary} onChange=${(summary) => edit({ summary })} placeholder="Mi történt a hónapban, és mit jelent az ügyfélnek…" />`}
				</section>
				<section class="card">
					<h3>Számok</h3>
					${sent ? html`<p class="hint">${r.metrics.length} mutató.</p>` : html`<${MetricsEditor} metrics=${f.metrics} onChange=${(metrics) => edit({ metrics })} />`}
				</section>
				<div class="report-cols">
					<section class="card"><h3>Amit csináltunk</h3>${sent ? html`<ul>${r.work.map((w) => html`<li>${w.text}</li>`)}</ul>` : html`<${ListEditor} items=${f.work} withGroup placeholder="Elvégzett munka" onChange=${(work) => edit({ work })} />`}</section>
					<section class="card"><h3>Jövő hónapban</h3>${sent ? html`<ul>${r.next.map((w) => html`<li>${w.text}</li>`)}</ul>` : html`<${ListEditor} items=${f.next} placeholder="Következő lépés" onChange=${(next) => edit({ next })} />`}</section>
				</div>
				${!sent && r.status === 'draft' ? html`<p class="hint"><button class="link danger" onClick=${() => window.confirm('Törlöd a piszkozatot?') && api('/reports/' + r.id, { method: 'DELETE' }).then(() => navigate('/reports'))}>Piszkozat törlése</button>${dirty ? ' · Nem mentett változás' : ''}</p>` : null}
			</div>
		</div>`;
}
