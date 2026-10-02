import { RangeSetBuilder } from '@codemirror/state'
import { Decoration, ViewPlugin } from '@codemirror/view'
import { syntaxTree } from '@codemirror/language'

const hidden = Decoration.replace({})

// Inline elements whose markers are hidden while the selection is elsewhere.
const MARKERS = {
	Emphasis: ['EmphasisMark'],
	StrongEmphasis: ['EmphasisMark'],
	Strikethrough: ['StrikethroughMark'],
	Highlight: ['HighlightMark'],
	InlineCode: ['CodeMark'],
}

const LINE_CLASSES = {
	ATXHeading1: 'cm-mds-h1',
	ATXHeading2: 'cm-mds-h2',
	ATXHeading3: 'cm-mds-h3',
	ATXHeading4: 'cm-mds-h4',
	ATXHeading5: 'cm-mds-h5',
	ATXHeading6: 'cm-mds-h6',
}

/** True when any selection range touches [from, to]. */
export function touches(selection, from, to) {
	return selection.ranges.some(r => r.from <= to && r.to >= from)
}

/** Ranges to hide (and line classes to add) for the visible part of the document. */
function decorations(view) {
	const { state } = view
	const ranges = []
	for (const { from, to } of view.visibleRanges) {
		syntaxTree(state).iterate({
			from,
			to,
			enter(node) {
				const name = node.name
				if (LINE_CLASSES[name]) {
					const line = state.doc.lineAt(node.from)
					ranges.push([line.from, line.from, Decoration.line({ class: LINE_CLASSES[name] })])
				}
				const active = touches(state.selection, node.from, node.to)
				if (active) {
					return
				}
				if (MARKERS[name]) {
					for (let child = node.node.firstChild; child; child = child.nextSibling) {
						if (MARKERS[name].includes(child.name)) {
							ranges.push([child.from, child.to, hidden])
						}
					}
				} else if (LINE_CLASSES[name]) {
					const mark = node.node.getChild('HeaderMark')
					if (mark) {
						const end = state.doc.sliceString(mark.to, mark.to + 1) === ' ' ? mark.to + 1 : mark.to
						ranges.push([mark.from, end, hidden])
					}
				} else if (name === 'Link') {
					// [text](url "title"): keep only "text".
					const marks = node.node.getChildren('LinkMark')
					if (marks.length >= 2) {
						ranges.push([marks[0].from, marks[0].to, hidden])
						ranges.push([marks[1].from, node.to, hidden])
					}
				} else if (name === 'Wikilink') {
					// [[target]] shows "target"; [[target|label]] shows "label".
					const label = node.node.getChild('WikilinkLabel')
					const marks = node.node.getChildren('WikilinkMark')
					if (label) {
						ranges.push([node.from, label.from, hidden])
					} else if (marks.length) {
						ranges.push([marks[0].from, marks[0].to, hidden])
					}
					if (marks.length) {
						const last = marks[marks.length - 1]
						ranges.push([last.from, last.to, hidden])
					}
					ranges.push([node.from, node.to, Decoration.mark({ class: 'cm-mds-wikilink' })])
				}
			},
		})
	}
	// RangeSetBuilder needs ranges sorted by start, then by end-side.
	ranges.sort((a, b) => a[0] - b[0] || a[2].startSide - b[2].startSide || a[1] - b[1])
	const builder = new RangeSetBuilder()
	for (const [from, to, deco] of ranges) {
		builder.add(from, to, deco)
	}
	return builder.finish()
}

/**
 * Obsidian-style live preview: Markdown markers (`**`, `_`, `~~`, `==`,
 * heading `#`, backticks, link syntax, wikilink brackets) are hidden unless
 * the selection touches their element. The text itself is never changed.
 */
export const livePreview = ViewPlugin.fromClass(class {
	constructor(view) {
		this.decorations = decorations(view)
	}

	update(update) {
		if (update.docChanged || update.selectionSet || update.viewportChanged) {
			this.decorations = decorations(update.view)
		}
	}
}, { decorations: v => v.decorations })
