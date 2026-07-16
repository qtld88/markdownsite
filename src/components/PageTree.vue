<template>
	<ul class="mds-tree">
		<NcAppNavigationItem
			v-for="node in nodes"
			:key="node.path"
			:name="node.name"
			:allow-collapse="node.type === 'dir'"
			:open="isOpen(node)"
			:active="node.path === activePath"
			:to="node.type === 'page' ? pageRoute(node) : undefined"
			@click="onItemClick(node)">
			<template #icon>
				<NcIconSvgWrapper v-if="node.type === 'dir'" :path="mdiFolder" :size="20" />
				<NcIconSvgWrapper v-else :path="mdiFileDocumentOutline" :size="20" />
			</template>
			<template v-if="node.type === 'dir'" #default>
				<PageTree :nodes="node.children || []" :site-id="siteId" :active-path="activePath" />
			</template>
		</NcAppNavigationItem>
	</ul>
</template>

<script>
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiFolder, mdiFileDocumentOutline } from '@mdi/js'

export default {
	name: 'PageTree',
	components: { NcAppNavigationItem, NcIconSvgWrapper },
	props: {
		nodes: { type: Array, default: () => [] },
		siteId: { type: [String, Number], required: true },
		// Path of the currently open page. When set, ancestor folders of
		// this path default to open. Pass '' to disable reveal entirely
		// (the "always show current open file" preference, off).
		activePath: { type: String, default: '' },
	},
	data() { return { openMap: {}, mdiFolder, mdiFileDocumentOutline } },
	computed: {
		activeAncestors() {
			if (!this.activePath) { return new Set() }
			const parts = this.activePath.split('/').slice(0, -1)
			const set = new Set()
			let acc = ''
			for (const part of parts) {
				acc = acc ? `${acc}/${part}` : part
				set.add(acc)
			}
			return set
		},
	},
	methods: {
		isOpen(node) {
			// An explicit user toggle always wins over the reveal default.
			if (node.path in this.openMap) { return this.openMap[node.path] }
			return this.activeAncestors.has(node.path)
		},
		setOpen(node, v) { this.openMap = { ...this.openMap, [node.path]: v } },
		onItemClick(node) {
			// Folder rows have no route; clicking the row toggles open (like the chevron).
			if (node.type === 'dir') { this.setOpen(node, !this.isOpen(node)) }
		},
		pageRoute(node) {
			return { name: 'page', params: { siteId: this.siteId, path: node.path } }
		},
	},
}
</script>

<style scoped>
.mds-tree { list-style: none; margin: 0; padding: 0; }
</style>
