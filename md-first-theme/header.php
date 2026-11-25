<?php
/**
 * MD First theme — document head + frame top.
 *
 * @package md-first-theme
 */

defined( 'ABSPATH' ) || exit;
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<a class="skip-link" href="#main"><?php esc_html_e( 'Skip to main content', 'md-first-theme' ); ?></a>

<header class="site-header">
	<div class="inner">
		<div class="site-brand">
			<p class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></p>
			<?php if ( '' !== get_bloginfo( 'description' ) ) : ?>
				<p class="site-description"><?php bloginfo( 'description' ); ?></p>
			<?php endif; ?>
		</div>
		<nav class="site-nav" aria-label="<?php esc_attr_e( 'Primary', 'md-first-theme' ); ?>">
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'primary',
					'container'      => false,
					'menu_class'     => 'menu',
					'fallback_cb'    => 'mdft_nav_fallback',
				)
			);
			?>
		</nav>
	</div>
</header>

<main id="main" class="site-main">
