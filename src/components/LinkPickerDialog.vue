<template>
	<NcDialog :name="t('markdownsite', 'Link to a page')" size="small" @update:open="v => !v && $emit('close', null)">
		<div class="mds-link-picker" @keydown="onKey">
			<NcTextField ref="field" v-model="query" :label="t('markdownsite', 'Search a page')" />
			<ul ref="list" class="mds-link-picker-list" role="listbox">
				<li
					v-for="(page, i) in results"
					:key="page.path"
					role="option"
					:aria-selected="i === active"
					class="mds-link-picker-item"
					:class="{ 'is-active': i === active }"
					@mousemove="active = i"
					@click="choose(page)">
					<span class="mds-link-picker-title">{{ page.title }}</span>
					<span class="mds-link-picker-folder">{{ folderOf(page) }}</span>
				</li>
				<li v-if="!results.length" class="mds-link-picker-empty">{{ t('markdownsite', 'No page found') }}</li>
			</ul>
		</div>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close', null)">{{ t('markdownsite', 'Cancel') }}</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'
import { filterPages, linkTarget, titleCounts } from '../services/pageLinks.js'
import { parentOf } from '../services/fileOps.js'

/** Picks the page a link should point to. Closes with the `[[...]]` target, or null. */
export default {
	name: 'LinkPickerDialog',
	components: { NcButton, NcDialog, NcTextField },
	props: {
		pages: { type: Array, required: true },
		selection: { type: String, default: '' },
	},
	emits: ['close'],
	setup() { return { t } },
	data() { return { query: '', active: 0 } },
	computed: {
		results() { return filterPages(this.pages, this.query, this.selection) },
	},
	watch: {
		query() { this.active = 0 },
	},
	mounted() { this.$nextTick(() => this.$refs.field?.focus()) },
	methods: {
		folderOf: page => parentOf(page.path),
		choose(page) {
			this.$emit('close', linkTarget(page, titleCounts(this.pages)))
		},
		onKey(event) {
			const count = this.results.length
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				if (count) {
					this.active = (this.active + (event.key === 'ArrowDown' ? 1 : count - 1)) % count
					this.$nextTick(() => this.$refs.list?.querySelector('.is-active')?.scrollIntoView({ block: 'nearest' }))
				}
			} else if (event.key === 'Enter' && this.results[this.active]) {
				event.preventDefault()
				this.choose(this.results[this.active])
			}
		},
	},
}
</script>

<style scoped>
.mds-link-picker { padding: 8px 0; }
.mds-link-picker-list { max-height: 40vh; margin: 8px 0 0; padding: 0; overflow-y: auto; list-style: none; }
.mds-link-picker-item {
	display: flex; align-items: baseline; justify-content: space-between; gap: 12px;
	padding: 8px 12px; border-radius: var(--border-radius-large, 8px); cursor: pointer;
}
.mds-link-picker-item.is-active { background: var(--color-background-hover); }
.mds-link-picker-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mds-link-picker-folder { flex: none; max-width: 50%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--color-text-maxcontrast); font-size: 0.9em; }
.mds-link-picker-empty { padding: 12px; color: var(--color-text-maxcontrast); }
</style>
