<template>
	<NcContent app-name="markdownsite">
		<NcAppNavigation>
			<template v-if="store.sites.length">
				<SiteSwitcher />
				<SearchPanel :site-id="activeSiteId" @update:active="v => searching = v" />
				<div v-if="writable && !searching" class="mds-tree-toolbar">
					<NcButton variant="tertiary" @click="newRootPage">
						<template #icon><NcIconSvgWrapper :path="mdiPlus" :size="20" /></template>
						{{ t('markdownsite', 'New page') }}
					</NcButton>
				</div>
				<PageTree v-if="activeSiteId && !searching" :nodes="tree" :site-id="activeSiteId"
					:active-path="prefs.revealActive ? currentPath : ''"
					:editable="writable"
					@changed="onTreeChanged" />
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
					<div class="mds-page-header">
						<PageBreadcrumb :site-id="activeSiteId"
							:site-name="store.current ? store.current.name : ''"
							:path="currentPath"
							:tree="tree"
							@reveal="revealFolder" />
						<NcButton v-if="writable && !editing"
							variant="tertiary"
							:aria-label="t('markdownsite', 'Edit (E)')"
							:title="t('markdownsite', 'Edit (E)')"
							@click="startEditing">
							<template #icon><NcIconSvgWrapper :path="mdiPencil" :size="20" /></template>
						</NcButton>
						<NcButton v-if="editing" variant="secondary" @click="stopEditing">
							{{ t('markdownsite', 'Done') }}
						</NcButton>
					</div>
					<PageEditor v-if="editing"
						ref="editor"
						:key="currentPath"
						:site-id="activeSiteId"
						:path="currentPath"
						:pages="pages"
						:css-vars="prefs.cssVars"
						@exit="stopEditing"
						@follow="followLink"
						@forbidden="onEditingForbidden" />
					<template v-else>
						<PageToc class="mds-toc-narrow" variant="inline" :toc="toc" @navigate="goToHeading" />
						<article ref="article" class="mds-content" :style="prefs.cssVars" v-html="html" @click="onClick" />
					</template>
					<PagePager :site-id="activeSiteId" :path="currentPath" :tree="tree" />
				</div>
				<PageToc v-if="!editing"
					class="mds-toc-wide"
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
import { mdiPlus, mdiCog, mdiBookOpenVariant, mdiFileDocumentOutline, mdiFileRemoveOutline, mdiPencil } from '@mdi/js'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getTree, getPage } from '../services/api.js'
import { decorateCallouts } from '../services/calloutCopy.js'
import { copy } from '../services/clipboard.js'
import { movedPath, newPage, parentOf } from '../services/fileOps.js'
import { decorateCode } from '../services/codeHighlight.js'
import { renderDiagrams } from '../services/mermaid.js'
import { highlightTerms } from '../services/searchHighlight.js'
import { parseQuery } from '../services/searchText.js'
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
import PageEditor from '../components/PageEditor.vue'
import SearchPanel from '../components/SearchPanel.vue'
import NewSiteDialog from '../components/NewSiteDialog.vue'
import SettingsDialog from '../components/SettingsDialog.vue'

export default {
	name: 'WikiView',
	components: {
		NcContent, NcAppNavigation, NcAppContent, NcButton, NcEmptyContent, NcLoadingIcon, NcIconSvgWrapper,
		SiteSwitcher, SearchPanel, PageTree, PageToc, PageBreadcrumb, PagePager, PageEditor, NewSiteDialog, SettingsDialog,
	},
	setup() {
		return {
			store: useSitesStore(),
			prefs: usePrefsStore(),
			treeState: useTreeStore(),
			t,
			mdiPlus, mdiCog, mdiBookOpenVariant, mdiFileDocumentOutline, mdiFileRemoveOutline, mdiPencil,
		}
	},
	data() { return { tree: [], html: '', toc: [], searching: false, editing: false, editAfterLoad: null, pages: [], error: false, loading: false, showNew: false, showSettings: false, homePath: null } },
	computed: {
		routeSiteId() { return this.$route.params.siteId || null },
		activeSiteId() { return this.routeSiteId || (this.store.current && this.store.current.id) || null },
		currentPath() {
			const p = this.$route.params.path
			return Array.isArray(p) ? p.join('/') : (p || '')
		},
		/** Owner or editor, on a folder the owner can write to. */
		writable() { return !!(this.store.current && this.store.current.writable) },
	},
	watch: {
		async $route(to, from) {
			// Only the #heading changed (TOC click, back/forward): scroll, don't reload.
			if (from && to.path === from.path && to.hash !== from.hash) {
				this.scrollToHash()
				return
			}
			if (this.editing) {
				// Leaving the page while editing: save first.
				await this.$refs.editor?.flush()
				this.editing = false
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
	beforeUnmount() {
		window.removeEventListener('keydown', this.onKeydown)
	},
	async mounted() {
		// "E" switches to edit mode (not while typing somewhere).
		this.onKeydown = (ev) => {
			if (ev.key !== 'e' || ev.ctrlKey || ev.metaKey || ev.altKey || this.editing || !this.writable || !this.currentPath) {
				return
			}
			if (ev.target.closest?.('input, textarea, select, [contenteditable="true"], .cm-editor, [role="dialog"]')) {
				return
			}
			ev.preventDefault()
			this.startEditing()
		}
		window.addEventListener('keydown', this.onKeydown)
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
				this.pages = data.pages || []
			} catch (e) {
				this.tree = []; this.homePath = null; this.pages = []
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
			if (this.editAfterLoad === this.currentPath) {
				// A page just created from the tree opens in edit mode.
				this.editAfterLoad = null
				this.editing = true
				return
			}
			// Opened from a search result: mark the terms and show the first one.
			const q = this.$route.query.q
			const hit = q ? highlightTerms(this.$refs.article, parseQuery(String(q))) : null
			if (hit && !this.$route.hash) {
				this.$nextTick(() => hit.scrollIntoView({ block: 'center' }))
			} else {
				this.scrollToHash()
			}
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
		startEditing() {
			this.editing = true
		},
		/** Saves, leaves edit mode and shows the rendered page again. */
		async stopEditing() {
			await this.$refs.editor?.flush()
			this.editing = false
			await this.loadPage()
		},
		/** Ctrl/Cmd+click on a link in the editor: save, then follow it. */
		async followLink(link) {
			await this.$refs.editor?.flush()
			if (link.href && /^[a-z][a-z0-9+.-]*:/i.test(link.href)) {
				window.open(link.href, '_blank', 'noopener')
				return
			}
			const path = link.wikilink ? this.findPage(link.wikilink) : this.relativePage(link.href)
			if (path) {
				this.editing = false
				this.$router.push({ name: 'page', params: { siteId: this.activeSiteId, path } })
			}
		},
		/** Page a wikilink target names: full path, alias, then title. */
		findPage(target) {
			const name = safeDecode(target.split('#')[0]).trim().replace(/\.md$/i, '').toLowerCase()
			const noExt = p => p.path.replace(/\.md$/i, '').toLowerCase()
			const page = this.pages.find(p => noExt(p) === name)
				|| this.pages.find(p => (p.aliases || []).some(a => a.toLowerCase() === name))
				|| this.pages.find(p => p.title.toLowerCase() === name)
			return page ? page.path : null
		},
		/** Root-relative path of a relative Markdown link from the current page. */
		relativePage(href) {
			const parts = parentOf(this.currentPath).split('/').filter(Boolean)
			for (const segment of safeDecode(splitHash(href)[0]).split('/')) {
				if (segment === '..') {
					parts.pop()
				} else if (segment && segment !== '.') {
					parts.push(segment)
				}
			}
			return parts.join('/') || null
		},
		async onEditingForbidden(text) {
			this.editing = false
			this.store.load()
			const { getDialogBuilder, showSuccess } = await import('@nextcloud/dialogs')
			await getDialogBuilder(t('markdownsite', 'Editing rights removed'))
				.setText(t('markdownsite', 'You can no longer edit this page. Copy your unsaved text to keep it.'))
				.addButton({
					label: t('markdownsite', 'Copy my text'),
					variant: 'primary',
					callback: async () => {
						if (await copy(text)) {
							showSuccess(t('markdownsite', 'Copied'))
						}
					},
				})
				.build()
				.show()
		},
		async newRootPage() {
			const path = await newPage(this.activeSiteId, '')
			if (path) {
				await this.onTreeChanged({ edit: path })
			}
		},
		/** After a tree operation: reload the tree and follow the open page. */
		async onTreeChanged(change) {
			await this.loadTree()
			if (change.edit) {
				this.editAfterLoad = change.edit
				this.$router.push({ name: 'page', params: { siteId: this.activeSiteId, path: change.edit } })
			} else if (change.moved) {
				const next = movedPath(this.currentPath, change.moved.from, change.moved.to)
				if (next !== this.currentPath) {
					this.$router.replace({ name: 'page', params: { siteId: this.activeSiteId, path: next } })
				}
			} else if (change.deleted) {
				const gone = this.currentPath === change.deleted || this.currentPath.startsWith(change.deleted + '/')
				if (gone) {
					this.$router.push({ name: 'site', params: { siteId: this.activeSiteId } })
				}
			}
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
.mds-tree-toolbar { display: flex; padding: 0 8px; }
.mds-page-header { display: flex; align-items: center; gap: 8px; min-height: 44px; }
.mds-page-header .mds-crumbs { flex: 1 1 auto; min-width: 0; }
/* Page layout: main column (breadcrumb, article, pager) + sticky outline on wide
   screens. The left padding keeps the breadcrumb clear of the navigation toggle. */
/* --mds-measure caps the text width (article and editor). Collapsing the outline
   hands its 196px to the text, so the page keeps the same overall footprint. */
.mds-page { --mds-measure: 760px; display: flex; justify-content: center; align-items: flex-start; gap: 24px; padding: 0 24px 0 52px; }
.mds-main { flex: 0 1 var(--mds-measure); min-width: 0; padding: 24px 0 96px; }
.mds-toc-wide { flex: 0 0 240px; position: sticky; top: 0; max-height: 100vh; overflow-y: auto; }
.mds-page--toc-collapsed .mds-toc-wide { flex-basis: 44px; }
@media (max-width: 1023px) {
	.mds-toc-wide { display: none; }
}
@media (min-width: 1024px) {
	.mds-toc-narrow { display: none; }
	.mds-page--toc-collapsed { --mds-measure: 956px; }
}
</style>
