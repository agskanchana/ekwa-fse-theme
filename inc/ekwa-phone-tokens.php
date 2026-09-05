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
 * The regex that recognises a phone number however it is written.
 *
 * Optional country code, an area code that may be wrapped in (), [] or {},
 * then 3 + 4 digits, with spaces / dots / slashes / hyphens / typographic
 * dashes between them, e.g.
 *   (813) 734-7102   [813] 734 7102   813.734.7102   +1 813-734-7102
 *   8137347102       813–734–7102
 *
 * Kept in step with PHONE_RE in assets/js/ekwa-paste-phone.js, which does the
 * same swap for content pasted into the editor.
 *
 * The lookarounds stop the match starting or ending part-way through a longer
 * run of digits. Nothing depends on them today — a partial match normalises to
 * the wrong length and misses the map lookup — but they keep that safety in the
 * pattern itself rather than resting it entirely on the lookup.
 *
 * Lives in one function so the swap below and the "which numbers are NOT in
 * settings" report used by the content importer can never drift apart.
 *
 * @return string PCRE pattern with the /u modifier.
 */
function ekwa_phone_pattern() {
	$sep = '[\s.\x{00B7}\x{2010}-\x{2015}\/-]*';

	return '/(?<!\d)(?:\+?\d{1,3}' . $sep . ')?[(\[{]?\d{3}[)\]}]?' . $sep . '\d{3}' . $sep . '\d{4}(?!\d)/u';
}

/**
 * Find phone numbers in a text blob that Ekwa Settings does NOT know about.
 *
 * The importer converts configured numbers to [ekwa_phone] and deliberately
 * leaves everything else as literal text — a referral office, a lab, a fax.
 * That silence is the bug: the author cannot tell "left alone on purpose" from
 * "missed". This returns the leftovers so they can be surfaced for a decision.
 *
 * @param string     $text      Text to scan.
 * @param array|null $phone_map Map from ekwa_phone_token_map(); built when null.
 * @return string[] Unconfigured numbers as written, de-duplicated by digits.
 */
function ekwa_phone_find_unconfigured( $text, $phone_map = null ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return array();
	}
	if ( null === $phone_map ) {
		$phone_map = ekwa_phone_token_map();
	}

	if ( ! preg_match_all( ekwa_phone_pattern(), $text, $matches ) ) {
		return array();
	}

	$found = array();
	foreach ( (array) $matches[0] as $raw ) {
		$digits = ekwa_phone_normalize_digits( $raw );
		if ( '' === $digits || isset( $phone_map[ $digits ] ) || isset( $found[ $digits ] ) ) {
			continue;
		}
		$found[ $digits ] = trim( $raw );
	}

	return array_values( $found );
}

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

	$pattern = ekwa_phone_pattern();

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

/**
 * Find phone numbers in a text blob that Ekwa Settings DOES know about.
 *
 * The mirror of ekwa_phone_find_unconfigured(), for the places a configured
 * number was recognised but deliberately left as literal text — inside a link,
 * where the shortcode's own `<a href="tel:…">` could not go. Reporting those is
 * the difference between "left alone on purpose" and "quietly went stale".
 *
 * @param string     $text      Text to scan.
 * @param array|null $phone_map Map from ekwa_phone_token_map(); built when null.
 * @return string[] Configured numbers as written, de-duplicated by digits.
 */
function ekwa_phone_find_configured( $text, $phone_map = null ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return array();
	}
	if ( null === $phone_map ) {
		$phone_map = ekwa_phone_token_map();
	}
	if ( empty( $phone_map ) || ! preg_match_all( ekwa_phone_pattern(), $text, $matches ) ) {
		return array();
	}

	$found = array();
	foreach ( (array) $matches[0] as $raw ) {
		$digits = ekwa_phone_normalize_digits( $raw );
		if ( '' === $digits || ! isset( $phone_map[ $digits ] ) || isset( $found[ $digits ] ) ) {
			continue;
		}
		$found[ $digits ] = trim( $raw );
	}

	return array_values( $found );
}

/**
 * Replace configured phone numbers in an HTML fragment, tag-safely.
 *
 * Two passes, the same shape as ekwa_import_rewrite_phones():
 *
 *  1. A whole `<a href="tel:…">…</a>` becomes the shortcode. [ekwa_phone]
 *     renders its own `<a href="tel:…">`, so replacing the element keeps the
 *     label and the dialled number in step; rewriting only the label would
 *     leave a live shortcode inside a frozen href, which is worse than not
 *     converting at all.
 *  2. Numbers written as plain text are swapped where they sit — but never
 *     inside a tag, because an attribute is not prose, and never inside an
 *     `<a>` that pass 1 left behind, because the shortcode emits an anchor of
 *     its own and an `<a>` inside an `<a>` is invalid HTML. Numbers skipped for
 *     that reason are collected in $blocked so the caller can say so.
 *
 * @param string     $html      HTML fragment.
 * @param array|null $phone_map Map from ekwa_phone_token_map(); built when null.
 * @param array      $blocked   By reference; numbers left as text inside a link.
 * @return string
 */
function ekwa_phone_replace_in_html( $html, $phone_map = null, &$blocked = array() ) {
	if ( ! is_string( $html ) || '' === $html ) {
		return $html;
	}
	if ( null === $phone_map ) {
		$phone_map = ekwa_phone_token_map();
	}
	if ( empty( $phone_map ) ) {
		return $html;
	}

	// ── Pass 1: whole tel: anchors ─────────────────────────────────
	$out = preg_replace_callback(
		'#<a\b[^>]*\bhref\s*=\s*(["\'])\s*tel:([^"\']*)\1[^>]*>(.*?)</a>#is',
		static function ( $m ) use ( $phone_map ) {
			// The href is the authoritative number; fall back to the label only
			// when the href carries no digits at all.
			$digits = ekwa_phone_normalize_digits( $m[2] );
			if ( '' === $digits ) {
				$digits = ekwa_phone_normalize_digits( wp_strip_all_tags( $m[3] ) );
			}
			return ( '' !== $digits && isset( $phone_map[ $digits ] ) ) ? $phone_map[ $digits ] : $m[0];
		},
		$html
	);
	if ( null !== $out ) {
		$html = $out;
	}

	// ── Pass 2: numbers written as plain text ──────────────────────
	$parts = preg_split( '/(<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $parts ) ) {
		return $html;
	}

	$link_depth = 0;

	foreach ( $parts as $i => $part ) {
		// With one capture group, the odd indices are the tags themselves.
		if ( 1 === $i % 2 ) {
			if ( preg_match( '/^<a[\s>]/i', $part ) ) {
				$link_depth++;
			} elseif ( preg_match( '#^</a\s*>$#i', $part ) ) {
				$link_depth = max( 0, $link_depth - 1 );
			}
			continue;
		}

		if ( '' === trim( $part ) ) {
			continue;
		}

		if ( $link_depth > 0 ) {
			foreach ( ekwa_phone_find_configured( $part, $phone_map ) as $raw ) {
				$blocked[] = $raw;
			}
			continue;
		}

		$parts[ $i ] = ekwa_phone_replace_in_text( $part, $phone_map );
	}

	return implode( '', $parts );
}

/* ------------------------------------------------------------------
 * Generated block markup.
 * ------------------------------------------------------------------ */

/**
 * Swap configured phone numbers for [ekwa_phone] throughout block markup.
 *
 * For AI-generated content. A number the model types into a paragraph is frozen
 * the moment it is inserted: it misses the next change in Ekwa Settings →
 * Locations and it misses the ad-tracking swap [ekwa_phone] performs at render
 * time. This is the same swap the paste handler and the importer already do,
 * applied to generated output.
 *
 * ONLY FOR CONTENT THAT RENDERS THROUGH `the_content`. That is where WordPress
 * runs shortcodes — do_blocks() at priority 9, do_shortcode() at 11. A block in
 * an FSE template part gets do_blocks() and nothing else, so a shortcode there
 * would print as literal text. Callers must not run this over header or footer
 * markup.
 *
 * Left alone deliberately:
 *  - ekwa/phone and ekwa/phone-dropdown, which already render the number from
 *    Settings — the user's "if its the phone block its okey";
 *  - anything inside a link (ekwa/link, ekwa/button, an ekwa/div with
 *    tagName "a", or an `<a>` in saved HTML), because the shortcode emits its
 *    own anchor. Those numbers are reported instead, so a hard-coded "Call
 *    (555) 123-4567" button is something the author is told about rather than
 *    something that silently goes stale.
 *
 * @param string $markup Block-comment markup.
 * @param array  $report By reference: converted (int), blocked (string[]).
 * @return string Markup, unchanged byte-for-byte when nothing was swapped.
 */
function ekwa_phone_replace_in_blocks( $markup, &$report = array() ) {
	$report = array( 'converted' => 0, 'blocked' => array() );

	if ( ! is_string( $markup ) || '' === trim( $markup ) ) {
		return $markup;
	}

	$phone_map = ekwa_phone_token_map();
	if ( empty( $phone_map ) ) {
		return $markup;
	}

	$changed   = false;
	$converted = 0;
	$blocked   = array();

	$walk = static function ( $blocks, $in_link ) use ( &$walk, $phone_map, &$changed, &$converted, &$blocked ) {
		foreach ( $blocks as $i => $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

			// Already dynamic: its number comes from Settings at render time.
			if ( 'ekwa/phone' === $name || 'ekwa/phone-dropdown' === $name ) {
				continue;
			}

			// An anchor — here, or anywhere above us in the tree.
			$here = $in_link
				|| in_array( $name, array( 'ekwa/link', 'ekwa/button' ), true )
				|| ( 'ekwa/div' === $name && 'a' === strtolower( (string) ( $block['attrs']['tagName'] ?? '' ) ) );

			// The one text attribute worth rewriting: ekwa/text renders a bare
			// inline element, so a shortcode inside it is safe. Every other
			// block that stores its text as an attribute is an anchor.
			if ( ! $here && 'ekwa/text' === $name && isset( $block['attrs']['text'] ) && is_string( $block['attrs']['text'] ) ) {
				$swapped = ekwa_phone_replace_in_text( $block['attrs']['text'], $phone_map );
				if ( $swapped !== $block['attrs']['text'] ) {
					$converted             += substr_count( $swapped, '[ekwa_phone' ) - substr_count( $block['attrs']['text'], '[ekwa_phone' );
					$block['attrs']['text'] = $swapped;
					$changed                = true;
				}
			} elseif ( $here ) {
				foreach ( array( 'text', 'url', 'href' ) as $key ) {
					if ( isset( $block['attrs'][ $key ] ) && is_string( $block['attrs'][ $key ] ) ) {
						foreach ( ekwa_phone_find_configured( $block['attrs'][ $key ], $phone_map ) as $raw ) {
							$blocked[] = $raw;
						}
					}
				}
			}

			// Static blocks render their saved HTML, and serialize_blocks()
			// rebuilds from innerContent — so that is the copy that has to
			// change. Inner-block positions are null and are skipped.
			if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				foreach ( $block['innerContent'] as $c => $chunk ) {
					if ( ! is_string( $chunk ) || '' === trim( $chunk ) ) {
						continue;
					}
					if ( $here ) {
						foreach ( ekwa_phone_find_configured( $chunk, $phone_map ) as $raw ) {
							$blocked[] = $raw;
						}
						continue;
					}
					$swapped = ekwa_phone_replace_in_html( $chunk, $phone_map, $blocked );
					if ( $swapped !== $chunk ) {
						$converted                  += substr_count( $swapped, '[ekwa_phone' ) - substr_count( $chunk, '[ekwa_phone' );
						$block['innerContent'][ $c ] = $swapped;
						$changed                     = true;
					}
				}
				$block['innerHTML'] = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $walk( $block['innerBlocks'], $here );
			}

			$blocks[ $i ] = $block;
		}

		return $blocks;
	};

	$blocks = $walk( parse_blocks( $markup ), false );

	// De-duplicate the report by digits — the same number in a link on three
	// cards is one thing to tell the author about, not three.
	$unique = array();
	foreach ( $blocked as $raw ) {
		$digits = ekwa_phone_normalize_digits( $raw );
		if ( '' !== $digits && ! isset( $unique[ $digits ] ) ) {
			$unique[ $digits ] = $raw;
		}
	}

	$report['converted'] = max( 0, $converted );
	$report['blocked']   = array_values( $unique );

	// Nothing swapped — hand back the original bytes rather than a re-serialized
	// copy of them.
	return $changed ? serialize_blocks( $blocks ) : $markup;
}

/**
 * The site's phone numbers as AI-prompt context.
 *
 * Without this the model has no way to know that a number belongs to this
 * practice, so it writes whatever number it was given as literal text. Naming
 * them alongside the tag that renders them lets the generator get it right on
 * the way out, instead of relying on ekwa_phone_replace_in_blocks() to repair
 * it afterwards.
 *
 * @return string Prompt fragment, or '' when no location has a number.
 */
function ekwa_phone_ai_context() {
	$locations = get_option( 'ekwa_locations', array() );
	if ( ! is_array( $locations ) || ! $locations ) {
		return '';
	}

	$lines = array();

	foreach ( $locations as $i => $loc ) {
		if ( ! is_array( $loc ) ) {
			continue;
		}
		$loc_num = $i + 1;
		$where   = trim( (string) ( $loc['city'] ?? '' ) );
		$where   = '' !== $where ? ' (' . $where . ')' : '';

		foreach ( array( 'new' => 'phone_new', 'existing' => 'phone_existing' ) as $type => $key ) {
			$number = trim( (string) ( $loc[ $key ] ?? '' ) );
			if ( '' === $number || strlen( ekwa_phone_normalize_digits( $number ) ) < 7 ) {
				continue;
			}
			$lines[] = sprintf(
				'%s — location %d%s, %s patients → %s',
				$number,
				$loc_num,
				$where,
				$type,
				ekwa_phone_shortcode_tag( $type, $loc_num )
			);
		}
	}

	if ( ! $lines ) {
		return '';
	}

	return "\n\nSITE PHONE NUMBERS — these belong to the practice and are stored in Ekwa Settings → Locations:\n"
		. implode( "\n", $lines ) . "\n"
		. "NEVER type one of these numbers as literal text. A typed number is frozen: it does not follow a change in Settings and it skips the call-tracking swap.\n"
		. "- In prose (a paragraph, a heading, a list item), write the SHORTCODE shown above in place of the number — e.g. \"Call [ekwa_phone] to book\". It renders the number as a tel: link.\n"
		. "- For a number on its own — a header bar, a CTA panel, a footer column — use the ekwa/phone block instead.\n"
		. "- NEVER put the shortcode inside a link or a button: it renders its own <a href=\"tel:…\">, and an <a> inside an <a> is invalid. For a call button use ekwa/phone (it is already a link) rather than ekwa/button with a tel: URL.\n"
		. "- A number that is NOT in the list above belongs to someone else (a referral office, a lab, a fax) — leave it as plain text.\n";
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
