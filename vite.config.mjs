import { resolve } from 'path'
import { mkdir, readdir, rename } from 'node:fs/promises'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
	plugins: [vue(), {
		name: 'nextcloud-css-directory',
		async closeBundle() {
			const jsDir = resolve(__dirname, 'js')
			const cssDir = resolve(__dirname, 'css')
			await mkdir(cssDir, { recursive: true })
			for (const file of await readdir(jsDir)) {
				if (file.endsWith('.css')) {
					await rename(resolve(jsDir, file), resolve(cssDir, file))
				}
			}
		},
	}],
	build: {
		outDir: 'js',
		emptyOutDir: true,
		rollupOptions: {
			input: {
				'deckgithubsync-admin': resolve(__dirname, 'src/admin.js'),
				'deckgithubsync-personal': resolve(__dirname, 'src/personal.js'),
			},
			output: {
				entryFileNames: '[name]-v2.mjs',
				chunkFileNames: '[name]-[hash].mjs',
				assetFileNames: '[name]-v2[extname]',
			},
		},
	},
})
