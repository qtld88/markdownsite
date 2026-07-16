<template>
	<div class="mds-switcher">
		<NcSelect
			:options="options"
			:model-value="selected"
			:clearable="false"
			:placeholder="t('markdownsite', 'Select a wiki')"
			label="label"
			@update:model-value="onSelect" />
		<NcActions :aria-label="t('markdownsite', 'Site actions')">
			<NcActionButton :close-after-click="true" @click="confirmDelete">
				<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="20" /></template>
				{{ t('markdownsite', 'Delete site') }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiDelete } from '@mdi/js'
import { translate as t } from '@nextcloud/l10n'
import { useSitesStore } from '../stores/sites.js'

export default {
	name: 'SiteSwitcher',
	components: { NcSelect, NcActions, NcActionButton, NcIconSvgWrapper },
	setup() { return { store: useSitesStore(), t, mdiDelete } },
	computed: {
		options() { return this.store.sites.map(s => ({ id: s.id, label: `${s.icon || '📄'} ${s.name}` })) },
		selected() { const c = this.store.current; return c ? { id: c.id, label: `${c.icon || '📄'} ${c.name}` } : null },
	},
	methods: {
		onSelect(opt) {
			if (!opt) { return }
			this.$router.push({ name: 'site', params: { siteId: opt.id } })
		},
		async confirmDelete() {
			const c = this.store.current
			if (!c) { return }
			const { showConfirmation } = await import('@nextcloud/dialogs')
			const ok = await showConfirmation({
				name: t('markdownsite', 'Delete site'),
				text: t('markdownsite', 'Delete this wiki site? The underlying files are not touched.'),
			})
			if (!ok) { return }
			await this.store.remove(c.id)
			this.$router.push({ name: 'home' })
		},
	},
}
</script>

<style scoped>
.mds-switcher { display: flex; align-items: center; gap: 4px; padding: 8px; }
.mds-switcher :deep(.v-select) { flex: 1 1 auto; min-width: 0; }
</style>
