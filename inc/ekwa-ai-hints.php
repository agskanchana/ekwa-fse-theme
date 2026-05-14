<?php
/**
 * Block Markup Hints registry for the AI HTML generator.
 *
 * For each dynamic block that the converter's detector recognises, this file
 * holds one entry describing the detector pattern and a list of example HTML
 * snippets the AI should follow. The registry is filterable so child themes
 * or mu-plugins can append markup variations.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of block markup hints, keyed by short slug.
 *
 * Each entry:
 *   - block:       Block name shown to the AI (informational).
 *   - contexts:    Which generation contexts the hint applies to
 *                  ('header', 'footer', 'page'). Empty = all.
 *   - description: One-line description of the detector pattern.
 *   - examples:    Array of HTML snippets the AI can emit verbatim or vary.
 *
 * @return array<string,array<string,mixed>>
 */
function ekwa_ai_block_hints_registry() {
	$hints = array(

		// core/site-logo (header only — detector skips footers).
		'site-logo' => array(
			'block'       => 'core/site-logo',
			'contexts'    => array( 'header' ),
			'description' => 'Site logo. Detector triggers for ANY <a class="*logo*"> wrapping an <img>, OR an <img class="*logo*">, OR an <img alt="…logo…">. Do not output the verbose wp-block-site-logo markup — just emit one of the simple forms below; WordPress renders the full markup at runtime.',
			'examples'    => array(
				'<a href="#" class="logo-link"><img src="https://placehold.co/240x60" alt="Acme Dental logo" width="240" height="60"></a>',
				'<a href="#" class="site-logo"><img src="https://placehold.co/200x60" alt="logo"></a>',
			),
		),

		// ekwa/search (new detector — class signature).
		'search' => array(
			'block'       => 'ekwa/search',
			'contexts'    => array( 'header' ),
			'description' => 'Search trigger button (opens overlay modal). Detector triggers for ANY <div> with class "ekwa-search-block". Just emit that wrapper with an inner button — block re-renders the SVG / overlay at runtime.',
			'examples'    => array(
				'<div class="ekwa-search-block"><button type="button" aria-label="Open Search">Search</button></div>',
			),
		),

		// ekwa/phone (existing detector — tel: link).
		'phone' => array(
			'block'       => 'ekwa/phone',
			'contexts'    => array( 'header', 'footer', 'page' ),
			'description' => 'Single phone number. Detector triggers for any <a href="tel:…">. Prefix text before the digits is captured into the "prefix" attribute; words "new" or "existing" set the patient type; icon class is read from any nested <i class="fa-…">. Variations: with/without prefix label, with/without icon, any Font Awesome icon class.',
			'examples'    => array(
				'<a href="tel:+15551234567"><i class="fa-solid fa-phone"></i> New Patients: (555) 123-4567</a>',
				'<a href="tel:+15559871234">Existing Patients: (555) 987-1234</a>',
				'<a href="tel:+15551234567"><i class="fa-solid fa-mobile-screen"></i> (555) 123-4567</a>',
			),
		),

		// ekwa/phone-dropdown (new detector — class signature).
		// Detector for this MUST run before ekwa/phone or each inner tel: link gets picked off individually.
		'phone-dropdown' => array(
			'block'       => 'ekwa/phone-dropdown',
			'contexts'    => array( 'header' ),
			'description' => 'Multi-location / multi-line phone dropdown. Detector triggers for any <div> with class "ekwa-phone-dd". Trigger label, icon, and inner location/line content are rendered at runtime from settings — emit the wrapper with a trigger button only. Variations: label text can change ("Call Us", "Reach Us"), icon class can change, icon can be omitted.',
			'examples'    => array(
				'<div class="ekwa-phone-dd"><button type="button"><i class="fa-solid fa-phone"></i> Call Us</button></div>',
				'<div class="ekwa-phone-dd"><button type="button">Reach Us</button></div>',
			),
		),

		// ekwa/address (existing detector — maps link).
		'address' => array(
			'block'       => 'ekwa/address',
			'contexts'    => array( 'header', 'footer', 'page' ),
			'description' => 'Single directions / address link. Detector triggers for any <a href="…maps.google.com…|…goo.gl/maps…|…maps.apple.com…|…waze.com…">. Detector picks mode from link text: full street address with state+zip → mode=full; "Directions" → mode=text; "City, ST" → mode=address. Icon class is read from any nested <i class="fa-…">.',
			'examples'    => array(
				'<a href="https://maps.google.com/?q=1510+Barton+Rd+Redlands+CA"><i class="fa-solid fa-location-dot"></i> 1510 Barton Rd, Redlands, CA 92373</a>',
				'<a href="https://maps.google.com/?q=Redlands+CA">Redlands, CA</a>',
				'<a href="https://maps.google.com/?q=address"><i class="fa-solid fa-diamond-turn-right"></i> Directions</a>',
			),
		),

		// ekwa/address-dropdown (new detector — class signature).
		// Detector for this MUST run before ekwa/address or each inner maps link gets picked off individually.
		'address-dropdown' => array(
			'block'       => 'ekwa/address-dropdown',
			'contexts'    => array( 'header', 'footer' ),
			'description' => 'Multi-location directions dropdown. Detector triggers for any <div> with class "ekwa-addr-dd". Trigger label, icon, and per-location addresses are rendered from settings at runtime — emit the wrapper with a trigger button only. Variations: label can change ("Directions", "Our Locations"), icon class can change, icon can be omitted.',
			'examples'    => array(
				'<div class="ekwa-addr-dd"><button type="button"><i class="fa-solid fa-location-dot"></i> Directions</button></div>',
				'<div class="ekwa-addr-dd"><button type="button">Our Locations</button></div>',
			),
		),

		// ekwa/social (existing detector — 2+ social domain anchors).
		'social' => array(
			'block'       => 'ekwa/social',
			'contexts'    => array( 'header', 'footer' ),
			'description' => 'Social icons row. Detector triggers for any container (div/nav/ul/section/footer) with 2+ <a> children linking to facebook.com / instagram.com / x.com / youtube.com / linkedin.com / pinterest.com / tiktok.com / yelp.com / threads.net / nextdoor.com / snapchat.com. Inline font-size styles on the <i> get reflected into block icon size.',
			'examples'    => array(
				'<div class="social-icons"><a href="https://facebook.com/x" aria-label="Facebook"><i class="fa-brands fa-facebook"></i></a><a href="https://instagram.com/x" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a><a href="https://youtube.com/@x" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a></div>',
				'<div class="social"><a href="https://facebook.com/x"><i class="fa-brands fa-facebook" style="font-size:30px"></i></a><a href="https://x.com/x"><i class="fa-brands fa-x-twitter" style="font-size:30px"></i></a></div>',
			),
		),

		// ekwa/hours (existing detector — 5+ day names + times).
		'hours' => array(
			'block'       => 'ekwa/hours',
			'contexts'    => array( 'header', 'footer' ),
			'description' => 'Working hours. Detector triggers for a container (div/section/table/dl/ul) containing 5+ weekday names AND 2+ time-or-Closed tokens. Container can be a list, table, definition list, or div rows. Day grouping (e.g. "Monday – Thursday" or "Monday, Tuesday, Wednesday") is fine — the block re-formats at runtime.',
			'examples'    => array(
				'<ul class="hours"><li>Monday: 9:00 AM – 5:00 PM</li><li>Tuesday: 9:00 AM – 5:00 PM</li><li>Wednesday: 9:00 AM – 5:00 PM</li><li>Thursday: 9:00 AM – 5:00 PM</li><li>Friday: 9:00 AM – 3:00 PM</li><li>Saturday: Closed</li><li>Sunday: Closed</li></ul>',
				'<div class="working-hours"><div><span>Monday – Thursday</span><span>9:00 AM – 5:00 PM</span></div><div><span>Friday</span><span>9:00 AM – 3:00 PM</span></div><div><span>Saturday</span><span>Closed</span></div><div><span>Sunday</span><span>Closed</span></div></div>',
			),
		),

		// ekwa/map (existing detector — google maps embed iframe).
		'map' => array(
			'block'       => 'ekwa/map',
			'contexts'    => array( 'footer', 'page' ),
			'description' => 'Google Map embed. Detector triggers for any <iframe> whose src contains "google.com/maps" (real or placeholder). Height attribute is preserved.',
			'examples'    => array(
				'<iframe src="https://www.google.com/maps/embed?pb=PLACEHOLDER" width="100%" height="450" loading="lazy" title="Map"></iframe>',
			),
		),

		// ekwa/header-menu (new detector — class signature).
		// Detector for this MUST run before core/navigation or the inner <nav>
		// gets picked off as a plain navigation block.
		'header-menu' => array(
			'block'       => 'ekwa/header-menu',
			'contexts'    => array( 'header' ),
			'description' => 'Primary header navigation with multi-level submenus and optional mega-menu items. Detector triggers for any <div> with class "ekwa-header-menu-wrap". Menu items come from the WordPress Main Menu admin location at runtime — DO NOT embed real menu items, just emit the wrapper. Three CSS variables on the wrapper map to block attributes: --ekwa-header-align (alignment), --ekwa-header-gap (itemGap px), --ekwa-submenu-minw (submenuMinWidth px). Use this for the SITE\'S PRIMARY HEADER MENU; for a secondary utility nav (e.g. top-bar links, skip links, breadcrumbs) use the plain <nav> hint below.',
			'examples'    => array(
				'<div class="ekwa-header-menu-wrap" style="--ekwa-header-align:center;--ekwa-header-gap:24px;--ekwa-submenu-minw:220px;"><nav class="ekwa-header-nav" aria-label="Main Navigation"></nav></div>',
				'<div class="ekwa-header-menu-wrap" style="--ekwa-header-align:flex-start;--ekwa-header-gap:32px;"><nav class="ekwa-header-nav" aria-label="Main Navigation"></nav></div>',
			),
		),

		// core/navigation (existing detector — <nav> with anchors).
		'navigation' => array(
			'block'       => 'core/navigation',
			'contexts'    => array( 'header', 'footer' ),
			'description' => 'Plain navigation list — use for the FOOTER site nav, or for SECONDARY header nav (utility/top-bar links, skip links, breadcrumbs). Do NOT use this for the primary header menu — use ekwa/header-menu (ekwa-header-menu-wrap) instead. Detector triggers for any <nav> containing <a> descendants. Anchors inside <ul><li> are recursively collected. The verbose wp-block-navigation markup is rendered at runtime — just emit the simple <nav> form.',
			'examples'    => array(
				'<nav aria-label="Footer menu"><a href="/">Home</a><a href="/about">About</a><a href="/services">Services</a><a href="/contact">Contact</a></nav>',
				'<nav><ul><li><a href="/">Home</a></li><li><a href="/about">About</a></li><li><a href="/services">Services</a></li><li><a href="/contact">Contact</a></li></ul></nav>',
			),
		),

		// ekwa/copyright (existing detector — © YYYY in small element).
		'copyright' => array(
			'block'       => 'ekwa/copyright',
			'contexts'    => array( 'footer' ),
			'description' => 'Copyright line. Detector triggers for a small element (p / div / span / small with ≤3 element children) containing "© YYYY". Surrounding "Powered by …" text is fine.',
			'examples'    => array(
				'<p class="copyright">© 2026 Walden Dental. All rights reserved. Powered by <a href="https://www.ekwa.com">www.ekwa.com</a></p>',
			),
		),

	);

	return apply_filters( 'ekwa_ai_block_hints', $hints );
}

/**
 * Build the "BLOCK MARKUP HINTS" section appended to the AI system prompt.
 *
 * @param string $context One of: 'header', 'footer', 'page'. Anything else
 *                        falls back to 'page'.
 * @return string Empty string when no hints apply.
 */
function ekwa_ai_build_hints_section( $context ) {
	$context = in_array( $context, array( 'header', 'footer', 'page' ), true ) ? $context : 'page';
	$all     = ekwa_ai_block_hints_registry();

	$applicable = array();
	foreach ( $all as $key => $hint ) {
		if ( empty( $hint['contexts'] ) || in_array( $context, $hint['contexts'], true ) ) {
			$applicable[ $key ] = $hint;
		}
	}
	if ( empty( $applicable ) ) {
		return '';
	}

	$out  = "\n\nBLOCK MARKUP HINTS (context: " . strtoupper( $context ) . "):\n";
	$out .= "REQUIRED: whenever the user asks for one of the elements below — by name or by intent (e.g. \"menu\", \"nav\", \"phone\", \"logo\", \"search\", \"hours\", \"address\", \"map\", \"social\", \"copyright\") — you MUST emit the signature pattern shown. Match the structural signal (href scheme, signature class, container shape) exactly so the deterministic converter recognises the block. Visible text, ordering, surrounding wrappers, layout, and styling are free. Use realistic placeholder content (real-looking phone numbers, sample hours, alt=\"logo\") so the detector triggers; real per-location data is filled in at runtime from block settings. DO NOT output the verbose rendered Gutenberg/WP markup (e.g. wp-block-site-logo, ekwa-phone-number__link) — emit the simple input forms below. DO NOT silently drop a requested element; if the user mentioned it, it must appear.\n\n";

	foreach ( $applicable as $hint ) {
		$out .= '- ' . $hint['block'] . ' — ' . $hint['description'] . "\n";
		foreach ( $hint['examples'] as $ex ) {
			$out .= '  Example: ' . $ex . "\n";
		}
	}

	return $out;
}
