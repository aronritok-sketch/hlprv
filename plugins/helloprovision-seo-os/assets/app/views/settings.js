/**
 * Beállítások: csapat (mindenki látja, aki csapatot láthat), API kulcsok, modellek, pontozás (csak admin),
 * referenciadokumentumok és Screaming Frog ügynök (a későbbi ütemek szakaszai).
 */
import { html, useState, useEffect, useApp, api, useLoad, toast, errorText, fmt, Icon, Spinner, Field, Input, DataTable, Avatar, can, CFG } from '../ui.js';
import { ReferenceDocs } from './settings-refs.js';
import { CrawlerAgent } from './settings-crawler.js';

function Team() {
	const { meta } = useApp();
	const [users, loading] = useLoad('/users');
	if (loading && !users) return html`<${Spinner} />`;
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="tasks" /> Csapat</h2><a class="link" href=${CFG.usersUrl.replace('users.php', 'options-general.php?page=hpv-seo-os')}>Szerepkörök kiosztása <${Icon} name="ext" size="13" /></a></header>
		<${DataTable} rows=${users || []} columns=${[
			{ key: 'display_name', label: 'Név', render: (u) => html`<span class="member"><${Avatar} name=${u.display_name} /> <strong>${u.display_name}</strong></span>` },
			{ key: 'email', label: 'E-mail' },
			{ key: 'role', label: 'Szerepkör', render: (u) => meta.roles[u.role] },
			{ key: 'is_active', label: 'Állapot', render: (u) => (u.is_active ? 'Aktív' : html`<span class="muted">Kikapcsolva</span>`) },
			{ key: 'last_seen_at', label: 'Utoljára', render: (u) => fmt.ago(u.last_seen_at), num: true },
		]} />
		<p class="muted small pad">A szerepkört a WordPressben lehet állítani (Beállítások → SEO OS, vagy a felhasználó profilján). A WordPress adminisztrátorok automatikusan Adminok.</p>
	</section>`;
}

const GROUPS = [
	['AI modellek', ['openai_api_key', 'openai_model', 'openai_embedding_model', 'anthropic_api_key', 'claude_model', 'claude_model_client']],
	['SEO adatforrások', ['dataforseo_login', 'dataforseo_password', 'ahrefs_api_key', 'monthly_budget_usd']],
	['Dokumentumok', ['brand_name']],
];

function ApiSettings() {
	const [list, loading, , reload] = useLoad('/settings');
	const [draft, setDraft] = useState({});
	const [busy, setBusy] = useState(false);
	const [tests, setTests] = useState(null);
	if (loading && !list) return html`<${Spinner} />`;
	const byKey = Object.fromEntries((list || []).map((s) => [s.key, s]));
	const save = async () => {
		setBusy(true);
		try {
			await api('/settings', { method: 'PUT', body: draft });
			setDraft({});
			reload();
			toast('Beállítások mentve.');
		} catch (e) { toast(errorText(e), 'error'); }
		setBusy(false);
	};
	const test = async () => {
		setTests({ running: true });
		try { setTests(await api('/integrations/test', { method: 'POST' })); } catch (e) { setTests({ error: e.message }); }
	};
	const weights = draft.scoring_weights || (byKey.scoring_weights && byKey.scoring_weights.value) || {};
	const thresholds = draft.priority_thresholds || (byKey.priority_thresholds && byKey.priority_thresholds.value) || {};
	const wLabels = { intent: 'Keresési szándék', business_value: 'Üzleti érték', commercial: 'Kereskedelmi lehetőség', ease: 'Verseny (könnyűség)', volume: 'Keresési volumen' };

	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="key" /> Integrációk és AI</h2>
			<div class="row-edit">
				<button class="btn btn--ghost btn--sm" onClick=${test}>Kapcsolatok tesztelése</button>
				<button class="btn btn--sm" disabled=${busy || !Object.keys(draft).length} onClick=${save}>Mentés</button>
			</div>
		</header>
		${tests ? html`<div class="pad">${tests.running ? html`<${Spinner} label="Tesztelés…" />` : tests.error ? html`<p class="alert alert--error">${tests.error}</p>` : html`<ul class="list">${Object.entries(tests).map(([k, v]) => html`<li key=${k}><span><strong>${k}</strong> <span class=${v.ok ? 'ok' : 'danger'}>${v.ok ? 'OK' : 'Hiba'}</span> <span class="muted small">${v.message || ''}</span></span></li>`)}</ul>`}</div>` : ''}
		<div class="pad form-grid">
			${GROUPS.map(([title, keys]) => html`<div key=${title} class="field field--wide"><h3 class="subhead">${title}</h3>
				<div class="form-grid">${keys.filter((k) => byKey[k]).map((k) => html`<${Field} key=${k} label=${byKey[k].label} hint=${byKey[k].secret ? (byKey[k].is_set ? 'Beállítva: ' + byKey[k].value : 'Nincs beállítva') : ''}>
					<${Input} type=${byKey[k].secret ? 'password' : 'text'} autocomplete="off" value=${draft[k] !== undefined ? draft[k] : byKey[k].secret ? '' : byKey[k].value}
						placeholder=${byKey[k].secret ? 'Új érték…' : ''} onInput=${(v) => setDraft((d) => ({ ...d, [k]: k === 'monthly_budget_usd' ? Number(v) || 0 : v }))} />
				</${Field}>`)}</div>
			</div>`)}
			<div class="field field--wide"><h3 class="subhead">Kulcsszó-prioritás súlyai</h3>
				<p class="muted small">A sorrend a módszertan szerint: szándék → üzleti érték → kereskedelmi lehetőség → verseny → volumen. A súlyok összege legyen 1.</p>
				<div class="form-grid">${Object.entries(wLabels).map(([k, label]) => html`<${Field} key=${k} label=${label}>
					<input class="input" type="number" step="0.01" min="0" max="1" value=${weights[k]} onInput=${(e) => setDraft((d) => ({ ...d, scoring_weights: { ...weights, [k]: Number(e.target.value) } }))} />
				</${Field}>`)}
				<${Field} label="P1 határ (0–1)"><input class="input" type="number" step="0.01" value=${thresholds.p1} onInput=${(e) => setDraft((d) => ({ ...d, priority_thresholds: { ...thresholds, p1: Number(e.target.value) } }))} /></${Field}>
				<${Field} label="P2 határ (0–1)"><input class="input" type="number" step="0.01" value=${thresholds.p2} onInput=${(e) => setDraft((d) => ({ ...d, priority_thresholds: { ...thresholds, p2: Number(e.target.value) } }))} /></${Field}>
				</div>
			</div>
		</div>
	</section>`;
}

export function Settings() {
	const { me } = useApp();
	return html`<div class="page">
		<header class="page__head"><div><h1>Beállítások</h1></div></header>
		<div class="stack">
			<${Team} />
			${can(me, 'settings.manage') ? html`<${ApiSettings} />` : ''}
			${can(me, 'settings.manage') ? html`<${ReferenceDocs} />` : ''}
			${can(me, 'settings.manage') ? html`<${CrawlerAgent} />` : ''}
		</div>
	</div>`;
}
