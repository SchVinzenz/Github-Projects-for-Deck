import { createApp } from 'vue'
import Personal from './components/Personal.vue'

const el = document.getElementById('deckgithubsync-personal')
if (el) {
	createApp(Personal).mount(el)
}
