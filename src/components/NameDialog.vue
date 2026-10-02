<template>
	<NcDialog :name="title" size="small" @update:open="v => !v && $emit('close', null)">
		<form class="mds-name-dialog" @submit.prevent="submit">
			<NcTextField ref="field" v-model="value" :label="label" :error="!!error" :helper-text="error" />
		</form>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close', null)">{{ t('markdownsite', 'Cancel') }}</NcButton>
			<NcButton variant="primary" :disabled="!value.trim()" @click="submit">{{ confirmLabel }}</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'

/** Asks for a name. Closes with the trimmed name, or null when cancelled. */
export default {
	name: 'NameDialog',
	components: { NcButton, NcDialog, NcTextField },
	props: {
		title: { type: String, required: true },
		label: { type: String, required: true },
		confirmLabel: { type: String, required: true },
		initial: { type: String, default: '' },
	},
	emits: ['close'],
	setup() { return { t } },
	data() { return { value: this.initial, error: '' } },
	mounted() { this.$nextTick(() => this.$refs.field?.focus()) },
	methods: {
		submit() {
			const name = this.value.trim()
			if (!name) {
				return
			}
			if (/[/\\:*?"<>|]/.test(name) || name.startsWith('.')) {
				this.error = t('markdownsite', 'A name cannot start with a dot or contain / \\ : * ? " < > |')
				return
			}
			this.$emit('close', name)
		},
	},
}
</script>

<style scoped>
.mds-name-dialog { padding: 8px 0; }
</style>
