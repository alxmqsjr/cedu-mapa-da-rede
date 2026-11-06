<?php
/**
 * Localização aproximada pela sede do município (tabela do IBGE).
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

/**
 * Resolve "município + UF" (texto livre) para as coordenadas da sede municipal.
 *
 * Dados: data/municipios.php, gerado a partir de kelvins/municipios-brasileiros
 * (MIT, códigos e nomes do IBGE). Nenhuma chamada externa.
 */
final class Municipios {

	/**
	 * Índice UF => nome normalizado => [lat, lng, nome oficial, código IBGE].
	 *
	 * @var array<string,array<string,array>>|null
	 */
	private static $indice = null;

	/**
	 * Siglas válidas de UF.
	 *
	 * @return string[]
	 */
	public static function ufs(): array {
		return array_keys( self::indice() );
	}

	/**
	 * Procura a sede do município.
	 *
	 * @param string $municipio Nome do município (texto livre; aceita "Cidade - UF").
	 * @param string $uf        Sigla da UF.
	 * @return array{lat:float,lng:float,nome:string,uf:string,ibge:int}|null
	 */
	public static function localizar( string $municipio, string $uf ): ?array {
		// Nomes de município têm até ~35 letras; cortar antes das regex limita o custo com entradas enormes.
		$municipio = trim( wp_strip_all_tags( mb_substr( $municipio, 0, 120 ) ) );
		$uf        = strtoupper( trim( mb_substr( $uf, 0, 10 ) ) );

		// "Recife - PE", "Recife/PE", "Recife (PE)": separa a UF do nome.
		if ( preg_match( '/^(.+?)\s*(?:[-\/,]|\()\s*([A-Za-z]{2})\)?\s*$/u', $municipio, $m ) ) {
			$sigla = strtoupper( $m[2] );
			if ( isset( self::indice()[ $sigla ] ) ) {
				$municipio = $m[1];
				$uf        = '' === $uf ? $sigla : $uf;
			}
		}

		$indice = self::indice();
		if ( '' === $municipio || ! isset( $indice[ $uf ] ) ) {
			return null;
		}

		$chave = self::encontrar( $municipio, $indice[ $uf ] );

		// O DF tem um único município; regiões administrativas (Ceilândia etc.) ficam em Brasília.
		if ( null === $chave && 'DF' === $uf && 1 === count( $indice['DF'] ) ) {
			$chave = array_key_first( $indice['DF'] );
		}

		if ( null === $chave ) {
			return null;
		}

		list( $lat, $lng, $nome, $ibge ) = $indice[ $uf ][ $chave ];

		return array(
			'lat'  => (float) $lat,
			'lng'  => (float) $lng,
			'nome' => $nome,
			'uf'   => $uf,
			'ibge' => (int) $ibge,
		);
	}

	/**
	 * Casa o texto com um município da UF.
	 *
	 * Tenta, em ordem: o nome inteiro; o primeiro município de uma lista
	 * ("Fortaleza, Pacajus e Ocara", "Jaboatão/ Recife"); e um prefixo único com
	 * pelo menos 5 letras ("Jaboatão" → "Jaboatão dos Guararapes").
	 *
	 * @param string $municipio Texto livre.
	 * @param array  $da_uf     Índice da UF (nome normalizado => dados).
	 * @return string|null Chave no índice.
	 */
	private static function encontrar( string $municipio, array $da_uf ): ?string {
		$candidatos = array_unique(
			array(
				self::normalizar( $municipio ),
				self::normalizar( (string) preg_split( '/\s*(?:[,;\/]|\s+e\s+)\s*/u', $municipio )[0] ),
			)
		);

		foreach ( $candidatos as $chave ) {
			if ( isset( $da_uf[ $chave ] ) ) {
				return $chave;
			}
		}

		foreach ( $candidatos as $chave ) {
			if ( strlen( $chave ) < 5 ) {
				continue;
			}
			$achados = array_filter(
				array_keys( $da_uf ),
				static function ( $nome ) use ( $chave ) {
					return 0 === strpos( (string) $nome, $chave );
				}
			);
			if ( 1 === count( $achados ) ) {
				return (string) reset( $achados );
			}
		}

		return null;
	}

	/**
	 * Normaliza nomes para comparação: sem acentos, caixa, pontuação e espaços.
	 *
	 * "São João del-Rei" e "Sao Joao Del Rei" viram "saojoaodelrei";
	 * "Pérola d'Oeste" e "Perola D Oeste" viram "peroladoeste".
	 *
	 * @param string $texto Texto livre.
	 * @return string
	 */
	public static function normalizar( string $texto ): string {
		$texto = remove_accents( html_entity_decode( $texto, ENT_QUOTES, 'UTF-8' ) );
		$texto = strtolower( $texto );

		return (string) preg_replace( '/[^a-z0-9]+/', '', $texto );
	}

	/**
	 * Carrega o índice uma vez por requisição.
	 *
	 * @return array<string,array<string,array>>
	 */
	private static function indice(): array {
		if ( null === self::$indice ) {
			$dados        = require DIR . '/data/municipios.php';
			self::$indice = array();
			foreach ( $dados as $uf => $lista ) {
				foreach ( $lista as $linha ) {
					self::$indice[ $uf ][ self::normalizar( $linha[0] ) ] = array( $linha[1], $linha[2], $linha[0], $linha[3] );
				}
			}
		}

		return self::$indice;
	}
}
