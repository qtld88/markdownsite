<template>
	<div class="mds-editor">
		<div class="mds-editor-bar">
			<span class="mds-editor-status" :class="'is-' + state">{{ statusText }}</span>
		</div>

		<div v-if="draft" class="mds-editor-banner">
			<span>{{ t('markdownsite', 'This page has unsaved text from your last visit.') }}</span>
			<NcButton variant="primary" @click="restoreDraft">{{ t('markdownsite', 'Restore it') }}</NcButton>
			<NcButton variant="tertiary" @click="discardDraft">{{ t('markdownsite', 'Discard') }}</NcButton>
		</div>
		<div v-if="state === 'conflict'" class="mds-editor-banner mds-editor-banner--warning">
			<span>{{ t('markdownsite', 'Modified elsewhere. Autosave is paused.') }}</span>
			<NcButton @click="reloadFromServer">{{ t('markdownsite', 'Reload') }}</NcButton>
			<NcButton variant="primary" @click="keepMine">{{ t('markdownsite', 'Keep mine') }}</NcButton>
		</div>
		<div v-if="reason === 'deleted'" class="mds-editor-banner mds-editor-banner--warning">
			<span>{{ t('markdownsite', 'Page deleted elsewhere.') }}</span>
			<NcButton variant="primary" @click="recreate">{{ t('markdownsite', 'Recreate with my text') }}</NcButton>
		</div>
		<p v-if="loadError" class="mds-editor-banner mds-editor-banner--error">
			{{ t('markdownsite', 'The editor could not be opened.') }}
		</p>

		<div v-if="loading" class="mds-editor-loading"><NcLoadingIcon :size="28" /></div>
		<div ref="host" class="mds-editor-host" :style="cssVars" />
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { translate as t } from '@nextcloud/l10n'
import { createPage, getSource, renderMarkdown, savePage, uploadAttachment } from '../services/api.js'
import { loadChunk } from '../services/chunks.js'
import { createAutosave, readDraft } from '../editor/autosave.js'

export default {
	name: 'PageEditor',
	components: { NcButton, NcLoadingIcon },
	props: {
		siteId: { type: [String, Number], required: true },
		path: { type: String, required: true },
		pages: { type: Array, default: () => [] },
		cssVars: { type: Object, default: () => ({}) },
	},
	emits: ['exit', 'follow', 'forbidden', 'saved'],
	setup() { return { t } },
	data() {
		return { loading: true, loadError: false, state: 'idle', reason: null, draft: null }
	},
	computed: {
		draftKey() { return `markdownsite:draft:${this.siteId}:${this.path}` },
		statusText() {
			switch (this.state) {
			case 'dirty': return t('markdownsite', 'Unsaved changes')
			case 'saving': return t('markdownsite', 'Saving…')
			case 'saved': return t('markdownsite', 'Saved')
			case 'conflict': return t('markdownsite', 'Modified elsewhere')
			case 'error': return this.reason === 'network' ? t('markdownsite', 'Offline: retrying…') : t('markdownsite', 'Not saved')
			default: return ''
			}
		},
	},
	async mounted() {
		this.onBeforeUnload = (event) => {
			if (this.autosave?.hasUnsaved()) {
				event.preventDefault()
				event.returnValue = ''
			}
		}
		window.addEventListener('beforeunload', this.onBeforeUnload)
		const [editor, source] = await Promise.all([
			loadChunk(() => import(/* webpackChunkName: "editor" */ '../editor/index.js'), 'the editor'),
			getSource(this.siteId, this.path).catch(() => null),
		])
		this.loading = false
		if (!editor || !source || !this.$refs.host) {
			this.loadError = true
			return
		}
		this.editorModule = editor
		const draft = readDraft(window.sessionStorage, this.draftKey)
		if (draft && draft.text !== source.content) {
			this.draft = draft
		}
		this.autosave = createAutosave({
			save: (text, etag) => savePage(this.siteId, this.path, text, etag),
			etag: source.etag,
			storage: window.sessionStorage,
			draftKey: this.draftKey,
			onState: (state, info) => {
				this.state = state
				this.reason = info?.reason ?? null
				if (state === 'saved') {
					this.$emit('saved')
				}
				if (info?.reason === 'forbidden') {
					this.$emit('forbidden', this.autosave.text ?? '')
				}
			},
		})
		this.view = editor.createEditor({
			parent: this.$refs.host,
			doc: source.content,
			getPages: () => this.pages,
			render: markdown => renderMarkdown(this.siteId, this.path, markdown),
			upload: file => uploadAttachment(this.siteId, this.path, file),
			onChange: (text) => {
				if (!this.applyingServerText) {
					this.autosave.change(text)
				}
			},
			onExit: () => this.$emit('exit'),
			onFollow: link => this.$emit('follow', link),
			onError: (error) => this.showError(error),
		})
		this.view.focus()
	},
	beforeUnmount() {
		window.removeEventListener('beforeunload', this.onBeforeUnload)
		this.autosave?.flush()
		this.autosave?.dispose()
		this.view?.destroy()
	},
	methods: {
		/** Saves now; resolves when the server answered. */
		async flush() {
			await this.autosave?.flush()
		},
		restoreDraft() {
			this.editorModule.setDocument(this.view, this.draft.text)
			this.draft = null
		},
		discardDraft() {
			window.sessionStorage.removeItem(this.draftKey)
			this.draft = null
		},
		reloadFromServer() {
			const text = this.autosave.reload()
			if (text !== null) {
				// The server's own text: showing it is not an edit to save.
				this.applyingServerText = true
				this.editorModule.setDocument(this.view, text)
				this.applyingServerText = false
			}
		},
		keepMine() {
			this.autosave.keepMine()
		},
		async recreate() {
			try {
				const { etag } = await createPage(this.siteId, this.path)
				await this.autosave.resume(etag)
			} catch (e) {
				this.showError(e)
			}
		},
		async showError(error) {
			const { showError } = await import('@nextcloud/dialogs')
			showError(error?.response?.data?.message || t('markdownsite', 'Upload failed'))
		},
	},
}
</script>

<style scoped>
.mds-editor { position: relative; }
.mds-editor-bar { display: flex; justify-content: flex-end; min-height: 20px; font-size: 0.85em; color: var(--color-text-maxcontrast); }
.mds-editor-status.is-error, .mds-editor-status.is-conflict { color: var(--color-error); }
.mds-editor-banner {
	display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 8px 0; padding: 8px 12px;
	border-radius: var(--border-radius-large, 8px); background: var(--color-background-dark);
}
.mds-editor-banner--warning { background: rgba(236, 117, 0, 0.12); border: 1px solid rgba(236, 117, 0, 0.4); }
.mds-editor-banner--error { color: var(--color-error); }
.mds-editor-loading { display: flex; justify-content: center; padding: 32px; }
.mds-editor-host { max-width: 760px; min-height: 50vh; margin: 0 auto; padding-top: 16px; }
</style>
