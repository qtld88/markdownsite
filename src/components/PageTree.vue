<template>
	<ul class="mds-tree">
		<NcAppNavigationItem
			v-for="node in nodes"
			:key="node.path"
			:name="node.name"
			:allow-collapse="node.type === 'dir'"
			:open="isOpen(node)"
			:active="isActive(node)"
			:data-mds-active="isActive(node) || null"
			:data-mds-path="node.path"
			:to="routeFor(node)"
			@update:open="v => tree.setOpen(node.path, v)"
			@click="ev => onItemClick(node, ev)">
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
import { useTreeStore } from '../stores/tree.js'

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
	setup() { return { tree: useTreeStore(), mdiFolder, mdiFileDocumentOutline } },
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
			// An explicit toggle (user click, breadcrumb reveal) wins over the reveal default.
			if (node.path in this.tree.openMap) { return this.tree.openMap[node.path] }
			return this.activeAncestors.has(node.path)
		},
		// A folder whose note is open counts as the active entry.
		isActive(node) {
			return !!this.activePath && (node.path === this.activePath || node.note === this.activePath)
		},
		routeFor(node) {
			const path = node.type === 'page' ? node.path : node.note
			return path ? { name: 'page', params: { siteId: this.siteId, path } } : undefined
		},
		onItemClick(node, event) {
			if (node.type !== 'dir') { return }
			if (!node.note) {
				// The entry is an <a href="#">: following it would open the site's home page.
				event?.preventDefault()
			}
			// A folder with a note opens the note (via `to`) and expands;
			// a folder without one toggles, like the chevron.
			this.tree.setOpen(node.path, node.note ? true : !this.isOpen(node))
		},
	},
}
</script>

<style scoped>
.mds-tree { list-style: none; margin: 0; padding: 0; }
</style>
