<?php
/**
 * MD First Core — Agenten-Endpunkte.
 *
 * Routen:
 *   /<slug>.md       YAML-Frontmatter + rohe post_content  (Byte-identisch zur DB)
 *   /llms.txt        Site-Übersicht für LLMs
 *   /llms-full.txt   dito, mit vollem Markdown je Seite/Post
 *
 * Content-Negotiation:  Accept: text/markdown (Q-Wert-Parsing; konventionell, nicht IANA).
 * HTTP: ETag + If-None-Match → 304; nocache_headers() gegen veraltete Zwischen-Caches.
 *
 * @package MD_First_Core
 */

defined( 'ABSPATH' ) || exit;

final class Mdf_Endpoints {

	// ── Rewrites ────────────────────────────────────────────────────────────

	public static function add_rewrite_rules(): void {
		if ( empty( mdf_config()['md_endpoints'] ) ) {
			return;
		}
		add_rewrite_rule( '([a-z0-9_\-]+)\.md/?$', 'index.php?mdf_md=1&mdf_slug=$matches[1]', 'top' );
	}

	public static function add_llms_rules(): void {
		if ( empty( mdf_config()['llms'] ) ) {
			return;
		}
		add_rewrite_rule( '^llms-full\.txt/?$', 'index.php?mdf_llms=full', 'top' );
		add_rewrite_rule( '^llms\.txt/?$', 'index.php?mdf_llms=index', 'top' );
	}

	public static function register_query_vars( array $vars ): array {
		$vars[] = 'mdf_md';
		$vars[] = 'mdf_slug';
		$vars[] = 'mdf_llms';
		return $vars;
	}

	public static function init(): void {
		// Regeln DIREKT registrieren (nicht als verschachtelte init-Callback — die läuft in der
		// aktuellen init-Runde nie, und jeder flush_rewrite_rules() ohne sie (CLI 'wp rewrite',
		// Permalinks-Speichern im Admin) würde die Rules aus der Option löschen; 2026-11-23).
		self::add_rewrite_rules();
		self::add_llms_rules();
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 5 );
	}

	public static function activate(): void {
		self::add_rewrite_rules();
		self::add_llms_rules();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		global $wp_rewrite;
		if ( isset( $wp_rewrite->rules ) && is_array( $wp_rewrite->rules ) ) {
			foreach ( array_keys( $wp_rewrite->rules ) as $key ) {
				if ( false !== strpos( (string) $wp_rewrite->rules[ $key ], 'mdf_slug' )
					|| 0 === strpos( $key, '^llms' )
					|| false !== strpos( $key, '\.md' ) ) {
					unset( $wp_rewrite->rules[ $key ] );
				}
			}
		}
		flush_rewrite_rules();
	}

	// ── Dispatch ────────────────────────────────────────────────────────────

	public static function maybe_serve(): void {
		if ( is_admin() || wp_doing_ajax() || is_embed() ) {
			return;
		}
		$llms = (string) get_query_var( 'mdf_llms' );
		if ( in_array( $llms, array( 'index', 'full' ), true ) ) {
			if ( empty( mdf_config()['llms'] ) ) {
				self::not_found();
			}
			self::serve_llms( 'full' === $llms );
			return;
		}
		if ( '' !== (string) get_query_var( 'mdf_slug' ) ) {
			if ( empty( mdf_config()['md_endpoints'] ) ) {
				self::not_found();
			}
			self::find_by_slug( (string) get_query_var( 'mdf_slug' ) );
			return;
		}
		if ( ! empty( mdf_config()['md_endpoints'] )
			&& ( is_singular( 'post' ) || is_page() )
			&& ! is_feed()
			&& self::wants_markdown() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				self::serve_post_md( $post );
			}
		}
	}

	/**
	 * Sichtbarkeit: publish + post/page öffentlich (Passwortschutz → 404);
	 * alle anderen Status nur `edit_post`-berechtigten.
	 */
	public static function can_view( WP_Post $post ): bool {
		if ( 'publish' === $post->post_status && in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return ! post_password_required( $post );
		}
		return current_user_can( 'edit_post', $post->ID );
	}

	public static function find_by_slug( string $slug ): void {
		$slug = sanitize_title( $slug );
		$q    = new WP_Query(
			array(
				'post_type'         => array( 'post', 'page' ),
				'post_status'       => array( 'publish', 'draft', 'pending', 'future' ),
				'name'              => $slug,
				'posts_per_page'    => 1,
				'no_found_rows'     => true,
				'ignore_sticky_posts' => true, // phpcs:ignore
			)
		);
		$post = $q->have_posts() ? $q->get_posts()[0] : null;
		if ( ! $post instanceof WP_Post && is_numeric( $slug ) ) {
			// Fallback: numerische ID (z.B. Slugs ohne post_name).
			$candidate = get_post( (int) $slug );
			if ( $candidate instanceof WP_Post && in_array( $candidate->post_type, array( 'post', 'page' ), true ) ) {
				$post = $candidate;
			}
		}
		if ( ! $post instanceof WP_Post || ! self::can_view( $post ) ) {
			self::not_found();
		}
		self::serve_post_md( $post );
	}

	// ── MD-Auslieferung ─────────────────────────────────────────────────────

	/**
	 * MD-Dokument: YAML-Frontmatter + rohe post_content (BYTE-IDENTISCH).
	 */
	public static function md_document( WP_Post $post ): string {
		$fm = array(
			'title'     => get_the_title( $post ),
			'type'      => $post->post_type,
			'id'        => (int) $post->ID,
			'url'       => get_permalink( $post ),
			'date'      => mysql2date( 'Y-m-d', $post->post_date_gmt ? $post->post_date_gmt : $post->post_date ),
			'modified'  => mysql2date( 'c', $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified ),
			'status'    => $post->post_status,
			'terms'     => array_values( (array) wp_get_post_terms( $post->ID, array( 'category', 'post_tag' ), array( 'fields' => 'names' ) ) ),
			'generator' => 'md-first-core/' . MDF_CORE_VERSION,
		);
		return "---\n" . self::yaml( $fm ) . "---\n\n" . rtrim( (string) $post->post_content ) . "\n";
	}

	public static function serve_post_md( WP_Post $post ): void {
		if ( ! self::can_view( $post ) ) {
			self::not_found();
		}
		$body    = self::md_document( $post );
		$etag    = '"' . md5( $body ) . '"';
		$lastmod = gmdate( 'D, d M Y H:i:s', strtotime( $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified ) ) . ' GMT';
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'ETag: ' . $etag );
		header( 'Last-Modified: ' . $lastmod );
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) === $etag ) {
			status_header( 304 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore
		exit;
	}

	// ── Accept: text/markdown (Konvention, nicht IANA — in manifest/llms.txt dokumentiert) ──

	public static function wants_markdown(): bool {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) ) : '';
		if ( '' === $accept ) {
			return false;
		}
		$entries = array();
		foreach ( explode( ',', $accept ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$bits = array_map( 'trim', explode( ';', $part ) );
			$type = strtolower( $bits[0] );
			$q    = 1.0;
			foreach ( array_slice( $bits, 1 ) as $kv ) {
				if ( 0 === stripos( $kv, 'q=' ) ) {
					$q = (float) substr( $kv, 2 );
				}
			}
			$entries[] = array( $type, $q );
		}
		usort( $entries, static fn( $a, $b ) => $b[1] <=> $a[1] );
		$markdown   = false;
		$html_types = array();
		foreach ( $entries as $e ) {
			if ( in_array( $e[0], array( '*/*', 'text/*' ), true ) ) {
				continue;
			}
			if ( 'text/markdown' === $e[0] ) {
				if ( ! $markdown && $e[1] > 0 ) {
					$markdown = true;
				}
				continue;
			}
			if ( 'text/html' === $e[0] ) {
				$html_types[] = $e;
			}
		}
		if ( ! $markdown ) {
			return false;
		}
		// text/html mit strikt höherem Q → HTML gewinnen (konservativ).
		$md_q    = 0.0;
		foreach ( $entries as $e ) {
			if ( 'text/markdown' === $e[0] && $e[1] > $md_q ) {
				$md_q = $e[1];
			}
		}
		foreach ( $html_types as $h ) {
			if ( $h[1] > $md_q ) {
				return false;
			}
		}
		return true;
	}

	// ── llms.txt / llms-full.txt ────────────────────────────────────────────

	public static function serve_llms( bool $full ): void {
		$cache_key = 'mdf_llms_' . ( $full ? 'full' : 'head' );
		$body      = get_transient( $cache_key );
		if ( ! is_string( $body ) || '' === $body ) {
			$body = self::llms_body( $full );
			set_transient( $cache_key, $body, 300 );
		}
		$etag = '"' . md5( $body ) . '"';
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'ETag: ' . $etag );
		if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && trim( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) === $etag ) {
			status_header( 304 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Length: ' . strlen( $body ) );
		echo $body; // phpcs:ignore
		exit;
	}

	public static function llms_body( bool $full ): string {
		$site = get_bloginfo( 'name' );
		$desc = apply_filters( 'mdf_llms_about', get_bloginfo( 'description' ) );
		if ( '' === (string) $desc ) {
			$desc = 'Markdown-First WordPress-Instanz: Inhalte sind Markdown (Single Source of Truth), gerendert zu HTML; für Agenten via MCP/Abilities und HTTP.';
		}
		$base = untrailingslashit( home_url() );

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'order'          => 'ASC',
			)
		);
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
			)
		);

		$out   = "# {$site}\n\n> " . trim( (string) $desc ) . "\n\n";
		$out  .= "## About\n" . trim( (string) apply_filters( 'mdf_llms_about_long', '' ) ) . "\n\n";
		if ( '' === trim( (string) apply_filters( 'mdf_llms_about_long', '' ) ) ) {
			$out = str_replace( "## About\n\n\n", "## About\n", $out );
		}

		$out .= "## Pages\n";
		if ( $pages ) {
			foreach ( $pages as $p ) {
				$out .= sprintf( "- [%s](%s) — %s\n", esc_html( get_the_title( $p ) ), esc_url( get_permalink( $p ) ), esc_html( wp_strip_all_tags( (string) wp_trim_words( (string) $p->post_content, 12 ) ) ) );
				if ( $full ) {
					$out .= "\n" . self::md_document( $p ) . "\n";
				}
			}
		} else {
			$out .= "(keine Seiten)\n";
		}
		$out .= "\n## Posts (neu → alt)\n";
		if ( $posts ) {
			foreach ( $posts as $p ) {
				$out .= sprintf(
					"- [%s](%s) (%s) — %s\n  Markdown: %s\n",
					esc_html( get_the_title( $p ) ),
					esc_url( get_permalink( $p ) ),
					esc_html( mysql2date( 'Y-m-d', $p->post_date ) ),
					esc_html( wp_strip_all_tags( (string) wp_trim_words( (string) $p->post_content, 12 ) ) ),
					esc_url( $base . '/' . ( $p->post_name ? $p->post_name : $p->ID ) . '.md' )
				);
				if ( $full ) {
					$out .= "\n" . self::md_document( $p ) . "\n";
				}
			}
		} else {
			$out .= "(keine Posts)\n";
		}

		$out .= "\n## Machine-Readable Endpoints\n"
			. "- llms.txt:  this file\n"
			. "- llms-full.txt:  full site content, Markdown\n"
			. "- /<slug>.md:  single post/page as Markdown (Frontmatter + rohes post_content)\n"
			. "- MCP:  {$base}/wp-json/mcp/mcp-adapter-default-server\n"
			. "- Accept:  'text/markdown' auf Post-/Seiten-URLs → Markdown statt HTML\n";
		return $out;
	}

	// ── Util ────────────────────────────────────────────────────────────────

	public static function not_found(): void {
		nocache_headers();
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo "404 — nicht gefunden. (Markdown-Endpunkte: /<slug>.md, /llms.txt)\n";
		exit;
	}

	public static function yaml( array $data, int $indent = 0 ): string {
		$pad  = str_repeat( '  ', $indent );
		$lines = array();
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$lines[] = $pad . $k . ': [';
				$items    = array();
				foreach ( $v as $item ) {
					$items[] = '"' . str_replace( '"', '\\"', (string) $item ) . '"';
				}
				$lines[] = $pad . '  ' . implode( ', ', $items ) . ']';
				unset( $v );
			} else {
				$str = (string) $v;
				// YAML-Skalare: Strings mit Sonderzeichen anfühlen.
				if ( preg_match( '/[:#\[\]{}&*!|>\'"]|^ | $/', $str ) ) {
					$str = '"' . str_replace( '"', '\\"', $str ) . '"';
				}
				$lines[] = $pad . $k . ': ' . $str;
			}
		}
		return implode( "\n", $lines ) . "\n";
	}
}
