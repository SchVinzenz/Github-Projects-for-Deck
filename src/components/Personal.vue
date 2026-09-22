<template>
	<div class="deckghs-personal">
		<h2>Board-Mappings</h2>
		<p v-if="error" class="error">{{ error }}</p>
		<div v-for="m in mappings" :key="m.id">
			<span>Board {{ m.deckBoardId }} ↔ {{ m.githubOwner }}#{{ m.githubNumber }} ({{ m.direction }})</span>
			<select v-model="m.direction" @change="update(m)">
				<option value="both">Bidirektional</option>
				<option value="deck_to_github">Deck → GitHub</option>
				<option value="github_to_deck">GitHub → Deck</option>
			</select>
			<button @click="sync(m)">Sync</button>
			<button @click="remove(m)">Löschen</button>
			<details>
				<summary>Felder</summary>
				<label v-for="f in Object.keys(m.fieldConfig)" :key="f">
					{{ f }}
					<select v-model="m.fieldConfig[f]" @change="update(m)">
						<option value="both">↔</option>
						<option value="deck_to_github">→</option>
						<option value="github_to_deck">←</option>
						<option value="off">aus</option>
					</select>
				</label>
			</details>
		</div>
		<h3>Neu</h3>
		<label>Deck Board ID <input v-model.number="form.deckBoardId" type="number" /></label>
		<label>Owner <input v-model="form.githubOwner" /></label>
		<label>Project Nr <input v-model.number="form.githubNumber" type="number" /></label>
		<button @click="create">Anlegen</button>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'

export default {
	name: 'Personal',
	data() {
		return { mappings: [], form: { deckBoardId: 0, githubOwner: '', githubNumber: 0 }, error: '' }
	},
	async mounted() {
		try {
			const { data } = await axios.get('/index.php/apps/deckgithubsync/api/v1/mappings')
			this.mappings = data
		} catch (e) {
			this.error = 'Mappings konnten nicht geladen werden.'
		}
	},
	methods: {
		async create() {
			const { data } = await axios.post('/index.php/apps/deckgithubsync/api/v1/mappings', this.form)
			this.mappings.push(data)
		},
		async update(m) {
			await axios.put(`/index.php/apps/deckgithubsync/api/v1/mappings/${m.id}`, {
				direction: m.direction,
				fieldConfig: m.fieldConfig,
			})
		},
		async remove(m) {
			await axios.delete(`/index.php/apps/deckgithubsync/api/v1/mappings/${m.id}`)
			this.mappings = this.mappings.filter((x) => x.id !== m.id)
		},
		async sync(m) {
			await axios.post(`/index.php/apps/deckgithubsync/api/v1/sync/${m.id}`)
		},
	},
}
</script>
