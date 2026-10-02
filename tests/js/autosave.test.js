import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createAutosave, readDraft } from '../../src/editor/autosave.js'

function memoryStorage() {
	const data = new Map()
	return {
		getItem: k => (data.has(k) ? data.get(k) : null),
		setItem: (k, v) => data.set(k, String(v)),
		removeItem: k => data.delete(k),
	}
}

const ok = etag => ({ status: 200, etag })

describe('autosave', () => {
	let storage
	let states
	beforeEach(() => {
		vi.useFakeTimers()
		storage = memoryStorage()
		states = []
	})
	afterEach(() => vi.useRealTimers())

	function make(save, etag = 'e0') {
		return createAutosave({
			save, etag, storage, draftKey: 'k', onState: (s, info) => states.push([s, info?.reason ?? null]),
		})
	}

	it('saves 1.5 s after the last keystroke, once', async () => {
		const save = vi.fn().mockResolvedValue(ok('e1'))
		const auto = make(save)
		auto.change('a')
		await vi.advanceTimersByTimeAsync(1000)
		auto.change('ab')
		await vi.advanceTimersByTimeAsync(1499)
		expect(save).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		expect(save).toHaveBeenCalledTimes(1)
		expect(save).toHaveBeenCalledWith('ab', 'e0')
		expect(auto.state).toBe('saved')
		expect(auto.etag).toBe('e1')
		expect(states.map(s => s[0])).toEqual(['dirty', 'saving', 'saved'])
	})

	it('flush saves immediately and waits for the result', async () => {
		const save = vi.fn().mockResolvedValue(ok('e1'))
		const auto = make(save)
		auto.change('text')
		await auto.flush()
		expect(save).toHaveBeenCalledWith('text', 'e0')
		expect(auto.state).toBe('saved')
		await vi.advanceTimersByTimeAsync(5000)
		expect(save).toHaveBeenCalledTimes(1)
	})

	it('flush does nothing when nothing changed', async () => {
		const save = vi.fn()
		await make(save).flush()
		expect(save).not.toHaveBeenCalled()
	})

	it('saves again when typing continued during a save', async () => {
		let resolve
		const save = vi.fn()
			.mockImplementationOnce(() => new Promise(r => { resolve = r }))
			.mockResolvedValueOnce(ok('e2'))
		const auto = make(save)
		auto.change('one')
		await vi.advanceTimersByTimeAsync(1500)
		auto.change('one two')
		resolve(ok('e1'))
		await vi.advanceTimersByTimeAsync(1500)
		expect(save).toHaveBeenLastCalledWith('one two', 'e1')
		expect(auto.state).toBe('saved')
	})

	it('pauses on a conflict until the user chooses', async () => {
		const save = vi.fn()
			.mockResolvedValueOnce({ status: 409, etag: 'e9', content: 'theirs' })
			.mockResolvedValueOnce(ok('e10'))
		const auto = make(save)
		auto.change('mine')
		await vi.advanceTimersByTimeAsync(1500)
		expect(auto.state).toBe('conflict')
		expect(auto.conflict).toEqual({ etag: 'e9', content: 'theirs' })
		auto.change('mine 2')
		await vi.advanceTimersByTimeAsync(5000)
		expect(save).toHaveBeenCalledTimes(1)
		await auto.keepMine()
		expect(save).toHaveBeenLastCalledWith('mine 2', 'e9')
		expect(auto.state).toBe('saved')
	})

	it('reload takes the server text and etag', async () => {
		const save = vi.fn().mockResolvedValueOnce({ status: 409, etag: 'e9', content: 'theirs' })
		const auto = make(save)
		auto.change('mine')
		await vi.advanceTimersByTimeAsync(1500)
		expect(auto.reload()).toBe('theirs')
		expect(auto.etag).toBe('e9')
		expect(auto.state).toBe('saved')
		expect(readDraft(storage, 'k')).toBeNull()
	})

	it('retries a network failure 3 times (2 s, 5 s, 15 s), then stays in error', async () => {
		const save = vi.fn().mockRejectedValue(new Error('offline'))
		const auto = make(save)
		auto.change('text')
		await vi.advanceTimersByTimeAsync(1500)
		expect(auto.state).toBe('error')
		await vi.advanceTimersByTimeAsync(2000)
		expect(save).toHaveBeenCalledTimes(2)
		await vi.advanceTimersByTimeAsync(5000)
		expect(save).toHaveBeenCalledTimes(3)
		await vi.advanceTimersByTimeAsync(15000)
		expect(save).toHaveBeenCalledTimes(4)
		await vi.advanceTimersByTimeAsync(60000)
		expect(save).toHaveBeenCalledTimes(4)
		expect(auto.state).toBe('error')
		expect(states.at(-1)).toEqual(['error', 'network'])
	})

	it('recovers when a retry succeeds', async () => {
		const save = vi.fn().mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(ok('e1'))
		const auto = make(save)
		auto.change('text')
		await vi.advanceTimersByTimeAsync(1500 + 2000)
		expect(auto.state).toBe('saved')
	})

	it('reports lost rights and deleted pages without retrying', async () => {
		const forbidden = make(vi.fn().mockResolvedValue({ status: 403 }))
		forbidden.change('x')
		await forbidden.flush()
		expect([forbidden.state, states.at(-1)[1]]).toEqual(['error', 'forbidden'])

		const save = vi.fn().mockResolvedValue({ status: 404 })
		const deleted = make(save)
		deleted.change('x')
		await deleted.flush()
		expect(states.at(-1)).toEqual(['error', 'deleted'])
		await vi.advanceTimersByTimeAsync(60000)
		expect(save).toHaveBeenCalledTimes(1)
	})

	it('mirrors unsaved text to storage and clears it once saved', async () => {
		const auto = make(vi.fn().mockResolvedValue(ok('e1')))
		auto.change('draft text')
		expect(readDraft(storage, 'k')).toEqual({ text: 'draft text', etag: 'e0' })
		await auto.flush()
		expect(readDraft(storage, 'k')).toBeNull()
	})

	it('knows whether leaving would lose text', async () => {
		const auto = make(vi.fn().mockResolvedValue(ok('e1')))
		expect(auto.hasUnsaved()).toBe(false)
		auto.change('x')
		expect(auto.hasUnsaved()).toBe(true)
		await auto.flush()
		expect(auto.hasUnsaved()).toBe(false)
	})

	it('resumes after the page was recreated', async () => {
		const save = vi.fn().mockResolvedValueOnce({ status: 404 }).mockResolvedValueOnce(ok('e2'))
		const auto = make(save)
		auto.change('my text')
		await auto.flush()
		expect(auto.state).toBe('error')
		await auto.resume('e1')
		expect(save).toHaveBeenLastCalledWith('my text', 'e1')
		expect(auto.state).toBe('saved')
	})
})
