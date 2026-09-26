/**
 * Egyszerű dokumentum-szerkesztő (szerződés, ajánlat, minta): címsor, lista, félkövér, dőlt, link.
 * Beillesztéskor (Word, Google Docs, ChatGPT) a formázás-szemét lekerül, a szerkezet marad.
 * A szerver is megtisztítja (hpv_doc_clean_html).
 */
import { html, useEffect, useRef, Icon } from '../ui.js';

const ALLOWED = new Set(['H2', 'H3', 'H4', 'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'BLOCKQUOTE', 'A', 'MARK', 'HR']);
const RENAME = { H1: 'H2', DIV: 'P', SECTION: 'P', ARTICLE: 'P' };
const DROP = new Set(['STYLE', 'SCRIPT', 'META', 'LINK', 'TITLE', 'XML', 'HEAD', 'IMG', 'SVG', 'IFRAME', 'OBJECT']);

export function cleanHtml(dirty) {
	const doc = new DOMParser().parseFromString(dirty, 'text/html');
	const walk = (node, out) => {
		node.childNodes.forEach((child) => {
			if (child.nodeType === 3) { out.appendChild(out.ownerDocument.createTextNode(child.textContent)); return; }
			if (child.nodeType !== 1) return;
			let tag = child.tagName;
			if (DROP.has(tag) || tag.includes(':')) {
				if (tag.includes(':')) walk(child, out); // pl. <o:p> — a szövege maradjon
				return;
			}
			tag = RENAME[tag] || tag;
			if (!ALLOWED.has(tag)) { walk(child, out); return; } // span, font: kicsomagolva
			const el = out.ownerDocument.createElement(tag);
			if (tag === 'A') {
				const href = child.getAttribute('href') || '';
				if (/^(https?:|mailto:|tel:)/i.test(href)) el.setAttribute('href', href);
			}
			walk(child, el);
			if (/^(P|LI|H2|H3|H4)$/.test(tag) && !el.textContent.trim()) return;
			out.appendChild(el);
		});
	};
	const target = document.implementation.createHTMLDocument('').body;
	walk(doc.body, target);
	return target.innerHTML;
}

const esc = (t) => t.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

export function RichText({ value, onChange, placeholder, readOnly, compact }) {
	const ref = useRef(null);
	const last = useRef(value);

	useEffect(() => {
		if (ref.current && value !== last.current && ref.current.innerHTML !== value) {
			ref.current.innerHTML = value || '';
		}
		last.current = value;
	}, [value]);
	useEffect(() => { if (ref.current) ref.current.innerHTML = value || ''; }, []);

	const emit = () => {
		const v = ref.current.innerHTML;
		if (v !== last.current) {
			last.current = v;
			onChange && onChange(v);
		}
	};
	const exec = (cmd, arg) => (e) => {
		e.preventDefault();
		ref.current.focus();
		document.execCommand(cmd, false, arg);
		emit();
	};
	const link = (e) => {
		e.preventDefault();
		const url = window.prompt('Link címe (https://…):');
		if (url && /^(https?:|mailto:)/i.test(url)) { ref.current.focus(); document.execCommand('createLink', false, url); emit(); }
	};
	const onPaste = (e) => {
		e.preventDefault();
		const h = e.clipboardData.getData('text/html');
		const t = e.clipboardData.getData('text/plain');
		const clean = h ? cleanHtml(h) : esc(t).split(/\n{2,}/).map((p) => '<p>' + p.replace(/\n/g, '<br>') + '</p>').join('');
		document.execCommand('insertHTML', false, clean);
		emit();
	};
	const B = (label, cmd, arg, title) => html`<button type="button" class="rt-btn" title=${title} onMouseDown=${exec(cmd, arg)}>${label}</button>`;

	return html`
		<div class=${'rt' + (readOnly ? ' rt--ro' : '') + (compact ? ' rt--compact' : '')}>
			${readOnly ? null : html`
				<div class="rt-bar">
					${B(html`<strong>B</strong>`, 'bold', null, 'Félkövér (Ctrl+B)')}
					${B(html`<em>I</em>`, 'italic', null, 'Dőlt (Ctrl+I)')}
					${compact ? null : B('H2', 'formatBlock', 'h2', 'Fejezetcím')}
					${B('H3', 'formatBlock', 'h3', 'Alcím')}
					${B('¶', 'formatBlock', 'p', 'Bekezdés')}
					${B('•', 'insertUnorderedList', null, 'Felsorolás')}
					${B('1.', 'insertOrderedList', null, 'Számozott lista')}
					<button type="button" class="rt-btn" title="Link" onMouseDown=${link}><${Icon} name="link" size="14" /></button>
					${B('⌫', 'removeFormat', null, 'Formázás törlése')}
				</div>`}
			<div ref=${ref} class="rt-body doc" contenteditable=${readOnly ? 'false' : 'true'} data-placeholder=${placeholder || ''}
				onInput=${() => { if (ref.current.innerHTML === '<br>') ref.current.innerHTML = ''; }} onBlur=${emit} onPaste=${onPaste}></div>
		</div>`;
}
