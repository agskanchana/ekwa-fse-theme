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
	$css        = trim( (string) $request->get_param( 'css' ) );
	$css_mode   = $request->get_param( 'css_mode' );
	$ai_extract = (bool) $request->get_param( 'css_ai_extract' );

	if ( $ai_extract ) {
		// Source: pasted CSS wins; otherwise the saved mockup stylesheet.
		$source_css = '' !== $css
			? $css
			: ( function_exists( 'ekwa_tokens_mockup_css' ) ? trim( ekwa_tokens_mockup_css() ) : '' );

		if ( '' === $source_css ) {
			$response['warnings'][] = __( 'AI CSS extraction skipped — paste the mockup CSS or save it in Ekwa Settings → Design Tokens first.', 'ekwa' );
		} else {
			$extracted_css = ekwa_mc_ai_extract_section_css( $html, $source_css );
			if ( is_wp_error( $extracted_css ) ) {
				$response['warnings'][] = __( 'AI CSS extraction failed: ', 'ekwa' ) . $extracted_css->get_error_message();
			} else {
				$response['css_scoped']  = $extracted_css;
				$response['css_extract'] = ekwa_mc_extract_css_tokens( $source_css );
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

	return rest_ensure_response( $response );
}

/**
 * AI: extract only the CSS rules that style the given HTML from a full
 * stylesheet, rewritten to use the site's design-token variables.
 *
 * Billable Gemini call — gated by the AI governance permission (role gate,
 * daily cap) even though the converter route itself only needs edit_posts.
 *
 * @param string $html Section HTML being converted.
 * @param string $css  Full mockup stylesheet.
 * @return string|WP_Error Extracted CSS.
 */
function ekwa_mc_ai_extract_section_css( $html, $css ) {
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

	$system = "You are a precise CSS extractor for a WordPress block theme.\n"
		. "INPUT: an HTML section, then a full stylesheet.\n"
		. "TASK: return ONLY the CSS rules from the stylesheet that apply to elements in the HTML section — matched by its classes, ids, tags and their descendants. INCLUDE the section's ::before/::after pseudo-element rules, :hover/:focus states, @media variants of those rules, and any @keyframes they reference. EXCLUDE resets, :root blocks, and rules for elements not present in the section.\n"
		. "REWRITE values to the site's design tokens where one matches: use var(--name) for colors, font-family variables for fonts, and background-image variables instead of url(...) when the token represents the same image. Do NOT redeclare the token variables themselves.\n"
		. "Preserve every selector exactly as written otherwise. OUTPUT raw CSS only — no markdown fences, no commentary.";

	if ( function_exists( 'ekwa_tokens_ai_context' ) ) {
		$system .= "\n" . ekwa_tokens_ai_context();
	}

	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => "HTML SECTION:\n\n" . $html . "\n\nFULL STYLESHEET:\n\n" . $css ),
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

	return $out;
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
