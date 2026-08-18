<?php
/**
 * AI HTML generation for the Mockup Converter — Gemini multimodal API.
 *
 * Provides POST /ekwa/v1/ai-generate-html. Accepts a free-form prompt and
 * optional reference screenshots; returns clean HTML (with inline styles)
 * plus any extracted <style> and <script> content for the user to review.
 *
 * The Mockup Converter consumes the returned HTML separately — this endpoint
 * does not call the converter or know about block markup.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once get_template_directory() . '/inc/ekwa-ai-shared.php';

add_action( 'rest_api_init', 'ekwa_ai_generate_register_routes' );

/**
 * Register the AI HTML generation REST route.
 */
function ekwa_ai_generate_register_routes() {
	register_rest_route( 'ekwa/v1', '/ai-generate-html', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_ai_generate_handle_request',
		'permission_callback' => 'ekwa_ai_rest_permission',
		'args' => array(
			'prompt' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'images' => array(
				'required' => false,
				'type'     => 'array',
				'default'  => array(),
			),
			'history' => array(
				'required' => false,
				'type'     => 'array',
				'default'  => array(),
			),
			'use_child_css' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => true,
			),
			'temperature' => array(
				'required' => false,
				'type'     => 'number',
				'default'  => 0.4,
			),
			'model' => array(
				'required' => false,
				'type'     => 'string',
				'default'  => '',
			),
			'context' => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'page',
				'enum'     => array( 'header', 'footer', 'page' ),
			),
		),
	) );
}

/**
 * Allow-list of Gemini models the front end can request. Anything else
 * falls back to the current best model for the feature's tier.
 *
 * Kept as a thin wrapper because several modules already call it; the list
 * itself now comes from inc/ekwa-ai-models.php, which reads what the
 * configured API key can actually call.
 *
 * @return array<string,string> Map of model id → human label.
 */
function ekwa_ai_generate_allowed_models() {
	return ekwa_ai_models();
}

/**
 * Handle the AI HTML generation REST request.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_generate_handle_request( $request ) {
	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'html' );
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
	$temperature   = (float) $request->get_param( 'temperature' );
	$model         = (string) $request->get_param( 'model' );
	$context       = (string) $request->get_param( 'context' );
	if ( ! in_array( $context, array( 'header', 'footer', 'page' ), true ) ) {
		$context = 'page';
	}

	$model = ekwa_ai_resolve_model( $model, 'pro' );

	if ( '' === $prompt ) {
		return new WP_Error(
			'empty_prompt',
			'Prompt is required.',
			array( 'status' => 400 )
		);
	}

	// Hard limits matching what the JS enforces.
	if ( count( $images ) > 6 ) {
		return new WP_Error(
			'too_many_images',
			'Up to 6 images per request.',
			array( 'status' => 400 )
		);
	}

	$contents = ekwa_ai_generate_build_contents( $prompt, $images, $history );
	if ( is_wp_error( $contents ) ) {
		return $contents;
	}

	$system_prompt = ekwa_ai_generate_build_system_prompt( $context );
	if ( $use_child_css ) {
		$system_prompt .= ekwa_ai_generate_child_stylesheet_context();
	}

	$result = ekwa_ai_generate_call_gemini(
		$system_prompt,
		$contents,
		$temperature,
		$api_key,
		$model
	);

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'ai_error',
			$result->get_error_message(),
			array( 'status' => 502 )
		);
	}

	$cleaned   = ekwa_ai_generate_strip_fences( $result['content'] );
	$extracted = ekwa_ai_generate_extract_css_js( $cleaned );

	return rest_ensure_response( array(
		'html'          => $extracted['html'],
		'extracted_css' => $extracted['css'],
		'extracted_js'  => $extracted['js'],
	) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROMPT
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Build the system prompt that biases Gemini toward converter-friendly HTML.
 *
 * @param string $context One of: 'header', 'footer', 'page'. Drives the
 *                        BLOCK MARKUP HINTS section appended to the prompt
 *                        and a leading context cue for header/footer modes.
 * @return string
 */
function ekwa_ai_generate_build_system_prompt( $context = 'page' ) {
	$context_cue = '';
	if ( $context === 'header' ) {
		$context_cue = "HEADER CONTEXT — strict rules for this generation:\n"
			. "1. DESKTOP ONLY. The site has a separate mobile header, so this fragment must hide itself below 1200px viewport width. Wrap the header in a class you control and add this CSS to your <style> block: `@media (max-width: 1199.98px) { .your-header-class { display: none !important; } }`.\n"
			. "2. DO NOT generate any mobile version — no hamburger button, no off-canvas drawer, no mobile menu toggle, no mobile-specific markup. Assume the viewport is ≥ 1200px and design accordingly.\n"
			. "3. EVERY dynamic element the user mentions (logo, navigation/menu, phone, search, address, dropdowns, social) MUST appear in the output using the signature pattern from the BLOCK MARKUP HINTS section below. If the user said \"menu\" or \"navigation\", you MUST emit a <div class=\"ekwa-header-menu-wrap\">…</div>. Do not silently drop requested elements; they are required, not optional.\n"
			. "4. Keep markup compact — no full-page hero sections, no body-content shapes. This is a header bar only.\n\n";
	} elseif ( $context === 'footer' ) {
		$context_cue = "FOOTER CONTEXT — strict rules for this generation:\n"
			. "1. EVERY dynamic element the user mentions (address, hours, social, map, copyright, navigation, phone) MUST appear using the signature pattern from the BLOCK MARKUP HINTS section below. Do not silently drop requested elements.\n"
			. "2. Typical footer content: address, hours, social row, map embed, footer nav, copyright line. Use the BLOCK MARKUP HINTS section for each.\n\n";
	}

	$prompt = <<<PROMPT
You are an expert front-end developer producing static HTML for a WordPress block theme. Your output will be split: the HTML is passed to a deterministic HTML→Gutenberg block converter, while any <style> and <script> blocks are extracted and given to the user to paste into their site-wide CSS/JS files.

OUTPUT RULES:
- Output a single HTML document fragment. No prose, no explanations, no Markdown code fences.
- Do NOT emit <html>, <head>, <body>, <meta>, or <link> wrappers — fragment only.
- Do NOT invent filenames that look like real assets; use https://placehold.co/WIDTHxHEIGHT for image placeholders, or honor any image URLs supplied by the user.

CSS — YOU HAVE FULL FREEDOM:
- Use <style>...</style> blocks for anything that needs media queries, hover/focus states, pseudo-elements, keyframes, complex selectors, or shared rules across multiple elements. Place <style> blocks at the top of your output.
- Use inline style="..." attributes only for one-off, element-specific styling.
- Use semantic class names (BEM-style or descriptive) so the rules in <style> can target them.
- COLORS & DESIGN TOKENS: When a SITE STYLESHEET is provided below, reuse its existing CSS custom properties for colors (and for fonts/spacing/radii where they fit) — e.g. `color: var(--brand-primary)`. Do NOT hardcode hex/rgb values that duplicate an existing variable, and do NOT redefine color variables that the site stylesheet already declares. Only add a new variable when no suitable one exists.
- NEVER REFERENCE A CLASS YOU DID NOT DEFINE. You may reuse a class from the SITE STYLESHEET only if that exact class appears there — check before using it. If you want a variant the stylesheet does not have (e.g. it defines `.btn--ghost` and you want `.btn--ghost-light`), you MUST write the rule for it in your own <style>. A class that is neither in the site stylesheet nor in your <style> produces an element with no styling at all: this is the single most common way a generated section looks right in preview and broken once it is on the page.
- The same applies to fonts: only use a font variable the site stylesheet actually declares. If you name a font family directly, the site has no way to load it — prefer the existing variables.
- The user will paste extracted CSS into a shared stylesheet, so feel free to use as much or as little CSS as the design needs.

JS — ALSO FREE:
- If the design needs interactivity, use <script>...</script> blocks. They will be extracted and shown to the user separately to paste into their site JS file.
- Avoid inline on*= event handler attributes; prefer addEventListener inside a <script> block.

PREFERRED HTML PATTERNS (the converter understands these best):
- Top-level sections: <section class="...">
- Centered max-width wrappers: <div class="container"> with width controlled in CSS
- Flex rows/columns and grids — use either CSS classes with rules in <style>, or inline styles for simple cases
- Headings: <h1>..<h6>
- Paragraphs: <p>
- Lists: <ul> / <ol> with <li>
- Images: <img src="..." alt="..." width="..." height="...">
- Phone links: <a href="tel:+15551234567"><i class="fa-solid fa-phone"></i> (555) 123-4567</a>
  When a phone sits in a button/CTA row, give the <a> the SAME classes as the buttons next to it (e.g. class="btn btn-outline") and write matching rules in <style>. The converter preserves that element verbatim, so classes are what keeps it styled after conversion; an unclassed tel: link is re-rendered with plain theme markup and will look unstyled beside a styled button.
- Email links: <a href="mailto:hello@example.com">hello@example.com</a>
- Map links: <a href="https://www.google.com/maps/...">Get Directions</a>
- Icons: <i class="fa-solid fa-..."></i> (Font Awesome class names)
- Anchors wrapping cards are fine

CONTENT RULES:
- Use the user's prompt as the source of truth for copy. If the user supplies real text/content, use it verbatim. If not, write plausible placeholder content matching the section type.
- If the user attaches images, use them however the prompt instructs. They may be layout references, error screenshots showing a bug to fix, source content to transcribe, or something else entirely — do not assume a fixed role. Defer to the prompt for interpretation.
- If the user supplies reference HTML, treat it as a starting point to transform per their instructions.

Return only the HTML (with any <style>/<script> blocks inside it), nothing else.
PROMPT;

	if ( function_exists( 'ekwa_ai_build_hints_section' ) ) {
		$prompt .= ekwa_ai_build_hints_section( $context );
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
 * The CSS the front end prints in <head>, assembled for an off-page preview.
 *
 * The generator is told to reuse the site's design tokens (`var(--brand-…)`)
 * and the mockup's shared component rules (`.btn`, body typography, resets) —
 * but none of that lives in the child style.css. It is echoed into <head> by
 * ekwa-tokens.php and ekwa-fonts.php, so a preview iframe that loads only the
 * stylesheet gets undefined variables and no base rules, and renders unstyled
 * while the same markup looks correct on the front end.
 *
 * Concatenated in wp_head priority order so the cascade matches the real page:
 * tokens (3) → mockup sheet (4) → fonts (5) → legacy global pool (6).
 *
 * @return string
 */
function ekwa_ai_generate_preview_head_css() {
	$parts = array();

	if ( function_exists( 'ekwa_tokens_root_css' ) ) {
		$parts[] = ekwa_tokens_root_css();
	}

	$legacy = function_exists( 'ekwa_tokens_legacy_mode' ) && ekwa_tokens_legacy_mode();

	if ( ! $legacy && function_exists( 'ekwa_tokens_printable_mockup_css' ) ) {
		$parts[] = ekwa_tokens_printable_mockup_css();
	}

	if ( function_exists( 'ekwa_fonts_build_css' ) ) {
		$parts[] = ekwa_fonts_build_css();
	}

	if ( $legacy && function_exists( 'ekwa_tokens_global_css' ) ) {
		$parts[] = ekwa_tokens_global_css();
	}

	$css = trim( implode( "\n\n", array_filter( array_map( 'trim', $parts ) ) ) );

	/**
	 * Filter the stylesheet handed to the AI generator's preview iframe.
	 *
	 * @param string $css Concatenated head CSS.
	 */
	return apply_filters( 'ekwa_ai_preview_head_css', $css );
}

/**
 * Load the active child theme's stylesheet (if any) and return it as an
 * appended block of context for the system prompt. The AI is told it can
 * reuse classes, CSS variables, and utility patterns from this stylesheet.
 *
 * Capped at ~80 KB so we don't blow the request budget; truncated with a
 * marker if larger.
 *
 * @return string Empty string when no child stylesheet is available.
 */
function ekwa_ai_generate_child_stylesheet_context() {
	$css    = '';
	$source = '';

	// Preferred source: the saved mockup stylesheet (Design Tokens tab) — the
	// workflow goal is to not use the child style.css at all.
	if ( function_exists( 'ekwa_tokens_mockup_css' ) ) {
		$css = trim( ekwa_tokens_mockup_css() );
		if ( '' !== $css ) {
			$source = 'saved mockup stylesheet';
		}
	}

	// Legacy fallback: the active child theme's style.css.
	if ( '' === $css ) {
		$parent_dir = get_template_directory();
		$child_dir  = get_stylesheet_directory();
		if ( $parent_dir === $child_dir ) {
			return '';
		}
		$path = $child_dir . '/style.css';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$file_css = file_get_contents( $path );
		if ( false === $file_css || '' === trim( $file_css ) ) {
			return '';
		}
		$css    = $file_css;
		$source = 'child theme';
	}

	$max = 80000;
	if ( strlen( $css ) > $max ) {
		$css = substr( $css, 0, $max ) . "\n\n/* …truncated for prompt budget */";
	}

	return "\n\n---\n\nSITE STYLESHEET (" . $source . " — reuse these classes, CSS variables, and patterns when they fit the design):\n\n```css\n" . $css . "\n```\n";
}

/**
 * Build the multimodal `contents` array for the Gemini request.
 *
 * History entries are previous turns from a multi-turn conversation:
 *   { role: 'user', text: string, images?: [{mime,data_base64}] }
 *   { role: 'model', html: string, css?: string, js?: string }
 * Model turns are reconstructed back into a single text block (with any
 * <style>/<script> from prior outputs) so the model has full context of
 * what it previously produced — including any user edits the frontend
 * applied before sending the next turn.
 *
 * @param string $prompt  Current user prompt text.
 * @param array  $images  Current user images.
 * @param array  $history Prior conversation turns.
 * @return array|WP_Error Gemini contents array, or error.
 */
function ekwa_ai_generate_build_contents( $prompt, $images, $history = array() ) {
	$contents = array();

	foreach ( $history as $turn ) {
		if ( ! is_array( $turn ) ) {
			continue;
		}
		$role = isset( $turn['role'] ) ? (string) $turn['role'] : '';

		if ( 'user' === $role ) {
			$user_parts = array();
			$text = isset( $turn['text'] ) ? (string) $turn['text'] : '';
			if ( '' !== $text ) {
				$user_parts[] = array( 'text' => $text );
			}
			$turn_images = isset( $turn['images'] ) && is_array( $turn['images'] ) ? $turn['images'] : array();
			foreach ( $turn_images as $img ) {
				$image_part = ekwa_ai_generate_image_part( $img );
				if ( is_wp_error( $image_part ) ) {
					return $image_part;
				}
				if ( $image_part ) {
					$user_parts[] = $image_part;
				}
			}
			if ( $user_parts ) {
				$contents[] = array( 'role' => 'user', 'parts' => $user_parts );
			}
		} elseif ( 'model' === $role ) {
			$reconstructed = '';
			$css = isset( $turn['css'] ) ? trim( (string) $turn['css'] ) : '';
			$html = isset( $turn['html'] ) ? trim( (string) $turn['html'] ) : '';
			$js = isset( $turn['js'] ) ? trim( (string) $turn['js'] ) : '';
			if ( '' !== $css ) {
				$reconstructed .= "<style>\n" . $css . "\n</style>\n";
			}
			if ( '' !== $html ) {
				$reconstructed .= $html;
			}
			if ( '' !== $js ) {
				$reconstructed .= "\n<script>\n" . $js . "\n</script>";
			}
			if ( '' !== $reconstructed ) {
				$contents[] = array(
					'role'  => 'model',
					'parts' => array( array( 'text' => $reconstructed ) ),
				);
			}
		}
	}

	// Current turn (always user).
	$current_parts = array( array( 'text' => $prompt ) );
	foreach ( $images as $img ) {
		$image_part = ekwa_ai_generate_image_part( $img );
		if ( is_wp_error( $image_part ) ) {
			return $image_part;
		}
		if ( $image_part ) {
			$current_parts[] = $image_part;
		}
	}
	$contents[] = array( 'role' => 'user', 'parts' => $current_parts );

	return $contents;
}

/**
 * Validate one image entry and return its Gemini inline_data part.
 *
 * @param mixed $img Should be { mime: string, data_base64: string }.
 * @return array|WP_Error|null Part array, error on bad mime, or null when entry is empty/invalid.
 */
function ekwa_ai_generate_image_part( $img ) {
	if ( ! is_array( $img ) ) {
		return null;
	}
	$mime = isset( $img['mime'] ) ? (string) $img['mime'] : '';
	$data = isset( $img['data_base64'] ) ? (string) $img['data_base64'] : '';
	if ( '' === $mime || '' === $data ) {
		return null;
	}
	if ( ! preg_match( '#^image/(png|jpe?g|webp|gif)$#i', $mime ) ) {
		return new WP_Error(
			'bad_image_mime',
			'Unsupported image type: ' . $mime,
			array( 'status' => 400 )
		);
	}
	return array(
		'inline_data' => array(
			'mime_type' => $mime,
			'data'      => $data,
		),
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// GEMINI API
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Call the Gemini API with a multimodal contents array.
 *
 * @param string   $system_prompt     System instruction.
 * @param array    $contents          Pre-built `contents` array.
 * @param float    $temperature       Sampling temperature.
 * @param string   $api_key           Gemini API key.
 * @param string   $model             Gemini model id. Empty resolves to the
 *                                    current most-capable model.
 * @param int      $max_output_tokens Output-token cap. Raise it for tasks that
 *                                    must echo large input back (e.g. the CSS
 *                                    splitter returns the whole leftover pool);
 *                                    leaving it too low silently TRUNCATES the
 *                                    response, which callers must then detect via
 *                                    the returned finish_reason.
 * @param int|null $thinking_budget   When set, caps the model's "thinking" tokens
 *                                    (0 disables thinking entirely). Thinking is
 *                                    drawn from the SAME output budget, so for
 *                                    mechanical, deterministic tasks passing 0
 *                                    frees the whole budget for the actual answer
 *                                    and removes a source of intermittent
 *                                    truncation/empty responses on the 2.5 models.
 * @return array|WP_Error { content: string, tokens: int, finish_reason: string } or error.
 */
function ekwa_ai_generate_call_gemini( $system_prompt, $contents, $temperature, $api_key, $model = '', $max_output_tokens = 16384, $thinking_budget = null ) {
	if ( '' === trim( (string) $model ) ) {
		$model = ekwa_ai_default_model();
	}

	$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . urlencode( $api_key );

	$generation_config = array(
		'temperature'     => $temperature,
		'maxOutputTokens' => (int) $max_output_tokens,
	);
	if ( null !== $thinking_budget ) {
		$generation_config['thinkingConfig'] = array( 'thinkingBudget' => (int) $thinking_budget );
	}

	$body = array(
		'system_instruction' => array(
			'parts' => array(
				array( 'text' => $system_prompt ),
			),
		),
		'contents'         => $contents,
		'generationConfig' => $generation_config,
	);

	$response = wp_remote_post( $url, array(
		'headers' => array(
			'Content-Type' => 'application/json',
		),
		'body'    => wp_json_encode( $body ),
		'timeout' => 120,
	) );

	// Concise label for the diagnostic log so intermittent failures (rate limits,
	// truncation, network) leave a trail in the PHP error log instead of vanishing
	// into a returned WP_Error the user may or may not read.
	$feature_label = function_exists( 'ekwa_ai_current_feature' ) ? ekwa_ai_current_feature() : 'ai';

	if ( is_wp_error( $response ) ) {
		error_log( sprintf( '[ekwa-ai] %s (%s) — network error: %s', $feature_label, $model, $response->get_error_message() ) );
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code ) {
		$msg    = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Gemini API error (HTTP ' . $code . ')';
		$status = isset( $data['error']['status'] ) ? (string) $data['error']['status'] : '';
		// 429 / RESOURCE_EXHAUSTED here is Google's own quota (per-minute or
		// per-day), which is the usual cause of "works sometimes, fails others".
		error_log( sprintf( '[ekwa-ai] %s (%s) — Gemini HTTP %d %s: %s', $feature_label, $model, $code, $status, $msg ) );
		return new WP_Error( 'gemini_error', $msg );
	}

	$finish_reason = isset( $data['candidates'][0]['finishReason'] ) ? (string) $data['candidates'][0]['finishReason'] : '';
	if ( 'MAX_TOKENS' === $finish_reason ) {
		error_log( sprintf( '[ekwa-ai] %s (%s) — response truncated at MAX_TOKENS (budget %d)', $feature_label, $model, (int) $max_output_tokens ) );
	}

	$content = '';
	if ( ! empty( $data['candidates'][0]['content']['parts'] ) ) {
		foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) ) {
				$content .= $part['text'];
			}
		}
	}

	if ( '' === $content ) {
		// MAX_TOKENS with no text means the budget was spent before any answer
		// (often the 2.5 "thinking" step eating the whole allowance) — a distinct,
		// actionable failure from a genuinely empty completion.
		$msg = ( 'MAX_TOKENS' === $finish_reason )
			? 'Gemini hit its output-token limit before returning any text. Try again, raise the token budget, or shorten the input.'
			: 'Gemini returned an empty response.';
		return new WP_Error( 'gemini_empty', $msg );
	}

	// Usage accounting: every billable call bumps the caller's daily counter
	// and appends to the rolling log (inc/ekwa-ai-governance.php).
	$tokens = isset( $data['usageMetadata']['totalTokenCount'] ) ? (int) $data['usageMetadata']['totalTokenCount'] : 0;
	if ( function_exists( 'ekwa_ai_record_usage' ) ) {
		ekwa_ai_record_usage( $tokens, $feature_label, $model );
	}

	return array( 'content' => $content, 'tokens' => $tokens, 'finish_reason' => $finish_reason );
}

// ═══════════════════════════════════════════════════════════════════════════════
// RESPONSE CLEANUP
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Strip Markdown code fences and stray prose around the HTML body.
 *
 * @param string $content Raw model output.
 * @return string
 */
function ekwa_ai_generate_strip_fences( $content ) {
	$content = trim( $content );

	// Strip a leading ```html / ``` and trailing ``` if present.
	if ( preg_match( '/^```(?:html|HTML)?\s*\n(.*?)\n```\s*$/s', $content, $m ) ) {
		return trim( $m[1] );
	}

	// Looser fallback: strip any first/last fence if both exist.
	$content = preg_replace( '/^```[a-zA-Z]*\s*\n?/', '', $content );
	$content = preg_replace( '/\n?```\s*$/', '', $content );

	return trim( $content );
}

/**
 * Extract any <style> blocks and <script> blocks (plus on*= attributes) from
 * the HTML, returning them separately so the caller can show them to the user
 * without inserting them into the converted blocks.
 *
 * Done with regex (not DOMDocument) so we can preserve original whitespace and
 * avoid DOM normalization changing the user-visible HTML preview.
 *
 * @param string $html
 * @return array { html: string, css: string, js: string }
 */
function ekwa_ai_generate_extract_css_js( $html ) {
	$css_chunks = array();
	$js_chunks  = array();

	// Extract <style>...</style>.
	$html = preg_replace_callback(
		'#<style\b[^>]*>(.*?)</style\s*>#is',
		function ( $m ) use ( &$css_chunks ) {
			$css_chunks[] = trim( $m[1] );
			return '';
		},
		$html
	);

	// Extract <script>...</script> (with or without src).
	$html = preg_replace_callback(
		'#<script\b[^>]*>(.*?)</script\s*>#is',
		function ( $m ) use ( &$js_chunks ) {
			$body = trim( $m[1] );
			if ( '' !== $body ) {
				$js_chunks[] = $body;
			} else {
				// External script — capture the src so the user sees what was requested.
				if ( preg_match( '/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $m[0], $sm ) ) {
					$js_chunks[] = '// external: ' . $sm[1];
				}
			}
			return '';
		},
		$html
	);

	// Extract on*= attributes (onclick, onload, etc.) and strip them.
	$html = preg_replace_callback(
		'/\s+on[a-z]+\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i',
		function ( $m ) use ( &$js_chunks ) {
			$value = isset( $m[2] ) && '' !== $m[2] ? $m[2] : ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : ( isset( $m[4] ) ? $m[4] : '' ) );
			// Capture the attribute name so the JS panel is informative.
			if ( preg_match( '/\s+(on[a-z]+)\s*=/i', $m[0], $am ) ) {
				$js_chunks[] = '// ' . $am[1] . ': ' . $value;
			}
			return '';
		},
		$html
	);

	return array(
		'html' => trim( $html ),
		'css'  => implode( "\n\n", array_filter( $css_chunks ) ),
		'js'   => implode( "\n\n", array_filter( $js_chunks ) ),
	);
}
