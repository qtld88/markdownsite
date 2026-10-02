import { EditorView } from '@codemirror/view'

/**
 * Dropping or pasting files uploads them and inserts the returned embed.
 *
 * @param {Function} upload (File) => Promise<{embed: string}>
 * @param {Function} onError (error) => void
 */
export function attachments(upload, onError) {
	async function insert(view, files, pos) {
		for (const file of files) {
			try {
				const { embed } = await upload(file)
				const text = embed + '\n'
				view.dispatch({ changes: { from: pos, insert: text }, selection: { anchor: pos + text.length } })
				pos += text.length
			} catch (error) {
				onError(error)
			}
		}
	}
	return EditorView.domEventHandlers({
		drop(event, view) {
			const files = [...(event.dataTransfer?.files || [])]
			if (!files.length) {
				return false
			}
			event.preventDefault()
			const pos = view.posAtCoords({ x: event.clientX, y: event.clientY }) ?? view.state.selection.main.head
			insert(view, files, pos)
			return true
		},
		paste(event, view) {
			const files = [...(event.clipboardData?.files || [])]
			if (!files.length) {
				return false
			}
			event.preventDefault()
			insert(view, files, view.state.selection.main.head)
			return true
		},
	})
}
