// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
vi.mock('../../src/components/MoveDialog.vue', () => ({ default: {} }))
vi.mock('../../src/components/NameDialog.vue', () => ({ default: {} }))

const { canDrop, countPages, join, movedPath, parentOf } = await import('../../src/services/fileOps.js')

describe('fileOps path helpers', () => {
	it('joins and splits paths', () => {
		expect(join('', 'A.md')).toBe('A.md')
		expect(join('Guides', 'A.md')).toBe('Guides/A.md')
		expect(parentOf('Guides/Deep/A.md')).toBe('Guides/Deep')
		expect(parentOf('A.md')).toBe('')
	})

	it('follows a moved page or folder', () => {
		expect(movedPath('Old.md', 'Old.md', 'New.md')).toBe('New.md')
		expect(movedPath('Projects/Alpha.md', 'Projects', 'Work/Projects')).toBe('Work/Projects/Alpha.md')
		expect(movedPath('Projects2/A.md', 'Projects', 'Work')).toBe('Projects2/A.md')
	})

	it('refuses drops into itself, a descendant or the current folder', () => {
		expect(canDrop('Projects', 'Projects')).toBe(false)
		expect(canDrop('Projects', 'Projects/Sub')).toBe(false)
		expect(canDrop('Projects/A.md', 'Projects')).toBe(false)
		expect(canDrop('A.md', '')).toBe(false)
		expect(canDrop('Projects/A.md', '')).toBe(true)
		expect(canDrop('Projects', 'Archive')).toBe(true)
	})

	it('counts the pages a folder deletion takes with it', () => {
		const tree = { type: 'dir', note: 'X/X.md', children: [
			{ type: 'page' }, { type: 'dir', children: [{ type: 'page' }, { type: 'page' }] },
		] }
		expect(countPages(tree)).toBe(4)
	})
})
