/**
 * Reading order of a site: pages in depth-first tree order. A folder with a
 * folder note contributes that note at the folder's position, before its
 * children.
 *
 * @param {Array} tree nodes from GET /s/{siteId}/tree
 * @return {Array<{path: string, title: string}>}
 */
export function flatten(tree) {
	const out = []
	for (const node of tree || []) {
		if (node.type === 'dir') {
			if (node.note) {
				out.push({ path: node.note, title: node.name })
			}
			out.push(...flatten(node.children))
		} else {
			out.push({ path: node.path, title: node.name })
		}
	}
	return out
}

/**
 * Pages before and after `path` in `order`.
 *
 * @param {Array<{path: string, title: string}>} order result of flatten()
 * @param {string} path current page path
 * @return {{prev: object|null, next: object|null}}
 */
export function neighbours(order, path) {
	const i = order.findIndex(p => p.path === path)
	if (i === -1) {
		return { prev: null, next: null }
	}
	return {
		prev: i > 0 ? order[i - 1] : null,
		next: i < order.length - 1 ? order[i + 1] : null,
	}
}
