import { describe, expect, it } from 'vitest'
import { safeDecode, splitHash } from '../../src/services/anchors.js'

describe('splitHash', () => {
	it('splits a path and its fragment', () => {
		expect(splitHash('Guides/Guide.md#first-step')).toEqual(['Guides/Guide.md', 'first-step'])
	})

	it('returns an empty fragment when there is none', () => {
		expect(splitHash('Guides/Guide.md')).toEqual(['Guides/Guide.md', ''])
	})
})

describe('safeDecode', () => {
	it('decodes percent-encoded text', () => {
		expect(safeDecode('caf%C3%A9-cr%C3%A8me')).toBe('café-crème')
	})

	it('returns malformed input unchanged', () => {
		expect(safeDecode('100%')).toBe('100%')
	})
})
