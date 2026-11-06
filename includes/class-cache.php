<?php
/**
 * Cache da coleção do mapa: JSON pronto (comprimido), reconstrução em segundo
 * plano e proteção contra reconstruções simultâneas.
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda a resposta do endpoint pronta para servir.
 *
 * - Option sem autoload (não pesa nas demais páginas), com o JSON comprimido
 *   (gzip, em base64) e o ETag.
 * - Alterar conteúdo não apaga o cache: marca como "sujo" e agenda a
 *   reconstrução no WP-Cron. Os visitantes recebem a versão anterior até a nova
 *   ficar pronta; se o cron estiver desligado, a primeira requisição depois da
 *   alteração reconstrói.
 * - Uma trava impede duas reconstruções ao mesmo tempo (cada uma lê ~2 mil posts).
 */
final class Cache {

	const OPCAO        = 'cemaden_mapa_rede_cache';
	const OPCAO_SUJO   = 'cemaden_mapa_rede_sujo';
	const OPCAO_TRAVA  = 'cemaden_mapa_rede_trava';
	const EVENTO       = 'cemaden_mapa_rede_reconstruir';
	const VERSAO_DADOS = 2;

	/**
	 * Segundos até a trava ser considerada abandonada (processo morto no meio).
	 */
	const VALIDADE_TRAVA = 120;

	/**
	 * Segundos que a versão anterior continua sendo servida enquanto o cron não reconstrói.
	 */
	const ESPERA_CRON = 60;

	/**
	 * Liga invalidação e reconstrução agendada.
	 */
	public static function registrar(): void {
		add_action( self::EVENTO, array( self::class, 'reconstruir' ) );

		$por_post = static function ( $post_id ) {
			if ( in_array( get_post_type( $post_id ), Config::post_types(), true ) ) {
				self::marcar_sujo();
			}
		};

		add_action( 'save_post', $por_post );
		add_action( 'deleted_post', $por_post );
		add_action( 'trashed_post', $por_post );
		add_action( 'untrashed_post', $por_post );

		$observadas = Repositorio::metas_observadas();
		$por_meta   = static function ( $meta_id, $post_id, $meta_key ) use ( $por_post, $observadas ) {
			if ( in_array( $meta_key, $observadas, true ) ) {
				$por_post( $post_id );
			}
		};

		add_action( 'added_post_meta', $por_meta, 10, 3 );
		add_action( 'updated_post_meta', $por_meta, 10, 3 );
		add_action( 'deleted_post_meta', $por_meta, 10, 3 );
	}

	/**
	 * Entrada pronta para servir, reconstruindo se necessário.
	 *
	 * @return array{gz:?string,json:?string,etag:string}|null null = indisponível no momento (outra reconstrução em andamento).
	 */
	public static function obter(): ?array {
		$entrada = self::ler();

		if ( null === $entrada ) {
			// Primeira vez (ou cache apagado): só um processo constrói; os demais aguardam.
			return self::reconstruir() ? self::ler() : null;
		}

		$sujo = (int) get_option( self::OPCAO_SUJO );
		if ( $sujo ) {
			if ( time() - $sujo > self::ESPERA_CRON ) {
				// O cron não rodou a tempo (pode estar desligado): reconstrói agora.
				// Se outro processo já está reconstruindo, segue com a versão atual.
				self::reconstruir();
				$entrada = self::ler() ?? $entrada;
			} elseif ( ! wp_next_scheduled( self::EVENTO ) ) {
				wp_schedule_single_event( time() + 15, self::EVENTO );
			}
		}

		return $entrada;
	}

	/**
	 * Reconstrói o cache (usado pelo cron, pelo endpoint e pelo WP-CLI).
	 *
	 * @return bool true se reconstruiu; false se outra reconstrução está em andamento.
	 */
	public static function reconstruir(): bool {
		if ( ! self::travar() ) {
			return false;
		}

		$inicio = time();

		try {
			$json = wp_json_encode( Repositorio::construir(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( false === $json ) {
				return false;
			}

			$gz = function_exists( 'gzencode' ) ? gzencode( $json, 6 ) : false;

			update_option(
				self::OPCAO,
				array(
					'versao' => self::VERSAO_DADOS,
					'etag'   => '"' . md5( $json ) . '"',
					// Base64: bytes binários podem ser corrompidos numa coluna de texto.
					'gz'     => false !== $gz ? base64_encode( $gz ) : null, // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'json'   => false !== $gz ? null : $json,
				),
				false
			);

			// Só limpa o "sujo" se nada mudou durante a reconstrução.
			if ( (int) get_option( self::OPCAO_SUJO ) <= $inicio ) {
				delete_option( self::OPCAO_SUJO );
			}
		} finally {
			self::destravar();
		}

		return true;
	}

	/**
	 * Marca o cache como desatualizado e agenda a reconstrução.
	 */
	public static function marcar_sujo(): void {
		update_option( self::OPCAO_SUJO, time(), false );

		if ( ! wp_next_scheduled( self::EVENTO ) ) {
			wp_schedule_single_event( time() + 15, self::EVENTO );
		}
	}

	/**
	 * Apaga tudo o que o cache guardou (desativação/WP-CLI).
	 */
	public static function limpar(): void {
		delete_option( self::OPCAO );
		delete_option( self::OPCAO_SUJO );
		self::destravar();
		wp_clear_scheduled_hook( self::EVENTO );
	}

	/**
	 * Lê a entrada do cache, validando o formato.
	 *
	 * @return array|null
	 */
	private static function ler(): ?array {
		$entrada = get_option( self::OPCAO );

		if ( ! is_array( $entrada ) || self::VERSAO_DADOS !== ( $entrada['versao'] ?? 0 ) || empty( $entrada['etag'] )
			|| ( empty( $entrada['gz'] ) && empty( $entrada['json'] ) ) ) {
			return null;
		}

		return $entrada;
	}

	/**
	 * Adquire a trava de forma atômica.
	 *
	 * A função add_option() não serve: usa INSERT ... ON DUPLICATE KEY UPDATE e "vence"
	 * em dois processos ao mesmo tempo. INSERT IGNORE na chave única
	 * option_name só insere em um deles. Consultas diretas para não depender
	 * do cache de objetos.
	 *
	 * @return bool
	 */
	private static function travar(): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$inserir = static function () use ( $wpdb ) {
			return 1 === (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
					self::OPCAO_TRAVA,
					(string) time()
				)
			);
		};

		if ( $inserir() ) {
			return true;
		}

		// Trava abandonada por um processo que morreu: libera e tenta de novo.
		$desde = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPCAO_TRAVA )
		);
		if ( time() - $desde > self::VALIDADE_TRAVA ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					self::OPCAO_TRAVA,
					(string) $desde
				)
			);
			return $inserir();
		}
		// phpcs:enable

		return false;
	}

	/**
	 * Libera a trava.
	 */
	private static function destravar(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::OPCAO_TRAVA ) );
		wp_cache_delete( self::OPCAO_TRAVA, 'options' );
	}
}
