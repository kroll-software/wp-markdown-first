<?php
/**
 * MD First theme — setup.
 *
 * Zero-JS theme (Konzeption/04 §6): no scripts are enqueued anywhere in
 * this theme. Markdown/Math/endpoint logic stays in the md-first-core
 * plugin (Konzeption/04 §1 coupling rules); this file only uses standard
 * WordPress templates plus guarded core helpers, so a deactivated plugin
 * degrades gracefully (plain content) instead of erroring.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Theme capabilities + menus.
 */
function mdft_setup(): void {
	add_theme_support( 'title-tag' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'customize-selective-refresh-widgets' );

	load_theme_textdomain( 'md-first-theme' );

	register_nav_menus(
		array(
			'primary' => __( 'Primary Menu', 'md-first-theme' ),
		)
	);
}
add_action( 'after_setup_theme', 'mdft_setup' );

/**
 * Theme stylesheet.
 *
 * WordPress core (7.x) does not enqueue classic theme stylesheets
 * automatically — this theme registers its design system explicitly
 * (no JS, no build step).
 */
function mdft_assets(): void {
	wp_enqueue_style(
		'mdft-style',
		get_template_directory_uri() . '/style.css',
		array(),
		(string) wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'mdft_assets' );

/**
 * Machine-readable Markdown alternate in <head> (Konzeption/04 §6,
 * acceptance criterion 8):
 *
 *   <link rel="alternate" type="text/markdown" href="…/slug.md" />
 *
 * Guarded via function_exists(): with the plugin disabled the theme must
 * render without error — the link simply does not appear.
 */
function mdft_md_alternate(): void {
	if ( ! is_singular( array( 'post', 'page' ) ) ) {
		return;
	}
	if ( ! function_exists( 'mdf_md_alt_href' ) ) {
		return;
	}
	$href = mdf_md_alt_href( (int) get_the_ID() );
	if ( '' !== $href ) {
		printf(
			'<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
			esc_url( $href )
		);
	}
}
add_action( 'wp_head', 'mdft_md_alternate', 1 );

/**
 * Excerpt length for post lists. The md-first-core plugin takes over
 * excerpt rendering for Markdown content; this is the fallback.
 *
 * @param int $length Default length in words.
 */
function mdft_excerpt_length( $length ) {
	return is_admin() ? (int) $length : 28;
}
add_filter( 'excerpt_length', 'mdft_excerpt_length' );

/**
 * Fallback navigation: flat page list, for installs without an assigned
 * menu. (wp_nav_menu fallback callback — no JS involved.)
 */
function mdft_nav_fallback(): void {
	wp_list_pages(
		array(
			'title_li' => '',
			'depth'    => 1,
		)
	);
}
