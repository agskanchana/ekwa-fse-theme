<?php
/**
 * AI alt-text generation for the ekwa/image block.
 *
 * Exposes POST /ekwa/v1/generate-alt which sends an attachment's image to Gemini
 * (multimodal) and returns a concise, accessibility-friendly alt string. Reuses
 * the shared Gemini helpers in inc/ekwa-ai-generate.php and the key resolver in
 * inc/ekwa-ai-shared.php.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the alt-text REST route.
 */
function ekwa_ai_alt_register_routes() {
	register_rest_route( 'ekwa/v1', '/generate-alt', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_alt_handle_request',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'attachment_id' => array(
				'required' => true,
				'type'     => 'integer',
			),
			'model'         => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'gemini-2.5-flash',
			),
			'context'       => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
		),
	) );
}
add_action( 'rest_api_init', 'ekwa_ai_alt_register_routes' );

/**
 * Load an attachment as a downscaled JPEG, base64-encoded for the Gemini API.
 *
 * Resizing keeps the request small/fast and avoids sending multi-MB originals.
 * Falls back to the original bytes (under 4 MB) when the image editor is missing.
 *
 * @param int $attachment_id Attachment ID.
 * @return array|WP_Error { mime: string, data_base64: string } or error.
 */
function ekwa_ai_alt_image_base64( $attachment_id ) {
	$path = get_attached_file( $attachment_id );
	if ( ! $path || ! file_exists( $path ) ) {
		return new WP_Error( 'no_file', __( 'Could not locate the image file.', 'ekwa' ), array( 'status' => 404 ) );
	}

	$editor = wp_get_image_editor( $path );
	if ( ! is_wp_error( $editor ) ) {
		$editor->resize( 1024, 1024, false );
		$saved = $editor->save( null, 'image/jpeg' );
		if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
			$bytes = file_get_contents( $saved['path'] );
			wp_delete_file( $saved['path'] );
			if ( false !== $bytes ) {
				return array(
					'mime'        => 'image/jpeg',
					'data_base64' => base64_encode( $bytes ),
				);
			}
		}
	}

	// Fallback: original bytes, if small enough for an inline request.
	$size = (int) @filesize( $path );
	if ( $size > 0 && $size <= 4 * 1024 * 1024 ) {
		$bytes = file_get_contents( $path );
		if ( false !== $bytes ) {
			$mime = get_post_mime_type( $attachment_id );
			return array(
				'mime'        => $mime ? $mime : 'image/jpeg',
				'data_base64' => base64_encode( $bytes ),
			);
		}
	}

	return new WP_Error( 'image_too_large', __( 'The image could not be processed for AI analysis.', 'ekwa' ), array( 'status' => 422 ) );
}

/**
 * REST callback: generate alt text for an attachment via Gemini.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_alt_handle_request( $request ) {
	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return new WP_Error( 'no_api_key', __( 'Gemini API key is not configured (Settings → AI).', 'ekwa' ), array( 'status' => 400 ) );
	}

	$attachment_id = (int) $request->get_param( 'attachment_id' );
	if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
		return new WP_Error( 'bad_attachment', __( 'Pick an image from the media library first.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$mime = (string) get_post_mime_type( $attachment_id );
	if ( 0 !== strpos( $mime, 'image/' ) ) {
		return new WP_Error( 'not_an_image', __( 'That attachment is not an image.', 'ekwa' ), array( 'status' => 400 ) );
	}
	if ( 'image/svg+xml' === $mime ) {
		return new WP_Error( 'unsupported_svg', __( 'SVG images can\'t be analysed for alt text.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$image = ekwa_ai_alt_image_base64( $attachment_id );
	if ( is_wp_error( $image ) ) {
		return $image;
	}

	$image_part = ekwa_ai_generate_image_part( $image );
	if ( is_wp_error( $image_part ) ) {
		return $image_part;
	}
	if ( ! $image_part ) {
		return new WP_Error( 'image_failed', __( 'The image could not be prepared for AI analysis.', 'ekwa' ), array( 'status' => 422 ) );
	}

	// Validate the requested model against the allowed list.
	$model  = (string) $request->get_param( 'model' );
	$models = function_exists( 'ekwa_ai_generate_allowed_models' ) ? ekwa_ai_generate_allowed_models() : array();
	if ( ! isset( $models[ $model ] ) ) {
		$model = 'gemini-2.5-flash';
	}

	$system_prompt = 'You write alt text for website images. Return ONLY the alt text: '
		. 'one concise, factual phrase (aim for under 125 characters) describing the image '
		. 'for screen-reader users and SEO. Do not begin with "image of" or "picture of". '
		. 'No surrounding quotes, no markdown, no extra commentary.';

	$user_prompt = 'Write alt text for this image.';
	$context     = trim( (string) $request->get_param( 'context' ) );
	if ( '' !== $context ) {
		$user_prompt .= ' Page context: ' . $context;
	}

	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => $user_prompt ),
				$image_part,
			),
		),
	);

	$result = ekwa_ai_generate_call_gemini( $system_prompt, $contents, 0.2, $api_key, $model );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$alt = isset( $result['content'] ) ? (string) $result['content'] : '';
	$alt = ekwa_ai_alt_clean( $alt );
	if ( '' === $alt ) {
		return new WP_Error( 'empty_alt', __( 'The AI did not return any alt text. Please try again.', 'ekwa' ), array( 'status' => 502 ) );
	}

	return rest_ensure_response( array( 'alt' => $alt ) );
}

/**
 * Tidy the model's raw output into a clean alt string.
 *
 * @param string $text Raw model text.
 * @return string
 */
function ekwa_ai_alt_clean( $text ) {
	$text = trim( $text );
	// Drop a leading "Alt text:" / "Alt:" label if the model added one.
	$text = preg_replace( '/^\s*alt(\s*text)?\s*[:\-]\s*/i', '', $text );
	// Strip wrapping quotes and collapse whitespace/newlines.
	$text = trim( $text, " \t\n\r\"'" );
	$text = preg_replace( '/\s+/', ' ', $text );
	// Soft length cap so a runaway response can't bloat the attribute.
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 180 ) {
		$text = rtrim( mb_substr( $text, 0, 179 ) ) . '…';
	}
	return $text;
}
