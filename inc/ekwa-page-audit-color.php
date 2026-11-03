<?php
/**
 * Page audit — colour choices that are guaranteed to pass contrast.
 *
 * A colour fix is only right if the new colour has enough contrast against the
 * background the element really sits on. The model's own "against" colour can
 * be wrong (a breadcrumb on a dark banner fixed against white, say), which made
 * every re-analysis "fix" the previous fix. So the background is not taken from
 * the model: Lighthouse's color-contrast audit states, for every failing
 * element, its foreground, background and font size — those are matched to the
 * elements the fix targets, and every colour offered is computed to pass all of
 * them:
 *
 *   - the site's existing colour tokens that pass, closest to the current
 *     colour first (no new token, the design's own palette);
 *   - a shade of the current colour — same hue, lightness moved only as far as
 *     needed — as a new token (or a plain value in Section CSS);
 *   - the model's own suggestion, only if it passes.
 *
 * The person picks one in the sidebar (the closest passing one is preselected);
 * Apply, Compare and the change log all use the chosen option, read back from
 * the stored analysis — never from the request.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// COLOUR MATHS (WCAG 2.x)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * @param string $hex #rrggbb
 * @return int[]|null [r, g, b]
 */
function ekwa_pa_rgb( $hex ) {
	$hex = ekwa_page_audit_hex( $hex );
	if ( '' === $hex ) {
		return null;
	}
	return array( hexdec( substr( $hex, 1, 2 ) ), hexdec( substr( $hex, 3, 2 ) ), hexdec( substr( $hex, 5, 2 ) ) );
}

/**
 * @param int[] $rgb
 * @return string
 */
function ekwa_pa_hex_from_rgb( $rgb ) {
	return sprintf( '#%02x%02x%02x', max( 0, min( 255, (int) round( $rgb[0] ) ) ), max( 0, min( 255, (int) round( $rgb[1] ) ) ), max( 0, min( 255, (int) round( $rgb[2] ) ) ) );
}

/**
 * Relative luminance.
 *
 * @param int[] $rgb
 * @return float
 */
function ekwa_pa_luminance( $rgb ) {
	$c = array_map(
		static function ( $v ) {
			$v /= 255;
			return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
		},
		$rgb
	);
	return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

/**
 * WCAG contrast ratio of two colours, or 0 when either is invalid.
 *
 * @param string $a
 * @param string $b
 * @return float
 */
function ekwa_pa_contrast( $a, $b ) {
	$x = ekwa_pa_rgb( $a );
	$y = ekwa_pa_rgb( $b );
	if ( ! $x || ! $y ) {
		return 0.0;
	}
	$l1 = ekwa_pa_luminance( $x );
	$l2 = ekwa_pa_luminance( $y );
	return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
}

/**
 * CIE76 colour difference (≈2 barely visible, 20+ reads as a different colour).
 *
 * @param string $a
 * @param string $b
 * @return float
 */
function ekwa_pa_delta_e( $a, $b ) {
	$x = ekwa_pa_rgb( $a );
	$y = ekwa_pa_rgb( $b );
	if ( ! $x || ! $y ) {
		return 0.0;
	}
	$lab = static function ( $rgb ) {
		$c = array_map(
			static function ( $v ) {
				$v /= 255;
				return $v <= 0.04045 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
			},
			$rgb
		);
		$xx = ( $c[0] * 0.4124 + $c[1] * 0.3576 + $c[2] * 0.1805 ) / 0.95047;
		$yy = $c[0] * 0.2126 + $c[1] * 0.7152 + $c[2] * 0.0722;
		$zz = ( $c[0] * 0.0193 + $c[1] * 0.1192 + $c[2] * 0.9505 ) / 1.08883;
		$f  = static function ( $t ) {
			return $t > 0.008856 ? pow( $t, 1 / 3 ) : ( 7.787 * $t ) + 16 / 116;
		};
		return array( 116 * $f( $yy ) - 16, 500 * ( $f( $xx ) - $f( $yy ) ), 200 * ( $f( $yy ) - $f( $zz ) ) );
	};
	$p = $lab( $x );
	$q = $lab( $y );
	return sqrt( pow( $p[0] - $q[0], 2 ) + pow( $p[1] - $q[1], 2 ) + pow( $p[2] - $q[2], 2 ) );
}

/**
 * RGB → HSL (h 0-360, s and l 0-1).
 *
 * @param int[] $rgb
 * @return float[]
 */
function ekwa_pa_hsl( $rgb ) {
	list( $r, $g, $b ) = array( $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255 );
	$max = max( $r, $g, $b );
	$min = min( $r, $g, $b );
	$l   = ( $max + $min ) / 2;
	if ( $max === $min ) {
		return array( 0.0, 0.0, $l );
	}
	$d = $max - $min;
	$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
	if ( $max === $r ) {
		$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
	} elseif ( $max === $g ) {
		$h = ( $b - $r ) / $d + 2;
	} else {
		$h = ( $r - $g ) / $d + 4;
	}
	return array( $h * 60, $s, $l );
}

/**
 * HSL → RGB.
 *
 * @param float $h
 * @param float $s
 * @param float $l
 * @return float[]
 */
function ekwa_pa_rgb_from_hsl( $h, $s, $l ) {
	$h /= 360;
	if ( 0.0 === (float) $s ) {
		return array( $l * 255, $l * 255, $l * 255 );
	}
	$q   = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
	$p   = 2 * $l - $q;
	$hue = static function ( $t ) use ( $p, $q ) {
		if ( $t < 0 ) {
			$t += 1;
		}
		if ( $t > 1 ) {
			$t -= 1;
		}
		if ( $t < 1 / 6 ) {
			return $p + ( $q - $p ) * 6 * $t;
		}
		if ( $t < 1 / 2 ) {
			return $q;
		}
		if ( $t < 2 / 3 ) {
			return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
		}
		return $p;
	};
	return array( $hue( $h + 1 / 3 ) * 255, $hue( $h ) * 255, $hue( $h - 1 / 3 ) * 255 );
}

/**
 * The same colour, lightness moved just far enough to reach $ratio against
 * every background (darker when the backgrounds are light, lighter when dark).
 *
 * @param string   $fg
 * @param string[] $bgs
 * @param float    $ratio
 * @return string '' when no lightness reaches it (mixed light and dark backgrounds).
 */
function ekwa_pa_shade_for( $fg, $bgs, $ratio ) {
	$rgb = ekwa_pa_rgb( $fg );
	if ( ! $rgb || ! $bgs ) {
		return '';
	}
	list( $h, $s, $l0 ) = ekwa_pa_hsl( $rgb );
	$min_lum = 1.0;
	$max_lum = 0.0;
	foreach ( $bgs as $bg ) {
		$c = ekwa_pa_rgb( $bg );
		if ( $c ) {
			$min_lum = min( $min_lum, ekwa_pa_luminance( $c ) );
			$max_lum = max( $max_lum, ekwa_pa_luminance( $c ) );
		}
	}
	$darker = ( $min_lum + $max_lum ) / 2 > 0.18;
	$passes = static function ( $hex ) use ( $bgs, $ratio ) {
		foreach ( $bgs as $bg ) {
			if ( ekwa_pa_contrast( $hex, $bg ) < $ratio ) {
				return false;
			}
		}
		return true;
	};
	for ( $step = 0; $step <= 100; $step++ ) {
		$l   = $darker ? $l0 - $step / 100 : $l0 + $step / 100;
		if ( $l < 0 || $l > 1 ) {
			break;
		}
		$hex = ekwa_pa_hex_from_rgb( ekwa_pa_rgb_from_hsl( $h, $s, $l ) );
		if ( $passes( $hex ) ) {
			return $hex;
		}
	}
	return '';
}

/**
 * Resolve a CSS colour to [r, g, b, alpha] through the site's tokens —
 * keeping the alpha, which matters: rgba(255,255,255,.74) on a dark banner is
 * a light grey, not white.
 *
 * @param string $value
 * @param array  $tokens
 * @param int    $depth
 * @return float[]|null
 */
function ekwa_pa_resolve_rgba( $value, $tokens, $depth = 0 ) {
	$v = strtolower( trim( preg_replace( '/!\s*important\s*$/i', '', (string) $value ) ) );
	if ( $depth > 6 || '' === $v ) {
		return null;
	}
	if ( preg_match( '/^var\(\s*(--[a-z0-9_-]+)\s*(?:,\s*(.+))?\)$/i', $v, $m ) ) {
		foreach ( $tokens as $name => $tv ) {
			if ( strtolower( $name ) === strtolower( $m[1] ) && '' !== (string) $tv ) {
				return ekwa_pa_resolve_rgba( $tv, $tokens, $depth + 1 );
			}
		}
		return isset( $m[2] ) ? ekwa_pa_resolve_rgba( $m[2], $tokens, $depth + 1 ) : null;
	}
	if ( 'transparent' === $v ) {
		return array( 0, 0, 0, 0.0 );
	}
	if ( preg_match( '/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/', $v, $m ) ) {
		$h = $m[1];
		if ( strlen( $h ) <= 4 ) {
			$h = preg_replace( '/(.)/', '$1$1', $h );
		}
		$a = 8 === strlen( $h ) ? hexdec( substr( $h, 6, 2 ) ) / 255 : 1.0;
		return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ), $a );
	}
	if ( preg_match( '/^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*(?:[,\/]\s*([\d.]+%?))?\s*\)$/', $v, $m ) ) {
		$a = isset( $m[4] ) && '' !== $m[4] ? ( '%' === substr( $m[4], -1 ) ? (float) $m[4] / 100 : (float) $m[4] ) : 1.0;
		return array( min( 255, (float) $m[1] ), min( 255, (float) $m[2] ), min( 255, (float) $m[3] ), max( 0, min( 1, $a ) ) );
	}
	$named = array( 'white' => array( 255, 255, 255, 1.0 ), 'black' => array( 0, 0, 0, 1.0 ) );
	return isset( $named[ $v ] ) ? $named[ $v ] : null;
}

/**
 * A colour with alpha, composited over a solid background → #rrggbb.
 *
 * @param float[] $rgba
 * @param string  $bg
 * @return string
 */
function ekwa_pa_over( $rgba, $bg ) {
	$b = ekwa_pa_rgb( $bg );
	if ( ! $b ) {
		$b = array( 255, 255, 255 );
	}
	$a = $rgba[3];
	return ekwa_pa_hex_from_rgb( array(
		$rgba[0] * $a + $b[0] * ( 1 - $a ),
		$rgba[1] * $a + $b[1] * ( 1 - $a ),
		$rgba[2] * $a + $b[2] * ( 1 - $a ),
	) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// WHAT LIGHTHOUSE MEASURED
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * The failing color-contrast elements from a stored report, parsed.
 *
 * Lighthouse explains each one as "… foreground color: #999999, background
 * color: #ffffff, font size: 12.0pt (16px), font weight: normal). Expected
 * contrast ratio of 4.5:1".
 *
 * @param array $report
 * @return array[] {selector, label, fg, bg, px, bold, required}
 */
function ekwa_page_audit_contrast_items( $report ) {
	$out = array();
	foreach ( isset( $report['audits']['accessibility'] ) && is_array( $report['audits']['accessibility'] ) ? $report['audits']['accessibility'] : array() as $audit ) {
		if ( ! isset( $audit['id'] ) || 'color-contrast' !== $audit['id'] || empty( $audit['items'] ) ) {
			continue;
		}
		foreach ( $audit['items'] as $item ) {
			$node = isset( $item['node'] ) && is_array( $item['node'] ) ? $item['node'] : array();
			$text = isset( $node['explanation'] ) ? (string) $node['explanation'] : '';
			if ( ! preg_match( '/foreground color:\s*(#[0-9a-f]{3,8})/i', $text, $fg ) || ! preg_match( '/background color:\s*(#[0-9a-f]{3,8})/i', $text, $bg ) ) {
				continue;
			}
			$px       = preg_match( '/font size:[^(]*\(\s*([\d.]+)px/i', $text, $m ) ? (float) $m[1] : 16.0;
			$bold     = (bool) preg_match( '/font weight:\s*(bold|[6-9]00)/i', $text );
			$required = preg_match( '/Expected contrast ratio of\s*([\d.]+)\s*:\s*1/i', $text, $m ) ? (float) $m[1] : ( ( $px >= 24 || ( $bold && $px >= 18.66 ) ) ? 3.0 : 4.5 );
			$out[]    = array(
				'selector' => isset( $node['selector'] ) ? (string) $node['selector'] : '',
				'label'    => isset( $node['label'] ) ? (string) $node['label'] : '',
				'fg'       => ekwa_page_audit_hex( $fg[1] ),
				'bg'       => ekwa_page_audit_hex( $bg[1] ),
				'px'       => $px,
				'bold'     => $bold,
				'required' => $required,
			);
		}
	}
	return $out;
}

/**
 * Hints for the model: each failing element with the existing tokens that
 * already pass against its background.
 *
 * @param array $items  From ekwa_page_audit_contrast_items().
 * @param array $tokens name => value.
 * @return array[]
 */
function ekwa_page_audit_contrast_hints( $items, $tokens ) {
	$hex_tokens = ekwa_page_audit_color_tokens( $tokens );
	$out        = array();
	foreach ( array_slice( $items, 0, 12 ) as $item ) {
		$pass = array();
		foreach ( array_keys( $hex_tokens ) as $name ) {
			if ( ! ekwa_pa_text_token( $name ) ) {
				continue;
			}
			$r = ekwa_pa_worst_ratio( 'var(' . $name . ')', array( $item['bg'] ), $tokens );
			if ( $r >= $item['required'] ) {
				$pass[ $name ] = round( $r, 2 );
			}
		}
		arsort( $pass );
		$out[] = array(
			'element'        => $item['selector'],
			'text'           => $item['label'],
			'foreground'     => $item['fg'],
			'background'     => $item['bg'],
			'required_ratio' => $item['required'],
			'now'            => round( ekwa_pa_contrast( $item['fg'], $item['bg'] ), 2 ),
			'tokens_that_pass' => array_slice( array_keys( $pass ), 0, 8 ),
		);
	}
	return $out;
}

/**
 * Could this token be a text colour? Tokens named for borders, lines, scrims,
 * overlays, shadows or backgrounds are left out of text-colour suggestions,
 * even when their contrast happens to pass.
 *
 * @param string $name
 * @return bool
 */
function ekwa_pa_text_token( $name ) {
	return ! preg_match( '/(^|-)(border|borders|line|lines|rule|divider|scrim|veil|overlay|backdrop|shadow|bg|background|surface|fill|tint|wash|track|scrolled)(-|$)/i', ltrim( (string) $name, '-' ) );
}

/**
 * Site tokens that are plain colours: name => #rrggbb.
 *
 * @param array $tokens name => value.
 * @return array
 */
function ekwa_page_audit_color_tokens( $tokens ) {
	$out = array();
	foreach ( $tokens as $name => $value ) {
		if ( '' === (string) $value ) {
			continue;
		}
		$hex = ekwa_page_audit_css_resolve_color( $value, $tokens );
		if ( '' !== $hex ) {
			$out[ $name ] = $hex;
		}
	}
	return $out;
}

// ═══════════════════════════════════════════════════════════════════════════════
// OPTIONS FOR ONE COLOUR EDIT
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * The backgrounds (and required ratio) a colour edit must pass: the failing
 * contrast elements it covers — matched on the page when a snapshot is there,
 * otherwise by their current foreground colour.
 *
 * @param array      $edit   Validated edit (selector, before_hex…).
 * @param array      $items  Contrast items.
 * @param array|null $snap   Page snapshot or null.
 * @return array{bgs:string[],required:float,items:int}
 */
function ekwa_page_audit_color_targets( $edit, $items, $snap ) {
	$covered = array();
	if ( $snap && ! is_wp_error( $snap ) ) {
		$mine  = ekwa_page_audit_css_nodes( $snap, $edit['selector'] );
		$paths = array();
		foreach ( is_array( $mine ) ? $mine : array() as $node ) {
			$paths[] = $node->getNodePath();
		}
		if ( $paths ) {
			foreach ( $items as $i => $item ) {
				$theirs = '' !== $item['selector'] ? ekwa_page_audit_css_nodes( $snap, $item['selector'] ) : null;
				foreach ( is_array( $theirs ) ? $theirs : array() as $node ) {
					$p = $node->getNodePath();
					foreach ( $paths as $mp ) {
						// Same element, or a descendant (colour is inherited).
						if ( $p === $mp || 0 === strpos( $p, $mp . '/' ) ) {
							$covered[ $i ] = true;
						}
					}
				}
			}
		}
	}
	if ( ! $covered && ! empty( $edit['before_hex'] ) ) {
		foreach ( $items as $i => $item ) {
			if ( $item['fg'] === $edit['before_hex'] ) {
				$covered[ $i ] = true;
			}
		}
	}
	$bgs      = array();
	$fgs      = array();
	$required = 0.0;
	foreach ( array_keys( $covered ) as $i ) {
		$bgs[ $items[ $i ]['bg'] ] = true;
		$fgs[ $items[ $i ]['fg'] ] = isset( $fgs[ $items[ $i ]['fg'] ] ) ? $fgs[ $items[ $i ]['fg'] ] + 1 : 1;
		$required                  = max( $required, (float) $items[ $i ]['required'] );
	}
	arsort( $fgs );
	return array( 'bgs' => array_keys( $bgs ), 'fgs' => array_keys( $fgs ), 'required' => $required, 'items' => count( $covered ) );
}

/**
 * Lowest contrast a colour value reaches over a set of backgrounds (alpha
 * composited over each one).
 *
 * @param string   $value  CSS colour value (var(), hex, rgba()).
 * @param string[] $bgs
 * @param array    $tokens
 * @return float 0 when the value is not a colour.
 */
function ekwa_pa_worst_ratio( $value, $bgs, $tokens ) {
	$rgba = ekwa_pa_resolve_rgba( $value, $tokens );
	if ( ! $rgba || $rgba[3] < 0.05 || ! $bgs ) {
		return 0.0;
	}
	$min = 99.0;
	foreach ( $bgs as $bg ) {
		$min = min( $min, ekwa_pa_contrast( ekwa_pa_over( $rgba, $bg ), $bg ) );
	}
	return $min;
}

/**
 * Colour options for one text-colour edit, each guaranteed to pass.
 *
 * @param array  $edit     Validated edit (property color, before_hex, after_hex, to).
 * @param array  $targets  From ekwa_page_audit_color_targets().
 * @param array  $tokens   Site tokens (name => value), incl. the model's new ones.
 * @param bool   $scoped   Section CSS target (no new tokens).
 * @param array  $ai_vars  The model's new token(s), if any.
 * @param string $base     Token the current value used (for naming a shade), e.g. "--gold".
 * @return array[] {id, kind, label, to, vars, hex, sample, ratio, delta, recommended}
 */
function ekwa_page_audit_color_options( $edit, $targets, $tokens, $scoped, $ai_vars, $base ) {
	$bgs      = $targets['bgs'];
	$required = $targets['required'] > 0 ? $targets['required'] : 4.5;
	// Today's colour: what the rule says, or — when the fix adds a colour the
	// element only inherits now — what Lighthouse measured on it.
	$from     = ! empty( $edit['before_hex'] ) ? $edit['before_hex'] : ( ! empty( $targets['fgs'][0] ) ? $targets['fgs'][0] : '' );
	if ( ! $bgs || '' === $from ) {
		return array();
	}
	if ( '' === $base ) {
		foreach ( $tokens as $name => $value ) {
			if ( '' !== (string) $value && ekwa_pa_text_token( $name ) && ekwa_page_audit_css_resolve_color( $value, $tokens ) === $from ) {
				$base = strtolower( $name );
				break;
			}
		}
	}
	$margin  = 0.05; // A value that just scrapes the line can still fail after rounding.
	$options = array();
	$seen    = array();
	$add     = static function ( $opt ) use ( &$options, &$seen, $bgs, $tokens, $from, $required, $margin ) {
		$rgba = ekwa_pa_resolve_rgba( $opt['value'], $tokens );
		if ( ! $rgba ) {
			return;
		}
		$ratio = ekwa_pa_worst_ratio( $opt['value'], $bgs, $tokens );
		if ( $ratio < $required + $margin ) {
			return;
		}
		$hex = ekwa_pa_over( $rgba, $bgs[0] );
		if ( isset( $seen[ $hex ] ) ) {
			return;
		}
		$seen[ $hex ]  = true;
		$opt['hex']    = $hex;
		$opt['sample'] = $rgba[3] < 1 ? sprintf( 'rgba(%d,%d,%d,%s)', $rgba[0], $rgba[1], $rgba[2], round( $rgba[3], 3 ) ) : $hex;
		$opt['ratio']  = round( $ratio, 2 );
		$opt['delta']  = round( ekwa_pa_delta_e( $from, $hex ), 1 );
		unset( $opt['value'] );
		$options[] = $opt;
	};

	// 1. Existing tokens that pass — the design's own palette.
	$ranked = array();
	foreach ( $tokens as $name => $value ) {
		if ( '' === (string) $value || ! ekwa_pa_text_token( $name ) || ekwa_pa_worst_ratio( 'var(' . $name . ')', $bgs, $tokens ) < $required + $margin ) {
			continue;
		}
		$rgba            = ekwa_pa_resolve_rgba( 'var(' . $name . ')', $tokens );
		$ranked[ $name ] = ekwa_pa_delta_e( $from, ekwa_pa_over( $rgba, $bgs[0] ) );
	}
	asort( $ranked );
	foreach ( array_slice( array_keys( $ranked ), 0, 2 ) as $name ) {
		$add( array(
			'kind'  => 'token',
			/* translators: %s: CSS variable name */
			'label' => sprintf( __( 'Your colour %s', 'ekwa' ), $name ),
			'to'    => 'var(' . $name . ')',
			'value' => 'var(' . $name . ')',
			'vars'  => array(),
		) );
	}

	// 2. A shade of the current colour, just dark/light enough.
	$shade = ekwa_pa_shade_for( $from, $bgs, $required + 0.15 );
	if ( '' !== $shade ) {
		$light = ekwa_pa_luminance( ekwa_pa_rgb( $shade ) ) > ekwa_pa_luminance( ekwa_pa_rgb( $from ) );
		if ( $scoped ) {
			$add( array(
				'kind'  => 'shade',
				'label' => $light ? __( 'Lighter shade of the current colour', 'ekwa' ) : __( 'Darker shade of the current colour', 'ekwa' ),
				'to'    => $shade,
				'value' => $shade,
				'vars'  => array(),
			) );
		} else {
			$stem = '' !== $base ? $base : '--text';
			$name = $stem . ( $light ? '-light' : '-ink' );
			$n    = 2;
			while ( isset( $tokens[ $name ] ) ) {
				$name = $stem . ( $light ? '-light' : '-ink' ) . '-' . $n++;
			}
			$add( array(
				'kind'  => 'shade',
				/* translators: %s: new CSS variable name */
				'label' => sprintf( $light ? __( 'Lighter shade of the current colour (new %s)', 'ekwa' ) : __( 'Darker shade of the current colour (new %s)', 'ekwa' ), $name ),
				'to'    => 'var(' . $name . ')',
				'value' => $shade,
				'vars'  => array( array( 'name' => $name, 'value' => $shade ) ),
			) );
		}
	}

	// 3. The model's own pick — only when it really passes.
	if ( ! empty( $edit['to'] ) ) {
		$add( array(
			'kind'  => 'ai',
			'label' => __( 'The AI’s suggestion', 'ekwa' ),
			'to'    => $edit['to'],
			'value' => $edit['to'],
			'vars'  => $scoped ? array() : array_values( (array) $ai_vars ),
		) );
	}

	// Recommended: the passing option closest to today's colour; an existing
	// token wins a near tie (no new token to maintain).
	usort( $options, static function ( $a, $b ) {
		$da = $a['delta'] - ( 'token' === $a['kind'] ? 4 : 0 );
		$db = $b['delta'] - ( 'token' === $b['kind'] ? 4 : 0 );
		return $da <=> $db;
	} );
	foreach ( $options as $i => $opt ) {
		$options[ $i ]['id']          = 'o' . ( $i + 1 );
		$options[ $i ]['recommended'] = 0 === $i;
	}
	return $options;
}

/**
 * A stored css fix with the person's chosen colour option applied (or the
 * recommended one). The option is looked up in the stored fix — the request
 * only names it.
 *
 * @param array  $fix
 * @param string $option_id
 * @return array|WP_Error
 */
function ekwa_page_audit_css_fix_with_option( $fix, $option_id ) {
	if ( empty( $fix['colorOptions'] ) || ! isset( $fix['colorEdit'] ) ) {
		return $fix;
	}
	$chosen = null;
	foreach ( $fix['colorOptions'] as $opt ) {
		if ( ( '' === (string) $option_id && ! empty( $opt['recommended'] ) ) || ( isset( $opt['id'] ) && $opt['id'] === $option_id ) ) {
			$chosen = $opt;
		}
	}
	if ( ! $chosen ) {
		return new WP_Error( 'ekwa_page_audit_option', __( 'That colour option is not in the latest analysis. Analyze again.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$i = (int) $fix['colorEdit'];
	if ( ! isset( $fix['css']['edits'][ $i ] ) ) {
		return $fix;
	}
	$important = preg_match( '/!\s*important\s*$/i', (string) $fix['css']['edits'][ $i ]['to'] );
	$fix['css']['edits'][ $i ]['to']        = $chosen['to'] . ( $important && ! preg_match( '/!\s*important\s*$/i', $chosen['to'] ) ? ' !important' : '' );
	$fix['css']['edits'][ $i ]['after_hex'] = $chosen['hex'];
	// Tokens: only the chosen option's (the model's own new token is dropped
	// when another option was picked).
	$fix['css']['vars']   = isset( $chosen['vars'] ) ? array_values( (array) $chosen['vars'] ) : array();
	$fix['chosenOption']  = $chosen['id'];
	return $fix;
}
