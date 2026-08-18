<?php
/**
 * Schema.org JSON-LD output (Organization / LocalBusiness).
 *
 * The JSON-LD is no longer assembled in PHP. Instead an editable *template*
 * (Appearance → Schema Editor, option `ekwa_schema_template`) is rendered
 * against a context built from the same settings the hard-coded version used
 * — so the markup is authorable per site while the data still has exactly one
 * source of truth: inc/ekwa-settings.php.
 *
 * Template syntax (see ekwa_schema_render_template()):
 *   {{tag}}                       scalar, substituted inside a JSON string
 *   "{{tag}}"                     structural — the *quoted* tag is replaced by
 *                                 a whole JSON array/object, so the template
 *                                 itself stays valid JSON
 *   {{#each collection}}…{{/each}} repeat, with {{@first}}/{{@last}}/{{@index}}
 *   {{#if tag}}…{{/if}}           conditional
 *   {{#unless tag}}…{{/unless}}   negated conditional
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
 * The template shipped with the theme.
 *
 * Reproduces the output the hard-coded builder used to emit — with the three
 * corrections that prompted the rewrite: `telephone` falls back to the
 * existing-patient line, `priceRange` is a real price band instead of `0`, and
 * the node carries an `@id`. Both branches (single location vs. department
 * list) are expressed with template syntax so the file doubles as a worked
 * example of what the editor accepts.
 *
 * @return string
 */
function ekwa_schema_default_template() {
	return <<<'TPL'
{
    "@context": "https://schema.org",
    "@type": "{{schema_type}}",
    "@id": "{{schema_id}}",
    "name": "{{practice_name}}",
    "url": "{{site_url}}",
    "logo": "{{logo_url}}",
    "image": "{{image_url}}",
    "email": "{{email}}",
    "priceRange": "$$",
{{#unless is_multi_location}}
    "telephone": "{{phone}}",
    "hasMap": "{{direction}}",
    "address": "{{address}}",
    "geo": "{{geo}}",
    "openingHoursSpecification": "{{opening_hours}}",
{{/unless}}
{{#if is_multi_location}}
    "department": [
{{#each locations}}
        {
            "@type": "{{org_type}}",
            "name": "{{practice_name}}",
            "image": "{{image_url}}",
            "priceRange": "$$",
            "telephone": "{{phone}}",
            "hasMap": "{{direction}}",
            "address": "{{address}}",
            "geo": "{{geo}}",
            "openingHoursSpecification": "{{opening_hours}}"
        }{{#unless @last}},{{/unless}}
{{/each}}
    ],
{{/if}}
    "founder": "{{founder}}",
    "sameAs": "{{social_links}}"
}
TPL;
}

/**
 * The active template: the saved one, or the default when nothing is saved.
 *
 * @return string
 */
function ekwa_schema_get_template() {
	$saved = get_option( 'ekwa_schema_template', '' );
	return ( is_string( $saved ) && '' !== trim( $saved ) ) ? $saved : ekwa_schema_default_template();
}

/**
 * Every tag a template can use, grouped for the editor's reference panel.
 *
 * @return array<string, array{label:string, tags:array<string,string>}>
 */
function ekwa_schema_template_tags() {
	return array(
		'business'   => array(
			'label' => __( 'Business', 'ekwa' ),
			'tags'  => array(
				'schema_type'    => __( 'Organization when several locations exist, otherwise the configured type', 'ekwa' ),
				'org_type'       => __( 'Configured Organization Type (e.g. Dentist)', 'ekwa' ),
				'schema_id'      => __( 'Main entity @id', 'ekwa' ),
				'practice_name'  => __( 'Practice Name', 'ekwa' ),
				'client_name'    => __( 'Client Name', 'ekwa' ),
				'site_name'      => __( 'WordPress site title', 'ekwa' ),
				'site_url'       => __( 'Site URL', 'ekwa' ),
				'email'          => __( 'Email Address', 'ekwa' ),
				'logo_url'       => __( 'Site logo URL', 'ekwa' ),
				'image_url'      => __( 'Same as the logo URL', 'ekwa' ),
				'country_code'   => __( 'Two-letter country code', 'ekwa' ),
			),
		),
		'location'   => array(
			'label' => __( 'First location', 'ekwa' ),
			'tags'  => array(
				'phone'          => __( 'Phone — new-patient line, falling back to existing patients', 'ekwa' ),
				'phone_new'      => __( 'Phone (New Patients)', 'ekwa' ),
				'phone_existing' => __( 'Phone (Existing Patients)', 'ekwa' ),
				'street'         => __( 'Street Address', 'ekwa' ),
				'city'           => __( 'City', 'ekwa' ),
				'state'          => __( 'State', 'ekwa' ),
				'zip'            => __( 'Zip', 'ekwa' ),
				'latitude'       => __( 'Latitude', 'ekwa' ),
				'longitude'      => __( 'Longitude', 'ekwa' ),
				'direction'      => __( 'Direction URL', 'ekwa' ),
			),
		),
		'founder'    => array(
			'label' => __( 'Founder', 'ekwa' ),
			'tags'  => array(
				'founder_id'        => __( 'Founder @id', 'ekwa' ),
				'founder_name'      => __( 'Founder name', 'ekwa' ),
				'founder_job_title' => __( 'Founder job title', 'ekwa' ),
				'founder_url'       => __( 'Founder page URL', 'ekwa' ),
			),
		),
		'structural' => array(
			'label' => __( 'Whole nodes — write these in quotes', 'ekwa' ),
			'tags'  => array(
				'address'       => __( 'PostalAddress object for the first location', 'ekwa' ),
				'geo'           => __( 'GeoCoordinates object for the first location', 'ekwa' ),
				'opening_hours' => __( 'OpeningHoursSpecification array for the first location', 'ekwa' ),
				'social_links'  => __( 'Array of social profile URLs (sameAs)', 'ekwa' ),
				'founder'       => __( 'Complete founder Person node', 'ekwa' ),
			),
		),
		'blocks'     => array(
			'label' => __( 'Repeat & conditions', 'ekwa' ),
			'tags'  => array(
				'#each locations'   => __( 'Repeat for every location — inside, use the first-location tags above', 'ekwa' ),
				'#each working_hours' => __( 'Repeat for each open day — {{day}}, {{opens}}, {{closes}}', 'ekwa' ),
				'#each social'      => __( 'Repeat for each social profile — {{name}}, {{link}}', 'ekwa' ),
				'#if is_multi_location'  => __( 'Only when more than one location is configured', 'ekwa' ),
				'#unless is_multi_location' => __( 'Only when the site has a single location', 'ekwa' ),
				'@first / @last / @index' => __( 'Position helpers inside a loop — {{#unless @last}},{{/unless}} keeps commas valid', 'ekwa' ),
			),
		),
	);
}

/**
 * The site logo URL, used for both `logo` and `image`.
 *
 * @return string
 */
function ekwa_schema_logo_url() {
	$logo_id  = (int) get_theme_mod( 'custom_logo', 0 );
	$logo_src = $logo_id ? wp_get_attachment_image_src( $logo_id, 'full' ) : false;
	return $logo_src ? $logo_src[0] : '';
}

/**
 * A location's telephone, preferring the new-patient line.
 *
 * The new-patient number is a tracking number that is routinely left blank on
 * sites that don't run call tracking, which is what produced the empty
 * `"telephone": ""` in the output. Fall back to the existing-patient number
 * rather than emitting an empty property.
 *
 * @param array $loc
 * @return string
 */
function ekwa_schema_location_phone( $loc ) {
	$new = trim( (string) ( $loc['phone_new'] ?? '' ) );
	if ( '' !== $new ) {
		return $new;
	}
	return trim( (string) ( $loc['phone_existing'] ?? '' ) );
}

/**
 * Per-location context: the scalars a template can use directly, plus the
 * structural nodes ({{address}}, {{geo}}, {{opening_hours}}) and the
 * {{#each working_hours}} collection.
 *
 * @param array  $loc
 * @param string $country_code
 * @return array
 */
function ekwa_schema_location_context( $loc, $country_code ) {
	$hours = ekwa_schema_opening_hours( $loc['working_hours'] ?? array() );

	$wh_rows = array();
	foreach ( $hours as $h ) {
		$wh_rows[] = array(
			'day'    => $h['dayOfWeek'],
			'opens'  => $h['opens'],
			'closes' => $h['closes'],
		);
	}

	return array(
		'street'         => $loc['street']    ?? '',
		'city'           => $loc['city']      ?? '',
		'state'          => $loc['state']     ?? '',
		'zip'            => $loc['zip']       ?? '',
		'latitude'       => $loc['latitude']  ?? '',
		'longitude'      => $loc['longitude'] ?? '',
		'direction'      => $loc['direction'] ?? '',
		'phone'          => ekwa_schema_location_phone( $loc ),
		'phone_new'      => $loc['phone_new']      ?? '',
		'phone_existing' => $loc['phone_existing'] ?? '',
		'address'        => ekwa_schema_build_address( $loc, $country_code ),
		'geo'            => ekwa_schema_build_geo( $loc ),
		'opening_hours'  => $hours,
		'working_hours'  => $wh_rows,
	);
}

/**
 * Build the founder Person node, or null when no founder name is configured.
 *
 * @param array $ctx Partially-built context (needs site_url / schema_id).
 * @return array|null
 */
function ekwa_schema_build_founder( $ctx ) {
	$name = trim( (string) get_option( 'ekwa_founder_name', '' ) );
	if ( '' === $name ) {
		return null;
	}

	$founder = array(
		'@type' => 'Person',
		'@id'   => $ctx['founder_id'],
		'name'  => $name,
	);

	$job = trim( (string) get_option( 'ekwa_founder_job_title', '' ) );
	if ( '' !== $job ) {
		$founder['jobTitle'] = $job;
	}

	if ( '' !== $ctx['founder_url'] ) {
		$founder['url'] = $ctx['founder_url'];
	}

	$image_id = (int) get_option( 'ekwa_founder_image', 0 );
	if ( $image_id ) {
		$src = wp_get_attachment_image_src( $image_id, 'full' );
		if ( is_array( $src ) && ! empty( $src[0] ) ) {
			$founder['image'] = $src[0];
		}
	}

	$desc = trim( (string) get_option( 'ekwa_founder_description', '' ) );
	if ( '' !== $desc ) {
		$founder['description'] = $desc;
	}

	// Point back at the main entity so the two nodes are actually linked.
	if ( '' !== $ctx['schema_id'] ) {
		$founder['worksFor'] = array( '@id' => $ctx['schema_id'] );
	}

	return $founder;
}

/**
 * Resolve a configurable `@id`: manual value, else selected page/post
 * permalink, else the supplied derived fallback.
 *
 * @param string $option_base Option name, e.g. 'ekwa_schema_id'. The page
 *                            selection lives in "{$option_base}_page".
 * @param string $fallback    Derived value used when nothing is configured.
 * @return string
 */
function ekwa_schema_resolve_id( $option_base, $fallback ) {
	$manual = trim( (string) get_option( $option_base, '' ) );
	if ( '' !== $manual ) {
		return $manual;
	}

	$page_id = (int) get_option( $option_base . '_page', 0 );
	if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
		$permalink = get_permalink( $page_id );
		if ( $permalink ) {
			return (string) $permalink;
		}
	}

	return $fallback;
}

/**
 * Assemble every value a schema template can reference.
 *
 * @return array
 */
function ekwa_schema_context() {
	$locations = get_option( 'ekwa_locations', array() );
	if ( ! is_array( $locations ) ) {
		$locations = array();
	}
	$social = get_option( 'ekwa_social', array() );
	if ( ! is_array( $social ) ) {
		$social = array();
	}

	$org_type     = get_option( 'ekwa_organization_type', '' );
	$org_type     = $org_type ? $org_type : 'Organization';
	$country_code = ekwa_schema_country_code();
	$loc_count    = count( $locations );
	$site_url     = (string) get_option( 'siteurl' );
	$logo_url     = ekwa_schema_logo_url();

	// @id values come from Ekwa Settings → General, in this order: a manually
	// typed value, then the permalink of a chosen page/post, then a derived
	// fragment — so a site that configures neither still emits a stable,
	// self-consistent identifier rather than an empty one.
	$job = trim( (string) get_option( 'ekwa_founder_job_title', '' ) );

	$schema_id = ekwa_schema_resolve_id(
		'ekwa_schema_id',
		$site_url . '/#' . strtolower( $org_type )
	);
	$founder_id = ekwa_schema_resolve_id(
		'ekwa_founder_id',
		$site_url . '/#' . ( $job ? sanitize_title( $job ) : 'founder' )
	);

	// The founder's bio page is chosen from the site's own pages/posts; a bio
	// hosted elsewhere is typed directly into the template instead.
	$founder_url  = '';
	$founder_page = (int) get_option( 'ekwa_founder_page', 0 );
	if ( $founder_page && 'publish' === get_post_status( $founder_page ) ) {
		$founder_url = (string) get_permalink( $founder_page );
	}

	$ctx = array(
		'site_url'       => $site_url,
		'site_name'      => get_bloginfo( 'name' ),
		'practice_name'  => get_option( 'ekwa_practice_name', '' ),
		'client_name'    => get_option( 'ekwa_client_name', '' ),
		'org_type'       => $org_type,
		// Matches the pre-template behavior: a multi-location site is an
		// Organization whose `department` entries carry the specific type.
		'schema_type'    => $loc_count > 1 ? 'Organization' : $org_type,
		'schema_id'      => $schema_id,
		'founder_id'     => $founder_id,
		'email'          => get_option( 'ekwa_email', '' ),
		'logo_url'       => $logo_url,
		'image_url'      => $logo_url,
		'country_code'   => $country_code,
		'location_count' => $loc_count,
		'is_multi_location' => $loc_count > 1,
		'has_location'   => $loc_count > 0,
	);

	// First location's fields are promoted to the top level so a single-location
	// template can write {{phone}} / "{{address}}" without an {{#each}}.
	$ctx = array_merge(
		$ctx,
		$loc_count > 0
			? ekwa_schema_location_context( $locations[0], $country_code )
			: ekwa_schema_location_context( array(), $country_code )
	);

	$ctx['locations'] = array();
	foreach ( $locations as $loc ) {
		$ctx['locations'][] = ekwa_schema_location_context( $loc, $country_code );
	}

	$ctx['social']       = array();
	$ctx['social_links'] = array();
	foreach ( $social as $item ) {
		if ( empty( $item['link'] ) ) {
			continue;
		}
		$ctx['social'][] = array(
			'name' => $item['name'] ?? '',
			'link' => $item['link'],
			'icon' => $item['icon'] ?? '',
		);
		$ctx['social_links'][] = $item['link'];
	}

	// Scalars first — ekwa_schema_build_founder() reads founder_url off the
	// context, so it has to be populated before the node is assembled.
	$ctx['founder_name']      = get_option( 'ekwa_founder_name', '' );
	$ctx['founder_job_title'] = get_option( 'ekwa_founder_job_title', '' );
	$ctx['founder_url']       = $founder_url;
	$ctx['founder']           = ekwa_schema_build_founder( $ctx );

	/**
	 * Filter the values available to the schema template.
	 *
	 * @param array $ctx Template context.
	 */
	return apply_filters( 'ekwa_schema_context', $ctx );
}

/**
 * Resolve a (possibly dotted) tag name against the context.
 *
 * @param array  $ctx
 * @param string $path
 * @return mixed null when unknown.
 */
function ekwa_schema_template_lookup( $ctx, $path ) {
	$value = $ctx;
	foreach ( explode( '.', $path ) as $segment ) {
		if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
			return null;
		}
		$value = $value[ $segment ];
	}
	return $value;
}

/**
 * Locate the first block tag and its matching close, honouring nesting.
 *
 * @param string $tpl
 * @return array|null
 */
function ekwa_schema_template_find_block( $tpl ) {
	if ( ! preg_match( '/\{\{#(each|if|unless)\s+([A-Za-z0-9_.@]+)\s*\}\}/', $tpl, $m, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}

	$open_start  = $m[0][1];
	$inner_start = $open_start + strlen( $m[0][0] );
	$depth       = 1;
	$pos         = $inner_start;
	$token       = '/\{\{(#(?:each|if|unless)\s+[A-Za-z0-9_.@]+|\/(?:each|if|unless))\s*\}\}/';

	while ( preg_match( $token, $tpl, $t, PREG_OFFSET_CAPTURE, $pos ) ) {
		$is_open   = ( '#' === $t[1][0][0] );
		$tok_start = $t[0][1];
		$pos       = $tok_start + strlen( $t[0][0] );

		if ( $is_open ) {
			$depth++;
			continue;
		}
		$depth--;
		if ( 0 === $depth ) {
			return array(
				'type'        => $m[1][0],
				'name'        => $m[2][0],
				'open_start'  => $open_start,
				'inner_start' => $inner_start,
				'inner_end'   => $tok_start,
				'close_end'   => $pos,
			);
		}
	}

	return null; // Unbalanced — treated as literal text by the caller.
}

/**
 * Is a context value truthy for {{#if}} / {{#unless}} purposes?
 *
 * @param mixed $value
 * @return bool
 */
function ekwa_schema_template_truthy( $value ) {
	if ( is_array( $value ) ) {
		return ! empty( $value );
	}
	return ! empty( $value );
}

/**
 * Substitute {{tag}} occurrences in a literal (block-free) segment.
 *
 * A tag wrapped in quotes is *structural*: the quotes are consumed and the
 * value is injected as raw JSON. That keeps a template containing whole
 * arrays/objects parseable as JSON while it's being edited.
 *
 * @param string $text
 * @param array  $ctx
 * @return string
 */
function ekwa_schema_render_tags( $text, $ctx ) {
	// Structural: "{{tag}}" → raw JSON value.
	$text = preg_replace_callback(
		'/"\{\{\s*([A-Za-z0-9_.@]+)\s*\}\}"/',
		function ( $m ) use ( $ctx ) {
			$value = ekwa_schema_template_lookup( $ctx, $m[1] );
			if ( null === $value ) {
				return 'null';
			}
			if ( is_array( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			return wp_json_encode( (string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		},
		$text
	);

	// Scalar: {{tag}} inside a JSON string → escaped string content.
	return preg_replace_callback(
		'/\{\{\s*([A-Za-z0-9_.@]+)\s*\}\}/',
		function ( $m ) use ( $ctx ) {
			$value = ekwa_schema_template_lookup( $ctx, $m[1] );
			if ( is_array( $value ) || null === $value ) {
				return '';
			}
			if ( is_bool( $value ) ) {
				return $value ? 'true' : 'false';
			}
			$encoded = wp_json_encode( (string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			// Strip the wrapping quotes; keep the escaping that makes it safe
			// to sit inside the template's own quotes.
			return substr( $encoded, 1, -1 );
		},
		$text
	);
}

/**
 * Render a schema template against a context.
 *
 * @param string $template
 * @param array  $ctx
 * @param int    $depth Recursion guard.
 * @return string Rendered text — expected, but not guaranteed, to be JSON.
 */
function ekwa_schema_render_template( $template, $ctx, $depth = 0 ) {
	if ( $depth > 12 ) {
		return '';
	}

	$out = '';
	while ( true ) {
		$block = ekwa_schema_template_find_block( $template );
		if ( ! $block ) {
			$out .= ekwa_schema_render_tags( $template, $ctx );
			break;
		}

		// Everything before the block renders in the current scope.
		$out  .= ekwa_schema_render_tags( substr( $template, 0, $block['open_start'] ), $ctx );
		$inner = substr( $template, $block['inner_start'], $block['inner_end'] - $block['inner_start'] );
		$value = ekwa_schema_template_lookup( $ctx, $block['name'] );

		if ( 'each' === $block['type'] ) {
			$items = is_array( $value ) ? array_values( $value ) : array();
			$total = count( $items );
			foreach ( $items as $i => $item ) {
				// Item fields win, but the parent scope stays visible so a loop
				// body can still reach {{practice_name}}, {{country_code}}, etc.
				$scope = array_merge(
					$ctx,
					is_array( $item ) ? $item : array(),
					array(
						'this'   => is_array( $item ) ? '' : $item,
						'@index' => $i,
						'@first' => ( 0 === $i ),
						'@last'  => ( $i === $total - 1 ),
					)
				);
				$out .= ekwa_schema_render_template( $inner, $scope, $depth + 1 );
			}
		} else {
			$truthy = ekwa_schema_template_truthy( $value );
			if ( 'unless' === $block['type'] ) {
				$truthy = ! $truthy;
			}
			if ( $truthy ) {
				$out .= ekwa_schema_render_template( $inner, $ctx, $depth + 1 );
			}
		}

		$template = substr( $template, $block['close_end'] );
	}

	return $out;
}

/**
 * Recursively drop null / '' / empty-array properties.
 *
 * Without this a tag with no data behind it leaves `"telephone": ""` in the
 * output — syntactically fine, but exactly the kind of empty property that
 * gets flagged in a rich-result test.
 *
 * @param mixed $data
 * @return mixed
 */
function ekwa_schema_prune_empty( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}

	$clean   = array();
	$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );

	foreach ( $data as $key => $value ) {
		$value = ekwa_schema_prune_empty( $value );
		if ( null === $value || '' === $value || ( is_array( $value ) && empty( $value ) ) ) {
			continue;
		}
		// A node stripped back to nothing but its @type carries no information —
		// e.g. an address whose every field was blank. Drop it rather than
		// emitting `{"@type": "PostalAddress"}`. A lone @id is different: that's
		// a deliberate reference to another node, so it stays.
		if ( is_array( $value ) && array( '@type' ) === array_keys( $value ) ) {
			continue;
		}
		if ( $is_list ) {
			$clean[] = $value;
		} else {
			$clean[ $key ] = $value;
		}
	}

	return $clean;
}

/**
 * Render the active template and return the finished JSON-LD.
 *
 * @param string|null $template Template source; null uses the saved/default one.
 * @param array|null  $ctx      Context; null builds it from settings.
 * @return array{json:string, error:string} `json` is '' when rendering failed.
 */
function ekwa_schema_build_json( $template = null, $ctx = null ) {
	if ( null === $template ) {
		$template = ekwa_schema_get_template();
	}
	if ( null === $ctx ) {
		$ctx = ekwa_schema_context();
	}

	$rendered = ekwa_schema_render_template( $template, $ctx );
	$data     = json_decode( $rendered, true );

	if ( null === $data ) {
		return array(
			'json'  => '',
			'error' => sprintf(
				/* translators: %s: JSON parser message. */
				__( 'The rendered schema is not valid JSON: %s', 'ekwa' ),
				json_last_error_msg()
			),
		);
	}

	if ( get_option( 'ekwa_schema_prune_empty', '1' ) ) {
		$data = ekwa_schema_prune_empty( $data );
	}

	/**
	 * Allow themes/plugins to modify the schema array before output.
	 *
	 * @param array $data Assembled JSON-LD data.
	 */
	$data = apply_filters( 'ekwa_schema_data', $data );

	$json = wp_json_encode(
		$data,
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_PRETTY_PRINT
	);

	if ( ! $json ) {
		return array( 'json' => '', 'error' => __( 'Could not encode the schema.', 'ekwa' ) );
	}

	return array( 'json' => $json, 'error' => '' );
}

/**
 * Render the Organization / LocalBusiness JSON-LD block on wp_head.
 */
function ekwa_render_schema() {
	$result = ekwa_schema_build_json();

	// A template the author has broken must not take the site's schema down
	// with it — fall back to the shipped default, which always renders.
	if ( '' === $result['json'] ) {
		$result = ekwa_schema_build_json( ekwa_schema_default_template() );
	}
	if ( '' === $result['json'] ) {
		return;
	}

	echo "\n<script type=\"application/ld+json\">\n" . $result['json'] . "\n</script>\n";
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

/**
 * Yoast schema: give the Article author (Person) node a `url`.
 *
 * Google's Article rich-result test flags "Missing field url (optional)" on the
 * author when Yoast omits it — which is the norm under Ekwa's standards, where
 * WordPress author archives are disabled and so no archive URL is generated.
 *
 * We fill it from the theme's configured Author Page (Ekwa Settings → General →
 * "Author Page", the `ekwa_author_page` option). That's the same page the visible
 * byline links to via ekwa_filter_author_link() in inc/ekwa-blog.php, so the
 * schema URL and the byline stay consistent. We deliberately do NOT fall back to
 * the native author archive URL, since that target is disabled/noindexed.
 *
 * No-ops when Yoast already set a `url`, or when no Author Page is configured
 * (the optional warning simply remains until one is selected).
 *
 * @param array $data    The author Person piece.
 * @param mixed $context Yoast Meta_Tags_Context — unused; kept as Yoast passes it.
 * @return array
 */
function ekwa_yoast_schema_author_url( $data, $context = null ) {
	if ( ! is_array( $data ) || ! empty( $data['url'] ) ) {
		return $data;
	}

	$author_page_id = absint( get_option( 'ekwa_author_page', 0 ) );
	if ( $author_page_id && 'publish' === get_post_status( $author_page_id ) ) {
		$data['url'] = get_permalink( $author_page_id );
	}

	return $data;
}
add_filter( 'wpseo_schema_author', 'ekwa_yoast_schema_author_url', 11, 2 );
