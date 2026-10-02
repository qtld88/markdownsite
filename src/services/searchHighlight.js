import { normalize } from './searchText.js'

// Text inside these is never wrapped: code (highlighting replaces it), diagrams,
// buttons and existing marks.
const SKIP = 'pre, code, script, style, svg, button, mark.mds-search-hit'

/** [start, end) UTF-16 ranges of every term in `text`, sorted, overlaps merged. */
function ranges(text, terms) {
	const found = []
	for (const term of terms) {
		let at = text.indexOf(term)
		while (at !== -1) {
			found.push([at, at + term.length])
			at = text.indexOf(term, at + 1)
		}
	}
	found.sort((a, b) => a[0] - b[0] || b[1] - a[1])
	const merged = []
	for (const range of found) {
		const last = merged[merged.length - 1]
		if (last && range[0] < last[1]) {
			last[1] = Math.max(last[1], range[1])
		} else {
			merged.push(range)
		}
	}
	return merged
}

/**
 * Wraps every occurrence of `terms` (normalised, see parseQuery) in the text
 * of `root` with <mark class="mds-search-hit">. Matching ignores case and
 * accents. Returns the first mark, or null.
 */
export function highlightTerms(root, terms) {
	if (!root || !terms || !terms.length) {
		return null
	}
	const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
		acceptNode: node => (node.parentElement?.closest(SKIP) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
	})
	const nodes = []
	while (walker.nextNode()) {
		nodes.push(walker.currentNode)
	}
	for (const node of nodes) {
		const found = ranges(normalize(node.data), terms)
		// From the end, so earlier offsets stay valid while splitting.
		for (let i = found.length - 1; i >= 0; i--) {
			const [start, end] = found[i]
			const hit = node.splitText(start)
			hit.splitText(end - start)
			const mark = document.createElement('mark')
			mark.className = 'mds-search-hit'
			hit.replaceWith(mark)
			mark.appendChild(hit)
		}
	}
	return root.querySelector('mark.mds-search-hit')
}
