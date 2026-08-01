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
			// Build a real WP menu from the mockup's nav and assign it to the
			// main_menu location (which is what ekwa/header-menu renders).
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
			// Drop a single outer <header>/<footer>/<main> — the template part
			// being edited already renders that landmark.
			'drop_outer_landmark' => array(
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

	// Landmark demotion runs on the SOURCE html, before conversion, so both
	// engines see the same tree (and the menu import below still finds the nav).
	$demoted = '';
	if ( $request->get_param( 'drop_outer_landmark' ) ) {
		$landmark = ekwa_mc_demote_root_landmark( $html );
		$html     = $landmark['html'];
		$demoted  = $landmark['demoted'];
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

	if ( $demoted ) {
		$response['warnings'][] = ekwa_mc_demote_notice( $demoted );
	}

	// ── Optional mockup CSS handling ─────────────────────────────────────
	$response = ekwa_mc_apply_css_options( $request, $html, $response );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	// ── Menu import + fidelity audit (shared with /ai-convert) ───────────
	$response = ekwa_mc_apply_post_conversion( $request, $html, $response );

	return rest_ensure_response( $response );
}

/**
 * The warning shown when an outer landmark tag was demoted to a div.
 *
 * @param string $tag The demoted tag name.
 * @return string
 */
function ekwa_mc_demote_notice( $tag ) {
	return sprintf(
		/* translators: %s: the HTML tag that was demoted, e.g. "header". */
		__( 'The outer <%1$s> was converted to a <div>, keeping its classes and id — the template part you are editing already renders its own <%1$s>, and nesting them is invalid HTML (and gives assistive tech two landmarks). CSS written as ".main-header" still matches; a selector written as "%1$s.main-header" would need updating. Turn off "Drop the outer landmark tag" if you are pasting into a page instead.', 'ekwa' ),
		$tag
	);
}

/**
 * Steps that run after either converter produces markup: materialize the
 * mockup's navigation as a real WP menu, and report mockup classes that didn't
 * survive the conversion.
 *
 * @param WP_REST_Request $request  Carries import_menu / menu_replace.
 * @param string          $html     The source section HTML.
 * @param array           $response Response payload so far.
 * @return array Amended payload.
 */
function ekwa_mc_apply_post_conversion( $request, $html, array $response ) {
	if ( ! isset( $response['warnings'] ) || ! is_array( $response['warnings'] ) ) {
		$response['warnings'] = array();
	}

	// ── Build the WP menu the header block renders from. ─────────────────
	if ( $request->get_param( 'import_menu' ) && function_exists( 'ekwa_mc_menu_import_from_html' ) ) {
		$menu = ekwa_mc_menu_import_from_html( $html, (bool) $request->get_param( 'menu_replace' ) );

		if ( is_wp_error( $menu ) ) {
			$response['warnings'][] = __( 'Menu import: ', 'ekwa' ) . $menu->get_error_message();
		} elseif ( null === $menu ) {
			// Only worth mentioning when the markup actually claims a menu.
			if ( false !== stripos( (string) $response['markup'], 'wp:ekwa/header-menu' ) ) {
				$response['warnings'][] = __( 'Menu import: the header-menu block was added, but no navigation list could be read out of this HTML — build the menu under Appearance → Menus and assign it to "Main Menu".', 'ekwa' );
			}
		} else {
			$response['menu_import'] = $menu;
			$note = sprintf(
				/* translators: 1: item count, 2: menu name. */
				__( 'Menu import: created %1$d item(s) in "%2$s" and assigned it to the Main Menu location.', 'ekwa' ),
				$menu['created'],
				$menu['menu_name']
			);
			if ( ! empty( $menu['replaced'] ) ) {
				$note .= ' ' . __( 'The previous items in that menu were replaced.', 'ekwa' );
			}
			if ( ! empty( $menu['images'] ) ) {
				$note .= ' ' . sprintf(
					/* translators: %d: number of mega-menu column images matched. */
					_n( '%d mega-menu image was matched in the media library.', '%d mega-menu images were matched in the media library.', $menu['images'], 'ekwa' ),
					$menu['images']
				);
			}
			$response['warnings'][] = $note;
		}
	}

	// ── Normalize every icon to Font Awesome. ────────────────────────────
	// Catches what the DOM pass can't reach: AI-generated markup, the HTML
	// inside a dynamic block's customTemplate, and raw inner HTML.
	if ( function_exists( 'ekwa_mc_icons_to_fontawesome' ) ) {
		$icons = ekwa_mc_icons_to_fontawesome( (string) $response['markup'] );
		if ( $icons['converted'] > 0 ) {
			$response['markup']     = $icons['markup'];
			$response['icons_converted'] = $icons['converted'];
			$response['warnings'][] = sprintf(
				/* translators: %d: number of icons rewritten. */
				_n(
					'Converted %d icon to Font Awesome — the theme only loads Font Awesome, so other icon fonts would not have rendered.',
					'Converted %d icons to Font Awesome — the theme only loads Font Awesome, so other icon fonts would not have rendered.',
					$icons['converted'],
					'ekwa'
				),
				$icons['converted']
			);
		}
		if ( ! empty( $icons['unmapped'] ) ) {
			$response['icons_unmapped'] = $icons['unmapped'];
			$response['warnings'][]     = sprintf(
				/* translators: %s: comma-separated icon class names. */
				__( 'No Font Awesome equivalent for these icons, so they will render as blank: %s. Pick a replacement at fontawesome.com/search and set it on the block (Icon class).', 'ekwa' ),
				implode( ', ', array_slice( $icons['unmapped'], 0, 12 ) ) . ( count( $icons['unmapped'] ) > 12 ? '…' : '' )
			);
		}
	}

	// ── Fidelity audit: which mockup classes vanished? ───────────────────
	// A dropped class is invisible in the block markup but silently unstyles
	// whatever the mockup's CSS targeted, so it's worth naming explicitly.
	$lost = ekwa_mc_missing_classes( $html, (string) $response['markup'] );
	if ( ! empty( $lost ) ) {
		$response['lost_classes'] = $lost;
		$response['warnings'][]   = sprintf(
			/* translators: %s: comma-separated CSS class names and #ids. */
			__( 'These mockup selectors are not in the converted blocks, so any CSS or JS targeting them will not apply: %s. Add a class back via the block\'s Advanced → Additional CSS class(es), and an #id via Advanced → HTML anchor.', 'ekwa' ),
			implode( ', ', array_slice( $lost, 0, 20 ) ) . ( count( $lost ) > 20 ? '…' : '' )
		);
	}

	return $response;
}

/**
 * Class tokens present in the source HTML but absent from the converted block
 * markup — i.e. mockup CSS that will no longer match anything.
 *
 * Elements a dynamic block replaces are skipped WITH THEIR WHOLE SUBTREE: the
 * block renders its own canonical markup from site settings, so those classes
 * are meant to disappear (and a customTemplate preserves them when the exact
 * markup matters). Without that, converting a header would report every class
 * inside the nav and the logo as "lost", which is just noise.
 *
 * @param string $html   Source mockup HTML.
 * @param string $markup Converted block markup.
 * @return string[] Missing class names, in source order.
 */
function ekwa_mc_missing_classes( $html, $markup ) {
	if ( '' === trim( $html ) || '' === trim( $markup ) ) {
		return array();
	}
	if ( ! function_exists( 'ekwa_mc_extract_body' ) ) {
		require_once get_template_directory() . '/inc/ekwa-converter-lib.php';
	}

	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML(
		'<?xml encoding="utf-8"?><div data-ekwa-audit-root="1">' . ekwa_mc_extract_body( $html ) . '</div>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
	libxml_clear_errors();

	// Signature classes whose element is swapped for a dynamic block. Mirrors
	// the canonical map in ekwa_mc_detect_canonical().
	$consumed_signatures = array(
		'ekwa-header-nav', 'ekwa-header-menu', 'ekwa-mobile-nav', 'ekwa-phone-number',
		'ekwa-working-hours', 'ekwa-social-icons', 'ekwa-copyright', 'ekwa-svg-logo',
		'wp-block-site-logo', 'custom-logo-link', 'ekwa-hamburger-btn', 'ekwa-address',
		'ekwa-search-block', 'ekwa-scroll-top', 'ekwa-mobile-dock',
	);

	$source = array();
	$walk   = function ( $node ) use ( &$walk, &$source, $consumed_signatures ) {
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			// A forced mapping (data-ekwa="phone") consumes its subtree too;
			// "static"/"ignore" opt out of detection, so keep walking those.
			$token = strtolower( trim( $child->getAttribute( 'data-ekwa' ) ) );
			if ( '' !== $token && ! in_array( $token, array( 'static', 'ignore' ), true ) ) {
				continue;
			}

			$classes = preg_split( '/\s+/', $child->getAttribute( 'class' ), -1, PREG_SPLIT_NO_EMPTY );
			if ( array_intersect( $classes, $consumed_signatures ) ) {
				continue; // Replaced by a dynamic block — subtree and all.
			}

			foreach ( $classes as $class ) {
				// wp-block-* is WordPress' own; ekwa-* classes are the mockup's
				// opt-in signatures, which the blocks re-emit themselves.
				if ( preg_match( '/^(?:wp-block-|ekwa-)/', $class ) ) {
					continue;
				}
				// A foreign icon-font class is *meant* to change — it's rewritten
				// to Font Awesome, and that pass reports its own summary (and
				// anything it couldn't map). Flagging it here too would just be
				// noise: "ri-search-line is missing" when it became fa-solid
				// fa-magnifying-glass exactly as intended.
				if ( function_exists( 'ekwa_mc_icon_base_name' )
					&& ( null !== ekwa_mc_icon_base_name( strtolower( $class ) )
						|| ekwa_mc_icon_is_family_token( strtolower( $class ) ) ) ) {
					continue;
				}
				$source[ $class ] = true;
			}

			// ids matter as much as classes — mockups hang scroll handlers and
			// skip links off them, and a dropped id fails just as quietly.
			$id = trim( $child->getAttribute( 'id' ) );
			if ( '' !== $id ) {
				$source[ '#' . $id ] = true;
			}

			$walk( $child );
		}
	};

	$root = $doc->documentElement;
	if ( ! $root ) {
		return array();
	}
	$walk( $root );

	if ( empty( $source ) ) {
		return array();
	}

	// Anything the markup mentions anywhere counts as kept — className
	// attributes, inline HTML, and customTemplate payloads all qualify.
	$missing = array();
	foreach ( array_keys( $source ) as $name ) {
		$needle = ( '#' === $name[0] ) ? substr( $name, 1 ) : $name;
		if ( false === strpos( $markup, $needle ) ) {
			$missing[] = $name;
		}
	}

	return $missing;
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
				$scoped = trim( $split['scoped'] );

				// Deterministic backstop: make every font the site self-hosts resolve
				// through its own CSS variable, even when the model echoed the raw
				// family name (see ekwa_fonts_rewrite_css_to_vars()).
				if ( '' !== $scoped && function_exists( 'ekwa_fonts_rewrite_css_to_vars' ) ) {
					$rewritten = ekwa_fonts_rewrite_css_to_vars( $scoped );
					$scoped    = $rewritten['css'];
				}

				$response['css_scoped']  = $scoped;
				$response['css_extract'] = ekwa_mc_extract_css_tokens( $source_css );

				if ( ! empty( $split['truncated'] ) ) {
					$response['warnings'][] = __( 'This section’s stylesheet was large enough that the AI response was cut short — the Scoped CSS below may be incomplete. Only the rules it did return were taken out of the Global CSS, so nothing is lost; re-run the extraction (or extract a smaller portion) and review the Scoped CSS before inserting.', 'ekwa' );
				}

				// Thin the shared pool — pool path only, and only for users who may
				// edit theme-wide CSS.
				//
				// The leftover is computed HERE by subtracting the rules the model
				// actually claimed (ekwa_css_subtract), never taken from the model's
				// own "leftover" output. A model that returns an empty/partial/
				// mangled response can therefore only leave the pool too FULL — the
				// old code trusted its leftover verbatim, so a response that silently
				// omitted the section's rules deleted them from <head> while the
				// Scoped CSS came back empty, leaving the section unstyled.
				if ( ! $pasted && function_exists( 'ekwa_tokens_set_global_css' ) ) {
					if ( ! current_user_can( 'edit_theme_options' ) ) {
						$response['warnings'][] = __( 'Section CSS extracted, but only an administrator can update the site-wide Global CSS.', 'ekwa' );
					} elseif ( '' === $scoped ) {
						// Nothing was extracted — the pool must stay exactly as it is.
						$response['warnings'][] = __( 'Global CSS left unchanged — the AI found no rules for this section, so nothing was removed from the site-wide stylesheet. Check that the pasted HTML still carries the mockup’s class names, then try again.', 'ekwa' );
					} else {
						$thinned = ekwa_css_subtract( $source_css, $scoped );

						if ( 0 === $thinned['removed'] ) {
							$response['warnings'][] = __( 'Global CSS left unchanged — the extracted rules didn’t match any rule in the pool (they may have been rewritten rather than copied). The Scoped CSS below is still usable; it will just be duplicated in <head>.', 'ekwa' );
						} elseif ( '' === trim( $thinned['css'] ) ) {
							// Everything matched: the model swept the entire pool into
							// one section. Almost always wrong, and it would drop the
							// site's base CSS — so keep the pool.
							$response['warnings'][] = __( 'Global CSS left unchanged — this section claimed every rule in the pool, which would have emptied the site-wide stylesheet. Double-check this section’s Scoped CSS.', 'ekwa' );
						} else {
							ekwa_tokens_set_global_css( $thinned['css'] );
							$response['css_global_updated'] = true;
							$response['css_global_bytes']   = strlen( trim( $thinned['css'] ) );
							$response['css_rules_moved']    = $thinned['removed'];
						}
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

	$system = "You are a precise CSS extractor for a WordPress block theme.\n"
		. "INPUT: an HTML section, then a stylesheet (\"the pool\").\n"
		. "TASK: return ONLY the rules from the pool that specifically style THIS section (matched by its classes, ids, tags and their descendants). INCLUDE the section's ::before/::after pseudo-element rules, :hover/:focus states, @media variants of those rules (keep them inside their original @media wrapper, with the media query written exactly as in the pool), and any @keyframes they reference.\n"
		. "COPY EACH SELECTOR EXACTLY as it appears in the pool — character for character, including the @media prelude. The selector text is how the rule is matched back to the pool and removed from the site-wide stylesheet; a reworded or merged selector means the rule stays duplicated. You MAY rewrite DECLARATION VALUES to the site's design tokens where one matches: var(--name) for colors, font-family variables for fonts, background-image variables instead of url(...) when the token represents the same image. Do NOT redeclare the token variables themselves.\n"
		. "LEAVE OUT the shared/base layer: resets, html/body typography, bare element rules (a, img, headings, lists…), generic component rules (e.g. .btn, .container) that aren't unique to this section, utility classes, and other sections' rules. When unsure whether a rule is section-specific or shared, LEAVE IT OUT — anything you omit simply stays in the site-wide stylesheet.\n"
		. "EXCLUDE entirely: :root blocks, @font-face, and @import (those are handled elsewhere). Invent nothing — every rule you return must exist in the pool.\n"
		. "OUTPUT: the marker on its own line, then raw CSS — no markdown fences, no commentary, nothing after the CSS:\n"
		. "===EKWA_SCOPED===\n<the section's css>";

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

	// Only this section's rules come back now (the pool is thinned locally by
	// subtracting them), so the output is a small fraction of the input. The
	// budget stays generous anyway for section-heavy stylesheets, and "thinking"
	// stays OFF — copying matching rules out of a stylesheet needs none, and
	// thinking tokens would eat into the same output budget.
	$result = ekwa_ai_generate_call_gemini( $system, $contents, 0.1, $api_key, 'gemini-2.5-flash', 65536, 0 );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$out = function_exists( 'ekwa_ai_generate_strip_fences' )
		? ekwa_ai_generate_strip_fences( $result['content'] )
		: trim( $result['content'] );

	if ( '' === trim( $out ) ) {
		return new WP_Error( 'ai_empty', __( 'The AI returned no CSS.', 'ekwa' ) );
	}

	$split = ekwa_mc_parse_split_css( $out );

	// A truncated response is a partial list of the section's rules. That is
	// safe now — the pool only loses what came back — but the Scoped CSS is
	// incomplete, so flag it for review.
	if ( isset( $result['finish_reason'] ) && 'MAX_TOKENS' === $result['finish_reason'] ) {
		$split['truncated'] = true;
	}

	return $split;
}

/**
 * Pull the section's CSS out of the model's response.
 *
 * Tolerant of missing/misspelled markers: with no marker at all the whole
 * output is the section CSS. Older prompts asked for a second "LEFTOVER" half
 * and a model may still emit one out of habit — it is discarded here, because
 * the pool is now thinned by subtraction (see ekwa_css_subtract()) rather than
 * by trusting the model's copy of it.
 *
 * @param string $out Raw model output (fences already stripped).
 * @return array{scoped:string,leftover:?string}
 */
function ekwa_mc_parse_split_css( $out ) {
	// Tolerant marker matching: the model occasionally varies the spacing, the
	// separator, or the number of '=' around the markers (===EKWA_SCOPED===,
	// === EKWA SCOPED ===, ==EKWA-LEFTOVER==…). Matching loosely keeps a healthy
	// split from being misread as "all scoped" over cosmetic drift.
	$scoped_re   = '/=+\s*EKWA[\s_-]*SCOPED\s*=+/i';
	$leftover_re = '/=+\s*EKWA[\s_-]*LEFTOVER\s*=+/i';

	if ( preg_match( $leftover_re, $out ) ) {
		$parts    = preg_split( $leftover_re, $out, 2 );
		$scoped   = isset( $parts[0] ) ? $parts[0] : '';
		$leftover = isset( $parts[1] ) ? $parts[1] : '';
	} else {
		// No LEFTOVER marker — treat the whole output as scoped, pool untouched.
		$scoped   = $out;
		$leftover = null;
	}

	// Drop everything up to and including the SCOPED marker — this also strips any
	// preamble line ("Here is the split:") the model sometimes emits before it.
	if ( preg_match( $scoped_re, $scoped, $m, PREG_OFFSET_CAPTURE ) ) {
		$scoped = substr( $scoped, $m[0][1] + strlen( $m[0][0] ) );
	}

	return array(
		'scoped'   => trim( (string) $scoped ),
		'leftover' => ( null === $leftover ) ? null : trim( (string) $leftover ),
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
