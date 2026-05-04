<?php
/**
 * Ekwa Blog — TOC, author link, AJAX load-more, post card helpers.
 *
 * @package ekwa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// TABLE OF CONTENTS — Add IDs to H2/H3 headings in post content.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Add id attributes to H2 and H3 headings that don't already have one.
 */
/**
 * Add id attributes to H2/H3 headings. Used by the_content filter
 * AND called directly by the TOC block render callback.
 *
 * @param string $content  HTML content.
 * @param bool   $force    Skip is_single() guard (for direct calls).
 * @return string
 */
function ekwa_toc_add_heading_ids( $content, $force = false ) {
	if ( ! $force && ( ! is_single() || ! is_main_query() ) ) {
		return $content;
	}

	$used_ids = array();

	$content = preg_replace_callback(
		'/<h([23])([^>]*)>(.*?)<\/h([23])>/si',
		function ( $matches ) use ( &$used_ids ) {
			$level      = $matches[1];
			$attrs      = $matches[2];
			$inner      = $matches[3];
			$close_level = $matches[4];

			if ( preg_match( '/\bid=["\']/', $attrs ) ) {
				return $matches[0];
			}

			$text = wp_strip_all_tags( $inner );
			$id   = sanitize_title( $text );
			if ( empty( $id ) ) {
				$id = 'heading-' . count( $used_ids );
			}

			$base = $id;
			$n    = 2;
			while ( in_array( $id, $used_ids, true ) ) {
				$id = $base . '-' . $n++;
			}
			$used_ids[] = $id;

			return '<h' . $level . $attrs . ' id="' . esc_attr( $id ) . '">' . $inner . '</h' . $close_level . '>';
		},
		$content
	);

	return $content;
}
add_filter( 'the_content', 'ekwa_toc_add_heading_ids', 8 );

// ═══════════════════════════════════════════════════════════════════════════════
// AUTHOR LINK — Redirect to the ekwa_author_page setting.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Override the author archive link with the Ekwa author page setting.
 */
function ekwa_filter_author_link( $link ) {
	if ( is_admin() ) {
		return $link;
	}

	$author_page_id = absint( get_option( 'ekwa_author_page', 0 ) );
	if ( $author_page_id && 'publish' === get_post_status( $author_page_id ) ) {
		return get_permalink( $author_page_id );
	}

	return $link;
}
add_filter( 'author_link', 'ekwa_filter_author_link' );

// ═══════════════════════════════════════════════════════════════════════════════
// SHARED POST CARD — Used by templates and AJAX load-more.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Render a compact post card used by the related-articles carousel.
 *
 * @param int $post_id Post ID.
 * @return string Card HTML.
 */
function ekwa_render_post_card( $post_id ) {
	$permalink = get_permalink( $post_id );
	$title     = get_the_title( $post_id );
	$excerpt   = get_the_excerpt( $post_id );
	$date      = get_the_date( 'M j, Y', $post_id );
	$thumb     = get_the_post_thumbnail( $post_id, 'medium_large', array(
		'loading' => 'lazy',
	) );

	$html  = '<article class="ekwa-post-card">';
	$html .= '<a href="' . esc_url( $permalink ) . '" class="ekwa-post-card__image">' . $thumb . '</a>';
	$html .= '<div class="ekwa-post-card__body">';
	$html .= '<h3 class="ekwa-post-card__title"><a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a></h3>';
	$html .= '<p class="ekwa-post-card__excerpt">' . esc_html( wp_trim_words( $excerpt, 20, '&hellip;' ) ) . '</p>';
	$html .= '<span class="ekwa-post-card__date"><i class="fa-regular fa-calendar" aria-hidden="true"></i> ' . esc_html( $date ) . '</span>';
	$html .= '</div>';
	$html .= '</article>';

	return $html;
}

/**
 * Render a blog-grid card matching the post-template markup in index.html.
 * Wrapped in <li class="wp-block-post"> so it slots into the existing grid
 * when appended via the AJAX load-more flow.
 */
function ekwa_render_blog_grid_li( $post_id ) {
	$permalink = get_permalink( $post_id );
	$title     = get_the_title( $post_id );
	$excerpt   = get_the_excerpt( $post_id );
	$date      = get_the_date( 'M j, Y', $post_id );
	$thumb     = get_the_post_thumbnail( $post_id, 'medium_large', array( 'loading' => 'lazy' ) );

	$html  = '<li class="wp-block-post post type-post status-publish has-post-thumbnail">';
	$html .= '<div class="wp-block-group ekwa-blog-card">';
	$html .= '<div class="wp-block-group ekwa-blog-card__media">';
	$html .= '<figure class="wp-block-post-featured-image"><a href="' . esc_url( $permalink ) . '">' . $thumb . '</a></figure>';
	$html .= '<div class="wp-block-post-date ekwa-blog-card__date has-small-font-size"><time datetime="' . esc_attr( get_the_date( 'c', $post_id ) ) . '">' . esc_html( $date ) . '</time></div>';
	$html .= '</div>';
	$html .= '<div class="wp-block-group ekwa-blog-card__body">';
	$html .= '<h3 class="wp-block-post-title has-medium-font-size"><a href="' . esc_url( $permalink ) . '">' . esc_html( $title ) . '</a></h3>';
	$html .= '<div class="wp-block-post-excerpt has-small-font-size"><p class="wp-block-post-excerpt__excerpt">' . esc_html( wp_trim_words( $excerpt, 22, '&hellip;' ) ) . '</p></div>';
	$html .= '<a class="wp-block-read-more" href="' . esc_url( $permalink ) . '">Continue Reading</a>';
	$html .= '</div>';
	$html .= '</div>';
	$html .= '</li>';

	return $html;
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX LOAD MORE
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Handle AJAX load-more requests.
 */
function ekwa_ajax_load_more() {
	check_ajax_referer( 'ekwa_load_more', 'nonce' );

	$page  = absint( $_POST['page'] ?? 2 );
	$query = json_decode( stripslashes( $_POST['query'] ?? '{}' ), true );

	if ( ! is_array( $query ) ) {
		wp_send_json_error( 'Invalid query.' );
	}

	// Whitelist allowed query keys.
	$allowed = array( 'post_type', 'posts_per_page', 'orderby', 'order', 'category_name', 'tag', 's', 'tax_query', 'author' );
	$args    = array_intersect_key( $query, array_flip( $allowed ) );
	$args['paged']  = $page;
	$args['post_status'] = 'publish';

	$q    = new WP_Query( $args );
	$html = '';

	if ( $q->have_posts() ) {
		while ( $q->have_posts() ) {
			$q->the_post();
			$html .= ekwa_render_blog_grid_li( get_the_ID() );
		}
		wp_reset_postdata();
	}

	wp_send_json_success( array(
		'html'    => $html,
		'hasMore' => $q->max_num_pages > $page,
	) );
}
add_action( 'wp_ajax_ekwa_load_more', 'ekwa_ajax_load_more' );
add_action( 'wp_ajax_nopriv_ekwa_load_more', 'ekwa_ajax_load_more' );

/**
 * Inject query data attributes into core/query blocks that contain ekwa/load-more.
 */
function ekwa_inject_query_data( $block_content, $block ) {
	if ( $block['blockName'] !== 'core/query' ) {
		return $block_content;
	}

	// Check if this query block contains a load-more block.
	$has_load_more = false;
	$search_blocks = function ( $blocks ) use ( &$search_blocks, &$has_load_more ) {
		foreach ( $blocks as $b ) {
			if ( isset( $b['blockName'] ) && $b['blockName'] === 'ekwa/load-more' ) {
				$has_load_more = true;
				return;
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$search_blocks( $b['innerBlocks'] );
			}
		}
	};
	$search_blocks( $block['innerBlocks'] ?? array() );

	if ( ! $has_load_more ) {
		return $block_content;
	}

	// Extract query attributes.
	$query_attrs = $block['attrs']['query'] ?? array();
	$per_page    = $query_attrs['perPage'] ?? get_option( 'posts_per_page', 10 );

	// Build WP_Query args from block query.
	$args = array(
		'post_type'      => $query_attrs['postType'] ?? 'post',
		'posts_per_page' => $per_page,
		'orderby'        => $query_attrs['orderBy'] ?? 'date',
		'order'          => $query_attrs['order'] ?? 'desc',
	);

	// Inherit from the current query (archive, search, etc.).
	if ( ! empty( $query_attrs['inherit'] ) ) {
		global $wp_query;
		if ( $wp_query->is_category() ) {
			$args['category_name'] = get_queried_object()->slug;
		} elseif ( $wp_query->is_tag() ) {
			$args['tag'] = get_queried_object()->slug;
		} elseif ( $wp_query->is_search() ) {
			$args['s'] = get_search_query();
		} elseif ( $wp_query->is_author() ) {
			$args['author'] = get_queried_object_id();
		}
	}

	// Calculate max pages.
	$count_args = $args;
	$count_args['posts_per_page'] = -1;
	$count_args['fields'] = 'ids';
	$total      = count( get_posts( $count_args ) );
	$max_pages  = (int) ceil( $total / $per_page );

	$nonce      = wp_create_nonce( 'ekwa_load_more' );
	$query_json = esc_attr( wp_json_encode( $args ) );

	// Inject data attributes into the first div whose class contains wp-block-query.
	// WordPress may add layout classes (e.g. "wp-block-query is-layout-flow ..."),
	// so match wp-block-query as a class token rather than the exact attribute value.
	$block_content = preg_replace(
		'/(<div\b[^>]*\bclass="[^"]*\bwp-block-query\b[^"]*"[^>]*?)>/s',
		'$1 data-ekwa-query="' . $query_json . '" data-ekwa-max-pages="' . $max_pages . '" data-ekwa-nonce="' . $nonce . '">',
		$block_content,
		1
	);

	return $block_content;
}
add_filter( 'render_block', 'ekwa_inject_query_data', 10, 2 );

// ═══════════════════════════════════════════════════════════════════════════════
// BLOG ASSETS — Conditional enqueue.
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Enqueue blog-specific CSS and JS.
 */
function ekwa_blog_enqueue_assets() {
	// Load on single posts, archives, search, blog home — NOT on pages or front page.
	if ( is_singular( 'post' ) || is_archive() || is_search() || is_home() ) {
		wp_enqueue_style(
			'ekwa-blog',
			get_template_directory_uri() . '/assets/css/ekwa-blog.css',
			array( 'ekwa-style' ),
			filemtime( get_template_directory() . '/assets/css/ekwa-blog.css' )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'ekwa_blog_enqueue_assets' );
