<?php
/**
 * Design Tokens — the site's stylesheet, background-image variables, and the
 * design tokens the AI tools reuse.
 *
 * THE MODEL: the mockup stylesheet IS the site's global stylesheet. You paste
 * it once and it is printed in <head> as-is. Its `:root` block defines every
 * design token, including responsive `@media` overrides, because nothing is
 * split out of it. Sections still carry their own Scoped CSS; the Fonts module
 * still owns @font-face and font variables (self-hosting + conditional mobile
 * loading), which is why those are stripped from the printed sheet.
 *
 * <head> print order, which is deliberate — see ekwa_tokens_print_mockup_css():
 *
 *   Background images   → :root{--bg-hero:url(…)} so section CSS (and the
 *                         ::before/::after pseudo-elements blocks can't own)
 *                         can reference them. Printed BEFORE the stylesheet,
 *                         which references these names without declaring them.
 *   Mockup stylesheet   → <style id="ekwa-global-css">
 *   Fonts tab           → @font-face + font variables, printed AFTER the
 *                         stylesheet so self-hosting wins
 *
 * LEGACY MODEL (pre-simplification, still fully supported): the stylesheet used
 * to be split three ways — variables extracted into a "CSS variables" field, a
 * shrinking "Global CSS" pool printed in <head>, and per-section CSS moved out
 * by the converter's AI extraction. Splitting proved lossy in ways that failed
 * silently (rules dropped from the pool, `@media` context flattened out of
 * variables, the field and the front end drifting apart), so it was retired.
 *
 * Sites that already have those options populated keep the old behaviour
 * EXACTLY — see ekwa_tokens_legacy_mode(). Nothing is migrated or rewritten;
 * the legacy fields simply move under a "Legacy" heading. Block `scopedCss`
 * renders in both models, forever.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The saved mockup stylesheet — the site's global CSS (see the file header).
 *
 * @return string
 */
function ekwa_tokens_mockup_css() {
	return (string) get_option( 'ekwa_mockup_css', '' );
}

/**
 * Is this site still on the legacy split-stylesheet model?
 *
 * True when either legacy option holds anything, which can only happen on a
 * site that used the old Design Setup. Those sites keep printing the Global CSS
 * pool and the extracted CSS variables exactly as before, and the mockup
 * stylesheet stays reference-only for them — otherwise the two would both print
 * and every rule the converter had moved into a section would be duplicated.
 *
 * @return bool
 */
function ekwa_tokens_legacy_mode() {
	return '' !== trim( (string) get_option( 'ekwa_global_css', '' ) )
		|| '' !== trim( (string) get_option( 'ekwa_tokens_colors', '' ) );
}

/**
 * The CSS printed site-wide in <head>.
 *
 * @return string
 */
function ekwa_tokens_site_css() {
	if ( ekwa_tokens_legacy_mode() ) {
		return trim( ekwa_tokens_global_css() );
	}
	return ekwa_tokens_printable_mockup_css();
}

/**
 * The mockup stylesheet, ready to print: `@import` and `@font-face` removed.
 *
 * `@import` would add a blocking request for something the site doesn't serve,
 * and `@font-face` is the Fonts tab's job — it self-hosts the files and can
 * skip the download on mobile, neither of which works if the mockup declares
 * its own faces. Everything else, `:root` included, is printed untouched.
 *
 * @return string
 */
function ekwa_tokens_printable_mockup_css() {
	$css = trim( ekwa_tokens_mockup_css() );
	if ( '' === $css ) {
		return '';
	}
	$css = preg_replace( '/@import\b[^;]+;/i', '', $css );
	$css = preg_replace( '/@font-face\s*\{[^}]*\}/is', '', $css );
	$css = preg_replace( "/\n{3,}/", "\n\n", (string) $css );
	return trim( (string) $css );
}

/**
 * Color-variable lines, normalized to `--name: value;` one per line.
 *
 * @return string
 */
function ekwa_tokens_colors() {
	return (string) get_option( 'ekwa_tokens_colors', '' );
}

/**
 * Background-image variables.
 *
 * @return array<int,array{name:string,url:string}>
 */
function ekwa_tokens_bgimages() {
	$rows = get_option( 'ekwa_tokens_bgimages', array() );
	return is_array( $rows ) ? $rows : array();
}

/**
 * Breakpoint (px, min-width) at which conditionally-loaded custom fonts kick
 * in. Below it, font variables resolve to their web-safe fallback stacks so
 * the font files are never downloaded on mobile.
 *
 * @return int
 */
function ekwa_fonts_conditional_bp() {
	$bp = (int) get_option( 'ekwa_fonts_conditional_bp', 767 );
	return max( 480, min( 1920, $bp ) );
}

/**
 * Sanitize a CSS variable name fragment ("--brand-primary" or "brand_primary"
 * both become "brand-primary"; the leading dashes are re-added on output).
 *
 * @param string $raw
 * @return string
 */
function ekwa_tokens_sanitize_var_name( $raw ) {
	$name = strtolower( trim( (string) $raw ) );
	$name = ltrim( $name, '-' );
	$name = preg_replace( '/[^a-z0-9_-]/', '-', $name );
	return trim( $name, '-' );
}

/**
 * Sanitize the pasted color-variables text down to safe `--name: value;`
 * lines. Accepts a full stylesheet (`:root { … }` plus any other rules) as well
 * as bare declaration lines; drops anything that isn't a custom property with a
 * simple value.
 *
 * When the input contains CSS blocks, only the *inside* of the innermost
 * `{ … }` declaration blocks is scanned. Selectors live OUTSIDE the braces, so a
 * BEM class name carrying a pseudo-class or pseudo-element — `.btn--primary:hover`,
 * `.card__title--center::after` — can never be misread as a custom property. The
 * old flat regex scanned the whole stylesheet and happily turned those selectors
 * into junk tokens (`--primary: hover;`, `--center: :after;`). Restricting the
 * scan to block bodies also handles CSS nesting for free: an outer block still
 * contains braces, so it isn't captured by the brace-less `[^{}]*` body match.
 *
 * @param string $raw
 * @return string One declaration per line.
 */
function ekwa_tokens_sanitize_colors( $raw ) {
	return ekwa_tokens_serialize_var_groups( ekwa_tokens_parse_var_groups( $raw ) );
}

/**
 * Parse custom-property declarations into ordered groups, one per at-rule
 * context.
 *
 * Responsive tokens are the reason this isn't a flat list. A mockup routinely
 * steps a variable down across breakpoints:
 *
 *     :root { --container-width: 1700px; }
 *     @media (max-width: 1600px) { :root { --container-width: 1500px; } }
 *     @media (max-width: 1440px) { :root { --container-width: 1360px; } }
 *
 * Collecting those declarations without their `@media` context collapsed them
 * into one `:root` block where the LAST one silently won at every viewport —
 * so a 1700px container rendered at 1200px on desktop, and the mockup's whole
 * responsive scale was gone.
 *
 * Accepts three shapes: a full stylesheet, the field's own stored format
 * (bare lines plus `@media (…) { … }` groups), and plain declaration lines.
 *
 * @param string $raw
 * @return array<int,array{chain:string[],decls:array<string,string>}> Source order,
 *         context-free group first. Later declarations of the same name inside
 *         one group win, matching the CSS cascade.
 */
function ekwa_tokens_parse_var_groups( $raw ) {
	$raw = (string) $raw;
	if ( ! function_exists( 'ekwa_css_walk' ) ) {
		require_once get_template_directory() . '/inc/ekwa-css-rules.php';
	}

	$groups = array();

	/** Add one declaration to the group for an at-rule chain. */
	$add = function ( $chain, $name, $value ) use ( &$groups ) {
		$name  = ekwa_tokens_sanitize_var_name( $name );
		$value = trim( $value );
		// Values may be colors, gradients, or var() chains — allow a safe
		// character set; anything markup-ish or executable is dropped.
		if ( '' === $name || '' === $value
			|| preg_match( '/[<>{}]|url\s*\(\s*["\']?\s*javascript:|expression\s*\(/i', $value ) ) {
			return;
		}
		$key = implode( '||', $chain );
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array( 'chain' => $chain, 'decls' => array() );
		}
		// Re-assign rather than overwrite in place so the last declaration also
		// takes the last position, as the cascade would.
		unset( $groups[ $key ]['decls'][ $name ] );
		$groups[ $key ]['decls'][ $name ] = $value;
	};

	/** Pull `--name: value` pairs out of a declaration body. */
	$scan = function ( $body, $chain ) use ( $add ) {
		$body = rtrim( trim( (string) $body ), ';' ) . ';';
		if ( preg_match_all( '/(--[a-z0-9_-]+)\s*:\s*([^;{}\r\n]+?)\s*;/i', $body, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $pair ) {
				$add( $chain, $pair[1], $pair[2] );
			}
		}
	};

	if ( false !== strpos( $raw, '{' ) ) {
		ekwa_css_walk( $raw, function ( $rule ) use ( $scan, $add ) {
			// Normalize the chain: only at-rules that can legitimately scope a
			// token are kept as context (@media/@supports/@container).
			$chain = array();
			foreach ( $rule['chain'] as $prelude ) {
				$prelude = trim( preg_replace( '/\s+/', ' ', $prelude ) );
				if ( preg_match( '/^@(?:media|supports|container)\b/i', $prelude ) ) {
					$chain[] = $prelude;
				}
			}

			if ( null === $rule['body'] ) {
				// A bare `--name: value;` sitting directly inside an at-rule —
				// the shape this field itself stores.
				if ( preg_match( '/^(--[a-z0-9_-]+)\s*:\s*(.+)$/is', trim( $rule['selector'] ), $m ) ) {
					$add( $chain, $m[1], $m[2] );
				}
				return;
			}
			$scan( $rule['body'], $chain );
		} );
	} else {
		// Plain declaration lines, no context.
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$scan( $line, array() );
		}
	}

	// Context-free declarations must come first so the media overrides that
	// follow can actually override them.
	uasort( $groups, function ( $a, $b ) {
		return ( empty( $a['chain'] ) ? 0 : 1 ) <=> ( empty( $b['chain'] ) ? 0 : 1 );
	} );

	return array_values( $groups );
}

/**
 * Render parsed groups back into the field's editable text format.
 *
 * @param array $groups From ekwa_tokens_parse_var_groups().
 * @return string
 */
function ekwa_tokens_serialize_var_groups( $groups ) {
	$out = array();

	foreach ( $groups as $group ) {
		if ( empty( $group['decls'] ) ) {
			continue;
		}
		$lines = array();
		foreach ( $group['decls'] as $name => $value ) {
			$lines[] = '--' . $name . ': ' . $value . ';';
		}

		if ( empty( $group['chain'] ) ) {
			$out[] = implode( "\n", $lines );
			continue;
		}

		$body   = implode( "\n", array_map( function ( $l ) { return "\t" . $l; }, $lines ) );
		$nested = $body;
		foreach ( array_reverse( $group['chain'] ) as $i => $prelude ) {
			$indent = str_repeat( "\t", count( $group['chain'] ) - $i - 1 );
			$nested = $indent . $prelude . " {\n" . $nested . "\n" . $indent . '}';
		}
		$out[] = $nested;
	}

	return implode( "\n\n", $out );
}

/**
 * Does a `--name: value` custom property declare a font family (rather than a
 * colour, length, or other token)? Font-family variables are owned by the Fonts
 * module (ekwa-fonts.php), which self-hosts the file and adds conditional mobile
 * loading; auto-extracting them here too would redefine the same variable as a
 * bare family name and defeat both. Detected from the value — a font stack names
 * a generic family keyword and/or a quoted family, and never carries a colour,
 * length or CSS function.
 *
 * @param string $name  Variable name (leading dashes optional).
 * @param string $value Declared value.
 * @return bool
 */
function ekwa_tokens_value_is_font_family( $name, $value ) {
	$name  = strtolower( ltrim( (string) $name, '-' ) );
	$value = strtolower( trim( (string) $value ) );
	if ( '' === $value ) {
		return false;
	}
	// Colours, lengths, and CSS functions (incl. var() aliases) are never a bare
	// font stack — leave those as tokens.
	if ( preg_match( '~#[0-9a-f]{3,8}\b|rgba?\(|hsla?\(|var\(|url\(|gradient|\d~', $value ) ) {
		return false;
	}
	// A generic family keyword anywhere in the stack settles it.
	if ( preg_match( '~\b(sans-serif|serif|monospace|cursive|fantasy|system-ui|ui-sans-serif|ui-serif|ui-monospace)\b~', $value ) ) {
		return true;
	}
	// Otherwise, a quoted family paired with a font-ish variable name.
	if ( preg_match( '~["\']~', $value ) && preg_match( '~font|typeface|family|(?:^|[-_])ff(?:[-_]|$)~', $name ) ) {
		return true;
	}
	return false;
}

/**
 * Extract custom-property declarations from a stylesheet (deterministic, no
 * AI). Used to pre-fill the colors textarea from the saved mockup CSS. Font
 * families are dropped — the Fonts tab owns those (see
 * ekwa_tokens_value_is_font_family()).
 *
 * @param string $css
 * @return string `--name: value;` lines.
 */
function ekwa_tokens_extract_vars_from_css( $css ) {
	// Strip comments first so commented-out variables don't leak in.
	$css    = preg_replace( '/\/\*.*?\*\//s', '', (string) $css );
	$groups = ekwa_tokens_parse_var_groups( $css );

	foreach ( $groups as $i => $group ) {
		foreach ( $group['decls'] as $name => $value ) {
			if ( ekwa_tokens_value_is_font_family( $name, $value ) ) {
				unset( $groups[ $i ]['decls'][ $name ] ); // Owned by the Fonts module.
			}
		}
	}

	return ekwa_tokens_serialize_var_groups( $groups );
}

/**
 * Build the :root token CSS (colors + background-image variables). Font
 * variables are emitted separately by the Fonts module so their conditional
 * media query stays with them.
 *
 * @return string CSS, or '' when no tokens are configured.
 */
function ekwa_tokens_root_css() {
	$groups = ekwa_tokens_parse_var_groups( ekwa_tokens_colors() );

	// Background-image variables are always context-free, so they join the
	// first (unconditional) group.
	$bg = array();
	foreach ( ekwa_tokens_bgimages() as $row ) {
		$name = isset( $row['name'] ) ? ekwa_tokens_sanitize_var_name( $row['name'] ) : '';
		$url  = isset( $row['url'] ) ? esc_url( $row['url'] ) : '';
		if ( '' === $name || '' === $url ) {
			continue;
		}
		$bg[ $name ] = "url('" . $url . "')";
	}

	$css = '';
	$did_bg = false;

	foreach ( $groups as $group ) {
		$decls = $group['decls'];
		if ( empty( $group['chain'] ) && ! $did_bg ) {
			$decls  = array_merge( $decls, $bg );
			$did_bg = true;
		}
		if ( empty( $decls ) ) {
			continue;
		}

		$body = '';
		foreach ( $decls as $name => $value ) {
			$body .= '--' . $name . ':' . $value . ';';
		}
		$rule = ':root{' . $body . '}';

		// Re-wrap in the at-rule chain so responsive token overrides keep the
		// breakpoint they were written for.
		foreach ( array_reverse( $group['chain'] ) as $prelude ) {
			$rule = $prelude . '{' . $rule . '}';
		}
		$css .= $rule;
	}

	// No variables configured but backgrounds are.
	if ( ! $did_bg && ! empty( $bg ) ) {
		$body = '';
		foreach ( $bg as $name => $value ) {
			$body .= '--' . $name . ':' . $value . ';';
		}
		$css .= ':root{' . $body . '}';
	}

	return $css;
}

/**
 * Print the token CSS in <head>, FIRST — before the site stylesheet (priority 4),
 * block CSS and section Scoped CSS — so every `var()` reference downstream has
 * its definition already in the document.
 *
 * Priority 3, not 5. The stylesheet these tokens feed prints at 4, so at 5 the
 * `:root` block landed *after* the sheet that reads it. The mockup stylesheet is
 * the whole reason the Background images tab exists: it references
 * `var(--bg-hero-1)` and never declares it, so the token block is the definition,
 * not an override, and it belongs above its consumer.
 *
 * Anything that genuinely needs to WIN over a name the stylesheet also declares
 * still can — the fonts module prints at 5, after the sheet, which is what keeps
 * self-hosting and conditional mobile loading working on a mockup that declares
 * its own font tokens.
 */
/**
 * Minify a token/global CSS blob when the inline-minify toggle is on.
 *
 * These blocks are echoed straight into <head> rather than carried on a style
 * handle, so ekwa_perf_minify_registered_inline_styles() can't reach them —
 * and #ekwa-global-css is routinely the largest single blob on the page
 * (measured at 29,677 raw / 6,228 gzip on a mockup-driven site).
 *
 * @param string $css
 * @return string
 */
function ekwa_tokens_maybe_minify( $css ) {
	if ( function_exists( 'ekwa_inline_minify_enabled' ) && ekwa_inline_minify_enabled()
		&& function_exists( 'ekwa_inline_minify_css' ) ) {
		$min = ekwa_inline_minify_css( $css );
		if ( is_string( $min ) && '' !== trim( $min ) ) {
			return $min;
		}
	}
	return $css;
}

function ekwa_tokens_print_head() {
	$css = ekwa_tokens_root_css();
	if ( '' === $css ) {
		return;
	}
	echo '<style id="ekwa-design-tokens">' . ekwa_tokens_maybe_minify( $css ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'ekwa_tokens_print_head', 3 );

// ═══════════════════════════════════════════════════════════════════════════════
// GLOBAL CSS POOL (thinning <head> stylesheet)
// ═══════════════════════════════════════════════════════════════════════════════
//
// The mockup's base/shared CSS — resets, body typography, generic components —
// lives here and is printed site-wide in <head>. It seeds from the mockup
// stylesheet (minus the color/font *variables*, which the Colors and Fonts tabs
// already emit as :root vars) and SHRINKS as the converter's "Extract this
// section's CSS with AI" moves each section's own rules out to that section's
// Scoped CSS. Whatever no section claims (body font, `.btn`, resets) stays here,
// so the base styling every section inherits is never lost — the gap that made
// `body { font-family }` disappear on the front end.

/**
 * The saved global CSS pool.
 *
 * @return string
 */
function ekwa_tokens_global_css() {
	return (string) get_option( 'ekwa_global_css', '' );
}

/**
 * Strip variable/at-rule declarations that are owned elsewhere, so the global
 * pool never re-declares them: `:root {}` blocks (colors + font families come
 * from the Colors/Fonts tabs), `@font-face` and `@import` (fonts are self-hosted
 * by the Fonts tab). Base element rules (body, headings, a, img…) and component
 * rules are kept — those are exactly what the pool exists to carry.
 *
 * @param string $css
 * @return string
 */
function ekwa_tokens_strip_css_variables( $css ) {
	$css = (string) $css;
	$css = preg_replace( '/@import\b[^;]+;/i', '', $css );
	$css = preg_replace( '/@font-face\s*\{[^}]*\}/is', '', $css );
	// Remove :root variable blocks (they hold no nested braces). This also
	// catches the ones nested in @media — responsive token overrides — which
	// the CSS variables field now carries with their breakpoint intact.
	$css = preg_replace( '/:root\b[^{]*\{[^}]*\}/is', '', $css );
	// Drop the @media shells those left behind, so the pool doesn't accumulate
	// empty breakpoint blocks.
	$css = preg_replace( '/@(?:media|supports|container)\b[^{]*\{\s*\}/i', '', (string) $css );
	$css = preg_replace( "/\n{3,}/", "\n\n", (string) $css );
	return trim( (string) $css );
}

/**
 * Make a raw CSS string safe to echo inside a <style> element: it can never
 * close the element or smuggle markup/JS. Mirrors the guard the block renderer
 * applies to Scoped CSS.
 *
 * @param string $css
 * @return string
 */
function ekwa_tokens_sanitize_css_blob( $css ) {
	$css = (string) $css;
	$css = preg_replace( '#</\s*style#i', '', $css );
	$css = preg_replace( '#<\s*script#i', '', $css );
	$css = str_ireplace( array( 'javascript:', 'expression(' ), '', $css );
	return (string) $css;
}

/**
 * (Re)seed the global pool from the saved mockup stylesheet, minus its
 * variables. Returns the seeded CSS.
 *
 * @return string
 */
function ekwa_tokens_seed_global_css_from_mockup() {
	$mockup = trim( ekwa_tokens_mockup_css() );
	$seed   = '' === $mockup ? '' : ekwa_tokens_strip_css_variables( $mockup );
	update_option( 'ekwa_global_css', $seed, false );
	return $seed;
}

/**
 * Replace the global pool with new CSS (e.g. the leftover after a section's
 * rules were extracted out). Variables are stripped defensively.
 *
 * @param string $css
 */
function ekwa_tokens_set_global_css( $css ) {
	update_option( 'ekwa_global_css', ekwa_tokens_strip_css_variables( (string) $css ), false );
}

/**
 * Every CSS custom property the site actually defines, from all four sources.
 *
 * @return array<string,true> Lowercased names, no leading dashes.
 */
function ekwa_tokens_defined_var_names() {
	$names = array();

	// 1. Whatever declares the site's tokens: the mockup stylesheet's own
	//    :root (current model), or the legacy CSS variables field.
	$declared = ekwa_tokens_legacy_mode() ? ekwa_tokens_colors() : ekwa_tokens_mockup_css();
	foreach ( ekwa_tokens_parse_var_groups( $declared ) as $group ) {
		foreach ( array_keys( $group['decls'] ) as $name ) {
			$names[ strtolower( $name ) ] = true;
		}
	}

	// 2. Background image variables.
	foreach ( ekwa_tokens_bgimages() as $row ) {
		if ( ! empty( $row['name'] ) && ! empty( $row['url'] ) ) {
			$names[ strtolower( ekwa_tokens_sanitize_var_name( $row['name'] ) ) ] = true;
		}
	}

	// 3. Self-hosted fonts — their own variable plus the mockup aliases the
	//    Fonts module emits for them.
	if ( function_exists( 'ekwa_fonts_get_all' ) ) {
		foreach ( ekwa_fonts_get_all() as $font ) {
			$var = ekwa_fonts_sanitize_var_name( $font['var_name'] ?? '' );
			if ( '' !== $var ) {
				$names[ strtolower( $var ) ] = true;
			}
		}
	}
	if ( function_exists( 'ekwa_fonts_mockup_var_aliases' ) ) {
		foreach ( array_keys( ekwa_fonts_mockup_var_aliases() ) as $alias ) {
			$names[ strtolower( $alias ) ] = true;
		}
	}

	return $names;
}

/**
 * Custom properties a stylesheet READS but nothing defines.
 *
 * `color: var(--color-text)` where `--color-text` was never declared is not a
 * CSS error — the declaration is simply thrown away at computed-value time, so
 * the element silently keeps its inherited colour. Nothing warns, and the CSS
 * looks perfectly fine in the editor, which makes this one of the easiest ways
 * for a converted mockup to end up "missing styles" with no visible cause.
 *
 * Two things are deliberately NOT reported: references that supply a fallback
 * (`var(--x, 1rem)` degrades on purpose), and WordPress' own `--wp--*` presets.
 * Properties the stylesheet declares itself count as defined.
 *
 * @param string $css       Stylesheet to check.
 * @param array  $extra     Extra defined names to treat as available.
 * @return string[] Undefined variable names (no leading dashes), in first-use order.
 */
function ekwa_tokens_undefined_vars( $css, $extra = array() ) {
	$css = (string) $css;
	if ( '' === trim( $css ) ) {
		return array();
	}

	$defined = ekwa_tokens_defined_var_names();
	foreach ( (array) $extra as $name ) {
		$defined[ strtolower( ltrim( (string) $name, '-' ) ) ] = true;
	}

	// Anything this sheet declares itself is defined for its own purposes.
	if ( preg_match_all( '/(--[a-z0-9_-]+)\s*:/i', $css, $own ) ) {
		foreach ( $own[1] as $name ) {
			$defined[ strtolower( ltrim( $name, '-' ) ) ] = true;
		}
	}

	$missing = array();
	// `var(--name)` with NO fallback — a comma means the author planned for it.
	if ( preg_match_all( '/var\(\s*--([a-z0-9_-]+)\s*\)/i', $css, $refs ) ) {
		foreach ( $refs[1] as $name ) {
			$key = strtolower( $name );
			if ( isset( $defined[ $key ] ) || 0 === strpos( $key, 'wp--' ) ) {
				continue;
			}
			$missing[ $key ] = true;
		}
	}

	return array_keys( $missing );
}

/**
 * "1,204 lines · 38 KB" — the at-a-glance size of a CSS blob, shown on the
 * collapsed Global CSS field so its bulk is visible without opening it.
 *
 * @param string $css
 * @return string
 */
function ekwa_tokens_css_size_label( $css ) {
	$css = (string) $css;
	if ( '' === trim( $css ) ) {
		return __( 'empty', 'ekwa' );
	}
	$lines = substr_count( $css, "\n" ) + 1;
	return sprintf(
		/* translators: 1: line count, 2: human-readable size (e.g. "38 KB"). */
		_n( '%1$s line · %2$s', '%1$s lines · %2$s', $lines, 'ekwa' ),
		number_format_i18n( $lines ),
		size_format( strlen( $css ) )
	);
}

/**
 * Print the global pool in <head>, after the token (priority 3) and font
 * (priority 5) :root vars so any var() it references is already defined.
 */
function ekwa_tokens_print_global_css() {
	// Legacy sites only — the pool keeps printing exactly where it always did.
	// New sites print the mockup stylesheet earlier (priority 4) instead.
	if ( ! ekwa_tokens_legacy_mode() ) {
		return;
	}
	$css = trim( ekwa_tokens_global_css() );
	if ( '' === $css ) {
		return;
	}
	echo '<style id="ekwa-global-css">' . ekwa_tokens_maybe_minify( ekwa_tokens_sanitize_css_blob( $css ) ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'ekwa_tokens_print_global_css', 6 );

/**
 * Print the mockup stylesheet as the site's global CSS (current model).
 *
 * Priority 4 sits between the two `:root` blocks, and both sides of that are
 * load-bearing:
 *
 *   3 — design tokens.  Background-image (and legacy colour) variables the sheet
 *       REFERENCES but never declares. They have to be defined above it.
 *   5 — font variables.  The Fonts tab OVERRIDES names the sheet declares for
 *       itself, so it has to land below it — that's what keeps self-hosting and
 *       conditional mobile loading working on a mockup with its own font tokens.
 */
function ekwa_tokens_print_mockup_css() {
	if ( ekwa_tokens_legacy_mode() ) {
		return;
	}
	$css = ekwa_tokens_printable_mockup_css();
	if ( '' === $css ) {
		return;
	}
	echo '<style id="ekwa-global-css">' . ekwa_tokens_maybe_minify( ekwa_tokens_sanitize_css_blob( $css ) ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'ekwa_tokens_print_mockup_css', 4 );

/**
 * Same CSS inside the editor (canvas + shell) so authored sections preview
 * with real token values — plus the site stylesheet so base typography matches
 * the front end.
 */
function ekwa_tokens_editor_assets() {
	// enqueue_block_assets fires on the FRONT END as well as in the editor, and
	// ekwa_tokens_print_head() (wp_head, priority 3) already prints this exact
	// :root block there as #ekwa-design-tokens — so it shipped twice in every
	// response. Both hooks stay registered: enqueue_block_assets is the only one
	// that reaches inside the FSE editor iframe, and is_admin() is true there.
	if ( ! is_admin() ) {
		return;
	}
	$css = ekwa_tokens_root_css();
	// The site CSS is printed in <head> on the front end; here it's added ONLY
	// in the editor so the canvas previews with the same base styles.
	$global = trim( ekwa_tokens_site_css() );
	if ( '' === $css && '' === $global ) {
		return;
	}
	wp_register_style( 'ekwa-tokens-inline', false, array(), wp_get_theme()->get( 'Version' ) );
	wp_enqueue_style( 'ekwa-tokens-inline' );
	if ( '' !== $css ) {
		wp_add_inline_style( 'ekwa-tokens-inline', $css );
	}
	if ( '' !== $global ) {
		wp_add_inline_style( 'ekwa-tokens-inline', ekwa_tokens_sanitize_css_blob( $global ) );
	}
}
add_action( 'enqueue_block_editor_assets', 'ekwa_tokens_editor_assets' );
add_action( 'enqueue_block_assets', 'ekwa_tokens_editor_assets' );

/**
 * Save handler — called from ekwa_save_settings() (main settings form).
 */
function ekwa_tokens_save_settings() {
	// The mockup stylesheet — the site's global CSS. Stored raw; it's sanitized
	// for the <style> element at print time.
	if ( isset( $_POST['ekwa_mockup_css'] ) ) {
		update_option( 'ekwa_mockup_css', wp_unslash( $_POST['ekwa_mockup_css'] ), false ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	// ── Legacy fields ────────────────────────────────────────────────────
	// Only rendered (and therefore only posted) by sites still on the split
	// model. Emptying one now means EMPTY — the old "clear to re-derive from
	// the mockup" behaviour would have refilled it and pinned the site to the
	// legacy path forever, so clearing both is how a site opts in to printing
	// the mockup stylesheet directly.
	if ( isset( $_POST['ekwa_tokens_colors'] ) ) {
		update_option( 'ekwa_tokens_colors', ekwa_tokens_sanitize_colors( wp_unslash( $_POST['ekwa_tokens_colors'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	if ( isset( $_POST['ekwa_global_css'] ) ) {
		update_option( 'ekwa_global_css', ekwa_tokens_sanitize_css_blob( wp_unslash( $_POST['ekwa_global_css'] ) ), false ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	// Background image variables (repeater: parallel name/url arrays).
	if ( isset( $_POST['ekwa_tokens_bg_name'] ) && is_array( $_POST['ekwa_tokens_bg_name'] ) ) {
		$names = array_map( 'sanitize_text_field', wp_unslash( $_POST['ekwa_tokens_bg_name'] ) ); // phpcs:ignore
		$urls  = isset( $_POST['ekwa_tokens_bg_url'] ) ? array_map( 'esc_url_raw', wp_unslash( (array) $_POST['ekwa_tokens_bg_url'] ) ) : array(); // phpcs:ignore
		$rows  = array();
		foreach ( $names as $i => $name ) {
			$name = ekwa_tokens_sanitize_var_name( $name );
			$url  = isset( $urls[ $i ] ) ? $urls[ $i ] : '';
			if ( '' !== $name && '' !== $url ) {
				$rows[] = array( 'name' => $name, 'url' => $url );
			}
		}
		update_option( 'ekwa_tokens_bgimages', $rows );
	}

	// Conditional-font breakpoint.
	if ( isset( $_POST['ekwa_fonts_conditional_bp'] ) ) {
		update_option( 'ekwa_fonts_conditional_bp', max( 480, min( 1920, absint( $_POST['ekwa_fonts_conditional_bp'] ) ) ) );
	}

	// Editable front-end JS files (delayed-scripts.js / ekwa-child.js).
	if ( function_exists( 'ekwa_js_editor_save' ) ) {
		ekwa_js_editor_save();
	}
}

/**
 * Render the Design Tokens settings pane (inside the main settings <form>).
 */
function ekwa_tokens_render_tab() {
	$mockup_css = ekwa_tokens_mockup_css();
	$colors     = ekwa_tokens_colors();
	$bgimages   = ekwa_tokens_bgimages();
	$bp         = ekwa_fonts_conditional_bp();

	$mockup_prompts = ekwa_mockup_ai_prompts();
	?>
	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Mockup authoring kit', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Copy one of these prompts into ChatGPT, Claude, or any AI. Each is self-contained — the canonical block structures are baked in — so the mockup it produces converts 100% into the dynamic blocks and its CSS styles the live site 1:1. Use the first to align a mockup you already have; the second to build one from scratch. Nothing to hand around — just copy the text.', 'ekwa' ); ?>
		</p>
		<?php
		$ekwa_prompt_headings = array(
			'retrofit' => __( 'Align an existing mockup', 'ekwa' ),
			'create'   => __( 'Create a new mockup from scratch', 'ekwa' ),
		);
		$ekwa_prompt_i = 0;
		foreach ( $ekwa_prompt_headings as $ekwa_pk => $ekwa_ph ) :
			if ( empty( $mockup_prompts[ $ekwa_pk ] ) ) {
				continue;
			}
			$ekwa_p   = $mockup_prompts[ $ekwa_pk ];
			$ekwa_pid = 'ekwa-mockup-prompt-' . $ekwa_pk;
			$ekwa_prompt_i++;
			?>
			<div style="margin-top:<?php echo $ekwa_prompt_i > 1 ? '1.5em' : '0'; ?>;">
				<h3 style="margin:0 0 .25em;"><?php echo esc_html( $ekwa_ph ); ?></h3>
				<p class="description" style="margin:0 0 .5em;"><?php echo esc_html( $ekwa_p['intro'] ); ?></p>
				<p style="margin:0 0 .4em;">
					<button type="button" class="button button-primary ekwa-copy-prompt" data-target="<?php echo esc_attr( $ekwa_pid ); ?>"><?php esc_html_e( 'Copy prompt', 'ekwa' ); ?></button>
					<span class="ekwa-copy-status" style="margin-left:8px;color:#646970;"></span>
				</p>
				<textarea id="<?php echo esc_attr( $ekwa_pid ); ?>" rows="6" class="large-text code" readonly onclick="this.select();"><?php echo esc_textarea( $ekwa_p['prompt'] ); ?></textarea>
			</div>
			<?php
		endforeach;
		?>
		<script>
		( function () {
			var btns = document.querySelectorAll( '.ekwa-copy-prompt' );
			Array.prototype.forEach.call( btns, function ( btn ) {
				btn.addEventListener( 'click', function () {
					var ta = document.getElementById( btn.getAttribute( 'data-target' ) );
					if ( ! ta ) { return; }
					var status = btn.parentNode.querySelector( '.ekwa-copy-status' );
					function done( ok ) {
						if ( ! status ) { return; }
						status.textContent = ok ? '<?php echo esc_js( __( 'Copied to clipboard.', 'ekwa' ) ); ?>' : '<?php echo esc_js( __( 'Select the text and press Ctrl/Cmd+C.', 'ekwa' ) ); ?>';
						status.style.color = ok ? '#008a20' : '#996800';
					}
					ta.focus();
					ta.select();
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( ta.value ).then( function () { done( true ); }, function () { done( false ); } );
					} else {
						try { done( document.execCommand( 'copy' ) ); } catch ( e ) { done( false ); }
					}
				} );
			} );
		} )();
		</script>
	</div>

	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Mockup Ready Check', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Paste the WHOLE mockup file (index.html) and check it before converting: header/footer structure, whether the menu and dynamic elements will map to the right blocks, media resolution, and CSS setup — with copyable fix-it snippets for anything that won\'t convert cleanly.', 'ekwa' ); ?>
		</p>
		<textarea id="ekwa-mrc-html" rows="8" class="large-text code" placeholder="<!DOCTYPE html>… paste the full mockup HTML …"></textarea>
		<p>
			<button type="button" class="button button-primary" id="ekwa-mrc-run"><?php esc_html_e( 'Check mockup', 'ekwa' ); ?></button>
			<span id="ekwa-mrc-status" style="margin-left:8px;"></span>
		</p>
		<div id="ekwa-mrc-results" style="max-width:860px;"></div>
		<style>
		.ekwa-mrc-row{border:1px solid #d6dde4;border-radius:6px;margin:8px 0;padding:10px 14px;background:#fff}
		.ekwa-mrc-row--pass{border-left:4px solid #00a32a}
		.ekwa-mrc-row--warn{border-left:4px solid #dba617;background:#fffbf0}
		.ekwa-mrc-row--fail{border-left:4px solid #d63638;background:#fcf0f1}
		.ekwa-mrc-row strong{display:inline-block;min-width:180px}
		.ekwa-mrc-fix{margin-top:8px}
		.ekwa-mrc-fix pre{background:#0f172a;color:#e2e8f0;padding:10px 12px;border-radius:6px;overflow-x:auto;font-size:12px;line-height:1.5;max-height:260px}
		.ekwa-mrc-fix summary{cursor:pointer;font-size:12px;color:#2271b1}
		</style>
		<script>
		( function () {
			var btn = document.getElementById( 'ekwa-mrc-run' );
			if ( ! btn ) { return; }
			var ta      = document.getElementById( 'ekwa-mrc-html' );
			var status  = document.getElementById( 'ekwa-mrc-status' );
			var results = document.getElementById( 'ekwa-mrc-results' );
			var restUrl = '<?php echo esc_url_raw( rest_url( 'ekwa/v1/mockup-check' ) ); ?>';
			var icons   = { pass: '✓', warn: '⚠', fail: '✗' };

			function esc( s ) {
				var d = document.createElement( 'div' );
				d.textContent = s || '';
				return d.innerHTML;
			}

			btn.addEventListener( 'click', function () {
				if ( ! ta.value.trim() ) {
					status.textContent = '<?php echo esc_js( __( 'Paste the mockup HTML first.', 'ekwa' ) ); ?>';
					status.style.color = '#d63638';
					return;
				}
				var nonce = ( window.ekwaAdmin && ekwaAdmin.webpRestNonce ) ? ekwaAdmin.webpRestNonce : '';
				btn.disabled = true;
				status.textContent = '<?php echo esc_js( __( 'Checking…', 'ekwa' ) ); ?>';
				status.style.color = '#646970';
				results.innerHTML = '';
				fetch( restUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
					body: JSON.stringify( { html: ta.value } ),
				} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
					btn.disabled = false;
					if ( ! res || ! Array.isArray( res.sections ) ) {
						status.textContent = ( res && res.message ) ? res.message : '<?php echo esc_js( __( 'Check failed.', 'ekwa' ) ); ?>';
						status.style.color = '#d63638';
						return;
					}
					var warns = res.sections.filter( function ( s ) { return s.status !== 'pass'; } ).length;
					status.textContent = warns
						? warns + ' <?php echo esc_js( __( 'item(s) need attention.', 'ekwa' ) ); ?>'
						: '<?php echo esc_js( __( 'Mockup is ready to convert.', 'ekwa' ) ); ?>';
					status.style.color = warns ? '#996800' : '#008a20';
					res.sections.forEach( function ( s ) {
						var row = document.createElement( 'div' );
						row.className = 'ekwa-mrc-row ekwa-mrc-row--' + ( s.status || 'warn' );
						var fix = s.fix
							? '<details class="ekwa-mrc-fix"><summary><?php echo esc_js( __( 'Show canonical snippet', 'ekwa' ) ); ?></summary><pre><code>' + esc( s.fix ) + '</code></pre></details>'
							: '';
						row.innerHTML = '<strong>' + icons[ s.status ] + ' ' + esc( s.label ) + '</strong> ' + esc( s.message ) + fix;
						results.appendChild( row );
					} );
				} ).catch( function () {
					btn.disabled = false;
					status.textContent = '<?php echo esc_js( __( 'Check failed.', 'ekwa' ) ); ?>';
					status.style.color = '#d63638';
				} );
			} );
		} )();
		</script>
	</div>

	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Mockup stylesheet', 'ekwa' ); ?></h2>
		<?php if ( ekwa_tokens_legacy_mode() ) : ?>
			<p class="description" style="margin-bottom:1em;">
				<?php esc_html_e( 'Paste the mockup\'s style.css here once. On this site it is NOT printed to the page — the legacy Global CSS pool below is still in use and prints instead. It feeds font detection (Fonts tab) and the AI tools\' stylesheet context.', 'ekwa' ); ?>
			</p>
		<?php else : ?>
			<p class="description" style="margin-bottom:1em;">
				<?php esc_html_e( 'This is your site\'s stylesheet. Paste the mockup\'s style.css here and it is printed as-is in the <head> of every page — including its :root variables, which become the site\'s design tokens (responsive @media overrides and all). It also feeds font detection on the Fonts tab and the AI tools\' stylesheet context.', 'ekwa' ); ?>
			</p>
			<p class="description" style="margin-bottom:1em;">
				<?php esc_html_e( 'Two things are handled for you and should stay out of it: @font-face and @import are stripped before printing (the Fonts tab self-hosts your typefaces and can skip the download on mobile), and any hard-coded image path is flagged below — upload the image, add it under “Background image variables”, and reference it with var(--your-name). Per-section CSS still belongs in that section\'s Scoped CSS.', 'ekwa' ); ?>
			</p>
		<?php endif; ?>
		<details class="ekwa-collapsible" id="ekwa-mockup-css-details" open>
			<summary>
				<span class="ekwa-collapsible__label"><?php esc_html_e( 'Edit the stylesheet', 'ekwa' ); ?></span>
				<span class="ekwa-collapsible__meta" id="ekwa-mockup-css-meta"><?php echo esc_html( ekwa_tokens_css_size_label( $mockup_css ) ); ?></span>
			</summary>
			<div class="ekwa-collapsible__body">
				<textarea id="ekwa-mockup-css" name="ekwa_mockup_css" rows="10" class="large-text code ekwa-code-css" spellcheck="false" placeholder="/* paste the full mockup stylesheet */"><?php echo esc_textarea( $mockup_css ); ?></textarea>
			</div>
		</details>
		<div id="ekwa-mockup-css-bg-warning" class="ekwa-css-bg-warning" aria-live="polite"></div>
		<div id="ekwa-mockup-css-var-warning" class="ekwa-css-bg-warning" aria-live="polite"></div>
	</div>

	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Import mockup assets', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Upload the mockup\'s images and videos to the media library once. Their filenames are saved to the site manifest, so the Mockup Converter resolves <img src="assets/photo.jpg"> to the right file automatically — no per-page "missing media" mapping.', 'ekwa' ); ?>
		</p>
		<p>
			<button type="button" class="button button-secondary" id="ekwa-tokens-assets-import"><?php esc_html_e( 'Select / upload mockup assets', 'ekwa' ); ?></button>
			<span id="ekwa-tokens-assets-status" style="margin-left:8px;"></span>
		</p>
		<script>
		( function () {
			var btn = document.getElementById( 'ekwa-tokens-assets-import' );
			if ( ! btn ) { return; }
			var status  = document.getElementById( 'ekwa-tokens-assets-status' );
			var restUrl = '<?php echo esc_url_raw( rest_url( 'ekwa/v1/mc-manifest' ) ); ?>';
			var frame   = null; // Built lazily on first click, after wp.media has loaded.

			function ensureFrame() {
				if ( frame || ! window.wp || ! wp.media ) { return frame; }
				frame = wp.media( {
					title: '<?php echo esc_js( __( 'Select or upload mockup assets', 'ekwa' ) ); ?>',
					button: { text: '<?php echo esc_js( __( 'Add to manifest', 'ekwa' ) ); ?>' },
					multiple: true,
					library: { type: [ 'image', 'video', 'audio' ] },
				} );
				frame.on( 'select', function () {
					var ids = frame.state().get( 'selection' ).toJSON().map( function ( a ) { return a.id; } ).filter( Boolean );
					if ( ! ids.length ) { return; }
					var nonce = ( window.ekwaAdmin && ekwaAdmin.webpRestNonce ) ? ekwaAdmin.webpRestNonce : ( window.wpApiSettings ? wpApiSettings.nonce : '' );
					status.textContent = '<?php echo esc_js( __( 'Saving…', 'ekwa' ) ); ?>';
					status.style.color = '#646970';
					fetch( restUrl, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
						body: JSON.stringify( { attachment_ids: ids } ),
					} ).then( function ( r ) { return r.json(); } ).then( function ( res ) {
						if ( res && typeof res.saved !== 'undefined' ) {
							status.textContent = res.saved + ' <?php echo esc_js( __( 'added — manifest now has', 'ekwa' ) ); ?> ' + res.total + ' <?php echo esc_js( __( 'entries.', 'ekwa' ) ); ?>';
							status.style.color = '#008a20';
						} else {
							status.textContent = ( res && res.message ) ? res.message : '<?php echo esc_js( __( 'Save failed.', 'ekwa' ) ); ?>';
							status.style.color = '#d63638';
						}
					} ).catch( function () {
						status.textContent = '<?php echo esc_js( __( 'Save failed.', 'ekwa' ) ); ?>';
						status.style.color = '#d63638';
					} );
				} );
				return frame;
			}

			btn.addEventListener( 'click', function () {
				if ( ! ensureFrame() ) {
					status.textContent = '<?php echo esc_js( __( 'Media library still loading — try again in a moment.', 'ekwa' ) ); ?>';
					status.style.color = '#d63638';
					return;
				}
				frame.open();
			} );
		} )();
		</script>
	</div>

	<?php
	// ── Legacy: the split-stylesheet model ───────────────────────────────
	// Rendered only on sites that still have these populated. They're posted
	// (and therefore saved) only while they're on screen, so a new site can
	// never accidentally fall back into the old model.
	if ( ekwa_tokens_legacy_mode() ) :
		$ekwa_pool = ekwa_tokens_global_css();
		?>
	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Legacy: split stylesheet', 'ekwa' ); ?></h2>
		<div class="notice notice-warning inline" style="margin:0 0 1em;padding:8px 12px;">
			<p style="margin:0 0 .5em;"><strong><?php esc_html_e( 'This site still uses the older split-stylesheet setup, and keeps working exactly as before.', 'ekwa' ); ?></strong></p>
			<p style="margin:0;"><?php esc_html_e( 'The stylesheet used to be broken into three pieces — variables extracted here, a shrinking “Global CSS” pool printed in <head>, and per-section CSS moved out by the converter. Splitting it lost rules in ways nothing reported, so it was retired: the Mockup stylesheet above is now printed directly instead. While either field below holds anything, the Global CSS pool is what prints and the Mockup stylesheet is not. To switch this site over, move whatever you still need into the Mockup stylesheet above, then clear both fields and save. Section Scoped CSS keeps working either way — nothing you have built is affected.', 'ekwa' ); ?></p>
		</div>

		<h3><?php esc_html_e( 'CSS variables', 'ekwa' ); ?></h3>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Emitted in :root on the front end and in the editor. One custom property per line; a group wrapped in @media (max-width: 1600px) { … } is emitted inside that breakpoint. Font families are managed on the Fonts tab, not here.', 'ekwa' ); ?>
		</p>
		<textarea name="ekwa_tokens_colors" rows="8" class="large-text code" placeholder="--brand-primary: #1a6ef5;&#10;--container-width: 1700px;&#10;&#10;@media (max-width: 1440px) {&#10;&#9;--container-width: 1360px;&#10;}"><?php echo esc_textarea( $colors ); ?></textarea>

		<h3 style="margin-top:1.5em;"><?php esc_html_e( 'Global CSS (printed in <head>)', 'ekwa' ); ?></h3>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'The shared CSS every page inherits on this site. Edit freely — this box holds exactly what is printed. Any hard-coded image path is highlighted below; upload the image, add it under “Background image variables”, and reference it with var(--your-name).', 'ekwa' ); ?>
		</p>
		<details class="ekwa-collapsible" id="ekwa-global-css-details" open>
			<summary>
				<span class="ekwa-collapsible__label"><?php esc_html_e( 'Edit the Global CSS', 'ekwa' ); ?></span>
				<span class="ekwa-collapsible__meta" id="ekwa-global-css-meta"><?php echo esc_html( ekwa_tokens_css_size_label( $ekwa_pool ) ); ?></span>
			</summary>
			<div class="ekwa-collapsible__body">
				<textarea id="ekwa-global-css" name="ekwa_global_css" rows="10" class="large-text code ekwa-code-css" spellcheck="false" placeholder="/* shared/global CSS — body, resets, shared components */"><?php echo esc_textarea( $ekwa_pool ); ?></textarea>
			</div>
		</details>
		<div id="ekwa-global-css-bg-warning" class="ekwa-css-bg-warning" aria-live="polite"></div>
		<div id="ekwa-global-css-var-warning" class="ekwa-css-bg-warning" aria-live="polite"></div>
	</div>
	<?php endif; ?>

	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Background image variables', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Upload backgrounds once, use them anywhere as var(--name) — including on ::before/::after pseudo-elements in a section\'s Scoped CSS, which blocks can\'t own directly.', 'ekwa' ); ?>
		</p>
		<table class="widefat striped" id="ekwa-tokens-bg-table" style="max-width:760px;">
			<thead>
				<tr>
					<th style="width:220px;"><?php esc_html_e( 'Variable name', 'ekwa' ); ?></th>
					<th><?php esc_html_e( 'Image URL', 'ekwa' ); ?></th>
					<th style="width:170px;"></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$rows = $bgimages ? $bgimages : array( array( 'name' => '', 'url' => '' ) );
				foreach ( $rows as $row ) :
					?>
					<tr>
						<td><input type="text" name="ekwa_tokens_bg_name[]" value="<?php echo esc_attr( $row['name'] ); ?>" placeholder="bg-hero" class="regular-text" style="width:100%;" /></td>
						<td><input type="url" name="ekwa_tokens_bg_url[]" value="<?php echo esc_attr( $row['url'] ); ?>" class="regular-text" style="width:100%;" /></td>
						<td>
							<button type="button" class="button ekwa-tokens-bg-pick"><?php esc_html_e( 'Select image', 'ekwa' ); ?></button>
							<button type="button" class="button-link-delete ekwa-tokens-bg-remove"><?php esc_html_e( 'Remove', 'ekwa' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="ekwa-tokens-bg-add"><?php esc_html_e( 'Add background', 'ekwa' ); ?></button></p>
		<script>
		( function () {
			var table = document.getElementById( 'ekwa-tokens-bg-table' );
			if ( ! table ) { return; }
			document.getElementById( 'ekwa-tokens-bg-add' ).addEventListener( 'click', function () {
				var tr = table.tBodies[0].rows[0].cloneNode( true );
				tr.querySelectorAll( 'input' ).forEach( function ( i ) { i.value = ''; } );
				table.tBodies[0].appendChild( tr );
			} );
			table.addEventListener( 'click', function ( e ) {
				if ( e.target.classList.contains( 'ekwa-tokens-bg-remove' ) ) {
					var body = table.tBodies[0];
					if ( body.rows.length > 1 ) { e.target.closest( 'tr' ).remove(); }
					else { e.target.closest( 'tr' ).querySelectorAll( 'input' ).forEach( function ( i ) { i.value = ''; } ); }
				}
				if ( e.target.classList.contains( 'ekwa-tokens-bg-pick' ) && window.wp && wp.media ) {
					var row = e.target.closest( 'tr' );
					var frame = wp.media( { title: 'Select background image', multiple: false, library: { type: 'image' } } );
					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first().toJSON();
						row.querySelector( 'input[name="ekwa_tokens_bg_url[]"]' ).value = att.url;
						var nameInput = row.querySelector( 'input[name="ekwa_tokens_bg_name[]"]' );
						if ( ! nameInput.value ) {
							nameInput.value = 'bg-' + ( att.filename || 'image' ).replace( /\.[^.]+$/, '' ).toLowerCase().replace( /[^a-z0-9_-]+/g, '-' );
						}
					} );
					frame.open();
				}
			} );
		} )();
		</script>
	</div>

	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Conditional font breakpoint', 'ekwa' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="ekwa_fonts_conditional_bp"><?php esc_html_e( 'Load custom fonts from', 'ekwa' ); ?></label></th>
				<td>
					<input type="number" id="ekwa_fonts_conditional_bp" name="ekwa_fonts_conditional_bp" value="<?php echo esc_attr( $bp ); ?>" min="480" max="1920" step="1" style="width:100px;" /> px (min-width)
					<p class="description"><?php esc_html_e( 'Fonts marked "conditional" on the Fonts tab resolve to their web-safe fallback below this width — the font files are never downloaded on mobile — and switch to the custom font from this width up.', 'ekwa' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<?php if ( function_exists( 'ekwa_js_editor_render' ) ) { ekwa_js_editor_render(); } ?>
	<?php
}

/**
 * Serialize every design token into an AI prompt section so generated CSS
 * uses the exact site variables instead of hardcoding values.
 *
 * @return string Prompt fragment, or '' when no tokens exist.
 */
function ekwa_tokens_ai_context() {
	$lines = array();

	// Font variables (from the Fonts registry).
	if ( function_exists( 'ekwa_fonts_get_all' ) ) {
		foreach ( ekwa_fonts_get_all() as $font ) {
			$family = (string) ( $font['family'] ?? '' );
			$var    = function_exists( 'ekwa_fonts_sanitize_var_name' ) ? ekwa_fonts_sanitize_var_name( $font['var_name'] ?? '' ) : '';
			if ( '' === $family || '' === $var ) {
				continue;
			}
			$lines[] = "--{$var}  → font-family variable for '{$family}' (ALWAYS use font-family: var(--{$var}); never the raw family name)";
		}
	}

	// Design-token variables — declared in the mockup stylesheet's :root, or in
	// the legacy CSS variables field on sites still on the split model. Groups
	// scoped to a breakpoint are labelled so the AI doesn't treat a responsive
	// override as the base value.
	$declared = ekwa_tokens_legacy_mode() ? ekwa_tokens_colors() : ekwa_tokens_mockup_css();
	foreach ( ekwa_tokens_parse_var_groups( $declared ) as $group ) {
		$suffix = empty( $group['chain'] ) ? '' : '   [only inside ' . implode( ' ', $group['chain'] ) . ']';
		foreach ( $group['decls'] as $name => $value ) {
			$lines[] = '--' . $name . ': ' . $value . ';' . $suffix;
		}
	}

	// Background-image variables.
	foreach ( ekwa_tokens_bgimages() as $row ) {
		$name = isset( $row['name'] ) ? ekwa_tokens_sanitize_var_name( $row['name'] ) : '';
		if ( '' !== $name ) {
			$lines[] = "--{$name}  → background image (use background-image: var(--{$name}); works on ::before/::after too)";
		}
	}

	if ( empty( $lines ) ) {
		return '';
	}

	return "\n\nSITE DESIGN TOKENS — these CSS variables are defined globally on this site. REUSE them: reference the variable (var(--name)) instead of hardcoding any color, font-family, or background image they already represent. Do not redeclare them:\n"
		. implode( "\n", $lines ) . "\n";
}
