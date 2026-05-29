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
 * Sanitize a fallback string (e.g. "serif", "sans-serif"). Falls back to
 * 'sans-serif' when invalid.
 */
function ekwa_fonts_sanitize_fallback( $raw ) {
	$allowed = array( 'sans-serif', 'serif', 'monospace', 'cursive', 'system-ui' );
	$raw     = strtolower( trim( (string) $raw ) );
	return in_array( $raw, $allowed, true ) ? $raw : 'sans-serif';
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

		<div class="ekwa-fonts-actions" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
			<button type="button" class="button button-secondary" id="ekwa-fonts-add-google">
				<?php esc_html_e( '+ Add Google Font', 'ekwa' ); ?>
			</button>
			<button type="button" class="button button-secondary" id="ekwa-fonts-add-upload">
				<?php esc_html_e( '+ Upload Font File', 'ekwa' ); ?>
			</button>
		</div>
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
							<option value="sans-serif">sans-serif</option>
							<option value="serif">serif</option>
							<option value="monospace">monospace</option>
							<option value="cursive">cursive</option>
							<option value="system-ui">system-ui</option>
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
							<option value="sans-serif">sans-serif</option>
							<option value="serif">serif</option>
							<option value="monospace">monospace</option>
							<option value="cursive">cursive</option>
							<option value="system-ui">system-ui</option>
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
	</style>
	<?php
}

/**
 * Render a single saved-font row.
 */
function ekwa_fonts_render_row( $id, $font ) {
	$var_name = ekwa_fonts_sanitize_var_name( $font['var_name'] ?? '' );
	$family   = (string) ( $font['family']   ?? '' );
	$fallback = (string) ( $font['fallback'] ?? 'sans-serif' );
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

	$saved = array();
	foreach ( $blocks[0] as $block ) {
		if ( ! preg_match( '/font-weight:\s*(\d+)/i', $block, $wm ) ) { continue; }
		if ( ! preg_match( '/src:\s*url\((https:\/\/fonts\.gstatic\.com\/[^)]+\.woff2)\)/i', $block, $sm ) ) { continue; }
		$weight = $wm[1];
		$src    = $sm[1];

		if ( ! in_array( $weight, $weights, true ) ) {
			continue;
		}
		// Already saved this weight? Skip duplicate (Google may return Latin & latin-ext).
		if ( isset( $saved[ $weight ] ) ) {
			continue;
		}

		$bin = wp_remote_get( $src, array( 'timeout' => 30 ) );
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
		$root_rules .= sprintf( "--%s:'%s',%s;", $var, str_replace( "'", '', $family ), $fallback );
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
			'fontFamily' => sprintf( "'%s', %s", str_replace( "'", '', $family ), $fallback ),
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
