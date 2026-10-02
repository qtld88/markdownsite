import { describe, expect, it } from 'vitest'
import { breadcrumbSegments } from '../../src/services/breadcrumb.js'

const tree = [
	{
		name: 'Guides', path: 'Guides', type: 'dir', note: 'Guides/Guides.md',
		children: [
			{ name: 'Deep', path: 'Guides/Deep', type: 'dir', children: [
				{ name: 'Inner', path: 'Guides/Deep/Inner.md', type: 'page' },
			] },
		],
	},
	{ name: 'Home', path: 'Home.md', type: 'page' },
]

describe('breadcrumbSegments', () => {
	it('returns only the page at the root', () => {
		expect(breadcrumbSegments(tree, 'Home.md')).toEqual([
			{ name: 'Home', path: 'Home.md', current: true, note: null },
		])
	})

	it('lists each folder with its note, then the page', () => {
		expect(breadcrumbSegments(tree, 'Guides/Deep/Inner.md')).toEqual([
			{ name: 'Guides', path: 'Guides', current: false, note: 'Guides/Guides.md' },
			{ name: 'Deep', path: 'Guides/Deep', current: false, note: null },
			{ name: 'Inner', path: 'Guides/Deep/Inner.md', current: true, note: null },
		])
	})

	it('shows an open folder note as its folder', () => {
		expect(breadcrumbSegments(tree, 'Guides/Guides.md')).toEqual([
			{ name: 'Guides', path: 'Guides', current: true, note: 'Guides/Guides.md' },
		])
	})

	it('copes with folders missing from the tree', () => {
		expect(breadcrumbSegments([], 'A/B.md')).toEqual([
			{ name: 'A', path: 'A', current: false, note: null },
			{ name: 'B', path: 'A/B.md', current: true, note: null },
		])
	})
})
