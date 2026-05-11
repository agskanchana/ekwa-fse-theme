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
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
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
		),
	) );
}

/**
 * Handle the AI HTML generation REST request.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_ai_generate_handle_request( $request ) {
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

	$system_prompt = ekwa_ai_generate_build_system_prompt();
	if ( $use_child_css ) {
		$system_prompt .= ekwa_ai_generate_child_stylesheet_context();
	}

	$result = ekwa_ai_generate_call_gemini(
		$system_prompt,
		$contents,
		$temperature,
		$api_key
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
 * @return string
 */
function ekwa_ai_generate_build_system_prompt() {
	return <<<PROMPT
You are an expert front-end developer producing static HTML for a WordPress block theme. Your output will be split: the HTML is passed to a deterministic HTML→Gutenberg block converter, while any <style> and <script> blocks are extracted and given to the user to paste into their site-wide CSS/JS files.

OUTPUT RULES:
- Output a single HTML document fragment. No prose, no explanations, no Markdown code fences.
- Do NOT emit <html>, <head>, <body>, <meta>, or <link> wrappers — fragment only.
- Do NOT invent filenames that look like real assets; use https://placehold.co/WIDTHxHEIGHT for image placeholders, or honor any image URLs supplied by the user.

CSS — YOU HAVE FULL FREEDOM:
- Use <style>...</style> blocks for anything that needs media queries, hover/focus states, pseudo-elements, keyframes, complex selectors, or shared rules across multiple elements. Place <style> blocks at the top of your output.
- Use inline style="..." attributes only for one-off, element-specific styling.
- Use semantic class names (BEM-style or descriptive) so the rules in <style> can target them.
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
	// Only meaningful when a child theme is active (parent != stylesheet).
	$parent_dir = get_template_directory();
	$child_dir  = get_stylesheet_directory();
	if ( $parent_dir === $child_dir ) {
		return '';
	}

	$path = $child_dir . '/style.css';
	if ( ! is_readable( $path ) ) {
		return '';
	}

	$css = file_get_contents( $path );
	if ( false === $css || '' === trim( $css ) ) {
		return '';
	}

	$max = 80000;
	if ( strlen( $css ) > $max ) {
		$css = substr( $css, 0, $max ) . "\n\n/* …truncated for prompt budget */";
	}

	return "\n\n---\n\nSITE STYLESHEET (child theme — reuse these classes, CSS variables, and patterns when they fit the design):\n\n```css\n" . $css . "\n```\n";
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
 * @param string $system_prompt System instruction.
 * @param array  $contents      Pre-built `contents` array.
 * @param float  $temperature   Sampling temperature.
 * @param string $api_key       Gemini API key.
 * @return array|WP_Error { content: string } or error.
 */
function ekwa_ai_generate_call_gemini( $system_prompt, $contents, $temperature, $api_key ) {
	$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode( $api_key );

	$body = array(
		'system_instruction' => array(
			'parts' => array(
				array( 'text' => $system_prompt ),
			),
		),
		'contents'         => $contents,
		'generationConfig' => array(
			'temperature'     => $temperature,
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

	if ( 200 !== $code ) {
		$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Gemini API error (HTTP ' . $code . ')';
		return new WP_Error( 'gemini_error', $msg );
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
		return new WP_Error( 'gemini_empty', 'Gemini returned an empty response.' );
	}

	return array( 'content' => $content );
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
