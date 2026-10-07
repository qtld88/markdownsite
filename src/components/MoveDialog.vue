<template>
	<NcDialog :name="title" size="small" @update:open="v => !v && $emit('close', null)">
		<p class="mds-move-text">
			{{ n('markdownsite', '%n link', '%n links', count) }}
			{{ n('markdownsite', 'in %n page points to “{name}”:', 'in %n pages point to “{name}”:', pages.length, { name: move.name }, { escape: false }) }}
		</p>
		<ul class="mds-move-pages">
			<li v-for="page in shownPages" :key="page">{{ page }}</li>
			<li v-if="hiddenPages" class="mds-move-more">
				{{ n('markdownsite', 'and %n more', 'and %n more', hiddenPages) }}
			</li>
		</ul>
		<NcCheckboxRadioSwitch v-model="updateLinks">
			{{ t('markdownsite', 'Update these links to follow the change') }}
		</NcCheckboxRadioSwitch>
		<p v-if="!updateLinks" class="mds-move-warning">
			{{ t('markdownsite', 'These links will be broken. You can find and fix them later in Settings → Links.') }}
		</p>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close', null)">
				{{ move.rename ? t('markdownsite', 'Cancel the rename') : t('markdownsite', 'Cancel the move') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('close', { updateLinks })">
				{{ move.rename ? t('markdownsite', 'Rename') : t('markdownsite', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { describeMove } from '../services/moveText.js'

const SHOWN = 5

/** Confirms a rename or move that affects links. Closes with {updateLinks} or null. */
export default {
	name: 'MoveDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog },
	props: {
		from: { type: String, required: true },
		to: { type: String, required: true },
		count: { type: Number, required: true },
		pages: { type: Array, required: true },
	},
	emits: ['close'],
	setup() { return { t, n } },
	data() { return { updateLinks: true } },
	computed: {
		move() { return describeMove(this.from, this.to) },
		title() {
			const vars = { name: this.move.name, target: this.move.target || t('markdownsite', 'the top level') }
			return this.move.rename
				? t('markdownsite', 'Rename “{name}” to “{target}”?', vars, undefined, { escape: false })
				: t('markdownsite', 'Move “{name}” to “{target}”?', vars, undefined, { escape: false })
		},
		shownPages() { return this.pages.slice(0, SHOWN) },
		hiddenPages() { return Math.max(0, this.pages.length - SHOWN) },
	},
}
</script>

<style scoped>
.mds-move-text { margin: 4px 0 8px; }
.mds-move-pages {
	margin: 0 0 12px;
	padding-left: 20px;
	list-style: disc;
	color: var(--color-text-maxcontrast);
}
.mds-move-more { list-style: none; margin-left: -20px; }
.mds-move-warning { margin: 8px 0 0; color: var(--color-warning-text, var(--color-text-maxcontrast)); }
</style>
