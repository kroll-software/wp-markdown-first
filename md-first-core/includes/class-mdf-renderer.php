<?php
/**
 * MD First Core — Renderer.
 *
 * Pipeline (Reihenfolge ist Teil des Vertrags):
 *   1) Math schützen (Block $$…$$ / \[ … \], Inline $…$ / \( … \) — TeX-Heuristik)
 *   2) Markdown → HTML (ParsedownExtra; Codeblöcke bleiben dort $-sicher)
 *   3) Platzhalter → MathML (Latex2MathML\Converter), Fehler → .mdf-math-fallback
 *   4) KSES mit Post-Whitelist + MathML-Allowlist
 *   5) Fußnote-Anker-Reparatur (KSES strippt #fn:/#fnrefN: aus hrefs — dort)
 *
 * Cache: gerendertes HTML in _mdf_html_cache als BASE64 (seit v1.2.1) —
 * WP-Meta-Wert-Handling (Slashing) würde sonst HTML mit Backslash zerstören
 * und die Pipeline wäre nicht deterministisch.
 *
 * @package MD_First_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Markdown → HTML (öffentliche API).
 *
 * @param string $md Rohes Markdown.
 * @return string Sanisiertes HTML.
 */
function mdf_render( string $md ): string {
	return Mdf_Renderer::render( $md );
}

/**
 * Enthält der Inhalt Math-Delimiter?
 *
 * @param string $md Markdown.
 * @return bool
 */
function mdf_has_math( string $md ): bool {
	return (bool) preg_match( '/\\\\\[|\\\\\(|\$(?:\\$|\\\\|\{|\}|[\\^_])|\\\$[^\n\$]{1,400?}[\\^_{}\$]/', $md );
}

/**
 * <slug>.md-URL für Theme-<head> (link rel=alternate).
 *
 * @param int $post_id Post/Seiten-ID.
 * @return string Absolute URL oder '' falls ungültig.
 */
function mdf_md_alt_href( int $post_id ): string {
 $post = get_post( (int) $post_id );
	if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		return '';
	}
	$slug = '' !== (string) $post->post_name ? $post->post_name : (string) $post->ID;
	return home_url( '/' . $slug . '.md' );
}

final class Mdf_Renderer {
	const CACHE_META    = '_mdf_html_cache';
	const CACHE_VERSION = 'v3';

	public static function render( string $md ): string {
		$pieces = self::protect_math( $md );
		$spans  = self::protect_code_spans( $pieces[0] );
		$html   = ( new ParsedownExtra() )->text( $spans[0] );
		$html   = self::restore_math( $html, $pieces[1] );
		$html   = self::restore_code_spans( $html, $spans[1] );
		$html   = wp_kses( $html, self::kses_allowlist() );
		return self::repair_footnote_anchors( $html );
	}

	/**
	 * KSES-Link-Prüfung behandelt alles vor dem ersten Doppelpunkt in einer
	 * href wie ein (nicht erlaubtes) Protokoll und streicht es: die
	 * ParsedownExtra-Fußnote-Anker
	 *     href="#fn:KEY"      → href="KEY"      (class="footnote-ref")
	 *     href="#fnrefN:KEY"  → href="KEY"      (rev="footnote")
	 * verlierten damit ihr Fragment. Die ID-Seite (id="fn:KEY", id="fnrefN:KEY")
	 * bleibt bei KSES unangetastet — die Reparatur nutzt das als Ziel und die
	 * Parsedown-spezifischen Marker (footnote-ref / rev="footnote"), die sonst
	 * nirgends auftauchen: deterministisch, keine Kollision mit User-Links.
	 *
	 * Geprüft 2026-11-23 (Matrix-Post 62): KSES liefert exakt diese beiden
	 * Destinationsformen; id="fn:..." überlebt.
	 *
	 * Bekannte Einschränkung: eine Fußnote-KEY, die selbst ":" enthält, wird
	 * von KSES am href gekürzt — solche Keys sollten vermieden werden.
	 */
	public static function repair_footnote_anchors( string $html ): string {
		// Referenzen -> #fn:KEY (Ziel: <li id="fn:KEY">).
		$html = preg_replace_callback(
			'~<a href="([^"]*)" class="footnote-ref">~',
			static function ( array $m ): string {
				return str_starts_with( $m[1], '#fn:' )
					? $m[0]
					: '<a href="#fn:' . $m[1] . '" class="footnote-ref">';
			},
			$html
		);

		// Backrefs -> #fnrefN:KEY: Pairing pro <li id="fn:KEY">-Block.
		return preg_replace_callback(
			'~<li id="fn:([^"]+)"[^>]*>.*?</li>~s',
			static function ( array $m ) use ( $html ): string {
				return self::repair_footnote_backrefs_in_block( $m[0], $m[1], $html );
			},
			$html
		);
	}

	private static function repair_footnote_backrefs_in_block( string $block, string $key, string $full_html ): string {
		preg_match_all( '~<sup id="(fnref\d+:' . preg_quote( $key, '~' ) . ')"~', $full_html, $supm );
		$targets = $supm[1] ?? array();
		if ( empty( $targets ) ) {
			return $block;
		}

		preg_match_all( '~<a href="[^"]*" rev="footnote"~', $block, $matches, PREG_OFFSET_CAPTURE );
		if ( empty( $matches[0] ) ) {
			return $block;
		}

		$result = '';
		$cursor = 0;
		$target_count = count( $targets );

		foreach ( $matches[0] as $idx => $match ) {
			[ $matched_text, $offset ] = $match;
			$result .= substr( $block, $cursor, $offset - $cursor );

			if ( $idx < $target_count ) {
				$result .= '<a href="#' . $targets[ $idx ] . '" rev="footnote"';
			} else {
				$result .= $matched_text;
			}

			$cursor = $offset + strlen( $matched_text );
		}

		$result .= substr( $block, $cursor );
		return $result;
	}

	/**
	 * Math-Delimiter gegen Platzhalter tauschen.
	 *
	 * @return array{0: string, 1: array<int, array{tex: string, display: string}>} [Text, Liste].
	 */
	public static function protect_math( string $md ): array {
		$state = new class( $md ) {
			public string $md;
			/** @var array<int, array{tex: string, display: string}> */
			public array $maths = array();

			public function __construct( string $md ) {
				$this->md = $md;
			}

			public function store_block( string $tex, bool $wrap_newlines ): string {
				$idx = count( $this->maths );
				$this->maths[] = array(
					'tex'     => trim( $tex ),
					'display' => 'block',
				);
				$placeholder = '%%MDMATH' . $idx . '%%';
				return $wrap_newlines ? "\n" . $placeholder . "\n" : $placeholder;
			}

			public function store_inline( string $tex ): string {
				$idx = count( $this->maths );
				$this->maths[] = array(
					'tex'     => $tex,
					'display' => 'inline',
				);
				return '%%MDMATH' . $idx . '%%';
			}
		};

		// Block: $$...$$ (mehrzeilig) -> display=block.
		$state->md = preg_replace_callback(
			'~^\s*\$\$(.+?)\$\$~ms',
			static function ( array $m ) use ( $state ): string {
				return $state->store_block( $m[1], true );
			},
			$state->md
		);

		// Block: \[ ... \] (mehrzeilig; LaTeX-Delimiter wie sie LLMs typischerweise ausgeben)
		// -> display=block. Inhalt wird unveraendert in die MathML-Konversion uebergeben.
		$state->md = preg_replace_callback(
			'~\\\\\[[\s\S]+?\\\\\]~',
			static function ( array $m ) use ( $state ): string {
				return $state->store_block( substr( $m[0], 2, -2 ), true );
			},
			$state->md
		);

		// Inline: \( ... \) (keine Zeilenumbrueche; der Inhalt darf Klammern enthalten -
		// abgebrochen wird erst am ersten \)-Delimiter). Vor der $-Heuristik, damit deren
		// Scan keine geschuetzten Formeln mehr sieht.
		$state->md = preg_replace_callback(
			'~\\\\\(([^\n]+?)\\\\\)~',
			static function ( array $m ) use ( $state ): string {
				return $state->store_inline( $m[1] );
			},
			$state->md
		);

		// Inline: $...$ ohne Zeilenumbruch; TeX-Heuristik ({}^_\ oder Rueckwaertsschraegstrich)
		// reduziert False-Positives in Shell-/Code-Kontexten.
		$state->md = preg_replace_callback(
			'~\$((?:\\\\[a-zA-Z]+|[^\$\\\\])*?[{}^_\\\\][^$\n]*?)\$~',
			static function ( array $m ) use ( $state ): string {
				return $state->store_inline( $m[1] );
			},
			$state->md
		);

		return array( $state->md, $state->maths );
	}

	public static function restore_math( string $html, array $maths ): string {
		// preserve_keys: $i ist der Originalindex des Platzhalters. Die umgekehrte
		// Reihenfolge sorgt dafür, dass %%MDMATH1%% nicht in %%MDMATH10%% matcht.
		foreach ( array_reverse( $maths, true ) as $i => $entry ) {
			$html = str_replace( '%%MDMATH' . $i . '%%', self::tex_to_mathml( $entry['tex'], $entry['display'] ), $html );
		}
		return $html;
	}

	/**
	 * Inline-Code-Spans, die `*` oder `_` enthalten, vor Parsedown schützen.
	 *
	 * Parsedown-Mangel (reproduziert 2026-11-23, Post 33 Zeile 468):
	 * In `**`code*mit_stern*`**` gewinnt die Emphasis-Erkennung gegen den
	 * Code-Span; der `*` im Span schließt die Emphasis ab und das Ergebnis
	 * wird zu `*<em>`code_</em>`**` zerlegt. Nur Spans mit Emphasis-Zeichen
	 * sind betroffen — die hier als Platzhalter durchlaufen und Parsedown
	 * sieht saubere Token. Spans ohne `*`/`_`, gefasste Codeblöcke und Spans mit
	 * internen Backticks bleiben unberührt.
	 *
	 * @return array{0: string, 1: array<string, string>} [Text, Map Placeholder=>Inhalt].
	 */
	public static function protect_code_spans( string $md ): array {
		$state = new class() {
			/** @var array<string, string> */
			public array $spans = array();

			public function store( string $code ): string {
				$key                 = 'MDFCODESPAN' . count( $this->spans ) . 'X';
				$this->spans[ $key ] = $code;
				return $key;
			}
		};

		$md = preg_replace_callback(
			'~(`{1,2})([^\n`]*[*_][^\n`]*)\1~',
			static function ( array $m ) use ( $state ): string {
				return $state->store( $m[2] );
			},
			$md
		);

		return array( $md, $state->spans );
	}

	/**
	 * Code-Span-Platzhalter nach dem Render nach <code>-Tags zurückbauen.
	 * Vor wp_kses: 'code' steht in der Post-Allowlist; Inhalt wird escaped.
	 *
	 * @param string $html  Angegebenes HTML mit Platzhaltern.
	 * @param array<string, string> $spans Map aus protect_code_spans().
	 */
	public static function restore_code_spans( string $html, array $spans ): string {
		foreach ( $spans as $key => $content ) {
			$html = str_replace( $key, '<code>' . htmlspecialchars( $content, ENT_QUOTES ) . '</code>', $html );
		}
		return $html;
	}

	/**
	 * LaTeX → MathML (latex2mathml/latex2mathml-php; API Spike-verifiziert:
	 * Latex2MathML\Converter::convert( $latex, 'inline'|'block' )).
	 *
	 * @param string $tex     LaTeX-Quelle.
	 * @param string $display 'inline' | 'block'.
	 * @return string MathML-Span oder Fallback-<pre>.
	 */
	public static function tex_to_mathml( string $tex, string $display = 'inline' ): string {
		try {
			$mathml = \Latex2MathML\Converter::convert( $tex, $display );
			if ( is_string( $mathml ) && '' !== trim( $mathml ) && false !== strpos( $mathml, '<math' ) ) {
				return $mathml;
			}
			throw new \RuntimeException( 'Konversion lieferte kein MathML.' );
		} catch ( \Throwable $e ) {
			// Fallback ist Teil des Vertrags: rohe LaTeX-Quelle sichtbar, kein Crash.
			return '<pre class="mdf-math-fallback">' . esc_html( '$' . $tex . '$' ) . '</pre>';
		}
	}

	public static function kses_allowlist() {
		$base = wp_kses_allowed_html( 'post' );
		$math = array(
			'math'       => array( 'display' => true, 'class' => true, 'xmlns' => true ),
			'mrow'       => array( 'class' => true, 'id' => true, 'displaystyle' => true ),
			'mi'         => array(),
			'mo'         => array( 'stretchy' => true, 'fence' => true, 'movablelimits' => true, 'symmetric' => true, 'accent' => true, 'lquote' => true, 'rquote' => true, 'lspace' => true, 'rspace' => true ),
			'mn'         => array(),
			'ms'         => array( 'lquote' => true, 'rquote' => true ),
			'mspace'     => array( 'width' => true, 'height' => true, 'depth' => true ),
			'mtext'      => array( 'class' => true, 'mathvariant' => true, 'id' => true ),
			'msub'       => array(),
			'msup'       => array(),
			'msubsup'    => array(),
			'mfrac'      => array( 'linethickness' => true, 'displaystyle' => true ),
			'msqrt'      => array(),
			'mroot'      => array(),
			'munder'     => array(),
			'mover'      => array(),
			'munderover' => array(),
			'mtable'     => array( 'displaystyle' => true, 'rowspacing' => true, 'columnspacing' => true, 'columnalign' => true, 'rowalign' => true ),
			'mtr'        => array(),
			'mtd'        => array( 'colspan' => true, 'rowspan' => true, 'columnalign' => true, 'rowalign' => true ),
			'mphantom'   => array(),
			'mpadded'    => array( 'width' => true, 'height' => true, 'depth' => true, 'lspace' => true, 'rspace' => true ),
			'menclose'   => array( 'notation' => true, 'color' => true ),
			'mstyle'     => array( 'scriptlevel' => true, 'displaystyle' => true, 'scriptlevelmin' => true ),
			'annotation' => array( 'encoding' => true ),
			'semantics'  => array(),
		);
		foreach ( $math as $tag => $attrs ) {
			$base[ $tag ] = $attrs;
		}
		$base['span']['class'] = true;
		$base['pre']['class']  = true;
		return $base;
	}

	/**
	 * Caching-Ausgang (Post-Meta; Key = Version + MD5 des MD — Invalidation = Meta-Löschung).
	 *
	 * @param int    $post_id Post/Seiten-ID.
	 * @param string $md      Markdown.
	 * @return string HTML.
	 */
	public static function render_cached( int $post_id, string $md ): string {
		$key = self::CACHE_VERSION . ':' . md5( $md );
		$c   = get_post_meta( $post_id, self::CACHE_META, true );
		// HTML wird als BASE64 gespeichert (seit v1.2.1): Das Alphabet ist
		// slash/quote-frei, dadurch ist der WP-Meta-Rundtrip (Slashing beim
		// Schreiben, Unslashen beim Lesen) eine Identität — gerendertes HTML
		// mit Backslashes (Windows-Pfade, Escapes, Code) überlebt unverändert.
		if (
			is_array( $c ) && isset( $c['key'], $c['html'] ) && $c['key'] === $key
			&& is_string( $c['html'] )
			&& false !== ( $decoded = base64_decode( $c['html'], true ) )
		) {
			return $decoded;
		}
		$html = self::render( $md );
		update_post_meta( $post_id, self::CACHE_META, array(
			'key'  => $key,
			'html' => base64_encode( $html ),
		) );
		return $html;
	}

	public static function invalidate( int $post_id ): void {
		delete_post_meta( (int) $post_id, self::CACHE_META );
	}

	// ── Content-Pipeline ──────────────────────────────────────────────────────

	public static function install_content_pipeline(): void {
		add_filter( 'the_content', array( __CLASS__, 'render_the_content' ), 0 );
		add_filter( 'the_excerpt', array( __CLASS__, 'render_excerpt' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'maybe_invalidate_save' ), 10, 3 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'maybe_invalidate_insert' ), 10, 2 );
	}

	/**
	 * Für MD-Inhalte die Standard-`the_content`-Kette (wpautop & Co.) abkürzen:
	 * deterministischer Renderer-Output statt HTML-Welt-Filter.
	 */
	public static function render_the_content( string $content ): string {
		if ( is_admin() || ( ! is_singular() && ! in_the_loop() ) ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return $content;
		}
		// Verbleibende the_content-Filter (wpautop/priority 10 …) für diesen Request
		// entfernen — DABEI bleibt der eigene Hook aber aktiv: remove_all_filters()
		// würde ihn sonst mit auslöschen und die Folge-Posts desselben Loops
		// (Übersichtsseite) kämen ohne Renderer durch (rohes Markdown).
		// ACHTUNG: 2. Argument von remove_all_filters() ist PRIORITY (Default false =
		// alle Prioritäten) — remove_all_filters( 'the_content', 1 ) würde nur
		// Priority-1-Callbacks löschen (leer) und wpautop & Co. überleben lassen!
		remove_all_filters( 'the_content' );
		add_filter( 'the_content', array( __CLASS__, 'render_the_content' ), 0 );
		$html = self::render_cached( (int) $post->ID, (string) $content );
		return apply_filters( 'mdf_content', $html, $post );
	}

	/**
	 * Excerpt: explizite Excerpt-Kiste gewinnen, sonst gerendertes MD → Plain-Text → Worttrim.
	 *
	 * @param string          $excerpt Vorbearbeiteter Excerpt (WP-Kette).
	 * @param WP_Post|integer $post    Post.
	 */
	public static function render_excerpt( string $excerpt, $post = null ): string {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return $excerpt;
		}
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return wp_kses_post( $post->post_excerpt );
		}
		$html = self::render_cached( (int) $post->ID, (string) $post->post_content );
		$txt  = wp_strip_all_tags( $html );
		return esc_html( wp_trim_words( $txt, (int) mdf_config()['excerpt_words'], '…' ) );
	}

	/**
	 * @param int    $id      Post-ID.
	 * @param mixed  $post    Post.
	 * @param bool   $updated true bei Update (nicht Create).
	 */
	public static function maybe_invalidate_save( int $id, $post, bool $updated ): void {
		if ( $updated && $post instanceof WP_Post && ! wp_is_post_autosave( $id ) && ! wp_is_post_revision( $id ) ) {
			self::invalidate( $id );
		}
	}

	/**
	 * `wp_after_insert_post` feuert hier: ( int $post_id, WP_Post $post, bool $update, null|WP_Post $post_before ).
	 */
	public static function maybe_invalidate_insert( int $post_id, $post = null, bool $update = false ): void {
		self::invalidate( $post_id );
	}
}
