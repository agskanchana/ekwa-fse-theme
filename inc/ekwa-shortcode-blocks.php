<?php
/**
 * Ekwa Shortcode Blocks — block-editor authored shortcodes.
 *
 * Registers the `ekwa_shortcode` post type: a Gutenberg-editable container
 * whose block content is emitted wherever its shortcode is placed. Authors get
 * the full block editor (every Ekwa block included) instead of hand-writing
 * shortcode attributes, which is what the Appearance → Shortcodes builder is
 * for (that page configures the built-in `[ekwa_*]` data shortcodes; this one
 * creates brand-new shortcodes out of block content).
 *
 * Each item gets a slug — typed by the author, or auto-generated from the
 * title — and is callable two ways:
 *
 *   [my-slug]                        — the slug as its own shortcode tag
 *   [ekwa_block slug="my-slug"]      — namespaced form, always available
 *
 * The namespaced form is the safe fallback: a bare slug is only registered when
 * no other plugin/theme already owns that tag, so this never clobbers an
 * existing shortcode.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post type name, slug meta key, and the autoloaded slug→ID map option.
 */
define( 'EKWA_SHORTCODE_POST_TYPE', 'ekwa_shortcode' );
define( 'EKWA_SHORTCODE_SLUG_META', '_ekwa_shortcode_slug' );
define( 'EKWA_SHORTCODE_MAP_OPTION', 'ekwa_shortcode_block_map' );

/* ------------------------------------------------------------------
 * Post type + meta registration.
 * ------------------------------------------------------------------ */

/**
 * Register the `ekwa_shortcode` post type.
 *
 * Not publicly queryable — these are content fragments, not pages, so they get
 * no permalink of their own and never surface in search or archives. `page`
 * capabilities keep them to Editors and Administrators, matching the fact that
 * a shortcode edit changes every page that embeds it.
 */
function ekwa_shortcode_blocks_register_post_type() {
	register_post_type(
		EKWA_SHORTCODE_POST_TYPE,
		array(
			'labels' => array(
				'name'                  => __( 'Shortcode Blocks', 'ekwa' ),
				'singular_name'         => __( 'Shortcode Block', 'ekwa' ),
				'menu_name'             => __( 'Shortcode Blocks', 'ekwa' ),
				'add_new'               => __( 'Add New', 'ekwa' ),
				'add_new_item'          => __( 'Add New Shortcode Block', 'ekwa' ),
				'edit_item'             => __( 'Edit Shortcode Block', 'ekwa' ),
				'new_item'              => __( 'New Shortcode Block', 'ekwa' ),
				'view_item'             => __( 'View Shortcode Block', 'ekwa' ),
				'search_items'          => __( 'Search Shortcode Blocks', 'ekwa' ),
				'not_found'             => __( 'No shortcode blocks yet.', 'ekwa' ),
				'not_found_in_trash'    => __( 'No shortcode blocks in Trash.', 'ekwa' ),
				'all_items'             => __( 'Shortcode Blocks', 'ekwa' ),
				'item_published'        => __( 'Shortcode block published.', 'ekwa' ),
				'item_updated'          => __( 'Shortcode block updated.', 'ekwa' ),
			),
			'description'         => __( 'Reusable block content published as a shortcode.', 'ekwa' ),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => true,
			'menu_position'       => 26,
			'menu_icon'           => 'dashicons-shortcode',
			'hierarchical'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'capability_type'     => 'page',
			'map_meta_cap'        => true,
			'supports'            => array( 'title', 'editor', 'revisions', 'custom-fields' ),
			// REST is what makes the block editor available for this post type.
			'show_in_rest'        => true,
			'rest_base'           => 'ekwa-shortcodes',
		)
	);
}
add_action( 'init', 'ekwa_shortcode_blocks_register_post_type', 5 );

/**
 * Register the slug meta so the editor sidebar can read/write it over REST.
 *
 * Underscore-prefixed meta is protected, so `auth_callback` has to grant access
 * explicitly — gated on the same capability that edits the post itself.
 */
function ekwa_shortcode_blocks_register_meta() {
	register_post_meta(
		EKWA_SHORTCODE_POST_TYPE,
		EKWA_SHORTCODE_SLUG_META,
		array(
			'type'              => 'string',
			'single'            => true,
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'ekwa_shortcode_blocks_normalize_slug',
			'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', $post_id );
			},
		)
	);
}
add_action( 'init', 'ekwa_shortcode_blocks_register_meta', 6 );

/* ------------------------------------------------------------------
 * Slug handling — normalize, uniquify, auto-generate.
 * ------------------------------------------------------------------ */

/**
 * Reduce arbitrary text to a shortcode-safe slug.
 *
 * WordPress forbids `& / < > [ ] =` and whitespace in a shortcode tag, and the
 * shortcode regex is byte-oriented, so we keep the character set to lowercase
 * ASCII letters, digits, hyphen and underscore. Accented characters are
 * transliterated first (via sanitize_title) rather than dropped.
 *
 * @param string $slug Raw slug or title.
 * @return string Normalized slug, or '' when nothing usable remains.
 */
function ekwa_shortcode_blocks_normalize_slug( $slug ) {
	$slug = strtolower( remove_accents( (string) $slug ) );
	$slug = preg_replace( '/[^a-z0-9_-]+/', '-', $slug );
	$slug = preg_replace( '/-{2,}/', '-', $slug );
	$slug = trim( $slug, '-' );

	// A tag starting with a digit still works, but a bare number reads as a
	// mistake and collides easily — prefix it so it stays recognizable.
	if ( '' !== $slug && ctype_digit( $slug ) ) {
		$slug = 'sc-' . $slug;
	}
	return $slug;
}

/**
 * Whether another shortcode block already claims this slug.
 *
 * Checks every non-trashed status, so a draft in progress can't have its slug
 * taken out from under it by a later publish.
 *
 * @param string $slug       Normalized slug.
 * @param int    $exclude_id Post ID to ignore (the one being saved).
 * @return bool
 */
function ekwa_shortcode_blocks_slug_taken( $slug, $exclude_id = 0 ) {
	$hits = get_posts(
		array(
			'post_type'        => EKWA_SHORTCODE_POST_TYPE,
			'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'numberposts'      => 1,
			'fields'           => 'ids',
			'exclude'          => array_filter( array( (int) $exclude_id ) ),
			'meta_key'         => EKWA_SHORTCODE_SLUG_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'       => $slug,                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'suppress_filters' => false,
			'no_found_rows'    => true,
		)
	);
	return ! empty( $hits );
}

/**
 * Make a slug unique among shortcode blocks by appending -2, -3, …
 *
 * @param string $slug    Normalized slug.
 * @param int    $post_id Post being saved.
 * @return string
 */
function ekwa_shortcode_blocks_unique_slug( $slug, $post_id ) {
	$base   = $slug;
	$suffix = 2;
	while ( ekwa_shortcode_blocks_slug_taken( $slug, $post_id ) ) {
		$slug = $base . '-' . $suffix;
		++$suffix;
		// Pathological case (a huge run of duplicates) — the post ID is unique
		// by definition, so fall back to it rather than looping forever.
		if ( $suffix > 100 ) {
			$slug = $base . '-' . $post_id;
			break;
		}
	}
	return $slug;
}

/**
 * The stored slug for a shortcode block.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function ekwa_shortcode_blocks_get_slug( $post_id ) {
	return ekwa_shortcode_blocks_normalize_slug( get_post_meta( $post_id, EKWA_SHORTCODE_SLUG_META, true ) );
}

/**
 * Resolve, uniquify and persist a shortcode block's slug on save.
 *
 * When the author left the field empty the slug is generated from the title,
 * then the post slug, then the post ID — so every item is always addressable.
 *
 * Runs on both `save_post_*` (classic/quick-edit paths) and
 * `rest_after_insert_*`: the REST controller writes meta *after* `save_post`,
 * so a value generated during `save_post` would be overwritten by the empty
 * string the editor sent. The second pass corrects it, and lands before the
 * REST response is prepared so the editor receives the generated slug.
 *
 * @param int|WP_Post $post Post ID or object.
 */
function ekwa_shortcode_blocks_sync_slug( $post ) {
	$post = get_post( $post );
	if ( ! $post || EKWA_SHORTCODE_POST_TYPE !== $post->post_type ) {
		return;
	}
	if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'auto-draft' === $post->post_status ) {
		return;
	}

	$slug = ekwa_shortcode_blocks_normalize_slug( get_post_meta( $post->ID, EKWA_SHORTCODE_SLUG_META, true ) );
	if ( '' === $slug ) {
		$slug = ekwa_shortcode_blocks_normalize_slug( $post->post_title );
	}
	// The post slug is only a useful source when it carries real words. For an
	// untitled post WordPress falls back to the bare post ID, which would come
	// through the normalizer as "sc-123" — noise dressed up as a name. Skip it
	// so those land on the explicit "shortcode-{ID}" form below instead.
	if ( '' === $slug && ! ctype_digit( (string) $post->post_name ) ) {
		$slug = ekwa_shortcode_blocks_normalize_slug( $post->post_name );
	}
	if ( '' === $slug ) {
		$slug = 'shortcode-' . $post->ID;
	}

	$slug = ekwa_shortcode_blocks_unique_slug( $slug, $post->ID );
	update_post_meta( $post->ID, EKWA_SHORTCODE_SLUG_META, $slug );

	ekwa_shortcode_blocks_flush_map();
}
add_action( 'save_post_' . EKWA_SHORTCODE_POST_TYPE, 'ekwa_shortcode_blocks_sync_slug', 20 );
add_action( 'rest_after_insert_' . EKWA_SHORTCODE_POST_TYPE, 'ekwa_shortcode_blocks_sync_slug', 20 );

/* ------------------------------------------------------------------
 * Slug → post ID map (autoloaded; rebuilt only when an item changes).
 * ------------------------------------------------------------------ */

/**
 * Map of published slug => post ID.
 *
 * Cached in an autoloaded option so a front-end request registers shortcodes
 * without a query. Rebuilt whenever an item is saved, trashed or deleted.
 *
 * @return array<string,int>
 */
function ekwa_shortcode_blocks_slug_map() {
	$map = get_option( EKWA_SHORTCODE_MAP_OPTION, null );
	if ( ! is_array( $map ) ) {
		$map = ekwa_shortcode_blocks_build_map();
	}
	return $map;
}

/**
 * Query every published shortcode block and store the slug map.
 *
 * @return array<string,int>
 */
function ekwa_shortcode_blocks_build_map() {
	$map = array();

	$ids = get_posts(
		array(
			'post_type'        => EKWA_SHORTCODE_POST_TYPE,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => false,
			'no_found_rows'    => true,
		)
	);

	foreach ( $ids as $id ) {
		$slug = ekwa_shortcode_blocks_get_slug( $id );
		// First writer wins — the uniqueness pass on save keeps this from
		// happening, but a direct DB edit or an import could still duplicate.
		if ( '' === $slug || isset( $map[ $slug ] ) ) {
			continue;
		}
		$map[ $slug ] = (int) $id;
	}

	update_option( EKWA_SHORTCODE_MAP_OPTION, $map, true );
	return $map;
}

/**
 * Drop the cached map so the next read rebuilds it.
 */
function ekwa_shortcode_blocks_flush_map() {
	delete_option( EKWA_SHORTCODE_MAP_OPTION );
}

/**
 * Flush the map when a shortcode block is trashed, restored or deleted.
 *
 * These hooks fire for every post type, so the type is checked first — deleting
 * an unrelated page shouldn't cost the next front-end request a rebuild query.
 *
 * @param int $post_id Post ID.
 */
function ekwa_shortcode_blocks_maybe_flush_map( $post_id ) {
	if ( EKWA_SHORTCODE_POST_TYPE === get_post_type( $post_id ) ) {
		ekwa_shortcode_blocks_flush_map();
	}
}
add_action( 'trashed_post', 'ekwa_shortcode_blocks_maybe_flush_map' );
add_action( 'untrashed_post', 'ekwa_shortcode_blocks_maybe_flush_map' );
// `deleted_post` runs after the row is gone, so get_post_type() can't resolve it
// any more — the post object comes in as the second argument instead.
add_action(
	'deleted_post',
	static function ( $post_id, $post = null ) {
		if ( $post && EKWA_SHORTCODE_POST_TYPE === $post->post_type ) {
			ekwa_shortcode_blocks_flush_map();
		}
	},
	10,
	2
);

/* ------------------------------------------------------------------
 * Shortcode registration + rendering.
 * ------------------------------------------------------------------ */

/**
 * Register `[ekwa_block]` plus one bare tag per slug.
 *
 * Priority 20 so most plugins have registered their own shortcodes by now —
 * `shortcode_exists()` then tells us whether a bare slug is free. When it
 * isn't, the item stays reachable through `[ekwa_block slug="…"]` and the
 * admin list flags the conflict.
 */
function ekwa_shortcode_blocks_register_shortcodes() {
	add_shortcode( 'ekwa_block', 'ekwa_shortcode_blocks_generic_shortcode' );

	foreach ( array_keys( ekwa_shortcode_blocks_slug_map() ) as $slug ) {
		if ( shortcode_exists( $slug ) ) {
			continue;
		}
		add_shortcode(
			$slug,
			static function ( $atts, $content, $tag ) {
				return ekwa_shortcode_blocks_render( $tag );
			}
		);
	}
}
add_action( 'init', 'ekwa_shortcode_blocks_register_shortcodes', 20 );

/**
 * `[ekwa_block slug="my-slug"]` — the namespaced form.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function ekwa_shortcode_blocks_generic_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'slug' => '' ), $atts, 'ekwa_block' );
	return ekwa_shortcode_blocks_render( $atts['slug'] );
}

/**
 * Render a shortcode block's content by slug.
 *
 * Mirrors the `the_content` order — `do_blocks()` then `do_shortcode()` — so
 * dynamic blocks render first and any shortcode they emit still runs. wpautop
 * is deliberately skipped: block content is already complete markup.
 *
 * The global post is left alone on purpose. Blocks that read the current post
 * (ekwa/page-title, ekwa/toc, the blog blocks) should describe the page the
 * shortcode was placed on, not the shortcode block itself.
 *
 * @param string $slug Shortcode slug.
 * @return string Rendered HTML, or '' when unknown/unpublished/recursive.
 */
function ekwa_shortcode_blocks_render( $slug ) {
	static $rendering = array();

	$slug = ekwa_shortcode_blocks_normalize_slug( $slug );
	if ( '' === $slug ) {
		return '';
	}

	// A shortcode block that embeds itself (directly or through another block)
	// would recurse until the request dies. Render the outer pass only.
	if ( isset( $rendering[ $slug ] ) ) {
		return '';
	}

	$map = ekwa_shortcode_blocks_slug_map();
	if ( ! isset( $map[ $slug ] ) ) {
		return '';
	}

	$post = get_post( $map[ $slug ] );
	if ( ! $post || EKWA_SHORTCODE_POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
		return '';
	}
	if ( '' === trim( (string) $post->post_content ) ) {
		return '';
	}

	$rendering[ $slug ] = true;
	$html               = do_shortcode( do_blocks( $post->post_content ) );
	unset( $rendering[ $slug ] );

	return $html;
}

/**
 * The shortcode string an author should copy for a given item.
 *
 * Returns the bare `[slug]` form when that tag is ours, and the namespaced
 * `[ekwa_block slug="…"]` form when something else owns it.
 *
 * @param string $slug Normalized slug.
 * @return string
 */
function ekwa_shortcode_blocks_tag_for( $slug ) {
	if ( '' === $slug ) {
		return '';
	}
	if ( ekwa_shortcode_blocks_slug_conflicts( $slug ) ) {
		return '[ekwa_block slug="' . $slug . '"]';
	}
	return '[' . $slug . ']';
}

/**
 * Whether a bare slug tag is owned by something other than this module.
 *
 * `shortcode_exists()` is true for our own registration too, so the map is
 * consulted first — a slug we registered is not a conflict.
 *
 * @param string $slug Normalized slug.
 * @return bool
 */
function ekwa_shortcode_blocks_slug_conflicts( $slug ) {
	if ( ! shortcode_exists( $slug ) ) {
		return false;
	}
	$map = ekwa_shortcode_blocks_slug_map();
	return ! isset( $map[ $slug ] );
}

/* ------------------------------------------------------------------
 * Admin list table.
 * ------------------------------------------------------------------ */

/**
 * Add a "Shortcode" column (and drop the meaningless author column).
 *
 * @param array $columns Existing columns.
 * @return array
 */
function ekwa_shortcode_blocks_columns( $columns ) {
	$new = array();
	foreach ( $columns as $key => $label ) {
		if ( 'author' === $key ) {
			continue;
		}
		$new[ $key ] = $label;
		if ( 'title' === $key ) {
			$new['ekwa_shortcode_tag'] = __( 'Shortcode', 'ekwa' );
		}
	}
	return $new;
}
add_filter( 'manage_' . EKWA_SHORTCODE_POST_TYPE . '_posts_columns', 'ekwa_shortcode_blocks_columns' );

/**
 * Render the "Shortcode" column: the copyable tag, plus a conflict warning.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 */
function ekwa_shortcode_blocks_column_content( $column, $post_id ) {
	if ( 'ekwa_shortcode_tag' !== $column ) {
		return;
	}

	$slug = ekwa_shortcode_blocks_get_slug( $post_id );
	if ( '' === $slug ) {
		echo '<span class="ekwa-sb-muted">' . esc_html__( 'Assigned on save', 'ekwa' ) . '</span>';
		return;
	}

	$tag       = ekwa_shortcode_blocks_tag_for( $slug );
	$conflicts = ekwa_shortcode_blocks_slug_conflicts( $slug );

	echo '<code class="ekwa-sb-tag">' . esc_html( $tag ) . '</code> ';
	printf(
		'<button type="button" class="button-link ekwa-sb-copy" data-clipboard="%s">%s</button>',
		esc_attr( $tag ),
		esc_html__( 'Copy', 'ekwa' )
	);

	if ( 'publish' !== get_post_status( $post_id ) ) {
		echo '<p class="ekwa-sb-muted">' . esc_html__( 'Renders nothing until published.', 'ekwa' ) . '</p>';
	}
	if ( $conflicts ) {
		echo '<p class="ekwa-sb-warn">' . esc_html(
			sprintf(
				/* translators: %s: shortcode slug. */
				__( '[%s] is already registered elsewhere — use the namespaced form above.', 'ekwa' ),
				$slug
			)
		) . '</p>';
	}
}
add_action( 'manage_' . EKWA_SHORTCODE_POST_TYPE . '_posts_custom_column', 'ekwa_shortcode_blocks_column_content', 10, 2 );

/**
 * Column styling + click-to-copy on the list screen.
 *
 * @param string $hook Current admin page.
 */
function ekwa_shortcode_blocks_admin_assets( $hook ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( 'edit.php' !== $hook || ! $screen || EKWA_SHORTCODE_POST_TYPE !== $screen->post_type ) {
		return;
	}

	wp_register_style( 'ekwa-shortcode-blocks-admin', false, array(), wp_get_theme()->get( 'Version' ) );
	wp_enqueue_style( 'ekwa-shortcode-blocks-admin' );
	wp_add_inline_style(
		'ekwa-shortcode-blocks-admin',
		'.column-ekwa_shortcode_tag{width:26%}' .
		'.ekwa-sb-tag{background:#f0f0f1;padding:2px 6px;border-radius:3px;font-size:12px}' .
		'.ekwa-sb-copy{text-decoration:none}' .
		'.ekwa-sb-muted{color:#787c82;font-style:italic;margin:4px 0 0}' .
		'.ekwa-sb-warn{color:#b32d2e;margin:4px 0 0}'
	);

	wp_register_script( 'ekwa-shortcode-blocks-admin', false, array(), wp_get_theme()->get( 'Version' ), true );
	wp_enqueue_script( 'ekwa-shortcode-blocks-admin' );
	wp_add_inline_script(
		'ekwa-shortcode-blocks-admin',
		<<<'JS'
document.addEventListener( 'click', function ( e ) {
	var btn = e.target && e.target.closest ? e.target.closest( '.ekwa-sb-copy' ) : null;
	if ( ! btn ) { return; }
	e.preventDefault();
	var text = btn.dataset.clipboard || '';
	var done = function () {
		var prev = btn.textContent;
		btn.textContent = '✓ Copied';
		setTimeout( function () { btn.textContent = prev; }, 1800 );
	};
	if ( navigator.clipboard && navigator.clipboard.writeText ) {
		navigator.clipboard.writeText( text ).then( done, function () { fallback( text, done ); } );
	} else {
		fallback( text, done );
	}
	function fallback( value, cb ) {
		var ta = document.createElement( 'textarea' );
		ta.value = value;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); cb(); } catch ( err ) {}
		document.body.removeChild( ta );
	}
} );
JS
	);
}
add_action( 'admin_enqueue_scripts', 'ekwa_shortcode_blocks_admin_assets' );

/* ------------------------------------------------------------------
 * Block editor sidebar.
 * ------------------------------------------------------------------ */

/**
 * Enqueue the slug panel on the shortcode block editor screen only.
 */
function ekwa_shortcode_blocks_editor_assets() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || EKWA_SHORTCODE_POST_TYPE !== $screen->post_type ) {
		return;
	}

	$path = 'assets/js/ekwa-shortcode-blocks-editor.js';
	wp_enqueue_script(
		'ekwa-shortcode-blocks-editor',
		get_theme_file_uri( $path ),
		array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-element', 'wp-data', 'wp-i18n' ),
		filemtime( get_theme_file_path( $path ) ),
		true
	);
	wp_localize_script(
		'ekwa-shortcode-blocks-editor',
		'ekwaShortcodeBlocks',
		array(
			'metaKey' => EKWA_SHORTCODE_SLUG_META,
		)
	);
}
add_action( 'enqueue_block_editor_assets', 'ekwa_shortcode_blocks_editor_assets' );
