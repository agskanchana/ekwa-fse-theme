<?php
/**
 * Editable front-end JS files (Ekwa Settings → Design Setup).
 *
 * Lets an admin edit the active theme's two front-end JS files straight from the
 * Design Setup tab, in a CodeMirror editor:
 *
 *   - assets/js/delayed-scripts.js — loaded once, on the first user interaction
 *     (see inc/ekwa-delayed-scripts.php). Non-critical third-party / analytics JS.
 *   - assets/js/ekwa-child.js      — this site's own footer JS (optionally
 *     inlined before </body>; see inc/ekwa-inline-child.php).
 *
 * Both files live in the ACTIVE (stylesheet) theme — the exact paths their
 * loaders read — so what you edit here is what ships. Content is written to disk
 * with WP_Filesystem. A WAF-safe base64 mirror of each field bypasses
 * mod_security rules that strip raw <script>/JS from POST bodies (the same trick
 * the Related Posts template and SVG logo fields use).
 *
 * The render/save entry points are called from the Design Setup tab
 * (ekwa_tokens_render_tab() / ekwa_tokens_save_settings() in inc/ekwa-tokens.php)
 * so the save runs inside the main settings form's nonce + capability checks.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The editable JS files: key => metadata. `rel` is relative to the active
 * (stylesheet) theme directory; `field` is the POST/textarea name (its base64
 * WAF-safe mirror is `field . '_b64'`).
 *
 * @return array<string,array{rel:string,field:string,label:string,desc:string}>
 */
function ekwa_js_editor_files() {
	return array(
		'delayed' => array(
			'rel'   => 'assets/js/delayed-scripts.js',
			'field' => 'ekwa_delayed_js',
			'label' => 'delayed-scripts.js',
			'desc'  => __( 'Loaded once, on the visitor\'s first interaction (scroll, move, tap, key, click) — so it never blocks the initial render. Put non-critical third-party / analytics JS here. Leave empty to ship nothing.', 'ekwa' ),
		),
		'child'   => array(
			'rel'   => 'assets/js/ekwa-child.js',
			'field' => 'ekwa_child_js',
			'label' => 'ekwa-child.js',
			'desc'  => __( 'This site\'s own JavaScript, loaded in the footer (and optionally inlined before </body> via Performance → “Inline child theme JS”).', 'ekwa' ),
		),
	);
}

/**
 * Absolute path to an editable JS file in the active theme.
 *
 * @param string $key File key (see ekwa_js_editor_files()).
 * @return string Absolute path, or '' for an unknown key.
 */
function ekwa_js_editor_path( $key ) {
	$files = ekwa_js_editor_files();
	if ( ! isset( $files[ $key ] ) ) {
		return '';
	}
	return get_stylesheet_directory() . '/' . $files[ $key ]['rel'];
}

/**
 * Current contents of an editable JS file ('' when it doesn't exist yet).
 *
 * @param string $key File key.
 * @return string
 */
function ekwa_js_editor_read( $key ) {
	$path = ekwa_js_editor_path( $key );
	if ( '' === $path || ! is_readable( $path ) ) {
		return '';
	}
	return (string) file_get_contents( $path );
}

/**
 * Whether writing theme files from the admin is allowed at all. Respects the
 * standard lockdown constants an admin may set to forbid in-dashboard file
 * edits (DISALLOW_FILE_EDIT) or any file changes (DISALLOW_FILE_MODS).
 *
 * @return bool
 */
function ekwa_js_editor_file_mods_allowed() {
	if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
		return false;
	}
	if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
		return false;
	}
	return true;
}

/**
 * Can these files be written from here? True when file mods are allowed AND the
 * active theme's assets/js folder (or, if it doesn't exist yet, the theme root
 * that would hold it) is writable by PHP.
 *
 * @return bool
 */
function ekwa_js_editor_writable() {
	if ( ! ekwa_js_editor_file_mods_allowed() ) {
		return false;
	}
	$dir = get_stylesheet_directory() . '/assets/js';
	if ( is_dir( $dir ) ) {
		return wp_is_writable( $dir );
	}
	return wp_is_writable( get_stylesheet_directory() );
}

/**
 * Write one editable JS file (creating assets/js when missing).
 *
 * @param string $key     File key.
 * @param string $content Raw JS.
 * @return true|WP_Error
 */
function ekwa_js_editor_write( $key, $content ) {
	$path = ekwa_js_editor_path( $key );
	if ( '' === $path ) {
		return new WP_Error( 'ekwa_js_bad_key', __( 'Unknown file.', 'ekwa' ) );
	}

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	// No credential prompt: uses the "direct" method when the theme folder is
	// writable (the common case). Returns false when it can't write directly.
	if ( ! WP_Filesystem() ) {
		return new WP_Error( 'ekwa_js_fs', __( 'Could not access the filesystem to write the file.', 'ekwa' ) );
	}
	global $wp_filesystem;

	$dir = dirname( $path );
	if ( ! $wp_filesystem->is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'ekwa_js_mkdir', __( 'Could not create the assets/js folder in the active theme.', 'ekwa' ) );
	}
	if ( ! $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE ) ) {
		return new WP_Error( 'ekwa_js_write', __( 'Could not write the file — the active theme folder may be read-only.', 'ekwa' ) );
	}
	return true;
}

/**
 * Save handler — called from ekwa_tokens_save_settings() (inside the main
 * settings form's nonce + capability checks). Prefers the WAF-safe base64
 * mirror, falls back to the raw textarea, and only writes when the content
 * actually changed (so unrelated settings saves don't cache-bust the files).
 */
function ekwa_js_editor_save() {
	// Respect an explicit file-editing lockdown — never write theme files then.
	if ( ! ekwa_js_editor_file_mods_allowed() ) {
		return;
	}
	foreach ( ekwa_js_editor_files() as $key => $meta ) {
		$field = $meta['field'];
		$b64   = $field . '_b64';

		$content = null;
		if ( isset( $_POST[ $b64 ] ) && '' !== $_POST[ $b64 ] ) {
			$decoded = base64_decode( wp_unslash( $_POST[ $b64 ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( false !== $decoded ) {
				$content = $decoded;
			}
		}
		if ( null === $content && isset( $_POST[ $field ] ) ) {
			$content = (string) wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( null === $content ) {
			continue; // Field not submitted at all — leave the file untouched.
		}

		// Normalize line endings so diffs/filemtime stay clean.
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );

		// Skip the write when nothing changed — avoids needless filemtime churn
		// (which would cache-bust the file) on every unrelated settings save.
		if ( ekwa_js_editor_read( $key ) === $content ) {
			continue;
		}

		$result = ekwa_js_editor_write( $key, $content );
		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'ekwa_settings',
				'ekwa_js_' . $key,
				sprintf(
					/* translators: 1: file name, 2: error detail. */
					__( 'Couldn\'t save %1$s: %2$s', 'ekwa' ),
					$meta['label'],
					$result->get_error_message()
				),
				'error'
			);
		}
	}
}

/**
 * Render the "Front-end JavaScript" section inside the Design Setup tab.
 * Each file gets a textarea (upgraded to CodeMirror by assets/js/ekwa-admin.js)
 * plus its hidden base64 mirror.
 */
function ekwa_js_editor_render() {
	$is_child = get_template_directory() !== get_stylesheet_directory();
	$writable = ekwa_js_editor_writable();
	?>
	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Front-end JavaScript', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Edit the active theme\'s two front-end JS files directly. Changes are written to disk and cache-busted automatically on save.', 'ekwa' ); ?>
		</p>

		<?php if ( ! $is_child ) : ?>
			<p><em><?php esc_html_e( 'No child theme is active — these edits write to the active theme and would be lost on a theme update. Activate a child theme (General tab → “Starter child theme”) to keep them.', 'ekwa' ); ?></em></p>
		<?php endif; ?>

		<?php if ( ! $writable ) : ?>
			<div class="notice notice-warning inline" style="margin:.5em 0;">
				<p>
					<?php
					if ( ! ekwa_js_editor_file_mods_allowed() ) {
						esc_html_e( 'In-dashboard file editing is disabled on this site (DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS), so these files are read-only here.', 'ekwa' );
					} else {
						esc_html_e( 'The active theme\'s assets/js folder is not writable, so these files can\'t be saved from here. Make it writable or edit the files over SFTP. The editors below are read-only.', 'ekwa' );
					}
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php
		foreach ( ekwa_js_editor_files() as $key => $meta ) :
			$path    = ekwa_js_editor_path( $key );
			$exists  = '' !== $path && file_exists( $path );
			$content = ekwa_js_editor_read( $key );
			?>
			<div class="ekwa-js-file" style="margin-top:1.5em;">
				<h3 style="margin-bottom:.2em;">
					<code><?php echo esc_html( $meta['label'] ); ?></code>
					<?php if ( ! $exists ) : ?>
						<span class="description">(<?php esc_html_e( 'will be created on save', 'ekwa' ); ?>)</span>
					<?php endif; ?>
				</h3>
				<p class="description" style="margin:.2em 0 .6em;"><?php echo esc_html( $meta['desc'] ); ?></p>
				<textarea
					name="<?php echo esc_attr( $meta['field'] ); ?>"
					class="large-text code ekwa-code-js"
					data-ekwa-js-key="<?php echo esc_attr( $key ); ?>"
					rows="10" spellcheck="false"
					<?php disabled( ! $writable ); ?>><?php echo esc_textarea( $content ); ?></textarea>
				<input type="hidden" name="<?php echo esc_attr( $meta['field'] ); ?>_b64" value="" />
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}
