<?php
/**
 * Mockup Converter — build a real WordPress menu from the mockup's navigation.
 *
 * Both converters replace the primary <nav> with a single `ekwa/header-menu`
 * block, because that block re-renders the menu from whatever is assigned to
 * the `main_menu` theme location. That mapping was only half the job: the
 * mockup's actual menu — items, dropdowns, mega-menu columns and their images —
 * was thrown away, and the freshly converted header rendered nothing until
 * somebody rebuilt the whole structure by hand under Appearance → Menus.
 *
 * This module reads that structure out of the mockup and materializes it as a
 * WP menu of custom links, complete with the per-item mega-menu meta
 * (`_ekwa_megamenu`, `_ekwa_megamenu_columns`, `_ekwa_menu_image`) that
 * ekwa/header-menu renders, then assigns it to `main_menu`.
 *
 * Level mapping (matches ekwa_header_menu_render_megamenu()):
 *   level 1  top-level item        — mega-menu flag + column count live here
 *   level 2  dropdown item, or a mega-menu COLUMN HEADING (+ its image)
 *   level 3  items inside a mega-menu column
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Element children of a node, optionally filtered by tag name.
 *
 * @param DOMNode $node
 * @param string  $tag Lowercase tag name, or '' for any element.
 * @return DOMElement[]
 */
function ekwa_mc_menu_children( $node, $tag = '' ) {
	$out = array();
	if ( ! $node || ! $node->hasChildNodes() ) {
		return $out;
	}
	foreach ( $node->childNodes as $child ) {
		if ( XML_ELEMENT_NODE !== $child->nodeType ) {
			continue;
		}
		if ( '' === $tag || strtolower( $child->nodeName ) === $tag ) {
			$out[] = $child;
		}
	}
	return $out;
}

/**
 * First descendant matching a tag + class, searching depth-first but NOT
 * descending into nested <ul>/<li> (so a parent item never picks up a child
 * item's link).
 *
 * @param DOMNode $node
 * @param string  $tag   Lowercase tag name.
 * @param string  $class Class token, or '' for any.
 * @return DOMElement|null
 */
function ekwa_mc_menu_find_own( $node, $tag, $class = '' ) {
	foreach ( ekwa_mc_menu_children( $node ) as $child ) {
		$name = strtolower( $child->nodeName );
		if ( 'ul' === $name || 'li' === $name ) {
			continue; // Belongs to a deeper level.
		}
		if ( $name === $tag && ( '' === $class || ekwa_mc_node_has_class( $child, $class ) ) ) {
			return $child;
		}
		$found = ekwa_mc_menu_find_own( $child, $tag, $class );
		if ( $found ) {
			return $found;
		}
	}
	return null;
}

/**
 * The visible label of a menu link: the canonical `.ekwa-menu-label` span when
 * present, otherwise the anchor's own text (icon <i> elements carry none).
 *
 * @param DOMElement|null $anchor
 * @return string
 */
function ekwa_mc_menu_label( $anchor ) {
	if ( ! $anchor ) {
		return '';
	}
	foreach ( $anchor->getElementsByTagName( 'span' ) as $span ) {
		if ( ekwa_mc_node_has_class( $span, 'ekwa-menu-label' ) ) {
			$text = trim( $span->textContent );
			if ( '' !== $text ) {
				return $text;
			}
		}
	}
	return trim( preg_replace( '/\s+/', ' ', $anchor->textContent ) );
}

/**
 * Locate the mockup's PRIMARY navigation element.
 *
 * Preference order: the canonical `nav.ekwa-header-nav` / `ul.ekwa-header-menu`
 * signatures, then the link-richest <nav> inside <header>, then the
 * link-richest <nav> anywhere. Footer link columns are never chosen — they sit
 * outside <header> and lose the tie-break to a real nav.
 *
 * @param DOMDocument $doc
 * @return DOMElement|null
 */
function ekwa_mc_menu_find_nav( $doc ) {
	$xpath = new DOMXPath( $doc );

	// Canonical signatures first. Document order puts a wrapping
	// nav.ekwa-header-nav ahead of its own ul.ekwa-header-menu, so the outer
	// element wins naturally; a bare ul is matched when there's no nav.
	foreach ( $xpath->query( '//nav | //ul' ) as $el ) {
		if ( ekwa_mc_node_has_class( $el, 'ekwa-header-nav' ) || ekwa_mc_node_has_class( $el, 'ekwa-header-menu' ) ) {
			return $el;
		}
	}

	$best       = null;
	$best_score = 0;
	foreach ( $xpath->query( '//nav' ) as $nav ) {
		// Skip the mobile drawer — ekwa/hamburger-menu renders that one.
		if ( ekwa_mc_node_has_class( $nav, 'ekwa-mobile-nav' ) ) {
			continue;
		}
		$links = $nav->getElementsByTagName( 'a' )->length;
		if ( $links < 2 ) {
			continue;
		}
		// Being inside <header> is worth more than raw link count, so a big
		// footer sitemap can't outrank the real header nav.
		$in_header = false;
		for ( $p = $nav->parentNode; $p && XML_ELEMENT_NODE === $p->nodeType; $p = $p->parentNode ) {
			if ( 'header' === strtolower( $p->nodeName ) ) {
				$in_header = true;
				break;
			}
		}
		$score = $links + ( $in_header ? 1000 : 0 );
		if ( $score > $best_score ) {
			$best       = $nav;
			$best_score = $score;
		}
	}

	return $best;
}

/**
 * Parse a nav element into a nested menu tree.
 *
 * @param DOMElement $nav
 * @return array<int,array> Items: { title, url, target, children[], megamenu, columns, image }
 */
function ekwa_mc_menu_parse( $nav ) {
	// The item list is the first <ul> in the nav (the nav itself may BE the ul).
	$list = ( 'ul' === strtolower( $nav->nodeName ) ) ? $nav : null;
	if ( ! $list ) {
		foreach ( $nav->getElementsByTagName( 'ul' ) as $ul ) {
			$list = $ul;
			break;
		}
	}
	if ( ! $list ) {
		return array();
	}

	$items = array();
	foreach ( ekwa_mc_menu_children( $list, 'li' ) as $li ) {
		$item = ekwa_mc_menu_parse_item( $li );
		if ( $item ) {
			$items[] = $item;
		}
	}
	return $items;
}

/**
 * Parse one <li> (and everything below it) into a menu item.
 *
 * @param DOMElement $li
 * @return array|null
 */
function ekwa_mc_menu_parse_item( $li ) {
	$anchor = ekwa_mc_menu_find_own( $li, 'a' );
	$title  = ekwa_mc_menu_label( $anchor );

	// A non-linked heading inside a dropdown (e.g. <li class="dropdown-header">
	// YouTube</li>) is still a menu item — it just has no destination.
	if ( '' === $title ) {
		$own = trim( preg_replace( '/\s+/', ' ', ekwa_mc_menu_own_text( $li ) ) );
		$title = $own;
	}
	if ( '' === $title ) {
		return null;
	}

	$item = array(
		'title'    => $title,
		'url'      => $anchor ? trim( $anchor->getAttribute( 'href' ) ) : '',
		'target'   => $anchor && '_blank' === $anchor->getAttribute( 'target' ) ? '_blank' : '',
		'children' => array(),
		'megamenu' => false,
		'columns'  => 0,
		'image'    => '',
	);
	if ( '' === $item['url'] ) {
		$item['url'] = '#';
	}

	// ── Mega menu: a panel of columns. ───────────────────────────────────
	$mega = ekwa_mc_menu_find_mega_panel( $li );
	if ( $mega ) {
		$item['megamenu'] = true;
		$item['columns']  = ekwa_mc_menu_mega_columns( $mega );
		foreach ( ekwa_mc_menu_mega_column_nodes( $mega ) as $col ) {
			$column = ekwa_mc_menu_parse_column( $col );
			if ( $column ) {
				$item['children'][] = $column;
			}
		}
		if ( ! empty( $item['children'] ) ) {
			if ( $item['columns'] < 1 ) {
				$item['columns'] = count( $item['children'] );
			}
			return $item;
		}
		// A "mega" panel with no columns is just a dropdown — fall through.
		$item['megamenu'] = false;
		$item['columns']  = 0;
	}

	// ── Plain dropdown: a nested <ul>. ───────────────────────────────────
	foreach ( ekwa_mc_menu_children( $li, 'ul' ) as $sub ) {
		foreach ( ekwa_mc_menu_children( $sub, 'li' ) as $sub_li ) {
			$child = ekwa_mc_menu_parse_item( $sub_li );
			if ( $child ) {
				$item['children'][] = $child;
			}
		}
	}

	return $item;
}

/**
 * Text belonging to this element itself, ignoring nested lists.
 *
 * @param DOMElement $node
 * @return string
 */
function ekwa_mc_menu_own_text( $node ) {
	$text = '';
	foreach ( $node->childNodes as $child ) {
		if ( XML_TEXT_NODE === $child->nodeType ) {
			$text .= $child->textContent;
			continue;
		}
		if ( XML_ELEMENT_NODE === $child->nodeType ) {
			$name = strtolower( $child->nodeName );
			if ( 'ul' === $name || 'ol' === $name || 'div' === $name ) {
				continue;
			}
			$text .= $child->textContent;
		}
	}
	return $text;
}

/**
 * The mega-menu panel inside an <li>, if it has one.
 *
 * @param DOMElement $li
 * @return DOMElement|null
 */
function ekwa_mc_menu_find_mega_panel( $li ) {
	foreach ( ekwa_mc_menu_children( $li ) as $child ) {
		if ( ekwa_mc_node_has_class( $child, 'ekwa-megamenu' )
			|| ekwa_mc_node_has_class( $child, 'mega-dropdown' )
			|| ekwa_mc_node_has_class( $child, 'mega-menu' ) ) {
			return $child;
		}
	}
	return null;
}

/**
 * Column count declared on a mega panel via --ekwa-mega-cols.
 *
 * @param DOMElement $panel
 * @return int 0 when not declared.
 */
function ekwa_mc_menu_mega_columns( $panel ) {
	$style = $panel->getAttribute( 'style' );
	if ( $style && preg_match( '/--ekwa-mega-cols\s*:\s*(\d+)/i', $style, $m ) ) {
		return max( 1, min( 6, (int) $m[1] ) );
	}
	return 0;
}

/**
 * The column elements inside a mega panel, looking through one optional grid
 * wrapper (mockups usually nest .ekwa-megamenu-grid inside .ekwa-megamenu).
 *
 * @param DOMElement $panel
 * @return DOMElement[]
 */
function ekwa_mc_menu_mega_column_nodes( $panel ) {
	$is_column = function ( $el ) {
		return ekwa_mc_node_has_class( $el, 'ekwa-megamenu-column' )
			|| ekwa_mc_node_has_class( $el, 'mega-column' );
	};

	$columns = array();
	foreach ( ekwa_mc_menu_children( $panel ) as $child ) {
		if ( $is_column( $child ) ) {
			$columns[] = $child;
			continue;
		}
		// One level of grid wrapper.
		foreach ( ekwa_mc_menu_children( $child ) as $grandchild ) {
			if ( $is_column( $grandchild ) ) {
				$columns[] = $grandchild;
			}
		}
	}
	return $columns;
}

/**
 * Parse one mega-menu column into a level-2 item with level-3 children.
 *
 * @param DOMElement $col
 * @return array|null
 */
function ekwa_mc_menu_parse_column( $col ) {
	// Heading: the canonical class, else .dropdown-header, else the first
	// non-list link in the column.
	$heading = ekwa_mc_menu_find_own( $col, 'a', 'ekwa-megamenu-heading' );
	if ( ! $heading ) {
		$heading = ekwa_mc_menu_find_own( $col, 'a', 'dropdown-header' );
	}
	if ( ! $heading ) {
		$heading = ekwa_mc_menu_find_own( $col, 'a' );
	}

	$title = $heading ? ekwa_mc_menu_label( $heading ) : '';
	if ( '' === $title ) {
		// Unlinked heading (<span>/<h4> etc.).
		foreach ( array( 'span', 'h3', 'h4', 'h5', 'div', 'p' ) as $tag ) {
			$el = ekwa_mc_menu_find_own( $col, $tag, 'ekwa-megamenu-heading' );
			if ( ! $el ) {
				$el = ekwa_mc_menu_find_own( $col, $tag, 'dropdown-header' );
			}
			if ( $el ) {
				$title = trim( preg_replace( '/\s+/', ' ', $el->textContent ) );
				break;
			}
		}
	}
	if ( '' === $title ) {
		return null;
	}

	$item = array(
		'title'    => $title,
		'url'      => $heading ? trim( $heading->getAttribute( 'href' ) ) : '#',
		'target'   => '',
		'children' => array(),
		'megamenu' => false,
		'columns'  => 0,
		'image'    => '',
	);
	if ( '' === $item['url'] ) {
		$item['url'] = '#';
	}

	// Column image (rendered from the media library at run time, so all we keep
	// is the filename — resolved against the manifest on import).
	foreach ( $col->getElementsByTagName( 'img' ) as $img ) {
		$src = trim( $img->getAttribute( 'src' ) );
		if ( '' !== $src ) {
			$item['image'] = $src;
			break;
		}
	}

	// Column items: every <li> in the column's list(s). Nested <li>s are
	// skipped — their parent already carries them, and the mega renderer only
	// goes three levels deep anyway.
	foreach ( $col->getElementsByTagName( 'li' ) as $li ) {
		$nested = false;
		for ( $p = $li->parentNode; $p && $p !== $col; $p = $p->parentNode ) {
			if ( XML_ELEMENT_NODE === $p->nodeType && 'li' === strtolower( $p->nodeName ) ) {
				$nested = true;
				break;
			}
		}
		if ( $nested ) {
			continue;
		}
		$leaf = ekwa_mc_menu_parse_item( $li );
		if ( $leaf ) {
			$leaf['children'] = array();
			$item['children'][] = $leaf;
		}
	}

	return $item;
}

/**
 * Count every item in a parsed tree.
 *
 * @param array $items
 * @return int
 */
function ekwa_mc_menu_count( $items ) {
	$n = 0;
	foreach ( $items as $item ) {
		$n += 1 + ekwa_mc_menu_count( $item['children'] );
	}
	return $n;
}

/**
 * Create (or refill) a WordPress menu from a parsed tree and assign it to a
 * theme location.
 *
 * Refuses to touch a menu that already has items unless $replace is true — a
 * second conversion of the same header must not silently wipe menu work done
 * in the admin.
 *
 * @param array  $items    Parsed tree from ekwa_mc_menu_parse().
 * @param string $location Theme location slug.
 * @param bool   $replace  Overwrite an existing, non-empty menu.
 * @return array|WP_Error { menu_id, menu_name, created:int, replaced:bool, images:int }
 */
function ekwa_mc_menu_import( $items, $location = 'main_menu', $replace = false ) {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return new WP_Error(
			'forbidden',
			__( 'You need permission to edit theme options to create menus.', 'ekwa' ),
			array( 'status' => 403 )
		);
	}
	if ( empty( $items ) ) {
		return new WP_Error( 'empty_menu', __( 'No menu items were found in the markup.', 'ekwa' ) );
	}

	$locations = get_nav_menu_locations();
	$menu_id   = isset( $locations[ $location ] ) ? (int) $locations[ $location ] : 0;
	$menu      = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;

	if ( ! $menu ) {
		// Reuse a menu already named "Main Menu" before creating a duplicate.
		$menu = wp_get_nav_menu_object( __( 'Main Menu', 'ekwa' ) );
		if ( ! $menu ) {
			$new_id = wp_create_nav_menu( __( 'Main Menu', 'ekwa' ) );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
			$menu = wp_get_nav_menu_object( $new_id );
		}
	}
	if ( ! $menu ) {
		return new WP_Error( 'menu_failed', __( 'Could not create the menu.', 'ekwa' ) );
	}

	$existing = wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'publish,draft' ) );
	$existing = is_array( $existing ) ? $existing : array();

	if ( ! empty( $existing ) && ! $replace ) {
		return new WP_Error(
			'menu_exists',
			sprintf(
				/* translators: 1: menu name, 2: item count. */
				__( 'The menu "%1$s" already has %2$d item(s), so it was left alone. Re-run with "Replace the existing menu" to rebuild it from the mockup.', 'ekwa' ),
				$menu->name,
				count( $existing )
			),
			array( 'menu_id' => (int) $menu->term_id )
		);
	}

	$replaced = false;
	if ( ! empty( $existing ) ) {
		foreach ( $existing as $item ) {
			wp_delete_post( $item->ID, true );
		}
		$replaced = true;
	}

	$stats = array( 'created' => 0, 'images' => 0 );
	ekwa_mc_menu_insert_items( $items, $menu->term_id, 0, $stats );

	// Assign to the location (leaving every other location untouched).
	$locations[ $location ] = (int) $menu->term_id;
	set_theme_mod( 'nav_menu_locations', $locations );

	return array(
		'menu_id'   => (int) $menu->term_id,
		'menu_name' => $menu->name,
		'created'   => $stats['created'],
		'images'    => $stats['images'],
		'replaced'  => $replaced,
	);
}

/**
 * Insert a parsed tree as custom-link menu items, depth-first.
 *
 * @param array $items     Parsed items.
 * @param int   $menu_id   Menu term ID.
 * @param int   $parent_id Parent menu-item ID (0 for top level).
 * @param array $stats     Running counters, by reference.
 */
function ekwa_mc_menu_insert_items( $items, $menu_id, $parent_id, &$stats ) {
	$position = 0;
	foreach ( $items as $item ) {
		$position++;
		$item_id = wp_update_nav_menu_item( $menu_id, 0, array(
			'menu-item-title'     => $item['title'],
			'menu-item-url'       => $item['url'],
			'menu-item-type'      => 'custom',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_id,
			'menu-item-position'  => $position,
			'menu-item-target'    => $item['target'],
		) );

		if ( is_wp_error( $item_id ) || ! $item_id ) {
			continue;
		}
		$stats['created']++;

		if ( ! empty( $item['megamenu'] ) ) {
			update_post_meta( $item_id, '_ekwa_megamenu', 1 );
			if ( ! empty( $item['columns'] ) ) {
				update_post_meta( $item_id, '_ekwa_megamenu_columns', min( 6, max( 1, (int) $item['columns'] ) ) );
			}
		}

		if ( ! empty( $item['image'] ) ) {
			$attachment = ekwa_mc_menu_resolve_image( $item['image'] );
			if ( $attachment ) {
				update_post_meta( $item_id, '_ekwa_menu_image', $attachment );
				$stats['images']++;
			}
		}

		if ( ! empty( $item['children'] ) ) {
			ekwa_mc_menu_insert_items( $item['children'], $menu_id, (int) $item_id, $stats );
		}
	}
}

/**
 * Resolve a mockup image src to a media-library attachment ID, via the same
 * basename lookup the converter uses for <img> tags.
 *
 * @param string $src
 * @return int Attachment ID, or 0 when the image hasn't been imported.
 */
function ekwa_mc_menu_resolve_image( $src ) {
	$path     = wp_parse_url( $src, PHP_URL_PATH );
	$basename = strtolower( basename( $path ? $path : $src ) );
	if ( '' === $basename ) {
		return 0;
	}

	// The site manifest first (populated by "Import mockup assets").
	if ( function_exists( 'ekwa_converter_load_server_manifest' ) ) {
		$manifest = ekwa_converter_load_server_manifest();
		if ( ! empty( $manifest['media'] ) ) {
			foreach ( $manifest['media'] as $media ) {
				if ( isset( $media['filename'] ) && strtolower( $media['filename'] ) === $basename && ! empty( $media['id'] ) ) {
					return (int) $media['id'];
				}
			}
		}
	}

	if ( function_exists( 'ekwa_mc_find_attachment_by_basename' ) ) {
		$found = ekwa_mc_find_attachment_by_basename( $basename );
		if ( $found && ! empty( $found['id'] ) ) {
			return (int) $found['id'];
		}
	}

	return 0;
}

/**
 * End-to-end: find the primary nav in mockup HTML, parse it, and import it.
 *
 * @param string $html    Mockup HTML (the section being converted).
 * @param bool   $replace Overwrite an existing non-empty menu.
 * @return array|WP_Error|null Import result, an error, or null when the markup
 *                             holds no navigation at all.
 */
function ekwa_mc_menu_import_from_html( $html, $replace = false ) {
	// The converter library is only loaded inside the conversion request, and
	// this can also be reached on its own — pull in the two helpers we borrow
	// (ekwa_mc_extract_body, ekwa_mc_node_has_class).
	if ( ! function_exists( 'ekwa_mc_extract_body' ) ) {
		require_once get_template_directory() . '/inc/ekwa-converter-lib.php';
	}
	if ( ! function_exists( 'ekwa_mc_node_has_class' ) ) {
		require_once get_template_directory() . '/inc/ekwa-converter-detect.php';
	}

	$html = ekwa_mc_extract_body( (string) $html );
	if ( '' === trim( $html ) ) {
		return null;
	}

	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML(
		'<?xml encoding="utf-8"?><div data-ekwa-menu-root="1">' . $html . '</div>',
		LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
	);
	libxml_clear_errors();

	$nav = ekwa_mc_menu_find_nav( $doc );
	if ( ! $nav ) {
		return null;
	}

	$items = ekwa_mc_menu_parse( $nav );
	if ( empty( $items ) ) {
		return null;
	}

	return ekwa_mc_menu_import( $items, 'main_menu', $replace );
}
