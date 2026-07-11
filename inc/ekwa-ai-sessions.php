<?php
/**
 * AI conversation memory — persistent Build/Refine sessions.
 *
 * The AI Block Builder's multi-turn conversation normally lives only in the
 * editor modal's React state, so closing the modal or reloading loses it.
 * This stores each session server-side (per user) so it can be restored and
 * refined later. Storage is split into a small index (for the "recent
 * sessions" list) and one meta row per session (the full turn history), both
 * in user meta so nothing is autoloaded and each user only sees their own.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EKWA_AI_SESSION_INDEX_KEY = 'ekwa_ai_session_index';
const EKWA_AI_SESSION_MAX       = 30;               // Sessions kept per user.
const EKWA_AI_SESSION_MAX_BYTES = 500 * 1024;       // Per-session payload cap.

/**
 * Capability-only permission for session storage (no rate-limit check — these
 * are not billable AI calls). Mirrors the AI feature role gate.
 *
 * @return bool
 */
function ekwa_ai_sessions_permission() {
	$cap = function_exists( 'ekwa_ai_min_capability' ) ? ekwa_ai_min_capability() : 'edit_posts';
	return current_user_can( $cap );
}

/**
 * Per-session data meta key.
 *
 * @param string $id
 * @return string
 */
function ekwa_ai_session_data_key( $id ) {
	return 'ekwa_ai_session_data_' . $id;
}

/**
 * The current user's session index (newest first).
 *
 * @return array<int,array>
 */
function ekwa_ai_session_index() {
	$index = get_user_meta( get_current_user_id(), EKWA_AI_SESSION_INDEX_KEY, true );
	return is_array( $index ) ? $index : array();
}

add_action( 'rest_api_init', 'ekwa_ai_sessions_register_routes' );

/**
 * Register the session CRUD routes.
 */
function ekwa_ai_sessions_register_routes() {
	$perm = 'ekwa_ai_sessions_permission';

	register_rest_route( 'ekwa/v1', '/ai-sessions', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => $perm,
			'callback'            => 'ekwa_ai_sessions_list',
		),
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => $perm,
			'callback'            => 'ekwa_ai_sessions_save',
		),
	) );

	register_rest_route( 'ekwa/v1', '/ai-sessions/(?P<id>[A-Za-z0-9\-]+)', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => $perm,
			'callback'            => 'ekwa_ai_sessions_get',
		),
		array(
			'methods'             => WP_REST_Server::DELETABLE,
			'permission_callback' => $perm,
			'callback'            => 'ekwa_ai_sessions_delete',
		),
	) );
}

/**
 * GET /ai-sessions — the index (id, title, context, updated).
 *
 * @return WP_REST_Response
 */
function ekwa_ai_sessions_list() {
	return rest_ensure_response( array( 'sessions' => ekwa_ai_session_index() ) );
}

/**
 * GET /ai-sessions/{id} — full session payload.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_sessions_get( $request ) {
	$id   = sanitize_text_field( $request['id'] );
	$data = get_user_meta( get_current_user_id(), ekwa_ai_session_data_key( $id ), true );
	if ( ! is_array( $data ) ) {
		return new WP_Error( 'ekwa_ai_session_not_found', __( 'Session not found.', 'ekwa' ), array( 'status' => 404 ) );
	}
	return rest_ensure_response( $data );
}

/**
 * POST /ai-sessions — create or update a session (upsert).
 *
 * Body: { id?, title?, context?, turns?, markup?, css? }
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_sessions_save( $request ) {
	$user_id = get_current_user_id();
	$params  = $request->get_json_params();
	if ( ! is_array( $params ) ) {
		$params = $request->get_params();
	}

	$id      = isset( $params['id'] ) ? sanitize_text_field( (string) $params['id'] ) : '';
	$turns   = isset( $params['turns'] ) && is_array( $params['turns'] ) ? $params['turns'] : array();
	$markup  = isset( $params['markup'] ) ? (string) $params['markup'] : '';
	$css     = isset( $params['css'] ) ? (string) $params['css'] : '';
	$context = isset( $params['context'] ) ? sanitize_key( $params['context'] ) : 'section';
	$title   = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';

	// Derive a title from the first user turn when none is supplied.
	if ( '' === $title ) {
		foreach ( $turns as $t ) {
			if ( isset( $t['role'] ) && 'user' === $t['role'] && ! empty( $t['text'] ) ) {
				$title = wp_trim_words( sanitize_text_field( $t['text'] ), 10, '…' );
				break;
			}
		}
	}
	if ( '' === $title ) {
		$title = __( 'Untitled session', 'ekwa' );
	}

	$now      = current_time( 'mysql' );
	$existing = $id ? get_user_meta( $user_id, ekwa_ai_session_data_key( $id ), true ) : false;

	if ( ! $id || ! is_array( $existing ) ) {
		$id      = 'sess-' . wp_generate_uuid4();
		$created = $now;
	} else {
		$created = isset( $existing['created'] ) ? $existing['created'] : $now;
	}

	$data = array(
		'id'      => $id,
		'title'   => $title,
		'context' => $context,
		'created' => $created,
		'updated' => $now,
		'turns'   => $turns,
		'markup'  => $markup,
		'css'     => $css,
	);

	// Size guard — reject oversized payloads instead of bloating user meta.
	if ( strlen( wp_json_encode( $data ) ) > EKWA_AI_SESSION_MAX_BYTES ) {
		return new WP_Error(
			'ekwa_ai_session_too_large',
			__( 'This conversation is too large to save.', 'ekwa' ),
			array( 'status' => 413 )
		);
	}

	update_user_meta( $user_id, ekwa_ai_session_data_key( $id ), $data );

	// Update the index: remove any prior entry for this id, unshift the fresh
	// one, then trim to the cap (deleting the dropped sessions' data rows).
	$index = ekwa_ai_session_index();
	$index = array_values( array_filter( $index, function ( $e ) use ( $id ) {
		return isset( $e['id'] ) && $e['id'] !== $id;
	} ) );
	array_unshift( $index, array(
		'id'      => $id,
		'title'   => $title,
		'context' => $context,
		'updated' => $now,
	) );
	if ( count( $index ) > EKWA_AI_SESSION_MAX ) {
		$dropped = array_slice( $index, EKWA_AI_SESSION_MAX );
		foreach ( $dropped as $d ) {
			if ( isset( $d['id'] ) ) {
				delete_user_meta( $user_id, ekwa_ai_session_data_key( $d['id'] ) );
			}
		}
		$index = array_slice( $index, 0, EKWA_AI_SESSION_MAX );
	}
	update_user_meta( $user_id, EKWA_AI_SESSION_INDEX_KEY, $index );

	return rest_ensure_response( array(
		'id'      => $id,
		'title'   => $title,
		'updated' => $now,
	) );
}

/**
 * DELETE /ai-sessions/{id}.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function ekwa_ai_sessions_delete( $request ) {
	$user_id = get_current_user_id();
	$id      = sanitize_text_field( $request['id'] );

	delete_user_meta( $user_id, ekwa_ai_session_data_key( $id ) );

	$index = array_values( array_filter( ekwa_ai_session_index(), function ( $e ) use ( $id ) {
		return isset( $e['id'] ) && $e['id'] !== $id;
	} ) );
	update_user_meta( $user_id, EKWA_AI_SESSION_INDEX_KEY, $index );

	return rest_ensure_response( array( 'deleted' => $id ) );
}
