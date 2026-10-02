import { describe, expect, it } from 'vitest'
import { flatten, neighbours } from '../../src/services/pageOrder.js'

const tree = [
	{
		name: 'Guides', path: 'Guides', type: 'dir', note: 'Guides/Guides.md',
		children: [
			{ name: 'Deep', path: 'Guides/Deep', type: 'dir', children: [
				{ name: 'Inner', path: 'Guides/Deep/Inner.md', type: 'page' },
			] },
			{ name: 'Alpha', path: 'Guides/Alpha.md', type: 'page' },
		],
	},
	{ name: 'Empty', path: 'Empty', type: 'dir', children: [] },
	{ name: 'Home', path: 'Home.md', type: 'page' },
]

describe('flatten', () => {
	it('lists pages depth-first, folder notes before their children', () => {
		expect(flatten(tree)).toEqual([
			{ path: 'Guides/Guides.md', title: 'Guides' },
			{ path: 'Guides/Deep/Inner.md', title: 'Inner' },
			{ path: 'Guides/Alpha.md', title: 'Alpha' },
			{ path: 'Home.md', title: 'Home' },
		])
	})

	it('skips empty folders and handles an empty tree', () => {
		expect(flatten([{ name: 'E', path: 'E', type: 'dir', children: [] }])).toEqual([])
		expect(flatten([])).toEqual([])
	})
})

describe('neighbours', () => {
	const order = flatten(tree)

	it('has no previous page for the first page', () => {
		expect(neighbours(order, 'Guides/Guides.md')).toEqual({
			prev: null,
			next: { path: 'Guides/Deep/Inner.md', title: 'Inner' },
		})
	})

	it('has no next page for the last page', () => {
		expect(neighbours(order, 'Home.md')).toEqual({
			prev: { path: 'Guides/Alpha.md', title: 'Alpha' },
			next: null,
		})
	})

	it('returns both sides for a middle page', () => {
		expect(neighbours(order, 'Guides/Deep/Inner.md')).toEqual({
			prev: { path: 'Guides/Guides.md', title: 'Guides' },
			next: { path: 'Guides/Alpha.md', title: 'Alpha' },
		})
	})

	it('returns nothing for an unknown path', () => {
		expect(neighbours(order, 'Nope.md')).toEqual({ prev: null, next: null })
	})
})
