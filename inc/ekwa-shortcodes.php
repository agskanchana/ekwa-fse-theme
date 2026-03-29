<?php
/**
 * Ekwa Theme Shortcodes.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Set the adward_number tracking cookie when ?ads is present in the URL.
 *
 * Once set, the cookie keeps the adsense number visible site-wide for 30 days
 * even after the ?ads parameter is gone — matching the old theme behaviour.
 *
 * Cookie flags:
 *   httponly  – not accessible via JS (security)
 *   samesite  – Lax (safe default, works with normal navigations)
 *   secure    – only sent over HTTPS when the site uses it
 */
function ekwa_set_ad_tracking_cookie() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['ads'] ) ) {
		return;
	}

	// Cookie already present — refresh its expiry.
	$expiry = time() + ( 30 * DAY_IN_SECONDS );

	setcookie( 'adward_number', '1', array(
		'expires'  => $expiry,
		'path'     => '/',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	) );

	// Make the cookie available within this same request so phone numbers
	// swap immediately on the very first ?ads page load as well.
	$_COOKIE['adward_number'] = '1';
}
add_action( 'init', 'ekwa_set_ad_tracking_cookie' );

/**
 * Build a dialable tel: number from a human-readable phone string.
 *
 * Mirrors the old theme's mobile_number() function:
 *   - Strips all non-digit characters.
 *   - Auto-prepends +1 when the saved country is United States or Canada,
 *     unless the number already starts with a country code (+).
 *   - Use $country_code_override to supply any other country code manually
 *     (pass just the digits, e.g. '44' for UK → produces +44…).
 *     Pass an empty string to suppress any country-code prefix entirely.
 *
 * @param  string      $phone_number       Raw phone number string.
 * @param  string|null $country_code_override  Digits of the country code to
 *                                             force, '' to suppress, null to
 *                                             use auto-detection.
 * @return string  Dialable number suitable for a tel: href.
 */
function ekwa_mobile_number( $phone_number, $country_code_override = null ) {
	// Strip everything except digits.
	$digits = preg_replace( '/[^0-9]/', '', $phone_number );

	if ( '' === $digits ) {
		return '';
	}

	// Determine the country code to prefix.
	if ( null !== $country_code_override ) {
		// Caller supplied an explicit value (including '' to suppress).
		$code = preg_replace( '/[^0-9]/', '', (string) $country_code_override );
	} else {
		// Auto-detect from the saved country option (same logic as old theme).
		$country = get_option( 'ekwa_country', 'United States' );
		$code    = ( 'United States' === $country || 'Canada' === $country ) ? '1' : '';
	}

	if ( '' !== $code ) {
		return '+' . $code . $digits;
	}

	return $digits;
}

/**
 * [ekwa_phone] shortcode.
 *
 * Renders a clickable tel: link for a location phone number.
 * Mirrors the ad-tracking logic from the legacy phone-number block:
 *   - If ?ads is in the URL or the adward_number cookie is set, the
 *     adsense number (ekwa_adsense_number option) is shown for new-patient
 *     calls and the existing-patient number is hidden entirely.
 *   - Otherwise the number is pulled from the saved ekwa_locations data.
 *
 * Attributes:
 *   type          (string)  'new' | 'existing'   Default: 'new'
 *   location      (int)     1-based location index. Default: 1
 *   prefix        (string)  Label shown before the number. Leave blank for auto.
 *   show_icon     (bool)    Whether to render the phone icon. Default: true
 *   icon_class    (string)  CSS class(es) for the icon. Default: 'fa-solid fa-phone'
 *   country_code  (string)  Override the dialling country code (digits only,
 *                           e.g. "44"). Leave blank to auto-detect from the
 *                           saved country setting. Use "none" to suppress any
 *                           country code prefix.
 *
 * Usage examples:
 *   [ekwa_phone]
 *   [ekwa_phone type="existing" location="2"]
 *   [ekwa_phone type="new" prefix="Call us:" show_icon="false"]
 *   [ekwa_phone country_code="44"]
 *   [ekwa_phone country_code="none"]
 */
function ekwa_phone_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'type'         => 'new',
			'location'     => 1,
			'prefix'       => '',
			'show_icon'    => 'true',
			'icon_class'   => 'fa-solid fa-phone',
			'country_code' => '',   // '' = auto-detect; 'none' = no prefix; digits = forced code
		),
		$atts,
		'ekwa_phone'
	);

	$type       = sanitize_text_field( $atts['type'] );
	$loc_index  = max( 1, absint( $atts['location'] ) ) - 1;
	$prefix     = sanitize_text_field( $atts['prefix'] );
	$show_icon  = filter_var( $atts['show_icon'], FILTER_VALIDATE_BOOLEAN );
	$icon_class = sanitize_text_field( $atts['icon_class'] );

	// Resolve country_code override:
	//   ''     → null  (auto-detect)
	//   'none' → ''    (suppress prefix)
	//   digits → those digits
	$raw_cc = sanitize_text_field( $atts['country_code'] );
	if ( '' === $raw_cc ) {
		$country_code_override = null;            // auto-detect
	} elseif ( 'none' === strtolower( $raw_cc ) ) {
		$country_code_override = '';              // no prefix
	} else {
		$country_code_override = preg_replace( '/[^0-9]/', '', $raw_cc );
	}

	// -----------------------------------------------------------------------
	// Ad-tracking detection (same logic as old block: cookie OR ?ads param).
	// -----------------------------------------------------------------------
	$is_ad_tracking = (
		isset( $_COOKIE['adward_number'] ) ||
		isset( $_GET['ads'] )  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	);

	$phone_number = '';
	$prefix_text  = $prefix;

	if ( $is_ad_tracking ) {
		if ( 'new' === $type ) {
			// Show adsense/tracking number for new patients, drop any prefix label.
			$phone_number = get_option( 'ekwa_adsense_number', '' );
			$prefix_text  = '';
		} else {
			// Hide existing-patients number entirely during ad tracking.
			return '';
		}
	} else {
		// Normal path: pull from saved location data.
		$locations = get_option( 'ekwa_locations', array() );
		$loc       = isset( $locations[ $loc_index ] ) ? $locations[ $loc_index ] : array();

		if ( 'existing' === $type ) {
			$phone_number = isset( $loc['phone_existing'] ) ? $loc['phone_existing'] : '';
		} else {
			$phone_number = isset( $loc['phone_new'] ) ? $loc['phone_new'] : '';
		}

		// Build default prefix label when none was supplied.
		if ( '' === $prefix_text ) {
			$prefix_text = ( 'existing' === $type )
				? __( 'Existing Patients:', 'ekwa' )
				: __( 'New Patients:', 'ekwa' );
		}
	}

	if ( empty( $phone_number ) ) {
		return '';
	}

	// Build the dialable tel: number (country code logic lives in ekwa_mobile_number).
	$tel_number = ekwa_mobile_number( $phone_number, $country_code_override );

	// Build compact single-line output — no newlines to prevent wpautop from injecting <p>/<br>.
	$icon_html   = $show_icon ? '<i class="ekwa-phone-number__icon ' . esc_attr( $icon_class ) . '" aria-hidden="true"></i>' : '';
	$prefix_html = ! empty( $prefix_text ) ? '<span class="ekwa-phone-number__prefix">' . esc_html( $prefix_text ) . ' </span>' : '';

	return '<span class="ekwa-phone-number">'
		. '<a href="tel:' . esc_attr( $tel_number ) . '" class="ekwa-phone-number__link" aria-label="' . esc_attr( sprintf( __( 'Call %s', 'ekwa' ), $phone_number ) ) . '">'
		. $icon_html
		. '<span class="ekwa-phone-number__text">' . $prefix_html . '<span class="ekwa-phone-number__number">' . esc_html( $phone_number ) . '</span></span>'
		. '</a>'
		. '</span>';
}
add_shortcode( 'ekwa_phone', 'ekwa_phone_shortcode' );

/**
 * [ekwa_address] shortcode.
 *
 * Renders an address / directions link for a saved location.
 * Mirrors the functionality of the legacy address ACF block.
 *
 * Attributes:
 *   location   (int)     1-based location index. Default: 1
 *   mode       (string)  'icon'    – map-pin icon only (default)
 *                        'text'    – icon + "Directions" label
 *                        'address' – icon + formatted street address
 *                        'full'    – icon + full address including city/state/zip
 *   label      (string)  Custom link label used when mode="text". Default: 'Directions'
 *   show_icon  (bool)    Whether to show the map-pin icon. Default: true
 *   icon_class (string)  FA icon class. Default: 'fa-solid fa-location-dot'
 *   new_tab    (bool)    Open in new tab. Default: true
 *
 * Usage examples:
 *   [ekwa_address]
 *   [ekwa_address mode="address" location="2"]
 *   [ekwa_address mode="text" label="Get Directions"]
 *   [ekwa_address mode="full" show_icon="false"]
 */
function ekwa_address_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'location'   => 1,
			'mode'       => 'icon',
			'label'      => '',
			'show_icon'  => 'true',
			'icon_class' => 'fa-solid fa-location-dot',
			'new_tab'    => 'true',
		),
		$atts,
		'ekwa_address'
	);

	$loc_index  = max( 1, absint( $atts['location'] ) ) - 1;
	$mode       = sanitize_text_field( $atts['mode'] );
	$label      = sanitize_text_field( $atts['label'] );
	$show_icon  = filter_var( $atts['show_icon'], FILTER_VALIDATE_BOOLEAN );
	$icon_class = sanitize_text_field( $atts['icon_class'] );
	$new_tab    = filter_var( $atts['new_tab'], FILTER_VALIDATE_BOOLEAN );

	$locations = get_option( 'ekwa_locations', array() );
	$loc       = isset( $locations[ $loc_index ] ) ? $locations[ $loc_index ] : array();

	$direction_url = isset( $loc['direction'] ) ? esc_url( $loc['direction'] ) : '';
	$street        = isset( $loc['street'] )    ? sanitize_text_field( $loc['street'] )  : '';
	$city          = isset( $loc['city'] )      ? sanitize_text_field( $loc['city'] )    : '';
	$state         = isset( $loc['state'] )     ? sanitize_text_field( $loc['state'] )   : '';
	$zip           = isset( $loc['zip'] )       ? sanitize_text_field( $loc['zip'] )     : '';

	// Build address strings.
	$street_line = $street;
	$city_line   = trim( implode( ', ', array_filter( array( $city, $state ) ) ) );
	if ( $zip ) {
		$city_line = trim( $city_line . ' ' . $zip );
	}
	$full_address = trim( implode( ', ', array_filter( array( $street_line, $city_line ) ) ) );

	// Resolve what text to show based on mode.
	$display_text = '';
	if ( 'text' === $mode ) {
		$display_text = $label ? $label : __( 'Directions', 'ekwa' );
	} elseif ( 'address' === $mode ) {
		// Show "City, State" — matches the screenshot style.
		$display_text = trim( implode( ', ', array_filter( array( $city, $state ) ) ) );
	} elseif ( 'full' === $mode ) {
		$display_text = $full_address;
	}

	// aria-label for screen readers.
	$aria_label = $full_address
		? sprintf( __( 'Get directions to %s', 'ekwa' ), $full_address )
		: __( 'Get directions to our location', 'ekwa' );

	$target    = $new_tab ? ' target="_blank" rel="noreferrer nofollow"' : '';
	$css_class = 'ekwa-address ekwa-address--' . esc_attr( $mode );

	$inner = '';
	if ( $show_icon ) {
		$inner .= '<i class="ekwa-address__icon ' . esc_attr( $icon_class ) . '" aria-hidden="true" style="margin-right:0.4em;"></i>';
	}
	if ( ! empty( $display_text ) ) {
		$inner .= '<span class="ekwa-address__text">' . esc_html( $display_text ) . '</span>';
	}

	return '<a href="' . $direction_url . '" class="' . esc_attr( $css_class ) . '" aria-label="' . esc_attr( $aria_label ) . '"' . $target . '>' . $inner . '</a>';
}
add_shortcode( 'ekwa_address', 'ekwa_address_shortcode' );

/**
 * Server-side render filter for the core/button block.
 *
 * When a button has { ekwaPhoneButton: true }, the href is replaced at
 * request time with a tel: link — applying the same ad-tracking logic
 * used by [ekwa_phone].
 *
 *   - ?ads in URL or adward_number cookie + type "new"  → adsense number
 *   - ?ads / cookie + type "existing"                  → button hidden
 *   - Normal                                            → number from saved locations
 */
function ekwa_render_button_phone( $block_content, $block ) {
	if ( 'core/button' !== $block['blockName'] ) {
		return $block_content;
	}

	if ( empty( $block['attrs']['ekwaPhoneButton'] ) ) {
		return $block_content;
	}

	$type      = isset( $block['attrs']['ekwaPhoneType'] )     ? $block['attrs']['ekwaPhoneType']     : 'new';
	$location  = isset( $block['attrs']['ekwaPhoneLocation'] ) ? (int) $block['attrs']['ekwaPhoneLocation'] : 1;
	$loc_index = max( 1, $location ) - 1;

	// Ad-tracking detection — same as ekwa_phone_shortcode.
	$is_ad_tracking = (
		isset( $_COOKIE['adward_number'] ) ||
		isset( $_GET['ads'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	);

	$phone_number = '';

	if ( $is_ad_tracking ) {
		if ( 'new' === $type ) {
			$phone_number = get_option( 'ekwa_adsense_number', '' );
		} else {
			// Hide existing-patients button entirely during ad tracking.
			return '';
		}
	} else {
		$locations    = get_option( 'ekwa_locations', array() );
		$loc          = isset( $locations[ $loc_index ] ) ? $locations[ $loc_index ] : array();
		$phone_number = ( 'existing' === $type )
			? ( isset( $loc['phone_existing'] ) ? $loc['phone_existing'] : '' )
			: ( isset( $loc['phone_new'] )      ? $loc['phone_new']      : '' );
	}

	if ( empty( $phone_number ) ) {
		return $block_content;
	}

	$tel_number = ekwa_mobile_number( $phone_number );
	$tel_href   = 'tel:' . esc_attr( $tel_number );

	// The core button renders without an href when the URL field is left blank:
	//   <a class="wp-block-button__link …">Label</a>
	// Handle both cases:
	//   1. href already present (user typed something) → replace it.
	//   2. href absent (URL left blank)               → inject it.
	if ( preg_match( '/href=/i', $block_content ) ) {
		// Replace existing href value.
		$block_content = preg_replace(
			'/(href=)["\'][^"\']*["\']/i',
			'$1"' . $tel_href . '"',
			$block_content,
			1
		);
	} else {
		// Inject href into the first <a …> tag.
		$block_content = preg_replace(
			'/<a\b/i',
			'<a href="' . $tel_href . '"',
			$block_content,
			1
		);
	}

	return $block_content;
}
add_filter( 'render_block', 'ekwa_render_button_phone', 10, 2 );

/**
 * [ekwa_copyright] shortcode.
 *
 * Renders the site copyright line. Styling is handled entirely by the theme.
 *
 * Usage:
 *   [ekwa_copyright]
 */
function ekwa_copyright_shortcode() {
	// Prefer ekwa_practice_name from ekwa-settings, fallback to theme_mod, then bloginfo
	$practice_name = get_option( 'ekwa_practice_name', '' );
	if ( empty( $practice_name ) ) {
		$practice_name = get_theme_mod( 'practise_name', get_bloginfo( 'name' ) );
	}
	$current_year  = wp_date( 'Y' );

	ob_start();
	?>
	<div class="ekwa-copyright">
		&copy; <?php echo esc_html( $current_year ); ?> <?php echo esc_html( $practice_name ); ?>. All Rights Reserved.
		Powered by <a href="https://www.ekwa.com" target="_blank" rel="noreferrer nofollow">www.ekwa.com</a>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'ekwa_copyright', 'ekwa_copyright_shortcode' );

/* ==========================================================================
 * Working Hours helpers — used exclusively by [ekwa_hours].
 * ========================================================================== */

/**
 * Format hour/min/period components into a readable time string.
 * e.g. ( '09', '00', 'AM' ) → '9:00 AM'
 */
if ( ! function_exists( 'ekwa_wh_format_time' ) ) {
	function ekwa_wh_format_time( $hour, $min, $period ) {
		return (int) $hour . ':' . $min . ' ' . strtoupper( $period );
	}
}

/**
 * Build a unique key used to identify identical time slots for grouping.
 */
if ( ! function_exists( 'ekwa_wh_time_key' ) ) {
	function ekwa_wh_time_key( $wh ) {
		return $wh['open_hour'] . $wh['open_min'] . $wh['open_period'] .
		       '-' . $wh['close_hour'] . $wh['close_min'] . $wh['close_period'];
	}
}

/**
 * Convert a full day name to its 3-letter abbreviation.
 */
if ( ! function_exists( 'ekwa_wh_short_day' ) ) {
	function ekwa_wh_short_day( $day ) {
		$map = array(
			'Monday'    => 'Mon',
			'Tuesday'   => 'Tue',
			'Wednesday' => 'Wed',
			'Thursday'  => 'Thu',
			'Friday'    => 'Fri',
			'Saturday'  => 'Sat',
			'Sunday'    => 'Sun',
		);
		return isset( $map[ $day ] ) ? $map[ $day ] : $day;
	}
}

/**
 * [ekwa_hours] shortcode.
 *
 * Displays working hours for a saved ekwa location.
 *
 * Attributes:
 *   location     (int)    1-based location index. Default: 1
 *   show_closed  (bool)   Include closed days in the output. Default: true
 *   short_days   (bool)   Abbreviate day names (Mon/Tue…). Default: false
 *   group        (string) How to combine days with the same hours:
 *                           'none'        – one row per day (default)
 *                           'consecutive' – adjacent days sharing the same hours
 *                                          shown as a range, e.g. Mon – Fri: 9:00 AM – 5:00 PM
 *                           'all'         – all days sharing the same hours regardless
 *                                          of position, e.g. Mon, Wed, Fri: 9:00 AM – 5:00 PM
 *   show_notes   (bool)   Append extra_note text to each row. Default: true
 *   closed_label (string) Text displayed for closed days. Default: 'Closed'
 *
 * Usage:
 *   [ekwa_hours]
 *   [ekwa_hours location="2" group="consecutive" short_days="true"]
 *   [ekwa_hours show_closed="false"]
 *   [ekwa_hours group="all" closed_label="Not available" show_notes="false"]
 */
function ekwa_hours_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'location'     => 1,
			'show_closed'  => 'true',
			'short_days'   => 'false',
			'group'        => 'none',
			'show_notes'   => 'true',
			'closed_label' => 'Closed',
		),
		$atts,
		'ekwa_hours'
	);

	$loc_index    = max( 1, absint( $atts['location'] ) ) - 1;
	$show_closed  = filter_var( $atts['show_closed'], FILTER_VALIDATE_BOOLEAN );
	$short_days   = filter_var( $atts['short_days'],  FILTER_VALIDATE_BOOLEAN );
	$show_notes   = filter_var( $atts['show_notes'],  FILTER_VALIDATE_BOOLEAN );
	$group        = sanitize_text_field( $atts['group'] );
	$closed_label = sanitize_text_field( $atts['closed_label'] );

	// Pull raw hours from the saved location.
	$locations = get_option( 'ekwa_locations', array() );
	$loc       = isset( $locations[ $loc_index ] ) ? $locations[ $loc_index ] : array();
	$raw_hours = isset( $loc['working_hours'] ) ? $loc['working_hours'] : array();

	if ( empty( $raw_hours ) ) {
		return '<div class="ekwa-working-hours"><p class="ekwa-working-hours__empty">' .
		       esc_html__( 'No working hours available.', 'ekwa' ) .
		       '</p></div>';
	}

	/* ------------------------------------------------------------------
	 * Build the $rows array.
	 * Each entry: [ 'label' => '', 'time' => '', 'note' => '', 'is_closed' => bool ]
	 * ------------------------------------------------------------------ */
	$rows = array();

	/* ---- Helper: build a formatted time range string from a raw entry ---- */
	$fmt_time = function ( $wh ) use ( $closed_label ) {
		return empty( $wh['closed'] )
			? ekwa_wh_format_time( $wh['open_hour'], $wh['open_min'], $wh['open_period'] ) .
			  ' – ' .
			  ekwa_wh_format_time( $wh['close_hour'], $wh['close_min'], $wh['close_period'] )
			: $closed_label;
	};

	/* ---- Helper: pick a shared note (empty string when notes differ) ---- */
	$shared_note = function ( $notes ) {
		$unique = array_unique( array_filter( $notes ) );
		return count( $unique ) === 1 ? reset( $unique ) : '';
	};

	/* ---- Helper: resolve day label ---- */
	$day_label = function ( $day ) use ( $short_days ) {
		return $short_days ? ekwa_wh_short_day( $day ) : $day;
	};

	// -----------------------------------------------------------------------
	// GROUP: consecutive — run-length encode adjacent days with identical hours.
	// -----------------------------------------------------------------------
	if ( 'consecutive' === $group ) {

		$total = count( $raw_hours );
		$i     = 0;

		while ( $i < $total ) {
			$cur       = $raw_hours[ $i ];
			$is_closed = ! empty( $cur['closed'] );
			$time_key  = $is_closed ? '__closed__' : ekwa_wh_time_key( $cur );

			if ( ! $show_closed && $is_closed ) {
				$i++;
				continue;
			}

			$start_day = $cur['day'];
			$end_day   = $cur['day'];
			$notes     = array( isset( $cur['extra_note'] ) ? $cur['extra_note'] : '' );
			$j         = $i + 1;

			while ( $j < $total ) {
				$nxt          = $raw_hours[ $j ];
				$nxt_closed   = ! empty( $nxt['closed'] );
				$nxt_time_key = $nxt_closed ? '__closed__' : ekwa_wh_time_key( $nxt );

				// A skipped (hidden) closed day breaks a run.
				if ( ! $show_closed && $nxt_closed ) {
					break;
				}

				if ( $nxt_time_key !== $time_key ) {
					break;
				}

				$end_day = $nxt['day'];
				$notes[] = isset( $nxt['extra_note'] ) ? $nxt['extra_note'] : '';
				$j++;
			}

			$label = ( $start_day === $end_day )
				? $day_label( $start_day )
				: $day_label( $start_day ) . ' – ' . $day_label( $end_day );

			$rows[] = array(
				'label'     => $label,
				'time'      => $fmt_time( $cur ),
				'note'      => $shared_note( $notes ),
				'is_closed' => $is_closed,
			);

			$i = $j;
		}

	// -----------------------------------------------------------------------
	// GROUP: all — bucket days by time signature regardless of position.
	// -----------------------------------------------------------------------
	} elseif ( 'all' === $group ) {

		$buckets = array(); // time_key => [ days, is_closed, time, notes ]

		foreach ( $raw_hours as $wh ) {
			$is_closed = ! empty( $wh['closed'] );

			if ( ! $show_closed && $is_closed ) {
				continue;
			}

			$time_key = $is_closed ? '__closed__' : ekwa_wh_time_key( $wh );

			if ( ! isset( $buckets[ $time_key ] ) ) {
				$buckets[ $time_key ] = array(
					'days'      => array(),
					'is_closed' => $is_closed,
					'time'      => $fmt_time( $wh ),
					'notes'     => array(),
				);
			}

			$buckets[ $time_key ]['days'][]  = $wh['day'];
			$buckets[ $time_key ]['notes'][] = isset( $wh['extra_note'] ) ? $wh['extra_note'] : '';
		}

		foreach ( $buckets as $bucket ) {
			$rows[] = array(
				'label'     => implode( ', ', array_map( $day_label, $bucket['days'] ) ),
				'time'      => $bucket['time'],
				'note'      => $shared_note( $bucket['notes'] ),
				'is_closed' => $bucket['is_closed'],
			);
		}

	// -----------------------------------------------------------------------
	// GROUP: none — one row per day.
	// -----------------------------------------------------------------------
	} else {

		foreach ( $raw_hours as $wh ) {
			$is_closed = ! empty( $wh['closed'] );

			if ( ! $show_closed && $is_closed ) {
				continue;
			}

			$rows[] = array(
				'label'     => $day_label( $wh['day'] ),
				'time'      => $fmt_time( $wh ),
				'note'      => isset( $wh['extra_note'] ) ? $wh['extra_note'] : '',
				'is_closed' => $is_closed,
			);
		}
	}

	if ( empty( $rows ) ) {
		return '<div class="ekwa-working-hours"><p class="ekwa-working-hours__empty">' .
		       esc_html__( 'No working hours available.', 'ekwa' ) .
		       '</p></div>';
	}

	ob_start();
	?>
	<div class="ekwa-working-hours">
		<div class="ekwa-working-hours__list">
			<?php foreach ( $rows as $row ) : ?>
				<div class="ekwa-working-hours__row<?php echo $row['is_closed'] ? ' ekwa-working-hours__row--closed' : ''; ?>">
					<span class="ekwa-working-hours__day"><?php echo esc_html( $row['label'] ); ?></span>
					<span class="ekwa-working-hours__time"><?php echo esc_html( $row['time'] ); ?></span>
					<?php if ( $show_notes && ! empty( $row['note'] ) ) : ?>
						<span class="ekwa-working-hours__note"><?php echo esc_html( $row['note'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'ekwa_hours', 'ekwa_hours_shortcode' );

/**
 * [ekwa_social] — Social media icon row with optional share toggle.
 *
 * Attributes:
 *  show_share  true/false — Whether to show the share button. Default: true.
 *
 * Social links are read from the 'ekwa_social' option (set in Ekwa Settings).
 * Each entry has: name, link, icon (Font Awesome class string).
 */
function ekwa_social_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'show_share' => 'true',
	), $atts, 'ekwa_social' );

	$show_share = filter_var( $atts['show_share'], FILTER_VALIDATE_BOOLEAN );
	$links      = get_option( 'ekwa_social', array() );

	if ( empty( $links ) || ! is_array( $links ) ) {
		return '';
	}

	// Unique ID per shortcode instance on the page.
	static $instance = 0;
	$instance++;
	$uid   = 'ekwa-soc-' . $instance;
	$js_fn = 'ekwaSocToggle' . $instance;

	// Emit base CSS once per page load.
	static $base_css_done = false;
	$out = '';
	if ( ! $base_css_done ) {
		$base_css_done = true;
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

	// Emit global click-outside handler once.
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

	foreach ( $links as $link ) {
		$link = wp_parse_args( $link, array( 'name' => '', 'link' => '', 'icon' => '' ) );
		if ( empty( $link['link'] ) ) {
			continue;
		}
		$label = ! empty( $link['name'] ) ? esc_attr( $link['name'] ) : esc_attr__( 'Social Media', 'ekwa' );
		$out  .= '<a class="sm-icons" aria-label="' . $label . '" rel="noopener noreferrer" target="_blank" href="' . esc_url( $link['link'] ) . '">';
		if ( ! empty( $link['icon'] ) ) {
			$out .= '<i class="' . esc_attr( $link['icon'] ) . '"></i>';
		}
		$out .= '</a>';
	}

	if ( $show_share ) {
		$out .= '<button class="addthis" aria-label="' . esc_attr__( 'Toggle Share', 'ekwa' ) . '" onclick="' . esc_js( $js_fn ) . '()" type="button">'
			. '<i class="fa-solid fa-share-nodes"></i>'
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
add_shortcode( 'ekwa_social', 'ekwa_social_shortcode' );
