import { describe, expect, it } from 'vitest'
import { tocItems } from '../../src/services/toc.js'

const h = (level, id) => ({ level, text: id.toUpperCase(), id })

describe('tocItems', () => {
	it('renders nothing below three headings', () => {
		expect(tocItems([h(2, 'a'), h(2, 'b')])).toEqual([])
		expect(tocItems([])).toEqual([])
		expect(tocItems(undefined)).toEqual([])
	})

	it('shows h2-h4 when the page has exactly one h1', () => {
		const items = tocItems([h(1, 'title'), h(2, 'a'), h(3, 'b'), h(4, 'c'), h(5, 'd')])
		expect(items.map(i => [i.id, i.depth])).toEqual([['a', 0], ['b', 1], ['c', 2]])
	})

	it('shows h1-h4 otherwise', () => {
		const items = tocItems([h(1, 'one'), h(2, 'a'), h(1, 'two'), h(4, 'c'), h(6, 'z')])
		expect(items.map(i => [i.id, i.depth])).toEqual([['one', 0], ['a', 1], ['two', 0], ['c', 3]])
		const noH1 = tocItems([h(2, 'a'), h(2, 'b'), h(3, 'c')])
		expect(noH1.map(i => [i.id, i.depth])).toEqual([['a', 1], ['b', 1], ['c', 2]])
	})
})
