<?php
/**
 * Page audit — PageSpeed + accessibility scores and AI fixes in the editor.
 *
 * The "Page Speed & Accessibility" sidebar in the block editor
 * (assets/js/ekwa-page-audit-editor.js) tests the published page with Google
 * PageSpeed Insights, shows the Performance and Accessibility scores, and asks
 * the site's AI provider — the Gemini key or the MCP connector, through the
 * same ekwa_ai_generate_call_gemini() choke point every AI feature uses — to
 * read the report together with the page's blocks and the theme's performance
 * settings, and propose fixes.
 *
 * Every fix is shown before anything changes, applied only on an explicit
 * click, and can be undone:
 *   - "setting" fixes change one whitelisted Ekwa performance option
 *     (ekwa_page_audit_settings_registry()). Site-wide, so administrators only.
 *     The old value — including "never saved" — goes into a site-wide log
 *     (EKWA_PAGE_AUDIT_LOG_OPTION) that both the sidebar and Ekwa Settings →
 *     Performance can undo from.
 *   - "block" fixes change attributes of one block in the editor (client side,
 *     so they go through the editor's own undo stack and are saved with the
 *     page). The before/after attributes are recorded in post meta so the
 *     sidebar can undo them later too.
 *   - "css" fixes change one declaration of the site stylesheet (and may add
 *     one colour variable) — see inc/ekwa-page-audit-css.php. Site-wide,
 *     administrators only, logged as exact text patches in
 *     EKWA_PAGE_AUDIT_CHANGES_OPTION and undoable byte for byte.
 *   - "part" fixes change one block's attributes inside a template part
 *     (header, footer…) — see inc/ekwa-page-audit-parts.php. Same log.
 *   - "manual" fixes are steps for a person (hosting, image files, …).
 *
 * Each report is also judged PASS/FAIL against the same criteria as the
 * team's PageSpeed sitemap tool (ekwa_page_audit_thresholds()), and a short
 * summary is kept per page for the site-wide dashboard
 * (inc/ekwa-page-audit-dashboard.php).
 *
 * Nothing here runs unless someone opens the sidebar or the dashboard and
 * clicks: no hooks on the front end (except the compare preview, which needs a
 * signed URL), no options written on load, no change for a site that never
 * uses it.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Site-wide log of setting changes made from the audit (non-autoloaded). */
const EKWA_PAGE_AUDIT_LOG_OPTION = 'ekwa_page_audit_log';

/** Site-wide log of stylesheet and template-part changes (non-autoloaded). */
const EKWA_PAGE_AUDIT_CHANGES_OPTION = 'ekwa_page_audit_changes';

/** Post meta: small per-strategy summary (scores, metrics, PASS/FAIL) for the dashboard. */
const EKWA_PAGE_AUDIT_META_SUMMARY = '_ekwa_page_audit_summary';

/** Post meta: compact PageSpeed report, one key per strategy (suffix). */
const EKWA_PAGE_AUDIT_META_REPORT = '_ekwa_page_audit_report_';

/** Post meta: the last AI analysis, one key per strategy (suffix). */
const EKWA_PAGE_AUDIT_META_AI = '_ekwa_page_audit_ai_';

/** Post meta: score history, for the trend shown next to each score. */
const EKWA_PAGE_AUDIT_META_SCORES = '_ekwa_page_audit_scores';

/** Post meta: block attribute changes made from the audit. */
const EKWA_PAGE_AUDIT_META_HISTORY = '_ekwa_page_audit_history';

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIGURATION
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * The PageSpeed Insights API key: the EKWA_PSI_API_KEY constant, else the
 * option saved under Ekwa Settings → Performance. '' when neither is set —
 * Google still answers a few keyless requests, so the feature degrades to
 * "quota exceeded" rather than refusing outright.
 *
 * @return string
 */
function ekwa_page_audit_psi_key() {
	if ( defined( 'EKWA_PSI_API_KEY' ) && EKWA_PSI_API_KEY ) {
		return (string) EKWA_PSI_API_KEY;
	}
	return trim( (string) get_option( 'ekwa_psi_api_key', '' ) );
}

/**
 * Normalize a strategy name.
 *
 * @param mixed $raw
 * @return string 'mobile' (default) or 'desktop'.
 */
function ekwa_page_audit_strategy( $raw ) {
	return 'desktop' === $raw ? 'desktop' : 'mobile';
}

/**
 * The settings the audit — and the AI — may change, with what each one does.
 *
 * Defaults are the ones the theme reads with get_option(), so "the option was
 * never saved" and "the option holds its default" read the same. Free-text
 * settings (critical CSS, footer style handles) and layout settings
 * (breakpoints) are deliberately absent: a wrong value there breaks a site in
 * ways a one-click undo would not make obvious.
 *
 * @return array<string,array{type:string,default:mixed,values?:string[],label:string,help:string,risk:string}>
 */
function ekwa_page_audit_settings_registry() {
	$bool = static function ( $default, $label, $help, $risk = '' ) {
		return array(
			'type'    => 'bool',
			'default' => (int) $default,
			'label'   => $label,
			'help'    => $help,
			'risk'    => $risk,
		);
	};

	$settings = array(
		'ekwa_perf_lazy_mode'         => array(
			'type'    => 'enum',
			'values'  => array( 'off', 'native', 'lazysizes' ),
			'default' => 'native',
			'label'   => __( 'Lazy loading mode', 'ekwa' ),
			'help'    => __( 'off = no loading attribute; native = loading="lazy" (default); lazysizes = JS library with an earlier load threshold. Hero images always load eagerly.', 'ekwa' ),
			'risk'    => '',
		),
		'ekwa_perf_lazysizes_footer'  => $bool( 0, __( 'lazysizes in the footer', 'ekwa' ), __( 'Load the lazysizes scripts before </body> instead of the <head>. Only matters in lazysizes mode.', 'ekwa' ) ),
		'ekwa_perf_srcset'            => $bool( 1, __( 'Responsive srcset', 'ekwa' ), __( 'Generate srcset/sizes for ekwa/image so phones download smaller files.', 'ekwa' ) ),
		'ekwa_perf_preload_hero'      => $bool( 1, __( 'Hero preload', 'ekwa' ), __( 'Preload images marked Hero with fetchpriority="high" (helps LCP).', 'ekwa' ) ),
		'ekwa_perf_decoding_async'    => $bool( 1, __( 'Async decoding', 'ekwa' ), __( 'decoding="async" on ekwa/image.', 'ekwa' ) ),
		'ekwa_perf_defer_fa_mobile'   => $bool( 0, __( 'Defer Font Awesome on mobile', 'ekwa' ), __( 'On phones, load the icon font on the first interaction (removes ~33KB render-blocking CSS).', 'ekwa' ), __( 'Header icons can show briefly unstyled on phones until the visitor scrolls or taps.', 'ekwa' ) ),
		'ekwa_perf_defer_mmenu'       => $bool( 0, __( 'Defer mobile menu', 'ekwa' ), __( 'Load the off-canvas mobile menu library on first interaction.', 'ekwa' ) ),
		'ekwa_perf_minify_inline'     => $bool( 0, __( 'Minify inline CSS/JS', 'ekwa' ), __( 'Minify all CSS/JS inlined into the page, including WordPress\'s own inline styles.', 'ekwa' ) ),
		'ekwa_perf_minify_child_css'  => $bool( 0, __( 'Minify child theme CSS file', 'ekwa' ), __( 'Serve a minified copy of the child style.css (clears "Minify CSS").', 'ekwa' ), __( 'Re-check background images and icon fonts after switching it on.', 'ekwa' ) ),
		'ekwa_perf_inline_child_css'  => $bool( 0, __( 'Inline child theme CSS', 'ekwa' ), __( 'Inline the child style.css in the <head> instead of a separate render-blocking request.', 'ekwa' ), __( 'Makes every HTML page heavier; best when the child stylesheet is small.', 'ekwa' ) ),
		'ekwa_perf_inline_child_js'   => $bool( 0, __( 'Inline child theme JS', 'ekwa' ), __( 'Inline assets/js/ekwa-child.js before </body> instead of a separate request.', 'ekwa' ) ),
		'ekwa_perf_preload_fonts'     => $bool( 0, __( 'Preload fonts', 'ekwa' ), __( 'Preload one weight per self-hosted font family (helps font-display and LCP text).', 'ekwa' ) ),
		'ekwa_perf_lean_head'         => $bool( 0, __( 'Lean head', 'ekwa' ), __( 'Drop jQuery Migrate, Heartbeat and wp-embed on the front end.', 'ekwa' ), __( 'A plugin that needs legacy jQuery or front-end Heartbeat can stop working. Re-test forms and sliders.', 'ekwa' ) ),
		'ekwa_perf_image_dimensions'  => $bool( 0, __( 'Image dimensions (CLS)', 'ekwa' ), __( 'Add missing width/height to ekwa/image from the media library (prevents layout shift).', 'ekwa' ) ),
		'ekwa_perf_preload_banner'    => $bool( 0, __( 'Preload banner image', 'ekwa' ), __( 'Preload the inner-banner featured image (a CSS background) with fetchpriority="high".', 'ekwa' ), __( 'Only useful when templates use the Ekwa inner banner; otherwise it preloads an unused image.', 'ekwa' ) ),
		'ekwa_webp_enabled'           => $bool( 1, __( 'WebP images', 'ekwa' ), __( 'Generate WebP copies on upload and serve them to supporting browsers. Existing media need "Regenerate WebP" in Ekwa Settings → Performance.', 'ekwa' ) ),
		'ekwa_webp_apply_core_image'  => $bool( 1, __( 'WebP for core/image', 'ekwa' ), __( 'Also swap WebP URLs into core image blocks.', 'ekwa' ) ),
		'ekwa_analytics_location'     => array(
			'type'    => 'enum',
			'values'  => array( 'header', 'footer', 'delay' ),
			'default' => 'footer',
			'label'   => __( 'Google Analytics placement', 'ekwa' ),
			'help'    => __( 'header / footer (default) / delay = load the tag on first interaction or after 10 seconds.', 'ekwa' ),
			'risk'    => __( 'delay can under-count very short visits that never interact.', 'ekwa' ),
		),
	);

	/**
	 * Filter the settings the page audit may change. A child theme can add its
	 * own options here — same shape: type bool|enum, default, values (enum),
	 * label, help, risk.
	 *
	 * @param array $settings
	 */
	$settings = apply_filters( 'ekwa_page_audit_settings', $settings );
	return is_array( $settings ) ? $settings : array();
}

/**
 * Coerce a value to a setting's type, or null when it is not a valid value.
 *
 * @param array $def   Registry entry.
 * @param mixed $value
 * @return int|string|null Stored form: 1/0 for bools, the string for enums.
 */
function ekwa_page_audit_coerce( $def, $value ) {
	if ( 'bool' === $def['type'] ) {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}
		if ( is_numeric( $value ) ) {
			return ( (int) $value ) ? 1 : 0;
		}
		$s = strtolower( trim( (string) $value ) );
		if ( in_array( $s, array( 'true', 'on', 'yes' ), true ) ) {
			return 1;
		}
		if ( in_array( $s, array( 'false', 'off', 'no', '' ), true ) ) {
			return 0;
		}
		return null;
	}
	if ( 'enum' === $def['type'] ) {
		$s = (string) $value;
		return in_array( $s, (array) $def['values'], true ) ? $s : null;
	}
	return null;
}

/**
 * A setting's current value in its stored form (default when never saved).
 *
 * @param string $key
 * @param array  $def
 * @return int|string
 */
function ekwa_page_audit_current( $key, $def ) {
	$value = ekwa_page_audit_coerce( $def, get_option( $key, $def['default'] ) );
	return null === $value ? $def['default'] : $value;
}

/**
 * The registry with each setting's current value, for the sidebar and prompt.
 *
 * @return array<string,array>
 */
function ekwa_page_audit_settings_snapshot() {
	$out = array();
	foreach ( ekwa_page_audit_settings_registry() as $key => $def ) {
		$out[ $key ] = array(
			'label'   => (string) $def['label'],
			'type'    => (string) $def['type'],
			'values'  => 'enum' === $def['type'] ? array_values( (array) $def['values'] ) : array( true, false ),
			'current' => 'bool' === $def['type'] ? (bool) ekwa_page_audit_current( $key, $def ) : ekwa_page_audit_current( $key, $def ),
			'help'    => (string) $def['help'],
			'risk'    => (string) $def['risk'],
		);
	}
	return $out;
}

// ═══════════════════════════════════════════════════════════════════════════════
// STORAGE HELPERS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Write post meta without losing backslashes (update_post_meta() unslashes, and
 * reports and attribute values legitimately contain them — CSS escapes, regex).
 *
 * @param int    $post_id
 * @param string $key
 * @param mixed  $value
 */
function ekwa_page_audit_put_meta( $post_id, $key, $value ) {
	update_post_meta( $post_id, $key, wp_slash( $value ) );
}

/**
 * Read an array post meta value.
 *
 * @param int    $post_id
 * @param string $key
 * @return array
 */
function ekwa_page_audit_get_meta( $post_id, $key ) {
	$value = get_post_meta( $post_id, $key, true );
	return is_array( $value ) ? $value : array();
}

/**
 * The site-wide setting-change log.
 *
 * @return array[]
 */
function ekwa_page_audit_log() {
	$log = get_option( EKWA_PAGE_AUDIT_LOG_OPTION, array() );
	return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
}

/**
 * Change the log under a lock (two editors applying fixes at once must not
 * overwrite each other's entries).
 *
 * @param callable $change Receives the log, returns the new log.
 * @return bool False when the lock could not be taken.
 */
function ekwa_page_audit_mutate_log( $change ) {
	$lock = function_exists( 'ekwa_ai_lock' ) ? ekwa_ai_lock( 'page-audit-log', 5 ) : 'none';
	if ( '' === $lock ) {
		return false;
	}
	try {
		wp_cache_delete( EKWA_PAGE_AUDIT_LOG_OPTION, 'options' );
		$log = call_user_func( $change, ekwa_page_audit_log() );
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}
		update_option( EKWA_PAGE_AUDIT_LOG_OPTION, array_values( $log ), false );
		return true;
	} finally {
		if ( 'none' !== $lock && function_exists( 'ekwa_ai_unlock' ) ) {
			ekwa_ai_unlock( $lock );
		}
	}
}

/**
 * A short random id.
 *
 * @return string
 */
function ekwa_page_audit_id() {
	return strtolower( wp_generate_password( 10, false, false ) );
}

/**
 * Current user's display name, for the logs.
 *
 * @return string
 */
function ekwa_page_audit_user_name() {
	$user = wp_get_current_user();
	return $user && $user->exists() ? (string) $user->display_name : '';
}

// ═══════════════════════════════════════════════════════════════════════════════
// PAGESPEED INSIGHTS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * The URL PageSpeed should test for a post, or a WP_Error explaining why not.
 *
 * @param WP_Post $post
 * @return string|WP_Error
 */
function ekwa_page_audit_test_url( $post ) {
	if ( 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
		return new WP_Error(
			'ekwa_page_audit_not_public',
			__( 'PageSpeed Insights tests the live page, so it has to be published (and not password-protected) first.', 'ekwa' ),
			array( 'status' => 400 )
		);
	}
	$url = get_permalink( $post );

	/**
	 * Filter the URL the page audit sends to PageSpeed Insights.
	 *
	 * @param string  $url
	 * @param WP_Post $post
	 */
	$url = (string) apply_filters( 'ekwa_page_audit_url', $url, $post );
	if ( '' === $url || ! wp_http_validate_url( $url ) ) {
		return new WP_Error( 'ekwa_page_audit_bad_url', __( 'This page has no public URL to test.', 'ekwa' ), array( 'status' => 400 ) );
	}
	return $url;
}

/**
 * Run PageSpeed Insights for one URL.
 *
 * @param string $url
 * @param string $strategy 'mobile' or 'desktop'.
 * @return array|WP_Error Decoded API response.
 */
function ekwa_page_audit_fetch_psi( $url, $strategy ) {
	// All four categories, as the team's sitemap tool tests them: SEO is part
	// of PASS/FAIL, Best Practices is recorded for reference.
	$query = 'url=' . rawurlencode( $url )
		. '&strategy=' . rawurlencode( $strategy )
		. '&category=performance&category=accessibility&category=best-practices&category=seo&locale=en';
	$key   = ekwa_page_audit_psi_key();
	if ( '' !== $key ) {
		$query .= '&key=' . rawurlencode( $key );
	}

	$timeout = (int) apply_filters( 'ekwa_page_audit_psi_timeout', 120 );
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( $timeout + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts.
	}

	$response = wp_remote_get(
		'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . $query,
		array( 'timeout' => $timeout )
	);

	if ( is_wp_error( $response ) ) {
		$raw = $response->get_error_message();
		if ( false !== stripos( $raw, 'timed out' ) || false !== stripos( $raw, 'timeout' ) ) {
			return new WP_Error(
				'ekwa_page_audit_timeout',
				__( 'PageSpeed Insights did not finish in time. Slow pages can take over a minute to test — try again, or test the Desktop strategy, which is usually faster.', 'ekwa' ),
				array( 'status' => 504 )
			);
		}
		return new WP_Error( 'ekwa_page_audit_network', sprintf( /* translators: %s: error */ __( 'Could not reach PageSpeed Insights: %s', 'ekwa' ), $raw ), array( 'status' => 502 ) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code || ! is_array( $data ) ) {
		$msg = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
		if ( 429 === $code || false !== stripos( $msg, 'quota' ) ) {
			$text = '' === $key
				? __( 'Google\'s free PageSpeed quota is used up. Add a PageSpeed Insights API key in Ekwa Settings → Performance — it raises the limit to 25,000 tests a day.', 'ekwa' )
				: __( 'The PageSpeed Insights API key has reached its quota. Try again later.', 'ekwa' );
			return new WP_Error( 'ekwa_page_audit_quota', $text, array( 'status' => 429 ) );
		}
		if ( 403 === $code || ( 400 === $code && false !== stripos( $msg, 'API key' ) ) ) {
			return new WP_Error( 'ekwa_page_audit_key', __( 'Google refused the PageSpeed Insights API key. Check it in Ekwa Settings → Performance, and that the PageSpeed Insights API is enabled for it in Google Cloud.', 'ekwa' ), array( 'status' => 403 ) );
		}
		if ( preg_match( '/FAILED_DOCUMENT_REQUEST|ERRORED_DOCUMENT_REQUEST|DNS_FAILURE|NOT_HTML|NO_FCP|PAGE_HUNG|Unable to process/i', $msg ) ) {
			return new WP_Error(
				'ekwa_page_audit_unreachable',
				sprintf(
					/* translators: %s: Google's message. */
					__( 'PageSpeed Insights could not load the page. It has to be reachable from the public internet — local, staging or password-protected sites cannot be tested. (%s)', 'ekwa' ),
					wp_strip_all_tags( $msg )
				),
				array( 'status' => 400 )
			);
		}
		return new WP_Error( 'ekwa_page_audit_psi', sprintf( /* translators: %s: Google's message. */ __( 'PageSpeed Insights error: %s', 'ekwa' ), wp_strip_all_tags( $msg ) ), array( 'status' => 502 ) );
	}

	return $data;
}

/**
 * Plain text from a Lighthouse string: Markdown links reduced to their text,
 * tags removed, whitespace collapsed, length capped.
 *
 * @param mixed $text
 * @param int   $max
 * @return string
 */
function ekwa_page_audit_text( $text, $max = 300 ) {
	if ( ! is_scalar( $text ) ) {
		return '';
	}
	$text = preg_replace( '/\[([^\]]*)\]\([^)]*\)/', '$1', (string) $text );
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
		return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}
	return strlen( $text ) > $max ? rtrim( substr( $text, 0, $max - 1 ) ) . '…' : $text;
}

/**
 * The fields of a Lighthouse node worth keeping.
 *
 * @param array $node
 * @return array
 */
function ekwa_page_audit_compact_node( $node ) {
	$out = array();
	if ( ! empty( $node['selector'] ) ) {
		$out['selector'] = ekwa_page_audit_text( $node['selector'], 220 );
	}
	if ( ! empty( $node['snippet'] ) ) {
		// The snippet is HTML — keep it as text, not stripped.
		$snippet        = trim( preg_replace( '/\s+/', ' ', (string) $node['snippet'] ) );
		$out['snippet'] = strlen( $snippet ) > 320 ? substr( $snippet, 0, 319 ) . '…' : $snippet;
	}
	if ( ! empty( $node['nodeLabel'] ) ) {
		$out['label'] = ekwa_page_audit_text( $node['nodeLabel'], 120 );
	}
	if ( ! empty( $node['explanation'] ) ) {
		$out['explanation'] = ekwa_page_audit_text( $node['explanation'], 500 );
	}
	return $out;
}

/**
 * Up to $limit rows from an audit's details, flattened to the useful scalars.
 *
 * Handles Lighthouse's table/opportunity details and the list-of-tables shape
 * the newer "insight" audits use.
 *
 * @param array $details
 * @param int   $limit
 * @return array[]
 */
function ekwa_page_audit_compact_items( $details, $limit = 6 ) {
	$out   = array();
	$items = isset( $details['items'] ) && is_array( $details['items'] ) ? $details['items'] : array();
	$keep  = array( 'url', 'wastedBytes', 'wastedMs', 'totalBytes', 'transferSize', 'blockingTime', 'mainThreadTime', 'duration', 'cacheLifetimeMs', 'wastedPercent', 'label', 'groupLabel', 'value', 'requestCount' );

	foreach ( $items as $item ) {
		if ( count( $out ) >= $limit ) {
			break;
		}
		if ( ! is_array( $item ) ) {
			continue;
		}
		$type = isset( $item['type'] ) ? (string) $item['type'] : '';
		if ( in_array( $type, array( 'table', 'list', 'opportunity', 'checklist' ), true ) && isset( $item['items'] ) ) {
			foreach ( ekwa_page_audit_compact_items( $item, $limit - count( $out ) ) as $row ) {
				$out[] = $row;
			}
			continue;
		}
		if ( 'node' === $type ) {
			$node = ekwa_page_audit_compact_node( $item );
			if ( $node ) {
				$out[] = array( 'node' => $node );
			}
			continue;
		}

		$row = array();
		foreach ( $item as $k => $v ) {
			if ( 'node' === $k && is_array( $v ) ) {
				$node = ekwa_page_audit_compact_node( $v );
				if ( $node ) {
					$row['node'] = $node;
				}
				continue;
			}
			if ( 'source' === $k && is_array( $v ) && ! empty( $v['url'] ) ) {
				$row['source'] = ekwa_page_audit_text( $v['url'], 220 ) . ( isset( $v['line'] ) ? ':' . (int) $v['line'] : '' );
				continue;
			}
			if ( 'entity' === $k ) {
				$row['entity'] = is_array( $v ) ? ekwa_page_audit_text( isset( $v['text'] ) ? $v['text'] : '', 80 ) : ekwa_page_audit_text( $v, 80 );
				continue;
			}
			if ( ! in_array( $k, $keep, true ) ) {
				continue;
			}
			if ( is_float( $v ) || is_int( $v ) ) {
				$row[ $k ] = round( (float) $v, 'wastedPercent' === $k ? 1 : 0 );
			} elseif ( is_string( $v ) || is_bool( $v ) ) {
				$row[ $k ] = is_string( $v ) ? ekwa_page_audit_text( $v, 220 ) : $v;
			} elseif ( is_array( $v ) && isset( $v['type'] ) && 'node' === $v['type'] ) {
				$row[ $k ] = ekwa_page_audit_compact_node( $v );
			}
		}
		if ( $row ) {
			$out[] = $row;
		}
	}
	return $out;
}

/**
 * The first node anywhere inside an audit's details (the LCP element audits).
 *
 * @param mixed $details
 * @param int   $depth
 * @return array|null
 */
function ekwa_page_audit_first_node( $details, $depth = 0 ) {
	if ( ! is_array( $details ) || $depth > 5 ) {
		return null;
	}
	if ( isset( $details['type'] ) && 'node' === $details['type'] ) {
		$node = ekwa_page_audit_compact_node( $details );
		return $node ? $node : null;
	}
	foreach ( $details as $value ) {
		$found = ekwa_page_audit_first_node( $value, $depth + 1 );
		if ( $found ) {
			return $found;
		}
	}
	return null;
}

/**
 * Reduce a PageSpeed response to what the sidebar shows and the AI needs:
 * scores, lab metrics, field data, the LCP element and the failing audits.
 * Roughly 2% of the raw response.
 *
 * @param array  $data     Decoded PageSpeed response.
 * @param string $strategy
 * @return array
 */
function ekwa_page_audit_compact( $data, $strategy ) {
	$lh     = isset( $data['lighthouseResult'] ) && is_array( $data['lighthouseResult'] ) ? $data['lighthouseResult'] : array();
	$cats   = isset( $lh['categories'] ) && is_array( $lh['categories'] ) ? $lh['categories'] : array();
	$audits = isset( $lh['audits'] ) && is_array( $lh['audits'] ) ? $lh['audits'] : array();

	// v1 reports (3.3.6–3.3.8) only have performance + accessibility; every
	// reader treats a missing key as "not measured".
	$categories = array( 'performance', 'accessibility', 'best-practices', 'seo' );
	$scores     = array();
	foreach ( $categories as $cat ) {
		$scores[ $cat ] = isset( $cats[ $cat ]['score'] ) && is_numeric( $cats[ $cat ]['score'] )
			? (int) round( $cats[ $cat ]['score'] * 100 )
			: null;
	}

	$metrics = array();
	$ids     = array(
		'first-contentful-paint'   => 'FCP',
		'largest-contentful-paint' => 'LCP',
		'total-blocking-time'      => 'TBT',
		'cumulative-layout-shift'  => 'CLS',
		'speed-index'              => 'SI',
	);
	foreach ( $ids as $id => $abbr ) {
		if ( empty( $audits[ $id ] ) || ! is_array( $audits[ $id ] ) ) {
			continue;
		}
		$a         = $audits[ $id ];
		$metrics[] = array(
			'id'      => $id,
			'abbr'    => $abbr,
			'title'   => ekwa_page_audit_text( isset( $a['title'] ) ? $a['title'] : $abbr, 60 ),
			'display' => ekwa_page_audit_text( isset( $a['displayValue'] ) ? $a['displayValue'] : '', 30 ),
			'score'   => isset( $a['score'] ) && is_numeric( $a['score'] ) ? round( (float) $a['score'], 2 ) : null,
			// Raw lab value (ms, or unitless for CLS) — what PASS/FAIL reads.
			'value'   => isset( $a['numericValue'] ) && is_numeric( $a['numericValue'] ) ? round( (float) $a['numericValue'], 'cumulative-layout-shift' === $id ? 4 : 0 ) : null,
		);
	}

	$lcp = null;
	foreach ( array( 'largest-contentful-paint-element', 'lcp-breakdown-insight', 'lcp-phases-insight', 'lcp-discovery-insight' ) as $id ) {
		if ( ! empty( $audits[ $id ]['details'] ) ) {
			$lcp = ekwa_page_audit_first_node( $audits[ $id ]['details'] );
			if ( $lcp ) {
				break;
			}
		}
	}

	$failing = array();
	$keep    = array( 'performance' => 30, 'accessibility' => 30, 'seo' => 20, 'best-practices' => 12 );
	foreach ( $keep as $cat => $max ) {
		$refs = isset( $cats[ $cat ]['auditRefs'] ) && is_array( $cats[ $cat ]['auditRefs'] ) ? $cats[ $cat ]['auditRefs'] : array();
		$list = array();
		foreach ( $refs as $ref ) {
			$id    = isset( $ref['id'] ) ? (string) $ref['id'] : '';
			$group = isset( $ref['group'] ) ? (string) $ref['group'] : '';
			if ( '' === $id || 'metrics' === $group || 'hidden' === $group || empty( $audits[ $id ] ) || ! is_array( $audits[ $id ] ) ) {
				continue;
			}
			$a     = $audits[ $id ];
			$mode  = isset( $a['scoreDisplayMode'] ) ? (string) $a['scoreDisplayMode'] : '';
			$score = isset( $a['score'] ) ? $a['score'] : null;
			if ( ! in_array( $mode, array( 'binary', 'numeric', 'metricSavings' ), true ) || ! is_numeric( $score ) ) {
				continue;
			}
			if ( (float) $score >= ( 'performance' === $cat ? 0.9 : 1.0 ) ) {
				continue;
			}
			// SEO / Best Practices: like the sitemap tool, only audits that can
			// move the score (weight > 0) explain a score below 100.
			if ( ( 'seo' === $cat || 'best-practices' === $cat ) && empty( $ref['weight'] ) ) {
				continue;
			}

			$entry = array(
				'id'          => $id,
				'title'       => ekwa_page_audit_text( isset( $a['title'] ) ? $a['title'] : $id, 140 ),
				'description' => ekwa_page_audit_text( isset( $a['description'] ) ? $a['description'] : '', 260 ),
				'display'     => ekwa_page_audit_text( isset( $a['displayValue'] ) ? $a['displayValue'] : '', 80 ),
				'score'       => round( (float) $score, 2 ),
				'weight'      => isset( $ref['weight'] ) ? (float) $ref['weight'] : 0,
			);
			if ( ! empty( $a['metricSavings'] ) && is_array( $a['metricSavings'] ) ) {
				$savings = array();
				foreach ( $a['metricSavings'] as $metric => $value ) {
					if ( is_numeric( $value ) && $value > 0 ) {
						$savings[ sanitize_key( $metric ) ] = round( (float) $value, 'CLS' === $metric ? 3 : 0 );
					}
				}
				if ( $savings ) {
					$entry['savings'] = $savings;
				}
			}
			if ( ! empty( $a['details'] ) && is_array( $a['details'] ) ) {
				$items = ekwa_page_audit_compact_items( $a['details'], 'accessibility' === $cat ? 8 : ( 'best-practices' === $cat ? 3 : 6 ) );
				if ( $items ) {
					$entry['items'] = $items;
				}
			}
			$list[] = $entry;
		}

		usort(
			$list,
			static function ( $x, $y ) {
				$sx = isset( $x['savings'] ) ? array_sum( $x['savings'] ) : 0;
				$sy = isset( $y['savings'] ) ? array_sum( $y['savings'] ) : 0;
				if ( $x['weight'] !== $y['weight'] ) {
					return $y['weight'] > $x['weight'] ? 1 : -1;
				}
				if ( $sx !== $sy ) {
					return $sy > $sx ? 1 : -1;
				}
				return $x['score'] > $y['score'] ? 1 : ( $x['score'] < $y['score'] ? -1 : 0 );
			}
		);
		$failing[ $cat ] = array_slice( $list, 0, $max );
	}

	$field = array();
	if ( ! empty( $data['loadingExperience']['metrics'] ) && is_array( $data['loadingExperience']['metrics'] ) ) {
		$field['overall'] = isset( $data['loadingExperience']['overall_category'] ) ? sanitize_key( $data['loadingExperience']['overall_category'] ) : '';
		foreach ( $data['loadingExperience']['metrics'] as $metric => $row ) {
			if ( is_array( $row ) && isset( $row['percentile'] ) ) {
				$field['metrics'][ sanitize_key( $metric ) ] = array(
					'p75'      => (float) $row['percentile'],
					'category' => isset( $row['category'] ) ? sanitize_key( $row['category'] ) : '',
				);
			}
		}
	}

	$url = '';
	foreach ( array( 'finalDisplayedUrl', 'finalUrl', 'requestedUrl' ) as $k ) {
		if ( ! empty( $lh[ $k ] ) ) {
			$url = esc_url_raw( (string) $lh[ $k ] );
			break;
		}
	}

	return array(
		'v'          => 2,
		'strategy'   => $strategy,
		'url'        => $url,
		'fetched'    => time(),
		'lighthouse' => isset( $lh['lighthouseVersion'] ) ? sanitize_text_field( (string) $lh['lighthouseVersion'] ) : '',
		'scores'     => $scores,
		'metrics'    => $metrics,
		'lcp'        => $lcp,
		'field'      => $field,
		'audits'     => $failing,
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// REST
// ═══════════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════════
// PASS / FAIL — the team's PageSpeed sitemap tool criteria
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Pass criteria. Defaults are the sitemap tool's (performance-eval):
 * Performance ≥ 90, Accessibility 100, SEO 100, FCP ≤ 1.8 s, LCP ≤ 2.5 s,
 * CLS ≤ 0.1. Best Practices and TBT are recorded but not enforced (null).
 *
 * @return array<string,int|float|null>
 */
function ekwa_page_audit_thresholds() {
	$defaults = array(
		'performance'    => 90,
		'accessibility'  => 100,
		'best-practices' => null,
		'seo'            => 100,
		'fcp'            => 1.8,
		'lcp'            => 2.5,
		'cls'            => 0.1,
		'tbt'            => null,
	);
	/**
	 * Filter the PASS/FAIL thresholds. Set a key to null to stop enforcing it.
	 *
	 * @param array $defaults
	 */
	$filtered = apply_filters( 'ekwa_page_audit_thresholds', $defaults );
	return is_array( $filtered ) ? array_merge( $defaults, array_intersect_key( $filtered, $defaults ) ) : $defaults;
}

/**
 * A lab metric in seconds (unitless for CLS), from a stored report.
 * Reports saved before 'value' existed fall back to parsing the display text.
 *
 * @param array  $report
 * @param string $id Lighthouse audit id.
 * @return float|null
 */
function ekwa_page_audit_metric_value( $report, $id ) {
	foreach ( isset( $report['metrics'] ) && is_array( $report['metrics'] ) ? $report['metrics'] : array() as $metric ) {
		if ( ! is_array( $metric ) || ! isset( $metric['id'] ) || $metric['id'] !== $id ) {
			continue;
		}
		$is_cls = 'cumulative-layout-shift' === $id;
		if ( isset( $metric['value'] ) && is_numeric( $metric['value'] ) ) {
			return $is_cls ? round( (float) $metric['value'], 3 ) : round( (float) $metric['value'] / 1000, 3 );
		}
		$display = isset( $metric['display'] ) ? str_replace( array( "\xc2\xa0", ',' ), array( ' ', '' ), (string) $metric['display'] ) : '';
		if ( preg_match( '/([0-9]*\.?[0-9]+)\s*(ms|s)?/u', $display, $m ) ) {
			$n = (float) $m[1];
			if ( $is_cls ) {
				return round( $n, 3 );
			}
			return round( isset( $m[2] ) && 'ms' === $m[2] ? $n / 1000 : $n, 3 );
		}
		return null;
	}
	return null;
}

/**
 * Judge a stored report: the sitemap tool's evaluate(), rule for rule.
 * A score check with no score fails ("not measured"); a metric with no value
 * is skipped.
 *
 * @param array|null $report
 * @return array{status:string,issues:array[]} status pass|fail|none
 */
function ekwa_page_audit_evaluate( $report ) {
	if ( ! is_array( $report ) || empty( $report['scores'] ) ) {
		return array( 'status' => 'none', 'issues' => array() );
	}
	$t      = ekwa_page_audit_thresholds();
	$issues = array();

	$labels = array(
		'performance'    => __( 'Performance', 'ekwa' ),
		'accessibility'  => __( 'Accessibility', 'ekwa' ),
		'best-practices' => __( 'Best Practices', 'ekwa' ),
		'seo'            => __( 'SEO', 'ekwa' ),
	);
	foreach ( $labels as $key => $label ) {
		if ( null === $t[ $key ] ) {
			continue;
		}
		$value = isset( $report['scores'][ $key ] ) && is_numeric( $report['scores'][ $key ] ) ? (int) $report['scores'][ $key ] : null;
		if ( null === $value ) {
			$issues[] = array(
				'key'  => $key,
				'text' => sprintf( /* translators: %s: category */ __( '%s: not measured — re-run the test', 'ekwa' ), $label ),
			);
		} elseif ( $value < $t[ $key ] ) {
			$issues[] = array(
				'key'   => $key,
				'value' => $value,
				'limit' => $t[ $key ],
				/* translators: 1: category, 2: score, 3: required score */
				'text'  => sprintf( __( '%1$s: %2$d (required: ≥%3$d)', 'ekwa' ), $label, $value, $t[ $key ] ),
			);
		}
	}

	$metrics = array(
		'fcp' => array( 'first-contentful-paint', 'FCP', 's' ),
		'lcp' => array( 'largest-contentful-paint', 'LCP', 's' ),
		'cls' => array( 'cumulative-layout-shift', 'CLS', '' ),
		'tbt' => array( 'total-blocking-time', 'TBT', 's' ),
	);
	foreach ( $metrics as $key => $def ) {
		if ( null === $t[ $key ] ) {
			continue;
		}
		$value = ekwa_page_audit_metric_value( $report, $def[0] );
		if ( null === $value || $value <= (float) $t[ $key ] ) {
			continue;
		}
		$issues[] = array(
			'key'   => $key,
			'value' => $value,
			'limit' => $t[ $key ],
			/* translators: 1: metric, 2: value, 3: unit, 4: limit */
			'text'  => sprintf( __( '%1$s: %2$s%3$s (required: ≤%4$s%3$s)', 'ekwa' ), $def[1], rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' ), $def[2], $t[ $key ] ),
		);
	}

	return array( 'status' => $issues ? 'fail' : 'pass', 'issues' => $issues );
}

/**
 * The small per-strategy record the dashboard lists.
 *
 * @param array $report
 * @return array
 */
function ekwa_page_audit_summary_from_report( $report ) {
	$eval    = ekwa_page_audit_evaluate( $report );
	$titles  = static function ( $list, $max ) {
		$out = array();
		foreach ( array_slice( is_array( $list ) ? $list : array(), 0, $max ) as $a ) {
			if ( is_array( $a ) && ! empty( $a['title'] ) ) {
				$out[] = array( 'id' => isset( $a['id'] ) ? (string) $a['id'] : '', 'title' => (string) $a['title'] );
			}
		}
		return $out;
	};
	$scores  = isset( $report['scores'] ) && is_array( $report['scores'] ) ? $report['scores'] : array();
	$audits  = isset( $report['audits'] ) && is_array( $report['audits'] ) ? $report['audits'] : array();
	return array(
		't'       => isset( $report['fetched'] ) ? (int) $report['fetched'] : 0,
		'scores'  => array(
			'performance'    => isset( $scores['performance'] ) ? $scores['performance'] : null,
			'accessibility'  => isset( $scores['accessibility'] ) ? $scores['accessibility'] : null,
			'best-practices' => isset( $scores['best-practices'] ) ? $scores['best-practices'] : null,
			'seo'            => isset( $scores['seo'] ) ? $scores['seo'] : null,
		),
		'metrics' => array(
			'fcp' => ekwa_page_audit_metric_value( $report, 'first-contentful-paint' ),
			'lcp' => ekwa_page_audit_metric_value( $report, 'largest-contentful-paint' ),
			'cls' => ekwa_page_audit_metric_value( $report, 'cumulative-layout-shift' ),
			'tbt' => ekwa_page_audit_metric_value( $report, 'total-blocking-time' ),
		),
		'status'  => $eval['status'],
		'issues'  => wp_list_pluck( $eval['issues'], 'text' ),
		'keys'    => wp_list_pluck( $eval['issues'], 'key' ),
		'failing' => array(
			'accessibility' => $titles( isset( $audits['accessibility'] ) ? $audits['accessibility'] : array(), 12 ),
			'seo'           => $titles( isset( $audits['seo'] ) ? $audits['seo'] : array(), 8 ),
			'performance'   => $titles( isset( $audits['performance'] ) ? $audits['performance'] : array(), 6 ),
		),
	);
}

/**
 * A post's summaries (mobile/desktop). Built from the full reports when the
 * summary meta is missing — pages tested before it existed.
 *
 * @param int $post_id
 * @return array{mobile:array|null,desktop:array|null}
 */
function ekwa_page_audit_summaries( $post_id ) {
	$stored = ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_SUMMARY );
	$out    = array();
	foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
		if ( isset( $stored[ $strategy ] ) && is_array( $stored[ $strategy ] ) ) {
			$out[ $strategy ] = $stored[ $strategy ];
			continue;
		}
		$report           = ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_REPORT . $strategy );
		$out[ $strategy ] = $report ? ekwa_page_audit_summary_from_report( $report ) : null;
	}
	return $out;
}

// ═══════════════════════════════════════════════════════════════════════════════
// SITE CHANGES (stylesheet + template parts)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * The stylesheet / template-part change log.
 *
 * @return array[]
 */
function ekwa_page_audit_changes() {
	$log = get_option( EKWA_PAGE_AUDIT_CHANGES_OPTION, array() );
	return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
}

/**
 * Change the log under a lock.
 *
 * @param callable $change Receives the log, returns the new log.
 * @return bool
 */
function ekwa_page_audit_mutate_changes( $change ) {
	$lock = function_exists( 'ekwa_ai_lock' ) ? ekwa_ai_lock( 'page-audit-changes', 5 ) : 'none';
	if ( '' === $lock ) {
		return false;
	}
	try {
		wp_cache_delete( EKWA_PAGE_AUDIT_CHANGES_OPTION, 'options' );
		$log = call_user_func( $change, ekwa_page_audit_changes() );
		if ( count( $log ) > 100 ) {
			$log = array_slice( $log, -100 );
		}
		update_option( EKWA_PAGE_AUDIT_CHANGES_OPTION, array_values( $log ), false );
		return true;
	} finally {
		if ( 'none' !== $lock && function_exists( 'ekwa_ai_unlock' ) ) {
			ekwa_ai_unlock( $lock );
		}
	}
}

/**
 * A change entry as the UI sees it: no patches, plus where it stands now.
 *
 * @param array $entry
 * @return array
 */
function ekwa_page_audit_public_change( $entry ) {
	$out = array(
		'id'       => isset( $entry['id'] ) ? (string) $entry['id'] : '',
		'type'     => isset( $entry['type'] ) ? (string) $entry['type'] : '',
		'time'     => isset( $entry['time'] ) ? (int) $entry['time'] : 0,
		'user'     => isset( $entry['user'] ) ? (string) $entry['user'] : '',
		'post_id'  => isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0,
		'fix_id'   => isset( $entry['fix_id'] ) ? (string) $entry['fix_id'] : '',
		'title'    => isset( $entry['title'] ) ? (string) $entry['title'] : '',
		'category' => isset( $entry['category'] ) ? (string) $entry['category'] : '',
		'undone'   => ! empty( $entry['undone'] ) ? (int) $entry['undone'] : 0,
	);
	$out['verify'] = isset( $entry['verify'] ) && is_array( $entry['verify'] ) ? array(
		'status' => (string) $entry['verify']['status'],
		'detail' => isset( $entry['verify']['detail'] ) ? (string) $entry['verify']['detail'] : '',
		'time'   => isset( $entry['verify']['time'] ) ? (int) $entry['verify']['time'] : 0,
	) : null;
	if ( 'css' === $out['type'] ) {
		$out['css']    = isset( $entry['css'] ) ? $entry['css'] : array();
		$out['detail'] = ekwa_page_audit_css_describe( $out['css'] );
		$out['marker'] = 'ekwa-fix:' . $out['id'];
		$out['state']  = $out['undone'] ? 'undone' : ( ekwa_page_audit_css_entry_applied( $entry ) ? 'applied' : 'changed' );
	} elseif ( 'part' === $out['type'] ) {
		foreach ( array( 'part', 'part_title', 'path', 'block' ) as $key ) {
			$out[ $key ] = isset( $entry[ $key ] ) ? $entry[ $key ] : null;
		}
		// Section CSS changes carry whole stylesheets in before/after: keep the
		// readable description instead.
		if ( ! empty( $entry['css'] ) ) {
			$out['css']    = $entry['css'];
			$out['detail'] = (string) $entry['part_title'] . ' → ' . __( 'Section CSS', 'ekwa' ) . ': ' . ekwa_page_audit_css_describe( $entry['css'] );
		} else {
			$out['before'] = isset( $entry['before'] ) ? $entry['before'] : null;
			$out['after']  = isset( $entry['after'] ) ? $entry['after'] : null;
			$out['detail'] = sprintf( /* translators: 1: template part, 2: block name */ __( '%1$s → %2$s', 'ekwa' ), (string) $out['part_title'], (string) $out['block'] );
		}
		$out['state'] = $out['undone'] ? 'undone' : ekwa_page_audit_parts_entry_state( $entry );
	}
	$out['undoable'] = ! $out['undone'] && in_array( isset( $out['state'] ) ? $out['state'] : '', array( 'applied', 'reverted' ), true );
	return $out;
}

/**
 * Change entries made from one post.
 *
 * @param int $post_id
 * @return array[]
 */
function ekwa_page_audit_changes_for_post( $post_id ) {
	$out = array();
	foreach ( ekwa_page_audit_changes() as $entry ) {
		if ( isset( $entry['post_id'] ) && (int) $entry['post_id'] === (int) $post_id ) {
			$out[] = ekwa_page_audit_public_change( $entry );
		}
	}
	return $out;
}

/**
 * Undo one logged stylesheet / template-part change.
 *
 * @param string $entry_id
 * @return array|WP_Error Public entry.
 */
function ekwa_page_audit_undo_change( $entry_id ) {
	$found = null;
	foreach ( ekwa_page_audit_changes() as $entry ) {
		if ( isset( $entry['id'] ) && $entry['id'] === $entry_id ) {
			$found = $entry;
		}
	}
	if ( ! $found ) {
		return new WP_Error( 'ekwa_page_audit_missing', __( 'That change is no longer in the log.', 'ekwa' ), array( 'status' => 404 ) );
	}
	if ( ! empty( $found['undone'] ) ) {
		return new WP_Error( 'ekwa_page_audit_undone', __( 'That change was already undone.', 'ekwa' ), array( 'status' => 409 ) );
	}

	$lock = function_exists( 'ekwa_ai_lock' ) ? ekwa_ai_lock( 'page-audit-' . ( 'css' === $found['type'] ? 'css' : 'part' ), 5 ) : 'none';
	if ( '' === $lock ) {
		return new WP_Error( 'ekwa_page_audit_busy', __( 'Busy — try again in a moment.', 'ekwa' ), array( 'status' => 503 ) );
	}
	try {
		if ( 'css' === $found['type'] ) {
			$result = ekwa_page_audit_css_undo_entry( $found );
		} elseif ( 'part' === $found['type'] ) {
			$result = ekwa_page_audit_parts_undo_entry( $found );
		} else {
			$result = new WP_Error( 'ekwa_page_audit_entry', __( 'Invalid change record.', 'ekwa' ), array( 'status' => 400 ) );
		}
	} finally {
		if ( 'none' !== $lock && function_exists( 'ekwa_ai_unlock' ) ) {
			ekwa_ai_unlock( $lock );
		}
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$user = ekwa_page_audit_user_name();
	ekwa_page_audit_mutate_changes( static function ( $log ) use ( $entry_id, $user, &$found ) {
		foreach ( $log as $i => $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === $entry_id ) {
				$log[ $i ]['undone']    = time();
				$log[ $i ]['undone_by'] = $user;
				$found                  = $log[ $i ];
			}
		}
		return $log;
	} );
	ekwa_page_audit_purge_caches();
	return ekwa_page_audit_public_change( $found );
}

/**
 * Purge common page caches after a site-wide change, so the next PageSpeed
 * test (and every visitor) sees it. Only on an explicit apply/undo.
 */
function ekwa_page_audit_purge_caches() {
	/**
	 * Filter whether the page audit purges page caches after a change.
	 *
	 * @param bool $purge
	 */
	if ( ! apply_filters( 'ekwa_page_audit_purge_caches', true ) ) {
		return;
	}
	do_action( 'litespeed_purge_all' );
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
	}
	if ( class_exists( 'autoptimizeCache' ) && is_callable( array( 'autoptimizeCache', 'clearall' ) ) ) {
		autoptimizeCache::clearall();
	}
	do_action( 'ekwa_page_audit_caches_purged' );
}

/**
 * Request the page once before testing it, so a page cache emptied by a fix
 * is warm again — PageSpeed should measure what visitors get, not a cold
 * cache miss. A failure here is harmless.
 *
 * @param string $url
 */
function ekwa_page_audit_warm( $url ) {
	if ( ! apply_filters( 'ekwa_page_audit_warm_cache', true, $url ) ) {
		return;
	}
	wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 3, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
}

/**
 * A fix from a post's stored analysis.
 *
 * @param int    $post_id
 * @param string $strategy
 * @param string $fix_id
 * @return array|null
 */
function ekwa_page_audit_find_fix( $post_id, $strategy, $fix_id ) {
	$analysis = ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_AI . ekwa_page_audit_strategy( $strategy ) );
	foreach ( isset( $analysis['fixes'] ) && is_array( $analysis['fixes'] ) ? $analysis['fixes'] : array() as $fix ) {
		if ( is_array( $fix ) && isset( $fix['id'] ) && $fix['id'] === $fix_id ) {
			return $fix;
		}
	}
	return null;
}

/**
 * The active (not undone) change entry recorded for a fix, if any.
 *
 * @param string $fix_id
 * @return array|null Raw entry.
 */
function ekwa_page_audit_change_for_fix( $fix_id ) {
	$found = null;
	foreach ( ekwa_page_audit_changes() as $entry ) {
		if ( isset( $entry['fix_id'] ) && $entry['fix_id'] === $fix_id && empty( $entry['undone'] ) ) {
			$found = $entry;
		}
	}
	return $found;
}

add_action( 'rest_api_init', 'ekwa_page_audit_register_routes' );

/**
 * Register the audit's REST routes. All live under ekwa/v1, so the MCP relay
 * client tags them; only /analyze ever makes an AI call (and is relayed).
 */
function ekwa_page_audit_register_routes() {
	$post_arg = array(
		'post_id' => array( 'required' => true, 'type' => 'integer' ),
	);

	register_rest_route( 'ekwa/v1', '/page-audit/state', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'ekwa_page_audit_rest_state',
		'permission_callback' => 'ekwa_page_audit_can_edit',
		'args'                => $post_arg,
	) );

	register_rest_route( 'ekwa/v1', '/page-audit/run', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_page_audit_rest_run',
		'permission_callback' => 'ekwa_page_audit_can_edit',
		'args'                => $post_arg + array(
			'strategy' => array( 'required' => false, 'type' => 'string', 'default' => 'mobile' ),
		),
	) );

	register_rest_route( 'ekwa/v1', '/page-audit/analyze', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_page_audit_rest_analyze',
		'permission_callback' => 'ekwa_page_audit_can_analyze',
		'args'                => $post_arg + array(
			'strategy' => array( 'required' => false, 'type' => 'string', 'default' => 'mobile' ),
			'focus'    => array( 'required' => false, 'type' => 'string', 'default' => 'all' ),
			'model'    => array( 'required' => false, 'type' => 'string', 'default' => '' ),
			'blocks'   => array( 'required' => false, 'type' => 'array', 'default' => array() ),
			'schemas'  => array( 'required' => false, 'type' => 'object', 'default' => array() ),
		),
	) );

	register_rest_route( 'ekwa/v1', '/page-audit/apply-setting', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_page_audit_rest_apply_setting',
		'permission_callback' => 'ekwa_page_audit_can_manage',
		'args'                => $post_arg + array(
			'fix_id'  => array( 'required' => false, 'type' => 'string', 'default' => '' ),
			'title'   => array( 'required' => false, 'type' => 'string', 'default' => '' ),
			'setting' => array( 'required' => true, 'type' => 'string' ),
			'value'   => array( 'required' => true, 'type' => array( 'string', 'boolean', 'integer' ) ),
		),
	) );

	register_rest_route( 'ekwa/v1', '/page-audit/undo-setting', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_page_audit_rest_undo_setting',
		'permission_callback' => 'ekwa_page_audit_can_manage',
		'args'                => array(
			'entry_id' => array( 'required' => true, 'type' => 'string' ),
		),
	) );

	register_rest_route( 'ekwa/v1', '/page-audit/history', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_page_audit_rest_history',
		'permission_callback' => 'ekwa_page_audit_can_edit',
		'args'                => $post_arg + array(
			'op'       => array( 'required' => true, 'type' => 'string' ),
			'entry'    => array( 'required' => false, 'type' => 'object', 'default' => array() ),
			'entry_id' => array( 'required' => false, 'type' => 'string', 'default' => '' ),
		),
	) );

	// Stylesheet / template-part fixes: the client names the fix; the change
	// itself is read from the analysis stored on the server, never from the
	// request.
	$fix_args = $post_arg + array(
		'strategy' => array( 'required' => false, 'type' => 'string', 'default' => 'mobile' ),
		'fix_id'   => array( 'required' => true, 'type' => 'string' ),
	);
	register_rest_route( 'ekwa/v1', '/page-audit/apply-css', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_page_audit_rest_apply_css',
		'permission_callback' => 'ekwa_page_audit_can_manage',
		'args'                => $fix_args,
	) );
	register_rest_route( 'ekwa/v1', '/page-audit/apply-part', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_page_audit_rest_apply_part',
		'permission_callback' => 'ekwa_page_audit_can_manage_parts',
		'args'                => $fix_args,
	) );
	// Section CSS of a block in the page body: the editor sends the block's
	// current scopedCss, the server runs the CSS engine and returns the new
	// text; the editor applies it as a block change (its own undo + history).
	register_rest_route( 'ekwa/v1', '/page-audit/css-compute', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_page_audit_rest_css_compute',
		'permission_callback' => 'ekwa_page_audit_can_edit',
		'args'                => $fix_args + array(
			'css' => array( 'required' => true, 'type' => 'string' ),
		),
	) );
	register_rest_route( 'ekwa/v1', '/page-audit/undo-change', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_page_audit_rest_undo_change',
		'permission_callback' => 'ekwa_page_audit_can_manage',
		'args'                => array(
			'entry_id' => array( 'required' => true, 'type' => 'string' ),
		),
	) );
}

/**
 * Permission for template-part changes: site-wide and theme-level.
 *
 * @param WP_REST_Request $request
 * @return bool
 */
function ekwa_page_audit_can_manage_parts( $request ) {
	return current_user_can( 'manage_options' ) && current_user_can( 'edit_theme_options' );
}

/**
 * POST /page-audit/apply-css.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_page_audit_rest_apply_css( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	$fix     = ekwa_page_audit_find_fix( $post_id, (string) $request->get_param( 'strategy' ), (string) $request->get_param( 'fix_id' ) );
	if ( ! $fix || 'css' !== ( isset( $fix['kind'] ) ? $fix['kind'] : '' ) || empty( $fix['css'] ) ) {
		return new WP_Error( 'ekwa_page_audit_fix', __( 'That fix is not in the latest analysis any more. Analyze again.', 'ekwa' ), array( 'status' => 404 ) );
	}
	if ( ekwa_page_audit_change_for_fix( $fix['id'] ) ) {
		return new WP_Error( 'ekwa_page_audit_same', __( 'This fix is already applied.', 'ekwa' ), array( 'status' => 409 ) );
	}
	$entry = ekwa_page_audit_css_apply_fix( $post_id, $fix );
	if ( is_wp_error( $entry ) ) {
		return $entry;
	}
	return rest_ensure_response( array( 'entry' => $entry ) );
}

/**
 * POST /page-audit/css-compute — new Section CSS for a page-body block fix.
 * Pure: nothing is saved here.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_page_audit_rest_css_compute( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	$fix     = ekwa_page_audit_find_fix( $post_id, (string) $request->get_param( 'strategy' ), (string) $request->get_param( 'fix_id' ) );
	if ( ! $fix || 'css' !== ( isset( $fix['kind'] ) ? $fix['kind'] : '' ) || ! isset( $fix['css']['in']['type'] ) || 'block' !== $fix['css']['in']['type'] ) {
		return new WP_Error( 'ekwa_page_audit_fix', __( 'That fix is not in the latest analysis any more. Analyze again.', 'ekwa' ), array( 'status' => 404 ) );
	}
	$marker = 'ekwa-fix-' . ekwa_page_audit_id();
	$result = ekwa_page_audit_css_apply(
		array( 'model' => 'scoped', 'rules' => 'scoped', 'vars' => 'scoped' ),
		array( 'scoped' => (string) $request->get_param( 'css' ) ),
		array_merge( $fix['css'], array( 'marker_style' => 'prop', 'vars' => array() ) ),
		$marker
	);
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'ekwa_page_audit_css_changed', __( 'The block’s Section CSS changed since the analysis, so the fix no longer fits. Analyze again.', 'ekwa' ), array( 'status' => 409 ) );
	}
	return rest_ensure_response( array( 'css' => $result['texts']['scoped'], 'marker' => '--' . $marker ) );
}

/**
 * POST /page-audit/apply-part.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_page_audit_rest_apply_part( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	$fix     = ekwa_page_audit_find_fix( $post_id, (string) $request->get_param( 'strategy' ), (string) $request->get_param( 'fix_id' ) );
	if ( ! $fix || 'part' !== ( isset( $fix['kind'] ) ? $fix['kind'] : '' ) || empty( $fix['part'] ) ) {
		return new WP_Error( 'ekwa_page_audit_fix', __( 'That fix is not in the latest analysis any more. Analyze again.', 'ekwa' ), array( 'status' => 404 ) );
	}
	if ( ekwa_page_audit_change_for_fix( $fix['id'] ) ) {
		return new WP_Error( 'ekwa_page_audit_same', __( 'This fix is already applied.', 'ekwa' ), array( 'status' => 409 ) );
	}
	$entry = ekwa_page_audit_parts_apply_fix( $post_id, $fix );
	if ( is_wp_error( $entry ) ) {
		return $entry;
	}
	return rest_ensure_response( array( 'entry' => $entry ) );
}

/**
 * POST /page-audit/undo-change.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_page_audit_rest_undo_change( $request ) {
	$entry = ekwa_page_audit_undo_change( sanitize_text_field( (string) $request->get_param( 'entry_id' ) ) );
	if ( is_wp_error( $entry ) ) {
		return $entry;
	}
	return rest_ensure_response( array( 'entry' => $entry ) );
}

/**
 * Permission: may edit the post named by post_id.
 *
 * @param WP_REST_Request $request
 * @return bool
 */
function ekwa_page_audit_can_edit( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	return $post_id > 0 && current_user_can( 'edit_post', $post_id );
}

/**
 * Permission for the AI route: edit the post AND pass the shared AI gate
 * (role, daily cap, payload ceiling).
 *
 * @param WP_REST_Request $request
 * @return true|WP_Error
 */
function ekwa_page_audit_can_analyze( $request ) {
	if ( ! ekwa_page_audit_can_edit( $request ) ) {
		return new WP_Error( 'rest_forbidden', __( 'You cannot edit this page.', 'ekwa' ), array( 'status' => 403 ) );
	}
	return function_exists( 'ekwa_ai_rest_permission' ) ? ekwa_ai_rest_permission() : true;
}

/**
 * Permission for setting changes: they are site-wide, so the same capability
 * the Ekwa Settings screen requires.
 *
 * @param WP_REST_Request $request
 * @return bool
 */
function ekwa_page_audit_can_manage( $request ) {
	return current_user_can( 'manage_options' );
}

/**
 * Score history entries for a post.
 *
 * @param int $post_id
 * @return array[]
 */
function ekwa_page_audit_scores( $post_id ) {
	return array_values( array_filter( ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_SCORES ), 'is_array' ) );
}

/**
 * Setting-log entries made from one post.
 *
 * @param int $post_id
 * @return array[]
 */
function ekwa_page_audit_log_for_post( $post_id ) {
	$out = array();
	foreach ( ekwa_page_audit_log() as $entry ) {
		if ( isset( $entry['post_id'] ) && (int) $entry['post_id'] === (int) $post_id ) {
			$out[] = ekwa_page_audit_public_log_entry( $entry );
		}
	}
	return $out;
}

/**
 * A log entry as the sidebar sees it (with the setting's label and whether it
 * can still be undone).
 *
 * @param array $entry
 * @return array
 */
function ekwa_page_audit_public_log_entry( $entry ) {
	$registry = ekwa_page_audit_settings_registry();
	$key      = isset( $entry['setting'] ) ? (string) $entry['setting'] : '';
	$def      = isset( $registry[ $key ] ) ? $registry[ $key ] : null;
	$current  = $def ? ekwa_page_audit_current( $key, $def ) : null;

	$entry['label']     = $def ? (string) $def['label'] : $key;
	$entry['undoable']  = $def && empty( $entry['undone'] ) && $current === ( isset( $entry['new'] ) ? $entry['new'] : null );
	$entry['current']   = $current;
	return $entry;
}

/**
 * GET /page-audit/state — everything the sidebar shows on open.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_page_audit_rest_state( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	$post    = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'rest_post_invalid_id', __( 'Page not found.', 'ekwa' ), array( 'status' => 404 ) );
	}

	$reports    = array();
	$analysis   = array();
	$evaluation = array();
	foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
		$report                  = ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_REPORT . $strategy );
		$ai                      = ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_AI . $strategy );
		$reports[ $strategy ]    = $report ? $report : null;
		$analysis[ $strategy ]   = $ai ? $ai : null;
		$evaluation[ $strategy ] = $report ? ekwa_page_audit_evaluate( $report ) : null;
	}
	$target = ekwa_page_audit_css_target();

	return rest_ensure_response( array(
		'reports'    => $reports,
		'analysis'   => $analysis,
		'evaluation' => $evaluation,
		'thresholds' => ekwa_page_audit_thresholds(),
		'scores'     => ekwa_page_audit_scores( $post_id ),
		'history'    => array_values( ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_HISTORY ) ),
		'log'        => ekwa_page_audit_log_for_post( $post_id ),
		'changes'    => ekwa_page_audit_changes_for_post( $post_id ),
		'settings'   => ekwa_page_audit_settings_snapshot(),
		'cssTarget'  => array( 'model' => $target['model'], 'label' => $target['label'] ),
		'psiKey'     => '' !== ekwa_page_audit_psi_key(),
	) );
}

/**
 * POST /page-audit/run — test the published page with PageSpeed Insights and
 * store the compact report. No AI call.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_page_audit_rest_run( $request ) {
	$post_id  = (int) $request->get_param( 'post_id' );
	$strategy = ekwa_page_audit_strategy( $request->get_param( 'strategy' ) );
	$post     = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'rest_post_invalid_id', __( 'Page not found.', 'ekwa' ), array( 'status' => 404 ) );
	}

	$url = ekwa_page_audit_test_url( $post );
	if ( is_wp_error( $url ) ) {
		return $url;
	}

	ekwa_page_audit_warm( $url );
	$data = ekwa_page_audit_fetch_psi( $url, $strategy );
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$report = ekwa_page_audit_compact( $data, $strategy );
	if ( null === $report['scores']['performance'] && null === $report['scores']['accessibility'] ) {
		return new WP_Error( 'ekwa_page_audit_empty', __( 'PageSpeed Insights returned no scores for this page. Try again in a minute.', 'ekwa' ), array( 'status' => 502 ) );
	}

	ekwa_page_audit_put_meta( $post_id, EKWA_PAGE_AUDIT_META_REPORT . $strategy, $report );

	$scores   = ekwa_page_audit_scores( $post_id );
	$scores[] = array(
		't' => $report['fetched'],
		's' => $strategy,
		'p' => $report['scores']['performance'],
		'a' => $report['scores']['accessibility'],
		'o' => $report['scores']['seo'],
		'b' => $report['scores']['best-practices'],
	);
	if ( count( $scores ) > 30 ) {
		$scores = array_slice( $scores, -30 );
	}
	ekwa_page_audit_put_meta( $post_id, EKWA_PAGE_AUDIT_META_SCORES, $scores );

	$summary              = ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_SUMMARY );
	$summary[ $strategy ] = ekwa_page_audit_summary_from_report( $report );
	ekwa_page_audit_put_meta( $post_id, EKWA_PAGE_AUDIT_META_SUMMARY, $summary );

	return rest_ensure_response( array(
		'report'     => $report,
		'scores'     => $scores,
		'evaluation' => ekwa_page_audit_evaluate( $report ),
		'summary'    => $summary[ $strategy ],
	) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// AI ANALYSIS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Sanitize the block outline the editor sends: [{path, name, attrs, note?}].
 *
 * @param mixed $blocks
 * @return array{list:array[],index:array<string,string>}
 */
function ekwa_page_audit_sanitize_outline( $blocks ) {
	$list    = array();
	$index   = array();
	$css_map = array();
	$budget = 160000; // bytes of JSON sent to the model for the block tree.

	foreach ( (array) $blocks as $block ) {
		if ( count( $list ) >= 600 || ! is_array( $block ) ) {
			break;
		}
		$path = isset( $block['path'] ) ? (string) $block['path'] : '';
		$name = isset( $block['name'] ) ? (string) $block['name'] : '';
		if ( ! preg_match( '/^\d{1,4}(\.\d{1,4}){0,20}$/', $path ) || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ) {
			continue;
		}
		$row = array(
			'path' => $path,
			'name' => $name,
		);
		if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			$row['attrs'] = $block['attrs'];
		}
		if ( ! empty( $block['note'] ) && is_string( $block['note'] ) ) {
			$row['note'] = ekwa_page_audit_text( $block['note'], 120 );
		}
		// Full Section CSS (scopedCss) — editable by "css" fixes, so the model
		// needs it whole (attrs only carry the first 220 characters).
		if ( ! empty( $block['css'] ) && is_string( $block['css'] ) && strlen( $block['css'] ) <= 40000 ) {
			$row['css']            = $block['css'];
			$css_map[ $path ]      = $block['css'];
		}
		$size = strlen( (string) wp_json_encode( $row ) );
		if ( $size > $budget ) {
			unset( $row['attrs'] );
			$row['note'] = 'attributes omitted (page too large)';
			$size        = strlen( (string) wp_json_encode( $row ) );
		}
		$budget -= $size;
		if ( $budget < 0 ) {
			break;
		}
		$list[]         = $row;
		$index[ $path ] = $name;
	}

	return array( 'list' => $list, 'index' => $index, 'css' => $css_map );
}

/**
 * The site's colour palette (theme + custom), for colour-contrast fixes.
 *
 * @return array[] [{slug, name, color}]
 */
function ekwa_page_audit_palette() {
	if ( ! function_exists( 'wp_get_global_settings' ) ) {
		return array();
	}
	$palette = wp_get_global_settings( array( 'color', 'palette' ) );
	$out     = array();
	foreach ( array( 'custom', 'theme' ) as $origin ) {
		if ( empty( $palette[ $origin ] ) || ! is_array( $palette[ $origin ] ) ) {
			continue;
		}
		foreach ( $palette[ $origin ] as $entry ) {
			if ( isset( $entry['slug'], $entry['color'] ) ) {
				$out[] = array(
					'slug'  => sanitize_key( $entry['slug'] ),
					'name'  => isset( $entry['name'] ) ? sanitize_text_field( $entry['name'] ) : '',
					'color' => sanitize_text_field( $entry['color'] ),
				);
			}
		}
	}
	return array_slice( $out, 0, 40 );
}

/**
 * Site context for the model: theme, child theme and active plugin names.
 *
 * @return array
 */
function ekwa_page_audit_site_context() {
	$theme   = wp_get_theme();
	$plugins = array();
	if ( ! function_exists( 'get_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( function_exists( 'get_plugins' ) ) {
		$all = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( isset( $all[ $file ]['Name'] ) ) {
				$plugins[] = sanitize_text_field( $all[ $file ]['Name'] );
			}
		}
	}
	return array(
		'theme'       => sanitize_text_field( $theme->get( 'Name' ) ),
		'parentTheme' => $theme->parent() ? sanitize_text_field( $theme->parent()->get( 'Name' ) ) : '',
		'plugins'     => array_slice( $plugins, 0, 60 ),
	);
}

/**
 * The model's instructions.
 *
 * @param string $focus 'all', 'performance' or 'accessibility'.
 * @return string
 */
function ekwa_page_audit_system_prompt( $focus ) {
	$names      = array( 'performance' => 'Performance', 'accessibility' => 'Accessibility', 'seo' => 'SEO' );
	$focus_line = isset( $names[ $focus ] )
		? 'Cover ONLY ' . $names[ $focus ] . ' issues.'
		: 'Cover Performance, Accessibility and SEO.';
	$css_props  = implode( ', ', ekwa_page_audit_css_properties() );

	return <<<PROMPT
You are a senior web performance, accessibility and SEO engineer working on a WordPress site built on the "Ekwa" block theme. You receive a Google PageSpeed Insights (Lighthouse) report for ONE published page, the page's block tree as it is in the editor now (BLOCKS), the template parts the page's template renders around it (PARTS: header, footer…), the site stylesheet (STYLESHEET) with its CSS custom properties (TOKENS), the theme's performance SETTINGS with their current values, the colour PALETTE, and basic SITE info. Explain what holds the scores back and propose concrete fixes the site owner can apply with one click, plus manual steps for what cannot be applied automatically. {$focus_line}

FIX KINDS
1. "setting" — change ONE Ekwa setting from SETTINGS: only keys listed there, only to one of its allowed values, and only to a value different from "current". Settings are SITE-WIDE (every page changes), so use them for problems that come from the theme's global output, and say "site-wide" in "why". The setting's "risk" is shown to the person automatically — do not repeat it; use "visualNote" only for other visible effects.
2. "block" — change attributes of ONE block in BLOCKS, addressed by its "path" (copy it exactly) and "block" (must equal that block's "name"). Only use attribute names that exist in that block type's SCHEMA. Never change attributes marked "richText" (their text is truncated in BLOCKS) — make such a fix "manual". "attrs" holds only the attributes you change, with complete new values; for an object attribute such as "style" give only the nested keys you change (they are merged into the existing object).
3. "part" — change attributes of ONE block inside a template part from PARTS: "part" (the part's slug), "path" (copy it exactly from that part's blocks) and "block" (must equal that block's "name"). Only parts with "editable": true, and only attributes that exist in PART_SCHEMA for that block and are NOT marked "html" (those live in the block's HTML). Same "attrs" rules as "block". A template part is shared by every page, so say "site-wide" in "why".
4. "css" — change the site stylesheet (STYLESHEET), which styles every page including the header and footer. Use it for colour contrast and other visual accessibility problems (focus outlines, link underlines, small text, target size) wherever the element is. Give "css": {"edits": [{"selector": "", "media": "", "property": "", "from": "", "to": ""}], "vars": [{"name": "--token-name", "value": "#rrggbb"}]} and a "note" of 2-6 words for the stylesheet comment (e.g. "text contrast 2.9:1 → 4.8:1").
   - "selector" and "media" are copied EXACTLY from a rule in STYLESHEET ("media" = the enclosing @media/@supports prelude, or ""). "from" = the value that rule has now, copied exactly. To add a property the rule does not set, use "from": "". If no rule targets the element, give a new, specific selector with "from": "" — it is appended at the end of the stylesheet, so it must be at least as specific as the rule it overrides.
   - Edit the rule that actually sets the failing element's style. If that rule also styles elements that pass, add a more specific rule for the failing element instead.
   - COLOURS: the site's colours are CSS variables listed in TOKENS — write colours as var(--token). First pick an EXISTING token that reaches the required contrast and fits the design (same hue family). Only if none does, add exactly ONE new token in "vars", named after the token it derives from (e.g. --gold → --gold-ink or --gold-dark, --slate → --slate-strong), with a hex value that keeps the original hue and changes lightness only as much as needed — then use var(--that-name). Never change an existing token's value (that recolours the whole site), and never write a raw hex in "to" where the rule used a variable.
   - Allowed properties: {$css_props}. Never url(), @import, comments, or values containing { } ; < >. Add !important only if the original declaration had it.
   - The old declaration is kept in a comment automatically; do not add comments yourself.
   - WHERE THE CSS LIVES: besides STYLESHEET, blocks have their own Section CSS — the "css" field of a block in BLOCKS (page body) or in PARTS (template parts). To edit a Section CSS instead of STYLESHEET, add "in": {"block": "path"} (page body) or "in": {"part": "slug", "path": "path"} (template part) to "css"; "selector"/"media"/"from" are then copied from that block's "css" text. Edit the rule where it actually lives. New tokens ("vars") are only added to STYLESHEET; in Section CSS write an existing var(--token).
   - THE CASCADE: Section CSS and every block's own CSS print AFTER the site stylesheet, so a STYLESHEET rule only shows if it is MORE specific than any rule that sets the same property on that element in OTHER_CSS or in a block's Section CSS (equal specificity loses). Read OTHER_CSS before choosing a selector — e.g. to override `.ekwa-breadcrumb a {text-decoration:none}` use something like `.ekwa-breadcrumb .ekwa-breadcrumb__list a`. Every css fix is checked against the real page and strengthened automatically if it would lose, but pick a winning selector yourself.
5. "manual" — everything else: third-party scripts, server, hosting, caching, image files that need re-exporting, SEO fields of an SEO plugin, attributes stored in a block's HTML. Put short, specific numbered steps in "steps".

RULES
- Base every fix on an audit in REPORT and put that audit's id in "audit". Do not invent problems or repeat one problem as several fixes.
- BLOCKS is only the page body. Elements of the header, footer or other template parts are in PARTS — fix them with "part" (block attributes) or "css" (visual). Blocks in BLOCKS with a "note" saying they are synced patterns or template parts must not be changed with "block".
- Match report nodes (selector, snippet, label) to blocks by className, anchor, text, image URL/alt or position. If you cannot identify the block with confidence, make the fix "manual" instead of guessing a path.
- Colour contrast: reach 4.5:1 (3:1 for text at least 24px, or at least 18.66px bold), reading the foreground, background and font size from the audit item's explanation. Prefer a "css" fix (see COLOURS). Use a "block"/"part" colour attribute only when the colour is set on that block itself (style.color, textColor or the block's own colour attribute). Always fill "colors" with before/after values and the colour they are measured against ("against"), set "visual": true and describe the visible change in "visualNote".
- SEO: document title, meta description, link text, crawlable links, image alt, hreflang, robots and canonical audits. Most are "manual" (SEO plugin fields, page settings) unless a block or part attribute fixes them.
- PREVIOUS lists fixes already applied from this page: "still_failing": true means its audit still fails in the latest test, "live_check" says whether a CSS change really takes effect on the page. Never propose the same change again; if a previous fix did not work, fix the reason (a stronger selector, the overriding rule, a different property) or explain in a "manual" fix.
- Image alt text: concise and specific, from the file name, caption, nearby text and the page topic. Never "image of". Use "" only for an image inside a link or button that already has its own text.
- Link and button names: say where the link goes or what the button does. Never "click here" or "read more" alone.
- Never weaken accessibility to gain performance or the reverse. Never remove content or blocks.
- Do not propose a change that is already in effect.
- Set "visual": true for anything a visitor would notice (colour, size, layout, content that now appears later) and explain it in "visualNote".
- Order fixes by expected score impact, highest first. At most 25 fixes.

THEME KNOWLEDGE — map audits to these first
- LCP element is an ekwa/image: set "hero": true on that block (it is then preloaded with fetchpriority="high" and never lazy-loaded). Only the LCP image should be a hero; set "hero": false on any other block that has it.
- LCP is the poster of an ekwa/video: "fetchPriorityHigh": true. An above-the-fold ekwa/video without a poster: "videoFetchPriorityHigh": true.
- LCP is an ekwa/youtube-video or ekwa/vimeo-video thumbnail: "fetchPriorityHigh": true on that block.
- LCP is the inner-banner featured image (a CSS background): setting ekwa_perf_preload_banner.
- LCP is the site logo (a core/site-logo block, usually in a header template part): a "part" fix setting "ekwaFetchPriority": true on that core/site-logo block (it is then preloaded with fetchpriority="high" and never lazy-loaded). Use the part the tested device shows — on mobile that is the mobile header part (e.g. "mobile-header") when PARTS has one, on desktop the main "header". If the logo is in BLOCKS instead, use a "block" fix with the same attribute.
- Offscreen images loading early: block "loading": "lazy" (ekwa/image), or setting ekwa_perf_lazy_mode. Never lazy-load the LCP image.
- Render-blocking or unused CSS: ekwa_perf_inline_child_css, ekwa_perf_minify_child_css, ekwa_perf_defer_fa_mobile (Font Awesome), ekwa_perf_defer_mmenu (mobile menu). Critical CSS is manual (Ekwa Settings → Performance → Critical CSS).
- Unminified CSS/JS: ekwa_perf_minify_inline, ekwa_perf_minify_child_css.
- Font display / late web fonts: ekwa_perf_preload_fonts.
- Unsized images / layout shift: ekwa_perf_image_dimensions.
- Old image formats / oversized images: ekwa_webp_enabled, ekwa_perf_srcset; regenerating WebP for existing media is manual (Ekwa Settings → Performance → Regenerate WebP).
- Third-party JS / main-thread work: ekwa/share-button "loadOn": "interaction"; ekwa/map "lazyLoad": true; setting ekwa_analytics_location "delay"; setting ekwa_perf_lean_head.

OUTPUT
Return ONLY one JSON object, no Markdown fences, no text around it:
{"summary": "2-4 plain-language sentences for the site owner",
 "fixes": [{
   "id": "short-kebab-case-id",
   "category": "performance" | "accessibility" | "seo",
   "audit": "lighthouse-audit-id",
   "title": "imperative, max 80 characters",
   "why": "1-3 sentences: what is wrong and what the change does",
   "impact": "high" | "medium" | "low",
   "kind": "setting" | "block" | "part" | "css" | "manual",
   "setting": "option_key", "value": true,
   "path": "0.2.1", "block": "core/paragraph", "attrs": {},
   "part": "header",
   "css": {"in": {"part": "header", "path": "0"}, "edits": [{"selector": ".x", "media": "", "property": "color", "from": "var(--a)", "to": "var(--b)"}], "vars": []},
   "note": "short stylesheet comment",
   "steps": "1. … 2. …",
   "visual": false, "visualNote": "",
   "colors": [{"role": "text" | "background" | "border" | "icon", "before": "#rrggbb", "after": "#rrggbb", "against": "#rrggbb"}]
 }]}
Include "setting"/"value" only for kind "setting"; "path"/"block"/"attrs" only for kinds "block" and "part" ("part" also needs "part"); "css"/"note" only for kind "css"; "steps" only for kind "manual"; "colors" only for colour changes.
PROMPT;
}

/**
 * Validate a hex colour; returns '#rrggbb' or ''.
 *
 * @param mixed $value
 * @return string
 */
function ekwa_page_audit_hex( $value ) {
	$value = strtolower( trim( (string) $value ) );
	if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $m ) ) {
		return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
	}
	if ( preg_match( '/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $value, $m ) ) {
		return '#' . $m[1];
	}
	return '';
}

/**
 * Check the model's answer against what may actually be applied, and reshape
 * anything that cannot be into a manual step rather than dropping it.
 *
 * @param mixed  $decoded Decoded JSON answer.
 * @param array  $index   path => block name, from the outline that was sent.
 * @param string $stamp   Prefix that makes fix ids unique across analyses.
 * @param array  $ctx     Optional: 'parts' => ekwa_page_audit_parts_context()
 *                        result, 'css' => bool (a stylesheet was sent).
 * @return array{summary:string,fixes:array[]}
 */
function ekwa_page_audit_validate_fixes( $decoded, $index, $stamp, $ctx = array() ) {
	$registry = ekwa_page_audit_settings_registry();
	$summary  = is_array( $decoded ) && isset( $decoded['summary'] ) ? sanitize_textarea_field( (string) $decoded['summary'] ) : '';
	$raw      = is_array( $decoded ) && isset( $decoded['fixes'] ) && is_array( $decoded['fixes'] ) ? $decoded['fixes'] : array();
	$fixes    = array();
	$seen     = array();

	foreach ( $raw as $n => $fix ) {
		if ( count( $fixes ) >= 30 || ! is_array( $fix ) ) {
			continue;
		}

		$base = isset( $fix['id'] ) ? sanitize_key( (string) $fix['id'] ) : '';
		$base = '' !== $base ? substr( $base, 0, 40 ) : 'fix-' . ( $n + 1 );
		$id   = $stamp . '-' . $base;
		while ( isset( $seen[ $id ] ) ) {
			$id .= 'x';
		}
		$seen[ $id ] = true;

		$kind = isset( $fix['kind'] ) && in_array( $fix['kind'], array( 'setting', 'block', 'part', 'css', 'manual' ), true ) ? $fix['kind'] : 'manual';
		$out  = array(
			'id'         => $id,
			'category'   => isset( $fix['category'] ) && in_array( $fix['category'], array( 'accessibility', 'seo' ), true ) ? $fix['category'] : 'performance',
			'audit'      => isset( $fix['audit'] ) ? substr( preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $fix['audit'] ) ), 0, 80 ) : '',
			'title'      => isset( $fix['title'] ) ? ekwa_page_audit_text( $fix['title'], 120 ) : '',
			'why'        => isset( $fix['why'] ) ? sanitize_textarea_field( substr( (string) $fix['why'], 0, 900 ) ) : '',
			'impact'     => isset( $fix['impact'] ) && in_array( $fix['impact'], array( 'high', 'medium', 'low' ), true ) ? $fix['impact'] : 'medium',
			'kind'       => $kind,
			'visual'     => ! empty( $fix['visual'] ),
			'visualNote' => isset( $fix['visualNote'] ) ? sanitize_textarea_field( substr( (string) $fix['visualNote'], 0, 500 ) ) : '',
		);
		if ( '' === $out['title'] ) {
			continue;
		}

		if ( ! empty( $fix['colors'] ) && is_array( $fix['colors'] ) ) {
			$colors = array();
			foreach ( array_slice( $fix['colors'], 0, 6 ) as $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$before  = ekwa_page_audit_hex( isset( $c['before'] ) ? $c['before'] : '' );
				$after   = ekwa_page_audit_hex( isset( $c['after'] ) ? $c['after'] : '' );
				$against = ekwa_page_audit_hex( isset( $c['against'] ) ? $c['against'] : '' );
				if ( '' === $before || '' === $after ) {
					continue;
				}
				$colors[] = array(
					'role'    => isset( $c['role'] ) && in_array( $c['role'], array( 'text', 'background', 'border', 'icon' ), true ) ? $c['role'] : 'text',
					'before'  => $before,
					'after'   => $after,
					'against' => $against,
				);
			}
			if ( $colors ) {
				$out['colors'] = $colors;
				$out['visual'] = true;
			}
		}

		$manual_reason = '';

		if ( 'setting' === $kind ) {
			$key = isset( $fix['setting'] ) ? (string) $fix['setting'] : '';
			if ( ! isset( $registry[ $key ] ) ) {
				$manual_reason = sprintf( /* translators: %s: option name */ __( 'This needs a change the audit cannot make automatically (%s).', 'ekwa' ), sanitize_key( $key ) );
			} else {
				$value = ekwa_page_audit_coerce( $registry[ $key ], isset( $fix['value'] ) ? $fix['value'] : null );
				if ( null === $value ) {
					$manual_reason = sprintf( /* translators: %s: setting label */ __( 'The suggested value is not one "%s" accepts.', 'ekwa' ), $registry[ $key ]['label'] );
				} elseif ( $value === ekwa_page_audit_current( $key, $registry[ $key ] ) ) {
					continue; // Already in effect — nothing to do.
				} else {
					$out['setting'] = $key;
					$out['value']   = 'bool' === $registry[ $key ]['type'] ? (bool) $value : $value;
					$out['label']   = (string) $registry[ $key ]['label'];
					$out['risk']    = (string) $registry[ $key ]['risk'];
				}
			}
		} elseif ( 'block' === $kind ) {
			$path  = isset( $fix['path'] ) ? (string) $fix['path'] : '';
			$block = isset( $fix['block'] ) ? (string) $fix['block'] : '';
			$attrs = isset( $fix['attrs'] ) && is_array( $fix['attrs'] ) ? $fix['attrs'] : array();
			$json  = (string) wp_json_encode( $attrs );
			if ( ! isset( $index[ $path ] ) || $index[ $path ] !== $block ) {
				$manual_reason = __( 'The block this applies to could not be matched on the page, so it was not made one-click.', 'ekwa' );
			} elseif ( ! $attrs || array_values( $attrs ) === $attrs || strlen( $json ) > 6000 ) {
				$manual_reason = __( 'The suggested block change was not in a form that can be applied automatically.', 'ekwa' );
			} else {
				$out['path']  = $path;
				$out['block'] = $block;
				$out['attrs'] = json_decode( $json, true );
			}
		} elseif ( 'part' === $kind ) {
			$check = empty( $ctx['parts'] )
				? array( 'ok' => false, 'reason' => __( 'Template parts were not available to this analysis.', 'ekwa' ) )
				: ekwa_page_audit_parts_validate( $fix, $ctx['parts'] );
			if ( empty( $check['ok'] ) ) {
				$manual_reason = $check['reason'];
			} else {
				$out['part']      = $check['part'];
				$out['partTitle'] = '';
				foreach ( $ctx['parts']['parts'] as $p ) {
					if ( $p['part'] === $check['part'] ) {
						$out['partTitle'] = (string) $p['title'];
					}
				}
				$out['path']  = $check['path'];
				$out['block'] = $check['block'];
				$out['attrs'] = $check['attrs'];
			}
		} elseif ( 'css' === $kind ) {
			$check = empty( $ctx['css'] ) && empty( $fix['css']['in'] )
				? array( 'ok' => false, 'reason' => __( 'The site stylesheet was not available to this analysis.', 'ekwa' ) )
				: ekwa_page_audit_css_validate( $fix, array(
					'url'        => isset( $ctx['url'] ) ? $ctx['url'] : '',
					'blocks'     => $index,
					'blocks_css' => isset( $ctx['blocks_css'] ) ? $ctx['blocks_css'] : array(),
					'parts'      => isset( $ctx['parts'] ) ? $ctx['parts'] : array(),
				) );
			if ( ! empty( $check['skip'] ) ) {
				continue; // Already in effect.
			}
			if ( empty( $check['ok'] ) ) {
				$manual_reason = $check['reason'];
			} else {
				$out['css'] = $check['css'];
				if ( ! empty( $check['checks'] ) ) {
					$out['cssCheck'] = $check['checks'];
				}
				if ( $check['colors'] ) {
					$out['colors'] = $check['colors'];
					$out['visual'] = true;
				}
				if ( $check['notes'] ) {
					$out['cssNotes'] = $check['notes'];
				}
			}
		}

		if ( 'manual' === $kind || '' !== $manual_reason ) {
			$out['kind']  = 'manual';
			$steps        = isset( $fix['steps'] ) ? sanitize_textarea_field( substr( (string) $fix['steps'], 0, 1500 ) ) : '';
			if ( '' === $steps && 'css' === $kind && ! empty( $fix['css']['edits'] ) && is_array( $fix['css']['edits'] ) ) {
				// Keep what the model suggested as instructions a person can follow.
				foreach ( array_slice( $fix['css']['edits'], 0, 4 ) as $n => $edit ) {
					if ( is_array( $edit ) ) {
						$steps .= ( $n + 1 ) . '. ' . sanitize_text_field( sprintf(
							/* translators: 1: selector, 2: property, 3: value */
							__( 'In the stylesheet, set %2$s to %3$s for %1$s.', 'ekwa' ),
							isset( $edit['selector'] ) ? (string) $edit['selector'] : '',
							isset( $edit['property'] ) ? (string) $edit['property'] : '',
							isset( $edit['to'] ) ? (string) $edit['to'] : ''
						) ) . "\n";
					}
				}
			}
			$out['steps'] = trim( ( '' !== $manual_reason ? $manual_reason . "\n" : '' ) . $steps );
			unset( $out['setting'], $out['value'], $out['path'], $out['block'], $out['attrs'], $out['part'], $out['partTitle'], $out['css'], $out['cssNotes'], $out['cssCheck'] );
		}

		$fixes[] = $out;
	}

	return array( 'summary' => $summary, 'fixes' => $fixes );
}

/**
 * Fixes already applied from this page (not undone), with whether each one's
 * audit still fails in the latest test and, for CSS, the live-page check — so
 * the model does not repeat a change that did not work.
 *
 * @param int   $post_id
 * @param array $report Latest report for the strategy being analysed.
 * @return array[]
 */
function ekwa_page_audit_previous_attempts( $post_id, $report ) {
	$audits = array();
	foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
		$analysis = ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_AI . $strategy );
		foreach ( isset( $analysis['fixes'] ) && is_array( $analysis['fixes'] ) ? $analysis['fixes'] : array() as $f ) {
			if ( isset( $f['id'], $f['audit'] ) ) {
				$audits[ $f['id'] ] = $f['audit'];
			}
		}
	}
	$failing = array();
	foreach ( isset( $report['audits'] ) && is_array( $report['audits'] ) ? $report['audits'] : array() as $list ) {
		foreach ( (array) $list as $a ) {
			if ( isset( $a['id'] ) ) {
				$failing[ $a['id'] ] = true;
			}
		}
	}
	$tested = isset( $report['fetched'] ) ? (int) $report['fetched'] : 0;
	$out    = array();
	$add    = static function ( $kind, $e, $detail ) use ( &$out, $audits, $failing, $tested ) {
		if ( ! empty( $e['undone'] ) ) {
			return;
		}
		$fix_id = isset( $e['fix_id'] ) ? (string) $e['fix_id'] : '';
		$audit  = isset( $e['audit'] ) ? (string) $e['audit'] : ( isset( $audits[ $fix_id ] ) ? $audits[ $fix_id ] : '' );
		$row    = array(
			'kind'    => $kind,
			'title'   => isset( $e['title'] ) ? (string) $e['title'] : '',
			'audit'   => $audit,
			'applied' => isset( $e['time'] ) ? gmdate( 'Y-m-d H:i', (int) $e['time'] ) : '',
			'change'  => ekwa_page_audit_text( $detail, 300 ),
		);
		if ( '' !== $audit && $tested > ( isset( $e['time'] ) ? (int) $e['time'] : 0 ) ) {
			$row['still_failing'] = isset( $failing[ $audit ] );
		}
		if ( ! empty( $e['verify']['status'] ) ) {
			$row['live_check'] = $e['verify']['status'] . ( empty( $e['verify']['detail'] ) ? '' : ': ' . $e['verify']['detail'] );
		}
		$out[] = $row;
	};
	foreach ( ekwa_page_audit_changes() as $e ) {
		if ( isset( $e['post_id'] ) && (int) $e['post_id'] === (int) $post_id ) {
			$add( isset( $e['type'] ) ? $e['type'] : 'css', $e, ! empty( $e['css'] ) ? ekwa_page_audit_css_describe( $e['css'] ) : ( isset( $e['part_title'] ) ? $e['part_title'] . ' ' . wp_json_encode( isset( $e['after'] ) ? $e['after'] : array() ) : '' ) );
		}
	}
	foreach ( ekwa_page_audit_log_for_post( $post_id ) as $e ) {
		$add( 'setting', $e, ( isset( $e['label'] ) ? $e['label'] : '' ) . ' → ' . wp_json_encode( isset( $e['new'] ) ? $e['new'] : '' ) );
	}
	foreach ( ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_HISTORY ) as $e ) {
		if ( is_array( $e ) ) {
			$add( 'block', $e, ( isset( $e['block'] ) ? $e['block'] : '' ) . ' ' . wp_json_encode( isset( $e['after'] ) ? array_map( static function ( $v ) { return is_string( $v ) && strlen( $v ) > 120 ? substr( $v, 0, 117 ) . '…' : $v; }, (array) $e['after'] ) : array() ) );
		}
	}
	return array_slice( $out, -25 );
}

/**
 * Decode the model's JSON answer, tolerating Markdown fences or prose around it.
 *
 * @param string $text
 * @return array|null
 */
function ekwa_page_audit_decode_json( $text ) {
	$text    = trim( (string) $text );
	$text    = preg_replace( '/^```(?:json)?\s*/i', '', $text );
	$text    = preg_replace( '/\s*```$/', '', $text );
	$decoded = json_decode( $text, true );
	if ( is_array( $decoded ) ) {
		return $decoded;
	}
	$start = strpos( $text, '{' );
	$end   = strrpos( $text, '}' );
	if ( false !== $start && false !== $end && $end > $start ) {
		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}
	return null;
}

/**
 * POST /page-audit/analyze — ask the AI for fixes. Reads the stored report;
 * writes the analysis only after the AI has answered (the MCP relay re-sends
 * this request, so nothing before the AI call may have side effects).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_page_audit_rest_analyze( $request ) {
	if ( function_exists( 'ekwa_ai_current_feature' ) ) {
		ekwa_ai_current_feature( 'page-audit' );
	}

	$post_id  = (int) $request->get_param( 'post_id' );
	$strategy = ekwa_page_audit_strategy( $request->get_param( 'strategy' ) );
	$focus    = (string) $request->get_param( 'focus' );
	$focus    = in_array( $focus, array( 'performance', 'accessibility', 'seo' ), true ) ? $focus : 'all';
	$post     = get_post( $post_id );
	if ( ! $post ) {
		return new WP_Error( 'rest_post_invalid_id', __( 'Page not found.', 'ekwa' ), array( 'status' => 404 ) );
	}
	if ( ! function_exists( 'ekwa_ai_is_configured' ) || ! ekwa_ai_is_configured() ) {
		return new WP_Error( 'ekwa_ai_unavailable', __( 'No AI provider is set up. Add a Gemini API key or turn on the MCP connector in Ekwa Settings → AI.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$report = ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_REPORT . $strategy );
	if ( ! $report ) {
		return new WP_Error( 'ekwa_page_audit_no_report', __( 'Run the PageSpeed test first — the AI reads its report.', 'ekwa' ), array( 'status' => 400 ) );
	}
	if ( isset( $report['audits'] ) && is_array( $report['audits'] ) ) {
		// Best Practices is recorded for the dashboard, not part of the fixes.
		unset( $report['audits']['best-practices'] );
		if ( 'all' !== $focus ) {
			$report['audits'] = array( $focus => isset( $report['audits'][ $focus ] ) ? $report['audits'][ $focus ] : array() );
		}
	}

	$outline = ekwa_page_audit_sanitize_outline( $request->get_param( 'blocks' ) );
	$schemas = $request->get_param( 'schemas' );
	$schemas = is_array( $schemas ) ? $schemas : array();
	if ( strlen( (string) wp_json_encode( $schemas ) ) > 120000 ) {
		$schemas = array_slice( $schemas, 0, 40, true );
	}

	$settings = array();
	foreach ( ekwa_page_audit_settings_snapshot() as $key => $row ) {
		$settings[] = array(
			'key'     => $key,
			'label'   => $row['label'],
			'allowed' => $row['values'],
			'current' => $row['current'],
			'help'    => $row['help'],
			'risk'    => $row['risk'],
		);
	}

	// Header/footer and the site stylesheet: read-only here (no side effects
	// before the AI call — the MCP relay re-sends this request).
	$parts = ekwa_page_audit_parts_context( $post );
	$css   = ekwa_page_audit_css_context();

	$payload = array(
		'PAGE'        => array(
			'title' => wp_strip_all_tags( get_the_title( $post ) ),
			'url'   => get_permalink( $post ),
			'type'  => $post->post_type,
		),
		'STRATEGY'    => $strategy,
		'REPORT'      => $report,
		'SETTINGS'    => $settings,
		'PALETTE'     => ekwa_page_audit_palette(),
		'SITE'        => ekwa_page_audit_site_context(),
		'BLOCKS'      => $outline['list'],
		'SCHEMA'      => $schemas,
		'PARTS'       => $parts['parts'],
		'PART_SCHEMA' => $parts['schemas'],
	);
	if ( $css ) {
		$payload['TOKENS']     = $css['tokens'];
		$payload['STYLESHEET'] = $css['stylesheet'];
		if ( $css['reduced'] ) {
			$payload['STYLESHEET_NOTE'] = 'Large stylesheet: only rules that set properties a fix may change are included.';
		}
	}

	// The CSS that competes with the stylesheet on this page (block CSS and
	// Section CSS print after it), read from the live page.
	$page_url = ekwa_page_audit_test_url( $post );
	$page_url = is_wp_error( $page_url ) ? '' : $page_url;
	if ( '' !== $page_url && apply_filters( 'ekwa_page_audit_cascade_check', true ) ) {
		$snap = ekwa_page_audit_page_snapshot( $page_url );
		if ( ! is_wp_error( $snap ) ) {
			$other = ekwa_page_audit_other_css( $snap );
			if ( $other ) {
				$payload['OTHER_CSS'] = $other;
			}
		}
	}

	// What was already tried from this page, and whether it worked.
	$previous = ekwa_page_audit_previous_attempts( $post_id, $report );
	if ( $previous ) {
		$payload['PREVIOUS'] = $previous;
	}

	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => "Audit data for this page (JSON):\n" . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n\nReturn the JSON object described in your instructions." ),
			),
		),
	);

	$model = function_exists( 'ekwa_ai_resolve_model' ) ? ekwa_ai_resolve_model( (string) $request->get_param( 'model' ), 'pro' ) : '';

	$result = ekwa_ai_generate_call_gemini(
		ekwa_page_audit_system_prompt( $focus ),
		$contents,
		0.2,
		(string) ekwa_get_ai_api_key(),
		$model,
		0,
		null
	);
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		if ( ! is_array( $data ) || empty( $data['status'] ) ) {
			$result->add_data( array( 'status' => 502 ) );
		}
		return $result;
	}

	$decoded = ekwa_page_audit_decode_json( isset( $result['content'] ) ? (string) $result['content'] : '' );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error( 'ekwa_page_audit_bad_answer', __( 'The AI answer could not be read. Try again.', 'ekwa' ), array( 'status' => 502 ) );
	}

	$stamp    = base_convert( (string) time(), 10, 36 );
	$checked  = ekwa_page_audit_validate_fixes( $decoded, $outline['index'], $stamp, array(
		'parts'      => $parts,
		'css'        => (bool) $css,
		'url'        => $page_url,
		'blocks_css' => $outline['css'],
	) );
	$provider = function_exists( 'ekwa_ai_relay_applies' ) && ekwa_ai_relay_applies() ? 'mcp' : 'gemini';

	$analysis = array(
		'v'        => 2,
		'time'     => time(),
		'strategy' => $strategy,
		'focus'    => $focus,
		'report'   => isset( $report['fetched'] ) ? (int) $report['fetched'] : 0,
		'provider' => $provider,
		'model'    => 'gemini' === $provider ? $model : '',
		'summary'  => $checked['summary'],
		'fixes'    => $checked['fixes'],
	);
	ekwa_page_audit_put_meta( $post_id, EKWA_PAGE_AUDIT_META_AI . $strategy, $analysis );

	return rest_ensure_response( array( 'analysis' => $analysis ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// APPLY / UNDO
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * POST /page-audit/apply-setting — change one whitelisted setting and log it.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_page_audit_rest_apply_setting( $request ) {
	$registry = ekwa_page_audit_settings_registry();
	$key      = (string) $request->get_param( 'setting' );
	if ( ! isset( $registry[ $key ] ) ) {
		return new WP_Error( 'ekwa_page_audit_setting', __( 'The audit cannot change that setting.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$def   = $registry[ $key ];
	$value = ekwa_page_audit_coerce( $def, $request->get_param( 'value' ) );
	if ( null === $value ) {
		return new WP_Error( 'ekwa_page_audit_value', __( 'That is not a valid value for this setting.', 'ekwa' ), array( 'status' => 400 ) );
	}

	$missing    = '__ekwa_page_audit_missing__';
	$raw_before = get_option( $key, $missing );
	$existed    = $missing !== $raw_before;
	$before     = ekwa_page_audit_current( $key, $def );
	if ( $before === $value ) {
		return new WP_Error( 'ekwa_page_audit_same', __( 'That setting already has this value.', 'ekwa' ), array( 'status' => 409 ) );
	}

	$entry = array(
		'id'         => ekwa_page_audit_id(),
		'time'       => time(),
		'user'       => ekwa_page_audit_user_name(),
		'post_id'    => (int) $request->get_param( 'post_id' ),
		'fix_id'     => sanitize_text_field( (string) $request->get_param( 'fix_id' ) ),
		'title'      => ekwa_page_audit_text( (string) $request->get_param( 'title' ), 120 ),
		'setting'    => $key,
		'old'        => $before,
		'old_raw'    => $existed ? $raw_before : null,
		'old_exists' => $existed,
		'new'        => $value,
		'undone'     => 0,
	);

	update_option( $key, $value );

	$logged = ekwa_page_audit_mutate_log( static function ( $log ) use ( $entry ) {
		$log[] = $entry;
		return $log;
	} );
	if ( ! $logged ) {
		// Could not record how to undo it: put the old value back rather than
		// leave a change nobody can reverse from here.
		if ( $existed ) {
			update_option( $key, $raw_before );
		} else {
			delete_option( $key );
		}
		return new WP_Error( 'ekwa_page_audit_busy', __( 'Busy — try again in a moment.', 'ekwa' ), array( 'status' => 503 ) );
	}

	ekwa_page_audit_purge_caches();
	return rest_ensure_response( array(
		'entry'    => ekwa_page_audit_public_log_entry( $entry ),
		'settings' => ekwa_page_audit_settings_snapshot(),
	) );
}

/**
 * Undo one logged setting change. Refuses when the setting has been changed
 * again since (by a person or another fix), so an undo never overwrites a
 * newer decision.
 *
 * @param string $entry_id
 * @return array|WP_Error The updated entry.
 */
function ekwa_page_audit_undo_setting( $entry_id ) {
	$registry = ekwa_page_audit_settings_registry();
	$result   = null;

	$ok = ekwa_page_audit_mutate_log( static function ( $log ) use ( $entry_id, $registry, &$result ) {
		foreach ( $log as $i => $entry ) {
			if ( ! isset( $entry['id'] ) || $entry['id'] !== $entry_id ) {
				continue;
			}
			if ( ! empty( $entry['undone'] ) ) {
				$result = new WP_Error( 'ekwa_page_audit_undone', __( 'That change was already undone.', 'ekwa' ), array( 'status' => 409 ) );
				return $log;
			}
			$key = isset( $entry['setting'] ) ? (string) $entry['setting'] : '';
			if ( ! isset( $registry[ $key ] ) ) {
				$result = new WP_Error( 'ekwa_page_audit_setting', __( 'That setting can no longer be changed from here.', 'ekwa' ), array( 'status' => 400 ) );
				return $log;
			}
			$current = ekwa_page_audit_current( $key, $registry[ $key ] );
			if ( $current !== $entry['new'] ) {
				$result = new WP_Error(
					'ekwa_page_audit_changed',
					sprintf(
						/* translators: %s: setting label */
						__( '"%s" was changed again after this fix, so it was not undone. Change it in Ekwa Settings → Performance if needed.', 'ekwa' ),
						$registry[ $key ]['label']
					),
					array( 'status' => 409 )
				);
				return $log;
			}

			if ( ! empty( $entry['old_exists'] ) ) {
				update_option( $key, $entry['old_raw'] );
			} else {
				delete_option( $key ); // Never saved before: back to the theme default.
			}
			$log[ $i ]['undone']    = time();
			$log[ $i ]['undone_by'] = ekwa_page_audit_user_name();
			$result                 = $log[ $i ];
			return $log;
		}
		$result = new WP_Error( 'ekwa_page_audit_missing', __( 'That change is no longer in the log.', 'ekwa' ), array( 'status' => 404 ) );
		return $log;
	} );

	if ( ! $ok ) {
		return new WP_Error( 'ekwa_page_audit_busy', __( 'Busy — try again in a moment.', 'ekwa' ), array( 'status' => 503 ) );
	}
	if ( ! is_wp_error( $result ) ) {
		ekwa_page_audit_purge_caches();
	}
	return $result;
}

/**
 * POST /page-audit/undo-setting.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_page_audit_rest_undo_setting( $request ) {
	$entry = ekwa_page_audit_undo_setting( sanitize_text_field( (string) $request->get_param( 'entry_id' ) ) );
	if ( is_wp_error( $entry ) ) {
		return $entry;
	}
	return rest_ensure_response( array(
		'entry'    => ekwa_page_audit_public_log_entry( $entry ),
		'settings' => ekwa_page_audit_settings_snapshot(),
	) );
}

/**
 * JSON-safe copy of a client value, or null when it is too big to keep.
 *
 * @param mixed $value
 * @param int   $max   Bytes.
 * @return mixed
 */
function ekwa_page_audit_json_value( $value, $max = 90000 ) {
	$json = wp_json_encode( $value );
	if ( false === $json || strlen( $json ) > $max ) {
		return null;
	}
	return json_decode( $json, true );
}

/**
 * POST /page-audit/history — record a block fix the editor applied ("add"),
 * or that it was undone ("undone").
 *
 * The change itself happens in the editor and is saved with the page; this
 * only remembers what it was, so it can be undone after a reload.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_page_audit_rest_history( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	$op      = (string) $request->get_param( 'op' );
	$history = array_values( array_filter( ekwa_page_audit_get_meta( $post_id, EKWA_PAGE_AUDIT_META_HISTORY ), 'is_array' ) );

	if ( 'add' === $op ) {
		$raw   = $request->get_param( 'entry' );
		$raw   = is_array( $raw ) ? $raw : array();
		$path  = isset( $raw['path'] ) ? (string) $raw['path'] : '';
		$block = isset( $raw['block'] ) ? (string) $raw['block'] : '';
		if ( ! preg_match( '/^\d{1,4}(\.\d{1,4}){0,20}$/', $path ) || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $block ) ) {
			return new WP_Error( 'ekwa_page_audit_entry', __( 'Invalid change record.', 'ekwa' ), array( 'status' => 400 ) );
		}
		$before = ekwa_page_audit_json_value( isset( $raw['before'] ) ? $raw['before'] : array() );
		$after  = ekwa_page_audit_json_value( isset( $raw['after'] ) ? $raw['after'] : array() );
		if ( ! is_array( $before ) || ! is_array( $after ) ) {
			return new WP_Error( 'ekwa_page_audit_entry', __( 'That change is too large to record.', 'ekwa' ), array( 'status' => 400 ) );
		}
		$entry     = array(
			'id'       => ekwa_page_audit_id(),
			'time'     => time(),
			'user'     => ekwa_page_audit_user_name(),
			'fix_id'   => isset( $raw['fix_id'] ) ? sanitize_text_field( (string) $raw['fix_id'] ) : '',
			'title'    => isset( $raw['title'] ) ? ekwa_page_audit_text( $raw['title'], 120 ) : '',
			'category' => isset( $raw['category'] ) && 'accessibility' === $raw['category'] ? 'accessibility' : 'performance',
			'path'     => $path,
			'block'    => $block,
			'before'   => $before,
			'after'    => $after,
			'undone'   => 0,
		);
		$history[] = $entry;
		if ( count( $history ) > 100 ) {
			$history = array_slice( $history, -100 );
		}
	} elseif ( 'undone' === $op ) {
		$id    = sanitize_text_field( (string) $request->get_param( 'entry_id' ) );
		$found = false;
		foreach ( $history as $i => $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === $id ) {
				$history[ $i ]['undone'] = time();
				$found                   = true;
			}
		}
		if ( ! $found ) {
			return new WP_Error( 'ekwa_page_audit_missing', __( 'That change is no longer recorded.', 'ekwa' ), array( 'status' => 404 ) );
		}
	} else {
		return new WP_Error( 'ekwa_page_audit_op', __( 'Unknown operation.', 'ekwa' ), array( 'status' => 400 ) );
	}

	ekwa_page_audit_put_meta( $post_id, EKWA_PAGE_AUDIT_META_HISTORY, $history );
	return rest_ensure_response( array( 'history' => $history ) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// EKWA SETTINGS → PERFORMANCE: undo from the log
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * admin-post.php?action=ekwa_page_audit_undo — the Undo links in the
 * Performance tab's "Changes made by the page audit" table.
 */
function ekwa_page_audit_admin_undo() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'ekwa' ), 403 );
	}
	$entry_id = isset( $_GET['entry'] ) ? sanitize_text_field( wp_unslash( $_GET['entry'] ) ) : '';
	check_admin_referer( 'ekwa_page_audit_undo_' . $entry_id );

	$result = ekwa_page_audit_undo_setting( $entry_id );
	$status = is_wp_error( $result ) ? $result->get_error_code() : 'ok';

	wp_safe_redirect( add_query_arg(
		array(
			'page'           => 'ekwa-settings',
			'ekwa_tab'       => 'performance',
			'ekwa_audit_undo' => rawurlencode( $status ),
		),
		admin_url( 'themes.php' )
	) . '#ekwa-page-audit-settings' );
	exit;
}
add_action( 'admin_post_ekwa_page_audit_undo', 'ekwa_page_audit_admin_undo' );

/**
 * admin-post.php?action=ekwa_page_audit_undo_change — Undo links for
 * stylesheet / template-part changes in the Performance tab.
 */
function ekwa_page_audit_admin_undo_change() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'ekwa' ), 403 );
	}
	$entry_id = isset( $_GET['entry'] ) ? sanitize_text_field( wp_unslash( $_GET['entry'] ) ) : '';
	check_admin_referer( 'ekwa_page_audit_undo_change_' . $entry_id );

	$result = ekwa_page_audit_undo_change( $entry_id );
	$status = is_wp_error( $result ) ? $result->get_error_code() : 'ok';

	wp_safe_redirect( add_query_arg(
		array(
			'page'              => 'ekwa-settings',
			'ekwa_tab'          => 'performance',
			'ekwa_audit_change' => rawurlencode( $status ),
		),
		admin_url( 'themes.php' )
	) . '#ekwa-page-audit-settings' );
	exit;
}
add_action( 'admin_post_ekwa_page_audit_undo_change', 'ekwa_page_audit_admin_undo_change' );

/**
 * The Performance tab's audit section: the PageSpeed API key and the log of
 * setting changes made from page audits, each with an Undo link.
 */
function ekwa_page_audit_render_settings_section() {
	$key_const = defined( 'EKWA_PSI_API_KEY' ) && EKWA_PSI_API_KEY;
	$stored    = (string) get_option( 'ekwa_psi_api_key', '' );
	$masked    = ( ! $key_const && '' !== $stored && function_exists( 'ekwa_ai_mask_key' ) ) ? ekwa_ai_mask_key( $stored ) : '';
	$log         = array_reverse( ekwa_page_audit_log() );
	$changes     = array_reverse( ekwa_page_audit_changes() );
	$undo_code   = isset( $_GET['ekwa_audit_undo'] ) ? sanitize_key( wp_unslash( $_GET['ekwa_audit_undo'] ) ) : '';
	$change_code = isset( $_GET['ekwa_audit_change'] ) ? sanitize_key( wp_unslash( $_GET['ekwa_audit_change'] ) ) : '';
	?>
	<div class="ekwa-section" id="ekwa-page-audit-settings">
		<h2><?php esc_html_e( 'PageSpeed & Accessibility audit', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Every page and post editor has a “Page Speed & Accessibility” panel (the gauge icon in the top toolbar, and a summary in the Page sidebar). It tests the published page with Google PageSpeed Insights, and your AI provider (Ekwa Settings → AI: the Gemini key or the MCP connector) suggests fixes you can apply and undo one by one.', 'ekwa' ); ?>
		</p>
		<?php if ( function_exists( 'ekwa_page_audit_dashboard_url' ) ) : ?>
			<p><a class="button" href="<?php echo esc_url( ekwa_page_audit_dashboard_url() ); ?>"><span class="dashicons dashicons-performance" style="margin-top:4px;" aria-hidden="true"></span> <?php esc_html_e( 'Open the site-wide Page Speed dashboard', 'ekwa' ); ?></a></p>
		<?php endif; ?>
		<?php if ( 'ok' === $change_code ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'The change was undone.', 'ekwa' ); ?></p></div>
		<?php elseif ( '' !== $change_code ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'That change could not be undone automatically — it was edited since, already undone, or is no longer in the log. Search the stylesheet for its “ekwa-fix:” note to revert it by hand.', 'ekwa' ); ?></p></div>
		<?php endif; ?>
		<?php if ( 'ok' === $undo_code ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'The setting was restored to its value before the fix.', 'ekwa' ); ?></p></div>
		<?php elseif ( '' !== $undo_code ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				echo esc_html(
					'ekwa_page_audit_changed' === $undo_code
						? __( 'Not undone: that setting was changed again after the fix. Change it in the table above if needed.', 'ekwa' )
						: __( 'That change could not be undone (already undone, or no longer in the log).', 'ekwa' )
				);
				?>
			</p></div>
		<?php endif; ?>
		<table class="form-table">
			<tr>
				<th><label for="ekwa_psi_api_key"><?php esc_html_e( 'PageSpeed Insights API key', 'ekwa' ); ?></label></th>
				<td>
					<input type="password" id="ekwa_psi_api_key" name="ekwa_psi_api_key" value="" class="regular-text" autocomplete="off"
						placeholder="<?php echo $masked ? esc_attr( $masked . '  —  ' . __( 'leave blank to keep', 'ekwa' ) ) : esc_attr__( 'Paste your key…', 'ekwa' ); ?>"
						<?php disabled( $key_const ); ?> />
					<?php if ( $masked ) : ?>
						<label style="margin-left:8px;"><input type="checkbox" name="ekwa_psi_api_key_clear" value="1" /> <?php esc_html_e( 'Remove the stored key', 'ekwa' ); ?></label>
					<?php endif; ?>
					<p class="description">
						<?php
						if ( $key_const ) {
							esc_html_e( 'Set via wp-config.php (EKWA_PSI_API_KEY). This field is ignored.', 'ekwa' );
						} else {
							printf(
								/* translators: %s: link to the Google documentation. */
								esc_html__( 'Without a key Google allows only a handful of tests. Create one in Google Cloud with the PageSpeed Insights API enabled (%s). The stored key is never shown in full.', 'ekwa' ),
								'<a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank" rel="noopener">' . esc_html__( 'get a key', 'ekwa' ) . '</a>'
							);
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Changes made by the audit', 'ekwa' ); ?></th>
				<td>
					<?php if ( ! $log ) : ?>
						<p class="description"><?php esc_html_e( 'None yet. Setting changes applied from a page audit are listed here and can be undone.', 'ekwa' ); ?></p>
					<?php else : ?>
						<table class="widefat striped" style="max-width:860px;">
							<thead><tr>
								<th><?php esc_html_e( 'When', 'ekwa' ); ?></th>
								<th><?php esc_html_e( 'Setting', 'ekwa' ); ?></th>
								<th><?php esc_html_e( 'Change', 'ekwa' ); ?></th>
								<th><?php esc_html_e( 'From page', 'ekwa' ); ?></th>
								<th><?php esc_html_e( 'By', 'ekwa' ); ?></th>
								<th></th>
							</tr></thead>
							<tbody>
							<?php
							$fmt = static function ( $v ) {
								return is_int( $v ) ? ( $v ? __( 'on', 'ekwa' ) : __( 'off', 'ekwa' ) ) : (string) $v;
							};
							foreach ( array_slice( $log, 0, 30 ) as $entry ) :
								$entry = ekwa_page_audit_public_log_entry( $entry );
								$pid   = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
								?>
								<tr>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?></td>
									<td><?php echo esc_html( $entry['label'] ); ?></td>
									<td><code><?php echo esc_html( $fmt( $entry['old'] ) ); ?></code> → <code><?php echo esc_html( $fmt( $entry['new'] ) ); ?></code></td>
									<td><?php echo $pid && get_post( $pid ) ? '<a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a>' : '—'; ?></td>
									<td><?php echo esc_html( (string) $entry['user'] ); ?></td>
									<td>
										<?php if ( ! empty( $entry['undone'] ) ) : ?>
											<em><?php esc_html_e( 'Undone', 'ekwa' ); ?></em>
										<?php elseif ( $entry['undoable'] ) : ?>
											<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ekwa_page_audit_undo&entry=' . rawurlencode( $entry['id'] ) ), 'ekwa_page_audit_undo_' . $entry['id'] ) ); ?>"><?php esc_html_e( 'Undo', 'ekwa' ); ?></a>
										<?php else : ?>
											<em title="<?php esc_attr_e( 'The setting was changed again afterwards.', 'ekwa' ); ?>"><?php esc_html_e( 'Changed since', 'ekwa' ); ?></em>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Stylesheet & header/footer changes', 'ekwa' ); ?></th>
				<td>
					<?php if ( ! $changes ) : ?>
						<p class="description"><?php esc_html_e( 'None yet. Stylesheet fixes (marked “ekwa-fix:” in the CSS) and header/footer block fixes applied from a page audit are listed here and can be undone.', 'ekwa' ); ?></p>
					<?php else : ?>
						<table class="widefat striped" style="max-width:860px;">
							<thead><tr>
								<th><?php esc_html_e( 'When', 'ekwa' ); ?></th>
								<th><?php esc_html_e( 'Fix', 'ekwa' ); ?></th>
								<th><?php esc_html_e( 'Change', 'ekwa' ); ?></th>
								<th><?php esc_html_e( 'From page', 'ekwa' ); ?></th>
								<th></th>
							</tr></thead>
							<tbody>
							<?php
							foreach ( array_slice( $changes, 0, 30 ) as $raw_entry ) :
								$entry = ekwa_page_audit_public_change( $raw_entry );
								$pid   = (int) $entry['post_id'];
								?>
								<tr>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ); ?><br><span class="description"><?php echo esc_html( (string) $entry['user'] ); ?></span></td>
									<td><?php echo esc_html( $entry['title'] ); ?><?php if ( 'css' === $entry['type'] ) : ?><br><code><?php echo esc_html( $entry['marker'] ); ?></code><?php endif; ?></td>
									<td><code style="white-space:normal;"><?php echo esc_html( ekwa_page_audit_text( isset( $entry['detail'] ) ? $entry['detail'] : '', 220 ) ); ?></code></td>
									<td><?php echo $pid && get_post( $pid ) ? '<a href="' . esc_url( get_edit_post_link( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a>' : '—'; ?></td>
									<td>
										<?php if ( ! empty( $entry['undone'] ) ) : ?>
											<em><?php esc_html_e( 'Undone', 'ekwa' ); ?></em>
										<?php elseif ( $entry['undoable'] ) : ?>
											<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ekwa_page_audit_undo_change&entry=' . rawurlencode( $entry['id'] ) ), 'ekwa_page_audit_undo_change_' . $entry['id'] ) ); ?>"><?php esc_html_e( 'Undo', 'ekwa' ); ?></a>
											<?php if ( 'css' === $entry['type'] && $pid && function_exists( 'ekwa_page_audit_compare_url' ) ) : ?>
												<a class="button button-small" href="<?php echo esc_url( ekwa_page_audit_compare_url( array( 'change' => $entry['id'] ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Compare', 'ekwa' ); ?></a>
											<?php endif; ?>
										<?php else : ?>
											<em title="<?php esc_attr_e( 'The lines this fix changed were edited afterwards.', 'ekwa' ); ?>"><?php esc_html_e( 'Edited since', 'ekwa' ); ?></em>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</td>
			</tr>
		</table>
	</div>
	<?php
}

/**
 * Save the PageSpeed key from the settings form (masked-field convention: a
 * blank submit keeps the stored key). Called from ekwa_save_settings(), inside
 * its nonce and capability checks.
 */
function ekwa_page_audit_save_settings() {
	if ( ! empty( $_POST['ekwa_psi_api_key_clear'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by ekwa_save_settings().
		delete_option( 'ekwa_psi_api_key' );
		return;
	}
	if ( isset( $_POST['ekwa_psi_api_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$submitted = sanitize_text_field( wp_unslash( $_POST['ekwa_psi_api_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' !== $submitted && false === strpos( $submitted, '•' ) ) {
			update_option( 'ekwa_psi_api_key', $submitted, false );
		}
	}
}

// ═══════════════════════════════════════════════════════════════════════════════
// EDITOR ASSETS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Load the sidebar in the post/page editor.
 */
function ekwa_page_audit_enqueue_editor() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && isset( $screen->base ) && 'post' !== $screen->base ) {
		return; // Site editor, widgets, etc.: no single published page to test.
	}

	$rel = 'assets/js/ekwa-page-audit-editor.js';
	wp_enqueue_script(
		'ekwa-page-audit-editor',
		get_template_directory_uri() . '/' . $rel,
		array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-data', 'wp-i18n', 'wp-api-fetch', 'wp-core-data', 'wp-dom-ready' ),
		filemtime( get_template_directory() . '/' . $rel ),
		true
	);

	$css = 'assets/css/ekwa-page-audit.css';
	wp_enqueue_style(
		'ekwa-page-audit-editor',
		get_template_directory_uri() . '/' . $css,
		array(),
		filemtime( get_template_directory() . '/' . $css )
	);

	$models = array();
	if ( function_exists( 'ekwa_ai_models' ) ) {
		foreach ( ekwa_ai_models() as $id => $label ) {
			$models[] = array( 'value' => $id, 'label' => $label );
		}
	}
	$ai_cap = function_exists( 'ekwa_ai_min_capability' ) ? ekwa_ai_min_capability() : 'edit_posts';

	wp_localize_script( 'ekwa-page-audit-editor', 'ekwaPageAudit', array(
		'canManage'    => current_user_can( 'manage_options' ),
		'canParts'     => current_user_can( 'manage_options' ) && current_user_can( 'edit_theme_options' ),
		'canAi'        => function_exists( 'ekwa_ai_is_configured' ) && ekwa_ai_is_configured() && current_user_can( $ai_cap ),
		'aiProvider'   => function_exists( 'ekwa_ai_effective_provider' ) ? ekwa_ai_effective_provider() : 'gemini',
		'models'       => $models,
		'defaultModel' => function_exists( 'ekwa_ai_model_for_tier' ) ? ekwa_ai_model_for_tier( 'pro' ) : '',
		'psiKey'       => '' !== ekwa_page_audit_psi_key(),
		'settingsUrl'  => admin_url( 'themes.php?page=ekwa-settings&ekwa_tab=performance#ekwa-page-audit-settings' ),
		'aiSettingsUrl' => admin_url( 'themes.php?page=ekwa-settings&ekwa_tab=ai' ),
		'dashboardUrl' => function_exists( 'ekwa_page_audit_dashboard_url' ) ? ekwa_page_audit_dashboard_url() : '',
		'compareUrl'   => function_exists( 'ekwa_page_audit_compare_url' ) ? ekwa_page_audit_compare_url( array() ) : '',
	) );
}
add_action( 'enqueue_block_editor_assets', 'ekwa_page_audit_enqueue_editor' );

require_once __DIR__ . '/ekwa-page-audit-css.php';
require_once __DIR__ . '/ekwa-page-audit-cascade.php';
require_once __DIR__ . '/ekwa-page-audit-parts.php';
require_once __DIR__ . '/ekwa-page-audit-compare.php';
require_once __DIR__ . '/ekwa-page-audit-dashboard.php';
