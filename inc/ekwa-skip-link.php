<?php
/**
 * Configurable "Skip to content" link.
 *
 * WordPress' block-theme skip link auto-targets the first <main> element and
 * gives authors no control over where it points — on layouts assembled without
 * a <main> (common with converted mockups) it can land nowhere. When an author
 * sets a target anchor under Ekwa Settings → General → "Skip to content link",
 * we take over: WordPress' built-in skip link is disabled and ours is printed
 * as the first focusable element in the <body>, pointing at the author's
 * anchor. Leave the field empty to keep WordPress' default behavior untouched.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The configured skip-link target, normalized to a leading "#". Returns '' when
 * the feature is not configured (author left the field empty).
 *
 * @return string
 */
function ekwa_skip_link_anchor() {
	$raw = trim( (string) get_option( 'ekwa_skip_link_anchor', '' ) );
	if ( '' === $raw ) {
		return '';
	}
	// Keep only characters valid in an element id / URL fragment.
	$raw = preg_replace( '/[^A-Za-z0-9_\-]/', '', ltrim( $raw, '#' ) );
	return '' === $raw ? '' : '#' . $raw;
}

/**
 * The visible/screen-reader label for the skip link (falls back to a default).
 *
 * @return string
 */
function ekwa_skip_link_text() {
	$text = trim( (string) get_option( 'ekwa_skip_link_text', '' ) );
	return '' !== $text ? $text : __( 'Skip to content', 'ekwa' );
}

/**
 * When a custom anchor is set, disable WordPress' built-in block-template skip
 * link so ours is the only one.
 *
 * Core inserts its skip link into the template HTML from
 * get_the_block_template_html(), which runs at the very start of the template
 * canvas — before wp_head(). It only inserts when the wp_footer action below is
 * still hooked (see _block_template_add_skip_link()), so removing it on
 * template_redirect (which fires first) suppresses core's link cleanly.
 */
function ekwa_skip_link_disable_core() {
	if ( '' === ekwa_skip_link_anchor() ) {
		return; // Not configured — leave WordPress' default skip link in place.
	}
	remove_action( 'wp_footer', 'the_block_template_skip_link' );
}
add_action( 'template_redirect', 'ekwa_skip_link_disable_core' );

/**
 * Screen-reader-text styles for our skip link (mirrors WordPress core's
 * skip-link CSS). Printed only when the feature is configured, since core's
 * stylesheet is not enqueued once its own skip link is disabled.
 */
function ekwa_skip_link_styles() {
	if ( '' === ekwa_skip_link_anchor() ) {
		return;
	}
	echo '<style id="ekwa-skip-link-styles">'
		. '.skip-link.screen-reader-text{border:0;clip-path:inset(50%);height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute!important;width:1px;word-wrap:normal!important;word-break:normal!important}'
		. '.skip-link.screen-reader-text:focus{background-color:#eee;clip-path:none;color:#444;display:block;font-size:1em;height:auto;left:5px;line-height:normal;padding:15px 23px 14px;text-decoration:none;top:5px;width:auto;z-index:100000}'
		. "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS.
}
add_action( 'wp_head', 'ekwa_skip_link_styles', 1 );

/**
 * Print the skip link as the first thing after <body> (the ideal spot for a
 * skip link — the first focusable element on the page).
 */
function ekwa_skip_link_output() {
	$anchor = ekwa_skip_link_anchor();
	if ( '' === $anchor ) {
		return;
	}
	printf(
		'<a class="skip-link screen-reader-text" href="%s">%s</a>' . "\n",
		esc_url( $anchor ),
		esc_html( ekwa_skip_link_text() )
	);
}
add_action( 'wp_body_open', 'ekwa_skip_link_output' );
