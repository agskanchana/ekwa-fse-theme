<?php
/**
 * MCP connector — personal connection keys.
 *
 * An AI app proves which WordPress user it is acting for with a key the user
 * created here:
 *
 *   ekwa_mcp_<user id>_<43 random base64url characters>   (256 bits)
 *
 * The key is shown once, when it is created. The site keeps only its SHA-256
 * (a 256-bit random secret needs no slow hash), per site (a user option, so on
 * multisite a key made on one site does not open another).
 *
 * A key can do nothing but read and answer its owner's AI jobs
 * (inc/ekwa-mcp-server.php) — it is not a login. It is accepted three ways:
 * in the connection URL (claude.ai and ChatGPT connectors take only a URL), as
 * `Authorization: Bearer <key>`, or as `X-Ekwa-MCP-Key: <key>`.
 *
 * Keys are created only by their owner clicking "Create key", and removed by
 * the owner or anyone allowed to edit that user.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/ekwa-ai-provider.php';

const EKWA_MCP_KEYS_OPTION = 'ekwa_mcp_keys';
const EKWA_MCP_SEEN_PREFIX = 'ekwa_mcp_key_seen_';
const EKWA_MCP_MAX_KEYS    = 10;

/**
 * A user's key records (hashes, never keys).
 *
 * @param int $user_id
 * @return array<int,array{id:string,label:string,hash:string,created:int}>
 */
function ekwa_mcp_get_keys( $user_id ) {
	$keys = $user_id ? get_user_option( EKWA_MCP_KEYS_OPTION, (int) $user_id ) : array();
	return is_array( $keys ) ? array_values( $keys ) : array();
}

/**
 * Has this user created at least one key on this site?
 *
 * @param int $user_id
 * @return bool
 */
function ekwa_mcp_user_has_key( $user_id ) {
	return (bool) ekwa_mcp_get_keys( $user_id );
}

/**
 * When a key was last used and by which app.
 *
 * Stored apart from the key list: the MCP endpoint updates it while the person
 * may be creating or revoking keys in another tab, and rewriting the list from
 * both places could drop a key.
 *
 * @param int    $user_id
 * @param string $key_id
 * @return array{time:int,client:string}
 */
function ekwa_mcp_key_seen( $user_id, $key_id ) {
	$seen = get_user_option( EKWA_MCP_SEEN_PREFIX . $key_id, (int) $user_id );
	return array(
		'time'   => is_array( $seen ) && isset( $seen['time'] ) ? (int) $seen['time'] : 0,
		'client' => is_array( $seen ) && isset( $seen['client'] ) ? (string) $seen['client'] : '',
	);
}

/**
 * Note that a key was used. Written at most every 5 minutes unless the app changed.
 *
 * @param int    $user_id
 * @param string $key_id
 * @param string $client App name and version, when known.
 */
function ekwa_mcp_touch_key( $user_id, $key_id, $client = '' ) {
	$seen   = ekwa_mcp_key_seen( $user_id, $key_id );
	$client = (string) $client;
	$now    = time();
	if ( $now - $seen['time'] < 300 && ( '' === $client || $client === $seen['client'] ) ) {
		return;
	}
	update_user_option(
		(int) $user_id,
		EKWA_MCP_SEEN_PREFIX . $key_id,
		array(
			'time'   => $now,
			'client' => '' !== $client ? $client : $seen['client'],
		)
	);
}

/**
 * A key record as the UI may see it.
 *
 * @param int   $user_id
 * @param array $record
 * @return array
 */
function ekwa_mcp_public_key( $user_id, $record ) {
	$seen = ekwa_mcp_key_seen( $user_id, (string) $record['id'] );
	return array(
		'id'         => (string) $record['id'],
		'label'      => (string) $record['label'],
		'created'    => (int) $record['created'],
		'lastUsed'   => $seen['time'],
		'lastClient' => $seen['client'],
	);
}

/**
 * Create a key. The only moment the key itself exists outside the AI app.
 *
 * @param int    $user_id
 * @param string $label
 * @return array|WP_Error `{key: string, record: array}`.
 */
function ekwa_mcp_create_key( $user_id, $label = '' ) {
	$user_id = (int) $user_id;
	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return new WP_Error( 'ekwa_mcp_no_user', __( 'Unknown user.', 'ekwa' ) );
	}

	// Two tabs creating or revoking at once must not overwrite each other's
	// change (a revoked key coming back would be the worst case): the list is
	// re-read and rewritten under a per-user lock.
	$lock = ekwa_ai_lock( 'mcp-keys:' . $user_id );
	if ( '' === $lock ) {
		return new WP_Error( 'ekwa_mcp_busy', __( 'Your keys are being changed in another window — try again in a moment.', 'ekwa' ) );
	}
	try {
		return ekwa_mcp_create_key_locked( $user_id, $label );
	} finally {
		ekwa_ai_unlock( $lock );
	}
}

/**
 * The body of ekwa_mcp_create_key(), run while holding the user's key lock.
 *
 * @param int    $user_id
 * @param string $label
 * @return array|WP_Error
 */
function ekwa_mcp_create_key_locked( $user_id, $label ) {
	wp_cache_delete( $user_id, 'user_meta' );
	$keys = ekwa_mcp_get_keys( $user_id );
	if ( count( $keys ) >= EKWA_MCP_MAX_KEYS ) {
		return new WP_Error(
			'ekwa_mcp_too_many_keys',
			sprintf(
				/* translators: %d: maximum number of keys. */
				__( 'You already have %d connection keys. Revoke one you no longer use first.', 'ekwa' ),
				EKWA_MCP_MAX_KEYS
			)
		);
	}

	try {
		$secret = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$id     = bin2hex( random_bytes( 4 ) );
	} catch ( Exception $e ) {
		return new WP_Error( 'ekwa_mcp_random', __( 'This server could not generate a secure random key.', 'ekwa' ) );
	}

	$key   = 'ekwa_mcp_' . $user_id . '_' . $secret;
	$label = sanitize_text_field( (string) $label );
	if ( '' === $label ) {
		$label = sprintf(
			/* translators: %s: date the key was created. */
			__( 'Key created %s', 'ekwa' ),
			wp_date( get_option( 'date_format' ) )
		);
	}
	if ( strlen( $label ) > 80 ) {
		$label = substr( $label, 0, 80 );
	}

	$record = array(
		'id'      => $id,
		'label'   => $label,
		'hash'    => hash( 'sha256', $key ),
		'created' => time(),
	);

	$keys[] = $record;
	update_user_option( $user_id, EKWA_MCP_KEYS_OPTION, $keys );

	return array(
		'key'    => $key,
		'record' => ekwa_mcp_public_key( $user_id, $record ),
	);
}

/**
 * Revoke a key.
 *
 * @param int    $user_id
 * @param string $key_id
 * @return bool Whether a key was removed.
 */
function ekwa_mcp_revoke_key( $user_id, $key_id ) {
	$lock = ekwa_ai_lock( 'mcp-keys:' . (int) $user_id );
	if ( '' === $lock ) {
		return false;
	}
	try {
		return ekwa_mcp_revoke_key_locked( $user_id, $key_id );
	} finally {
		ekwa_ai_unlock( $lock );
	}
}

/**
 * The body of ekwa_mcp_revoke_key(), run while holding the user's key lock.
 *
 * @param int    $user_id
 * @param string $key_id
 * @return bool
 */
function ekwa_mcp_revoke_key_locked( $user_id, $key_id ) {
	wp_cache_delete( (int) $user_id, 'user_meta' );
	$keys = ekwa_mcp_get_keys( $user_id );
	$kept = array();
	foreach ( $keys as $record ) {
		if ( isset( $record['id'] ) && (string) $record['id'] === (string) $key_id ) {
			continue;
		}
		$kept[] = $record;
	}
	if ( count( $kept ) === count( $keys ) ) {
		return false;
	}
	if ( $kept ) {
		update_user_option( (int) $user_id, EKWA_MCP_KEYS_OPTION, $kept );
	} else {
		delete_user_option( (int) $user_id, EKWA_MCP_KEYS_OPTION );
	}
	delete_user_option( (int) $user_id, EKWA_MCP_SEEN_PREFIX . $key_id );
	return true;
}

/**
 * Who does this key belong to?
 *
 * @param string $key
 * @return array{user_id:int,key_id:string}|WP_Error|null Null for no/unknown
 *         key; WP_Error when the key is valid but its owner may no longer use AI.
 */
function ekwa_mcp_authenticate_key( $key ) {
	if ( ! is_string( $key ) || ! preg_match( '/^ekwa_mcp_([0-9]+)_[A-Za-z0-9_-]{43}$/', $key, $m ) ) {
		return null;
	}
	$user = get_userdata( (int) $m[1] );
	if ( ! $user ) {
		return null;
	}

	$hash = hash( 'sha256', $key );
	foreach ( ekwa_mcp_get_keys( $user->ID ) as $record ) {
		if ( isset( $record['hash'], $record['id'] ) && hash_equals( (string) $record['hash'], $hash ) ) {
			$cap = function_exists( 'ekwa_ai_min_capability' ) ? ekwa_ai_min_capability() : 'edit_posts';
			if ( ! user_can( $user, $cap ) ) {
				return new WP_Error( 'ekwa_mcp_forbidden', __( 'This key belongs to a user whose role may not use the AI features on this site.', 'ekwa' ) );
			}
			return array( 'user_id' => (int) $user->ID, 'key_id' => (string) $record['id'] );
		}
	}
	return null;
}

/**
 * Is this site reachable only from the computer it runs on (or a private
 * network)? claude.ai and ChatGPT connect from the internet and cannot reach it.
 *
 * @return bool
 */
function ekwa_mcp_site_is_local() {
	if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
		return true;
	}
	$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	$host = trim( $host, '[]' );
	if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
		return true;
	}
	foreach ( array( '.test', '.local', '.localhost', '.lan', '.internal', '.home.arpa', '.example', '.invalid' ) as $suffix ) {
		if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
			return true;
		}
	}
	if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return true;
	}
	return false;
}

/**
 * A name to register this site under in an AI app: "ekwa-<site name>".
 *
 * Built from the site title rather than the host so that several sites under
 * one host (a sub-directory "launchpad") get different names.
 *
 * @return string
 */
function ekwa_mcp_suggested_server_name() {
	$slug = sanitize_title( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	if ( '' === $slug ) {
		$slug = sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
	$slug = trim( substr( (string) preg_replace( '/[^a-z0-9-]+/', '-', $slug ), 0, 32 ), '-' );
	// A site titled "Ekwa …" would otherwise come out as "ekwa-ekwa-…".
	$slug = (string) preg_replace( '/^ekwa(?:-|$)/', '', $slug );
	return 'ekwa' . ( '' !== $slug ? '-' . $slug : '' );
}

/**
 * The MCP endpoint, optionally with a key in the path.
 *
 * @param string $key
 * @return string
 */
function ekwa_mcp_endpoint_url( $key = '' ) {
	return rest_url( 'ekwa/v1/mcp' . ( '' !== $key ? '/' . $key : '' ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// REST — managing your keys (cookie + nonce, from the profile and settings UI)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'rest_api_init', 'ekwa_mcp_keys_register_routes' );

/**
 * Register the key-management routes.
 */
function ekwa_mcp_keys_register_routes() {
	register_rest_route( 'ekwa/v1', '/mcp-keys', array(
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'ekwa_mcp_rest_list_keys',
			'permission_callback' => 'ekwa_mcp_rest_keys_permission',
			'args'                => array(
				'user_id' => array( 'type' => 'integer', 'default' => 0 ),
			),
		),
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'ekwa_mcp_rest_create_key',
			'permission_callback' => 'ekwa_mcp_rest_keys_permission',
			'args'                => array(
				'label' => array( 'type' => 'string', 'default' => '' ),
			),
		),
	) );

	register_rest_route( 'ekwa/v1', '/mcp-keys/(?P<id>[a-f0-9]{8})', array(
		'methods'             => WP_REST_Server::DELETABLE,
		'callback'            => 'ekwa_mcp_rest_revoke_key',
		'permission_callback' => 'ekwa_mcp_rest_keys_permission',
		'args'                => array(
			'user_id' => array( 'type' => 'integer', 'default' => 0 ),
		),
	) );
}

/**
 * The user a key request is about: yourself, or — for listing and revoking —
 * someone you are allowed to edit.
 *
 * @param WP_REST_Request $request
 * @return int
 */
function ekwa_mcp_rest_target_user( $request ) {
	$target = (int) $request->get_param( 'user_id' );
	return $target > 0 ? $target : get_current_user_id();
}

/**
 * Permission: signed in, and either acting on yourself with the AI capability,
 * or (list/revoke only) allowed to edit the other user.
 *
 * @param WP_REST_Request $request
 * @return true|WP_Error
 */
function ekwa_mcp_rest_keys_permission( $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', __( 'Sign in to WordPress first.', 'ekwa' ), array( 'status' => 401 ) );
	}

	$target = ekwa_mcp_rest_target_user( $request );
	if ( $target !== get_current_user_id() ) {
		if ( 'POST' === $request->get_method() ) {
			return new WP_Error( 'ekwa_mcp_forbidden', __( 'Connection keys can only be created by the person who will use them.', 'ekwa' ), array( 'status' => 403 ) );
		}
		return current_user_can( 'edit_user', $target )
			? true
			: new WP_Error( 'ekwa_mcp_forbidden', __( 'You may not manage this user\'s keys.', 'ekwa' ), array( 'status' => 403 ) );
	}

	$cap = function_exists( 'ekwa_ai_min_capability' ) ? ekwa_ai_min_capability() : 'edit_posts';
	return current_user_can( $cap )
		? true
		: new WP_Error( 'ekwa_ai_forbidden', __( 'Your role is not allowed to use the AI features.', 'ekwa' ), array( 'status' => 403 ) );
}

/**
 * Facts every key response carries, for building the setup instructions.
 *
 * @return array
 */
function ekwa_mcp_rest_site_facts() {
	return array(
		'endpoint'   => ekwa_mcp_endpoint_url(),
		'enabled'    => ekwa_ai_mcp_enabled(),
		'isLocal'    => ekwa_mcp_site_is_local(),
		'serverName' => ekwa_mcp_suggested_server_name(),
		'siteName'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'siteUrl'    => home_url( '/' ),
	);
}

/**
 * GET /mcp-keys
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function ekwa_mcp_rest_list_keys( $request ) {
	$user_id = ekwa_mcp_rest_target_user( $request );
	$keys    = array();
	foreach ( ekwa_mcp_get_keys( $user_id ) as $record ) {
		$keys[] = ekwa_mcp_public_key( $user_id, $record );
	}
	$response = rest_ensure_response( array_merge( ekwa_mcp_rest_site_facts(), array( 'keys' => $keys ) ) );
	$response->header( 'Cache-Control', 'no-store, max-age=0' );
	return $response;
}

/**
 * POST /mcp-keys — the response is the only time the key is ever shown.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_mcp_rest_create_key( $request ) {
	$created = ekwa_mcp_create_key( get_current_user_id(), (string) $request->get_param( 'label' ) );
	if ( is_wp_error( $created ) ) {
		$created->add_data( array( 'status' => 400 ) );
		return $created;
	}
	$response = rest_ensure_response( array_merge(
		ekwa_mcp_rest_site_facts(),
		array(
			'key'    => $created['key'],
			'url'    => ekwa_mcp_endpoint_url( $created['key'] ),
			'record' => $created['record'],
		)
	) );
	$response->header( 'Cache-Control', 'no-store, max-age=0' );
	return $response;
}

/**
 * DELETE /mcp-keys/<id>
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_mcp_rest_revoke_key( $request ) {
	$user_id = ekwa_mcp_rest_target_user( $request );
	if ( ! ekwa_mcp_revoke_key( $user_id, (string) $request['id'] ) ) {
		return new WP_Error( 'ekwa_mcp_no_key', __( 'No such key.', 'ekwa' ), array( 'status' => 404 ) );
	}
	return rest_ensure_response( array( 'revoked' => (string) $request['id'] ) );
}
