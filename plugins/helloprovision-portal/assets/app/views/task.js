import { html, useState, useEffect, useRef, api, useApp, setParam, Icon, Avatar, Spinner, InlineText, minutesLabel, timeAgo, todayISO, clock, toast } from '../ui.js';

const changed = () => window.dispatchEvent(new CustomEvent('hpv:changed'));

function Section({ title, count, children, action }) {
	return html`<section class="drawer__section"><header><h3>${title}${count !== undefined ? html` <span class="muted">${count}</span>` : null}</h3>${action || null}</header>${children}</section>`;
}

function AddInput({ placeholder, onAdd }) {
	return html`<input class="add-input" placeholder=${placeholder} onKeyDown=${(e) => { if (e.key === 'Enter' && e.target.value.trim()) { onAdd(e.target.value.trim()); e.target.value = ''; } }} />`;
}

export function TaskDrawer({ id, onClose }) {
	const { boot, timer, setTimer } = useApp();
	const [t, setT] = useState(null);
	const [projectTasks, setProjectTasks] = useState([]);
	const [now, setNow] = useState(Math.floor(Date.now() / 1000));
	const [manual, setManual] = useState(false);
	const commentRef = useRef(null);

	const load = () => api('/pm/tasks/' + id).then((d) => { setT(d); return d; }).catch((e) => { toast(e.message, 'error'); onClose(); });

	useEffect(() => {
		load().then((d) => d && api('/pm/projects/' + d.project_id).then((p) => setProjectTasks(p.tasks)).catch(() => {}));
		const onKey = (e) => e.key === 'Escape' && !document.querySelector('.modal') && onClose();
		document.addEventListener('keydown', onKey);
		return () => document.removeEventListener('keydown', onKey);
	}, [id]);

	const running = timer && timer.task_id === id;
	useEffect(() => {
		if (!running) return undefined;
		const i = setInterval(() => setNow(Math.floor(Date.now() / 1000)), 1000);
		return () => clearInterval(i);
	}, [running]);

	if (!t) return html`<div class="drawer-wrap" onMouseDown=${(e) => e.target === e.currentTarget && onClose()}><aside class="drawer"><${Spinner} /></aside></div>`;

	const save = (body) => api('/pm/tasks/' + t.id, { method: 'POST', body }).then(() => { load(); changed(); }).catch((e) => toast(e.message, 'error'));
	const toggleTimer = () => api('/pm/tasks/' + t.id + '/timer', { method: 'POST', body: { action: running ? 'stop' : 'start' } }).then((r) => { setTimer(r); load(); changed(); if (!r) toast('Idő rögzítve.'); });
	const addSub = (title) => api('/pm/tasks', { method: 'POST', body: { project_id: t.project_id, parent_id: t.id, title, status: 'todo' } }).then(() => { load(); changed(); });
	const toggleSub = (s) => api('/pm/tasks/' + s.id, { method: 'POST', body: { status: s.status === 'done' ? 'todo' : 'done' } }).then(() => { load(); changed(); });
	const addCheck = (title) => api('/pm/tasks/' + t.id + '/checklist', { method: 'POST', body: { title } }).then(() => { load(); changed(); });
	const toggleCheck = (c) => {
		setT({ ...t, checklist: t.checklist.map((x) => (x.id === c.id ? { ...x, done: !x.done } : x)) });
		api('/pm/checklist/' + c.id, { method: 'POST', body: { done: c.done ? 0 : 1 } }).then(changed);
	};
	const delCheck = (c) => api('/pm/checklist/' + c.id, { method: 'DELETE' }).then(() => { load(); changed(); });
	const addLink = (depId) => api('/pm/tasks/' + t.id + '/links', { method: 'POST', body: { depends_on: depId } }).then(() => { load(); changed(); }).catch((e) => toast(e.message, 'error'));
	const delLink = (l) => api('/pm/links/' + l.id, { method: 'DELETE' }).then(() => { load(); changed(); });
	const delTime = (e) => api('/pm/time/' + e.id, { method: 'DELETE' }).then(() => { load(); changed(); }).catch((err) => toast(err.message, 'error'));
	const comment = () => {
		const body = commentRef.current.value.trim();
		if (!body) return;
		api('/pm/tasks/' + t.id + '/comments', { method: 'POST', body: { body } }).then(() => { commentRef.current.value = ''; load(); changed(); }).catch((e) => toast(e.message, 'error'));
	};
	const del = () => {
		if (!window.confirm('Biztosan törlöd a feladatot' + (t.subtasks.length ? ' és az alfeladatait' : '') + '?')) return;
		api('/pm/tasks/' + t.id, { method: 'DELETE' }).then(() => { onClose(); changed(); toast('Feladat törölve.'); });
	};
	const logManual = (e) => {
		e.preventDefault();
		const f = e.target;
		const h = parseFloat(f.elements.namedItem('hours').value.replace(',', '.')) || 0;
		const minutes = Math.round(h * 60);
		api('/pm/tasks/' + t.id + '/time', { method: 'POST', body: { minutes, date: f.elements.namedItem('date').value, note: f.elements.namedItem('note').value } }).then(() => { setManual(false); load(); changed(); toast('Idő rögzítve.'); }).catch((err) => toast(err.message, 'error'));
	};

	const linkable = projectTasks.filter((x) => x.id !== t.id && !t.links.some((l) => l.depends_on === x.id) && x.parent_id !== t.id);
	const checkDone = t.checklist.filter((c) => c.done).length;
	const logged = t.stats.minutes + (running ? Math.floor((now - timer.started_at) / 60) : 0);

	return html`
		<div class="drawer-wrap" onMouseDown=${(e) => e.target === e.currentTarget && onClose()}>
			<aside class="drawer" role="dialog" aria-modal="true" aria-label=${t.title}>
				<header class="drawer__top">
					<div class="drawer__crumbs">
						<a href=${'#/projects/' + t.project_id} style=${{ '--pc': t.project.color }} class="chip">${t.project.name}</a>
						${t.parent ? html`<span class="muted">›</span><a href="#" onClick=${(e) => { e.preventDefault(); setParam('task', t.parent.id); }}>${t.parent.title}</a>` : null}
					</div>
					<button class=${'btn btn--small' + (t.status === 'done' ? ' btn--done' : ' btn--ghost')} onClick=${() => save({ status: t.status === 'done' ? 'todo' : 'done' })}><${Icon} name="check" size="15" /> ${t.status === 'done' ? 'Kész' : 'Késznek jelölés'}</button>
					<button class="icon-btn" onClick=${onClose} aria-label="Bezárás"><${Icon} name="x" /></button>
				</header>

				<div class="drawer__body">
					<${InlineText} className="drawer__title" value=${t.title} onSave=${(v) => v.trim() && save({ title: v })} multiline=${false} />

					<div class="props">
						<label><span>Státusz</span><select value=${t.status} onChange=${(e) => save({ status: e.target.value })}>${boot.statuses.map((s) => html`<option value=${s.key}>${s.label}</option>`)}</select></label>
						<label><span>Felelős</span><select value=${t.assignee ? t.assignee.id : 0} onChange=${(e) => save({ assignee_id: e.target.value })}><option value="0">—</option>${boot.users.map((u) => html`<option value=${u.id}>${u.name}</option>`)}</select></label>
						<label><span>Prioritás</span><select value=${t.priority} onChange=${(e) => save({ priority: e.target.value })}>${boot.priorities.map((p) => html`<option value=${p.key}>${p.label}</option>`)}</select></label>
						<label><span>Kezdés</span><input type="date" value=${t.start_date || ''} onChange=${(e) => save({ start_date: e.target.value })} /></label>
						<label><span>Határidő</span><input type="date" value=${t.due_date || ''} onChange=${(e) => save({ due_date: e.target.value })} /></label>
						<label><span>Becslés (óra)</span><input type="number" min="0" step="0.25" value=${t.estimate ? t.estimate / 60 : ''} onChange=${(e) => save({ estimate: Math.round((parseFloat(e.target.value) || 0) * 60) })} /></label>
						${t.project.client_id ? html`<label class="props__toggle"><input type="checkbox" checked=${t.visible} onChange=${(e) => save({ visible: e.target.checked ? 1 : 0 })} /><span>Az ügyfél látja a portálon</span></label>` : null}
					</div>

					<div class="timebox">
						<button class=${'btn ' + (running ? 'btn--stop' : '')} onClick=${toggleTimer}><${Icon} name=${running ? 'stop' : 'play'} size="15" /> ${running ? 'Stop ' + clock(Math.max(0, now - timer.started_at)) : 'Stopper indítása'}</button>
						<span><strong>${minutesLabel(logged)}</strong>${t.estimate ? html` <span class="muted">/ ${minutesLabel(t.estimate)} becsült</span>` : null}</span>
						<button class="link" onClick=${() => setManual(!manual)}>+ Idő kézzel</button>
					</div>
					${manual ? html`
						<form class="manual-time" onSubmit=${logManual}>
							<input name="hours" placeholder="Óra (pl. 1,5)" required autoFocus />
							<input name="date" type="date" value=${todayISO()} />
							<input name="note" placeholder="Megjegyzés" />
							<button class="btn btn--small">Mentés</button>
						</form>` : null}

					<${Section} title="Leírás">
						<${InlineText} multiline className="desc" value=${t.description} placeholder="Részletek, linkek, elvárások… (belső, az ügyfél nem látja)" onSave=${(v) => save({ description: v })} />
					</${Section}>

					${!t.parent_id ? html`
						<${Section} title="Alfeladatok" count=${t.subtasks.length ? `${t.subtasks.filter((s) => s.status === 'done').length}/${t.subtasks.length}` : undefined}>
							<ul class="checklist">
								${t.subtasks.map((s) => html`
									<li key=${s.id} class=${s.status === 'done' ? 'is-done' : ''}>
										<button class="check" onClick=${() => toggleSub(s)} aria-label="Kész"><${Icon} name="check" size="13" /></button>
										<a href="#" onClick=${(e) => { e.preventDefault(); setParam('task', s.id); }}>${s.title}</a>
										<${Avatar} user=${s.assignee} size="20" />
									</li>`)}
							</ul>
							<${AddInput} placeholder="+ Alfeladat, Enter" onAdd=${addSub} />
						</${Section}>` : null}

					<${Section} title="Ellenőrzőlista" count=${t.checklist.length ? `${checkDone}/${t.checklist.length}` : undefined}>
						${t.checklist.length ? html`<span class="progress progress--thin"><span style=${{ width: (100 * checkDone) / t.checklist.length + '%' }}></span></span>` : null}
						<ul class="checklist">
							${t.checklist.map((c) => html`
								<li key=${c.id} class=${c.done ? 'is-done' : ''}>
									<button class="check" onClick=${() => toggleCheck(c)} aria-label="Kész"><${Icon} name="check" size="13" /></button>
									<span>${c.title}</span>
									<button class="icon-btn icon-btn--tiny" onClick=${() => delCheck(c)} aria-label="Törlés"><${Icon} name="x" size="13" /></button>
								</li>`)}
						</ul>
						<${AddInput} placeholder="+ Pont hozzáadása, Enter" onAdd=${addCheck} />
					</${Section}>

					<${Section} title="Függőségek">
						${t.links.length ? html`<ul class="links">${t.links.map((l) => html`
							<li key=${l.id}><${Icon} name=${l.done ? 'check' : 'block'} size="14" /><span>Erre vár: <a href="#" onClick=${(e) => { e.preventDefault(); setParam('task', l.depends_on); }}>${l.title}</a></span>
								<button class="icon-btn icon-btn--tiny" onClick=${() => delLink(l)} aria-label="Törlés"><${Icon} name="x" size="13" /></button></li>`)}</ul>` : null}
						${linkable.length ? html`<select class="add-input" value="" onChange=${(e) => e.target.value && addLink(Number(e.target.value))}><option value="">+ Ettől a feladattól függ…</option>${linkable.map((x) => html`<option value=${x.id}>${x.title}</option>`)}</select>` : null}
					</${Section}>

					${t.time.length ? html`
						<${Section} title="Időnapló" count=${minutesLabel(t.stats.minutes)}>
							<ul class="timelog">${t.time.map((e) => html`
								<li key=${e.id}><${Avatar} user=${e.user} size="20" /><span>${e.running ? html`<em>fut…</em>` : minutesLabel(e.minutes)}</span><span class="muted">${e.date}${e.note ? ' · ' + e.note : ''}</span>
									${e.mine && !e.running ? html`<button class="icon-btn icon-btn--tiny" onClick=${() => delTime(e)} aria-label="Törlés"><${Icon} name="x" size="13" /></button>` : null}</li>`)}</ul>
						</${Section}>` : null}

					<${Section} title="Hozzászólások" count=${t.comments.length || undefined}>
						<ul class="comments">
							${t.comments.map((c) => html`
								<li key=${c.id}><${Avatar} user=${c.user} size="28" /><div><header><strong>${c.user.name}</strong><span class="muted">${timeAgo(c.time)}</span></header><p>${c.body}</p></div></li>`)}
						</ul>
						<div class="comment-box">
							<textarea ref=${commentRef} rows="2" placeholder="Hozzászólás a csapatnak… (Ctrl+Enter)" onKeyDown=${(e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) comment(); }}></textarea>
							<button class="btn btn--small" onClick=${comment}>Küldés</button>
						</div>
					</${Section}>

					<footer class="drawer__foot">
						<span class="muted">Létrehozta ${t.created_by ? t.created_by.name : '—'}, ${timeAgo(t.created_at)}</span>
						<button class="btn btn--danger btn--small" onClick=${del}><${Icon} name="trash" size="14" /> Törlés</button>
					</footer>
				</div>
			</aside>
		</div>`;
}
