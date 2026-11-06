<?php
/**
 * Endpoint REST com os itens do mapa.
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/cemaden-mapa-rede/v1/itens → FeatureCollection (GeoJSON).
 *
 * Público e somente leitura: expõe apenas conteúdo publicado e campos que já
 * aparecem nas páginas públicas dos posts (ver Repositorio).
 *
 * Entrega: o JSON vem pronto do Cache (não é recodificado a cada pedido),
 * com ETag (304 quando o navegador já tem a versão), gzip feito aqui mesmo
 * quando o cliente aceita (o Apache/nginx não recomprime uma resposta que já
 * tem Content-Encoding) e Cache-Control de 5 minutos.
 */
final class Rest {

	const NAMESPACE_REST = 'cemaden-mapa-rede/v1';
	const ROTA           = '/itens';

	/**
	 * Corpo a enviar em rest_pre_serve_request (definido em itens()).
	 *
	 * @var string|null
	 */
	private static $corpo = null;

	/**
	 * Registra a rota.
	 */
	public static function registrar(): void {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					self::NAMESPACE_REST,
					self::ROTA,
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( self::class, 'itens' ),
						'permission_callback' => '__return_true',
						'args'                => array(),
					)
				);
			}
		);

		add_filter( 'rest_pre_serve_request', array( self::class, 'servir' ), 10, 4 );
	}

	/**
	 * URL pública do endpoint.
	 *
	 * @return string
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE_REST . self::ROTA );
	}

	/**
	 * Monta a resposta (cabeçalhos); o corpo é enviado em servir().
	 *
	 * @param \WP_REST_Request $requisicao Requisição.
	 * @return \WP_REST_Response
	 */
	public static function itens( \WP_REST_Request $requisicao ): \WP_REST_Response {
		$entrada = Cache::obter();

		if ( null === $entrada ) {
			// Outro processo está montando o cache pela primeira vez.
			$resposta = new \WP_REST_Response(
				array(
					'code'    => 'cemaden_mapa_rede_indisponivel',
					'message' => __( 'Os dados do mapa estão sendo preparados. Tente novamente em instantes.', 'cedu-mapa-da-rede' ),
				),
				503
			);
			$resposta->header( 'Retry-After', '5' );
			$resposta->header( 'Cache-Control', 'no-store' );
			return $resposta;
		}

		$resposta = new \WP_REST_Response( null, 200 );
		$resposta->header( 'Cache-Control', 'public, max-age=300' );
		$resposta->header( 'ETag', $entrada['etag'] );
		$resposta->header( 'Vary', 'Accept-Encoding' );
		$resposta->header( 'X-Content-Type-Options', 'nosniff' );

		$se_nao_bate = (string) $requisicao->get_header( 'if_none_match' );
		if ( '' !== $se_nao_bate && in_array( $entrada['etag'], array_map( 'trim', explode( ',', $se_nao_bate ) ), true ) ) {
			$resposta->set_status( 304 );
			self::$corpo = '';
			return $resposta;
		}

		$gz = isset( $entrada['gz'] ) ? base64_decode( $entrada['gz'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false !== $gz && self::cliente_aceita_gzip( $requisicao ) ) {
			$resposta->header( 'Content-Encoding', 'gzip' );
			self::$corpo = $gz;
		} elseif ( false !== $gz ) {
			self::$corpo = (string) gzdecode( $gz );
		} else {
			self::$corpo = (string) $entrada['json'];
		}

		$resposta->header( 'Content-Length', (string) strlen( self::$corpo ) );

		return $resposta;
	}

	/**
	 * Envia o corpo pronto em vez de deixar o WordPress recodificar o array.
	 *
	 * @param bool             $servido    Já enviado por outro filtro.
	 * @param mixed            $resultado  Resposta.
	 * @param \WP_REST_Request $requisicao Requisição.
	 * @return bool
	 */
	public static function servir( $servido, $resultado, $requisicao ) {
		if ( $servido || null === self::$corpo || ! $requisicao instanceof \WP_REST_Request
			|| '/' . self::NAMESPACE_REST . self::ROTA !== $requisicao->get_route() ) {
			return $servido;
		}

		if ( 'HEAD' !== $requisicao->get_method() ) {
			echo self::$corpo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON (application/json) gerado por wp_json_encode().
		}

		self::$corpo = null;

		return true;
	}

	/**
	 * O cliente aceita gzip e o PHP não está comprimindo a saída por conta própria?
	 *
	 * @param \WP_REST_Request $requisicao Requisição.
	 * @return bool
	 */
	private static function cliente_aceita_gzip( \WP_REST_Request $requisicao ): bool {
		if ( ini_get( 'zlib.output_compression' ) ) {
			return false;
		}

		// Ex.: "gzip, deflate, br" ou "br;q=1.0, gzip;q=0.8"; "gzip;q=0" recusa.
		foreach ( explode( ',', (string) $requisicao->get_header( 'accept_encoding' ) ) as $parte ) {
			$pedacos = array_map( 'trim', explode( ';', $parte ) );
			if ( 'gzip' === strtolower( $pedacos[0] ) ) {
				return ! isset( $pedacos[1] ) || (float) preg_replace( '/^q=/i', '', $pedacos[1] ) > 0;
			}
		}

		return false;
	}
}
