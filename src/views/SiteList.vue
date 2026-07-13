<template>
	<NcAppContent>
		<div class="site-list">
			<h2>{{ t('markdownsite', 'Your wiki sites') }}</h2>
			<ul>
				<li v-for="s in store.sites" :key="s.id">
					<RouterLink :to="{ name: 'page', params: { siteId: s.id } }">
						{{ s.icon || '📄' }} {{ s.name }}
					</RouterLink>
					<NcButton type="tertiary" @click="remove(s.id)">
						{{ t('markdownsite', 'Delete') }}
					</NcButton>
				</li>
			</ul>

			<h3>{{ t('markdownsite', 'Create a site from a folder') }}</h3>
			<input v-model="name" :placeholder="t('markdownsite', 'Site name')" >
			<NcButton @click="pickFolder">{{ t('markdownsite', 'Choose folder…') }}</NcButton>
			<span v-if="pickedPath">{{ pickedPath }}</span>
			<NcButton type="primary" :disabled="!name || !pickedFileId" @click="create">
				{{ t('markdownsite', 'Create') }}
			</NcButton>

			<h3>{{ t('markdownsite', 'Link appearance') }}</h3>
			<div class="pref-row">
				<label>
					<input type="checkbox" v-model="prefs.linkUnderline" >
					{{ t('markdownsite', 'Underline links') }}
				</label>
			</div>
			<div class="pref-row">
				<label>
					<input type="checkbox" v-model="prefs.linkBold" >
					{{ t('markdownsite', 'Bold links') }}
				</label>
			</div>
			<div class="pref-row">
				<label>
					<input type="color" :value="prefs.linkColor || '#0082c9'" @input="prefs.linkColor = $event.target.value" >
					{{ t('markdownsite', 'Link colour') }}
				</label>
				<NcButton type="tertiary" @click="prefs.linkColor = ''">
					{{ t('markdownsite', 'Use theme colour') }}
				</NcButton>
			</div>
			<a class="mds-preview" :style="prefs.cssVars">{{ t('markdownsite', 'Preview link') }}</a>
			<NcButton type="secondary" @click="prefs.save()">
				{{ t('markdownsite', 'Save appearance') }}
			</NcButton>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton } from '@nextcloud/vue'
import { getFilePickerBuilder, FilePickerType } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { useSitesStore } from '../stores/sites.js'
import { usePrefsStore } from '../stores/prefs.js'

export default {
	name: 'SiteList',
	components: { NcAppContent, NcButton },
	setup() {
		return { store: useSitesStore(), prefs: usePrefsStore(), t }
	},
	data() {
		return { name: '', pickedFileId: null, pickedPath: '' }
	},
	async mounted() {
		this.prefs.load()
		if (!this.store.loaded) { await this.store.load() }
	},
	methods: {
		async pickFolder() {
			const picker = getFilePickerBuilder(t('markdownsite', 'Pick the wiki folder'))
				.setMimeTypeFilter(['httpd/unix-directory'])
				.allowDirectories(true)
				.setType(FilePickerType.Choose)
				.build()
			const nodes = await picker.pickNodes()
			const node = Array.isArray(nodes) ? nodes[0] : nodes
			if (!node) { return }
			this.pickedPath = node.path
			this.pickedFileId = node.fileid ?? null
		},
		async create() {
			await this.store.add({ name: this.name, rootFileId: this.pickedFileId, rootHintPath: this.pickedPath })
			this.name = ''; this.pickedFileId = null; this.pickedPath = ''
		},
		async remove(id) { await this.store.remove(id) },
	},
}
</script>

<style scoped>
.site-list { max-width: 640px; margin: 0 auto; padding: 24px; }
.site-list h3 { margin-top: 24px; }
.pref-row { display: flex; align-items: center; gap: 12px; margin: 8px 0; }
.pref-row label { display: flex; align-items: center; gap: 8px; }
.mds-preview {
	display: inline-block;
	margin: 8px 0;
	color: var(--mds-link-color, var(--color-primary-element));
	text-decoration: var(--mds-link-decoration, underline);
	font-weight: var(--mds-link-weight, 600);
}
</style>
