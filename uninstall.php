<?php
/**
 * Remoção do plugin: apaga apenas os dados de cache criados por ele.
 * Nenhum conteúdo (posts, metas) é alterado.
 *
 * @package CemadenMapaRede
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'cemaden_mapa_rede_cache' );
delete_option( 'cemaden_mapa_rede_sujo' );
delete_option( 'cemaden_mapa_rede_trava' );
wp_clear_scheduled_hook( 'cemaden_mapa_rede_reconstruir' );

// Caches de CEP, geocodificação e limite de requisições (transients com prefixo próprio),
// além do cache da versão 1.0.0.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cemaden_mapa_rede_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cemaden_mapa_rede_' ) . '%'
	)
);
