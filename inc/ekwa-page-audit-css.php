<?php
/**
 * Page audit — fixes applied to the site stylesheet.
 *
 * Most accessibility problems on an Ekwa site are colours, and on a converted
 * site every colour comes from the site stylesheet (Ekwa Settings → Design
 * Setup) — usually through a CSS variable. So the audit's AI can propose a
 * "css" fix: change one declaration of one existing rule (or add one), and
 * optionally define ONE new colour variable next to the site's own tokens.
 *
 * The edit is made in place, the way a person would make it, and leaves a
 * short searchable note behind:
 *
 *     .eyebrow{
 *       /* ekwa-fix:k3j9x2qa — text contrast 2.9:1 → 4.8:1 (was color:var(--gold)) *\/ color:var(--gold-ink);
 *     }
 *     :root{ … --gold-ink:#8a6a3a; /* ekwa-fix:k3j9x2qa — new colour for contrast *\/ }
 *
 * Every change is recorded as exact text patches (what was removed, what was
 * inserted — the inserted text always carries the unique marker), so Undo
 * puts the stylesheet back byte for byte. If someone edited those lines since,
 * Undo refuses rather than guess, and the marker tells them where to look.
 *
 * Where the CSS lives:
 *   current model — the Mockup stylesheet (option ekwa_mockup_css), rules and
 *                   :root variables in one text;
 *   legacy model  — rules in the Global CSS pool (ekwa_global_css), variables
 *                   in the CSS variables field (ekwa_tokens_colors). That field
 *                   is re-serialized on every settings save, which drops
 *                   comments — Undo copes by finding the declaration itself.
 *
 * Nothing here runs unless someone applies, undoes or compares a fix.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// TARGET + TOKENS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Where the site's CSS rules and colour variables are stored.
 *
 * @return array{model:string,rules:string,vars:string,label:string}
 */
function ekwa_page_audit_css_target() {
	$legacy = function_exists( 'ekwa_tokens_legacy_mode' ) && ekwa_tokens_legacy_mode();
	if ( $legacy ) {
		return array(
			'model' => 'legacy',
			'rules' => 'ekwa_global_css',
			'vars'  => 'ekwa_tokens_colors',
			'label' => __( 'Global CSS (Ekwa Settings → Design Setup)', 'ekwa' ),
		);
	}
	return array(
		'model' => 'mockup',
		'rules' => 'ekwa_mockup_css',
		'vars'  => 'ekwa_mockup_css',
		'label' => __( 'Mockup stylesheet (Ekwa Settings → Design Setup)', 'ekwa' ),
	);
}

/**
 * Current text of every option a target touches.
 *
 * @param array $target
 * @return array<string,string> option => text
 */
function ekwa_page_audit_css_texts( $target ) {
	$texts = array();
	foreach ( array_unique( array( $target['rules'], $target['vars'] ) ) as $option ) {
		$value            = get_option( $option, '' );
		$texts[ $option ] = is_string( $value ) ? $value : '';
	}
	return $texts;
}

/**
 * Load the theme's CSS walker on demand.
 */
function ekwa_page_audit_css_require() {
	if ( ! function_exists( 'ekwa_css_walk' ) ) {
		require_once get_template_directory() . '/inc/ekwa-css-rules.php';
	}
}

/**
 * The site's context-free custom properties (name with dashes => value).
 *
 * @param array $target
 * @param array $texts
 * @return array<string,string>
 */
function ekwa_page_audit_css_tokens( $target, $texts ) {
	ekwa_page_audit_css_require();
	$source = isset( $texts[ $target['vars'] ] ) ? $texts[ $target['vars'] ] : '';
	// Comments first: a commented-out declaration is not a token.
	$source = function_exists( 'ekwa_css_strip_comments' ) ? ekwa_css_strip_comments( $source ) : preg_replace( '#/\*.*?\*/#s', '', $source );
	$out    = array();
	if ( function_exists( 'ekwa_tokens_parse_var_groups' ) ) {
		foreach ( ekwa_tokens_parse_var_groups( $source ) as $group ) {
			if ( ! empty( $group['chain'] ) ) {
				continue;
			}
			foreach ( $group['decls'] as $name => $value ) {
				$out[ '--' . $name ] = (string) $value;
			}
		}
	}
	// Background-image and font variables are defined elsewhere but are tokens too.
	if ( function_exists( 'ekwa_tokens_defined_var_names' ) ) {
		foreach ( array_keys( ekwa_tokens_defined_var_names() ) as $name ) {
			if ( ! isset( $out[ '--' . $name ] ) ) {
				$out[ '--' . $name ] = '';
			}
		}
	}
	return $out;
}

// ═══════════════════════════════════════════════════════════════════════════════
// VALUES + COLOURS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Properties a stylesheet fix may change.
 *
 * @return string[]
 */
function ekwa_page_audit_css_properties() {
	$props = array(
		'color', 'background-color', 'background',
		'border-color', 'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
		'outline', 'outline-color', 'outline-offset', 'outline-style', 'outline-width',
		'text-decoration', 'text-decoration-line', 'text-decoration-color', 'text-decoration-thickness', 'text-underline-offset',
		'fill', 'stroke', 'opacity',
		'font-size', 'font-weight', 'line-height', 'letter-spacing', 'word-spacing',
		'min-height', 'min-width', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
		'content-visibility', 'contain-intrinsic-size', 'aspect-ratio',
	);
	/**
	 * Filter the CSS properties a page-audit stylesheet fix may change.
	 *
	 * @param string[] $props
	 */
	$props = apply_filters( 'ekwa_page_audit_css_properties', $props );
	return is_array( $props ) ? array_values( array_map( 'strtolower', $props ) ) : array();
}

/**
 * Is a value safe to write into the stylesheet? (No new blocks, comments,
 * imports or URLs — a fix changes one value, it never adds structure.)
 *
 * @param string $value
 * @return bool
 */
function ekwa_page_audit_css_safe_value( $value ) {
	$value = (string) $value;
	if ( '' === trim( $value ) || strlen( $value ) > 200 ) {
		return false;
	}
	if ( preg_match( '#[{};<>\\\\]|/\*|\*/|@|url\s*\(|expression\s*\(|javascript:|behavior\s*:#i', $value ) ) {
		return false;
	}
	return (bool) preg_match( '/^[a-zA-Z0-9#%(),.\s\-+*\/_!\'"]+$/', $value );
}

/**
 * Normalize a selector for comparison.
 *
 * @param string $selector
 * @return string
 */
function ekwa_page_audit_css_norm_selector( $selector ) {
	$s = preg_replace( '#/\*.*?\*/#s', '', (string) $selector );
	$s = preg_replace( '/\s+/', ' ', trim( $s ) );
	$s = preg_replace( '/\s*([>+~,])\s*/', '$1', $s );
	return strtolower( $s );
}

/**
 * Normalize an at-rule chain ("@media (max-width: 768px)") for comparison.
 *
 * @param string|string[] $media
 * @return string
 */
function ekwa_page_audit_css_norm_media( $media ) {
	$parts = is_array( $media ) ? $media : ( '' === trim( (string) $media ) ? array() : array( (string) $media ) );
	$out   = array();
	foreach ( $parts as $p ) {
		$p = strtolower( trim( preg_replace( '/\s+/', ' ', (string) $p ) ) );
		$p = preg_replace( '/\(\s+/', '(', $p );
		$p = preg_replace( '/\s+\)/', ')', $p );
		$p = preg_replace( '/\s*:\s*/', ':', $p );
		if ( '' !== $p ) {
			$out[] = $p;
		}
	}
	return implode( ' ', $out );
}

/**
 * Normalize a declaration value for comparison.
 *
 * @param string $value
 * @return string
 */
function ekwa_page_audit_css_norm_value( $value ) {
	$v = preg_replace( '#/\*.*?\*/#s', '', (string) $value );
	$v = preg_replace( '/!\s*important\s*$/i', '', $v );
	$v = strtolower( trim( preg_replace( '/\s+/', ' ', $v ) ) );
	$v = preg_replace( '/\(\s+/', '(', $v );
	$v = preg_replace( '/\s+\)/', ')', $v );
	$v = preg_replace( '/\s*,\s*/', ',', $v );
	$v = preg_replace_callback( '/#([0-9a-f]{3})\b/', static function ( $m ) {
		return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
	}, $v );
	return $v;
}

/**
 * Resolve a CSS colour value to #rrggbb through the site's tokens.
 *
 * @param string $value
 * @param array  $tokens name => value.
 * @param int    $depth
 * @return string '' when it is not a plain colour.
 */
function ekwa_page_audit_css_resolve_color( $value, $tokens, $depth = 0 ) {
	$v = strtolower( trim( preg_replace( '/!\s*important\s*$/i', '', (string) $value ) ) );
	if ( $depth > 6 || '' === $v ) {
		return '';
	}
	if ( preg_match( '/^var\(\s*(--[a-z0-9_-]+)\s*(?:,\s*(.+))?\)$/i', $v, $m ) ) {
		foreach ( $tokens as $name => $tv ) {
			if ( strtolower( $name ) === strtolower( $m[1] ) && '' !== $tv ) {
				return ekwa_page_audit_css_resolve_color( $tv, $tokens, $depth + 1 );
			}
		}
		return isset( $m[2] ) ? ekwa_page_audit_css_resolve_color( $m[2], $tokens, $depth + 1 ) : '';
	}
	if ( function_exists( 'ekwa_page_audit_hex' ) ) {
		$hex = ekwa_page_audit_hex( $v );
		if ( '' !== $hex ) {
			return $hex;
		}
	}
	if ( preg_match( '/^rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})/', $v, $m ) ) {
		return sprintf( '#%02x%02x%02x', min( 255, (int) $m[1] ), min( 255, (int) $m[2] ), min( 255, (int) $m[3] ) );
	}
	$named = array( 'white' => '#ffffff', 'black' => '#000000', '#fff' => '#ffffff', '#000' => '#000000' );
	return isset( $named[ $v ] ) ? $named[ $v ] : '';
}

/**
 * Which colour role a property paints.
 *
 * @param string $prop
 * @return string text|background|border|icon|''
 */
function ekwa_page_audit_css_role( $prop ) {
	if ( 'color' === $prop || 'text-decoration-color' === $prop ) {
		return 'text';
	}
	if ( 'background' === $prop || 'background-color' === $prop ) {
		return 'background';
	}
	if ( 0 === strpos( $prop, 'border' ) || 0 === strpos( $prop, 'outline' ) ) {
		return 'border';
	}
	if ( 'fill' === $prop || 'stroke' === $prop ) {
		return 'icon';
	}
	return '';
}

// ═══════════════════════════════════════════════════════════════════════════════
// LOCATING RULES + DECLARATIONS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Rules in $css whose selector (one of a comma group, or the whole group) and
 * at-rule context match. One entry per rule, in source order.
 *
 * @param string $css
 * @param string $selector
 * @param string $media
 * @return array[] Walker rule arrays plus 'prelude' (the full selector text).
 */
function ekwa_page_audit_css_find_rules( $css, $selector, $media ) {
	ekwa_page_audit_css_require();
	$want_sel   = ekwa_page_audit_css_norm_selector( $selector );
	$want_media = ekwa_page_audit_css_norm_media( $media );
	$hits       = array();

	ekwa_css_walk( $css, static function ( $rule ) use ( $css, $want_sel, $want_media, &$hits ) {
		if ( null === $rule['body'] || isset( $hits[ $rule['start'] ] ) ) {
			return;
		}
		if ( ekwa_page_audit_css_norm_media( $rule['chain'] ) !== $want_media ) {
			return;
		}
		$prelude = substr( $css, $rule['prelude_start'], $rule['prelude_end'] - $rule['prelude_start'] );
		if ( ekwa_page_audit_css_norm_selector( $rule['selector'] ) === $want_sel || ekwa_page_audit_css_norm_selector( $prelude ) === $want_sel ) {
			$rule['prelude']        = trim( preg_replace( '/\s+/', ' ', preg_replace( '#/\*.*?\*/#s', '', $prelude ) ) );
			$hits[ $rule['start'] ] = $rule;
		}
	} );

	return array_values( $hits );
}

/**
 * Declarations inside $css[$from, $to) — a rule body — with their offsets.
 * Comments and strings are skipped; nested rules are left alone.
 *
 * @param string $css
 * @param int    $from
 * @param int    $to
 * @return array[] {prop, value, important, start, end, text}
 */
function ekwa_page_audit_css_decls( $css, $from, $to ) {
	$out   = array();
	$i     = $from;
	$start = null;
	$depth = 0;

	while ( $i < $to ) {
		$ch = $css[ $i ];
		if ( '/' === $ch && $i + 1 < $to && '*' === $css[ $i + 1 ] ) {
			$end = strpos( $css, '*/', $i + 2 );
			$i   = ( false === $end || $end + 2 > $to ) ? $to : $end + 2;
			continue;
		}
		if ( '"' === $ch || "'" === $ch ) {
			if ( null === $start ) {
				$start = $i;
			}
			$quote = $ch;
			$i++;
			while ( $i < $to ) {
				if ( '\\' === $css[ $i ] ) {
					$i += 2;
					continue;
				}
				if ( $css[ $i ] === $quote ) {
					$i++;
					break;
				}
				$i++;
			}
			continue;
		}
		if ( '{' === $ch ) {
			// A nested rule (CSS nesting) — opaque, not a declaration.
			$close = function_exists( 'ekwa_css_find_block_end' ) ? ekwa_css_find_block_end( $css, $i ) : $to;
			$i     = min( $to, $close + 1 );
			$start = null;
			continue;
		}
		if ( '(' === $ch ) {
			$depth++;
		} elseif ( ')' === $ch ) {
			$depth = max( 0, $depth - 1 );
		}
		if ( ';' === $ch && 0 === $depth ) {
			if ( null !== $start ) {
				$decl = ekwa_page_audit_css_decl( $css, $start, $i + 1 );
				if ( $decl ) {
					$out[] = $decl;
				}
			}
			$start = null;
			$i++;
			continue;
		}
		if ( null === $start && ! ctype_space( $ch ) ) {
			$start = $i;
		}
		$i++;
	}

	if ( null !== $start ) {
		$end = $to;
		while ( $end > $start && ctype_space( $css[ $end - 1 ] ) ) {
			$end--;
		}
		$decl = ekwa_page_audit_css_decl( $css, $start, $end );
		if ( $decl ) {
			$out[] = $decl;
		}
	}
	return $out;
}

/**
 * One declaration from its text span.
 *
 * @param string $css
 * @param int    $start
 * @param int    $end   Exclusive; includes the ';' when there is one.
 * @return array|null
 */
function ekwa_page_audit_css_decl( $css, $start, $end ) {
	$text  = substr( $css, $start, $end - $start );
	$colon = strpos( $text, ':' );
	if ( false === $colon ) {
		return null;
	}
	$prop = strtolower( trim( preg_replace( '#/\*.*?\*/#s', '', substr( $text, 0, $colon ) ) ) );
	if ( '' === $prop || ! preg_match( '/^-?[a-z][a-z0-9-]*$/', $prop ) ) {
		return null;
	}
	$value     = rtrim( substr( $text, $colon + 1 ) );
	$value     = preg_replace( '/;\s*$/', '', $value );
	$value     = trim( preg_replace( '#/\*.*?\*/#s', '', $value ) );
	$important = (bool) preg_match( '/!\s*important\s*$/i', $value );
	if ( $important ) {
		$value = trim( preg_replace( '/!\s*important\s*$/i', '', $value ) );
	}
	return array(
		'prop'      => $prop,
		'value'     => $value,
		'important' => $important,
		'start'     => $start,
		'end'       => $end,
		'text'      => $text,
	);
}

/**
 * Offset just past the last declaration/comment in a rule body, i.e. where an
 * added declaration goes, and whether a ';' must be added first.
 *
 * @param string $css
 * @param int    $body_start
 * @param int    $body_end   Offset of the closing brace.
 * @return array{pos:int,needs_semicolon:bool,indent:string,multiline:bool}
 */
function ekwa_page_audit_css_body_tail( $css, $body_start, $body_end ) {
	$pos = $body_end;
	while ( $pos > $body_start && ctype_space( $css[ $pos - 1 ] ) ) {
		$pos--;
	}
	// Last significant character, ignoring trailing comments.
	$probe = $pos;
	while ( $probe >= $body_start + 2 && '/' === $css[ $probe - 1 ] && '*' === $css[ $probe - 2 ] ) {
		$open = strrpos( substr( $css, $body_start, $probe - $body_start - 2 ), '/*' );
		if ( false === $open ) {
			break;
		}
		$probe = $body_start + $open;
		while ( $probe > $body_start && ctype_space( $css[ $probe - 1 ] ) ) {
			$probe--;
		}
	}
	$last      = $probe > $body_start ? $css[ $probe - 1 ] : '';
	$body      = substr( $css, $body_start, $body_end - $body_start );
	$multiline = false !== strpos( $body, "\n" );
	$indent    = '  ';
	if ( $multiline && preg_match( '/\n([ \t]+)\S[^\n]*$/', rtrim( substr( $css, $body_start, $pos - $body_start ) ), $m ) ) {
		$indent = $m[1];
	}
	return array(
		'pos'             => $pos,
		'needs_semicolon' => '' !== $last && ';' !== $last && '{' !== $last,
		'indent'          => $indent,
		'multiline'       => $multiline,
	);
}

/**
 * Find where one edit applies in $css.
 *
 * @param string $css
 * @param array  $edit {selector, media, property, from, to}
 * @return array|WP_Error {mode: replace|add|new, rule?, decl?, actual_from?, group?}
 */
function ekwa_page_audit_css_locate( $css, $edit ) {
	$rules = ekwa_page_audit_css_find_rules( $css, $edit['selector'], $edit['media'] );
	$prop  = $edit['property'];
	$want  = ekwa_page_audit_css_norm_value( $edit['from'] );

	$exact = null;
	$any   = array();
	foreach ( $rules as $rule ) {
		foreach ( ekwa_page_audit_css_decls( $css, $rule['prelude_end'] + 1, $rule['end'] - 1 ) as $decl ) {
			if ( $decl['prop'] !== $prop ) {
				continue;
			}
			$any[] = array( 'rule' => $rule, 'decl' => $decl );
			if ( '' !== $want && ekwa_page_audit_css_norm_value( $decl['value'] ) === $want ) {
				$exact = array( 'rule' => $rule, 'decl' => $decl ); // Last one wins, as in the cascade.
			}
		}
	}

	$hit = $exact;
	if ( ! $hit && $any ) {
		// The model misquoted the value, or asked to "add" a property the rule
		// already sets: change the one declaration the rule actually has.
		if ( 1 === count( $any ) || '' === $want ) {
			$hit = end( $any );
		} else {
			return new WP_Error( 'ekwa_page_audit_css_ambiguous', __( 'That rule sets this property more than once, so the change could not be placed automatically.', 'ekwa' ) );
		}
	}

	if ( $hit ) {
		$group = ekwa_page_audit_css_norm_selector( $hit['rule']['prelude'] ) !== ekwa_page_audit_css_norm_selector( $edit['selector'] ) ? $hit['rule']['prelude'] : '';
		return array(
			'mode'        => 'replace',
			'rule'        => $hit['rule'],
			'decl'        => $hit['decl'],
			'actual_from' => $hit['decl']['value'],
			'group'       => $group,
		);
	}

	if ( '' !== $want ) {
		return new WP_Error( 'ekwa_page_audit_css_missing', __( 'The rule this fix changes is not in the stylesheet (any more).', 'ekwa' ) );
	}
	if ( $rules ) {
		$rule  = end( $rules );
		$group = ekwa_page_audit_css_norm_selector( $rule['prelude'] ) !== ekwa_page_audit_css_norm_selector( $edit['selector'] ) ? $rule['prelude'] : '';
		return array( 'mode' => 'add', 'rule' => $rule, 'group' => $group );
	}
	return array( 'mode' => 'new' );
}

/**
 * Offset of the first top-level :root rule's closing brace, or -1.
 *
 * @param string $css
 * @return array{open:int,close:int}|null
 */
function ekwa_page_audit_css_root_rule( $css ) {
	ekwa_page_audit_css_require();
	$found = null;
	ekwa_css_walk( $css, static function ( $rule ) use ( &$found ) {
		if ( null !== $found || null === $rule['body'] || ! empty( $rule['chain'] ) ) {
			return;
		}
		if ( ':root' === strtolower( trim( $rule['selector'] ) ) ) {
			$found = array( 'open' => $rule['prelude_end'], 'close' => $rule['end'] - 1 );
		}
	} );
	return $found;
}

/**
 * Offset after any leading @charset / @import statements (a new :root block
 * must not go above them — an @import after a rule is ignored).
 *
 * @param string $css
 * @return int
 */
function ekwa_page_audit_css_after_imports( $css ) {
	if ( preg_match( '/\A(?:\s*(?:\/\*.*?\*\/)|\s*@(?:charset|import)\b[^;]*;)*/is', $css, $m ) ) {
		return strlen( $m[0] );
	}
	return 0;
}

// ═══════════════════════════════════════════════════════════════════════════════
// APPLY + REVERT (pure: texts in, texts out)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Text for the comment left in the stylesheet.
 *
 * @param string $text
 * @param int    $max
 * @return string
 */
function ekwa_page_audit_css_comment_text( $text, $max = 90 ) {
	$text = str_replace( array( '/*', '*/', '{', '}', '<', '>' ), ' ', (string) $text );
	$text = trim( preg_replace( '/\s+/', ' ', $text ) );
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
		$text = rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}
	return $text;
}

/**
 * Apply a validated css fix to the stylesheet texts.
 *
 * @param array  $target  From ekwa_page_audit_css_target().
 * @param array  $texts   option => current text.
 * @param array  $css     {edits: [...], vars: [...], note: string}
 * @param string $marker  e.g. "ekwa-fix:k3j9x2qa".
 * @return array|WP_Error {texts, patches}
 */
function ekwa_page_audit_css_apply( $target, $texts, $css, $marker ) {
	$patches = array();
	$note    = ekwa_page_audit_css_comment_text( isset( $css['note'] ) ? $css['note'] : '' );

	// Section CSS (an ekwa/div scopedCss attribute) is saved through the block
	// editor's REST request, and ModSecurity's CRS rule 942440 reads a CSS
	// comment there as an SQL comment and answers 403 — so in Section CSS the
	// old value is kept in a do-nothing custom property instead of a comment:
	//     --ekwa-fix-k3j9x2qa:"was color:var(--gold) — text contrast"; color:var(--gold-ink);
	if ( isset( $css['marker_style'] ) && 'prop' === $css['marker_style'] ) {
		return ekwa_page_audit_css_apply_prop_style( $texts, $css, $target['rules'], str_replace( ':', '-', $marker ), $note );
	}

	// 1. New variables, next to the site's own tokens.
	foreach ( isset( $css['vars'] ) ? (array) $css['vars'] : array() as $var ) {
		$option = $target['vars'];
		$text   = isset( $texts[ $option ] ) ? $texts[ $option ] : '';
		$tokens = ekwa_page_audit_css_tokens( $target, $texts );
		$name   = $var['name'];
		if ( isset( $tokens[ $name ] ) ) {
			if ( ekwa_page_audit_css_norm_value( $tokens[ $name ] ) === ekwa_page_audit_css_norm_value( $var['value'] ) ) {
				continue; // Already defined with this value.
			}
			return new WP_Error( 'ekwa_page_audit_css_var', sprintf( /* translators: %s: variable name */ __( '%s already exists with a different value; the fix never changes an existing colour token.', 'ekwa' ), $name ) );
		}
		$comment = '/* ' . $marker . ' — ' . __( 'new colour for this fix', 'ekwa' ) . ' */';

		if ( 'legacy' === $target['model'] ) {
			$decl     = $name . ': ' . $var['value'] . '; ' . $comment;
			$pos      = strlen( $text );
			$inserted = ( '' === trim( $text ) ? '' : ( "\n" === substr( $text, -1 ) ? '' : "\n" ) ) . $decl . "\n";
		} else {
			$root = ekwa_page_audit_css_root_rule( $text );
			if ( $root ) {
				$tail     = ekwa_page_audit_css_body_tail( $text, $root['open'] + 1, $root['close'] );
				$pos      = $tail['pos'];
				$inserted = ( $tail['needs_semicolon'] ? ';' : '' )
					. ( $tail['multiline'] ? "\n" . $tail['indent'] : ' ' )
					. $name . ':' . $var['value'] . '; ' . $comment;
			} else {
				$pos      = ekwa_page_audit_css_after_imports( $text );
				$inserted = ( $pos > 0 ? "\n" : '' ) . ":root{\n  " . $name . ':' . $var['value'] . '; ' . $comment . "\n}\n";
			}
		}
		$texts[ $option ] = substr( $text, 0, $pos ) . $inserted . substr( $text, $pos );
		$patches[]        = array(
			'option'   => $option,
			'kind'     => 'var',
			'removed'  => '',
			'inserted' => $inserted,
			'name'     => $name,
			'value'    => $var['value'],
		);
	}

	// 2. Declarations, one at a time (each re-located in the text as it is now).
	foreach ( isset( $css['edits'] ) ? (array) $css['edits'] : array() as $edit ) {
		$option = $target['rules'];
		$text   = isset( $texts[ $option ] ) ? $texts[ $option ] : '';
		$loc    = ekwa_page_audit_css_locate( $text, $edit );
		if ( is_wp_error( $loc ) ) {
			return $loc;
		}
		$label = '' !== $note ? ' — ' . $note : '';

		if ( 'replace' === $loc['mode'] ) {
			$decl    = $loc['decl'];
			$removed = substr( $text, $decl['start'], $decl['end'] - $decl['start'] );
			$was     = trim( preg_replace( '/\s+/', ' ', rtrim( trim( $removed ), ';' ) ) );
			if ( false !== strpos( $was, '*/' ) || false !== strpos( $was, '/*' ) ) {
				return new WP_Error( 'ekwa_page_audit_css_comment', __( 'The declaration this fix replaces contains a comment, so it was not changed automatically.', 'ekwa' ) );
			}
			$value    = $edit['to'];
			$value   .= ( $decl['important'] && ! preg_match( '/!\s*important\s*$/i', $value ) ) ? ' !important' : '';
			$inserted = '/* ' . $marker . $label . ' (was ' . $was . ') */ ' . $decl['prop'] . ':' . $value . ';';
			$pos      = $decl['start'];
		} elseif ( 'add' === $loc['mode'] ) {
			$rule     = $loc['rule'];
			$tail     = ekwa_page_audit_css_body_tail( $text, $rule['prelude_end'] + 1, $rule['end'] - 1 );
			$removed  = '';
			$inserted = ( $tail['needs_semicolon'] ? ';' : '' )
				. ( $tail['multiline'] ? "\n" . $tail['indent'] : ' ' )
				. '/* ' . $marker . $label . ' (added) */ ' . $edit['property'] . ':' . $edit['to'] . ';';
			$pos      = $tail['pos'];
		} else {
			$rule = $edit['selector'] . '{' . $edit['property'] . ':' . $edit['to'] . ';}';
			if ( '' !== trim( $edit['media'] ) ) {
				$rule = trim( $edit['media'] ) . '{' . $rule . '}';
			}
			$removed  = '';
			$pos      = strlen( $text );
			$inserted = ( '' === trim( $text ) ? '' : "\n\n" ) . '/* ' . $marker . $label . ' (new rule) */' . "\n" . $rule . "\n";
		}

		$texts[ $option ] = substr( $text, 0, $pos ) . $inserted . substr( $text, $pos + strlen( $removed ) );
		$patches[]        = array(
			'option'   => $option,
			'kind'     => $loc['mode'],
			'removed'  => $removed,
			'inserted' => $inserted,
		);
	}

	return array( 'texts' => $texts, 'patches' => $patches );
}

/**
 * The Section CSS variant of ekwa_page_audit_css_apply(): same placement,
 * custom-property markers instead of comments, no new tokens (those belong in
 * the site stylesheet; the validator inlines their value instead).
 *
 * @param array  $texts
 * @param array  $css
 * @param string $option Key of the text to edit.
 * @param string $marker Custom property name without dashes, e.g. "ekwa-fix-k3j9x2qa".
 * @param string $note
 * @return array|WP_Error {texts, patches}
 */
function ekwa_page_audit_css_apply_prop_style( $texts, $css, $option, $marker, $note ) {
	$patches = array();
	$quote   = static function ( $s ) {
		return '"' . trim( preg_replace( '/\s+/', ' ', str_replace( array( '"', '\\', '{', '}', ';' ), array( "'", '', '(', ')', ',' ), (string) $s ) ) ) . '"';
	};
	$prop = '--' . preg_replace( '/[^a-z0-9-]/', '', strtolower( $marker ) );
	foreach ( isset( $css['edits'] ) ? (array) $css['edits'] : array() as $edit ) {
		$text = isset( $texts[ $option ] ) ? $texts[ $option ] : '';
		$loc  = ekwa_page_audit_css_locate( $text, $edit );
		if ( is_wp_error( $loc ) ) {
			return $loc;
		}
		$label = '' !== $note ? ' — ' . $note : '';
		if ( 'replace' === $loc['mode'] ) {
			$decl     = $loc['decl'];
			$removed  = substr( $text, $decl['start'], $decl['end'] - $decl['start'] );
			$was      = trim( preg_replace( '/\s+/', ' ', rtrim( trim( $removed ), ';' ) ) );
			$value    = $edit['to'] . ( ( $decl['important'] && ! preg_match( '/!\s*important\s*$/i', $edit['to'] ) ) ? ' !important' : '' );
			$inserted = $prop . ':' . $quote( 'was ' . $was . $label ) . '; ' . $decl['prop'] . ':' . $value . ';';
			$pos      = $decl['start'];
		} elseif ( 'add' === $loc['mode'] ) {
			$rule     = $loc['rule'];
			$tail     = ekwa_page_audit_css_body_tail( $text, $rule['prelude_end'] + 1, $rule['end'] - 1 );
			$removed  = '';
			$inserted = ( $tail['needs_semicolon'] ? ';' : '' ) . ( $tail['multiline'] ? "\n" . $tail['indent'] : ' ' )
				. $prop . ':' . $quote( 'added' . $label ) . '; ' . $edit['property'] . ':' . $edit['to'] . ';';
			$pos      = $tail['pos'];
		} else {
			$rule = $edit['selector'] . '{' . $prop . ':' . $quote( 'new rule' . $label ) . ';' . $edit['property'] . ':' . $edit['to'] . ';}';
			if ( '' !== trim( $edit['media'] ) ) {
				$rule = trim( $edit['media'] ) . '{' . $rule . '}';
			}
			$removed  = '';
			$pos      = strlen( $text );
			$inserted = ( '' === trim( $text ) ? '' : "\n" ) . $rule . "\n";
		}
		$texts[ $option ] = substr( $text, 0, $pos ) . $inserted . substr( $text, $pos + strlen( $removed ) );
		$patches[]        = array(
			'option'   => $option,
			'kind'     => $loc['mode'],
			'removed'  => $removed,
			'inserted' => $inserted,
		);
	}
	return array( 'texts' => $texts, 'patches' => $patches );
}

/**
 * Undo recorded patches, newest first.
 *
 * @param array $texts   option => current text (every option the patches name).
 * @param array $patches From ekwa_page_audit_css_apply().
 * @return array|WP_Error option => text.
 */
function ekwa_page_audit_css_revert( $texts, $patches ) {
	foreach ( array_reverse( (array) $patches ) as $patch ) {
		$option = isset( $patch['option'] ) ? (string) $patch['option'] : '';
		$text   = isset( $texts[ $option ] ) ? (string) $texts[ $option ] : '';
		$ins    = isset( $patch['inserted'] ) ? (string) $patch['inserted'] : '';
		$count  = '' === $ins ? 0 : substr_count( $text, $ins );

		if ( 1 === $count ) {
			$pos              = strpos( $text, $ins );
			$texts[ $option ] = substr( $text, 0, $pos ) . ( isset( $patch['removed'] ) ? (string) $patch['removed'] : '' ) . substr( $text, $pos + strlen( $ins ) );
			continue;
		}

		if ( 0 === $count && 'var' === ( isset( $patch['kind'] ) ? $patch['kind'] : '' ) && ! empty( $patch['name'] ) ) {
			// The legacy variables field is re-serialized on every settings save
			// (comments dropped): find the declaration itself.
			$re = '/^[ \t]*' . preg_quote( $patch['name'], '/' ) . '\s*:\s*' . preg_quote( trim( (string) $patch['value'] ), '/' ) . '\s*;[^\n]*(?:\n|$)/m';
			if ( 1 === preg_match_all( $re, $text ) ) {
				$texts[ $option ] = preg_replace( $re, '', $text, 1 );
				continue;
			}
		}

		return new WP_Error(
			'ekwa_page_audit_css_changed',
			__( 'The stylesheet was edited where this fix changed it, so it was not undone automatically. Search the stylesheet for the fix’s “ekwa-fix:” note to revert it by hand.', 'ekwa' ),
			array( 'status' => 409 )
		);
	}

	// A variable this fix added must not be removed while something still uses it.
	foreach ( (array) $patches as $patch ) {
		if ( 'var' !== ( isset( $patch['kind'] ) ? $patch['kind'] : '' ) || empty( $patch['name'] ) ) {
			continue;
		}
		foreach ( $texts as $text ) {
			if ( preg_match( '/var\(\s*' . preg_quote( $patch['name'], '/' ) . '\s*[,)]/i', (string) $text ) ) {
				return new WP_Error(
					'ekwa_page_audit_css_in_use',
					sprintf( /* translators: %s: CSS variable name */ __( '%s, which this fix added, is now used by another change. Undo that change first.', 'ekwa' ), $patch['name'] ),
					array( 'status' => 409 )
				);
			}
		}
	}

	return $texts;
}

// ═══════════════════════════════════════════════════════════════════════════════
// VALIDATING THE AI'S ANSWER
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Stylesheet context for the model: the CSS it may edit and the tokens.
 *
 * @return array|null {model, label, stylesheet, tokens[], reduced}
 */
function ekwa_page_audit_css_context() {
	$target = ekwa_page_audit_css_target();
	$texts  = ekwa_page_audit_css_texts( $target );
	$rules  = isset( $texts[ $target['rules'] ] ) ? $texts[ $target['rules'] ] : '';
	if ( '' === trim( $rules ) ) {
		return null;
	}

	$tokens = array();
	foreach ( ekwa_page_audit_css_tokens( $target, $texts ) as $name => $value ) {
		if ( '' === $value ) {
			continue;
		}
		$row = array( 'name' => $name, 'value' => $value );
		$hex = ekwa_page_audit_css_resolve_color( $value, array() );
		if ( '' !== $hex && strtolower( $hex ) !== strtolower( trim( $value ) ) ) {
			$row['hex'] = $hex;
		}
		$tokens[] = $row;
		if ( count( $tokens ) >= 120 ) {
			break;
		}
	}

	// Large sheets: keep only rules that set something a fix may change.
	$reduced = false;
	$sheet   = $rules;
	$limit   = (int) apply_filters( 'ekwa_page_audit_css_context_bytes', 90000 );
	if ( strlen( $sheet ) > $limit ) {
		ekwa_page_audit_css_require();
		$props   = ekwa_page_audit_css_properties();
		$kept    = array();
		$seen    = array();
		ekwa_css_walk( $sheet, static function ( $rule ) use ( $sheet, $props, &$kept, &$seen ) {
			if ( null === $rule['body'] || isset( $seen[ $rule['start'] ] ) ) {
				return;
			}
			$seen[ $rule['start'] ] = true;
			foreach ( ekwa_page_audit_css_decls( $sheet, $rule['prelude_end'] + 1, $rule['end'] - 1 ) as $decl ) {
				if ( in_array( $decl['prop'], $props, true ) || 0 === strpos( $decl['prop'], '--' ) ) {
					$text = substr( $sheet, $rule['start'], $rule['end'] - $rule['start'] );
					foreach ( array_reverse( $rule['chain'] ) as $prelude ) {
						$text = $prelude . '{' . $text . '}';
					}
					$kept[] = $text;
					return;
				}
			}
		} );
		$sheet   = implode( "\n", $kept );
		$reduced = true;
		if ( strlen( $sheet ) > $limit ) {
			$sheet = substr( $sheet, 0, $limit );
		}
	}

	return array(
		'model'      => $target['model'],
		'label'      => $target['label'],
		'stylesheet' => $sheet,
		'tokens'     => $tokens,
		'reduced'    => $reduced,
	);
}

/**
 * Check a model-proposed css fix against the CSS as it is now — and against
 * the real page, so a change that would be overridden is strengthened before
 * it is offered, or turned into a manual step that names the rule in the way.
 *
 * Targets ("css.in" from the model):
 *   none               the site stylesheet (Design Setup);
 *   {"block": path}    the Section CSS (scopedCss) of a block in the page body;
 *   {"part": slug, "path": path}  the Section CSS of a block in a template part.
 *
 * @param array $fix Raw fix from the model.
 * @param array $ctx {url, blocks: path=>name, blocks_css: path=>css, parts: parts ctx}
 * @return array {ok:true, css, colors, notes, checks} or {ok:false, reason} or {ok:false, skip:true}
 */
function ekwa_page_audit_css_validate( $fix, $ctx = array() ) {
	$raw = isset( $fix['css'] ) && is_array( $fix['css'] ) ? $fix['css'] : array();
	if ( empty( $raw['edits'] ) || ! is_array( $raw['edits'] ) ) {
		return array( 'ok' => false, 'reason' => __( 'The suggested stylesheet change was incomplete.', 'ekwa' ) );
	}

	$site_target = ekwa_page_audit_css_target();
	$site_texts  = ekwa_page_audit_css_texts( $site_target );
	$tokens      = ekwa_page_audit_css_tokens( $site_target, $site_texts );
	$props       = ekwa_page_audit_css_properties();

	// Where the edits go.
	$in = isset( $raw['in'] ) && is_array( $raw['in'] ) ? $raw['in'] : array();
	if ( ! empty( $in['part'] ) && isset( $in['path'] ) ) {
		$slug = sanitize_title( (string) $in['part'] );
		$path = (string) $in['path'];
		if ( ! isset( $ctx['parts']['css'][ $slug ][ $path ] ) ) {
			return array( 'ok' => false, 'reason' => __( 'That block’s Section CSS was not found in the template part.', 'ekwa' ) );
		}
		if ( empty( $ctx['parts']['editable'][ $slug ] ) ) {
			return array( 'ok' => false, 'reason' => __( 'This template part is still the theme’s own file, so it is changed in Appearance → Editor rather than from here.', 'ekwa' ) );
		}
		$place  = array( 'type' => 'part', 'part' => $slug, 'path' => $path, 'block' => (string) $ctx['parts']['index'][ $slug ][ $path ] );
		$target = array( 'model' => 'scoped', 'rules' => 'scoped', 'vars' => 'scoped', 'label' => __( 'Section CSS in a template part', 'ekwa' ) );
		$texts  = array( 'scoped' => (string) $ctx['parts']['css'][ $slug ][ $path ] );
	} elseif ( isset( $in['block'] ) ) {
		$path = (string) $in['block'];
		if ( ! isset( $ctx['blocks_css'][ $path ] ) ) {
			return array( 'ok' => false, 'reason' => __( 'That block’s Section CSS was not found on the page.', 'ekwa' ) );
		}
		$place  = array( 'type' => 'block', 'path' => $path, 'block' => isset( $ctx['blocks'][ $path ] ) ? (string) $ctx['blocks'][ $path ] : '' );
		$target = array( 'model' => 'scoped', 'rules' => 'scoped', 'vars' => 'scoped', 'label' => __( 'Section CSS of a block on this page', 'ekwa' ) );
		$texts  = array( 'scoped' => (string) $ctx['blocks_css'][ $path ] );
	} else {
		if ( '' === trim( $site_texts[ $site_target['rules'] ] ) ) {
			return array( 'ok' => false, 'reason' => __( 'This site has no stylesheet in Ekwa Settings → Design Setup to change.', 'ekwa' ) );
		}
		$place  = array( 'type' => 'site' );
		$target = $site_target;
		$texts  = $site_texts;
	}
	$scoped = 'site' !== $place['type'];

	// New variables. Only the site stylesheet gets new tokens; in Section CSS
	// the value is written directly instead.
	$vars   = array();
	$inline = array();
	foreach ( array_slice( isset( $raw['vars'] ) && is_array( $raw['vars'] ) ? $raw['vars'] : array(), 0, 3 ) as $var ) {
		if ( ! is_array( $var ) ) {
			continue;
		}
		$name  = '--' . ltrim( strtolower( trim( isset( $var['name'] ) ? (string) $var['name'] : '' ) ), '-' );
		$value = isset( $var['value'] ) ? trim( (string) $var['value'] ) : '';
		if ( ! preg_match( '/^--[a-z][a-z0-9-]{1,40}$/', $name ) || '' === ekwa_page_audit_css_resolve_color( $value, array() ) || ! ekwa_page_audit_css_safe_value( $value ) ) {
			return array( 'ok' => false, 'reason' => __( 'The new colour variable it suggested is not a valid colour token.', 'ekwa' ) );
		}
		if ( isset( $tokens[ $name ] ) ) {
			if ( ekwa_page_audit_css_norm_value( $tokens[ $name ] ) !== ekwa_page_audit_css_norm_value( $value ) ) {
				return array( 'ok' => false, 'reason' => sprintf( /* translators: %s: variable name */ __( 'It wanted to redefine %s, an existing colour token — that would change the whole site.', 'ekwa' ), $name ) );
			}
			continue;
		}
		$hex = ekwa_page_audit_hex( $value );
		if ( $scoped ) {
			$inline[ $name ] = $hex ? $hex : $value;
		} else {
			$vars[] = array( 'name' => $name, 'value' => $hex ? $hex : $value );
		}
	}
	$all_tokens = $tokens;
	foreach ( $vars as $var ) {
		$all_tokens[ $var['name'] ] = $var['value'];
	}

	$edits   = array();
	$already = array();
	$notes   = array();
	$text    = $texts[ $target['rules'] ];
	foreach ( array_slice( $raw['edits'], 0, 6 ) as $edit ) {
		if ( ! is_array( $edit ) ) {
			continue;
		}
		$selector = isset( $edit['selector'] ) ? trim( (string) $edit['selector'] ) : '';
		$media    = isset( $edit['media'] ) ? trim( (string) $edit['media'] ) : '';
		$property = isset( $edit['property'] ) ? strtolower( trim( (string) $edit['property'] ) ) : '';
		$from     = isset( $edit['from'] ) ? trim( (string) $edit['from'] ) : '';
		$to       = isset( $edit['to'] ) ? trim( (string) $edit['to'] ) : '';
		foreach ( $inline as $name => $value ) {
			$to = preg_replace( '/var\(\s*' . preg_quote( $name, '/' ) . '\s*\)/i', $value, $to );
		}

		if ( '' === $selector || strlen( $selector ) > 300 || preg_match( '#[{};<@]|/\*|\*/#', $selector ) ) {
			return array( 'ok' => false, 'reason' => __( 'The suggested CSS selector is not usable.', 'ekwa' ) );
		}
		if ( '' !== $media && ( strlen( $media ) > 200 || ! preg_match( '/^@(media|supports|container)\b[^{};]*$/i', $media ) ) ) {
			return array( 'ok' => false, 'reason' => __( 'The suggested media query is not usable.', 'ekwa' ) );
		}
		if ( ! in_array( $property, $props, true ) ) {
			return array( 'ok' => false, 'reason' => sprintf( /* translators: %s: CSS property */ __( 'Changing “%s” from the audit is not allowed.', 'ekwa' ), $property ) );
		}
		if ( ! ekwa_page_audit_css_safe_value( $to ) ) {
			return array( 'ok' => false, 'reason' => __( 'The suggested CSS value is not safe to write automatically.', 'ekwa' ) );
		}
		if ( preg_match_all( '/var\(\s*(--[a-z0-9_-]+)/i', $to, $m ) ) {
			foreach ( $m[1] as $ref ) {
				if ( ! isset( $all_tokens[ strtolower( $ref ) ] ) && ! isset( $all_tokens[ $ref ] ) ) {
					return array( 'ok' => false, 'reason' => sprintf( /* translators: %s: variable */ __( 'It uses %s, which the site does not define.', 'ekwa' ), $ref ) );
				}
			}
		}

		$clean = array( 'selector' => $selector, 'media' => $media, 'property' => $property, 'from' => $from, 'to' => $to );
		$loc   = ekwa_page_audit_css_locate( $text, $clean );
		if ( is_wp_error( $loc ) ) {
			return array( 'ok' => false, 'reason' => $loc->get_error_message() );
		}
		if ( 'replace' === $loc['mode'] ) {
			$clean['from'] = $loc['actual_from'];
			if ( 'background' === $property && preg_match( '/url\s*\(|gradient\s*\(/i', $loc['actual_from'] ) ) {
				return array( 'ok' => false, 'reason' => __( 'That background also holds an image or gradient, so replacing it automatically could remove it.', 'ekwa' ) );
			}
			if ( ekwa_page_audit_css_norm_value( $loc['actual_from'] ) === ekwa_page_audit_css_norm_value( $to ) ) {
				// The stylesheet already says this — but that does not mean the
				// page shows it (a stronger rule elsewhere may win). Checked below.
				$already[] = $clean;
				continue;
			}
		} else {
			$clean['from'] = '';
		}
		$clean['mode'] = $loc['mode'];
		if ( ! empty( $loc['group'] ) ) {
			$clean['group'] = $loc['group'];
			$notes[]        = sprintf( /* translators: %s: CSS selector list */ __( 'The rule it changes is shared: “%s”. Every selector in it gets the new value.', 'ekwa' ), ekwa_page_audit_text( $loc['group'], 200 ) );
		}
		$role = ekwa_page_audit_css_role( $property );
		if ( '' !== $role ) {
			$clean['before_hex'] = '' !== $clean['from'] ? ekwa_page_audit_css_resolve_color( $clean['from'], $all_tokens ) : '';
			$clean['after_hex']  = ekwa_page_audit_css_resolve_color( $to, $all_tokens );
		}
		$edits[] = $clean;
	}
	// The real page, for the cascade checks below.
	$snap = null;
	if ( ! empty( $ctx['url'] ) && apply_filters( 'ekwa_page_audit_cascade_check', true ) ) {
		$snap = ekwa_page_audit_page_snapshot( $ctx['url'] );
	}

	// Values the CSS already has: in effect only if they win on the page. One
	// that is overridden (a previous fix that never showed, say) becomes a new,
	// stronger rule instead of being skipped as "already done".
	if ( $already && $snap && ! is_wp_error( $snap ) ) {
		$source = $scoped ? ekwa_page_audit_scoped_style_id( $texts['scoped'] ) : 'ekwa-global-css';
		foreach ( $already as $clean ) {
			$res = ekwa_page_audit_cascade_check( $snap, array(), $source, $clean['selector'], $clean['property'], $clean['to'] );
			if ( 'fails' !== $res['status'] ) {
				continue;
			}
			if ( ! empty( $res['winner']['important'] ) ) {
				$stronger = $clean['selector'];
				$clean['to'] .= ' !important';
			} else {
				$stronger = ekwa_page_audit_cascade_strengthen( $snap, $clean['selector'], $res['winner']['spec'] );
			}
			if ( '' === $stronger ) {
				return array( 'ok' => false, 'reason' => sprintf( /* translators: %s: why it is overridden */ __( 'The stylesheet already has this, but it does not show on the page. %s', 'ekwa' ), $res['detail'] ) );
			}
			$new = array_merge( $clean, array(
				'selector'          => $stronger,
				'from'              => '',
				'mode'              => 'new',
				'strengthened_from' => $clean['selector'],
				'beats'             => $res['winner']['selector'] . ' (' . $res['winner']['label'] . ')',
			) );
			unset( $new['group'] );
			$loc = ekwa_page_audit_css_locate( $text, $new );
			if ( ! is_wp_error( $loc ) ) {
				$new['mode'] = $loc['mode'];
				$new['from'] = 'replace' === $loc['mode'] ? $loc['actual_from'] : '';
			}
			$edits[] = $new;
			$notes[] = sprintf( /* translators: 1: selector, 2: why */ __( 'The stylesheet already had “%1$s” but it did not show (%2$s). This adds a stronger rule that does.', 'ekwa' ), $clean['selector'], $res['detail'] );
		}
	}

	if ( ! $edits ) {
		return array( 'ok' => false, 'skip' => true, 'reason' => '' );
	}
	if ( $inline ) {
		$notes[] = __( 'New colour tokens can only be added to the site stylesheet, so this Section CSS change writes the colour value directly.', 'ekwa' );
	}

	$style = $scoped ? 'prop' : 'comment';
	$trial = ekwa_page_audit_css_apply( $target, $texts, array( 'edits' => $edits, 'vars' => $vars, 'note' => '', 'marker_style' => $style ), 'ekwa-fix:trial' );
	if ( is_wp_error( $trial ) ) {
		return array( 'ok' => false, 'reason' => $trial->get_error_message() );
	}

	// Will it actually show? Checked against the real page.
	$checks = array();
	if ( null !== $snap ) {
		if ( is_wp_error( $snap ) ) {
			$checks[] = array( 'status' => 'unchecked', 'detail' => $snap->get_error_message() );
		} else {
			$result = ekwa_page_audit_css_check_edits( $snap, $place, $target, $texts, $edits, $vars, $style );
			$edits  = $result['edits'];
			$checks = $result['checks'];
			if ( '' !== $result['failure'] ) {
				return array( 'ok' => false, 'reason' => $result['failure'] );
			}
		}
	}

	// Colour options that are guaranteed to pass against the backgrounds
	// Lighthouse measured (see inc/ekwa-page-audit-color.php). Only for a fix
	// with exactly one text-colour change — the common case, and one choice
	// the person can make at a glance.
	$color_options = array();
	$color_edit    = null;
	$color_targets = null;
	$color_idx     = array();
	foreach ( $edits as $i => $edit ) {
		if ( 'color' === $edit['property'] ) {
			$color_idx[] = $i;
		}
	}
	$items = ! empty( $ctx['report'] ) && function_exists( 'ekwa_page_audit_contrast_items' ) ? ekwa_page_audit_contrast_items( $ctx['report'] ) : array();
	if ( 1 === count( $color_idx ) && $items ) {
		$color_edit    = $color_idx[0];
		$edit          = $edits[ $color_edit ];
		$color_targets = ekwa_page_audit_color_targets( $edit, $items, ( $snap && ! is_wp_error( $snap ) ) ? $snap : null );
		if ( $color_targets['bgs'] ) {
			$base = '';
			if ( preg_match( '/^var\(\s*(--[a-z0-9_-]+)\s*\)$/i', trim( (string) $edit['from'] ), $bm ) ) {
				$base = strtolower( $bm[1] );
			}
			$color_options = ekwa_page_audit_color_options( $edit, $color_targets, $all_tokens, $scoped, $vars, $base );
			if ( ! $color_options ) {
				return array( 'ok' => false, 'reason' => sprintf(
					/* translators: %s: list of background colours */
					__( 'No single colour reaches the required contrast against every background this text sits on (%s). The background itself needs to change — or split the rule so each background gets its own colour.', 'ekwa' ),
					implode( ', ', $color_targets['bgs'] )
				) );
			}
			$worst = ekwa_pa_worst_ratio( $edit['to'], $color_targets['bgs'], $all_tokens );
			if ( $worst > 0 ) {
				if ( $worst < $color_targets['required'] ) {
					$notes[] = sprintf(
						/* translators: 1: colour, 2: ratio, 3: background, 4: required ratio */
						__( 'The AI’s colour %1$s would reach only %2$s:1 against %3$s (needs %4$s:1), so it is not offered. Every option below passes.', 'ekwa' ),
						$edit['to'],
						number_format( $worst, 2 ),
						implode( ', ', $color_targets['bgs'] ),
						$color_targets['required']
					);
				}
			}
		}
	}

	// Colours for the before/after samples: computed here, not taken on trust.
	$colors = array();
	$given  = isset( $fix['colors'] ) && is_array( $fix['colors'] ) ? $fix['colors'] : array();
	foreach ( $edits as $edit ) {
		if ( empty( $edit['after_hex'] ) ) {
			continue;
		}
		$role    = ekwa_page_audit_css_role( $edit['property'] );
		$against = '';
		$before  = isset( $edit['before_hex'] ) ? $edit['before_hex'] : '';
		foreach ( $given as $c ) {
			if ( is_array( $c ) && ( ! isset( $c['role'] ) || $c['role'] === $role ) ) {
				$against = ekwa_page_audit_hex( isset( $c['against'] ) ? $c['against'] : '' );
				if ( '' === $before ) {
					$before = ekwa_page_audit_hex( isset( $c['before'] ) ? $c['before'] : '' );
				}
				break;
			}
		}
		if ( '' === $before ) {
			continue;
		}
		$colors[] = array(
			'role'    => '' === $role ? 'text' : $role,
			'before'  => $before,
			'after'   => $edit['after_hex'],
			'against' => $against,
		);
	}

	$note = isset( $fix['note'] ) ? ekwa_page_audit_css_comment_text( $fix['note'], 80 ) : '';
	if ( '' === $note && isset( $fix['title'] ) ) {
		$note = ekwa_page_audit_css_comment_text( $fix['title'], 80 );
	}

	// With options, the samples use the real background Lighthouse measured.
	if ( $color_options && $color_targets ) {
		$colors = array(
			array(
				'role'    => 'text',
				'before'  => ! empty( $edits[ $color_edit ]['before_hex'] ) ? $edits[ $color_edit ]['before_hex'] : $color_targets['fgs'][0],
				'after'   => $color_options[0]['hex'],
				'against' => $color_targets['bgs'][0],
			),
		);
	}

	$out = array(
		'ok'     => true,
		'css'    => array(
			'edits'        => $edits,
			'vars'         => $vars,
			'note'         => $note,
			'model'        => $scoped ? $site_target['model'] : $target['model'],
			'target'       => $target['label'],
			'in'           => $place,
			'marker_style' => $style,
		),
		'colors' => $colors,
		'notes'  => $notes,
		'checks' => $checks,
	);
	if ( $color_options ) {
		$out['colorOptions'] = $color_options;
		$out['colorEdit']    = $color_edit;
		$out['colorTargets'] = array(
			'bgs'      => $color_targets['bgs'],
			'required' => $color_targets['required'],
			'items'    => $color_targets['items'],
		);
	}
	return $out;
}

/**
 * Cascade-check each edit on the page; strengthen a losing one.
 *
 * A declaration that loses to a rule with !important gets !important too; one
 * that loses on specificity becomes a new rule with ancestor classes from the
 * page prefixed until it wins. Anything still losing is reported.
 *
 * @return array {edits, checks, failure}
 */
function ekwa_page_audit_css_check_edits( $snap, $place, $target, $texts, $edits, $vars, $style ) {
	$checks = array();
	$source = 'site' === $place['type'] ? 'ekwa-global-css' : ekwa_page_audit_scoped_style_id( $texts['scoped'] );

	foreach ( array_keys( $edits ) as $i ) {
		$res = null;
		for ( $attempt = 0; $attempt < 4; $attempt++ ) {
			$trial = ekwa_page_audit_css_apply( $target, $texts, array( 'edits' => $edits, 'vars' => $vars, 'note' => '', 'marker_style' => $style ), 'ekwa-fix:trial' );
			if ( is_wp_error( $trial ) ) {
				return array( 'edits' => $edits, 'checks' => $checks, 'failure' => $trial->get_error_message() );
			}
			$edit = $edits[ $i ];
			$res  = ekwa_page_audit_cascade_check( $snap, array( $source => $trial['texts'][ $target['rules'] ] ), $source, $edit['selector'], $edit['property'], preg_replace( '/\s*!\s*important\s*$/i', '', $edit['to'] ) );
			if ( 'fails' !== $res['status'] ) {
				break;
			}
			$winner = $res['winner'];
			if ( ! empty( $winner['important'] ) && ! preg_match( '/!\s*important\s*$/i', $edit['to'] ) ) {
				$edits[ $i ]['to']        = $edit['to'] . ' !important';
				$edits[ $i ]['important'] = 'added';
				continue;
			}
			$stronger = ekwa_page_audit_cascade_strengthen( $snap, $edit['selector'], $winner['spec'] );
			if ( '' === $stronger || ekwa_page_audit_css_norm_selector( $stronger ) === ekwa_page_audit_css_norm_selector( $edit['selector'] ) ) {
				break;
			}
			$edits[ $i ] = array_merge( $edit, array(
				'selector'          => $stronger,
				'from'              => '',
				'mode'              => 'new',
				'strengthened_from' => isset( $edit['strengthened_from'] ) ? $edit['strengthened_from'] : $edit['selector'],
				'beats'             => $winner['selector'] . ' (' . $winner['label'] . ')',
			) );
			unset( $edits[ $i ]['group'] );
			// Re-locate: the stronger selector may already have a rule.
			$loc = ekwa_page_audit_css_locate( $texts[ $target['rules'] ], $edits[ $i ] );
			if ( ! is_wp_error( $loc ) ) {
				$edits[ $i ]['mode'] = $loc['mode'];
				$edits[ $i ]['from'] = 'replace' === $loc['mode'] ? $loc['actual_from'] : '';
			}
		}
		$res['selector'] = $edits[ $i ]['selector'];
		if ( isset( $edits[ $i ]['strengthened_from'] ) ) {
			$res['strengthened_from'] = $edits[ $i ]['strengthened_from'];
			$res['beats']             = $edits[ $i ]['beats'];
		}
		$checks[] = $res;
		if ( 'fails' === $res['status'] ) {
			return array(
				'edits'   => $edits,
				'checks'  => $checks,
				/* translators: %s: why it is overridden */
				'failure' => sprintf( __( 'This change would not show on the page. %s Fix or remove that rule (Design Setup or the block’s Section CSS).', 'ekwa' ), $res['detail'] ),
			);
		}
		if ( 'nomatch' === $res['status'] ) {
			// Lighthouse also sees elements a script adds after load, which the
			// page's HTML does not contain — so this cannot be checked in
			// advance, but it is not proof the fix is wrong. Keep it, say so; the
			// live check and the PageSpeed re-test still judge it afterwards.
			$checks[ count( $checks ) - 1 ]['detail'] = __( 'The selector matches nothing in the page’s HTML (the element may be added by a script), so it could not be checked in advance.', 'ekwa' );
		}
	}
	return array( 'edits' => $edits, 'checks' => $checks, 'failure' => '' );
}

/**
 * After a change: fetch the live page (caches bypassed) and check each edit
 * really takes effect.
 *
 * @param string $url
 * @param array  $css    The applied css spec (edits, in).
 * @param string $scoped The Section CSS text after the change (Section CSS targets).
 * @return array {status: ok|fails|unchecked, detail, checks}
 */
function ekwa_page_audit_css_verify( $url, $css, $scoped = '' ) {
	$snap = ekwa_page_audit_page_snapshot( $url, true );
	if ( is_wp_error( $snap ) ) {
		return array( 'status' => 'unchecked', 'detail' => $snap->get_error_message(), 'checks' => array(), 'time' => time() );
	}
	$place  = isset( $css['in'] ) ? $css['in'] : array( 'type' => 'site' );
	$source = 'site' === ( isset( $place['type'] ) ? $place['type'] : 'site' ) ? 'ekwa-global-css' : ekwa_page_audit_scoped_style_id( $scoped );
	$checks = array();
	$status = 'ok';
	foreach ( isset( $css['edits'] ) ? (array) $css['edits'] : array() as $edit ) {
		$res      = ekwa_page_audit_cascade_check( $snap, array(), $source, $edit['selector'], $edit['property'], preg_replace( '/\s*!\s*important\s*$/i', '', $edit['to'] ) );
		$checks[] = $res;
		if ( 'fails' === $res['status'] || 'nomatch' === $res['status'] ) {
			$status = 'fails';
		} elseif ( 'ok' !== $res['status'] && 'ok' === $status ) {
			$status = 'unchecked';
		}
	}
	$first = $checks ? $checks[0] : array( 'detail' => '' );
	foreach ( $checks as $c ) {
		if ( 'ok' !== $c['status'] ) {
			$first = $c;
			break;
		}
	}
	return array( 'status' => $status, 'detail' => isset( $first['detail'] ) ? $first['detail'] : '', 'checks' => $checks, 'time' => time() );
}

// ═══════════════════════════════════════════════════════════════════════════════
// APPLY / UNDO ON THE SITE
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Apply a stored css fix to the live stylesheet and log it.
 *
 * @param int    $post_id
 * @param array  $fix     Validated fix from the stored analysis.
 * @return array|WP_Error Public change entry.
 */
function ekwa_page_audit_css_apply_fix( $post_id, $fix ) {
	if ( isset( $fix['css']['in']['type'] ) && 'part' === $fix['css']['in']['type'] ) {
		return ekwa_page_audit_css_apply_part_fix( $post_id, $fix );
	}
	if ( isset( $fix['css']['in']['type'] ) && 'block' === $fix['css']['in']['type'] ) {
		return new WP_Error( 'ekwa_page_audit_css_block', __( 'This fix changes a block on the page, so it is applied in the editor.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$target = ekwa_page_audit_css_target();
	if ( ! isset( $fix['css']['model'] ) || $fix['css']['model'] !== $target['model'] ) {
		return new WP_Error( 'ekwa_page_audit_css_model', __( 'The site’s stylesheet setup changed since this analysis. Analyze again.', 'ekwa' ), array( 'status' => 409 ) );
	}

	$lock = function_exists( 'ekwa_ai_lock' ) ? ekwa_ai_lock( 'page-audit-css', 5 ) : 'none';
	if ( '' === $lock ) {
		return new WP_Error( 'ekwa_page_audit_busy', __( 'Busy — try again in a moment.', 'ekwa' ), array( 'status' => 503 ) );
	}
	try {
		foreach ( array_unique( array( $target['rules'], $target['vars'] ) ) as $option ) {
			wp_cache_delete( $option, 'options' );
		}
		$texts  = ekwa_page_audit_css_texts( $target );
		$id     = ekwa_page_audit_id();
		$result = ekwa_page_audit_css_apply( $target, $texts, $fix['css'], 'ekwa-fix:' . $id );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}

		$entry = array(
			'id'       => $id,
			'type'     => 'css',
			'time'     => time(),
			'user'     => ekwa_page_audit_user_name(),
			'post_id'  => (int) $post_id,
			'fix_id'   => isset( $fix['id'] ) ? (string) $fix['id'] : '',
			'title'    => isset( $fix['title'] ) ? (string) $fix['title'] : '',
			'category' => isset( $fix['category'] ) ? (string) $fix['category'] : 'accessibility',
			'audit'    => isset( $fix['audit'] ) ? (string) $fix['audit'] : '',
			'model'    => $target['model'],
			'css'      => $fix['css'],
			'patches'  => $result['patches'],
			'undone'   => 0,
		);

		// Record first: a change nobody can undo must not be made.
		$logged = ekwa_page_audit_mutate_changes( static function ( $log ) use ( $entry ) {
			$log[] = $entry;
			return $log;
		} );
		if ( ! $logged ) {
			return new WP_Error( 'ekwa_page_audit_busy', __( 'Busy — try again in a moment.', 'ekwa' ), array( 'status' => 503 ) );
		}
		foreach ( $result['texts'] as $option => $text ) {
			if ( $text !== $texts[ $option ] ) {
				update_option( $option, $text, false );
			}
		}
	} finally {
		if ( 'none' !== $lock && function_exists( 'ekwa_ai_unlock' ) ) {
			ekwa_ai_unlock( $lock );
		}
	}

	ekwa_page_audit_purge_caches();
	ekwa_page_audit_css_record_verify( $entry, $post_id, $fix['css'], '' );
	return ekwa_page_audit_public_change( $entry );
}

/**
 * Check a just-applied change on the live page and keep the result on its log
 * entry, so the sidebar can say whether it really took effect.
 *
 * @param array  $entry   Change entry (updated in place).
 * @param int    $post_id
 * @param array  $css
 * @param string $scoped  Section CSS after the change (Section CSS targets).
 */
function ekwa_page_audit_css_record_verify( &$entry, $post_id, $css, $scoped ) {
	$post = get_post( $post_id );
	if ( ! $post || ! apply_filters( 'ekwa_page_audit_cascade_check', true ) ) {
		return;
	}
	$url = ekwa_page_audit_test_url( $post );
	if ( is_wp_error( $url ) ) {
		return;
	}
	$verify          = ekwa_page_audit_css_verify( $url, $css, $scoped );
	$entry['verify'] = $verify;
	$id              = $entry['id'];
	ekwa_page_audit_mutate_changes( static function ( $log ) use ( $id, $verify ) {
		foreach ( $log as $i => $e ) {
			if ( isset( $e['id'] ) && $e['id'] === $id ) {
				$log[ $i ]['verify'] = $verify;
			}
		}
		return $log;
	} );
}

/**
 * Apply a css fix whose target is the Section CSS of a block inside a
 * template part: compute the new scopedCss with the CSS engine (comment-free
 * markers), then change that one attribute through the template-part path
 * (surgical delimiter rewrite, logged, undoable).
 *
 * @param int   $post_id
 * @param array $fix
 * @return array|WP_Error Public change entry.
 */
function ekwa_page_audit_css_apply_part_fix( $post_id, $fix ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error( 'rest_forbidden', __( 'You cannot change template parts.', 'ekwa' ), array( 'status' => 403 ) );
	}
	$place     = $fix['css']['in'];
	$part_post = ekwa_page_audit_parts_post( $place['part'] );
	if ( is_wp_error( $part_post ) ) {
		return $part_post;
	}
	$block = null;
	foreach ( ekwa_page_audit_block_scan( (string) $part_post->post_content ) as $b ) {
		if ( $b['path'] === $place['path'] ) {
			$block = $b;
		}
	}
	if ( ! $block || $block['name'] !== $place['block'] ) {
		return new WP_Error( 'ekwa_page_audit_part_moved', __( 'That block is no longer at the same place in the template part. Analyze again.', 'ekwa' ), array( 'status' => 409 ) );
	}
	$current = isset( $block['attrs']['scopedCss'] ) ? (string) $block['attrs']['scopedCss'] : '';
	$result  = ekwa_page_audit_css_apply(
		array( 'model' => 'scoped', 'rules' => 'scoped', 'vars' => 'scoped' ),
		array( 'scoped' => $current ),
		array_merge( $fix['css'], array( 'marker_style' => 'prop', 'vars' => array() ) ),
		'ekwa-fix-' . ekwa_page_audit_id()
	);
	if ( is_wp_error( $result ) ) {
		$result->add_data( array( 'status' => 409 ) );
		return $result;
	}
	$new   = $result['texts']['scoped'];
	$entry = ekwa_page_audit_parts_apply_fix( $post_id, array_merge( $fix, array(
		'part'  => $place['part'],
		'path'  => $place['path'],
		'block' => $place['block'],
		'attrs' => array( 'scopedCss' => $new ),
	) ) );
	if ( is_wp_error( $entry ) ) {
		return $entry;
	}
	// Keep the readable description of what changed on the entry too.
	$id = $entry['id'];
	ekwa_page_audit_mutate_changes( static function ( $log ) use ( $id, $fix ) {
		foreach ( $log as $i => $e ) {
			if ( isset( $e['id'] ) && $e['id'] === $id ) {
				$log[ $i ]['css'] = $fix['css'];
			}
		}
		return $log;
	} );
	$raw = null;
	foreach ( ekwa_page_audit_changes() as $e ) {
		if ( isset( $e['id'] ) && $e['id'] === $id ) {
			$raw = $e;
		}
	}
	if ( $raw ) {
		ekwa_page_audit_css_record_verify( $raw, $post_id, $fix['css'], $new );
		return ekwa_page_audit_public_change( $raw );
	}
	return $entry;
}

/**
 * Undo a logged css change.
 *
 * @param array $entry
 * @return true|WP_Error
 */
function ekwa_page_audit_css_undo_entry( $entry ) {
	$target = ekwa_page_audit_css_target();
	$texts  = array();
	foreach ( (array) $entry['patches'] as $patch ) {
		$option = isset( $patch['option'] ) ? (string) $patch['option'] : '';
		if ( ! in_array( $option, array( 'ekwa_mockup_css', 'ekwa_global_css', 'ekwa_tokens_colors' ), true ) ) {
			return new WP_Error( 'ekwa_page_audit_css_option', __( 'Invalid change record.', 'ekwa' ), array( 'status' => 400 ) );
		}
		if ( ! isset( $texts[ $option ] ) ) {
			wp_cache_delete( $option, 'options' );
			$value            = get_option( $option, '' );
			$texts[ $option ] = is_string( $value ) ? $value : '';
		}
	}
	$reverted = ekwa_page_audit_css_revert( $texts, $entry['patches'] );
	if ( is_wp_error( $reverted ) ) {
		return $reverted;
	}
	foreach ( $reverted as $option => $text ) {
		if ( $text !== $texts[ $option ] ) {
			update_option( $option, $text, false );
		}
	}
	unset( $target );
	return true;
}

/**
 * Would undoing this css change work right now?
 *
 * @param array $entry
 * @return bool
 */
function ekwa_page_audit_css_entry_applied( $entry ) {
	$texts = array();
	foreach ( (array) ( isset( $entry['patches'] ) ? $entry['patches'] : array() ) as $patch ) {
		$option = isset( $patch['option'] ) ? (string) $patch['option'] : '';
		if ( '' !== $option && ! isset( $texts[ $option ] ) ) {
			$value            = get_option( $option, '' );
			$texts[ $option ] = is_string( $value ) ? $value : '';
		}
	}
	return $texts && ! is_wp_error( ekwa_page_audit_css_revert( $texts, $entry['patches'] ) );
}

/**
 * One-line description of a css change, for lists.
 *
 * @param array $css
 * @return string
 */
function ekwa_page_audit_css_describe( $css ) {
	$parts = array();
	foreach ( isset( $css['edits'] ) ? (array) $css['edits'] : array() as $edit ) {
		$parts[] = $edit['selector'] . ' { ' . $edit['property'] . ': ' . ( '' !== $edit['from'] ? $edit['from'] . ' → ' : '' ) . $edit['to'] . ' }';
	}
	foreach ( isset( $css['vars'] ) ? (array) $css['vars'] : array() as $var ) {
		$parts[] = sprintf( /* translators: 1: variable name, 2: value */ __( 'new %1$s: %2$s', 'ekwa' ), $var['name'], $var['value'] );
	}
	return implode( '; ', $parts );
}

// ═══════════════════════════════════════════════════════════════════════════════
// PREVIEW VARIANTS (for the side-by-side compare)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * The stylesheet texts "without" and "with" a css fix, without writing
 * anything: an applied fix is reverted in memory for "without", a fix not yet
 * applied is applied in memory for "with".
 *
 * @param array      $fix   Fix from the stored analysis (kind css).
 * @param array|null $entry Active change entry for it, if applied.
 * @return array|WP_Error {before: texts, after: texts, applied: bool}
 */
function ekwa_page_audit_css_variants( $fix, $entry = null ) {
	$target = ekwa_page_audit_css_target();
	$texts  = ekwa_page_audit_css_texts( $target );
	if ( $entry && empty( $entry['undone'] ) ) {
		foreach ( (array) $entry['patches'] as $patch ) {
			$option = (string) $patch['option'];
			if ( ! isset( $texts[ $option ] ) ) {
				$value            = get_option( $option, '' );
				$texts[ $option ] = is_string( $value ) ? $value : '';
			}
		}
		$before = ekwa_page_audit_css_revert( $texts, $entry['patches'] );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		return array( 'before' => $before, 'after' => $texts, 'applied' => true );
	}
	if ( ! isset( $fix['css'] ) ) {
		return new WP_Error( 'ekwa_page_audit_css_fix', __( 'That is not a stylesheet fix.', 'ekwa' ) );
	}
	if ( isset( $fix['css']['in']['type'] ) && 'site' !== $fix['css']['in']['type'] ) {
		return new WP_Error( 'ekwa_page_audit_css_fix', __( 'Compare is available for site stylesheet fixes.', 'ekwa' ) );
	}
	$after = ekwa_page_audit_css_apply( $target, $texts, $fix['css'], 'ekwa-fix:preview' );
	if ( is_wp_error( $after ) ) {
		return $after;
	}
	return array( 'before' => $texts, 'after' => $after['texts'], 'applied' => false );
}
