import { defineStore } from 'pinia'
import { listSites, createSite, deleteSite } from '../services/api.js'

export const useSitesStore = defineStore('sites', {
	state: () => ({ sites: [], loaded: false }),
	actions: {
		async load() {
			this.sites = await listSites()
			this.loaded = true
		},
		async add(payload) {
			const site = await createSite(payload)
			this.sites.push(site)
			return site
		},
		async remove(id) {
			await deleteSite(id)
			this.sites = this.sites.filter(s => s.id !== id)
		},
	},
})
