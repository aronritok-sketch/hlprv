/**
 * Struktúra: oldalfa és URL / SEO title / H1 mátrix, oldalszerkesztő (kulcsszó-hozzárendelés, belső linkek),
 * kannibalizációs figyelmeztetések. Az oldalakat az AI javasolja; minden ember által módosítható.
 */
import { html, useState, useMemo, useApp, api, useLoad, useJob, toast, errorText, fmt, Icon, Spinner, ErrorBox, Empty, Drawer, Modal, Field, Input, Textarea, Select, Pill, can } from '../../ui.js';

const ORDER = ['home', 'service_hub', 'service', 'city_hub', 'city_service', 'category', 'product', 'pillar', 'article', 'case_study', 'support'];

function PageDrawer({ project, page, pages, links, onClose, onSaved }) {
	const { me, meta } = useApp();
	const editable = can(me, 'structure.edit');
	const [f, setF] = useState({ ...page });
	const [kwData] = useLoad(editable ? '/projects/' + project.id + '/keywords' : null);
	const [addKw, setAddKw] = useState('');
	const [linkTo, setLinkTo] = useState('');
	const [linkAnchor, setLinkAnchor] = useState('');
	const [linkType, setLinkType] = useState('text');
	const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));
	const fields = ['url', 'page_type', 'title', 'seo_title', 'h1', 'meta_description', 'seo_goal', 'cta_label', 'cta_url', 'priority', 'lifecycle', 'redirect_to', 'local_variation', 'notes', 'location', 'service'];
	const save = async () => {
		const body = Object.fromEntries(fields.filter((k) => f[k] !== page[k]).map((k) => [k, f[k]]));
		if (!Object.keys(body).length) { onClose(); return; }
		try { await api('/projects/' + project.id + '/pages/' + page.id, { method: 'PATCH', body }); toast('Mentve.'); onSaved(); onClose(); } catch (e) { toast(errorText(e), 'error'); }
	};
	const setKeywords = async (primary, secondary) => {
		try { await api('/projects/' + project.id + '/pages/' + page.id + '/keywords', { method: 'PUT', body: { primary, secondary } }); onSaved(); } catch (e) { toast(errorText(e), 'error'); }
	};
	const accept = async () => { try { await api('/projects/' + project.id + '/pages/' + page.id, { method: 'PATCH', body: { status: 'accepted' } }); onSaved(); } catch (e) { toast(e.message, 'error'); } };
	const addLink = async () => {
		try {
			const to = pages.find((p) => String(p.id) === linkTo);
			await api('/projects/' + project.id + '/links', { method: 'POST', body: { from_page_id: page.id, to_page_id: to.id, anchor: linkAnchor || to.h1 || to.title, link_type: linkType, placement: '' } });
			setLinkTo(''); setLinkAnchor(''); onSaved();
		} catch (e) { toast(errorText(e), 'error'); }
	};
	const delLink = async (id) => { try { await api('/projects/' + project.id + '/links/' + id, { method: 'DELETE' }); onSaved(); } catch (e) { toast(e.message, 'error'); } };
	const remove = async () => {
		if (!confirm('Törlöd az oldalt a struktúrából?')) return;
		try { await api('/projects/' + project.id + '/pages/' + page.id, { method: 'DELETE' }); onSaved(); onClose(); } catch (e) { toast(e.message, 'error'); }
	};
	const out = links.filter((l) => l.from_page_id === page.id);
	const inc = links.filter((l) => l.to_page_id === page.id);
	const byId = Object.fromEntries(pages.map((p) => [p.id, p]));
	const secIds = page.secondary.map((k) => k.id);
	const kwOptions = kwData ? kwData.keywords.filter((k) => !k.is_excluded).map((k) => [k.id, k.term + (k.url && k.url !== page.url ? ' (' + k.url + ')' : '')]) : [];

	return html`<${Drawer} title=${page.url} subtitle=${meta.page_types[page.page_type] + (page.location ? ' · ' + page.location : '')} onClose=${onClose}
		footer=${editable ? html`<button class="btn btn--ghost btn--sm" onClick=${remove}><${Icon} name="trash" /> Törlés</button><span class="toolbar__spacer"></span>
			${page.status === 'suggested' ? html`<button class="btn btn--ghost" onClick=${accept}>Elfogad</button>` : ''}<button class="btn" onClick=${save}>Mentés</button>` : ''}>
		<div class="stack">
			<section class="card"><header class="card__head"><h2>Kulcsszavak</h2></header>
				<div class="pad stack-sm">
					<div><span class="muted small">Elsődleges</span><div>${page.primary ? html`<span class="chip chip--primary">${page.primary.term}</span> <span class="muted small">${fmt.num(page.primary.volume)} · KD ${page.primary.kd ?? '–'} · ${meta.intents[page.primary.intent] || ''}</span>
						${editable ? html` <button class="link small" onClick=${() => setKeywords(null, secIds)}>eltávolítás</button>` : ''}` : html`<span class="muted">nincs</span>`}</div></div>
					<div><span class="muted small">Másodlagos</span><div class="chips">${page.secondary.map((k) => html`<span key=${k.id} class="chip">${k.term}${editable ? html` <button class="link" onClick=${() => setKeywords(page.primary && page.primary.id, secIds.filter((x) => x !== k.id))}>×</button>` : ''}</span>`)}</div></div>
					${editable ? html`<div class="row-edit">
						<${Select} value=${addKw} onChange=${setAddKw} options=${kwOptions} placeholder=${kwData ? 'Kulcsszó választása…' : 'Betöltés…'} />
						<button class="btn btn--ghost btn--sm" disabled=${!addKw} onClick=${() => { setKeywords(Number(addKw), secIds.filter((x) => x !== Number(addKw)).concat(page.primary ? [page.primary.id] : [])); setAddKw(''); }}>Elsődleges</button>
						<button class="btn btn--ghost btn--sm" disabled=${!addKw} onClick=${() => { setKeywords(page.primary && page.primary.id, [...secIds, Number(addKw)]); setAddKw(''); }}>Másodlagos</button>
					</div><p class="muted small">Egy elsődleges kulcsszó csak egy oldalhoz tartozhat – a rendszer ezt nem engedi megszegni.</p>` : ''}
				</div>
			</section>
			<div class="form-grid">
				<${Field} label="URL"><${Input} value=${f.url} disabled=${!editable} onInput=${set('url')} /></${Field}>
				<${Field} label="Oldaltípus"><${Select} value=${f.page_type} disabled=${!editable} onChange=${set('page_type')} options=${meta.page_types} /></${Field}>
				<${Field} label="SEO title" wide hint=${(f.seo_title || '').length + ' karakter (ajánlott: legfeljebb 60)'}><${Input} value=${f.seo_title} disabled=${!editable} onInput=${set('seo_title')} /></${Field}>
				<${Field} label="H1" wide><${Input} value=${f.h1} disabled=${!editable} onInput=${set('h1')} /></${Field}>
				<${Field} label="Meta description" wide hint=${(f.meta_description || '').length + ' karakter (ajánlott: 120–155)'}><${Textarea} rows="2" value=${f.meta_description} disabled=${!editable} onInput=${set('meta_description')} /></${Field}>
				<${Field} label="SEO-cél" wide><${Textarea} rows="2" value=${f.seo_goal} disabled=${!editable} onInput=${set('seo_goal')} /></${Field}>
				<${Field} label="CTA felirat"><${Input} value=${f.cta_label} disabled=${!editable} onInput=${set('cta_label')} /></${Field}>
				<${Field} label="CTA cél"><${Input} value=${f.cta_url} disabled=${!editable} onInput=${set('cta_url')} /></${Field}>
				<${Field} label="Prioritás"><${Select} value=${f.priority} disabled=${!editable} onChange=${set('priority')} options=${{ P1: 'P1', P2: 'P2', hub: 'Hub', support: 'Támogató' }} placeholder="–" /></${Field}>
				<${Field} label="Állapot"><${Select} value=${f.lifecycle} disabled=${!editable} onChange=${set('lifecycle')} options=${meta.page_lifecycle} /></${Field}>
				${f.lifecycle === 'redirect' || f.lifecycle === 'merge' ? html`<${Field} label="301 cél" wide><${Input} value=${f.redirect_to} disabled=${!editable} onInput=${set('redirect_to')} /></${Field}>` : ''}
				${page.page_type === 'city_service' || page.page_type === 'city_hub' ? html`<${Field} label="Helyi eltérés" wide hint="Iparágak, problémák, példák, FAQ – ez teszi egyedivé a városi oldalt."><${Textarea} rows="2" value=${f.local_variation} disabled=${!editable} onInput=${set('local_variation')} /></${Field}>` : ''}
				<${Field} label="Megjegyzés" wide><${Textarea} rows="2" value=${f.notes} disabled=${!editable} onInput=${set('notes')} /></${Field}>
			</div>
			<section class="card"><header class="card__head"><h2>Belső linkek (${out.length} ki · ${inc.length} be)</h2></header>
				<table class="table table--dense"><thead><tr><th>Cél</th><th>Anchor</th><th>Típus</th><th>Hol</th><th></th></tr></thead>
				<tbody>${out.map((l) => html`<tr key=${l.id}><td class="url">${l.to_url}</td><td>${l.anchor}</td><td>${meta.link_types[l.link_type]}</td><td class="small muted">${l.placement}</td>
					<td>${editable ? html`<button class="icon-btn" onClick=${() => delLink(l.id)}><${Icon} name="x" /></button>` : ''}</td></tr>`)}</tbody></table>
				${editable ? html`<div class="pad row-edit">
					<${Select} value=${linkTo} onChange=${setLinkTo} options=${pages.filter((p) => p.id !== page.id).map((p) => [p.id, p.url])} placeholder="Céloldal…" />
					<input class="input" placeholder="Anchor" value=${linkAnchor} onInput=${(e) => setLinkAnchor(e.target.value)} />
					<${Select} value=${linkType} onChange=${setLinkType} options=${meta.link_types} />
					<button class="btn btn--sm" disabled=${!linkTo} onClick=${addLink}>Hozzáad</button>
				</div>` : ''}
				${inc.length ? html`<p class="pad small muted">Bejövő: ${inc.map((l) => (byId[l.from_page_id] || {}).url).filter(Boolean).join(', ')}</p>` : ''}
			</section>
		</div>
	</${Drawer}>`;
}

function Tree({ pages, onOpen, meta }) {
	const children = {};
	pages.forEach((p) => { (children[p.parent_id || 0] = children[p.parent_id || 0] || []).push(p); });
	const ids = new Set(pages.map((p) => p.id));
	const roots = pages.filter((p) => !p.parent_id || !ids.has(p.parent_id));
	const node = (p) => html`<li key=${p.id}>
		<div class="tree__node" onClick=${() => onOpen(p)}>
			<span class="url tree__url">${p.url}</span>
			<${Pill}>${meta.page_types[p.page_type]}</${Pill}>
			${p.priority === 'P1' || p.priority === 'P2' ? html`<${Pill} kind=${p.priority}>${p.priority}</${Pill}>` : ''}
			${p.status === 'suggested' ? html`<span class="ai-badge"><${Icon} name="spark" size="11" /> javaslat</span>` : ''}
			<span class="tree__kw">${p.primary ? p.primary.term : ''}</span>
		</div>
		${children[p.id] ? html`<ul class="tree">${children[p.id].map(node)}</ul>` : ''}
	</li>`;
	return html`<ul class="tree tree--root">${roots.map(node)}</ul>`;
}

function AddPage({ project, onClose, onDone }) {
	const { meta } = useApp();
	const [f, setF] = useState({ url: '', page_type: 'service', h1: '', lifecycle: 'new' });
	const save = async () => {
		try { await api('/projects/' + project.id + '/pages', { method: 'POST', body: f }); onDone(); onClose(); } catch (e) { toast(errorText(e), 'error'); }
	};
	return html`<${Modal} title="Új oldal" onClose=${onClose} footer=${html`<button class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" onClick=${save}>Hozzáadás</button>`}>
		<div class="form-grid">
			<${Field} label="URL"><${Input} value=${f.url} onInput=${(v) => setF({ ...f, url: v })} placeholder="/oldal/" /></${Field}>
			<${Field} label="Oldaltípus"><${Select} value=${f.page_type} onChange=${(v) => setF({ ...f, page_type: v })} options=${meta.page_types} /></${Field}>
			<${Field} label="H1" wide><${Input} value=${f.h1} onInput=${(v) => setF({ ...f, h1: v })} /></${Field}>
			<${Field} label="Állapot"><${Select} value=${f.lifecycle} onChange=${(v) => setF({ ...f, lifecycle: v })} options=${meta.page_lifecycle} /></${Field}>
		</div>
	</${Modal}>`;
}

export function Structure({ project }) {
	const { me, meta } = useApp();
	const [data, loading, error, reload] = useLoad('/projects/' + project.id + '/pages');
	const [view, setView] = useState('tree');
	const [open, setOpen] = useState(null);
	const [adding, setAdding] = useState(false);
	const [job, setJob] = useJob((j) => { toast('Struktúra kész: ' + j.result.pages + ' oldal, ' + j.result.links + ' belső link.'); reload(); });
	const running = job && (job.status === 'queued' || job.status === 'running');
	const pages = useMemo(() => (data ? [...data.pages].sort((a, b) => (a.sort - b.sort) || ORDER.indexOf(a.page_type) - ORDER.indexOf(b.page_type)) : []), [data]);

	if (loading && !data) return html`<${Spinner} />`;
	if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
	const generate = async () => {
		if (pages.length && !confirm('A még nem jóváhagyott oldaljavaslatok újragenerálódnak. A kézzel felvett és elfogadott oldalak megmaradnak. Mehet?')) return;
		try { const j = await api('/projects/' + project.id + '/structure/generate', { method: 'POST' }); if (j.status === 'done') reload(); else if (j.status === 'failed') toast(j.error, 'error'); else setJob(j); } catch (e) { toast(errorText(e), 'error'); }
	};
	const acceptAll = async () => { try { const r = await api('/projects/' + project.id + '/structure/accept', { method: 'POST' }); toast(r.accepted + ' oldal elfogadva.'); reload(); } catch (e) { toast(e.message, 'error'); } };
	const suggested = pages.filter((p) => p.status === 'suggested').length;
	const current = open ? pages.find((p) => p.id === open.id) : null;

	return html`<div class="stack">
		<div class="toolbar">
			<div class="segmented">
				<button class=${view === 'tree' ? 'is-active' : ''} onClick=${() => setView('tree')}>Oldalfa</button>
				<button class=${view === 'matrix' ? 'is-active' : ''} onClick=${() => setView('matrix')}>URL / title / H1 mátrix</button>
			</div>
			<span class="muted small">${pages.length} oldal · ${data.links.length} belső link${suggested ? ' · ' + suggested + ' javaslat átnézendő' : ''}</span>
			<span class="toolbar__spacer"></span>
			${can(me, 'structure.edit') && suggested ? html`<button class="btn btn--ghost btn--sm" onClick=${acceptAll}>Minden oldal elfogadása</button>` : ''}
			${can(me, 'structure.edit') ? html`<button class="btn btn--ghost btn--sm" onClick=${() => setAdding(true)}><${Icon} name="plus" /> Oldal</button>` : ''}
			${can(me, 'ai.run') ? html`<button class="btn btn--sm" disabled=${running} onClick=${generate}>${running ? 'Készül… ' + Math.round((job.progress || 0) * 100) + '%' : pages.length ? 'Struktúra újragenerálása' : 'Struktúra generálása'}</button>` : ''}
		</div>
		${data.warnings.length ? html`<section class="card"><header class="card__head"><h2><${Icon} name="flag" /> Figyelmeztetések</h2><span class="count">${data.warnings.length}</span></header>
			<ul class="warning-list">${data.warnings.slice(0, 20).map((w, i) => html`<li key=${i}>${w.message}</li>`)}</ul></section>` : ''}
		${!pages.length ? html`<${Empty} icon="sitemap" title="Még nincs oldalstruktúra">A kulcsszó-elemzés után a rendszer a módszertan oldalmodelljei alapján javasol struktúrát:
			főoldal, szolgáltatási oldalak, városi hubok és városi landingek (csak ahol a kutatás helyi keresést mutat), blog hub és támogató oldalak.</${Empty}>` : ''}
		${pages.length && view === 'tree' ? html`<div class="card pad"><${Tree} pages=${pages} meta=${meta} onOpen=${setOpen} /></div>` : ''}
		${pages.length && view === 'matrix' ? html`<div class="card"><div class="table-wrap"><table class="table">
			<thead><tr><th>URL</th><th>SEO title</th><th>H1</th><th>Elsődleges kulcsszó</th><th>Típus</th><th>Szerep</th></tr></thead>
			<tbody>${pages.map((p) => html`<tr key=${p.id} class="is-clickable" onClick=${() => setOpen(p)}>
				<td class="url">${p.url}</td><td>${p.seo_title}</td><td>${p.h1}</td>
				<td>${p.primary ? html`${p.primary.term} <span class="muted small">${fmt.num(p.primary.volume)}</span>` : html`<span class="muted">–</span>`}</td>
				<td class="small">${meta.page_types[p.page_type]}</td><td>${p.priority ? html`<${Pill} kind=${p.priority}>${p.priority}</${Pill}>` : ''}</td>
			</tr>`)}</tbody></table></div></div>` : ''}
		${current ? html`<${PageDrawer} key=${current.id + ':' + (current.primary ? current.primary.id : 0) + ':' + current.secondary.length} project=${project} page=${current} pages=${pages} links=${data.links} onClose=${() => setOpen(null)} onSaved=${reload} />` : ''}
		${adding ? html`<${AddPage} project=${project} onClose=${() => setAdding(false)} onDone=${reload} />` : ''}
	</div>`;
}
