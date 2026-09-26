/**
 * Kutatás: exportok feltöltése (Ahrefs, Keyword Planner, saját tábla, Screaming Frog), oszlop-hozzárendelés előnézettel,
 * importtörténet, API-lekérések (5. ütem), stílusbeli irányelvek.
 */
import { html, useState, useEffect, useApp, api, upload, download, useLoad, useJob, toast, errorText, fmt, Icon, Spinner, DropZone, Field, Select, Input, Textarea, DataTable, Pill, Modal, can, navigate } from '../../ui.js';
import { ApiFetch } from './research-api.js';

const FIELD_LABELS = {
	term: 'Kulcsszó', volume: 'Volumen', kd: 'Nehézség (KD)', cpc: 'CPC', traffic_potential: 'Traffic potential',
	parent_topic: 'Parent topic', intents: 'Intents (Ahrefs)', serp_features: 'SERP features', location: 'Lokáció',
	modifier: 'Local long-tail módosító', translation: 'Magyar fordítás', category: 'Kategória', competition: 'Competition',
	own_position: 'Saját pozíció', own_url: 'Saját URL', own_traffic: 'Saját forgalom', sv_trend: 'Trend',
};

function Preview({ project, data, onClose, onDone }) {
	const { meta } = useApp();
	const [imp, setImp] = useState(data.import);
	const [det, setDet] = useState(data.detection);
	const [busy, setBusy] = useState(false);
	const [job, setJob] = useJob((j) => { toast('Import kész: ' + (j.result.created || 0) + ' új kulcsszó, ' + (j.result.merged || 0) + ' összevonva.'); onDone(); onClose(); });
	const sources = meta.import_sources || {};
	const cols = det.columns.filter(Boolean);

	const patch = async (body) => {
		try {
			const r = await api('/projects/' + project.id + '/imports/' + imp.id, { method: 'PATCH', body });
			setImp(r.import);
			if (r.detection) setDet(r.detection);
		} catch (e) { toast(errorText(e), 'error'); }
	};
	const run = async () => {
		setBusy(true);
		try {
			const r = await api('/projects/' + project.id + '/imports/' + imp.id + '/run', { method: 'POST' });
			if (r.job.status === 'done') { toast('Import kész: ' + (r.job.result.created || 0) + ' új kulcsszó, ' + (r.job.result.merged || 0) + ' összevonva.'); onDone(); onClose(); }
			else if (r.job.status === 'failed') { toast(r.job.error, 'error'); setBusy(false); }
			else setJob(r.job);
		} catch (e) { toast(errorText(e), 'error'); setBusy(false); }
	};
	const isSF = imp.source === 'screaming_frog';

	return html`<${Modal} wide title=${'Import előnézet – ' + imp.filename} onClose=${onClose} footer=${html`
		<button class="btn btn--ghost" onClick=${onClose}>Mégse</button>
		${isSF ? html`<button class="btn" onClick=${() => { onClose(); navigate('/projects/' + project.id, { tab: 'audit' }); }}>Tovább a technikai audithoz</button>`
			: html`<button class="btn" disabled=${busy || !imp.column_map.term} onClick=${run}>${job ? 'Import folyamatban… ' + Math.round((job.progress || 0) * 100) + '%' : busy ? 'Indul…' : 'Import indítása (' + det.row_count + ' sor)'}</button>`}`}>
		<div class="stack-sm">
			${isSF ? html`<div class="alert alert--info">Screaming Frog export (${det.sf_issue}). Ezt a Technikai audit fül dolgozza fel.</div>` : ''}
			<div class="form-grid">
				<${Field} label="Forrás"><${Select} value=${imp.source} onChange=${(v) => patch({ source: v })} options=${sources} /></${Field}>
				<${Field} label="Munkalap"><${Select} value=${imp.sheet} onChange=${(v) => patch({ sheet: v })} options=${(det.sheets || [imp.sheet]).map((s) => [s, s])} /></${Field}>
				${!imp.column_map.location ? html`<${Field} label="Lokáció" hint="Ha az export egy városra szól (pl. Keyword Planner városi lekérés). Üresen: országos.">
					<${Select} value=${imp.location} onChange=${(v) => patch({ location: v })} options=${project.locations.map((l) => [l, l])} placeholder="Országos / nincs" /></${Field}>` : ''}
			</div>
			${!isSF ? html`<details open><summary class="subhead">Oszlopok hozzárendelése</summary>
				<div class="form-grid">${Object.entries(FIELD_LABELS).map(([fld, label]) => html`<${Field} key=${fld} label=${label}>
					<${Select} value=${imp.column_map[fld] || ''} onChange=${(v) => { const m = { ...imp.column_map }; if (v) m[fld] = v; else delete m[fld]; patch({ column_map: m }); }} options=${cols.map((c) => [c, c])} placeholder="–" />
				</${Field}>`)}</div>
			</details>` : ''}
			${Object.keys(imp.competitor_map || {}).length ? html`<p class="small muted">Versenytárs-oszlopok: ${Object.keys(imp.competitor_map).join(', ')}</p>` : ''}
			<div class="card"><div class="table-wrap"><table class="table table--dense">
				<thead><tr>${cols.slice(0, 10).map((c) => html`<th key=${c}>${c}</th>`)}</tr></thead>
				<tbody>${det.preview.map((r, i) => html`<tr key=${i}>${cols.slice(0, 10).map((c) => html`<td key=${c}>${r[c] ?? ''}</td>`)}</tr>`)}</tbody>
			</table></div></div>
		</div>
	</${Modal}>`;
}

function StyleGuide({ project }) {
	const { me } = useApp();
	const [data, loading, , reload] = useLoad('/projects/' + project.id + '/style-guide');
	const [answers, setAnswers] = useState(null);
	const [brand, setBrand] = useState('');
	useEffect(() => { if (data) { setAnswers(data.answers); setBrand(data.brand_name); } }, [data]);
	if (loading && !data) return html`<${Spinner} />`;
	const editable = can(me, 'strategy.status');
	const save = async (mark) => {
		try {
			await api('/projects/' + project.id + '/style-guide', { method: 'PUT', body: { brand_name: brand, answers, mark_received: mark } });
			toast(mark ? 'Megérkezettnek jelölve.' : 'Mentve.');
			reload();
		} catch (e) { toast(errorText(e), 'error'); }
	};
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="edit" /> Stílusbeli irányelvek</h2>
			<div class="row-edit">
				${data.received_at ? html`<${Pill} kind="ok">Megérkezett ${fmt.date(data.received_at)}</${Pill}>` : html`<${Pill} kind="warn">Még nincs kitöltve</${Pill}>`}
				<button class="btn btn--ghost btn--sm" onClick=${() => download('/projects/' + project.id + '/style-guide.docx')}><${Icon} name="download" /> Kiküldhető DOCX</button>
			</div>
		</header>
		<div class="pad stack-sm">
			<p class="muted small">A kiküldhető dokumentum címében az ügyfél domainje szerepel, a márkanév be van írva – nem a sablont küldjük ki.</p>
			<${Field} label="Márkanév"><${Input} value=${brand} onInput=${setBrand} disabled=${!editable} /></${Field}>
			${(data.questions || []).map((q) => html`<${Field} key=${q.key} label=${q.question} hint=${q.hint}>
				<${Textarea} rows="2" value=${(answers || {})[q.key] || ''} disabled=${!editable} onInput=${(v) => setAnswers((a) => ({ ...a, [q.key]: v }))} />
			</${Field}>`)}
			${editable ? html`<div class="row-edit"><button class="btn btn--ghost" onClick=${() => save(false)}>Mentés</button><button class="btn" onClick=${() => save(true)}>Mentés, megérkezett</button></div>` : ''}
		</div>
	</section>`;
}

export function Research({ project }) {
	const { me, meta } = useApp();
	const [imports, loading, , reload] = useLoad('/projects/' + project.id + '/imports');
	const [summary, , , reloadSummary] = useLoad('/projects/' + project.id + '/research/summary');
	const [preview, setPreview] = useState(null);
	const [busy, setBusy] = useState(false);
	const editable = can(me, 'research.edit');

	const onFiles = async (files) => {
		setBusy(true);
		for (const f of files) {
			try {
				const r = await upload('/projects/' + project.id + '/imports', f);
				setPreview(r);
			} catch (e) { toast(f.name + ': ' + errorText(e), 'error'); }
		}
		setBusy(false);
		reload();
	};
	const remove = async (id) => {
		try { await api('/projects/' + project.id + '/imports/' + id, { method: 'DELETE' }); reload(); } catch (e) { toast(e.message, 'error'); }
	};
	const statusKind = { done: 'ok', failed: 'danger', preview: '', queued: 'warn', running: 'warn' };
	const statusLabel = { done: 'Kész', failed: 'Hiba', preview: 'Előnézet', queued: 'Sorban', running: 'Fut' };

	return html`<div class="grid grid--overview">
		<div class="stack">
			${summary ? html`<div class="stats">
				<div class="stat"><span class="stat__label">Kulcsszó</span><span class="stat__value">${fmt.num(summary.keywords)}</span></div>
				<div class="stat"><span class="stat__label">Aktív</span><span class="stat__value">${fmt.num(summary.active)}</span></div>
				<div class="stat"><span class="stat__label">Kizárt</span><span class="stat__value">${fmt.num(summary.excluded)}</span></div>
				<div class="stat"><span class="stat__label">Import</span><span class="stat__value">${summary.imports}</span></div>
			</div>` : ''}
			${editable ? html`<section class="card"><header class="card__head"><h2><${Icon} name="upload" /> Export feltöltése</h2></header>
				<div class="pad stack-sm">
					<${DropZone} busy=${busy} onFiles=${onFiles} accept=".xlsx,.csv,.tsv,.txt" label="Ahrefs, Keyword Planner, saját kulcsszókutatás tábla vagy Screaming Frog export (XLSX, CSV)" />
					<p class="muted small">A rendszer felismeri a forrást és az oszlopokat; indítás előtt ellenőrizheted. Ugyanaz a kulcsszó több fájlból összevonódik, a városonkénti adatok külön megmaradnak.</p>
				</div>
			</section>` : ''}
			<${ApiFetch} project=${project} onDone=${() => { reload(); reloadSummary(); }} />
			<section class="card"><header class="card__head"><h2><${Icon} name="refresh" /> Importtörténet</h2></header>
				${loading && !imports ? html`<${Spinner} />` : html`<${DataTable} rows=${imports || []} empty="Még nincs import." columns=${[
					{ key: 'filename', label: 'Fájl', render: (r) => html`<div class="cell-main"><strong>${r.filename || r.source_label}</strong><span class="muted">${r.source_label}${r.location ? ' · ' + r.location : ''}</span></div>` },
					{ key: 'status', label: 'Állapot', render: (r) => html`<${Pill} kind=${statusKind[r.status]} title=${r.error}>${statusLabel[r.status] || r.status}</${Pill}>` },
					{ key: 'row_count', label: 'Sor', class: 'num', num: true, render: (r) => fmt.num(r.row_count) },
					{ key: 'stats', label: 'Új / összevont', sort: false, render: (r) => (r.stats && r.stats.rows ? (r.stats.created || 0) + ' / ' + (r.stats.merged || 0) : '–') },
					{ key: 'created_at', label: 'Idő', num: true, render: (r) => fmt.datetime(r.created_at) },
					{ key: 'x', label: '', sort: false, render: (r) => html`<div class="row-edit">
						${editable && (r.status === 'preview' || r.status === 'failed') ? html`<button class="btn btn--ghost btn--sm" onClick=${async () => { try { setPreview(await api('/projects/' + project.id + '/imports/' + r.id)); } catch (e) { toast(e.message, 'error'); } }}>Folytatás</button>` : ''}
						${editable ? html`<button class="icon-btn" title="Törlés" onClick=${() => remove(r.id)}><${Icon} name="trash" /></button>` : ''}
					</div>` },
				]} />`}
			</section>
		</div>
		<div class="stack">
			<${StyleGuide} project=${project} />
		</div>
		${preview ? html`<${Preview} project=${project} data=${preview} onClose=${() => setPreview(null)} onDone=${() => { reload(); reloadSummary(); }} />` : ''}
	</div>`;
}
