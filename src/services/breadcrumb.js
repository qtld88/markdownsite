/**
 * Breadcrumb segments for `path`, site segment excluded: one per folder, then
 * the page. When the page is its folder's note, the folder stands for it and
 * no separate page segment is added.
 *
 * @param {Array} tree nodes from GET /s/{siteId}/tree
 * @param {string} path current page path
 * @return {Array<{name: string, path: string, current: boolean, note: string|null}>}
 */
export function breadcrumbSegments(tree, path) {
	const parts = path.split('/')
	const segments = []
	let nodes = tree || []
	let acc = ''
	for (const part of parts.slice(0, -1)) {
		acc = acc ? `${acc}/${part}` : part
		const node = nodes.find(n => n.type === 'dir' && n.path === acc)
		segments.push({ name: part, path: acc, current: false, note: node?.note || null })
		nodes = node?.children || []
	}
	const last = segments[segments.length - 1]
	if (last && last.note === path) {
		last.current = true
	} else {
		segments.push({ name: parts[parts.length - 1].replace(/\.md$/i, ''), path, current: true, note: null })
	}
	return segments
}
