<?php
/**
 * MD First Settings: wp-admin-Settings-UI für mdf_config() (0 JS).
 *
 * Speichert die übergebene Konfiguration als Option `mdf_settings` und
 * brückt sie per Filter `mdf_config` in die Laufzeit-Konfiguration ein.
 * Schlüssel, die nicht gespeichert sind, behalten den Default (Filter-Kette).
 *
 * @package MD_First_Core
 */

defined( 'ABSPATH' ) || exit;

class Mdf_Settings {

	const OPTION = 'mdf_settings';

	/**
	 * Defaults — müssen mit dem Array in mdf_config() (md-first-core.php)
	 * übereinstimmen; die Defaults dort bleiben die SSoT, diese Liste
	 * steuert nur die renderbare/validierbare Menge der UI.
	 */
	public static function defaults(): array {
		return array(
			'md_endpoints'  => true,
			'llms'          => true,
			'list_max'      => 100,
			'excerpt_words' => 30,
		);
	}

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_filter( 'mdf_config', array( __CLASS__, 'apply_saved' ) );
	}

	/**
	 * Option unter "Einstellungen → Markdown First".
	 */
	public static function menu(): void {
		add_options_page(
			__( 'Markdown First', 'md-first-core' ),
			__( 'Markdown First', 'md-first-core' ),
			'manage_options',
			'markdown-first',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_setting(): void {
		register_setting(
			'mdf_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	/**
	 * Gepeicherte Werte über die Defaults legen (nur vorhandene Schlüssel).
	 *
	 * @param array $cfg Laufzeit-Konfiguration (Defaults).
	 * @return array
	 */
	public static function apply_saved( $cfg ): array {
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}
		$saved = get_option( self::OPTION );
		if ( is_array( $saved ) ) {
			foreach ( self::defaults() as $key => $default ) {
				if ( array_key_exists( $key, $saved ) ) {
					$cfg[ $key ] = $saved[ $key ];
				}
			}
		}
		return $cfg;
	}

	/**
	 * Sanitize: Booles als Booles, Integer in festen Ranges.
	 *
	 * @param mixed $in Roh-Optionseingang.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $in ): array {
		$out   = array();
		$in    = is_array( $in ) ? $in : array();
		$out['md_endpoints']  = empty( $in['md_endpoints'] ) ? false : true;
		$out['llms']          = empty( $in['llms'] ) ? false : true;
		$out['list_max']      = (int) ( $in['list_max'] ?? self::defaults()['list_max'] );
		$out['list_max']      = max( 1, min( 500, $out['list_max'] ) );
		$out['excerpt_words'] = (int) ( $in['excerpt_words'] ?? self::defaults()['excerpt_words'] );
		$out['excerpt_words'] = max( 5, min( 200, $out['excerpt_words'] ) );
		return $out;
	}

	/**
	 * Einstellungsseite (native wp-admin-Formulare, 0 JS, 0 CSS-Dateien).
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Angezeigte Werte = EFFEKTIVE Konfiguration (Defaults, überlagert von
		// gespeicherten Werten) — sonst zeigt ein frischinstalliertes Plugin
		// unangekreuzte Checkboxen, während die Defaults aktiv sind.
		$val = self::defaults();
		$saved = get_option( self::OPTION );
		if ( is_array( $saved ) ) {
			$val = array_merge( $val, array_intersect_key( $saved, $val ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Markdown First', 'md-first-core' ); ?></h1>
			<p>
				<?php esc_html_e(
					'Settings apply to the mdf/* abilities and the Markdown endpoints (.md routes, /llms.txt). Saved per site; defaults listed as placeholder values.',
					'md-first-core'
				); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'mdf_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Markdown endpoints', 'md-first-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[md_endpoints]" value="1" <?php checked( ! empty( $val['md_endpoints'] ) ); ?> />
								<?php esc_html_e( 'Serve /<slug>.md and respect "Accept: text/markdown".', 'md-first-core' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'llms.txt', 'md-first-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[llms]" value="1" <?php checked( ! empty( $val['llms'] ) ); ?> />
								<?php esc_html_e( 'Serve /llms.txt and /llms-full.txt.', 'md-first-core' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mdf_list_max"><?php esc_html_e( 'List size limit', 'md-first-core' ); ?></label>
						</th>
						<td>
							<input id="mdf_list_max" type="number" min="1" max="500"
								name="<?php echo esc_attr( self::OPTION ); ?>[list_max]"
								value="<?php echo esc_attr( (string) ( $val['list_max'] ?? self::defaults()['list_max'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Maximum per_page accepted by mdf/list-posts-markdown (1–500).', 'md-first-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mdf_excerpt_words"><?php esc_html_e( 'Excerpt length', 'md-first-core' ); ?></label>
						</th>
						<td>
							<input id="mdf_excerpt_words" type="number" min="5" max="200"
								name="<?php echo esc_attr( self::OPTION ); ?>[excerpt_words]"
								value="<?php echo esc_attr( (string) ( $val['excerpt_words'] ?? self::defaults()['excerpt_words'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Auto-excerpt length in words for list and the_excerpt (5–200).', 'md-first-core' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

Mdf_Settings::register();
