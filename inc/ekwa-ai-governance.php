<?php
/**
 * AI governance — role gating, per-user daily rate limits, usage logging,
 * payload ceilings, and a key test endpoint.
 *
 * Every theme AI feature ultimately calls ekwa_ai_generate_call_gemini() and
 * every AI REST route shares ekwa_ai_rest_permission() as its permission
 * callback. That gives two chokepoints:
 *   - permission callback → capability gate + daily-cap check + payload ceiling
 *   - central Gemini caller → token accounting + usage log + counter increment
 *
 * Without this, any edit_posts user could burn the site owner's Gemini quota
 * with no cap, no log, and no visibility.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Map the configured minimum role to a representative capability.
 *
 * @return string A capability suitable for current_user_can().
 */
function ekwa_ai_min_capability() {
	$role = get_option( 'ekwa_ai_min_role', 'contributor' );
	$map  = array(
		'administrator' => 'manage_options',
		'editor'        => 'edit_others_posts',
		'author'        => 'publish_posts',
		'contributor'   => 'edit_posts',
	);
	return isset( $map[ $role ] ) ? $map[ $role ] : 'edit_posts';
}

/**
 * Per-user daily request cap. 0 = unlimited.
 *
 * @return int
 */
function ekwa_ai_daily_limit() {
	return max( 0, (int) get_option( 'ekwa_ai_daily_limit', 100 ) );
}

/**
 * Maximum accepted request body size in bytes (DoS guard for the base64
 * screenshot + conversation-history inputs). Filterable.
 *
 * @return int
 */
function ekwa_ai_payload_ceiling() {
	return (int) apply_filters( 'ekwa_ai_payload_ceiling', 30 * 1024 * 1024 ); // 30 MB.
}

/**
 * User-meta key holding today's AI request count (site timezone date).
 *
 * @param int $user_id
 * @return string
 */
function ekwa_ai_usage_meta_key( $user_id ) {
	return 'ekwa_ai_usage_' . current_time( 'Ymd' );
}

/**
 * How many AI requests the given user has made today.
 *
 * @param int $user_id
 * @return int
 */
function ekwa_ai_usage_today( $user_id ) {
	return (int) get_user_meta( $user_id, ekwa_ai_usage_meta_key( $user_id ), true );
}

/**
 * Shared permission callback for every AI REST route.
 *
 * Returns true, or a WP_Error whose status code REST surfaces to the client
 * (403 forbidden, 413 too large, 429 rate limited).
 *
 * @return true|WP_Error
 */
function ekwa_ai_rest_permission() {
	if ( ! current_user_can( ekwa_ai_min_capability() ) ) {
		return new WP_Error(
			'ekwa_ai_forbidden',
			__( 'Your role is not allowed to use the AI features. Ask an administrator to lower the minimum role in Ekwa Settings → AI.', 'ekwa' ),
			array( 'status' => 403 )
		);
	}

	// Payload ceiling — cheap Content-Length check before the body is processed.
	if ( isset( $_SERVER['CONTENT_LENGTH'] ) && (int) $_SERVER['CONTENT_LENGTH'] > ekwa_ai_payload_ceiling() ) {
		return new WP_Error(
			'ekwa_ai_too_large',
			__( 'Request is too large.', 'ekwa' ),
			array( 'status' => 413 )
		);
	}

	// Daily rate limit (per user). Administrators are never capped so a site
	// owner can't lock themselves out.
	$limit = ekwa_ai_daily_limit();
	if ( $limit > 0 && ! current_user_can( 'manage_options' ) ) {
		if ( ekwa_ai_usage_today( get_current_user_id() ) >= $limit ) {
			return new WP_Error(
				'ekwa_ai_rate_limited',
				sprintf(
					/* translators: %d: daily request limit */
					__( 'Daily AI limit reached (%d requests). It resets at midnight, or an administrator can raise it in Ekwa Settings → AI.', 'ekwa' ),
					$limit
				),
				array( 'status' => 429 )
			);
		}
	}

	return true;
}

/**
 * Request-scoped label for the AI feature currently running, so the usage log
 * can attribute tokens. Set by REST handlers; read by the Gemini caller.
 *
 * @param string|null $set Pass a label to set it; null to read.
 * @return string
 */
function ekwa_ai_current_feature( $set = null ) {
	static $feature = 'ai';
	if ( null !== $set ) {
		$feature = sanitize_key( $set );
	}
	return $feature;
}

/**
 * Record one billable AI call: bump today's per-user counter and append a
 * capped entry to the rolling usage log.
 *
 * @param int    $tokens  Total tokens for the call (0 when unknown).
 * @param string $feature Feature label.
 * @param string $model   Model id.
 */
function ekwa_ai_record_usage( $tokens, $feature = 'ai', $model = '' ) {
	$user_id = get_current_user_id();
	if ( $user_id ) {
		$key = ekwa_ai_usage_meta_key( $user_id );
		update_user_meta( $user_id, $key, ekwa_ai_usage_today( $user_id ) + 1 );
	}

	$log   = get_option( 'ekwa_ai_usage_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$user  = $user_id ? get_userdata( $user_id ) : null;
	$log[] = array(
		'time'    => current_time( 'mysql' ),
		'user'    => $user ? $user->user_login : 'system',
		'feature' => sanitize_key( $feature ),
		'tokens'  => (int) $tokens,
		'model'   => sanitize_text_field( $model ),
	);
	// Keep the log bounded — most recent 200 entries.
	if ( count( $log ) > 200 ) {
		$log = array_slice( $log, -200 );
	}
	update_option( 'ekwa_ai_usage_log', $log, false );
}

/**
 * Total tokens logged today across all users (for the settings summary).
 *
 * @return array{requests:int, tokens:int}
 */
function ekwa_ai_usage_totals_today() {
	$today    = current_time( 'Y-m-d' );
	$log      = get_option( 'ekwa_ai_usage_log', array() );
	$requests = 0;
	$tokens   = 0;
	if ( is_array( $log ) ) {
		foreach ( $log as $row ) {
			if ( isset( $row['time'] ) && 0 === strpos( $row['time'], $today ) ) {
				$requests++;
				$tokens += isset( $row['tokens'] ) ? (int) $row['tokens'] : 0;
			}
		}
	}
	return array( 'requests' => $requests, 'tokens' => $tokens );
}

/**
 * The site's standing "project memory" — a note the admin writes once
 * (industry, brand voice, preferred fonts/colors, dos & don'ts) that is
 * injected into every AI prompt. Capped to keep token cost bounded.
 *
 * @return string Trimmed memory text, or '' when unset.
 */
function ekwa_ai_project_memory() {
	$mem = trim( (string) get_option( 'ekwa_ai_project_memory', '' ) );
	if ( '' === $mem ) {
		return '';
	}
	if ( strlen( $mem ) > 4000 ) {
		$mem = substr( $mem, 0, 4000 );
	}
	return $mem;
}

/**
 * Project memory formatted as a prompt section, or '' when unset. Shared by
 * both AI generators so they stay in sync.
 *
 * @return string
 */
function ekwa_ai_project_memory_block() {
	$mem = ekwa_ai_project_memory();
	if ( '' === $mem ) {
		return '';
	}
	return "\n\nPROJECT MEMORY — standing context about this site. Honor it in everything you produce unless the user's prompt overrides a specific point:\n" . $mem . "\n";
}

/**
 * Mask an API key for display: keep the last 4 characters.
 *
 * @param string $key
 * @return string
 */
function ekwa_ai_mask_key( $key ) {
	$key = (string) $key;
	if ( '' === $key ) {
		return '';
	}
	$last = substr( $key, -4 );
	return str_repeat( '•', max( 4, strlen( $key ) - 4 ) ) . $last;
}

/**
 * REST: test the configured Gemini key with a minimal request.
 */
function ekwa_ai_register_governance_routes() {
	register_rest_route( 'ekwa/v1', '/ai-test-key', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => 'ekwa_ai_rest_test_key',
	) );
}
add_action( 'rest_api_init', 'ekwa_ai_register_governance_routes' );

/**
 * Ping Gemini with a tiny prompt and report whether the key works.
 *
 * @return WP_REST_Response
 */
function ekwa_ai_rest_test_key() {
	$api_key = function_exists( 'ekwa_get_ai_api_key' ) ? ekwa_get_ai_api_key() : false;
	if ( ! $api_key ) {
		return rest_ensure_response( array(
			'ok'      => false,
			'message' => __( 'No API key configured.', 'ekwa' ),
		) );
	}

	ekwa_ai_current_feature( 'test' );
	$result = ekwa_ai_generate_call_gemini(
		'You are a health check. Reply with the single word: OK.',
		array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'ping' ) ) ) ),
		0.0,
		$api_key,
		'gemini-2.5-flash-lite'
	);

	if ( is_wp_error( $result ) ) {
		return rest_ensure_response( array(
			'ok'      => false,
			'message' => $result->get_error_message(),
		) );
	}

	return rest_ensure_response( array(
		'ok'      => true,
		'message' => __( 'Key works — Gemini responded.', 'ekwa' ),
	) );
}
