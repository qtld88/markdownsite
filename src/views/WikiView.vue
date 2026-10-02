<template>
	<NcContent app-name="markdownsite">
		<NcAppNavigation>
			<template v-if="store.sites.length">
				<SiteSwitcher />
				<PageTree v-if="activeSiteId" :nodes="tree" :site-id="activeSiteId"
					:active-path="prefs.revealActive ? currentPath : ''" />
			</template>
			<template #footer>
				<div class="mds-navfooter">
					<NcButton variant="primary" wide @click="showNew = true">
						<template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
						{{ t('markdownsite', 'New site') }}
					</NcButton>
					<NcButton variant="tertiary" :aria-label="t('markdownsite', 'Settings')" @click="showSettings = true">
						<template #icon><NcIconSvgWrapper :path="mdiCog" :size="20" /></template>
					</NcButton>
				</div>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<NcEmptyContent v-if="!store.sites.length"
				:name="t('markdownsite', 'No wiki yet')"
				:description="t('markdownsite', 'Create your first wiki from a folder of Markdown files.')">
				<template #icon><NcIconSvgWrapper :path="mdiBookOpenVariant" :size="64" /></template>
				<template #action><NcButton variant="primary" @click="showNew = true">{{ t('markdownsite', 'New site') }}</NcButton></template>
			</NcEmptyContent>

			<div v-else-if="loading" class="mds-center"><NcLoadingIcon :size="32" /></div>

			<NcEmptyContent v-else-if="error"
				:name="t('markdownsite', 'Page not found')">
				<template #icon><NcIconSvgWrapper :path="mdiFileRemoveOutline" :size="64" /></template>
			</NcEmptyContent>

			<NcEmptyContent v-else-if="!currentPath"
				:name="t('markdownsite', 'Choose a page')">
				<template #icon><NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="64" /></template>
			</NcEmptyContent>

			<div v-else ref="page" class="mds-page" :class="{ 'mds-page--toc-collapsed': prefs.tocCollapsed }">
				<div class="mds-main">
					<PageBreadcrumb :site-id="activeSiteId"
						:site-name="store.current ? store.current.name : ''"
						:path="currentPath"
						:tree="tree"
						@reveal="revealFolder" />
					<PageToc class="mds-toc-narrow" variant="inline" :toc="toc" @navigate="goToHeading" />
					<article ref="article" class="mds-content" :style="prefs.cssVars" v-html="html" @click="onClick" />
					<PagePager :site-id="activeSiteId" :path="currentPath" :tree="tree" />
				</div>
				<PageToc class="mds-toc-wide"
					variant="side"
					:toc="toc"
					:collapsed="prefs.tocCollapsed"
					@update:collapsed="setTocCollapsed"
					@navigate="goToHeading" />
			</div>
		</NcAppContent>

		<NewSiteDialog v-model:open="showNew" />
		<SettingsDialog v-model:open="showSettings" />
	</NcContent>
</template>

<script>
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiPlus, mdiCog, mdiBookOpenVariant, mdiFileDocumentOutline, mdiFileRemoveOutline } from '@mdi/js'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getTree, getPage } from '../services/api.js'
import { decorateCallouts } from '../services/calloutCopy.js'
import { decorateCode } from '../services/codeHighlight.js'
import { renderDiagrams } from '../services/mermaid.js'
import { headingElement, safeDecode, splitHash } from '../services/anchors.js'
import { enableTaskLists } from '../services/taskList.js'
import { useSitesStore } from '../stores/sites.js'
import { usePrefsStore } from '../stores/prefs.js'
import { useTreeStore } from '../stores/tree.js'
import SiteSwitcher from '../components/SiteSwitcher.vue'
import PageTree from '../components/PageTree.vue'
import PageToc from '../components/PageToc.vue'
import PageBreadcrumb from '../components/PageBreadcrumb.vue'
import PagePager from '../components/PagePager.vue'
import NewSiteDialog from '../components/NewSiteDialog.vue'
import SettingsDialog from '../components/SettingsDialog.vue'

export default {
	name: 'WikiView',
	components: {
		NcContent, NcAppNavigation, NcAppContent, NcButton, NcEmptyContent, NcLoadingIcon, NcIconSvgWrapper,
		SiteSwitcher, PageTree, PageToc, PageBreadcrumb, PagePager, NewSiteDialog, SettingsDialog,
	},
	setup() {
		return {
			store: useSitesStore(),
			prefs: usePrefsStore(),
			treeState: useTreeStore(),
			t,
			mdiPlus, mdiCog, mdiBookOpenVariant, mdiFileDocumentOutline, mdiFileRemoveOutline,
		}
	},
	data() { return { tree: [], html: '', toc: [], error: false, loading: false, showNew: false, showSettings: false, homePath: null } },
	computed: {
		routeSiteId() { return this.$route.params.siteId || null },
		activeSiteId() { return this.routeSiteId || (this.store.current && this.store.current.id) || null },
		currentPath() {
			const p = this.$route.params.path
			return Array.isArray(p) ? p.join('/') : (p || '')
		},
	},
	watch: {
		$route(to, from) {
			// Only the #heading changed (TOC click, back/forward): scroll, don't reload.
			if (from && to.path === from.path && to.hash !== from.hash) {
				this.scrollToHash()
				return
			}
			this.sync()
		},
		activeSiteId(next, prev) {
			// The id is a string in the route and a number in the store: compare as text.
			if (String(next) !== String(prev)) {
				this.treeState.clear()
			}
		},
	},
	async mounted() {
		this.prefs.load()
		if (!this.store.loaded) { await this.store.load() }
		await this.bootstrap()
	},
	methods: {
		async bootstrap() {
			// No site in route: open first site if any.
			if (!this.routeSiteId && this.store.sites.length) {
				this.$router.replace({ name: 'site', params: { siteId: this.store.sites[0].id } })
				return
			}
			await this.sync()
		},
		async sync() {
			if (!this.activeSiteId) { return }
			this.store.setCurrent(this.activeSiteId)
			await this.loadTree()
			if (!this.currentPath) {
				if (this.homePath) {
					this.$router.replace({ name: 'page', params: { siteId: this.activeSiteId, path: this.homePath } })
				}
				return
			}
			await this.loadPage()
			this.scrollActiveIntoView()
		},
		scrollActiveIntoView() {
			if (!this.prefs.revealActive) { return }
			this.$nextTick(() => {
				const el = document.querySelector('.mds-tree [data-mds-active]')
				if (el && el.scrollIntoView) {
					el.scrollIntoView({ block: 'center', behavior: 'smooth' })
				}
			})
		},
		async loadTree() {
			try {
				const data = await getTree(this.activeSiteId)
				this.tree = data.tree
				this.homePath = data.home
			} catch (e) {
				this.tree = []; this.homePath = null
			}
		},
		async loadPage() {
			this.loading = true; this.error = false
			try {
				const data = await getPage(this.activeSiteId, this.currentPath)
				this.html = data.html
				this.toc = data.toc || []
			} catch (e) {
				this.error = true; this.html = ''; this.toc = []
			} finally {
				this.loading = false
			}
			// The article is re-created with fresh HTML: add the client-side behaviours.
			await this.$nextTick()
			decorateCallouts(this.$refs.article)
			enableTaskLists(this.$refs.article)
			// Both load their libraries only when the page needs them; not awaited.
			decorateCode(this.$refs.article)
			renderDiagrams(this.$refs.article)
			this.scrollToHash()
		},
		/** Scrolls to the heading named by the URL hash, or to the top of the page. */
		scrollToHash() {
			this.$nextTick(() => {
				const id = this.$route.hash ? this.$route.hash.slice(1) : ''
				const target = (id && headingElement(this.$refs.article, id)) || (!id && this.$refs.page)
				if (target && target.scrollIntoView) {
					target.scrollIntoView({ block: 'start' })
				}
			})
		},
		async goToHeading(id) {
			await this.$router.push({ name: this.$route.name, params: this.$route.params, query: this.$route.query, hash: '#' + id })
			// Same hash again: the route does not change, so scroll explicitly.
			this.scrollToHash()
		},
		revealFolder(path) {
			this.treeState.reveal(path)
			this.$nextTick(() => {
				const el = [...document.querySelectorAll('.mds-tree [data-mds-path]')]
					.find(item => item.getAttribute('data-mds-path') === path)
				if (el && el.scrollIntoView) {
					el.scrollIntoView({ block: 'center', behavior: 'smooth' })
				}
			})
		},
		setTocCollapsed(collapsed) {
			this.prefs.tocCollapsed = collapsed
			this.prefs.save()
		},
		onClick(ev) {
			const a = ev.target.closest('a')
			if (!a) { return }
			const href = a.getAttribute('href') || ''
			if (href.startsWith('#') && href.length > 1) {
				// Heading on this page ([[#Heading]] or [text](#id)).
				ev.preventDefault()
				this.goToHeading(safeDecode(href.slice(1)))
				return
			}
			const prefix = generateUrl(`/apps/markdownsite/s/${this.activeSiteId}/page/`)
			if (href.startsWith(prefix)) {
				ev.preventDefault()
				const [rel, fragment] = splitHash(href.slice(prefix.length))
				this.$router.push({
					name: 'page',
					params: { siteId: this.activeSiteId, path: safeDecode(rel) },
					hash: fragment ? '#' + safeDecode(fragment) : '',
				})
			}
		},
	},
}
</script>

<style scoped>
.mds-navfooter { display: flex; align-items: center; gap: 4px; padding: 8px; }
.mds-navfooter :deep(.button-vue--vue-primary) { flex: 1 1 auto; }
.mds-center { display: flex; justify-content: center; padding: 48px; }
/* Page layout: main column (breadcrumb, article, pager) + sticky outline on wide
   screens. The left padding keeps the breadcrumb clear of the navigation toggle. */
.mds-page { display: flex; justify-content: center; align-items: flex-start; gap: 24px; padding: 0 24px 0 52px; }
.mds-main { flex: 0 1 760px; min-width: 0; padding: 24px 0 96px; }
.mds-toc-wide { flex: 0 0 240px; position: sticky; top: 0; max-height: 100vh; overflow-y: auto; }
.mds-page--toc-collapsed .mds-toc-wide { flex-basis: 44px; }
@media (max-width: 1023px) {
	.mds-toc-wide { display: none; }
}
@media (min-width: 1024px) {
	.mds-toc-narrow { display: none; }
}
.mds-content {
	max-width: 760px;
	margin: 0 auto;
	padding: 16px 0 0;
	line-height: 1.6;
	color: var(--color-main-text);
}
.mds-content :deep([id^='mds-']) { scroll-margin-top: 16px; }
.mds-content :deep(h1) { font-size: 1.9em; margin: 0.4em 0 0.5em; font-weight: 700; }
.mds-content :deep(h2) { font-size: 1.5em; margin: 1.4em 0 0.4em; font-weight: 700; border-bottom: 1px solid var(--color-border); padding-bottom: 0.2em; }
.mds-content :deep(h3) { font-size: 1.2em; margin: 1.2em 0 0.3em; font-weight: 600; }
.mds-content :deep(p) { margin: 0.7em 0; }
.mds-content :deep(ul), .mds-content :deep(ol) { margin: 0.6em 0; padding-left: 1.6em; }
.mds-content :deep(li) { margin: 0.2em 0; }
.mds-content :deep(a) {
	color: var(--mds-link-color, var(--color-primary-element));
	text-decoration: var(--mds-link-decoration, underline);
	font-weight: var(--mds-link-weight, 600);
}
.mds-content :deep(a.markdownsite-broken) { color: var(--color-error); text-decoration: line-through; font-weight: 400; }
.mds-content :deep(img) { max-width: 100%; border-radius: var(--border-radius-large, 8px); border: 1px solid var(--color-border); margin: 0.6em 0; }
.mds-content :deep(blockquote) { border-left: 4px solid var(--color-primary-element); margin: 0.8em 0; padding: 0.2em 0 0.2em 1em; color: var(--color-text-maxcontrast); }
.mds-content :deep(code) { background: var(--color-background-dark); border-radius: 4px; padding: 0.1em 0.4em; font-family: monospace; }
.mds-content :deep(pre) { background: var(--color-background-dark); border-radius: var(--border-radius-large, 8px); padding: 12px 16px; overflow-x: auto; }
.mds-content :deep(pre code) { background: none; padding: 0; }
.mds-content :deep(ul) { list-style: disc; }
.mds-content :deep(ol) { list-style: decimal; }
.mds-content :deep(ul ul) { list-style: circle; }
.mds-content :deep(mark) { background: rgba(255, 208, 0, 0.35); color: inherit; border-radius: 3px; padding: 0.05em 0.2em; }
.mds-content :deep(del) { color: var(--color-text-maxcontrast); }

/* Tables */
/* display:block lets wide tables scroll; width:max-content keeps the frame hugging the columns. */
.mds-content :deep(table) { border-collapse: separate; border-spacing: 0; margin: 0.9em 0; display: block; width: max-content; max-width: 100%; overflow-x: auto; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px); }
.mds-content :deep(th), .mds-content :deep(td) { padding: 7px 12px; text-align: left; border-bottom: 1px solid var(--color-border); border-right: 1px solid var(--color-border); }
.mds-content :deep(th:last-child), .mds-content :deep(td:last-child) { border-right: none; }
.mds-content :deep(tbody tr:last-child td) { border-bottom: none; }
.mds-content :deep(th) { background: var(--color-background-dark); font-weight: 600; }

/* Task lists: "- [ ] item" */
.mds-content :deep(li:has(> input[type='checkbox'])), .mds-content :deep(li:has(> p > input[type='checkbox'])) {
	list-style: none; position: relative; margin-left: -1.3em; padding-left: 1.75em;
}
.mds-content :deep(li > input[type='checkbox']), .mds-content :deep(li > p > input[type='checkbox']) { position: absolute; left: 0; top: 0.28em; }
.mds-content :deep(li > p:has(> input[type='checkbox'])) { margin-top: 0; }
.mds-content :deep(input[type='checkbox']) {
	appearance: none; -webkit-appearance: none;
	width: 1.05em; height: 1.05em; min-height: 0; margin: 0; padding: 0;
	border: 2px solid var(--color-text-maxcontrast); border-radius: 0.3em;
	background: transparent; cursor: pointer;
}
.mds-content :deep(input[type='checkbox']:hover) { border-color: var(--color-primary-element); }
.mds-content :deep(input[type='checkbox']:focus-visible) { outline: 2px solid var(--color-primary-element); outline-offset: 2px; }
.mds-content :deep(input[type='checkbox'][disabled]) { cursor: default; }
.mds-content :deep(input[type='checkbox']:checked) { background: var(--color-primary-element); border-color: var(--color-primary-element); }
.mds-content :deep(input[type='checkbox']:checked)::after {
	content: ''; position: absolute; left: 0.28em; top: 0.07em; width: 0.28em; height: 0.52em;
	border: solid var(--color-primary-element-text, #fff); border-width: 0 2px 2px 0; transform: rotate(45deg);
}
.mds-content :deep(li:has(> input[type='checkbox']:checked)) { color: var(--color-text-maxcontrast); }

/* Callouts: "> [!note] Title" */
.mds-content :deep(.mds-callout) {
	--mds-c: 8, 109, 221;
	--mds-icon: '✏️';
	margin: 1em 0; border-radius: var(--border-radius-large, 8px);
	background: rgba(var(--mds-c), 0.1); border: 1px solid rgba(var(--mds-c), 0.25);
}
.mds-content :deep(.mds-callout-title) {
	display: flex; align-items: center; gap: 0.5em; padding: 0.6em 1em; font-weight: 600;
	color: rgb(var(--mds-c)); list-style: none; cursor: default;
}
.mds-content :deep(.mds-callout-title::-webkit-details-marker) { display: none; }
.mds-content :deep(.mds-callout-icon::before) { content: var(--mds-icon); font-size: 1em; }
.mds-content :deep(.mds-callout-title-text) { flex: 1; color: var(--color-main-text); }
.mds-content :deep(summary.mds-callout-title) { cursor: pointer; }
.mds-content :deep(summary.mds-callout-title::after) {
	content: ''; width: 0.5em; height: 0.5em; border: solid var(--color-text-maxcontrast); border-width: 0 2px 2px 0;
	transform: rotate(45deg); transition: transform 0.15s; margin-right: 0.3em;
}
.mds-content :deep(details.mds-callout[open] > summary.mds-callout-title::after) { transform: rotate(-135deg); }
.mds-content :deep(.mds-callout-actions) { position: relative; flex: none; margin-left: auto; }
.mds-content :deep(.mds-callout-copy) {
	display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0;
	border: none; border-radius: var(--border-radius-element, 8px); background: transparent;
	color: var(--color-text-maxcontrast); cursor: pointer; opacity: 0.7;
}
.mds-content :deep(.mds-callout-copy:hover), .mds-content :deep(.mds-callout-copy:focus-visible), .mds-content :deep(.mds-callout-copy[aria-expanded='true']) { opacity: 1; background: rgba(var(--mds-c), 0.18); color: var(--color-main-text); }
.mds-content :deep(.mds-callout-copy.is-done) { opacity: 1; color: var(--color-success-text, #2d7b41); }
.mds-content :deep(.mds-callout-menu) {
	position: absolute; right: 0; top: calc(100% + 4px); z-index: 10; min-width: 240px; padding: 4px;
	background: var(--color-main-background); border: 1px solid var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large, 8px); box-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
}
.mds-content :deep(.mds-callout-menu[hidden]) { display: none; }
.mds-content :deep(.mds-callout-menu button) {
	display: block; width: 100%; padding: 8px 12px; border: none; background: transparent; text-align: left;
	color: var(--color-main-text); font: inherit; font-weight: 400; border-radius: var(--border-radius-element, 8px); cursor: pointer;
}
.mds-content :deep(.mds-callout-menu button:hover), .mds-content :deep(.mds-callout-menu button:focus-visible) { background: var(--color-background-hover); }
.mds-content :deep(.mds-callout-content) { padding: 0.1em 1em 0.6em; }
.mds-content :deep(.mds-callout-content > :first-child) { margin-top: 0.3em; }
.mds-content :deep(.mds-callout-content > :last-child) { margin-bottom: 0.2em; }
.mds-content :deep(.mds-callout-content table) { background: var(--color-main-background); }
.mds-content :deep(.mds-callout[data-callout='abstract']), .mds-content :deep(.mds-callout[data-callout='summary']), .mds-content :deep(.mds-callout[data-callout='tldr']) { --mds-c: 0, 176, 255; --mds-icon: '📄'; }
.mds-content :deep(.mds-callout[data-callout='info']) { --mds-c: 8, 109, 221; --mds-icon: 'ℹ️'; }
.mds-content :deep(.mds-callout[data-callout='todo']) { --mds-c: 8, 109, 221; --mds-icon: '☑️'; }
.mds-content :deep(.mds-callout[data-callout='tip']), .mds-content :deep(.mds-callout[data-callout='hint']), .mds-content :deep(.mds-callout[data-callout='important']) { --mds-c: 0, 191, 188; --mds-icon: '💡'; }
.mds-content :deep(.mds-callout[data-callout='success']), .mds-content :deep(.mds-callout[data-callout='check']), .mds-content :deep(.mds-callout[data-callout='done']) { --mds-c: 8, 176, 66; --mds-icon: '✅'; }
.mds-content :deep(.mds-callout[data-callout='question']), .mds-content :deep(.mds-callout[data-callout='help']), .mds-content :deep(.mds-callout[data-callout='faq']) { --mds-c: 236, 117, 0; --mds-icon: '❓'; }
.mds-content :deep(.mds-callout[data-callout='warning']), .mds-content :deep(.mds-callout[data-callout='caution']), .mds-content :deep(.mds-callout[data-callout='attention']) { --mds-c: 236, 117, 0; --mds-icon: '⚠️'; }
.mds-content :deep(.mds-callout[data-callout='failure']), .mds-content :deep(.mds-callout[data-callout='fail']), .mds-content :deep(.mds-callout[data-callout='missing']) { --mds-c: 233, 49, 71; --mds-icon: '❌'; }
.mds-content :deep(.mds-callout[data-callout='danger']), .mds-content :deep(.mds-callout[data-callout='error']) { --mds-c: 233, 49, 71; --mds-icon: '⚡'; }
.mds-content :deep(.mds-callout[data-callout='bug']) { --mds-c: 233, 49, 71; --mds-icon: '🐛'; }
.mds-content :deep(.mds-callout[data-callout='example']) { --mds-c: 120, 82, 238; --mds-icon: '📋'; }
.mds-content :deep(.mds-callout[data-callout='quote']), .mds-content :deep(.mds-callout[data-callout='cite']) { --mds-c: 158, 158, 158; --mds-icon: '💬'; }

/* Code blocks: language label, copy button, highlight.js token colours */
.mds-content :deep(.mds-code) {
	margin: 0.8em 0; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-dark); overflow: hidden;
}
.mds-content :deep(.mds-code-header) {
	display: flex; align-items: center; justify-content: space-between; min-height: 32px; padding: 0 4px 0 12px;
	border-bottom: 1px solid var(--color-border); font-size: 0.8em; color: var(--color-text-maxcontrast);
}
.mds-content :deep(.mds-code-lang) { font-family: monospace; }
.mds-content :deep(.mds-code-copy) {
	display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; min-height: 0; padding: 0; margin: 0;
	border: none; border-radius: var(--border-radius-element, 8px); background: transparent;
	color: var(--color-text-maxcontrast); cursor: pointer;
}
.mds-content :deep(.mds-code-copy:hover), .mds-content :deep(.mds-code-copy:focus-visible) { background: var(--color-background-hover); color: var(--color-main-text); }
.mds-content :deep(.mds-code-copy.is-done) { color: var(--color-success-text, #2d7b41); }
.mds-content :deep(.mds-code pre) { margin: 0; border-radius: 0; background: transparent; }
.mds-content :deep(.hljs-comment), .mds-content :deep(.hljs-quote) { color: var(--mds-hl-comment); font-style: italic; }
.mds-content :deep(.hljs-keyword), .mds-content :deep(.hljs-selector-tag), .mds-content :deep(.hljs-doctag), .mds-content :deep(.hljs-section) { color: var(--mds-hl-keyword); }
.mds-content :deep(.hljs-string), .mds-content :deep(.hljs-regexp), .mds-content :deep(.hljs-symbol) { color: var(--mds-hl-string); }
.mds-content :deep(.hljs-number), .mds-content :deep(.hljs-literal), .mds-content :deep(.hljs-variable), .mds-content :deep(.hljs-template-variable) { color: var(--mds-hl-number); }
.mds-content :deep(.hljs-title), .mds-content :deep(.hljs-title.function_), .mds-content :deep(.hljs-name) { color: var(--mds-hl-title); }
.mds-content :deep(.hljs-type), .mds-content :deep(.hljs-built_in), .mds-content :deep(.hljs-title.class_) { color: var(--mds-hl-type); }
.mds-content :deep(.hljs-attr), .mds-content :deep(.hljs-attribute), .mds-content :deep(.hljs-property), .mds-content :deep(.hljs-params) { color: var(--mds-hl-attr); }
.mds-content :deep(.hljs-meta), .mds-content :deep(.hljs-bullet), .mds-content :deep(.hljs-link) { color: var(--mds-hl-meta); }
.mds-content :deep(.hljs-addition) { color: var(--mds-hl-meta); background: var(--mds-hl-addition-bg); }
.mds-content :deep(.hljs-deletion) { color: var(--mds-hl-keyword); background: var(--mds-hl-deletion-bg); }
.mds-content :deep(.hljs-emphasis) { font-style: italic; }
.mds-content :deep(.hljs-strong) { font-weight: 700; }

/* Mermaid diagrams */
.mds-content :deep(.mds-mermaid) { margin: 1em 0; overflow-x: auto; text-align: center; }
.mds-content :deep(.mds-mermaid svg) { max-width: 100%; height: auto; }
.mds-content :deep(.mds-mermaid-error) {
	margin: 1em 0; padding: 8px 12px; border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large, 8px);
}
.mds-content :deep(.mds-mermaid-error-title) { margin: 0 0 6px; color: var(--color-error); font-weight: 600; }
</style>

<style>
/* Syntax colours. Not scoped: they switch on Nextcloud's theme, set on <body>
   (data-themes "dark…", "light…", or "default" = follow the system). */
.mds-content {
	--mds-hl-comment: #6e7781; --mds-hl-keyword: #cf222e; --mds-hl-string: #0a3069; --mds-hl-number: #0550ae;
	--mds-hl-title: #8250df; --mds-hl-type: #953800; --mds-hl-attr: #0550ae; --mds-hl-meta: #116329;
	--mds-hl-addition-bg: #dafbe1; --mds-hl-deletion-bg: #ffebe9;
}
body[data-themes*='dark'] .mds-content {
	--mds-hl-comment: #8b949e; --mds-hl-keyword: #ff7b72; --mds-hl-string: #a5d6ff; --mds-hl-number: #79c0ff;
	--mds-hl-title: #d2a8ff; --mds-hl-type: #ffa657; --mds-hl-attr: #79c0ff; --mds-hl-meta: #7ee787;
	--mds-hl-addition-bg: rgba(46, 160, 67, 0.15); --mds-hl-deletion-bg: rgba(248, 81, 73, 0.15);
}
@media (prefers-color-scheme: dark) {
	body:not([data-themes*='light']):not([data-themes*='dark']) .mds-content {
		--mds-hl-comment: #8b949e; --mds-hl-keyword: #ff7b72; --mds-hl-string: #a5d6ff; --mds-hl-number: #79c0ff;
		--mds-hl-title: #d2a8ff; --mds-hl-type: #ffa657; --mds-hl-attr: #79c0ff; --mds-hl-meta: #7ee787;
		--mds-hl-addition-bg: rgba(46, 160, 67, 0.15); --mds-hl-deletion-bg: rgba(248, 81, 73, 0.15);
	}
}
</style>
