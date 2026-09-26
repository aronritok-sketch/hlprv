/**
 * Fájlok: az ügyféllel megosztott és tőle kapott fájlok (ügyfélhez vagy projekthez).
 * A projekt oldalán fül, az ügyfeleknél külön oldal (#/files?client=ID).
 */
import { html, useState, useEffect, useRef, api, upload, useApp, setParam, Icon, Spinner, Empty, toast, timeAgo } from '../ui.js';

function Uploader({ clientId, projectId, projects, max, maxLabel, onDone }) {
	const input = useRef(null);
	const [over, setOver] = useState(false);
	const [busy, setBusy] = useState(null); // { name, i, n, p }
	const [opts, setOpts] = useState({ visible: true, notify: true, note: '', project_id: projectId || 0 });

	const send = async (list) => {
		const files = Array.from(list || []);
		if (!files.length) return;
		const big = files.find((f) => max && f.size > max);
		if (big) { toast(`${big.name}: túl nagy (legfeljebb ${maxLabel}).`, 'error'); return; }
		let ok = 0;
		for (let i = 0; i < files.length; i++) {
			const fd = new FormData();
			fd.append('file', files[i]);
			fd.append('client_id', clientId);
			fd.append('project_id', opts.project_id || 0);
			fd.append('note', opts.note);
			fd.append('visible', opts.visible ? '1' : '0');
			fd.append('notify', opts.visible && opts.notify ? '1' : '0');
			setBusy({ name: files[i].name, i: i + 1, n: files.length, p: 0 });
			try {
				await upload('/files', fd, (p) => setBusy((b) => b && { ...b, p }));
				ok++;
			} catch (e) {
				toast(files[i].name + ': ' + e.message, 'error');
			}
		}
		setBusy(null);
		if (input.current) input.current.value = '';
		if (ok) {
			toast(ok === 1 ? 'Feltöltve.' : `${ok} fájl feltöltve.`);
			setOpts((o) => ({ ...o, note: '' }));
			onDone();
		}
	};

	return html`
		<div class=${'dropzone' + (over ? ' is-over' : '') + (busy ? ' is-busy' : '')}
			onDragOver=${(e) => { e.preventDefault(); setOver(true); }}
			onDragLeave=${() => setOver(false)}
			onDrop=${(e) => { e.preventDefault(); setOver(false); if (!busy) send(e.dataTransfer.files); }}>
			${busy ? html`
				<div class="dropzone__busy">
					<strong>${busy.name}</strong><span class="muted">${busy.n > 1 ? `${busy.i}/${busy.n} · ` : ''}${Math.round(busy.p * 100)}%</span>
					<span class="progress"><span style=${{ width: Math.round(busy.p * 100) + '%' }}></span></span>
				</div>` : html`
				<div class="dropzone__cta">
					<${Icon} name="up" />
					<span><strong>Húzd ide a fájlokat</strong> vagy <button type="button" class="link" onClick=${() => input.current.click()}>válaszd ki</button></span>
					<small class="muted">Legfeljebb ${maxLabel} fájlonként</small>
				</div>`}
			<input ref=${input} type="file" multiple hidden onChange=${(e) => send(e.target.files)} />
			<div class="dropzone__opts">
				${!projectId && projects.length ? html`
					<select value=${opts.project_id} onChange=${(e) => setOpts({ ...opts, project_id: Number(e.target.value) })}>
						<option value="0">Általános (nem projekthez)</option>
						${projects.map((p) => html`<option value=${p.id}>${p.name}</option>`)}
					</select>` : null}
				<input class="dropzone__note" placeholder="Megjegyzés (nem kötelező)" value=${opts.note} onInput=${(e) => setOpts({ ...opts, note: e.target.value })} />
				<label class="opt"><input type="checkbox" checked=${opts.visible} onChange=${(e) => setOpts({ ...opts, visible: e.target.checked })} /> Az ügyfél látja</label>
				${opts.visible ? html`<label class="opt"><input type="checkbox" checked=${opts.notify} onChange=${(e) => setOpts({ ...opts, notify: e.target.checked })} /> E-mail az ügyfélnek</label>` : null}
			</div>
		</div>`;
}

export function FilesPanel({ clientId, projectId, hideProject }) {
	const [data, setData] = useState(null);
	const [projects, setProjects] = useState([]);

	const load = () => {
		const q = new URLSearchParams({ client_id: clientId, ...(projectId ? { project_id: projectId } : {}) });
		return api('/files?' + q).then(setData).catch((e) => toast(e.message, 'error'));
	};
	useEffect(() => { setData(null); load(); }, [clientId, projectId]);
	useEffect(() => {
		if (!projectId) api('/pm/projects?client_id=' + clientId).then((l) => setProjects(l.filter((p) => p.status !== 'completed'))).catch(() => {});
	}, [clientId, projectId]);

	const update = (f, body) => api('/files/' + f.id, { method: 'POST', body }).then((nf) => {
		setData((d) => ({ ...d, files: d.files.map((x) => (x.id === nf.id ? nf : x)) }));
	}).catch((e) => toast(e.message, 'error'));
	const toggle = (f) => {
		const notify = !f.visible && f.source === 'staff' && window.confirm('Küldjünk e-mailt az ügyfélnek a fájlról?');
		update(f, { visible: !f.visible, notify });
	};
	const remove = (f) => {
		if (!window.confirm(`Törlöd: ${f.name}? Az ügyfél sem fogja látni.`)) return;
		api('/files/' + f.id, { method: 'DELETE' }).then(() => { toast('Törölve.'); load(); }).catch((e) => toast(e.message, 'error'));
	};

	if (!data) return html`<${Spinner} />`;
	return html`
		<div class="files">
			<${Uploader} clientId=${clientId} projectId=${projectId} projects=${projects} max=${data.max} maxLabel=${data.maxLabel} onDone=${load} />
			${data.files.length ? html`
				<div class="card table-card">
					<table class="table files-table">
						<thead><tr><th>Fájl</th>${hideProject ? null : html`<th>Projekt</th>`}<th>Feltöltötte</th><th class="right">Méret</th><th>Mikor</th><th>Ügyfél látja</th><th></th></tr></thead>
						<tbody>${data.files.map((f) => html`
							<tr key=${f.id}>
								<td class="files-table__name"><a class="link" href=${f.url} target="_blank" rel="noopener">${f.name}</a>${f.note ? html`<small class="muted">${f.note}</small>` : null}</td>
								${hideProject ? null : html`<td class="muted">${f.project || '—'}</td>`}
								<td>${f.source === 'client' ? html`<span class="pill pill--client">Ügyfél</span> ` : null}${f.by}</td>
								<td class="right muted">${f.size_label}</td>
								<td class="muted">${timeAgo(f.created_ts * 1000)}</td>
								<td><label class="switch" title=${f.source === 'client' ? 'Az ügyfél töltötte fel' : ''}><input type="checkbox" checked=${f.visible} disabled=${f.source === 'client'} onChange=${() => toggle(f)} /><span></span></label></td>
								<td class="right"><button class="icon-btn" title="Törlés" aria-label="Törlés" onClick=${() => remove(f)}><${Icon} name="trash" size="16" /></button></td>
							</tr>`)}
						</tbody>
					</table>
				</div>` : html`<${Empty} icon="folder" title="Még nincs fájl">Amit ide feltöltesz, az ügyfél a portálon látja (ha bekapcsolod). Amit ő tölt fel, arról e-mailt kapsz.</${Empty}>`}
		</div>`;
}

export function Files({ params }) {
	const { boot } = useApp();
	const clientId = Number(params.client) || 0;
	const client = boot.clients.find((c) => c.id === clientId);
	return html`
		<div class="page">
			<header class="page__head">
				<div><h1>Fájlok</h1><p class="muted">Megosztott fájlok az ügyfelekkel. Az ügyfél a portál „Files / Fájlok” menüjében látja őket.</p></div>
			</header>
			<div class="toolbar">
				<select class="meta-select files-client" value=${clientId} onChange=${(e) => setParam('client', e.target.value === '0' ? '' : e.target.value)}>
					<option value="0">Válassz ügyfelet…</option>
					${boot.clients.map((c) => html`<option value=${c.id}>${c.name}</option>`)}
				</select>
			</div>
			${client ? html`<${FilesPanel} key=${clientId} clientId=${clientId} />` : html`<${Empty} icon="folder" title="Válassz ügyfelet" />`}
		</div>`;
}
