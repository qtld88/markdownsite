<template>
	<NcAppSettingsSection id="sharing" :name="t('markdownsite', 'Sharing')">
		<p v-if="!ownedSites.length" class="mds-empty">
			{{ t('markdownsite', "You don't own any wiki sites yet.") }}
		</p>
		<div v-for="site in ownedSites" :key="site.id" class="mds-share-site">
			<h4>{{ site.icon || '📄' }} {{ site.name }}</h4>
			<div class="mds-chips">
				<span v-for="(s, i) in shareMap[site.id] || []" :key="s.type + ':' + s.with" class="mds-chip">
					{{ s.type === 'group' ? '👥' : '👤' }} {{ s.with }}
					<button type="button" class="mds-chip-remove"
						:aria-label="t('markdownsite', 'Remove')"
						@click="removeShare(site, i)">×</button>
				</span>
			</div>
			<NcSelect
				:model-value="null"
				:options="optionsFor(site.id)"
				:loading="loadingFor(site.id)"
				label="label"
				:placeholder="t('markdownsite', 'Share with user or group…')"
				@search="q => onSearch(site.id, q)"
				@update:model-value="opt => addShare(site, opt)" />
		</div>
	</NcAppSettingsSection>
</template>

<script>
import NcAppSettingsSection from '@nextcloud/vue/components/NcAppSettingsSection'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { translate as t } from '@nextcloud/l10n'
import { useSitesStore } from '../stores/sites.js'
import { getShares, searchSharees, shareSite } from '../services/api.js'

export default {
	name: 'SharingSettings',
	components: { NcAppSettingsSection, NcSelect },
	setup() { return { store: useSitesStore(), t } },
	data() {
		return { shareMap: {}, searchResults: {}, searching: {} }
	},
	computed: {
		ownedSites() { return this.store.sites.filter(s => s.isOwner) },
	},
	watch: {
		ownedSites: {
			immediate: true,
			handler(sites) {
				sites.forEach(s => { if (!(s.id in this.shareMap)) { this.loadShares(s.id) } })
			},
		},
	},
	methods: {
		async loadShares(siteId) {
			this.shareMap = { ...this.shareMap, [siteId]: await getShares(siteId) }
		},
		optionsFor(siteId) {
			return this.searchResults[siteId] || []
		},
		loadingFor(siteId) {
			return !!this.searching[siteId]
		},
		async onSearch(siteId, query) {
			if (!query || query.length < 2) {
				this.searchResults = { ...this.searchResults, [siteId]: [] }
				return
			}
			this.searching = { ...this.searching, [siteId]: true }
			try {
				const results = await searchSharees(query)
				const current = this.shareMap[siteId] || []
				const filtered = results.filter(r => !current.some(c => c.type === r.type && c.with === r.id))
				this.searchResults = {
					...this.searchResults,
					[siteId]: filtered.map(r => ({
						id: r.id,
						type: r.type,
						label: `${r.type === 'group' ? '👥' : '👤'} ${r.label}`,
					})),
				}
			} finally {
				this.searching = { ...this.searching, [siteId]: false }
			}
		},
		async addShare(site, opt) {
			if (!opt) { return }
			const current = this.shareMap[site.id] || []
			const next = [...current, { type: opt.type, with: opt.id }]
			await this.persist(site, next)
		},
		async removeShare(site, index) {
			const current = this.shareMap[site.id] || []
			const next = current.filter((_, i) => i !== index)
			await this.persist(site, next)
		},
		async persist(site, shares) {
			try {
				await shareSite(site.id, shares)
				this.shareMap = { ...this.shareMap, [site.id]: shares }
				const { showSuccess } = await import('@nextcloud/dialogs')
				showSuccess(t('markdownsite', 'Sharing updated'))
			} catch (e) {
				const { showError } = await import('@nextcloud/dialogs')
				showError(t('markdownsite', 'Could not update sharing'))
			}
		},
	},
}
</script>

<style scoped>
.mds-empty { color: var(--color-text-maxcontrast); }
.mds-share-site { margin: 12px 0 20px; }
.mds-share-site h4 { margin: 0 0 6px; }
.mds-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.mds-chip {
	display: inline-flex; align-items: center; gap: 4px;
	background: var(--color-background-dark); border-radius: var(--border-radius-pill, 16px);
	padding: 2px 6px 2px 10px; font-size: 0.9em;
}
.mds-chip-remove {
	border: none; background: none; cursor: pointer; color: var(--color-text-maxcontrast);
	font-size: 1.1em; line-height: 1; padding: 0 4px;
}
</style>
