<?php
/**
 * Mobile Menu: nav menu location, icon meta field, custom walker.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue Font Awesome + admin icon-picker assets on the nav-menus screen
 * so the existing event-delegated picker works for our icon fields.
 */
function ekwa_enqueue_nav_menu_admin_assets( $hook ) {
	if ( 'nav-menus.php' !== $hook ) {
		return;
	}
	wp_enqueue_style(
		'font-awesome',
		get_template_directory_uri() . '/assets/fontawesome/css/all.min.css',
		array(),
		'6.5.1'
	);
	wp_enqueue_style(
		'ekwa-admin-css',
		get_template_directory_uri() . '/assets/css/ekwa-admin.css',
		array(),
		wp_get_theme()->get( 'Version' )
	);
	// Load the WP media library before our admin JS so wp.media is defined when our handler binds.
	wp_enqueue_media();
	wp_enqueue_script(
		'ekwa-admin-js',
		get_template_directory_uri() . '/assets/js/ekwa-admin.js',
		array( 'jquery', 'media-editor', 'media-views' ),
		filemtime( get_template_directory() . '/assets/js/ekwa-admin.js' ),
		true
	);
}
add_action( 'admin_enqueue_scripts', 'ekwa_enqueue_nav_menu_admin_assets' );

/**
 * Render the icon field inside each nav menu item's settings box.
 *
 * @param int    $item_id Menu item ID.
 * @param object $item    Menu item data object.
 * @param int    $depth   Depth of menu item.
 * @param object $args    An object of menu item arguments.
 */
function ekwa_nav_menu_icon_field( $item_id, $item, $depth, $args ) {
	$icon = get_post_meta( $item_id, '_ekwa_menu_icon', true );
	?>
	<p class="field-ekwa-icon description description-wide">
		<label for="ekwa-menu-icon-<?php echo esc_attr( $item_id ); ?>">
			<?php esc_html_e( 'Font Awesome Icon', 'ekwa' ); ?>
		</label>
		<span class="ekwa-icon-field">
			<span class="ekwa-icon-field__row">
				<span class="ekwa-icon-preview-wrap">
					<i class="<?php echo esc_attr( $icon ); ?>"></i>
				</span>
				<input type="text"
				       id="ekwa-menu-icon-<?php echo esc_attr( $item_id ); ?>"
				       name="ekwa_menu_icon[<?php echo esc_attr( $item_id ); ?>]"
				       value="<?php echo esc_attr( $icon ); ?>"
				       placeholder="Search or paste icon class..."
				       class="ekwa-icon-input widefat"
				       autocomplete="off" />
			</span>
			<span class="ekwa-icon-picker-dropdown"></span>
		</span>
	</p>
	<?php
}
add_action( 'wp_nav_menu_item_custom_fields', 'ekwa_nav_menu_icon_field', 10, 4 );

/**
 * Save the icon meta when a menu item is updated.
 *
 * @param int $menu_id         ID of the updated menu.
 * @param int $menu_item_db_id ID of the updated menu item.
 */
function ekwa_save_nav_menu_icon( $menu_id, $menu_item_db_id ) {
	if ( isset( $_POST['ekwa_menu_icon'][ $menu_item_db_id ] ) ) {
		$icon = sanitize_text_field( wp_unslash( $_POST['ekwa_menu_icon'][ $menu_item_db_id ] ) );
		update_post_meta( $menu_item_db_id, '_ekwa_menu_icon', $icon );
	}
}
add_action( 'wp_update_nav_menu_item', 'ekwa_save_nav_menu_icon', 10, 2 );

/**
 * Attach icon_class property to each menu item object when loaded.
 *
 * @param object $menu_item The menu item object.
 * @return object
 */
function ekwa_setup_nav_menu_icon( $menu_item ) {
	$menu_item->icon_class = get_post_meta( $menu_item->ID, '_ekwa_menu_icon', true );
	return $menu_item;
}
add_filter( 'wp_setup_nav_menu_item', 'ekwa_setup_nav_menu_icon' );

/**
 * Custom Walker for the mobile menu.
 *
 * Prepends a Font Awesome <i> element before each menu item label
 * when an icon class is set.
 */
/**
 * Inject visibility classes into header / mobile-header template parts.
 *
 * WordPress FSE caches template parts in the database, so classes added
 * directly in the .html file are ignored once a user customises the part
 * in the Site Editor.  This filter always runs on the rendered output.
 *
 * @param string $block_content Rendered block HTML.
 * @param array  $block         Parsed block array.
 * @return string
 */
function ekwa_inject_header_visibility_class( $block_content, $block ) {
	if ( 'core/template-part' !== ( $block['blockName'] ?? '' ) ) {
		return $block_content;
	}
	$slug = $block['attrs']['slug'] ?? '';

	if ( 'header' === $slug ) {
		// Add ekwa-desktop-header to the first element (the wrapper tag).
		$block_content = preg_replace(
			'/^(<\w+\b[^>]*\bclass=")/s',
			'$1ekwa-desktop-header ',
			$block_content,
			1
		);
	} elseif ( 'mobile-header' === $slug ) {
		$block_content = preg_replace(
			'/^(<\w+\b[^>]*\bclass=")/s',
			'$1ekwa-mobile-header ',
			$block_content,
			1
		);
	}

	return $block_content;
}
add_filter( 'render_block', 'ekwa_inject_header_visibility_class', 10, 2 );

class Ekwa_Mobile_Menu_Walker extends Walker_Nav_Menu {

	/**
	 * Starts the element output.
	 *
	 * @param string   $output Used to append additional content (passed by reference).
	 * @param WP_Post  $item   Menu item data object.
	 * @param int      $depth  Depth of menu item.
	 * @param stdClass $args   An object of wp_nav_menu() arguments.
	 * @param int      $id     Current item ID.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes      = empty( $item->classes ) ? array() : (array) $item->classes;
		$classes[]    = 'menu-item-' . $item->ID;
		$class_string = implode( ' ', array_filter( $classes ) );

		$output .= '<li class="' . esc_attr( $class_string ) . '">';

		$atts = array(
			'title'  => ! empty( $item->attr_title ) ? $item->attr_title : '',
			'target' => ! empty( $item->target )     ? $item->target     : '',
			'rel'    => ! empty( $item->xfn )        ? $item->xfn        : '',
			'href'   => ! empty( $item->url )        ? $item->url        : '',
		);

		$attr_string = '';
		foreach ( $atts as $att => $val ) {
			if ( $val ) {
				$attr_string .= ' ' . $att . '="' . esc_attr( $val ) . '"';
			}
		}

		$icon_html  = '';
		$icon_class = ! empty( $item->icon_class ) ? $item->icon_class : '';
		if ( $icon_class ) {
			$icon_html = '<i class="' . esc_attr( $icon_class ) . '" aria-hidden="true"></i> ';
		}

		$output .= '<a' . $attr_string . '>';
		$output .= $icon_html;
		$output .= esc_html( $item->title );
		$output .= '</a>';
	}
}
