<?php
/**
 * Responsive layer — per-block device visibility + configurable breakpoints.
 *
 * Adds a "Responsive visibility" control (hide on desktop / tablet / mobile)
 * to every ekwa/* block. Because ekwa blocks are all server-rendered, the
 * chosen classes are injected onto the block's outermost element by a
 * render_block filter — no wrapper div, so layout (flex/grid children) is
 * never disturbed. The hide CSS is generated from globally configurable
 * breakpoints (Ekwa Settings → Performance) and inlined once in <head>.
 *
 * Breakpoint model (three bands):
 *   mobile  = viewport <= mobile max            (default 599px)
 *   tablet  = mobile max < viewport <= tablet max (default 600–1199px)
 *   desktop = viewport > tablet max             (default >= 1200px)
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Global responsive breakpoints, clamped to sane bounds.
 *
 * Defaults match the values previously hardcoded in the grid/carousel CSS
 * (1199 / 599) so existing sites behave identically until an admin changes
 * them.
 *
 * @return array{tablet:int, mobile:int}
 */
function ekwa_responsive_breakpoints() {
	$tablet = (int) get_option( 'ekwa_bp_tablet', 1199 );
	$mobile = (int) get_option( 'ekwa_bp_mobile', 599 );

	// Keep them ordered and within reason: mobile < tablet.
	$tablet = max( 480, min( 1920, $tablet ) );
	$mobile = max( 320, min( $tablet - 1, $mobile ) );

	return array(
		'tablet' => $tablet,
		'mobile' => $mobile,
	);
}

/**
 * Inject one or more classes onto the first real element of a rendered block.
 *
 * Skips any leading <style>/<script>/<link>/<template>/<noscript> (ekwa/div
 * prepends its own scoped <style>), then merges into an existing class
 * attribute or adds one right after the tag name.
 *
 * @param string $content Rendered block HTML.
 * @param string $classes Space-separated class list.
 * @return string
 */
function ekwa_responsive_inject_classes( $content, $classes ) {
	if ( '' === $classes || '' === trim( (string) $content ) ) {
		return $content;
	}

	// First opening tag that is a genuine element (not a leading asset tag).
	if ( ! preg_match(
		'/<(?!\/)(?!(?:style|script|link|template|noscript)\b)[a-z][a-z0-9-]*\b/i',
		$content,
		$m,
		PREG_OFFSET_CAPTURE
	) ) {
		return $content;
	}

	$tag_start = $m[0][1];
	$tag_end   = strpos( $content, '>', $tag_start );
	if ( false === $tag_end ) {
		return $content;
	}

	$open_tag = substr( $content, $tag_start, $tag_end - $tag_start + 1 );

	if ( preg_match( '/\sclass\s*=\s*"([^"]*)"/i', $open_tag, $cm ) ) {
		$new_open = str_replace( $cm[0], ' class="' . $cm[1] . ' ' . $classes . '"', $open_tag );
	} elseif ( preg_match( "/\sclass\s*=\s*'([^']*)'/i", $open_tag, $cm ) ) {
		$new_open = str_replace( $cm[0], " class='" . $cm[1] . ' ' . $classes . "'", $open_tag );
	} else {
		$new_open = preg_replace( '/^(<[a-z][a-z0-9-]*)/i', '$1 class="' . $classes . '"', $open_tag, 1 );
	}

	return substr( $content, 0, $tag_start ) . $new_open . substr( $content, $tag_end + 1 );
}

/**
 * Register the visibility attributes SERVER-SIDE on every ekwa/* block.
 *
 * The editor adds ekwaHideDesktop/Tablet/Mobile client-side (assets/js/
 * ekwa-responsive.js). Dynamic blocks preview via ServerSideRender, which
 * posts every attribute to the /block-renderer REST endpoint — and that
 * endpoint validates against the server-registered attributes with
 * additionalProperties:false. Without registering them here, those requests
 * fail with "Invalid parameter(s): attributes" and the block won't render in
 * the editor. This keeps the two schemas in sync.
 *
 * @param array  $args Block type registration args.
 * @param string $name Block name.
 * @return array
 */
function ekwa_responsive_register_attributes( $args, $name ) {
	if ( 0 !== strpos( $name, 'ekwa/' ) ) {
		return $args;
	}
	if ( empty( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
		$args['attributes'] = array();
	}
	$args['attributes']['ekwaHideDesktop'] = array( 'type' => 'boolean', 'default' => false );
	$args['attributes']['ekwaHideTablet']  = array( 'type' => 'boolean', 'default' => false );
	$args['attributes']['ekwaHideMobile']  = array( 'type' => 'boolean', 'default' => false );
	return $args;
}
add_filter( 'register_block_type_args', 'ekwa_responsive_register_attributes', 10, 2 );

/**
 * render_block filter: stamp ekwa-hide-* classes on ekwa blocks that opted in.
 *
 * Runs at priority 9 — before ekwa_inline_block_assets (10) prepends the
 * block's CSS — so the injector sees the real block markup first.
 *
 * @param string $block_content
 * @param array  $block
 * @return string
 */
function ekwa_responsive_visibility_render( $block_content, $block ) {
	if ( is_admin() ) {
		return $block_content;
	}
	$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
	if ( 0 !== strpos( $name, 'ekwa/' ) ) {
		return $block_content;
	}

	$attrs   = isset( $block['attrs'] ) ? $block['attrs'] : array();
	$classes = array();
	if ( ! empty( $attrs['ekwaHideDesktop'] ) ) { $classes[] = 'ekwa-hide-desktop'; }
	if ( ! empty( $attrs['ekwaHideTablet'] ) )  { $classes[] = 'ekwa-hide-tablet'; }
	if ( ! empty( $attrs['ekwaHideMobile'] ) )  { $classes[] = 'ekwa-hide-mobile'; }

	if ( empty( $classes ) ) {
		return $block_content;
	}

	return ekwa_responsive_inject_classes( $block_content, implode( ' ', $classes ) );
}
add_filter( 'render_block', 'ekwa_responsive_visibility_render', 9, 2 );

/**
 * Build the responsive-visibility CSS from the configured breakpoints.
 *
 * @return string Minified CSS (no <style> wrapper).
 */
function ekwa_responsive_visibility_css() {
	$bp          = ekwa_responsive_breakpoints();
	$mobile_max  = $bp['mobile'];
	$tablet_max  = $bp['tablet'];
	$tablet_min  = $mobile_max + 1;
	$desktop_min = $tablet_max + 1;

	return
		'@media (max-width:' . $mobile_max . 'px){.ekwa-hide-mobile{display:none !important}}' .
		'@media (min-width:' . $tablet_min . 'px) and (max-width:' . $tablet_max . 'px){.ekwa-hide-tablet{display:none !important}}' .
		'@media (min-width:' . $desktop_min . 'px){.ekwa-hide-desktop{display:none !important}}';
}

/**
 * Inline the visibility CSS in <head>.
 *
 * It is tiny (~200 bytes) and foundational (any page may hide a block), so it
 * ships in the head to avoid a flash of wrongly-shown content. Printed at
 * priority 6 — after critical CSS (1) but before the stylesheet preloads.
 */
function ekwa_responsive_print_head_css() {
	echo '<style id="ekwa-responsive">' . ekwa_responsive_visibility_css() . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'ekwa_responsive_print_head_css', 6 );

/**
 * Enqueue the editor script that adds the visibility control to ekwa blocks,
 * and hand it the current breakpoints so the inspector help text matches.
 */
function ekwa_responsive_enqueue_editor() {
	wp_enqueue_script(
		'ekwa-responsive',
		get_template_directory_uri() . '/assets/js/ekwa-responsive.js',
		array( 'wp-hooks', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-compose', 'wp-i18n' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-responsive.js' ),
		true
	);

	wp_localize_script(
		'ekwa-responsive',
		'ekwaResponsive',
		ekwa_responsive_breakpoints()
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_responsive_enqueue_editor' );
