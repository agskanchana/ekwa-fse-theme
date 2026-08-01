<?php
/**
 * Mockup Contract — canonical markup library, readiness checker, and the
 * copyable AI authoring prompts.
 *
 * Every ekwa dynamic block renders fixed markup. When a mockup is written
 * with those EXACT structures, two things follow: (1) the converter maps the
 * markup straight back to the dynamic blocks (see ekwa_mc_detect_canonical in
 * inc/ekwa-converter-detect.php), and (2) the mockup's own CSS styles the
 * live rendered blocks 1:1 with zero adaptation. This file is the single
 * source of truth for those structures:
 *
 *   - ekwa_mockup_canonical_snippets()  — the snippet library
 *   - ekwa_mockup_ai_prompts()          — the copyable "align / create" prompts
 *   - ekwa_mockup_readiness_check()     — whole-file pre-flight analyzer
 *
 * The prompts are surfaced (copy-to-clipboard) in Ekwa Settings → Design Setup;
 * authors paste one into any AI instead of downloading a file. See
 * ekwa_tokens_render_tab() in inc/ekwa-tokens.php.
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
 * The "you don't design the mobile experience" note — shared by the AI prompts
 * (and the authoring-kit UI) so the message stays consistent.
 *
 * @return string Plain text.
 */
function ekwa_mockup_mobile_note() {
	return __( 'Design the DESKTOP header and footer only. Below 1200px the Ekwa theme automatically hides your desktop header and builds the entire mobile experience itself — its own mobile header (logo + search + hamburger), off-canvas menu, and mobile bottom bar, all from the same menu and settings. So do NOT create a mobile header; and if the mockup you are adapting already has one, REMOVE it completely: the hamburger / menu-toggle button and its markup, the off-canvas or slide-down menu, any mobile-only bottom/sticky bar, the JavaScript that opens and closes them, and the @media rules that restyle the header into a mobile bar or show/hide those pieces. Keep only the desktop header — the theme hides it below 1200px for you. A responsive BODY (your @media rules for the page sections) is expected and encouraged; a hand-built mobile header is not.', 'ekwa' );
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
	$cheat .= "- Header menu: the ONE place where your own class names are fine — the converter reads them and the menu block re-renders wearing them, so your menu CSS keeps working. What matters is the SHAPE:\n"
		. "  <nav> > <ul> > <li> > <a>, a nested <ul> inside the <li> for a dropdown, and for a mega menu a <div> panel inside the <li> holding one <div> per column (each column: optional <img>, a heading <a>, then a <ul> of links).\n"
		. "  Put the link text in a <span> inside the <a>, and the dropdown arrow in its own empty <span> or <i>. Name every one of those elements whatever your design calls them.\n"
		. "  Reference shape:\n  " . str_replace( "\n", "\n  ", $snips['menu']['snippet'] ) . "\n";
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
		. "- FONTS MUST BE APPLIED THROUGH A VARIABLE, never by name. Declare each typeface once in :root — e.g. --font-heading:'Playfair Display',serif; --font-body:'Inter',sans-serif; — and every rule that sets type uses font-family:var(--font-heading). A literal font-family:'Inter',sans-serif anywhere outside :root is wrong. The theme self-hosts each typeface and re-points that same variable at the local files, which is also what lets it skip downloading the font on mobile — a rule that names the family directly opts out of both. Use the font-family longhand (not the `font:` shorthand) so the variable is visible.\n"
		. "- Keep the ekwa-* class names EXACTLY as written; you may add your own classes alongside them. THE HEADER MENU IS THE EXCEPTION: name its elements however you like, but keep the nesting shape described below — the converter copies your class names onto the menu block so your CSS still matches.\n"
		. "- HEADER MENU SHAPE (what the converter needs): one <nav> containing one <ul> of <li> items, each with a single <a>. A dropdown is a nested <ul> inside its <li>. A mega menu is a <div> inside its <li> containing one <div> per column, and each column holds an optional <img>, a heading <a>, then a <ul> of links. Put the link's text in a <span> and the dropdown arrow in its own EMPTY <span> or <i>. Don't build dropdowns out of bare <div>/<a> stacks, don't add wrapper elements between the <li> and its dropdown, and don't hand-write mobile menu markup.\n"
		. "- ICONS: Font Awesome 6 ONLY — <i class=\"fa-solid fa-phone\" aria-hidden=\"true\"></i> for regular icons and <i class=\"fa-brands fa-facebook-f\"></i> for logos. Do NOT use Remix Icon (ri-*), Bootstrap Icons (bi-*), Themify (ti-*), Ionicons, Material Icons, Line Awesome or Glyphicons, and do NOT add a stylesheet/CDN link for any icon font — the theme bundles Font Awesome 6 Free and loads nothing else, so those icons render as blank boxes on the live site. Use only icons that exist in the FREE set (Solid and Brands); check at fontawesome.com/search with the Free filter on. Always put the icon in its own <i> element (never a background image or a ::before glyph) so it converts to an editable icon block.\n"
		. "- HERO SLIDERS & BACKGROUND-VIDEO HEROES: design them as simple static markup (one visible slide / a poster image is enough) — do NOT hand-code slider JS, dots, or arrows. The theme's \"Convert with AI\" maps them to its built-in ekwa/slider and ekwa/hero-video blocks (fade/slide/slide-up/zoom/zoom-out/blur/parallax-push/wipe/flip transitions, per-caption entrance animations, arrows, dots, autoplay).\n"
		. "- YOUTUBE/VIMEO VIDEOS: a real embedded iframe (or even just the video's URL as a placeholder) is enough — do NOT hand-build a custom play button/lightbox. \"Convert with AI\" maps it to ekwa/youtube-video or ekwa/vimeo-video, which auto-fetches the title/thumbnail/duration and adds click-to-play, an optional lightbox, and Schema.org video markup.";

	return array(
		'retrofit' => array(
			'title'  => __( 'Prompt A — Make an existing mockup Ekwa-compatible', 'ekwa' ),
			'intro'  => __( 'Paste this into ChatGPT / Claude / any AI, then paste your existing mockup HTML where indicated. It rewrites only the dynamic header/footer elements to the canonical structures without redesigning anything.', 'ekwa' ),
			'prompt' => "You are adapting an existing HTML mockup so it is compatible with the Ekwa WordPress theme's Mockup Converter. The theme has dynamic blocks that render real site data (menu, phone, address, hours, social, copyright, logo, search, map) from WordPress settings.\n\n"
				. "YOUR TASK: rewrite ONLY the header and footer dynamic elements to use the exact canonical structures below, so the converter maps them to the right blocks and my existing CSS keeps working. Do NOT redesign the site, change the visual layout, or touch the body sections — keep every class, wrapper, and style; only swap the inner structure of the dynamic elements and add the ekwa-* classes. Where an element can't be restructured, add the appropriate data-ekwa=\"...\" attribute instead.\n\nREMOVE THE MOBILE HEADER: if my mockup includes a hamburger / menu-toggle button, an off-canvas or slide-down mobile menu, a mobile-only bottom or sticky bar, the JavaScript that toggles any of them, or @media rules that turn the header into a mobile bar — delete all of it. The theme supplies its own mobile header and hides the desktop header below 1200px automatically; leave only the desktop header, and keep the body's own responsive @media rules.\n\n"
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
				. "Output a single index.html plus a style.css (or an embedded <style>). Make the body fully responsive with @media queries, but remember: no mobile header — the theme adds its own and hides your desktop header below 1200px.",
		),
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// READINESS CHECK
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Concatenate every inline <style> block in a document. Used as a CSS source
 * for the readiness check when nothing is saved in Design Setup yet.
 *
 * @param string $html
 * @return string
 */
function ekwa_mockup_inline_css( $html ) {
	if ( ! preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', (string) $html, $m ) ) {
		return '';
	}
	return implode( "\n", $m[1] );
}

/**
 * Selectors whose rules apply a font family by NAME instead of through a CSS
 * variable.
 *
 * Skipped: `:root` (that's where families are *supposed* to be declared),
 * `@font-face` (its font-family names the face being defined), custom-property
 * declarations, and stacks made only of generic/system/icon families — those
 * are fallbacks, not typefaces to self-host.
 *
 * @param string $css Stylesheet.
 * @return string[] Offending selectors, in source order, de-duplicated.
 */
function ekwa_mockup_literal_font_rules( $css ) {
	if ( ! function_exists( 'ekwa_css_walk' ) ) {
		require_once get_template_directory() . '/inc/ekwa-css-rules.php';
	}

	$generic = array(
		'inherit', 'initial', 'unset', 'revert', 'sans-serif', 'serif', 'monospace',
		'cursive', 'fantasy', 'system-ui', 'ui-sans-serif', 'ui-serif', 'ui-monospace',
		'-apple-system', 'blinkmacsystemfont', 'segoe ui', 'arial', 'helvetica',
		'helvetica neue', 'roboto', 'tahoma', 'verdana', 'times new roman', 'georgia',
		'courier new', 'font awesome 6 free', 'font awesome 6 brands', 'fontawesome',
		'font awesome 5 free', 'dashicons', 'material icons', 'remixicon',
	);

	$found = array();
	ekwa_css_walk( (string) $css, function ( $rule ) use ( &$found, $generic ) {
		if ( null === $rule['body'] ) {
			return; // @import & friends.
		}
		$selector = trim( $rule['selector'] );
		if ( ':root' === strtolower( $selector ) || 0 === stripos( $selector, '@font-face' ) ) {
			return;
		}
		// font-family longhand only — a custom property here is a declaration,
		// which is exactly what we want people to write.
		if ( ! preg_match_all( '/(?<![-a-z0-9_])font-family\s*:\s*([^;}]+)/i', $rule['body'], $m ) ) {
			return;
		}
		foreach ( $m[1] as $value ) {
			$value = trim( $value );
			if ( '' === $value || stripos( $value, 'var(' ) !== false ) {
				continue;
			}
			$first = strtolower( trim( explode( ',', $value )[0], " \t\n\r\"'" ) );
			if ( '' === $first || in_array( $first, $generic, true ) ) {
				continue;
			}
			$found[ $selector ] = true;
			return;
		}
	} );

	return array_keys( $found );
}

/**
 * Icon classes in a mockup that belong to a font other than Font Awesome,
 * split into "the converter can map these" and "these have no equivalent".
 *
 * @param string $html Full mockup HTML.
 * @return array{classes:string[],unmapped:string[]}
 */
function ekwa_mockup_foreign_icons( $html ) {
	if ( ! function_exists( 'ekwa_mc_icon_class_to_fontawesome' ) ) {
		require_once get_template_directory() . '/inc/ekwa-converter-icons.php';
	}

	$classes  = array();
	$unmapped = array();

	if ( preg_match_all( '#<(?:i|span)\b[^>]*\sclass=("|\')([^"\']+)\1#i', (string) $html, $matches ) ) {
		foreach ( $matches[2] as $class_string ) {
			$result = ekwa_mc_icon_class_to_fontawesome( $class_string );
			if ( ! $result['changed'] && empty( $result['unmapped'] ) ) {
				continue; // Already Font Awesome, or not an icon at all.
			}
			$classes[ trim( $class_string ) ] = true;
			foreach ( $result['unmapped'] as $token ) {
				$unmapped[ $token ] = true;
			}
		}
	}

	return array(
		'classes'  => array_keys( $classes ),
		'unmapped' => array_keys( $unmapped ),
	);
}

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

	// ── Fonts applied through a variable? ────────────────────────────────
	// A rule that names the family directly still renders (the @font-face
	// matches by name), so this never shows up visually — but it defeats the
	// font variable entirely: the theme can't swap in the self-hosted files,
	// and conditional loading can't stop phones from downloading the font.
	// Check BOTH the stylesheet saved in Design Setup (what the converter and
	// the AI actually use) and any CSS inlined in the pasted file — a rule that
	// only exists in one of them still ships.
	$font_css = trim( $saved_css . "\n" . ekwa_mockup_inline_css( $html ) );
	if ( '' !== trim( $font_css ) ) {
		$literals = ekwa_mockup_literal_font_rules( $font_css );
		$sections[] = array(
			'id'      => 'font-vars',
			'label'   => __( 'Fonts use variables', 'ekwa' ),
			'status'  => empty( $literals ) ? 'pass' : 'warn',
			'message' => empty( $literals )
				? __( 'Every font-family declaration goes through a CSS variable (or defines one). Self-hosting and conditional mobile loading will apply cleanly.', 'ekwa' )
				: sprintf(
					/* translators: 1: count, 2: comma-separated selectors. */
					__( '%1$d rule(s) name a font family directly instead of using a variable: %2$s. Move each typeface into a :root variable and reference it with font-family:var(--name) — otherwise the variable the Fonts tab creates is never applied, and the font downloads on mobile even when conditional loading is on.', 'ekwa' ),
					count( $literals ),
					implode( ', ', array_slice( $literals, 0, 10 ) ) . ( count( $literals ) > 10 ? '…' : '' )
				),
			'fix'     => empty( $literals ) ? '' : ":root {\n  --font-heading: 'Playfair Display', serif;\n  --font-body: 'Inter', sans-serif;\n}\n\nbody { font-family: var(--font-body); }\nh1, h2, h3 { font-family: var(--font-heading); }",
		);
	}

	// ── CSS variables that resolve to nothing ────────────────────────────
	// A declaration reading an undefined custom property is discarded at
	// computed-value time — silently. `body{font-family:var(--font-main)}`
	// with no --font-main anywhere means the whole site falls back to the
	// browser default font and nothing anywhere says why.
	if ( '' !== trim( $font_css ) && function_exists( 'ekwa_tokens_undefined_vars' ) ) {
		$undefined = ekwa_tokens_undefined_vars( $font_css );
		$sections[] = array(
			'id'      => 'css-vars',
			'label'   => __( 'CSS variables resolve', 'ekwa' ),
			'status'  => empty( $undefined ) ? 'pass' : 'fail',
			'message' => empty( $undefined )
				? __( 'Every var() in the stylesheet has a definition. ✓', 'ekwa' )
				: sprintf(
					/* translators: %s: comma-separated variable names. */
					__( 'Used but never defined: %s. Every declaration that reads one of these is thrown away by the browser — no error, the style just never applies. Declare them in :root (a font goes on the Fonts tab instead), or give each use a fallback like var(--color-text, #333).', 'ekwa' ),
					'--' . implode( ', --', array_slice( $undefined, 0, 12 ) ) . ( count( $undefined ) > 12 ? '…' : '' )
				),
			'fix'     => empty( $undefined ) ? '' : ":root {\n" . implode( "\n", array_map( function ( $n ) {
				return '  --' . $n . ': /* value */;';
			}, array_slice( $undefined, 0, 12 ) ) ) . "\n}",
		);
	}

	// ── Icon font ────────────────────────────────────────────────────────
	// The theme bundles Font Awesome 6 Free and loads no other icon font, so a
	// mockup built on Remix/Bootstrap/Material icons converts to markup whose
	// glyphs are blank boxes. The converter rewrites what it can recognise;
	// this reports it up front, plus anything it would not be able to map.
	$foreign = ekwa_mockup_foreign_icons( $html );
	if ( ! empty( $foreign['classes'] ) ) {
		$sections[] = array(
			'id'      => 'icons',
			'label'   => __( 'Icon font', 'ekwa' ),
			'status'  => empty( $foreign['unmapped'] ) ? 'warn' : 'fail',
			'message' => empty( $foreign['unmapped'] )
				? sprintf(
					/* translators: 1: count, 2: comma-separated class names. */
					__( '%1$d icon(s) use a font other than Font Awesome (%2$s). The converter will rewrite them all to Font Awesome automatically, but the mockup itself will keep looking different until you swap them — the theme loads Font Awesome 6 Free only.', 'ekwa' ),
					count( $foreign['classes'] ),
					implode( ', ', array_slice( $foreign['classes'], 0, 8 ) ) . ( count( $foreign['classes'] ) > 8 ? '…' : '' )
				)
				: sprintf(
					/* translators: 1: count, 2: comma-separated class names. */
					__( '%1$d icon(s) use a font other than Font Awesome, and %2$s have no Font Awesome equivalent — those will render blank. Replace them with fa-solid/fa-brands classes from the Free set (fontawesome.com/search).', 'ekwa' ),
					count( $foreign['classes'] ),
					implode( ', ', array_slice( $foreign['unmapped'], 0, 8 ) ) . ( count( $foreign['unmapped'] ) > 8 ? '…' : '' )
				),
			'fix'     => '<i class="fa-solid fa-phone" aria-hidden="true"></i>' . "\n"
				. '<i class="fa-solid fa-location-dot" aria-hidden="true"></i>' . "\n"
				. '<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>' . "\n"
				. '<i class="fa-brands fa-facebook-f"></i>',
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
