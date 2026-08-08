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
			'desc'     => 'Generic clean wrapper. Renders <tagName> with your className and children. Your ONLY layout building block — express flex rows, grids and centered max-width containers by writing the CSS (display:flex/grid, max-width, gap, @media) under the className in the scoped stylesheet.',
			'attrs'    => array(
				'tagName — div|section|header|footer|nav|main|aside|article|a|span|small|strong|em|figcaption (default "div")',
				'className — your semantic CSS class(es)',
				'anchor — optional id',
				'tagName "a" only: target, rel, and linkType — external|internal|media|appointment (default "external"); pair with url, or pageId, or mediaUrl+mediaFileId',
				'tagName "a" only: lightbox — bool, opens href in the site lightbox (image, YouTube/Vimeo or MP4) instead of navigating; lightboxGroup groups triggers into one gallery; lightboxCaption sets the overlay caption. Use this for a linked thumbnail or a whole clickable card.',
			),
			'examples' => array(
				"<!-- wp:ekwa/div {\"tagName\":\"header\",\"className\":\"site-header\"} -->\n<!-- wp:ekwa/div {\"className\":\"site-header__inner\"} -->\n<!-- inner blocks here -->\n<!-- /wp:ekwa/div -->\n<!-- /wp:ekwa/div -->",
			),
		),

		// NOTE: ekwa/flex, ekwa/grid, ekwa/container and ekwa/card-link are
		// deprecated — do not reintroduce them here. All layout (flex rows,
		// grids, centered max-width wrappers) is expressed as ekwa/div with the
		// layout CSS written in the scoped stylesheet under the className.

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
			'desc'     => 'Responsive content carousel, vanilla JS (no jQuery). Each top-level inner block becomes one slide. This is what a mockup\'s Owl Carousel / Slick / Swiper markup must become — the theme loads none of those libraries.',
			'attrs'    => array(
				'desktopItems (default 3), tabletItems (default 2), mobileItems (default 1)',
				'showArrows (bool), showDots (bool), autoplay (bool), loop (bool), gap (px)',
				'arrowPosition — inside|outside|top-left|top-center|top-right|bottom-left|bottom-center|bottom-right (default "inside"). "inside" overlays the slide edges; every other value reserves its own space so the arrows never cover a slide.',
				'arrowOffset (px from the slides), arrowGap (px between the paired arrows)',
				'prevIcon / nextIcon — optional raw SVG markup replacing the default chevron on that arrow. Omit both unless the mockup shows a distinctly non-chevron arrow; the SVG must be a bare <svg>…</svg> (no wrapper), and fill="currentColor" keeps it matching the button hover state.',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/carousel {\"desktopItems\":3,\"showDots\":true} -->\n<!-- wp:ekwa/div {\"className\":\"slide\"} --> ... <!-- /wp:ekwa/div -->\n<!-- /wp:ekwa/carousel -->",
				"<!-- wp:ekwa/carousel {\"desktopItems\":2,\"showArrows\":true,\"showDots\":false,\"arrowPosition\":\"outside\"} -->\n<!-- wp:ekwa/div {\"className\":\"ba-card\"} --> ... <!-- /wp:ekwa/div -->\n<!-- /wp:ekwa/carousel -->",
			),
		),

		'slider' => array(
			'block'    => 'ekwa/slider',
			'type'     => 'container',
			'contexts' => array( 'section' ),
			'desc'     => 'Full-width HERO slider — the PREFERRED way to build a home-page hero (use it even for a single slide). Children are ekwa/slide blocks (background image + overlay); each slide holds ekwa/slide-content groups with entrance animations. Arrows/dots/keyboard/swipe/autoplay are built in — never hand-build slider chrome.',
			'attrs'    => array(
				'transition — fade|slide|slide-up|zoom|zoom-out|blur|parallax|wipe|flip (default "fade")',
				'autoplay (bool, default true), interval (ms, default 6000), loop (bool), pauseOnHover (bool)',
				'showArrows (bool), showDots (bool)',
				'minHeight — CSS length (default "80vh")',
				'align — full for edge-to-edge heroes',
			),
			'examples' => array(
				"<!-- wp:ekwa/slider {\"transition\":\"zoom\",\"minHeight\":\"85vh\",\"align\":\"full\"} -->\n<!-- wp:ekwa/slide {\"bgImage\":\"https://placehold.co/1600x900\",\"overlayOpacity\":40} -->\n<!-- wp:ekwa/slide-content {\"animation\":\"fadeInDown\",\"delay\":200} -->\n<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Headline</h1>\n<!-- /wp:heading -->\n<!-- /wp:ekwa/slide-content -->\n<!-- wp:ekwa/slide-content {\"animation\":\"fadeInUp\",\"delay\":500} -->\n<!-- wp:ekwa/button {\"text\":\"Book Now\",\"url\":\"#\"} /-->\n<!-- /wp:ekwa/slide-content -->\n<!-- /wp:ekwa/slide -->\n<!-- /wp:ekwa/slider -->",
			),
		),

		'slide-content' => array(
			'block'    => 'ekwa/slide-content',
			'type'     => 'container',
			'contexts' => array( 'section' ),
			'desc'     => 'Animated content group INSIDE an ekwa/slide. The entrance replays every time its slide activates.',
			'attrs'    => array(
				'animation — none|fadeIn|fadeInUp|fadeInDown|fadeInLeft|fadeInRight|zoomIn|slideInUp|blurIn (default "fadeInUp")',
				'delay (ms, default 0), duration (ms, default 800)',
			),
			'examples' => array(
				"<!-- wp:ekwa/slide-content {\"animation\":\"fadeInLeft\",\"delay\":400} -->\n<!-- wp:paragraph -->\n<p>Supporting copy.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:ekwa/slide-content -->",
			),
		),

		'hero-video' => array(
			'block'    => 'ekwa/hero-video',
			'type'     => 'container',
			'contexts' => array( 'section' ),
			'desc'     => 'Background-video hero (muted, looped, mobile-safe autoplay, accessible pause button). Children are the caption blocks shown over the video.',
			'attrs'    => array(
				'videoUrl (required, mp4/webm), posterUrl — first paint + reduced-motion fallback',
				'overlayColor (default "#0b1622"), overlayOpacity (0–90, default 40)',
				'minHeight (default "80vh"), contentAlign — left|center|right, showPauseButton (bool)',
				'align — full for edge-to-edge',
			),
			'examples' => array(
				"<!-- wp:ekwa/hero-video {\"videoUrl\":\"https://example.com/loop.mp4\",\"posterUrl\":\"https://placehold.co/1600x900\",\"contentAlign\":\"center\",\"align\":\"full\"} -->\n<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Welcome</h1>\n<!-- /wp:heading -->\n<!-- /wp:ekwa/hero-video -->",
			),
		),

		// ─── Content / leaf (all contexts) ──────────────────────────────────

		'text' => array(
			'block'    => 'ekwa/text',
			'type'     => 'leaf',
			'contexts' => array(),
			'desc'     => 'A single inline element. The visible text is the "text" attribute (NOT inner blocks).',
			'attrs'    => array(
				'tagName — span|small|strong|b|em|i|u|mark|time|label|sup|sub|figcaption (default "span")',
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
				'linkType — external|internal|media|appointment (default "external"); pair with url, or pageId, or mediaUrl+mediaFileId. Ignored when lightbox is on',
				'lightbox — bool, click to open the image full-size in the site lightbox',
				'lightbox opens whatever linkType/url points at, or the image itself when no link is set — do NOT add a second URL for it',
				'lightboxGroup — images sharing this name open as ONE swipeable gallery',
				'lightboxCaption — optional caption shown in the overlay',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/image {\"src\":\"https://placehold.co/1200x600\",\"alt\":\"Office exterior\",\"width\":\"1200\",\"height\":\"600\"} /-->",
				"<!-- wp:ekwa/image {\"src\":\"https://placehold.co/400x300\",\"alt\":\"Case 1 after\",\"lightbox\":true,\"lightboxGroup\":\"smile-gallery\"} /-->",
			),
		),

		'youtube-video' => array(
			'block'    => 'ekwa/youtube-video',
			'type'     => 'leaf',
			'contexts' => array(),
			'desc'     => 'A YouTube video: paste a watch/share URL and the block auto-fetches title, thumbnail and duration server-side. Click-to-play inline, or an optional lightbox popup. Schema.org VideoObject markup included.',
			'attrs'    => array(
				'videoUrl (required) — any youtube.com/youtu.be watch, share or embed URL',
				'showTitle (default true), showDescription (default false)',
				'openInLightbox — bool, plays in the site lightbox instead of inline (default false)',
				'transcript — plain text, blank line between paragraphs; showTranscript to reveal the toggle button',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/youtube-video {\"videoUrl\":\"https://www.youtube.com/watch?v=dQw4w9WgXcQ\"} /-->",
			),
		),

		'vimeo-video' => array(
			'block'    => 'ekwa/vimeo-video',
			'type'     => 'leaf',
			'contexts' => array(),
			'desc'     => 'Same as ekwa/youtube-video but for a vimeo.com URL. No automatic transcript fetch (Vimeo has no public captions API) — transcript text must be pasted in manually.',
			'attrs'    => array(
				'videoUrl (required) — any vimeo.com URL',
				'showTitle (default true), showDescription (default false)',
				'openInLightbox — bool, plays in the site lightbox instead of inline (default false)',
				'transcript — plain text, blank line between paragraphs; showTranscript to reveal the toggle button',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/vimeo-video {\"videoUrl\":\"https://vimeo.com/76979871\"} /-->",
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
				// A CTA row — two buttons side by side. Write the flex/gap CSS under the className.
				"<!-- wp:ekwa/div {\"className\":\"cta-row\"} -->\n<!-- wp:ekwa/button {\"text\":\"Get Started\",\"url\":\"#\",\"variant\":\"filled\"} /-->\n<!-- wp:ekwa/button {\"text\":\"Call Us\",\"url\":\"tel:+15551234567\",\"variant\":\"outline\",\"iconClass\":\"fa-solid fa-phone\"} /-->\n<!-- /wp:ekwa/div -->",
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
			'desc'     => 'The site logo (from Settings → Site Identity). Set a width only. The standard logo block — use it whenever no SVG logo markup is configured.',
			'attrs'    => array( 'width — px number' ),
			'examples' => array( "<!-- wp:site-logo {\"width\":180} /-->" ),
		),

		'svg-logo' => array(
			'block'    => 'ekwa/svg-logo',
			'type'     => 'leaf',
			'contexts' => array( 'header', 'footer' ),
			'desc'     => 'Inline-SVG site logo (markup set in Theme Settings → Branding). Links home by default. Use ONLY when the site has SVG logo markup configured (the header rules say which logo block to use); never fake a logo with ekwa/image or text.',
			'attrs'    => array( 'linkToHome — bool (default true)', 'maxWidth — px number', 'ariaLabel', 'className' ),
			'examples' => array( "<!-- wp:ekwa/svg-logo {\"maxWidth\":200} /-->" ),
		),

		'header-menu' => array(
			'block'    => 'ekwa/header-menu',
			'type'     => 'leaf',
			'contexts' => array( 'header' ),
			'desc'     => 'PRIMARY header navigation with submenus/mega-menus. Items come from the Main Menu location at runtime — DO NOT type menu items. The block renders its own structure (nav > ul > li > a, nested ul for dropdowns, a panel of columns for mega menus); "classMap" makes it wear the mockup\'s class names so the mockup CSS still matches.',
			'attrs'    => array(
				'alignment — left|center|right (default "center")',
				'itemGap — px between items (default 24)',
				'submenuMinWidth — px (default 220)',
				'classMap — object mapping the mockup\'s classes onto the menu\'s parts. Keys: nav, menu, item, hasChildren, link, label, caret, submenu, submenuItem, submenuLink, megaParent, mega, megaGrid, megaColumn, megaImageWrap, megaImage, megaHeading, megaList, megaItem, megaLink. Copy the class from the matching element in the source nav; omit a key when the source has no extra class there. Leave OUT state classes (active/current) and any ekwa-*/menu-item* class the block already emits.',
				'caretTag — "i" when the mockup draws the dropdown arrow with an icon font, else "span"',
				'wrapLabel — false when the mockup puts link text directly in the <a> rather than a <span>',
				'useBlockCss — false when the mockup\'s CSS fully styles the menu',
				'className',
			),
			'examples' => array(
				"<!-- wp:ekwa/header-menu {\"alignment\":\"center\",\"itemGap\":28} /-->",
				"<!-- wp:ekwa/header-menu {\"classMap\":{\"nav\":\"main-nav\",\"menu\":\"nav-list\",\"item\":\"nav-item\",\"link\":\"nav-link\",\"hasChildren\":\"has-dropdown\",\"submenu\":\"dropdown\",\"megaParent\":\"mega-menu-parent\",\"mega\":\"mega-dropdown\",\"megaColumn\":\"mega-column\"},\"caretTag\":\"i\"} /-->",
			),
		),

		'phone' => array(
			'block'    => 'ekwa/phone',
			'type'     => 'leaf',
			'contexts' => array( 'header', 'footer' ),
			'desc'     => 'A single click-to-call number. The number comes from a saved location — set attributes only, never type a real number. In a header use EITHER this OR ekwa/phone-dropdown, never both (they render the same numbers).',
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
			'desc'     => 'A call button that opens a dropdown of all locations/lines (rendered from settings). Set the trigger label only. Use INSTEAD of individual ekwa/phone links for a multi-location business — never alongside them in the same header.',
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
			'desc'     => 'A search icon/button that opens a full-screen search overlay. REQUIRED in every header — always include one in the header bar.',
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
			'desc'     => 'Google Map embed — the standard Ekwa footer map. Emit the block with default attrs (the embed code is pasted by the user after insertion); never hand-build an <iframe> map.',
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
function ekwa_ai_build_block_spec_section( $context, $allow_inline_style = false ) {
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

	// Only the conversion path preserves a source element's inline CSS. The
	// generator writes all its styling into one <style> block and forbids the
	// attribute outright, so it must not be advertised there.
	if ( $allow_inline_style ) {
		$out .= "Every block below also accepts \"inlineStyle\" — a RAW CSS STRING holding the source element's style attribute, e.g. {\"inlineStyle\":\"margin-bottom: 4rem\"}. "
			. "There is no \"style\" attribute on any of these blocks; an object like {\"style\":{\"marginBottom\":\"4rem\"}} is discarded on parse.\n\n";
	}

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
