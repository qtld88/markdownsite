<template>
	<NcDialog :name="t('markdownsite', 'Update links?')" size="small" @update:open="v => !v && $emit('close', null)">
		<p class="mds-move-text">
			{{ n('markdownsite', '%n link', '%n links', count) }}
			{{ n('markdownsite', 'in %n page will be updated.', 'in %n pages will be updated.', pages.length) }}
		</p>
		<NcCheckboxRadioSwitch v-model="updateLinks">
			{{ t('markdownsite', 'Update the links') }}
		</NcCheckboxRadioSwitch>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close', null)">{{ t('markdownsite', 'Cancel') }}</NcButton>
			<NcButton variant="primary" @click="$emit('close', { updateLinks })">{{ t('markdownsite', 'Confirm') }}</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

/** Confirms a rename or move that affects links. Closes with {updateLinks} or null. */
export default {
	name: 'MoveDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog },
	props: {
		count: { type: Number, required: true },
		pages: { type: Array, required: true },
	},
	emits: ['close'],
	setup() { return { t, n } },
	data() { return { updateLinks: true } },
}
</script>

<style scoped>
.mds-move-text { margin: 4px 0 12px; }
</style>
