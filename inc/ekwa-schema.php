<?php
/**
 * Schema.org JSON-LD output (Organization / LocalBusiness).
 *
 * Reads the data saved by inc/ekwa-settings.php and prints a JSON-LD
 * <script> block on wp_head. Mirrors the structure used by the previous
 * (classic) theme's schema.php so SEO behavior carries over.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map the saved country name to its ISO 3166-1 alpha-2 code.
 *
 * Returns '' for unknown / online-only / custom values so callers can
 * skip the addressCountry property when there's no canonical code.
 */
function ekwa_schema_country_code() {
	$map = array(
		'United States' => 'US',
		'Canada'        => 'CA',
		'Australia'     => 'AU',
		'England'       => 'GB',
	);
	$country = get_option( 'ekwa_country', '' );
	return isset( $map[ $country ] ) ? $map[ $country ] : '';
}

/**
 * Convert a 12-hour AM/PM time to "HH:MM" 24-hour format for schema.org.
 */
function ekwa_schema_format_time( $hour, $min, $period ) {
	$h = (int) $hour;
	$period = strtoupper( (string) $period );
	if ( 'PM' === $period && 12 !== $h ) {
		$h += 12;
	} elseif ( 'AM' === $period && 12 === $h ) {
		$h = 0;
	}
	return sprintf( '%02d:%02d', $h, (int) $min );
}

/**
 * Build OpeningHoursSpecification entries from a location's working_hours array.
 */
function ekwa_schema_opening_hours( $working_hours ) {
	$out = array();
	if ( ! is_array( $working_hours ) ) {
		return $out;
	}
	foreach ( $working_hours as $wh ) {
		if ( ! empty( $wh['closed'] ) ) {
			continue;
		}
		$day = isset( $wh['day'] ) ? $wh['day'] : '';
		if ( '' === $day ) {
			continue;
		}
		$out[] = array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => $day,
			'opens'     => ekwa_schema_format_time(
				$wh['open_hour']   ?? '09',
				$wh['open_min']    ?? '00',
				$wh['open_period'] ?? 'AM'
			),
			'closes'    => ekwa_schema_format_time(
				$wh['close_hour']   ?? '05',
				$wh['close_min']    ?? '00',
				$wh['close_period'] ?? 'PM'
			),
		);
	}
	return $out;
}

/**
 * Build the address node for a location, optionally including addressCountry.
 */
function ekwa_schema_build_address( $loc, $country_code ) {
	$address = array(
		'@type'           => 'PostalAddress',
		'addressLocality' => $loc['city']   ?? '',
		'addressRegion'   => $loc['state']  ?? '',
		'postalCode'      => $loc['zip']    ?? '',
		'streetAddress'   => $loc['street'] ?? '',
	);
	if ( $country_code ) {
		$address['addressCountry'] = $country_code;
	}
	return $address;
}

/**
 * Build the geo node for a location, or null if no coordinates are set.
 */
function ekwa_schema_build_geo( $loc ) {
	$lat = isset( $loc['latitude'] )  ? trim( (string) $loc['latitude'] )  : '';
	$lng = isset( $loc['longitude'] ) ? trim( (string) $loc['longitude'] ) : '';
	if ( '' === $lat && '' === $lng ) {
		return null;
	}
	return array(
		'@type'     => 'GeoCoordinates',
		'latitude'  => $lat,
		'longitude' => $lng,
	);
}

/**
 * Render the Organization / LocalBusiness JSON-LD block on wp_head.
 */
function ekwa_render_schema() {
	$locations    = get_option( 'ekwa_locations', array() );
	if ( ! is_array( $locations ) ) {
		$locations = array();
	}
	$org_type     = get_option( 'ekwa_organization_type', '' );
	$practice     = get_option( 'ekwa_practice_name', '' );
	$email        = get_option( 'ekwa_email', '' );
	$country_code = ekwa_schema_country_code();

	$logo_id  = (int) get_theme_mod( 'custom_logo', 0 );
	$logo_src = $logo_id ? wp_get_attachment_image_src( $logo_id, 'full' ) : false;
	$logo_url = $logo_src ? $logo_src[0] : '';

	$social  = get_option( 'ekwa_social', array() );
	if ( ! is_array( $social ) ) {
		$social = array();
	}

	$loc_count = count( $locations );

	$schema = array(
		'@context' => 'http://schema.org',
		'@type'    => $loc_count > 1 ? 'Organization' : ( $org_type ? $org_type : 'Organization' ),
		'url'      => get_option( 'siteurl' ),
	);

	if ( $logo_url ) {
		$schema['logo']  = $logo_url;
		$schema['image'] = $logo_url;
	}

	$schema['name'] = $practice;

	if ( 1 === $loc_count ) {
		$loc = $locations[0];

		$schema['priceRange'] = 0;

		if ( ! empty( $loc['direction'] ) ) {
			$schema['hasMap'] = $loc['direction'];
		}

		$schema['address']   = ekwa_schema_build_address( $loc, $country_code );
		$schema['telephone'] = $loc['phone_new'] ?? '';

		$hours = ekwa_schema_opening_hours( $loc['working_hours'] ?? array() );
		if ( ! empty( $hours ) ) {
			$schema['openingHoursSpecification'] = $hours;
		}

		$geo = ekwa_schema_build_geo( $loc );
		if ( $geo ) {
			$schema['geo'] = $geo;
		}
	} elseif ( $loc_count > 1 ) {
		$departments    = array();
		$dept_type      = $org_type ? $org_type : 'Organization';
		foreach ( $locations as $loc ) {
			$dept = array(
				'@type'   => $dept_type,
				'address' => ekwa_schema_build_address( $loc, $country_code ),
			);

			$hours = ekwa_schema_opening_hours( $loc['working_hours'] ?? array() );
			if ( ! empty( $hours ) ) {
				$dept['openingHoursSpecification'] = $hours;
			}

			$dept['telephone'] = $loc['phone_new'] ?? '';
			$dept['name']      = $practice;

			if ( $logo_url ) {
				$dept['image'] = $logo_url;
			}

			$dept['priceRange'] = 0;

			if ( ! empty( $loc['direction'] ) ) {
				$dept['hasMap'] = $loc['direction'];
			}

			$geo = ekwa_schema_build_geo( $loc );
			if ( $geo ) {
				$dept['geo'] = $geo;
			}

			$departments[] = $dept;
		}
		$schema['department'] = $departments;
	}

	if ( $email ) {
		$schema['email'] = $email;
	}

	$same_as = array();
	foreach ( $social as $item ) {
		if ( ! empty( $item['link'] ) ) {
			$same_as[] = $item['link'];
		}
	}
	if ( ! empty( $same_as ) ) {
		$schema['sameAs'] = $same_as;
	}

	/**
	 * Allow themes/plugins to modify the schema array before output.
	 *
	 * @param array $schema Assembled JSON-LD data.
	 */
	$schema = apply_filters( 'ekwa_schema_data', $schema );

	$json = wp_json_encode(
		$schema,
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT
	);

	if ( ! $json ) {
		return;
	}

	echo "\n<script type=\"application/ld+json\">\n" . $json . "\n</script>\n";
}
add_action( 'wp_head', 'ekwa_render_schema' );

/**
 * Yoast schema: fall back to the Branding → Share Image when a page has no
 * Featured Image.
 *
 * Yoast builds the Article/WebPage `image` (its `#primaryimage` ImageObject)
 * from the page's Featured Image. Pages without one omit `image` entirely,
 * which trips Google's "Missing field image (optional)" warning. When that
 * happens we synthesise the same `#primaryimage` node Yoast would have built —
 * sourced from the theme's Share Image option — and wire it into the Article
 * and WebPage pieces so the graph matches a page that does have a featured image.
 *
 * No-ops when Yoast is inactive (the filter never fires), when the page already
 * has a featured image, or when no Share Image is configured.
 *
 * @param array $graph   The Yoast `@graph` array.
 * @param mixed $context Yoast Meta_Tags_Context (object) — used for the canonical URL.
 * @return array
 */
function ekwa_yoast_schema_fallback_image( $graph, $context = null ) {
	if ( ! is_array( $graph ) || ! is_singular() ) {
		return $graph;
	}

	// If the page has its own featured image, Yoast already populated the
	// primary image — leave the graph untouched.
	$post_id = get_queried_object_id();
	if ( $post_id && has_post_thumbnail( $post_id ) ) {
		return $graph;
	}

	$share_id = (int) get_option( 'ekwa_share_image', 0 );
	if ( ! $share_id ) {
		return $graph;
	}

	$src = wp_get_attachment_image_src( $share_id, 'full' );
	if ( ! is_array( $src ) || empty( $src[0] ) ) {
		return $graph;
	}
	$url    = $src[0];
	$width  = isset( $src[1] ) ? (int) $src[1] : 0;
	$height = isset( $src[2] ) ? (int) $src[2] : 0;

	// Stable @id matching Yoast's own scheme: {canonical}#primaryimage.
	$permalink = '';
	if ( is_object( $context ) ) {
		if ( ! empty( $context->canonical ) ) {
			$permalink = (string) $context->canonical;
		} elseif ( ! empty( $context->permalink ) ) {
			$permalink = (string) $context->permalink;
		}
	}
	if ( '' === $permalink ) {
		$permalink = (string) get_permalink( $post_id );
	}
	if ( '' === $permalink ) {
		return $graph;
	}
	$image_ref = $permalink . '#primaryimage';

	// Don't add a second image node if one with this @id already exists.
	$has_node = false;
	foreach ( $graph as $piece ) {
		if ( isset( $piece['@id'] ) && $piece['@id'] === $image_ref ) {
			$has_node = true;
			break;
		}
	}
	if ( ! $has_node ) {
		$node = array(
			'@type'      => 'ImageObject',
			'inLanguage' => get_bloginfo( 'language' ),
			'@id'        => $image_ref,
			'url'        => $url,
			'contentUrl' => $url,
		);
		if ( $width ) {
			$node['width'] = $width;
		}
		if ( $height ) {
			$node['height'] = $height;
		}
		$graph[] = $node;
	}

	// Wire the reference into the Article and WebPage pieces (only where absent).
	foreach ( $graph as &$piece ) {
		if ( empty( $piece['@type'] ) ) {
			continue;
		}
		$types = is_array( $piece['@type'] ) ? $piece['@type'] : array( $piece['@type'] );

		if ( in_array( 'WebPage', $types, true ) ) {
			if ( empty( $piece['primaryImageOfPage'] ) ) {
				$piece['primaryImageOfPage'] = array( '@id' => $image_ref );
			}
			if ( empty( $piece['image'] ) ) {
				$piece['image'] = array( '@id' => $image_ref );
			}
			if ( empty( $piece['thumbnailUrl'] ) ) {
				$piece['thumbnailUrl'] = $url;
			}
		}

		if ( in_array( 'Article', $types, true ) ) {
			if ( empty( $piece['image'] ) ) {
				$piece['image'] = array( '@id' => $image_ref );
			}
			if ( empty( $piece['thumbnailUrl'] ) ) {
				$piece['thumbnailUrl'] = $url;
			}
		}
	}
	unset( $piece );

	return $graph;
}
add_filter( 'wpseo_schema_graph', 'ekwa_yoast_schema_fallback_image', 11, 2 );
