import { translate as t } from '@nextcloud/l10n'
import { mdiContentCopy, mdiCheck } from '@mdi/js'

const SVG_NS = 'http://www.w3.org/2000/svg'

function icon(path) {
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

/** HTML of the callout body, made self-contained for pasting into an email. */
function bodyHtml(content) {
	const clone = content.cloneNode(true)
	clone.querySelectorAll('a[href]').forEach((a) => { a.setAttribute('href', a.href) })
	clone.querySelectorAll('img[src]').forEach((img) => { img.setAttribute('src', img.src) })
	clone.querySelectorAll('table').forEach((el) => el.setAttribute('style', 'border-collapse:collapse'))
	clone.querySelectorAll('th, td').forEach((el) => el.setAttribute('style', 'border:1px solid #ccc;padding:4px 8px;text-align:left'))
	clone.querySelectorAll('th').forEach((el) => el.setAttribute('style', 'border:1px solid #ccc;padding:4px 8px;text-align:left;background:#f0f0f0'))
	clone.querySelectorAll('mark').forEach((el) => el.setAttribute('style', 'background:#ffe97a'))
	clone.querySelectorAll('input[type=checkbox]').forEach((el) => el.replaceWith(el.checked ? '☑ ' : '☐ '))
	return clone.innerHTML
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

async function copy(text, html = null) {
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

function closeMenus(except = null) {
	document.querySelectorAll('.mds-callout-menu').forEach((m) => {
		if (m !== except) {
			m.hidden = true
			m.parentElement?.querySelector('.mds-callout-copy')?.setAttribute('aria-expanded', 'false')
		}
	})
}

let globalListenersBound = false
function bindGlobalListeners() {
	if (globalListenersBound) { return }
	globalListenersBound = true
	document.addEventListener('click', (ev) => {
		if (!ev.target.closest?.('.mds-callout-actions')) { closeMenus() }
	})
	document.addEventListener('keydown', (ev) => {
		if (ev.key === 'Escape') { closeMenus() }
	})
}

function decorate(callout) {
	if (callout.querySelector(':scope > .mds-callout-title .mds-callout-actions')) { return }
	const title = callout.querySelector(':scope > .mds-callout-title')
	const content = callout.querySelector(':scope > .mds-callout-content')
	if (!title || !content) { return }

	const actions = document.createElement('span')
	actions.className = 'mds-callout-actions'

	const button = document.createElement('button')
	button.type = 'button'
	button.className = 'mds-callout-copy'
	button.title = t('markdownsite', 'Copy')
	button.setAttribute('aria-label', t('markdownsite', 'Copy'))
	button.setAttribute('aria-haspopup', 'true')
	button.setAttribute('aria-expanded', 'false')
	button.appendChild(icon(mdiContentCopy))

	const menu = document.createElement('div')
	menu.className = 'mds-callout-menu'
	menu.hidden = true

	const flash = () => {
		button.replaceChildren(icon(mdiCheck))
		button.classList.add('is-done')
		setTimeout(() => {
			button.replaceChildren(icon(mdiContentCopy))
			button.classList.remove('is-done')
		}, 1500)
	}

	const addItem = (label, handler) => {
		const item = document.createElement('button')
		item.type = 'button'
		item.textContent = label
		item.addEventListener('click', async (ev) => {
			ev.preventDefault()
			ev.stopPropagation()
			menu.hidden = true
			button.setAttribute('aria-expanded', 'false')
			if (await handler()) { flash() }
		})
		menu.appendChild(item)
	}

	const markdown = callout.getAttribute('data-markdown')
	if (markdown) {
		addItem(t('markdownsite', 'Copy as Markdown'), () => copy(markdown))
	}
	addItem(t('markdownsite', 'Copy as HTML (e.g. for an email)'), () => copy(content.innerText.trim(), bodyHtml(content)))

	button.addEventListener('click', (ev) => {
		// The button sits inside <summary> for foldable callouts: don't toggle it.
		ev.preventDefault()
		ev.stopPropagation()
		const willOpen = menu.hidden
		closeMenus(menu)
		menu.hidden = !willOpen
		button.setAttribute('aria-expanded', String(willOpen))
	})

	actions.append(button, menu)
	title.appendChild(actions)
}

/** Adds a "copy" button with a Markdown / HTML choice to every callout under `root`. */
export function decorateCallouts(root) {
	if (!root) { return }
	bindGlobalListeners()
	root.querySelectorAll('.mds-callout').forEach(decorate)
}
