<template>
	<div class="mds-search">
		<NcTextField ref="field"
			v-model="query"
			class="mds-search-field"
			:label="t('markdownsite', 'Search')"
			:placeholder="t('markdownsite', 'Search (Ctrl+Shift+F)')"
			trailing-button-icon="close"
			:trailing-button-label="t('markdownsite', 'Clear search')"
			:show-trailing-button="query !== ''"
			@trailing-button-click="clear"
			@keydown.esc.prevent="clear" />

		<template v-if="active">
			<div class="mds-search-scope" role="radiogroup" :aria-label="t('markdownsite', 'Search in')">
				<NcCheckboxRadioSwitch type="radio" name="mds-search-scope" value="site"
					button-variant button-variant-grouped="horizontal"
					:model-value="prefs.searchScope" @update:model-value="setScope">
					{{ t('markdownsite', 'This site') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch type="radio" name="mds-search-scope" value="all"
					button-variant button-variant-grouped="horizontal"
					:model-value="prefs.searchScope" @update:model-value="setScope">
					{{ t('markdownsite', 'All my sites') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div v-if="loading && !results.length" class="mds-search-state">
				<NcLoadingIcon :size="20" />
				<span>{{ slow ? t('markdownsite', 'Indexing…') : t('markdownsite', 'Searching…') }}</span>
			</div>
			<p v-else-if="error" class="mds-search-state mds-search-state--error">
				{{ t('markdownsite', 'Search failed. Try again.') }}
			</p>
			<p v-else-if="!terms.length" class="mds-search-state">
				{{ t('markdownsite', 'Type at least 2 characters.') }}
			</p>
			<p v-else-if="!results.length" class="mds-search-state">
				{{ t('markdownsite', 'No results') }}
			</p>

			<div v-for="group in groups" :key="group.siteId" class="mds-search-group">
				<h3 v-if="scope === 'all'" class="mds-search-site">{{ group.siteName }}</h3>
				<ul class="mds-search-results">
					<li v-for="result in group.results" :key="result.siteId + ':' + result.path">
						<RouterLink class="mds-search-result" :to="routeTo(result)">
							<span class="mds-search-title">{{ result.title }}</span>
							<span v-if="result.folder" class="mds-search-folder">{{ result.folder }}</span>
							<span class="mds-search-snippet">
								<template v-for="(part, i) in parts(result)" :key="i">
									<mark v-if="part.hit">{{ part.text }}</mark>
									<template v-else>{{ part.text }}</template>
								</template>
							</span>
						</RouterLink>
					</li>
				</ul>
			</div>

			<NcButton v-if="results.length < total" class="mds-search-more" variant="tertiary" wide
				:disabled="loading" @click="more">
				{{ t('markdownsite', 'More results') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'
import { searchPages } from '../services/api.js'
import { parseQuery, snippetParts } from '../services/searchText.js'
import { usePrefsStore } from '../stores/prefs.js'

const DEBOUNCE_MS = 300
const SLOW_MS = 1000

export default {
	name: 'SearchPanel',
	components: { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcTextField },
	props: {
		siteId: { type: [String, Number], default: null },
	},
	emits: ['update:active'],
	setup() { return { prefs: usePrefsStore(), t } },
	data() {
		return { query: '', results: [], total: 0, loading: false, slow: false, error: false }
	},
	computed: {
		active() { return this.query.trim() !== '' },
		terms() { return parseQuery(this.query) },
		scope() { return this.prefs.searchScope === 'all' || !this.siteId ? 'all' : 'site' },
		/** Results grouped by site, groups in order of their best result. */
		groups() {
			const groups = []
			for (const result of this.results) {
				let group = groups.find(g => g.siteId === result.siteId)
				if (!group) {
					group = { siteId: result.siteId, siteName: result.siteName, results: [] }
					groups.push(group)
				}
				group.results.push(result)
			}
			return groups
		},
	},
	watch: {
		query() { this.schedule() },
		active(value) { this.$emit('update:active', value) },
		siteId() { if (this.active && this.scope === 'site') { this.schedule() } },
	},
	mounted() {
		// Capture phase + stopPropagation: Nextcloud's unified search also
		// reacts to Ctrl+(Shift+)F and would take the focus otherwise.
		this.onKeydown = (ev) => {
			if ((ev.ctrlKey || ev.metaKey) && ev.shiftKey && ev.key.toLowerCase() === 'f') {
				ev.preventDefault()
				ev.stopPropagation()
				this.$refs.field?.focus()
			}
		}
		window.addEventListener('keydown', this.onKeydown, true)
	},
	beforeUnmount() {
		window.removeEventListener('keydown', this.onKeydown, true)
		this.cancel()
	},
	methods: {
		parts(result) { return snippetParts(result.snippet, result.highlights) },
		routeTo(result) {
			return { name: 'page', params: { siteId: result.siteId, path: result.path }, query: { q: this.query.trim() } }
		},
		clear() {
			this.query = ''
		},
		setScope(scope) {
			this.prefs.searchScope = scope
			this.prefs.save()
			this.schedule()
		},
		cancel() {
			clearTimeout(this.timer)
			clearTimeout(this.slowTimer)
			this.controller?.abort()
			this.controller = null
		},
		schedule() {
			this.cancel()
			this.error = false
			if (!this.terms.length) {
				this.results = []
				this.total = 0
				this.loading = false
				return
			}
			this.timer = setTimeout(() => this.run(0), DEBOUNCE_MS)
		},
		more() {
			this.run(this.results.length)
		},
		async run(offset) {
			this.cancel()
			const controller = new AbortController()
			this.controller = controller
			this.loading = true
			this.slow = false
			// The first search on a large site indexes it inside the request.
			this.slowTimer = setTimeout(() => { this.slow = true }, SLOW_MS)
			try {
				const params = { q: this.query.trim(), offset }
				if (this.scope === 'site') {
					params.site = this.siteId
				}
				const data = await searchPages(params, controller.signal)
				this.results = offset === 0 ? data.results : [...this.results, ...data.results]
				this.total = data.total
			} catch (e) {
				if (controller.signal.aborted) {
					return
				}
				this.error = true
			} finally {
				if (this.controller === controller) {
					clearTimeout(this.slowTimer)
					this.loading = false
					this.controller = null
				}
			}
		},
	},
}
</script>

<style scoped>
.mds-search { padding: 0 8px 4px; }
.mds-search-scope { display: flex; margin: 6px 0; }
.mds-search-scope :deep(.checkbox-radio-switch) { flex: 1 1 0; }
.mds-search-state { display: flex; align-items: center; gap: 8px; padding: 8px 4px; color: var(--color-text-maxcontrast); }
.mds-search-state--error { color: var(--color-error); }
.mds-search-site { margin: 10px 4px 2px; font-size: 0.85em; font-weight: 600; color: var(--color-text-maxcontrast); text-transform: uppercase; letter-spacing: 0.04em; }
.mds-search-results { list-style: none; margin: 0; padding: 0; }
.mds-search-result {
	display: flex; flex-direction: column; gap: 2px; padding: 6px 8px; border-radius: var(--border-radius-element, 8px);
	color: var(--color-main-text); text-decoration: none;
}
.mds-search-result:hover, .mds-search-result:focus-visible { background: var(--color-background-hover); }
.mds-search-title { font-weight: 600; }
.mds-search-folder { font-size: 0.8em; color: var(--color-text-maxcontrast); }
.mds-search-snippet { font-size: 0.85em; color: var(--color-text-maxcontrast); line-height: 1.35; overflow-wrap: anywhere; }
.mds-search-snippet mark { background: rgba(255, 208, 0, 0.45); color: var(--color-main-text); border-radius: 2px; }
.mds-search-more { margin-top: 6px; }
</style>
