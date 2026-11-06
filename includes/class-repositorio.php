<?php
/**
 * Monta os itens do mapa (GeoJSON) a partir dos CPTs.
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

/**
 * Fonte de dados do mapa (sem cache; ver Cache).
 *
 * Regras de localização (em ordem):
 * - organizacao: meta `geolocalizacao` (GeoJSON) → sede do município (`cidade` + `estado`).
 * - acao: `geolocalizacao` própria → local da organização (se `reuse_address` != "no")
 *   → sede do município da ação (`cidade` + `estado`).
 * - inscricoes: sede do município (`municipio` + `estado`).
 * - atividade_da_rede: local da organização (`institution` ou `organizacao`).
 * Itens sem local entram só na lista/busca (geometry null).
 *
 * Só campos públicos saem daqui (lista branca); as inscrições guardam dados
 * pessoais (CPF, e-mail, telefone etc.) que nunca devem ser lidos nem expostos.
 *
 * Desempenho: em vez de carregar todos os metadados dos posts (as inscrições
 * têm dezenas de campos, alguns com JSON grande), lê apenas as chaves usadas,
 * numa consulta por tipo de post.
 */
final class Repositorio {

	/**
	 * Metas lidas por tipo de post (lista branca). Também invalidam o cache.
	 */
	const METAS = array(
		'organizacao'       => array( '_thumbnail_id', 'institution_type', 'geolocalizacao', 'cidade', 'estado', 'photo' ),
		'acao'              => array( '_thumbnail_id', 'organizacao', 'geolocalizacao', 'reuse_address', 'cidade', 'estado' ),
		'inscricoes'        => array( '_thumbnail_id', 'municipio', 'estado', 'tipoProponente', 'tipoProponenteSegmento', 'nomeInstituicao', 'como' ),
		'atividade_da_rede' => array( '_thumbnail_id', 'institution', 'organizacao', 'description' ),
	);

	/**
	 * Limite de caracteres de texto livre processado por item (protege CPU/memória).
	 */
	const LIMITE_TEXTO = 4000;

	/**
	 * Metas carregadas nesta construção: post_id => chave => valor.
	 *
	 * @var array<int,array<string,string>>
	 */
	private static $metas = array();

	/**
	 * Organizações já resolvidas nesta construção (id => dados).
	 *
	 * @var array<int,array>
	 */
	private static $organizacoes = array();

	/**
	 * Todas as chaves de meta observadas (para invalidar o cache).
	 *
	 * @return string[]
	 */
	public static function metas_observadas(): array {
		return array_values( array_unique( array_merge( ...array_values( self::METAS ) ) ) );
	}

	/**
	 * Constrói a coleção a partir do banco.
	 *
	 * @return array FeatureCollection.
	 */
	public static function construir(): array {
		self::$metas        = array();
		self::$organizacoes = array();

		$itens = array();

		// Organizações primeiro: ações e atividades dependem delas.
		$organizacoes = self::posts( 'organizacao' );
		foreach ( $organizacoes as $post ) {
			self::$organizacoes[ $post->ID ] = self::dados_organizacao( $post );
		}
		foreach ( $organizacoes as $post ) {
			$org     = self::$organizacoes[ $post->ID ];
			$itens[] = self::item(
				$post,
				'organizacao',
				array(
					'categoria'  => $org['categoria'],
					'coords'     => $org['coords'],
					'aproximado' => $org['aproximado'],
					'local'      => $org['local'],
					'resumo'     => self::resumo( $post ),
					'imagem'     => self::imagem( $post, true ),
				)
			);
		}

		foreach ( self::posts( 'acao' ) as $post ) {
			$itens[] = self::item( $post, 'acao', self::dados_acao( $post ) );
		}

		foreach ( self::posts( 'inscricoes' ) as $post ) {
			$itens[] = self::item( $post, 'inscricao', self::dados_inscricao( $post ) );
		}

		foreach ( self::posts( 'atividade_da_rede' ) as $post ) {
			$itens[] = self::item( $post, 'atividade', self::dados_atividade( $post ) );
		}

		self::$metas        = array();
		self::$organizacoes = array();

		return array(
			'type'      => 'FeatureCollection',
			'gerado_em' => gmdate( 'c' ),
			'features'  => $itens,
		);
	}

	/**
	 * Posts publicados (sem senha) de um tipo, com as metas da lista branca e as
	 * miniaturas pré-carregadas.
	 *
	 * @param string $post_type Post type.
	 * @return \WP_Post[]
	 */
	private static function posts( string $post_type ): array {
		$consulta = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		self::carregar_metas( $post_type );

		// Anexos das imagens destacadas (post + metadados de tamanhos) numa só ida ao banco.
		$miniaturas = array();
		foreach ( $consulta->posts as $post ) {
			$id = (int) self::meta( $post->ID, '_thumbnail_id' );
			if ( $id > 0 ) {
				$miniaturas[] = $id;
			}
		}
		if ( $miniaturas ) {
			_prime_post_caches( array_unique( $miniaturas ), false, true );
		}

		return $consulta->posts;
	}

	/**
	 * Lê, numa consulta, as metas da lista branca de todos os posts publicados do tipo.
	 *
	 * @param string $post_type Post type.
	 */
	private static function carregar_metas( string $post_type ): void {
		global $wpdb;

		$chaves = self::METAS[ $post_type ];
		$marcas = implode( ', ', array_fill( 0, count( $chaves ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$linhas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_key, pm.meta_value
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_key IN ($marcas)
				ORDER BY pm.meta_id ASC",
				array_merge( array( $post_type ), $chaves )
			),
			ARRAY_N
		);
		// phpcs:enable

		foreach ( $linhas as list( $post_id, $chave, $valor ) ) {
			// Como get_post_meta( ..., true ): vale o primeiro valor de cada chave.
			if ( ! isset( self::$metas[ (int) $post_id ][ $chave ] ) ) {
				self::$metas[ (int) $post_id ][ $chave ] = (string) $valor;
			}
		}
	}

	/**
	 * Valor de uma meta carregada (string vazia se ausente).
	 *
	 * @param int    $post_id Post.
	 * @param string $chave   Meta key.
	 * @return string
	 */
	private static function meta( int $post_id, string $chave ): string {
		return self::$metas[ $post_id ][ $chave ] ?? '';
	}

	/**
	 * Monta a Feature GeoJSON com os campos públicos.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string   $tipo  acao|inscricao|organizacao|atividade.
	 * @param array    $dados categoria, coords, aproximado, local, resumo, imagem, instituicao.
	 * @return array
	 */
	private static function item( \WP_Post $post, string $tipo, array $dados ): array {
		$grupos = array(
			'acao'        => 'acoes',
			'inscricao'   => 'acoes',
			'organizacao' => 'organizacoes',
			'atividade'   => 'atividades',
		);

		return array(
			'type'       => 'Feature',
			'id'         => $tipo . '-' . $post->ID,
			'geometry'   => $dados['coords'] ? array(
				'type'        => 'Point',
				'coordinates' => $dados['coords'],
			) : null,
			'properties' => array(
				'id'          => $post->ID,
				'tipo'        => $tipo,
				'grupo'       => $grupos[ $tipo ],
				'titulo'      => self::texto( get_the_title( $post ) ),
				'resumo'      => $dados['resumo'],
				'link'        => (string) get_permalink( $post ),
				'imagem'      => $dados['imagem'],
				'categoria'   => $dados['categoria'],
				'instituicao' => $dados['instituicao'] ?? '',
				'local'       => $dados['local'],
				'aproximado'  => (bool) $dados['aproximado'],
			),
		);
	}

	/**
	 * Dados (e local) de uma organização.
	 *
	 * @param \WP_Post $post Organização.
	 * @return array categoria, coords, aproximado, local, titulo.
	 */
	private static function dados_organizacao( \WP_Post $post ): array {
		$categoria = self::meta( $post->ID, 'institution_type' );
		if ( ! array_key_exists( $categoria, Config::categorias() ) ) {
			$categoria = 'OTHER';
		}

		$local = self::local( self::meta( $post->ID, 'cidade' ), self::meta( $post->ID, 'estado' ) );
		$ponto = self::ponto( self::meta( $post->ID, 'geolocalizacao' ) );

		return array(
			'categoria'  => $categoria,
			'coords'     => $ponto ? $ponto : $local['coords'],
			'aproximado' => $ponto ? false : $local['aproximado'],
			'local'      => $local['rotulo'],
			'titulo'     => self::texto( get_the_title( $post ) ),
		);
	}

	/**
	 * Organização publicada já carregada, pelo ID guardado num meta.
	 *
	 * @param string $id ID (texto do meta).
	 * @return array|null
	 */
	private static function organizacao( string $id ): ?array {
		return self::$organizacoes[ (int) $id ] ?? null;
	}

	/**
	 * Dados de uma ação.
	 *
	 * @param \WP_Post $post Ação.
	 * @return array
	 */
	private static function dados_acao( \WP_Post $post ): array {
		$org     = self::organizacao( self::meta( $post->ID, 'organizacao' ) );
		$proprio = self::local( self::meta( $post->ID, 'cidade' ), self::meta( $post->ID, 'estado' ) );
		$ponto   = self::ponto( self::meta( $post->ID, 'geolocalizacao' ) );

		// Ausente ou "yes" = reaproveita o endereço da organização; só "no" desliga.
		$reusa = 'no' !== self::meta( $post->ID, 'reuse_address' );

		$dados = array(
			'categoria'   => $org ? $org['categoria'] : 'OTHER',
			'instituicao' => $org ? $org['titulo'] : '',
			'coords'      => null,
			'aproximado'  => false,
			'local'       => '' !== $proprio['rotulo'] ? $proprio['rotulo'] : ( $org ? $org['local'] : '' ),
			'resumo'      => self::resumo( $post ),
			'imagem'      => self::imagem( $post ),
		);

		if ( $ponto ) {
			$dados['coords'] = $ponto;
		} elseif ( $org && $reusa && $org['coords'] ) {
			$dados['coords']     = $org['coords'];
			$dados['aproximado'] = $org['aproximado'];
		} elseif ( $proprio['coords'] ) {
			$dados['coords']     = $proprio['coords'];
			$dados['aproximado'] = true;
		}

		return $dados;
	}

	/**
	 * Dados de uma inscrição (somente campos já públicos na página da inscrição).
	 *
	 * @param \WP_Post $post Inscrição.
	 * @return array
	 */
	private static function dados_inscricao( \WP_Post $post ): array {
		$local = self::local( self::meta( $post->ID, 'municipio' ), self::meta( $post->ID, 'estado' ) );

		return array(
			'categoria'   => self::categoria_inscricao( $post->ID ),
			'instituicao' => self::texto( self::meta( $post->ID, 'nomeInstituicao' ) ),
			'coords'      => $local['coords'],
			'aproximado'  => true,
			'local'       => $local['rotulo'],
			'resumo'      => wp_trim_words( self::texto( self::meta( $post->ID, 'como' ) ), 20, '…' ),
			'imagem'      => self::imagem( $post ),
		);
	}

	/**
	 * Dados de uma atividade da rede (local e categoria da organização).
	 *
	 * @param \WP_Post $post Atividade.
	 * @return array
	 */
	private static function dados_atividade( \WP_Post $post ): array {
		$org = self::organizacao( self::meta( $post->ID, 'institution' ) );
		if ( ! $org ) {
			$org = self::organizacao( self::meta( $post->ID, 'organizacao' ) );
		}

		$resumo = self::resumo( $post );
		if ( '' === $resumo ) {
			$resumo = wp_trim_words( self::texto( self::meta( $post->ID, 'description' ) ), 20, '…' );
		}

		return array(
			'categoria'   => $org ? $org['categoria'] : 'OTHER',
			'instituicao' => $org ? $org['titulo'] : '',
			'coords'      => $org ? $org['coords'] : null,
			'aproximado'  => $org ? $org['aproximado'] : false,
			'local'       => $org ? $org['local'] : '',
			'resumo'      => $resumo,
			'imagem'      => self::imagem( $post ),
		);
	}

	/**
	 * Categoria da inscrição a partir do tipo de proponente.
	 *
	 * @param int $post_id Inscrição.
	 * @return string
	 */
	private static function categoria_inscricao( int $post_id ): string {
		switch ( Municipios::normalizar( self::meta( $post_id, 'tipoProponente' ) ) ) {
			case 'escolasdeeducacaobasica':
				return 'SCHOOL';
			case 'instituicoesdeensinosuperior':
				return 'UNIVERSITY';
			case 'organizacoessociais':
				return 'NGO';
			case 'orgaospublicos':
				$segmento = Municipios::normalizar( self::meta( $post_id, 'tipoProponenteSegmento' ) );
				return false !== strpos( $segmento, 'defesascivis' ) ? 'CIVIL_DEFENSE' : 'OTHER';
		}

		return 'OTHER';
	}

	/**
	 * Rótulo "Cidade/UF" e, se possível, a sede do município.
	 *
	 * @param string $cidade Município (texto livre).
	 * @param string $uf     UF.
	 * @return array{rotulo:string,coords:?array,aproximado:bool}
	 */
	private static function local( string $cidade, string $uf ): array {
		$sede = Municipios::localizar( $cidade, $uf );
		if ( $sede ) {
			return array(
				'rotulo'     => $sede['nome'] . '/' . $sede['uf'],
				'coords'     => array( $sede['lng'], $sede['lat'] ),
				'aproximado' => true,
			);
		}

		$cidade = mb_substr( self::texto( $cidade ), 0, 80 );
		$uf     = strtoupper( mb_substr( self::texto( $uf ), 0, 2 ) );

		return array(
			'rotulo'     => trim( $cidade . ( '' !== $cidade && '' !== $uf ? '/' : '' ) . $uf ),
			'coords'     => null,
			'aproximado' => false,
		);
	}

	/**
	 * Lê um ponto GeoJSON salvo como texto; descarta valores corrompidos.
	 *
	 * @param string $valor Valor do meta.
	 * @return float[]|null [lng, lat].
	 */
	private static function ponto( string $valor ): ?array {
		if ( '' === $valor || strlen( $valor ) > 500 ) {
			return null;
		}

		$geo = json_decode( $valor, true, 4 );
		if ( ! is_array( $geo ) ) {
			$geo = json_decode( wp_unslash( $valor ), true, 4 );
		}

		if ( ! is_array( $geo ) || 'Point' !== ( $geo['type'] ?? '' ) || ! isset( $geo['coordinates'][0], $geo['coordinates'][1] )
			|| ! is_numeric( $geo['coordinates'][0] ) || ! is_numeric( $geo['coordinates'][1] ) ) {
			return null;
		}

		$lng = (float) $geo['coordinates'][0];
		$lat = (float) $geo['coordinates'][1];

		if ( abs( $lng ) > 180 || abs( $lat ) > 90 || ( 0.0 === $lng && 0.0 === $lat ) ) {
			return null;
		}

		return array( round( $lng, 6 ), round( $lat, 6 ) );
	}

	/**
	 * Resumo em texto puro (sem aplicar the_content, que é caro com page builders).
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private static function resumo( \WP_Post $post ): string {
		$texto = '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_content;

		// Corta antes de processar: só as primeiras palavras são usadas.
		$texto = strip_shortcodes( mb_substr( $texto, 0, self::LIMITE_TEXTO ) );

		return wp_trim_words( self::texto( $texto ), 20, '…' );
	}

	/**
	 * Imagem do card: destacada → meta `photo` (organizações) → 1ª imagem do conteúdo → padrão.
	 *
	 * Só URLs http(s) válidas são aceitas.
	 *
	 * @param \WP_Post $post       Post.
	 * @param bool     $usar_photo Considerar o meta `photo`.
	 * @return string
	 */
	private static function imagem( \WP_Post $post, bool $usar_photo = false ): string {
		$id = (int) self::meta( $post->ID, '_thumbnail_id' );
		if ( $id > 0 ) {
			$url = wp_get_attachment_image_url( $id, 'medium_large' );
			if ( $url ) {
				return $url;
			}
		}

		$candidatas = array();
		if ( $usar_photo ) {
			$candidatas[] = self::meta( $post->ID, 'photo' );
		}
		if ( preg_match( '/<img[^>]+src=[\'"]([^\'"]{1,2048})[\'"]/i', mb_substr( $post->post_content, 0, 200000 ), $m ) ) {
			$candidatas[] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
		}

		// Validação local (sem DNS): só URLs absolutas http(s) com host.
		foreach ( $candidatas as $url ) {
			$limpa = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
			if ( '' !== $limpa && preg_match( '#^https?://#i', $limpa ) && wp_parse_url( $limpa, PHP_URL_HOST ) ) {
				return $limpa;
			}
		}

		return plugins_url( 'assets/img/placeholder.png', ARQUIVO );
	}

	/**
	 * Texto puro: sem tags e com entidades decodificadas (o front escapa na saída).
	 *
	 * @param string $texto Texto.
	 * @return string
	 */
	private static function texto( string $texto ): string {
		$texto = html_entity_decode( wp_strip_all_tags( mb_substr( $texto, 0, self::LIMITE_TEXTO ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $texto ) );
	}
}
