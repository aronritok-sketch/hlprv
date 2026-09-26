/**
 * Exportok: a projekt minden letölthető anyaga egy helyen – adatexportok (mindig a friss adatokból) és a legutóbbi
 * dokumentumváltozatok fájljai.
 */
import { html, useApp, useLoad, download, fmt, Icon, Spinner, ErrorBox, Pill, can } from '../../ui.js';

export function Exports({ project }) {
	const { me } = useApp();
	const [data, loading, error, reload] = useLoad('/projects/' + project.id + '/documents');
	const live = [
		['keywords.view', 'Kulcsszókutatás', 'XLSX – a saját táblaformátumban, AI-elemzés oszlopaival', '/projects/' + project.id + '/export/keyword-research.xlsx', project.domain + ' kulcsszókutatás.xlsx'],
		['strategy.view', 'Tartalomstratégia munkafüzet', 'XLSX – 6 lap: összefoglaló, klaszterek, roadmap, mérési terv, PPC, következő témák', '/projects/' + project.id + '/exports/content-strategy.xlsx', project.domain + ' tartalomstratégia.xlsx'],
		['strategy.view', 'Stílusbeli irányelvek', 'DOCX – ügyfélnek kiküldhető, a domainre és a márkanévre szabva', '/projects/' + project.id + '/style-guide.docx', 'Stílusbeli irányelvek – ' + project.domain + '.docx'],
	].filter(([cap]) => can(me, cap));
	if (loading && !data) return html`<${Spinner} />`;
	if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
	const latest = data.documents.filter((d) => d.stale !== null);
	return html`<div class="grid grid--2">
		<section class="card"><header class="card__head"><h2><${Icon} name="download" /> Adatexportok</h2></header>
			<ul class="export-list">${live.map(([, title, hint, path, name]) => html`<li key=${path}>
				<div><strong>${title}</strong><span class="muted small">${hint}</span></div>
				<button class="btn btn--ghost btn--sm" onClick=${() => download(path, name)}><${Icon} name="download" size="14" /> Letöltés</button>
			</li>`)}</ul>
		</section>
		<section class="card"><header class="card__head"><h2><${Icon} name="doc" /> Dokumentumok – legutóbbi változat</h2><a class="link" href=${'#/projects/' + project.id + '?tab=documents'}>Kezelés</a></header>
			${latest.length ? html`<ul class="export-list">${latest.map((d) => html`<li key=${d.id}>
				<div><strong>${d.type_label}${d.language === 'en' ? ' (EN)' : ''}</strong>
					<span class="muted small">v${d.version} · ${d.status_label} · ${fmt.date(d.created_at)} ${d.stale ? html`<${Pill} kind="warn">Elavult</${Pill}>` : ''}</span></div>
				${d.files.map((f) => html`<button key=${f.format} class="btn btn--ghost btn--sm" onClick=${() => download('/documents/' + d.id + '/files/' + f.format, d.title + '.' + f.format)}>${f.format.toUpperCase()}</button>`)}
			</li>`)}</ul>` : html`<p class="muted pad">Még nem készült dokumentum.</p>`}
		</section>
	</div>`;
}
