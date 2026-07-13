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
		),
	) );
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

EVERYTHING ELSE — structure:
- Containers (<div>, <section>, <header>, <footer>, <nav>, <main>, <aside>) → ekwa/div with {"tagName":"…"} and {"className":"…"} copied VERBATIM from the source (so the mockup CSS still targets them). Keep the nesting exactly.
- Headings <h1>–<h6> → core/heading with the matching level. Paragraphs → core/paragraph. Lists <ul>/<ol> → core/list. Copy the inner text/HTML exactly.
- <img> → ekwa/image with src, alt, width, height.
- A link or button that is a call-to-action (has a button class like .btn/.b/.button, or is an obvious CTA) → ekwa/button {"text":"…","url":"…"} (add {"variant":"outline"} for outline styles). A plain text link → ekwa/link {"text":"…","url":"…"}. Preserve href exactly.
- A Font Awesome icon (<i class="fa…">) → ekwa/icon {"iconClass":"…"}.

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

	$system   = ekwa_ai_convert_system_prompt();
	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => "Convert this HTML into Ekwa block markup, following every rule above:\n\n" . $html ),
			),
		),
	);

	$result = ekwa_ai_generate_call_gemini( $system, $contents, 0.1, $api_key, $model );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'ai_error', $result->get_error_message(), array( 'status' => 502 ) );
	}

	// Same post-processing as the Block Builder: strip fences, drop any stray
	// CSS/JS the model emitted, repair attribute JSON, self-correct once.
	$cleaned      = ekwa_ai_generate_strip_fences( $result['content'] );
	$extracted    = ekwa_ai_generate_extract_css_js( $cleaned );
	$block_markup = trim( $extracted['html'] );
	$warnings     = array();

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

	$warnings      = array_merge( $warnings, ekwa_ai_generate_blocks_validate( $block_markup ) );
	$rendered_html = ekwa_ai_generate_blocks_render_preview( $block_markup );

	return rest_ensure_response( array(
		'markup'        => $block_markup,
		'rendered_html' => $rendered_html,
		'warnings'      => $warnings,
	) );
}
