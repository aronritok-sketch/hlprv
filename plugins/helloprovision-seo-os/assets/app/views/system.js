/**
 * Super admin – Rendszer állapot: élesítési ellenőrzőlista (WordPress + API), szolgáltatások életjele, integrációk
 * (kulcsok forrása, utolsó sikeres hívás / hiba, havi költség), háttérfeladatok, adatok. Gyors műveletek: kapcsolatteszt,
 * teszt e-mail, ütemezett feladatok futtatása, demó projekt.
 */
import { html, useState, api, useLoad, useJob, toast, errorText, fmt, navigate, setParam, Icon, Spinner, ErrorBox, Pill } from '../ui.js';

const LEVEL = { error: ['danger', 'Hiba', 0], warn: ['warn', 'Teendő', 1], optional: ['', 'Opcionális', 2], ok: ['ok', 'Rendben', 3] };
const SOURCE = { db: 'felületen', env: 'szerver .env', default: 'alapérték', '': 'nincs' };

function bytes(n) {
	if (n === null || n === undefined) return '–';
	const u = ['B', 'KB', 'MB', 'GB', 'TB'];
	let i = 0;
	while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
	return n.toFixed(i ? 1 : 0) + ' ' + u[i];
}

function Beat({ label, beat, hint }) {
	const on = beat && beat.online;
	return html`<div class="svc">
		<span class=${'svc__dot ' + (on ? 'is-on' : 'is-off')}></span>
		<div><strong>${label}</strong><span class="muted small">${beat && beat.last_seen_at ? 'utoljára ' + fmt.ago(beat.last_seen_at) : 'még nem jelentkezett'}${hint ? ' · ' + hint : ''}</span></div>
	</div>`;
}

export function SystemStatus() {
	const [data, loading, error, reload] = useLoad('/wp/status');
	const [tests, setTests] = useState(null);
	const [busy, setBusy] = useState('');
	const [job, setJob] = useJob((j) => { toast('A demó projekt elkészült.'); navigate('/projects/' + j.result.project_id); });
	if (loading && !data) return html`<${Spinner} label="Állapot lekérése…" />`;
	if (error) return html`<${ErrorBox} error=${error} onRetry=${reload} />`;
	const a = data.api && !data.api.error ? data.api : null;
	const items = [...data.checklist, ...(a ? a.checklist : [{ key: 'api', label: 'Az API állapota lekérhető', status: 'error', detail: data.api.error, fix: 'Ellenőrizd, hogy fut-e az API, és egyezik-e a közös titok.' }])]
		.sort((x, y) => LEVEL[x.status][2] - LEVEL[y.status][2]);
	const errors = items.filter((i) => i.status === 'error').length;
	const warns = items.filter((i) => i.status === 'warn').length;
	const act = async (key, fn) => { setBusy(key); try { await fn(); } catch (e) { toast(errorText(e), 'error'); } setBusy(''); };
	const test = () => act('test', async () => setTests(await api('/integrations/test', { method: 'POST' })));
	const mail = () => act('mail', async () => { const r = await api('/wp/test-email', { method: 'POST' }); toast(r.ok ? 'Teszt levél elküldve: ' + r.to : 'A levélküldés nem sikerült (' + r.to + ').', r.ok ? 'ok' : 'error'); });
	const cron = () => act('cron', async () => { const r = await api('/wp/run-cron', { method: 'POST' }); toast('Ütemezett feladatok lefutottak: ' + (r.outbox.sent || 0) + ' e-mail elküldve.'); reload(); });
	const demo = () => act('demo', async () => { const r = await api('/admin/demo', { method: 'POST' }); setJob(r.job); });
	const retry = (id) => act('retry' + id, async () => { await api('/admin/jobs/' + id + '/retry', { method: 'POST' }); toast('Újra sorba állítva.'); reload(); });
	const demoRunning = job && ['queued', 'running'].includes(job.status);
	return html`<div class="stack">
		<section class=${'card pad readiness ' + (errors ? 'is-error' : warns ? 'is-warn' : 'is-ok')}>
			<div>
				<h2>${errors ? errors + ' hiba akadályozza az éles használatot' : warns ? 'Használható – ' + warns + ' javasolt teendő' : 'Éles üzemre kész'}</h2>
				<p class="muted small">WordPress ${data.wp.wp_version} · bővítmény ${data.wp.plugin_version} · PHP ${data.wp.php_version}${a ? ' · API Python ' + a.api.python + ' · adatbázis ' + a.database.revision : ''}</p>
			</div>
			<div class="row-edit">
				<button class="btn btn--ghost btn--sm" onClick=${reload}><${Icon} name="refresh" size="14" /> Frissítés</button>
				<button class="btn btn--ghost btn--sm" disabled=${busy === 'test'} onClick=${test}>Kapcsolatok tesztelése</button>
				<button class="btn btn--ghost btn--sm" disabled=${busy === 'mail'} onClick=${mail}>Teszt e-mail</button>
				<button class="btn btn--ghost btn--sm" disabled=${busy === 'cron'} onClick=${cron}>Ütemezett feladatok most</button>
				<button class="btn btn--sm" disabled=${busy === 'demo' || demoRunning} onClick=${demo}>${demoRunning ? 'Demó készül… ' + Math.round((job.progress || 0) * 100) + '%' : 'Demó projekt létrehozása'}</button>
			</div>
		</section>

		${tests ? html`<section class="card"><header class="card__head"><h2>Kapcsolatteszt</h2><button class="link small" onClick=${() => setTests(null)}>Bezárás</button></header>
			<ul class="list">${Object.entries(tests).map(([k, v]) => html`<li key=${k}><span><strong>${k}</strong> <span class=${v.ok ? 'ok' : v.configured === false ? 'muted' : 'danger'}>${v.ok ? 'OK' : v.configured === false ? 'nincs beállítva' : 'Hiba'}</span> <span class="muted small">${v.message || ''}</span></span></li>`)}</ul>
		</section>` : ''}

		<section class="card"><header class="card__head"><h2><${Icon} name="check" /> Élesítési ellenőrzőlista</h2></header>
			<ul class="checklist-items">${items.map((i) => html`<li key=${i.key} class=${'is-' + i.status}>
				<${Pill} kind=${LEVEL[i.status][0]}>${LEVEL[i.status][1]}</${Pill}>
				<div><strong>${i.label}</strong>${i.detail ? html`<span class="muted small"> – ${i.detail}</span>` : ''}
					${i.fix ? html`<div class="small fix">${i.fix}</div>` : ''}</div>
			</li>`)}</ul>
		</section>

		${a ? html`<div class="grid grid--2">
			<section class="card"><header class="card__head"><h2>Szolgáltatások</h2></header>
				<div class="pad svc-list">
					<${Beat} label="API + adatbázis" beat=${{ online: true, last_seen_at: a.api.time }} hint=${a.database.up_to_date ? 'migrációk naprakészek' : 'migráció szükséges'} />
					<${Beat} label="Háttérfeladat-worker" beat=${a.services.worker} hint=${a.jobs.queued + ' sorban, ' + a.jobs.running + ' fut'} />
					<${Beat} label="WordPress cron (e-mailek, 5 perc)" beat=${a.services.wp_cron} hint=${data.wp.next_outbox ? 'következő: ' + fmt.datetime(data.wp.next_outbox) : ''} />
					<${Beat} label="Napi emlékeztetők" beat=${a.services.wp_daily} hint=${data.wp.next_daily ? 'következő: ' + fmt.datetime(data.wp.next_daily) : ''} />
					<${Beat} label="Screaming Frog ügynök" beat=${{ online: a.crawler.agents.some((x) => x.online), last_seen_at: a.crawler.agents[0] && a.crawler.agents[0].last_seen_at }} hint=${a.crawler.agents.map((x) => x.name).join(', ') || 'nincs'} />
					<div class="svc"><span class="svc__dot is-info"></span><div><strong>E-mail értesítések</strong><span class="muted small">${a.email.pending} várakozik · ${a.email.sent_24h} elküldve / ${a.email.failed_24h} hiba (24 óra)</span></div></div>
					<div class="svc"><span class=${'svc__dot ' + (a.storage.writable ? 'is-on' : 'is-off')}></span><div><strong>Fájltár</strong><span class="muted small">${a.storage.files} fájl, ${bytes(a.storage.bytes)} · ${bytes(a.storage.free)} szabad</span></div></div>
				</div>
			</section>
			<section class="card"><header class="card__head"><h2>Adatok és költség</h2></header>
				<div class="pad stats stats--compact">
					<div class="stat"><span class="stat__label">Aktív projekt</span><span class="stat__value">${a.counts.projects}</span></div>
					<div class="stat"><span class="stat__label">Felhasználó</span><span class="stat__value">${a.counts.users}</span></div>
					<div class="stat"><span class="stat__label">Kulcsszó</span><span class="stat__value">${fmt.num(a.counts.keywords)}</span></div>
					<div class="stat"><span class="stat__label">Dokumentum</span><span class="stat__value">${a.counts.documents}</span></div>
					<div class="stat"><span class="stat__label">Nyitott feladat</span><span class="stat__value">${a.counts.tasks_open}</span></div>
					<div class="stat"><span class="stat__label">API-költség e hónapban</span><span class="stat__value">$${a.spend.month_usd.toFixed(2)}</span><span class="stat__hint">keret: $${a.spend.budget_per_project_usd} / projekt</span></div>
				</div>
				<p class="pad muted small">Modellek: OpenAI ${a.models.openai} · Claude ${a.models.claude} (${a.models.claude_effort})</p>
			</section>
		</div>

		<section class="card"><header class="card__head"><h2><${Icon} name="key" /> Integrációk és kulcsok</h2><button class="link small" onClick=${() => setParam('tab', 'keys')}>Kulcsok szerkesztése</button></header>
			<div class="table-wrap"><table class="table table--dense"><thead><tr><th>Szolgáltatás</th><th>Kulcs</th><th>Utolsó sikeres hívás</th><th>Utolsó hiba</th><th>E havi hívás / költség</th></tr></thead>
			<tbody>${a.integrations.map((i) => html`<tr key=${i.key}>
				<td><strong>${i.label}</strong><div><${Pill} kind=${i.configured ? 'ok' : ''}>${i.configured ? 'beállítva' : 'nincs'}</${Pill}></div></td>
				<td class="small">${i.fields.map((f) => html`<div key=${f.key}><span class="url">${f.masked || '–'}</span> <span class="muted">(${SOURCE[f.source]})</span></div>`)}</td>
				<td class="small">${i.last_ok_at ? fmt.datetime(i.last_ok_at) : '–'}</td>
				<td class="small">${i.last_error_at ? html`<span class="danger">${fmt.datetime(i.last_error_at)}</span><div class="muted">${i.last_error}</div>` : '–'}</td>
				<td class="small">${i.month_calls} / $${i.month_cost_usd.toFixed(2)}</td>
			</tr>`)}</tbody></table></div>
		</section>

		<section class="card"><header class="card__head"><h2><${Icon} name="refresh" /> Háttérfeladatok</h2>
			<span class="muted small">24 óra: ${a.jobs.done_24h} kész, ${a.jobs.failed_24h} hibás · ${a.jobs.queued} sorban${a.jobs.oldest_queued_seconds ? ' (legrégebbi ' + Math.round(a.jobs.oldest_queued_seconds / 60) + ' perce)' : ''}</span></header>
			${a.jobs.failed.length ? html`<div class="table-wrap"><table class="table table--dense"><thead><tr><th>Feladat</th><th>Projekt</th><th>Hiba</th><th></th></tr></thead>
				<tbody>${a.jobs.failed.map((j) => html`<tr key=${j.id}><td class="small">#${j.id} ${j.type}<div class="muted">${fmt.datetime(j.finished_at)}</div></td><td class="small">${j.project}</td><td class="small danger">${j.error}</td>
					<td><button class="btn btn--ghost btn--sm" disabled=${busy === 'retry' + j.id} onClick=${() => retry(j.id)}>Újra</button></td></tr>`)}</tbody></table></div>`
			: html`<p class="pad muted small">Nincs hibás feladat.</p>`}
		</section>` : ''}

		<section class="card"><header class="card__head"><h2>WordPress</h2></header>
			<dl class="kv pad">
				<dt>SEO OS cím</dt><dd class="url">${data.wp.host}${data.wp.request_host !== data.wp.host ? ' (most: ' + data.wp.request_host + ')' : ''}</dd>
				<dt>API cím</dt><dd class="url">${data.wp.api_url}</dd>
				<dt>Közös titok</dt><dd>${data.wp.secret_source || html`<span class="danger">nincs</span>`}</dd>
				<dt>Cron</dt><dd>${data.wp.cron_disabled ? 'szerver cron (DISABLE_WP_CRON)' : 'WP-Cron (látogatásfüggő)'}</dd>
				<dt>Frissítések</dt><dd>${data.wp.updates && data.wp.updates.configured ? Object.entries(data.wp.updates.plugins).map(([s, v]) => s.replace('helloprovision-', '') + ' ' + v.installed + (v.latest && v.latest !== v.installed ? ' → ' + v.latest : '')).join(' · ') : html`<span class="danger">nincs GitHub token</span>`}</dd>
				<dt>Szerepkörök</dt><dd>${Object.entries(data.wp.roles).map(([r, n]) => r + ': ' + n).join(' · ') || '–'}</dd>
			</dl>
		</section>
	</div>`;
}
