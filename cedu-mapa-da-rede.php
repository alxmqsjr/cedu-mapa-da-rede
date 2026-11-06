<?php
/**
 * Plugin Name:       Cemaden Educação: Mapa da Rede
 * Plugin URI:        https://github.com/alxmqsjr/cedu-mapa-da-rede
 * Description:       Mapa da Rede do Cemaden Educação (ações, inscrições, organizações e atividades da rede) com MapLibre GL JS, sem dependência do JEO.
 * Version:           1.2.1
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            Alexandre Marques
 * Author URI:        https://github.com/alxmqsjr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cedu-mapa-da-rede
 * Update URI:        https://github.com/alxmqsjr/cedu-mapa-da-rede
 *
 * @package CemadenMapaRede
 */

namespace CemadenMapaRede;

defined( 'ABSPATH' ) || exit;

const VERSAO  = '1.2.1';
const ARQUIVO = __FILE__;
const DIR     = __DIR__;

require_once DIR . '/includes/class-config.php';
require_once DIR . '/includes/class-municipios.php';
require_once DIR . '/includes/class-repositorio.php';
require_once DIR . '/includes/class-cache.php';
require_once DIR . '/includes/class-rest.php';
require_once DIR . '/includes/class-geocodificador.php';
require_once DIR . '/includes/class-rota-cep.php';
require_once DIR . '/includes/class-frontend.php';
require_once DIR . '/includes/class-atualizador.php';

add_action(
	'plugins_loaded',
	static function () {
		Cache::registrar();
		Rest::registrar();
		Rota_Cep::registrar();
		Frontend::registrar();
		Atualizador::registrar();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once DIR . '/includes/class-cli.php';
			\WP_CLI::add_command( 'cemaden-mapa', Cli::class );
		}
	}
);

register_deactivation_hook( ARQUIVO, array( Cache::class, 'limpar' ) );

// Monta o cache logo após a ativação (via WP-Cron), para o primeiro visitante não esperar.
register_activation_hook(
	ARQUIVO,
	static function () {
		wp_schedule_single_event( time(), Cache::EVENTO );
	}
);
