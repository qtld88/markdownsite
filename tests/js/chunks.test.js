import { describe, expect, it, vi } from 'vitest'
import { loadChunk } from '../../src/services/chunks.js'

describe('loadChunk', () => {
	it('returns the loaded module', async () => {
		expect(await loadChunk(async () => ({ default: 42 }), 'x')).toEqual({ default: 42 })
	})

	it('returns null and warns when the chunk fails', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const error = Object.assign(new Error('Loading chunk 7 failed.'), { name: 'ChunkLoadError' })
		expect(await loadChunk(async () => { throw error }, 'Mermaid')).toBeNull()
		expect(warn).toHaveBeenCalledWith(expect.stringContaining('Mermaid'), error)
		warn.mockRestore()
	})
})
