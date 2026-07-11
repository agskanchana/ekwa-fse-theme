<?php
/**
 * Starter child-theme generator.
 *
 * The whole Ekwa workflow assumes an active child theme that owns the site's
 * content/CSS while the parent owns behavior — but nothing scaffolded it, and
 * the handle/path contract (ekwa-child-style, ekwa-child-js,
 * assets/css/critical.css, assets/js/delayed-scripts.js) lived only in parent
 * source. This builds a ready-to-activate child theme zip, pre-wired to that
 * contract, from Ekwa Settings.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the "Starter child theme" settings card. Call inside the settings
 * form area (it emits its own nonced sub-form via formaction).
 */
function ekwa_child_generator_card() {
	// Default to the PARENT theme's name (e.g. "Ekwa Child"), not the site
	// title. This card lives inside the main settings <form>, so the download
	// must be a plain link — a nested <form> is invalid HTML (the browser drops
	// it and the button submits the settings form instead), and submitting the
	// settings form would also trip ekwa_save_settings() on admin_init.
	$parent      = wp_get_theme( get_template() );
	$parent_name = $parent->get( 'Name' ) ? $parent->get( 'Name' ) : 'Ekwa';
	$default_name = $parent_name . ' Child';
	$is_child     = get_template_directory() !== get_stylesheet_directory();

	$dl_url = wp_nonce_url(
		add_query_arg(
			array(
				'action'          => 'ekwa_generate_child',
				'ekwa_child_name' => rawurlencode( $default_name ),
			),
			admin_url( 'admin-post.php' )
		),
		'ekwa_generate_child'
	);
	?>
	<div class="ekwa-section">
		<h2><?php esc_html_e( 'Starter child theme', 'ekwa' ); ?></h2>
		<p class="description" style="margin-bottom:1em;">
			<?php esc_html_e( 'Download a ready-to-activate child theme, pre-wired to the Ekwa contract: the ekwa-child-style / ekwa-child-js handles (so the parent can inline them), plus empty assets/css/critical.css and assets/js/delayed-scripts.js. Put your site\'s CSS in the child\'s style.css.', 'ekwa' ); ?>
		</p>
		<?php if ( $is_child ) : ?>
			<p><em><?php esc_html_e( 'A child theme is already active. Generating a new one is only needed for a fresh site.', 'ekwa' ); ?></em></p>
		<?php endif; ?>
		<table class="form-table">
			<tr>
				<th><label for="ekwa_child_name"><?php esc_html_e( 'Child theme name', 'ekwa' ); ?></label></th>
				<td>
					<input type="text" id="ekwa_child_name" value="<?php echo esc_attr( $default_name ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Requires the PHP Zip extension.', 'ekwa' ); ?></p>
				</td>
			</tr>
		</table>
		<a id="ekwa-child-dl" href="<?php echo esc_url( $dl_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Download child theme (.zip)', 'ekwa' ); ?></a>
		<script>
		( function () {
			var input = document.getElementById( 'ekwa_child_name' );
			var link  = document.getElementById( 'ekwa-child-dl' );
			if ( ! input || ! link ) { return; }
			input.addEventListener( 'input', function () {
				var u = new URL( link.href, window.location.origin );
				u.searchParams.set( 'ekwa_child_name', input.value || 'Ekwa Child' );
				link.href = u.toString();
			} );
		} )();
		</script>
	</div>
	<?php
}

add_action( 'admin_post_ekwa_generate_child', 'ekwa_child_generator_download' );

/**
 * Build the child theme zip and stream it as a download.
 */
function ekwa_child_generator_download() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'ekwa' ) );
	}
	check_admin_referer( 'ekwa_generate_child' );

	// The name arrives via the download link (GET); rawurldecode handles the
	// encoded default. Fall back to the parent theme name + " Child".
	$name = isset( $_REQUEST['ekwa_child_name'] )
		? sanitize_text_field( rawurldecode( wp_unslash( $_REQUEST['ekwa_child_name'] ) ) )
		: ( wp_get_theme( get_template() )->get( 'Name' ) . ' Child' );
	if ( '' === trim( $name ) ) {
		$name = 'Ekwa Child';
	}
	$slug  = sanitize_title( $name );
	if ( '' === $slug ) {
		$slug = 'ekwa-child';
	}
	// Parent DIRECTORY name — this is what the child's "Template:" must point at.
	$parent_dir = basename( get_template_directory() );

	$files = ekwa_child_generator_files( $name, $slug, $parent_dir );

	// Prefix every entry with the theme folder so the zip extracts to slug/.
	$entries = array();
	foreach ( $files as $rel_path => $contents ) {
		$entries[ $slug . '/' . $rel_path ] = $contents;
	}

	// Prefer the Zip extension when present (deflate); otherwise fall back to a
	// dependency-free pure-PHP writer (store method) so the download works on
	// servers without ext/zip.
	if ( class_exists( 'ZipArchive' ) ) {
		$tmp = wp_tempnam( $slug . '.zip' );
		$zip = new ZipArchive();
		if ( true === $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			foreach ( $entries as $path => $contents ) {
				$zip->addFromString( $path, $contents );
			}
			$zip->close();
			$bytes = file_get_contents( $tmp );
			@unlink( $tmp ); // phpcs:ignore
		} else {
			$bytes = ekwa_child_zip_store( $entries );
		}
	} else {
		$bytes = ekwa_child_zip_store( $entries );
	}

	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="' . $slug . '.zip"' );
	header( 'Content-Length: ' . strlen( $bytes ) );
	echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

/**
 * Build a ZIP archive in pure PHP using the "store" (no compression) method.
 * No ext/zip required — correct for the small text files this generator emits.
 *
 * @param array<string,string> $entries Map of in-zip path => contents.
 * @return string Raw ZIP bytes.
 */
function ekwa_child_zip_store( $entries ) {
	// DOS-format time/date (local time), shared by every entry.
	$t    = getdate();
	$time = ( $t['hours'] << 11 ) | ( $t['minutes'] << 5 ) | ( (int) ( $t['seconds'] / 2 ) );
	$date = ( ( $t['year'] - 1980 ) << 9 ) | ( $t['mon'] << 5 ) | $t['mday'];
	$time &= 0xffff;
	$date &= 0xffff;

	$local   = '';
	$central = '';
	$offset  = 0;
	$count   = 0;

	foreach ( $entries as $path => $data ) {
		$crc      = crc32( $data );
		$len      = strlen( $data );
		$path_len = strlen( $path );

		$lfh = pack( 'V', 0x04034b50 ) // Local file header signature.
			. pack( 'v', 20 )          // Version needed.
			. pack( 'v', 0 )           // Flags.
			. pack( 'v', 0 )           // Compression method: store.
			. pack( 'v', $time )
			. pack( 'v', $date )
			. pack( 'V', $crc )
			. pack( 'V', $len )        // Compressed size.
			. pack( 'V', $len )        // Uncompressed size.
			. pack( 'v', $path_len )
			. pack( 'v', 0 )           // Extra field length.
			. $path;
		$local .= $lfh . $data;

		$central .= pack( 'V', 0x02014b50 ) // Central directory header signature.
			. pack( 'v', 20 )          // Version made by.
			. pack( 'v', 20 )          // Version needed.
			. pack( 'v', 0 )           // Flags.
			. pack( 'v', 0 )           // Compression method.
			. pack( 'v', $time )
			. pack( 'v', $date )
			. pack( 'V', $crc )
			. pack( 'V', $len )
			. pack( 'V', $len )
			. pack( 'v', $path_len )
			. pack( 'v', 0 )           // Extra length.
			. pack( 'v', 0 )           // Comment length.
			. pack( 'v', 0 )           // Disk number start.
			. pack( 'v', 0 )           // Internal attributes.
			. pack( 'V', 32 )          // External attributes (archive bit).
			. pack( 'V', $offset )     // Offset of local header.
			. $path;

		$offset += strlen( $lfh ) + $len;
		$count++;
	}

	$eocd = pack( 'V', 0x06054b50 ) // End of central directory signature.
		. pack( 'v', 0 )           // Disk number.
		. pack( 'v', 0 )           // Disk with central directory.
		. pack( 'v', $count )      // Entries on this disk.
		. pack( 'v', $count )      // Total entries.
		. pack( 'V', strlen( $central ) )
		. pack( 'V', strlen( $local ) )
		. pack( 'v', 0 );          // Comment length.

	return $local . $central . $eocd;
}

/**
 * The child theme's file map (relative path => contents).
 *
 * @param string $name       Human theme name.
 * @param string $slug       Theme slug / text domain.
 * @param string $parent_dir Parent theme directory name (the Template value).
 * @return array<string,string>
 */
function ekwa_child_generator_files( $name, $slug, $parent_dir ) {
	$year = gmdate( 'Y' );

	$style = "/*\n"
		. "Theme Name: {$name}\n"
		. "Template: {$parent_dir}\n"
		. "Description: Child theme for the Ekwa theme. Put this site's CSS here.\n"
		. "Version: 1.0.0\n"
		. "Text Domain: {$slug}\n"
		. "*/\n\n"
		. "/* ------------------------------------------------------------------\n"
		. "   Paste this site's CSS below (e.g. the extracted mockup styles).\n"
		. "   Ekwa can inline this file in the <head> — Ekwa Settings →\n"
		. "   Performance → \"Inline child theme CSS\".\n"
		. "   ------------------------------------------------------------------ */\n";

	$functions = "<?php\n"
		. "/**\n"
		. " * {$name} — functions.\n"
		. " *\n"
		. " * Enqueues the child stylesheet and script under the handles the Ekwa\n"
		. " * parent expects (ekwa-child-style / ekwa-child-js) so it can optionally\n"
		. " * inline them. Do NOT rename these handles.\n"
		. " */\n\n"
		. "if ( ! defined( 'ABSPATH' ) ) { exit; }\n\n"
		. "add_action( 'wp_enqueue_scripts', function () {\n"
		. "\t// Child stylesheet — handle MUST be 'ekwa-child-style'.\n"
		. "\twp_enqueue_style(\n"
		. "\t\t'ekwa-child-style',\n"
		. "\t\tget_stylesheet_uri(),\n"
		. "\t\tarray(),\n"
		. "\t\tfilemtime( get_stylesheet_directory() . '/style.css' )\n"
		. "\t);\n\n"
		. "\t// Child script — handle MUST be 'ekwa-child-js'. Optional.\n"
		. "\t\$child_js = get_stylesheet_directory() . '/assets/js/ekwa-child.js';\n"
		. "\tif ( file_exists( \$child_js ) ) {\n"
		. "\t\twp_enqueue_script(\n"
		. "\t\t\t'ekwa-child-js',\n"
		. "\t\t\tget_stylesheet_directory_uri() . '/assets/js/ekwa-child.js',\n"
		. "\t\t\tarray(),\n"
		. "\t\t\tfilemtime( \$child_js ),\n"
		. "\t\t\ttrue\n"
		. "\t\t);\n"
		. "\t}\n"
		. "}, 20 );\n";

	$critical = "/* Above-the-fold critical CSS for this site.\n"
		. "   Ekwa inlines this in the <head> when Ekwa Settings → Performance →\n"
		. "   \"Critical CSS\" is enabled. Keep it small (only what paints first). */\n";

	$delayed = "/* delayed-scripts.js\n"
		. "   Ekwa loads this file only on the FIRST user interaction (scroll, move,\n"
		. "   touch, key, click). Put non-critical third-party / analytics JS here so\n"
		. "   it never blocks the initial render. Leave empty to ship nothing. */\n";

	$childjs = "/* ekwa-child.js — this site's own JavaScript (loaded in the footer).\n"
		. "   Ekwa can inline it before </body> (Ekwa Settings → Performance →\n"
		. "   \"Inline child theme JS\"). */\n";

	$readme = "={$name}=\n\n"
		. "Child theme for the Ekwa parent theme.\n\n"
		. "The Ekwa parent owns behavior (blocks, performance, tooling); this child\n"
		. "owns this site's content and CSS. The contract the parent relies on:\n\n"
		. "  style.css                     -> handle 'ekwa-child-style'\n"
		. "  assets/js/ekwa-child.js       -> handle 'ekwa-child-js'\n"
		. "  assets/css/critical.css       -> read for the Critical CSS option\n"
		. "  assets/js/delayed-scripts.js  -> loaded on first interaction\n\n"
		. "Do not rename the two handles. Activate this theme (Appearance → Themes)\n"
		. "after uploading. Requires the '{$parent_dir}' parent theme to be installed.\n";

	return array(
		'style.css'                    => $style,
		'functions.php'                => $functions,
		'assets/css/critical.css'      => $critical,
		'assets/js/delayed-scripts.js' => $delayed,
		'assets/js/ekwa-child.js'      => $childjs,
		'readme.txt'                   => $readme,
	);
}
