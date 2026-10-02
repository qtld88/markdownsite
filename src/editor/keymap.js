import { EditorSelection } from '@codemirror/state'
import { keymap } from '@codemirror/view'

/** Wraps each selection in `marker` (or removes it when already wrapped). */
export function toggleWrap(marker) {
	return (view) => {
		view.dispatch(view.state.changeByRange((range) => {
			const text = view.state.sliceDoc(range.from, range.to)
			const n = marker.length
			if (text.length >= 2 * n && text.startsWith(marker) && text.endsWith(marker)) {
				return {
					changes: { from: range.from, to: range.to, insert: text.slice(n, -n) },
					range: EditorSelection.range(range.from, range.to - 2 * n),
				}
			}
			return {
				changes: { from: range.from, to: range.to, insert: marker + text + marker },
				range: EditorSelection.range(range.from + n, range.to + n),
			}
		}))
		return true
	}
}

/** [selection](|) with the cursor between the parentheses. */
export function insertLink(view) {
	view.dispatch(view.state.changeByRange((range) => {
		const text = view.state.sliceDoc(range.from, range.to)
		const insert = `[${text}]()`
		return { changes: { from: range.from, to: range.to, insert }, range: EditorSelection.cursor(range.from + insert.length - 1) }
	}))
	return true
}

/** Cmd/Ctrl+B, I, K and Escape (leave edit mode). */
export function editorKeymap({ onExit }) {
	return keymap.of([
		{ key: 'Mod-b', run: toggleWrap('**') },
		{ key: 'Mod-i', run: toggleWrap('*') },
		{ key: 'Mod-k', run: insertLink },
		{ key: 'Escape', run: () => { onExit(); return true } },
	])
}
