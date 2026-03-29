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

	// Build the output.
	ob_start();
	?>
	<span class="ekwa-phone-number">
		<a href="tel:<?php echo esc_attr( $tel_number ); ?>"
		   class="ekwa-phone-number__link"
		   aria-label="<?php echo esc_attr( sprintf( __( 'Call %s', 'ekwa' ), $phone_number ) ); ?>">

			<?php if ( $show_icon ) : ?>
				<i class="ekwa-phone-number__icon <?php echo esc_attr( $icon_class ); ?>" aria-hidden="true"></i>
			<?php endif; ?>

			<span class="ekwa-phone-number__text">
				<?php if ( ! empty( $prefix_text ) ) : ?>
					<span class="ekwa-phone-number__prefix"><?php echo esc_html( $prefix_text ); ?> </span>
				<?php endif; ?>
				<span class="ekwa-phone-number__number"><?php echo esc_html( $phone_number ); ?></span>
			</span>
		</a>
	</span>
	<?php
	return ob_get_clean();
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

	$target = $new_tab ? ' target="_blank" rel="noreferrer nofollow"' : '';
	$css_class = 'ekwa-address ekwa-address--' . esc_attr( $mode );

	ob_start();
	?>
	<a href="<?php echo $direction_url; ?>"
	   class="<?php echo esc_attr( $css_class ); ?>"
	   aria-label="<?php echo esc_attr( $aria_label ); ?>"
	   <?php echo $target; ?>>

		<?php if ( $show_icon ) : ?>
			<i class="ekwa-address__icon <?php echo esc_attr( $icon_class ); ?>" aria-hidden="true"></i>
		<?php endif; ?>

		<?php if ( ! empty( $display_text ) ) : ?>
			<span class="ekwa-address__text"><?php echo esc_html( $display_text ); ?></span>
		<?php endif; ?>

	</a>
	<?php
	return ob_get_clean();
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
