/**
 * HelloProVision CRM — Levelezés (#/mail).
 * Beérkezett / elküldött levelek, olvasás, válasz, továbbítás, új levél egységes aláírással, feladat levélből,
 * ügyfélhez kötés; az adminnak postafiókok, aláírás és munkatárs-adatok (#/mail/settings).
 * A CRM ui.js modulját használja (ugyanaz a példány, mint az alkalmazásé), és a bővítés-ponton regisztrál.
 */
const ui = await import(window.HPV_APP.ui);
const { RichText } = await import(new URL('views/richtext.js', window.HPV_APP.ui).href);
const { html, useState, useEffect, useRef, useCallback, useMemo, api, CFG, useApp, navigate, setParam, Icon, Avatar, Modal, Empty, Spinner, toast, shortDate, timeAgo, registerExtension, setExtensionBadge } = ui;

const ICONS = {
	mail: 'M3 6h18v12H3zM3 7l9 6 9-6',
	reply: 'M10 8L5 12l5 4M5 12h9a5 5 0 015 5v1',
	forward: 'M14 8l5 4-5 4M19 12h-9a5 5 0 00-5 5v1',
	attach: 'M16 7l-7.5 7.5a2.5 2.5 0 003.5 3.5L19.5 10a4.5 4.5 0 00-6.4-6.4L5.6 11.1a6.5 6.5 0 009.2 9.2L20 15',
	sync: 'M20 11a8 8 0 00-14.3-4.9L4 8M4 4v4h4M4 13a8 8 0 0014.3 4.9L20 16M20 20v-4h-4',
	unread: 'M3 6h18v12H3zM3 7l9 6 9-6M18 3a3 3 0 110 6 3 3 0 010-6',
};

/* ── Segédek ─────────────────────────────────────── */

async function rawUpload(file) {
	const res = await fetch(CFG.rest + '/mail/uploads', {
		method: 'POST',
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': CFG.nonce, 'X-Filename': encodeURIComponent(file.name), 'Content-Type': file.type || 'application/octet-stream' },
		body: file,
	});
	const data = await res.json().catch(() => ({}));
	if (!res.ok) throw new Error(data.message || 'A feltöltés nem sikerült.');
	return data;
}

const attachmentUrl = (msgId, i) => `${CFG.rest}/mail/messages/${msgId}/attachments/${i}?_wpnonce=${encodeURIComponent(CFG.nonce)}`;
const initials = (name) => String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
const who = (a) => (a && (a.name || a.email)) || '';
const fmtSize = (n) => (n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB');
const escapeHtml = (s) => String(s || '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);

function parseAddresses(text) {
	return String(text || '').split(/[,;\n]/).map((s) => s.trim()).filter(Boolean).map((s) => {
		const m = s.match(/^(.*)<([^>]+)>$/);
		return m ? { name: m[1].trim().replace(/^"|"$/g, ''), email: m[2].trim() } : { name: '', email: s };
	});
}

function msgDate(iso) {
	if (!iso) return '';
	const d = new Date(iso);
	const today = new Date();
	return d.toDateString() === today.toDateString()
		? d.toLocaleTimeString('hu-HU', { hour: '2-digit', minute: '2-digit' })
		: d.toLocaleDateString('hu-HU', { month: 'short', day: 'numeric' });
}

function useMe() {
	const [me, setMe] = useState(null);
	const [error, setError] = useState('');
	const load = useCallback(() => api('/mail/me').then((r) => {
		setMe(r);
		setExtensionBadge('/mail', r.accounts.reduce((n, a) => n + a.unread, 0));
	}).catch((e) => setError(e.message)), []);
	useEffect(() => { load(); }, [load]);
	return [me, load, error];
}

/* ── Levél megjelenítése (elszigetelt keretben, szkript nélkül) ── */

function MailBody({ htmlBody }) {
	const ref = useRef(null);
	const doc = `<!doctype html><html><head><meta charset="utf-8"><base target="_blank"><style>body{margin:0;font:14px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#121212;overflow-wrap:anywhere}img{max-width:100%;height:auto}blockquote{margin:0 0 0 .8ex;border-left:2px solid #ddd;padding-left:1ex;color:#555}table{max-width:100%}</style></head><body>${htmlBody}</body></html>`;
	const fit = () => {
		const f = ref.current;
		try { if (f && f.contentDocument) f.style.height = Math.max(120, f.contentDocument.documentElement.scrollHeight + 8) + 'px'; } catch (e) { /* elszigetelt keret */ }
	};
	return html`<iframe ref=${ref} class="mail-body" title="Levél tartalma" sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin" srcdoc=${doc} onLoad=${fit}></iframe>`;
}

/* ── Oldal ───────────────────────────────────────── */

function MailPage({ params }) {
	const { boot } = useApp();
	const [me, reloadMe, meError] = useMe();
	const folder = params.folder || 'inbox';
	const account = params.account ? Number(params.account) : null;
	const client = params.client ? Number(params.client) : null;
	const unreadOnly = params.unread === '1';
	const [q, setQ] = useState(params.q || '');
	const [list, setList] = useState(null);
	const [page, setPage] = useState(1);
	const [compose, setCompose] = useState(null);
	const [syncing, setSyncing] = useState(false);
	const selected = params.m ? Number(params.m) : null;

	const load = useCallback(() => {
		const qs = new URLSearchParams({ folder: client ? 'all' : folder, page: String(page) });
		if (account) qs.set('account_id', String(account));
		if (client) qs.set('client_id', String(client));
		if (unreadOnly) qs.set('unread', '1');
		if (params.q) qs.set('q', params.q);
		return api('/mail/messages?' + qs.toString()).then(setList).catch((e) => { toast(e.message, 'error'); setList({ items: [], total: 0 }); });
	}, [folder, account, client, unreadOnly, params.q, page]);
	useEffect(() => { load(); }, [load]);
	useEffect(() => {
		const t = setInterval(() => { if (!document.hidden) { load(); reloadMe(); } }, 60000);
		return () => clearInterval(t);
	}, [load, reloadMe]);

	if (meError) return html`<${Empty} icon="x" title="A levelezés nem érhető el">${meError}</${Empty}>`;
	if (!me) return html`<${Spinner} />`;
	if (!me.accounts.length) {
		return html`<div class="page page--narrow"><header class="page__head"><div><h1>Levelezés</h1></div></header>
			<${Empty} icon="mail" title="Még nincs postafiókod">
				${me.is_admin ? html`<p>Vedd fel a csapat postafiókjait és állítsd be az egységes aláírást.</p><a class="btn" href="#/mail/settings">Postafiókok beállítása</a>` : 'Az admin veszi fel a postafiókodat, utána itt látod a leveleidet.'}
			</${Empty}></div>`;
	}

	const clientName = (id) => (boot.clients.find((c) => c.id === id) || {}).name || '';
	const setFilter = (patch) => { setPage(1); navigate('/mail', { ...params, m: undefined, ...patch }); };
	const sync = async () => {
		setSyncing(true);
		try { await api('/mail/sync', { method: 'POST', body: account ? { account_id: account } : {} }); toast('Szinkron elindítva – az új levelek pár másodpercen belül megjelennek.'); setTimeout(() => { load(); reloadMe(); }, 5000); } catch (e) { toast(e.message, 'error'); }
		setSyncing(false);
	};
	const open = (m) => navigate('/mail', { ...params, m: String(m.id) });
	const current = list && list.items.find((m) => m.id === selected);

	return html`
		<div class="mail">
			<aside class="mail-side">
				<button class="btn mail-new" onClick=${() => setCompose({})}><${Icon} name="plus" /> Új levél</button>
				<nav class="mail-folders">
					<a class=${!client && folder === 'inbox' && !unreadOnly ? 'is-active' : ''} href="#/mail" onClick=${(e) => { e.preventDefault(); setFilter({ folder: 'inbox', unread: undefined, client: undefined }); }}><${Icon} name="mail" /> Beérkezett</a>
					<a class=${unreadOnly ? 'is-active' : ''} href="#/mail?unread=1" onClick=${(e) => { e.preventDefault(); setFilter({ folder: 'inbox', unread: '1', client: undefined }); }}><${Icon} name="unread" /> Olvasatlan</a>
					<a class=${!client && folder === 'sent' ? 'is-active' : ''} href="#/mail?folder=sent" onClick=${(e) => { e.preventDefault(); setFilter({ folder: 'sent', unread: undefined, client: undefined }); }}><${Icon} name="send" /> Elküldött</a>
				</nav>
				<p class="nav-label">Postafiókok</p>
				<nav class="mail-folders">
					<a class=${!account ? 'is-active' : ''} href="#/mail" onClick=${(e) => { e.preventDefault(); setFilter({ account: undefined }); }}>Mind</a>
					${me.accounts.map((a) => html`<a key=${a.id} class=${account === a.id ? 'is-active' : ''} href=${'#/mail?account=' + a.id} onClick=${(e) => { e.preventDefault(); setFilter({ account: String(a.id) }); }} title=${a.last_error || ''}>
						<span class="mail-acc">${a.display_name || a.email}<small>${a.shared ? 'közös · ' : ''}${a.email}</small></span>
						${a.last_error ? html`<em class="count count--warn" title=${a.last_error}>!</em>` : a.unread ? html`<em class="count">${a.unread}</em>` : null}
					</a>`)}
				</nav>
				${client ? html`<div class="mail-filter">Ügyfél: <strong>${clientName(client)}</strong> <button class="link" onClick=${() => setFilter({ client: undefined })}>× összes</button></div>` : null}
				<div class="mail-side__foot">
					<button class="btn btn--ghost btn--small" disabled=${syncing} onClick=${sync}><${Icon} name="sync" size="15" /> Frissítés</button>
					${me.is_admin ? html`<a class="btn btn--ghost btn--small" href="#/mail/settings"><${Icon} name="cog" size="15" /> Beállítások</a>` : null}
				</div>
			</aside>

			<section class="mail-list">
				<form class="mail-search" onSubmit=${(e) => { e.preventDefault(); setFilter({ q: q || undefined }); }}>
					<${Icon} name="search" size="16" /><input value=${q} onInput=${(e) => setQ(e.target.value)} placeholder="Keresés a levelekben…" aria-label="Keresés a levelekben" />
				</form>
				${!list ? html`<${Spinner} />` : !list.items.length ? html`<${Empty} icon="mail" title=${params.q ? 'Nincs találat' : 'Nincs levél'}>${params.q ? '' : 'Az új levelek pár percenként érkeznek.'}</${Empty}>` : html`
					<ul class="mail-items">
						${list.items.map((m) => html`<li key=${m.id} class=${'mail-item' + (m.seen ? '' : ' is-unread') + (m.id === selected ? ' is-selected' : '')} onClick=${() => open(m)} tabindex="0" onKeyDown=${(e) => e.key === 'Enter' && open(m)}>
							<div class="mail-item__top"><strong>${m.folder === 'sent' ? 'Címzett: ' + (m.to || []).map(who).join(', ') : who(m.from)}</strong><time>${msgDate(m.date)}</time></div>
							<div class="mail-item__subject">${m.subject}${m.has_attachments ? html` <${Icon} name="attach" size="13" />` : null}</div>
							<div class="mail-item__snippet">${m.snippet}</div>
							<div class="mail-item__tags">
								${m.crm_client_id ? html`<span class="pill pill--active">${clientName(m.crm_client_id) || 'Ügyfél'}</span>` : null}
								${m.crm_task_id ? html`<span class="pill pill--done">feladat</span>` : null}
							</div>
						</li>`)}
					</ul>
					${list.total > list.items.length * page || page > 1 ? html`<div class="mail-pager">
						<button class="btn btn--ghost btn--small" disabled=${page <= 1} onClick=${() => setPage(page - 1)}>Előző</button>
						<span class="muted">${page}. oldal · ${list.total} levél</span>
						<button class="btn btn--ghost btn--small" disabled=${page * (list.per_page || 50) >= list.total} onClick=${() => setPage(page + 1)}>Következő</button></div>` : null}`}
			</section>

			<section class="mail-read">
				${selected ? html`<${Reader} key=${selected} id=${selected} summary=${current} me=${me} onCompose=${setCompose} onChanged=${() => { load(); reloadMe(); }} onClose=${() => navigate('/mail', { ...params, m: undefined })} />`
					: html`<${Empty} icon="mail" title="Válassz egy levelet">Innen válaszolhatsz, feladatot készíthetsz belőle, vagy ügyfélhez kötheted.</${Empty}>`}
			</section>
		</div>
		${compose ? html`<${Compose} me=${me} init=${compose} defaultAccount=${account} onClose=${() => setCompose(null)} onSent=${() => { setCompose(null); load(); }} />` : null}`;
}

/* ── Olvasás ─────────────────────────────────────── */

function Reader({ id, me, onCompose, onChanged, onClose }) {
	const { boot, setBoot } = useApp();
	const [m, setM] = useState(null);
	const [images, setImages] = useState(false);
	const [taskOpen, setTaskOpen] = useState(false);
	const load = useCallback(() => api(`/mail/messages/${id}${images ? '?images=1' : ''}`).then((d) => { setM(d); }).catch((e) => toast(e.message, 'error')), [id, images]);
	useEffect(() => { load().then(onChanged); }, [load]);
	if (!m) return html`<${Spinner} />`;

	const account = me.accounts.find((a) => a.id === m.account_id) || me.accounts[0];
	const reply = (all) => {
		const ownEmails = me.accounts.map((a) => a.email);
		const to = m.folder === 'sent' ? m.to : [m.from];
		const cc = all ? [...(m.to || []), ...(m.cc || [])].filter((x) => !ownEmails.includes(x.email) && !to.some((t) => t.email === x.email)) : [];
		onCompose({ account_id: account.id, to, cc, subject: /^re:/i.test(m.subject) ? m.subject : 'Re: ' + m.subject, reply_to_id: m.id, crm_client_id: m.crm_client_id });
	};
	const forward = () => onCompose({ account_id: account.id, to: [], subject: /^fwd?:/i.test(m.subject) ? m.subject : 'Fwd: ' + m.subject, forward_of_id: m.id,
		body_html: `<p></p><p>---------- Továbbított levél ----------<br>Feladó: ${escapeHtml(who(m.from))} &lt;${escapeHtml(m.from.email)}&gt;<br>Dátum: ${new Date(m.date).toLocaleString('hu-HU')}<br>Tárgy: ${escapeHtml(m.subject)}</p>${m.body_html}`,
		forward_attachments: m.attachments });
	const link = async (clientId) => {
		try { const r = await api(`/mail/messages/${m.id}/link`, { method: 'POST', body: { crm_client_id: clientId || null, remember: true } }); setM({ ...m, crm_client_id: r.crm_client_id }); toast(clientId ? 'Ügyfélhez kötve – a következő levelei is ide kerülnek.' : 'Leválasztva.'); onChanged(); } catch (e) { toast(e.message, 'error'); }
	};
	const addLead = async () => {
		try {
			const r = await api('/mail-lead', { method: 'POST', body: { message_id: m.id } });
			if (!boot.clients.some((c) => c.id === r.client_id)) setBoot({ ...boot, clients: [...boot.clients, { id: r.client_id, name: r.name, status: r.status }] });
			setM({ ...m, crm_client_id: r.client_id });
			toast(r.created ? 'Új érdeklődő a tölcsér elején (Értékesítés).' : 'Már ismert ügyfél – a levelet hozzá kötöttük.');
			onChanged();
		} catch (e) { toast(e.message, 'error'); }
	};
	const markUnread = async () => { await api(`/mail/messages/${m.id}/seen`, { method: 'POST', body: { seen: false } }); onChanged(); onClose(); };

	return html`
		<article class="mail-msg">
			<header class="mail-msg__head">
				<h2>${m.subject}</h2>
				<div class="mail-msg__actions">
					<button class="btn btn--small" onClick=${() => reply(false)}><${Icon} name="reply" size="15" /> Válasz</button>
					<button class="btn btn--ghost btn--small" onClick=${() => reply(true)}>Válasz mindenkinek</button>
					<button class="btn btn--ghost btn--small" onClick=${forward}><${Icon} name="forward" size="15" /> Továbbítás</button>
					<button class="btn btn--ghost btn--small" onClick=${() => setTaskOpen(true)}><${Icon} name="check" size="15" /> Feladat ebből</button>
					<button class="icon-btn" title="Olvasatlannak jelölés" aria-label="Olvasatlannak jelölés" onClick=${markUnread}><${Icon} name="unread" /></button>
					<button class="icon-btn" title="Bezárás" aria-label="Bezárás" onClick=${onClose}><${Icon} name="x" /></button>
				</div>
			</header>
			<div class="mail-msg__meta">
				<${Avatar} user=${{ id: (m.from.email || '').length, name: who(m.from), initials: initials(who(m.from)) }} size="36" />
				<div>
					<strong>${who(m.from)}</strong> <span class="muted">${'<' + m.from.email + '>'}</span>
					<div class="muted small">Címzett: ${(m.to || []).map(who).join(', ')}${m.cc && m.cc.length ? ' · Másolat: ' + m.cc.map(who).join(', ') : ''}</div>
				</div>
				<time class="muted small">${m.date ? new Date(m.date).toLocaleString('hu-HU', { dateStyle: 'medium', timeStyle: 'short' }) : ''}</time>
			</div>
			<div class="mail-msg__crm">
				<label class="field field--inline">Ügyfél
					<select value=${m.crm_client_id || ''} onChange=${(e) => link(Number(e.target.value) || null)}>
						<option value="">— nincs hozzárendelve —</option>
						${boot.clients.map((c) => html`<option key=${c.id} value=${c.id}>${c.name}</option>`)}
					</select>
				</label>
				${m.crm_client_id ? html`<a class="link small" href=${'#/clients/' + m.crm_client_id}>Ügyfél adatlapja</a> <a class="link small" href=${'#/mail?client=' + m.crm_client_id}>Összes levele</a>` : null}
				${!m.crm_client_id && m.folder === 'inbox' ? html`<button class="btn btn--ghost btn--small" onClick=${addLead}><${Icon} name="flag" size="15" /> Felvétel érdeklődőként</button>` : null}
				${m.crm_task_id ? html`<span class="pill pill--done">Feladat készült belőle</span>` : null}
			</div>
			${m.thread && m.thread.length > 1 ? html`<details class="mail-thread"><summary>${m.thread.length} levél ebben a beszélgetésben</summary>
				<ul>${m.thread.map((t) => html`<li key=${t.id} class=${t.id === m.id ? 'is-current' : ''}><a href=${'#/mail?m=' + t.id}>${t.folder === 'sent' ? 'Te' : who(t.from)} · ${msgDate(t.date)}</a> <span class="muted">${t.snippet.slice(0, 90)}</span></li>`)}</ul></details>` : null}
			${m.blocked_images ? html`<div class="mail-notice">${m.blocked_images} külső kép letiltva (követés elleni védelem). <button class="link" onClick=${() => setImages(true)}>Képek megjelenítése</button></div>` : null}
			<${MailBody} htmlBody=${m.body_html} />
			${m.attachments && m.attachments.length ? html`<div class="mail-atts">${m.attachments.map((a, i) => html`<a key=${i} class="mail-att" href=${attachmentUrl(m.id, i)} target="_blank" rel="noopener"><${Icon} name="attach" size="15" /> ${a.name} <span class="muted">${fmtSize(a.size)}</span></a>`)}</div>` : null}
		</article>
		${taskOpen ? html`<${TaskFromMail} message=${m} onClose=${() => setTaskOpen(false)} onDone=${() => { setTaskOpen(false); load(); onChanged(); }} />` : null}`;
}

/* ── Feladat levélből ────────────────────────────── */

function TaskFromMail({ message, onClose, onDone }) {
	const { boot } = useApp();
	const [projects, setProjects] = useState(null);
	const [form, setForm] = useState({ title: message.subject.replace(/^(re|fwd?|vs):\s*/i, ''), project_id: '', assignee_id: boot.me.id, due_date: '', priority: 'normal', note: '' });
	const [busy, setBusy] = useState(false);
	useEffect(() => {
		api('/pm/projects').then((all) => {
			const list = all.filter((p) => p.status !== 'completed');
			list.sort((a, b) => (b.client_id === message.crm_client_id) - (a.client_id === message.crm_client_id));
			setProjects(list);
			const first = list.find((p) => p.client_id === message.crm_client_id);
			if (first) setForm((f) => ({ ...f, project_id: String(first.id) }));
		}).catch((e) => toast(e.message, 'error'));
	}, []);
	const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });
	const save = async (e) => {
		e.preventDefault();
		if (!form.project_id) return toast('Válassz projektet.', 'error');
		setBusy(true);
		try {
			const r = await api('/mail-task', { method: 'POST', body: { ...form, message_id: message.id } });
			toast(`Feladat létrehozva: ${r.project}`);
			onDone();
			navigate(r.url.replace(/^#/, '').split('?')[0], Object.fromEntries(new URLSearchParams(r.url.split('?')[1] || '')));
		} catch (err) { toast(err.message, 'error'); }
		setBusy(false);
	};
	const clientName = (id) => (boot.clients.find((c) => c.id === id) || {}).name || '';
	return html`<${Modal} title="Feladat ebből a levélből" onClose=${onClose}>
		<form class="form" onSubmit=${save}>
			<label class="field">Feladat<input value=${form.title} onInput=${set('title')} required /></label>
			<label class="field">Projekt
				${!projects ? html`<${Spinner} />` : html`<select value=${form.project_id} onChange=${set('project_id')} required>
					<option value="">Válassz…</option>
					${projects.map((p) => html`<option key=${p.id} value=${p.id}>${p.name}${p.client_id ? ' — ' + clientName(p.client_id) : ''}</option>`)}
				</select>`}
			</label>
			<div class="row">
				<label class="field">Felelős<select value=${form.assignee_id} onChange=${set('assignee_id')}>${boot.users.map((u) => html`<option key=${u.id} value=${u.id}>${u.name}</option>`)}</select></label>
				<label class="field">Határidő<input type="date" value=${form.due_date} onInput=${set('due_date')} /></label>
				<label class="field">Prioritás<select value=${form.priority} onChange=${set('priority')}>${boot.priorities.map((p) => html`<option key=${p.key} value=${p.key}>${p.label}</option>`)}</select></label>
			</div>
			<label class="field">Megjegyzés (nem kötelező)<textarea rows="3" value=${form.note} onInput=${set('note')} placeholder="A levél szövege magától bekerül a feladat leírásába."></textarea></label>
			<div class="form__foot"><button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button><button class="btn" disabled=${busy}>Feladat létrehozása</button></div>
		</form>
	</${Modal}>`;
}

/* ── Levélírás ───────────────────────────────────── */

const addrText = (list) => (list || []).map((a) => (a.name ? `${a.name} <${a.email}>` : a.email)).join(', ');

function Compose({ me, init, defaultAccount, onClose, onSent }) {
	const { boot } = useApp();
	const firstAccount = init.account_id || defaultAccount || (me.accounts.find((a) => !a.shared) || me.accounts[0]).id;
	const [form, setForm] = useState({ account_id: firstAccount, to: addrText(init.to), cc: addrText(init.cc), bcc: '', subject: init.subject || '', body: init.body_html || '' });
	const [showCc, setShowCc] = useState(!!(init.cc && init.cc.length));
	const [uploads, setUploads] = useState([]);
	const [busy, setBusy] = useState(false);
	const [clientId, setClientId] = useState(init.crm_client_id || '');
	const fileRef = useRef(null);
	const account = me.accounts.find((a) => a.id === Number(form.account_id)) || me.accounts[0];
	const set = (k) => (e) => setForm({ ...form, [k]: e.target.value });

	const addFiles = async (files) => {
		for (const f of files) {
			if (f.size > 15 * 1024 * 1024) { toast(`${f.name}: legfeljebb 15 MB lehet.`, 'error'); continue; }
			const tmp = { name: f.name, size: f.size, uploading: true };
			setUploads((u) => [...u, tmp]);
			try {
				const r = await rawUpload(f);
				setUploads((u) => u.map((x) => (x === tmp ? { ...r, size: f.size } : x)));
			} catch (e) {
				toast(e.message, 'error');
				setUploads((u) => u.filter((x) => x !== tmp));
			}
		}
	};
	const send = async () => {
		const to = parseAddresses(form.to);
		if (!to.length) return toast('Adj meg címzettet.', 'error');
		if (uploads.some((u) => u.uploading)) return toast('Várd meg, amíg a mellékletek feltöltődnek.', 'error');
		setBusy(true);
		try {
			await api('/mail/send', { method: 'POST', body: {
				account_id: Number(form.account_id), to, cc: parseAddresses(form.cc), bcc: parseAddresses(form.bcc), subject: form.subject,
				body_html: form.body, reply_to_id: init.reply_to_id || null, forward_of_id: init.forward_of_id || null,
				upload_ids: uploads.map((u) => u.id), crm_client_id: clientId ? Number(clientId) : null,
			} });
			toast('Elküldve.');
			onSent();
		} catch (e) { toast(e.message, 'error'); }
		setBusy(false);
	};
	// Ügyfél választásakor a kapcsolattartó címe a címzett, ha még üres.
	const pickClient = async (id) => {
		setClientId(id);
		if (id && !form.to.trim()) {
			try { const c = await api('/clients/' + id); if (c.email) setForm((f) => ({ ...f, to: c.contact_name ? `${c.contact_name} <${c.email}>` : c.email })); } catch (e) { /* nem kötelező */ }
		}
	};

	return html`<div class="modal"><div class="modal__box compose" role="dialog" aria-label="Új levél">
		<header class="compose__head"><h3>${init.reply_to_id ? 'Válasz' : init.forward_of_id ? 'Továbbítás' : 'Új levél'}</h3><button class="icon-btn" onClick=${onClose} aria-label="Bezárás"><${Icon} name="x" /></button></header>
		<div class="compose__fields">
			<label>Feladó<select value=${form.account_id} onChange=${set('account_id')}>${me.accounts.map((a) => html`<option key=${a.id} value=${a.id}>${a.display_name ? a.display_name + ' <' + a.email + '>' : a.email}</option>`)}</select></label>
			<label>Címzett<input value=${form.to} onInput=${set('to')} placeholder="nev@ceg.hu, masik@ceg.com" autofocus=${!init.to} /></label>
			${showCc ? html`<label>Másolat<input value=${form.cc} onInput=${set('cc')} /></label><label>Titkos<input value=${form.bcc} onInput=${set('bcc')} /></label>` : html`<button class="link small compose__cc" onClick=${() => setShowCc(true)}>Másolat / titkos másolat</button>`}
			<label>Tárgy<input value=${form.subject} onInput=${set('subject')} /></label>
			<label>Ügyfél<select value=${clientId} onChange=${(e) => pickClient(e.target.value)}><option value="">— magától, a címzett alapján —</option>${boot.clients.map((c) => html`<option key=${c.id} value=${c.id}>${c.name}</option>`)}</select></label>
		</div>
		<div class="compose__editor"><${RichText} value=${form.body} onChange=${(v) => setForm((f) => ({ ...f, body: v }))} placeholder="Írd ide a leveled…" /></div>
		<div class="compose__signature" title="Az aláírást az admin állítja be, mindenkinél egységes">
			<span class="muted small">Aláírás (automatikus)</span>
			<div dangerouslySetInnerHTML=${{ __html: account.signature || '' }}></div>
		</div>
		${(init.forward_attachments || []).length ? html`<div class="mail-atts">${init.forward_attachments.map((a, i) => html`<span key=${i} class="mail-att"><${Icon} name="attach" size="14" /> ${a.name}</span>`)} <span class="muted small">a továbbított levél mellékletei</span></div>` : null}
		${uploads.length ? html`<div class="mail-atts">${uploads.map((u, i) => html`<span key=${i} class="mail-att"><${Icon} name="attach" size="14" /> ${u.name} <span class="muted">${u.uploading ? 'feltöltés…' : fmtSize(u.size)}</span> <button class="icon-btn icon-btn--tiny" aria-label="Eltávolítás" onClick=${() => setUploads(uploads.filter((x) => x !== u))}><${Icon} name="x" size="12" /></button></span>`)}</div>` : null}
		<footer class="compose__foot">
			<button class="btn" disabled=${busy} onClick=${send}><${Icon} name="send" size="15" /> Küldés</button>
			<input ref=${fileRef} type="file" multiple hidden onChange=${(e) => { addFiles([...e.target.files]); e.target.value = ''; }} />
			<button class="btn btn--ghost" onClick=${() => fileRef.current.click()}><${Icon} name="attach" size="15" /> Melléklet</button>
			<span class="muted small">A levél a levelezőprogramod Elküldött mappájába is bekerül.</span>
		</footer>
	</div></div>`;
}

/* ── Beállítások (admin) ─────────────────────────── */

const EMPTY_ACCOUNT = { email: '', display_name: '', owner_wp_user_id: '', imap_host: '', imap_port: 993, imap_security: 'ssl', smtp_host: '', smtp_port: 465, smtp_security: 'ssl', username: '', password: '', sent_folder: '' };

function Settings() {
	const { boot } = useApp();
	const [accounts, setAccounts] = useState(null);
	const [presets, setPresets] = useState({});
	const [edit, setEdit] = useState(null);
	const [sig, setSig] = useState(null);
	const [preview, setPreview] = useState('');
	const [syncingContacts, setSyncingContacts] = useState(false);
	const load = () => api('/mail/accounts').then((r) => { setAccounts(r.items); setPresets(r.presets); }).catch((e) => toast(e.message, 'error'));
	const loadSig = () => api('/mail/signature').then((r) => { setSig(r); }).catch((e) => toast(e.message, 'error'));
	useEffect(() => { load(); loadSig(); }, []);
	useEffect(() => {
		if (!sig) return undefined;
		const t = setTimeout(() => api('/mail/signature/preview', { method: 'POST', body: { template: sig.template } }).then((r) => setPreview(r.html)).catch(() => {}), 300);
		return () => clearTimeout(t);
	}, [sig && sig.template]);
	if (!boot.me.is_admin) return html`<${Empty} icon="x" title="Csak az admin érheti el" />`;
	const userName = (id) => (boot.users.find((u) => u.id === id) || {}).name || '';
	const profile = (uid) => (sig && sig.profiles.find((p) => p.wp_user_id === uid)) || { wp_user_id: uid, name: userName(uid), title: '', phone: '' };
	const saveProfile = async (p) => { try { await api('/mail/profiles', { method: 'PUT', body: p }); loadSig(); toast('Mentve.'); } catch (e) { toast(e.message, 'error'); } };
	const test = async (a) => {
		toast('Kapcsolat ellenőrzése…');
		const r = await api(`/mail/accounts/${a.id}/test`, { method: 'POST' }).catch((e) => ({ ok: false, error: e.message }));
		toast(r.ok ? `Rendben: IMAP és SMTP működik${r.sent_folder ? ', Elküldött mappa: ' + r.sent_folder : ''}.` : r.error, r.ok ? 'ok' : 'error');
		load();
	};
	const remove = async (a) => {
		try { await api(`/mail/accounts/${a.id}`, { method: 'DELETE' }); toast('Postafiók törölve.'); load(); } catch (e) { toast(e.message, 'error'); }
	};
	const syncContacts = async () => {
		setSyncingContacts(true);
		try { const r = await api('/mail-contacts-sync', { method: 'POST' }); toast(r.error ? r.error : `${r.contacts} ügyfél-cím frissítve, ${r.relinked} levél ügyfélhez kötve.`, r.error ? 'error' : 'ok'); } catch (e) { toast(e.message, 'error'); }
		setSyncingContacts(false);
	};

	return html`<div class="page page--narrow mail-settings">
		<header class="page__head"><div><h1>Levelezés beállításai</h1><p class="muted">Postafiókok, egységes aláírás és a munkatársak aláírás-adatai. Csak az admin módosíthatja.</p></div>
			<a class="btn btn--ghost" href="#/mail">Vissza a levelekhez</a></header>

		<section class="card">
			<div class="card__head"><h2>Postafiókok</h2><button class="btn btn--small" onClick=${() => setEdit({ ...EMPTY_ACCOUNT })}><${Icon} name="plus" size="15" /> Postafiók</button></div>
			${!accounts ? html`<${Spinner} />` : !accounts.length ? html`<p class="muted">Még nincs postafiók. Vedd fel a csapat tagjainak címét, és egy közöset (pl. info@).</p>` : html`
				<table class="table"><thead><tr><th>Cím</th><th>Kié</th><th>Állapot</th><th></th></tr></thead><tbody>
					${accounts.map((a) => html`<tr key=${a.id}>
						<td><strong>${a.display_name || a.email}</strong><div class="muted small">${a.email}</div></td>
						<td>${a.shared ? html`<span class="pill">közös</span>` : userName(a.owner_wp_user_id)}</td>
						<td>${a.last_error ? html`<span class="pill pill--overdue" title=${a.last_error}>hiba</span><div class="small danger">${a.last_error}</div>` : a.last_sync_at ? html`<span class="pill pill--done">rendben</span><div class="muted small">szinkron: ${timeAgo(a.last_sync_at)}</div>` : html`<span class="pill">még nem szinkronizált</span>`}</td>
						<td class="nowrap"><button class="btn btn--ghost btn--small" onClick=${() => test(a)}>Teszt</button> <button class="btn btn--ghost btn--small" onClick=${() => setEdit({ ...a, owner_wp_user_id: a.owner_wp_user_id || '', password: '' })}>Szerkesztés</button></td>
					</tr>`)}
				</tbody></table>`}
			<p class="muted small">Google Workspace-nél a munkatárs Google-fiókjában kell egy <strong>alkalmazásjelszó</strong> (Biztonság → Kétlépcsős azonosítás → Alkalmazásjelszavak), azt írd be jelszónak. Microsoft 365-nél az IMAP/SMTP hozzáférést az admin központban engedélyezni kell.</p>
		</section>

		<section class="card">
			<div class="card__head"><h2>Egységes aláírás</h2><button class="btn btn--ghost btn--small" onClick=${() => setSig({ ...sig, template: sig.default })}>Alapértelmezett</button></div>
			${!sig ? html`<${Spinner} />` : html`
				<p class="muted small">HTML sablon. Helyettesítők: ${sig.placeholders.map((p) => html`<code key=${p}>${p}</code> `)} – a <code>{phone_line}</code> csak akkor jelenik meg, ha van telefonszám.</p>
				<textarea class="code-area" rows="8" value=${sig.template} onInput=${(e) => setSig({ ...sig, template: e.target.value })} aria-label="Aláírás HTML sablon"></textarea>
				<div class="sig-preview"><span class="muted small">Előnézet (a te adataiddal)</span><div dangerouslySetInnerHTML=${{ __html: preview }}></div></div>
				<button class="btn" onClick=${async () => { try { await api('/mail/signature', { method: 'PUT', body: { template: sig.template } }); toast('Aláírás mentve – mindenki levelében ez szerepel.'); loadSig(); } catch (e) { toast(e.message, 'error'); } }}>Aláírás mentése</button>`}
		</section>

		<section class="card">
			<div class="card__head"><h2>Munkatársak az aláírásban</h2></div>
			${!sig ? null : html`<table class="table"><thead><tr><th>Munkatárs</th><th>Név az aláírásban</th><th>Beosztás</th><th>Telefon</th><th></th></tr></thead><tbody>
				${boot.users.map((u) => html`<${ProfileRow} key=${u.id} user=${u} profile=${profile(u.id)} onSave=${saveProfile} />`)}
			</tbody></table>`}
		</section>

		<section class="card">
			<div class="card__head"><h2>Ügyfél-címek</h2><button class="btn btn--ghost btn--small" disabled=${syncingContacts} onClick=${syncContacts}>Frissítés most</button></div>
			<p class="muted small">A CRM ügyfeleinek e-mail címei alapján a beérkező levél magától az ügyfélhez kerül (óránként frissül, ügyfél mentésekor azonnal). Ha egy levelet kézzel kötsz ügyfélhez, a feladó címét megjegyzi.</p>
		</section>
		${edit ? html`<${AccountForm} account=${edit} presets=${presets} users=${boot.users} onClose=${() => setEdit(null)} onSaved=${() => { setEdit(null); load(); }} onDelete=${edit.id ? () => { remove(edit); setEdit(null); } : null} />` : null}
	</div>`;
}

function ProfileRow({ user, profile, onSave }) {
	const [p, setP] = useState(profile);
	useEffect(() => setP(profile), [profile.name, profile.title, profile.phone]);
	const dirty = p.name !== profile.name || p.title !== profile.title || p.phone !== profile.phone;
	return html`<tr>
		<td>${user.name}</td>
		<td><input class="input-inline" value=${p.name} onInput=${(e) => setP({ ...p, name: e.target.value })} aria-label="Név" /></td>
		<td><input class="input-inline" value=${p.title} onInput=${(e) => setP({ ...p, title: e.target.value })} placeholder="pl. SEO manager" aria-label="Beosztás" /></td>
		<td><input class="input-inline" value=${p.phone} onInput=${(e) => setP({ ...p, phone: e.target.value })} placeholder="+36 …" aria-label="Telefon" /></td>
		<td>${dirty ? html`<button class="btn btn--small" onClick=${() => onSave({ wp_user_id: user.id, name: p.name, title: p.title, phone: p.phone })}>Mentés</button>` : null}</td>
	</tr>`;
}

function AccountForm({ account, presets, users, onClose, onSaved, onDelete }) {
	const [f, setF] = useState(account);
	const [busy, setBusy] = useState(false);
	const [confirmDelete, setConfirmDelete] = useState(false);
	const set = (k, num) => (e) => setF({ ...f, [k]: num ? Number(e.target.value) : e.target.value });
	const preset = (key) => setF({ ...f, ...presets[key], username: f.username || f.email });
	const save = async (e) => {
		e.preventDefault();
		setBusy(true);
		const body = { ...f, owner_wp_user_id: f.owner_wp_user_id ? Number(f.owner_wp_user_id) : null, username: f.username || f.email };
		['id', 'shared', 'is_active', 'last_sync_at', 'last_error', 'has_password', 'unread', 'signature'].forEach((k) => delete body[k]);
		if (!body.password) delete body.password;
		try {
			const saved = f.id ? await api(`/mail/accounts/${f.id}`, { method: 'PATCH', body }) : await api('/mail/accounts', { method: 'POST', body });
			const r = await api(`/mail/accounts/${saved.id}/test`, { method: 'POST' }).catch((err) => ({ ok: false, error: err.message }));
			toast(r.ok ? 'Mentve, a kapcsolat működik. Az első levelek pár percen belül megjelennek.' : 'Mentve, de a kapcsolat nem működik: ' + r.error, r.ok ? 'ok' : 'error');
			if (r.ok) api('/mail/sync', { method: 'POST', body: { account_id: saved.id } }).catch(() => {});
			onSaved();
		} catch (err) { toast(err.message, 'error'); }
		setBusy(false);
	};
	return html`<${Modal} title=${f.id ? 'Postafiók szerkesztése' : 'Új postafiók'} onClose=${onClose} wide>
		<form class="form" onSubmit=${save}>
			<div class="preset-row"><span class="muted small">Szolgáltató:</span>
				<button type="button" class="btn btn--ghost btn--small" onClick=${() => preset('google')}>Google Workspace</button>
				<button type="button" class="btn btn--ghost btn--small" onClick=${() => preset('microsoft')}>Microsoft 365</button>
				<span class="muted small">vagy add meg kézzel (tárhely levelezője)</span></div>
			<div class="row">
				<label class="field">E-mail cím<input type="email" value=${f.email} onInput=${set('email')} required /></label>
				<label class="field">Megjelenő név<input value=${f.display_name} onInput=${set('display_name')} placeholder="pl. Kiss Dóra – HelloProVision" /></label>
			</div>
			<label class="field">Kié<select value=${f.owner_wp_user_id} onChange=${set('owner_wp_user_id')}>
				<option value="">Közös fiók (minden munkatárs látja, pl. info@)</option>
				${users.map((u) => html`<option key=${u.id} value=${u.id}>${u.name}</option>`)}
			</select></label>
			<div class="row">
				<label class="field">IMAP szerver<input value=${f.imap_host} onInput=${set('imap_host')} placeholder="imap.gmail.com" required /></label>
				<label class="field field--small">Port<input type="number" value=${f.imap_port} onInput=${set('imap_port', true)} /></label>
				<label class="field field--small">Titkosítás<select value=${f.imap_security} onChange=${set('imap_security')}><option value="ssl">SSL</option><option value="starttls">STARTTLS</option><option value="none">nincs</option></select></label>
			</div>
			<div class="row">
				<label class="field">SMTP szerver<input value=${f.smtp_host} onInput=${set('smtp_host')} placeholder="smtp.gmail.com" required /></label>
				<label class="field field--small">Port<input type="number" value=${f.smtp_port} onInput=${set('smtp_port', true)} /></label>
				<label class="field field--small">Titkosítás<select value=${f.smtp_security} onChange=${set('smtp_security')}><option value="ssl">SSL</option><option value="starttls">STARTTLS</option><option value="none">nincs</option></select></label>
			</div>
			<div class="row">
				<label class="field">Felhasználónév<input value=${f.username} onInput=${set('username')} placeholder="általában az e-mail cím" /></label>
				<label class="field">Jelszó / alkalmazásjelszó<input type="password" value=${f.password} onInput=${set('password')} placeholder=${f.id ? 'változatlan' : ''} required=${!f.id} autocomplete="new-password" /></label>
			</div>
			<p class="muted small">A jelszót titkosítva tároljuk, és senkinek nem mutatjuk meg újra.</p>
			<div class="form__foot">
				${onDelete ? (confirmDelete ? html`<button type="button" class="btn btn--danger" onClick=${onDelete}>Biztosan törlöd? A letöltött levelek is törlődnek.</button>` : html`<button type="button" class="btn btn--danger" onClick=${() => setConfirmDelete(true)}>Törlés</button>`) : null}
				<span style="flex:1"></span>
				<button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button>
				<button class="btn" disabled=${busy}>Mentés és kapcsolat-teszt</button>
			</div>
		</form>
	</${Modal}>`;
}

/* ── Regisztráció a CRM-ben ──────────────────────── */

registerExtension({
	icons: ICONS,
	nav: [{ path: '/mail', label: 'Levelezés', icon: 'mail', after: '/chat' }],
	routes: [
		{ match: (p) => p === '/mail/settings', render: () => html`<${Settings} />` },
		{ match: (p) => p === '/mail', render: (p, params) => html`<${MailPage} params=${params} />` },
	],
});

// Olvasatlan számláló a menüben (induláskor és percenként).
const refreshBadge = () => api('/mail/me').then((r) => setExtensionBadge('/mail', r.accounts.reduce((n, a) => n + a.unread, 0))).catch(() => {});
refreshBadge();
setInterval(() => { if (!document.hidden) refreshBadge(); }, 60000);
