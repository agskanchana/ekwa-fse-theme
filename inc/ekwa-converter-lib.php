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

	// Paragraph → core/paragraph.
	if ( $tag === 'p' ) {
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
		if ( ekwa_mc_has_element_children( $node ) ) {
			return ekwa_mc_convert_anchor_wrapper( $node, $depth );
		}
		return ekwa_mc_convert_link( $node, $depth );
	}

	// Button — same logic as anchor.
	if ( $tag === 'button' ) {
		if ( ekwa_mc_has_element_children( $node ) ) {
			return ekwa_mc_convert_anchor_wrapper( $node, $depth );
		}
		return ekwa_mc_convert_link( $node, $depth );
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
	$inline_tags = array( 'span', 'small', 'strong', 'em', 'mark', 'time', 'label', 'sup', 'sub' );
	if ( in_array( $tag, $inline_tags, true ) ) {
		$has_elements = ekwa_mc_has_element_children( $node );
		if ( ! $has_elements ) {
			$text_content = trim( $node->textContent );
			if ( $text_content !== '' ) {
				return ekwa_mc_convert_text( $node, $depth, $tag );
			}
			return ekwa_mc_convert_raw_html( $node, $depth );
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

	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$class_attr = 'wp-block-heading';
	if ( $class ) { $class_attr .= ' ' . $class; }

	return $indent . '<!-- wp:heading' . $attrs_json . ' -->' . "\n" .
	       $indent . '<h' . $level . ' class="' . $class_attr . '">' . trim( $inner ) . '</h' . $level . '>' . "\n" .
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

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$class_attr = $class ? ' class="' . $class . '"' : '';

	return $indent . '<!-- wp:paragraph' . $attrs_json . ' -->' . "\n" .
	       $indent . '<p' . $class_attr . '>' . trim( $inner ) . '</p>' . "\n" .
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
	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$inner = $paragraph_blocks;
	if ( $cite_html !== '' ) {
		$inner .= str_repeat( '  ', $depth + 1 ) . $cite_html . "\n";
	}

	return $indent . '<!-- wp:quote' . $attrs_json . ' -->' . "\n" .
	       $indent . '<blockquote class="' . $class_attr . '">' . "\n" .
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

	if ( ! isset( $attrs['src'] ) && $src ) { $attrs['src'] = $src; }
	if ( $alt )    { $attrs['alt']    = $alt; }
	if ( $width )  { $attrs['width']  = $width; }
	if ( $height ) { $attrs['height'] = $height; }
	if ( $load )   { $attrs['loading'] = $load; }
	if ( $class )  { $attrs['className'] = $class; }
	if ( isset( $style['object-fit'] ) ) { $attrs['objectFit'] = $style['object-fit']; }

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
	if ( $url )   { $attrs['url']  = $url; }
	if ( $text )  { $attrs['text'] = $text; }
	if ( $class ) { $attrs['className'] = $class; }
	if ( $target === '_blank' ) { $attrs['newTab'] = true; }
	if ( $rel )   { $attrs['rel'] = $rel; }

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return $indent . '<!-- wp:ekwa/link' . $attrs_json . ' /-->' . "\n";
}

/**
 * Convert to ekwa/icon.
 */
function ekwa_mc_convert_icon( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$class  = $node->getAttribute( 'class' );
	$attrs  = array( 'iconClass' => $class, 'wrapperClass' => '' );

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

	$custom = ekwa_mc_extract_custom_attributes( $node );
	if ( ! empty( $custom ) ) {
		$attrs['customAttributes'] = $custom;
	}

	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return $indent . '<!-- wp:ekwa/text' . $attrs_json . ' /-->' . "\n";
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

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$class_attr = $class ? ' class="' . $class . '"' : '';

	return $indent . '<!-- wp:list' . $attrs_json . ' -->' . "\n" .
	       $indent . '<' . $tag . $class_attr . '>' . trim( $inner ) . '</' . $tag . '>' . "\n" .
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

	$figure_class = 'wp-block-table' . ( $class ? ' ' . $class : '' );
	$caption_html = $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '';

	ekwa_mc_warn( 'Converted <table> to an editable core/table block.', 'converted' );

	return $indent . '<!-- wp:table ' . ekwa_mc_json_encode_block_attrs( $attrs ) . ' -->' . "\n" .
	       $indent . '<figure class="' . $figure_class . '"><table>' . $table_html . '</table>' . $caption_html . '</figure>' . "\n" .
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

	return $indent . '<!-- wp:audio -->' . "\n" .
	       $indent . '<figure class="wp-block-audio"><audio controls src="' . $src . '"></audio></figure>' . "\n" .
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

	return $indent . '<!-- wp:ekwa/svg ' . ekwa_mc_json_encode_block_attrs( array( 'svg' => $svg ) ) . ' /-->' . "\n";
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

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return $indent . '<!-- wp:ekwa/video' . $attrs_json . ' /-->' . "\n";
}

/**
 * Convert <a> (or <button>) with element children to ekwa/div with tagName="a".
 * Preserves inner blocks (img, div, h3, span, icon, etc.).
 */
function ekwa_mc_convert_anchor_wrapper( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$tag    = strtolower( $node->nodeName );
	$url    = $node->getAttribute( 'href' ) ?: '';
	$class  = $node->getAttribute( 'class' );
	$target = $node->getAttribute( 'target' );
	$rel    = $node->getAttribute( 'rel' );
	$attrs  = array( 'tagName' => 'a' );

	if ( $url )                     { $attrs['href']      = $url; }
	if ( $class )                   { $attrs['className'] = $class; }
	if ( $target === '_blank' )     { $attrs['target']    = '_blank'; }
	if ( $rel )                     { $attrs['rel']       = $rel; }

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
	return $class && preg_match( '/\b(fa-|fas |far |fab |fal |fad |fa )\b/i', $class );
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
