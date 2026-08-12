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

	// Deliberately no fetchpriority: as="style" is already Highest in Chrome,
	// and the LCP image can't paint before layout exists — promoting the image
	// above the CSS it depends on would delay the very paint it's meant to
	// speed up.
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
 * Catches images rendered via wp_get_attachment_image() — featured images,
 * site logo, theme template parts. WebP swap on wp_get_attachment_image_attributes
 * (priority 20) runs first, so data-src points at .webp companions.
 */
function ekwa_perf_lazysize_attachment_image( $html ) {
	return ekwa_perf_lazysize_img_tag( $html );
}
add_filter( 'wp_get_attachment_image', 'ekwa_perf_lazysize_attachment_image', 25 );
