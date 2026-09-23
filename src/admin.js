import { createApp } from 'vue'
import { loadTranslations } from '@nextcloud/l10n'
import Admin from './components/Admin.vue'

const el = document.getElementById('deckgithubsync-admin')
if (el) {
	loadTranslations('deckgithubsync').catch(() => {}).finally(() => createApp(Admin).mount(el))
}
