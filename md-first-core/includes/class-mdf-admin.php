<?php
/**
 * MD-First Core — Admin: Markdown-Editor (CodeMirror 5, klassischer Editor-Stil).
 *
 * Konzept (klein, rückkompatibel, kein Fremd-Plugin):
 * 1) Post/Page fallen per Core-Filter auf den klassischen WP-Editor zurück
 *    (exakt die Filter, die das offizielle Classic-Editor-Plugin intern nutzt).
 * 2) Auf dem Textfeld (id="content") läuft CodeMirror 5: Mono-Font,
 *    Markdown/GFM-Syntax-Highlighting + Math-Erkennung ($…$, $$…$$, \Befehle).
 * 3) Speichern läuft komplett über den nativen WP-Formularweg — das Textfeld
 *    enthält exakt den Text, den der Nutzer eingegeben hat. Kein HTML-Filter,
 *    keine Konvertierung auf Schreibpfad; die Frontend-Renderung bleibt
 *    Mdf_Renderer (Display-Seite).
 * 4) Luxus-Tab "Vorschau": rendert live über Mdf_Renderer::render() (gleiche
 *    Pipeline wie die Frontend) per admin-post AJAX-Aufruf.
 *
 * @package MD_First_Core
 */

defined( 'ABSPATH' ) || exit;

final class MDF_Admin {

	/** Post-Typen, für die der Markdown-Editor aktiv ist. */
	const MD_TYPES = array( 'post', 'page' );

	public static function register(): void {
		// 1) Klassischer Editor statt Block-Editor (Core-Fallback, FSE-tauglich).
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'no_block_editor_for_type' ), 10, 2 );
		add_filter( 'use_block_editor_for_post',      array( __CLASS__, 'no_block_editor_for_post' ), 10, 2 );

		// 2) Reines Textfeld: kein TinyMCE, keine Medien-Buttons.
		add_filter( 'wp_editor_settings', array( __CLASS__, 'editor_settings_filter' ), 10, 2 );

		// 3) Assets + Vorschau-Handler.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_action( 'admin_post_mdf_preview', array( __CLASS__, 'handle_preview' ) );
	}

	/**
	 * Block-Editor für MD-Posttypen deaktivieren.
	 *
	 * @param bool        $use       Ob der Block-Editor genutzt wird.
	 * @param WP_Post_Type $post_type Post-Typ-Objekt.
	 * @return bool
	 */
	public static function no_block_editor_for_type( bool $use, $post_type ): bool {
		return in_array( $post_type->name, self::MD_TYPES, true ) ? false : $use;
	}

	/**
	 * Dito für konkrete Posts.
	 *
	 * @param bool    $use  Ob der Block-Editor genutzt wird.
	 * @param WP_Post $post Post-Objekt.
	 * @return bool
	 */
	public static function no_block_editor_for_post( bool $use, $post ): bool {
		return in_array( $post->post_type, self::MD_TYPES, true ) ? false : $use;
	}

	/**
	 * Einstellungen des Content-Editors: Text-only.
	 *
	 * @param array $settings      Editor-Settings.
	 * @param string $editor_id    Editor-Instanz.
	 * @return array
	 */
	public static function editor_settings_filter( array $settings, string $editor_id ): array {
		if ( 'content' !== $editor_id ) {
			return $settings;
		}
		$settings['tinymce']       = false;
		$settings['media_buttons'] = false;
		$settings['textarea_rows'] = 30;
		return $settings;
	}

	/**
	 * Assets nur auf der Post-/Page-Bearbeitungsseite laden.
	 *
	 * @param string $hook Admin-Hook Suffix.
	 * @return void
	 */
	public static function assets( string $hook ): void {
		global $post;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_md  = $post instanceof WP_Post
			&& in_array( $post->post_type, self::MD_TYPES, true )
			&& false !== $screen
			&& 'post' === $screen->id;
		if ( ! $is_md ) {
			return;
		}
		$base = plugin_dir_url( MDF_CORE_FILE ) . 'assets/editor/';
		$c    = '5.65.16';
		$p = '1.3.20-fence-bg'; // Version-String muss bei jeder JS/CSS-Änderung steigen (Browser-Cache-Key!)

		wp_enqueue_style( 'mdf-codemirror', $base . 'codemirror.min.css', array(), $c );
		wp_enqueue_style( 'mdf-editor',     $base . 'mdf-editor.css',     array( 'mdf-codemirror' ), $p );

		// Content-Styles der Seite (Frontend-Datei) für die Vorschau-Panne:
		// nach mdf-editor.css, damit identische Regeln die Vorschau-Stilgebung
		// (Editor-Palette) ersetzen → Vorschau sieht exakt wie die Seite aus.
		$mdf_content_css = plugin_dir_path( MDF_CORE_FILE ) . 'assets/mdf-content.css';
		if ( file_exists( $mdf_content_css ) ) {
			wp_enqueue_style( 'mdf-content', plugin_dir_url( MDF_CORE_FILE ) . 'assets/mdf-content.css', array( 'mdf-editor' ), (string) @filemtime( $mdf_content_css ) );
		}

		wp_enqueue_script( 'mdf-codemirror', $base . 'codemirror.min.js', array(), $c, true );
		wp_enqueue_script( 'mdf-mode-xml',    $base . 'mode_xml_xml.min.js', array( 'mdf-codemirror' ), $c, true );
		wp_enqueue_script( 'mdf-mode-md',     $base . 'mode_markdown_markdown.min.js', array( 'mdf-codemirror' ), $c, true );
		wp_enqueue_script( 'mdf-mode-overlay', $base . 'mode_overlay.min.js', array( 'mdf-codemirror' ), $c, true );
		wp_enqueue_script( 'mdf-mode-gfm',    $base . 'mode_gfm_gfm.min.js', array( 'mdf-mode-overlay' ), $c, true );
		wp_enqueue_script( 'mdf-editor',     $base . 'mdf-editor.js', array( 'mdf-codemirror', 'mdf-mode-gfm' ), $p, true );

		wp_localize_script(
			'mdf-editor',
			'MDF',
			array(
				'previewUrl' => admin_url( 'admin-post.php?action=mdf_preview' ),
				'nonce'      => wp_create_nonce( 'mdf_preview' ),
				'strings'    => array(
							'edit'      => __( 'Markup', 'md-first-core' ),
							'preview'   => __( 'Preview', 'md-first-core' ),
							'loading'   => __( 'Loading preview …', 'md-first-core' ),
							'error'     => __( 'The preview could not be loaded.', 'md-first-core' ),
							'empty'     => __( 'No content yet — write in the Markup tab, then open the preview.', 'md-first-core' ),
							// Toolbar (Quicktags-Buttons + undo/redo) — Tooltips.
							'undo'      => __( 'Undo (Ctrl+Z)', 'md-first-core' ),
							'redo'      => __( 'Redo (Ctrl+Shift+Z)', 'md-first-core' ),
							'bold'      => __( 'Bold (**text**)', 'md-first-core' ),
							'italic'    => __( 'Italic (*text*)', 'md-first-core' ),
							'strike'    => __( 'Strikethrough (~~text~~)', 'md-first-core' ),
							'link'      => __( 'Link — "Insert/edit link" dialog (standard-WP) → [text](URL)', 'md-first-core' ),
							'image'     => __( 'Image — original prompt (src, alt) → ![alt](src)', 'md-first-core' ),
							'ul'        => __( 'Bulleted list (- item)', 'md-first-core' ),
							'ol'        => __( 'Numbered list (1. item)', 'md-first-core' ),
							'quote'     => __( 'Blockquote (> text)', 'md-first-core' ),
							'code'      => __( 'Code (`inline` / ```block```)', 'md-first-core' ),
							'footnote'  => __( 'Footnote — selected text becomes [^N]; definition added at the end of the post', 'md-first-core' ),
						),
			)
		);
	}

	/**
	 * Vorschau: Markdown → HTML über die bestehende Renderer-Pipeline.
	 * Leseonly; schreibt nichts. Fehler → JSON-Error (kein wp_die).
	 *
	 * @return void
	 */
	public static function handle_preview(): void {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! hash_equals( wp_create_nonce( 'mdf_preview' ), $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'Session invalid, please reload the page.', 'md-first-core' ) ), 403 );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'md-first-core' ) ), 403 );
		}
		$md   = isset( $_POST['markdown'] ) ? wp_unslash( $_POST['markdown'] ) : '';
		$md   = is_string( $md ) ? $md : '';
		$size = function_exists( 'mb_strlen' ) ? mb_strlen( $md ) : strlen( $md );
		if ( $size > 300000 ) {
			wp_send_json_error( array( 'message' => __( 'Content too large for the preview.', 'md-first-core' ) ), 413 );
		}
		if ( ! class_exists( 'Mdf_Renderer' ) ) {
			wp_send_json_error( array( 'message' => __( 'Renderer not available.', 'md-first-core' ) ), 500 );
		}
		try {
			$html = Mdf_Renderer::render( $md );
		} catch ( \Throwable $e ) {  // Fallback-Vertrag des Renderers hält, defensiv trotzdem.
			wp_send_json_error( array( 'message' => __( 'Preview rendering failed.', 'md-first-core' ) ), 500 );
		}
		wp_send_json_success( array( 'html' => $html ) );
	}
}

MDF_Admin::register();
