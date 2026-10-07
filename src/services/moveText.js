const parentOf = path => (path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '')
const nameOf = path => path.split('/').pop().replace(/\.md$/i, '')

/**
 * What a move is called in the confirmation dialog. Staying in the same
 * folder is a rename (`target` = the new name); otherwise it is a move
 * (`target` = the destination folder, '' for the top level).
 */
export function describeMove(from, to) {
	const rename = parentOf(from) === parentOf(to)
	return { rename, name: nameOf(from), target: rename ? nameOf(to) : parentOf(to) }
}
