/**
 * Átköltözés a Bitrix24-ből (csak adminisztrátor): webhook, próbafuttatás, import lépésenként, haladással.
 */
import { html, useState, useEffect, useRef, api, useApp, Icon, Spinner, Empty, toast } from '../ui.js';

const WHAT = { created: 'új', matched: 'meglévő', updated: 'kiegészítve', skipped: 'kihagyva' };

function Counts({ run, steps }) {
	const label = Object.fromEntries(steps.map((s) => [s.key, s.label]));
	return html`
		<table class="table import-table">
			<thead><tr><th>Mit</th>${Object.values(WHAT).map((w) => html`<th class="right">${w}</th>`)}<th class="right">Bitrix24-ben</th></tr></thead>
			<tbody>${run.steps.map((k, i) => {
				const c = run.counts[k] || {};
				const state = run.done || i < run.stepIndex ? 'done' : i === run.stepIndex ? 'now' : 'wait';
				return html`
					<tr key=${k} class=${'import-row import-row--' + state}>
						<td>${state === 'now' ? html`<span class="spin-dot"></span>` : state === 'done' ? html`<${Icon} name="check" size="15" />` : html`<span class="muted">·</span>`} ${label[k]}</td>
						${Object.keys(WHAT).map((w) => html`<td class="right">${c[w] || 0}</td>`)}
						<td class="right muted">${run.total[k] ?? '—'}</td>
					</tr>`;
			})}</tbody>
		</table>`;
}

export function ImportPage() {
	const { boot } = useApp();
	const [st, setSt] = useState(null);
	const [hook, setHook] = useState('');
	const [busy, setBusy] = useState(false);
	const [pick, setPick] = useState(null);
	const stop = useRef(false);

	const load = () => api('/import/bitrix').then((d) => { setSt(d); if (!pick) setPick(d.steps.map((s) => s.key)); }).catch((e) => toast(e.message, 'error'));
	useEffect(() => { load(); return () => { stop.current = true; }; }, []);

	if (!boot.me.is_admin) return html`<${Empty} icon="users" title="Ezt az oldalt csak az adminisztrátor látja" />`;
	if (!st) return html`<${Spinner} />`;

	const connect = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/import/bitrix/connect', { method: 'POST', body: { webhook: hook } })
			.then((d) => { setSt(d); setHook(''); toast('Kapcsolódva.'); })
			.catch((err) => toast(err.message, 'error'))
			.finally(() => setBusy(false));
	};
	const disconnect = () => {
		if (!window.confirm('Törlöd a webhookot? Az importált adatok megmaradnak.')) return;
		api('/import/bitrix/disconnect', { method: 'POST' }).then(setSt);
	};
	const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
	const go = async (dry) => {
		if (!dry && !window.confirm('Indulhat az import? A meglévő ügyfeleket nem írja felül, csak az üres mezőket tölti ki. Levelet nem küld.')) return;
		setBusy(true);
		stop.current = false;
		try {
			let d = await api('/import/bitrix/start', { method: 'POST', body: { dry, steps: pick, force: true } });
			setSt(d);
			let fails = 0;
			while (!stop.current && d.run && !d.run.done) {
				try {
					d = await api('/import/bitrix/step', { method: 'POST' });
					setSt(d);
					fails = 0;
				} catch (e) {
					// Túlterhelés vagy átmeneti hiba: kicsit várunk, legfeljebb 5-ször.
					if (++fails > 5) throw e;
					await sleep(1500 * fails);
				}
			}
			if (d.run && d.run.done) toast(dry ? 'A próbafuttatás kész. Semmi nem változott.' : 'Az import kész.');
		} catch (e) {
			toast(e.message, 'error');
		}
		setBusy(false);
	};

	const run = st.run;
	return html`
		<div class="page">
			<header class="page__head">
				<div><h1>Átköltözés a Bitrix24-ből</h1><p class="muted">Cégek, kapcsolatok, érdeklődők, üzletek, munkacsoportok és feladatok. Többször is futtatható: ami már megvan, az nem lesz dupla.</p></div>
			</header>

			<section class="card import-card">
				<h2>1. Kapcsolat</h2>
				${st.connected ? html`
					<p><${Icon} name="check" size="16" /> Kapcsolódva${st.profile ? ' (' + st.profile + ')' : ''}: <code>${st.webhook}</code> <button class="link danger" onClick=${disconnect}>Webhook törlése</button></p>` : html`
					<ol class="hint import-steps">
						<li>Bitrix24-ben: <strong>Fejlesztői erőforrások → Egyéb → Bejövő webhook</strong>.</li>
						<li>Jogok: <strong>CRM (crm)</strong>, <strong>Feladatok (task)</strong>, <strong>Munkacsoportok (sonet_group)</strong>, <strong>Felhasználók (user)</strong>.</li>
						<li>Mentés után másold be ide a „Webhook a REST hívásához” címet.</li>
					</ol>
					<form class="import-connect" onSubmit=${connect}>
						<input required type="url" placeholder="https://cegnev.bitrix24.hu/rest/1/abc123…/" value=${hook} onInput=${(e) => setHook(e.target.value)} />
						<button class="btn" disabled=${busy}>Kapcsolódás</button>
					</form>
					<p class="hint">A címet titkosítva tároljuk. Az import végén törölheted a webhookot a Bitrix24-ben is.</p>`}
			</section>

			${st.connected ? html`
				<section class="card import-card">
					<h2>2. Mit hozzunk át?</h2>
					<div class="import-pick">${st.steps.map((s) => html`
						<label key=${s.key} class="opt"><input type="checkbox" disabled=${busy} checked=${pick && pick.includes(s.key)} onChange=${(e) => setPick(e.target.checked ? [...pick, s.key] : pick.filter((k) => k !== s.key))} /> ${s.label}</label>`)}
					</div>
					<p class="hint">Az új projektek és feladatok rejtve jönnek át (az ügyfél nem látja őket), az üzletek belső jegyzetként az ügyfélnél. A felelősöket e-mail cím alapján párosítjuk a munkatársakhoz. Levelet senki nem kap, portál-hozzáférés nem jön létre.</p>
					<div class="import-actions">
						<button class="btn btn--ghost" disabled=${busy || !pick.length} onClick=${() => go(true)}><${Icon} name="eye" /> Próbafuttatás</button>
						<button class="btn" disabled=${busy || !pick.length} onClick=${() => go(false)}>Importálás</button>
						${busy ? html`<button class="link" onClick=${() => { stop.current = true; }}>Megállítás</button>` : null}
					</div>
				</section>` : null}

			${run ? html`
				<section class="card import-card">
					<h2>${run.dry ? 'Próbafuttatás' : 'Import'} ${run.done ? '— kész' : '— folyamatban…'}</h2>
					${run.dry ? html`<p class="hint">Ez csak előnézet: ennyi rekord jönne létre vagy egészülne ki. Az adatbázis nem változott.</p>` : null}
					<div class="card table-card"><${Counts} run=${run} steps=${st.steps} /></div>
					${run.warnings.length ? html`<details class="import-warn"><summary>${run.warnings.length} figyelmeztetés</summary><ul>${run.warnings.map((w, i) => html`<li key=${i}>${w}</li>`)}</ul></details>` : null}
				</section>` : null}
		</div>`;
}
