import { createRouter, createWebHashHistory } from 'vue-router'
import WikiView from '../views/WikiView.vue'

export default createRouter({
	history: createWebHashHistory(),
	routes: [
		{ path: '/', name: 'home', component: WikiView },
		{ path: '/s/:siteId', name: 'site', component: WikiView, props: true },
		{ path: '/s/:siteId/:path(.*)*', name: 'page', component: WikiView, props: true },
	],
})
