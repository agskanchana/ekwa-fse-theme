<?php
/**
 * Block Spec registry for the AI Block Builder.
 *
 * Sibling to inc/ekwa-ai-hints.php — but where the hints file teaches the AI
 * detector-friendly *HTML* (for the HTML→block converter), THIS file teaches
 * the AI how to serialize Ekwa/core blocks as Gutenberg block-comment markup
 * directly, so no HTML→block conversion step is needed.
 *
 * Consumed by inc/ekwa-ai-generate-blocks.php to build the "BLOCK SPEC" section
 * of the system prompt. Filterable so child themes / mu-plugins can extend it.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of block specs, keyed by short slug.
 *
 * Each entry:
 *   - block:    Block name (e.g. "ekwa/div").
 *   - type:     'container' (paired open/close comments wrapping inner blocks)
 *               or 'leaf' (self-closing `/-->`, no inner blocks).
 *   - contexts: Which builder contexts the block applies to
 *               ('header', 'footer', 'section'). Empty = all contexts.
 *   - desc:     One-line description.
 *   - attrs:    Human-readable list of the meaningful attributes.
 *   - examples: Verbatim block-markup snippets the AI can emit or vary.
 *
 * @return array<string,array<string,mixed>>
 */
function ekwa_ai_block_spec_registry() {
	$specs = array(

		// ─── Structure (all contexts) ───────────────────────────────────────

		'div' => array(
			'block'    => 'ekwa/div',
			'type'     => 'container',
			'contexts' => array(),
			'desc'     => 'Generic clean wrapper. Renders <tagName> with your className and children. Your primary layout building block.',
			'attrs'    => array(
				'tagName — div|section|header|footer|nav|main|aside|article|a|span|small|strong|em|figcaption (default "div")',
				'className — your semantic CSS class(es)',
				'anchor — optional id',
			),
			'examples' => array(
				"<!-- wp:ekwa/div {\"tagName\":\"header\",\"className\":\"site-header\"} -->\n<!-- wp:ekwa/div {\"className\":\"site-header__inner\"} -->\n<!-- inner blocks here -->\n<!-- /wp:ekwa/div -->\n<!-- /wp:ekwa/div -->",
			),
		),

		'flex' => array(
			'block'    => 'ekwa/flex',
			'type'     => 'container',
			'contexts' => array(),
			'desc'     => 'Flexbox row/column. Renders display:flex with the attributes below; gap lives in your CSS via the className.',
			'attrs'    => array(
				'direction — row|column (default "row")',
				'justifyContent — flex-start|center|flex-end|space-between|space-around (default "flex-start")',
				'alignItems — center|flex-start|flex-end|stretch (default "center")',
				'wrap — wrap|nowrap (default "wrap")',
				'tagName — div|nav|header|footer|aside (default "div")',
				'className — for gap and any extra styling',
			),
			'examples' => array(
				"<!-- wp:ekwa/flex {\"justifyContent\":\"space-between\",\"alignItems\":\"center\",\"wrap\":\"nowrap\",\"className\":\"header-bar\"} -->\n<!-- inner blocks -->\n<!-- /wp:ekwa/flex -->",
			),
		),

		'grid' => array(
			'block'    => 'ekwa/grid',
			'type'     => 'container',
			'contexts' => array(),
			'desc'     => 'CSS Grid. Renders display:grid with responsive column counts. Set the gap in your CSS via the className.',
			'attrs'    => array(
				'columns — desktop column count (default 3)',
				'columnWidths — optional explicit template, e.g. "2fr 1fr" (overrides columns)',
				'tabletColumns — default 2',
				'mobileColumns — default 1',
				'className — for gap and extra styling',
			),
			'examples' => array(
				"<!-- wp:ekwa/grid {\"columns\":3,\"tabletColumns\":2,\"mobileColumns\":1,\"className\":\"services-grid\"} -->\n<!-- one inner block per cell -->\n<!-- /wp:ekwa/grid -->",
			),
		),

		'container' => array(
			'block'    => 'ekwa/container',
			'type'     => 'container',
			'contexts' => array(),
			'desc'     => 'Centered max-width wrapper (margin auto). Use to constrain a full-width section\'s inner content.',
			'attrs'    => array(
				'maxWidth — CSS length, e.g. "1280px" (default "1280px")',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/container {\"maxWidth\":\"1200px\",\"className\":\"footer-inner\"} -->\n<!-- inner blocks -->\n<!-- /wp:ekwa/container -->",
			),
		),

		'button-group' => array(
			'block'    => 'ekwa/button-group',
			'type'     => 'container',
			'contexts' => array(),
			'desc'     => 'Flex wrapper for grouping ekwa/button children.',
			'attrs'    => array(
				'justifyContent — flex-start|center|flex-end|space-between (default "flex-start")',
				'direction — row|column (default "row")',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/button-group {\"justifyContent\":\"center\"} -->\n<!-- wp:ekwa/button {\"text\":\"Book Now\",\"url\":\"#\"} /-->\n<!-- /wp:ekwa/button-group -->",
			),
		),

		'figure' => array(
			'block'    => 'ekwa/figure',
			'type'     => 'container',
			'contexts' => array(),
			'desc'     => 'Clean <figure> wrapper. Pair ekwa/image with an ekwa/text (tagName "figcaption") caption.',
			'attrs'    => array( 'className' ),
			'examples' => array(
				"<!-- wp:ekwa/figure {\"className\":\"card-figure\"} -->\n<!-- wp:ekwa/image {\"src\":\"https://placehold.co/600x400\",\"alt\":\"\"} /-->\n<!-- /wp:ekwa/figure -->",
			),
		),

		'carousel' => array(
			'block'    => 'ekwa/carousel',
			'type'     => 'container',
			'contexts' => array(),
			'desc'     => 'Responsive carousel. Each top-level inner block becomes one slide.',
			'attrs'    => array(
				'desktopItems (default 3), tabletItems (default 2), mobileItems (default 1)',
				'showArrows (bool), showDots (bool), autoplay (bool), loop (bool), gap (px)',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/carousel {\"desktopItems\":3,\"showDots\":true} -->\n<!-- wp:ekwa/div {\"className\":\"slide\"} --> ... <!-- /wp:ekwa/div -->\n<!-- /wp:ekwa/carousel -->",
			),
		),

		// ─── Content / leaf (all contexts) ──────────────────────────────────

		'text' => array(
			'block'    => 'ekwa/text',
			'type'     => 'leaf',
			'contexts' => array(),
			'desc'     => 'A single inline element. The visible text is the "text" attribute (NOT inner blocks).',
			'attrs'    => array(
				'tagName — span|small|strong|em|mark|time|label|sup|sub|figcaption (default "span")',
				'text — the visible text',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/text {\"tagName\":\"small\",\"text\":\"Open today until 5pm\",\"className\":\"status\"} /-->",
			),
		),

		'image' => array(
			'block'    => 'ekwa/image',
			'type'     => 'leaf',
			'contexts' => array(),
			'desc'     => 'Clean <img>, no figure/wp-block-image wrapper. Use https://placehold.co/WIDTHxHEIGHT for placeholders.',
			'attrs'    => array(
				'src (required), alt, width, height',
				'loading — lazy|eager (default "lazy")',
				'hero — bool, set true for the above-the-fold LCP image',
				'objectFit — cover|contain|… (optional)',
				'linkUrl — optional wrapping link',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/image {\"src\":\"https://placehold.co/1200x600\",\"alt\":\"Office exterior\",\"width\":\"1200\",\"height\":\"600\"} /-->",
			),
		),

		'button' => array(
			'block'    => 'ekwa/button',
			'type'     => 'leaf',
			'contexts' => array(),
			'desc'     => 'A single <a> (default) or <button>. Renders classes ekwa-btn ekwa-btn--{variant}; style those in your CSS. The label is the "text" attribute (NOT inner content) — a button with no "text" renders empty.',
			'attrs'    => array(
				'text — label (REQUIRED; without it the button is empty)',
				'url — href (for htmlTag "a")',
				'htmlTag — a|button (default "a")',
				'variant — filled|outline|… → class ekwa-btn--{variant} (default "filled")',
				'size — default|small|large',
				'iconClass — optional Font Awesome class, iconPosition — left|right',
				'newTab — bool, className',
			),
			'examples' => array(
				// Primary filled CTA with a leading icon.
				"<!-- wp:ekwa/button {\"text\":\"Book Appointment\",\"url\":\"#\",\"variant\":\"filled\",\"iconClass\":\"fa-solid fa-calendar\"} /-->",
				// Secondary outline button, opens in a new tab.
				"<!-- wp:ekwa/button {\"text\":\"Learn More\",\"url\":\"/services\",\"variant\":\"outline\",\"size\":\"large\"} /-->",
				// A CTA row — two buttons side by side. Put gap/layout in CSS via the flex className.
				"<!-- wp:ekwa/flex {\"className\":\"cta-row\"} -->\n<!-- wp:ekwa/button {\"text\":\"Get Started\",\"url\":\"#\",\"variant\":\"filled\"} /-->\n<!-- wp:ekwa/button {\"text\":\"Call Us\",\"url\":\"tel:+15551234567\",\"variant\":\"outline\",\"iconClass\":\"fa-solid fa-phone\"} /-->\n<!-- /wp:ekwa/flex -->",
			),
		),

		'link' => array(
			'block'    => 'ekwa/link',
			'type'     => 'leaf',
			'contexts' => array(),
			'desc'     => 'Plain anchor — no button styling. The visible text is the "text" attribute.',
			'attrs'    => array( 'url', 'text', 'newTab — bool', 'rel', 'className' ),
			'examples' => array(
				"<!-- wp:ekwa/link {\"url\":\"/about\",\"text\":\"Read more\",\"className\":\"more-link\"} /-->",
			),
		),

		'icon' => array(
			'block'    => 'ekwa/icon',
			'type'     => 'leaf',
			'contexts' => array(),
			'desc'     => 'A standalone Font Awesome icon.',
			'attrs'    => array(
				'iconClass — FA class, e.g. "fa-solid fa-tooth" (default "fa-solid fa-star")',
				'size — px number, color — CSS color',
				'wrapperClass (default "way-icon"), className',
				'url — optional link',
			),
			'examples' => array(
				"<!-- wp:ekwa/icon {\"iconClass\":\"fa-solid fa-tooth\",\"size\":32,\"color\":\"#1a6ef5\"} /-->",
			),
		),

		'video' => array(
			'block'    => 'ekwa/video',
			'type'     => 'leaf',
			'contexts' => array(),
			'desc'     => 'Clean <video> element.',
			'attrs'    => array(
				'src (required), poster',
				'autoplay, loop, muted, controls, playsinline — bools',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/video {\"src\":\"https://example.com/video.mp4\",\"controls\":true} /-->",
			),
		),

		// core text blocks — STATIC blocks, so the inner HTML must match WordPress's
		// expected save output EXACTLY (incl. the wp-block-* classes) or the block
		// will be flagged invalid. Emit them precisely as shown.
		'paragraph' => array(
			'block'    => 'core/paragraph',
			'type'     => 'container',
			'contexts' => array(),
			'desc'     => 'Body copy. Emit the <p> exactly as shown (no extra class needed for a plain paragraph).',
			'attrs'    => array( 'align via {"align":"center"} adds class has-text-align-center' ),
			'examples' => array(
				"<!-- wp:paragraph -->\n<p>Your paragraph text.</p>\n<!-- /wp:paragraph -->",
			),
		),

		'heading' => array(
			'block'    => 'core/heading',
			'type'     => 'container',
			'contexts' => array(),
			'desc'     => 'Headings h1–h6. IMPORTANT: include class="wp-block-heading" exactly, and match the level to the tag.',
			'attrs'    => array( 'level — 1..6 (controls the h tag)', 'textAlign via {"textAlign":"center"}' ),
			'examples' => array(
				"<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Section title</h2>\n<!-- /wp:heading -->",
			),
		),

		'list' => array(
			'block'    => 'core/list',
			'type'     => 'container',
			'contexts' => array(),
			'desc'     => 'Bulleted/numbered list. Each item is a nested core/list-item block; emit the wp-block-list class exactly.',
			'attrs'    => array( 'ordered via {"ordered":true} (use <ol>)' ),
			'examples' => array(
				"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>First item</li>\n<!-- /wp:list-item -->\n<!-- wp:list-item -->\n<li>Second item</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
			),
		),

		// ─── Header data blocks (content filled at runtime) ─────────────────

		'site-logo' => array(
			'block'    => 'core/site-logo',
			'type'     => 'leaf',
			'contexts' => array( 'header' ),
			'desc'     => 'The site logo (from Settings → Site Identity). Set a width only.',
			'attrs'    => array( 'width — px number' ),
			'examples' => array( "<!-- wp:site-logo {\"width\":180} /-->" ),
		),

		'svg-logo' => array(
			'block'    => 'ekwa/svg-logo',
			'type'     => 'leaf',
			'contexts' => array( 'header', 'footer' ),
			'desc'     => 'Inline-SVG site logo (markup set in Theme Settings → Branding). Links home by default.',
			'attrs'    => array( 'linkToHome — bool (default true)', 'maxWidth — px number', 'ariaLabel', 'className' ),
			'examples' => array( "<!-- wp:ekwa/svg-logo {\"maxWidth\":200} /-->" ),
		),

		'header-menu' => array(
			'block'    => 'ekwa/header-menu',
			'type'     => 'leaf',
			'contexts' => array( 'header' ),
			'desc'     => 'PRIMARY header navigation with submenus/mega-menus. Items come from the Main Menu location at runtime — DO NOT type menu items.',
			'attrs'    => array(
				'alignment — left|center|right (default "center")',
				'itemGap — px between items (default 24)',
				'submenuMinWidth — px (default 220)',
				'className',
			),
			'examples' => array( "<!-- wp:ekwa/header-menu {\"alignment\":\"center\",\"itemGap\":28} /-->" ),
		),

		'phone' => array(
			'block'    => 'ekwa/phone',
			'type'     => 'leaf',
			'contexts' => array( 'header', 'footer' ),
			'desc'     => 'A single click-to-call number. The number comes from a saved location — set attributes only, never type a real number.',
			'attrs'    => array(
				'type — new|existing (patient line, default "new")',
				'location — saved location number (default 1)',
				'prefix — label text (e.g. "Call us"), showPrefix — bool',
				'showIcon — bool, iconClass — FA class (default "fa-solid fa-phone")',
				'className',
			),
			'examples' => array( "<!-- wp:ekwa/phone {\"type\":\"new\",\"prefix\":\"New Patients\",\"showPrefix\":true} /-->" ),
		),

		'phone-dropdown' => array(
			'block'    => 'ekwa/phone-dropdown',
			'type'     => 'leaf',
			'contexts' => array( 'header' ),
			'desc'     => 'A call button that opens a dropdown of all locations/lines (rendered from settings). Set the trigger label only.',
			'attrs'    => array( 'label (default "Call Us")', 'iconClass (default "fa-solid fa-phone")', 'showIcon — bool', 'className' ),
			'examples' => array( "<!-- wp:ekwa/phone-dropdown {\"label\":\"Call Us\"} /-->" ),
		),

		'address' => array(
			'block'    => 'ekwa/address',
			'type'     => 'leaf',
			'contexts' => array( 'header', 'footer' ),
			'desc'     => 'A single address / directions link for a saved location. Content comes from settings.',
			'attrs'    => array(
				'location — saved location number (default 1)',
				'mode — full|text|address (default "full")',
				'label — optional override',
				'showIcon — bool, iconClass (default "fa-solid fa-location-dot")',
				'className',
			),
			'examples' => array( "<!-- wp:ekwa/address {\"location\":1,\"mode\":\"full\"} /-->" ),
		),

		'address-dropdown' => array(
			'block'    => 'ekwa/address-dropdown',
			'type'     => 'leaf',
			'contexts' => array( 'header', 'footer' ),
			'desc'     => 'A directions button that opens a dropdown of all locations (rendered from settings). Set the trigger label only.',
			'attrs'    => array( 'label (default "Directions")', 'iconClass (default "fa-solid fa-location-dot")', 'showIcon — bool', 'className' ),
			'examples' => array( "<!-- wp:ekwa/address-dropdown {\"label\":\"Directions\"} /-->" ),
		),

		'search' => array(
			'block'    => 'ekwa/search',
			'type'     => 'leaf',
			'contexts' => array( 'header' ),
			'desc'     => 'A search icon/button that opens a full-screen search overlay.',
			'attrs'    => array( 'iconSize — px (default 20)', 'placeholder', 'buttonLabel', 'className' ),
			'examples' => array( "<!-- wp:ekwa/search {\"iconSize\":20} /-->" ),
		),

		'social' => array(
			'block'    => 'ekwa/social',
			'type'     => 'leaf',
			'contexts' => array( 'header', 'footer' ),
			'desc'     => 'Social icon row. The links/icons come from Theme Settings — set presentation only.',
			'attrs'    => array( 'showShare — bool', 'iconSize — px (0 = default)', 'iconColor — CSS color', 'className' ),
			'examples' => array( "<!-- wp:ekwa/social {\"showShare\":false,\"iconSize\":22} /-->" ),
		),

		// ─── Footer data blocks ─────────────────────────────────────────────

		'map' => array(
			'block'    => 'ekwa/map',
			'type'     => 'leaf',
			'contexts' => array( 'footer', 'section' ),
			'desc'     => 'Google Map embed. The embed code is pasted by the user after insertion — emit the block with default attrs.',
			'attrs'    => array( 'height — px (default 450)', 'colorful — bool', 'lazyLoad — bool' ),
			'examples' => array( "<!-- wp:ekwa/map {\"height\":400} /-->" ),
		),

		'navigation' => array(
			'block'    => 'core/navigation',
			'type'     => 'leaf',
			'contexts' => array( 'footer' ),
			'desc'     => 'Footer / secondary navigation. Menu is assigned by the user after insertion. (For the PRIMARY header menu use ekwa/header-menu instead.)',
			'attrs'    => array( 'overlayMenu — never|mobile|always' ),
			'examples' => array( "<!-- wp:navigation {\"overlayMenu\":\"never\"} /-->" ),
		),

		'copyright' => array(
			'block'    => 'ekwa/copyright',
			'type'     => 'leaf',
			'contexts' => array( 'footer' ),
			'desc'     => 'Copyright line (practice name + current year), rendered automatically. No attributes.',
			'attrs'    => array(),
			'examples' => array( "<!-- wp:ekwa/copyright /-->" ),
		),

		'hours' => array(
			'block'    => 'ekwa/hours',
			'type'     => 'leaf',
			'contexts' => array( 'footer' ),
			'desc'     => 'Working hours for a saved location, rendered from settings.',
			'attrs'    => array(
				'location — saved location number (default 1)',
				'shortDays — bool, showClosed — bool, showNotes — bool',
				'closedLabel (default "Closed")',
				'className',
			),
			'examples' => array( "<!-- wp:ekwa/hours {\"location\":1} /-->" ),
		),

		'scroll-top' => array(
			'block'    => 'ekwa/scroll-top',
			'type'     => 'leaf',
			'contexts' => array( 'footer' ),
			'desc'     => 'A back-to-top button that appears after scrolling.',
			'attrs'    => array( 'iconSize, buttonSize, iconColor, buttonBg, borderRadius — numbers/colors', 'className' ),
			'examples' => array( "<!-- wp:ekwa/scroll-top /-->" ),
		),

		// ─── Section / page content blocks ──────────────────────────────────

		'faq' => array(
			'block'    => 'ekwa/faq',
			'type'     => 'container',
			'contexts' => array( 'section' ),
			'desc'     => 'Collapsible FAQ list with FAQPage schema. Inner blocks are ekwa/faq-item only.',
			'attrs'    => array( 'accentColor — CSS color', 'accordion — bool (one open at a time)', 'firstOpen — bool', 'emitSchema — bool (default true)' ),
			'examples' => array(
				"<!-- wp:ekwa/faq {\"accentColor\":\"#1a6ef5\"} -->\n<!-- wp:ekwa/faq-item {\"question\":\"How long is a visit?\"} -->\n<!-- wp:paragraph -->\n<p>About 45 minutes.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:ekwa/faq-item -->\n<!-- /wp:ekwa/faq -->",
			),
		),

		'faq-item' => array(
			'block'    => 'ekwa/faq-item',
			'type'     => 'container',
			'contexts' => array( 'section' ),
			'desc'     => 'One Q/A pair inside ekwa/faq. The question is the "question" attribute; the answer is the inner blocks (paragraphs, lists, …).',
			'attrs'    => array( 'question — the question text', 'defaultOpen — bool' ),
			'examples' => array(
				"<!-- wp:ekwa/faq-item {\"question\":\"Do you accept insurance?\"} -->\n<!-- wp:paragraph -->\n<p>Yes, most major plans.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:ekwa/faq-item -->",
			),
		),

		'related-posts' => array(
			'block'    => 'ekwa/related-posts',
			'type'     => 'leaf',
			'contexts' => array( 'section', 'footer' ),
			'desc'     => 'Lists related blog posts by category context. Rendered automatically.',
			'attrs'    => array( 'count — number (default 3)', 'headingLevel — h2|h3|…', 'hideHeading — bool' ),
			'examples' => array( "<!-- wp:ekwa/related-posts {\"count\":3} /-->" ),
		),

		'elfsight-review' => array(
			'block'    => 'ekwa/elfsight-review',
			'type'     => 'leaf',
			'contexts' => array( 'section', 'footer' ),
			'desc'     => 'Elfsight reviews widget. The embed code is pasted by the user after insertion.',
			'attrs'    => array(),
			'examples' => array( "<!-- wp:ekwa/elfsight-review /-->" ),
		),

	);

	/**
	 * Filter the AI Block Builder spec registry.
	 *
	 * @param array $specs Registry keyed by short slug.
	 */
	return apply_filters( 'ekwa_ai_block_specs', $specs );
}

/**
 * Build the "BLOCK SPEC" section appended to the AI system prompt.
 *
 * @param string $context One of: 'header', 'footer', 'section'. Anything else
 *                        falls back to 'section'.
 * @return string Empty string when no specs apply.
 */
function ekwa_ai_build_block_spec_section( $context ) {
	$context = in_array( $context, array( 'header', 'footer', 'section' ), true ) ? $context : 'section';
	$all     = ekwa_ai_block_spec_registry();

	$applicable = array();
	foreach ( $all as $key => $spec ) {
		if ( empty( $spec['contexts'] ) || in_array( $context, $spec['contexts'], true ) ) {
			$applicable[ $key ] = $spec;
		}
	}
	if ( empty( $applicable ) ) {
		return '';
	}

	$out  = "\n\nBLOCK SPEC (context: " . strtoupper( $context ) . "):\n";
	$out .= "Only the blocks below are allowed. Use the EXACT block name and serialization shown. "
		. "Container blocks wrap inner blocks between paired comments; leaf blocks are self-closing ( /--> ). "
		. "Attribute JSON must be strict, valid JSON. Prefer ekwa/* blocks; they are server-rendered so they never fail block validation.\n\n";

	foreach ( $applicable as $spec ) {
		$out .= '### ' . $spec['block'] . ' (' . $spec['type'] . ") — " . $spec['desc'] . "\n";
		if ( ! empty( $spec['attrs'] ) ) {
			foreach ( $spec['attrs'] as $attr ) {
				$out .= '  - ' . $attr . "\n";
			}
		}
		foreach ( $spec['examples'] as $ex ) {
			$out .= "  Example:\n" . ekwa_ai_indent_block( $ex ) . "\n";
		}
		$out .= "\n";
	}

	return $out;
}

/**
 * Indent a multi-line example block for readability in the prompt.
 *
 * @param string $text
 * @return string
 */
function ekwa_ai_indent_block( $text ) {
	$lines = explode( "\n", $text );
	foreach ( $lines as $i => $line ) {
		$lines[ $i ] = '    ' . $line;
	}
	return implode( "\n", $lines );
}
