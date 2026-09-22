<template>
	<div class="deckghs-wrap">
		<div v-if="notice" class="deckghs-note deckghs-note-ok">{{ notice }}</div>
		<div v-if="error" class="deckghs-note deckghs-note-err">{{ error }}</div>

		<section class="deckghs-card">
			<h2>GitHub App <span class="deckghs-muted">(Server-Sync)</span></h2>
			<p class="deckghs-muted">Für Hintergrund-Sync ohne Nutzer-Token. Permissions: Projects R/W, Issues R/W.</p>
			<div class="deckghs-grid">
				<label>App ID <input v-model="form.githubAppId" inputmode="numeric" /></label>
				<label>Installation ID <input v-model="form.githubInstallationId" inputmode="numeric" /></label>
			</div>
			<label class="deckghs-block">Private Key (.pem)
				<textarea v-model="form.githubPrivateKey" rows="3" placeholder="Nur beim Ändern einfügen – gespeicherter Key bleibt sonst erhalten." autocomplete="off" />
			</label>
			<div class="deckghs-grid">
				<label>Webhook Secret <input v-model="form.webhookSecret" type="password" autocomplete="off" /></label>
				<label>Sync-Intervall (s, min. 300) <input v-model.number="form.syncInterval" type="number" min="300" /></label>
			</div>
		</section>

		<section class="deckghs-card">
			<h2>GitHub OAuth <span class="deckghs-muted">(Login für Nutzer)</span></h2>
			<p class="deckghs-muted">OAuth App unter GitHub → Settings → Developer settings anlegen. Authorization callback URL:</p>
			<p><code class="deckghs-code">{{ form.oauthCallbackUrl }}</code></p>
			<div class="deckghs-grid">
				<label>Client ID <input v-model="form.oauthClientId" autocomplete="off" /></label>
				<label>Client Secret <input v-model="form.oauthClientSecret" type="password" autocomplete="off" placeholder="Nur beim Ändern einfügen" /></label>
			</div>
		</section>

		<button class="deckghs-btn primary big" :disabled="saving" @click="save">{{ saving ? 'Speichert …' : 'Speichern' }}</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'

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
			notice: '',
			error: '',
		}
	},
	async mounted() {
		try {
			const { data } = await axios.get('/index.php/apps/deckgithubsync/api/v1/admin')
			this.form.githubAppId = data.github_app_id
			this.form.githubInstallationId = data.github_installation_id
			this.form.syncInterval = data.sync_interval
			this.form.oauthClientId = data.oauth_client_id
			this.form.oauthCallbackUrl = data.oauth_callback_url
		} catch (e) {
			this.error = 'Konfiguration konnte nicht geladen werden.'
		}
	},
	methods: {
		async save() {
			this.saving = true
			this.error = ''
			this.notice = ''
			try {
				await axios.put('/index.php/apps/deckgithubsync/api/v1/admin', {
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
				this.notice = 'Gespeichert.'
			} catch (e) {
				this.error = 'Speichern fehlgeschlagen.'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.deckghs-wrap { max-width: 860px; display: flex; flex-direction: column; gap: 16px; }
.deckghs-card { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 16px 20px; background: var(--color-main-background); }
.deckghs-card h2 { margin: 0 0 8px; font-size: 1.1em; }
.deckghs-muted { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.deckghs-note { border-radius: var(--border-radius); padding: 8px 12px; }
.deckghs-note-ok { background: var(--color-success-background, #e6f4ea); }
.deckghs-note-err { background: var(--color-error-background, #fdecea); border-radius: var(--border-radius); padding: 8px 12px; }
.deckghs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 8px 12px; margin: 8px 0; }
.deckghs-grid label, .deckghs-block { display: flex; flex-direction: column; gap: 4px; font-size: 0.9em; }
.deckghs-block { margin: 8px 0; }
.deckghs-card input, .deckghs-card textarea { background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius); padding: 6px 8px; color: var(--color-main-text); font-family: monospace; }
.deckghs-code { background: var(--color-background-hover); border-radius: var(--border-radius); padding: 4px 8px; font-size: 0.9em; word-break: break-all; }
.deckghs-btn { border: 1px solid var(--color-border); border-radius: var(--border-radius-pill, 999px); padding: 6px 14px; background: var(--color-main-background); color: var(--color-main-text); cursor: pointer; }
.deckghs-btn.primary { background: var(--color-primary-element); border-color: var(--color-primary-element); color: var(--color-primary-element-text, #fff); }
.deckghs-btn.big { align-self: flex-start; padding: 8px 24px; }
.deckghs-btn:disabled { opacity: 0.5; cursor: default; }
</style>
