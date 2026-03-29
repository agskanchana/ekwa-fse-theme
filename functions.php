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
 * Load theme settings page.
 */
require_once get_template_directory() . '/inc/ekwa-settings.php';

/**
 * Load theme shortcodes.
 */
require_once get_template_directory() . '/inc/ekwa-shortcodes.php';

/**
 * Load custom block registrations and render callbacks.
 */

require_once get_template_directory() . '/inc/ekwa-blocks.php';

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
 * Register theme support.
 */
function ekwa_setup() {
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
