/**
 * Tartalomstratégia – a HelloProVision 6 munkalapos stratégiájának megfelelően:
 * 6 havi roadmap (tábla és havi nézet), kulcsszóklaszterek, mérési terv, PPC / Meta terv, félretett témák.
 */
import { html, useState, useMemo, useApp, api, useLoad, useJob, toast, errorText, fmt, Icon, Spinner, ErrorBox, Empty, Drawer, Field, Input, Textarea, Select, Pill, Checkbox, InlineEdit, Tabs, can } from '../../ui.js';

function ItemDrawer({ project, item, onClose, onSaved }) {
	const { me, meta } = useApp();
	const full = can(me, 'strategy.edit');
	const [users] = useLoad('/users');
	const [f, setF] = useState({ ...item, internal_links: (item.internal_links || []).join('\n') });
	const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));
	const save = async () => {
		const keys = full ? ['month', 'priority', 'content_type', 'pillar', 'title', 'url', 'location_context', 'content_direction', 'cta', 'social_hook', 'cannibalization_rule', 'client_input', 'status', 'assignee_id', 'due_date']
			: ['status', 'assignee_id', 'due_date'];
		const body = {};
		for (const k of keys) if (f[k] !== item[k]) body[k] = f[k] === '' && (k === 'assignee_id' || k === 'due_date') ? null : f[k];
		if (full) {
			const links = f.internal_links.split('\n').map((s) => s.trim()).filter(Boolean);
			if (links.join() !== (item.internal_links || []).join()) body.internal_links = links;
		}
		if (body.assignee_id) body.assignee_id = Number(body.assignee_id);
		try { await api('/projects/' + project.id + '/roadmap/' + item.id, { method: 'PATCH', body }); toast('Mentve.'); onSaved(); onClose(); } catch (e) { toast(errorText(e), 'error'); }
	};
	return html`<${Drawer} title=${item.title} subtitle=${fmt.month(item.month) + ' · ' + (meta.content_types[item.content_type] || item.content_type)} onClose=${onClose}
		footer=${html`<button class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" onClick=${save}>Mentés</button>`}>
		<div class="form-grid">
			<${Field} label="Állapot"><${Select} value=${f.status} onChange=${set('status')} options=${meta.roadmap_statuses} /></${Field}>
			<${Field} label="Felelős"><${Select} value=${f.assignee_id || ''} onChange=${set('assignee_id')} options=${(users || []).filter((u) => u.is_active).map((u) => [u.id, u.display_name])} placeholder="–" /></${Field}>
			<${Field} label="Határidő"><input class="input" type="date" value=${f.due_date || ''} onInput=${(e) => set('due_date')(e.target.value)} /></${Field}>
			<${Field} label="Hónap"><input class="input" type="month" disabled=${!full} value=${(f.month || '').slice(0, 7)} onInput=${(e) => set('month')(e.target.value + '-01')} /></${Field}>
			<${Field} label="Javasolt H1 / cím" wide><${Input} value=${f.title} disabled=${!full} onInput=${set('title')} /></${Field}>
			<${Field} label="URL"><${Input} value=${f.url} disabled=${!full} onInput=${set('url')} /></${Field}>
			<${Field} label="Prioritás"><${Select} value=${f.priority} disabled=${!full} onChange=${set('priority')} options=${{ P1: 'P1', P2: 'P2' }} /></${Field}>
			<${Field} label="Elsődleges kulcsszó"><span>${item.keyword || '–'} ${item.volume ? html`<span class="muted small">${fmt.num(item.volume)}/hó</span>` : ''}</span></${Field}>
			<${Field} label="Pillar"><${Input} value=${f.pillar} disabled=${!full} onInput=${set('pillar')} /></${Field}>
			<${Field} label="Kapcsolódó kulcsszavak" wide><span class="small">${(item.related_keywords || []).join('; ') || '–'}</span></${Field}>
			<${Field} label="Tartalmi irány / fő blokkok" wide><${Textarea} rows="4" value=${f.content_direction} disabled=${!full} onInput=${set('content_direction')} /></${Field}>
			<${Field} label="Lokáció"><${Input} value=${f.location_context} disabled=${!full} onInput=${set('location_context')} /></${Field}>
			<${Field} label="Fő CTA"><${Input} value=${f.cta} disabled=${!full} onInput=${set('cta')} /></${Field}>
			<${Field} label="Belső linkelés (soronként egy URL)" wide><${Textarea} rows="3" value=${f.internal_links} disabled=${!full} onInput=${set('internal_links')} /></${Field}>
			<${Field} label="Boost / social hook" wide><${Input} value=${f.social_hook} disabled=${!full} onInput=${set('social_hook')} /></${Field}>
			<${Field} label="Kannibalizációs szabály" wide><${Textarea} rows="2" value=${f.cannibalization_rule} disabled=${!full} onInput=${set('cannibalization_rule')} /></${Field}>
			<${Field} label="Ügyféltől kért input" wide><${Textarea} rows="2" value=${f.client_input} disabled=${!full} onInput=${set('client_input')} /></${Field}>
		</div>
		${item.page_id ? html`<p class="small" style="margin-top:12px"><a class="link" href=${'#/projects/' + project.id + '?tab=wireframes&page=' + item.page_id}>Wireframe megnyitása →</a></p>` : ''}
	</${Drawer}>`;
}

function Board({ items, onOpen, meta }) {
	const months = [...new Set(items.map((i) => i.month))].sort();
	return html`<div class="roadmap">${months.map((m) => {
		const list = items.filter((i) => i.month === m);
		return html`<section key=${m} class="roadmap__month"><header><span>${fmt.month(m)}</span><span class="muted small">${list.length}</span></header>
			${list.map((i) => html`<button key=${i.id} class="roadmap__item" onClick=${() => onOpen(i)}>
				<div class="row-edit"><${Pill} kind=${i.priority}>${i.priority}</${Pill}><span class="muted small">${meta.content_types[i.content_type]}</span>
					${i.review === 'suggested' ? html`<span class="ai-badge"><${Icon} name="spark" size="11" /></span>` : ''}</div>
				<strong>${i.title}</strong>
				<span class="muted">${i.keyword || i.pillar}${i.volume ? ' · ' + fmt.num(i.volume) + '/hó' : ''}</span>
				<span class="muted">${meta.roadmap_statuses[i.status]}${i.assignee ? ' · ' + i.assignee : ''}</span>
			</button>`)}
		</section>`;
	})}</div>`;
}

function RoadmapTable({ items, onOpen, meta }) {
	return html`<div class="card"><div class="table-wrap"><table class="table table--dense">
		<thead><tr><th>Hónap</th><th>Prioritás</th><th>Elsődleges kulcsszó</th><th>Pillar</th><th class="num">Keresés</th><th>URL</th><th>Típus</th><th>Javasolt H1 / cím</th><th>Tartalmi irány</th><th>CTA</th><th>Belső linkelés</th><th>Social hook</th><th>Státusz</th></tr></thead>
		<tbody>${items.map((i) => html`<tr key=${i.id} class="is-clickable" onClick=${() => onOpen(i)}>
			<td>${fmt.month(i.month)}</td><td><${Pill} kind=${i.priority}>${i.priority}</${Pill}></td><td>${i.keyword || '—'}</td><td class="small">${i.pillar}</td>
			<td class="num">${fmt.num(i.volume)}</td><td class="url">${i.url}</td><td class="small">${meta.content_types[i.content_type]}</td><td><strong>${i.title}</strong></td>
			<td class="small" style="min-width:260px;white-space:normal">${i.content_direction}</td><td class="small">${i.cta}</td><td class="url">${(i.internal_links || []).join(' · ')}</td>
			<td class="small" style="white-space:normal">${i.social_hook}</td><td>${meta.roadmap_statuses[i.status]}</td>
		</tr>`)}</tbody></table></div></div>`;
}

function Clusters({ project }) {
	const { meta } = useApp();
	const [clusters] = useLoad('/projects/' + project.id + '/clusters');
	if (!clusters) return html`<${Spinner} />`;
	return html`<div class="card"><div class="table-wrap"><table class="table table--dense">
		<thead><tr><th>Klaszter</th><th>Elsődleges kulcsszó</th><th class="num">Klaszter havi keresése</th><th>Szándék</th><th>Pillar</th><th>Kapcsolódó kulcsszavak</th><th>Céloldal</th><th>Prioritás</th><th>Kannibalizációs szabály</th></tr></thead>
		<tbody>${clusters.map((c) => {
			const all = Object.values(c.buckets).flat().sort((a, b) => b.score - a.score);
			return html`<tr key=${c.id}><td><strong>${c.name}</strong></td><td>${all[0] ? all[0].term : '–'}</td><td class="num">${fmt.num(c.total_volume)}</td>
				<td class="small">${meta.intents[c.intent] || '–'}</td><td class="small">${c.pillar}</td><td class="small" style="white-space:normal;min-width:240px">${all.slice(1, 6).map((m) => m.term).join('; ')}</td>
				<td class="url">${c.target_url || '–'}</td><td>${c.priority ? html`<${Pill} kind=${c.priority}>${meta.priorities[c.priority]}</${Pill}>` : ''}</td>
				<td class="small" style="white-space:normal;min-width:220px">${c.cannibalization_rule || '–'}</td></tr>`;
		})}</tbody></table></div></div>`;
}

function Measurement({ project, rows, reload }) {
	const { me } = useApp();
	const editable = can(me, 'strategy.edit');
	const save = (id, k) => async (v) => { try { await api('/projects/' + project.id + '/measurement/' + id, { method: 'PATCH', body: { [k]: v } }); reload(); } catch (e) { toast(e.message, 'error'); } };
	const cols = [['period', 'Időszak'], ['focus', 'Fő mérési fókusz'], ['what', 'Mit nézzünk?'], ['where_measured', 'Hol mérjük?'], ['success_signal', 'Sikerjel'], ['decision', 'Döntés / következő lépés'], ['content_scope', 'Milyen tartalomra bontsuk?'], ['owner', 'Tulajdonos'], ['status', 'Státusz'], ['note', 'Megjegyzés']];
	return html`<div class="card"><div class="table-wrap"><table class="table table--dense">
		<thead><tr>${cols.map(([, l]) => html`<th key=${l}>${l}</th>`)}</tr></thead>
		<tbody>${rows.map((r) => html`<tr key=${r.id}>${cols.map(([k]) => html`<td key=${k} class="small" style="white-space:normal;min-width:120px"><${InlineEdit} multiline value=${r[k]} disabled=${!editable} onSave=${save(r.id, k)} /></td>`)}</tr>`)}</tbody>
	</table></div><p class="pad muted small">Új oldalrendszernél az első hónapokban az indexelés és a query-felfutás fontosabb jel, mint a korai helyezésígéret.</p></div>`;
}

function Paid({ project, items, reload }) {
	const { me } = useApp();
	const editable = can(me, 'strategy.edit');
	const rows = items.filter((i) => i.paid);
	const save = (id, k) => async (v) => { try { await api('/projects/' + project.id + '/paid/' + id, { method: 'PATCH', body: { [k]: v } }); reload(); } catch (e) { toast(e.message, 'error'); } };
	const cols = [['seo_role', 'SEO-szerep'], ['meta_creative', 'Meta / Facebook kreatív'], ['paid_role', 'Paid szerep'], ['search_target', 'Google Search cél'], ['remarketing_next', 'Remarketing következő lépés'], ['kpi', 'Fő KPI']];
	if (!rows.length) return html`<${Empty} icon="calendar" title="Nincs paid terv">A roadmap generálásakor kapcsold be a PPC / Meta tervet.</${Empty}>`;
	return html`<div class="card"><div class="table-wrap"><table class="table table--dense">
		<thead><tr><th>Hónap</th><th>Tartalom / hook</th>${cols.map(([, l]) => html`<th key=${l}>${l}</th>`)}</tr></thead>
		<tbody>${rows.map((i) => html`<tr key=${i.id}><td>${fmt.month(i.month)}</td><td class="small" style="white-space:normal;min-width:200px"><strong>${i.title}</strong><br />${i.social_hook}</td>
			${cols.map(([k]) => html`<td key=${k} class="small" style="white-space:normal;min-width:130px"><${InlineEdit} value=${i.paid[k]} disabled=${!editable} onSave=${save(i.paid.id, k)} /></td>`)}</tr>`)}</tbody>
	</table></div><p class="pad muted small">A magas vásárlási szándékú Google Search forgalom a szolgáltatási vagy városi landingre menjen, ne a blogra.</p></div>`;
}

function Parked({ project, rows, reload }) {
	const { me } = useApp();
	const del = async (id) => { try { await api('/projects/' + project.id + '/parked/' + id, { method: 'DELETE' }); reload(); } catch (e) { toast(e.message, 'error'); } };
	if (!rows.length) return html`<${Empty} icon="flag" title="Nincs félretett téma" />`;
	return html`<div class="card"><div class="table-wrap"><table class="table table--dense">
		<thead><tr><th>Kulcsszó</th><th class="num">Volume</th><th class="num">KD</th><th>Pillar</th><th>Miért nincs most a roadmapben?</th><th>Javasolt kezelés</th><th></th></tr></thead>
		<tbody>${rows.map((r) => html`<tr key=${r.id}><td>${r.term}</td><td class="num">${fmt.num(r.volume)}</td><td class="num">${r.kd ?? '–'}</td><td class="small">${r.pillar}</td>
			<td class="small" style="white-space:normal">${r.reason}</td><td class="small" style="white-space:normal">${r.recommended_handling}</td>
			<td>${can(me, 'strategy.edit') ? html`<button class="icon-btn" onClick=${() => del(r.id)}><${Icon} name="x" /></button>` : ''}</td></tr>`)}</tbody>
	</table></div></div>`;
}

export function Strategy({ project }) {
	const { me, meta } = useApp();
	const [data, loading, error, reload] = useLoad('/projects/' + project.id + '/roadmap');
	const [sub, setSub] = useState('board');
	const [open, setOpen] = useState(null);
	const [paid, setPaid] = useState(true);
	const [job, setJob] = useJob((j) => { toast('Roadmap kész: ' + j.result.items + ' tétel, ' + j.result.parked + ' félretett téma.'); reload(); });
	const running = job && (job.status === 'queued' || job.status === 'running');
	if (loading && !data) return html`<${Spinner} />`;
	if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
	const items = data.items;
	const generate = async () => {
		if (items.length && !confirm('A még nem jóváhagyott roadmap-tételek újragenerálódnak. Mehet?')) return;
		try { const j = await api('/projects/' + project.id + '/roadmap/generate?include_paid=' + (paid ? 'true' : 'false'), { method: 'POST' }); if (j.status === 'done') reload(); else if (j.status === 'failed') toast(j.error, 'error'); else setJob(j); } catch (e) { toast(errorText(e), 'error'); }
	};
	const acceptAll = async () => { try { const r = await api('/projects/' + project.id + '/roadmap/accept', { method: 'POST' }); toast(r.accepted + ' tétel elfogadva.'); reload(); } catch (e) { toast(e.message, 'error'); } };
	const suggested = items.filter((i) => i.review === 'suggested').length;
	const arts = items.filter((i) => i.content_type !== 'case_study').length;

	return html`<div class="stack">
		<div class="toolbar">
			<span class="muted small">${items.length ? `${arts} cikk + ${items.length - arts} case study · ${project.strategy_months} hónap · ${project.content_per_month} tartalom/hó` : ''}${suggested ? ' · ' + suggested + ' javaslat átnézendő' : ''}</span>
			<span class="toolbar__spacer"></span>
			${can(me, 'strategy.edit') && suggested ? html`<button class="btn btn--ghost btn--sm" onClick=${acceptAll}>Roadmap elfogadása</button>` : ''}
			${can(me, 'ai.run') ? html`<${Checkbox} checked=${paid} onChange=${setPaid} label="PPC / Meta terv" />
				<button class="btn btn--sm" disabled=${running} onClick=${generate}>${running ? 'Készül… ' + Math.round((job.progress || 0) * 100) + '%' : items.length ? 'Roadmap újragenerálása' : 'Roadmap generálása'}</button>` : ''}
		</div>
		${!items.length ? html`<${Empty} icon="calendar" title="Még nincs tartalmi roadmap">A struktúra után a rendszer a nem landinghez rendelt információs, döntési és
			problémaalapú keresésekből ${project.strategy_months} havi tervet készít (${project.content_per_month} tartalom/hó; 4-nél havonta egy case study), mérési tervvel,
			PPC / Meta tervvel és a félretett témák indoklásával.</${Empty}>` : html`
		<${Tabs} tabs=${[['board', 'Havi nézet', 'calendar'], ['table', '6 havi roadmap', 'tasks', items.length], ['clusters', 'Kulcsszóklaszterek', 'key'], ['measurement', 'Mérési terv', 'flag'], ['paid', 'PPC / Meta', 'spark'], ['parked', 'Következő témák', 'folder', data.parked.length]]} active=${sub} onChange=${setSub} />
		${sub === 'board' ? html`<${Board} items=${items} onOpen=${setOpen} meta=${meta} />` : ''}
		${sub === 'table' ? html`<${RoadmapTable} items=${items} onOpen=${setOpen} meta=${meta} />` : ''}
		${sub === 'clusters' ? html`<${Clusters} project=${project} />` : ''}
		${sub === 'measurement' ? html`<${Measurement} project=${project} rows=${data.measurement} reload=${reload} />` : ''}
		${sub === 'paid' ? html`<${Paid} project=${project} items=${items} reload=${reload} />` : ''}
		${sub === 'parked' ? html`<${Parked} project=${project} rows=${data.parked} reload=${reload} />` : ''}`}
		${open ? html`<${ItemDrawer} project=${project} item=${items.find((i) => i.id === open.id) || open} onClose=${() => setOpen(null)} onSaved=${reload} />` : ''}
	</div>`;
}
