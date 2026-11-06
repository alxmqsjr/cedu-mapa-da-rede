<template>
	<article class="cmr-cartao" :data-chave="item.chave">
		<img class="cmr-cartao__marcador" :src="urlMarcador" alt="" width="24" height="24">
		<button
			v-if="item.coordenadas"
			type="button"
			class="cmr-cartao__imagem-botao"
			:aria-label="`Localizar no mapa: ${item.titulo}`"
			title="Localizar no mapa"
			@click="$emit('localizar', item)"
		>
			<img class="cmr-cartao__imagem" :src="item.imagem" alt="" loading="lazy" decoding="async">
		</button>
		<img v-else class="cmr-cartao__imagem" :src="item.imagem" alt="" loading="lazy" decoding="async">
		<p class="cmr-cartao__meta">
			<span>{{ rotuloTipo }}</span>
			<span v-if="item.local"> · {{ item.local }}</span>
		</p>
		<h2 class="cmr-cartao__titulo">
			<a :href="item.link" target="_blank" rel="noopener" :title="`Abrir ${rotuloTipo.toLowerCase()} (nova aba)`">{{ item.titulo }}</a>
		</h2>
		<p v-if="item.instituicao && item.tipo !== 'organizacao'" class="cmr-cartao__instituicao">{{ item.instituicao }}</p>
		<p class="cmr-cartao__resumo">{{ item.resumo }}</p>
		<p v-if="!item.coordenadas" class="cmr-cartao__sem-local">Sem localização no mapa</p>
	</article>
</template>

<script setup>
import { computed } from 'vue'
import { ROTULO_TIPO } from '../texto.js'

const props = defineProps({
	item: { type: Object, required: true },
	marcadores: { type: String, required: true },
})
defineEmits(['localizar'])

const rotuloTipo = computed(() => ROTULO_TIPO[props.item.tipo] || '')
const urlMarcador = computed(() => `${props.marcadores}${props.item.categoria}.svg`)
</script>
