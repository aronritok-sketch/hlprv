/**
 * Wireframe-ek: oldalanként a saját formátumban (fejléc, lényeg, folyamatsor, blokktábla, belső linkek, proof,
 * kötelező / tilos lista, vizuális sorrend, kötelező inputok). Generálás, szerkesztés, jóváhagyás, verziók.
 */
import { html, useState, useEffect, useApp, api, useLoad, useJob, setParam, toast, errorText, fmt, Icon, Spinner, ErrorBox, Empty, Field, Textarea, Pill, InlineEdit, Checkbox, Select, can } from '../../ui.js';

const STATUS = { draft: 'Vázlat', review: 'Ellenőrzésre', approved: 'Jóváhagyva' };

function Viewer({ project, id, onChanged }) {
	const { me, meta } = useApp();
	const [wf, loading, error, reload, setWf] = useLoad('/projects/' + project.id + '/wireframes/' + id, [id]);
	if (loading && !wf) return html`<${Spinner} />`;
	if (error) return html`<${ErrorBox} error=${error} />`;
	const editable = can(me, 'wireframes.edit') && wf.status !== 'approved';
	const patch = async (body) => {
		try { setWf(await api('/projects/' + project.id + '/wireframes/' + wf.id, { method: 'PATCH', body })); onChanged(); } catch (e) { toast(errorText(e), 'error'); }
	};
	const setSection = (i, k) => (v) => patch({ sections: wf.sections.map((s, j) => (j === i ? { ...s, [k]: v } : s)) });
	const setList = (k) => (v) => patch({ [k]: v.split('\n').map((s) => s.trim()).filter(Boolean) });
	const prim = (wf.keywords || []).find((k) => k.role === 'primary');
	const sec = (wf.keywords || []).filter((k) => k.role !== 'primary');
	return html`<div class="stack">
		<div class="toolbar">
			<${Pill} kind=${wf.status === 'approved' ? 'ok' : wf.status === 'review' ? 'warn' : ''}>${STATUS[wf.status]}</${Pill}>
			<span class="muted small">v${wf.version} · ${wf.method === 'llm' ? 'AI' : 'sablon'} · ${fmt.datetime(wf.updated_at)}</span>
			${wf.versions.length > 1 ? html`<${Select} value=${String(wf.id)} onChange=${(v) => setParam('wf', v)} options=${wf.versions.map((v) => [String(v.id), 'v' + v.version + ' – ' + STATUS[v.status]])} />` : ''}
			<span class="toolbar__spacer"></span>
			${can(me, 'wireframes.edit') && wf.status === 'draft' ? html`<button class="btn btn--ghost btn--sm" onClick=${() => patch({ status: 'review' })}>Ellenőrzésre</button>` : ''}
			${can(me, 'approve.internal') && wf.status !== 'approved' ? html`<button class="btn btn--sm" onClick=${() => patch({ status: 'approved' })}>Jóváhagyás</button>` : ''}
		</div>
		<section class="card pad stack-sm">
			<h2>${(wf.page && wf.page.h1) || wf.label}</h2>
			<dl class="wf-head">
				<div><dt>Javasolt URL</dt><dd class="url">${wf.page && wf.page.url}</dd></div>
				<div><dt>Oldaltípus</dt><dd>${wf.page && meta.page_types[wf.page.page_type]}</dd></div>
				<div><dt>Elsődleges kulcsszó</dt><dd>${prim ? prim.term : '–'}</dd></div>
				<div><dt>Volume / KD</dt><dd>${prim ? fmt.num(prim.volume) + ' / ' + (prim.kd ?? '–') : '–'}</dd></div>
				<div style="grid-column: span 2"><dt>Kapcsolódó kulcsszavak</dt><dd class="small">${sec.map((k) => k.term).join('; ') || '–'}</dd></div>
				<div><dt>Ajánlott terjedelem</dt><dd>${wf.word_count_min}–${wf.word_count_max} szó</dd></div>
				<div><dt>Linkek</dt><dd>${wf.links.length}</dd></div>
			</dl>
			<div class="wf-essence">${editable ? html`<${InlineEdit} multiline value=${wf.essence} onSave=${(v) => patch({ essence: v })} />` : wf.essence}</div>
			<div class="wf-flow">${wf.flow.map((s, i) => html`${i ? html`<span class="arrow">→</span>` : ''}<span class="chip">${s}</span>`)}</div>
		</section>
		<section class="stack-sm">${wf.sections.map((s, i) => html`<article key=${i} class="card wf-section">
			<span class="wf-section__num">${i + 1}</span>
			<div>
				<strong>${editable ? html`<${InlineEdit} value=${s.h2} onSave=${setSection(i, 'h2')} />` : s.h2}</strong> <span class="muted small">${s.length_min}–${s.length_max} szó</span>
				<dl>
					<dt>A blokk célja</dt><dd>${editable ? html`<${InlineEdit} multiline value=${s.goal} onSave=${setSection(i, 'goal')} />` : s.goal}</dd>
					<dt>Mit írjon bele?</dt><dd>${editable ? html`<${InlineEdit} multiline value=${s.what_to_write} onSave=${setSection(i, 'what_to_write')} />` : s.what_to_write}</dd>
					<dt>SEO / kulcsszóhasználat</dt><dd>${editable ? html`<${InlineEdit} multiline value=${s.seo_usage} onSave=${setSection(i, 'seo_usage')} />` : s.seo_usage}</dd>
					<dt>Belső link / CTA</dt><dd>${editable ? html`<${InlineEdit} multiline value=${s.link_cta} onSave=${setSection(i, 'link_cta')} />` : s.link_cta}</dd>
				</dl>
			</div>
		</article>`)}</section>
		<section class="card"><header class="card__head"><h2>Belső linkelés – pontosan hova kerüljön?</h2></header>
			<table class="table table--dense"><thead><tr><th>Hol legyen?</th><th>Anchor / felirat</th><th>Cél</th><th>Kivitelezési megjegyzés</th></tr></thead>
			<tbody>${wf.links.map((l, i) => html`<tr key=${i}><td>${l.placement}</td><td>${l.anchor}</td><td class="url">${l.target}</td><td class="small">${l.note}</td></tr>`)}</tbody></table>
		</section>
		${wf.proof_requirements ? html`<section class="card pad"><h3 class="subhead">Bizonyíték / input</h3><p>${editable ? html`<${InlineEdit} multiline value=${wf.proof_requirements} onSave=${(v) => patch({ proof_requirements: v })} />` : wf.proof_requirements}</p></section>` : ''}
		<div class="checklists">
			<div class="checklist checklist--ok"><h3>Kötelező a leadás előtt</h3>${editable ? html`<${Textarea} rows="5" value=${wf.must_have.join('\n')} onBlur=${(e) => setList('must_have')(e.target.value)} onInput=${() => {}} />` : html`<ul>${wf.must_have.map((x) => html`<li key=${x}>✓ ${x}</li>`)}</ul>`}</div>
			<div class="checklist checklist--no"><h3>Tilos / kerülendő</h3>${editable ? html`<${Textarea} rows="5" value=${wf.forbidden.join('\n')} onBlur=${(e) => setList('forbidden')(e.target.value)} onInput=${() => {}} />` : html`<ul>${wf.forbidden.map((x) => html`<li key=${x}>× ${x}</li>`)}</ul>`}</div>
		</div>
		${wf.visual_sequence ? html`<section class="card pad"><h3 class="subhead">Vizuális sorrend</h3><p>${wf.visual_sequence}</p></section>` : ''}
		${wf.inputs.length ? html`<section class="card"><header class="card__head"><h2>Kötelező input</h2></header>
			<table class="table table--dense"><tbody>${wf.inputs.map((inp, i) => html`<tr key=${i}><td><${Checkbox} checked=${inp.provided} label=${inp.item} onChange=${(v) => editable && patch({ inputs: wf.inputs.map((x, j) => (j === i ? { ...x, provided: v } : x)) })} /></td><td class="small">${inp.description}</td></tr>`)}</tbody></table>
		</section>` : ''}
		${can(me, 'wireframes.view') ? html`<section class="card pad"><h3 class="subhead">UX-megjegyzés a grafikusnak</h3>${editable ? html`<${InlineEdit} multiline value=${wf.ux_notes} onSave=${(v) => patch({ ux_notes: v })} placeholder="pl. hero: responsive mockup, proof az első képernyőn" />` : html`<p>${wf.ux_notes || '–'}</p>`}</section>` : ''}
	</div>`;
}

export function Wireframes({ project, params }) {
	const { me, meta } = useApp();
	const [list, loading, error, reload] = useLoad('/projects/' + project.id + '/wireframes');
	const [selected, setSelected] = useState(new Set());
	const [filter, setFilter] = useState('');
	const [job, setJob] = useJob((j) => { toast(j.result.wireframes + ' wireframe elkészült.'); setSelected(new Set()); reload(); });
	const running = job && (job.status === 'queued' || job.status === 'running');
	const pageId = params.page ? Number(params.page) : null;
	const current = list && pageId ? list.find((x) => x.page_id === pageId) : null;
	const wfId = params.wf ? Number(params.wf) : current && current.wireframe ? current.wireframe.id : null;
	if (loading && !list) return html`<${Spinner} />`;
	if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
	if (!list.length) return html`<${Empty} icon="layout" title="Még nincs oldal">A wireframe-ek a struktúra és a roadmap oldalaihoz készülnek.</${Empty}>`;
	const generate = async (ids) => {
		try { const j = await api('/projects/' + project.id + '/wireframes/generate', { method: 'POST', body: { page_ids: ids } }); if (j.status === 'done') { reload(); toast(j.result.wireframes + ' wireframe elkészült.'); } else if (j.status === 'failed') toast(j.error, 'error'); else setJob(j); } catch (e) { toast(errorText(e), 'error'); }
	};
	const rows = list.filter((x) => !filter || x.page_type === filter);
	return html`<div class="grid grid--wf">
		<div class="stack">
			<div class="toolbar" style="margin:0">
				<${Select} value=${filter} onChange=${setFilter} options=${meta.page_types} placeholder="Minden oldaltípus" />
				${can(me, 'wireframes.edit') ? html`<button class="btn btn--sm" disabled=${running || !selected.size} onClick=${() => generate([...selected])}>${running ? 'Készül… ' + Math.round((job.progress || 0) * 100) + '%' : 'Generálás (' + selected.size + ')'}</button>` : ''}
			</div>
			<div class="card"><ul class="list">${rows.map((x) => html`<li key=${x.page_id} style=${x.page_id === pageId ? 'background:var(--card-2)' : ''}>
				<span style="flex-direction:row;align-items:flex-start;gap:8px">
					${can(me, 'wireframes.edit') ? html`<input type="checkbox" checked=${selected.has(x.page_id)} onChange=${(e) => { const n = new Set(selected); if (e.target.checked) n.add(x.page_id); else n.delete(x.page_id); setSelected(n); }} />` : ''}
					<button class="link" style="flex-direction:column;align-items:flex-start;color:var(--ink);text-align:left" onClick=${() => { setParam('wf', ''); setParam('page', x.page_id); }}>
						<strong class="url">${x.url}</strong><span class="muted small">${meta.page_types[x.page_type]}${x.month ? ' · ' + fmt.month(x.month) : ''}</span>
					</button>
					<span style="margin-left:auto">${x.wireframe ? html`<${Pill} kind=${x.wireframe.status === 'approved' ? 'ok' : ''}>${STATUS[x.wireframe.status]}</${Pill}>` : html`<span class="muted small">nincs</span>`}</span>
				</span>
			</li>`)}</ul></div>
		</div>
		<div>
			${wfId ? html`<${Viewer} key=${wfId} project=${project} id=${wfId} onChanged=${reload} />`
				: current ? html`<${Empty} icon="layout" title="Ehhez az oldalhoz még nincs wireframe" action=${can(me, 'wireframes.edit') ? html`<button class="btn" onClick=${() => generate([current.page_id])}>Wireframe generálása</button>` : ''} />`
				: html`<${Empty} icon="layout" title="Válassz oldalt">Jelöld ki az oldalakat a generáláshoz, vagy nyiss meg egy meglévő wireframe-et.</${Empty}>`}
		</div>
	</div>`;
}
