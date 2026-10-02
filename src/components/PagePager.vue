<template>
	<nav v-if="prev || next" class="mds-pager" :aria-label="t('markdownsite', 'Previous and next page')">
		<RouterLink v-if="prev" class="mds-pager-card mds-pager-card--prev" :to="routeTo(prev)">
			<span class="mds-pager-label">{{ t('markdownsite', 'Previous') }}</span>
			<span class="mds-pager-title">← {{ prev.title }}</span>
		</RouterLink>
		<RouterLink v-if="next" class="mds-pager-card mds-pager-card--next" :to="routeTo(next)">
			<span class="mds-pager-label">{{ t('markdownsite', 'Next') }}</span>
			<span class="mds-pager-title">{{ next.title }} →</span>
		</RouterLink>
	</nav>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { flatten, neighbours } from '../services/pageOrder.js'

export default {
	name: 'PagePager',
	props: {
		siteId: { type: [String, Number], required: true },
		path: { type: String, required: true },
		tree: { type: Array, default: () => [] },
	},
	setup() { return { t } },
	computed: {
		around() { return neighbours(flatten(this.tree), this.path) },
		prev() { return this.around.prev },
		next() { return this.around.next },
	},
	methods: {
		routeTo(page) { return { name: 'page', params: { siteId: this.siteId, path: page.path } } },
	},
}
</script>

<style scoped>
.mds-pager { display: flex; gap: 12px; margin-top: 48px; }
.mds-pager-card {
	flex: 1 1 0; max-width: calc(50% - 6px); display: flex; flex-direction: column; gap: 2px; padding: 12px 16px;
	border: 1px solid var(--color-border); border-radius: var(--border-radius-large, 8px);
	color: var(--color-main-text); text-decoration: none;
}
.mds-pager-card:hover { border-color: var(--color-primary-element); background: var(--color-background-hover); }
.mds-pager-card--next { margin-left: auto; text-align: right; }
.mds-pager-label { font-size: 0.85em; color: var(--color-text-maxcontrast); }
.mds-pager-title { font-weight: 600; }
</style>
