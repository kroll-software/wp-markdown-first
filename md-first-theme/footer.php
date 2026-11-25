<?php
/**
 * MD First theme — frame bottom.
 *
 * @package md-first-theme
 */

defined( 'ABSPATH' ) || exit;
?>
</main>

<footer class="site-footer">
	<div class="inner">
		<p>
			© <?php echo esc_html( gmdate( 'Y' ) ); ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a>
			·
			<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>">llms.txt</a>
			·
			<a href="<?php echo esc_url( home_url( '/llms-full.txt' ) ); ?>">llms-full.txt</a>
		</p>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
