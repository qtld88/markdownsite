// Loaded as a lazy chunk the first time a user enters edit mode: readers
// never download CodeMirror.
import { EditorState } from '@codemirror/state'
import { EditorView, keymap } from '@codemirror/view'
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands'
import { autocompletion } from '@codemirror/autocomplete'
import { HighlightStyle, syntaxHighlighting, syntaxTree } from '@codemirror/language'
import { markdown, markdownLanguage } from '@codemirror/lang-markdown'
import { tags } from '@lezer/highlight'
import { obsidianSyntax } from './syntax.js'
import { livePreview } from './livePreview.js'
import { blockWidgets } from './blockWidgets.js'
import { wikilinkCompletion } from './wikilinkComplete.js'
import { attachments } from './attachments.js'
import { editorKeymap } from './keymap.js'

const markdownStyle = HighlightStyle.define([
	{ tag: tags.strong, fontWeight: '700' },
	{ tag: tags.emphasis, fontStyle: 'italic' },
	{ tag: tags.strikethrough, textDecoration: 'line-through', color: 'var(--color-text-maxcontrast)' },
	{ tag: tags.special(tags.content), backgroundColor: 'rgba(255, 208, 0, 0.35)', borderRadius: '3px' },
	{ tag: tags.link, color: 'var(--color-primary-element)' },
	{ tag: tags.url, color: 'var(--color-text-maxcontrast)' },
	{ tag: tags.monospace, fontFamily: 'monospace', backgroundColor: 'var(--color-background-dark)', borderRadius: '4px' },
	{ tag: tags.processingInstruction, color: 'var(--color-text-maxcontrast)' },
	{ tag: tags.quote, color: 'var(--color-text-maxcontrast)' },
	{ tag: tags.heading, fontWeight: '700' },
])

const theme = EditorView.theme({
	'&': { backgroundColor: 'transparent', color: 'var(--color-main-text)' },
	'&.cm-focused': { outline: 'none' },
	'.cm-scroller': { fontFamily: 'inherit', lineHeight: '1.6' },
	// Nextcloud's core CSS gives every div[contenteditable] a fixed width, a
	// border and a focus ring; the editor and its widgets must not get them.
	'.cm-content': {
		width: 'auto', minHeight: '0', margin: '0', padding: '0', border: 'none', borderRadius: '0',
		boxShadow: 'none', background: 'transparent', caretColor: 'var(--color-main-text)',
	},
	'.cm-content:focus, .cm-content:focus-visible': { outline: 'none', boxShadow: 'none' },
	'.cm-line': { padding: '0' },
	'.cm-mds-h1': { fontSize: '1.9em', fontWeight: '700' },
	'.cm-mds-h2': { fontSize: '1.5em', fontWeight: '700' },
	'.cm-mds-h3': { fontSize: '1.2em', fontWeight: '600' },
	'.cm-mds-h4, .cm-mds-h5, .cm-mds-h6': { fontWeight: '600' },
	'.cm-mds-wikilink': { color: 'var(--mds-link-color, var(--color-primary-element))' },
	'.mds-block-widget': {
		width: 'auto', maxWidth: 'none', minHeight: '0', margin: '0', padding: '0', border: 'none',
		boxShadow: 'none', background: 'transparent', cursor: 'pointer', borderRadius: 'var(--border-radius-large, 8px)',
	},
	'.mds-block-widget:hover': { outline: '1px dashed var(--color-border-dark, var(--color-border))' },
	'.mds-frontmatter': { margin: '0', fontSize: '0.9em', color: 'var(--color-text-maxcontrast)' },
})

/** Name or URL of the link under a Ctrl/Cmd+click, or null. */
function linkAt(view, pos) {
	for (let node = syntaxTree(view.state).resolveInner(pos, 1); node; node = node.parent) {
		if (node.name === 'Wikilink' || node.name === 'Embed') {
			const target = node.getChild('WikilinkTarget')
			return target ? { wikilink: view.state.sliceDoc(target.from, target.to) } : null
		}
		if (node.name === 'Link') {
			const url = node.getChild('URL')
			return url ? { href: view.state.sliceDoc(url.from, url.to) } : null
		}
	}
	return null
}

/**
 * Creates the Markdown editor inside `parent`.
 *
 * @param {object} options
 * @param {HTMLElement} options.parent
 * @param {string} options.doc initial Markdown
 * @param {Function} options.getPages () => pages for [[ completion
 * @param {Function} options.render (markdown) => Promise<string> HTML for block widgets
 * @param {Function} options.upload (File) => Promise<{embed}>
 * @param {Function} options.onChange (text) on every edit
 * @param {Function} options.onExit Escape
 * @param {Function} options.onFollow ({wikilink}|{href}) on Ctrl/Cmd+click of a link
 * @param {Function} options.onError (error) for failed uploads
 * @return {EditorView}
 */
export function createEditor({ parent, doc, getPages, render, upload, onChange, onExit, onFollow, onError }) {
	return new EditorView({
		parent,
		state: EditorState.create({
			doc,
			extensions: [
				history(),
				markdown({ base: markdownLanguage, extensions: [obsidianSyntax] }),
				syntaxHighlighting(markdownStyle),
				livePreview,
				blockWidgets(render),
				autocompletion({ override: [wikilinkCompletion(getPages)] }),
				attachments(upload, onError),
				keymap.of([...defaultKeymap, ...historyKeymap]),
				editorKeymap({ onExit }),
				EditorView.lineWrapping,
				theme,
				EditorView.updateListener.of((update) => {
					if (update.docChanged) {
						onChange(update.state.doc.toString())
					}
				}),
				EditorView.domEventHandlers({
					mousedown(event, view) {
						if (!(event.ctrlKey || event.metaKey)) {
							return false
						}
						// The clicked element is more reliable than coordinates.
						let pos = null
						try {
							pos = view.posAtDOM(event.target)
						} catch (e) {
							pos = view.posAtCoords({ x: event.clientX, y: event.clientY })
						}
						const link = pos === null ? null : linkAt(view, pos)
						if (!link) {
							return false
						}
						event.preventDefault()
						onFollow(link)
						return true
					},
				}),
			],
		}),
	})
}

/** Replaces the whole document (after "Reload" on a conflict). */
export function setDocument(view, text) {
	view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text } })
}
