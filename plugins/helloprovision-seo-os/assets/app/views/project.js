/**
 * Projekt: fejléc (ügyfél, státusz, lépésjelző) + fülek. A fülek a views/tabs/ mappában vannak.
 */
import { html, useApp, useLoad, setParam, Spinner, ErrorBox, StatusPill, Stepper, Tabs, Pill, can } from '../ui.js';
import { Overview } from './tabs/overview.js';
import { Research } from './tabs/research.js';
import { Keywords } from './tabs/keywords.js';
import { Structure } from './tabs/structure.js';
import { Strategy } from './tabs/strategy.js';
import { Wireframes } from './tabs/wireframes.js';
import { Documents } from './tabs/documents.js';
import { Exports } from './tabs/exports.js';
import { Audit } from './tabs/audit.js';

const TABS = [
	['overview', 'Áttekintés', 'home', 'project.view', Overview],
	['research', 'Kutatás', 'upload', 'research.view', Research],
	['keywords', 'Kulcsszavak', 'key', 'keywords.view', Keywords],
	['structure', 'Struktúra', 'sitemap', 'structure.view', Structure],
	['strategy', 'Tartalomstratégia', 'calendar', 'strategy.view', Strategy],
	['wireframes', 'Wireframe-ek', 'layout', 'wireframes.view', Wireframes],
	['audit', 'Technikai audit', 'bug', 'audit.view', Audit],
	['documents', 'Dokumentumok', 'doc', 'documents.view', Documents],
	['exports', 'Exportok', 'download', 'documents.view', Exports],
];

export function ProjectPage({ id, tab, params }) {
	const { me, meta } = useApp();
	const [project, loading, error, reload, setProject] = useLoad('/projects/' + id);

	if (loading && !project) return html`<div class="page"><${Spinner} /></div>`;
	if (error) return html`<div class="page"><${ErrorBox} error=${error} onRetry=${reload} /></div>`;

	const tabs = TABS.filter(([, , , cap]) => can(me, cap));
	const current = tabs.find(([k]) => k === tab) || tabs[0];
	const Comp = current[4];

	return html`<div class="page page--project">
		<header class="project-head">
			<div class="project-head__main">
				<a class="crumb" href="#/projects">Projektek</a>
				<h1>${project.name}</h1>
				<p class="muted">${project.client.name} · <a class="link" href=${'https://' + project.domain} target="_blank" rel="noopener">${project.domain}</a>
					${project.locations.length ? ' · ' + project.locations.join(', ') : ''}</p>
			</div>
			<div class="project-head__status">
				<${StatusPill} status=${project.status} meta=${meta} />
				${project.on_hold ? html`<${Pill} kind="warn">Szünetel</${Pill}>` : ''}
				${project.archived_at ? html`<${Pill}>Archivált</${Pill}>` : ''}
			</div>
		</header>
		<${Stepper} status=${project.status} meta=${meta} />
		<${Tabs} tabs=${tabs.map(([k, l, i]) => [k, l, i])} active=${current[0]} onChange=${(k) => setParam('tab', k === 'overview' ? '' : k)} />
		<div class="tab-body">
			<${Comp} project=${project} reload=${reload} setProject=${setProject} params=${params} />
		</div>
	</div>`;
}
