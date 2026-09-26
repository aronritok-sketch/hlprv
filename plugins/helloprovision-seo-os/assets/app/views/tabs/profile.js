/**
 * Üzleti profil: a weboldal és a bekért adatok alapján (AI vagy szabályalapú), szerkeszthető, jóváhagyható.
 */
import { html, useState, useApp, api, useLoad, useJob, toast, errorText, fmt, Icon, Pill, Drawer, Field, Textarea, Tags, can } from '../../ui.js';

function Edit({ project, profile, onClose, onSaved }) {
	const [f, setF] = useState({
		summary: profile.summary, positioning: profile.positioning,
		differentiators: profile.differentiators || [], proof_assets: profile.proof_assets || [],
		audiences: (profile.audiences || []).map((a) => a.name + (a.needs ? ' – ' + a.needs : '')),
	});
	const set = (k) => (v) => setF((x) => ({ ...x, [k]: v }));
	const save = async (approve) => {
		try {
			const body = { ...f, audiences: f.audiences.map((s) => { const [name, ...rest] = s.split(' – '); return { name, needs: rest.join(' – ') }; }), approve };
			onSaved(await api('/projects/' + project.id + '/profile', { method: 'PUT', body }));
			toast(approve ? 'Jóváhagyva.' : 'Mentve.');
			onClose();
		} catch (e) { toast(errorText(e), 'error'); }
	};
	return html`<${Drawer} title="Üzleti profil" onClose=${onClose} footer=${html`<button class="btn btn--ghost" onClick=${() => save(false)}>Mentés</button><button class="btn" onClick=${() => save(true)}>Mentés és jóváhagyás</button>`}>
		<div class="form-grid">
			<${Field} label="Összefoglaló" wide><${Textarea} rows="3" value=${f.summary} onInput=${set('summary')} /></${Field}>
			<${Field} label="Pozicionálás" wide><${Textarea} rows="3" value=${f.positioning} onInput=${set('positioning')} /></${Field}>
			<${Field} label="Célcsoportok" wide hint="„Név – igény” formában"><${Tags} value=${f.audiences} onChange=${set('audiences')} /></${Field}>
			<${Field} label="Megkülönböztető előnyök" wide><${Tags} value=${f.differentiators} onChange=${set('differentiators')} /></${Field}>
			<${Field} label="Proof (esettanulmány, eredmény, díj)" wide><${Tags} value=${f.proof_assets} onChange=${set('proof_assets')} /></${Field}>
		</div>
	</${Drawer}>`;
}

export function BusinessProfile({ project }) {
	const { me } = useApp();
	const [profile, loading, , reload, setProfile] = useLoad('/projects/' + project.id + '/profile');
	const [editing, setEditing] = useState(false);
	const [job, setJob] = useJob((j) => { toast(j.result.site_error ? 'Profil kész (a weboldal nem volt elérhető).' : 'Üzleti profil kész.'); reload(); });
	const running = job && (job.status === 'queued' || job.status === 'running');
	const generate = async () => {
		try {
			const j = await api('/projects/' + project.id + '/profile/generate', { method: 'POST' });
			if (j.status === 'done') reload(); else if (j.status === 'failed') toast(j.error, 'error'); else setJob(j);
		} catch (e) { toast(errorText(e), 'error'); }
	};
	if (loading && !profile) return null;
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="spark" /> Üzleti profil</h2>
			<div class="row-edit">
				${profile ? html`<${Pill} kind=${profile.approved_at ? 'ok' : 'warn'}>${profile.approved_at ? 'Jóváhagyva' : 'v' + profile.version + ' · ' + (profile.source === 'ai' ? 'AI' : profile.source === 'manual' ? 'kézi' : 'szabályalapú')}</${Pill}>` : ''}
				${can(me, 'ai.run') ? html`<button class="btn btn--ghost btn--sm" disabled=${running} onClick=${generate}>${running ? 'Készül…' : profile ? 'Újragenerálás' : 'Profil készítése'}</button>` : ''}
				${profile && can(me, 'project.edit') ? html`<button class="btn btn--ghost btn--sm" onClick=${() => setEditing(true)}><${Icon} name="edit" /></button>` : ''}
			</div>
		</header>
		${profile ? html`<div class="pad stack-sm">
			${profile.summary ? html`<p>${profile.summary}</p>` : ''}
			${profile.positioning ? html`<p class="small"><span class="muted">Pozicionálás:</span> ${profile.positioning}</p>` : ''}
			${(profile.audiences || []).length ? html`<p class="small"><span class="muted">Célcsoportok:</span> ${profile.audiences.map((a) => a.name).join(', ')}</p>` : ''}
			${(profile.differentiators || []).length ? html`<div class="chips">${profile.differentiators.map((d) => html`<span key=${d} class="chip">${d}</span>`)}</div>` : ''}
			${profile.site_snapshot && profile.site_snapshot.title ? html`<p class="muted small">Weboldal: ${profile.site_snapshot.title} · ${fmt.date(profile.created_at)}</p>` : ''}
		</div>` : html`<p class="muted pad small">A profil az AI-elemzés környezete: szolgáltatások, célcsoport, pozicionálás. A weboldal és a bekért adatok alapján készül.</p>`}
		${editing ? html`<${Edit} project=${project} profile=${profile} onClose=${() => setEditing(false)} onSaved=${setProfile} />` : ''}
	</section>`;
}
