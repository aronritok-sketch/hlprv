/**
 * Kulcsszóadat API-ról (DataForSEO, Ahrefs): volumen + nehézség, ötletek a seed kulcsszavakból, domain rangsorolt
 * kulcsszavai, SERP-versenytársak. Havi keret és e havi költés kijelzése.
 */
import { html, useState, useApp, api, useLoad, useJob, toast, errorText, fmt, Icon, Spinner, Field, Input, Textarea, Select, can } from '../../ui.js';

const NEEDS = {
	volume: 'dataforseo', ideas: 'dataforseo', ranked: 'dataforseo', serp: 'dataforseo', ahrefs_organic: 'ahrefs',
};
const HINTS = {
	volume: 'A projekt összes aktív kulcsszavához (vagy az alább felsoroltakhoz) Google Ads volument, CPC-t és nehézséget kér. A lokáció megadásával városi szintű adat is kérhető.',
	ideas: 'Kulcsszóötletek a seed kulcsszavakból (ha üres, a projekt seed kulcsszavait használja). A kizárási szabályok automatikusan érvényesülnek.',
	ranked: 'Egy domain (alapértelmezés: a projekt domainje, de lehet versenytárs is) jelenleg rangsoroló kulcsszavai pozícióval és URL-lel.',
	serp: 'A felsorolt kulcsszavak (max. 50) Google top 20 organikus találata – a versenytárs-oszlopokba kerül.',
	ahrefs_organic: 'Egy domain organikus kulcsszavai az Ahrefs API-ból (Enterprise csomag kell hozzá).',
};

export function ApiFetch({ project, onDone }) {
	const { me } = useApp();
	const allowed = can(me, 'integrations.run');
	const [st, loading, , reload] = useLoad(allowed ? '/projects/' + project.id + '/research/api' : null);
	const [form, setForm] = useState({ action: 'volume', location_label: '', seeds: '', terms: '', target: '', limit: '' });
	const [job, setJob] = useJob((j) => {
		const r = j.result || {};
		toast('Kész: ' + [r.created ? r.created + ' új kulcsszó' : '', r.updated ? r.updated + ' frissítve' : '', r.merged ? r.merged + ' meglévő' : '', r.rankings ? r.rankings + ' rangsor' : ''].filter(Boolean).join(', '));
		reload();
		onDone && onDone();
	});
	if (!allowed) return '';
	if (loading && !st) return html`<section class="card pad"><${Spinner} /></section>`;
	if (!st) return '';
	const running = job && (job.status === 'queued' || job.status === 'running');
	const set = (k) => (v) => setForm((f) => ({ ...f, [k]: v }));
	const available = Object.entries(st.actions).filter(([k]) => st[NEEDS[k]]);
	if (!available.length) {
		return html`<section class="card"><header class="card__head"><h2><${Icon} name="link" /> Adatlekérés API-ról</h2></header>
			<p class="pad muted small">Nincs DataForSEO vagy Ahrefs API hozzáférés beállítva. ${can(me, 'settings.manage') ? html`<a class="link" href="#/settings">Beállítások → Integrációk</a>` : 'Szólj az adminnak.'}</p></section>`;
	}
	const action = st[NEEDS[form.action]] ? form.action : available[0][0];
	const lines = (s) => s.split(/\n|,/).map((x) => x.trim()).filter(Boolean);
	const start = async () => {
		const body = {
			action, location_label: form.location_label,
			location_name: form.location_name || undefined, language_code: form.language_code || undefined,
			seeds: action === 'ideas' ? lines(form.seeds) : [], terms: ['volume', 'serp'].includes(action) ? lines(form.terms) : [],
			target: ['ranked', 'ahrefs_organic'].includes(action) ? form.target || undefined : undefined,
			limit: form.limit ? Number(form.limit) : undefined,
		};
		if (action === 'serp' && !body.terms.length) { toast('Adj meg legalább egy kulcsszót.', 'error'); return; }
		try { setJob(await api('/projects/' + project.id + '/research/api', { method: 'POST', body })); } catch (e) { toast(errorText(e), 'error'); }
	};
	const over = st.budget_usd && st.spend_month_usd >= st.budget_usd;
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="link" /> Adatlekérés API-ról</h2>
			<span class=${'small ' + (over ? 'danger' : 'muted')} title="A projekt e havi API-költése (DataForSEO, Ahrefs, AI) a beállított kerethez képest">
				E hónap: $${st.spend_month_usd.toFixed(2)}${st.budget_usd ? ' / $' + st.budget_usd : ''}</span>
		</header>
		<div class="pad stack-sm">
			<${Field} label="Művelet"><${Select} value=${action} onChange=${set('action')} options=${available} /></${Field}>
			<p class="muted small">${HINTS[action]}</p>
			${action !== 'ahrefs_organic' ? html`<div class="form-grid">
				<${Field} label="Lokáció (DataForSEO)" hint="pl. United States vagy Naples,Florida,United States"><${Input} value=${form.location_name ?? ''} placeholder=${st.defaults.location_name} onInput=${set('location_name')} /></${Field}>
				<${Field} label="Nyelv"><${Input} value=${form.language_code ?? ''} placeholder=${st.defaults.language_code} onInput=${set('language_code')} /></${Field}>
			</div>` : ''}
			<${Field} label="Az adat a projekt melyik lokációjához tartozzon?">
				<${Select} value=${form.location_label} onChange=${set('location_label')} options=${[['', 'Országos / általános'], ...project.locations.map((l) => [l, l])]} />
			</${Field}>
			${action === 'ideas' ? html`<${Field} label="Seed kulcsszavak (soronként)" hint="Üresen a projekt seed kulcsszavai"><${Textarea} rows="3" value=${form.seeds} onInput=${set('seeds')} /></${Field}>` : ''}
			${['volume', 'serp'].includes(action) ? html`<${Field} label=${action === 'serp' ? 'Kulcsszavak (soronként, max. 50)' : 'Csak ezekhez a kulcsszavakhoz (üresen: mindhez)'}><${Textarea} rows="3" value=${form.terms} onInput=${set('terms')} /></${Field}>` : ''}
			${['ranked', 'ahrefs_organic'].includes(action) ? html`<div class="form-grid">
				<${Field} label="Domain" hint="Üresen a projekt domainje"><${Input} value=${form.target} placeholder=${project.domain} onInput=${set('target')} /></${Field}>
				<${Field} label="Legfeljebb (db)"><${Input} type="number" value=${form.limit} placeholder="500" onInput=${set('limit')} /></${Field}>
			</div>` : ''}
			<div class="row-edit">
				<button class="btn btn--sm" disabled=${running || over} onClick=${start}>${running ? 'Lekérés… ' + Math.round((job.progress || 0) * 100) + '%' : 'Lekérés indítása'}</button>
				${running && job.message ? html`<span class="muted small">${job.message}</span>` : ''}
				${over ? html`<span class="danger small">A havi keret elfogyott.</span>` : ''}
			</div>
			<p class="muted small">Az azonos kérések 30 napig gyorsítótárból jönnek (nem kerülnek újra pénzbe). Minden hívás naplózva van a költséggel.</p>
		</div>
	</section>`;
}
