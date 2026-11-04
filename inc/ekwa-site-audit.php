<?php
/**
 * Site Audit — the admin menu that grew out of the "Page Speed" dashboard.
 *
 * Menu (top level "Site Audit", slug ekwa-page-audit — the same slug and URL
 * the Page Speed dashboard always had, so bookmarks and links keep working):
 *   - PageSpeed       the PageSpeed / Accessibility / SEO dashboard
 *                     (inc/ekwa-page-audit-dashboard.php)
 *   - Content & SEO   pages without content ("coming soon"), missing Yoast SEO
 *                     titles and meta descriptions, and which pages search
 *                     engines may show (Yoast's own per-page setting)
 *   - Broken Links    every link and image in posts, pages, any post type,
 *                     templates, template parts, synced patterns and menus
 *                     (inc/ekwa-site-audit-links.php)
 *   - Image Alt Text  every image in all of those, plus the media library
 *                     (inc/ekwa-site-audit-images.php)
 *   - Spelling        AI proofreading that leaves medical terms alone
 *                     (inc/ekwa-site-audit-spelling.php)
 *
 * This file holds what they share: the menu, the page "kinds" (blog page,
 * thank-you pages, policy pages), the "has this page any content?" test, the
 * Yoast helpers, and one walker that reads blocks into text, links and images.
 *
 * Nothing here runs on the front end. Every screen only reads; the writes are
 * explicit clicks — hide/show a page in search (Yoast's noindex), exclude a
 * page from the PageSpeed dashboard, a link-check run, a spelling check, an
 * alt text saved to the media library — each one reversible from the screen.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Post meta: PageSpeed dashboard override — 'exclude' or 'include' (absent = automatic). */
const EKWA_SITE_AUDIT_META_EXCLUDE = '_ekwa_page_audit_exclude';

/** Post meta: set when Site Audit hid a page from search — {prev, t, user, reason}. */
const EKWA_SITE_AUDIT_META_HIDDEN = '_ekwa_site_audit_noindex';

/** Yoast SEO post meta keys (read; noindex also written, only on a click). */
const EKWA_SITE_AUDIT_YOAST_NOINDEX = '_yoast_wpseo_meta-robots-noindex';
const EKWA_SITE_AUDIT_YOAST_TITLE   = '_yoast_wpseo_title';
const EKWA_SITE_AUDIT_YOAST_DESC    = '_yoast_wpseo_metadesc';

// ═══════════════════════════════════════════════════════════════════════════════
// MENU
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * The Site Audit screens besides PageSpeed, in menu order.
 *
 * @return array<string,array{title:string,menu:string,view:string}>
 */
function ekwa_site_audit_screens() {
	return array(
		'ekwa-site-audit-content'  => array(
			'title' => __( 'Content & SEO', 'ekwa' ),
			'menu'  => __( 'Content & SEO', 'ekwa' ),
			'view'  => 'content',
		),
		'ekwa-site-audit-links'    => array(
			'title' => __( 'Broken Links', 'ekwa' ),
			'menu'  => __( 'Broken Links', 'ekwa' ),
			'view'  => 'links',
		),
		'ekwa-site-audit-images'   => array(
			'title' => __( 'Image Alt Text', 'ekwa' ),
			'menu'  => __( 'Image Alt Text', 'ekwa' ),
			'view'  => 'images',
		),
		'ekwa-site-audit-spelling' => array(
			'title' => __( 'Spelling', 'ekwa' ),
			'menu'  => __( 'Spelling', 'ekwa' ),
			'view'  => 'spelling',
		),
	);
}

/**
 * URL of a Site Audit screen.
 *
 * @param string $view pagespeed|content|links|images|spelling
 * @return string
 */
function ekwa_site_audit_url( $view ) {
	if ( 'pagespeed' === $view ) {
		return ekwa_page_audit_dashboard_url();
	}
	foreach ( ekwa_site_audit_screens() as $slug => $screen ) {
		if ( $screen['view'] === $view ) {
			return admin_url( 'admin.php?page=' . $slug );
		}
	}
	return ekwa_page_audit_dashboard_url();
}

/**
 * Register the submenus (after the top level and its "PageSpeed" entry).
 */
function ekwa_site_audit_menu() {
	$GLOBALS['ekwa_site_audit_hooks'] = array();
	foreach ( ekwa_site_audit_screens() as $slug => $screen ) {
		$hook = add_submenu_page( 'ekwa-page-audit', $screen['title'], $screen['menu'], ekwa_page_audit_dashboard_cap(), $slug, 'ekwa_site_audit_render' );
		if ( $hook ) {
			$GLOBALS['ekwa_site_audit_hooks'][ $hook ] = $screen['view'];
		}
	}
}
add_action( 'admin_menu', 'ekwa_site_audit_menu', 11 );

/**
 * The view for the current screen, or '' when this is not a Site Audit screen.
 *
 * @param string $hook
 * @return string
 */
function ekwa_site_audit_view_for_hook( $hook ) {
	$hooks = isset( $GLOBALS['ekwa_site_audit_hooks'] ) && is_array( $GLOBALS['ekwa_site_audit_hooks'] ) ? $GLOBALS['ekwa_site_audit_hooks'] : array();
	return isset( $hooks[ $hook ] ) ? $hooks[ $hook ] : '';
}

/**
 * Render a screen shell; the app is assets/js/ekwa-site-audit.js.
 */
function ekwa_site_audit_render() {
	$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$screens = ekwa_site_audit_screens();
	$title   = isset( $screens[ $page ] ) ? $screens[ $page ]['title'] : __( 'Site Audit', 'ekwa' );
	?>
	<div class="wrap ekwa-pad-wrap">
		<h1 class="wp-heading-inline"><?php echo esc_html( $title ); ?></h1>
		<div id="ekwa-sa-app" class="ekwa-pad ekwa-sa"><p><span class="spinner is-active" style="float:none;"></span> <?php esc_html_e( 'Loading…', 'ekwa' ); ?></p></div>
		<noscript><p><?php esc_html_e( 'This screen needs JavaScript.', 'ekwa' ); ?></p></noscript>
	</div>
	<?php
}

/**
 * Assets for the Site Audit screens.
 *
 * @param string $hook
 */
function ekwa_site_audit_enqueue( $hook ) {
	$view = ekwa_site_audit_view_for_hook( $hook );
	if ( '' === $view ) {
		return;
	}
	$base = get_template_directory();
	$uri  = get_template_directory_uri();
	$js   = 'assets/js/ekwa-site-audit.js';

	wp_enqueue_style( 'wp-components' );
	wp_enqueue_style( 'ekwa-page-audit-dashboard', $uri . '/assets/css/ekwa-page-audit-dashboard.css', array( 'wp-components' ), filemtime( $base . '/assets/css/ekwa-page-audit-dashboard.css' ) );
	wp_enqueue_style( 'ekwa-site-audit', $uri . '/assets/css/ekwa-site-audit.css', array( 'ekwa-page-audit-dashboard' ), filemtime( $base . '/assets/css/ekwa-site-audit.css' ) );
	wp_enqueue_script( 'ekwa-site-audit', $uri . '/' . $js, array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ), filemtime( $base . '/' . $js ), true );

	// The MCP connector relays AI requests through the browser: the spelling
	// check and the alt-text suggestions need its client on their screen.
	if ( in_array( $view, array( 'spelling', 'images' ), true ) && function_exists( 'ekwa_ai_relay_client_wanted' ) && ekwa_ai_relay_client_wanted() ) {
		ekwa_ai_relay_client_enqueue( array( 'wp-api-fetch' ) );
	}

	$ai_cap = function_exists( 'ekwa_ai_min_capability' ) ? ekwa_ai_min_capability() : 'edit_posts';
	wp_localize_script( 'ekwa-site-audit', 'ekwaSiteAudit', array(
		'view'          => $view,
		'yoast'         => ekwa_site_audit_yoast_active(),
		'blogPublic'    => '0' !== (string) get_option( 'blog_public', '1' ),
		'readingUrl'    => admin_url( 'options-reading.php' ),
		'canManage'     => current_user_can( 'manage_options' ),
		'canUpload'     => current_user_can( 'upload_files' ),
		'ai'            => function_exists( 'ekwa_ai_is_configured' ) && ekwa_ai_is_configured() && current_user_can( $ai_cap ),
		'aiProvider'    => function_exists( 'ekwa_ai_effective_provider' ) ? ekwa_ai_effective_provider() : 'gemini',
		'aiSettingsUrl' => admin_url( 'themes.php?page=ekwa-settings&ekwa_tab=ai' ),
		'home'          => home_url( '/' ),
		'urls'          => array(
			'pagespeed' => ekwa_site_audit_url( 'pagespeed' ),
			'content'   => ekwa_site_audit_url( 'content' ),
			'links'     => ekwa_site_audit_url( 'links' ),
			'images'    => ekwa_site_audit_url( 'images' ),
			'spelling'  => ekwa_site_audit_url( 'spelling' ),
		),
	) );
}
add_action( 'admin_enqueue_scripts', 'ekwa_site_audit_enqueue' );

// ═══════════════════════════════════════════════════════════════════════════════
// PAGE KINDS (blog page, thank-you pages, policy pages)
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Slugs of policy pages (exact match).
 *
 * @return string[]
 */
function ekwa_site_audit_policy_slugs() {
	$slugs = array(
		'accessibility-statement', 'accessibility',
		'privacy-policy', 'privacy', 'privacy-notice', 'privacy-practices', 'notice-of-privacy-practices',
		'cookie-policy', 'cookies-policy', 'cookie-notice', 'cookies',
		'hipaa-policy', 'hippa-policy', 'hipaa-notice', 'hippa-notice', 'hipaa', 'hippa', 'hipaa-privacy-policy', 'hipaa-compliance',
		'terms-of-use', 'terms-and-conditions', 'terms-of-service', 'terms-conditions', 'terms',
	);
	/**
	 * Filter the slugs treated as policy pages (left out of the PageSpeed
	 * dashboard by default, and suggested to hide from search engines).
	 *
	 * @param string[] $slugs
	 */
	return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'ekwa_site_audit_policy_slugs', $slugs ) ) ) );
}

/**
 * What kind of page this is, for the audit: 'blog' (the posts page),
 * 'thank-you' (where a form redirects after submit), 'policy', or ''.
 *
 * @param WP_Post|int $post
 * @return string
 */
function ekwa_site_audit_page_kind( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	$kind = '';
	$slug = strtolower( rawurldecode( (string) $post->post_name ) );

	if ( 'page' === $post->post_type && (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
		$kind = 'blog';
	} elseif ( '' !== $slug && preg_match( '/(^|-)(thank-?you|thanks)(-|$)/', $slug ) ) {
		// thank-you, thank-you-contact, thank-you-apt, thank-you-appointment, thankyou…
		$kind = 'thank-you';
	} elseif ( '' !== $slug && ( in_array( $slug, ekwa_site_audit_policy_slugs(), true ) || (int) get_option( 'wp_page_for_privacy_policy' ) === (int) $post->ID ) ) {
		$kind = 'policy';
	}

	/**
	 * Filter a page's audit kind.
	 *
	 * @param string  $kind 'blog', 'thank-you', 'policy' or ''.
	 * @param WP_Post $post
	 */
	$kind = (string) apply_filters( 'ekwa_site_audit_page_kind', $kind, $post );
	return in_array( $kind, array( 'blog', 'thank-you', 'policy' ), true ) ? $kind : '';
}

/**
 * Labels for the kinds.
 *
 * @return array<string,string>
 */
function ekwa_site_audit_kind_labels() {
	return array(
		'blog'      => __( 'Blog page', 'ekwa' ),
		'thank-you' => __( 'Thank-you page', 'ekwa' ),
		'policy'    => __( 'Policy page', 'ekwa' ),
	);
}

/**
 * The PageSpeed-dashboard override of a page ('exclude', 'include' or '').
 *
 * @param int $post_id
 * @return string
 */
function ekwa_site_audit_exclude_override( $post_id ) {
	$value = (string) get_post_meta( $post_id, EKWA_SITE_AUDIT_META_EXCLUDE, true );
	return in_array( $value, array( 'exclude', 'include' ), true ) ? $value : '';
}

// ═══════════════════════════════════════════════════════════════════════════════
// READING BLOCKS: text, links, images, "is there any content?"
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Block names by role, for the content test.
 *   layout  — wrappers: their inner blocks count, not they themselves
 *   text    — blocks whose content is their text (counted as words)
 *   chrome  — page furniture: title, banner, breadcrumb, buttons, spacers —
 *             not content, and their text does not count as words
 * Everything else (images, videos, maps, FAQs, forms, shortcodes, phone and
 * address blocks, any third-party block) counts as content.
 *
 * @return array{layout:string[],text:string[],chrome:string[]}
 */
function ekwa_site_audit_block_sets() {
	static $sets = null;
	if ( null !== $sets ) {
		return $sets;
	}
	$sets = array(
		'layout' => array(
			'core/group', 'core/columns', 'core/column', 'core/row', 'core/stack', 'core/buttons',
			'ekwa/section', 'ekwa/div', 'ekwa/container', 'ekwa/flex', 'ekwa/grid', 'ekwa/reveal', 'ekwa/reveal-hidden',
			'ekwa/conditional', 'ekwa/button-group',
		),
		'text'   => array(
			'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/pullquote',
			'core/preformatted', 'core/verse', 'core/table', 'core/details', 'core/code', 'ekwa/text',
		),
		'chrome' => array(
			'ekwa/page-title', 'ekwa/banner-title', 'ekwa/breadcrumb', 'ekwa/inner-banner', 'ekwa/page-banner',
			'core/post-title', 'core/spacer', 'core/separator', 'ekwa/read-time', 'ekwa/share-button',
			'ekwa/back-to-category', 'ekwa/scroll-top', 'core/button', 'ekwa/button', 'ekwa/icon', 'ekwa/svg',
			'core/post-featured-image', 'core/post-date', 'core/post-author', 'core/post-terms',
		),
	);
	/**
	 * Filter which blocks are layout, text or page furniture for the
	 * "does this page have content?" test.
	 *
	 * @param array $sets
	 */
	$filtered = apply_filters( 'ekwa_site_audit_block_sets', $sets );
	if ( is_array( $filtered ) ) {
		foreach ( array( 'layout', 'text', 'chrome' ) as $k ) {
			$sets[ $k ] = isset( $filtered[ $k ] ) && is_array( $filtered[ $k ] ) ? array_values( array_map( 'strval', $filtered[ $k ] ) ) : $sets[ $k ];
		}
	}
	return $sets;
}

/**
 * Visible text of an HTML fragment: no tags, scripts, styles, comments or
 * shortcodes; block-level tags become line breaks so words don't run together.
 *
 * @param string $html
 * @return string
 */
function ekwa_site_audit_html_text( $html ) {
	$html = (string) $html;
	if ( '' === trim( $html ) ) {
		return '';
	}
	$html = preg_replace( '#<(script|style|noscript|template|svg|iframe)\b[^>]*>.*?</\1\s*>#is', ' ', $html );
	$html = preg_replace( '/<!--.*?-->/s', ' ', $html );
	$html = preg_replace( '#<(br|hr)\b[^>]*>|</(p|div|li|h[1-6]|td|th|tr|dt|dd|figcaption|blockquote|section|article|summary|details|ul|ol|table)\s*>#i', "\n", $html );
	$html = preg_replace( '#<(p|div|li|h[1-6]|td|th|dt|dd)\b[^>]*>#i', "\n", $html );
	$text = wp_strip_all_tags( $html );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/\[\/?[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/i', ' ', $text ); // shortcodes
	$text = preg_replace( '/[ \t\x{00A0}\x{200B}]+/u', ' ', (string) $text );
	$text = preg_replace( '/\s*\n\s*/', "\n", (string) $text );
	return trim( (string) $text );
}

/**
 * Words in a text (letters/digits runs).
 *
 * @param string $text
 * @return int
 */
function ekwa_site_audit_word_count( $text ) {
	return (int) preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\'’-]*/u', (string) $text );
}

/**
 * One attribute's value from an HTML tag's attribute string; null when absent.
 *
 * @param string $attrs e.g. ' href="/a" class="b"'
 * @param string $name
 * @return string|null
 */
function ekwa_site_audit_tag_attr( $attrs, $name ) {
	if ( ! preg_match( '/(?:^|\s)' . preg_quote( $name, '/' ) . '(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+)))?(?=[\s\/>]|$)/i', (string) $attrs, $m ) ) {
		return null;
	}
	if ( isset( $m[3] ) && '' !== $m[3] ) {
		$value = $m[3];
	} elseif ( isset( $m[2] ) && '' !== $m[2] ) {
		$value = $m[2];
	} else {
		$value = isset( $m[1] ) ? $m[1] : '';
	}
	return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

/**
 * Text kept in block attributes (Ekwa blocks keep their text there).
 *
 * @param array $attrs
 * @return string[]
 */
function ekwa_site_audit_attr_texts( $attrs ) {
	static $keys = array( 'text', 'content', 'title', 'heading', 'subtitle', 'subheading', 'label', 'caption', 'description', 'question', 'answer', 'buttonText', 'linkText', 'tagline', 'eyebrow' );
	$out = array();
	foreach ( $keys as $key ) {
		if ( empty( $attrs[ $key ] ) || ! is_string( $attrs[ $key ] ) ) {
			continue;
		}
		$value = trim( $attrs[ $key ] );
		if ( preg_match( '#^(https?:|/|\#|var\()#i', $value ) || false !== strpbrk( $value, '{}' ) ) {
			continue;
		}
		$text = ekwa_site_audit_html_text( $value );
		if ( '' !== $text && preg_match( '/\p{L}/u', $text ) ) {
			$out[] = $text;
		}
	}
	return $out;
}

/**
 * Read content (block markup or classic HTML) into text, links and images.
 *
 * @param string $content
 * @return array{segments:array[],links:array[],images:array[],media:int,words:int}
 */
function ekwa_site_audit_parse( $content ) {
	$out = array(
		'segments' => array(),
		'links'    => array(),
		'images'   => array(),
		'media'    => 0,
		'words'    => 0,
	);
	$content = (string) $content;
	if ( '' === trim( $content ) ) {
		return $out;
	}
	ekwa_site_audit_walk( parse_blocks( $content ), $out, false, 0 );
	return $out;
}

/**
 * Walk blocks (recursive) — see ekwa_site_audit_parse().
 *
 * @param array $blocks
 * @param array $out    By reference.
 * @param bool  $chrome Inside page furniture.
 * @param int   $depth
 */
function ekwa_site_audit_walk( $blocks, &$out, $chrome, $depth ) {
	if ( $depth > 60 || ! is_array( $blocks ) ) {
		return;
	}
	$sets = ekwa_site_audit_block_sets();
	foreach ( $blocks as $block ) {
		if ( ! is_array( $block ) ) {
			continue;
		}
		$name      = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		$attrs     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$html      = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
		$is_chrome = $chrome || in_array( $name, $sets['chrome'], true );
		$label     = '' === $name ? 'core/freeform' : $name;

		$text = ekwa_site_audit_html_text( $html );
		foreach ( ekwa_site_audit_attr_texts( $attrs ) as $piece ) {
			$text .= ( '' === $text ? '' : "\n" ) . $piece;
		}
		if ( '' !== $text ) {
			$out['segments'][] = array(
				'b' => $label,
				't' => $text,
				'c' => $is_chrome,
			);
			if ( ! $is_chrome ) {
				$out['words'] += ekwa_site_audit_word_count( $text );
			}
		}

		if ( ! $is_chrome ) {
			if ( '' !== $name && ! in_array( $name, $sets['layout'], true ) && ! in_array( $name, $sets['text'], true ) ) {
				++$out['media'];
			} elseif ( preg_match( '/<(img|iframe|video|audio|embed|object|form)\b/i', $html ) || preg_match( '/\[[a-z][a-z0-9_-]*[\s\]\/]/i', $html ) ) {
				++$out['media'];
			}
		}

		ekwa_site_audit_html_links( $html, $label, $out );
		ekwa_site_audit_attr_links( $name, $attrs, $out );
		ekwa_site_audit_html_images( $html, $label, $attrs, $out );
		ekwa_site_audit_attr_images( $name, $attrs, $out );

		if ( ! empty( $block['innerBlocks'] ) ) {
			ekwa_site_audit_walk( $block['innerBlocks'], $out, $is_chrome, $depth + 1 );
		}
	}
}

/**
 * <a href> links in a block's own HTML.
 *
 * @param string $html
 * @param string $block
 * @param array  $out By reference.
 */
function ekwa_site_audit_html_links( $html, $block, &$out ) {
	if ( false === stripos( $html, '<a' ) || ! preg_match_all( '#<a\b([^>]*)>(.*?)(?=</a\s*>|<a\b|$)#is', $html, $m, PREG_SET_ORDER ) ) {
		return;
	}
	foreach ( $m as $a ) {
		$href = ekwa_site_audit_tag_attr( $a[1], 'href' );
		if ( null === $href ) {
			continue;
		}
		$text = ekwa_site_audit_html_text( $a[2] );
		if ( '' === $text ) {
			$text = (string) ekwa_site_audit_tag_attr( $a[1], 'aria-label' );
		}
		if ( '' === $text && preg_match( '#<img\b([^>]*)>#i', $a[2], $img ) ) {
			$alt  = (string) ekwa_site_audit_tag_attr( $img[1], 'alt' );
			$text = '' !== $alt ? sprintf( /* translators: %s: alt text */ __( 'Image: %s', 'ekwa' ), $alt ) : __( '(image)', 'ekwa' );
		}
		$out['links'][] = array(
			'u' => $href,
			't' => ekwa_site_audit_clip( $text, 90 ),
			'b' => $block,
			'k' => 'link',
		);
	}
}

/**
 * Links kept in block attributes: Ekwa blocks (Link Source: external URL,
 * a chosen page, a media file, the appointment link) and core blocks whose
 * URL lives in the block comment (navigation links, social links, embeds).
 *
 * @param string $name
 * @param array  $attrs
 * @param array  $out By reference.
 */
function ekwa_site_audit_attr_links( $name, $attrs, &$out ) {
	if ( ! $attrs || '' === $name ) {
		return;
	}
	$text = '';
	foreach ( array( 'text', 'label', 'title', 'buttonText', 'linkText' ) as $key ) {
		if ( ! empty( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) {
			$text = ekwa_site_audit_html_text( $attrs[ $key ] );
			break;
		}
	}
	$text = ekwa_site_audit_clip( $text, 90 );

	if ( 0 === strpos( $name, 'ekwa/' ) ) {
		if ( ! isset( $attrs['linkType'] ) && empty( $attrs['url'] ) && empty( $attrs['href'] ) && empty( $attrs['linkUrl'] ) ) {
			return;
		}
		$type = isset( $attrs['linkType'] ) ? sanitize_key( $attrs['linkType'] ) : 'external';
		if ( 'internal' === $type ) {
			$pid = isset( $attrs['pageId'] ) ? absint( $attrs['pageId'] ) : 0;
			if ( $pid ) {
				$out['links'][] = array( 'u' => '', 'pid' => $pid, 't' => $text, 'b' => $name, 'k' => 'link' );
			}
			return;
		}
		if ( 'media' === $type ) {
			$mid = isset( $attrs['mediaFileId'] ) ? absint( $attrs['mediaFileId'] ) : 0;
			$url = $mid ? (string) wp_get_attachment_url( $mid ) : '';
			if ( '' === $url && ! empty( $attrs['mediaUrl'] ) ) {
				$url = (string) $attrs['mediaUrl'];
			}
			if ( '' !== $url ) {
				$out['links'][] = array( 'u' => $url, 't' => $text, 'b' => $name, 'k' => 'link' );
			}
			return;
		}
		if ( 'appointment' === $type ) {
			return; // One site setting — checked once, as "Ekwa Settings → appointment link".
		}
		// External, with the legacy fallbacks the renderers use (linkUrl, href).
		$url = ! empty( $attrs['url'] ) ? $attrs['url'] : ( ! empty( $attrs['linkUrl'] ) ? $attrs['linkUrl'] : ( ! empty( $attrs['href'] ) ? $attrs['href'] : '' ) );
		if ( is_string( $url ) && '' !== trim( $url ) ) {
			$out['links'][] = array( 'u' => $url, 't' => $text, 'b' => $name, 'k' => 'link' );
		}
		return;
	}

	if ( in_array( $name, array( 'core/cover', 'core/image', 'core/video', 'core/audio', 'core/media-text', 'core/file', 'core/site-logo' ), true ) ) {
		return; // Their URL attribute is the media itself (handled as an image, or in the HTML).
	}
	foreach ( array( 'url', 'href', 'linkUrl' ) as $key ) {
		if ( ! empty( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) ) {
			$out['links'][] = array( 'u' => $attrs[ $key ], 't' => $text, 'b' => $name, 'k' => 'link' );
			return;
		}
	}
}

/**
 * <img> tags in a block's own HTML.
 *
 * @param string $html
 * @param string $block
 * @param array  $attrs Block attributes (core/image keeps the attachment id there).
 * @param array  $out   By reference.
 */
function ekwa_site_audit_html_images( $html, $block, $attrs, &$out ) {
	if ( false === stripos( $html, '<img' ) || ! preg_match_all( '#<img\b([^>]*)>#i', $html, $m ) ) {
		return;
	}
	foreach ( $m[1] as $tag ) {
		$src = ekwa_site_audit_tag_attr( $tag, 'src' );
		if ( null === $src || '' === trim( $src ) || 0 === strpos( trim( $src ), 'data:' ) ) {
			$lazy = ekwa_site_audit_tag_attr( $tag, 'data-src' );
			$src  = null !== $lazy ? $lazy : $src;
		}
		if ( null === $src || '' === trim( $src ) ) {
			continue;
		}
		$id    = 0;
		$class = (string) ekwa_site_audit_tag_attr( $tag, 'class' );
		if ( preg_match( '/\bwp-image-(\d+)\b/', $class, $cm ) ) {
			$id = (int) $cm[1];
		} elseif ( 'core/image' === $block && ! empty( $attrs['id'] ) ) {
			$id = absint( $attrs['id'] );
		}
		$out['images'][] = array(
			'src' => trim( $src ),
			'alt' => ekwa_site_audit_tag_attr( $tag, 'alt' ),
			'b'   => $block,
			'id'  => $id,
		);
	}
}

/**
 * Images kept in block attributes: ekwa/image (alt in the attributes), and
 * background / poster images (no alt — decorative — but a missing file is a
 * broken image, so they go to the link check).
 *
 * @param string $name
 * @param array  $attrs
 * @param array  $out By reference.
 */
function ekwa_site_audit_attr_images( $name, $attrs, &$out ) {
	if ( ! $attrs ) {
		return;
	}
	if ( 'ekwa/image' === $name && ! empty( $attrs['src'] ) && is_string( $attrs['src'] ) ) {
		$out['images'][] = array(
			'src' => trim( $attrs['src'] ),
			'alt' => isset( $attrs['alt'] ) && is_string( $attrs['alt'] ) ? $attrs['alt'] : '',
			'b'   => $name,
			'id'  => isset( $attrs['mediaId'] ) ? absint( $attrs['mediaId'] ) : 0,
		);
	}
	$keys = array( 'bgImage', 'bgImageUrl', 'backgroundImage', 'posterUrl', 'poster', 'thumbnailUrl' );
	if ( 'core/cover' === $name ) {
		$keys[] = 'url';
	}
	foreach ( $keys as $key ) {
		if ( ! empty( $attrs[ $key ] ) && is_string( $attrs[ $key ] ) && 0 !== strpos( trim( $attrs[ $key ] ), 'data:' ) ) {
			$out['links'][] = array(
				'u' => trim( $attrs[ $key ] ),
				't' => __( 'Background image', 'ekwa' ),
				'b' => $name,
				'k' => 'image',
			);
		}
	}
}

/**
 * Shorten a string for display.
 *
 * @param string $text
 * @param int    $max
 * @return string
 */
function ekwa_site_audit_clip( $text, $max ) {
	$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
		return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
	}
	return strlen( $text ) > $max ? rtrim( substr( $text, 0, $max - 1 ) ) . '…' : $text;
}

/**
 * Does a page have content? Empty = (almost) no words outside the page
 * furniture and no content blocks (image, video, form, shortcode, map…);
 * "coming soon" = the text says so.
 *
 * @param string $content post_content
 * @return array{words:int,empty:bool,soon:bool}
 */
function ekwa_site_audit_content_state( $content ) {
	$parsed = ekwa_site_audit_parse( $content );
	$text   = '';
	foreach ( $parsed['segments'] as $seg ) {
		if ( empty( $seg['c'] ) ) {
			$text .= ' ' . $seg['t'];
		}
	}
	$pattern = (string) apply_filters( 'ekwa_site_audit_coming_soon_pattern', '/\b(coming\s+soon|under\s+construction)\b/iu' );
	$min     = (int) apply_filters( 'ekwa_site_audit_empty_words', 5 );
	return array(
		'words' => (int) $parsed['words'],
		'empty' => $parsed['words'] < $min && 0 === $parsed['media'],
		'soon'  => '' !== $pattern && (bool) @preg_match( $pattern, $text ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a filtered pattern may be invalid.
	);
}

// ═══════════════════════════════════════════════════════════════════════════════
// SOURCES — everything that holds content
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Post types scanned for links, images and spelling.
 *
 * @return string[]
 */
function ekwa_site_audit_post_types() {
	$types = get_post_types( array( 'public' => true ), 'names' );
	unset( $types['attachment'], $types['web-story'] );
	/**
	 * Filter the post types Site Audit scans (posts, pages and every public
	 * custom type; templates, parts, synced patterns and menus are added on top).
	 *
	 * @param string[] $types
	 */
	return array_values( array_filter( (array) apply_filters( 'ekwa_site_audit_post_types', array_values( $types ) ), 'post_type_exists' ) );
}

/**
 * Edit link for a post row (posts, patterns, navigation…).
 *
 * @param object $row ID, post_type, post_name.
 * @return string
 */
function ekwa_site_audit_post_edit_url( $row ) {
	if ( function_exists( 'ekwa_find_post_edit_url' ) ) {
		return ekwa_find_post_edit_url( $row );
	}
	$link = get_edit_post_link( (int) $row->ID, 'raw' );
	return $link ? $link : '';
}

/**
 * Site Editor link for a block template / template part.
 *
 * @param WP_Block_Template $template
 * @return string
 */
function ekwa_site_audit_template_edit_url( $template ) {
	return admin_url( 'site-editor.php?postType=' . rawurlencode( $template->type ) . '&postId=' . rawurlencode( $template->id ) . '&canvas=edit' );
}

/**
 * Call $fn for every source of content: published posts of every scanned
 * type, synced patterns, navigation menus (block), templates and template
 * parts (the Site Editor's saved copy when there is one, else the theme file).
 * Read-only.
 *
 * Each source: key, group (content|pattern|navigation|part|template), type,
 * typeLabel, title, status, edit, view, id, content, excerpt.
 *
 * @param callable $fn
 * @param array    $opts only (string[] keys to visit; empty = all).
 * @return int Sources visited.
 */
function ekwa_site_audit_each_source( $fn, $opts = array() ) {
	global $wpdb;

	$only  = isset( $opts['only'] ) && is_array( $opts['only'] ) ? array_flip( $opts['only'] ) : array();
	$max   = (int) apply_filters( 'ekwa_site_audit_max_sources', 3000 );
	$count = 0;

	$types = ekwa_site_audit_post_types();
	foreach ( array( 'wp_block', 'wp_navigation' ) as $extra ) {
		if ( post_type_exists( $extra ) ) {
			$types[] = $extra;
		}
	}
	$statuses = array( 'publish', 'future', 'private' );

	$ids_wanted = array();
	if ( $only ) {
		foreach ( array_keys( $only ) as $key ) {
			if ( 0 === strpos( (string) $key, 'post:' ) ) {
				$ids_wanted[] = (int) substr( (string) $key, 5 );
			}
		}
	}

	if ( $types && ( ! $only || $ids_wanted ) ) {
		$type_in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$status_in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$last      = 0;
		do {
			$where_ids = $ids_wanted ? ' AND ID IN (' . implode( ',', array_map( 'intval', $ids_wanted ) ) . ')' : '';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above; IDs are integers.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_type, post_status, post_title, post_name, post_content, post_excerpt, post_parent
				 FROM {$wpdb->posts}
				 WHERE post_type IN ({$type_in}) AND post_status IN ({$status_in}) AND ID > %d{$where_ids}
				 ORDER BY ID ASC LIMIT 200",
				array_merge( $types, $statuses, array( $last ) )
			) );
			// phpcs:enable
			foreach ( (array) $rows as $row ) {
				$last  = (int) $row->ID;
				$group = 'wp_block' === $row->post_type ? 'pattern' : ( 'wp_navigation' === $row->post_type ? 'navigation' : 'content' );
				$title = '' !== trim( (string) $row->post_title ) ? html_entity_decode( wp_strip_all_tags( $row->post_title ), ENT_QUOTES, 'UTF-8' ) : sprintf( /* translators: %d: post ID */ __( '(no title) #%d', 'ekwa' ), (int) $row->ID );
				$view  = ( 'content' === $group && is_post_type_viewable( $row->post_type ) && 'private' !== $row->post_status ) ? (string) get_permalink( (int) $row->ID ) : '';
				call_user_func( $fn, array(
					'key'       => 'post:' . (int) $row->ID,
					'group'     => $group,
					'type'      => (string) $row->post_type,
					'typeLabel' => ekwa_site_audit_type_label( $row->post_type ),
					'title'     => $title,
					'status'    => (string) $row->post_status,
					'edit'      => ekwa_site_audit_post_edit_url( $row ),
					'view'      => $view,
					'id'        => (int) $row->ID,
					'content'   => (string) $row->post_content,
					'excerpt'   => (string) $row->post_excerpt,
				) );
				if ( ++$count >= $max ) {
					return $count;
				}
			}
		} while ( count( (array) $rows ) === 200 );
	}

	$block_theme = ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) || current_theme_supports( 'block-templates' );
	if ( function_exists( 'get_block_templates' ) && $block_theme ) {
		foreach ( array( 'wp_template_part' => 'part', 'wp_template' => 'template' ) as $tt => $group ) {
			$templates = get_block_templates( array(), $tt );
			foreach ( (array) $templates as $template ) {
				if ( ! is_object( $template ) || empty( $template->id ) ) {
					continue;
				}
				$key = $group . ':' . $template->id;
				if ( $only && ! isset( $only[ $key ] ) ) {
					continue;
				}
				$title = '' !== (string) $template->title ? (string) $template->title : (string) $template->slug;
				call_user_func( $fn, array(
					'key'       => $key,
					'group'     => $group,
					'type'      => $tt,
					'typeLabel' => 'part' === $group ? __( 'Template part', 'ekwa' ) : __( 'Template', 'ekwa' ),
					'title'     => html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' ),
					'status'    => 'publish',
					'edit'      => ekwa_site_audit_template_edit_url( $template ),
					'view'      => '',
					'id'        => (int) $template->wp_id,
					'content'   => (string) $template->content,
					'excerpt'   => '',
					'source'    => (string) $template->source, // theme (file) | custom (saved in the Site Editor)
				) );
				if ( ++$count >= $max ) {
					return $count;
				}
			}
		}
	}

	return $count;
}

/**
 * A post type's singular label.
 *
 * @param string $type
 * @return string
 */
function ekwa_site_audit_type_label( $type ) {
	static $cache = array();
	if ( ! isset( $cache[ $type ] ) ) {
		if ( 'wp_block' === $type ) {
			$cache[ $type ] = __( 'Synced pattern', 'ekwa' );
		} elseif ( 'wp_navigation' === $type ) {
			$cache[ $type ] = __( 'Navigation menu', 'ekwa' );
		} else {
			$object         = get_post_type_object( $type );
			$cache[ $type ] = $object && ! empty( $object->labels->singular_name ) ? (string) $object->labels->singular_name : (string) $type;
		}
	}
	return $cache[ $type ];
}

/**
 * Public description of a source (no content), for the screens.
 *
 * @param array $src
 * @return array
 */
function ekwa_site_audit_public_source( $src ) {
	return array(
		'key'       => $src['key'],
		'group'     => $src['group'],
		'type'      => $src['type'],
		'typeLabel' => $src['typeLabel'],
		'title'     => $src['title'],
		'status'    => $src['status'],
		'edit'      => $src['edit'],
		'view'      => $src['view'],
		'file'      => isset( $src['source'] ) && 'theme' === $src['source'],
	);
}

/**
 * A MySQL named lock around read-modify-write of one option row (several
 * browser requests may finish at once). Falls back to no lock.
 *
 * @param string $key
 * @return string Lock name to release ('' when none).
 */
function ekwa_site_audit_lock( $key ) {
	if ( function_exists( 'ekwa_ai_lock' ) ) {
		return ekwa_ai_lock( 'site-audit:' . $key, 10 );
	}
	return '';
}

/**
 * Release a lock from ekwa_site_audit_lock().
 *
 * @param string $name
 */
function ekwa_site_audit_unlock( $name ) {
	if ( '' !== $name && function_exists( 'ekwa_ai_unlock' ) ) {
		ekwa_ai_unlock( $name );
	}
}

// ═══════════════════════════════════════════════════════════════════════════════
// YOAST SEO
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Is Yoast SEO active?
 *
 * @return bool
 */
function ekwa_site_audit_yoast_active() {
	return defined( 'WPSEO_VERSION' );
}

/**
 * A Yoast "Search Appearance" option ('' when Yoast is off).
 *
 * @param string $key e.g. noindex-page, title-page, metadesc-page
 * @return mixed
 */
function ekwa_site_audit_yoast_option( $key ) {
	if ( class_exists( 'WPSEO_Options' ) && method_exists( 'WPSEO_Options', 'get' ) ) {
		return WPSEO_Options::get( $key, '' );
	}
	$titles = get_option( 'wpseo_titles', array() );
	return is_array( $titles ) && isset( $titles[ $key ] ) ? $titles[ $key ] : '';
}

/**
 * Does Yoast hide this post type from search by default?
 *
 * @param string $type
 * @return bool
 */
function ekwa_site_audit_yoast_type_noindex( $type ) {
	return ! empty( ekwa_site_audit_yoast_option( 'noindex-' . $type ) );
}

/**
 * Is a page hidden from search engines by its Yoast setting?
 * Yoast's per-page value: '1' = no, '2' = yes, '' / '0' = the post type default.
 *
 * @param string $value Raw _yoast_wpseo_meta-robots-noindex.
 * @param string $type  Post type.
 * @return bool
 */
function ekwa_site_audit_noindex_effective( $value, $type ) {
	$value = (string) $value;
	return '1' === $value || ( '2' !== $value && ekwa_site_audit_yoast_type_noindex( $type ) );
}

/**
 * May the current person change whether a page shows in search? The same rule
 * Yoast uses for its Advanced tab: administrators, or anyone allowed to edit
 * the page when Yoast lets non-admins edit advanced settings.
 *
 * @param int $post_id
 * @return bool
 */
function ekwa_site_audit_can_set_visibility( $post_id ) {
	if ( ! ekwa_site_audit_yoast_active() ) {
		return false;
	}
	// 0 = a post not saved yet (the editor's "new page" screen).
	if ( $post_id ? ! current_user_can( 'edit_post', $post_id ) : ! current_user_can( 'edit_posts' ) ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) || current_user_can( 'wpseo_manage_options' ) || current_user_can( 'wpseo_edit_advanced_metadata' ) ) {
		return true;
	}
	return ! ekwa_site_audit_yoast_option( 'disableadvanced_meta' );
}

/**
 * Hide a page from search engines, or show it again — through Yoast's own
 * per-page setting ("Allow search engines to show this page in search
 * results?"), nothing else. Yoast updates its indexable (and sitemap) itself.
 *
 * Hiding records the value it replaced, so showing the page again restores
 * exactly what Yoast had before.
 *
 * @param int    $post_id
 * @param bool   $hide
 * @param string $reason Why (policy, thank-you, empty, soon, manual).
 * @return string 'hidden'|'shown'|'unchanged'
 */
function ekwa_site_audit_set_hidden( $post_id, $hide, $reason = 'manual' ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return 'unchanged';
	}
	$current = (string) get_post_meta( $post_id, EKWA_SITE_AUDIT_YOAST_NOINDEX, true );
	$hidden  = ekwa_site_audit_noindex_effective( $current, $post->post_type );

	if ( $hide ) {
		if ( $hidden ) {
			return 'unchanged';
		}
		update_post_meta( $post_id, EKWA_SITE_AUDIT_META_HIDDEN, array(
			'prev'   => $current,
			't'      => time(),
			'user'   => get_current_user_id(),
			'reason' => sanitize_key( $reason ),
		) );
		update_post_meta( $post_id, EKWA_SITE_AUDIT_YOAST_NOINDEX, '1' );
		return 'hidden';
	}

	if ( ! $hidden ) {
		delete_post_meta( $post_id, EKWA_SITE_AUDIT_META_HIDDEN );
		return 'unchanged';
	}
	$record = get_post_meta( $post_id, EKWA_SITE_AUDIT_META_HIDDEN, true );
	$prev   = is_array( $record ) && isset( $record['prev'] ) ? (string) $record['prev'] : null;
	if ( null !== $prev && ! ekwa_site_audit_noindex_effective( $prev, $post->post_type ) ) {
		$target = $prev;
	} else {
		$target = ekwa_site_audit_yoast_type_noindex( $post->post_type ) ? '2' : '';
	}
	if ( '' === $target || '0' === $target ) {
		delete_post_meta( $post_id, EKWA_SITE_AUDIT_YOAST_NOINDEX );
	} else {
		update_post_meta( $post_id, EKWA_SITE_AUDIT_YOAST_NOINDEX, $target );
	}
	delete_post_meta( $post_id, EKWA_SITE_AUDIT_META_HIDDEN );
	return 'shown';
}

// ═══════════════════════════════════════════════════════════════════════════════
// REST
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Routes shared by the screens.
 */
function ekwa_site_audit_routes() {
	$can_view = static function () {
		return current_user_can( ekwa_page_audit_dashboard_cap() );
	};
	register_rest_route( 'ekwa/v1', '/site-audit/content', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'ekwa_site_audit_rest_content',
		'permission_callback' => $can_view,
	) );
	register_rest_route( 'ekwa/v1', '/site-audit/visibility', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_site_audit_rest_visibility',
		'permission_callback' => $can_view,
		'args'                => array(
			'ids'    => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'integer' ) ),
			'hidden' => array( 'required' => true, 'type' => 'boolean' ),
			'reason' => array( 'type' => 'string', 'default' => 'manual' ),
		),
	) );
	register_rest_route( 'ekwa/v1', '/page-audit/exclude', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'ekwa_site_audit_rest_exclude',
		'permission_callback' => static function ( $request ) {
			return current_user_can( ekwa_page_audit_dashboard_cap() ) && current_user_can( 'edit_post', (int) $request->get_param( 'post_id' ) );
		},
		'args'                => array(
			'post_id' => array( 'required' => true, 'type' => 'integer' ),
			'mode'    => array( 'required' => true, 'type' => 'string', 'enum' => array( 'exclude', 'include', '' ) ),
		),
	) );
}
add_action( 'rest_api_init', 'ekwa_site_audit_routes' );

/**
 * Several post meta values for many posts in one query.
 *
 * @param int[]    $ids
 * @param string[] $keys
 * @return array<int,array<string,string>>
 */
function ekwa_site_audit_meta_map( $ids, $keys ) {
	global $wpdb;
	$map = array();
	if ( ! $ids || ! $keys ) {
		return $map;
	}
	$key_in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
	foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
		$in   = implode( ',', $chunk );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) AND meta_key IN ($key_in)", $keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers + placeholders.
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->post_id ][ $row->meta_key ] = (string) $row->meta_value;
		}
	}
	return $map;
}

/**
 * GET /site-audit/content — every published page, post and public custom
 * post with its content state, Yoast title/description and search visibility.
 * Reads only.
 *
 * @return WP_REST_Response
 */
function ekwa_site_audit_rest_content() {
	$max   = (int) apply_filters( 'ekwa_page_audit_dashboard_max', 1500 );
	$types = ekwa_site_audit_post_types();
	$front = (int) get_option( 'page_on_front' );
	$yoast = ekwa_site_audit_yoast_active();
	$all   = array();

	foreach ( $types as $type ) {
		$ids = get_posts( array(
			'post_type'        => $type,
			'post_status'      => 'publish',
			'posts_per_page'   => $max,
			'fields'           => 'ids',
			'orderby'          => is_post_type_hierarchical( $type ) ? array( 'menu_order' => 'ASC', 'title' => 'ASC' ) : array( 'date' => 'DESC' ),
			'no_found_rows'    => true,
			'suppress_filters' => false,
		) );
		foreach ( $ids as $id ) {
			$all[] = (int) $id;
		}
	}
	if ( $all ) {
		_prime_post_caches( $all, false, false );
	}
	$meta = ekwa_site_audit_meta_map( $all, array( EKWA_SITE_AUDIT_YOAST_TITLE, EKWA_SITE_AUDIT_YOAST_DESC, EKWA_SITE_AUDIT_YOAST_NOINDEX, EKWA_SITE_AUDIT_META_HIDDEN, EKWA_SITE_AUDIT_META_EXCLUDE ) );

	$items = array();
	foreach ( $all as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			continue;
		}
		$m       = isset( $meta[ $id ] ) ? $meta[ $id ] : array();
		$state   = ekwa_site_audit_content_state( $post->post_content );
		$noindex = isset( $m[ EKWA_SITE_AUDIT_YOAST_NOINDEX ] ) ? $m[ EKWA_SITE_AUDIT_YOAST_NOINDEX ] : '';
		$record  = isset( $m[ EKWA_SITE_AUDIT_META_HIDDEN ] ) ? maybe_unserialize( $m[ EKWA_SITE_AUDIT_META_HIDDEN ] ) : null;
		$hidden  = $yoast && ekwa_site_audit_noindex_effective( $noindex, $post->post_type );
		$edit    = get_edit_post_link( $id, 'raw' );
		$items[] = array(
			'id'        => $id,
			'type'      => $post->post_type,
			'title'     => '' !== get_the_title( $post ) ? html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ) : __( '(no title)', 'ekwa' ),
			'url'       => get_permalink( $post ),
			'edit'      => $edit ? $edit : '',
			'front'     => $id === $front,
			'kind'      => ekwa_site_audit_page_kind( $post ),
			'words'     => $state['words'],
			'empty'     => $state['empty'],
			'soon'      => $state['soon'],
			'seoTitle'  => isset( $m[ EKWA_SITE_AUDIT_YOAST_TITLE ] ) ? trim( $m[ EKWA_SITE_AUDIT_YOAST_TITLE ] ) : '',
			'seoDesc'   => isset( $m[ EKWA_SITE_AUDIT_YOAST_DESC ] ) ? trim( $m[ EKWA_SITE_AUDIT_YOAST_DESC ] ) : '',
			'noindex'   => $noindex,
			'hidden'    => $hidden,
			'hiddenBy'  => $hidden && is_array( $record ) ? array(
				'reason' => isset( $record['reason'] ) ? (string) $record['reason'] : '',
				't'      => isset( $record['t'] ) ? (int) $record['t'] : 0,
			) : null,
			'canHide'   => $yoast && ekwa_site_audit_can_set_visibility( $id ),
		);
	}

	$type_info = array();
	foreach ( $types as $type ) {
		$object             = get_post_type_object( $type );
		$type_info[ $type ] = array(
			'label'   => $object ? (string) $object->labels->name : $type,
			'single'  => ekwa_site_audit_type_label( $type ),
			'noindex' => $yoast && ekwa_site_audit_yoast_type_noindex( $type ),
			'title'   => $yoast ? (string) ekwa_site_audit_yoast_option( 'title-' . $type ) : '',
			'desc'    => $yoast ? (string) ekwa_site_audit_yoast_option( 'metadesc-' . $type ) : '',
		);
	}

	return rest_ensure_response( array(
		'items' => $items,
		'types' => $type_info,
		'yoast' => $yoast,
		'kinds' => ekwa_site_audit_kind_labels(),
	) );
}

/**
 * POST /site-audit/visibility — hide pages from search engines (or show them
 * again) through Yoast. Each page is checked on its own permission.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_site_audit_rest_visibility( $request ) {
	if ( ! ekwa_site_audit_yoast_active() ) {
		return new WP_Error( 'ekwa_site_audit_no_yoast', __( 'Yoast SEO is not active — its per-page setting is what hides a page from search engines.', 'ekwa' ), array( 'status' => 400 ) );
	}
	$ids    = array_slice( array_unique( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ), 0, 500 );
	$hide   = (bool) $request->get_param( 'hidden' );
	$reason = sanitize_key( (string) $request->get_param( 'reason' ) );
	$out    = array();
	foreach ( $ids as $id ) {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			$out[ $id ] = array( 'result' => 'missing' );
			continue;
		}
		if ( ! ekwa_site_audit_can_set_visibility( $id ) ) {
			$out[ $id ] = array( 'result' => 'forbidden' );
			continue;
		}
		$result     = ekwa_site_audit_set_hidden( $id, $hide, $reason );
		$noindex    = (string) get_post_meta( $id, EKWA_SITE_AUDIT_YOAST_NOINDEX, true );
		$record     = get_post_meta( $id, EKWA_SITE_AUDIT_META_HIDDEN, true );
		$hidden     = ekwa_site_audit_noindex_effective( $noindex, $post->post_type );
		$out[ $id ] = array(
			'result'   => $result,
			'noindex'  => $noindex,
			'hidden'   => $hidden,
			'hiddenBy' => $hidden && is_array( $record ) ? array(
				'reason' => isset( $record['reason'] ) ? (string) $record['reason'] : '',
				't'      => isset( $record['t'] ) ? (int) $record['t'] : 0,
			) : null,
		);
	}
	return rest_ensure_response( array( 'results' => $out ) );
}

/**
 * POST /page-audit/exclude — leave a page out of the PageSpeed dashboard, or
 * bring an automatically excluded one (thank-you, policy, blog page) back.
 * mode '' removes the override (back to automatic).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function ekwa_site_audit_rest_exclude( $request ) {
	$post_id = (int) $request->get_param( 'post_id' );
	$mode    = (string) $request->get_param( 'mode' );
	if ( ! get_post( $post_id ) ) {
		return new WP_Error( 'rest_post_invalid_id', __( 'Page not found.', 'ekwa' ), array( 'status' => 404 ) );
	}
	$kind = ekwa_site_audit_page_kind( $post_id );
	// Store only what differs from automatic, so "back to normal" leaves no trace.
	if ( '' === $mode || ( 'exclude' === $mode && '' !== $kind ) || ( 'include' === $mode && '' === $kind ) ) {
		delete_post_meta( $post_id, EKWA_SITE_AUDIT_META_EXCLUDE );
	} else {
		update_post_meta( $post_id, EKWA_SITE_AUDIT_META_EXCLUDE, $mode );
	}
	return rest_ensure_response( array(
		'post_id' => $post_id,
		'kind'    => $kind,
		'exclude' => ekwa_site_audit_exclude_override( $post_id ),
	) );
}

// ═══════════════════════════════════════════════════════════════════════════════
// EDITOR: "this page is hidden from search engines" after a save
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * In the post editor, when Yoast is active: after a save, if the page is
 * hidden from search engines but now has content, say so and offer to show it
 * (through Yoast's own setting, then save). Policy and thank-you pages are
 * meant to stay hidden — no warning there.
 */
function ekwa_site_audit_enqueue_editor() {
	if ( ! ekwa_site_audit_yoast_active() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'post' !== $screen->base || empty( $screen->post_type ) || ! in_array( $screen->post_type, ekwa_site_audit_post_types(), true ) ) {
		return;
	}
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$kind    = $post_id ? ekwa_site_audit_page_kind( $post_id ) : '';

	$rel = 'assets/js/ekwa-site-audit-editor.js';
	wp_enqueue_script( 'ekwa-site-audit-editor', get_template_directory_uri() . '/' . $rel, array( 'wp-data', 'wp-blocks', 'wp-i18n', 'wp-dom-ready' ), filemtime( get_template_directory() . '/' . $rel ), true );
	wp_localize_script( 'ekwa-site-audit-editor', 'ekwaSiteAuditEditor', array(
		'kind'        => $kind,
		'typeNoindex' => ekwa_site_audit_yoast_type_noindex( $screen->post_type ),
		'canChange'   => ekwa_site_audit_can_set_visibility( $post_id ),
		'blocks'      => ekwa_site_audit_block_sets(),
		'minWords'    => (int) apply_filters( 'ekwa_site_audit_empty_words', 5 ),
		'contentUrl'  => ekwa_site_audit_url( 'content' ),
	) );
}
add_action( 'enqueue_block_editor_assets', 'ekwa_site_audit_enqueue_editor' );

require_once __DIR__ . '/ekwa-site-audit-links.php';
require_once __DIR__ . '/ekwa-site-audit-images.php';
require_once __DIR__ . '/ekwa-site-audit-spelling.php';
