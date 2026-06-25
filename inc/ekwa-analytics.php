<?php
/**
 * Google Analytics (gtag.js).
 *
 * Injects the Google Analytics tag. Authors can either enter just the
 * Measurement ID (e.g. G-NWNDT72C1E) — the standard gtag snippet is built for
 * them — or paste a full custom <script> tracking snippet, which is output
 * verbatim. The tag prints in the footer by default (kept out of the critical
 * <head>); a placement option can move it to the header instead. Configure
 * under Ekwa Settings → General → "Google Analytics".
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize the Google Analytics field.
 *
 * Two accepted shapes:
 *   1. A bare Measurement ID — G-XXXXXXXX (GA4), UA-XXXXXX-X, AW-XXXXXXXXX,
 *      or GT-XXXXXXX. Stored stripped of any character outside the valid set.
 *   2. A full <script> snippet — stored verbatim (this is a manage_options-only
 *      field) so custom gtag configs, consent mode, etc. survive intact.
 *
 * @param string $raw Raw field value.
 * @return string
 */
function ekwa_sanitize_analytics( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	// Full snippet: keep verbatim (admin-only field, same trust model as the
	// SVG logo markup and Related Posts template).
	if ( false !== stripos( $raw, '<script' ) ) {
		return $raw;
	}
	// Otherwise treat as a bare Measurement ID — keep only the valid charset.
	return preg_replace( '/[^A-Za-z0-9\-]/', '', $raw );
}

/**
 * Whether a stored value is a bare gtag Measurement ID (vs. a full snippet).
 *
 * @param string $value Stored analytics value.
 * @return bool
 */
function ekwa_analytics_is_measurement_id( $value ) {
	return (bool) preg_match( '/^(?:G|UA|AW|GT)-[A-Za-z0-9\-]+$/i', trim( (string) $value ) );
}

/**
 * Print the Google Analytics tag.
 *
 * Output is identical regardless of placement; only the hook it fires on
 * (wp_footer vs wp_head) differs — see ekwa_analytics_register(). The gtag
 * loader is async, so it never blocks rendering either way.
 */
function ekwa_analytics_emit() {
	if ( is_admin() ) {
		return;
	}
	$value = trim( (string) get_option( 'ekwa_analytics', '' ) );
	if ( '' === $value ) {
		return;
	}

	// Full custom snippet — output verbatim.
	if ( false !== stripos( $value, '<script' ) ) {
		echo "\n" . $value . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin-provided analytics snippet.
		return;
	}

	// Bare Measurement ID — build the standard gtag snippet.
	if ( ! ekwa_analytics_is_measurement_id( $value ) ) {
		return;
	}
	$id    = esc_attr( $value );
	$id_js = wp_json_encode( $value );
	?>
<!-- Google Analytics (Ekwa) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $id; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', <?php echo $id_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>);
</script>
	<?php
}

/**
 * Register the analytics output on the configured hook.
 *
 * Footer (wp_footer) is the default so the tag never competes with critical
 * above-the-fold <head> resources. A very late priority (PHP_INT_MAX) is used
 * so the tag renders after every other footer script — enqueued footer scripts
 * (including the child theme's ekwa-child-js) print at wp_footer priority 20,
 * and the inlined child JS at priority 21. Header placement is opt-in and uses
 * wp_head at high priority so the tag sits near the top of <head>.
 */
function ekwa_analytics_register() {
	$location = get_option( 'ekwa_analytics_location', 'footer' );
	if ( 'header' === $location ) {
		add_action( 'wp_head', 'ekwa_analytics_emit', 1 );
	} else {
		add_action( 'wp_footer', 'ekwa_analytics_emit', PHP_INT_MAX );
	}
}
ekwa_analytics_register();
