import { describe, expect, it } from 'vitest'
import { parser as baseParser, GFM } from '@lezer/markdown'
import { obsidianSyntax } from '../../src/editor/syntax.js'

const parser = baseParser.configure([GFM, obsidianSyntax])

/** "Name[from,to]" for every node inside the paragraph, in document order. */
function nodes(text) {
	const out = []
	parser.parse(text).iterate({
		enter(node) {
			if (!['Document', 'Paragraph'].includes(node.name)) {
				out.push(`${node.name}[${text.slice(node.from, node.to)}]`)
			}
		},
	})
	return out
}

describe('obsidianSyntax', () => {
	it('parses a wikilink', () => {
		expect(nodes('see [[Page]] now')).toEqual([
			'Wikilink[[[Page]]]', 'WikilinkMark[[[]', 'WikilinkTarget[Page]', 'WikilinkMark[]]]',
		])
	})

	it('parses a label', () => {
		expect(nodes('[[Page|the label]]')).toEqual([
			'Wikilink[[[Page|the label]]]', 'WikilinkMark[[[]', 'WikilinkTarget[Page]',
			'WikilinkMark[|]', 'WikilinkLabel[the label]', 'WikilinkMark[]]]',
		])
	})

	it('keeps a heading in the target', () => {
		expect(nodes('[[Page#Setup]]')).toContain('WikilinkTarget[Page#Setup]')
	})

	it('parses an embed', () => {
		expect(nodes('![[pic.png]]')).toEqual([
			'Embed[![[pic.png]]]', 'WikilinkMark[![[]', 'WikilinkTarget[pic.png]', 'WikilinkMark[]]]',
		])
	})

	it('parses ==highlight==', () => {
		expect(nodes('a ==marked== b')).toEqual([
			'Highlight[==marked==]', 'HighlightMark[==]', 'HighlightMark[==]',
		])
	})

	it('leaves an unclosed wikilink and lone == alone', () => {
		expect(nodes('[[Page and a == b')).toEqual([])
	})

	it('does not treat a normal link as a wikilink', () => {
		expect(nodes('[text](url)')[0]).toBe('Link[[text](url)]')
	})
})
