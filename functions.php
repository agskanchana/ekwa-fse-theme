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
 * Load theme settings page.
 */
require_once get_template_directory() . '/inc/ekwa-settings.php';

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
 * Load WebP image support (auto-generation + transparent URL swap).
 */
require_once get_template_directory() . '/inc/ekwa-webp.php';

/**
 * Load block style variations.
 */
require_once get_template_directory() . '/inc/ekwa-block-styles.php';

/**
 * Load mockup converter REST API.
 */
require_once get_template_directory() . '/inc/ekwa-converter-api.php';

/**
 * Load AI refinement for mockup converter (Gemini API).
 */
require_once get_template_directory() . '/inc/ekwa-ai-refine.php';

/**
 * Load blog features (TOC, author link, load more, post cards).
 */
require_once get_template_directory() . '/inc/ekwa-blog.php';

/**
 * Load mobile menu: nav location, icon meta field, custom walker.
 */
require_once get_template_directory() . '/inc/ekwa-mobile-menu.php';

/**
 * Enqueue theme stylesheet and Font Awesome.
 */
function ekwa_enqueue_styles() {
	wp_enqueue_style(
		'ekwa-style',
		get_stylesheet_uri(),
		array(),
		wp_get_theme()->get( 'Version' )
	);
	wp_enqueue_style(
		'font-awesome',
		get_template_directory_uri() . '/assets/fontawesome/css/all.min.css',
		array(),
		'6.5.1'
	);
	wp_enqueue_style(
		'ekwa-mobile',
		get_template_directory_uri() . '/assets/css/ekwa-mobile.css',
		array( 'ekwa-style' ),
		wp_get_theme()->get( 'Version' )
	);
	wp_enqueue_style(
		'ekwa-blocks-css',
		get_template_directory_uri() . '/assets/css/ekwa-blocks.css',
		array( 'ekwa-style' ),
		wp_get_theme()->get( 'Version' )
	);
	wp_enqueue_style(
		'ekwa-block-styles',
		get_template_directory_uri() . '/assets/css/ekwa-block-styles.css',
		array( 'ekwa-style' ),
		wp_get_theme()->get( 'Version' )
	);
	wp_enqueue_script(
		'ekwa-blocks-js',
		get_template_directory_uri() . '/assets/js/ekwa-blocks.js',
		array(),
		wp_get_theme()->get( 'Version' ),
		true
	);
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
	add_editor_style( 'assets/css/ekwa-block-styles.css' );
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
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_converter_editor_script' );

/**
 * Register theme support.
 */
function ekwa_setup() {
	register_nav_menus( array(
		'primary'         => __( 'Primary Menu', 'ekwa' ),
		'mobile'          => __( 'Mobile Menu', 'ekwa' ),
		'mobile_services' => __( 'Mobile Services Menu', 'ekwa' ),
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

function ekwa_sanitize_svg_on_upload( $file ) {
	if ( 'image/svg+xml' !== $file['type'] ) {
		return $file;
	}

	$contents = file_get_contents( $file['tmp_name'] );
	if ( false === $contents ) {
		$file['error'] = __( 'Could not read SVG file.', 'ekwa' );
		return $file;
	}

	// Strip XML processing instructions, scripts, and event handlers.
	$contents = preg_replace( '/<\?xml.*?\?>/s', '', $contents );
	$contents = preg_replace( '/<script[^>]*>.*?<\/script>/si', '', $contents );
	$contents = preg_replace( '/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $contents );

	// Must contain an <svg tag to be valid.
	if ( false === stripos( $contents, '<svg' ) ) {
		$file['error'] = __( 'Invalid SVG file.', 'ekwa' );
		return $file;
	}

	file_put_contents( $file['tmp_name'], $contents );
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
