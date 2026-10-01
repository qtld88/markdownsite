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

			<article v-else ref="article" class="mds-content" :style="prefs.cssVars" v-html="html" @click="onClick" />
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
import { useSitesStore } from '../stores/sites.js'
import { usePrefsStore } from '../stores/prefs.js'
import SiteSwitcher from '../components/SiteSwitcher.vue'
import PageTree from '../components/PageTree.vue'
import NewSiteDialog from '../components/NewSiteDialog.vue'
import SettingsDialog from '../components/SettingsDialog.vue'

export default {
	name: 'WikiView',
	components: {
		NcContent, NcAppNavigation, NcAppContent, NcButton, NcEmptyContent, NcLoadingIcon, NcIconSvgWrapper,
		SiteSwitcher, PageTree, NewSiteDialog, SettingsDialog,
	},
	setup() {
		return {
			store: useSitesStore(),
			prefs: usePrefsStore(),
			t,
			mdiPlus, mdiCog, mdiBookOpenVariant, mdiFileDocumentOutline, mdiFileRemoveOutline,
		}
	},
	data() { return { tree: [], html: '', error: false, loading: false, showNew: false, showSettings: false, homePath: null } },
	computed: {
		routeSiteId() { return this.$route.params.siteId || null },
		activeSiteId() { return this.routeSiteId || (this.store.current && this.store.current.id) || null },
		currentPath() {
			const p = this.$route.params.path
			return Array.isArray(p) ? p.join('/') : (p || '')
		},
	},
	watch: {
		'$route': 'sync',
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
			} catch (e) {
				this.error = true; this.html = ''
			} finally {
				this.loading = false
			}
		},
		onClick(ev) {
			const a = ev.target.closest('a')
			if (!a) { return }
			const href = a.getAttribute('href') || ''
			const prefix = generateUrl(`/apps/markdownsite/s/${this.activeSiteId}/page/`)
			if (href.startsWith(prefix)) {
				ev.preventDefault()
				const rel = decodeURIComponent(href.slice(prefix.length))
				this.$router.push({ name: 'page', params: { siteId: this.activeSiteId, path: rel } })
			}
		},
	},
}
</script>

<style scoped>
.mds-navfooter { display: flex; align-items: center; gap: 4px; padding: 8px; }
.mds-navfooter :deep(.button-vue--vue-primary) { flex: 1 1 auto; }
.mds-center { display: flex; justify-content: center; padding: 48px; }
.mds-content {
	max-width: 760px;
	margin: 0 auto;
	padding: 32px 24px 96px;
	line-height: 1.6;
	color: var(--color-main-text);
}
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
.mds-content :deep(table) { border-collapse: separate; border-spacing: 0; margin: 0.9em 0; max-width: 100%; display: block; overflow-x: auto; border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px); }
.mds-content :deep(th), .mds-content :deep(td) { padding: 7px 12px; text-align: left; border-bottom: 1px solid var(--color-border); border-right: 1px solid var(--color-border); }
.mds-content :deep(th:last-child), .mds-content :deep(td:last-child) { border-right: none; }
.mds-content :deep(tbody tr:last-child td) { border-bottom: none; }
.mds-content :deep(th) { background: var(--color-background-dark); font-weight: 600; }

/* Task lists: "- [ ] item" */
.mds-content :deep(li:has(> input[type='checkbox'])) { list-style: none; margin-left: -1.3em; display: flex; align-items: baseline; gap: 0.55em; }
.mds-content :deep(input[type='checkbox']) {
	appearance: none; -webkit-appearance: none;
	flex: none; width: 1.05em; height: 1.05em; margin: 0; position: relative; top: 0.15em;
	border: 2px solid var(--color-text-maxcontrast); border-radius: 0.3em;
	background: transparent; cursor: default;
}
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
</style>
