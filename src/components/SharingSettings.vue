<template>
	<NcAppSettingsSection id="sharing" :name="t('markdownsite', 'Sharing')">
		<p v-if="!ownedSites.length" class="mds-empty">
			{{ t('markdownsite', "You don't own any wiki sites yet.") }}
		</p>
		<div v-for="site in ownedSites" :key="site.id" class="mds-share-site">
			<h4>{{ site.icon || '📄' }} {{ site.name }}</h4>
			<table class="mds-share-table">
				<thead>
					<tr>
						<th>{{ t('markdownsite', 'Type') }}</th>
						<th>{{ t('markdownsite', 'Shared with') }}</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<tr v-if="!(shareMap[site.id] || []).length">
						<td colspan="3" class="mds-empty">{{ t('markdownsite', 'No shares yet.') }}</td>
					</tr>
					<tr v-for="(s, i) in shareMap[site.id] || []" :key="s.type + ':' + s.with">
						<td>{{ s.type === 'group' ? t('markdownsite', 'Group') : t('markdownsite', 'User') }}</td>
						<td>{{ s.with }}</td>
						<td>
							<NcButton type="tertiary" @click="removeShare(site, i)">
								{{ t('markdownsite', 'Unshare') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
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
import NcButton from '@nextcloud/vue/components/NcButton'
import { translate as t } from '@nextcloud/l10n'
import { useSitesStore } from '../stores/sites.js'
import { getShares, searchSharees, shareSite } from '../services/api.js'

export default {
	name: 'SharingSettings',
	components: { NcAppSettingsSection, NcSelect, NcButton },
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
.mds-share-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.mds-share-table th {
	text-align: left; font-weight: 600; color: var(--color-text-maxcontrast);
	font-size: 0.85em; padding: 4px 8px; border-bottom: 1px solid var(--color-border);
}
.mds-share-table td {
	padding: 4px 8px; border-bottom: 1px solid var(--color-border);
}
.mds-share-table td.mds-empty { padding: 10px 8px; }
</style>
