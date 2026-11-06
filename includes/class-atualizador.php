<?php
/**
 * Atualizações pelo painel do WordPress, a partir das Releases do GitHub.
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

/**
 * Integra o plugin ao sistema de atualizações do WordPress (cabeçalho "Update URI").
 *
 * - O WordPress chama o filtro `update_plugins_github.com` ao verificar atualizações.
 * - A última release de github.com/alxmqsjr/cedu-mapa-da-rede é consultada (no máximo a cada
 *   12 h; 1 h após uma falha) e, se a versão for maior, o painel mostra "Atualizar agora".
 * - Só é aceito como pacote um .zip anexado a uma release desse repositório (HTTPS).
 * - "Ver detalhes" mostra o texto da release como changelog.
 *
 * Para desligar: `define( 'CEMADEN_MAPA_REDE_ATUALIZAR', false );` no wp-config.php.
 */
final class Atualizador {

	const REPOSITORIO   = 'alxmqsjr/cedu-mapa-da-rede';
	const SLUG          = 'cedu-mapa-da-rede';
	const TRANSIENT     = 'cemaden_mapa_rede_release';
	const VALIDADE      = 12 * HOUR_IN_SECONDS;
	const VALIDADE_ERRO = HOUR_IN_SECONDS;

	/**
	 * Registra hooks.
	 */
	public static function registrar(): void {
		if ( defined( 'CEMADEN_MAPA_REDE_ATUALIZAR' ) && ! CEMADEN_MAPA_REDE_ATUALIZAR ) {
			return;
		}

		add_filter( 'update_plugins_github.com', array( self::class, 'verificar' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'detalhes' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( self::class, 'apos_atualizar' ), 10, 2 );
	}

	/**
	 * Informa ao WordPress a versão disponível no GitHub.
	 *
	 * @param array|false $atualizacao Valor recebido (false se ninguém respondeu).
	 * @param array       $dados       Cabeçalho do plugin.
	 * @param string      $arquivo     Arquivo do plugin (pasta/arquivo.php).
	 * @return array|false
	 */
	public static function verificar( $atualizacao, $dados, $arquivo ) {
		if ( plugin_basename( ARQUIVO ) !== $arquivo ) {
			return $atualizacao;
		}

		$release = self::release();
		if ( ! $release ) {
			return $atualizacao;
		}

		// O WordPress compara `version` com a instalada e só oferece se for maior.
		return array(
			'id'           => 'github.com/' . self::REPOSITORIO,
			'slug'         => self::SLUG,
			'version'      => $release['versao'],
			'url'          => $release['pagina'],
			'package'      => $release['pacote'],
			'requires_php' => '8.0',
		);
	}

	/**
	 * Janela "Ver detalhes" da atualização.
	 *
	 * @param false|object|array $resultado Resultado de outros filtros.
	 * @param string             $acao      Ação da API.
	 * @param object             $args      Argumentos (slug).
	 * @return false|object|array
	 */
	public static function detalhes( $resultado, $acao, $args ) {
		if ( 'plugin_information' !== $acao || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $resultado;
		}

		$release = self::release();
		if ( ! $release ) {
			return $resultado;
		}

		$notas = '' !== $release['notas'] ? wpautop( esc_html( $release['notas'] ) ) : '';

		return (object) array(
			'name'          => 'Cemaden Educação: Mapa da Rede',
			'slug'          => self::SLUG,
			'version'       => $release['versao'],
			'author'        => '<a href="https://github.com/alxmqsjr">Alexandre Marques</a>',
			'homepage'      => 'https://github.com/' . self::REPOSITORIO,
			'requires_php'  => '8.0',
			'requires'      => '6.6',
			'last_updated'  => $release['data'],
			'download_link' => $release['pacote'],
			'sections'      => array(
				'changelog' => $notas . '<p><a href="' . esc_url( $release['pagina'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ver a release no GitHub', 'cedu-mapa-da-rede' ) . '</a></p>',
			),
		);
	}

	/**
	 * Depois de atualizar este plugin, esquece a release guardada.
	 *
	 * @param \WP_Upgrader $upgrader Instância (não usada).
	 * @param array        $extra    Dados da operação.
	 */
	public static function apos_atualizar( $upgrader, $extra ): void {
		unset( $upgrader );
		$plugins = (array) ( $extra['plugins'] ?? array() );
		if ( 'plugin' === ( $extra['type'] ?? '' ) && in_array( plugin_basename( ARQUIVO ), $plugins, true ) ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * Última release válida (versão, pacote, página, notas, data) ou null.
	 *
	 * @return array|null
	 */
	private static function release(): ?array {
		$guardada = get_transient( self::TRANSIENT );

		// "Verificar novamente" em Painel → Atualizações também ignora o cache próprio.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só força uma leitura.
		$forcar = isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' );

		if ( is_array( $guardada ) && ! $forcar ) {
			return $guardada['release'];
		}

		$release = self::buscar();
		set_transient( self::TRANSIENT, array( 'release' => $release ), $release ? self::VALIDADE : self::VALIDADE_ERRO );

		return $release;
	}

	/**
	 * Consulta a API do GitHub e valida a resposta.
	 *
	 * @return array|null
	 */
	private static function buscar(): ?array {
		$resposta = wp_remote_get(
			'https://api.github.com/repos/' . self::REPOSITORIO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'cedu-mapa-da-rede/' . VERSAO,
				),
			)
		);

		if ( is_wp_error( $resposta ) || 200 !== wp_remote_retrieve_response_code( $resposta ) ) {
			return null;
		}

		$dados = json_decode( wp_remote_retrieve_body( $resposta ), true );
		if ( ! is_array( $dados ) || ! empty( $dados['draft'] ) || ! empty( $dados['prerelease'] ) ) {
			return null;
		}

		$versao = ltrim( (string) ( $dados['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $versao ) ) {
			return null;
		}

		// Só um .zip anexado a uma release deste repositório.
		$prefixo = 'https://github.com/' . self::REPOSITORIO . '/releases/download/';
		$pacote  = '';
		foreach ( (array) ( $dados['assets'] ?? array() ) as $anexo ) {
			$nome = (string) ( $anexo['name'] ?? '' );
			$url  = (string) ( $anexo['browser_download_url'] ?? '' );
			if ( preg_match( '/^cedu-mapa-da-rede[\w.-]*\.zip$/', $nome ) && str_starts_with( $url, $prefixo ) ) {
				$pacote = $url;
				break;
			}
		}
		if ( '' === $pacote ) {
			return null;
		}

		$pagina = (string) ( $dados['html_url'] ?? '' );
		if ( ! str_starts_with( $pagina, 'https://github.com/' . self::REPOSITORIO . '/' ) ) {
			$pagina = 'https://github.com/' . self::REPOSITORIO . '/releases';
		}

		return array(
			'versao' => $versao,
			'pacote' => $pacote,
			'pagina' => $pagina,
			'notas'  => mb_substr( (string) ( $dados['body'] ?? '' ), 0, 5000 ),
			'data'   => (string) ( $dados['published_at'] ?? '' ),
		);
	}
}
