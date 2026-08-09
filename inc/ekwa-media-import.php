<?php
/**
 * Import images into the Media Library from a list of URLs.
 *
 * Adds an "Import from URL" panel to the Media Library (upload.php) and the
 * Add New Media screen (media-new.php): paste one or more image URLs, hit
 * Import, and each one is downloaded and sideloaded as a normal attachment —
 * thumbnails, WebP companions (inc/ekwa-webp.php hooks the same
 * wp_generate_attachment_metadata filter) and all.
 *
 * Each URL is imported by its own REST request (POST ekwa/v1/media-import),
 * one at a time, so a list of fifty images can never hit PHP's max_execution_time
 * and every failure is reported against the URL that caused it instead of
 * aborting the run.
 *
 * The source URL is recorded on the attachment as _ekwa_source_url so a repeat
 * paste reuses the existing copy rather than filling the library with
 * photo-1.jpg, photo-2.jpg, photo-3.jpg. The CSV bulk-page importer's older
 * _ekwa_bulk_source_url meta is checked too, so images pulled in by that
 * feature also dedupe here.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Download
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Toggle/read the "identify as a browser" flag for the retry download.
 *
 * Kept as a static rather than adding/removing the http_request_args filter
 * around each call, so the filter registration stays declarative below.
 *
 * @param bool|null $set True/false to set, null to read.
 * @return bool
 */
function ekwa_media_import_ua_override( $set = null ) {
	static $on = false;
	if ( null !== $set ) {
		$on = (bool) $set;
	}
	return $on;
}

/**
 * Send browser-ish request headers while the retry flag is on.
 *
 * Plenty of hosts (hotlink protection, bot rules, some CDN WAFs) answer the
 * default "WordPress/7.0; https://site" user agent with a 403 while serving the
 * exact same image to a browser. This only applies to the second attempt — see
 * ekwa_media_import_download().
 *
 * @param array  $args HTTP request args.
 * @param string $url  Request URL.
 * @return array
 */
function ekwa_media_import_http_args( $args, $url ) {
	if ( ! ekwa_media_import_ua_override() ) {
		return $args;
	}
	if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
		$args['headers'] = array();
	}
	$args['user-agent']        = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
	$args['headers']['Accept'] = 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8';

	// A same-origin referer is what hotlink rules are looking for.
	$parts = wp_parse_url( $url );
	if ( ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
		$args['headers']['Referer'] = $parts['scheme'] . '://' . $parts['host'] . '/';
	}
	return $args;
}
add_filter( 'http_request_args', 'ekwa_media_import_http_args', 10, 2 );

/**
 * Download a URL to a temp file, retrying once as a browser on a refusal.
 *
 * download_url() goes through wp_safe_remote_get(), which refuses private /
 * loopback hosts — that SSRF guard is deliberate and stays.
 *
 * @param string $url      Remote URL.
 * @param bool   $insecure Skip TLS certificate verification for this download.
 * @return string|WP_Error Temp file path, or error.
 */
function ekwa_media_import_download( $url, $insecure = false ) {
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	// Opt-in, one download at a time: plenty of sites serve an incomplete
	// certificate chain, which browsers paper over (they fetch the missing
	// intermediate) and PHP does not. The user asks for this per run, having
	// seen the certificate error — see ekwa_media_import_download_error().
	if ( $insecure ) {
		add_filter( 'https_ssl_verify', '__return_false', 99 );
	}
	try {
		return ekwa_media_import_download_attempt( $url );
	} finally {
		if ( $insecure ) {
			remove_filter( 'https_ssl_verify', '__return_false', 99 );
		}
	}
}

/**
 * The download itself. Split out so the TLS filter above is always removed,
 * on every return path.
 *
 * @param string $url Remote URL.
 * @return string|WP_Error Temp file path, or error.
 */
function ekwa_media_import_download_attempt( $url ) {
	/**
	 * Filters the per-image download timeout, in seconds.
	 *
	 * @param int $timeout Timeout. Default 30.
	 */
	$timeout = (int) apply_filters( 'ekwa_media_import_timeout', 30 );

	$tmp = download_url( $url, $timeout );
	if ( ! is_wp_error( $tmp ) ) {
		return $tmp;
	}

	// Only a deliberate refusal is worth a second attempt — a DNS failure or a
	// timeout will just fail again more slowly.
	$data = $tmp->get_error_data();
	$code = is_array( $data ) && isset( $data['code'] ) ? (int) $data['code'] : 0;
	if ( ! in_array( $code, array( 401, 403, 405, 406, 429, 503 ), true ) ) {
		return $tmp;
	}

	ekwa_media_import_ua_override( true );
	try {
		$retry = download_url( $url, $timeout );
	} finally {
		ekwa_media_import_ua_override( false );
	}

	// Report the original refusal if the retry fails too — it's the honest one.
	return is_wp_error( $retry ) ? $tmp : $retry;
}

// ─────────────────────────────────────────────────────────────────────────────
// Inspection
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Image MIME types this site accepts, as a flat list.
 *
 * Derived from get_allowed_mime_types() so it automatically honours the theme's
 * SVG opt-in (ekwa_allow_svg_upload) and any site-level restriction.
 *
 * @return string[]
 */
function ekwa_media_import_allowed_mimes() {
	$images = array();
	foreach ( get_allowed_mime_types() as $mime ) {
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$images[ $mime ] = true;
		}
	}
	/**
	 * Filters the MIME types the URL importer accepts. Images only by default.
	 *
	 * @param string[] $mimes Allowed MIME types.
	 */
	return (array) apply_filters( 'ekwa_media_import_allowed_mimes', array_keys( $images ) );
}

/**
 * Identify a downloaded file from its own bytes.
 *
 * The URL's extension is not trusted: CDN and page-builder URLs are routinely
 * extensionless, or say .jpg while serving WebP. Sniffing here means the
 * attachment lands with the right extension and MIME type.
 *
 * @param string $path Temp file path.
 * @return array|WP_Error { ext, mime, width, height } or error.
 */
function ekwa_media_import_detect( $path ) {
	$size = wp_getimagesize( $path );
	if ( is_array( $size ) && ! empty( $size[2] ) ) {
		$ext = image_type_to_extension( $size[2], false );
		if ( 'jpeg' === $ext ) {
			$ext = 'jpg'; // Match what WordPress itself writes.
		}
		return array(
			'ext'    => $ext,
			'mime'   => image_type_to_mime_type( $size[2] ),
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
		);
	}

	// SVG is markup, so it has no bitmap header for getimagesize() to read.
	$head = (string) file_get_contents( $path, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false !== stripos( $head, '<svg' ) ) {
		return array(
			'ext'    => 'svg',
			'mime'   => 'image/svg+xml',
			'width'  => 0,
			'height' => 0,
		);
	}

	return new WP_Error(
		'ekwa_media_import_not_image',
		__( 'That URL did not return an image — check the link opens the image itself, not the page around it.', 'ekwa' ),
		array( 'status' => 415 )
	);
}

/**
 * Build the attachment filename: the URL's own name, with the extension the
 * bytes actually call for.
 *
 * @param string $url      Source URL.
 * @param array  $detected Result of ekwa_media_import_detect().
 * @param string $override Explicit filename from the request, if any.
 * @return string
 */
function ekwa_media_import_filename( $url, $detected, $override = '' ) {
	$name = '';
	if ( '' !== trim( (string) $override ) ) {
		$name = trim( (string) $override );
	} else {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$name = '' !== $path ? urldecode( basename( $path ) ) : '';
	}

	$base = sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) );
	$base = trim( $base, '.-_' );

	if ( '' === $base ) {
		$base = 'imported-image';
	}
	// Some CDN paths are hash strings hundreds of characters long.
	if ( strlen( $base ) > 100 ) {
		$base = rtrim( substr( $base, 0, 100 ), '.-_' );
	}

	return $base . '.' . $detected['ext'];
}

// ─────────────────────────────────────────────────────────────────────────────
// Duplicate detection
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Find an attachment previously imported from the same URL.
 *
 * Checks this module's _ekwa_source_url as well as the CSV bulk-page importer's
 * _ekwa_bulk_source_url (inc/ekwa-settings.php), so the two features share one
 * pool of imported images.
 *
 * @param string $url Source URL.
 * @return int Attachment ID, or 0.
 */
function ekwa_media_import_find_existing( $url ) {
	$ids = get_posts( array(
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'posts_per_page'         => 1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'suppress_filters'       => false,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		'meta_query'             => array(
			'relation' => 'OR',
			array(
				'key'   => '_ekwa_source_url',
				'value' => $url,
			),
			array(
				'key'   => '_ekwa_bulk_source_url',
				'value' => $url,
			),
		),
	) );

	return empty( $ids ) ? 0 : (int) $ids[0];
}

/**
 * Resolve a URL that already points at this site's own uploads.
 *
 * Pasting a local URL is a common slip (copying from another tab of the same
 * site). Re-downloading it would duplicate the file, and on a local dev host
 * wp_safe_remote_get() would refuse the request outright.
 *
 * @param string $url Source URL.
 * @return int Attachment ID, or 0.
 */
function ekwa_media_import_local_attachment( $url ) {
	$id = attachment_url_to_postid( $url );
	if ( $id ) {
		return (int) $id;
	}

	// Try again without a size suffix: …/photo-300x200.jpg → …/photo.jpg.
	$full = preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $url );
	if ( $full && $full !== $url ) {
		return (int) attachment_url_to_postid( $full );
	}

	return 0;
}

// ─────────────────────────────────────────────────────────────────────────────
// Import
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Import one remote image into the media library.
 *
 * @param string $url  Remote image URL.
 * @param array  $args {
 *     @type string $alt             Alt text to store.
 *     @type string $title           Attachment title. Defaults to the filename.
 *     @type string $filename        Filename override (extension is ignored).
 *     @type int    $post_id         Post to attach to. Default 0 (unattached).
 *     @type bool   $skip_duplicates Reuse a previous import of the same URL.
 *     @type bool   $insecure        Skip TLS verification for this download.
 * }
 * @return array|WP_Error { attachment_id, duplicate } or error.
 */
function ekwa_media_import_sideload( $url, $args = array() ) {
	$args = wp_parse_args( $args, array(
		'alt'             => '',
		'title'           => '',
		'filename'        => '',
		'post_id'         => 0,
		'skip_duplicates' => true,
		'insecure'        => false,
	) );

	$url = trim( (string) $url );
	if ( '' === $url ) {
		return new WP_Error( 'ekwa_media_import_empty', __( 'No URL given.', 'ekwa' ), array( 'status' => 400 ) );
	}

	// Demand an explicit scheme on the raw input: esc_url() would otherwise
	// prepend http:// to any junk it is handed, turning "not a url" into a
	// request that fails later with a much vaguer message.
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		return new WP_Error(
			'ekwa_media_import_bad_url',
			__( 'Not a valid http(s) image URL.', 'ekwa' ),
			array( 'status' => 400 )
		);
	}

	// esc_url_raw then encodes stray spaces and the like — the usual damage
	// from a URL copied out of a document.
	$url = esc_url_raw( $url, array( 'http', 'https' ) );
	if ( '' === $url ) {
		return new WP_Error(
			'ekwa_media_import_bad_url',
			__( 'Not a valid http(s) image URL.', 'ekwa' ),
			array( 'status' => 400 )
		);
	}

	// Already here? Reuse it — for a previous import or for a URL that points
	// into this site's own uploads folder.
	if ( $args['skip_duplicates'] ) {
		$existing = ekwa_media_import_find_existing( $url );
		if ( $existing ) {
			ekwa_media_import_apply_alt( $existing, $args['alt'] );
			return array(
				'attachment_id' => $existing,
				'duplicate'     => true,
			);
		}
	}
	$local = ekwa_media_import_local_attachment( $url );
	if ( $local ) {
		ekwa_media_import_apply_alt( $local, $args['alt'] );
		return array(
			'attachment_id' => $local,
			'duplicate'     => true,
		);
	}

	$tmp = ekwa_media_import_download( $url, (bool) $args['insecure'] );
	if ( is_wp_error( $tmp ) ) {
		return ekwa_media_import_download_error( $tmp );
	}

	$result = ekwa_media_import_store( $tmp, $url, $args );

	// wp_handle_sideload() moves the temp file on success; anything else leaves
	// it behind for us to clean up.
	if ( is_wp_error( $result ) && file_exists( $tmp ) ) {
		wp_delete_file( $tmp );
	}

	return $result;
}

/**
 * Validate a downloaded temp file and turn it into an attachment.
 *
 * Split out of ekwa_media_import_sideload() so every early return there has one
 * temp-file cleanup path.
 *
 * @param string $tmp  Temp file path.
 * @param string $url  Source URL (recorded on the attachment).
 * @param array  $args Import args, already parsed.
 * @return array|WP_Error
 */
function ekwa_media_import_store( $tmp, $url, $args ) {
	$bytes = (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( $bytes < 1 ) {
		return new WP_Error( 'ekwa_media_import_empty_file', __( 'The download was empty.', 'ekwa' ), array( 'status' => 502 ) );
	}

	$max = wp_max_upload_size();
	if ( $max > 0 && $bytes > $max ) {
		return new WP_Error(
			'ekwa_media_import_too_big',
			sprintf(
				/* translators: 1: image size, 2: the site's upload limit. */
				__( 'That image is %1$s — larger than this site\'s %2$s upload limit.', 'ekwa' ),
				size_format( $bytes ),
				size_format( $max )
			),
			array( 'status' => 413 )
		);
	}

	$detected = ekwa_media_import_detect( $tmp );
	if ( is_wp_error( $detected ) ) {
		return $detected;
	}

	if ( ! in_array( $detected['mime'], ekwa_media_import_allowed_mimes(), true ) ) {
		return new WP_Error(
			'ekwa_media_import_mime',
			sprintf(
				/* translators: %s: MIME type, e.g. image/avif. */
				__( 'This site does not accept %s uploads.', 'ekwa' ),
				$detected['mime']
			),
			array( 'status' => 415 )
		);
	}

	$filename = ekwa_media_import_filename( $url, $detected, $args['filename'] );

	// SVG uploads normally pass through the theme's sanitizer on
	// wp_handle_upload_prefilter, which a sideload never fires. Run it here so
	// an imported SVG gets the same scrubbing as an uploaded one.
	if ( 'image/svg+xml' === $detected['mime'] ) {
		if ( ! function_exists( 'ekwa_sanitize_svg_markup' ) ) {
			return new WP_Error( 'ekwa_media_import_svg', __( 'SVG import is unavailable.', 'ekwa' ), array( 'status' => 500 ) );
		}
		$clean = ekwa_sanitize_svg_markup( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $clean ) {
			return new WP_Error( 'ekwa_media_import_svg_invalid', __( 'That SVG could not be sanitized.', 'ekwa' ), array( 'status' => 415 ) );
		}
		file_put_contents( $tmp, $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$title = trim( (string) $args['title'] );

	$attachment_id = media_handle_sideload(
		array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		),
		(int) $args['post_id'],
		'' !== $title ? $title : null
	);

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	update_post_meta( $attachment_id, '_ekwa_source_url', $url );
	ekwa_media_import_apply_alt( $attachment_id, $args['alt'] );

	return array(
		'attachment_id' => (int) $attachment_id,
		'duplicate'     => false,
	);
}

/**
 * Store alt text on an attachment, without overwriting one that's already set.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $alt           Alt text ('' is a no-op).
 */
function ekwa_media_import_apply_alt( $attachment_id, $alt ) {
	$alt = trim( (string) $alt );
	if ( '' === $alt ) {
		return;
	}
	$current = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	if ( '' === $current ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
	}
}

/**
 * Rewrite a download failure into something an editor can act on.
 *
 * @param WP_Error $error Error from ekwa_media_import_download().
 * @return WP_Error
 */
function ekwa_media_import_download_error( $error ) {
	$data = $error->get_error_data();
	$code = is_array( $data ) && isset( $data['code'] ) ? (int) $data['code'] : 0;
	$raw  = $error->get_error_message();

	// A TLS failure is almost always the remote server omitting an intermediate
	// certificate — the site loads fine in a browser, which fills the gap in,
	// so "could not reach that URL" reads as nonsense. Name it, and point at
	// the checkbox that gets past it.
	if ( preg_match( '/certificate|cURL error (35|58|60|77|83)|SSL/i', $raw ) ) {
		return new WP_Error(
			'ekwa_media_import_ssl',
			__( 'That server\'s HTTPS certificate could not be verified (its certificate chain is probably incomplete). Tick “Ignore HTTPS certificate errors” below to import it anyway.', 'ekwa' ),
			array( 'status' => 502 )
		);
	}

	if ( 404 === $code || 410 === $code ) {
		$message = __( 'The image no longer exists at that URL (404).', 'ekwa' );
	} elseif ( in_array( $code, array( 401, 403 ), true ) ) {
		$message = __( 'That server refused the download (403) — the image may be hotlink-protected or behind a login.', 'ekwa' );
	} elseif ( $code >= 500 ) {
		/* translators: %d: HTTP status code. */
		$message = sprintf( __( 'That server returned an error (%d). Try again shortly.', 'ekwa' ), $code );
	} elseif ( $code > 0 ) {
		/* translators: 1: HTTP status code, 2: status message. */
		$message = sprintf( __( 'Download failed (HTTP %1$d %2$s).', 'ekwa' ), $code, $error->get_error_message() );
	} else {
		/* translators: %s: underlying error message. */
		$message = sprintf( __( 'Could not reach that URL: %s', 'ekwa' ), $error->get_error_message() );
	}

	return new WP_Error( 'ekwa_media_import_download', $message, array( 'status' => 502 ) );
}

/**
 * Describe an attachment for the results list in the panel.
 *
 * @param int $attachment_id Attachment ID.
 * @return array
 */
function ekwa_media_import_attachment_payload( $attachment_id ) {
	$thumb = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
	$file  = get_attached_file( $attachment_id );

	return array(
		'attachment_id' => (int) $attachment_id,
		'title'         => get_the_title( $attachment_id ),
		'filename'      => $file ? wp_basename( $file ) : '',
		'mime'          => (string) get_post_mime_type( $attachment_id ),
		'alt'           => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		// SVGs have no generated thumbnail — fall back to the file itself.
		'thumb'         => $thumb ? $thumb[0] : (string) wp_get_attachment_url( $attachment_id ),
		'edit_link'     => (string) get_edit_post_link( $attachment_id, 'raw' ),
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// REST
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Register the import route. One image per request — see the file header.
 */
function ekwa_media_import_register_rest() {
	register_rest_route( 'ekwa/v1', '/media-import', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_media_import_rest_import',
		'permission_callback' => function () {
			return current_user_can( 'upload_files' );
		},
		'args'                => array(
			'url'             => array(
				'type'     => 'string',
				'required' => true,
			),
			'alt'             => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'title'           => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'filename'        => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'post_id'         => array(
				'type'    => 'integer',
				'default' => 0,
			),
			'skip_duplicates' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'insecure'        => array(
				'type'    => 'boolean',
				'default' => false,
			),
		),
	) );
}
add_action( 'rest_api_init', 'ekwa_media_import_register_rest' );

/**
 * REST callback: import one URL.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function ekwa_media_import_rest_import( WP_REST_Request $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'ekwa_media_import_post', __( 'You can\'t attach media to that post.', 'ekwa' ), array( 'status' => 403 ) );
	}

	$result = ekwa_media_import_sideload(
		(string) $request->get_param( 'url' ),
		array(
			'alt'             => (string) $request->get_param( 'alt' ),
			'title'           => (string) $request->get_param( 'title' ),
			'filename'        => (string) $request->get_param( 'filename' ),
			'post_id'         => $post_id,
			'skip_duplicates' => (bool) $request->get_param( 'skip_duplicates' ),
			'insecure'        => (bool) $request->get_param( 'insecure' ),
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$payload              = ekwa_media_import_attachment_payload( $result['attachment_id'] );
	$payload['duplicate'] = ! empty( $result['duplicate'] );
	$payload['source']    = (string) $request->get_param( 'url' );

	return rest_ensure_response( $payload );
}

// ─────────────────────────────────────────────────────────────────────────────
// Admin UI
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Screens the panel appears on: Media Library and Add New Media.
 *
 * @param string $hook Current admin page hook.
 * @return bool
 */
function ekwa_media_import_is_screen( $hook ) {
	return in_array( $hook, array( 'upload.php', 'media-new.php' ), true );
}

/**
 * Asset version — file mtime so edits are picked up without a theme bump.
 *
 * @param string $rel Theme-relative path.
 * @return string
 */
function ekwa_media_import_asset_ver( $rel ) {
	$path = get_template_directory() . '/' . ltrim( $rel, '/' );
	return file_exists( $path ) ? (string) filemtime( $path ) : (string) wp_get_theme()->get( 'Version' );
}

/**
 * Enqueue the panel's CSS/JS on the media screens.
 *
 * @param string $hook Current admin page hook.
 */
function ekwa_media_import_enqueue( $hook ) {
	if ( ! ekwa_media_import_is_screen( $hook ) || ! current_user_can( 'upload_files' ) ) {
		return;
	}

	wp_enqueue_style(
		'ekwa-media-import',
		get_template_directory_uri() . '/assets/css/ekwa-media-import.css',
		array(),
		ekwa_media_import_asset_ver( 'assets/css/ekwa-media-import.css' )
	);
	wp_enqueue_script(
		'ekwa-media-import',
		get_template_directory_uri() . '/assets/js/ekwa-media-import.js',
		array( 'jquery' ),
		ekwa_media_import_asset_ver( 'assets/js/ekwa-media-import.js' ),
		true
	);

	// Alt text is written through core's own media route, so no extra endpoint.
	wp_localize_script( 'ekwa-media-import', 'ekwaMediaImport', array(
		'importUrl' => esc_url_raw( rest_url( 'ekwa/v1/media-import' ) ),
		'mediaUrl'  => esc_url_raw( rest_url( 'wp/v2/media/' ) ),
		'aiAltUrl'  => esc_url_raw( rest_url( 'ekwa/v1/generate-alt' ) ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'hasAi'     => (bool) ( function_exists( 'ekwa_get_ai_api_key' ) && ekwa_get_ai_api_key() ),
		'i18n'      => array(
			'toggle'      => __( 'Import from URL', 'ekwa' ),
			'noUrls'      => __( 'Paste at least one image URL first.', 'ekwa' ),
			/* translators: %d: number of URLs found in the textarea. */
			'found'       => __( '%d image URLs ready', 'ekwa' ),
			'foundOne'    => __( '1 image URL ready', 'ekwa' ),
			/* translators: 1: current image number, 2: total. */
			'progress'    => __( 'Importing %1$d of %2$d…', 'ekwa' ),
			/* translators: 1: imported count, 2: skipped count, 3: failed count. */
			'summary'     => __( 'Done — %1$d imported, %2$d already in the library, %3$d failed.', 'ekwa' ),
			'stopped'     => __( 'Stopped.', 'ekwa' ),
			'imported'    => __( 'Imported', 'ekwa' ),
			'duplicate'   => __( 'Already in library', 'ekwa' ),
			'failed'      => __( 'Failed', 'ekwa' ),
			'requestFail' => __( 'Request failed — check the browser console.', 'ekwa' ),
			'altLabel'    => __( 'Alt text', 'ekwa' ),
			'save'        => __( 'Save', 'ekwa' ),
			'saved'       => __( 'Saved', 'ekwa' ),
			'saveFail'    => __( 'Could not save alt text.', 'ekwa' ),
			'aiAlt'       => __( 'Write with AI', 'ekwa' ),
			'aiWorking'   => __( 'Writing…', 'ekwa' ),
			'aiFail'      => __( 'AI alt text failed.', 'ekwa' ),
			'aiAll'       => __( 'Write alt text for all with AI', 'ekwa' ),
			'reload'      => __( 'Reload library', 'ekwa' ),
		),
	) );
}
add_action( 'admin_enqueue_scripts', 'ekwa_media_import_enqueue' );

/**
 * Print the panel.
 *
 * Hooked to admin_notices because that fires exactly where the panel belongs —
 * inside .wrap, directly under the page heading — on both the list and grid
 * modes of upload.php. It starts hidden; the toggle button that reveals it is
 * added to the heading row by the JS.
 */
function ekwa_media_import_render_panel() {
	$screen = get_current_screen();
	// upload.php → 'upload', media-new.php → 'media'.
	if ( ! $screen || ! in_array( $screen->id, array( 'upload', 'media' ), true ) ) {
		return;
	}
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}
	$has_ai = function_exists( 'ekwa_get_ai_api_key' ) && ekwa_get_ai_api_key();
	?>
	<div id="ekwa-media-import" class="ekwa-mi" hidden>
		<div class="ekwa-mi__head">
			<h2 class="ekwa-mi__title"><?php esc_html_e( 'Import images from URLs', 'ekwa' ); ?></h2>
			<button type="button" class="ekwa-mi__close" aria-label="<?php esc_attr_e( 'Close', 'ekwa' ); ?>">&times;</button>
		</div>

		<p class="description">
			<?php esc_html_e( 'Paste image links — one per line. Each image is downloaded into this library with its own thumbnails, exactly as if you had uploaded it.', 'ekwa' ); ?>
		</p>

		<textarea
			id="ekwa-mi-urls"
			class="large-text code"
			rows="6"
			spellcheck="false"
			placeholder="https://example.com/wp-content/uploads/2025/05/photo.jpg&#10;https://example.com/images/team.png"></textarea>

		<div class="ekwa-mi__options">
			<label>
				<input type="checkbox" id="ekwa-mi-skip" checked />
				<?php esc_html_e( 'Skip links already imported', 'ekwa' ); ?>
			</label>
			<?php if ( $has_ai ) : ?>
				<label>
					<input type="checkbox" id="ekwa-mi-ai" />
					<?php esc_html_e( 'Write alt text with AI', 'ekwa' ); ?>
				</label>
			<?php endif; ?>
			<label title="<?php esc_attr_e( 'Only for sites you trust. Some servers send an incomplete certificate chain that browsers fix up silently but PHP will not.', 'ekwa' ); ?>">
				<input type="checkbox" id="ekwa-mi-insecure" />
				<?php esc_html_e( 'Ignore HTTPS certificate errors', 'ekwa' ); ?>
			</label>
			<span class="ekwa-mi__count" aria-live="polite"></span>
		</div>

		<p class="ekwa-mi__actions">
			<button type="button" class="button button-primary" id="ekwa-mi-start"><?php esc_html_e( 'Import images', 'ekwa' ); ?></button>
			<button type="button" class="button" id="ekwa-mi-stop" hidden><?php esc_html_e( 'Stop', 'ekwa' ); ?></button>
			<span class="ekwa-mi__status" aria-live="polite"></span>
		</p>

		<div class="ekwa-mi__bar" hidden><span></span></div>

		<ul class="ekwa-mi__results"></ul>
	</div>
	<?php
}
add_action( 'admin_notices', 'ekwa_media_import_render_panel' );
