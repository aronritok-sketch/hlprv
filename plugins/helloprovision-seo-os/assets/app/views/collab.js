/**
 * Együttműködés: értesítés-csengő, megjegyzések (@említéssel, válasszal, megoldottnak jelöléssel),
 * jóváhagyási panel (belső kérés / döntés, ügyfélnek küldés az ügyfélportálon keresztül).
 */
import { html, useState, useEffect, useRef, useApp, api, useLoad, toast, errorText, fmt, navigate, Icon, Spinner, Modal, Field, Input, Textarea, Select, Checkbox, Pill, can } from '../ui.js';

/* ── Értesítések ──────────────────────────────────── */

export function Bell() {
	const [data, setData] = useState({ unread: 0, items: [] });
	const [open, setOpen] = useState(false);
	const ref = useRef(null);
	const load = () => api('/me/notifications').then(setData).catch(() => {});
	useEffect(() => {
		load();
		const t = setInterval(() => document.visibilityState === 'visible' && load(), 60000);
		return () => clearInterval(t);
	}, []);
	useEffect(() => {
		if (!open) return undefined;
		const close = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
		document.addEventListener('mousedown', close);
		return () => document.removeEventListener('mousedown', close);
	}, [open]);
	const go = async (n) => {
		setOpen(false);
		if (!n.read) { api('/me/notifications/read', { method: 'POST', body: { ids: [n.id] } }).then(load); }
		if (n.link) window.location.hash = n.link.replace(/^#/, '');
	};
	const readAll = async () => { await api('/me/notifications/read', { method: 'POST', body: { all: true } }); load(); };
	return html`<div class="bell" ref=${ref}>
		<button class="icon-btn" aria-label="Értesítések" title="Értesítések" onClick=${() => { setOpen(!open); if (!open) load(); }}>
			<${Icon} name="bell" size="18" />${data.unread ? html`<span class="bell__dot">${data.unread > 9 ? '9+' : data.unread}</span>` : ''}
		</button>
		${open ? html`<div class="bell__panel">
			<header><strong>Értesítések</strong>${data.unread ? html`<button class="link small" onClick=${readAll}>Mind olvasott</button>` : ''}</header>
			${data.items.length ? html`<ul>${data.items.map((n) => html`<li key=${n.id} class=${n.read ? '' : 'is-unread'} onClick=${() => go(n)}>
				<span class="bell__title">${n.title}</span>
				${n.body ? html`<span class="bell__body">${n.body}</span>` : ''}
				<span class="muted small">${fmt.ago(n.created_at)}</span>
			</li>`)}</ul>` : html`<p class="muted pad small">Nincs értesítésed.</p>`}
		</div>` : ''}
	</div>`;
}

/* ── Megjegyzések ─────────────────────────────────── */

export function Comments({ subject, id, title = 'Megjegyzések' }) {
	const { me } = useApp();
	const [items, loading, , reload] = useLoad('/comments?subject_type=' + subject + '&subject_id=' + id, [subject, id]);
	const [text, setText] = useState('');
	const [reply, setReply] = useState(null);
	const [busy, setBusy] = useState(false);
	const [users] = useLoad(can(me, 'team.view') ? '/users' : null);
	const send = async () => {
		if (!text.trim()) return;
		setBusy(true);
		try { await api('/comments', { method: 'POST', body: { subject_type: subject, subject_id: id, body: text, parent_id: reply } }); setText(''); setReply(null); reload(); } catch (e) { toast(errorText(e), 'error'); }
		setBusy(false);
	};
	const patch = async (c, body) => { try { await api('/comments/' + c.id, { method: 'PATCH', body }); reload(); } catch (e) { toast(errorText(e), 'error'); } };
	const remove = async (c) => { if (window.confirm('Törlöd a megjegyzést?')) { try { await api('/comments/' + c.id, { method: 'DELETE' }); reload(); } catch (e) { toast(errorText(e), 'error'); } } };
	const roots = (items || []).filter((c) => !c.parent_id);
	const kids = (pid) => (items || []).filter((c) => c.parent_id === pid);
	const mention = (u) => setText((t) => (t && !t.endsWith(' ') ? t + ' ' : t) + '@' + u.display_name + ' ');
	const one = (c, child) => html`<div key=${c.id} class=${'comment' + (child ? ' comment--reply' : '') + (c.resolved ? ' is-resolved' : '') + (c.is_client ? ' comment--client' : '')}>
		<div class="comment__head"><strong>${c.author}</strong>${c.is_client ? html`<${Pill} kind="warn">Ügyfél</${Pill}>` : ''}<span class="muted small">${fmt.datetime(c.created_at)}${c.edited_at ? ' · szerkesztve' : ''}</span>
			<span class="toolbar__spacer"></span>
			${!child ? html`<button class="link small" onClick=${() => setReply(c.id)}>Válasz</button>
				<button class="link small" onClick=${() => patch(c, { resolved: !c.resolved })}>${c.resolved ? 'Újranyitás' : 'Megoldva'}</button>` : ''}
			${c.mine || me.role === 'admin' ? html`<button class="icon-btn" title="Törlés" onClick=${() => remove(c)}><${Icon} name="trash" size="13" /></button>` : ''}
		</div>
		<div class="comment__body">${c.body}</div>
		${!child ? kids(c.id).map((k) => one(k, true)) : ''}
	</div>`;
	return html`<section class="card comments">
		<header class="card__head"><h2><${Icon} name="chat" /> ${title}${items && items.length ? html`<span class="count">${items.length}</span>` : ''}</h2></header>
		<div class="pad stack-sm">
			${loading && !items ? html`<${Spinner} />` : roots.length ? roots.map((c) => one(c, false)) : html`<p class="muted small">Még nincs megjegyzés. Az @név említés értesítést küld a kollégának.</p>`}
			${can(me, 'comments.write') ? html`<div class="comment-form">
				${reply ? html`<div class="muted small">Válasz: ${(items.find((c) => c.id === reply) || {}).author} <button class="link small" onClick=${() => setReply(null)}>mégse</button></div>` : ''}
				<${Textarea} rows="2" value=${text} onInput=${setText} placeholder="Megjegyzés… (@név – értesítés)" onKeyDown=${(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) send(); }} />
				<div class="row-edit">
					${users ? html`<${Select} value="" placeholder="@ említés…" onChange=${(v) => { const u = users.find((x) => String(x.id) === v); if (u) mention(u); }} options=${users.filter((u) => u.is_active).map((u) => [String(u.id), u.display_name])} />` : ''}
					<button class="btn btn--sm" disabled=${busy || !text.trim()} onClick=${send}>Küldés</button>
				</div>
			</div>` : ''}
		</div>
	</section>`;
}

/* ── Jóváhagyás ───────────────────────────────────── */

function ClientReviewModal({ doc, project, onClose, onSent }) {
	const [form, setForm] = useState({ email: '', message: '', language: doc.language, chat: true });
	const [busy, setBusy] = useState(false);
	const send = async () => {
		setBusy(true);
		try {
			const r = await api('/crm/client-review', { method: 'POST', body: { document_id: doc.id, ...form } });
			if (r.portal_id) toast('Elküldve az ügyfélportálra jóváhagyásra (az ügyfél értesítést kap, a döntése ide visszaér).', 'ok');
			else toast(r.emailed || r.chat ? 'Elküldve az ügyfélnek' + (r.chat ? ' (e-mail + portál üzenet).' : ' (e-mail).') : 'A jóváhagyó link elkészült – nem ment ki értesítés (nincs CRM ügyfél / e-mail).', r.emailed || r.chat ? 'ok' : 'error');
			onSent(r);
		} catch (e) { toast(errorText(e), 'error'); }
		setBusy(false);
	};
	return html`<${Modal} title="Küldés ügyfél-jóváhagyásra" onClose=${onClose}
		footer=${html`<button class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy} onClick=${send}>${busy ? 'Küldés…' : 'Küldés'}</button>`}>
		<div class="stack-sm">
			<p class="muted small">Az ügyfél titkos linket kap (${project.client.crm_client_id ? 'az ügyfélportálon, a Jóváhagyás menüben (e-mail értesítéssel)' : 'e-mailben'}); a linken letöltheti a dokumentumot, kérdezhet, jóváhagyhatja vagy módosítást kérhet. A döntésről értesítést kapsz.</p>
			${!project.client.crm_client_id ? html`<p class="alert alert--info small"><span>A projekt ügyfele nincs összekötve CRM ügyféllel – add meg az e-mail címet.</span></p>` : ''}
			<${Field} label=${'E-mail cím' + (project.client.crm_client_id ? ' (opcionális, a portál-felhasználókon felül)' : '')}><${Input} type="email" value=${form.email} onInput=${(v) => setForm({ ...form, email: v })} /></${Field}>
			<${Field} label="Kísérő üzenet"><${Textarea} rows="3" value=${form.message} onInput=${(v) => setForm({ ...form, message: v })} /></${Field}>
			<${Field} label="Az oldal nyelve"><${Select} value=${form.language} onChange=${(v) => setForm({ ...form, language: v })} options=${[['hu', 'Magyar'], ['en', 'Angol']]} /></${Field}>
			${project.client.crm_client_id ? html`<${Checkbox} checked=${form.chat} label="Üzenet az ügyfél portál-chatjébe is" onChange=${(v) => setForm({ ...form, chat: v })} />` : ''}
		</div>
	</${Modal}>`;
}

export function ApprovalPanel({ subject, id, doc, project, onChanged }) {
	const { me } = useApp();
	const [list, loading, , reload] = useLoad('/approvals?subject_type=' + subject + '&subject_id=' + id, [subject, id]);
	const [note, setNote] = useState('');
	const [modal, setModal] = useState(false);
	const [link, setLink] = useState('');
	if (loading && !list) return '';
	const pendingInternal = (list || []).find((a) => a.stage === 'internal' && a.status === 'pending');
	const client = (list || []).find((a) => a.stage === 'client' && a.status !== 'cancelled');
	const done = () => { reload(); onChanged && onChanged(); };
	const request = async () => { try { await api('/approvals', { method: 'POST', body: { subject_type: subject, subject_id: id } }); toast('Jóváhagyási kérés elküldve.'); done(); } catch (e) { toast(errorText(e), 'error'); } };
	const decide = async (decision) => {
		try { await api('/approvals/' + pendingInternal.id + '/decide', { method: 'POST', body: { decision, note } }); setNote(''); done(); } catch (e) { toast(errorText(e), 'error'); }
	};
	const canApprove = can(me, 'approve.internal') && pendingInternal && (!pendingInternal.requested_from_id || pendingInternal.requested_from_id === me.id);
	const status = doc ? doc.status : null;
	return html`<div class="approval">
		${pendingInternal ? html`<div class="approval__row"><${Pill} kind="warn">Belső jóváhagyásra vár</${Pill}><span class="muted small">${pendingInternal.requested_by} · ${fmt.ago(pendingInternal.created_at)}</span>
			${pendingInternal.requested_by === me.name || can(me, 'approve.internal') ? html`<button class="link small" onClick=${async () => { await api('/approvals/' + pendingInternal.id, { method: 'DELETE' }); done(); }}>Visszavonás</button>` : ''}</div>` : ''}
		${canApprove ? html`<div class="approval__decide">
			<${Textarea} rows="2" value=${note} onInput=${setNote} placeholder="Megjegyzés (módosításkérésnél kötelező)" />
			<div class="row-edit"><button class="btn btn--sm" onClick=${() => decide('approved')}><${Icon} name="check" size="14" /> Jóváhagyom</button>
			<button class="btn btn--ghost btn--sm" onClick=${() => decide('changes_requested')}>Módosítást kérek</button></div>
		</div>` : ''}
		${!pendingInternal && status && ['draft', 'review'].includes(status) && (can(me, 'documents.generate') || can(me, 'documents.generate.writer') || can(me, 'wireframes.edit')) ? html`<button class="btn btn--ghost btn--sm" onClick=${request}>Jóváhagyás kérése</button>` : ''}
		${doc && doc.audience === 'client' && ['approved', 'sent'].includes(status) && can(me, 'approve.request_client') ? html`<button class="btn btn--sm" onClick=${() => setModal(true)}><${Icon} name="ext" size="14" /> ${client ? 'Újraküldés ügyfélnek' : 'Küldés ügyfélnek'}</button>` : ''}
		${client ? html`<div class="approval__row">
			<${Pill} kind=${client.status === 'approved' ? 'ok' : client.status === 'changes_requested' ? 'danger' : 'warn'}>Ügyfél: ${client.status_label}</${Pill}>
			<span class="muted small">${client.decided_at ? client.decided_by + ' · ' + fmt.datetime(client.decided_at) : client.viewed_at ? 'megnyitotta ' + fmt.ago(client.viewed_at) : 'elküldve ' + fmt.ago(client.created_at)}</span>
			${client.decision_note ? html`<span class="small">„${client.decision_note}”</span>` : ''}
		</div>` : ''}
		${link ? html`<div class="approval__row small"><span class="muted">${link.includes('/review/') ? 'Jóváhagyó link:' : 'A portálon:'}</span> <a class="link url" href=${link} target="_blank" rel="noopener">${link}</a></div>` : ''}
		${modal ? html`<${ClientReviewModal} doc=${doc} project=${project} onClose=${() => setModal(false)} onSent=${(r) => { setModal(false); setLink(r.url || r.portal || ''); done(); }} />` : ''}
	</div>`;
}
