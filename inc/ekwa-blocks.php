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

	// Emit the shared CSS once per page — uses a global flag so it is not
	// duplicated if both the shortcode and the block appear on the same page.
	global $ekwa_social_css_printed;
	$out = '';
	if ( empty( $ekwa_social_css_printed ) ) {
		$ekwa_social_css_printed = true;
		$out .= '<style id="ekwa-social-css">'
			. '.ekwa-social-icons .social-media{display:flex;gap:10px;align-items:center;flex-wrap:wrap}'
			. '.ekwa-social-icons .sm-icons{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:none;background:none;padding:0}'
			. '.ekwa-social-icons .addthis{position:relative;display:inline-flex;align-items:center;background:none;border:none;padding:0;cursor:pointer}'
			. '.ekwa-social-icons .addthis span.hide{display:none}'
			. '.ekwa-social-icons .share-toggle{visibility:hidden;opacity:0;position:absolute;bottom:calc(100% + 12px);left:50%;transform:translateX(-50%) translateY(10px);background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.15);padding:8px;transition:all .3s ease;z-index:99;white-space:nowrap}'
			. '.ekwa-social-icons .share-toggle.active{visibility:visible;opacity:1;transform:translateX(-50%) translateY(0)}'
			. '.ekwa-social-icons .share-toggle::after{content:"";position:absolute;top:100%;left:50%;transform:translateX(-50%);border:10px solid transparent;border-top-color:#fff;filter:drop-shadow(0 3px 2px rgba(0,0,0,.1))}'
			. '.ekwa-social-icons .share-toggle a{color:#fff;width:44px;height:44px;margin:4px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border-radius:8px;transition:all .2s ease}'
			. '.ekwa-social-icons .share-toggle a:hover{transform:scale(1.1)}'
			. '.ekwa-social-icons .share-toggle i{font-size:20px}'
			. '.share-facebook{background:#3b5998}'
			. '.share-twit{background:#38A1F3}'
			. '.share-pinterest{background:#E60023}'
			. '</style>';
	}

	static $global_js_done = false;
	if ( $show_share && ! $global_js_done ) {
		$global_js_done = true;
		$out .= '<script>document.addEventListener("click",function(e){'
			. 'if(!e.target.closest(".ekwa-social-icons .addthis")){'
			. 'document.querySelectorAll(".ekwa-social-icons .share-toggle.active")'
			. '.forEach(function(el){el.classList.remove("active");});}'
			. '});</script>';
	}

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
