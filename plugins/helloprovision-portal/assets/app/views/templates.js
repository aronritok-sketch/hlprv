/**
 * Minták: a saját szerződés- és ajánlatmintátok. Ebből dolgozik az AI.
 */
import { html, useState, useEffect, api, useApp, setParam, Icon, Modal, Spinner, Empty, toast } from '../ui.js';
import { RichText } from './richtext.js';

const TYPES = { contract: 'Szerződésminták', proposal: 'Ajánlatminták' };

function TemplateEditor({ tpl, type, onClose, onSaved }) {
	const [f, setF] = useState({ name: tpl ? tpl.name : '', language: tpl ? tpl.language : 'en', body: tpl ? tpl.body : '', instructions: tpl ? tpl.instructions : '' });
	const [busy, setBusy] = useState(false);
	const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
	const submit = (e) => {
		e.preventDefault();
		setBusy(true);
		api('/docs/templates' + (tpl ? '/' + tpl.id : ''), { method: 'POST', body: { ...f, type } })
			.then((t) => { toast('Minta mentve.'); onSaved(t); })
			.catch((err) => { toast(err.message, 'error'); setBusy(false); });
	};
	const remove = () => {
		if (!window.confirm('Törlöd a mintát? A belőle készült dokumentumok megmaradnak.')) return;
		api('/docs/templates/' + tpl.id, { method: 'DELETE' }).then(() => { toast('Törölve.'); onSaved(null); }).catch((e) => toast(e.message, 'error'));
	};
	return html`
		<${Modal} title=${(tpl ? '' : 'Új ') + (type === 'contract' ? 'szerződésminta' : 'ajánlatminta')} onClose=${onClose} wide>
			<form class="form" onSubmit=${submit}>
				<div class="row">
					<label class="field"><span>Név</span><input required value=${f.name} onInput=${set('name')} placeholder=${type === 'contract' ? 'pl. Weboldal szerződés (USA)' : 'pl. Weboldal + SEO ajánlat'} /></label>
					<label class="field"><span>Nyelv</span><select value=${f.language} onChange=${set('language')}><option value="en">Angol</option><option value="hu">Magyar</option></select></label>
				</div>
				<div class="field"><span>A minta szövege — másold be a saját ${type === 'contract' ? 'szerződésedet' : 'ajánlatodat'} (Wordből is jó)</span>
					<${RichText} value=${f.body} onChange=${(body) => setF((x) => ({ ...x, body }))} placeholder="Illeszd be ide a mintát…" />
				</div>
				<label class="field"><span>Állandó utasítás az AI-nak (nem kötelező)</span>
					<textarea rows="3" value=${f.instructions} onInput=${set('instructions')} placeholder=${type === 'contract' ? 'pl. Mindig 50% előleg. A tárhely külön szolgáltatás. Floridai jog.' : 'pl. Tegeződés nélkül, rövid bekezdések. Mindig legyen havi SEO opció.'}></textarea>
				</label>
				<footer class="form__foot">
					${tpl ? html`<button type="button" class="link danger" onClick=${remove}>Törlés</button>` : null}
					<span style="flex:1"></span>
					<button type="button" class="btn btn--ghost" onClick=${onClose}>Mégse</button>
					<button class="btn" disabled=${busy}>Mentés</button>
				</footer>
			</form>
		</${Modal}>`;
}

export function Templates({ params }) {
	const { boot } = useApp();
	const caps = boot.me.caps || {};
	const allowed = Object.keys(TYPES).filter((t) => (t === 'contract' ? caps.contracts : caps.proposals));
	const type = allowed.includes(params.type) ? params.type : allowed[0];
	const [data, setData] = useState(null);
	const [edit, setEdit] = useState(null); // null | 'new' | template

	const load = () => api('/docs/templates').then(setData).catch((e) => toast(e.message, 'error'));
	useEffect(() => { load(); }, []);

	if (!allowed.length) return html`<${Empty} icon="template" title="Ehhez nincs jogod" />`;
	const list = data ? data.templates.filter((t) => t.type === type) : [];

	return html`
		<div class="page">
			<header class="page__head">
				<div><h1>Minták</h1><p class="muted">A saját szerződés- és ajánlatmintáitok. Az AI ezeket szabja az ügyfélre, a stílusotokat és a pontjaitokat megtartva.</p></div>
				<button class="btn" onClick=${() => setEdit('new')}><${Icon} name="plus" /> Új minta</button>
			</header>
			<div class="toolbar"><div class="seg">${allowed.map((t) => html`<button key=${t} class=${type === t ? 'is-active' : ''} onClick=${() => setParam('type', t)}>${TYPES[t]}</button>`)}</div></div>
			${!data ? html`<${Spinner} />` : list.length ? html`
				<div class="template-grid">${list.map((t) => html`
					<button key=${t.id} class="template-card" onClick=${() => setEdit(t)}>
						<span class="template-card__lang">${t.language === 'hu' ? 'HU' : 'EN'}</span>
						<strong>${t.name}</strong>
						<span class="template-card__preview" dangerouslySetInnerHTML=${{ __html: t.body.slice(0, 600) }}></span>
						${t.instructions ? html`<small class="muted">AI: ${t.instructions}</small>` : null}
					</button>`)}</div>`
				: html`<${Empty} icon="template" title="Még nincs minta">Másold be a jelenlegi ${type === 'contract' ? 'szerződésmintádat' : 'ajánlatmintádat'} (angol és magyar változatot is, ha van). Utána minden új ${type === 'contract' ? 'szerződés' : 'ajánlat'} ebből készül.</${Empty}>`}
			${edit ? html`<${TemplateEditor} tpl=${edit === 'new' ? null : edit} type=${type} onClose=${() => setEdit(null)} onSaved=${() => { setEdit(null); load(); }} />` : null}
		</div>`;
}
