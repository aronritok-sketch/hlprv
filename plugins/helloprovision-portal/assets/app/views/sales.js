/**
 * Értékesítés: az érdeklődők tölcsére (húzással mozgatható szakaszok) és a források kimutatása
 * (csatornánként, kampányonként, űrlaponként: érdeklődő, válaszidő, nyerési arány, bevétel).
 * Az adatlapon az értékesítési panel (SalesPanel) ugyanezt az API-t használja.
 */
import { html, useState, useEffect, useRef, api, useApp, navigate, setParam, Icon, Avatar, Modal, Spinner, Empty, toast, todayISO, addDays, shortDate } from '../ui.js';

export const hoursLabel = (h) => {
	if (h === null || h === undefined) return '—';
	if (h < 1) return Math.max(1, Math.round(h * 60)) + ' perc';
	if (h < 48) return (Math.round(h * 10) / 10).toLocaleString('hu-HU') + ' óra';
	return Math.round(h / 24) + ' nap';
};

const TOUCH = { utm_source: 'Forrás', utm_medium: 'Médium', utm_campaign: 'Kampány', utm_term: 'Kulcsszó', utm_content: 'Hirdetés', gclid: 'Google Ads kattintás', gbraid: 'Google Ads kattintás', wbraid: 'Google Ads kattintás', fbclid: 'Meta kattintás', msclkid: 'Microsoft Ads kattintás', ref: 'Hivatkozó oldal', land: 'Érkezési oldal' };

export function Sales({ params }) {
	const tab = params.tab === 'sources' ? 'sources' : 'board';
	const [creating, setCreating] = useState(false);
	const [reload, setReload] = useState(0);
	return html`
		<div class="page page--wide">
			<header class="page__head">
				<div><h1>Értékesítés</h1><p class="muted">Az érdeklődők útja az első megkereséstől a megnyert ügyfélig, és hogy melyik marketing hozza őket.</p></div>
				<button class="btn" onClick=${() => setCreating(true)}><${Icon} name="plus" /> Új érdeklődő</button>
			</header>
			<nav class="tabs">
				<button class=${tab === 'board' ? 'is-active' : ''} onClick=${() => setParam('tab', null)}><${Icon} name="board" size="16" /> Tölcsér</button>
				<button class=${tab === 'sources' ? 'is-active' : ''} onClick=${() => setParam('tab', 'sources')}><${Icon} name="gantt" size="16" /> Források</button>
			</nav>
			${tab === 'board' ? html`<${Board} key=${reload} params=${params} />` : html`<${Sources} params=${params} />`}
			${creating ? html`<${NewLeadModal} onClose=${() => setCreating(false)} onSaved=${() => { setCreating(false); setReload(reload + 1); }} />` : null}
		</div>`;
}

/* ── Tölcsér ─────────────────────────────────────── */

function Board({ params }) {
	const { boot, setBoot } = useApp();
	const [data, setData] = useState(null);
	const [drag, setDrag] = useState(null);
	const [losing, setLosing] = useState(null);
	const boardRef = useRef(null);
	const owner = params.owner || '';
	const channel = params.channel || '';

	const load = () => {
		const q = new URLSearchParams({ ...(owner ? { owner } : {}), ...(channel ? { channel } : {}) });
		return api('/sales/board?' + q).then((d) => {
			setData(d);
			const waiting = d.cards.filter((c) => c.stage === 'new').length;
			if (!owner && !channel && setBoot && waiting !== boot.salesAttention) setBoot({ ...boot, salesAttention: waiting });
		}).catch((e) => toast(e.message, 'error'));
	};
	useEffect(() => { setData(null); load(); }, [owner, channel]);

	const move = (card, stage, reason) => {
		if (stage === card.stage) return;
		if (stage === 'lost' && !reason) { setLosing({ card }); return; }
		if (stage === 'won' && !confirm(`Megnyert: ${card.name} aktív ügyfél lesz. Mehet?`)) return;
		setData((d) => ({ ...d, cards: d.cards.map((c) => (c.id === card.id ? { ...c, stage } : c)) }));
		api('/sales/leads/' + card.id, { method: 'POST', body: { stage, lost_reason: reason || '' } })
			.then(() => { toast(stage === 'won' ? '🎉 Megnyert!' : 'Áthelyezve.'); load(); })
			.catch((e) => { toast(e.message, 'error'); load(); });
	};

	// Húzás: egérrel és érintéssel is; kattintás (mozgatás nélkül) az adatlapot nyitja.
	const onPointerDown = (e, card) => {
		if (e.button !== 0 || e.target.closest('select, a, button')) return;
		const start = { x: e.clientX, y: e.clientY };
		let moved = false;
		let over = null;
		const el = e.currentTarget;
		const onMove = (ev) => {
			if (!moved && Math.hypot(ev.clientX - start.x, ev.clientY - start.y) < 6) return;
			moved = true;
			const col = document.elementFromPoint(ev.clientX, ev.clientY)?.closest('[data-stage]');
			over = col ? col.dataset.stage : null;
			setDrag({ id: card.id, name: card.name, over, x: ev.clientX, y: ev.clientY });
		};
		const onUp = () => {
			window.removeEventListener('pointermove', onMove);
			window.removeEventListener('pointerup', onUp);
			setDrag(null);
			if (!moved) navigate('/clients/' + card.id);
			else if (over) move(card, over);
		};
		window.addEventListener('pointermove', onMove);
		window.addEventListener('pointerup', onUp);
		el.setPointerCapture?.(e.pointerId);
	};

	if (!data) return html`<${Spinner} />`;
	const k = data.kpi;
	const cols = data.stages.map((s) => ({ ...s, items: data.cards.filter((c) => c.stage === s.key) }));
	return html`
		<div class="kpis">
			<div class=${'kpi' + (k.sla_breach ? ' is-alert' : '')}><span>Válaszra vár</span><strong>${k.waiting}</strong><small>${k.sla_breach ? k.sla_breach + ' több mint ' + data.slaHours + ' órája' : 'mind időben'}</small></div>
			<div class="kpi"><span>Új érdeklődő (30 nap)</span><strong>${k.new_30}</strong><small>átlagos első válasz: ${hoursLabel(k.response_hours)}</small></div>
			<div class="kpi"><span>Nyerési arány (90 nap)</span><strong>${k.win_rate === null ? '—' : k.win_rate + '%'}</strong><small>${k.won_90} megnyert · ${k.lost_90} elveszett</small></div>
			<div class="kpi"><span>Nyitott tölcsér értéke</span><strong class="kpi__money">${k.pipeline}</strong><small>becsült érték, első év</small></div>
		</div>
		<div class="toolbar">
			<div class="seg">
				<button class=${!owner ? 'is-active' : ''} onClick=${() => setParam('owner', null)}>Mindenki</button>
				<button class=${owner === 'me' ? 'is-active' : ''} onClick=${() => setParam('owner', 'me')}>Enyém</button>
			</div>
			<select value=${channel} onChange=${(e) => setParam('channel', e.target.value || null)} aria-label="Csatorna">
				<option value="">Minden csatorna</option>
				${data.channels.map((c) => html`<option value=${c.key}>${c.label}</option>`)}
			</select>
			<span class="muted small">Húzd a kártyát a következő oszlopba · kattintásra nyílik az adatlap · a Megnyert és az Elveszett oszlop az elmúlt 30 napot mutatja.</span>
		</div>
		${!data.cards.length ? html`<${Empty} icon="users" title="Még nincs érdeklődő">A weboldal űrlapjai és a Grader ide hozzák őket, vagy vedd fel kézzel („Új érdeklődő”).</${Empty}>` : null}
		<div class="board board--sales" ref=${boardRef}>
			${cols.map((col) => html`
				<section key=${col.key} data-stage=${col.key} class=${'col col--stage-' + col.key + (drag && drag.over === col.key ? ' is-over' : '')}>
					<header class="col__head"><span class=${'dot dot--stage-' + col.key}></span><h3>${col.label}</h3><span class="muted">${col.items.length}</span></header>
					<div class="col__body">
						${col.items.map((c) => html`<${LeadCard} key=${c.id} c=${c} users=${boot.users} stages=${data.stages} dragged=${drag && drag.id === c.id ? drag : null} onPointerDown=${(e) => onPointerDown(e, c)} onMove=${(s) => move(c, s)} />`)}
					</div>
				</section>`)}
		</div>
		${drag ? html`<div class="drag-ghost" style=${'left:' + drag.x + 'px;top:' + drag.y + 'px'}>${drag.name}${drag.over ? html` → <strong>${(data.stages.find((s) => s.key === drag.over) || {}).label}</strong>` : null}</div>` : null}
		${losing ? html`<${LostModal} reasons=${data.lostReasons} name=${losing.card.name} onClose=${() => setLosing(null)} onSave=${(r) => { const c = losing.card; setLosing(null); move(c, 'lost', r); }} />` : null}`;
}

function LeadCard({ c, users, stages, dragged, onPointerDown, onMove }) {
	const owner = users.find((u) => u.id === c.owner);
	return html`
		<article class=${'card-task card-lead' + (dragged ? ' is-dragged' : '') + (c.sla_breach ? ' is-late' : '')} onPointerDown=${onPointerDown} tabIndex="0"
			onKeyDown=${(e) => e.key === 'Enter' && navigate('/clients/' + c.id)}>
			<span class="card-task__title"><strong>${c.name}</strong>${c.contact_name && c.contact_name !== c.name ? html`<small class="muted"> · ${c.contact_name}</small>` : null}</span>
			<span class="lead-chips">
				<span class=${'chip chip--ch-' + c.channel}>${c.channel_label}</span>
				${c.source && c.source !== 'manual' ? html`<span class="chip">${c.source_label}</span>` : null}
				${c.campaign ? html`<span class="chip chip--muted" title="Kampány">${c.campaign}</span>` : null}
				${c.country === 'HU' ? html`<span class="chip chip--muted">HU</span>` : null}
			</span>
			${c.stage === 'new' && c.waiting_hours !== null ? html`<span class=${'lead-wait' + (c.sla_breach ? ' is-late' : '')}><${Icon} name="clock" size="14" /> ${hoursLabel(c.waiting_hours)} óta vár${c.phone ? ' · ' + c.phone : ''}</span>` : null}
			${c.next_step || c.next_step_date ? html`<span class=${'lead-next' + (c.overdue ? ' is-late' : c.due_today ? ' is-today' : '')}><${Icon} name="flag" size="14" /> ${c.next_step || 'Következő lépés'}${c.next_step_date ? ' · ' + shortDate(c.next_step_date) : ''}</span>` : null}
			${c.stage === 'lost' && c.lost_reason ? html`<span class="lead-next muted">✕ ${c.lost_reason}</span>` : null}
			<span class="card-task__foot">
				<span class="lead-value" title="Becsült érték">${c.value_label || html`<span class="muted">—</span>`}</span>
				<span class="lead-foot-right">
					<select class="lead-stage-select" value=${c.stage} onChange=${(e) => onMove(e.target.value)} aria-label="Szakasz" title="Szakasz">
						${stages.map((s) => html`<option value=${s.key}>${s.label}</option>`)}
					</select>
					${owner ? html`<${Avatar} user=${owner} size="24" />` : html`<span class="muted small" title="Nincs felelős">—</span>`}
				</span>
			</span>
		</article>`;
}

function LostModal({ reasons, name, onClose, onSave }) {
	const [reason, setReason] = useState(reasons[0]);
	const [text, setText] = useState('');
	const submit = (e) => { e.preventDefault(); onSave(reason === 'Egyéb' ? (text.trim() || 'Egyéb') : reason + (text.trim() ? ': ' + text.trim() : '')); };
	return html`
		<${Modal} title=${'Elveszett: ' + name} onClose=${onClose}>
			<form class="form" onSubmit=${submit}>
				<div class="reason-list">${reasons.map((r) => html`<label key=${r} class="check-item"><input type="radio" name="reason" checked=${reason === r} onChange=${() => setReason(r)} /> <span>${r}</span></label>`)}</div>
				<label class="field"><span>${reason === 'Egyéb' ? 'Mi történt?' : 'Részletek (nem kötelező)'}</span><input value=${text} onInput=${(e) => setText(e.target.value)} placeholder=${reason === 'Mást választott' ? 'pl. kit, mennyiért' : ''} /></label>
				<p class="hint">Az okokból a Források fülön látszik, hol veszítünk. Ha később újra jelentkezik, magától visszakerül a tölcsér elejére.</p>
				<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn btn--danger">Elveszett</button></footer>
			</form>
		</${Modal}>`;
}

function NewLeadModal({ onClose, onSaved }) {
	const { boot } = useApp();
	const [channels, setChannels] = useState([]);
	const [f, setF] = useState({ name: '', contact_name: '', email: '', phone: '', country: 'US', channel: 'offline', value: '', next_step: 'Első hívás', next_step_date: todayISO(1), note: '', owner: boot.me.id });
	const [busy, setBusy] = useState(false);
	const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
	useEffect(() => { api('/sales/meta').then((d) => setChannels(d.channels.filter((c) => c.key !== 'import'))).catch(() => {}); }, []);
	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/sales/leads', { method: 'POST', body: f })
			.then((c) => { toast('Érdeklődő felvéve.'); onSaved(c); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};
	return html`
		<${Modal} title="Új érdeklődő" onClose=${onClose} wide>
			<form class="form" onSubmit=${submit}>
				<div class="row">
					<label class="field"><span>Cég vagy név</span><input required value=${f.name} onInput=${set('name')} autoFocus /></label>
					<label class="field"><span>Kapcsolattartó</span><input value=${f.contact_name} onInput=${set('contact_name')} /></label>
				</div>
				<div class="row row--3">
					<label class="field"><span>E-mail</span><input type="email" value=${f.email} onInput=${set('email')} /></label>
					<label class="field"><span>Telefon</span><input value=${f.phone} onInput=${set('phone')} /></label>
					<label class="field"><span>Ország</span><select value=${f.country} onChange=${set('country')}><option value="US">USA</option><option value="HU">Magyarország</option></select></label>
				</div>
				<div class="row row--3">
					<label class="field"><span>Honnan jött?</span><select value=${f.channel} onChange=${set('channel')}>${channels.map((c) => html`<option value=${c.key}>${c.label}</option>`)}</select></label>
					<label class="field"><span>Becsült érték (${f.country === 'HU' ? 'HUF' : 'USD'}, első év)</span><input type="number" min="0" step="1" value=${f.value} onInput=${set('value')} /></label>
					<label class="field"><span>Felelős</span><select value=${f.owner} onChange=${set('owner')}>${boot.users.map((u) => html`<option value=${u.id}>${u.name}</option>`)}</select></label>
				</div>
				<div class="row">
					<label class="field"><span>Következő lépés</span><input value=${f.next_step} onInput=${set('next_step')} /></label>
					<label class="field"><span>Mikor</span><input type="date" value=${f.next_step_date} onInput=${set('next_step_date')} /></label>
				</div>
				<label class="field"><span>Jegyzet (belső)</span><textarea rows="3" value=${f.note} onInput=${set('note')} placeholder="pl. mit szeretne, ki ajánlotta"></textarea></label>
				<footer class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>Felvétel</button></footer>
			</form>
		</${Modal}>`;
}

/* ── Források ────────────────────────────────────── */

const PERIODS = [
	{ key: '30', label: '30 nap', range: () => [todayISO(-29), todayISO()] },
	{ key: '90', label: '90 nap', range: () => [todayISO(-89), todayISO()] },
	{ key: 'year', label: 'Idei év', range: () => [todayISO().slice(0, 4) + '-01-01', todayISO()] },
	{ key: '365', label: '12 hónap', range: () => [todayISO(-364), todayISO()] },
];
const GROUPS = { channel: 'Csatorna', campaign: 'Kampány', source: 'Űrlap' };

function Sources({ params }) {
	const [data, setData] = useState(null);
	const period = PERIODS.find((p) => p.key === params.period) || PERIODS[1];
	const group = GROUPS[params.group] ? params.group : 'channel';
	useEffect(() => {
		setData(null);
		const [from, to] = period.range();
		api('/sales/sources?' + new URLSearchParams({ from, to, group })).then(setData).catch((e) => toast(e.message, 'error'));
	}, [period.key, group]);

	const max = data ? Math.max(1, ...data.rows.map((r) => r.leads)) : 1;
	const maxMonth = data ? Math.max(1, ...data.months.map((m) => m.leads)) : 1;
	return html`
		<div class="toolbar">
			<div class="seg">${PERIODS.map((p) => html`<button key=${p.key} class=${p.key === period.key ? 'is-active' : ''} onClick=${() => setParam('period', p.key)}>${p.label}</button>`)}</div>
			<div class="seg">${Object.entries(GROUPS).map(([k, l]) => html`<button key=${k} class=${k === group ? 'is-active' : ''} onClick=${() => setParam('group', k)}>${l}</button>`)}</div>
		</div>
		${!data ? html`<${Spinner} />` : !data.rows.length ? html`<${Empty} icon="users" title="Ebben az időszakban nem jött érdeklődő" />` : html`
			<div class="kpis">
				<div class="kpi"><span>Érdeklődő</span><strong>${data.total.leads}</strong><small>${shortDate(data.from)} – ${shortDate(data.to)}</small></div>
				<div class="kpi"><span>Megnyert</span><strong>${data.total.won}</strong><small>nyerési arány: ${data.total.win_rate === null ? '—' : data.total.win_rate + '%'}</small></div>
				<div class="kpi"><span>Átlagos első válasz</span><strong>${hoursLabel(data.total.response_hours)}</strong><small>${data.total.open} még nyitott</small></div>
				${data.money ? html`<div class="kpi"><span>Bevétel tőlük eddig</span><strong class="kpi__money">${data.total.revenue_label}</strong><small>havi díj: ${data.total.mrr_label}</small></div>` : null}
			</div>
			${data.months.length > 1 ? html`
				<section class="card month-bars" aria-label="Érdeklődők havonta">
					${data.months.map((m) => html`<div key=${m.month} class=${'month-bar' + (m.leads ? '' : ' is-empty')} title=${m.month + ': ' + m.leads + ' érdeklődő, ' + m.won + ' megnyert'}>
						<span class="month-bar__col"><i style=${'height:' + Math.round((100 * m.leads) / maxMonth) + '%'}></i><b style=${'height:' + Math.round((100 * m.won) / maxMonth) + '%'}></b></span>
						<small>${m.month.slice(5) === '01' ? m.month.slice(0, 4) : m.month.slice(5) + '.'}</small><em>${m.leads || ''}</em></div>`)}
					<p class="hint">Szürke: érdeklődő, zöld: ebből megnyert.</p>
				</section>` : null}
			<div class="card table-card"><table class="table table--sources">
				<thead><tr><th>${GROUPS[group]}</th><th class="right">Érdeklődő</th><th class="right col-opt">Ajánlat</th><th class="right">Megnyert</th><th class="right col-opt">Elveszett</th><th class="right">Nyerési arány</th><th class="right col-opt">Első válasz</th>${data.money ? html`<th class="right">Bevétel eddig</th><th class="right col-opt">Havi díj</th>` : null}</tr></thead>
				<tbody>${data.rows.map((r) => html`<tr key=${r.key}>
					<td><strong>${r.label}</strong><span class="src-bar"><i style=${'width:' + Math.round((100 * r.leads) / max) + '%'}></i></span></td>
					<td class="right">${r.leads}</td><td class="right col-opt">${r.proposals}</td><td class="right"><strong>${r.won}</strong></td><td class="right col-opt">${r.lost}</td>
					<td class="right">${r.win_rate === null ? html`<span class="muted">${r.open} nyitott</span>` : r.win_rate + '%'}</td>
					<td class="right col-opt">${hoursLabel(r.response_hours)}</td>
					${data.money ? html`<td class="right">${r.revenue_label}</td><td class="right col-opt">${r.mrr_label}</td>` : null}
				</tr>`)}</tbody>
				<tfoot><tr><td>Összesen</td><td class="right">${data.total.leads}</td><td class="right col-opt">${data.total.proposals}</td><td class="right">${data.total.won}</td><td class="right col-opt">${data.total.lost}</td><td class="right">${data.total.win_rate === null ? '—' : data.total.win_rate + '%'}</td><td class="right col-opt">${hoursLabel(data.total.response_hours)}</td>${data.money ? html`<td class="right">${data.total.revenue_label}</td><td class="right col-opt">${data.total.mrr_label}</td>` : null}</tr></tfoot>
			</table></div>
			<p class="hint">Az időszakban érkezett érdeklődők, a csatorna az első látogatásuk szerint (a weboldal megjegyzi a UTM-et, a Google/Meta kattintást és a hivatkozó oldalt). A bevétel a megnyertek összes eddigi befizetése. Kampánylinkekhez: <code>?utm_source=…&utm_medium=…&utm_campaign=…</code></p>`}`;
}

/* ── Adatlap panel ───────────────────────────────── */

export function SalesPanel({ client, onChange }) {
	const { boot } = useApp();
	const s = client.sales;
	const [f, setF] = useState(null);
	const [meta, setMeta] = useState(null);
	const [busy, setBusy] = useState(false);
	const [losing, setLosing] = useState(false);
	useEffect(() => {
		setF({ owner: s.owner, value: s.value || '', next_step: s.next_step, next_step_date: s.next_step_date, channel: s.channel, campaign: s.campaign });
	}, [client.id, s.stage, s.owner, s.next_step, s.next_step_date, s.value, s.channel]);
	useEffect(() => { api('/sales/meta').then(setMeta).catch(() => {}); }, []);
	if (!f || !meta) return null;
	const save = (body) => {
		setBusy(true);
		return api('/sales/leads/' + client.id, { method: 'POST', body })
			.then((d) => { toast('Mentve.'); onChange(d); })
			.catch((e) => toast(e.message, 'error'))
			.finally(() => setBusy(false));
	};
	const stage = (key, reason) => {
		if (key === 'lost' && !reason) { setLosing(true); return; }
		if (key === 'won' && !confirm('Megnyert: aktív ügyfél lesz. Mehet?')) return;
		save({ stage: key, lost_reason: reason || '' });
	};
	const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
	const dirty = String(f.owner) !== String(s.owner) || String(f.value || 0) !== String(s.value || 0) || f.next_step !== s.next_step || (f.next_step_date || '') !== (s.next_step_date || '') || f.channel !== s.channel || f.campaign !== s.campaign;
	const open = ['new', 'contacted', 'qualified', 'proposal'].includes(s.stage);
	const a = s.attribution || {};
	return html`
		<section class="card sales-panel">
			<h3>Értékesítés</h3>
			<div class="stage-steps">${meta.stages.map((st) => html`<button key=${st.key} type="button" disabled=${busy || s.stage === 'won'} class=${'stage-step stage-step--' + st.key + (st.key === s.stage ? ' is-active' : '')} onClick=${() => stage(st.key)}>${st.label}</button>`)}</div>
			<p class="muted small">
				${s.source_label}${s.lead_at ? ' · érdeklődött ' + new Date(s.lead_at * 1000).toLocaleDateString('hu-HU') : ''}
				${s.response_hours !== null ? ' · első válasz ' + hoursLabel(s.response_hours) + ' alatt' : s.stage === 'new' && s.waiting_hours !== null ? html` · <strong class=${s.sla_breach ? 'text-late' : ''}>${hoursLabel(s.waiting_hours)} óta vár válaszra</strong>` : ''}
				${s.stage === 'lost' && s.lost_reason ? ' · elveszett: ' + s.lost_reason : ''}
			</p>
			${open || s.stage === 'lost' ? html`
				<div class="row">
					<label class="field"><span>Következő lépés</span><input value=${f.next_step} onInput=${set('next_step')} placeholder="pl. ajánlat egyeztetése" /></label>
					<label class="field"><span>Mikor</span><input type="date" value=${f.next_step_date || ''} onInput=${set('next_step_date')} /></label>
				</div>
				<div class="row">
					<label class="field"><span>Becsült érték (${s.currency}, első év)</span><input type="number" min="0" step="1" value=${f.value} onInput=${set('value')} /></label>
					<label class="field"><span>Felelős</span><select value=${f.owner} onChange=${set('owner')}><option value="0">— nincs —</option>${boot.users.map((u) => html`<option value=${u.id}>${u.name}</option>`)}</select></label>
				</div>` : null}
			<div class="row">
				<label class="field"><span>Csatorna</span><select value=${f.channel} onChange=${set('channel')}>${meta.channels.map((c) => html`<option value=${c.key}>${c.label}</option>`)}</select></label>
				<label class="field"><span>Kampány</span><input value=${f.campaign} onInput=${set('campaign')} /></label>
			</div>
			${dirty ? html`<p><button class="btn btn--small" disabled=${busy} onClick=${() => save(f)}>Mentés</button></p>` : null}
			${a.first ? html`
				<details class="touch">
					<summary>Honnan jött (weboldal)</summary>
					${['first', 'last'].filter((w) => a[w] && (w === 'first' || JSON.stringify(a.last) !== JSON.stringify(a.first))).map((w) => html`
						<dl class="kv" key=${w}><dt>${w === 'first' ? 'Első látogatás' : 'Utolsó kampány'}</dt><dd>${a[w].at ? new Date(a[w].at * 1000).toLocaleString('hu-HU') : ''}</dd>
						${Object.entries(TOUCH).filter(([k]) => a[w][k]).map(([k, l]) => html`<dt>${l}</dt><dd class="break">${a[w][k]}</dd>`)}</dl>`)}
				</details>` : null}
			${losing ? html`<${LostModal} reasons=${meta.lostReasons} name=${client.name} onClose=${() => setLosing(false)} onSave=${(r) => { setLosing(false); stage('lost', r); }} />` : null}
		</section>`;
}
