<?php
/**
 * Mockup Converter — Dynamic Data Detection.
 *
 * Detects phone numbers, email, maps links, social icons, hours, copyright,
 * and navigation in mockup HTML and replaces them with the appropriate
 * Ekwa/core blocks.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ORCHESTRATOR
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Try all dynamic data detectors on a node.
 *
 * @param DOMElement $node
 * @param int        $depth
 * @return string|null Block markup, or null to continue normal conversion.
 */
function ekwa_mc_detect_dynamic( $node, $depth ) {
	if ( $node->nodeType !== XML_ELEMENT_NODE ) {
		return null;
	}

	$tag = strtolower( $node->nodeName );

	// Anchor-based detections.
	if ( $tag === 'a' ) {
		$href = $node->getAttribute( 'href' );

		// Logo: <a class="...logo..."><img ...></a>
		$result = ekwa_mc_detect_logo_link( $node, $depth );
		if ( $result !== null ) {
			return $result;
		}

		// Phone: <a href="tel:...">
		if ( stripos( $href, 'tel:' ) === 0 ) {
			return ekwa_mc_detect_phone( $node, $depth );
		}

		// Maps: <a href="...maps.google.com...">
		if ( preg_match( '/(maps\.google|google\.com\/maps|goo\.gl\/maps|maps\.apple\.com|waze\.com)/i', $href ) ) {
			return ekwa_mc_detect_address( $node, $depth );
		}
	}

	// Standalone <img> with logo context.
	if ( $tag === 'img' ) {
		$result = ekwa_mc_detect_logo_img( $node, $depth );
		if ( $result !== null ) {
			return $result;
		}
	}

	// <nav> → core/navigation.
	if ( $tag === 'nav' ) {
		return ekwa_mc_detect_navigation( $node, $depth );
	}

	// <iframe> with Google Maps → ekwa/map.
	if ( $tag === 'iframe' ) {
		$result = ekwa_mc_detect_map_iframe( $node, $depth );
		if ( $result !== null ) {
			return $result;
		}
	}

	// Container-based detections.
	if ( $node->hasChildNodes() ) {
		// Social icons.
		$result = ekwa_mc_detect_social( $node, $depth );
		if ( $result !== null ) {
			return $result;
		}

		// Working hours.
		$result = ekwa_mc_detect_hours( $node, $depth );
		if ( $result !== null ) {
			return $result;
		}

		// Copyright.
		$result = ekwa_mc_detect_copyright( $node, $depth );
		if ( $result !== null ) {
			return $result;
		}
	}

	return null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PHONE → ekwa/phone
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Detect <a href="tel:..."> → ekwa/phone block.
 */
function ekwa_mc_detect_phone( $node, $depth ) {
	$href       = $node->getAttribute( 'href' );
	$tel_digits = preg_replace( '/[^0-9]/', '', substr( $href, 4 ) );

	if ( strlen( $tel_digits ) < 7 ) {
		return null;
	}

	// Determine type from the link's own text content only.
	$text = strtolower( $node->textContent );
	$type = ( strpos( $text, 'existing' ) !== false ) ? 'existing' : 'new';

	// Extract prefix text (everything before the phone number).
	$full_text = trim( $node->textContent );
	// Remove the phone number and icon from the text to get the prefix.
	$clean = preg_replace( '/\(?\d[\d\s\(\)\-\.]{6,}\d/', '', $full_text );
	$clean = trim( $clean );

	// Only set prefix if it differs from the block's auto-generated default.
	$default_prefix = ( $type === 'existing' ) ? 'Existing Patients:' : 'New Patients:';
	$prefix = ( $clean && $clean !== $default_prefix ) ? $clean : '';

	// Detect icon class if present.
	$icon_class = 'fa-solid fa-phone';
	$icons = $node->getElementsByTagName( 'i' );
	if ( $icons->length > 0 ) {
		$icon_el = $icons->item( 0 );
		$ic = $icon_el->getAttribute( 'class' );
		if ( $ic && preg_match( '/\bfa[srlbd]?\s+fa-[a-z0-9-]+/i', $ic ) ) {
			$icon_class = $ic;
		}
	}

	$attrs = array( 'type' => $type, 'location' => 1 );
	if ( $prefix ) {
		$attrs['prefix'] = $prefix;
	}
	$attrs['iconClass'] = $icon_class;

	$indent     = str_repeat( '  ', $depth );
	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	ekwa_mc_warn( "Auto-detected tel: link → ekwa/phone (type: $type)" );

	return $indent . '<!-- wp:ekwa/phone' . $attrs_json . ' /-->' . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADDRESS → ekwa/address
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Detect <a href="...maps.google..."> → ekwa/address block.
 */
function ekwa_mc_detect_address( $node, $depth ) {
	$text = trim( $node->textContent );

	// Determine mode from link text.
	$mode = 'text';
	if ( preg_match( '/\d+\s+\w+.*\b[A-Z]{2}\s+\d{5}/i', $text ) ) {
		$mode = 'full';
	} elseif ( preg_match( '/direction/i', $text ) ) {
		$mode = 'text';
	}

	// Detect icon.
	$icon_class = 'fa-solid fa-location-dot';
	$icons = $node->getElementsByTagName( 'i' );
	if ( $icons->length > 0 ) {
		$ic = $icons->item( 0 )->getAttribute( 'class' );
		if ( $ic && preg_match( '/\bfa[srlbd]?\s+fa-[a-z0-9-]+/i', $ic ) ) {
			$icon_class = $ic;
		}
	}

	$attrs = array(
		'location'  => 1,
		'mode'      => $mode,
		'iconClass' => $icon_class,
		'newTab'    => true,
	);

	$indent     = str_repeat( '  ', $depth );
	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	ekwa_mc_warn( 'Auto-detected maps link → ekwa/address (mode: ' . $mode . ')' );

	return $indent . '<!-- wp:ekwa/address' . $attrs_json . ' /-->' . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// COPYRIGHT → ekwa/copyright
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Detect copyright text → ekwa/copyright block.
 */
function ekwa_mc_detect_copyright( $node, $depth ) {
	$tag  = strtolower( $node->nodeName );
	$text = $node->textContent;

	// Only match small leaf-level elements, not large containers.
	$allowed = array( 'p', 'div', 'span', 'small' );
	if ( ! in_array( $tag, $allowed, true ) ) {
		return null;
	}

	if ( ! preg_match( '/(\xC2\xA9|©|&copy;|copyright)\s*\d{4}/iu', $text ) ) {
		return null;
	}

	// Guard: skip if this element has many child elements (it's a container, not a copyright line).
	$child_element_count = 0;
	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_ELEMENT_NODE ) {
			$child_element_count++;
		}
	}
	if ( $child_element_count > 3 ) {
		return null;
	}

	$indent = str_repeat( '  ', $depth );

	ekwa_mc_warn( 'Auto-detected copyright text → ekwa/copyright' );

	return $indent . '<!-- wp:ekwa/copyright /-->' . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// SOCIAL → ekwa/social
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Known social media domain patterns.
 */
function ekwa_mc_is_social_domain( $url ) {
	return (bool) preg_match(
		'/(facebook\.com|fb\.com|instagram\.com|twitter\.com|x\.com|youtube\.com|linkedin\.com|pinterest\.com|tiktok\.com|yelp\.com|snapchat\.com|threads\.net|nextdoor\.com)/i',
		$url
	);
}

/**
 * Detect container with 2+ social media links → ekwa/social block.
 */
function ekwa_mc_detect_social( $node, $depth ) {
	$tag = strtolower( $node->nodeName );

	$containers = array( 'div', 'nav', 'ul', 'section', 'footer' );
	if ( ! in_array( $tag, $containers, true ) ) {
		return null;
	}

	// Collect social links.
	$social_count = 0;
	$total_links  = 0;
	$found_names  = array();

	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType !== XML_ELEMENT_NODE ) {
			continue;
		}
		$child_tag = strtolower( $child->nodeName );

		$anchors = array();
		if ( $child_tag === 'a' ) {
			$anchors[] = $child;
		} elseif ( $child_tag === 'li' ) {
			foreach ( $child->childNodes as $li_child ) {
				if ( $li_child->nodeType === XML_ELEMENT_NODE && strtolower( $li_child->nodeName ) === 'a' ) {
					$anchors[] = $li_child;
				}
			}
		}

		foreach ( $anchors as $a ) {
			$total_links++;
			$href = $a->getAttribute( 'href' );
			if ( ekwa_mc_is_social_domain( $href ) ) {
				$social_count++;
				if ( preg_match( '/(?:www\.)?([a-z]+)\.\w+/i', $href, $m ) ) {
					$found_names[] = $m[1];
				}
			}
		}
	}

	if ( $social_count < 2 ) {
		return null;
	}
	if ( $total_links > 0 && $social_count / $total_links < 0.5 ) {
		return null;
	}

	$indent = str_repeat( '  ', $depth );

	ekwa_mc_warn( 'Auto-detected social icons → ekwa/social (found: ' . implode( ', ', $found_names ) . ')' );

	return $indent . '<!-- wp:ekwa/social /-->' . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// HOURS → ekwa/hours
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Detect working hours container → ekwa/hours block.
 */
function ekwa_mc_detect_hours( $node, $depth ) {
	$tag  = strtolower( $node->nodeName );
	$text = $node->textContent;

	$containers = array( 'div', 'section', 'table', 'dl', 'ul', 'tbody' );
	if ( ! in_array( $tag, $containers, true ) ) {
		return null;
	}

	// Guard: must have "hour" or "schedule" or "time" in its class, OR
	// be a small element with mostly day+time content (not a large footer/section).
	$class = strtolower( $node->getAttribute( 'class' ) );
	$has_hours_class = (bool) preg_match( '/(hour|schedule|time)/i', $class );

	// Count direct child elements — large containers shouldn't match.
	$child_count = 0;
	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_ELEMENT_NODE ) {
			$child_count++;
		}
	}

	$day_count = preg_match_all(
		'/(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|Mon|Tue|Wed|Thu|Fri|Sat|Sun)/i',
		$text
	);

	$time_count = preg_match_all(
		'/\d{1,2}(:\d{2})?\s*(am|pm)/i',
		$text
	);

	$closed_count = preg_match_all( '/Closed/i', $text );

	// Require 5+ day names for containers without an hours-related class.
	// This prevents large footer/section containers from being matched.
	$min_days = $has_hours_class ? 3 : 5;

	if ( $day_count < $min_days || ( $time_count + $closed_count ) < 2 ) {
		return null;
	}

	// Extra guard: if the container has many children AND no hours class, skip it.
	// A dedicated hours widget typically has 5-7 rows, not 10+ mixed children.
	if ( ! $has_hours_class && $child_count > 10 ) {
		return null;
	}

	$indent = str_repeat( '  ', $depth );

	ekwa_mc_warn( 'Auto-detected working hours → ekwa/hours (' . $day_count . ' days found)' );

	return $indent . '<!-- wp:ekwa/hours {"location":1} /-->' . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// NAVIGATION → core/navigation
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Detect <nav> with links → core/navigation block with navigation-link children.
 */
function ekwa_mc_detect_navigation( $node, $depth ) {
	$indent = str_repeat( '  ', $depth );

	// Collect all <a> links from the nav (direct or inside <ul>/<li>).
	$links = array();
	ekwa_mc_collect_nav_links( $node, $links );

	if ( empty( $links ) ) {
		return null;
	}

	// Build navigation block with navigation-link children.
	$output = $indent . '<!-- wp:navigation -->' . "\n";
	foreach ( $links as $link ) {
		$attrs = array(
			'label' => $link['label'],
			'url'   => $link['url'],
		);
		$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );
		$output .= $indent . '  <!-- wp:navigation-link' . $attrs_json . ' /-->' . "\n";
	}
	$output .= $indent . '<!-- /wp:navigation -->' . "\n";

	ekwa_mc_warn( 'Auto-detected <nav> → core/navigation (' . count( $links ) . ' links)' );

	return $output;
}

/**
 * Recursively collect links from a nav element.
 */
function ekwa_mc_collect_nav_links( $parent, &$links ) {
	foreach ( $parent->childNodes as $child ) {
		if ( $child->nodeType !== XML_ELEMENT_NODE ) {
			continue;
		}
		$tag = strtolower( $child->nodeName );

		if ( $tag === 'a' ) {
			$href  = $child->getAttribute( 'href' ) ?: '#';
			$label = trim( $child->textContent );
			if ( $label ) {
				$links[] = array( 'label' => $label, 'url' => $href );
			}
		} else {
			// Recurse into <ul>, <li>, <div>, etc.
			ekwa_mc_collect_nav_links( $child, $links );
		}
	}
}

// ═══════════════════════════════════════════════════════════════════════════════
// LOGO → core/site-logo
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Check if a node is inside a <footer> element.
 */
function ekwa_mc_is_inside_footer( $node ) {
	$parent = $node->parentNode;
	while ( $parent && $parent->nodeType === XML_ELEMENT_NODE ) {
		$ptag = strtolower( $parent->nodeName );
		if ( $ptag === 'footer' ) {
			return true;
		}
		$pclass = strtolower( $parent->getAttribute( 'class' ) ?: '' );
		if ( strpos( $pclass, 'footer' ) !== false ) {
			return true;
		}
		$parent = $parent->parentNode;
	}
	return false;
}

/**
 * Check if a class or alt string indicates a logo.
 */
function ekwa_mc_is_logo_context( $class, $alt = '' ) {
	$haystack = strtolower( $class . ' ' . $alt );
	return (bool) preg_match( '/\blogo\b/i', $haystack );
}

/**
 * Detect <a class="...logo..."><img ...></a> → core/site-logo block.
 * Only in header context — footer logos stay as ekwa/image.
 */
function ekwa_mc_detect_logo_link( $node, $depth ) {
	// Skip if inside a footer element.
	if ( ekwa_mc_is_inside_footer( $node ) ) {
		return null;
	}

	$class = $node->getAttribute( 'class' );

	// Check if the <a> itself has "logo" in its class.
	$has_logo_class = ekwa_mc_is_logo_context( $class );

	// Also check for an <img> child with logo context.
	$img = null;
	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_ELEMENT_NODE && strtolower( $child->nodeName ) === 'img' ) {
			$img = $child;
			break;
		}
	}

	if ( ! $img ) {
		return null;
	}

	$img_class = $img->getAttribute( 'class' );
	$img_alt   = $img->getAttribute( 'alt' );
	$has_logo_img = ekwa_mc_is_logo_context( $img_class, $img_alt );

	if ( ! $has_logo_class && ! $has_logo_img ) {
		return null;
	}

	$indent = str_repeat( '  ', $depth );
	$attrs  = array();

	// Try to extract width from img attributes.
	$width = $img->getAttribute( 'width' );
	if ( $width ) {
		$attrs['width'] = (int) $width;
	}

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	ekwa_mc_warn( 'Auto-detected logo → core/site-logo' );

	return $indent . '<!-- wp:site-logo' . $attrs_json . ' /-->' . "\n";
}

/**
 * Detect standalone <img> with logo context → core/site-logo.
 * Only in header context — footer logos stay as ekwa/image.
 */
function ekwa_mc_detect_logo_img( $node, $depth ) {
	if ( ekwa_mc_is_inside_footer( $node ) ) {
		return null;
	}

	$class = $node->getAttribute( 'class' );
	$alt   = $node->getAttribute( 'alt' );

	if ( ! ekwa_mc_is_logo_context( $class, $alt ) ) {
		return null;
	}

	$indent = str_repeat( '  ', $depth );
	$attrs  = array();

	$width = $node->getAttribute( 'width' );
	if ( $width ) {
		$attrs['width'] = (int) $width;
	}

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	ekwa_mc_warn( 'Auto-detected logo image → core/site-logo' );

	return $indent . '<!-- wp:site-logo' . $attrs_json . ' /-->' . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// MAP IFRAME → ekwa/map
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Detect <iframe src="...google.com/maps/embed..."> → ekwa/map block.
 */
function ekwa_mc_detect_map_iframe( $node, $depth ) {
	$src = $node->getAttribute( 'src' );

	if ( ! preg_match( '/(google\.com\/maps|maps\.google)/i', $src ) ) {
		return null;
	}

	$indent   = str_repeat( '  ', $depth );
	$iframe_html = ekwa_mc_get_outer_html( $node );

	// Extract height.
	$height = 450;
	$h = $node->getAttribute( 'height' );
	if ( $h && is_numeric( $h ) ) {
		$height = (int) $h;
	}

	$attrs = array(
		'embedCode' => $iframe_html,
		'height'    => $height,
	);

	$attrs_json = ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	ekwa_mc_warn( 'Auto-detected Google Maps iframe → ekwa/map' );

	return $indent . '<!-- wp:ekwa/map' . $attrs_json . ' /-->' . "\n";
}
