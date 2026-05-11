<?php
/**
 * WebP image support — generation + transparent URL swap.
 *
 * Generates `image.jpg.webp` companions for every attachment image (original
 * + each registered size) and swaps the `<img src>` to the `.webp` URL when
 * the request's Accept header advertises `image/webp`. Browsers without WebP
 * support never see the swap, so the `<img>` markup is unchanged for them.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Options
// ─────────────────────────────────────────────────────────────────────────────

function ekwa_webp_is_enabled() {
	return (bool) get_option( 'ekwa_webp_enabled', 1 );
}

function ekwa_webp_apply_to_core_image() {
	return (bool) get_option( 'ekwa_webp_apply_core_image', 1 );
}

function ekwa_webp_quality() {
	$q = (int) get_option( 'ekwa_webp_quality', 82 );
	if ( $q < 50 ) { $q = 50; }
	if ( $q > 100 ) { $q = 100; }
	return $q;
}

// ─────────────────────────────────────────────────────────────────────────────
// Generation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * MIME types we'll convert. GIF is excluded (animation would be lost).
 */
function ekwa_webp_supported_mime( $mime ) {
	return in_array( $mime, array( 'image/jpeg', 'image/png' ), true );
}

/**
 * Companion `.webp` path for a source image: image.jpg → image.webp.
 * Replaces the extension so the WebP file sits beside the original
 * with a clean name rather than a doubled `.jpg.webp` suffix.
 */
function ekwa_webp_companion_path( $source_path ) {
	return preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source_path );
}

/**
 * Companion `.webp` URL — preserves query string and fragment.
 * e.g. image.jpg?v=2 → image.webp?v=2
 */
function ekwa_webp_companion_url( $url ) {
	return preg_replace( '/\.(jpe?g|png)(?=$|[?#])/i', '.webp', $url );
}

/**
 * Direct GD fallback for palette PNGs.
 *
 * GD's imagewebp() rejects palette images with a non-fatal warning. We
 * sidestep this by loading the PNG, converting to truecolor, preserving
 * alpha, and encoding manually.
 */
function ekwa_webp_gd_png_fallback( $source_path, $webp_path ) {
	if ( ! function_exists( 'imagecreatefrompng' ) || ! function_exists( 'imagewebp' ) ) {
		return false;
	}

	$img = @imagecreatefrompng( $source_path );
	if ( ! $img ) {
		return false;
	}

	if ( function_exists( 'imagepalettetotruecolor' ) ) {
		@imagepalettetotruecolor( $img );
	}

	// Preserve PNG transparency in the WebP output.
	@imagealphablending( $img, false );
	@imagesavealpha( $img, true );

	$ok = @imagewebp( $img, $webp_path, ekwa_webp_quality() );
	@imagedestroy( $img );

	return (bool) $ok;
}

/**
 * Generate a `.webp` companion next to a source image file.
 *
 * Validates the result on disk — GD/Imagick can return success while writing
 * a 0-byte file (CMYK JPEGs, exotic color profiles, OOM mid-encode). Without
 * the size check the URL swap on render would still happen and the browser
 * would fetch an empty WebP, giving the user a "broken image" with a blank
 * "open in new tab" preview.
 *
 * @param string $source_path Absolute path to the source image.
 * @return bool True on success or when up-to-date; false on failure.
 */
function ekwa_webp_generate_file( $source_path ) {
	if ( ! file_exists( $source_path ) ) {
		return false;
	}

	$mime = wp_check_filetype( $source_path );
	if ( empty( $mime['type'] ) || ! ekwa_webp_supported_mime( $mime['type'] ) ) {
		return false;
	}

	$webp_path = ekwa_webp_companion_path( $source_path );

	// Sweep the legacy `.jpg.webp` companion left over from older versions.
	$legacy_path = $source_path . '.webp';
	if ( $legacy_path !== $webp_path && file_exists( $legacy_path ) ) {
		@unlink( $legacy_path );
	}

	// Idempotent: skip when companion is newer than source AND non-empty.
	// (Pre-safety-net runs may have left zero-byte files behind; re-generate
	// those even if their mtime says they're current.)
	if ( file_exists( $webp_path )
		&& filemtime( $webp_path ) >= filemtime( $source_path )
		&& filesize( $webp_path ) > 0
	) {
		return true;
	}

	// Buffer output so any GD/Imagick warning bytes can't leak into REST
	// responses or rendered pages.
	ob_start();

	$ok     = false;
	$editor = wp_get_image_editor( $source_path );
	if ( ! is_wp_error( $editor ) ) {
		$editor->set_quality( ekwa_webp_quality() );
		$saved = @$editor->save( $webp_path, 'image/webp' );
		$ok    = ! is_wp_error( $saved );
	}

	// Free the underlying GD/Imagick resource immediately. Without this the
	// raw bitmap (width × height × 4 bytes — easily 50–100MB for a large
	// JPG) lingers until PHP GC runs, causing OOM after a few iterations.
	unset( $editor, $saved );
	if ( function_exists( 'gc_collect_cycles' ) ) {
		gc_collect_cycles();
	}

	ob_end_clean();

	// Palette PNG fallback — WP_Image_Editor_GD::save() fails on indexed PNGs.
	if ( ! $ok && $mime['type'] === 'image/png' ) {
		$ok = ekwa_webp_gd_png_fallback( $source_path, $webp_path );
	}

	// Post-write validation. A "success" from the editor that produced 0 bytes
	// (or fewer than a plausible minimum) means the file is broken; delete it
	// so the front-end render filter falls back to the original JPG/PNG.
	if ( $ok ) {
		$bytes = file_exists( $webp_path ) ? filesize( $webp_path ) : 0;
		if ( $bytes < 100 ) {
			@unlink( $webp_path );
			error_log( '[ekwa-webp] empty/truncated output (' . (int) $bytes . ' bytes) for ' . $source_path . ' — deleted, original will be served' );
			$ok = false;
		}
	}

	return $ok;
}

/**
 * Generate WebP for an attachment's original file plus each registered size.
 *
 * @param int $attachment_id
 * @return int Number of files newly created (or refreshed).
 */
function ekwa_webp_generate_for_attachment( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( ! $attachment_id ) {
		return 0;
	}

	$mime = get_post_mime_type( $attachment_id );
	if ( ! ekwa_webp_supported_mime( $mime ) ) {
		return 0;
	}

	$original = get_attached_file( $attachment_id );
	if ( ! $original || ! file_exists( $original ) ) {
		return 0;
	}

	$count = 0;
	if ( ekwa_webp_generate_file( $original ) ) {
		$count++;
	}

	$meta = wp_get_attachment_metadata( $attachment_id );
	if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		$dir = trailingslashit( dirname( $original ) );
		foreach ( $meta['sizes'] as $size ) {
			if ( empty( $size['file'] ) ) {
				continue;
			}
			if ( ekwa_webp_generate_file( $dir . $size['file'] ) ) {
				$count++;
			}
		}
	}

	return $count;
}

/**
 * Auto-generate WebP after an attachment's metadata is built.
 * Hooked on `wp_generate_attachment_metadata`.
 */
function ekwa_webp_on_upload( $metadata, $attachment_id ) {
	if ( ekwa_webp_is_enabled() ) {
		ekwa_webp_generate_for_attachment( $attachment_id );
	}
	return $metadata;
}

/**
 * Remove `.webp` companions when an attachment is deleted.
 * Hooked on `delete_attachment`.
 */
function ekwa_webp_on_delete( $attachment_id ) {
	$original = get_attached_file( $attachment_id );
	if ( ! $original ) {
		return;
	}

	$candidates = array( $original );
	$meta       = wp_get_attachment_metadata( $attachment_id );
	if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		$dir = trailingslashit( dirname( $original ) );
		foreach ( $meta['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$candidates[] = $dir . $size['file'];
			}
		}
	}

	foreach ( $candidates as $path ) {
		$webp   = ekwa_webp_companion_path( $path );
		$legacy = $path . '.webp';
		foreach ( array_unique( array( $webp, $legacy ) ) as $companion ) {
			if ( file_exists( $companion ) ) {
				@unlink( $companion );
			}
		}
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Delivery — URL swap based on Accept header
// ─────────────────────────────────────────────────────────────────────────────

function ekwa_webp_browser_supports() {
	static $cached = null;
	if ( $cached !== null ) {
		return $cached;
	}
	$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
	$cached = ( false !== stripos( $accept , 'image/webp' ) );
	return $cached;
}

/**
 * Convert a single image URL to its WebP companion when the file exists.
 *
 * Returns the input URL unchanged if the asset is not in this site's uploads
 * dir, the extension isn't convertible, or no `.webp` companion exists on disk.
 */
function ekwa_webp_url_for( $url ) {
	if ( ! is_string( $url ) || $url === '' ) {
		return $url;
	}

	$path_only = wp_parse_url( $url, PHP_URL_PATH );
	if ( ! $path_only ) {
		return $url;
	}

	$ext = strtolower( pathinfo( $path_only, PATHINFO_EXTENSION ) );
	if ( $ext !== 'jpg' && $ext !== 'jpeg' && $ext !== 'png' ) {
		return $url;
	}

	$upload   = wp_upload_dir();
	$base_url = isset( $upload['baseurl'] ) ? $upload['baseurl'] : '';
	$base_dir = isset( $upload['basedir'] ) ? $upload['basedir'] : '';
	if ( ! $base_url || ! $base_dir ) {
		return $url;
	}

	// Normalize protocol so http/https variants both match. Trailing slash on
	// the base prevents prefix collisions like /uploads vs /uploads-staging.
	$normalized_url      = preg_replace( '#^https?://#i', '//', $url );
	$normalized_base_url = rtrim( preg_replace( '#^https?://#i', '//', $base_url ), '/' ) . '/';

	if ( strpos( $normalized_url, $normalized_base_url ) !== 0 ) {
		return $url;
	}

	// Build the filesystem path from the URL path component only — query
	// strings (?ver=…) were leaking in before and breaking file_exists().
	$base_url_path = wp_parse_url( $base_url, PHP_URL_PATH );
	if ( ! $base_url_path ) {
		return $url;
	}
	$relative  = substr( $path_only, strlen( $base_url_path ) );
	$file_path = $base_dir . $relative;
	$webp_path = ekwa_webp_companion_path( $file_path );

	if ( ! file_exists( $webp_path ) ) {
		return $url;
	}

	return ekwa_webp_companion_url( $url );
}

/**
 * Rewrite every URL in a srcset string to its WebP companion.
 */
function ekwa_webp_rewrite_srcset( $srcset ) {
	if ( ! is_string( $srcset ) || $srcset === '' ) {
		return $srcset;
	}
	$parts = array_map( 'trim', explode( ',', $srcset ) );
	foreach ( $parts as &$part ) {
		if ( $part === '' ) {
			continue;
		}
		$bits    = preg_split( '/\s+/', $part, 2 );
		$bits[0] = ekwa_webp_url_for( $bits[0] );
		$part    = implode( ' ', $bits );
	}
	unset( $part );
	return implode( ', ', $parts );
}

/**
 * Swap every `src="..."` and `srcset="..."` URL inside rendered block HTML.
 *
 * Runs on every block but bails immediately when the HTML has no `<img`,
 * so non-image blocks pay near-zero cost. Covers core/image, core/cover,
 * core/gallery, core/media-text, ekwa/image, and any custom block that
 * outputs an `<img>`.
 */
function ekwa_webp_filter_block_html( $html, $block ) {
	// Always strip the per-image opt-out marker on the way out, even when WebP
	// is disabled or unsupported, so it never reaches the browser.
	if ( strpos( $html, '<img' ) === false ) {
		return $html;
	}

	$enabled = ekwa_webp_is_enabled() && ekwa_webp_browser_supports();

	// Walk each <img ...> tag in isolation. Per-image `data-ekwa-no-webp="1"`
	// opts that tag out of the URL swap.
	$out = preg_replace_callback(
		'/<img\b[^>]*>/i',
		function ( $m ) use ( $enabled ) {
			$tag    = $m[0];
			$opted  = ( false !== stripos( $tag, 'data-ekwa-no-webp' ) );

			if ( $enabled && ! $opted ) {
				$tag = preg_replace_callback(
					'/\s(src|srcset|data-src|data-srcset)=(["\'])([^"\']+)\2/i',
					function ( $am ) {
						$attr  = $am[1];
						$quote = $am[2];
						$value = $am[3];
						$is_srcset = ( $attr === 'srcset' || $attr === 'data-srcset' );
						$new_value = $is_srcset
							? ekwa_webp_rewrite_srcset( $value )
							: ekwa_webp_url_for( $value );
						return ' ' . $attr . '=' . $quote . $new_value . $quote;
					},
					$tag
				);
			}

			// Always strip the opt-out marker so it doesn't appear in the DOM.
			$tag = preg_replace( '/\s+data-ekwa-no-webp=(["\'])[^"\']*\1/i', '', $tag );

			return $tag;
		},
		$html
	);

	return ( $out === null ) ? $html : $out;
}

/**
 * Catches images rendered outside the block pipeline:
 *   - Featured images via the_post_thumbnail() / wp_get_attachment_image()
 *   - Site logo, custom logo
 *   - Anything templates emit through get_the_post_thumbnail() etc.
 *
 * The render_block filter never sees these, so we hook here directly.
 */
function ekwa_webp_filter_attachment_image_attrs( $attr ) {
	if ( ! ekwa_webp_is_enabled() || ! ekwa_webp_browser_supports() ) {
		return $attr;
	}
	if ( ! empty( $attr['src'] ) ) {
		$attr['src'] = ekwa_webp_url_for( $attr['src'] );
	}
	if ( ! empty( $attr['srcset'] ) ) {
		$attr['srcset'] = ekwa_webp_rewrite_srcset( $attr['srcset'] );
	}
	return $attr;
}

/**
 * Tell caches that the response varies by Accept header — different bytes get
 * served to WebP-capable vs. legacy browsers.
 */
function ekwa_webp_send_vary_header() {
	if ( ! ekwa_webp_is_enabled() || is_admin() ) {
		return;
	}
	if ( ! headers_sent() ) {
		header( 'Vary: Accept', false );
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// REST: bulk regenerate
// ─────────────────────────────────────────────────────────────────────────────

function ekwa_webp_register_rest() {
	register_rest_route( 'ekwa/v1', '/webp-regen-batch', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_webp_rest_regen_batch',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args' => array(
			'offset'     => array( 'type' => 'integer', 'default' => 0 ),
			'batch_size' => array( 'type' => 'integer', 'default' => 10 ),
		),
	) );

	register_rest_route( 'ekwa/v1', '/webp-status', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'ekwa_webp_rest_status',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
	) );

	// Per-attachment regenerate — used by the ekwa/image block's "Regenerate
	// WebP" inspector button when an individual image is broken.
	register_rest_route( 'ekwa/v1', '/webp-regen-one', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_webp_rest_regen_one',
		'permission_callback' => function () {
			// Editors can fix broken images in their posts — no need to
			// gate this behind manage_options.
			return current_user_can( 'edit_posts' );
		},
		'args' => array(
			'attachment_id' => array( 'type' => 'integer', 'required' => true ),
		),
	) );

	// Manual WebP replacement — used when GD/Imagick simply cannot encode a
	// particular source image and the user has converted it offline.
	register_rest_route( 'ekwa/v1', '/webp-upload-one', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_webp_rest_upload_one',
		'permission_callback' => function () {
			return current_user_can( 'upload_files' );
		},
		// File comes from $_FILES['file']; we read the attachment id from POST.
	) );
}

function ekwa_webp_query_image_ids( $offset, $limit ) {
	$q = new WP_Query( array(
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'post_mime_type'         => array( 'image/jpeg', 'image/png' ),
		'posts_per_page'         => $limit,
		'offset'                 => $offset,
		'fields'                 => 'ids',
		'orderby'                => 'ID',
		'order'                  => 'ASC',
		'no_found_rows'          => false,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	) );
	return array(
		'ids'   => $q->posts,
		'total' => (int) $q->found_posts,
	);
}

function ekwa_webp_rest_regen_batch( WP_REST_Request $request ) {
	$offset     = max( 0, (int) $request->get_param( 'offset' ) );
	$batch_size = (int) $request->get_param( 'batch_size' );
	if ( $batch_size < 1 ) { $batch_size = 1; }
	// Cap lowered from 50 → 10. GD/Imagick decode each JPG to a full bitmap,
	// which blows past memory_limit on shared hosts when batches are large.
	if ( $batch_size > 10 ) { $batch_size = 10; }

	@set_time_limit( 120 );
	// Bump memory only if the host's limit is below 512M. Some hosts disable
	// ini_set; failing silently is fine — we still try.
	$current_limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
	if ( $current_limit > 0 && $current_limit < ( 512 * 1024 * 1024 ) ) {
		@ini_set( 'memory_limit', '512M' );
	}

	// Capture and discard any stray PHP notices/warnings so they can't pollute the JSON body.
	ob_start();

	$processed = 0;
	$generated = 0;
	$errors    = array();
	$total     = 0;

	try {
		$result = ekwa_webp_query_image_ids( $offset, $batch_size );
		$total  = $result['total'];

		foreach ( $result['ids'] as $id ) {
			$processed++;
			// Per-image try/catch so one bad attachment (corrupt file, GD
			// failure, memory hiccup) doesn't kill the whole batch and 500
			// the endpoint. Throwable covers both Exception and Error in PHP 7+.
			try {
				$generated += ekwa_webp_generate_for_attachment( $id );
			} catch ( Throwable $e ) {
				$errors[] = array(
					'attachment_id' => $id,
					'message'       => $e->getMessage(),
				);
				error_log( '[ekwa-webp] attachment ' . $id . ': ' . $e->getMessage() );
			}

			// Force GC between images so GD/Imagick bitmaps don't accumulate
			// across the loop. PHP releases the editor handle but the
			// underlying image resource can linger until the next cycle.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}
	} catch ( Throwable $e ) {
		ob_end_clean();
		error_log( '[ekwa-webp] regen batch fatal: ' . $e->getMessage() );
		return new WP_Error(
			'ekwa_webp_regen_failed',
			$e->getMessage(),
			array(
				'status'    => 500,
				'offset'    => $offset,
				'processed' => $processed,
				'errors'    => $errors,
				'trace'     => defined( 'WP_DEBUG' ) && WP_DEBUG ? $e->getTraceAsString() : null,
			)
		);
	}

	ob_end_clean();

	$next_offset = $offset + $processed;
	$done        = ( $next_offset >= $total ) || $processed === 0;

	return rest_ensure_response( array(
		'processed'   => $processed,
		'generated'   => $generated,
		'next_offset' => $next_offset,
		'total'       => $total,
		'done'        => $done,
		'errors'      => $errors,
	) );
}

/**
 * Force-regenerate the `.webp` companions for one attachment.
 *
 * Deletes existing companions first so we never short-circuit on the mtime
 * check — this is the user explicitly asking for a fresh pass, presumably
 * because the existing file is empty or visually broken.
 */
function ekwa_webp_rest_regen_one( WP_REST_Request $request ) {
	$attachment_id = (int) $request->get_param( 'attachment_id' );
	if ( $attachment_id < 1 ) {
		return new WP_Error( 'bad_attachment', 'attachment_id is required.', array( 'status' => 400 ) );
	}

	$post = get_post( $attachment_id );
	if ( ! $post || 'attachment' !== $post->post_type ) {
		return new WP_Error( 'not_attachment', 'Attachment not found.', array( 'status' => 404 ) );
	}

	$mime = get_post_mime_type( $attachment_id );
	if ( ! ekwa_webp_supported_mime( $mime ) ) {
		return new WP_Error( 'unsupported_mime', 'Only JPEG and PNG can be converted.', array( 'status' => 400 ) );
	}

	$original = get_attached_file( $attachment_id );
	if ( ! $original || ! file_exists( $original ) ) {
		return new WP_Error( 'no_source_file', 'Source file is missing on disk.', array( 'status' => 404 ) );
	}

	@set_time_limit( 60 );

	// Wipe existing companions (original + every registered size) so the
	// idempotent mtime check in ekwa_webp_generate_file() can't skip work.
	$wiped = 0;
	$candidates = array( $original );
	$meta = wp_get_attachment_metadata( $attachment_id );
	if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		$dir = trailingslashit( dirname( $original ) );
		foreach ( $meta['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$candidates[] = $dir . $size['file'];
			}
		}
	}
	foreach ( $candidates as $path ) {
		$webp   = ekwa_webp_companion_path( $path );
		$legacy = $path . '.webp';
		foreach ( array_unique( array( $webp, $legacy ) ) as $companion ) {
			if ( file_exists( $companion ) ) {
				@unlink( $companion );
				$wiped++;
			}
		}
	}

	$generated = 0;
	try {
		$generated = ekwa_webp_generate_for_attachment( $attachment_id );
	} catch ( Throwable $e ) {
		error_log( '[ekwa-webp] regen-one ' . $attachment_id . ': ' . $e->getMessage() );
		return new WP_Error( 'regen_failed', $e->getMessage(), array( 'status' => 500 ) );
	}

	// Verify the primary (original-size) WebP actually wrote successfully.
	// The safety net inside ekwa_webp_generate_file() will have already
	// unlinked an empty file, so a missing companion means real failure.
	$primary_webp = ekwa_webp_companion_path( $original );
	$primary_ok   = file_exists( $primary_webp ) && filesize( $primary_webp ) > 0;

	return rest_ensure_response( array(
		'attachment_id'    => $attachment_id,
		'wiped'            => $wiped,
		'generated'        => $generated,
		'primary_ok'       => $primary_ok,
		'primary_webp_url' => $primary_ok ? ekwa_webp_companion_url( wp_get_attachment_url( $attachment_id ) ) : '',
	) );
}

/**
 * Accept a user-supplied `.webp` file and write it as the primary companion
 * for an attachment. Used as an escape hatch when server-side conversion
 * cannot produce a valid WebP for a specific image (e.g. unusual color
 * profile, GD/Imagick crash) and the user has converted it offline.
 *
 * Replaces ONLY the primary companion (matching the original-size file).
 * The size-specific companions stay as whatever auto-generation produced;
 * they're less likely to be visually critical and we don't have user-supplied
 * variants to drop in for each registered size.
 */
function ekwa_webp_rest_upload_one( WP_REST_Request $request ) {
	$attachment_id = (int) $request->get_param( 'attachment_id' );
	if ( $attachment_id < 1 ) {
		return new WP_Error( 'bad_attachment', 'attachment_id is required.', array( 'status' => 400 ) );
	}

	$post = get_post( $attachment_id );
	if ( ! $post || 'attachment' !== $post->post_type ) {
		return new WP_Error( 'not_attachment', 'Attachment not found.', array( 'status' => 404 ) );
	}

	if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
		return new WP_Error( 'no_file', 'No file uploaded.', array( 'status' => 400 ) );
	}
	$file = $_FILES['file'];
	if ( ! empty( $file['error'] ) ) {
		return new WP_Error( 'upload_error', 'Upload error code ' . (int) $file['error'], array( 'status' => 400 ) );
	}
	if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'bad_upload', 'Invalid upload.', array( 'status' => 400 ) );
	}

	// Verify it really is a WebP — refuse anything else so a user can't
	// drop a renamed .jpg into the slot and break delivery silently.
	$bytes = file_get_contents( $file['tmp_name'], false, null, 0, 16 );
	if ( ! $bytes || strlen( $bytes ) < 12 || substr( $bytes, 0, 4 ) !== 'RIFF' || substr( $bytes, 8, 4 ) !== 'WEBP' ) {
		return new WP_Error( 'not_webp', 'Uploaded file is not a valid WebP image (RIFF/WEBP header missing).', array( 'status' => 400 ) );
	}

	$original = get_attached_file( $attachment_id );
	if ( ! $original ) {
		return new WP_Error( 'no_source_file', 'Source file is missing on disk.', array( 'status' => 404 ) );
	}

	$dest = ekwa_webp_companion_path( $original );
	if ( $dest === $original ) {
		// Means the original extension didn't match jpg/jpeg/png and the
		// regex didn't change anything — refuse to overwrite the source.
		return new WP_Error( 'unsupported_source', 'Attachment is not a JPEG or PNG.', array( 'status' => 400 ) );
	}

	if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
		// Fallback: try copy + unlink (some PHP/Windows combos refuse move).
		if ( ! @copy( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'write_failed', 'Could not write file to ' . $dest, array( 'status' => 500 ) );
		}
		@unlink( $file['tmp_name'] );
	}

	// Sanity check the written file.
	$size = file_exists( $dest ) ? filesize( $dest ) : 0;
	if ( $size < 100 ) {
		@unlink( $dest );
		return new WP_Error( 'empty_result', 'Written file is empty.', array( 'status' => 500 ) );
	}

	return rest_ensure_response( array(
		'attachment_id'    => $attachment_id,
		'bytes_written'    => $size,
		'primary_ok'       => true,
		'primary_webp_url' => ekwa_webp_companion_url( wp_get_attachment_url( $attachment_id ) ),
	) );
}

function ekwa_webp_rest_status( WP_REST_Request $request ) {
	$result = ekwa_webp_query_image_ids( 0, 1 );
	return rest_ensure_response( array(
		'total_images' => $result['total'],
		'enabled'      => ekwa_webp_is_enabled(),
		'quality'      => ekwa_webp_quality(),
	) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Hook registration
// ─────────────────────────────────────────────────────────────────────────────

add_filter( 'wp_generate_attachment_metadata', 'ekwa_webp_on_upload', 10, 2 );
add_action( 'delete_attachment',               'ekwa_webp_on_delete' );
add_filter( 'render_block',                    'ekwa_webp_filter_block_html', 20, 2 );
add_filter( 'wp_get_attachment_image_attributes', 'ekwa_webp_filter_attachment_image_attrs', 20 );
add_action( 'send_headers',                    'ekwa_webp_send_vary_header' );
add_action( 'rest_api_init',                   'ekwa_webp_register_rest' );
