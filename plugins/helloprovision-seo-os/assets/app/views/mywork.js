import { html, Empty } from '../ui.js';

export function MyWork() {
	return html`<div class="page"><h1>Saját munkáim</h1><${Empty} title="Nincs nyitott feladatod" icon="tasks" /></div>`;
}
