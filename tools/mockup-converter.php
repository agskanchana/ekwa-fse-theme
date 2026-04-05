#!/usr/bin/env php
<?php
/**
 * Mockup HTML to WordPress Block Markup Converter (CLI).
 *
 * Thin CLI wrapper around the shared converter library in inc/ekwa-converter-lib.php.
 *
 * Usage:
 *   php mockup-converter.php input.html
 *   php mockup-converter.php input.html --output=output.html
 *   php mockup-converter.php input.html --manifest=/path/to/manifest.json
 *   php mockup-converter.php input.html --extract-body
 *   php mockup-converter.php input.html --extract-css
 *
 * @package ekwa
 */

if ( PHP_SAPI !== 'cli' ) {
	die( 'This script must be run from the command line.' );
}

// Load the shared conversion engine.
require_once __DIR__ . '/../inc/ekwa-converter-lib.php';

// ─── CLI Arguments ──────────────────────────────────────────────────────────

$args = parse_cli_args( $argv );

if ( empty( $args['input'] ) ) {
	fwrite( STDERR, "Usage: php mockup-converter.php <input.html> [options]\n" );
	fwrite( STDERR, "\nOptions:\n" );
	fwrite( STDERR, "  --output=<file>      Write output to file instead of stdout\n" );
	fwrite( STDERR, "  --manifest=<file>    Path to media manifest JSON\n" );
	fwrite( STDERR, "  --extract-body       Only convert content inside <body>\n" );
	fwrite( STDERR, "  --extract-css        Extract custom CSS from <style> tags\n" );
	fwrite( STDERR, "  --no-detect-dynamic  Skip dynamic data detection (phone, hours, social)\n" );
	exit( 1 );
}

$input_file = $args['input'];
if ( ! file_exists( $input_file ) ) {
	fwrite( STDERR, "Error: File not found: $input_file\n" );
	exit( 1 );
}

$html = file_get_contents( $input_file );

// ─── Media Manifest ─────────────────────────────────────────────────────────

$manifest_data = null;

$manifest_path = $args['manifest'] ?? auto_detect_manifest( $input_file );
if ( $manifest_path && file_exists( $manifest_path ) ) {
	$manifest_data = json_decode( file_get_contents( $manifest_path ), true );
	if ( $manifest_data && ! empty( $manifest_data['media'] ) ) {
		fwrite( STDERR, "Loaded manifest: " . count( $manifest_data['media'] ) . " media items\n" );
	}
} else {
	fwrite( STDERR, "Warning: No media manifest found. Image src values will not be resolved.\n" );
}

// ─── CSS Extraction Mode ────────────────────────────────────────────────────

if ( ! empty( $args['extract-css'] ) ) {
	echo ekwa_mc_extract_css( $html );
	exit( 0 );
}

// ─── Convert ────────────────────────────────────────────────────────────────

$options = array(
	'detect_dynamic' => empty( $args['no-detect-dynamic'] ),
);

$result = ekwa_mc_convert_html( $html, $manifest_data, $options );

// Print warnings to stderr.
foreach ( $result['warnings'] as $warning ) {
	fwrite( STDERR, "Warning: $warning\n" );
}

if ( ! empty( $args['output'] ) ) {
	file_put_contents( $args['output'], $result['markup'] );
	fwrite( STDERR, "Written to: " . $args['output'] . "\n" );
} else {
	echo $result['markup'];
}

exit( 0 );

// ═══════════════════════════════════════════════════════════════════════════════
// CLI-ONLY FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Parse CLI arguments.
 */
function parse_cli_args( $argv ) {
	$result = array( 'input' => '' );
	for ( $i = 1; $i < count( $argv ); $i++ ) {
		$arg = $argv[ $i ];
		if ( strpos( $arg, '--' ) === 0 ) {
			if ( strpos( $arg, '=' ) !== false ) {
				list( $key, $val ) = explode( '=', substr( $arg, 2 ), 2 );
				$result[ $key ] = $val;
			} else {
				$result[ substr( $arg, 2 ) ] = true;
			}
		} elseif ( empty( $result['input'] ) ) {
			$result['input'] = $arg;
		}
	}
	return $result;
}

/**
 * Auto-detect the media manifest file by walking up from the input file.
 */
function auto_detect_manifest( $input_file ) {
	$dir = dirname( realpath( $input_file ) );
	for ( $i = 0; $i < 10; $i++ ) {
		$candidate = $dir . '/wp-content/uploads/ekwa-media-manifest.json';
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}
		$parent = dirname( $dir );
		if ( $parent === $dir ) break;
		$dir = $parent;
	}
	return null;
}
