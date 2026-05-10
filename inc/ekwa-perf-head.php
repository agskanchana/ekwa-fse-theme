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
 * Style handles that should be deferred via the preload→swap pattern.
 *
 * Default list covers parent + child theme stylesheets and Ekwa-family
 * conditional/block stylesheets. Extra handles can be added per-site via
 * the "Extra stylesheets to defer" textarea on the Performance tab — useful
 * for plugin-owned CSS where we don't ship a hard-coded handle.
 */
function ekwa_perf_deferred_handles() {
	$defaults = array(
		'ekwa-style',
		'ekwa-mobile',
		'ekwa-blocks-css',
		'ekwa-block-styles',
		'font-awesome',
		'ekwa-child-style',
		'ekwa-blog',
		'ekwa-header-menu-style',
		'mmenu-light',
	);
	$extra_raw = (string) get_option( 'ekwa_perf_extra_deferred_styles', '' );
	$extra     = array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $extra_raw ) ) );
	return array_unique( array_merge( $defaults, $extra ) );
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
