<?php
/**
 * Mockup Contract — canonical markup library, readiness checker, and the
 * downloadable Author Guide / starter template.
 *
 * Every ekwa dynamic block renders fixed markup. When a mockup is written
 * with those EXACT structures, two things follow: (1) the converter maps the
 * markup straight back to the dynamic blocks (see ekwa_mc_detect_canonical in
 * inc/ekwa-converter-detect.php), and (2) the mockup's own CSS styles the
 * live rendered blocks 1:1 with zero adaptation. This file is the single
 * source of truth for those structures:
 *
 *   - ekwa_mockup_canonical_snippets()  — the snippet library
 *   - ekwa_mockup_readiness_check()     — whole-file pre-flight analyzer
 *   - admin-post handlers               — Author Guide + starter downloads
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The canonical snippet library.
 *
 * Each entry: label, block (what it converts to), token (data-ekwa fallback),
 * expected (header|footer|either|optional — drives the readiness check),
 * signature (class that marks it canonical, '' when heuristic-only),
 * snippet (the exact markup the block renders), notes.
 *
 * @return array<string,array<string,string>>
 */
function ekwa_mockup_canonical_snippets() {
	return array(

		'menu' => array(
			'label'     => __( 'Header menu', 'ekwa' ),
			'block'     => 'ekwa/header-menu',
			'token'     => 'menu',
			'group'     => 'header',
			'expected'  => 'header',
			'signature' => 'ekwa-header-nav',
			'snippet'   => '<nav class="ekwa-header-nav" aria-label="Main Navigation">
  <ul class="ekwa-header-menu">
    <li class="menu-item"><a href="/"><span class="ekwa-menu-label">Home</span></a></li>
    <li class="menu-item menu-item-has-children">
      <a href="/about/" aria-haspopup="true" aria-expanded="false">
        <span class="ekwa-menu-label">About</span><span class="ekwa-caret" aria-hidden="true"></span>
      </a>
      <ul class="sub-menu">
        <li class="menu-item"><a href="/team/"><span class="ekwa-menu-label">Meet the Team</span></a></li>
        <li class="menu-item"><a href="/doctor/"><span class="ekwa-menu-label">Meet the Doctor</span></a></li>
      </ul>
    </li>
    <li class="menu-item"><a href="/contact/"><span class="ekwa-menu-label">Contact</span></a></li>
  </ul>
</nav>',
			'megamenu'  => '<!-- A megamenu item: same nav/ul as above, but the parent <li> has class
     "menu-item-megamenu" and its children live in a div.ekwa-megamenu (not a ul.sub-menu). -->
<li class="menu-item menu-item-has-children menu-item-megamenu">
  <a href="/services/" aria-haspopup="true" aria-expanded="false">
    <span class="ekwa-menu-label">Services</span><span class="ekwa-caret" aria-hidden="true"></span>
  </a>
  <div class="ekwa-megamenu" style="--ekwa-mega-cols:3">
    <div class="ekwa-megamenu-grid">
      <div class="ekwa-megamenu-column has-image">
        <div class="ekwa-megamenu-image-wrap">
          <img class="ekwa-megamenu-image" src="cosmetic-dentistry.jpg" alt="Cosmetic dentistry">
        </div>
        <a class="ekwa-megamenu-heading" href="/cosmetic-dentistry/">Cosmetic Dentistry</a>
        <ul class="ekwa-megamenu-list">
          <li class="menu-item"><a href="/invisalign/"><span class="ekwa-menu-label">Invisalign</span></a></li>
          <li class="menu-item"><a href="/veneers/"><span class="ekwa-menu-label">Dental Veneers</span></a></li>
        </ul>
      </div>
      <div class="ekwa-megamenu-column">
        <a class="ekwa-megamenu-heading" href="/general-dentistry/">General Dentistry</a>
        <ul class="ekwa-megamenu-list">
          <li class="menu-item"><a href="/cleanings/"><span class="ekwa-menu-label">Cleanings</span></a></li>
          <li class="menu-item"><a href="/fillings/"><span class="ekwa-menu-label">Fillings</span></a></li>
        </ul>
      </div>
    </div>
  </div>
</li>',
			'notes'     => __( 'Converts to the live WP menu (build it under Appearance → Menus). The actual menu items and megamenu configuration come from WordPress; the mockup markup exists only so your CSS matches the rendered structure exactly. Two variants: a normal dropdown uses ul.sub-menu; a megamenu uses the menu-item-megamenu / div.ekwa-megamenu structure shown below.', 'ekwa' ),
		),

		'logo' => array(
			'label'      => __( 'Logo (image)', 'ekwa' ),
			'block'      => 'core/site-logo',
			'token'      => 'logo',
			'group'      => 'header',
			'expected'   => 'header',
			'signature'  => 'custom-logo-link',
			'snippet'    => '<div class="wp-block-site-logo">
  <a href="/" class="custom-logo-link" rel="home">
    <img class="custom-logo" src="practice-logo.webp" alt="Practice name" width="320" height="89">
  </a>
</div>',
			'alt_label'  => __( 'SVG logo variant', 'ekwa' ),
			'alt_snippet' => '<a href="/" class="ekwa-svg-logo" aria-label="Practice name">
  <svg viewBox="0 0 200 48"><!-- logo paths --></svg>
</a>',
			'notes'      => __( 'Most logos are an image → this maps to core/site-logo (the real logo comes from the WordPress site logo / Ekwa Settings → Branding; the mockup image is a stand-in for styling — style .custom-logo). A simple <a class="logo"><img></a> is also auto-detected. Use the SVG variant below only for an inline vector logo (renders as ekwa/svg-logo).', 'ekwa' ),
		),

		'phone' => array(
			'label'     => __( 'Phone number', 'ekwa' ),
			'block'     => 'ekwa/phone',
			'token'     => 'phone',
			'group'     => 'header',
			'expected'  => 'either',
			'signature' => 'ekwa-phone-number',
			'snippet'   => '<span class="ekwa-phone-number">
  <a class="ekwa-phone-number__link" href="tel:+15551234567">
    <i class="fa-solid fa-phone" aria-hidden="true"></i>
    <span class="ekwa-phone-number__prefix">New Patients:</span> (555) 123-4567
  </a>
</span>',
			'notes'     => __( 'Usable in the header or footer. Plain tel: links are also auto-detected, but the canonical wrapper is what the block renders — style .ekwa-phone-number and you style the live site. Real numbers come from Ekwa Settings → Locations.', 'ekwa' ),
		),

		'search' => array(
			'label'     => __( 'Search (popup trigger)', 'ekwa' ),
			'block'     => 'ekwa/search',
			'token'     => 'search',
			'group'     => 'header',
			'expected'  => 'optional',
			'signature' => 'ekwa-search-block',
			'snippet'   => '<div class="ekwa-search-block">
  <button class="ekwa-search-trigger" aria-label="Search">
    <svg viewBox="0 0 24 24" width="20" height="20"><circle cx="10" cy="10" r="7" fill="none" stroke="currentColor" stroke-width="2"/><line x1="15" y1="15" x2="21" y2="21" stroke="currentColor" stroke-width="2"/></svg>
  </button>
</div>',
			'notes'     => __( 'The block renders the trigger; the full-screen search overlay is added automatically on the live site (do not design the overlay).', 'ekwa' ),
		),

		'address' => array(
			'label'     => __( 'Address / directions link', 'ekwa' ),
			'block'     => 'ekwa/address',
			'token'     => 'address',
			'group'     => 'footer',
			'expected'  => 'footer',
			'signature' => 'ekwa-address',
			'snippet'   => '<a class="ekwa-address ekwa-address--full" href="#" aria-label="Get directions">
  <i class="ekwa-address__icon fa-solid fa-location-dot" aria-hidden="true"></i>
  123 Main Street, Suite 200, Austin, TX 78701
</a>',
			'notes'     => __( 'Modifier class picks the display mode: ekwa-address--icon | --text | --address | --full. The real address and maps link come from Ekwa Settings → Locations.', 'ekwa' ),
		),

		'hours' => array(
			'label'     => __( 'Working hours', 'ekwa' ),
			'block'     => 'ekwa/hours',
			'token'     => 'hours',
			'group'     => 'footer',
			'expected'  => 'footer',
			'signature' => 'ekwa-working-hours',
			'snippet'   => '<div class="ekwa-working-hours">
  <div class="ekwa-working-hours__list">
    <div class="ekwa-working-hours__row"><span>Monday – Friday</span><span>8am – 6pm</span></div>
    <div class="ekwa-working-hours__row ekwa-working-hours__row--closed"><span>Sunday</span><span>Closed</span></div>
  </div>
</div>',
			'notes'     => __( 'Real hours come from Ekwa Settings → Locations.', 'ekwa' ),
		),

		'social' => array(
			'label'     => __( 'Social icons', 'ekwa' ),
			'block'     => 'ekwa/social',
			'token'     => 'social',
			'group'     => 'footer',
			'expected'  => 'footer',
			'signature' => 'ekwa-social-icons',
			'snippet'   => '<div class="ekwa-social-icons">
  <div class="social-media">
    <a class="sm-icons" href="https://facebook.com/example" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
    <a class="sm-icons" href="https://instagram.com/example" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
  </div>
</div>',
			'notes'     => __( 'Real profile URLs come from Ekwa Settings → Branding.', 'ekwa' ),
		),

		'map' => array(
			'label'     => __( 'Google map', 'ekwa' ),
			'block'     => 'ekwa/map',
			'token'     => 'map',
			'group'     => 'footer',
			'expected'  => 'optional',
			'signature' => 'ekwa-map-wrapper',
			'snippet'   => '<div class="ekwa-map-wrapper">
  <iframe src="https://www.google.com/maps/embed?pb=..." width="100%" height="360" style="border:0;" loading="lazy"></iframe>
</div>',
			'notes'     => __( 'A Google Maps embed <iframe> is auto-detected → ekwa/map (lazy-loaded on the live site). Paste your real embed URL.', 'ekwa' ),
		),

		'copyright' => array(
			'label'     => __( 'Copyright line', 'ekwa' ),
			'block'     => 'ekwa/copyright',
			'token'     => 'copyright',
			'group'     => 'footer',
			'expected'  => 'footer',
			'signature' => 'ekwa-copyright',
			'snippet'   => '<div class="ekwa-copyright">© 2026 Practice Name. All rights reserved.</div>',
			'notes'     => __( 'The year and name render dynamically. Plain "© YYYY …" text is also auto-detected.', 'ekwa' ),
		),

		'phone-dropdown' => array(
			'label'     => __( 'Phone dropdown (multi-number)', 'ekwa' ),
			'block'     => 'ekwa/phone-dropdown',
			'token'     => 'phone-dropdown',
			'group'     => 'optional',
			'expected'  => 'optional',
			'signature' => 'ekwa-phone-dd',
			'snippet'   => '<div class="ekwa-phone-dd">
  <button class="ekwa-phone-dd__trigger"><i class="fa-solid fa-phone" aria-hidden="true"></i> Call Us</button>
  <div class="ekwa-phone-dd__panel"><!-- numbers render dynamically --></div>
</div>',
			'notes'     => __( 'Use instead of a single phone number when the site has multiple numbers/locations.', 'ekwa' ),
		),

		'address-dropdown' => array(
			'label'     => __( 'Address dropdown (multi-location)', 'ekwa' ),
			'block'     => 'ekwa/address-dropdown',
			'token'     => 'address-dropdown',
			'group'     => 'optional',
			'expected'  => 'optional',
			'signature' => 'ekwa-addr-dd',
			'snippet'   => '<div class="ekwa-addr-dd">
  <button class="ekwa-addr-dd__trigger"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Directions</button>
  <div class="ekwa-addr-dd__panel"><!-- locations render dynamically --></div>
</div>',
			'notes'     => __( 'Use instead of a single address when the site has multiple locations.', 'ekwa' ),
		),
	);
}

/**
 * The "you don't design the mobile experience" note — shared by the guide,
 * the starter template, and the AI prompts so the message is consistent.
 *
 * @return string Plain text.
 */
function ekwa_mockup_mobile_note() {
	return __( 'Design the DESKTOP header and footer only. Do NOT create a mobile header, hamburger button, off-canvas menu, or mobile bottom bar in your mockup — the Ekwa theme generates the entire mobile experience automatically from the same menu and settings. A responsive body (your @media rules for sections) is expected; a hand-built mobile header is not.', 'ekwa' );
}

/**
 * Two ready-to-paste AI prompts (retrofit an existing mockup / create a new
 * one), each self-contained with a compact contract cheat-sheet built from the
 * snippet library so nothing drifts.
 *
 * @return array<string,array{title:string,intro:string,prompt:string}>
 */
function ekwa_mockup_ai_prompts() {
	$snips = ekwa_mockup_canonical_snippets();

	// Compact class → structure cheat sheet embedded in both prompts.
	$cheat  = "CANONICAL STRUCTURES (use these EXACT class names — the theme fills them with real data):\n";
	$cheat .= "- Header menu:  " . str_replace( "\n", "\n  ", $snips['menu']['snippet'] ) . "\n";
	$cheat .= "  Megamenu item (optional):\n  " . str_replace( "\n", "\n  ", $snips['menu']['megamenu'] ) . "\n";
	foreach ( array( 'logo', 'phone', 'search', 'address', 'hours', 'social', 'map', 'copyright' ) as $id ) {
		$cheat .= '- ' . $snips[ $id ]['label'] . ":\n  " . str_replace( "\n", "\n  ", $snips[ $id ]['snippet'] ) . "\n";
	}
	$cheat .= "\nESCAPE HATCH: if something can't use these structures, add data-ekwa=\"phone|address|hours|social|copyright|logo|menu|search|map\" to the element to force the mapping. Add data-ekwa=\"static\" to opt an element OUT of detection.";

	$rules  = "RULES:\n"
		. "- " . ekwa_mockup_mobile_note() . "\n"
		. "- Wrap the site header in <header> and the footer in <footer>; body content in <main>.\n"
		. "- Phone numbers use tel:+1... links; email uses mailto:; the address/directions link may use \"#\" (the real maps URL comes from settings).\n"
		. "- Define ALL colors and fonts as CSS variables in :root (they import as design tokens). Use unique, descriptive image filenames.\n"
		. "- Keep the ekwa-* class names EXACTLY as written; you may add your own classes alongside them.";

	return array(
		'retrofit' => array(
			'title'  => __( 'Prompt A — Make an existing mockup Ekwa-compatible', 'ekwa' ),
			'intro'  => __( 'Paste this into ChatGPT / Claude / any AI, then paste your existing mockup HTML where indicated. It rewrites only the dynamic header/footer elements to the canonical structures without redesigning anything.', 'ekwa' ),
			'prompt' => "You are adapting an existing HTML mockup so it is compatible with the Ekwa WordPress theme's Mockup Converter. The theme has dynamic blocks that render real site data (menu, phone, address, hours, social, copyright, logo, search, map) from WordPress settings.\n\n"
				. "YOUR TASK: rewrite ONLY the header and footer dynamic elements to use the exact canonical structures below, so the converter maps them to the right blocks and my existing CSS keeps working. Do NOT redesign the site, change the visual layout, or touch the body sections — keep every class, wrapper, and style; only swap the inner structure of the dynamic elements and add the ekwa-* classes. Where an element can't be restructured, add the appropriate data-ekwa=\"...\" attribute instead.\n\n"
				. $rules . "\n\n"
				. $cheat . "\n\n"
				. "Return the full updated HTML. Here is my current mockup:\n\n[PASTE YOUR HTML HERE]",
		),
		'create' => array(
			'title'  => __( 'Prompt B — Create a new Ekwa-ready mockup', 'ekwa' ),
			'intro'  => __( 'Fill in the bracketed parts and paste into any AI to generate a fresh mockup that converts cleanly.', 'ekwa' ),
			'prompt' => "Create a complete, responsive HTML + CSS mockup for [DESCRIBE THE BUSINESS — e.g. \"a dental practice in Austin, TX\"] that is compatible with the Ekwa WordPress theme's Mockup Converter.\n\n"
				. "Pages/sections in the body: [LIST SECTIONS — e.g. hero with CTA, services grid, about, testimonials, FAQ, contact CTA].\nVisual style: [DESCRIBE — colors, fonts, mood].\n\n"
				. "Use semantic HTML5 (<header>, <main>, <footer>) and, for the dynamic elements, the EXACT canonical structures below so they convert 1:1 into the theme's dynamic blocks.\n\n"
				. $rules . "\n\n"
				. $cheat . "\n\n"
				. "Output a single index.html plus a style.css (or an embedded <style>). Make the body fully responsive with @media queries, but remember: no mobile header.",
		),
	);
}

/**
 * The data-ekwa token vocabulary (documented in the guide; parsed by
 * ekwa_mc_detect_token in inc/ekwa-converter-detect.php).
 *
 * @return array<string,string> token => description.
 */
function ekwa_mockup_token_vocabulary() {
	return array(
		'phone'            => __( 'Force the element to convert to ekwa/phone. Modifiers: data-ekwa-location="2", data-ekwa-type="existing", data-ekwa-prefix="Call us:".', 'ekwa' ),
		'phone-dropdown'   => __( 'Force ekwa/phone-dropdown.', 'ekwa' ),
		'address'          => __( 'Force ekwa/address. Modifiers: data-ekwa-location, data-ekwa-mode="icon|text|address|full".', 'ekwa' ),
		'address-dropdown' => __( 'Force ekwa/address-dropdown.', 'ekwa' ),
		'hours'            => __( 'Force ekwa/hours. Modifier: data-ekwa-location.', 'ekwa' ),
		'social'           => __( 'Force ekwa/social.', 'ekwa' ),
		'copyright'        => __( 'Force ekwa/copyright.', 'ekwa' ),
		'logo'             => __( 'Force ekwa/svg-logo.', 'ekwa' ),
		'menu'             => __( 'Force ekwa/header-menu (the live WP menu).', 'ekwa' ),
		'navigation'       => __( 'Force a core/navigation block (footer quick links etc.).', 'ekwa' ),
		'search'           => __( 'Force ekwa/search.', 'ekwa' ),
		'hamburger'        => __( 'Force ekwa/hamburger-menu.', 'ekwa' ),
		'map'              => __( 'Force ekwa/map (Google map).', 'ekwa' ),
		'scroll-top'       => __( 'Force ekwa/scroll-top.', 'ekwa' ),
		'static'           => __( 'Opt OUT: convert this element normally, skip all dynamic detection (escape hatch for false positives). "ignore" is an alias.', 'ekwa' ),
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// READINESS CHECK
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Analyze a whole mockup HTML file and report conversion readiness.
 *
 * @param string $html Full mockup HTML (typically index.html).
 * @return array { sections: [...], media: {...}, tokens: {...} }
 */
function ekwa_mockup_readiness_check( $html ) {
	require_once get_template_directory() . '/inc/ekwa-converter-lib.php';

	$sections = array();

	// ── Parse the DOM (same loader as the converter). Full documents are
	// reduced to body content — the raw $html is still used further down for
	// the <style>/<link> and url() checks, which may live in <head>.
	$body_html = ekwa_mc_extract_body( $html );
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div data-ekwa-mc-root="1">' . $body_html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();

	// Collect every class token once.
	$classes = array();
	foreach ( $doc->getElementsByTagName( '*' ) as $el ) {
		$attr = $el->getAttribute( 'class' );
		if ( $attr ) {
			foreach ( preg_split( '/\s+/', $attr ) as $c ) {
				if ( '' !== $c ) {
					$classes[ $c ] = true;
				}
			}
		}
	}

	$headers = $doc->getElementsByTagName( 'header' );
	$footers = $doc->getElementsByTagName( 'footer' );
	$has_header = $headers->length > 0;
	$has_footer = $footers->length > 0;

	// ── Structure. ────────────────────────────────────────────────────────
	$sections[] = array(
		'id'      => 'structure-header',
		'label'   => __( 'Header element', 'ekwa' ),
		'status'  => $has_header ? 'pass' : 'warn',
		'message' => $has_header
			? __( '<header> found — convert it into the Header template part.', 'ekwa' )
			: __( 'No <header> element. Wrap the site header in <header>…</header> so it can be converted into the Header template part.', 'ekwa' ),
	);
	$sections[] = array(
		'id'      => 'structure-footer',
		'label'   => __( 'Footer element', 'ekwa' ),
		'status'  => $has_footer ? 'pass' : 'warn',
		'message' => $has_footer
			? __( '<footer> found — convert it into the Footer template part.', 'ekwa' )
			: __( 'No <footer> element. Wrap the site footer in <footer>…</footer>.', 'ekwa' ),
	);

	// ── Dry-run conversion → which blocks come out. ──────────────────────
	$manifest = function_exists( 'ekwa_converter_load_server_manifest' ) ? ekwa_converter_load_server_manifest() : null;
	$result   = ekwa_mc_convert_html( $html, $manifest, array( 'detect_dynamic' => true ) );
	$emitted  = array();
	if ( preg_match_all( '/<!--\s+wp:([a-z0-9\/_-]+)/', $result['markup'], $m ) ) {
		foreach ( $m[1] as $name ) {
			$name = false === strpos( $name, '/' ) ? 'core/' . $name : $name;
			$emitted[ $name ] = true;
		}
	}

	// ── The header menu (the #1 conversion problem). ─────────────────────
	$snippets  = ekwa_mockup_canonical_snippets();
	$menu_spec = $snippets['menu'];

	$nav_in_header = false;
	if ( $has_header ) {
		foreach ( $headers as $h ) {
			if ( $h->getElementsByTagName( 'nav' )->length > 0 ) {
				$nav_in_header = true;
				break;
			}
		}
	}

	if ( isset( $classes['ekwa-header-nav'] ) || isset( $classes['ekwa-header-menu'] ) ) {
		$sections[] = array(
			'id'      => 'menu',
			'label'   => $menu_spec['label'],
			'status'  => 'pass',
			'message' => __( 'Canonical menu markup found → converts to ekwa/header-menu (the live WP menu), and your menu CSS styles the real thing 1:1.', 'ekwa' ),
		);
	} elseif ( $nav_in_header ) {
		$sections[] = array(
			'id'      => 'menu',
			'label'   => $menu_spec['label'],
			'status'  => 'warn',
			'message' => __( 'The header has a <nav>, but it is NOT the canonical structure — it will convert to a flat core/navigation block instead of ekwa/header-menu. Rewrite it with the structure below (then your CSS also styles the live menu), or add data-ekwa="menu" to the nav.', 'ekwa' ),
			'fix'     => $menu_spec['snippet'],
		);
	} elseif ( $has_header ) {
		$sections[] = array(
			'id'      => 'menu',
			'label'   => $menu_spec['label'],
			'status'  => 'warn',
			'message' => __( 'No <nav> found inside the header. Add the canonical menu markup below.', 'ekwa' ),
			'fix'     => $menu_spec['snippet'],
		);
	}

	// ── Logo (image → core/site-logo, or SVG → ekwa/svg-logo). ────────────
	$logo_spec  = $snippets['logo'];
	$logo_canon = isset( $classes['custom-logo-link'] ) || isset( $classes['wp-block-site-logo'] ) || isset( $classes['ekwa-svg-logo'] );
	$logo_block = isset( $emitted['core/site-logo'] ) || isset( $emitted['ekwa/svg-logo'] );
	if ( $logo_canon ) {
		$sections[] = array( 'id' => 'logo', 'label' => $logo_spec['label'], 'status' => 'pass', 'message' => __( 'Canonical logo markup found (image → core/site-logo, or SVG → ekwa/svg-logo).', 'ekwa' ) );
	} elseif ( $logo_block ) {
		$sections[] = array( 'id' => 'logo', 'label' => $logo_spec['label'], 'status' => 'pass', 'message' => __( 'Logo auto-detected. For 1:1 CSS reuse, use the canonical markup below (style .custom-logo).', 'ekwa' ), 'fix' => $logo_spec['snippet'] );
	} else {
		$sections[] = array( 'id' => 'logo', 'label' => $logo_spec['label'], 'status' => 'warn', 'message' => __( 'No logo found in the header. Use the canonical image-logo markup below (or the SVG variant for a vector logo).', 'ekwa' ), 'fix' => $logo_spec['snippet'] );
	}

	// ── Other dynamic elements. ───────────────────────────────────────────
	foreach ( $snippets as $id => $spec ) {
		if ( 'menu' === $id || 'logo' === $id ) {
			continue; // Handled above.
		}
		$canonical = isset( $classes[ $spec['signature'] ] );
		$block_out = isset( $emitted[ $spec['block'] ] );

		if ( $canonical ) {
			$sections[] = array(
				'id'      => $id,
				'label'   => $spec['label'],
				'status'  => 'pass',
				'message' => sprintf( __( 'Canonical markup found → %s.', 'ekwa' ), $spec['block'] ),
			);
		} elseif ( $block_out ) {
			$sections[] = array(
				'id'      => $id,
				'label'   => $spec['label'],
				'status'  => 'pass',
				'message' => sprintf( __( 'Auto-detected → %s (heuristic). For 1:1 CSS reuse, prefer the canonical markup.', 'ekwa' ), $spec['block'] ),
			);
		} elseif ( 'optional' !== $spec['expected'] ) {
			$where = 'header' === $spec['expected'] ? __( 'header', 'ekwa' ) : ( 'footer' === $spec['expected'] ? __( 'footer', 'ekwa' ) : __( 'header or footer', 'ekwa' ) );
			$sections[] = array(
				'id'      => $id,
				'label'   => $spec['label'],
				'status'  => 'warn',
				'message' => sprintf( __( 'Not found (usually lives in the %1$s). If the design has one, use the canonical markup below or add data-ekwa="%2$s".', 'ekwa' ), $where, $spec['token'] ),
				'fix'     => $spec['snippet'],
			);
		}
	}

	// ── Raw-HTML fallbacks from the dry run. ─────────────────────────────
	$raw_count = 0;
	foreach ( (array) ( $result['report'] ?? array() ) as $entry ) {
		if ( isset( $entry['category'] ) && 'raw-html' === $entry['category'] ) {
			$raw_count++;
		}
	}
	$sections[] = array(
		'id'      => 'raw-html',
		'label'   => __( 'Block coverage', 'ekwa' ),
		'status'  => $raw_count > 0 ? 'warn' : 'pass',
		'message' => $raw_count > 0
			? sprintf( __( '%d element(s) will fall back to raw HTML (forms need your form plugin; everything else converts). Run a real conversion to see the detailed report.', 'ekwa' ), $raw_count )
			: __( 'Every element maps to a block — no raw-HTML fallbacks.', 'ekwa' ),
	);

	// ── Media resolution. ─────────────────────────────────────────────────
	$refs = array();
	foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
		$src = $img->getAttribute( 'src' );
		if ( $src && 0 !== stripos( $src, 'data:' ) ) {
			$refs[ strtolower( basename( $src ) ) ] = true;
		}
	}
	foreach ( array( 'source', 'video', 'audio' ) as $t ) {
		foreach ( $doc->getElementsByTagName( $t ) as $el ) {
			$src = $el->getAttribute( 'src' );
			if ( $src ) {
				$refs[ strtolower( basename( $src ) ) ] = true;
			}
		}
	}
	if ( preg_match_all( '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $html, $um ) ) {
		foreach ( $um[1] as $u ) {
			if ( 0 !== stripos( $u, 'data:' ) && preg_match( '/\.(jpe?g|png|webp|gif|svg|mp4|webm)$/i', $u ) ) {
				$refs[ strtolower( basename( $u ) ) ] = true;
			}
		}
	}

	$media_by_name = array();
	if ( $manifest && ! empty( $manifest['media'] ) ) {
		foreach ( $manifest['media'] as $item ) {
			if ( ! empty( $item['filename'] ) ) {
				$media_by_name[ strtolower( $item['filename'] ) ] = true;
			}
		}
	}
	$missing = array();
	foreach ( array_keys( $refs ) as $file ) {
		if ( isset( $media_by_name[ $file ] ) ) {
			continue;
		}
		if ( function_exists( 'ekwa_mc_find_attachment_by_basename' ) && ekwa_mc_find_attachment_by_basename( $file ) ) {
			continue;
		}
		$missing[] = $file;
	}
	$sections[] = array(
		'id'      => 'media',
		'label'   => __( 'Media assets', 'ekwa' ),
		'status'  => empty( $missing ) ? 'pass' : 'warn',
		'message' => empty( $missing )
			? sprintf( __( 'All %d referenced file(s) resolve via the site manifest / media library.', 'ekwa' ), count( $refs ) )
			: sprintf( __( '%1$d of %2$d referenced file(s) are not in the media library yet: %3$s. Use "Import mockup assets" above.', 'ekwa' ), count( $missing ), count( $refs ), implode( ', ', array_slice( $missing, 0, 12 ) ) . ( count( $missing ) > 12 ? '…' : '' ) ),
	);

	// ── CSS / design tokens. ─────────────────────────────────────────────
	$saved_css = function_exists( 'ekwa_tokens_mockup_css' ) ? trim( ekwa_tokens_mockup_css() ) : '';
	$has_style = false !== stripos( $html, '<style' ) || preg_match( '/<link[^>]+rel=["\']?stylesheet/i', $html );
	if ( '' !== $saved_css ) {
		$tok = function_exists( 'ekwa_mc_extract_css_tokens' ) ? ekwa_mc_extract_css_tokens( $saved_css ) : array( 'fonts' => array(), 'colors' => array() );
		$sections[] = array(
			'id'      => 'css',
			'label'   => __( 'Mockup stylesheet', 'ekwa' ),
			'status'  => 'pass',
			'message' => sprintf( __( 'Saved in Design Setup (%1$d font(s), %2$d color value(s) detected). The converter and AI tools will use it.', 'ekwa' ), count( $tok['fonts'] ), count( $tok['colors'] ) ),
		);
	} else {
		$sections[] = array(
			'id'      => 'css',
			'label'   => __( 'Mockup stylesheet', 'ekwa' ),
			'status'  => 'warn',
			'message' => $has_style
				? __( 'The mockup references CSS, but nothing is saved in Design Setup → "Mockup stylesheet". Paste the full stylesheet there so fonts/colors extract and AI CSS extraction works.', 'ekwa' )
				: __( 'No stylesheet saved in Design Setup and none referenced in the file. Paste the mockup CSS in the "Mockup stylesheet" field above.', 'ekwa' ),
		);
	}

	// data-ekwa usage (informational).
	$token_count = preg_match_all( '/\sdata-ekwa=/', $html );
	if ( $token_count ) {
		$sections[] = array(
			'id'      => 'tokens',
			'label'   => __( 'data-ekwa tokens', 'ekwa' ),
			'status'  => 'pass',
			'message' => sprintf( __( '%d explicit data-ekwa token(s) found — those mappings are forced.', 'ekwa' ), $token_count ),
		);
	}

	return array( 'sections' => $sections );
}

// ═══════════════════════════════════════════════════════════════════════════════
// DOWNLOADS — Author Guide + starter template (generated from the library)
// ═══════════════════════════════════════════════════════════════════════════════

add_action( 'admin_post_ekwa_mockup_guide', 'ekwa_mockup_download_guide' );
add_action( 'admin_post_ekwa_mockup_starter', 'ekwa_mockup_download_starter' );

/**
 * Stream a generated HTML file.
 *
 * @param string $filename
 * @param string $html
 */
function ekwa_mockup_stream_html( $filename, $html ) {
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

/**
 * The Mockup Author Guide — self-contained HTML, shareable with designers.
 */
function ekwa_mockup_download_guide() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ekwa' ) );
	}
	check_admin_referer( 'ekwa_mockup_kit' );
	ekwa_mockup_stream_html( 'ekwa-mockup-guide.html', ekwa_mockup_build_guide_html() );
}

/**
 * Build the Author Guide HTML (separate from the handler so it's testable).
 *
 * @return string
 */
function ekwa_mockup_build_guide_html() {
	$snippets = ekwa_mockup_canonical_snippets();
	$tokens   = ekwa_mockup_token_vocabulary();
	$prompts  = ekwa_mockup_ai_prompts();
	$bp       = function_exists( 'ekwa_responsive_breakpoints' ) ? ekwa_responsive_breakpoints() : array( 'tablet' => 1199, 'mobile' => 599 );

	// Render one element's block (with the megamenu variant when present).
	$render_spec = function ( $spec ) {
		$out = '<section><h3>' . esc_html( $spec['label'] ) . ' <code>' . esc_html( $spec['block'] ) . '</code></h3>'
			. '<pre><code>' . esc_html( $spec['snippet'] ) . '</code></pre>'
			. '<p>' . esc_html( $spec['notes'] ) . '</p>';
		if ( ! empty( $spec['megamenu'] ) ) {
			$out .= '<p class="sub"><strong>' . esc_html__( 'Megamenu variant', 'ekwa' ) . '</strong> — ' . esc_html__( 'replace a normal dropdown item with this to get a megamenu:', 'ekwa' ) . '</p>'
				. '<pre><code>' . esc_html( $spec['megamenu'] ) . '</code></pre>';
		}
		if ( ! empty( $spec['alt_snippet'] ) ) {
			$out .= '<p class="sub"><strong>' . esc_html( $spec['alt_label'] ) . '</strong></p>'
				. '<pre><code>' . esc_html( $spec['alt_snippet'] ) . '</code></pre>';
		}
		$out .= '<p class="alt">' . sprintf( esc_html__( 'Fallback: add data-ekwa="%s" to any element to force this mapping.', 'ekwa' ), esc_html( $spec['token'] ) ) . '</p>'
			. '</section>';
		return $out;
	};

	// Group the elements.
	$groups = array( 'header' => '', 'footer' => '', 'optional' => '' );
	foreach ( $snippets as $spec ) {
		$g = isset( $spec['group'] ) ? $spec['group'] : 'optional';
		$groups[ $g ] .= $render_spec( $spec );
	}

	$token_rows = '';
	foreach ( $tokens as $token => $desc ) {
		$token_rows .= '<tr><td><code>data-ekwa="' . esc_html( $token ) . '"</code></td><td>' . esc_html( $desc ) . '</td></tr>';
	}

	$prompt_html = '';
	foreach ( $prompts as $p ) {
		$prompt_html .= '<section><h3>' . esc_html( $p['title'] ) . '</h3>'
			. '<p>' . esc_html( $p['intro'] ) . '</p>'
			. '<pre class="prompt"><code>' . esc_html( $p['prompt'] ) . '</code></pre></section>';
	}

	$css = 'body{font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;max-width:880px;margin:40px auto;padding:0 20px;color:#1e2933}'
		. 'h1{font-size:28px}h2{margin-top:48px;font-size:22px;color:#0e7c6b;border-bottom:2px solid #0e7c6b;padding-bottom:6px}'
		. 'h3{margin-top:32px;font-size:17px}h3 code{font-size:12.5px;background:#e6f4f1;color:#0e7c6b;padding:2px 8px;border-radius:4px;margin-left:6px}'
		. 'pre{background:#0f172a;color:#e2e8f0;padding:14px 16px;border-radius:8px;overflow-x:auto;font-size:12.5px;line-height:1.55}'
		. 'pre.prompt{background:#0b2b26;white-space:pre-wrap}'
		. 'table{border-collapse:collapse;width:100%;font-size:13.5px}td,th{border:1px solid #d6dde4;padding:7px 10px;text-align:left;vertical-align:top}'
		. '.lead{font-size:16.5px;color:#374551}.alt{font-size:13px;color:#5b6b7c}.sub{font-size:13.5px}'
		. '.rule{background:#f4f7fb;border-left:4px solid #0e7c6b;padding:10px 14px;margin:14px 0}'
		. '.warn{background:#fffbf0;border-left:4px solid #dba617;padding:12px 16px;margin:18px 0;border-radius:4px}';

	$html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . esc_html__( 'Ekwa Mockup Author Guide', 'ekwa' ) . '</title>'
		. '<style>' . $css . '</style></head><body>'
		. '<h1>' . esc_html__( 'Ekwa Mockup Author Guide', 'ekwa' ) . '</h1>'
		. '<p class="lead">' . esc_html__( 'Build the mockup with the canonical structures in this guide and two things happen automatically: the Mockup Converter maps each element to the matching dynamic block (real phone numbers, addresses, hours, menus — all pulled from site settings), and your mockup CSS styles the live site 1:1, because these are the exact structures the blocks render.', 'ekwa' ) . '</p>'

		. '<div class="warn"><strong>' . esc_html__( 'Desktop only — no mobile header.', 'ekwa' ) . '</strong><br>' . esc_html( ekwa_mockup_mobile_note() ) . '</div>'

		. '<div class="rule"><strong>' . esc_html__( 'Ground rules', 'ekwa' ) . '</strong><ul>'
		. '<li>' . esc_html__( 'Wrap the site header in <header>, the footer in <footer>, body content in <main>.', 'ekwa' ) . '</li>'
		. '<li>' . esc_html__( 'Use tel:+1… links for phone numbers, mailto: for email, and real Google Maps URLs (or "#") for direction links.', 'ekwa' ) . '</li>'
		. '<li>' . sprintf( esc_html__( 'Keep image/video filenames unique — the importer matches by filename. Breakpoints on this site: mobile ≤ %1$dpx, desktop > %2$dpx.', 'ekwa' ), (int) $bp['mobile'], (int) $bp['tablet'] ) . '</li>'
		. '<li>' . esc_html__( 'Define colors and fonts as CSS variables in :root — they import as design tokens.', 'ekwa' ) . '</li>'
		. '<li>' . esc_html__( 'Keep the ekwa-* class names exactly; add your own classes alongside them. When in doubt, stamp data-ekwa="…" on the element (vocabulary at the end).', 'ekwa' ) . '</li>'
		. '</ul></div>'

		. '<h2>' . esc_html__( 'Header elements', 'ekwa' ) . '</h2>' . $groups['header']
		. '<h2>' . esc_html__( 'Footer elements', 'ekwa' ) . '</h2>' . $groups['footer']
		. '<h2>' . esc_html__( 'Optional variants', 'ekwa' ) . '</h2>'
		. '<p>' . esc_html__( 'Use these in place of the single phone/address when the site has multiple numbers or locations.', 'ekwa' ) . '</p>' . $groups['optional']

		. '<h2>' . esc_html__( 'AI prompts', 'ekwa' ) . '</h2>'
		. '<p>' . esc_html__( 'Copy a prompt into ChatGPT, Claude, or any AI. Each is self-contained (the canonical structures are embedded), so it works even without attaching this guide.', 'ekwa' ) . '</p>'
		. $prompt_html

		. '<h2>' . esc_html__( 'data-ekwa token vocabulary', 'ekwa' ) . '</h2><table><tr><th>' . esc_html__( 'Token', 'ekwa' ) . '</th><th>' . esc_html__( 'Effect', 'ekwa' ) . '</th></tr>' . $token_rows . '</table>'
		. '</body></html>';

	return $html;
}

/**
 * The starter template — a working mockup skeleton assembled from the
 * canonical snippets. Authors copy it and build on top.
 */
function ekwa_mockup_download_starter() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ekwa' ) );
	}
	check_admin_referer( 'ekwa_mockup_kit' );
	ekwa_mockup_stream_html( 'ekwa-mockup-starter.html', ekwa_mockup_build_starter_html() );
}

/**
 * Build the starter-template HTML (separate from the handler so it's testable).
 *
 * @return string
 */
function ekwa_mockup_build_starter_html() {
	$s = ekwa_mockup_canonical_snippets();

	$html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mockup Starter — Ekwa</title>
<link rel="stylesheet" href="style.css">
<!--
  EKWA MOCKUP STARTER
  - The header/footer below use the CANONICAL structures: they convert 1:1 to
    the Ekwa dynamic blocks, and your CSS here styles the live site directly.
  - Keep the ekwa-* classes intact; add your own classes alongside them.
  - DESKTOP ONLY: do NOT add a mobile header, hamburger, or off-canvas menu —
    the theme builds the entire mobile experience automatically. Just make the
    body responsive with @media rules.
  - For a megamenu, the data-ekwa vocabulary, and AI prompts, see
    ekwa-mockup-guide.html.
-->
</head>
<body>

<header class="site-header">
	<div class="site-header__inner">
		' . $s['logo']['snippet'] . '

		' . $s['menu']['snippet'] . '

		<div class="site-header__actions">
			' . $s['phone']['snippet'] . '
			' . $s['search']['snippet'] . '
		</div>
	</div>
</header>

<main>
	<section class="hero">
		<div class="hero__inner">
			<h1>Headline that sells the outcome</h1>
			<p>Supporting sentence that earns the call to action.</p>
			<a class="btn btn--primary" href="#">Book an Appointment</a>
		</div>
	</section>

	<section class="services">
		<h2>What we offer</h2>
		<div class="services__grid">
			<div class="card"><h3>Service one</h3><p>Short description.</p></div>
			<div class="card"><h3>Service two</h3><p>Short description.</p></div>
			<div class="card"><h3>Service three</h3><p>Short description.</p></div>
		</div>
	</section>
</main>

<footer class="site-footer">
	<div class="site-footer__grid">
		<div class="site-footer__col">
			' . $s['address']['snippet'] . '
			' . $s['phone']['snippet'] . '
		</div>
		<div class="site-footer__col">
			' . $s['hours']['snippet'] . '
		</div>
		<div class="site-footer__col">
			' . $s['social']['snippet'] . '
		</div>
		<div class="site-footer__col">
			' . $s['map']['snippet'] . '
		</div>
	</div>
	<div class="site-footer__bar">
		' . $s['copyright']['snippet'] . '
	</div>
</footer>

</body>
</html>';

	return $html;
}
