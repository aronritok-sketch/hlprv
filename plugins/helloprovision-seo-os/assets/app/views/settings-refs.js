/**
 * Referenciadokumentumok: saját HelloProVision minták (DOCX / PDF / TXT). A Claude ezekből veszi át a hangnemet és a
 * felépítést az adott dokumentumtípusnál; a tartalmukat nem másolja.
 */
import { html, useState, useApp, api, upload, useLoad, toast, errorText, fmt, Icon, Spinner, Select, DropZone, Checkbox } from '../ui.js';

export function ReferenceDocs() {
	const { meta } = useApp();
	const [refs, loading, , reload, setRefs] = useLoad('/reference-docs');
	const [type, setType] = useState('general');
	const [lang, setLang] = useState('hu');
	const [busy, setBusy] = useState(false);
	const types = [['general', 'Általános (minden dokumentum)'], ...Object.entries(meta.doc_types || {}).map(([k, v]) => [k, v.label])];
	const onFiles = async (files) => {
		setBusy(true);
		for (const f of files) {
			try { await upload('/reference-docs?doc_type=' + type + '&language=' + lang, f); toast(f.name + ' feltöltve.'); } catch (e) { toast(f.name + ': ' + errorText(e), 'error'); }
		}
		setBusy(false);
		reload();
	};
	const patch = async (r, body) => {
		try { const n = await api('/reference-docs/' + r.id, { method: 'PATCH', body }); setRefs((all) => all.map((x) => (x.id === n.id ? n : x))); } catch (e) { toast(errorText(e), 'error'); }
	};
	const remove = async (r) => {
		if (!window.confirm('Törlöd: ' + r.title + '?')) return;
		try { await api('/reference-docs/' + r.id, { method: 'DELETE' }); reload(); } catch (e) { toast(errorText(e), 'error'); }
	};
	return html`<section class="card">
		<header class="card__head"><h2><${Icon} name="doc" /> Saját minták a dokumentumokhoz</h2></header>
		<div class="pad stack-sm">
			<p class="muted small">Töltsd fel a korábbi, jól sikerült dokumentumainkat (pl. „gulyastamas.hu SEO fejlesztői módosítások.docx”, tartalomstratégia, ajánlat-PDF). A Claude ezek hangnemét és felépítését követi – a számokat és táblákat mindig a projekt adataiból veszi.</p>
			<div class="row-edit"><${Select} value=${type} onChange=${setType} options=${types} /><${Select} value=${lang} onChange=${setLang} options=${[['hu', 'Magyar'], ['en', 'Angol']]} /></div>
			<${DropZone} onFiles=${onFiles} busy=${busy} accept=".docx,.pdf,.txt,.md" label="Minta feltöltése (DOCX, PDF, TXT)" />
		</div>
		${loading && !refs ? html`<${Spinner} />` : (refs || []).length ? html`<table class="table table--dense"><thead><tr><th>Minta</th><th>Típus</th><th>Nyelv</th><th>Szöveg</th><th>Aktív</th><th></th></tr></thead>
			<tbody>${refs.map((r) => html`<tr key=${r.id}>
				<td><strong>${r.title}</strong><div class="muted small">${fmt.date(r.created_at)}</div></td>
				<td><${Select} value=${r.doc_type} onChange=${(v) => patch(r, { doc_type: v })} options=${types} /></td>
				<td>${r.language === 'en' ? 'angol' : 'magyar'}</td>
				<td class="small">${fmt.num(r.chars)} karakter</td>
				<td><${Checkbox} checked=${r.is_active} onChange=${(v) => patch(r, { is_active: v })} /></td>
				<td><button class="icon-btn" title="Törlés" onClick=${() => remove(r)}><${Icon} name="trash" size="15" /></button></td>
			</tr>`)}</tbody></table>` : ''}
	</section>`;
}
