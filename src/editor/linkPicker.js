import { showTooltip } from '@codemirror/view'
import { translate as t } from '@nextcloud/l10n'
import { buildWikilink } from '../services/pageLinks.js'

const MAX_TOOLTIP_SELECTION = 200

/** Change turning `range` into a wikilink to `target`; whitespace around the text stays outside the link. */
export function linkChange(state, range, target) {
	const text = state.sliceDoc(range.from, range.to)
	const from = range.from + (text.length - text.trimStart().length)
	const to = range.to - (text.length - text.trimEnd().length)
	const insert = buildWikilink(target, text)
	return { changes: { from: Math.min(from, to), to: Math.max(from, to), insert }, selection: { anchor: Math.min(from, to) + insert.length } }
}

/**
 * Cmd/Ctrl+K and the selection button: asks which page to link to, then turns
 * the selection (or the cursor) into a wikilink.
 *
 * @param {Function} askTarget (selectedText) => Promise<string|null> wikilink target
 */
export function pickLink(askTarget) {
	return (view) => {
		const range = view.state.selection.main
		const text = view.state.sliceDoc(range.from, range.to)
		askTarget(text).then((target) => {
			if (target) {
				// The dialog is async: aim at the same text if the selection moved meanwhile.
				const at = view.state.sliceDoc(range.from, range.to) === text ? range : view.state.selection.main
				view.dispatch(linkChange(view.state, at, target))
			}
			view.focus()
		})
		return true
	}
}

function tooltipFor(state, run) {
	const range = state.selection.main
	if (range.empty || range.to - range.from > MAX_TOOLTIP_SELECTION) {
		return null
	}
	const text = state.sliceDoc(range.from, range.to)
	if (!text.trim() || text.includes('\n')) {
		return null
	}
	return {
		pos: range.from,
		above: true,
		strictSide: true,
		create(view) {
			const button = document.createElement('button')
			button.type = 'button'
			button.className = 'mds-link-tip'
			button.textContent = t('markdownsite', 'Link to a page')
			// Keep the selection and the focus in the editor.
			button.addEventListener('mousedown', event => event.preventDefault())
			button.addEventListener('click', () => run(view))
			return { dom: button }
		},
	}
}

/** A "Link to a page" button above any selected text. */
export function selectionLinkButton(run) {
	return showTooltip.compute(['selection'], state => tooltipFor(state, run))
}
