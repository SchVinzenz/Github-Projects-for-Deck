<template>
	<div class="deckghs-wrap">
		<div v-if="notice" class="deckghs-note deckghs-note-ok" role="status">{{ notice }}</div>
		<div v-if="error" class="deckghs-note deckghs-note-err" role="alert">{{ error }}</div>

		<section class="deckghs-card">
			<div class="deckghs-connection-header">
				<div>
					<h2>GitHub verbinden</h2>
					<p class="deckghs-muted">Verbinde dein persönliches GitHub-Konto für die Project-Synchronisation.</p>
				</div>
				<span v-if="!loading" class="deckghs-status" :class="status.connected ? 'connected' : 'disconnected'">
					<span class="deckghs-dot" />{{ status.connected ? 'Verbunden' : 'Nicht verbunden' }}
				</span>
			</div>
			<div v-if="loading" class="deckghs-muted">Wird geladen …</div>
			<div v-else class="deckghs-conn">
				<div class="deckghs-conn-text">
					<strong v-if="status.connected">GitHub-Konto: {{ status.login }}</strong>
					<strong v-else>Dein GitHub-Konto ist noch nicht verbunden.</strong>
					<p class="deckghs-muted">
						<span v-if="status.connected">Projects werden mit diesem Konto abgerufen und synchronisiert.</span>
						<span v-else>{{ status.reason || 'Verbinde dein GitHub-Konto, damit Boards synchronisiert werden können.' }}</span>
					</p>
				</div>
				<div class="deckghs-actions">
					<a v-if="!status.connected && status.oauth" class="deckghs-btn primary" :href="apiUrl('/oauth/start')">Mit GitHub verbinden →</a>
					<button v-if="status.connected" class="deckghs-btn" :disabled="checkingConnection" @click="refreshConnection">{{ checkingConnection ? 'Prüfe …' : 'Verbindung prüfen' }}</button>
					<button v-if="status.connected" class="deckghs-btn" @click="disconnect">Trennen</button>
				</div>
			</div>
			<p v-if="!loading && !status.connected && !status.oauth" class="deckghs-muted">Für die Anmeldung per Klick muss ein Admin einmalig eine GitHub OAuth App einrichten.</p>
			<details v-if="!status.connected" class="deckghs-pat">
				<summary>Alternativ: Personal Access Token eintragen</summary>
				<p class="deckghs-muted">Fine-grained Token mit <code>Projects: Read &amp; Write</code> und <code>Issues: Read &amp; Write</code>.</p>
				<div class="deckghs-row">
					<label>GitHub-Token <input v-model="pat" type="password" placeholder="github_pat_…" autocomplete="off" /></label>
					<button class="deckghs-btn primary" :disabled="!pat || savingPat" @click="savePat">{{ savingPat ? 'Prüfe …' : 'Token speichern' }}</button>
				</div>
			</details>
		</section>

		<section class="deckghs-card">
			<h2>Board-Mappings</h2>
			<p v-if="!loading && !status.connected" class="deckghs-note deckghs-note-warn">Tipp: Verbinde zuerst oben dein GitHub-Konto – sonst schlägt das Anlegen fehl.</p>
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
			<p class="deckghs-muted">Wähle ein Deck-Board und ein GitHub Project. GitHub-Repositories sind keine Project-Mappings.</p>
			<div class="deckghs-row">
				<label>Deck-Board
					<select v-model.number="form.deckBoardId">
						<option :value="0" disabled>Bitte wählen …</option>
						<option v-for="b in boards" :key="b.id" :value="b.id">{{ b.title }}</option>
					</select>
				</label>
				<label v-if="projectMode === 'select'">GitHub Project
					<select v-model="selectedProjectId" :disabled="projectsLoading || !status.connected">
						<option value="" disabled>{{ projectsLoading ? 'Lade Projects …' : 'Bitte wählen …' }}</option>
						<option v-for="p in projects" :key="p.id" :value="p.id">{{ p.owner }} / {{ p.title }} (#{{ p.number }})</option>
					</select>
				</label>
				<template v-else>
					<label>Owner <input v-model="form.githubOwner" placeholder="z. B. meine-org" /></label>
					<label>Project-Nr. <input v-model.number="form.githubNumber" type="number" min="1" /></label>
				</template>
				<label>Richtung
					<select v-model="form.direction">
						<option value="both">Bidirektional</option>
						<option value="deck_to_github">Deck → GitHub</option>
						<option value="github_to_deck">GitHub → Deck</option>
					</select>
				</label>
				<button class="deckghs-btn primary" :disabled="!canCreate || creating" @click="create">{{ creating ? 'Legt an …' : 'Anlegen' }}</button>
			</div>
			<p v-if="projectError" class="deckghs-note deckghs-note-warn">{{ projectError }}</p>
			<div class="deckghs-row">
				<button v-if="projectMode === 'select' && status.connected" class="deckghs-btn" :disabled="projectsLoading" @click="loadProjects">Projects aktualisieren</button>
				<button class="deckghs-btn" @click="projectMode = projectMode === 'select' ? 'manual' : 'select'">{{ projectMode === 'select' ? 'Project manuell eingeben' : 'Zur Project-Auswahl' }}</button>
			</div>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const DIRS = { both: 'Bidirektional', deck_to_github: 'Deck → GitHub', github_to_deck: 'GitHub → Deck' }
const OAUTH_ERRORS = {
	no_oauth_app: 'GitHub OAuth ist noch nicht vollständig eingerichtet. Client ID und Client Secret in den Admin-Einstellungen prüfen.',
	invalid_state: 'Die Anmeldung konnte deiner Nextcloud-Sitzung nicht zugeordnet werden. Bitte erneut starten und Cookies zulassen.',
	exchange_failed: 'GitHub konnte den Anmeldecode nicht einlösen. Client ID, Client Secret und Callback-URL prüfen.',
	incorrect_client_credentials: 'Client ID oder Client Secret der GitHub OAuth App sind falsch. Bitte den Admin informieren.',
	redirect_uri_mismatch: 'Die GitHub-Callback-URL stimmt nicht mit der URL in den Admin-Einstellungen überein.',
	bad_verification_code: 'Der GitHub-Anmeldecode ist abgelaufen oder ungültig. Bitte erneut verbinden.',
	unverified_user_email: 'Bitte zuerst die primäre E-Mail-Adresse deines GitHub-Kontos bestätigen.',
	access_denied: 'Die GitHub-Anmeldung wurde abgebrochen.',
}
const apiUrl = (path) => generateUrl('/apps/deckgithubsync' + path)

export default {
	name: 'Personal',
	data() {
		return {
			loading: true,
			mappings: [],
			boards: [],
			projects: [],
			projectsLoading: false,
			projectError: '',
			projectMode: 'select',
			selectedProjectId: '',
			status: { connected: false, oauth: false },
			pat: '',
			form: { deckBoardId: 0, githubOwner: '', githubNumber: null, direction: 'both' },
			results: {},
			syncing: {},
			checkingConnection: false,
			savingPat: false,
			creating: false,
			notice: '',
			error: '',
		}
	},
	computed: {
		canCreate() {
			return this.form.deckBoardId > 0 && (this.projectMode === 'select'
				? this.projects.some((p) => p.id === this.selectedProjectId)
				: this.form.githubOwner.trim() !== '' && (this.form.githubNumber || 0) > 0)
		},
	},
	async mounted() {
		const q = new URLSearchParams(window.location.search)
		const returnedFromOAuth = q.get('gh_connected') === '1'
		if (q.get('gh_error')) {
			this.error = OAUTH_ERRORS[q.get('gh_error')] || 'GitHub-Verbindung fehlgeschlagen.'
		}
		try {
			const [maps, boards, status] = await Promise.all([
				axios.get(apiUrl('/api/v1/mappings')),
				axios.get(apiUrl('/api/v1/deck/boards')),
				axios.get(apiUrl('/api/v1/github/status')),
			])
			this.mappings = Array.isArray(maps.data) ? maps.data : []
			this.boards = Array.isArray(boards.data) ? boards.data : []
			this.status = status.data
			if (returnedFromOAuth) {
				if (this.status.connected) {
					this.notice = `Erfolgreich mit GitHub verbunden als ${this.status.login}.`
				} else {
					this.error = this.status.reason || 'GitHub hat die Anmeldung abgeschlossen, aber die Verbindung konnte nicht bestätigt werden.'
				}
			}
			if (this.status.connected) {
				await this.loadProjects()
			}
		} catch (e) {
			this.error = 'Daten konnten nicht geladen werden.'
		} finally {
			this.loading = false
			if (returnedFromOAuth || q.has('gh_error')) {
				q.delete('gh_connected')
				q.delete('gh_error')
				window.history.replaceState(window.history.state, '', window.location.pathname + (q.toString() ? '?' + q : '') + window.location.hash)
			}
		}
	},
	methods: {
		apiUrl,
		async refreshConnection() {
			this.checkingConnection = true
			this.error = ''
			this.notice = ''
			try {
				const { data } = await axios.get(apiUrl('/api/v1/github/status'))
				this.status = data
				if (data.connected) {
					this.notice = `GitHub-Verbindung bestätigt: ${data.login}.`
					await this.loadProjects()
				} else {
					this.projects = []
					this.error = data.reason || 'GitHub-Verbindung konnte nicht bestätigt werden.'
				}
			} catch (e) {
				this.error = 'Verbindung konnte nicht geprüft werden. Bitte später erneut versuchen.'
			} finally {
				this.checkingConnection = false
			}
		},
		async loadProjects() {
			this.projectsLoading = true
			this.projectError = ''
			try {
				const { data } = await axios.get(apiUrl('/api/v1/github/projects'))
				this.projects = Array.isArray(data) ? data : []
				if (!this.projects.some((p) => p.id === this.selectedProjectId)) {
					this.selectedProjectId = ''
				}
				if (!this.projects.length) {
					this.projectError = 'Keine Projects gefunden. Prüfe die GitHub-Berechtigungen oder gib das Project manuell ein.'
				}
			} catch (e) {
				this.projects = []
				this.selectedProjectId = ''
				this.projectError = e.response?.data?.error || 'Projects konnten nicht geladen werden. Du kannst sie manuell eingeben.'
			} finally {
				this.projectsLoading = false
			}
		},
		boardTitle(id) {
			return (this.boards.find((b) => b.id === id) || {}).title || ('Board ' + id)
		},
		dirLabel(d) {
			return DIRS[d] || d
		},
		async create() {
			this.error = ''
			this.notice = ''
			this.creating = true
			try {
				const selected = this.projects.find((p) => p.id === this.selectedProjectId)
				const payload = this.projectMode === 'select'
					? { ...this.form, githubOwner: selected.owner, githubNumber: selected.number }
					: this.form
				const { data } = await axios.post(apiUrl('/api/v1/mappings'), payload)
				this.mappings.push(data)
				this.form = { deckBoardId: 0, githubOwner: '', githubNumber: null, direction: 'both' }
				this.selectedProjectId = ''
				this.notice = 'Mapping angelegt.'
			} catch (e) {
				this.error = e.response?.data?.error || 'Mapping konnte nicht angelegt werden (GitHub-Project prüfen).'
			} finally {
				this.creating = false
			}
		},
		async update(m) {
			try {
				await axios.put(apiUrl(`/api/v1/mappings/${m.id}`), {
					direction: m.direction,
					fieldConfig: m.fieldConfig,
				})
			} catch (e) {
				this.error = 'Speichern fehlgeschlagen.'
			}
		},
		async saveUsers(m) {
			try {
				const { data } = await axios.put(apiUrl(`/api/v1/mappings/${m.id}/users`), {
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
			this.error = ''
			try {
				await axios.delete(apiUrl(`/api/v1/mappings/${m.id}`))
				this.mappings = this.mappings.filter((x) => x.id !== m.id)
				this.notice = 'Mapping gelöscht.'
			} catch (e) {
				this.error = e.response?.data?.error || 'Mapping konnte nicht gelöscht werden.'
			}
		},
		async sync(m) {
			this.syncing[m.id] = true
			try {
				const { data } = await axios.post(apiUrl(`/api/v1/sync/${m.id}`))
				const errs = (data.errors || []).length
				this.results[m.id] = `Deck→GitHub: ${data.deck_to_github}, GitHub→Deck: ${data.github_to_deck}` + (errs ? `, Fehler: ${errs}` : '')
				if (errs) {
					this.error = data.errors.join('; ')
				} else {
					m.lastSync = Math.floor(Date.now() / 1000)
				}
			} catch (e) {
				this.error = 'Sync fehlgeschlagen.'
			} finally {
				this.syncing[m.id] = false
			}
		},
		async savePat() {
			this.error = ''
			this.notice = ''
			this.savingPat = true
			try {
				const { data } = await axios.put(apiUrl('/api/v1/github/token'), { token: this.pat })
				this.status = { connected: true, login: data.login, oauth: this.status.oauth }
				this.pat = ''
				this.notice = `Erfolgreich mit GitHub verbunden als ${data.login}.`
				await this.loadProjects()
			} catch (e) {
				const status = e.response?.status
				this.error = e.response?.data?.error
					|| (status === 403 ? 'Nextcloud hat die Anfrage abgelehnt (403). Bitte neu anmelden und erneut versuchen.'
						: status === 404 ? 'Der Token-Endpunkt wurde nicht gefunden (404). Bitte die App aktualisieren.'
							: status ? `Token konnte nicht gespeichert werden (HTTP ${status}).`
								: 'Nextcloud ist nicht erreichbar. Bitte Verbindung prüfen.')
			} finally {
				this.savingPat = false
			}
		},
		async disconnect() {
			try {
				await axios.delete(apiUrl('/api/v1/github/token'))
				this.status = { connected: false, oauth: this.status.oauth }
				this.projects = []
				this.selectedProjectId = ''
				this.projectError = ''
				this.error = ''
				this.notice = 'GitHub-Verbindung getrennt.'
			} catch (e) {
				this.error = 'GitHub-Verbindung konnte nicht getrennt werden.'
			}
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
.deckghs-note-warn { background: var(--color-warning-background, #fdf3e0); }
.deckghs-note-err, .error { background: var(--color-error-background, #fdecea); color: var(--color-error-text, inherit); border-radius: var(--border-radius); padding: 8px 12px; }
.deckghs-connection-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.deckghs-connection-header p { margin: 0 0 14px; }
.deckghs-status { display: inline-flex; align-items: center; gap: 7px; padding: 5px 11px; border: 1px solid var(--color-border); border-radius: var(--border-radius-pill, 999px); font-size: 0.85em; white-space: nowrap; }
.deckghs-status.connected { color: var(--color-success-text, var(--color-main-text)); background: var(--color-success-background, transparent); }
.deckghs-status.disconnected { color: var(--color-text-maxcontrast); }
.deckghs-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; background: var(--color-warning, #e6a817); }
.deckghs-status.connected .deckghs-dot { background: var(--color-success, #46ba61); }
.deckghs-conn { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px; border: 1px solid var(--color-border); border-radius: var(--border-radius); background: var(--color-background-hover); }
.deckghs-conn-text { flex: 1; }
.deckghs-conn-text p { margin: 4px 0 0; }
.deckghs-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.deckghs-row { display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; margin: 8px 0; }
.deckghs-row label { display: flex; flex-direction: column; gap: 4px; font-size: 0.9em; }
.deckghs-row input, .deckghs-row select, .deckghs-pat input { background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius); padding: 6px 8px; color: var(--color-main-text); }
.deckghs-btn { display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--color-border); border-radius: var(--border-radius-pill, 999px); padding: 6px 14px; background: var(--color-main-background); color: var(--color-main-text); cursor: pointer; text-decoration: none; }
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
.deckghs-pat { margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--color-border); }
.deckghs-pat summary { cursor: pointer; color: var(--color-text-maxcontrast); }
</style>
