<?php
/**
 * Phone numbers ↔ [ekwa_phone] tokens.
 *
 * A phone number written into content as literal text goes stale the moment a
 * number changes in Ekwa Settings → Locations, and it misses the ad-tracking
 * swap [ekwa_phone] performs at render time. This file keeps numbers dynamic
 * wherever they get authored:
 *
 *  - Pasting into the block editor — assets/js/ekwa-paste-phone.js swaps matched
 *    numbers for the [ekwa_phone] shortcode that renders them.
 *  - Saving a Yoast SEO title / meta description — the same swap, server-side.
 *    Yoast's snippet fields are an undocumented React editor, so this hooks the
 *    save instead of the keystroke, which also covers typing, the classic editor
 *    and Quick Edit.
 *  - The bulk page importer — inc/ekwa-settings.php shares the swap below.
 *
 * Yoast fields need one extra step on the way out. Nothing runs shortcodes on
 * meta output, and a <meta> tag could not carry the shortcode's
 * <a href="tel:…"> markup anyway, so the token is resolved back to the bare
 * number in the meta tags, the OG/Twitter tags and the schema graph.
 *
 * Numbers the settings don't know about — a referral office, a lab, a fax — are
 * never touched on any of these paths.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * The number map.
 * ------------------------------------------------------------------ */

/**
 * Reduce a phone number to comparable digits — no separators, no leading "1".
 *
 * Mirrored by digitsOf() in assets/js/ekwa-paste-phone.js.
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
 * Map phone-number digit strings to the [ekwa_phone] tag that renders them.
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

/* ------------------------------------------------------------------
 * Number  ->  token.
 * ------------------------------------------------------------------ */

/**
 * Replace configured phone numbers in a text blob with their [ekwa_phone] tag.
 *
 * Only numbers that match a saved location phone are swapped — unrelated digit
 * runs are left alone. Safe to run twice: a tag has no digits left to match.
 *
 * @param string     $text      Text to rewrite.
 * @param array|null $phone_map Map from ekwa_phone_token_map(); built when null.
 * @return string
 */
function ekwa_phone_replace_in_text( $text, $phone_map = null ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return $text;
	}
	if ( null === $phone_map ) {
		$phone_map = ekwa_phone_token_map();
	}
	if ( empty( $phone_map ) ) {
		return $text;
	}

	// Matches a phone number however it is written: optional country code, an
	// area code that may be wrapped in (), [] or {}, then 3 + 4 digits, with
	// spaces / dots / slashes / hyphens / typographic dashes between them, e.g.
	//   (813) 734-7102   [813] 734 7102   813.734.7102   +1 813-734-7102
	//   8137347102       813–734–7102
	//
	// Kept in step with PHONE_RE in assets/js/ekwa-paste-phone.js, which does the
	// same swap for content pasted into the editor.
	//
	// The lookarounds stop the match starting or ending part-way through a
	// longer run of digits. Nothing depends on them today — a partial match
	// normalises to the wrong length and misses the map lookup below — but
	// they keep that safety in the pattern itself rather than resting it
	// entirely on the lookup.
	$sep     = '[\s.\x{00B7}\x{2010}-\x{2015}\/-]*';
	$pattern = '/(?<!\d)(?:\+?\d{1,3}' . $sep . ')?[(\[{]?\d{3}[)\]}]?' . $sep . '\d{3}' . $sep . '\d{4}(?!\d)/u';

	$replaced = preg_replace_callback(
		$pattern,
		static function ( $matches ) use ( $phone_map ) {
			$digits = ekwa_phone_normalize_digits( $matches[0] );
			return isset( $phone_map[ $digits ] ) ? $phone_map[ $digits ] : $matches[0];
		},
		$text
	);

	// /u makes preg_* return null on a subject that isn't valid UTF-8 — a CSV
	// saved in a Windows codepage would otherwise blank the field outright.
	return null === $replaced ? $text : $replaced;
}

/* ------------------------------------------------------------------
 * Token  ->  number (for places that cannot run a shortcode).
 * ------------------------------------------------------------------ */

/**
 * Resolve [ekwa_phone] tags to the bare number, with no markup.
 *
 * For meta tags and schema, where the shortcode's <a href="tel:…"> markup would
 * be invalid. Deliberately skips the ad-tracking swap ekwa_phone_shortcode()
 * does: a meta description is written for the SERP and gets cached by search
 * engines, so a per-visitor tracking number has no business in it.
 *
 * A tag pointing at a location with no number set resolves to nothing, and the
 * space it leaves behind is tidied up.
 *
 * @param string $text Text that may contain [ekwa_phone] tags.
 * @return string
 */
function ekwa_phone_resolve_tokens( $text ) {
	if ( ! is_string( $text ) || false === stripos( $text, '[ekwa_phone' ) ) {
		return $text;
	}

	$dropped = false;

	$out = preg_replace_callback(
		'/\[ekwa_phone\b([^\]]*)\]/i',
		static function ( $matches ) use ( &$dropped ) {
			$atts = shortcode_parse_atts( $matches[1] );
			if ( ! is_array( $atts ) ) {
				$atts = array();
			}

			$type    = isset( $atts['type'] ) ? strtolower( (string) $atts['type'] ) : 'new';
			$loc_num = isset( $atts['location'] ) ? max( 1, absint( $atts['location'] ) ) : 1;

			$locations = get_option( 'ekwa_locations', array() );
			$loc       = ( is_array( $locations ) && isset( $locations[ $loc_num - 1 ] ) && is_array( $locations[ $loc_num - 1 ] ) )
				? $locations[ $loc_num - 1 ]
				: array();

			$number = ( 'existing' === $type )
				? trim( (string) ( $loc['phone_existing'] ?? '' ) )
				: trim( (string) ( $loc['phone_new'] ?? '' ) );

			if ( '' === $number ) {
				$dropped = true;
			}

			return $number;
		},
		$text
	);

	if ( null === $out ) {
		return $text;
	}

	// Close the gap a dropped tag left mid-sentence.
	if ( $dropped ) {
		$out = trim( preg_replace( '/[ \t]{2,}/', ' ', $out ) );
	}

	return $out;
}

/* ------------------------------------------------------------------
 * Yoast SEO integration.
 * ------------------------------------------------------------------ */

/**
 * Yoast post-meta keys whose text is kept in sync with the location numbers.
 *
 * @return string[]
 */
function ekwa_phone_seo_meta_keys() {
	return array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_opengraph-title',
		'_yoast_wpseo_opengraph-description',
		'_yoast_wpseo_twitter-title',
		'_yoast_wpseo_twitter-description',
	);
}

/**
 * Rewrite a Yoast meta value on the way into the database.
 *
 * Hooked to sanitize_meta() rather than to update_post_metadata, because that
 * one short-circuits the write when a handler returns a value. sanitize_meta()
 * runs first and hands its result on, so Yoast's own filters still see it.
 *
 * @param mixed $value Meta value being saved.
 * @return mixed
 */
function ekwa_phone_sync_seo_meta( $value ) {
	return is_string( $value ) ? ekwa_phone_replace_in_text( $value ) : $value;
}

foreach ( ekwa_phone_seo_meta_keys() as $ekwa_phone_meta_key ) {
	add_filter( "sanitize_post_meta_{$ekwa_phone_meta_key}", 'ekwa_phone_sync_seo_meta' );
}
unset( $ekwa_phone_meta_key );

// Resolve on output. Late priority so Yoast's %%replacement vars%% have already
// run and we only ever see the finished string.
foreach ( array(
	'wpseo_title',
	'wpseo_metadesc',
	'wpseo_opengraph_title',
	'wpseo_opengraph_desc',
	'wpseo_twitter_title',
	'wpseo_twitter_description',
) as $ekwa_phone_out_filter ) {
	add_filter( $ekwa_phone_out_filter, 'ekwa_phone_resolve_tokens', 20 );
}
unset( $ekwa_phone_out_filter );

/**
 * Resolve tokens anywhere in the Yoast schema graph.
 *
 * The graph's descriptions come from the indexable rather than through
 * `wpseo_metadesc`, so they would otherwise ship the raw tag. Walking every
 * string is cheap — ekwa_phone_resolve_tokens() bails on the first strpos.
 *
 * @param array $graph Yoast @graph array.
 * @return array
 */
function ekwa_phone_resolve_schema_graph( $graph ) {
	if ( ! is_array( $graph ) ) {
		return $graph;
	}
	array_walk_recursive(
		$graph,
		static function ( &$value ) {
			if ( is_string( $value ) ) {
				$value = ekwa_phone_resolve_tokens( $value );
			}
		}
	);
	return $graph;
}
// After ekwa_yoast_schema_fallback_image() (priority 11) in inc/ekwa-schema.php.
add_filter( 'wpseo_schema_graph', 'ekwa_phone_resolve_schema_graph', 12 );

/* ------------------------------------------------------------------
 * Block editor.
 * ------------------------------------------------------------------ */

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
