<?php
/**
 * Page audit — "will this CSS fix actually take effect?"
 *
 * A declaration only changes the page if it wins the cascade. On an Ekwa site
 * the site stylesheet prints in <head>, while each block's own CSS and every
 * section's Section CSS (ekwa/div scopedCss) print later, inside the body — so
 * a stylesheet rule with the same specificity as a block rule silently loses
 * (the breadcrumb `text-decoration: underline` that never showed up was
 * exactly that: `.ekwa-breadcrumb a { text-decoration: none }` in the
 * breadcrumb block's CSS won by source order).
 *
 * This file takes a snapshot of the real page — its HTML and every <style> /
 * same-site stylesheet in document order — and works out, for the elements a
 * fix targets, which declaration wins for the property it changes:
 * !important first, then specificity, then order; inline style="" beats all
 * normal rules; :hover/:focus/… rules don't apply to the default state.
 *
 * Used three ways:
 *   - before a fix is offered: a losing declaration is strengthened (ancestor
 *     classes from the page itself are prefixed until it wins) or, failing
 *     that, turned into a manual step that names the rule in the way;
 *   - right after a fix is applied: the live page is fetched again (cache
 *     bypassed) and checked, so the person sees "takes effect on 3 elements"
 *     or "overridden by …" without inspecting anything;
 *   - read-only context for the model (OTHER_CSS), so it can pick selectors
 *     strong enough in the first place.
 *
 * It reads; it never writes.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch a page and collect its DOM and CSS sources (in document order).
 *
 * @param string $url
 * @param bool   $fresh Bypass page caches (used after a change).
 * @return array|WP_Error {xpath: DOMXPath, sources: [{id, label, css}]}
 */
function ekwa_page_audit_page_snapshot( $url, $fresh = false ) {
	static $cache = array();
	$key = $url . ( $fresh ? '|fresh' : '' );
	if ( ! $fresh && isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	if ( ! class_exists( 'DOMDocument' ) ) {
		return new WP_Error( 'ekwa_page_audit_dom', 'DOM extension missing' );
	}
	ekwa_page_audit_css_require();

	$fetch = $fresh ? add_query_arg( 'ekwa_pa_verify', wp_rand( 100000, 999999 ), $url ) : $url;
	$args  = array(
		'timeout'     => 15,
		'redirection' => 3,
		'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		'headers'     => array( 'Cache-Control' => 'no-cache' ),
	);
	$res = wp_remote_get( $fetch, $args );
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return new WP_Error( 'ekwa_page_audit_snapshot', __( 'The page could not be loaded from the server to check the CSS.', 'ekwa' ) );
	}
	$snap = ekwa_page_audit_snapshot_from_html( (string) wp_remote_retrieve_body( $res ), $args );
	if ( ! $fresh ) {
		$cache[ $key ] = $snap;
	}
	return $snap;
}

/**
 * Build a snapshot from page HTML.
 *
 * @param string     $html
 * @param array|null $args wp_remote_get() args for same-site stylesheets; null = do not fetch them.
 * @return array {xpath, sources}
 */
function ekwa_page_audit_snapshot_from_html( $html, $args = null ) {
	ekwa_page_audit_css_require();
	$sources = array();
	$host    = wp_parse_url( home_url(), PHP_URL_HOST );
	$linked  = 0;
	if ( preg_match_all( '#<style\b([^>]*)>(.*?)</style>|<link\b([^>]*)>#is', $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $i => $hit ) {
			if ( ! empty( $hit[3] ) ) {
				$attrs = $hit[3];
				if ( ! preg_match( '/\brel=["\']?stylesheet/i', $attrs ) || ! preg_match( '/\bhref=["\']([^"\']+)/i', $attrs, $h ) ) {
					continue;
				}
				if ( null === $args || preg_match( '/\bmedia=["\']print/i', $attrs ) || $linked >= 6 ) {
					continue;
				}
				$href = html_entity_decode( $h[1], ENT_QUOTES );
				if ( 0 === strpos( $href, '//' ) ) {
					$href = ( is_ssl() ? 'https:' : 'http:' ) . $href;
				}
				if ( wp_parse_url( $href, PHP_URL_HOST ) !== $host ) {
					continue; // Same site only.
				}
				$r = wp_remote_get( $href, $args );
				if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
					continue;
				}
				$linked++;
				$sources[] = array(
					'id'    => 'link:' . basename( (string) wp_parse_url( $href, PHP_URL_PATH ) ),
					'label' => basename( (string) wp_parse_url( $href, PHP_URL_PATH ) ),
					'css'   => substr( (string) wp_remote_retrieve_body( $r ), 0, 400000 ),
				);
				continue;
			}
			$id        = preg_match( '/\bid=["\']([^"\']+)/i', $hit[1], $idm ) ? $idm[1] : 'style-' . $i;
			$sources[] = array(
				'id'    => $id,
				'label' => ekwa_page_audit_source_label( $id ),
				'css'   => (string) $hit[2],
			);
		}
	}

	$doc  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	return array(
		'xpath'   => new DOMXPath( $doc ),
		'sources' => $sources,
	);
}

/**
 * Human label for a <style> id.
 *
 * @param string $id
 * @return string
 */
function ekwa_page_audit_source_label( $id ) {
	if ( 'ekwa-global-css' === $id ) {
		return __( 'site stylesheet', 'ekwa' );
	}
	if ( preg_match( '/^ekwa-inline-blocks-ekwa-(.+)-style$/', $id, $m ) ) {
		/* translators: %s: block name */
		return sprintf( __( '“%s” block CSS', 'ekwa' ), str_replace( '-', ' ', $m[1] ) );
	}
	if ( preg_match( '/^ekwa-inline-[0-9a-f]{12}$/', $id ) ) {
		return __( 'Section CSS of a block', 'ekwa' );
	}
	return $id;
}

/**
 * The <style> id a Section CSS (ekwa/div scopedCss) value prints under.
 * Mirrors ekwa_render_div_block() → ekwa_inline_get_style_inline().
 *
 * @param string $css Stored scopedCss.
 * @return string
 */
function ekwa_page_audit_scoped_style_id( $css ) {
	$decoded = function_exists( 'ekwa_css_decode_entities' ) ? ekwa_css_decode_entities( (string) $css ) : (string) $css;
	return 'ekwa-inline-' . substr( md5( 'scoped-' . md5( $decoded ) ), 0, 12 );
}

// ═══════════════════════════════════════════════════════════════════════════════
// SPECIFICITY + PROPERTIES
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * CSS specificity of one selector as [ids, classes, types].
 *
 * @param string $selector
 * @return int[]
 */
function ekwa_page_audit_css_specificity( $selector ) {
	$s = preg_replace( '/"[^"]*"|\'[^\']*\'/', '', (string) $selector );
	$a = 0;
	$b = 0;
	$c = 0;
	$s = preg_replace( '/:where\((?:[^()]|\([^()]*\))*\)/i', ' ', $s );
	$s = preg_replace_callback(
		'/:(is|not|has|matches)\(((?:[^()]|\([^()]*\))*)\)/i',
		static function ( $m ) use ( &$a, &$b, &$c ) {
			$best = array( 0, 0, 0 );
			foreach ( explode( ',', $m[2] ) as $arg ) {
				$sp = ekwa_page_audit_css_specificity( $arg );
				if ( $sp > $best ) {
					$best = $sp;
				}
			}
			$a += $best[0];
			$b += $best[1];
			$c += $best[2];
			return ' ';
		},
		$s
	);
	$a += preg_match_all( '/#[\w-]+/', $s );
	$b += preg_match_all( '/\.[\w-]+/', $s );
	$b += preg_match_all( '/\[[^\]]*\]/', $s );
	$c += preg_match_all( '/::[\w-]+/', $s );
	$rest = preg_replace( '/::[\w-]+/', '', $s );
	$old  = '/:(before|after|first-line|first-letter)\b/i';
	$c   += preg_match_all( $old, $rest );
	$rest = preg_replace( $old, '', $rest );
	$b   += preg_match_all( '/:[\w-]+(\([^)]*\))?/', $rest );
	$bare = preg_replace( '/#[\w-]+|\.[\w-]+|\[[^\]]*\]|::?[\w-]+(\([^)]*\))?/', ' ', $s );
	$c   += preg_match_all( '/(?:^|[\s>+~])\s*[a-zA-Z][\w-]*/', $bare );
	return array( $a, $b, $c );
}

/**
 * Properties whose declarations compete with $prop (itself, its shorthand,
 * its longhands).
 *
 * @param string $prop
 * @return string[]
 */
function ekwa_page_audit_css_related_props( $prop ) {
	$sides = array( 'top', 'right', 'bottom', 'left' );
	$map   = array(
		'background'                => array( 'background', 'background-color', 'background-image' ),
		'background-color'          => array( 'background', 'background-color' ),
		'border-color'              => array( 'border', 'border-color' ),
		'outline'                   => array( 'outline', 'outline-color', 'outline-style', 'outline-width' ),
		'outline-color'             => array( 'outline', 'outline-color' ),
		'outline-style'             => array( 'outline', 'outline-style' ),
		'outline-width'             => array( 'outline', 'outline-width' ),
		'text-decoration'           => array( 'text-decoration', 'text-decoration-line', 'text-decoration-color', 'text-decoration-style', 'text-decoration-thickness' ),
		'text-decoration-line'      => array( 'text-decoration', 'text-decoration-line' ),
		'text-decoration-color'     => array( 'text-decoration', 'text-decoration-color' ),
		'text-decoration-thickness' => array( 'text-decoration', 'text-decoration-thickness' ),
		'font-size'                 => array( 'font', 'font-size' ),
		'font-weight'               => array( 'font', 'font-weight' ),
		'line-height'               => array( 'font', 'line-height' ),
		'padding'                   => array( 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ),
	);
	foreach ( $sides as $side ) {
		$map[ 'border-' . $side . '-color' ] = array( 'border', 'border-color', 'border-' . $side, 'border-' . $side . '-color' );
		$map[ 'padding-' . $side ]           = array( 'padding', 'padding-' . $side );
	}
	return isset( $map[ $prop ] ) ? $map[ $prop ] : array( $prop );
}

/**
 * Selector usable for matching the default state, or '' when the rule only
 * applies in an interactive state or to a pseudo-element.
 *
 * @param string $selector
 * @return string
 */
function ekwa_page_audit_css_match_selector( $selector ) {
	if ( preg_match( '/:(hover|focus|focus-visible|focus-within|active|visited|target|checked)\b|::?(before|after|marker|placeholder|selection|first-line|first-letter)\b/i', $selector ) ) {
		return '';
	}
	return trim( $selector );
}

/**
 * Elements a selector matches in the snapshot (default state).
 *
 * @param array  $snap
 * @param string $selector
 * @return DOMNode[]|null Null when the selector can't be evaluated.
 */
function ekwa_page_audit_css_nodes( $snap, $selector ) {
	static $memo = array();
	$sel = ekwa_page_audit_css_match_selector( $selector );
	if ( '' === $sel ) {
		return array();
	}
	$key = spl_object_id( $snap['xpath'] ) . '|' . $sel;
	if ( array_key_exists( $key, $memo ) ) {
		return $memo[ $key ];
	}
	$analysis = ekwa_css_selector_analyze( $sel );
	if ( false === $analysis ) {
		return $memo[ $key ] = null;
	}
	$list = @$snap['xpath']->query( $analysis['xpath'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( false === $list ) {
		return $memo[ $key ] = null;
	}
	$out = array();
	foreach ( $list as $node ) {
		$out[] = $node;
	}
	return $memo[ $key ] = $out;
}

// ═══════════════════════════════════════════════════════════════════════════════
// THE CHECK
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Every declaration of the related properties, from every source.
 *
 * @param array $sources  Snapshot sources (possibly with replaced css).
 * @param array $props
 * @return array[] {source, label, order, selector, value, important, spec}
 */
function ekwa_page_audit_cascade_candidates( $sources, $props ) {
	$out   = array();
	$order = 0;
	foreach ( $sources as $si => $src ) {
		$css = (string) $src['css'];
		if ( '' === trim( $css ) ) {
			continue;
		}
		$seen = array();
		ekwa_css_walk( $css, static function ( $rule ) use ( $css, $props, $si, $src, &$out, &$order, &$seen ) {
			if ( null === $rule['body'] ) {
				return;
			}
			foreach ( $rule['chain'] as $prelude ) {
				if ( preg_match( '/^@media\s+print\b/i', trim( $prelude ) ) ) {
					return;
				}
			}
			$k = $rule['start'];
			if ( ! isset( $seen[ $k ] ) ) {
				$seen[ $k ] = ekwa_page_audit_css_decls( $css, $rule['prelude_end'] + 1, $rule['end'] - 1 );
			}
			foreach ( $seen[ $k ] as $di => $decl ) {
				if ( ! in_array( $decl['prop'], $props, true ) ) {
					continue;
				}
				$out[] = array(
					'source'    => $si,
					'source_id' => $src['id'],
					'label'     => $src['label'],
					'order'     => $si * 1000000 + $rule['order'] * 100 + $di,
					'selector'  => trim( $rule['selector'] ),
					'chain'     => $rule['chain'],
					'prop'      => $decl['prop'],
					'value'     => $decl['value'],
					'important' => $decl['important'],
					'spec'      => ekwa_page_audit_css_specificity( $rule['selector'] ),
				);
			}
		} );
		$order++;
	}
	return $out;
}

/**
 * Does a declaration win on the elements its selector matches?
 *
 * @param array  $snap      Snapshot.
 * @param array  $replace   source id => css text to use instead (the patched text).
 * @param string $source_id Source holding our declaration ('ekwa-global-css' or a Section CSS id).
 * @param string $selector  Our rule's selector.
 * @param string $property
 * @param string $value     The value we wrote (identifies our declaration).
 * @return array {status: ok|fails|nomatch|unchecked, elements, wins, winner?: {selector,label,value}, detail}
 */
function ekwa_page_audit_cascade_check( $snap, $replace, $source_id, $selector, $property, $value ) {
	$sources = $snap['sources'];
	$found   = false;
	foreach ( $sources as $i => $src ) {
		if ( isset( $replace[ $src['id'] ] ) ) {
			$sources[ $i ]['css'] = $replace[ $src['id'] ];
		}
		if ( $src['id'] === $source_id ) {
			$found = true;
		}
	}
	if ( ! $found && isset( $replace[ $source_id ] ) ) {
		// The source is not on the page yet (e.g. a stylesheet that was empty):
		// it prints in <head>, before the body's block CSS.
		array_unshift( $sources, array( 'id' => $source_id, 'label' => ekwa_page_audit_source_label( $source_id ), 'css' => $replace[ $source_id ] ) );
	}

	$nodes = ekwa_page_audit_css_nodes( array( 'xpath' => $snap['xpath'] ), $selector );
	if ( null === $nodes || '' === ekwa_page_audit_css_match_selector( $selector ) ) {
		return array( 'status' => 'unchecked', 'elements' => 0, 'wins' => 0, 'detail' => __( 'This selector uses a state or syntax the check cannot evaluate.', 'ekwa' ) );
	}
	if ( ! $nodes ) {
		return array( 'status' => 'nomatch', 'elements' => 0, 'wins' => 0, 'detail' => __( 'The selector matches nothing on this page.', 'ekwa' ) );
	}

	$props = ekwa_page_audit_css_related_props( $property );
	$cands = ekwa_page_audit_cascade_candidates( $sources, $props );
	$want  = ekwa_page_audit_css_norm_value( $value );
	$wsel  = ekwa_page_audit_css_norm_selector( $selector );

	// Ours = the last matching declaration in our source.
	$ours = null;
	foreach ( $cands as $ci => $cand ) {
		if ( $cand['source_id'] === $source_id && $cand['prop'] === $property
			&& ekwa_page_audit_css_norm_selector( $cand['selector'] ) === $wsel
			&& ekwa_page_audit_css_norm_value( $cand['value'] ) === $want ) {
			$ours = $ci;
		}
	}
	if ( null === $ours ) {
		return array( 'status' => 'unchecked', 'elements' => count( $nodes ), 'wins' => 0, 'detail' => __( 'The new declaration was not found in the page’s CSS.', 'ekwa' ) );
	}

	$wins   = 0;
	$winner = null;
	$memo   = array();
	foreach ( $nodes as $node ) {
		$best = null;
		$path = $node->getNodePath();
		foreach ( $cands as $ci => $cand ) {
			if ( $ci !== $ours ) {
				if ( ! isset( $memo[ $cand['selector'] ] ) ) {
					$matched = ekwa_page_audit_css_nodes( array( 'xpath' => $snap['xpath'] ), $cand['selector'] );
					$set     = array();
					foreach ( is_array( $matched ) ? $matched : array() as $mn ) {
						$set[ $mn->getNodePath() ] = true;
					}
					$memo[ $cand['selector'] ] = $set;
				}
				if ( ! isset( $memo[ $cand['selector'] ][ $path ] ) ) {
					continue;
				}
			}
			if ( null === $best || ekwa_page_audit_cascade_beats( $cand, $cands[ $best ] ) ) {
				$best = $ci;
			}
		}
		// Inline style="" outranks every normal rule.
		$inline = null;
		if ( $node instanceof DOMElement && $node->hasAttribute( 'style' ) ) {
			$style = $node->getAttribute( 'style' );
			foreach ( ekwa_page_audit_css_decls( $style, 0, strlen( $style ) ) as $d ) {
				if ( in_array( $d['prop'], $props, true ) ) {
					$inline = $d;
				}
			}
		}
		if ( $inline && ( ! $cands[ $best ]['important'] || $inline['important'] ) ) {
			$winner = $winner ? $winner : array( 'selector' => 'style=""', 'label' => __( 'inline style on the element', 'ekwa' ), 'value' => $inline['value'], 'spec' => array( 9, 9, 9 ), 'important' => $inline['important'] );
			continue;
		}
		if ( $best === $ours ) {
			$wins++;
		} elseif ( ! $winner ) {
			$winner = $cands[ $best ];
		}
	}

	$out = array(
		'status'   => $wins === count( $nodes ) ? 'ok' : 'fails',
		'elements' => count( $nodes ),
		'wins'     => $wins,
	);
	if ( $winner && 'fails' === $out['status'] ) {
		$out['winner'] = array(
			'selector'  => $winner['selector'],
			'label'     => $winner['label'],
			'value'     => $winner['value'],
			'spec'      => $winner['spec'],
			'important' => ! empty( $winner['important'] ),
		);
		/* translators: 1: CSS selector, 2: where it comes from, 3: its value */
		$out['detail'] = sprintf( __( 'Overridden by “%1$s” (%2$s), which sets %3$s.', 'ekwa' ), $winner['selector'], $winner['label'], $winner['value'] );
	} else {
		/* translators: %d: number of elements */
		$out['detail'] = sprintf( _n( 'Takes effect on the %d element it targets.', 'Takes effect on all %d elements it targets.', count( $nodes ), 'ekwa' ), count( $nodes ) );
	}
	return $out;
}

/**
 * Cascade order: does declaration $a win over $b?
 *
 * @param array $a
 * @param array $b
 * @return bool
 */
function ekwa_page_audit_cascade_beats( $a, $b ) {
	if ( $a['important'] !== $b['important'] ) {
		return $a['important'];
	}
	if ( $a['spec'] !== $b['spec'] ) {
		return $a['spec'] > $b['spec'];
	}
	return $a['order'] > $b['order'];
}

/**
 * A stronger selector for the same elements: prefix ancestor classes taken
 * from the page itself until the specificity beats $beat.
 *
 * @param array  $snap
 * @param string $selector
 * @param int[]  $beat Specificity to exceed.
 * @return string '' when no stronger selector matching the same elements was found.
 */
function ekwa_page_audit_cascade_strengthen( $snap, $selector, $beat ) {
	$nodes = ekwa_page_audit_css_nodes( $snap, $selector );
	if ( ! $nodes ) {
		return '';
	}
	$have   = ekwa_page_audit_css_specificity( $selector );
	$prefix = array();
	$node   = $nodes[0]->parentNode;
	$depth  = 0;
	while ( $node instanceof DOMElement && $depth++ < 8 && $have <= $beat ) {
		$classes = preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) );
		foreach ( $classes as $class ) {
			if ( '' === $class || ! preg_match( '/^[A-Za-z_-][\w-]*$/', $class ) || preg_match( '/\.' . preg_quote( $class, '/' ) . '(?![\w-])/', $selector ) || in_array( '.' . $class, $prefix, true ) ) {
				continue;
			}
			if ( preg_match( '/^(wp-|has-|is-layout|wp-container)/', $class ) && count( $classes ) > 1 ) {
				continue; // Prefer the site's own classes over WordPress utility ones.
			}
			// $prefix is outermost-first (each higher ancestor is unshifted).
			$trial = trim( '.' . $class . ' ' . implode( ' ', $prefix ) . ' ' . $selector );
			$tn    = ekwa_page_audit_css_nodes( $snap, $trial );
			if ( is_array( $tn ) && count( $tn ) === count( $nodes ) ) {
				array_unshift( $prefix, '.' . $class );
				$have = ekwa_page_audit_css_specificity( $trial );
				if ( $have > $beat ) {
					return preg_replace( '/\s+/', ' ', $trial );
				}
				break;
			}
		}
		$node = $node->parentNode;
	}
	// Last resort: :root adds one class-level point and matches every page.
	$trial = $selector;
	for ( $n = 0; $n < 4 && ekwa_page_audit_css_specificity( $trial ) <= $beat; $n++ ) {
		$trial = ':root' . ( 0 === $n ? ' ' : '' ) . $trial;
	}
	return ekwa_page_audit_css_specificity( $trial ) > $beat ? $trial : '';
}

/**
 * Rules from the page's other CSS (block CSS, Section CSS) that set
 * properties a fix may change — read-only context for the model.
 *
 * @param array $snap
 * @param int   $limit Bytes.
 * @return array[] {source, selector, media, declarations}
 */
function ekwa_page_audit_other_css( $snap, $limit = 40000 ) {
	$props = ekwa_page_audit_css_properties();
	$out   = array();
	$size  = 0;
	foreach ( $snap['sources'] as $src ) {
		if ( in_array( $src['id'], array( 'ekwa-global-css', 'ekwa-design-tokens' ), true ) || 0 === strpos( $src['id'], 'ekwa-fonts' ) ) {
			continue;
		}
		$css = (string) $src['css'];
		ekwa_css_walk( $css, static function ( $rule ) use ( $css, $src, $props, &$out, &$size, $limit ) {
			if ( null === $rule['body'] || $size > $limit ) {
				return;
			}
			$keep = array();
			foreach ( ekwa_page_audit_css_decls( $css, $rule['prelude_end'] + 1, $rule['end'] - 1 ) as $decl ) {
				if ( in_array( $decl['prop'], $props, true ) || 'text-decoration' === substr( $decl['prop'], 0, 15 ) ) {
					$keep[] = $decl['prop'] . ':' . $decl['value'] . ( $decl['important'] ? ' !important' : '' );
				}
			}
			if ( ! $keep || '' === ekwa_page_audit_css_match_selector( $rule['selector'] ) ) {
				return;
			}
			$row   = array(
				'source'   => $src['label'],
				'selector' => trim( $rule['selector'] ),
				'decls'    => implode( '; ', $keep ),
			);
			if ( $rule['chain'] ) {
				$row['media'] = implode( ' ', $rule['chain'] );
			}
			$size += strlen( (string) wp_json_encode( $row ) );
			$out[] = $row;
		} );
	}
	return $out;
}
