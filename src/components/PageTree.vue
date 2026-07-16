<template>
	<ul class="mds-tree">
		<NcAppNavigationItem
			v-for="node in nodes"
			:key="node.path"
			:name="node.name"
			:allow-collapse="node.type === 'dir'"
			:open="isOpen(node)"
			:to="node.type === 'page' ? pageRoute(node) : undefined"
			@click="onItemClick(node)">
			<template #icon>
				<NcIconSvgWrapper v-if="node.type === 'dir'" :path="mdiFolder" :size="20" />
				<NcIconSvgWrapper v-else :path="mdiFileDocumentOutline" :size="20" />
			</template>
			<template v-if="node.type === 'dir'" #default>
				<PageTree :nodes="node.children || []" :site-id="siteId" />
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
	},
	data() { return { openMap: {}, mdiFolder, mdiFileDocumentOutline } },
	methods: {
		isOpen(node) { return !!this.openMap[node.path] },
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
