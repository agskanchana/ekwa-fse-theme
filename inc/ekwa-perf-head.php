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
//
// Sourced from the active (child) theme at assets/css/critical.css so per-site
// critical CSS lives in the child and survives parent theme auto-updates. If the
// file is absent, no critical CSS is inlined (graceful bail below).
// ─────────────────────────────────────────────────────────────────────────────

function ekwa_perf_inline_critical_css() {
	if ( is_admin() ) {
		return;
	}

	// Opt-in: off unless explicitly enabled on the Performance settings tab.
	if ( ! get_option( 'ekwa_perf_critical_css', 0 ) ) {
		return;
	}

	// Prefer CSS pasted into settings; fall back to the child theme's
	// assets/css/critical.css so existing file-based setups keep working.
	$css = (string) get_option( 'ekwa_perf_critical_css_code', '' );
	if ( '' === trim( $css ) ) {
		$path = get_stylesheet_directory() . '/assets/css/critical.css';
		if ( ! file_exists( $path ) ) {
			return;
		}
		$css = (string) file_get_contents( $path );
	}

	if ( '' === trim( $css ) ) {
		return;
	}

	if ( function_exists( 'ekwa_inline_minify_css' ) && get_option( 'ekwa_perf_minify_inline', 0 ) ) {
		$css = ekwa_inline_minify_css( $css );
	}

	echo "<style id=\"ekwa-critical-css\">\n" . $css . "\n</style>\n";
}
add_action( 'wp_head', 'ekwa_perf_inline_critical_css', 1 );

// ─────────────────────────────────────────────────────────────────────────────
// 2. Stylesheet deferral — preload→swap pattern via style_loader_tag
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Mobile-only Font Awesome deferral toggle. When enabled, mobile devices
 * delay the FA download until the first user interaction (scroll/tap/click).
 * Desktop is unaffected.
 */
function ekwa_perf_defer_fa_mobile_enabled() {
	return (bool) get_option( 'ekwa_perf_defer_fa_mobile', 0 );
}

/**
 * Captured inside the style_loader_tag filter so the inline loader script
 * (printed later in wp_head) knows the resolved Font Awesome URL without
 * having to re-resolve it from the registered handle.
 */
function ekwa_perf_fa_href( $href = null ) {
	static $cached = null;
	if ( null !== $href ) {
		$cached = $href;
	}
	return $cached;
}

/**
 * Theme-owned style handles that should be deferred. Anything not on this list
 * loads synchronously as before — keeps third-party plugin styles untouched.
 */
function ekwa_perf_deferred_handles() {
	return array(
		'ekwa-style',
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

	// Special case: FA + mobile-defer toggle on. Suppress the preload tag
	// entirely; the inline loader (Section 2b) injects the stylesheet on
	// interaction for mobile, immediately for desktop.
	if ( 'font-awesome' === $handle && ekwa_perf_defer_fa_mobile_enabled() ) {
		ekwa_perf_fa_href( $href );
		// Keep a noscript fallback so JS-disabled clients still get icons.
		return '<noscript><link rel="stylesheet" href="' . esc_url( $href ) . '" /></noscript>' . "\n";
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
// 2b. Mobile FA loader — inline script that defers FA on mobile until the
//     first user interaction. Runs after the style_loader_tag filter has
//     captured the resolved href via ekwa_perf_fa_href().
// ─────────────────────────────────────────────────────────────────────────────

function ekwa_perf_emit_fa_mobile_loader() {
	if ( is_admin() || ! ekwa_perf_defer_fa_mobile_enabled() ) {
		return;
	}
	$href = ekwa_perf_fa_href();
	if ( ! $href ) {
		return;
	}
	$href_js = wp_json_encode( $href );
	?>
<script id="ekwa-fa-mobile-loader">
(function(){
	var href = <?php echo $href_js; ?>;
	function inject(){
		var l = document.createElement('link');
		l.rel = 'stylesheet';
		l.href = href;
		document.head.appendChild(l);
	}
	// Desktop / wide tablets: load immediately.
	if (!window.matchMedia || !window.matchMedia('(max-width: 768px)').matches) {
		inject();
		return;
	}
	// Mobile: wait for first scroll / tap / click / key press.
	var events = ['scroll','touchstart','click','keydown'];
	var loaded = false;
	function trigger(){
		if (loaded) return;
		loaded = true;
		inject();
		events.forEach(function(e){ window.removeEventListener(e, trigger); });
	}
	events.forEach(function(e){ window.addEventListener(e, trigger, {passive:true, once:true}); });
})();
</script>
	<?php
}
add_action( 'wp_print_footer_scripts', 'ekwa_perf_emit_fa_mobile_loader' );

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

// ─────────────────────────────────────────────────────────────────────────────
// 4b. Lean head — opt-in removal of heavier WP extras (Performance settings).
//
// Off by default because a few of these can matter to some plugins (Heartbeat
// for live features, jQuery Migrate for legacy scripts). Front-end only — the
// admin keeps Heartbeat, jQuery Migrate, etc.
// ─────────────────────────────────────────────────────────────────────────────

function ekwa_perf_lean_head_enabled() {
	return (bool) get_option( 'ekwa_perf_lean_head', 0 );
}

function ekwa_perf_lean_head() {
	if ( ! ekwa_perf_lean_head_enabled() ) {
		return;
	}

	// REST API discovery <link> (the REST API itself keeps working).
	remove_action( 'wp_head', 'rest_output_link_wp_head' );
	remove_action( 'template_redirect', 'rest_output_link_header', 11 );

	// Drop jQuery Migrate from the jquery dependency chain on the front end.
	add_action( 'wp_default_scripts', 'ekwa_perf_remove_jquery_migrate' );

	// Deregister Heartbeat and wp-embed on the front end.
	add_action( 'wp_enqueue_scripts', 'ekwa_perf_deregister_extra_scripts', 100 );
}
add_action( 'after_setup_theme', 'ekwa_perf_lean_head' );

/**
 * Strip jquery-migrate from the jquery handle's dependencies (front end only).
 *
 * @param WP_Scripts $scripts Core scripts registry.
 */
function ekwa_perf_remove_jquery_migrate( $scripts ) {
	if ( is_admin() ) {
		return;
	}
	$jquery = isset( $scripts->registered['jquery'] ) ? $scripts->registered['jquery'] : null;
	if ( $jquery && ! empty( $jquery->deps ) ) {
		$jquery->deps = array_diff( $jquery->deps, array( 'jquery-migrate' ) );
	}
}

/**
 * Deregister Heartbeat and wp-embed on the front end.
 */
function ekwa_perf_deregister_extra_scripts() {
	if ( is_admin() ) {
		return;
	}
	wp_deregister_script( 'heartbeat' );
	wp_deregister_script( 'wp-embed' );
}
