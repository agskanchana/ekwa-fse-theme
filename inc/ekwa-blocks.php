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
}
add_action( 'init', 'ekwa_register_blocks' );


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
