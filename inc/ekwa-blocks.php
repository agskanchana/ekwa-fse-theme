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
	$appt_page  = get_option( 'ekwa_appt_page', 0 );
	$appt_url   = get_option( 'ekwa_appt_url', '' );
	$adsense    = get_option( 'ekwa_adsense_number', '' );

	// Determine appointment link.
	if ( 'external' === $appt_type && $appt_url ) {
		$appt_link   = $appt_url;
		$appt_target = ' target="_blank" rel="noopener"';
	} elseif ( $appt_page ) {
		$appt_link   = get_permalink( $appt_page );
		$appt_target = '';
	} else {
		$appt_link   = '#';
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

	/* CSS and JS are now in ekwa-blocks.css / ekwa-blocks.js. */
	// ── HTML ─────────────────────────────────────────────────────
	$html  = '<div class="ekwa-mobile-dock">';
	$html .= '<div class="dock-wrap">';

	// SVG icons.
	$svg_phone    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
	$svg_calendar = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
	$svg_arrow_up = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
	$svg_services = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z"/><path d="M12 8v8M8 12h8"/></svg>';
	$svg_pin      = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
	$svg_chevron  = '<svg class="accordion-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>';

	// 1. Call
	if ( $needs_call_popup ) {
		$html .= '<button class="dock-item call-item" data-popup="call-popup-' . $bid . '" aria-label="Call">';
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
	$html .= '<button class="dock-item scroll-up-item" aria-label="Scroll to Top">';
	$html .= $svg_arrow_up . '<span class="dock-label">Up</span></button>';

	$html .= '<span class="dock-divider"></span>';

	// 4. Services
	$html .= '<button class="dock-item services-item" aria-label="Services">';
	$html .= $svg_services . '<span class="dock-label">Services</span></button>';

	// 5. Find Us
	if ( $needs_loc_popup ) {
		$html .= '<button class="dock-item findus-item" data-popup="location-popup-' . $bid . '" aria-label="Find Us">';
	} else {
		$html .= '<a href="' . esc_url( $single_direction ) . '" class="dock-item findus-item" target="_blank" rel="noopener" aria-label="Find Us">';
	}
	$html .= $svg_pin . '<span class="dock-label">Find Us</span>';
	$html .= $needs_loc_popup ? '</button>' : '</a>';

	$html .= '</div>'; // .dock-wrap
	$html .= '</div>'; // #bid

	// ── Call popup ──────────────────────────────────────────────
	if ( $needs_call_popup ) {
		$html .= '<div class="ekwa-dock-popup" id="call-popup-' . esc_attr( $bid ) . '">';
		$html .= '<div class="popup-content"><div class="popup-header">';
		$html .= '<div class="popup-title">Call Us</div>';
		$html .= '<button class="popup-close" aria-label="Close">&times;</button>';
		$html .= '</div><div class="popup-body">';

		$li = 0;
		foreach ( $phone_data as $loc ) {
			if ( ! $loc['new'] && ! $loc['existing'] ) { continue; }
			$aid   = 'call-acc-' . $bid . '-' . $li;
			$first = ( 0 === $li );

			$html .= '<div class="location-accordion">';
			if ( count( $phone_data ) > 1 ) {
				$html .= '<button class="accordion-header' . ( $first ? ' active' : '' )
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
		$html .= '<button class="popup-close" aria-label="Close">&times;</button>';
		$html .= '</div><div class="popup-body">';

		$li = 0;
		foreach ( $phone_data as $loc ) {
			if ( ! $loc['dir'] || ! $loc['address'] ) { continue; }
			$aid   = 'loc-acc-' . $bid . '-' . $li;
			$first = ( 0 === $li );

			$html .= '<div class="location-accordion">';
			$html .= '<button class="accordion-header' . ( $first ? ' active' : '' )
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
