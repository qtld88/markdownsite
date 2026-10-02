import { defineStore } from 'pinia'

/**
 * Open/closed state of folders in the page tree, shared by every PageTree
 * level so that other components (the breadcrumb) can reveal a folder.
 * A path missing from openMap falls back to PageTree's reveal default.
 */
export const useTreeStore = defineStore('tree', {
	// dragging: path of the page or folder being dragged (editors only)
	state: () => ({ openMap: {}, dragging: null }),
	actions: {
		setOpen(path, open) {
			this.openMap = { ...this.openMap, [path]: open }
		},
		/** Opens `path` and every folder above it. */
		reveal(path) {
			const next = { ...this.openMap }
			let acc = ''
			for (const part of path.split('/')) {
				acc = acc ? `${acc}/${part}` : part
				next[acc] = true
			}
			this.openMap = next
		},
		clear() {
			this.openMap = {}
		},
	},
})
