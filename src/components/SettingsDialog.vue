<template>
	<NcAppSettingsDialog :open="open"
		:name="t('markdownsite', 'MarkdownSite settings')"
		@update:open="v => $emit('update:open', v)">
		<NcAppSettingsSection id="navigation" :name="t('markdownsite', 'Navigation')">
			<NcCheckboxRadioSwitch v-model="prefs.revealActive" @update:model-value="prefs.save()">
				{{ t('markdownsite', 'Always show current open file in the tree structure') }}
			</NcCheckboxRadioSwitch>
		</NcAppSettingsSection>
		<NcAppSettingsSection id="link-appearance" :name="t('markdownsite', 'Link appearance')">
			<NcCheckboxRadioSwitch v-model="prefs.linkUnderline">
				{{ t('markdownsite', 'Underline links') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="prefs.linkBold">
				{{ t('markdownsite', 'Bold links') }}
			</NcCheckboxRadioSwitch>
			<div class="mds-color">
				<span>{{ t('markdownsite', 'Link colour') }}</span>
				<NcColorPicker :model-value="prefs.linkColor || '#0082c9'" @update:model-value="c => prefs.linkColor = c">
					<NcButton>{{ t('markdownsite', 'Pick colour') }}</NcButton>
				</NcColorPicker>
				<NcButton variant="tertiary" @click="prefs.linkColor = ''">
					{{ t('markdownsite', 'Use theme colour') }}
				</NcButton>
			</div>
			<a class="mds-preview" :style="prefs.cssVars">{{ t('markdownsite', 'Preview link') }}</a>
			<NcButton variant="secondary" @click="prefs.save()">
				{{ t('markdownsite', 'Save appearance') }}
			</NcButton>
		</NcAppSettingsSection>
		<SharingSettings />
	</NcAppSettingsDialog>
</template>

<script>
import NcAppSettingsDialog from '@nextcloud/vue/components/NcAppSettingsDialog'
import NcAppSettingsSection from '@nextcloud/vue/components/NcAppSettingsSection'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import NcButton from '@nextcloud/vue/components/NcButton'
import { translate as t } from '@nextcloud/l10n'
import { usePrefsStore } from '../stores/prefs.js'
import SharingSettings from './SharingSettings.vue'

export default {
	name: 'SettingsDialog',
	components: { NcAppSettingsDialog, NcAppSettingsSection, NcCheckboxRadioSwitch, NcColorPicker, NcButton, SharingSettings },
	props: { open: { type: Boolean, default: false } },
	emits: ['update:open'],
	setup() { return { prefs: usePrefsStore(), t } },
	mounted() { this.prefs.load() },
}
</script>

<style scoped>
.mds-color { display: flex; align-items: center; gap: 12px; margin: 12px 0; flex-wrap: wrap; }
.mds-preview {
	display: inline-block; margin: 8px 0;
	color: var(--mds-link-color, var(--color-primary-element));
	text-decoration: var(--mds-link-decoration, underline);
	font-weight: var(--mds-link-weight, 600);
}
</style>
