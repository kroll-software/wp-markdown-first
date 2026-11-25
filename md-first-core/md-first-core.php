<?php
/**
 * Plugin Name:       MD First Core
 * Description:       Markdown-First Companion: post_content als Single Source of Truth —
 *                    Markdown→HTML-Renderer (LaTeX→MathML, 0 JS), native MD-Exposition
 *                    (/<slug>.md, Accept: text/markdown, /llms.txt) und mdf/*-Abilities
 *                    für die WordPress Abilities API / den offiziellen MCP-Adapter.
 * Version:           1.3.30
 * Requires at least: 6.9
 * Author:            Kroll-Software
 * License:           MIT
 * Text Domain:       md-first-core
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MDF_CORE_VERSION', '1.3.30' );
define( 'MDF_CORE_SCHEMA',  'mdf/1.0.0' );
define( 'MDF_CORE_PATH',    plugin_dir_path( __FILE__ ) );
define( 'MDF_CORE_FILE',    __FILE__ );

// Abhängigkeiten aus dem dev-lokal installierten vendor/-Baum (kein Composer zur Laufzeit).
$mdf_vendor = MDF_CORE_PATH . 'vendor/autoload.php';
if ( file_exists( $mdf_vendor ) ) {
	require_once $mdf_vendor;
}

require_once MDF_CORE_PATH . 'includes/class-mdf-renderer.php';
require_once MDF_CORE_PATH . 'includes/class-mdf-endpoints.php';
require_once MDF_CORE_PATH . 'includes/class-mdf-abilities.php';
require_once MDF_CORE_PATH . 'includes/class-mdf-admin.php';
require_once MDF_CORE_PATH . 'includes/class-mdf-settings.php';

/**
 * Zentrale Konfiguration (per Filter 'mdf_config' überschreibbar).
 *
 * @return array<string, mixed>
 */
function mdf_config() {
	static $cfg = null;
	if ( null === $cfg ) {
		$cfg = apply_filters(
			'mdf_config',
			array(
				'md_endpoints'  => true, // <slug>.md + Accept: text/markdown
				'llms'          => true, // /llms.txt + /llms-full.txt
				'list_max'      => 100,  // Obergrenze per_page bei mdf/list-posts-markdown
				'excerpt_words' => 30,   // Auto-Excerpt-Länge in Wörtern
			)
		);
	}
	return $cfg;
}

// ── Hooks (Hook-Ordnung: Kategorie VOR Abilities — bewusste Reihenfolge) ─────────
// i18n: Textdomain (de_DE.mo unter languages/, Default der Strings = Englisch).
add_action(
	'init',
	function () {
		load_plugin_textdomain(
			'md-first-core',
			false,
			'md-first-core/languages/'
		);
	},
	1
);
add_action( 'init', array( 'Mdf_Renderer', 'install_content_pipeline' ) );
add_action( 'init', array( 'Mdf_Endpoints', 'init' ), 5 );
add_action( 'wp_abilities_api_categories_init', array( 'Mdf_Abilities', 'register_category' ) );
add_action( 'wp_abilities_api_init', array( 'Mdf_Abilities', 'register_abilities' ) );

/**
 * Frontend: Content-Styles für gerendertes Markdown (helle Referenz-Optik) ausliefern.
 * Version = mtime → Browser-Cache wird bei CSS-Änderungen automatisch gebusted.
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		$css = MDF_CORE_PATH . 'assets/mdf-content.css';
		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'mdf-content',
				plugin_dir_url( MDF_CORE_FILE ) . 'assets/mdf-content.css',
				array(),
				(string) @filemtime( $css )
			);
		}
	}
);

register_activation_hook( __FILE__, array( 'Mdf_Endpoints', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mdf_Endpoints', 'deactivate' ) );
