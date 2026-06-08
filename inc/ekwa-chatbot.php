<?php
/**
 * Chatbot loader.
 *
 * Injects the configured chatbot loader script (Ekwa Settings → General →
 * "Chatbot script URL") on the FIRST user interaction — scroll, mouse move,
 * touch, key press, or click — so it never blocks initial render or hurts the
 * Core Web Vitals. Unlike the Font Awesome deferral (mobile only), this defers
 * on BOTH desktop and mobile.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize the chatbot field. Accepts a bare loader URL OR a full
 * `<script ... src="…"></script>` embed (the src is extracted), and returns a
 * clean URL or '' when empty/invalid.
 *
 * @param string $raw Raw field value.
 * @return string
 */
function ekwa_sanitize_chatbot_src( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	// If the user pasted the whole embed code, pull the src out of it.
	if ( false !== stripos( $raw, '<script' ) && preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $raw, $m ) ) {
		$raw = $m[1];
	}
	return esc_url_raw( $raw );
}

/**
 * The configured chatbot loader src (validated), or '' when unset.
 */
function ekwa_chatbot_src() {
	return esc_url_raw( (string) get_option( 'ekwa_chatbot_src', '' ) );
}

/**
 * Print the inline interaction-loader in the footer.
 *
 * On the first scroll / mousemove / touch / keydown / click it appends an
 * async <script src="…loader.js"> to the page, then unbinds the listeners so it
 * only ever loads once. If the visitor never interacts, the chatbot never loads
 * — that's the intent (zero cost until the user is actually engaging).
 */
function ekwa_chatbot_emit_loader() {
	if ( is_admin() ) {
		return;
	}
	$src = ekwa_chatbot_src();
	if ( '' === $src ) {
		return;
	}
	$src_js = wp_json_encode( $src );
	?>
<script id="ekwa-chatbot-loader">
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
add_action( 'wp_print_footer_scripts', 'ekwa_chatbot_emit_loader' );
