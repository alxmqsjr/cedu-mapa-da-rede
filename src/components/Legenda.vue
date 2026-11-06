<template>
	<section class="cmr-legenda" :class="{ 'cmr-legenda--recolhida': recolhida }" aria-label="Legenda do mapa">
		<h2 class="cmr-legenda__cabecalho">
			<button
				type="button"
				:aria-expanded="recolhida ? 'false' : 'true'"
				:aria-controls="idConteudo"
				@click="recolhida = !recolhida"
			>
				<span>Legenda</span>
				<span aria-hidden="true">{{ recolhida ? '+' : '−' }}</span>
			</button>
		</h2>
		<ul :id="idConteudo" class="cmr-legenda__conteudo">
			<li v-for="(rotulo, slug) in categorias" :key="slug">
				<img :src="`${marcadores}${slug}.svg`" alt="" width="24" height="24">
				<span>{{ rotulo }}</span>
			</li>
			<li class="cmr-legenda__aproximado">
				<span class="cmr-legenda__anel" aria-hidden="true"></span>
				<span>Localização aproximada (município)</span>
			</li>
		</ul>
	</section>
</template>

<script setup>
import { ref, useId } from 'vue'

defineProps({
	categorias: { type: Object, required: true },
	marcadores: { type: String, required: true },
})

// Em telas pequenas ou baixas a legenda aberta cobriria boa parte do mapa: começa recolhida.
const recolhida = ref(window.matchMedia('(max-width: 767.98px), (max-height: 599.98px)').matches)
const idConteudo = `cmr-legenda-${useId()}`
</script>
