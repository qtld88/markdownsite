import { StateEffect, StateField } from '@codemirror/state'
import { Decoration, EditorView, ViewPlugin, WidgetType } from '@codemirror/view'
import { syntaxTree } from '@codemirror/language'
import { decorateCallouts } from '../services/calloutCopy.js'
import { decorateCode } from '../services/codeHighlight.js'
import { renderDiagrams } from '../services/mermaid.js'
import { touches } from './livePreview.js'

const rendered = StateEffect.define()

/** Rendered HTML per block source: string, null (failed), or a pending promise. */
const cache = new Map()

class RenderedBlock extends WidgetType {
	constructor(source, html, kind) {
		super()
		this.source = source
		this.html = html
		this.kind = kind
	}

	eq(other) {
		return other.source === this.source && other.html === this.html
	}

	toDOM(view) {
		const el = document.createElement('div')
		// mds-content: the same styles as the reading view (src/styles/content.css).
		el.className = `mds-block-widget mds-block-widget--${this.kind} mds-content`
		if (this.kind === 'frontmatter') {
			// The server renders frontmatter as nothing: show the properties instead.
			const pre = document.createElement('pre')
			pre.className = 'mds-frontmatter'
			pre.textContent = this.source.replace(/^---\n|\n---\s*$/g, '')
			el.appendChild(pre)
			return el
		}
		el.innerHTML = this.html
		decorateCallouts(el)
		// Diagrams and images change the block's height once ready: tell the
		// editor, or clicks below would land on the wrong line.
		const remeasure = () => view.requestMeasure()
		Promise.all([decorateCode(el), renderDiagrams(el)]).then(remeasure, remeasure)
		el.querySelectorAll('img').forEach(img => img.addEventListener('load', remeasure, { once: true }))
		return el
	}

	ignoreEvent(event) {
		return event.type !== 'mousedown'
	}
}

/** Blocks shown rendered while the cursor is outside them: [{from, to, kind}]. */
export function findBlocks(state) {
	const blocks = []
	const doc = state.doc
	const text = doc.toString()
	const front = /^---\n[\s\S]*?\n---[ \t]*(?:\n|$)/.exec(text)
	if (front) {
		blocks.push({ from: 0, to: front[0].endsWith('\n') ? front[0].length - 1 : front[0].length, kind: 'frontmatter' })
	}
	const tree = syntaxTree(state)
	for (let node = tree.topNode.firstChild; node; node = node.nextSibling) {
		if (front && node.from < blocks[0].to) {
			continue
		}
		let kind = null
		if (node.name === 'Table') {
			kind = 'table'
		} else if (node.name === 'Blockquote' && /^\s*>\s*\[!/.test(doc.lineAt(node.from).text)) {
			kind = 'callout'
		} else if (node.name === 'FencedCode' && /^mermaid\b/i.test(doc.sliceString(node.getChild('CodeInfo')?.from ?? node.from, node.getChild('CodeInfo')?.to ?? node.from))) {
			kind = 'mermaid'
		} else if (node.name === 'Paragraph') {
			const only = node.firstChild
			if (only && !only.nextSibling && only.from === node.from && only.to === node.to && (only.name === 'Embed' || only.name === 'Image')) {
				kind = 'embed'
			}
		}
		if (kind) {
			blocks.push({ from: doc.lineAt(node.from).from, to: doc.lineAt(node.to).to, kind })
		}
	}
	return blocks
}

function build(state, render, dispatch) {
	const decorations = []
	for (const block of findBlocks(state)) {
		if (touches(state.selection, block.from, block.to)) {
			continue
		}
		const source = state.doc.sliceString(block.from, block.to)
		let html = ''
		if (block.kind !== 'frontmatter') {
			html = cache.get(source)
			if (html === undefined) {
				const pending = render(source).then(
					(result) => { cache.set(source, result) },
					() => { cache.set(source, null) }, // failure: stay raw text, no message
				).then(() => dispatch())
				cache.set(source, pending)
				continue
			}
			if (typeof html !== 'string') {
				continue // still loading, or failed
			}
		}
		decorations.push(Decoration.replace({ widget: new RenderedBlock(source, html, block.kind), block: true }).range(block.from, block.to))
	}
	return Decoration.set(decorations, true)
}

/**
 * Callouts, tables, embeds, images, Mermaid diagrams and frontmatter are
 * shown as the reading view renders them (HTML from `render(markdown)`)
 * while the cursor is outside; clicking one reveals its Markdown.
 *
 * @param {Function} render (markdown) => Promise<string> html
 */
export function blockWidgets(render) {
	let view = null
	const refresh = () => view?.dispatch({ effects: rendered.of(null) })
	const field = StateField.define({
		create: state => build(state, render, refresh),
		update(value, tr) {
			if (tr.docChanged || tr.selection || tr.effects.some(e => e.is(rendered))) {
				return build(tr.state, render, refresh)
			}
			return value
		},
		provide: f => EditorView.decorations.from(f),
	})
	// Remembers the view so a finished render can trigger a redraw.
	const capture = ViewPlugin.define((v) => {
		view = v
		return { destroy() { view = null } }
	})
	const reveal = EditorView.domEventHandlers({
		mousedown(event, v) {
			const widget = event.target.closest?.('.mds-block-widget')
			if (!widget || event.target.closest('a, button, summary')) {
				return false
			}
			const pos = v.posAtDOM(widget)
			v.dispatch({ selection: { anchor: pos } })
			v.focus()
			event.preventDefault()
			return true
		},
	})
	return [field, capture, reveal]
}
