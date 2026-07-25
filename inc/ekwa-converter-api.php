<?php
/**
 * REST API endpoint for the Mockup Converter.
 *
 * Provides POST /ekwa/v1/convert-markup so the Gutenberg editor plugin
 * can convert HTML to block markup without leaving the editor.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'ekwa_register_converter_routes' );

/**
 * Register the convert-markup REST route.
 */
function ekwa_register_converter_routes() {
	register_rest_route( 'ekwa/v1', '/convert-markup', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_rest_convert_markup',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args' => array(
			'html' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return wp_unslash( $v );
				},
			),
			'manifest' => array(
				'required' => false,
				'type'     => 'object',
				'default'  => null,
			),
			'use_server_manifest' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => true,
			),
			'detect_dynamic' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => true,
			),
			'css' => array(
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => function ( $v ) {
					return wp_unslash( $v );
				},
			),
			'css_mode' => array(
				'required' => false,
				'type'     => 'string',
				'default'  => 'extract',
				'enum'     => array( 'extract', 'child', 'scoped' ),
			),
			// AI extraction: pull just this section's rules out of the full
			// stylesheet (pasted css, or the saved mockup stylesheet when the
			// field is empty) and attach them as the wrapper's Scoped CSS.
			'css_ai_extract' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => false,
			),
		),
	) );

	// Mockup Ready Check — whole-file pre-flight analysis against the mockup
	// contract (surfaced in Ekwa Settings → Design Setup).
	register_rest_route( 'ekwa/v1', '/mockup-check', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_rest_mockup_check',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args' => array(
			'html' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => function ( $v ) {
					return wp_unslash( $v );
				},
			),
		),
	) );

	// Save selected media-library items into the server-side manifest
	// (uploads/ekwa-media-manifest.json) so conversions resolve mockup image
	// filenames automatically — replaces hand-crafting the manifest file.
	register_rest_route( 'ekwa/v1', '/mc-manifest', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_rest_mc_save_manifest',
		'permission_callback' => function () {
			return current_user_can( 'upload_files' );
		},
		'args' => array(
			'attachment_ids' => array(
				'required' => true,
				'type'     => 'array',
				'items'    => array( 'type' => 'integer' ),
			),
			'reset' => array(
				'required' => false,
				'type'     => 'boolean',
				'default'  => false,
			),
		),
	) );
}

/**
 * Handle the mockup-check REST request.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_rest_mockup_check( $request ) {
	require_once get_template_directory() . '/inc/ekwa-mockup-contract.php';

	$html = (string) $request->get_param( 'html' );
	if ( '' === trim( $html ) ) {
		return new WP_Error( 'empty_html', __( 'Paste the mockup HTML first.', 'ekwa' ), array( 'status' => 400 ) );
	}
	if ( strlen( $html ) > 3000000 ) {
		return new WP_Error( 'too_large', __( 'The file is too large to analyze.', 'ekwa' ), array( 'status' => 413 ) );
	}

	return rest_ensure_response( ekwa_mockup_readiness_check( $html ) );
}

/**
 * Handle the convert-markup REST request.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_rest_convert_markup( $request ) {
	require_once get_template_directory() . '/inc/ekwa-converter-lib.php';

	$html = $request->get_param( 'html' );

	if ( empty( trim( $html ) ) ) {
		return new WP_Error(
			'empty_html',
			'HTML markup is empty.',
			array( 'status' => 400 )
		);
	}

	// Build manifest data.
	$manifest_data = $request->get_param( 'manifest' );

	// Merge with server-side manifest if requested.
	if ( $request->get_param( 'use_server_manifest' ) ) {
		$server_manifest = ekwa_converter_load_server_manifest();
		if ( $server_manifest ) {
			if ( $manifest_data && ! empty( $manifest_data['media'] ) ) {
				// Merge: client manifest takes precedence.
				$server_manifest['media'] = array_merge(
					$server_manifest['media'],
					$manifest_data['media']
				);
				$manifest_data = $server_manifest;
			} else {
				$manifest_data = $server_manifest;
			}
		}
	}

	$options = array(
		'detect_dynamic' => $request->get_param( 'detect_dynamic' ),
	);

	$result = ekwa_mc_convert_html( $html, $manifest_data, $options );

	$response = array(
		'markup'   => $result['markup'],
		'warnings' => $result['warnings'],
		'report'   => isset( $result['report'] ) ? $result['report'] : array(),
	);

	// ── Optional mockup CSS handling ─────────────────────────────────────
	$response = ekwa_mc_apply_css_options( $request, $html, $response );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return rest_ensure_response( $response );
}

/**
 * Apply the shared CSS options (extract tokens / append-to-child / attach as
 * scoped / AI-extract) to a converter response. Used by BOTH conversion
 * endpoints — /convert-markup and /ai-convert — so the CSS workflow behaves
 * identically whichever converter produced the markup.
 *
 * @param WP_REST_Request $request  Carries css, css_mode, css_ai_extract.
 * @param string          $html     The section HTML (for AI extraction).
 * @param array           $response Response payload so far (warnings array present).
 * @return array|WP_Error Amended response payload.
 */
function ekwa_mc_apply_css_options( $request, $html, array $response ) {
	if ( ! isset( $response['warnings'] ) || ! is_array( $response['warnings'] ) ) {
		$response['warnings'] = array();
	}

	$css        = trim( (string) $request->get_param( 'css' ) );
	$css_mode   = $request->get_param( 'css_mode' );
	$ai_extract = (bool) $request->get_param( 'css_ai_extract' );

	if ( $ai_extract ) {
		// Thinning-pool model: with no CSS pasted, the source is the site-wide
		// Global CSS pool (seeded from the mockup stylesheet in Design Setup). The
		// AI splits it into this section's rules (scoped → attached to the wrapper)
		// and the leftover, which REPLACES the pool — so the <head> stylesheet
		// gets thinner section by section, and shared/base CSS that no section
		// claims (body font, resets, generic buttons) stays global.
		//
		// Pasting CSS in the modal is a one-off override: it's split for the scoped
		// result but never rewrites the shared pool.
		$pasted     = '' !== $css;
		$source_css = $pasted
			? $css
			: ( function_exists( 'ekwa_tokens_global_css' ) ? trim( ekwa_tokens_global_css() ) : '' );

		// Pool not seeded yet (e.g. an install predating this feature) — fall back
		// to the saved mockup stylesheet minus its variables, so the first
		// extraction works and seeds the pool from its leftover.
		if ( '' === $source_css && ! $pasted && function_exists( 'ekwa_tokens_mockup_css' ) && function_exists( 'ekwa_tokens_strip_css_variables' ) ) {
			$mock = trim( ekwa_tokens_mockup_css() );
			if ( '' !== $mock ) {
				$source_css = ekwa_tokens_strip_css_variables( $mock );
			}
		}

		if ( '' === $source_css ) {
			$response['warnings'][] = $pasted
				? __( 'AI CSS extraction skipped — the pasted CSS was empty.', 'ekwa' )
				: __( 'AI CSS extraction skipped — save your mockup stylesheet in Ekwa Settings → Design Setup first to seed the Global CSS pool.', 'ekwa' );
		} else {
			$split = ekwa_mc_ai_split_section_css( $html, $source_css );
			if ( is_wp_error( $split ) ) {
				$response['warnings'][] = __( 'AI CSS extraction failed: ', 'ekwa' ) . $split->get_error_message();
			} else {
				$response['css_scoped']  = $split['scoped'];
				$response['css_extract'] = ekwa_mc_extract_css_tokens( $source_css );

				// Thin the shared pool with the leftover — pool path only, and only
				// for users who may edit theme-wide CSS.
				if ( ! $pasted && null !== $split['leftover'] && function_exists( 'ekwa_tokens_set_global_css' ) ) {
					if ( ! current_user_can( 'edit_theme_options' ) ) {
						$response['warnings'][] = __( 'Section CSS extracted, but only an administrator can update the site-wide Global CSS.', 'ekwa' );
					} elseif ( '' === trim( $split['leftover'] ) && '' !== trim( $source_css ) ) {
						// Safety: never empty the whole shared pool on one response —
						// an empty leftover means the model swept everything into the
						// section, which would drop the site's base CSS.
						$response['warnings'][] = __( 'Global CSS left unchanged — the AI returned no leftover for this section (that would have emptied the shared pool). Double-check this section’s Scoped CSS.', 'ekwa' );
					} else {
						ekwa_tokens_set_global_css( $split['leftover'] );
						$response['css_global_updated'] = true;
						$response['css_global_bytes']   = strlen( trim( $split['leftover'] ) );
					}
				}
			}
		}
	} elseif ( '' !== $css ) {
		// Always extract fonts + colors so the modal can surface them.
		$response['css_extract'] = ekwa_mc_extract_css_tokens( $css );

		if ( 'child' === $css_mode ) {
			$saved = ekwa_mc_append_css_to_child( $css );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$response['css_saved'] = true;
		} elseif ( 'scoped' === $css_mode ) {
			// The editor plugin attaches this to the first wrapper block's
			// scopedCss attribute after parsing the markup.
			$response['css_scoped'] = $css;
		}
	}

	return $response;
}

/**
 * AI: split a stylesheet ("the pool") into (1) the rules that specifically
 * style the given HTML section — rewritten to the site's design tokens, ready
 * to attach as the wrapper's Scoped CSS — and (2) everything else, returned
 * verbatim so it can replace the shrinking site-wide Global CSS pool.
 *
 * Billable Gemini call — gated by the AI governance permission (role gate,
 * daily cap) even though the converter route itself only needs edit_posts.
 *
 * @param string $html Section HTML being converted.
 * @param string $css  The current CSS pool to split.
 * @return array{scoped:string,leftover:?string}|WP_Error  leftover is null when
 *         the model didn't return the two-part format (pool left untouched).
 */
function ekwa_mc_ai_split_section_css( $html, $css ) {
	if ( ! function_exists( 'ekwa_ai_generate_call_gemini' ) || ! function_exists( 'ekwa_get_ai_api_key' ) ) {
		return new WP_Error( 'ai_unavailable', __( 'AI modules are not loaded.', 'ekwa' ) );
	}
	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		return new WP_Error( 'no_api_key', __( 'Gemini API key not configured (Ekwa Settings → AI).', 'ekwa' ) );
	}
	if ( function_exists( 'ekwa_ai_rest_permission' ) ) {
		$allowed = ekwa_ai_rest_permission();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
	}
	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'css-extract' );
	}

	// Budget guards.
	if ( strlen( $css ) > 150000 ) {
		$css = substr( $css, 0, 150000 ) . "\n/* …truncated */";
	}
	if ( strlen( $html ) > 60000 ) {
		$html = substr( $html, 0, 60000 ) . "\n<!-- …truncated -->";
	}

	$system = "You are a precise CSS splitter for a WordPress block theme.\n"
		. "INPUT: an HTML section, then a stylesheet (\"the pool\").\n"
		. "TASK: split the pool into TWO parts with NO overlap:\n"
		. "1) SCOPED — the rules that specifically style THIS section (matched by its classes, ids, tags and their descendants). INCLUDE the section's ::before/::after pseudo-element rules, :hover/:focus states, @media variants of those rules, and any @keyframes they reference. REWRITE values to the site's design tokens where one matches: var(--name) for colors, font-family variables for fonts, background-image variables instead of url(...) when the token represents the same image. Do NOT redeclare the token variables themselves.\n"
		. "2) LEFTOVER — EVERY other rule from the pool, returned VERBATIM (do not rewrite, reorder, merge, or drop anything). This is the shared/base layer: resets, html/body typography, bare element rules (a, img, headings, lists…), generic component rules (e.g. .btn, .container) that aren't unique to this section, utility classes, and other sections' rules. When unsure whether a rule is section-specific or shared, put it in LEFTOVER.\n"
		. "EXCLUDE from BOTH outputs: :root blocks, @font-face, and @import (those are handled elsewhere). Otherwise every rule in the pool must appear in exactly one part. Invent nothing.\n"
		. "OUTPUT EXACTLY this, the two markers each on their own line with raw CSS between — no markdown fences, no commentary:\n"
		. "===EKWA_SCOPED===\n<scoped css>\n===EKWA_LEFTOVER===\n<leftover css>";

	if ( function_exists( 'ekwa_tokens_ai_context' ) ) {
		$system .= "\n" . ekwa_tokens_ai_context();
	}

	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => "HTML SECTION:\n\n" . $html . "\n\nPOOL STYLESHEET:\n\n" . $css ),
			),
		),
	);

	$result = ekwa_ai_generate_call_gemini( $system, $contents, 0.1, $api_key, 'gemini-2.5-flash' );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$out = function_exists( 'ekwa_ai_generate_strip_fences' )
		? ekwa_ai_generate_strip_fences( $result['content'] )
		: trim( $result['content'] );

	if ( '' === trim( $out ) ) {
		return new WP_Error( 'ai_empty', __( 'The AI returned no CSS.', 'ekwa' ) );
	}

	return ekwa_mc_parse_split_css( $out );
}

/**
 * Parse the AI's two-part CSS response. Tolerant of missing/misspelled markers:
 * when the LEFTOVER marker is absent, the whole output is treated as scoped and
 * leftover is null (the caller then leaves the pool untouched).
 *
 * @param string $out Raw model output (fences already stripped).
 * @return array{scoped:string,leftover:?string}
 */
function ekwa_mc_parse_split_css( $out ) {
	$scoped_marker   = '===EKWA_SCOPED===';
	$leftover_marker = '===EKWA_LEFTOVER===';

	$has_leftover = ( false !== stripos( $out, $leftover_marker ) );
	if ( ! $has_leftover ) {
		// No split — strip a stray scoped marker if present, keep all as scoped.
		$scoped = trim( preg_replace( '/^\s*' . preg_quote( $scoped_marker, '/' ) . '\s*/i', '', $out ) );
		return array( 'scoped' => $scoped, 'leftover' => null );
	}

	$parts    = preg_split( '/' . preg_quote( $leftover_marker, '/' ) . '/i', $out, 2 );
	$scoped   = isset( $parts[0] ) ? $parts[0] : '';
	$leftover = isset( $parts[1] ) ? $parts[1] : '';

	// Drop the scoped marker from the first part.
	$scoped = preg_replace( '/^\s*' . preg_quote( $scoped_marker, '/' ) . '\s*/i', '', $scoped );

	return array(
		'scoped'   => trim( (string) $scoped ),
		'leftover' => trim( (string) $leftover ),
	);
}

/**
 * Deterministic font + color extraction from mockup CSS (no AI call).
 *
 * Fonts: first family of every font-family declaration (and --*font*
 * custom properties), minus generic/system/icon families. Colors: hex and
 * rgb()/hsl() values ranked by frequency, custom-property names attached
 * when the color is declared as one.
 *
 * @param string $css
 * @return array { fonts: string[], colors: array<{value,count,var}> }
 */
function ekwa_mc_extract_css_tokens( $css ) {
	// Strip comments so commented-out declarations don't count.
	$css = preg_replace( '/\/\*.*?\*\//s', '', (string) $css );

	$skip_families = array(
		'inherit', 'initial', 'unset', 'sans-serif', 'serif', 'monospace', 'cursive',
		'fantasy', 'system-ui', 'ui-sans-serif', 'ui-serif', 'ui-monospace',
		'-apple-system', 'blinkmacsystemfont', 'segoe ui', 'arial', 'helvetica',
		'helvetica neue', 'roboto', 'tahoma', 'verdana', 'times new roman', 'georgia',
		'courier new', 'font awesome 6 free', 'font awesome 6 brands', 'fontawesome',
	);

	$fonts = array();
	if ( preg_match_all( '/(?:font-family|--[a-z0-9-]*font[a-z0-9-]*)\s*:\s*([^;}]+)/i', $css, $m ) ) {
		foreach ( $m[1] as $stack ) {
			$first = trim( explode( ',', $stack )[0], " \t\n\r\"'" );
			if ( '' === $first || 0 === stripos( $first, 'var(' ) ) {
				continue;
			}
			if ( in_array( strtolower( $first ), $skip_families, true ) ) {
				continue;
			}
			$fonts[ $first ] = true;
		}
	}

	$colors = array();
	if ( preg_match_all( '/(?:(--[a-z0-9-]+)\s*:\s*)?(#[0-9a-f]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\))/i', $css, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $match ) {
			$value = strtolower( $match[2] );
			if ( ! isset( $colors[ $value ] ) ) {
				$colors[ $value ] = array(
					'value' => $value,
					'count' => 0,
					'var'   => '',
				);
			}
			$colors[ $value ]['count']++;
			if ( ! empty( $match[1] ) && '' === $colors[ $value ]['var'] ) {
				$colors[ $value ]['var'] = $match[1];
			}
		}
	}
	usort( $colors, function ( $a, $b ) {
		return $b['count'] - $a['count'];
	} );

	return array(
		'fonts'  => array_keys( $fonts ),
		'colors' => array_slice( array_values( $colors ), 0, 16 ),
	);
}

/**
 * Append mockup CSS to the active child theme's style.css with a dated
 * banner — replaces the manual copy-paste step of the conversion workflow.
 *
 * @param string $css
 * @return true|WP_Error
 */
function ekwa_mc_append_css_to_child( $css ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error(
			'forbidden',
			__( 'You need permission to edit themes to write the child stylesheet.', 'ekwa' ),
			array( 'status' => 403 )
		);
	}
	if ( get_template_directory() === get_stylesheet_directory() ) {
		return new WP_Error(
			'no_child_theme',
			__( 'No child theme is active — activate the child theme first, or choose "Attach to section" instead.', 'ekwa' ),
			array( 'status' => 400 )
		);
	}

	$file = get_stylesheet_directory() . '/style.css';
	if ( ! is_writable( $file ) && ! is_writable( dirname( $file ) ) ) {
		return new WP_Error(
			'not_writable',
			__( 'The child theme style.css is not writable.', 'ekwa' ),
			array( 'status' => 500 )
		);
	}

	$banner = "\n\n/* ── Imported by Ekwa Mockup Converter — " . gmdate( 'Y-m-d H:i' ) . " UTC ── */\n";
	$ok     = file_put_contents( $file, $banner . rtrim( $css ) . "\n", FILE_APPEND | LOCK_EX );

	if ( false === $ok ) {
		return new WP_Error(
			'write_failed',
			__( 'Could not write to the child theme style.css.', 'ekwa' ),
			array( 'status' => 500 )
		);
	}
	return true;
}

/**
 * Handle POST /ekwa/v1/mc-manifest — merge selected attachments into the
 * server-side media manifest used by every conversion.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_rest_mc_save_manifest( $request ) {
	$ids   = array_filter( array_map( 'absint', (array) $request->get_param( 'attachment_ids' ) ) );
	$reset = (bool) $request->get_param( 'reset' );

	if ( empty( $ids ) ) {
		return new WP_Error( 'no_attachments', 'No attachments given.', array( 'status' => 400 ) );
	}

	$upload_dir = wp_upload_dir();
	$path       = $upload_dir['basedir'] . '/ekwa-media-manifest.json';

	$manifest = array(
		'upload_url' => $upload_dir['baseurl'],
		'media'      => array(),
	);
	if ( ! $reset && file_exists( $path ) ) {
		$existing = json_decode( file_get_contents( $path ), true );
		if ( is_array( $existing ) && ! empty( $existing['media'] ) ) {
			$manifest['media'] = $existing['media'];
		}
	}

	// Key by lowercased filename so re-imports overwrite instead of duplicate.
	$by_name = array();
	foreach ( $manifest['media'] as $item ) {
		if ( ! empty( $item['filename'] ) ) {
			$by_name[ strtolower( $item['filename'] ) ] = $item;
		}
	}

	$added = 0;
	foreach ( $ids as $id ) {
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			continue;
		}
		$meta  = wp_get_attachment_metadata( $id );
		$entry = array(
			'filename' => strtolower( basename( get_attached_file( $id ) ?: $url ) ),
			'url'      => $url,
			'id'       => $id,
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
		);
		$by_name[ $entry['filename'] ] = $entry;
		$added++;
	}

	$manifest['media'] = array_values( $by_name );

	$ok = file_put_contents( $path, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
	if ( false === $ok ) {
		return new WP_Error( 'write_failed', 'Could not write the manifest file.', array( 'status' => 500 ) );
	}

	return rest_ensure_response( array(
		'saved' => $added,
		'total' => count( $manifest['media'] ),
	) );
}

/**
 * Load the server-side media manifest from wp-content/uploads.
 *
 * @return array|null Parsed manifest data or null if not found.
 */
function ekwa_converter_load_server_manifest() {
	$upload_dir = wp_upload_dir();
	$manifest_path = $upload_dir['basedir'] . '/ekwa-media-manifest.json';

	if ( ! file_exists( $manifest_path ) ) {
		return null;
	}

	$data = json_decode( file_get_contents( $manifest_path ), true );

	if ( ! $data || ! is_array( $data ) ) {
		return null;
	}

	// Ensure upload_url is set.
	if ( empty( $data['upload_url'] ) ) {
		$data['upload_url'] = $upload_dir['baseurl'];
	}

	return $data;
}
