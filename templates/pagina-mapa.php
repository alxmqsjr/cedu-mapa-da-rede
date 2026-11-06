<?php
/**
 * Template de página "Mapa da Rede (plugin)".
 *
 * Usa apenas o cabeçalho e o rodapé do tema ativo.
 *
 * @package CemadenMapaRede
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="cmr-conteudo" class="cmr-pagina__conteudo">
	<?php echo \CemadenMapaRede\Frontend::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML montado e escapado em Frontend::render(). ?>
</main>
<?php
get_footer();
