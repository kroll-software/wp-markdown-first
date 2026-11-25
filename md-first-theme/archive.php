<?php
/**
 * MD First theme — archives (categories, tags, authors, dates).
 *
 * @package md-first-theme
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard template hook.
get_header();

echo '<h1 class="page-title">';
if ( is_category() ) {
	single_term_title();
} elseif ( is_tag() ) {
	single_term_title();
} elseif ( is_author() ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered author name.
	the_author();
} elseif ( is_year() ) {
	printf( esc_html__( 'Archives: %s', 'md-first-theme' ), esc_html( get_the_date( 'Y' ) ) );
} elseif ( is_month() ) {
	get_the_date( esc_html_x( 'F Y', 'archive month', 'md-first-theme' ) );
} elseif ( is_day() ) {
	get_the_date(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} else {
	esc_html_e( 'Archives', 'md-first-theme' );
}
echo '</h1>';

if ( have_posts() ) :
	?>
	<div class="post-list">
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'post-card' ); // phpcs:ignore ?> >
			<h2><a href="<?php the_permalink(); ?>"><?php the_title(); // phpcs:ignore ?></a></h2>
			<p class="post-meta">
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
			</p>
			<div class="post-excerpt">
				<?php the_excerpt(); // phpcs:ignore ?>
			</div>
		</article>
		<?php
	endwhile;

	the_posts_pagination();
	?>
	</div>
<?php else : ?>
	<div class="no-posts">
		<h1 class="page-title"><?php esc_html_e( 'Nothing here', 'md-first-theme' ); ?></h1>
		<p><?php esc_html_e( 'No content has been published yet.', 'md-first-theme' ); ?></p>
	</div>
<?php endif; ?>

<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard template hook.
get_footer();
