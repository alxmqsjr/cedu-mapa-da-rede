import { copyFileSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const AVISO = '/*! Cemaden Educação: Mapa da Rede | inclui Vue (MIT) e MapLibre GL JS (BSD-3-Clause); licenças em LICENSES.txt */'

/**
 * Depois do build:
 * - copia o worker do MapLibre GL JS 6 para build/ com extensão .js (servidores
 *   sem MIME para .mjs entregariam application/octet-stream e o worker não
 *   carregaria). A URL é passada ao app pelo PHP (data-config.worker);
 * - grava build/LICENSES.txt com as licenças das bibliotecas empacotadas.
 */
const posBuild = () => ({
	name: 'cemaden-pos-build',
	closeBundle() {
		copyFileSync(
			resolve('node_modules/maplibre-gl/dist/maplibre-gl-worker.mjs'),
			resolve('build/maplibre-worker.js'),
		)

		const pacotes = [
			['maplibre-gl', 'LICENSE.txt'],
			['vue', 'LICENSE'],
		]
		const texto = pacotes
			.map(([nome, arquivo]) => {
				const { version } = JSON.parse(readFileSync(resolve(`node_modules/${nome}/package.json`), 'utf8'))
				return `===== ${nome} ${version} =====\n\n${readFileSync(resolve(`node_modules/${nome}/${arquivo}`), 'utf8')}`
			})
			.join('\n\n')
		writeFileSync(resolve('build/LICENSES.txt'), texto)
	},
})

// Gera build/mapa-rede.js (IIFE sem variáveis globais), build/mapa-rede.css, o worker e LICENSES.txt.
export default defineConfig(({ mode }) => ({
	plugins: [vue(), posBuild()],
	base: './',
	define: {
		'process.env.NODE_ENV': JSON.stringify(mode === 'development' ? 'development' : 'production'),
		__VUE_OPTIONS_API__: 'false',
		__VUE_PROD_DEVTOOLS__: 'false',
		__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: 'false',
	},
	build: {
		outDir: 'build',
		emptyOutDir: true,
		sourcemap: false,
		target: 'es2020',
		cssCodeSplit: false,
		modulePreload: false,
		rollupOptions: {
			input: 'src/main.js',
			output: {
				format: 'iife',
				entryFileNames: 'mapa-rede.js',
				assetFileNames: 'mapa-rede[extname]',
				postBanner: AVISO,
			},
		},
	},
}))
