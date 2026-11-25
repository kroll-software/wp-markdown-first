<?php
/**
 * MD First theme — 404.
 *
 * @package md-first-theme
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard template hook.
get_header();
?>
<div class="not-found">
	<h1 class="page-title">404</h1>
	<p><?php esc_html_e( 'This content could not be found. It may have been moved or removed.', 'md-first-theme' ); ?></p>
	<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Go home', 'md-first-theme' ); ?></a></p>
</div>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard template hook.
get_footer();
