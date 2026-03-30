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
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		wp_get_theme()->get( 'Version' ),
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
	$wrapper_class = sanitize_text_field( isset( $attrs['wrapperClass'] ) ? $attrs['wrapperClass'] : 'way-icon' );
	$size          = isset( $attrs['size'] ) ? absint( $attrs['size'] ) : 0;
	$color         = sanitize_text_field( isset( $attrs['color'] ) ? $attrs['color'] : '' );
	$align_raw     = isset( $attrs['align'] ) ? $attrs['align'] : '';
	$anchor        = isset( $attrs['anchor'] ) ? sanitize_html_class( $attrs['anchor'] ) : '';

	$align = in_array( $align_raw, array( 'left', 'center', 'right' ), true ) ? $align_raw : '';

	$icon_style = '';
	if ( $size )  { $icon_style .= 'font-size:' . $size . 'px;'; }
	if ( $color ) { $icon_style .= 'color:' . esc_attr( $color ) . ';'; }

	$wrapper_attrs  = ' class="' . esc_attr( $wrapper_class ) . '"';
	if ( $anchor )  { $wrapper_attrs .= ' id="' . esc_attr( $anchor ) . '"'; }
	if ( $align )   { $wrapper_attrs .= ' style="text-align:' . esc_attr( $align ) . ';"'; }

	$icon_attrs  = ' class="' . esc_attr( $icon_class ) . '" aria-hidden="true"';
	if ( $icon_style ) { $icon_attrs .= ' style="' . esc_attr( $icon_style ) . '"'; }

	return '<div' . $wrapper_attrs . '><i' . $icon_attrs . '></i></div>';
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
