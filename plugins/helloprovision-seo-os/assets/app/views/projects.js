/**
 * Projektek: tábla és státusz szerinti tábla-nézet, szűrőkkel.
 */
import { html, useState, useApp, useLoad, navigate, setParam, fmt, Icon, Spinner, ErrorBox, Empty, StatusPill, DataTable, Select, Pill, can } from '../ui.js';

export function Projects() {
	const { me, meta, route } = useApp();
	const p = route.params;
	const view = p.view || 'table';
	const query = new URLSearchParams(Object.entries({ status: p.status, q: p.q, archived: p.archived }).filter(([, v]) => v)).toString();
	const [rows, loading, error, reload] = useLoad('/projects' + (query ? '?' + query : ''));
	const [search, setSearch] = useState(p.q || '');

	const columns = [
		{ key: 'name', label: 'Projekt', render: (r) => html`<div class="cell-main"><strong>${r.name}</strong><span class="muted">${r.client.name}</span></div>` },
		{ key: 'domain', label: 'Domain' },
		{ key: 'status', label: 'Státusz', render: (r) => html`<${StatusPill} status=${r.status} meta=${meta} />${r.on_hold ? html` <${Pill} kind="warn">Szünetel</${Pill}>` : ''}`, sortValue: (r) => meta.status_order.indexOf(r.status) },
		{ key: 'scope', label: 'Terjedelem', sort: false, render: (r) => html`<span class="muted small">${r.scope.map((s) => meta.scopes[s]).join(', ') || '–'}</span>` },
		{ key: 'owner', label: 'Felelős', render: (r) => (r.owner ? r.owner.display_name : '–'), sortValue: (r) => (r.owner ? r.owner.display_name : '') },
		{ key: 'updated_at', label: 'Frissítve', render: (r) => fmt.ago(r.updated_at), num: true },
	];

	return html`<div class="page">
		<header class="page__head">
			<div><h1>Projektek</h1><p class="muted">${rows ? rows.length + ' projekt' : ''}</p></div>
			${can(me, 'project.create') ? html`<button class="btn" onClick=${() => navigate('/projects/new')}><${Icon} name="plus" /> Új projekt</button>` : ''}
		</header>
		<div class="toolbar">
			<form class="search" onSubmit=${(e) => { e.preventDefault(); setParam('q', search.trim()); }}>
				<${Icon} name="search" /><input value=${search} onInput=${(e) => setSearch(e.target.value)} placeholder="Keresés név vagy domain alapján…" />
			</form>
			<${Select} value=${p.status || ''} onChange=${(v) => setParam('status', v)} options=${meta.statuses} placeholder="Minden státusz" />
			<${Select} value=${p.archived || ''} onChange=${(v) => setParam('archived', v)} options=${{ true: 'Archivált' }} placeholder="Aktív" />
			<div class="segmented">
				<button class=${view === 'table' ? 'is-active' : ''} onClick=${() => setParam('view', '')}>Tábla</button>
				<button class=${view === 'board' ? 'is-active' : ''} onClick=${() => setParam('view', 'board')}>Státusz szerint</button>
			</div>
		</div>
		${error ? html`<${ErrorBox} error=${error} onRetry=${reload} />` : ''}
		${loading && !rows ? html`<${Spinner} />` : ''}
		${rows && !rows.length ? html`<${Empty} title="Nincs találat">Módosíts a szűrőkön, vagy hozz létre új projektet.</${Empty}>` : ''}
		${rows && rows.length && view === 'table' ? html`<div class="card"><${DataTable} columns=${columns} rows=${rows} onRowClick=${(r) => navigate('/projects/' + r.id)} /></div>` : ''}
		${rows && rows.length && view === 'board' ? html`<div class="board">
			${meta.status_order.map((s) => {
				const list = rows.filter((r) => r.status === s);
				return html`<section key=${s} class="board__col">
					<header><${StatusPill} status=${s} meta=${meta} /><span class="muted">${list.length}</span></header>
					${list.map((r) => html`<button key=${r.id} class="board__card" onClick=${() => navigate('/projects/' + r.id)}>
						<strong>${r.name}</strong><span class="muted">${r.client.name}</span><span class="muted small">${r.domain}</span>
					</button>`)}
				</section>`;
			})}
		</div>` : ''}
	</div>`;
}
