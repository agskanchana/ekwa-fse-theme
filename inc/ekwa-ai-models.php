<?php
/**
 * Gemini model catalog — the single source of truth for which models the
 * theme offers and which one each feature defaults to.
 *
 * The list is read from Google's ListModels endpoint using the configured key,
 * not hard-coded: Google retires model ids on a rolling basis (a site pinned to
 * gemini-2.5-flash now gets "no longer available to new users" back), and only
 * the API knows what a given key can actually call. The static catalog below is
 * a last resort for when that request fails.
 *
 * Three tiers are exposed, and every feature picks one rather than naming a
 * model directly:
 *   ekwa_ai_default_model()  most capable — user-facing generators default here
 *   ekwa_ai_fast_model()     flash tier   — background/bulk jobs
 *   ekwa_ai_cheap_model()    flash-lite   — cheap classification work
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/ekwa-ai-shared.php';

const EKWA_AI_MODEL_TRANSIENT = 'ekwa_ai_model_catalog';

/**
 * Tier ranking. Higher is more capable — this is what "most powerful" means
 * everywhere else in the file.
 *
 * @return array<string,int>
 */
function ekwa_ai_model_tiers() {
	return array(
		'pro'        => 3,
		'flash'      => 2,
		'flash-lite' => 1,
	);
}

/**
 * Catalog used when the API cannot be reached (no key, network failure, quota,
 * outbound HTTP blocked, or cron has not run yet on a fresh install).
 *
 * Deliberately the long-standing 2.5 family and nothing newer. This list is
 * only ever reached when we could NOT confirm what the site's key can call, so
 * guessing at a newer or preview model here would turn "we don't know" into a
 * hard 404 from Google on every request — on sites whose AI features work
 * today. These three are what every existing install is already using, so
 * falling back to them cannot regress anyone. The moment the live list is read
 * (daily cron, or opening the settings screen) the newest models take over.
 *
 * @return array<int,array{id:string,label:string,tier:string,major:int,minor:int,preview:bool}>
 */
function ekwa_ai_model_fallback_catalog() {
	$ids = array(
		'gemini-2.5-pro',
		'gemini-2.5-flash',
		'gemini-2.5-flash-lite',
	);

	$catalog = array();
	foreach ( $ids as $id ) {
		$parsed = ekwa_ai_parse_model_id( $id );
		if ( $parsed ) {
			$catalog[] = $parsed;
		}
	}

	return ekwa_ai_sort_model_catalog( $catalog );
}

/**
 * Parse a model id into its tier and version, rejecting anything that is not a
 * general-purpose Gemini text model.
 *
 * The allow-list is intentionally strict. ListModels also returns TTS, image
 * ("Nano Banana"), robotics, computer-use, deep-research and Gemma entries,
 * none of which belong in a "which model writes my markup" dropdown, and some
 * of which would fail outright against the generateContent call the theme makes.
 *
 * @param string      $id           Bare model id, e.g. gemini-3.1-pro-preview.
 * @param string|null $display_name Google's display name, when available.
 * @return array{id:string,label:string,tier:string,major:int,minor:int,preview:bool}|null
 */
function ekwa_ai_parse_model_id( $id, $display_name = null ) {
	if ( ! preg_match( '/^gemini-(\d+)(?:\.(\d+))?-(flash-lite|flash|pro)(-preview)?$/', $id, $m ) ) {
		return null;
	}

	$tier    = $m[3];
	$preview = ! empty( $m[4] );

	if ( ! $display_name ) {
		$names        = array( 'pro' => 'Pro', 'flash' => 'Flash', 'flash-lite' => 'Flash-Lite' );
		$version      = $m[1] . ( isset( $m[2] ) && '' !== $m[2] ? '.' . $m[2] : '' );
		$display_name = 'Gemini ' . $version . ' ' . $names[ $tier ] . ( $preview ? ' Preview' : '' );
	}

	$hints = array(
		'pro'        => __( 'best quality', 'ekwa' ),
		'flash'      => __( 'fast', 'ekwa' ),
		'flash-lite' => __( 'cheapest', 'ekwa' ),
	);

	return array(
		'id'      => $id,
		'label'   => $display_name . ' — ' . $hints[ $tier ],
		'tier'    => $tier,
		'major'   => (int) $m[1],
		'minor'   => isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0,
		'preview' => $preview,
	);
}

/**
 * Sort most capable first: tier, then version, then stable ahead of preview.
 *
 * @param array $catalog
 * @return array
 */
function ekwa_ai_sort_model_catalog( $catalog ) {
	$tiers = ekwa_ai_model_tiers();

	usort(
		$catalog,
		function ( $a, $b ) use ( $tiers ) {
			if ( $tiers[ $a['tier'] ] !== $tiers[ $b['tier'] ] ) {
				return $tiers[ $b['tier'] ] <=> $tiers[ $a['tier'] ];
			}
			if ( $a['major'] !== $b['major'] ) {
				return $b['major'] <=> $a['major'];
			}
			if ( $a['minor'] !== $b['minor'] ) {
				return $b['minor'] <=> $a['minor'];
			}
			// A stable release beats a preview of the same version.
			return ( $a['preview'] ? 1 : 0 ) <=> ( $b['preview'] ? 1 : 0 );
		}
	);

	return $catalog;
}

/**
 * Ask Google which models this key can call.
 *
 * @param string $api_key
 * @return array|WP_Error Parsed catalog (possibly empty) or a transport error.
 */
function ekwa_ai_fetch_remote_models( $api_key ) {
	$response = wp_remote_get(
		add_query_arg(
			array( 'key' => $api_key, 'pageSize' => 200 ),
			'https://generativelanguage.googleapis.com/v1beta/models'
		),
		array( 'timeout' => 8 )
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error(
			'ekwa_ai_models_http',
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Google returned HTTP %d when listing models.', 'ekwa' ),
				$code
			)
		);
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['models'] ) || ! is_array( $body['models'] ) ) {
		return new WP_Error( 'ekwa_ai_models_empty', __( 'Google returned no models.', 'ekwa' ) );
	}

	$catalog = array();
	foreach ( $body['models'] as $model ) {
		$methods = $model['supportedGenerationMethods'] ?? array();
		if ( ! is_array( $methods ) || ! in_array( 'generateContent', $methods, true ) ) {
			continue;
		}

		// Names come back namespaced as "models/gemini-…".
		$id     = preg_replace( '#^models/#', '', (string) ( $model['name'] ?? '' ) );
		$parsed = ekwa_ai_parse_model_id( $id, $model['displayName'] ?? null );
		if ( $parsed ) {
			$catalog[] = $parsed;
		}
	}

	return ekwa_ai_sort_model_catalog( $catalog );
}

/**
 * Are we rendering the Ekwa settings screen?
 *
 * The only admin screen allowed to make the blocking ListModels request: it is
 * where the catalog is displayed and refreshed, so a moment's wait is expected
 * there and nowhere else.
 *
 * @return bool
 */
function ekwa_ai_on_settings_screen() {
	if ( ! is_admin() ) {
		return false;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
	return isset( $_GET['page'] ) && 'ekwa-settings' === $_GET['page'];
}

/**
 * The active model catalog, cached for 12 hours.
 *
 * Only refreshes during admin/REST/CLI work: a front-end page view must never
 * block on an outbound HTTP request, so it takes the cache or the static list.
 *
 * @param bool $force Bypass the cache (used by the "Refresh models" button).
 * @return array
 */
function ekwa_ai_model_catalog( $force = false ) {
	static $memo = null;

	if ( ! $force && null !== $memo ) {
		return $memo;
	}

	$api_key  = ekwa_get_ai_api_key();
	$key_hash = $api_key ? md5( $api_key ) : '';
	$cached   = get_transient( EKWA_AI_MODEL_TRANSIENT );

	// Swapping the key must invalidate the list — a different key can expose a
	// different line-up.
	$cache_ok = is_array( $cached ) && isset( $cached['key'] ) && $cached['key'] === $key_hash;

	if ( ! $force && $cache_ok ) {
		$memo = ! empty( $cached['models'] ) ? $cached['models'] : ekwa_ai_model_fallback_catalog();
		return $memo;
	}

	// Only refresh where an outbound request is expected and a stall is
	// acceptable: the settings screen (which shows the list and has the Refresh
	// button), the daily cron event, WP-CLI, or an explicit force. Notably NOT
	// on enqueue_block_editor_assets — ekwa_ai_models() runs there to build the
	// model dropdowns, and a slow or unreachable Google would have added seconds
	// to every editor load. Everywhere else takes the cache, or the static list.
	$can_fetch = $api_key && ( $force || wp_doing_cron()
		|| ( defined( 'WP_CLI' ) && WP_CLI )
		|| ekwa_ai_on_settings_screen() );

	if ( ! $can_fetch ) {
		$memo = $cache_ok && ! empty( $cached['models'] ) ? $cached['models'] : ekwa_ai_model_fallback_catalog();
		return $memo;
	}

	$fetched = ekwa_ai_fetch_remote_models( $api_key );

	if ( is_wp_error( $fetched ) || empty( $fetched ) ) {
		// Cache the failure briefly so a bad key doesn't retry on every admin
		// page load, and keep serving whatever we last had.
		set_transient(
			EKWA_AI_MODEL_TRANSIENT,
			array(
				'key'    => $key_hash,
				'models' => $cache_ok && ! empty( $cached['models'] ) ? $cached['models'] : array(),
				'error'  => is_wp_error( $fetched ) ? $fetched->get_error_message() : __( 'No usable models returned.', 'ekwa' ),
			),
			HOUR_IN_SECONDS
		);

		$memo = $cache_ok && ! empty( $cached['models'] ) ? $cached['models'] : ekwa_ai_model_fallback_catalog();
		return $memo;
	}

	set_transient(
		EKWA_AI_MODEL_TRANSIENT,
		array( 'key' => $key_hash, 'models' => $fetched, 'error' => '', 'fetched' => time() ),
		12 * HOUR_IN_SECONDS
	);

	$memo = $fetched;
	return $memo;
}

/**
 * Drop the cached catalog so the next call re-reads it from Google.
 */
function ekwa_ai_flush_model_catalog() {
	delete_transient( EKWA_AI_MODEL_TRANSIENT );
}

/**
 * The last catalog refresh error, if any.
 *
 * @return string Empty when the last refresh succeeded.
 */
function ekwa_ai_model_catalog_error() {
	$cached = get_transient( EKWA_AI_MODEL_TRANSIENT );
	return is_array( $cached ) && ! empty( $cached['error'] ) ? (string) $cached['error'] : '';
}

/**
 * Model id → label map for dropdowns, most capable first.
 *
 * @return array<string,string>
 */
function ekwa_ai_models() {
	$models = array();
	foreach ( ekwa_ai_model_catalog() as $model ) {
		$models[ $model['id'] ] = $model['label'];
	}

	/**
	 * Filter the models offered in the theme's AI dropdowns.
	 *
	 * @param array<string,string> $models Model id → label.
	 */
	return apply_filters( 'ekwa_ai_models', $models );
}

/**
 * Best available model in a tier, falling back down the tiers when a site's
 * key has no model at that level.
 *
 * @param string $tier pro|flash|flash-lite.
 * @return string Model id.
 */
function ekwa_ai_model_for_tier( $tier ) {
	$catalog = ekwa_ai_model_catalog();
	if ( empty( $catalog ) ) {
		$catalog = ekwa_ai_model_fallback_catalog();
	}

	foreach ( $catalog as $model ) {
		if ( $model['tier'] === $tier ) {
			return $model['id'];
		}
	}

	// Nothing in the requested tier — the catalog is sorted most-capable-first,
	// so the head is the closest thing available.
	return $catalog[0]['id'];
}

/**
 * The most capable model available. This is what user-facing generators use
 * when the operator has not chosen one.
 *
 * @return string
 */
function ekwa_ai_default_model() {
	/**
	 * Filter the theme-wide default (most capable) model.
	 *
	 * @param string $model Model id.
	 */
	return apply_filters( 'ekwa_ai_default_model', ekwa_ai_model_for_tier( 'pro' ) );
}

/**
 * Current Flash-tier model — for background and bulk work where latency and
 * cost matter more than peak quality.
 *
 * @return string
 */
function ekwa_ai_fast_model() {
	/** This filter is documented in ekwa_ai_default_model(). */
	return apply_filters( 'ekwa_ai_fast_model', ekwa_ai_model_for_tier( 'flash' ) );
}

/**
 * Current Flash-Lite model — cheapest tier, for short classification calls.
 *
 * @return string
 */
function ekwa_ai_cheap_model() {
	/** This filter is documented in ekwa_ai_default_model(). */
	return apply_filters( 'ekwa_ai_cheap_model', ekwa_ai_model_for_tier( 'flash-lite' ) );
}

/**
 * Validate a requested model against the catalog.
 *
 * A model id saved months ago may since have been retired, so an unknown value
 * resolves to the current best in the given tier rather than being passed
 * through to Google to fail.
 *
 * @param string $model Requested model id.
 * @param string $tier  Tier to fall back to.
 * @return string
 */
function ekwa_ai_resolve_model( $model, $tier = 'pro' ) {
	$model  = trim( (string) $model );
	$models = ekwa_ai_models();

	if ( '' !== $model && isset( $models[ $model ] ) ) {
		return $model;
	}

	return ekwa_ai_model_for_tier( $tier );
}

/**
 * Refresh the catalog whenever the API key changes.
 */
add_action( 'update_option_ekwa_gemini_api_key', 'ekwa_ai_flush_model_catalog' );
add_action( 'add_option_ekwa_gemini_api_key', 'ekwa_ai_flush_model_catalog' );

/**
 * Keep the catalog current without any page load ever paying for it: the
 * refresh happens on cron, which is a request of its own.
 */
function ekwa_ai_schedule_model_refresh() {
	if ( ! wp_next_scheduled( 'ekwa_ai_refresh_model_catalog' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ekwa_ai_refresh_model_catalog' );
	}
}
add_action( 'init', 'ekwa_ai_schedule_model_refresh' );

function ekwa_ai_cron_refresh_model_catalog() {
	ekwa_ai_model_catalog( true );
}
add_action( 'ekwa_ai_refresh_model_catalog', 'ekwa_ai_cron_refresh_model_catalog' );

/**
 * Drop the scheduled refresh when the theme is switched away.
 */
function ekwa_ai_unschedule_model_refresh() {
	$next = wp_next_scheduled( 'ekwa_ai_refresh_model_catalog' );
	if ( $next ) {
		wp_unschedule_event( $next, 'ekwa_ai_refresh_model_catalog' );
	}
}
add_action( 'switch_theme', 'ekwa_ai_unschedule_model_refresh' );

/**
 * Handle the "Refresh model list" link on the AI settings tab.
 *
 * A plain link rather than a submit button: the AI tab lives inside the main
 * settings form, and a second submit there would save every other field as a
 * side effect of asking Google what models exist.
 */
function ekwa_ai_handle_model_refresh() {
	if ( empty( $_GET['ekwa_refresh_models'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ekwa_refresh_models' ) ) {
		return;
	}

	ekwa_ai_flush_model_catalog();
	ekwa_ai_model_catalog( true );

	wp_safe_redirect( admin_url( 'themes.php?page=ekwa-settings&ekwa_tab=ai&ekwa_models_refreshed=1' ) );
	exit;
}
add_action( 'admin_init', 'ekwa_ai_handle_model_refresh' );
