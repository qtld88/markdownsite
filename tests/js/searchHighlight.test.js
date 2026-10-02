// @vitest-environment jsdom
import { describe, expect, it } from 'vitest'
import { highlightTerms } from '../../src/services/searchHighlight.js'

function article(html) {
	const el = document.createElement('article')
	el.innerHTML = html
	document.body.replaceChildren(el)
	return el
}

describe('highlightTerms', () => {
	it('wraps matches in text nodes, ignoring case and accents', () => {
		const el = article('<p>Le café et le CAFÉ crème.</p>')
		const first = highlightTerms(el, ['cafe'])
		const marks = [...el.querySelectorAll('mark.mds-search-hit')]
		expect(marks.map(m => m.textContent)).toEqual(['café', 'CAFÉ'])
		expect(first).toBe(marks[0])
		expect(el.textContent).toBe('Le café et le CAFÉ crème.')
	})

	it('wraps link text but never touches attributes', () => {
		const el = article('<p><a href="/apps/x/vault.md" title="vault">Vault page</a></p>')
		highlightTerms(el, ['vault'])
		const a = el.querySelector('a')
		expect(a.getAttribute('href')).toBe('/apps/x/vault.md')
		expect(a.getAttribute('title')).toBe('vault')
		expect(a.querySelector('mark').textContent).toBe('Vault')
	})

	it('skips code blocks and inline code', () => {
		const el = article('<p>vault <code>vault</code></p><pre><code class="language-bash">vault</code></pre>')
		highlightTerms(el, ['vault'])
		expect(el.querySelectorAll('mark').length).toBe(1)
		expect(el.querySelector('pre mark')).toBeNull()
	})

	it('wraps several terms and phrases, merging overlaps', () => {
		const el = article('<p>mot de passe oublié</p>')
		highlightTerms(el, ['mot de passe', 'passe', 'oublie'])
		expect([...el.querySelectorAll('mark')].map(m => m.textContent)).toEqual(['mot de passe', 'oublié'])
	})

	it('returns null when nothing matches', () => {
		expect(highlightTerms(article('<p>nothing</p>'), ['vault'])).toBeNull()
		expect(highlightTerms(article('<p>x</p>'), [])).toBeNull()
	})
})
