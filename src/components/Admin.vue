<template>
	<div class="deckghs-wrap">
		<div v-if="notice" class="deckghs-note deckghs-note-ok">{{ notice }}</div>
		<div v-if="error" class="deckghs-note deckghs-note-err">{{ error }}</div>

		<section class="deckghs-card">
			<h2>GitHub App <span class="deckghs-muted">{{ tr("(server sync)") }}</span></h2>
			<p class="deckghs-muted">{{ tr("For background sync without a user token. Permissions: Projects R/W, Issues R/W.") }}</p>
			<div class="deckghs-grid">
				<label>App ID <input v-model="form.githubAppId" inputmode="numeric" /></label>
				<label>Installation ID <input v-model="form.githubInstallationId" inputmode="numeric" /></label>
			</div>
			<label class="deckghs-block">{{ tr("Private key (.pem)") }}{{ hasPrivateKey ? tr(' (saved)') : '' }}
				<textarea v-model="form.githubPrivateKey" rows="3" :placeholder="tr('Only enter when changing it. The saved key remains otherwise.')" autocomplete="off" />
			</label>
			<div class="deckghs-grid">
				<label>Webhook Secret <input v-model="form.webhookSecret" type="password" autocomplete="off" /></label>
				<label>{{ tr("Sync interval (s, min. 300)") }} <input v-model.number="form.syncInterval" type="number" min="300" /></label>
			</div>
		</section>

		<section class="deckghs-card">
			<h2>GitHub OAuth <span class="deckghs-muted">{{ tr("(user sign-in)") }}</span></h2>
			<p class="deckghs-muted">{{ tr("Set this up once. Each Nextcloud user can then connect their GitHub account in personal settings.") }}</p>
			<ol class="deckghs-steps">
				<li>{{ tr("Create an OAuth app under GitHub → Settings → Developer settings → OAuth Apps.") }}</li>
				<li>{{ tr("Enter this exact authorization callback URL:") }}
					<span class="deckghs-copyrow"><code class="deckghs-code">{{ form.oauthCallbackUrl }}</code>
					<button class="deckghs-btn small" type="button" @click="copyCallback">{{ copied ? tr('Copied ✓') : tr('Copy') }}</button></span>
				</li>
				<li>{{ tr("Enter and save the client ID and client secret below.") }}</li>
			</ol>
			<div class="deckghs-grid">
				<label>Client ID <input v-model="form.oauthClientId" autocomplete="off" /></label>
				<label>Client Secret <input v-model="form.oauthClientSecret" type="password" autocomplete="off" :placeholder="tr('Only enter when changing it')" /></label>
			</div>
			<p class="deckghs-muted">Client Secret: {{ hasOauthSecret ? tr('saved') : tr('not saved yet') }}.</p>
		</section>

		<button class="deckghs-btn primary big" :disabled="saving" @click="save">{{ saving ? tr('Saving …') : tr('Save') }}</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { tr } from '../l10n.js'

const apiUrl = (path) => generateUrl('/apps/deckgithubsync' + path)

export default {
	name: 'Admin',
	data() {
		return {
			form: {
				githubAppId: '', githubInstallationId: '', githubPrivateKey: '',
				webhookSecret: '', syncInterval: 900,
				oauthClientId: '', oauthClientSecret: '', oauthCallbackUrl: '',
			},
			saving: false,
			hasOauthSecret: false,
			hasPrivateKey: false,
			copied: false,
			notice: '',
			error: '',
		}
	},
	async mounted() {
		try {
			const { data } = await axios.get(apiUrl('/api/v1/admin'))
			this.form.githubAppId = data.github_app_id
			this.form.githubInstallationId = data.github_installation_id
			this.form.syncInterval = data.sync_interval
			this.form.oauthClientId = data.oauth_client_id
			this.form.oauthCallbackUrl = data.oauth_callback_url
			this.hasOauthSecret = data.has_oauth_secret
			this.hasPrivateKey = data.has_private_key
		} catch (e) {
			this.error = tr('Configuration could not be loaded.')
		}
	},
	methods: {
		tr,
		async copyCallback() {
			const text = this.form.oauthCallbackUrl || ''
			try {
				if (navigator.clipboard?.writeText) {
					await navigator.clipboard.writeText(text)
				} else {
					const ta = document.createElement('textarea')
					ta.value = text
					document.body.appendChild(ta)
					ta.select()
					document.execCommand('copy')
					ta.remove()
				}
				this.copied = true
				setTimeout(() => { this.copied = false }, 2000)
			} catch (e) {
				this.error = tr('Copy failed. Select the URL manually.')
			}
		},
		async save() {
			this.saving = true
			this.error = ''
			this.notice = ''
			try {
				const { data } = await axios.put(apiUrl('/api/v1/admin'), {
					githubAppId: this.form.githubAppId,
					githubInstallationId: this.form.githubInstallationId,
					githubPrivateKey: this.form.githubPrivateKey,
					webhookSecret: this.form.webhookSecret,
					syncInterval: this.form.syncInterval,
					oauthClientId: this.form.oauthClientId,
					oauthClientSecret: this.form.oauthClientSecret,
				})
				this.form.githubPrivateKey = ''
				this.form.oauthClientSecret = ''
				this.hasOauthSecret = data.has_oauth_secret
				this.hasPrivateKey = data.has_private_key
				this.notice = tr('Saved.')
			} catch (e) {
				this.error = tr('Save failed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.deckghs-wrap { box-sizing: border-box; max-width: 860px; padding-left: clamp(40px, 5vw, 72px); display: flex; flex-direction: column; gap: 16px; }
.deckghs-card { padding: 4px 0 20px; }
.deckghs-card + .deckghs-card { border-top: 1px solid var(--color-border); padding-top: 20px; }
.deckghs-card h2 { margin: 0 0 8px; font-size: 1.1em; }
.deckghs-steps { margin: 8px 0; padding-left: 22px; display: flex; flex-direction: column; gap: 6px; font-size: 0.95em; }
.deckghs-copyrow { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
.deckghs-muted { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.deckghs-note { border-radius: var(--border-radius); padding: 8px 12px; }
.deckghs-note-ok { background: color-mix(in srgb, var(--color-success, #46ba61) 18%, var(--color-main-background)); color: var(--color-main-text); }
.deckghs-note-err { background: color-mix(in srgb, var(--color-error, #d2322d) 18%, var(--color-main-background)); color: var(--color-main-text); border-radius: var(--border-radius); padding: 8px 12px; }
.deckghs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 8px 12px; margin: 8px 0; }
.deckghs-grid label, .deckghs-block { display: flex; flex-direction: column; gap: 4px; font-size: 0.9em; }
.deckghs-block { margin: 8px 0; }
.deckghs-card input, .deckghs-card textarea { background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius); padding: 6px 8px; color: var(--color-main-text); font-family: monospace; }
.deckghs-code { background: var(--color-background-hover); border-radius: var(--border-radius); padding: 4px 8px; font-size: 0.9em; word-break: break-all; }
.deckghs-btn { border: 1px solid var(--color-border); border-radius: var(--border-radius-pill, 999px); padding: 6px 14px; background: var(--color-main-background); color: var(--color-main-text); cursor: pointer; }
.deckghs-btn.primary { background: var(--color-primary-element); border-color: var(--color-primary-element); color: var(--color-primary-element-text, #fff); }
.deckghs-btn.big { align-self: flex-start; padding: 8px 24px; }
.deckghs-btn.small { padding: 3px 10px; font-size: 0.85em; }
.deckghs-btn:disabled { opacity: 0.5; cursor: default; }
.deckghs-btn:focus-visible, .deckghs-card input:focus-visible, .deckghs-card textarea:focus-visible, .deckghs-card select:focus-visible { outline: 2px solid var(--color-main-text); outline-offset: 1px; }
</style>
