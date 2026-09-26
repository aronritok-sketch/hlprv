import { html, useState, useEffect, useRef, useMemo, api, useApp, navigate, setParam, CFG, Icon, Avatar, PriorityFlag, Spinner, Empty, Modal, InlineText, shortDate, minutesLabel, isOverdue, todayISO, addDays, daysBetween, parseISO, MONTHS_LONG, toast } from '../ui.js';
import { COLORS } from './projects.js';
import { NewCallModal } from './calls.js';
import { FilesPanel } from './files.js';

const VIEWS = [
	{ key: 'board', label: 'Tábla', icon: 'board' },
	{ key: 'list', label: 'Lista', icon: 'list' },
	{ key: 'timeline', label: 'Idővonal', icon: 'gantt' },
	{ key: 'files', label: 'Fájlok', icon: 'files' },
];

/* ── Feladatkártya (tábla) ───────────────────────── */

function Badges({ t, blocked, projectHasClient }) {
	const s = t.stats;
	return html`
		<span class="badges">
			<${PriorityFlag} priority=${t.priority} />
			${t.due_date ? html`<span class=${'due' + (isOverdue(t) ? ' is-late' : '')}>${shortDate(t.due_date)}</span>` : null}
			${s.subtasks_total ? html`<span class="badge" title="Alfeladatok"><${Icon} name="sub" size="13" />${s.subtasks_done}/${s.subtasks_total}</span>` : null}
			${s.checklist_total ? html`<span class=${'badge' + (s.checklist_done === s.checklist_total ? ' is-done' : '')} title="Ellenőrzőlista"><${Icon} name="check" size="13" />${s.checklist_done}/${s.checklist_total}</span>` : null}
			${s.comments ? html`<span class="badge" title="Hozzászólások"><${Icon} name="comment" size="13" />${s.comments}</span>` : null}
			${s.minutes ? html`<span class="badge" title="Rögzített idő"><${Icon} name="clock" size="13" />${minutesLabel(s.minutes)}</span>` : null}
			${blocked ? html`<span class="badge is-blocked" title="Másik feladatra vár"><${Icon} name="block" size="13" /></span>` : null}
			${projectHasClient && !t.visible ? html`<span class="badge" title="Az ügyfél nem látja"><${Icon} name="eyeOff" size="13" /></span>` : null}
		</span>`;
}

/* ── Tábla húzással ──────────────────────────────── */

function Board({ project, tasks, statuses, reload, setTasks, period }) {
	const [drag, setDrag] = useState(null); // { id, status, index }
	const [adding, setAdding] = useState(null);
	const boardRef = useRef(null);
	const top = tasks.filter((t) => !t.parent_id);
	const byId = Object.fromEntries(tasks.map((t) => [t.id, t]));
	const isBlocked = (t) => t.stats.blocked_by.some((id) => byId[id] && byId[id].status !== 'done');
	const columns = statuses.map((s) => ({ ...s, items: top.filter((t) => t.status === s.key).sort((a, b) => a.sort - b.sort) }));

	const onPointerDown = (e, task) => {
		if (e.button !== 0) return;
		const card = e.currentTarget;
		const start = { x: e.clientX, y: e.clientY };
		let ghost = null;
		let target = null;

		const move = (ev) => {
			if (!ghost) {
				if (Math.hypot(ev.clientX - start.x, ev.clientY - start.y) < 6) return;
				const r = card.getBoundingClientRect();
				ghost = card.cloneNode(true);
				ghost.classList.add('card-ghost');
				ghost.style.width = r.width + 'px';
				ghost.dataset.dx = String(start.x - r.left);
				ghost.dataset.dy = String(start.y - r.top);
				document.body.appendChild(ghost);
				document.body.classList.add('is-dragging');
			}
			ghost.style.transform = `translate(${ev.clientX - Number(ghost.dataset.dx)}px, ${ev.clientY - Number(ghost.dataset.dy)}px) rotate(2deg)`;
			ghost.style.display = 'none';
			const col = document.elementFromPoint(ev.clientX, ev.clientY);
			ghost.style.display = '';
			const colEl = col && col.closest('[data-col]');
			if (!colEl) return;
			const cards = [...colEl.querySelectorAll('[data-card]')].filter((c) => Number(c.dataset.card) !== task.id);
			let index = cards.length;
			for (let i = 0; i < cards.length; i++) {
				const r = cards[i].getBoundingClientRect();
				if (ev.clientY < r.top + r.height / 2) { index = i; break; }
			}
			target = { status: colEl.dataset.col, index };
			setDrag({ id: task.id, ...target });
		};

		const up = () => {
			window.removeEventListener('pointermove', move);
			window.removeEventListener('pointerup', up);
			document.body.classList.remove('is-dragging');
			if (ghost) ghost.remove();
			setDrag(null);
			if (!ghost) {
				setParam('task', task.id);
				return;
			}
			if (!target) return;
			const colItems = top.filter((t) => t.status === target.status && t.id !== task.id).sort((a, b) => a.sort - b.sort);
			colItems.splice(target.index, 0, task);
			const ids = colItems.map((t) => t.id);
			// Optimista frissítés: azonnal a helyére kerül, a szerver a háttérben ment.
			setTasks((list) => list.map((t) => {
				const i = ids.indexOf(t.id);
				return i >= 0 ? { ...t, status: target.status, sort: (i + 1) * 10 } : t;
			}));
			api('/pm/tasks/reorder', { method: 'POST', body: { status: target.status, ids } }).catch((err) => { toast(err.message, 'error'); reload(); });
		};

		window.addEventListener('pointermove', move);
		window.addEventListener('pointerup', up);
	};

	const create = (status, title) => {
		if (!title.trim()) return;
		api('/pm/tasks', { method: 'POST', body: { project_id: project.id, title, status, ...(period ? { period } : {}) } }).then((t) => {
			setTasks((list) => [...list, t]);
		}).catch((e) => toast(e.message, 'error'));
	};

	return html`
		<div class="board" ref=${boardRef}>
			${columns.map((col) => html`
				<section class=${'col col--' + col.key} data-col=${col.key} key=${col.key}>
					<header class="col__head"><span class=${'dot dot--' + col.key}></span><h3>${col.label}</h3><span class="muted">${col.items.length}</span></header>
					<div class="col__body">
						${(() => { let k = 0; return col.items.map((t) => {
							// A beszúrási vonal helye a húzott kártya nélkül számolva.
							const line = drag && drag.status === col.key && t.id !== drag.id && k === drag.index;
							if (!drag || t.id !== drag.id) k++;
							return html`
							${line ? html`<div class="drop-line" key=${'d' + t.id}></div>` : null}
							<article key=${t.id} data-card=${t.id} class=${'card-task' + (drag && drag.id === t.id ? ' is-dragged' : '') + (t.status === 'done' ? ' is-done' : '')}
								onPointerDown=${(e) => onPointerDown(e, t)} tabIndex="0" onKeyDown=${(e) => e.key === 'Enter' && setParam('task', t.id)}>
								<span class="card-task__title">${t.title}</span>
								<span class="card-task__foot">
									<${Badges} t=${t} blocked=${isBlocked(t)} projectHasClient=${!!project.client_id} />
									<${Avatar} user=${t.assignee} size="24" />
								</span>
							</article>`; }); })()}
						${drag && drag.status === col.key && drag.index >= col.items.filter((t) => t.id !== drag.id).length ? html`<div class="drop-line"></div>` : null}
					</div>
					${adding === col.key
						? html`<input class="quick-add" autoFocus placeholder="Feladat neve, Enter" onKeyDown=${(e) => { if (e.key === 'Enter') { create(col.key, e.target.value); e.target.value = ''; } if (e.key === 'Escape') setAdding(null); }} onBlur=${(e) => { create(col.key, e.target.value); setAdding(null); }} />`
						: html`<button class="col__add" onClick=${() => setAdding(col.key)}><${Icon} name="plus" size="15" /> Feladat</button>`}
				</section>`)}
		</div>`;
}

/* ── Lista ───────────────────────────────────────── */

function ListView({ project, tasks, statuses, reload }) {
	const { boot } = useApp();
	const [open, setOpen] = useState({});
	const update = (t, body) => api('/pm/tasks/' + t.id, { method: 'POST', body }).then(reload).catch((e) => toast(e.message, 'error'));
	const subs = (id) => tasks.filter((t) => t.parent_id === id);

	const Row = (t, depth = 0) => html`
		<tr key=${t.id} class=${(t.status === 'done' ? 'is-done ' : '') + (depth ? 'is-sub' : '')}>
			<td class="list-title" style=${{ paddingLeft: 12 + depth * 22 + 'px' }}>
				${!depth && t.stats.subtasks_total ? html`<button class=${'twisty' + (open[t.id] ? ' is-open' : '')} onClick=${() => setOpen({ ...open, [t.id]: !open[t.id] })} aria-label="Alfeladatok"><${Icon} name="chevron" size="14" /></button>` : html`<span class="twisty-space"></span>`}
				<a href="#" onClick=${(e) => { e.preventDefault(); setParam('task', t.id); }}>${t.title}</a>
				${t.stats.subtasks_total ? html`<span class="badge"><${Icon} name="sub" size="12" />${t.stats.subtasks_done}/${t.stats.subtasks_total}</span>` : null}
			</td>
			<td><select class="cell-select" value=${t.status} onChange=${(e) => update(t, { status: e.target.value })}>${statuses.map((s) => html`<option value=${s.key}>${s.label}</option>`)}</select></td>
			<td><select class="cell-select" value=${t.assignee ? t.assignee.id : 0} onChange=${(e) => update(t, { assignee_id: e.target.value })}><option value="0">—</option>${boot.users.map((u) => html`<option value=${u.id}>${u.name}</option>`)}</select></td>
			<td><select class="cell-select" value=${t.priority} onChange=${(e) => update(t, { priority: e.target.value })}>${boot.priorities.map((p) => html`<option value=${p.key}>${p.label}</option>`)}</select></td>
			<td><input type="date" class=${'cell-date' + (isOverdue(t) ? ' is-late' : '')} value=${t.due_date || ''} onChange=${(e) => update(t, { due_date: e.target.value })} /></td>
			<td class="muted">${t.stats.minutes ? minutesLabel(t.stats.minutes) : '—'}</td>
			<td>${project.client_id ? html`<button class=${'icon-btn' + (t.visible ? '' : ' is-off')} title=${t.visible ? 'Az ügyfél látja' : 'Az ügyfél nem látja'} onClick=${() => update(t, { visible: t.visible ? 0 : 1 })}><${Icon} name=${t.visible ? 'users' : 'eyeOff'} size="15" /></button>` : null}</td>
		</tr>
		${open[t.id] ? subs(t.id).map((s) => Row(s, 1)) : null}`;

	return html`
		<div class="card table-card">
			<table class="table table--tasks">
				<thead><tr><th>Feladat</th><th>Státusz</th><th>Felelős</th><th>Prioritás</th><th>Határidő</th><th>Idő</th><th>${project.client_id ? 'Ügyfél' : ''}</th></tr></thead>
				${statuses.map((s) => {
					const list = tasks.filter((t) => !t.parent_id && t.status === s.key).sort((a, b) => a.sort - b.sort);
					if (!list.length) return null;
					return html`<tbody key=${s.key}><tr class="group-row"><td colspan="7"><span class=${'dot dot--' + s.key}></span>${s.label} <span class="muted">${list.length}</span></td></tr>${list.map((t) => Row(t))}</tbody>`;
				})}
			</table>
			${!tasks.length ? html`<${Empty} title="Még nincs feladat" />` : null}
		</div>`;
}

/* ── Idővonal (Gantt) ────────────────────────────── */

const DAY_W = 34;
const ROW_H = 40;

function Timeline({ project, tasks, reload, setTasks }) {
	const [preview, setPreview] = useState(null); // { id, start, end }
	const scrollRef = useRef(null);
	const today = todayISO();

	// Sorrend: fő feladatok kezdés szerint, alattuk az alfeladatok.
	const rows = useMemo(() => {
		const key = (t) => t.start_date || t.due_date || '9999';
		const top = tasks.filter((t) => !t.parent_id).sort((a, b) => key(a).localeCompare(key(b)) || a.sort - b.sort);
		const out = [];
		top.forEach((t) => {
			out.push({ t, depth: 0 });
			tasks.filter((s) => s.parent_id === t.id).sort((a, b) => key(a).localeCompare(key(b))).forEach((s) => out.push({ t: s, depth: 1 }));
		});
		return out;
	}, [tasks]);

	const dated = tasks.filter((t) => t.start_date || t.due_date);
	const allDates = dated.flatMap((t) => [t.start_date, t.due_date].filter(Boolean)).concat([today, project.start_date, project.due_date].filter(Boolean));
	const minDate = allDates.reduce((a, b) => (a < b ? a : b));
	const maxDate = allDates.reduce((a, b) => (a > b ? a : b));
	const start = addDays(minDate, -3);
	const days = Math.max(35, daysBetween(start, maxDate) + 10);
	const dates = Array.from({ length: days }, (_, i) => addDays(start, i));

	const span = (t) => {
		const p = preview && preview.id === t.id ? preview : null;
		const s = p ? p.start : t.start_date || t.due_date;
		const e = p ? p.end : t.due_date || t.start_date;
		return s && e ? { s, e: e < s ? s : e } : null;
	};
	const indexOf = Object.fromEntries(rows.map((r, i) => [r.t.id, i]));

	const onPointerDown = (e, t, mode) => {
		e.stopPropagation();
		const sp = span(t);
		const x0 = e.clientX;
		let moved = false;
		let current = { id: t.id, start: sp.s, end: sp.e };
		const move = (ev) => {
			const d = Math.round((ev.clientX - x0) / DAY_W);
			if (d !== 0) moved = true;
			if (mode === 'move') current = { id: t.id, start: addDays(sp.s, d), end: addDays(sp.e, d) };
			if (mode === 'end') current = { id: t.id, start: sp.s, end: addDays(sp.e, d) < sp.s ? sp.s : addDays(sp.e, d) };
			if (mode === 'start') current = { id: t.id, start: addDays(sp.s, d) > sp.e ? sp.e : addDays(sp.s, d), end: sp.e };
			setPreview(current);
		};
		const up = () => {
			window.removeEventListener('pointermove', move);
			window.removeEventListener('pointerup', up);
			document.body.classList.remove('is-dragging');
			if (!moved) { setPreview(null); setParam('task', t.id); return; }
			setTasks((list) => list.map((x) => (x.id === t.id ? { ...x, start_date: current.start, due_date: current.end } : x)));
			setPreview(null);
			api('/pm/tasks/' + t.id, { method: 'POST', body: { start_date: current.start, due_date: current.end } }).catch((err) => { toast(err.message, 'error'); reload(); });
		};
		document.body.classList.add('is-dragging');
		window.addEventListener('pointermove', move);
		window.addEventListener('pointerup', up);
	};

	const setDates = (t) => {
		const s = today;
		const e = addDays(today, 2);
		setTasks((list) => list.map((x) => (x.id === t.id ? { ...x, start_date: s, due_date: e } : x)));
		api('/pm/tasks/' + t.id, { method: 'POST', body: { start_date: s, due_date: e } }).catch(() => reload());
	};

	// Hónap-címkék a fejlécben.
	const months = [];
	dates.forEach((d, i) => {
		const m = d.slice(0, 7);
		if (!months.length || months[months.length - 1].m !== m) months.push({ m, i, n: 1 });
		else months[months.length - 1].n++;
	});

	const width = days * DAY_W;
	const todayX = daysBetween(start, today) * DAY_W;

	// Megnyitáskor a mai nap legyen látható (a bal oldali címoszlop mellett).
	useEffect(() => {
		if (scrollRef.current) scrollRef.current.scrollLeft = Math.max(0, todayX - 7 * DAY_W);
	}, []);

	// Függőség-nyilak: az előfeltétel végétől a feladat elejéig.
	const arrows = [];
	rows.forEach((r) => {
		const to = span(r.t);
		if (!to) return;
		r.t.stats.blocked_by.forEach((depId) => {
			const dep = tasks.find((x) => x.id === depId);
			const from = dep && span(dep);
			if (!from || indexOf[depId] === undefined) return;
			const x1 = (daysBetween(start, from.e) + 1) * DAY_W;
			const y1 = indexOf[depId] * ROW_H + ROW_H / 2;
			const x2 = daysBetween(start, to.s) * DAY_W;
			const y2 = indexOf[r.t.id] * ROW_H + ROW_H / 2;
			const late = x2 < x1;
			arrows.push(html`<path key=${depId + '-' + r.t.id} class=${'arrow' + (late ? ' is-late' : '')} d=${`M${x1} ${y1} H${x1 + 10} V${y2} H${x2 - 2}`} marker-end="url(#arrowhead)" />`);
		});
	});

	if (!tasks.length) return html`<${Empty} icon="gantt" title="Még nincs feladat">Adj hozzá feladatokat a Tábla nézetben.</${Empty}>`;

	return html`
		<div class="gantt card">
			<div class="gantt__scroll" ref=${scrollRef}>
				<div class="gantt__inner" style=${{ width: 280 + width + 'px' }}>
					<div class="gantt__head">
						<div class="gantt__corner">Feladat</div>
						<div class="gantt__scale" style=${{ width: width + 'px' }}>
							<div class="gantt__months">${months.map((m) => html`<span key=${m.m} style=${{ left: m.i * DAY_W + 'px', width: m.n * DAY_W + 'px' }}>${MONTHS_LONG[Number(m.m.slice(5)) - 1]} ${m.m.slice(0, 4)}</span>`)}</div>
							<div class="gantt__days">${dates.map((d) => {
								const dow = parseISO(d).getDay();
								return html`<span key=${d} class=${(dow === 0 || dow === 6 ? 'is-weekend ' : '') + (d === today ? 'is-today' : '')}>${Number(d.slice(8))}</span>`;
							})}</div>
						</div>
					</div>
					<div class="gantt__body">
						<div class="gantt__labels">
							${rows.map(({ t, depth }) => html`
								<div key=${t.id} class=${'gantt__label' + (depth ? ' is-sub' : '') + (t.status === 'done' ? ' is-done' : '')} style=${{ height: ROW_H + 'px' }} onClick=${() => setParam('task', t.id)}>
									<span class=${'dot dot--' + t.status}></span><span class="gantt__title">${t.title}</span><${Avatar} user=${t.assignee} size="20" />
								</div>`)}
						</div>
						<div class="gantt__grid" style=${{ width: width + 'px', height: rows.length * ROW_H + 'px', '--day': DAY_W + 'px', '--row': ROW_H + 'px' }}>
							${dates.map((d, i) => {
								const dow = parseISO(d).getDay();
								return dow === 0 || dow === 6 ? html`<span key=${'w' + d} class="gantt__weekend" style=${{ left: i * DAY_W + 'px', width: DAY_W + 'px' }}></span>` : null;
							})}
							<span class="gantt__today" style=${{ left: todayX + DAY_W / 2 + 'px' }}></span>
							<svg class="gantt__arrows" width=${width} height=${rows.length * ROW_H}>
								<defs><marker id="arrowhead" markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto"><path d="M0 0 L8 4 L0 8 z" /></marker></defs>
								${arrows}
							</svg>
							${rows.map(({ t }, i) => {
								const sp = span(t);
								if (!sp) {
									return html`<button key=${t.id} class="gantt__nodate" style=${{ top: i * ROW_H + 8 + 'px', left: (todayX > 0 ? todayX : 0) + 'px' }} onClick=${() => setDates(t)}>+ dátum</button>`;
								}
								const left = daysBetween(start, sp.s) * DAY_W;
								const w = (daysBetween(sp.s, sp.e) + 1) * DAY_W;
								return html`
									<div key=${t.id} class=${'bar bar--' + t.status + (isOverdue(t) ? ' is-late' : '')} style=${{ top: i * ROW_H + 7 + 'px', left: left + 'px', width: w + 'px', '--pc': project.color }}
										onPointerDown=${(e) => onPointerDown(e, t, 'move')} title=${t.title + ' · ' + sp.s + ' → ' + sp.e}>
										<span class="bar__handle bar__handle--l" onPointerDown=${(e) => onPointerDown(e, t, 'start')}></span>
										<span class="bar__label">${w > 90 ? t.title : ''}</span>
										<span class="bar__handle bar__handle--r" onPointerDown=${(e) => onPointerDown(e, t, 'end')}></span>
									</div>`;
							})}
						</div>
					</div>
				</div>
			</div>
			<p class="hint gantt__hint">Húzd a sávot az áthelyezéshez, a szélét a hossz módosításához. A nyilak a függőségeket mutatják.</p>
		</div>`;
}

/* ── Projekt beállítások ─────────────────────────── */

function SettingsModal({ project, onClose, onSaved }) {
	const { boot } = useApp();
	const [f, setF] = useState({ description: project.description || '', visible: project.visible, color: project.color, client_id: project.client_id || '', is_template: project.is_template, kind: project.kind || 'web', package_id: project.package_id || '', package_day: project.package_day || 1 });
	const [templates, setTemplates] = useState([]);
	useEffect(() => { api('/pm/projects?templates=1').then((l) => setTemplates(l.filter((t) => t.id !== project.id))).catch(() => {}); }, []);
	const save = (e) => {
		e.preventDefault();
		api('/pm/projects/' + project.id, { method: 'POST', body: { ...f, package_id: f.is_template ? 0 : Number(f.package_id) || 0, visible: f.visible ? 1 : 0, is_template: f.is_template ? 1 : 0 } }).then(() => { onClose(); onSaved(); toast('Mentve.'); }).catch((err) => toast(err.message, 'error'));
	};
	const del = () => {
		if (!window.confirm('Biztosan törlöd a projektet az összes feladattal együtt?')) return;
		api('/pm/projects/' + project.id, { method: 'DELETE' }).then(() => { onClose(); navigate('/projects'); toast('Projekt törölve.'); }).catch((err) => toast(err.message, 'error'));
	};
	return html`
		<${Modal} title="Projekt beállítások" onClose=${onClose}>
			<form class="form" onSubmit=${save}>
				<label class="field"><span>Leírás (az ügyfél is látja, ha a projekt látható)</span><textarea rows="4" value=${f.description} onInput=${(e) => setF({ ...f, description: e.target.value })}></textarea></label>
				<div class="row">
					<label class="field"><span>Ügyfél</span><select value=${f.client_id} onChange=${(e) => setF({ ...f, client_id: e.target.value })}><option value="">— belső projekt —</option>${boot.clients.map((c) => html`<option value=${c.id}>${c.name}</option>`)}</select></label>
					<label class="field"><span>Típus</span><select value=${f.kind} onChange=${(e) => setF({ ...f, kind: e.target.value })}>${(boot.projectKinds || []).map((k) => html`<option value=${k.key}>${k.label}</option>`)}</select></label>
				</div>
				${!f.is_template ? html`
					<div class="row">
						<label class="field"><span>Havi feladatcsomag</span><select value=${f.package_id} onChange=${(e) => setF({ ...f, package_id: e.target.value })}><option value="">— nincs (egyszeri projekt) —</option>${templates.map((t) => html`<option value=${t.id}>${t.name}</option>`)}</select></label>
						${f.package_id ? html`<label class="field"><span>Minden hónap ennyiedik napján</span><input type="number" min="1" max="28" value=${f.package_day} onInput=${(e) => setF({ ...f, package_day: e.target.value })} /></label>` : null}
					</div>` : null}
				<div class="field"><span>Szín</span><div class="swatches">${COLORS.map((c) => html`<button type="button" key=${c} class=${'swatch' + (f.color === c ? ' is-active' : '')} style=${{ background: c }} onClick=${() => setF({ ...f, color: c })}></button>`)}</div></div>
				<label class="toggle"><input type="checkbox" checked=${f.visible} onChange=${(e) => setF({ ...f, visible: e.target.checked })} /> <span>Az ügyfél látja a portálon</span></label>
				<label class="toggle"><input type="checkbox" checked=${f.is_template} onChange=${(e) => setF({ ...f, is_template: e.target.checked })} /> <span>Sablon (új projektek kiindulópontja)</span></label>
				<footer class="form__foot">
					${boot.me.is_admin ? html`<button type="button" class="btn btn--danger" onClick=${del}>Projekt törlése</button>` : null}
					<span class="spacer"></span>
					<button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn">Mentés</button>
				</footer>
			</form>
		</${Modal}>`;
}

/* ── Oldal ───────────────────────────────────────── */

export function ProjectPage({ id, params }) {
	const { boot } = useApp();
	const [data, setData] = useState(null);
	const [tasks, setTasks] = useState([]);
	const [settings, setSettings] = useState(false);
	const [calling, setCalling] = useState(false);
	const [error, setError] = useState('');
	const view = params.view || localStorage.getItem('hpv-project-view') || 'board';
	const [creatingPkg, setCreatingPkg] = useState(false);

	const load = () => api('/pm/projects/' + id).then((d) => { setData(d); setTasks(d.tasks); }).catch((e) => setError(e.message));
	useEffect(() => {
		load();
		const on = () => load();
		window.addEventListener('hpv:changed', on);
		return () => window.removeEventListener('hpv:changed', on);
	}, [id]);

	if (error) return html`<${Empty} icon="x" title=${error} />`;
	if (!data) return html`<${Spinner} />`;
	const p = data.project;
	const statuses = boot.statuses;
	// Havidíjas projekt: hónaponként (alapból a legutóbbi hónap; „all” = minden hónap).
	const periods = [...new Set(tasks.map((t) => t.period).filter(Boolean))].sort().reverse();
	const period = p.package_id && periods.length ? (params.period === 'all' ? '' : periods.includes(params.period) ? params.period : periods[0]) : '';
	const shown = period ? tasks.filter((t) => !t.period || t.period === period) : tasks;
	const monthLabel = (per) => { const [y, m] = per.split('-'); return y + '. ' + MONTHS_LONG[Number(m) - 1]; };
	const nextPeriod = (() => { const d = new Date(); const cur = d.toISOString().slice(0, 7); if (!periods.includes(cur)) return cur; d.setDate(1); d.setMonth(d.getMonth() + 1); return d.toISOString().slice(0, 7); })();
	const createPackage = () => {
		if (!window.confirm('Elkészítsük a(z) ' + monthLabel(nextPeriod) + ' havi feladatcsomagot?')) return;
		setCreatingPkg(true);
		api('/pm/projects/' + p.id + '/package', { method: 'POST', body: { period: nextPeriod } })
			.then((r) => { toast(r.created + ' feladat létrehozva.'); setParam('period', nextPeriod); load(); })
			.catch((e) => toast(e.message, 'error'))
			.finally(() => setCreatingPkg(false));
	};
	const done = shown.filter((t) => !t.parent_id && t.status === 'done').length;
	const total = shown.filter((t) => !t.parent_id).length;
	const progress = total ? Math.round((100 * done) / total) : 0;

	const saveProject = (body) => api('/pm/projects/' + p.id, { method: 'POST', body }).then((np) => setData({ ...data, project: { ...p, ...np } })).catch((e) => toast(e.message, 'error'));
	const setView = (v) => {
		try { localStorage.setItem('hpv-project-view', v); } catch (e) { /* privát mód */ }
		setParam('view', v);
	};
	const addTask = () => {
		const title = window.prompt('Új feladat neve:');
		if (!title) return;
		api('/pm/tasks', { method: 'POST', body: { project_id: p.id, title, status: 'todo', ...(period ? { period } : {}) } }).then((t) => { setTasks((l) => [...l, t]); setParam('task', t.id); });
	};

	return html`
		<div class="page page--wide">
			<header class="project-head" style=${{ '--pc': p.color }}>
				<div class="project-head__main">
					<a class="crumb" href="#/projects">Projektek</a>
					<div class="project-head__title"><span class="project-dot"></span><${InlineText} className="title-input" value=${p.name} onSave=${(v) => saveProject({ name: v })} /></div>
					<div class="project-head__meta">
						${p.client ? html`<a class="chip chip--client" href=${'#/projects?client=' + p.client_id}>${p.client}</a>` : html`<span class="chip">${p.is_template ? 'Sablon' : 'Belső projekt'}</span>`}
						${p.kind && p.kind !== 'web' ? html`<span class="chip chip--kind">${((boot.projectKinds || []).find((k) => k.key === p.kind) || {}).label || p.kind}</span>` : null}
						${p.package_id ? html`<select class="meta-select meta-select--month" value=${period || 'all'} onChange=${(e) => setParam('period', e.target.value)} aria-label="Hónap">
							${periods.map((per) => html`<option value=${per}>${monthLabel(per)}</option>`)}<option value="all">Minden hónap</option></select>` : null}
						<select class="meta-select" value=${p.status} onChange=${(e) => saveProject({ status: e.target.value })}>${boot.projectStatuses.map((s) => html`<option value=${s.key}>${s.label}</option>`)}</select>
						<label class="meta-field"><${Icon} name="users" size="15" /><select class="meta-select" value=${p.owner ? p.owner.id : 0} onChange=${(e) => saveProject({ owner_id: e.target.value })}><option value="0">Nincs felelős</option>${boot.users.map((u) => html`<option value=${u.id}>${u.name}</option>`)}</select></label>
						<label class="meta-field"><span class="muted">Kezdés</span><input type="date" value=${p.start_date || ''} onChange=${(e) => saveProject({ start_date: e.target.value })} /></label>
						<label class="meta-field"><span class="muted">Határidő</span><input type="date" value=${p.due_date || ''} onChange=${(e) => saveProject({ due_date: e.target.value })} /></label>
						<span class="meta-progress"><span class="progress"><span style=${{ width: progress + '%' }}></span></span>${progress}%</span>
					</div>
				</div>
				<div class="project-head__actions">
					${p.client_id ? html`<a class="btn btn--ghost" href=${'#/chat?client=' + p.client_id}><${Icon} name="chat" /> Chat</a>` : null}
					${p.client_id ? html`<button class="btn btn--ghost" onClick=${() => setCalling(true)}><${Icon} name="video" /> Hívás</button>` : null}
					${p.client_id ? html`<a class="btn btn--ghost" target="_blank" rel="noopener" href=${CFG.portalUrl + (CFG.portalUrl.includes('?') ? '&' : '?') + 'preview_client=' + p.client_id + '&view=projects&id=' + p.id}>Portál előnézet</a>` : null}
					<button class="icon-btn" onClick=${() => setSettings(true)} title="Beállítások" aria-label="Beállítások"><${Icon} name="cog" /></button>
					${p.package_id ? html`<button class="btn btn--ghost" disabled=${creatingPkg} onClick=${createPackage} title="A havi csomag magától is elkészül a megadott napon">+ ${monthLabel(nextPeriod)}</button>` : null}
					<button class="btn" onClick=${addTask}><${Icon} name="plus" /> Feladat</button>
				</div>
			</header>

			<nav class="tabs">${VIEWS.filter((v) => v.key !== 'files' || p.client_id).map((v) => html`<button key=${v.key} class=${view === v.key ? 'is-active' : ''} onClick=${() => setView(v.key)}><${Icon} name=${v.icon} size="16" /> ${v.label}</button>`)}</nav>

			${view === 'board' ? html`<${Board} project=${p} tasks=${shown} statuses=${statuses} reload=${load} setTasks=${setTasks} period=${period} />` : null}
			${view === 'list' ? html`<${ListView} project=${p} tasks=${shown} statuses=${statuses} reload=${load} />` : null}
			${view === 'timeline' ? html`<${Timeline} project=${p} tasks=${shown} reload=${load} setTasks=${setTasks} />` : null}
			${view === 'files' && p.client_id ? html`<${FilesPanel} clientId=${p.client_id} projectId=${p.id} hideProject />` : null}
			${settings ? html`<${SettingsModal} project=${p} onClose=${() => setSettings(false)} onSaved=${load} />` : null}
			${calling ? html`<${NewCallModal} clientId=${p.client_id} projectId=${p.id} onClose=${() => setCalling(false)} />` : null}
		</div>`;
}
