<?php
/**
 * Ekwa Theme Blocks.
 *
 * Block registration and server-side render callbacks.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register custom blocks and their editor scripts.
 */
function ekwa_register_blocks() {
	wp_register_script(
		'ekwa-conditional-editor',
		get_template_directory_uri() . '/assets/js/ekwa-conditional-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-data' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-conditional',
		array(
			'render_callback' => 'ekwa_render_conditional_block',
		)
	);

	// Google Map block.
	wp_register_script(
		'ekwa-map-editor',
		get_template_directory_uri() . '/assets/js/ekwa-map-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-map',
		array(
			'render_callback' => 'ekwa_render_map_block',
		)
	);

	// Icon block (standalone FA icon element).
	wp_register_script(
		'ekwa-icon-editor',
		get_template_directory_uri() . '/assets/js/ekwa-icon-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'ekwa-link-source-control' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-icon-editor.js' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-icon',
		array(
			'render_callback' => 'ekwa_render_icon_block',
		)
	);

	// Inline FA icon format (inserted into RichText blocks via toolbar).
	wp_register_script(
		'ekwa-icon-format',
		get_template_directory_uri() . '/assets/js/ekwa-icon-format.js',
		array( 'wp-rich-text', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	// Phone number block.
	wp_register_script(
		'ekwa-phone-editor',
		get_template_directory_uri() . '/assets/js/ekwa-phone-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-phone',
		array(
			'render_callback' => 'ekwa_render_phone_block',
		)
	);

	// Address block.
	wp_register_script(
		'ekwa-address-editor',
		get_template_directory_uri() . '/assets/js/ekwa-address-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-address',
		array(
			'render_callback' => 'ekwa_render_address_block',
		)
	);

	// Working Hours block.
	wp_register_script(
		'ekwa-hours-editor',
		get_template_directory_uri() . '/assets/js/ekwa-hours-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-hours',
		array(
			'render_callback' => 'ekwa_render_hours_block',
		)
	);

	// Copyright block.
	wp_register_script(
		'ekwa-copyright-editor',
		get_template_directory_uri() . '/assets/js/ekwa-copyright-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-copyright',
		array(
			'render_callback' => 'ekwa_render_copyright_block',
		)
	);

	// Social Icons block.
	wp_register_script(
		'ekwa-social-editor',
		get_template_directory_uri() . '/assets/js/ekwa-social-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render', 'wp-api-fetch' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-social',
		array(
			'render_callback' => 'ekwa_render_social_block',
		)
	);

	// Sitemap block.
	wp_register_script(
		'ekwa-sitemap-editor',
		get_template_directory_uri() . '/assets/js/ekwa-sitemap-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-sitemap',
		array(
			'render_callback' => 'ekwa_render_sitemap_block',
		)
	);

	// Search block.
	wp_register_script(
		'ekwa-search-editor',
		get_template_directory_uri() . '/assets/js/ekwa-search-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-search',
		array(
			'render_callback' => 'ekwa_render_search_block',
		)
	);

	// Scroll-to-top block.
	wp_register_script(
		'ekwa-scroll-top-editor',
		get_template_directory_uri() . '/assets/js/ekwa-scroll-top-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-scroll-top',
		array(
			'render_callback' => 'ekwa_render_scroll_top_block',
		)
	);

	// Hamburger menu block (mobile off-canvas nav).
	wp_register_script(
		'ekwa-hamburger-menu-editor',
		get_template_directory_uri() . '/assets/js/ekwa-hamburger-menu-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-hamburger-menu',
		array(
			'render_callback' => 'ekwa_render_hamburger_menu_block',
		)
	);

	// Header menu block (multi-level desktop nav with optional mega menus).
	wp_register_script(
		'ekwa-header-menu-editor',
		get_template_directory_uri() . '/assets/js/ekwa-header-menu-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-header-menu-editor.js' ),
		true
	);
	wp_register_style(
		'ekwa-header-menu-style',
		get_template_directory_uri() . '/assets/css/ekwa-header-menu.css',
		array(),
		filemtime( get_template_directory() . '/assets/css/ekwa-header-menu.css' )
	);
	wp_register_script(
		'ekwa-header-menu-view',
		get_template_directory_uri() . '/assets/js/ekwa-header-menu.js',
		array(),
		filemtime( get_template_directory() . '/assets/js/ekwa-header-menu.js' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-header-menu',
		array(
			'render_callback' => 'ekwa_render_header_menu_block',
		)
	);

	// Inner page banner block.
	wp_register_script(
		'ekwa-inner-banner-editor',
		get_template_directory_uri() . '/assets/js/ekwa-inner-banner-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-inner-banner',
		array(
			'render_callback' => 'ekwa_render_inner_banner_block',
		)
	);

	// Page title block (conditional).
	wp_register_script(
		'ekwa-page-title-editor',
		get_template_directory_uri() . '/assets/js/ekwa-page-title-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-page-title',
		array(
			'render_callback' => 'ekwa_render_page_title_block',
		)
	);

	// Phone dropdown block.
	wp_register_script(
		'ekwa-phone-dropdown-editor',
		get_template_directory_uri() . '/assets/js/ekwa-phone-dropdown-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-phone-dropdown',
		array(
			'render_callback' => 'ekwa_render_phone_dropdown_block',
		)
	);

	// Address dropdown block.
	wp_register_script(
		'ekwa-address-dropdown-editor',
		get_template_directory_uri() . '/assets/js/ekwa-address-dropdown-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-address-dropdown',
		array(
			'render_callback' => 'ekwa_render_address_dropdown_block',
		)
	);

	// Mobile dock block (floating bottom bar).
	wp_register_script(
		'ekwa-mobile-dock-editor',
		get_template_directory_uri() . '/assets/js/ekwa-mobile-dock-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-mobile-dock',
		array(
			'render_callback' => 'ekwa_render_mobile_dock_block',
		)
	);

	// Shared link-source Inspector helper (used by ekwa-link, ekwa-button, ekwa-card-link).
	wp_register_script(
		'ekwa-link-source-control',
		get_template_directory_uri() . '/assets/js/ekwa-link-source-control.js',
		array( 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-i18n' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-link-source-control.js' ),
		true
	);
	wp_localize_script(
		'ekwa-link-source-control',
		'ekwaBlockData',
		array(
			'appointmentUrl'  => ekwa_get_appointment_url(),
			'apptSettingsUrl' => admin_url( 'themes.php?page=ekwa-settings' ),
		)
	);

	// Card link block (linked card wrapper with InnerBlocks).
	wp_register_script(
		'ekwa-card-link-editor',
		get_template_directory_uri() . '/assets/js/ekwa-card-link-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'ekwa-link-source-control' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-card-link-editor.js' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-card-link',
		array(
			'render_callback' => 'ekwa_render_card_link_block',
		)
	);

	// Section block (semantic section wrapper with bg image + overlay).
	wp_register_script(
		'ekwa-section-editor',
		get_template_directory_uri() . '/assets/js/ekwa-section-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-section',
		array(
			'render_callback' => 'ekwa_render_section_block',
		)
	);

	// Container block (centered max-width wrapper).
	wp_register_script(
		'ekwa-container-editor',
		get_template_directory_uri() . '/assets/js/ekwa-container-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-container',
		array(
			'render_callback' => 'ekwa_render_container_block',
		)
	);

	// Flex block (flexbox container).
	wp_register_script(
		'ekwa-flex-editor',
		get_template_directory_uri() . '/assets/js/ekwa-flex-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-flex',
		array(
			'render_callback' => 'ekwa_render_flex_block',
		)
	);

	// Grid block (CSS Grid with responsive breakpoints).
	wp_register_script(
		'ekwa-grid-editor',
		get_template_directory_uri() . '/assets/js/ekwa-grid-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-grid',
		array(
			'render_callback' => 'ekwa_render_grid_block',
		)
	);

	// Carousel block (responsive, ADA-compliant).
	// Shared frontend script + style — auto-enqueued by WP only on pages where the block (or another consumer) appears.
	wp_register_script(
		'ekwa-carousel-view',
		get_template_directory_uri() . '/assets/js/ekwa-carousel.js',
		array(),
		filemtime( get_template_directory() . '/assets/js/ekwa-carousel.js' ),
		true
	);
	wp_register_style(
		'ekwa-carousel-style',
		get_template_directory_uri() . '/assets/css/ekwa-carousel.css',
		array(),
		filemtime( get_template_directory() . '/assets/css/ekwa-carousel.css' )
	);
	wp_register_script(
		'ekwa-carousel-editor',
		get_template_directory_uri() . '/assets/js/ekwa-carousel-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-carousel-editor.js' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-carousel',
		array(
			'render_callback' => 'ekwa_render_carousel_block',
		)
	);

	// Button block (clean <a> or <button>).
	wp_register_script(
		'ekwa-button-editor',
		get_template_directory_uri() . '/assets/js/ekwa-button-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'ekwa-link-source-control' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-button-editor.js' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-button',
		array(
			'render_callback' => 'ekwa_render_button_block',
		)
	);

	// Button Group block (flex wrapper for buttons).
	wp_register_script(
		'ekwa-button-group-editor',
		get_template_directory_uri() . '/assets/js/ekwa-button-group-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-button-group',
		array(
			'render_callback' => 'ekwa_render_button_group_block',
		)
	);

	// Text block (inline text element — <span>, <small>, etc.).
	wp_register_script(
		'ekwa-text-editor',
		get_template_directory_uri() . '/assets/js/ekwa-text-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-text',
		array(
			'render_callback' => 'ekwa_render_text_block',
		)
	);

	// Image block (clean <img> — no figure wrapper).
	wp_register_script(
		'ekwa-image-editor',
		get_template_directory_uri() . '/assets/js/ekwa-image-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-image',
		array(
			'render_callback' => 'ekwa_render_image_block',
		)
	);

	// Div block (clean wrapper — any HTML tag, no layout styles).
	wp_register_script(
		'ekwa-div-editor',
		get_template_directory_uri() . '/assets/js/ekwa-div-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-div-editor.js' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-div',
		array(
			'render_callback' => 'ekwa_render_div_block',
		)
	);

	// Video block (clean <video> — no figure wrapper).
	wp_register_script(
		'ekwa-video-editor',
		get_template_directory_uri() . '/assets/js/ekwa-video-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-video',
		array(
			'render_callback' => 'ekwa_render_video_block',
		)
	);

	// Link block (clean <a> — no button styles).
	wp_register_script(
		'ekwa-link-editor',
		get_template_directory_uri() . '/assets/js/ekwa-link-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'ekwa-link-source-control' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-link-editor.js' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-link',
		array(
			'render_callback' => 'ekwa_render_link_block',
		)
	);

	// FAQ block (collapsible Q&A list with FAQPage schema markup).
	wp_register_script(
		'ekwa-faq-editor',
		get_template_directory_uri() . '/assets/js/ekwa-faq-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-faq-editor.js' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-faq',
		array(
			'render_callback' => 'ekwa_render_faq_block',
		)
	);

	// FAQ Item child block.
	wp_register_script(
		'ekwa-faq-item-editor',
		get_template_directory_uri() . '/assets/js/ekwa-faq-item-editor.js',
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-faq-item-editor.js' ),
		true
	);

	register_block_type(
		get_template_directory() . '/blocks/ekwa-faq-item',
		array(
			'render_callback' => 'ekwa_render_faq_item_block',
		)
	);

	// ── Blog Blocks ─────────────────────────────────────────────────────────

	$blog_blocks = array(
		'back-to-category' => array( 'deps' => array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-server-side-render' ) ),
		'read-time'        => array( 'deps' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ) ),
		'share-button'     => array( 'deps' => array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n' ) ),
		'toc'              => array( 'deps' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ) ),
		'related-articles' => array( 'deps' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ) ),
		'load-more'        => array( 'deps' => array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ) ),
	);

	foreach ( $blog_blocks as $slug => $config ) {
		$handle = 'ekwa-' . $slug . '-editor';
		wp_register_script(
			$handle,
			get_template_directory_uri() . '/assets/js/' . $handle . '.js',
			$config['deps'],
			filemtime( get_template_directory() . '/assets/js/' . $handle . '.js' ),
			true
		);
		register_block_type(
			get_template_directory() . '/blocks/ekwa-' . $slug,
			array(
				'render_callback' => 'ekwa_render_' . str_replace( '-', '_', $slug ) . '_block',
			)
		);
	}
}
add_action( 'init', 'ekwa_register_blocks' );

/**
 * Enqueue editor-only assets: inline format script + picker CSS.
 * The ekwa-editor-css style is also registered via add_editor_style() in
 * functions.php so it loads inside the FSE iframe canvas too.
 */
function ekwa_enqueue_editor_assets() {
	wp_enqueue_script( 'ekwa-icon-format' );
	wp_enqueue_style(
		'ekwa-editor-css',
		get_template_directory_uri() . '/assets/css/ekwa-editor.css',
		array(),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_editor_assets' );


/**
 * Server-side render callback for the ekwa/conditional block.
 *
 * Evaluates every condition in order. Returns empty string on the first
 * failing condition so inner block markup is never sent to the browser.
 *
 * Conditions (evaluated in this order):
 *   1. Page visibility   – show/hide on specific page IDs
 *   2. Content type      – post, page, front page, archive, search, 404…
 *   3. Device type       – mobile / desktop (wp_is_mobile)
 *   4. User state        – logged-in / logged-out  (+optional role filter)
 *   5. Ad tracking       – adward_number cookie or ?ads URL param
 *   6. Schedule          – site-timezone date-time range
 *   7. Days of week      – restrict to specific days (site timezone)
 *
 * @param  array  $attrs   Block attributes.
 * @param  string $content Rendered InnerBlocks HTML.
 * @return string          $content if conditions pass, '' otherwise.
 */
function ekwa_render_conditional_block( $attrs, $content ) {

	// Attribute defaults.
	$page_visibility   = $attrs['pageVisibility']  ?? 'everywhere';
	$selected_page_ids = array_map( 'intval', $attrs['selectedPageIds'] ?? array() );
	$content_type      = $attrs['contentType']     ?? 'all';
	$device_type       = $attrs['deviceType']      ?? 'all';
	$user_state        = $attrs['userState']        ?? 'all';
	$user_roles        = $attrs['userRoles']        ?? array();
	$ad_tracking_rule  = $attrs['adTracking']       ?? 'all';
	$schedule_enabled  = $attrs['scheduleEnabled']  ?? false;
	$schedule_from     = $attrs['scheduleFrom']     ?? '';
	$schedule_to       = $attrs['scheduleTo']       ?? '';
	$days_of_week      = $attrs['daysOfWeek']       ?? array();

	/* ------------------------------------------------------------------
	 * 1. Page Visibility
	 * ------------------------------------------------------------------ */
	if ( 'show_on' === $page_visibility && ! empty( $selected_page_ids ) ) {
		if ( ! is_page( $selected_page_ids ) ) {
			return '';
		}
	} elseif ( 'hide_on' === $page_visibility && ! empty( $selected_page_ids ) ) {
		if ( is_page( $selected_page_ids ) ) {
			return '';
		}
	}

	/* ------------------------------------------------------------------
	 * 2. Content Type
	 * ------------------------------------------------------------------ */
	switch ( $content_type ) {
		case 'posts_only':
			if ( ! is_singular( 'post' ) ) { return ''; }
			break;
		case 'pages_only':
			if ( ! is_page() ) { return ''; }
			break;
		case 'front_page':
			if ( ! is_front_page() ) { return ''; }
			break;
		case 'archive':
			if ( ! is_archive() ) { return ''; }
			break;
		case 'search':
			if ( ! is_search() ) { return ''; }
			break;
		case '404':
			if ( ! is_404() ) { return ''; }
			break;
		case 'hide_on_posts':
			if ( is_singular( 'post' ) ) { return ''; }
			break;
		case 'hide_on_pages':
			if ( is_page() ) { return ''; }
			break;
	}

	/* ------------------------------------------------------------------
	 * 3. Device Type
	 * ------------------------------------------------------------------ */
	if ( 'all' !== $device_type ) {
		$is_mobile = wp_is_mobile();
		if ( 'mobile_only'  === $device_type && ! $is_mobile ) { return ''; }
		if ( 'desktop_only' === $device_type &&   $is_mobile ) { return ''; }
	}

	/* ------------------------------------------------------------------
	 * 4. User State / Roles
	 * ------------------------------------------------------------------ */
	if ( 'logged_in' === $user_state ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		// Optional: restrict to specific roles.
		if ( ! empty( $user_roles ) ) {
			$current_user = wp_get_current_user();
			if ( empty( array_intersect( $user_roles, (array) $current_user->roles ) ) ) {
				return '';
			}
		}
	} elseif ( 'logged_out' === $user_state ) {
		if ( is_user_logged_in() ) {
			return '';
		}
	}

	/* ------------------------------------------------------------------
	 * 5. Ad Tracking
	 *    Active when: adward_number cookie is set  OR  ?ads in URL.
	 * ------------------------------------------------------------------ */
	if ( 'all' !== $ad_tracking_rule ) {
		$is_tracking = (
			isset( $_COOKIE['adward_number'] ) ||
			isset( $_GET['ads'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
		if ( 'tracking_only'      === $ad_tracking_rule && ! $is_tracking ) { return ''; }
		if ( 'hide_when_tracking' === $ad_tracking_rule &&   $is_tracking ) { return ''; }
	}

	/* ------------------------------------------------------------------
	 * 6. Schedule (site timezone)
	 * ------------------------------------------------------------------ */
	if ( $schedule_enabled ) {
		try {
			$tz  = wp_timezone();
			$now = new DateTime( 'now', $tz );

			if ( $schedule_from ) {
				$from = new DateTime( $schedule_from, $tz );
				if ( $now < $from ) { return ''; }
			}
			if ( $schedule_to ) {
				$to = new DateTime( $schedule_to, $tz );
				if ( $now > $to ) { return ''; }
			}
		} catch ( Exception $e ) {
			// Invalid date string — skip schedule check rather than hiding content.
		}
	}

	/* ------------------------------------------------------------------
	 * 7. Days of Week (site timezone, empty = all days)
	 * ------------------------------------------------------------------ */
	if ( ! empty( $days_of_week ) ) {
		$today = wp_date( 'l' ); // e.g. 'Monday'
		if ( ! in_array( $today, $days_of_week, true ) ) {
			return '';
		}
	}

	/* All conditions passed — output the inner blocks. */
	return $content;
}

/**
 * Server-side render callback for the ekwa/map block.
 *
 * Extracts the src from the saved iframe embed code, validates it is a
 * legitimate Google Maps URL, then outputs a clean, full-width iframe.
 * filter:none is applied so no theme stylesheet can accidentally make it grey.
 *
 * @param  array $attrs  Block attributes (embedCode, height).
 * @return string        HTML output or empty string when no valid src is found.
 */
function ekwa_render_map_block( $attrs ) {
	$embed_code = isset( $attrs['embedCode'] ) ? $attrs['embedCode'] : '';
	$height     = isset( $attrs['height'] )    ? absint( $attrs['height'] ) : 450;
	$colorful   = isset( $attrs['colorful'] )  ? (bool) $attrs['colorful'] : true;
	$filter     = $colorful ? 'none' : 'grayscale(100%)';

	if ( empty( $embed_code ) ) {
		return '';
	}

	// Pull the src attribute out of the raw iframe string.
	if ( ! preg_match( '/src=["\']([^"\']+)["\']/i', $embed_code, $matches ) ) {
		return '';
	}

	$src = esc_url( $matches[1] );

	// Accept Google Maps embed URLs only (security: prevent arbitrary iframe injection).
	if ( ! preg_match( '#^https://www\.google\.com/maps/#', $src ) ) {
		return '';
	}

	return sprintf(
		'<div class="ekwa-map-wrapper" style="width:100%%;overflow:hidden;">' .
		'<iframe src="%s" width="100%%" height="%d" ' .
		'style="border:0;display:block;width:100%%;filter:%s;-webkit-filter:%s;" ' .
		'allowfullscreen="" loading="lazy" ' .
		'referrerpolicy="no-referrer-when-downgrade" ' .
		'title="%s"></iframe>' .
		'</div>',
		$src,
		$height,
		$filter,
		$filter,
		esc_attr__( 'Google Map', 'ekwa' )
	);
}

/**
 * Server-side render callback for the ekwa/icon block.
 *
 * Outputs: <div class="way-icon"><i class="fa-solid fa-bolt" aria-hidden="true"></i></div>
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_icon_block( $attrs ) {
	$icon_class    = sanitize_text_field( isset( $attrs['iconClass'] )    ? $attrs['iconClass']    : 'fa-solid fa-star' );
	$wrapper_class = isset( $attrs['wrapperClass'] ) ? sanitize_text_field( $attrs['wrapperClass'] ) : 'way-icon';
	$size          = isset( $attrs['size'] ) ? absint( $attrs['size'] ) : 0;
	$color         = sanitize_text_field( isset( $attrs['color'] ) ? $attrs['color'] : '' );
	$align_raw     = isset( $attrs['align'] ) ? $attrs['align'] : '';
	$anchor        = isset( $attrs['anchor'] ) ? sanitize_html_class( $attrs['anchor'] ) : '';
	$url           = ekwa_resolve_block_link_url( $attrs );
	$link_target   = isset( $attrs['linkTarget'] ) ? sanitize_text_field( $attrs['linkTarget'] ) : '';
	$link_rel      = isset( $attrs['linkRel'] )    ? sanitize_text_field( $attrs['linkRel'] )    : '';

	$align = in_array( $align_raw, array( 'left', 'center', 'right' ), true ) ? $align_raw : '';

	$icon_style = '';
	if ( $size )  { $icon_style .= 'font-size:' . $size . 'px;'; }
	if ( $color ) { $icon_style .= 'color:' . esc_attr( $color ) . ';'; }

	$icon_attrs  = ' class="' . esc_attr( $icon_class ) . '" aria-hidden="true"';
	if ( $icon_style ) { $icon_attrs .= ' style="' . esc_attr( $icon_style ) . '"'; }

	$icon_html = '<i' . $icon_attrs . '></i>';

	if ( $url ) {
		$target_attr = ( '_blank' === $link_target ) ? ' target="_blank"' : '';
		$rel_parts   = array();
		if ( '_blank' === $link_target ) {
			$rel_parts[] = 'noopener';
			$rel_parts[] = 'noreferrer';
		}
		if ( $link_rel ) {
			$rel_parts[] = $link_rel;
		}
		$rel_attr  = $rel_parts ? ' rel="' . esc_attr( implode( ' ', array_unique( $rel_parts ) ) ) . '"' : '';
		$icon_html = '<a href="' . esc_url( $url ) . '"' . $target_attr . $rel_attr . '>' . $icon_html . '</a>';
	}

	// No wrapper div when wrapperClass is empty — output bare <i> element.
	if ( $wrapper_class === '' ) {
		return $icon_html;
	}

	$wrapper_attrs  = ' class="' . esc_attr( $wrapper_class ) . '"';
	if ( $anchor )  { $wrapper_attrs .= ' id="' . esc_attr( $anchor ) . '"'; }
	if ( $align )   { $wrapper_attrs .= ' style="text-align:' . esc_attr( $align ) . ';"'; }

	return '<div' . $wrapper_attrs . '>' . $icon_html . '</div>';
}

/**
 * Server-side render callback for the ekwa/phone block.
 *
 * Delegates to the shortcode function so rendering logic stays in one place.
 *
 * @param array $attrs Block attributes (camelCase from block.json).
 * @return string
 */
function ekwa_render_phone_block( $attrs ) {
	$shortcode_atts = array(
		'type'         => isset( $attrs['type'] )        ? $attrs['type']                                          : 'new',
		'location'     => isset( $attrs['location'] )    ? $attrs['location']                                      : 1,
		'prefix'       => isset( $attrs['prefix'] )      ? $attrs['prefix']                                        : '',
		'show_prefix'  => isset( $attrs['showPrefix'] )  ? ( $attrs['showPrefix'] ? 'true' : 'false' )             : 'true',
		'show_icon'    => isset( $attrs['showIcon'] )    ? ( $attrs['showIcon'] ? 'true' : 'false' )               : 'true',
		'icon_class'   => isset( $attrs['iconClass'] )   ? $attrs['iconClass']                                     : 'fa-solid fa-phone',
		'country_code' => isset( $attrs['countryCode'] ) ? $attrs['countryCode']                                   : '',
	);
	return ekwa_phone_shortcode( $shortcode_atts );
}

/**
 * Server-side render callback for the ekwa/address block.
 *
 * Delegates to the shortcode function so rendering logic stays in one place.
 *
 * @param array $attrs Block attributes (camelCase from block.json).
 * @return string
 */
function ekwa_render_address_block( $attrs ) {
	$shortcode_atts = array(
		'location'   => isset( $attrs['location'] )  ? $attrs['location']                                    : 1,
		'mode'       => isset( $attrs['mode'] )       ? $attrs['mode']                                        : 'full',
		'label'      => isset( $attrs['label'] )      ? $attrs['label']                                       : '',
		'show_icon'  => isset( $attrs['showIcon'] )   ? ( $attrs['showIcon'] ? 'true' : 'false' )             : 'true',
		'icon_class' => isset( $attrs['iconClass'] )  ? $attrs['iconClass']                                   : 'fa-solid fa-location-dot',
		'new_tab'    => isset( $attrs['newTab'] )     ? ( $attrs['newTab'] ? 'true' : 'false' )               : 'true',
	);
	return ekwa_address_shortcode( $shortcode_atts );
}

/**
 * Server-side render callback for the ekwa/hours block.
 *
 * @param array $attrs Block attributes (camelCase from block.json).
 * @return string
 */
function ekwa_render_hours_block( $attrs ) {
	$shortcode_atts = array(
		'location'     => isset( $attrs['location'] )    ? $attrs['location']                                        : 1,
		'group'        => isset( $attrs['group'] )        ? $attrs['group']                                          : 'none',
		'show_closed'  => isset( $attrs['showClosed'] )   ? ( $attrs['showClosed']  ? 'true' : 'false' )            : 'true',
		'short_days'   => isset( $attrs['shortDays'] )    ? ( $attrs['shortDays']   ? 'true' : 'false' )            : 'false',
		'show_notes'   => isset( $attrs['showNotes'] )    ? ( $attrs['showNotes']   ? 'true' : 'false' )            : 'true',
		'closed_label' => isset( $attrs['closedLabel'] )  ? $attrs['closedLabel']                                   : 'Closed',
	);
	return ekwa_hours_shortcode( $shortcode_atts );
}

/**
 * Server-side render callback for the ekwa/copyright block.
 *
 * @return string
 */
function ekwa_render_copyright_block() {
	return ekwa_copyright_shortcode();
}

/**
 * Server-side render callback for the ekwa/social block.
 *
 * Mirrors the [ekwa_social] shortcode output but adds per-icon colour and
 * size support via block attributes.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_social_block( $attrs ) {
	$show_share       = isset( $attrs['showShare'] )       ? (bool) $attrs['showShare']  : true;
	$icon_size        = isset( $attrs['iconSize'] )        ? absint( $attrs['iconSize'] ) : 0;
	$icon_color       = isset( $attrs['iconColor'] )       ? sanitize_hex_color( $attrs['iconColor'] ) : '';
	$icon_colors      = ( isset( $attrs['iconColors'] ) && is_array( $attrs['iconColors'] ) )
		? $attrs['iconColors']
		: array();
	// Share button icon colour: explicit value wins, then falls back to global colour.
	$share_icon_color = isset( $attrs['shareIconColor'] ) ? sanitize_hex_color( $attrs['shareIconColor'] ) : '';
	if ( ! $share_icon_color ) {
		$share_icon_color = $icon_color;
	}

	$links = get_option( 'ekwa_social', array() );
	if ( empty( $links ) || ! is_array( $links ) ) {
		return '';
	}

	static $instance = 0;
	$instance++;
	$uid   = 'ekwa-soc-blk-' . $instance;
	$js_fn = 'ekwaSocBlkToggle' . $instance;

	/* CSS and JS are now in ekwa-blocks.css / ekwa-blocks.js. */
	$out = '';

	$permalink = urlencode( (string) get_permalink() );
	$title     = urlencode( (string) get_the_title() );

	$out .= '<div id="' . esc_attr( $uid ) . '" class="ekwa-social-icons"><div class="social-media">';

	foreach ( $links as $idx => $link ) {
		$link = wp_parse_args( $link, array( 'name' => '', 'link' => '', 'icon' => '' ) );
		if ( empty( $link['link'] ) ) {
			continue;
		}

		$label = ! empty( $link['name'] ) ? esc_attr( $link['name'] ) : esc_attr__( 'Social Media', 'ekwa' );

		// Per-icon colour overrides global colour.
		$color = '';
		if ( ! empty( $icon_colors[ $idx ] ) ) {
			$color = sanitize_hex_color( $icon_colors[ $idx ] );
		} elseif ( $icon_color ) {
			$color = $icon_color;
		}

		$icon_style = '';
		if ( $icon_size ) { $icon_style .= 'font-size:' . $icon_size . 'px;'; }
		if ( $color )     { $icon_style .= 'color:' . esc_attr( $color ) . ';'; }

		$out .= '<a class="sm-icons" aria-label="' . $label . '" rel="noopener noreferrer" target="_blank" href="' . esc_url( $link['link'] ) . '">';
		if ( ! empty( $link['icon'] ) ) {
			$style_attr = $icon_style ? ' style="' . esc_attr( $icon_style ) . '"' : '';
			$out .= '<i class="' . esc_attr( $link['icon'] ) . '"' . $style_attr . '></i>';
		}
		$out .= '</a>';
	}

	if ( $show_share ) {
		$share_icon_style = '';
		if ( $icon_size )        { $share_icon_style .= 'font-size:' . $icon_size . 'px;'; }
		if ( $share_icon_color ) { $share_icon_style .= 'color:' . esc_attr( $share_icon_color ) . ';'; }
		$share_icon_attr = $share_icon_style ? ' style="' . esc_attr( $share_icon_style ) . '"' : '';

		$out .= '<button class="addthis" aria-label="' . esc_attr__( 'Toggle Share', 'ekwa' ) . '" onclick="' . esc_js( $js_fn ) . '()" type="button">'
			. '<i class="fa-solid fa-share-nodes"' . $share_icon_attr . '></i>'
			. '<span class="hide">' . esc_html__( 'Share', 'ekwa' ) . '</span>'
			. '<div id="share-toggle-' . esc_attr( $uid ) . '" class="share-toggle">'
			. '<a aria-label="' . esc_attr__( 'Share on Facebook', 'ekwa' ) . '" class="share-facebook" rel="noopener noreferrer"'
			. ' href="https://www.facebook.com/sharer/sharer.php?u=' . $permalink . '&amp;t=' . $title . '"'
			. ' onclick="window.open(this.href,\'\',\'menubar=no,toolbar=no,resizable=yes,scrollbars=yes,height=300,width=600\');return false;"'
			. ' target="_blank"><i class="fa-brands fa-facebook-f"></i></a>'
			. '<a aria-label="' . esc_attr__( 'Share on X / Twitter', 'ekwa' ) . '" class="share-twit" rel="noopener noreferrer"'
			. ' href="https://twitter.com/share?url=' . $permalink . '&amp;text=' . $title . '"'
			. ' onclick="window.open(this.href,\'\',\'menubar=no,toolbar=no,resizable=yes,scrollbars=yes,height=300,width=600\');return false;"'
			. ' target="_blank"><i class="fa-brands fa-x-twitter"></i></a>'
			. '<a aria-label="' . esc_attr__( 'Share on Pinterest', 'ekwa' ) . '" class="share-pinterest" rel="noopener noreferrer"'
			. ' href="https://www.pinterest.com/pin/create/button/?url=' . $permalink . '"'
			. ' target="_blank"><i class="fa-brands fa-pinterest-p"></i></a>'
			. '</div>'
			. '</button>';

		$out .= '<script>function ' . esc_js( $js_fn ) . '(){'
			. 'var el=document.getElementById("share-toggle-' . esc_js( $uid ) . '");'
			. 'if(el){el.classList.toggle("active");}'
			. '}</script>';
	}

	$out .= '</div></div>';
	return $out;
}

/**
 * Server-side render callback for the ekwa/sitemap block.
 *
 * Outputs a collapsible, hierarchical page tree with "Collapse All / Expand All"
 * controls. Toggle behaviour is handled by a small vanilla-JS snippet injected
 * inline — no jQuery required. Can pull from all published pages (default) or
 * from a WordPress nav menu when useMenu is true.
 *
 * @param array $attrs Block attributes.
 * @return string HTML output.
 */
function ekwa_render_sitemap_block( $attrs ) {

	/* ---- Attribute parsing ---- */
	$title           = sanitize_text_field( $attrs['title']           ?? '' );
	$depth           = absint( $attrs['depth']                        ?? 0 );
	$columns         = max( 1, min( 4, absint( $attrs['columns']      ?? 1 ) ) );
	$sort_by         = sanitize_key( $attrs['sortBy']                 ?? 'menu_order' );
	$sort_order      = strtoupper( $attrs['sortOrder']                ?? 'ASC' );
	$exclude_raw     = $attrs['excludeIds']                           ?? '';
	$show_desc       = (bool) ( $attrs['showDescription']             ?? false );
	$show_date       = (bool) ( $attrs['showDate']                    ?? false );
	$show_controls   = (bool) ( $attrs['showControls']                ?? true );
	$start_collapsed = (bool) ( $attrs['startCollapsed']              ?? true );
	$use_menu        = (bool) ( $attrs['useMenu']                     ?? false );
	$menu_slug       = sanitize_text_field( $attrs['menuSlug']        ?? 'site-map' );
	$link_color      = sanitize_hex_color( $attrs['linkColor']        ?? '' );
	$ctrl_color      = sanitize_hex_color( $attrs['controlColor']     ?? '' );
	$anchor          = sanitize_html_class( $attrs['anchor']          ?? '' );

	$valid_sort = array( 'menu_order', 'title', 'date', 'modified', 'ID' );
	if ( ! in_array( $sort_by, $valid_sort, true ) ) {
		$sort_by = 'menu_order';
	}
	$sort_order = in_array( $sort_order, array( 'ASC', 'DESC' ), true ) ? $sort_order : 'ASC';

	/* ---- Build normalised tree ---- */
	$tree = $use_menu
		? ekwa_sitemap_menu_tree( $menu_slug )
		: ekwa_sitemap_page_tree( $sort_by, $sort_order, $exclude_raw );

	if ( empty( $tree ) ) {
		return '';
	}

	/* ---- Shared CSS — printed once per page ---- */
	global $ekwa_sitemap_css_done;
	$out = '';
	if ( empty( $ekwa_sitemap_css_done ) ) {
		$ekwa_sitemap_css_done = true;
		$out .= '<style id="ekwa-sitemap-css">'
			. '.ekwa-sitemap__controls{margin-bottom:12px;font-weight:700}'
			. '.ekwa-sitemap__controls a{'
			. 'color:var(--ekwa-sm-ctrl,var(--ekwa-sm-link,unset));'
			. 'text-decoration:none;cursor:pointer}'
			. '.ekwa-sitemap__controls a:hover{text-decoration:underline}'
			. '.ekwa-sitemap__title{margin-bottom:16px}'
			. '.ekwa-sitemap__list,.ekwa-sitemap__children{'
			. 'list-style:none!important;margin:0;padding:0}'
			. '.ekwa-sitemap__children{padding-left:20px}'
			. '.ekwa-sitemap__item{padding:2px 0;line-height:1.8}'
			. '.ekwa-sitemap__item-inner{'
			. 'display:flex;align-items:center;gap:4px}'
			. '.ekwa-sitemap__toggle{'
			. 'display:inline-flex;align-items:center;justify-content:center;'
			. 'width:15px;height:15px;min-width:15px;'
			. 'border:1px solid #888;background:#fff;color:#444;'
			. 'font-size:11px;line-height:1;cursor:pointer;padding:0;'
			. 'font-family:monospace;font-weight:700;flex-shrink:0;'
			. 'border-radius:0}'
			. '.ekwa-sitemap__toggle:hover{background:#f0f0f0}'
			. '.ekwa-sitemap__link{'
			. 'color:var(--ekwa-sm-link,unset);text-decoration:none}'
			. '.ekwa-sitemap__link:hover{text-decoration:underline}'
			. '.ekwa-sitemap__desc{'
			. 'font-size:.85em;color:#666;margin:0 0 2px 19px;'
			. 'padding:0;line-height:1.4}'
			. '.ekwa-sitemap__date{'
			. 'font-size:.8em;color:#999;margin-left:4px}'
			. '</style>';
	}

	/* ---- Unique wrapper ID ---- */
	static $sm_n = 0;
	++$sm_n;
	$uid = $anchor ?: 'ekwa-sitemap-' . $sm_n;

	/* ---- Wrapper opening tag ---- */
	$wrapper_class = 'ekwa-sitemap';
	if ( $columns > 1 ) {
		$wrapper_class .= ' ekwa-sitemap--cols-' . $columns;
	}
	$wrapper_style = '';
	if ( $link_color ) {
		$wrapper_style .= '--ekwa-sm-link:' . esc_attr( $link_color ) . ';';
	}
	if ( $ctrl_color ) {
		$wrapper_style .= '--ekwa-sm-ctrl:' . esc_attr( $ctrl_color ) . ';';
	}

	$out .= '<nav id="' . esc_attr( $uid ) . '"'
		. ' class="' . esc_attr( $wrapper_class ) . '"'
		. ( $wrapper_style ? ' style="' . $wrapper_style . '"' : '' )
		. ' aria-label="' . esc_attr__( 'Site Map', 'ekwa' ) . '">';

	if ( $title ) {
		$out .= '<h2 class="ekwa-sitemap__title">' . esc_html( $title ) . '</h2>';
	}

	if ( $show_controls ) {
		$out .= '<div class="ekwa-sitemap__controls">'
			. '<a class="ekwa-sitemap__btn-collapse" href="#">'
			. esc_html__( 'Collapse All', 'ekwa' )
			. '</a>'
			. ' | '
			. '<a class="ekwa-sitemap__btn-expand" href="#">'
			. esc_html__( 'Expand All', 'ekwa' )
			. '</a>'
			. '</div>';
	}

	/* ---- Recursive renderer ---- */
	$render = null;
	$render = function ( $nodes, $cur ) use ( &$render, $depth, $show_desc, $show_date ) {
		$html = '';
		foreach ( $nodes as $node ) {
			$has_kids = ! empty( $node['children'] );
			$html    .= '<li class="ekwa-sitemap__item'
				. ( $has_kids ? ' ekwa-sitemap__item--has-children' : '' )
				. '">';

			/* Inner row: (toggle injected by JS) + link + optional date */
			$html .= '<span class="ekwa-sitemap__item-inner">'
				. '<a class="ekwa-sitemap__link" href="' . esc_url( $node['url'] ) . '">'
				. esc_html( $node['title'] )
				. '</a>';

			if ( $show_date && ! empty( $node['date_display'] ) ) {
				$html .= '<time class="ekwa-sitemap__date"'
					. ' datetime="' . esc_attr( $node['date_iso'] ) . '">'
					. esc_html( $node['date_display'] )
					. '</time>';
			}

			$html .= '</span>';

			/* Optional description (below the row) */
			if ( $show_desc && ! empty( $node['excerpt'] ) ) {
				$html .= '<p class="ekwa-sitemap__desc">'
					. esc_html( $node['excerpt'] )
					. '</p>';
			}

			/* Recurse */
			if ( $has_kids && ( 0 === $depth || $cur < $depth ) ) {
				$html .= '<ul class="ekwa-sitemap__children">';
				$html .= $render( $node['children'], $cur + 1 );
				$html .= '</ul>';
			}

			$html .= '</li>';
		}
		return $html;
	};

	$out .= '<ul class="ekwa-sitemap__list">';
	$out .= $render( $tree, 1 );
	$out .= '</ul>';

	/* Column styles (scoped to this instance) */
	if ( $columns > 1 ) {
		$out .= '<style>'
			. '#' . esc_attr( $uid ) . '>.ekwa-sitemap__list{'
			. 'columns:' . $columns . ';column-gap:2em}'
			. '#' . esc_attr( $uid ) . '>.ekwa-sitemap__list>.ekwa-sitemap__item{'
			. 'break-inside:avoid;page-break-inside:avoid}'
			. '</style>';
	}

	$out .= '</nav>';

	/* ---- Inline JS: collapsible tree + controls ---- */
	$sc = $start_collapsed ? 'true' : 'false';

	$out .= '<script>(function(id,sc){'

		/* Locate root */
		. 'var root=document.getElementById(id);if(!root)return;'

		/* Add toggle button to every item that has children */
		. 'root.querySelectorAll(".ekwa-sitemap__item--has-children").forEach(function(li){'
		. 'var inner=li.querySelector(":scope>.ekwa-sitemap__item-inner");'
		. 'var ul=li.querySelector(":scope>.ekwa-sitemap__children");'
		. 'if(!inner||!ul)return;'
		. 'var btn=document.createElement("button");'
		. 'btn.className="ekwa-sitemap__toggle";btn.type="button";'
		. 'if(sc){'
		. 'ul.style.display="none";'
		. 'btn.textContent="+";btn.setAttribute("aria-expanded","false");'
		. '}else{'
		. 'btn.textContent="\u2212";btn.setAttribute("aria-expanded","true");'
		. '}'
		. 'btn.addEventListener("click",function(e){'
		. 'e.preventDefault();'
		. 'var open=ul.style.display!=="none";'
		. 'ul.style.display=open?"none":"";'
		. 'btn.textContent=open?"+":"\u2212";'
		. 'btn.setAttribute("aria-expanded",open?"false":"true");'
		. '});'
		. 'inner.insertBefore(btn,inner.firstChild);'
		. '});'

		/* Collapse All */
		. 'var ca=root.querySelector(".ekwa-sitemap__btn-collapse");'
		. 'if(ca)ca.addEventListener("click",function(e){'
		. 'e.preventDefault();'
		. 'root.querySelectorAll(".ekwa-sitemap__children")'
		. '.forEach(function(u){u.style.display="none";});'
		. 'root.querySelectorAll(".ekwa-sitemap__toggle")'
		. '.forEach(function(b){b.textContent="+";b.setAttribute("aria-expanded","false");});'
		. '});'

		/* Expand All */
		. 'var ea=root.querySelector(".ekwa-sitemap__btn-expand");'
		. 'if(ea)ea.addEventListener("click",function(e){'
		. 'e.preventDefault();'
		. 'root.querySelectorAll(".ekwa-sitemap__children")'
		. '.forEach(function(u){u.style.display="";});'
		. 'root.querySelectorAll(".ekwa-sitemap__toggle")'
		. '.forEach(function(b){b.textContent="\u2212";b.setAttribute("aria-expanded","true");});'
		. '});'

		. '})(' . wp_json_encode( $uid ) . ',' . $sc . ');</script>';

	return $out;
}

/**
 * Build a normalised tree from published WordPress pages.
 *
 * Each node: [ title, url, excerpt, date_display, date_iso, children[] ]
 *
 * @param string $sort_by    get_pages sort_column value.
 * @param string $sort_order ASC or DESC.
 * @param string $exclude_raw Comma-separated page IDs to exclude.
 * @return array
 */
function ekwa_sitemap_page_tree( $sort_by, $sort_order, $exclude_raw ) {
	$exclude = array();
	if ( $exclude_raw ) {
		$exclude = array_values(
			array_filter( array_map( 'absint', array_map( 'trim', explode( ',', $exclude_raw ) ) ) )
		);
	}

	$sort_column = ( 'menu_order' === $sort_by ) ? 'menu_order,post_title' : $sort_by;

	$pages = get_pages(
		array(
			'sort_column'  => $sort_column,
			'sort_order'   => $sort_order,
			'hierarchical' => true,
			'exclude'      => $exclude,
			'post_status'  => 'publish',
		)
	);

	if ( empty( $pages ) ) {
		return array();
	}

	$exclude_set = array_flip( $exclude );

	$build = null;
	$build = function ( $pages, $parent_id ) use ( &$build, $exclude_set ) {
		$nodes = array();
		foreach ( $pages as $page ) {
			if ( (int) $page->post_parent !== $parent_id ) {
				continue;
			}
			if ( isset( $exclude_set[ $page->ID ] ) ) {
				continue;
			}
			$nodes[] = array(
				'title'        => $page->post_title,
				'url'          => get_permalink( $page->ID ),
				'excerpt'      => $page->post_excerpt,
				'date_display' => get_the_modified_date( '', $page ),
				'date_iso'     => get_the_modified_date( 'c', $page ),
				'children'     => $build( $pages, $page->ID ),
			);
		}
		return $nodes;
	};

	return $build( $pages, 0 );
}

/**
 * Build a normalised tree from a WordPress nav menu.
 *
 * @param string $menu_slug Menu slug or location slug.
 * @return array
 */
function ekwa_sitemap_menu_tree( $menu_slug ) {
	// Accept both menu slugs and registered theme location slugs.
	$menu_obj = wp_get_nav_menu_object( $menu_slug );

	if ( ! $menu_obj ) {
		// Fall back to a theme location.
		$locations = get_nav_menu_locations();
		if ( isset( $locations[ $menu_slug ] ) ) {
			$menu_obj = wp_get_nav_menu_object( $locations[ $menu_slug ] );
		}
	}

	if ( ! $menu_obj ) {
		return array();
	}

	$items = wp_get_nav_menu_items( $menu_obj->term_id );
	if ( empty( $items ) ) {
		return array();
	}

	$build = null;
	$build = function ( $items, $parent_id ) use ( &$build ) {
		$nodes = array();
		foreach ( $items as $item ) {
			if ( (int) $item->menu_item_parent !== $parent_id ) {
				continue;
			}
			$nodes[] = array(
				'title'        => $item->title,
				'url'          => $item->url,
				'excerpt'      => '',
				'date_display' => '',
				'date_iso'     => '',
				'children'     => $build( $items, $item->ID ),
			);
		}
		return $nodes;
	};

	return $build( $items, 0 );
}

/**
 * Server-side render callback for the ekwa/search block.
 *
 * Outputs a small search-icon trigger button. Clicking the button opens a
 * full-screen modal overlay with a search form. The modal, its styles, and
 * the open/close JavaScript are all emitted inline — no external assets needed.
 * A single shared CSS block is printed once per page via a global flag.
 *
 * @param array $attrs Block attributes.
 * @return string HTML output.
 */
function ekwa_render_search_block( $attrs ) {

	$icon_size       = absint( $attrs['iconSize']       ?? 20 );
	$icon_color      = sanitize_hex_color( $attrs['iconColor']     ?? '' );
	$button_bg       = sanitize_hex_color( $attrs['buttonBg']      ?? '' );
	$placeholder     = sanitize_text_field( $attrs['placeholder']  ?? 'Type to search...' );
	$btn_label       = sanitize_text_field( $attrs['buttonLabel']  ?? 'Search' );
	$overlay_bg      = $attrs['overlayBg']                         ?? 'rgba(15,23,42,0.85)';
	$overlay_blur    = (bool) ( $attrs['overlayBlur']              ?? true );
	$search_btn_bg   = sanitize_hex_color( $attrs['searchBtnBg']   ?? '' );
	$search_btn_col  = sanitize_hex_color( $attrs['searchBtnColor'] ?? '' );
	$anchor          = sanitize_html_class( $attrs['anchor']       ?? '' );

	// Sanitise the overlay background — allow rgba/hsla but strip anything suspicious.
	$overlay_bg = preg_replace( '/[^a-zA-Z0-9%(),.\s#]/', '', $overlay_bg );

	/* CSS and JS are now in ekwa-blocks.css / ekwa-blocks.js. */
	$out = '';

	/*
	 * Single shared overlay: the first instance registers the wp_footer
	 * output, all subsequent instances just render a trigger button.
	 */
	static $shared_overlay_registered = false;

	/* ---- CSS custom properties scoped to this instance ---- */
	$instance_style = '';
	if ( $search_btn_bg  ) { $instance_style .= '--ekwa-srch-btn-bg:' . esc_attr( $search_btn_bg ) . ';'; }
	if ( $search_btn_col ) { $instance_style .= '--ekwa-srch-btn-col:' . esc_attr( $search_btn_col ) . ';'; }

	/* ---- Trigger button ---- */
	$btn_style = '';
	if ( $button_bg   ) { $btn_style .= 'background:' . esc_attr( $button_bg ) . ';'; }
	if ( $icon_color  ) { $btn_style .= 'color:' . esc_attr( $icon_color ) . ';'; }

	$wrapper_attrs = ' class="ekwa-search-block"';
	if ( $anchor ) {
		$wrapper_attrs .= ' id="' . esc_attr( $anchor ) . '"';
	}
	if ( $instance_style ) {
		$wrapper_attrs .= ' style="' . esc_attr( $instance_style ) . '"';
	}

	$out .= '<div' . $wrapper_attrs . '>';

	$out .= '<button'
		. ' class="ekwa-search-trigger"'
		. ' type="button"'
		. ' aria-label="' . esc_attr__( 'Open Search', 'ekwa' ) . '"'
		. ' aria-expanded="false"'
		. ( $btn_style ? ' style="' . esc_attr( $btn_style ) . '"' : '' )
		. '>';

	// Inline SVG magnifying-glass icon — scales with iconSize.
	$sz = $icon_size;
	$ic = $icon_color ? ' fill="' . esc_attr( $icon_color ) . '"' : ' fill="currentColor"';
	$out .= '<svg xmlns="http://www.w3.org/2000/svg" width="' . $sz . '" height="' . $sz . '" viewBox="0 0 24 24" aria-hidden="true"' . $ic . '>'
		. '<path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>'
		. '</svg>';

	$out .= '</button>';
	$out .= '</div>'; // .ekwa-search-block

	/*
	 * Overlay modal — rendered via wp_footer so it sits at the <body> level
	 * and is never hidden by a parent container (e.g. display:none on a
	 * header template part at a certain breakpoint).  Registered once.
	 */
	if ( ! $shared_overlay_registered ) {
		$shared_overlay_registered = true;

		$bg_style = 'background:' . esc_attr( $overlay_bg ) . ';';
		if ( $overlay_blur ) {
			$bg_style .= 'backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);';
		}

		$search_url = esc_url( home_url( '/' ) );
		$ph         = $placeholder;
		$bl         = $btn_label;

		add_action( 'wp_footer', function () use ( $bg_style, $search_url, $ph, $bl ) {
			echo '<div id="ekwa-search-overlay-1" class="ekwa-search-overlay" role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'Search', 'ekwa' ) . '">';
			echo '<div class="ekwa-search-overlay__bg" style="' . esc_attr( $bg_style ) . '" aria-hidden="true"></div>';
			echo '<div class="ekwa-search-overlay__box">';
			echo '<button class="ekwa-search-overlay__close" type="button" aria-label="' . esc_attr__( 'Close Search', 'ekwa' ) . '">&#x2715;</button>';
			echo '<form class="ekwa-search-overlay__form" role="search" method="get" action="' . $search_url . '">';
			echo '<input id="ekwa-search-input-1" class="ekwa-search-overlay__input" type="search" name="s" placeholder="' . esc_attr( $ph ) . '" autocomplete="off" aria-label="' . esc_attr__( 'Search', 'ekwa' ) . '"/>';
			echo '<button class="ekwa-search-overlay__submit" type="submit">' . esc_html( $bl ) . '</button>';
			echo '</form></div></div>';
		} );
	}

	return $out;
}

/* ------------------------------------------------------------------ */
/* Scroll-to-top block                                                 */
/* ------------------------------------------------------------------ */

/**
 * Server-side render callback for ekwa/scroll-top.
 *
 * Outputs a fixed-position button that appears after the user scrolls past a
 * configurable threshold and smoothly scrolls the page back to the top.
 */
function ekwa_render_scroll_top_block( $attributes ) {
	$icon_size   = absint( $attributes['iconSize'] ?? 20 );
	$btn_size    = absint( $attributes['buttonSize'] ?? 48 );
	$icon_color  = sanitize_hex_color( $attributes['iconColor'] ?? '' ) ?: '#ffffff';
	$btn_bg      = sanitize_hex_color( $attributes['buttonBg'] ?? '' ) ?: '#0073aa';
	$radius      = absint( $attributes['borderRadius'] ?? 8 );
	$bottom      = absint( $attributes['offsetBottom'] ?? 30 );
	$right       = absint( $attributes['offsetRight'] ?? 30 );
	$threshold   = absint( $attributes['scrollThreshold'] ?? 300 );

	$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST;

	/* CSS and JS are now in ekwa-blocks.css / ekwa-blocks.js. */

	/* In the editor, render inline and always visible so the preview is useful. */
	$editor_style = $is_editor
		? 'position:static;opacity:1;visibility:visible;'
		: 'bottom:' . $bottom . 'px;right:' . $right . 'px;';

	/* ---- Button markup — data-threshold for the external JS ---- */
	$out = '<button'
		. ' class="ekwa-scroll-top-btn' . ( $is_editor ? ' is-visible' : '' ) . '"'
		. ' aria-label="' . esc_attr__( 'Scroll to top', 'ekwa' ) . '"'
		. ' data-threshold="' . $threshold . '"'
		. ' style="'
			. $editor_style
			. 'width:' . $btn_size . 'px;'
			. 'height:' . $btn_size . 'px;'
			. 'border-radius:' . $radius . 'px;'
			. 'background:' . esc_attr( $btn_bg ) . ';'
			. 'color:' . esc_attr( $icon_color ) . ';'
		. '">'
		. '<svg xmlns="http://www.w3.org/2000/svg" width="' . $icon_size . '" height="' . $icon_size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'
			. '<polyline points="18 15 12 9 6 15"></polyline>'
		. '</svg>'
		. '</button>';

	return $out;
}

/**
 * REST endpoint: GET /ekwa/v1/social-links
 *
 * Returns the name + icon of each saved social link so the block editor can
 * render per-icon colour pickers with meaningful labels.
 * Restricted to users who can edit posts.
 */
add_action( 'rest_api_init', function () {
	register_rest_route(
		'ekwa/v1',
		'/social-links',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => function () {
				$links = get_option( 'ekwa_social', array() );
				if ( ! is_array( $links ) ) {
					return rest_ensure_response( array() );
				}
				$safe = array();
				foreach ( $links as $link ) {
					$safe[] = array(
						'name' => sanitize_text_field( $link['name'] ?? '' ),
						'icon' => sanitize_text_field( $link['icon'] ?? '' ),
					);
				}
				return rest_ensure_response( $safe );
			},
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
} );


/**
 * Server-side render callback for the ekwa/hamburger-menu block.
 *
 * Outputs a hamburger button + hidden mobile nav that mmenu-light initialises on.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_hamburger_menu_block( $attrs ) {
	$icon_size = isset( $attrs['iconSize'] ) ? absint( $attrs['iconSize'] ) : 24;
	$bar_h     = max( 2, round( $icon_size / 8 ) );
	$bar_gap   = max( 3, round( $icon_size / 5 ) );

	// Enqueue mmenu-light only when this block is present.
	wp_enqueue_style(
		'mmenu-light',
		get_template_directory_uri() . '/assets/mmenu-light/mmenu-light.css',
		array(),
		'3.2.2'
	);
	wp_enqueue_script(
		'mmenu-light',
		get_template_directory_uri() . '/assets/mmenu-light/mmenu-light.js',
		array(),
		'3.2.2',
		true
	);

	// Emit per-site mmenu color overrides as CSS custom properties.
	$mmenu_color_map = array(
		'--ekwa-mmenu-bg'           => get_option( 'ekwa_mmenu_bg', '' ),
		'--ekwa-mmenu-text'         => get_option( 'ekwa_mmenu_text', '' ),
		'--ekwa-mmenu-icon'         => get_option( 'ekwa_mmenu_icon', '' ),
		'--ekwa-mmenu-divider'      => get_option( 'ekwa_mmenu_divider', '' ),
		'--ekwa-mmenu-navbar-bg'    => get_option( 'ekwa_mmenu_navbar_bg', '' ),
		'--ekwa-mmenu-navbar-text'  => get_option( 'ekwa_mmenu_navbar_text', '' ),
	);
	$mmenu_css = '';
	foreach ( $mmenu_color_map as $var_name => $var_value ) {
		if ( '' !== $var_value ) {
			$mmenu_css .= $var_name . ':' . $var_value . ';';
		}
	}
	if ( '' !== $mmenu_css ) {
		wp_add_inline_style( 'mmenu-light', ':root{' . $mmenu_css . '}' );
	}

	/* CSS and JS are now in ekwa-blocks.css / ekwa-blocks.js. */
	$out = '';

	// ── Hamburger button ────────────────────────────────────────
	$out .= '<button class="ekwa-hamburger-btn"'
		. ' aria-controls="ekwa-mobile-nav" aria-expanded="false"'
		. ' aria-label="' . esc_attr__( 'Open Menu', 'ekwa' ) . '">';
	for ( $i = 0; $i < 3; $i++ ) {
		$out .= '<span class="ekwa-hamburger-bar" style="width:'
			. $icon_size . 'px;height:' . $bar_h . 'px;'
			. ( $i < 2 ? 'margin-bottom:' . $bar_gap . 'px' : '' )
			. '"></span>';
	}
	$out .= '</button>';

	// ── Mobile nav (rendered from wp_nav_menu) ──────────────────
	if ( has_nav_menu( 'mobile' ) ) {
		ob_start();
		wp_nav_menu( array(
			'theme_location'  => 'mobile',
			'container'       => 'nav',
			'container_id'    => 'ekwa-mobile-nav',
			'container_class' => 'ekwa-mobile-nav',
			'walker'          => new Ekwa_Mobile_Menu_Walker(),
			'fallback_cb'     => false,
			'depth'           => 3,
		) );
		$out .= ob_get_clean();
	}

	return $out;
}


/**
 * Server-side render callback for the ekwa/header-menu block.
 *
 * Builds a multi-level desktop nav from the menu assigned to the
 * "Main Menu" theme location. Top-level items flagged as mega menus
 * expand into a columnar grid; other items render as nested flyouts.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_header_menu_block( $attrs ) {
	wp_enqueue_style( 'ekwa-header-menu-style' );
	wp_enqueue_script( 'ekwa-header-menu-view' );

	$nav = ekwa_render_main_nav( 'main_menu' );

	if ( '' === $nav ) {
		if ( current_user_can( 'edit_theme_options' ) ) {
			return '<div class="ekwa-header-menu-empty" style="padding:8px 12px;border:1px dashed #b3b3b3;color:#555;font-size:14px;">'
				. esc_html__( 'Assign a menu to the "Main Menu" location at Appearance → Menus to populate this block.', 'ekwa' )
				. '</div>';
		}
		return '';
	}

	$alignment_map = array(
		'left'          => 'flex-start',
		'center'        => 'center',
		'right'         => 'flex-end',
		'space-between' => 'space-between',
	);
	$align_value = isset( $alignment_map[ $attrs['alignment'] ?? '' ] )
		? $alignment_map[ $attrs['alignment'] ]
		: 'center';

	$gap     = isset( $attrs['itemGap'] ) ? max( 0, (int) $attrs['itemGap'] ) : 24;
	$sub_min = isset( $attrs['submenuMinWidth'] ) ? max( 120, (int) $attrs['submenuMinWidth'] ) : 220;

	$style = sprintf(
		'--ekwa-header-align:%s;--ekwa-header-gap:%dpx;--ekwa-submenu-minw:%dpx;',
		esc_attr( $align_value ),
		$gap,
		$sub_min
	);

	$wrapper_attrs = get_block_wrapper_attributes( array(
		'class' => 'ekwa-header-menu-wrap',
		'style' => $style,
	) );

	return '<div ' . $wrapper_attrs . '>' . $nav . '</div>';
}


/**
 * Sanitize a user-pasted SVG string for safe inline output.
 *
 * Allows only the tags/attributes needed for simple icon SVGs. Strips
 * <script>, event handlers, and external xlink:href values.
 *
 * @param string $svg
 * @return string Sanitized SVG, or '' when nothing valid remains.
 */
function ekwa_sanitize_dock_svg( $svg ) {
	$svg = is_string( $svg ) ? trim( $svg ) : '';
	if ( '' === $svg || stripos( $svg, '<svg' ) === false ) {
		return '';
	}

	$attrs_common = array(
		'class'            => true,
		'fill'             => true,
		'stroke'           => true,
		'stroke-width'     => true,
		'stroke-linecap'   => true,
		'stroke-linejoin'  => true,
		'stroke-miterlimit'=> true,
		'stroke-dasharray' => true,
		'opacity'          => true,
		'transform'        => true,
		'style'            => true,
	);

	$allowed = array(
		'svg' => array_merge( $attrs_common, array(
			'xmlns'       => true,
			'xmlns:xlink' => true,
			'viewbox'     => true,
			'width'       => true,
			'height'      => true,
			'preserveaspectratio' => true,
			'aria-hidden' => true,
			'role'        => true,
			'focusable'   => true,
		) ),
		'g'      => array_merge( $attrs_common, array( 'id' => true ) ),
		'title'  => array(),
		'desc'   => array(),
		'defs'   => array(),
		'path'   => array_merge( $attrs_common, array( 'd' => true ) ),
		'circle' => array_merge( $attrs_common, array( 'cx' => true, 'cy' => true, 'r' => true ) ),
		'ellipse'=> array_merge( $attrs_common, array( 'cx' => true, 'cy' => true, 'rx' => true, 'ry' => true ) ),
		'rect'   => array_merge( $attrs_common, array( 'x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true ) ),
		'line'   => array_merge( $attrs_common, array( 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true ) ),
		'polyline' => array_merge( $attrs_common, array( 'points' => true ) ),
		'polygon'  => array_merge( $attrs_common, array( 'points' => true ) ),
		'linearGradient' => array_merge( $attrs_common, array( 'id' => true, 'x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'gradientunits' => true ) ),
		'radialGradient' => array_merge( $attrs_common, array( 'id' => true, 'cx' => true, 'cy' => true, 'r' => true, 'fx' => true, 'fy' => true, 'gradientunits' => true ) ),
		'stop'   => array_merge( $attrs_common, array( 'offset' => true, 'stop-color' => true, 'stop-opacity' => true ) ),
		'use'    => array_merge( $attrs_common, array( 'href' => true, 'x' => true, 'y' => true, 'width' => true, 'height' => true ) ),
	);

	$clean = wp_kses( $svg, $allowed );

	// Defense-in-depth: drop any remaining on* event attrs and javascript: URIs.
	$clean = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $clean );
	$clean = preg_replace( '/(href|xlink:href)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', '', $clean );

	return ( stripos( $clean, '<svg' ) === false ) ? '' : $clean;
}

/**
 * Server-side render callback for the ekwa/mobile-dock block.
 *
 * Floating bottom dock for mobile: Call, Book, Scroll Up, Services, Find Us.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_mobile_dock_block( $attrs ) {
	$locations  = get_option( 'ekwa_locations', array() );
	$appt_type  = get_option( 'ekwa_appt_type', 'page' );
	$adsense    = get_option( 'ekwa_adsense_number', '' );

	// Determine appointment link via shared resolver.
	$appt_link = ekwa_get_appointment_url();
	if ( ! $appt_link ) {
		$appt_link   = '#';
		$appt_target = '';
	} elseif ( 'url' === $appt_type ) {
		$appt_target = ' target="_blank" rel="noopener"';
	} else {
		$appt_target = '';
	}

	// Build phone data per location.
	$phone_data          = array();
	$total_phones        = 0;
	$has_both_types      = false;
	foreach ( $locations as $i => $loc ) {
		$pn  = isset( $loc['phone_new'] )      ? $loc['phone_new']      : '';
		$pe  = isset( $loc['phone_existing'] )  ? $loc['phone_existing']  : '';
		$dir = isset( $loc['direction'] )       ? $loc['direction']       : '';

		$city = '';
		$parts = array();
		if ( ! empty( $loc['city'] ) )           { $city = $loc['city']; }
		if ( ! empty( $loc['street_address'] ) ) { $parts[] = $loc['street_address']; }
		if ( ! empty( $loc['city'] ) )           { $parts[] = $loc['city']; }
		if ( ! empty( $loc['state'] ) )          { $parts[] = $loc['state']; }
		if ( ! empty( $loc['zip'] ) )            { $parts[] = $loc['zip']; }
		$address = implode( ', ', $parts );

		if ( $pn ) { $total_phones++; }
		if ( $pe ) { $total_phones++; }
		if ( $pn && $pe ) { $has_both_types = true; }

		$phone_data[] = array(
			'city'     => $city ?: 'Location ' . ( $i + 1 ),
			'new'      => $pn,
			'existing' => $pe,
			'dir'      => $dir,
			'address'  => $address,
		);
	}

	// Ad tracking override.
	$is_ad = ( isset( $_COOKIE['adward_number'] ) || isset( $_GET['ads'] ) );

	if ( $is_ad && $adsense ) {
		$needs_call_popup = false;
		$single_phone     = preg_replace( '/[^0-9+]/', '', $adsense );
	} else {
		$needs_call_popup = ( count( $phone_data ) > 1 ) || ( count( $phone_data ) === 1 && $has_both_types );
		$single_phone     = '';
		if ( ! $needs_call_popup && $total_phones === 1 ) {
			foreach ( $phone_data as $d ) {
				if ( $d['existing'] ) { $single_phone = preg_replace( '/[^0-9+]/', '', $d['existing'] ); break; }
				if ( $d['new'] )      { $single_phone = preg_replace( '/[^0-9+]/', '', $d['new'] );      break; }
			}
		}
	}

	$needs_loc_popup  = count( $phone_data ) > 1;
	$single_direction = '';
	if ( ! $needs_loc_popup && ! empty( $phone_data[0]['dir'] ) ) {
		$single_direction = $phone_data[0]['dir'];
	}

	$bid = 'ekwa-mobile-dock';

	// Ensure mmenu-light is loaded for the services drawer (and harmless if the
	// hamburger block already enqueued it — wp_enqueue_* dedupes on handle).
	wp_enqueue_style(
		'mmenu-light',
		get_template_directory_uri() . '/assets/mmenu-light/mmenu-light.css',
		array(),
		'3.2.2'
	);
	wp_enqueue_script(
		'mmenu-light',
		get_template_directory_uri() . '/assets/mmenu-light/mmenu-light.js',
		array(),
		'3.2.2',
		true
	);

	/* CSS and JS are now in ekwa-blocks.css / ekwa-blocks.js. */
	// ── HTML ─────────────────────────────────────────────────────
	$html  = '<div class="ekwa-mobile-dock">';
	$html .= '<div class="dock-wrap">';

	// Default SVG icons.
	$default_phone    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
	$default_calendar = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
	$default_arrow_up = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
	$default_services = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/><path d="M12 8v8M8 12h8"/></svg>';
	$default_pin      = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
	$svg_chevron      = '<svg class="accordion-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';

	// Per-button override: sanitized SVG from attributes, falling back to default.
	$svg_phone    = ekwa_sanitize_dock_svg( $attrs['iconCall']     ?? '' ) ?: $default_phone;
	$svg_calendar = ekwa_sanitize_dock_svg( $attrs['iconBook']     ?? '' ) ?: $default_calendar;
	$svg_arrow_up = ekwa_sanitize_dock_svg( $attrs['iconUp']       ?? '' ) ?: $default_arrow_up;
	$svg_services = ekwa_sanitize_dock_svg( $attrs['iconServices'] ?? '' ) ?: $default_services;
	$svg_pin      = ekwa_sanitize_dock_svg( $attrs['iconFindUs']   ?? '' ) ?: $default_pin;

	// 1. Call
	if ( $needs_call_popup ) {
		$html .= '<button type="button" class="dock-item call-item" data-popup="call-popup-' . $bid . '" aria-label="Call">';
	} else {
		$html .= '<a href="tel:' . esc_attr( $single_phone ) . '" class="dock-item call-item" aria-label="Call">';
	}
	$html .= $svg_phone . '<span class="dock-label">Call</span>';
	$html .= $needs_call_popup ? '</button>' : '</a>';

	// 2. Book
	$html .= '<a href="' . esc_url( $appt_link ) . '" class="dock-item book-item" aria-label="Book"' . $appt_target . '>';
	$html .= $svg_calendar . '<span class="dock-label">Book</span></a>';

	$html .= '<span class="dock-divider"></span>';

	// 3. Scroll Up (FAB)
	$html .= '<button type="button" class="dock-item scroll-up-item" aria-label="Scroll to Top">';
	$html .= $svg_arrow_up . '<span class="dock-label">Up</span></button>';

	$html .= '<span class="dock-divider"></span>';

	// 4. Services
	$html .= '<button type="button" class="dock-item services-item" aria-label="Services" aria-controls="ekwa-mobile-services-nav" aria-expanded="false">';
	$html .= $svg_services . '<span class="dock-label">Services</span></button>';

	// 5. Find Us
	if ( $needs_loc_popup ) {
		$html .= '<button type="button" class="dock-item findus-item" data-popup="location-popup-' . $bid . '" aria-label="Find Us">';
	} else {
		$html .= '<a href="' . esc_url( $single_direction ) . '" class="dock-item findus-item" target="_blank" rel="noopener" aria-label="Find Us">';
	}
	$html .= $svg_pin . '<span class="dock-label">Find Us</span>';
	$html .= $needs_loc_popup ? '</button>' : '</a>';

	$html .= '</div>'; // .dock-wrap
	$html .= '</div>'; // #bid

	// ── Mobile Services nav (mmenu-light drawer) ───────────────────
	if ( has_nav_menu( 'mobile_services' ) ) {
		ob_start();
		wp_nav_menu( array(
			'theme_location'  => 'mobile_services',
			'container'       => 'nav',
			'container_id'    => 'ekwa-mobile-services-nav',
			'container_class' => 'ekwa-mobile-nav ekwa-mobile-services-nav',
			'walker'          => new Ekwa_Mobile_Menu_Walker(),
			'fallback_cb'     => false,
			'depth'           => 3,
		) );
		$html .= ob_get_clean();
	}

	// ── Call popup ──────────────────────────────────────────────
	if ( $needs_call_popup ) {
		$html .= '<div class="ekwa-dock-popup" id="call-popup-' . esc_attr( $bid ) . '">';
		$html .= '<div class="popup-content"><div class="popup-header">';
		$html .= '<div class="popup-title">Call Us</div>';
		$html .= '<button type="button" class="popup-close" aria-label="Close">&times;</button>';
		$html .= '</div><div class="popup-body">';

		$li = 0;
		foreach ( $phone_data as $loc ) {
			if ( ! $loc['new'] && ! $loc['existing'] ) { continue; }
			$aid   = 'call-acc-' . $bid . '-' . $li;
			$first = ( 0 === $li );

			$html .= '<div class="location-accordion">';
			if ( count( $phone_data ) > 1 ) {
				$html .= '<button type="button" class="accordion-header' . ( $first ? ' active' : '' )
					. '" data-accordion="' . esc_attr( $aid ) . '">'
					. '<div class="location-name">' . esc_html( $loc['city'] ) . '</div>'
					. $svg_chevron . '</button>';
			}
			$html .= '<div class="accordion-body' . ( $first ? ' active' : '' ) . '" id="' . esc_attr( $aid ) . '">';
			$html .= '<div class="accordion-content">';
			if ( $loc['existing'] ) {
				$tel = preg_replace( '/[^0-9+]/', '', $loc['existing'] );
				$html .= '<div class="phone-item"><span class="phone-label">Existing Patient</span>'
					. '<a href="tel:' . esc_attr( $tel ) . '" class="phone-link">'
					. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>'
					. esc_html( $loc['existing'] ) . '</a></div>';
			}
			if ( $loc['new'] ) {
				$tel = preg_replace( '/[^0-9+]/', '', $loc['new'] );
				$html .= '<div class="phone-item"><span class="phone-label">New Patient</span>'
					. '<a href="tel:' . esc_attr( $tel ) . '" class="phone-link">'
					. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>'
					. esc_html( $loc['new'] ) . '</a></div>';
			}
			$html .= '</div></div></div>'; // .accordion-content, .accordion-body, .location-accordion
			$li++;
		}

		$html .= '</div></div></div>'; // .popup-body, .popup-content, .ekwa-dock-popup
	}

	// ── Location popup ──────────────────────────────────────────
	if ( $needs_loc_popup ) {
		$html .= '<div class="ekwa-dock-popup" id="location-popup-' . esc_attr( $bid ) . '">';
		$html .= '<div class="popup-content"><div class="popup-header">';
		$html .= '<div class="popup-title">Find Us</div>';
		$html .= '<button type="button" class="popup-close" aria-label="Close">&times;</button>';
		$html .= '</div><div class="popup-body">';

		$li = 0;
		foreach ( $phone_data as $loc ) {
			if ( ! $loc['dir'] || ! $loc['address'] ) { continue; }
			$aid   = 'loc-acc-' . $bid . '-' . $li;
			$first = ( 0 === $li );

			$html .= '<div class="location-accordion">';
			$html .= '<button type="button" class="accordion-header' . ( $first ? ' active' : '' )
				. '" data-accordion="' . esc_attr( $aid ) . '">'
				. '<div class="location-name">' . esc_html( $loc['city'] ) . '</div>'
				. $svg_chevron . '</button>';
			$html .= '<div class="accordion-body' . ( $first ? ' active' : '' ) . '" id="' . esc_attr( $aid ) . '">';
			$html .= '<div class="accordion-content"><div class="address-item">';
			$html .= '<a href="' . esc_url( $loc['dir'] ) . '" class="address-link" target="_blank" rel="noopener">'
				. '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
				. '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'
				. '<span>' . esc_html( $loc['address'] ) . '</span></a>';
			$html .= '</div></div></div></div>'; // .address-item, .accordion-content, .accordion-body, .location-accordion
			$li++;
		}

		$html .= '</div></div></div>'; // .popup-body, .popup-content, .ekwa-dock-popup
	}

	return $html;
}


/**
 * Server-side render callback for the ekwa/address-dropdown block.
 *
 * Shows a "Directions" button. If there is a single location, it links
 * directly.  If there are multiple locations, clicking opens a dropdown
 * listing every location's address with its direction link.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_address_dropdown_block( $attrs ) {
	$label      = sanitize_text_field( $attrs['label']     ?? 'Directions' );
	$icon_class = sanitize_text_field( $attrs['iconClass'] ?? 'fa-solid fa-location-dot' );
	$show_icon  = (bool) ( $attrs['showIcon'] ?? true );

	$locations = get_option( 'ekwa_locations', array() );
	if ( empty( $locations ) || ! is_array( $locations ) ) {
		return '';
	}

	// Build location data.
	$items = array();
	foreach ( $locations as $i => $loc ) {
		$loc = wp_parse_args( $loc, array(
			'street' => '', 'city' => '', 'state' => '', 'zip' => '', 'direction' => '',
		) );

		$city_line = trim( implode( ', ', array_filter( array( $loc['city'], $loc['state'] ) ) ) );
		if ( $loc['zip'] ) {
			$city_line = trim( $city_line . ' ' . $loc['zip'] );
		}
		$full = trim( implode( ', ', array_filter( array( $loc['street'], $city_line ) ) ) );
		$dir  = $loc['direction'];

		if ( $full && $dir ) {
			$items[] = array(
				'city'    => $loc['city'] ?: ( 'Location ' . ( $i + 1 ) ),
				'address' => $full,
				'dir'     => $dir,
			);
		}
	}
	if ( empty( $items ) ) {
		return '';
	}

	$icon_html = '';
	if ( $show_icon && $icon_class ) {
		$icon_html = '<i class="' . esc_attr( $icon_class ) . '" aria-hidden="true"></i> ';
	}

	$out = '';

	// Always show the dropdown, even for a single location.
	static $inst = 0;
	$inst++;
	$uid = 'ekwa-addr-dd-' . $inst;

	$out .= '<div class="ekwa-addr-dd" id="' . esc_attr( $uid ) . '">';

	// Trigger button.
	$out .= '<button class="ekwa-addr-dd__trigger" type="button"'
		. ' aria-expanded="false" aria-controls="' . esc_attr( $uid ) . '-panel">'
		. $icon_html . '<span>' . esc_html( $label ) . '</span>'
		. '<svg class="ekwa-addr-dd__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>'
		. '</button>';

	// Dropdown panel.
	$out .= '<div class="ekwa-addr-dd__panel" id="' . esc_attr( $uid ) . '-panel">';

	foreach ( $items as $item ) {
		$out .= '<div class="ekwa-addr-dd__location">';
		$out .= '<div class="ekwa-addr-dd__city">'
			. '<i class="fa-solid fa-location-dot" aria-hidden="true"></i> '
			. esc_html( $item['city'] )
			. '</div>';
		$out .= '<a href="' . esc_url( $item['dir'] ) . '" class="ekwa-addr-dd__link"'
			. ' target="_blank" rel="noopener noreferrer"'
			. ' aria-label="' . esc_attr( sprintf( __( 'Get directions to %s', 'ekwa' ), $item['address'] ) ) . '">'
			. '<i class="fa-solid fa-diamond-turn-right" aria-hidden="true"></i> '
			. esc_html( $item['address'] )
			. '</a>';
		$out .= '</div>';
	}

	$out .= '</div>'; // .panel
	$out .= '</div>'; // .ekwa-addr-dd

	return $out;
}


/**
 * Server-side render callback for the ekwa/phone-dropdown block.
 *
 * Shows a "Call Us" button.  Clicking opens a dropdown listing every
 * location's new-patient and existing-patient phone numbers.
 *
 * Ad-tracking override: when the adward_number cookie or ?ads query param
 * is present, the dropdown is replaced by a single direct tel: link to
 * the adsense number.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_phone_dropdown_block( $attrs ) {
	$label      = sanitize_text_field( $attrs['label']     ?? 'Call Us' );
	$icon_class = sanitize_text_field( $attrs['iconClass'] ?? 'fa-solid fa-phone' );
	$show_icon  = (bool) ( $attrs['showIcon'] ?? true );

	$locations = get_option( 'ekwa_locations', array() );
	$adsense   = get_option( 'ekwa_adsense_number', '' );

	$icon_html = '';
	if ( $show_icon && $icon_class ) {
		$icon_html = '<i class="' . esc_attr( $icon_class ) . '" aria-hidden="true"></i> ';
	}

	// ── Ad-tracking override — direct link, no dropdown ─────────
	$is_ad = ( isset( $_COOKIE['adward_number'] ) || isset( $_GET['ads'] ) );

	if ( $is_ad && $adsense ) {
		$tel = ekwa_mobile_number( $adsense );
		return '<a href="tel:' . esc_attr( $tel ) . '" class="ekwa-phone-dd__trigger ekwa-phone-dd__trigger--link"'
			. ' aria-label="' . esc_attr( sprintf( __( 'Call %s', 'ekwa' ), $adsense ) ) . '">'
			. $icon_html . '<span>' . esc_html( $label ) . '</span>'
			. ' <span class="ekwa-phone-dd__number">' . esc_html( $adsense ) . '</span>'
			. '</a>';
	}

	// ── Build phone data per location ───────────────────────────
	$items = array();
	foreach ( $locations as $i => $loc ) {
		$loc = wp_parse_args( $loc, array(
			'phone_new' => '', 'phone_existing' => '', 'city' => '',
		) );
		$pn = $loc['phone_new'];
		$pe = $loc['phone_existing'];

		if ( $pn || $pe ) {
			$items[] = array(
				'city'     => $loc['city'] ?: ( 'Location ' . ( $i + 1 ) ),
				'new'      => $pn,
				'existing' => $pe,
			);
		}
	}
	if ( empty( $items ) ) {
		return '';
	}

	// If only one location with only one phone type, render a direct link.
	if ( count( $items ) === 1 && ( ! $items[0]['new'] || ! $items[0]['existing'] ) ) {
		$phone = $items[0]['new'] ?: $items[0]['existing'];
		$tel   = ekwa_mobile_number( $phone );
		return '<a href="tel:' . esc_attr( $tel ) . '" class="ekwa-phone-dd__trigger ekwa-phone-dd__trigger--link"'
			. ' aria-label="' . esc_attr( sprintf( __( 'Call %s', 'ekwa' ), $phone ) ) . '">'
			. $icon_html . '<span>' . esc_html( $label ) . '</span>'
			. '</a>';
	}

	// ── Dropdown ────────────────────────────────────────────────
	static $inst = 0;
	$inst++;
	$uid = 'ekwa-phone-dd-' . $inst;

	$out  = '<div class="ekwa-phone-dd" id="' . esc_attr( $uid ) . '">';
	$out .= '<button class="ekwa-phone-dd__trigger" type="button"'
		. ' aria-expanded="false" aria-controls="' . esc_attr( $uid ) . '-panel">'
		. $icon_html . '<span>' . esc_html( $label ) . '</span>'
		. '<svg class="ekwa-phone-dd__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>'
		. '</button>';

	$out .= '<div class="ekwa-phone-dd__panel" id="' . esc_attr( $uid ) . '-panel">';

	$multi = ( count( $items ) > 1 );

	foreach ( $items as $item ) {
		$out .= '<div class="ekwa-phone-dd__location">';

		// City header (always show when multiple, optional for single).
		if ( $multi ) {
			$out .= '<div class="ekwa-phone-dd__city">'
				. '<i class="fa-solid fa-location-dot" aria-hidden="true"></i> '
				. esc_html( $item['city'] )
				. '</div>';
		}

		if ( $item['new'] ) {
			$tel = ekwa_mobile_number( $item['new'] );
			$out .= '<a href="tel:' . esc_attr( $tel ) . '" class="ekwa-phone-dd__link"'
				. ' aria-label="' . esc_attr( sprintf( __( 'Call New Patients %s', 'ekwa' ), $item['new'] ) ) . '">'
				. '<i class="fa-solid fa-phone" aria-hidden="true"></i>'
				. '<span class="ekwa-phone-dd__info">'
				. '<span class="ekwa-phone-dd__label">New Patients</span>'
				. '<span class="ekwa-phone-dd__num">' . esc_html( $item['new'] ) . '</span>'
				. '</span></a>';
		}
		if ( $item['existing'] ) {
			$tel = ekwa_mobile_number( $item['existing'] );
			$out .= '<a href="tel:' . esc_attr( $tel ) . '" class="ekwa-phone-dd__link"'
				. ' aria-label="' . esc_attr( sprintf( __( 'Call Existing Patients %s', 'ekwa' ), $item['existing'] ) ) . '">'
				. '<i class="fa-solid fa-user-check" aria-hidden="true"></i>'
				. '<span class="ekwa-phone-dd__info">'
				. '<span class="ekwa-phone-dd__label">Existing Patients</span>'
				. '<span class="ekwa-phone-dd__num">' . esc_html( $item['existing'] ) . '</span>'
				. '</span></a>';
		}

		$out .= '</div>';
	}

	$out .= '</div>'; // .panel
	$out .= '</div>'; // .ekwa-phone-dd

	return $out;
}


/* ====================================================================
 * Inner Page Banner + Conditional Page Title
 * ==================================================================== */

/**
 * Look up the current page's menu-item title from the header menu location.
 *
 * Prefers the "main_menu" theme location (used by the Ekwa Header Menu block)
 * and falls back to "primary" for backwards compatibility. Returns the title
 * of the item whose object_id matches the given post ID.
 *
 * @param int $post_id The current post/page ID.
 * @return string Menu item title, or empty string if not found.
 */
function ekwa_get_menu_name_for_page( $post_id ) {
	$locations = get_nav_menu_locations();

	$candidates = array();
	if ( ! empty( $locations['main_menu'] ) ) {
		$candidates[] = $locations['main_menu'];
	}
	if ( ! empty( $locations['primary'] ) && ! in_array( $locations['primary'], $candidates, true ) ) {
		$candidates[] = $locations['primary'];
	}
	if ( empty( $candidates ) ) {
		return '';
	}

	foreach ( $candidates as $menu_id ) {
		$menu_items = wp_get_nav_menu_items( $menu_id );
		if ( empty( $menu_items ) || ! is_array( $menu_items ) ) {
			continue;
		}
		foreach ( $menu_items as $item ) {
			if ( 'post_type' === $item->type && (int) $item->object_id === (int) $post_id ) {
				return $item->title;
			}
		}
	}

	return '';
}

/**
 * Determine the banner heading mode for the current page.
 *
 * Returns an array:
 *   'mode'       => 'same' | 'different'
 *   'page_title' => The full page title (string).
 *   'menu_name'  => The menu item title (string), empty if page not in menu.
 *
 * "same"      — menu name equals page title (or page is not in the menu).
 *               Banner shows the page title as <h1>.
 * "different" — menu name differs from page title.
 *               Banner shows the menu name (non-h tag), page title shown separately.
 *
 * @return array
 */
function ekwa_inner_banner_heading_data() {
	$post_id    = get_the_ID();
	$page_title = get_the_title( $post_id );
	$menu_name  = ekwa_get_menu_name_for_page( $post_id );

	if ( '' === $menu_name || strtolower( trim( $menu_name ) ) === strtolower( trim( $page_title ) ) ) {
		return array(
			'mode'       => 'same',
			'page_title' => $page_title,
			'menu_name'  => $menu_name,
		);
	}

	return array(
		'mode'       => 'different',
		'page_title' => $page_title,
		'menu_name'  => $menu_name,
	);
}


/**
 * Server-side render callback for the ekwa/inner-banner block.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_inner_banner_block( $attrs ) {
	$is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
	$is_real = is_page() && ! is_front_page();

	// Outside the editor (e.g. archives, front page), bail out.
	if ( ! $is_real && ! $is_rest ) {
		return '';
	}

	$overlay_opacity  = isset( $attrs['overlayOpacity'] ) ? absint( $attrs['overlayOpacity'] ) : 50;
	$min_height       = isset( $attrs['minHeight'] )      ? absint( $attrs['minHeight'] )      : 200;
	$show_breadcrumbs = (bool) ( $attrs['showBreadcrumbs'] ?? true );

	// Resolve heading data and featured image.
	if ( $is_real ) {
		$post_id   = get_the_ID();
		$data      = ekwa_inner_banner_heading_data();
		$bg_url    = has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';
		$is_sample = false;
	} else {
		// Editor-only template preview — no real post context.
		$data = array(
			'mode'       => 'different',
			'page_title' => __( 'Sample Page Title', 'ekwa' ),
			'menu_name'  => __( 'Menu Name', 'ekwa' ),
		);
		$bg_url    = '';
		$is_sample = true;
	}

	// Inline styles for min-height + optional background image.
	$inline = 'min-height:' . $min_height . 'px;';
	if ( $bg_url ) {
		$inline .= 'background-image:url(' . esc_url( $bg_url ) . ');';
	}

	// Build wrapper classes — keep our base hook + a flag when a bg image is present.
	$extra_classes = array( 'ekwa-inner-banner' );
	if ( $bg_url ) {
		$extra_classes[] = 'ekwa-inner-banner--has-bg';
	}
	if ( $is_sample ) {
		$extra_classes[] = 'ekwa-inner-banner--editor-preview';
	}

	// get_block_wrapper_attributes() merges:
	//   • is-style-{name} from the registered style variations
	//   • has-{slug}-background-color / has-{slug}-color / has-background / has-text-color
	//   • inline color styles when the user picks a custom hex
	//   • the user's "Additional CSS classes"
	$wrapper_attrs = get_block_wrapper_attributes( array(
		'class' => implode( ' ', $extra_classes ),
		'style' => $inline,
	) );

	$out  = '<section ' . $wrapper_attrs . '>';

	// Overlay (only when bg image is present and opacity > 0).
	if ( $bg_url && $overlay_opacity > 0 ) {
		$out .= '<div class="ekwa-inner-banner__overlay" style="opacity:' . ( $overlay_opacity / 100 ) . '"></div>';
	}

	$out .= '<div class="ekwa-inner-banner__content">';

	// Heading logic — same as before. For the editor preview we always render
	// the "different" path so users see how the menu-name layout looks.
	if ( 'same' === $data['mode'] ) {
		$out .= '<h1 class="ekwa-inner-banner__heading">' . esc_html( $data['page_title'] ) . '</h1>';
	} else {
		$out .= '<p class="ekwa-inner-banner__heading">' . esc_html( $data['menu_name'] ) . '</p>';
	}

	// Breadcrumbs.
	if ( $show_breadcrumbs ) {
		$out .= '<nav class="ekwa-inner-banner__breadcrumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'ekwa' ) . '">';
		if ( $is_sample ) {
			$out .= '<span><a href="#">' . esc_html__( 'Home', 'ekwa' ) . '</a>'
				. ' &raquo; <span>' . esc_html( $data['page_title'] ) . '</span></span>';
		} elseif ( function_exists( 'yoast_breadcrumb' ) ) {
			$out .= yoast_breadcrumb( '<span>', '</span>', false );
		} elseif ( function_exists( 'rank_math_the_breadcrumbs' ) ) {
			ob_start();
			rank_math_the_breadcrumbs();
			$out .= ob_get_clean();
		} else {
			$out .= '<span><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'ekwa' ) . '</a>'
				. ' &raquo; <span>' . esc_html( get_the_title() ) . '</span></span>';
		}
		$out .= '</nav>';
	}

	$out .= '</div>'; // .content
	$out .= '</section>';

	return $out;
}


/**
 * Server-side render callback for the ekwa/page-title block.
 *
 * Only renders the page title (<h1>) when the menu name differs from
 * the page title — because in that case the banner shows the shorter
 * menu name and the full title needs to appear below the banner.
 *
 * When menu name equals page title (or page is not in the menu), the
 * banner already contains the <h1>, so this block outputs nothing.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_page_title_block( $attrs ) {
	if ( ! is_page() || is_front_page() ) {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return '<div class="ekwa-page-title" style="opacity:.5"><h1>Page Title (conditional)</h1></div>';
		}
		return '';
	}

	$data = ekwa_inner_banner_heading_data();

	// Only show when menu name differs from page title.
	if ( 'different' !== $data['mode'] ) {
		return '';
	}

	return '<div class="ekwa-page-title"><h1 class="ekwa-page-title__heading">'
		. esc_html( $data['page_title'] )
		. '</h1></div>';
}

/**
 * Server-side render callback for the ekwa/card-link block.
 *
 * Renders the card as an <a> tag wrapping InnerBlocks content.
 * Falls back to a <div> when no URL is provided.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content InnerBlocks HTML.
 * @return string
 */
function ekwa_render_card_link_block( $attrs, $content ) {
	$url     = ekwa_resolve_block_link_url( $attrs );
	$new_tab = ! empty( $attrs['newTab'] );
	$rel_val = isset( $attrs['rel'] )    ? sanitize_text_field( $attrs['rel'] ) : '';

	$extra_attrs = array( 'class' => 'ekwa-card-link' );

	$wrapper_attrs = get_block_wrapper_attributes( $extra_attrs );

	if ( ! $url ) {
		return '<div ' . $wrapper_attrs . '>' . $content . '</div>';
	}

	$target_attr = $new_tab ? ' target="_blank"' : '';
	$rel_parts   = array();
	if ( $new_tab ) {
		$rel_parts[] = 'noopener';
		$rel_parts[] = 'noreferrer';
	}
	if ( $rel_val ) {
		$rel_parts[] = $rel_val;
	}
	$rel_attr = $rel_parts ? ' rel="' . esc_attr( implode( ' ', array_unique( $rel_parts ) ) ) . '"' : '';

	return '<a href="' . esc_url( $url ) . '"' . $target_attr . $rel_attr . ' ' . $wrapper_attrs . '>'
		. $content
		. '</a>';
}


/**
 * Permissive color sanitizer for FAQ CSS variables.
 *
 * Accepts hex (#abc / #aabbcc / #aabbccdd), rgb()/rgba(), hsl()/hsla(),
 * and var(--token) values. Anything else is stripped — this prevents
 * style-attribute injection while supporting theme palette tokens that
 * the block editor sometimes returns as CSS custom properties.
 *
 * @param string $value Raw color value from block attribute.
 * @return string Safe color string, or '' if none of the patterns match.
 */
function ekwa_faq_sanitize_color( $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';
	if ( '' === $value ) {
		return '';
	}
	if ( preg_match( '/^#([a-f0-9]{3,4}|[a-f0-9]{6}|[a-f0-9]{8})$/i', $value ) ) {
		return $value;
	}
	if ( preg_match( '/^(rgb|rgba|hsl|hsla)\([0-9.,%\s\/-]+\)$/i', $value ) ) {
		return $value;
	}
	if ( preg_match( '/^var\(\s*--[a-z0-9_-]+(\s*,\s*[^()]+)?\s*\)$/i', $value ) ) {
		return $value;
	}
	return '';
}

/**
 * Server-side render callback for the ekwa/faq block.
 *
 * Wraps inner ekwa/faq-item blocks in a <div class="ekwa-faq"> with custom
 * CSS variables for color overrides, plus data attributes consumed by the
 * frontend toggle JS. When emitSchema is enabled, walks the parsed inner
 * blocks to build a FAQPage JSON-LD <script> tag in the same output.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content Rendered InnerBlocks HTML.
 * @param object $block   WP_Block instance (used to walk parsed inner blocks).
 * @return string
 */
function ekwa_render_faq_block( $attrs, $content, $block = null ) {
	$accent      = isset( $attrs['accentColor'] )   ? ekwa_faq_sanitize_color( $attrs['accentColor'] )   : '';
	$q_color     = isset( $attrs['questionColor'] ) ? ekwa_faq_sanitize_color( $attrs['questionColor'] ) : '';
	$a_color     = isset( $attrs['answerColor'] )   ? ekwa_faq_sanitize_color( $attrs['answerColor'] )   : '';
	$item_bg     = isset( $attrs['itemBg'] )        ? ekwa_faq_sanitize_color( $attrs['itemBg'] )        : '';
	$accordion   = ! empty( $attrs['accordion'] );
	$first_open  = ! empty( $attrs['firstOpen'] );
	$emit_schema = ! isset( $attrs['emitSchema'] ) || ! empty( $attrs['emitSchema'] );

	$style_parts = array();
	if ( $accent )  { $style_parts[] = '--ekwa-faq-accent:'   . $accent;  }
	if ( $q_color ) { $style_parts[] = '--ekwa-faq-q-color:'  . $q_color; }
	if ( $a_color ) { $style_parts[] = '--ekwa-faq-a-color:'  . $a_color; }
	if ( $item_bg ) { $style_parts[] = '--ekwa-faq-item-bg:'  . $item_bg; }

	$extra = array( 'class' => 'ekwa-faq' );
	if ( $style_parts ) {
		$extra['style'] = implode( ';', $style_parts ) . ';';
	}
	if ( $accordion ) {
		$extra['data-accordion'] = '1';
	}
	if ( $first_open ) {
		$extra['data-first-open'] = '1';
	}
	$wrapper_attrs = get_block_wrapper_attributes( $extra );

	// Build FAQPage JSON-LD by walking the parsed inner blocks.
	$schema_html = '';
	if ( $emit_schema && $block && ! empty( $block->parsed_block['innerBlocks'] ) ) {
		$entities = array();
		foreach ( $block->parsed_block['innerBlocks'] as $inner ) {
			if ( 'ekwa/faq-item' !== $inner['blockName'] ) {
				continue;
			}
			$question = isset( $inner['attrs']['question'] ) ? wp_strip_all_tags( $inner['attrs']['question'] ) : '';
			$question = trim( html_entity_decode( $question, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $question ) {
				continue;
			}

			// Render the inner blocks of the faq-item to get the answer HTML.
			$answer_html = '';
			if ( ! empty( $inner['innerBlocks'] ) ) {
				foreach ( $inner['innerBlocks'] as $child ) {
					$answer_html .= render_block( $child );
				}
			} else {
				$answer_html = isset( $inner['innerHTML'] ) ? $inner['innerHTML'] : '';
			}

			$answer_text = trim( wp_strip_all_tags( $answer_html ) );
			$answer_text = preg_replace( '/\s+/', ' ', $answer_text );
			if ( '' === $answer_text ) {
				continue;
			}

			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer_text,
				),
			);
		}

		if ( $entities ) {
			$schema = array(
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => $entities,
			);
			$schema_html = '<script type="application/ld+json">'
				. wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
				. '</script>';
		}
	}

	return '<div ' . $wrapper_attrs . '>' . $content . '</div>' . $schema_html;
}


/**
 * Server-side render callback for the ekwa/faq-item block.
 *
 * Renders <details>/<summary> so the block works without JavaScript;
 * the frontend script enhances it (single-open accordion, smooth toggle).
 *
 * @param array  $attrs   Block attributes.
 * @param string $content Rendered InnerBlocks HTML (the answer).
 * @return string
 */
function ekwa_render_faq_item_block( $attrs, $content ) {
	$question     = isset( $attrs['question'] ) ? wp_kses( $attrs['question'], array(
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
	) ) : '';
	$default_open = ! empty( $attrs['defaultOpen'] );

	$extra = array( 'class' => 'ekwa-faq__item' );
	if ( $default_open ) {
		$extra['class'] .= ' is-default-open';
	}
	$wrapper_attrs = get_block_wrapper_attributes( $extra );

	$open_attr = $default_open ? ' open' : '';

	$html  = '<details ' . $wrapper_attrs . $open_attr . '>';
	$html .= '<summary class="ekwa-faq__q">';
	$html .= '<span class="ekwa-faq__q-text">' . $question . '</span>';
	$html .= '<span class="ekwa-faq__icon" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>';
	$html .= '</summary>';
	$html .= '<div class="ekwa-faq__a">' . $content . '</div>';
	$html .= '</details>';

	return $html;
}


/**
 * Server-side render callback for the ekwa/section block.
 *
 * Outputs a semantic HTML tag (section, header, footer, etc.) with optional
 * background image, overlay, and inner container.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content InnerBlocks HTML.
 * @return string
 */
function ekwa_render_section_block( $attrs, $content ) {
	$tag             = isset( $attrs['tagName'] )        ? sanitize_key( $attrs['tagName'] ) : 'section';
	$container_width = isset( $attrs['containerWidth'] ) ? sanitize_text_field( $attrs['containerWidth'] ) : '';
	$bg_url          = isset( $attrs['bgImageUrl'] )     ? esc_url( $attrs['bgImageUrl'] ) : '';
	$bg_size         = isset( $attrs['bgSize'] )         ? sanitize_text_field( $attrs['bgSize'] ) : 'cover';
	$bg_position     = isset( $attrs['bgPosition'] )     ? sanitize_text_field( $attrs['bgPosition'] ) : '50% 50%';
	$bg_fixed        = ! empty( $attrs['bgFixed'] );
	$overlay_color   = isset( $attrs['overlayColor'] )   ? sanitize_text_field( $attrs['overlayColor'] ) : '';
	$overlay_opacity = isset( $attrs['overlayOpacity'] )  ? absint( $attrs['overlayOpacity'] ) : 50;

	$allowed_tags = array( 'section', 'div', 'header', 'footer', 'main', 'aside', 'article', 'nav' );
	if ( ! in_array( $tag, $allowed_tags, true ) ) {
		$tag = 'section';
	}

	// Build inline styles for background image.
	$bg_styles = '';
	if ( $bg_url ) {
		$bg_styles .= 'background-image:url(' . esc_url( $bg_url ) . ');';
		$bg_styles .= 'background-size:' . esc_attr( $bg_size ) . ';';
		$bg_styles .= 'background-position:' . esc_attr( $bg_position ) . ';';
		if ( $bg_fixed ) {
			$bg_styles .= 'background-attachment:fixed;';
		}
	}

	$extra = array( 'class' => 'ekwa-section' );
	if ( $bg_styles ) {
		$extra['style'] = $bg_styles;
	}
	$wrapper_attrs = get_block_wrapper_attributes( $extra );

	// Overlay.
	$overlay_html = '';
	if ( $overlay_color ) {
		$opacity      = max( 0, min( 100, $overlay_opacity ) ) / 100;
		$overlay_html = '<div class="ekwa-section__overlay" style="background:'
			. esc_attr( $overlay_color ) . ';opacity:' . esc_attr( $opacity )
			. ';" aria-hidden="true"></div>';
	}

	// Inner container.
	$open  = '<div class="ekwa-section__inner">';
	$close = '</div>';
	if ( $container_width ) {
		$open  = '<div class="ekwa-section__container" style="max-width:'
			. esc_attr( $container_width ) . ';">';
		$close = '</div>';
	}

	return '<' . $tag . ' ' . $wrapper_attrs . '>'
		. $overlay_html
		. $open . $content . $close
		. '</' . $tag . '>';
}


/**
 * Server-side render callback for the ekwa/container block.
 *
 * Outputs a centered <div> with configurable max-width.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content InnerBlocks HTML.
 * @return string
 */
function ekwa_render_container_block( $attrs, $content ) {
	$max_width = isset( $attrs['maxWidth'] ) ? sanitize_text_field( $attrs['maxWidth'] ) : '1280px';

	$extra = array(
		'class' => 'ekwa-container',
		'style' => 'max-width:' . esc_attr( $max_width ) . ';margin-left:auto;margin-right:auto;',
	);

	$wrapper_attrs = get_block_wrapper_attributes( $extra );

	return '<div ' . $wrapper_attrs . '>' . $content . '</div>';
}


/**
 * Server-side render callback for the ekwa/flex block.
 *
 * Outputs a flexbox container with configurable direction, alignment, and wrap.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content InnerBlocks HTML.
 * @return string
 */
function ekwa_render_flex_block( $attrs, $content ) {
	$tag             = isset( $attrs['tagName'] )        ? sanitize_key( $attrs['tagName'] ) : 'div';
	$direction       = isset( $attrs['direction'] )      ? sanitize_text_field( $attrs['direction'] ) : 'row';
	$justify_content = isset( $attrs['justifyContent'] ) ? sanitize_text_field( $attrs['justifyContent'] ) : 'flex-start';
	$align_items     = isset( $attrs['alignItems'] )     ? sanitize_text_field( $attrs['alignItems'] ) : 'center';
	$wrap            = isset( $attrs['wrap'] )            ? sanitize_text_field( $attrs['wrap'] ) : 'wrap';

	$allowed_tags = array( 'div', 'nav', 'header', 'footer', 'aside' );
	if ( ! in_array( $tag, $allowed_tags, true ) ) {
		$tag = 'div';
	}

	$style  = 'display:flex;';
	$style .= 'flex-direction:' . esc_attr( $direction ) . ';';
	$style .= 'justify-content:' . esc_attr( $justify_content ) . ';';
	$style .= 'align-items:' . esc_attr( $align_items ) . ';';
	$style .= 'flex-wrap:' . esc_attr( $wrap ) . ';';

	$extra = array(
		'class' => 'ekwa-flex',
		'style' => $style,
	);

	$wrapper_attrs = get_block_wrapper_attributes( $extra );

	return '<' . $tag . ' ' . $wrapper_attrs . '>' . $content . '</' . $tag . '>';
}


/**
 * Server-side render callback for the ekwa/grid block.
 *
 * Outputs a CSS Grid container with data attributes for responsive breakpoints.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content InnerBlocks HTML.
 * @return string
 */
function ekwa_render_grid_block( $attrs, $content ) {
	$columns        = isset( $attrs['columns'] )       ? absint( $attrs['columns'] ) : 3;
	$column_widths  = isset( $attrs['columnWidths'] )  ? sanitize_text_field( $attrs['columnWidths'] ) : '';
	$tablet_columns = isset( $attrs['tabletColumns'] ) ? absint( $attrs['tabletColumns'] ) : 2;
	$mobile_columns = isset( $attrs['mobileColumns'] ) ? absint( $attrs['mobileColumns'] ) : 1;

	$grid_template = $column_widths ? $column_widths : 'repeat(' . $columns . ', 1fr)';

	$style = 'display:grid;grid-template-columns:' . esc_attr( $grid_template ) . ';';

	$extra = array(
		'class' => 'ekwa-grid',
		'style' => $style,
	);

	$wrapper_attrs = get_block_wrapper_attributes( $extra );

	$data_attrs = ' data-tablet-cols="' . esc_attr( $tablet_columns ) . '"'
		. ' data-mobile-cols="' . esc_attr( $mobile_columns ) . '"';

	return '<div ' . $wrapper_attrs . $data_attrs . '>' . $content . '</div>';
}


/**
 * Server-side render callback for the ekwa/carousel block.
 *
 * Each top-level inner block becomes a slide. Frontend script + style are
 * auto-enqueued by WP via the block.json viewScript / style handles, so they
 * load only on pages where the carousel is actually used.
 *
 * @param array    $attrs Block attributes.
 * @param string   $content InnerBlocks HTML (unused — we render slides from block tree).
 * @param WP_Block $block   Parsed block, gives us the inner block array.
 * @return string
 */
function ekwa_render_carousel_block( $attrs, $content, $block ) {
	$desktop      = isset( $attrs['desktopItems'] )     ? max( 1, absint( $attrs['desktopItems'] ) )     : 3;
	$tablet       = isset( $attrs['tabletItems'] )      ? max( 1, absint( $attrs['tabletItems'] ) )      : 2;
	$mobile       = isset( $attrs['mobileItems'] )      ? max( 1, absint( $attrs['mobileItems'] ) )      : 1;
	$tablet_bp    = isset( $attrs['tabletBreakpoint'] ) ? max( 1, absint( $attrs['tabletBreakpoint'] ) ) : 992;
	$mobile_bp    = isset( $attrs['mobileBreakpoint'] ) ? max( 1, absint( $attrs['mobileBreakpoint'] ) ) : 600;
	$show_arrows  = ! empty( $attrs['showArrows'] );
	$show_dots    = ! empty( $attrs['showDots'] );
	$autoplay     = ! empty( $attrs['autoplay'] );
	$autoplay_int = isset( $attrs['autoplayInterval'] ) ? max( 500, absint( $attrs['autoplayInterval'] ) ) : 5000;
	$loop         = ! empty( $attrs['loop'] );
	$gap          = isset( $attrs['gap'] )   ? absint( $attrs['gap'] )   : 20;
	$speed        = isset( $attrs['speed'] ) ? max( 50, absint( $attrs['speed'] ) ) : 350;
	$aria_label   = isset( $attrs['ariaLabel'] ) ? sanitize_text_field( $attrs['ariaLabel'] ) : '';

	// Render each top-level inner block individually so each becomes one slide.
	$slides = '';
	if ( $block instanceof WP_Block && ! empty( $block->parsed_block['innerBlocks'] ) ) {
		foreach ( $block->parsed_block['innerBlocks'] as $inner ) {
			$slides .= '<div class="ekwa-carousel__item">' . render_block( $inner ) . '</div>';
		}
	}

	if ( '' === $slides ) {
		return '';
	}

	$data  = ' data-desktop-items="' . esc_attr( $desktop ) . '"';
	$data .= ' data-tablet-items="'  . esc_attr( $tablet )  . '"';
	$data .= ' data-mobile-items="'  . esc_attr( $mobile )  . '"';
	$data .= ' data-tablet-bp="'     . esc_attr( $tablet_bp ) . '"';
	$data .= ' data-mobile-bp="'     . esc_attr( $mobile_bp ) . '"';
	$data .= ' data-show-arrows="'   . ( $show_arrows ? 'true' : 'false' ) . '"';
	$data .= ' data-show-dots="'     . ( $show_dots   ? 'true' : 'false' ) . '"';
	$data .= ' data-autoplay="'      . ( $autoplay    ? 'true' : 'false' ) . '"';
	$data .= ' data-autoplay-interval="' . esc_attr( $autoplay_int ) . '"';
	$data .= ' data-loop="'          . ( $loop ? 'true' : 'false' ) . '"';
	$data .= ' data-gap="'           . esc_attr( $gap ) . '"';
	$data .= ' data-speed="'         . esc_attr( $speed ) . '"';

	$wrapper_attrs = get_block_wrapper_attributes( array(
		'class'      => 'ekwa-carousel',
		'aria-label' => $aria_label ? $aria_label : __( 'Carousel', 'ekwa' ),
	) );

	$html  = '<div ' . $wrapper_attrs . $data . '>';
	$html .=   '<div class="ekwa-carousel__viewport">';
	$html .=     '<div class="ekwa-carousel__track">' . $slides . '</div>';
	$html .=   '</div>';

	if ( $show_arrows ) {
		$html .= '<button type="button" class="ekwa-carousel__arrow ekwa-carousel__arrow--prev" aria-label="' . esc_attr__( 'Previous slide', 'ekwa' ) . '"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>';
		$html .= '<button type="button" class="ekwa-carousel__arrow ekwa-carousel__arrow--next" aria-label="' . esc_attr__( 'Next slide', 'ekwa' ) . '"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>';
	}

	if ( $show_dots ) {
		$html .= '<div class="ekwa-carousel__dots" role="tablist" aria-label="' . esc_attr__( 'Slide pagination', 'ekwa' ) . '"></div>';
	}

	$html .= '<div class="ekwa-carousel__sr-status" aria-live="polite" aria-atomic="true"></div>';
	$html .= '</div>';

	return $html;
}


/**
 * Resolve a block's link URL from its attributes.
 *
 * Three sources, controlled by the linkType attribute:
 *   - 'external'    → uses the url attribute as-is
 *   - 'internal'    → uses get_permalink( pageId ) for the chosen page/post
 *   - 'appointment' → uses ekwa_get_appointment_url()
 *
 * Caller is responsible for esc_url() on output.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_resolve_block_link_url( $attrs ) {
	$type = isset( $attrs['linkType'] ) ? sanitize_key( $attrs['linkType'] ) : 'external';

	if ( 'appointment' === $type ) {
		return ekwa_get_appointment_url();
	}
	if ( 'internal' === $type ) {
		$pid = isset( $attrs['pageId'] ) ? absint( $attrs['pageId'] ) : 0;
		if ( ! $pid ) {
			return '';
		}
		$link = get_permalink( $pid );
		return $link ? $link : '';
	}
	return isset( $attrs['url'] ) ? (string) $attrs['url'] : '';
}


/**
 * Server-side render callback for the ekwa/button block.
 *
 * Outputs a single <a> or <button> element with variant and size classes.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_button_block( $attrs ) {
	$text          = isset( $attrs['text'] )         ? $attrs['text'] : '';
	$url           = ekwa_resolve_block_link_url( $attrs );
	$new_tab       = ! empty( $attrs['newTab'] );
	$rel_val       = isset( $attrs['rel'] )          ? sanitize_text_field( $attrs['rel'] ) : '';
	$html_tag      = isset( $attrs['htmlTag'] )      ? sanitize_key( $attrs['htmlTag'] ) : 'a';
	$variant       = isset( $attrs['variant'] )      ? sanitize_key( $attrs['variant'] ) : 'filled';
	$size          = isset( $attrs['size'] )         ? sanitize_key( $attrs['size'] ) : 'default';
	$icon_class    = isset( $attrs['iconClass'] )    ? sanitize_text_field( $attrs['iconClass'] ) : '';
	$icon_position = isset( $attrs['iconPosition'] ) ? sanitize_key( $attrs['iconPosition'] ) : 'left';

	if ( ! in_array( $html_tag, array( 'a', 'button' ), true ) ) {
		$html_tag = 'a';
	}

	$classes = 'ekwa-btn ekwa-btn--' . $variant;
	if ( 'default' !== $size ) {
		$classes .= ' ekwa-btn--' . $size;
	}

	$extra         = array( 'class' => $classes );
	$wrapper_attrs = get_block_wrapper_attributes( $extra );

	// Icon element.
	$icon_html = '';
	if ( $icon_class ) {
		$icon_html = '<i class="' . esc_attr( $icon_class ) . '" aria-hidden="true"></i>';
	}

	$content = '';
	if ( 'left' === $icon_position && $icon_html ) {
		$content .= $icon_html;
	}
	$content .= esc_html( $text );
	if ( 'right' === $icon_position && $icon_html ) {
		$content .= $icon_html;
	}

	if ( 'a' === $html_tag ) {
		$target_attr = $new_tab ? ' target="_blank"' : '';
		$rel_parts   = array();
		if ( $new_tab ) {
			$rel_parts[] = 'noopener';
			$rel_parts[] = 'noreferrer';
		}
		if ( $rel_val ) {
			$rel_parts[] = $rel_val;
		}
		$rel_attr = $rel_parts ? ' rel="' . esc_attr( implode( ' ', array_unique( $rel_parts ) ) ) . '"' : '';

		return '<a href="' . ( $url ? esc_url( $url ) : '#' ) . '"' . $target_attr . $rel_attr
			. ' ' . $wrapper_attrs . '>' . $content . '</a>';
	}

	return '<button type="button" ' . $wrapper_attrs . '>' . $content . '</button>';
}


/**
 * Server-side render callback for the ekwa/button-group block.
 *
 * Outputs a flex wrapper for button children.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content InnerBlocks HTML.
 * @return string
 */
function ekwa_render_button_group_block( $attrs, $content ) {
	$justify_content = isset( $attrs['justifyContent'] ) ? sanitize_text_field( $attrs['justifyContent'] ) : 'flex-start';
	$direction       = isset( $attrs['direction'] )      ? sanitize_text_field( $attrs['direction'] ) : 'row';

	$style  = 'display:flex;flex-wrap:wrap;';
	$style .= 'justify-content:' . esc_attr( $justify_content ) . ';';
	$style .= 'flex-direction:' . esc_attr( $direction ) . ';';

	$extra = array(
		'class' => 'ekwa-button-group',
		'style' => $style,
	);

	$wrapper_attrs = get_block_wrapper_attributes( $extra );

	return '<div ' . $wrapper_attrs . '>' . $content . '</div>';
}


/**
 * Server-side render callback for the ekwa/text block.
 *
 * Outputs a single inline element (<span>, <small>, <strong>, etc.).
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_text_block( $attrs ) {
	$tag        = isset( $attrs['tagName'] )   ? sanitize_key( $attrs['tagName'] ) : 'span';
	$text       = isset( $attrs['text'] )      ? $attrs['text'] : '';
	$class_name = isset( $attrs['className'] ) ? sanitize_text_field( $attrs['className'] ) : '';
	$anchor     = isset( $attrs['anchor'] )    ? sanitize_html_class( $attrs['anchor'] ) : '';

	$allowed_tags = array( 'span', 'small', 'strong', 'em', 'mark', 'time', 'label', 'sup', 'sub' );
	if ( ! in_array( $tag, $allowed_tags, true ) ) {
		$tag = 'span';
	}

	$html = '<' . $tag;
	if ( $class_name ) { $html .= ' class="' . esc_attr( $class_name ) . '"'; }
	if ( $anchor )     { $html .= ' id="' . esc_attr( $anchor ) . '"'; }
	$html .= '>' . esc_html( $text ) . '</' . $tag . '>';

	return $html;
}


/**
 * Server-side render callback for the ekwa/image block.
 *
 * Outputs a clean <img> tag with no figure wrapper. Builds attributes
 * manually (like ekwa/icon) to avoid get_block_wrapper_attributes()
 * injecting wp-block-* classes.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_image_block( $attrs ) {
	$src         = isset( $attrs['src'] )        ? esc_url( $attrs['src'] ) : '';
	$alt         = isset( $attrs['alt'] )        ? esc_attr( $attrs['alt'] ) : '';
	$width       = isset( $attrs['width'] )      ? esc_attr( $attrs['width'] ) : '';
	$height      = isset( $attrs['height'] )     ? esc_attr( $attrs['height'] ) : '';
	$loading     = isset( $attrs['loading'] )    ? esc_attr( $attrs['loading'] ) : 'lazy';
	$object_fit  = isset( $attrs['objectFit'] )  ? esc_attr( $attrs['objectFit'] ) : '';
	$anchor      = isset( $attrs['anchor'] )     ? sanitize_html_class( $attrs['anchor'] ) : '';
	$class_name  = isset( $attrs['className'] )  ? sanitize_text_field( $attrs['className'] ) : '';
	$link_url    = isset( $attrs['linkUrl'] )    ? esc_url( $attrs['linkUrl'] ) : '';
	$link_blank  = ! empty( $attrs['linkNewTab'] );

	if ( ! $src ) {
		return '';
	}

	$style = '';
	if ( $object_fit ) {
		$style = 'object-fit:' . $object_fit . ';';
	}

	$html = '<img';
	if ( $class_name ) { $html .= ' class="' . esc_attr( $class_name ) . '"'; }
	$html .= ' src="' . $src . '" alt="' . $alt . '"';
	if ( $width )   { $html .= ' width="' . $width . '"'; }
	if ( $height )  { $html .= ' height="' . $height . '"'; }
	if ( $loading ) { $html .= ' loading="' . $loading . '"'; }
	if ( $style )   { $html .= ' style="' . esc_attr( $style ) . '"'; }
	if ( $anchor )  { $html .= ' id="' . esc_attr( $anchor ) . '"'; }
	$html .= '>';

	if ( $link_url ) {
		$link_html = '<a href="' . $link_url . '"';
		if ( $link_blank ) {
			$link_html .= ' target="_blank" rel="noopener noreferrer"';
		}
		$link_html .= '>' . $html . '</a>';
		$html = $link_html;
	}

	return $html;
}


/**
 * Server-side render callback for the ekwa/div block.
 *
 * Outputs a clean wrapper element with only the user's classes and children.
 * Supports div, section, header, footer, nav, main, aside, article.
 * No layout styles, no inner wrappers, no forced classes.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content InnerBlocks HTML.
 * @return string
 */
function ekwa_render_div_block( $attrs, $content ) {
	$tag          = isset( $attrs['tagName'] )         ? sanitize_key( $attrs['tagName'] ) : 'div';
	$class_name   = isset( $attrs['className'] )       ? sanitize_text_field( $attrs['className'] ) : '';
	$anchor       = isset( $attrs['anchor'] )          ? sanitize_html_class( $attrs['anchor'] ) : '';
	$bg_image     = isset( $attrs['backgroundImage'] ) ? esc_url( $attrs['backgroundImage'] ) : '';
	$inline_style = isset( $attrs['inlineStyle'] )     ? $attrs['inlineStyle'] : '';
	$href         = isset( $attrs['href'] )            ? esc_url( $attrs['href'] ) : '';
	$target       = isset( $attrs['target'] )          ? sanitize_text_field( $attrs['target'] ) : '';
	$rel          = isset( $attrs['rel'] )             ? sanitize_text_field( $attrs['rel'] ) : '';

	$allowed = array(
		'div', 'section', 'header', 'footer', 'nav', 'main', 'aside', 'article', 'a',
		'span', 'small', 'strong', 'em', 'mark', 'time', 'label', 'sup', 'sub',
	);
	if ( ! in_array( $tag, $allowed, true ) ) {
		$tag = 'div';
	}

	// Build style string from background image + any extra inline styles.
	$style_parts = array();
	if ( $bg_image ) {
		$style_parts[] = "background-image:url('" . $bg_image . "')";
	}
	if ( $inline_style ) {
		$style_parts[] = rtrim( $inline_style, '; ' );
	}
	$style = implode( ';', $style_parts );

	$html = '<' . $tag;
	if ( $class_name ) { $html .= ' class="' . esc_attr( $class_name ) . '"'; }
	if ( $anchor )     { $html .= ' id="' . esc_attr( $anchor ) . '"'; }
	if ( $style )      { $html .= ' style="' . esc_attr( $style ) . '"'; }
	if ( $tag === 'a' ) {
		$html .= ' href="' . ( $href ? $href : '#' ) . '"';
		if ( $target ) { $html .= ' target="' . esc_attr( $target ) . '"'; }
		if ( $rel )    { $html .= ' rel="' . esc_attr( $rel ) . '"'; }
	}
	$html .= '>' . $content . '</' . $tag . '>';

	return $html;
}


/**
 * Server-side render callback for the ekwa/video block.
 *
 * Outputs a clean <video> element with <source> child.
 * No figure wrapper, no wp-block-video class.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_video_block( $attrs ) {
	$src         = isset( $attrs['src'] )       ? esc_url( $attrs['src'] ) : '';
	$poster      = isset( $attrs['poster'] )    ? esc_url( $attrs['poster'] ) : '';
	$class_name  = isset( $attrs['className'] ) ? sanitize_text_field( $attrs['className'] ) : '';
	$anchor      = isset( $attrs['anchor'] )    ? sanitize_html_class( $attrs['anchor'] ) : '';
	$autoplay    = ! empty( $attrs['autoplay'] );
	$loop        = ! empty( $attrs['loop'] );
	$muted       = ! empty( $attrs['muted'] );
	$playsinline = ! empty( $attrs['playsinline'] );
	$controls    = ! empty( $attrs['controls'] );

	if ( ! $src ) {
		return '';
	}

	$html = '<video';
	if ( $class_name )  { $html .= ' class="' . esc_attr( $class_name ) . '"'; }
	if ( $anchor )      { $html .= ' id="' . esc_attr( $anchor ) . '"'; }
	if ( $autoplay )    { $html .= ' autoplay'; }
	if ( $loop )        { $html .= ' loop'; }
	if ( $muted )       { $html .= ' muted'; }
	if ( $playsinline ) { $html .= ' playsinline'; }
	if ( $controls )    { $html .= ' controls'; }
	if ( $poster )      { $html .= ' poster="' . $poster . '"'; }
	$html .= '>';
	$html .= '<source src="' . $src . '" type="video/mp4">';
	$html .= '</video>';

	return $html;
}


/**
 * Server-side render callback for the ekwa/link block.
 *
 * Outputs a clean <a> element with only the user's classes.
 * No ekwa-btn classes, no variant/size logic.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content InnerBlocks HTML (if used with inner blocks).
 * @return string
 */
function ekwa_render_link_block( $attrs, $content = '' ) {
	$resolved   = ekwa_resolve_block_link_url( $attrs );
	$url        = $resolved ? esc_url( $resolved ) : '#';
	$text       = isset( $attrs['text'] )      ? $attrs['text'] : '';
	$new_tab    = ! empty( $attrs['newTab'] );
	$rel_val    = isset( $attrs['rel'] )       ? sanitize_text_field( $attrs['rel'] ) : '';
	$class_name = isset( $attrs['className'] ) ? sanitize_text_field( $attrs['className'] ) : '';
	$anchor     = isset( $attrs['anchor'] )    ? sanitize_html_class( $attrs['anchor'] ) : '';

	$html = '<a href="' . $url . '"';
	if ( $class_name ) { $html .= ' class="' . esc_attr( $class_name ) . '"'; }
	if ( $anchor )     { $html .= ' id="' . esc_attr( $anchor ) . '"'; }
	if ( $new_tab ) {
		$rel_parts = 'noopener noreferrer';
		if ( $rel_val ) {
			$rel_parts .= ' ' . esc_attr( $rel_val );
		}
		$html .= ' target="_blank" rel="' . $rel_parts . '"';
	} elseif ( $rel_val ) {
		$html .= ' rel="' . esc_attr( $rel_val ) . '"';
	}
	$html .= '>' . ( $content ? $content : esc_html( $text ) ) . '</a>';

	return $html;
}

// ═══════════════════════════════════════════════════════════════════════════════
// BLOG BLOCK RENDER CALLBACKS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Render: ekwa/back-to-category.
 */
function ekwa_render_back_to_category_block() {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return '<div class="ekwa-back-to-category"><a href="#">← Back to [Category] Page</a></div>';
	}

	if ( ! get_the_ID() || get_post_type() !== 'post' ) {
		return '';
	}

	$categories = get_the_category();
	if ( empty( $categories ) ) {
		return '';
	}

	$excluded = array( 'uncategorized', 'featured', 'featured-articles' );
	$filtered = array_filter( $categories, function ( $cat ) use ( $excluded ) {
		return ! in_array( strtolower( $cat->slug ), $excluded, true );
	} );

	if ( empty( $filtered ) ) {
		return '';
	}

	$primary  = reset( $filtered );
	$page_url = home_url( '/' . $primary->slug . '/' );

	return '<div class="ekwa-back-to-category">'
		. '<a href="' . esc_url( $page_url ) . '" class="ekwa-back-link">'
		. '<i class="fa-solid fa-arrow-left" aria-hidden="true"></i> '
		. esc_html( sprintf( __( 'Back to %s', 'ekwa' ), $primary->name ) )
		. '</a>'
		. '</div>';
}

/**
 * Render: ekwa/read-time.
 */
function ekwa_render_read_time_block( $attrs ) {
	$wpm     = absint( $attrs['wordsPerMinute'] ?? 200 );
	$post_id = get_the_ID();

	if ( ! $post_id ) {
		return '';
	}

	$content    = get_post_field( 'post_content', $post_id );
	$word_count = str_word_count( wp_strip_all_tags( $content ) );
	$minutes    = max( 1, (int) ceil( $word_count / $wpm ) );

	return '<span class="ekwa-read-time">'
		. '<i class="fa-regular fa-clock" aria-hidden="true"></i> '
		. esc_html( sprintf( _n( '%d min read', '%d min read', $minutes, 'ekwa' ), $minutes ) )
		. '</span>';
}

/**
 * Render: ekwa/share-button.
 */
function ekwa_render_share_button_block() {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return '<div class="ekwa-share-button"><i class="fa-solid fa-share-nodes"></i> Share</div>';
	}

	// Enqueue the external share script.
	wp_enqueue_script(
		'ekwa-share-embed',
		'https://www.doneformesocial.com/sm-share-buttons/embed.js',
		array(),
		null,
		true
	);

	return '<div class="ekwa-share-button"></div>';
}

/**
 * Render: ekwa/toc.
 */
function ekwa_render_toc_block( $attrs ) {
	$title = $attrs['title'] ?? __( 'Table of Contents', 'ekwa' );

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return '<nav class="ekwa-toc"><p class="ekwa-toc__title"><i class="fa-solid fa-bookmark"></i> ' . esc_html( $title ) . '</p><p style="color:#9ca3af;font-size:12px;">Auto-generated from headings.</p></nav>';
	}

	$post_id = get_the_ID();

	// No post context — nothing to render.
	if ( ! $post_id ) {
		return '';
	}

	// Parse rendered content to find headings.
	$raw_content = get_post_field( 'post_content', $post_id );
	$content     = ekwa_toc_add_heading_ids( do_blocks( $raw_content ), true );

	// Match all H2/H3 headings (with or without existing IDs).
	$headings = array();
	$used_ids = array();
	if ( preg_match_all( '/<h([23])([^>]*)>(.*?)<\/h[23]>/si', $content, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $m ) {
			$level = (int) $m[1];
			$attrs_str = $m[2];
			$text  = wp_strip_all_tags( $m[3] );

			// Extract id if present.
			$id = '';
			if ( preg_match( '/\bid=["\']([^"\']+)["\']/', $attrs_str, $id_match ) ) {
				$id = $id_match[1];
			}
			// Generate id if missing.
			if ( empty( $id ) ) {
				$id = sanitize_title( $text );
				if ( empty( $id ) ) {
					$id = 'heading-' . count( $headings );
				}
				$base = $id;
				$n = 2;
				while ( in_array( $id, $used_ids, true ) ) {
					$id = $base . '-' . $n++;
				}
			}
			$used_ids[] = $id;

			$headings[] = array(
				'level' => $level,
				'id'    => $id,
				'text'  => $text,
			);
		}
	}

	$has_toc = count( $headings ) >= 2;

	$html = '';

	// Render TOC only if enough headings.
	if ( $has_toc ) {
		wp_enqueue_script(
			'ekwa-toc',
			get_template_directory_uri() . '/assets/js/ekwa-toc.js',
			array(),
			filemtime( get_template_directory() . '/assets/js/ekwa-toc.js' ),
			true
		);

		$html .= '<nav class="ekwa-toc">';
		$html .= '<p class="ekwa-toc__title"><i class="fa-solid fa-bookmark" aria-hidden="true"></i> ' . esc_html( $title ) . '</p>';
		$html .= '<ul class="ekwa-toc__list">';

		$in_sub = false;
		foreach ( $headings as $h ) {
			if ( $h['level'] === 3 ) {
				if ( ! $in_sub ) {
					$html .= '<ul class="ekwa-toc__sublist">';
					$in_sub = true;
				}
				$html .= '<li><a href="#' . esc_attr( $h['id'] ) . '" class="ekwa-toc__link">' . esc_html( $h['text'] ) . '</a></li>';
			} else {
				if ( $in_sub ) {
					$html .= '</ul>';
					$in_sub = false;
				}
				$html .= '<li><a href="#' . esc_attr( $h['id'] ) . '" class="ekwa-toc__link">' . esc_html( $h['text'] ) . '</a></li>';
			}
		}
		if ( $in_sub ) {
			$html .= '</ul>';
		}

		$html .= '</ul></nav>';
	}

	// ── Recent Posts widget ──────────────────────────────────────
	$recent = get_posts( array(
		'posts_per_page' => 5,
		'post__not_in'   => array( get_the_ID() ),
		'post_status'    => 'publish',
	) );

	if ( $recent ) {
		$html .= '<div class="ekwa-sidebar-widget">';
		$html .= '<h4 class="ekwa-sidebar-widget__title">' . esc_html__( 'Recent Posts', 'ekwa' ) . '</h4>';
		$html .= '<ul class="ekwa-recent-posts">';
		foreach ( $recent as $rp ) {
			$thumb = get_the_post_thumbnail( $rp->ID, 'thumbnail', array(
				'class'   => 'ekwa-recent-posts__thumb',
				'loading' => 'lazy',
			) );
			$html .= '<li class="ekwa-recent-posts__item">';
			$html .= '<a href="' . esc_url( get_permalink( $rp->ID ) ) . '" class="ekwa-recent-posts__link">';
			$html .= $thumb;
			$html .= '<span class="ekwa-recent-posts__info">';
			$html .= '<span class="ekwa-recent-posts__name">' . esc_html( get_the_title( $rp->ID ) ) . '</span>';
			$html .= '<span class="ekwa-recent-posts__date">' . esc_html( get_the_date( 'M j, Y', $rp->ID ) ) . '</span>';
			$html .= '</span>';
			$html .= '</a>';
			$html .= '</li>';
		}
		$html .= '</ul></div>';
	}

	// ── Categories widget ────────────────────────────────────────
	$excluded_slugs = array( 'uncategorized', 'featured', 'featured-articles' );
	$cats = get_categories( array(
		'hide_empty' => true,
		'orderby'    => 'name',
		'order'      => 'ASC',
	) );
	$cats = array_filter( $cats, function ( $c ) use ( $excluded_slugs ) {
		return ! in_array( strtolower( $c->slug ), $excluded_slugs, true );
	} );

	if ( $cats ) {
		$html .= '<div class="ekwa-sidebar-widget">';
		$html .= '<h4 class="ekwa-sidebar-widget__title">' . esc_html__( 'Categories', 'ekwa' ) . '</h4>';
		$html .= '<ul class="ekwa-categories">';
		foreach ( $cats as $cat ) {
			$html .= '<li class="ekwa-categories__item">';
			$html .= '<a href="' . esc_url( get_category_link( $cat->term_id ) ) . '">' . esc_html( $cat->name ) . '</a>';
			$html .= '<span class="ekwa-categories__count">(' . $cat->count . ')</span>';
			$html .= '</li>';
		}
		$html .= '</ul></div>';
	}

	return $html;
}

/**
 * Render: ekwa/related-articles.
 */
function ekwa_render_related_articles_block( $attrs ) {
	$count        = absint( $attrs['count'] ?? 6 );
	$desktop      = absint( $attrs['desktopItems'] ?? 3 );
	$tablet       = absint( $attrs['tabletItems'] ?? 2 );
	$mobile       = absint( $attrs['mobileItems'] ?? 1 );
	$show_arrows  = ! empty( $attrs['showArrows'] );
	$show_dots    = ! empty( $attrs['showDots'] );
	$title        = $attrs['title'] ?? __( 'Related Articles', 'ekwa' );

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return '<div class="ekwa-related"><h2>' . esc_html( $title ) . '</h2><p style="color:#9ca3af;">Related articles carousel (rendered on front-end).</p></div>';
	}

	if ( ! get_the_ID() || get_post_type() !== 'post' ) {
		return '';
	}

	// Get primary category.
	$categories = get_the_category();
	$excluded   = array( 'uncategorized', 'featured', 'featured-articles' );
	$filtered   = array_filter( $categories, function ( $cat ) use ( $excluded ) {
		return ! in_array( strtolower( $cat->slug ), $excluded, true );
	} );

	if ( empty( $filtered ) ) {
		return '';
	}

	$cat_id = reset( $filtered )->term_id;

	$posts = get_posts( array(
		'post_type'      => 'post',
		'posts_per_page' => $count,
		'cat'            => $cat_id,
		'post__not_in'   => array( get_the_ID() ),
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );

	if ( empty( $posts ) ) {
		return '';
	}

	// Enqueue shared carousel assets (registered by ekwa_register_blocks).
	wp_enqueue_script( 'ekwa-carousel-view' );
	wp_enqueue_style(  'ekwa-carousel-style' );

	$data_attrs = ' data-desktop-items="' . $desktop . '"'
	            . ' data-tablet-items="' . $tablet . '"'
	            . ' data-mobile-items="' . $mobile . '"'
	            . ' data-show-arrows="' . ( $show_arrows ? 'true' : 'false' ) . '"'
	            . ' data-show-dots="' . ( $show_dots ? 'true' : 'false' ) . '"';

	$html  = '<div class="ekwa-related">';
	$html .= '<h2 class="ekwa-related__title">' . esc_html( $title ) . '</h2>';
	$html .= '<div class="ekwa-carousel"' . $data_attrs . '>';
	$html .= '<div class="ekwa-carousel__track">';

	foreach ( $posts as $p ) {
		$html .= '<div class="ekwa-carousel__item">';
		$html .= ekwa_render_post_card( $p->ID );
		$html .= '</div>';
	}

	$html .= '</div>'; // track

	if ( $show_arrows ) {
		$html .= '<button class="ekwa-carousel__arrow ekwa-carousel__arrow--prev" aria-label="' . esc_attr__( 'Previous', 'ekwa' ) . '"><i class="fa-solid fa-chevron-left"></i></button>';
		$html .= '<button class="ekwa-carousel__arrow ekwa-carousel__arrow--next" aria-label="' . esc_attr__( 'Next', 'ekwa' ) . '"><i class="fa-solid fa-chevron-right"></i></button>';
	}

	if ( $show_dots ) {
		$html .= '<div class="ekwa-carousel__dots"></div>';
	}

	$html .= '</div>'; // carousel
	$html .= '</div>'; // related

	return $html;
}

/**
 * Render: ekwa/load-more.
 */
function ekwa_render_load_more_block( $attrs ) {
	$button_text  = $attrs['buttonText'] ?? __( 'Load More', 'ekwa' );
	$loading_text = $attrs['loadingText'] ?? __( 'Loading...', 'ekwa' );
	$no_more_text = $attrs['noMoreText'] ?? __( 'No more posts', 'ekwa' );

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return '<div class="ekwa-load-more" style="text-align:center;padding:16px;"><button class="ekwa-load-more__btn" disabled>' . esc_html( $button_text ) . '</button></div>';
	}

	wp_enqueue_script(
		'ekwa-load-more',
		get_template_directory_uri() . '/assets/js/ekwa-load-more.js',
		array(),
		filemtime( get_template_directory() . '/assets/js/ekwa-load-more.js' ),
		true
	);

	wp_localize_script( 'ekwa-load-more', 'ekwaLoadMore', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
	) );

	return '<div class="ekwa-load-more" data-loading-text="' . esc_attr( $loading_text ) . '" data-no-more-text="' . esc_attr( $no_more_text ) . '">'
		. '<button class="ekwa-load-more__btn">' . esc_html( $button_text ) . '</button>'
		. '</div>';
}
