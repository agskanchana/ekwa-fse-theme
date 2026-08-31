<?php
/**
 * Imported page content — storage and pre-conversion normalisation.
 *
 * The Bulk Page Creator's CSV (produced by ekwa-tools/sitemap-to-csv) may carry
 * a `content` column holding the source page's inner HTML. That HTML is NOT
 * written to post_content on import: pages are created with the metadata they
 * always were, and the HTML is parked in post meta so an author can convert it
 * later, deliberately, from the AI Block Builder — reviewing a preview and
 * re-running as many times as they like.
 *
 * This file owns the parking spot and everything that has to happen to the HTML
 * BEFORE the block converter (inc/ekwa-converter-lib.php) sees it:
 *
 *   1. Lazy-loaded images       data-src/data-srcset → src/srcset. Exports from
 *                               a lazyloading site carry NO src at all, so
 *                               skipping this silently loses every image.
 *   2. Scripts and schema metas <script>/<noscript> dropped outright;
 *                               <meta itemprop> scaffolding unwrapped.
 *   3. Duplicate H1             the page title is rendered by the template, so a
 *                               leading H1 repeating it is removed.
 *   4. Phone numbers            <a href="tel:…"> whose number is configured in
 *                               Ekwa Settings → Locations becomes [ekwa_phone].
 *                               Numbers the settings don't know are LEFT ALONE
 *                               and reported, never guessed at.
 *   5. Internal links           links to the source site are re-pointed at the
 *                               matching page on this site. Only hosts the user
 *                               nominated are touched, so cdc.gov and the like
 *                               survive untouched.
 *
 * Every step reports rather than throws: the result carries a `warnings` list in
 * the same shape the converter's loss report uses, so one screen can show "here
 * is what came across and here is what needs a human".
 *
 * BACK-COMPAT: nothing here runs unless a row actually carries a `content`
 * value. CSVs without the column import exactly as they did before, and pages
 * created by earlier versions are untouched — they simply have no stored
 * content and no button.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------
 * Meta keys and options.
 * ------------------------------------------------------------------ */

/** Raw imported HTML, exactly as the CSV carried it. */
const EKWA_IMPORT_META_CONTENT = '_ekwa_import_content';

/** The source URL the row came from — the key for internal-link remapping. */
const EKWA_IMPORT_META_SOURCE_URL = '_ekwa_import_source_url';

/** Timestamp of the last time the stored content was applied to the page. */
const EKWA_IMPORT_META_APPLIED = '_ekwa_import_applied_at';

/** Option: newline/comma separated hosts treated as "the site we imported from". */
const EKWA_IMPORT_OPTION_HOSTS = 'ekwa_import_source_hosts';

/**
 * Hosts that count as the imported site.
 *
 * Saved by the user in Bulk Page Creator; when nothing is saved we fall back to
 * the hosts seen in the stored source URLs, so the common case needs no setup.
 * Both the apex and www forms of every host are returned — an export mixes them
 * freely (this one had 200 www links and 3 apex).
 *
 * @return string[] Lower-cased hosts, www-stripped and www-added.
 */
function ekwa_import_source_hosts() {
	$raw   = (string) get_option( EKWA_IMPORT_OPTION_HOSTS, '' );
	$hosts = array();

	foreach ( preg_split( '/[\s,]+/', $raw ) as $entry ) {
		$entry = trim( (string) $entry );
		if ( '' === $entry ) {
			continue;
		}
		// Accept a bare host or a full URL.
		if ( false !== strpos( $entry, '//' ) ) {
			$entry = (string) wp_parse_url( $entry, PHP_URL_HOST );
		}
		$entry = strtolower( trim( $entry, '/' ) );
		if ( '' === $entry ) {
			continue;
		}
		$bare = preg_replace( '/^www\./', '', $entry );
		$hosts[ $bare ]         = true;
		$hosts[ 'www.' . $bare ] = true;
	}

	return array_keys( $hosts );
}

/**
 * Normalise a URL to the comparison key used for link remapping.
 *
 * Host is lower-cased and www-stripped; the path loses its trailing slash and
 * its case; scheme, query and fragment are dropped. So all of
 * https://www.example.com/fillings/, http://example.com/fillings and
 * //www.example.com/Fillings/?utm=x#top collapse to "example.com/fillings".
 *
 * @param string $url
 * @return string Comparison key, or '' when the URL has no host.
 */
function ekwa_import_url_key( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) {
		return '';
	}
	$host = preg_replace( '/^www\./', '', strtolower( $parts['host'] ) );
	$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';

	return $host . strtolower( $path );
}

/* ------------------------------------------------------------------
 * Storage.
 * ------------------------------------------------------------------ */

/**
 * Park a row's imported HTML on a page.
 *
 * Follows the importer's existing "fill only what's empty" rule: an existing
 * stored value is never replaced, so re-running the CSV after an author has
 * already converted and revised a page cannot clobber their work. post_content
 * is never touched here — applying is a separate, explicit step.
 *
 * @param int    $post_id
 * @param string $html       Raw HTML from the CSV's content column.
 * @param string $source_url The row's url column.
 * @param bool   $overwrite  Replace an existing stored value.
 * @return bool True when something was written.
 */
function ekwa_import_store_content( $post_id, $html, $source_url = '', $overwrite = false ) {
	$post_id = (int) $post_id;
	$html    = (string) $html;

	if ( $post_id <= 0 || '' === trim( $html ) ) {
		return false;
	}

	$existing = (string) get_post_meta( $post_id, EKWA_IMPORT_META_CONTENT, true );
	if ( '' !== trim( $existing ) && ! $overwrite ) {
		return false;
	}

	// wp_slash: update_post_meta runs the value through stripslashes, which
	// would eat backslashes that are legitimately part of the markup.
	update_post_meta( $post_id, EKWA_IMPORT_META_CONTENT, wp_slash( $html ) );

	$source_url = esc_url_raw( trim( (string) $source_url ) );
	if ( '' !== $source_url ) {
		update_post_meta( $post_id, EKWA_IMPORT_META_SOURCE_URL, $source_url );
	}

	return true;
}

/**
 * The stored HTML for a page, or '' when there is none.
 *
 * @param int $post_id
 * @return string
 */
function ekwa_import_get_content( $post_id ) {
	return (string) get_post_meta( (int) $post_id, EKWA_IMPORT_META_CONTENT, true );
}

/**
 * Whether a page has imported content waiting to be converted.
 *
 * @param int $post_id
 * @return bool
 */
function ekwa_import_has_content( $post_id ) {
	return '' !== trim( ekwa_import_get_content( $post_id ) );
}

/* ------------------------------------------------------------------
 * The source-URL → local-page index.
 * ------------------------------------------------------------------ */

/**
 * Map every known source URL to the page that now represents it.
 *
 * Built from the source URLs the importer stored, so it covers exactly the
 * pages that came from this import. Cached per request only — it is consulted
 * a few hundred times while converting one page and then thrown away.
 *
 * @return array<string,int> url key => post ID.
 */
function ekwa_import_source_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	global $wpdb;
	$map = array();

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT pm.post_id, pm.meta_value
		   FROM {$wpdb->postmeta} pm
		   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		  WHERE pm.meta_key = %s
		    AND p.post_status <> 'trash'",
		EKWA_IMPORT_META_SOURCE_URL
	) );

	foreach ( (array) $rows as $row ) {
		$key = ekwa_import_url_key( $row->meta_value );
		if ( '' !== $key && ! isset( $map[ $key ] ) ) {
			$map[ $key ] = (int) $row->post_id;
		}
	}

	return $map;
}

/**
 * Reset the per-request source map (used by tests and after bulk imports).
 */
function ekwa_import_flush_source_map() {
	// Re-running the static initialiser is not possible from outside, so the
	// map is rebuilt by the next request. Bulk imports finish with a redirect,
	// which is a new request, so this is a no-op marker for callers.
	return true;
}

/**
 * Resolve a source-site path to a local page ID.
 *
 * Tried in order: the source-URL index (exact provenance), then a slug lookup
 * against published pages (covers pages that existed before the import).
 *
 * @param string $path Path portion of the source URL, e.g. "/fillings/".
 * @param string $key  Full comparison key from ekwa_import_url_key().
 * @return int Post ID, or 0.
 */
function ekwa_import_resolve_local_page( $path, $key ) {
	$map = ekwa_import_source_map();
	if ( '' !== $key && isset( $map[ $key ] ) ) {
		return (int) $map[ $key ];
	}

	// Fall back to the last path segment as a slug.
	$slug = sanitize_title( basename( rtrim( (string) $path, '/' ) ) );
	if ( '' === $slug ) {
		return 0;
	}

	$page = get_page_by_path( $slug, OBJECT, 'page' );

	return $page ? (int) $page->ID : 0;
}

/* ------------------------------------------------------------------
 * Pre-conversion normalisation.
 * ------------------------------------------------------------------ */

/**
 * Collect a warning in the converter's report shape.
 *
 * @param array  $warnings Collected so far (by reference).
 * @param string $category media | phone | link | dropped | converted | general
 * @param string $message  Human-readable, already translated.
 */
function ekwa_import_warn( &$warnings, $category, $message ) {
	$warnings[] = array(
		'category' => $category,
		'message'  => $message,
	);
}

/**
 * Resolve a possibly-relative URL against a base URL.
 *
 * Handles the four forms an exported page actually contains: absolute
 * (https://host/path), protocol-relative (//host/path), root-relative (/path)
 * and document-relative (path). Anything else is returned unchanged.
 *
 * @param string $url  URL as written in the markup.
 * @param string $base Absolute URL of the page the markup came from.
 * @return string Absolute URL, or the input when it cannot be resolved.
 */
function ekwa_import_absolutize( $url, $base ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return $url;
	}
	if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
		return $url; // Already absolute (http:, https:, tel:, mailto:, data:).
	}

	$b = wp_parse_url( (string) $base );
	if ( empty( $b['host'] ) ) {
		return $url;
	}
	$scheme = isset( $b['scheme'] ) ? $b['scheme'] : 'https';

	if ( 0 === strpos( $url, '//' ) ) {
		return $scheme . ':' . $url;
	}
	if ( 0 === strpos( $url, '/' ) ) {
		return $scheme . '://' . $b['host'] . $url;
	}
	if ( 0 === strpos( $url, '#' ) || 0 === strpos( $url, '?' ) ) {
		return $url; // Same-document reference — leave it alone.
	}

	$dir = isset( $b['path'] ) ? rtrim( dirname( $b['path'] . 'x' ), '/' ) : '';

	return $scheme . '://' . $b['host'] . $dir . '/' . $url;
}

/**
 * Point lazy-loaded <img> elements at their real source.
 *
 * A lazyloading source site ships `data-src` (and often `data-srcset`) with NO
 * `src` at all — every image in the sample export was shaped that way. Left
 * unresolved the converter sees an <img> with nothing to import and the page
 * arrives with its images silently missing, which is the single most damaging
 * failure mode of the whole import. Existing `src` is never overwritten.
 *
 * @param DOMDocument $doc
 * @return int Number of images repaired.
 */
function ekwa_import_fix_lazy_images( $doc ) {
	$fixed = 0;

	foreach ( iterator_to_array( $doc->getElementsByTagName( 'img' ) ) as $img ) {
		$src = trim( $img->getAttribute( 'src' ) );

		// A 1x1 gif/svg placeholder counts as "no source".
		$is_placeholder = ( '' === $src ) || 0 === stripos( $src, 'data:' );

		if ( $is_placeholder ) {
			foreach ( array( 'data-src', 'data-original', 'data-lazy-src', 'data-lazy' ) as $attr ) {
				$candidate = trim( $img->getAttribute( $attr ) );
				if ( '' !== $candidate && 0 !== stripos( $candidate, 'data:' ) ) {
					$img->setAttribute( 'src', $candidate );
					$fixed++;
					break;
				}
			}
		}

		if ( '' === trim( $img->getAttribute( 'srcset' ) ) ) {
			foreach ( array( 'data-srcset', 'data-lazy-srcset' ) as $attr ) {
				$candidate = trim( $img->getAttribute( $attr ) );
				if ( '' !== $candidate ) {
					$img->setAttribute( 'srcset', $candidate );
					break;
				}
			}
		}

		foreach ( array( 'data-src', 'data-srcset', 'data-original', 'data-lazy-src', 'data-lazy', 'data-lazy-srcset' ) as $attr ) {
			$img->removeAttribute( $attr );
		}
	}

	ekwa_import_strip_lazy_classes( $doc );

	return $fixed;
}

/**
 * Strip lazyload marker classes from every element.
 *
 * This theme owns that class: Performance → lazy mode decides whether images
 * are rewritten to lazysizes (`src` → `data-src` + `.lazyload`) at RENDER time,
 * per site. Imported markup carrying the source site's own `.lazyload` would
 * either double up on that or, with lazy mode off, leave elements styled as
 * permanently-pending with no script to unveil them. Plain markup in, theme
 * decides on the way out.
 *
 * @param DOMDocument $doc
 * @return void
 */
function ekwa_import_strip_lazy_classes( $doc ) {
	$xpath = new DOMXPath( $doc );
	$nodes = $xpath->query( '//*[contains(@class, "lazyload")]' );

	foreach ( iterator_to_array( $nodes ) as $node ) {
		$class = trim( preg_replace(
			'/\s+/',
			' ',
			(string) preg_replace( '/\b(lazyload|lazyloading|lazyloaded)\b/', ' ', $node->getAttribute( 'class' ) )
		) );

		if ( '' === $class ) {
			$node->removeAttribute( 'class' );
		} else {
			$node->setAttribute( 'class', $class );
		}
	}
}

/**
 * Download images referenced by imported markup into the Media Library.
 *
 * Runs after ekwa_import_fix_lazy_images(), so `src` is the real URL by now.
 * Each image is pulled through ekwa_bulk_pages_sideload_image(), the same
 * helper the featured-image importer uses — which means the same
 * `_ekwa_bulk_source_url` dedupe: a banner shared by twenty pages is stored
 * once, and re-converting a page re-uses what is already in the library rather
 * than downloading it again.
 *
 * Rewrites `src` to the local attachment URL and drops the source site's
 * `srcset`, which still pointed at the old host. WordPress regenerates srcset
 * from the attachment's own sizes at render time.
 *
 * A download that fails is reported and the original URL is left in place, so
 * the page still shows the image (hot-linked) rather than a gap, and the author
 * can see exactly which ones need attention.
 *
 * @param DOMDocument $doc
 * @param array       $warnings By reference.
 * @return int Number of images now served from the Media Library.
 */
function ekwa_import_sideload_images( $doc, &$warnings ) {
	if ( ! function_exists( 'ekwa_bulk_pages_sideload_image' ) ) {
		return 0;
	}

	$imported = 0;
	$failed   = array();

	foreach ( iterator_to_array( $doc->getElementsByTagName( 'img' ) ) as $img ) {
		$src = trim( $img->getAttribute( 'src' ) );
		if ( '' === $src || ! preg_match( '#^https?://#i', $src ) ) {
			continue;
		}

		// Already ours? Nothing to do.
		if ( 0 === strpos( $src, home_url() ) ) {
			continue;
		}

		$attachment_id = ekwa_bulk_pages_sideload_image( $src, trim( $img->getAttribute( 'alt' ) ) );

		if ( is_wp_error( $attachment_id ) ) {
			$failed[ $src ] = $attachment_id->get_error_message();
			continue;
		}

		$local = wp_get_attachment_url( $attachment_id );
		if ( ! $local ) {
			$failed[ $src ] = __( 'imported but has no URL', 'ekwa' );
			continue;
		}

		$img->setAttribute( 'src', $local );
		// The old host's srcset would keep the page hot-linking the source site.
		$img->removeAttribute( 'srcset' );
		$img->removeAttribute( 'sizes' );
		$imported++;
	}

	if ( $failed ) {
		$list = array();
		foreach ( array_slice( $failed, 0, 5, true ) as $url => $why ) {
			$list[] = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) . ' (' . $why . ')';
		}
		if ( count( $failed ) > 5 ) {
			/* translators: %d: number of further images */
			$list[] = sprintf( __( 'and %d more', 'ekwa' ), count( $failed ) - 5 );
		}
		ekwa_import_warn(
			$warnings,
			'media',
			sprintf(
				/* translators: %s: comma-separated list of filenames */
				__( 'Could not copy these images into the Media Library, so the page still loads them from the old site: %s.', 'ekwa' ),
				implode( ', ', $list )
			)
		);
	}

	return $imported;
}

/**
 * Rewrite schema.org FAQ accordions into <details> runs.
 *
 * The exported accordion is Bootstrap markup — nested .panel/.collapse divs
 * wired to a data-toggle script we do not ship — but it carries schema.org
 * microdata, which is the most reliable signal in the whole document:
 *
 *   <div itemtype="…/FAQPage">
 *     <div itemprop="mainEntity" itemtype="…/Question">
 *       <span itemprop="name">Question?</span>
 *       <div itemprop="acceptedAnswer"><div itemprop="text">…answer…</div></div>
 *
 * Rather than teach the converter a second FAQ shape, this normalises to the
 * one it ALREADY converts: a run of sibling <details><summary>. That reuses
 * ekwa_mc_convert_details_run() — shipped and exercised — so the FAQ path gains
 * no new block-emitting code and no new way to be wrong.
 *
 * Questions with no name, or no answer, are skipped and reported; a wrapper
 * that yields no usable question at all is left untouched for the converter to
 * handle as ordinary markup.
 *
 * @param DOMDocument $doc
 * @param array       $warnings By reference.
 * @return int Number of questions rewritten.
 */
function ekwa_import_rewrite_faq( $doc, &$warnings ) {
	$xpath   = new DOMXPath( $doc );
	$total   = 0;
	$skipped = 0;

	$wrappers = $xpath->query( '//*[contains(@itemtype, "schema.org/FAQPage")]' );

	foreach ( iterator_to_array( $wrappers ) as $wrapper ) {
		$questions = $xpath->query( './/*[@itemprop="mainEntity"]', $wrapper );
		if ( ! $questions->length ) {
			continue;
		}

		$fragment = $doc->createDocumentFragment();
		$made     = 0;

		foreach ( iterator_to_array( $questions ) as $question ) {
			$name_node = $xpath->query( './/*[@itemprop="name"]', $question )->item( 0 );
			$text_node = $xpath->query( './/*[@itemprop="text"]', $question )->item( 0 );

			$label = $name_node ? trim( $name_node->textContent ) : '';
			if ( '' === $label || ! $text_node ) {
				$skipped++;
				continue;
			}

			$details = $doc->createElement( 'details' );
			$summary = $doc->createElement( 'summary' );
			$summary->appendChild( $doc->createTextNode( $label ) );
			$details->appendChild( $summary );

			// Move the answer's children across — appendChild on a node that is
			// still parented would silently reparent mid-iteration, so snapshot.
			foreach ( iterator_to_array( $text_node->childNodes ) as $child ) {
				$details->appendChild( $child );
			}

			$fragment->appendChild( $details );
			$made++;
		}

		if ( ! $made ) {
			continue;
		}

		$wrapper->parentNode->replaceChild( $fragment, $wrapper );
		$total += $made;
	}

	if ( $total ) {
		ekwa_import_warn(
			$warnings,
			'converted',
			sprintf(
				/* translators: %d: number of questions */
				_n( 'Converted %d FAQ question into an Ekwa FAQ block.', 'Converted %d FAQ questions into Ekwa FAQ blocks.', $total, 'ekwa' ),
				$total
			)
		);
	}
	if ( $skipped ) {
		ekwa_import_warn(
			$warnings,
			'dropped',
			sprintf(
				/* translators: %d: number of questions */
				_n( 'Skipped %d FAQ entry that had no question or no answer.', 'Skipped %d FAQ entries that had no question or no answer.', $skipped, 'ekwa' ),
				$skipped
			)
		);
	}

	return $total;
}

/**
 * Rewrite Ekwa video-plugin players into data-ekwa="video" tokens.
 *
 * The exported player is a wrapper full of schema.org metas plus a div holding
 * the provider and video id. Every field it carries has an exact counterpart on
 * ekwa/youtube-video and ekwa/vimeo-video — title, description, duration,
 * upload date, thumbnail, transcript — so this is a lossless mapping rather
 * than an approximation.
 *
 * It emits the converter's OWN documented extension point (`data-ekwa`) rather
 * than block markup, so the import module stays out of the business of
 * serializing blocks and the converter keeps its single code path for turning a
 * token into a block.
 *
 * @param DOMDocument $doc
 * @param array       $warnings By reference.
 * @return int Number of players rewritten.
 */
function ekwa_import_rewrite_videos( $doc, &$warnings ) {
	$xpath   = new DOMXPath( $doc );
	$count   = 0;
	$skipped = 0;

	$wrappers = $xpath->query(
		'//*[contains(concat(" ", normalize-space(@class), " "), " ekv-wrapper ")]'
		. ' | //*[contains(@itemtype, "schema.org/VideoObject")]'
	);

	foreach ( iterator_to_array( $wrappers ) as $wrapper ) {
		// The player div carries the provider and id — without it there is
		// nothing to embed, so leave the markup alone rather than guess.
		$player = $xpath->query( './/*[@data-id and @data-provider]', $wrapper )->item( 0 );

		$meta = static function ( $prop ) use ( $xpath, $wrapper ) {
			$n = $xpath->query( './/meta[@itemprop="' . $prop . '"]', $wrapper )->item( 0 );
			return $n ? trim( $n->getAttribute( 'content' ) ) : '';
		};

		$embed_url = $meta( 'embedURL' );
		$video_id  = $player ? trim( $player->getAttribute( 'data-id' ) ) : '';
		$provider  = $player ? strtolower( trim( $player->getAttribute( 'data-provider' ) ) ) : '';

		// Fall back to reading the provider/id out of the embed URL.
		if ( '' === $provider && '' !== $embed_url ) {
			if ( preg_match( '#(youtube\.com|youtu\.be)#i', $embed_url ) ) {
				$provider = 'youtube';
			} elseif ( false !== stripos( $embed_url, 'vimeo.com' ) ) {
				$provider = 'vimeo';
			}
		}
		if ( '' === $video_id && '' !== $embed_url ) {
			if ( preg_match( '#/(?:embed|video)/([A-Za-z0-9_-]+)#', $embed_url, $m ) ) {
				$video_id = $m[1];
			}
		}

		if ( '' === $video_id || ! in_array( $provider, array( 'youtube', 'vimeo' ), true ) ) {
			$skipped++;
			continue;
		}

		$token = $doc->createElement( 'div' );
		$token->setAttribute( 'data-ekwa', 'video' );
		$token->setAttribute( 'data-ekwa-provider', $provider );
		$token->setAttribute( 'data-ekwa-video-id', $video_id );

		foreach ( array(
			'data-ekwa-embed-url'  => $embed_url,
			'data-ekwa-title'      => $meta( 'name' ),
			'data-ekwa-duration'   => $meta( 'duration' ),
			'data-ekwa-upload'     => $meta( 'uploadDate' ),
			'data-ekwa-thumbnail'  => $meta( 'thumbnailURL' ),
		) as $attr => $value ) {
			if ( '' !== $value ) {
				$token->setAttribute( $attr, $value );
			}
		}

		// Description and transcript are long-form and may contain the phone
		// numbers the later pass rewrites, so they travel as child elements
		// rather than attributes and stay part of the document.
		foreach ( array(
			'description' => './/*[@itemprop="description"]',
			'transcript'  => './/*[contains(concat(" ", normalize-space(@class), " "), " ekv-transcript ")]',
		) as $part => $query ) {
			$found = $xpath->query( $query, $wrapper )->item( 0 );
			if ( ! $found || '' === trim( $found->textContent ) ) {
				continue;
			}
			$holder = $doc->createElement( 'div' );
			$holder->setAttribute( 'data-ekwa-part', $part );
			foreach ( iterator_to_array( $found->childNodes ) as $child ) {
				$holder->appendChild( $child );
			}
			$token->appendChild( $holder );
		}

		$wrapper->parentNode->replaceChild( $token, $wrapper );
		$count++;
	}

	if ( $count ) {
		ekwa_import_warn(
			$warnings,
			'converted',
			sprintf(
				/* translators: %d: number of videos */
				_n( 'Converted %d video into an Ekwa video block, with its title, duration, thumbnail and transcript.', 'Converted %d videos into Ekwa video blocks, with their titles, durations, thumbnails and transcripts.', $count, 'ekwa' ),
				$count
			)
		);
	}
	if ( $skipped ) {
		ekwa_import_warn(
			$warnings,
			'media',
			sprintf(
				/* translators: %d: number of players */
				_n( 'Left %d video player as plain markup — no YouTube or Vimeo id could be read from it.', 'Left %d video players as plain markup — no YouTube or Vimeo id could be read from them.', $skipped, 'ekwa' ),
				$skipped
			)
		);
	}

	return $count;
}

/**
 * Remove markup that must never reach post_content.
 *
 * <script>/<noscript> are third-party code from someone else's site.
 * <meta itemprop> is schema scaffolding — the sample export carried 337 of
 * them inside FAQ and video wrappers; the FAQ/video detectors read them first
 * (they are the most reliable signal in the document) and this runs after, so
 * only the leftovers are cleared. <link> and <style> follow the same logic.
 *
 * @param DOMDocument $doc
 * @param array       $warnings By reference.
 * @return void
 */
function ekwa_import_strip_noise( $doc, &$warnings ) {
	$dropped = array();

	foreach ( array( 'script', 'noscript', 'style', 'link', 'meta' ) as $tag ) {
		foreach ( iterator_to_array( $doc->getElementsByTagName( $tag ) ) as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
				$dropped[ $tag ] = ( $dropped[ $tag ] ?? 0 ) + 1;
			}
		}
	}

	if ( ! empty( $dropped['script'] ) ) {
		ekwa_import_warn(
			$warnings,
			'dropped',
			sprintf(
				/* translators: %d: number of script tags */
				_n( 'Removed %d <script> tag from the imported markup — scripts are never copied across.', 'Removed %d <script> tags from the imported markup — scripts are never copied across.', (int) $dropped['script'], 'ekwa' ),
				(int) $dropped['script']
			)
		);
	}
}

/**
 * Drop a leading H1 that just repeats the page title.
 *
 * The template renders the title (page banner / H1 block), so keeping the
 * source page's own H1 puts it on screen twice. Only the FIRST heading is
 * considered, and only when it matches the title — an H1 that says something
 * else is real content and stays.
 *
 * @param DOMDocument $doc
 * @param string      $title Page title to compare against.
 * @return bool True when a heading was removed.
 */
function ekwa_import_drop_leading_h1( $doc, $title ) {
	$title = trim( (string) $title );
	if ( '' === $title ) {
		return false;
	}

	$h1s = $doc->getElementsByTagName( 'h1' );
	if ( ! $h1s->length ) {
		return false;
	}

	$first = $h1s->item( 0 );
	$norm  = static function ( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
		$s = preg_replace( '/[^a-z0-9]+/u', ' ', strtolower( $s ) );
		return trim( preg_replace( '/\s+/', ' ', (string) $s ) );
	};

	if ( $norm( $first->textContent ) !== $norm( $title ) ) {
		return false;
	}

	$first->parentNode->removeChild( $first );

	return true;
}

/**
 * Swap phone numbers for [ekwa_phone], and report the ones we left alone.
 *
 * Two passes, because an exported number lives in two places at once:
 *
 *   <a href="tel:+13102549355">(310) 254-9355</a>
 *
 * Rewriting only the visible text would leave the shortcode sitting inside a
 * hard-coded tel: href — the number would then change in the link text and not
 * in the link, which is worse than not converting at all. So a tel: anchor is
 * replaced as a whole element, and only then are the remaining text nodes
 * scanned for numbers written as plain text.
 *
 * Numbers Ekwa Settings does not know are never rewritten — that is the
 * existing contract of ekwa_phone_replace_in_text() and it is deliberate (a
 * referral office, a lab, a fax). They are collected and reported instead, so
 * the author can add them to Locations and re-run, or leave them as text
 * knowingly.
 *
 * @param DOMDocument $doc
 * @param array       $warnings By reference.
 * @return int Number of numbers converted.
 */
function ekwa_import_rewrite_phones( $doc, &$warnings ) {
	$phone_map = ekwa_phone_token_map();
	$converted = 0;
	$unknown   = array();

	// ── Pass 1: <a href="tel:…"> elements ──────────────────────────
	foreach ( iterator_to_array( $doc->getElementsByTagName( 'a' ) ) as $a ) {
		$href = trim( $a->getAttribute( 'href' ) );
		if ( 0 !== stripos( $href, 'tel:' ) ) {
			continue;
		}

		// The href is the authoritative number; the label may be formatted
		// any which way, or be something like "Call us".
		$digits = ekwa_phone_normalize_digits( substr( $href, 4 ) );
		if ( '' === $digits ) {
			$digits = ekwa_phone_normalize_digits( $a->textContent );
		}

		if ( '' === $digits || ! isset( $phone_map[ $digits ] ) ) {
			if ( '' !== $digits && ! isset( $unknown[ $digits ] ) ) {
				$unknown[ $digits ] = trim( $a->textContent ) ?: $digits;
			}
			continue;
		}

		$a->parentNode->replaceChild(
			$doc->createTextNode( $phone_map[ $digits ] ),
			$a
		);
		$converted++;
	}

	// ── Pass 2: numbers written as plain text ──────────────────────
	$xpath = new DOMXPath( $doc );
	foreach ( iterator_to_array( $xpath->query( '//text()' ) ) as $text ) {
		$value = $text->nodeValue;
		if ( '' === trim( (string) $value ) ) {
			continue;
		}
		$swapped = ekwa_phone_replace_in_text( $value, $phone_map );
		if ( $swapped !== $value ) {
			// Count how many tags appeared, not how many nodes changed.
			$converted += substr_count( $swapped, '[ekwa_phone' ) - substr_count( (string) $value, '[ekwa_phone' );
			$text->nodeValue = $swapped;
		}
	}

	// ── Report what was deliberately left as literal text ──────────
	foreach ( ekwa_phone_find_unconfigured( $doc->textContent, $phone_map ) as $raw ) {
		$digits = ekwa_phone_normalize_digits( $raw );
		if ( '' !== $digits && ! isset( $unknown[ $digits ] ) ) {
			$unknown[ $digits ] = $raw;
		}
	}

	if ( $unknown ) {
		ekwa_import_warn(
			$warnings,
			'phone',
			sprintf(
				/* translators: %s: comma-separated list of phone numbers */
				__( 'Left as plain text because they are not in Ekwa Settings → Locations: %s. Add them there and convert again, or leave them if they belong to someone else.', 'ekwa' ),
				implode( ', ', array_values( $unknown ) )
			)
		);
	}

	return $converted;
}

/**
 * Re-point links that referred to the imported site at their new pages.
 *
 * Only hosts the user nominated as "the site we imported from" are considered.
 * That allowlist is the whole safety story: the sample export links to cdc.gov,
 * ada.org, fda.gov, carecredit and yelp, and rewriting those would be
 * destructive. Everything off-allowlist is left exactly as written, silently —
 * an external link is not a problem to report.
 *
 * For an on-allowlist link there are three outcomes:
 *   - a page exists for it        → href becomes that page's permalink
 *   - no page exists for it (yet) → left as-is and reported, because it will
 *                                   otherwise keep pointing at the old site
 *   - it is the imported page itself → left as-is, reported as a self link
 *
 * Query strings are dropped (they were the old site's) and fragments kept.
 *
 * @param DOMDocument $doc
 * @param string      $source_url URL the markup came from.
 * @param int         $post_id    Page being built, to detect self-links.
 * @param array       $warnings   By reference.
 * @return int Number of links re-pointed.
 */
function ekwa_import_rewrite_links( $doc, $source_url, $post_id, &$warnings ) {
	$hosts = ekwa_import_source_hosts();
	if ( empty( $hosts ) ) {
		$derived = ekwa_import_url_key( $source_url );
		if ( '' === $derived ) {
			return 0;
		}
		$bare  = strtok( $derived, '/' );
		$hosts = array( $bare, 'www.' . $bare );
	}

	$remapped   = 0;
	$unresolved = array();

	foreach ( iterator_to_array( $doc->getElementsByTagName( 'a' ) ) as $a ) {
		$href = trim( $a->getAttribute( 'href' ) );
		if ( '' === $href ) {
			continue;
		}
		// Non-navigational schemes and same-document refs are none of our business.
		if ( preg_match( '#^(tel:|mailto:|javascript:|data:|\#)#i', $href ) ) {
			continue;
		}

		$absolute = ekwa_import_absolutize( $href, $source_url );
		$parts    = wp_parse_url( $absolute );
		if ( empty( $parts['host'] ) ) {
			continue;
		}

		if ( ! in_array( strtolower( $parts['host'] ), $hosts, true ) ) {
			continue; // A genuine external link — leave it alone.
		}

		$path     = isset( $parts['path'] ) ? $parts['path'] : '/';
		$fragment = ! empty( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		$key      = ekwa_import_url_key( $absolute );

		// A link to the old site's root is a link to this site's home page —
		// there is no slug to look up, and reporting it as "unmatched" would
		// surface a warning with an empty path in it.
		if ( '' === trim( $path, '/' ) ) {
			$a->setAttribute( 'href', home_url( '/' ) . $fragment );
			$remapped++;
			continue;
		}

		$target_id = ekwa_import_resolve_local_page( $path, $key );

		if ( $target_id && $target_id === (int) $post_id ) {
			// A link from the page to itself; harmless, but rewriting it to a
			// permalink is pointless. Keep the fragment behaviour intact.
			$a->setAttribute( 'href', '' !== $fragment ? $fragment : get_permalink( $target_id ) );
			continue;
		}

		if ( $target_id ) {
			$a->setAttribute( 'href', get_permalink( $target_id ) . $fragment );
			$remapped++;
			continue;
		}

		$label = rtrim( $path, '/' );
		if ( ! isset( $unresolved[ $label ] ) ) {
			$unresolved[ $label ] = true;
		}
	}

	if ( $unresolved ) {
		ekwa_import_warn(
			$warnings,
			'link',
			sprintf(
				/* translators: %s: comma-separated list of URL paths */
				__( 'These links still point at the old site because no page here matches them yet: %s. Import or create those pages, then convert again.', 'ekwa' ),
				implode( ', ', array_keys( $unresolved ) )
			)
		);
	}

	return $remapped;
}

/* ------------------------------------------------------------------
 * The pipeline.
 * ------------------------------------------------------------------ */

/**
 * Normalise imported HTML so the block converter can do its job.
 *
 * Order matters. Images are repaired before anything can drop them; noise is
 * stripped before link/phone passes so removed nodes are not walked; the H1
 * check runs on the original heading text; phones are rewritten before links so
 * a tel: anchor is already gone by the time the link pass runs.
 *
 * Nothing here writes to the database — it is a pure text transform apart from
 * the optional media sideload, which is opt-in via $args['sideload'].
 *
 * @param string $html Raw imported HTML.
 * @param array  $args {
 *     @type string $source_url URL the markup came from.
 *     @type string $page_title Title, for the duplicate-H1 check.
 *     @type int    $post_id    Page being built (self-link detection).
 *     @type bool   $sideload   Download images into the media library.
 * }
 * @return array{html:string,warnings:array,stats:array}
 */
function ekwa_import_prepare_html( $html, $args = array() ) {
	$args = wp_parse_args( $args, array(
		'source_url' => '',
		'page_title' => '',
		'post_id'    => 0,
		'sideload'   => false,
	) );

	$warnings = array();
	$stats    = array(
		'images_fixed'     => 0,
		'images_imported'  => 0,
		'faq_items'        => 0,
		'videos'           => 0,
		'phones_converted' => 0,
		'links_remapped'   => 0,
		'h1_removed'       => 0,
	);

	$html = (string) $html;
	if ( '' === trim( $html ) ) {
		return array( 'html' => '', 'warnings' => $warnings, 'stats' => $stats );
	}

	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	// The XML declaration forces UTF-8; the wrapper keeps multiple top-level
	// siblings alive, exactly as ekwa_mc_convert_html() does.
	$doc->loadHTML(
		'<?xml encoding="utf-8"?><div data-ekwa-import-root="1">' . $html . '</div>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
	libxml_clear_errors();

	$stats['images_fixed'] = ekwa_import_fix_lazy_images( $doc );

	if ( $args['sideload'] && function_exists( 'ekwa_import_sideload_images' ) ) {
		$stats['images_imported'] = ekwa_import_sideload_images( $doc, $warnings );
	}

	// BEFORE strip_noise: both of these read the schema.org <meta itemprop>
	// scaffolding that strip_noise removes. Reordering these two lines below it
	// silently costs every video its title, duration, thumbnail and transcript.
	$stats['faq_items']  = ekwa_import_rewrite_faq( $doc, $warnings );
	$stats['videos']     = ekwa_import_rewrite_videos( $doc, $warnings );

	ekwa_import_strip_noise( $doc, $warnings );

	if ( ekwa_import_drop_leading_h1( $doc, $args['page_title'] ) ) {
		$stats['h1_removed'] = 1;
	}

	$stats['phones_converted'] = ekwa_import_rewrite_phones( $doc, $warnings );
	$stats['links_remapped']   = ekwa_import_rewrite_links( $doc, $args['source_url'], (int) $args['post_id'], $warnings );

	// The caller may want to keep working on the tree (the AI path lifts the
	// already-correct subtrees out of it before handing the rest to the model),
	// so the document goes back alongside the serialized HTML.
	return array(
		'html'     => ekwa_import_serialize_root( $doc ),
		'doc'      => $doc,
		'warnings' => $warnings,
		'stats'    => $stats,
	);
}

/**
 * Serialize the synthetic wrapper's children, not the wrapper itself.
 *
 * @param DOMDocument $doc
 * @return string
 */
function ekwa_import_serialize_root( $doc ) {
	$root = $doc->documentElement;

	if ( ! $root || ! $root->hasAttribute( 'data-ekwa-import-root' ) ) {
		return (string) $doc->saveHTML();
	}

	$out = '';
	foreach ( $root->childNodes as $child ) {
		$out .= $doc->saveHTML( $child );
	}

	return $out;
}

/**
 * POST /ekwa/v1/import-prepared — the page's imported content, ready to build from.
 *
 * Returns HTML for the AI Block Builder's prompt field, with the work done that
 * a language model cannot do for itself:
 *
 *   - lazy-loaded images resolved (the sample export had NO src on any image,
 *     only data-src — paste that in raw and every image is silently lost)
 *   - those images COPIED INTO THE MEDIA LIBRARY and rewritten to local URLs,
 *     so whatever the model builds points at this site, not the old one
 *   - phone numbers that match Ekwa Settings swapped for [ekwa_phone]
 *   - the old site's internal links re-pointed at the matching pages here
 *
 * The media copy is the one part that writes to the site, and it only happens
 * because someone pressed the button that calls this.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_import_rest_prepared( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );

	$allowed = ekwa_import_can_edit( $post_id );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	$html = ekwa_import_get_content( $post_id );
	if ( '' === trim( $html ) ) {
		return new WP_Error(
			'ekwa_import_empty',
			__( 'This page has no imported content stored on it.', 'ekwa' ),
			array( 'status' => 404 )
		);
	}

	$prepared = ekwa_import_prepare_html( $html, array(
		'source_url' => (string) get_post_meta( $post_id, EKWA_IMPORT_META_SOURCE_URL, true ),
		'page_title' => get_the_title( $post_id ),
		'post_id'    => $post_id,
		'sideload'   => (bool) $request->get_param( 'sideload' ),
	) );

	return rest_ensure_response( array(
		'html'     => $prepared['html'],
		'stats'    => $prepared['stats'],
		'warnings' => array_values( array_filter( $prepared['warnings'], static function ( $w ) {
			return '' !== trim( (string) $w['message'] );
		} ) ),
	) );
}

/**
 * Replace the parts that are already correct with placeholder tokens, in HTML.
 *
 * The model is handed HTML, not block markup — the same shape a person gets by
 * pasting a page into "Build with AI (Blocks)", which is the version that
 * produces good pages. Feeding it the converted blocks instead meant handing it
 * seven-deep ekwa/div scaffolding and asking it to design around that, and it
 * mostly just preserved the scaffolding.
 *
 * The exception is the handful of things the deterministic import got exactly
 * right and a language model cannot reproduce: an FAQ's question/answer
 * pairing, a video's transcript and upload date, an image's Media Library id.
 * Those subtrees are converted to blocks HERE, lifted out, and replaced with
 * [[EKWA_KEEP_n]] text. The model arranges around the tokens; they are swapped
 * back afterwards, so its output can never damage them.
 *
 * @param DOMDocument $doc Prepared document (mutated).
 * @return array<int,string> Block markup for each token, indexed by token number.
 */
function ekwa_import_protect_dom( $doc ) {
	$kept  = array();
	$xpath = new DOMXPath( $doc );

	// Convert one node (or a run of them) to block markup via the converter.
	$to_blocks = static function ( array $nodes ) use ( $doc ) {
		$html = '';
		foreach ( $nodes as $n ) {
			$html .= $doc->saveHTML( $n );
		}
		if ( '' === trim( $html ) ) {
			return '';
		}
		$res = ekwa_mc_convert_html( $html );
		return trim( $res['markup'] );
	};

	// Swap a run of sibling nodes for a single token.
	$swap = static function ( array $nodes ) use ( $doc, &$kept, $to_blocks ) {
		$markup = $to_blocks( $nodes );
		if ( '' === $markup ) {
			return;
		}
		$kept[] = $markup;
		$token  = $doc->createTextNode( "\n[[EKWA_KEEP_" . ( count( $kept ) - 1 ) . "]]\n" );
		$nodes[0]->parentNode->replaceChild( $token, $nodes[0] );
		foreach ( array_slice( $nodes, 1 ) as $extra ) {
			if ( $extra->parentNode ) {
				$extra->parentNode->removeChild( $extra );
			}
		}
	};

	// ── FAQ: consecutive <details> siblings are ONE accordion ──────
	// Converting them one at a time would produce a separate single-item
	// ekwa/faq per question instead of one accordion, which is why the run is
	// gathered before conversion (ekwa_mc_convert_details_run does the same).
	foreach ( iterator_to_array( $doc->getElementsByTagName( 'details' ) ) as $details ) {
		if ( ! $details->parentNode ) {
			continue; // Already consumed as part of an earlier run.
		}
		$run  = array( $details );
		$next = $details->nextSibling;
		while ( $next ) {
			if ( XML_TEXT_NODE === $next->nodeType && '' === trim( $next->textContent ) ) {
				$next = $next->nextSibling;
				continue;
			}
			if ( XML_ELEMENT_NODE !== $next->nodeType || 'details' !== strtolower( $next->nodeName ) ) {
				break;
			}
			$run[] = $next;
			$next  = $next->nextSibling;
		}
		$swap( $run );
	}

	// ── Videos, then figures, then bare images ─────────────────────
	// Figures before images so a <figure><img></figure> is taken whole rather
	// than having its image pulled out from under it.
	foreach ( array(
		'//*[@data-ekwa="video"]',
		'//figure',
		'//img',
	) as $query ) {
		foreach ( iterator_to_array( $xpath->query( $query ) ) as $node ) {
			if ( ! $node->parentNode ) {
				continue;
			}
			$swap( array( $node ) );
		}
	}

	return $kept;
}

/* ------------------------------------------------------------------
 * REST: convert the stored content into blocks, with a preview.
 * ------------------------------------------------------------------ */

add_action( 'rest_api_init', 'ekwa_import_register_routes' );

/**
 * Register the import-conversion routes.
 *
 * Two routes, both requiring the AI features' capability (the button that
 * calls them lives in the AI Block Builder):
 *
 *   GET  /ekwa/v1/import-status?post_id=…  — does this page have content parked?
 *   POST /ekwa/v1/import-convert           — convert it and return a preview.
 *
 * Neither writes post_content. Converting is a pure read → the author inserts
 * the result themselves, and can run it again as many times as they like.
 */
function ekwa_import_register_routes() {
	register_rest_route( 'ekwa/v1', '/import-status', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'ekwa_import_rest_status',
		'permission_callback' => 'ekwa_ai_rest_permission',
		'args'                => array(
			'post_id' => array( 'required' => true, 'type' => 'integer' ),
		),
	) );

	// Hand the page's imported content over to "Build with AI (Blocks)" as
	// prompt text. This is the path that produces good pages: the AI Block
	// Builder already designs well from pasted content, so rather than
	// reimplementing that, the importer does the part it cannot do — resolve
	// lazy-loaded images and copy them into the Media Library, swap configured
	// phone numbers for [ekwa_phone], and re-point the old site's internal
	// links — and hands over content that is ready to build from.
	register_rest_route( 'ekwa/v1', '/import-prepared', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_import_rest_prepared',
		'permission_callback' => 'ekwa_ai_rest_permission',
		'args'                => array(
			'post_id'  => array( 'required' => true, 'type' => 'integer' ),
			'sideload' => array( 'required' => false, 'type' => 'boolean', 'default' => true ),
		),
	) );

	register_rest_route( 'ekwa/v1', '/import-convert', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_import_rest_convert',
		'permission_callback' => 'ekwa_ai_rest_permission',
		'args'                => array(
			'post_id'  => array( 'required' => true, 'type' => 'integer' ),
			'sideload' => array( 'required' => false, 'type' => 'boolean', 'default' => true ),
			// Off by default: a faithful conversion is free, instant and
			// repeatable, so the billable design pass is something the author
			// asks for rather than something that happens to them.
			'design'   => array( 'required' => false, 'type' => 'boolean', 'default' => false ),
			'model'    => array( 'required' => false, 'type' => 'string',  'default' => '' ),
		),
	) );
}

/**
 * Whether the current user may work with this page's imported content.
 *
 * The route-level capability covers "may use the AI tools at all"; this is the
 * per-object half — you must also be able to edit the page in question.
 *
 * @param int $post_id
 * @return true|WP_Error
 */
function ekwa_import_can_edit( $post_id ) {
	$post_id = (int) $post_id;
	$post    = $post_id ? get_post( $post_id ) : null;

	if ( ! $post ) {
		return new WP_Error( 'ekwa_import_no_post', __( 'That page no longer exists.', 'ekwa' ), array( 'status' => 404 ) );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'ekwa_import_forbidden', __( 'You cannot edit that page.', 'ekwa' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * GET /ekwa/v1/import-status — is there imported content on this page?
 *
 * Drives whether the button appears at all, so it stays cheap: no conversion,
 * just the presence of the stored HTML and whether it has been applied before.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_import_rest_status( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );

	$allowed = ekwa_import_can_edit( $post_id );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	$html = ekwa_import_get_content( $post_id );

	// The section designs available to build with. Collected from saved
	// patterns, the Inner Page Template and the site's own pages — so this is
	// normally non-empty even on a site that has never configured anything.
	$patterns    = function_exists( 'ekwa_design_vocabulary' ) ? ekwa_design_vocabulary( $post_id ) : array();
	$template_id = function_exists( 'ekwa_inner_template_id' ) ? ekwa_inner_template_id() : 0;

	// Where each design came from, so the modal can say so rather than implying
	// everything came from a template the practice may not have set up.
	$by_source = array();
	foreach ( $patterns as $p ) {
		$src               = isset( $p['source'] ) ? $p['source'] : 'page';
		$by_source[ $src ] = ( isset( $by_source[ $src ] ) ? $by_source[ $src ] : 0 ) + 1;
	}

	return rest_ensure_response( array(
		'has_content'    => '' !== trim( $html ),
		'size'           => strlen( $html ),
		'source_url'     => (string) get_post_meta( $post_id, EKWA_IMPORT_META_SOURCE_URL, true ),
		'applied_at'     => (string) get_post_meta( $post_id, EKWA_IMPORT_META_APPLIED, true ),
		'template_id'    => $template_id,
		'template_title' => $template_id ? get_the_title( $template_id ) : '',
		'template_edit'  => $template_id ? (string) get_edit_post_link( $template_id, 'raw' ) : '',
		'pattern_count'  => count( $patterns ),
		'pattern_labels' => wp_list_pluck( $patterns, 'label' ),
		'pattern_sources' => $by_source,
		'has_api_key'    => function_exists( 'ekwa_get_ai_api_key' ) && (bool) ekwa_get_ai_api_key(),
	) );
}

/**
 * POST /ekwa/v1/import-convert — imported HTML → block markup + preview.
 *
 * Deliberately does NOT save anything to the page. The author sees the preview,
 * decides, and inserts from the editor; running this again just produces a
 * fresh result from the same stored HTML, which is what makes "revise as many
 * times as you like" work without any undo machinery.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_import_rest_convert( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );

	$allowed = ekwa_import_can_edit( $post_id );
	if ( is_wp_error( $allowed ) ) {
		return $allowed;
	}

	$html = ekwa_import_get_content( $post_id );
	if ( '' === trim( $html ) ) {
		return new WP_Error(
			'ekwa_import_empty',
			__( 'This page has no imported content stored on it.', 'ekwa' ),
			array( 'status' => 404 )
		);
	}

	$prepared = ekwa_import_prepare_html( $html, array(
		'source_url' => (string) get_post_meta( $post_id, EKWA_IMPORT_META_SOURCE_URL, true ),
		'page_title' => get_the_title( $post_id ),
		'post_id'    => $post_id,
		'sideload'   => (bool) $request->get_param( 'sideload' ),
	) );

	// The converter library is NOT loaded on every request — functions.php
	// leaves it out and each caller pulls it in on demand (see
	// ekwa-converter-api.php, ekwa-converter-menu.php, ekwa-mockup-contract.php,
	// which all do exactly this). Calling ekwa_mc_convert_html() without it is
	// a fatal, which reaches the browser as WordPress's generic "critical error"
	// page with nothing to go on.
	require_once get_template_directory() . '/inc/ekwa-converter-lib.php';

	$warnings = $prepared['warnings'];

	// Lift the already-correct parts out as tokens, leaving HTML for the model.
	$kept      = ekwa_import_protect_dom( $prepared['doc'] );
	$body_html = ekwa_import_serialize_root( $prepared['doc'] );

	$designed = false;
	if ( function_exists( 'ekwa_inner_template_design_pass' ) ) {
		$markup = ekwa_inner_template_design_pass( $body_html, $warnings, array(
			'model'   => (string) $request->get_param( 'model' ),
			'post_id' => $post_id,
			'kept'    => $kept,
		) );
		$designed = ( '' !== trim( $markup ) );
	}

	// Fallback — no API key, a model error, or an unusable answer. Still a
	// complete page with every image, link, phone number, FAQ and video in
	// place; just laid out as the source had it rather than designed. Better
	// than handing back nothing.
	if ( ! $designed ) {
		$converted = ekwa_mc_convert_html( $prepared['html'] );
		$markup    = $converted['markup'];

		foreach ( (array) $converted['report'] as $entry ) {
			if ( is_array( $entry ) ) {
				$warnings[] = array(
					'category' => isset( $entry['category'] ) ? $entry['category'] : 'general',
					'message'  => isset( $entry['message'] ) ? $entry['message'] : '',
				);
			} elseif ( is_string( $entry ) && '' !== $entry ) {
				$warnings[] = array( 'category' => 'general', 'message' => $entry );
			}
		}
	}

	return rest_ensure_response( array(
		'markup'   => $markup,
		'designed' => $designed,
		'preview'  => function_exists( 'ekwa_ai_generate_blocks_render_preview' )
			? ekwa_ai_generate_blocks_render_preview( $markup )
			: '',
		'stats'    => $prepared['stats'],
		'warnings' => array_values( array_filter( $warnings, static function ( $w ) {
			return '' !== trim( (string) $w['message'] );
		} ) ),
	) );
}

/**
 * Record that a page's imported content has been turned into blocks.
 *
 * The stored HTML is deliberately KEPT — clearing it would make the conversion
 * a one-shot, and the whole point is that an author can come back and redo it.
 * This marker only drives the "you have already done this once" hint in the UI.
 *
 * @param int $post_id
 * @return void
 */
function ekwa_import_mark_applied( $post_id ) {
	update_post_meta( (int) $post_id, EKWA_IMPORT_META_APPLIED, current_time( 'mysql' ) );
}

add_action( 'rest_api_init', 'ekwa_import_register_applied_route' );

/**
 * Register the "mark as applied" route, called after the author inserts.
 */
function ekwa_import_register_applied_route() {
	register_rest_route( 'ekwa/v1', '/import-applied', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => static function ( $request ) {
			$post_id = (int) $request->get_param( 'post_id' );
			$allowed = ekwa_import_can_edit( $post_id );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
			ekwa_import_mark_applied( $post_id );
			return rest_ensure_response( array( 'ok' => true ) );
		},
		'permission_callback' => 'ekwa_ai_rest_permission',
		'args'                => array(
			'post_id' => array( 'required' => true, 'type' => 'integer' ),
		),
	) );
}
