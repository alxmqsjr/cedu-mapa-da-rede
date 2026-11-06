<template>
	<div ref="raiz" class="cmr-filtro" :class="{ 'cmr-filtro--aberto': aberto }" @keydown.esc="fechar">
		<button
			type="button"
			class="cmr-filtro__botao"
			:aria-expanded="aberto ? 'true' : 'false'"
			:aria-controls="idLista"
			@click="aberto = !aberto"
		>
			<img :src="`${icones}icon-filter2.svg`" alt="">
			<span>Filtrar exibição</span>
			<img class="cmr-filtro__seta" :src="`${icones}icon-filter.svg`" alt="">
		</button>
		<ul :id="idLista" class="cmr-filtro__lista">
			<li v-for="(rotulo, slug) in categorias" :key="slug">
				<label>
					<input type="checkbox" :checked="modelValue.includes(slug)" @change="alternar(slug)">
					<img :src="`${marcadores}${slug}.svg`" alt="" width="24" height="24">
					<span>{{ rotulo }}</span>
				</label>
			</li>
		</ul>
	</div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, useId } from 'vue'

const props = defineProps({
	categorias: { type: Object, required: true },
	modelValue: { type: Array, required: true },
	marcadores: { type: String, required: true },
	icones: { type: String, required: true },
})
const emit = defineEmits(['update:modelValue'])

const aberto = ref(false)
const raiz = ref(null)
const idLista = `cmr-filtro-${useId()}`

function alternar(slug) {
	const selecionadas = props.modelValue.includes(slug)
		? props.modelValue.filter((s) => s !== slug)
		: [...props.modelValue, slug]
	// Mantém a ordem da legenda.
	emit('update:modelValue', Object.keys(props.categorias).filter((s) => selecionadas.includes(s)))
}

function fechar() {
	aberto.value = false
}

function cliqueFora(evento) {
	if (aberto.value && raiz.value && !raiz.value.contains(evento.target)) {
		fechar()
	}
}

onMounted(() => document.addEventListener('click', cliqueFora))
onBeforeUnmount(() => document.removeEventListener('click', cliqueFora))
</script>
