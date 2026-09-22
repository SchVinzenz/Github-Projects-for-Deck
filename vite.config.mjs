import { resolve } from 'path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
	plugins: [vue()],
	build: {
		outDir: 'js',
		emptyOutDir: true,
		rollupOptions: {
			input: {
				'deckgithubsync-admin': resolve(__dirname, 'src/admin.js'),
				'deckgithubsync-personal': resolve(__dirname, 'src/personal.js'),
			},
			output: {
				entryFileNames: '[name].mjs',
				chunkFileNames: '[name]-[hash].mjs',
				assetFileNames: '[name][extname]',
			},
		},
	},
})
