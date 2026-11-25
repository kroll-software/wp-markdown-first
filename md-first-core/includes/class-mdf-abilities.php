<?php
/**
 * MD First Core — Abilities (wp-abilities/v1, Namespace mdf/<slug>).
 *
 * Kanonische Oberfläche (ersetzt den mu-Plugin-Dev-Katalog):
 *   mdf/manifest               read,     Site/Schema/Endpunkte
 *   mdf/list-posts-markdown    read/edit Posts+Seiten listen (Filter, Pagination)
 *   mdf/get-post-markdown      read/edit Post inkl. rohem Markdown + Metadaten
 *   mdf/create-post-markdown   edit      Anlegen (KSES-Isoliert + Post-Write-Verification)
 *   mdf/update-post-markdown   edit      Gezieltes Aktualisieren (Markdown/Titel/Status)
 *   mdf/delete-post-markdown   edit      Trash-Default, optionales force
 *   mdf/render-markdown        edit      Vorschau: Markdown→HTML (Pipeline, ohne Post)
 *   mdf/upload-media           upload    Base64-Upload → Attachment + URL + Markdown-Snippet
 *
 * Namensregel: genau ein Slash, zwei Segmente `[a-z0-9-]+/[a-z0-9-]+`.
 * Hook-Ordnung: Kategorie auf wp_abilities_api_categories_init, Abilities auf
 * wp_abilities_api_init (bewiesen: falsche Reihenfolge = stille Drop).
 *
 * @package MD_First_Core
 */

defined( 'ABSPATH' ) || exit;

final class Mdf_Abilities {

	const STATUSES    = array( 'publish', 'draft', 'pending', 'future' );
	const POST_TYPES  = array( 'post', 'page' );
	const META_PREFIX = 'mdf_'; // erlaubte Metadaten-Schlüssel beim create/update

	// ── Registration ────────────────────────────────────────────────────────

	public static function register_category(): void {
		// Kein doppeltes Registrieren, falls der mu-Plugin-Fallback bereits lief (er checkt MDF_CORE_VERSION).
		wp_register_ability_category(
			'mdf',
			array(
				'label'       => 'Markdown First',
				'description' => 'Markdown-First companion: post_content as the single source of truth, .md / llms.txt endpoints, mdf/* abilities.',
			)
		);
	}

	public static function register_abilities(): void {
		$public = function ( array $annotations ) {
			return array(
				'public'      => true,
				'annotations' => $annotations,
			);
		};
		$read_only  = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
		$write_post = array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
		$destructive = array( 'readonly' => false, 'destructive' => true, 'idempotent' => true );

		// 1) manifest ──────────────────────────────────────────────────────
		wp_register_ability(
			'mdf/manifest',
			array(
				'label'               => 'MDF Manifest',
				'description'         => 'Schema version, site info, WordPress/plugin versions, all Markdown endpoints and the mdf/* abilities. Read-only, no login required.',
				'category'            => 'mdf',
				'execute_callback'    => array( __CLASS__, 'manifest' ),
				'permission_callback' => array( __CLASS__, 'perm_read' ),
				'meta'                => $public( $read_only ),
				'output_schema'       => array( 'type' => 'object' ),
			)
		);

		// 2) list ───────────────────────────────────────────────────────────
		wp_register_ability(
			'mdf/list-posts-markdown',
			array(
				'label'               => 'List posts as Markdown',
				'description'         => 'Posts/pages as a list (ID, title, status, date, URL, .md-URL, excerpt). Filters: post_type (post|page), status (publish|draft|pending|future), search (title), page, per_page. Raw content via mdf/get-post-markdown.',
				'category'            => 'mdf',
				'execute_callback'    => array( __CLASS__, 'run_list' ),
				'permission_callback' => array( __CLASS__, 'perm_list' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array( 'type' => 'string', 'enum' => self::POST_TYPES, 'default' => 'post' ),
						'status'    => array( 'type' => 'string', 'enum' => self::STATUSES, 'default' => 'publish' ),
						'search'    => array( 'type' => 'string', 'description' => 'Substring search in the title.' ),
						'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
						'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
					),
				),
				'meta'                => $public( $read_only ),
				'output_schema'       => array( 'type' => 'object' ),
			)
		);

		// 3) get ────────────────────────────────────────────────────────────
		wp_register_ability(
			'mdf/get-post-markdown',
			array(
				'label'               => 'Get post as Markdown',
				'description'         => 'Fetch a single post: raw Markdown (post_content = single source of truth), metadata, URL, .md-URL, ETag. By id or slug (slug: include post_type on collisions).',
				'category'            => 'mdf',
				'execute_callback'    => array( __CLASS__, 'run_get' ),
				'permission_callback' => array( __CLASS__, 'perm_get' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer', 'description' => 'Post ID — canonical identifier, accepted by every mdf/* ability (get, update, delete).' ),
						'id'        => array( 'type' => 'integer', 'description' => 'Deprecated alias for post_id (accepted by get only, during the v1.3.x deprecation cycle; use post_id).' ),
						'slug'      => array( 'type' => 'string' ),
						'post_type' => array( 'type' => 'string', 'enum' => self::POST_TYPES ),
					),
				),
				'meta'                => $public( $read_only ),
				'output_schema'       => array( 'type' => 'object' ),
			)
		);

		// 4) create ─────────────────────────────────────────────────────────
		wp_register_ability(
			'mdf/create-post-markdown',
			array(
				'label'               => 'Create post from Markdown',
				'description'         => 'Create a new post/page: title + content (Markdown). The Markdown is stored BYTE-IDENTICAL as post_content (no KSES, no block conversion — KSES isolation + post-write verification). status: draft (default), publish, pending. Optional slug. meta object with mdf_ keys.',
				'category'            => 'mdf',
				'execute_callback'    => array( __CLASS__, 'run_create' ),
				'permission_callback' => array( __CLASS__, 'perm_edit_posts' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array( 'type' => 'string' ),
						'content'   => array( 'type' => 'string', 'description' => 'Raw Markdown.' ),
						'markdown'  => array( 'type' => 'string', 'description' => 'Alias for content.' ),
						'post_type' => array( 'type' => 'string', 'enum' => self::POST_TYPES, 'default' => 'post' ),
						'status'    => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending' ), 'default' => 'draft' ),
						'slug'      => array( 'type' => 'string' ),
						'meta'      => array( 'type' => 'object', 'description' => 'Optional metadata, keys with the mdf_ prefix.' ),
					),
					'required'   => array( 'title', 'content' ),
				),
				'meta'                => $public( $write_post ),
				'output_schema'       => array( 'type' => 'object' ),
			)
		);

		// 5) update ─────────────────────────────────────────────────────────
		wp_register_ability(
			'mdf/update-post-markdown',
			array(
				'label'               => 'Update post Markdown',
				'description'         => 'Targeted update of a post: post_id + at least one of content (Markdown, stored BYTE-IDENTICAL), title, status. Response includes a new ETag (md5 of post_content) for change tracking. Supports optimistic locking via optional if_etag (409 mdf_conflict on mismatch, v1.3.28).',
				'category'            => 'mdf',
				'execute_callback'    => array( __CLASS__, 'run_update' ),
				'permission_callback' => array( __CLASS__, 'perm_update' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer', 'description' => 'ID of the target post — canonical identifier, accepted by every mdf/* ability. (update/delete accept exactly this name; the deprecated id alias exists only on get.)' ),
						'content'   => array( 'type' => 'string' ),
						'markdown'  => array( 'type' => 'string' ),
						'title'     => array( 'type' => 'string' ),
						'status'    => array( 'type' => 'string', 'enum' => self::STATUSES ),
						'meta'      => array( 'type' => 'object' ),
						'if_etag'   => array( 'type' => 'string', 'description' => 'Optional optimistic lock (v1.3.28): the etag (md5 of post_content) from your last mdf/* response for this post. If the post has changed in the meantime, the update is rejected with 409 mdf_conflict (nothing is written). Omit to skip the check (backward compatible).' ),
					),
					'required'   => array( 'post_id' ),
				),
				'meta'                => $public( $write_post ),
				'output_schema'       => array( 'type' => 'object' ),
			)
		);

		// 6) delete ─────────────────────────────────────────────────────────
		wp_register_ability(
			'mdf/delete-post-markdown',
			array(
				'label'               => 'Delete post (Trash by default)',
				'description'         => 'Delete a post. Default: move to the trash (reversible). force=true = permanent (additionally requires the delete_post permission).',
				'category'            => 'mdf',
				'execute_callback'    => array( __CLASS__, 'run_delete' ),
				'permission_callback' => array( __CLASS__, 'perm_delete' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'description' => 'ID of the target post — canonical identifier, accepted by every mdf/* ability. (delete accepts exactly this name; the deprecated id alias exists only on get.)' ),
						'force'   => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'post_id' ),
				),
				'meta'                => $public( $destructive ),
				'output_schema'       => array( 'type' => 'object' ),
				)
				);

		// 7) render (Vorschau: MD→HTML, nur-lesen) ───────────
		wp_register_ability(
			'mdf/render-markdown',
			array(
				'label'               => 'Render Markdown to HTML',
				'description'         => 'Render arbitrary Markdown through the full pipeline (ParsedownExtra, LaTeX→MathML, KSES) into sanitized HTML — the same pipeline the editor preview uses. Read-only; creates no post. Use to check formatting before mdf/create-post-markdown or mdf/update-post-markdown.',
				'category'            => 'mdf',
				'execute_callback'    => array( __CLASS__, 'run_render_markdown' ),
				'permission_callback' => array( __CLASS__, 'perm_edit_posts' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'content'  => array( 'type' => 'string', 'description' => 'Markdown source to render (max 300 kB).' ),
						'markdown' => array( 'type' => 'string', 'description' => 'Alias for content.' ),
					),
				),
				'meta'                => $public( $read_only ),
				'output_schema'       => array( 'type' => 'object' ),
			)
		);

		// 8) upload-media (Media-Upload-Service für Agenten, v1.3.29) ───────
		wp_register_ability(
			'mdf/upload-media',
			array(
				'label'               => 'Upload media file',
				'description'         => 'Upload a binary file (images/PDFs/…) into the WP media library and get back the public URL plus a paste-ready Markdown snippet (image → ![alt](url), other → [filename](url)). Input is base64 (data), max 5 MB decoded. Optional alt_text and post_id (attach as child of that post). Use to embed media into posts.',
				'category'            => 'mdf',
				'execute_callback'    => array( __CLASS__, 'run_upload_media' ),
				'permission_callback' => array( __CLASS__, 'perm_upload_files' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'data'     => array( 'type' => 'string', 'description' => 'Base64-encoded file content (max 5 MB decoded).' ),
						'filename' => array( 'type' => 'string', 'description' => 'Optional file name (basename, e.g. chart.png).' ),
						'alt_text' => array( 'type' => 'string', 'description' => 'Optional alt text for images.' ),
						'post_id'  => array( 'type' => 'integer', 'description' => 'Optional post to attach the file to (child attachment).' ),
					),
					'required'   => array( 'data' ),
				),
				'meta'                => $public( $write_post ),
				'output_schema'       => array( 'type' => 'object' ),
			)
		);
	}

	// ── Utilities ───────────────────────────────────────────────────────────

	/**
	 * Parameter-Extraktion (robust für Objekt- oder Array-Requests).
	 *
	 * @param mixed $input Request/Aufgabeneingang.
	 * @return array<string, mixed>
	 */
	public static function params( $input ): array {
		if ( is_array( $input ) ) {
			return $input;
		}
		if ( is_object( $input ) ) {
			if ( method_exists( $input, 'get_params' ) ) {
				return (array) $input->get_params();
			}
			return (array) $input;
		}
		return array();
	}

	public static function err( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * KSES auf dem Speicherpfad gezielt deaktivieren (Reversibel innerhalb desselben Calls).
	 * Begründung: post_content ist Markdown (SSoT); KSES würde MD-Tokens beschädigen.
	 * Output-Sanitation erfolgt beim Rendering (Mdf_Renderer::render). Akzeptiert in Abschnitt 9
	 * des Konzepts; die Rolle `edit_posts` ist das Zugriffs-Kriterium.
	 *
	 * @param callable $fn Zu ausführender Schreib-Vorgang.
	 * @return mixed
	 */
	public static function without_kses( callable $fn ) {
		remove_filter( 'wp_filter_post_kses', 'wp_filter_post_kses' );
		try {
			return $fn();
		} finally {
			add_filter( 'wp_filter_post_kses', 'wp_filter_post_kses' );
		}
	}

	/**
	 * Metadaten: nur mdf_-Schlüssel, skalare Werte.
	 *
	 * @param int   $post_id Post-ID.
	 * @param array $meta    Meta-Objekt.
	 */
	public static function apply_meta( int $post_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			if ( ! is_string( $key ) || 0 !== strpos( $key, self::META_PREFIX ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				$value = (string) json_encode( $value );
			}
			update_post_meta( $post_id, '_' . $key, (string) $value );
		}
	}

	/**
	 * Meta-Objekt zurückgeben (mdf_-Schlüssel + Taxonomie-Namen).
	 *
	 * @param int $post_id Post-ID.
	 * @return array{custom: array<string, string>, terms: string[]}
	 */
	public static function read_meta( int $post_id ): array {
		$custom = array();
		foreach ( get_post_meta( $post_id ) as $key => $value ) { // phpcs:ignore
			if ( 0 === strpos( $key, '_' . self::META_PREFIX ) && is_string( $value ) ) {
				$custom[ substr( $key, 1 ) ] = $value; // phpcs:ignore
			}
		}
		return array(
			'custom' => $custom,
			'terms'  => array_values( (array) wp_get_post_terms( $post_id, array( 'category', 'post_tag' ), array( 'fields' => 'names' ) ) ),
		);
	}

	// ── Permissions ─────────────────────────────────────────────────────────

	public static function perm_read( $input = null ): bool {
		return current_user_can( 'read' );
	}

	public static function perm_list( $input = null ): bool {
		$in     = self::params( $input );
		$status = (string) ( $in['status'] ?? 'publish' );
		return 'publish' === $status ? current_user_can( 'read' ) : current_user_can( 'edit_posts' );
	}

	public static function perm_get( $input = null ): bool {
		$in = self::params( $input );
		if ( ! empty( $in['id'] ) || ! empty( $in['post_id'] ) ) {
			$id   = (int) ( $in['id'] ?? $in['post_id'] );
			$post = get_post( $id );
			if ( $post && 'publish' === $post->post_status ) {
				return current_user_can( 'read' );
			}
			return current_user_can( 'edit_post', $id );
		}
		return current_user_can( 'edit_posts' ); // slug-Pfad ohne publish-Info: konservativ
	}

	public static function perm_edit_posts( $input = null ): bool {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Capability-Gate für mdf/upload-media.
	 *
	 * @param mixed $input Request (nuttlich, aber ignoriert).
	 * @return bool Erlaubt?
	 */
	public static function perm_upload_files( $input = null ): bool {
		return current_user_can( 'upload_files' );
	}

	public static function perm_update( $input = null ): bool {
		$in = self::params( $input );
		$id = (int) ( $in['post_id'] ?? 0 );
		return $id > 0 && current_user_can( 'edit_post', $id );
	}

	public static function perm_delete( $input = null ): bool {
		$in = self::params( $input );
		$id = (int) ( $in['post_id'] ?? 0 );
		if ( ! $id ) {
			return false;
		}
		if ( ! empty( $in['force'] ) ) {
			return current_user_can( 'delete_post', $id );
		}
		return current_user_can( 'edit_post', $id );
	}

	// ── Handlers ────────────────────────────────────────────────────────────

	public static function manifest( $input = null ): array {
		$adapter = get_plugin_data( WP_CONTENT_DIR . '/plugins/mcp-adapter/mcp-adapter.php', false, false );
		return array(
			'schema'      => MDF_CORE_SCHEMA,
			'site'        => array(
				'title'       => get_bloginfo( 'name' ),
				'url'         => home_url( '/' ),
				'description' => get_bloginfo( 'description' ),
			),
			'wordpress'   => $GLOBALS['wp_version'] ?? 'unknown',
			'abilities_api' => 'wp-abilities/v1',
			'plugin'      => array( 'name' => 'md-first-core', 'version' => MDF_CORE_VERSION ),
			'mcp_adapter' => array(
				'version'   => $adapter['Version'] ?? 'not installed',
				'endpoint'  => untrailingslashit( home_url() ) . '/wp-json/mcp/mcp-adapter-default-server',
			),
			'endpoints'   => array(
				'markdown_single'    => home_url( '/<slug>.md' ),
				'llms_txt'           => home_url( '/llms.txt' ),
				'llms_full_txt'      => home_url( '/llms-full.txt' ),
				'accept_negotiation' => 'text/markdown',
			),
			'abilities'   => array(
				'mdf/manifest',
				'mdf/list-posts-markdown',
				'mdf/get-post-markdown',
				'mdf/create-post-markdown',
				'mdf/update-post-markdown',
				'mdf/delete-post-markdown',
				'mdf/render-markdown',
				'mdf/upload-media',
			),
			'conventions' => array(
				'content_format'    => 'text/markdown (source of truth)',
				'id_parameter'      => 'post_id — canonical identifier for every mdf/* ability (get, update, delete). The alias id is accepted by get only and is deprecated since v1.3.25 (one deprecation cycle during v1.3.x, then strict). Always use post_id.',
				'alias_pairs'       => array(
					'mdf/get-post-markdown'    => array( 'canonical' => 'post_id', 'alias' => 'id', 'alias_status' => 'deprecated since v1.3.25 (one cycle, then strict)' ),
					'mdf/update-post-markdown' => array( 'canonical' => 'post_id' ),
					'mdf/delete-post-markdown' => array( 'canonical' => 'post_id' ),
				),
				'optimistic_lock'   => 'mdf/update-post-markdown akzeptiert optionales if_etag (v1.3.28): md5( post_content ) des zuletzt gelesenen Zustands (= etag-Feld jeder mdf/*-Antwort). Mismatch → 409 mdf_conflict, nichts wird geschrieben. Weggelassen → keine Prüfung (rückwärtskompatibel).',
			'media_upload'      => 'mdf/upload-media (v1.3.29): binary upload via base64 (data), max 5 MB decoded, WP-MIME-whitelist enforced. Returns attachment_id, url and a paste-ready markdown snippet (image → ![alt](url), other → [filename](url)). Optional alt_text, post_id (child attachment). Permission: upload_files.',
				'math_inline'       => '$…$',
				'math_block'        => '$$…$$',
				'math_fallback'     => '<pre class="mdf-math-fallback"> (raw LaTeX source)',
				'write_abilities'   => array( 'mdf/create-post-markdown', 'mdf/update-post-markdown', 'mdf/delete-post-markdown', 'mdf/upload-media' ),
				'roundtrip'         => 'create/update → get: content is BYTE-IDENTICAL (post-write verification)',
			),
		);
	}

	public static function run_list( $input = null ): array|WP_Error {
		$in = self::params( $input );
		$post_type = (string) ( $in['post_type'] ?? 'post' );
		$status    = (string) ( $in['status'] ?? 'publish' );
		if ( ! in_array( $post_type, self::POST_TYPES, true ) ) {
			return self::err( 'mdf_bad_param', 'post_type must be post or page.' );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return self::err( 'mdf_bad_param', 'status must be one of: publish, draft, pending, future.' );
		}
		$page     = max( 1, (int) ( $in['page'] ?? 1 ) );
		$per_page = min( (int) mdf_config()['list_max'], max( 1, (int) ( $in['per_page'] ?? 20 ) ) );
		$args     = array(
			'post_type'      => $post_type,
			'post_status'    => $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $in['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $in['search'] );
		}
		$q  = new WP_Query( $args );
		$items = array();
		foreach ( $q->get_posts() as $p ) {
			$items[] = array(
				'id'            => (int) $p->ID,
				'slug'          => $p->post_name,
				'type'          => $post_type,
				'title'         => (string) $p->post_title,
				'status'        => $p->post_status,
				'date_modified' => mysql2date( 'c', $p->post_modified ),
				'url'           => get_permalink( $p ),
				'url_md'        => mdf_md_alt_href( (int) $p->ID ),
				'excerpt'       => wp_strip_all_tags( (string) wp_trim_words( (string) $p->post_content, (int) mdf_config()['excerpt_words'] ) ),
			);
		}
		return array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $q->found_posts,
			'total_pages' => (int) $q->max_num_pages,
			'items'       => $items,
		);
	}

	public static function run_get( $input = null ): array|WP_Error {
		$in = self::params( $input );
		$id = (int) ( $in['post_id'] ?? $in['id'] ?? 0 );
		if ( $id <= 0 && ! empty( $in['slug'] ) ) {
			$args = array(
				'post_type'   => ! empty( $in['post_type'] ) ? (string) $in['post_type'] : self::POST_TYPES,
				'post_status' => self::STATUSES,
				'name'        => sanitize_title( (string) $in['slug'] ),
				'posts_per_page' => 1,
			);
			$q = new WP_Query( $args );
			if ( ! $q->have_posts() ) {
				return self::err( 'mdf_unknown_post', 'No post with slug "' . (string) $in['slug'] . '".', 404 );
			}
			$found = $q->get_posts();
			$id    = (int) $found[0]->ID;
		}
		if ( $id <= 0 ) {
			return self::err( 'mdf_missing_ref', 'Provide id (or post_id) or slug.', 400 );
		}
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return self::err( 'mdf_unknown_post', 'No post with this ID.', 404 );
		}
		if ( 'trash' === $post->post_status ) {
			return self::err( 'mdf_post_trashed', 'Post is in the trash (deleted).', 404 );
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $id ) ) {
			return self::err( 'mdf_visibility_denied', 'Post is not published and you lack edit rights.', 403 );
		}
		$meta = self::read_meta( $id );
		return array(
			'id'             => (int) $id,
			'slug'           => $post->post_name,
			'type'           => $post->post_type,
			'title'          => (string) $post->post_title,
			'status'         => $post->post_status,
			'date'           => $post->post_date,
			'date_modified'  => mysql2date( 'c', $post->post_modified ),
			'url'            => get_permalink( $post ),
			'url_md'         => mdf_md_alt_href( $id ),
			'content_format' => 'text/markdown (source of truth)',
			'content'        => (string) $post->post_content,
			'etag'           => md5( (string) $post->post_content ),
			'meta'           => $meta,
		);
	}

	public static function run_create( $input = null ): array|WP_Error {
		$in  = self::params( $input );
		$title = trim( (string) ( $in['title'] ?? '' ) );
		$md    = (string) ( $in['markdown'] ?? $in['content'] ?? '' );
		if ( '' === $title ) {
			return self::err( 'mdf_missing_fields', 'title is required.' );
		}
		if ( '' === $md ) {
			return self::err( 'mdf_missing_fields', 'content (Markdown) is required.' );
		}
		$post_type = (string) ( $in['post_type'] ?? 'post' );
		if ( ! in_array( $post_type, self::POST_TYPES, true ) ) {
			return self::err( 'mdf_bad_param', 'post_type must be post or page.' );
		}
		$status = (string) ( $in['status'] ?? 'draft' );
		if ( ! in_array( $status, array( 'publish', 'draft', 'pending' ), true ) ) {
			$status = 'draft';
		}

		if ( ! current_user_can( 'publish_posts' ) && 'publish' === $status ) {
			return self::err( 'mdf_forbidden', 'Cannot create as publish (missing publish_posts).', 403 );
		}

		$result = self::without_kses(
			static function () use ( $title, $md, $post_type, $status, $in ) {
				// wp_slash(): wp_insert_post() un-slashed das Array UNKONDITIONAL per
				// wp_unslash() (post.php) – ungeschlachte Backslashes (LaTeX!) gingen sonst verloren.
				$data = array(
					'post_title'   => wp_slash( $title ),
					'post_content' => wp_slash( $md ),
					'post_type'    => $post_type,
					'post_status'  => $status,
				);
				if ( ! empty( $in['slug'] ) ) {
					$data['post_name'] = sanitize_title( (string) $in['slug'] );
				}
				return wp_insert_post( $data, true );
			}
		);

		if ( is_wp_error( $result ) ) {
			return self::err( 'mdf_create_failed', 'wp_insert_post: ' . $result->get_error_message(), 400 );
		}
		$id = (int) $result;

		// Post-Write-Verification: gespeichertes == übergebenes Markdown.
		$saved = (string) get_post_field( 'post_content', $id );
		if ( $saved !== $md ) {
			delete_post( $id );
			return self::err( 'mdf_write_verify_failed', 'Stored content differs from input (roundtrip: slashes/KSES modified the content on write). New post deleted; please retry.', 400 );
		}

		self::apply_meta( $id, (array) ( $in['meta'] ?? array() ) );
		Mdf_Renderer::invalidate( $id );

		return array(
			'post_id'  => $id,
			'slug'     => get_post_field( 'post_name', $id ),
			'url'      => get_permalink( $id ),
			'url_md'   => mdf_md_alt_href( $id ),
			'etag'     => md5( $md ),
			'saved_as' => 'text/markdown (source of truth)',
		);
	}

	public static function run_update( $input = null ): array|WP_Error {
		$in  = self::params( $input );
		$id  = (int) ( $in['post_id'] ?? 0 );
		$post = get_post( $id );
		if ( ! $post ) {
			return self::err( 'mdf_unknown_post', 'No post with this ID.', 404 );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return self::err( 'mdf_forbidden', 'User lacks edit_post on this post.', 403 );
		}

		// Optimistic lock (v1.3.28): if_etag = md5( post_content ) der letzten
		// bekanneten ZUSTAND (etag-Feld der mdf/get- bzw. -create-Antwort).
		// Mismatch = jemand hat zwischenzeitlich geschrieben → 409, NICHTS
		// wird verändert. Weggelassen = keine Prüfung (rückwärtskompatibel).
		$if_etag = isset( $in['if_etag'] ) ? trim( (string) $in['if_etag'] ) : '';
		if ( '' !== $if_etag && ! hash_equals( md5( (string) $post->post_content ), $if_etag ) ) {
			return self::err( 'mdf_conflict', 'Post changed since your last read (etag mismatch). Re-fetch via mdf/get-post-markdown, then retry.', 409 );
		}

		$data = array( 'ID' => $id );
		// wp_slash(): wp_update_post() re-slasht NUR Objects (post.php:4967) – Arrays
		// gehen durch ungeschütztes wp_unslash(), Backslashes (LaTeX!) gingen verloren.
		if ( ( $in['markdown'] ?? null ) !== null ) {
			$data['post_content'] = wp_slash( (string) $in['markdown'] );
		} elseif ( ( $in['content'] ?? null ) !== null ) {
			$data['post_content'] = wp_slash( (string) $in['content'] );
		}
		if ( ( $in['title'] ?? null ) !== null ) {
			$data['post_title'] = wp_slash( (string) $in['title'] );
		}
		if ( ( $in['status'] ?? null ) !== null && in_array( (string) $in['status'], self::STATUSES, true ) ) {
			$data['post_status'] = (string) $in['status'];
		}

		if ( count( $data ) === 1 ) {
			return self::err( 'mdf_missing_fields', 'Provide at least one of: content (Markdown), title, status.' );
		}

		// Vergleich gegen das UNGESLACHETE Input-Markdown: WP speichert ent-slashet,
		// $data['post_content'] ist jedoch per wp_slash() oben verslashet (2x Backslash).
		$expect_md = isset( $data['post_content'] ) ? wp_unslash( (string) $data['post_content'] ) : null;
		$result    = self::without_kses(
			static function () use ( $data ) {
				return wp_update_post( $data, true );
			}
		);
		if ( is_wp_error( $result ) ) {
			return self::err( 'mdf_update_failed', 'wp_update_post: ' . $result->get_error_message(), 400 );
		}

		if ( null !== $expect_md ) {
			$saved = (string) get_post_field( 'post_content', $id );
			if ( $saved !== $expect_md ) {
				return self::err( 'mdf_write_verify_failed', 'Stored content differs from input (roundtrip: slashes/KSES modified the content on write).' );
			}
		}

		if ( ! empty( $in['meta'] ) && is_array( $in['meta'] ) ) {
			self::apply_meta( $id, $in['meta'] );
		}
		Mdf_Renderer::invalidate( $id );
		$updated = get_post( $id );

		return array(
			'post_id'  => (int) $id,
			'slug'     => $updated->post_name,
			'url'      => get_permalink( $id ),
			'url_md'   => mdf_md_alt_href( $id ),
			'etag'     => md5( (string) $updated->post_content ),
			'saved_as' => isset( $data['post_content'] ) ? 'text/markdown (source of truth)' : 'unchanged',
		);
	}

	public static function run_delete( $input = null ): array|WP_Error {
		$in  = self::params( $input );
		$id  = (int) ( $in['post_id'] ?? 0 );
		$force = ! empty( $in['force'] );
		if ( $id <= 0 ) {
			return self::err( 'mdf_missing_ref', 'post_id is required.' );
		}
		$post = get_post( $id );
		if ( ! $post ) {
			return self::err( 'mdf_unknown_post', 'No post with this ID.', 404 );
		}
		if ( $force ) {
			if ( ! current_user_can( 'delete_post', $id ) ) {
				return self::err( 'mdf_forbidden', 'User lacks delete_post on this post.', 403 );
			}
			$res = wp_delete_post( $id, true );
			if ( ! $res ) {
				return self::err( 'mdf_delete_failed', 'wp_delete_post(force) failed.' );
			}
		} else {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				return self::err( 'mdf_forbidden', 'User lacks edit_post on this post.', 403 );
			}
			$res = wp_trash_post( $id );
			if ( ! $res ) {
				return self::err( 'mdf_delete_failed', 'wp_trash_post failed (already trashed?).' );
			}
		}
		Mdf_Renderer::invalidate( $id );
		return array(
			'post_id'    => $id,
			'deleted_as' => $force ? 'force-delete' : 'trash',
			'reversible' => ! $force,
		);
	}

	public static function run_render_markdown( $input = null ): array|WP_Error {
		$in = self::params( $input );
		$md = (string) ( $in['content'] ?? $in['markdown'] ?? '' );
		if ( $md === '' ) {
			return self::err( 'mdf_missing_content', 'content (Markdown source) is required.' );
		}
		$size = function_exists( 'mb_strlen' ) ? mb_strlen( $md ) : strlen( $md );
		if ( $size > 300000 ) {
			return self::err( 'mdf_too_large', 'Content too large for the preview.', 413 );
		}
		try {
			$html = Mdf_Renderer::render( $md );
		} catch ( \Throwable $e ) {
			return self::err( 'mdf_render_failed', 'Rendering failed.' );
		}
		return array(
			'html'     => $html,
			'bytes_md' => (int) $size,
			'pipeline' => 'ParsedownExtra + LaTeX→MathML + KSES (Mdf_Renderer)',
		);
	}

	/**
	 * Handler: mdf/upload-media (v1.3.29, Media-Upload-Service für Agenten).
	 *
	 * base64 → Temp-File → wp-includes-Upload-Pfad (MIME-Whitelist,
	 * Uploads-Dir, wp_unique_filename) → Attachment + URL + fertiges
	 * Markdown-Snippet. (wp-admin-Funktionen bewusst vermieden: REST-Kontext.)
	 *
	 * @param mixed $input Request.
	 * @return array|WP_Error attachment_id, url, markdown, alt, mime_type, bytes, filename.
	 */
	public static function run_upload_media( $input = null ) {
		$in  = self::params( $input );
		$b64 = trim( (string) ( $in['data'] ?? '' ) );
		$name = (string) ( $in['filename'] ?? '' );
		$alt  = (string) ( $in['alt_text'] ?? '' );
		$pid  = (int) ( $in['post_id'] ?? 0 );

		if ( $b64 === '' ) {
			return self::err( 'mdf_missing_fields', 'data (base64 file content) is required.' );
		}
		// 5 MiB gedeckt (base64-Overhead ≈ 6,8 MB Zeichen)
		if ( strlen( $b64 ) > 7000000 ) {
			return self::err( 'mdf_too_large', 'File too large (max 5 MB decoded).', 413 );
		}
		$raw = base64_decode( $b64, true );
		if ( $raw === false ) {
			return self::err( 'mdf_bad_param', 'data is not valid base64.' );
		}
		if ( $raw === '' || strlen( $raw ) > 5 * 1024 * 1024 ) {
			return self::err( 'mdf_too_large', 'File too large (max 5 MB decoded).', 413 );
		}
		if ( $pid > 0 && ! get_post( $pid ) ) {
			return self::err( 'mdf_unknown_post', 'Post with this ID (post_id) not found.', 404 );
		}
		// Basename nur: keine Pfade, kein Traversal, keine Steuerzeichen
		if ( $name !== '' ) {
			$name = basename( str_replace( '\\', '/', $name ) );
		}
		if ( $name !== '' && ( strpos( $name, '..' ) !== false || $name[0] === '.' || preg_match( '/[\x00-\x1f]/', $name ) ) ) {
			return self::err( 'mdf_bad_param', 'filename must be a plain basename without path elements.' );
		}
		$display = $name !== '' ? $name : 'upload';

		// 1) Temp-Datei (sys-TEMP; wp_tempnam liegt in wp-admin/includes → REST-kontextunverfügbar)
		$tmp = tempnam( sys_get_temp_dir(), 'mdf' );
		if ( $tmp === false || file_put_contents( $tmp, $raw ) === false ) {
			if ( $tmp !== false ) { @unlink( $tmp ); }
			return self::err( 'mdf_upload_failed', 'Upload buffer could not be prepared (temp file).' );
		}

		// 2) MIME: Dateiname → finfo, danach WP-MIME-Whitelist
		$fin      = wp_check_filetype( $display );
		$type_str = (string) ( $fin['type'] ?? '' );
		if ( $type_str === '' && function_exists( 'finfo_open' ) ) {
			$fi = finfo_open( FILEINFO_MIME_TYPE );
			if ( $fi ) {
				$type_str = (string) finfo_file( $fi, $tmp );
				finfo_close( $fi );
			}
		}
		if ( $type_str === '' || ! in_array( $type_str, get_allowed_mime_types( null ), true ) ) {
			@unlink( $tmp );
			return self::err( 'mdf_bad_param', sprintf( 'File type %s is not allowed (WP MIME allowlist).', '' === $type_str ? 'unknown' : $type_str ) );
		}

		// 3) In Uploads-Verzeichnis einordnen (nur wp-includes-Funktionen)
		$upload = wp_upload_dir();
		if ( is_wp_error( $upload ) ) {
			@unlink( $tmp );
			return self::err( 'mdf_upload_failed', $upload->get_error_message() );
		}
		$subdir     = ltrim( (string) $upload['subdir'], '/' );
		$dir        = '' === $subdir ? $upload['basedir'] : trailingslashit( $upload['basedir'], $subdir );
		if ( ! wp_mkdir_p( $dir ) ) {
			@unlink( $tmp );
			return self::err( 'mdf_upload_failed', 'Upload directory could not be created.' );
		}
		$final_name = wp_unique_filename( $dir, $display );
		$path = trailingslashit( $dir ) . $final_name;
		if ( ! rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return self::err( 'mdf_upload_failed', 'File could not be moved into the uploads directory.' );
		}
		chmod( $path, 0644 );

		// 4) Attachment-Record
		$attach_id = wp_insert_attachment(
			array(
				'post_title'     => $final_name,
				'post_status'    => 'inherit',
				'post_mime_type' => $type_str,
			),
			$path
		);
		if ( is_wp_error( $attach_id ) || (int) $attach_id <= 0 ) {
			@unlink( $path );
			return self::err( 'mdf_upload_failed', is_wp_error( $attach_id ) ? $attach_id->get_error_message() : 'Attachment record could not be stored.' );
		}
		update_attached_file( (int) $attach_id, $path );

		if ( $alt !== '' ) {
			update_post_meta( (int) $attach_id, '_wp_attachment_image_alt', $alt );
		}
		if ( $pid > 0 ) {
			wp_update_post( array( 'ID' => (int) $attach_id, 'post_parent' => $pid ) );
		}

		// Bild-Metadaten (Thumbs) generieren — nur wenn WP die Image-Funktionen kennt (wp-admin-Kontext)
		if ( function_exists( 'wp_generate_attachment_metadata' ) && ( 0 === strpos( $type_str, 'image/' ) ) ) {
			$meta = wp_generate_attachment_metadata( (int) $attach_id, $path );
			if ( is_array( $meta ) ) {
				wp_update_attachment_metadata( (int) $attach_id, $meta );
			}
		}

		$url      = (string) wp_get_attachment_url( (int) $attach_id );
		$mime     = $type_str;
		$base     = $final_name;
		$is_image = ( 0 === strpos( $mime, 'image/' ) );
		$markdown = $is_image
			? '![' . $alt . '](' . $url . ')'
			: '[' . ( $base !== '' ? $base : $display ) . '](' . $url . ')';

		return array(
			'attachment_id' => (int) $attach_id,
			'url'           => $url,
			'markdown'      => $markdown,
			'alt'           => $alt,
			'mime_type'     => $mime,
			'filename'      => $base,
			'bytes'         => (int) strlen( $raw ),
		);
	}
}
