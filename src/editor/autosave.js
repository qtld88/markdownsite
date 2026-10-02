/**
 * Autosave state machine for one page: idle → dirty → saving → saved, plus
 * error (network, forbidden, deleted) and conflict. Independent of
 * CodeMirror and of the network layer so it can be tested on its own.
 */

export const DEBOUNCE_MS = 1500
export const RETRY_DELAYS_MS = [2000, 5000, 15000]

/** The draft mirrored in sessionStorage, or null. */
export function readDraft(storage, key) {
	try {
		const raw = storage?.getItem(key)
		return raw ? JSON.parse(raw) : null
	} catch (e) {
		return null
	}
}

/**
 * @param {object} options
 * @param {Function} options.save (text, etag) => Promise<{status, etag?, content?}>; rejects on network failure
 * @param {string} options.etag etag of the text the editor started from
 * @param {Storage} [options.storage] where unsaved text is mirrored (sessionStorage)
 * @param {string} [options.draftKey] storage key, e.g. markdownsite:draft:{siteId}:{path}
 * @param {Function} [options.onState] (state, {reason}) on every state change
 */
export function createAutosave({ save, etag, storage = null, draftKey = '', onState = () => {} }) {
	const api = {
		state: 'idle',
		etag,
		conflict: null,
		text: null,
		change,
		flush,
		keepMine,
		reload,
		resume,
		hasUnsaved,
		dispose,
	}
	let timer = null
	let retries = 0
	let inFlight = null
	let pendingText = null // text not yet confirmed by the server

	function setState(state, info = {}) {
		if (state === api.state && state === 'dirty') {
			return // typing keeps the editor dirty; report it once
		}
		api.state = state
		onState(state, info)
	}

	function mirror() {
		if (!storage || !draftKey) {
			return
		}
		try {
			if (pendingText === null) {
				storage.removeItem(draftKey)
			} else {
				storage.setItem(draftKey, JSON.stringify({ text: pendingText, etag: api.etag }))
			}
		} catch (e) {
			// storage full or disabled: the editor still works
		}
	}

	function schedule(delay) {
		clearTimeout(timer)
		timer = setTimeout(() => { run() }, delay)
	}

	function change(text) {
		api.text = text
		pendingText = text
		mirror()
		if (api.state === 'conflict' || (api.state === 'error' && api.reason !== 'network')) {
			return // paused: the user has to decide first
		}
		retries = 0
		setState('dirty')
		schedule(DEBOUNCE_MS)
	}

	async function run() {
		clearTimeout(timer)
		if (inFlight) {
			await inFlight
		}
		if (pendingText === null || api.state === 'conflict') {
			return
		}
		const text = pendingText
		setState('saving')
		inFlight = (async () => {
			let result
			try {
				result = await save(text, api.etag)
			} catch (e) {
				api.reason = 'network'
				if (retries < RETRY_DELAYS_MS.length) {
					schedule(RETRY_DELAYS_MS[retries++])
				}
				setState('error', { reason: 'network' })
				return
			}
			if (result.status === 409) {
				api.conflict = { etag: result.etag, content: result.content }
				setState('conflict')
				return
			}
			if (result.status === 403 || result.status === 404) {
				api.reason = result.status === 403 ? 'forbidden' : 'deleted'
				setState('error', { reason: api.reason })
				return
			}
			api.etag = result.etag
			api.reason = null
			retries = 0
			if (pendingText === text) {
				pendingText = null
				mirror()
				setState('saved')
			} else {
				mirror()
				setState('dirty')
				schedule(DEBOUNCE_MS)
			}
		})()
		await inFlight
		inFlight = null
	}

	/** Saves now (page change, leaving edit mode, Ctrl+click). */
	async function flush() {
		clearTimeout(timer)
		if (inFlight) {
			await inFlight
		}
		if (pendingText !== null && api.state !== 'conflict') {
			await run()
		}
	}

	/** Conflict: overwrite the server's version with the local text. */
	async function keepMine() {
		if (!api.conflict) {
			return
		}
		api.etag = api.conflict.etag
		api.conflict = null
		setState('dirty')
		await run()
	}

	/** Conflict: drop the local text; returns the server's text to show. */
	function reload() {
		const server = api.conflict
		if (!server) {
			return null
		}
		api.etag = server.etag
		api.conflict = null
		pendingText = null
		api.text = server.content
		mirror()
		setState('saved')
		return server.content
	}

	/** After an error (page recreated, rights restored): save again with this etag. */
	async function resume(newEtag) {
		api.etag = newEtag
		api.reason = null
		api.conflict = null
		retries = 0
		setState('dirty')
		await run()
	}

	function hasUnsaved() {
		return pendingText !== null
	}

	function dispose() {
		clearTimeout(timer)
	}

	return api
}
