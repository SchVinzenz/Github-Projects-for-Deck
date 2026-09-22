import { createApp } from 'vue'
import Admin from './components/Admin.vue'

const el = document.getElementById('deckgithubsync-admin')
if (el) {
	createApp(Admin).mount(el)
}
