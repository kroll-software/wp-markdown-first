<?php
/**
 * MD First theme — main index (front page, search, fallbacks).
 *
 * Markdown prose (when md-first-core is active) is rendered by the
 * plugin's the_content filter and styled under .mdf-prose; the list
 * below only carries structure.
 *
 * @package md-first-theme
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard template hook.
get_header();

if ( have_posts() ) :

	if ( is_home() && ! is_front_page() ) {
		echo '<h1 class="page-title">';
		single_post_title();
		echo '</h1>';
	}

	if ( is_search() ) {
		echo '<h1 class="page-title">';
		esc_html_e( 'Search results', 'md-first-theme' );
		echo '</h1>';
	}
	?>
	<div class="post-list">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'post-card' ); // phpcs:ignore ?> >
			<header class="post-header">
				<h2><a href="<?php the_permalink(); ?>"><?php the_title(); // phpcs:ignore ?></a></h2>
				<p class="post-meta">
					<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
				</p>
			</header>
			<div class="post-excerpt">
				<?php
				// the_excerpt() triggers the md-first-core plain-text filter
				// when active; without the plugin, WP trims stored content.
				the_excerpt();
				?>
			</div>
		</article>
		<?php
	endwhile;

	the_posts_pagination(
		array(
			'prev_text' => __( '← Newer', 'md-first-theme' ),
			'next_text' => __( 'Older →', 'md-first-theme' ),
		)
	);
	?>
	</div>
<?php else : ?>
	<div class="no-posts">
		<h1 class="page-title"><?php esc_html_e( 'Nothing here', 'md-first-theme' ); ?></h1>
		<p><?php esc_html_e( 'No content has been published yet.', 'md-first-theme' ); ?></p>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Go home', 'md-first-theme' ); ?></a></p>
	</div>
<?php endif; ?>

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard template hook.
get_footer();
