<?php
/**
 * Location address extraction from a Google Maps "Direction URL".
 *
 * Ekwa Settings → Locations already has a "Direction URL" field (a Google Maps
 * link business owners paste in). This resolves that link — following short-URL
 * redirects (maps.app.goo.gl, goo.gl/maps) — pulls out either coordinates or a
 * place/address string embedded in it, then geocodes that through OpenStreetMap's
 * Nominatim API to populate street/city/state/zip/latitude/longitude. Phone
 * numbers and working hours aren't in the link at all, so those stay manual.
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
 * @return array|WP_Error
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

	return array(
		'street'    => $street,
		'city'      => (string) $city,
		'state'     => (string) $state,
		'zip'       => (string) $zip,
		'latitude'  => (string) ( $body['lat'] ?? $fallback_lat ?? '' ),
		'longitude' => (string) ( $body['lon'] ?? $fallback_lng ?? '' ),
		'formatted' => (string) ( $body['display_name'] ?? '' ),
	);
}
