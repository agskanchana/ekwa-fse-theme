<?php
/**
 * Delayed scripts loader.
 *
 * Defers the active theme's assets/js/delayed-scripts.js until the FIRST user
 * interaction (scroll / mousemove / touch / keydown / click), so it never
 * blocks initial render or hurts the Core Web Vitals. If the visitor never
 * interacts, the script never loads — that's the intent (zero cost until the
 * user is actually engaging).
 *
 * The mechanism lives in the parent theme, but the file itself is read from the
 * active (child) theme via get_stylesheet_directory(), so each site ships its
 * own assets/js/delayed-scripts.js. Mirrors the chatbot loader pattern
 * (inc/ekwa-chatbot.php).
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print the inline interaction-loader in the footer.
 *
 * On the first scroll / mousemove / touch / keydown / click it appends an
 * async <script src="…delayed-scripts.js"> to the page, then unbinds the
 * listeners so it only ever loads once. Bails silently if the active theme
 * has no assets/js/delayed-scripts.js.
 */
function ekwa_emit_delayed_scripts_loader() {
	if ( is_admin() ) {
		return;
	}

	$rel = '/assets/js/delayed-scripts.js';
	$abs = get_stylesheet_directory() . $rel; // active (child) theme.
	if ( ! file_exists( $abs ) ) {
		return;
	}

	$src    = add_query_arg( 'ver', filemtime( $abs ), get_stylesheet_directory_uri() . $rel ); // cache-bust on change.
	$src_js = wp_json_encode( $src );
	?>
<script id="ekwa-delayed-scripts-loader">
(function(){
	var src = <?php echo $src_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
	var events = ['scroll','mousemove','touchstart','keydown','click'];
	var loaded = false;
	function load(){
		if (loaded) return;
		loaded = true;
		var s = document.createElement('script');
		s.src = src;
		s.async = true;
		document.body.appendChild(s);
		events.forEach(function(e){ window.removeEventListener(e, load); });
	}
	events.forEach(function(e){ window.addEventListener(e, load, { passive: true, once: true }); });
})();
</script>
	<?php
}
add_action( 'wp_print_footer_scripts', 'ekwa_emit_delayed_scripts_loader' );
