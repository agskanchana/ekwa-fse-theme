<?php
/**
 * Inline per-block CSS/JS on render.
 *
 * Each block ships its own front-end CSS/JS partial. Instead of enqueuing
 * monolithic stylesheets and scripts on every page, the render_block filter
 * inlines a block's assets into the page only when that block actually renders
 * — and exactly once per request (dedupe keyed on the file path, so shared
 * partials are emitted at most once). CSS is prepended next to its block; JS is
 * collected as blocks render and printed together just before </body> (wp_footer).
 *
 * The reusable ekwa_inline_get_* / ekwa_inline_print_* helpers also let
 * non-block, page-conditional assets (blog CSS, lazysizes, the lazy-bg shim)
 * inline through the same dedupe registry. All paths are relative to the
 * (parent) theme root.
 *
 * Per-block partials are the single source of truth; the block editor loads the
 * full set via add_editor_style() (see ekwa_editor_styles()).
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map of block name => front-end asset partials (relative to the theme root).
 *
 * 'css' and 'js' are each optional. Several block names may point at the same
 * file (e.g. the shared blog stylesheet); the per-path dedupe guarantees the
 * file is inlined at most once per request.
 *
 * @return array<string, array{css?:string, js?:string}>
 */
function ekwa_inline_asset_map() {
	$blog = 'assets/css/ekwa-blog.css';

	return array(
		// Header / global UI blocks.
		'ekwa/search'           => array( 'css' => 'blocks/ekwa-search/style.css',           'js' => 'blocks/ekwa-search/view.js' ),
		'ekwa/hamburger-menu'   => array( 'css' => 'blocks/ekwa-hamburger-menu/style.css',    'js' => 'blocks/ekwa-hamburger-menu/view.js' ),
		'ekwa/header-menu'      => array( 'css' => 'blocks/ekwa-header-menu/style.css',       'js' => 'blocks/ekwa-header-menu/view.js' ),
		'ekwa/mobile-dock'      => array( 'css' => 'blocks/ekwa-mobile-dock/style.css',       'js' => 'blocks/ekwa-mobile-dock/view.js' ),
		'ekwa/scroll-top'       => array( 'css' => 'blocks/ekwa-scroll-top/style.css',        'js' => 'blocks/ekwa-scroll-top/view.js' ),
		'ekwa/social'           => array( 'css' => 'blocks/ekwa-social/style.css',            'js' => 'blocks/ekwa-social/view.js' ),
		'ekwa/address-dropdown' => array( 'css' => 'blocks/ekwa-address-dropdown/style.css',  'js' => 'blocks/ekwa-address-dropdown/view.js' ),
		'ekwa/phone'            => array( 'css' => 'blocks/ekwa-phone/style.css' ),
		'ekwa/phone-dropdown'   => array( 'css' => 'blocks/ekwa-phone-dropdown/style.css',    'js' => 'blocks/ekwa-phone-dropdown/view.js' ),

		// Layout / content blocks.
		'ekwa/inner-banner'     => array( 'css' => 'blocks/ekwa-inner-banner/style.css' ),
		'ekwa/page-title'       => array( 'css' => 'blocks/ekwa-page-title/style.css' ),
		'ekwa/section'          => array( 'css' => 'blocks/ekwa-section/style.css' ),
		'ekwa/grid'             => array( 'css' => 'blocks/ekwa-grid/style.css' ),
		'ekwa/button'           => array( 'css' => 'blocks/ekwa-button/style.css' ),
		'ekwa/card-link'        => array( 'css' => 'blocks/ekwa-card-link/style.css' ),
		'ekwa/svg-logo'         => array( 'css' => 'blocks/ekwa-svg-logo/style.css' ),
		'ekwa/faq'              => array( 'css' => 'blocks/ekwa-faq/style.css',               'js' => 'blocks/ekwa-faq/view.js' ),
		'ekwa/reveal'           => array( 'css' => 'blocks/ekwa-reveal/style.css',            'js' => 'blocks/ekwa-reveal/view.js' ),
		'ekwa/reveal-hidden'    => array( 'css' => 'blocks/ekwa-reveal/style.css' ),
		'ekwa/carousel'         => array( 'css' => 'blocks/ekwa-carousel/style.css',          'js' => 'blocks/ekwa-carousel/view.js' ),

		// Blog blocks — all share the (page-level) blog stylesheet; dedupe keeps
		// it to a single inline emission per request.
		'ekwa/toc'              => array( 'css' => $blog ),
		'ekwa/read-time'        => array( 'css' => $blog ),
		'ekwa/share-button'     => array( 'css' => $blog ),
		'ekwa/back-to-category' => array( 'css' => $blog ),
		'ekwa/recent-posts'     => array( 'css' => $blog ),
		'ekwa/categories'       => array( 'css' => $blog ),
		'ekwa/related-articles' => array( 'css' => $blog ),
		'ekwa/related-posts'    => array( 'css' => $blog ),
		'ekwa/load-more'        => array( 'css' => $blog ),
	);
}

/**
 * Map of core block name => [ style-variation token => CSS partial ].
 *
 * Theme style variations registered for core blocks (ekwa-block-styles.php) are
 * inlined only when the rendered block carries the matching is-style-* class.
 *
 * @return array<string, array<string, string>>
 */
function ekwa_inline_core_style_map() {
	return array(
		'core/button' => array(
			'outline' => 'blocks/_core-styles/button-outline.css',
			'ghost'   => 'blocks/_core-styles/button-ghost.css',
			'size-sm' => 'blocks/_core-styles/button-size-sm.css',
			'size-lg' => 'blocks/_core-styles/button-size-lg.css',
		),
		'core/group' => array(
			'service-card' => 'blocks/_core-styles/group-service-card.css',
			'parallax-bg'  => 'blocks/_core-styles/group-parallax-bg.css',
			'has-overlay'  => 'blocks/_core-styles/group-has-overlay.css',
		),
		'core/column' => array(
			'card' => 'blocks/_core-styles/column-card.css',
		),
	);
}

/**
 * Whether inlined CSS/JS should be minified (opt-in via Performance settings).
 *
 * @return bool
 */
function ekwa_inline_minify_enabled() {
	return (bool) get_option( 'ekwa_perf_minify_inline', 0 );
}

/**
 * Conservative CSS minifier — safe for hand-written CSS.
 *
 * Strips comments, collapses whitespace, and tightens spacing around braces,
 * semicolons and commas only. Spacing around `:` `+` `-` `>` `~` is left intact
 * so calc() expressions and combinators are never broken.
 *
 * @param string $css
 * @return string
 */
function ekwa_inline_minify_css( $css ) {
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );
	$css = preg_replace( '#\s+#', ' ', $css );
	$css = preg_replace( '#\s*([{};,])\s*#', '$1', $css );
	$css = str_replace( ';}', '}', $css );
	return trim( $css );
}

/**
 * Conservative JS minifier — strips block comments, full-line `//` comments,
 * indentation and blank lines. Existing line breaks are preserved so automatic
 * semicolon insertion behaves exactly as in the source (no risky single-lining).
 *
 * @param string $js
 * @return string
 */
function ekwa_inline_minify_js( $js ) {
	$js    = preg_replace( '#/\*.*?\*/#s', '', $js );
	$lines = preg_split( '#\R#', $js );
	$kept  = array();
	foreach ( $lines as $line ) {
		$trimmed = trim( $line );
		if ( '' === $trimmed || 0 === strpos( $trimmed, '//' ) ) {
			continue;
		}
		$kept[] = $trimmed;
	}
	return implode( "\n", $kept );
}

/**
 * Minify CSS/JS by file type, skipping already-minified (*.min.*) vendor files.
 *
 * @param string $code
 * @param string $rel  Path (used to detect the type / .min. marker).
 * @return string
 */
function ekwa_inline_minify( $code, $rel ) {
	if ( '' === $code || preg_match( '#\.min\.(css|js)$#i', $rel ) ) {
		return $code;
	}
	if ( preg_match( '#\.css$#i', $rel ) ) {
		return ekwa_inline_minify_css( $code );
	}
	if ( preg_match( '#\.js$#i', $rel ) ) {
		return ekwa_inline_minify_js( $code );
	}
	return $code;
}

/**
 * Read a theme file once per request (statically cached, minified when enabled).
 *
 * @param string $rel Path relative to the theme root.
 * @return string File contents, or '' if missing/empty.
 */
function ekwa_inline_read( $rel ) {
	static $cache = array();
	if ( isset( $cache[ $rel ] ) ) {
		return $cache[ $rel ];
	}
	$path = get_template_directory() . '/' . ltrim( $rel, '/' );
	$body = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	if ( '' !== $body && ekwa_inline_minify_enabled() ) {
		$body = ekwa_inline_minify( $body, $rel );
	}
	$cache[ $rel ] = $body;
	return $body;
}

/**
 * Mark an asset path emitted for this request. Shared by every inliner so the
 * same file is never inlined twice, regardless of which entry point emits it.
 *
 * @param string $rel Path relative to the theme root.
 * @return bool True the first time (caller should emit), false thereafter.
 */
function ekwa_inline_mark_emitted( $rel ) {
	static $emitted = array();
	if ( isset( $emitted[ $rel ] ) ) {
		return false;
	}
	$emitted[ $rel ] = true;
	return true;
}

/**
 * Build a DOM id for an inline tag from its path.
 *
 * @param string $rel Path relative to the theme root.
 * @return string
 */
function ekwa_inline_id( $rel ) {
	$base = preg_replace( '/\.(css|js)$/', '', $rel );
	return 'ekwa-inline-' . sanitize_html_class( str_replace( array( '/', '.' ), '-', $base ) );
}

/**
 * Inline <style> markup for a file, once per request.
 *
 * @param string $rel Path relative to the theme root.
 * @return string Markup, or '' if already emitted / empty.
 */
function ekwa_inline_get_style( $rel ) {
	if ( ! ekwa_inline_mark_emitted( $rel ) ) {
		return '';
	}
	$css = ekwa_inline_read( $rel );
	if ( '' === trim( $css ) ) {
		return '';
	}
	return '<style id="' . esc_attr( ekwa_inline_id( $rel ) ) . '">' . $css . '</style>';
}

/**
 * Inline <script> markup for a file, once per request.
 *
 * @param string $rel Path relative to the theme root.
 * @return string Markup, or '' if already emitted / empty.
 */
function ekwa_inline_get_script( $rel ) {
	if ( ! ekwa_inline_mark_emitted( $rel ) ) {
		return '';
	}
	$js = ekwa_inline_read( $rel );
	if ( '' === trim( $js ) ) {
		return '';
	}
	return '<script id="' . esc_attr( ekwa_inline_id( $rel ) ) . '">' . $js . '</script>';
}

/**
 * Echo an inline stylesheet (for wp_head). @see ekwa_inline_get_style().
 *
 * @param string $rel Path relative to the theme root.
 */
function ekwa_inline_print_style( $rel ) {
	echo ekwa_inline_get_style( $rel ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Echo an inline script (for wp_head). @see ekwa_inline_get_script().
 *
 * @param string $rel Path relative to the theme root.
 */
function ekwa_inline_print_script( $rel ) {
	echo ekwa_inline_get_script( $rel ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Queue a block's JS for inline output just before </body> (via wp_footer).
 *
 * Block JS is collected as blocks render and printed in one place at the foot
 * of the document, rather than scattered inline after each block. Order of
 * first appearance is preserved; paths are deduped.
 *
 * @param string|null $rel Path relative to the theme root, or null to read the queue.
 * @return string[] The current queue.
 */
function ekwa_inline_queue_script( $rel = null ) {
	static $queue = array();
	if ( null !== $rel && ! in_array( $rel, $queue, true ) ) {
		$queue[] = $rel;
	}
	return $queue;
}

/**
 * Print all queued block scripts inline just before </body>.
 */
function ekwa_inline_print_footer_scripts() {
	if ( is_admin() ) {
		return;
	}
	foreach ( ekwa_inline_queue_script() as $rel ) {
		$js = ekwa_inline_read( $rel );
		if ( '' === trim( $js ) ) {
			continue;
		}
		echo '<script id="' . esc_attr( ekwa_inline_id( $rel ) ) . '">' . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'wp_footer', 'ekwa_inline_print_footer_scripts', 20 );

/**
 * Inline a block's CSS/JS the first time it renders on the page.
 *
 * CSS is prepended so it precedes the block markup; JS is queued and printed
 * together just before </body> via wp_footer (see ekwa_inline_print_footer_scripts).
 *
 * @param string $block_content Rendered block HTML.
 * @param array  $block         Parsed block.
 * @return string
 */
function ekwa_inline_block_assets( $block_content, $block ) {
	if ( is_admin() ) {
		return $block_content;
	}

	$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
	if ( '' === $name ) {
		return $block_content;
	}

	$prepend = '';

	$map = ekwa_inline_asset_map();
	if ( isset( $map[ $name ] ) ) {
		if ( ! empty( $map[ $name ]['css'] ) ) {
			$prepend .= ekwa_inline_get_style( $map[ $name ]['css'] );
		}
		if ( ! empty( $map[ $name ]['js'] ) ) {
			ekwa_inline_queue_script( $map[ $name ]['js'] );
		}
	}

	// Related Articles renders a carousel unless explicitly switched to grid mode;
	// inline the shared carousel bundle only when it actually uses it.
	if ( 'ekwa/related-articles' === $name ) {
		$use_carousel = ! isset( $block['attrs']['useCarousel'] ) ? true : (bool) $block['attrs']['useCarousel'];
		if ( $use_carousel ) {
			$prepend .= ekwa_inline_get_style( 'blocks/ekwa-carousel/style.css' );
			ekwa_inline_queue_script( 'blocks/ekwa-carousel/view.js' );
		}
	}

	// Core-block style variations — inline only the matching is-style-* slice.
	$core = ekwa_inline_core_style_map();
	if ( isset( $core[ $name ] ) ) {
		$classes = isset( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '';
		if ( '' !== $classes ) {
			$haystack = ' ' . $classes . ' ';
			foreach ( $core[ $name ] as $token => $rel ) {
				if ( false !== strpos( $haystack, ' is-style-' . $token . ' ' ) ) {
					$prepend .= ekwa_inline_get_style( $rel );
				}
			}
		}
	}

	return '' === $prepend ? $block_content : $prepend . $block_content;
}
add_filter( 'render_block', 'ekwa_inline_block_assets', 10, 2 );
