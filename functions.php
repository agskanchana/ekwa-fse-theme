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
 * Enqueue theme stylesheet.
 */
function ekwa_enqueue_styles() {
	wp_enqueue_style(
		'ekwa-style',
		get_stylesheet_uri(),
		array(),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'ekwa_enqueue_styles' );

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
