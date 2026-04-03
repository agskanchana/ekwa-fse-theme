<?php
/**
 * REST API endpoint for the Mockup Converter.
 *
 * Provides POST /ekwa/v1/convert-markup so the Gutenberg editor plugin
 * can convert HTML to block markup without leaving the editor.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'ekwa_register_converter_routes' );

/**
 * Register the convert-markup REST route.
 */
function ekwa_register_converter_routes() {
	register_rest_route( 'ekwa/v1', '/convert-markup', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_rest_convert_markup',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args' => array(
			'html' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return wp_unslash( $v );
				},
			),
			'manifest' => array(
				'required' => false,
				'type'     => 'object',
				'default'  => null,
			),
			'use_server_manifest' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => true,
			),
		),
	) );
}

/**
 * Handle the convert-markup REST request.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_rest_convert_markup( $request ) {
	require_once get_template_directory() . '/inc/ekwa-converter-lib.php';

	$html = $request->get_param( 'html' );

	if ( empty( trim( $html ) ) ) {
		return new WP_Error(
			'empty_html',
			'HTML markup is empty.',
			array( 'status' => 400 )
		);
	}

	// Build manifest data.
	$manifest_data = $request->get_param( 'manifest' );

	// Merge with server-side manifest if requested.
	if ( $request->get_param( 'use_server_manifest' ) ) {
		$server_manifest = ekwa_converter_load_server_manifest();
		if ( $server_manifest ) {
			if ( $manifest_data && ! empty( $manifest_data['media'] ) ) {
				// Merge: client manifest takes precedence.
				$server_manifest['media'] = array_merge(
					$server_manifest['media'],
					$manifest_data['media']
				);
				$manifest_data = $server_manifest;
			} else {
				$manifest_data = $server_manifest;
			}
		}
	}

	$result = ekwa_mc_convert_html( $html, $manifest_data );

	return rest_ensure_response( array(
		'markup'   => $result['markup'],
		'warnings' => $result['warnings'],
	) );
}

/**
 * Load the server-side media manifest from wp-content/uploads.
 *
 * @return array|null Parsed manifest data or null if not found.
 */
function ekwa_converter_load_server_manifest() {
	$upload_dir = wp_upload_dir();
	$manifest_path = $upload_dir['basedir'] . '/ekwa-media-manifest.json';

	if ( ! file_exists( $manifest_path ) ) {
		return null;
	}

	$data = json_decode( file_get_contents( $manifest_path ), true );

	if ( ! $data || ! is_array( $data ) ) {
		return null;
	}

	// Ensure upload_url is set.
	if ( empty( $data['upload_url'] ) ) {
		$data['upload_url'] = $upload_dir['baseurl'];
	}

	return $data;
}
