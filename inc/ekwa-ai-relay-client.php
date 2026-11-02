<?php
/**
 * MCP connector — loads the browser relay client (assets/js/ekwa-ai-relay.js).
 *
 * The client is what makes a relayed AI request work in the browser: it tags
 * the theme's AI requests with `X-Ekwa-AI-Relay`, waits on the job the server
 * queues (HTTP 202), shows the small "waiting for your AI app" panel, and
 * re-sends the request once the app has answered. @see inc/ekwa-ai-relay.php.
 *
 * Loaded only when the site's AI provider is the MCP connector and the current
 * person may use the AI features. On a site that keeps the default provider
 * (Gemini) nothing here adds anything to any page, so every AI request goes
 * out exactly as it did before the connector existed — the callers routed
 * through the client fall back to their old code when it is absent.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Should this page load the relay client?
 *
 * @return bool
 */
function ekwa_ai_relay_client_wanted() {
	if ( ! function_exists( 'ekwa_ai_mcp_enabled' ) || ! ekwa_ai_mcp_enabled() ) {
		return false;
	}
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$cap = function_exists( 'ekwa_ai_min_capability' ) ? ekwa_ai_min_capability() : 'edit_posts';
	return current_user_can( $cap );
}

/**
 * Register, enqueue and configure the relay client.
 *
 * @param array $deps Script dependencies for this screen.
 */
function ekwa_ai_relay_client_enqueue( $deps ) {
	if ( wp_script_is( 'ekwa-ai-relay', 'enqueued' ) ) {
		return;
	}

	$rel = 'assets/js/ekwa-ai-relay.js';
	wp_enqueue_script(
		'ekwa-ai-relay',
		get_template_directory_uri() . '/' . $rel,
		$deps,
		filemtime( get_template_directory() . '/' . $rel ),
		true
	);

	$server = function_exists( 'ekwa_mcp_suggested_server_name' ) ? ekwa_mcp_suggested_server_name() : 'ekwa';

	wp_localize_script(
		'ekwa-ai-relay',
		'ekwaAiRelayConfig',
		array(
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'restUrl'    => esc_url_raw( rest_url() ),
			'serverName' => $server,
			'connectUrl' => esc_url_raw( admin_url( 'profile.php#ekwa-mcp' ) ),
			'hasKey'     => function_exists( 'ekwa_mcp_user_has_key' ) ? ekwa_mcp_user_has_key( get_current_user_id() ) : false,
			'canGemini'  => (bool) ekwa_get_ai_api_key(),
			'strings'    => array(
				'panelLabel'   => __( 'AI requests waiting for your AI app', 'ekwa' ),
				'defaultLabel' => __( 'AI request', 'ekwa' ),
				'waiting'      => __( 'Waiting for your AI app…', 'ekwa' ),
				/* translators: %s: name and version of the AI app, e.g. "Codex 0.166.0". */
				'claimedBy'    => __( 'Picked up by %s…', 'ekwa' ),
				'claimedAnon'  => __( 'Picked up by your AI app…', 'ekwa' ),
				'finishing'    => __( 'Finishing…', 'ekwa' ),
				'askingGemini' => __( 'Asking Gemini instead…', 'ekwa' ),
				'cancelling'   => __( 'Cancelling…', 'ekwa' ),
				'offline'      => __( 'Cannot reach the site — still trying…', 'ekwa' ),
				'askApp'       => __( 'In your AI app, say:', 'ekwa' ),
				'instruction'  => __( 'Run my pending Ekwa AI jobs', 'ekwa' ),
				/* translators: 1: the run_jobs slash command, 2: the listen slash command. */
				'hint'         => __( 'In Claude Code: %1$s — or start %2$s once to have jobs picked up automatically', 'ekwa' ),
				'copy'         => __( 'Copy', 'ekwa' ),
				'copied'       => __( 'Copied', 'ekwa' ),
				'copyLabel'    => __( 'Copy the instruction for your AI app', 'ekwa' ),
				'cancel'       => __( 'Cancel', 'ekwa' ),
				'useGemini'    => __( 'Use Gemini for this one', 'ekwa' ),
				'connect'      => __( 'Connect an AI app', 'ekwa' ),
				'noKey'        => __( 'No AI app is connected to your account yet.', 'ekwa' ),
				/* translators: %s: elapsed waiting time, e.g. "1:05". */
				'elapsed'      => __( 'Waiting for %s', 'ekwa' ),
				'cancelled'    => __( 'Cancelled.', 'ekwa' ),
				'gone'         => __( 'This AI request is no longer waiting for your AI app — it was cancelled or expired. Try again.', 'ekwa' ),
			),
		)
	);
}

/**
 * Block editor (post, page, site editor): every apiFetch-based AI feature.
 */
function ekwa_ai_relay_client_block_editor() {
	if ( ! ekwa_ai_relay_client_wanted() ) {
		return;
	}
	ekwa_ai_relay_client_enqueue( array( 'wp-api-fetch' ) );
}
add_action( 'enqueue_block_editor_assets', 'ekwa_ai_relay_client_block_editor' );

/**
 * Admin screens with AI features that do not run inside the block editor:
 * Ekwa Settings (Edit with AI, font detection), Help (docs assistant), the
 * media screens (alt text) and the profile screens (connection keys + test).
 *
 * No hard dependency on wp-api-fetch here — those screens mostly use fetch or
 * jQuery, and the client registers its apiFetch middleware whenever apiFetch
 * happens to be on the page.
 *
 * @param string $hook_suffix
 */
function ekwa_ai_relay_client_admin( $hook_suffix ) {
	$screens = array(
		'appearance_page_ekwa-settings',
		'appearance_page_ekwa-help',
		'upload.php',
		'media-new.php',
		'profile.php',
		'user-edit.php',
	);
	if ( ! in_array( $hook_suffix, $screens, true ) || ! ekwa_ai_relay_client_wanted() ) {
		return;
	}
	ekwa_ai_relay_client_enqueue( array() );
}
add_action( 'admin_enqueue_scripts', 'ekwa_ai_relay_client_admin' );
