/**
 * Runs a dynamic import. If the chunk cannot be loaded (ChunkLoadError,
 * network error) it logs a warning and returns null, so the page keeps its
 * plain rendering instead of breaking.
 *
 * @param {Function} load e.g. () => import('highlight.js/lib/core')
 * @param {string} what name used in the warning
 * @return {Promise<object|null>} the module, or null
 */
export async function loadChunk(load, what) {
	try {
		return await load()
	} catch (error) {
		console.warn(`[markdownsite] could not load ${what}; showing plain content`, error)
		return null
	}
}
