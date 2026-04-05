<?php
/**
 * AI Refinement for Mockup Converter — Gemini API integration.
 *
 * Provides POST /ekwa/v1/ai-refine-markup that sends the deterministic
 * converter's output to Google Gemini for improvement.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'ekwa_register_ai_refine_routes' );

/**
 * Register the AI refinement REST route.
 */
function ekwa_register_ai_refine_routes() {
	register_rest_route( 'ekwa/v1', '/ai-refine-markup', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_rest_ai_refine_markup',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args' => array(
			'html' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'markup' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) { return wp_unslash( $v ); },
			),
			'warnings' => array(
				'required' => false,
				'type'     => 'array',
				'default'  => array(),
			),
		),
	) );
}

/**
 * Handle the AI refinement REST request.
 */
function ekwa_rest_ai_refine_markup( $request ) {
	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return new WP_Error(
			'no_api_key',
			'Gemini API key not configured. Add EKWA_GEMINI_API_KEY to wp-config.php or set it in Ekwa Settings.',
			array( 'status' => 400 )
		);
	}

	$html     = $request->get_param( 'html' );
	$markup   = $request->get_param( 'markup' );
	$warnings = $request->get_param( 'warnings' );

	// Build prompts.
	$system_prompt = ekwa_ai_build_system_prompt();
	$user_prompt   = ekwa_ai_build_user_prompt( $html, $markup, $warnings );

	// Call Gemini API.
	$result = ekwa_ai_call_gemini( $system_prompt, $user_prompt, $api_key );

	if ( is_wp_error( $result ) ) {
		return new WP_Error(
			'ai_error',
			$result->get_error_message(),
			array( 'status' => 502 )
		);
	}

	// Parse the response.
	$parsed = ekwa_ai_parse_response( $result['content'] );

	return rest_ensure_response( array(
		'refined_markup'  => $parsed['markup'],
		'ai_notes'        => $parsed['notes'],
		'original_markup' => $markup,
	) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// API KEY
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Get the Gemini API key from wp-config constant or theme option.
 */
function ekwa_get_ai_api_key() {
	if ( defined( 'EKWA_GEMINI_API_KEY' ) && EKWA_GEMINI_API_KEY ) {
		return EKWA_GEMINI_API_KEY;
	}
	$key = get_option( 'ekwa_gemini_api_key', '' );
	return $key ? $key : false;
}

// ═══════════════════════════════════════════════════════════════════════════════
// BLOCK CATALOGUE
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Build a compact block catalogue from all block.json files.
 */
function ekwa_ai_build_block_catalogue() {
	$blocks_dir = get_template_directory() . '/blocks';
	$files      = glob( $blocks_dir . '/*/block.json' );
	$catalogue  = '';

	foreach ( $files as $file ) {
		$block = json_decode( file_get_contents( $file ), true );
		if ( ! $block ) {
			continue;
		}

		$name  = $block['name'] ?? '';
		$desc  = $block['description'] ?? '';
		$attrs = $block['attributes'] ?? array();

		$attr_parts = array();
		foreach ( $attrs as $key => $def ) {
			$type    = $def['type'] ?? '?';
			$default = isset( $def['default'] ) ? json_encode( $def['default'] ) : 'none';
			$attr_parts[] = "$key($type, default=$default)";
		}

		$catalogue .= "- $name";
		if ( $desc ) {
			$catalogue .= " — $desc";
		}
		$catalogue .= "\n";
		if ( $attr_parts ) {
			$catalogue .= '  Attrs: ' . implode( ', ', $attr_parts ) . "\n";
		}
	}

	// Add core blocks used by the converter.
	$catalogue .= "\nCore WordPress blocks also used:\n";
	$catalogue .= "- core/heading — level(number), className(string)\n";
	$catalogue .= "- core/paragraph — className(string)\n";
	$catalogue .= "- core/list — ordered(boolean), className(string)\n";
	$catalogue .= "- core/separator — no attrs\n";
	$catalogue .= "- core/html — raw HTML passthrough, no attrs\n";
	$catalogue .= "- core/navigation — wraps core/navigation-link children\n";
	$catalogue .= "- core/navigation-link — label(string), url(string)\n";
	$catalogue .= "- core/site-logo — width(number)\n";

	return $catalogue;
}

// ═══════════════════════════════════════════════════════════════════════════════
// PROMPTS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Build the system prompt.
 */
function ekwa_ai_build_system_prompt() {
	$catalogue = ekwa_ai_build_block_catalogue();

	return <<<PROMPT
You are a WordPress block markup expert for the Ekwa FSE theme. You receive an original HTML mockup, a first-pass deterministic conversion to WordPress block markup, and warnings from the converter. Your job is to improve the converted block markup.

OUTPUT FORMAT:
- Return ONLY valid WordPress block markup inside <markup>...</markup> tags
- Return a brief list of changes inside <notes>...</notes> tags
- Do NOT include any other text outside these tags

AVAILABLE BLOCKS:
$catalogue

CONVERSION RULES:

1. LAYOUT HIERARCHY (typical nesting):
   - ekwa/section for top-level semantic sections (full-width, may have background image/overlay)
   - ekwa/container for max-width centered wrappers
   - ekwa/flex for flexbox row/column layouts
   - ekwa/grid for uniform column layouts
   - ekwa/div for generic wrappers, semantic tags (header, footer, main, aside, article), or tagName="a" for clickable card wrappers

2. DYNAMIC DATA BLOCKS (replace hardcoded content):
   - Phone links (<a href="tel:...">) → ekwa/phone with type="new" or "existing", set iconClass from the <i> in the mockup
   - Google Maps iframes → ekwa/map with embedCode containing the iframe HTML
   - Social icon groups (2+ social media links) → ekwa/social
   - Working hours (structured day+time data) → ekwa/hours
   - Copyright text (© + year + practice name) → ekwa/copyright
   - Logo images in header → core/site-logo (NOT in footer — footer logos stay as ekwa/image)
   - Navigation menus (<nav> with links) → core/navigation with navigation-link children
   - Map direction links (maps.google.com) → ekwa/address

3. CONTENT BLOCKS:
   - Headings → core/heading with correct level
   - Paragraphs → core/paragraph (preserve inner HTML like <br>, <strong>, <a>)
   - Lists → core/list
   - Images → ekwa/image (self-closing: <!-- wp:ekwa/image {"src":"...","alt":"..."} /-->)
   - Videos → ekwa/video (self-closing, with src, poster, autoplay, loop, muted, playsinline)
   - Standalone FA icons (<i class="fa-...">) → ekwa/icon with wrapperClass=""
   - Inline text (span, small, strong, em with text only) → ekwa/text
   - Text-only anchors → ekwa/link
   - Anchors with children (img + div + text) → ekwa/div with tagName="a" and href
   - Buttons → ekwa/link or keep as-is

4. DO NOT:
   - Invent content not in the original HTML
   - Remove elements that exist in the original HTML
   - Change media URLs — keep src values exactly as in the first-pass markup
   - Use core/group, core/columns, core/cover, or core/buttons — use ekwa equivalents
   - Add unnecessary extra wrapper blocks

5. PRESERVE:
   - All className attributes from the first-pass
   - All media src/alt/width/height values
   - Block comment syntax: <!-- wp:block-name {"attr":"val"} --> and <!-- /wp:block-name -->
   - Self-closing blocks: <!-- wp:block-name {"attr":"val"} /-->
   - 2-space indentation per depth level
   - backgroundImage attribute on ekwa/div blocks
   - inlineStyle attribute for non-background CSS
PROMPT;
}

/**
 * Build the user prompt with conversion context.
 */
function ekwa_ai_build_user_prompt( $original_html, $converted_markup, $warnings ) {
	$warnings_text = '';
	if ( ! empty( $warnings ) ) {
		$warnings_text = "## Converter Warnings:\n";
		foreach ( $warnings as $w ) {
			$warnings_text .= "- $w\n";
		}
	}

	// Truncate if too large (keep under ~60k chars to stay within token limits).
	$max_chars = 60000;
	$total     = strlen( $original_html ) + strlen( $converted_markup );
	if ( $total > $max_chars ) {
		$half = (int) ( $max_chars / 2 );
		if ( strlen( $converted_markup ) > $half ) {
			$converted_markup = substr( $converted_markup, 0, $half ) . "\n<!-- TRUNCATED -->";
		}
		$remaining = $max_chars - strlen( $converted_markup );
		if ( strlen( $original_html ) > $remaining ) {
			$original_html = substr( $original_html, 0, $remaining ) . "\n<!-- TRUNCATED -->";
		}
	}

	return <<<PROMPT
## Original HTML Mockup:
<html_mockup>
$original_html
</html_mockup>

## First-Pass Converted Markup:
<first_pass>
$converted_markup
</first_pass>

$warnings_text

Please refine the first-pass markup. Fix any conversion errors, improve block type choices, and ensure dynamic data blocks are used where appropriate.
PROMPT;
}

// ═══════════════════════════════════════════════════════════════════════════════
// GEMINI API
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Call the Google Gemini API.
 *
 * @param string $system_prompt System instruction.
 * @param string $user_prompt   User message.
 * @param string $api_key       Gemini API key.
 * @return array|WP_Error { content: string } or error.
 */
function ekwa_ai_call_gemini( $system_prompt, $user_prompt, $api_key ) {
	$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode( $api_key );

	$body = array(
		'system_instruction' => array(
			'parts' => array(
				array( 'text' => $system_prompt ),
			),
		),
		'contents' => array(
			array(
				'parts' => array(
					array( 'text' => $user_prompt ),
				),
			),
		),
		'generationConfig' => array(
			'temperature'     => 0,
			'maxOutputTokens' => 16384,
		),
	);

	$response = wp_remote_post( $url, array(
		'headers' => array(
			'Content-Type' => 'application/json',
		),
		'body'    => wp_json_encode( $body ),
		'timeout' => 120,
	) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code !== 200 ) {
		$msg = $data['error']['message'] ?? 'Gemini API error (HTTP ' . $code . ')';
		return new WP_Error( 'gemini_error', $msg );
	}

	// Extract text from Gemini response.
	$content = '';
	if ( ! empty( $data['candidates'][0]['content']['parts'] ) ) {
		foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
			if ( isset( $part['text'] ) ) {
				$content .= $part['text'];
			}
		}
	}

	if ( empty( $content ) ) {
		return new WP_Error( 'gemini_empty', 'Gemini returned an empty response.' );
	}

	return array( 'content' => $content );
}

// ═══════════════════════════════════════════════════════════════════════════════
// RESPONSE PARSER
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Parse the AI response to extract markup and notes.
 */
function ekwa_ai_parse_response( $content ) {
	$markup = '';
	$notes  = array();

	// Extract <markup>...</markup>.
	if ( preg_match( '/<markup>(.*?)<\/markup>/s', $content, $m ) ) {
		$markup = trim( $m[1] );
	} else {
		// Fallback: use the entire response as markup (strip any <notes> block first).
		$markup = preg_replace( '/<notes>.*?<\/notes>/s', '', $content );
		$markup = trim( $markup );
	}

	// Extract <notes>...</notes>.
	if ( preg_match( '/<notes>(.*?)<\/notes>/s', $content, $m ) ) {
		$raw_notes = trim( $m[1] );
		// Split by newlines or bullet points.
		$lines = preg_split( '/\n/', $raw_notes );
		foreach ( $lines as $line ) {
			$line = trim( $line, " \t\n\r\0\x0B-*•" );
			if ( $line ) {
				$notes[] = $line;
			}
		}
	}

	return array(
		'markup' => $markup,
		'notes'  => $notes,
	);
}
