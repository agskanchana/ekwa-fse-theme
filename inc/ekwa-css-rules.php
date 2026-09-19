<?php
/**
 * CSS rule utilities — a small, dependency-free stylesheet walker.
 *
 * Three jobs, all about not losing CSS:
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
 * 3. SECTION EXTRACTION (`ekwa_css_extract_section_rules` +
 *    `ekwa_css_remove_rules_by_key`) — the converter's "move this section's CSS
 *    out of the mockup stylesheet". Rules are selected by actually matching
 *    their selectors against the section's DOM, so nothing rests on a model's
 *    judgement, and they are cut out of the sheet by byte offset, so the
 *    comments and formatting of a hand-written stylesheet survive around them.
 *    Both halves fail toward "leave it in the stylesheet": a rule this can't
 *    parse, can't match or can't classify costs a slightly larger <head>, while
 *    a rule wrongly removed is a broken page. See the section at the bottom.
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
 * Undo the HTML-escaping WordPress applies to CSS stored in a block attribute.
 *
 * On save, WordPress runs every *string* block attribute through `wp_kses()`
 * (core's `filter_block_kses_value()`) for any user without the
 * `unfiltered_html` capability — Authors and Contributors always, everyone on
 * multisite, and everyone (admins included) on a site that defines
 * `DISALLOW_UNFILTERED_HTML`, which hardened and managed hosts commonly do.
 *
 * kses is HTML-aware, not CSS-aware, so it escapes the two characters CSS
 * genuinely needs:
 *
 *     .card > .title  →  .card &gt; .title      (child combinator: rule dies)
 *     url(a.png?x=1&y=2) → url(a.png?x=1&amp;y=2)  (query string breaks)
 *
 * Nothing errors; the rules simply stop matching, which reads as "the scoped
 * CSS disappeared when I saved". kses is idempotent, so the damage never
 * compounds and one decode fully restores the CSS — done at render time here,
 * and on read in the editor so a re-save writes the clean form back.
 *
 * @param string $css Possibly-escaped CSS.
 * @return string
 */
function ekwa_css_decode_entities( $css ) {
	$css = (string) $css;
	if ( false === strpos( $css, '&' ) ) {
		return $css; // Nothing kses could have escaped.
	}
	// Order matters: &amp; last, or "&amp;gt;" would collapse to ">".
	return strtr( $css, array(
		'&gt;'   => '>',
		'&lt;'   => '<',
		'&quot;' => '"',
		'&#039;' => "'",
		'&#39;'  => "'",
		'&amp;'  => '&',
	) );
}

/**
 * Remove CSS comments from a stylesheet.
 *
 * Called where the converter and the AI Block Builder write a *new* value into
 * an ekwa/div `scopedCss` attribute — never over CSS a site already saved.
 *
 * The reason is not size: scopedCss is serialized into the block delimiter in
 * post_content, so every editor save POSTs it to /wp-json/wp/v2/…, and a
 * ModSecurity/OWASP-CRS server reads a bare comment-open sequence in a request
 * body as rule 942440 "SQL Comment Sequence Detected". It answers 403 with an
 * HTML error page, and the block editor — which expected JSON — reports the
 * opaque "Updating failed. The response is not a valid JSON response."
 * AI-written and mockup CSS is full of section comments, so this removes the
 * most common trigger. It is a mitigation, not a fix: the real fix is a rule
 * exclusion on the server. See ekwa_site_health_rest_write_test() in
 * inc/ekwa-site-health.php, which detects the condition and names the rule.
 *
 * Comments are dropped, not replaced with a space: per CSS Syntax a comment is
 * discarded at tokenization and produces no whitespace, so a comment sitting
 * between ".a" and ".b" leaves a compound ".a.b" selector — inserting a space
 * would silently turn it into a descendant selector.
 *
 * Tolerant in the same way as the rest of this file: quoted strings and
 * unquoted url() spans are copied verbatim (a comment-open sequence inside
 * either is data, not a comment), and an unterminated comment leaves the
 * remainder untouched rather than swallowing it.
 *
 * @param string $css Stylesheet.
 * @return string Stylesheet with comments removed.
 */
function ekwa_css_strip_comments( $css ) {
	$css = (string) $css;
	if ( false === strpos( $css, '/*' ) ) {
		return $css; // Nothing to do — the overwhelmingly common case.
	}

	$out = '';
	$len = strlen( $css );
	$i   = 0;

	while ( $i < $len ) {
		$ch = $css[ $i ];

		// Comment — drop it. An unterminated one is not a comment we can trust,
		// so keep everything that follows rather than truncating the stylesheet.
		if ( '/' === $ch && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
			$end = strpos( $css, '*/', $i + 2 );
			if ( false === $end ) {
				$out .= substr( $css, $i );
				break;
			}
			$i = $end + 2;
			continue;
		}

		// Quoted string — copy verbatim, honoring backslash escapes.
		if ( '"' === $ch || "'" === $ch ) {
			$quote = $ch;
			$out  .= $ch;
			$i++;
			while ( $i < $len ) {
				if ( '\\' === $css[ $i ] && $i + 1 < $len ) {
					$out .= substr( $css, $i, 2 );
					$i   += 2;
					continue;
				}
				$out .= $css[ $i ];
				$i++;
				if ( $css[ $i - 1 ] === $quote ) {
					break;
				}
			}
			continue;
		}

		// Unquoted url( … ) — copy verbatim to the closing paren. A path may
		// legitimately contain "/*", and it is not a comment there.
		if ( ( 'u' === $ch || 'U' === $ch ) && 0 === substr_compare( $css, 'url(', $i, 4, true ) ) {
			$end = strpos( $css, ')', $i + 4 );
			if ( false === $end ) {
				$out .= substr( $css, $i );
				break;
			}
			$out .= substr( $css, $i, $end - $i + 1 );
			$i    = $end + 1;
			continue;
		}

		$out .= $ch;
		$i++;
	}

	return $out;
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
 * Plus the rule's byte extents in the ORIGINAL stylesheet, which is what lets
 * ekwa_css_remove_rules_by_key() cut rules out by offset instead of
 * re-serializing the sheet — so comments, blank lines and the author's own
 * formatting survive everywhere a rule wasn't removed:
 *   start          int  Offset of the first character of the selector
 *   end            int  Offset just past the rule's closing "}" (or ";")
 *   prelude_start  int  Same as start
 *   prelude_end    int  Offset of the "{" (or the ";" for statement at-rules)
 *
 * @param string   $css      Stylesheet.
 * @param callable $callback Receives the rule array.
 * @param array    $chain    Internal: enclosing at-rule preludes.
 * @param int      $order    Internal: running rule counter.
 * @param int      $base     Internal: offset of $css within the original sheet.
 * @return int Next order value.
 */
function ekwa_css_walk( $css, $callback, $chain = array(), $order = 0, $base = 0 ) {
	$css       = (string) $css;
	$len       = strlen( $css );
	$i         = 0;
	$buf       = '';
	$buf_start = 0;

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
			if ( '' === trim( $buf ) ) {
				$buf_start = $i;
			}
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
			$start   = $buf_start;
			$buf     = '';
			$i++;
			if ( '' !== $prelude ) {
				call_user_func( $callback, array(
					'selector'      => $prelude,
					'body'          => null, // null = statement, re-emitted as "prelude;"
					'chain'         => $chain,
					'key'           => ekwa_css_rule_key( $chain, $prelude ),
					'order'         => $order,
					'start'         => $base + $start,
					'end'           => $base + $i,
					'prelude_start' => $base + $start,
					'prelude_end'   => $base + $i - 1,
				) );
				$order++;
			}
			continue;
		}

		if ( '{' === $ch ) {
			$prelude = trim( preg_replace( '/\s+/', ' ', $buf ) );
			$start   = $buf_start;
			$open    = $i;
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
					$order = ekwa_css_walk( $body, $callback, $sub, $order, $base + $open + 1 );
					continue;
				}
			}

			// Plain rule (or opaque at-rule): one entry per comma-separated selector.
			foreach ( ekwa_css_split_selectors( $prelude ) as $selector ) {
				call_user_func( $callback, array(
					'selector'      => $selector,
					'body'          => $body,
					'chain'         => $chain,
					'key'           => ekwa_css_rule_key( $chain, $selector ),
					'order'         => $order,
					'start'         => $base + $start,
					'end'           => $base + $close + 1,
					'prelude_start' => $base + $start,
					'prelude_end'   => $base + $open,
				) );
			}
			$order++;
			continue;
		}

		if ( '' === trim( $buf ) && '' !== trim( $ch ) ) {
			$buf_start = $i;
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

// ─────────────────────────────────────────────────────────────────────────────
//  SECTION EXTRACTION — "which of these rules belong to this piece of markup?"
//
//  Used by the Mockup Converter's "Move this section's CSS out of the mockup
//  stylesheet" option: every rule whose selector actually matches an element in
//  the pasted section is lifted into the wrapper's Scoped CSS (with its @media
//  context intact) and cut out of the sheet, so <head> stops carrying CSS that
//  only one section uses.
//
//  Three things are deliberately NEVER claimed, because they are what "common
//  CSS" means: rules that declare only custom properties (and anything under
//  `:root`), at-rules with no selector (@font-face, @keyframes, @import), and
//  selectors with no class / id / attribute of their own — `body`, `h2`,
//  `a:hover`, `ul li` are the site's base typography, not a section's styling.
//  Anything the matcher cannot parse is left in the sheet too: an unmatched rule
//  only means a slightly larger <head>, a wrongly-removed one means a broken
//  page.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Re-serialize a list of walked rules into a stylesheet.
 *
 * Source order is preserved exactly: consecutive rules that share an at-rule
 * chain are emitted inside one wrapper, and a new wrapper starts whenever the
 * chain changes — so a `@media` block never jumps ahead of (or behind) the base
 * rules it was written to override.
 *
 * Selectors that shared a comma group AND a body are merged back together.
 *
 * @param array $rules Rule arrays from ekwa_css_walk(), in source order.
 * @return string Stylesheet.
 */
function ekwa_css_assemble_rules( $rules ) {
	// Group into runs of one at-rule chain, preserving order.
	$runs = array();
	$sig  = null;
	foreach ( $rules as $rule ) {
		$this_sig = implode( '||', (array) $rule['chain'] );
		if ( null === $sig || $this_sig !== $sig ) {
			$runs[] = array( 'chain' => (array) $rule['chain'], 'rules' => array() );
			$sig    = $this_sig;
		}
		$runs[ count( $runs ) - 1 ]['rules'][] = $rule;
	}

	$out = '';
	foreach ( $runs as $run ) {
		$inner         = '';
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

		foreach ( $run['rules'] as $rule ) {
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

		$chain = array_reverse( $run['chain'] );
		foreach ( $chain as $prelude ) {
			$inner = $prelude . " {\n" . ekwa_css_indent( trim( $inner ) ) . "\n}\n";
		}
		$out .= $inner . "\n";
	}

	return trim( preg_replace( "/\n{3,}/", "\n\n", $out ) );
}

/**
 * Cut rules out of a stylesheet by rule identity, editing the source text in
 * place rather than re-serializing it.
 *
 * This is the difference that matters for the mockup stylesheet: it is a file a
 * human wrote and keeps editing, so its section banners, blank lines and
 * formatting have to survive. Only the byte ranges of the removed rules are
 * touched. A comma group that loses some of its selectors keeps its body and
 * its position — just its prelude is rewritten to the selectors that stayed.
 *
 * Matching is by the same normalized identity ekwa_css_subtract() uses, so a
 * rule leaves only when a rule with the same at-rule chain AND selector was
 * asked for. Anything unrecognized is kept.
 *
 * @param string $css  Stylesheet to thin.
 * @param array  $drop Map of rule key => anything (e.g. ekwa_css_rule_keys()).
 * @return array{css:string,removed:int,kept:int}
 */
function ekwa_css_remove_rules_by_key( $css, $drop ) {
	$css = (string) $css;
	if ( empty( $drop ) || ! is_array( $drop ) || '' === trim( $css ) ) {
		return array( 'css' => $css, 'removed' => 0, 'kept' => 0 );
	}

	$groups  = array(); // start offset => rule group.
	$removed = 0;
	$kept    = 0;

	ekwa_css_walk( $css, function ( $rule ) use ( &$groups, &$removed, &$kept, $drop ) {
		if ( ! isset( $rule['start'] ) ) {
			return; // Defensive: a walker without offsets can't be edited safely.
		}
		$id = (int) $rule['start'];
		if ( ! isset( $groups[ $id ] ) ) {
			$groups[ $id ] = array(
				'start'         => (int) $rule['start'],
				'end'           => (int) $rule['end'],
				'prelude_start' => (int) $rule['prelude_start'],
				'prelude_end'   => (int) $rule['prelude_end'],
				'keep'          => array(),
				'dropped'       => 0,
			);
		}
		if ( isset( $drop[ $rule['key'] ] ) ) {
			$groups[ $id ]['dropped']++;
			$removed++;
		} else {
			$groups[ $id ]['keep'][] = $rule['selector'];
			$kept++;
		}
	} );

	if ( 0 === $removed ) {
		return array( 'css' => $css, 'removed' => 0, 'kept' => $kept );
	}

	// Build the edit list, then apply back-to-front so earlier offsets stay valid.
	$edits = array();
	foreach ( $groups as $group ) {
		if ( ! $group['dropped'] ) {
			continue;
		}
		if ( empty( $group['keep'] ) ) {
			$edits[] = array( 'from' => $group['start'], 'to' => $group['end'], 'text' => '' );
		} else {
			$edits[] = array(
				'from' => $group['prelude_start'],
				'to'   => $group['prelude_end'],
				'text' => implode( ', ', $group['keep'] ) . ' ',
			);
		}
	}
	usort( $edits, function ( $a, $b ) {
		return $b['from'] - $a['from'];
	} );

	foreach ( $edits as $edit ) {
		$from = $edit['from'];
		$to   = min( $edit['to'], strlen( $css ) );
		if ( '' === $edit['text'] ) {
			// Take the whole line with it: trailing spaces + one newline, and the
			// indentation in front when the rule started its own line. Otherwise a
			// removal leaves a blank, indented hole behind.
			$len = strlen( $css );
			while ( $to < $len && ( ' ' === $css[ $to ] || "\t" === $css[ $to ] || "\r" === $css[ $to ] ) ) {
				$to++;
			}
			if ( $to < $len && "\n" === $css[ $to ] ) {
				$to++;
			}
			$j = $from;
			while ( $j > 0 && ( ' ' === $css[ $j - 1 ] || "\t" === $css[ $j - 1 ] ) ) {
				$j--;
			}
			if ( 0 === $j || "\n" === $css[ $j - 1 ] ) {
				$from = $j;
			}
		}
		$css = substr( $css, 0, $from ) . $edit['text'] . substr( $css, $to );

		// Collapse the blank-line pile-up the removal left behind — but only at
		// the seam, so blank lines the author put elsewhere are untouched.
		if ( '' === $edit['text'] ) {
			$css = ekwa_css_collapse_blank_lines_at( $css, $from );
		}
	}

	// Drop the @media/@supports shells the removals emptied, innermost first.
	$pattern = '/@(?:media|supports|layer|container|document|scope)\b[^{}]*\{\s*\}[ \t]*\r?\n?/i';
	do {
		$before = $css;
		$css    = preg_replace( $pattern, '', $css );
		if ( null === $css ) {
			$css = $before; // preg failure (e.g. bad UTF-8) — keep what we had.
			break;
		}
	} while ( $css !== $before );

	return array( 'css' => rtrim( $css ) . "\n", 'removed' => $removed, 'kept' => $kept );
}

/**
 * Collapse a run of blank lines around one offset down to a single blank line.
 *
 * Cutting a rule out joins whatever surrounded it, and two rules that each sat
 * on their own paragraph leave three or four newlines behind. Only the
 * whitespace run touching $at is rewritten; the rest of the sheet is untouched.
 *
 * @param string $css Stylesheet.
 * @param int    $at  Offset the removal closed over.
 * @return string
 */
function ekwa_css_collapse_blank_lines_at( $css, $at ) {
	$len = strlen( $css );
	$at  = max( 0, min( $at, $len ) );

	$start = $at;
	while ( $start > 0 && false !== strpos( " \t\r\n", $css[ $start - 1 ] ) ) {
		$start--;
	}
	$end = $at;
	while ( $end < $len && false !== strpos( " \t\r\n", $css[ $end ] ) ) {
		$end++;
	}

	$run = substr( $css, $start, $end - $start );
	if ( substr_count( $run, "\n" ) < 3 ) {
		return $css;
	}
	return substr( $css, 0, $start ) . "\n\n" . substr( $css, $end );
}

/**
 * Does this declaration block contain nothing but custom properties?
 *
 * Those are design tokens — they stay in the stylesheet no matter which section
 * happens to match their selector, because every other section reads them.
 *
 * @param string $body Declaration block, braces excluded.
 * @return bool
 */
function ekwa_css_body_is_vars_only( $body ) {
	$body = ekwa_css_strip_comments( (string) $body );
	if ( '' === trim( $body ) ) {
		return true; // Empty rule — nothing worth moving either way.
	}
	foreach ( explode( ';', $body ) as $decl ) {
		$decl = trim( $decl );
		if ( '' === $decl ) {
			continue;
		}
		if ( 0 !== strpos( $decl, '--' ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Read one CSS identifier, honoring backslash escapes.
 *
 * `.w-1\/2` is a class literally named "w-1/2", so an escape contributes the
 * escaped character itself — otherwise the generated XPath would look for a
 * class that no element has.
 *
 * @param string $text  Compound selector text.
 * @param int    $i     Cursor, advanced past the identifier.
 * @return string The identifier ('' when the cursor isn't on one).
 */
function ekwa_css_read_ident( $text, &$i ) {
	$len  = strlen( $text );
	$out  = '';
	while ( $i < $len ) {
		$ch = $text[ $i ];
		if ( '\\' === $ch && $i + 1 < $len ) {
			$out .= $text[ $i + 1 ];
			$i   += 2;
			continue;
		}
		if ( ctype_alnum( $ch ) || '-' === $ch || '_' === $ch || ord( $ch ) >= 0x80 ) {
			$out .= $ch;
			$i++;
			continue;
		}
		break;
	}
	return $out;
}

/**
 * Quote a string for use as an XPath 1.0 literal (which has no escape syntax —
 * a value containing both quote characters has to be built with concat()).
 *
 * @param string $value
 * @return string
 */
function ekwa_css_xpath_literal( $value ) {
	$value = (string) $value;
	if ( false === strpos( $value, "'" ) ) {
		return "'" . $value . "'";
	}
	if ( false === strpos( $value, '"' ) ) {
		return '"' . $value . '"';
	}
	$parts = array();
	foreach ( explode( "'", $value ) as $i => $chunk ) {
		if ( $i > 0 ) {
			$parts[] = '"\'"';
		}
		if ( '' !== $chunk ) {
			$parts[] = "'" . $chunk . "'";
		}
	}
	return 'concat(' . implode( ',', $parts ) . ')';
}

/**
 * Translate one compound selector ("a.btn[target]:hover") into an XPath step.
 *
 * Pseudo-classes and pseudo-elements are dropped: `.btn:hover` and `.card::after`
 * belong to `.btn` and `.card`, and dropping the state keeps them with the
 * markup that has those elements. A compound left with nothing but a pseudo
 * (`:hover`, `::selection`) is unresolvable and reported as such.
 *
 * @param string $text One compound selector, no combinators.
 * @return array{tag:string,preds:string[],tokens:string[],identity:bool}|false
 */
function ekwa_css_parse_compound( $text ) {
	$text     = (string) $text;
	$len      = strlen( $text );
	$i        = 0;
	$tag      = '';
	$preds    = array();
	$tokens   = array();
	$identity = false;
	$any      = false;

	while ( $i < $len ) {
		$ch = $text[ $i ];

		if ( '*' === $ch ) {
			$i++;
			$any = true;
			continue;
		}

		if ( '.' === $ch || '#' === $ch ) {
			$i++;
			$name = ekwa_css_read_ident( $text, $i );
			if ( '' === $name ) {
				return false;
			}
			if ( '.' === $ch ) {
				$preds[] = "[contains(concat(' ', normalize-space(@class), ' '), "
					. ekwa_css_xpath_literal( ' ' . $name . ' ' ) . ')]';
			} else {
				$preds[] = '[@id=' . ekwa_css_xpath_literal( $name ) . ']';
			}
			$tokens[] = strtolower( $name );
			$identity = true;
			$any      = true;
			continue;
		}

		if ( '[' === $ch ) {
			$close = strpos( $text, ']', $i );
			if ( false === $close ) {
				return false;
			}
			$inner = substr( $text, $i + 1, $close - $i - 1 );
			$i     = $close + 1;
			$pred  = ekwa_css_attr_predicate( $inner );
			if ( false === $pred ) {
				return false;
			}
			$preds[]  = $pred;
			$identity = true;
			$any      = true;
			continue;
		}

		if ( ':' === $ch ) {
			$i++;
			if ( $i < $len && ':' === $text[ $i ] ) {
				$i++;
			}
			$name = ekwa_css_read_ident( $text, $i );
			if ( '' === $name ) {
				return false;
			}
			if ( $i < $len && '(' === $text[ $i ] ) {
				$depth = 0;
				while ( $i < $len ) {
					if ( '(' === $text[ $i ] ) {
						$depth++;
					} elseif ( ')' === $text[ $i ] ) {
						$depth--;
						if ( 0 === $depth ) {
							$i++;
							break;
						}
					}
					$i++;
				}
			}
			continue;
		}

		// Type selector — only one per compound, and only in first position.
		$name = ekwa_css_read_ident( $text, $i );
		if ( '' === $name || '' !== $tag ) {
			return false;
		}
		$tag = strtolower( $name );
		$any = true;
	}

	if ( ! $any ) {
		return false;
	}

	return array(
		'tag'      => $tag,
		'preds'    => $preds,
		'tokens'   => $tokens,
		'identity' => $identity,
	);
}

/**
 * XPath predicate for one attribute selector body ("href^=https").
 *
 * @param string $inner Text between the square brackets.
 * @return string|false
 */
function ekwa_css_attr_predicate( $inner ) {
	$re = '/^\s*([-\w]+)\s*(?:([~^$*|]?=)\s*("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s\]]+)(?:\s+([iIsS]))?\s*)?$/';
	if ( ! preg_match( $re, (string) $inner, $m ) ) {
		return false;
	}
	$attr = $m[1];
	if ( ! isset( $m[2] ) || '' === $m[2] ) {
		return '[@' . $attr . ']';
	}
	if ( ! empty( $m[4] ) ) {
		return false; // Case-sensitivity flag — XPath 1.0 has no equivalent.
	}
	$value = $m[3];
	if ( strlen( $value ) > 1 && ( '"' === $value[0] || "'" === $value[0] ) && $value[ strlen( $value ) - 1 ] === $value[0] ) {
		$value = stripslashes( substr( $value, 1, -1 ) );
	}
	$lit = ekwa_css_xpath_literal( $value );

	switch ( $m[2] ) {
		case '=':
			return '[@' . $attr . '=' . $lit . ']';
		case '~=':
			return "[contains(concat(' ', normalize-space(@" . $attr . "), ' '), " . ekwa_css_xpath_literal( ' ' . $value . ' ' ) . ')]';
		case '^=':
			return '[starts-with(@' . $attr . ',' . $lit . ')]';
		case '$=':
			return '[substring(@' . $attr . ', string-length(@' . $attr . ') - ' . ( strlen( $value ) - 1 ) . ') = ' . $lit . ']';
		case '*=':
			return '[contains(@' . $attr . ',' . $lit . ')]';
		case '|=':
			return '[@' . $attr . '=' . $lit . ' or starts-with(@' . $attr . ',' . ekwa_css_xpath_literal( $value . '-' ) . ')]';
	}
	return false;
}

/**
 * Analyze a single CSS selector.
 *
 * @param string $selector One selector (no comma groups).
 * @return array{xpath:string,identity:bool,tokens:string[]}|false False when the
 *         selector uses syntax this translator doesn't handle — treated as
 *         "leave it in the stylesheet".
 */
function ekwa_css_selector_analyze( $selector ) {
	$sel = trim( (string) $selector );
	if ( '' === $sel || '@' === $sel[0] ) {
		return false;
	}

	// 1. Split into (combinator, compound) steps at top level.
	$parts = array();
	$buf   = '';
	$comb  = '';
	$depth = 0;
	$len   = strlen( $sel );

	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $sel[ $i ];

		if ( '\\' === $ch && $i + 1 < $len ) {
			$buf .= substr( $sel, $i, 2 );
			$i++;
			continue;
		}
		if ( '"' === $ch || "'" === $ch ) {
			$quote = $ch;
			$buf  .= $ch;
			$i++;
			while ( $i < $len ) {
				if ( '\\' === $sel[ $i ] && $i + 1 < $len ) {
					$buf .= substr( $sel, $i, 2 );
					$i   += 2;
					continue;
				}
				$buf .= $sel[ $i ];
				$i++;
				if ( $sel[ $i - 1 ] === $quote ) {
					break;
				}
			}
			$i--;
			continue;
		}
		if ( '(' === $ch || '[' === $ch ) {
			$depth++;
			$buf .= $ch;
			continue;
		}
		if ( ')' === $ch || ']' === $ch ) {
			$depth--;
			$buf .= $ch;
			continue;
		}
		if ( $depth > 0 ) {
			$buf .= $ch;
			continue;
		}
		if ( ' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch || "\f" === $ch ) {
			if ( '' !== $buf ) {
				$parts[] = array( 'comb' => $comb, 'text' => $buf );
				$buf     = '';
				$comb    = ' ';
			} elseif ( '' === $comb ) {
				$comb = ' ';
			}
			continue;
		}
		if ( '>' === $ch || '+' === $ch || '~' === $ch ) {
			if ( '' !== $buf ) {
				$parts[] = array( 'comb' => $comb, 'text' => $buf );
				$buf     = '';
			}
			$comb = $ch;
			continue;
		}
		$buf .= $ch;
	}
	if ( '' !== $buf ) {
		$parts[] = array( 'comb' => $comb, 'text' => $buf );
	}
	if ( empty( $parts ) ) {
		return false;
	}

	// 2. Compile each step.
	$xpath    = '';
	$tokens   = array();
	$identity = false;

	foreach ( $parts as $index => $part ) {
		$compound = ekwa_css_parse_compound( $part['text'] );
		if ( false === $compound ) {
			return false;
		}
		$identity = $identity || $compound['identity'];
		$tokens   = array_merge( $tokens, $compound['tokens'] );

		$step = ( '' === $compound['tag'] ? '*' : $compound['tag'] ) . implode( '', $compound['preds'] );

		if ( 0 === $index ) {
			$xpath .= '//' . $step;
		} elseif ( '>' === $part['comb'] ) {
			$xpath .= '/' . $step;
		} elseif ( '+' === $part['comb'] ) {
			$xpath .= '/following-sibling::*[1]/self::' . $step;
		} elseif ( '~' === $part['comb'] ) {
			$xpath .= '/following-sibling::' . $step;
		} else {
			$xpath .= '//' . $step;
		}
	}

	return array(
		'xpath'    => $xpath,
		'identity' => $identity,
		'tokens'   => array_values( array_unique( $tokens ) ),
	);
}

/**
 * Parse an HTML fragment into a DOMXPath for selector matching.
 *
 * The fragment is wrapped in a synthetic root so several top-level siblings all
 * survive — LIBXML_HTML_NOIMPLIED otherwise keeps only the first, which is the
 * same trick ekwa_mc_convert_html() uses. No implied <html>/<body> is created,
 * so a `body .hero` rule does not match a pasted section and stays global.
 *
 * @param string $html Section markup.
 * @return DOMXPath|null
 */
function ekwa_css_dom_xpath( $html ) {
	$html = (string) $html;
	if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
		return null;
	}
	$doc  = new DOMDocument();
	$prev = libxml_use_internal_errors( true );
	$ok   = $doc->loadHTML(
		'<?xml encoding="utf-8"?><div data-ekwa-css-root="1">' . $html . '</div>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	if ( ! $ok || ! $doc->documentElement ) {
		return null;
	}
	return new DOMXPath( $doc );
}

/**
 * Normalize a "keep these in the stylesheet" list into a lookup set.
 *
 * Accepts an array or a comma/newline separated string of class names, ids or
 * simple selectors (".btn", "#header", "container"). A trailing "*" makes it a
 * prefix rule, so "btn*" protects .btn, .btn-primary and .btn--ghost at once.
 *
 * @param string|array $raw
 * @return array{exact:array<string,true>,prefix:string[]}
 */
function ekwa_css_keep_tokens( $raw ) {
	$items  = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
	$exact  = array();
	$prefix = array();
	foreach ( (array) $items as $item ) {
		$item = strtolower( trim( (string) $item ) );
		$item = ltrim( $item, '.#' );
		if ( '' === $item ) {
			continue;
		}
		if ( '*' === substr( $item, -1 ) ) {
			$stem = substr( $item, 0, -1 );
			if ( '' !== $stem ) {
				$prefix[] = $stem;
			}
			continue;
		}
		$exact[ $item ] = true;
	}
	return array( 'exact' => $exact, 'prefix' => $prefix );
}

/**
 * Select the rules in a stylesheet that style a given HTML fragment.
 *
 * $keep matches on class/id TOKENS, so ".btn" protects `.btn`, `.btn:hover` and
 * the `.btn` inside a media query in one entry. $exclude matches whole
 * selectors instead, for the cases where only one spelling should be spared.
 *
 * @param string       $css     Stylesheet to read (never modified).
 * @param string       $html    Section markup.
 * @param string|array $keep    Class/id tokens that must stay in the stylesheet.
 * @param array        $exclude Whole selectors that must stay, matched verbatim.
 * @return array{scoped:string,keys:array<string,true>,selectors:string[],moved:int,kept_shared:string[]}
 */
function ekwa_css_extract_section_rules( $css, $html, $keep = array(), $exclude = array() ) {
	$result = array(
		'scoped'      => '',
		'keys'        => array(),
		'selectors'   => array(),
		'moved'       => 0,
		'kept_shared' => array(),
	);

	$css = (string) $css;
	if ( '' === trim( $css ) ) {
		return $result;
	}
	$xpath = ekwa_css_dom_xpath( $html );
	if ( ! $xpath ) {
		return $result;
	}

	$keep    = ekwa_css_keep_tokens( $keep );
	$cache   = array();
	$matched = array();
	$shared  = array();
	$keys    = array();
	$labels  = array();

	// Every class and id the fragment actually contains. A selector naming one
	// that isn't here cannot match, and skipping the XPath for those takes a
	// real mockup stylesheet (3,000+ rules) from hundreds of queries to a few
	// dozen. Folded to lowercase on both sides, which only ever lets MORE
	// selectors through to the real (case-sensitive) match below.
	$present = array();
	foreach ( $xpath->query( '//*[@class or @id]' ) as $node ) {
		foreach ( preg_split( '/\s+/', (string) $node->getAttribute( 'class' ) ) as $class ) {
			if ( '' !== $class ) {
				$present[ strtolower( $class ) ] = true;
			}
		}
		$id = (string) $node->getAttribute( 'id' );
		if ( '' !== $id ) {
			$present[ strtolower( $id ) ] = true;
		}
	}

	// Whole-selector exclusions, normalized the same way rule keys are.
	$skip = array();
	foreach ( (array) $exclude as $selector ) {
		$selector = trim( (string) $selector );
		if ( '' !== $selector ) {
			$skip[ ekwa_css_rule_key( array(), $selector ) ] = true;
		}
	}

	ekwa_css_walk( $css, function ( $rule ) use ( &$matched, &$cache, &$shared, &$keys, &$labels, $xpath, $keep, $skip, $present ) {
		$selector = $rule['selector'];

		// Never claimed: statements (@import), at-rules with no selector
		// (@font-face, @keyframes), :root, and pure custom-property blocks.
		if ( null === $rule['body'] || '' === trim( $selector ) ) {
			return;
		}
		if ( '@' === substr( ltrim( $selector ), 0, 1 ) ) {
			return;
		}
		if ( false !== stripos( $selector, ':root' ) ) {
			return;
		}
		if ( ekwa_css_body_is_vars_only( $rule['body'] ) ) {
			return;
		}
		if ( isset( $skip[ ekwa_css_rule_key( array(), $selector ) ] ) ) {
			$shared[ $selector ] = true;
			return;
		}

		if ( ! array_key_exists( $selector, $cache ) ) {
			$cache[ $selector ] = ekwa_css_selector_analyze( $selector );
		}
		$analysis = $cache[ $selector ];

		// Unparseable, or base CSS with no class/id/attribute of its own.
		if ( false === $analysis || ! $analysis['identity'] ) {
			return;
		}

		// Definite miss — the fragment has no element with that class or id.
		foreach ( $analysis['tokens'] as $token ) {
			if ( ! isset( $present[ $token ] ) ) {
				return;
			}
		}

		foreach ( $analysis['tokens'] as $token ) {
			if ( isset( $keep['exact'][ $token ] ) ) {
				$shared[ $selector ] = true;
				return;
			}
			foreach ( $keep['prefix'] as $stem ) {
				if ( 0 === strpos( $token, $stem ) ) {
					$shared[ $selector ] = true;
					return;
				}
			}
		}

		$nodes = @$xpath->query( $analysis['xpath'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $nodes || 0 === $nodes->length ) {
			return;
		}

		$chain = (array) $rule['chain'];

		$matched[]            = $rule;
		$keys[ $rule['key'] ] = true;
		$labels[]             = empty( $chain ) ? $selector : ( $selector . '  ·  ' . end( $chain ) );
	} );

	$result['scoped']      = ekwa_css_assemble_rules( $matched );
	$result['keys']        = $keys;
	$result['selectors']   = $labels;
	$result['moved']       = count( $matched );
	$result['kept_shared'] = array_keys( $shared );

	return $result;
}
