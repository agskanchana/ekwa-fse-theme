<?php
/**
 * MCP connector — admin UI: connection keys on the profile screen and the
 * connector panel in Ekwa Settings → AI.
 *
 * One component serves both screens (ekwa_mcp_admin_render_component()): the
 * PHP renders an empty shell carrying the facts it needs as JSON, and
 * assets/js/ekwa-mcp-admin.js builds the key table, the create form, the
 * one-time key display and the per-app setup instructions from it. Building the
 * instructions in the browser is deliberate — right after "Create" they must
 * contain the new key, which exists only in that one REST response and is never
 * sent to the page again.
 *
 * Nothing here shows on a site that has not chosen the MCP connector — its
 * profile screens stay exactly as they were — except the key list for anyone
 * who still holds keys from when it was on, so they can revoke them. The
 * connector is discovered in Ekwa Settings → AI.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The capability the AI features require, with the same fallback the key and
 * relay routes use when the governance module is missing.
 *
 * @return string
 */
function ekwa_mcp_admin_ai_capability() {
	return function_exists( 'ekwa_ai_min_capability' ) ? ekwa_ai_min_capability() : 'edit_posts';
}

/**
 * What the profile screen should show about a user's connection keys.
 *
 * @param int $user_id The profile being viewed.
 * @return string 'full' (the key component) or '' (nothing).
 */
function ekwa_mcp_admin_profile_mode( $user_id ) {
	$user_id = (int) $user_id;
	$user    = $user_id ? get_userdata( $user_id ) : false;
	if ( ! $user || ! function_exists( 'ekwa_mcp_user_has_key' ) ) {
		return '';
	}

	$is_self = get_current_user_id() === $user_id;
	if ( ! $is_self && ! current_user_can( 'edit_user', $user_id ) ) {
		return '';
	}

	$can_ai   = user_can( $user, ekwa_mcp_admin_ai_capability() );
	$has_keys = ekwa_mcp_user_has_key( $user_id );

	// The key routes let you manage your OWN keys only while your role may use
	// AI, but let anyone who can edit you list and revoke them. Showing a list
	// the viewer could not act on would only produce errors.
	$can_manage = $is_self ? $can_ai : true;

	if ( ekwa_ai_mcp_enabled() ) {
		// Someone without AI access but with leftover keys (their role was
		// lowered) still gets listed for the person editing them, so the keys
		// can be revoked.
		return $can_manage && ( $can_ai || $has_keys ) ? 'full' : '';
	}

	// Connector off: keys left from when it was on stay visible so they can be
	// revoked; otherwise nothing, so an existing site's profile is unchanged.
	return $has_keys && $can_manage ? 'full' : '';
}

// ═══════════════════════════════════════════════════════════════════════════════
// ASSETS
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_enqueue_scripts', 'ekwa_mcp_admin_enqueue' );

/**
 * Enqueue the key component on the screens that render it — and only when they
 * do, so a site that never chose the connector loads nothing new.
 *
 * @param string $hook Admin page hook.
 */
function ekwa_mcp_admin_enqueue( $hook ) {
	if ( 'appearance_page_ekwa-settings' === $hook ) {
		if ( ! current_user_can( 'manage_options' ) || ! ekwa_ai_mcp_enabled() ) {
			return;
		}
	} elseif ( 'profile.php' === $hook || 'user-edit.php' === $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which profile is on screen.
		$user_id = ( 'user-edit.php' === $hook && ! empty( $_GET['user_id'] ) ) ? absint( $_GET['user_id'] ) : get_current_user_id();
		if ( 'full' !== ekwa_mcp_admin_profile_mode( $user_id ) ) {
			return;
		}
	} else {
		return;
	}

	wp_enqueue_style(
		'ekwa-mcp-admin',
		get_template_directory_uri() . '/assets/css/ekwa-mcp-admin.css',
		array(),
		filemtime( get_template_directory() . '/assets/css/ekwa-mcp-admin.css' )
	);
	wp_enqueue_script(
		'ekwa-mcp-admin',
		get_template_directory_uri() . '/assets/js/ekwa-mcp-admin.js',
		array(),
		filemtime( get_template_directory() . '/assets/js/ekwa-mcp-admin.js' ),
		true
	);
	wp_localize_script( 'ekwa-mcp-admin', 'ekwaMcpAdmin', array(
		'keysUrl'  => esc_url_raw( rest_url( 'ekwa/v1/mcp-keys' ) ),
		'jobsUrl'  => esc_url_raw( rest_url( 'ekwa/v1/ai-relay/jobs' ) ),
		'testUrl'  => esc_url_raw( rest_url( 'ekwa/v1/ai-relay/test' ) ),
		'nonce'    => wp_create_nonce( 'wp_rest' ),
		'strings'  => ekwa_mcp_admin_strings(),
	) );
}

/**
 * Every string the component shows, translated here because the script has no
 * build step (and so no wp.i18n extraction).
 *
 * @return array
 */
function ekwa_mcp_admin_strings() {
	return array(
		// Key table.
		'colLabel'        => __( 'Label', 'ekwa' ),
		'colCreated'      => __( 'Created', 'ekwa' ),
		'colLastUsed'     => __( 'Last used', 'ekwa' ),
		'colLastApp'      => __( 'Last app', 'ekwa' ),
		'never'           => __( 'never', 'ekwa' ),
		'noKeys'          => __( 'No connection keys yet.', 'ekwa' ),
		'noKeysOther'     => __( 'This user has no connection keys.', 'ekwa' ),
		'revoke'          => __( 'Revoke', 'ekwa' ),
		'revokeConfirm'   => __( 'Click again to revoke', 'ekwa' ),
		'revoking'        => __( 'Revoking…', 'ekwa' ),
		'revoked'         => __( 'Key revoked. Any AI app using it can no longer reach this site.', 'ekwa' ),

		// Create.
		'createHeading'   => __( 'Create a connection key', 'ekwa' ),
		'createIntro'     => __( 'Make one key per AI app or computer, so you can revoke one without disconnecting the others.', 'ekwa' ),
		'labelPlaceholder'=> __( 'Label (optional), e.g. "Claude Code on my laptop"', 'ekwa' ),
		'labelA11y'       => __( 'Key label', 'ekwa' ),
		'create'          => __( 'Create connection key', 'ekwa' ),
		'creating'        => __( 'Creating…', 'ekwa' ),

		// The one-time display.
		'newHeading'      => __( 'Your new connection key — copy it now', 'ekwa' ),
		'newWarning'      => __( 'This is the only time the key is shown. The site keeps only a fingerprint of it, so it cannot be shown again. If you lose it, revoke it and create another.', 'ekwa' ),
		'keyLabel'        => __( 'Key', 'ekwa' ),
		'urlLabel'        => __( 'Connection URL', 'ekwa' ),
		'urlWarning'      => __( 'The URL contains the key — treat this URL like a password.', 'ekwa' ),
		'hideKey'         => __( 'I have copied it — hide the key', 'ekwa' ),
		'copy'            => __( 'Copy', 'ekwa' ),
		'copied'          => __( 'Copied', 'ekwa' ),
		'copyFailed'      => __( 'Select and copy it manually', 'ekwa' ),

		// Setup instructions.
		'setupHeading'    => __( 'Connect your AI app', 'ekwa' ),
		'pickHeading'     => __( 'Which AI app will you use? Pick one to see its setup steps.', 'ekwa' ),
		'setupIntroKey'   => __( 'The commands below already contain your new key.', 'ekwa' ),
		'setupIntroNoKey' => __( 'Replace <YOUR-KEY> with the connection key you copied when you created it. Lost it? Create a new key — this box then fills in the real values.', 'ekwa' ),
		'keyPlaceholder'  => '<YOUR-KEY>',
		'localNotice'     => __( 'This site is only reachable from this computer, so claude.ai and ChatGPT cannot connect to it. Use Claude Code, Codex, Gemini CLI or Claude Desktop.', 'ekwa' ),
		'unavailable'     => __( 'Not available for this site', 'ekwa' ),
		'unavailableWhy'  => __( 'It connects from the internet, and this site is only reachable from this computer.', 'ekwa' ),
		'appClaudeCode'   => __( 'Claude Code', 'ekwa' ),
		'claudeCodeStep'  => __( 'Run in a terminal:', 'ekwa' ),
		'appCodex'        => __( 'Codex (CLI, IDE and app)', 'ekwa' ),
		'codexStep'       => __( 'Run in a terminal — the Codex IDE extension and app read the same configuration:', 'ekwa' ),
		'appGemini'       => __( 'Gemini CLI', 'ekwa' ),
		'geminiStep'      => __( 'Run in a terminal:', 'ekwa' ),
		'appDesktop'      => __( 'Claude Desktop', 'ekwa' ),
		'desktopLocal'    => __( 'Add this to claude_desktop_config.json (Settings → Developer → Edit Config), then restart Claude Desktop. It needs Node.js installed.', 'ekwa' ),
		/* translators: %s: the site name. */
		'desktopLive'     => __( 'Settings → Connectors → Add custom connector. Name: %s. URL:', 'ekwa' ),
		'appClaudeAi'     => __( 'claude.ai (web)', 'ekwa' ),
		'claudeAiStep'    => __( 'Settings → Connectors → Add custom connector. URL:', 'ekwa' ),
		'appChatGpt'      => __( 'ChatGPT (developer mode)', 'ekwa' ),
		'chatGptStep'     => __( 'Settings → Apps & Connectors → Advanced → turn on Developer mode → Create. URL:', 'ekwa' ),
		'chatGptAuth'     => __( 'Authentication: No authentication (the key is in the URL).', 'ekwa' ),

		// Using it.
		'usingHeading'    => __( 'Using it', 'ekwa' ),
		'usingAny'        => __( 'Keep your AI app open. When you click an AI button in WordPress, the job waits for it. In your AI app, say:', 'ekwa' ),
		'usingPhrase'     => __( 'Run my pending Ekwa AI jobs', 'ekwa' ),
		/* translators: %s: a slash command, e.g. /mcp__ekwa-site__run_jobs */
		'usingClaudeRun'  => __( 'In Claude Code you can type %s instead,', 'ekwa' ),
		/* translators: %s: a slash command, e.g. /mcp__ekwa-site__listen */
		'usingClaudeListen' => __( 'or %s once to have every new job picked up automatically.', 'ekwa' ),

		// Connector off.
		'offNotice'       => __( 'The MCP connector is turned off on this site, so these keys do nothing until an administrator turns it back on. You can still revoke them.', 'ekwa' ),

		// Test job.
		'testHeading'     => __( 'Test the connection', 'ekwa' ),
		'testIntro'       => __( 'Sends a tiny job to your AI app. Ask your app to run your Ekwa AI jobs (or have it listening) and it should answer within moments.', 'ekwa' ),
		'test'            => __( 'Send a test job', 'ekwa' ),
		'testing'         => __( 'Waiting for your AI app…', 'ekwa' ),
		'testNoRelay'     => __( 'The AI job helper did not load on this page. Reload the page and try again.', 'ekwa' ),
		/* translators: %s: the AI app's reply. */
		'testReply'       => __( 'Reply: %s', 'ekwa' ),

		// Jobs.
		'jobsHeading'     => __( 'Your recent AI jobs', 'ekwa' ),
		'jobsEmpty'       => __( 'No AI jobs yet.', 'ekwa' ),
		'jobsRefresh'     => __( 'Refresh', 'ekwa' ),
		'jobsClear'       => __( 'Clear', 'ekwa' ),
		'jobsClearConfirm'=> __( 'Click again to clear all', 'ekwa' ),
		'jobsCleared'     => __( 'Your AI jobs were cleared.', 'ekwa' ),
		'colStatus'       => __( 'Status', 'ekwa' ),
		'colJob'          => __( 'Job', 'ekwa' ),
		'colApp'          => __( 'App', 'ekwa' ),
		'colAge'          => __( 'Age', 'ekwa' ),
		'statuses'        => array(
			'pending'   => __( 'Waiting for your AI app', 'ekwa' ),
			'claimed'   => __( 'Your AI app is working on it', 'ekwa' ),
			'done'      => __( 'Done', 'ekwa' ),
			'failed'    => __( 'Failed', 'ekwa' ),
			'cancelled' => __( 'Cancelled', 'ekwa' ),
			'abandoned' => __( 'Abandoned', 'ekwa' ),
		),

		// Times. Abbreviated units sidestep plural forms, which a script without
		// wp.i18n cannot pick per language.
		'justNow'         => __( 'just now', 'ekwa' ),
		/* translators: %d: number of minutes. */
		'minutesAgo'      => __( '%d min ago', 'ekwa' ),
		/* translators: %d: number of hours. */
		'hoursAgo'        => __( '%d h ago', 'ekwa' ),
		/* translators: %d: number of days. */
		'daysAgo'         => __( '%d d ago', 'ekwa' ),

		'error'           => __( 'Request failed. Check the connection and try again.', 'ekwa' ),
		'loading'         => __( 'Loading…', 'ekwa' ),
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// THE SHARED COMPONENT
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Render the connection-key component (keys, create, setup, and optionally the
 * test button and the job list). The script fills it in.
 *
 * @param array $args {
 *     @type int  $user_id   Whose keys. Default: the current user.
 *     @type bool $show_test Show "Send a test job" (own keys, connector on only).
 *     @type bool $show_jobs Show "Your recent AI jobs" (own keys, connector on only).
 * }
 */
function ekwa_mcp_admin_render_component( $args = array() ) {
	if ( ! function_exists( 'ekwa_mcp_get_keys' ) || ! function_exists( 'ekwa_mcp_rest_site_facts' ) ) {
		return;
	}

	$args    = wp_parse_args( $args, array(
		'user_id'   => get_current_user_id(),
		'show_test' => false,
		'show_jobs' => false,
	) );
	$user_id = (int) $args['user_id'];
	$is_self = get_current_user_id() === $user_id;
	$enabled = ekwa_ai_mcp_enabled();
	$can_ai  = current_user_can( ekwa_mcp_admin_ai_capability() );

	// The same shape GET /mcp-keys returns, so the first paint needs no request.
	$keys = array();
	foreach ( ekwa_mcp_get_keys( $user_id ) as $record ) {
		$keys[] = ekwa_mcp_public_key( $user_id, $record );
	}

	$config = array(
		'userId'    => $user_id,
		'isSelf'    => $is_self,
		// Keys are made only by the person who will use them (the create route
		// refuses anyone else), and only while there is a connector to use them.
		'canCreate' => $is_self && $enabled && $can_ai,
		'showSetup' => $is_self && $enabled && $can_ai,
		'showTest'  => ! empty( $args['show_test'] ) && $is_self && $enabled && $can_ai,
		'showJobs'  => ! empty( $args['show_jobs'] ) && $is_self && $enabled && $can_ai,
		// Server clock, so "5 min ago" is right on a computer whose clock is off.
		'now'       => time(),
		'data'      => array_merge( ekwa_mcp_rest_site_facts(), array( 'keys' => $keys ) ),
	);
	?>
	<div class="ekwa-mcp" data-ekwa-mcp="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
		<p class="ekwa-mcp__loading description"><?php esc_html_e( 'Loading…', 'ekwa' ); ?></p>
		<noscript><p class="description"><?php esc_html_e( 'Managing connection keys needs JavaScript.', 'ekwa' ); ?></p></noscript>
	</div>
	<?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROFILE SCREEN
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'show_user_profile', 'ekwa_mcp_admin_profile_section' );
add_action( 'edit_user_profile', 'ekwa_mcp_admin_profile_section' );

/**
 * The "Ekwa AI connector" section on Users → Profile / Edit User.
 *
 * Both hooks print inside the profile <form>; every control the component adds
 * is a type="button" or a nameless input, so saving the profile is unaffected.
 *
 * @param WP_User $user The profile being viewed.
 */
function ekwa_mcp_admin_profile_section( $user ) {
	if ( ! $user instanceof WP_User ) {
		return;
	}
	$mode = ekwa_mcp_admin_profile_mode( $user->ID );
	if ( '' === $mode ) {
		return;
	}
	$is_self = get_current_user_id() === (int) $user->ID;
	?>
	<div id="ekwa-mcp" class="ekwa-mcp-profile">
		<h2><?php esc_html_e( 'Ekwa AI connector', 'ekwa' ); ?></h2>
		<p class="description">
			<?php
			if ( $is_self ) {
				esc_html_e( 'A connection key lets your own AI app (Claude, ChatGPT/Codex or Gemini) answer the AI features on this site for you, on your own subscription. A key can only read and answer your AI jobs — it cannot sign in or change anything else.', 'ekwa' );
			} else {
				esc_html_e( 'Connection keys this user created for their AI apps. Only they can create keys; you can revoke them.', 'ekwa' );
			}
			?>
		</p>
		<?php
		ekwa_mcp_admin_render_component( array(
			'user_id'   => (int) $user->ID,
			// The relay client is loaded here too, so the owner can check their app
			// without going to the (admin-only) settings page.
			'show_test' => $is_self,
		) );
		?>
	</div>
	<?php
}

// ═══════════════════════════════════════════════════════════════════════════════
// SETTINGS → AI
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * The "MCP connector" panel in Ekwa Settings → AI. Called from
 * ekwa_render_settings_page(); printed inside the settings <form>, so — as on
 * the profile screen — nothing in it carries a name and nothing is submitted.
 */
function ekwa_mcp_admin_settings_panel() {
	if ( ! ekwa_ai_mcp_enabled() ) {
		?>
		<p class="description" id="ekwa-mcp-off-note"><?php esc_html_e( 'Choose "MCP connector" above and save to set it up.', 'ekwa' ); ?></p>
		<div class="notice notice-warning inline" id="ekwa-mcp-save-note" style="display:none;">
			<p><strong><?php esc_html_e( 'Now click "Save Changes" at the bottom of the page.', 'ekwa' ); ?></strong>
			<?php esc_html_e( 'The connector turns on when you save. This panel then lets you create your connection key and shows the setup steps for your AI app: Claude Code, Codex, Gemini CLI, Claude Desktop, claude.ai or ChatGPT.', 'ekwa' ); ?></p>
		</div>
		<script>
		( function () {
			var radios = document.querySelectorAll( 'input[name="ekwa_ai_provider"]' );
			function sync() {
				var mcp = document.querySelector( 'input[name="ekwa_ai_provider"][value="mcp"]' );
				var on  = !! ( mcp && mcp.checked );
				document.getElementById( 'ekwa-mcp-save-note' ).style.display = on ? '' : 'none';
				document.getElementById( 'ekwa-mcp-off-note' ).style.display = on ? 'none' : '';
			}
			for ( var i = 0; i < radios.length; i++ ) {
				radios[ i ].addEventListener( 'change', sync );
			}
			sync();
		} )();
		</script>
		<?php
		return;
	}
	?>
	<p class="description">
		<?php
		printf(
			/* translators: %s: link to the user's profile screen. */
			esc_html__( 'Everyone who uses the AI features connects their own AI app with a personal connection key, created on their %s. Yours are below.', 'ekwa' ),
			'<a href="' . esc_url( admin_url( 'profile.php#ekwa-mcp' ) ) . '">' . esc_html__( 'profile page', 'ekwa' ) . '</a>'
		);
		?>
	</p>
	<?php
	ekwa_mcp_admin_render_component( array(
		'user_id'   => get_current_user_id(),
		'show_test' => true,
		'show_jobs' => true,
	) );
}
