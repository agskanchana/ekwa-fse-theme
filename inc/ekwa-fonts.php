<?php
/**
 * Ekwa Custom Fonts.
 *
 * Lets admins add fonts to the theme as either an uploaded font file or a
 * Google Font downloaded server-side into wp-content/fonts/. Each font is bound
 * to a user-named CSS variable that is emitted in <head> and also registered
 * in theme.json so it appears in the block editor font-family picker.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------------
 * Constants & helpers
 * ------------------------------------------------------------------------ */

define( 'EKWA_FONTS_OPTION', 'ekwa_custom_fonts' );
define( 'EKWA_FONTS_MIGRATED_OPTION', 'ekwa_fonts_migrated_to_content' );

/**
 * Get the absolute filesystem path to the fonts dir, with trailing slash.
 * Stored under wp-content/fonts/ so files survive theme updates/switches.
 */
function ekwa_fonts_dir_path() {
	return trailingslashit( WP_CONTENT_DIR . '/fonts' );
}

/**
 * Get the public URL for the fonts dir, with trailing slash.
 */
function ekwa_fonts_dir_url() {
	return trailingslashit( content_url( '/fonts' ) );
}

/**
 * Legacy location (kept only so we can migrate files out of it once).
 */
function ekwa_fonts_legacy_dir_path() {
	return trailingslashit( get_template_directory() . '/assets/fonts' );
}

/**
 * One-time migration: move any font files that were previously saved under the
 * theme's assets/fonts directory into wp-content/fonts so saved entries keep
 * resolving after the path change. Safe to call on every request — it short-
 * circuits once the option flag is set.
 */
function ekwa_fonts_maybe_migrate_legacy_dir() {
	if ( get_option( EKWA_FONTS_MIGRATED_OPTION ) ) {
		return;
	}
	$legacy = ekwa_fonts_legacy_dir_path();
	if ( ! is_dir( $legacy ) ) {
		update_option( EKWA_FONTS_MIGRATED_OPTION, 1, false );
		return;
	}
	$target = ekwa_fonts_ensure_dir();
	if ( is_wp_error( $target ) ) {
		return; // try again next request
	}
	$files = glob( $legacy . 'ekwa-*.{woff2,woff,ttf,otf}', GLOB_BRACE );
	if ( is_array( $files ) ) {
		foreach ( $files as $src ) {
			$dest = $target . basename( $src );
			if ( file_exists( $dest ) ) {
				continue;
			}
			@rename( $src, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	update_option( EKWA_FONTS_MIGRATED_OPTION, 1, false );
}
add_action( 'admin_init', 'ekwa_fonts_maybe_migrate_legacy_dir' );
add_action( 'init', 'ekwa_fonts_maybe_migrate_legacy_dir' );

/**
 * Allowed font file extensions (lowercased, no dot).
 */
function ekwa_fonts_allowed_exts() {
	return array( 'woff2', 'woff', 'ttf', 'otf' );
}

/**
 * Weights we let users pick from when downloading Google Fonts.
 */
function ekwa_fonts_weight_choices() {
	return array( '100', '200', '300', '400', '500', '600', '700', '800', '900' );
}

/**
 * Sanitize the user-supplied CSS variable name. Always returns a value
 * suitable for use as `--{name}` (no leading dashes, kebab-case).
 */
function ekwa_fonts_sanitize_var_name( $raw ) {
	$raw = strtolower( (string) $raw );
	$raw = ltrim( $raw, '-' );
	$raw = preg_replace( '/[^a-z0-9-]+/', '-', $raw );
	$raw = trim( $raw, '-' );
	if ( '' === $raw ) {
		$raw = 'ekwa-font';
	}
	return $raw;
}

/**
 * The generic CSS font keywords (always listed first in the fallback picker).
 *
 * @return string[]
 */
function ekwa_fonts_generic_fallbacks() {
	return array( 'sans-serif', 'serif', 'monospace', 'cursive', 'system-ui' );
}

/**
 * Allowed fallback choices: stored key => full CSS font-stack it expands to.
 *
 * The key is what the picker stores and what we validate against; the value is
 * the stack written into the CSS variable / theme.json font-family so the
 * fallback degrades sensibly (each web-safe family carries its own generic
 * tail). Generic keywords map to themselves, which keeps older saved values
 * working unchanged.
 *
 * @return array<string,string>
 */
function ekwa_fonts_fallback_choices() {
	return array(
		// Generic keywords.
		'sans-serif'      => 'sans-serif',
		'serif'           => 'serif',
		'monospace'       => 'monospace',
		'cursive'         => 'cursive',
		'system-ui'       => 'system-ui',
		// Web-safe families (each with its own fallback tail).
		'Arial'           => 'Arial, Helvetica, sans-serif',
		'Helvetica'       => 'Helvetica, Arial, sans-serif',
		'Verdana'         => 'Verdana, Geneva, sans-serif',
		'Tahoma'          => 'Tahoma, Geneva, sans-serif',
		'Trebuchet MS'    => '"Trebuchet MS", Helvetica, Arial, sans-serif',
		'Gill Sans'       => '"Gill Sans", "Gill Sans MT", Calibri, sans-serif',
		'Georgia'         => 'Georgia, "Times New Roman", Times, serif',
		'Times New Roman' => '"Times New Roman", Times, serif',
		'Garamond'        => 'Garamond, Baskerville, "Times New Roman", serif',
		'Palatino'        => '"Palatino Linotype", Palatino, "Book Antiqua", serif',
		'Cambria'         => 'Cambria, Georgia, serif',
		'Courier New'     => '"Courier New", Courier, monospace',
		'Lucida Console'  => '"Lucida Console", Monaco, monospace',
	);
}

/**
 * Sanitize a fallback choice. Returns a valid stored key (e.g. "serif" or
 * "Georgia"), defaulting to 'sans-serif' when unrecognised. Matches older
 * lowercased saves case-insensitively for backward-compatibility.
 */
function ekwa_fonts_sanitize_fallback( $raw ) {
	$choices = ekwa_fonts_fallback_choices();
	$raw     = trim( (string) $raw );
	if ( isset( $choices[ $raw ] ) ) {
		return $raw;
	}
	foreach ( array_keys( $choices ) as $key ) {
		if ( 0 === strcasecmp( $key, $raw ) ) {
			return $key;
		}
	}
	return 'sans-serif';
}

/**
 * Resolve a stored fallback key to the full CSS font-stack it expands to.
 */
function ekwa_fonts_fallback_stack( $fallback ) {
	$choices = ekwa_fonts_fallback_choices();
	$key     = ekwa_fonts_sanitize_fallback( $fallback );
	return isset( $choices[ $key ] ) ? $choices[ $key ] : 'sans-serif';
}

/**
 * Render the <option> list for a fallback <select>, grouped into Generic and
 * Web-safe, with $selected pre-chosen. Used by both the Google and Upload rows.
 *
 * @param string $selected Currently-selected fallback key.
 * @return string Options markup.
 */
function ekwa_fonts_fallback_options_html( $selected = 'sans-serif' ) {
	$selected = ekwa_fonts_sanitize_fallback( $selected );
	$generic  = ekwa_fonts_generic_fallbacks();
	$choices  = ekwa_fonts_fallback_choices();

	$html = '<optgroup label="' . esc_attr__( 'Generic', 'ekwa' ) . '">';
	foreach ( $generic as $key ) {
		$html .= '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $key ) . '</option>';
	}
	$html .= '</optgroup>';

	$html .= '<optgroup label="' . esc_attr__( 'Web-safe fonts', 'ekwa' ) . '">';
	foreach ( array_keys( $choices ) as $key ) {
		if ( in_array( $key, $generic, true ) ) {
			continue;
		}
		$html .= '<option value="' . esc_attr( $key ) . '"' . selected( $selected, $key, false ) . '>' . esc_html( $key ) . '</option>';
	}
	$html .= '</optgroup>';

	return $html;
}

/**
 * Return all configured custom fonts.
 *
 * @return array<string,array>
 */
function ekwa_fonts_get_all() {
	$fonts = get_option( EKWA_FONTS_OPTION, array() );
	return is_array( $fonts ) ? $fonts : array();
}

/**
 * Persist the full font registry.
 */
function ekwa_fonts_save_all( $fonts ) {
	update_option( EKWA_FONTS_OPTION, $fonts, false );
}

/**
 * Build a stable filename for a downloaded/uploaded weight file.
 *
 * Example: "Lora", 700, ".woff2" -> "ekwa-lora-700.woff2"
 */
function ekwa_fonts_build_filename( $family, $weight, $ext ) {
	$slug   = sanitize_title( $family );
	$weight = preg_replace( '/[^0-9a-z]+/i', '', (string) $weight );
	$ext    = ltrim( strtolower( $ext ), '.' );
	return 'ekwa-' . $slug . '-' . $weight . '.' . $ext;
}

/**
 * Ensure the fonts directory is writable and exists. Returns the path on
 * success, WP_Error on failure.
 */
function ekwa_fonts_ensure_dir() {
	$dir = ekwa_fonts_dir_path();
	if ( ! file_exists( $dir ) ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'ekwa_fonts_mkdir', __( 'Could not create the fonts directory.', 'ekwa' ) );
		}
	}
	if ( ! is_writable( $dir ) ) {
		return new WP_Error( 'ekwa_fonts_writable', __( 'The fonts directory is not writable.', 'ekwa' ) );
	}
	return $dir;
}

/* ------------------------------------------------------------------------
 * Admin assets
 * ------------------------------------------------------------------------ */

function ekwa_fonts_admin_enqueue( $hook ) {
	if ( 'appearance_page_ekwa-settings' !== $hook ) {
		return;
	}
	wp_enqueue_script(
		'ekwa-fonts-admin',
		get_template_directory_uri() . '/assets/js/ekwa-fonts-admin.js',
		array( 'jquery', 'wp-util' ),
		wp_get_theme()->get( 'Version' ),
		true
	);
	wp_localize_script( 'ekwa-fonts-admin', 'ekwaFonts', array(
		'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
		'nonce'          => wp_create_nonce( 'ekwa_fonts' ),
		'weightChoices'  => ekwa_fonts_weight_choices(),
		'i18n'           => array(
			'downloading'     => __( 'Downloading…', 'ekwa' ),
			'uploading'       => __( 'Uploading…', 'ekwa' ),
			'pickFont'        => __( 'Search Google Fonts…', 'ekwa' ),
			'noResults'       => __( 'No matching fonts', 'ekwa' ),
			'confirmRemove'   => __( 'Remove this font entry? Existing files in wp-content/fonts will stay on disk.', 'ekwa' ),
			'missingFamily'   => __( 'Please choose a Google Font first.', 'ekwa' ),
			'missingWeights'  => __( 'Please pick at least one weight.', 'ekwa' ),
			'missingFile'     => __( 'Please choose a font file (.woff2, .woff, .ttf, .otf).', 'ekwa' ),
			'invalidExt'      => __( 'Only .woff2, .woff, .ttf, .otf files are accepted.', 'ekwa' ),
			'detecting'       => __( 'Scanning the child theme stylesheet with AI…', 'ekwa' ),
			'aiError'         => __( 'Could not analyse the stylesheet. Please try again.', 'ekwa' ),
			'aiNoFonts'       => __( 'No embeddable typefaces were detected in the stylesheet.', 'ekwa' ),
			'aiHeading'       => __( 'Fonts detected in the child theme stylesheet', 'ekwa' ),
			'aiIntro'         => __( 'Review each typeface, then click Embed to self-host it. Google fonts are downloaded; custom fonts open an upload row.', 'ekwa' ),
			'aiUsage'         => __( 'used in %s rules', 'ekwa' ),
			'aiItalic'        => __( 'italic used — Google download self-hosts the upright (normal) weights only.', 'ekwa' ),
			'srcGoogle'       => __( 'Google Font', 'ekwa' ),
			'srcCustom'       => __( 'Custom / upload', 'ekwa' ),
			'embed'           => __( 'Embed', 'ekwa' ),
			'embedUpload'     => __( 'Upload to embed', 'ekwa' ),
			'dismiss'         => __( 'Dismiss', 'ekwa' ),
		),
	) );
}
add_action( 'admin_enqueue_scripts', 'ekwa_fonts_admin_enqueue' );

/* ------------------------------------------------------------------------
 * Settings tab — render
 * ------------------------------------------------------------------------ */

/**
 * Render the Fonts tab markup. Called from inside the main settings form.
 */
function ekwa_fonts_render_tab() {
	$fonts   = ekwa_fonts_get_all();
	$weights = ekwa_fonts_weight_choices();
	?>
	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Custom Fonts', 'ekwa' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Add fonts and bind each one to a CSS variable. Variables appear in <head> as :root { --your-name: ...; } and the font is also registered in the block editor font picker.', 'ekwa' ); ?>
		</p>

		<div id="ekwa-fonts-list" class="ekwa-fonts-list">
			<?php foreach ( $fonts as $id => $font ) : ?>
				<?php ekwa_fonts_render_row( $id, $font ); ?>
			<?php endforeach; ?>
		</div>

		<div class="ekwa-fonts-actions" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
			<button type="button" class="button button-secondary" id="ekwa-fonts-add-google">
				<?php esc_html_e( '+ Add Google Font', 'ekwa' ); ?>
			</button>
			<button type="button" class="button button-secondary" id="ekwa-fonts-add-upload">
				<?php esc_html_e( '+ Upload Font File', 'ekwa' ); ?>
			</button>
			<?php
			$child_active = ( get_template_directory() !== get_stylesheet_directory() );
			$ai_key       = function_exists( 'ekwa_get_ai_api_key' ) ? ekwa_get_ai_api_key() : false;
			if ( $child_active ) :
				?>
				<button type="button" class="button button-secondary" id="ekwa-fonts-detect-ai" <?php disabled( ! $ai_key ); ?>>
					<span class="dashicons dashicons-superhero-alt" style="vertical-align:text-bottom;"></span>
					<?php esc_html_e( 'Detect fonts from stylesheet (AI)', 'ekwa' ); ?>
				</button>
				<?php if ( ! $ai_key ) : ?>
					<span class="description" style="margin:0;">
						<?php
						printf(
							/* translators: %s: link to the AI settings tab. */
							wp_kses_post( __( 'Add a %s to enable AI detection.', 'ekwa' ) ),
							'<a href="' . esc_url( admin_url( 'themes.php?page=ekwa-settings&ekwa_tab=ai' ) ) . '">' . esc_html__( 'Gemini API key', 'ekwa' ) . '</a>'
						);
						?>
					</span>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div id="ekwa-fonts-ai-results" class="ekwa-fonts-ai-results" style="display:none;"></div>
	</div>

	<?php /* HTML template for a Google-font picker row (used by JS when adding new) */ ?>
	<script type="text/html" id="tmpl-ekwa-fonts-new-google">
		<div class="ekwa-fonts-new ekwa-fonts-new--google" data-mode="google">
			<h3 style="margin-top:0;"><?php esc_html_e( 'New Google Font', 'ekwa' ); ?></h3>
			<table class="form-table"><tbody>
				<tr>
					<th><label><?php esc_html_e( 'Google Font family', 'ekwa' ); ?></label></th>
					<td>
						<input type="text" class="regular-text ekwa-fonts-family-search" placeholder="<?php esc_attr_e( 'Start typing… e.g. Lora', 'ekwa' ); ?>" autocomplete="off" />
						<ul class="ekwa-fonts-suggest" style="display:none;"></ul>
						<div class="ekwa-fonts-preview" style="display:none;">
							<span class="ekwa-fonts-preview-label"><?php esc_html_e( 'Preview', 'ekwa' ); ?>:</span>
							<span class="ekwa-fonts-preview-sample">The quick brown fox jumps over the lazy dog</span>
						</div>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'CSS variable name', 'ekwa' ); ?></label></th>
					<td>
						<code>--</code>
						<input type="text" class="regular-text ekwa-fonts-varname" value="" placeholder="<?php esc_attr_e( 'auto from family', 'ekwa' ); ?>" />
						<p class="description"><?php esc_html_e( 'Auto-set from the family name. Edit if you want a custom variable.', 'ekwa' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Weights to download', 'ekwa' ); ?></label></th>
					<td class="ekwa-fonts-weights">
						<?php foreach ( $weights as $w ) : ?>
							<label style="display:inline-block;margin-right:8px;">
								<input type="checkbox" value="<?php echo esc_attr( $w ); ?>" <?php checked( '400' === $w ); ?> />
								<?php echo esc_html( $w ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Fallback', 'ekwa' ); ?></label></th>
					<td>
						<select class="ekwa-fonts-fallback">
							<?php echo ekwa_fonts_fallback_options_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</select>
					</td>
				</tr>
			</tbody></table>
			<p>
				<button type="button" class="button button-primary ekwa-fonts-download"><?php esc_html_e( 'Download & save', 'ekwa' ); ?></button>
				<button type="button" class="button ekwa-fonts-cancel"><?php esc_html_e( 'Cancel', 'ekwa' ); ?></button>
				<span class="spinner" style="float:none;"></span>
				<span class="ekwa-fonts-msg" style="margin-left:8px;"></span>
			</p>
		</div>
	</script>

	<?php /* HTML template for a custom upload row */ ?>
	<script type="text/html" id="tmpl-ekwa-fonts-new-upload">
		<div class="ekwa-fonts-new ekwa-fonts-new--upload" data-mode="upload">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Upload Font File', 'ekwa' ); ?></h3>
			<table class="form-table"><tbody>
				<tr>
					<th><label><?php esc_html_e( 'Font family name', 'ekwa' ); ?></label></th>
					<td>
						<input type="text" class="regular-text ekwa-fonts-family" placeholder="<?php esc_attr_e( 'e.g. My Brand Sans', 'ekwa' ); ?>" />
						<p class="description"><?php esc_html_e( 'How CSS will reference this font (font-family).', 'ekwa' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'CSS variable name', 'ekwa' ); ?></label></th>
					<td>
						<code>--</code>
						<input type="text" class="regular-text ekwa-fonts-varname" value="" placeholder="<?php esc_attr_e( 'auto from family', 'ekwa' ); ?>" />
						<p class="description"><?php esc_html_e( 'Auto-set from the family name. Edit if you want a custom variable.', 'ekwa' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Weight', 'ekwa' ); ?></label></th>
					<td>
						<select class="ekwa-fonts-weight">
							<?php foreach ( $weights as $w ) : ?>
								<option value="<?php echo esc_attr( $w ); ?>" <?php selected( '400' === $w ); ?>><?php echo esc_html( $w ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Upload one file per weight. Add more weights by uploading again on the same variable name.', 'ekwa' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Fallback', 'ekwa' ); ?></label></th>
					<td>
						<select class="ekwa-fonts-fallback">
							<?php echo ekwa_fonts_fallback_options_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label><?php esc_html_e( 'Font file', 'ekwa' ); ?></label></th>
					<td>
						<input type="file" class="ekwa-fonts-file" accept=".woff2,.woff,.ttf,.otf" />
					</td>
				</tr>
			</tbody></table>
			<p>
				<button type="button" class="button button-primary ekwa-fonts-upload"><?php esc_html_e( 'Upload & save', 'ekwa' ); ?></button>
				<button type="button" class="button ekwa-fonts-cancel"><?php esc_html_e( 'Cancel', 'ekwa' ); ?></button>
				<span class="spinner" style="float:none;"></span>
				<span class="ekwa-fonts-msg" style="margin-left:8px;"></span>
			</p>
		</div>
	</script>

	<style>
		.ekwa-fonts-list { display:flex; flex-direction:column; gap:12px; }
		.ekwa-fonts-row,
		.ekwa-fonts-new { border:1px solid #c3c4c7; background:#fff; padding:12px 16px; border-radius:4px; }
		.ekwa-fonts-row h3 { margin:0 0 4px; font-size:14px; }
		.ekwa-fonts-row .ekwa-fonts-meta { color:#646970; font-size:12px; margin:0 0 8px; }
		.ekwa-fonts-row .ekwa-fonts-weights-list { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 8px; }
		.ekwa-fonts-row .ekwa-fonts-weight-chip { background:#f0f0f1; border:1px solid #dcdcde; padding:1px 8px; border-radius:10px; font-size:11px; }
		.ekwa-fonts-row .ekwa-fonts-var { font-family:monospace; background:#f6f7f7; padding:1px 6px; border-radius:3px; }
		.ekwa-fonts-suggest { position:relative; max-height:280px; overflow-y:auto; border:1px solid #c3c4c7; background:#fff; margin:4px 0 0; padding:0; list-style:none; max-width:28em; z-index:2; }
		.ekwa-fonts-suggest li { padding:6px 10px; cursor:pointer; font-size:18px; line-height:1.3; }
		.ekwa-fonts-suggest li .ekwa-fonts-suggest-fallback { font-family:system-ui,sans-serif; font-size:11px; color:#646970; margin-left:6px; }
		.ekwa-fonts-suggest li:hover .ekwa-fonts-suggest-fallback,
		.ekwa-fonts-suggest li.is-active .ekwa-fonts-suggest-fallback { color:#dfe9f2; }
		.ekwa-fonts-suggest li:hover, .ekwa-fonts-suggest li.is-active { background:#2271b1; color:#fff; }
		.ekwa-fonts-preview { margin-top:10px; padding:10px 12px; background:#f6f7f7; border:1px solid #dcdcde; border-radius:3px; max-width:28em; }
		.ekwa-fonts-preview-label { display:block; font-size:11px; color:#646970; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em; }
		.ekwa-fonts-preview-sample { display:block; font-size:22px; line-height:1.3; }
		.ekwa-fonts-ai-results { margin-top:16px; }
		.ekwa-fonts-ai-panel { border:1px solid #c3c4c7; border-left:4px solid #2271b1; background:#fff; padding:12px 16px; border-radius:4px; }
		.ekwa-fonts-ai-panel .ekwa-fonts-ai-head { display:flex; align-items:center; justify-content:space-between; gap:8px; }
		.ekwa-fonts-ai-panel .ekwa-fonts-ai-head h3 { margin:0; font-size:14px; }
		.ekwa-fonts-ai-cards { display:flex; flex-direction:column; gap:10px; margin-top:12px; }
		.ekwa-fonts-ai-card { display:flex; flex-wrap:wrap; align-items:center; gap:10px 16px; border:1px solid #dcdcde; border-radius:4px; padding:10px 12px; background:#fbfbfc; }
		.ekwa-fonts-ai-card-name { font-size:15px; font-weight:600; }
		.ekwa-fonts-ai-card-src { font-size:11px; color:#fff; background:#2271b1; padding:1px 8px; border-radius:10px; }
		.ekwa-fonts-ai-card-src.is-custom { background:#646970; }
		.ekwa-fonts-ai-card-weights { display:flex; flex-wrap:wrap; gap:5px; }
		.ekwa-fonts-ai-card-weights .ekwa-fonts-weight-chip { background:#f0f0f1; border:1px solid #dcdcde; padding:1px 8px; border-radius:10px; font-size:11px; }
		.ekwa-fonts-ai-card-meta { color:#646970; font-size:12px; flex-basis:100%; }
		.ekwa-fonts-ai-card .ekwa-fonts-ai-embed { margin-left:auto; }
	</style>
	<?php
}

/**
 * Render a single saved-font row.
 */
function ekwa_fonts_render_row( $id, $font ) {
	$var_name = ekwa_fonts_sanitize_var_name( $font['var_name'] ?? '' );
	$family   = (string) ( $font['family']   ?? '' );
	$fallback = ekwa_fonts_sanitize_fallback( $font['fallback'] ?? 'sans-serif' );
	$source   = (string) ( $font['source']   ?? 'upload' );
	$weights  = ( isset( $font['weights'] ) && is_array( $font['weights'] ) ) ? $font['weights'] : array();
	ksort( $weights, SORT_NUMERIC );
	?>
	<div class="ekwa-fonts-row" data-id="<?php echo esc_attr( $id ); ?>">
		<h3>
			<?php echo esc_html( $family ); ?>
			<span style="font-weight:400;color:#646970;font-size:12px;">
				(<?php echo esc_html( 'google' === $source ? __( 'Google Font', 'ekwa' ) : __( 'Uploaded', 'ekwa' ) ); ?>)
			</span>
		</h3>
		<p class="ekwa-fonts-meta">
			<?php esc_html_e( 'CSS variable', 'ekwa' ); ?>:
			<span class="ekwa-fonts-var">--<?php echo esc_html( $var_name ); ?></span>
			&nbsp;|&nbsp;
			<?php esc_html_e( 'Fallback', 'ekwa' ); ?>: <?php echo esc_html( $fallback ); ?>
		</p>
		<div class="ekwa-fonts-weights-list">
			<?php foreach ( $weights as $weight => $filename ) : ?>
				<span class="ekwa-fonts-weight-chip" title="<?php echo esc_attr( $filename ); ?>">
					<?php echo esc_html( $weight ); ?>
				</span>
			<?php endforeach; ?>
			<?php if ( ! $weights ) : ?>
				<em style="color:#b32d2e;"><?php esc_html_e( 'No weights — re-upload or re-download.', 'ekwa' ); ?></em>
			<?php endif; ?>
		</div>
		<p>
			<button type="button" class="button-link-delete ekwa-fonts-remove">
				<?php esc_html_e( 'Remove', 'ekwa' ); ?>
			</button>
			&nbsp;
			<label style="font-size:12px;">
				<input type="text" class="ekwa-fonts-rename-var" value="<?php echo esc_attr( $var_name ); ?>" size="22" />
				<button type="button" class="button button-small ekwa-fonts-rename"><?php esc_html_e( 'Rename variable', 'ekwa' ); ?></button>
			</label>
		</p>
	</div>
	<?php
}

/* ------------------------------------------------------------------------
 * AJAX: Google Fonts catalog (cached)
 * ------------------------------------------------------------------------ */

/**
 * Returns the list of Google Font family names from Google's public metadata
 * endpoint. Cached for 7 days. Falls back to a small built-in list on error.
 */
function ekwa_fonts_get_google_catalog() {
	$cached = get_transient( 'ekwa_fonts_google_catalog' );
	if ( is_array( $cached ) && ! empty( $cached ) ) {
		return $cached;
	}

	$res = wp_remote_get(
		'https://fonts.google.com/metadata/fonts',
		array( 'timeout' => 15 )
	);
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return array( 'Lora', 'Inter', 'Roboto', 'Open Sans', 'Playfair Display', 'Montserrat', 'Lato', 'Poppins' );
	}

	// Google prefixes the JSON with ")]}'" — strip it.
	$body = ltrim( wp_remote_retrieve_body( $res ), ")]}'\n\r " );
	$data = json_decode( $body, true );
	$families = array();
	if ( is_array( $data ) && isset( $data['familyMetadataList'] ) && is_array( $data['familyMetadataList'] ) ) {
		foreach ( $data['familyMetadataList'] as $item ) {
			if ( isset( $item['family'] ) ) {
				$families[] = (string) $item['family'];
			}
		}
	}
	if ( empty( $families ) ) {
		return array( 'Lora', 'Inter', 'Roboto', 'Open Sans', 'Playfair Display', 'Montserrat', 'Lato', 'Poppins' );
	}
	set_transient( 'ekwa_fonts_google_catalog', $families, 7 * DAY_IN_SECONDS );
	return $families;
}

function ekwa_fonts_ajax_catalog() {
	check_ajax_referer( 'ekwa_fonts', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ekwa' ) ), 403 );
	}
	wp_send_json_success( array( 'families' => ekwa_fonts_get_google_catalog() ) );
}
add_action( 'wp_ajax_ekwa_fonts_catalog', 'ekwa_fonts_ajax_catalog' );

/* ------------------------------------------------------------------------
 * AJAX: Detect fonts used in the child theme stylesheet (Gemini AI)
 * ------------------------------------------------------------------------ */

/**
 * Read the active (child) theme's style.css, capped for the prompt budget.
 *
 * @return string|WP_Error CSS contents, or error when there's no child sheet.
 */
function ekwa_fonts_read_child_stylesheet() {
	$parent_dir = get_template_directory();
	$child_dir  = get_stylesheet_directory();
	if ( $parent_dir === $child_dir ) {
		return new WP_Error( 'ekwa_fonts_no_child', __( 'No child theme is active, so there is no stylesheet to scan.', 'ekwa' ) );
	}
	$path = $child_dir . '/style.css';
	if ( ! is_readable( $path ) ) {
		return new WP_Error( 'ekwa_fonts_no_css', __( 'The child theme stylesheet could not be read.', 'ekwa' ) );
	}
	$css = file_get_contents( $path );
	if ( false === $css || '' === trim( $css ) ) {
		return new WP_Error( 'ekwa_fonts_empty_css', __( 'The child theme stylesheet is empty.', 'ekwa' ) );
	}
	$max = 120000; // ~120 KB is plenty for a hand-written theme sheet.
	if ( strlen( $css ) > $max ) {
		$css = substr( $css, 0, $max ) . "\n\n/* …truncated for AI analysis */";
	}
	return $css;
}

/**
 * System prompt instructing Gemini to extract real typefaces + weights as JSON.
 *
 * @return string
 */
function ekwa_fonts_ai_detect_prompt() {
	$allowed = implode( ',', ekwa_fonts_weight_choices() );
	return <<<PROMPT
You are a CSS typography analyzer. You will be given the full contents of a WordPress theme stylesheet. Identify every REAL text typeface (font family) the stylesheet actually uses, and for each one list the font-weights and font-styles it is rendered in.

RULES:
- Resolve CSS custom properties. If a variable like `--ff-sans: 'Inter', system-ui, sans-serif;` is defined and used via `font-family: var(--ff-sans)`, the family is "Inter".
- The PRIMARY (first) named family in a stack is the real typeface. IGNORE generic/system fallbacks used only after the primary: serif, sans-serif, monospace, cursive, system-ui, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto (only when clearly a system fallback), Helvetica, Arial, Georgia, "Times New Roman".
- IGNORE icon fonts entirely: "Font Awesome", "Font Awesome 6 Free", "FontAwesome", dashicons, "Material Icons".
- WEIGHTS: collect every explicit font-weight applied to rules that use the family (map keyword normal=400, bold=700). When the family is applied to heading tags (h1–h6) or strong/b WITHOUT an explicit font-weight, assume 700 for those rules. When applied to body/normal text without an explicit weight, assume 400. Output numeric weights only, each from this allowed set: {$allowed}.
- STYLES: list "normal" and/or "italic" based on font-style usage of that family.
- usage_count: number of distinct CSS rules that reference the family (directly or through its variable).
- is_google_font: true if the family is a well-known Google Fonts family; false if it looks like a custom/brand font that would need a manual upload.
- notes: a short (max ~8 words) human note on where it is used (e.g. "Body & UI text", "Headings").

OUTPUT:
Return ONLY valid minified JSON, no markdown, no code fences, no commentary, in EXACTLY this shape:
{"fonts":[{"family":"Inter","is_google_font":true,"weights":["400","600","700"],"styles":["normal"],"usage_count":16,"notes":"Body & UI text"}]}
Order fonts by usage_count descending. If no real typefaces are found, return {"fonts":[]}.
PROMPT;
}

/**
 * Normalize one AI font entry against our allowed weights/values.
 *
 * @param mixed $entry Raw decoded entry.
 * @return array|null Sanitized entry, or null when invalid.
 */
function ekwa_fonts_ai_sanitize_entry( $entry ) {
	if ( ! is_array( $entry ) ) {
		return null;
	}
	$family = isset( $entry['family'] ) ? sanitize_text_field( (string) $entry['family'] ) : '';
	if ( '' === $family ) {
		return null;
	}

	$allowed = ekwa_fonts_weight_choices();
	$weights = array();
	if ( isset( $entry['weights'] ) && is_array( $entry['weights'] ) ) {
		foreach ( $entry['weights'] as $w ) {
			$w = preg_replace( '/[^0-9]/', '', (string) $w );
			if ( '' !== $w && in_array( $w, $allowed, true ) && ! in_array( $w, $weights, true ) ) {
				$weights[] = $w;
			}
		}
	}
	if ( empty( $weights ) ) {
		$weights = array( '400' );
	}
	sort( $weights, SORT_NUMERIC );

	$styles = array();
	if ( isset( $entry['styles'] ) && is_array( $entry['styles'] ) ) {
		foreach ( $entry['styles'] as $s ) {
			$s = strtolower( trim( (string) $s ) );
			if ( in_array( $s, array( 'normal', 'italic' ), true ) && ! in_array( $s, $styles, true ) ) {
				$styles[] = $s;
			}
		}
	}
	if ( empty( $styles ) ) {
		$styles = array( 'normal' );
	}

	return array(
		'family'         => $family,
		'is_google_font' => ! empty( $entry['is_google_font'] ),
		'weights'        => $weights,
		'styles'         => $styles,
		'usage_count'    => isset( $entry['usage_count'] ) ? max( 0, (int) $entry['usage_count'] ) : 0,
		'notes'          => isset( $entry['notes'] ) ? sanitize_text_field( wp_html_excerpt( (string) $entry['notes'], 80 ) ) : '',
	);
}

function ekwa_fonts_ajax_ai_detect() {
	check_ajax_referer( 'ekwa_fonts', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ekwa' ) ), 403 );
	}

	// The AI helpers live in files loaded later in functions.php; they are all
	// loaded by the time this AJAX request runs, but require defensively.
	require_once get_template_directory() . '/inc/ekwa-ai-shared.php';
	if ( ! function_exists( 'ekwa_ai_generate_call_gemini' ) ) {
		require_once get_template_directory() . '/inc/ekwa-ai-generate.php';
	}

	$api_key = ekwa_get_ai_api_key();
	if ( ! $api_key ) {
		wp_send_json_error( array( 'message' => __( 'Gemini API key is not configured (Settings → AI).', 'ekwa' ) ) );
	}

	$css = ekwa_fonts_read_child_stylesheet();
	if ( is_wp_error( $css ) ) {
		wp_send_json_error( array( 'message' => $css->get_error_message() ) );
	}

	$model = 'gemini-2.5-flash';
	if ( function_exists( 'ekwa_ai_generate_allowed_models' ) ) {
		$models = ekwa_ai_generate_allowed_models();
		if ( ! isset( $models[ $model ] ) ) {
			$model = (string) array_key_first( $models );
		}
	}

	$contents = array(
		array(
			'role'  => 'user',
			'parts' => array(
				array( 'text' => "Analyze this stylesheet and return the fonts JSON.\n\n```css\n" . $css . "\n```" ),
			),
		),
	);

	$result = ekwa_ai_generate_call_gemini( ekwa_fonts_ai_detect_prompt(), $contents, 0.1, $api_key, $model );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	$raw = isset( $result['content'] ) ? (string) $result['content'] : '';
	if ( function_exists( 'ekwa_ai_generate_strip_fences' ) ) {
		$raw = ekwa_ai_generate_strip_fences( $raw );
	}
	$data = json_decode( $raw, true );
	// Fallback: pull the first {...} object out if the model added stray text.
	if ( ! is_array( $data ) && preg_match( '/\{.*\}/s', $raw, $m ) ) {
		$data = json_decode( $m[0], true );
	}
	if ( ! is_array( $data ) || ! isset( $data['fonts'] ) || ! is_array( $data['fonts'] ) ) {
		wp_send_json_error( array( 'message' => __( 'The AI response could not be parsed. Please try again.', 'ekwa' ) ) );
	}

	$fonts = array();
	foreach ( $data['fonts'] as $entry ) {
		$clean = ekwa_fonts_ai_sanitize_entry( $entry );
		if ( $clean ) {
			$fonts[] = $clean;
		}
	}

	// Stable order: most-used first.
	usort( $fonts, function ( $a, $b ) {
		return $b['usage_count'] <=> $a['usage_count'];
	} );

	wp_send_json_success( array( 'fonts' => $fonts ) );
}
add_action( 'wp_ajax_ekwa_fonts_ai_detect', 'ekwa_fonts_ajax_ai_detect' );

/* ------------------------------------------------------------------------
 * AJAX: Download a Google Font (server-side)
 * ------------------------------------------------------------------------ */

/**
 * Fetch CSS from Google for the given family + weights, parse out the woff2
 * URLs by weight, and download each into the theme fonts dir.
 *
 * @param string $family  Display name, e.g. "Playfair Display".
 * @param array  $weights Numeric weight strings.
 * @return array|WP_Error Map of weight => saved filename on success.
 */
function ekwa_fonts_download_google( $family, $weights ) {
	$family  = trim( (string) $family );
	$weights = array_values( array_unique( array_filter( array_map( 'strval', (array) $weights ) ) ) );
	if ( '' === $family || empty( $weights ) ) {
		return new WP_Error( 'ekwa_fonts_args', __( 'Missing family or weights.', 'ekwa' ) );
	}

	$dir = ekwa_fonts_ensure_dir();
	if ( is_wp_error( $dir ) ) {
		return $dir;
	}

	$weights_str = implode( ';', $weights );
	$css_url     = add_query_arg(
		array(
			'family'  => str_replace( ' ', '+', $family ) . ':wght@' . $weights_str,
			'display' => 'swap',
		),
		'https://fonts.googleapis.com/css2'
	);

	// Modern UA forces Google to return woff2 URLs.
	$css_res = wp_remote_get( $css_url, array(
		'timeout'    => 15,
		'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
	) );
	if ( is_wp_error( $css_res ) ) {
		return $css_res;
	}
	if ( 200 !== wp_remote_retrieve_response_code( $css_res ) ) {
		return new WP_Error( 'ekwa_fonts_css', __( 'Google Fonts CSS request failed.', 'ekwa' ) );
	}

	$css = wp_remote_retrieve_body( $css_res );

	// Parse each @font-face block: capture font-weight + woff2 src.
	if ( ! preg_match_all( '/@font-face\s*\{[^}]*\}/i', $css, $blocks ) ) {
		return new WP_Error( 'ekwa_fonts_parse', __( 'No @font-face declarations found.', 'ekwa' ) );
	}

	// Google returns SEVERAL @font-face blocks per weight — one per unicode-range
	// subset, in this order: cyrillic(-ext), greek(-ext), vietnamese, latin-ext,
	// latin. We self-host a single file per weight and emit it without a
	// unicode-range, so we MUST pick the `latin` subset (the block whose range
	// covers basic Latin, U+0000–00FF). Grabbing the first block self-hosts the
	// Cyrillic subset, which has none of the Latin letters — so on-page Latin
	// text silently falls back to a system font (the bug this fixes). Prefer
	// latin; fall back to the first subset seen only if a font has no latin block.
	$chosen = array(); // weight => array( src, is_latin )
	foreach ( $blocks[0] as $block ) {
		if ( ! preg_match( '/font-weight:\s*(\d+)/i', $block, $wm ) ) { continue; }
		if ( ! preg_match( '/src:\s*url\((https:\/\/fonts\.gstatic\.com\/[^)]+\.woff2)\)/i', $block, $sm ) ) { continue; }
		$weight = $wm[1];
		if ( ! in_array( $weight, $weights, true ) ) {
			continue;
		}

		$is_latin = (bool) preg_match( '/unicode-range:[^;}]*U\+0000/i', $block );
		if ( ! isset( $chosen[ $weight ] ) || ( $is_latin && empty( $chosen[ $weight ]['is_latin'] ) ) ) {
			$chosen[ $weight ] = array( 'src' => $sm[1], 'is_latin' => $is_latin );
		}
	}

	$saved = array();
	foreach ( $chosen as $weight => $info ) {
		$bin = wp_remote_get( $info['src'], array( 'timeout' => 30 ) );
		if ( is_wp_error( $bin ) || 200 !== wp_remote_retrieve_response_code( $bin ) ) {
			continue;
		}
		$filename = ekwa_fonts_build_filename( $family, $weight, 'woff2' );
		$path     = $dir . $filename;
		if ( false === file_put_contents( $path, wp_remote_retrieve_body( $bin ) ) ) {
			continue;
		}
		$saved[ $weight ] = $filename;
	}

	if ( empty( $saved ) ) {
		return new WP_Error( 'ekwa_fonts_save', __( 'Downloaded 0 weight files. Check that the family name and weights are valid.', 'ekwa' ) );
	}
	return $saved;
}

function ekwa_fonts_ajax_download() {
	check_ajax_referer( 'ekwa_fonts', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ekwa' ) ), 403 );
	}

	$family   = isset( $_POST['family'] )   ? sanitize_text_field( wp_unslash( $_POST['family'] ) )   : '';
	$weights  = isset( $_POST['weights'] )  ? (array) wp_unslash( $_POST['weights'] )                 : array();
	$var_name = isset( $_POST['var_name'] ) ? ekwa_fonts_sanitize_var_name( wp_unslash( $_POST['var_name'] ) ) : 'ekwa-font-custom';
	$fallback = isset( $_POST['fallback'] ) ? ekwa_fonts_sanitize_fallback( wp_unslash( $_POST['fallback'] ) ) : 'sans-serif';

	$weights = array_filter( array_map( 'strval', $weights ), function ( $w ) {
		return in_array( $w, ekwa_fonts_weight_choices(), true );
	} );

	$saved = ekwa_fonts_download_google( $family, $weights );
	if ( is_wp_error( $saved ) ) {
		wp_send_json_error( array( 'message' => $saved->get_error_message() ) );
	}

	$id    = uniqid( 'gf_' );
	$fonts = ekwa_fonts_get_all();
	$fonts[ $id ] = array(
		'id'       => $id,
		'var_name' => $var_name,
		'family'   => $family,
		'fallback' => $fallback,
		'source'   => 'google',
		'weights'  => $saved,
	);
	ekwa_fonts_save_all( $fonts );

	ob_start();
	ekwa_fonts_render_row( $id, $fonts[ $id ] );
	$row_html = ob_get_clean();

	wp_send_json_success( array( 'message' => sprintf( __( 'Saved %d weight file(s).', 'ekwa' ), count( $saved ) ), 'rowHtml' => $row_html ) );
}
add_action( 'wp_ajax_ekwa_fonts_download', 'ekwa_fonts_ajax_download' );

/* ------------------------------------------------------------------------
 * AJAX: Upload a custom font file
 * ------------------------------------------------------------------------ */

function ekwa_fonts_ajax_upload() {
	check_ajax_referer( 'ekwa_fonts', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ekwa' ) ), 403 );
	}

	$family   = isset( $_POST['family'] )   ? sanitize_text_field( wp_unslash( $_POST['family'] ) )   : '';
	$weight   = isset( $_POST['weight'] )   ? sanitize_text_field( wp_unslash( $_POST['weight'] ) )   : '400';
	$var_name = isset( $_POST['var_name'] ) ? ekwa_fonts_sanitize_var_name( wp_unslash( $_POST['var_name'] ) ) : 'ekwa-font-custom';
	$fallback = isset( $_POST['fallback'] ) ? ekwa_fonts_sanitize_fallback( wp_unslash( $_POST['fallback'] ) ) : 'sans-serif';

	if ( '' === $family ) {
		wp_send_json_error( array( 'message' => __( 'Font family name is required.', 'ekwa' ) ) );
	}
	if ( ! in_array( $weight, ekwa_fonts_weight_choices(), true ) ) {
		$weight = '400';
	}
	if ( empty( $_FILES['file'] ) || empty( $_FILES['file']['name'] ) ) {
		wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'ekwa' ) ) );
	}

	$ext = strtolower( pathinfo( $_FILES['file']['name'], PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, ekwa_fonts_allowed_exts(), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Only .woff2, .woff, .ttf, .otf files are accepted.', 'ekwa' ) ) );
	}

	$dir = ekwa_fonts_ensure_dir();
	if ( is_wp_error( $dir ) ) {
		wp_send_json_error( array( 'message' => $dir->get_error_message() ) );
	}

	$filename = ekwa_fonts_build_filename( $family, $weight, $ext );
	$dest     = $dir . $filename;

	if ( ! move_uploaded_file( $_FILES['file']['tmp_name'], $dest ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not save the uploaded file.', 'ekwa' ) ) );
	}

	// If an entry already exists for this variable name, append the weight to it.
	$fonts   = ekwa_fonts_get_all();
	$existing_id = null;
	foreach ( $fonts as $fid => $f ) {
		if ( ekwa_fonts_sanitize_var_name( $f['var_name'] ?? '' ) === $var_name ) {
			$existing_id = $fid;
			break;
		}
	}

	if ( $existing_id ) {
		$fonts[ $existing_id ]['weights'][ $weight ] = $filename;
		// Don't change family/fallback when appending — first entry wins.
	} else {
		$id = uniqid( 'up_' );
		$fonts[ $id ] = array(
			'id'       => $id,
			'var_name' => $var_name,
			'family'   => $family,
			'fallback' => $fallback,
			'source'   => 'upload',
			'weights'  => array( $weight => $filename ),
		);
		$existing_id = $id;
	}
	ekwa_fonts_save_all( $fonts );

	ob_start();
	ekwa_fonts_render_row( $existing_id, $fonts[ $existing_id ] );
	$row_html = ob_get_clean();

	wp_send_json_success( array( 'message' => __( 'Font uploaded.', 'ekwa' ), 'rowHtml' => $row_html, 'id' => $existing_id ) );
}
add_action( 'wp_ajax_ekwa_fonts_upload', 'ekwa_fonts_ajax_upload' );

/* ------------------------------------------------------------------------
 * AJAX: Remove font entry (does NOT delete files on disk)
 * ------------------------------------------------------------------------ */

function ekwa_fonts_ajax_remove() {
	check_ajax_referer( 'ekwa_fonts', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ekwa' ) ), 403 );
	}
	$id    = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
	$fonts = ekwa_fonts_get_all();
	if ( $id && isset( $fonts[ $id ] ) ) {
		unset( $fonts[ $id ] );
		ekwa_fonts_save_all( $fonts );
	}
	wp_send_json_success();
}
add_action( 'wp_ajax_ekwa_fonts_remove', 'ekwa_fonts_ajax_remove' );

/* ------------------------------------------------------------------------
 * AJAX: Rename the CSS variable for an existing entry
 * ------------------------------------------------------------------------ */

function ekwa_fonts_ajax_rename() {
	check_ajax_referer( 'ekwa_fonts', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ekwa' ) ), 403 );
	}
	$id       = isset( $_POST['id'] )       ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
	$var_name = isset( $_POST['var_name'] ) ? ekwa_fonts_sanitize_var_name( wp_unslash( $_POST['var_name'] ) ) : '';
	$fonts    = ekwa_fonts_get_all();
	if ( $id && isset( $fonts[ $id ] ) && '' !== $var_name ) {
		$fonts[ $id ]['var_name'] = $var_name;
		ekwa_fonts_save_all( $fonts );
		wp_send_json_success( array( 'var_name' => $var_name ) );
	}
	wp_send_json_error( array( 'message' => __( 'Invalid request.', 'ekwa' ) ) );
}
add_action( 'wp_ajax_ekwa_fonts_rename', 'ekwa_fonts_ajax_rename' );

/* ------------------------------------------------------------------------
 * Frontend + editor: emit @font-face + :root vars
 * ------------------------------------------------------------------------ */

/**
 * Build the CSS string for all configured fonts: @font-face blocks plus a
 * single :root rule defining each user-named CSS variable.
 */
function ekwa_fonts_build_css() {
	$fonts = ekwa_fonts_get_all();
	if ( empty( $fonts ) ) {
		return '';
	}
	$url_base   = ekwa_fonts_dir_url();
	$dir_base   = ekwa_fonts_dir_path();
	$face_rules = '';
	$root_rules = '';
	foreach ( $fonts as $font ) {
		$family   = (string) ( $font['family']   ?? '' );
		$fallback = ekwa_fonts_sanitize_fallback( $font['fallback'] ?? 'sans-serif' );
		$var      = ekwa_fonts_sanitize_var_name( $font['var_name'] ?? '' );
		$weights  = ( isset( $font['weights'] ) && is_array( $font['weights'] ) ) ? $font['weights'] : array();
		if ( '' === $family || empty( $weights ) ) {
			continue;
		}

		foreach ( $weights as $weight => $filename ) {
			$file_path = $dir_base . $filename;
			if ( ! file_exists( $file_path ) ) {
				continue;
			}
			$ext     = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$format  = 'woff2' === $ext ? 'woff2' : ( 'woff' === $ext ? 'woff' : ( 'ttf' === $ext ? 'truetype' : 'opentype' ) );
			$face_rules .= sprintf(
				"@font-face{font-family:'%s';font-style:normal;font-weight:%d;font-display:swap;src:url('%s') format('%s');}\n",
				str_replace( "'", '', $family ),
				(int) $weight,
				esc_url( $url_base . $filename ),
				$format
			);
		}
		$root_rules .= sprintf( "--%s:'%s',%s;", $var, str_replace( "'", '', $family ), ekwa_fonts_fallback_stack( $fallback ) );
	}
	if ( '' === $face_rules && '' === $root_rules ) {
		return '';
	}
	return $face_rules . ":root{" . $root_rules . "}";
}

/**
 * Print the font CSS in <head> on the frontend.
 */
function ekwa_fonts_print_head() {
	$css = ekwa_fonts_build_css();
	if ( '' === $css ) {
		return;
	}
	echo "<style id=\"ekwa-custom-fonts\">{$css}</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'ekwa_fonts_print_head', 5 );

/**
 * Preload critical self-hosted fonts so the browser fetches them early instead
 * of discovering them inside the inline @font-face block (cuts FOUT / late LCP).
 *
 * Opt-in via the Performance settings tab. Emits one tag per configured font for
 * its 400 weight (falling back to the first available weight) so it never
 * over-preloads — typical sites run one or two families.
 */
function ekwa_fonts_print_preloads() {
	if ( is_admin() || ! get_option( 'ekwa_perf_preload_fonts', 0 ) ) {
		return;
	}
	$fonts = ekwa_fonts_get_all();
	if ( empty( $fonts ) ) {
		return;
	}
	$url_base = ekwa_fonts_dir_url();
	$dir_base = ekwa_fonts_dir_path();

	foreach ( $fonts as $font ) {
		$weights = ( isset( $font['weights'] ) && is_array( $font['weights'] ) ) ? $font['weights'] : array();
		if ( empty( $weights ) ) {
			continue;
		}

		// Prefer the 400 (regular) weight; otherwise the first available one.
		$filename = isset( $weights[400] ) ? $weights[400] : reset( $weights );
		if ( ! $filename || ! file_exists( $dir_base . $filename ) ) {
			continue;
		}

		$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$type = 'woff2' === $ext ? 'font/woff2' : ( 'woff' === $ext ? 'font/woff' : ( 'ttf' === $ext ? 'font/ttf' : 'font/otf' ) );

		printf(
			"<link rel=\"preload\" href=\"%s\" as=\"font\" type=\"%s\" crossorigin>\n",
			esc_url( $url_base . $filename ),
			esc_attr( $type )
		);
	}
}
add_action( 'wp_head', 'ekwa_fonts_print_preloads', 4 );

/**
 * Make the same CSS available inside the block editor iframe so designers
 * see the custom variables when authoring.
 */
function ekwa_fonts_editor_assets() {
	$css = ekwa_fonts_build_css();
	if ( '' === $css ) {
		return;
	}
	wp_register_style( 'ekwa-fonts-inline', false, array(), wp_get_theme()->get( 'Version' ) );
	wp_enqueue_style( 'ekwa-fonts-inline' );
	wp_add_inline_style( 'ekwa-fonts-inline', $css );
}
add_action( 'enqueue_block_editor_assets', 'ekwa_fonts_editor_assets' );
add_action( 'enqueue_block_assets', 'ekwa_fonts_editor_assets' );

/* ------------------------------------------------------------------------
 * theme.json: register user fonts as font families so they appear in the
 * block editor's Typography font-family picker.
 * ------------------------------------------------------------------------ */

function ekwa_fonts_filter_theme_json( $theme_json ) {
	$fonts = ekwa_fonts_get_all();
	if ( empty( $fonts ) ) {
		return $theme_json;
	}

	$current   = $theme_json->get_data();
	// In the theme.json data filter, presets are origin-nested: the theme's own
	// font families live under the 'theme' key. Reading the bare 'fontFamilies'
	// would return the origin map and clobber the theme families on merge.
	$existing  = $current['settings']['typography']['fontFamilies']['theme'] ?? array();
	$url_base  = ekwa_fonts_dir_url();
	$dir_base  = ekwa_fonts_dir_path();

	$new_entries = array();
	foreach ( $fonts as $font ) {
		$family   = (string) ( $font['family']   ?? '' );
		$fallback = ekwa_fonts_sanitize_fallback( $font['fallback'] ?? 'sans-serif' );
		$weights  = ( isset( $font['weights'] ) && is_array( $font['weights'] ) ) ? $font['weights'] : array();
		if ( '' === $family || empty( $weights ) ) {
			continue;
		}
		$slug = sanitize_title( $family );

		$face_entries = array();
		foreach ( $weights as $weight => $filename ) {
			if ( ! file_exists( $dir_base . $filename ) ) { continue; }
			$face_entries[] = array(
				'fontFamily'  => $family,
				'fontStyle'   => 'normal',
				'fontWeight'  => (string) (int) $weight,
				'fontDisplay' => 'swap',
				'src'         => array( $url_base . $filename ),
			);
		}
		if ( empty( $face_entries ) ) { continue; }

		$new_entries[] = array(
			'slug'       => $slug,
			'name'       => $family,
			'fontFamily' => sprintf( "'%s', %s", str_replace( "'", '', $family ), ekwa_fonts_fallback_stack( $fallback ) ),
			'fontFace'   => $face_entries,
		);
	}

	if ( empty( $new_entries ) ) {
		return $theme_json;
	}

	// Append our fonts, but let an existing theme family with the same slug win
	// (and guard against duplicates if the filter runs more than once).
	$existing_slugs = array();
	foreach ( $existing as $entry ) {
		if ( isset( $entry['slug'] ) ) {
			$existing_slugs[ $entry['slug'] ] = true;
		}
	}
	$merged = $existing;
	foreach ( $new_entries as $entry ) {
		if ( isset( $existing_slugs[ $entry['slug'] ] ) ) {
			continue;
		}
		$merged[] = $entry;
	}

	return $theme_json->update_with( array(
		'version'  => $current['version'] ?? 3,
		'settings' => array(
			'typography' => array(
				'fontFamilies' => $merged,
			),
		),
	) );
}
add_filter( 'wp_theme_json_data_theme', 'ekwa_fonts_filter_theme_json' );
