<?php
/**
 * Mockup HTML to WordPress Block Markup Converter — Shared Library.
 *
 * Extracted from tools/mockup-converter.php so both the CLI tool and the
 * REST API (Gutenberg editor plugin) share the same conversion engine.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

// Load dynamic data detection functions.
require_once __DIR__ . '/ekwa-converter-detect.php';

// ═══════════════════════════════════════════════════════════════════════════════
// CONTEXT (replaces globals)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Get or set the converter context (media map, manifest, warnings).
 *
 * @param array|null $ctx  Pass an array to merge into the current context,
 *                         or null to just read the current value.
 * @return array
 */
function ekwa_mc_context( $ctx = null ) {
	static $context = null;
	if ( $context === null ) {
		$context = array(
			'media_by_name'  => array(),
			'manifest'       => null,
			'warnings'       => array(),
			'report'         => array(),
			'consumed'       => array(),
			'detect_dynamic' => true,
		);
	}
	if ( $ctx !== null ) {
		$context = array_merge( $context, $ctx );
	}
	return $context;
}

/**
 * Append a warning to the converter context.
 *
 * Every warning also lands in the structured loss report. When no explicit
 * category is passed, one is inferred from the message so the existing
 * detector call sites stay untouched.
 *
 * Report categories:
 *   dynamic   – dynamic-data detections (settings-driven blocks emitted)
 *   media     – unresolved images/videos (placeholder used)
 *   converted – element rescued into a proper block (table, svg, faq…)
 *   raw-html  – fell back to a core/html blob (fidelity kept, not editable)
 *   dropped   – content that could not be preserved
 *   general   – everything else
 *
 * @param string $message
 * @param string $category Optional explicit report category.
 */
function ekwa_mc_warn( $message, $category = '' ) {
	if ( '' === $category ) {
		if ( preg_match( '/^(Auto-detected|data-ekwa)/', $message ) ) {
			$category = 'dynamic';
		} elseif ( stripos( $message, 'manifest' ) !== false || stripos( $message, 'media' ) !== false ) {
			$category = 'media';
		} else {
			$category = 'general';
		}
	}
	$ctx = ekwa_mc_context();
	$ctx['warnings'][] = $message;
	$ctx['report'][]   = array(
		'category' => $category,
		'message'  => $message,
	);
	ekwa_mc_context( $ctx );
}

/**
 * Reduce a full HTML document to its body content. Section pastes (no
 * doctype/html/head/body tags) are returned unchanged.
 *
 * @param string $html
 * @return string
 */
function ekwa_mc_extract_body( $html ) {
	if ( preg_match( '/<body[^>]*>(.*)<\/body>/is', $html, $m ) ) {
		return $m[1];
	}
	if ( ! preg_match( '/<!DOCTYPE|<html[\s>]|<head[\s>]/i', $html ) ) {
		return $html;
	}
	$html = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $html );
	$html = preg_replace( '/<head[^>]*>.*?<\/head>/is', '', $html );
	$html = preg_replace( '/<\/?(?:html|body)[^>]*>/i', '', $html );
	return $html;
}

/**
 * Demote a single outer `<header>`/`<footer>`/`<main>` to a `<div>`.
 *
 * A converted header is pasted INTO the header template part, and that part
 * already renders the landmark itself — `parts/header.html` is a group with
 * `tagName:"header"`, so the result was
 * `<header class="ekwa-desktop-header"><header class="main-header">…`. Nested
 * `<header>` is invalid (a header may not descend from another header), it
 * gives screen readers two banner landmarks, and it stacks two positioned
 * wrappers on top of each other.
 *
 * Only the OUTERMOST element is touched, and only when it is the sole root —
 * every class, id and attribute is kept, so CSS written as `.main-header`
 * still matches. A selector written as `header.main-header` would not; that's
 * the trade, and it's reported.
 *
 * @param string $html Section HTML.
 * @return array{html:string,demoted:string} demoted is '' when nothing changed.
 */
function ekwa_mc_demote_root_landmark( $html ) {
	$html      = (string) $html;
	$landmarks = array( 'header', 'footer', 'main' );

	// Confirm via the DOM that the landmark really is the only root element —
	// a regex alone can't tell a wrapper from the first of several siblings.
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML(
		'<?xml encoding="utf-8"?><div data-ekwa-mc-root="1">' . ekwa_mc_extract_body( $html ) . '</div>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
	libxml_clear_errors();

	$root = $doc->documentElement;
	if ( ! $root ) {
		return array( 'html' => $html, 'demoted' => '' );
	}

	$elements = array();
	foreach ( $root->childNodes as $child ) {
		if ( XML_ELEMENT_NODE === $child->nodeType ) {
			$elements[] = $child;
		}
	}
	if ( 1 !== count( $elements ) ) {
		return array( 'html' => $html, 'demoted' => '' );
	}

	$tag = strtolower( $elements[0]->nodeName );
	if ( ! in_array( $tag, $landmarks, true ) ) {
		return array( 'html' => $html, 'demoted' => '' );
	}

	// Swap the outermost open/close tags only, leaving everything between them
	// byte-for-byte intact.
	$open  = preg_replace( '/^(\s*)<' . $tag . '(\s[^>]*)?>/i', '$1<div$2>', $html, 1, $open_count );
	if ( ! $open_count ) {
		return array( 'html' => $html, 'demoted' => '' );
	}
	$closed = preg_replace( '/<\/' . $tag . '\s*>(\s*)$/i', '</div>$1', $open, 1, $close_count );
	if ( ! $close_count ) {
		return array( 'html' => $html, 'demoted' => '' );
	}

	return array( 'html' => $closed, 'demoted' => $tag );
}

// ═══════════════════════════════════════════════════════════════════════════════
// MAIN ENTRY POINT
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Convert an HTML string to WordPress block markup.
 *
 * @param string     $html          The HTML markup to convert.
 * @param array|null $manifest_data Manifest array with 'upload_url' and 'media' keys.
 * @return array {
 *     @type string   $markup   The converted block markup.
 *     @type string[] $warnings Any warnings generated during conversion.
 * }
 */
function ekwa_mc_convert_html( $html, $manifest_data = null, $options = array() ) {
	// Full-document input (a pasted index.html) would be mangled by the
	// synthetic <div> root below — a DOCTYPE/<html>/<head> inside a div makes
	// libxml restructure the tree (content after the header can silently
	// vanish) and head tags degrade to raw-HTML blobs. Reduce to body content
	// first; plain section pastes pass through unchanged.
	$html = ekwa_mc_extract_body( $html );

	// Reset context.
	$media_by_name  = array();
	$detect_dynamic = isset( $options['detect_dynamic'] ) ? (bool) $options['detect_dynamic'] : true;

	if ( $manifest_data && ! empty( $manifest_data['media'] ) ) {
		foreach ( $manifest_data['media'] as $item ) {
			$fname = strtolower( $item['filename'] );
			$media_by_name[ $fname ] = $item;
		}
	}

	ekwa_mc_context( array(
		'media_by_name'  => $media_by_name,
		'manifest'       => $manifest_data,
		'warnings'       => array(),
		'report'         => array(),
		'consumed'       => array(),
		'detect_dynamic' => $detect_dynamic,
	) );

	// Parse HTML. Wrap the input in a synthetic root so multiple top-level
	// siblings (e.g. two consecutive <section> elements) are all preserved —
	// otherwise LIBXML_HTML_NOIMPLIED leaves us with documentElement pointing
	// at only the first root, silently dropping the rest.
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$wrapped = '<?xml encoding="utf-8"?><div data-ekwa-mc-root="1">' . $html . '</div>';
	$doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	$root   = $doc->documentElement;
	$output = '';
	if ( $root && $root->hasAttribute( 'data-ekwa-mc-root' ) ) {
		$output = ekwa_mc_convert_children( $root, 0 );
	} else {
		// Defensive fallback if the wrapper didn't survive parsing.
		$body    = $doc->getElementsByTagName( 'body' )->item( 0 );
		$html_el = $doc->getElementsByTagName( 'html' )->item( 0 );
		if ( $body ) {
			$output = ekwa_mc_convert_children( $body, 0 );
		} elseif ( $html_el ) {
			$output = ekwa_mc_convert_children( $html_el, 0 );
		} elseif ( $root ) {
			$output = ekwa_mc_convert_node( $root, 0 );
		}
	}

	$ctx = ekwa_mc_context();

	return array(
		'markup'   => $output,
		'warnings' => $ctx['warnings'],
		'report'   => $ctx['report'],
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// NODE TRAVERSAL
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Convert all child nodes of a parent element.
 */
function ekwa_mc_convert_children( $parent, $depth ) {
	$output = '';
	foreach ( $parent->childNodes as $node ) {
		$output .= ekwa_mc_convert_node( $node, $depth );
	}
	return $output;
}

/**
 * Convert a single DOM node to WordPress block markup.
 */
function ekwa_mc_convert_node( $node, $depth ) {
	// Skip comments.
	if ( $node->nodeType === XML_COMMENT_NODE ) {
		return '';
	}

	// Text nodes. Bare significant text between elements used to be silently
	// dropped — emit an ekwa/text span instead (layout-neutral: no paragraph
	// margins, so mockup fidelity is preserved).
	if ( $node->nodeType === XML_TEXT_NODE ) {
		$text = trim( $node->textContent );
		if ( $text === '' ) {
			return '';
		}
		$snippet = strlen( $text ) > 60 ? substr( $text, 0, 57 ) . '…' : $text;
		ekwa_mc_warn( 'Bare text rescued into ekwa/text: "' . $snippet . '"', 'converted' );
		return str_repeat( '  ', $depth )
			. '<!-- wp:ekwa/text ' . ekwa_mc_json_encode_block_attrs( array( 'text' => $text ) ) . ' /-->' . "\n";
	}

	if ( $node->nodeType !== XML_ELEMENT_NODE ) {
		return '';
	}

	// Nodes already consumed by a sibling-run converter (e.g. a <details>
	// accordion leader) emit nothing — checked BEFORE detection so their
	// content can't double-emit as a dynamic block.
	$ctx_consumed = ekwa_mc_context();
	if ( ! empty( $ctx_consumed['consumed'][ spl_object_hash( $node ) ] ) ) {
		return '';
	}

	$tag    = strtolower( $node->nodeName );
	$indent = str_repeat( '  ', $depth );

	// Dynamic data detection (phone, email, maps, social, hours, copyright).
	$ctx = ekwa_mc_context();
	if ( ! empty( $ctx['detect_dynamic'] ) ) {
		$detected = ekwa_mc_detect_dynamic( $node, $depth );
		if ( $detected !== null ) {
			return $detected;
		}
	}

	// Semantic wrapper tags → ekwa/div with tagName.
	// (figcaption uses the same treatment so simple text captions work; <figure>
	// itself gets its own dedicated block below.)
	$semantic_tags = array( 'section', 'header', 'footer', 'main', 'aside', 'article', 'nav', 'figcaption' );
	if ( in_array( $tag, $semantic_tags, true ) ) {
		return ekwa_mc_convert_div_block( $node, $depth, $tag );
	}

	// <figure> → ekwa/figure (inner <img> becomes ekwa/image, <figcaption>
	// becomes ekwa/div tagName=figcaption via the semantic-tags path above).
	if ( $tag === 'figure' ) {
		return ekwa_mc_convert_figure_block( $node, $depth );
	}

	// Headings → core/heading.
	if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
		return ekwa_mc_convert_heading( $node, $depth, (int) $m[1] );
	}

	// Paragraph → core/paragraph, EXCEPT when it wraps media.
	//
	// core/paragraph stores its content as rich text, and the converter writes
	// that inner HTML through verbatim — fine for <strong>/<em>/<a>/<br>, fatal
	// for an <img>: its src is never looked up in the media library, so a
	// mockup's `assets/images/team.jpg` ships to production as a dead relative
	// path, with no WebP, no srcset, no alt from the attachment, and no way to
	// swap the picture in the editor. `<p><img></p>` is how most editors and
	// mockups wrap a standalone image, so this is a common shape, not an edge
	// case. Route those through ekwa/div tagName=p instead: the wrapper (and its
	// classes) survives, and each image becomes a real ekwa/image block.
	if ( $tag === 'p' ) {
		if ( ekwa_mc_has_media_descendant( $node ) ) {
			return ekwa_mc_convert_div_block( $node, $depth, 'p' );
		}
		return ekwa_mc_convert_paragraph( $node, $depth );
	}

	// Image → ekwa/image.
	if ( $tag === 'img' ) {
		return ekwa_mc_convert_image( $node, $depth );
	}

	// Lists → core/list.
	if ( $tag === 'ul' || $tag === 'ol' ) {
		return ekwa_mc_convert_list( $node, $depth );
	}

	// Separator.
	if ( $tag === 'hr' ) {
		return $indent . "<!-- wp:separator -->\n" .
		       $indent . '<hr class="wp-block-separator has-alpha-channel-opacity"/>' . "\n" .
		       $indent . "<!-- /wp:separator -->\n";
	}

	// Anchor — if only FA icons as element children (text + decorative icon),
	// use ekwa/div tagName=a to preserve the full inner HTML structure.
	// If it has real element children (img, div, etc.), use anchor wrapper.
	// If text-only, use ekwa/link.
	if ( $tag === 'a' ) {
		// A lightbox trigger always goes through the wrapper: ekwa/link has no
		// lightbox attribute, so a text-only "View gallery" trigger routed there
		// would quietly lose the behaviour the mockup was marking up.
		if ( ekwa_mc_has_element_children( $node ) || ekwa_mc_lightbox_attrs( $node ) ) {
			return ekwa_mc_convert_anchor_wrapper( $node, $depth );
		}
		return ekwa_mc_convert_link( $node, $depth );
	}

	// Button — always through the wrapper converter, which keeps it a <button>.
	// (Routing text-only buttons to ekwa/link turned a dropdown/search trigger
	// into an <a>, silently changing its semantics and default styling.)
	if ( $tag === 'button' ) {
		return ekwa_mc_convert_anchor_wrapper( $node, $depth );
	}

	// Font Awesome icon → ekwa/icon.
	if ( $tag === 'i' && ekwa_mc_has_fa_class( $node ) ) {
		return ekwa_mc_convert_icon( $node, $depth );
	}

	// Inline elements:
	//   text-only                      → ekwa/text
	//   element-only kids              → ekwa/div with tagName=<inline tag>
	//   mixed text+element (splittable)→ ekwa/div, children split into icon/text blocks
	//   mixed but not splittable       → wp:html fallback
	// Presentational b/i/u sit alongside their semantic cousins (strong, em) —
	// without them a plain <b>/<u> fell through to the raw-HTML fallback. Font
	// Awesome <i> is already intercepted above, so only text-carrying <i> lands here.
	$inline_tags = array( 'span', 'small', 'strong', 'b', 'em', 'i', 'u', 'mark', 'time', 'label', 'sup', 'sub' );
	if ( in_array( $tag, $inline_tags, true ) ) {
		$has_elements = ekwa_mc_has_element_children( $node );
		if ( ! $has_elements ) {
			$text_content = trim( $node->textContent );
			if ( $text_content !== '' ) {
				return ekwa_mc_convert_text( $node, $depth, $tag );
			}
			// Empty inline element (no text, no element children). Routing it to
			// the raw-HTML fallback produced a needless "<b> has no block mapping"
			// warning and a non-editable blob. Instead: if it carries attributes
			// it's almost always a CSS-decorated marker (an icon drawn via
			// ::before, a divider, a screen-reader label) — keep it as a
			// block-editable ekwa/div with its tagName + class/style intact so the
			// styling hook survives. A bare, attribute-less <b></b> is pure noise —
			// drop it silently.
			if ( $node->hasAttributes() ) {
				return ekwa_mc_convert_div_block( $node, $depth, $tag );
			}
			return '';
		}
		return ekwa_mc_convert_div_block( $node, $depth, $tag );
	}

	// <div> → ekwa/div. Flex/grid/max-width divs used to become the dedicated
	// ekwa/flex, ekwa/grid, ekwa/container blocks; those are deprecated (hidden
	// from the inserter, kept registered only for legacy content), and the div
	// converter preserves the full inline style — including display:flex/grid
	// and max-width — into inlineStyle, so layout fidelity is identical.
	if ( $tag === 'div' ) {
		return ekwa_mc_convert_div_block( $node, $depth, 'div' );
	}

	// Video element → ekwa/video.
	if ( $tag === 'video' ) {
		return ekwa_mc_convert_video( $node, $depth );
	}

	// Blockquote → core/quote.
	if ( $tag === 'blockquote' ) {
		return ekwa_mc_convert_quote( $node, $depth );
	}

	// Table → core/table (editable cells instead of a raw HTML blob).
	if ( $tag === 'table' ) {
		return ekwa_mc_convert_table( $node, $depth );
	}

	// <details> runs → ekwa/faq accordion (consecutive siblings grouped).
	if ( $tag === 'details' ) {
		return ekwa_mc_convert_details_run( $node, $depth );
	}

	// <picture> → ekwa/image from its <img> fallback (the theme regenerates
	// WebP and srcset at render time, so <source> variants are redundant).
	if ( $tag === 'picture' ) {
		return ekwa_mc_convert_picture( $node, $depth );
	}

	// <audio> → core/audio.
	if ( $tag === 'audio' ) {
		return ekwa_mc_convert_audio( $node, $depth );
	}

	// Inline <svg> → ekwa/svg (sanitized on render).
	if ( $tag === 'svg' ) {
		return ekwa_mc_convert_svg_block( $node, $depth );
	}

	// Any other element — render as core/html.
	return ekwa_mc_convert_raw_html( $node, $depth );
}

// ═══════════════════════════════════════════════════════════════════════════════
// BLOCK CONVERTERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Convert to ekwa/div block.
 */
function ekwa_mc_convert_div_block( $node, $depth, $tag_name ) {
	$indent       = str_repeat( '  ', $depth );
	$class        = $node->getAttribute( 'class' );
	$inline_style = $node->getAttribute( 'style' );
	$attrs        = array();

	if ( $tag_name !== 'div' ) {
		$attrs['tagName'] = $tag_name;
	}
	if ( $class ) {
		$attrs['className'] = $class;
	}
	// The element id becomes the block's anchor. Dropping it broke anything the
	// mockup addressed by id — `#header` scroll handlers, skip links, `#id` CSS.
	$id = trim( $node->getAttribute( 'id' ) );
	if ( '' !== $id ) {
		$attrs['anchor'] = $id;
	}
	if ( $inline_style ) {
		// Extract background-image into a dedicated attribute.
		$bg_result = ekwa_mc_extract_background_image( $inline_style );
		if ( $bg_result['url'] ) {
			$attrs['backgroundImage'] = $bg_result['url'];
			if ( $bg_result['mediaId'] ) {
				$attrs['backgroundImageId'] = $bg_result['mediaId'];
			}
		}
		// Any remaining styles go into inlineStyle.
		$remaining = $bg_result['remaining'];
		if ( $remaining ) {
			$attrs['inlineStyle'] = $remaining;
		}
	}

	// Forward data-*/aria-* and friends into customAttributes.
	$custom = ekwa_mc_extract_custom_attributes( $node );
	if ( ! empty( $custom ) ) {
		$attrs['customAttributes'] = $custom;
	}

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	// Mixed content (text + element children): convert each child on its own —
	// bare text becomes ekwa/text, elements dispatch to their converters
	// (tables, details, svg, picture included). A whole-wrapper wp:html blob
	// is never emitted here anymore; unconvertible children fall back to
	// wp:html individually, which the loss report calls out per element.
	if ( ekwa_mc_has_mixed_content( $node ) ) {
		$children = ekwa_mc_convert_inline_mixed_children( $node, $depth + 1 );
		return $indent . '<!-- wp:ekwa/div' . $attrs_json . ' -->' . "\n" .
		       $children .
		       $indent . '<!-- /wp:ekwa/div -->' . "\n";
	}

	// Text-only with no element children → keep the wrapper, text becomes an
	// ekwa/text child (layout-neutral span; the wrapper keeps its classes).
	if ( ekwa_mc_has_text_only( $node ) ) {
		$text       = trim( $node->textContent );
		$text_attrs = array( 'text' => $text );
		return $indent . '<!-- wp:ekwa/div' . $attrs_json . ' -->' . "\n" .
		       $indent . '  <!-- wp:ekwa/text ' . ekwa_mc_json_encode_block_attrs( $text_attrs ) . ' /-->' . "\n" .
		       $indent . '<!-- /wp:ekwa/div -->' . "\n";
	}

	$children = ekwa_mc_convert_children( $node, $depth + 1 );

	return $indent . '<!-- wp:ekwa/div' . $attrs_json . ' -->' . "\n" .
	       $children .
	       $indent . '<!-- /wp:ekwa/div -->' . "\n";
}

/**
 * Convert to core/heading.
 */
function ekwa_mc_convert_heading( $node, $depth, $level ) {
	$indent    = str_repeat( '  ', $depth );
	$class     = $node->getAttribute( 'class' );
	$inner     = ekwa_mc_get_inner_html( $node );
	$attrs     = array( 'level' => $level );

	if ( $class ) { $attrs['className'] = $class; }

	$attrs += ekwa_mc_anchor_attr( $node );
	// Core blocks save static markup, so the style CANNOT go on the tag — an
	// attribute the block's save() doesn't regenerate fails block validation and
	// greets the editor with "unexpected or invalid content". It rides in the
	// block comment instead and ekwa_core_inline_style_render() puts it back on
	// the element at render time.
	$attrs += ekwa_mc_style_attr( $node );

	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$class_attr = 'wp-block-heading';
	if ( $class ) { $class_attr .= ' ' . $class; }

	return $indent . '<!-- wp:heading' . $attrs_json . ' -->' . "\n" .
	       $indent . '<h' . $level . ' class="' . $class_attr . '"' . ekwa_mc_anchor_html_attr( $node ) . '>' . trim( $inner ) . '</h' . $level . '>' . "\n" .
	       $indent . '<!-- /wp:heading -->' . "\n";
}

/**
 * Convert to core/paragraph.
 */
function ekwa_mc_convert_paragraph( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$class  = $node->getAttribute( 'class' );
	$inner  = ekwa_mc_get_inner_html( $node );
	$attrs  = array();

	if ( $class ) { $attrs['className'] = $class; }

	$attrs += ekwa_mc_anchor_attr( $node );
	$attrs += ekwa_mc_style_attr( $node );

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$class_attr = $class ? ' class="' . $class . '"' : '';

	return $indent . '<!-- wp:paragraph' . $attrs_json . ' -->' . "\n" .
	       $indent . '<p' . $class_attr . ekwa_mc_anchor_html_attr( $node ) . '>' . trim( $inner ) . '</p>' . "\n" .
	       $indent . '<!-- /wp:paragraph -->' . "\n";
}

/**
 * Convert <blockquote> to core/quote, preserving any class and lifting
 * <cite> children to the citation slot.
 */
function ekwa_mc_convert_quote( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$class  = trim( $node->getAttribute( 'class' ) );

	// Walk children: separate <cite> from the rest, convert <p> children
	// through the paragraph converter, and fold any loose text or other
	// inline content into a single leading paragraph block.
	$paragraph_blocks = '';
	$cite_html        = '';
	$loose_parts      = array();

	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_TEXT_NODE ) {
			$t = trim( $child->textContent );
			if ( $t !== '' ) {
				$loose_parts[] = $t;
			}
			continue;
		}
		if ( $child->nodeType !== XML_ELEMENT_NODE ) {
			continue;
		}

		$child_tag = strtolower( $child->nodeName );
		if ( $child_tag === 'cite' ) {
			$cite_html = '<cite>' . trim( ekwa_mc_get_inner_html( $child ) ) . '</cite>';
			continue;
		}
		if ( $child_tag === 'p' ) {
			$paragraph_blocks .= ekwa_mc_convert_paragraph( $child, $depth + 1 );
			continue;
		}
		// Other inline content (br, em, strong, …) — fold into loose text.
		$loose_parts[] = trim( ekwa_mc_get_outer_html( $child ) );
	}

	if ( $loose_parts ) {
		$text         = trim( implode( ' ', $loose_parts ) );
		$inner_indent = str_repeat( '  ', $depth + 1 );
		$paragraph_blocks =
			$inner_indent . '<!-- wp:paragraph -->' . "\n" .
			$inner_indent . '<p>' . $text . '</p>' . "\n" .
			$inner_indent . '<!-- /wp:paragraph -->' . "\n" .
			$paragraph_blocks;
	}

	// Block attributes — only emit className when there's a source class.
	$attrs      = array();
	$class_attr = 'wp-block-quote';
	if ( $class !== '' ) {
		$attrs['className'] = $class;
		$class_attr        .= ' ' . $class;
	}
	$attrs += ekwa_mc_anchor_attr( $node );
	$attrs += ekwa_mc_style_attr( $node );

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$inner = $paragraph_blocks;
	if ( $cite_html !== '' ) {
		$inner .= str_repeat( '  ', $depth + 1 ) . $cite_html . "\n";
	}

	return $indent . '<!-- wp:quote' . $attrs_json . ' -->' . "\n" .
	       $indent . '<blockquote class="' . $class_attr . '"' . ekwa_mc_anchor_html_attr( $node ) . '>' . "\n" .
	       $inner .
	       $indent . '</blockquote>' . "\n" .
	       $indent . '<!-- /wp:quote -->' . "\n";
}

/**
 * Convert to ekwa/image.
 */
function ekwa_mc_convert_image( $node, $depth ) {
	$ctx = ekwa_mc_context();
	$media_by_name = $ctx['media_by_name'];
	$manifest      = $ctx['manifest'];

	$indent = str_repeat( '  ', $depth );
	$src    = $node->getAttribute( 'src' );
	$alt    = $node->getAttribute( 'alt' );
	$width  = $node->getAttribute( 'width' );
	$height = $node->getAttribute( 'height' );
	$class  = $node->getAttribute( 'class' );
	$load   = $node->getAttribute( 'loading' );
	$style  = ekwa_mc_parse_inline_style( $node->getAttribute( 'style' ) );

	$attrs = array();

	// Resolve via manifest.
	$filename = strtolower( basename( $src ) );

	if ( ! empty( $media_by_name[ $filename ] ) ) {
		$media_item = $media_by_name[ $filename ];
		$attrs['src']     = $media_item['url'];
		$attrs['mediaId'] = $media_item['id'];
		if ( ! $alt && ! empty( $media_item['alt'] ) )       { $alt    = $media_item['alt']; }
		if ( ! $width && ! empty( $media_item['width'] ) )   { $width  = (string) $media_item['width']; }
		if ( ! $height && ! empty( $media_item['height'] ) ) { $height = (string) $media_item['height']; }
	} elseif ( $src && ( $lib = ekwa_mc_find_attachment_by_basename( $filename ) ) ) {
		// Manifest miss — fall back to a basename match in the WP library.
		$attrs['src']     = $lib['url'];
		$attrs['mediaId'] = $lib['id'];
		if ( ! $alt && $lib['alt'] )       { $alt    = $lib['alt']; }
		if ( ! $width && $lib['width'] )   { $width  = (string) $lib['width']; }
		if ( ! $height && $lib['height'] ) { $height = (string) $lib['height']; }
	} else {
		if ( $src ) {
			$upload_url = $manifest['upload_url'] ?? '';
			if ( $upload_url ) {
				$attrs['src'] = rtrim( $upload_url, '/' ) . '/placeholder.svg';
			} else {
				$attrs['src'] = $src;
			}
			ekwa_mc_warn( "No manifest match for '$filename' (src: $src)" );
		}
	}

	// A bare <img data-lightbox="…"> (no wrapping link) becomes a lightbox on
	// the image block itself. Skipped when an ancestor <a> already carries the
	// trigger — that link is the trigger, and doubling up would nest anchors.
	$lightbox = ekwa_mc_inside_lightbox_link( $node ) ? array() : ekwa_mc_lightbox_attrs( $node );

	// Drop the JS-binding classes either way: when this image is the trigger the
	// renderer re-adds the canonical one, and when the wrapping <a> is the
	// trigger an inherited `.glightbox` here would become a duplicate entry.
	$class = ekwa_mc_strip_lightbox_classes( $class );

	if ( ! isset( $attrs['src'] ) && $src ) { $attrs['src'] = $src; }
	if ( $alt )    { $attrs['alt']    = $alt; }
	if ( $width )  { $attrs['width']  = $width; }
	if ( $height ) { $attrs['height'] = $height; }
	if ( $load )   { $attrs['loading'] = $load; }
	if ( $class )  { $attrs['className'] = $class; }
	if ( isset( $style['object-fit'] ) ) { $attrs['objectFit'] = $style['object-fit']; }
	$attrs += $lightbox;

	$attrs += ekwa_mc_anchor_attr( $node );
	$attrs += ekwa_mc_style_attr( $node, array( 'object-fit' ) );

	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return $indent . '<!-- wp:ekwa/image' . $attrs_json . ' /-->' . "\n";
}

/**
 * Convert a <figure> element into the ekwa/figure block, recursively
 * converting children (so nested <img> becomes ekwa/image and <figcaption>
 * becomes ekwa/div tagName=figcaption).
 */
function ekwa_mc_convert_figure_block( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$class  = $node->getAttribute( 'class' );
	$attrs  = array();

	if ( $class ) {
		$attrs['className'] = $class;
	}
	$attrs += ekwa_mc_anchor_attr( $node );
	$attrs += ekwa_mc_style_attr( $node );

	$custom = ekwa_mc_extract_custom_attributes( $node );
	if ( ! empty( $custom ) ) {
		$attrs['customAttributes'] = $custom;
	}

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$children = ekwa_mc_convert_children( $node, $depth + 1 );

	return $indent . '<!-- wp:ekwa/figure' . $attrs_json . ' -->' . "\n" .
	       $children .
	       $indent . '<!-- /wp:ekwa/figure -->' . "\n";
}

/**
 * Convert to ekwa/link.
 */
function ekwa_mc_convert_link( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$url    = $node->getAttribute( 'href' ) ?: '';
	$class  = $node->getAttribute( 'class' );
	$target = $node->getAttribute( 'target' );
	$rel    = $node->getAttribute( 'rel' );
	$text   = trim( $node->textContent );

	$attrs = array();
	// ekwa/link has no href attribute — drop the converter's fallback key so the
	// block's own `url` (and, for a file, linkType/mediaFileId) is the only source.
	if ( $url ) {
		$link_attrs = ekwa_mc_link_attrs( $url );
		unset( $link_attrs['href'] );
		$attrs += $link_attrs;
	}
	if ( $text )  { $attrs['text'] = $text; }
	if ( $class ) { $attrs['className'] = $class; }
	if ( $target === '_blank' ) { $attrs['newTab'] = true; }
	if ( $rel )   { $attrs['rel'] = $rel; }

	$attrs += ekwa_mc_anchor_attr( $node );
	$attrs += ekwa_mc_style_attr( $node );

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return $indent . '<!-- wp:ekwa/link' . $attrs_json . ' /-->' . "\n";
}

/**
 * Convert to ekwa/icon.
 */
function ekwa_mc_convert_icon( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$class  = $node->getAttribute( 'class' );

	// The theme ships Font Awesome and nothing else, so any other icon font is
	// translated here. Material Icons name the glyph in the element's TEXT
	// rather than its class, and that text is dropped by this block — so the
	// conversion has to happen while we still have the node.
	if ( function_exists( 'ekwa_mc_icon_class_to_fontawesome' ) ) {
		$mapped = ekwa_mc_icon_class_to_fontawesome( $class, $node->textContent );
		if ( $mapped['changed'] ) {
			ekwa_mc_warn( 'Icon converted to Font Awesome: "' . $class . '" → "' . $mapped['class'] . '"', 'converted' );
			$class = $mapped['class'];
		}
		foreach ( $mapped['unmapped'] as $unknown ) {
			ekwa_mc_warn( 'Icon "' . $unknown . '" has no Font Awesome equivalent — it will not render. Replace it with a fa-solid/fa-brands class.', 'dynamic' );
		}
	}

	$attrs = array( 'iconClass' => $class, 'wrapperClass' => '' );

	$attrs += ekwa_mc_anchor_attr( $node );
	// font-size/color already have dedicated controls (size/color); anything the
	// mockup set beyond them rides along as inline style.
	$attrs += ekwa_mc_style_attr( $node );

	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return $indent . '<!-- wp:ekwa/icon' . $attrs_json . ' /-->' . "\n";
}

/**
 * Convert to ekwa/text.
 */
function ekwa_mc_convert_text( $node, $depth, $tag ) {
	$indent = str_repeat( '  ', $depth );
	$text   = trim( $node->textContent );
	$class  = $node->getAttribute( 'class' );
	$attrs  = array( 'tagName' => $tag, 'text' => $text );

	if ( $class ) { $attrs['className'] = $class; }

	$attrs += ekwa_mc_anchor_attr( $node );
	$attrs += ekwa_mc_style_attr( $node );

	$custom = ekwa_mc_extract_custom_attributes( $node );
	if ( ! empty( $custom ) ) {
		$attrs['customAttributes'] = $custom;
	}

	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return $indent . '<!-- wp:ekwa/text' . $attrs_json . ' /-->' . "\n";
}

/**
 * The element's id as a block `anchor` attribute (empty array when it has none).
 *
 * Every block the converter emits supports `anchor` and renders it back as
 * `id`, so an id in the mockup should always survive. Mockups hang real
 * behaviour off ids — `getElementById('hero-video')` for a mute toggle, a
 * sticky-header scroll handler on `#header`, in-page anchor links, `#id` CSS —
 * and losing one fails silently.
 *
 * @param DOMElement $node
 * @return array{anchor?:string}
 */
function ekwa_mc_anchor_attr( $node ) {
	$id = trim( $node->getAttribute( 'id' ) );
	return ( '' === $id ) ? array() : array( 'anchor' => $id );
}

/**
 * The same id as a ready-to-print ` id="…"` HTML attribute.
 *
 * Static core blocks (heading, paragraph, list, quote, table, audio) render
 * their SAVED markup, so the id has to be written onto the element itself —
 * the block attribute alone would be ignored on the front end.
 *
 * @param DOMElement $node
 * @return string
 */
function ekwa_mc_anchor_html_attr( $node ) {
	$id = trim( $node->getAttribute( 'id' ) );
	return ( '' === $id ) ? '' : ' id="' . esc_attr( $id ) . '"';
}

/**
 * The element's inline `style` as a block `inlineStyle` attribute (empty array
 * when it has none).
 *
 * Mockups hang real design on inline styles — `border-radius` on a CTA, a
 * per-card `background`, `max-width` on a column — and every converter except
 * ekwa/div used to drop them silently, so the converted block came out looking
 * nothing like the mockup with nothing in the loss report to explain why.
 *
 * Every block this is used on renders `inlineStyle` back as a `style`
 * attribute and exposes it in the sidebar as "Inline Style", so the
 * declarations survive the round trip and stay editable. `url()` references
 * resolve through the media manifest, the same as an ekwa/div background.
 *
 * @param DOMElement $node
 * @param string[]   $skip Properties already captured by a dedicated attribute
 *                         (object-fit on ekwa/image) — stripped here so the
 *                         declaration isn't emitted twice.
 * @return array{inlineStyle?:string}
 */
function ekwa_mc_style_attr( $node, $skip = array() ) {
	$style = trim( (string) $node->getAttribute( 'style' ) );
	if ( '' === $style ) {
		return array();
	}

	$style = ekwa_mc_resolve_style_urls( $style );

	foreach ( $skip as $prop ) {
		$style = preg_replace( '/(?:^|;)\s*' . preg_quote( $prop, '/' ) . '\s*:[^;]*/i', ';', $style );
	}

	$style = trim( preg_replace( '/;\s*;+/', ';', $style ), " \t\n\r;" );

	return ( '' === $style ) ? array() : array( 'inlineStyle' => $style );
}

/**
 * Detect a lightbox trigger in mockup markup and map it to block attributes.
 *
 * Mockups arrive marked up for whichever library the designer reached for, so
 * rather than demanding one convention this recognises the handful that are
 * actually in circulation and normalises them onto the theme's own lightbox:
 *
 *   class="glightbox|lightbox|fancybox|venobox|swipebox|magnific-popup|…"
 *   data-lightbox="group"      (Lightbox2)
 *   data-fancybox="group"      (Fancybox)
 *   data-fslightbox="group"    (fsLightbox)
 *   data-gallery="group"       (GLightbox's own)
 *   rel="lightbox[group]"      (very old Lightbox2 markup)
 *
 * The group name is what makes several thumbnails open as one swipeable
 * gallery, and every one of those conventions carries it in a different place —
 * losing it would silently turn a designed gallery into a pile of unrelated
 * single images, so each spelling is read back out here.
 *
 * @param DOMElement $node
 * @return array Block attributes to merge (`lightbox`, maybe `lightboxGroup`),
 *               or an empty array when the node is not a lightbox trigger.
 */
function ekwa_mc_lightbox_attrs( $node ) {
	if ( ! $node || ! $node->hasAttributes() ) {
		return array();
	}

	$class_attr = strtolower( $node->getAttribute( 'class' ) );
	$classes    = preg_split( '/\s+/', trim( $class_attr ), -1, PREG_SPLIT_NO_EMPTY );
	$known      = array(
		'glightbox', 'ekwa-lightbox', 'lightbox', 'lightbox-trigger', 'fancybox',
		'venobox', 'swipebox', 'magnific-popup', 'mfp-image', 'colorbox',
		'lity', 'baguettebox', 'photoswipe', 'fslightbox',
	);
	$has_class  = ! empty( array_intersect( $classes, $known ) );

	// Group-carrying attributes, in the order they should win: an explicit
	// data-gallery beats a library-specific one.
	$group = '';
	foreach ( array( 'data-gallery', 'data-fancybox', 'data-lightbox', 'data-fslightbox', 'data-lightbox-gallery' ) as $attr ) {
		if ( $node->hasAttribute( $attr ) ) {
			$has_class = true; // Presence of the attribute IS the opt-in.
			$value     = trim( $node->getAttribute( $attr ) );
			if ( '' !== $value && '' === $group ) {
				$group = $value;
			}
		}
	}

	// rel="lightbox[kitchen]" — the group sits inside the brackets.
	$rel = trim( $node->getAttribute( 'rel' ) );
	if ( '' !== $rel && preg_match( '/^lightbox(?:\[([^\]]*)\])?$/i', $rel, $m ) ) {
		$has_class = true;
		if ( '' === $group && ! empty( $m[1] ) ) {
			$group = trim( $m[1] );
		}
	}

	if ( ! $has_class ) {
		return array();
	}

	$attrs = array( 'lightbox' => true );
	if ( '' !== $group ) {
		$attrs['lightboxGroup'] = $group;
	}

	return $attrs;
}

/**
 * Resolve an href that points at a local media FILE to the media library.
 *
 * A lightbox thumbnail is `<a href="case-1-full.jpg"><img src="case-1-thumb.jpg"></a>`:
 * the href is what the overlay opens, so it is every bit as much a media
 * reference as the `<img src>` beside it — but only the src was ever resolved.
 * The href went through untouched, and `esc_url()` turned the relative
 * `assets/images/case-1-full.jpg` into `http://assets/images/case-1-full.jpg`,
 * pointing the lightbox at a host called "assets". Same story for a linked PDF
 * or MP4.
 *
 * Resolution mirrors ekwa_mc_convert_image(): manifest first, then a basename
 * match in the WP library. On a miss the caller keeps the original path and
 * this emits the same "No manifest match" warning the images use, so the
 * converter modal lists the file in its media-mapping step and offers to pick
 * or upload it — one mapping then fixes the thumbnail and the lightbox target
 * together.
 *
 * Only file-looking, same-site URLs are touched: mailto:/tel:/#anchors, page
 * URLs and links to other domains are left exactly as written.
 *
 * @param string $url Raw href.
 * @return array{url:string,id:int}|null Resolved target, or null when the href
 *                                       is not a local media file (or wasn't found).
 */
function ekwa_mc_resolve_media_link( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return null;
	}

	// Not a file reference: anchors, protocols that aren't http(s), data URIs.
	if ( preg_match( '~^(?:\#|mailto:|tel:|sms:|javascript:|data:)~i', $url ) ) {
		return null;
	}

	// Query/fragment don't belong to the filename.
	$path = preg_replace( '/[?#].*$/', '', $url );
	if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?|mp4|webm|ogv|mov|m4v|mp3|wav|m4a|pdf)$/i', $path ) ) {
		return null;
	}

	// An absolute URL to somewhere else stays somewhere else. (A URL on this
	// site still resolves — mockups exported from a staging site carry those.)
	if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) || 0 === strpos( $path, '//' ) ) {
		$host = strtolower( (string) parse_url( $path, PHP_URL_HOST ) );
		$home = function_exists( 'home_url' ) ? strtolower( (string) parse_url( home_url(), PHP_URL_HOST ) ) : '';
		if ( '' === $home || $host !== $home ) {
			return null;
		}
	}

	$ctx      = ekwa_mc_context();
	$filename = strtolower( basename( $path ) );

	if ( ! empty( $ctx['media_by_name'][ $filename ] ) ) {
		$item = $ctx['media_by_name'][ $filename ];
		return array( 'url' => $item['url'], 'id' => (int) $item['id'] );
	}

	$lib = ekwa_mc_find_attachment_by_basename( $filename );
	if ( $lib ) {
		return array( 'url' => $lib['url'], 'id' => (int) $lib['id'] );
	}

	// Same wording as the image path — the editor parses this to build the
	// "map your media" list, so an unresolved link target gets the same
	// select-or-upload treatment an unresolved <img src> gets.
	ekwa_mc_warn( "No manifest match for '$filename' (src: $url)", 'media' );

	return null;
}

/**
 * Link-source block attributes for an <a> href, resolving media files.
 *
 * The Link Source control in the editor reads `url` + `linkType` — the
 * converter's `href` attribute is only a render-time fallback, which is why a
 * converted link showed an empty URL field in the sidebar. Every anchor
 * converter now emits `url` as well, and a media file additionally gets
 * `linkType:"media"` + `mediaFileId` so the block re-resolves the attachment at
 * render time (surviving a re-upload) and the sidebar shows the file picker
 * with the right file already chosen.
 *
 * @param string $href Raw href from the mockup.
 * @return array Block attributes (empty when there is no href).
 */
function ekwa_mc_link_attrs( $href ) {
	$href = trim( (string) $href );
	if ( '' === $href ) {
		return array();
	}

	$media = ekwa_mc_resolve_media_link( $href );
	if ( $media ) {
		return array(
			'href'        => $media['url'],
			'url'         => $media['url'],
			'linkType'    => 'media',
			'mediaUrl'    => $media['url'],
			'mediaFileId' => $media['id'],
		);
	}

	return array( 'href' => $href, 'url' => $href );
}

/**
 * Remove the class names the theme's lightbox JS binds to.
 *
 * Once a trigger is expressed as the `lightbox` block attribute the renderer
 * adds `ekwa-lightbox` itself, so leaving the mockup's copy in `className`
 * risks a second, unwanted trigger: the JS selector also matches `.glightbox`,
 * and an <img> that inherited that class inside an already-converted <a> would
 * be picked up as its own gallery entry — the same photo twice in one gallery.
 * Every other class the designer wrote is left untouched; their CSS still hangs
 * off those.
 *
 * @param string $class Raw class attribute.
 * @return string Class attribute without the binding classes.
 */
function ekwa_mc_strip_lightbox_classes( $class ) {
	if ( '' === trim( (string) $class ) ) {
		return '';
	}
	$keep = array();
	foreach ( preg_split( '/\s+/', trim( $class ), -1, PREG_SPLIT_NO_EMPTY ) as $name ) {
		if ( ! in_array( strtolower( $name ), array( 'glightbox', 'ekwa-lightbox' ), true ) ) {
			$keep[] = $name;
		}
	}
	return implode( ' ', $keep );
}

/**
 * Is this node inside an <a> that is already a lightbox trigger?
 *
 * Guards against giving an inner <img> its own lightbox when its wrapping link
 * has one — that would render a link inside a link, which browsers un-nest and
 * neither trigger survives intact.
 *
 * @param DOMElement $node
 * @return bool
 */
function ekwa_mc_inside_lightbox_link( $node ) {
	for ( $p = $node->parentNode; $p && XML_ELEMENT_NODE === $p->nodeType; $p = $p->parentNode ) {
		if ( 'a' === strtolower( $p->nodeName ) && ekwa_mc_lightbox_attrs( $p ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Pull pass-through HTML attributes off a DOM node.
 *
 * Used by block converters to forward `data-*`, `aria-*`, and a small set of
 * static a11y/i18n attributes (role/title/tabindex/lang/dir) into the block's
 * `customAttributes` map so they survive the conversion. Anything outside the
 * allowlist (style/onclick/etc.) is intentionally dropped — those are handled
 * by other attributes (inlineStyle, etc.) or are unsafe to forward verbatim.
 *
 * @param DOMElement $node
 * @return array<string, string>
 */
function ekwa_mc_extract_custom_attributes( $node ) {
	$out = array();
	if ( ! $node || ! $node->hasAttributes() ) {
		return $out;
	}
	$static_allowed = array( 'role', 'title', 'tabindex', 'lang', 'dir' );
	foreach ( $node->attributes as $attr ) {
		$name = strtolower( $attr->nodeName );
		$ok   = preg_match( '/^(?:data|aria)-[a-z0-9_-]+$/', $name )
		        || in_array( $name, $static_allowed, true );
		if ( $ok ) {
			$out[ $name ] = (string) $attr->nodeValue;
		}
	}
	return $out;
}

/**
 * Convert to core/list.
 */
function ekwa_mc_convert_list( $node, $depth ) {
	$indent  = str_repeat( '  ', $depth );
	$class   = $node->getAttribute( 'class' );
	$tag     = strtolower( $node->nodeName );
	$inner   = ekwa_mc_get_inner_html( $node );
	$attrs   = array();
	$ordered = ( $tag === 'ol' );

	if ( $ordered ) { $attrs['ordered'] = true; }
	if ( $class )   { $attrs['className'] = $class; }

	$attrs += ekwa_mc_anchor_attr( $node );
	$attrs += ekwa_mc_style_attr( $node );

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$class_attr = $class ? ' class="' . $class . '"' : '';

	return $indent . '<!-- wp:list' . $attrs_json . ' -->' . "\n" .
	       $indent . '<' . $tag . $class_attr . ekwa_mc_anchor_html_attr( $node ) . '>' . trim( $inner ) . '</' . $tag . '>' . "\n" .
	       $indent . '<!-- /wp:list -->' . "\n";
}

/**
 * Convert any node to core/html (raw HTML passthrough).
 */
function ekwa_mc_convert_raw_html( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$html   = ekwa_mc_get_outer_html( $node );

	// Structured loss report: say WHAT fell back and why, so nothing is
	// silently opaque. Forms get their own category (they need a form
	// plugin/embed, not block conversion).
	$tag = strtolower( $node->nodeName );
	$form_tags = array( 'form', 'select', 'input', 'textarea', 'fieldset', 'label', 'option', 'button' );
	if ( in_array( $tag, $form_tags, true ) ) {
		ekwa_mc_warn( "<$tag> kept as raw HTML — rebuild forms with your form plugin and embed its shortcode.", 'raw-html' );
	} else {
		ekwa_mc_warn( "<$tag> has no block mapping — kept as raw HTML (renders identically but isn't block-editable).", 'raw-html' );
	}

	return $indent . '<!-- wp:html -->' . "\n" .
	       $indent . trim( $html ) . "\n" .
	       $indent . '<!-- /wp:html -->' . "\n";
}

/**
 * Convert <table> to core/table, preserving thead/tbody/tfoot structure and
 * cell contents so the table is editable in the block editor. Bare rows are
 * wrapped in <tbody> (core/table sources its cells from "tbody tr").
 */
function ekwa_mc_convert_table( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$class  = trim( $node->getAttribute( 'class' ) );

	$head_rows = array();
	$body_rows = array();
	$foot_rows = array();
	$caption   = '';

	$collect_rows = function ( $parent ) {
		$rows = array();
		foreach ( $parent->childNodes as $child ) {
			if ( $child->nodeType === XML_ELEMENT_NODE && strtolower( $child->nodeName ) === 'tr' ) {
				$rows[] = $child;
			}
		}
		return $rows;
	};

	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType !== XML_ELEMENT_NODE ) {
			continue;
		}
		$child_tag = strtolower( $child->nodeName );
		if ( 'thead' === $child_tag ) {
			$head_rows = array_merge( $head_rows, $collect_rows( $child ) );
		} elseif ( 'tfoot' === $child_tag ) {
			$foot_rows = array_merge( $foot_rows, $collect_rows( $child ) );
		} elseif ( 'tbody' === $child_tag ) {
			$body_rows = array_merge( $body_rows, $collect_rows( $child ) );
		} elseif ( 'tr' === $child_tag ) {
			$body_rows[] = $child;
		} elseif ( 'caption' === $child_tag ) {
			$caption = trim( ekwa_mc_get_inner_html( $child ) );
		}
	}

	if ( empty( $head_rows ) && empty( $body_rows ) && empty( $foot_rows ) ) {
		return ekwa_mc_convert_raw_html( $node, $depth );
	}

	// Rebuild rows with only what core/table understands: td/th tag, cell
	// inner HTML, and colspan/rowspan. Everything else is dropped.
	$render_rows = function ( $rows ) {
		$html = '';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( $row->childNodes as $cell ) {
				if ( $cell->nodeType !== XML_ELEMENT_NODE ) {
					continue;
				}
				$cell_tag = strtolower( $cell->nodeName );
				if ( 'td' !== $cell_tag && 'th' !== $cell_tag ) {
					continue;
				}
				$span = '';
				if ( $cell->getAttribute( 'colspan' ) ) {
					$span .= ' colspan="' . (int) $cell->getAttribute( 'colspan' ) . '"';
				}
				if ( $cell->getAttribute( 'rowspan' ) ) {
					$span .= ' rowspan="' . (int) $cell->getAttribute( 'rowspan' ) . '"';
				}
				$html .= '<' . $cell_tag . $span . '>' . trim( ekwa_mc_get_inner_html( $cell ) ) . '</' . $cell_tag . '>';
			}
			$html .= '</tr>';
		}
		return $html;
	};

	$table_html = '';
	if ( ! empty( $head_rows ) ) {
		$table_html .= '<thead>' . $render_rows( $head_rows ) . '</thead>';
	}
	$table_html .= '<tbody>' . $render_rows( $body_rows ) . '</tbody>';
	if ( ! empty( $foot_rows ) ) {
		$table_html .= '<tfoot>' . $render_rows( $foot_rows ) . '</tfoot>';
	}

	// hasFixedLayout must be explicit: newer WP defaults it to true and adds a
	// class in save(), which would invalidate our markup if omitted.
	$attrs = array( 'hasFixedLayout' => false );
	if ( $class ) {
		$attrs['className'] = $class;
	}
	$attrs += ekwa_mc_anchor_attr( $node );
	$attrs += ekwa_mc_style_attr( $node );

	$figure_class = 'wp-block-table' . ( $class ? ' ' . $class : '' );
	$caption_html = $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '';

	ekwa_mc_warn( 'Converted <table> to an editable core/table block.', 'converted' );

	return $indent . '<!-- wp:table ' . ekwa_mc_json_encode_block_attrs( $attrs ) . ' -->' . "\n" .
	       $indent . '<figure class="' . $figure_class . '"' . ekwa_mc_anchor_html_attr( $node ) . '><table>' . $table_html . '</table>' . $caption_html . '</figure>' . "\n" .
	       $indent . '<!-- /wp:table -->' . "\n";
}

/**
 * Convert a run of consecutive <details> siblings into ONE ekwa/faq
 * accordion (each <details> → ekwa/faq-item, <summary> → question,
 * remaining children → answer blocks). Non-leading members of the run
 * return '' — the leader consumed them.
 */
function ekwa_mc_convert_details_run( $node, $depth ) {
	$is_details = function ( $n ) {
		return $n && $n->nodeType === XML_ELEMENT_NODE && strtolower( $n->nodeName ) === 'details';
	};
	$significant = function ( $n ) {
		return ( $n->nodeType === XML_ELEMENT_NODE )
			|| ( $n->nodeType === XML_TEXT_NODE && trim( $n->textContent ) !== '' );
	};

	// Already consumed by an earlier run leader?
	$prev = $node->previousSibling;
	while ( $prev && ! $significant( $prev ) ) {
		$prev = $prev->previousSibling;
	}
	if ( $is_details( $prev ) ) {
		return '';
	}

	// Collect the run and mark the consumed siblings so convert_node skips
	// them entirely (including dynamic detection, which runs first).
	$items = array( $node );
	$next  = $node->nextSibling;
	while ( $next ) {
		if ( ! $significant( $next ) ) {
			$next = $next->nextSibling;
			continue;
		}
		if ( ! $is_details( $next ) ) {
			break;
		}
		$items[] = $next;
		$next    = $next->nextSibling;
	}

	$ctx = ekwa_mc_context();
	foreach ( array_slice( $items, 1 ) as $consumed_node ) {
		$ctx['consumed'][ spl_object_hash( $consumed_node ) ] = true;
	}
	ekwa_mc_context( $ctx );

	$indent = str_repeat( '  ', $depth );
	$out    = $indent . '<!-- wp:ekwa/faq -->' . "\n";

	foreach ( $items as $details ) {
		$question = '';
		$answer   = '';

		foreach ( $details->childNodes as $child ) {
			if ( $child->nodeType === XML_ELEMENT_NODE && strtolower( $child->nodeName ) === 'summary' ) {
				$question = trim( $child->textContent );
				continue;
			}
			if ( $child->nodeType === XML_TEXT_NODE ) {
				$text = trim( $child->textContent );
				if ( $text !== '' ) {
					$answer .= $indent . '    <!-- wp:paragraph -->' . "\n"
						. $indent . '    <p>' . $text . '</p>' . "\n"
						. $indent . '    <!-- /wp:paragraph -->' . "\n";
				}
				continue;
			}
			$answer .= ekwa_mc_convert_node( $child, $depth + 2 );
		}

		$item_attrs = array( 'question' => $question );
		if ( $details->hasAttribute( 'open' ) ) {
			$item_attrs['defaultOpen'] = true;
		}

		$out .= $indent . '  <!-- wp:ekwa/faq-item ' . ekwa_mc_json_encode_block_attrs( $item_attrs ) . ' -->' . "\n"
			. $answer
			. $indent . '  <!-- /wp:ekwa/faq-item -->' . "\n";
	}

	$out .= $indent . '<!-- /wp:ekwa/faq -->' . "\n";

	ekwa_mc_warn( 'Converted ' . count( $items ) . ' <details> element(s) into an ekwa/faq accordion.', 'converted' );

	return $out;
}

/**
 * Convert <picture> to ekwa/image using its <img> fallback. The theme
 * serves WebP and generates srcset from attachment metadata at render time,
 * so the <source> variants are redundant.
 */
function ekwa_mc_convert_picture( $node, $depth ) {
	$img = $node->getElementsByTagName( 'img' )->item( 0 );
	if ( ! $img ) {
		return ekwa_mc_convert_raw_html( $node, $depth );
	}
	ekwa_mc_warn( 'Converted <picture> to ekwa/image via its <img> fallback (WebP/srcset regenerate at render).', 'converted' );

	// The <picture> disappears, so hand its id (and class) down to the <img>
	// that replaces it — otherwise anything targeting #id/.class is orphaned.
	$picture_id = trim( $node->getAttribute( 'id' ) );
	if ( '' !== $picture_id && '' === trim( $img->getAttribute( 'id' ) ) ) {
		$img->setAttribute( 'id', $picture_id );
	}
	$picture_class = trim( $node->getAttribute( 'class' ) );
	if ( '' !== $picture_class ) {
		$img->setAttribute( 'class', trim( $img->getAttribute( 'class' ) . ' ' . $picture_class ) );
	}

	return ekwa_mc_convert_image( $img, $depth );
}

/**
 * Convert <audio> to core/audio, resolving src via the media manifest and
 * the WP library like every other media element.
 */
function ekwa_mc_convert_audio( $node, $depth ) {
	$ctx           = ekwa_mc_context();
	$media_by_name = $ctx['media_by_name'];

	$indent = str_repeat( '  ', $depth );
	$src    = $node->getAttribute( 'src' );
	if ( ! $src ) {
		$sources = $node->getElementsByTagName( 'source' );
		if ( $sources->length > 0 ) {
			$src = $sources->item( 0 )->getAttribute( 'src' );
		}
	}
	if ( ! $src ) {
		return ekwa_mc_convert_raw_html( $node, $depth );
	}

	$filename = strtolower( basename( $src ) );
	if ( ! empty( $media_by_name[ $filename ] ) ) {
		$src = $media_by_name[ $filename ]['url'];
	} elseif ( $lib = ekwa_mc_find_attachment_by_basename( $filename ) ) {
		$src = $lib['url'];
	} else {
		ekwa_mc_warn( "No manifest match for '$filename' (audio src: $src)" );
	}

	ekwa_mc_warn( 'Converted <audio> to core/audio.', 'converted' );

	$attrs      = ekwa_mc_anchor_attr( $node );
	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return $indent . '<!-- wp:audio' . $attrs_json . ' -->' . "\n" .
	       $indent . '<figure class="wp-block-audio"' . ekwa_mc_anchor_html_attr( $node ) . '><audio controls src="' . $src . '"></audio></figure>' . "\n" .
	       $indent . '<!-- /wp:audio -->' . "\n";
}

/**
 * Convert inline <svg> to the ekwa/svg block. The full markup goes into the
 * block's "svg" attribute (hex-escaped by the attr encoder so it can't break
 * the block comment) and is sanitized server-side on every render.
 */
function ekwa_mc_convert_svg_block( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$svg    = trim( ekwa_mc_get_outer_html( $node ) );

	// Strip HTML comments inside the SVG — belt and braces for comment safety.
	$svg = preg_replace( '/<!--.*?-->/s', '', $svg );

	if ( '' === $svg ) {
		return '';
	}

	ekwa_mc_warn( 'Converted inline <svg> to ekwa/svg (sanitized on render).', 'converted' );

	$attrs = array( 'svg' => $svg ) + ekwa_mc_anchor_attr( $node );

	return $indent . '<!-- wp:ekwa/svg ' . ekwa_mc_json_encode_block_attrs( $attrs ) . ' /-->' . "\n";
}

/**
 * Convert <video> to ekwa/video block.
 * Extracts src from <source> child, poster, and boolean attributes.
 */
function ekwa_mc_convert_video( $node, $depth ) {
	$ctx           = ekwa_mc_context();
	$media_by_name = $ctx['media_by_name'];
	$manifest      = $ctx['manifest'];

	$indent = str_repeat( '  ', $depth );
	$class  = $node->getAttribute( 'class' );
	$poster = $node->getAttribute( 'poster' );
	$attrs  = array();

	// Get video src from <source> child or src attribute.
	$src = $node->getAttribute( 'src' );
	if ( ! $src ) {
		$sources = $node->getElementsByTagName( 'source' );
		if ( $sources->length > 0 ) {
			$src = $sources->item( 0 )->getAttribute( 'src' );
		}
	}

	// Resolve video src via manifest, then WP library, then fall back.
	if ( $src ) {
		$filename = strtolower( basename( $src ) );
		if ( ! empty( $media_by_name[ $filename ] ) ) {
			$media_item = $media_by_name[ $filename ];
			$attrs['src']     = $media_item['url'];
			$attrs['mediaId'] = $media_item['id'];
		} elseif ( $lib = ekwa_mc_find_attachment_by_basename( $filename ) ) {
			$attrs['src']     = $lib['url'];
			$attrs['mediaId'] = $lib['id'];
		} else {
			$upload_url = $manifest['upload_url'] ?? '';
			if ( $upload_url ) {
				$attrs['src'] = rtrim( $upload_url, '/' ) . '/placeholder.svg';
			} else {
				$attrs['src'] = $src;
			}
			ekwa_mc_warn( "No manifest match for '$filename' (src: $src)" );
		}
	}

	// Resolve poster via manifest, then WP library.
	if ( $poster ) {
		$poster_filename = strtolower( basename( $poster ) );
		if ( ! empty( $media_by_name[ $poster_filename ] ) ) {
			$poster_item = $media_by_name[ $poster_filename ];
			$attrs['poster']   = $poster_item['url'];
			$attrs['posterId'] = $poster_item['id'];
		} elseif ( $lib = ekwa_mc_find_attachment_by_basename( $poster_filename ) ) {
			$attrs['poster']   = $lib['url'];
			$attrs['posterId'] = $lib['id'];
		} else {
			$attrs['poster'] = $poster;
			ekwa_mc_warn( "No manifest match for poster '$poster_filename' (src: $poster)" );
		}
	}

	// Boolean attributes.
	if ( $node->hasAttribute( 'autoplay' ) )    { $attrs['autoplay']    = true; }
	if ( $node->hasAttribute( 'loop' ) )        { $attrs['loop']        = true; }
	if ( $node->hasAttribute( 'muted' ) )       { $attrs['muted']       = true; }
	if ( $node->hasAttribute( 'playsinline' ) ) { $attrs['playsinline'] = true; }
	if ( $node->hasAttribute( 'controls' ) )    { $attrs['controls']    = true; }
	if ( $class )                               { $attrs['className']   = $class; }

	$attrs += ekwa_mc_anchor_attr( $node );
	// Hero videos carry their fit in the style attribute (object-fit:cover;
	// width:100%) far more often than in a class.
	$attrs += ekwa_mc_style_attr( $node );

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return $indent . '<!-- wp:ekwa/video' . $attrs_json . ' /-->' . "\n";
}

/**
 * Convert <a> (or <button>) with element children to ekwa/div with tagName="a".
 * Preserves inner blocks (img, div, h3, span, icon, etc.).
 */
function ekwa_mc_convert_anchor_wrapper( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	// Keep <button> a button. Rendering a dropdown/search trigger as an <a>
	// changed its semantics (and its default styling) for no reason — ekwa/div
	// renders either tag.
	$tag    = ( 'button' === strtolower( $node->nodeName ) ) ? 'button' : 'a';
	$url    = $node->getAttribute( 'href' ) ?: '';
	$class  = $node->getAttribute( 'class' );
	$target = $node->getAttribute( 'target' );
	$rel    = $node->getAttribute( 'rel' );
	$attrs  = array( 'tagName' => $tag );

	// Detect before stripping — the class list is one of the signals.
	$lightbox = ( 'a' === $tag ) ? ekwa_mc_lightbox_attrs( $node ) : array();
	if ( ! empty( $lightbox ) ) {
		$class = ekwa_mc_strip_lightbox_classes( $class );
	}

	if ( $url && 'a' === $tag )     { $attrs += ekwa_mc_link_attrs( $url ); }
	if ( $class )                   { $attrs['className'] = $class; }
	if ( $target === '_blank' )     { $attrs['target']    = '_blank'; }
	if ( $rel )                     { $attrs['rel']       = $rel; }

	$id = trim( $node->getAttribute( 'id' ) );
	if ( '' !== $id )               { $attrs['anchor']    = $id; }

	// ekwa/div renders inlineStyle back as a style attribute, so a styled CTA
	// (`<a class="btn" style="border-radius:6px">`) keeps its look here too.
	$attrs += ekwa_mc_style_attr( $node );

	// A mockup's lightbox thumbnail becomes a real lightbox trigger.
	$attrs += $lightbox;

	$custom = ekwa_mc_extract_custom_attributes( $node );
	if ( ! empty( $lightbox ) ) {
		// The block now emits data-gallery itself, so drop the source markup's
		// own grouping attributes — forwarding them would print a second,
		// conflicting data-gallery onto the same element.
		unset(
			$custom['data-gallery'], $custom['data-fancybox'], $custom['data-lightbox'],
			$custom['data-fslightbox'], $custom['data-lightbox-gallery']
		);
		ekwa_mc_warn(
			'Lightbox link converted to the theme lightbox'
				. ( isset( $lightbox['lightboxGroup'] ) ? ' (gallery "' . $lightbox['lightboxGroup'] . '")' : '' )
				. ' — the mockup\'s own lightbox library and its JS are not needed.',
			'converted'
		);
	}
	if ( ! empty( $custom ) ) {
		$attrs['customAttributes'] = $custom;
	}

	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	if ( ekwa_mc_has_mixed_content( $node ) ) {
		$children = ekwa_mc_convert_inline_mixed_children( $node, $depth + 1 );
		return $indent . '<!-- wp:ekwa/div' . $attrs_json . ' -->' . "\n" .
		       $children .
		       $indent . '<!-- /wp:ekwa/div -->' . "\n";
	}

	$children = ekwa_mc_convert_children( $node, $depth + 1 );

	return $indent . '<!-- wp:ekwa/div' . $attrs_json . ' -->' . "\n" .
	       $children .
	       $indent . '<!-- /wp:ekwa/div -->' . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Parse inline style string into key-value pairs.
 */
function ekwa_mc_parse_inline_style( $style_string ) {
	$result = array();
	if ( ! $style_string ) return $result;

	$parts = explode( ';', $style_string );
	foreach ( $parts as $part ) {
		$part = trim( $part );
		if ( ! $part ) continue;
		$colon = strpos( $part, ':' );
		if ( $colon === false ) continue;
		$key = trim( substr( $part, 0, $colon ) );
		$val = trim( substr( $part, $colon + 1 ) );
		$result[ strtolower( $key ) ] = $val;
	}
	return $result;
}

/**
 * Check if a node has Font Awesome classes.
 */
function ekwa_mc_has_fa_class( $node ) {
	$class = $node->getAttribute( 'class' );
	if ( ! $class ) {
		return false;
	}

	// Font Awesome is no longer the only icon font mockups ship with — Remix
	// Icon (ri-*) and Bootstrap Icons (bi-*) are common now. Without them an
	// <i class="ri-search-line"> fell through to the generic inline-element
	// path: it still RENDERED correctly, but as an ekwa/div rather than the
	// ekwa/icon block (no icon picker in the editor), and the two converters
	// disagreed about the same markup.
	static $families = array(
		'fa', 'fas', 'far', 'fab', 'fal', 'fad',      // Font Awesome
		'las', 'lar', 'lab',                          // Line Awesome
		'dashicons',                                  // WordPress
		'material-icons',                             // Material
		'glyphicon',                                  // Bootstrap 3
	);
	static $prefixes = array(
		'fa-', 'la-', 'ri-', 'bi-', 'ti-', 'ion-', 'icofont-',
		'feather-', 'dashicons-', 'glyphicon-', 'material-symbols',
	);

	foreach ( preg_split( '/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
		$token = strtolower( $token );
		if ( in_array( $token, $families, true ) ) {
			return true;
		}
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $token, $prefix ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Does this node contain media that has to become a block of its own?
 *
 * These are the elements whose sources are resolved through the media manifest
 * / library (or that carry their own block); leaving one inside a rich-text
 * attribute silently strands its URL. Deliberately limited to elements that are
 * valid *inside* a <p>, so the wrapper can keep its tag either way.
 *
 * @param DOMElement $node
 * @return bool
 */
function ekwa_mc_has_media_descendant( $node ) {
	foreach ( array( 'img', 'picture', 'svg', 'video', 'audio', 'iframe', 'canvas' ) as $tag ) {
		if ( $node->getElementsByTagName( $tag )->length > 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Check if a node has element children (not just text).
 */
function ekwa_mc_has_element_children( $node ) {
	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_ELEMENT_NODE ) {
			return true;
		}
	}
	return false;
}

/**
 * Check if a node has mixed content (both element children AND significant text nodes).
 */
function ekwa_mc_has_mixed_content( $node ) {
	$has_elements = false;
	$has_text     = false;

	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_ELEMENT_NODE ) {
			$has_elements = true;
		} elseif ( $child->nodeType === XML_TEXT_NODE && trim( $child->textContent ) !== '' ) {
			$has_text = true;
		}
	}

	return $has_elements && $has_text;
}

/**
 * Convert mixed children (text + elements) to a sequence of blocks. Bare
 * text nodes become `ekwa/text` (tagName=span); element children dispatch
 * through the normal converter — so tables, details, svg etc. all get their
 * proper blocks instead of a whole-wrapper `wp:html` blob. Consecutive
 * inline blocks reflow inline on the front end, preserving text flow.
 *
 * @param DOMElement $node
 * @param int        $depth Nesting depth for the children blocks (parent depth + 1).
 * @return string Block markup for the inner content.
 */
function ekwa_mc_convert_inline_mixed_children( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$output = '';

	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_TEXT_NODE ) {
			$text = trim( $child->textContent );
			if ( '' === $text ) {
				continue;
			}
			$attrs = array( 'tagName' => 'span', 'text' => $text );
			$output .= $indent . '<!-- wp:ekwa/text ' . ekwa_mc_json_encode_block_attrs( $attrs ) . ' /-->' . "\n";
		} elseif ( $child->nodeType === XML_ELEMENT_NODE ) {
			// <br> inside mixed content: emit a literal break via a tiny html block.
			if ( strtolower( $child->nodeName ) === 'br' ) {
				$output .= $indent . "<!-- wp:html -->\n" . $indent . "<br>\n" . $indent . "<!-- /wp:html -->\n";
				continue;
			}
			$output .= ekwa_mc_convert_node( $child, $depth );
		}
	}

	return $output;
}

/**
 * Check if all element children of a node are FA icons (<i class="fa-*">).
 * Returns true if there is at least one element child AND every element child is an FA icon.
 */
function ekwa_mc_has_only_fa_children( $node ) {
	$has_elements = false;
	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_ELEMENT_NODE ) {
			$has_elements = true;
			$child_tag = strtolower( $child->nodeName );
			if ( $child_tag !== 'i' || ! ekwa_mc_has_fa_class( $child ) ) {
				// Also allow <span> wrapping a single FA icon.
				if ( $child_tag === 'span' && ekwa_mc_has_only_fa_children( $child ) ) {
					continue;
				}
				return false;
			}
		}
	}
	return $has_elements;
}

/**
 * Get direct text content of a node (not including descendant text).
 */
function ekwa_mc_get_text_content_direct( $node ) {
	$text = '';
	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_TEXT_NODE ) {
			$text .= $child->textContent;
		}
	}
	return $text;
}

/**
 * Check if a node has ONLY text content (no element children, but has significant text).
 */
function ekwa_mc_has_text_only( $node ) {
	$has_text = false;
	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_ELEMENT_NODE ) {
			return false; // Has element children — not text-only.
		}
		if ( $child->nodeType === XML_TEXT_NODE && trim( $child->textContent ) !== '' ) {
			$has_text = true;
		}
	}
	return $has_text;
}

/**
 * Get inner HTML of a node.
 */
function ekwa_mc_get_inner_html( $node ) {
	$html = '';
	foreach ( $node->childNodes as $child ) {
		$html .= $node->ownerDocument->saveHTML( $child );
	}
	return $html;
}

/**
 * Get outer HTML of a node.
 */
function ekwa_mc_get_outer_html( $node ) {
	return $node->ownerDocument->saveHTML( $node );
}

/**
 * JSON-encode block attributes in WordPress block comment format.
 *
 * Mirrors core serialize_block_attributes(): <, >, &, " are hex-escaped and
 * double hyphens are unicode-escaped, so attribute values containing markup
 * (e.g. the ekwa/svg "svg" attribute) can never terminate the block comment
 * early.
 */
function ekwa_mc_json_encode_block_attrs( $attrs ) {
	$encoded = json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS );
	return str_replace( '--', '\\u002d\\u002d', $encoded );
}

/**
 * Resolve url() references in an inline style string via the media manifest.
 *
 * Replaces background-image:url('assets/team.jpg') with the manifest URL.
 *
 * @param string $style_string Raw inline style.
 * @return string Style string with resolved URLs.
 */
function ekwa_mc_resolve_style_urls( $style_string ) {
	$ctx           = ekwa_mc_context();
	$media_by_name = $ctx['media_by_name'];
	$manifest      = $ctx['manifest'];

	return preg_replace_callback(
		'/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/',
		function ( $matches ) use ( $media_by_name, $manifest ) {
			$original = $matches[1];
			$filename = strtolower( basename( $original ) );

			if ( ! empty( $media_by_name[ $filename ] ) ) {
				return 'url(' . $media_by_name[ $filename ]['url'] . ')';
			}

			// Manifest miss — try the WP media library by basename.
			$lib = ekwa_mc_find_attachment_by_basename( $filename );
			if ( $lib ) {
				return 'url(' . $lib['url'] . ')';
			}

			$upload_url = $manifest['upload_url'] ?? '';
			if ( $upload_url ) {
				ekwa_mc_warn( "No manifest match for style url '$filename' (src: $original)" );
			}
			return $matches[0]; // Keep original.
		},
		$style_string
	);
}

/**
 * Look up an attachment in the WP media library by file basename.
 *
 * Used as a secondary lookup when the supplied manifest doesn't contain a
 * given filename — so already-uploaded assets resolve to a real attachment
 * (URL + ID) instead of being left as raw relative paths in the markup.
 *
 * Returns null when WordPress isn't loaded (e.g. CLI converter usage) or
 * when no attachment matches. Results are memoized per-request.
 *
 * @param string $filename Lowercased basename, e.g. "hero-banners-1.jpg".
 * @return array|null { id:int, url:string, alt:string, width:int, height:int } or null.
 */
function ekwa_mc_find_attachment_by_basename( $filename ) {
	static $cache = array();

	$filename = strtolower( trim( (string) $filename ) );
	if ( '' === $filename ) {
		return null;
	}
	if ( array_key_exists( $filename, $cache ) ) {
		return $cache[ $filename ];
	}

	// Bail when running outside a WP context (e.g. CLI converter without WP).
	if ( ! defined( 'ABSPATH' ) || ! function_exists( 'wp_get_attachment_url' ) ) {
		$cache[ $filename ] = null;
		return null;
	}

	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		$cache[ $filename ] = null;
		return null;
	}

	// _wp_attached_file values look like "2024/01/hero-banners-1.jpg" — match
	// by trailing basename (case-insensitive via collation).
	$like   = '%/' . $wpdb->esc_like( $filename );
	$att_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta}
		 WHERE meta_key = '_wp_attached_file'
		   AND ( meta_value LIKE %s OR meta_value = %s )
		 ORDER BY post_id DESC
		 LIMIT 1",
		$like,
		$filename
	) );

	if ( ! $att_id ) {
		$cache[ $filename ] = null;
		return null;
	}

	$url = wp_get_attachment_url( $att_id );
	if ( ! $url ) {
		$cache[ $filename ] = null;
		return null;
	}

	$meta = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $att_id ) : array();
	$info = array(
		'id'     => (int) $att_id,
		'url'    => $url,
		'alt'    => (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ),
		'width'  => isset( $meta['width'] )  ? (int) $meta['width']  : 0,
		'height' => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
	);

	$cache[ $filename ] = $info;
	return $info;
}

/**
 * Extract background-image from an inline style string.
 *
 * Pulls out the background-image:url(...) declaration, resolves the URL via
 * the media manifest, and returns the resolved URL + any remaining CSS.
 *
 * @param string $style_string Raw inline style.
 * @return array { url: string, mediaId: int, remaining: string }
 */
function ekwa_mc_extract_background_image( $style_string ) {
	$ctx           = ekwa_mc_context();
	$media_by_name = $ctx['media_by_name'];
	$manifest      = $ctx['manifest'];

	$result = array( 'url' => '', 'mediaId' => 0, 'remaining' => '' );

	// Match background-image:url(...) or background:...url(...)
	if ( preg_match( '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', $style_string, $m ) ) {
		$original = $m[1];
		$filename = strtolower( basename( $original ) );

		if ( ! empty( $media_by_name[ $filename ] ) ) {
			$result['url']     = $media_by_name[ $filename ]['url'];
			$result['mediaId'] = $media_by_name[ $filename ]['id'];
		} elseif ( $lib = ekwa_mc_find_attachment_by_basename( $filename ) ) {
			// Manifest miss — fall back to a basename match in the WP library.
			$result['url']     = $lib['url'];
			$result['mediaId'] = $lib['id'];
		} else {
			// No match anywhere — keep the original URL.
			$result['url'] = $original;
			$upload_url = $manifest['upload_url'] ?? '';
			if ( $upload_url ) {
				ekwa_mc_warn( "No manifest match for background '$filename' (src: $original)" );
			}
		}

		// Remove the background-image (or background) property from the style.
		$remaining = preg_replace( '/background(-image)?\s*:\s*[^;]*url\([^)]*\)[^;]*;?\s*/i', '', $style_string );
		$remaining = trim( $remaining, " ;\t\n\r" );
		$result['remaining'] = $remaining;
	} else {
		$result['remaining'] = $style_string;
	}

	return $result;
}

/**
 * Extract custom CSS from HTML <style> tags, stripping boilerplate.
 */
function ekwa_mc_extract_css( $html ) {
	$css = '';
	if ( preg_match_all( '/<style[^>]*>(.*?)<\/style>/si', $html, $matches ) ) {
		foreach ( $matches[1] as $block ) {
			$css .= $block . "\n";
		}
	}

	$css = preg_replace( '/:root\s*\{[^}]*\}/s', '', $css );
	$css = preg_replace( '/\*\s*,\s*\*::before\s*,\s*\*::after\s*\{[^}]*\}/s', '', $css );
	$css = preg_replace( '/\bbody\s*\{[^}]*\}/s', '', $css );
	$css = preg_replace( '/\/\*\s*=+.*?=+\s*\*\//s', '', $css );
	$css = preg_replace( '/\n{3,}/', "\n\n", $css );
	$css = trim( $css );

	if ( $css ) {
		$css = "/* Custom styles extracted from mockup */\n\n" . $css . "\n";
	}

	return $css;
}
