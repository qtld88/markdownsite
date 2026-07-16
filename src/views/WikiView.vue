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

			<article v-else class="mds-content" :style="prefs.cssVars" v-html="html" @click="onClick" />
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
.mds-content :deep(table) { border-collapse: collapse; margin: 0.8em 0; }
.mds-content :deep(th), .mds-content :deep(td) { border: 1px solid var(--color-border); padding: 6px 10px; }
</style>
