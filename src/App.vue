<template>
	<div ref="elPainel" class="cmr-painel" :class="{ 'cmr-painel--expandido': expandido }">
		<div class="cmr-painel__lateral">
			<div class="cmr-painel__caixa">
				<header class="cmr-painel__cabecalho">
					<h1 class="cmr-sr">{{ config.titulo }}</h1>
					<button
						type="button"
						class="cmr-painel__alternar"
						:aria-expanded="expandido ? 'true' : 'false'"
						:aria-controls="idConteudo"
						@click="expandido = !expandido"
					>Menu do mapa</button>
					<div :id="idConteudo" class="cmr-painel__filtros" role="search">
						<label class="cmr-campo">
							<span>Buscar:</span>
							<input
								v-model="busca"
								type="search"
								placeholder="Buscar por nome ou município"
								autocomplete="off"
								maxlength="100"
							>
						</label>
						<div class="cmr-painel__linha">
							<label class="cmr-campo">
								<span>Exibindo:</span>
								<select v-model="grupo">
									<option value="todos">Todos</option>
									<option v-for="(rotulo, slug) in config.grupos" :key="slug" :value="slug">{{ rotulo }}</option>
								</select>
							</label>
							<FiltroCategorias
								v-model="categorias"
								:categorias="config.categorias"
								:marcadores="config.marcadores"
								:icones="config.icones"
							/>
						</div>
						<p class="cmr-painel__contagem" aria-live="polite">{{ textoContagem }}</p>
					</div>
				</header>

				<div ref="areaLista" class="cmr-painel__lista">
					<p v-if="erro" class="cmr-aviso" role="alert">{{ erro }}</p>
					<p v-else-if="carregando" class="cmr-aviso">Carregando…</p>
					<p v-else-if="!filtrados.length" class="cmr-aviso">Nenhum resultado para os filtros escolhidos.</p>
					<CartaoItem
						v-for="item in visiveis"
						:key="item.chave"
						:item="item"
						:marcadores="config.marcadores"
						@localizar="localizar"
					/>
					<div v-if="visiveis.length < filtrados.length" ref="sentinela" class="cmr-painel__mais" aria-hidden="true"></div>
				</div>
			</div>
		</div>

		<div class="cmr-painel__mapa">
			<div ref="elMapa" class="cmr-mapa" role="region" aria-label="Mapa interativo da rede"></div>
			<Legenda :categorias="config.categorias" :marcadores="config.marcadores" />
		</div>
	</div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, useId, watch } from 'vue'
import CartaoItem from './components/CartaoItem.vue'
import FiltroCategorias from './components/FiltroCategorias.vue'
import Legenda from './components/Legenda.vue'
import { criarMapa } from './mapa/mapa.js'
import { gravarEstado, lerEstado } from './estado-url.js'
import { normalizar } from './texto.js'

const props = defineProps({
	config: { type: Object, required: true },
})

const POR_PAGINA = 40
const todasCategorias = Object.keys(props.config.categorias)
const inicial = lerEstado(props.config.grupos, props.config.categorias)

const idConteudo = `cmr-filtros-${useId()}`
const expandido = ref(false)
const carregando = ref(true)
const erro = ref('')
const itens = shallowRef([])
const grupo = ref(inicial.grupo)
const categorias = ref(inicial.categorias)
const busca = ref(inicial.busca)
const selecionado = ref(inicial.item)
const limite = ref(POR_PAGINA)

const elPainel = ref(null)
const elMapa = ref(null)
const areaLista = ref(null)
const sentinela = ref(null)

/**
 * Altura mínima do painel. No modelo de página ele ocupa a tela abaixo do
 * cabeçalho, mesmo baixa (celular deitado); no shortcode, no meio de um
 * conteúdo, não fica menor que ALTURA_MINIMA.
 */
const ALTURA_MINIMA = 420
const ALTURA_MINIMA_PAGINA = 240
const naPagina = document.body.classList.contains('cmr-pagina')

/**
 * No modelo de página, o cabeçalho do tema é fixo e sua altura varia com a
 * largura da tela; o CSS reserva um valor aproximado (--cmr-topo). Aqui medimos
 * a altura real para o mapa começar logo abaixo dele, sem faixa vazia.
 */
function ajustarTopo() {
	const cabecalho = document.querySelector('body.cmr-pagina > .main-header')
	if (!cabecalho || getComputedStyle(cabecalho).position !== 'fixed') {
		return
	}
	// Barra de administração: o tema já desloca o <html>; o corpo começa depois dela.
	const deslocamento = parseFloat(getComputedStyle(document.documentElement).marginTop) || 0
	const topo = Math.round(cabecalho.getBoundingClientRect().bottom - deslocamento)
	if (topo > 40 && topo < 400) {
		document.body.style.setProperty('--cmr-topo', `${topo}px`)
	}
}

/**
 * Faz o painel ocupar exatamente o espaço visível abaixo do cabeçalho.
 * O CSS tem um valor fixo de reserva (100vh − altura do cabeçalho do tema), mas
 * o espaço real muda com a barra de administração do WordPress (logado) e com
 * outros elementos acima do mapa; por isso medimos a posição real do painel.
 */
function ajustarAltura() {
	const painel = elPainel.value
	if (!painel) {
		return
	}
	if (naPagina) {
		ajustarTopo()
	}
	const topo = painel.getBoundingClientRect().top + window.scrollY
	const minima = naPagina ? ALTURA_MINIMA_PAGINA : ALTURA_MINIMA
	const altura = Math.max(minima, Math.round(window.innerHeight - topo))
	painel.style.setProperty('--cmr-altura', `${altura}px`)
	mapa?.mapa.resize()
}

let temporizadorAltura = 0
function agendarAjuste() {
	clearTimeout(temporizadorAltura)
	temporizadorAltura = setTimeout(ajustarAltura, 100)
}

let mapa = null
let observador = null
let porChave = new Map()

const buscaNormalizada = computed(() => normalizar(busca.value))

const filtrados = computed(() => {
	const termo = buscaNormalizada.value
	const cats = new Set(categorias.value)

	return itens.value.filter(
		(item) =>
			(grupo.value === 'todos' || item.grupo === grupo.value) &&
			cats.has(item.categoria) &&
			(!termo || item.busca.includes(termo)),
	)
})

const visiveis = computed(() => filtrados.value.slice(0, limite.value))

const textoContagem = computed(() => {
	if (carregando.value || erro.value) {
		return ''
	}
	const total = filtrados.value.length
	const noMapa = filtrados.value.filter((item) => item.coordenadas).length
	const plural = (n, um, varios) => `${n.toLocaleString('pt-BR')} ${n === 1 ? um : varios}`
	return `${plural(total, 'resultado', 'resultados')}, ${plural(noMapa, 'no mapa', 'no mapa')}`
})

const colecaoMapa = computed(() => ({
	type: 'FeatureCollection',
	features: filtrados.value
		.filter((item) => item.coordenadas)
		.map((item) => ({
			type: 'Feature',
			geometry: { type: 'Point', coordinates: item.coordenadas },
			properties: { chave: item.chave, categoria: item.categoria, aproximado: item.aproximado },
		})),
}))

/** Seleciona um item vindo do mapa: destaca e rola a lista até ele. */
async function selecionarNoMapa(chave) {
	selecionado.value = chave
	const indice = filtrados.value.findIndex((item) => item.chave === chave)
	if (indice < 0) {
		return
	}
	if (indice >= limite.value) {
		limite.value = indice + POR_PAGINA
	}
	await nextTick()
	const cartao = areaLista.value?.querySelector(`[data-chave="${CSS.escape(chave)}"]`)
	if (cartao && areaLista.value) {
		areaLista.value.scrollTop = cartao.offsetTop - areaLista.value.offsetTop
	}
}

/** Card → mapa. */
function localizar(item) {
	selecionado.value = item.chave
	mapa?.mostrarItem(item)
	if (window.innerWidth < 992) {
		expandido.value = false
	}
}

function observarSentinela() {
	observador?.disconnect()
	if (sentinela.value) {
		observador.observe(sentinela.value)
	}
}

/**
 * Busca os itens. Se o servidor responder 503 (dados sendo preparados, logo após
 * uma publicação ou atualização), tenta de novo após o tempo pedido em Retry-After.
 */
async function buscarItens(tentativas = 4) {
	for (let tentativa = 1; ; tentativa++) {
		const resposta = await fetch(props.config.endpoint, {
			headers: { Accept: 'application/json' },
			credentials: 'omit',
		})
		if (resposta.ok) {
			return resposta.json()
		}
		if (resposta.status !== 503 || tentativa >= tentativas) {
			throw new Error(`HTTP ${resposta.status}`)
		}
		const espera = Math.min(Number(resposta.headers.get('Retry-After')) || 5, 30)
		await new Promise((ok) => setTimeout(ok, espera * 1000))
	}
}

async function carregarItens() {
	try {
		const dados = await buscarItens()
		if (!dados || !Array.isArray(dados.features)) {
			throw new Error('Resposta inesperada')
		}

		const lista = dados.features.map((f) => {
			const p = f.properties
			return Object.freeze({
				...p,
				chave: f.id,
				coordenadas: f.geometry ? f.geometry.coordinates : null,
				busca: normalizar(`${p.titulo} ${p.instituicao} ${p.local}`),
			})
		})
		const ordem = new Intl.Collator('pt-BR', { sensitivity: 'base', numeric: true })
		lista.sort((a, b) => ordem.compare(a.titulo, b.titulo))

		porChave = new Map(lista.map((item) => [item.chave, item]))
		itens.value = lista
	} catch (e) {
		console.error('[Mapa da Rede] Falha ao carregar os itens.', e)
		erro.value = 'Não foi possível carregar os dados do mapa. Tente recarregar a página.'
	} finally {
		carregando.value = false
	}
}

watch([grupo, categorias, buscaNormalizada], () => {
	limite.value = POR_PAGINA
	if (areaLista.value) {
		areaLista.value.scrollTop = 0
	}
})

watch(colecaoMapa, (colecao) => mapa?.atualizar(colecao))

watch(visiveis, () => nextTick(observarSentinela))

let temporizadorUrl = null
watch([grupo, categorias, busca, selecionado], () => {
	clearTimeout(temporizadorUrl)
	temporizadorUrl = setTimeout(() => {
		gravarEstado(
			{ grupo: grupo.value, categorias: categorias.value, busca: busca.value, item: selecionado.value },
			todasCategorias,
		)
	}, 300)
})

onMounted(async () => {
	observador = new IntersectionObserver(
		(entradas) => {
			if (entradas.some((e) => e.isIntersecting)) {
				limite.value += POR_PAGINA
			}
		},
		{ root: areaLista.value, rootMargin: '400px' },
	)

	ajustarAltura()
	window.addEventListener('resize', agendarAjuste)
	// O cabeçalho do tema pode terminar de se ajustar depois (fontes, logo).
	window.addEventListener('load', agendarAjuste, { once: true })

	mapa = criarMapa({
		container: elMapa.value,
		config: props.config,
		buscarItem: (chave) => porChave.get(chave),
		aoSelecionar: selecionarNoMapa,
	})

	await carregarItens()
	mapa.atualizar(colecaoMapa.value)

	// Item vindo da URL (?item=...): abre depois que o mapa estiver pronto.
	const doLink = porChave.get(inicial.item)
	if (doLink) {
		await mapa.pronto
		selecionarNoMapa(doLink.chave)
		mapa.mostrarItem(doLink)
	} else {
		selecionado.value = ''
	}
})

onBeforeUnmount(() => {
	window.removeEventListener('resize', agendarAjuste)
	window.removeEventListener('load', agendarAjuste)
	clearTimeout(temporizadorAltura)
	observador?.disconnect()
	mapa?.destruir()
})
</script>
