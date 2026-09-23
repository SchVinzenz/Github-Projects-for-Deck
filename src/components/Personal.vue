<template>
	<div class="deckghs-wrap">
		<div v-if="notice" class="deckghs-note deckghs-note-ok" role="status">{{ notice }}</div>
		<div v-if="error" class="deckghs-note deckghs-note-err" role="alert">{{ error }}</div>

		<section class="deckghs-card">
			<div class="deckghs-connection-header">
				<div>
					<h2>{{ tr("Connect GitHub") }}</h2>
					<p class="deckghs-muted">{{ tr("Connect your personal GitHub account to sync projects.") }}</p>
				</div>
				<span v-if="!loading" class="deckghs-status" :class="status.connected ? 'connected' : 'disconnected'">
					<span class="deckghs-dot" />{{ status.connected ? tr('Connected') : tr('Not connected') }}
				</span>
			</div>
			<div v-if="loading" class="deckghs-muted">{{ tr("Loading …") }}</div>
			<div v-else class="deckghs-conn">
				<div class="deckghs-conn-text">
					<strong v-if="status.connected">{{ tr("GitHub account:") }} {{ status.login }}</strong>
					<strong v-else>{{ tr("Your GitHub account is not connected yet.") }}</strong>
					<p class="deckghs-muted">
						<span v-if="status.connected">{{ tr("Projects are fetched and synced with this account.") }}</span>
						<span v-else>{{ status.reason || tr('Connect your GitHub account to sync boards.') }}</span>
					</p>
				</div>
				<div class="deckghs-actions">
					<a v-if="!status.connected && status.oauth" class="deckghs-btn primary" :href="apiUrl('/oauth/start')">{{ tr("Connect with GitHub →") }}</a>
					<button v-if="status.connected" class="deckghs-btn" :disabled="checkingConnection" @click="refreshConnection">{{ checkingConnection ? tr('Checking …') : tr('Check connection') }}</button>
					<button v-if="status.connected" class="deckghs-btn" @click="disconnect">{{ tr("Disconnect") }}</button>
				</div>
			</div>
			<p v-if="!loading && !status.connected && !status.oauth" class="deckghs-muted">{{ tr("An administrator must set up a GitHub OAuth app to enable one-click sign-in.") }}</p>
			<details v-if="!status.connected" class="deckghs-pat">
				<summary>{{ tr("Alternatively: enter a personal access token") }}</summary>
				<p class="deckghs-muted">{{ tr("Fine-grained token with") }} <code>Projects: Read &amp; Write</code> {{ tr("and") }} <code>Issues: Read &amp; Write</code>.</p>
				<div class="deckghs-row">
					<label>{{ tr("GitHub token") }} <input v-model="pat" type="password" placeholder="github_pat_…" autocomplete="off" /></label>
					<button class="deckghs-btn primary" :disabled="!pat || savingPat" @click="savePat">{{ savingPat ? tr('Checking …') : tr('Save token') }}</button>
				</div>
			</details>
		</section>

		<section class="deckghs-card">
			<h2>{{ tr("Board mappings") }}</h2>
			<p v-if="!loading && !status.connected" class="deckghs-note deckghs-note-warn">{{ tr("Connect your GitHub account above before creating a mapping.") }}</p>
			<p v-if="!mappings.length" class="deckghs-muted">{{ tr("No mappings yet. Create your first one below.") }}</p>
			<article v-for="m in mappings" :key="m.id" class="deckghs-map">
				<header>
					<strong class="deckghs-map-title">{{ boardTitle(m.deckBoardId) }} <span class="deckghs-muted">↔ {{ m.githubOwner }}#{{ m.githubNumber }}</span></strong>
					<span class="deckghs-pill">{{ dirLabel(m.direction) }}</span>
				</header>
				<p class="deckghs-meta deckghs-muted">Sync: {{ m.lastSync ? new Date(m.lastSync * 1000).toLocaleString() : tr('never') }}</p>
				<div class="deckghs-row">
					<label>{{ tr("Direction") }}
						<select v-model="m.direction" @change="update(m)">
							<option value="both">{{ tr("Bidirectional") }}</option>
							<option value="deck_to_github">Deck → GitHub</option>
							<option value="github_to_deck">GitHub → Deck</option>
						</select>
					</label>
					<label>{{ tr('Issue repository') }}
						<input v-model.trim="m.githubRepository" list="deckghs-repositories" :placeholder="tr('empty = draft')" @change="update(m)" />
					</label>
					<span class="deckghs-muted">{{ tr("A repository converts linked drafts to issues on the next sync.") }}</span>
					<span class="deckghs-spacer" />
					<button class="deckghs-btn primary" :disabled="syncing[m.id]" @click="sync(m)">{{ syncing[m.id] ? tr('Syncing …') : tr('Sync now') }}</button>
					<button class="deckghs-btn danger" @click="remove(m)">{{ tr("Delete") }}</button>
				</div>
				<p v-if="results[m.id]" :class="resultErrors[m.id] ? 'deckghs-note deckghs-note-err' : 'deckghs-note deckghs-note-ok'">{{ results[m.id] }}</p>
				<details @toggle="loadDateFields(m, $event)">
					<summary>{{ tr("Fields & users") }}</summary>
					<label>{{ tr("GitHub due date field") }}
						<select v-model="m.dateFieldId" @change="update(m)">
							<option value="">{{ tr("Detect automatically") }}</option>
							<option v-for="f in dateFields[m.id] || []" :key="f.id" :value="f.id">{{ f.name }}</option>
						</select>
					</label>
					<div class="deckghs-fields">
						<label v-for="f in Object.keys(m.fieldConfig)" :key="f">{{ f }}
							<select v-model="m.fieldConfig[f]" @change="update(m)">
								<option value="both">{{ tr("↔ both ways") }}</option>
								<option value="deck_to_github">{{ tr("→ Deck to GitHub only") }}</option>
								<option value="github_to_deck">{{ tr("← GitHub to Deck only") }}</option>
								<option value="off">{{ tr("off") }}</option>
							</select>
						</label>
					</div>
					<p class="deckghs-muted">{{ tr("Deck attachments are not synced. Files remain available only in Nextcloud.") }}</p>
					<div class="deckghs-users">
						<p class="deckghs-muted">{{ tr("GitHub login → Deck user (for assignees)") }}</p>
						<div v-for="(u, i) in m.userMap" :key="i" class="deckghs-row">
							<input v-model="u.githubLogin" :placeholder="tr('GitHub login')" />
							<input v-model="u.deckUid" :placeholder="tr('Deck user')" />
							<button class="deckghs-btn" @click="m.userMap.splice(i, 1); saveUsers(m)">✕</button>
						</div>
						<button class="deckghs-btn" @click="m.userMap.push({ githubLogin: '', deckUid: '' })">{{ tr("Add row") }}</button>
						<button class="deckghs-btn primary" @click="saveUsers(m)">{{ tr("Save user mapping") }}</button>
					</div>
				</details>
			</article>
		</section>

		<section class="deckghs-card">
			<h2>{{ tr("New mapping") }}</h2>
			<p class="deckghs-muted">{{ tr("Choose a Deck board and a GitHub Project. GitHub repositories are not project mappings.") }}</p>
			<p class="deckghs-muted">{{ tr("Choose an issue repository to create GitHub issues from cards. Existing linked drafts are converted on the next sync. Without a repository they remain project drafts.") }}</p>
			<div class="deckghs-row">
				<label>{{ tr("Deck board") }}
					<select v-model.number="form.deckBoardId">
						<option :value="0" disabled>{{ tr("Select …") }}</option>
						<option v-for="b in boards" :key="b.id" :value="b.id">{{ b.title }}</option>
					</select>
				</label>
				<label v-if="projectMode === 'select'">GitHub Project
					<select v-model="selectedProjectId" :disabled="projectsLoading || !status.connected">
						<option value="" disabled>{{ projectsLoading ? tr('Loading projects …') : tr('Select …') }}</option>
						<option v-for="p in projects" :key="p.id" :value="p.id">{{ p.owner }} / {{ p.title }} (#{{ p.number }})</option>
					</select>
				</label>
				<template v-else>
					<label>Owner <input v-model="form.githubOwner" :placeholder="tr('e.g. my-org')" /></label>
					<label>{{ tr("Project number") }} <input v-model.number="form.githubNumber" type="number" min="1" /></label>
				</template>
				<label>{{ tr("Direction") }}
					<select v-model="form.direction">
						<option value="both">{{ tr("Bidirectional") }}</option>
						<option value="deck_to_github">Deck → GitHub</option>
						<option value="github_to_deck">GitHub → Deck</option>
					</select>
				</label>
				<label>{{ tr('Issue repository') }}
					<input v-model.trim="form.githubRepository" list="deckghs-repositories" :placeholder="tr('owner/repository (empty = draft)')" />
				</label>
				<datalist id="deckghs-repositories"><option v-for="repo in repositories" :key="repo" :value="repo" /></datalist>
				<button class="deckghs-btn primary" :disabled="!canCreate || creating" @click="create">{{ creating ? tr('Creating …') : tr('Create') }}</button>
			</div>
			<p v-if="projectError" class="deckghs-note deckghs-note-warn">{{ projectError }}</p>
			<p v-if="repositoryError" class="deckghs-note deckghs-note-warn">{{ repositoryError }}</p>
			<div class="deckghs-row">
				<button v-if="projectMode === 'select' && status.connected" class="deckghs-btn" :disabled="projectsLoading" @click="loadProjects">{{ tr("Refresh projects") }}</button>
				<button class="deckghs-btn" @click="projectMode = projectMode === 'select' ? 'manual' : 'select'">{{ projectMode === 'select' ? tr('Enter project manually') : tr('Back to project selection') }}</button>
			</div>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { tr } from '../l10n.js'

const DIRS = { both: 'Bidirectional', deck_to_github: 'Deck → GitHub', github_to_deck: 'GitHub → Deck' }
const OAUTH_ERRORS = {
	no_oauth_app: 'GitHub OAuth is not fully configured. Check the client ID and secret in admin settings.',
	invalid_state: 'Sign-in could not be linked to your Nextcloud session. Try again and allow cookies.',
	exchange_failed: 'GitHub could not exchange the authorization code. Check the client ID, secret and callback URL.',
	incorrect_client_credentials: 'The GitHub OAuth app client ID or secret is incorrect. Contact an administrator.',
	redirect_uri_mismatch: 'The GitHub callback URL does not match the URL in admin settings.',
	bad_verification_code: 'The GitHub authorization code has expired or is invalid. Connect again.',
	unverified_user_email: 'Verify your primary GitHub email address first.',
	access_denied: 'GitHub sign-in was cancelled.',
}
const apiUrl = (path) => generateUrl('/apps/deckgithubsync' + path)

export default {
	name: 'Personal',
	data() {
		return {
			loading: true,
			mappings: [],
			dateFields: {},
			boards: [],
			projects: [],
			repositories: [],
			repositoryError: '',
			projectsLoading: false,
			projectError: '',
			projectMode: 'select',
			selectedProjectId: '',
			status: { connected: false, oauth: false },
			pat: '',
			form: { deckBoardId: 0, githubOwner: '', githubNumber: null, direction: 'both', githubRepository: '' },
			results: {},
			resultErrors: {},
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
			this.error = tr(OAUTH_ERRORS[q.get('gh_error')] || 'GitHub connection failed.')
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
					this.notice = tr('Connected to GitHub as {login}.', { login: this.status.login })
				} else {
					this.error = this.status.reason || tr('GitHub sign-in completed, but the connection could not be verified.')
				}
			}
			if (this.status.connected) {
				await Promise.all([this.loadProjects(), this.loadRepositories()])
			}
		} catch (e) {
			this.error = tr('Data could not be loaded.')
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
		tr,
		async loadDateFields(m, event) {
			if (!event.target.open || this.dateFields[m.id]) return
			try {
				const { data } = await axios.get(apiUrl(`/api/v1/mappings/${m.id}/date-fields`))
				this.dateFields[m.id] = Array.isArray(data) ? data : []
			} catch (e) {
				this.error = e.response?.data?.error || tr('Date fields could not be loaded.')
			}
		},
		async loadRepositories() {
			this.repositoryError = ''
			try {
				const { data } = await axios.get(apiUrl('/api/v1/github/repositories'))
				this.repositories = Array.isArray(data) ? data : []
			} catch (e) {
				this.repositories = []
				this.repositoryError = e.response?.data?.error || tr('Repositories could not be loaded.')
			}
		},
		async refreshConnection() {
			this.checkingConnection = true
			this.error = ''
			this.notice = ''
			try {
				const { data } = await axios.get(apiUrl('/api/v1/github/status'))
				this.status = data
				if (data.connected) {
					this.notice = tr('GitHub connection confirmed: {login}.', { login: data.login })
					await Promise.all([this.loadProjects(), this.loadRepositories()])
				} else {
					this.projects = []
					this.repositories = []
					this.error = data.reason || tr('GitHub connection could not be confirmed.')
				}
			} catch (e) {
				this.error = tr('Connection could not be checked. Try again later.')
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
					this.projectError = tr('No projects found. Check GitHub permissions or enter the project manually.')
				}
			} catch (e) {
				this.projects = []
				this.selectedProjectId = ''
				this.projectError = e.response?.data?.error || tr('Projects could not be loaded. You can enter one manually.')
			} finally {
				this.projectsLoading = false
			}
		},
		boardTitle(id) {
			return (this.boards.find((b) => b.id === id) || {}).title || ('Board ' + id)
		},
		dirLabel(d) {
			return DIRS[d] ? tr(DIRS[d]) : d
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
				this.form = { deckBoardId: 0, githubOwner: '', githubNumber: null, direction: 'both', githubRepository: '' }
				this.selectedProjectId = ''
				this.notice = tr('Mapping created.')
			} catch (e) {
				this.error = e.response?.data?.error || tr('Mapping could not be created. Check the GitHub Project.')
			} finally {
				this.creating = false
			}
		},
		async update(m) {
			try {
				const { data } = await axios.put(apiUrl(`/api/v1/mappings/${m.id}`), {
					direction: m.direction,
					fieldConfig: m.fieldConfig,
					githubRepository: m.githubRepository || '',
					dateFieldId: m.dateFieldId || '',
				})
				Object.assign(m, data)
			} catch (e) {
				this.error = e.response?.data?.error || tr('Save failed.')
				try {
					const { data } = await axios.get(apiUrl('/api/v1/mappings'))
					this.mappings = Array.isArray(data) ? data : this.mappings
				} catch (ignored) {}
			}
		},
		async saveUsers(m) {
			try {
				const { data } = await axios.put(apiUrl(`/api/v1/mappings/${m.id}/users`), {
					users: m.userMap.filter((u) => u.githubLogin && u.deckUid),
				})
				m.userMap = data.userMap
				this.notice = tr('User mapping saved.')
			} catch (e) {
				this.error = tr('User mapping could not be saved.')
			}
		},
		async remove(m) {
			if (!window.confirm(tr('Delete this mapping?'))) {
				return
			}
			this.error = ''
			try {
				await axios.delete(apiUrl(`/api/v1/mappings/${m.id}`))
				this.mappings = this.mappings.filter((x) => x.id !== m.id)
				this.notice = tr('Mapping deleted.')
			} catch (e) {
				this.error = e.response?.data?.error || tr('Mapping could not be deleted.')
			}
		},
		async sync(m) {
			this.syncing[m.id] = true
			try {
				const { data } = await axios.post(apiUrl(`/api/v1/sync/${m.id}`))
				const errs = (data.errors || []).length
				const warns = (data.warnings || []).length
				this.results[m.id] = tr('Deck→GitHub: {toGithub}, GitHub→Deck: {toDeck}', { toGithub: data.deck_to_github, toDeck: data.github_to_deck })
					+ (errs ? tr(', errors: {count}', { count: errs }) : '') + (warns ? tr(', warnings: {count}', { count: warns }) : '')
				this.resultErrors[m.id] = errs > 0
				if (errs) {
					this.error = data.errors.join('; ')
				} else {
					m.lastSync = Math.floor(Date.now() / 1000)
				}
				if (warns && !errs) {
					this.notice = data.warnings.join('; ')
				}
			} catch (e) {
				this.error = tr('Sync failed.')
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
				this.notice = tr('Connected to GitHub as {login}.', { login: data.login })
				await Promise.all([this.loadProjects(), this.loadRepositories()])
			} catch (e) {
				const status = e.response?.status
				this.error = e.response?.data?.error
					|| (status === 403 ? tr('Nextcloud rejected the request (403). Sign in again and retry.')
						: status === 404 ? tr('The token endpoint was not found (404). Update the app.')
							: status ? tr('Token could not be saved (HTTP {status}).', { status })
								: tr('Nextcloud is unavailable. Check your connection.'))
			} finally {
				this.savingPat = false
			}
		},
		async disconnect() {
			try {
				await axios.delete(apiUrl('/api/v1/github/token'))
				this.status = { connected: false, oauth: this.status.oauth }
				this.projects = []
				this.repositories = []
				this.selectedProjectId = ''
				this.projectError = ''
				this.error = ''
				this.notice = tr('GitHub disconnected.')
			} catch (e) {
				this.error = tr('GitHub could not be disconnected.')
			}
		},
	},
}
</script>

<style scoped>
.deckghs-wrap { max-width: 860px; display: flex; flex-direction: column; gap: 16px; }
.deckghs-card { padding: 4px 0 20px; }
.deckghs-card + .deckghs-card { border-top: 1px solid var(--color-border); padding-top: 20px; }
.deckghs-card h2 { margin: 0 0 12px; font-size: 1.1em; }
.deckghs-muted { color: var(--color-text-maxcontrast); font-size: 0.9em; }
.deckghs-note { border-radius: var(--border-radius); padding: 8px 12px; }
.deckghs-note-ok { background: color-mix(in srgb, var(--color-success, #46ba61) 18%, var(--color-main-background)); color: var(--color-main-text); }
.deckghs-note-warn { background: color-mix(in srgb, var(--color-warning, #e6a817) 18%, var(--color-main-background)); color: var(--color-main-text); }
.deckghs-note-err, .error { background: color-mix(in srgb, var(--color-error, #d2322d) 18%, var(--color-main-background)); color: var(--color-main-text); border-radius: var(--border-radius); padding: 8px 12px; }
.deckghs-connection-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.deckghs-connection-header p { margin: 0 0 14px; }
.deckghs-status { display: inline-flex; align-items: center; gap: 7px; padding: 5px 11px; border: 1px solid var(--color-border); border-radius: var(--border-radius-pill, 999px); font-size: 0.85em; white-space: nowrap; }
.deckghs-status.connected { color: var(--color-success-text, var(--color-main-text)); background: var(--color-success-background, transparent); }
.deckghs-status.disconnected { color: var(--color-text-maxcontrast); }
.deckghs-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; background: var(--color-warning, #e6a817); }
.deckghs-status.connected .deckghs-dot { background: var(--color-success, #46ba61); }
.deckghs-conn { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 4px 0 12px; }
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
.deckghs-btn:focus-visible, .deckghs-row input:focus-visible, .deckghs-row select:focus-visible, .deckghs-fields select:focus-visible { outline: 2px solid var(--color-main-text); outline-offset: 1px; }
.deckghs-map { border-top: 1px solid var(--color-border); padding: 12px 0; }
.deckghs-map:first-of-type { margin-top: 12px; }
.deckghs-map header { display: flex; gap: 8px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
.deckghs-map-title { min-width: 0; overflow-wrap: anywhere; }
.deckghs-meta { margin: 6px 0 0; }
.deckghs-pill { font-size: 0.8em; border: 1px solid var(--color-border); border-radius: var(--border-radius-pill, 999px); padding: 2px 10px; color: var(--color-text-maxcontrast); white-space: nowrap; }
.deckghs-spacer { flex: 1; }
.deckghs-fields { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; margin: 8px 0; }
.deckghs-fields label { display: flex; flex-direction: column; gap: 4px; font-size: 0.9em; }
.deckghs-fields select { background: var(--color-main-background); border: 1px solid var(--color-border); border-radius: var(--border-radius); padding: 6px 8px; color: var(--color-main-text); }
.deckghs-users { margin-top: 8px; }
.deckghs-map details { margin-top: 8px; }
.deckghs-map summary, .deckghs-pat summary { cursor: pointer; color: var(--color-text-maxcontrast); border-radius: var(--border-radius); padding: 2px 4px; display: inline-block; }
.deckghs-map summary:hover, .deckghs-pat summary:hover { color: var(--color-main-text); background: var(--color-background-hover); }
.deckghs-pat { margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--color-border); }
.deckghs-pat summary { cursor: pointer; color: var(--color-text-maxcontrast); }
</style>
