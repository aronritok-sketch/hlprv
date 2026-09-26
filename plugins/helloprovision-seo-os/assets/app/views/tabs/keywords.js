/**
 * Kulcsszavak: kulcsszó × lokáció tábla szűrőkkel, tömeges műveletekkel, részletpanellel és XLSX exporttal.
 * Az AI-elemzés (szándék, klaszter, prioritás, javasolt URL) oszlopai a 3. ütemtől jelennek meg.
 */
import { html, useState, useMemo, useApp, api, download, useLoad, toast, errorText, fmt, Icon, Spinner, ErrorBox, Empty, Drawer, Modal, Field, Select, Textarea, InlineEdit, Pill, Checkbox, can } from '../../ui.js';
import { KeywordsAI, AnalysisPanel, ClusterView } from './keywords-ai.js';

const PAGE = 200;

function Kd({ v }) {
	if (v === null || v === undefined) return html`<span class="muted">–</span>`;
	const cls = v < 20 ? 'easy' : v < 50 ? 'mid' : 'hard';
	return html`<span class=${'kd kd--' + cls}>${v}</span>`;
}

function metricFor(k, location) {
	if (!location) return k;
	const m = k.metrics.filter((x) => x.location === location);
	if (!m.length) return { volume: null, kd: null, cpc: null, currency: '', traffic_potential: null };
	const pick = (f) => { const v = m.map((x) => x[f]).filter((x) => x !== null && x !== undefined); return v.length ? v[0] : null; };
	const withCpc = m.find((x) => x.cpc !== null && x.cpc !== undefined);
	return { volume: pick('volume'), kd: pick('kd'), cpc: pick('cpc'), currency: withCpc ? withCpc.currency : '', traffic_potential: pick('traffic_potential') };
}

function Cpc({ v, currency }) {
	if (v === null || v === undefined) return html`<span class="muted">–</span>`;
	const digits = currency === 'HUF' ? 0 : 2;
	return html`${Number(v).toLocaleString('hu-HU', { minimumFractionDigits: digits, maximumFractionDigits: digits })}${currency ? html` <span class="muted small">${currency}</span>` : ''}`;
}

function Detail({ project, k, onClose, onChange }) {
	const { me, meta } = useApp();
	const editable = can(me, 'keywords.edit');
	const patch = async (body) => {
		try { onChange(await api('/projects/' + project.id + '/keywords/' + k.id, { method: 'PATCH', body })); } catch (e) { toast(errorText(e), 'error'); }
	};
	return html`<${Drawer} title=${k.term} subtitle=${[k.term_location, k.parent_topic && 'Parent topic: ' + k.parent_topic].filter(Boolean).join(' · ')} onClose=${onClose}>
		<div class="stack">
			<${AnalysisPanel} project=${project} k=${k} onChange=${onChange} />
			<div class="form-grid">
				<${Field} label="Magyar fordítás"><${InlineEdit} value=${k.translation} disabled=${!editable} onSave=${(v) => patch({ translation: v })} /></${Field}>
				<${Field} label="Kategória"><${InlineEdit} value=${k.category} disabled=${!editable} onSave=${(v) => patch({ category: v })} /></${Field}>
				<${Field} label="Megjegyzés" wide><${InlineEdit} multiline value=${k.notes} disabled=${!editable} onSave=${(v) => patch({ notes: v })} /></${Field}>
			</div>
			${editable ? html`<div class="row-edit">
				${k.is_excluded
					? html`<span class="muted">Kizárva: ${k.exclusion_reason}</span><button class="btn btn--ghost btn--sm" onClick=${() => patch({ is_excluded: false })}>Visszavétel</button>`
					: html`<button class="btn btn--ghost btn--sm" onClick=${() => patch({ is_excluded: true, exclusion_reason: 'Kézzel kizárva' })}>Kizárás</button>`}
			</div>` : ''}
			${k.source_intents.length || k.serp_features.length ? html`<div class="chips">
				${k.source_intents.map((i) => html`<span key=${i} class="chip">${i}</span>`)}
				${k.serp_features.map((i) => html`<span key=${i} class="chip">${i}</span>`)}
			</div>` : ''}
			<section class="card"><header class="card__head"><h2>Mérőszámok lokációnként</h2></header>
				<table class="table table--dense"><thead><tr><th>Lokáció</th><th>Forrás</th><th class="num">Volumen</th><th class="num">KD</th><th class="num">CPC</th><th class="num">TP</th></tr></thead>
				<tbody>${k.metrics.map((m, i) => html`<tr key=${i}><td>${m.location || 'Országos'}</td><td class="muted">${(meta.import_sources || {})[m.source] || m.source}</td><td class="num">${fmt.num(m.volume)}</td><td class="num"><${Kd} v=${m.kd} /></td><td class="num"><${Cpc} v=${m.cpc} currency=${m.currency} /></td><td class="num">${fmt.num(m.traffic_potential)}</td></tr>`)}</tbody></table>
			</section>
			<section class="card"><header class="card__head"><h2>Pozíciók</h2>${k.own_position ? html`<${Pill} kind="ok">Saját: #${k.own_position}</${Pill}>` : ''}</header>
				${k.competitors.length ? html`<table class="table table--dense"><thead><tr><th>Domain</th><th class="num">Pozíció</th><th>Lokáció</th><th>URL</th></tr></thead>
					<tbody>${k.competitors.map((c, i) => html`<tr key=${i}><td>${c.domain}</td><td class="num">${c.position}</td><td>${c.location || '–'}</td><td class="url"><a class="link" href=${c.url} target="_blank" rel="noopener">${c.url.replace(/^https?:\/\//, '').slice(0, 60)}</a></td></tr>`)}</tbody></table>`
					: html`<p class="muted pad">Nincs versenytárs-pozíció.</p>`}
			</section>
		</div>
	</${Drawer}>`;
}

function AddKeywords({ project, onClose, onDone }) {
	const [text, setText] = useState('');
	const save = async () => {
		try {
			const r = await api('/projects/' + project.id + '/keywords', { method: 'POST', body: { terms: text.split('\n').map((s) => s.trim()).filter(Boolean) } });
			toast(r.created + ' új kulcsszó.');
			onDone();
			onClose();
		} catch (e) { toast(errorText(e), 'error'); }
	};
	return html`<${Modal} title="Kulcsszavak hozzáadása" onClose=${onClose} footer=${html`<button class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" onClick=${save}>Hozzáadás</button>`}>
		<${Field} label="Soronként egy kulcsszó"><${Textarea} rows="10" value=${text} onInput=${setText} /></${Field}>
	</${Modal}>`;
}

export function Keywords({ project, params }) {
	const { me, meta } = useApp();
	const [data, loading, error, reload, setData] = useLoad('/projects/' + project.id + '/keywords');
	const [q, setQ] = useState('');
	const [location, setLocation] = useState('');
	const [status, setStatus] = useState('active');
	const [minVol, setMinVol] = useState('');
	const [filters, setFilters] = useState({});
	const [showComp, setShowComp] = useState(false);
	const [selected, setSelected] = useState(new Set());
	const [open, setOpen] = useState(null);
	const [adding, setAdding] = useState(false);
	const [limit, setLimit] = useState(PAGE);
	const [sort, setSort] = useState({ key: 'auto', dir: 'desc' });
	const [view, setView] = useState(params.view || 'table');
	const editable = can(me, 'keywords.edit');

	const rows = useMemo(() => {
		if (!data) return [];
		const needle = q.trim().toLowerCase();
		const min = Number(minVol) || 0;
		let list = data.keywords.map((k) => ({ ...k, _m: metricFor(k, location) }));
		list = list.filter((k) => {
			if (status === 'active' && k.is_excluded) return false;
			if (status === 'excluded' && !k.is_excluded) return false;
			if (status === 'seed' && !k.is_seed) return false;
			if (location && !k.locations.includes(location) && k.term_location !== location) return false;
			if (needle && !(k.term.toLowerCase().includes(needle) || (k.translation || '').toLowerCase().includes(needle) || (k.parent_topic || '').toLowerCase().includes(needle))) return false;
			if (min && (k._m.volume || 0) < min) return false;
			for (const [f, v] of Object.entries(filters)) {
				if (v && String(k[f] ?? '') !== v) return false;
			}
			return true;
		});
		// Alapértelmezés: elemzés után a prioritási pontszám, előtte a volumen szerint.
		const key = sort.key === 'auto' ? (data.keywords.some((k) => k.analysis) ? 'priority_score' : 'volume') : sort.key;
		const get = (k) => (['volume', 'kd', 'cpc', 'traffic_potential'].includes(key) ? k._m[key] : k[key]);
		list.sort((a, b) => {
			const x = get(a); const y = get(b);
			if (x === y) return a.term.localeCompare(b.term);
			if (x === null || x === undefined || x === '') return 1;
			if (y === null || y === undefined || y === '') return -1;
			return (x > y ? 1 : -1) * (sort.dir === 'desc' ? -1 : 1);
		});
		return list;
	}, [data, q, location, status, minVol, filters, sort]);

	if (loading && !data) return html`<${Spinner} />`;
	if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
	if (!data.keywords.length) {
		return html`<${Empty} icon="key" title="Még nincs kulcsszó" action=${editable ? html`<div class="row-edit">
			<a class="btn" href=${'#/projects/' + project.id + '?tab=research'}>Export feltöltése</a>
			<button class="btn btn--ghost" onClick=${async () => { const r = await api('/projects/' + project.id + '/keywords/from-seeds', { method: 'POST' }); toast(r.created + ' kulcsszó a kiinduló listából.'); reload(); }}>Kiinduló kulcsszavakból</button>
		</div>` : ''}>Tölts fel Ahrefs vagy Keyword Planner exportot a Kutatás fülön.</${Empty}>`;
	}

	const hasAnalysis = data.keywords.some((k) => k.analysis);
	const updateRow = (row) => setData((d) => ({ ...d, keywords: d.keywords.map((k) => (k.id === row.id ? row : k)) }));
	const bulk = async (action, value) => {
		try {
			const r = await api('/projects/' + project.id + '/keywords/bulk', { method: 'POST', body: { ids: [...selected], action, value } });
			toast(r.changed + ' kulcsszó módosítva.');
			setSelected(new Set());
			reload();
		} catch (e) { toast(errorText(e), 'error'); }
	};
	const th = (key, label, cls = '') => html`<th class=${'is-sortable ' + cls} onClick=${() => setSort((s) => (s.key === key ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: ['term', 'parent_topic'].includes(key) ? 'asc' : 'desc' }))}>${label}${sort.key === key ? (sort.dir === 'asc' ? ' ↑' : ' ↓') : ''}</th>`;
	const visible = rows.slice(0, limit);
	const allSel = visible.length && visible.every((r) => selected.has(r.id));
	const comps = data.competitors.slice(0, 6);

	return html`<div class="stack">
		<${KeywordsAI} project=${project} data=${data} reload=${reload} filters=${filters} setFilters=${setFilters} view=${view} setView=${setView} />
		${view === 'clusters' && hasAnalysis ? html`<${ClusterView} project=${project} data=${data} onOpen=${(k) => setOpen(k)} />` : html`
		<div class="toolbar">
			<div class="search"><${Icon} name="search" /><input value=${q} onInput=${(e) => { setQ(e.target.value); setLimit(PAGE); }} placeholder="Keresés kulcsszóra, fordításra, parent topicra…" /></div>
			<${Select} value=${status} onChange=${setStatus} options=${{ active: 'Aktív', all: 'Mind', excluded: 'Kizárt', seed: 'Kiinduló' }} />
			<${Select} value=${location} onChange=${setLocation} options=${data.locations.map((l) => [l, l])} placeholder="Minden lokáció" />
			<input class="input" style="width:120px" type="number" min="0" placeholder="Min. volumen" value=${minVol} onInput=${(e) => setMinVol(e.target.value)} />
			<${Checkbox} checked=${showComp} onChange=${setShowComp} label="Versenytársak" />
			<span class="toolbar__spacer"></span>
			${editable ? html`<button class="btn btn--ghost btn--sm" onClick=${() => setAdding(true)}><${Icon} name="plus" /> Kulcsszó</button>` : ''}
			<button class="btn btn--sm" onClick=${() => download('/projects/' + project.id + '/export/keyword-research.xlsx?translation=' + (data.keywords.some((k) => k.translation) ? 'true' : 'false'))}><${Icon} name="download" /> Kulcsszókutatás XLSX</button>
		</div>
		<p class="muted small">${fmt.num(rows.length)} kulcsszó${location ? ' · ' + location + ' mérőszámai' : ''}</p>
		<div class="card"><div class="table-wrap table-scroll"><table class="table table--dense kw-grid">
			<thead><tr>
				${editable ? html`<th class="col-check"><input type="checkbox" checked=${allSel} onChange=${(e) => setSelected(e.target.checked ? new Set(visible.map((r) => r.id)) : new Set())} /></th>` : ''}
				${th('term', 'Kulcsszó')}
				${hasAnalysis ? html`${th('intent', 'Szándék')}${th('cluster', 'Klaszter')}${th('priority', 'Prioritás')}${th('url', 'Javasolt URL')}` : ''}
				${th('volume', 'Volumen', 'num')}${th('kd', 'KD', 'num')}${th('cpc', 'CPC', 'num')}${th('traffic_potential', 'TP', 'num')}
				${th('parent_topic', 'Parent topic')}
				${th('own_position', 'Saját', 'num')}${th('best_competitor_position', 'Legjobb vers.', 'num')}
				${showComp ? comps.map((d) => html`<th key=${d} class="num" title=${d}>${d.split('.')[0].slice(0, 12)}</th>`) : ''}
			</tr></thead>
			<tbody>${visible.map((k) => html`<tr key=${k.id} class=${'is-clickable' + (k.is_excluded ? ' is-excluded' : '') + (selected.has(k.id) ? ' is-selected' : '')}
				onClick=${(e) => { if (!e.target.closest('input,button,a')) setOpen(k); }}>
				${editable ? html`<td class="col-check"><input type="checkbox" checked=${selected.has(k.id)} onChange=${(e) => { const n = new Set(selected); if (e.target.checked) n.add(k.id); else n.delete(k.id); setSelected(n); }} /></td>` : ''}
				<td class="kw-term"><strong>${k.term}</strong>${k.is_seed ? html` <span title="Kiinduló kulcsszó">★</span>` : ''}
					${k.translation ? html`<div class="muted small">${k.translation}</div>` : ''}
					${k.is_excluded ? html`<div class="muted small">${k.exclusion_reason}</div>` : ''}</td>
				${hasAnalysis ? html`
					<td>${k.intent ? html`<span class="chip">${(meta.intents || {})[k.intent] || k.intent}</span>` : html`<span class="muted">–</span>`}${k.is_local ? html` <span class="chip" title="Lokális">📍</span>` : ''}</td>
					<td class="small">${k.cluster || '–'}</td>
					<td>${k.priority ? html`<${Pill} kind=${k.priority}>${k.priority === 'parked' ? 'Félretett' : k.priority}</${Pill}>` : '–'}${k.role === 'primary' ? html` <span class="chip chip--primary">primary</span>` : ''}</td>
					<td class="url">${k.url || '–'}</td>` : ''}
				<td class="num">${fmt.num(k._m.volume)}</td>
				<td class="num"><${Kd} v=${k._m.kd} /></td>
				<td class="num"><${Cpc} v=${k._m.cpc} currency=${k._m.currency} /></td>
				<td class="num">${fmt.num(k._m.traffic_potential)}</td>
				<td class="small">${k.parent_topic || '–'}</td>
				<td class="num">${k.own_position ? '#' + k.own_position : '–'}</td>
				<td class="num">${k.best_competitor_position ? '#' + k.best_competitor_position : '–'}</td>
				${showComp ? comps.map((d) => { const c = k.competitors.find((x) => x.domain === d && (!location || !x.location || x.location === location)); return html`<td key=${d} class="num">${c ? c.position : ''}</td>`; }) : ''}
			</tr>`)}</tbody>
		</table></div></div>
		${rows.length > limit ? html`<button class="btn btn--ghost" onClick=${() => setLimit(limit + PAGE)}>További ${Math.min(PAGE, rows.length - limit)} betöltése</button>` : ''}
		${selected.size ? html`<div class="bulkbar">
			<strong>${selected.size} kijelölve</strong>
			<button class="btn btn--ghost btn--sm" onClick=${() => bulk('exclude', 'Nem releváns')}>Kizárás</button>
			<button class="btn btn--ghost btn--sm" onClick=${() => bulk('include')}>Visszavétel</button>
			<button class="btn btn--ghost btn--sm" onClick=${() => { const v = prompt('Kategória'); if (v !== null) bulk('category', v); }}>Kategória</button>
			<button class="btn btn--danger btn--sm" onClick=${() => { if (confirm(selected.size + ' kulcsszó végleges törlése?')) bulk('delete'); }}>Törlés</button>
			<button class="link" onClick=${() => setSelected(new Set())}>Mégse</button>
		</div>` : ''}
		`}
		${open ? html`<${Detail} project=${project} k=${data.keywords.find((x) => x.id === open.id) || open} onClose=${() => setOpen(null)} onChange=${updateRow} />` : ''}
		${adding ? html`<${AddKeywords} project=${project} onClose=${() => setAdding(false)} onDone=${reload} />` : ''}
	</div>`;
}
