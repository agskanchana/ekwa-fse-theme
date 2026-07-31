<?php
/**
 * Ekwa YouTube Video / Ekwa Vimeo Video — Gutenberg blocks.
 *
 * Two provider-specific blocks (ekwa/youtube-video, ekwa/vimeo-video) sharing
 * one render/metadata/transcript core. URL in → auto-fetched title, thumbnail,
 * duration, upload date; click-to-play inline player or (opt-in) the theme's
 * existing GLightbox popup; optional transcript (YouTube: fetchable from the
 * editor; either provider: paste your own). Schema.org VideoObject markup on
 * every render.
 *
 * Deliberately leans on infrastructure the theme already ships instead of
 * re-bundling it: GLightbox (inc/ekwa-lightbox.php), GA4 (inc/ekwa-analytics.php
 * — the front-end just calls window.gtag() if it exists), lazy-loading mode
 * (ekwa_perf_lazy_mode()), automatic WebP rewriting (inc/ekwa-webp.php runs on
 * all block output already), and the transcript toggle markup/assets already
 * used by ekwa/video (ekwa_video_transcript_assets_once() below in
 * inc/ekwa-blocks.php).
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// BLOCK REGISTRATION
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Register the two blocks and their editor scripts.
 */
function ekwa_video_embed_register_blocks() {
	wp_register_script(
		'ekwa-youtube-video-editor',
		get_theme_file_uri( 'assets/js/ekwa-youtube-video-editor.js' ),
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-api-fetch' ),
		filemtime( get_theme_file_path( 'assets/js/ekwa-youtube-video-editor.js' ) ),
		true
	);
	wp_register_script(
		'ekwa-vimeo-video-editor',
		get_theme_file_uri( 'assets/js/ekwa-vimeo-video-editor.js' ),
		array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-api-fetch' ),
		filemtime( get_theme_file_path( 'assets/js/ekwa-vimeo-video-editor.js' ) ),
		true
	);

	register_block_type(
		ekwa_block_dir( 'ekwa-youtube-video' ),
		array( 'render_callback' => 'ekwa_render_youtube_video_block' )
	);
	register_block_type(
		ekwa_block_dir( 'ekwa-vimeo-video' ),
		array( 'render_callback' => 'ekwa_render_vimeo_video_block' )
	);
}
add_action( 'init', 'ekwa_video_embed_register_blocks' );

// ═══════════════════════════════════════════════════════════════════════════════
// URL PARSING & METADATA
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Extract the video ID + embed URL for a provider from an arbitrary pasted URL.
 *
 * @param string $url
 * @param string $provider 'youtube' | 'vimeo'.
 * @return array{videoId:string, embedUrl:string}
 */
function ekwa_video_extract_info( $url, $provider ) {
	$info = array(
		'videoId'  => '',
		'embedUrl' => '',
	);

	if ( 'youtube' === $provider ) {
		if ( preg_match( '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $m ) ) {
			$info['videoId']  = $m[1];
			$info['embedUrl'] = 'https://www.youtube-nocookie.com/embed/' . $m[1] . '?rel=0';
		}
	} elseif ( 'vimeo' === $provider ) {
		if ( preg_match( '/(?:www\.)?vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/[^\/]*\/videos\/|album\/\d+\/video\/|video\/|)(\d+)(?:$|\/|\?)/', $url, $m )
			|| preg_match( '/vimeo\.com\/(\d+)/', $url, $m ) ) {
			$info['videoId']  = $m[1];
			$info['embedUrl'] = 'https://player.vimeo.com/video/' . $m[1];
		}
	}

	return $info;
}

/**
 * Fetch (and cache) title/description/duration/upload date/thumbnail for a video.
 *
 * @param string $provider 'youtube' | 'vimeo'.
 * @param string $video_id
 * @return array<string,string>
 */
function ekwa_video_get_metadata( $provider, $video_id ) {
	if ( empty( $video_id ) ) {
		return array();
	}

	$cache_key = 'ekwa_vid_meta_' . md5( $provider . '|' . $video_id );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$metadata = ( 'youtube' === $provider )
		? ekwa_video_get_youtube_metadata( $video_id )
		: ekwa_video_get_vimeo_metadata( $video_id );

	// Cache positive results for a day; empty/failed lookups for 5 minutes only,
	// so a transient API hiccup doesn't lock the block out of metadata for a day.
	$has_data = ! empty( $metadata['videoTitle'] );
	set_transient( $cache_key, $metadata, $has_data ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS );

	return $metadata;
}

/**
 * YouTube metadata via the Data API (if a key is configured), else scraped
 * from the public watch page (no key needed — this is what actually supplies
 * duration/upload date most sites run without a key), else oEmbed as a last
 * resort (title only).
 *
 * @param string $video_id
 * @return array<string,string>
 */
function ekwa_video_get_youtube_metadata( $video_id ) {
	$api_key = apply_filters( 'ekwa_video_youtube_api_key', defined( 'EKWA_YOUTUBE_API_KEY' ) ? EKWA_YOUTUBE_API_KEY : '' );

	if ( ! empty( $api_key ) ) {
		$api_url  = 'https://www.googleapis.com/youtube/v3/videos?id=' . rawurlencode( $video_id )
			. '&key=' . rawurlencode( $api_key ) . '&part=snippet,contentDetails';
		$response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );

		if ( ! is_wp_error( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $data['items'][0] ) ) {
				$video           = $data['items'][0];
				$snippet         = $video['snippet'] ?? array();
				$content_details = $video['contentDetails'] ?? array();

				return array(
					'videoTitle'       => $snippet['title'] ?? '',
					'videoDescription' => isset( $snippet['description'] ) ? wp_trim_words( $snippet['description'], 30 ) : '',
					'videoDuration'    => $content_details['duration'] ?? '',
					'uploadDate'       => $snippet['publishedAt'] ?? '',
					'thumbnailUrl'     => ekwa_video_best_youtube_thumbnail( $video_id, $snippet ),
				);
			}
		}
	}

	// No API key (or the API call failed/returned nothing) — scrape the public
	// watch page. Unlike oEmbed below, this actually yields duration and
	// upload date, so it's tried first.
	$scraped = ekwa_video_youtube_metadata_from_watch_page( $video_id );
	if ( $scraped ) {
		return $scraped;
	}

	// Last resort: oEmbed gives us a title without needing an API key and
	// without depending on YouTube's watch-page markup staying scrapable.
	$title    = '';
	$response = wp_remote_get(
		'https://www.youtube.com/oembed?url=' . rawurlencode( 'https://www.youtube.com/watch?v=' . $video_id ) . '&format=json',
		array( 'timeout' => 10 )
	);
	if ( ! is_wp_error( $response ) ) {
		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$title = $data['title'] ?? '';
	}

	return array(
		'videoTitle'       => $title,
		'videoDescription' => '',
		'videoDuration'    => '',
		'uploadDate'       => '',
		'thumbnailUrl'     => 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg',
	);
}

/**
 * Scrape title/description/duration/upload date/thumbnail out of the public
 * watch page's ytInitialPlayerResponse — the only source (short of a paid
 * Data API key) that actually has duration and upload date; oEmbed never
 * returns either for YouTube.
 *
 * @param string $video_id
 * @return array<string,string>|null Null if the page couldn't be read/parsed.
 */
function ekwa_video_youtube_metadata_from_watch_page( $video_id ) {
	$data = ekwa_video_youtube_watch_page_player_response( $video_id );
	if ( ! $data ) {
		return null;
	}

	$details     = $data['videoDetails'] ?? array();
	$microformat = $data['microformat']['playerMicroformatRenderer'] ?? array();

	$title       = $microformat['title']['simpleText'] ?? $details['title'] ?? '';
	$description = $microformat['description']['simpleText'] ?? $details['shortDescription'] ?? '';
	$length      = $microformat['lengthSeconds'] ?? $details['lengthSeconds'] ?? '';
	$upload_date = $microformat['uploadDate'] ?? $microformat['publishDate'] ?? '';

	// If none of these came through, the page was probably a consent/CAPTCHA
	// wall rather than the real watch page — let the caller fall through to
	// oEmbed instead of returning an all-empty result.
	if ( '' === $title && '' === $length && '' === $upload_date ) {
		return null;
	}

	$thumbnails = $microformat['thumbnail']['thumbnails'] ?? $details['thumbnail']['thumbnails'] ?? array();
	$thumbnail  = ! empty( $thumbnails ) ? ( end( $thumbnails )['url'] ?? '' ) : ''; // Largest is listed last.

	return array(
		'videoTitle'       => $title,
		'videoDescription' => '' !== $description ? wp_trim_words( $description, 30 ) : '',
		'videoDuration'    => '' !== $length ? ekwa_video_seconds_to_iso8601( (int) $length ) : '',
		'uploadDate'       => '' !== $upload_date ? ekwa_video_date_to_iso8601( $upload_date ) : '',
		'thumbnailUrl'     => $thumbnail ? $thumbnail : ( 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg' ),
	);
}

/**
 * Pick the best available YouTube thumbnail from an API snippet, falling back
 * to the predictable img.youtube.com URL pattern.
 *
 * @param string $video_id
 * @param array  $snippet
 * @return string
 */
function ekwa_video_best_youtube_thumbnail( $video_id, $snippet ) {
	if ( ! empty( $snippet['thumbnails'] ) ) {
		foreach ( array( 'maxres', 'high', 'medium', 'default' ) as $size ) {
			if ( ! empty( $snippet['thumbnails'][ $size ]['url'] ) ) {
				return $snippet['thumbnails'][ $size ]['url'];
			}
		}
	}
	return 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg';
}

/**
 * Normalize a YouTube watch-page date ("2011-10-19", occasionally already
 * full ISO 8601) into the same Y-m-d\TH:i:s\Z shape used across all providers
 * for the uploadDate attribute (matches ekwa_video_vimeo_date_to_iso()).
 *
 * @param string $date
 * @return string
 */
function ekwa_video_date_to_iso8601( $date ) {
	if ( empty( $date ) ) {
		return '';
	}
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}T/', $date ) ) {
		return $date;
	}
	$parsed = DateTime::createFromFormat( 'Y-m-d', $date );
	return $parsed ? $parsed->format( 'Y-m-d\TH:i:s\Z' ) : $date;
}

/**
 * Vimeo metadata via its public oEmbed endpoint (no key needed/available).
 *
 * @param string $video_id
 * @return array<string,string>
 */
function ekwa_video_get_vimeo_metadata( $video_id ) {
	$response = wp_remote_get(
		'https://vimeo.com/api/oembed.json?url=' . rawurlencode( 'https://vimeo.com/' . $video_id ),
		array( 'timeout' => 10 )
	);
	if ( is_wp_error( $response ) ) {
		return array();
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! $data ) {
		return array();
	}

	return array(
		'videoTitle'       => $data['title'] ?? '',
		'videoDescription' => $data['description'] ?? '',
		'videoDuration'    => isset( $data['duration'] ) ? ekwa_video_seconds_to_iso8601( (int) $data['duration'] ) : '',
		'uploadDate'       => isset( $data['upload_date'] ) ? ekwa_video_vimeo_date_to_iso( $data['upload_date'] ) : '',
		'thumbnailUrl'     => $data['thumbnail_url'] ?? '',
	);
}

/**
 * @param int $seconds
 * @return string ISO 8601 duration (e.g. PT4M30S).
 */
function ekwa_video_seconds_to_iso8601( $seconds ) {
	$h = (int) floor( $seconds / 3600 );
	$m = (int) floor( ( $seconds % 3600 ) / 60 );
	$s = $seconds % 60;

	$duration = 'PT';
	if ( $h > 0 ) {
		$duration .= $h . 'H';
	}
	if ( $m > 0 ) {
		$duration .= $m . 'M';
	}
	if ( $s > 0 || ( 0 === $h && 0 === $m ) ) {
		$duration .= $s . 'S';
	}
	return $duration;
}

/**
 * Vimeo returns "2016-01-01 01:43:02"; normalize to the same ISO 8601 shape
 * YouTube's API returns so the block attribute is provider-agnostic.
 *
 * @param string $vimeo_date
 * @return string
 */
function ekwa_video_vimeo_date_to_iso( $vimeo_date ) {
	if ( empty( $vimeo_date ) ) {
		return '';
	}
	$date = DateTime::createFromFormat( 'Y-m-d H:i:s', $vimeo_date );
	if ( ! $date ) {
		return $vimeo_date;
	}
	return $date->format( 'Y-m-d\TH:i:s\Z' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// RENDER
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Resolve display dimensions for a thumbnail (used for width/height attrs and
 * the frame's CSS aspect-ratio, so there's zero layout shift on click-to-play).
 *
 * @param string $thumbnail_url
 * @param string $provider
 * @param array  $custom_thumbnail
 * @return array{width:int, height:int}
 */
function ekwa_video_thumbnail_dimensions( $thumbnail_url, $provider, $custom_thumbnail ) {
	if ( ! empty( $custom_thumbnail['width'] ) && ! empty( $custom_thumbnail['height'] ) ) {
		return array(
			'width'  => (int) $custom_thumbnail['width'],
			'height' => (int) $custom_thumbnail['height'],
		);
	}
	if ( ! empty( $custom_thumbnail['id'] ) ) {
		$meta = wp_get_attachment_metadata( (int) $custom_thumbnail['id'] );
		if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			return array(
				'width'  => (int) $meta['width'],
				'height' => (int) $meta['height'],
			);
		}
	}

	if ( 'vimeo' === $provider ) {
		if ( preg_match( '/_(\d+)x(\d+)/', $thumbnail_url, $m ) ) {
			return array( 'width' => (int) $m[1], 'height' => (int) $m[2] );
		}
		if ( preg_match( '/_d?_?(\d+)(?:\?|$)/', $thumbnail_url, $m ) ) {
			$w = (int) $m[1];
			return array( 'width' => $w, 'height' => (int) round( $w * 9 / 16 ) );
		}
		return array( 'width' => 640, 'height' => 360 );
	}

	// YouTube.
	if ( false !== strpos( $thumbnail_url, 'hqdefault' ) ) {
		return array( 'width' => 480, 'height' => 360 );
	}
	if ( false !== strpos( $thumbnail_url, 'sddefault' ) ) {
		return array( 'width' => 640, 'height' => 480 );
	}
	return array( 'width' => 1280, 'height' => 720 );
}

/**
 * @param string $duration ISO 8601 duration.
 * @return string Human-readable (e.g. "4:30" or "1:04:30").
 */
function ekwa_video_format_duration( $duration ) {
	if ( preg_match( '/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/', $duration, $m ) ) {
		$h = isset( $m[1] ) ? (int) $m[1] : 0;
		$mi = isset( $m[2] ) ? (int) $m[2] : 0;
		$s = isset( $m[3] ) ? (int) $m[3] : 0;
		return $h > 0 ? sprintf( '%d:%02d:%02d', $h, $mi, $s ) : sprintf( '%d:%02d', $mi, $s );
	}
	return $duration;
}

/**
 * Build the <img> (+ play icon + duration badge) shown before click-to-play,
 * honoring the theme's global lazy-load mode exactly like ekwa/image does.
 *
 * @param string $thumbnail_url
 * @param string $alt
 * @param array  $dims {width, height}
 * @param string $duration ISO 8601 duration, or ''.
 * @param bool   $disable_lazy
 * @param bool   $fetch_priority_high
 * @return string
 */
function ekwa_video_render_thumbnail_inner( $thumbnail_url, $alt, $dims, $duration, $disable_lazy, $fetch_priority_high ) {
	$lazy_mode     = function_exists( 'ekwa_perf_lazy_mode' ) ? ekwa_perf_lazy_mode() : 'native';
	$use_lazysizes = ! $disable_lazy && 'lazysizes' === $lazy_mode;
	$loading_attr  = ( ! $disable_lazy && 'native' === $lazy_mode ) ? ' loading="lazy"' : '';
	$fp_attr       = $fetch_priority_high ? ' fetchpriority="high"' : '';
	$classes       = 'ekwa-video-embed__thumb-img' . ( $use_lazysizes ? ' lazyload' : '' );

	$html = '<img decoding="async"' . $loading_attr . $fp_attr . ' class="' . esc_attr( $classes ) . '"';
	if ( $use_lazysizes ) {
		$html .= ' src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="'
			. ' data-src="' . esc_url( $thumbnail_url ) . '"';
	} else {
		$html .= ' src="' . esc_url( $thumbnail_url ) . '"';
	}
	$html .= ' alt="' . esc_attr( $alt ) . '" width="' . esc_attr( $dims['width'] ) . '" height="' . esc_attr( $dims['height'] ) . '">';

	if ( $use_lazysizes ) {
		$html .= '<noscript><img class="ekwa-video-embed__thumb-img" src="' . esc_url( $thumbnail_url ) . '"'
			. ' alt="' . esc_attr( $alt ) . '" width="' . esc_attr( $dims['width'] ) . '" height="' . esc_attr( $dims['height'] ) . '"></noscript>';
	}

	$html .= '<span class="ekwa-video-embed__play-icon" aria-hidden="true"><svg width="68" height="48" viewBox="0 0 68 48" focusable="false">'
		. '<path d="M66.52 7.74c-.78-2.93-2.49-5.41-5.42-6.19C55.79.13 34 0 34 0S12.21.13 6.9 1.55c-2.93.78-4.63 3.26-5.42 6.19C.06 13.05 0 24 0 24s.06 10.95 1.48 16.26c.78 2.93 2.49 5.41 5.42 6.19C12.21 47.87 34 48 34 48s21.79-.13 27.1-1.55c2.93-.78 4.63-3.26 5.42-6.19C67.94 34.95 68 24 68 24s-.06-10.95-1.48-16.26z" fill="#f00"></path>'
		. '<path d="M45 24L27 14v20" fill="#fff"></path></svg></span>';

	if ( ! empty( $duration ) ) {
		$html .= '<span class="ekwa-video-embed__duration">' . esc_html( ekwa_video_format_duration( $duration ) ) . '</span>';
	}

	return $html;
}

/**
 * Shared render body for both blocks and both shortcodes.
 *
 * @param array  $attrs    Block/shortcode attributes (camelCase keys).
 * @param string $provider 'youtube' | 'vimeo'.
 * @return string
 */
function ekwa_video_render( $attrs, $provider ) {
	$video_url = isset( $attrs['videoUrl'] ) ? esc_url_raw( $attrs['videoUrl'] ) : '';
	if ( '' === $video_url ) {
		return '';
	}

	$video_id  = isset( $attrs['videoId'] ) ? sanitize_text_field( $attrs['videoId'] ) : '';
	$embed_url = isset( $attrs['embedUrl'] ) ? esc_url_raw( $attrs['embedUrl'] ) : '';
	if ( '' === $video_id || '' === $embed_url ) {
		$info      = ekwa_video_extract_info( $video_url, $provider );
		$video_id  = '' !== $video_id ? $video_id : $info['videoId'];
		$embed_url = '' !== $embed_url ? $embed_url : $info['embedUrl'];
	}
	if ( '' === $video_id ) {
		return '';
	}

	$video_title       = isset( $attrs['videoTitle'] ) ? (string) $attrs['videoTitle'] : '';
	$video_description = isset( $attrs['videoDescription'] ) ? (string) $attrs['videoDescription'] : '';
	$video_duration     = isset( $attrs['videoDuration'] ) ? (string) $attrs['videoDuration'] : '';
	$upload_date        = isset( $attrs['uploadDate'] ) ? (string) $attrs['uploadDate'] : '';
	$thumbnail_url      = isset( $attrs['thumbnailUrl'] ) ? (string) $attrs['thumbnailUrl'] : '';

	// Fill in anything still missing from the cache/API — keeps content saved
	// before metadata finished loading (or authored via shortcode) fully formed.
	if ( '' === $video_title || '' === $thumbnail_url || '' === $video_duration || '' === $upload_date || '' === $video_description ) {
		$metadata           = ekwa_video_get_metadata( $provider, $video_id );
		$video_title       = '' !== $video_title ? $video_title : ( $metadata['videoTitle'] ?? '' );
		$video_description = '' !== $video_description ? $video_description : ( $metadata['videoDescription'] ?? '' );
		$video_duration     = '' !== $video_duration ? $video_duration : ( $metadata['videoDuration'] ?? '' );
		$upload_date        = '' !== $upload_date ? $upload_date : ( $metadata['uploadDate'] ?? '' );
		$thumbnail_url      = '' !== $thumbnail_url ? $thumbnail_url : ( $metadata['thumbnailUrl'] ?? '' );
	}

	$custom_thumbnail = ( isset( $attrs['customThumbnail'] ) && is_array( $attrs['customThumbnail'] ) ) ? $attrs['customThumbnail'] : array();
	$display_thumb     = ! empty( $custom_thumbnail['url'] ) ? $custom_thumbnail['url'] : $thumbnail_url;
	$display_thumb_alt = ! empty( $custom_thumbnail['alt'] ) ? $custom_thumbnail['alt'] : $video_title;

	$show_title        = ! isset( $attrs['showTitle'] ) || (bool) $attrs['showTitle'];
	$show_description  = isset( $attrs['showDescription'] ) && (bool) $attrs['showDescription'];
	$show_transcript    = isset( $attrs['showTranscript'] ) && (bool) $attrs['showTranscript'];
	$transcript          = isset( $attrs['transcript'] ) ? trim( (string) $attrs['transcript'] ) : '';
	$open_lightbox      = isset( $attrs['openInLightbox'] ) && (bool) $attrs['openInLightbox'];
	$disable_lazy        = isset( $attrs['disableLazyLoad'] ) && (bool) $attrs['disableLazyLoad'];
	$fetch_priority_high = isset( $attrs['fetchPriorityHigh'] ) && (bool) $attrs['fetchPriorityHigh'];
	$class_name          = isset( $attrs['className'] ) ? sanitize_text_field( $attrs['className'] ) : '';
	$anchor              = isset( $attrs['anchor'] ) ? sanitize_html_class( $attrs['anchor'] ) : '';

	$dims  = ekwa_video_thumbnail_dimensions( $display_thumb, $provider, $custom_thumbnail );
	$ratio = $dims['width'] . '/' . $dims['height'];

	$watch_url = ( 'youtube' === $provider )
		? 'https://www.youtube.com/watch?v=' . $video_id
		: 'https://vimeo.com/' . $video_id;

	$play_label = $video_title
		? sprintf( __( 'Play video: %s', 'ekwa' ), $video_title )
		: __( 'Play video', 'ekwa' );

	$wrapper_classes = trim( 'ekwa-video-embed ekwa-video-embed--' . $provider
		. ( $open_lightbox ? ' ekwa-video-embed--lightbox' : '' )
		. ( $class_name ? ' ' . $class_name : '' ) );

	$html = '<div class="' . esc_attr( $wrapper_classes ) . '"'
		. ( $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '' )
		. ' data-provider="' . esc_attr( $provider ) . '"'
		. ' itemprop="video" itemscope itemtype="https://schema.org/VideoObject">';

	if ( $video_title ) {
		$html .= '<meta itemprop="name" content="' . esc_attr( $video_title ) . '">';
	}
	if ( $video_duration ) {
		$html .= '<meta itemprop="duration" content="' . esc_attr( $video_duration ) . '">';
	}
	if ( $upload_date ) {
		$html .= '<meta itemprop="uploadDate" content="' . esc_attr( $upload_date ) . '">';
	}
	if ( $display_thumb ) {
		$html .= '<meta itemprop="thumbnailUrl" content="' . esc_url( $display_thumb ) . '">';
	}
	// Schema.org requires a description; fall back to the title when none was
	// fetched/entered rather than omitting the field (display paragraph below
	// still only shows when $video_description itself is non-empty).
	$schema_description = '' !== $video_description ? $video_description : $video_title;
	if ( $schema_description ) {
		$html .= '<meta itemprop="description" content="' . esc_attr( $schema_description ) . '">';
	}
	$html .= '<meta itemprop="embedUrl" content="' . esc_url( $embed_url ) . '">';

	if ( $show_title && $video_title ) {
		$html .= '<h3 class="ekwa-video-embed__title">' . esc_html( $video_title ) . '</h3>';
	}

	$thumb_markup = $display_thumb
		? ekwa_video_render_thumbnail_inner( $display_thumb, $display_thumb_alt, $dims, $video_duration, $disable_lazy, $fetch_priority_high )
		: '<span class="ekwa-video-embed__placeholder">' . esc_html__( 'Thumbnail not available', 'ekwa' ) . '</span>';

	$html .= '<div class="ekwa-video-embed__frame" style="aspect-ratio:' . esc_attr( $ratio ) . '">';
	if ( $open_lightbox ) {
		$html .= '<a href="' . esc_url( $watch_url ) . '" class="ekwa-lightbox ekwa-video-embed__thumb" aria-label="' . esc_attr( $play_label ) . '">'
			. $thumb_markup . '</a>';
	} else {
		$html .= '<button type="button" class="ekwa-video-embed__thumb ekwa-video-embed__play"'
			. ' data-embed-url="' . esc_attr( $embed_url ) . '"'
			. ' data-provider="' . esc_attr( $provider ) . '"'
			. ' data-video-id="' . esc_attr( $video_id ) . '"'
			. ' aria-label="' . esc_attr( $play_label ) . '">'
			. $thumb_markup . '</button>';
	}
	$html .= '</div>';

	if ( $show_description && $video_description ) {
		$html .= '<div class="ekwa-video-embed__description"><p>' . esc_html( $video_description ) . '</p></div>';
	}

	if ( $show_transcript && '' !== $transcript ) {
		$html .= ekwa_video_render_transcript_toggle( $transcript );
	}

	$html .= '</div>';

	return $html;
}

/**
 * Render the transcript toggle button + collapsible panel, reusing the exact
 * markup/classes and shared once-per-page assets already shipped for the
 * self-hosted ekwa/video block's transcript feature (ekwa_render_video_block()
 * / ekwa_video_transcript_assets_once() in inc/ekwa-blocks.php) — one "Video
 * Transcript" widget behavior across the whole theme instead of a second one.
 *
 * @param string $transcript Plain text, blank-line-separated paragraphs.
 * @return string
 */
function ekwa_video_render_transcript_toggle( $transcript ) {
	static $counter = 0;
	$counter++;
	$panel_id = 'ekwa-vte-' . $counter;

	$paragraphs = preg_split( '/\n\s*\n/', $transcript );
	$panel_html = '';
	foreach ( $paragraphs as $p ) {
		$p = trim( $p );
		if ( '' !== $p ) {
			$panel_html .= '<p>' . esc_html( $p ) . '</p>';
		}
	}

	$show_label = __( 'Video Transcript', 'ekwa' );
	$hide_label = __( 'Hide Transcript', 'ekwa' );

	$html = '<div class="ekwa-video-transcript-wrap">'
		. '<button type="button" class="ekwa-video-transcript-toggle"'
		. ' aria-expanded="false" aria-controls="' . esc_attr( $panel_id ) . '"'
		. ' data-show-label="' . esc_attr( $show_label ) . '" data-hide-label="' . esc_attr( $hide_label ) . '">'
		. '<span class="ekwa-video-transcript-label">' . esc_html( $show_label ) . '</span>'
		. '<i class="ekwa-video-transcript-icon fa-regular fa-file-lines" aria-hidden="true"></i>'
		. '</button>'
		. '<div class="ekwa-video-transcript-panel" id="' . esc_attr( $panel_id ) . '" hidden>' . $panel_html . '</div>'
		. '</div>';

	if ( function_exists( 'ekwa_video_transcript_assets_once' ) ) {
		$html .= ekwa_video_transcript_assets_once();
	}

	return $html;
}

/**
 * Server-side render callback for ekwa/youtube-video.
 *
 * @param array $attrs
 * @return string
 */
function ekwa_render_youtube_video_block( $attrs ) {
	return ekwa_video_render( $attrs, 'youtube' );
}

/**
 * Server-side render callback for ekwa/vimeo-video.
 *
 * @param array $attrs
 * @return string
 */
function ekwa_render_vimeo_video_block( $attrs ) {
	return ekwa_video_render( $attrs, 'vimeo' );
}

// ═══════════════════════════════════════════════════════════════════════════════
// YOUTUBE TRANSCRIPT (scrape — no official public API for this)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Fetch a YouTube video's transcript.
 *
 * Tries scraping captionTracks out of the public watch page first; if that
 * yields nothing (common on hosting IPs that get a stripped response) falls
 * back to the InnerTube youtubei/v1/player endpoint. Caches hits for 30 days
 * and misses for 5 minutes.
 *
 * @param string $video_id
 * @param bool   $force_refresh
 * @return array{success:bool, transcript?:string, source?:string, message?:string}
 */
function ekwa_video_fetch_youtube_transcript( $video_id, $force_refresh = false ) {
	if ( empty( $video_id ) ) {
		return array( 'success' => false, 'message' => __( 'Missing video ID.', 'ekwa' ) );
	}

	$cache_key = 'ekwa_vid_transcript_' . md5( $video_id );
	if ( $force_refresh ) {
		delete_transient( $cache_key );
	} else {
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return ( '__none__' === $cached )
				? array( 'success' => false, 'message' => __( 'No captions available for this video.', 'ekwa' ) )
				: array( 'success' => true, 'transcript' => $cached['text'], 'source' => $cached['source'] );
		}
	}

	$tracks = ekwa_video_youtube_caption_tracks_from_watch_page( $video_id );
	if ( empty( $tracks ) ) {
		$tracks = ekwa_video_youtube_caption_tracks_from_innertube( $video_id );
	}
	if ( empty( $tracks ) ) {
		set_transient( $cache_key, '__none__', 5 * MINUTE_IN_SECONDS );
		return array( 'success' => false, 'message' => __( 'No captions available for this video.', 'ekwa' ) );
	}

	$pick = ekwa_video_pick_youtube_caption_track( $tracks );
	if ( empty( $pick['baseUrl'] ) ) {
		set_transient( $cache_key, '__none__', 5 * MINUTE_IN_SECONDS );
		return array( 'success' => false, 'message' => __( 'Captions were found but could not be read.', 'ekwa' ) );
	}

	$text = ekwa_video_fetch_caption_text( $pick['baseUrl'], 'json3' );
	if ( null === $text ) {
		// json3 endpoint failed or returned no events — fall back to XML/srv1.
		$text = ekwa_video_fetch_caption_text( $pick['baseUrl'], null );
	}

	if ( null === $text ) {
		return array( 'success' => false, 'message' => __( 'Could not reach YouTube to fetch captions. Try again shortly.', 'ekwa' ) );
	}
	if ( '' === $text ) {
		set_transient( $cache_key, '__none__', 5 * MINUTE_IN_SECONDS );
		return array( 'success' => false, 'message' => __( 'Captions were empty for this video.', 'ekwa' ) );
	}

	$source = ( 'asr' === ( $pick['kind'] ?? '' ) ) ? 'auto' : 'human';
	set_transient( $cache_key, array( 'text' => $text, 'source' => $source ), 30 * DAY_IN_SECONDS );

	return array( 'success' => true, 'transcript' => $text, 'source' => $source );
}

/**
 * Fetch the public watch page and pull out ytInitialPlayerResponse — the same
 * JSON blob backs both caption-track discovery (below) and the no-API-key
 * metadata scrape (ekwa_video_youtube_metadata_from_watch_page() above), so
 * the fetch + regex/json_decode dance lives here once.
 *
 * @param string $video_id
 * @return array|null
 */
function ekwa_video_youtube_watch_page_player_response( $video_id ) {
	$response = wp_remote_get(
		'https://www.youtube.com/watch?v=' . rawurlencode( $video_id ),
		array(
			'timeout'     => 15,
			'redirection' => 5,
			'headers'     => array(
				'Accept-Language' => 'en-US,en;q=0.9',
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				'Cookie'          => 'CONSENT=YES+cb',
			),
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$html = wp_remote_retrieve_body( $response );
	if ( ! preg_match( '/ytInitialPlayerResponse\s*=\s*(\{.+?\})\s*;\s*(?:var|<\/script>)/s', $html, $m ) ) {
		return null;
	}

	$data = json_decode( $m[1], true );
	return is_array( $data ) ? $data : null;
}

/**
 * Scrape captionTracks out of the public watch page.
 *
 * @param string $video_id
 * @return array|null
 */
function ekwa_video_youtube_caption_tracks_from_watch_page( $video_id ) {
	$data   = ekwa_video_youtube_watch_page_player_response( $video_id );
	$tracks = $data['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ?? null;

	return ( is_array( $tracks ) && ! empty( $tracks ) ) ? $tracks : null;
}

/**
 * Fallback: ask YouTube's InnerTube player endpoint directly. Datacenter IPs
 * that get a stripped watch page usually still get a full response here.
 *
 * @param string $video_id
 * @return array|null
 */
function ekwa_video_youtube_caption_tracks_from_innertube( $video_id ) {
	$body = wp_json_encode(
		array(
			'videoId' => $video_id,
			'context' => array(
				'client' => array(
					'clientName'    => 'WEB',
					'clientVersion' => '2.20240101.00.00',
					'hl'            => 'en',
					'gl'            => 'US',
				),
			),
		)
	);

	$response = wp_remote_post(
		'https://www.youtube.com/youtubei/v1/player?prettyPrint=false',
		array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'             => 'application/json',
				'Accept'                   => 'application/json',
				'Accept-Language'          => 'en-US,en;q=0.9',
				'User-Agent'               => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				'X-Youtube-Client-Name'    => '1',
				'X-Youtube-Client-Version' => '2.20240101.00.00',
				'Origin'                   => 'https://www.youtube.com',
				'Referer'                  => 'https://www.youtube.com/',
			),
			'body'    => $body,
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$data   = json_decode( wp_remote_retrieve_body( $response ), true );
	$tracks = $data['captions']['playerCaptionsTracklistRenderer']['captionTracks'] ?? null;

	return ( is_array( $tracks ) && ! empty( $tracks ) ) ? $tracks : null;
}

/**
 * Preference order: English human → en-* human → English auto → first human → first track.
 *
 * @param array $tracks
 * @return array
 */
function ekwa_video_pick_youtube_caption_track( $tracks ) {
	foreach ( $tracks as $t ) {
		if ( ( $t['languageCode'] ?? '' ) === 'en' && empty( $t['kind'] ) ) {
			return $t;
		}
	}
	foreach ( $tracks as $t ) {
		if ( 0 === strpos( $t['languageCode'] ?? '', 'en' ) && empty( $t['kind'] ) ) {
			return $t;
		}
	}
	foreach ( $tracks as $t ) {
		if ( ( $t['languageCode'] ?? '' ) === 'en' && ( $t['kind'] ?? '' ) === 'asr' ) {
			return $t;
		}
	}
	foreach ( $tracks as $t ) {
		if ( empty( $t['kind'] ) ) {
			return $t;
		}
	}
	return $tracks[0] ?? array();
}

/**
 * Fetch a caption track's text. $fmt = 'json3' (preferred) or null (XML/srv1
 * fallback — used when json3 returns 200 but no events, a known quirk with
 * some ASR tracks).
 *
 * @param string      $base_url
 * @param string|null $fmt
 * @return string|null Text on success (may be ''), null on network/parse failure.
 */
function ekwa_video_fetch_caption_text( $base_url, $fmt ) {
	$url = preg_replace( '/([?&])fmt=[^&]*(&|$)/', '$1', $base_url );
	$url = rtrim( $url, '?&' );
	if ( $fmt ) {
		$url .= ( false !== strpos( $url, '?' ) ? '&' : '?' ) . 'fmt=' . rawurlencode( $fmt );
	}

	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$body = wp_remote_retrieve_body( $response );

	if ( 'json3' === $fmt ) {
		$json = json_decode( $body, true );
		if ( empty( $json['events'] ) || ! is_array( $json['events'] ) ) {
			return null;
		}
		$text = '';
		foreach ( $json['events'] as $event ) {
			if ( empty( $event['segs'] ) || ! is_array( $event['segs'] ) ) {
				continue;
			}
			foreach ( $event['segs'] as $seg ) {
				$piece = $seg['utf8'] ?? '';
				if ( '' === $piece || "\n" === $piece ) {
					continue;
				}
				$trimmed = trim( $piece );
				if ( '' !== $trimmed && preg_match( '/^[\[\(].*[\]\)]$/', $trimmed ) ) {
					continue;
				}
				$text .= $piece;
			}
			$text .= ' ';
		}
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	// XML (srv1).
	if ( '' === $body || false === stripos( $body, '<text' ) ) {
		return null;
	}
	$previous = libxml_use_internal_errors( true );
	$xml      = simplexml_load_string( $body );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	if ( false === $xml ) {
		return null;
	}

	$text = '';
	foreach ( $xml->text as $node ) {
		$piece = trim( html_entity_decode( (string) $node, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
		if ( '' === $piece || preg_match( '/^[\[\(].*[\]\)]$/', $piece ) ) {
			continue;
		}
		$text .= $piece . ' ';
	}
	return trim( preg_replace( '/\s+/', ' ', $text ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// REST ROUTES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Register the editor-facing REST routes.
 */
function ekwa_video_embed_register_rest_routes() {
	register_rest_route(
		'ekwa/v1',
		'/video-metadata',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'ekwa_video_rest_metadata',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'url'      => array( 'required' => true, 'type' => 'string' ),
				'provider' => array( 'required' => true, 'type' => 'string', 'enum' => array( 'youtube', 'vimeo' ) ),
			),
		)
	);

	register_rest_route(
		'ekwa/v1',
		'/video-transcript',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'ekwa_video_rest_transcript',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'video_id'      => array( 'required' => true, 'type' => 'string' ),
				'force_refresh' => array( 'required' => false, 'type' => 'boolean', 'default' => false ),
			),
		)
	);
}
add_action( 'rest_api_init', 'ekwa_video_embed_register_rest_routes' );

/**
 * REST: resolve a pasted URL to videoId/embedUrl + metadata in one round trip.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_video_rest_metadata( $request ) {
	$url      = esc_url_raw( $request->get_param( 'url' ) );
	$provider = $request->get_param( 'provider' );

	$info = ekwa_video_extract_info( $url, $provider );
	if ( empty( $info['videoId'] ) ) {
		return new WP_Error( 'ekwa_video_invalid_url', __( 'Could not recognize a video ID in that URL.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$metadata = ekwa_video_get_metadata( $provider, $info['videoId'] );

	return rest_ensure_response(
		array_merge(
			array(
				'videoId'  => $info['videoId'],
				'embedUrl' => $info['embedUrl'],
			),
			$metadata
		)
	);
}

/**
 * REST: fetch (or refresh) a YouTube video's transcript.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_video_rest_transcript( $request ) {
	$result = ekwa_video_fetch_youtube_transcript( $request->get_param( 'video_id' ), (bool) $request->get_param( 'force_refresh' ) );

	if ( empty( $result['success'] ) ) {
		return new WP_Error( 'ekwa_video_transcript_failed', $result['message'] ?? __( 'Could not fetch transcript.', 'ekwa' ), array( 'status' => 422 ) );
	}

	return rest_ensure_response(
		array(
			'transcript' => $result['transcript'],
			'source'     => $result['source'],
		)
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// SHORTCODES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Map shortcode attributes (snake_case, string booleans) onto the same
 * camelCase shape ekwa_video_render() expects from block attributes.
 *
 * @param array $atts
 * @return array
 */
function ekwa_video_shortcode_atts_to_render_attrs( $atts ) {
	$atts = shortcode_atts(
		array(
			'url'              => '',
			'title'            => '',
			'description'      => '',
			'show_title'       => 'true',
			'show_description' => 'false',
			'lightbox'         => 'false',
			'transcript'       => '',
			'show_transcript'  => 'false',
			'class'            => '',
		),
		$atts
	);

	return array(
		'videoUrl'         => $atts['url'],
		'videoTitle'       => $atts['title'],
		'videoDescription' => $atts['description'],
		'showTitle'        => 'true' === $atts['show_title'],
		'showDescription'  => 'true' === $atts['show_description'],
		'openInLightbox'   => 'true' === $atts['lightbox'],
		'transcript'       => $atts['transcript'],
		'showTranscript'   => 'true' === $atts['show_transcript'],
		'className'        => $atts['class'],
	);
}

/**
 * [ekwa_youtube_video url="https://youtu.be/…" title="…" lightbox="true"]
 *
 * @param array $atts
 * @return string
 */
function ekwa_youtube_video_shortcode( $atts ) {
	return ekwa_video_render( ekwa_video_shortcode_atts_to_render_attrs( $atts ), 'youtube' );
}
add_shortcode( 'ekwa_youtube_video', 'ekwa_youtube_video_shortcode' );

/**
 * [ekwa_vimeo_video url="https://vimeo.com/…" title="…" lightbox="true"]
 *
 * @param array $atts
 * @return string
 */
function ekwa_vimeo_video_shortcode( $atts ) {
	return ekwa_video_render( ekwa_video_shortcode_atts_to_render_attrs( $atts ), 'vimeo' );
}
add_shortcode( 'ekwa_vimeo_video', 'ekwa_vimeo_video_shortcode' );
