/**
 * Technikai audit: Screaming Frog crawl (ügynökkel vagy kézi exportfeltöltéssel), témák XL / M / S prioritással
 * (a HelloProVision audit-sablonja szerint), megállapítások URL-listával, helyszíni ellenőrzés, fejlesztői feladatok,
 * audit XLSX és a „Technikai SEO audit” dokumentum.
 */
import { html, useState, useApp, api, upload, useLoad, useJob, setParam, toast, errorText, fmt, download, Icon, Spinner, ErrorBox, Empty, Pill, Select, Drawer, Field, Textarea, Input, Checkbox, DropZone, can } from '../../ui.js';
import { Comments } from '../collab.js';

const SIZE_KIND = { XL: 'XL', M: 'M', S: 'S' };
const RUN_KIND = { done: 'ok', failed: 'danger', queued: 'warn', running: 'warn', importing: 'warn', draft: '', cancelled: '' };

function Delta({ f }) {
	if (f.previous_count === null || f.previous_count === undefined || f.previous_count === f.count) return '';
	const better = f.count < f.previous_count;
	return html`<span class=${'small ' + (better ? 'ok' : 'danger')} title="Az előző futáshoz képest">${better ? '↓' : '↑'} ${f.previous_count}</span>`;
}

function FindingDrawer({ id, onClose, onPatch, editable }) {
	const { meta } = useApp();
	const [f, loading] = useLoad('/audit/findings/' + id, [id]);
	const [notes, setNotes] = useState(null);
	if (loading || !f) return html`<${Drawer} title="Megállapítás" onClose=${onClose}><${Spinner} /></${Drawer}>`;
	const statuses = editable ? Object.entries(meta.finding_statuses) : Object.entries(meta.finding_statuses).filter(([k]) => ['in_progress', 'fixed', f.status].includes(k));
	return html`<${Drawer} title=${f.label} subtitle=${meta.audit_topics[f.topic]} onClose=${onClose}>
		<div class="row-edit">
			<${Select} value=${f.status} onChange=${(v) => onPatch(f, { status: v })} options=${statuses} />
			${f.count > f.urls.length ? html`<span class="muted small">Az első ${f.urls.length} URL látható; a teljes lista az audit XLSX-ben.</span>` : ''}
		</div>
		${editable ? html`<${Field} label="Belső megjegyzés"><${Textarea} rows="2" value=${notes ?? f.notes} onInput=${setNotes} onBlur=${() => notes !== null && notes !== f.notes && onPatch(f, { notes })} /></${Field}>` : ''}
		<table class="table table--dense"><thead><tr><th>URL</th><th>Részlet</th></tr></thead>
			<tbody>${f.urls.map((u, i) => html`<tr key=${i}><td class="url"><a class="link" href=${u.url} target="_blank" rel="noopener">${u.url}</a>${u.from ? html`<div class="muted small">forrás: ${u.from}</div>` : ''}</td><td class="small">${u.detail}</td></tr>`)}</tbody>
		</table>
		<${Comments} subject="finding" id=${f.id} />
	</${Drawer}>`;
}

function Topic({ t, editable, onPatchTopic, onOpen }) {
	const { meta } = useApp();
	const [open, setOpen] = useState(false);
	const [draft, setDraft] = useState({});
	const active = t.findings.filter((f) => f.count);
	const fixed = t.findings.filter((f) => !f.count);
	const save = (k) => draft[k] !== undefined && draft[k] !== t[k] && onPatchTopic(t.topic, { [k]: draft[k] });
	return html`<article class=${'card audit-topic' + (t.included ? '' : ' is-muted')} id=${'topic-' + t.topic}>
		<header class="audit-topic__head" onClick=${(e) => !e.target.closest('select,input,button,label') && setOpen(!open)}>
			<${Pill} kind=${SIZE_KIND[t.size]}>${t.size}</${Pill}>
			<h3>${t.title}</h3>
			<span class="muted small">${active.length ? active.length + ' megállapítás' : t.findings.length ? 'minden javítva' : 'nincs adat'}</span>
			<span class="toolbar__spacer"></span>
			${editable ? html`<${Select} value=${t.size} onChange=${(v) => onPatchTopic(t.topic, { size: v })} options=${Object.keys(meta.audit_sizes).map((k) => [k, k])} />
				<${Checkbox} checked=${t.included} label="Dokumentumba" onChange=${(v) => onPatchTopic(t.topic, { included: v })} />` : ''}
			<${Icon} name=${open ? 'down' : 'chevron'} />
		</header>
		${active.length ? html`<ul class="finding-list">${active.map((f) => html`<li key=${f.id} class="is-clickable" onClick=${() => onOpen(f.id)}>
			<span class=${'finding-dot finding-dot--' + f.status}></span>
			<span class="finding-list__label">${f.label}</span>
			<${Delta} f=${f} />
			<span class="muted small">${meta.finding_statuses[f.status]}</span>
		</li>`)}</ul>` : ''}
		${open ? html`<div class="pad stack-sm audit-topic__body">
			<p class="small muted">${t.intro}</p>
			${fixed.length ? html`<p class="small ok">Javítva: ${fixed.map((f) => f.label.replace(/^0 /, '')).join('; ')}</p>` : ''}
			${t.topic === 'speed' ? html`<div class="form-grid">
				<${Field} label="PageSpeed mobil pontszám"><${Input} type="number" value=${(draft.metrics || t.metrics).mobile ?? ''} disabled=${!editable}
					onInput=${(v) => setDraft((d) => ({ ...d, metrics: { ...(d.metrics || t.metrics), mobile: v === '' ? null : Number(v) } }))} onBlur=${() => save('metrics')} /></${Field}>
				<${Field} label="PageSpeed asztali pontszám"><${Input} type="number" value=${(draft.metrics || t.metrics).desktop ?? ''} disabled=${!editable}
					onInput=${(v) => setDraft((d) => ({ ...d, metrics: { ...(d.metrics || t.metrics), desktop: v === '' ? null : Number(v) } }))} onBlur=${() => save('metrics')} /></${Field}>
				<${Checkbox} checked=${(draft.metrics || t.metrics).cwv_pass === false} label="Nem teljesíti a Core Web Vitals elvárásait" onChange=${(v) => { const m = { ...(draft.metrics || t.metrics), cwv_pass: !v }; setDraft((d) => ({ ...d, metrics: m })); editable && onPatchTopic(t.topic, { metrics: m }); }} />
			</div>` : ''}
			<${Field} label="Saját megfigyelés (a dokumentum „Mit látunk?” részébe kerül)">
				<${Textarea} rows="3" value=${draft.observation ?? t.observation} disabled=${!editable} placeholder="pl. A duplikált címek nagy része ugyanazt a terméket tartalmazza – mi a különbség az oldalak között?"
					onInput=${(v) => setDraft((d) => ({ ...d, observation: v }))} onBlur=${() => save('observation')} />
			</${Field}>
			<${Field} label="Fejlesztési javaslat" hint="Üresen a sablon javaslata kerül a dokumentumba és a fejlesztői feladatba.">
				<${Textarea} rows="3" value=${draft.recommendation ?? t.recommendation} disabled=${!editable} placeholder=${t.default_recommendation}
					onInput=${(v) => setDraft((d) => ({ ...d, recommendation: v }))} onBlur=${() => save('recommendation')} />
			</${Field}>
		</div>` : ''}
	</article>`;
}

function Crawler({ project, onImported }) {
	const { me } = useApp();
	const editable = can(me, 'audit.edit');
	const [data, loading, , reload] = useLoad('/projects/' + project.id + '/crawls');
	const [draftRun, setDraftRun] = useState(null);
	const [busy, setBusy] = useState(false);
	const [job, setJob] = useJob((j) => { toast(j.result.findings + ' megállapítás a crawl alapján.'); setDraftRun(null); reload(); onImported(); });
	const importing = job && (job.status === 'queued' || job.status === 'running');
	if (loading && !data) return html`<${Spinner} />`;
	const online = (data.agents || []).filter((a) => a.online);
	const active = data.runs.find((r) => ['queued', 'running', 'importing'].includes(r.status) && r.source === 'agent');
	const startCrawl = async () => {
		try { await api('/projects/' + project.id + '/crawls', { method: 'POST', body: {} }); toast(online.length ? 'Crawl kérése elküldve az ügynöknek.' : 'A crawl sorba került; akkor indul, amikor az ügynök jelentkezik.'); reload(); } catch (e) { toast(errorText(e), 'error'); }
	};
	const onFiles = async (files) => {
		setBusy(true);
		let run = draftRun;
		for (const f of files) {
			try { run = await upload('/projects/' + project.id + '/crawls/upload' + (run ? '?run_id=' + run.id : ''), f); } catch (e) { toast(f.name + ': ' + errorText(e), 'error'); }
		}
		setDraftRun(run);
		setBusy(false);
	};
	const runImport = async () => {
		try { setJob(await api('/crawls/' + draftRun.id + '/import', { method: 'POST' })); } catch (e) { toast(errorText(e), 'error'); }
	};
	const cancel = async (r) => { try { await api('/crawls/' + r.id, { method: 'DELETE' }); reload(); } catch (e) { toast(errorText(e), 'error'); } };
	const recognized = draftRun ? draftRun.files.flatMap((f) => f.recognized || []) : [];
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="crawl" /> Screaming Frog</h2>
			<span class=${'small ' + (online.length ? 'ok' : 'muted')} title=${(data.agents || []).map((a) => a.name + ': ' + fmt.ago(a.last_seen_at)).join('\n')}>
				${online.length ? '● Ügynök online: ' + online.map((a) => a.name).join(', ') : '○ Nincs online ügynök'}</span>
		</header>
		<div class="pad stack-sm">
			${editable ? html`<div class="row-edit">
				<button class="btn btn--sm" disabled=${!!active} onClick=${startCrawl}><${Icon} name="crawl" size="14" /> Crawl indítása</button>
				<span class="muted small">${active ? active.status_label + ' – ' + (active.message || '') : 'https://' + project.domain + '/ – a beállított export fülekkel'}</span>
				${active ? html`<button class="btn btn--ghost btn--sm" onClick=${() => cancel(active)}>Megszakítás</button>` : ''}
			</div>
			<div class="divider-label">vagy exportok kézi feltöltése</div>
			<${DropZone} busy=${busy} onFiles=${onFiles} accept=".csv,.xlsx,.zip" label="Screaming Frog exportok (CSV, XLSX vagy ZIP) – pl. response_codes_client_error_(4xx).xlsx, internal_all.csv" />
			${recognized.length ? html`<ul class="recognized">${recognized.map((r, i) => html`<li key=${i} class=${r.issue_key ? '' : 'muted'}><${Icon} name=${r.issue_key ? 'check' : 'x'} size="13" /> <span class="url">${r.name}</span> – ${r.label}</li>`)}</ul>
				<div class="row-edit"><button class="btn btn--sm" disabled=${importing} onClick=${runImport}>${importing ? 'Feldolgozás…' : 'Feldolgozás'}</button>
				<button class="btn btn--ghost btn--sm" onClick=${() => { cancel(draftRun); setDraftRun(null); }}>Elvetés</button></div>` : ''}` : ''}
			${data.runs.filter((r) => r.status !== 'draft').length ? html`<details class="runs"><summary class="small muted">Korábbi futások (${data.runs.filter((r) => r.status !== 'draft').length})</summary>
				<table class="table table--dense"><tbody>${data.runs.filter((r) => r.status !== 'draft').map((r) => html`<tr key=${r.id}>
					<td class="small">${fmt.datetime(r.created_at)}</td><td class="small">${r.source === 'agent' ? 'Ügynök' + (r.agent ? ' (' + r.agent + ')' : '') : 'Feltöltés'}</td>
					<td><${Pill} kind=${RUN_KIND[r.status]} title=${r.error}>${r.status_label}</${Pill}></td>
					<td class="small">${r.stats && r.stats.urls ? fmt.num(r.stats.urls) + ' URL' : ''} ${r.error ? html`<span class="danger">${r.error}</span>` : r.message}</td>
				</tr>`)}</tbody></table></details>` : ''}
		</div>
	</section>`;
}

export function Audit({ project, params }) {
	const { me } = useApp();
	const editable = can(me, 'audit.edit');
	const [data, loading, error, reload, setData] = useLoad('/projects/' + project.id + '/audit');
	const [busy, setBusy] = useState(false);
	if (loading && !data) return html`<${Spinner} />`;
	if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
	const patchTopic = async (topic, body) => {
		try { const t = await api('/projects/' + project.id + '/audit/topics/' + topic, { method: 'PATCH', body }); setData((d) => ({ ...d, topics: d.topics.map((x) => (x.topic === topic ? t : x)) })); } catch (e) { toast(errorText(e), 'error'); }
	};
	const patchFinding = async (f, body) => {
		try { await api('/audit/findings/' + f.id, { method: 'PATCH', body }); reload(); } catch (e) { toast(errorText(e), 'error'); }
	};
	const act = async (path, msg) => {
		setBusy(true);
		try { const r = await api(path, { method: 'POST' }); toast(msg(r)); reload(); } catch (e) { toast(errorText(e), 'error'); } finally { setBusy(false); }
	};
	const hasFindings = data.topics.some((t) => t.findings.length);
	const board = ['XL', 'M', 'S'].map((sz) => [sz, data.topics.filter((t) => t.size === sz && t.included)]);
	const last = data.last_run;
	return html`<div class="grid grid--overview">
		<div class="stack">
			${last ? html`<div class="stats">
				<div class="stat"><span class="stat__label">Crawlolt URL</span><span class="stat__value">${fmt.num(last.stats.urls)}</span><span class="stat__hint">${fmt.date(last.finished_at || last.created_at)}</span></div>
				<div class="stat"><span class="stat__label">HTML oldal</span><span class="stat__value">${fmt.num(last.stats.html)}</span></div>
				<div class="stat"><span class="stat__label">Indexelhető</span><span class="stat__value">${fmt.num(last.stats.indexable)}</span></div>
				<div class="stat"><span class="stat__label">Nyitott</span><span class="stat__value">${data.topics.reduce((n, t) => n + t.open, 0)}</span><span class="stat__hint">megállapítás</span></div>
			</div>` : ''}
			<div class="toolbar">
				${editable ? html`<button class="btn btn--ghost btn--sm" disabled=${busy} onClick=${() => act('/projects/' + project.id + '/audit/site-checks', (r) => 'Ellenőrizve: HTTPS ' + (r.https_redirect ? '✓' : '✗') + ', security.txt ' + (r.security_txt ? '✓' : '✗') + ', robots.txt ' + (r.robots_txt ? '✓' : '✗') + ', sitemap ' + (r.sitemap ? '✓' : '✗'))}>
					<${Icon} name="check" size="14" /> Helyszíni ellenőrzés</button>` : ''}
				${can(me, 'tasks.edit') && hasFindings ? html`<button class="btn btn--ghost btn--sm" disabled=${busy} onClick=${() => act('/projects/' + project.id + '/audit/tasks', (r) => r.created + ' új, ' + r.updated + ' frissített fejlesztői feladat.')}>
					<${Icon} name="tasks" size="14" /> Fejlesztői feladatok</button>` : ''}
				<span class="toolbar__spacer"></span>
				${hasFindings ? html`<button class="btn btn--ghost btn--sm" onClick=${() => download('/projects/' + project.id + '/audit/export.xlsx', project.domain + ' technikai audit.xlsx')}><${Icon} name="download" size="14" /> Audit XLSX</button>` : ''}
				${hasFindings && can(me, 'documents.view') ? html`<a class="btn btn--sm" href=${'#/projects/' + project.id + '?tab=documents'}><${Icon} name="doc" size="14" /> Audit dokumentum</a>` : ''}
			</div>
			${hasFindings ? data.topics.map((t) => html`<${Topic} key=${t.topic} t=${t} editable=${editable} onPatchTopic=${patchTopic} onOpen=${(id) => setParam('finding', String(id))} />`)
				: html`<${Empty} icon="bug" title="Még nincs audit adat">Indíts crawlt a Screaming Frog ügynökkel, vagy töltsd fel a Screaming Frogból exportált táblákat. A hibák a HelloProVision audit-sablon témáiba, XL / M / S prioritással kerülnek.</${Empty}>`}
		</div>
		<div class="stack">
			<${Crawler} project=${project} onImported=${reload} />
			${hasFindings ? html`<section class="card"><header class="card__head"><h2>Prioritási lista</h2></header>
				<div class="size-board">${board.map(([sz, list]) => html`<div key=${sz}><h4><${Pill} kind=${sz}>${sz}</${Pill}></h4>
					${list.map((t) => html`<a key=${t.topic} class=${'size-board__item' + (t.open ? '' : ' muted')} href=${'#topic-' + t.topic} onClick=${(e) => { e.preventDefault(); document.getElementById('topic-' + t.topic).scrollIntoView({ behavior: 'smooth' }); }}>${t.title}${t.open ? html` <span class="count">${t.open}</span>` : ''}</a>`)}</div>`)}</div>
			</section>` : ''}
		</div>
		${params.finding ? html`<${FindingDrawer} id=${Number(params.finding)} editable=${editable} onClose=${() => setParam('finding', '')} onPatch=${patchFinding} />` : ''}
	</div>`;
}
