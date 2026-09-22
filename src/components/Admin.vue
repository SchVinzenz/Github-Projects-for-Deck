<template>
	<div class="deckghs-admin">
		<h2>Deck ↔ GitHub Projects Sync</h2>
		<label>GitHub App ID <input v-model="appId" /></label>
		<label>Installation ID <input v-model="installationId" /></label>
		<label>Sync-Intervall (s) <input v-model.number="interval" type="number" min="300" /></label>
		<button @click="save">Speichern</button>
		<p>Webhook-URL: <code>{{ webhookUrl }}</code> (Events: projects_v2_item, issues, pull_request)</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'

export default {
	name: 'Admin',
	data() {
		return { appId: '', installationId: '', interval: 900 }
	},
	computed: {
		webhookUrl() {
			return window.location.origin + '/index.php/apps/deckgithubsync/webhook/github'
		},
	},
	async mounted() {
		const { data } = await axios.get('/index.php/apps/deckgithubsync/api/v1/admin')
		this.appId = data.github_app_id
		this.installationId = data.github_installation_id
		this.interval = data.sync_interval
	},
	methods: {
		async save() {
			await axios.put('/index.php/apps/deckgithubsync/api/v1/admin', {
				githubAppId: this.appId,
				githubInstallationId: this.installationId,
				syncInterval: this.interval,
			})
		},
	},
}
</script>
