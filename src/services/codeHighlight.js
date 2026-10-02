import { translate as t } from '@nextcloud/l10n'
import { mdiContentCopy } from '@mdi/js'
import { loadChunk } from './chunks.js'
import { copy, flashDone, icon } from './clipboard.js'

// One lazy chunk per language. Only these are highlighted; any other
// language keeps its label and stays plain text.
const LANGUAGES = {
	apache: () => import(/* webpackChunkName: "hljs-apache" */ 'highlight.js/lib/languages/apache'),
	bash: () => import(/* webpackChunkName: "hljs-bash" */ 'highlight.js/lib/languages/bash'),
	c: () => import(/* webpackChunkName: "hljs-c" */ 'highlight.js/lib/languages/c'),
	cpp: () => import(/* webpackChunkName: "hljs-cpp" */ 'highlight.js/lib/languages/cpp'),
	csharp: () => import(/* webpackChunkName: "hljs-csharp" */ 'highlight.js/lib/languages/csharp'),
	css: () => import(/* webpackChunkName: "hljs-css" */ 'highlight.js/lib/languages/css'),
	diff: () => import(/* webpackChunkName: "hljs-diff" */ 'highlight.js/lib/languages/diff'),
	dockerfile: () => import(/* webpackChunkName: "hljs-dockerfile" */ 'highlight.js/lib/languages/dockerfile'),
	go: () => import(/* webpackChunkName: "hljs-go" */ 'highlight.js/lib/languages/go'),
	ini: () => import(/* webpackChunkName: "hljs-ini" */ 'highlight.js/lib/languages/ini'),
	java: () => import(/* webpackChunkName: "hljs-java" */ 'highlight.js/lib/languages/java'),
	javascript: () => import(/* webpackChunkName: "hljs-javascript" */ 'highlight.js/lib/languages/javascript'),
	json: () => import(/* webpackChunkName: "hljs-json" */ 'highlight.js/lib/languages/json'),
	kotlin: () => import(/* webpackChunkName: "hljs-kotlin" */ 'highlight.js/lib/languages/kotlin'),
	latex: () => import(/* webpackChunkName: "hljs-latex" */ 'highlight.js/lib/languages/latex'),
	lua: () => import(/* webpackChunkName: "hljs-lua" */ 'highlight.js/lib/languages/lua'),
	makefile: () => import(/* webpackChunkName: "hljs-makefile" */ 'highlight.js/lib/languages/makefile'),
	markdown: () => import(/* webpackChunkName: "hljs-markdown" */ 'highlight.js/lib/languages/markdown'),
	nginx: () => import(/* webpackChunkName: "hljs-nginx" */ 'highlight.js/lib/languages/nginx'),
	perl: () => import(/* webpackChunkName: "hljs-perl" */ 'highlight.js/lib/languages/perl'),
	php: () => import(/* webpackChunkName: "hljs-php" */ 'highlight.js/lib/languages/php'),
	powershell: () => import(/* webpackChunkName: "hljs-powershell" */ 'highlight.js/lib/languages/powershell'),
	python: () => import(/* webpackChunkName: "hljs-python" */ 'highlight.js/lib/languages/python'),
	r: () => import(/* webpackChunkName: "hljs-r" */ 'highlight.js/lib/languages/r'),
	ruby: () => import(/* webpackChunkName: "hljs-ruby" */ 'highlight.js/lib/languages/ruby'),
	rust: () => import(/* webpackChunkName: "hljs-rust" */ 'highlight.js/lib/languages/rust'),
	scss: () => import(/* webpackChunkName: "hljs-scss" */ 'highlight.js/lib/languages/scss'),
	shell: () => import(/* webpackChunkName: "hljs-shell" */ 'highlight.js/lib/languages/shell'),
	sql: () => import(/* webpackChunkName: "hljs-sql" */ 'highlight.js/lib/languages/sql'),
	swift: () => import(/* webpackChunkName: "hljs-swift" */ 'highlight.js/lib/languages/swift'),
	typescript: () => import(/* webpackChunkName: "hljs-typescript" */ 'highlight.js/lib/languages/typescript'),
	xml: () => import(/* webpackChunkName: "hljs-xml" */ 'highlight.js/lib/languages/xml'),
	yaml: () => import(/* webpackChunkName: "hljs-yaml" */ 'highlight.js/lib/languages/yaml'),
}

const ALIASES = {
	sh: 'bash', zsh: 'bash', console: 'shell', 'c++': 'cpp', cs: 'csharp', 'c#': 'csharp',
	docker: 'dockerfile', golang: 'go', toml: 'ini', js: 'javascript', jsx: 'javascript',
	mjs: 'javascript', cjs: 'javascript', jsonc: 'json', kt: 'kotlin', tex: 'latex',
	make: 'makefile', md: 'markdown', pl: 'perl', ps1: 'powershell', ps: 'powershell',
	py: 'python', rb: 'ruby', rs: 'rust', ts: 'typescript', tsx: 'typescript',
	html: 'xml', xhtml: 'xml', svg: 'xml', yml: 'yaml',
}

/** The language written after the opening fence (`language-xyz` class), lower-cased. */
export function languageOf(code) {
	const match = /(?:^|\s)language-(\S+)/.exec(code.className)
	return match ? match[1].toLowerCase() : ''
}

/** highlight.js module name for a fence language, or null when not supported. */
export function resolveLanguage(lang) {
	const name = ALIASES[lang] || lang
	return Object.prototype.hasOwnProperty.call(LANGUAGES, name) ? name : null
}

/** Wraps a `<pre>` in `.mds-code` with a header holding the language label and a copy button. */
function wrap(code) {
	const pre = code.parentElement
	if (pre.parentElement?.classList.contains('mds-code')) {
		return
	}
	const box = document.createElement('div')
	box.className = 'mds-code'

	const header = document.createElement('div')
	header.className = 'mds-code-header'
	const label = document.createElement('span')
	label.className = 'mds-code-lang'
	label.textContent = languageOf(code)

	const button = document.createElement('button')
	button.type = 'button'
	button.className = 'mds-code-copy'
	button.title = t('markdownsite', 'Copy')
	button.setAttribute('aria-label', t('markdownsite', 'Copy'))
	button.appendChild(icon(mdiContentCopy))
	button.addEventListener('click', async () => {
		if (await copy(code.textContent)) {
			flashDone(button)
		}
	})

	header.append(label, button)
	pre.replaceWith(box)
	box.append(header, pre)
}

/**
 * Adds a language label and a copy button to every fenced code block under
 * `root` (Mermaid blocks excepted), then highlights the supported languages.
 * highlight.js is only downloaded when the page has such a block.
 */
export async function decorateCode(root) {
	if (!root) {
		return
	}
	const blocks = [...root.querySelectorAll('pre > code[class*="language-"]')]
		.filter(code => !code.classList.contains('language-mermaid'))
	if (!blocks.length) {
		return
	}
	blocks.forEach(wrap)

	const wanted = new Set(blocks.map(code => resolveLanguage(languageOf(code))).filter(Boolean))
	if (!wanted.size) {
		return
	}
	const core = await loadChunk(() => import(/* webpackChunkName: "hljs-core" */ 'highlight.js/lib/core'), 'highlight.js')
	if (!core) {
		return
	}
	const hljs = core.default
	await Promise.all([...wanted].map(async (name) => {
		if (hljs.getLanguage(name)) {
			return
		}
		const language = await loadChunk(LANGUAGES[name], `highlight.js (${name})`)
		if (language) {
			hljs.registerLanguage(name, language.default)
		}
	}))

	for (const code of blocks) {
		const name = resolveLanguage(languageOf(code))
		// The reader may have moved on while the chunks were loading.
		if (!name || !code.isConnected || !hljs.getLanguage(name)) {
			continue
		}
		code.innerHTML = hljs.highlight(code.textContent, { language: name, ignoreIllegals: true }).value
		code.classList.add('hljs')
	}
}
