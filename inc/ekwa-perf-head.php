<?php
/**
 * Head-level performance hardening — always-on theme defaults.
 *
 * 1. Inlines critical CSS so first paint isn't blocked on external stylesheets.
 * 2. Defers all theme stylesheets via the preload→swap pattern.
 * 3. Emits resource hints (preconnect / dns-prefetch) for origins the theme talks to.
 * 4. Strips WordPress core <head> bloat the theme doesn't need.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Critical CSS — inline above-the-fold styles before any <link>
// ─────────────────────────────────────────────────────────────────────────────

function ekwa_perf_inline_critical_css() {
	if ( is_admin() ) {
		return;
	}
	$path = get_template_directory() . '/assets/css/critical.css';
	if ( ! file_exists( $path ) ) {
		return;
	}
	$css = file_get_contents( $path );
	if ( false === $css ) {
		return;
	}
	echo "<style id=\"ekwa-critical-css\">\n" . $css . "\n</style>\n";
}
add_action( 'wp_head', 'ekwa_perf_inline_critical_css', 1 );

// ─────────────────────────────────────────────────────────────────────────────
// 2. Stylesheet deferral — preload→swap pattern via style_loader_tag
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Theme-owned style handles that should be deferred. Anything not on this list
 * loads synchronously as before — keeps third-party plugin styles untouched.
 */
function ekwa_perf_deferred_handles() {
	return array(
		'ekwa-style',
		'ekwa-mobile',
		'ekwa-blocks-css',
		'ekwa-block-styles',
		'font-awesome',
	);
}

function ekwa_perf_defer_stylesheets( $html, $handle, $href, $media ) {
	if ( is_admin() ) {
		return $html;
	}
	if ( ! in_array( $handle, ekwa_perf_deferred_handles(), true ) ) {
		return $html;
	}
	if ( empty( $href ) ) {
		return $html;
	}

	$media_attr = $media && $media !== 'all' ? ' media="' . esc_attr( $media ) . '"' : '';

	return sprintf(
		'<link rel="preload" as="style" href="%1$s"%2$s onload="this.onload=null;this.rel=\'stylesheet\'" />' . "\n"
		. '<noscript><link rel="stylesheet" href="%1$s"%2$s /></noscript>' . "\n",
		esc_url( $href ),
		$media_attr
	);
}
add_filter( 'style_loader_tag', 'ekwa_perf_defer_stylesheets', 10, 4 );

// ─────────────────────────────────────────────────────────────────────────────
// 3. Resource hints — preconnect + conditional dns-prefetch
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Detects whether the current post's content contains any of the named blocks.
 * Reuses parse_blocks the same way ekwa_perf_collect_hero_image_blocks does.
 */
function ekwa_perf_post_has_block_in( $block_names ) {
	if ( ! is_singular() ) {
		return false;
	}
	$post = get_post();
	if ( ! $post || empty( $post->post_content ) || ! has_blocks( $post->post_content ) ) {
		return false;
	}
	$found  = false;
	$check  = function ( $blocks ) use ( &$check, $block_names, &$found ) {
		foreach ( $blocks as $block ) {
			if ( $found ) {
				return;
			}
			if ( isset( $block['blockName'] ) && in_array( $block['blockName'], $block_names, true ) ) {
				$found = true;
				return;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$check( $block['innerBlocks'] );
			}
		}
	};
	$check( parse_blocks( $post->post_content ) );
	return $found;
}

function ekwa_perf_resource_hints( $hints, $relation_type ) {
	if ( is_admin() ) {
		return $hints;
	}

	if ( 'preconnect' === $relation_type ) {
		$template_uri = get_template_directory_uri();
		$origin       = wp_parse_url( $template_uri, PHP_URL_SCHEME ) . '://' . wp_parse_url( $template_uri, PHP_URL_HOST );
		$site_origin  = wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST );
		// Only emit when assets are served from a different origin (CDN setups).
		if ( $origin && $origin !== $site_origin ) {
			$hints[] = array(
				'href'        => $origin,
				'crossorigin' => 'anonymous',
			);
		}
	}

	if ( 'dns-prefetch' === $relation_type ) {
		if ( ekwa_perf_post_has_block_in( array( 'ekwa/map' ) ) ) {
			$hints[] = '//maps.google.com';
			$hints[] = '//www.google.com';
		}
		if ( ekwa_perf_post_has_block_in( array( 'ekwa/elfsight-review' ) ) ) {
			$hints[] = '//elfsightcdn.com';
		}
	}

	return $hints;
}
add_filter( 'wp_resource_hints', 'ekwa_perf_resource_hints', 10, 2 );

// ─────────────────────────────────────────────────────────────────────────────
// 4. WordPress core bloat removal
// ─────────────────────────────────────────────────────────────────────────────

function ekwa_perf_remove_wp_bloat() {
	// Emoji scripts/styles — the theme uses no emoji-specific markup.
	remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles',     'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles',  'print_emoji_styles' );
	remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
	remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );

	// oEmbed discovery — theme renders embeds via custom blocks.
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'wp_head', 'wp_oembed_add_host_js' );

	// Generator meta + RSD + wlwmanifest — security + bytes.
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );

	// Shortlinks — not used, costs a header on every request.
	remove_action( 'wp_head',           'wp_shortlink_wp_head' );
	remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
}
add_action( 'after_setup_theme', 'ekwa_perf_remove_wp_bloat' );
