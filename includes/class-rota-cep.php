<?php
/**
 * Rota de CEP do cadastro, sem depender do JEO.
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

/**
 * Substitui GET /wp-json/cemaden/v1/endereco/{cep} (registrada pelo tema em
 * library/registration/api.php) somente quando o JEO não está carregado.
 *
 * Com o JEO ativo, a rota do tema continua valendo e nada muda. Sem o JEO, a
 * rota do tema daria erro fatal (\Jeo\Geocode_Handler inexistente); esta
 * versão responde no mesmo formato: bairro, cep, cidade, estado, rua, lat, lon.
 *
 * Proteções (a rota é pública e dispara chamadas a serviços externos):
 * - CEP validado (8 dígitos) e resposta do ViaCEP em cache por 30 dias
 *   (CEP inexistente: 1 dia);
 * - limite de consultas externas por IP (LIMITE_POR_IP a cada JANELA_LIMITE s);
 *   CEPs já em cache não contam;
 * - geocodificação com cache e sem espera bloqueante (ver Geocodificador).
 */
final class Rota_Cep {

	const LIMITE_POR_IP = 30;
	const JANELA_LIMITE = 600;

	/**
	 * Registra a substituição depois do tema (prioridade 99).
	 */
	public static function registrar(): void {
		add_action(
			'rest_api_init',
			static function () {
				if ( class_exists( '\Jeo\Geocode_Handler' ) ) {
					return;
				}

				register_rest_route(
					'cemaden/v1',
					'/endereco/(?P<cep>[0-9]{8})',
					array(
						'methods'             => \WP_REST_Server::READABLE,
						'callback'            => array( self::class, 'responder' ),
						'permission_callback' => '__return_true',
						'args'                => array(
							'cep' => array(
								'type'    => 'string',
								'pattern' => '^[0-9]{8}$',
							),
						),
					),
					true
				);
			},
			99
		);
	}

	/**
	 * ViaCEP → endereço; geocodificação própria → lat/lon.
	 *
	 * @param \WP_REST_Request $requisicao Requisição.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function responder( \WP_REST_Request $requisicao ) {
		$cep = substr( preg_replace( '/\D/', '', (string) $requisicao['cep'] ), 0, 8 );

		// Mesmos códigos e mensagens da rota original do tema (o JS do cadastro depende de 'api_error').
		$endereco = self::viacep( $cep );
		if ( is_wp_error( $endereco ) ) {
			return $endereco;
		}

		$ponto = Geocodificador::geocodificar( $endereco['rua'], $endereco['cidade'], $endereco['estado'] );

		$resposta = rest_ensure_response(
			$endereco + array(
				'lat' => $ponto ? $ponto['lat'] : null,
				'lon' => $ponto ? $ponto['lon'] : null,
			)
		);
		$resposta->header( 'Cache-Control', 'private, max-age=3600' );

		return $resposta;
	}

	/**
	 * Endereço do CEP via ViaCEP, com cache e limite por IP.
	 *
	 * @param string $cep CEP com 8 dígitos.
	 * @return array|\WP_Error bairro, cep, cidade, estado, rua.
	 */
	private static function viacep( string $cep ) {
		$chave = 'cemaden_mapa_rede_cep_' . $cep;
		$cache = get_transient( $chave );

		if ( is_array( $cache ) ) {
			return empty( $cache['erro'] )
				? $cache
				: new \WP_Error( 'api_error', __( 'CEP não encontrado.', 'cedu-mapa-da-rede' ), array( 'status' => 404 ) );
		}

		if ( ! self::dentro_do_limite() ) {
			return new \WP_Error(
				'cemaden_mapa_rede_limite',
				__( 'Muitas consultas de CEP. Aguarde alguns minutos e tente novamente.', 'cedu-mapa-da-rede' ),
				array( 'status' => 429 )
			);
		}

		$resposta = wp_safe_remote_get(
			'https://viacep.com.br/ws/' . $cep . '/json/',
			array(
				'timeout'             => 6,
				'redirection'         => 0,
				'limit_response_size' => 4096,
			)
		);

		if ( is_wp_error( $resposta ) || 200 !== wp_remote_retrieve_response_code( $resposta ) ) {
			// Falha temporária do serviço: não guarda em cache.
			return new \WP_Error( 'api_error', __( 'Não foi possível consultar o CEP agora.', 'cedu-mapa-da-rede' ), array( 'status' => 502 ) );
		}

		$viacep = json_decode( wp_remote_retrieve_body( $resposta ), true, 4 );

		if ( ! is_array( $viacep ) || ! empty( $viacep['erro'] ) ) {
			set_transient( $chave, array( 'erro' => true ), DAY_IN_SECONDS );
			return new \WP_Error( 'api_error', __( 'CEP não encontrado.', 'cedu-mapa-da-rede' ), array( 'status' => 404 ) );
		}

		foreach ( array( 'bairro', 'cep', 'localidade', 'uf', 'logradouro' ) as $campo ) {
			if ( ! isset( $viacep[ $campo ] ) || ! is_string( $viacep[ $campo ] ) ) {
				return new \WP_Error( 'api_error', __( 'A resposta da API ViaCep é inválida.', 'cedu-mapa-da-rede' ), array( 'status' => 502 ) );
			}
		}

		$endereco = array(
			'bairro' => sanitize_text_field( $viacep['bairro'] ),
			'cep'    => sanitize_text_field( $viacep['cep'] ),
			'cidade' => sanitize_text_field( $viacep['localidade'] ),
			'estado' => strtoupper( substr( sanitize_text_field( $viacep['uf'] ), 0, 2 ) ),
			'rua'    => sanitize_text_field( $viacep['logradouro'] ),
		);

		set_transient( $chave, $endereco, 30 * DAY_IN_SECONDS );

		return $endereco;
	}

	/**
	 * Conta uma consulta externa para o IP do cliente; false se passou do limite.
	 *
	 * Usa REMOTE_ADDR (não confia em X-Forwarded-For, que o cliente pode forjar).
	 * Atrás de proxy reverso, todos compartilham o IP do proxy; o limite é largo
	 * o bastante para o uso normal (consulta de CEP só no cadastro).
	 *
	 * @return bool
	 */
	private static function dentro_do_limite(): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$chave = 'cemaden_mapa_rede_lim_' . md5( $ip );
		$uso   = get_transient( $chave );

		$uso = is_array( $uso ) ? $uso : array(
			'n'      => 0,
			'inicio' => time(),
		);

		if ( $uso['n'] >= self::LIMITE_POR_IP ) {
			return false;
		}

		++$uso['n'];
		set_transient( $chave, $uso, max( 1, self::JANELA_LIMITE - ( time() - (int) $uso['inicio'] ) ) );

		return true;
	}
}
