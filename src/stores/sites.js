import { defineStore } from 'pinia'
import { loadState } from '@nextcloud/initial-state'
import { listSites, createSite, deleteSite } from '../services/api.js'

// The page arrives with the site list; it is used once, later loads ask the server.
let initialUsed = false

export const useSitesStore = defineStore('sites', {
	state: () => ({ sites: [], loaded: false, currentId: null }),
	getters: {
		current: (state) => state.sites.find(s => String(s.id) === String(state.currentId)) || null,
	},
	actions: {
		async load() {
			const initial = initialUsed ? null : loadState('markdownsite', 'sites', null)
			initialUsed = true
			this.sites = initial ?? await listSites()
			this.loaded = true
		},
		async add(payload) {
			const site = await createSite(payload)
			this.sites.push(site)
			return site
		},
		async remove(id) {
			await deleteSite(id)
			this.sites = this.sites.filter(s => String(s.id) !== String(id))
			if (String(this.currentId) === String(id)) { this.currentId = null }
		},
		setCurrent(id) { this.currentId = id },
	},
})
