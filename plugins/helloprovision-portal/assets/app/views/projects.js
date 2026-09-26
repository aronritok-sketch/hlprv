import { html, useState, useEffect, api, useApp, navigate, Icon, Modal, Spinner, Empty, todayISO, toast } from '../ui.js';
import { ProjectCard } from './dashboard.js';

export const COLORS = ['#b8ff34', '#d29dff', '#7dd3fc', '#fdba74', '#fca5a5', '#86efac', '#fde047', '#a5b4fc', '#121212'];

export function NewProjectModal({ onClose, clientId, asTemplate }) {
	const { boot } = useApp();
	const [templates, setTemplates] = useState([]);
	const [f, setF] = useState({ name: '', client_id: clientId || '', template_id: '', start_date: todayISO(), due_date: '', owner_id: boot.me.id, color: COLORS[0], visible: true, is_template: !!asTemplate });
	const [busy, setBusy] = useState(false);
	const set = (k) => (e) => setF({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

	useEffect(() => { api('/pm/projects?templates=1').then(setTemplates).catch(() => {}); }, []);

	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/pm/projects', { method: 'POST', body: { ...f, visible: f.visible ? 1 : 0, is_template: f.is_template ? 1 : 0 } })
			.then((p) => { onClose(); toast('Projekt létrehozva.'); navigate('/projects/' + p.id); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};

	return html`
		<${Modal} title=${asTemplate ? 'Új projektsablon' : 'Új projekt'} onClose=${onClose}>
			<form class="form" onSubmit=${submit}>
				<label class="field"><span>Név</span><input required value=${f.name} onInput=${set('name')} placeholder="pl. Weboldal újratervezés" autoFocus /></label>
				${!asTemplate ? html`
					<div class="row">
						<label class="field"><span>Ügyfél</span>
							<select value=${f.client_id} onChange=${set('client_id')}><option value="">— belső projekt —</option>${boot.clients.map((c) => html`<option value=${c.id}>${c.name}</option>`)}</select>
						</label>
						<label class="field"><span>Sablonból</span>
							<select value=${f.template_id} onChange=${set('template_id')}><option value="">— üres projekt —</option>${templates.map((t) => html`<option value=${t.id}>${t.name} (${t.tasks} feladat)</option>`)}</select>
						</label>
					</div>` : null}
				<div class="row">
					<label class="field"><span>Kezdés</span><input type="date" value=${f.start_date} onInput=${set('start_date')} /></label>
					<label class="field"><span>Határidő</span><input type="date" value=${f.due_date} onInput=${set('due_date')} /></label>
					<label class="field"><span>Felelős</span>
						<select value=${f.owner_id} onChange=${set('owner_id')}>${boot.users.map((u) => html`<option value=${u.id}>${u.name}</option>`)}</select>
					</label>
				</div>
				<div class="field"><span>Szín</span>
					<div class="swatches">${COLORS.map((c) => html`<button type="button" key=${c} class=${'swatch' + (f.color === c ? ' is-active' : '')} style=${{ background: c }} onClick=${() => setF({ ...f, color: c })} aria-label=${c}></button>`)}</div>
				</div>
				${!asTemplate && f.client_id ? html`<label class="toggle"><input type="checkbox" checked=${f.visible} onChange=${set('visible')} /> <span>Az ügyfél látja a portálon</span></label>` : null}
				${f.template_id ? html`<p class="hint">A sablon feladatai átmásolódnak, a dátumok a kezdőnaphoz igazodnak.</p>` : null}
				<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>Létrehozás</button></footer>
			</form>
		</${Modal}>`;
}

export function Projects({ params }) {
	const { boot } = useApp();
	const [list, setList] = useState(null);
	const [q, setQ] = useState('');
	const [status, setStatus] = useState('active');
	const [modal, setModal] = useState(false);
	const templates = params.view === 'templates';
	const client = params.client || '';

	useEffect(() => {
		setList(null);
		api('/pm/projects' + (templates ? '?templates=1' : client ? '?client_id=' + client : '')).then(setList).catch((e) => toast(e.message, 'error'));
	}, [templates, client]);

	const activeStatuses = ['planning', 'in_progress', 'review'];
	const shown = (list || []).filter((p) => {
		if (q && !(p.name + ' ' + p.client).toLowerCase().includes(q.toLowerCase())) return false;
		if (templates || status === 'all') return true;
		if (status === 'active') return activeStatuses.includes(p.status);
		return p.status === status;
	});
	const clientName = client ? (boot.clients.find((c) => String(c.id) === String(client)) || {}).name : '';

	return html`
		<div class="page">
			<header class="page__head">
				<div>
					<h1>${templates ? 'Projektsablonok' : clientName ? 'Projektek: ' + clientName : 'Projektek'}</h1>
					<p class="muted">${templates ? 'Újrahasznosítható feladatlisták (pl. weboldal-projekt, havi SEO)' : shown.length + ' projekt'}</p>
				</div>
				<div class="actions">
					<a class="btn btn--ghost" href=${templates ? '#/projects' : '#/projects?view=templates'}>${templates ? 'Projektek' : 'Sablonok'}</a>
					<button class="btn" onClick=${() => setModal(true)}><${Icon} name="plus" /> ${templates ? 'Új sablon' : 'Új projekt'}</button>
				</div>
			</header>
			<div class="toolbar">
				<input class="search-input" placeholder="Keresés projekt vagy ügyfél szerint…" value=${q} onInput=${(e) => setQ(e.target.value)} />
				${!templates ? html`
					<div class="seg">
						${[['active', 'Aktív'], ['completed', 'Kész'], ['on_hold', 'Felfüggesztve'], ['all', 'Mind']].map(([k, l]) => html`<button key=${k} class=${status === k ? 'is-active' : ''} onClick=${() => setStatus(k)}>${l}</button>`)}
					</div>
					${client ? html`<a class="link" href="#/projects">× Ügyfélszűrő törlése</a>` : null}` : null}
			</div>
			${!list ? html`<${Spinner} />` : shown.length ? html`<div class="project-grid">${shown.map((p) => html`<${ProjectCard} key=${p.id} p=${p} />`)}</div>`
				: html`<${Empty} icon="folder" title=${templates ? 'Még nincs sablon' : 'Nincs ilyen projekt'}>${templates ? 'Egy sablon a visszatérő projektek feladatlistája — pl. „Weboldal projekt” 25 lépéssel.' : null}</${Empty}>`}
			${modal ? html`<${NewProjectModal} onClose=${() => setModal(false)} clientId=${client} asTemplate=${templates} />` : null}
		</div>`;
}
