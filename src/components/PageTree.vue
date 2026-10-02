<template>
	<ul class="mds-tree">
		<li v-if="editable && isRoot && tree.dragging"
			class="mds-tree-rootdrop"
			:class="{ 'is-over': overPath === '' }"
			@dragover="onDragOver($event, '')"
			@dragleave="overPath = null"
			@drop="onDrop($event, '')">
			{{ t('markdownsite', 'Drop here to move to the top level') }}
		</li>
		<NcAppNavigationItem
			v-for="node in nodes"
			:key="node.path"
			:name="node.name"
			:allow-collapse="node.type === 'dir'"
			:open="isOpen(node)"
			:active="isActive(node)"
			:data-mds-active="isActive(node) || null"
			:data-mds-path="node.path"
			:class="{ 'mds-tree-drop': overPath === node.path }"
			:to="routeFor(node)"
			:editable="editable"
			:edit-label="t('markdownsite', 'Rename')"
			:draggable="editable ? 'true' : null"
			@update:name="name => onRename(node, name)"
			@update:open="v => tree.setOpen(node.path, v)"
			@click="ev => onItemClick(node, ev)"
			@dragstart="onDragStart($event, node)"
			@dragend="tree.dragging = null; overPath = null"
			@dragover="node.type === 'dir' && onDragOver($event, node.path)"
			@dragleave="overPath = null"
			@drop="node.type === 'dir' && onDrop($event, node.path)">
			<template #icon>
				<NcIconSvgWrapper v-if="node.type === 'dir'" :path="mdiFolder" :size="20" />
				<NcIconSvgWrapper v-else :path="mdiFileDocumentOutline" :size="20" />
			</template>
			<template v-if="editable" #actions>
				<template v-if="node.type === 'dir'">
					<NcActionButton :close-after-click="true" @click="onNewPage(node.path)">
						<template #icon><NcIconSvgWrapper :path="mdiFileDocumentPlusOutline" :size="20" /></template>
						{{ t('markdownsite', 'New page here') }}
					</NcActionButton>
					<NcActionButton :close-after-click="true" @click="onNewFolder(node.path)">
						<template #icon><NcIconSvgWrapper :path="mdiFolderPlusOutline" :size="20" /></template>
						{{ t('markdownsite', 'New folder') }}
					</NcActionButton>
					<NcActionButton v-if="!node.note" :close-after-click="true" @click="onFolderNote(node)">
						<template #icon><NcIconSvgWrapper :path="mdiFileDocumentEditOutline" :size="20" /></template>
						{{ t('markdownsite', 'Create folder note') }}
					</NcActionButton>
				</template>
				<NcActionButton :close-after-click="true" @click="onDelete(node)">
					<template #icon><NcIconSvgWrapper :path="mdiDelete" :size="20" /></template>
					{{ t('markdownsite', 'Delete') }}
				</NcActionButton>
			</template>
			<template v-if="node.type === 'dir'" #default>
				<PageTree :nodes="node.children || []" :site-id="siteId" :active-path="activePath"
					:editable="editable" :is-root="false"
					@changed="e => $emit('changed', e)" />
			</template>
		</NcAppNavigationItem>
	</ul>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiDelete, mdiFileDocumentEditOutline, mdiFileDocumentOutline, mdiFileDocumentPlusOutline, mdiFolder, mdiFolderPlusOutline } from '@mdi/js'
import { translate as t } from '@nextcloud/l10n'
import { canDrop, createFolderNote, join, moveTo, newFolder, newPage, remove, rename } from '../services/fileOps.js'
import { useTreeStore } from '../stores/tree.js'

const DRAG_TYPE = 'application/x-markdownsite-path'

/**
 * The site's pages and folders. With `editable` (owners and editors), each
 * entry gets a menu (new page, new folder, folder note, rename, delete) and
 * can be dragged onto a folder. Every change is reported with `changed`:
 * { edit?: path to open in edit mode, moved?: {from, to}, deleted?: path }.
 */
export default {
	name: 'PageTree',
	components: { NcActionButton, NcAppNavigationItem, NcIconSvgWrapper },
	props: {
		nodes: { type: Array, default: () => [] },
		siteId: { type: [String, Number], required: true },
		// Path of the currently open page. When set, ancestor folders of
		// this path default to open. Pass '' to disable reveal entirely
		// (the "always show current open file" preference, off).
		activePath: { type: String, default: '' },
		editable: { type: Boolean, default: false },
		isRoot: { type: Boolean, default: true },
	},
	emits: ['changed'],
	setup() {
		return {
			tree: useTreeStore(),
			t,
			mdiDelete, mdiFileDocumentEditOutline, mdiFileDocumentOutline, mdiFileDocumentPlusOutline, mdiFolder, mdiFolderPlusOutline,
		}
	},
	data() { return { overPath: null } },
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
		async onNewPage(dir) {
			const path = await newPage(this.siteId, dir)
			if (path) {
				this.tree.reveal(dir)
				this.$emit('changed', { edit: path })
			}
		},
		async onNewFolder(dir) {
			const path = await newFolder(this.siteId, dir)
			if (path) {
				this.tree.reveal(path)
				this.$emit('changed', {})
			}
		},
		async onFolderNote(node) {
			const path = await createFolderNote(this.siteId, node.path)
			if (path) {
				this.$emit('changed', { edit: path })
			}
		},
		async onRename(node, name) {
			const result = await rename(this.siteId, node, name.trim())
			if (result) {
				this.$emit('changed', { moved: { from: node.path, to: result.path } })
			}
		},
		async onDelete(node) {
			if (await remove(this.siteId, node)) {
				this.$emit('changed', { deleted: node.path })
			}
		},
		onDragStart(event, node) {
			if (!this.editable) { return }
			event.stopPropagation()
			event.dataTransfer.setData(DRAG_TYPE, node.path)
			event.dataTransfer.effectAllowed = 'move'
			this.tree.dragging = node.path
		},
		onDragOver(event, folder) {
			if (!canDrop(this.tree.dragging, folder)) { return }
			event.preventDefault()
			event.stopPropagation()
			event.dataTransfer.dropEffect = 'move'
			this.overPath = folder
		},
		async onDrop(event, folder) {
			const source = event.dataTransfer.getData(DRAG_TYPE) || this.tree.dragging
			this.overPath = null
			this.tree.dragging = null
			if (!canDrop(source, folder)) { return }
			event.preventDefault()
			event.stopPropagation()
			const name = source.split('/').pop()
			const result = await moveTo(this.siteId, source, join(folder, name))
			if (result) {
				if (folder) { this.tree.reveal(folder) }
				this.$emit('changed', { moved: { from: source, to: result.path } })
			}
		},
	},
}
</script>

<style scoped>
.mds-tree { list-style: none; margin: 0; padding: 0; }
.mds-tree-drop :deep(.app-navigation-entry) { outline: 2px dashed var(--color-primary-element); outline-offset: -2px; }
.mds-tree-rootdrop {
	margin: 4px 8px; padding: 8px; border: 2px dashed var(--color-border-dark, var(--color-border));
	border-radius: var(--border-radius-large, 8px); color: var(--color-text-maxcontrast); text-align: center; font-size: 0.9em;
}
.mds-tree-rootdrop.is-over { border-color: var(--color-primary-element); color: var(--color-main-text); }
</style>
