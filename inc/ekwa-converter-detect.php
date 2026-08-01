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

	// Explicit author tokens — data-ekwa="phone|address|hours|..." on any
	// element forces the mapping, no heuristics needed. data-ekwa="static"
	// opts the element OUT of all detection (escape hatch for false
	// positives). Mockup authors can pre-tag their HTML with these.
	if ( $node->hasAttribute( 'data-ekwa' ) ) {
		$token = strtolower( trim( $node->getAttribute( 'data-ekwa' ) ) );
		if ( 'static' === $token || 'ignore' === $token ) {
			return null; // Skip every detector; normal conversion continues.
		}
		$result = ekwa_mc_detect_token( $node, $depth, $token );
		if ( $result !== null ) {
			return $result;
		}
	}

	// Canonical rendered-markup signatures — a mockup written with the EXACT
	// structures the dynamic blocks render (see the mockup authoring kit in
	// Ekwa Settings → Design Setup) maps straight back to those blocks, and
	// the mockup's own CSS then styles the live output 1:1. Checked before
	// every heuristic.
	$result = ekwa_mc_detect_canonical( $node, $depth, $tag );
	if ( $result !== null ) {
		return $result;
	}

	// Container-class detections — must run BEFORE anchor-based phone/address
	// detectors AND before the inner <nav>/<a>/<i> children get visited so
	// dropdown wrappers, the search block, and the header menu stay as a
	// single block instead of being torn apart.
	if ( $tag === 'div' ) {
		$result = ekwa_mc_detect_header_menu( $node, $depth );
		if ( $result !== null ) {
			return $result;
		}

		$result = ekwa_mc_detect_phone_dropdown( $node, $depth );
		if ( $result !== null ) {
			return $result;
		}

		$result = ekwa_mc_detect_address_dropdown( $node, $depth );
		if ( $result !== null ) {
			return $result;
		}

		$result = ekwa_mc_detect_search( $node, $depth );
		if ( $result !== null ) {
			return $result;
		}
	}

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

		// Maps: <a href="...maps.google.com..."> — covers the modern short links
		// maps.app.goo.gl/… and goo.gl/maps/… as well as full maps URLs.
		if ( preg_match( '/(maps\.google|google\.com\/maps|maps\.app\.goo\.gl|goo\.gl\/maps|maps\.apple\.com|waze\.com)/i', $href ) ) {
			return ekwa_mc_detect_address( $node, $depth );
		}

		// Direction links whose href isn't a maps URL yet (mockups often use
		// "#"): text like "Get Directions" → ekwa/address in link mode, which
		// pulls the real maps URL from Ekwa Settings at render time.
		$link_text = trim( $node->textContent );
		if ( $link_text !== '' && preg_match( '/^(get\s+)?directions?$|^find\s+us$/i', $link_text ) ) {
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
// CANONICAL RENDERED-MARKUP SIGNATURES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Whether an element carries the given class (whole-token match, so
 * "ekwa-phone-number" never matches "ekwa-phone-number__link").
 *
 * @param DOMElement $node
 * @param string     $class
 * @return bool
 */
function ekwa_mc_node_has_class( $node, $class ) {
	$attr = $node->getAttribute( 'class' );
	return $attr && preg_match( '/(^|\s)' . preg_quote( $class, '/' ) . '(\s|$)/', $attr );
}

/**
 * Whether the node has a descendant of the given tag carrying the class.
 *
 * @param DOMElement $node
 * @param string     $tag
 * @param string     $class
 * @return bool
 */
function ekwa_mc_has_class_descendant( $node, $tag, $class ) {
	foreach ( $node->getElementsByTagName( $tag ) as $el ) {
		if ( ekwa_mc_node_has_class( $el, $class ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Canonical detection: the classes every dynamic block RENDERS double as
 * converter signatures. A mockup built from the canonical snippets
 * (nav.ekwa-header-nav menus, span.ekwa-phone-number, a.ekwa-address,
 * div.ekwa-working-hours, …) converts 100% into the matching dynamic blocks,
 * whole subtree consumed. Anything else falls through to the heuristics.
 *
 * @param DOMElement $node
 * @param int        $depth
 * @param string     $tag   Lowercased tag name.
 * @return string|null Block markup, '' to consume silently, null to continue.
 */
function ekwa_mc_detect_canonical( $node, $depth, $tag ) {
	$indent = str_repeat( '  ', $depth );

	$leaf = function ( $block, $attrs = array() ) use ( $indent ) {
		$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );
		ekwa_mc_warn( "Canonical markup → $block", 'dynamic' );
		return $indent . '<!-- wp:' . $block . $attrs_json . ' /-->' . "\n";
	};

	if ( 'nav' === $tag ) {
		// The header menu: nav.ekwa-header-nav, or any nav wrapping
		// ul.ekwa-header-menu. Consumes the whole subtree — including the
		// div.ekwa-megamenu variant, which the live block re-renders from the
		// assigned WP menu's item meta.
		if ( ekwa_mc_node_has_class( $node, 'ekwa-header-nav' )
			|| ekwa_mc_has_class_descendant( $node, 'ul', 'ekwa-header-menu' ) ) {
			// Hand the block the mockup's own class names so the header renders
			// with the selectors the mockup's stylesheet already targets.
			return $leaf( 'ekwa/header-menu', ekwa_mc_header_menu_attrs( $node ) );
		}
		// nav.ekwa-mobile-nav is rendered BY ekwa/hamburger-menu — emit nothing.
		if ( ekwa_mc_node_has_class( $node, 'ekwa-mobile-nav' ) ) {
			ekwa_mc_warn( 'Canonical markup: nav.ekwa-mobile-nav skipped — ekwa/hamburger-menu renders it.', 'dynamic' );
			return '';
		}
	}

	// Simple wrapper-class → block leaves. The image logo maps to core's
	// site-logo (its rendered markup: div.wp-block-site-logo > a.custom-logo-link
	// > img.custom-logo); the inline SVG logo maps to ekwa/svg-logo.
	static $map = array(
		'ekwa-phone-number'  => 'ekwa/phone',
		'ekwa-working-hours' => 'ekwa/hours',
		'ekwa-social-icons'  => 'ekwa/social',
		'ekwa-copyright'     => 'ekwa/copyright',
		'ekwa-svg-logo'      => 'ekwa/svg-logo',
		'wp-block-site-logo' => 'site-logo',
		'custom-logo-link'   => 'site-logo',
		'ekwa-hamburger-btn' => 'ekwa/hamburger-menu',
	);
	foreach ( $map as $sig => $block ) {
		if ( ekwa_mc_node_has_class( $node, $sig ) ) {
			return $leaf( $block );
		}
	}

	// Address: the mode travels in the ekwa-address--{mode} modifier class.
	if ( ekwa_mc_node_has_class( $node, 'ekwa-address' ) ) {
		$attrs = array();
		if ( preg_match( '/(^|\s)ekwa-address--(icon|text|address|full)(\s|$)/', $node->getAttribute( 'class' ), $m ) ) {
			$attrs['mode'] = $m[2];
		}
		return $leaf( 'ekwa/address', $attrs );
	}

	return null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXPLICIT TOKENS — data-ekwa="..."
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Forced mapping via data-ekwa tokens. The element (and its subtree) is
 * replaced by the corresponding dynamic block; real content comes from Ekwa
 * Settings at render time.
 *
 * Vocabulary (put on any element in the mockup HTML):
 *   phone, phone-dropdown, address, address-dropdown, hours, social,
 *   copyright, logo, menu, navigation, search, hamburger, map, scroll-top,
 *   static|ignore (handled by the orchestrator — skips detection entirely)
 *
 * Optional modifier attributes:
 *   data-ekwa-location="2"        → location index (phone, address, hours)
 *   data-ekwa-mode="full"         → address mode (icon|text|address|full)
 *   data-ekwa-prefix="Call us:"   → phone prefix
 *   data-ekwa-type="existing"     → phone type (new|existing)
 *
 * @param DOMElement $node
 * @param int        $depth
 * @param string     $token Lowercased data-ekwa value.
 * @return string|null Block markup, or null for unknown tokens.
 */
function ekwa_mc_detect_token( $node, $depth, $token ) {
	$indent   = str_repeat( '  ', $depth );
	// No WP functions here — this file also runs under the CLI without WordPress.
	$location = max( 1, (int) ( $node->getAttribute( 'data-ekwa-location' ) ?: 1 ) );

	$leaf = function ( $block, $attrs = array() ) use ( $indent, $token ) {
		$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );
		ekwa_mc_warn( "data-ekwa=\"$token\" token → $block" );
		return $indent . '<!-- wp:' . $block . $attrs_json . ' /-->' . "\n";
	};

	switch ( $token ) {
		case 'phone':
			$attrs = array( 'location' => $location );
			$type  = strtolower( trim( $node->getAttribute( 'data-ekwa-type' ) ) );
			if ( in_array( $type, array( 'new', 'existing' ), true ) ) {
				$attrs['type'] = $type;
			}
			$prefix = trim( $node->getAttribute( 'data-ekwa-prefix' ) );
			if ( $prefix !== '' ) {
				$attrs['prefix'] = $prefix;
			}
			return $leaf( 'ekwa/phone', $attrs );

		case 'phone-dropdown':
			return $leaf( 'ekwa/phone-dropdown' );

		case 'address':
			$attrs = array( 'location' => $location );
			$mode  = strtolower( trim( $node->getAttribute( 'data-ekwa-mode' ) ) );
			if ( in_array( $mode, array( 'icon', 'text', 'address', 'full' ), true ) ) {
				$attrs['mode'] = $mode;
			}
			return $leaf( 'ekwa/address', $attrs );

		case 'address-dropdown':
			return $leaf( 'ekwa/address-dropdown' );

		case 'hours':
			return $leaf( 'ekwa/hours', array( 'location' => $location ) );

		case 'social':
			return $leaf( 'ekwa/social', array( 'showShare' => false ) );

		case 'copyright':
			return $leaf( 'ekwa/copyright' );

		case 'logo':
			return $leaf( 'ekwa/svg-logo' );

		case 'menu':
			// data-ekwa="menu" forces the mapping on any markup — read its
			// classes so the block can wear them (see ekwa_mc_menu_class_map()).
			return $leaf( 'ekwa/header-menu', ekwa_mc_header_menu_attrs( $node ) );

		case 'navigation':
			return $leaf( 'core/navigation' );

		case 'search':
			return $leaf( 'ekwa/search' );

		case 'hamburger':
			return $leaf( 'ekwa/hamburger-menu' );

		case 'map':
			return $leaf( 'ekwa/map' );

		case 'scroll-top':
			return $leaf( 'ekwa/scroll-top' );
	}

	ekwa_mc_warn( "Unknown data-ekwa token \"$token\" — element converted normally." );
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

	// Mixed-container guard: a real hours widget holds ONLY hours. If this
	// container also contains a phone (tel:), a maps/directions link, a form,
	// or a button/CTA, it's a mixed block — e.g. a footer contact band with
	// address + phone + hours + "Book" side by side. Matching it here would
	// SWALLOW those siblings into a single ekwa/hours. Skip so the inner
	// elements convert individually (their own detectors still fire).
	foreach ( $node->getElementsByTagName( 'a' ) as $a ) {
		$href = $a->getAttribute( 'href' );
		if ( stripos( $href, 'tel:' ) === 0
			|| preg_match( '/(maps\.google|google\.com\/maps|maps\.app\.goo\.gl|goo\.gl\/maps)/i', $href ) ) {
			return null;
		}
	}
	if ( $node->getElementsByTagName( 'button' )->length > 0
		|| $node->getElementsByTagName( 'form' )->length > 0
		|| $node->getElementsByTagName( 'input' )->length > 0 ) {
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

// ═══════════════════════════════════════════════════════════════════════════════
// SEARCH → ekwa/search
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Detect <div class="ekwa-search-block"> → ekwa/search block.
 * Returns early without descending into children — the inner button/SVG are
 * rendered by the block at runtime.
 */
function ekwa_mc_detect_search( $node, $depth ) {
	$class = $node->getAttribute( 'class' );
	if ( ! $class ) {
		return null;
	}
	if ( ! preg_match( '/(^|\s)ekwa-search-block(\s|$)/', $class ) ) {
		return null;
	}

	ekwa_mc_warn( 'Auto-detected ekwa-search-block → ekwa/search' );

	// The block re-emits `ekwa-search-block` itself; carry any OTHER classes
	// over so the mockup's own positioning (e.g. `.search-icon`) still applies.
	$attrs = ekwa_mc_extra_classes_attr( $node, array( 'ekwa-search-block' ) );

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	return str_repeat( '  ', $depth ) . '<!-- wp:ekwa/search' . $attrs_json . ' /-->' . "\n";
}

/**
 * ekwa/header-menu attributes for a mockup nav — the class map plus the
 * structural switches. Safe to call before the menu module has loaded.
 *
 * @param DOMElement $node The nav (or list) element.
 * @return array
 */
function ekwa_mc_header_menu_attrs( $node ) {
	if ( ! function_exists( 'ekwa_mc_menu_block_attrs' ) ) {
		$file = get_template_directory() . '/inc/ekwa-converter-menu.php';
		if ( ! file_exists( $file ) ) {
			return array();
		}
		require_once $file;
	}
	return ekwa_mc_menu_block_attrs( $node );
}

/**
 * Build a `className` attribute array from an element's classes, minus the
 * canonical signature class(es) the block already renders itself.
 *
 * @param DOMElement $node
 * @param string[]   $signatures Classes to drop.
 * @return array Empty, or array{className:string}.
 */
function ekwa_mc_extra_classes_attr( $node, $signatures ) {
	$classes = preg_split( '/\s+/', (string) $node->getAttribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
	$extra   = array_values( array_diff( $classes, $signatures ) );
	return empty( $extra ) ? array() : array( 'className' => implode( ' ', $extra ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// PHONE DROPDOWN → ekwa/phone-dropdown
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Detect <div class="ekwa-phone-dd"> → ekwa/phone-dropdown block.
 * Reads label and iconClass from the trigger <button>; returns early so the
 * inner <a href="tel:"> children are NOT picked up by ekwa_mc_detect_phone.
 */
function ekwa_mc_detect_phone_dropdown( $node, $depth ) {
	$class = $node->getAttribute( 'class' );
	if ( ! $class ) {
		return null;
	}
	if ( ! preg_match( '/(^|\s)ekwa-phone-dd(\s|$)/', $class ) ) {
		return null;
	}

	$attrs = ekwa_mc_extract_dropdown_attrs( $node, 'Call Us', 'fa-solid fa-phone' );

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	ekwa_mc_warn( 'Auto-detected ekwa-phone-dd → ekwa/phone-dropdown' );

	return str_repeat( '  ', $depth ) . '<!-- wp:ekwa/phone-dropdown' . $attrs_json . ' /-->' . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// ADDRESS DROPDOWN → ekwa/address-dropdown
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Detect <div class="ekwa-addr-dd"> → ekwa/address-dropdown block.
 * Reads label and iconClass from the trigger <button>; returns early so the
 * inner <a href="…maps…"> children are NOT picked up by ekwa_mc_detect_address.
 */
function ekwa_mc_detect_address_dropdown( $node, $depth ) {
	$class = $node->getAttribute( 'class' );
	if ( ! $class ) {
		return null;
	}
	if ( ! preg_match( '/(^|\s)ekwa-addr-dd(\s|$)/', $class ) ) {
		return null;
	}

	$attrs = ekwa_mc_extract_dropdown_attrs( $node, 'Directions', 'fa-solid fa-location-dot' );

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	ekwa_mc_warn( 'Auto-detected ekwa-addr-dd → ekwa/address-dropdown' );

	return str_repeat( '  ', $depth ) . '<!-- wp:ekwa/address-dropdown' . $attrs_json . ' /-->' . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// HEADER MENU → ekwa/header-menu
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Detect <div class="ekwa-header-menu-wrap"> → ekwa/header-menu block.
 * Menu items come from the WP Main Menu admin location, so the detector only
 * needs to recognise the wrapper and forward its three CSS-variable–driven
 * attributes. Returns early so the inner <nav>/<ul>/<a> are NOT picked up by
 * the core/navigation detector or anchor converters.
 */
function ekwa_mc_detect_header_menu( $node, $depth ) {
	$class = $node->getAttribute( 'class' );
	if ( ! $class ) {
		return null;
	}
	if ( ! preg_match( '/(^|\s)ekwa-header-menu-wrap(\s|$)/', $class ) ) {
		return null;
	}

	$attrs = array();
	$style = ekwa_mc_parse_inline_style( $node->getAttribute( 'style' ) );

	// CSS variables map to block attributes. Only emit non-default values.
	if ( isset( $style['--ekwa-header-align'] ) ) {
		$val = trim( $style['--ekwa-header-align'] );
		if ( $val !== '' && $val !== 'center' ) {
			$attrs['alignment'] = $val;
		}
	}
	if ( isset( $style['--ekwa-header-gap'] ) ) {
		$gap = (int) preg_replace( '/[^0-9]/', '', $style['--ekwa-header-gap'] );
		if ( $gap > 0 && $gap !== 24 ) {
			$attrs['itemGap'] = $gap;
		}
	}
	if ( isset( $style['--ekwa-submenu-minw'] ) ) {
		$minw = (int) preg_replace( '/[^0-9]/', '', $style['--ekwa-submenu-minw'] );
		if ( $minw > 0 && $minw !== 220 ) {
			$attrs['submenuMinWidth'] = $minw;
		}
	}

	// The nav inside the wrapper carries the mockup's own class names.
	$inner = $node->getElementsByTagName( 'nav' )->item( 0 );
	if ( ! $inner ) {
		$inner = $node->getElementsByTagName( 'ul' )->item( 0 );
	}
	if ( $inner ) {
		$attrs = array_merge( $attrs, ekwa_mc_header_menu_attrs( $inner ) );
	}

	$attrs_json = empty( $attrs ) ? '' : ' ' . ekwa_mc_json_encode_block_attrs( $attrs );

	ekwa_mc_warn( 'Auto-detected ekwa-header-menu-wrap → ekwa/header-menu' );

	return str_repeat( '  ', $depth ) . '<!-- wp:ekwa/header-menu' . $attrs_json . ' /-->' . "\n";
}

/**
 * Shared trigger-button parser for phone-dropdown and address-dropdown
 * detectors. Extracts the label text and icon class from the first <button>
 * child and returns a block-attributes array containing only non-default
 * values.
 *
 * @param DOMElement $node           The dropdown container.
 * @param string     $default_label  Block's default label (e.g. "Call Us").
 * @param string     $default_icon   Block's default iconClass.
 * @return array<string,mixed>
 */
function ekwa_mc_extract_dropdown_attrs( $node, $default_label, $default_icon ) {
	$attrs = array();

	$btns = $node->getElementsByTagName( 'button' );
	if ( $btns->length === 0 ) {
		return $attrs;
	}
	$btn = $btns->item( 0 );

	// Icon: look for any <i class="fa-…"> inside the button. No icon → showIcon=false.
	$icons     = $btn->getElementsByTagName( 'i' );
	$has_icon  = false;
	$icon_text = '';
	if ( $icons->length > 0 ) {
		$ic = $icons->item( 0 )->getAttribute( 'class' );
		if ( $ic && preg_match( '/\bfa[srlbd]?\s+fa-[a-z0-9-]+/i', $ic ) ) {
			$has_icon  = true;
			$icon_text = trim( $icons->item( 0 )->textContent );
			if ( $ic !== $default_icon ) {
				$attrs['iconClass'] = $ic;
			}
		}
	}
	if ( ! $has_icon ) {
		$attrs['showIcon'] = false;
	}

	// Label: prefer text inside <span>, then full button text minus icon text.
	$label = '';
	$spans = $btn->getElementsByTagName( 'span' );
	if ( $spans->length > 0 ) {
		$label = trim( $spans->item( 0 )->textContent );
	}
	if ( '' === $label ) {
		$label = trim( $btn->textContent );
		// Trim trailing "(arrow svg text)" or stray whitespace — but textContent
		// already drops SVG inner text in most cases. Belt-and-braces: collapse runs of whitespace.
		$label = preg_replace( '/\s+/u', ' ', $label );
	}
	if ( $label && $label !== $default_label ) {
		$attrs['label'] = $label;
	}

	return $attrs;
}
