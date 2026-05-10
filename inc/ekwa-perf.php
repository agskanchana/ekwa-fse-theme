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

function ekwa_perf_enqueue_lazysizes() {
	if ( ekwa_perf_lazy_mode() !== 'lazysizes' ) {
		return;
	}

	// unveilhooks extends lazysizes with <video>, <iframe poster>, CSS-bg, etc.
	// Listed as a dep of lazysizes so it prints first — lazysizes plugins
	// are designed to be loaded before the core lib.
	wp_register_script(
		'ekwa-lazysizes-unveilhooks',
		get_template_directory_uri() . '/assets/js/ls.unveilhooks.min.js',
		array(),
		'5.3.2',
		array(
			'in_footer' => false,
			'strategy'  => 'async',
		)
	);

	wp_enqueue_script(
		'ekwa-lazysizes',
		get_template_directory_uri() . '/assets/js/lazysizes.min.js',
		array( 'ekwa-lazysizes-unveilhooks' ),
		'5.3.2',
		array(
			'in_footer' => false,
			'strategy'  => 'async',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'ekwa_perf_enqueue_lazysizes' );

/**
 * Register (don't enqueue) the IntersectionObserver shim that paints
 * `[data-bg]` background images once the wrapper enters the viewport.
 *
 * The ekwa/div render callback calls wp_enqueue_script('ekwa-lazy-bg')
 * on demand, so pages without lazy-bg divs ship zero extra JS.
 */
function ekwa_perf_register_lazy_bg() {
	wp_register_script(
		'ekwa-lazy-bg',
		get_template_directory_uri() . '/assets/js/ekwa-lazy-bg.js',
		array(),
		wp_get_theme()->get( 'Version' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'ekwa_perf_register_lazy_bg' );

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

function ekwa_perf_emit_hero_preloads() {
	if ( ! ekwa_perf_preload_hero_enabled() ) {
		return;
	}
	if ( ! is_singular() ) {
		return;
	}

	$post = get_post();
	if ( ! $post || empty( $post->post_content ) ) {
		return;
	}
	if ( ! has_blocks( $post->post_content ) ) {
		return;
	}

	$found = array();
	ekwa_perf_collect_hero_image_blocks( parse_blocks( $post->post_content ), $found );
	if ( empty( $found ) ) {
		return;
	}

	$webp_supports = function_exists( 'ekwa_webp_browser_supports' ) && ekwa_webp_browser_supports();
	$srcset_on     = ekwa_perf_srcset_enabled();
	$emitted       = array();

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
}
add_action( 'wp_head', 'ekwa_perf_emit_hero_preloads', 1 );

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
	return preg_replace_callback(
		'/<img\s[^>]*>/i',
		function ( $m ) { return ekwa_perf_lazysize_img_tag( $m[0] ); },
		$html
	);
}
add_filter( 'render_block', 'ekwa_perf_lazysize_block_html', 25, 2 );

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
