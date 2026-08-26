<?php
/**
 * Ekwa Page Banner family — parent banner, banner title, breadcrumb.
 *
 * The successor to ekwa/inner-banner. That block was a closed box: it owned its
 * heading, its breadcrumb nav, one overlay and one min-height, accepted no inner
 * blocks, and every layout variation had to become another is-style-* rule in its
 * stylesheet. This family splits the same behaviour into composable pieces:
 *
 *   ekwa/page-banner   the wrapper — tag, background, overlay, scoped CSS, and
 *                      any blocks you like inside it
 *   ekwa/banner-title  the smart heading (Main Menu label vs. page title)
 *   ekwa/breadcrumb    the trail, with a separator you control
 *
 * ekwa/inner-banner and ekwa/page-title stay registered and unchanged — existing
 * pages and templates keep rendering exactly as before, and nothing is migrated.
 * The legacy banner is simply hidden from the inserter (blocks/ekwa-inner-banner/
 * block.json), the same treatment ekwa/section already got.
 *
 * The heading contract is deliberately shared, not reimplemented: ekwa/banner-title
 * in its default "auto" mode calls ekwa_inner_banner_heading_data() (inc/ekwa-blocks.php),
 * so ekwa/page-title still supplies the <h1> on exactly the pages it used to.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ====================================================================
 * Shared data helpers
 * ==================================================================== */

/**
 * The current page's label in a nav menu location.
 *
 * Starts from ekwa_get_menu_name_for_page() — the legacy lookup, which matches
 * `post_type` menu items by object_id — then falls back to matching `custom`
 * items by URL. That second pass is new: a page added to the menu as a Custom
 * Link has no object_id, so the legacy lookup never resolved it even though the
 * link points straight at the page. The legacy function itself is untouched, so
 * ekwa/inner-banner and ekwa/page-title keep their exact current behaviour on
 * every page.
 *
 * @param int    $post_id  Post/page id.
 * @param string $location Theme menu location slug. Falls back to the legacy
 *                         main_menu → primary preference when left empty.
 * @return string Menu item title, or '' when the page isn't in the menu.
 */
function ekwa_banner_menu_name( $post_id, $location = 'main_menu' ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return '';
	}

	// Default location → the legacy helper already prefers main_menu then primary.
	if ( '' === $location || 'main_menu' === $location ) {
		$name = function_exists( 'ekwa_get_menu_name_for_page' )
			? ekwa_get_menu_name_for_page( $post_id )
			: '';
		if ( '' !== $name ) {
			return $name;
		}
	}

	$locations = get_nav_menu_locations();
	$menu_id   = ! empty( $locations[ $location ] ) ? $locations[ $location ] : 0;
	if ( ! $menu_id ) {
		return '';
	}

	$items = wp_get_nav_menu_items( $menu_id );
	if ( empty( $items ) || ! is_array( $items ) ) {
		return '';
	}

	foreach ( $items as $item ) {
		if ( 'post_type' === $item->type && (int) $item->object_id === $post_id ) {
			return (string) $item->title;
		}
	}

	// Second pass: Custom Link items whose URL resolves to this page. Compared
	// scheme- and trailing-slash-insensitively so http/https and with/without a
	// trailing slash all match the permalink.
	$permalink = get_permalink( $post_id );
	if ( ! $permalink ) {
		return '';
	}
	$target = ekwa_banner_normalize_url( $permalink );

	foreach ( $items as $item ) {
		if ( 'custom' !== $item->type || empty( $item->url ) ) {
			continue;
		}
		if ( ekwa_banner_normalize_url( $item->url ) === $target ) {
			return (string) $item->title;
		}
	}

	return '';
}

/**
 * Reduce a URL to host + path for comparison — no scheme, no query, no trailing
 * slash, lowercased.
 *
 * @param string $url Any URL.
 * @return string
 */
function ekwa_banner_normalize_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! $parts ) {
		return '';
	}
	$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
	$path = isset( $parts['path'] ) ? $parts['path'] : '/';
	return $host . '/' . trim( $path, '/' );
}

/**
 * The label that represents the current page at the end of a breadcrumb trail.
 *
 * Resolution order, which is also what the Breadcrumb block's "auto" mode uses:
 *   1. Yoast's per-page breadcrumb title (_yoast_wpseo_bctitle) — the field an
 *      SEO editor sets precisely so the trail reads shorter than the page title.
 *      inc/ekwa-settings.php's bulk page importer already writes it.
 *   2. The Main Menu label for the page, so the trail matches the nav.
 *   3. The page title.
 *
 * @param int $post_id Post/page id.
 * @return string
 */
function ekwa_banner_breadcrumb_label( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return '';
	}

	$bc = trim( (string) get_post_meta( $post_id, '_yoast_wpseo_bctitle', true ) );
	if ( '' !== $bc ) {
		return $bc;
	}

	$menu = ekwa_banner_menu_name( $post_id );
	if ( '' !== $menu ) {
		return $menu;
	}

	return (string) get_the_title( $post_id );
}

/**
 * Clamp a tag name to an allow-list.
 *
 * @param mixed    $tag     Requested tag.
 * @param string[] $allowed Allowed tags.
 * @param string   $default Fallback when the request isn't allowed.
 * @return string
 */
function ekwa_banner_tag( $tag, array $allowed, $default ) {
	$tag = sanitize_key( (string) $tag );
	return in_array( $tag, $allowed, true ) ? $tag : $default;
}

/**
 * Accept a CSS length only when it can't break out of the attribute it lands in.
 *
 * Permissive about the value itself (px/rem/%/vw/clamp()/var() all pass) but
 * rejects anything carrying quotes, semicolons, braces or angle brackets — the
 * characters that would end the declaration or the attribute.
 *
 * @param string $value Raw value from the block attribute.
 * @return string The value, or '' when it isn't safe to print.
 */
function ekwa_banner_css_length( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	return preg_match( '/^[a-z0-9\s%.,()\-+*\/_]+$/i', $value ) ? $value : '';
}

/**
 * Accept a CSS color only when it can't break out of the declaration.
 *
 * @param string $value Hex, rgb()/hsl(), a var(), or a keyword.
 * @return string The value, or '' when it isn't safe to print.
 */
function ekwa_banner_css_color( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	return preg_match( '/^[a-z0-9\s%.,()#\-\/_]+$/i', $value ) ? $value : '';
}


/* ====================================================================
 * ekwa/page-banner
 * ==================================================================== */

/**
 * Resolve the banner's background image attachment id.
 *
 * @param array $attrs Block attributes.
 * @return int Attachment id, or 0 when the banner has no background.
 */
function ekwa_page_banner_image_id( array $attrs ) {
	$source = isset( $attrs['bgSource'] ) ? (string) $attrs['bgSource'] : 'featured';

	if ( 'custom' === $source ) {
		return isset( $attrs['bgImageId'] ) ? (int) $attrs['bgImageId'] : 0;
	}
	if ( 'featured' !== $source ) {
		return 0;
	}

	// The featured image of whatever singular object is being viewed — page, post
	// or CPT. The legacy block restricted itself to is_page(); there is no reason
	// a post can't have a banner.
	//
	// Gated on is_singular() rather than just get_the_ID(): on an archive that
	// call returns whichever post the loop is currently on, so a banner placed in
	// an archive template would silently adopt the first post's featured image.
	if ( ! is_singular() ) {
		return 0;
	}

	$post_id = get_the_ID();
	if ( ! $post_id || ! has_post_thumbnail( $post_id ) ) {
		return 0;
	}
	return (int) get_post_thumbnail_id( $post_id );
}

/**
 * Build the CSS-background declarations for a banner instance.
 *
 * Only used in `bgRender: "css"` mode, where the author wants background-position
 * / background-attachment control that a <picture> layer can't give them. The
 * cost is real and worth stating: a CSS background can't carry fetchpriority and
 * isn't discoverable by the preload scanner, so it is the slower choice for an
 * above-the-fold banner. `picture` stays the default for that reason.
 *
 * @param int    $thumb_id    Attachment id.
 * @param array  $attrs       Block attributes.
 * @param string $scope_class Per-instance class the rules are keyed to.
 * @return string CSS text, or '' when the attachment is unresolved.
 */
function ekwa_page_banner_bg_css( $thumb_id, array $attrs, $scope_class ) {
	$sources = ekwa_inner_banner_bg_sources( $thumb_id );
	if ( ! $sources ) {
		return '';
	}

	$mobile     = isset( $attrs['bgMobile'] ) ? (string) $attrs['bgMobile'] : 'crop';
	$size       = ekwa_banner_css_length( $attrs['bgSize'] ?? 'cover' );
	$position   = ekwa_banner_css_length( $attrs['bgPosition'] ?? '50% 50%' );
	$attachment = ekwa_banner_tag( $attrs['bgAttachment'] ?? 'scroll', array( 'scroll', 'fixed', 'local' ), 'scroll' );

	$sel = '.' . $scope_class;
	$css = $sel . '{background-image:url(' . esc_url_raw( $sources['full']['url'] ) . ');'
		. 'background-size:' . ( $size ? $size : 'cover' ) . ';'
		. 'background-position:' . ( $position ? $position : '50% 50%' ) . ';'
		. 'background-repeat:no-repeat;'
		. 'background-attachment:' . $attachment . '}';

	// Phone override. 'crop' points ≤480px at the ~480w crop when one exists;
	// 'hide' drops the image entirely; 'full' adds nothing, so phones keep the
	// full-size file declared above.
	if ( 'hide' === $mobile ) {
		$css .= '@media(max-width:480px){' . $sel . '{background-image:none}}';
	} elseif ( 'crop' === $mobile ) {
		$small = wp_get_attachment_image_src( $thumb_id, 'ekwa-banner-mobile' );
		// [3] = is_intermediate — false means WP handed back the full image
		// because the size isn't generated yet, and pointing phones at that
		// would be worse than leaving the rule out.
		if ( $small && ! empty( $small[3] ) ) {
			$url = $small[0];
			if ( function_exists( 'ekwa_webp_is_enabled' ) && ekwa_webp_is_enabled()
				&& function_exists( 'ekwa_webp_browser_supports' ) && ekwa_webp_browser_supports()
				&& function_exists( 'ekwa_webp_url_for' ) ) {
				$url = ekwa_webp_url_for( $url );
			}
			$css .= '@media(max-width:480px){' . $sel . '{background-image:url(' . esc_url_raw( $url ) . ')}}';
		}
	}

	return $css;
}

/**
 * Server-side render callback for the ekwa/page-banner block.
 *
 * @param array  $attrs   Block attributes.
 * @param string $content InnerBlocks HTML.
 * @return string
 */
function ekwa_render_page_banner_block( $attrs, $content ) {
	$attrs = is_array( $attrs ) ? $attrs : array();

	$tag = ekwa_banner_tag(
		$attrs['tagName'] ?? 'section',
		array( 'section', 'div', 'header', 'aside', 'article', 'main' ),
		'section'
	);

	$bg_render = ekwa_banner_tag( $attrs['bgRender'] ?? 'picture', array( 'picture', 'css' ), 'picture' );
	$bg_mobile = ekwa_banner_tag( $attrs['bgMobile'] ?? 'crop', array( 'crop', 'full', 'hide' ), 'crop' );
	$thumb_id  = ekwa_page_banner_image_id( $attrs );

	// A per-instance class derived from the attributes, so two banners configured
	// identically share one class *and* one inlined <style> (the dedupe in
	// ekwa_inline_get_style_inline() is keyed on the CSS hash), while two
	// differently configured banners can never collide.
	$scope_class = 'ekwa-pb-' . substr( md5( (string) wp_json_encode( $attrs ) ), 0, 8 );

	$classes = array( 'ekwa-page-banner', $scope_class );
	if ( $thumb_id ) {
		$classes[] = 'ekwa-page-banner--has-bg';
		$classes[] = 'ekwa-page-banner--bg-' . $bg_render;
		if ( 'hide' === $bg_mobile ) {
			$classes[] = 'ekwa-page-banner--no-mobile-bg';
		}
	}

	// Inline style: min-height plus the author's own declarations. Everything
	// else (colors, spacing, borders, typography) arrives through block supports
	// on the wrapper attributes below.
	$style_parts = array();
	$min_height  = isset( $attrs['minHeight'] ) ? absint( $attrs['minHeight'] ) : 0;
	if ( $min_height > 0 ) {
		$style_parts[] = 'min-height:' . $min_height . 'px';
	}
	$inline_style = isset( $attrs['inlineStyle'] ) ? (string) $attrs['inlineStyle'] : '';
	if ( function_exists( 'ekwa_css_decode_entities' ) ) {
		$inline_style = ekwa_css_decode_entities( $inline_style );
	}
	$inline_style = trim( $inline_style, " \t\n\r;" );
	if ( '' !== $inline_style ) {
		$style_parts[] = $inline_style;
	}

	$wrapper_attrs = get_block_wrapper_attributes( array(
		'class' => implode( ' ', $classes ),
		'style' => implode( ';', $style_parts ),
	) );

	$aria_label = isset( $attrs['ariaLabel'] ) ? trim( (string) $attrs['ariaLabel'] ) : '';

	// WordPress 7.0 turned `anchor` into a server-applied block support, so
	// get_block_wrapper_attributes() already emitted the id there. Adding our own
	// would put two id attributes on the element. Older versions leave it to the
	// block, which is why this still emits one when core's handler is absent.
	$anchor = isset( $attrs['anchor'] ) ? sanitize_html_class( $attrs['anchor'] ) : '';
	$needs_anchor = $anchor && ! function_exists( 'wp_apply_anchor_support' );

	$out = '<' . $tag . ' ' . $wrapper_attrs;
	if ( $needs_anchor ) { $out .= ' id="' . esc_attr( $anchor ) . '"'; }
	if ( $aria_label )   { $out .= ' aria-label="' . esc_attr( $aria_label ) . '"'; }
	if ( function_exists( 'ekwa_render_custom_attributes' ) ) {
		$out .= ekwa_render_custom_attributes( $attrs );
	}
	$out .= '>';

	// Background layer. In `picture` mode this is a real <img> — LCP-discoverable,
	// art-directed per breakpoint, WebP-served and fetchpriority=high — reusing
	// the exact ladder the legacy banner and the <head> preload share, so the
	// preloaded file and the painted file can't drift apart.
	if ( $thumb_id && 'picture' === $bg_render ) {
		$out .= ekwa_inner_banner_bg_picture( $thumb_id, array(
			'mobile' => $bg_mobile,
			'class'  => 'ekwa-page-banner__bg',
		) );
	}

	// Overlay — only over an image, and only when it would tint anything.
	//
	// Gated on the image because an overlay on a flat background colour is just
	// a different flat colour, which the colour picker already does better. Its
	// real job is making white type legible over a photograph nobody vetted:
	// practice sites use bright clinical photography, and white-on-pale is the
	// most common way one of these banners ships unreadable. That is also why
	// the attribute defaults to 45 rather than 0.
	$overlay_opacity = isset( $attrs['overlayOpacity'] ) ? absint( $attrs['overlayOpacity'] ) : 45;
	if ( $thumb_id && $overlay_opacity > 0 ) {
		$overlay_color = ekwa_banner_css_color( $attrs['overlayColor'] ?? '' );
		$overlay_style = 'opacity:' . ( $overlay_opacity / 100 );
		if ( '' !== $overlay_color ) {
			$overlay_style .= ';background:' . $overlay_color;
		}
		$out .= '<div class="ekwa-page-banner__overlay" style="' . esc_attr( $overlay_style ) . '"></div>';
	}

	// Content well — carries the z-index that lifts inner blocks above the
	// background and overlay layers, and the max-width.
	$content_width   = isset( $attrs['contentWidth'] ) ? trim( (string) $attrs['contentWidth'] ) : '';
	$content_classes = 'ekwa-page-banner__content';
	$content_style   = '';
	if ( 'full' === $content_width ) {
		$content_classes .= ' ekwa-page-banner__content--full';
	} elseif ( '' !== $content_width ) {
		$width = ekwa_banner_css_length( $content_width );
		if ( '' !== $width ) {
			$content_style = ' style="max-width:' . esc_attr( $width ) . '"';
		}
	}

	$out .= '<div class="' . esc_attr( $content_classes ) . '"' . $content_style . '>';
	$out .= $content;
	$out .= '</div>';

	$out .= '</' . $tag . '>';

	// Everything this block needs to inline: the CSS-mode background rules and
	// the author's own scoped stylesheet. Both go through the shared dedupe, so
	// repeated banners emit one <style> between them.
	$css = '';
	if ( $thumb_id && 'css' === $bg_render ) {
		$css .= ekwa_page_banner_bg_css( $thumb_id, $attrs, $scope_class );
	}
	$scoped_css = isset( $attrs['scopedCss'] ) ? (string) $attrs['scopedCss'] : '';
	if ( function_exists( 'ekwa_css_decode_entities' ) ) {
		$scoped_css = ekwa_css_decode_entities( $scoped_css );
	}
	if ( '' !== trim( $scoped_css ) ) {
		$css .= ( '' !== $css ? "\n" : '' ) . $scoped_css;
	}

	if ( '' !== trim( $css ) && ! is_admin() && function_exists( 'ekwa_inline_get_style_inline' ) ) {
		$out = ekwa_inline_get_style_inline( $css, 'page-banner-' . md5( $css ) ) . $out;
	}

	return $out;
}


/* ====================================================================
 * ekwa/banner-title
 * ==================================================================== */

/**
 * Resolve the banner title's text and tag.
 *
 * "auto" is the mode that reproduces the legacy banner exactly: it defers to
 * ekwa_inner_banner_heading_data(), so on a page whose Main Menu label differs
 * from its title the banner prints the short label in a <p> — NOT an <h1> —
 * and ekwa/page-title supplies the real <h1> below the banner. When the two are
 * the same (or the page isn't in the menu) this block is the <h1> and
 * ekwa/page-title prints nothing. Keeping that logic in one function is the
 * point: the two blocks must agree about which one owns the <h1>, or a page ends
 * up with none or two.
 *
 * @param array $attrs Block attributes.
 * @return array{text:string,tag:string}
 */
function ekwa_banner_title_data( array $attrs ) {
	$source   = ekwa_banner_tag( $attrs['source'] ?? 'auto', array( 'auto', 'menu', 'page', 'breadcrumb-title' ), 'auto' );
	$post_id  = (int) get_the_ID();
	$fallback = trim( (string) ( $attrs['fallback'] ?? '' ) );
	$location = sanitize_key( (string) ( $attrs['menuLocation'] ?? 'main_menu' ) );

	$auto_tag = 'h1';
	$text     = '';

	switch ( $source ) {
		case 'menu':
			$text = ekwa_banner_menu_name( $post_id, $location );
			break;

		case 'page':
			$text = (string) get_the_title( $post_id );
			break;

		case 'breadcrumb-title':
			$text = ekwa_banner_breadcrumb_label( $post_id );
			break;

		case 'auto':
		default:
			$data     = ekwa_inner_banner_heading_data();
			$is_same  = ( 'same' === $data['mode'] );
			$text     = $is_same ? $data['page_title'] : $data['menu_name'];
			$auto_tag = $is_same ? 'h1' : 'p';
			break;
	}

	if ( '' === trim( $text ) ) {
		$text = '' !== $fallback ? $fallback : (string) get_the_title( $post_id );
	}

	$tag = (string) ( $attrs['tagName'] ?? 'auto' );
	if ( 'auto' === $tag || '' === $tag ) {
		$tag = $auto_tag;
	} else {
		$tag = ekwa_banner_tag( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div' ), $auto_tag );
	}

	return array( 'text' => $text, 'tag' => $tag );
}

/**
 * Server-side render callback for the ekwa/banner-title block.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_banner_title_block( $attrs ) {
	$attrs   = is_array( $attrs ) ? $attrs : array();
	$is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;

	if ( ! is_singular() ) {
		if ( ! $is_rest ) {
			return '';
		}
		// Editor preview inside a template, where there is no post context.
		// Always shows the "menu name differs" shape, because that is the case
		// authors need to see laid out.
		$data = array( 'text' => __( 'Menu Name', 'ekwa' ), 'tag' => 'p' );
		$tag  = (string) ( $attrs['tagName'] ?? 'auto' );
		if ( 'auto' !== $tag && '' !== $tag ) {
			$data['tag'] = ekwa_banner_tag( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div' ), 'p' );
		}
	} else {
		$data = ekwa_banner_title_data( $attrs );
	}

	if ( '' === trim( $data['text'] ) ) {
		return '';
	}

	// The author's inlineStyle goes THROUGH get_block_wrapper_attributes(), not
	// alongside it: the block supports (color, typography, spacing) generate a
	// style attribute of their own, and emitting a second one would leave the
	// browser silently ignoring everything in it.
	$wrapper_attrs = get_block_wrapper_attributes( array(
		'class' => 'ekwa-banner-title' . ( $is_rest && ! is_singular() ? ' ekwa-banner-title--preview' : '' ),
		'style' => function_exists( 'ekwa_inline_style_value' ) ? ekwa_inline_style_value( $attrs ) : '',
	) );

	$out  = '<' . $data['tag'] . ' ' . $wrapper_attrs;
	if ( function_exists( 'ekwa_render_custom_attributes' ) ) {
		$out .= ekwa_render_custom_attributes( $attrs );
	}
	$out .= '>' . esc_html( $data['text'] ) . '</' . $data['tag'] . '>';

	return $out;
}


/* ====================================================================
 * ekwa/breadcrumb
 * ==================================================================== */

/**
 * Build the breadcrumb trail ourselves.
 *
 * Used when no SEO plugin is available, when the author pins the provider to
 * "builtin", and whenever a custom HTML template is set (a template describes
 * OUR item loop — an SEO plugin's markup is the plugin's to own).
 *
 * @param array $attrs Block attributes.
 * @return array<int,array{url:string,label:string,current:bool}>
 */
function ekwa_breadcrumb_items( array $attrs ) {
	$items = array();

	$show_home = ! isset( $attrs['showHome'] ) || (bool) $attrs['showHome'];
	if ( $show_home ) {
		$home = trim( (string) ( $attrs['homeLabel'] ?? '' ) );
		$items[] = array(
			'url'     => home_url( '/' ),
			'label'   => '' !== $home ? $home : __( 'Home', 'ekwa' ),
			'current' => false,
		);
	}

	$post_id = (int) get_the_ID();
	if ( ! $post_id ) {
		return $items;
	}

	// Ancestors, outermost first. get_post_ancestors() returns them child-to-root.
	foreach ( array_reverse( get_post_ancestors( $post_id ) ) as $ancestor_id ) {
		$items[] = array(
			'url'     => (string) get_permalink( $ancestor_id ),
			'label'   => ekwa_banner_breadcrumb_label( $ancestor_id ),
			'current' => false,
		);
	}

	$source = ekwa_banner_tag(
		$attrs['currentSource'] ?? 'auto',
		array( 'auto', 'breadcrumb-title', 'menu', 'page' ),
		'auto'
	);
	switch ( $source ) {
		case 'menu':
			$label = ekwa_banner_menu_name( $post_id );
			break;
		case 'page':
			$label = (string) get_the_title( $post_id );
			break;
		default:
			// auto and breadcrumb-title are the same ladder: Yoast breadcrumb
			// title → Main Menu name → page title.
			$label = ekwa_banner_breadcrumb_label( $post_id );
			break;
	}
	if ( '' === trim( $label ) ) {
		$label = (string) get_the_title( $post_id );
	}

	$items[] = array(
		'url'     => (string) get_permalink( $post_id ),
		'label'   => $label,
		'current' => true,
	);

	return $items;
}

/**
 * The separator markup for a breadcrumb block.
 *
 * @param array $attrs Block attributes.
 * @return string Already-escaped HTML.
 */
function ekwa_breadcrumb_separator_html( array $attrs ) {
	$type = ekwa_banner_tag( $attrs['separatorType'] ?? 'text', array( 'text', 'icon' ), 'text' );

	if ( 'icon' === $type ) {
		$icon = trim( (string) ( $attrs['separatorIcon'] ?? '' ) );
		$icon = '' !== $icon ? $icon : 'fa-solid fa-angle-right';
		return '<span class="ekwa-breadcrumb__sep" aria-hidden="true"><i class="'
			. esc_attr( $icon ) . '"></i></span>';
	}

	$sep = (string) ( $attrs['separator'] ?? '' );
	if ( '' === trim( $sep ) ) {
		$sep = '»';
	}
	return '<span class="ekwa-breadcrumb__sep" aria-hidden="true">' . esc_html( $sep ) . '</span>';
}

/**
 * Which breadcrumb provider to use.
 *
 * @param array $attrs Block attributes.
 * @return string 'yoast' | 'rankmath' | 'builtin'
 */
function ekwa_breadcrumb_provider( array $attrs ) {
	$requested = ekwa_banner_tag(
		$attrs['provider'] ?? 'auto',
		array( 'auto', 'yoast', 'rankmath', 'builtin' ),
		'auto'
	);

	// A custom HTML template describes our own {{#items}} loop, so it can only be
	// filled by the builtin trail — an SEO plugin hands back finished markup.
	if ( ! empty( $attrs['customTemplate'] ) ) {
		return 'builtin';
	}

	if ( 'yoast' === $requested ) {
		return function_exists( 'yoast_breadcrumb' ) ? 'yoast' : 'builtin';
	}
	if ( 'rankmath' === $requested ) {
		return function_exists( 'rank_math_the_breadcrumbs' ) ? 'rankmath' : 'builtin';
	}
	if ( 'builtin' === $requested ) {
		return 'builtin';
	}

	if ( function_exists( 'yoast_breadcrumb' ) ) {
		return 'yoast';
	}
	if ( function_exists( 'rank_math_the_breadcrumbs' ) ) {
		return 'rankmath';
	}
	return 'builtin';
}

/**
 * Yoast's own breadcrumb HTML, with this block's separator applied.
 *
 * Yoast renders its separator from its own site-wide setting, so the block's
 * separator has to be pushed in through the filter for the duration of the call
 * — otherwise choosing a separator here would silently do nothing on any site
 * running Yoast, which is most of them.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_breadcrumb_yoast_html( array $attrs ) {
	$type = ekwa_banner_tag( $attrs['separatorType'] ?? 'text', array( 'text', 'icon' ), 'text' );
	$sep  = 'icon' === $type
		? '<i class="' . esc_attr( trim( (string) ( $attrs['separatorIcon'] ?? 'fa-solid fa-angle-right' ) ) ) . '" aria-hidden="true"></i>'
		: esc_html( '' !== trim( (string) ( $attrs['separator'] ?? '' ) ) ? (string) $attrs['separator'] : '»' );

	$filter = static function () use ( $sep ) {
		return $sep;
	};

	add_filter( 'wpseo_breadcrumb_separator', $filter, 99 );
	$html = yoast_breadcrumb( '<span class="ekwa-breadcrumb__list">', '</span>', false );
	remove_filter( 'wpseo_breadcrumb_separator', $filter, 99 );

	return (string) $html;
}

/**
 * Server-side render callback for the ekwa/breadcrumb block.
 *
 * @param array $attrs Block attributes.
 * @return string
 */
function ekwa_render_breadcrumb_block( $attrs ) {
	$attrs   = is_array( $attrs ) ? $attrs : array();
	$is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;

	$aria = trim( (string) ( $attrs['ariaLabel'] ?? '' ) );
	$aria = '' !== $aria ? $aria : __( 'Breadcrumb', 'ekwa' );

	// inlineStyle is merged into the wrapper's style rather than appended as a
	// second style attribute — see the note in ekwa_render_banner_title_block().
	$wrapper_attrs = get_block_wrapper_attributes( array(
		'class' => 'ekwa-breadcrumb',
		'style' => function_exists( 'ekwa_inline_style_value' ) ? ekwa_inline_style_value( $attrs ) : '',
	) );

	$open = '<nav ' . $wrapper_attrs . ' aria-label="' . esc_attr( $aria ) . '"';
	if ( function_exists( 'ekwa_render_custom_attributes' ) ) {
		$open .= ekwa_render_custom_attributes( $attrs );
	}
	$open .= '>';

	// Editor preview inside a template — no post context to build a real trail.
	if ( ! is_singular() && $is_rest ) {
		$sep = ekwa_breadcrumb_separator_html( $attrs );
		return $open . '<span class="ekwa-breadcrumb__list">'
			. '<a class="ekwa-breadcrumb__item" href="#">' . esc_html__( 'Home', 'ekwa' ) . '</a>'
			. $sep
			. '<span class="ekwa-breadcrumb__item is-current">' . esc_html__( 'Sample Page Title', 'ekwa' ) . '</span>'
			. '</span></nav>';
	}

	if ( ! is_singular() ) {
		return '';
	}

	$provider = ekwa_breadcrumb_provider( $attrs );

	if ( 'yoast' === $provider ) {
		$html = ekwa_breadcrumb_yoast_html( $attrs );
		return '' !== trim( $html ) ? $open . $html . '</nav>' : '';
	}

	if ( 'rankmath' === $provider ) {
		ob_start();
		rank_math_the_breadcrumbs();
		$html = (string) ob_get_clean();
		return '' !== trim( $html ) ? $open . $html . '</nav>' : '';
	}

	$items = ekwa_breadcrumb_items( $attrs );
	if ( count( $items ) < 2 ) {
		return '';
	}

	$sep          = ekwa_breadcrumb_separator_html( $attrs );
	$link_current = ! empty( $attrs['linkCurrent'] );

	$html = '<span class="ekwa-breadcrumb__list">';
	foreach ( $items as $i => $item ) {
		if ( $i > 0 ) {
			$html .= $sep;
		}
		if ( $item['current'] && ! $link_current ) {
			$html .= '<span class="ekwa-breadcrumb__item is-current" aria-current="page">'
				. esc_html( $item['label'] ) . '</span>';
		} else {
			$html .= '<a class="ekwa-breadcrumb__item' . ( $item['current'] ? ' is-current' : '' ) . '"'
				. ( $item['current'] ? ' aria-current="page"' : '' )
				. ' href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a>';
		}
	}
	$html .= '</span>';

	// BreadcrumbList schema is opt-in and off by default: Yoast already emits one
	// on most of these sites, and two competing graphs is worse than none.
	if ( ! empty( $attrs['emitSchema'] ) ) {
		$list = array();
		foreach ( $items as $i => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['label'],
				'item'     => $item['url'],
			);
		}
		$html .= '<script type="application/ld+json">' . wp_json_encode( array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list,
		) ) . '</script>';
	}

	return $open . $html . '</nav>';
}


/* ====================================================================
 * Fresh-install seeding of the page template
 * ==================================================================== */

/**
 * Give brand-new installs the new banner in their page template, without moving
 * a single existing site onto it.
 *
 * templates/page.html is one file, and WordPress reads it for every site that
 * has not customised the page template in the Site Editor. Editing it to use
 * ekwa/page-banner would therefore change existing sites on a theme *update*,
 * which is exactly what we're avoiding — so the file keeps the legacy blocks and
 * a fresh install gets the new composition seeded as a saved template instead.
 *
 * A saved wp_template post is the same thing the Site Editor writes when you
 * edit a template: it takes precedence over the theme file, shows up in the Site
 * Editor with a "Customized" badge, and can be reverted from there in one click.
 * Nothing is hidden or irreversible.
 *
 * "Fresh install" is decided on three signals, all of which have to agree:
 *
 *   1. The hook is after_switch_theme — it fires when the theme is ACTIVATED.
 *      A theme update never fires it, so an existing site updating cannot reach
 *      this code at all. This is the signal doing most of the work.
 *   2. No seed marker yet, so re-activating never re-seeds (and never overwrites
 *      a template the user has since edited).
 *   3. The site looks new: no saved page template already, and no more published
 *      pages than a stock WordPress install ships with. This is what stops an
 *      established site that happens to switch away and back from being treated
 *      as new.
 *
 * Filterable end to end: return false from `ekwa_seed_page_banner_template` to
 * opt out entirely.
 */
function ekwa_banner_seed_page_template() {
	if ( get_option( 'ekwa_page_banner_seeded' ) ) {
		return;
	}

	// Mark it done regardless of the outcome below — this should be attempted
	// exactly once per site, never retried on every activation.
	update_option( 'ekwa_page_banner_seeded', 1, false );

	if ( ! apply_filters( 'ekwa_seed_page_banner_template', true ) ) {
		return;
	}

	$theme = get_stylesheet();

	// Already-customised page template → the site owns it; leave it alone.
	$existing = get_posts( array(
		'post_type'      => 'wp_template',
		'post_status'    => array( 'publish', 'draft', 'auto-draft' ),
		'name'           => 'page',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			array(
				'taxonomy' => 'wp_theme',
				'field'    => 'name',
				'terms'    => $theme,
			),
		),
	) );
	if ( ! empty( $existing ) ) {
		return;
	}

	// A stock WordPress install publishes "Sample Page" and "Privacy Policy".
	// Anything beyond that is a site with real content, i.e. not a fresh install.
	$counts = wp_count_posts( 'page' );
	if ( $counts && (int) $counts->publish > 2 ) {
		return;
	}

	$template_id = wp_insert_post( array(
		'post_type'    => 'wp_template',
		'post_status'  => 'publish',
		'post_name'    => 'page',
		'post_title'   => __( 'Pages', 'ekwa' ),
		'post_excerpt' => __( 'Displays a single page.', 'ekwa' ),
		'post_content' => ekwa_banner_seed_page_template_content(),
	), true );

	if ( is_wp_error( $template_id ) || ! $template_id ) {
		return;
	}

	// The wp_theme term is what scopes a saved template to this theme; without
	// it WordPress will not resolve the template at all.
	wp_set_object_terms( $template_id, $theme, 'wp_theme' );
}
add_action( 'after_switch_theme', 'ekwa_banner_seed_page_template' );

/**
 * The seeded page template: templates/page.html with the legacy banner swapped
 * for the ekwa/page-banner composition.
 *
 * @return string Block markup.
 */
function ekwa_banner_seed_page_template_content() {
	return <<<'HTML'
<!-- wp:template-part {"slug":"mobile-header","area":"header"} /-->
<!-- wp:template-part {"slug":"header","area":"header"} /-->

<!-- wp:ekwa/page-banner {"align":"full","minHeight":260,"overlayOpacity":45,"style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl","left":"20px","right":"20px"}}},"textColor":"white"} -->
<!-- wp:ekwa/banner-title /-->
<!-- wp:ekwa/breadcrumb /-->
<!-- /wp:ekwa/page-banner -->

<!-- wp:group {"tagName":"main","layout":{"type":"default"}} -->
<main class="wp-block-group">

	<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|xl","bottom":"var:preset|spacing|xl"}}},"layout":{"type":"constrained"}} -->
	<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--xl);padding-bottom:var(--wp--preset--spacing--xl)">
		<!-- wp:ekwa/page-title /-->
		<!-- wp:post-content {"layout":{"type":"constrained"}} /-->
	</div>
	<!-- /wp:group -->

</main>
<!-- /wp:group -->

<!-- wp:template-part {"slug":"footer","area":"footer"} /-->
<!-- wp:template-part {"slug":"mobile-footer","area":"uncategorized"} /-->
HTML;
}
