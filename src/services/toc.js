/** Prefix of heading ids in rendered pages (MarkdownRenderer::HEADING_ID_PREFIX). */
export const HEADING_ID_PREFIX = 'mds'

/** A page needs at least this many headings to get a table of contents. */
export const TOC_MIN_HEADINGS = 3

/**
 * Headings to show in the table of contents. h2-h4 when the page has exactly
 * one h1 (the page title), h1-h4 otherwise. `depth` is the indentation level,
 * 0 for the outermost level shown.
 *
 * @param {Array<{level: number, text: string, id: string}>} toc from GET /page
 * @return {Array<{level: number, text: string, id: string, depth: number}>}
 */
export function tocItems(toc) {
	if (!Array.isArray(toc) || toc.length < TOC_MIN_HEADINGS) {
		return []
	}
	const min = toc.filter(h => h.level === 1).length === 1 ? 2 : 1
	return toc
		.filter(h => h.level >= min && h.level <= 4)
		.map(h => ({ ...h, depth: h.level - min }))
}
