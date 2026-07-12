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
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton } from '@nextcloud/vue'
import { getFilePickerBuilder, FilePickerType } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { useSitesStore } from '../stores/sites.js'

export default {
	name: 'SiteList',
	components: { NcAppContent, NcButton },
	setup() {
		return { store: useSitesStore(), t }
	},
	data() {
		return { name: '', pickedFileId: null, pickedPath: '' }
	},
	async mounted() {
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
