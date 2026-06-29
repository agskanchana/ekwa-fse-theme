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
	$mode          = (string) $request->get_param( 'mode' );
	$base_markup   = (string) $request->get_param( 'base_markup' );
	$base_css      = (string) $request->get_param( 'base_css' );
	if ( ! in_array( $context, array( 'header', 'footer', 'section' ), true ) ) {
		$context = 'section';
	}
	if ( ! in_array( $mode, array( 'create', 'edit' ), true ) ) {
		$mode = 'create';
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
 * @param string $mode    'create' (generate from scratch) or 'edit' (modify an
 *                        existing section supplied in the user message).
 * @return string
 */
function ekwa_ai_generate_blocks_system_prompt( $context = 'section', $mode = 'create' ) {
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
- COLORS & DESIGN TOKENS: When a SITE STYLESHEET is provided below, REUSE its existing CSS custom properties for colors (and for fonts, spacing, and radii where they fit) — e.g. `color: var(--brand-primary)`. Do NOT hardcode a hex/rgb value that an existing variable already represents, and do NOT declare a new color variable (in :root or on the wrapper) that duplicates one already defined in the site stylesheet. Only introduce a new variable when no suitable one exists; otherwise reference the existing var() directly.
- DO NOT use any per-block `inlineStyle` attribute, and do NOT put a `style="..."` attribute on elements. All CSS goes in the <style> block.
- ekwa/flex and ekwa/grid already emit their own display/flex/grid declarations from their attributes — set those via attributes, and use the className only for gap and extra styling.

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

	$result = ekwa_ai_generate_call_gemini( $system, $contents, 0.1, $api_key, 'gemini-2.5-pro' );
	if ( is_wp_error( $result ) ) {
		return null;
	}

	$fixed = trim( ekwa_ai_generate_strip_fences( $result['content'] ) );
	return ( '' !== $fixed ) ? $fixed : null;
}
