/**
 * Áttekintés: státuszváltás, bekérési checklist, projektadatok, versenytársak, kiinduló kulcsszavak, csapat, előzmények.
 */
import { html, useState, useEffect, useApp, api, useLoad, toast, errorText, fmt, Icon, Drawer, Field, Input, Textarea, Select, Tags, Checkbox, Pill, Avatar, can } from '../../ui.js';
import { BusinessProfile } from './profile.js';

function Transitions({ project, setProject }) {
	const { me, meta } = useApp();
	const [gates] = useLoad('/projects/' + project.id + '/gates', [project.status]);
	const [note, setNote] = useState('');
	const [busy, setBusy] = useState(false);
	const [problems, setProblems] = useState([]);
	const targets = project.allowed_transitions;
	const idx = meta.status_order.indexOf(project.status);

	const go = async (to, force = false) => {
		setBusy(true);
		setProblems([]);
		try {
			const p = await api('/projects/' + project.id + '/transition', { method: 'POST', body: { to, note, force } });
			setProject(p);
			setNote('');
			toast('Státusz: ' + meta.statuses[to]);
		} catch (e) {
			setProblems(e.problems || []);
			toast(e.message, 'error');
		}
		setBusy(false);
	};

	if (!targets.length) {
		return html`<section class="card"><header class="card__head"><h2><${Icon} name="flag" /> Következő lépés</h2></header>
			<p class="muted pad">A státuszt a SEO manager lépteti. Jelenleg: <strong>${meta.statuses[project.status]}</strong>.</p></section>`;
	}
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="flag" /> Következő lépés</h2></header>
		<div class="pad stack-sm">
			${targets.map((to) => {
				const blockers = (gates && gates[to]) || [];
				const forward = meta.status_order.indexOf(to) > idx;
				const regular = meta.status_order.indexOf(to) === idx + 1 || (project.status === 'client_review' && to === 'seo_review');
				if (me.role === 'admin' && !regular) return null;
				return html`<div key=${to} class="transition">
					<button class=${'btn ' + (forward ? '' : 'btn--ghost')} disabled=${busy} onClick=${() => go(to)}>
						${forward ? 'Tovább: ' : 'Vissza: '}${meta.statuses[to]}
					</button>
					${blockers.length ? html`<ul class="blockers">${blockers.map((b) => html`<li key=${b}>${b}</li>`)}</ul>` : ''}
				</div>`;
			})}
			<${Field} label="Megjegyzés a váltáshoz"><${Input} value=${note} onInput=${setNote} placeholder="pl. az ügyfél a kulcsszókutatást jóváhagyta" /></${Field}>
			${me.role === 'admin' ? html`<details class="admin-override"><summary>Admin: bármelyik státuszba</summary>
				<div class="row-edit">${meta.status_order.filter((s) => s !== project.status).map((s) => html`<button key=${s} class="btn btn--ghost btn--sm" disabled=${busy || !note.trim()} onClick=${() => go(s, true)}>${meta.statuses[s]}</button>`)}</div>
				<p class="muted small">Megjegyzés kötelező. A hiányzó feltételeket ilyenkor figyelmen kívül hagyja.</p>
			</details>` : ''}
			${problems.length ? html`<ul class="blockers">${problems.map((b) => html`<li key=${b}>${b}</li>`)}</ul>` : ''}
		</div>
	</section>`;
}

function Intake({ project, setProject }) {
	const { me, meta } = useApp();
	const editable = can(me, 'project.edit');
	const items = project.intake_items.filter((i) => i.status !== 'n_a' || editable);
	const save = async (key, patch) => {
		try {
			const r = await api('/projects/' + project.id + '/intake/' + key, { method: 'PATCH', body: patch });
			setProject((p) => ({ ...p, intake_items: p.intake_items.map((i) => (i.key === key ? { ...i, ...r } : i)) }));
		} catch (e) { toast(e.message, 'error'); }
	};
	const open = project.intake_items.filter((i) => i.status === 'missing' || i.status === 'requested').length;
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="check" /> Bekérendő anyagok</h2>${open ? html`<${Pill} kind="warn">${open} nyitott</${Pill}>` : html`<${Pill} kind="ok">Minden megvan</${Pill}>`}</header>
		<ul class="intake">${items.map((i) => html`<li key=${i.key} class=${'intake__item intake__item--' + i.status}>
			<div class="intake__label">${meta.intake_items[i.key]}</div>
			${editable ? html`<select class="input input--sm" value=${i.status} onChange=${(e) => save(i.key, { status: e.target.value })}>
				${Object.entries(meta.intake_statuses).map(([k, v]) => html`<option key=${k} value=${k}>${v}</option>`)}
			</select>` : html`<span class="muted">${meta.intake_statuses[i.status]}</span>`}
			${editable ? html`<input class="input input--sm intake__note" value=${i.note} placeholder="Megjegyzés" onChange=${(e) => save(i.key, { note: e.target.value })} />` : i.note ? html`<span class="muted small">${i.note}</span>` : ''}
		</li>`)}</ul>
	</section>`;
}

function EditProject({ project, onClose, onSaved }) {
	const { meta } = useApp();
	const [users] = useLoad('/users');
	const [f, setF] = useState({
		name: project.name, domain: project.domain, industry: project.industry, market: project.market,
		locations: project.locations, content_language: project.content_language, working_language: project.working_language,
		business_services: project.business_services.length ? project.business_services : [{ name: '', high_margin: false, priority: false }],
		target_audience: project.target_audience, business_goals: project.business_goals, conversion_goals: project.conversion_goals,
		excluded_topics: project.excluded_topics, scope: project.scope, start_date: project.start_date || '',
		strategy_months: project.strategy_months, content_per_month: project.content_per_month,
		owner_id: project.owner ? project.owner.id : '', on_hold: project.on_hold,
	});
	const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));
	const save = async () => {
		try {
			const body = { ...f, start_date: f.start_date || null, owner_id: f.owner_id ? Number(f.owner_id) : null,
				strategy_months: Number(f.strategy_months), content_per_month: Number(f.content_per_month),
				business_services: f.business_services.filter((s) => s.name.trim()) };
			if (!body.owner_id) delete body.owner_id;
			const p = await api('/projects/' + project.id, { method: 'PATCH', body });
			onSaved(p);
			toast('Mentve.');
			onClose();
		} catch (e) { toast(errorText(e), 'error'); }
	};
	return html`<${Drawer} title="Projektadatok" onClose=${onClose} footer=${html`<button class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" onClick=${save}>Mentés</button>`}>
		<div class="form-grid">
			<${Field} label="Projekt neve" wide><${Input} value=${f.name} onInput=${set('name')} /></${Field}>
			<${Field} label="Domain"><${Input} value=${f.domain} onInput=${set('domain')} /></${Field}>
			<${Field} label="Felelős"><${Select} value=${f.owner_id} onChange=${set('owner_id')} options=${(users || []).filter((u) => u.is_active).map((u) => [u.id, u.display_name])} placeholder="–" /></${Field}>
			<${Field} label="Iparág"><${Input} value=${f.industry} onInput=${set('industry')} /></${Field}>
			<${Field} label="Piac"><${Input} value=${f.market} onInput=${set('market')} /></${Field}>
			<${Field} label="Lokációk" wide><${Tags} value=${f.locations} onChange=${set('locations')} /></${Field}>
			<${Field} label="Tartalmi nyelv"><${Select} value=${f.content_language} onChange=${set('content_language')} options=${meta.languages} /></${Field}>
			<${Field} label="Munkanyelv"><${Select} value=${f.working_language} onChange=${set('working_language')} options=${meta.languages} /></${Field}>
			<div class="field field--wide"><span class="field__label">Szolgáltatások</span>
				${f.business_services.map((s, i) => html`<div key=${i} class="row-edit">
					<input class="input" value=${s.name} onInput=${(e) => set('business_services')(f.business_services.map((x, j) => (j === i ? { ...x, name: e.target.value } : x)))} />
					<${Checkbox} checked=${s.high_margin} label="Magas árrés" onChange=${(v) => set('business_services')(f.business_services.map((x, j) => (j === i ? { ...x, high_margin: v } : x)))} />
					<${Checkbox} checked=${s.priority} label="Kiemelt" onChange=${(v) => set('business_services')(f.business_services.map((x, j) => (j === i ? { ...x, priority: v } : x)))} />
					<button class="icon-btn" onClick=${() => set('business_services')(f.business_services.filter((_, j) => j !== i))}><${Icon} name="x" /></button>
				</div>`)}
				<button class="btn btn--ghost btn--sm" onClick=${() => set('business_services')([...f.business_services, { name: '', high_margin: false, priority: false }])}><${Icon} name="plus" /> Szolgáltatás</button>
			</div>
			<${Field} label="Célközönség" wide><${Textarea} value=${f.target_audience} onInput=${set('target_audience')} /></${Field}>
			<${Field} label="Üzleti célok" wide><${Textarea} value=${f.business_goals} onInput=${set('business_goals')} /></${Field}>
			<${Field} label="Konverziós célok" wide><${Textarea} value=${f.conversion_goals} onInput=${set('conversion_goals')} /></${Field}>
			<${Field} label="Kizárt témák" wide><${Tags} value=${f.excluded_topics} onChange=${set('excluded_topics')} /></${Field}>
			<div class="field field--wide"><span class="field__label">Terjedelem</span>
				<div class="check-grid">${Object.entries(meta.scopes).map(([k, label]) => html`<${Checkbox} key=${k} label=${label} checked=${f.scope.includes(k)}
					onChange=${(v) => set('scope')(v ? [...f.scope, k] : f.scope.filter((s) => s !== k))} />`)}</div></div>
			<${Field} label="Kezdés"><input class="input" type="date" value=${f.start_date} onInput=${(e) => set('start_date')(e.target.value)} /></${Field}>
			<${Field} label="Stratégia (hónap)"><input class="input" type="number" min="1" max="24" value=${f.strategy_months} onInput=${(e) => set('strategy_months')(e.target.value)} /></${Field}>
			<${Field} label="Tartalom / hónap"><input class="input" type="number" min="0" max="30" value=${f.content_per_month} onInput=${(e) => set('content_per_month')(e.target.value)} /></${Field}>
			<${Field} label="Szünetel"><${Checkbox} checked=${f.on_hold} label="A projekt szünetel" onChange=${set('on_hold')} /></${Field}>
		</div>
	</${Drawer}>`;
}

function Facts({ project, setProject }) {
	const { me, meta } = useApp();
	const [editing, setEditing] = useState(false);
	const row = (label, value) => html`<div class="fact"><dt>${label}</dt><dd>${value || html`<span class="muted">–</span>`}</dd></div>`;
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="folder" /> Projektadatok</h2>
			${can(me, 'project.edit') ? html`<button class="btn btn--ghost btn--sm" onClick=${() => setEditing(true)}><${Icon} name="edit" /> Szerkesztés</button>` : ''}</header>
		<dl class="facts">
			${row('Iparág', project.industry)}
			${row('Piac', project.market)}
			${row('Nyelv', meta.languages[project.content_language] + ' tartalom · ' + meta.languages[project.working_language] + ' briefek')}
			${row('Felelős', project.owner && project.owner.display_name)}
			${row('Kezdés', project.start_date && fmt.date(project.start_date))}
			${row('Ritmus', project.strategy_months + ' hónap · ' + project.content_per_month + ' tartalom/hó')}
			${row('Upsell emlékeztető', project.upsell_reminder_at && fmt.date(project.upsell_reminder_at))}
			${row('Terjedelem', project.scope.map((s) => meta.scopes[s]).join(', '))}
			${row('Szolgáltatások', project.business_services.map((s) => s.name + (s.high_margin ? ' (magas árrés)' : '') + (s.priority ? ' ★' : '')).join(', '))}
			${row('Célközönség', project.target_audience)}
			${row('Üzleti célok', project.business_goals)}
			${row('Konverziós célok', project.conversion_goals)}
			${row('Kizárt témák', project.excluded_topics.join(', '))}
		</dl>
		${editing ? html`<${EditProject} project=${project} onClose=${() => setEditing(false)} onSaved=${setProject} />` : ''}
	</section>`;
}

function ResearchInput({ project, setProject }) {
	const { me, meta } = useApp();
	const editable = can(me, 'project.edit');
	const [competitors, setCompetitors] = useState(project.competitors.map((c) => c.domain));
	const [seeds, setSeeds] = useState(() => Object.fromEntries(Object.keys(meta.seed_kinds).map((k) => [k, project.seed_keywords.filter((s) => s.kind === k).map((s) => s.keyword)])));
	const [dirty, setDirty] = useState(false);
	useEffect(() => { setCompetitors(project.competitors.map((c) => c.domain)); }, [project.competitors]);

	const save = async () => {
		try {
			const bySource = Object.fromEntries(project.competitors.map((c) => [c.domain, c.source]));
			const c = await api('/projects/' + project.id + '/competitors', { method: 'PUT', body: competitors.map((d) => ({ domain: d, source: bySource[d] || 'seo' })) });
			const s = await api('/projects/' + project.id + '/seed-keywords', { method: 'PUT', body: Object.entries(seeds).flatMap(([kind, list]) => list.map((keyword) => ({ keyword, kind }))) });
			setProject((p) => ({ ...p, competitors: c, seed_keywords: s }));
			setDirty(false);
			toast('Mentve.');
		} catch (e) { toast(errorText(e), 'error'); }
	};
	const count = Object.values(seeds).reduce((n, l) => n + l.length, 0);
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="key" /> Kutatási input</h2>${dirty ? html`<button class="btn btn--sm" onClick=${save}>Mentés</button>` : ''}</header>
		<div class="pad stack-sm">
			<${Field} label=${'Versenytársak · ' + competitors.length + ' db'} hint="4–5 domain organikus pozíció alapján.">
				${editable ? html`<${Tags} value=${competitors} onChange=${(v) => { setCompetitors(v); setDirty(true); }} />` : html`<span>${competitors.join(', ') || '–'}</span>`}
			</${Field}>
			<div class="field"><span class="field__label">Kiinduló kulcsszavak · ${count} db ${count < 15 ? html`<span class="muted">(ajánlott 15–25)</span>` : ''}</span>
				${Object.entries(meta.seed_kinds).map(([k, label]) => html`<div key=${k} class="seed-row"><span class="seed-row__label">${label}</span>
					${editable ? html`<${Tags} value=${seeds[k]} onChange=${(v) => { setSeeds((x) => ({ ...x, [k]: v })); setDirty(true); }} />` : html`<span>${seeds[k].join(', ') || '–'}</span>`}
				</div>`)}
			</div>
		</div>
	</section>`;
}

function Team({ project, setProject }) {
	const { me, meta } = useApp();
	const [users] = useLoad(can(me, 'team.view') ? '/users' : null);
	const [adding, setAdding] = useState('');
	const members = project.members;
	const put = async (list) => {
		try {
			const r = await api('/projects/' + project.id + '/members', { method: 'PUT', body: list });
			setProject((p) => ({ ...p, members: r }));
		} catch (e) { toast(e.message, 'error'); }
	};
	const available = (users || []).filter((u) => u.is_active && !members.some((m) => m.user_id === u.id));
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="tasks" /> Csapat</h2></header>
		<ul class="list">${members.map((m) => html`<li key=${m.user_id} class="member"><${Avatar} name=${m.name} /><span><strong>${m.name}</strong><span class="muted">${meta.roles[m.role]}</span></span>
			${can(me, 'project.edit') ? html`<button class="icon-btn" aria-label="Eltávolítás" onClick=${() => put(members.filter((x) => x.user_id !== m.user_id).map(({ user_id, project_role }) => ({ user_id, project_role })))}><${Icon} name="x" /></button>` : ''}
		</li>`)}</ul>
		${can(me, 'project.edit') && available.length ? html`<div class="pad row-edit">
			<${Select} value=${adding} onChange=${setAdding} options=${available.map((u) => [u.id, u.display_name + ' – ' + meta.roles[u.role]])} placeholder="Tag hozzáadása…" />
			<button class="btn btn--sm" disabled=${!adding} onClick=${() => { put([...members.map(({ user_id, project_role }) => ({ user_id, project_role })), { user_id: Number(adding), project_role: '' }]); setAdding(''); }}>Hozzáad</button>
		</div>` : ''}
	</section>`;
}

function History({ project }) {
	const [data] = useLoad('/projects/' + project.id + '/history', [project.updated_at, project.status]);
	if (!data) return null;
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="refresh" /> Előzmények</h2></header>
		<ul class="timeline">
			${data.statuses.map((s) => html`<li key=${'s' + s.at}><span class="timeline__dot is-status"></span><div><strong>${s.to_label}</strong> <span class="muted">${s.user} · ${fmt.datetime(s.at)}</span>${s.note ? html`<p class="small">${s.note}</p>` : ''}</div></li>`)}
			${data.activity.filter((a) => a.action !== 'status').slice(0, 30).map((a, i) => html`<li key=${'a' + i}><span class="timeline__dot"></span><div>${a.summary || a.action} <span class="muted">${a.user} · ${fmt.datetime(a.at)}</span></div></li>`)}
		</ul>
	</section>`;
}

export function Overview({ project, setProject }) {
	return html`<div class="grid grid--overview">
		<div class="stack">
			<${Transitions} project=${project} setProject=${setProject} />
			<${BusinessProfile} project=${project} />
			<${Facts} project=${project} setProject=${setProject} />
			<${ResearchInput} project=${project} setProject=${setProject} />
		</div>
		<div class="stack">
			<${Intake} project=${project} setProject=${setProject} />
			<${Team} project=${project} setProject=${setProject} />
			<${History} project=${project} />
		</div>
	</div>`;
}
