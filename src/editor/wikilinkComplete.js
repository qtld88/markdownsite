import { linkTarget, titleCounts } from '../services/pageLinks.js'

/**
 * `[[` completion over the site's pages and aliases.
 *
 * @param {Function} getPages () => [{path, title, aliases}] (from GET /tree)
 */
export function wikilinkCompletion(getPages) {
	return (context) => {
		const match = context.matchBefore(/!?\[\[[^\]|#\n]*$/)
		if (!match) {
			return null
		}
		const from = match.from + match.text.indexOf('[[') + 2
		const pages = getPages() || []
		const counts = titleCounts(pages)
		const options = []
		for (const page of pages) {
			const folder = page.path.includes('/') ? page.path.slice(0, page.path.lastIndexOf('/')) : ''
			const target = linkTarget(page, counts)
			options.push({ label: page.title, detail: folder, apply: applyLink(target), type: 'text' })
			for (const alias of page.aliases || []) {
				options.push({ label: alias, detail: `→ ${page.title}`, apply: applyLink(alias), type: 'text' })
			}
		}
		return { from, options, validFor: /^[^\]|#\n]*$/ }
	}
}

/** Inserts the target and closing brackets (unless they are already there). */
export function applyLink(target) {
	return (view, completion, from, to) => {
		const closed = view.state.doc.sliceString(to, to + 2) === ']]'
		const insert = closed ? target : target + ']]'
		view.dispatch({
			changes: { from, to, insert },
			selection: { anchor: from + target.length + 2 },
		})
	}
}
