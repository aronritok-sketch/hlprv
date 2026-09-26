/**
 * Vezérlőpult: aktív projektek, rám váró jóváhagyások, friss dokumentumok, esedékes tartalmak, hiányzó bekérések.
 */
import { html, useApp, useLoad, navigate, fmt, Icon, Spinner, ErrorBox, Empty, StatusPill, Stepper, Progress, Stat, Pill, can } from '../ui.js';

function Card({ title, icon, children, count, action }) {
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name=${icon} /> ${title}${count ? html`<span class="count">${count}</span>` : ''}</h2>${action || ''}</header>
		${children}
	</section>`;
}

export function Dashboard() {
	const { me, meta } = useApp();
	const [data, loading, error, reload] = useLoad('/dashboard');

	if (loading && !data) return html`<div class="page"><${Spinner} /></div>`;
	if (error) return html`<div class="page"><${ErrorBox} error=${error} onRetry=${reload} /></div>`;

	const active = data.projects.filter((p) => !p.on_hold);
	const groups = [
		['Kutatás alatt', ['draft', 'researching', 'ai_analysis_complete']],
		['Ellenőrzés', ['seo_review', 'client_review']],
		['Gyártásban', ['approved', 'production']],
	];

	return html`<div class="page">
		<header class="page__head">
			<div><h1>Jó munkát, ${me.name.split(' ')[0]}!</h1><p class="muted">${data.projects.length} aktív projekt</p></div>
		</header>

		<div class="stats">
			${groups.map(([label, sts]) => html`<${Stat} key=${label} label=${label} value=${sts.reduce((n, s) => n + (data.counts[s] || 0), 0)} />`)}
			<${Stat} label="Rám vár" value=${(data.approvals || []).length + (data.my_tasks || []).length} hint="jóváhagyás és feladat" />
		</div>

		<div class="grid grid--dash">
			<${Card} title="Aktív projektek" icon="folder" count=${active.length}
				action=${html`<a class="link" href="#/projects">Összes</a>`}>
				${active.length ? html`<ul class="project-list">
					${active.map((p) => html`<li key=${p.id} class="project-row" onClick=${() => navigate('/projects/' + p.id)}>
						<div class="project-row__main">
							<strong>${p.name}</strong>
							<span class="muted">${p.client} · ${p.domain}</span>
						</div>
						<div class="project-row__status">
							<${StatusPill} status=${p.status} meta=${meta} />
							<${Progress} value=${p.progress} />
						</div>
						<div class="project-row__meta">
							${p.missing_intake ? html`<${Pill} kind="warn" title="Hiányzó bekérendő anyag">${p.missing_intake} hiányzik</${Pill}>` : ''}
							<span class="muted">${p.owner || ''}</span>
						</div>
					</li>`)}
				</ul>` : html`<${Empty} title="Még nincs aktív projekt" action=${can(me, 'project.create') ? html`<button class="btn" onClick=${() => navigate('/projects/new')}>Új projekt</button>` : ''} />`}
			</${Card}>

			<div class="stack">
				${data.approvals ? html`<${Card} title="Jóváhagyásra vár" icon="check" count=${data.approvals.length}>
					${data.approvals.length ? html`<ul class="list">${data.approvals.map((a) => html`<li key=${a.id}>
						<a href=${'#/projects/' + a.project_id + '?tab=' + (a.tab || 'overview')}><strong>${a.label}</strong><span class="muted">${a.project} · ${a.stage_label}</span></a>
					</li>`)}</ul>` : html`<p class="muted pad">Nincs függő jóváhagyás.</p>`}
				</${Card}>` : ''}

				${data.my_tasks ? html`<${Card} title="Saját feladataim" icon="tasks" count=${data.my_tasks.length} action=${html`<a class="link" href="#/work">Mind</a>`}>
					${data.my_tasks.length ? html`<ul class="list">${data.my_tasks.slice(0, 6).map((t) => html`<li key=${t.id}>
						<a href=${'#/projects/' + t.project_id + '?tab=documents&sub=tasks'}><span class=${'role-dot role-dot--' + t.role}></span><strong>${t.title}</strong><span class="muted">${t.project}</span></a>
					</li>`)}</ul>` : html`<p class="muted pad">Nincs nyitott feladatod.</p>`}
				</${Card}>` : ''}

				${data.roadmap_due ? html`<${Card} title="E havi tartalmak" icon="calendar" count=${data.roadmap_due.length}>
					${data.roadmap_due.length ? html`<ul class="list">${data.roadmap_due.map((r) => html`<li key=${r.id}>
						<a href=${'#/projects/' + r.project_id + '?tab=strategy'}><strong>${r.title}</strong><span class="muted">${r.project} · ${r.status_label}</span></a>
					</li>`)}</ul>` : html`<p class="muted pad">Ebben a hónapban nincs esedékes tartalom.</p>`}
				</${Card}>` : ''}

				${data.documents ? html`<${Card} title="Friss dokumentumok" icon="doc">
					${data.documents.length ? html`<ul class="list">${data.documents.map((d) => html`<li key=${d.id}>
						<a href=${'#/projects/' + d.project_id + '?tab=documents'}><strong>${d.title}</strong><span class="muted">${d.project} · ${fmt.ago(d.created_at)}</span></a>
					</li>`)}</ul>` : html`<p class="muted pad">Még nem készült dokumentum.</p>`}
				</${Card}>` : ''}

				${data.upsell.length ? html`<${Card} title="Upsell emlékeztető" icon="flag">
					<ul class="list">${data.upsell.map((u) => html`<li key=${u.project_id}>
						<a href=${'#/projects/' + u.project_id}><strong>${u.name}</strong><span class="muted">${fmt.date(u.date)} – az 5. hónap eleje: új 6 havi stratégia vagy frissítés</span></a>
					</li>`)}</ul>
				</${Card}>` : ''}

				<${Card} title="Hiányzó bekérendő anyagok" icon="bell" count=${data.missing_intake.length}>
					${data.missing_intake.length ? html`<ul class="list">${data.missing_intake.slice(0, 12).map((m) => html`<li key=${m.project_id + m.key}>
						<a href=${'#/projects/' + m.project_id}><strong>${m.label}</strong><span class="muted">${m.project} · ${meta.intake_statuses[m.status]}</span></a>
					</li>`)}</ul>` : html`<p class="muted pad">Minden bekérendő anyag megvan.</p>`}
				</${Card}>
			</div>
		</div>
	</div>`;
}
