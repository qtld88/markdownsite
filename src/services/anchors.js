import { HEADING_ID_PREFIX } from './toc.js'

/** decodeURIComponent that returns its input when it is not valid encoding. */
export function safeDecode(text) {
	try {
		return decodeURIComponent(text)
	} catch (e) {
		return text
	}
}

/** Splits `path#fragment` into `[path, fragment]`. */
export function splitHash(href) {
	const i = href.indexOf('#')
	return i === -1 ? [href, ''] : [href.slice(0, i), href.slice(i + 1)]
}

/**
 * The heading inside `root` that a link fragment points at. The server gives
 * headings the id `mds-<slug>` (see MarkdownRenderer::HEADING_ID_PREFIX) while
 * links carry the bare slug; ids written by hand in the Markdown also match.
 */
export function headingElement(root, id) {
	if (!root || !id) {
		return null
	}
	for (const candidate of [`${HEADING_ID_PREFIX}-${id}`, id]) {
		const el = document.getElementById(candidate)
		if (el && root.contains(el)) {
			return el
		}
	}
	return null
}
