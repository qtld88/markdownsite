import { mdiCheck, mdiContentCopy } from '@mdi/js'

const SVG_NS = 'http://www.w3.org/2000/svg'

/** An 18 px inline SVG icon from an @mdi/js path. */
export function icon(path) {
	const svg = document.createElementNS(SVG_NS, 'svg')
	svg.setAttribute('viewBox', '0 0 24 24')
	svg.setAttribute('width', '18')
	svg.setAttribute('height', '18')
	svg.setAttribute('aria-hidden', 'true')
	const p = document.createElementNS(SVG_NS, 'path')
	p.setAttribute('d', path)
	p.setAttribute('fill', 'currentColor')
	svg.appendChild(p)
	return svg
}

function legacyCopy(text, html) {
	const holder = document.createElement(html ? 'div' : 'textarea')
	holder.style.cssText = 'position:fixed;left:-9999px;top:0;white-space:pre-wrap'
	if (html) {
		holder.contentEditable = 'true'
		holder.innerHTML = html
	} else {
		holder.value = text
	}
	document.body.appendChild(holder)
	try {
		if (html) {
			const range = document.createRange()
			range.selectNodeContents(holder)
			const sel = window.getSelection()
			sel.removeAllRanges()
			sel.addRange(range)
		} else {
			holder.select()
		}
		return document.execCommand('copy')
	} finally {
		window.getSelection()?.removeAllRanges()
		holder.remove()
	}
}

/** Copies `text` (and `html`, as rich text, when given). Resolves to true on success. */
export async function copy(text, html = null) {
	try {
		if (html && window.ClipboardItem && navigator.clipboard?.write) {
			await navigator.clipboard.write([new ClipboardItem({
				'text/html': new Blob([html], { type: 'text/html' }),
				'text/plain': new Blob([text], { type: 'text/plain' }),
			})])
			return true
		}
		if (!html && navigator.clipboard?.writeText) {
			await navigator.clipboard.writeText(text)
			return true
		}
	} catch (e) {
		// fall through to the legacy path
	}
	return legacyCopy(text, html)
}

/** Shows a check mark on a copy button for 1.5 s ("done" state). */
export function flashDone(button) {
	button.replaceChildren(icon(mdiCheck))
	button.classList.add('is-done')
	setTimeout(() => {
		button.replaceChildren(icon(mdiContentCopy))
		button.classList.remove('is-done')
	}, 1500)
}
