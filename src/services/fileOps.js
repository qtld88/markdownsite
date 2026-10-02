import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { createFolder, createPage, deleteNode, getBacklinks, moveNode } from './api.js'
import MoveDialog from '../components/MoveDialog.vue'
import NameDialog from '../components/NameDialog.vue'

/** `dir/name`, or `name` at the root. */
export const join = (dir, name) => (dir ? `${dir}/${name}` : name)

/** Parent folder of a path ('' at the root). */
export const parentOf = path => (path.includes('/') ? path.slice(0, path.lastIndexOf('/')) : '')

/** Where `path` ends up after `from` moved to `to` (unchanged when unrelated). */
export function movedPath(path, from, to) {
	if (path === from) {
		return to
	}
	return path.startsWith(from + '/') ? to + path.slice(from.length) : path
}

/** A drop of `source` into folder `target` ('' = root) that would do something. */
export function canDrop(source, target) {
	return !!source && source !== target && !target.startsWith(source + '/') && parentOf(source) !== target
}

async function toast(kind, text) {
	const dialogs = await import('@nextcloud/dialogs')
	dialogs[kind](text)
}

async function reportError(error) {
	const status = error?.response?.status
	if (status === 409) {
		await toast('showError', t('markdownsite', 'Already exists'))
	} else if (status === 400 && error.response.data?.error === 'invalid-name') {
		await toast('showError', t('markdownsite', 'This name is not allowed'))
	} else {
		await toast('showError', error?.response?.data?.message || t('markdownsite', 'The operation failed'))
	}
}

const askName = props => spawnDialog(NameDialog, props)

/** New page in `dir`: asks a name, creates `Name.md`. Returns its path, or null. */
export async function newPage(siteId, dir) {
	const name = await askName({
		title: t('markdownsite', 'New page'),
		label: t('markdownsite', 'Page name'),
		confirmLabel: t('markdownsite', 'Create'),
	})
	if (!name) {
		return null
	}
	try {
		return (await createPage(siteId, join(dir, name))).path
	} catch (e) {
		await reportError(e)
		return null
	}
}

/** New folder in `dir`. Returns its path, or null. */
export async function newFolder(siteId, dir) {
	const name = await askName({
		title: t('markdownsite', 'New folder'),
		label: t('markdownsite', 'Folder name'),
		confirmLabel: t('markdownsite', 'Create'),
	})
	if (!name) {
		return null
	}
	try {
		return (await createFolder(siteId, join(dir, name))).path
	} catch (e) {
		await reportError(e)
		return null
	}
}

/** Creates `X/X.md` for folder X. Returns its path, or null. */
export async function createFolderNote(siteId, folder) {
	const name = folder.split('/').pop()
	try {
		return (await createPage(siteId, `${folder}/${name}.md`)).path
	} catch (e) {
		await reportError(e)
		return null
	}
}

/**
 * Moves `from` to `to`. When links point at it, asks first ("N links in M
 * pages will be updated", checked by default). Returns the move result, or
 * null when cancelled or failed.
 */
export async function moveTo(siteId, from, to) {
	try {
		const { count, pages } = await getBacklinks(siteId, from, to)
		let updateLinks = false
		if (count > 0) {
			const answer = await spawnDialog(MoveDialog, { count, pages })
			if (!answer) {
				return null
			}
			updateLinks = answer.updateLinks
		}
		const result = await moveNode(siteId, from, to, updateLinks)
		if (result.failed.length) {
			await toast('showWarning', n('markdownsite', '%n page not updated: {pages}', '%n pages not updated: {pages}',
				result.failed.length, { pages: result.failed.join(', ') }))
		}
		return result
	} catch (e) {
		await reportError(e)
		return null
	}
}

/** Renames a page or folder in place (a page keeps its .md). */
export function rename(siteId, node, name) {
	const to = join(parentOf(node.path), node.type === 'page' ? `${name}.md` : name)
	return to === node.path ? Promise.resolve(null) : moveTo(siteId, node.path, to)
}

/** Asks, then moves a page or folder to the trash. Returns true when deleted. */
export async function remove(siteId, node) {
	const pages = countPages(node)
	const { showConfirmation } = await import('@nextcloud/dialogs')
	const ok = await showConfirmation({
		name: t('markdownsite', 'Delete'),
		text: node.type === 'dir'
			? n('markdownsite', 'Move "{name}" to the trash? (+ %n page)', 'Move "{name}" to the trash? (+ %n pages)', pages, { name: node.name })
			: t('markdownsite', 'Move "{name}" to the trash?', { name: node.name }),
	})
	if (!ok) {
		return false
	}
	try {
		await deleteNode(siteId, node.path)
		return true
	} catch (e) {
		await reportError(e)
		return false
	}
}

/** Pages inside a tree node (a folder note counts). */
export function countPages(node) {
	if (node.type === 'page') {
		return 1
	}
	return (node.note ? 1 : 0) + (node.children || []).reduce((sum, child) => sum + countPages(child), 0)
}
