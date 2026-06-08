<?php
/**
 * Ekwa theme functions and definitions.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub auto-updater — checks agskanchana/ekwa-fse-theme for new releases.
 */
require_once get_template_directory() . '/includes/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ekwa_theme_updater = PucFactory::buildUpdateChecker(
	'https://github.com/agskanchana/ekwa-fse-theme/',
	get_template_directory() . '/style.css',
	'ekwa'
);

/**
 * Authenticate GitHub update checks with a Personal Access Token when one is
 * available, lifting the API limit from 60/hr (anonymous) to 5000/hr. The
 * EKWA_GITHUB_TOKEN constant (e.g. in wp-config.php) takes precedence over the
 * value stored on the settings screen.
 */
function ekwa_github_token() {
	if ( defined( 'EKWA_GITHUB_TOKEN' ) && EKWA_GITHUB_TOKEN ) {
		return (string) EKWA_GITHUB_TOKEN;
	}
	return (string) get_option( 'ekwa_github_token', '' );
}

$ekwa_github_token = ekwa_github_token();
if ( '' !== $ekwa_github_token ) {
	$ekwa_theme_updater->setAuthentication( $ekwa_github_token );
}

/**
 * Flag a GitHub API rate-limit (HTTP 403 with X-RateLimit-Remaining: 0) so we
 * can prompt the admin to add a token. Stored as a short-lived transient.
 */
function ekwa_github_flag_rate_limit( $error, $http_response = null, $url = null, $slug = null ) {
	if ( 'ekwa' !== $slug || ! is_array( $http_response ) ) {
		return;
	}
	$code      = wp_remote_retrieve_response_code( $http_response );
	$remaining = wp_remote_retrieve_header( $http_response, 'x-ratelimit-remaining' );
	if ( 403 == $code && '0' === (string) $remaining ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
		set_transient( 'ekwa_github_rate_limited', 1, HOUR_IN_SECONDS );
	}
}
add_action( 'puc_api_error', 'ekwa_github_flag_rate_limit', 10, 4 );

/**
 * Admin notice when update checks were rate-limited and no token is configured.
 */
function ekwa_github_rate_limit_notice() {
	if ( ! current_user_can( 'update_themes' ) ) {
		return;
	}
	if ( '' !== ekwa_github_token() ) {
		// A token is set — it already raises the cap; nothing to prompt.
		delete_transient( 'ekwa_github_rate_limited' );
		return;
	}
	if ( ! get_transient( 'ekwa_github_rate_limited' ) ) {
		return;
	}
	$url = admin_url( 'admin.php?page=ekwa-settings&tab=general#ekwa-theme-updates' );
	echo '<div class="notice notice-warning is-dismissible"><p>';
	printf(
		/* translators: %s: settings URL. */
		wp_kses_post( __( '<strong>Ekwa theme updates:</strong> GitHub\'s API rate limit was reached, so update checks may fail. <a href="%s">Add a GitHub Personal Access Token</a> to raise the limit.', 'ekwa' ) ),
		esc_url( $url )
	);
	echo '</p></div>';
}
add_action( 'admin_notices', 'ekwa_github_rate_limit_notice' );

/**
 * Load theme settings page.
 */
require_once get_template_directory() . '/inc/ekwa-settings.php';

/**
 * Load custom fonts registry (Fonts tab + frontend output + theme.json filter).
 */
require_once get_template_directory() . '/inc/ekwa-fonts.php';

/**
 * Load schema.org JSON-LD output (uses ekwa-settings data).
 */
require_once get_template_directory() . '/inc/ekwa-schema.php';

/**
 * Load shortcode builder admin page.
 */
require_once get_template_directory() . '/inc/ekwa-shortcode-builder.php';

/**
 * Load theme shortcodes.
 */
require_once get_template_directory() . '/inc/ekwa-shortcodes.php';

/**
 * Load custom block registrations and render callbacks.
 */

require_once get_template_directory() . '/inc/ekwa-blocks.php';

/**
 * Inline each block's front-end CSS/JS on render (replaces the monolithic
 * ekwa-blocks.css / ekwa-block-styles.css / ekwa-blocks.js / ekwa-faq.js).
 */
require_once get_template_directory() . '/inc/ekwa-inline-assets.php';

/**
 * Inline the active child theme's style.css / ekwa-child.js (opt-in via the
 * Performance settings tab). Lives in the parent so it works without editing
 * each child theme.
 */
require_once get_template_directory() . '/inc/ekwa-inline-child.php';

/**
 * Load WebP image support (auto-generation + transparent URL swap).
 */
require_once get_template_directory() . '/inc/ekwa-webp.php';

/**
 * Load image performance helpers (lazy loading, hero preload, srcset).
 */
require_once get_template_directory() . '/inc/ekwa-perf.php';

/**
 * Load head-level performance hardening (critical CSS, stylesheet deferral,
 * resource hints, WP core bloat removal).
 */
require_once get_template_directory() . '/inc/ekwa-perf-head.php';

/**
 * Load block style variations.
 */
require_once get_template_directory() . '/inc/ekwa-block-styles.php';

/**
 * Load mockup converter REST API.
 */
require_once get_template_directory() . '/inc/ekwa-converter-api.php';

/**
 * Load AI HTML generator for mockup converter (Gemini multimodal API).
 */
require_once get_template_directory() . '/inc/ekwa-ai-hints.php';
require_once get_template_directory() . '/inc/ekwa-ai-generate.php';

/**
 * AI alt-text generation for the ekwa/image block (Gemini multimodal).
 */
require_once get_template_directory() . '/inc/ekwa-ai-alt.php';

/**
 * Tag external links with a descriptive title on first user interaction.
 */
require_once get_template_directory() . '/inc/ekwa-external-links.php';

/**
 * Cookie consent banner (inline CSS + JS, dismissible, 360-day cookie).
 */
require_once get_template_directory() . '/inc/ekwa-cookie-banner.php';

/**
 * Load blog features (TOC, author link, load more, post cards).
 */
require_once get_template_directory() . '/inc/ekwa-blog.php';

/**
 * Load mobile menu: nav location, icon meta field, custom walker.
 */
require_once get_template_directory() . '/inc/ekwa-mobile-menu.php';

/**
 * Load header menu: mega-menu meta fields and custom walker.
 */
require_once get_template_directory() . '/inc/ekwa-header-menu.php';

/**
 * Enqueue theme stylesheet and Font Awesome.
 */
function ekwa_enqueue_styles() {
	// The parent theme's style.css contains only the desktop/mobile header
	// toggle. Inline it (no HTTP request) but keep the 'ekwa-style' handle
	// registered with src=false so the child's ekwa-child-style still chains
	// to it. The two media queries below are the entire contents of the former
	// assets/css/ekwa-mobile.css — inlined here so no separate request is made.
	wp_register_style( 'ekwa-style', false, array(), wp_get_theme()->get( 'Version' ) );
	wp_enqueue_style( 'ekwa-style' );
	wp_add_inline_style(
		'ekwa-style',
		'@media (max-width:1199px){.ekwa-desktop-header{display:none !important}}' .
		'@media (min-width:1200px){.ekwa-mobile-header{display:none !important}}'
	);
	wp_enqueue_style(
		'font-awesome',
		get_template_directory_uri() . '/assets/fontawesome/css/all.min.css',
		array(),
		'6.5.1'
	);
	// Per-block CSS/JS is no longer enqueued globally. Each block inlines its
	// own front-end CSS (blocks/<name>/style.css) and JS (blocks/<name>/view.js)
	// only when it renders — see inc/ekwa-inline-assets.php.
}
add_action( 'wp_enqueue_scripts', 'ekwa_enqueue_styles' );

/**
 * Enqueue Font Awesome in the block editor outer shell and admin pages.
 */
function ekwa_enqueue_admin_fa() {
	wp_enqueue_style(
		'font-awesome',
		get_template_directory_uri() . '/assets/fontawesome/css/all.min.css',
		array(),
		'6.5.1'
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_admin_fa' );
add_action( 'admin_enqueue_scripts', 'ekwa_enqueue_admin_fa' );

/**
 * Inject styles into the FSE iframed canvas.
 *
 * add_editor_style() with a RELATIVE theme path causes WordPress to set
 * baseURL = the theme's asset URL, so relative font paths in the CSS
 * (e.g. ../webfonts/) resolve correctly inside the iframe.
 */
function ekwa_editor_styles() {
	add_editor_style( 'assets/fontawesome/css/all.min.css' );
	add_editor_style( 'assets/css/ekwa-editor.css' );

	// The per-block partials are the single source of truth for block CSS. The
	// front end inlines only the blocks in use; the editor loads the full set so
	// every block previews correctly. Paths are relative to the theme root.
	$theme_dir = get_template_directory();
	$partials  = array_merge(
		glob( $theme_dir . '/blocks/*/style.css' ) ?: array(),
		glob( $theme_dir . '/blocks/_core-styles/*.css' ) ?: array()
	);
	foreach ( $partials as $partial ) {
		add_editor_style( 'blocks/' . ltrim( str_replace( $theme_dir . '/blocks/', '', $partial ), '/' ) );
	}
}
add_action( 'after_setup_theme', 'ekwa_editor_styles' );

/**
 * Enqueue the phone-button block extension in the editor.
 */
function ekwa_enqueue_button_phone_editor_script() {
	wp_enqueue_script(
		'ekwa-button-phone',
		get_template_directory_uri() . '/assets/js/ekwa-button-phone.js',
		array(
			'wp-hooks',
			'wp-blocks',
			'wp-block-editor',
			'wp-components',
			'wp-compose',
			'wp-element',
			'wp-i18n',
		),
		wp_get_theme()->get( 'Version' ),
		true
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_button_phone_editor_script' );

/**
 * Enqueue the mockup converter editor plugin.
 */
function ekwa_enqueue_converter_editor_script() {
	wp_enqueue_script(
		'ekwa-converter-editor',
		get_template_directory_uri() . '/assets/js/ekwa-converter-editor.js',
		array(
			'wp-plugins',
			'wp-editor',
			'wp-blocks',
			'wp-block-editor',
			'wp-components',
			'wp-element',
			'wp-data',
			'wp-i18n',
			'wp-api-fetch',
		),
		filemtime( get_template_directory() . '/assets/js/ekwa-converter-editor.js' ),
		true
	);

	// AI HTML generator — separate plugin entry point that hands off HTML to the converter.
	wp_enqueue_script(
		'ekwa-ai-generate-editor',
		get_template_directory_uri() . '/assets/js/ekwa-ai-generate-editor.js',
		array(
			'ekwa-converter-editor',
			'wp-plugins',
			'wp-editor',
			'wp-components',
			'wp-element',
			'wp-i18n',
			'wp-api-fetch',
		),
		filemtime( get_template_directory() . '/assets/js/ekwa-ai-generate-editor.js' ),
		true
	);

	// Expose the active (child) theme stylesheet URL so the preview iframe can
	// load it — mirrors what the front-end renders so AI-generated HTML that
	// uses theme classes/variables previews correctly.
	$child_css_path = get_stylesheet_directory() . '/style.css';
	$child_css_uri  = get_stylesheet_uri();
	if ( file_exists( $child_css_path ) ) {
		$child_css_uri = add_query_arg( 'ver', filemtime( $child_css_path ), $child_css_uri );
	}
	$ai_models     = function_exists( 'ekwa_ai_generate_allowed_models' ) ? ekwa_ai_generate_allowed_models() : array();
	$ai_model_list = array();
	foreach ( $ai_models as $model_id => $model_label ) {
		$ai_model_list[] = array(
			'value' => $model_id,
			'label' => $model_label,
		);
	}
	wp_localize_script(
		'ekwa-ai-generate-editor',
		'ekwaAiGenerate',
		array(
			'childStylesheetUrl' => $child_css_uri,
			'models'             => $ai_model_list,
			'defaultModel'       => 'gemini-2.5-flash',
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_converter_editor_script' );

/**
 * Register theme support.
 */
function ekwa_setup() {
	register_nav_menus( array(
		'main_menu'       => __( 'Main Menu', 'ekwa' ),
		'primary'         => __( 'Primary Menu', 'ekwa' ),
		'mobile'          => __( 'Mobile Menu', 'ekwa' ),
		'mobile_services' => __( 'Mobile Services Menu', 'ekwa' ),
		'sitemap'         => __( 'Sitemap', 'ekwa' ),
	) );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'title-tag' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array(
		'comment-list',
		'comment-form',
		'search-form',
		'gallery',
		'caption',
		'style',
		'script',
	) );
}
add_action( 'after_setup_theme', 'ekwa_setup' );

/**
 * Register block pattern category.
 */
function ekwa_register_pattern_categories() {
	register_block_pattern_category( 'ekwa-patterns', array(
		'label' => esc_html__( 'Ekwa Patterns', 'ekwa' ),
	) );
}
add_action( 'init', 'ekwa_register_pattern_categories' );

/**
 * Allow SVG uploads and display them correctly in the media library.
 */
function ekwa_allow_svg_upload( $mimes ) {
	$mimes['svg']  = 'image/svg+xml';
	$mimes['svgz'] = 'image/svg+xml';
	return $mimes;
}
add_filter( 'upload_mimes', 'ekwa_allow_svg_upload' );

function ekwa_fix_svg_mime_check( $data, $file, $filename, $mimes ) {
	$ext = pathinfo( $filename, PATHINFO_EXTENSION );
	if ( 'svg' === strtolower( $ext ) ) {
		$data['type'] = 'image/svg+xml';
		$data['ext']  = 'svg';
	}
	return $data;
}
add_filter( 'wp_check_filetype_and_ext', 'ekwa_fix_svg_mime_check', 10, 4 );

/**
 * Sanitize raw SVG markup for safe inline output / storage.
 *
 * Strips XML processing instructions, <script> elements and inline event
 * handlers. Returns '' when the input contains no <svg> root (invalid).
 * Shared by the SVG upload prefilter, the SVG-logo setting save, and the
 * ekwa/svg-logo block render.
 *
 * @param string $svg Raw SVG markup.
 * @return string Sanitized markup, or '' if it isn't an SVG.
 */
function ekwa_sanitize_svg_markup( $svg ) {
	$svg = (string) $svg;
	if ( '' === $svg ) {
		return '';
	}
	$svg = preg_replace( '/<\?xml.*?\?>/s', '', $svg );
	$svg = preg_replace( '/<script[^>]*>.*?<\/script>/si', '', $svg );
	$svg = preg_replace( '/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $svg );

	if ( false === stripos( $svg, '<svg' ) ) {
		return '';
	}
	return trim( $svg );
}

function ekwa_sanitize_svg_on_upload( $file ) {
	if ( 'image/svg+xml' !== $file['type'] ) {
		return $file;
	}

	$contents = file_get_contents( $file['tmp_name'] );
	if ( false === $contents ) {
		$file['error'] = __( 'Could not read SVG file.', 'ekwa' );
		return $file;
	}

	$clean = ekwa_sanitize_svg_markup( $contents );
	if ( '' === $clean ) {
		$file['error'] = __( 'Invalid SVG file.', 'ekwa' );
		return $file;
	}

	file_put_contents( $file['tmp_name'], $clean );
	return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'ekwa_sanitize_svg_on_upload' );

function ekwa_svg_media_library_display() {
	echo '<style>
		.attachment-266x266, .thumbnail img[src$=".svg"] {
			width: 100% !important;
			height: auto !important;
		}
	</style>';
}
add_action( 'admin_head', 'ekwa_svg_media_library_display' );
