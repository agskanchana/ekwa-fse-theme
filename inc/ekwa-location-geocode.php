<?php
/**
 * Location address extraction from a Google Maps "Direction URL".
 *
 * Ekwa Settings → Locations already has a "Direction URL" field (a Google Maps
 * link business owners paste in). This resolves that link — following short-URL
 * redirects (maps.app.goo.gl, goo.gl/maps) — pulls out either coordinates or a
 * place/address string embedded in it, then geocodes that through OpenStreetMap's
 * Nominatim API to populate street/city/state/zip/latitude/longitude. When the
 * matched OSM place also carries an `opening_hours` tag, that gets parsed into
 * the per-day working-hours rows too — but OSM coverage for that tag is thin, so
 * most lookups still fill the address only. Phone numbers aren't in the data at
 * all, so those stay manual.
 *
 * Nominatim's free tier is for occasional, single-lookup use (not bulk/automated
 * queries) — this endpoint is only ever called one row at a time from an admin
 * clicking "Extract address", which fits that policy. See
 * https://operations.osmfoundation.org/policies/nominatim/
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'ekwa_location_geocode_register_routes' );

/**
 * Register the extraction REST route.
 */
function ekwa_location_geocode_register_routes() {
	register_rest_route( 'ekwa/v1', '/location-geocode', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_location_geocode_handler',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'url' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
		),
	) );
}

/**
 * Handle POST /ekwa/v1/location-geocode.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_location_geocode_handler( $request ) {
	$url = (string) $request->get_param( 'url' );
	if ( '' === $url || ! wp_http_validate_url( $url ) ) {
		return new WP_Error( 'bad_url', __( 'That doesn\'t look like a valid link.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$resolved = ekwa_location_geocode_resolve_redirects( $url );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	$found = ekwa_location_geocode_extract_from_url( $resolved );
	if ( ! $found ) {
		return new WP_Error( 'no_location', __( 'Couldn\'t find coordinates or an address in that link. Try pasting the link from the address bar after opening the pin in Google Maps.', 'ekwa' ), array( 'status' => 422 ) );
	}

	$address = isset( $found['lat'] )
		? ekwa_location_geocode_nominatim_reverse( $found['lat'], $found['lng'] )
		: ekwa_location_geocode_nominatim_search( $found['query'] );
	if ( is_wp_error( $address ) ) {
		return $address;
	}

	return rest_ensure_response( $address );
}

/**
 * Follow a chain of HTTP redirects manually (rather than letting wp_remote_get
 * auto-follow) so short links like maps.app.goo.gl resolve to the full Google
 * Maps URL we can pattern-match, capped well short of a redirect loop.
 *
 * @param string $url
 * @param int    $max_hops
 * @return string|WP_Error Final URL, or the original if it never redirected.
 */
function ekwa_location_geocode_resolve_redirects( $url, $max_hops = 5 ) {
	$current = $url;
	for ( $i = 0; $i < $max_hops; $i++ ) {
		$response = wp_remote_get( $current, array(
			'timeout'     => 8,
			'redirection' => 0,
			'user-agent'  => ekwa_location_geocode_user_agent(),
		) );
		if ( is_wp_error( $response ) ) {
			// A failed hop after at least one successful redirect still leaves us
			// with a usable (more specific) URL from the previous hop.
			return $i > 0 ? $current : $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 300 || $code >= 400 ) {
			return $current;
		}
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( '' === $location ) {
			return $current;
		}
		$current = ( 0 === strpos( $location, 'http' ) ) ? $location : $current;
	}
	return $current;
}

/**
 * User agent identifying this site, per Nominatim's usage policy (generic
 * library user agents get blocked).
 *
 * @return string
 */
function ekwa_location_geocode_user_agent() {
	return 'Ekwa Theme/' . wp_get_theme()->get( 'Version' ) . ' (' . home_url( '/' ) . ')';
}

/**
 * Pull either a lat/lng pair or a place/address text out of a (resolved)
 * Google Maps URL. Coordinates are preferred when both are present — they're
 * the actual dropped pin, while the place text can be a business name that
 * geocodes less precisely.
 *
 * @param string $url
 * @return array{lat?:float,lng?:float,query?:string}|null
 */
function ekwa_location_geocode_extract_from_url( $url ) {
	// The dropped-pin coordinate embedded in the page data blob (most precise).
	if ( preg_match( '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $m ) ) {
		return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
	}
	// The @lat,lng,zoom map-center segment.
	if ( preg_match( '#/@(-?\d+\.\d+),(-?\d+\.\d+)#', $url, $m ) ) {
		return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
	}
	// Directions API: destination as "lat,lng".
	if ( preg_match( '/[?&]destination=(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m ) ) {
		return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
	}
	// /maps/place/{query}/ — URL-encoded place name or address.
	if ( preg_match( '#/maps/place/([^/@]+)#', $url, $m ) ) {
		return array( 'query' => str_replace( '+', ' ', urldecode( $m[1] ) ) );
	}
	// destination= or q= as free text (address/business name).
	if ( preg_match( '/[?&](?:destination|q|query)=([^&]+)/', $url, $m ) ) {
		return array( 'query' => str_replace( '+', ' ', urldecode( $m[1] ) ) );
	}
	return null;
}

/**
 * Reverse-geocode a coordinate through Nominatim.
 *
 * @param float $lat
 * @param float $lng
 * @return array{street:string,city:string,state:string,zip:string,latitude:string,longitude:string,formatted:string}|WP_Error
 */
function ekwa_location_geocode_nominatim_reverse( $lat, $lng ) {
	$url = add_query_arg( array(
		'format'      => 'jsonv2',
		'lat'         => $lat,
		'lon'         => $lng,
		'addressdetails' => 1,
		'extratags'   => 1,
		'zoom'        => 18,
	), 'https://nominatim.openstreetmap.org/reverse' );

	return ekwa_location_geocode_call_nominatim( $url, $lat, $lng );
}

/**
 * Forward-geocode a free-text address/place name through Nominatim.
 *
 * @param string $query
 * @return array|WP_Error
 */
function ekwa_location_geocode_nominatim_search( $query ) {
	$url = add_query_arg( array(
		'format'         => 'jsonv2',
		'q'              => $query,
		'addressdetails' => 1,
		'extratags'      => 1,
		'limit'          => 1,
	), 'https://nominatim.openstreetmap.org/search' );

	return ekwa_location_geocode_call_nominatim( $url );
}

/**
 * Shared Nominatim request + response parsing.
 *
 * @param string     $url
 * @param float|null $fallback_lat Used if the response omits lat (shouldn't happen).
 * @param float|null $fallback_lng
 * @return array{street:string,city:string,state:string,zip:string,latitude:string,longitude:string,formatted:string,working_hours:array}|WP_Error
 */
function ekwa_location_geocode_call_nominatim( $url, $fallback_lat = null, $fallback_lng = null ) {
	$response = wp_remote_get( $url, array(
		'timeout'    => 10,
		'user-agent' => ekwa_location_geocode_user_agent(),
		'headers'    => array( 'Accept-Language' => 'en' ),
	) );
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'geocode_failed', $response->get_error_message(), array( 'status' => 502 ) );
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	// /search returns a JSON array of matches; /reverse returns one object.
	// Theme targets PHP 7.4+, so check list-ness manually (array_is_list is 8.1+).
	if ( is_array( $body ) && isset( $body[0] ) && is_array( $body[0] ) ) {
		$body = $body[0];
	}
	if ( ! is_array( $body ) || empty( $body['address'] ) ) {
		return new WP_Error( 'no_match', __( 'That location couldn\'t be matched to an address.', 'ekwa' ), array( 'status' => 422 ) );
	}

	$addr = $body['address'];

	$street = trim( ( $addr['house_number'] ?? '' ) . ' ' . ( $addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? '' ) );
	$city   = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['hamlet'] ?? $addr['municipality'] ?? '';
	$state  = $addr['state'] ?? $addr['state_district'] ?? '';
	$zip    = $addr['postcode'] ?? '';

	// Working hours only exist when the matched OSM place happens to carry an
	// `opening_hours` tag (requested via extratags=1). Usually absent — an empty
	// array signals the client to leave any manually-entered hours untouched.
	$working_hours = array();
	if ( ! empty( $body['extratags']['opening_hours'] ) ) {
		$working_hours = ekwa_location_geocode_parse_opening_hours( $body['extratags']['opening_hours'] );
	}

	return array(
		'street'        => $street,
		'city'          => (string) $city,
		'state'         => (string) $state,
		'zip'           => (string) $zip,
		'latitude'      => (string) ( $body['lat'] ?? $fallback_lat ?? '' ),
		'longitude'     => (string) ( $body['lon'] ?? $fallback_lng ?? '' ),
		'formatted'     => (string) ( $body['display_name'] ?? '' ),
		'working_hours' => $working_hours,
	);
}

/**
 * Convert an OSM `opening_hours` string into the theme's per-day working-hours
 * rows. Only the common subset is handled — weekday singles/ranges/lists with
 * one or more `HH:MM-HH:MM` windows, plus `off`/`closed` and `24/7`; anything
 * more exotic (month ranges, holidays, dawn/dusk, week numbers) is skipped
 * rather than guessed at. Returns an empty array when nothing usable is found,
 * so the caller never wipes existing manual hours over a value it can't read.
 *
 * @param string $osm e.g. "Mo-Fr 09:00-17:00; Sa 09:00-13:00; Su off".
 * @return array<int,array<string,mixed>> Rows in ekwa_render_working_hour_row shape.
 */
function ekwa_location_geocode_parse_opening_hours( $osm ) {
	$osm = trim( (string) $osm );
	if ( '' === $osm ) {
		return array();
	}

	// slug => full day name, in the Monday-first order the UI lists.
	$day_names = array(
		'mo' => 'Monday',
		'tu' => 'Tuesday',
		'we' => 'Wednesday',
		'th' => 'Thursday',
		'fr' => 'Friday',
		'sa' => 'Saturday',
		'su' => 'Sunday',
	);
	$slugs     = array_keys( $day_names );
	$day_index = array_flip( $slugs );

	// "24/7" is shorthand for every day, all day.
	if ( '24/7' === $osm ) {
		$osm = 'Mo-Su 00:00-24:00';
	}

	$by_day = array(); // slug => list of row fragments (open/close parts or a closed flag).

	foreach ( explode( ';', $osm ) as $rule ) {
		// Drop any quoted free-text comment, then normalise.
		$rule = strtolower( trim( preg_replace( '/"[^"]*"/', '', (string) $rule ) ) );
		if ( '' === $rule ) {
			continue;
		}

		$days   = ekwa_location_geocode_expand_days( $rule, $day_index, $slugs );
		$is_off = ( false !== strpos( $rule, 'off' ) || false !== strpos( $rule, 'closed' ) );
		preg_match_all( '/(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/', $rule, $windows, PREG_SET_ORDER );

		// Public/school-holiday clauses (PH/SH) name no weekday — skip them rather
		// than letting the "no day tokens → every day" fallback apply them weekwide.
		if ( empty( $days ) && preg_match( '/\b(?:ph|sh)\b/', $rule ) ) {
			continue;
		}

		// A rule with no day tokens applies to every day (only if it says something).
		if ( empty( $days ) ) {
			if ( empty( $windows ) && ! $is_off ) {
				continue;
			}
			$days = $slugs;
		}

		if ( empty( $windows ) ) {
			if ( $is_off ) {
				foreach ( $days as $d ) {
					$by_day[ $d ][] = array( 'closed' => 1 );
				}
			}
			continue;
		}

		foreach ( $days as $d ) {
			foreach ( $windows as $w ) {
				$open  = ekwa_location_geocode_time_to_parts( (int) $w[1], (int) $w[2] );
				$close = ekwa_location_geocode_time_to_parts( (int) $w[3], (int) $w[4] );
				if ( null === $open || null === $close ) {
					continue;
				}
				$by_day[ $d ][] = array( 'open' => $open, 'close' => $close );
			}
		}
	}

	if ( empty( $by_day ) ) {
		return array();
	}

	// Flatten to a flat list in Monday..Sunday order (split shifts become extra rows).
	$rows = array();
	foreach ( $day_names as $slug => $full ) {
		if ( empty( $by_day[ $slug ] ) ) {
			continue;
		}
		foreach ( $by_day[ $slug ] as $frag ) {
			if ( ! empty( $frag['closed'] ) ) {
				$rows[] = array( 'day' => $full, 'closed' => 1 );
				continue;
			}
			$rows[] = array(
				'day'          => $full,
				'open_hour'    => $frag['open']['hour'],
				'open_min'     => $frag['open']['min'],
				'open_period'  => $frag['open']['period'],
				'close_hour'   => $frag['close']['hour'],
				'close_min'    => $frag['close']['min'],
				'close_period' => $frag['close']['period'],
				'closed'       => 0,
			);
		}
	}

	return $rows;
}

/**
 * Expand the day tokens in one opening_hours rule into a list of day slugs,
 * handling single days (`mo`), ranges (`mo-fr`), lists (`mo,we,fr`) and
 * week-wrapping ranges (`sa-mo`). Non-day tokens (times, `off`, `ph`) are
 * ignored by the regex.
 *
 * @param string             $rule      Lower-cased rule text.
 * @param array<string,int>  $day_index slug => 0..6.
 * @param array<int,string>  $slugs     0..6 => slug.
 * @return array<int,string> Unique day slugs, in first-seen order.
 */
function ekwa_location_geocode_expand_days( $rule, $day_index, $slugs ) {
	if ( ! preg_match_all( '/(mo|tu|we|th|fr|sa|su)(?:\s*-\s*(mo|tu|we|th|fr|sa|su))?/', $rule, $matches, PREG_SET_ORDER ) ) {
		return array();
	}
	$result = array();
	foreach ( $matches as $m ) {
		$start = $day_index[ $m[1] ];
		if ( isset( $m[2] ) && '' !== $m[2] ) {
			$i = $start;
			while ( true ) {
				$result[] = $slugs[ $i ];
				if ( $i === $day_index[ $m[2] ] ) {
					break;
				}
				$i = ( $i + 1 ) % 7;
			}
		} else {
			$result[] = $slugs[ $start ];
		}
	}
	return array_values( array_unique( $result ) );
}

/**
 * Convert a 24-hour `H:M` time into the theme's 12-hour select values, snapping
 * minutes to the 15-minute steps the UI offers (00/15/30/45) so the parsed value
 * is always selectable. `24:00` (and `00:00`) map to 12:00 AM.
 *
 * @param int $hour 0..24.
 * @param int $min  0..59.
 * @return array{hour:string,min:string,period:string}|null Null if out of range.
 */
function ekwa_location_geocode_time_to_parts( $hour, $min ) {
	if ( $hour < 0 || $hour > 24 || $min < 0 || $min > 59 ) {
		return null;
	}
	// Snap to the nearest quarter hour, carrying minutes into the hour if needed.
	$total = (int) ( round( ( $hour * 60 + $min ) / 15 ) * 15 );
	$hour  = intdiv( $total, 60 ) % 24;
	$min   = $total % 60;

	$period = ( $hour >= 12 ) ? 'PM' : 'AM';
	$h12    = $hour % 12;
	if ( 0 === $h12 ) {
		$h12 = 12;
	}

	return array(
		'hour'   => sprintf( '%02d', $h12 ),
		'min'    => sprintf( '%02d', $min ),
		'period' => $period,
	);
}
