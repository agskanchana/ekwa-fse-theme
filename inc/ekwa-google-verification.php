<?php
/**
 * Google Search Console site-verification meta tag.
 *
 * Outputs <meta name="google-site-verification" content="…"> in <head> from the
 * token an author pastes under Ekwa Settings → General → "Site Verification".
 * Accepts either the bare token or the full <meta> tag (the content value is
 * extracted). Leave empty to output nothing.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitize the verification field: accept a full <meta … content="TOKEN" …>
 * tag or a bare token, and store just the token (the content attribute value).
 *
 * @param string $raw Raw field value.
 * @return string
 */
function ekwa_sanitize_google_verification( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	// Full meta tag pasted — pull the content="" value out.
	if ( false !== stripos( $raw, '<meta' ) && preg_match( '/content\s*=\s*["\']([^"\']+)["\']/i', $raw, $m ) ) {
		$raw = $m[1];
	}
	// Google verification tokens are URL-safe base64-ish; keep a conservative charset.
	return preg_replace( '/[^A-Za-z0-9_\-]/', '', $raw );
}

/**
 * Print the verification meta tag near the top of <head>.
 */
function ekwa_google_verification_emit() {
	$token = trim( (string) get_option( 'ekwa_google_verification', '' ) );
	if ( '' === $token ) {
		return;
	}
	printf( '<meta name="google-site-verification" content="%s" />' . "\n", esc_attr( $token ) );
}
add_action( 'wp_head', 'ekwa_google_verification_emit', 1 );
