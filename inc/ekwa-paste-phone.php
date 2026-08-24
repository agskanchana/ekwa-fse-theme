<?php
/**
 * Dynamic phone numbers on paste.
 *
 * Copy handed to an editor — a Word doc, a client email, the old site — carries
 * the practice's phone numbers as literal text. Those go stale the moment a
 * number changes in Ekwa Settings → Locations, and they miss the ad-tracking
 * swap that [ekwa_phone] performs at render time.
 *
 * This swaps them at the one moment the author is guaranteed to be looking: the
 * paste. Any number in the pasted content that matches a configured location
 * number — new-patient, existing-patient, any location — is replaced with the
 * matching [ekwa_phone] shortcode, which resolves live on every render. Numbers
 * the settings don't know about (a referral office, a lab, a fax) are left
 * exactly as pasted.
 *
 * The matching runs in the editor (assets/js/ekwa-paste-phone.js) against the
 * map localized below; this file owns the map itself, which the bulk-page
 * importer shares so both paths swap the same numbers for the same shortcodes.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map phone-number digit strings to the [ekwa_phone] shortcode that renders them.
 *
 * Keys are digits-only with any leading US country code trimmed, so the same
 * number written (813) 734-7102 / 813-734-7102 / +1 813.734.7102 / 8137347102
 * all normalise to one key.
 *
 * First definition wins: when the same number is saved as both the new- and
 * existing-patient line (or repeated across locations), the earlier one is what
 * gets inserted, since there is nothing in the text to tell the two apart.
 *
 * @return array<string,string> digits => shortcode.
 */
function ekwa_phone_token_map() {
	$locations = get_option( 'ekwa_locations', array() );
	$map       = array();

	if ( ! is_array( $locations ) ) {
		return $map;
	}

	foreach ( $locations as $i => $loc ) {
		if ( ! is_array( $loc ) ) {
			continue;
		}
		$loc_num = $i + 1;

		$new = ekwa_phone_normalize_digits( $loc['phone_new'] ?? '' );
		if ( strlen( $new ) >= 7 && ! isset( $map[ $new ] ) ) {
			$map[ $new ] = ekwa_phone_shortcode_tag( 'new', $loc_num );
		}

		$existing = ekwa_phone_normalize_digits( $loc['phone_existing'] ?? '' );
		if ( strlen( $existing ) >= 7 && ! isset( $map[ $existing ] ) ) {
			$map[ $existing ] = ekwa_phone_shortcode_tag( 'existing', $loc_num );
		}
	}

	return $map;
}

/**
 * Write the shortest [ekwa_phone] tag that resolves to a given number.
 *
 * ekwa_phone_shortcode() already defaults to type="new" location="1", so
 * spelling either out adds nothing but noise in the middle of a paragraph —
 * the first location's new-patient number is simply [ekwa_phone].
 *
 * @param string $type    'new' or 'existing'.
 * @param int    $loc_num 1-based location index.
 * @return string Shortcode tag.
 */
function ekwa_phone_shortcode_tag( $type, $loc_num ) {
	$atts = '';
	if ( 'new' !== $type ) {
		$atts .= sprintf( ' type="%s"', $type );
	}
	if ( 1 !== (int) $loc_num ) {
		$atts .= sprintf( ' location="%d"', (int) $loc_num );
	}
	return '[ekwa_phone' . $atts . ']';
}

/**
 * Reduce a phone number to comparable digits — no separators, no leading "1".
 *
 * @param string $raw Number in any formatting.
 * @return string Digits only, or '' when there were none.
 */
function ekwa_phone_normalize_digits( $raw ) {
	$digits = preg_replace( '/\D+/', '', (string) $raw );
	if ( '' === $digits ) {
		return '';
	}
	if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
		$digits = substr( $digits, 1 );
	}
	return $digits;
}

/**
 * Enqueue the paste rewriter in the block editor.
 *
 * Skipped entirely when no location has a usable phone number — with an empty
 * map the script has nothing to match and would only cost a request.
 */
function ekwa_enqueue_paste_phone_editor_script() {
	$map = ekwa_phone_token_map();
	if ( ! $map ) {
		return;
	}

	wp_enqueue_script(
		'ekwa-paste-phone',
		get_template_directory_uri() . '/assets/js/ekwa-paste-phone.js',
		array(
			'wp-blocks',
			'wp-block-editor',
			'wp-data',
			'wp-rich-text',
			'wp-i18n',
		),
		filemtime( get_template_directory() . '/assets/js/ekwa-paste-phone.js' ),
		true
	);

	wp_localize_script(
		'ekwa-paste-phone',
		'ekwaPastePhone',
		array( 'map' => $map )
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_paste_phone_editor_script' );
