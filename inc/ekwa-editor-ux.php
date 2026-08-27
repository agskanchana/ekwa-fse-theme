<?php
/**
 * Editor UX — makes the ekwa block suite first-class in the block editor.
 *
 * 1. Branded block categories pinned to the top of the inserter so ekwa
 *    blocks are found before core blocks (each block's block.json points at
 *    one of these slugs).
 * 2. Brand-colored icons stamped on every ekwa/* block via a single
 *    blocks.registerBlockType filter injected right after wp-blocks loads,
 *    so it runs before any block registers — no per-block edits needed.
 * 3. Select mode (X-ray): editor toggle that flattens positioning/z-index in
 *    the canvas so overlapped blocks become selectable (JS + per-user
 *    preference; the CSS lives in assets/css/ekwa-editor.css).
 * 4. REST toggle for the existing "disable child CSS in editor" option so it
 *    can be flipped from the editor itself (admins only).
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Editor-UI brand color for ekwa blocks. Deliberately NOT the site palette
 * (child themes replace that per project) — this is a fixed identity color,
 * distinct from WP-admin blue, so ekwa blocks read at a glance.
 */
const EKWA_EDITOR_BRAND_BG = '#0e7c6b';
const EKWA_EDITOR_BRAND_FG = '#ffffff';

/**
 * Register the ekwa block categories at the TOP of the inserter.
 */
function ekwa_register_block_categories( $categories ) {
	$ekwa = array(
		array(
			'slug'  => 'ekwa-layout',
			'title' => __( 'Ekwa · Layout', 'ekwa' ),
		),
		array(
			'slug'  => 'ekwa-elements',
			'title' => __( 'Ekwa · Elements', 'ekwa' ),
		),
		array(
			'slug'  => 'ekwa-dynamic',
			'title' => __( 'Ekwa · Dynamic Data', 'ekwa' ),
		),
		array(
			'slug'  => 'ekwa-header-footer',
			'title' => __( 'Ekwa · Header & Footer', 'ekwa' ),
		),
		array(
			'slug'  => 'ekwa-blog',
			'title' => __( 'Ekwa · Blog & FAQ', 'ekwa' ),
		),
	);

	return array_merge( $ekwa, $categories );
}
add_filter( 'block_categories_all', 'ekwa_register_block_categories' );

/**
 * Stamp the brand icon colors on every ekwa/* block.
 *
 * Attached as an inline script AFTER wp-blocks: every block editor script
 * depends on wp-blocks, so this filter is guaranteed to be registered before
 * the first registerBlockType() call — enqueue order doesn't matter.
 */
function ekwa_editor_brand_icons_script() {
	$js = '( function ( wp ) {'
		. 'wp.hooks.addFilter( "blocks.registerBlockType", "ekwa/brand-icons", function ( settings, name ) {'
		. 'if ( name.indexOf( "ekwa/" ) !== 0 ) { return settings; }'
		. 'var src = ( settings.icon && settings.icon.src ) ? settings.icon.src : settings.icon;'
		. 'settings.icon = { src: src || "layout", foreground: "' . EKWA_EDITOR_BRAND_FG . '", background: "' . EKWA_EDITOR_BRAND_BG . '" };'
		. 'return settings;'
		. '} );'
		. '} )( window.wp );';
	wp_add_inline_script( 'wp-blocks', $js, 'after' );
}
add_action( 'enqueue_block_editor_assets', 'ekwa_editor_brand_icons_script' );

/**
 * Select mode (X-ray) editor plugin.
 */
function ekwa_enqueue_select_mode_script() {
	wp_enqueue_script(
		'ekwa-select-mode',
		get_template_directory_uri() . '/assets/js/ekwa-select-mode.js',
		array(
			'wp-plugins',
			'wp-editor',
			'wp-components',
			'wp-element',
			'wp-data',
			'wp-preferences',
			'wp-i18n',
			'wp-api-fetch',
		),
		filemtime( get_template_directory() . '/assets/js/ekwa-select-mode.js' ),
		true
	);

	wp_localize_script(
		'ekwa-select-mode',
		'ekwaSelectMode',
		array(
			// The child-CSS editor kill switch is a site option — admins only.
			'canToggleChildCss' => current_user_can( 'manage_options' ),
			'childCssDisabled'  => (bool) get_option( 'ekwa_editor_disable_child_css', 0 ),
			'hasChildTheme'     => get_template_directory() !== get_stylesheet_directory(),
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_select_mode_script' );

/**
 * REST: flip the existing "disable child CSS in editor" option from the
 * editor's tools menu. Editor styles are compiled server-side at load, so the
 * UI prompts for a reload after toggling.
 */
function ekwa_editor_ux_register_rest_routes() {
	register_rest_route(
		'ekwa/v1',
		'/editor-child-css',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'disabled' => array(
					'type'     => 'boolean',
					'required' => true,
				),
			),
			'callback'            => function ( $request ) {
				$disabled = (bool) $request->get_param( 'disabled' );
				update_option( 'ekwa_editor_disable_child_css', $disabled ? 1 : 0 );
				return rest_ensure_response( array( 'disabled' => $disabled ) );
			},
		)
	);
}
add_action( 'rest_api_init', 'ekwa_editor_ux_register_rest_routes' );
