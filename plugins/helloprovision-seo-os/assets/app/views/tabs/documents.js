/**
 * Dokumentumok: típusonként generálás (Claude vagy sablonszöveg), előnézet, szövegszerkesztés, jóváhagyás, letöltés
 * (PDF / DOCX / XLSX), elavultság jelzése. Második fül: gyártási feladatok szerepkörönként, CRM-be küldéssel.
 */
import { html, useState, useApp, api, useLoad, useJob, setParam, toast, errorText, fmt, download, Icon, Spinner, ErrorBox, Empty, Pill, Select, InlineEdit, Drawer, Field, Textarea, Input, Confirm, can } from '../../ui.js';

const STATUS_KIND = { draft: '', review: 'warn', approved: 'ok', sent: 'ok' };
const LANGS = [['hu', 'Magyar'], ['en', 'Angol']];

function TypeCard({ t, latest, active, onOpen, onGenerate, busy }) {
	const [lang, setLang] = useState(latest ? latest.language : t.default_language);
	return html`<li class=${'doc-type' + (active ? ' is-active' : '')}>
		<button class="doc-type__main" onClick=${() => latest && onOpen(latest.id)} disabled=${!latest}>
			<span class="doc-type__title"><${Icon} name="doc" size="15" /> ${t.label}</span>
			<span class="muted small">${t.audience === 'client' ? 'Ügyfélnek' : 'Belső'} · ${t.formats.map((f) => f.toUpperCase()).join(' / ')}</span>
			${latest ? html`<span class="doc-type__meta">
				<${Pill} kind=${STATUS_KIND[latest.status]}>${latest.status_label}</${Pill}>
				<span class="muted small">v${latest.version} · ${fmt.ago(latest.created_at)}</span>
				${latest.stale ? html`<${Pill} kind="warn" title="Az alapadatok (kulcsszavak, struktúra, roadmap, wireframe) változtak a generálás óta">Elavult</${Pill}>` : ''}
			</span>` : html`<span class="muted small">Még nincs generálva</span>`}
		</button>
		${t.can_generate ? html`<div class="doc-type__actions">
			${t.audience === 'client' ? html`<${Select} value=${lang} onChange=${setLang} options=${LANGS} />` : ''}
			<button class="btn btn--sm" disabled=${busy} onClick=${() => onGenerate(t.doc_type, lang)}><${Icon} name="spark" size="14" /> ${latest ? 'Új változat' : 'Generálás'}</button>
		</div>` : ''}
	</li>`;
}

/** A szöveges blokkok szerkesztése (a táblák adatból jönnek, azokat a forrásnál kell módosítani). */
function Editor({ doc, onSave, onClose }) {
	const [content, setContent] = useState(doc.content);
	const [saving, setSaving] = useState(false);
	const setBlock = (si, bi, patch) => setContent((c) => ({ ...c, sections: c.sections.map((s, i) => (i !== si ? s : { ...s, blocks: s.blocks.map((b, j) => (j === bi ? { ...b, ...patch } : b)) })) }));
	const save = async () => {
		const clean = { ...content, sections: content.sections.map((s) => ({ ...s, blocks: s.blocks.map((b) => (b.type === 'bullets' ? { ...b, items: b.items.map((x) => x.trim()).filter(Boolean) } : b)) })) };
		setSaving(true);
		try { await onSave(clean); onClose(); } catch (e) { /* a hibát a patch már jelezte */ } finally { setSaving(false); }
	};
	return html`<${Drawer} title="Szöveg szerkesztése" subtitle=${doc.title + ' · v' + doc.version} onClose=${onClose}
		footer=${html`<button class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${saving} onClick=${save}>${saving ? 'Mentés…' : 'Mentés és újrarajzolás'}</button>`}>
		<p class="muted small">A táblák (kulcsszavak, URL-ek, számok) az adatokból készülnek – ha azokon kell változtatni, a forrásnál (Kulcsszavak, Struktúra, Tartalomstratégia) módosíts, majd generálj új változatot.</p>
		<${Field} label="Bevezető"><${Textarea} rows="3" value=${content.lead || ''} onInput=${(v) => setContent({ ...content, lead: v })} /></${Field}>
		${content.sections.map((s, si) => html`<div key=${si} class="stack-sm doc-edit-section">
			<${Field} label=${'Szakasz ' + (si + 1)}><${Input} value=${s.title} onInput=${(v) => setContent((c) => ({ ...c, sections: c.sections.map((x, i) => (i === si ? { ...x, title: v } : x)) }))} /></${Field}>
			${s.blocks.map((b, bi) => (b.type === 'paragraph' ? html`<${Textarea} key=${bi} rows="3" value=${b.text} onInput=${(v) => setBlock(si, bi, { text: v })} />`
				: b.type === 'bullets' ? html`<${Textarea} key=${bi} rows=${Math.min(8, b.items.length + 1)} value=${b.items.join('\n')} onInput=${(v) => setBlock(si, bi, { items: v.split('\n') })} />`
				: b.type === 'callout' ? html`<${Textarea} key=${bi} rows="2" value=${b.text} onInput=${(v) => setBlock(si, bi, { text: v })} />`
				: b.type === 'table' ? html`<span key=${bi} class="muted small">▦ Tábla: ${b.columns.slice(0, 4).join(', ')}${b.columns.length > 4 ? '…' : ''} (${b.rows.length} sor)</span>` : ''))}
		</div>`)}
	</${Drawer}>`;
}

function Viewer({ id, versions, onChanged }) {
	const { me } = useApp();
	const [doc, loading, error, reload, setDoc] = useLoad('/documents/' + id, [id]);
	const [preview] = useLoad('/documents/' + id + '/preview', [id, doc && doc.files.map((f) => f.file_id).join()]);
	const [editing, setEditing] = useState(false);
	const [confirm, setConfirm] = useState(false);
	if (loading && !doc) return html`<${Spinner} />`;
	if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
	const canEdit = versions.canEdit && !['approved', 'sent'].includes(doc.status);
	const patch = async (body) => {
		try { setDoc(await api('/documents/' + doc.id, { method: 'PATCH', body })); onChanged(); } catch (e) { toast(errorText(e), 'error'); throw e; }
	};
	const name = (f) => doc.title + ' v' + doc.version + '.' + f;
	return html`<div class="stack">
		<div class="toolbar">
			<${Pill} kind=${STATUS_KIND[doc.status]}>${doc.status_label}</${Pill}>
			<span class="muted small">v${doc.version} · ${doc.method === 'claude' ? 'Claude' : 'sablonszöveg'} · ${fmt.datetime(doc.created_at)}${doc.language === 'en' ? ' · angol' : ''}</span>
			${versions.list.length > 1 ? html`<${Select} value=${String(doc.id)} onChange=${(v) => setParam('doc', v)} options=${versions.list.map((v) => [String(v.id), 'v' + v.version + ' – ' + v.status_label + (v.language === 'en' ? ' (EN)' : '')])} />` : ''}
			<span class="toolbar__spacer"></span>
			${doc.files.map((f) => html`<button key=${f.format} class="btn btn--ghost btn--sm" onClick=${() => download('/documents/' + doc.id + '/files/' + f.format, name(f.format))}><${Icon} name="download" size="14" /> ${f.format.toUpperCase()}</button>`)}
		</div>
		${doc.method !== 'claude' ? html`<div class="alert alert--info small"><span>Ez a változat sablonszöveggel készült (nincs Anthropic API-kulcs, vagy a Claude hívás nem sikerült). A táblák így is teljesek; a szöveget szerkesztheted, vagy a kulcs beállítása után generálj új változatot.</span></div>` : ''}
		<div class="toolbar">
			${canEdit ? html`<button class="btn btn--ghost btn--sm" onClick=${() => setEditing(true)}><${Icon} name="edit" size="14" /> Szöveg szerkesztése</button>` : ''}
			${canEdit && doc.status === 'draft' ? html`<button class="btn btn--ghost btn--sm" onClick=${() => patch({ status: 'review' })}>Ellenőrzésre</button>` : ''}
			${can(me, 'approve.internal') && ['draft', 'review'].includes(doc.status) ? html`<button class="btn btn--sm" onClick=${() => patch({ status: 'approved' })}><${Icon} name="check" size="14" /> Jóváhagyás</button>` : ''}
			${versions.canEdit && doc.status === 'approved' ? html`<button class="btn btn--sm" onClick=${() => patch({ status: 'sent' })}>Kiküldve jelölés</button>` : ''}
			${doc.status === 'sent' ? html`<span class="muted small">Kiküldve: ${fmt.datetime(doc.sent_at)}</span>` : ''}
			<span class="toolbar__spacer"></span>
			${can(me, 'documents.generate') ? html`<button class="btn btn--ghost btn--sm" onClick=${() => setConfirm(true)}><${Icon} name="trash" size="14" /></button>` : ''}
		</div>
		${preview ? html`<iframe class="doc-frame" title="Előnézet" srcdoc=${preview.html}></iframe>` : html`<${Spinner} />`}
		${editing ? html`<${Editor} doc=${doc} onClose=${() => setEditing(false)} onSave=${(content) => patch({ content })} />` : ''}
		${confirm ? html`<${Confirm} danger text=${'Törlöd ezt a változatot (v' + doc.version + ')?'} yes="Törlés" onClose=${() => setConfirm(false)}
			onYes=${async () => { try { await api('/documents/' + doc.id, { method: 'DELETE' }); setParam('doc', ''); onChanged(); } catch (e) { toast(errorText(e), 'error'); } }} />` : ''}
	</div>`;
}

function DocumentList({ project, params }) {
	const [data, loading, error, reload] = useLoad('/projects/' + project.id + '/documents');
	const [job, setJob] = useJob((j) => {
		toast(j.result.warning ? 'Elkészült sablonszöveggel: ' + j.result.warning : 'A dokumentum elkészült.', j.result.warning ? 'error' : 'ok');
		setParam('doc', String(j.result.document_id));
		reload();
	});
	const running = job && (job.status === 'queued' || job.status === 'running');
	if (loading && !data) return html`<${Spinner} />`;
	if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
	const latestOf = (type) => data.documents.find((d) => d.doc_type === type && d.stale !== null) || data.documents.find((d) => d.doc_type === type);
	const openId = params.doc ? Number(params.doc) : null;
	const open = data.documents.find((d) => d.id === openId);
	const generate = async (doc_type, language) => {
		try { setJob(await api('/projects/' + project.id + '/documents/generate', { method: 'POST', body: { doc_type, language } })); } catch (e) { toast(errorText(e), 'error'); }
	};
	const client = data.types.filter((t) => t.audience === 'client');
	const internal = data.types.filter((t) => t.audience !== 'client');
	const group = (title, list) => (list.length ? html`<section class="card"><header class="card__head"><h2>${title}</h2></header>
		<ul class="doc-types">${list.map((t) => html`<${TypeCard} key=${t.doc_type} t=${t} latest=${latestOf(t.doc_type)} active=${open && open.doc_type === t.doc_type}
			busy=${running} onOpen=${(id) => setParam('doc', String(id))} onGenerate=${generate} />`)}</ul></section>` : '');
	return html`<div class="grid grid--wf">
		<div class="stack">
			${running ? html`<div class="card pad"><div class="job-line"><${Spinner} label=${job.message || 'Generálás…'} /></div></div>` : ''}
			${group('Ügyféldokumentumok', client)}
			${group('Belső briefek', internal)}
		</div>
		<div>${open ? html`<${Viewer} key=${open.id} id=${open.id} onChanged=${reload}
				versions=${{ list: data.documents.filter((d) => d.doc_type === open.doc_type), canEdit: (data.types.find((t) => t.doc_type === open.doc_type) || {}).can_generate }} />`
			: html`<${Empty} icon="doc" title="Válassz dokumentumot">A dokumentumok az adatbázisból épülnek (kulcsszavak, struktúra, roadmap, wireframe-ek); a magyarázó szöveget a Claude írja a saját mintáink alapján.</${Empty}>`}</div>
	</div>`;
}

/* ── Gyártási feladatok ─────────────────────────────── */

function TaskDrawer({ task, users, onClose, onPatch, full }) {
	const { meta } = useApp();
	const [notes, setNotes] = useState(task.notes || '');
	return html`<${Drawer} title=${task.title} subtitle=${task.role_label + ' · ' + task.priority} onClose=${onClose}>
		<dl class="kv">
			${task.source_url ? html`<dt>Forrás URL</dt><dd class="url">${task.source_url}</dd>` : ''}
			${task.target_url ? html`<dt>Cél URL</dt><dd class="url">${task.target_url}</dd>` : ''}
			<dt>Teendő</dt><dd class="pre">${full ? html`<${InlineEdit} multiline value=${task.action} onSave=${(v) => onPatch({ action: v })} />` : task.action || '–'}</dd>
			<dt>Akkor kész, ha</dt><dd>${full ? html`<${InlineEdit} multiline value=${task.done_when} onSave=${(v) => onPatch({ done_when: v })} />` : task.done_when}</dd>
			<dt>Státusz</dt><dd><${Select} value=${task.status} onChange=${(v) => onPatch({ status: v })} options=${Object.entries(meta.task_statuses)} /></dd>
			${full ? html`<dt>Felelős</dt><dd><${Select} value=${task.assignee_id ? String(task.assignee_id) : ''} placeholder="– nincs –" onChange=${(v) => onPatch({ assignee_id: v ? Number(v) : null })} options=${users.map((u) => [String(u.id), u.display_name])} /></dd>
			<dt>Határidő</dt><dd><input class="input" type="date" value=${task.due_date || ''} onChange=${(e) => onPatch({ due_date: e.target.value || null })} /></dd>` : html`<dt>Határidő</dt><dd>${fmt.date(task.due_date)}</dd>`}
			${task.crm_task_id ? html`<dt>CRM</dt><dd>#${task.crm_task_id}</dd>` : ''}
		</dl>
		<${Field} label="Megjegyzés"><${Textarea} rows="3" value=${notes} onInput=${(v) => setNotes(v)} onBlur=${() => notes !== (task.notes || '') && onPatch({ notes })} /></${Field}>
	</${Drawer}>`;
}

export function TaskTable({ tasks, users, onPatch, full, showProject }) {
	const { meta } = useApp();
	const [open, setOpen] = useState(null);
	const current = open && tasks.find((t) => t.id === open);
	return html`<div class="table-wrap"><table class="table table--dense">
		<thead><tr><th></th>${showProject ? html`<th>Projekt</th>` : ''}<th>Feladat</th><th>Cél URL</th><th>Felelős</th><th>Határidő</th><th>Státusz</th></tr></thead>
		<tbody>${tasks.map((t) => html`<tr key=${t.id} class="is-clickable" onClick=${(e) => !e.target.closest('select,button') && setOpen(t.id)}>
			<td><${Pill} kind=${t.priority}>${t.priority}</${Pill}></td>
			${showProject ? html`<td class="small">${t.project}</td>` : ''}
			<td><span class=${'role-dot role-dot--' + t.role}></span>${t.title}${t.crm_task_id ? html` <span class="chip" title="Átküldve a CRM-be">CRM</span>` : ''}</td>
			<td class="url">${t.target_url || t.source_url}</td>
			<td class="small">${t.assignee || html`<span class="muted">–</span>`}</td>
			<td class="small">${fmt.date(t.due_date)}</td>
			<td><${Select} value=${t.status} onChange=${(v) => onPatch(t, { status: v })} options=${Object.entries(meta.task_statuses)} /></td>
		</tr>`)}</tbody>
	</table>
	${current ? html`<${TaskDrawer} task=${current} users=${users} full=${full} onClose=${() => setOpen(null)} onPatch=${(b) => onPatch(current, b)} />` : ''}
	</div>`;
}

function Tasks({ project }) {
	const { me, meta } = useApp();
	const [tasks, loading, error, reload, setTasks] = useLoad('/projects/' + project.id + '/tasks');
	const [users] = useLoad(can(me, 'tasks.edit') ? '/users' : null);
	const [role, setRole] = useState('');
	const [hideDone, setHideDone] = useState(true);
	const [busy, setBusy] = useState(false);
	if (loading && !tasks) return html`<${Spinner} />`;
	if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
	const full = can(me, 'tasks.edit');
	const shown = tasks.filter((t) => (!role || t.role === role) && (!hideDone || t.status !== 'done'));
	const counts = Object.keys(meta.task_roles).map((r) => [r, tasks.filter((t) => t.role === r && t.status !== 'done').length]);
	const patch = async (t, body) => {
		try { const n = await api('/tasks/' + t.id, { method: 'PATCH', body }); setTasks((all) => all.map((x) => (x.id === n.id ? { ...n, project: x.project } : x))); } catch (e) { toast(errorText(e), 'error'); }
	};
	const generate = async () => {
		setBusy(true);
		try { const r = await api('/projects/' + project.id + '/tasks/generate', { method: 'POST' }); toast(r.created + ' új, ' + r.updated + ' frissített feladat.'); reload(); } catch (e) { toast(errorText(e), 'error'); } finally { setBusy(false); }
	};
	const push = async () => {
		setBusy(true);
		try { const r = await api('/crm/push-tasks', { method: 'POST', body: { project: project.id } }); toast(r.pushed + ' feladat átküldve a CRM-be.'); reload(); } catch (e) { toast(errorText(e), 'error'); } finally { setBusy(false); }
	};
	return html`<div class="stack">
		<div class="toolbar">
			<div class="segmented">
				<button class=${!role ? 'is-active' : ''} onClick=${() => setRole('')}>Mind</button>
				${counts.map(([r, n]) => html`<button key=${r} class=${role === r ? 'is-active' : ''} onClick=${() => setRole(r)}><span class=${'role-dot role-dot--' + r}></span>${meta.task_roles[r]} ${n ? html`<span class="muted">${n}</span>` : ''}</button>`)}
			</div>
			<label class="check small"><input type="checkbox" checked=${hideDone} onChange=${(e) => setHideDone(e.target.checked)} /> Kész feladatok elrejtése</label>
			<span class="toolbar__spacer"></span>
			${full ? html`<button class="btn btn--ghost btn--sm" disabled=${busy} onClick=${generate}><${Icon} name="refresh" size="14" /> Feladatok ${tasks.length ? 'frissítése' : 'generálása'}</button>` : ''}
			${full && ['admin', 'seo_manager'].includes(me.role) && tasks.some((t) => !t.crm_task_id && t.status !== 'done') ? html`<button class="btn btn--sm" disabled=${busy} onClick=${push}><${Icon} name="ext" size="14" /> Küldés a CRM-be</button>` : ''}
		</div>
		${tasks.length ? html`<section class="card"><${TaskTable} tasks=${shown} users=${users || []} full=${full} onPatch=${patch} /></section>`
			: html`<${Empty} icon="tasks" title="Még nincsenek gyártási feladatok">A jóváhagyott struktúrából, roadmapből és wireframe-ekből készülnek: fejlesztő, szövegíró, grafikus és SEO teendők, elfogadási feltétellel.</${Empty}>`}
	</div>`;
}

export function Documents({ project, params }) {
	const { me } = useApp();
	const sub = params.sub === 'tasks' && can(me, 'tasks.view') ? 'tasks' : 'docs';
	return html`<div class="stack">
		${can(me, 'tasks.view') ? html`<div class="segmented">
			<button class=${sub === 'docs' ? 'is-active' : ''} onClick=${() => setParam('sub', '')}><${Icon} name="doc" size="14" /> Dokumentumok</button>
			<button class=${sub === 'tasks' ? 'is-active' : ''} onClick=${() => setParam('sub', 'tasks')}><${Icon} name="tasks" size="14" /> Gyártási feladatok</button>
		</div>` : ''}
		${sub === 'tasks' ? html`<${Tasks} project=${project} />` : html`<${DocumentList} project=${project} params=${params} />`}
	</div>`;
}
