import { html, useState, useEffect, api, useApp, navigate, setParam, Icon, Avatar, Spinner, Empty, shortDate, minutesLabel, isOverdue, PriorityFlag } from '../ui.js';

export function ProjectCard({ p }) {
	return html`
		<a class="project-card" href=${'#/projects/' + p.id} style=${{ '--pc': p.color }}>
			<span class="project-card__bar"></span>
			<span class="project-card__top">
				<strong>${p.name}</strong>
				${p.overdue ? html`<span class="pill pill--overdue">${p.overdue} lejárt</span>` : null}
			</span>
			<small class="muted">${p.client || (p.is_template ? 'Sablon' : 'Belső projekt')}</small>
			<span class="progress"><span style=${{ width: p.progress + '%' }}></span></span>
			<span class="project-card__meta">
				<small>${p.done}/${p.tasks} kész${p.due_date ? ' · határidő ' + shortDate(p.due_date) : ''}</small>
				<span class="stack">${(p.people || []).map((u) => html`<${Avatar} key=${u.id} user=${u} size="22" />`)}</span>
			</span>
		</a>`;
}

export function TaskRow({ t, showProject = true, onToggle }) {
	return html`
		<div class=${'task-row' + (t.status === 'done' ? ' is-done' : '')} onClick=${() => setParam('task', t.id)}>
			<button class="check" aria-label="Kész" onClick=${(e) => { e.stopPropagation(); onToggle && onToggle(t); }}><${Icon} name="check" size="14" /></button>
			<span class="task-row__title">${t.title}</span>
			<${PriorityFlag} priority=${t.priority} />
			${showProject && t.project ? html`<span class="chip" style=${{ '--pc': t.project.color }}>${t.project.name}</span>` : null}
			${t.due_date ? html`<span class=${'due' + (isOverdue(t) ? ' is-late' : '')}>${shortDate(t.due_date)}</span>` : null}
			<${Avatar} user=${t.assignee} size="24" />
		</div>`;
}

export function Dashboard() {
	const { boot } = useApp();
	const [d, setD] = useState(null);
	const load = () => api('/pm/dashboard').then(setD).catch(() => {});
	useEffect(() => {
		load();
		const on = () => load();
		window.addEventListener('hpv:changed', on);
		return () => window.removeEventListener('hpv:changed', on);
	}, []);
	if (!d) return html`<${Spinner} />`;

	const hour = new Date().getHours();
	const hello = hour < 10 ? 'Jó reggelt' : hour < 18 ? 'Szia' : 'Jó estét';
	const complete = (t) => api('/pm/tasks/' + t.id, { method: 'POST', body: { status: 'done' } }).then(load);

	const kpis = [
		{ label: 'Nyitott feladataim', value: d.my_open, href: '#/my' },
		{ label: 'Lejárt feladataim', value: d.my_overdue, href: '#/my', alert: d.my_overdue > 0 },
		{ label: 'Csapat: kész 7 nap alatt', value: d.done_week },
		{ label: 'Rögzített idő (7 nap)', value: minutesLabel(d.my_minutes), sub: 'csapat: ' + minutesLabel(d.team_minutes) },
	];
	const money_kpis = [];
	if (d.money) {
		money_kpis.push({ label: 'Havi ismétlődő bevétel', value: d.money.mrr });
		money_kpis.push({ label: 'Kintlévőség', value: d.money.outstanding, sub: d.money.overdue_invoices ? d.money.overdue_invoices + ' lejárt számla' : 'nincs lejárt', alert: d.money.overdue_invoices > 0 });
	}
	money_kpis.push({ label: 'Aktív ügyfelek', value: d.active_clients });
	if (d.contracts_waiting !== null && d.contracts_waiting !== undefined) money_kpis.push({ label: 'Aláírásra vár', value: d.contracts_waiting });
	const Kpi = (k) => html`<a class=${'kpi' + (k.alert ? ' is-alert' : '')} href=${k.href || null}><span>${k.label}</span><strong>${k.value}</strong>${k.sub ? html`<small>${k.sub}</small>` : null}</a>`;

	return html`
		<div class="page">
			<header class="page__head">
				<div><h1>${hello}, ${boot.me.name.split(' ')[0]}!</h1><p class="muted">${new Date().toLocaleDateString('hu-HU', { weekday: 'long', month: 'long', day: 'numeric' })}</p></div>
			</header>
			<div class="kpis">${kpis.map(Kpi)}</div>
			<div class="kpis kpis--money">${money_kpis.map(Kpi)}</div>

			<div class="grid-2">
				<section class="card">
					<header class="card__head"><h2>Ma esedékes és lejárt</h2><a class="link" href="#/my">Összes saját feladat</a></header>
					${d.my_today.length
						? d.my_today.map((t) => html`<${TaskRow} key=${t.id} t=${t} showProject=${false} onToggle=${complete} />`)
						: html`<${Empty} title="Mára nincs lejárt vagy esedékes feladatod" />`}
				</section>
				<section class="card">
					<header class="card__head"><h2>Aktív projektek</h2><a class="link" href="#/projects">Összes projekt</a></header>
					${d.projects.length ? html`<div class="project-list">${d.projects.slice(0, 6).map((p) => html`<${ProjectCard} key=${p.id} p=${p} />`)}</div>` : html`<${Empty} icon="folder" title="Nincs aktív projekt" />`}
				</section>
			</div>
		</div>`;
}
