<template>
	<ul class="page-tree">
		<li v-for="node in nodes" :key="node.path">
			<template v-if="node.type === 'dir'">
				<span class="dir" @click="toggle(node.path)">
					{{ open[node.path] ? '▾' : '▸' }} {{ node.name }}
				</span>
				<PageTree v-if="open[node.path]" :nodes="node.children" :site-id="siteId" />
			</template>
			<RouterLink v-else :to="{ name: 'page', params: { siteId, path: node.path } }">
				{{ node.name }}
			</RouterLink>
		</li>
	</ul>
</template>

<script>
export default {
	name: 'PageTree',
	props: { nodes: { type: Array, default: () => [] }, siteId: { type: [String, Number], required: true } },
	data() { return { open: {} } },
	methods: { toggle(p) { this.open = { ...this.open, [p]: !this.open[p] } } },
}
</script>
