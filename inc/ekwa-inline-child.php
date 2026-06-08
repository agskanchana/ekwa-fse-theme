<?php
/**
 * Inline the active child theme's stylesheet and front-end JS.
 *
 * Two opt-in Performance toggles (see ekwa-settings.php) replace the child
 * theme's separate style.css / ekwa-child.js HTTP requests with inline markup:
 *   - CSS: always minified, printed in <head> after the enqueued styles so the
 *          original parent → child cascade order is preserved.
 *   - JS:  printed just before </body>, after the block-script inliner, so it
 *          still runs last.
 *
 * This lives in the PARENT theme (not the child) on purpose: the parent ships
 * the setting AND the behaviour, so the feature works on any site running this
 * theme without editing each child's functions.php. We simply dequeue the
 * child's own handles (ekwa-child-style / ekwa-child-js) and emit inline
 * versions read from the active stylesheet directory.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the child stylesheet should be inlined in <head>.
 */
function ekwa_inline_child_css_enabled() {
	return (bool) get_option( 'ekwa_perf_inline_child_css', 0 );
}

/**
 * Whether the child front-end JS should be inlined before </body>.
 */
function ekwa_inline_child_js_enabled() {
	return (bool) get_option( 'ekwa_perf_inline_child_js', 0 );
}

/**
 * Only meaningful when a child theme is active (parent !== stylesheet root).
 */
function ekwa_inline_child_active() {
	return get_template_directory() !== get_stylesheet_directory();
}

/**
 * Drop the child's separate CSS/JS requests when their inline toggle is on.
 *
 * Runs at priority 20 — after the child enqueues them at the default priority
 * 10 — so the handles are present to dequeue regardless of which child theme
 * (or theme version) registered them.
 */
function ekwa_inline_child_dequeue() {
	if ( ! ekwa_inline_child_active() ) {
		return;
	}
	if ( ekwa_inline_child_css_enabled() ) {
		wp_dequeue_style( 'ekwa-child-style' );
	}
	if ( ekwa_inline_child_js_enabled() ) {
		wp_dequeue_script( 'ekwa-child-js' );
	}
}
add_action( 'wp_enqueue_scripts', 'ekwa_inline_child_dequeue', 20 );

/**
 * Print the child stylesheet inline (always minified) in <head>.
 *
 * Priority 100 so it lands after the enqueued stylesheets, keeping the original
 * parent → child cascade order intact.
 */
function ekwa_inline_child_print_css() {
	if ( is_admin() || ! ekwa_inline_child_active() || ! ekwa_inline_child_css_enabled() ) {
		return;
	}
	$path = get_stylesheet_directory() . '/style.css';
	if ( ! is_readable( $path ) ) {
		return;
	}
	$css = (string) file_get_contents( $path );
	if ( '' === trim( $css ) ) {
		return;
	}
	// Always minify the inlined stylesheet (reuse the per-block minifier).
	if ( function_exists( 'ekwa_inline_minify_css' ) ) {
		$css = ekwa_inline_minify_css( $css );
	} else {
		$css = preg_replace( '#/\*.*?\*/#s', '', $css );
		$css = preg_replace( '#\s+#', ' ', $css );
		$css = preg_replace( '#\s*([{};,])\s*#', '$1', $css );
		$css = trim( str_replace( ';}', '}', $css ) );
	}
	if ( '' === trim( (string) $css ) ) {
		return;
	}
	echo '<style id="ekwa-child-style-inline">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'ekwa_inline_child_print_css', 100 );

/**
 * Print the child front-end JS inline just before </body>.
 *
 * Priority 21 — after the block-script inliner (ekwa_inline_print_footer_scripts,
 * priority 20) — so the child JS runs last. Minified only when the global
 * "Minify inline CSS/JS" option is on (mirrors how block JS is treated).
 */
function ekwa_inline_child_print_js() {
	if ( is_admin() || ! ekwa_inline_child_active() || ! ekwa_inline_child_js_enabled() ) {
		return;
	}
	$path = get_stylesheet_directory() . '/assets/js/ekwa-child.js';
	if ( ! is_readable( $path ) ) {
		return;
	}
	$js = (string) file_get_contents( $path );
	if ( '' === trim( $js ) ) {
		return;
	}
	if ( function_exists( 'ekwa_inline_minify_enabled' ) && ekwa_inline_minify_enabled()
		&& function_exists( 'ekwa_inline_minify_js' ) ) {
		$js = ekwa_inline_minify_js( $js );
	}
	echo '<script id="ekwa-child-js-inline">' . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_footer', 'ekwa_inline_child_print_js', 21 );
