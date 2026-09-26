/**
 * Csapat és jogok (csak adminisztrátor): ki számlázhat, ki készíthet szerződést és ajánlatot.
 */
import { html, useState, useEffect, api, useApp, Avatar, Spinner, Empty, toast } from '../ui.js';

export function Team() {
	const { boot } = useApp();
	const [data, setData] = useState(null);

	useEffect(() => { api('/team').then(setData).catch((e) => toast(e.message, 'error')); }, []);

	if (!boot.me.is_admin) return html`<${Empty} icon="users" title="Ezt az oldalt csak az adminisztrátor látja" />`;
	if (!data) return html`<${Spinner} />`;

	const toggle = (u, key, value) => {
		api('/team/' + u.id, { method: 'POST', body: { [key]: value } })
			.then((d) => { setData(d); toast('Mentve.'); })
			.catch((e) => toast(e.message, 'error'));
	};

	return html`
		<div class="page">
			<header class="page__head">
				<div>
					<h1>Csapat és jogok</h1>
					<p class="muted">Projekteket, chatet és hívásokat minden munkatárs kezelhet. A pénzügyi és jogi részekhez itt adhatsz hozzáférést.</p>
				</div>
			</header>
			<div class="card table-card">
				<table class="table table--team">
					<thead><tr><th>Munkatárs</th>${data.caps.map((c) => html`<th key=${c.key} title=${c.help}>${c.label}</th>`)}</tr></thead>
					<tbody>${data.users.map((u) => html`
						<tr key=${u.id}>
							<td><span class="team-user"><${Avatar} user=${u} size="30" /><span><strong>${u.name}</strong><small class="muted">${u.email}</small></span></span></td>
							${data.caps.map((c) => html`
								<td key=${c.key}>
									${u.caps.admin ? html`<span class="muted small">admin</span>` : html`
										<label class="switch"><input type="checkbox" checked=${u.caps[c.key]} onChange=${(e) => toggle(u, c.key, e.target.checked)} aria-label=${c.label + ': ' + u.name} /><span></span></label>`}
								</td>`)}
						</tr>`)}</tbody>
				</table>
			</div>
			<ul class="hint team-help">${data.caps.map((c) => html`<li key=${c.key}><strong>${c.label}:</strong> ${c.help}</li>`)}</ul>
			<p class="hint">Aki nem kap számlázási jogot, a bevételi számokat, a számlákat és a szolgáltatások díjait sem látja. Új munkatársat a WordPress Felhasználók menüjében lehet felvenni, „Munkatárs (CRM)” szerepkörrel.</p>
		</div>`;
}
