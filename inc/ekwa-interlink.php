<?php
/**
 * Internal linking suggestions for the block editor.
 *
 * Builds an index of published pages (plus a few settings-derived special
 * targets) and exposes it to the editor sidebar so authors can link mentions
 * of those pages with one click. Anchor keywords come from page titles, an
 * optional Gemini expansion cached per page, and the theme's Practice Name /
 * Appointment / Directions settings.
 *
 * REST routes (namespace ekwa/v1):
 *   GET  /interlink-targets?exclude={id}   — the link-target index for the editor.
 *   POST /interlink-refine                 — optional Gemini relevance pass.
 *   POST /interlink-rebuild-keywords       — (re)generate the cached AI keywords.
 *
 * Reuses the shared Gemini helpers in inc/ekwa-ai-shared.php +
 * inc/ekwa-ai-generate.php and the appointment resolver in inc/ekwa-settings.php.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Per-page meta: cached AI-expanded anchor keywords + a hash of the title used
// to detect when they're stale and need regenerating.
const EKWA_INTERLINK_META_KEYWORDS = '_ekwa_interlink_keywords';
const EKWA_INTERLINK_META_HASH     = '_ekwa_interlink_kw_hash';
const EKWA_INTERLINK_INDEX_TRANSIENT = 'ekwa_interlink_index';

/**
 * Whether the feature is enabled (default on).
 *
 * @return bool
 */
function ekwa_interlink_enabled() {
	$v = (string) get_option( 'ekwa_interlink_enabled', '1' );
	return '' !== $v && '0' !== $v;
}

/**
 * Resolve the Gemini model used for the on-demand "Refine with AI" pass.
 *
 * @return string
 */
function ekwa_interlink_refine_model() {
	$model  = (string) get_option( 'ekwa_interlink_refine_model', 'gemini-2.5-flash' );
	$models = function_exists( 'ekwa_ai_generate_allowed_models' ) ? ekwa_ai_generate_allowed_models() : array();
	return isset( $models[ $model ] ) ? $model : 'gemini-2.5-flash';
}

// ═══════════════════════════════════════════════════════════════════════════════
// REST ROUTES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Register the internal-link REST routes.
 */
function ekwa_interlink_register_routes() {
	register_rest_route( 'ekwa/v1', '/interlink-targets', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'ekwa_interlink_rest_targets',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'exclude' => array(
				'required' => false,
				'type'     => 'integer',
				'default'  => 0,
			),
		),
	) );

	register_rest_route( 'ekwa/v1', '/interlink-refine', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_interlink_rest_refine',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'candidates' => array(
				'required' => true,
				'type'     => 'array',
			),
			'text'       => array(
				'required' => false,
				'type'     => 'string',
				'default'  => '',
			),
			'model'      => array(
				'required' => false,
				'type'     => 'string',
				'default'  => '',
			),
		),
	) );

	register_rest_route( 'ekwa/v1', '/interlink-rebuild-keywords', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_interlink_rest_rebuild',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );
}
add_action( 'rest_api_init', 'ekwa_interlink_register_routes' );

/**
 * REST: return the link-target index for the editor.
 *
 * Read-only and fast — never calls Gemini. AI keyword expansion happens out of
 * band (Rebuild button / on page save), and this endpoint just serves whatever
 * is cached in post meta plus the title-derived and settings-derived anchors.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function ekwa_interlink_rest_targets( $request ) {
	if ( ! ekwa_interlink_enabled() ) {
		return rest_ensure_response( array( 'targets' => array() ) );
	}

	$exclude = absint( $request->get_param( 'exclude' ) );
	$targets = ekwa_interlink_page_targets();      // Cached page index.
	$targets = array_merge( $targets, ekwa_interlink_special_targets() );

	// Drop the page being edited (no self-links) and any target that ended up
	// without usable keywords.
	$out = array();
	foreach ( $targets as $t ) {
		if ( $exclude && ! empty( $t['postId'] ) && (int) $t['postId'] === $exclude ) {
			continue;
		}
		if ( empty( $t['keywords'] ) ) {
			continue;
		}
		$out[] = array(
			'title'    => $t['title'],
			'url'      => $t['url'],
			'keywords' => array_values( $t['keywords'] ),
		);
	}

	return rest_ensure_response( array( 'targets' => $out ) );
}

/**
 * REST: rebuild the cached AI keywords for all pages, then bust the index cache.
 *
 * @return WP_REST_Response|WP_Error
 */
function ekwa_interlink_rest_rebuild() {
	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return new WP_Error( 'no_api_key', __( 'Gemini API key is not configured (Settings → AI).', 'ekwa' ), array( 'status' => 400 ) );
	}
	$count = ekwa_interlink_expand_keywords( null, true );
	ekwa_interlink_flush_index();
	return rest_ensure_response( array(
		'updated' => (int) $count,
		'message' => sprintf(
			/* translators: %d: number of pages processed. */
			_n( 'Generated keywords for %d page.', 'Generated keywords for %d pages.', (int) $count, 'ekwa' ),
			(int) $count
		),
	) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// TARGET INDEX
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Build (and cache) the published-page link targets.
 *
 * Each target: { postId, title, url, keywords[] }. Keywords are the page title
 * plus any cached AI-expanded variations stored in post meta.
 *
 * @return array
 */
function ekwa_interlink_page_targets() {
	$cached = get_transient( EKWA_INTERLINK_INDEX_TRANSIENT );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$ids = get_posts( array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'numberposts' => -1,
		'fields'      => 'ids',
		'orderby'     => 'title',
		'order'       => 'ASC',
	) );

	$front_id = (int) get_option( 'page_on_front', 0 );

	$targets = array();
	foreach ( $ids as $id ) {
		$id = (int) $id;
		// The front page is reached via the Practice Name special target instead.
		if ( $front_id && $id === $front_id ) {
			continue;
		}
		$title = get_the_title( $id );
		$url   = get_permalink( $id );
		if ( '' === $title || ! $url ) {
			continue;
		}

		$keywords = array( $title );
		$cachedkw = get_post_meta( $id, EKWA_INTERLINK_META_KEYWORDS, true );
		if ( is_array( $cachedkw ) ) {
			$keywords = array_merge( $keywords, $cachedkw );
		}

		$targets[] = array(
			'postId'   => $id,
			'title'    => $title,
			'url'      => $url,
			'keywords' => ekwa_interlink_clean_keywords( $keywords ),
		);
	}

	set_transient( EKWA_INTERLINK_INDEX_TRANSIENT, $targets, DAY_IN_SECONDS );
	return $targets;
}

/**
 * Settings-derived special targets: home (Practice Name), appointment, directions.
 *
 * Assembled per-request from theme options so they always reflect current
 * settings. Each carries a `postId` where one applies, so self-links are still
 * filtered when editing that page.
 *
 * @return array
 */
function ekwa_interlink_special_targets() {
	$targets = array();

	// Practice Name → home page.
	$practice = trim( (string) get_option( 'ekwa_practice_name', '' ) );
	if ( '' !== $practice ) {
		$targets[] = array(
			'postId'   => (int) get_option( 'page_on_front', 0 ),
			'title'    => sprintf( /* translators: %s: practice name. */ __( '%s (Home)', 'ekwa' ), $practice ),
			'url'      => home_url( '/' ),
			'keywords' => ekwa_interlink_clean_keywords( array( $practice ) ),
		);
	}

	// Appointment page/URL.
	$appt_url = function_exists( 'ekwa_get_appointment_url' ) ? ekwa_get_appointment_url() : '';
	if ( '' !== $appt_url ) {
		$appt_post = 'page' === get_option( 'ekwa_appt_type', 'page' ) ? (int) get_option( 'ekwa_appt_page', 0 ) : 0;
		$targets[] = array(
			'postId'   => $appt_post,
			'title'    => __( 'Appointment', 'ekwa' ),
			'url'      => $appt_url,
			'keywords' => array(
				'book an appointment',
				'schedule an appointment',
				'request an appointment',
				'make an appointment',
				'book now',
				'book online',
				'schedule a consultation',
				'appointment',
			),
		);
	}

	// Directions — first location with a direction URL or a usable address.
	$directions = ekwa_interlink_directions_url();
	if ( '' !== $directions ) {
		$targets[] = array(
			'postId'   => 0,
			'title'    => __( 'Directions', 'ekwa' ),
			'url'      => $directions,
			'keywords' => array(
				'driving directions',
				'get directions',
				'directions',
			),
		);
	}

	return $targets;
}

/**
 * Resolve a directions URL from the first location that has one, or build a
 * Google Maps link from its address. Empty string when no address is present.
 *
 * @return string
 */
function ekwa_interlink_directions_url() {
	$locations = get_option( 'ekwa_locations', array() );
	if ( ! is_array( $locations ) ) {
		return '';
	}
	foreach ( $locations as $loc ) {
		if ( ! is_array( $loc ) ) {
			continue;
		}
		if ( ! empty( $loc['direction'] ) ) {
			return (string) $loc['direction'];
		}
		$parts = array_filter( array(
			$loc['street'] ?? '',
			$loc['city'] ?? '',
			trim( ( $loc['state'] ?? '' ) . ' ' . ( $loc['zip'] ?? '' ) ),
		) );
		if ( $parts ) {
			return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( implode( ', ', $parts ) );
		}
		if ( ! empty( $loc['latitude'] ) && ! empty( $loc['longitude'] ) ) {
			return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $loc['latitude'] . ',' . $loc['longitude'] );
		}
	}
	return '';
}

/**
 * Normalise a keyword list: trim, drop blanks/very-short phrases, de-dupe
 * case-insensitively while preserving the first-seen casing.
 *
 * @param array $keywords Raw keywords.
 * @return array
 */
function ekwa_interlink_clean_keywords( $keywords ) {
	$seen = array();
	$out  = array();
	foreach ( (array) $keywords as $kw ) {
		$kw = trim( preg_replace( '/\s+/', ' ', (string) $kw ) );
		if ( mb_strlen( $kw ) < 3 ) {
			continue;
		}
		$key = mb_strtolower( $kw );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$out[]        = $kw;
	}
	return $out;
}

// ═══════════════════════════════════════════════════════════════════════════════
// CACHE INVALIDATION
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Drop the cached page index so it rebuilds on the next request.
 */
function ekwa_interlink_flush_index() {
	delete_transient( EKWA_INTERLINK_INDEX_TRANSIENT );
}

/**
 * Bust the index when a page is saved/deleted, and (re)generate that page's
 * keywords out of band so saving never blocks on a Gemini call.
 *
 * @param int $post_id Post ID.
 */
function ekwa_interlink_on_save_post( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}
	if ( 'page' !== get_post_type( $post_id ) ) {
		return;
	}
	ekwa_interlink_flush_index();

	// Schedule a single-page expansion if the title changed and a key exists.
	if ( 'publish' === get_post_status( $post_id ) && ekwa_get_ai_api_key() ) {
		$hash = md5( get_the_title( $post_id ) );
		if ( $hash !== get_post_meta( $post_id, EKWA_INTERLINK_META_HASH, true ) ) {
			if ( ! wp_next_scheduled( 'ekwa_interlink_expand_event', array( $post_id ) ) ) {
				wp_schedule_single_event( time() + 10, 'ekwa_interlink_expand_event', array( $post_id ) );
			}
		}
	}
}
add_action( 'save_post', 'ekwa_interlink_on_save_post' );
add_action( 'deleted_post', 'ekwa_interlink_flush_index' );

/**
 * Cron handler: expand keywords for a single page.
 *
 * @param int $post_id Post ID.
 */
function ekwa_interlink_expand_event( $post_id ) {
	ekwa_interlink_expand_keywords( array( (int) $post_id ), true );
	ekwa_interlink_flush_index();
}
add_action( 'ekwa_interlink_expand_event', 'ekwa_interlink_expand_event' );

// Theme settings save touches Practice Name / Appointment / Locations — the
// special targets read those live, but flush anyway so nothing stale lingers.
add_action( 'update_option_ekwa_practice_name', 'ekwa_interlink_flush_index' );
add_action( 'update_option_ekwa_locations', 'ekwa_interlink_flush_index' );
add_action( 'update_option_ekwa_appt_page', 'ekwa_interlink_flush_index' );

// ═══════════════════════════════════════════════════════════════════════════════
// AI KEYWORD EXPANSION (Gemini)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Generate and cache natural anchor keywords for pages via Gemini.
 *
 * Batches titles into a single request per chunk and stores the result in post
 * meta. Pages whose title hash is unchanged are skipped unless $force is true.
 *
 * @param int[]|null $page_ids Specific page IDs, or null for all published pages.
 * @param bool       $force    Regenerate even when the cached hash matches.
 * @return int Number of pages updated.
 */
function ekwa_interlink_expand_keywords( $page_ids = null, $force = false ) {
	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return 0;
	}

	if ( null === $page_ids ) {
		$page_ids = get_posts( array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
		) );
	}

	$front_id = (int) get_option( 'page_on_front', 0 );

	// Collect the pages that actually need work (title -> id), skipping unchanged.
	$pending = array();
	foreach ( (array) $page_ids as $id ) {
		$id = (int) $id;
		if ( $front_id && $id === $front_id ) {
			continue;
		}
		$title = get_the_title( $id );
		if ( '' === $title ) {
			continue;
		}
		if ( ! $force ) {
			$hash = md5( $title );
			if ( $hash === get_post_meta( $id, EKWA_INTERLINK_META_HASH, true ) && get_post_meta( $id, EKWA_INTERLINK_META_KEYWORDS, true ) ) {
				continue;
			}
		}
		$pending[ $id ] = $title;
	}

	if ( ! $pending ) {
		return 0;
	}

	$updated = 0;
	foreach ( array_chunk( $pending, 40, true ) as $chunk ) {
		$map = ekwa_interlink_ai_keyword_call( $chunk, $api_key );
		foreach ( $chunk as $id => $title ) {
			$kw = isset( $map[ $title ] ) && is_array( $map[ $title ] ) ? $map[ $title ] : array();
			$kw = ekwa_interlink_clean_keywords( $kw );
			update_post_meta( $id, EKWA_INTERLINK_META_KEYWORDS, $kw );
			update_post_meta( $id, EKWA_INTERLINK_META_HASH, md5( $title ) );
			$updated++;
		}
	}

	return $updated;
}

/**
 * Ask Gemini for anchor-phrase variations for a batch of page titles.
 *
 * @param array  $titles_by_id id => title.
 * @param string $api_key      Gemini API key.
 * @return array title => string[] (empty array on failure).
 */
function ekwa_interlink_ai_keyword_call( $titles_by_id, $api_key ) {
	$titles = array_values( $titles_by_id );

	$system_prompt = 'You help an editor build internal links on a local business / medical website. '
		. 'For each page title you receive, list the short natural phrases a writer would actually type in body copy when referring to that page. '
		. 'Include the page topic, common synonyms, and — when a title clearly names a person (e.g. "Meet Dr. Jane Smith") — that person\'s name and short forms ("Dr. Smith"). '
		. 'Rules: 2-6 phrases per title; lowercase unless it is a proper noun; no generic words like "page", "here", "click", "read more"; phrases must be specific enough to link confidently. '
		. 'Return ONLY a JSON object mapping each exact title string to an array of phrase strings. No markdown, no commentary.';

	$user_text = "Titles:\n" . wp_json_encode( $titles );

	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => $user_text ),
			),
		),
	);

	$result = ekwa_ai_generate_call_gemini( $system_prompt, $contents, 0.3, $api_key, ekwa_interlink_refine_model() );
	if ( is_wp_error( $result ) || empty( $result['content'] ) ) {
		return array();
	}

	$decoded = ekwa_interlink_decode_json( $result['content'] );
	return is_array( $decoded ) ? $decoded : array();
}

// ═══════════════════════════════════════════════════════════════════════════════
// AI REFINE (on demand)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * REST: filter/rank a candidate suggestion list for contextual relevance.
 *
 * Degrades gracefully — on any AI error or unparseable reply the original
 * candidates are returned unchanged so the feature never gets worse with AI on.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ekwa_interlink_rest_refine( $request ) {
	$candidates = (array) $request->get_param( 'candidates' );
	$candidates = ekwa_interlink_sanitize_candidates( $candidates );
	if ( ! $candidates ) {
		return rest_ensure_response( array( 'candidates' => array() ) );
	}

	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return new WP_Error( 'no_api_key', __( 'Gemini API key is not configured (Settings → AI).', 'ekwa' ), array( 'status' => 400 ) );
	}

	$text  = (string) $request->get_param( 'text' );
	$text  = trim( wp_strip_all_tags( $text ) );
	if ( mb_strlen( $text ) > 12000 ) {
		$text = mb_substr( $text, 0, 12000 );
	}

	$model  = (string) $request->get_param( 'model' );
	$models = function_exists( 'ekwa_ai_generate_allowed_models' ) ? ekwa_ai_generate_allowed_models() : array();
	if ( ! isset( $models[ $model ] ) ) {
		$model = ekwa_interlink_refine_model();
	}

	$system_prompt = 'You are an SEO editor reviewing proposed internal links for a web page. '
		. 'Given the page text and a list of candidate links (anchor phrase + destination), decide which links are genuinely relevant and helpful in this context. '
		. 'Keep only candidates whose anchor phrase, read in context, naturally refers to the destination. Drop weak, off-topic, or forced links. '
		. 'Return ONLY a JSON array; each item is {"phrase": string, "url": string, "keep": true|false}. Preserve the phrase and url exactly as given. No markdown, no commentary.';

	$payload = array();
	foreach ( $candidates as $c ) {
		$payload[] = array( 'phrase' => $c['phrase'], 'url' => $c['url'], 'destination' => $c['title'] );
	}

	$user_text = "Page text:\n" . $text . "\n\nCandidates:\n" . wp_json_encode( $payload );

	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => $user_text ),
			),
		),
	);

	$result = ekwa_ai_generate_call_gemini( $system_prompt, $contents, 0.2, $api_key, $model );
	if ( is_wp_error( $result ) || empty( $result['content'] ) ) {
		// Graceful fallback: keep the deterministic list.
		return rest_ensure_response( array( 'candidates' => $candidates, 'refined' => false ) );
	}

	$decoded = ekwa_interlink_decode_json( $result['content'] );
	if ( ! is_array( $decoded ) ) {
		return rest_ensure_response( array( 'candidates' => $candidates, 'refined' => false ) );
	}

	// Build a keep-set keyed by phrase|url from the AI verdict.
	$keep = array();
	foreach ( $decoded as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$phrase = isset( $row['phrase'] ) ? (string) $row['phrase'] : '';
		$url    = isset( $row['url'] ) ? (string) $row['url'] : '';
		$ok     = ! isset( $row['keep'] ) || ! empty( $row['keep'] );
		if ( $ok && '' !== $phrase ) {
			$keep[ mb_strtolower( $phrase ) . '|' . $url ] = true;
		}
	}

	$out = array();
	foreach ( $candidates as $c ) {
		if ( isset( $keep[ mb_strtolower( $c['phrase'] ) . '|' . $c['url'] ] ) ) {
			$out[] = $c;
		}
	}

	return rest_ensure_response( array( 'candidates' => $out, 'refined' => true ) );
}

/**
 * Sanitise the candidate list received from the editor.
 *
 * @param array $candidates Raw candidates.
 * @return array { phrase, url, title }[]
 */
function ekwa_interlink_sanitize_candidates( $candidates ) {
	$out = array();
	foreach ( (array) $candidates as $c ) {
		if ( ! is_array( $c ) ) {
			continue;
		}
		$phrase = isset( $c['phrase'] ) ? sanitize_text_field( $c['phrase'] ) : '';
		$url    = isset( $c['url'] ) ? esc_url_raw( $c['url'] ) : '';
		$title  = isset( $c['title'] ) ? sanitize_text_field( $c['title'] ) : '';
		if ( '' === $phrase || '' === $url ) {
			continue;
		}
		$out[] = array( 'phrase' => $phrase, 'url' => $url, 'title' => $title );
	}
	return $out;
}

/**
 * Decode JSON that a model may have wrapped in markdown fences or prose.
 *
 * @param string $text Raw model output.
 * @return mixed Decoded value, or null on failure.
 */
function ekwa_interlink_decode_json( $text ) {
	$text = trim( (string) $text );
	// Strip ```json ... ``` fences if present.
	$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
	$text = preg_replace( '/\s*```$/', '', $text );
	$decoded = json_decode( $text, true );
	if ( null !== $decoded ) {
		return $decoded;
	}
	// Last resort: grab the first {...} or [...] block.
	if ( preg_match( '/(\{.*\}|\[.*\])/s', $text, $m ) ) {
		return json_decode( $m[1], true );
	}
	return null;
}
