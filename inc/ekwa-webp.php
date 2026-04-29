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

	$webp_path = $source_path . '.webp';

	// Idempotent: skip when companion is newer than source.
	if ( file_exists( $webp_path ) && filemtime( $webp_path ) >= filemtime( $source_path ) ) {
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

	ob_end_clean();

	// Palette PNG fallback — WP_Image_Editor_GD::save() fails on indexed PNGs.
	if ( ! $ok && $mime['type'] === 'image/png' ) {
		$ok = ekwa_webp_gd_png_fallback( $source_path, $webp_path );
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
		$webp = $path . '.webp';
		if ( file_exists( $webp ) ) {
			@unlink( $webp );
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

	$ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) );
	if ( $ext !== 'jpg' && $ext !== 'jpeg' && $ext !== 'png' ) {
		return $url;
	}

	$upload   = wp_upload_dir();
	$base_url = isset( $upload['baseurl'] ) ? $upload['baseurl'] : '';
	$base_dir = isset( $upload['basedir'] ) ? $upload['basedir'] : '';
	if ( ! $base_url || ! $base_dir ) {
		return $url;
	}

	// Normalize protocol so http/https variants both match.
	$normalized_url      = preg_replace( '#^https?://#i', '//', $url );
	$normalized_base_url = preg_replace( '#^https?://#i', '//', $base_url );

	if ( strpos( $normalized_url, $normalized_base_url ) !== 0 ) {
		return $url;
	}

	$relative  = substr( $normalized_url, strlen( $normalized_base_url ) );
	$file_path = $base_dir . $relative;
	$webp_path = $file_path . '.webp';

	if ( ! file_exists( $webp_path ) ) {
		return $url;
	}

	return $url . '.webp';
}

/**
 * Swap every `src="..."` URL inside rendered block HTML to its WebP companion
 * when the browser supports it.
 *
 * Registered narrowly on render_block so unrelated blocks pay zero cost.
 */
function ekwa_webp_filter_block_html( $html, $block ) {
	if ( ! ekwa_webp_is_enabled() ) {
		return $html;
	}
	if ( ! ekwa_webp_browser_supports() ) {
		return $html;
	}

	$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
	if ( $name !== 'ekwa/image' && ! ( $name === 'core/image' && ekwa_webp_apply_to_core_image() ) ) {
		return $html;
	}

	if ( strpos( $html, '<img' ) === false ) {
		return $html;
	}

	$replacer = function ( $matches ) {
		$attr     = $matches[1]; // src or data-src or srcset
		$quote    = $matches[2];
		$value    = $matches[3];

		if ( $attr === 'srcset' ) {
			$parts = array_map( 'trim', explode( ',', $value ) );
			foreach ( $parts as &$part ) {
				if ( $part === '' ) {
					continue;
				}
				$bits     = preg_split( '/\s+/', $part, 2 );
				$bits[0]  = ekwa_webp_url_for( $bits[0] );
				$part     = implode( ' ', $bits );
			}
			unset( $part );
			$new_value = implode( ', ', $parts );
		} else {
			$new_value = ekwa_webp_url_for( $value );
		}

		return ' ' . $attr . '=' . $quote . $new_value . $quote;
	};

	return preg_replace_callback(
		'/\s(src|srcset)=(["\'])([^"\']+)\2/i',
		$replacer,
		$html
	);
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
	if ( $batch_size > 50 ) { $batch_size = 50; }

	@set_time_limit( 60 );

	// Capture and discard any stray PHP notices/warnings so they can't pollute the JSON body.
	ob_start();

	$result    = ekwa_webp_query_image_ids( $offset, $batch_size );
	$processed = 0;
	$generated = 0;

	foreach ( $result['ids'] as $id ) {
		$generated += ekwa_webp_generate_for_attachment( $id );
		$processed++;
	}

	ob_end_clean();

	$next_offset = $offset + $processed;
	$done        = ( $next_offset >= $result['total'] ) || $processed === 0;

	return rest_ensure_response( array(
		'processed'    => $processed,
		'generated'    => $generated,
		'next_offset'  => $next_offset,
		'total'        => $result['total'],
		'done'         => $done,
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
add_action( 'send_headers',                    'ekwa_webp_send_vary_header' );
add_action( 'rest_api_init',                   'ekwa_webp_register_rest' );
