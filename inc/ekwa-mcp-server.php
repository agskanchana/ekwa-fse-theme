<?php
/**
 * MCP connector — the MCP server the person's AI app connects to.
 *
 *   POST {rest}/ekwa/v1/mcp            key in `Authorization: Bearer` or `X-Ekwa-MCP-Key`
 *   POST {rest}/ekwa/v1/mcp/<key>      key in the URL (claude.ai / ChatGPT connectors)
 *
 * Streamable HTTP, stateless, one JSON response per POST (never SSE). Speaks
 * both eras of the protocol on the same endpoint:
 *
 *   legacy  2024-11-05 … 2025-11-25   `initialize` handshake, `ping`, no sessions
 *                                      issued (they are optional there)
 *   modern  2026-07-28                 no handshake; every request carries its
 *                                      version and client in `_meta`; the
 *                                      MCP-Protocol-Version / Mcp-Method / Mcp-Name
 *                                      headers must match the body; `server/discover`
 *
 * What it offers: the job relay's tools (read a waiting AI job, answer it, give
 * up on it, wait for the next) and two prompts (run my jobs / listen). A key
 * reaches only its owner's jobs — nothing here reads or changes site content.
 *
 * Off unless an administrator chose the MCP connector in Ekwa Settings → AI;
 * until then every request gets a JSON-RPC error saying so.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/ekwa-ai-provider.php';
require_once get_template_directory() . '/inc/ekwa-mcp-keys.php';
require_once get_template_directory() . '/inc/ekwa-ai-relay.php';

const EKWA_MCP_MODERN_VERSIONS = array( '2026-07-28' );
const EKWA_MCP_LEGACY_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

/** Characters of job text per ekwa_get_job part — well under Claude Code's 25k-token tool-output cap. */
const EKWA_MCP_PART_CHARS = 40000;

/** Route pattern of the endpoint with a key in the path. */
const EKWA_MCP_KEY_PATTERN = 'ekwa_mcp_[0-9]+_[A-Za-z0-9_-]{43}';

add_action( 'rest_api_init', 'ekwa_mcp_register_routes' );
add_filter( 'rest_authentication_errors', 'ekwa_mcp_rest_authentication', 5 );
add_filter( 'rest_request_before_callbacks', 'ekwa_mcp_rest_invalid_json', 10, 3 );

/**
 * WordPress rejects a malformed JSON body (`rest_invalid_json`) before the
 * endpoint runs, in its own error shape. MCP clients expect a JSON-RPC parse
 * error, so answer with one — only on this endpoint.
 *
 * @param mixed           $response
 * @param array           $handler
 * @param WP_REST_Request $request
 * @return mixed
 */
function ekwa_mcp_rest_invalid_json( $response, $handler, $request ) {
	if ( is_wp_error( $response ) && 'rest_invalid_json' === $response->get_error_code()
		&& preg_match( '#^/ekwa/v1/mcp(?:/' . EKWA_MCP_KEY_PATTERN . ')?$#', (string) $request->get_route() ) ) {
		ekwa_mcp_send( 400, ekwa_mcp_error( null, -32700, 'Parse error: the body must be one JSON-RPC message.' ) );
	}
	return $response;
}

/**
 * Register the endpoint (with and without a key in the path).
 */
function ekwa_mcp_register_routes() {
	$handlers = array(
		array(
			'methods'             => 'POST',
			'callback'            => 'ekwa_mcp_handle_post',
			'permission_callback' => '__return_true', // Authenticated by key inside, so failures are JSON-RPC shaped.
		),
		array(
			'methods'             => array( 'GET', 'DELETE', 'PUT', 'PATCH' ),
			'callback'            => 'ekwa_mcp_handle_not_allowed',
			'permission_callback' => '__return_true',
		),
	);
	register_rest_route( 'ekwa/v1', '/mcp', $handlers );
	register_rest_route( 'ekwa/v1', '/mcp/(?P<key>' . EKWA_MCP_KEY_PATTERN . ')', $handlers );
}

/**
 * Sign the key's owner in for this one request, early enough that plugins which
 * refuse the REST API to anonymous visitors see a signed-in user.
 *
 * Matches the exact route being served (the `rest_route` query var), never the
 * URL as a whole: a key must not authenticate a request to any other route.
 *
 * @param WP_Error|null|true $result
 * @return WP_Error|null|true
 */
function ekwa_mcp_rest_authentication( $result ) {
	if ( null !== $result || ! ekwa_ai_mcp_enabled() ) {
		return $result;
	}
	$route = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] )
		? untrailingslashit( (string) $GLOBALS['wp']->query_vars['rest_route'] )
		: '';
	if ( ! preg_match( '#^/ekwa/v1/mcp(?:/(' . EKWA_MCP_KEY_PATTERN . '))?$#', $route, $m ) ) {
		return $result;
	}

	$key = ! empty( $m[1] ) ? $m[1] : ekwa_mcp_key_from_server();
	$auth = ekwa_mcp_authenticate_key( $key );
	if ( is_array( $auth ) ) {
		wp_set_current_user( $auth['user_id'] );
		$GLOBALS['ekwa_mcp_auth'] = $auth;
		return true;
	}
	return $result;
}

/**
 * The key from request headers, read from $_SERVER (before WP_REST_Request exists).
 *
 * @return string
 */
function ekwa_mcp_key_from_server() {
	$authz = '';
	if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$authz = (string) $_SERVER['HTTP_AUTHORIZATION'];
	} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		$authz = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
	}
	if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $authz, $m ) ) {
		return $m[1];
	}
	return isset( $_SERVER['HTTP_X_EKWA_MCP_KEY'] ) ? trim( (string) $_SERVER['HTTP_X_EKWA_MCP_KEY'] ) : '';
}

/**
 * The key a request carries: path, then `?key=`, then headers.
 *
 * @param WP_REST_Request $request
 * @return string
 */
function ekwa_mcp_key_from_request( $request ) {
	$url = $request->get_url_params();
	if ( ! empty( $url['key'] ) ) {
		return (string) $url['key'];
	}
	$query = $request->get_query_params();
	if ( ! empty( $query['key'] ) && is_string( $query['key'] ) ) {
		return $query['key'];
	}
	$authz = (string) $request->get_header( 'authorization' );
	if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $authz, $m ) ) {
		return $m[1];
	}
	return trim( (string) $request->get_header( 'x_ekwa_mcp_key' ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// TRANSPORT
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * GET / DELETE / …: this server offers no stream and keeps no sessions.
 */
function ekwa_mcp_handle_not_allowed() {
	ekwa_mcp_send( 405, null, array( 'Allow' => 'POST' ) );
}

/**
 * POST: one JSON-RPC message (or, from legacy clients, a batch).
 *
 * @param WP_REST_Request $request
 */
function ekwa_mcp_handle_post( $request ) {
	if ( ! ekwa_mcp_origin_allowed( (string) $request->get_header( 'origin' ) ) ) {
		ekwa_mcp_send( 403, ekwa_mcp_error( null, -32000, 'Forbidden: requests from web pages on other sites are not accepted.' ) );
	}

	if ( ! ekwa_ai_mcp_enabled() ) {
		ekwa_mcp_send( 403, ekwa_mcp_error( null, -32002, 'The MCP connector is turned off on this site. An administrator can turn it on in WordPress: Appearance → Ekwa Settings → AI → AI provider.' ) );
	}

	$auth = isset( $GLOBALS['ekwa_mcp_auth'] ) && is_array( $GLOBALS['ekwa_mcp_auth'] )
		? $GLOBALS['ekwa_mcp_auth']
		: ekwa_mcp_authenticate_key( ekwa_mcp_key_from_request( $request ) );
	if ( is_wp_error( $auth ) ) {
		ekwa_mcp_send( 403, ekwa_mcp_error( null, -32003, $auth->get_error_message() ) );
	}
	if ( ! is_array( $auth ) ) {
		ekwa_mcp_send(
			401,
			ekwa_mcp_error( null, -32001, 'Missing or invalid Ekwa connection key. Create one in WordPress (Users → Profile → Ekwa AI connector) and use the connection URL it gives you, or send it as "Authorization: Bearer <key>".' ),
			array( 'WWW-Authenticate' => 'Bearer realm="ekwa-mcp"' )
		);
	}
	wp_set_current_user( $auth['user_id'] );

	$body = trim( (string) $request->get_body() );
	// The largest legitimate message is one piece of an answer (well under the
	// relay's 5 MB per job); anything bigger is refused before it is decoded.
	if ( strlen( $body ) > 6 * MB_IN_BYTES ) {
		ekwa_mcp_send( 413, ekwa_mcp_error( null, -32600, 'Request too large.' ) );
	}
	$data = json_decode( $body, true );
	if ( '' === $body || ( null === $data && JSON_ERROR_NONE !== json_last_error() ) || ! is_array( $data ) ) {
		ekwa_mcp_send( 400, ekwa_mcp_error( null, -32700, 'Parse error: the body must be one JSON-RPC message.' ) );
	}

	// A JSON array is a batch (allowed by 2025-03-26, removed later).
	if ( '[' === substr( $body, 0, 1 ) ) {
		// Bounded: a batch runs its messages one after another in one PHP worker.
		if ( count( $data ) > 20 ) {
			ekwa_mcp_send( 400, ekwa_mcp_error( null, -32600, 'Batches are limited to 20 messages.' ) );
		}
		$responses = array();
		foreach ( $data as $message ) {
			$out = ekwa_mcp_process( $message, $request, $auth );
			if ( null !== $out['body'] ) {
				$responses[] = $out['body'];
			}
		}
		if ( ! $responses ) {
			ekwa_mcp_send( 202, null );
		}
		ekwa_mcp_send( 200, $responses );
	}

	$out = ekwa_mcp_process( $data, $request, $auth );
	ekwa_mcp_send( $out['status'], $out['body'] );
}

/**
 * Write the response and end the request.
 *
 * Done here rather than by returning to the REST server: the body must be the
 * bare JSON-RPC message, and a notification must get 202 with no body at all.
 *
 * @param int        $status
 * @param array|null $body
 * @param array      $headers
 */
function ekwa_mcp_send( $status, $body = null, $headers = array() ) {
	$json = null;
	if ( null !== $body ) {
		$json = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			$status = 500;
			$json   = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error: the response could not be encoded."}}';
		}
	}

	if ( ! headers_sent() ) {
		status_header( (int) $status );
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: application/json; charset=utf-8' );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	if ( null !== $json ) {
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response body.
	}
	exit;
}

/**
 * DNS-rebinding guard: a browser page may only talk to this endpoint from the
 * site's own origin. AI apps are not browsers and send no Origin at all.
 *
 * @param string $origin
 * @return bool
 */
function ekwa_mcp_origin_allowed( $origin ) {
	$origin = strtolower( trim( $origin ) );
	if ( '' === $origin ) {
		return true;
	}
	$allowed = array();
	foreach ( array( home_url(), site_url(), admin_url() ) as $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			continue;
		}
		$allowed[] = strtolower( ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) );
	}
	return in_array( untrailingslashit( $origin ), $allowed, true );
}

// ═══════════════════════════════════════════════════════════════════════════════
// JSON-RPC
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * A JSON-RPC error message.
 *
 * @param mixed      $id
 * @param int        $code
 * @param string     $message
 * @param mixed|null $data
 * @return array
 */
function ekwa_mcp_error( $id, $code, $message, $data = null ) {
	$error = array(
		'code'    => (int) $code,
		'message' => (string) $message,
	);
	if ( null !== $data ) {
		$error['data'] = $data;
	}
	return array(
		'jsonrpc' => '2.0',
		'id'      => $id,
		'error'   => $error,
	);
}

/**
 * A JSON-RPC result message, with what the modern protocol requires on every
 * result: `resultType`, the server's identity in `_meta`, and cache hints on
 * list results.
 *
 * @param mixed $id
 * @param array $result
 * @param array $ctx       Request context from ekwa_mcp_process().
 * @param bool  $cacheable A list result (tools/list, prompts/list, discover).
 * @return array
 */
function ekwa_mcp_result( $id, $result, $ctx, $cacheable = false ) {
	$result = (array) $result;
	if ( $ctx['modern'] ) {
		$result['resultType'] = 'complete';
		$result['_meta']      = array( 'io.modelcontextprotocol/serverInfo' => ekwa_mcp_server_info() );
		if ( $cacheable ) {
			$result['ttlMs']      = 300000;
			$result['cacheScope'] = 'private';
		}
	}
	return array(
		'jsonrpc' => '2.0',
		'id'      => $id,
		'result'  => $result ? $result : new stdClass(),
	);
}

/**
 * Every protocol version this server speaks, newest first.
 *
 * @return string[]
 */
function ekwa_mcp_supported_versions() {
	return array_merge( EKWA_MCP_MODERN_VERSIONS, EKWA_MCP_LEGACY_VERSIONS );
}

/**
 * Decode an Mcp-Name / Mcp-Param value sent in the `=?base64?…?=` form.
 *
 * @param string $value
 * @return string
 */
function ekwa_mcp_decode_header_value( $value ) {
	if ( preg_match( '/^=\?base64\?(.*)\?=$/', $value, $m ) ) {
		$decoded = base64_decode( $m[1], true );
		return false === $decoded ? $value : $decoded;
	}
	return $value;
}

/**
 * Modern requests mirror body fields into headers; they must agree.
 *
 * @param WP_REST_Request $request
 * @param string          $method
 * @param array           $params
 * @param string          $version The `_meta` protocol version.
 * @return string Empty when they agree, else what is wrong.
 */
function ekwa_mcp_header_mismatch( $request, $method, $params, $version ) {
	$header_version = (string) $request->get_header( 'mcp_protocol_version' );
	if ( $header_version !== $version ) {
		return sprintf( 'Header mismatch: MCP-Protocol-Version header "%s" does not match _meta protocol version "%s".', $header_version, $version );
	}
	$header_method = (string) $request->get_header( 'mcp_method' );
	if ( $header_method !== $method ) {
		return sprintf( 'Header mismatch: Mcp-Method header "%s" does not match body method "%s".', $header_method, $method );
	}
	if ( in_array( $method, array( 'tools/call', 'prompts/get', 'resources/read' ), true ) ) {
		$field    = 'resources/read' === $method ? 'uri' : 'name';
		$expected = isset( $params[ $field ] ) ? (string) $params[ $field ] : '';
		$header   = ekwa_mcp_decode_header_value( (string) $request->get_header( 'mcp_name' ) );
		if ( $header !== $expected ) {
			return sprintf( 'Header mismatch: Mcp-Name header "%s" does not match body value "%s".', $header, $expected );
		}
	}
	return '';
}

/**
 * Handle one JSON-RPC message.
 *
 * @param mixed           $message
 * @param WP_REST_Request $request
 * @param array           $auth    `{user_id, key_id}`.
 * @return array{status:int,body:array|null}
 */
function ekwa_mcp_process( $message, $request, $auth ) {
	if ( ! is_array( $message ) ) {
		return array( 'status' => 400, 'body' => ekwa_mcp_error( null, -32600, 'Invalid Request' ) );
	}

	// A response or an error sent to us (we never ask the client anything): accept and ignore.
	if ( ! isset( $message['method'] ) && ( array_key_exists( 'result', $message ) || array_key_exists( 'error', $message ) ) ) {
		return array( 'status' => 202, 'body' => null );
	}

	$id = array_key_exists( 'id', $message ) ? $message['id'] : null;
	if ( ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] || ! isset( $message['method'] ) || ! is_string( $message['method'] ) ) {
		return array( 'status' => 400, 'body' => ekwa_mcp_error( $id, -32600, 'Invalid Request' ) );
	}

	$method = $message['method'];
	$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

	// Notifications (no id) need no answer.
	if ( ! array_key_exists( 'id', $message ) ) {
		return array( 'status' => 202, 'body' => null );
	}

	$meta           = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
	$modern_version = isset( $meta['io.modelcontextprotocol/protocolVersion'] ) ? (string) $meta['io.modelcontextprotocol/protocolVersion'] : '';
	$modern         = '' !== $modern_version;
	$client         = '';

	if ( $modern ) {
		if ( ! in_array( $modern_version, EKWA_MCP_MODERN_VERSIONS, true ) ) {
			return array(
				'status' => 400,
				'body'   => ekwa_mcp_error( $id, -32022, 'Unsupported protocol version', array( 'supported' => ekwa_mcp_supported_versions(), 'requested' => $modern_version ) ),
			);
		}
		$mismatch = ekwa_mcp_header_mismatch( $request, $method, $params, $modern_version );
		if ( '' !== $mismatch ) {
			return array( 'status' => 400, 'body' => ekwa_mcp_error( $id, -32020, $mismatch ) );
		}
		if ( isset( $meta['io.modelcontextprotocol/clientInfo'] ) && is_array( $meta['io.modelcontextprotocol/clientInfo'] ) ) {
			$client = ekwa_mcp_client_label( $meta['io.modelcontextprotocol/clientInfo'] );
		}
	} else {
		$header_version = (string) $request->get_header( 'mcp_protocol_version' );
		if ( '' !== $header_version && ! in_array( $header_version, EKWA_MCP_LEGACY_VERSIONS, true ) ) {
			if ( in_array( $header_version, EKWA_MCP_MODERN_VERSIONS, true ) ) {
				return array( 'status' => 400, 'body' => ekwa_mcp_error( $id, -32020, sprintf( 'Header mismatch: MCP-Protocol-Version header is "%s" but the request has no _meta protocol version.', $header_version ) ) );
			}
			return array(
				'status' => 400,
				'body'   => ekwa_mcp_error( $id, -32022, 'Unsupported protocol version', array( 'supported' => ekwa_mcp_supported_versions(), 'requested' => $header_version ) ),
			);
		}
	}

	if ( 'initialize' === $method && isset( $params['clientInfo'] ) && is_array( $params['clientInfo'] ) ) {
		$client = ekwa_mcp_client_label( $params['clientInfo'] );
	}
	ekwa_mcp_touch_key( $auth['user_id'], $auth['key_id'], $client );
	if ( '' === $client ) {
		// Legacy clients name themselves only in `initialize`; remembered per key.
		$seen   = ekwa_mcp_key_seen( $auth['user_id'], $auth['key_id'] );
		$client = $seen['client'];
	}

	$ctx = array(
		'modern'  => $modern,
		'user_id' => (int) $auth['user_id'],
		'client'  => $client,
	);

	// The modern revision removed the handshake and ping; a modern request may
	// only call what that revision defines.
	$modern_methods = array( 'server/discover', 'tools/list', 'tools/call', 'prompts/list', 'prompts/get', 'resources/list', 'resources/templates/list' );
	if ( $modern && ! in_array( $method, $modern_methods, true ) ) {
		return array( 'status' => 404, 'body' => ekwa_mcp_error( $id, -32601, sprintf( 'Method not found: %s', $method ) ) );
	}

	switch ( $method ) {
		case 'initialize':
			$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
			$version   = in_array( $requested, EKWA_MCP_LEGACY_VERSIONS, true ) ? $requested : EKWA_MCP_LEGACY_VERSIONS[0];
			return array(
				'status' => 200,
				'body'   => ekwa_mcp_result(
					$id,
					array(
						'protocolVersion' => $version,
						'capabilities'    => ekwa_mcp_capabilities(),
						'serverInfo'      => ekwa_mcp_server_info(),
						'instructions'    => ekwa_mcp_instructions(),
					),
					array( 'modern' => false )
				),
			);

		case 'server/discover':
			return array(
				'status' => 200,
				'body'   => ekwa_mcp_result(
					$id,
					array(
						'supportedVersions' => ekwa_mcp_supported_versions(),
						'capabilities'      => ekwa_mcp_capabilities(),
						'instructions'      => ekwa_mcp_instructions(),
					),
					array( 'modern' => true ),
					true
				),
			);

		case 'ping':
			return array( 'status' => 200, 'body' => ekwa_mcp_result( $id, array(), $ctx ) );

		case 'tools/list':
			return array( 'status' => 200, 'body' => ekwa_mcp_result( $id, array( 'tools' => ekwa_mcp_tools() ), $ctx, true ) );

		case 'prompts/list':
			return array( 'status' => 200, 'body' => ekwa_mcp_result( $id, array( 'prompts' => ekwa_mcp_prompts() ), $ctx, true ) );

		case 'resources/list':
			return array( 'status' => 200, 'body' => ekwa_mcp_result( $id, array( 'resources' => array() ), $ctx, true ) );

		case 'resources/templates/list':
			return array( 'status' => 200, 'body' => ekwa_mcp_result( $id, array( 'resourceTemplates' => array() ), $ctx, true ) );

		case 'prompts/get':
			$prompt = ekwa_mcp_prompt( isset( $params['name'] ) ? (string) $params['name'] : '' );
			if ( null === $prompt ) {
				return array( 'status' => 200, 'body' => ekwa_mcp_error( $id, -32602, 'Unknown prompt.' ) );
			}
			return array( 'status' => 200, 'body' => ekwa_mcp_result( $id, $prompt, $ctx ) );

		case 'tools/call':
			$name = isset( $params['name'] ) ? (string) $params['name'] : '';
			$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
			$out  = ekwa_mcp_call_tool( $name, $args, $ctx );
			if ( null === $out ) {
				return array( 'status' => 200, 'body' => ekwa_mcp_error( $id, -32602, sprintf( 'Unknown tool: %s', $name ) ) );
			}
			return array( 'status' => 200, 'body' => ekwa_mcp_result( $id, $out, $ctx ) );
	}

	return array(
		'status' => $modern ? 404 : 200,
		'body'   => ekwa_mcp_error( $id, -32601, sprintf( 'Method not found: %s', $method ) ),
	);
}

/**
 * "Claude Code 2.1.3" rather than "claude-code" — what the editor shows as
 * "picked up by …".
 *
 * @param array $info `{name, version, title?}` from the client.
 * @return string
 */
function ekwa_mcp_client_label( $info ) {
	$name    = isset( $info['name'] ) ? (string) $info['name'] : '';
	$title   = isset( $info['title'] ) ? (string) $info['title'] : '';
	$version = isset( $info['version'] ) ? (string) $info['version'] : '';

	$known = array(
		'claude-code'           => 'Claude Code',
		'claude-ai'             => 'Claude',
		'codex-mcp-client'      => 'Codex',
		'codex'                 => 'Codex',
		'gemini-cli-mcp-client' => 'Gemini CLI',
		'gemini-cli'            => 'Gemini CLI',
		'openai-mcp'            => 'ChatGPT',
		'chatgpt'               => 'ChatGPT',
		'mcp-remote'            => 'mcp-remote',
	);
	$key   = strtolower( $name );
	$label = isset( $known[ $key ] ) ? $known[ $key ] : ( '' !== $title ? $title : $name );
	$label = trim( $label . ( '' !== $version ? ' ' . $version : '' ) );
	$label = sanitize_text_field( $label );
	return strlen( $label ) > 60 ? substr( $label, 0, 60 ) : $label;
}

// ═══════════════════════════════════════════════════════════════════════════════
// SERVER DESCRIPTION
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * @return array{name:string,title:string,version:string}
 */
function ekwa_mcp_server_info() {
	$theme = wp_get_theme( get_template() );
	return array(
		'name'    => 'ekwa-wordpress',
		'title'   => 'Ekwa · ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'version' => (string) $theme->get( 'Version' ),
	);
}

/**
 * @return array
 */
function ekwa_mcp_capabilities() {
	return array(
		'tools'   => array( 'listChanged' => false ),
		'prompts' => array( 'listChanged' => false ),
	);
}

/**
 * Guidance for the model, sent at initialize / server/discover.
 *
 * @return string
 */
function ekwa_mcp_instructions() {
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	return 'This server connects you to the WordPress site "' . $site . '" (' . home_url( '/' ) . ') running the Ekwa theme. '
		. 'When someone on that site clicks an AI button (Generate with AI, Build with AI, Edit with AI, alt text and others) with the MCP connector turned on, the theme does not call an AI API: it queues a job here, and you act as the AI for that job. '
		. 'To complete jobs: call ekwa_get_job (it opens the oldest waiting job; if has_more is true, keep calling with the offset it gives you until you have read every part), write exactly the output the job\'s TASK RULES ask for, and deliver it with ekwa_submit_job_result. '
		. 'Reply with only the requested output: no commentary, and no Markdown code fences unless the rules ask for them. Do not change the site in any other way; WordPress checks and applies your answer itself. '
		. 'After submitting, call ekwa_wait_for_job with that job\'s request id: some requests need a follow-up step that appears within seconds. If you cannot do a job, call ekwa_fail_job with a short reason; the person sees it in the editor.';
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROMPTS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * @return array
 */
function ekwa_mcp_prompts() {
	return array(
		array(
			'name'        => 'run_jobs',
			'title'       => 'Run my pending Ekwa AI jobs',
			'description' => 'Complete every AI job waiting for you on this WordPress site.',
		),
		array(
			'name'        => 'listen',
			'title'       => 'Listen for Ekwa AI jobs',
			'description' => 'Keep waiting for AI jobs from this WordPress site and complete each one as it arrives.',
		),
	);
}

/**
 * @param string $name
 * @return array|null `{description, messages}`.
 */
function ekwa_mcp_prompt( $name ) {
	$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

	$how = "For each job:\n"
		. "1. Call ekwa_get_job. If has_more is true, keep calling it with the job_id and offset it gives you until you have read every part.\n"
		. "2. Write the answer exactly as the job's TASK RULES ask: only the answer, no commentary, no Markdown code fences unless the rules ask for them.\n"
		. "3. Deliver it with ekwa_submit_job_result.\n"
		. "4. Call ekwa_wait_for_job with request set to that job's request id and timeout_seconds 20, in case WordPress queues a follow-up step for the same request; complete it the same way.\n";

	if ( 'run_jobs' === $name ) {
		return array(
			'description' => 'Complete every AI job waiting for you on this WordPress site.',
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => 'Please complete the AI jobs waiting for me on my WordPress site "' . $site . '", using the Ekwa MCP tools.' . "\n\n" . $how
							. "\nRepeat until ekwa_get_job says no jobs are waiting, then tell me in one or two lines what you completed.",
					),
				),
			),
		);
	}

	if ( 'listen' === $name ) {
		return array(
			'description' => 'Keep waiting for AI jobs from this WordPress site and complete each one as it arrives.',
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => 'Listen for AI jobs from my WordPress site "' . $site . '" and complete them as they arrive, using the Ekwa MCP tools. '
							. 'Call ekwa_wait_for_job with timeout_seconds 25 in a loop. Whenever it reports a job, complete it:' . "\n\n" . $how
							. "\nThen go back to waiting. Keep going until I tell you to stop, and don't comment between polls.",
					),
				),
			),
		);
	}

	return null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// TOOLS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Tool definitions, in a fixed order (clients cache them).
 *
 * @return array
 */
function ekwa_mcp_tools() {
	$job_id = array(
		'type'        => 'string',
		'description' => 'The job id, for example "a1B2c3D4e5F6g7H8i9J0k1L2.1".',
	);

	return array(
		array(
			'name'        => 'ekwa_site_info',
			'title'       => 'Site info',
			'description' => 'Which WordPress site this connection is for, who you are signed in as, and how many AI jobs are waiting for you.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			'annotations' => array( 'readOnlyHint' => true, 'openWorldHint' => false ),
		),
		array(
			'name'        => 'ekwa_list_jobs',
			'title'       => 'List AI jobs',
			'description' => 'List the AI jobs waiting for you on this site, oldest first. A job is queued when someone clicks an AI button in WordPress while the MCP connector is on.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'include_finished' => array(
						'type'        => 'boolean',
						'description' => 'Also list jobs that are done, failed or cancelled.',
					),
				),
			),
			'annotations' => array( 'readOnlyHint' => true, 'openWorldHint' => false ),
		),
		array(
			'name'        => 'ekwa_get_job',
			'title'       => 'Open an AI job',
			'description' => 'Open an AI job: its full instructions (the feature\'s TASK RULES and the conversation) and any images. Omit job_id to take the oldest waiting job. Long jobs come in parts: while has_more is true, call again with the same job_id and the offset you were given. Read every part, then write the answer and deliver it with ekwa_submit_job_result.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'job_id' => $job_id,
					'offset' => array(
						'type'        => 'integer',
						'minimum'     => 0,
						'description' => 'Where to continue reading (the offset the previous part gave you). Omit for the first part.',
					),
				),
			),
			'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
		),
		array(
			'name'        => 'ekwa_submit_job_result',
			'title'       => 'Deliver an answer',
			'description' => 'Deliver your answer for an AI job to WordPress. content must be exactly the output the job\'s TASK RULES ask for: no commentary, and no Markdown code fences unless the rules ask for them. A very long answer can be sent in pieces: done=false on every piece except the last.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'job_id'  => $job_id,
					'content' => array(
						'type'        => 'string',
						'description' => 'The answer, or one piece of it.',
					),
					'done'    => array(
						'type'        => 'boolean',
						'description' => 'False when more pieces follow. Defaults to true.',
					),
				),
				'required'   => array( 'job_id', 'content' ),
			),
			'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
		),
		array(
			'name'        => 'ekwa_fail_job',
			'title'       => 'Give up on a job',
			'description' => 'Give up on an AI job you cannot complete. The reason is shown to the person waiting in WordPress.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'job_id' => $job_id,
					'reason' => array(
						'type'        => 'string',
						'description' => 'One or two sentences the person will read.',
					),
				),
				'required'   => array( 'job_id', 'reason' ),
			),
			'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ),
		),
		array(
			'name'        => 'ekwa_wait_for_job',
			'title'       => 'Wait for the next AI job',
			'description' => 'Wait up to about 25 seconds for an AI job. Use it to listen for new jobs, or right after delivering an answer with request set to that job\'s request id, because some requests need a follow-up step. Returns as soon as a job is waiting, or says that none came.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'timeout_seconds' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'description' => 'How long to wait. Defaults to 25.',
					),
					'request'         => array(
						'type'        => 'string',
						'description' => 'Only wait for follow-up steps of this request id (the part of a job id before the dot).',
					),
				),
			),
			'annotations' => array( 'readOnlyHint' => true, 'openWorldHint' => false ),
		),
	);
}

/**
 * Run a tool.
 *
 * @param string $name
 * @param array  $args
 * @param array  $ctx
 * @return array|null Tool result, or null for an unknown tool.
 */
function ekwa_mcp_call_tool( $name, $args, $ctx ) {
	switch ( $name ) {
		case 'ekwa_site_info':
			return ekwa_mcp_tool_site_info( $ctx );
		case 'ekwa_list_jobs':
			return ekwa_mcp_tool_list_jobs( $args, $ctx );
		case 'ekwa_get_job':
			return ekwa_mcp_tool_get_job( $args, $ctx );
		case 'ekwa_submit_job_result':
			return ekwa_mcp_tool_submit( $args, $ctx );
		case 'ekwa_fail_job':
			return ekwa_mcp_tool_fail( $args, $ctx );
		case 'ekwa_wait_for_job':
			return ekwa_mcp_tool_wait( $args, $ctx );
	}
	return null;
}

/**
 * A tool result: text, optionally followed by images.
 *
 * @param string $text
 * @param bool   $is_error
 * @param array  $extra More content items.
 * @return array
 */
function ekwa_mcp_tool_text( $text, $is_error = false, $extra = array() ) {
	$content = array( array( 'type' => 'text', 'text' => (string) $text ) );
	foreach ( $extra as $item ) {
		$content[] = $item;
	}
	return array(
		'content' => $content,
		'isError' => (bool) $is_error,
	);
}

/**
 * One line describing a job.
 *
 * @param array $job
 * @return string
 */
function ekwa_mcp_job_line( $job ) {
	$public = ekwa_ai_relay_public_job( $job );
	$user   = get_userdata( (int) $job['user'] );
	$line   = '- ' . $job['id'] . ' · ' . $job['label'] . ' · ' . $public['status'];
	if ( 'claimed' === $public['status'] && '' !== (string) $job['client'] ) {
		$line .= ' (by ' . $job['client'] . ')';
	}
	$line .= ' · queued ' . human_time_diff( (int) $job['created'] ) . ' ago';
	if ( $user ) {
		$line .= ' by ' . $user->display_name;
	}
	if ( (int) $job['step'] > 1 ) {
		$line .= ' · step ' . (int) $job['step'] . ' of request ' . $job['rid'];
	}
	if ( (int) $job['images'] ) {
		$line .= ' · ' . (int) $job['images'] . ' image(s)';
	}
	return $line;
}

/**
 * ekwa_site_info
 *
 * @param array $ctx
 * @return array
 */
function ekwa_mcp_tool_site_info( $ctx ) {
	$user  = get_userdata( $ctx['user_id'] );
	$open  = ekwa_ai_relay_open_jobs( $ctx['user_id'] );
	$info  = ekwa_mcp_server_info();
	$text  = 'Site: ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' — ' . home_url( '/' ) . "\n";
	$text .= 'Theme: Ekwa ' . $info['version'] . "\n";
	$text .= 'Signed in as: ' . ( $user ? $user->display_name . ' (' . $user->user_login . ')' : '?' ) . "\n";
	$text .= "AI provider: MCP connector (on)\n";
	$text .= 'Jobs waiting for you: ' . count( $open ) . "\n";
	$text .= $open
		? 'Open the oldest with ekwa_get_job.'
		: 'Wait for the next one with ekwa_wait_for_job.';
	return ekwa_mcp_tool_text( $text );
}

/**
 * ekwa_list_jobs
 *
 * @param array $args
 * @param array $ctx
 * @return array
 */
function ekwa_mcp_tool_list_jobs( $args, $ctx ) {
	$all  = ! empty( $args['include_finished'] );
	$jobs = $all ? ekwa_ai_relay_user_jobs( $ctx['user_id'] ) : ekwa_ai_relay_open_jobs( $ctx['user_id'] );
	if ( ! $jobs ) {
		return ekwa_mcp_tool_text( $all
			? 'You have no AI jobs on this site.'
			: 'No AI jobs are waiting. A job appears when someone clicks an AI button in WordPress while the MCP connector is on; ekwa_wait_for_job waits for the next one.' );
	}
	$lines = array( count( $jobs ) . ( $all ? ' job(s):' : ' job(s) waiting:' ) );
	foreach ( $jobs as $job ) {
		$lines[] = ekwa_mcp_job_line( $job );
	}
	return ekwa_mcp_tool_text( implode( "\n", $lines ) );
}

/**
 * ekwa_get_job
 *
 * @param array $args
 * @param array $ctx
 * @return array
 */
function ekwa_mcp_tool_get_job( $args, $ctx ) {
	$job_id = isset( $args['job_id'] ) ? trim( (string) $args['job_id'] ) : '';
	$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

	if ( '' !== $job_id ) {
		$job = ekwa_ai_relay_find( $ctx['user_id'], $job_id );
		if ( ! $job ) {
			return ekwa_mcp_tool_text( sprintf( 'There is no job "%s" for you on this site. It may have expired; ekwa_list_jobs shows what is waiting.', $job_id ), true );
		}
	} else {
		$open = ekwa_ai_relay_open_jobs( $ctx['user_id'] );
		if ( ! $open ) {
			return ekwa_mcp_tool_text( 'No AI jobs are waiting right now. ekwa_wait_for_job waits for the next one.' );
		}
		$job = $open[0];
	}

	if ( ekwa_ai_relay_is_abandoned( $job ) ) {
		return ekwa_mcp_tool_text( 'The person who asked for job ' . $job['id'] . ' has stopped waiting (the editor was closed or left), so it does not need an answer.', true );
	}
	if ( ! in_array( $job['status'], array( 'pending', 'claimed' ), true ) ) {
		$states = array(
			'done'      => 'already answered',
			'failed'    => 'marked as failed',
			'cancelled' => 'cancelled by the person in WordPress',
		);
		$state = isset( $states[ $job['status'] ] ) ? $states[ $job['status'] ] : $job['status'];
		return ekwa_mcp_tool_text( 'Job ' . $job['id'] . ' is ' . $state . '; there is nothing to do for it.' );
	}

	$job = ekwa_ai_relay_claim( $job, $ctx['client'] );

	$text  = ekwa_ai_relay_job_text( $job );
	$total = mb_strlen( $text, 'UTF-8' );
	if ( $offset >= $total ) {
		return ekwa_mcp_tool_text( sprintf( 'Offset %d is past the end of job %s (%d characters), so you have read all of it. Write the answer now and deliver it with ekwa_submit_job_result.', $offset, $job['id'], $total ) );
	}
	$part = mb_substr( $text, $offset, EKWA_MCP_PART_CHARS, 'UTF-8' );
	$next = $offset + mb_strlen( $part, 'UTF-8' );
	$more = $next < $total;

	$head = sprintf(
		"Job %s · %s · queued %s ago · characters %d–%d of %d · has_more: %s\n",
		$job['id'],
		$job['label'],
		human_time_diff( (int) $job['created'] ),
		$offset,
		$next,
		$total,
		$more ? 'true' : 'false'
	);
	if ( $more ) {
		$head .= sprintf( "MORE TO READ: call ekwa_get_job with job_id \"%s\" and offset %d for the next part. Do not answer before you have read every part.\n", $job['id'], $next );
	}
	$head .= "\n";

	$tail = $more
		? ''
		: "\n(End of job. Write the answer now and deliver it with ekwa_submit_job_result, job_id \"" . $job['id'] . "\".)";

	$images = array();
	if ( 0 === $offset ) {
		foreach ( ekwa_ai_relay_job_images( $job ) as $image ) {
			$images[] = array(
				'type'     => 'image',
				'data'     => $image['data'],
				'mimeType' => $image['mime'],
			);
		}
	}

	return ekwa_mcp_tool_text( $head . $part . $tail, false, $images );
}

/**
 * ekwa_submit_job_result
 *
 * @param array $args
 * @param array $ctx
 * @return array
 */
function ekwa_mcp_tool_submit( $args, $ctx ) {
	$job_id = isset( $args['job_id'] ) ? trim( (string) $args['job_id'] ) : '';
	$job    = ekwa_ai_relay_find( $ctx['user_id'], $job_id );
	if ( ! $job ) {
		return ekwa_mcp_tool_text( sprintf( 'There is no job "%s" for you on this site. It may have expired.', $job_id ), true );
	}
	if ( ! isset( $args['content'] ) || ! is_string( $args['content'] ) ) {
		return ekwa_mcp_tool_text( 'content is required: the answer as a string.', true );
	}

	$done = ! ( isset( $args['done'] ) && in_array( $args['done'], array( false, 'false', 0, '0' ), true ) );

	$res = ekwa_ai_relay_submit( $job, $args['content'], $done, $ctx['client'] );
	if ( is_wp_error( $res ) ) {
		return ekwa_mcp_tool_text( $res->get_error_message(), true );
	}

	if ( 'part' === $res['state'] ) {
		return ekwa_mcp_tool_text( sprintf( 'Got that piece (%d characters so far). Send the next one with done=false, or the last one with done=true.', $res['chars'] ) );
	}

	return ekwa_mcp_tool_text( sprintf(
		"Delivered (%d characters). The person's editor will pick it up in a few seconds.\nThis request may need a follow-up step: call ekwa_wait_for_job with request \"%s\" and timeout_seconds 20. If no job comes, this request is finished.",
		$res['chars'],
		$job['rid']
	) );
}

/**
 * ekwa_fail_job
 *
 * @param array $args
 * @param array $ctx
 * @return array
 */
function ekwa_mcp_tool_fail( $args, $ctx ) {
	$job_id = isset( $args['job_id'] ) ? trim( (string) $args['job_id'] ) : '';
	$job    = ekwa_ai_relay_find( $ctx['user_id'], $job_id );
	if ( ! $job ) {
		return ekwa_mcp_tool_text( sprintf( 'There is no job "%s" for you on this site.', $job_id ), true );
	}
	$res = ekwa_ai_relay_fail( $job, isset( $args['reason'] ) ? (string) $args['reason'] : '', $ctx['client'] );
	if ( is_wp_error( $res ) ) {
		return ekwa_mcp_tool_text( $res->get_error_message(), true );
	}
	return ekwa_mcp_tool_text( 'Marked as failed. The person sees: ' . $res['error'] );
}

/**
 * Longest a single ekwa_wait_for_job may hold the request, in seconds.
 *
 * 25 by default: comfortably inside common proxy and web-server timeouts. On a
 * server that will not let PHP raise its own time limit, it also stays inside
 * max_execution_time (on Windows a sleeping request counts against it).
 *
 * @return int
 */
function ekwa_mcp_wait_cap() {
	$cap = (int) apply_filters( 'ekwa_mcp_wait_max_seconds', 25 );
	$cap = max( 1, min( 50, $cap ) );

	$max_exec = (int) ini_get( 'max_execution_time' );
	$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
	$can_lift = function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true );
	if ( $max_exec > 0 && ! $can_lift ) {
		$cap = max( 1, min( $cap, $max_exec - 5 ) );
	}
	return $cap;
}

/**
 * ekwa_wait_for_job — long-poll for the next waiting job.
 *
 * @param array $args
 * @param array $ctx
 * @return array
 */
function ekwa_mcp_tool_wait( $args, $ctx ) {
	// One budget for the whole HTTP request, so a batch of waits cannot hold
	// a worker for longer than a single wait would.
	static $budget = null;
	if ( null === $budget ) {
		$budget = ekwa_mcp_wait_cap();
	}

	$timeout = isset( $args['timeout_seconds'] ) ? (int) $args['timeout_seconds'] : 25;
	$timeout = max( 0, min( $timeout, ekwa_mcp_wait_cap(), $budget ) );
	$rid     = isset( $args['request'] ) && is_string( $args['request'] ) && preg_match( '/^[A-Za-z0-9]{12,40}$/', $args['request'] ) ? $args['request'] : '';

	// One listener per person at a time: a second connection waiting in
	// parallel would only tie up another PHP worker for nothing.
	$lock = ekwa_ai_lock( 'mcp-wait:' . (int) $ctx['user_id'], 0 );
	if ( '' === $lock ) {
		$timeout = 0;
	}

	if ( $timeout > 0 && function_exists( 'set_time_limit' ) ) {
		@set_time_limit( $timeout + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled; the cap already allows for that.
	}

	$started  = microtime( true );
	$deadline = $started + $timeout;
	$names    = null;

	try {
		return ekwa_mcp_wait_loop( $ctx, $rid, $deadline, $names );
	} finally {
		$budget = max( 0, $budget - (int) ceil( microtime( true ) - $started ) );
		ekwa_ai_unlock( $lock );
	}
}

/**
 * The polling loop of ekwa_wait_for_job.
 *
 * @param array      $ctx
 * @param string     $rid      Only this request's jobs; '' for all.
 * @param float      $deadline microtime() to stop at.
 * @param array|null $names    Job rows seen last time.
 * @return array Tool result.
 */
function ekwa_mcp_wait_loop( $ctx, $rid, $deadline, $names ) {
	while ( true ) {
		// Re-read the jobs only when the set of job rows changed: each row holds
		// a whole prompt, so unserializing them every second would be wasteful.
		$now_names = ekwa_mcp_job_names( $ctx['user_id'] );
		if ( null === $names || $now_names !== $names ) {
			$names = $now_names;
			$open  = ekwa_ai_relay_open_jobs( $ctx['user_id'], $rid );
			if ( $open ) {
				$job = $open[0];
				return ekwa_mcp_tool_text(
					"A job is waiting:\n" . ekwa_mcp_job_line( $job ) . "\nOpen it with ekwa_get_job (job_id \"" . $job['id'] . '").'
				);
			}
		}
		if ( microtime( true ) >= $deadline ) {
			break;
		}
		sleep( 1 );
	}

	return ekwa_mcp_tool_text( '' !== $rid
		? 'No follow-up step was queued for request ' . $rid . ', so that request is finished.'
		: 'No job yet. Call ekwa_wait_for_job again to keep listening.' );
}

/**
 * The names of a user's job rows — cheap to read every second.
 *
 * @param int $user_id
 * @return string[]
 */
function ekwa_mcp_job_names( $user_id ) {
	global $wpdb;
	return (array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC",
			$wpdb->esc_like( EKWA_AI_RELAY_JOB_PREFIX . (int) $user_id . '_' ) . '%'
		)
	);
}
