<?php
/**
 * Media manifest — a live inventory of the site's image/video attachments.
 *
 * Every media-library change (upload, edit, alt-text change, delete) updates a
 * manifest stored in the ekwa_media_manifest option and mirrored to
 * uploads/ekwa-media-manifest.json. The AI Site Generator sends the manifest to
 * Gemini so the planner can assign REAL uploaded files (by URL) to the header,
 * footer, and page sections it designs, instead of placehold.co placeholders.
 *
 * Exposes POST /ekwa/v1/media-manifest-rebuild (admins) to rebuild from scratch.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum attachments kept in the manifest (newest first). Bounds both the
 * option size and the JSON file; the AI prompt context is capped separately.
 */
const EKWA_MEDIA_MANIFEST_MAX = 500;

/**
 * Absolute path of the mirrored JSON manifest file.
 *
 * @return string
 */
function ekwa_media_manifest_file_path() {
	$uploads = wp_get_upload_dir();
	return trailingslashit( $uploads['basedir'] ) . 'ekwa-media-manifest.json';
}

/**
 * Build one manifest entry for an attachment.
 *
 * @param int $attachment_id Attachment ID.
 * @return array|null Entry array, or null when the attachment is not a
 *                    manifest-worthy type (only images and videos are tracked).
 */
function ekwa_media_manifest_entry( $attachment_id ) {
	$post = get_post( $attachment_id );
	if ( ! $post || 'attachment' !== $post->post_type || 'trash' === $post->post_status ) {
		return null;
	}

	$mime = (string) get_post_mime_type( $post );
	if ( 0 === strpos( $mime, 'image/' ) ) {
		$type = 'image';
	} elseif ( 0 === strpos( $mime, 'video/' ) ) {
		$type = 'video';
	} else {
		return null;
	}

	$url = wp_get_attachment_url( $attachment_id );
	if ( ! $url ) {
		return null;
	}

	$meta  = wp_get_attachment_metadata( $attachment_id );
	$entry = array(
		'id'       => (int) $attachment_id,
		'type'     => $type,
		'mime'     => $mime,
		'url'      => $url,
		'filename' => wp_basename( $url ),
		'title'    => (string) $post->post_title,
		'alt'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
		'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
		'date'     => (string) $post->post_date_gmt,
	);

	if ( 'video' === $type && ! empty( $meta['length_formatted'] ) ) {
		$entry['duration'] = (string) $meta['length_formatted'];
	}

	return $entry;
}

/**
 * The manifest: attachment id → entry, newest first. Rebuilds lazily when the
 * option has never been populated (e.g. right after the theme update).
 *
 * @return array<int,array>
 */
function ekwa_media_manifest_get() {
	$manifest = get_option( 'ekwa_media_manifest', null );
	if ( ! is_array( $manifest ) ) {
		$manifest = ekwa_media_manifest_rebuild();
	}
	return $manifest;
}

/**
 * Rebuild the manifest from the media library and persist it.
 *
 * @return array<int,array> The fresh manifest.
 */
function ekwa_media_manifest_rebuild() {
	$ids = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => array( 'image', 'video' ),
		'posts_per_page' => EKWA_MEDIA_MANIFEST_MAX,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'fields'         => 'ids',
	) );

	$manifest = array();
	foreach ( $ids as $id ) {
		$entry = ekwa_media_manifest_entry( $id );
		if ( $entry ) {
			$manifest[ (int) $id ] = $entry;
		}
	}

	ekwa_media_manifest_save( $manifest );
	return $manifest;
}

/**
 * Persist the manifest: option (autoload off — it can be sizeable) + JSON file.
 *
 * @param array<int,array> $manifest
 */
function ekwa_media_manifest_save( $manifest ) {
	// Newest first, bounded.
	uasort( $manifest, function ( $a, $b ) {
		return strcmp( (string) ( $b['date'] ?? '' ), (string) ( $a['date'] ?? '' ) );
	} );
	if ( count( $manifest ) > EKWA_MEDIA_MANIFEST_MAX ) {
		$manifest = array_slice( $manifest, 0, EKWA_MEDIA_MANIFEST_MAX, true );
	}

	update_option( 'ekwa_media_manifest', $manifest, false );

	// Mirror to uploads/ekwa-media-manifest.json — best-effort; the option is
	// the source of truth, the file is the exportable artifact.
	$json = wp_json_encode( array(
		'generated' => gmdate( 'c' ),
		'count'     => count( $manifest ),
		'items'     => array_values( $manifest ),
	), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	if ( false !== $json ) {
		@file_put_contents( ekwa_media_manifest_file_path(), $json );
	}
}

/**
 * Add or refresh one attachment's entry (no-op for untracked types).
 *
 * @param int $attachment_id
 */
function ekwa_media_manifest_touch( $attachment_id ) {
	$entry = ekwa_media_manifest_entry( $attachment_id );
	if ( ! $entry ) {
		return;
	}
	$manifest                           = ekwa_media_manifest_get();
	$manifest[ (int) $attachment_id ]   = $entry;
	ekwa_media_manifest_save( $manifest );
}

/**
 * Remove one attachment's entry.
 *
 * @param int $attachment_id
 */
function ekwa_media_manifest_forget( $attachment_id ) {
	$manifest = ekwa_media_manifest_get();
	if ( isset( $manifest[ (int) $attachment_id ] ) ) {
		unset( $manifest[ (int) $attachment_id ] );
		ekwa_media_manifest_save( $manifest );
	}
}

// ─── Keep the manifest in sync with the media library ───────────────────────

add_action( 'add_attachment', 'ekwa_media_manifest_touch' );
add_action( 'attachment_updated', 'ekwa_media_manifest_touch' );
add_action( 'delete_attachment', 'ekwa_media_manifest_forget' );

// Dimensions only exist after WP generates attachment metadata (which happens
// AFTER add_attachment), so re-touch when the metadata is saved/updated.
add_filter( 'wp_update_attachment_metadata', function ( $data, $attachment_id ) {
	// Defer to shutdown so the metadata being saved right now is what we read.
	add_action( 'shutdown', function () use ( $attachment_id ) {
		ekwa_media_manifest_touch( $attachment_id );
	} );
	return $data;
}, 20, 2 );

// Alt text lives in post meta, not the attachment post — track its changes too.
foreach ( array( 'added_post_meta', 'updated_post_meta' ) as $ekwa_mm_hook ) {
	add_action( $ekwa_mm_hook, function ( $meta_id, $object_id, $meta_key ) {
		if ( '_wp_attachment_image_alt' === $meta_key ) {
			ekwa_media_manifest_touch( $object_id );
		}
	}, 10, 3 );
}
unset( $ekwa_mm_hook );

// ─── AI prompt context ──────────────────────────────────────────────────────

/**
 * One human/AI-readable line describing a manifest entry.
 *
 * @param array $entry Manifest entry.
 * @return string e.g. `id 42 · image · 1600×900 · alt: "Office lobby" · lobby.jpg`
 */
function ekwa_media_manifest_line( $entry ) {
	$bits   = array( 'id ' . (int) $entry['id'], $entry['type'] );
	$w      = (int) ( $entry['width'] ?? 0 );
	$h      = (int) ( $entry['height'] ?? 0 );
	if ( $w && $h ) {
		$bits[] = $w . '×' . $h;
	}
	if ( 'video' === $entry['type'] ) {
		$bits[] = $entry['mime'] . ( ! empty( $entry['duration'] ) ? ' · ' . $entry['duration'] : '' );
	}
	if ( '' !== trim( (string) ( $entry['alt'] ?? '' ) ) ) {
		$bits[] = 'alt: "' . trim( $entry['alt'] ) . '"';
	} elseif ( '' !== trim( (string) ( $entry['title'] ?? '' ) ) ) {
		$bits[] = 'title: "' . trim( $entry['title'] ) . '"';
	}
	$bits[] = $entry['filename'];
	return implode( ' · ', $bits );
}

/**
 * Manifest formatted as a prompt section for the site PLANNER, which references
 * items by numeric id. Capped to keep token cost bounded.
 *
 * @param int $limit Maximum items to include.
 * @return string Prompt block, or '' when the library has no usable media.
 */
function ekwa_media_manifest_ai_context( $limit = 80 ) {
	$manifest = ekwa_media_manifest_get();
	if ( empty( $manifest ) ) {
		return '';
	}

	$total = count( $manifest );
	$items = array_slice( $manifest, 0, max( 1, (int) $limit ), true );

	$out = "\n\nMEDIA LIBRARY MANIFEST — real files already uploaded to this site (newest first). "
		. "When you assign media to a section, reference items by their numeric id in \"media_ids\". "
		. "Prefer large landscape images (≥1200px wide) for heroes/sliders, videos for hero video "
		. "backgrounds, and match each file's alt/filename to a section where it makes sense. "
		. "Never assign an id that is not in this list.\n";
	foreach ( $items as $entry ) {
		$out .= '- ' . ekwa_media_manifest_line( $entry ) . "\n";
	}
	if ( $total > count( $items ) ) {
		$out .= '(…' . ( $total - count( $items ) ) . " older items omitted)\n";
	}
	return $out;
}

/**
 * Resolve specific manifest ids into a prompt block for a PART generation call,
 * with full URLs the model must use instead of placeholders.
 *
 * @param array<int> $ids Attachment ids chosen by the planner.
 * @return string Prompt block, or '' when none resolve.
 */
function ekwa_media_manifest_prompt_for_ids( $ids ) {
	$manifest = ekwa_media_manifest_get();
	$lines    = array();
	foreach ( (array) $ids as $id ) {
		$id = (int) $id;
		if ( ! isset( $manifest[ $id ] ) ) {
			continue;
		}
		$e    = $manifest[ $id ];
		$line = '- ' . $e['type'] . ' — ' . $e['url'];
		$w    = (int) ( $e['width'] ?? 0 );
		$h    = (int) ( $e['height'] ?? 0 );
		if ( $w && $h ) {
			$line .= ' (' . $w . '×' . $h . ')';
		}
		if ( 'video' === $e['type'] ) {
			$line .= ' (' . $e['mime'] . ( ! empty( $e['duration'] ) ? ', ' . $e['duration'] : '' ) . ')';
		}
		if ( '' !== trim( (string) ( $e['alt'] ?? '' ) ) ) {
			$line .= ' — alt: "' . trim( $e['alt'] ) . '"';
		}
		$lines[] = $line;
	}
	if ( empty( $lines ) ) {
		return '';
	}
	return "\n\nREAL MEDIA TO USE — these files are already in this site's media library. Use these EXACT URLs "
		. "(with the given width/height and alt where applicable) in the appropriate blocks: ekwa/image src, "
		. "ekwa/slide bgImage, ekwa/hero-video videoUrl (pair it with an image URL as posterUrl), ekwa/video src. "
		. "Do NOT use placehold.co for content this media can cover:\n"
		. implode( "\n", $lines ) . "\n";
}

/**
 * Counts for the settings UI.
 *
 * @return array{images:int, videos:int, total:int}
 */
function ekwa_media_manifest_counts() {
	$manifest = ekwa_media_manifest_get();
	$images   = 0;
	$videos   = 0;
	foreach ( $manifest as $entry ) {
		if ( 'video' === ( $entry['type'] ?? '' ) ) {
			$videos++;
		} else {
			$images++;
		}
	}
	return array(
		'images' => $images,
		'videos' => $videos,
		'total'  => $images + $videos,
	);
}

// ─── REST: manual rebuild ───────────────────────────────────────────────────

add_action( 'rest_api_init', function () {
	register_rest_route( 'ekwa/v1', '/media-manifest-rebuild', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function () {
			ekwa_media_manifest_rebuild();
			return rest_ensure_response( ekwa_media_manifest_counts() );
		},
	) );
} );
