/**
 * Kulcsszavak fül – AI rész: elemzés indítása, összesítő, szűrők (szándék, prioritás, csoport, klaszter),
 * javaslatok elfogadása, klaszternézet, és a részletpanel elemzési szakasza.
 */
import { html, useState, useApp, api, useLoad, useJob, toast, errorText, fmt, Icon, Select, Pill, Field, Textarea, can } from '../../ui.js';

const METHOD = { llm: 'AI (OpenAI)', heuristic: 'szabályalapú (nincs API kulcs)', manual: 'kézi' };

export function KeywordsAI({ project, data, reload, filters, setFilters, view, setView }) {
	const { me, meta } = useApp();
	const [summary, , , reloadSummary] = useLoad('/projects/' + project.id + '/analysis/summary', [data]);
	const [job, setJob] = useJob((j) => { toast('Elemzés kész: ' + j.result.keywords + ' kulcsszó, ' + j.result.clusters + ' klaszter.'); reload(); reloadSummary(); });
	const running = job && (job.status === 'queued' || job.status === 'running');
	const clusters = [...new Set(data.keywords.map((k) => k.cluster).filter(Boolean))].sort();
	const start = async () => {
		try {
			const j = await api('/projects/' + project.id + '/analysis/run', { method: 'POST' });
			if (j.status === 'done') { toast('Elemzés kész.'); reload(); reloadSummary(); } else if (j.status === 'failed') toast(j.error, 'error'); else setJob(j);
		} catch (e) { toast(errorText(e), 'error'); }
	};
	const accept = async (body) => {
		try { const r = await api('/projects/' + project.id + '/analysis/accept', { method: 'POST', body }); toast(r.accepted + ' javaslat elfogadva.'); reload(); reloadSummary(); } catch (e) { toast(e.message, 'error'); }
	};
	const set = (k) => (v) => setFilters((f) => ({ ...f, [k]: v }));
	const suggested = summary ? summary.by_status.suggested || 0 : 0;

	return html`<section class="card">
		<header class="card__head">
			<h2><${Icon} name="spark" /> Kulcsszó-intelligencia</h2>
			<div class="row-edit">
				${summary && summary.analysed ? html`<span class="muted small">${summary.analysed}/${summary.active} elemezve · ${summary.clusters} klaszter · ${METHOD[summary.method] || ''}</span>` : ''}
				${can(me, 'ai.run') ? html`<button class="btn btn--sm" disabled=${running} onClick=${start}>${running ? 'Elemzés… ' + Math.round((job.progress || 0) * 100) + '%' : summary && summary.analysed ? 'Újraelemzés' : 'AI-elemzés indítása'}</button>` : ''}
			</div>
		</header>
		<div class="pad stack-sm">
			${running && job.message ? html`<p class="muted small">${job.message}</p>` : ''}
			${summary && summary.analysed ? html`<div class="row-edit" style="flex-wrap:wrap">
				${['P1', 'P2', 'parked'].map((p) => html`<${Pill} key=${p} kind=${p}>${meta.priorities[p]}: ${summary.by_priority[p] || 0}</${Pill}>`)}
				<span class="muted small">Átnézendő: ${suggested} · elfogadva: ${summary.by_status.accepted || 0} · átírva: ${summary.by_status.overridden || 0}</span>
				<span class="toolbar__spacer"></span>
				${can(me, 'keywords.edit') && suggested ? html`<button class="btn btn--ghost btn--sm" onClick=${() => accept({ priority: 'P1' })}>P1 javaslatok elfogadása</button>
					<button class="btn btn--ghost btn--sm" onClick=${() => accept({})}>Minden javaslat elfogadása</button>` : ''}
			</div>
			<div class="toolbar" style="margin:0">
				<${Select} value=${filters.intent || ''} onChange=${set('intent')} options=${meta.intents} placeholder="Minden szándék" />
				<${Select} value=${filters.priority || ''} onChange=${set('priority')} options=${meta.priorities} placeholder="Minden prioritás" />
				<${Select} value=${filters.bucket || ''} onChange=${set('bucket')} options=${meta.buckets} placeholder="Minden csoport" />
				<${Select} value=${filters.cluster || ''} onChange=${set('cluster')} options=${clusters.map((c) => [c, c])} placeholder="Minden klaszter" />
				<${Select} value=${filters.analysis || ''} onChange=${set('analysis')} options=${{ suggested: 'Átnézendő', accepted: 'Elfogadott', overridden: 'Átírt' }} placeholder="Minden állapot" />
				<span class="toolbar__spacer"></span>
				<div class="segmented">
					<button class=${view !== 'clusters' ? 'is-active' : ''} onClick=${() => setView('table')}>Tábla</button>
					<button class=${view === 'clusters' ? 'is-active' : ''} onClick=${() => setView('clusters')}>Klaszterek</button>
				</div>
			</div>` : html`<p class="muted small">Az elemzés minden aktív kulcsszóhoz megadja a keresési szándékot, a lokalitást, az üzleti értéket és a kereskedelmi lehetőséget,
				klaszterekbe rendezi őket, és a módszertan sorrendjében (szándék → üzleti érték → kereskedelmi lehetőség → verseny → volumen) prioritást számol.
				Az eredmény javaslat: átnézed, elfogadod vagy átírod.</p>`}
		</div>
	</section>`;
}

export function AnalysisPanel({ project, k, onChange }) {
	const { me, meta } = useApp();
	const [notes, setNotes] = useState(k.analysis_notes || '');
	if (!k.analysis) return null;
	const editable = can(me, 'keywords.edit');
	const patch = async (body) => {
		try { onChange(await api('/projects/' + project.id + '/keywords/' + k.id + '/analysis', { method: 'PATCH', body })); toast('Mentve.'); } catch (e) { toast(errorText(e), 'error'); }
	};
	const accept = async () => {
		try { await api('/projects/' + project.id + '/analysis/accept', { method: 'POST', body: { ids: [k.id] } }); onChange({ ...k, analysis: 'accepted' }); } catch (e) { toast(e.message, 'error'); }
	};
	const statusLabel = { suggested: 'AI-javaslat, átnézendő', accepted: 'Elfogadva', overridden: 'Kézzel átírva' };
	const aiDiff = k.analysis !== 'suggested' && k.ai && (k.ai.intent !== k.intent || k.ai.priority !== k.priority);
	const scale = { 1: '1', 2: '2', 3: '3', 4: '4', 5: '5' };
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="spark" /> Elemzés</h2>
			<div class="row-edit"><${Pill} kind=${k.analysis === 'suggested' ? 'warn' : 'ok'}>${statusLabel[k.analysis]}</${Pill}>
			${editable && k.analysis === 'suggested' ? html`<button class="btn btn--sm" onClick=${accept}>Elfogad</button>` : ''}</div>
		</header>
		<div class="pad stack-sm">
			${k.reason ? html`<p class="small"><span class="muted">Indoklás:</span> ${k.reason}</p>` : ''}
			${aiDiff ? html`<p class="small muted">Az AI legutóbbi javaslata: ${meta.intents[k.ai.intent] || k.ai.intent}, ${meta.priorities[k.ai.priority] || k.ai.priority}</p>` : ''}
			<div class="form-grid">
				<${Field} label="Keresési szándék"><${Select} value=${k.intent} disabled=${!editable} onChange=${(v) => patch({ intent: v })} options=${meta.intents} /></${Field}>
				<${Field} label="Prioritás" hint=${'Pontszám: ' + (k.priority_score || 0).toFixed(2)}><${Select} value=${k.priority} disabled=${!editable} onChange=${(v) => patch({ priority: v })} options=${meta.priorities} /></${Field}>
				<${Field} label="Üzleti érték (1–5)"><${Select} value=${String(k.business_value)} disabled=${!editable} onChange=${(v) => patch({ business_value: Number(v) })} options=${scale} /></${Field}>
				<${Field} label="Kereskedelmi lehetőség (1–5)"><${Select} value=${String(k.commercial_opportunity)} disabled=${!editable} onChange=${(v) => patch({ commercial_opportunity: Number(v) })} options=${scale} /></${Field}>
				<${Field} label="Lokális"><${Select} value=${k.is_local ? '1' : ''} disabled=${!editable} onChange=${(v) => patch({ is_local: v === '1' })} options=${{ 1: 'Igen' }} placeholder="Nem" /></${Field}>
				<${Field} label="Csoport"><span>${meta.buckets[k.bucket] || '–'}</span></${Field}>
				<${Field} label="Klaszter"><span>${k.cluster || '–'}</span></${Field}>
				<${Field} label="Javasolt URL"><span class="url">${k.url || '–'} ${k.role ? html`<span class="chip">${k.role}</span>` : ''}</span></${Field}>
				<${Field} label="Megjegyzés (a kulcsszótérképbe kerül)" wide><${Textarea} rows="2" value=${notes} disabled=${!editable} onInput=${setNotes} onBlur=${() => notes !== (k.analysis_notes || '') && patch({ notes })} /></${Field}>
			</div>
		</div>
	</section>`;
}

export function ClusterView({ project, data, onOpen }) {
	const { me, meta } = useApp();
	const [clusters, loading, , reload] = useLoad('/projects/' + project.id + '/clusters', [data]);
	if (loading && !clusters) return null;
	const byId = Object.fromEntries(data.keywords.map((k) => [k.id, k]));
	const rename = async (c) => {
		const name = prompt('Klaszter neve', c.name);
		if (!name || name === c.name) return;
		try { await api('/projects/' + project.id + '/clusters/' + c.id, { method: 'PATCH', body: { name } }); reload(); } catch (e) { toast(e.message, 'error'); }
	};
	return html`<div class="cluster-grid">${(clusters || []).map((c) => html`<article key=${c.id} class="card cluster">
		<div class="cluster__head">
			<div><strong>${c.name}</strong><div class="muted small">${c.pillar && c.pillar !== c.name ? 'Pillar: ' + c.pillar + ' · ' : ''}${fmt.num(c.total_volume)} keresés/hó · ${c.count} kulcsszó</div></div>
			<div class="row-edit">${c.priority ? html`<${Pill} kind=${c.priority}>${meta.priorities[c.priority]}</${Pill}>` : ''}
				${can(me, 'keywords.edit') ? html`<button class="icon-btn" title="Átnevezés" onClick=${() => rename(c)}><${Icon} name="edit" /></button>` : ''}</div>
		</div>
		${c.target_url ? html`<div class="small"><span class="muted">Céloldal:</span> <span class="url">${c.target_url}</span></div>` : ''}
		<div class="cluster__buckets">${Object.entries(c.buckets).map(([b, list]) => html`<div key=${b} class="bucket"><strong>${meta.buckets[b] || b}</strong>
			<div class="chips">${list.slice(0, 12).map((m) => html`<button key=${m.id} class=${'chip' + (byId[m.id] && byId[m.id].role === 'primary' ? ' chip--primary' : '')} onClick=${() => byId[m.id] && onOpen(byId[m.id])} title=${fmt.num(m.volume) + ' · ' + (meta.priorities[m.priority] || '')}>${m.term}</button>`)}
			${list.length > 12 ? html`<span class="muted small">+${list.length - 12}</span>` : ''}</div></div>`)}</div>
		${c.cannibalization_rule ? html`<p class="small muted">${c.cannibalization_rule}</p>` : ''}
	</article>`)}</div>`;
}
