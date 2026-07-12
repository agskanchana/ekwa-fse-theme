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
 * lines. Accepts full `:root { … }` blocks or bare declaration lines; drops
 * anything that isn't a custom property with a simple value.
 *
 * @param string $raw
 * @return string One declaration per line.
 */
function ekwa_tokens_sanitize_colors( $raw ) {
	$out = array();
	if ( preg_match_all( '/(--[a-z0-9_-]+)\s*:\s*([^;{}]+);?/i', (string) $raw, $m, PREG_SET_ORDER ) ) {
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
 * Extract custom-property declarations from a stylesheet (deterministic, no
 * AI). Used to pre-fill the colors textarea from the saved mockup CSS.
 *
 * @param string $css
 * @return string `--name: value;` lines.
 */
function ekwa_tokens_extract_vars_from_css( $css ) {
	// Strip comments first so commented-out variables don't leak in.
	$css = preg_replace( '/\/\*.*?\*\//s', '', (string) $css );
	return ekwa_tokens_sanitize_colors( $css );
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

/**
 * Same CSS inside the editor (canvas + shell) so authored sections preview
 * with real token values.
 */
function ekwa_tokens_editor_assets() {
	$css = ekwa_tokens_root_css();
	if ( '' === $css ) {
		return;
	}
	wp_register_style( 'ekwa-tokens-inline', false, array(), wp_get_theme()->get( 'Version' ) );
	wp_enqueue_style( 'ekwa-tokens-inline' );
	wp_add_inline_style( 'ekwa-tokens-inline', $css );
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
}

/**
 * Render the Design Tokens settings pane (inside the main settings <form>).
 */
function ekwa_tokens_render_tab() {
	$mockup_css = ekwa_tokens_mockup_css();
	$colors     = ekwa_tokens_colors();
	$bgimages   = ekwa_tokens_bgimages();
	$bp         = ekwa_fonts_conditional_bp();
	?>
	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Mockup stylesheet', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Paste the mockup\'s style.css here once. It is never output to the page — it feeds font detection (Fonts tab), the AI generators\' stylesheet context, and the converter\'s "Extract section CSS with AI".', 'ekwa' ); ?>
		</p>
		<textarea name="ekwa_mockup_css" rows="10" class="large-text code" placeholder="/* paste the full mockup stylesheet */"><?php echo esc_textarea( $mockup_css ); ?></textarea>
	</div>

	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Color variables', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'One CSS custom property per line (e.g. --brand-primary: #1a6ef5;). Emitted globally in :root on the front end and in the editor, and sent to the AI so generated CSS reuses them. Save with this field empty to auto-extract the variables from the mockup stylesheet above.', 'ekwa' ); ?>
		</p>
		<textarea name="ekwa_tokens_colors" rows="8" class="large-text code" placeholder="--brand-primary: #1a6ef5;&#10;--brand-dark: #0f2f66;"><?php echo esc_textarea( $colors ); ?></textarea>
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
