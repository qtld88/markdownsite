import { tags } from '@lezer/highlight'

const OPEN_BRACKET = 91 // [
const CLOSE_BRACKET = 93 // ]
const BANG = 33 // !
const PIPE = 124 // |
const EQUALS = 61 // =
const NEWLINE = 10

/** Wikilink or embed starting at `pos`: [[target]], [[target|label]], ![[target]]. */
function parseWikilink(cx, next, pos) {
	const embed = next === BANG
	const open = embed ? pos + 1 : pos
	if (cx.char(open) !== OPEN_BRACKET || cx.char(open + 1) !== OPEN_BRACKET) {
		return -1
	}
	const start = open + 2
	let pipe = -1
	for (let i = start; i < cx.end; i++) {
		const ch = cx.char(i)
		if (ch === NEWLINE || (ch === OPEN_BRACKET && cx.char(i + 1) === OPEN_BRACKET)) {
			return -1
		}
		if (ch === PIPE && pipe < 0) {
			pipe = i
		}
		if (ch === CLOSE_BRACKET && cx.char(i + 1) === CLOSE_BRACKET) {
			const targetEnd = pipe < 0 ? i : pipe
			if (targetEnd === start) {
				return -1
			}
			const children = [
				cx.elt('WikilinkMark', pos, start),
				cx.elt('WikilinkTarget', start, targetEnd),
			]
			if (pipe >= 0) {
				children.push(cx.elt('WikilinkMark', pipe, pipe + 1))
				children.push(cx.elt('WikilinkLabel', pipe + 1, i))
			}
			children.push(cx.elt('WikilinkMark', i, i + 2))
			return cx.addElement(cx.elt(embed ? 'Embed' : 'Wikilink', pos, i + 2, children))
		}
	}
	return -1
}

const HighlightDelim = { resolve: 'Highlight', mark: 'HighlightMark' }

/**
 * @lezer/markdown extension for Obsidian syntax: [[wikilinks]],
 * [[target|label]], ![[embeds]] and ==highlight==.
 */
export const obsidianSyntax = {
	defineNodes: [
		{ name: 'Wikilink', style: tags.link },
		{ name: 'Embed', style: tags.link },
		{ name: 'WikilinkMark', style: tags.processingInstruction },
		{ name: 'WikilinkTarget', style: tags.link },
		{ name: 'WikilinkLabel', style: tags.link },
		{ name: 'Highlight', style: { 'Highlight/...': tags.special(tags.content) } },
		{ name: 'HighlightMark', style: tags.processingInstruction },
	],
	parseInline: [
		{ name: 'Wikilink', parse: parseWikilink, before: 'Link' },
		{
			name: 'Highlight',
			parse(cx, next, pos) {
				if (next !== EQUALS || cx.char(pos + 1) !== EQUALS || cx.char(pos + 2) === EQUALS) {
					return -1
				}
				const before = cx.slice(pos - 1, pos)
				const after = cx.slice(pos + 2, pos + 3)
				const spaceBefore = /\s|^$/.test(before)
				const spaceAfter = /\s|^$/.test(after)
				return cx.addDelimiter(HighlightDelim, pos, pos + 2, !spaceAfter, !spaceBefore)
			},
			after: 'Emphasis',
		},
	],
}
