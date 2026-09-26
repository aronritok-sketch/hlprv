/**
 * Screaming Frog ügynök: jelentkezett ügynökök (online / offline), telepítési útmutató (irodai gép vagy szerver),
 * az export fülek és a crawl beállításai.
 */
import { html, useState, api, useLoad, toast, errorText, fmt, Icon, Spinner, Field, Textarea, Input, Checkbox } from '../ui.js';

export function CrawlerAgent() {
	const [data, loading, , reload] = useLoad('/crawler/agents');
	const [settings, , , reloadSettings] = useLoad('/settings');
	const [draft, setDraft] = useState({});
	const [busy, setBusy] = useState(false);
	if ((loading && !data) || !settings) return html`<section class="card pad"><${Spinner} /></section>`;
	const byKey = Object.fromEntries(settings.map((s) => [s.key, s.value]));
	const val = (k) => (draft[k] !== undefined ? draft[k] : byKey[k]);
	const save = async () => {
		setBusy(true);
		try { await api('/settings', { method: 'PUT', body: draft }); setDraft({}); reloadSettings(); toast('Screaming Frog beállítások mentve.'); } catch (e) { toast(errorText(e), 'error'); }
		setBusy(false);
	};
	const lines = (v) => v.split('\n').map((x) => x.trim()).filter(Boolean);
	const site = window.location.origin;
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="crawl" /> Screaming Frog ügynök</h2>
			<div class="row-edit"><button class="btn btn--ghost btn--sm" onClick=${reload}><${Icon} name="refresh" size="14" /></button>
			<button class="btn btn--sm" disabled=${busy || !Object.keys(draft).length} onClick=${save}>Mentés</button></div>
		</header>
		<div class="pad stack-sm">
			${data.agents.length ? html`<table class="table table--dense"><thead><tr><th>Ügynök</th><th>Állapot</th><th>Rendszer</th><th>Screaming Frog</th></tr></thead>
				<tbody>${data.agents.map((a) => html`<tr key=${a.name}>
					<td><strong>${a.name}</strong><div class="muted small">v${a.info.agent_version || '?'}</div></td>
					<td><span class=${a.online ? 'ok' : 'muted'}>${a.online ? '● online' : '○ ' + fmt.ago(a.last_seen_at)}</span></td>
					<td class="small">${a.info.os || ''}</td>
					<td class=${'small' + (a.info.sf_found ? '' : ' danger')}>${a.info.sf_found ? a.info.sf_cli : 'Nem található a gépen'}</td>
				</tr>`)}</tbody></table>`
			: html`<p class="alert alert--info small"><span>Még nem jelentkezett ügynök. ${data.token_set ? '' : 'Előbb állítsd be a szerveren a SEO_OS_AGENT_TOKEN értéket.'}</span></p>`}
			<details class="howto" open=${!data.agents.length}><summary><strong>Telepítés</strong> – hova tegyük az ügynököt?</summary>
				<div class="stack-sm small">
					<p><strong>A) Irodai gép, ahol a licencelt Screaming Frog már fut</strong> (a legegyszerűbb): Python 3.9+ kell, más nem.</p>
					<ol>
						<li>Másold a gépre a <span class="url">services/seo-os-crawler/agent.py</span> fájlt és mellé az <span class="url">agent.env</span>-t (minta: <span class="url">agent.env.example</span>).</li>
						<li>Az <span class="url">agent.env</span>-be: <span class="url">HPV_SEO_URL=${site}</span> és <span class="url">HPV_AGENT_TOKEN=</span> a szerveren beállított token.</li>
						<li>Próba: <span class="url">python agent.py --check</span>, majd indítás: <span class="url">python agent.py</span> (Windows-on Feladatütemezőből indításkor, macOS-en launchd-vel).</li>
					</ol>
					<p><strong>B) Szerveren, Dockerben</strong> (nem függ az irodai géptől): a <span class="url">services/seo-os-crawler/Dockerfile</span> telepíti a Screaming Frogot; a licencet a <span class="url">SF_LICENCE_USER</span> / <span class="url">SF_LICENCE_KEY</span> környezeti változó adja. A licencfeltételeket (hány gépen használható) ellenőrizd.</p>
					<p>Az ügynök csak kimenő kapcsolatot nyit (${site}/wp-json/hpv-seo/v1/agent/…), tűzfalat nem kell nyitni. Ha nincs ügynök, a Screaming Frogból exportált táblák a Technikai audit fülön kézzel is feltölthetők.</p>
				</div>
			</details>
			<${Field} label="Export fülek (Fül:Szűrő, soronként)" hint="A Screaming Frog felületén látható fül- és szűrőnevek. A felismert exportok a HelloProVision audit témáiba kerülnek.">
				<${Textarea} rows="8" value=${(val('sf_export_tabs') || []).join('\n')} onInput=${(v) => setDraft((d) => ({ ...d, sf_export_tabs: lines(v) }))} />
			</${Field}>
			<${Field} label="Bulk exportok (soronként, opcionális)" hint="pl. a törött linkek forrásoldalaihoz – a pontos név a program Bulk Export menüjéből.">
				<${Textarea} rows="2" value=${(val('sf_bulk_exports') || []).join('\n')} onInput=${(v) => setDraft((d) => ({ ...d, sf_bulk_exports: lines(v) }))} />
			</${Field}>
			<${Field} label="Konfigurációs fájl az ügynök gépén (opcionális)" hint="A Screaming Frogban mentett .seospiderconfig (pl. URL-limit, JavaScript renderelés, sitemap crawl).">
				<${Input} value=${val('sf_config_file') || ''} placeholder="C:\\SEO\\hpv-audit.seospiderconfig" onInput=${(v) => setDraft((d) => ({ ...d, sf_config_file: v }))} />
			</${Field}>
			<${Checkbox} checked=${!!val('sf_save_crawl')} label="A crawl mentése a Screaming Frog adatbázisába (később a programban megnyitható)" onChange=${(v) => setDraft((d) => ({ ...d, sf_save_crawl: v }))} />
		</div>
	</section>`;
}
