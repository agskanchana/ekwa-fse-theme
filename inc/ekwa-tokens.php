<?php
/**
 * Design Tokens — site-wide CSS variables for colors, background images, and
 * the saved mockup stylesheet.
 *
 * The workflow goal is to stop using the child theme's style.css entirely:
 * every section carries its own Scoped CSS, and anything global lives here as
 * a CSS variable emitted in <head> (and inside the editor canvas):
 *
 *   - Color variables    — pasted (or auto-extracted from the saved mockup
 *                          stylesheet) as `--name: value;` lines.
 *   - Background images  — a repeater of variable => image URL, so section CSS
 *                          (including ::before/::after pseudo-elements, which
 *                          blocks can't own) can use background:var(--bg-hero).
 *   - Font variables     — emitted by the Fonts module (ekwa-fonts.php), with
 *                          optional conditional loading (web-safe on mobile,
 *                          custom font from the breakpoint up).
 *
 * All tokens are also serialized into an AI prompt section so Generate with
 * AI / Build with AI reuse the exact same variables.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The saved mockup stylesheet (pasted once in Design Tokens). Used for font
 * detection, AI stylesheet context, and the converter's AI CSS extraction.
 *
 * @return string
 */
function ekwa_tokens_mockup_css() {
	return (string) get_option( 'ekwa_mockup_css', '' );
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
	$raw = (string) $raw;

	if ( false !== strpos( $raw, '{' ) ) {
		// Stylesheet input: collect the contents of declaration blocks only.
		$bodies = array();
		if ( preg_match_all( '/\{([^{}]*)\}/s', $raw, $bm ) ) {
			foreach ( $bm[1] as $body ) {
				$body = trim( $body );
				if ( '' !== $body ) {
					// Guarantee a trailing terminator so the last declaration in
					// a block (CSS lets it omit the semicolon) still matches below.
					$bodies[] = rtrim( $body, ';' ) . ';';
				}
			}
		}
		$src = implode( "\n", $bodies );
	} else {
		// Bare declaration lines (manual paste): terminate each with ';' so a
		// value that omits the semicolon still matches the declaration regex.
		$src = '';
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$src .= rtrim( $line, ';' ) . ";\n";
			}
		}
	}

	$out = array();
	// Match `--name: value;` only — the required `;` terminator (added above) plus
	// a single-line value (no line breaks) keeps values from bleeding across
	// declarations.
	if ( preg_match_all( '/(--[a-z0-9_-]+)\s*:\s*([^;{}\r\n]+?)\s*;/i', $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $pair ) {
			$name  = ekwa_tokens_sanitize_var_name( $pair[1] );
			$value = trim( $pair[2] );
			// Values may be colors, gradients, or var() chains — allow a safe
			// character set; anything with markup-ish characters is dropped.
			if ( '' === $name || '' === $value || preg_match( '/[<>{}]|url\s*\(\s*["\']?\s*javascript:/i', $value ) ) {
				continue;
			}
			$out[] = '--' . $name . ': ' . $value . ';';
		}
	}
	return implode( "\n", $out );
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
	$css   = preg_replace( '/\/\*.*?\*\//s', '', (string) $css );
	$lines = ekwa_tokens_sanitize_colors( $css );
	if ( '' === $lines ) {
		return '';
	}
	$kept = array();
	foreach ( explode( "\n", $lines ) as $line ) {
		if ( preg_match( '/^(--[a-z0-9_-]+)\s*:\s*(.+?);?$/i', trim( $line ), $m )
			&& ekwa_tokens_value_is_font_family( $m[1], $m[2] ) ) {
			continue; // Owned by the Fonts module — don't duplicate here.
		}
		if ( '' !== trim( $line ) ) {
			$kept[] = $line;
		}
	}
	return implode( "\n", $kept );
}

/**
 * Build the :root token CSS (colors + background-image variables). Font
 * variables are emitted separately by the Fonts module so their conditional
 * media query stays with them.
 *
 * @return string CSS, or '' when no tokens are configured.
 */
function ekwa_tokens_root_css() {
	$decls = '';

	$colors = trim( ekwa_tokens_colors() );
	if ( '' !== $colors ) {
		// Stored pre-sanitized; collapse to single line.
		$decls .= preg_replace( '/\s*\n\s*/', '', $colors );
	}

	foreach ( ekwa_tokens_bgimages() as $row ) {
		$name = isset( $row['name'] ) ? ekwa_tokens_sanitize_var_name( $row['name'] ) : '';
		$url  = isset( $row['url'] ) ? esc_url( $row['url'] ) : '';
		if ( '' === $name || '' === $url ) {
			continue;
		}
		$decls .= '--' . $name . ":url('" . $url . "');";
	}

	return '' === $decls ? '' : ':root{' . $decls . '}';
}

/**
 * Print the token CSS in <head> (before block CSS and section Scoped CSS so
 * the variables are defined when they're referenced).
 */
function ekwa_tokens_print_head() {
	$css = ekwa_tokens_root_css();
	if ( '' === $css ) {
		return;
	}
	echo '<style id="ekwa-design-tokens">' . $css . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'ekwa_tokens_print_head', 5 );

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
	// Remove :root variable blocks (they hold no nested braces).
	$css = preg_replace( '/:root\b[^{]*\{[^}]*\}/is', '', $css );
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
 * Print the global pool in <head>, after the token/font :root vars (priority 5)
 * so any var() it references is already defined.
 */
function ekwa_tokens_print_global_css() {
	$css = trim( ekwa_tokens_global_css() );
	if ( '' === $css ) {
		return;
	}
	echo '<style id="ekwa-global-css">' . ekwa_tokens_sanitize_css_blob( $css ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'ekwa_tokens_print_global_css', 6 );

/**
 * Same CSS inside the editor (canvas + shell) so authored sections preview
 * with real token values — plus the global pool so base typography matches
 * the front end.
 */
function ekwa_tokens_editor_assets() {
	$css = ekwa_tokens_root_css();
	// The global pool is printed in <head> on the front end by
	// ekwa_tokens_print_global_css(); here it's added ONLY in the editor (this
	// hook also fires front-side via enqueue_block_assets) so the canvas previews
	// with the same base styles without double-printing the pool on the front end.
	$global = is_admin() ? trim( ekwa_tokens_global_css() ) : '';
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
	// Saved mockup stylesheet.
	if ( isset( $_POST['ekwa_mockup_css'] ) ) {
		// Raw CSS: keep as-is (it's never output; only parsed/sent to AI).
		update_option( 'ekwa_mockup_css', wp_unslash( $_POST['ekwa_mockup_css'] ), false ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	// Colors — sanitize to safe declaration lines. When left empty but a
	// mockup stylesheet exists, auto-extract its custom properties.
	if ( isset( $_POST['ekwa_tokens_colors'] ) ) {
		$colors = ekwa_tokens_sanitize_colors( wp_unslash( $_POST['ekwa_tokens_colors'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $colors && '' !== trim( ekwa_tokens_mockup_css() ) ) {
			$colors = ekwa_tokens_extract_vars_from_css( ekwa_tokens_mockup_css() );
		}
		update_option( 'ekwa_tokens_colors', $colors );
	}

	// Global CSS pool. Persist the edited value as-is (this is where the thinning
	// pool is stored — the field shows its current, shrinking contents). Saving
	// it EMPTY (re)seeds it from the mockup stylesheet minus its variables — the
	// same "clear to re-derive" convention as the Colors field above.
	if ( isset( $_POST['ekwa_global_css'] ) ) {
		$global = ekwa_tokens_sanitize_css_blob( wp_unslash( $_POST['ekwa_global_css'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === trim( $global ) && '' !== trim( ekwa_tokens_mockup_css() ) ) {
			$global = ekwa_tokens_strip_css_variables( ekwa_tokens_mockup_css() );
		}
		update_option( 'ekwa_global_css', $global, false );
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
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Paste the mockup\'s style.css here once. It is never output to the page — it feeds font detection (Fonts tab), the AI generators\' stylesheet context, and the converter\'s "Extract section CSS with AI".', 'ekwa' ); ?>
		</p>
		<textarea name="ekwa_mockup_css" rows="10" class="large-text code" placeholder="/* paste the full mockup stylesheet */"><?php echo esc_textarea( $mockup_css ); ?></textarea>
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

	<div class="ekwa-section">
		<h2><?php esc_html_e( 'CSS variables', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'One CSS custom property per line (e.g. --brand-primary: #1a6ef5; --space-lg: 2rem; --radius: 8px;). Not just colours — any :root variable your CSS reuses. Emitted globally in :root on the front end and in the editor, and sent to the AI so generated CSS reuses them. Save with this field empty to auto-extract the variables from the mockup stylesheet above — font families are skipped here, manage those on the Fonts tab.', 'ekwa' ); ?>
		</p>
		<textarea name="ekwa_tokens_colors" rows="8" class="large-text code" placeholder="--brand-primary: #1a6ef5;&#10;--brand-dark: #0f2f66;"><?php echo esc_textarea( $colors ); ?></textarea>
	</div>

	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Global CSS (printed in <head>)', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'The shared, site-wide CSS every page inherits — resets, body typography, generic components. It seeds from the mockup stylesheet above (minus the CSS/font variables, which the CSS variables and Fonts sections already emit), and shrinks on its own as you use the converter\'s “Extract this section\'s CSS with AI”: each section\'s own rules move out to that section\'s Scoped CSS, and whatever no section claims (body font, buttons, resets) stays here. Edit freely — this box always holds the current pool. Save it EMPTY to re-seed from the mockup stylesheet.', 'ekwa' ); ?>
		</p>
		<p class="description" style="margin-bottom:1em;"><?php esc_html_e( 'Heads up: any hard-coded image path — like background: url(images/hero.jpg) — is highlighted in red below, because mockup-relative paths break on the live site. Upload the image to the Media Library, add it under “Background image variables” below, and reference it with var(--your-name). Backgrounds that already use a variable are fine.', 'ekwa' ); ?></p>
			<textarea id="ekwa-global-css" name="ekwa_global_css" rows="10" class="large-text code ekwa-code-css" spellcheck="false" placeholder="/* shared/global CSS — body, resets, shared components */"><?php echo esc_textarea( ekwa_tokens_global_css() ); ?></textarea>
			<div id="ekwa-global-css-bg-warning" class="ekwa-css-bg-warning" aria-live="polite"></div>
	</div>

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

	// Color variables.
	$colors = trim( ekwa_tokens_colors() );
	if ( '' !== $colors ) {
		foreach ( explode( "\n", $colors ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
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
