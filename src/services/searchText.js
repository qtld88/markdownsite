/*
 * Search text helpers, mirroring lib/Search/Normalizer.php and
 * lib/Search/QueryParser.php so the page highlights what the server matched.
 */

// Lower-case Latin-1 Supplement and Latin Extended-A letters => base letter.
const BASE = {
	'ß': 's', 'à': 'a', 'á': 'a', 'â': 'a', 'ã': 'a', 'ä': 'a', 'å': 'a', 'æ': 'a',
	'ç': 'c', 'è': 'e', 'é': 'e', 'ê': 'e', 'ë': 'e', 'ì': 'i', 'í': 'i', 'î': 'i',
	'ï': 'i', 'ð': 'd', 'ñ': 'n', 'ò': 'o', 'ó': 'o', 'ô': 'o', 'õ': 'o', 'ö': 'o',
	'ø': 'o', 'ù': 'u', 'ú': 'u', 'û': 'u', 'ü': 'u', 'ý': 'y', 'þ': 't', 'ÿ': 'y',
	'ā': 'a', 'ă': 'a', 'ą': 'a', 'ć': 'c', 'ĉ': 'c', 'ċ': 'c', 'č': 'c', 'ď': 'd',
	'đ': 'd', 'ē': 'e', 'ĕ': 'e', 'ė': 'e', 'ę': 'e', 'ě': 'e', 'ĝ': 'g', 'ğ': 'g',
	'ġ': 'g', 'ģ': 'g', 'ĥ': 'h', 'ħ': 'h', 'ĩ': 'i', 'ī': 'i', 'ĭ': 'i', 'į': 'i',
	'ı': 'i', 'ĳ': 'i', 'ĵ': 'j', 'ķ': 'k', 'ĸ': 'k', 'ĺ': 'l', 'ļ': 'l', 'ľ': 'l',
	'ŀ': 'l', 'ł': 'l', 'ń': 'n', 'ņ': 'n', 'ň': 'n', 'ŉ': 'n', 'ŋ': 'n', 'ō': 'o',
	'ŏ': 'o', 'ő': 'o', 'œ': 'o', 'ŕ': 'r', 'ŗ': 'r', 'ř': 'r', 'ś': 's', 'ŝ': 's',
	'ş': 's', 'š': 's', 'ţ': 't', 'ť': 't', 'ŧ': 't', 'ũ': 'u', 'ū': 'u', 'ŭ': 'u',
	'ů': 'u', 'ű': 'u', 'ų': 'u', 'ŵ': 'w', 'ŷ': 'y', 'ź': 'z', 'ż': 'z', 'ž': 'z',
	'ſ': 's',
}

const MIN_TERM_LENGTH = 2

/**
 * Lower case, accents and ligatures folded to their base letter. One code
 * point in, one code point out, and each keeps its UTF-16 length, so string
 * indices in the result are valid in the original.
 */
export function normalize(text) {
	let out = ''
	for (const ch of String(text)) {
		// Simple case mapping: keep one code point ("İ" would become two).
		const lower = ch.toLowerCase()
		const one = lower.length === ch.length ? lower : String.fromCodePoint(lower.codePointAt(0))
		out += BASE[one] || one
	}
	return out
}

/** Normalised terms of a query: words, or "quoted phrases"; terms shorter than 2 dropped. */
export function parseQuery(query) {
	const terms = []
	for (const match of String(query).matchAll(/"([^"]*)"?|(\S+)/gu)) {
		const raw = match[2] !== undefined ? match[2] : match[1]
		const term = normalize(raw).replace(/\s+/gu, ' ').trim()
		if ([...term].length >= MIN_TERM_LENGTH && !terms.includes(term)) {
			terms.push(term)
		}
	}
	return terms
}

/**
 * Splits a result snippet into plain and highlighted parts. `highlights` are
 * [start, length] pairs in code points (from the server).
 *
 * @return {Array<{text: string, hit: boolean}>}
 */
export function snippetParts(snippet, highlights) {
	const chars = Array.from(snippet)
	const parts = []
	let at = 0
	for (const [start, length] of highlights || []) {
		if (start < at || start >= chars.length) {
			continue
		}
		if (start > at) {
			parts.push({ text: chars.slice(at, start).join(''), hit: false })
		}
		parts.push({ text: chars.slice(start, start + length).join(''), hit: true })
		at = start + length
	}
	if (at < chars.length) {
		parts.push({ text: chars.slice(at).join(''), hit: false })
	}
	return parts
}
