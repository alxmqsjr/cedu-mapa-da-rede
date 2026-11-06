/**
 * "Spiderfy": abre em leque os pontos empilhados no mesmo lugar.
 *
 * Usado quando um cluster não se separa mais com zoom (ações que reaproveitam o
 * endereço da organização, inscrições na sede do mesmo município etc.).
 * Cada ponto vira um botão HTML (acessível por teclado) ligado ao centro por
 * uma "perna" desenhada numa camada de linhas. Fecha ao mudar o zoom, arrastar
 * o mapa, clicar fora ou pressionar Esc.
 */
import { Marker } from 'maplibre-gl'

const FONTE = 'cmr-aranha'
const MAX_FOLHAS = 120
const LIMITE_CIRCULO = 9

/** Posições (px) em círculo para poucos pontos e em espiral para muitos. */
function posicoes(total) {
	const pontos = []

	if (total <= LIMITE_CIRCULO) {
		const raio = Math.max(34, (32 * (2 + total)) / (2 * Math.PI))
		const passo = (2 * Math.PI) / total
		for (let i = 0; i < total; i++) {
			const angulo = i * passo - Math.PI / 2
			pontos.push([raio * Math.cos(angulo), raio * Math.sin(angulo)])
		}
		return pontos
	}

	let comprimento = 28
	let angulo = 0
	for (let i = 0; i < total; i++) {
		angulo += 34 / comprimento + i * 0.0005
		pontos.push([comprimento * Math.cos(angulo), comprimento * Math.sin(angulo)])
		comprimento += (2 * Math.PI * 5) / angulo
	}
	return pontos
}

export class Aranha {
	/**
	 * @param {import('maplibre-gl').Map} mapa
	 * @param {{ imagem: (categoria: string) => string, rotulo: (chave: string) => string, aoClicar: (chave: string, lngLat: object) => void }} opcoes
	 */
	constructor(mapa, opcoes) {
		this.mapa = mapa
		this.opcoes = opcoes
		this.marcadores = []
		this.aberta = false

		mapa.addSource(FONTE, { type: 'geojson', data: { type: 'FeatureCollection', features: [] } })
		mapa.addLayer({
			id: `${FONTE}-pernas`,
			type: 'line',
			source: FONTE,
			paint: { 'line-color': '#07346B', 'line-width': 1.25, 'line-opacity': 0.6 },
		})

		this.fechar = this.fechar.bind(this)
		this.teclado = (evento) => evento.key === 'Escape' && this.fechar()
		mapa.on('zoomstart', this.fechar)
		mapa.on('dragstart', this.fechar)
		mapa.on('click', (evento) => {
			// Clique no próprio leque é tratado pelos botões; aqui é "clicar fora".
			if (this.aberta && !evento.originalEvent?.target?.closest?.('.cmr-aranha__folha')) {
				this.fechar()
			}
		})
	}

	/**
	 * @param {[number, number]} centro lng/lat do cluster
	 * @param {Array} folhas features GeoJSON do cluster
	 */
	abrir(centro, folhas) {
		this.fechar()

		const lista = folhas.slice(0, MAX_FOLHAS)
		const origem = this.mapa.project(centro)
		const pernas = []

		posicoes(lista.length).forEach(([x, y], i) => {
			const { chave, categoria } = lista[i].properties
			const lngLat = this.mapa.unproject([origem.x + x, origem.y + y])

			const botao = document.createElement('button')
			botao.type = 'button'
			botao.className = 'cmr-aranha__folha'
			botao.setAttribute('aria-label', this.opcoes.rotulo(chave))
			botao.title = this.opcoes.rotulo(chave)
			const img = document.createElement('img')
			img.src = this.opcoes.imagem(categoria)
			img.alt = ''
			img.width = 24
			img.height = 24
			botao.appendChild(img)
			botao.addEventListener('click', (evento) => {
				evento.stopPropagation()
				this.opcoes.aoClicar(chave, lngLat)
			})

			this.marcadores.push(new Marker({ element: botao }).setLngLat(lngLat).addTo(this.mapa))
			pernas.push({
				type: 'Feature',
				geometry: { type: 'LineString', coordinates: [centro, [lngLat.lng, lngLat.lat]] },
				properties: {},
			})
		})

		this.mapa.getSource(FONTE).setData({ type: 'FeatureCollection', features: pernas })
		this.aberta = true
		document.addEventListener('keydown', this.teclado)
	}

	fechar() {
		if (!this.aberta) {
			return
		}
		this.marcadores.forEach((marcador) => marcador.remove())
		this.marcadores = []
		this.mapa.getSource(FONTE)?.setData({ type: 'FeatureCollection', features: [] })
		this.aberta = false
		document.removeEventListener('keydown', this.teclado)
	}
}
