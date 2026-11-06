<?php
/**
 * Configurações e vocabulários do mapa.
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

/**
 * Valores fixos (categorias, tipos) e configuração do mapa base.
 */
final class Config {

	/**
	 * Estilo vetorial livre usado por padrão (OpenFreeMap, sem chave).
	 */
	const ESTILO_POSITRON = 'https://tiles.openfreemap.org/styles/positron';

	/**
	 * Glyphs do estilo raster do OSM (o raster não traz fontes; usadas nos números dos clusters).
	 */
	const GLYPHS_RESERVA = 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf';

	/**
	 * Cor padrão da água (mar, lagos e rios) no mapa base vetorial.
	 */
	const COR_AGUA = '#bceeff';

	/**
	 * Categorias exibidas no mapa, na ordem da legenda (slug => rótulo).
	 *
	 * Os slugs são os valores do meta `institution_type` das organizações.
	 *
	 * @return array<string,string>
	 */
	public static function categorias(): array {
		return array(
			'SCHOOL'        => __( 'Escola', 'cedu-mapa-da-rede' ),
			'CIVIL_DEFENSE' => __( 'Defesa Civil', 'cedu-mapa-da-rede' ),
			'NGO'           => __( 'Não governamental', 'cedu-mapa-da-rede' ),
			'COLLECTIVE'    => __( 'Coletivo', 'cedu-mapa-da-rede' ),
			'RELIGIOUS'     => __( 'Instituição Religiosa', 'cedu-mapa-da-rede' ),
			'UNIVERSITY'    => __( 'Universidade', 'cedu-mapa-da-rede' ),
			'OTHER'         => __( 'Outro', 'cedu-mapa-da-rede' ),
		);
	}

	/**
	 * Grupos do seletor "Exibindo" (slug => rótulo).
	 *
	 * "Ações" reúne os CPTs `acao` e `inscricoes`.
	 *
	 * @return array<string,string>
	 */
	public static function grupos(): array {
		return array(
			'acoes'        => __( 'Ações', 'cedu-mapa-da-rede' ),
			'organizacoes' => __( 'Organizações', 'cedu-mapa-da-rede' ),
			'atividades'   => __( 'Atividades da rede', 'cedu-mapa-da-rede' ),
		);
	}

	/**
	 * Post types lidos pelo mapa.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		return array( 'acao', 'inscricoes', 'organizacao', 'atividade_da_rede' );
	}

	/**
	 * Configuração do mapa base para o front-end.
	 *
	 * Pode ser trocada pela constante CEMADEN_MAPA_REDE_ESTILO no wp-config.php:
	 * 'positron' (padrão), 'osm' ou a URL https de um estilo MapLibre (JSON).
	 *
	 * 'reserva' é usada automaticamente pelo front-end se o estilo principal
	 * não carregar (serviço fora do ar), para o mapa nunca ficar em branco.
	 *
	 * 'cor_agua' recolore as camadas `water` e `waterway` do estilo vetorial
	 * (sem efeito no raster do OSM). Constante CEMADEN_MAPA_REDE_COR_AGUA:
	 * cor hexadecimal ou '' para manter a cor original do estilo.
	 *
	 * @return array{estilo:mixed,fonte:string[],reserva:array,cor_agua:string}
	 */
	public static function mapa_base(): array {
		$escolha = defined( 'CEMADEN_MAPA_REDE_ESTILO' ) ? (string) CEMADEN_MAPA_REDE_ESTILO : 'positron';
		$osm     = self::estilo_osm();

		if ( 'osm' === $escolha ) {
			$config = $osm;
		} elseif ( 'positron' === $escolha || '' === $escolha ) {
			$config = array(
				'estilo' => self::ESTILO_POSITRON,
				'fonte'  => array( 'Noto Sans Bold' ),
			);
		} else {
			$url    = esc_url_raw( $escolha, array( 'https' ) );
			$config = '' !== $url ? array(
				'estilo' => $url,
				'fonte'  => array( 'Noto Sans Bold' ),
			) : $osm;
		}

		$config['reserva']  = $osm;
		$config['cor_agua'] = defined( 'CEMADEN_MAPA_REDE_COR_AGUA' ) ? (string) CEMADEN_MAPA_REDE_COR_AGUA : self::COR_AGUA;

		/**
		 * Filtra a configuração do mapa base.
		 *
		 * @param array $config 'estilo' (URL ou objeto de estilo), 'fonte' (text-font dos números dos
		 *                      clusters, precisa existir nos glyphs do estilo), 'reserva' (mesmo formato)
		 *                      e 'cor_agua' (cor hexadecimal da água; '' mantém a do estilo).
		 */
		return apply_filters( 'cemaden_mapa_rede_mapa_base', $config );
	}

	/**
	 * Estilo raster com os tiles padrão do OpenStreetMap e glyphs do projeto MapLibre
	 * (independente do OpenFreeMap, por isso serve de reserva).
	 *
	 * @return array{estilo:array,fonte:string[]}
	 */
	private static function estilo_osm(): array {
		return array(
			'estilo' => array(
				'version' => 8,
				'glyphs'  => self::GLYPHS_RESERVA,
				'sources' => array(
					'osm' => array(
						'type'        => 'raster',
						'tiles'       => array( 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ),
						'tileSize'    => 256,
						'maxzoom'     => 19,
						'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
					),
				),
				'layers'  => array(
					array(
						'id'     => 'osm',
						'type'   => 'raster',
						'source' => 'osm',
					),
				),
			),
			'fonte'  => array( 'Open Sans Semibold' ),
		);
	}
}
