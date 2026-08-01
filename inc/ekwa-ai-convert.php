<?php
/**
 * AI Convert — semantic HTML → Ekwa block conversion.
 *
 * The deterministic converter (inc/ekwa-converter-lib.php) maps HTML to blocks
 * by structure + heuristics. That is fast and free, but it cannot reliably tell
 * "this container IS the hours" from "this container HAS hours among an address,
 * two phones and a button" — so dense real-world footers/headers either miss or
 * over-capture. This path hands the whole HTML to Gemini, which reasons about
 * the content and emits correct block markup: dynamic content (phone, address,
 * hours, social, copyright, logo, menu, map, search) becomes the matching
 * dynamic block (real data filled from settings at render), everything else
 * becomes ekwa/div + core blocks with the original classNames preserved so the
 * mockup CSS still applies.
 *
 * Reuses the block-builder pipeline wholesale: the Gemini caller, block-spec
 * catalog, JSON repair, self-correct, validation and preview render.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/ekwa-ai-generate.php';
require_once get_template_directory() . '/inc/ekwa-ai-block-specs.php';
require_once get_template_directory() . '/inc/ekwa-ai-generate-blocks.php';

add_action( 'rest_api_init', 'ekwa_ai_convert_register_routes' );

/**
 * Register POST /ekwa/v1/ai-convert.
 */
function ekwa_ai_convert_register_routes() {
	register_rest_route( 'ekwa/v1', '/ai-convert', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_convert_handle_request',
		'permission_callback' => 'ekwa_ai_rest_permission', // Role gate + daily cap.
		'args'                => array(
			'html' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'model' => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'gemini-2.5-flash',
			),
			// Same CSS workflow as /convert-markup (extract / child / scoped /
			// AI-extract) — handled by the shared ekwa_mc_apply_css_options().
			'css' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'css_mode' => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'extract',
				'enum'     => array( 'extract', 'child', 'scoped' ),
			),
			'css_ai_extract' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => false,
			),
			// Menu import — handled by the shared ekwa_mc_apply_post_conversion().
			'import_menu' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => false,
			),
			'menu_replace' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => false,
			),
			'drop_outer_landmark' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => false,
			),
		),
	) );

	// Reconvert one mis-mapped part: turn a selected HTML snippet into a single
	// dynamic block (address, phone, hours…), preserving the mockup structure via
	// customTemplate. Used by the editor's "Reconvert as…" block action.
	register_rest_route( 'ekwa/v1', '/reconvert-part', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_reconvert_handle_request',
		'permission_callback' => 'ekwa_ai_rest_permission', // Role gate + daily cap.
		'args'                => array(
			'html' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'target' => array(
				'required' => true,
				'type'     => 'string',
			),
			'model' => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'gemini-2.5-flash',
			),
		),
	) );
}

/**
 * Dynamic blocks a selected part can be reconverted into, with the placeholder
 * vocabulary each accepts via customTemplate. The customTemplate-capable blocks
 * are sourced from the block-templates module so the two stay in lock-step; the
 * rest are simple standalone dynamic blocks (no template).
 *
 * @return array<string,array{label:string,placeholders:string,customTemplate:bool}>
 */
function ekwa_ai_reconvert_targets() {
	$tpl = function_exists( 'ekwa_tpl_supported_blocks' ) ? ekwa_tpl_supported_blocks() : array();

	$targets = array();
	foreach ( array( 'ekwa/address', 'ekwa/phone', 'ekwa/hours', 'ekwa/social', 'ekwa/copyright' ) as $block ) {
		if ( isset( $tpl[ $block ] ) ) {
			$targets[ $block ] = array(
				'label'          => $tpl[ $block ]['label'],
				'placeholders'   => $tpl[ $block ]['placeholders'],
				'customTemplate' => true,
			);
		}
	}

	// Standalone dynamic blocks (no customTemplate — they render their own markup).
	$targets['ekwa/map']         = array( 'label' => __( 'Map', 'ekwa' ),         'placeholders' => '', 'customTemplate' => false );
	$targets['ekwa/search']      = array( 'label' => __( 'Search', 'ekwa' ),      'placeholders' => '', 'customTemplate' => false );
	$targets['ekwa/header-menu'] = array( 'label' => __( 'Header menu', 'ekwa' ), 'placeholders' => '', 'customTemplate' => false );
	$targets['ekwa/svg-logo']    = array( 'label' => __( 'Logo (SVG)', 'ekwa' ),  'placeholders' => '', 'customTemplate' => false );
	$targets['site-logo']        = array( 'label' => __( 'Logo (image)', 'ekwa' ), 'placeholders' => '', 'customTemplate' => false );

	return $targets;
}

/**
 * The conversion system prompt: preserve content, map dynamic → dynamic blocks.
 *
 * @return string
 */
function ekwa_ai_convert_system_prompt() {
	$prompt = <<<PROMPT
You are an expert WordPress block-theme CONVERTER for the Ekwa theme. You are given an existing HTML section (from a design mockup) and you output Gutenberg BLOCK MARKUP that reproduces it. Your output is parsed with wp.blocks.parse(), so it must be valid block-comment markup.

CARDINAL RULE — PRESERVE, DON'T INVENT: reproduce the given HTML faithfully. Keep every heading, paragraph, list, image, link, button, and its text, order, href, and structure. Do NOT summarize, rewrite, translate, add, or drop visible content. The ONE exception is dynamic data (below), whose placeholder text you REPLACE with a dynamic block.

DYNAMIC DATA — replace the mockup's placeholder with the matching dynamic block, emitting the block with attributes ONLY and NO text/inner content (the theme fills the REAL value from site settings at render time). Detect these by meaning, wherever they appear:
- A phone number (a tel: link, or displayed digits like "(847) 349-4306") → <!-- wp:ekwa/phone /-->. If several distinct numbers exist, emit one block each; if a number is labeled "existing"/"current patient" add {"type":"existing"}, otherwise {"type":"new"}; for a 2nd location add {"location":2}.
- A street address, a "Get Directions" link, or any Google/Apple Maps link → <!-- wp:ekwa/address {"mode":"full"} /-->.
- Business/office hours (a list of days + times) → <!-- wp:ekwa/hours /-->. Emit ONE hours block and DROP the mockup's day/time text.
- A row of social-media icon links (facebook/instagram/x/youtube/linkedin/etc.) → <!-- wp:ekwa/social /-->.
- A copyright line ("© 2026 …") → <!-- wp:ekwa/copyright /-->.
- The site logo: an <img> logo → <!-- wp:site-logo /-->; an inline <svg> logo → <!-- wp:ekwa/svg-logo /-->.
- The PRIMARY site navigation menu (the main header nav, with its dropdowns/megamenu) → <!-- wp:ekwa/header-menu /-->. Secondary link lists (footer "Quick Links", "Services" columns) are NOT the main menu — keep those as core/list.
- A Google Maps <iframe> → <!-- wp:ekwa/map /-->.
- A search icon/trigger → <!-- wp:ekwa/search /-->.
NEVER type a real or fake phone/address/hours into these blocks. NEVER wrap them around other content — a dynamic block replaces ONLY its own element, leaving its siblings (other items in the same row/band) as their own blocks.
A dynamic block is ALWAYS a standalone block among its siblings — NEVER place it inside a core/paragraph, core/heading, or any other block's HTML. If the mockup wrapped the dynamic content in a tag (e.g. <p>Call: (847) 349-4306</p>, or <p>addr<br>addr</p>), REPLACE that entire wrapping tag with the dynamic block(s); do not keep the <p> around the block. Two phone numbers in one <p> separated by <br> become two consecutive ekwa/phone blocks (no <p>, no <br>).

CUSTOM TEMPLATES — pixel-parity for dynamic blocks. ekwa/phone, ekwa/address, ekwa/hours, ekwa/social and ekwa/copyright accept a "customTemplate" attribute: the block renders THAT markup with the live data injected. When the mockup's markup for one of these elements is anything other than trivial, PRESERVE it by setting customTemplate to the mockup's own HTML for that element, with the concrete data replaced by placeholders:
- ekwa/phone: {{prefix}} {{number}} {{tel}} (tel: href value) {{icon}} (icon class). Example from <p><a href="tel:+1555..."><i class="fas fa-phone"></i> New: (555) 123-4567</a></p> →
  <!-- wp:ekwa/phone {"type":"new","customTemplate":"<p><a href=\"tel:{{tel}}\"><i class=\"fas fa-phone\"></i> {{prefix}} {{number}}</a></p>"} /-->
- ekwa/address: {{street}} {{city}} {{state}} {{zip}} {{city_state}} {{full}} {{maps_url}}. Keep the mockup's tag/classes, e.g. customTemplate:"<a class=\"ft-addr\" href=\"{{maps_url}}\" target=\"_blank\">{{street}}<br>{{city_state}} {{zip}}</a>".
- ekwa/hours: repeat a row with {{#rows}}…{{/rows}} containing {{day}}, {{time}}, and {{closed_class}} (adds " is-closed" on closed days). E.g. customTemplate:"<ul class=\"hrs\">{{#rows}}<li class=\"hrs-row{{closed_class}}\"><span>{{day}}</span><span>{{time}}</span></li>{{/rows}}</ul>". Emit ONE row template — it repeats per day automatically.
- ekwa/social: {{#links}}…{{/links}} with {{url}}, {{name}}, {{icon}}, plus {{share_button}} (the theme's share popup button — ALWAYS place it after the links). E.g. customTemplate:"<div class=\"ft-soc\">{{#links}}<a href=\"{{url}}\" aria-label=\"{{name}}\" target=\"_blank\"><i class=\"{{icon}}\"></i></a>{{/links}}{{share_button}}</div>".
- ekwa/copyright: {{year}} {{name}}. E.g. customTemplate:"<span>© {{year}} {{name}}. All rights reserved.</span>".
Template rules: copy the mockup's classes/structure EXACTLY (so the mockup CSS applies unchanged); replace every concrete value (numbers, addresses, day/time text, social URLs, years, names) with the placeholders; include NO <script>, no event handlers, and no HTML comments inside the template; escape the double quotes for JSON. When the mockup element is trivial or matches the block's canonical output, omit customTemplate.

HERO SLIDERS & VIDEO HEROES — mockups often ship custom slider widgets (slick/swiper/hand-rolled) and background-video heroes. Convert them to the theme's dedicated blocks instead of reproducing their JS markup:
- A hero slider/carousel of full-width slides → ekwa/slider wrapping one ekwa/slide per slide. Slide background image → the slide's {"bgImage":"…"} attribute (drop the mockup's <img>/inline-bg for it). Each caption/CTA group inside a slide → ekwa/slide-content with an entrance animation, e.g.:
  <!-- wp:ekwa/slider {"transition":"fade","interval":6000} -->
  <!-- wp:ekwa/slide {"bgImage":"hero-1.jpg","overlayOpacity":40} -->
  <!-- wp:ekwa/slide-content {"animation":"fadeInDown","delay":300} --> …heading/paragraph/button blocks… <!-- /wp:ekwa/slide-content -->
  <!-- /wp:ekwa/slide -->
  <!-- /wp:ekwa/slider -->
  Drop the mockup's own slider dots/arrows/JS — the block renders its own. Animations: none|fadeIn|fadeInUp|fadeInDown|fadeInLeft|fadeInRight|zoomIn|slideInUp|blurIn.
- A hero with a background <video> → ekwa/hero-video {"videoUrl":"…","posterUrl":"…","overlayOpacity":40} wrapping the caption blocks. Drop the mockup's <video> markup.

EVERYTHING ELSE — structure:
- Containers (<div>, <section>, <header>, <footer>, <nav>, <main>, <aside>) → ekwa/div with {"tagName":"…"} and {"className":"…"} copied VERBATIM from the source (so the mockup CSS still targets them). Keep the nesting exactly.
- Headings <h1>–<h6> → core/heading with the matching level. Paragraphs → core/paragraph. Lists <ul>/<ol> → core/list. Copy the inner text/HTML exactly.
- <img> → ekwa/image with src, alt, width, height.
- A link or button that is a call-to-action (has a button class like .btn/.b/.button, or is an obvious CTA) → ekwa/button {"text":"…","url":"…"} (add {"variant":"outline"} for outline styles). A plain text link → ekwa/link {"text":"…","url":"…"}. Preserve href exactly.
- An icon (<i class="fa…">) → ekwa/icon {"iconClass":"…","wrapperClass":""}. Always set wrapperClass to "" — the block otherwise wraps the icon in a <div>, which breaks inline/flex rows.
- ICONS ARE ALWAYS FONT AWESOME 6. The theme loads Font Awesome and no other icon font, so if the mockup uses Remix Icon (ri-*), Bootstrap Icons (bi-*), Themify (ti-*), Ionicons, Material Icons, Line Awesome or Glyphicons, translate the class to the equivalent Font Awesome 6 Free class: fa-solid for regular icons, fa-brands for logos. E.g. ri-search-line → "fa-solid fa-magnifying-glass", ri-phone-fill → "fa-solid fa-phone", ri-map-pin-fill → "fa-solid fa-location-dot", ri-arrow-down-s-line → "fa-solid fa-chevron-down", ri-facebook-circle-fill → "fa-brands fa-facebook", bi-telephone-fill → "fa-solid fa-phone". Keep any non-icon classes on the element. This applies inside customTemplate markup too.

NEVER PUT HTML IN AN ATTRIBUTE. A "text" attribute is rendered as escaped plain text — a tag inside it shows up as literal characters, so the element it described is lost. This is the single most common conversion failure, so check every link you emit:
- A link containing ONLY text → ekwa/link {"text":"Read more","url":"/about/"}. Fine.
- A link containing an <img>, an <svg>, a nested <div>/<span> with its own classes, or any other element → NOT ekwa/link. Emit ekwa/div {"tagName":"a","href":"…","className":"…"} and put the link's contents inside it as real child blocks:
  <!-- wp:ekwa/div {"tagName":"a","href":"#","className":"lang-item"} -->
  <!-- wp:ekwa/image {"src":"us.png","alt":"English Flag","className":"flag-icon"} /-->
  <!-- wp:ekwa/text {"text":"English"} /-->
  <!-- /wp:ekwa/div -->
  (An <i> icon is the ONE exception: an icon plus text may stay a single ekwa/link when the link is otherwise plain, since ekwa/link renders its own icon.) The same rule applies to ekwa/button and ekwa/text — their "text" is always plain text.
- KEEP EVERY CLASS ON core/list. A static block renders its SAVED HTML, so the class must be written on the element itself, not only in the attributes: <!-- wp:list {"className":"lang-dropdown"} --><ul class="wp-block-list lang-dropdown">…</ul><!-- /wp:list -->. Setting className without repeating it on the <ul> silently loses the mockup's styling. Same for core/paragraph, core/heading, core/quote and core/table.

OUTPUT: return ONLY the block-comment markup — no <style> block, no <script>, no markdown code fences, no commentary. CSS is handled separately, so do not emit any CSS.
Attribute JSON must be STRICT valid JSON (double-quoted keys/strings, no trailing commas). Prefer ekwa/* and the core blocks named above; do not invent block names.
PROMPT;

	$prompt .= ekwa_ai_build_block_spec_section( 'section' );

	if ( function_exists( 'ekwa_tokens_ai_context' ) ) {
		$prompt .= ekwa_tokens_ai_context();
	}

	return $prompt;
}

/**
 * Handle POST /ekwa/v1/ai-convert.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_convert_handle_request( $request ) {
	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'convert' );
	}

	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return new WP_Error( 'no_api_key', __( 'Gemini API key not configured (Ekwa Settings → AI).', 'ekwa' ), array( 'status' => 400 ) );
	}

	$html  = trim( (string) $request->get_param( 'html' ) );
	$model = (string) $request->get_param( 'model' );
	if ( '' === $html ) {
		return new WP_Error( 'empty_html', __( 'Paste the mockup HTML first.', 'ekwa' ), array( 'status' => 400 ) );
	}
	if ( strlen( $html ) > 200000 ) {
		return new WP_Error( 'too_large', __( 'That is too large for one pass — convert it section by section (header, each section, footer).', 'ekwa' ), array( 'status' => 413 ) );
	}

	$allowed = ekwa_ai_generate_allowed_models();
	if ( ! isset( $allowed[ $model ] ) ) {
		$model = 'gemini-2.5-flash';
	}

	// Reduce a full document to body content — same guard the deterministic
	// converter uses, so pasting a whole index.html is safe.
	if ( function_exists( 'ekwa_mc_extract_body' ) ) {
		$html = ekwa_mc_extract_body( $html );
	}

	// Demote the outer landmark BEFORE the model sees it, so both engines
	// behave identically and the model never has to be told about it.
	$demoted = '';
	if ( $request->get_param( 'drop_outer_landmark' ) && function_exists( 'ekwa_mc_demote_root_landmark' ) ) {
		$landmark = ekwa_mc_demote_root_landmark( $html );
		$html     = $landmark['html'];
		$demoted  = $landmark['demoted'];
	}

	$system   = ekwa_ai_convert_system_prompt();
	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => "Convert this HTML into Ekwa block markup, following every rule above:\n\n" . $html ),
			),
		),
	);

	// Block markup runs roughly the length of the input HTML plus block-comment
	// overhead, so on a large section the default 16k output cap would cut the
	// markup off mid-block and leave unbalanced/parse-broken output. Give it the
	// model's full window; thinking stays ON — mapping content to the right
	// dynamic blocks is a reasoning task, unlike the mechanical CSS split.
	$result = ekwa_ai_generate_call_gemini( $system, $contents, 0.1, $api_key, $model, 65536 );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'ai_error', $result->get_error_message(), array( 'status' => 502 ) );
	}
	$truncated = isset( $result['finish_reason'] ) && 'MAX_TOKENS' === $result['finish_reason'];

	// Same post-processing as the Block Builder: strip fences, drop any stray
	// CSS/JS the model emitted, repair attribute JSON, self-correct once.
	$cleaned      = ekwa_ai_generate_strip_fences( $result['content'] );
	$extracted    = ekwa_ai_generate_extract_css_js( $cleaned );
	$block_markup = trim( $extracted['html'] );
	$warnings     = array();
	if ( $truncated ) {
		$warnings[] = __( 'This section was large enough that the AI response was cut short — the converted blocks may be incomplete. Convert it in smaller pieces (header, each section, footer) and review the result before inserting.', 'ekwa' );
	}

	$repair       = ekwa_ai_repair_block_markup( $block_markup );
	$block_markup = $repair['markup'];

	if ( $repair['failed'] > 0 ) {
		$corrected = ekwa_ai_blocks_self_correct( $block_markup, $api_key );
		if ( null !== $corrected ) {
			$re_markup = trim( ekwa_ai_generate_extract_css_js( ekwa_ai_generate_strip_fences( $corrected ) )['html'] );
			if ( '' !== $re_markup ) {
				$re_repair = ekwa_ai_repair_block_markup( $re_markup );
				if ( $re_repair['failed'] <= $repair['failed'] ) {
					$block_markup = $re_repair['markup'];
					$repair       = $re_repair;
				}
			}
		}
	}

	if ( $repair['repaired'] > 0 ) {
		$warnings[] = sprintf(
			_n( 'Auto-corrected the attributes on %d block.', 'Auto-corrected the attributes on %d blocks.', $repair['repaired'], 'ekwa' ),
			$repair['repaired']
		);
	}
	foreach ( array_unique( $repair['failed_names'] ) as $bad_name ) {
		$warnings[] = sprintf(
			__( 'The "%s" block has attributes that could not be auto-corrected — check it after inserting.', 'ekwa' ),
			$bad_name
		);
	}

	// Structural repairs the JSON repairer can't see: HTML smuggled into text
	// attributes, and static core blocks whose className never reached their
	// saved markup.
	$structure    = ekwa_ai_repair_block_structure( $block_markup, array( 'bare_icons' => true ) );
	$block_markup = $structure['markup'];
	$warnings     = array_merge( $warnings, $structure['notes'] );

	if ( $demoted && function_exists( 'ekwa_mc_demote_notice' ) ) {
		$warnings[] = ekwa_mc_demote_notice( $demoted );
	}

	$warnings      = array_merge( $warnings, ekwa_ai_generate_blocks_validate( $block_markup ) );
	$rendered_html = ekwa_ai_generate_blocks_render_preview( $block_markup );

	$response = array(
		'markup'        => $block_markup,
		'rendered_html' => $rendered_html,
		'warnings'      => $warnings,
	);

	// Shared CSS workflow (extract tokens / append to child / attach scoped /
	// AI-extract) — identical to the deterministic converter path.
	if ( function_exists( 'ekwa_mc_apply_css_options' ) ) {
		$response = ekwa_mc_apply_css_options( $request, $html, $response );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
	}

	// Menu import + fidelity audit — also shared with /convert-markup.
	if ( function_exists( 'ekwa_mc_apply_post_conversion' ) ) {
		$response = ekwa_mc_apply_post_conversion( $request, $html, $response );
	}

	return rest_ensure_response( $response );
}

/**
 * System prompt for reconverting ONE selected snippet into a single dynamic
 * block, preserving the mockup structure via customTemplate where supported.
 *
 * @param string $target Block name (e.g. "ekwa/address").
 * @return string
 */
function ekwa_ai_reconvert_system_prompt( $target ) {
	$targets = ekwa_ai_reconvert_targets();
	$info    = isset( $targets[ $target ] ) ? $targets[ $target ] : null;
	$label   = $info ? $info['label'] : $target;

	$prompt  = "You convert ONE HTML snippet a user selected in the WordPress editor into Ekwa dynamic BLOCK MARKUP. Your output is parsed by wp.blocks.parse(), so emit ONLY valid block-comment markup — no markdown fences, no <style>, no <script>, no prose.\n\n";
	$prompt .= "TARGET: convert the snippet into the {$target} block ({$label}). If the snippet clearly holds several of the SAME item (e.g. two phone numbers), emit one block per item in source order; otherwise emit exactly one block. Emit the block with ATTRIBUTES ONLY and NO inner text/content — the theme fills the REAL value from the site's settings at render time. NEVER type a real or placeholder phone/address/hours/name/URL into the block body.\n\n";

	switch ( $target ) {
		case 'ekwa/phone':
			$prompt .= "ATTRIBUTES: {\"type\":\"new\"} for a new-patient number, {\"type\":\"existing\"} for an existing/current-patient number (read the label beside it); add {\"location\":2} for a clearly-second location.\n";
			break;
		case 'ekwa/address':
			$prompt .= "ATTRIBUTES: {\"mode\":\"full\"}.\n";
			break;
		case 'ekwa/hours':
			$prompt .= "Emit ONE ekwa/hours block; drop the snippet's literal day/time text.\n";
			break;
		case 'ekwa/social':
			$prompt .= "Emit ONE ekwa/social block covering the whole row of social icons.\n";
			break;
		case 'ekwa/copyright':
			$prompt .= "Emit ONE ekwa/copyright block; drop the literal year/name.\n";
			break;
		case 'site-logo':
			$prompt .= "Emit <!-- wp:site-logo /--> for an <img> logo.\n";
			break;
		case 'ekwa/svg-logo':
			$prompt .= "Emit <!-- wp:ekwa/svg-logo /--> for an inline <svg> logo.\n";
			break;
		case 'ekwa/map':
			$prompt .= "Emit <!-- wp:ekwa/map /--> for a Google Maps embed/iframe/link.\n";
			break;
		case 'ekwa/search':
			$prompt .= "Emit <!-- wp:ekwa/search /--> for a search trigger/box.\n";
			break;
		case 'ekwa/header-menu':
			$prompt .= "Emit <!-- wp:ekwa/header-menu /--> for the primary navigation menu.\n";
			break;
	}

	if ( $info && ! empty( $info['customTemplate'] ) ) {
		$prompt .= "\nPRESERVE STRUCTURE — set the block's \"customTemplate\" attribute to the snippet's OWN markup with every concrete value replaced by these placeholders: {$info['placeholders']}. Copy the snippet's tags and class names EXACTLY so the mockup CSS still applies. The loops {{#rows}}…{{/rows}} (hours) and {{#links}}…{{/links}} (social) repeat once per row/link — write ONE row/link template. No <script>, event handlers, or HTML comments inside the template. Escape double quotes for the JSON. If the snippet is a single trivial element that already matches the block's default output, omit customTemplate.\n";
		$prompt .= ekwa_ai_reconvert_template_example( $target );
	}

	$prompt .= "\nAttribute JSON must be STRICT valid JSON: double-quoted keys/strings, no trailing commas.";
	return $prompt;
}

/**
 * A concrete customTemplate example per target, to anchor the model's output.
 *
 * @param string $target
 * @return string
 */
function ekwa_ai_reconvert_template_example( $target ) {
	switch ( $target ) {
		case 'ekwa/phone':
			return "EXAMPLE — <p><a href=\"tel:+15551234567\"><i class=\"fas fa-phone\"></i> New: (555) 123-4567</a></p> →\n"
				. "<!-- wp:ekwa/phone {\"type\":\"new\",\"customTemplate\":\"<p><a href=\\\"tel:{{tel}}\\\"><i class=\\\"fas fa-phone\\\"></i> {{prefix}} {{number}}</a></p>\"} /-->\n";
		case 'ekwa/address':
			return "EXAMPLE — <a class=\"ft-addr\" href=\"https://maps.google.com/…\" target=\"_blank\">123 Main St<br>Wayzata, MN 55391</a> →\n"
				. "<!-- wp:ekwa/address {\"mode\":\"full\",\"customTemplate\":\"<a class=\\\"ft-addr\\\" href=\\\"{{maps_url}}\\\" target=\\\"_blank\\\">{{street}}<br>{{city_state}} {{zip}}</a>\"} /-->\n";
		case 'ekwa/hours':
			return "EXAMPLE — <ul class=\"hrs\"><li class=\"hrs-row\"><span>Mon</span><span>9–5</span></li>…</ul> →\n"
				. "<!-- wp:ekwa/hours {\"customTemplate\":\"<ul class=\\\"hrs\\\">{{#rows}}<li class=\\\"hrs-row{{closed_class}}\\\"><span>{{day}}</span><span>{{time}}</span></li>{{/rows}}</ul>\"} /-->\n";
		case 'ekwa/social':
			return "EXAMPLE — <div class=\"ft-soc\"><a href=\"https://facebook.com/…\" aria-label=\"Facebook\"><i class=\"fab fa-facebook\"></i></a>…</div> →\n"
				. "<!-- wp:ekwa/social {\"customTemplate\":\"<div class=\\\"ft-soc\\\">{{#links}}<a href=\\\"{{url}}\\\" aria-label=\\\"{{name}}\\\" target=\\\"_blank\\\"><i class=\\\"{{icon}}\\\"></i></a>{{/links}}{{share_button}}</div>\"} /-->\n";
		case 'ekwa/copyright':
			return "EXAMPLE — <span>© 2026 Acme Dental. All rights reserved.</span> →\n"
				. "<!-- wp:ekwa/copyright {\"customTemplate\":\"<span>© {{year}} {{name}}. All rights reserved.</span>\"} /-->\n";
	}
	return '';
}

/**
 * Handle POST /ekwa/v1/reconvert-part.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_reconvert_handle_request( $request ) {
	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'reconvert' );
	}

	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return new WP_Error( 'no_api_key', __( 'Gemini API key not configured (Ekwa Settings → AI).', 'ekwa' ), array( 'status' => 400 ) );
	}

	$html   = trim( (string) $request->get_param( 'html' ) );
	$target = (string) $request->get_param( 'target' );
	$model  = (string) $request->get_param( 'model' );

	if ( '' === $html ) {
		return new WP_Error( 'empty_html', __( 'Nothing to reconvert — paste the original mockup snippet for this part.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$targets = ekwa_ai_reconvert_targets();
	if ( ! isset( $targets[ $target ] ) ) {
		return new WP_Error( 'bad_target', __( 'Unknown reconvert target.', 'ekwa' ), array( 'status' => 400 ) );
	}
	if ( strlen( $html ) > 20000 ) {
		$html = substr( $html, 0, 20000 );
	}

	$allowed = ekwa_ai_generate_allowed_models();
	if ( ! isset( $allowed[ $model ] ) ) {
		$model = 'gemini-2.5-flash';
	}

	$system   = ekwa_ai_reconvert_system_prompt( $target );
	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => "Selected snippet to convert into the {$target} block:\n\n" . $html ),
			),
		),
	);

	$result = ekwa_ai_generate_call_gemini( $system, $contents, 0.1, $api_key, $model );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'ai_error', $result->get_error_message(), array( 'status' => 502 ) );
	}

	// Same post-processing as AI Convert: strip fences, drop stray CSS/JS,
	// repair attribute JSON, self-correct once, validate, preview-render.
	$cleaned   = ekwa_ai_generate_strip_fences( $result['content'] );
	$extracted = ekwa_ai_generate_extract_css_js( $cleaned );
	$markup    = trim( $extracted['html'] );
	$warnings  = array();

	$repair = ekwa_ai_repair_block_markup( $markup );
	$markup = $repair['markup'];
	if ( $repair['failed'] > 0 ) {
		$corrected = ekwa_ai_blocks_self_correct( $markup, $api_key );
		if ( null !== $corrected ) {
			$re = trim( ekwa_ai_generate_extract_css_js( ekwa_ai_generate_strip_fences( $corrected ) )['html'] );
			if ( '' !== $re ) {
				$re_repair = ekwa_ai_repair_block_markup( $re );
				if ( $re_repair['failed'] <= $repair['failed'] ) {
					$markup = $re_repair['markup'];
					$repair = $re_repair;
				}
			}
		}
	}

	if ( '' === trim( $markup ) ) {
		return new WP_Error( 'ai_empty', __( 'The AI returned no block markup for that snippet.', 'ekwa' ), array( 'status' => 502 ) );
	}

	$warnings = array_merge( $warnings, ekwa_ai_generate_blocks_validate( $markup ) );
	$rendered = ekwa_ai_generate_blocks_render_preview( $markup );

	return rest_ensure_response( array(
		'markup'        => $markup,
		'rendered_html' => $rendered,
		'warnings'      => $warnings,
	) );
}
