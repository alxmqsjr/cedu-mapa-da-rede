<?php
/**
 * Comandos WP-CLI de diagnóstico.
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

/**
 * Diagnóstico do Mapa da Rede.
 */
final class Cli {

	/**
	 * Resume os itens do mapa e lista os que ficaram sem localização.
	 *
	 * ## OPTIONS
	 *
	 * [--detalhes]
	 * : Lista cada item sem ponto no mapa, com o município/UF informado.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cemaden-mapa diagnostico
	 *     wp cemaden-mapa diagnostico --detalhes
	 *
	 * @param array $args       Argumentos.
	 * @param array $assoc_args Opções.
	 */
	public function diagnostico( $args, $assoc_args ): void {
		$colecao = Repositorio::construir();
		$resumo  = array();
		$sem     = array();

		foreach ( $colecao['features'] as $f ) {
			$p    = $f['properties'];
			$tipo = $p['tipo'];

			$resumo[ $tipo ] = $resumo[ $tipo ] ?? array(
				'tipo'       => $tipo,
				'total'      => 0,
				'exato'      => 0,
				'aproximado' => 0,
				'sem_ponto'  => 0,
			);
			++$resumo[ $tipo ]['total'];

			if ( ! $f['geometry'] ) {
				++$resumo[ $tipo ]['sem_ponto'];
				$sem[] = array(
					'tipo'   => $tipo,
					'id'     => $p['id'],
					'titulo' => mb_substr( $p['titulo'], 0, 50 ),
					'local'  => $p['local'],
				);
			} elseif ( $p['aproximado'] ) {
				++$resumo[ $tipo ]['aproximado'];
			} else {
				++$resumo[ $tipo ]['exato'];
			}
		}

		\WP_CLI\Utils\format_items( 'table', array_values( $resumo ), array( 'tipo', 'total', 'exato', 'aproximado', 'sem_ponto' ) );

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'detalhes', false ) ) {
			\WP_CLI\Utils\format_items( 'table', $sem, array( 'tipo', 'id', 'titulo', 'local' ) );
		}
	}

	/**
	 * Apaga o cache dos itens do mapa (será refeito no próximo acesso).
	 *
	 * @subcommand limpar-cache
	 */
	public function limpar_cache(): void {
		Cache::limpar();
		\WP_CLI::success( 'Cache do Mapa da Rede apagado.' );
	}

	/**
	 * Reconstrói agora o cache dos itens do mapa.
	 */
	public function reconstruir(): void {
		if ( ! Cache::reconstruir() ) {
			\WP_CLI::error( 'Outra reconstrução está em andamento. Tente de novo em instantes.' );
		}
		\WP_CLI::success( 'Cache do Mapa da Rede reconstruído.' );
	}
}
