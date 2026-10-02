<template>
	<details v-if="items.length && variant === 'inline'" class="mds-toc mds-toc--inline">
		<summary class="mds-toc-title">{{ t('markdownsite', 'On this page') }}</summary>
		<ul class="mds-toc-list">
			<li v-for="item in items" :key="item.id" :style="{ '--mds-toc-depth': item.depth }">
				<a :href="hrefFor(item)" :class="{ 'is-active': item.id === activeId }" @click.prevent="select(item)">{{ item.text }}</a>
			</li>
		</ul>
	</details>

	<nav v-else-if="items.length"
		class="mds-toc mds-toc--side"
		:class="{ 'is-collapsed': collapsed }"
		:aria-label="t('markdownsite', 'On this page')">
		<div class="mds-toc-header">
			<span v-if="!collapsed" class="mds-toc-title">{{ t('markdownsite', 'On this page') }}</span>
			<NcButton variant="tertiary"
				:aria-label="collapsed ? t('markdownsite', 'Show outline') : t('markdownsite', 'Hide outline')"
				:title="collapsed ? t('markdownsite', 'Show outline') : t('markdownsite', 'Hide outline')"
				@click="$emit('update:collapsed', !collapsed)">
				<template #icon>
					<NcIconSvgWrapper :path="collapsed ? mdiTableOfContents : mdiChevronRight" :size="20" />
				</template>
			</NcButton>
		</div>
		<ul v-if="!collapsed" class="mds-toc-list">
			<li v-for="item in items" :key="item.id" :style="{ '--mds-toc-depth': item.depth }">
				<a :href="hrefFor(item)" :class="{ 'is-active': item.id === activeId }" @click.prevent="select(item)">{{ item.text }}</a>
			</li>
		</ul>
	</nav>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiChevronRight, mdiTableOfContents } from '@mdi/js'
import { translate as t } from '@nextcloud/l10n'
import { HEADING_ID_PREFIX, tocItems } from '../services/toc.js'

export default {
	name: 'PageToc',
	components: { NcButton, NcIconSvgWrapper },
	props: {
		toc: { type: Array, default: () => [] },
		// 'side': sticky column (wide screens); 'inline': "On this page" block (narrow screens)
		variant: { type: String, default: 'side' },
		collapsed: { type: Boolean, default: false },
	},
	emits: ['navigate', 'update:collapsed'],
	setup() { return { t, mdiChevronRight, mdiTableOfContents } },
	data() { return { activeId: null } },
	computed: {
		items() { return tocItems(this.toc) },
	},
	watch: {
		// After the article with the new headings is in the DOM.
		items: { handler: 'observe', flush: 'post' },
	},
	mounted() { this.observe() },
	beforeUnmount() { this.observer?.disconnect() },
	methods: {
		select(item) {
			this.activeId = item.id
			this.$emit('navigate', item.id)
		},
		hrefFor(item) {
			return this.$router.resolve({ ...this.$route, hash: '#' + item.id }).href
		},
		/** Marks the first heading in the upper part of the viewport as active. */
		observe() {
			this.observer?.disconnect()
			this.activeId = this.items[0]?.id ?? null
			if (!this.items.length || typeof IntersectionObserver === 'undefined') { return }
			const visible = new Set()
			this.observer = new IntersectionObserver((entries) => {
				for (const entry of entries) {
					const id = entry.target.id.slice(HEADING_ID_PREFIX.length + 1)
					if (entry.isIntersecting) { visible.add(id) } else { visible.delete(id) }
				}
				const first = this.items.find(item => visible.has(item.id))
				if (first) { this.activeId = first.id }
			}, { rootMargin: '0px 0px -65% 0px' })
			for (const item of this.items) {
				const el = document.getElementById(`${HEADING_ID_PREFIX}-${item.id}`)
				if (el) { this.observer.observe(el) }
			}
		},
	},
}
</script>

<style scoped>
.mds-toc { font-size: 0.92em; }
.mds-toc-title { font-weight: 600; color: var(--color-text-maxcontrast); }
.mds-toc-list { list-style: none; margin: 0; padding: 0; }
.mds-toc-list li { padding-left: calc(var(--mds-toc-depth, 0) * 12px); }
.mds-toc-list a {
	display: block; padding: 3px 8px; border-left: 2px solid transparent;
	color: var(--color-main-text); text-decoration: none; line-height: 1.35;
}
.mds-toc-list a:hover { background: var(--color-background-hover); }
.mds-toc-list a.is-active { border-left-color: var(--color-primary-element); color: var(--color-primary-element); font-weight: 600; }

.mds-toc--side { padding: 16px 8px 32px 0; }
.mds-toc-header { display: flex; align-items: center; justify-content: space-between; gap: 4px; padding-left: 8px; margin-bottom: 4px; }
.mds-toc--side.is-collapsed .mds-toc-header { justify-content: center; padding-left: 0; }

.mds-toc--inline {
	margin: 8px 0 0; padding: 8px 12px; border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}
.mds-toc--inline summary { cursor: pointer; }
.mds-toc--inline .mds-toc-list { margin-top: 6px; }
</style>
