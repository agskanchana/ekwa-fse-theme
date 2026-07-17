<?php
/**
 * Ekwa Slider + Hero Video blocks — registration and render callbacks.
 *
 * Hero slider: ekwa/slider (transition engine, arrows, dots, keyboard, touch,
 * autoplay) → ekwa/slide (background image with WebP swap + overlay) →
 * ekwa/slide-content (entrance animation replayed on every activation).
 * Hero video: ekwa/hero-video (muted looped background video + caption).
 *
 * Frontend CSS/JS are per-block partials inlined ONLY on pages where the
 * blocks render (ekwa_inline_asset_map in inc/ekwa-inline-assets.php) — a
 * <style> before the block and a <script> before </body>, zero requests on
 * pages that don't use them.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The slide transitions offered by ekwa/slider. Keys are the CSS modifier
 * suffixes (ekwa-slider--{key}, rules in blocks/ekwa-slider/style.css);
 * labels feed the editor select. Shared with the editor via
 * wp_localize_script.
 *
 * @return array<string,string>
 */
function ekwa_slider_transitions() {
	return array(
		'fade'     => __( 'Fade', 'ekwa' ),
		'slide'    => __( 'Slide (horizontal)', 'ekwa' ),
		'slide-up' => __( 'Slide (vertical)', 'ekwa' ),
		'zoom'     => __( 'Zoom (Ken Burns fade)', 'ekwa' ),
		'zoom-out' => __( 'Zoom out (settle)', 'ekwa' ),
		'blur'     => __( 'Blur crossfade', 'ekwa' ),
		'parallax' => __( 'Parallax push', 'ekwa' ),
		'wipe'     => __( 'Wipe reveal', 'ekwa' ),
		'flip'     => __( 'Flip (3D)', 'ekwa' ),
	);
}

/**
 * The entrance animations offered by ekwa/slide-content. Keys are the CSS
 * class suffixes (keyframes live in blocks/ekwa-slider/style.css); labels
 * feed the editor select. Shared with the editor via wp_localize_script.
 *
 * @return array<string,string>
 */
function ekwa_slider_animations() {
	return array(
		'none'        => __( 'None', 'ekwa' ),
		'fadeIn'      => __( 'Fade In', 'ekwa' ),
		'fadeInUp'    => __( 'Fade In Up', 'ekwa' ),
		'fadeInDown'  => __( 'Fade In Down', 'ekwa' ),
		'fadeInLeft'  => __( 'Fade In Left', 'ekwa' ),
		'fadeInRight' => __( 'Fade In Right', 'ekwa' ),
		'zoomIn'      => __( 'Zoom In', 'ekwa' ),
		'slideInUp'   => __( 'Slide In Up', 'ekwa' ),
		'blurIn'      => __( 'Blur In', 'ekwa' ),
	);
}

/**
 * Register the four blocks (+ their editor scripts).
 */
function ekwa_slider_register_blocks() {
	wp_register_script(
		'ekwa-slider-editor',
		get_template_directory_uri() . '/assets/js/ekwa-slider-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-slider-editor.js' ),
		true
	);
	wp_localize_script( 'ekwa-slider-editor', 'ekwaSliderConfig', array(
		'animations'  => ekwa_slider_animations(),
		'transitions' => ekwa_slider_transitions(),
	) );

	wp_register_script(
		'ekwa-hero-video-editor',
		get_template_directory_uri() . '/assets/js/ekwa-hero-video-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-hero-video-editor.js' ),
		true
	);

	register_block_type( get_template_directory() . '/blocks/ekwa-slider', array(
		'render_callback' => 'ekwa_render_slider_block',
	) );
	register_block_type( get_template_directory() . '/blocks/ekwa-slide', array(
		'render_callback' => 'ekwa_render_slide_block',
	) );
	register_block_type( get_template_directory() . '/blocks/ekwa-slide-content', array(
		'render_callback' => 'ekwa_render_slide_content_block',
	) );
	register_block_type( get_template_directory() . '/blocks/ekwa-hero-video', array(
		'render_callback' => 'ekwa_render_hero_video_block',
	) );
}
add_action( 'init', 'ekwa_slider_register_blocks' );

/**
 * Resolve a background-image URL through the WebP module: supporting browsers
 * get the .webp companion, everyone else keeps the original (fallback).
 *
 * @param string $url
 * @return string
 */
function ekwa_slider_webp_url( $url ) {
	if ( $url
		&& function_exists( 'ekwa_webp_is_enabled' ) && ekwa_webp_is_enabled()
		&& function_exists( 'ekwa_webp_browser_supports' ) && ekwa_webp_browser_supports()
		&& function_exists( 'ekwa_webp_url_for' ) ) {
		return ekwa_webp_url_for( $url );
	}
	return $url;
}

/**
 * ekwa/slider — the slider shell.
 *
 * Slides render with data-bg (lazy); the FIRST slide's background is promoted
 * to an inline style here so the LCP image starts loading with the HTML,
 * while later slides hydrate from JS after init.
 *
 * @param array  $attrs
 * @param string $content Rendered ekwa/slide children.
 * @return string
 */
function ekwa_render_slider_block( $attrs, $content ) {
	if ( '' === trim( $content ) ) {
		return '';
	}

	static $instance = 0;
	$instance++;

	$transition = array_key_exists( $attrs['transition'] ?? 'fade', ekwa_slider_transitions() ) ? ( $attrs['transition'] ?? 'fade' ) : 'fade';
	$autoplay   = ! isset( $attrs['autoplay'] ) || (bool) $attrs['autoplay'];
	$interval   = max( 2000, absint( $attrs['interval'] ?? 6000 ) );
	$arrows     = ! isset( $attrs['showArrows'] ) || (bool) $attrs['showArrows'];
	$dots       = ! isset( $attrs['showDots'] ) || (bool) $attrs['showDots'];
	$loop       = ! isset( $attrs['loop'] ) || (bool) $attrs['loop'];
	$pause      = ! isset( $attrs['pauseOnHover'] ) || (bool) $attrs['pauseOnHover'];
	$min_h      = sanitize_text_field( $attrs['minHeight'] ?? '80vh' );
	$align      = isset( $attrs['align'] ) ? sanitize_html_class( $attrs['align'] ) : '';
	$class_name = isset( $attrs['className'] ) ? sanitize_text_field( $attrs['className'] ) : '';
	$anchor     = isset( $attrs['anchor'] ) ? sanitize_html_class( $attrs['anchor'] ) : '';

	// Promote the first slide's data-bg into its inline style (LCP: the
	// visible slide's image must not wait for JS; later slides stay lazy and
	// hydrate from view.js). Merges into the existing style attribute.
	$promoted = false;
	$content  = preg_replace_callback(
		'/class="ekwa-slide([^"]*)"([^>]*?)\sdata-bg="([^"]+)"\sstyle="([^"]*)"/',
		function ( $m ) use ( &$promoted ) {
			if ( $promoted ) {
				return $m[0];
			}
			$promoted = true;
			return 'class="ekwa-slide' . $m[1] . ' is-active"' . $m[2]
				. ' style="background-image:url(\'' . $m[3] . '\');' . $m[4] . '"';
		},
		$content
	);
	// A first slide without a background image still needs the active class.
	if ( ! $promoted ) {
		$content = preg_replace( '/class="ekwa-slide((?:[^"])*)"/', 'class="ekwa-slide$1 is-active"', $content, 1 );
	}

	$classes = trim( 'ekwa-slider ekwa-slider--' . $transition
		. ( $align ? ' align' . $align : '' )
		. ( $class_name ? ' ' . $class_name : '' ) );

	$html  = '<div class="' . esc_attr( $classes ) . '"'
		. ( $anchor ? ' id="' . esc_attr( $anchor ) . '"' : ' id="ekwa-slider-' . $instance . '"' )
		. ' style="--ekwa-slider-h:' . esc_attr( $min_h ) . '"'
		. ' data-transition="' . esc_attr( $transition ) . '"'
		. ' data-interval="' . esc_attr( $interval ) . '"'
		. ( $autoplay ? ' data-autoplay="1"' : '' )
		. ( $loop ? ' data-loop="1"' : '' )
		. ( $pause ? ' data-pause-hover="1"' : '' )
		. ' role="region" aria-roledescription="carousel" aria-label="' . esc_attr__( 'Hero slider', 'ekwa' ) . '" tabindex="0">';

	$html .= '<div class="ekwa-slider__track">' . $content . '</div>';

	if ( $arrows ) {
		$html .= '<button type="button" class="ekwa-slider__arrow ekwa-slider__arrow--prev" aria-label="' . esc_attr__( 'Previous slide', 'ekwa' ) . '">'
			. '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>'
			. '<button type="button" class="ekwa-slider__arrow ekwa-slider__arrow--next" aria-label="' . esc_attr__( 'Next slide', 'ekwa' ) . '">'
			. '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button>';
	}

	if ( $dots ) {
		$html .= '<div class="ekwa-slider__dots" role="tablist" aria-label="' . esc_attr__( 'Choose slide', 'ekwa' ) . '"></div>';
	}

	$html .= '</div>';

	return $html;
}

/**
 * ekwa/slide — one slide.
 *
 * @param array  $attrs
 * @param string $content Inner blocks (typically ekwa/slide-content groups).
 * @return string
 */
function ekwa_render_slide_block( $attrs, $content ) {
	$bg       = isset( $attrs['bgImage'] ) ? esc_url( ekwa_slider_webp_url( $attrs['bgImage'] ) ) : '';
	$position = sanitize_text_field( $attrs['bgPosition'] ?? 'center center' );
	$ov_color = sanitize_text_field( $attrs['overlayColor'] ?? '#0b1622' );
	$ov_op    = max( 0, min( 100, absint( $attrs['overlayOpacity'] ?? 35 ) ) );
	$align    = in_array( $attrs['contentAlign'] ?? 'left', array( 'left', 'center', 'right' ), true ) ? $attrs['contentAlign'] : 'left';
	$class    = isset( $attrs['className'] ) ? sanitize_text_field( $attrs['className'] ) : '';
	$anchor   = isset( $attrs['anchor'] ) ? sanitize_html_class( $attrs['anchor'] ) : '';

	$classes = trim( 'ekwa-slide ekwa-slide--' . $align . ( $class ? ' ' . $class : '' ) );

	$style = 'background-position:' . esc_attr( $position ) . ';';

	$html = '<div class="' . esc_attr( $classes ) . '"'
		. ( $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '' )
		. ( $bg ? ' data-bg="' . esc_attr( $bg ) . '"' : '' )
		. ' style="' . $style . '" role="group" aria-roledescription="slide">';

	if ( $ov_op > 0 ) {
		$html .= '<div class="ekwa-slide__overlay" style="background:' . esc_attr( $ov_color ) . ';opacity:' . esc_attr( $ov_op / 100 ) . '" aria-hidden="true"></div>';
	}

	$html .= '<div class="ekwa-slide__inner">' . $content . '</div></div>';

	return $html;
}

/**
 * ekwa/slide-content — an animated content group. The animation class is
 * (re)applied by view.js each time the parent slide activates, so entrances
 * replay on every pass. Without JS the content simply shows (no-JS guard in
 * the stylesheet keys off .ekwa-slider--ready).
 *
 * @param array  $attrs
 * @param string $content
 * @return string
 */
function ekwa_render_slide_content_block( $attrs, $content ) {
	$anims = ekwa_slider_animations();
	$anim  = array_key_exists( $attrs['animation'] ?? 'fadeInUp', $anims ) ? ( $attrs['animation'] ?? 'fadeInUp' ) : 'fadeInUp';
	$delay = max( 0, absint( $attrs['delay'] ?? 0 ) );
	$dur   = max( 100, absint( $attrs['duration'] ?? 800 ) );
	$class = isset( $attrs['className'] ) ? sanitize_text_field( $attrs['className'] ) : '';

	$attr_str = '';
	if ( 'none' !== $anim ) {
		$attr_str = ' data-anim="' . esc_attr( $anim ) . '" data-delay="' . esc_attr( $delay ) . '"'
			. ' style="animation-duration:' . esc_attr( $dur ) . 'ms;animation-delay:' . esc_attr( $delay ) . 'ms"';
	}

	return '<div class="' . esc_attr( trim( 'ekwa-slide-content' . ( $class ? ' ' . $class : '' ) ) ) . '"' . $attr_str . '>'
		. $content . '</div>';
}

/**
 * ekwa/hero-video — background video hero.
 *
 * @param array  $attrs
 * @param string $content Caption content.
 * @return string
 */
function ekwa_render_hero_video_block( $attrs, $content ) {
	$video   = isset( $attrs['videoUrl'] ) ? esc_url( $attrs['videoUrl'] ) : '';
	$poster  = isset( $attrs['posterUrl'] ) ? esc_url( ekwa_slider_webp_url( $attrs['posterUrl'] ) ) : '';
	$ov_col  = sanitize_text_field( $attrs['overlayColor'] ?? '#0b1622' );
	$ov_op   = max( 0, min( 100, absint( $attrs['overlayOpacity'] ?? 40 ) ) );
	$min_h   = sanitize_text_field( $attrs['minHeight'] ?? '80vh' );
	$align   = in_array( $attrs['contentAlign'] ?? 'left', array( 'left', 'center', 'right' ), true ) ? $attrs['contentAlign'] : 'left';
	$pausebtn = ! isset( $attrs['showPauseButton'] ) || (bool) $attrs['showPauseButton'];
	$alignw  = isset( $attrs['align'] ) ? sanitize_html_class( $attrs['align'] ) : '';
	$class   = isset( $attrs['className'] ) ? sanitize_text_field( $attrs['className'] ) : '';
	$anchor  = isset( $attrs['anchor'] ) ? sanitize_html_class( $attrs['anchor'] ) : '';

	if ( '' === $video ) {
		return '';
	}

	$type = 'video/mp4';
	$ext  = strtolower( pathinfo( wp_parse_url( $video, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
	if ( 'webm' === $ext ) {
		$type = 'video/webm';
	} elseif ( 'ogv' === $ext || 'ogg' === $ext ) {
		$type = 'video/ogg';
	}

	$classes = trim( 'ekwa-hero-video ekwa-hero-video--' . $align
		. ( $alignw ? ' align' . $alignw : '' )
		. ( $class ? ' ' . $class : '' ) );

	$html = '<div class="' . esc_attr( $classes ) . '"'
		. ( $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '' )
		. ' style="--ekwa-hero-h:' . esc_attr( $min_h ) . '">';

	// Muted + playsinline are required for mobile autoplay; preload metadata
	// keeps the initial payload light (the poster paints first).
	$html .= '<video class="ekwa-hero-video__media" autoplay muted loop playsinline preload="metadata"'
		. ( $poster ? ' poster="' . esc_attr( $poster ) . '"' : '' ) . ' aria-hidden="true" tabindex="-1">'
		. '<source src="' . esc_attr( $video ) . '" type="' . esc_attr( $type ) . '">'
		. '</video>';

	if ( $ov_op > 0 ) {
		$html .= '<div class="ekwa-hero-video__overlay" style="background:' . esc_attr( $ov_col ) . ';opacity:' . esc_attr( $ov_op / 100 ) . '" aria-hidden="true"></div>';
	}

	$html .= '<div class="ekwa-hero-video__content">' . $content . '</div>';

	if ( $pausebtn ) {
		$html .= '<button type="button" class="ekwa-hero-video__toggle" aria-label="' . esc_attr__( 'Pause background video', 'ekwa' ) . '" data-state="playing">'
			. '<svg class="ekwa-hero-video__ico-pause" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M7 5h4v14H7zM13 5h4v14h-4z" fill="currentColor"/></svg>'
			. '<svg class="ekwa-hero-video__ico-play" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M8 5l11 7-11 7z" fill="currentColor"/></svg>'
			. '</button>';
	}

	$html .= '</div>';

	return $html;
}
