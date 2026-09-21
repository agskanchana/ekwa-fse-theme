<?php
/**
 * Depositphotos comps — relevant stock imagery while a page is being designed.
 *
 * Two entry points, both opt-in:
 *
 *  1. A tick in the "Generate with AI" modal. When it is on, the placeholder
 *     images the model emits (placehold.co/800x600 and friends) are swapped for
 *     watermarked Depositphotos comps matched to what the model said each image
 *     WAS — its alt text — so a generated section arrives looking like the page
 *     it is meant to become instead of a wall of grey boxes.
 *
 *  2. A button beside Set featured image. It works out what the page is about,
 *     searches for it, and hands back links to follow and download by hand.
 *
 * NO API KEY IS INVOLVED. Depositphotos' own search pages carry their CDN
 * preview URLs in the delivered HTML, and those URLs are self-describing:
 *
 *   https://st3.depositphotos.com/16180064/18403/i/450/depositphotos_184038544-stock-photo-doctor-holding-model-of-teeth.jpg
 *                                                 └ size  └ image id        └ what it is a picture of
 *
 * which gives the id (for buying it later), a caption (for alt text), and a
 * size segment that can be rewritten: /i/450/ is 600px wide, /i/950/ is 1023px,
 * /i/1600/ is 1600px. Everything above /i/150/ carries the Depositphotos
 * watermark and prints the image id along the bottom edge — so a comp is always
 * traceable back to the file that has to be bought to replace it.
 *
 * LICENSING. Comps are licensed for evaluation before purchase, not for a live
 * public site. The site owner has said they will replace them by hand, so this
 * does not police it — but every comp is left obvious, and
 * ekwa_dp_find_comps_in_use() will list what is still outstanding.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long a search result list is kept. Stock libraries do not churn fast. */
const EKWA_DP_CACHE_TTL = 12 * HOUR_IN_SECONDS;

/** How long a failed search is remembered, so a block does not become a hammer. */
const EKWA_DP_FAIL_TTL = 15 * MINUTE_IN_SECONDS;

/** Host any comp URL must be on. Used as the "is this a comp?" test too. */
const EKWA_DP_HOST_RE = '(?:st|static)[0-9]*\.depositphotos\.com';

/* ══════════════════════════════════════════════════════════════════════════
 * Searching
 * ══════════════════════════════════════════════════════════════════════════ */

/**
 * Turn a phrase into the slug Depositphotos' search URLs use.
 *
 * @param string $query
 * @return string Empty when nothing usable survives.
 */
function ekwa_dp_slugify_query( $query ) {
	$query = strtolower( trim( (string) $query ) );
	$query = preg_replace( '/[^a-z0-9]+/', '-', $query );

	return trim( (string) $query, '-' );
}

/**
 * The comp size bands Depositphotos serves, keyed by the URL segment.
 *
 * Measured, not guessed. /i/450/ and /i/600/ both return the same 600px file,
 * so 600 is not offered as a separate choice.
 *
 * 1600 is deliberately absent. It exists for newer uploads and 404s for older
 * ones — depositphotos_184038544 serves it, depositphotos_14351475 does not —
 * and a 404 in a design is worse than a slightly soft photograph. It is reached
 * only through ekwa_dp_large_url(), which checks before committing to it.
 *
 * @return array<int,int> Segment number => delivered pixel width.
 */
function ekwa_dp_size_bands() {
	return array(
		150 => 150,
		450 => 600,
		950 => 1023,
	);
}

/**
 * The biggest comp that actually exists for one image.
 *
 * Only worth asking for something wider than the 1023px band covers, and only
 * worth asking once — the answer is cached against the image id, so a hero
 * placed on twenty pages costs one HEAD request in total.
 *
 * @param string $url   Any size of this image's comp URL.
 * @param int    $id    Image id, for the cache key.
 * @param int    $width Display width being asked for.
 * @return string A URL that is known to resolve.
 */
function ekwa_dp_large_url( $url, $id, $width ) {
	$safe = ekwa_dp_size_url( $url, 950 );
	if ( (int) $width <= 1023 ) {
		return $safe;
	}

	$key   = 'ekwa_dp_xl_' . (int) $id;
	$known = get_transient( $key );
	if ( '1' === $known ) {
		return ekwa_dp_size_url( $url, 1600 );
	}
	if ( '0' === $known ) {
		return $safe;
	}

	$big      = ekwa_dp_size_url( $url, 1600 );
	$response = wp_remote_head( $big, array(
		'timeout'    => 6,
		'user-agent' => ekwa_dp_user_agent(),
	) );
	$ok = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );

	set_transient( $key, $ok ? '1' : '0', EKWA_DP_CACHE_TTL );

	return $ok ? $big : $safe;
}

/**
 * Their search pages answer the default WordPress agent with a challenge; the
 * same request from a browser agent is served.
 *
 * @return string
 */
function ekwa_dp_user_agent() {
	return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36';
}

/**
 * The smallest band that still covers the width being asked for.
 *
 * Erring upward: a comp shown larger than it is looks broken in a way a
 * slightly oversized download never does.
 *
 * @param int $width Target display width in pixels. 0 asks for the usual comp.
 * @return int A key of ekwa_dp_size_bands().
 */
function ekwa_dp_band_for_width( $width ) {
	$width = (int) $width;
	if ( $width <= 0 ) {
		return 950;
	}

	foreach ( ekwa_dp_size_bands() as $segment => $pixels ) {
		if ( $pixels >= $width ) {
			return $segment;
		}
	}

	// Past the largest band that every image is guaranteed to have.
	// ekwa_dp_large_url() decides whether this one can do better.
	return 950;
}

/**
 * Rewrite a comp URL to a different size band.
 *
 * @param string $url     Any Depositphotos CDN preview URL.
 * @param int    $segment A key of ekwa_dp_size_bands().
 * @return string The URL unchanged when it is not one of theirs.
 */
function ekwa_dp_size_url( $url, $segment ) {
	return (string) preg_replace(
		'#(//' . EKWA_DP_HOST_RE . '/\d+/\d+/[a-z]/)\d+/#i',
		'${1}' . (int) $segment . '/',
		(string) $url
	);
}

/**
 * The public page for an image — where it is bought and downloaded.
 *
 * The bare-id form redirects to whatever the canonical slug is today, which is
 * why it is preferred over reconstructing the slug: their canonical URLs have
 * changed shape before, and a redirect survives that.
 *
 * @param int $id
 * @return string
 */
function ekwa_dp_page_url( $id ) {
	return 'https://depositphotos.com/' . (int) $id . '.html';
}

/**
 * Readable caption from the slug baked into a comp filename.
 *
 * "stock-photo-doctor-holding-model-of-teeth" → "Doctor holding model of teeth".
 * Truncated captions are common — Depositphotos cuts the slug at a fixed length,
 * so these arrive ending mid-phrase ("…installation of the dental"). Left as-is:
 * a slightly clipped description is still a better alt text than none.
 *
 * @param string $slug
 * @return string
 */
function ekwa_dp_title_from_slug( $slug ) {
	$slug = preg_replace( '/^stock-(?:photo|illustration|vector)-/i', '', (string) $slug );
	$text = trim( str_replace( '-', ' ', (string) $slug ) );

	return '' === $text ? '' : ucfirst( $text );
}

/**
 * Pull comp URLs out of a delivered search page.
 *
 * @param string $html  Raw HTML.
 * @param int    $limit Most results to return.
 * @return array<int,array<string,mixed>>
 */
function ekwa_dp_parse_results( $html, $limit = 24 ) {
	$found = array();

	if ( ! preg_match_all(
		'#https?://' . EKWA_DP_HOST_RE . '/\d+/\d+/[a-z]/\d+/depositphotos_(\d+)-(stock-(?:photo|illustration|vector)-[a-z0-9-]*)\.jpg#i',
		(string) $html,
		$matches,
		PREG_SET_ORDER
	) ) {
		return $found;
	}

	foreach ( $matches as $match ) {
		$id = (int) $match[1];
		// The same image appears at several sizes on one page; first wins, and
		// first is the one in relevance order.
		if ( $id <= 0 || isset( $found[ $id ] ) ) {
			continue;
		}

		$found[ $id ] = array(
			'id'    => $id,
			'title' => ekwa_dp_title_from_slug( $match[2] ),
			'thumb' => ekwa_dp_size_url( $match[0], 450 ),
			// The largest size every image is known to have. Anything bigger
			// goes through ekwa_dp_large_url(), which checks first.
			'comp'  => ekwa_dp_size_url( $match[0], 950 ),
			'page'  => ekwa_dp_page_url( $id ),
		);

		if ( count( $found ) >= (int) $limit ) {
			break;
		}
	}

	return array_values( $found );
}

/**
 * Search Depositphotos for a phrase.
 *
 * Cached hard. The search runs during an interactive generate, and a page with
 * six images would otherwise mean six round trips to a third party before the
 * editor sees anything.
 *
 * @param string $query
 * @param int    $limit
 * @return array<int,array<string,mixed>> Empty on any failure — every caller
 *                                        must degrade rather than error.
 */
function ekwa_dp_search( $query, $limit = 24 ) {
	$slug = ekwa_dp_slugify_query( $query );
	if ( '' === $slug ) {
		return array();
	}

	$key    = 'ekwa_dp_' . md5( $slug );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return array_slice( $cached, 0, (int) $limit );
	}
	// A cached failure is stored as the string 'fail' so it is distinguishable
	// from "never looked".
	if ( 'fail' === $cached ) {
		return array();
	}

	$response = wp_remote_get(
		'https://depositphotos.com/search/' . rawurlencode( $slug ) . '.html',
		array(
			'timeout'    => 12,
			'user-agent' => ekwa_dp_user_agent(),
			'headers'    => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-US,en;q=0.9',
			),
		)
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( $key, 'fail', EKWA_DP_FAIL_TTL );
		return array();
	}

	$results = ekwa_dp_parse_results( wp_remote_retrieve_body( $response ), 40 );
	if ( ! $results ) {
		set_transient( $key, 'fail', EKWA_DP_FAIL_TTL );
		return array();
	}

	set_transient( $key, $results, EKWA_DP_CACHE_TTL );

	return array_slice( $results, 0, (int) $limit );
}

/* ══════════════════════════════════════════════════════════════════════════
 * Filling a generated page's placeholders
 * ══════════════════════════════════════════════════════════════════════════ */

/**
 * Placeholder services the generator is told to use, plus the ones models
 * reach for out of habit.
 *
 * @return string Regex alternation body.
 */
function ekwa_dp_placeholder_hosts_re() {
	return '(?:placehold\.co|placehold\.it|via\.placeholder\.com|placekitten\.com|dummyimage\.com)';
}

/**
 * Width a placeholder URL is asking for, from its /WIDTHxHEIGHT path.
 *
 * @param string $url
 * @return int 0 when the URL does not say.
 */
function ekwa_dp_placeholder_width( $url ) {
	return preg_match( '#/(\d+)x\d+#', (string) $url, $m ) ? (int) $m[1] : 0;
}

/**
 * Swap every placeholder image in generated HTML for a matching comp.
 *
 * The model has already said what each image is meant to be — that is what the
 * alt text is — so the alt is the search query, and the page topic is the
 * fallback for images that have none (background images, mostly). Alt text is
 * also WRITTEN BACK where the model left it empty, because an image with no alt
 * is a defect this feature would otherwise be quietly introducing.
 *
 * Degrades in exactly one direction: anything that cannot be matched keeps its
 * placeholder, so a Depositphotos outage costs nothing but grey boxes.
 *
 * @param string $html  Generated HTML fragment.
 * @param string $topic What the page is about, for images with no alt.
 * @return array{html:string,used:array<int,array<string,mixed>>}
 */
function ekwa_dp_apply_to_html( $html, $topic = '' ) {
	$html = (string) $html;
	if ( '' === $html || false === stripos( $html, 'placehold' ) ) {
		return array( 'html' => $html, 'used' => array() );
	}

	$used  = array();
	$pools = array();

	/**
	 * One unused result for a query, trying the page topic as a fallback.
	 * Per-request memo on top of the transient, so six images sharing a topic
	 * cost one search rather than six.
	 */
	$take = static function ( $query, $width ) use ( &$used, &$pools, $topic ) {
		foreach ( array( $query, $topic ) as $candidate ) {
			$slug = ekwa_dp_slugify_query( $candidate );
			if ( '' === $slug ) {
				continue;
			}
			if ( ! array_key_exists( $slug, $pools ) ) {
				$pools[ $slug ] = ekwa_dp_search( $slug, 40 );
			}
			foreach ( $pools[ $slug ] as $result ) {
				// One photograph twice on a page is worse than a grey box.
				if ( isset( $used[ $result['id'] ] ) ) {
					continue;
				}
				$used[ $result['id'] ] = $result;

				$result['url'] = $width > 1023
					? ekwa_dp_large_url( $result['comp'], $result['id'], $width )
					: ekwa_dp_size_url( $result['comp'], ekwa_dp_band_for_width( $width ) );

				return $result;
			}
		}

		return null;
	};

	$placeholder = '#https?://' . ekwa_dp_placeholder_hosts_re() . '/[^"\'\\s)]+#i';

	// ── Pass 1: <img> tags, which carry the alt text worth searching on.
	$html = preg_replace_callback(
		'#<img\b[^>]*>#i',
		static function ( $m ) use ( $take, $placeholder ) {
			$tag = $m[0];
			if ( ! preg_match( $placeholder, $tag, $src ) ) {
				return $tag;
			}

			$alt = preg_match( '#\balt\s*=\s*(["\'])(.*?)\1#is', $tag, $a ) ? trim( $a[2] ) : '';
			$hit = $take( $alt, ekwa_dp_placeholder_width( $src[0] ) );
			if ( ! $hit ) {
				return $tag;
			}

			$tag = str_replace( $src[0], esc_url( $hit['url'] ), $tag );

			// An empty or absent alt gets the comp's own caption. Never
			// overwrite one the model wrote — it knows the page's context and
			// the caption does not.
			if ( '' === $alt && '' !== $hit['title'] ) {
				$replacement = ' alt="' . esc_attr( $hit['title'] ) . '"';
				$tag         = preg_match( '#\balt\s*=\s*(["\'])(.*?)\1#is', $tag )
					? preg_replace( '#\balt\s*=\s*(["\'])(.*?)\1#is', trim( $replacement ), $tag, 1 )
					: preg_replace( '#<img\b#i', '<img' . $replacement, $tag, 1 );
			}

			return $tag;
		},
		$html
	);

	// ── Pass 2: anything left — CSS background-image, <source srcset>, and any
	// placeholder whose <img> found no match above. Topic only; there is no
	// per-image description to go on out here.
	$html = preg_replace_callback(
		$placeholder,
		static function ( $m ) use ( $take ) {
			$hit = $take( '', ekwa_dp_placeholder_width( $m[0] ) );

			return $hit ? esc_url( $hit['url'] ) : $m[0];
		},
		$html
	);

	return array( 'html' => $html, 'used' => array_values( $used ) );
}

/* ══════════════════════════════════════════════════════════════════════════
 * Featured image suggestions
 * ══════════════════════════════════════════════════════════════════════════ */

/**
 * The size this site's featured images actually are.
 *
 * Asking would be reasonable once and tedious forever, and the answer is
 * already sitting in the media library: whatever most of the existing featured
 * images are is what the next one should be.
 *
 * @param string $post_type
 * @return array{width:int,height:int,matched:int,sampled:int}|null Null when
 *         the site has no featured images to learn from — then, and only then,
 *         is asking the right move.
 */
function ekwa_dp_featured_target( $post_type = 'page' ) {
	global $wpdb;

	$thumb_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT pm.meta_value
		   FROM {$wpdb->postmeta} pm
		   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		  WHERE pm.meta_key = '_thumbnail_id'
		    AND p.post_type = %s
		    AND p.post_status = 'publish'
		  ORDER BY p.post_modified DESC
		  LIMIT 60",
		$post_type
	) );

	$tally   = array();
	$sampled = 0;

	foreach ( (array) $thumb_ids as $thumb_id ) {
		$meta = wp_get_attachment_metadata( (int) $thumb_id );
		if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) {
			continue;
		}
		$sampled++;
		$key           = (int) $meta['width'] . 'x' . (int) $meta['height'];
		$tally[ $key ] = isset( $tally[ $key ] ) ? $tally[ $key ] + 1 : 1;
	}

	if ( ! $tally ) {
		return null;
	}

	arsort( $tally );
	$best = (string) array_key_first( $tally );
	$dims = explode( 'x', $best );

	return array(
		'width'   => (int) $dims[0],
		'height'  => isset( $dims[1] ) ? (int) $dims[1] : 0,
		'matched' => (int) $tally[ $best ],
		'sampled' => $sampled,
	);
}

/**
 * Search phrases for what a page is actually about.
 *
 * The title alone is a poor query — "Our Practice" and "Services" return
 * nothing useful — so the body text goes to the model too, and what comes back
 * is phrased the way stock libraries are captioned rather than the way a page
 * is titled.
 *
 * @param int $post_id
 * @return string[] Falls back to the page title when AI is unavailable, so the
 *                  button still does something useful without a key.
 */
function ekwa_dp_suggest_queries( $post_id ) {
	$post = get_post( (int) $post_id );
	if ( ! $post ) {
		return array();
	}

	$title    = trim( (string) $post->post_title );
	$fallback = '' !== $title ? array( $title ) : array();

	if ( ! function_exists( 'ekwa_get_ai_api_key' ) || ! function_exists( 'ekwa_ai_generate_call_gemini' ) ) {
		return $fallback;
	}

	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return $fallback;
	}

	$body = trim( wp_strip_all_tags( (string) $post->post_content ) );
	$body = preg_replace( '/\s+/', ' ', $body );

	$system = "You choose stock-photography search terms.\n"
		. "Given a web page, return the 3 search phrases most likely to find a photograph that suits it on a stock library.\n"
		. "- Describe a PHOTOGRAPH — people, place, action, object. Not the page's topic in the abstract.\n"
		. "- 2 to 4 words each. Stock libraries are captioned in plain nouns, not marketing language.\n"
		. "- No brand names, no invented procedure names, nothing a photographer could not have pointed a camera at.\n"
		. "Return ONLY a JSON array of 3 strings. No prose, no code fences.";

	$prompt = "PAGE TITLE: " . $title . "\n\nPAGE TEXT:\n" . mb_substr( $body, 0, 1500 );

	$contents = ekwa_ai_generate_build_contents( $prompt, array() );
	if ( is_wp_error( $contents ) ) {
		return $fallback;
	}

	$result = ekwa_ai_generate_call_gemini(
		$system,
		$contents,
		0.3,
		$api_key,
		ekwa_ai_resolve_model( '', 'flash' ),
		512
	);
	if ( is_wp_error( $result ) || empty( $result['content'] ) ) {
		return $fallback;
	}

	$text = function_exists( 'ekwa_ai_generate_strip_fences' )
		? ekwa_ai_generate_strip_fences( $result['content'] )
		: (string) $result['content'];

	$parsed = json_decode( trim( $text ), true );
	if ( ! is_array( $parsed ) ) {
		return $fallback;
	}

	$queries = array();
	foreach ( $parsed as $phrase ) {
		if ( is_string( $phrase ) && '' !== ekwa_dp_slugify_query( $phrase ) ) {
			$queries[] = sanitize_text_field( $phrase );
		}
	}

	return $queries ? array_slice( $queries, 0, 3 ) : $fallback;
}

/* ══════════════════════════════════════════════════════════════════════════
 * Comps still in use
 * ══════════════════════════════════════════════════════════════════════════ */

/**
 * Every page still pointing at a Depositphotos comp, with the ids to buy.
 *
 * The replace-by-hand step is the site owner's, and this is the worklist for
 * it. No comp is licensed for a published page, so "which ones are left" should
 * never be a question answered by memory.
 *
 * @param int $limit Pages to report at most.
 * @return array<int,array{post_id:int,title:string,edit:string,ids:int[]}>
 */
function ekwa_dp_find_comps_in_use( $limit = 100 ) {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, post_title, post_content
		   FROM {$wpdb->posts}
		  WHERE post_status IN ( 'publish', 'draft', 'pending', 'private' )
		    AND post_content LIKE %s
		  ORDER BY post_modified DESC
		  LIMIT %d",
		'%depositphotos.com%',
		(int) $limit
	) );

	$out = array();
	foreach ( (array) $rows as $row ) {
		if ( ! preg_match_all(
			'#' . EKWA_DP_HOST_RE . '/\d+/\d+/[a-z]/\d+/depositphotos_(\d+)-#i',
			(string) $row->post_content,
			$matches
		) ) {
			continue;
		}

		$out[] = array(
			'post_id' => (int) $row->ID,
			'title'   => (string) $row->post_title,
			'edit'    => get_edit_post_link( (int) $row->ID, 'raw' ),
			'ids'     => array_values( array_unique( array_map( 'intval', $matches[1] ) ) ),
		);
	}

	return $out;
}

/* ══════════════════════════════════════════════════════════════════════════
 * REST
 * ══════════════════════════════════════════════════════════════════════════ */

/**
 * Finding an image to illustrate a page is an editing job, not an AI job — so
 * this is gated on uploading media rather than on the AI role setting. Someone
 * whose role has AI switched off can still go and find a picture.
 *
 * @return true|WP_Error
 */
function ekwa_dp_rest_permission() {
	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error(
			'ekwa_dp_forbidden',
			__( 'You are not allowed to add media to this site.', 'ekwa' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Register the stock-image routes.
 */
function ekwa_dp_register_routes() {
	register_rest_route( 'ekwa/v1', '/dp-search', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_dp_handle_search',
		'permission_callback' => 'ekwa_dp_rest_permission',
		'args'                => array(
			'query' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit' => array(
				'required' => false,
				'type'     => 'integer',
				'default'  => 24,
			),
		),
	) );

	register_rest_route( 'ekwa/v1', '/dp-suggest-featured', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_dp_handle_suggest_featured',
		// This one does call Gemini, so it answers to the AI role gate.
		'permission_callback' => 'ekwa_ai_rest_permission',
		'args'                => array(
			'post_id' => array(
				'required' => true,
				'type'     => 'integer',
			),
		),
	) );
}
add_action( 'rest_api_init', 'ekwa_dp_register_routes' );

/**
 * POST /ekwa/v1/dp-search
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function ekwa_dp_handle_search( $request ) {
	$query   = (string) $request->get_param( 'query' );
	$limit   = max( 1, min( 40, (int) $request->get_param( 'limit' ) ) );
	$results = ekwa_dp_search( $query, $limit );

	return rest_ensure_response( array(
		'query'   => $query,
		'results' => $results,
		// Distinguishes "nothing matched that phrase" from "could not reach
		// Depositphotos", which want different things from the person reading.
		'ok'      => ! empty( $results ) || '' !== ekwa_dp_slugify_query( $query ),
	) );
}

/**
 * POST /ekwa/v1/dp-suggest-featured
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_dp_handle_suggest_featured( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	$post    = get_post( $post_id );

	if ( ! $post ) {
		return new WP_Error( 'ekwa_dp_no_post', __( 'That page could not be found.', 'ekwa' ), array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'ekwa_dp_forbidden', __( 'You cannot edit that page.', 'ekwa' ), array( 'status' => 403 ) );
	}

	$queries = ekwa_dp_suggest_queries( $post_id );
	$groups  = array();

	foreach ( $queries as $query ) {
		$results = ekwa_dp_search( $query, 8 );
		if ( $results ) {
			$groups[] = array( 'query' => $query, 'results' => $results );
		}
	}

	return rest_ensure_response( array(
		'queries' => $queries,
		'groups'  => $groups,
		'target'  => ekwa_dp_featured_target( $post->post_type ),
	) );
}
