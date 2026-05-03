<?php
/**
 * Header Menu: mega-menu meta fields, image meta, and renderer.
 *
 * Adds the following per-menu-item options on the nav-menus screen:
 *   - "Mega Menu" toggle (only meaningful on top-level items)
 *   - "Mega Menu Columns" (1–6, defaults to count of children)
 *   - "Image" attachment (used as the column heading image in mega menus)
 *
 * Provides ekwa_render_main_nav() which builds a multi-level menu where
 * top-level items flagged as mega menus expand into a columnar grid,
 * while other items render as standard nested flyouts.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the mega-menu and image fields on each menu item.
 *
 * @param int    $item_id Menu item ID.
 * @param object $item    Menu item data object.
 * @param int    $depth   Depth of menu item.
 * @param object $args    An object of menu item arguments.
 */
function ekwa_nav_menu_header_fields( $item_id, $item, $depth, $args ) {
	$is_mega       = (bool) get_post_meta( $item_id, '_ekwa_megamenu', true );
	$columns_raw   = get_post_meta( $item_id, '_ekwa_megamenu_columns', true );
	$columns_value = ( '' === $columns_raw || null === $columns_raw ) ? '' : (string) (int) $columns_raw;
	$image_id      = (int) get_post_meta( $item_id, '_ekwa_menu_image', true );
	$image_url     = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
	?>
	<p class="field-ekwa-megamenu description description-wide">
		<label for="ekwa-megamenu-<?php echo esc_attr( $item_id ); ?>">
			<input type="checkbox"
			       id="ekwa-megamenu-<?php echo esc_attr( $item_id ); ?>"
			       name="ekwa_megamenu[<?php echo esc_attr( $item_id ); ?>]"
			       value="1"
			       <?php checked( $is_mega ); ?> />
			<?php esc_html_e( 'Render as Mega Menu (top-level only)', 'ekwa' ); ?>
		</label>
	</p>

	<p class="field-ekwa-megamenu-columns description description-thin">
		<label for="ekwa-megamenu-columns-<?php echo esc_attr( $item_id ); ?>">
			<?php esc_html_e( 'Mega Menu Columns', 'ekwa' ); ?><br />
			<input type="number"
			       id="ekwa-megamenu-columns-<?php echo esc_attr( $item_id ); ?>"
			       name="ekwa_megamenu_columns[<?php echo esc_attr( $item_id ); ?>]"
			       value="<?php echo esc_attr( $columns_value ); ?>"
			       min="1" max="6" step="1"
			       placeholder="auto"
			       class="widefat" />
			<span class="description"><?php esc_html_e( 'Leave blank to auto-fit by child count.', 'ekwa' ); ?></span>
		</label>
	</p>

	<p class="field-ekwa-menu-image description description-wide">
		<label><?php esc_html_e( 'Image (used as mega-menu column image)', 'ekwa' ); ?></label>
		<span class="ekwa-menu-image-field" data-item-id="<?php echo esc_attr( $item_id ); ?>">
			<span class="ekwa-menu-image-preview">
				<?php if ( $image_url ) : ?>
					<img src="<?php echo esc_url( $image_url ); ?>" alt="" style="max-width:80px;height:auto;display:block;" />
				<?php endif; ?>
			</span>
			<input type="hidden"
			       class="ekwa-menu-image-id"
			       name="ekwa_menu_image[<?php echo esc_attr( $item_id ); ?>]"
			       value="<?php echo esc_attr( $image_id ); ?>" />
			<button type="button" class="button ekwa-menu-image-pick">
				<?php echo $image_id ? esc_html__( 'Change Image', 'ekwa' ) : esc_html__( 'Select Image', 'ekwa' ); ?>
			</button>
			<button type="button" class="button-link ekwa-menu-image-remove" style="<?php echo $image_id ? '' : 'display:none;'; ?>color:#b32d2e;margin-left:8px;">
				<?php esc_html_e( 'Remove', 'ekwa' ); ?>
			</button>
		</span>
	</p>
	<?php
}
add_action( 'wp_nav_menu_item_custom_fields', 'ekwa_nav_menu_header_fields', 20, 4 );

/**
 * Persist the header-menu meta when a menu item is saved.
 *
 * @param int $menu_id         ID of the updated menu.
 * @param int $menu_item_db_id ID of the updated menu item.
 */
function ekwa_save_nav_menu_header_fields( $menu_id, $menu_item_db_id ) {
	$is_mega = ! empty( $_POST['ekwa_megamenu'][ $menu_item_db_id ] );
	if ( $is_mega ) {
		update_post_meta( $menu_item_db_id, '_ekwa_megamenu', 1 );
	} else {
		delete_post_meta( $menu_item_db_id, '_ekwa_megamenu' );
	}

	if ( isset( $_POST['ekwa_megamenu_columns'][ $menu_item_db_id ] ) ) {
		$cols = (int) $_POST['ekwa_megamenu_columns'][ $menu_item_db_id ];
		if ( $cols > 0 ) {
			update_post_meta( $menu_item_db_id, '_ekwa_megamenu_columns', min( 6, max( 1, $cols ) ) );
		} else {
			delete_post_meta( $menu_item_db_id, '_ekwa_megamenu_columns' );
		}
	}

	if ( isset( $_POST['ekwa_menu_image'][ $menu_item_db_id ] ) ) {
		$img_id = absint( $_POST['ekwa_menu_image'][ $menu_item_db_id ] );
		if ( $img_id ) {
			update_post_meta( $menu_item_db_id, '_ekwa_menu_image', $img_id );
		} else {
			delete_post_meta( $menu_item_db_id, '_ekwa_menu_image' );
		}
	}
}
add_action( 'wp_update_nav_menu_item', 'ekwa_save_nav_menu_header_fields', 10, 2 );

/**
 * Attach mega-menu / image properties to each menu item object when loaded.
 *
 * @param object $menu_item The menu item object.
 * @return object
 */
function ekwa_setup_nav_menu_header_props( $menu_item ) {
	$menu_item->is_megamenu      = (bool) get_post_meta( $menu_item->ID, '_ekwa_megamenu', true );
	$menu_item->megamenu_columns = (int) get_post_meta( $menu_item->ID, '_ekwa_megamenu_columns', true );
	$menu_item->menu_image_id    = (int) get_post_meta( $menu_item->ID, '_ekwa_menu_image', true );
	return $menu_item;
}
add_filter( 'wp_setup_nav_menu_item', 'ekwa_setup_nav_menu_header_props' );

/**
 * Build a parent-keyed tree from a flat array of nav menu items.
 *
 * @param WP_Post[] $items Flat menu items.
 * @return array Map of parent_id => list of child items, ordered by menu_order.
 */
function ekwa_header_menu_build_tree( $items ) {
	$tree = array();
	foreach ( $items as $item ) {
		$parent              = (int) $item->menu_item_parent;
		$tree[ $parent ][]   = $item;
	}
	foreach ( $tree as $pid => $list ) {
		usort( $tree[ $pid ], function ( $a, $b ) {
			return ( (int) $a->menu_order ) - ( (int) $b->menu_order );
		} );
	}
	return $tree;
}

/**
 * Build the <a> link HTML for a single menu item.
 *
 * @param WP_Post $item Menu item.
 * @param bool    $has_children Whether the item has children (for caret).
 * @return string
 */
function ekwa_header_menu_link( $item, $has_children ) {
	$atts = array(
		'href'   => ! empty( $item->url )        ? $item->url        : '#',
		'title'  => ! empty( $item->attr_title ) ? $item->attr_title : '',
		'target' => ! empty( $item->target )     ? $item->target     : '',
		'rel'    => ! empty( $item->xfn )        ? $item->xfn        : '',
	);
	$attr_string = '';
	foreach ( $atts as $att => $val ) {
		if ( $val ) {
			$attr_string .= ' ' . $att . '="' . esc_attr( $val ) . '"';
		}
	}
	if ( $has_children ) {
		$attr_string .= ' aria-haspopup="true" aria-expanded="false"';
	}

	$icon_html  = '';
	$icon_class = ! empty( $item->icon_class ) ? $item->icon_class : '';
	if ( $icon_class ) {
		$icon_html = '<i class="' . esc_attr( $icon_class ) . '" aria-hidden="true"></i> ';
	}

	$caret = $has_children ? '<span class="ekwa-caret" aria-hidden="true"></span>' : '';

	return '<a' . $attr_string . '>' . $icon_html . '<span class="ekwa-menu-label">' . esc_html( $item->title ) . '</span>' . $caret . '</a>';
}

/**
 * Recursively render a normal (non-mega) submenu.
 *
 * @param int   $parent_id Parent menu item ID whose children to render.
 * @param array $tree      Tree built by ekwa_header_menu_build_tree().
 * @param int   $depth     Current depth (0 for top-level submenu).
 * @return string
 */
function ekwa_header_menu_render_submenu( $parent_id, $tree, $depth = 0 ) {
	if ( empty( $tree[ $parent_id ] ) ) {
		return '';
	}
	$out = '<ul class="sub-menu" role="menu">';
	foreach ( $tree[ $parent_id ] as $child ) {
		$grandkids   = ! empty( $tree[ $child->ID ] );
		$item_classes = array_merge( array( 'menu-item', 'menu-item-' . $child->ID ), (array) $child->classes );
		if ( $grandkids ) {
			$item_classes[] = 'menu-item-has-children';
		}
		$item_classes = array_filter( array_unique( $item_classes ) );

		$out .= '<li class="' . esc_attr( implode( ' ', $item_classes ) ) . '" role="none">';
		$out .= ekwa_header_menu_link( $child, $grandkids );
		if ( $grandkids ) {
			$out .= ekwa_header_menu_render_submenu( $child->ID, $tree, $depth + 1 );
		}
		$out .= '</li>';
	}
	$out .= '</ul>';
	return $out;
}

/**
 * Render the mega-menu panel for a top-level item.
 *
 * 2nd-level children become column headings (with optional image) and
 * 3rd-level children become items inside each column. Deeper levels are
 * flattened into the same column list.
 *
 * @param object $top_item  Top-level menu item.
 * @param array  $tree      Full tree array.
 * @return string
 */
function ekwa_header_menu_render_megamenu( $top_item, $tree ) {
	$columns_items = isset( $tree[ $top_item->ID ] ) ? $tree[ $top_item->ID ] : array();
	if ( empty( $columns_items ) ) {
		return '';
	}

	$cols = (int) ( $top_item->megamenu_columns ?? 0 );
	if ( $cols <= 0 ) {
		$cols = count( $columns_items );
	}
	$cols = max( 1, min( 6, $cols ) );

	$out  = '<div class="ekwa-megamenu" role="menu" style="--ekwa-mega-cols:' . $cols . '">';
	$out .= '<div class="ekwa-megamenu-grid">';

	foreach ( $columns_items as $col_item ) {
		$has_image  = ! empty( $col_item->menu_image_id );
		$image_html = '';
		if ( $has_image ) {
			$image_html = wp_get_attachment_image(
				$col_item->menu_image_id,
				'medium',
				false,
				array(
					'class'   => 'ekwa-megamenu-image',
					'loading' => 'lazy',
					'alt'     => '',
				)
			);
		}

		$col_classes = array( 'ekwa-megamenu-column', 'menu-item-' . $col_item->ID );
		if ( $has_image ) {
			$col_classes[] = 'has-image';
		}

		$out .= '<div class="' . esc_attr( implode( ' ', $col_classes ) ) . '" role="none">';

		if ( $image_html ) {
			$out .= '<div class="ekwa-megamenu-image-wrap">' . $image_html . '</div>';
		}

		// Column heading = the 2nd-level item (linked).
		$col_url = ! empty( $col_item->url ) ? $col_item->url : '';
		if ( $col_url && '#' !== $col_url ) {
			$out .= '<a class="ekwa-megamenu-heading" href="' . esc_url( $col_url ) . '">' . esc_html( $col_item->title ) . '</a>';
		} else {
			$out .= '<span class="ekwa-megamenu-heading">' . esc_html( $col_item->title ) . '</span>';
		}

		// Items = 3rd-level children of this column heading.
		if ( ! empty( $tree[ $col_item->ID ] ) ) {
			$out .= '<ul class="ekwa-megamenu-list" role="menu">';
			foreach ( $tree[ $col_item->ID ] as $leaf ) {
				$leaf_classes = array_merge( array( 'menu-item', 'menu-item-' . $leaf->ID ), (array) $leaf->classes );
				$leaf_classes = array_filter( array_unique( $leaf_classes ) );
				$out .= '<li class="' . esc_attr( implode( ' ', $leaf_classes ) ) . '" role="none">';
				$out .= ekwa_header_menu_link( $leaf, false );
				$out .= '</li>';
			}
			$out .= '</ul>';
		}

		$out .= '</div>';
	}

	$out .= '</div></div>';
	return $out;
}

/**
 * Render the main nav for a given theme location.
 *
 * @param string $location Theme location slug.
 * @return string
 */
function ekwa_render_main_nav( $location = 'main_menu' ) {
	$locations = get_nav_menu_locations();
	if ( empty( $locations[ $location ] ) ) {
		return '';
	}
	$menu_obj = wp_get_nav_menu_object( $locations[ $location ] );
	if ( ! $menu_obj ) {
		return '';
	}

	$items = wp_get_nav_menu_items( $menu_obj->term_id );
	if ( empty( $items ) ) {
		return '';
	}

	// Apply current-page classes.
	_wp_menu_item_classes_by_context( $items );

	$tree   = ekwa_header_menu_build_tree( $items );
	$top    = isset( $tree[0] ) ? $tree[0] : array();

	if ( empty( $top ) ) {
		return '';
	}

	$out  = '<nav class="ekwa-header-nav" aria-label="' . esc_attr__( 'Main Navigation', 'ekwa' ) . '">';
	$out .= '<ul class="ekwa-header-menu" role="menubar">';

	foreach ( $top as $item ) {
		$has_children = ! empty( $tree[ $item->ID ] );
		$is_mega      = $has_children && ! empty( $item->is_megamenu );

		$item_classes = array_merge( array( 'menu-item', 'menu-item-' . $item->ID ), (array) $item->classes );
		if ( $has_children ) {
			$item_classes[] = 'menu-item-has-children';
		}
		if ( $is_mega ) {
			$item_classes[] = 'menu-item-megamenu';
		}
		$item_classes = array_filter( array_unique( $item_classes ) );

		$out .= '<li class="' . esc_attr( implode( ' ', $item_classes ) ) . '" role="none">';
		$out .= ekwa_header_menu_link( $item, $has_children );

		if ( $is_mega ) {
			$out .= ekwa_header_menu_render_megamenu( $item, $tree );
		} elseif ( $has_children ) {
			$out .= ekwa_header_menu_render_submenu( $item->ID, $tree, 0 );
		}

		$out .= '</li>';
	}

	$out .= '</ul></nav>';
	return $out;
}
