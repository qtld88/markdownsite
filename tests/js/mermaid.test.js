// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import mermaid from 'mermaid'
import { renderDiagrams, resetMermaidForTests } from '../../src/services/mermaid.js'
import { loadChunk } from '../../src/services/chunks.js'

vi.mock('mermaid', () => ({
	default: { initialize: vi.fn(), render: vi.fn() },
}))
vi.mock('../../src/services/chunks.js', () => ({
	loadChunk: vi.fn(async (load) => load()),
}))

function article(html) {
	const el = document.createElement('article')
	el.innerHTML = html
	document.body.replaceChildren(el)
	return el
}

describe('renderDiagrams', () => {
	beforeEach(() => {
		loadChunk.mockClear()
		mermaid.initialize.mockClear()
		mermaid.render.mockReset()
		resetMermaidForTests()
	})

	it('loads nothing when the page has no diagram', async () => {
		const el = article('<pre><code class="language-bash">ls</code></pre>')
		await renderDiagrams(el)
		expect(loadChunk).not.toHaveBeenCalled()
	})

	it('replaces a diagram with its SVG, using strict security', async () => {
		mermaid.render.mockResolvedValue({ svg: '<svg data-test="diagram"></svg>' })
		const el = article('<pre><code class="language-mermaid">graph TD; A--&gt;B</code></pre>')
		await renderDiagrams(el)
		expect(mermaid.initialize).toHaveBeenCalledWith(expect.objectContaining({ startOnLoad: false, securityLevel: 'strict' }))
		expect(mermaid.render).toHaveBeenCalledWith(expect.any(String), 'graph TD; A-->B')
		expect(el.querySelector('.mds-mermaid svg[data-test="diagram"]')).not.toBeNull()
		expect(el.querySelector('pre')).toBeNull()
	})

	it('shows an error box with the source when the diagram is invalid', async () => {
		mermaid.render.mockRejectedValue(new Error('Parse error on line 1'))
		const el = article('<pre><code class="language-mermaid">graph ???</code></pre>')
		await renderDiagrams(el)
		const box = el.querySelector('.mds-mermaid-error')
		expect(box).not.toBeNull()
		expect(box.textContent).toContain('Invalid diagram')
		expect(box.querySelector('pre code').textContent).toBe('graph ???')
	})

	it('uses the dark theme when Nextcloud is dark', async () => {
		document.body.dataset.themes = 'dark'
		mermaid.render.mockResolvedValue({ svg: '<svg></svg>' })
		const el = article('<pre><code class="language-mermaid">graph TD; A</code></pre>')
		document.body.dataset.themes = 'dark'
		await renderDiagrams(el)
		expect(mermaid.initialize).toHaveBeenCalledWith(expect.objectContaining({ theme: 'dark' }))
		delete document.body.dataset.themes
	})
})
