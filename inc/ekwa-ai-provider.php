<?php
/**
 * AI provider — which engine answers the theme's AI features.
 *
 * Two providers:
 *   'gemini'  The theme calls Google's Gemini API with the site's key. This is
 *             the default and what every site did before this file existed: a
 *             site that never saved the option is on 'gemini', and nothing in
 *             this file changes its behaviour.
 *   'mcp'     The "MCP connector". The person's own AI app — Claude Code,
 *             Claude Desktop / claude.ai, Codex / ChatGPT, Gemini CLI — connects
 *             to this site over MCP and does the AI work on their own
 *             subscription, with no API key. WordPress cannot call that app (in
 *             MCP the app is the client), so a call is queued as a job that the
 *             app picks up and answers. @see inc/ekwa-ai-relay.php and
 *             inc/ekwa-mcp-server.php.
 *
 * The provider only changes when an administrator saves Ekwa Settings → AI.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/ekwa-ai-shared.php';

/**
 * The site's AI provider.
 *
 * @return string 'gemini' (also when the option was never saved) or 'mcp'.
 */
function ekwa_ai_provider() {
	return 'mcp' === get_option( 'ekwa_ai_provider', 'gemini' ) ? 'mcp' : 'gemini';
}

/**
 * Is the MCP connector the site's provider?
 *
 * @return bool
 */
function ekwa_ai_mcp_enabled() {
	return 'mcp' === ekwa_ai_provider();
}

/**
 * Sanitize callback for the provider option. Anything unknown is Gemini.
 *
 * @param mixed $value Submitted value.
 * @return string
 */
function ekwa_ai_sanitize_provider( $value ) {
	return 'mcp' === $value ? 'mcp' : 'gemini';
}

/**
 * Can the interactive AI features run at all?
 *
 * The gate the AI buttons and handlers used to express as "is there a Gemini
 * key". With the provider option absent this returns exactly what
 * `(bool) ekwa_get_ai_api_key()` returned, so a site that has not chosen the
 * MCP connector sees no difference anywhere this replaces that check.
 *
 * @return bool
 */
function ekwa_ai_is_configured() {
	if ( ekwa_get_ai_api_key() ) {
		return true;
	}
	return ekwa_ai_mcp_enabled();
}

/**
 * Which engine will answer this person's AI requests from the editor.
 *
 * 'mcp' when the connector is on and either they have connected an AI app or
 * the site has no Gemini key to fall back to; otherwise 'gemini'. Mirrors the
 * per-user half of ekwa_ai_relay_applies(), for UI that should only change for
 * people whose requests will actually go to their AI app (the model pickers).
 *
 * @param int $user_id Defaults to the current user.
 * @return string 'gemini' or 'mcp'.
 */
function ekwa_ai_effective_provider( $user_id = 0 ) {
	if ( ! ekwa_ai_mcp_enabled() ) {
		return 'gemini';
	}
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( ! ekwa_get_ai_api_key() ) {
		return 'mcp';
	}
	return function_exists( 'ekwa_mcp_user_has_key' ) && ekwa_mcp_user_has_key( $user_id ) ? 'mcp' : 'gemini';
}

/**
 * The relay id the browser relay client sent with this request, or ''.
 *
 * Only assets/js/ekwa-ai-relay.js sends the header, and only when the MCP
 * connector is on — so its presence also means "the page can wait for a job
 * and re-send this request when the answer is in". Anything that does not send
 * it (a background job, a script from before the connector existed) is never
 * relayed.
 *
 * @return string
 */
function ekwa_ai_relay_request_id() {
	$raw = isset( $_SERVER['HTTP_X_EKWA_AI_RELAY'] ) ? (string) $_SERVER['HTTP_X_EKWA_AI_RELAY'] : '';
	return preg_match( '/^[A-Za-z0-9]{12,40}$/', $raw ) ? $raw : '';
}

/**
 * The provider the browser asked for on this one request, or ''.
 *
 * The waiting panel's "Use Gemini for this one" button re-sends the request
 * with `X-Ekwa-AI-Provider: gemini`.
 *
 * @return string
 */
function ekwa_ai_relay_requested_provider() {
	$raw = isset( $_SERVER['HTTP_X_EKWA_AI_PROVIDER'] ) ? strtolower( trim( (string) $_SERVER['HTTP_X_EKWA_AI_PROVIDER'] ) ) : '';
	return 'gemini' === $raw ? 'gemini' : '';
}

/**
 * Should this AI call be handed to the person's AI app instead of Gemini?
 *
 * Every condition has to hold:
 *  - the site's provider is the MCP connector;
 *  - the caller did not mark the call `relay => false` (helper passes that run
 *    after a request has already written data, or in cron, never relay);
 *  - the browser relay client sent this request, so something is waiting to
 *    re-send it;
 *  - someone is signed in (jobs belong to a user);
 *  - and, when the site ALSO has a Gemini key: this person has connected an AI
 *    app and did not ask for Gemini on this request. Mixed teams keep working —
 *    whoever never connected an app is answered by the site's key.
 *
 * @param array $options The choke point's `$options` argument.
 * @return bool
 */
function ekwa_ai_relay_applies( $options = array() ) {
	if ( ! ekwa_ai_mcp_enabled() ) {
		return false;
	}
	if ( is_array( $options ) && array_key_exists( 'relay', $options ) && false === $options['relay'] ) {
		return false;
	}
	if ( '' === ekwa_ai_relay_request_id() ) {
		return false;
	}

	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}

	if ( ekwa_get_ai_api_key() ) {
		if ( 'gemini' === ekwa_ai_relay_requested_provider() ) {
			return false;
		}
		if ( function_exists( 'ekwa_mcp_user_has_key' ) && ! ekwa_mcp_user_has_key( $user_id ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Take a short-lived MySQL named lock, for read-modify-write of the connector's
 * own option rows (a job, a user's key list). Several processes touch the same
 * row — the waiting editor, one or more AI apps — so each change re-reads the
 * row inside the lock instead of saving a copy read earlier.
 *
 * @param string $key     What is being locked (scoped to this site).
 * @param int    $timeout Seconds to wait for it; 0 to give up at once.
 * @return string The lock's name to release, or '' when it was not obtained.
 */
function ekwa_ai_lock( $key, $timeout = 10 ) {
	global $wpdb;
	$name = 'ekwa_' . md5( $wpdb->prefix . '|' . $key );
	$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, (int) $timeout ) );
	return '1' === (string) $got ? $name : '';
}

/**
 * Release a lock from ekwa_ai_lock().
 *
 * @param string $name
 */
function ekwa_ai_unlock( $name ) {
	global $wpdb;
	if ( '' !== (string) $name ) {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}
}

/**
 * A value that stays the same every time the relay re-sends this request, or
 * '' when the request is not relayed.
 *
 * For code that mints random names BEFORE the AI call (pattern replication's
 * scope classes): the answer stored for the first send was written against the
 * first send's names, so the re-send has to mint the same ones. Not relayed —
 * the Gemini path — returns '' and callers keep their random names.
 *
 * @return string
 */
function ekwa_ai_relay_seed() {
	return ekwa_ai_relay_applies() ? ekwa_ai_relay_request_id() : '';
}
