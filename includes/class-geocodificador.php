<?php
/**
 * Geocodificação de endereços (substitui o \Jeo\Geocode_Handler).
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

/**
 * Endereço → coordenadas via Nominatim (OpenStreetMap), o mesmo serviço que o
 * JEO usava, respeitando a política de uso: User-Agent identificado, cache e
 * no máximo 1 requisição por segundo.
 *
 * Se uma chamada chegar a menos de 1 s da anterior, não espera (o que prenderia
 * um processo PHP): devolve a sede do município. Também usa a sede quando o
 * Nominatim não encontra o endereço ou está fora do ar.
 */
final class Geocodificador {

	const URL_NOMINATIM = 'https://nominatim.openstreetmap.org/search';
	const CHAVE_ULTIMA  = 'cemaden_mapa_rede_geo_ultima';

	/**
	 * Geocodifica um endereço.
	 *
	 * @param string $endereco Logradouro e complemento (texto livre).
	 * @param string $cidade   Município.
	 * @param string $uf       UF.
	 * @return array{lat:float,lon:float,precisao:string}|null precisao = 'endereco' ou 'municipio'.
	 */
	public static function geocodificar( string $endereco, string $cidade, string $uf ): ?array {
		$consulta = mb_substr( trim( implode( ', ', array_filter( array( $endereco, $cidade, $uf ) ) ) ), 0, 200 );

		$ponto = '' !== $consulta ? self::nominatim( $consulta ) : null;
		if ( $ponto ) {
			return $ponto + array( 'precisao' => 'endereco' );
		}

		$sede = Municipios::localizar( $cidade, $uf );
		if ( $sede ) {
			return array(
				'lat'      => $sede['lat'],
				'lon'      => $sede['lng'],
				'precisao' => 'municipio',
			);
		}

		return null;
	}

	/**
	 * Consulta o Nominatim (cache de 30 dias; endereço não encontrado: 1 dia).
	 *
	 * @param string $consulta Texto do endereço.
	 * @return array{lat:float,lon:float}|null
	 */
	private static function nominatim( string $consulta ): ?array {
		$chave = 'cemaden_mapa_rede_geo_' . md5( strtolower( $consulta ) );
		$cache = get_transient( $chave );
		if ( is_array( $cache ) ) {
			return $cache['ponto'];
		}

		// Respeita 1 req/s sem bloquear: se a última foi há menos de 1 s, desiste desta vez.
		if ( microtime( true ) - (float) get_transient( self::CHAVE_ULTIMA ) < 1 ) {
			return null;
		}
		set_transient( self::CHAVE_ULTIMA, microtime( true ), MINUTE_IN_SECONDS );

		$resposta = wp_safe_remote_get(
			add_query_arg(
				array(
					'q'            => rawurlencode( $consulta ),
					'format'       => 'jsonv2',
					'limit'        => 1,
					'countrycodes' => 'br',
				),
				self::URL_NOMINATIM
			),
			array(
				'timeout'             => 6,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'user-agent'          => 'CemadenEducacao-MapaRede/' . VERSAO . ' (+' . home_url( '/' ) . ')',
				'headers'             => array( 'Accept-Language' => 'pt-BR' ),
			)
		);

		if ( is_wp_error( $resposta ) || 200 !== wp_remote_retrieve_response_code( $resposta ) ) {
			// Falha temporária (rede, limite do serviço): não guarda em cache.
			return null;
		}

		$dados = json_decode( wp_remote_retrieve_body( $resposta ), true, 8 );
		$ponto = null;
		if ( isset( $dados[0]['lat'], $dados[0]['lon'] ) && is_numeric( $dados[0]['lat'] ) && is_numeric( $dados[0]['lon'] ) ) {
			$lat = (float) $dados[0]['lat'];
			$lon = (float) $dados[0]['lon'];
			if ( abs( $lat ) <= 90 && abs( $lon ) <= 180 ) {
				$ponto = array(
					'lat' => $lat,
					'lon' => $lon,
				);
			}
		}

		set_transient( $chave, array( 'ponto' => $ponto ), $ponto ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS );

		return $ponto;
	}
}
