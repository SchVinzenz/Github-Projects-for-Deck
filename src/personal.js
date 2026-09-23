import { createApp } from 'vue'
import { loadTranslations } from '@nextcloud/l10n'
import Personal from './components/Personal.vue'

const el = document.getElementById('deckgithubsync-personal')
if (el) {
	loadTranslations('deckgithubsync').catch(() => {}).finally(() => createApp(Personal).mount(el))
}
