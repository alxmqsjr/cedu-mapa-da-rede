/**
 * Mapa (MapLibre GL JS): mapa base, pontos, clusters, leque e popup.
 * Fica fora da reatividade do Vue; o App conversa com ele por esta API.
 */
import { Map as MapaGL, NavigationControl, Popup } from 'maplibre-gl'
import { Aranha } from './aranha.js'
import { ROTULO_TIPO } from '../texto.js'

const FONTE = 'cmr-itens'
const CAMADA_PINOS = 'cmr-pinos'
const CAMADA_APROXIMADOS = 'cmr-aproximados'
const CAMADA_CLUSTERS = 'cmr-clusters'
const CAMADA_CONTAGEM = 'cmr-contagem'

/**
 * Clique no cluster: aproxima até ele se separar; abre em leque quando ele só
 * se separaria acima de ZOOM_MAXIMO_EXPANSAO (pontos no mesmo endereço) ou
 * quando o mapa já está no nível de bairro (ZOOM_LEQUE), para não exigir
 * vários cliques em pontos quase coincidentes.
 */
const ZOOM_MAXIMO_EXPANSAO = 15
const ZOOM_LEQUE = 12

/** Rótulos do mapa base: português, depois a grafia latina e o nome local (OpenMapTiles). */
const ROTULO_PT = ['coalesce', ['get', 'name:pt'], ['get', 'name:latin'], ['get', 'name']]

const LOCALE = {
	'NavigationControl.ZoomIn': 'Aproximar',
	'NavigationControl.ZoomOut': 'Afastar',
	'AttributionControl.ToggleAttribution': 'Mostrar/ocultar créditos',
	'AttributionControl.MapFeedback': 'Sugerir correção no mapa',
	'Popup.Close': 'Fechar',
	'Map.Title': 'Mapa da Rede',
	'CooperativeGesturesHandler.WindowsHelpText': 'Use Ctrl + rolagem para dar zoom',
	'CooperativeGesturesHandler.MacHelpText': 'Use ⌘ + rolagem para dar zoom',
	'CooperativeGesturesHandler.MobileHelpText': 'Use dois dedos para mover o mapa',
}

/** Carrega um SVG como imagem do mapa (2x para telas de alta densidade). */
function carregarImagem(mapa, id, url) {
	return new Promise((resolve) => {
		const img = new Image(48, 48)
		img.onload = () => {
			if (!mapa.hasImage(id)) {
				mapa.addImage(id, img, { pixelRatio: 2 })
			}
			resolve()
		}
		img.onerror = () => resolve()
		img.src = url
	})
}

/**
 * @param {object} opcoes
 * @param {HTMLElement} opcoes.container
 * @param {object} opcoes.config        data-config do PHP
 * @param {(chave: string) => object|undefined} opcoes.buscarItem
 * @param {(chave: string) => void} opcoes.aoSelecionar  clique num ponto
 */
export function criarMapa({ container, config, buscarItem, aoSelecionar }) {
	const urlMarcador = (categoria) => `${config.marcadores}${categoria}.svg`
	const pequeno = window.innerWidth < 600

	const mapa = new MapaGL({
		container,
		style: config.estilo,
		center: config.centro,
		zoom: pequeno ? config.zoom.mobile : config.zoom.desktop,
		maxZoom: 18,
		attributionControl: { compact: true },
		locale: LOCALE,
	})
	mapa.addControl(new NavigationControl({ showCompass: false }), 'top-left')

	/*
	 * Telas estreitas: os créditos abertos ocupam duas linhas sobre a legenda.
	 * Começam recolhidos no botão "i" (o MapLibre já os recolhe no primeiro arraste).
	 */
	if (window.innerWidth < 600) {
		mapa.once('load', () => {
			container.querySelector('.maplibregl-ctrl-attrib.maplibregl-compact-show')?.classList.remove('maplibregl-compact-show')
		})
	}

	/*
	 * Mapa base de reserva: se o estilo principal não carregar (serviço fora do
	 * ar ou sem resposta em 15 s), troca para o estilo de reserva (OSM) em vez
	 * de deixar o mapa em branco. O sinal de sucesso é o estilo carregado
	 * ("style.load"), e não o "load" do mapa, que espera todos os tiles e pode
	 * demorar em redes lentas. Erros de tiles isolados não disparam a troca.
	 */
	let estiloOk = false
	let fonteTexto = config.fonte
	const usarReserva = () => {
		if (estiloOk || !config.reserva || fonteTexto === config.reserva.fonte) {
			return
		}
		console.warn('[Mapa da Rede] Mapa base indisponível; usando o mapa de reserva.')
		fonteTexto = config.reserva.fonte
		mapa.setStyle(config.reserva.estilo, { diff: false })
	}
	const urlEstilo = typeof config.estilo === 'string' ? config.estilo : ''
	mapa.on('error', (evento) => {
		const url = evento?.error?.url || ''
		if (!estiloOk && urlEstilo && url.startsWith(urlEstilo)) {
			usarReserva()
		}
	})
	const limiteCarga = setTimeout(usarReserva, 15000)
	mapa.once('style.load', () => {
		estiloOk = true
		clearTimeout(limiteCarga)
	})

	/*
	 * Ajustes no mapa base vetorial (sem efeito no raster de reserva):
	 * - rótulos em português ("Brasil", "Bolívia"), com o nome local como reserva;
	 * - cor da água (config.corAgua).
	 */
	mapa.on('style.load', () => {
		for (const camada of mapa.getStyle()?.layers || []) {
			if (camada.type !== 'symbol') {
				continue
			}
			const campo = mapa.getLayoutProperty(camada.id, 'text-field')
			if (campo && JSON.stringify(campo).includes('"name')) {
				mapa.setLayoutProperty(camada.id, 'text-field', ROTULO_PT)
			}
		}

		if (!config.corAgua) {
			return
		}
		if (mapa.getLayer('water')?.type === 'fill') {
			mapa.setPaintProperty('water', 'fill-color', config.corAgua)
		}
		if (mapa.getLayer('waterway')?.type === 'line') {
			mapa.setPaintProperty('waterway', 'line-color', config.corAgua)
		}
	})

	let popup = null
	let aranha = null
	let dadosPendentes = null

	const fecharPopup = () => {
		popup?.remove()
		popup = null
	}

	const conteudoPopup = (item) => {
		const raiz = document.createElement('div')
		raiz.className = 'cmr-popup'

		const link = document.createElement('a')
		link.href = item.link
		link.target = '_blank'
		link.rel = 'noopener'
		link.className = 'cmr-popup__titulo'
		link.textContent = item.titulo
		raiz.appendChild(link)

		const meta = document.createElement('p')
		meta.className = 'cmr-popup__meta'
		meta.textContent = [ROTULO_TIPO[item.tipo], item.local].filter(Boolean).join(' · ')
		raiz.appendChild(meta)

		if (item.aproximado) {
			const aviso = document.createElement('p')
			aviso.className = 'cmr-popup__aviso'
			aviso.textContent = 'Localização aproximada (sede do município)'
			raiz.appendChild(aviso)
		}

		return raiz
	}

	const pronto = new Promise((resolve) => {
		mapa.on('load', async () => {
			await Promise.all(
				Object.keys(config.categorias).map((slug) => carregarImagem(mapa, `cmr-${slug}`, urlMarcador(slug))),
			)

			mapa.addSource(FONTE, {
				type: 'geojson',
				data: dadosPendentes || { type: 'FeatureCollection', features: [] },
				cluster: true,
				clusterRadius: 30,
				clusterMaxZoom: 17,
				maxzoom: 18,
			})

			mapa.addLayer({
				id: CAMADA_APROXIMADOS,
				type: 'circle',
				source: FONTE,
				filter: ['all', ['!', ['has', 'point_count']], ['==', ['get', 'aproximado'], true]],
				paint: {
					'circle-radius': 15,
					'circle-color': 'rgba(255,255,255,0.35)',
					'circle-stroke-color': '#07346B',
					'circle-stroke-width': 1.5,
					'circle-stroke-opacity': 0.55,
				},
			})

			mapa.addLayer({
				id: CAMADA_PINOS,
				type: 'symbol',
				source: FONTE,
				filter: ['!', ['has', 'point_count']],
				layout: {
					'icon-image': ['concat', 'cmr-', ['get', 'categoria']],
					'icon-allow-overlap': true,
				},
			})

			mapa.addLayer({
				id: CAMADA_CLUSTERS,
				type: 'circle',
				source: FONTE,
				filter: ['has', 'point_count'],
				paint: {
					'circle-color': '#FFFFFF',
					'circle-radius': ['step', ['get', 'point_count'], 14, 50, 17, 200, 20],
					'circle-stroke-color': '#07346B',
					'circle-stroke-width': 1.5,
				},
			})

			mapa.addLayer({
				id: CAMADA_CONTAGEM,
				type: 'symbol',
				source: FONTE,
				filter: ['has', 'point_count'],
				layout: {
					'text-field': ['get', 'point_count_abbreviated'],
					'text-font': fonteTexto,
					'text-size': 12,
					'text-allow-overlap': true,
				},
				paint: { 'text-color': '#07346B' },
			})

			aranha = new Aranha(mapa, {
				imagem: urlMarcador,
				rotulo: (chave) => buscarItem(chave)?.titulo || '',
				aoClicar: (chave, lngLat) => {
					const item = buscarItem(chave)
					if (item) {
						abrirPopup(item, [lngLat.lng, lngLat.lat])
						aoSelecionar(chave)
					}
				},
			})

			mapa.on('click', CAMADA_PINOS, (evento) => {
				const chave = evento.features[0]?.properties.chave
				const item = chave && buscarItem(chave)
				if (item) {
					abrirPopup(item, evento.features[0].geometry.coordinates)
					aoSelecionar(chave)
				}
			})

			mapa.on('click', CAMADA_CLUSTERS, async (evento) => {
				const cluster = evento.features[0]
				const fonte = mapa.getSource(FONTE)
				const centro = cluster.geometry.coordinates
				const zoom = await fonte.getClusterExpansionZoom(cluster.properties.cluster_id)

				if (zoom <= ZOOM_MAXIMO_EXPANSAO && mapa.getZoom() < ZOOM_LEQUE) {
					mapa.easeTo({ center: centro, zoom: Math.max(zoom, mapa.getZoom() + 1) })
				} else {
					fecharPopup()
					const folhas = await fonte.getClusterLeaves(cluster.properties.cluster_id, Infinity, 0)
					aranha.abrir(centro, folhas)
				}
			})

			for (const camada of [CAMADA_PINOS, CAMADA_CLUSTERS]) {
				mapa.on('mouseenter', camada, () => {
					mapa.getCanvas().style.cursor = 'pointer'
				})
				mapa.on('mouseleave', camada, () => {
					mapa.getCanvas().style.cursor = ''
				})
			}

			resolve()
		})
	})

	function abrirPopup(item, coordenadas) {
		fecharPopup()
		popup = new Popup({ offset: 14, maxWidth: '300px', focusAfterOpen: false })
			.setLngLat(coordenadas)
			.setDOMContent(conteudoPopup(item))
			.addTo(mapa)
	}

	return {
		pronto,
		mapa,

		/** Substitui os pontos exibidos. */
		atualizar(colecao) {
			aranha?.fechar()
			fecharPopup()
			const fonte = mapa.getSource(FONTE)
			if (fonte) {
				fonte.setData(colecao)
			} else {
				dadosPendentes = colecao
			}
		},

		/** Voa até o item e abre o popup. */
		mostrarItem(item) {
			if (!item.coordenadas) {
				return
			}
			aranha?.fechar()
			// Celular/tablet: legenda e gaveta ocupam a parte de baixo; o ponto vai para o terço superior.
			const altura = mapa.getContainer().clientHeight
			const deslocamento = window.innerWidth < 992 ? Math.round(altura * 0.2) : 0
			mapa.flyTo({
				center: item.coordenadas,
				zoom: Math.max(mapa.getZoom(), item.aproximado ? 10 : 12),
				offset: [0, -deslocamento],
			})
			mapa.once('moveend', () => abrirPopup(item, item.coordenadas))
		},

		fecharPopup,

		destruir() {
			clearTimeout(limiteCarga)
			aranha?.fechar()
			mapa.remove()
		},
	}
}
