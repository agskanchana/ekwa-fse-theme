<?php
/**
 * CSS rule utilities — a small, dependency-free stylesheet walker.
 *
 * Two jobs, both about not losing CSS:
 *
 * 1. SUBTRACTION (`ekwa_css_subtract`) — the converter's "Extract this
 *    section's CSS with AI" used to trust the model to echo the entire
 *    remaining pool back verbatim as a LEFTOVER half, and that leftover was
 *    written over the site-wide Global CSS. When the model dropped rules (or
 *    returned an empty SCOPED half while still omitting the section's rules
 *    from LEFTOVER), those rules were gone from <head> and the section rendered
 *    unstyled. Now the leftover is computed HERE instead: pool minus the rules
 *    the model actually claimed. Nothing can be dropped, because nothing is
 *    ever taken on the model's word — a rule leaves the pool only when a rule
 *    with the same selector shows up in the scoped CSS.
 *
 * 2. FONT VARIABLE REWRITING (`ekwa_css_rewrite_font_families`) — see
 *    ekwa-fonts.php.
 *
 * The walker is deliberately tolerant: it never validates CSS, only finds rule
 * boundaries. Anything it can't classify is preserved untouched.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * At-rules whose body contains further rules (so the walker descends into
 * them). Everything else with a body (@font-face, @keyframes, @page…) is
 * treated as one opaque rule.
 */
function ekwa_css_nesting_at_rules() {
	return array( 'media', 'supports', 'layer', 'container', 'document', 'scope' );
}

/**
 * Walk a stylesheet and hand every rule to a callback.
 *
 * The callback receives one array per rule:
 *   selector  string  A single selector (comma groups are split, one call each)
 *   body      string  Raw declaration block, braces excluded
 *   chain     array   Enclosing at-rule preludes, outermost first ("@media …")
 *   key       string  Normalized "chain||selector" identity used for matching
 *   order     int     Source order, for stable re-assembly
 *
 * @param string   $css      Stylesheet.
 * @param callable $callback Receives the rule array.
 * @param array    $chain    Internal: enclosing at-rule preludes.
 * @param int      $order    Internal: running rule counter.
 * @return int Next order value.
 */
function ekwa_css_walk( $css, $callback, $chain = array(), $order = 0 ) {
	$css = (string) $css;
	$len = strlen( $css );
	$i   = 0;
	$buf = '';

	while ( $i < $len ) {
		$ch = $css[ $i ];

		// Comments — dropped from preludes, kept inside bodies (substr keeps them).
		if ( '/' === $ch && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
			$end = strpos( $css, '*/', $i + 2 );
			$i   = ( false === $end ) ? $len : $end + 2;
			continue;
		}

		// Strings — copied verbatim so braces/semicolons inside can't confuse us.
		if ( '"' === $ch || "'" === $ch ) {
			$quote = $ch;
			$buf  .= $ch;
			$i++;
			while ( $i < $len ) {
				if ( '\\' === $css[ $i ] && $i + 1 < $len ) {
					$buf .= substr( $css, $i, 2 );
					$i   += 2;
					continue;
				}
				$buf .= $css[ $i ];
				$i++;
				if ( $css[ $i - 1 ] === $quote ) {
					break;
				}
			}
			continue;
		}

		// Statement at-rule (@import/@charset/@namespace) — no body.
		if ( ';' === $ch ) {
			$prelude = trim( $buf );
			$buf     = '';
			$i++;
			if ( '' !== $prelude ) {
				call_user_func( $callback, array(
					'selector' => $prelude,
					'body'     => null, // null = statement, re-emitted as "prelude;"
					'chain'    => $chain,
					'key'      => ekwa_css_rule_key( $chain, $prelude ),
					'order'    => $order,
				) );
				$order++;
			}
			continue;
		}

		if ( '{' === $ch ) {
			$prelude = trim( preg_replace( '/\s+/', ' ', $buf ) );
			$buf     = '';
			$close   = ekwa_css_find_block_end( $css, $i );
			$body    = substr( $css, $i + 1, $close - $i - 1 );
			$i       = $close + 1;

			// Nesting at-rule → descend, carrying the prelude as context.
			if ( '@' === substr( $prelude, 0, 1 ) ) {
				$name = strtolower( preg_replace( '/^@([a-z-]*).*$/is', '$1', $prelude ) );
				if ( in_array( $name, ekwa_css_nesting_at_rules(), true ) ) {
					$sub   = $chain;
					$sub[] = $prelude;
					$order = ekwa_css_walk( $body, $callback, $sub, $order );
					continue;
				}
			}

			// Plain rule (or opaque at-rule): one entry per comma-separated selector.
			foreach ( ekwa_css_split_selectors( $prelude ) as $selector ) {
				call_user_func( $callback, array(
					'selector' => $selector,
					'body'     => $body,
					'chain'    => $chain,
					'key'      => ekwa_css_rule_key( $chain, $selector ),
					'order'    => $order,
				) );
			}
			$order++;
			continue;
		}

		$buf .= $ch;
		$i++;
	}

	return $order;
}

/**
 * Index of the opening brace's matching close brace, respecting nested braces,
 * strings and comments. Returns the string length when unbalanced (truncated
 * CSS still parses to "everything that's left").
 *
 * @param string $css   Stylesheet.
 * @param int    $open  Index of the opening "{".
 * @return int Index of the matching "}", or strlen when unbalanced.
 */
function ekwa_css_find_block_end( $css, $open ) {
	$len   = strlen( $css );
	$depth = 1;
	$i     = $open + 1;

	while ( $i < $len ) {
		$ch = $css[ $i ];

		if ( '/' === $ch && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
			$end = strpos( $css, '*/', $i + 2 );
			$i   = ( false === $end ) ? $len : $end + 2;
			continue;
		}
		if ( '"' === $ch || "'" === $ch ) {
			$quote = $ch;
			$i++;
			while ( $i < $len ) {
				if ( '\\' === $css[ $i ] && $i + 1 < $len ) {
					$i += 2;
					continue;
				}
				$i++;
				if ( $css[ $i - 1 ] === $quote ) {
					break;
				}
			}
			continue;
		}
		if ( '{' === $ch ) {
			$depth++;
		} elseif ( '}' === $ch ) {
			$depth--;
			if ( 0 === $depth ) {
				return $i;
			}
		}
		$i++;
	}

	return $len;
}

/**
 * Split a selector group on top-level commas (commas inside :is()/:not()/
 * strings stay put).
 *
 * @param string $prelude Selector group.
 * @return string[] Individual selectors (never empty — falls back to the input).
 */
function ekwa_css_split_selectors( $prelude ) {
	$out   = array();
	$buf   = '';
	$depth = 0;
	$len   = strlen( $prelude );

	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $prelude[ $i ];
		if ( '"' === $ch || "'" === $ch ) {
			$quote = $ch;
			$buf  .= $ch;
			$i++;
			while ( $i < $len ) {
				$buf .= $prelude[ $i ];
				if ( $prelude[ $i ] === $quote && '\\' !== $prelude[ $i - 1 ] ) {
					break;
				}
				$i++;
			}
			continue;
		}
		if ( '(' === $ch || '[' === $ch ) {
			$depth++;
		} elseif ( ')' === $ch || ']' === $ch ) {
			$depth--;
		}
		if ( ',' === $ch && $depth <= 0 ) {
			$out[] = trim( $buf );
			$buf   = '';
			continue;
		}
		$buf .= $ch;
	}
	$out[] = trim( $buf );

	$out = array_values( array_filter( $out, 'strlen' ) );
	return empty( $out ) ? array( trim( $prelude ) ) : $out;
}

/**
 * Normalized identity for a rule: enclosing at-rules + selector, whitespace and
 * case folded so cosmetic reformatting by the AI still matches the source rule.
 *
 * @param array  $chain    Enclosing at-rule preludes.
 * @param string $selector Single selector.
 * @return string
 */
function ekwa_css_rule_key( $chain, $selector ) {
	// At-rule preludes: whitespace is never semantic inside them, so
	// "@media (max-width: 1080px)" and "@media(max-width:1080px)" are one
	// context. (Spacing IS semantic in selectors — "a :hover" ≠ "a:hover" — so
	// selectors get the narrower normalizer below.)
	$norm_at = function ( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = preg_replace( '/\s+/', ' ', $s );
		$s = preg_replace( '/\s*([:,()])\s*/', '$1', $s );
		return $s;
	};

	$norm_sel = function ( $s ) {
		$s = strtolower( trim( (string) $s ) );
		$s = preg_replace( '/\s+/', ' ', $s );
		$s = preg_replace( '/\s*([>+~,])\s*/', '$1', $s );
		$s = str_replace( array( '"', "'" ), '', $s );
		return $s;
	};

	$parts   = array_map( $norm_at, (array) $chain );
	$parts[] = $norm_sel( $selector );
	return implode( '||', $parts );
}

/**
 * Every rule identity present in a stylesheet.
 *
 * @param string $css Stylesheet.
 * @return array<string,int> key => occurrence count.
 */
function ekwa_css_rule_keys( $css ) {
	$keys = array();
	ekwa_css_walk( $css, function ( $rule ) use ( &$keys ) {
		if ( ! isset( $keys[ $rule['key'] ] ) ) {
			$keys[ $rule['key'] ] = 0;
		}
		$keys[ $rule['key'] ]++;
	} );
	return $keys;
}

/**
 * Subtract one stylesheet's rules from another, by selector identity.
 *
 * This is how the Global CSS pool is thinned after a section's CSS is
 * extracted: `ekwa_css_subtract( $pool, $scoped )` returns the pool with every
 * rule the section claimed removed, and everything else byte-for-byte intact,
 * in its original order and inside its original @media/@supports wrappers.
 *
 * Only rules whose selector appears in $remove are dropped, so a model that
 * forgets a rule, mangles the response, or gets truncated can only ever leave
 * the pool LARGER than intended — never smaller than it should be. Duplicate
 * CSS is harmless; missing CSS is a broken page.
 *
 * @param string $css    Stylesheet to thin (the pool).
 * @param string $remove Stylesheet whose selectors should be removed.
 * @return array{css:string,removed:int,kept:int} Thinned CSS and rule counts.
 */
function ekwa_css_subtract( $css, $remove ) {
	$drop = ekwa_css_rule_keys( $remove );

	// Group surviving rules back under their at-rule chain, preserving order.
	$groups  = array(); // chain-signature => { chain, order, rules[] }
	$removed = 0;
	$kept    = 0;

	ekwa_css_walk( $css, function ( $rule ) use ( &$groups, &$removed, &$kept, $drop ) {
		if ( isset( $drop[ $rule['key'] ] ) ) {
			$removed++;
			return;
		}
		$kept++;
		$sig = implode( '||', $rule['chain'] );
		if ( ! isset( $groups[ $sig ] ) ) {
			$groups[ $sig ] = array(
				'chain' => $rule['chain'],
				'order' => $rule['order'],
				'rules' => array(),
			);
		}
		$groups[ $sig ]['rules'][] = $rule;
	} );

	$out = '';
	foreach ( $groups as $group ) {
		$inner = '';
		// Merge selectors that shared a comma group AND a body back together.
		$pending_body  = null;
		$pending_sels  = array();
		$pending_order = null;

		$flush = function () use ( &$inner, &$pending_body, &$pending_sels, &$pending_order ) {
			if ( empty( $pending_sels ) ) {
				return;
			}
			if ( null === $pending_body ) {
				$inner .= implode( ', ', $pending_sels ) . ";\n";
			} else {
				$body   = ekwa_css_dedent( $pending_body );
				$inner .= implode( ', ', $pending_sels ) . ' {'
					. ( '' === $body ? '' : "\n" . ekwa_css_indent( $body ) . "\n" )
					. "}\n";
			}
			$pending_sels  = array();
			$pending_body  = null;
			$pending_order = null;
		};

		foreach ( $group['rules'] as $rule ) {
			if ( $pending_order === $rule['order'] && $pending_body === $rule['body'] ) {
				$pending_sels[] = $rule['selector'];
				continue;
			}
			$flush();
			$pending_sels  = array( $rule['selector'] );
			$pending_body  = $rule['body'];
			$pending_order = $rule['order'];
		}
		$flush();

		if ( '' === trim( $inner ) ) {
			continue;
		}

		// Re-wrap in the at-rule chain, innermost last.
		$chain = array_reverse( $group['chain'] );
		foreach ( $chain as $prelude ) {
			$inner = $prelude . " {\n" . ekwa_css_indent( trim( $inner ) ) . "\n}\n";
		}
		$out .= $inner . "\n";
	}

	return array(
		'css'     => trim( preg_replace( "/\n{3,}/", "\n\n", $out ) ),
		'removed' => $removed,
		'kept'    => $kept,
	);
}

/**
 * Indent every line by one tab (used when re-wrapping rules in an at-rule).
 *
 * @param string $text
 * @return string
 */
function ekwa_css_indent( $text ) {
	return preg_replace( '/^(?=.)/m', "\t", (string) $text );
}

/**
 * Strip the common leading indentation from a declaration block, so a rule
 * lifted out of an @media wrapper doesn't keep that wrapper's extra level.
 *
 * @param string $text
 * @return string
 */
function ekwa_css_dedent( $text ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
	$min   = null;
	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		$indent = strlen( $line ) - strlen( ltrim( $line, " \t" ) );
		$min    = ( null === $min ) ? $indent : min( $min, $indent );
	}
	if ( ! $min ) {
		return trim( (string) $text );
	}
	foreach ( $lines as $i => $line ) {
		$lines[ $i ] = substr( $line, $min );
	}
	return trim( implode( "\n", $lines ) );
}
