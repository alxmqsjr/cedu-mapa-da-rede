/**
 * Entrada do Mapa da Rede: monta o app em cada .cmr-app da página.
 * A configuração vem do PHP no atributo data-config (Frontend::render()).
 */
import { createApp } from 'vue'
import { setWorkerUrl } from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'
import './estilo.css'
import App from './App.vue'

function iniciar() {
	document.querySelectorAll('.cmr-app').forEach((el) => {
		let config
		try {
			config = JSON.parse(el.dataset.config || '{}')
		} catch (erro) {
			console.error('[Mapa da Rede] Configuração inválida.', erro)
			return
		}

		if (config.worker) {
			setWorkerUrl(config.worker)
		}

		el.textContent = ''
		createApp(App, { config }).mount(el)
	})
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', iniciar)
} else {
	iniciar()
}
