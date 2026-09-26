/**
 * HelloProVision SEO OS – webalkalmazás (seo.helloprovision.com).
 * Keret: oldalsáv, felső sáv, útválasztás. A nézetek a views/ mappában vannak.
 */
import { html, render, useState, useEffect, api, CFG, AppContext, useRoute, navigate, Icon, Avatar, Toasts, Spinner, ErrorBox, can } from './ui.js';
import { Dashboard } from './views/dashboard.js';
import { Projects } from './views/projects.js';
import { ProjectNew } from './views/project-new.js';
import { ProjectPage } from './views/project.js';
import { MyWork } from './views/mywork.js';
import { Settings } from './views/settings.js';

const NAV = [
	['/', 'Vezérlőpult', 'home', null],
	['/projects', 'Projektek', 'folder', 'project.view'],
	['/work', 'Saját munkáim', 'tasks', 'tasks.view'],
	['/settings', 'Beállítások', 'settings', 'team.view'],
];

function Sidebar({ me, path, open, onClose }) {
	const active = (p) => (p === '/' ? path === '/' : path === p || path.startsWith(p + '/'));
	return html`
		<aside class=${'sidebar' + (open ? ' is-open' : '')}>
			<a class="brand" href="#/" onClick=${onClose}>
				<span class="brand__mark" aria-hidden="true"><i></i><i></i></span>
				<span class="brand__text">HelloProVision<strong>SEO OS</strong></span>
			</a>
			<nav class="nav">
				${NAV.filter(([, , , cap]) => !cap || can(me, cap)).map(([p, label, icon]) => html`
					<a key=${p} href=${'#' + p} class=${'nav__item' + (active(p) ? ' is-active' : '')} onClick=${onClose}>
						<${Icon} name=${icon} /><span>${label}</span>
					</a>`)}
			</nav>
			<div class="sidebar__foot">
				${CFG.crmUrl ? html`<a class="nav__item nav__item--muted" href=${CFG.crmUrl}><${Icon} name="ext" /><span>CRM</span></a>` : ''}
				<div class="me">
					<${Avatar} name=${me.name} size="30" />
					<div class="me__text"><strong>${me.name}</strong><span>${me.role_label}</span></div>
					<a class="icon-btn" href=${CFG.logoutUrl} title="Kijelentkezés" aria-label="Kijelentkezés"><${Icon} name="logout" /></a>
				</div>
			</div>
		</aside>`;
}

function Router({ route }) {
	const { path } = route;
	if (path === '/' || path === '') return html`<${Dashboard} />`;
	if (path === '/projects') return html`<${Projects} />`;
	if (path === '/projects/new') return html`<${ProjectNew} />`;
	const m = path.match(/^\/projects\/(\d+)$/);
	if (m) return html`<${ProjectPage} id=${Number(m[1])} tab=${route.params.tab || 'overview'} params=${route.params} />`;
	if (path === '/work') return html`<${MyWork} />`;
	if (path === '/settings') return html`<${Settings} />`;
	return html`<div class="page"><h1>Nincs ilyen oldal</h1><p><a class="link" href="#/">Vissza a vezérlőpultra</a></p></div>`;
}

function App() {
	const route = useRoute();
	const [boot, setBoot] = useState(null);
	const [error, setError] = useState(null);
	const [menu, setMenu] = useState(false);

	const load = () => {
		setError(null);
		Promise.all([api('/me'), api('/meta')])
			.then(([me, meta]) => setBoot({ me, meta }))
			.catch(setError);
	};
	useEffect(load, []);
	useEffect(() => { window.scrollTo(0, 0); }, [route.path]);

	if (error) return html`<div class="boot"><${ErrorBox} error=${error} onRetry=${load} /></div>`;
	if (!boot) return html`<div class="boot"><${Spinner} label="SEO OS betöltése…" /></div>`;

	return html`
		<${AppContext.Provider} value=${{ ...boot, route }}>
			<div class="shell">
				<${Sidebar} me=${boot.me} path=${route.path} open=${menu} onClose=${() => setMenu(false)} />
				${menu ? html`<div class="scrim" onClick=${() => setMenu(false)}></div>` : ''}
				<main class="main">
					<header class="topbar">
						<button class="icon-btn topbar__menu" onClick=${() => setMenu(true)} aria-label="Menü"><${Icon} name="menu" /></button>
						<div class="topbar__title">SEO OS</div>
						${can(boot.me, 'project.create') ? html`<button class="btn btn--sm" onClick=${() => navigate('/projects/new')}><${Icon} name="plus" /> Új projekt</button>` : ''}
					</header>
					<${Router} route=${route} />
				</main>
			</div>
			<${Toasts} />
		</${AppContext.Provider}>`;
}

render(html`<${App} />`, document.getElementById('app'));
