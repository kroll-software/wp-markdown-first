<?php
/**
 * MD First theme — static page.
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
		</header>

		<div class="entry-content mdf-prose">
			<?php the_content(); // phpcs:ignore ?>
		</div>
	</article>
	<?php
endwhile;

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- standard template hook.
get_footer();
