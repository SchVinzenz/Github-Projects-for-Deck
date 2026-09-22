<template>
	<div class="deckghs-wrap">
		<div v-if="notice" class="deckghs-note deckghs-note-ok">{{ notice }}</div>
		<div v-if="error" class="deckghs-note deckghs-note-err">{{ error }}</div>

		<section class="deckghs-card">
			<h2>GitHub-Verbindung</h2>
			<div v-if="loading" class="deckghs-muted">Wird geladen …</div>
			<div v-else class="deckghs-conn">
				<span class="deckghs-dot" :class="status.connected ? 'on' : 'off'" />
				<div class="deckghs-conn-text">
					<strong v-if="status.connected">Verbunden als {{ status.login }}</strong>
					<strong v-else>Nicht verbunden</strong>
					<p class="deckghs-muted">
						<span v-if="status.connected">Sync und Board-Zugriff laufen über dieses Konto.</span>
						<span v-else>Verbinde dein GitHub-Konto, damit Boards synchronisiert werden können.</span>
					</p>
				</div>
				<div class="deckghs-actions">
					<a v-if="!status.connected && status.oauth" class="deckghs-btn primary" href="/index.php/apps/deckgithubsync/oauth/start">Mit GitHub verbinden</a>
					<button v-if="status.connected" class="deckghs-btn" @click="disconnect">Trennen</button>
				</div>
			</div>
			<details v-if="!status.connected" class="deckghs-pat">
				<summary>Alternativ: Personal Access Token eintragen</summary>
				<p class="deckghs-muted">Fine-grained Token mit <code>Projects: Read &amp; Write</code> und <code>Issues: Read &amp; Write</code>.</p>
				<div class="deckghs-row">
					<input v-model="pat" type="password" placeholder="github_pat_…" autocomplete="off" />
					<button class="deckghs-btn primary" :disabled="!pat" @click="savePat">Speichern</button>
				</div>
			</details>
		</section>

		<section class="deckghs-card">
			<h2>Board-Mappings</h2>
			<p v-if="!mappings.length" class="deckghs-muted">Noch keine Mappings. Lege unten dein erstes an.</p>
			<article v-for="m in mappings" :key="m.id" class="deckghs-map">
				<header>
					<strong>{{ boardTitle(m.deckBoardId) }} <span class="deckghs-muted">↔ {{ m.githubOwner }}#{{ m.githubNumber }}</span></strong>
					<span class="deckghs-pill">{{ dirLabel(m.direction) }}</span>
				</header>
				<div class="deckghs-row">
					<label>Richtung
						<select v-model="m.direction" @change="update(m)">
							<option value="both">Bidirektional</option>
							<option value="deck_to_github">Deck → GitHub</option>
							<option value="github_to_deck">GitHub → Deck</option>
						</select>
					</label>
					<span class="deckghs-muted">Sync: {{ m.lastSync ? new Date(m.lastSync * 1000).toLocaleString() : 'noch nie' }}</span>
					<span class="deckghs-spacer" />
					<button class="deckghs-btn primary" :disabled="syncing[m.id]" @click="sync(m)">{{ syncing[m.id] ? 'Läuft …' : 'Jetzt syncen' }}</button>
					<button class="deckghs-btn danger" @click="remove(m)">Löschen</button>
				</div>
				<p v-if="results[m.id]" class="deckghs-muted">{{ results[m.id] }}</p>
				<details>
					<summary>Felder &amp; Nutzer</summary>
					<div class="deckghs-fields">
						<label v-for="f in Object.keys(m.fieldConfig)" :key="f">{{ f }}
							<select v-model="m.fieldConfig[f]" @change="update(m)">
								<option value="both">↔ beidseitig</option>
								<option value="deck_to_github">→ nur Deck zu GitHub</option>
								<option value="github_to_deck">← nur GitHub zu Deck</option>
								<option value="off">aus</option>
							</select>
						</label>
					</div>
					<div class="deckghs-users">
						<p class="deckghs-muted">GitHub-Login → Deck-Benutzer (für Assignees)</p>
						<div v-for="(u, i) in m.userMap" :key="i" class="deckghs-row">
							<input v-model="u.githubLogin" placeholder="GitHub-Login" />
							<input v-model="u.deckUid" placeholder="Deck-Benutzer" />
							<button class="deckghs-btn" @click="m.userMap.splice(i, 1); saveUsers(m)">✕</button>
						</div>
						<button class="deckghs-btn" @click="m.userMap.push({ githubLogin: '', deckUid: '' })">Zeile hinzufügen</button>
						<button class="deckghs-btn primary" @click="saveUsers(m)">Nutzer-Mapping speichern</button>
					</div>
				</details>
			</article>
		</section>

		<section class="deckghs-card">
			<h2>Neues Mapping</h2>
			<div class="deckghs-row">
				<label>Deck-Board
					<select v-model.number="form.deckBoardId">
						<option :value="0" disabled>Bitte wählen …</option>
						<option v-for="b in boards" :key="b.id" :value="b.id">{{ b.title }}</option>
					</select>
				</label>
				<label>Owner <input v-model="form.githubOwner" placeholder="z. B. meine-org" /></label>
				<label>Project-Nr. <input v-model.number="form.githubNumber" type="number" min="1" /></label>
				<label>Richtung
					<select v-model="form.direction">
						<option value="both">Bidirektional</option>
						<option value="deck_to_github">Deck → GitHub</option>
						<option value="github_to_deck">GitHub → Deck</option>
					</select>
				</label>
				<button class="deckghs-btn primary" :disabled="!canCreate" @click="create">Anlegen</button>
			</div>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'

const DIRS = { both: 'Bidirektional', deck_to_github: 'Deck → GitHub', github_to_deck: 'GitHub → Deck' }

export default {
	name: 'Personal',
	data() {
		return {
			loading: true,
			mappings: [],
			boards: [],
			status: { connected: false, oauth: false },
			pat: '',
			form: { deckBoardId: 0, githubOwner: '', githubNumber: null, direction: 'both' },
			results: {},
			syncing: {},
			notice: '',
			error: '',
		}
	},
	computed: {
		canCreate() {
			return this.form.deckBoardId > 0 && this.form.githubOwner.trim() !== '' && (this.form.githubNumber || 0) > 0
		},
	},
	async mounted() {
		const q = new URLSearchParams(window.location.search)
		if (q.get('gh_connected')) {
			this.notice = 'GitHub erfolgreich verbunden.'
		} else if (q.get('gh_error')) {
			this.error = 'GitHub-Verbindung fehlgeschlagen (' + q.get('gh_error') + ').'
		}
		try {
			const [maps, boards, status] = await Promise.all([
				axios.get('/index.php/apps/deckgithubsync/api/v1/mappings'),
				axios.get('/index.php/apps/deckgithubsync/api/v1/deck/boards'),
				axios.get('/index.php/apps/deckgithubsync/api/v1/github/status'),
			])
			this.mappings = Array.isArray(maps.data) ? maps.data : []
			this.boards = Array.isArray(boards.data) ? boards.data : []
			this.status = status.data
		} catch (e) {
			this.error = 'Daten konnten nicht geladen werden.'
		} finally {
			this.loading = false
		}
	},
	methods: {
		boardTitle(id) {
			return (this.boards.find((b) => b.id === id) || {}).title || ('Board ' + id)
		},
		dirLabel(d) {
			return DIRS[d] || d
		},
		async create() {
			this.error = ''
			try {
				const { data } = await axios.post('/index.php/apps/deckgithubsync/api/v1/mappings', this.form)
				this.mappings.push(data)
				this.form = { deckBoardId: 0, githubOwner: '', githubNumber: null, direction: 'both' }
			} catch (e) {
				this.error = e.response?.data?.error || 'Mapping konnte nicht angelegt werden (GitHub-Project prüfen).'
			}
		},
		async update(m) {
			try {
				await axios.put(`/index.php/apps/deckgithubsync/api/v1/mappings/${m.id}`, {
					direction: m.direction,
					fieldConfig: m.fieldConfig,
				})
			} catch (e) {
				this.error = 'Speichern fehlgeschlagen.'
			}
		},
		async saveUsers(m) {
			try {
				const { data } = await axios.put(`/index.php/apps/deckgithubsync/api/v1/mappings/${m.id}/users`, {
					users: m.userMap.filter((u) => u.githubLogin && u.deckUid),
				})
				m.userMap = data.userMap
				this.notice = 'Nutzer-Mapping gespeichert.'
			} catch (e) {
				this.error = 'Nutzer-Mapping konnte nicht gespeichert werden.'
			}
		},
		async remove(m) {
			if (!window.confirm('Mapping wirklich löschen?')) {
				return
			}
			await axios.delete(`/index.php/apps/deckgithubsync/api/v1/mappings/${m.id}`)
			this.mappings = this.mappings.filter((x) => x.id !== m.id)
		},
		async sync(m) {
			this.syncing[m.id] = true
			try {
				const { data } = await axios.post(`/index.php/apps/deckgithubsync/api/v1/sync/${m.id}`)
				const errs = (data.errors || []).length
				this.results[m.id] = `Deck→GitHub: ${data.deck_to_github}, GitHub→Deck: ${data.github_to_deck}` + (errs ? `, Fehler: ${errs}` : '')
				m.lastSync = Math.floor(Date.now() / 1000)
				if (errs) {
					this.error = data.errors.join('; ')
				}
			} catch (e) {
				this.error = 'Sync fehlgeschlagen.'
			} finally {
				this.syncing[m.id] = false
			}
		},
		async savePat() {
			this.error = ''
			try {
				const { data } = await axios.put('/index.php/apps/deckgithubsync/api/v1/github/token', { token: this.pat })
				this.status = { connected: true, login: data.login, oauth: this.status.oauth }
				this.pat = ''
				this.notice = 'Token gespeichert.'
			} catch (e) {
				this.error = e.response?.data?.error || 'Token ungültig.'
			}
		},
		async disconnect() {
			await axios.delete('/index.php/apps/deckgithubsync/api/v1/github/token')
			this.status = { connected: false, oauth: this.status.oauth }
		},
	},
}
</script>

<style scoped>
.deckghs-wrap { max-width: 860px; display: flex; flex-direction: column; gap: 16px; }
.deckghs-card { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 16px 20px; background: var(--color-main-background); }
.deckghs-card h2 { margin: 0 0 12px; font-size: 1.1em; }
.deckghs-muted { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.deckghs-note { border-radius: var(--border-radius); padding: 8px 12px; }
.deckghs-note-ok { background: var(--color-success-background, #e6f4ea); }
.deckghs-note-err, .error { background: var(--color-error-background, #fdecea); color: var(--color-error-text, inherit); border-radius: var(--border-radius); padding: 8px 12px; }
.deckghs-conn { display: flex; gap: 12px; align-items: flex-start; }
.deckghs-dot { width: 12px; height: 12px; border-radius: 50%; margin-top: 4px; flex-shrink: 0; }
.deckghs-dot.on { background: var(--color-success, #46ba61); }
.deckghs-dot.off { background: var(--color-warning, #e6a817); }
.deckghs-conn-text { flex: 1; }
.deckghs-conn-text p { margin: 4px 0 0; }
.deckghs-actions { display: flex; gap: 8px; }
.deckghs-row { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin: 8px 0; }
.deckghs-row label { display: flex; flex-direction: column; gap: 4px; font-size: 0.9em; }
.deckghs-row input, .deckghs-row select, .deckghs-pat input { background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius); padding: 6px 8px; color: var(--color-main-text); }
.deckghs-btn { border: 1px solid var(--color-border); border-radius: var(--border-radius-pill, 999px); padding: 6px 14px; background: var(--color-main-background); color: var(--color-main-text); cursor: pointer; }
.deckghs-btn.primary { background: var(--color-primary-element); border-color: var(--color-primary-element); color: var(--color-primary-element-text, #fff); }
.deckghs-btn.danger { color: var(--color-error); }
.deckghs-btn:disabled { opacity: 0.5; cursor: default; }
.deckghs-map { border-top: 1px solid var(--color-border); padding: 12px 0; }
.deckghs-map header { display: flex; gap: 8px; align-items: center; justify-content: space-between; }
.deckghs-pill { font-size: 0.8em; border: 1px solid var(--color-border); border-radius: var(--border-radius-pill, 999px); padding: 2px 10px; color: var(--color-text-maxcontrast); white-space: nowrap; }
.deckghs-spacer { flex: 1; }
.deckghs-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; margin: 8px 0; }
.deckghs-fields label { display: flex; flex-direction: column; gap: 4px; font-size: 0.9em; }
.deckghs-fields select { background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius); padding: 6px 8px; color: var(--color-main-text); }
.deckghs-users { margin-top: 8px; }
.deckghs-pat { margin-top: 12px; }
.deckghs-pat summary { cursor: pointer; }
</style>
