<?php
/**
 * Mockup Converter — normalize every icon font to Font Awesome.
 *
 * The theme bundles Font Awesome 6 Free and nothing else, so a mockup built on
 * Remix Icon, Bootstrap Icons, Themify, Ionicons, Material Icons, Line Awesome
 * or Glyphicons converts into markup whose icons simply do not render — an
 * empty box where the phone glyph should be, with no error anywhere. Rather
 * than ask the site to load a second icon font, the converter rewrites those
 * classes to their Font Awesome equivalents.
 *
 * Every mapping target is a real Font Awesome 6 **Free** icon (Solid or
 * Brands); the test harness validates the whole table against the bundled
 * assets/fontawesome/css/all.min.css, so a typo can't ship silently.
 *
 * Classes that aren't icons (`ekwa-caret`, layout hooks) are preserved, and an
 * icon we can't map is left untouched and reported, so it's visible rather than
 * silently turned into the wrong glyph.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) {
	exit;
}

/**
 * Icon-font families the converter recognizes and rewrites.
 *
 * 'prefix'   — class prefix identifying a glyph token.
 * 'family'   — bare family tokens to drop (they're replaced by fa-solid).
 * 'strip'    — suffixes/infixes removed to reach the semantic base name.
 *
 * @return array<string,array>
 */
function ekwa_mc_icon_families() {
	return array(
		'remix' => array(
			'prefix' => array( 'ri-' ),
			'family' => array(),
			// ri-arrow-down-s-line → arrow-down-s → arrow-down (the "-s" is
			// Remix's small/rounded variant, not part of the meaning).
			'strip'  => array( '-line', '-fill' ),
		),
		'bootstrap' => array(
			'prefix' => array( 'bi-' ),
			'family' => array( 'bi' ),
			'strip'  => array( '-fill' ),
		),
		'themify' => array(
			'prefix' => array( 'ti-' ),
			'family' => array(),
			'strip'  => array(),
		),
		'lineawesome' => array(
			'prefix' => array( 'la-' ),
			'family' => array( 'las', 'lar', 'lab' ),
			'strip'  => array(),
		),
		'ionicons' => array(
			'prefix' => array( 'ion-' ),
			'family' => array(),
			'strip'  => array( '-outline', '-sharp' ),
		),
		'icofont' => array(
			'prefix' => array( 'icofont-' ),
			'family' => array( 'icofont' ),
			'strip'  => array(),
		),
		'feather' => array(
			'prefix' => array( 'feather-' ),
			'family' => array( 'feather' ),
			'strip'  => array(),
		),
		'dashicons' => array(
			'prefix' => array( 'dashicons-' ),
			'family' => array( 'dashicons' ),
			'strip'  => array(),
		),
		'glyphicon' => array(
			'prefix' => array( 'glyphicon-' ),
			'family' => array( 'glyphicon' ),
			'strip'  => array(),
		),
	);
}

/**
 * Semantic base name → Font Awesome 6 Free icon.
 *
 * Value is either 'fa-name' (Solid) or array( 'fa-name', 'brands' ).
 *
 * @return array<string,string|array>
 */
function ekwa_mc_icon_alias_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}

	$map = array(
		// ── Navigation / UI ──────────────────────────────────────────────
		'search'              => 'magnifying-glass',
		'search-2'            => 'magnifying-glass',
		'search-eye'          => 'magnifying-glass',
		'zoom-in'             => 'magnifying-glass-plus',
		'zoom-out'            => 'magnifying-glass-minus',
		'magnifying-glass'    => 'magnifying-glass',
		'close'               => 'xmark',
		'close-circle'        => 'circle-xmark',
		'x'                   => 'xmark',
		'x-lg'                => 'xmark',
		'x-circle'            => 'circle-xmark',
		'remove'              => 'xmark',
		'menu'                => 'bars',
		'menu-2'              => 'bars',
		'menu-3'              => 'bars',
		'menu-4'              => 'bars',
		'menu-5'              => 'bars',
		'list'                => 'bars',
		'navicon'             => 'bars',
		'bars'                => 'bars',
		'arrow-down'          => 'arrow-down',
		'arrow-up'            => 'arrow-up',
		'arrow-left'          => 'arrow-left',
		'arrow-right'         => 'arrow-right',
		'arrow-down-s'        => 'chevron-down',
		'arrow-up-s'          => 'chevron-up',
		'arrow-left-s'        => 'chevron-left',
		'arrow-right-s'       => 'chevron-right',
		'arrow-down-circle'   => 'circle-arrow-down',
		'arrow-right-circle'  => 'circle-arrow-right',
		'arrow-left-circle'   => 'circle-arrow-left',
		'arrow-up-circle'     => 'circle-arrow-up',
		'chevron-down'        => 'chevron-down',
		'chevron-up'          => 'chevron-up',
		'chevron-left'        => 'chevron-left',
		'chevron-right'       => 'chevron-right',
		'chevron-compact-down' => 'chevron-down',
		'angle-down'          => 'chevron-down',
		'angle-up'            => 'chevron-up',
		'angle-left'          => 'chevron-left',
		'angle-right'         => 'chevron-right',
		'angle-double-right'  => 'angles-right',
		'angle-double-left'   => 'angles-left',
		'caret-down'          => 'caret-down',
		'caret-up'            => 'caret-up',
		'caret-left'          => 'caret-left',
		'caret-right'         => 'caret-right',
		'caret-down-fill'     => 'caret-down',
		'add'                 => 'plus',
		'plus'                => 'plus',
		'add-circle'          => 'circle-plus',
		'plus-circle'         => 'circle-plus',
		'subtract'            => 'minus',
		'minus'               => 'minus',
		'dash'                => 'minus',
		'check'               => 'check',
		'checkmark'           => 'check',
		'check-double'        => 'check-double',
		'check-circle'        => 'circle-check',
		'checkbox-circle'     => 'circle-check',
		'external-link'       => 'arrow-up-right-from-square',
		'box-arrow-up-right'  => 'arrow-up-right-from-square',
		'share-box'           => 'arrow-up-right-from-square',
		'home'                => 'house',
		'home-2'              => 'house',
		'house'               => 'house',
		'house-door'          => 'house',
		'more'                => 'ellipsis',
		'more-2'              => 'ellipsis-vertical',
		'three-dots'          => 'ellipsis',
		'filter'              => 'filter',
		'funnel'              => 'filter',
		'settings'            => 'gear',
		'settings-3'          => 'gear',
		'gear'                => 'gear',
		'cog'                 => 'gear',
		'refresh'             => 'arrows-rotate',
		'restart'             => 'arrows-rotate',
		'loader'              => 'spinner',
		'grid'                => 'table-cells',
		'apps'                => 'table-cells-large',
		'eye'                 => 'eye',
		'eye-off'             => 'eye-slash',
		'eye-slash'           => 'eye-slash',

		// ── Contact / location ───────────────────────────────────────────
		'phone'               => 'phone',
		'telephone'           => 'phone',
		'call'                => 'phone',
		'mobile'              => 'mobile-screen-button',
		'smartphone'          => 'mobile-screen-button',
		'phone-vibrate'       => 'mobile-screen-button',
		'headphone'           => 'headphones',
		'customer-service'    => 'headset',
		'customer-service-2'  => 'headset',
		'mail'                => 'envelope',
		'mail-send'           => 'paper-plane',
		'envelope'            => 'envelope',
		'send'                => 'paper-plane',
		'send-plane'          => 'paper-plane',
		'map-pin'             => 'location-dot',
		'map-pin-2'           => 'location-dot',
		'geo-alt'             => 'location-dot',
		'geo'                 => 'location-dot',
		'location'            => 'location-dot',
		'location-pin'        => 'location-dot',
		'location-arrow'      => 'location-arrow',
		'map-marker'          => 'location-dot',
		'map-marker-alt'      => 'location-dot',
		'pin'                 => 'location-dot',
		'map'                 => 'map-location-dot',
		'road-map'            => 'map-location-dot',
		'navigation'          => 'diamond-turn-right',
		'direction'           => 'diamond-turn-right',
		'compass'             => 'compass',
		'time'                => 'clock',
		'clock'               => 'clock',
		'timer'               => 'stopwatch',
		'alarm'               => 'bell',
		'notification'        => 'bell',
		'bell'                => 'bell',
		'calendar'            => 'calendar-days',
		'calendar-event'      => 'calendar-day',
		'calendar-check'      => 'calendar-check',
		'calendar2-check'     => 'calendar-check',
		'calendar-2'          => 'calendar-days',
		'user'                => 'user',
		'person'              => 'user',
		'account-circle'      => 'circle-user',
		'person-circle'       => 'circle-user',
		'user-add'            => 'user-plus',
		'group'               => 'users',
		'team'                => 'users',
		'people'              => 'users',
		'users'               => 'users',
		'printer'             => 'print',
		'print'               => 'print',

		// ── Content ──────────────────────────────────────────────────────
		'star'                => 'star',
		'star-s'              => 'star',
		'star-half'           => 'star-half-stroke',
		'star-half-s'         => 'star-half-stroke',
		'heart'               => 'heart',
		'heart-2'             => 'heart',
		'quote'               => 'quote-left',
		'double-quotes-l'     => 'quote-left',
		'double-quotes-r'     => 'quote-right',
		'quote-left'          => 'quote-left',
		'quote-right'         => 'quote-right',
		'chat-quote'          => 'quote-left',
		'format-quote'        => 'quote-left',
		'play'                => 'play',
		'play-circle'         => 'circle-play',
		'play-btn'            => 'circle-play',
		'pause'               => 'pause',
		'pause-circle'        => 'circle-pause',
		'image'               => 'image',
		'picture'             => 'image',
		'gallery'             => 'images',
		'camera'              => 'camera',
		'video'               => 'video',
		'videocam'            => 'video',
		'camera-video'        => 'video',
		'movie'               => 'film',
		'download'            => 'download',
		'download-2'          => 'download',
		'upload'              => 'upload',
		'file'                => 'file',
		'file-text'           => 'file-lines',
		'file-earmark'        => 'file',
		'file-pdf'            => 'file-pdf',
		'document'            => 'file-lines',
		'clipboard'           => 'clipboard',
		'share'               => 'share-nodes',
		'share-forward'       => 'share',
		'share-fill'          => 'share-nodes',
		'link'                => 'link',
		'links'               => 'link',
		'lock'                => 'lock',
		'unlock'              => 'lock-open',
		'shield'              => 'shield-halved',
		'shield-check'        => 'shield-halved',
		'verified-badge'      => 'certificate',
		'information'         => 'circle-info',
		'info'                => 'circle-info',
		'info-circle'         => 'circle-info',
		'question'            => 'circle-question',
		'question-circle'     => 'circle-question',
		'question-answer'     => 'comments',
		'alert'               => 'triangle-exclamation',
		'error-warning'       => 'triangle-exclamation',
		'exclamation-triangle' => 'triangle-exclamation',
		'thumb-up'            => 'thumbs-up',
		'hand-thumbs-up'      => 'thumbs-up',
		'thumb-down'          => 'thumbs-down',
		'award'               => 'award',
		'medal'               => 'medal',
		'trophy'              => 'trophy',
		'graduation-cap'      => 'graduation-cap',
		'book'                => 'book',
		'book-open'           => 'book-open',
		'bookmark'            => 'bookmark',
		'tag'                 => 'tag',
		'price-tag'           => 'tag',
		'shopping-cart'       => 'cart-shopping',
		'cart'                => 'cart-shopping',
		'wallet'              => 'wallet',
		'bank-card'           => 'credit-card',
		'credit-card'         => 'credit-card',
		'money-dollar-circle' => 'circle-dollar-to-slot',
		'chat'                => 'comment',
		'chat-1'              => 'comment',
		'chat-3'              => 'comments',
		'message'             => 'comment',
		'comment'             => 'comment',
		'comments'            => 'comments',
		'globe'               => 'globe',
		'earth'               => 'earth-americas',
		'translate'           => 'language',
		'lightbulb'           => 'lightbulb',
		'flashlight'          => 'lightbulb',
		'rocket'              => 'rocket',
		'flash'               => 'bolt',
		'lightning'           => 'bolt',
		'bolt'                => 'bolt',
		'fire'                => 'fire',
		'gift'                => 'gift',
		'building'            => 'building',
		'community'           => 'building',
		'briefcase'           => 'briefcase',
		'chart-line'          => 'chart-line',
		'bar-chart'           => 'chart-column',
		'pie-chart'           => 'chart-pie',
		'line-chart'          => 'chart-line',
		'rss'                 => 'rss',
		'wifi'                => 'wifi',
		'sun'                 => 'sun',
		'moon'                => 'moon',

		// ── Health / medical (practice sites) ─────────────────────────────
		'heart-pulse'         => 'heart-pulse',
		'pulse'               => 'heart-pulse',
		'heartbeat'           => 'heart-pulse',
		'activity'            => 'heart-pulse',
		'stethoscope'         => 'stethoscope',
		'tooth'               => 'tooth',
		'hospital'            => 'hospital',
		'hospital-line'       => 'hospital',
		'nurse'               => 'user-nurse',
		'doctor'              => 'user-doctor',
		'capsule'             => 'capsules',
		'medicine-bottle'     => 'prescription-bottle-medical',
		'pill'                => 'capsules',
		'syringe'             => 'syringe',
		'first-aid-kit'       => 'kit-medical',
		'briefcase-medical'   => 'kit-medical',
		'wheelchair'          => 'wheelchair',
		'bone'                => 'bone',
		'brain'               => 'brain',
		'lungs'               => 'lungs',
		'eye-2'               => 'eye',
		'dumbbell'            => 'dumbbell',
		'run'                 => 'person-running',
		'walk'                => 'person-walking',
		'body-scan'           => 'x-ray',
		'microscope'          => 'microscope',
		'test-tube'           => 'vial',
		'flask'               => 'flask',
		'leaf'                => 'leaf',
		'plant'               => 'seedling',
		'spa'                 => 'spa',
		'hand-heart'          => 'hand-holding-heart',
		'hand-holding-heart'  => 'hand-holding-heart',
		'mental-health'       => 'brain',
		'psychotherapy'       => 'brain',
		'empathize'           => 'hand-holding-heart',
		'baby'                => 'baby',
		'shield-cross'        => 'shield-heart',
		'health-book'         => 'notes-medical',
		'surgical-mask'       => 'head-side-mask',
		'virus'               => 'virus',
		'thermometer'         => 'temperature-half',

		// ── Brands ───────────────────────────────────────────────────────
		'facebook'            => array( 'facebook-f', 'brands' ),
		'facebook-circle'     => array( 'facebook', 'brands' ),
		'facebook-box'        => array( 'facebook-square', 'brands' ),
		'facebook-f'          => array( 'facebook-f', 'brands' ),
		'facebook-fill'       => array( 'facebook-f', 'brands' ),
		'messenger'           => array( 'facebook-messenger', 'brands' ),
		'google'              => array( 'google', 'brands' ),
		'google-fill'         => array( 'google', 'brands' ),
		'google-plus'         => array( 'google-plus-g', 'brands' ),
		'yelp'                => array( 'yelp', 'brands' ),
		'instagram'           => array( 'instagram', 'brands' ),
		'youtube'             => array( 'youtube', 'brands' ),
		'twitter'             => array( 'x-twitter', 'brands' ),
		'twitter-x'           => array( 'x-twitter', 'brands' ),
		'x-twitter'           => array( 'x-twitter', 'brands' ),
		'linkedin'            => array( 'linkedin-in', 'brands' ),
		'linkedin-box'        => array( 'linkedin', 'brands' ),
		'pinterest'           => array( 'pinterest-p', 'brands' ),
		'tiktok'              => array( 'tiktok', 'brands' ),
		'whatsapp'            => array( 'whatsapp', 'brands' ),
		'snapchat'            => array( 'snapchat', 'brands' ),
		'telegram'            => array( 'telegram', 'brands' ),
		'vimeo'               => array( 'vimeo-v', 'brands' ),
		'reddit'              => array( 'reddit-alien', 'brands' ),
		'apple'               => array( 'apple', 'brands' ),
		'android'             => array( 'android', 'brands' ),
		'threads'             => array( 'threads', 'brands' ),
	);

	return $map;
}

/**
 * Is this class token already a Font Awesome class?
 *
 * @param string $token Lowercased class token.
 * @return bool
 */
function ekwa_mc_icon_is_fontawesome( $token ) {
	static $fa_families = array( 'fa', 'fas', 'far', 'fab', 'fal', 'fad', 'fass', 'fasr' );
	return in_array( $token, $fa_families, true ) || 0 === strpos( $token, 'fa-' );
}

/**
 * Reduce a foreign icon class token to its semantic base name.
 *
 * @param string $token Lowercased class token.
 * @return string|null Base name, or null when the token isn't a glyph token.
 */
function ekwa_mc_icon_base_name( $token ) {
	foreach ( ekwa_mc_icon_families() as $family ) {
		foreach ( $family['prefix'] as $prefix ) {
			if ( 0 !== strpos( $token, $prefix ) ) {
				continue;
			}
			$base = substr( $token, strlen( $prefix ) );

			// Ionicons carry a platform segment: ion-ios-search / ion-md-search.
			$base = preg_replace( '/^(?:ios|md)-/', '', $base );

			foreach ( $family['strip'] as $suffix ) {
				if ( substr( $base, -strlen( $suffix ) ) === $suffix ) {
					$base = substr( $base, 0, -strlen( $suffix ) );
					break;
				}
			}

			return '' === $base ? null : $base;
		}
	}
	return null;
}

/**
 * Is this token a bare family class (to be dropped rather than mapped)?
 *
 * @param string $token Lowercased class token.
 * @return bool
 */
function ekwa_mc_icon_is_family_token( $token ) {
	foreach ( ekwa_mc_icon_families() as $family ) {
		if ( in_array( $token, $family['family'], true ) ) {
			return true;
		}
	}
	// Material Icons name the glyph in the element's TEXT, not the class.
	return 'material-icons' === $token || 0 === strpos( $token, 'material-symbols' );
}

/**
 * Rewrite a class attribute so any foreign icon becomes Font Awesome 6.
 *
 * Non-icon classes keep their place and order. A class already in Font Awesome
 * is left alone, and the whole string is returned unchanged when there was
 * nothing to convert.
 *
 * @param string $class_string Raw class attribute.
 * @param string $ligature     Element text content (Material Icons name the
 *                             glyph there rather than in the class).
 * @return array{class:string,changed:bool,unmapped:array<int,string>}
 */
function ekwa_mc_icon_class_to_fontawesome( $class_string, $ligature = '' ) {
	$tokens = preg_split( '/\s+/', trim( (string) $class_string ), -1, PREG_SPLIT_NO_EMPTY );
	if ( empty( $tokens ) ) {
		return array( 'class' => (string) $class_string, 'changed' => false, 'unmapped' => array() );
	}

	// Already Font Awesome — never touch it.
	foreach ( $tokens as $token ) {
		if ( ekwa_mc_icon_is_fontawesome( strtolower( $token ) ) ) {
			return array( 'class' => (string) $class_string, 'changed' => false, 'unmapped' => array() );
		}
	}

	$aliases   = ekwa_mc_icon_alias_map();
	$out       = array();
	$unmapped  = array();
	$changed   = false;
	$has_glyph = false;

	foreach ( $tokens as $token ) {
		$lower = strtolower( $token );

		if ( ekwa_mc_icon_is_family_token( $lower ) ) {
			$changed = true; // The family class is replaced by fa-solid/fa-brands.
			continue;
		}

		$base = ekwa_mc_icon_base_name( $lower );
		if ( null === $base ) {
			$out[] = $token; // Not an icon token — a layout/state class.
			continue;
		}

		$has_glyph = true;
		if ( ! isset( $aliases[ $base ] ) ) {
			$unmapped[] = $token;
			$out[]      = $token; // Leave it visible rather than guess wrong.
			continue;
		}

		$target = $aliases[ $base ];
		$style  = is_array( $target ) ? 'fa-' . $target[1] : 'fa-solid';
		$name   = is_array( $target ) ? $target[0] : $target;

		$out[]   = $style;
		$out[]   = 'fa-' . $name;
		$changed = true;
	}

	// Material Icons: the ligature text is the glyph name.
	if ( ! $has_glyph && '' !== trim( $ligature ) ) {
		$base = strtolower( trim( preg_replace( '/[\s_]+/', '-', trim( $ligature ) ) ) );
		if ( isset( $aliases[ $base ] ) ) {
			$target = $aliases[ $base ];
			$style  = is_array( $target ) ? 'fa-' . $target[1] : 'fa-solid';
			$name   = is_array( $target ) ? $target[0] : $target;
			array_unshift( $out, $style, 'fa-' . $name );
			$changed = true;
		} elseif ( $changed ) {
			$unmapped[] = $ligature;
		}
	}

	if ( ! $changed ) {
		return array( 'class' => (string) $class_string, 'changed' => false, 'unmapped' => $unmapped );
	}

	// A family token was dropped but nothing mapped — don't leave a classless
	// element behind; keep the original so the problem stays visible.
	if ( empty( $out ) ) {
		return array( 'class' => (string) $class_string, 'changed' => false, 'unmapped' => $unmapped );
	}

	return array(
		'class'    => implode( ' ', array_values( array_unique( $out ) ) ),
		'changed'  => true,
		'unmapped' => $unmapped,
	);
}

/**
 * Rewrite every icon element inside an HTML fragment.
 *
 * Targets `<i>` and `<span>` — the two tags icon fonts are used on. Material
 * Icons ligature text is consumed (the glyph moves into the class), otherwise
 * the element's children are untouched.
 *
 * @param string $html     HTML fragment.
 * @param array  $unmapped Collects icon classes with no Font Awesome match.
 * @param int    $count    Counts converted elements.
 * @return string
 */
function ekwa_mc_icons_html_to_fontawesome( $html, &$unmapped = array(), &$count = 0 ) {
	$html = (string) $html;
	if ( '' === $html || false === strpos( $html, 'class' ) ) {
		return $html;
	}

	// Pass 1 — Material Icons / Symbols, whose glyph name is the element's TEXT.
	// These are always leaf elements, so matching the full element is safe here
	// (and necessary: the text has to be consumed along with the rewrite).
	$html = preg_replace_callback(
		'#<(i|span)\b([^>]*\sclass=("|\')([^"\']*material-(?:icons|symbols)[^"\']*)\3[^>]*)>([^<]*)</\1>#is',
		function ( $m ) use ( &$unmapped, &$count ) {
			$result = ekwa_mc_icon_class_to_fontawesome( $m[4], $m[5] );
			if ( ! empty( $result['unmapped'] ) ) {
				$unmapped = array_merge( $unmapped, $result['unmapped'] );
			}
			if ( ! $result['changed'] ) {
				return $m[0];
			}
			$count++;
			$attrs = str_replace(
				'class=' . $m[3] . $m[4] . $m[3],
				'class=' . $m[3] . $result['class'] . $m[3],
				$m[2]
			);
			// The ligature text moved into the class — printing it too would
			// show the word "phone" next to the glyph.
			return '<' . $m[1] . $attrs . '></' . $m[1] . '>';
		},
		$html
	);

	// Pass 2 — every other icon font. Only the OPENING tag is matched: an icon
	// <i> can sit inside a <span> that also carries classes, and matching whole
	// elements made the outer one swallow the inner one before it was seen.
	$out = preg_replace_callback(
		'#<(i|span)\b([^>]*?)\sclass=("|\')([^"\']*)\3([^>]*)>#is',
		function ( $m ) use ( &$unmapped, &$count ) {
			$result = ekwa_mc_icon_class_to_fontawesome( $m[4] );
			if ( ! empty( $result['unmapped'] ) ) {
				$unmapped = array_merge( $unmapped, $result['unmapped'] );
			}
			if ( ! $result['changed'] ) {
				return $m[0];
			}
			$count++;
			return '<' . $m[1] . $m[2] . ' class=' . $m[3] . $result['class'] . $m[3] . $m[5] . '>';
		},
		$html
	);

	return ( null === $out ) ? $html : $out;
}

/**
 * Normalize every icon in a block-markup document to Font Awesome.
 *
 * Walks parsed blocks so all three places an icon can hide are covered:
 * the `iconClass` attribute, HTML smuggled into an attribute (a dynamic block's
 * `customTemplate`), and raw inner HTML (core/list contents, wp:html fallbacks).
 *
 * @param string $markup Block-comment markup.
 * @return array{markup:string,converted:int,unmapped:array<int,string>}
 */
function ekwa_mc_icons_to_fontawesome( $markup ) {
	$markup = (string) $markup;
	if ( '' === trim( $markup ) ) {
		return array( 'markup' => $markup, 'converted' => 0, 'unmapped' => array() );
	}

	$count    = 0;
	$unmapped = array();

	$blocks = parse_blocks( $markup );
	$blocks = ekwa_mc_icons_walk_blocks( $blocks, $count, $unmapped );

	if ( 0 === $count ) {
		return array( 'markup' => $markup, 'converted' => 0, 'unmapped' => array_values( array_unique( $unmapped ) ) );
	}

	return array(
		'markup'    => serialize_blocks( $blocks ),
		'converted' => $count,
		'unmapped'  => array_values( array_unique( $unmapped ) ),
	);
}

/**
 * Recursive worker for ekwa_mc_icons_to_fontawesome().
 *
 * @param array $blocks   Parsed blocks.
 * @param int   $count    Converted counter, by reference.
 * @param array $unmapped Unmapped classes, by reference.
 * @return array
 */
function ekwa_mc_icons_walk_blocks( $blocks, &$count, &$unmapped ) {
	foreach ( $blocks as $i => $block ) {
		if ( ! empty( $block['innerBlocks'] ) ) {
			$blocks[ $i ]['innerBlocks'] = ekwa_mc_icons_walk_blocks( $block['innerBlocks'], $count, $unmapped );
		}

		// 1. Dedicated icon attributes.
		foreach ( array( 'iconClass', 'icon' ) as $key ) {
			if ( empty( $block['attrs'][ $key ] ) || ! is_string( $block['attrs'][ $key ] ) ) {
				continue;
			}
			$res = ekwa_mc_icon_class_to_fontawesome( $block['attrs'][ $key ] );
			if ( ! empty( $res['unmapped'] ) ) {
				$unmapped = array_merge( $unmapped, $res['unmapped'] );
			}
			if ( $res['changed'] ) {
				$blocks[ $i ]['attrs'][ $key ] = $res['class'];
				$count++;
			}
		}

		// 2. An <i> rendered by ekwa/div or ekwa/text via tagName.
		if ( ! empty( $block['attrs']['className'] ) && is_string( $block['attrs']['className'] )
			&& isset( $block['attrs']['tagName'] ) && 'i' === $block['attrs']['tagName'] ) {
			$res = ekwa_mc_icon_class_to_fontawesome( $block['attrs']['className'] );
			if ( ! empty( $res['unmapped'] ) ) {
				$unmapped = array_merge( $unmapped, $res['unmapped'] );
			}
			if ( $res['changed'] ) {
				$blocks[ $i ]['attrs']['className'] = $res['class'];
				$count++;
			}
		}

		// 3. HTML inside any string attribute (customTemplate, and friends).
		if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			foreach ( $block['attrs'] as $key => $value ) {
				if ( ! is_string( $value ) || false === strpos( $value, '<' ) ) {
					continue;
				}
				$new = ekwa_mc_icons_html_to_fontawesome( $value, $unmapped, $count );
				if ( $new !== $value ) {
					$blocks[ $i ]['attrs'][ $key ] = $new;
				}
			}
		}

		// 4. Raw inner HTML (core/list contents, wp:html fallbacks).
		foreach ( array( 'innerHTML', 'innerContent' ) as $key ) {
			if ( empty( $block[ $key ] ) ) {
				continue;
			}
			if ( is_string( $block[ $key ] ) ) {
				$blocks[ $i ][ $key ] = ekwa_mc_icons_html_to_fontawesome( $block[ $key ], $unmapped, $count );
				continue;
			}
			foreach ( (array) $block[ $key ] as $j => $chunk ) {
				if ( is_string( $chunk ) ) {
					$blocks[ $i ][ $key ][ $j ] = ekwa_mc_icons_html_to_fontawesome( $chunk, $unmapped, $count );
				}
			}
		}
	}

	return $blocks;
}
