<?php
/**
 * Template de página, shortcode e assets do mapa.
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

/**
 * Exibição do mapa.
 *
 * - Template de página "Mapa da Rede (plugin)", que usa só get_header()/get_footer() do tema.
 * - Shortcode [cemaden_mapa_rede] para usar em qualquer conteúdo.
 * - Assets carregados apenas nas páginas que exibem o mapa.
 */
final class Frontend {

	/**
	 * Identificador do modelo gravado no meta `_wp_page_template` das páginas (não é
	 * um caminho; independe do nome da pasta). Não alterar: as páginas perderiam o modelo.
	 */
	const TEMPLATE  = 'cemaden-mapa-rede/pagina-mapa.php';
	const SHORTCODE = 'cemaden_mapa_rede';
	const HANDLE    = 'cemaden-mapa-rede';

	/**
	 * Registra hooks.
	 */
	public static function registrar(): void {
		add_filter( 'theme_page_templates', array( self::class, 'listar_template' ) );
		add_filter( 'template_include', array( self::class, 'usar_template' ) );
		add_filter( 'body_class', array( self::class, 'classe_body' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'registrar_assets' ) );
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
	}

	/**
	 * Oferece o template no seletor "Modelo" das páginas.
	 *
	 * @param array $templates Templates do tema.
	 * @return array
	 */
	public static function listar_template( array $templates ): array {
		$templates[ self::TEMPLATE ] = __( 'Mapa da Rede (plugin)', 'cedu-mapa-da-rede' );

		return $templates;
	}

	/**
	 * Carrega o template do plugin quando a página o usa.
	 *
	 * @param string $template Template escolhido pelo WordPress.
	 * @return string
	 */
	public static function usar_template( string $template ): string {
		if ( self::eh_pagina_do_template() ) {
			return DIR . '/templates/pagina-mapa.php';
		}

		return $template;
	}

	/**
	 * Classe no <body> para o layout em tela cheia.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function classe_body( array $classes ): array {
		if ( self::eh_pagina_do_template() ) {
			$classes[] = 'cmr-pagina';
		}

		return $classes;
	}

	/**
	 * Registra os assets e os enfileira só onde o mapa aparece.
	 */
	public static function registrar_assets(): void {
		self::registrar_handles();

		if ( self::pagina_tem_mapa() ) {
			wp_enqueue_style( self::HANDLE );
			wp_enqueue_script( self::HANDLE );
		}
	}

	/**
	 * Registra os handles de JS/CSS (uma vez).
	 */
	private static function registrar_handles(): void {
		$js  = DIR . '/build/mapa-rede.js';
		$css = DIR . '/build/mapa-rede.css';

		if ( wp_script_is( self::HANDLE, 'registered' ) || ! file_exists( $js ) || ! file_exists( $css ) ) {
			return;
		}

		wp_register_style( self::HANDLE, plugins_url( 'build/mapa-rede.css', ARQUIVO ), array(), (string) filemtime( $css ) );
		wp_register_script(
			self::HANDLE,
			plugins_url( 'build/mapa-rede.js', ARQUIVO ),
			array(),
			(string) filemtime( $js ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Shortcode [cemaden_mapa_rede].
	 *
	 * @return string
	 */
	public static function shortcode(): string {
		return self::render();
	}

	/**
	 * HTML do contêiner; o app (Vue) monta o restante a partir de data-config.
	 *
	 * @return string
	 */
	public static function render(): string {
		// Garante os assets mesmo quando o shortcode está num conteúdo que não foi detectado antes.
		self::registrar_handles();

		$worker = DIR . '/build/maplibre-worker.js';
		if ( ! wp_script_is( self::HANDLE, 'registered' ) || ! file_exists( $worker ) ) {
			// Envio incompleto do plugin: avisa em vez de deixar "Carregando…" para sempre.
			return '<p class="cmr-indisponivel">' . esc_html__( 'O Mapa da Rede está temporariamente indisponível.', 'cedu-mapa-da-rede' ) . '</p>';
		}

		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		$base   = Config::mapa_base();
		$config = array(
			'endpoint'   => Rest::url(),
			'worker'     => add_query_arg( 'ver', (string) filemtime( $worker ), plugins_url( 'build/maplibre-worker.js', ARQUIVO ) ),
			'marcadores' => plugins_url( 'assets/markers/', ARQUIVO ),
			'icones'     => plugins_url( 'assets/img/', ARQUIVO ),
			'titulo'     => is_singular() ? wp_strip_all_tags( get_the_title() ) : __( 'Mapa da Rede', 'cedu-mapa-da-rede' ),
			'categorias' => Config::categorias(),
			'grupos'     => Config::grupos(),
			'estilo'     => $base['estilo'],
			'fonte'      => $base['fonte'],
			'reserva'    => $base['reserva'],
			'corAgua'    => (string) sanitize_hex_color( $base['cor_agua'] ?? '' ),
			'centro'     => array( -54, -14 ),
			'zoom'       => array(
				'desktop' => 3.75,
				'mobile'  => 2.75,
			),
		);

		return sprintf(
			'<div class="cmr-app" data-config="%1$s"><p class="cmr-carregando">%2$s</p><noscript>%3$s</noscript></div>',
			esc_attr( wp_json_encode( $config ) ),
			esc_html__( 'Carregando o mapa…', 'cedu-mapa-da-rede' ),
			esc_html__( 'O Mapa da Rede precisa de JavaScript habilitado.', 'cedu-mapa-da-rede' )
		);
	}

	/**
	 * A página atual usa o template do plugin?
	 *
	 * @return bool
	 */
	private static function eh_pagina_do_template(): bool {
		return is_page() && self::TEMPLATE === get_page_template_slug( get_queried_object_id() );
	}

	/**
	 * A página atual exibe o mapa (template ou shortcode no conteúdo)?
	 *
	 * @return bool
	 */
	private static function pagina_tem_mapa(): bool {
		if ( self::eh_pagina_do_template() ) {
			return true;
		}

		$post = is_singular() ? get_queried_object() : null;

		return $post instanceof \WP_Post && has_shortcode( $post->post_content, self::SHORTCODE );
	}
}
