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
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args' => array(
			'prompt' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'images'        => array( 'required' => false, 'type' => 'array',   'default' => array() ),
			'history'       => array( 'required' => false, 'type' => 'array',   'default' => array() ),
			'use_child_css' => array( 'required' => false, 'type' => 'boolean', 'default' => true ),
			'temperature'   => array( 'required' => false, 'type' => 'number',  'default' => 0.3 ),
			'model'         => array( 'required' => false, 'type' => 'string',  'default' => 'gemini-2.5-flash' ),
			'context'       => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'section',
				'enum'     => array( 'header', 'footer', 'section' ),
			),
		),
	) );
}

/**
 * Handle the AI block generation REST request.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_generate_blocks_handle_request( $request ) {
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
	$temperature   = (float) $request->get_param( 'temperature' );
	$model         = (string) $request->get_param( 'model' );
	$context       = (string) $request->get_param( 'context' );
	if ( ! in_array( $context, array( 'header', 'footer', 'section' ), true ) ) {
		$context = 'section';
	}

	$allowed_models = ekwa_ai_generate_allowed_models();
	if ( ! isset( $allowed_models[ $model ] ) ) {
		$model = 'gemini-2.5-flash';
	}

	if ( '' === $prompt ) {
		return new WP_Error( 'empty_prompt', 'Prompt is required.', array( 'status' => 400 ) );
	}
	if ( count( $images ) > 6 ) {
		return new WP_Error( 'too_many_images', 'Up to 6 images per request.', array( 'status' => 400 ) );
	}

	// Reuse the multimodal contents builder from the HTML generator (handles
	// history reconstruction + image validation identically).
	$contents = ekwa_ai_generate_build_contents( $prompt, $images, $history );
	if ( is_wp_error( $contents ) ) {
		return $contents;
	}

	$system_prompt = ekwa_ai_generate_blocks_system_prompt( $context );
	if ( $use_child_css ) {
		$system_prompt .= ekwa_ai_generate_child_stylesheet_context();
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

	// Replace the AI's scoping sentinel with a real unique section id in BOTH the
	// CSS and the markup, then embed the (scoped) CSS into the wrapper block's
	// scopedCss attribute so the section becomes self-contained — its CSS inlines
	// on the front end only where the block renders (ekwa_render_div_block).
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
 * Build the system prompt that makes Gemini emit Gutenberg block markup.
 *
 * @param string $context One of: 'header', 'footer', 'section'.
 * @return string
 */
function ekwa_ai_generate_blocks_system_prompt( $context = 'section' ) {
	$context_cue = '';
	if ( 'header' === $context ) {
		$context_cue = "HEADER CONTEXT — strict rules:\n"
			. "1. DESKTOP ONLY. The site has a separate mobile header. The whole header is your single top-level ekwa/div (className \"EKWA_SCOPE\"); hide it on smaller screens with this rule in your <style>: `@media (max-width: 1199.98px){ .EKWA_SCOPE{ display:none !important; } }`.\n"
			. "2. NO mobile markup — no hamburger, no off-canvas drawer, no mobile toggle. Assume viewport ≥ 1200px.\n"
			. "3. Use ekwa/header-menu for the PRIMARY navigation (never type menu items). Use core/site-logo OR ekwa/svg-logo for the logo. Every requested element (logo, menu, phone, search, address, social, button) MUST appear as its block.\n"
			. "4. Keep it a compact header bar — no hero, no page body.\n\n";
	} elseif ( 'footer' === $context ) {
		$context_cue = "FOOTER CONTEXT — strict rules:\n"
			. "1. Build stacked, full-width footer sections (columns of links/info, then a bottom bar).\n"
			. "2. Every requested element (address, hours, social, map, footer nav, copyright, scroll-to-top) MUST appear as its block. Use core/navigation for the footer menu, ekwa/copyright for the copyright line.\n\n";
	} else {
		$context_cue = "SECTION CONTEXT — build an in-content page section (it sits inside the main content column). Use headings/paragraphs/lists, grids/flex for layout, and ekwa content blocks as needed.\n\n";
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
- Prefer ekwa/* blocks — they are server-rendered and never trigger block-validation errors. For the core/* text blocks (paragraph, heading, list) copy the serialization in the spec EXACTLY, including any wp-block-* classes, or the block becomes invalid.

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
- DO NOT use any per-block `inlineStyle` attribute, and do NOT put a `style="..."` attribute on elements. All CSS goes in the <style> block.
- ekwa/flex and ekwa/grid already emit their own display/flex/grid declarations from their attributes — set those via attributes, and use the className only for gap and extra styling.

DATA BLOCKS (content filled at runtime):
- Blocks like ekwa/phone, ekwa/address, ekwa/hours, ekwa/copyright, ekwa/social, ekwa/svg-logo, ekwa/header-menu, ekwa/phone-dropdown, ekwa/address-dropdown, core/site-logo, core/navigation pull their real content from Theme Settings / the assigned menu at render time. Emit the block with presentation attributes only — NEVER type fake phone numbers, addresses, hours, or menu items into them.

CONTENT RULES:
- Use the user's prompt as the source of truth for copy. Use supplied text verbatim; otherwise write plausible placeholder copy for the section type.
- For images use https://placehold.co/WIDTHxHEIGHT placeholders unless the user gives real URLs.
- If the user attaches screenshots, treat them as layout references unless the prompt says otherwise.
PROMPT;

	$prompt .= ekwa_ai_build_block_spec_section( $context );

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
		return $html;
	} catch ( \Throwable $e ) {
		return '';
	}
}
