<?php
/**
 * Image performance: lazy loading, hero preload, decoding, srcset toggle.
 *
 * Settings live on the "Performance" tab (inc/ekwa-settings.php).
 * The actual `<img>` rewriting happens in ekwa_render_image_block()
 * (inc/ekwa-blocks.php) — this file just exposes the option getters,
 * conditionally enqueues lazysizes, and emits hero preload hints.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Option getters (mirror the ekwa_webp_*_enabled() pattern)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Lazy loading mode.
 *
 * @return string One of 'off', 'native', 'lazysizes'. Defaults to 'native'.
 */
function ekwa_perf_lazy_mode() {
	$mode = (string) get_option( 'ekwa_perf_lazy_mode', 'native' );
	if ( ! in_array( $mode, array( 'off', 'native', 'lazysizes' ), true ) ) {
		return 'native';
	}
	return $mode;
}

function ekwa_perf_srcset_enabled() {
	return (bool) get_option( 'ekwa_perf_srcset', 1 );
}

function ekwa_perf_preload_hero_enabled() {
	return (bool) get_option( 'ekwa_perf_preload_hero', 1 );
}

function ekwa_perf_decoding_async_enabled() {
	return (bool) get_option( 'ekwa_perf_decoding_async', 1 );
}

// ─────────────────────────────────────────────────────────────────────────────
// lazysizes enqueue
// ─────────────────────────────────────────────────────────────────────────────

function ekwa_perf_inline_lazysizes() {
	if ( ekwa_perf_lazy_mode() !== 'lazysizes' ) {
		return;
	}

	// unveilhooks extends lazysizes with <video>, <iframe poster>, CSS-bg, etc.
	// It must run before the core lib, so it's inlined first. Both are inlined
	// (no separate request) — in <head> by default so lazy loading starts as
	// early as possible, or just before </body> when the footer option is on.
	ekwa_inline_print_script( 'assets/js/ls.unveilhooks.min.js' );
	ekwa_inline_print_script( 'assets/js/lazysizes.min.js' );
}
add_action(
	get_option( 'ekwa_perf_lazysizes_footer', 0 ) ? 'wp_footer' : 'wp_head',
	'ekwa_perf_inline_lazysizes',
	8
);

// ─────────────────────────────────────────────────────────────────────────────
// Hero preload — emits <link rel="preload" as="image"> in <head>
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Recursively collect ekwa/image blocks flagged hero=true from a block tree.
 */
function ekwa_perf_collect_hero_image_blocks( $blocks, &$found ) {
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		if ( isset( $block['blockName'] ) && $block['blockName'] === 'ekwa/image' ) {
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			if ( ! empty( $attrs['hero'] ) && ! empty( $attrs['mediaId'] ) ) {
				$found[] = $attrs;
			}
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			ekwa_perf_collect_hero_image_blocks( $block['innerBlocks'], $found );
		}
	}
}

/**
 * Recursively collect ekwa/div blocks whose background image is flagged
 * preloadBg=true. These paint a CSS background-image that is the page's LCP,
 * so we emit a high-priority preload hint for them (a background can't carry
 * fetchpriority itself — the <link rel=preload> is the supported mechanism).
 */
function ekwa_perf_collect_bg_preload_blocks( $blocks, &$found ) {
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		if ( isset( $block['blockName'] ) && $block['blockName'] === 'ekwa/div' ) {
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			if ( ! empty( $attrs['preloadBg'] ) && ! empty( $attrs['backgroundImage'] ) ) {
				$found[] = $attrs;
			}
		}
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			ekwa_perf_collect_bg_preload_blocks( $block['innerBlocks'], $found );
		}
	}
}

/**
 * Whether this request emitted an LCP image preload.
 *
 * Read by ekwa_perf_preload_child_style() so a stylesheet preload never gets
 * queued ahead of the image the page is actually measured on.
 *
 * @param bool $set Pass true to record that an image preload was emitted.
 * @return bool
 */
function ekwa_perf_image_preload_emitted( $set = false ) {
	static $emitted = false;
	if ( $set ) {
		$emitted = true;
	}
	return $emitted;
}

function ekwa_perf_emit_hero_preloads() {
	if ( ! is_singular() ) {
		return;
	}

	$post = get_post();
	if ( ! $post ) {
		return;
	}

	$hero_on   = ekwa_perf_preload_hero_enabled();
	$banner_on = (bool) get_option( 'ekwa_perf_preload_banner', 0 );

	$has_blocks = ! empty( $post->post_content ) && has_blocks( $post->post_content );
	$scan_hero  = $hero_on && $has_blocks;
	// Per-block opt-in (independent of the global hero/banner toggles). Cheap
	// substring guard so pages without a preloaded background never parse blocks
	// just for this pass.
	$scan_div   = $has_blocks && false !== strpos( $post->post_content, '"preloadBg"' );
	$scan_banner = $banner_on && has_post_thumbnail( $post );

	if ( ! $scan_hero && ! $scan_div && ! $scan_banner ) {
		return;
	}

	$webp_supports = function_exists( 'ekwa_webp_browser_supports' ) && ekwa_webp_browser_supports();
	$srcset_on     = ekwa_perf_srcset_enabled();
	$emitted       = array();

	$parsed = ( $scan_hero || $scan_div ) ? parse_blocks( $post->post_content ) : array();

	// Hero ekwa/image blocks declared in the post content.
	$found = array();
	if ( $scan_hero ) {
		ekwa_perf_collect_hero_image_blocks( $parsed, $found );
	}

	foreach ( $found as $attrs ) {
		$media_id = (int) $attrs['mediaId'];
		if ( isset( $emitted[ $media_id ] ) ) {
			continue;
		}
		$emitted[ $media_id ] = true;

		$src = ! empty( $attrs['src'] ) ? (string) $attrs['src'] : wp_get_attachment_image_url( $media_id, 'full' );
		if ( ! $src ) {
			continue;
		}

		$srcset = '';
		$sizes  = '';
		if ( $srcset_on ) {
			$srcset = wp_get_attachment_image_srcset( $media_id, 'full' );
			if ( $srcset ) {
				$width = isset( $attrs['width'] ) ? (int) $attrs['width'] : 0;
				$sizes = $width > 0
					? '(max-width: ' . $width . 'px) 100vw, ' . $width . 'px'
					: '100vw';
			}
		}

		// Route through WebP companion when the browser advertises support.
		if ( $webp_supports && function_exists( 'ekwa_webp_url_for' ) ) {
			$src = ekwa_webp_url_for( $src );
			if ( $srcset && function_exists( 'ekwa_webp_rewrite_srcset' ) ) {
				$srcset = ekwa_webp_rewrite_srcset( $srcset );
			}
		}

		echo '<link rel="preload" as="image"';
		if ( $srcset ) {
			echo ' imagesrcset="' . esc_attr( $srcset ) . '"';
			if ( $sizes ) {
				echo ' imagesizes="' . esc_attr( $sizes ) . '"';
			}
		} else {
			echo ' href="' . esc_url( $src ) . '"';
		}
		echo " fetchpriority=\"high\">\n";
		ekwa_perf_image_preload_emitted( true );
	}

	// Inner-banner background (the post's featured image) is the inner-page LCP.
	// ekwa/inner-banner renders it as an art-directed <picture>, so the preload
	// must be art-directed the same way: one media-scoped <link> per rung of the
	// ladder, ranges mutually exclusive so exactly one of them ever fetches.
	//
	// This replaced a single `imagesrcset` + `imagesizes="100vw"` preload. That
	// form resolves by viewport × device pixel ratio while <picture> resolves by
	// media query alone, so the two disagreed on any DPR ≥ 2 phone: the preload
	// pulled the 1536w file while the <picture> painted the 768w one. The result
	// was a wasted download eating mobile bandwidth *and* an LCP image the
	// preload scanner never fetched — it waited on the parser reaching the
	// <picture>, which is what PageSpeed reports as LCP "resource load delay".
	//
	// ekwa_inner_banner_bg_sources() is the shared ladder, so these can't drift
	// apart again if the breakpoints or registered sizes change.
	if ( $banner_on && has_post_thumbnail( $post ) ) {
		$media_id = (int) get_post_thumbnail_id( $post );
		$sources  = ( $media_id && ! isset( $emitted[ $media_id ] ) && function_exists( 'ekwa_inner_banner_bg_sources' ) )
			? ekwa_inner_banner_bg_sources( $media_id )
			: null;

		if ( $sources ) {
			$emitted[ $media_id ] = true;

			// Lower bound of the current range = previous rung's breakpoint. The
			// +0.02px step mirrors the <picture> handoff without leaving a gap a
			// fractional viewport width could fall into.
			$prev_max = 0;
			foreach ( $sources['rungs'] as $rung ) {
				$media = 0 === $prev_max
					? '(max-width: ' . $rung['max'] . 'px)'
					: '(min-width: ' . ( $prev_max + 0.02 ) . 'px) and (max-width: ' . $rung['max'] . 'px)';
				echo '<link rel="preload" as="image" href="' . esc_url( $rung['url'] ) . '"'
					. ' media="' . esc_attr( $media ) . '" fetchpriority="high">' . "\n";
				$prev_max = $rung['max'];
			}

			// Widest viewports fall through to the full image, exactly as the
			// <picture>'s fallback <img> does.
			$full_media = $prev_max > 0 ? ' media="(min-width: ' . ( $prev_max + 0.02 ) . 'px)"' : '';
			echo '<link rel="preload" as="image" href="' . esc_url( $sources['full']['url'] ) . '"'
				. $full_media . ' fetchpriority="high">' . "\n";
			ekwa_perf_image_preload_emitted( true );
		}
	}

	// ekwa/div backgrounds explicitly flagged as the LCP (preloadBg). A CSS
	// background can't carry fetchpriority itself, so a high-priority preload
	// hint is how the browser is told to fetch it first (the PageSpeed "LCP
	// request discovery / fetchpriority=high" audit). Routed through the WebP
	// companion so it matches the painted background exactly.
	if ( $scan_div ) {
		$bg_blocks = array();
		ekwa_perf_collect_bg_preload_blocks( $parsed, $bg_blocks );
		foreach ( $bg_blocks as $attrs ) {
			$src = isset( $attrs['backgroundImage'] ) ? esc_url_raw( (string) $attrs['backgroundImage'] ) : '';
			if ( ! $src ) {
				continue;
			}
			$key = 'bg:' . $src;
			if ( isset( $emitted[ $key ] ) ) {
				continue;
			}
			$emitted[ $key ] = true;

			if ( $webp_supports && function_exists( 'ekwa_webp_url_for' ) ) {
				$src = ekwa_webp_url_for( $src );
			}
			echo '<link rel="preload" as="image" href="' . esc_url( $src ) . "\" fetchpriority=\"high\">\n";
			ekwa_perf_image_preload_emitted( true );
		}
	}
}
add_action( 'wp_head', 'ekwa_perf_emit_hero_preloads', 1 );

// ─────────────────────────────────────────────────────────────────────────────
// Child stylesheet — early discovery.
//
// When the child CSS is served as a file (the inline toggle off), its <link> is
// printed with the rest of the style queue, which lands it *after* every inline
// <style> WordPress, the theme and plugins put in <head>. Measured on a live
// site: the <link> sat 10,687 gzip bytes into an 11,878 gzip <head> — 90% of the
// way through. The preload scanner can't see it until nearly the whole head has
// arrived, so a render-blocking stylesheet gets fetched serially *after* the
// document instead of alongside it. PageSpeed reports that as "render-blocking
// requests"; it gates FCP, and FCP gates LCP.
//
// A preload hint next to the hero image preload moves discovery into the first
// few hundred bytes. Discovery only — the stylesheet still applies through its
// own <link>, so unlike a preload→swap defer there's no FOUC and no layout
// shift. If the href doesn't match the <link> byte for byte the browser
// downloads the file twice, so both are built from the same resolver below.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Handle the child theme's stylesheet is registered under.
 *
 * ekwa-child-generator.php enqueues 'ekwa-child-style'; the filter is there for
 * hand-built children that chose a different handle.
 *
 * @return string
 */
function ekwa_perf_child_style_handle() {
	return (string) apply_filters( 'ekwa_perf_child_style_handle', 'ekwa-child-style' );
}

/**
 * Version the child stylesheet by file mtime.
 *
 * ekwa-child-generator.php:249 ships `filemtime()`, but children in the wild
 * hardcode a constant and drift from it — a live site is serving `?ver=1.5.2`
 * against a style.css edited well after that — so an edited stylesheet can stay
 * cached indefinitely. Overriding parent-side fixes every child at once.
 *
 * Runs before the preload href is built so the two URLs stay identical.
 */
function ekwa_perf_version_child_style() {
	$handle = ekwa_perf_child_style_handle();
	$styles = wp_styles();
	if ( ! isset( $styles->registered[ $handle ] ) ) {
		return;
	}

	// Only touch a handle that really points at the active stylesheet, so a
	// child that repurposed the handle for something else keeps its own version.
	$src = $styles->registered[ $handle ]->src;
	if ( ! $src || ! is_string( $src ) || 'style.css' !== basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) ) {
		return;
	}

	$path  = get_stylesheet_directory() . '/style.css';
	$mtime = is_readable( $path ) ? filemtime( $path ) : false;
	if ( $mtime ) {
		$styles->registered[ $handle ]->ver = (string) $mtime;
	}
}
add_action( 'wp_enqueue_scripts', 'ekwa_perf_version_child_style', 20 );

/**
 * Whether the child stylesheet should be served as a minified copy.
 *
 * Off by default: this swaps the one file every client site's appearance
 * depends on, so it's opted into per site after a visual check rather than
 * arriving with a theme update.
 */
function ekwa_perf_minify_child_css_enabled() {
	return (bool) get_option( 'ekwa_perf_minify_child_css', 0 );
}

/**
 * Absolute URL for one `url()` reference, resolved against the child theme.
 *
 * @param string $rel      Reference as written in the stylesheet.
 * @param string $base_uri Directory the stylesheet was authored in (no trailing slash).
 * @return string
 */
function ekwa_perf_absolutize_css_url( $rel, $base_uri ) {
	// Keep ?query / #fragment out of the path arithmetic, then reattach.
	$suffix = '';
	$cut    = strcspn( $rel, '?#' );
	if ( $cut < strlen( $rel ) ) {
		$suffix = substr( $rel, $cut );
		$rel    = substr( $rel, 0, $cut );
	}

	// Split scheme+host off so "../" can never chew past the site root.
	$prefix = '';
	$path   = rtrim( $base_uri, '/' );
	if ( preg_match( '#^(https?://[^/]+|//[^/]+)#i', $path, $m ) ) {
		$prefix = $m[1];
		$path   = substr( $path, strlen( $prefix ) );
	}

	$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
	foreach ( explode( '/', $rel ) as $segment ) {
		if ( '' === $segment || '.' === $segment ) {
			continue;
		}
		if ( '..' === $segment ) {
			array_pop( $segments );
			continue;
		}
		$segments[] = $segment;
	}

	return $prefix . '/' . implode( '/', $segments ) . $suffix;
}

/**
 * Rewrite relative `url()` references so the stylesheet survives being moved.
 *
 * The minified copy is served from uploads/, not the theme directory, so every
 * relative path in it would otherwise resolve against the wrong folder and 404.
 * Absolute, protocol-relative, root-relative, `data:` and bare-fragment refs
 * (`url(#filter)`, used by SVG filters) already resolve correctly and are left
 * exactly as written.
 *
 * @param string $css      Stylesheet source.
 * @param string $base_uri Directory the stylesheet was authored in.
 * @return string
 */
function ekwa_perf_absolutize_css_urls( $css, $base_uri ) {
	return (string) preg_replace_callback(
		'#\b(url)\(\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^)]*?)\s*\)#i',
		static function ( $m ) use ( $base_uri ) {
			$raw   = trim( $m[2] );
			$quote = '';
			if ( strlen( $raw ) > 1 && ( '"' === $raw[0] || "'" === $raw[0] ) && substr( $raw, -1 ) === $raw[0] ) {
				$quote = $raw[0];
				$raw   = substr( $raw, 1, -1 );
			}

			$trimmed = trim( $raw );
			if ( '' === $trimmed
				|| '/' === $trimmed[0]        // root-relative and protocol-relative
				|| '#' === $trimmed[0]        // in-document reference
				|| preg_match( '#^[a-z][a-z0-9+.-]*:#i', $trimmed ) ) { // data:, http:, blob:, …
				return $m[0];
			}

			// $m[1] rather than a literal, so `URL(` keeps the author's casing.
			return $m[1] . '(' . $quote . ekwa_perf_absolutize_css_url( $trimmed, $base_uri ) . $quote . ')';
		},
		(string) $css
	);
}

/**
 * Brace counts with comments and quoted strings removed.
 *
 * Used as the safety check on minification. Counting raw braces would false-
 * alarm on any stylesheet containing `/* { *\/` or a brace inside a string,
 * because the minifier legitimately drops comments.
 *
 * @param string $css Stylesheet.
 * @return array{0:int,1:int} Open and close brace counts.
 */
function ekwa_perf_css_brace_signature( $css ) {
	$stripped = preg_replace(
		'#/\*.*?\*/|"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'#s',
		'',
		(string) $css
	);
	return array( substr_count( (string) $stripped, '{' ), substr_count( (string) $stripped, '}' ) );
}

/**
 * Directory the minified child stylesheet is cached in.
 *
 * @return array{dir:string,url:string}|false False when uploads is unusable.
 */
function ekwa_perf_child_style_cache_dir() {
	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
		return false;
	}
	return array(
		'dir' => $uploads['basedir'] . '/ekwa-perf',
		'url' => $uploads['baseurl'] . '/ekwa-perf',
	);
}

/**
 * Build (or reuse) the minified copy of the child stylesheet.
 *
 * Cached under the source file's mtime, so an edited stylesheet regenerates on
 * its own and a stale copy can never be served. Generation happens once per
 * mtime, on whichever front-end request arrives first; concurrent builds are
 * harmless because they produce identical bytes and land via rename().
 *
 * Returns '' on ANY problem — unusable uploads, unreadable source, a minifier
 * that changed the rule count — so the caller leaves the original file in place.
 * A slightly larger stylesheet is a non-event; a broken one is every page.
 *
 * @param string $source Absolute path to the child style.css.
 * @param int    $mtime  Its modification time.
 * @return string URL of the minified copy, or '' to fall back.
 */
function ekwa_perf_build_minified_child_style( $source, $mtime ) {
	$cache = ekwa_perf_child_style_cache_dir();
	if ( ! $cache ) {
		return '';
	}

	$file = 'child-style-' . (int) $mtime . '.css';
	$path = $cache['dir'] . '/' . $file;
	if ( is_readable( $path ) && filesize( $path ) > 0 ) {
		return $cache['url'] . '/' . $file;
	}

	if ( ! wp_mkdir_p( $cache['dir'] ) ) {
		return '';
	}

	$raw = file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $raw || '' === trim( $raw ) ) {
		return '';
	}

	$css = ekwa_perf_absolutize_css_urls( $raw, get_stylesheet_directory_uri() );
	$min = ekwa_inline_minify_css( $css );

	// Refuse to swap unless the result is smaller AND structurally identical.
	$before = ekwa_perf_css_brace_signature( $css );
	$after  = ekwa_perf_css_brace_signature( $min );
	if ( '' === trim( (string) $min )
		|| strlen( $min ) >= strlen( $raw )
		|| $before !== $after ) {
		return '';
	}

	// Write somewhere else and rename, so a partial write is never served.
	$tmp = $path . '.' . wp_generate_password( 8, false ) . '.tmp';
	if ( false === file_put_contents( $tmp, $min ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return '';
	}
	if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		// Another request may have won the race and already published the file.
		if ( ! is_readable( $path ) || ! filesize( $path ) ) {
			return '';
		}
	}

	// Drop copies made from earlier revisions of the stylesheet.
	foreach ( (array) glob( $cache['dir'] . '/child-style-*.css' ) as $old ) {
		if ( $old && basename( $old ) !== $file ) {
			@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	return $cache['url'] . '/' . $file;
}

/**
 * Point the child stylesheet handle at its minified copy.
 *
 * Priority 21 — after ekwa_perf_version_child_style() has settled the version at
 * 20, and well before the wp_head preload at priority 1. Only the registry is
 * touched, so ekwa_perf_style_href() below rebuilds the preload href from the
 * same source of truth and the two URLs cannot drift apart.
 *
 * PageSpeed values this at roughly 20ms (~4KB at mobile throttling) — it clears
 * the "Minify CSS" audit rather than moving the score.
 */
function ekwa_perf_minify_child_style() {
	if ( ! ekwa_perf_minify_child_css_enabled() || ! function_exists( 'ekwa_inline_minify_css' ) ) {
		return;
	}
	// Inline mode dequeues the handle and minifies on its own — nothing to do.
	if ( function_exists( 'ekwa_inline_child_css_enabled' ) && ekwa_inline_child_css_enabled() ) {
		return;
	}

	$handle = ekwa_perf_child_style_handle();
	$styles = wp_styles();
	if ( ! isset( $styles->registered[ $handle ] ) ) {
		return;
	}

	// Same guard as the versioner: only act on a handle really pointing at the
	// active style.css, so a child that repurposed the handle is left alone.
	$src = $styles->registered[ $handle ]->src;
	if ( ! $src || ! is_string( $src ) || 'style.css' !== basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) ) {
		return;
	}

	$source = get_stylesheet_directory() . '/style.css';
	$mtime  = is_readable( $source ) ? filemtime( $source ) : false;
	if ( ! $mtime ) {
		return;
	}

	$url = ekwa_perf_build_minified_child_style( $source, $mtime );
	if ( '' === $url ) {
		return; // Fall back to the original file.
	}

	$styles->registered[ $handle ]->src = $url;
	$styles->registered[ $handle ]->ver = (string) $mtime;
}
add_action( 'wp_enqueue_scripts', 'ekwa_perf_minify_child_style', 21 );

/**
 * The exact href WordPress will print for a registered style handle.
 *
 * Mirrors WP_Styles::do_item() — base_url prefixing, the ver query arg and the
 * style_loader_src filter — because a preload only warms the cache if its URL
 * matches the stylesheet <link> exactly. Anything else downloads the file twice.
 *
 * @param string $handle Registered style handle.
 * @return string Resolved URL, or '' when the handle has no real file.
 */
function ekwa_perf_style_href( $handle ) {
	$styles = wp_styles();
	if ( ! isset( $styles->registered[ $handle ] ) ) {
		return '';
	}

	$obj = $styles->registered[ $handle ];
	$src = $obj->src;
	if ( ! $src || ! is_string( $src ) ) {
		return '';
	}

	if ( null === $obj->ver ) {
		$ver = '';
	} else {
		$ver = $obj->ver ? $obj->ver : $styles->default_version;
	}
	if ( isset( $styles->args[ $handle ] ) ) {
		$ver = $ver ? $ver . '&amp;' . $styles->args[ $handle ] : $styles->args[ $handle ];
	}

	if ( ! preg_match( '|^(https?:)?//|', $src )
		&& ! ( $styles->content_url && 0 === strpos( $src, $styles->content_url ) ) ) {
		$src = $styles->base_url . $src;
	}
	if ( ! empty( $ver ) ) {
		$src = add_query_arg( 'ver', $ver, $src );
	}

	/** This filter is documented in wp-includes/class-wp-styles.php */
	$src = apply_filters( 'style_loader_src', $src, $handle );

	return is_string( $src ) ? $src : '';
}

/**
 * Emit the child stylesheet preload in the first few hundred bytes of <head>.
 *
 * Priority 1, registered after ekwa_perf_emit_hero_preloads() so the LCP image
 * hint still comes first. No is_singular() guard — every template needs the
 * stylesheet, not just single posts.
 */
function ekwa_perf_preload_child_style() {
	if ( is_admin() || ! apply_filters( 'ekwa_perf_preload_child_style', true ) ) {
		return;
	}

	// Optional: yield to the LCP image preload. OFF by default.
	//
	// The theory is sound in the abstract — <link rel=preload as=style> is Highest
	// priority in Chrome while a fetchpriority="high" image is only High, so this
	// does put ~21 KB of CSS ahead of an ~8 KB banner on a throttled connection.
	// It was added after a live run scored 88 (LCP 3.6s) with both preloads
	// present.
	//
	// It defaults to false because the very next measurement contradicted it: a
	// second page on the SAME build, with the identical banner file, head size and
	// both preloads, scored 99 at LCP 1.5s. Whatever cost that 88, it was not the
	// stylesheet preload — and that page still reported 470ms of render-blocking
	// savings available, i.e. the preload is doing useful work there and
	// suppressing it would give ground back.
	//
	// Kept behind a filter rather than deleted so the hypothesis can be re-tested
	// cheaply if repeat runs on a single URL ever implicate it.
	if ( ekwa_perf_image_preload_emitted()
		&& apply_filters( 'ekwa_perf_preload_child_style_yields_to_image', false ) ) {
		return;
	}

	// Inline mode dequeues the handle, and a handle with no src has nothing to
	// preload — either way this no-ops, so the delivery toggle stays the single
	// source of truth and this never needs a setting of its own.
	$handle = ekwa_perf_child_style_handle();
	if ( ! wp_style_is( $handle, 'enqueued' ) ) {
		return;
	}

	$href = ekwa_perf_style_href( $handle );
	if ( '' === $href ) {
		return;
	}

	echo '<link rel="preload" as="style" href="' . esc_url( $href ) . "\">\n";
}
add_action( 'wp_head', 'ekwa_perf_preload_child_style', 1 );

// ─────────────────────────────────────────────────────────────────────────────
// Site logo — never lazy.
//
// The custom logo is usually in the header and often the LCP element. Force
// loading="eager" via the attachment-image attributes filter at priority 5
// (before WebP at 20 and the lazysizes rewriter at 25). The lazysizes
// rewriter already skips any tag with loading="eager", so this single check
// covers both native and lazysizes modes.
// ─────────────────────────────────────────────────────────────────────────────

function ekwa_perf_force_eager_for_logo( $attr ) {
	if ( ! empty( $attr['class'] ) && preg_match( '/\b(custom-logo|site-logo)\b/', $attr['class'] ) ) {
		$attr['loading'] = 'eager';
	}
	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'ekwa_perf_force_eager_for_logo', 5 );

// ─────────────────────────────────────────────────────────────────────────────
// Site-wide lazysizes rewriter — runs only when lazy mode is `lazysizes`.
//
// Walks every <img> in rendered HTML and converts:
//   src      → data-src (with 1×1 transparent GIF placeholder)
//   srcset   → data-srcset
//   class    → adds `lazyload`
//   loading  → stripped (lazysizes manages it)
// Appends a <noscript> fallback with the original tag for SEO / no-JS clients.
//
// Skips when the tag looks like an LCP candidate (loading="eager" or
// fetchpriority="high") and when it's already been lazysized (data-src or
// .lazyload class present), so it's safe to chain through multiple filter
// surfaces without double-rewriting.
//
// Hook priority is 25 so the WebP filters (priority 20) run first — that way
// the data-src URLs already point at .webp companions when the browser
// supports them.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * 1×1 transparent GIF used as the placeholder src.
 */
function ekwa_perf_lazysize_placeholder() {
	return 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';
}

/**
 * Rewrite a single <img> tag for lazysizes. Idempotent and hero-safe.
 */
function ekwa_perf_lazysize_img_tag( $tag ) {
	// Front-end only. lazysizes JS is never enqueued in wp-admin, so a
	// lazysized <img> there would show only the blank 1×1 placeholder — that's
	// what was blanking the Site Logo / Publisher Logo / Share Image previews on
	// the Ekwa Settings → Branding tab (they render via wp_get_attachment_image,
	// which this rewriter also filters).
	if ( is_admin() ) {
		return $tag;
	}
	if ( ekwa_perf_lazy_mode() !== 'lazysizes' ) {
		return $tag;
	}
	if ( ! is_string( $tag ) || strpos( $tag, '<img' ) === false ) {
		return $tag;
	}

	// Idempotency — already lazysized somewhere upstream.
	if ( preg_match( '/\sdata-src=/i', $tag ) ) {
		return $tag;
	}
	if ( preg_match( '/\sclass=["\'][^"\']*\blazyload\b/i', $tag ) ) {
		return $tag;
	}

	// Skip LCP candidates so the hero stays immediate.
	if ( preg_match( '/\sloading\s*=\s*["\']eager["\']/i', $tag ) ) {
		return $tag;
	}
	if ( preg_match( '/\sfetchpriority\s*=\s*["\']high["\']/i', $tag ) ) {
		return $tag;
	}

	// Need a src to rewrite.
	if ( ! preg_match( '/\ssrc=["\'][^"\']+["\']/i', $tag ) ) {
		return $tag;
	}

	$original    = $tag;
	$placeholder = ekwa_perf_lazysize_placeholder();

	// src → src=PLACEHOLDER + data-src=ORIGINAL
	$tag = preg_replace_callback(
		'/\ssrc=(["\'])([^"\']+)\1/i',
		function ( $m ) use ( $placeholder ) {
			return ' src="' . $placeholder . '" data-src=' . $m[1] . $m[2] . $m[1];
		},
		$tag,
		1
	);

	// srcset → data-srcset (drop the eager-load srcset entirely)
	$tag = preg_replace_callback(
		'/\ssrcset=(["\'])([^"\']+)\1/i',
		function ( $m ) {
			return ' data-srcset=' . $m[1] . $m[2] . $m[1];
		},
		$tag,
		1
	);

	// Append `lazyload` to existing class attr, or add one.
	if ( preg_match( '/\sclass=(["\'])([^"\']*)\1/i', $tag ) ) {
		$tag = preg_replace_callback(
			'/\sclass=(["\'])([^"\']*)\1/i',
			function ( $m ) {
				return ' class=' . $m[1] . trim( $m[2] . ' lazyload' ) . $m[1];
			},
			$tag,
			1
		);
	} else {
		$tag = preg_replace( '/<img/i', '<img class="lazyload"', $tag, 1 );
	}

	// Strip native loading attribute — lazysizes manages it via JS.
	$tag = preg_replace( '/\sloading=(["\'])[^"\']*\1/i', '', $tag, 1 );

	// Removing `loading` also removes the only clue WordPress has that this image
	// is deferred, so replace it with one core understands.
	$tag = ekwa_perf_mark_lazy_for_core( $tag );

	// noscript fallback uses the unmutated tag so SEO crawlers and
	// JS-disabled clients still see a working image.
	return $tag . '<noscript>' . $original . '</noscript>';
}

/**
 * render_block hook — rewrites every <img> in block output. Skips ekwa/image
 * because that block's render callback already emits lazysized markup directly.
 */
function ekwa_perf_lazysize_block_html( $html, $block ) {
	if ( ekwa_perf_lazy_mode() !== 'lazysizes' ) {
		return $html;
	}
	$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
	if ( $name === 'ekwa/image' ) {
		return $html;
	}
	if ( strpos( $html, '<img' ) === false ) {
		return $html;
	}
	return ekwa_perf_lazysize_html( $html );
}
add_filter( 'render_block', 'ekwa_perf_lazysize_block_html', 25, 2 );

/**
 * Lazysize every <img> in a block of HTML, EXCEPT the ones inside a <noscript>
 * fallback.
 *
 * render_block fires for every block, so an image nested inside containers is
 * seen again by each ancestor. The outer tag is protected by the data-src /
 * .lazyload guards in ekwa_perf_lazysize_img_tag(), but the copy inside the
 * <noscript> fallback is deliberately pristine — it passed every guard and got
 * rewritten again on each pass, so an image five containers deep came out as
 * five duplicated <img> tags wrapped in five nested <noscript> elements.
 * (Converted mockup headers nest that deeply as a matter of course.)
 *
 * @param string $html Rendered HTML.
 * @return string
 */
function ekwa_perf_lazysize_html( $html ) {
	// Odd indexes are the captured <noscript> blocks — passed through untouched.
	$chunks = preg_split( '#(<noscript\b[^>]*>.*?</noscript>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $chunks ) ) {
		return $html;
	}

	$out = '';
	foreach ( $chunks as $i => $chunk ) {
		if ( $i % 2 || strpos( $chunk, '<img' ) === false ) {
			$out .= $chunk;
			continue;
		}
		$done = preg_replace_callback(
			'/<img\s[^>]*>/i',
			function ( $m ) { return ekwa_perf_lazysize_img_tag( $m[0] ); },
			$chunk
		);
		$out .= ( null === $done ) ? $chunk : $done;
	}

	return $out;
}

/**
 * Catches <img> tags inside classic post content that don't go through render_block.
 */
function ekwa_perf_lazysize_content_img( $tag ) {
	return ekwa_perf_lazysize_img_tag( $tag );
}
add_filter( 'wp_content_img_tag', 'ekwa_perf_lazysize_content_img', 25 );

/**
 * Tell WordPress that a lazysized image is deferred.
 *
 * wp_get_loading_optimization_attributes() decides which image gets
 * fetchpriority="high" — and the ONLY signal it reads to know an image is
 * deferred is the `loading` attribute:
 *
 *     if ( 'lazy' === $attr['loading'] ) { $maybe_in_viewport = false; }
 *
 * lazysizes mode deliberately omits that attribute (it "opts out of native lazy
 * entirely", @see ekwa_render_image_block), so core sees an <img> with a src and
 * real dimensions, concludes it's the first large in-viewport image, and marks
 * it fetchpriority="high". The result on a live page: the LCP candidate shipped
 * as a 43-byte placeholder GIF that couldn't load until lazysizes ran — while an
 * author had explicitly left the block's "Hero image" toggle OFF. Core even warns
 * about this exact pairing ("An image should not be lazy-loaded and marked as
 * high priority at the same time") but never fires it, because it can't tell.
 *
 * fetchpriority="auto" is the signal for "may or may not be in the viewport —
 * don't promote it": core preserves it verbatim and stops the media count from
 * advancing, so the promotion doesn't simply move to the next lazysized image.
 * "auto" is also the browser default, so it changes nothing about how the image
 * is actually fetched — unlike re-adding loading="lazy", which would layer
 * native lazy-loading on top of lazysizes.
 *
 * @param string $tag Lazysized <img> tag.
 * @return string
 */
function ekwa_perf_mark_lazy_for_core( $tag ) {
	if ( preg_match( '/\sfetchpriority\s*=/i', $tag ) ) {
		return $tag; // Already carries an explicit priority — leave it alone.
	}
	return preg_replace( '/<img\s/i', '<img fetchpriority="auto" ', $tag, 1 );
}

/**
 * Catches images rendered via wp_get_attachment_image() — featured images,
 * site logo, theme template parts. WebP swap on wp_get_attachment_image_attributes
 * (priority 20) runs first, so data-src points at .webp companions.
 */
function ekwa_perf_lazysize_attachment_image( $html ) {
	return ekwa_perf_lazysize_img_tag( $html );
}
add_filter( 'wp_get_attachment_image', 'ekwa_perf_lazysize_attachment_image', 25 );
