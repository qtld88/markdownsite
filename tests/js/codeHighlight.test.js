// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { decorateCode, resolveLanguage } from '../../src/services/codeHighlight.js'
import { loadChunk } from '../../src/services/chunks.js'

vi.mock('../../src/services/chunks.js', () => ({
	loadChunk: vi.fn(async (load) => load()),
}))

function article(html) {
	const el = document.createElement('article')
	el.innerHTML = html
	document.body.replaceChildren(el)
	return el
}

describe('decorateCode', () => {
	beforeEach(() => {
		loadChunk.mockClear()
	})

	it('loads nothing when the page has no code block', async () => {
		const el = article('<p>Just text with <code>inline code</code>.</p>')
		await decorateCode(el)
		expect(loadChunk).not.toHaveBeenCalled()
		expect(el.querySelector('.mds-code')).toBeNull()
	})

	it('highlights a known language and shows its label', async () => {
		const el = article('<pre><code class="language-bash">echo "hi" # greet\n</code></pre>')
		await decorateCode(el)
		const box = el.querySelector('.mds-code')
		expect(box).not.toBeNull()
		expect(box.querySelector('.mds-code-lang').textContent).toBe('bash')
		expect(box.querySelector('code').classList.contains('hljs')).toBe(true)
		expect(box.querySelector('code .hljs-string')).not.toBeNull()
		expect(box.querySelector('code').textContent).toBe('echo "hi" # greet\n')
	})

	it('resolves aliases', async () => {
		expect(resolveLanguage('sh')).toBe('bash')
		expect(resolveLanguage('yml')).toBe('yaml')
		expect(resolveLanguage('py')).toBe('python')
		expect(resolveLanguage('constructor')).toBeNull()
	})

	it('leaves an unknown language plain but labelled, without loading highlight.js', async () => {
		const el = article('<pre><code class="language-dataview">TABLE file.name</code></pre>')
		await decorateCode(el)
		expect(el.querySelector('.mds-code-lang').textContent).toBe('dataview')
		expect(el.querySelector('code').innerHTML).toBe('TABLE file.name')
		expect(loadChunk).not.toHaveBeenCalled()
	})

	it('skips Mermaid blocks', async () => {
		const el = article('<pre><code class="language-mermaid">graph TD; A--&gt;B</code></pre>')
		await decorateCode(el)
		expect(el.querySelector('.mds-code')).toBeNull()
		expect(loadChunk).not.toHaveBeenCalled()
	})

	it('copies the raw code text', async () => {
		const writeText = vi.fn().mockResolvedValue()
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const el = article('<pre><code class="language-python">print("a &lt; b")\n</code></pre>')
		await decorateCode(el)
		el.querySelector('.mds-code-copy').click()
		await vi.waitFor(() => expect(writeText).toHaveBeenCalledWith('print("a < b")\n'))
		await vi.waitFor(() => expect(el.querySelector('.mds-code-copy').classList.contains('is-done')).toBe(true))
	})

	it('keeps the label and plain text when highlight.js fails to load', async () => {
		loadChunk.mockResolvedValueOnce(null)
		const el = article('<pre><code class="language-yaml">a: 1</code></pre>')
		await decorateCode(el)
		expect(el.querySelector('.mds-code-lang').textContent).toBe('yaml')
		expect(el.querySelector('code').innerHTML).toBe('a: 1')
	})
})
