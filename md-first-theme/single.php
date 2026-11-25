<?php
/**
 * MD First theme — single post.
 *
 * @package md-first-theme
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard template hook.
get_header();

while ( have_posts() ) :
	the_post();
	?>
	<article <?php post_class( 'entry' ); // phpcs:ignore ?> >
		<header class="entry-header">
			<h1 class="entry-title"><?php the_title(); // phpcs:ignore ?></h1>
			<p class="entry-meta">
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
				<?php
				$mdft_cats = get_the_category_list( ', ' );
				if ( '' !== $mdft_cats ) {
					echo ' · ', $mdft_cats; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_the_category_list() ist escaped.
				}
				?>
			</p>
		</header>

		<div class="entry-content mdf-prose">
			<?php
			// the_content() runs the md-first-core renderer hook when the
			// plugin is active; otherwise plain stored content is shown.
			the_content();
			?>
		</div>
	</article>
	<?php
endwhile;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard template hook.
get_footer();
