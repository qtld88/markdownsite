import { describe, expect, it } from 'vitest'
import { describeMove } from '../../src/services/moveText.js'

describe('describeMove', () => {
	it('calls a change of name in the same folder a rename', () => {
		expect(describeMove('Guides/Old.md', 'Guides/New.md')).toEqual({ rename: true, name: 'Old', target: 'New' })
		expect(describeMove('Projects', 'Work')).toEqual({ rename: true, name: 'Projects', target: 'Work' })
	})

	it('calls a change of folder a move to the destination folder', () => {
		expect(describeMove('Guides/Old.md', 'Archive/2026/Old.md')).toEqual({ rename: false, name: 'Old', target: 'Archive/2026' })
		expect(describeMove('Guides/Old.md', 'Old.md')).toEqual({ rename: false, name: 'Old', target: '' })
	})
})
