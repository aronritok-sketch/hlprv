/**
 * Új projekt – 3 lépés: 1) ügyfél és domain, 2) üzlet, 3) terjedelem, versenytársak, kiinduló kulcsszavak.
 */
import { html, useState, useEffect, useApp, api, navigate, toast, errorText, Icon, Field, Input, Textarea, Select, Tags, Checkbox } from '../ui.js';

const STEPS = ['Ügyfél és domain', 'Üzleti háttér', 'Terjedelem és kutatási input'];

export function ProjectNew() {
	const { meta } = useApp();
	const [step, setStep] = useState(0);
	const [busy, setBusy] = useState(false);
	const [crm, setCrm] = useState({ active: false, clients: [] });
	const [known, setKnown] = useState([]);
	const [f, setF] = useState({
		clientMode: 'crm', client_id: '', crm_client_id: '', client_name: '',
		name: '', domain: '', industry: '', market: 'US', locations: [],
		content_language: 'en-US', working_language: 'hu',
		services: [{ name: '', high_margin: false, priority: false }],
		target_audience: '', business_goals: '', conversion_goals: '', excluded_topics: [],
		scope: ['keyword_research', 'structure', 'content_strategy'], start_date: '', strategy_months: 6, content_per_month: 2,
		competitors: [], seeds: Object.fromEntries(Object.keys(meta.seed_kinds).map((k) => [k, []])),
	});
	const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));

	useEffect(() => {
		api('/crm/clients').then((r) => { setCrm(r); if (!r.active) setF((x) => ({ ...x, clientMode: 'new' })); }).catch(() => setF((x) => ({ ...x, clientMode: 'new' })));
		api('/clients').then(setKnown).catch(() => {});
	}, []);

	const pickCrm = (id) => {
		const c = crm.clients.find((x) => String(x.id) === String(id));
		setF((x) => ({
			...x, crm_client_id: id,
			client_name: c ? c.name : '',
			name: x.name || (c ? c.name + ' – SEO' : ''),
			domain: x.domain || (c && c.website ? c.website : ''),
		}));
	};

	const valid = [
		() => f.name.trim() && f.domain.trim() && (f.clientMode === 'existing' ? f.client_id : f.clientMode === 'crm' ? f.crm_client_id : f.client_name.trim()),
		() => true,
		() => f.scope.length > 0,
	];

	const submit = async () => {
		setBusy(true);
		const existing = f.clientMode === 'existing' ? Number(f.client_id) : null;
		const crmExisting = f.clientMode === 'crm' ? known.find((k) => String(k.crm_client_id) === String(f.crm_client_id)) : null;
		const body = {
			name: f.name, domain: f.domain, industry: f.industry, market: f.market, locations: f.locations,
			content_language: f.content_language, working_language: f.working_language,
			business_services: f.services.filter((s) => s.name.trim()),
			target_audience: f.target_audience, business_goals: f.business_goals, conversion_goals: f.conversion_goals,
			excluded_topics: f.excluded_topics, scope: f.scope, start_date: f.start_date || null,
			strategy_months: Number(f.strategy_months) || 6, content_per_month: Number(f.content_per_month) || 0,
			competitors: f.competitors.map((d) => ({ domain: d, source: 'client' })),
			seed_keywords: Object.entries(f.seeds).flatMap(([kind, list]) => list.map((keyword) => ({ keyword, kind }))),
		};
		if (existing || crmExisting) body.client_id = existing || crmExisting.id;
		else body.client = { name: f.client_name, crm_client_id: f.clientMode === 'crm' ? Number(f.crm_client_id) : null, primary_domain: f.domain };
		try {
			const p = await api('/projects', { method: 'POST', body });
			toast('Projekt létrehozva.');
			navigate('/projects/' + p.id);
		} catch (e) {
			toast(errorText(e), 'error');
			setBusy(false);
		}
	};

	const seedCount = Object.values(f.seeds).reduce((n, l) => n + l.length, 0);

	return html`<div class="page page--narrow">
		<header class="page__head"><div><h1>Új SEO projekt</h1><p class="muted">${STEPS[step]} · ${step + 1}/3</p></div></header>
		<ol class="wizard-steps">${STEPS.map((s, i) => html`<li key=${s} class=${i === step ? 'is-current' : i < step ? 'is-done' : ''}>${s}</li>`)}</ol>

		<div class="card card--form">
		${step === 0 ? html`
			<div class="segmented">
				${crm.active ? html`<button class=${f.clientMode === 'crm' ? 'is-active' : ''} onClick=${() => set('clientMode')('crm')}>CRM ügyfél</button>` : ''}
				${known.length ? html`<button class=${f.clientMode === 'existing' ? 'is-active' : ''} onClick=${() => set('clientMode')('existing')}>Meglévő SEO ügyfél</button>` : ''}
				<button class=${f.clientMode === 'new' ? 'is-active' : ''} onClick=${() => set('clientMode')('new')}>Új ügyfél</button>
			</div>
			<div class="form-grid">
				${f.clientMode === 'crm' ? html`<${Field} label="CRM ügyfél" wide><${Select} value=${f.crm_client_id} onChange=${pickCrm} options=${crm.clients.map((c) => [c.id, c.name])} placeholder="Válassz…" /></${Field}>` : ''}
				${f.clientMode === 'existing' ? html`<${Field} label="Ügyfél" wide><${Select} value=${f.client_id} onChange=${set('client_id')} options=${known.map((c) => [c.id, c.name])} placeholder="Válassz…" /></${Field}>` : ''}
				${f.clientMode === 'new' ? html`<${Field} label="Ügyfél neve" wide><${Input} value=${f.client_name} onInput=${set('client_name')} placeholder="pl. Imperial Kitchens" /></${Field}>` : ''}
				<${Field} label="Projekt neve"><${Input} value=${f.name} onInput=${set('name')} placeholder="pl. Imperial Kitchens – SEO 2026" /></${Field}>
				<${Field} label="Vizsgált domain"><${Input} value=${f.domain} onInput=${set('domain')} placeholder="imperialkitchens.com" /></${Field}>
			</div>` : ''}

		${step === 1 ? html`<div class="form-grid">
			<${Field} label="Iparág"><${Input} value=${f.industry} onInput=${set('industry')} placeholder="pl. Kitchen Remodeling" /></${Field}>
			<${Field} label="Piac (ország)"><${Input} value=${f.market} onInput=${set('market')} placeholder="US / HU" /></${Field}>
			<${Field} label="Lokációk" wide hint="Városok, szolgáltatási terület. Enter vagy vessző után új elem."><${Tags} value=${f.locations} onChange=${set('locations')} placeholder="pl. Naples" /></${Field}>
			<${Field} label="Tartalmi nyelv" hint="Kulcsszavak, H1-ek, ügyféldokumentumok nyelve."><${Select} value=${f.content_language} onChange=${set('content_language')} options=${meta.languages} /></${Field}>
			<${Field} label="Munkanyelv" hint="Belső briefek nyelve."><${Select} value=${f.working_language} onChange=${set('working_language')} options=${meta.languages} /></${Field}>
			<div class="field field--wide"><span class="field__label">Szolgáltatások</span>
				${f.services.map((s, i) => html`<div key=${i} class="row-edit">
					<input class="input" value=${s.name} placeholder="Szolgáltatás neve" onInput=${(e) => set('services')(f.services.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} />
					<${Checkbox} checked=${s.high_margin} label="Magas árrés" onChange=${(v) => set('services')(f.services.map((x, j) => (j === i ? { ...x, high_margin: v } : x)))} />
					<${Checkbox} checked=${s.priority} label="Kiemelt" onChange=${(v) => set('services')(f.services.map((x, j) => (j === i ? { ...x, priority: v } : x)))} />
					<button class="icon-btn" aria-label="Törlés" onClick=${() => set('services')(f.services.filter((_, j) => j !== i))}><${Icon} name="x" /></button>
				</div>`)}
				<button class="btn btn--ghost btn--sm" onClick=${() => set('services')([...f.services, { name: '', high_margin: false, priority: false }])}><${Icon} name="plus" /> Szolgáltatás</button>
			</div>
			<${Field} label="Célközönség" wide><${Textarea} value=${f.target_audience} onInput=${set('target_audience')} /></${Field}>
			<${Field} label="Üzleti célok" wide><${Textarea} value=${f.business_goals} onInput=${set('business_goals')} /></${Field}>
			<${Field} label="Konverziós célok" wide hint="Ha van konkrét termék vagy szolgáltatás, amit elsősorban el kell adni."><${Textarea} value=${f.conversion_goals} onInput=${set('conversion_goals')} /></${Field}>
			<${Field} label="Kizárt témák / szolgáltatások" wide><${Tags} value=${f.excluded_topics} onChange=${set('excluded_topics')} /></${Field}>
		</div>` : ''}

		${step === 2 ? html`<div class="form-grid">
			<div class="field field--wide"><span class="field__label">Eladott szolgáltatások (terjedelem)</span>
				<div class="check-grid">${Object.entries(meta.scopes).map(([k, label]) => html`<${Checkbox} key=${k} label=${label} checked=${f.scope.includes(k)}
					onChange=${(v) => set('scope')(v ? [...f.scope, k] : f.scope.filter((s) => s !== k))} />`)}</div>
			</div>
			<${Field} label="Kezdés"><input class="input" type="date" value=${f.start_date} onInput=${(e) => set('start_date')(e.target.value)} /></${Field}>
			<${Field} label="Stratégia hossza (hónap)"><input class="input" type="number" min="1" max="24" value=${f.strategy_months} onInput=${(e) => set('strategy_months')(e.target.value)} /></${Field}>
			<${Field} label="Tartalom / hónap" hint="Alap: 2, Fejlődő: 4"><input class="input" type="number" min="0" max="30" value=${f.content_per_month} onInput=${(e) => set('content_per_month')(e.target.value)} /></${Field}>
			<${Field} label="Versenytársak (4–5 domain)" wide hint="Organikus pozíció alapján."><${Tags} value=${f.competitors} onChange=${set('competitors')} placeholder="versenytars.com" /></${Field}>
			<div class="field field--wide"><span class="field__label">Kiinduló kulcsszavak (15–25 db) · ${seedCount} db</span>
				${Object.entries(meta.seed_kinds).map(([k, label]) => html`<div key=${k} class="seed-row"><span class="seed-row__label">${label}</span>
					<${Tags} value=${f.seeds[k]} onChange=${(v) => setF((x) => ({ ...x, seeds: { ...x.seeds, [k]: v } }))} /></div>`)}
			</div>
		</div>` : ''}
		</div>

		<footer class="wizard-foot">
			${step > 0 ? html`<button class="btn btn--ghost" onClick=${() => setStep(step - 1)}>Vissza</button>` : html`<a class="btn btn--ghost" href="#/projects">Mégse</a>`}
			${step < 2
				? html`<button class="btn" disabled=${!valid[step]()} onClick=${() => setStep(step + 1)}>Tovább</button>`
				: html`<button class="btn" disabled=${busy || !valid[2]()} onClick=${submit}>${busy ? 'Mentés…' : 'Projekt létrehozása'}</button>`}
		</footer>
	</div>`;
}
