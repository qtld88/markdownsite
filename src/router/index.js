import { createRouter, createWebHashHistory } from 'vue-router'
import SiteList from '../views/SiteList.vue'
import WikiView from '../views/WikiView.vue'

export default createRouter({
	history: createWebHashHistory(),
	routes: [
		{ path: '/', name: 'sites', component: SiteList },
		{ path: '/s/:siteId/:path(.*)?', name: 'page', component: WikiView, props: true },
	],
})
