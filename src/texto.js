/**
 * Utilidades de texto.
 */

/** Minúsculas e sem acentos, para busca. */
export function normalizar(texto) {
	return (texto || '')
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.trim()
}

/** Rótulo do tipo exibido no card e no popup ("inscrição" aparece como "Ação da Campanha"). */
export const ROTULO_TIPO = {
	acao: 'Ação da Campanha',
	inscricao: 'Ação da Campanha',
	organizacao: 'Organização',
	atividade: 'Atividade da rede',
}
