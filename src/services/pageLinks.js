const MAX_RESULTS = 50

/** Lowercase, accent-free text for matching. */
const fold = text => String(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()

/** How many pages carry each (lowercase) title. */
export function titleCounts(pages) {
	const counts = new Map()
	for (const page of pages) {
		const key = page.title.toLowerCase()
		counts.set(key, (counts.get(key) || 0) + 1)
	}
	return counts
}

/**
 * What goes between `[[` and `]]` to reach `page`: its title, or its path
 * without `.md` when another page shares that title.
 *
 * @param {object} page
 * @param {Map} counts from titleCounts()
 */
export function linkTarget(page, counts) {
	return counts.get(page.title.toLowerCase()) > 1 ? page.path.replace(/\.md$/i, '') : page.title
}

/** `[[target]]`, or `[[target|label]]` when the selected text differs from the target. */
export function buildWikilink(target, text) {
	const label = String(text).replace(/\s+/g, ' ').trim()
	return !label || label.toLowerCase() === target.toLowerCase() ? `[[${target}]]` : `[[${target}|${label}]]`
}

function score(page, query, hint) {
	const title = fold(page.title)
	if (query) {
		const haystack = [page.title, page.path, ...(page.aliases || [])].map(fold).join('\n')
		if (!fold(query).split(/\s+/).every(word => haystack.includes(word))) {
			return -1
		}
		return title.startsWith(fold(query)) ? 2 : title.includes(fold(query)) ? 1 : 0
	}
	// Nothing typed: pages that look like the selected text come first.
	const wanted = fold(hint).trim()
	return wanted && (title.includes(wanted) || wanted.includes(title)) ? 1 : 0
}

/**
 * Pages matching `query` (every word, in title, alias or path), best first.
 * With an empty query, pages resembling `hint` (the selected text) come first.
 */
export function filterPages(pages, query = '', hint = '') {
	return pages
		.map(page => ({ page, rank: score(page, query.trim(), hint) }))
		.filter(entry => entry.rank >= 0)
		.sort((a, b) => b.rank - a.rank || a.page.title.localeCompare(b.page.title))
		.slice(0, MAX_RESULTS)
		.map(entry => entry.page)
}
