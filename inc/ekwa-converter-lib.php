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
			'media_by_name' => array(),
			'manifest'      => null,
			'warnings'      => array(),
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
 * @param string $message
 */
function ekwa_mc_warn( $message ) {
	$ctx = ekwa_mc_context();
	$ctx['warnings'][] = $message;
	ekwa_mc_context( $ctx );
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
function ekwa_mc_convert_html( $html, $manifest_data = null ) {
	// Reset context.
	$media_by_name = array();
	if ( $manifest_data && ! empty( $manifest_data['media'] ) ) {
		foreach ( $manifest_data['media'] as $item ) {
			$fname = strtolower( $item['filename'] );
			$media_by_name[ $fname ] = $item;
		}
	}

	ekwa_mc_context( array(
		'media_by_name' => $media_by_name,
		'manifest'      => $manifest_data,
		'warnings'      => array(),
	) );

	// Parse HTML.
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	$body    = $doc->getElementsByTagName( 'body' )->item( 0 );
	$html_el = $doc->getElementsByTagName( 'html' )->item( 0 );

	if ( $body ) {
		$output = ekwa_mc_convert_children( $body, 0 );
	} elseif ( $html_el ) {
		$output = ekwa_mc_convert_children( $html_el, 0 );
	} else {
		$root = $doc->documentElement;
		if ( $root ) {
			$output = ekwa_mc_convert_node( $root, 0 );
		} else {
			$output = '';
		}
	}

	$ctx = ekwa_mc_context();

	return array(
		'markup'   => $output,
		'warnings' => $ctx['warnings'],
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

	// Text nodes.
	if ( $node->nodeType === XML_TEXT_NODE ) {
		$text = $node->textContent;
		if ( trim( $text ) === '' ) {
			return '';
		}
		return '';
	}

	if ( $node->nodeType !== XML_ELEMENT_NODE ) {
		return '';
	}

	$tag    = strtolower( $node->nodeName );
	$indent = str_repeat( '  ', $depth );

	// Semantic wrapper tags → ekwa/div with tagName.
	$semantic_tags = array( 'section', 'header', 'footer', 'main', 'aside', 'article', 'nav' );
	if ( in_array( $tag, $semantic_tags, true ) ) {
		return ekwa_mc_convert_div_block( $node, $depth, $tag );
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

	// Anchor → ekwa/link.
	if ( $tag === 'a' ) {
		return ekwa_mc_convert_link( $node, $depth );
	}

	// Button → ekwa/link.
	if ( $tag === 'button' ) {
		return ekwa_mc_convert_link( $node, $depth );
	}

	// Font Awesome icon → ekwa/icon.
	if ( $tag === 'i' && ekwa_mc_has_fa_class( $node ) ) {
		return ekwa_mc_convert_icon( $node, $depth );
	}

	// Inline text elements → ekwa/text (if text-only content).
	$inline_tags = array( 'span', 'small', 'strong', 'em', 'mark', 'time', 'label', 'sup', 'sub' );
	if ( in_array( $tag, $inline_tags, true ) ) {
		$text_content = trim( $node->textContent );
		if ( $text_content !== '' && ! ekwa_mc_has_element_children( $node ) ) {
			return ekwa_mc_convert_text( $node, $depth, $tag );
		}
		return ekwa_mc_convert_raw_html( $node, $depth );
	}

	// <div> detection — check inline styles.
	if ( $tag === 'div' ) {
		$style = ekwa_mc_parse_inline_style( $node->getAttribute( 'style' ) );

		// display:flex → ekwa/flex.
		if ( isset( $style['display'] ) && $style['display'] === 'flex' ) {
			return ekwa_mc_convert_flex_block( $node, $depth );
		}

		// display:grid → ekwa/grid.
		if ( isset( $style['display'] ) && $style['display'] === 'grid' ) {
			return ekwa_mc_convert_grid_block( $node, $depth );
		}

		// max-width + margin auto → ekwa/container.
		if ( isset( $style['max-width'] ) && isset( $style['margin'] ) && strpos( $style['margin'], 'auto' ) !== false ) {
			return ekwa_mc_convert_container_block( $node, $depth );
		}
		if ( isset( $style['max-width'] ) && isset( $style['margin-left'] ) && $style['margin-left'] === 'auto'
			&& isset( $style['margin-right'] ) && $style['margin-right'] === 'auto' ) {
			return ekwa_mc_convert_container_block( $node, $depth );
		}

		// Fallback: plain div → ekwa/div.
		return ekwa_mc_convert_div_block( $node, $depth, 'div' );
	}

	// Video element.
	if ( $tag === 'video' ) {
		return ekwa_mc_convert_raw_html( $node, $depth );
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
	$indent    = str_repeat( '  ', $depth );
	$class     = $node->getAttribute( 'class' );
	$attrs     = array();

	if ( $tag_name !== 'div' ) {
		$attrs['tagName'] = $tag_name;
	}
	if ( $class ) {
		$attrs['className'] = $class;
	}

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	if ( ekwa_mc_has_mixed_content( $node ) ) {
		$inner_html = ekwa_mc_get_inner_html( $node );
		return $indent . '<!-- wp:ekwa/div' . $attrs_json . ' -->' . "\n" .
		       $indent . '  <!-- wp:html -->' . "\n" .
		       $indent . '  ' . trim( $inner_html ) . "\n" .
		       $indent . '  <!-- /wp:html -->' . "\n" .
		       $indent . '<!-- /wp:ekwa/div -->' . "\n";
	}

	$children = ekwa_mc_convert_children( $node, $depth + 1 );

	return $indent . '<!-- wp:ekwa/div' . $attrs_json . ' -->' . "\n" .
	       $children .
	       $indent . '<!-- /wp:ekwa/div -->' . "\n";
}

/**
 * Convert to ekwa/flex block.
 */
function ekwa_mc_convert_flex_block( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$class  = $node->getAttribute( 'class' );
	$style  = ekwa_mc_parse_inline_style( $node->getAttribute( 'style' ) );
	$attrs  = array();

	if ( $class ) { $attrs['className'] = $class; }

	if ( isset( $style['flex-direction'] ) )  { $attrs['direction']      = $style['flex-direction']; }
	if ( isset( $style['justify-content'] ) ) { $attrs['justifyContent'] = $style['justify-content']; }
	if ( isset( $style['align-items'] ) )     { $attrs['alignItems']     = $style['align-items']; }
	if ( isset( $style['flex-wrap'] ) )       { $attrs['wrap']           = $style['flex-wrap']; }

	$tag = strtolower( $node->nodeName );
	if ( $tag !== 'div' ) { $attrs['tagName'] = $tag; }

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	if ( ekwa_mc_has_mixed_content( $node ) ) {
		$inner_html = ekwa_mc_get_inner_html( $node );
		return $indent . '<!-- wp:ekwa/flex' . $attrs_json . ' -->' . "\n" .
		       $indent . '  <!-- wp:html -->' . "\n" .
		       $indent . '  ' . trim( $inner_html ) . "\n" .
		       $indent . '  <!-- /wp:html -->' . "\n" .
		       $indent . '<!-- /wp:ekwa/flex -->' . "\n";
	}

	$children = ekwa_mc_convert_children( $node, $depth + 1 );

	return $indent . '<!-- wp:ekwa/flex' . $attrs_json . ' -->' . "\n" .
	       $children .
	       $indent . '<!-- /wp:ekwa/flex -->' . "\n";
}

/**
 * Convert to ekwa/grid block.
 */
function ekwa_mc_convert_grid_block( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$class  = $node->getAttribute( 'class' );
	$style  = ekwa_mc_parse_inline_style( $node->getAttribute( 'style' ) );
	$attrs  = array();

	if ( $class ) { $attrs['className'] = $class; }

	if ( isset( $style['grid-template-columns'] ) ) {
		$gtc = $style['grid-template-columns'];
		if ( preg_match( '/repeat\(\s*(\d+)/', $gtc, $m ) ) {
			$attrs['columns'] = (int) $m[1];
		} else {
			$attrs['columnWidths'] = $gtc;
		}
	}

	if ( $node->hasAttribute( 'data-tablet-cols' ) ) {
		$attrs['tabletColumns'] = (int) $node->getAttribute( 'data-tablet-cols' );
	}
	if ( $node->hasAttribute( 'data-mobile-cols' ) ) {
		$attrs['mobileColumns'] = (int) $node->getAttribute( 'data-mobile-cols' );
	}

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$children = ekwa_mc_convert_children( $node, $depth + 1 );

	return $indent . '<!-- wp:ekwa/grid' . $attrs_json . ' -->' . "\n" .
	       $children .
	       $indent . '<!-- /wp:ekwa/grid -->' . "\n";
}

/**
 * Convert to ekwa/container block.
 */
function ekwa_mc_convert_container_block( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );
	$class  = $node->getAttribute( 'class' );
	$style  = ekwa_mc_parse_inline_style( $node->getAttribute( 'style' ) );
	$attrs  = array();

	if ( $class ) { $attrs['className'] = $class; }
	if ( isset( $style['max-width'] ) ) { $attrs['maxWidth'] = $style['max-width']; }

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	$children = ekwa_mc_convert_children( $node, $depth + 1 );

	return $indent . '<!-- wp:ekwa/container' . $attrs_json . ' -->' . "\n" .
	       $children .
	       $indent . '<!-- /wp:ekwa/container -->' . "\n";
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
	$attrs  = array( 'iconClass' => $class );

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

	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return $indent . '<!-- wp:ekwa/text' . $attrs_json . ' /-->' . "\n";
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

	return $indent . '<!-- wp:html -->' . "\n" .
	       $indent . trim( $html ) . "\n" .
	       $indent . '<!-- /wp:html -->' . "\n";
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
 */
function ekwa_mc_json_encode_block_attrs( $attrs ) {
	return json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
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
