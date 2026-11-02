<?php
/**
 * WAF-safe admin form posts.
 *
 * Shared hosts run ModSecurity, which answers 403 to the WHOLE request when any
 * POST field looks like code — and the Ekwa settings form carries the site's
 * stylesheet and ekwa-child.js as plain text, so saving it was blocked outright
 * (the per-field base64 twins did not help: the raw field travelled too).
 *
 * assets/js/ekwa-waf-safe-form.js packs every field of the forms listed below
 * into one base64 value on submit. This file unpacks it back into $_POST before
 * any admin_init save handler runs, so those handlers see exactly the fields a
 * plain submit would have sent. A submit without the packed value (JS off, a
 * page opened before the update) is left untouched — the old path.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forms that submit packed: form id => array( nonce field, nonce action ).
 * The nonce stays a real POST field so it can be checked before unpacking.
 *
 * @return array
 */
function ekwa_waf_safe_forms() {
	return array(
		'ekwa-main-settings-form' => array( 'ekwa_settings_nonce', 'ekwa_save_settings' ),
		'ekwa-schema-form'        => array( 'ekwa_schema_editor_nonce', 'ekwa_save_schema_template' ),
	);
}

/**
 * admin_init (priority 0, before the save handlers): unpack `ekwa_waf_payload`.
 */
function ekwa_waf_safe_unpack_post() {
	if ( ! isset( $_POST['ekwa_waf_payload'] ) || ! is_string( $_POST['ekwa_waf_payload'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked below.
		return;
	}
	$packed = wp_unslash( $_POST['ekwa_waf_payload'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput
	unset( $_POST['ekwa_waf_payload'], $_REQUEST['ekwa_waf_payload'] );

	$nonce_field = '';
	foreach ( ekwa_waf_safe_forms() as $form ) {
		if ( isset( $_POST[ $form[0] ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $form[0] ] ) ), $form[1] ) ) {
			$nonce_field = $form[0];
			break;
		}
	}
	// Not ours, or not allowed: leave $_POST alone — the save handlers refuse
	// it on their own checks.
	if ( '' === $nonce_field || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$raw    = base64_decode( $packed, true );
	$fields = array();
	$failed = false === $raw || '' === $raw;
	// parse_str() obeys max_input_vars like a normal POST does, but a normal
	// POST truncates silently; refuse instead of saving a partial form.
	$max_vars = (int) ini_get( 'max_input_vars' );
	if ( ! $failed && $max_vars > 0 && substr_count( $raw, '&' ) + 1 > $max_vars ) {
		$failed = 'too-many';
	}
	if ( ! $failed ) {
		parse_str( $raw, $fields );
		$failed = empty( $fields );
	}

	if ( $failed ) {
		// Never let a save run on a half-empty $_POST — every checkbox it lacks
		// would be saved as "off". Dropping the nonce stops the handler.
		unset( $_POST[ $nonce_field ], $_REQUEST[ $nonce_field ] );
		$GLOBALS['ekwa_waf_safe_failed'] = 'too-many' === $failed ? 'too-many' : 'damaged';
		add_action( 'admin_notices', 'ekwa_waf_safe_failed_notice' );
		return;
	}

	// The real nonce fields are authoritative; a payload can't supply them.
	foreach ( ekwa_waf_safe_forms() as $form ) {
		unset( $fields[ $form[0] ] );
	}
	// WordPress slashes $_POST on load (wp_magic_quotes) and every handler
	// unslashes, so the unpacked values get the same treatment.
	foreach ( wp_slash( $fields ) as $key => $value ) {
		$_POST[ $key ]    = $value;
		$_REQUEST[ $key ] = $value;
	}
}
add_action( 'admin_init', 'ekwa_waf_safe_unpack_post', 0 );

/**
 * Notice when a packed submit could not be unpacked (nothing was saved).
 */
function ekwa_waf_safe_failed_notice() {
	$why = isset( $GLOBALS['ekwa_waf_safe_failed'] ) ? $GLOBALS['ekwa_waf_safe_failed'] : 'damaged';
	if ( 'too-many' === $why ) {
		/* translators: %d: the server's max_input_vars value. */
		$msg = sprintf( __( 'Nothing was saved: the form has more fields than this server accepts (max_input_vars = %d). Ask the host to raise max_input_vars, then save again.', 'ekwa' ), (int) ini_get( 'max_input_vars' ) );
	} else {
		$msg = __( 'Nothing was saved: the form data did not arrive intact. Please try again.', 'ekwa' );
	}
	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $msg ) );
}

/**
 * Load the packer on the screens that hold the forms above.
 *
 * @param string $hook Current admin page hook.
 */
function ekwa_waf_safe_enqueue( $hook ) {
	if ( ! in_array( $hook, array( 'appearance_page_ekwa-settings', 'appearance_page_ekwa-schema-editor' ), true ) ) {
		return;
	}
	$rel = '/assets/js/ekwa-waf-safe-form.js';
	wp_enqueue_script(
		'ekwa-waf-safe-form',
		get_template_directory_uri() . $rel,
		array(),
		(string) filemtime( get_template_directory() . $rel ),
		true
	);
	$forms = array();
	foreach ( ekwa_waf_safe_forms() as $id => $form ) {
		$forms[ $id ] = $form[0];
	}
	wp_add_inline_script( 'ekwa-waf-safe-form', 'window.ekwaWafSafeForms = ' . wp_json_encode( array( 'forms' => $forms ) ) . ';', 'before' );
}
add_action( 'admin_enqueue_scripts', 'ekwa_waf_safe_enqueue' );
