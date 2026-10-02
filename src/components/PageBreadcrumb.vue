<template>
	<nav class="mds-crumbs" :aria-label="t('markdownsite', 'Breadcrumb')">
		<ol>
			<li class="mds-crumb">
				<RouterLink :to="{ name: 'site', params: { siteId } }">{{ siteName }}</RouterLink>
			</li>
			<li v-if="hasMiddle" class="mds-crumb mds-crumb--ellipsis" aria-hidden="true">…</li>
			<li v-for="(segment, i) in segments"
				:key="segment.path"
				class="mds-crumb"
				:class="{ 'mds-crumb--middle': i < middleCount }">
				<span v-if="segment.current" aria-current="page">{{ segment.name }}</span>
				<RouterLink v-else-if="segment.note" :to="{ name: 'page', params: { siteId, path: segment.note } }">
					{{ segment.name }}
				</RouterLink>
				<button v-else type="button" class="mds-crumb-folder" @click="$emit('reveal', segment.path)">
					{{ segment.name }}
				</button>
			</li>
		</ol>
	</nav>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { breadcrumbSegments } from '../services/breadcrumb.js'

export default {
	name: 'PageBreadcrumb',
	props: {
		siteId: { type: [String, Number], required: true },
		siteName: { type: String, default: '' },
		path: { type: String, required: true },
		tree: { type: Array, default: () => [] },
	},
	emits: ['reveal'],
	setup() { return { t } },
	computed: {
		segments() { return breadcrumbSegments(this.tree, this.path) },
		// Folders between the site and the last folder: hidden behind "…" on narrow screens.
		middleCount() { return Math.max(0, this.segments.length - 2) },
		hasMiddle() { return this.middleCount > 0 },
	},
}
</script>

<style scoped>
.mds-crumbs ol { display: flex; flex-wrap: wrap; align-items: center; list-style: none; margin: 0; padding: 0; color: var(--color-text-maxcontrast); font-size: 0.92em; }
.mds-crumb { display: flex; align-items: center; min-width: 0; }
.mds-crumb + .mds-crumb::before { content: '›'; margin: 0 6px; color: var(--color-text-maxcontrast); }
.mds-crumb a, .mds-crumb-folder { color: var(--color-text-maxcontrast); text-decoration: none; }
.mds-crumb a:hover, .mds-crumb-folder:hover { color: var(--color-main-text); text-decoration: underline; }
.mds-crumb-folder { border: none; background: none; padding: 0; margin: 0; min-height: 0; font: inherit; cursor: pointer; }
.mds-crumb [aria-current] { color: var(--color-main-text); }
.mds-crumb--ellipsis { display: none; }
@media (max-width: 1023px) {
	.mds-crumb--ellipsis { display: flex; }
	.mds-crumb--middle { display: none; }
}
</style>
