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

/** Rótulo do tipo exibido no card e no popup ("inscrição" aparece como "Ação"). */
export const ROTULO_TIPO = {
	acao: 'Ação',
	inscricao: 'Ação',
	organizacao: 'Organização',
	atividade: 'Atividade da rede',
}
