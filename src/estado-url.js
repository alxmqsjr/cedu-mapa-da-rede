/**
 * Estado dos filtros na URL: ?tipo=acoes&cat=SCHOOL,NGO&q=texto&item=organizacao-123
 * Usa history.replaceState (não cria entradas no histórico a cada tecla).
 */

/** Chave própria do objeto (o operador "in" aceitaria "constructor", "toString"...). */
const temChave = (objeto, chave) => typeof chave === 'string' && Object.prototype.hasOwnProperty.call(objeto, chave)

export function lerEstado(grupos, categorias) {
	const params = new URLSearchParams(window.location.search)

	const tipo = params.get('tipo')
	const cat = params.get('cat')
	const selecionadas = cat
		? cat.split(',').filter((slug) => temChave(categorias, slug))
		: Object.keys(categorias)

	return {
		grupo: temChave(grupos, tipo) ? tipo : 'todos',
		categorias: selecionadas.length ? selecionadas : Object.keys(categorias),
		busca: (params.get('q') || '').slice(0, 100),
		item: /^[a-z]+-\d{1,10}$/.test(params.get('item') || '') ? params.get('item') : '',
	}
}

export function gravarEstado({ grupo, categorias, busca, item }, todasCategorias) {
	const params = new URLSearchParams(window.location.search)
	const definir = (chave, valor) => (valor ? params.set(chave, valor) : params.delete(chave))

	definir('tipo', grupo !== 'todos' ? grupo : '')
	definir('cat', categorias.length !== todasCategorias.length ? categorias.join(',') : '')
	definir('q', busca.trim())
	definir('item', item)

	const consulta = params.toString()
	const url = window.location.pathname + (consulta ? `?${consulta}` : '') + window.location.hash
	if (url !== window.location.pathname + window.location.search + window.location.hash) {
		window.history.replaceState(window.history.state, '', url)
	}
}
