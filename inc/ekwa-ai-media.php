<?php
/**
 * Media Library images and video links for the AI builders.
 *
 * "Build with AI (Blocks)" and "Generate with AI" could only ever put grey
 * placeholders where a picture goes: the one image input they had is for
 * reference screenshots, which the model is told to LOOK AT, not place. This
 * lets the author hand over the actual pictures and videos a section is built
 * around — images picked from the Media Library, YouTube / Vimeo links pasted
 * in — and have them placed in the design at their real URLs.
 *
 * Shape on the wire — the `media` request param, and `media` on a history turn:
 *   { type: 'image', id: 123, n: 1 }
 *   { type: 'video', url: 'https://youtu.be/…', n: 1, title?: '…', thumb?: 'https://…' }
 * `n` is the number the modal shows beside the item ("Image 1", "Video 2"), so
 * the prompt names each item the way the author sees it.
 *
 * Images are resolved from the attachment ID on the server — URL, alt text,
 * dimensions — rather than trusted from the browser, and the model is shown a
 * small copy of each one so it can design around what the picture actually
 * shows. Video links are accepted only when the theme's own video blocks can
 * play them. Nothing in this file writes to the site.
 *
 * Both request params default to empty, so a request without them — an older
 * editor script, or any other caller — builds exactly the prompt it did before.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Images accepted per request. */
const EKWA_AI_MEDIA_MAX_IMAGES = 20;

/** Video links accepted per request. */
const EKWA_AI_MEDIA_MAX_VIDEOS = 10;

/**
 * Largest single file shown to the model as a picture. The copy sent is the
 * "medium" size wherever one exists — tens of kilobytes — so this only bites
 * on an image small enough to have no intermediate sizes but heavy anyway.
 */
const EKWA_AI_MEDIA_MAX_VISUAL_BYTES = 1500000;

/**
 * Ceiling on all the pictures in one request put together. Well under
 * Gemini's inline-data limit; past it the remaining images still go in as
 * text (URL, alt, size), just without the model seeing them.
 */
const EKWA_AI_MEDIA_MAX_VISUAL_TOTAL = 8000000;

/**
 * Validate the media list sent with a request.
 *
 * Entries that no longer resolve (an image deleted since it was picked, a link
 * that is not YouTube or Vimeo) are left out and reported rather than failing
 * the whole generation — the rest of the request is still worth running.
 *
 * @param mixed      $raw      The `media` param (or a history turn's `media`).
 * @param array|null $warnings By reference: messages for the modal.
 * @return array<int,array<string,mixed>> Clean items, in the order given.
 */
function ekwa_ai_media_normalize( $raw, &$warnings = null ) {
	if ( ! is_array( $warnings ) ) {
		$warnings = array();
	}
	if ( ! is_array( $raw ) || ! $raw ) {
		return array();
	}

	$out      = array();
	$seen     = array();
	$position = array( 'image' => 0, 'video' => 0 );
	$count    = array( 'image' => 0, 'video' => 0 );
	$over     = array( 'image' => false, 'video' => false );
	$limits   = array( 'image' => EKWA_AI_MEDIA_MAX_IMAGES, 'video' => EKWA_AI_MEDIA_MAX_VIDEOS );

	foreach ( $raw as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		$type = isset( $entry['type'] ) ? (string) $entry['type'] : '';
		if ( ! isset( $position[ $type ] ) ) {
			continue;
		}
		$position[ $type ]++;

		// The modal's own number when it sent one, so "Image 3" in the prompt
		// is the thumbnail the author saw labelled 3; otherwise the position.
		$n = isset( $entry['n'] ) ? absint( $entry['n'] ) : 0;
		if ( $n < 1 || $n > 999 ) {
			$n = $position[ $type ];
		}

		if ( $count[ $type ] >= $limits[ $type ] ) {
			$over[ $type ] = true;
			continue;
		}

		if ( 'image' === $type ) {
			$item = ekwa_ai_media_image( isset( $entry['id'] ) ? $entry['id'] : 0 );
			if ( ! $item ) {
				/* translators: %d: the image's number in the modal. */
				$warnings[] = sprintf( __( 'Image %d is no longer in the Media Library, so it was left out.', 'ekwa' ), $n );
				continue;
			}
			$key = 'image:' . $item['id'];
		} else {
			$info = ekwa_ai_media_video_info( isset( $entry['url'] ) ? $entry['url'] : '' );
			if ( ! $info ) {
				/* translators: %d: the video's number in the modal. */
				$warnings[] = sprintf( __( 'Video %d is not a YouTube or Vimeo link the video blocks can play, so it was left out.', 'ekwa' ), $n );
				continue;
			}
			$item = $info + array(
				'type'  => 'video',
				'title' => ekwa_ai_media_clean_text( isset( $entry['title'] ) ? $entry['title'] : '', 200 ),
				'thumb' => ekwa_ai_media_video_thumb( $info, isset( $entry['thumb'] ) ? $entry['thumb'] : '' ),
			);
			$key = 'video:' . $info['provider'] . ':' . $info['video_id'];
		}

		// The same picture or video twice would only be placed twice.
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;

		$item['n'] = $n;
		$out[]     = $item;
		$count[ $type ]++;
	}

	if ( $over['image'] ) {
		/* translators: %d: maximum number of images. */
		$warnings[] = sprintf( __( 'Only the first %d images are used in one request.', 'ekwa' ), EKWA_AI_MEDIA_MAX_IMAGES );
	}
	if ( $over['video'] ) {
		/* translators: %d: maximum number of videos. */
		$warnings[] = sprintf( __( 'Only the first %d videos are used in one request.', 'ekwa' ), EKWA_AI_MEDIA_MAX_VIDEOS );
	}

	return $out;
}

/**
 * One Media Library image, as the prompt describes it.
 *
 * @param mixed $id Attachment ID.
 * @return array<string,mixed>|null Null when it is not an image this user can read.
 */
function ekwa_ai_media_image( $id ) {
	$id   = absint( $id );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || 'attachment' !== $post->post_type || ! current_user_can( 'read_post', $id ) ) {
		return null;
	}

	$mime = (string) get_post_mime_type( $post );
	if ( 0 !== strpos( $mime, 'image/' ) ) {
		return null;
	}

	$url = wp_get_attachment_url( $id );
	if ( ! $url ) {
		return null;
	}

	$meta = wp_get_attachment_metadata( $id );

	return array(
		'type'    => 'image',
		'id'      => $id,
		'url'     => $url,
		'alt'     => ekwa_ai_media_clean_text( get_post_meta( $id, '_wp_attachment_image_alt', true ), 300 ),
		'caption' => ekwa_ai_media_clean_text( $post->post_excerpt, 300 ),
		'width'   => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
		'height'  => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
		'mime'    => $mime,
	);
}

/**
 * Recognise a YouTube or Vimeo link the theme's video blocks can play.
 *
 * Validated with the blocks' own parser (ekwa_video_extract_info()), so a link
 * accepted here is one ekwa/youtube-video or ekwa/vimeo-video will render.
 * YouTube links are rewritten to the plain watch URL — Shorts and live links
 * included, which the blocks' parser does not read in their own form. Vimeo
 * links are kept as pasted, so an unlisted video's hash is not lost.
 *
 * @param mixed $url Link as the author pasted it.
 * @return array{provider:string,video_id:string,url:string}|null
 */
function ekwa_ai_media_video_info( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url || strlen( $url ) > 500 || ! function_exists( 'ekwa_video_extract_info' ) ) {
		return null;
	}
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		$url = 'https://' . ltrim( $url, '/' );
	}

	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

	if ( preg_match( '/(^|\.)(youtube\.com|youtube-nocookie\.com|youtu\.be)$/', $host ) ) {
		// Shorts, live and nocookie embeds carry the same 11-character id.
		if ( preg_match( '#(?:youtube(?:-nocookie)?\.com/(?:shorts|live|embed)/)([A-Za-z0-9_-]{11})#', $url, $m ) ) {
			$url = 'https://www.youtube.com/watch?v=' . $m[1];
		}
		$info = ekwa_video_extract_info( $url, 'youtube' );
		if ( empty( $info['videoId'] ) ) {
			return null;
		}
		return array(
			'provider' => 'youtube',
			'video_id' => (string) $info['videoId'],
			'url'      => 'https://www.youtube.com/watch?v=' . $info['videoId'],
		);
	}

	if ( preg_match( '/(^|\.)vimeo\.com$/', $host ) ) {
		$info = ekwa_video_extract_info( $url, 'vimeo' );
		if ( empty( $info['videoId'] ) ) {
			return null;
		}
		return array(
			'provider' => 'vimeo',
			'video_id' => (string) $info['videoId'],
			'url'      => esc_url_raw( $url ),
		);
	}

	return null;
}

/**
 * A thumbnail the preview can show for a video.
 *
 * YouTube's is derived from the id, so nothing from the browser is needed.
 * Vimeo's has no fixed address; the modal looked it up through the video
 * blocks' own metadata endpoint and sends it along — accepted only over https.
 *
 * @param array $info  From ekwa_ai_media_video_info().
 * @param mixed $given Thumbnail URL the modal sent, if any.
 * @return string
 */
function ekwa_ai_media_video_thumb( $info, $given ) {
	if ( 'youtube' === $info['provider'] ) {
		return 'https://i.ytimg.com/vi/' . rawurlencode( $info['video_id'] ) . '/hqdefault.jpg';
	}
	$given = trim( (string) $given );
	return preg_match( '#^https://#i', $given ) ? esc_url_raw( $given ) : '';
}

/**
 * Plain single-line text, capped — for titles, alt text and captions that go
 * into the prompt, where a stray newline or a paragraph of HTML only adds noise.
 *
 * @param mixed $text
 * @param int   $max  Characters.
 * @return string
 */
function ekwa_ai_media_clean_text( $text, $max ) {
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
	if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > $max ) {
		$text = rtrim( mb_substr( $text, 0, $max ) ) . '…';
	}
	return $text;
}

/**
 * The media list, written into the user's turn.
 *
 * Put in the TURN rather than the system prompt because it belongs to the turn:
 * a refine that adds a video adds it to that request, and the manifest travels
 * back in the conversation history exactly where it was first sent.
 *
 * @param array  $media   From ekwa_ai_media_normalize().
 * @param string $format  'blocks' (Block Builder) or 'html' (HTML generator).
 * @param bool   $visuals Whether copies of the images follow the list. False
 *                        for history turns and for the copy-the-prompt export.
 * @return string '' when there is nothing to place.
 */
function ekwa_ai_media_manifest( $media, $format = 'blocks', $visuals = true ) {
	$images = array();
	$videos = array();
	foreach ( (array) $media as $item ) {
		if ( isset( $item['type'] ) && 'image' === $item['type'] ) {
			$images[] = $item;
		} elseif ( isset( $item['type'] ) && 'video' === $item['type'] ) {
			$videos[] = $item;
		}
	}
	if ( ! $images && ! $videos ) {
		return '';
	}

	$html = 'html' === $format;

	$out  = "\n\n---\nMEDIA TO USE — the author chose these for this section: images from this site's Media Library and video links. They are real content to place in the design, not references.\n";
	$out .= "- Build EVERY item below into the design, each exactly once, where it best fits the content — unless the request says where one goes or to leave one out. The author refers to them by these names (\"Image 1\", \"Video 2\").\n";
	$out .= "- Use each URL EXACTLY as written. Never put a placeholder, a different size, a stock photo or an invented URL in their place.\n";

	if ( $images ) {
		$out .= $visuals
			? "- Each image is shown to you after this list, under its name. Design around what it actually shows — its subject, its shape, where the eye goes — and never crop the subject away. Keep the alt text given; where there is none, write a short, specific one from what you see.\n"
			: "- Keep the alt text given; where there is none, write a short, specific one that fits the content.\n";
		$out .= $html
			? "- IMAGES go in as <img src=\"URL\" alt=\"…\" width=\"W\" height=\"H\"> with the exact src — the converter recognises each file and links it to its Media Library item. Size and crop them with CSS through a class (object-fit:cover with an aspect-ratio lets photos of different shapes sit together). Use one as a CSS background-image only when text has to sit on top of it.\n"
			: "- IMAGES go in as ekwa/image blocks with the exact src, the mediaId and the alt, e.g. <!-- wp:ekwa/image {\"src\":\"URL\",\"mediaId\":123,\"alt\":\"…\"} /-->. Size and crop them in your <style> through a className (object-fit:cover with an aspect-ratio lets photos of different shapes sit together). An image with a caption can sit in an ekwa/figure with an ekwa/text figcaption. Use one as a CSS background-image only when text has to sit on top of it.\n";
	}

	if ( $videos ) {
		$out .= $html
			? "- VIDEOS go in as the marker shown under each one, copied exactly. The converter swaps the marker element for the site's video block — thumbnail, play button, title and video schema included; what is inside the marker only stands in for the preview. The marker itself is replaced, so put it INSIDE a wrapper element that carries your layout class and sizing. Never write an <iframe> for these videos.\n"
			: "- VIDEOS go in as their own block with the exact URL: <!-- wp:ekwa/youtube-video {\"videoUrl\":\"URL\"} /--> for YouTube, <!-- wp:ekwa/vimeo-video {\"videoUrl\":\"URL\"} /--> for Vimeo. The block draws the thumbnail, play button, title and video schema itself — never write an <iframe>, a thumbnail image or a play button for these. Size it through a wrapping ekwa/div's className.\n";
	}

	if ( $images ) {
		$out .= "\nIMAGES:\n";
		foreach ( $images as $item ) {
			$bits = array( 'Image ' . (int) $item['n'] );
			if ( ! $html ) {
				$bits[] = 'mediaId ' . (int) $item['id'];
			}
			$bits[] = $item['url'];

			$w = (int) $item['width'];
			$h = (int) $item['height'];
			if ( $w > 0 && $h > 0 ) {
				$shape  = $w > $h * 1.15 ? 'landscape' : ( $h > $w * 1.15 ? 'portrait' : 'square' );
				$bits[] = $w . '×' . $h . ' px, ' . $shape;
			}

			$bits[] = '' !== $item['alt'] ? 'alt: "' . $item['alt'] . '"' : 'alt: (none — write one)';
			if ( '' !== $item['caption'] ) {
				$bits[] = 'caption: "' . $item['caption'] . '"';
			}

			$out .= '- ' . implode( ' — ', $bits ) . "\n";
		}
	}

	if ( $videos ) {
		$out .= "\nVIDEOS:\n";
		foreach ( $videos as $item ) {
			$out .= '- Video ' . (int) $item['n']
				. ' — ' . ( 'youtube' === $item['provider'] ? 'YouTube' : 'Vimeo' )
				. ' — ' . $item['url']
				. ( '' !== $item['title'] ? ' — "' . $item['title'] . '"' : '' )
				. "\n";
			if ( $html ) {
				$out .= '  marker: ' . ekwa_ai_media_video_marker( $item ) . "\n";
			}
		}
	}

	return $out;
}

/**
 * The element the HTML generator writes for a video.
 *
 * `data-ekwa="video"` is the converter's own extension point; with a
 * data-ekwa-video-url on it the converter emits the video block holding just
 * that link, which is the state the paste-a-link transform leaves the block in
 * — it fills in its own title, thumbnail and duration. The <img> inside is for
 * the modal's preview only; the converter replaces the whole element.
 *
 * @param array $item Normalized video item.
 * @return string
 */
function ekwa_ai_media_video_marker( $item ) {
	$label = '' !== $item['title'] ? $item['title'] : 'Video ' . (int) $item['n'];
	$inner = '' !== $item['thumb']
		? '<img src="' . esc_url( $item['thumb'] ) . '" alt="' . esc_attr( $label ) . '">'
		: '<span>' . esc_html( $label ) . '</span>';

	return '<div data-ekwa="video"'
		. ' data-ekwa-provider="' . esc_attr( $item['provider'] ) . '"'
		. ' data-ekwa-video-id="' . esc_attr( $item['video_id'] ) . '"'
		. ' data-ekwa-video-url="' . esc_url( $item['url'] ) . '">'
		. $inner
		. '</div>';
}

/**
 * Add the media list — and a look at each image — to the current turn.
 *
 * Appended to the LAST entry of the contents array, which is always the turn
 * being sent now (ekwa_ai_generate_build_contents() puts it there), after the
 * prompt and any reference screenshots. Each picture follows a text part that
 * names it, so the model can tell "Image 2" from a screenshot of a layout.
 *
 * @param array  $contents Gemini contents array.
 * @param array  $media    From ekwa_ai_media_normalize().
 * @param string $format   'blocks' | 'html'.
 * @return array
 */
function ekwa_ai_media_attach( $contents, $media, $format = 'blocks' ) {
	if ( ! $media || ! is_array( $contents ) || ! $contents ) {
		return $contents;
	}
	$last = count( $contents ) - 1;
	if ( ! isset( $contents[ $last ]['role'] ) || 'user' !== $contents[ $last ]['role'] ) {
		return $contents;
	}

	$parts  = array( array( 'text' => ekwa_ai_media_manifest( $media, $format, true ) ) );
	$budget = EKWA_AI_MEDIA_MAX_VISUAL_TOTAL;

	foreach ( $media as $item ) {
		if ( 'image' !== $item['type'] ) {
			continue;
		}
		$visual = ekwa_ai_media_visual_part( $item['id'], $budget );
		if ( ! $visual ) {
			continue;
		}
		$parts[] = array( 'text' => 'Image ' . (int) $item['n'] . ':' );
		$parts[] = $visual;
	}

	$contents[ $last ]['parts'] = array_merge( (array) $contents[ $last ]['parts'], $parts );

	return $contents;
}

/**
 * A small copy of an attachment as a Gemini inline_data part.
 *
 * Read from disk, never fetched over HTTP. The "medium" size is preferred: it
 * is enough to see what a photo is of, and at that size an image costs the
 * model a few hundred tokens. An image stored off-site (a media-offload
 * plugin) has no local file; it simply goes in as text.
 *
 * @param int $id     Attachment ID.
 * @param int $budget By reference: bytes still allowed in this request.
 * @return array|null
 */
function ekwa_ai_media_visual_part( $id, &$budget ) {
	$id = (int) $id;

	$candidates = array();
	$uploads    = wp_get_upload_dir();
	foreach ( array( 'medium', 'medium_large' ) as $size ) {
		$data = image_get_intermediate_size( $id, $size );
		if ( is_array( $data ) && ! empty( $data['path'] ) && empty( $uploads['error'] ) ) {
			$candidates[] = array(
				'path' => trailingslashit( $uploads['basedir'] ) . ltrim( (string) $data['path'], '/' ),
				'mime' => ! empty( $data['mime-type'] ) ? (string) $data['mime-type'] : (string) get_post_mime_type( $id ),
			);
		}
	}
	// An image too small to have been given a "medium" size is small already.
	$candidates[] = array(
		'path' => (string) get_attached_file( $id ),
		'mime' => (string) get_post_mime_type( $id ),
	);

	foreach ( $candidates as $candidate ) {
		$path = $candidate['path'];
		if ( '' === $path || ! is_readable( $path ) || ! preg_match( '#^image/(png|jpe?g|webp|gif)$#i', $candidate['mime'] ) ) {
			continue;
		}
		$bytes = filesize( $path );
		if ( ! $bytes || $bytes > EKWA_AI_MEDIA_MAX_VISUAL_BYTES || $bytes > $budget ) {
			continue;
		}
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
		if ( false === $data || '' === $data ) {
			continue;
		}
		$budget -= $bytes;
		return array(
			'inline_data' => array(
				'mime_type' => strtolower( $candidate['mime'] ),
				'data'      => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Gemini inline data.
			),
		);
	}

	return null;
}

/**
 * Restore the media list on the user turns of a conversation's history.
 *
 * The modal keeps each turn's media next to its text; the model has to see
 * the list again on every later request, or "make Image 2 larger" in a refine
 * refers to nothing. Text only on the way back in — the pictures were shown
 * on the turn that sent them, and the model's answer already places them.
 *
 * Turns without media are returned untouched.
 *
 * @param array  $history Conversation turns from the request.
 * @param string $format  'blocks' | 'html'.
 * @return array
 */
function ekwa_ai_media_expand_history( $history, $format = 'blocks' ) {
	if ( ! is_array( $history ) ) {
		return array();
	}
	foreach ( $history as $i => $turn ) {
		if ( ! is_array( $turn ) || ! array_key_exists( 'media', $turn ) ) {
			continue;
		}
		$media = ( isset( $turn['role'] ) && 'user' === $turn['role'] ) ? ekwa_ai_media_normalize( $turn['media'] ) : array();
		if ( $media ) {
			$history[ $i ]['text'] = ( isset( $turn['text'] ) ? (string) $turn['text'] : '' )
				. ekwa_ai_media_manifest( $media, $format, false );
		}
		unset( $history[ $i ]['media'] );
	}
	return $history;
}

/**
 * Link the placed images to their attachments, and say what was not placed.
 *
 * The model is asked for the mediaId, but the src is what it gets right: any
 * ekwa/image whose src is one of the chosen files (at any size) gets that
 * file's mediaId — which is what gives it srcset and the WebP swap — and its
 * alt text when it has none. Nothing else in the markup is touched, and when
 * nothing needs linking the markup is returned exactly as given.
 *
 * @param string $markup Block markup, CSS already embedded.
 * @param string $css    The section CSS (images used as backgrounds live here).
 * @param array  $media  From ekwa_ai_media_normalize().
 * @return array{markup:string,warnings:array<int,string>}
 */
function ekwa_ai_media_apply_to_blocks( $markup, $css, $media ) {
	$out = array( 'markup' => (string) $markup, 'warnings' => array() );
	if ( ! $media ) {
		return $out;
	}

	$by_path = array();
	foreach ( $media as $item ) {
		if ( 'image' === $item['type'] ) {
			foreach ( ekwa_ai_media_image_paths( $item['id'] ) as $path ) {
				$by_path[ $path ] = $item;
			}
		}
	}

	if ( $by_path && false !== strpos( $out['markup'], 'wp:ekwa/image' ) ) {
		$changed = 0;
		$blocks  = ekwa_ai_media_link_images( parse_blocks( $out['markup'] ), $by_path, $changed );
		if ( $changed ) {
			$out['markup'] = serialize_blocks( $blocks );
		}
	}

	$missing = ekwa_ai_media_missing( $out['markup'] . "\n" . $css, $media );
	if ( $missing ) {
		$out['warnings'][] = ekwa_ai_media_missing_warning( $missing, 'blocks' );
	}

	return $out;
}

/**
 * Recursive worker for ekwa_ai_media_apply_to_blocks().
 *
 * @param array $blocks  Parsed blocks.
 * @param array $by_path URL path => image item.
 * @param int   $changed By reference: attributes changed.
 * @return array
 */
function ekwa_ai_media_link_images( $blocks, $by_path, &$changed ) {
	foreach ( $blocks as $i => $block ) {
		if ( ! empty( $block['innerBlocks'] ) ) {
			$blocks[ $i ]['innerBlocks'] = ekwa_ai_media_link_images( $block['innerBlocks'], $by_path, $changed );
		}
		if ( ! isset( $block['blockName'] ) || 'ekwa/image' !== $block['blockName'] ) {
			continue;
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$path  = ekwa_ai_media_url_path( isset( $attrs['src'] ) ? $attrs['src'] : '' );
		if ( '' === $path || ! isset( $by_path[ $path ] ) ) {
			continue;
		}
		$item = $by_path[ $path ];

		if ( ! isset( $attrs['mediaId'] ) || (int) $attrs['mediaId'] !== (int) $item['id'] ) {
			$attrs['mediaId'] = (int) $item['id'];
			$changed++;
		}
		if ( '' === trim( (string) ( isset( $attrs['alt'] ) ? $attrs['alt'] : '' ) ) && '' !== $item['alt'] ) {
			$attrs['alt'] = $item['alt'];
			$changed++;
		}
		$blocks[ $i ]['attrs'] = $attrs;
	}
	return $blocks;
}

/**
 * Say which chosen items the generated HTML left out.
 *
 * The HTML generator's output goes through the converter, which links each
 * image to its attachment by file name — so there is nothing to repair here,
 * only the report.
 *
 * @param string $html  Generated HTML.
 * @param string $css   Extracted CSS.
 * @param array  $media From ekwa_ai_media_normalize().
 * @return array<int,string> Warnings.
 */
function ekwa_ai_media_check_html( $html, $css, $media ) {
	$missing = ekwa_ai_media_missing( (string) $html . "\n" . (string) $css, $media );
	return $missing ? array( ekwa_ai_media_missing_warning( $missing, 'html' ) ) : array();
}

/**
 * Every URL path an attachment can be reached at — the file itself, the
 * original before WordPress scaled it, and each generated size.
 *
 * @param int $id Attachment ID.
 * @return string[] Lower-cased paths.
 */
function ekwa_ai_media_image_paths( $id ) {
	$id   = (int) $id;
	$urls = array( wp_get_attachment_url( $id ) );
	if ( function_exists( 'wp_get_original_image_url' ) ) {
		$urls[] = wp_get_original_image_url( $id );
	}

	$meta = wp_get_attachment_metadata( $id );
	$base = $urls[0] ? trailingslashit( dirname( $urls[0] ) ) : '';
	if ( $base && is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$urls[] = $base . $size['file'];
			}
		}
	}

	$paths = array();
	foreach ( $urls as $url ) {
		$path = ekwa_ai_media_url_path( $url );
		if ( '' !== $path ) {
			$paths[ $path ] = true;
		}
	}
	return array_keys( $paths );
}

/**
 * The path of a URL, decoded and lower-cased, for comparing files regardless
 * of host, scheme or encoding.
 *
 * @param mixed $url
 * @return string
 */
function ekwa_ai_media_url_path( $url ) {
	$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES, 'UTF-8' );
	if ( '' === $url ) {
		return '';
	}
	$path = wp_parse_url( $url, PHP_URL_PATH );
	return is_string( $path ) && '' !== $path ? strtolower( rawurldecode( $path ) ) : '';
}

/**
 * The chosen items that appear nowhere in the output.
 *
 * An image counts as placed when any of its files appears — as a src, a
 * background, a lightbox link — and a video when its id turns up in any
 * YouTube / Vimeo link or video marker.
 *
 * @param string $haystack Generated markup and CSS together.
 * @param array  $media    From ekwa_ai_media_normalize().
 * @return string[] Names as the modal shows them ("Image 3", "Video 1").
 */
function ekwa_ai_media_missing( $haystack, $media ) {
	// Undo the escaping block serialization applies inside attribute JSON, so a
	// URL stored in an attribute reads the same as one written in CSS.
	$plain = str_replace(
		array( '\\/', '\\u002d', '\\u0026', '\\u003c', '\\u003e', '\\u0022' ),
		array( '/', '-', '&', '<', '>', '"' ),
		(string) $haystack
	);
	$lower = strtolower( rawurldecode( $plain ) );

	$video_ids = array();
	if ( preg_match_all( '#(?:youtube(?:-nocookie)?\.com|youtu\.be|vimeo\.com)/[^\s"\'<>)\\\\]*#i', $plain, $m ) ) {
		foreach ( $m[0] as $link ) {
			// The match starts at the host, so this is a complete link again.
			$info = ekwa_ai_media_video_info( 'https://' . $link );
			if ( $info ) {
				$video_ids[ $info['provider'] . ':' . $info['video_id'] ] = true;
			}
		}
	}
	if ( preg_match_all( '#data-ekwa-video-id\s*=\s*["\']([^"\']+)["\']#i', $plain, $m ) ) {
		foreach ( $m[1] as $id ) {
			$video_ids[ 'youtube:' . $id ] = true;
			$video_ids[ 'vimeo:' . $id ]   = true;
		}
	}

	$missing = array();
	foreach ( $media as $item ) {
		if ( 'image' === $item['type'] ) {
			$found = false;
			foreach ( ekwa_ai_media_image_paths( $item['id'] ) as $path ) {
				if ( false !== strpos( $lower, $path ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = 'Image ' . (int) $item['n'];
			}
		} elseif ( ! isset( $video_ids[ $item['provider'] . ':' . $item['video_id'] ] ) ) {
			$missing[] = 'Video ' . (int) $item['n'];
		}
	}

	return $missing;
}

/**
 * The warning for items the AI did not place.
 *
 * @param string[] $missing Names, e.g. array( 'Image 3', 'Video 1' ).
 * @param string   $format  'blocks' | 'html'.
 * @return string
 */
function ekwa_ai_media_missing_warning( $missing, $format ) {
	$count = count( $missing );
	return sprintf(
		/* translators: 1: list like "Image 3, Video 1", 2: the first of them. */
		'html' === $format
			? _n( 'Not placed by the AI: %1$s. Ask for it in a refine turn (e.g. "add %2$s beside the intro"), or add it after converting.', 'Not placed by the AI: %1$s. Ask for them in a refine turn (e.g. "add %2$s beside the intro"), or add them after converting.', $count, 'ekwa' )
			: _n( 'Not placed by the AI: %1$s. Ask for it in a refine turn (e.g. "add %2$s beside the intro"), or add it after inserting.', 'Not placed by the AI: %1$s. Ask for them in a refine turn (e.g. "add %2$s beside the intro"), or add them after inserting.', $count, 'ekwa' ),
		implode( ', ', $missing ),
		$missing[0]
	);
}
