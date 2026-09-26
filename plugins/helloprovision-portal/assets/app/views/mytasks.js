import { html, useState, useEffect, api, Spinner, Empty, todayISO, toast } from '../ui.js';
import { TaskRow } from './dashboard.js';

const GROUPS = [
	{ key: 'late', label: 'Lejárt', test: (d, t) => d && d < t.today },
	{ key: 'today', label: 'Ma', test: (d, t) => d === t.today },
	{ key: 'week', label: 'Következő 7 nap', test: (d, t) => d && d > t.today && d <= t.week },
	{ key: 'later', label: 'Később', test: (d, t) => d && d > t.week },
	{ key: 'none', label: 'Határidő nélkül', test: (d) => !d },
];

export function MyTasks() {
	const [tasks, setTasks] = useState(null);
	const load = () => api('/pm/my-tasks').then(setTasks).catch((e) => toast(e.message, 'error'));
	useEffect(() => {
		load();
		const on = () => load();
		window.addEventListener('hpv:changed', on);
		return () => window.removeEventListener('hpv:changed', on);
	}, []);
	if (!tasks) return html`<${Spinner} />`;

	const ctx = { today: todayISO(), week: todayISO(7) };
	const complete = (t) => {
		setTasks((list) => list.filter((x) => x.id !== t.id));
		api('/pm/tasks/' + t.id, { method: 'POST', body: { status: 'done' } }).then(() => toast('Kész: ' + t.title)).catch((e) => { toast(e.message, 'error'); load(); });
	};

	return html`
		<div class="page page--narrow">
			<header class="page__head"><div><h1>Saját feladataim</h1><p class="muted">${tasks.length} nyitott feladat</p></div></header>
			${!tasks.length ? html`<${Empty} title="Nincs nyitott feladatod">Szép munka! 🎉</${Empty}>` : null}
			${GROUPS.map((g) => {
				const list = tasks.filter((t) => g.test(t.due_date, ctx));
				if (!list.length) return null;
				return html`
					<section class=${'card group group--' + g.key} key=${g.key}>
						<header class="card__head"><h2>${g.label} <span class="muted">${list.length}</span></h2></header>
						${list.map((t) => html`<${TaskRow} key=${t.id} t=${t} onToggle=${complete} />`)}
					</section>`;
			})}
		</div>`;
}
