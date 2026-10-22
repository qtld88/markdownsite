import { describe, expect, it } from 'vitest'
import { EditorState } from '@codemirror/state'
import { buildWikilink, filterPages, linkTarget, titleCounts } from '../../src/services/pageLinks.js'
import { linkChange } from '../../src/editor/linkPicker.js'

const pages = [
	{ path: 'Guide/Setup.md', title: 'Setup', aliases: ['Install'] },
	{ path: 'Ops/Setup.md', title: 'Setup', aliases: [] },
	{ path: 'Café.md', title: 'Café', aliases: [] },
	{ path: 'Notes/Backup plan.md', title: 'Backup plan', aliases: [] },
]

describe('linkTarget', () => {
	it('uses the title, or the path when the title is shared', () => {
		const counts = titleCounts(pages)
		expect(linkTarget(pages[2], counts)).toBe('Café')
		expect(linkTarget(pages[0], counts)).toBe('Guide/Setup')
	})
})

describe('buildWikilink', () => {
	it('adds a label only when it differs from the target', () => {
		expect(buildWikilink('Setup', 'the install guide')).toBe('[[Setup|the install guide]]')
		expect(buildWikilink('Setup', 'setup')).toBe('[[Setup]]')
		expect(buildWikilink('Setup', '')).toBe('[[Setup]]')
		expect(buildWikilink('Setup', 'a\n b')).toBe('[[Setup|a b]]')
	})
})

describe('filterPages', () => {
	it('matches title, alias and path without case or accents', () => {
		expect(filterPages(pages, 'cafe').map(p => p.path)).toEqual(['Café.md'])
		expect(filterPages(pages, 'install').map(p => p.path)).toEqual(['Guide/Setup.md'])
		expect(filterPages(pages, 'ops setup').map(p => p.path)).toEqual(['Ops/Setup.md'])
	})

	it('puts title prefixes first', () => {
		expect(filterPages(pages, 'plan')[0].title).toBe('Backup plan')
		expect(filterPages(pages, 'b').map(p => p.title)).toEqual(['Backup plan'])
	})

	it('shows pages resembling the selection first when nothing is typed', () => {
		expect(filterPages(pages, '', 'the café').map(p => p.title)[0]).toBe('Café')
		expect(filterPages(pages, '').length).toBe(4)
	})
})

describe('linkChange', () => {
	const apply = (doc, from, to, target) => {
		const state = EditorState.create({ doc })
		const tr = state.update(linkChange(state, { from, to }, target))
		return [tr.state.doc.toString(), tr.state.selection.main.head]
	}

	it('wraps the selection and keeps surrounding spaces outside', () => {
		expect(apply('see the guide now', 4, 13, 'Setup')).toEqual(['see [[Setup|the guide]] now', 23])
		expect(apply('see  guide ', 3, 11, 'Setup')).toEqual(['see  [[Setup|guide]] ', 20])
	})

	it('inserts a bare link at the cursor', () => {
		expect(apply('ab', 1, 1, 'Setup')).toEqual(['a[[Setup]]b', 10])
	})
})
