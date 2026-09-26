/**
 * Saját munkáim: a hozzám rendelt gyártási feladatok minden projektből, és a szerepkörömhöz tartozó, még gazdátlan
 * feladatok (ezeket magamra vehetem).
 */
import { html, useApp, api, useLoad, toast, errorText, Spinner, ErrorBox, Empty } from '../ui.js';
import { TaskTable } from './tabs/documents.js';

export function MyWork() {
	const { me } = useApp();
	const [data, loading, error, reload, setData] = useLoad('/me/tasks');
	if (loading && !data) return html`<div class="page"><${Spinner} /></div>`;
	if (error) return html`<div class="page"><${ErrorBox} error=${error} onRetry=${reload} /></div>`;
	const patch = async (t, body) => {
		try {
			const n = await api('/tasks/' + t.id, { method: 'PATCH', body });
			setData((d) => ({ ...d, mine: d.mine.map((x) => (x.id === n.id ? { ...n, project: x.project } : x)), open: d.open.map((x) => (x.id === n.id ? { ...n, project: x.project } : x)) }));
		} catch (e) { toast(errorText(e), 'error'); }
	};
	return html`<div class="page">
		<header class="page__head"><div><h1>Saját munkáim</h1><p class="muted">${data.mine.length} nyitott feladat · ${me.role_label}</p></div></header>
		<div class="stack">
			<section class="card"><header class="card__head"><h2>Hozzám rendelve</h2></header>
				${data.mine.length ? html`<${TaskTable} tasks=${data.mine} users=${[]} onPatch=${patch} showProject />` : html`<${Empty} icon="tasks" title="Nincs nyitott feladatod" />`}
			</section>
			${data.open.length ? html`<section class="card"><header class="card__head"><h2>Felelős nélküli feladatok a szerepkörödben</h2></header>
				<${TaskTable} tasks=${data.open} users=${[]} onPatch=${patch} showProject />
			</section>` : ''}
		</div>
	</div>`;
}
