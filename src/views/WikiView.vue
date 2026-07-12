<template>
	<NcContent app-name="markdownsite">
		<NcAppNavigation>
			<RouterLink :to="{ name: 'sites' }">← {{ t('markdownsite', 'All sites') }}</RouterLink>
			<PageTree :nodes="tree" :site-id="siteId" />
		</NcAppNavigation>
		<NcAppContent>
			<div v-if="error" class="mdsite-error">{{ error }}</div>
			<article v-else class="mdsite-content" v-html="html" @click="onClick" />
		</NcAppContent>
	</NcContent>
</template>

<script>
import { NcContent, NcAppNavigation, NcAppContent } from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getTree, getPage } from '../services/api.js'
import PageTree from '../components/PageTree.vue'

export default {
	name: 'WikiView',
	components: { NcContent, NcAppNavigation, NcAppContent, PageTree },
	props: { siteId: { type: [String, Number], required: true }, path: { type: String, default: '' } },
	setup() { return { t } },
	data() { return { tree: [], html: '', error: '' } },
	watch: { path: 'loadPage', siteId: 'init' },
	async mounted() { await this.init() },
	methods: {
		async init() {
			const data = await getTree(this.siteId)
			this.tree = data.tree
			if (!this.path && data.home) {
				this.$router.replace({ name: 'page', params: { siteId: this.siteId, path: data.home } })
				return
			}
			await this.loadPage()
		},
		async loadPage() {
			if (!this.path) { return }
			this.error = ''
			try {
				const data = await getPage(this.siteId, this.path)
				this.html = data.html
			} catch (e) {
				this.error = t('markdownsite', 'Page not found')
				this.html = ''
			}
		},
		onClick(ev) {
			const a = ev.target.closest('a')
			if (!a) { return }
			const href = a.getAttribute('href') || ''
			const prefix = generateUrl(`/apps/markdownsite/s/${this.siteId}/page/`)
			if (href.startsWith(prefix)) {
				ev.preventDefault()
				const rel = decodeURIComponent(href.slice(prefix.length))
				this.$router.push({ name: 'page', params: { siteId: this.siteId, path: rel } })
			}
			// asset links and external links fall through to the browser
		},
	},
}
</script>

<style scoped>
.mdsite-content { max-width: 820px; margin: 0 auto; padding: 24px; }
.mdsite-content :deep(.markdownsite-broken) { color: var(--color-error); text-decoration: line-through; }
.mdsite-content :deep(img) { max-width: 100%; }
</style>
