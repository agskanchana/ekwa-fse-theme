<?php
/**
 * Ekwa theme functions and definitions.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub auto-updater — checks agskanchana/ekwa-fse-theme for new releases.
 */
require_once get_template_directory() . '/includes/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Definable in wp-config.php to track a fork.
if ( ! defined( 'EKWA_GITHUB_REPO_URL' ) ) {
	define( 'EKWA_GITHUB_REPO_URL', 'https://github.com/agskanchana/ekwa-fse-theme/' );
}

$ekwa_theme_updater = PucFactory::buildUpdateChecker(
	EKWA_GITHUB_REPO_URL,
	get_template_directory() . '/style.css',
	'ekwa'
);

$ekwa_theme_updater->setBranch('live');

/**
 * The update checker instance, for code that runs after this file (the settings
 * screen's "Check for updates now" button).
 *
 * @return \YahnisElsts\PluginUpdateChecker\v5p6\Theme\UpdateChecker|null
 */
function ekwa_theme_updater() {
	return isset( $GLOBALS['ekwa_theme_updater'] ) ? $GLOBALS['ekwa_theme_updater'] : null;
}

/**
 * The GitHub account that owns the theme repo — the username PUC pairs with the
 * token in its Basic auth header (see ekwa_github_bearer_auth).
 *
 * @return string
 */
function ekwa_github_repo_owner() {
	$path = wp_parse_url( EKWA_GITHUB_REPO_URL, PHP_URL_PATH );
	$path = trim( (string) $path, '/' );
	$bits = explode( '/', $path );
	return isset( $bits[0] ) ? $bits[0] : '';
}

/**
 * Authenticate GitHub update checks with a Personal Access Token when one is
 * available, lifting the API limit from 60/hr (anonymous) to 5000/hr. The
 * EKWA_GITHUB_TOKEN constant (e.g. in wp-config.php) takes precedence over the
 * value stored on the settings screen.
 */
function ekwa_github_token() {
	if ( defined( 'EKWA_GITHUB_TOKEN' ) && EKWA_GITHUB_TOKEN ) {
		return (string) EKWA_GITHUB_TOKEN;
	}
	return (string) get_option( 'ekwa_github_token', '' );
}

$ekwa_github_token = ekwa_github_token();
if ( '' !== $ekwa_github_token ) {
	$ekwa_theme_updater->setAuthentication( $ekwa_github_token );
}

/**
 * Send the token as `Authorization: Bearer <token>` instead of PUC's Basic header.
 *
 * PUC authenticates as `Basic base64("<repo owner>:<token>")` — it pairs the
 * token with the username taken from the REPOSITORY URL, which is not
 * necessarily the account the token belongs to. GitHub rejects that pairing for
 * fine-grained tokens (github_pat_…) and whenever the token's owner isn't the
 * repo owner, answering 401 Bad credentials. Since an anonymous request to this
 * public repo succeeds, adding a token made update checks fail where having no
 * token worked — updates stopped appearing at all.
 *
 * Bearer is what GitHub documents for both classic and fine-grained tokens, and
 * it carries no username to mismatch.
 */
function ekwa_github_bearer_auth( $options ) {
	$token = ekwa_github_token();
	if ( '' === $token ) {
		return $options;
	}
	if ( empty( $options['headers'] ) || ! is_array( $options['headers'] ) ) {
		$options['headers'] = array();
	}
	$options['headers']['Authorization'] = 'Bearer ' . $token;
	return $options;
}
// Registered through PUC's own helper: it builds the hook name (which carries a
// "_theme" suffix for theme checkers — puc_request_update_options_theme-ekwa),
// so the filter can't quietly stop firing if that naming ever changes.
$ekwa_theme_updater->addFilter( 'request_update_options', 'ekwa_github_bearer_auth' );

/**
 * The same swap for the update DOWNLOAD, which PUC also authenticates: the zip
 * comes from api.github.com/repos/:user/:repo/zipball/:branch, so a rejected
 * header there fails the install even when the check succeeded.
 *
 * Only the exact header PUC generated for OUR token is rewritten — never a
 * header belonging to some other plugin that talks to the GitHub API.
 */
function ekwa_github_bearer_download_auth( $args, $url = '' ) {
	if ( empty( $args['headers']['Authorization'] ) ) {
		return $args;
	}
	if ( 'api.github.com' !== wp_parse_url( (string) $url, PHP_URL_HOST ) ) {
		return $args;
	}
	$token = ekwa_github_token();
	if ( '' === $token ) {
		return $args;
	}
	$puc_header = 'Basic ' . base64_encode( ekwa_github_repo_owner() . ':' . $token ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	if ( $args['headers']['Authorization'] !== $puc_header ) {
		return $args;
	}
	$args['headers']['Authorization'] = 'Bearer ' . $token;
	return $args;
}
add_filter( 'http_request_args', 'ekwa_github_bearer_download_auth', 20, 2 );

/**
 * Record why a GitHub update check failed.
 *
 * Flags a rate-limit (HTTP 403 with X-RateLimit-Remaining: 0) as before, and
 * additionally stores the last API error so the admin sees a reason instead of
 * updates silently never appearing — a rejected token answers 401 and used to
 * leave no trace anywhere.
 */
function ekwa_github_flag_rate_limit( $error, $http_response = null, $url = null, $slug = null ) {
	if ( 'ekwa' !== $slug ) {
		return;
	}

	// "Could not retrieve version information" is PUC summarising a failure it
	// already reported — recording it would bury the actual cause.
	if ( is_wp_error( $error ) && 'puc-no-update-source' === $error->get_error_code() ) {
		return;
	}

	// No HTTP response at all: the request never completed (DNS, firewall,
	// timeout, TLS). Worth recording — it looks identical to "no updates" from
	// the admin's side.
	if ( ! is_array( $http_response ) ) {
		if ( is_wp_error( $error ) ) {
			set_transient(
				'ekwa_github_last_error',
				array(
					'code'    => 0,
					'message' => $error->get_error_message(),
					'token'   => ( '' !== ekwa_github_token() ),
					'time'    => time(),
				),
				DAY_IN_SECONDS
			);
		}
		return;
	}

	$code      = (int) wp_remote_retrieve_response_code( $http_response );
	$remaining = wp_remote_retrieve_header( $http_response, 'x-ratelimit-remaining' );

	if ( 403 === $code && '0' === (string) $remaining ) {
		set_transient( 'ekwa_github_rate_limited', 1, HOUR_IN_SECONDS );
	}

	// GitHub puts a human-readable reason in the JSON body ("Bad credentials",
	// "Not Found", "API rate limit exceeded…").
	$body    = json_decode( wp_remote_retrieve_body( $http_response ) );
	$message = ( is_object( $body ) && ! empty( $body->message ) ) ? (string) $body->message : '';

	set_transient(
		'ekwa_github_last_error',
		array(
			'code'    => $code,
			'message' => $message,
			'token'   => ( '' !== ekwa_github_token() ),
			'time'    => time(),
		),
		DAY_IN_SECONDS
	);
}
add_action( 'puc_api_error', 'ekwa_github_flag_rate_limit', 10, 4 );

/**
 * Clear the recorded failure once a check succeeds, so a fixed token stops
 * showing a stale error.
 *
 * A successful check is one that came back with a version: PUC reads the
 * Version header out of style.css on the tracked branch and nulls the whole
 * update when it can't (see Vcs\ThemeUpdateChecker::requestUpdate). "Up to
 * date" still carries a version, so it counts as success — only a check that
 * never reached GitHub leaves the error in place.
 */
function ekwa_github_clear_last_error( $update, $http_result = null ) {
	if ( is_object( $update ) && ! empty( $update->version ) ) {
		delete_transient( 'ekwa_github_last_error' );
		delete_transient( 'ekwa_github_rate_limited' );
	}
	return $update;
}
$ekwa_theme_updater->addFilter( 'request_update_result', 'ekwa_github_clear_last_error', 10, 2 );

/**
 * Human-readable explanation of the last update-check failure, or '' when the
 * last check was fine.
 *
 * @return string
 */
function ekwa_github_last_error_message() {
	$last = get_transient( 'ekwa_github_last_error' );
	// isset, not empty: code 0 is a real value here — it means the request never
	// reached GitHub.
	if ( ! is_array( $last ) || ! isset( $last['code'] ) ) {
		return '';
	}

	$code   = (int) $last['code'];
	$detail = ! empty( $last['message'] ) ? ' (' . $last['message'] . ')' : '';

	if ( 0 === $code ) {
		return sprintf(
			/* translators: %s: the transport error, e.g. a cURL message. */
			__( 'This server could not reach api.github.com%s, so the update check never completed. That is usually a firewall, DNS, or an out-of-date CA certificate bundle on the server — not a theme setting.', 'ekwa' ),
			$detail
		);
	}
	if ( 401 === $code ) {
		return sprintf(
			/* translators: %s: the message GitHub returned. */
			__( 'GitHub rejected the access token%s. It is invalid, expired, or revoked — generate a new token and paste it again. Clearing the field also works: update checks fall back to anonymous requests.', 'ekwa' ),
			$detail
		);
	}
	if ( 404 === $code ) {
		return sprintf(
			/* translators: %s: the message GitHub returned. */
			__( 'GitHub could not find the theme repository%s. If the token is a fine-grained one, it must list this repository under "Repository access" with Contents: Read.', 'ekwa' ),
			$detail
		);
	}
	if ( 403 === $code ) {
		return empty( $last['token'] )
			? __( 'GitHub\'s anonymous rate limit (60 requests/hour per server) was reached. Add an access token below to raise it to 5,000/hour.', 'ekwa' )
			: sprintf(
				/* translators: %s: the message GitHub returned. */
				__( 'GitHub refused the request%s. The token may lack access to this repository.', 'ekwa' ),
				$detail
			);
	}

	return sprintf(
		/* translators: 1: HTTP status code, 2: the message GitHub returned. */
		__( 'The last update check failed — GitHub returned HTTP %1$d%2$s.', 'ekwa' ),
		$code,
		$detail
	);
}

/**
 * Admin notice when the last update check failed.
 *
 * This used to bail out whenever a token was configured, on the assumption that
 * a token can only help. A REJECTED token is the one case where updates stop
 * appearing entirely, so that early return hid the very failure worth
 * reporting — the reason is now shown either way.
 */
function ekwa_github_rate_limit_notice() {
	if ( ! current_user_can( 'update_themes' ) ) {
		return;
	}
	$message = ekwa_github_last_error_message();
	if ( '' === $message ) {
		return;
	}
	$url = admin_url( 'admin.php?page=ekwa-settings&tab=general#ekwa-theme-updates' );
	echo '<div class="notice notice-warning is-dismissible"><p>';
	printf(
		'<strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a>',
		esc_html__( 'Ekwa theme updates:', 'ekwa' ),
		esc_html( $message ),
		esc_url( $url ),
		esc_html__( 'Theme update settings', 'ekwa' )
	);
	echo '</p></div>';
}
add_action( 'admin_notices', 'ekwa_github_rate_limit_notice' );

/**
 * "Check for updates now" — clears PUC's cached state and re-runs the check.
 *
 * Without this, a token change looks like it did nothing: PUC caches the result
 * of a check (including a failed one) for 12 hours, so the Themes screen keeps
 * reporting "up to date" long after the cause was fixed.
 */
function ekwa_github_handle_manual_check() {
	if ( empty( $_GET['ekwa_check_updates'] ) ) {
		return;
	}
	if ( ! current_user_can( 'update_themes' ) ) {
		return;
	}
	check_admin_referer( 'ekwa_check_updates' );

	$updater = ekwa_theme_updater();
	$result  = 'nochecker';

	if ( $updater ) {
		delete_transient( 'ekwa_github_last_error' );
		delete_transient( 'ekwa_github_rate_limited' );
		$updater->resetUpdateState();

		$update = $updater->checkForUpdates();
		$failed = ekwa_github_last_error_message();

		if ( '' !== $failed ) {
			$result = 'failed';
		} elseif ( $update ) {
			$result = 'update';
		} else {
			$result = 'current';
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'              => 'ekwa-settings',
				'tab'               => 'general',
				'ekwa_check_result' => $result,
			),
			admin_url( 'admin.php' )
		) . '#ekwa-theme-updates'
	);
	exit;
}
add_action( 'admin_init', 'ekwa_github_handle_manual_check' );

/**
 * Load theme settings page.
 */
require_once get_template_directory() . '/inc/ekwa-settings.php';

/**
 * Location address extraction — resolves a pasted Google Maps "Direction URL"
 * and geocodes it to fill street/city/state/zip/lat/lng in the Locations tab.
 */
require_once get_template_directory() . '/inc/ekwa-location-geocode.php';

/**
 * CSS rule walker — lossless stylesheet subtraction (used to thin the Global
 * CSS pool after a section's CSS is extracted) and font-variable rewriting.
 * Loaded before tokens/fonts, which both build on it.
 */
require_once get_template_directory() . '/inc/ekwa-css-rules.php';

/**
 * Load design tokens (color/bg-image variables, saved mockup CSS, conditional
 * font breakpoint) — before fonts, which reads the breakpoint helper.
 */
require_once get_template_directory() . '/inc/ekwa-tokens.php';

/**
 * Load custom fonts registry (Fonts tab + frontend output + theme.json filter).
 */
require_once get_template_directory() . '/inc/ekwa-fonts.php';

/**
 * Load schema.org JSON-LD output (uses ekwa-settings data).
 */
require_once get_template_directory() . '/inc/ekwa-schema.php';

/**
 * Load the Schema Editor admin page (edits the template ekwa-schema.php renders).
 */
require_once get_template_directory() . '/inc/ekwa-schema-editor.php';

/**
 * Load shortcode builder admin page.
 */
require_once get_template_directory() . '/inc/ekwa-shortcode-builder.php';

/**
 * Load theme shortcodes.
 */
require_once get_template_directory() . '/inc/ekwa-shortcodes.php';

/**
 * Keep authored phone numbers in sync with Settings → Locations: swap them for
 * the [ekwa_phone] shortcode on paste and on Yoast meta save, and resolve that
 * token back to a bare number in meta tags and schema. Loaded after the
 * shortcodes it inserts.
 */
require_once get_template_directory() . '/inc/ekwa-phone-tokens.php';

/**
 * Shortcode Blocks — a post type whose block-editor content is published as a
 * shortcode ([my-slug] / [ekwa_block slug="my-slug"]). Complements the builder
 * above, which only configures the built-in [ekwa_*] data shortcodes.
 */
require_once get_template_directory() . '/inc/ekwa-shortcode-blocks.php';

/**
 * Load custom block registrations and render callbacks.
 */

require_once get_template_directory() . '/inc/ekwa-blocks.php';

/**
 * Page banner family — ekwa/page-banner, ekwa/banner-title, ekwa/breadcrumb.
 * The composable replacement for the (now inserter-hidden) ekwa/inner-banner,
 * which stays registered in ekwa-blocks.php so existing content is untouched.
 */
require_once get_template_directory() . '/inc/ekwa-page-banner.php';

/**
 * ekwa/field — print an ACF field or post meta, and render nothing at all on
 * pages where it is empty. Safe to place in a shared header/footer template.
 */
require_once get_template_directory() . '/inc/ekwa-field-block.php';

/**
 * Carry the mockup's inline styles onto the static core blocks the converter
 * emits (heading, paragraph, list, quote, table), which can't hold a style
 * attribute in their saved markup without failing block validation.
 */
require_once get_template_directory() . '/inc/ekwa-core-inline-style.php';

/**
 * Inline each block's front-end CSS/JS on render (replaces the monolithic
 * ekwa-blocks.css / ekwa-block-styles.css / ekwa-blocks.js / ekwa-faq.js).
 */
require_once get_template_directory() . '/inc/ekwa-inline-assets.php';

/**
 * Inline the active child theme's style.css / ekwa-child.js (opt-in via the
 * Performance settings tab). Lives in the parent so it works without editing
 * each child theme.
 */
require_once get_template_directory() . '/inc/ekwa-inline-child.php';

/**
 * Load WebP image support (auto-generation + transparent URL swap).
 */
require_once get_template_directory() . '/inc/ekwa-webp.php';

/**
 * "Import from URL" panel on the Media Library — paste one or more image links
 * and sideload them as normal attachments.
 */
require_once get_template_directory() . '/inc/ekwa-media-import.php';

/**
 * Disable attachment (media) pages — redirect them to the attached post or the
 * file itself instead of serving a thin one-image page.
 */
require_once get_template_directory() . '/inc/ekwa-attachment-pages.php';

/**
 * Load image performance helpers (lazy loading, hero preload, srcset).
 */
require_once get_template_directory() . '/inc/ekwa-perf.php';

/**
 * Load head-level performance hardening (critical CSS, stylesheet deferral,
 * resource hints, WP core bloat removal).
 */
require_once get_template_directory() . '/inc/ekwa-perf-head.php';

/**
 * Load block style variations.
 */
require_once get_template_directory() . '/inc/ekwa-block-styles.php';

/**
 * Load mockup converter REST API.
 */
require_once get_template_directory() . '/inc/ekwa-converter-api.php';

/**
 * Build a real WP menu from the mockup's navigation during conversion, so
 * ekwa/header-menu has something to render.
 */
require_once get_template_directory() . '/inc/ekwa-converter-menu.php';

/**
 * Translate Remix/Bootstrap/Themify/Material/… icon classes to Font Awesome,
 * the only icon font the theme loads.
 */
require_once get_template_directory() . '/inc/ekwa-converter-icons.php';

/**
 * Imported page content — parks the Bulk Page Creator CSV's `content` column on
 * each page and normalises that HTML (lazy images, phone shortcodes, internal
 * links, FAQ and video players) before the converter turns it into blocks.
 */
require_once get_template_directory() . '/inc/ekwa-import-content.php';

/**
 * Inner Page Template — the page holding one example of each section design an
 * inner page can use. Its sections are the vocabulary imported content is
 * rebuilt from, which is what makes an imported page look like it belongs here.
 * Loaded after the importer, whose conversion route calls its design pass.
 */
require_once get_template_directory() . '/inc/ekwa-inner-template.php';

/**
 * Gemini model catalog — reads the models the configured key can actually call
 * and supplies every feature's default. Loaded before the AI modules, which
 * all resolve their model through it.
 */
require_once get_template_directory() . '/inc/ekwa-ai-models.php';

/**
 * AI governance — role gating, per-user daily rate limits, usage logging,
 * payload ceilings. Loaded before the AI feature modules because they share
 * its permission callback (ekwa_ai_rest_permission).
 */
require_once get_template_directory() . '/inc/ekwa-ai-governance.php';

/**
 * Persistent AI Build/Refine sessions (conversation memory), stored per user.
 */
require_once get_template_directory() . '/inc/ekwa-ai-sessions.php';

/**
 * Load AI HTML generator for mockup converter (Gemini multimodal API).
 */
require_once get_template_directory() . '/inc/ekwa-ai-hints.php';
require_once get_template_directory() . '/inc/ekwa-ai-generate.php';

/**
 * AI Block Builder — generates Ekwa/core block markup directly (Gemini),
 * skipping the lossy HTML→block converter step.
 */
require_once get_template_directory() . '/inc/ekwa-ai-block-specs.php';
require_once get_template_directory() . '/inc/ekwa-ai-generate-blocks.php';

/**
 * AI Convert — semantic HTML → blocks with dynamic mapping (Mockup Converter).
 */
require_once get_template_directory() . '/inc/ekwa-ai-convert.php';

/**
 * AI alt-text generation for the ekwa/image block (Gemini multimodal).
 */
require_once get_template_directory() . '/inc/ekwa-ai-alt.php';

/**
 * Internal linking suggestions in the block editor (page index + Gemini helpers).
 */
require_once get_template_directory() . '/inc/ekwa-interlink.php';

/**
 * Tag external links with a descriptive title on first user interaction.
 */
require_once get_template_directory() . '/inc/ekwa-external-links.php';

/**
 * Cookie consent banner (inline CSS + JS, dismissible, 360-day cookie).
 */
require_once get_template_directory() . '/inc/ekwa-cookie-banner.php';

/**
 * Chatbot loader — injects the configured loader.js on first user interaction.
 */
require_once get_template_directory() . '/inc/ekwa-chatbot.php';

/**
 * Delayed scripts — injects the active theme's assets/js/delayed-scripts.js on
 * first user interaction.
 */
require_once get_template_directory() . '/inc/ekwa-delayed-scripts.php';

/**
 * Google Analytics — injects the gtag.js tag in <head> from a Measurement ID
 * or a full custom <script> snippet (Ekwa Settings → General).
 */
require_once get_template_directory() . '/inc/ekwa-analytics.php';

/**
 * Google Search Console site-verification meta tag in <head> (Ekwa Settings →
 * General).
 */
require_once get_template_directory() . '/inc/ekwa-google-verification.php';

/**
 * Configurable "Skip to content" link — lets authors set the target anchor
 * (Ekwa Settings → General) instead of relying on core's <main> auto-detection.
 */
require_once get_template_directory() . '/inc/ekwa-skip-link.php';

/**
 * Lightbox (GLightbox) — class-driven lightbox for images/videos; injects the
 * library CSS/JS on first user interaction. See inc/ekwa-lightbox.php for usage.
 */
require_once get_template_directory() . '/inc/ekwa-lightbox.php';

/**
 * Documentation links + the in-admin "Ask AI" docs assistant (Ekwa Settings →
 * Help). Answers from the published docs using the theme's own Gemini key.
 */
require_once get_template_directory() . '/inc/ekwa-docs-assistant.php';

/**
 * Load blog features (TOC, author link, load more, post cards).
 */
require_once get_template_directory() . '/inc/ekwa-blog.php';

/**
 * Load mobile menu: nav location, icon meta field, custom walker.
 */
require_once get_template_directory() . '/inc/ekwa-mobile-menu.php';

/**
 * Load header menu: mega-menu meta fields and custom walker.
 */
require_once get_template_directory() . '/inc/ekwa-header-menu.php';

/**
 * Load editor UX: ekwa block categories/branding, Select mode (X-ray).
 */
require_once get_template_directory() . '/inc/ekwa-editor-ux.php';

/**
 * Load responsive layer: per-block device visibility + configurable breakpoints.
 */
require_once get_template_directory() . '/inc/ekwa-responsive.php';

/**
 * Starter child-theme generator (Ekwa Settings → General).
 */
require_once get_template_directory() . '/inc/ekwa-child-generator.php';

/**
 * Copy Ekwa blocks into the active child theme so they can be edited there,
 * safe from parent-theme updates (Ekwa Settings → General). Pairs with the
 * child-first block resolver (ekwa_block_dir) in inc/ekwa-blocks.php.
 */
require_once get_template_directory() . '/inc/ekwa-block-overrides.php';

/**
 * Edit a copied child-theme block with AI (Gemini) — preview, apply-with-backup,
 * and one-click revert. Loaded after the AI modules and block-overrides so it can
 * reuse their helpers.
 */
require_once get_template_directory() . '/inc/ekwa-ai-edit-block.php';

/**
 * Editable front-end JS files (delayed-scripts.js / ekwa-child.js) in Design Setup.
 */
require_once get_template_directory() . '/inc/ekwa-js-editor.php';

/**
 * Mockup contract: canonical snippet library, readiness checker, and the
 * copyable AI authoring prompts (Ekwa Settings → Design Setup).
 */
require_once get_template_directory() . '/inc/ekwa-mockup-contract.php';

/**
 * Templated dynamic blocks — optional customTemplate attribute on the data
 * blocks so they render the mockup's own markup with live settings data.
 */
require_once get_template_directory() . '/inc/ekwa-block-templates.php';

/**
 * Ekwa Slider + Hero Video blocks (hero slider with animated content;
 * background-video hero). Assets inline per page via ekwa-inline-assets.
 */
require_once get_template_directory() . '/inc/ekwa-slider.php';

/**
 * Ekwa YouTube Video / Ekwa Vimeo Video blocks (URL → auto metadata, lazy
 * click-to-play, lightbox, transcript, Schema.org markup). Front-end CSS/JS
 * inline per page via ekwa-inline-assets.
 */
require_once get_template_directory() . '/inc/ekwa-video-embed.php';

/**
 * Enqueue theme stylesheet and Font Awesome.
 */
function ekwa_enqueue_styles() {
	// The parent theme's style.css contains only the desktop/mobile header
	// toggle. Inline it (no HTTP request) but keep the 'ekwa-style' handle
	// registered with src=false so the child's ekwa-child-style still chains
	// to it. The two media queries below are the entire contents of the former
	// assets/css/ekwa-mobile.css — inlined here so no separate request is made.
	wp_register_style( 'ekwa-style', false, array(), wp_get_theme()->get( 'Version' ) );
	wp_enqueue_style( 'ekwa-style' );
	wp_add_inline_style(
		'ekwa-style',
		// Zero WordPress' default block gap. Core's global stylesheet prints
		// `:root :where(.is-layout-flow) > *{margin-block-start:…}` (and the
		// same for `.wp-site-blocks` children), which drops a margin above every
		// flow child and every top-level block — spacing nothing in the site's
		// own stylesheet asks for, and which reads as an unexplained gap above
		// converted sections. Repeating core's selector only ties on specificity
		// and core's copy can print later in <head>, so !important is what makes
		// this stick. Section spacing comes from the site stylesheet instead.
		':root :where(.is-layout-flow) > *,:where(.wp-site-blocks) > *{margin-top:0 !important}' .
		'@media (max-width:1199px){.ekwa-desktop-header{display:none !important}}' .
		'@media (min-width:1200px){.ekwa-mobile-header{display:none !important}}'
	);
	wp_enqueue_style(
		'font-awesome',
		get_template_directory_uri() . '/assets/fontawesome/css/all.min.css',
		array(),
		'6.5.1'
	);
	// Per-block CSS/JS is no longer enqueued globally. Each block inlines its
	// own front-end CSS (blocks/<name>/style.css) and JS (blocks/<name>/view.js)
	// only when it renders — see inc/ekwa-inline-assets.php.
}
add_action( 'wp_enqueue_scripts', 'ekwa_enqueue_styles' );

/**
 * Enqueue Font Awesome in the block editor outer shell and admin pages.
 */
function ekwa_enqueue_admin_fa() {
	wp_enqueue_style(
		'font-awesome',
		get_template_directory_uri() . '/assets/fontawesome/css/all.min.css',
		array(),
		'6.5.1'
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_admin_fa' );
add_action( 'admin_enqueue_scripts', 'ekwa_enqueue_admin_fa' );

/**
 * Inject styles into the FSE iframed canvas.
 *
 * add_editor_style() with a RELATIVE theme path causes WordPress to set
 * baseURL = the theme's asset URL, so relative font paths in the CSS
 * (e.g. ../webfonts/) resolve correctly inside the iframe.
 */
function ekwa_editor_styles() {
	add_editor_style( 'assets/fontawesome/css/all.min.css' );
	add_editor_style( 'assets/css/ekwa-editor.css' );

	// The per-block partials are the single source of truth for block CSS. The
	// front end inlines only the blocks in use; the editor loads the full set so
	// every block previews correctly. Paths are RELATIVE and resolved child-first
	// by core (get_theme_file_uri() in get_editor_stylesheets()), so a child's
	// overridden blocks/<name>/style.css previews automatically. Union the parent
	// and active-child globs — deduped on the relative path — so child-only blocks
	// also preview; when no child theme is active the two bases coincide.
	$rel_paths = array();
	foreach ( array_unique( array( get_template_directory(), get_stylesheet_directory() ) ) as $base ) {
		$partials = array_merge(
			glob( $base . '/blocks/*/style.css' ) ?: array(),
			glob( $base . '/blocks/_core-styles/*.css' ) ?: array()
		);
		foreach ( $partials as $partial ) {
			$rel_paths[ 'blocks/' . ltrim( str_replace( $base . '/blocks/', '', $partial ), '/' ) ] = true;
		}
	}
	foreach ( array_keys( $rel_paths ) as $rel ) {
		add_editor_style( $rel );
	}
}
add_action( 'after_setup_theme', 'ekwa_editor_styles' );

/**
 * Whether the active child theme's style.css should be suppressed inside the
 * block editor canvas. Opt-in via Ekwa Settings → Performance.
 *
 * The child still enqueues its stylesheet on the front end (that path is
 * untouched) — this only affects the editor, where some child styles can
 * overlay blocks and make them hard to select/click.
 */
function ekwa_editor_disable_child_css_enabled() {
	return (bool) get_option( 'ekwa_editor_disable_child_css', 0 );
}

/**
 * Drop the active child theme's style.css (and its RTL variant) from the editor
 * styles global so it isn't inlined into the block editor iframe.
 *
 * Runs at priority 11 — after the child registers it via add_editor_style()
 * (priority 10) — and only when a child theme is active and the toggle is on.
 * Lives in the parent so it works for any child without editing the child's
 * functions.php (mirrors the inline-child design in inc/ekwa-inline-child.php).
 */
function ekwa_editor_remove_child_css() {
	if ( ! ekwa_editor_disable_child_css_enabled() ) {
		return;
	}
	// No child theme active — parent === stylesheet root; nothing to remove.
	if ( get_template_directory() === get_stylesheet_directory() ) {
		return;
	}
	if ( empty( $GLOBALS['editor_styles'] ) || ! is_array( $GLOBALS['editor_styles'] ) ) {
		return;
	}

	$child_dir = wp_normalize_path( get_stylesheet_directory() );
	$GLOBALS['editor_styles'] = array_values( array_filter(
		$GLOBALS['editor_styles'],
		static function ( $style ) use ( $child_dir ) {
			// Resolve each (usually relative) editor-style entry to a file path the
			// same way core does — get_theme_file_path() prefers the child theme.
			// Match the child's ROOT style.css exactly so block-partial overrides
			// (blocks/<name>/style.css) inside the child are never caught.
			$resolved = wp_normalize_path( get_theme_file_path( (string) $style ) );
			if ( $resolved === $child_dir . '/style.css' || $resolved === $child_dir . '/style-rtl.css' ) {
				return false; // Drop the child theme's own stylesheet.
			}
			return true;
		}
	) );
}
add_action( 'after_setup_theme', 'ekwa_editor_remove_child_css', 11 );

/**
 * Enqueue the phone-button block extension in the editor.
 */
function ekwa_enqueue_button_phone_editor_script() {
	wp_enqueue_script(
		'ekwa-button-phone',
		get_template_directory_uri() . '/assets/js/ekwa-button-phone.js',
		array(
			'wp-hooks',
			'wp-blocks',
			'wp-block-editor',
			'wp-components',
			'wp-compose',
			'wp-element',
			'wp-i18n',
		),
		wp_get_theme()->get( 'Version' ),
		true
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_button_phone_editor_script' );

/**
 * Enqueue the mockup converter editor plugin.
 */
function ekwa_enqueue_converter_editor_script() {
	wp_enqueue_script(
		'ekwa-converter-editor',
		get_template_directory_uri() . '/assets/js/ekwa-converter-editor.js',
		array(
			'wp-plugins',
			'wp-editor',
			'wp-blocks',
			'wp-block-editor',
			'wp-components',
			'wp-element',
			'wp-data',
			'wp-i18n',
			'wp-api-fetch',
		),
		filemtime( get_template_directory() . '/assets/js/ekwa-converter-editor.js' ),
		true
	);

	// AI HTML generator — separate plugin entry point that hands off HTML to the converter.
	wp_enqueue_script(
		'ekwa-ai-generate-editor',
		get_template_directory_uri() . '/assets/js/ekwa-ai-generate-editor.js',
		array(
			'ekwa-converter-editor',
			'wp-plugins',
			'wp-editor',
			'wp-components',
			'wp-element',
			'wp-i18n',
			'wp-api-fetch',
		),
		filemtime( get_template_directory() . '/assets/js/ekwa-ai-generate-editor.js' ),
		true
	);

	// Expose the active (child) theme stylesheet URL so the preview iframe can
	// load it — mirrors what the front-end renders so AI-generated HTML that
	// uses theme classes/variables previews correctly.
	$child_css_path = get_stylesheet_directory() . '/style.css';
	$child_css_uri  = get_stylesheet_uri();
	if ( file_exists( $child_css_path ) ) {
		$child_css_uri = add_query_arg( 'ver', filemtime( $child_css_path ), $child_css_uri );
	}
	$ai_models     = function_exists( 'ekwa_ai_models' ) ? ekwa_ai_models() : array();
	$ai_model_list = array();
	foreach ( $ai_models as $model_id => $model_label ) {
		$ai_model_list[] = array(
			'value' => $model_id,
			'label' => $model_label,
		);
	}
	wp_localize_script(
		'ekwa-ai-generate-editor',
		'ekwaAiGenerate',
		array(
			'childStylesheetUrl' => $child_css_uri,
			'models'             => $ai_model_list,
			'defaultModel'       => function_exists( 'ekwa_ai_default_model' ) ? ekwa_ai_default_model() : '',
			// Design tokens + mockup sheet + fonts, exactly as <head> prints
			// them — without these the preview can't resolve the var() and
			// component rules the generated CSS is told to build on.
			'previewCss'         => function_exists( 'ekwa_ai_generate_preview_head_css' )
				? ekwa_ai_generate_preview_head_css()
				: '',
		)
	);

	// AI Block Builder — emits Ekwa/core block markup directly (no HTML→block
	// conversion). Standalone plugin entry; reuses the same model list + child CSS.
	wp_enqueue_script(
		'ekwa-ai-blocks-editor',
		get_template_directory_uri() . '/assets/js/ekwa-ai-blocks-editor.js',
		array(
			'wp-plugins',
			'wp-editor',
			'wp-blocks',
			'wp-block-editor',
			'wp-components',
			'wp-element',
			'wp-data',
			'wp-i18n',
			'wp-api-fetch',
		),
		filemtime( get_template_directory() . '/assets/js/ekwa-ai-blocks-editor.js' ),
		true
	);
	wp_localize_script(
		'ekwa-ai-blocks-editor',
		'ekwaAiBlocks',
		array(
			'childStylesheetUrl' => $child_css_uri,
			'models'             => $ai_model_list,
			'defaultModel'       => function_exists( 'ekwa_ai_default_model' ) ? ekwa_ai_default_model() : '',
			// Same design tokens + mockup sheet + fonts the HTML generator's
			// preview gets — without them the preview iframe can't resolve the
			// var() and component rules the rendered blocks are built on.
			'previewCss'         => function_exists( 'ekwa_ai_generate_preview_head_css' )
				? ekwa_ai_generate_preview_head_css()
				: '',
		)
	);

	// "Create page (with imported content)" — converts the HTML the Bulk Page
	// Creator parked on a page into blocks. Registers its own ⋮ menu item and
	// hides itself on pages with nothing imported, so it costs nothing
	// elsewhere.
	wp_enqueue_script(
		'ekwa-import-editor',
		get_template_directory_uri() . '/assets/js/ekwa-import-editor.js',
		array(
			'wp-plugins',
			'wp-editor',
			'wp-blocks',
			'wp-block-editor',
			'wp-components',
			'wp-element',
			'wp-data',
			'wp-i18n',
			'wp-api-fetch',
		),
		filemtime( get_template_directory() . '/assets/js/ekwa-import-editor.js' ),
		true
	);
	wp_localize_script(
		'ekwa-import-editor',
		'ekwaImportContent',
		array(
			// The same design tokens the AI previews get, so the preview iframe
			// resolves the site's var() tokens instead of rendering unstyled.
			'previewCss'   => function_exists( 'ekwa_ai_generate_preview_head_css' )
				? ekwa_ai_generate_preview_head_css()
				: '',
			// Same catalog the AI Block Builder offers. Building a whole page is
			// the most demanding job either tool does, so the default is the
			// theme-wide most-capable model rather than a cheaper tier.
			'models'       => $ai_model_list,
			'defaultModel' => function_exists( 'ekwa_ai_default_model' ) ? ekwa_ai_default_model() : '',
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_converter_editor_script' );

/**
 * Enqueue the per-part "Reconvert as…" block action — turns a mis-mapped
 * element (e.g. an address flattened to text) into the correct dynamic block,
 * preserving the mockup structure via customTemplate.
 */
function ekwa_enqueue_reconvert_editor_script() {
	wp_enqueue_script(
		'ekwa-reconvert-editor',
		get_template_directory_uri() . '/assets/js/ekwa-reconvert-editor.js',
		array(
			'wp-hooks',
			'wp-compose',
			'wp-blocks',
			'wp-block-editor',
			'wp-components',
			'wp-element',
			'wp-data',
			'wp-i18n',
			'wp-api-fetch',
		),
		filemtime( get_template_directory() . '/assets/js/ekwa-reconvert-editor.js' ),
		true
	);

	$targets = function_exists( 'ekwa_ai_reconvert_targets' ) ? ekwa_ai_reconvert_targets() : array();
	$list    = array();
	foreach ( $targets as $name => $info ) {
		$list[] = array( 'value' => $name, 'label' => $info['label'] );
	}
	wp_localize_script( 'ekwa-reconvert-editor', 'ekwaReconvert', array( 'targets' => $list ) );
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_reconvert_editor_script' );

/**
 * Internal linking suggestions — editor sidebar that scans the current page and
 * proposes one-click internal links to other pages.
 */
function ekwa_enqueue_interlink_editor_script() {
	if ( function_exists( 'ekwa_interlink_enabled' ) && ! ekwa_interlink_enabled() ) {
		return;
	}

	wp_enqueue_script(
		'ekwa-interlink-editor',
		get_template_directory_uri() . '/assets/js/ekwa-interlink-editor.js',
		array(
			'wp-plugins',
			'wp-editor',
			'wp-edit-post',
			'wp-block-editor',
			'wp-blocks',
			'wp-components',
			'wp-element',
			'wp-data',
			'wp-rich-text',
			'wp-i18n',
			'wp-api-fetch',
		),
		filemtime( get_template_directory() . '/assets/js/ekwa-interlink-editor.js' ),
		true
	);

	$ai_models     = function_exists( 'ekwa_ai_models' ) ? ekwa_ai_models() : array();
	$ai_model_list = array();
	foreach ( $ai_models as $model_id => $model_label ) {
		$ai_model_list[] = array(
			'value' => $model_id,
			'label' => $model_label,
		);
	}
	wp_localize_script(
		'ekwa-interlink-editor',
		'ekwaInterlink',
		array(
			'models'       => $ai_model_list,
			'defaultModel' => function_exists( 'ekwa_interlink_refine_model' ) ? ekwa_interlink_refine_model() : ekwa_ai_default_model(),
			'hasApiKey'    => (bool) ekwa_get_ai_api_key(),
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_enqueue_interlink_editor_script' );

/**
 * Register theme support.
 */
function ekwa_setup() {
	// Load translations: parent /languages, then the active child theme's, so a
	// child can override or add strings. Block metadata (block.json) is loaded
	// automatically by core; this covers the PHP __() strings.
	load_theme_textdomain( 'ekwa', get_template_directory() . '/languages' );

	register_nav_menus( array(
		'main_menu'       => __( 'Main Menu', 'ekwa' ),
		'primary'         => __( 'Primary Menu', 'ekwa' ),
		'mobile'          => __( 'Mobile Menu', 'ekwa' ),
		'mobile_services' => __( 'Mobile Services Menu', 'ekwa' ),
		'sitemap'         => __( 'Sitemap', 'ekwa' ),
	) );
	add_theme_support( 'post-thumbnails' );

	// Phone-sized crop of the inner-banner featured image. WordPress has no
	// default size between 300w and 768w, so ekwa/inner-banner's <picture>
	// ladder serves this ~480w variant to phones instead of the heavier 768w.
	// Proportional (height 0) so object-fit: cover in the banner does the
	// cropping. Existing images need one thumbnail regeneration to pick it up.
	add_image_size( 'ekwa-banner-mobile', 480, 0, false );

	add_theme_support( 'title-tag' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'html5', array(
		'comment-list',
		'comment-form',
		'search-form',
		'gallery',
		'caption',
		'style',
		'script',
	) );
}
add_action( 'after_setup_theme', 'ekwa_setup' );

/**
 * Register block pattern category.
 */
function ekwa_register_pattern_categories() {
	register_block_pattern_category( 'ekwa-patterns', array(
		'label' => esc_html__( 'Ekwa Patterns', 'ekwa' ),
	) );
	register_block_pattern_category( 'ekwa-headers-footers', array(
		'label' => esc_html__( 'Ekwa Headers & Footers', 'ekwa' ),
	) );
	register_block_pattern_category( 'ekwa-banners', array(
		'label' => esc_html__( 'Ekwa Page Banners', 'ekwa' ),
	) );
}
add_action( 'init', 'ekwa_register_pattern_categories' );

/**
 * Allow SVG uploads and display them correctly in the media library.
 */
function ekwa_allow_svg_upload( $mimes ) {
	$mimes['svg']  = 'image/svg+xml';
	$mimes['svgz'] = 'image/svg+xml';
	return $mimes;
}
add_filter( 'upload_mimes', 'ekwa_allow_svg_upload' );

function ekwa_fix_svg_mime_check( $data, $file, $filename, $mimes ) {
	$ext = pathinfo( $filename, PATHINFO_EXTENSION );
	if ( 'svg' === strtolower( $ext ) ) {
		$data['type'] = 'image/svg+xml';
		$data['ext']  = 'svg';
	}
	return $data;
}
add_filter( 'wp_check_filetype_and_ext', 'ekwa_fix_svg_mime_check', 10, 4 );

/**
 * Sanitize raw SVG markup for safe inline output / storage.
 *
 * Strips XML processing instructions, <script> elements and inline event
 * handlers. Returns '' when the input contains no <svg> root (invalid).
 * Shared by the SVG upload prefilter, the SVG-logo setting save, and the
 * ekwa/svg-logo block render.
 *
 * @param string $svg Raw SVG markup.
 * @return string Sanitized markup, or '' if it isn't an SVG.
 */
function ekwa_sanitize_svg_markup( $svg ) {
	$svg = (string) $svg;
	if ( '' === $svg ) {
		return '';
	}
	$svg = preg_replace( '/<\?xml.*?\?>/s', '', $svg );
	$svg = preg_replace( '/<script[^>]*>.*?<\/script>/si', '', $svg );
	$svg = preg_replace( '/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $svg );

	if ( false === stripos( $svg, '<svg' ) ) {
		return '';
	}
	return trim( $svg );
}

function ekwa_sanitize_svg_on_upload( $file ) {
	if ( 'image/svg+xml' !== $file['type'] ) {
		return $file;
	}

	$contents = file_get_contents( $file['tmp_name'] );
	if ( false === $contents ) {
		$file['error'] = __( 'Could not read SVG file.', 'ekwa' );
		return $file;
	}

	$clean = ekwa_sanitize_svg_markup( $contents );
	if ( '' === $clean ) {
		$file['error'] = __( 'Invalid SVG file.', 'ekwa' );
		return $file;
	}

	file_put_contents( $file['tmp_name'], $clean );
	return $file;
}
add_filter( 'wp_handle_upload_prefilter', 'ekwa_sanitize_svg_on_upload' );

function ekwa_svg_media_library_display() {
	echo '<style>
		.attachment-266x266, .thumbnail img[src$=".svg"] {
			width: 100% !important;
			height: auto !important;
		}
	</style>';
}
add_action( 'admin_head', 'ekwa_svg_media_library_display' );
