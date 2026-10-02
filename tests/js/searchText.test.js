import { describe, expect, it } from 'vitest'
import { normalize, parseQuery, snippetParts } from '../../src/services/searchText.js'

describe('normalize', () => {
	it('matches the server: lower case, no accents, same length', () => {
		expect(normalize('Été à Paris, Straße, Œuvre, Łódź')).toBe('ete a paris, strase, ouvre, lodz')
		for (const text of ['İstanbul', 'naïve café 😀 日本', 'ĳ ŉ ſ']) {
			expect(normalize(text).length).toBe(text.length)
		}
	})
})

describe('parseQuery', () => {
	it('splits words, keeps quoted phrases, drops short and repeated terms', () => {
		expect(parseQuery('  Café "Mot  de passe" a x café')).toEqual(['cafe', 'mot de passe'])
		expect(parseQuery('nextcloud "talk app')).toEqual(['nextcloud', 'talk app'])
		expect(parseQuery('')).toEqual([])
	})
})

describe('snippetParts', () => {
	it('splits a snippet on highlight offsets', () => {
		expect(snippetParts('…the Café crème…', [[5, 4], [10, 5]])).toEqual([
			{ text: '…the ', hit: false },
			{ text: 'Café', hit: true },
			{ text: ' ', hit: false },
			{ text: 'crème', hit: true },
			{ text: '…', hit: false },
		])
	})

	it('counts offsets in code points, not UTF-16 units', () => {
		expect(snippetParts('😀 vault', [[2, 5]])).toEqual([
			{ text: '😀 ', hit: false },
			{ text: 'vault', hit: true },
		])
	})

	it('returns the whole text without highlights', () => {
		expect(snippetParts('plain', [])).toEqual([{ text: 'plain', hit: false }])
	})
})
