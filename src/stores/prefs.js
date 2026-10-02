import { defineStore } from 'pinia'
import { getPrefs, savePrefs } from '../services/api.js'

export const usePrefsStore = defineStore('prefs', {
	state: () => ({
		linkColor: '',
		linkUnderline: true,
		linkBold: true,
		revealActive: true,
		tocCollapsed: false,
		searchScope: 'site',
		loaded: false,
	}),
	getters: {
		cssVars: (state) => ({
			'--mds-link-color': state.linkColor || 'var(--color-primary-element)',
			'--mds-link-decoration': state.linkUnderline ? 'underline' : 'none',
			'--mds-link-weight': state.linkBold ? '600' : '400',
		}),
	},
	actions: {
		async load() {
			if (this.loaded) { return }
			this.$patch(await getPrefs())
			this.loaded = true
		},
		async save() {
			this.$patch(await savePrefs({
				linkColor: this.linkColor,
				linkUnderline: this.linkUnderline,
				linkBold: this.linkBold,
				revealActive: this.revealActive,
				tocCollapsed: this.tocCollapsed,
				searchScope: this.searchScope,
			}))
		},
	},
})
