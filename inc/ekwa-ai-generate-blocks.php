<?php
/**
 * AI Block Builder — generate Gutenberg block markup with Gemini.
 *
 * Provides POST /ekwa/v1/ai-generate-blocks. Accepts a free-form prompt and
 * optional reference screenshots; returns Ekwa/core block-comment markup ready
 * to drop into the editor (via wp.blocks.parse + insertBlocks), plus the CSS the
 * generated classes need and a server-rendered preview.
 *
 * Unlike inc/ekwa-ai-generate.php (which emits raw HTML for the HTML→block
 * converter), this endpoint asks the model to serialize blocks DIRECTLY, so no
 * lossy HTML detection step is involved. It reuses the Gemini plumbing,
 * multimodal contents builder, and CSS extractor from that file.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/ekwa-ai-shared.php';
require_once get_template_directory() . '/inc/ekwa-ai-generate.php';
require_once get_template_directory() . '/inc/ekwa-ai-block-specs.php';

add_action( 'rest_api_init', 'ekwa_ai_generate_blocks_register_routes' );

/**
 * Register the AI block generation REST route.
 */
function ekwa_ai_generate_blocks_register_routes() {
	register_rest_route( 'ekwa/v1', '/ai-generate-blocks', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_generate_blocks_handle_request',
		'permission_callback' => 'ekwa_ai_rest_permission',
		'args' => array(
			'prompt' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'images'        => array( 'required' => false, 'type' => 'array',   'default' => array() ),
			'history'       => array( 'required' => false, 'type' => 'array',   'default' => array() ),
			'use_child_css' => array( 'required' => false, 'type' => 'boolean', 'default' => true ),
			// Show the model the sections this site already has, so a new one
			// looks like it belongs here. Section context only — see
			// ekwa_ai_blocks_site_designs_context().
			'use_site_designs' => array( 'required' => false, 'type' => 'boolean', 'default' => true ),
			'temperature'   => array( 'required' => false, 'type' => 'number',  'default' => 0.3 ),
			'model'         => array( 'required' => false, 'type' => 'string',  'default' => '' ),
			'context'       => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'section',
				'enum'     => array( 'header', 'footer', 'section' ),
			),
			// Edit mode: when 'edit', the request modifies an existing selection
			// (its serialized markup + CSS) instead of generating from scratch.
			'mode'          => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'create',
				'enum'     => array( 'create', 'edit' ),
			),
			'base_markup'   => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'base_css'      => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
		),
	) );

	// Front-end render of arbitrary block markup, for the modal's preview pane.
	// No AI call, so it sits outside the AI role-gate / daily cap.
	register_rest_route( 'ekwa/v1', '/ai-blocks-preview', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_blocks_preview_request',
		'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
		'args' => array(
			'markup' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
		),
	) );
}

/**
 * Handle POST /ekwa/v1/ai-blocks-preview — render block markup the way the front
 * end would.
 *
 * "Edit with AI" needs this to show the *current* selection before any AI turn:
 * the editor canvas can't supply it, because what it holds is editor DOM
 * (placeholders, Replace/Remove buttons) styled by the editor's own stylesheets.
 * Rendering here also runs the render_block filters, so each block's front-end
 * CSS is inlined into the returned HTML (see inc/ekwa-inline-assets.php).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function ekwa_ai_blocks_preview_request( $request ) {
	return rest_ensure_response( array(
		'rendered_html' => ekwa_ai_generate_blocks_render_preview( (string) $request->get_param( 'markup' ) ),
	) );
}

/**
 * Handle the AI block generation REST request.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_generate_blocks_handle_request( $request ) {
	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'edit' === $request->get_param( 'mode' ) ? 'blocks-edit' : 'blocks' );
	}
	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return new WP_Error(
			'no_api_key',
			'Gemini API key not configured. Add EKWA_GEMINI_API_KEY to wp-config.php or set it in Appearance → Ekwa Settings → AI.',
			array( 'status' => 400 )
		);
	}

	$prompt        = trim( (string) $request->get_param( 'prompt' ) );
	$images        = (array) $request->get_param( 'images' );
	$history       = (array) $request->get_param( 'history' );
	$use_child_css = (bool) $request->get_param( 'use_child_css' );
	$use_designs   = (bool) $request->get_param( 'use_site_designs' );
	$temperature   = (float) $request->get_param( 'temperature' );
	$model         = (string) $request->get_param( 'model' );
	$context       = (string) $request->get_param( 'context' );
	$mode          = (string) $request->get_param( 'mode' );
	$base_markup   = (string) $request->get_param( 'base_markup' );
	$base_css      = (string) $request->get_param( 'base_css' );
	if ( ! in_array( $context, array( 'header', 'footer', 'section' ), true ) ) {
		$context = 'section';
	}
	if ( ! in_array( $mode, array( 'create', 'edit' ), true ) ) {
		$mode = 'create';
	}

	$model = ekwa_ai_resolve_model( $model, 'pro' );

	if ( '' === $prompt ) {
		return new WP_Error( 'empty_prompt', 'Prompt is required.', array( 'status' => 400 ) );
	}
	if ( count( $images ) > 6 ) {
		return new WP_Error( 'too_many_images', 'Up to 6 images per request.', array( 'status' => 400 ) );
	}

	// In edit mode, prepend the existing section (markup + CSS) to the prompt on the
	// FIRST turn so the model can investigate and modify the current state. On later
	// refine turns the prior model output already carries the section forward via
	// $history, so we don't re-inject it (avoids duplicating it every turn).
	$effective_prompt = $prompt;
	if ( 'edit' === $mode && '' !== trim( $base_markup ) && empty( $history ) ) {
		$effective_prompt  = "EXISTING SECTION TO EDIT — current block markup:\n\n" . $base_markup;
		if ( '' !== trim( $base_css ) ) {
			$effective_prompt .= "\n\nEXISTING SECTION CSS:\n\n" . $base_css;
		}
		$effective_prompt .= "\n\n---\nApply this change and return the COMPLETE updated section:\n\n" . $prompt;
	}

	// Reuse the multimodal contents builder from the HTML generator (handles
	// history reconstruction + image validation identically).
	$contents = ekwa_ai_generate_build_contents( $effective_prompt, $images, $history );
	if ( is_wp_error( $contents ) ) {
		return $contents;
	}

	$system_prompt = ekwa_ai_generate_blocks_system_prompt( $context, $mode );
	if ( $use_child_css ) {
		$system_prompt .= ekwa_ai_generate_child_stylesheet_context();
	}
	// Appended here rather than inside the system prompt so the import design
	// pass, which calls that function directly and adds the vocabulary itself,
	// cannot end up sending it twice.
	if ( $use_designs && 'section' === $context ) {
		$system_prompt .= ekwa_ai_blocks_site_designs_context();
	}

	$result = ekwa_ai_generate_call_gemini( $system_prompt, $contents, $temperature, $api_key, $model );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'ai_error', $result->get_error_message(), array( 'status' => 502 ) );
	}

	// Split the <style> blob out from the block markup (reuses the HTML
	// generator's extractor: returns html/css/js).
	$cleaned   = ekwa_ai_generate_strip_fences( $result['content'] );
	$extracted = ekwa_ai_generate_extract_css_js( $cleaned );

	$block_markup = trim( $extracted['html'] );
	$css          = $extracted['css'];
	$warnings     = array();

	// Repair malformed attribute JSON (trailing commas, smart quotes, NBSP, …)
	// BEFORE the markup is parsed/serialized. WordPress's block parser does not
	// repair invalid JSON — it silently discards the whole attribute set — so a
	// single bad comma turns into a "broken" block (e.g. a button with no text or
	// link). Done here while the original text is still intact.
	$repair       = ekwa_ai_repair_block_markup( $block_markup );
	$block_markup = $repair['markup'];

	// Self-check: if any blocks STILL won't parse, ask the model (best-quality
	// model, low temperature) to fix just those, once. Keep the result only if it
	// is no worse than what we had — never let the corrective pass regress.
	if ( $repair['failed'] > 0 ) {
		$corrected = ekwa_ai_blocks_self_correct( $block_markup, $api_key );
		if ( null !== $corrected ) {
			$re_extracted = ekwa_ai_generate_extract_css_js( ekwa_ai_generate_strip_fences( $corrected ) );
			$re_markup    = trim( $re_extracted['html'] );
			if ( '' !== $re_markup ) {
				$re_repair = ekwa_ai_repair_block_markup( $re_markup );
				if ( $re_repair['failed'] <= $repair['failed'] ) {
					$block_markup = $re_repair['markup'];
					$repair       = $re_repair;
					if ( '' !== trim( $re_extracted['css'] ) ) {
						$css = $re_extracted['css'];
					}
				}
			}
		}
	}

	if ( $repair['repaired'] > 0 ) {
		$warnings[] = sprintf(
			/* translators: %d: number of blocks whose attributes were auto-corrected. */
			_n( 'Auto-corrected the attributes on %d block.', 'Auto-corrected the attributes on %d blocks.', $repair['repaired'], 'ekwa' ),
			$repair['repaired']
		);
	}
	foreach ( array_unique( $repair['failed_names'] ) as $bad_name ) {
		$warnings[] = sprintf(
			/* translators: %s: block name. */
			__( 'The "%s" block has attributes that could not be auto-corrected — double-check it after inserting.', 'ekwa' ),
			$bad_name
		);
	}

	// Replace the AI's scoping sentinel with a real unique section id in BOTH the
	// CSS and the markup, then embed the (scoped) CSS into the wrapper block's
	// scopedCss attribute so the section becomes self-contained — its CSS inlines
	// on the front end only where the block renders (ekwa_render_div_block). When
	// editing an existing section the wrapper already carries a real scope class
	// (no sentinel), so $scope stays '' and the CSS is simply re-embedded.
	if ( false !== strpos( $block_markup, 'EKWA_SCOPE' ) || false !== strpos( $css, 'EKWA_SCOPE' ) ) {
		$scope        = 'eai-sec-' . substr( md5( uniqid( '', true ) ), 0, 6 );
		$css          = str_replace( 'EKWA_SCOPE', $scope, $css );
		$block_markup = str_replace( 'EKWA_SCOPE', $scope, $block_markup );
	} else {
		$scope = '';
	}

	if ( '' !== trim( $css ) ) {
		$embed        = ekwa_ai_blocks_embed_scoped_css( $block_markup, $css, $scope );
		$block_markup = $embed['markup'];
		$warnings     = array_merge( $warnings, $embed['warnings'] );
	}

	// A phone number the model typed into a paragraph is frozen — it misses the
	// next change in Locations and the call-tracking swap. Turn the ones this
	// practice owns into [ekwa_phone] before the markup is handed to the editor.
	// Section context only: a header/footer is a template part, which never runs
	// do_shortcode(). @see ekwa_phone_replace_in_blocks().
	if ( 'section' === $context && function_exists( 'ekwa_phone_replace_in_blocks' ) ) {
		$block_markup = ekwa_phone_replace_in_blocks( $block_markup, $phones );
		$warnings     = array_merge( $warnings, ekwa_ai_blocks_phone_warnings( $phones ) );
	}

	// Validate that every referenced block is registered, and (best-effort)
	// render the markup server-side for an accurate preview.
	$warnings      = array_merge( $warnings, ekwa_ai_generate_blocks_validate( $block_markup ) );
	$rendered_html = ekwa_ai_generate_blocks_render_preview( $block_markup );

	return rest_ensure_response( array(
		'block_markup'  => $block_markup,
		'extracted_css' => $css,
		'rendered_html' => $rendered_html,
		'warnings'      => $warnings,
	) );
}

/**
 * Embed the section's scoped CSS into its top-level ekwa/div wrapper.
 *
 * The Block Builder asks the AI to wrap each section in a single top-level
 * ekwa/div carrying the scope class, and to scope every selector under it. Here
 * we move the extracted CSS into that wrapper's `scopedCss` attribute so the
 * markup is self-contained — `ekwa_render_div_block()` then inlines the CSS once
 * per request, only where the block renders.
 *
 * @param string $markup Block-comment markup (sentinel already replaced).
 * @param string $css    Scoped CSS to embed (sentinel already replaced).
 * @param string $scope  The generated scope class (e.g. "eai-sec-ab12cd"), or ''.
 * @return array{ markup:string, warnings:array<int,string> }
 */
function ekwa_ai_blocks_embed_scoped_css( $markup, $css, $scope ) {
	$warnings = array();

	$blocks = parse_blocks( $markup );

	// Real top-level blocks = those with a block name (skip whitespace blocks).
	$real = array();
	foreach ( $blocks as $i => $block ) {
		if ( ! empty( $block['blockName'] ) ) {
			$real[] = $i;
		}
	}

	if ( count( $real ) === 1 && 'ekwa/div' === $blocks[ $real[0] ]['blockName'] ) {
		$idx = $real[0];
		if ( ! isset( $blocks[ $idx ]['attrs'] ) || ! is_array( $blocks[ $idx ]['attrs'] ) ) {
			$blocks[ $idx ]['attrs'] = array();
		}
		$blocks[ $idx ]['attrs']['scopedCss'] = $css;

		// Make sure the scope class is actually on the wrapper, so the scoped
		// selectors match. (It normally is, via the sentinel replacement.)
		if ( '' !== $scope ) {
			$class = isset( $blocks[ $idx ]['attrs']['className'] ) ? (string) $blocks[ $idx ]['attrs']['className'] : '';
			if ( false === strpos( ' ' . $class . ' ', ' ' . $scope . ' ' ) ) {
				$blocks[ $idx ]['attrs']['className'] = trim( $class . ' ' . $scope );
			}
		}

		return array( 'markup' => serialize_blocks( $blocks ), 'warnings' => $warnings );
	}

	// Structure isn't a single wrapping ekwa/div — leave markup untouched and
	// fall back to manual CSS handling via the panel.
	$warnings[] = __( 'Could not auto-embed the section CSS (the output is not wrapped in a single ekwa/div). Paste the CSS panel into your stylesheet manually.', 'ekwa' );
	return array( 'markup' => $markup, 'warnings' => $warnings );
}

/**
 * The sections this site already has, as prompt context for a new one.
 *
 * The same vocabulary the import design pass uses — saved patterns, the Inner
 * Page Template, and sections already on the site's pages — so a section built
 * in the modal comes out looking like the rest of the site instead of looking
 * like a generic block theme.
 *
 * Deliberately on a tighter budget than the import pass. That one runs once per
 * page and can afford the whole library; this one runs on every turn of an
 * interactive conversation, and the cost is paid every time.
 *
 * @param int $budget Character cap on the markup+CSS shipped.
 * @return string Prompt fragment, or '' when the site has no designs yet.
 */
function ekwa_ai_blocks_site_designs_context( $budget = 24000 ) {
	if ( ! function_exists( 'ekwa_design_vocabulary' ) || ! function_exists( 'ekwa_design_vocabulary_prompt' ) ) {
		return '';
	}

	return ekwa_design_vocabulary_prompt( ekwa_design_vocabulary(), $budget );
}

/**
 * Turn the phone-swap report into messages for the modal's warnings list.
 *
 * Both halves are worth saying out loud. The swap is a change to what the model
 * returned, so it should not happen invisibly; and a number left hard-coded
 * inside a link is the case the swap deliberately cannot fix, which makes it
 * the one the author actually has to act on.
 *
 * @param array $report From ekwa_phone_replace_in_blocks().
 * @return array<int,string>
 */
function ekwa_ai_blocks_phone_warnings( $report ) {
	$warnings = array();

	$converted = isset( $report['converted'] ) ? (int) $report['converted'] : 0;
	if ( $converted > 0 ) {
		$warnings[] = sprintf(
			/* translators: %d: number of phone numbers. */
			_n(
				'Replaced %d phone number with the [ekwa_phone] shortcode so it follows Ekwa Settings → Locations.',
				'Replaced %d phone numbers with the [ekwa_phone] shortcode so they follow Ekwa Settings → Locations.',
				$converted,
				'ekwa'
			),
			$converted
		);
	}

	$blocked = isset( $report['blocked'] ) && is_array( $report['blocked'] ) ? $report['blocked'] : array();
	if ( $blocked ) {
		$warnings[] = sprintf(
			/* translators: %s: comma-separated list of phone numbers. */
			__( 'Left as typed because it sits inside a link or button, where the shortcode cannot go: %s. Use the Phone block there instead, so the number updates from Settings.', 'ekwa' ),
			implode( ', ', $blocked )
		);
	}

	return $warnings;
}

/**
 * Build the system prompt that makes Gemini emit Gutenberg block markup.
 *
 * @param string $context One of: 'header', 'footer', 'section'.
 * @param string $mode    'create' (generate from scratch) or 'edit' (modify an
 *                        existing section supplied in the user message).
 * @return string
 */
function ekwa_ai_generate_blocks_system_prompt( $context = 'section', $mode = 'create' ) {
	// Site breakpoints (Ekwa Settings) so generated media queries match the
	// theme's responsive visibility bands.
	$bp          = function_exists( 'ekwa_responsive_breakpoints' ) ? ekwa_responsive_breakpoints() : array( 'tablet' => 1199, 'mobile' => 599 );
	$tablet_max  = $bp['tablet'];
	$mobile_max  = $bp['mobile'];
	$desktop_min = $tablet_max + 1;

	// Tell the model which logo block THIS site can actually render: the inline
	// SVG block only when its markup is configured; core/site-logo otherwise.
	$has_svg_logo = '' !== trim( (string) get_option( 'ekwa_svg_logo_markup', '' ) );
	$logo_cue     = $has_svg_logo
		? 'use ekwa/svg-logo (this site has its SVG logo configured; core/site-logo is also acceptable)'
		: 'use core/site-logo (this site has NO SVG logo configured — do not use ekwa/svg-logo)';

	$context_cue = '';
	if ( 'header' === $context ) {
		$context_cue = "HEADER CONTEXT — strict rules:\n"
			. "1. DESKTOP ONLY. The site has a separate mobile header. The whole header is your single top-level ekwa/div (className \"EKWA_SCOPE\"); hide it on smaller screens with this rule in your <style>: `@media (max-width: {$tablet_max}px){ .EKWA_SCOPE{ display:none !important; } }`.\n"
			. "2. NO mobile markup — no hamburger, no off-canvas drawer, no mobile toggle. Assume viewport ≥ {$desktop_min}px.\n"
			. "3. Use ekwa/header-menu for the PRIMARY navigation (never type menu items). The logo MUST be a logo block — {$logo_cue} — NEVER an ekwa/image, a placeholder, or typed text.\n"
			. "4. ALWAYS include ekwa/search in the header bar (the search-overlay trigger is required in every Ekwa header), even when the brief forgets to mention it.\n"
			. "5. PHONE — no duplication: include EITHER ekwa/phone number(s) OR one ekwa/phone-dropdown (\"Call Us\"), NEVER both — they render the same numbers twice. One location → ekwa/phone; multiple locations → ekwa/phone-dropdown. More generally, never repeat the same dynamic element (phone, address, social) in both a utility strip and the main bar.\n"
			. "6. Keep it a compact header bar — no hero, no page body.\n\n";
	} elseif ( 'footer' === $context ) {
		$context_cue = "FOOTER CONTEXT — strict rules:\n"
			. "1. Build stacked, full-width footer sections (columns of links/info, then a bottom bar).\n"
			. "2. Every requested element (address, hours, social, map, footer nav, copyright, scroll-to-top) MUST appear as its block. Use core/navigation for the footer menu, ekwa/copyright for the copyright line.\n"
			. "3. DEFAULT ELEMENTS — unless the brief explicitly excludes them, a complete Ekwa footer includes ekwa/social (the social icon row) and ekwa/map (the Google map embed) alongside ekwa/address and ekwa/hours. Never hand-build social icon links or an <iframe> map — those two blocks render the real data.\n"
			. "4. Never repeat the same dynamic element (social row, address, phone) twice within the footer.\n\n";
	} else {
		$context_cue = "SECTION CONTEXT — build an in-content page section (it sits inside the main content column). Use headings/paragraphs/lists, grids/flex for layout, and ekwa content blocks as needed. "
			. "HERO sections (top-of-page banners): build them with ekwa/slider (1–3 ekwa/slide, each with ekwa/slide-content groups) — or ekwa/hero-video when a background video URL is supplied. Do not hand-build a static hero div, custom slider chrome, or CSS animations the slider already provides.\n\n";
	}

	$prompt = <<<PROMPT
You are an expert WordPress block-theme builder. You output Gutenberg BLOCK MARKUP for the Ekwa theme — NOT plain HTML. Your output is parsed straight into the block editor with wp.blocks.parse(), so it must be valid block-comment markup.

OUTPUT FORMAT — return, in this order and nothing else:
1. EXACTLY ONE <style>...</style> block containing ALL the CSS (this is the only place styling may live).
2. The block markup.
Do NOT output prose, explanations, or Markdown code fences. Do NOT wrap anything in <html>/<head>/<body>.

BLOCK MARKUP RULES:
- Container blocks use paired comments wrapping inner blocks:
    <!-- wp:ekwa/div {"className":"foo"} -->
    ...inner blocks...
    <!-- /wp:ekwa/div -->
- Leaf blocks are self-closing:
    <!-- wp:ekwa/phone {"type":"new"} /-->
- Attribute JSON must be STRICT, valid JSON (double-quoted keys/strings, no trailing commas, no comments). Omit attributes you don't need; defaults apply.
- Use ONLY the blocks listed in the BLOCK SPEC below. Do not invent block names or attributes.
- Prefer ekwa/* blocks for layout, media, and dynamic data — they are server-rendered and never trigger block-validation errors. For TEXT content (headings, paragraphs, lists) the core blocks are the normal choice: use core/heading, core/paragraph and core/list freely, but copy the serialization in the spec EXACTLY, including any wp-block-* classes, or the block becomes invalid.

STYLING RULES (scoped classes + one stylesheet — IMPORTANT):
- Wrap your ENTIRE output in EXACTLY ONE top-level ekwa/div. Give that wrapper the className "EKWA_SCOPE" (you may add more classes after it, e.g. "EKWA_SCOPE site-header"). EKWA_SCOPE is a placeholder — the system replaces it with a unique section id.
- Put EVERY style rule — layout, spacing, colors, typography, responsive media queries, :hover/:focus, pseudo-elements — inside the single top <style> block.
- SCOPE every selector by prefixing it with .EKWA_SCOPE so the styles can't leak. Examples:
    .EKWA_SCOPE .card { ... }
    .EKWA_SCOPE .card:hover { ... }
    @media (max-width: 991.98px) { .EKWA_SCOPE .grid { grid-template-columns: 1fr; } }
  To style the wrapper itself, use `.EKWA_SCOPE { ... }`.
- Name any @keyframes uniquely (e.g. ekwa-fade-EKWA_SCOPE) so they never collide with other sections.
- Apply styling by giving inner blocks a semantic `className` (BEM-ish: block__element--modifier) and targeting `.EKWA_SCOPE .that-class` in the <style>. Reuse classes/CSS variables from the SITE STYLESHEET if one is provided below.
- COLORS & DESIGN TOKENS: When a SITE STYLESHEET is provided below, REUSE its existing CSS custom properties for colors (and for fonts, spacing, and radii where they fit) — e.g. `color: var(--brand-primary)`. Do NOT hardcode a hex/rgb value that an existing variable already represents, and do NOT declare a new color variable (in :root or on the wrapper) that duplicates one already defined in the site stylesheet. Only introduce a new variable when no suitable one exists; otherwise reference the existing var() directly.
- DO NOT use any per-block `inlineStyle` attribute, and do NOT put a `style="..."` attribute on elements. All CSS goes in the <style> block.
- LAYOUT: ekwa/div is the only layout container — do NOT use ekwa/flex, ekwa/grid, ekwa/container or ekwa/card-link (deprecated). Express flex rows, grids and centered max-width wrappers by writing display:flex / display:grid / max-width CSS under the wrapper's className in the <style> block, with @media rules for responsive column changes.
- RESPONSIVE BREAKPOINTS — this site's bands are: mobile ≤ {$mobile_max}px, tablet {$mobile_max}–{$tablet_max}px, desktop ≥ {$desktop_min}px. Use these exact widths in your @media rules (e.g. `@media (max-width: {$tablet_max}px)` for tablet-and-down, `@media (max-width: {$mobile_max}px)` for mobile).

DATA BLOCKS (content filled at runtime):
- Blocks like ekwa/phone, ekwa/address, ekwa/hours, ekwa/copyright, ekwa/social, ekwa/svg-logo, ekwa/header-menu, ekwa/phone-dropdown, ekwa/address-dropdown, core/site-logo, core/navigation pull their real content from Theme Settings / the assigned menu at render time. Emit the block with presentation attributes only — NEVER type fake phone numbers, addresses, hours, or menu items into them.

CONTENT RULES:
- Use the user's prompt as the source of truth for copy. Use supplied text verbatim; otherwise write plausible placeholder copy for the section type.
- For images use https://placehold.co/WIDTHxHEIGHT placeholders unless the user gives real URLs.
- If the user attaches screenshots, treat them as layout references unless the prompt says otherwise.
PROMPT;

	if ( 'edit' === $mode ) {
		$prompt .= "\n\nEDIT MODE — you are MODIFYING an existing section supplied in the user message (its current block markup, and its CSS, which may appear as a <style> block or inside the wrapper's scopedCss attribute):\n"
			. "- First read the existing markup and CSS carefully, then apply ONLY the change the user asks for. Preserve all other text, structure, classNames, attributes, and styles exactly as they are.\n"
			. "- Return the COMPLETE updated section (never a diff or partial snippet) in the OUTPUT FORMAT above: one <style> block holding ALL the section CSS, then the full block markup.\n"
			. "- KEEP the scope class that is already on the top-level ekwa/div wrapper, and keep every CSS selector scoped under that exact class. Do NOT introduce the EKWA_SCOPE placeholder when the section already has a scope class.\n"
			. "- Do not drop, reorder, or rename existing blocks unless the user explicitly asks you to.";
	}

	$prompt .= ekwa_ai_build_block_spec_section( $context );

	// Section context only. The shortcode advice inside it is true for page
	// content, which renders through the_content() and therefore gets
	// do_shortcode(); a header or footer is an FSE template part, which does
	// not, so a shortcode there would print as literal text. Those two contexts
	// already carry their own "never type a phone number, use the block" rule.
	if ( 'section' === $context && function_exists( 'ekwa_phone_ai_context' ) ) {
		$prompt .= ekwa_phone_ai_context();
	}

	if ( function_exists( 'ekwa_ai_project_memory_block' ) ) {
		$prompt .= ekwa_ai_project_memory_block();
	}

	if ( function_exists( 'ekwa_tokens_ai_context' ) ) {
		$prompt .= ekwa_tokens_ai_context();
	}

	return $context_cue . $prompt;
}

/**
 * Best-effort: collect block names in the markup that are not registered.
 *
 * @param string $markup Block-comment markup.
 * @return array<int,string> Warning strings (possibly empty).
 */
function ekwa_ai_generate_blocks_validate( $markup ) {
	$warnings = array();
	if ( '' === trim( $markup ) ) {
		$warnings[] = 'The AI returned no block markup.';
		return $warnings;
	}

	if ( ! preg_match_all( '/<!--\s*wp:([a-z0-9-]+\/[a-z0-9-]+|[a-z0-9-]+)\b/i', $markup, $m ) ) {
		$warnings[] = 'No block comments were found in the output.';
		return $warnings;
	}

	$registry = WP_Block_Type_Registry::get_instance();
	$seen     = array();
	foreach ( $m[1] as $name ) {
		// Core blocks are written without the "core/" prefix in markup.
		$full = ( false === strpos( $name, '/' ) ) ? 'core/' . $name : $name;
		if ( isset( $seen[ $full ] ) ) {
			continue;
		}
		$seen[ $full ] = true;
		if ( ! $registry->is_registered( $full ) ) {
			$warnings[] = sprintf( 'Unknown block "%s" — it may not insert correctly.', $full );
		}
	}

	return $warnings;
}

/**
 * Best-effort server-side render of the generated block markup, for preview.
 *
 * Wrapped defensively: any failure returns an empty string and the UI falls
 * back to showing the markup only. Some page-context blocks (related posts,
 * load-more) render empty outside the loop — that's acceptable for a preview.
 *
 * @param string $markup Block-comment markup.
 * @return string Rendered HTML, or '' on failure.
 */
function ekwa_ai_generate_blocks_render_preview( $markup ) {
	if ( '' === trim( $markup ) ) {
		return '';
	}
	try {
		$blocks = parse_blocks( $markup );
		if ( empty( $blocks ) ) {
			return '';
		}
		$html = '';
		foreach ( $blocks as $block ) {
			$html .= render_block( $block );
		}
		// Mirror the_content()'s order — do_blocks() then do_shortcode(). Block
		// rendering does not run shortcodes, so without this a [ekwa_phone] in a
		// paragraph previews as its own literal text and reads as a bug.
		return do_shortcode( $html );
	} catch ( \Throwable $e ) {
		return '';
	}
}

/**
 * Repair malformed attribute JSON in block-comment markup.
 *
 * LLMs frequently emit *almost*-valid JSON in block attributes — trailing
 * commas, smart/curly quotes, non-breaking spaces. WordPress's block parser
 * does not repair these; it silently discards the entire attribute set, so the
 * block renders with its defaults (e.g. a button with no text or link — a
 * "broken" button). This walks every `<!-- wp:NAME {…} -->` / `… /-->` comment
 * and, for any whose JSON won't parse, applies safe deterministic fixes and
 * re-serializes it with wp_json_encode().
 *
 * @param string $markup Block-comment markup.
 * @return array{ markup:string, repaired:int, failed:int, failed_names:array<int,string> }
 */
function ekwa_ai_repair_block_markup( $markup ) {
	$stats = array( 'repaired' => 0, 'failed' => 0, 'failed_names' => array() );

	if ( '' === trim( (string) $markup ) ) {
		return array( 'markup' => (string) $markup ) + $stats;
	}

	// Group 1: opening "<!-- wp:name " ; Group 2: name ; Group 3: balanced JSON
	// object (string-aware, recursive via (?3)) ; Group 4: closing "-->"/"/-->".
	$pattern = '/(<!--\s*wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)\s*)(\{(?:[^{}"]++|"(?:\\\\.|[^"\\\\])*+"|(?3))*+\})(\s*\/?-->)/i';

	$out = preg_replace_callback( $pattern, function ( $m ) use ( &$stats ) {
		$json = $m[3];

		json_decode( $json );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $m[0]; // Already valid — leave untouched.
		}

		$fixed = ekwa_ai_fix_json_blob( $json );
		if ( null !== $fixed ) {
			$decoded = json_decode( $fixed, true );
			if ( is_array( $decoded ) ) {
				$stats['repaired']++;
				return $m[1] . wp_json_encode( $decoded ) . $m[4];
			}
		}

		// Couldn't fix it deterministically — leave the original text in place so a
		// later AI self-correct pass can still see the intended values.
		$name = $m[2];
		$stats['failed']++;
		$stats['failed_names'][] = ( false === strpos( $name, '/' ) ) ? 'core/' . $name : $name;
		return $m[0];
	}, $markup );

	// PCRE failure (e.g. backtrack limit on pathological input): keep original.
	if ( null === $out ) {
		return array( 'markup' => $markup ) + $stats;
	}

	return array( 'markup' => $out ) + $stats;
}

/**
 * Structural repairs on generated block markup — the class of mistakes that
 * produce *valid* markup which nonetheless renders wrong.
 *
 * 1. HTML SMUGGLED INTO A TEXT ATTRIBUTE. Asked to convert
 *    `<a href="#"><img src="us.png"> English</a>`, models like to emit
 *    `ekwa/link {"text":"<img src=\"us.png\"> English"}`. ekwa/link escapes its
 *    text (as it must), so the reader sees the literal tag and the image is
 *    gone. The element is re-expressed the way the deterministic converter
 *    writes it: ekwa/div with tagName="a" wrapping real child blocks.
 *
 * 2. className THAT NEVER REACHED THE SAVED MARKUP. core/list, core/paragraph
 *    and friends are static blocks — they render their stored HTML, not their
 *    attributes. `<!-- wp:list {"className":"lang-dropdown"} --><ul
 *    class="wp-block-list">` therefore drops the mockup's class on the floor
 *    and the dropdown loses its CSS. The class is copied onto the element.
 *
 * 3. AN INVENTED `style` ATTRIBUTE. Told to preserve a mockup's inline style,
 *    models reach for the shape they know from core and emit
 *    `{"style":{"marginBottom":"4rem"}}`. No Ekwa block declares `style`, so
 *    the whole thing is dropped and the spacing silently disappears. The flat
 *    CSS map is rewritten into the real `inlineStyle` string attribute.
 *
 * @param string $markup  Block-comment markup.
 * @param array  $options {
 *     @type bool $bare_icons Emit ekwa/icon with no wrapper <div> unless the
 *                            block names one. Set by the mockup converter: a
 *                            bare <i> in the source must stay a bare <i>, but
 *                            the block's own "way-icon" default is deliberate
 *                            for generated content (service cards), so this is
 *                            opt-in rather than a change to the default.
 * }
 * @return array{markup:string,notes:array<int,string>,fixed:int}
 */
function ekwa_ai_repair_block_structure( $markup, $options = array() ) {
	$notes = array();
	if ( '' === trim( (string) $markup ) ) {
		return array( 'markup' => (string) $markup, 'notes' => $notes, 'fixed' => 0 );
	}

	$stats  = array( 'unwrapped' => 0, 'classed' => 0, 'icons' => 0, 'styled' => 0 );
	$stats['bare_icons'] = ! empty( $options['bare_icons'] );
	$blocks = parse_blocks( $markup );
	$blocks = ekwa_ai_repair_blocks_walk( $blocks, $stats );

	if ( $stats['unwrapped'] > 0 ) {
		$notes[] = sprintf(
			_n(
				'Rebuilt %d link that had HTML (an image or icon) inside its text — it is now a real link block with child blocks.',
				'Rebuilt %d links that had HTML (images or icons) inside their text — they are now real link blocks with child blocks.',
				$stats['unwrapped'],
				'ekwa'
			),
			$stats['unwrapped']
		);
	}
	if ( $stats['classed'] > 0 ) {
		$notes[] = sprintf(
			_n(
				'Restored the CSS class on %d block whose class was set as an attribute but missing from its markup.',
				'Restored the CSS class on %d blocks whose class was set as an attribute but missing from their markup.',
				$stats['classed'],
				'ekwa'
			),
			$stats['classed']
		);
	}
	if ( $stats['icons'] > 0 ) {
		$notes[] = sprintf(
			_n(
				'Dropped the wrapper <div> from %d icon so it renders as the bare <i> the mockup had.',
				'Dropped the wrapper <div> from %d icons so they render as the bare <i> the mockup had.',
				$stats['icons'],
				'ekwa'
			),
			$stats['icons']
		);
	}

	if ( $stats['styled'] > 0 ) {
		$notes[] = sprintf(
			_n(
				'Moved %d invented "style" attribute into the block\'s Inline Style field, so the mockup\'s inline CSS survives.',
				'Moved %d invented "style" attributes into the blocks\' Inline Style fields, so the mockup\'s inline CSS survives.',
				$stats['styled'],
				'ekwa'
			),
			$stats['styled']
		);
	}

	if ( ! $stats['unwrapped'] && ! $stats['classed'] && ! $stats['icons'] && ! $stats['styled'] ) {
		return array( 'markup' => $markup, 'notes' => $notes, 'fixed' => 0 );
	}

	return array(
		'markup' => serialize_blocks( $blocks ),
		'notes'  => $notes,
		'fixed'  => $stats['unwrapped'] + $stats['classed'] + $stats['icons'] + $stats['styled'],
	);
}

/**
 * Recursive worker for ekwa_ai_repair_block_structure().
 *
 * @param array $blocks Parsed blocks.
 * @param array $stats  Counters, by reference.
 * @return array Repaired blocks.
 */
function ekwa_ai_repair_blocks_walk( $blocks, &$stats ) {
	$out = array();

	foreach ( $blocks as $block ) {
		if ( ! empty( $block['innerBlocks'] ) ) {
			$block['innerBlocks'] = ekwa_ai_repair_blocks_walk( $block['innerBlocks'], $stats );
		}

		$block = ekwa_ai_repair_text_attribute_html( $block, $stats );
		$block = ekwa_ai_repair_static_class( $block, $stats );
		$block = ekwa_ai_repair_bare_icon( $block, $stats );
		$block = ekwa_ai_repair_style_attribute( $block, $stats );

		$out[] = $block;
	}

	return $out;
}

/**
 * Blocks whose "text" attribute is rendered as escaped plain text, mapped to
 * the tag they should become when that text turns out to contain HTML.
 *
 * @return array<string,string> block name => replacement tagName.
 */
function ekwa_ai_repair_text_blocks() {
	return array(
		'ekwa/link'   => 'a',
		'ekwa/button' => 'a',
		'ekwa/text'   => '',  // '' = keep the block's own tagName (default span).
	);
}

/**
 * Turn `ekwa/link {"text":"<img …> English"}` into an ekwa/div anchor wrapping
 * real child blocks.
 *
 * @param array $block Parsed block.
 * @param array $stats Counters, by reference.
 * @return array
 */
function ekwa_ai_repair_text_attribute_html( $block, &$stats ) {
	$targets = ekwa_ai_repair_text_blocks();
	$name    = isset( $block['blockName'] ) ? $block['blockName'] : '';
	if ( ! isset( $targets[ $name ] ) ) {
		return $block;
	}

	$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
	$text  = isset( $attrs['text'] ) ? (string) $attrs['text'] : '';

	// A tag, not merely an entity or a stray "<".
	if ( '' === $text || ! preg_match( '/<[a-z][a-z0-9]*\b[^>]*>/i', $text ) ) {
		return $block;
	}

	$children = ekwa_ai_repair_html_to_blocks( $text );
	if ( empty( $children ) ) {
		return $block; // Nothing usable — leave it for a human to look at.
	}

	$tag = $targets[ $name ];
	if ( '' === $tag ) {
		$tag = isset( $attrs['tagName'] ) && $attrs['tagName'] ? (string) $attrs['tagName'] : 'span';
	}

	$new_attrs = array( 'tagName' => $tag );
	foreach ( array( 'className', 'target', 'rel' ) as $keep ) {
		if ( ! empty( $attrs[ $keep ] ) ) {
			$new_attrs[ $keep ] = $attrs[ $keep ];
		}
	}
	// ekwa/link stores the destination in "url"; ekwa/div uses "href".
	$url = '';
	foreach ( array( 'url', 'href' ) as $key ) {
		if ( ! empty( $attrs[ $key ] ) ) {
			$url = (string) $attrs[ $key ];
			break;
		}
	}
	if ( 'a' === $tag && '' !== $url ) {
		$new_attrs['href'] = $url;
	}
	if ( ! empty( $attrs['newTab'] ) ) {
		$new_attrs['target'] = '_blank';
	}
	if ( ! empty( $attrs['customAttributes'] ) && is_array( $attrs['customAttributes'] ) ) {
		$new_attrs['customAttributes'] = $attrs['customAttributes'];
	}

	$stats['unwrapped']++;

	return array(
		'blockName'    => 'ekwa/div',
		'attrs'        => $new_attrs,
		'innerBlocks'  => $children,
		'innerHTML'    => '',
		'innerContent' => array_fill( 0, count( $children ), null ),
	);
}

/**
 * Convert an HTML fragment into parsed blocks, reusing the deterministic
 * converter so the result matches what the non-AI path would have produced.
 *
 * @param string $html Fragment.
 * @return array Parsed blocks (empty on failure).
 */
function ekwa_ai_repair_html_to_blocks( $html ) {
	if ( ! function_exists( 'ekwa_mc_convert_html' ) ) {
		$lib = get_template_directory() . '/inc/ekwa-converter-lib.php';
		if ( ! file_exists( $lib ) ) {
			return array();
		}
		require_once $lib;
	}

	// The converter keeps per-run state in a static context; snapshot it so
	// repairing a block mid-response can't disturb an enclosing conversion.
	$saved = function_exists( 'ekwa_mc_context' ) ? ekwa_mc_context() : null;

	try {
		// Dynamic-data detection stays OFF: this fragment is already inside a
		// converted tree, and re-detecting would swap a decorative flag image
		// for a site-logo block.
		$result = ekwa_mc_convert_html( $html, null, array( 'detect_dynamic' => false ) );
		$blocks = parse_blocks( isset( $result['markup'] ) ? $result['markup'] : '' );
	} catch ( \Throwable $e ) {
		$blocks = array();
	}

	if ( null !== $saved ) {
		ekwa_mc_context( $saved );
	}

	// parse_blocks() yields whitespace-only "null name" blocks between siblings.
	$blocks = array_values( array_filter( $blocks, function ( $b ) {
		return ! empty( $b['blockName'] );
	} ) );

	return $blocks;
}

/**
 * Keep a converted icon a bare `<i>`.
 *
 * `ekwa/icon` defaults `wrapperClass` to "way-icon" (a service-card convention),
 * so an omitted attribute renders `<div class="way-icon"><i …></i></div>`. When
 * the source was a bare `<i>` in a flex row — a dropdown caret, a search glyph —
 * that extra block-level div breaks the layout. Setting the attribute to an
 * empty string makes the block emit just the `<i>`.
 *
 * Only runs for the mockup converter (see the $options['bare_icons'] flag);
 * generated content keeps the default.
 *
 * @param array $block Parsed block.
 * @param array $stats Counters, by reference.
 * @return array
 */
function ekwa_ai_repair_bare_icon( $block, &$stats ) {
	if ( empty( $stats['bare_icons'] ) ) {
		return $block;
	}
	if ( ! isset( $block['blockName'] ) || 'ekwa/icon' !== $block['blockName'] ) {
		return $block;
	}
	// An explicit wrapperClass is a deliberate choice — leave it.
	if ( isset( $block['attrs']['wrapperClass'] ) ) {
		return $block;
	}

	$block['attrs']                 = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
	$block['attrs']['wrapperClass'] = '';
	$stats['icons']++;

	return $block;
}

/**
 * Top-level keys of core's structured `style` attribute.
 *
 * A `style` object built from these is a genuine core block-supports value
 * (`{"style":{"spacing":{"margin":{"bottom":"4rem"}}}}`) that WordPress
 * serializes itself — it must be left completely alone. Anything else is the
 * model inventing a flat CSS map.
 *
 * @return string[]
 */
function ekwa_ai_core_style_groups() {
	return array(
		'color', 'spacing', 'typography', 'border', 'elements',
		'shadow', 'dimensions', 'filter', 'outline', 'position', 'background',
	);
}

/**
 * Flatten a model-invented `style` object into a CSS declaration string.
 *
 * `{"marginBottom":"4rem","borderRadius":"6px"}` → "margin-bottom:4rem;border-radius:6px".
 * Returns '' when the value is a real core style object, is nested, or holds
 * anything that isn't a plain scalar — in those cases the caller leaves it be.
 *
 * @param mixed $style The block's `style` attribute value.
 * @return string
 */
function ekwa_ai_style_object_to_css( $style ) {
	if ( is_string( $style ) ) {
		// Already a CSS string ({"style":"margin-bottom:4rem"}) — just needs
		// moving to the attribute that actually renders.
		return trim( $style, " \t\n\r;" );
	}
	if ( ! is_array( $style ) || empty( $style ) ) {
		return '';
	}

	$groups = ekwa_ai_core_style_groups();
	$parts  = array();

	foreach ( $style as $prop => $value ) {
		// A support group, a nested map, or a non-scalar: this is core's shape.
		if ( in_array( (string) $prop, $groups, true ) || ! is_scalar( $value ) ) {
			return '';
		}
		$prop  = trim( (string) $prop );
		$value = trim( (string) $value );
		if ( '' === $prop || '' === $value ) {
			continue;
		}
		// camelCase → kebab-case, leaving custom properties (--brand) alone.
		if ( 0 !== strpos( $prop, '--' ) ) {
			$prop = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $prop ) );
		}
		if ( ! preg_match( '/^(?:--)?[a-z][a-z0-9-]*$/', $prop ) ) {
			return ''; // Not a CSS property name — don't guess.
		}
		$parts[] = $prop . ':' . $value;
	}

	return implode( ';', $parts );
}

/**
 * Rescue an invented `style` attribute into the real `inlineStyle` one.
 *
 * The convert prompt asks for the mockup's inline CSS to be preserved, and
 * models reliably reach for core's attribute name instead of the theme's. No
 * Ekwa block declares `style`, so the parser drops it and the declaration is
 * gone — this is exactly the failure the inlineStyle passthrough exists to
 * prevent, so it is repaired deterministically rather than left to the prompt.
 *
 * @param array $block Parsed block.
 * @param array $stats Counters, by reference.
 * @return array
 */
function ekwa_ai_repair_style_attribute( $block, &$stats ) {
	$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
	if ( '' === $name || ! isset( $block['attrs']['style'] ) ) {
		return $block;
	}
	$supported = function_exists( 'ekwa_inline_style_blocks' ) ? ekwa_inline_style_blocks() : array();
	if ( ! in_array( $name, $supported, true ) ) {
		return $block;
	}

	$css = ekwa_ai_style_object_to_css( $block['attrs']['style'] );
	if ( '' === $css ) {
		return $block; // Genuine core style object (or unparseable) — untouched.
	}

	// An explicit inlineStyle the model got right comes first; the rescued
	// declarations follow, matching the "last one wins" order the renderers use.
	$existing = isset( $block['attrs']['inlineStyle'] ) ? trim( (string) $block['attrs']['inlineStyle'], " \t\n\r;" ) : '';

	unset( $block['attrs']['style'] );
	$block['attrs']['inlineStyle'] = $existing ? $existing . ';' . $css : $css;
	$stats['styled']++;

	return $block;
}

/**
 * Static core blocks render their saved HTML, so a className attribute that
 * isn't also on the element is silently dropped. Copy it across.
 *
 * @param array $block Parsed block.
 * @param array $stats Counters, by reference.
 * @return array
 */
function ekwa_ai_repair_static_class( $block, &$stats ) {
	static $static_blocks = array( 'core/list', 'core/paragraph', 'core/heading', 'core/quote', 'core/table', 'core/preformatted' );

	$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
	if ( ! in_array( $name, $static_blocks, true ) ) {
		return $block;
	}

	$class = isset( $block['attrs']['className'] ) ? trim( (string) $block['attrs']['className'] ) : '';
	if ( '' === $class || '' === trim( (string) $block['innerHTML'] ) ) {
		return $block;
	}

	$wanted    = preg_split( '/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY );
	$satisfied = false;

	// Patch only the FIRST opening tag — the block's own wrapper element.
	$patch = function ( $html ) use ( $wanted, &$satisfied ) {
		$done = false;
		$out  = preg_replace_callback(
			'/<([a-z][a-z0-9]*)((?:\s[^>]*?)?)(\/?)>/i',
			function ( $m ) use ( $wanted, &$done, &$satisfied ) {
				if ( $done ) {
					return $m[0];
				}
				$done  = true;
				$attrs = $m[2];

				if ( preg_match( '/\sclass\s*=\s*"([^"]*)"/i', $attrs, $cm ) ) {
					$have    = preg_split( '/\s+/', $cm[1], -1, PREG_SPLIT_NO_EMPTY );
					$missing = array_diff( $wanted, $have );
					if ( empty( $missing ) ) {
						$satisfied = true; // Already carries every class.
						return $m[0];
					}
					$new_class = implode( ' ', array_merge( $have, $missing ) );
					$attrs     = preg_replace( '/\sclass\s*=\s*"[^"]*"/i', ' class="' . esc_attr( $new_class ) . '"', $attrs, 1 );
					return '<' . $m[1] . $attrs . $m[3] . '>';
				}

				return '<' . $m[1] . $attrs . ' class="' . esc_attr( implode( ' ', $wanted ) ) . '"' . $m[3] . '>';
			},
			$html,
			1
		);
		return array( null === $out ? $html : $out, $done );
	};

	// serialize_blocks() rebuilds from innerContent, so the chunk holding the
	// opening tag is the one that has to change — patching the (concatenated)
	// innerHTML alone would be thrown away for any block with inner blocks.
	$chunks  = ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) )
		? $block['innerContent']
		: array( $block['innerHTML'] );
	$changed = false;

	foreach ( $chunks as $i => $chunk ) {
		if ( ! is_string( $chunk ) || '' === trim( $chunk ) ) {
			continue;
		}
		list( $patched, $found ) = $patch( $chunk );
		if ( ! $found ) {
			continue; // No tag in this chunk — keep looking.
		}
		if ( $satisfied || $patched === $chunk ) {
			return $block; // Nothing to do.
		}
		$chunks[ $i ] = $patched;
		$changed      = true;
		break;
	}

	if ( ! $changed ) {
		return $block;
	}

	$block['innerContent'] = $chunks;
	$block['innerHTML']    = implode( '', array_filter( $chunks, 'is_string' ) );
	$stats['classed']++;

	return $block;
}

/**
 * Apply safe, deterministic fixes to a single JSON attribute blob and return the
 * corrected string, or null if it still won't parse.
 *
 * Only low-risk transforms are applied (curly quotes → straight, non-breaking
 * spaces → spaces, trailing commas removed) so we never corrupt valid content
 * such as URLs or apostrophes inside string values.
 *
 * @param string $json Raw (invalid) JSON object text, including braces.
 * @return string|null
 */
function ekwa_ai_fix_json_blob( $json ) {
	$fixed = strtr( $json, array(
		"\xE2\x80\x9C" => '"',  // “ left double quote
		"\xE2\x80\x9D" => '"',  // ” right double quote
		"\xE2\x80\x9E" => '"',  // „ low double quote
		"\xE2\x80\x98" => "'",  // ‘ left single quote
		"\xE2\x80\x99" => "'",  // ’ right single quote
		"\xC2\xA0"     => ' ',  // non-breaking space
	) );

	// Drop trailing commas before a closing } or ].
	$fixed = preg_replace( '/,(\s*[}\]])/', '$1', $fixed );
	if ( null === $fixed ) {
		return null;
	}

	json_decode( $fixed );
	return ( JSON_ERROR_NONE === json_last_error() ) ? $fixed : null;
}

/**
 * Ask Gemini (best-quality model, low temperature) to fix block markup whose
 * attribute JSON is still invalid after deterministic repair. Returns corrected
 * block markup, or null on any failure (the caller falls back to the original).
 *
 * @param string $markup  Block-comment markup with one or more invalid attrs.
 * @param string $api_key Gemini API key.
 * @return string|null
 */
function ekwa_ai_blocks_self_correct( $markup, $api_key ) {
	$system = 'You are a Gutenberg block-markup repair tool for the Ekwa WordPress theme. '
		. 'You receive block-comment markup whose attribute JSON is malformed on one or more blocks. '
		. 'Return the SAME markup with ONLY the invalid attribute JSON corrected to strict, valid JSON '
		. '(double-quoted keys and string values, no trailing commas, all special characters escaped). '
		. 'Do NOT change the block structure, the visible text, the classNames, or the styling. '
		. 'Preserve every className exactly (including any scope class). '
		. 'Output ONLY the corrected block markup — no prose, no Markdown code fences, no <style> block.';

	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array( array( 'text' => $markup ) ),
		),
	);

	// The correction echoes the WHOLE markup back, so give it the same full output
	// window the generation step now uses — otherwise a large section that fits on
	// generation would be truncated here and rejected by the caller's parse check.
	$result = ekwa_ai_generate_call_gemini( $system, $contents, 0.1, $api_key, ekwa_ai_default_model(), 65536 );
	if ( is_wp_error( $result ) ) {
		return null;
	}

	// A cut-off correction is a partial copy of the markup — discard it and let the
	// caller keep the original rather than adopt truncated (block-dropping) output.
	if ( isset( $result['finish_reason'] ) && 'MAX_TOKENS' === $result['finish_reason'] ) {
		return null;
	}

	$fixed = trim( ekwa_ai_generate_strip_fences( $result['content'] ) );
	return ( '' !== $fixed ) ? $fixed : null;
}
