<template>
	<NcAppSettingsSection id="links" :name="t('markdownsite', 'Links')">
		<p class="mds-hint">
			{{ t('markdownsite', 'Finds links that point to no page. When one page clearly matches, for example after a page was renamed outside MarkdownSite, the link can be fixed in one click.') }}
		</p>
		<p v-if="!editableSites.length" class="mds-empty">
			{{ t('markdownsite', "You can't edit any wiki site yet.") }}
		</p>
		<div v-for="site in editableSites" :key="site.id" class="mds-links-site">
			<div class="mds-links-head">
				<h4 class="mds-links-name">{{ site.icon || '📄' }} {{ site.name }}</h4>
				<NcButton variant="secondary" :disabled="!!busy[site.id]" @click="check(site)">
					{{ results[site.id] ? t('markdownsite', 'Check again') : t('markdownsite', 'Check links') }}
				</NcButton>
			</div>

			<template v-if="results[site.id]">
				<p v-if="!results[site.id].broken.length" class="mds-links-ok">
					{{ n('markdownsite', 'No broken link in %n page.', 'No broken link in %n pages.', results[site.id].pages) }}
				</p>
				<template v-else>
					<p class="mds-links-summary">
						{{ n('markdownsite', '%n broken link', '%n broken links', results[site.id].broken.length) }}
						<span v-if="results[site.id].truncated">{{ t('markdownsite', '(first ones only — fix these, then check again)') }}</span>
					</p>
					<NcButton v-if="fixable(site).length" variant="primary" :disabled="!!busy[site.id]" @click="fix(site, fixable(site))">
						{{ n('markdownsite', 'Fix the %n link with a match', 'Fix the %n links with a match', fixable(site).length) }}
					</NcButton>
					<ul class="mds-links-list">
						<li v-for="item in results[site.id].broken" :key="item.page + ':' + item.line + ':' + item.offset" class="mds-links-item">
							<span class="mds-links-where">{{ item.page }} · {{ t('markdownsite', 'line {line}', { line: item.line }) }}</span>
							<span class="mds-links-change">
								<code>{{ item.target }}</code>
								<template v-if="item.suggestion">
									<span aria-hidden="true">→</span>
									<code :title="item.suggestionPage">{{ item.suggestion }}</code>
									<NcButton variant="tertiary" :disabled="!!busy[site.id]" @click="fix(site, [item])">
										{{ t('markdownsite', 'Fix') }}
									</NcButton>
								</template>
								<span v-else class="mds-hint">{{ t('markdownsite', 'no match found') }}</span>
							</span>
						</li>
					</ul>
				</template>
			</template>
		</div>
	</NcAppSettingsSection>
</template>

<script>
import NcAppSettingsSection from '@nextcloud/vue/components/NcAppSettingsSection'
import NcButton from '@nextcloud/vue/components/NcButton'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { checkLinks, repairLinks } from '../services/api.js'
import { useSitesStore } from '../stores/sites.js'

async function toast(kind, text) {
	const dialogs = await import('@nextcloud/dialogs')
	dialogs[kind](text)
}

export default {
	name: 'LinkCheckSettings',
	components: { NcAppSettingsSection, NcButton },
	setup() { return { store: useSitesStore(), t, n } },
	data() { return { results: {}, busy: {} } },
	computed: {
		editableSites() { return this.store.sites.filter(s => s.writable) },
	},
	methods: {
		fixable(site) {
			return (this.results[site.id]?.broken ?? []).filter(item => item.suggestion)
		},
		async check(site) {
			this.busy = { ...this.busy, [site.id]: true }
			try {
				this.results = { ...this.results, [site.id]: await checkLinks(site.id) }
			} catch (e) {
				await toast('showError', t('markdownsite', 'Could not check the links'))
			} finally {
				this.busy = { ...this.busy, [site.id]: false }
			}
		},
		async fix(site, items) {
			this.busy = { ...this.busy, [site.id]: true }
			try {
				const fixes = items.map(({ page, line, offset }) => ({ page, line, offset }))
				const { fixed, failed } = await repairLinks(site.id, fixes)
				if (failed.length) {
					await toast('showWarning', n('markdownsite', '%n page not updated: {pages}', '%n pages not updated: {pages}', failed.length, { pages: failed.join(', ') }))
				} else {
					await toast('showSuccess', n('markdownsite', '%n link fixed', '%n links fixed', fixed))
				}
				// Fixing moves later links on the same line: ask again rather than patch the list.
				this.results = { ...this.results, [site.id]: await checkLinks(site.id) }
			} catch (e) {
				await toast('showError', t('markdownsite', 'Could not fix the links'))
			} finally {
				this.busy = { ...this.busy, [site.id]: false }
			}
		},
	},
}
</script>

<style scoped>
.mds-hint { margin: 4px 0 0; font-size: 13px; color: var(--color-text-maxcontrast); }
.mds-empty { color: var(--color-text-maxcontrast); }
.mds-links-site {
	margin: 12px 0 16px;
	padding: 14px 16px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
}
.mds-links-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.mds-links-name { margin: 0; font-size: 15px; }
.mds-links-ok { margin: 10px 0 0; }
.mds-links-summary { margin: 10px 0 8px; font-weight: 600; }
.mds-links-list { margin: 10px 0 0; padding: 0; list-style: none; max-height: 320px; overflow-y: auto; }
.mds-links-item {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 6px 0;
	border-top: 1px solid var(--color-border);
}
.mds-links-where { font-size: 12px; color: var(--color-text-maxcontrast); }
.mds-links-change { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.mds-links-change code { overflow-wrap: anywhere; }
</style>
