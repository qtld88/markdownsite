<template>
	<NcAppSettingsDialog :open="open"
		:name="t('markdownsite', 'MarkdownSite settings')"
		@update:open="v => $emit('update:open', v)">
		<NcAppSettingsSection id="navigation" :name="t('markdownsite', 'Navigation')">
			<NcCheckboxRadioSwitch v-model="prefs.revealActive" @update:model-value="prefs.save()">
				{{ t('markdownsite', 'Always show current open file in the tree structure') }}
			</NcCheckboxRadioSwitch>
			<p class="mds-hint">
				{{ t('markdownsite', 'Expands the folders leading to the page you have open, and scrolls it into view.') }}
			</p>
		</NcAppSettingsSection>

		<NcAppSettingsSection id="link-appearance" :name="t('markdownsite', 'Link appearance')">
			<div class="mds-group">
				<span class="mds-eyebrow">{{ t('markdownsite', 'Style') }}</span>
				<NcCheckboxRadioSwitch v-model="prefs.linkUnderline">
					{{ t('markdownsite', 'Underline links') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="prefs.linkBold">
					{{ t('markdownsite', 'Bold links') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div class="mds-group">
				<span class="mds-eyebrow">{{ t('markdownsite', 'Colour') }}</span>
				<div class="mds-color-row">
					<NcColorPicker :model-value="prefs.linkColor || '#0082c9'" @update:model-value="c => prefs.linkColor = c">
						<button type="button" class="mds-swatch" :style="{ background: prefs.linkColor || 'var(--color-primary-element)' }"
							:aria-label="t('markdownsite', 'Pick colour')" />
					</NcColorPicker>
					<span class="mds-color-label">
						{{ prefs.linkColor || t('markdownsite', 'Theme colour') }}
					</span>
					<NcButton v-if="prefs.linkColor" variant="tertiary" @click="prefs.linkColor = ''">
						{{ t('markdownsite', 'Reset') }}
					</NcButton>
				</div>
			</div>

			<div class="mds-group">
				<span class="mds-eyebrow">{{ t('markdownsite', 'Preview') }}</span>
				<div class="mds-preview-card">
					<a class="mds-preview" :style="prefs.cssVars" href="#" @click.prevent>{{ t('markdownsite', 'This is what a link looks like') }}</a>
				</div>
			</div>

			<NcButton variant="primary" @click="prefs.save()">
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
.mds-hint {
	margin: 4px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.mds-group {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 14px 0;
	border-top: 1px solid var(--color-border);
}
.mds-group:first-of-type { border-top: none; padding-top: 4px; }

.mds-eyebrow {
	font-size: 11px;
	font-weight: 600;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.mds-color-row { display: flex; align-items: center; gap: 12px; margin-top: 2px; }
.mds-swatch {
	width: 32px; height: 32px; border-radius: 50%;
	border: 2px solid var(--color-border);
	cursor: pointer; padding: 0;
}
.mds-swatch:hover { border-color: var(--color-primary-element); }
.mds-color-label {
	font-family: monospace;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.mds-preview-card {
	margin-top: 4px;
	padding: 16px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-background-hover);
	display: flex;
	justify-content: center;
}
.mds-preview {
	color: var(--mds-link-color, var(--color-primary-element));
	text-decoration: var(--mds-link-decoration, underline);
	font-weight: var(--mds-link-weight, 600);
}
</style>
