<template>
	<NcDialog :open="open"
		:name="t('markdownsite', 'New wiki site')"
		size="normal"
		@update:open="v => $emit('update:open', v)">
		<div class="mds-newsite">
			<NcTextField v-model="name" :label="t('markdownsite', 'Site name')" />
			<div class="mds-folder">
				<NcButton @click="pickFolder">{{ t('markdownsite', 'Choose folder…') }}</NcButton>
				<span v-if="pickedPath" class="mds-path">{{ pickedPath }}</span>
			</div>
		</div>
		<template #actions>
			<NcButton variant="primary" :disabled="!name || !pickedFileId" @click="create">
				{{ t('markdownsite', 'Create') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcButton from '@nextcloud/vue/components/NcButton'
import { getFilePickerBuilder, FilePickerType } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { useSitesStore } from '../stores/sites.js'

export default {
	name: 'NewSiteDialog',
	components: { NcDialog, NcTextField, NcButton },
	props: { open: { type: Boolean, default: false } },
	emits: ['update:open'],
	setup() { return { store: useSitesStore(), t } },
	data() { return { name: '', pickedFileId: null, pickedPath: '' } },
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
			const site = await this.store.add({ name: this.name, rootFileId: this.pickedFileId, rootHintPath: this.pickedPath })
			this.name = ''; this.pickedFileId = null; this.pickedPath = ''
			this.$emit('update:open', false)
			this.$router.push({ name: 'site', params: { siteId: site.id } })
		},
	},
}
</script>

<style scoped>
.mds-newsite { display: flex; flex-direction: column; gap: 16px; padding: 8px 0; }
.mds-folder { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.mds-path { color: var(--color-text-maxcontrast); font-size: 0.9em; word-break: break-all; }
</style>
