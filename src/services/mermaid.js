import { translate as t } from '@nextcloud/l10n'
import { loadChunk } from './chunks.js'
import { isDarkTheme } from './theme.js'

let initialisedTheme = null
let counter = 0

/**
 * Replaces every ```mermaid block under `root` with its rendered diagram.
 * Mermaid is only downloaded when the page has a diagram. A diagram that
 * does not parse becomes an "Invalid diagram" box followed by its source.
 */
export async function renderDiagrams(root) {
	if (!root) {
		return
	}
	const blocks = [...root.querySelectorAll('pre > code.language-mermaid')]
	if (!blocks.length) {
		return
	}
	const mod = await loadChunk(() => import(/* webpackChunkName: "mermaid" */ 'mermaid'), 'Mermaid')
	if (!mod) {
		return
	}
	const mermaid = mod.default
	// The theme is read once per render: a theme switch applies to the next page.
	const theme = isDarkTheme() ? 'dark' : 'default'
	if (initialisedTheme !== theme) {
		mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme })
		initialisedTheme = theme
	}

	for (const code of blocks) {
		const pre = code.parentElement
		if (!pre?.isConnected) {
			continue
		}
		const id = `mds-mermaid-${++counter}`
		const box = document.createElement('div')
		try {
			const { svg } = await mermaid.render(id, code.textContent)
			box.className = 'mds-mermaid'
			box.innerHTML = svg
		} catch (error) {
			// Mermaid may leave its scratch element behind on failure.
			document.getElementById(`d${id}`)?.remove()
			box.className = 'mds-mermaid-error'
			const title = document.createElement('p')
			title.className = 'mds-mermaid-error-title'
			title.textContent = t('markdownsite', 'Invalid diagram')
			const source = document.createElement('pre')
			const sourceCode = document.createElement('code')
			sourceCode.textContent = code.textContent
			source.appendChild(sourceCode)
			box.append(title, source)
		}
		pre.replaceWith(box)
	}
}

/** Test helper: forget the theme Mermaid was initialised with. */
export function resetMermaidForTests() {
	initialisedTheme = null
}
